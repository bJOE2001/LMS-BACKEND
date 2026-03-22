<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\DepartmentHead;
use App\Models\Employee;
use App\Models\HRAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAccrualHistory;
use App\Models\LeaveType;
use App\Services\RecycleBinService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Employee Management - uses local LMS_DB only.
 *
 * Employees are filtered by `office` (string) using the selected department name.
 */
class EmployeeController extends Controller
{
    private const ALLOWED_STATUSES = [
        'CASUAL',
        'CO-TERMINOUS',
        'CONTRACTUAL',
        'ELECTIVE',
        'REGULAR',
    ];

    /**
     * List departments for the filter dropdown.
     */
    public function departments(): JsonResponse
    {
        $departments = Department::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'departments' => $departments,
        ]);
    }

    /**
     * Get the current department head for the authenticated department admin.
     */
    public function departmentHead(Request $request): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $departmentHead = DepartmentHead::query()
            ->where('department_id', $admin->department_id)
            ->first();

        return response()->json([
            'department_head' => $departmentHead ? $this->serializeDepartmentHead($departmentHead) : null,
        ]);
    }

    /**
     * Create or update the current department head for the authenticated department admin.
     * POST creates a new head and rejects a second head for the same department.
     * PUT updates the existing head record.
     */
    public function upsertDepartmentHead(Request $request): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $validated = $this->validateDepartmentHeadPayload($request);
        $attributes = $this->normalizeDepartmentHeadPayload($validated, $admin->department->name);
        $fullName = $this->buildDepartmentHeadFullName($attributes);
        $existingDepartmentHead = DepartmentHead::query()
            ->where('department_id', $admin->department_id)
            ->first();
        $payload = array_merge($attributes, [
            'department_id' => $admin->department_id,
            'full_name' => $fullName,
            'position' => $attributes['designation'],
        ]);

        if ($request->isMethod('post')) {
            if ($existingDepartmentHead) {
                return response()->json([
                    'message' => 'A department head already exists for this department. Edit or remove the current record first.',
                    'department_head' => $this->serializeDepartmentHead($existingDepartmentHead),
                ], 422);
            }

            $departmentHead = DepartmentHead::query()->create($payload);
            $this->syncDepartmentHeadToEmployeeRecord($departmentHead);

            return response()->json([
                'message' => 'Department head added successfully.',
                'department_head' => $this->serializeDepartmentHead($departmentHead),
            ], 201);
        }

        if (!$existingDepartmentHead) {
            return response()->json([
                'message' => 'Department head not found.',
            ], 404);
        }

        $existingDepartmentHead->fill($payload);
        $existingDepartmentHead->save();
        $this->syncDepartmentHeadToEmployeeRecord($existingDepartmentHead);

        return response()->json([
            'message' => 'Department head updated successfully.',
            'department_head' => $this->serializeDepartmentHead($existingDepartmentHead),
        ]);
    }

    /**
     * Delete the current department head for the authenticated department admin.
     */
    public function deleteDepartmentHead(Request $request): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $departmentHead = DepartmentHead::query()
            ->where('department_id', $admin->department_id)
            ->first();

        if (!$departmentHead) {
            return response()->json([
                'message' => 'Department head not found.',
            ], 404);
        }

        $departmentHead->loadMissing('department');

        DB::transaction(function () use ($departmentHead, $request): void {
            app(RecycleBinService::class)->storeDeletedModel(
                $departmentHead,
                $request->user(),
                [
                    'record_title' => $departmentHead->full_name,
                    'delete_source' => 'admin.department-head',
                    'delete_reason' => $request->input('reason'),
                    'snapshot' => array_merge($departmentHead->toArray(), [
                        'department' => $departmentHead->department?->only(['id', 'name']),
                    ]),
                ]
            );

            $departmentHead->delete();
        });

        return response()->json([
            'message' => 'Department head removed successfully.',
        ]);
    }

    /**
     * List paginated employees.
     *
     * @queryParam department_id int    Optional department filter.
     * @queryParam search string        Optional employee name search.
     * @queryParam per_page int         Items per page (default 15, max 100).
     * @queryParam page int             Page number.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer', 'exists:tblDepartments,id'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $account = $request->user();
        $isHrAccount = $account instanceof HRAccount;
        $isDepartmentAdmin = $account instanceof DepartmentAdmin;

        if (!$isHrAccount && !$isDepartmentAdmin) {
            return response()->json([
                'message' => 'Only HR and department admin accounts can access this resource.',
            ], 403);
        }

        $departmentId = $validated['department_id'] ?? null;
        $searchTerm = $validated['search'] ?? null;
        $perPage = max(1, min(100, (int) ($validated['per_page'] ?? 15)));
        $page = max(1, (int) $request->input('page', 1));

        $departmentName = null;
        $summaryDepartmentId = null;
        if ($isDepartmentAdmin) {
            $account->loadMissing('department');

            if (!$account->department_id || !$account->department?->name) {
                return response()->json([
                    'message' => 'Department admin account is not assigned to a department.',
                ], 403);
            }

            if ($departmentId !== null && (int) $departmentId !== (int) $account->department_id) {
                return response()->json([
                    'message' => 'You can only access employees from your assigned department.',
                ], 403);
            }

            // Enforce tenant boundary: department admins are always scoped to their own department.
            $departmentName = $account->department->name;
            $summaryDepartmentId = (int) $account->department_id;
        } elseif ($departmentId) {
            $departmentName = Department::find($departmentId)?->name;
            $summaryDepartmentId = $departmentName ? (int) $departmentId : null;
        }

        $departmentHead = null;
        $departmentHeadLookup = [];
        if ($summaryDepartmentId) {
            $departmentHead = DepartmentHead::query()
                ->where('department_id', $summaryDepartmentId)
                ->first();
            $departmentHeadControlNo = trim((string) ($departmentHead?->control_no ?? ''));
            $departmentHeadLookup = $departmentHead && $departmentHeadControlNo !== ''
                ? [$departmentHeadControlNo => $departmentHead]
                : [];
        } elseif ($isHrAccount) {
            $departmentHeadLookup = $this->buildDepartmentHeadLookup(null, $departmentName);
        }

        if ($isHrAccount) {
            [$employees, $totalEmployees, $statusCounts] = $this->buildHrEmployeeListing(
                $request,
                $departmentName,
                $summaryDepartmentId,
                $searchTerm,
                $perPage,
                $page,
                $departmentHeadLookup
            );
        } else {
            $employees = $this->buildDepartmentAdminEmployeePaginator(
                $departmentName,
                $searchTerm,
                $perPage,
                $page,
                $departmentHeadLookup
            );
            $totalEmployees = $employees->total();
            $statusCounts = $this->buildDepartmentAdminStatusCounts($departmentName, $searchTerm);

            if (
                $departmentHead
                && $this->shouldIncludeDepartmentHeadInEmployeeSummary(
                    $departmentHead,
                    $departmentName,
                    $searchTerm,
                    false
                )
            ) {
                $totalEmployees++;

                $statusKey = strtoupper(trim((string) ($departmentHead->status ?? '')));
                if ($statusKey !== '') {
                    $statusCounts[$statusKey] = ((int) ($statusCounts[$statusKey] ?? 0)) + 1;
                }
            }
        }

        return response()->json([
            'employees' => $employees,
            'total_employees' => $totalEmployees,
            'status_counts' => $statusCounts,
            'department_head' => $departmentHead ? $this->serializeDepartmentHead($departmentHead) : null,
        ]);
    }

    /**
     * Create an employee in LMS_DB.
     * Department admins can only create employees inside their own department.
     */
    public function store(Request $request): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $validated = $this->validateEmployeePayload($request);
        $attributes = $this->normalizeEmployeePayload($validated, $admin->department->name, true);

        $employee = Employee::query()->create($attributes);

        return response()->json([
            'message' => 'Employee created successfully.',
            'employee' => $this->serializeEmployee($employee),
        ], 201);
    }

    /**
     * Update an employee in LMS_DB.
     * Department admins can only update employees inside their own department.
     */
    public function update(Request $request, string $controlNo): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $employee = Employee::findByControlNo($controlNo);
        if (!$employee) {
            $departmentHead = DepartmentHead::query()
                ->where('control_no', $controlNo)
                ->first();

            if (!$departmentHead) {
                return response()->json(['message' => 'Employee not found.'], 404);
            }

            return response()->json([
                'employee' => $this->serializeDepartmentHeadAsEmployee($departmentHead),
                'applications' => [],
            ]);
        }

        if (trim((string) $employee->office) !== trim((string) $admin->department->name)) {
            return response()->json([
                'message' => 'You can only update employees in your assigned department.',
            ], 403);
        }

        $validated = $this->validateEmployeePayload($request, true);
        $attributes = $this->normalizeEmployeePayload($validated, $admin->department->name, false);

        $employee->fill($attributes);
        $employee->save();

        return response()->json([
            'message' => 'Employee updated successfully.',
            'employee' => $this->serializeEmployee($employee),
        ]);
    }

    /**
     * Delete an employee in LMS_DB.
     * Department admins can only delete employees inside their own department.
     */
    public function destroy(Request $request, string $controlNo): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $employee = Employee::findByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        if (trim((string) $employee->office) !== trim((string) $admin->department->name)) {
            return response()->json([
                'message' => 'You can only delete employees in your assigned department.',
            ], 403);
        }

        $employee->loadMissing('department');

        DB::transaction(function () use ($employee, $request): void {
            app(RecycleBinService::class)->storeDeletedModel(
                $employee,
                $request->user(),
                [
                    'record_title' => $employee->full_name,
                    'delete_source' => 'admin.employees',
                    'delete_reason' => $request->input('reason'),
                    'snapshot' => array_merge($employee->toArray(), [
                        'department' => $employee->department?->only(['id', 'name']),
                    ]),
                ]
            );

            $employee->delete();
        });

        return response()->json([
            'message' => 'Employee deleted successfully.',
        ]);
    }

    /**
     * Return leave application history for one employee (HR only).
     */
    public function leaveHistory(Request $request, string $controlNo): JsonResponse
    {
        $account = $request->user();
        if (!$account instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $employee = Employee::findByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        $applications = LeaveApplication::with(['leaveType'])
            ->where('employee_control_no', (string) $employee->control_no)
            ->orderByDesc('created_at')
            ->get()
            ->map(function (LeaveApplication $application) {
                return [
                    'id' => $application->id,
                    'leave_type' => $application->leaveType?->name ?? 'Unknown',
                    'start_date' => $application->start_date
                        ? Carbon::parse($application->start_date)->toDateString()
                        : null,
                    'end_date' => $application->end_date
                        ? Carbon::parse($application->end_date)->toDateString()
                        : null,
                    'total_days' => (float) $application->total_days,
                    'status' => $this->statusLabel($application->status),
                    'raw_status' => $application->status,
                    'reason' => $application->reason,
                    'remarks' => $application->remarks,
                    'date_filed' => $application->created_at?->toDateString(),
                    'admin_approved_at' => $application->admin_approved_at?->toIso8601String(),
                    'hr_approved_at' => $application->hr_approved_at?->toIso8601String(),
                    'is_monetization' => (bool) $application->is_monetization,
                ];
            })
            ->values();

        return response()->json([
            'employee' => [
                'control_no' => $employee->control_no,
                'firstname' => $employee->firstname,
                'surname' => $employee->surname,
                'middlename' => $employee->middlename,
                'office' => $employee->office,
                'designation' => $employee->designation,
                'status' => $employee->status,
            ],
            'applications' => $applications,
        ]);
    }

    /**
     * Return leave credits ledger (Vacation/Sick) for one employee (HR only).
     */
    public function leaveCreditsLedger(Request $request, string $controlNo): JsonResponse
    {
        $account = $request->user();
        if (!$account instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $employee = Employee::findByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        $controlNoCandidates = $this->buildLedgerControlNoCandidates($controlNo, $employee);
        $trackedTypeIdsByKey = $this->resolveLedgerTrackedLeaveTypeIds();
        $typeIdToKey = [];
        foreach (['vacation', 'sick'] as $typeKey) {
            $typeId = $trackedTypeIdsByKey[$typeKey] ?? null;
            if (is_int($typeId) && $typeId > 0) {
                $typeIdToKey[$typeId] = $typeKey;
            }
        }

        $trackedTypeIds = array_keys($typeIdToKey);
        $vacationLeaveTypeId = $trackedTypeIdsByKey['vacation'] ?? null;
        $sickLeaveTypeId = $trackedTypeIdsByKey['sick'] ?? null;
        $queryTrackedTypeIds = $trackedTypeIds;
        $forcedLeaveTypeId = $trackedTypeIdsByKey['forced'] ?? null;
        if (is_int($forcedLeaveTypeId) && $forcedLeaveTypeId > 0) {
            $queryTrackedTypeIds[] = $forcedLeaveTypeId;
            $queryTrackedTypeIds = array_values(array_unique($queryTrackedTypeIds));
        }
        $balancesByType = $this->loadPreferredLedgerBalancesByType($controlNoCandidates, $trackedTypeIds);
        $runningBalances = [
            'vacation' => $this->resolveLedgerBalance($balancesByType, $trackedTypeIdsByKey['vacation'] ?? null),
            'sick' => $this->resolveLedgerBalance($balancesByType, $trackedTypeIdsByKey['sick'] ?? null),
        ];

        $transactions = [];
        if ($trackedTypeIds !== []) {
            $balanceIds = array_values(array_map(
                static fn(LeaveBalance $balance): int => (int) $balance->id,
                $balancesByType
            ));
            $balanceTypeLookup = [];
            foreach ($balancesByType as $balance) {
                if (!$balance instanceof LeaveBalance) {
                    continue;
                }

                $balanceTypeLookup[(int) $balance->id] = (int) $balance->leave_type_id;
            }

            if ($balanceIds !== []) {
                $accrualEntries = LeaveBalanceAccrualHistory::query()
                    ->whereIn('leave_balance_id', $balanceIds)
                    ->orderByDesc('accrual_date')
                    ->orderByDesc('id')
                    ->get();

                foreach ($accrualEntries as $entry) {
                    $balanceId = (int) $entry->leave_balance_id;
                    $typeId = $balanceTypeLookup[$balanceId] ?? null;
                    $typeKey = $typeId !== null ? ($typeIdToKey[(int) $typeId] ?? null) : null;
                    if ($typeKey === null) {
                        continue;
                    }

                    $accrualDate = $entry->accrual_date?->toDateString();
                    if ($accrualDate === null) {
                        continue;
                    }

                    $creditsAdded = round((float) $entry->credits_added, 2);
                    if ($creditsAdded <= 0) {
                        continue;
                    }

                    $source = strtoupper(trim((string) ($entry->source ?? '')));
                    $isManualAddSource = $source === 'HR_ADD' || str_starts_with($source, 'HR_ADD:');
                    $particulars = match (true) {
                        $isManualAddSource => 'Leave credits added',
                        default => 'Monthly accrual',
                    };

                    $transactions[] = [
                        'row_id' => 'accrual-' . (int) $entry->id,
                        'type_key' => $typeKey,
                        'transaction_date' => $accrualDate,
                        'sort_date' => $accrualDate,
                        'sort_timestamp' => (string) ($entry->created_at?->toIso8601String() ?? $accrualDate),
                        'particulars' => $particulars,
                        'action_taken' => $particulars,
                        'category' => 'earned',
                        'amount' => $creditsAdded,
                        'balance_delta' => $creditsAdded,
                    ];
                }
            }

            $approvedApplications = LeaveApplication::query()
                ->with(['logs' => function ($query) {
                    $query
                        ->where('action', LeaveApplicationLog::ACTION_HR_RECALLED)
                        ->orderByDesc('created_at')
                        ->orderByDesc('id');
                }])
                ->whereIn('status', [
                    LeaveApplication::STATUS_APPROVED,
                    LeaveApplication::STATUS_RECALLED,
                ])
                ->whereIn('employee_control_no', $controlNoCandidates)
                ->whereIn('leave_type_id', $queryTrackedTypeIds)
                ->orderByDesc('hr_approved_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get([
                    'id',
                    'leave_type_id',
                    'total_days',
                    'deductible_days',
                    'pay_mode',
                    'is_monetization',
                    'status',
                    'remarks',
                    'start_date',
                    'end_date',
                    'selected_dates',
                    'selected_date_pay_status',
                    'selected_date_coverage',
                    'hr_approved_at',
                    'created_at',
                    'updated_at',
                ]);

            foreach ($approvedApplications as $application) {
                $typeId = (int) $application->leave_type_id;
                $typeKey = $typeIdToKey[$typeId] ?? null;
                $isForcedLeave = is_int($forcedLeaveTypeId)
                    && $forcedLeaveTypeId > 0
                    && $typeId === $forcedLeaveTypeId;
                if ($isForcedLeave && $typeKey === null) {
                    // FL now deducts VL, so show it in the Vacation column.
                    $typeKey = 'vacation';
                }
                if ($typeKey === null) {
                    continue;
                }

                $transactionDate = $application->hr_approved_at?->toDateString()
                    ?? $application->created_at?->toDateString();
                if ($transactionDate === null) {
                    continue;
                }

                $totalDays = round((float) ($application->total_days ?? 0), 2);
                $deductibleDays = round((float) ($application->deductible_days ?? $totalDays), 2);
                $payMode = strtoupper(trim((string) ($application->pay_mode ?? LeaveApplication::PAY_MODE_WITH_PAY)));
                if (!in_array($payMode, [LeaveApplication::PAY_MODE_WITH_PAY, LeaveApplication::PAY_MODE_WITHOUT_PAY], true)) {
                    $payMode = LeaveApplication::PAY_MODE_WITH_PAY;
                }

                $isMonetization = (bool) $application->is_monetization;
                $withPayAmount = $isMonetization
                    ? $deductibleDays
                    : ($payMode === LeaveApplication::PAY_MODE_WITHOUT_PAY ? 0.0 : $deductibleDays);
                if ($totalDays > 0 && $withPayAmount > $totalDays) {
                    $withPayAmount = $totalDays;
                }
                $withPayAmount = round(max($withPayAmount, 0.0), 2);

                $withoutPayAmount = $isMonetization
                    ? 0.0
                    : round(max($totalDays - $withPayAmount, 0.0), 2);

                if ($withPayAmount <= 0 && $withoutPayAmount <= 0) {
                    continue;
                }

                $particulars = $isMonetization
                    ? 'Monetization'
                    : match (true) {
                        $isForcedLeave => 'Forced Leave',
                        is_int($vacationLeaveTypeId) && $typeId === $vacationLeaveTypeId => 'Vacation Leave',
                        is_int($sickLeaveTypeId) && $typeId === $sickLeaveTypeId => 'Sick Leave',
                        default => 'Leave application',
                    };
                $applicationId = (int) $application->id;
                $actionTaken = sprintf(
                    'Application #%d%s',
                    $applicationId,
                    $isMonetization ? ' (Monetization)' : ''
                );
                $inclusiveStartDate = $application->start_date?->toDateString();
                $inclusiveEndDate = $application->end_date?->toDateString();
                $inclusiveDates = $this->resolveLedgerInclusiveDates(
                    $application->selected_dates,
                    $inclusiveStartDate,
                    $inclusiveEndDate
                );
                $mergeKey = 'app-' . $applicationId;

                if ($withPayAmount > 0) {
                    $transactions[] = [
                        'row_id' => $mergeKey . '-wp',
                        'merge_key' => $mergeKey,
                        'type_key' => $typeKey,
                        'transaction_date' => $transactionDate,
                        'sort_date' => $transactionDate,
                        'sort_timestamp' => (string) (
                            $application->hr_approved_at?->toIso8601String()
                            ?? $application->created_at?->toIso8601String()
                            ?? $transactionDate
                        ),
                        'particulars' => $particulars,
                        'action_taken' => $actionTaken,
                        'inclusive_start_date' => $inclusiveStartDate,
                        'inclusive_end_date' => $inclusiveEndDate,
                        'inclusive_dates' => $inclusiveDates,
                        'category' => 'deduction_with_pay',
                        'amount' => $withPayAmount,
                        'balance_delta' => -$withPayAmount,
                    ];
                }

                if ($withoutPayAmount > 0) {
                    $transactions[] = [
                        'row_id' => $mergeKey . '-wop',
                        'merge_key' => $mergeKey,
                        'type_key' => $typeKey,
                        'transaction_date' => $transactionDate,
                        'sort_date' => $transactionDate,
                        'sort_timestamp' => (string) (
                            $application->hr_approved_at?->toIso8601String()
                            ?? $application->created_at?->toIso8601String()
                            ?? $transactionDate
                        ),
                        'particulars' => $particulars,
                        'action_taken' => $actionTaken,
                        'inclusive_start_date' => $inclusiveStartDate,
                        'inclusive_end_date' => $inclusiveEndDate,
                        'inclusive_dates' => $inclusiveDates,
                        'category' => 'deduction_without_pay',
                        'amount' => $withoutPayAmount,
                        'balance_delta' => 0.0,
                    ];
                }

                if ($application->status === LeaveApplication::STATUS_RECALLED) {
                    $recallLog = $application->relationLoaded('logs')
                        ? $application->logs->first(function (LeaveApplicationLog $log): bool {
                            return $log->action === LeaveApplicationLog::ACTION_HR_RECALLED
                                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR;
                        })
                        : null;

                    $recallOccurredAt = $recallLog?->created_at
                        ? CarbonImmutable::instance($recallLog->created_at)
                        : ($application->updated_at ? CarbonImmutable::instance($application->updated_at) : null);
                    $recallDetails = $this->resolveLedgerRecallRestorableDetails($application, $recallOccurredAt);
                    $restoredAmount = (float) ($recallDetails['days'] ?? 0.0);
                    $restoredDates = is_array($recallDetails['dates'] ?? null)
                        ? $recallDetails['dates']
                        : [];

                    $restoreTypeKey = $isForcedLeave ? 'vacation' : $typeKey;
                    if (
                        $restoreTypeKey !== null
                        && array_key_exists($restoreTypeKey, $runningBalances)
                        && $restoredAmount > 0.0
                    ) {
                        $recallDate = $recallOccurredAt?->toDateString();
                        if ($recallDate !== null) {
                            $transactions[] = [
                                'row_id' => $mergeKey . '-recall',
                                'merge_key' => $mergeKey . '-recall',
                                'type_key' => $restoreTypeKey,
                                'transaction_date' => $recallDate,
                                'sort_date' => $recallDate,
                                'sort_timestamp' => (string) ($recallOccurredAt?->toIso8601String() ?? $recallDate),
                                'particulars' => match (true) {
                                    $isForcedLeave => 'Forced Leave recalled',
                                    $typeKey === 'vacation' => 'Vacation Leave recalled',
                                    $typeKey === 'sick' => 'Sick Leave recalled',
                                    default => 'Leave recalled',
                                },
                                'action_taken' => 'Recalled by HR',
                                'inclusive_dates' => $restoredDates,
                                'category' => 'earned',
                                'amount' => $restoredAmount,
                                'balance_delta' => $restoredAmount,
                            ];
                        }
                    }
                }
            }
        }

        usort($transactions, function (array $left, array $right): int {
            $leftDate = (string) ($left['sort_date'] ?? '');
            $rightDate = (string) ($right['sort_date'] ?? '');
            if ($leftDate !== $rightDate) {
                return $leftDate < $rightDate ? 1 : -1;
            }

            $leftTimestamp = (string) ($left['sort_timestamp'] ?? '');
            $rightTimestamp = (string) ($right['sort_timestamp'] ?? '');
            if ($leftTimestamp !== $rightTimestamp) {
                return $leftTimestamp < $rightTimestamp ? 1 : -1;
            }

            $leftId = (string) ($left['row_id'] ?? '');
            $rightId = (string) ($right['row_id'] ?? '');
            return $rightId <=> $leftId;
        });

        $ledgerRows = [];
        $rowIndexByMergeKey = [];
        foreach ($transactions as $transaction) {
            $typeKey = $transaction['type_key'] ?? null;
            if (!is_string($typeKey) || !array_key_exists($typeKey, $runningBalances)) {
                continue;
            }

            $category = (string) ($transaction['category'] ?? '');
            $amount = round((float) ($transaction['amount'] ?? 0), 2);
            if ($amount <= 0) {
                continue;
            }

            $currentBalance = round((float) $runningBalances[$typeKey], 2);
            $runningBalances[$typeKey] = round(
                $currentBalance - (float) ($transaction['balance_delta'] ?? 0),
                2
            );

            $actionDate = (string) ($transaction['transaction_date'] ?? '');
            $particulars = trim((string) ($transaction['particulars'] ?? ''));
            $actionTaken = trim((string) ($transaction['action_taken'] ?? ''));
            $inclusiveStartDate = trim((string) ($transaction['inclusive_start_date'] ?? ''));
            $inclusiveEndDate = trim((string) ($transaction['inclusive_end_date'] ?? ''));
            $inclusiveDates = $transaction['inclusive_dates'] ?? null;
            if (!is_array($inclusiveDates)) {
                $inclusiveDates = [];
            }
            $period = $this->formatLedgerPeriodLabel($actionDate);

            $mergeKey = null;
            if ($category === 'earned') {
                $mergeKey = 'earned|' . mb_strtolower($actionDate . '|' . $particulars . '|' . $actionTaken);
            }

            $explicitMergeKey = trim((string) ($transaction['merge_key'] ?? ''));
            if ($explicitMergeKey !== '') {
                $mergeKey = 'tx|' . mb_strtolower($explicitMergeKey);
            }

            if ($mergeKey !== null && array_key_exists($mergeKey, $rowIndexByMergeKey)) {
                $rowIndex = $rowIndexByMergeKey[$mergeKey];
            } else {
                $rowIndex = count($ledgerRows);
                $ledgerRows[] = [
                    'id' => $transaction['row_id'] ?? null,
                    'period' => $period,
                    'particulars' => $particulars,
                    'action_date' => $actionDate,
                    'action_taken' => $actionTaken,
                    'inclusive_start_date' => $inclusiveStartDate !== '' ? $inclusiveStartDate : null,
                    'inclusive_end_date' => $inclusiveEndDate !== '' ? $inclusiveEndDate : null,
                    'inclusive_dates' => $inclusiveDates,
                ];

                if ($mergeKey !== null) {
                    $rowIndexByMergeKey[$mergeKey] = $rowIndex;
                }
            }

            if ($typeKey === 'vacation') {
                if (!array_key_exists('vacation_balance', $ledgerRows[$rowIndex])) {
                    $ledgerRows[$rowIndex]['vacation_balance'] = $currentBalance;
                }
                if ($category === 'earned') {
                    $ledgerRows[$rowIndex]['vacation_earned'] = round(
                        (float) ($ledgerRows[$rowIndex]['vacation_earned'] ?? 0) + $amount,
                        2
                    );
                } elseif ($category === 'deduction_with_pay') {
                    $ledgerRows[$rowIndex]['vacation_abs_und_wp'] = round(
                        (float) ($ledgerRows[$rowIndex]['vacation_abs_und_wp'] ?? 0) + $amount,
                        2
                    );
                } elseif ($category === 'deduction_without_pay') {
                    $ledgerRows[$rowIndex]['vacation_abs_und_wop'] = round(
                        (float) ($ledgerRows[$rowIndex]['vacation_abs_und_wop'] ?? 0) + $amount,
                        2
                    );
                }
            } elseif ($typeKey === 'sick') {
                if (!array_key_exists('sick_balance', $ledgerRows[$rowIndex])) {
                    $ledgerRows[$rowIndex]['sick_balance'] = $currentBalance;
                }
                if ($category === 'earned') {
                    $ledgerRows[$rowIndex]['sick_earned'] = round(
                        (float) ($ledgerRows[$rowIndex]['sick_earned'] ?? 0) + $amount,
                        2
                    );
                } elseif ($category === 'deduction_with_pay') {
                    $ledgerRows[$rowIndex]['sick_abs_und'] = round(
                        (float) ($ledgerRows[$rowIndex]['sick_abs_und'] ?? 0) + $amount,
                        2
                    );
                } elseif ($category === 'deduction_without_pay') {
                    $ledgerRows[$rowIndex]['sick_abs_und_wop'] = round(
                        (float) ($ledgerRows[$rowIndex]['sick_abs_und_wop'] ?? 0) + $amount,
                        2
                    );
                }
            }
        }

        return response()->json([
            'employee' => [
                'control_no' => $employee->control_no,
                'firstname' => $employee->firstname,
                'surname' => $employee->surname,
                'middlename' => $employee->middlename,
                'office' => $employee->office,
                'designation' => $employee->designation,
                'status' => $employee->status,
            ],
            'ledger' => $ledgerRows,
            'leave_balance_ledger' => $ledgerRows,
            'leave_credits_ledger' => $ledgerRows,
            'leaveCreditsLedger' => $ledgerRows,
        ]);
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            LeaveApplication::STATUS_PENDING_ADMIN => 'Pending Admin',
            LeaveApplication::STATUS_PENDING_HR => 'Pending HR',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
            LeaveApplication::STATUS_RECALLED => 'Recalled',
            default => (string) $status,
        };
    }

    /**
     * Ensure employee write endpoints are accessed only by a department admin
     * with a valid assigned department.
     */
    private function resolveDepartmentAdmin(Request $request): DepartmentAdmin|JsonResponse
    {
        $account = $request->user();
        if (!$account instanceof DepartmentAdmin) {
            return response()->json([
                'message' => 'Only department admin accounts can manage employees.',
            ], 403);
        }

        $account->loadMissing('department');
        if (!$account->department_id || !$account->department?->name) {
            return response()->json([
                'message' => 'Department admin account is not assigned to a department.',
            ], 403);
        }

        return $account;
    }

    /**
     * Validate incoming employee payload against HRIS-aligned field constraints.
     */
    private function validateEmployeePayload(
        Request $request,
        bool $isUpdate = false
    ): array {
        if ($request->has('status')) {
            $request->merge([
                'status' => strtoupper(trim((string) $request->input('status'))),
            ]);
        }

        if ($request->has('control_no')) {
            $request->merge([
                'control_no' => trim((string) $request->input('control_no')),
            ]);
        }

        $rules = [
            'surname' => ['required', 'string', 'max:255'],
            'firstname' => ['required', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(self::ALLOWED_STATUSES)],
            'designation' => ['nullable', 'string', 'max:255'],
            'rate_mon' => ['nullable', 'numeric', 'min:0'],
        ];

        if (!$isUpdate) {
            $rules['control_no'] = [
                'required',
                'string',
                'max:50',
                'regex:/^\d+$/',
                Rule::unique('tblEmployees', 'control_no'),
            ];
        }

        return $request->validate($rules);
    }

    /**
     * Normalize and map validated employee payload for tblEmployees writes.
     */
    private function normalizeEmployeePayload(array $validated, string $office, bool $includeControlNo): array
    {
        $attributes = [
            'surname' => trim((string) $validated['surname']),
            'firstname' => trim((string) $validated['firstname']),
            'middlename' => $this->trimNullable($validated['middlename'] ?? null),
            'office' => $office,
            'status' => strtoupper(trim((string) $validated['status'])),
            'designation' => $this->trimNullable($validated['designation'] ?? null),
            'rate_mon' => array_key_exists('rate_mon', $validated) && $validated['rate_mon'] !== null
                ? round((float) $validated['rate_mon'], 2)
                : null,
        ];

        if ($includeControlNo) {
            $attributes['control_no'] = trim((string) $validated['control_no']);
        }

        return $attributes;
    }

    /**
     * Validate incoming department head payload using the same fields as Add Employee.
     */
    private function validateDepartmentHeadPayload(Request $request): array
    {
        if ($request->has('status')) {
            $request->merge([
                'status' => strtoupper(trim((string) $request->input('status'))),
            ]);
        }

        if ($request->has('control_no')) {
            $request->merge([
                'control_no' => trim((string) $request->input('control_no')),
            ]);
        }

        return $request->validate([
            'control_no' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
            'surname' => ['required', 'string', 'max:255'],
            'firstname' => ['required', 'string', 'max:255'],
            'middlename' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(self::ALLOWED_STATUSES)],
            'designation' => ['nullable', 'string', 'max:255'],
            'rate_mon' => ['nullable', 'numeric', 'min:0'],
        ]);
    }

    /**
     * Normalize and map validated department head payload for tblDepartmentHeads writes.
     */
    private function normalizeDepartmentHeadPayload(array $validated, string $office): array
    {
        return [
            'control_no' => trim((string) $validated['control_no']),
            'surname' => trim((string) $validated['surname']),
            'firstname' => trim((string) $validated['firstname']),
            'middlename' => $this->trimNullable($validated['middlename'] ?? null),
            'office' => $office,
            'status' => strtoupper(trim((string) $validated['status'])),
            'designation' => $this->trimNullable($validated['designation'] ?? null),
            'rate_mon' => array_key_exists('rate_mon', $validated) && $validated['rate_mon'] !== null
                ? round((float) $validated['rate_mon'], 2)
                : null,
        ];
    }

    /**
     * Shared serializer for employee payloads used by API responses.
     */
    private function serializeEmployee(Employee $employee): array
    {
        return [
            'control_no' => $employee->control_no,
            'firstname' => $employee->firstname,
            'surname' => $employee->surname,
            'middlename' => $employee->middlename,
            'designation' => $employee->designation,
            'office' => $employee->office,
            'status' => $employee->status,
            'rate_mon' => $employee->rate_mon !== null ? (float) $employee->rate_mon : null,
        ];
    }

    private function serializeDepartmentHead(DepartmentHead $departmentHead): array
    {
        return [
            'id' => $departmentHead->id,
            'department_id' => $departmentHead->department_id,
            'control_no' => $departmentHead->control_no,
            'surname' => $departmentHead->surname,
            'firstname' => $departmentHead->firstname,
            'middlename' => $departmentHead->middlename,
            'office' => $departmentHead->office,
            'status' => $departmentHead->status,
            'designation' => $departmentHead->designation,
            'rate_mon' => $departmentHead->rate_mon !== null ? (float) $departmentHead->rate_mon : null,
            'full_name' => $departmentHead->full_name,
            'position' => $departmentHead->position,
        ];
    }

    private function serializeDepartmentHeadAsEmployee(DepartmentHead $departmentHead): array
    {
        return array_merge($this->serializeDepartmentHead($departmentHead), [
            'has_account' => false,
            'is_department_head_record' => true,
        ]);
    }

    private function buildDepartmentAdminEmployeePaginator(
        ?string $departmentName,
        ?string $searchTerm,
        int $perPage,
        int $page,
        array $departmentHeadLookup = []
    ): LengthAwarePaginator {
        $employees = Employee::query()
            ->when($departmentName, function ($query) use ($departmentName) {
                $query->where('office', $departmentName);
            })
            ->when($searchTerm, function ($query, $term) {
                $query->where(function ($q) use ($term) {
                    $q->where('firstname', 'LIKE', "%{$term}%")
                        ->orWhere('surname', 'LIKE', "%{$term}%");
                });
            })
            ->whereNotIn('control_no', function ($query) {
                $query->select('employee_control_no')
                    ->from('tblDepartmentAdmins')
                    ->whereNotNull('employee_control_no')
                    ->whereRaw("LTRIM(RTRIM(employee_control_no)) <> ''");
            })
            ->orderBy('surname')
            ->orderBy('firstname')
            ->paginate($perPage, ['*'], 'page', $page);

        $employees->getCollection()->transform(function (Employee $emp) use ($departmentHeadLookup) {
            $controlNo = trim((string) $emp->control_no);
            return array_merge($this->serializeEmployee($emp), [
                'has_account' => false,
                'is_department_head_record' => $controlNo !== '' && isset($departmentHeadLookup[$controlNo]),
            ]);
        });

        return $employees;
    }

    private function buildDepartmentAdminStatusCounts(?string $departmentName, ?string $searchTerm): array
    {
        $statusCountsQuery = Employee::query();
        if ($departmentName) {
            $statusCountsQuery->where('office', $departmentName);
        }
        if ($searchTerm) {
            $statusCountsQuery->where(function ($q) use ($searchTerm) {
                $q->where('firstname', 'LIKE', "%{$searchTerm}%")
                    ->orWhere('surname', 'LIKE', "%{$searchTerm}%");
            });
        }
        $statusCountsQuery->whereNotIn('control_no', function ($query) {
            $query->select('employee_control_no')
                ->from('tblDepartmentAdmins')
                ->whereNotNull('employee_control_no')
                ->whereRaw("LTRIM(RTRIM(employee_control_no)) <> ''");
        });

        return $statusCountsQuery
            ->selectRaw('status, COUNT(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    private function buildHrEmployeeListing(
        Request $request,
        ?string $departmentName,
        ?int $departmentId,
        ?string $searchTerm,
        int $perPage,
        int $page,
        array $departmentHeadLookup = []
    ): array {
        $employeeRows = Employee::query()
            ->when($departmentName, function ($query) use ($departmentName) {
                $query->where('office', $departmentName);
            })
            ->where(function ($q) {
                $q->whereNull('status')
                    ->orWhereRaw("UPPER(LTRIM(RTRIM(status))) <> 'CONTRACTUAL'");
            })
            ->when($searchTerm, function ($query, $term) {
                $query->where(function ($q) use ($term) {
                    $q->where('firstname', 'LIKE', "%{$term}%")
                        ->orWhere('surname', 'LIKE', "%{$term}%");
                });
            })
            ->orderBy('surname')
            ->orderBy('firstname')
            ->get()
            ->map(function (Employee $employee) use ($departmentHeadLookup): array {
                $controlNo = trim((string) $employee->control_no);
                return array_merge($this->serializeEmployee($employee), [
                    'has_account' => false,
                    'is_department_head_record' => $controlNo !== '' && isset($departmentHeadLookup[$controlNo]),
                ]);
            });

        $existingEmployeeControlNos = $employeeRows
            ->map(fn(array $employee): string => trim((string) ($employee['control_no'] ?? '')))
            ->filter()
            ->values()
            ->all();
        $existingEmployeeControlNoLookup = array_fill_keys($existingEmployeeControlNos, true);

        $departmentHeadRows = DepartmentHead::query()
            ->when($departmentId, function ($query) use ($departmentId) {
                $query->where('department_id', $departmentId);
            }, function ($query) use ($departmentName) {
                if ($departmentName) {
                    $query->where('office', $departmentName);
                }
            })
            ->orderBy('surname')
            ->orderBy('firstname')
            ->get()
            ->filter(function (DepartmentHead $departmentHead) use (
                $departmentName,
                $searchTerm,
                $existingEmployeeControlNoLookup
            ): bool {
                if (!$this->matchesDepartmentHeadEmployeeFilters($departmentHead, $searchTerm, true)) {
                    return false;
                }

                $controlNo = trim((string) ($departmentHead->control_no ?? ''));
                if ($controlNo !== '' && isset($existingEmployeeControlNoLookup[$controlNo])) {
                    return false;
                }

                return true;
            })
            ->map(fn(DepartmentHead $departmentHead): array => $this->serializeDepartmentHeadAsEmployee($departmentHead));

        $combinedRows = $employeeRows
            ->concat($departmentHeadRows)
            ->sort(function (array $left, array $right): int {
                $leftSurname = mb_strtoupper(trim((string) ($left['surname'] ?? '')));
                $rightSurname = mb_strtoupper(trim((string) ($right['surname'] ?? '')));
                if ($leftSurname !== $rightSurname) {
                    return $leftSurname <=> $rightSurname;
                }

                $leftFirstname = mb_strtoupper(trim((string) ($left['firstname'] ?? '')));
                $rightFirstname = mb_strtoupper(trim((string) ($right['firstname'] ?? '')));
                if ($leftFirstname !== $rightFirstname) {
                    return $leftFirstname <=> $rightFirstname;
                }

                return strcmp(
                    trim((string) ($left['control_no'] ?? '')),
                    trim((string) ($right['control_no'] ?? ''))
                );
            })
            ->values();

        $paginatedRows = new LengthAwarePaginator(
            $combinedRows->forPage($page, $perPage)->values(),
            $combinedRows->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'pageName' => 'page',
            ]
        );

        return [
            $paginatedRows,
            $combinedRows->count(),
            $this->buildStatusCountsFromRows($combinedRows),
        ];
    }

    private function buildStatusCountsFromRows(Collection $rows): array
    {
        $counts = [];

        foreach ($rows as $row) {
            $statusKey = strtoupper(trim((string) ($row['status'] ?? '')));
            if ($statusKey === '') {
                continue;
            }

            $counts[$statusKey] = ((int) ($counts[$statusKey] ?? 0)) + 1;
        }

        ksort($counts);

        return $counts;
    }

    private function buildDepartmentHeadLookup(?int $departmentId = null, ?string $departmentName = null): array
    {
        return DepartmentHead::query()
            ->when($departmentId !== null, function ($query) use ($departmentId) {
                $query->where('department_id', $departmentId);
            })
            ->when($departmentId === null && $departmentName, function ($query) use ($departmentName) {
                $query->where('office', $departmentName);
            })
            ->get()
            ->mapWithKeys(function (DepartmentHead $departmentHead): array {
                $controlNo = trim((string) $departmentHead->control_no);
                return $controlNo !== '' ? [$controlNo => $departmentHead] : [];
            })
            ->all();
    }

    private function syncDepartmentHeadToEmployeeRecord(DepartmentHead $departmentHead): void
    {
        $controlNo = trim((string) ($departmentHead->control_no ?? ''));
        if ($controlNo === '') {
            return;
        }

        Employee::query()->updateOrCreate(
            ['control_no' => $controlNo],
            [
                'surname' => trim((string) ($departmentHead->surname ?? '')),
                'firstname' => trim((string) ($departmentHead->firstname ?? '')),
                'middlename' => $this->trimNullable($departmentHead->middlename),
                'office' => trim((string) ($departmentHead->office ?? '')),
                'status' => strtoupper(trim((string) ($departmentHead->status ?? ''))),
                'designation' => $this->trimNullable($departmentHead->designation),
                'rate_mon' => $departmentHead->rate_mon !== null ? round((float) $departmentHead->rate_mon, 2) : null,
            ]
        );
    }

    private function shouldIncludeDepartmentHeadInEmployeeSummary(
        DepartmentHead $departmentHead,
        ?string $departmentName,
        ?string $searchTerm,
        bool $isHrAccount
    ): bool {
        if (!$this->matchesDepartmentHeadEmployeeFilters($departmentHead, $searchTerm, $isHrAccount)) {
            return false;
        }

        $controlNo = trim((string) ($departmentHead->control_no ?? ''));
        if ($controlNo === '') {
            return true;
        }

        return !Employee::query()
            ->when($departmentName, function ($query) use ($departmentName) {
                $query->where('office', $departmentName);
            })
            ->when($isHrAccount, function ($query) {
                $query->where(function ($q) {
                    $q->whereNull('status')
                        ->orWhereRaw("UPPER(LTRIM(RTRIM(status))) <> 'CONTRACTUAL'");
                });
            })
            ->when($searchTerm, function ($query, $term) {
                $query->where(function ($q) use ($term) {
                    $q->where('firstname', 'LIKE', "%{$term}%")
                        ->orWhere('surname', 'LIKE', "%{$term}%");
                });
            })
            ->where('control_no', $controlNo)
            ->exists();
    }

    private function matchesDepartmentHeadEmployeeFilters(
        DepartmentHead $departmentHead,
        ?string $searchTerm,
        bool $isHrAccount
    ): bool {
        $status = strtoupper(trim((string) ($departmentHead->status ?? '')));
        if ($isHrAccount && $status === 'CONTRACTUAL') {
            return false;
        }

        $term = trim((string) ($searchTerm ?? ''));
        if ($term === '') {
            return true;
        }

        $firstname = trim((string) ($departmentHead->firstname ?? ''));
        $surname = trim((string) ($departmentHead->surname ?? ''));

        return str_contains(strtolower($firstname), strtolower($term))
            || str_contains(strtolower($surname), strtolower($term));
    }

    private function buildDepartmentHeadFullName(array $attributes): string
    {
        return trim(implode(' ', array_filter([
            $attributes['firstname'] ?? null,
            $attributes['middlename'] ?? null,
            $attributes['surname'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && trim((string) $value) !== '')));
    }

    private function trimNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);
        return $text === '' ? null : $text;
    }

    private function buildLedgerControlNoCandidates(string $controlNo, ?Employee $employee = null): array
    {
        $rawControlNo = trim($controlNo);
        if ($rawControlNo === '') {
            return [];
        }

        $candidates = [];
        $employeeControlNo = trim((string) ($employee?->control_no ?? ''));
        if ($employeeControlNo !== '') {
            $candidates[] = $employeeControlNo;
        }

        $candidates[] = $rawControlNo;

        $normalizedControlNo = $this->normalizeLedgerControlNo($rawControlNo);
        if ($normalizedControlNo !== null) {
            $candidates[] = $normalizedControlNo;
        }

        return array_values(array_unique(array_filter(
            $candidates,
            static fn(string $value): bool => trim($value) !== ''
        )));
    }

    private function normalizeLedgerControlNo(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (!preg_match('/^\d+$/', $raw)) {
            return null;
        }

        $normalized = ltrim($raw, '0');
        return $normalized === '' ? '0' : $normalized;
    }

    private function resolveLedgerTrackedLeaveTypeIds(): array
    {
        $typeIds = [
            'vacation' => null,
            'sick' => null,
            'forced' => null,
        ];

        $leaveTypes = LeaveType::query()
            ->select(['id', 'name'])
            ->get();

        foreach ($leaveTypes as $leaveType) {
            $normalizedName = strtolower(trim((string) ($leaveType->name ?? '')));
            $typeId = (int) $leaveType->id;
            if ($typeId <= 0) {
                continue;
            }

            if (
                $typeIds['vacation'] === null
                && in_array($normalizedName, ['vacation leave', 'vacation'], true)
            ) {
                $typeIds['vacation'] = $typeId;
            }

            if (
                $typeIds['sick'] === null
                && in_array($normalizedName, ['sick leave', 'sick'], true)
            ) {
                $typeIds['sick'] = $typeId;
            }

            if (
                $typeIds['forced'] === null
                && in_array($normalizedName, ['mandatory / forced leave', 'mandatory forced leave', 'forced leave'], true)
            ) {
                $typeIds['forced'] = $typeId;
            }
        }

        return $typeIds;
    }

    private function loadPreferredLedgerBalancesByType(array $controlNoCandidates, array $trackedTypeIds): array
    {
        if ($controlNoCandidates === [] || $trackedTypeIds === []) {
            return [];
        }

        $priorityByControlNo = array_flip($controlNoCandidates);
        $preferredBalancesByType = [];

        $balances = LeaveBalance::query()
            ->whereIn('employee_control_no', $controlNoCandidates)
            ->whereIn('leave_type_id', $trackedTypeIds)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();

        foreach ($balances as $balance) {
            $typeId = (int) $balance->leave_type_id;
            if ($typeId <= 0) {
                continue;
            }

            if (!array_key_exists($typeId, $preferredBalancesByType)) {
                $preferredBalancesByType[$typeId] = $balance;
                continue;
            }

            $current = $preferredBalancesByType[$typeId];
            $currentControlNo = trim((string) $current->employee_control_no);
            $incomingControlNo = trim((string) $balance->employee_control_no);

            $currentPriority = $priorityByControlNo[$currentControlNo] ?? PHP_INT_MAX;
            $incomingPriority = $priorityByControlNo[$incomingControlNo] ?? PHP_INT_MAX;
            if ($incomingPriority < $currentPriority) {
                $preferredBalancesByType[$typeId] = $balance;
            }
        }

        return $preferredBalancesByType;
    }

    private function resolveLedgerBalance(array $balancesByType, ?int $leaveTypeId): float
    {
        if ($leaveTypeId === null || !isset($balancesByType[$leaveTypeId])) {
            return 0.0;
        }

        return round((float) ($balancesByType[$leaveTypeId]->balance ?? 0), 2);
    }

    private function formatLedgerPeriodLabel(mixed $date): string
    {
        $normalizedDate = trim((string) ($date ?? ''));
        if ($normalizedDate === '') {
            return '';
        }

        try {
            return Carbon::parse($normalizedDate)->format('M Y');
        } catch (\Throwable) {
            return '';
        }
    }

    private function resolveLedgerInclusiveDates(
        mixed $selectedDates,
        ?string $startDate,
        ?string $endDate
    ): array {
        $normalized = [];

        if (is_iterable($selectedDates)) {
            foreach ($selectedDates as $selectedDate) {
                $date = $this->normalizeLedgerDateString($selectedDate);
                if ($date !== null) {
                    $normalized[] = $date;
                }
            }
        }

        $normalized = array_values(array_unique($normalized));
        sort($normalized);
        if ($normalized !== []) {
            return $normalized;
        }

        $start = $this->normalizeLedgerDateString($startDate);
        $end = $this->normalizeLedgerDateString($endDate);
        if ($start === null || $end === null) {
            return [];
        }

        try {
            $cursor = Carbon::parse($start)->startOfDay();
            $last = Carbon::parse($end)->startOfDay();
        } catch (\Throwable) {
            return [];
        }

        if ($cursor->gt($last)) {
            return [];
        }

        $dates = [];
        $safetyLimit = 370;
        while ($cursor->lte($last) && $safetyLimit > 0) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->copy()->addDay();
            $safetyLimit--;
        }

        return $dates;
    }

    private function normalizeLedgerDateString(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveLedgerApplicationDeductibleDays(LeaveApplication $application): float
    {
        $totalDays = round(max((float) ($application->total_days ?? 0), 0.0), 2);
        if ($totalDays <= 0.0) {
            return 0.0;
        }

        if ($application->deductible_days !== null) {
            $stored = round((float) $application->deductible_days, 2);
            if ($stored < 0.0) {
                return 0.0;
            }

            return $stored > $totalDays ? $totalDays : $stored;
        }

        if ((bool) $application->is_monetization) {
            return $totalDays;
        }

        return $this->normalizeLedgerPayMode($application->pay_mode ?? null, false) === LeaveApplication::PAY_MODE_WITHOUT_PAY
            ? 0.0
            : $totalDays;
    }

    private function resolveLedgerRecallRestorableDetails(
        LeaveApplication $application,
        ?CarbonImmutable $asOfDate = null
    ): array {
        $deductibleDays = $this->resolveLedgerApplicationDeductibleDays($application);
        if ($deductibleDays <= 0.0) {
            return ['days' => 0.0, 'dates' => []];
        }

        if ((bool) $application->is_monetization) {
            return ['days' => $deductibleDays, 'dates' => []];
        }

        $selectedDates = $application->resolvedSelectedDates();
        if (!is_array($selectedDates) || $selectedDates === []) {
            return ['days' => $deductibleDays, 'dates' => []];
        }

        $normalizedPayMode = $this->normalizeLedgerPayMode($application->pay_mode ?? null, false);
        $normalizedPayStatus = $this->compactLedgerSelectedDatePayStatusMap(
            is_array($application->selected_date_pay_status) ? $application->selected_date_pay_status : null,
            $selectedDates,
            $normalizedPayMode
        );
        $normalizedCoverage = $this->compactLedgerSelectedDateCoverageMap(
            is_array($application->selected_date_coverage) ? $application->selected_date_coverage : null,
            $selectedDates
        );
        $coverageWeights = $this->resolveLedgerDateCoverageWeights(
            $selectedDates,
            $normalizedCoverage,
            round((float) ($application->total_days ?? 0), 2)
        );

        $recallDate = ($asOfDate ?? CarbonImmutable::now())->startOfDay()->toDateString();
        $restorableDays = 0.0;
        $restorableDates = [];

        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeLedgerDateKey($rawDate) ?? trim((string) $rawDate);
            if ($dateKey === '' || strcmp($dateKey, $recallDate) < 0) {
                continue;
            }

            $weight = round(max((float) ($coverageWeights[$dateKey] ?? 1.0), 0.0), 2);
            if ($weight <= 0.0) {
                continue;
            }

            $effectiveMode = $normalizedPayStatus[$dateKey] ?? $normalizedPayMode;
            $resolvedMode = $this->resolveLedgerPayModeFromStatusValue($effectiveMode) ?? $normalizedPayMode;
            if ($resolvedMode !== LeaveApplication::PAY_MODE_WITH_PAY) {
                continue;
            }

            $restorableDays += $weight;
            $restorableDates[] = $dateKey;
        }

        $restorableDays = round(max($restorableDays, 0.0), 2);
        if ($restorableDays > $deductibleDays) {
            $restorableDays = $deductibleDays;
        }

        $restorableDates = array_values(array_unique(array_filter($restorableDates)));
        sort($restorableDates);

        return [
            'days' => $restorableDays,
            'dates' => $restorableDates,
        ];
    }

    private function normalizeLedgerPayMode(mixed $payMode, bool $isMonetization = false): string
    {
        if ($isMonetization) {
            return LeaveApplication::PAY_MODE_WITH_PAY;
        }

        $normalized = strtoupper(trim((string) ($payMode ?? LeaveApplication::PAY_MODE_WITH_PAY)));
        if (in_array($normalized, [LeaveApplication::PAY_MODE_WITH_PAY, LeaveApplication::PAY_MODE_WITHOUT_PAY], true)) {
            return $normalized;
        }

        return LeaveApplication::PAY_MODE_WITH_PAY;
    }

    private function resolveLedgerPayModeFromStatusValue(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace(['_', '-'], ' ', $normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        if (in_array($normalized, ['wop', 'without pay', 'withoutpay', 'unpaid', 'no pay'], true)) {
            return LeaveApplication::PAY_MODE_WITHOUT_PAY;
        }

        if (in_array($normalized, ['wp', 'with pay', 'withpay', 'paid'], true)) {
            return LeaveApplication::PAY_MODE_WITH_PAY;
        }

        return null;
    }

    private function normalizeLedgerDateKey(mixed $rawDate): ?string
    {
        if ($rawDate === null || $rawDate === '') {
            return null;
        }

        if ($rawDate instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($rawDate)->toDateString();
        }

        $raw = trim((string) $rawDate);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^\d+$/', $raw) === 1 && strlen($raw) <= 3) {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function compactLedgerSelectedDatePayStatusMap(
        ?array $selectedDatePayStatus,
        ?array $selectedDates,
        string $payMode
    ): ?array {
        if (!is_array($selectedDatePayStatus) || $selectedDatePayStatus === []) {
            return null;
        }

        $defaultMode = $this->normalizeLedgerPayMode($payMode, false);
        $dateSet = [];
        $selectedDateLookup = [];
        if (is_array($selectedDates)) {
            foreach ($selectedDates as $index => $rawDate) {
                $rawKey = trim((string) $rawDate);
                if ($rawKey === '') {
                    continue;
                }

                $dateKey = $this->normalizeLedgerDateKey($rawDate) ?? $rawKey;
                $dateSet[$dateKey] = true;
                $selectedDateLookup[(string) $index] = $dateKey;
                $selectedDateLookup[$rawKey] = $dateKey;
            }
        }
        $restrictToSelectedDates = $dateSet !== [];

        $compacted = [];
        foreach ($selectedDatePayStatus as $rawDate => $rawStatus) {
            $rawKey = trim((string) $rawDate);
            $dateKey = $this->normalizeLedgerDateKey($rawDate);
            if ($dateKey === null && $rawKey !== '' && array_key_exists($rawKey, $selectedDateLookup)) {
                $dateKey = $selectedDateLookup[$rawKey];
            }
            if ($dateKey === null) {
                $dateKey = $rawKey;
            }
            if ($dateKey === '') {
                continue;
            }

            if ($restrictToSelectedDates && !array_key_exists($dateKey, $dateSet)) {
                continue;
            }

            $resolvedMode = $this->resolveLedgerPayModeFromStatusValue($rawStatus);
            if ($resolvedMode === null) {
                continue;
            }

            if ($restrictToSelectedDates && $resolvedMode === $defaultMode) {
                continue;
            }

            $compacted[$dateKey] = $resolvedMode;
        }

        if ($compacted === []) {
            return null;
        }

        ksort($compacted);
        return $compacted;
    }

    private function compactLedgerSelectedDateCoverageMap(
        ?array $selectedDateCoverage,
        ?array $selectedDates
    ): ?array {
        if (!is_array($selectedDateCoverage) || $selectedDateCoverage === []) {
            return null;
        }

        $dateSet = [];
        $selectedDateLookup = [];
        if (is_array($selectedDates)) {
            foreach ($selectedDates as $index => $rawDate) {
                $rawKey = trim((string) $rawDate);
                if ($rawKey === '') {
                    continue;
                }

                $dateKey = $this->normalizeLedgerDateKey($rawDate) ?? $rawKey;
                $dateSet[$dateKey] = true;
                $selectedDateLookup[(string) $index] = $dateKey;
                $selectedDateLookup[$rawKey] = $dateKey;
            }
        }
        $restrictToSelectedDates = $dateSet !== [];

        $compacted = [];
        foreach ($selectedDateCoverage as $rawDate => $rawCoverage) {
            $rawKey = trim((string) $rawDate);
            $dateKey = $this->normalizeLedgerDateKey($rawDate);
            if ($dateKey === null && $rawKey !== '' && array_key_exists($rawKey, $selectedDateLookup)) {
                $dateKey = $selectedDateLookup[$rawKey];
            }
            if ($dateKey === null) {
                $dateKey = $rawKey;
            }
            if ($dateKey === '') {
                continue;
            }

            if ($restrictToSelectedDates && !array_key_exists($dateKey, $dateSet)) {
                continue;
            }

            $coverage = strtolower(trim((string) $rawCoverage));
            if ($coverage === 'half') {
                $compacted[$dateKey] = 'half';
                continue;
            }

            if (!$restrictToSelectedDates && $coverage === 'whole') {
                $compacted[$dateKey] = 'whole';
            }
        }

        if ($compacted === []) {
            return null;
        }

        ksort($compacted);
        return $compacted;
    }

    private function resolveLedgerDateCoverageWeights(
        array $selectedDates,
        ?array $selectedDateCoverage,
        float $totalDays
    ): array {
        $resolvedDates = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeLedgerDateKey($rawDate) ?? trim((string) $rawDate);
            if ($dateKey === '') {
                continue;
            }

            $resolvedDates[$dateKey] = true;
        }

        $resolvedDates = array_keys($resolvedDates);
        sort($resolvedDates);
        if ($resolvedDates === []) {
            return [];
        }

        $coverageMap = is_array($selectedDateCoverage) ? $selectedDateCoverage : [];
        $hasCoverageOverrides = $coverageMap !== [];

        $defaultCoverageWeight = 1.0;
        $dateCount = count($resolvedDates);
        if ($dateCount > 0) {
            $halfMatch = abs(($dateCount * 0.5) - $totalDays) < 0.00001;
            $wholeMatch = abs(((float) $dateCount) - $totalDays) < 0.00001;
            if ($halfMatch) {
                $defaultCoverageWeight = 0.5;
            } elseif (!$wholeMatch) {
                $defaultCoverageWeight = max(min($totalDays / $dateCount, 1.0), 0.5);
            }
        }

        $weights = [];
        foreach ($resolvedDates as $dateKey) {
            $hasCoverageValue = array_key_exists($dateKey, $coverageMap);
            $coverage = strtolower(trim((string) ($coverageMap[$dateKey] ?? '')));
            if ($coverage === 'half') {
                $weights[$dateKey] = 0.5;
                continue;
            }

            if ($coverage === 'whole') {
                $weights[$dateKey] = 1.0;
                continue;
            }

            if ($hasCoverageOverrides && !$hasCoverageValue) {
                $weights[$dateKey] = 1.0;
                continue;
            }

            $weights[$dateKey] = $defaultCoverageWeight;
        }

        return $weights;
    }
}
