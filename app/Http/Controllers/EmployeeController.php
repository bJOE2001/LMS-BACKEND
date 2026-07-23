<?php

namespace App\Http\Controllers;

use App\Models\Department;
use App\Models\DepartmentAdmin;
use App\Models\DepartmentHead;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAccrualHistory;
use App\Models\LeaveType;
use App\Services\RecycleBinService;
use App\Services\WorkScheduleService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Employee Management - employee directory is sourced from HRIS (read-only).
 *
 * Employees are filtered by `office` (string) using the selected department name.
 */
class EmployeeController extends Controller
{
    private const LEDGER_HOURS_PER_DAY = 8;

    private const LEDGER_MINUTES_PER_HOUR = 60;

    private const LEDGER_DECIMAL_PRECISION = 3;

    private const TERMINAL_LEAVE_CONSTANT_FACTOR = 0.0478087;

    private const TERMINAL_LEAVE_RATE_PRECISION = 2;

    private const TERMINAL_LEAVE_AMOUNT_PRECISION = 12;

    private function hasLeaveApplicationLinkedSickDeductedDaysColumn(): bool
    {
        static $hasColumn;

        if ($hasColumn === null) {
            $hasColumn = Schema::hasColumn('tblLeaveApplications', 'linked_sick_leave_deducted_days');
        }

        return $hasColumn;
    }

    /**
     * List departments for the filter dropdown.
     */
    public function departments(): JsonResponse
    {
        $departments = Department::query()
            ->active()
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
     * Return the City Administrator signatory record from HRIS.
     * Accessible to HR and department admin accounts.
     */
    public function cityAdministrator(Request $request): JsonResponse
    {
        $account = $request->user();
        if (! $account instanceof HRAccount && ! $account instanceof DepartmentAdmin) {
            return response()->json([
                'message' => 'Only HR and department admin accounts can access this endpoint.',
            ], 403);
        }

        return response()->json([
            'city_administrator' => $this->serializeCityAdministratorEmployee(
                $this->resolveCityAdministratorEmployee()
            ),
        ]);
    }

    /**
     * Return the City Mayor signatory record from HRIS.
     * Accessible to HR and department admin accounts.
     */
    public function cityMayor(Request $request): JsonResponse
    {
        $account = $request->user();
        if (! $account instanceof HRAccount && ! $account instanceof DepartmentAdmin) {
            return response()->json([
                'message' => 'Only HR and department admin accounts can access this endpoint.',
            ], 403);
        }

        return response()->json([
            'city_mayor' => $this->serializeCityMayorEmployee(
                $this->resolveCityMayorEmployee()
            ),
        ]);
    }

    /**
     * Return the City Vice Mayor signatory record from HRIS.
     * Accessible to HR and department admin accounts.
     */
    public function cityViceMayor(Request $request): JsonResponse
    {
        $account = $request->user();
        if (! $account instanceof HRAccount && ! $account instanceof DepartmentAdmin) {
            return response()->json([
                'message' => 'Only HR and department admin accounts can access this endpoint.',
            ], 403);
        }

        return response()->json([
            'city_vice_mayor' => $this->serializeCityViceMayorEmployee(
                $this->resolveCityViceMayorEmployee()
            ),
        ]);
    }

    /**
     * Return the City Administrator signatory for ERMS-integrated clients.
     * Protected by the ERMS middleware route group.
     */
    public function ermsCityAdministrator(): JsonResponse
    {
        return response()->json([
            'city_administrator' => $this->serializeCityAdministratorEmployee(
                $this->resolveCityAdministratorEmployee()
            ),
        ]);
    }

    /**
     * Return the City Mayor signatory for ERMS-integrated clients.
     * Protected by the ERMS middleware route group.
     */
    public function ermsCityMayor(): JsonResponse
    {
        return response()->json([
            'city_mayor' => $this->serializeCityMayorEmployee(
                $this->resolveCityMayorEmployee()
            ),
        ]);
    }

    /**
     * Return the City Vice Mayor signatory for ERMS-integrated clients.
     * Protected by the ERMS middleware route group.
     */
    public function ermsCityViceMayor(): JsonResponse
    {
        return response()->json([
            'city_vice_mayor' => $this->serializeCityViceMayorEmployee(
                $this->resolveCityViceMayorEmployee()
            ),
        ]);
    }

    /**
     * Resolve the department head for ERMS-integrated clients.
     * Accepts department_id, department_name, or employee_control_no.
     */
    public function ermsDepartmentHead(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department_id' => ['nullable', 'integer', 'min:1'],
            'department_name' => ['nullable', 'string', 'max:255'],
            'employee_control_no' => ['nullable', 'string', 'max:64', 'regex:/^\d+$/'],
        ]);

        $departmentId = isset($validated['department_id']) ? (int) $validated['department_id'] : null;
        $departmentName = trim((string) ($validated['department_name'] ?? ''));
        $employeeControlNo = trim((string) ($validated['employee_control_no'] ?? ''));

        if ($departmentId !== null && $departmentName === '') {
            $departmentName = trim((string) (Department::query()->find($departmentId)?->name ?? ''));
        }

        if ($departmentName === '' && $employeeControlNo !== '') {
            $employee = HrisEmployee::findByControlNo($employeeControlNo, true)
                ?? HrisEmployee::findByControlNo($employeeControlNo);
            $departmentName = trim((string) ($employee?->office ?? ''));
        }

        if ($departmentId === null && $departmentName === '') {
            return response()->json([
                'department_head' => null,
            ]);
        }

        $departmentHead = null;
        if ($departmentId !== null) {
            $departmentHead = DepartmentHead::query()
                ->where('department_id', $departmentId)
                ->orderByDesc('id')
                ->first();
        }

        $normalizedDepartmentName = $this->normalizeDepartmentLookupLabel($departmentName);
        if (! $departmentHead && $normalizedDepartmentName !== '') {
            $departmentFromLibrary = Department::query()
                ->active()
                ->get()
                ->first(function (Department $department) use ($normalizedDepartmentName): bool {
                    return $this->normalizeDepartmentLookupLabel((string) $department->name) === $normalizedDepartmentName;
                });

            if ($departmentFromLibrary) {
                $departmentHead = DepartmentHead::query()
                    ->where('department_id', (int) $departmentFromLibrary->id)
                    ->orderByDesc('id')
                    ->first();
            }
        }

        if (! $departmentHead && $normalizedDepartmentName !== '') {
            $departmentHead = DepartmentHead::query()
                ->orderByDesc('id')
                ->get()
                ->first(function (DepartmentHead $candidate) use ($normalizedDepartmentName): bool {
                    return $this->normalizeDepartmentLookupLabel((string) ($candidate->office ?? ''))
                        === $normalizedDepartmentName;
                });
        }

        return response()->json([
            'department_head' => $departmentHead ? $this->serializeDepartmentHead($departmentHead) : null,
        ]);
    }

    /**
     * Create the current department head for the authenticated department admin.
     */
    public function createDepartmentHead(Request $request): JsonResponse
    {
        return $this->persistDepartmentHead($request, false);
    }

    /**
     * Update the current department head for the authenticated department admin.
     */
    public function updateDepartmentHead(Request $request): JsonResponse
    {
        return $this->persistDepartmentHead($request, true);
    }

    private function persistDepartmentHead(Request $request, bool $isUpdating): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $validated = $this->validateDepartmentHeadPayload($request);
        $employee = HrisEmployee::findByControlNo((string) ($validated['control_no'] ?? ''), true);
        if (! $employee) {
            return response()->json([
                'message' => 'Employee not found in HRIS active records.',
            ], 422);
        }

        $departmentName = trim((string) $admin->department->name);
        $employeeOffice = trim((string) ($employee->office ?? ''));
        if ($departmentName !== '' && strcasecmp($departmentName, $employeeOffice) !== 0) {
            return response()->json([
                'message' => 'Selected employee does not belong to your assigned department.',
                'employee_office' => $employeeOffice !== '' ? $employeeOffice : null,
            ], 422);
        }

        $attributes = $this->normalizeDepartmentHeadPayload($employee, $departmentName);
        $fullName = $this->buildDepartmentHeadFullName($attributes);
        $existingDepartmentHead = DepartmentHead::query()
            ->where('department_id', $admin->department_id)
            ->first();
        $payload = array_merge($attributes, [
            'department_id' => $admin->department_id,
            'full_name' => $fullName,
            'position' => $attributes['designation'],
        ]);

        if (! $isUpdating) {
            if ($existingDepartmentHead) {
                return response()->json([
                    'message' => 'A department head already exists for this department. Edit or remove the current record first.',
                    'department_head' => $this->serializeDepartmentHead($existingDepartmentHead),
                ], 422);
            }

            $departmentHead = DepartmentHead::query()->create($payload);

            return response()->json([
                'message' => 'Department head added successfully.',
                'department_head' => $this->serializeDepartmentHead($departmentHead),
            ], 201);
        }

        if (! $existingDepartmentHead) {
            return response()->json([
                'message' => 'Department head not found.',
            ], 404);
        }

        $existingDepartmentHead->fill($payload);
        $existingDepartmentHead->save();

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

        if (! $departmentHead) {
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
        if ($request->has('activity')) {
            $request->merge([
                'activity' => strtoupper(trim((string) $request->input('activity'))),
            ]);
        }

        $validated = $request->validate([
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('tblDepartments', 'id')->where(fn ($query) => $query->where('is_inactive', false)),
            ],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'activity' => ['nullable', 'string', 'in:ALL,ACTIVE,INACTIVE'],
        ]);

        $account = $request->user();
        $isHrAccount = $account instanceof HRAccount;
        $isDepartmentAdmin = $account instanceof DepartmentAdmin;

        if (! $isHrAccount && ! $isDepartmentAdmin) {
            return response()->json([
                'message' => 'Only HR and department admin accounts can access this resource.',
            ], 403);
        }

        $departmentId = $validated['department_id'] ?? null;
        $searchTerm = $validated['search'] ?? null;
        $activityFilter = strtoupper(trim((string) ($validated['activity'] ?? 'ALL')));
        $activeOnly = match ($activityFilter) {
            'ACTIVE' => true,
            'INACTIVE' => false,
            default => null,
        };
        $perPage = max(1, min(100, (int) ($validated['per_page'] ?? 15)));
        $page = max(1, (int) $request->input('page', 1));

        $departmentName = null;
        $summaryDepartmentId = null;
        if ($isDepartmentAdmin) {
            $account->loadMissing('department');

            if (! $account->department_id || ! $account->department?->name) {
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
            $departmentName = Department::query()->active()->find($departmentId)?->name;
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
                ? $this->buildControlNoLookup([$departmentHeadControlNo])
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
                $activeOnly,
                $perPage,
                $page,
                $departmentHeadLookup
            );
        } else {
            $employees = $this->buildDepartmentAdminEmployeePaginator(
                $summaryDepartmentId,
                $searchTerm,
                $activeOnly,
                $perPage,
                $page,
                $departmentHeadLookup
            );
            $totalEmployees = $employees->total();
            $statusCounts = $this->buildDepartmentAdminStatusCounts($summaryDepartmentId, $searchTerm, $activeOnly);

            if (
                $departmentHead
                && $this->shouldIncludeDepartmentHeadInEmployeeSummary(
                    $departmentHead,
                    $departmentName,
                    $searchTerm,
                    false,
                    $activeOnly
                )
            ) {
                $totalEmployees++;

                $statusKey = strtoupper(trim((string) ($departmentHead->status ?? '')));
                if ($statusKey !== '') {
                    $statusCounts[$statusKey] = ((int) ($statusCounts[$statusKey] ?? 0)) + 1;
                }
            }
        }

        if ($isHrAccount) {
            $this->attachManualLeaveCreditsUsageFlags($employees);
        }

        return response()->json([
            'employees' => $employees,
            'total_employees' => $totalEmployees,
            'status_counts' => $statusCounts,
            'activity_filter' => $activityFilter,
            'department_head' => $departmentHead ? $this->serializeDepartmentHead($departmentHead) : null,
        ]);
    }

    /**
     * Lightweight employee lookup for HR search-driven selects.
     */
    public function employeeOptions(Request $request): JsonResponse
    {
        $account = $request->user();
        if (! $account instanceof HRAccount) {
            return response()->json([
                'message' => 'Only HR accounts can access this resource.',
            ], 403);
        }

        $validated = $request->validate([
            'department_id' => [
                'nullable',
                'integer',
                Rule::exists('tblDepartments', 'id')->where(fn ($query) => $query->where('is_inactive', false)),
            ],
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
            'activity' => ['nullable', 'string', 'in:ALL,ACTIVE,INACTIVE'],
        ]);

        $departmentId = $validated['department_id'] ?? null;
        $searchTerm = trim((string) ($validated['search'] ?? ''));
        $limit = max(1, min(25, (int) ($validated['limit'] ?? 20)));
        $activityFilter = strtoupper(trim((string) ($validated['activity'] ?? 'ALL')));
        $activeOnly = match ($activityFilter) {
            'ACTIVE' => true,
            'INACTIVE' => false,
            default => null,
        };

        if ($searchTerm === '' && $departmentId === null) {
            return response()->json([
                'employees' => [],
            ]);
        }

        $departmentName = $departmentId
            ? Department::query()->active()->find($departmentId)?->name
            : null;

        $employeeRows = $this->fetchHrisEmployeeRows(
            $departmentName,
            $searchTerm,
            false,
            $activeOnly,
            $limit * 2
        );

        $existingEmployeeControlNoLookup = $this->buildControlNoLookup(
            $employeeRows
                ->map(fn (array $employee): string => trim((string) ($employee['control_no'] ?? '')))
                ->filter()
                ->values()
                ->all()
        );

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
                $searchTerm,
                $existingEmployeeControlNoLookup,
                $activeOnly
            ): bool {
                if (! $this->matchesDepartmentHeadEmployeeFilters($departmentHead, $searchTerm, true, $activeOnly)) {
                    return false;
                }

                $controlNo = trim((string) ($departmentHead->control_no ?? ''));
                if ($controlNo !== '' && $this->hasControlNoInLookup($controlNo, $existingEmployeeControlNoLookup)) {
                    return false;
                }

                return true;
            })
            ->map(fn (DepartmentHead $departmentHead): array => $this->serializeDepartmentHeadAsEmployee($departmentHead));

        $combinedRows = $this->sortEmployeeRows(
            $employeeRows
                ->concat($departmentHeadRows)
                ->values()
        )
            ->take($limit)
            ->values();

        return response()->json([
            'employees' => $combinedRows,
        ]);
    }

    /**
     * Lightweight employee lookup for the department-admin "pull employee" dialog.
     */
    public function adminEmployeeOptions(Request $request): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ]);

        $searchTerm = trim((string) ($validated['search'] ?? ''));
        $limit = max(1, min(25, (int) ($validated['limit'] ?? 20)));
        $pulledEmployeeLookup = $this->loadPulledEmployeeControlNoLookup();

        $rows = HrisEmployee::allCached(true)
            ->filter(function (object $employee) use ($searchTerm, $pulledEmployeeLookup): bool {
                if ($this->hasControlNoInLookup((string) ($employee->control_no ?? ''), $pulledEmployeeLookup)) {
                    return false;
                }

                return $this->matchesHrisEmployeeSearch($employee, $searchTerm);
            })
            ->map(fn (object $employee): array => $this->serializeEmployee($employee))
            ->pipe(fn (Collection $employeeRows): Collection => $this->sortEmployeeRows($employeeRows))
            ->take($limit)
            ->values();

        return response()->json([
            'employees' => $rows,
        ]);
    }

    /**
     * Employee directory is read-only and sourced from HRIS.
     */
    public function store(Request $request): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $validated = $request->validate([
            'control_no' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
        ]);

        return $this->assignEmployeeToDepartment($admin, (string) $validated['control_no']);
    }

    /**
     * Employee directory is read-only and sourced from HRIS.
     */
    public function update(Request $request, string $controlNo): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->assignEmployeeToDepartment($admin, $controlNo);
    }

    /**
     * Employee directory is read-only and sourced from HRIS.
     */
    public function destroy(Request $request, string $controlNo): JsonResponse
    {
        $admin = $this->resolveDepartmentAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $assignment = $this->findPulledEmployeeAssignment($controlNo, (int) $admin->department_id);

        if (! $assignment) {
            $otherOfficeAssignment = $this->findPulledEmployeeAssignment($controlNo);
            if ($otherOfficeAssignment) {
                return response()->json([
                    'message' => 'This employee is already pulled by another office.',
                ], 403);
            }

            return response()->json([
                'message' => 'Employee is not in your pulled LMS employee list.',
            ], 404);
        }

        $assignment->delete();

        return response()->json([
            'message' => 'Employee removed from your pulled LMS employee list successfully.',
        ]);
    }

    /**
     * Return leave application history for one employee (HR and department admin).
     */
    public function leaveHistory(Request $request, string $controlNo): JsonResponse
    {
        $account = $request->user();
        if ($account instanceof DepartmentAdmin) {
            $account->loadMissing('department');
            if (! $account->department_id || ! $account->department?->name) {
                return response()->json([
                    'message' => 'Department admin account is not assigned to a department.',
                ], 403);
            }

            $hasPulledEmployeeAssignment = $this->findPulledEmployeeAssignment($controlNo, (int) $account->department_id) !== null;
            $isDepartmentHead = $this->matchesDepartmentHeadControlNo((int) $account->department_id, $controlNo);

            if (! $hasPulledEmployeeAssignment && ! $isDepartmentHead) {
                return response()->json([
                    'message' => 'Employee is not in your department assignment list.',
                ], 403);
            }
        } elseif (! $account instanceof HRAccount) {
            return response()->json([
                'message' => 'Only HR and department admin accounts can access this endpoint.',
            ], 403);
        }

        $employee = HrisEmployee::findByControlNo($controlNo);
        if (! $employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        $controlNoCandidates = $this->buildLedgerControlNoCandidates($controlNo, $employee);
        $applications = LeaveApplication::with(['leaveType'])
            ->whereIn('employee_control_no', $controlNoCandidates)
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
                    'details_of_leave' => $application->details_of_leave,
                    'selected_date_half_day_portion' => is_array($application->selected_date_half_day_portion) ? $application->selected_date_half_day_portion : null,
                    'remarks' => $application->remarks,
                    'date_filed' => $application->created_at?->toDateString(),
                    'admin_approved_at' => $application->admin_approved_at?->toIso8601String(),
                    'hr_approved_at' => $application->hr_approved_at?->toIso8601String(),
                    'is_monetization' => (bool) $application->is_monetization,
                ];
            })
            ->values();

        $trackedTypeIdsByKey = $this->resolveLedgerTrackedLeaveTypeIds();
        $vacationLeaveTypeId = $trackedTypeIdsByKey['vacation'] ?? null;
        $sickLeaveTypeId = $trackedTypeIdsByKey['sick'] ?? null;
        $trackedTerminalLeaveTypeIds = [];
        if (is_int($vacationLeaveTypeId) && $vacationLeaveTypeId > 0) {
            $trackedTerminalLeaveTypeIds[] = $vacationLeaveTypeId;
        }
        if (is_int($sickLeaveTypeId) && $sickLeaveTypeId > 0) {
            $trackedTerminalLeaveTypeIds[] = $sickLeaveTypeId;
        }

        $balancesByType = $this->loadPreferredLedgerBalancesByType(
            $controlNoCandidates,
            $trackedTerminalLeaveTypeIds
        );
        $vacationLeaveBalance = $this->resolveLedgerBalance($balancesByType, $vacationLeaveTypeId);
        $sickLeaveBalance = $this->resolveLedgerBalance($balancesByType, $sickLeaveTypeId);
        $monthlyRate = $employee->rate_mon !== null
            ? round((float) $employee->rate_mon, self::TERMINAL_LEAVE_RATE_PRECISION)
            : null;
        $terminalLeaveEstimatedAmount = null;
        if ($monthlyRate !== null) {
            $terminalLeaveEstimatedAmount = round(
                ($sickLeaveBalance + $vacationLeaveBalance)
                    * $monthlyRate
                    * self::TERMINAL_LEAVE_CONSTANT_FACTOR,
                self::TERMINAL_LEAVE_AMOUNT_PRECISION
            );
        }

        return response()->json([
            'employee' => [
                'control_no' => $employee->control_no,
                'firstname' => $employee->firstname,
                'surname' => $employee->surname,
                'middlename' => $employee->middlename,
                'office' => $employee->office,
                'officeAcronym' => $this->trimOrBlank($employee->officeAcronym ?? null),
                'office_acronym' => $this->trimOrBlank($employee->officeAcronym ?? $employee->office_acronym ?? null),
                'hris_office' => $this->trimOrBlank($employee->hris_office ?? null),
                'hrisOfficeAcronym' => $this->trimOrBlank($employee->hrisOfficeAcronym ?? null),
                'hris_office_acronym' => $this->trimOrBlank($employee->hrisOfficeAcronym ?? $employee->hris_office_acronym ?? null),
                'assigned_department_name' => $this->trimOrBlank($employee->assigned_department_name ?? null),
                'assignedDepartmentAcronym' => $this->trimOrBlank($employee->assignedDepartmentAcronym ?? $employee->assigned_department_acronym ?? null),
                'assigned_department_acronym' => $this->trimOrBlank($employee->assignedDepartmentAcronym ?? $employee->assigned_department_acronym ?? null),
                'designation' => $employee->designation,
                'status' => $employee->status,
                'rate_mon' => $monthlyRate,
                'vl_balance' => $vacationLeaveBalance,
                'sl_balance' => $sickLeaveBalance,
                'terminal_leave_estimated_amount' => $terminalLeaveEstimatedAmount,
            ],
            'applications' => $applications,
        ]);
    }

    /**
     * Return leave credits ledger (Vacation/Sick/Other) for one employee (HR only).
     */
    public function leaveCreditsLedger(Request $request, string $controlNo): JsonResponse
    {
        $account = $request->user();
        if (! $account instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $employee = HrisEmployee::findByControlNo($controlNo);
        if (! $employee) {
            return response()->json(['message' => 'Employee not found.'], 404);
        }

        $controlNoCandidates = $this->buildLedgerControlNoCandidates($controlNo, $employee);
        $trackedTypeIdsByKey = $this->resolveLedgerTrackedLeaveTypeIds();
        $otherUsageOnlyTypeIds = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $typeId): int => (int) $typeId,
                $trackedTypeIdsByKey['other_usage_only'] ?? []
            ),
            static fn (int $typeId): bool => $typeId > 0
        )));
        $typeIdToKey = [];
        foreach (['vacation', 'sick'] as $typeKey) {
            $typeId = $trackedTypeIdsByKey[$typeKey] ?? null;
            if (is_int($typeId) && $typeId > 0) {
                $typeIdToKey[$typeId] = $typeKey;
            }
        }

        $otherTypeIds = [];
        $otherTypeCodeById = [];
        $trackedOtherTypeIds = $trackedTypeIdsByKey['other'] ?? [];
        if (is_array($trackedOtherTypeIds)) {
            foreach ($trackedOtherTypeIds as $typeId) {
                $normalizedTypeId = (int) $typeId;
                if ($normalizedTypeId <= 0) {
                    continue;
                }

                $otherTypeIds[] = $normalizedTypeId;
                $trackedCode = $trackedTypeIdsByKey['other_code_by_id'][$normalizedTypeId] ?? null;
                if (is_string($trackedCode) && trim($trackedCode) !== '') {
                    $otherTypeCodeById[$normalizedTypeId] = strtoupper(trim($trackedCode));
                }
            }
        }

        $mc06RelatedTypeIds = $trackedTypeIdsByKey['mc06_related'] ?? [];
        if (is_array($mc06RelatedTypeIds)) {
            foreach ($mc06RelatedTypeIds as $typeId) {
                $normalizedTypeId = (int) $typeId;
                if ($normalizedTypeId > 0) {
                    $otherTypeIds[] = $normalizedTypeId;
                    $otherTypeCodeById[$normalizedTypeId] = 'MC06';
                }
            }
        }
        $otherTypeCodeFallbackByKey = [
            'mc06' => 'MC06',
            'wellness' => 'WL',
        ];
        foreach (['mc06', 'wellness'] as $otherTypeKey) {
            $otherTypeId = $trackedTypeIdsByKey[$otherTypeKey] ?? null;
            if (is_int($otherTypeId) && $otherTypeId > 0) {
                $otherTypeIds[] = $otherTypeId;
                $otherTypeCodeById[$otherTypeId] = $otherTypeCodeFallbackByKey[$otherTypeKey] ?? null;
            }
        }
        $otherTypeIds = array_values(array_unique($otherTypeIds));
        foreach ($otherTypeIds as $otherTypeId) {
            $typeIdToKey[$otherTypeId] = 'other';
        }

        $trackedTypeIds = array_keys($typeIdToKey);
        $balanceKeyByTypeId = [];
        foreach ($typeIdToKey as $typeId => $mappedTypeKey) {
            $balanceKey = $this->resolveLedgerRunningBalanceKey($mappedTypeKey, (int) $typeId);
            if (is_string($balanceKey) && $balanceKey !== '') {
                $balanceKeyByTypeId[(int) $typeId] = $balanceKey;
            }
        }

        $vacationLeaveTypeId = $trackedTypeIdsByKey['vacation'] ?? null;
        $sickLeaveTypeId = $trackedTypeIdsByKey['sick'] ?? null;
        $queryTrackedTypeIds = $trackedTypeIds;
        $forcedLeaveTypeId = $trackedTypeIdsByKey['forced'] ?? null;
        if (is_int($forcedLeaveTypeId) && $forcedLeaveTypeId > 0) {
            $queryTrackedTypeIds[] = $forcedLeaveTypeId;
            $queryTrackedTypeIds = array_values(array_unique($queryTrackedTypeIds));
        }
        $balancesByType = $this->loadPreferredLedgerBalancesByType($controlNoCandidates, $trackedTypeIds);
        $preferredBalancesByBalanceKey = [];
        foreach ($balancesByType as $typeId => $balance) {
            if (! $balance instanceof LeaveBalance) {
                continue;
            }

            $balanceKey = $balanceKeyByTypeId[(int) $typeId] ?? null;
            if (! is_string($balanceKey) || $balanceKey === '') {
                continue;
            }

            if (
                ! array_key_exists($balanceKey, $preferredBalancesByBalanceKey)
                || $this->shouldPreferLedgerBalanceRecord($balance, $preferredBalancesByBalanceKey[$balanceKey])
            ) {
                $preferredBalancesByBalanceKey[$balanceKey] = $balance;
            }
        }
        $runningBalances = [
            'vacation' => $this->resolveLedgerBalanceByKey($preferredBalancesByBalanceKey, 'vacation'),
            'sick' => $this->resolveLedgerBalanceByKey($preferredBalancesByBalanceKey, 'sick'),
        ];
        foreach ($otherTypeIds as $otherTypeId) {
            $balanceKey = $balanceKeyByTypeId[$otherTypeId] ?? null;
            if (! is_string($balanceKey) || $balanceKey === '' || array_key_exists($balanceKey, $runningBalances)) {
                continue;
            }

            $runningBalances[$balanceKey] = $this->resolveLedgerBalanceByKey(
                $preferredBalancesByBalanceKey,
                $balanceKey
            );
        }
        $currentLedgerBalances = $runningBalances;

        $transactions = [];
        if ($trackedTypeIds !== []) {
            $balanceIds = [];
            $balanceTypeLookup = [];
            $balanceKeyLookup = [];
            foreach ($preferredBalancesByBalanceKey as $balanceKey => $balance) {
                if (! $balance instanceof LeaveBalance) {
                    continue;
                }

                $balanceId = (int) $balance->id;
                if ($balanceId <= 0) {
                    continue;
                }

                $balanceIds[] = $balanceId;
                $balanceTypeLookup[$balanceId] = (int) $balance->leave_type_id;
                $balanceKeyLookup[$balanceId] = $balanceKey;
            }
            $balanceIds = array_values(array_unique($balanceIds));

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
                    $balanceKey = $balanceKeyLookup[$balanceId]
                        ?? ($typeId !== null ? ($balanceKeyByTypeId[(int) $typeId] ?? null) : null);
                    if ($typeKey === null || ! is_string($balanceKey) || $balanceKey === '') {
                        continue;
                    }

                    $accrualDate = $entry->accrual_date?->toDateString();
                    if ($accrualDate === null) {
                        continue;
                    }

                    $creditsAdded = $this->roundLedgerValue($entry->credits_added);
                    $source = strtoupper(trim((string) ($entry->source ?? '')));
                    if ($creditsAdded === 0.0 && ! str_starts_with($source, 'AUTOMATED')) {
                        continue;
                    }

                    $isManualAddSource = $source === 'HR_ADD' || str_starts_with($source, 'HR_ADD:');
                    $isManualEditSource = $source === 'HR_EDIT' || str_starts_with($source, 'HR_EDIT:');
                    $isManualBalanceSource = $isManualAddSource || $isManualEditSource;
                    $isForcedLeaveBalanceEntry = is_int($forcedLeaveTypeId)
                        && $forcedLeaveTypeId > 0
                        && (int) $typeId === $forcedLeaveTypeId;
                    $actionTaken = match (true) {
                        $isManualAddSource => 'Initial leave balance',
                        $isManualEditSource => 'Leave credits adjusted',
                        default => 'Monthly accrual',
                    };
                    $isNegativeAdjustment = ! $isManualBalanceSource && $creditsAdded < 0;
                    $otherTypeCode = $typeKey === 'other'
                        ? ($otherTypeCodeById[(int) $typeId] ?? null)
                        : null;
                    $entryKind = $isManualBalanceSource || ! $isNegativeAdjustment ? 'earned' : 'deduction';
                    $displayAmount = abs($creditsAdded);
                    $entryCategory = $isManualBalanceSource
                        ? 'balance_only'
                        : ($isNegativeAdjustment
                        ? 'deduction_with_pay'
                        : 'earned');
                    $shouldMergeMonthlyVlSlAccrual = ! $isManualBalanceSource
                        && $entryCategory === 'earned'
                        && in_array($typeKey, ['vacation', 'sick'], true);
                    $accrualMergeKey = $shouldMergeMonthlyVlSlAccrual
                        ? 'monthly-accrual-vl-sl-'.$accrualDate
                        : null;
                    $accrualParticulars = $shouldMergeMonthlyVlSlAccrual
                        ? 'VL/SL 0-0-0'
                        : $this->buildLedgerParticulars(
                            $entryKind,
                            $typeKey,
                            $displayAmount,
                            false,
                            false,
                            is_string($otherTypeCode) ? $otherTypeCode : null
                        );
                    $leaveTypeCode = $this->resolveLedgerTypeCode(
                        $typeKey,
                        is_string($otherTypeCode) ? $otherTypeCode : null,
                    );

                    $transactions[] = [
                        'row_id' => 'accrual-'.(int) $entry->id,
                        'merge_key' => $accrualMergeKey,
                        'type_key' => $typeKey,
                        'balance_key' => $balanceKey,
                        'leave_type_code' => $leaveTypeCode,
                        'transaction_date' => $accrualDate,
                        'sort_date' => $accrualDate,
                        'sort_timestamp' => (string) ($entry->created_at?->toIso8601String() ?? $accrualDate),
                        'particulars' => $accrualParticulars,
                        'action_taken' => $actionTaken,
                        'category' => $entryCategory,
                        'amount' => $displayAmount,
                        'balance_delta' => $creditsAdded,
                        'suppress_display' => $isForcedLeaveBalanceEntry,
                        'allow_zero_display' => ($creditsAdded === 0.0 && str_starts_with($source, 'AUTOMATED')),
                    ];
                }
            }

            $approvedApplicationColumns = [
                'id',
                'leave_type_id',
                'total_days',
                'deductible_days',
                'without_pay_days',
                'linked_forced_leave_deducted_days',
                'linked_vacation_leave_deducted_days',
                'pay_mode',
                'is_monetization',
                'monetization_leave_credits',
                'status',
                'remarks',
                'start_date',
                'end_date',
                'selected_dates',
                'selected_date_pay_status',
                'selected_date_coverage',
                'recall_effective_date',
                'recall_selected_dates',
                'hr_approved_at',
                'created_at',
                'updated_at',
            ];
            if ($this->hasLeaveApplicationLinkedSickDeductedDaysColumn()) {
                $approvedApplicationColumns[] = 'linked_sick_leave_deducted_days';
            }

            $approvedApplications = LeaveApplication::query()
                ->with(['logs' => function ($query) {
                    $query
                        ->where('action', LeaveApplicationLog::ACTION_HR_RECALLED)
                        ->orderByDesc('created_at')
                        ->orderByDesc('id');
                }])
                ->where(function ($query) {
                    $query->whereIn('status', [
                        LeaveApplication::STATUS_APPROVED,
                        LeaveApplication::STATUS_RECALLED,
                    ])->orWhere(function ($subQuery) {
                        $subQuery->whereIn('status', [
                            LeaveApplication::STATUS_PENDING_HR,
                            LeaveApplication::STATUS_PENDING_ADMIN,
                        ])->whereExists(function ($reqQuery) {
                            $reqQuery->select(\Illuminate\Support\Facades\DB::raw(1))
                                ->from('tblLeaveApplicationUpdateRequests as req')
                                ->whereColumn('req.leave_application_id', 'tblLeaveApplications.id')
                                ->where('req.status', 'PENDING')
                                ->whereRaw('UPPER(LTRIM(RTRIM(req.previous_status))) = ?', [LeaveApplication::STATUS_APPROVED]);
                        });
                    });
                })
                ->whereIn('employee_control_no', $controlNoCandidates)
                ->whereIn('leave_type_id', $queryTrackedTypeIds)
                ->orderByDesc('hr_approved_at')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get($approvedApplicationColumns);

            $inferredForcedLeaveDeductions = [
                'by_application_id' => [],
                'total' => 0.0,
            ];
            if (is_int($forcedLeaveTypeId) && $forcedLeaveTypeId > 0) {
                $forcedBalanceKey = $balanceKeyByTypeId[$forcedLeaveTypeId]
                    ?? $this->resolveLedgerRunningBalanceKey('other', $forcedLeaveTypeId, true);

                if (is_string($forcedBalanceKey) && $forcedBalanceKey !== '' && array_key_exists($forcedBalanceKey, $runningBalances)) {
                    $inferredForcedLeaveDeductions = $this->resolveLedgerInferredForcedLeaveDeductions(
                        $approvedApplications,
                        $forcedLeaveTypeId,
                        $this->roundLedgerValue($runningBalances[$forcedBalanceKey])
                    );
                    $inferredForcedLeaveTotal = $this->roundLedgerValue($inferredForcedLeaveDeductions['total'] ?? 0.0);
                    if ($inferredForcedLeaveTotal > 0.0) {
                        $runningBalances[$forcedBalanceKey] = $this->roundLedgerValue(
                            (float) $runningBalances[$forcedBalanceKey] - $inferredForcedLeaveTotal
                        );
                    }
                }
            }
            $currentLedgerBalances = $runningBalances;

            foreach ($approvedApplications as $application) {
                $typeId = (int) $application->leave_type_id;
                $typeKey = $typeIdToKey[$typeId] ?? null;
                $isForcedLeave = is_int($forcedLeaveTypeId)
                    && $forcedLeaveTypeId > 0
                    && $typeId === $forcedLeaveTypeId;
                if ($isForcedLeave && $typeKey === null) {
                    $typeKey = 'other';
                }
                if ($typeKey === null) {
                    continue;
                }
                $balanceKey = $balanceKeyByTypeId[$typeId]
                    ?? $this->resolveLedgerRunningBalanceKey($typeKey, $typeId, $isForcedLeave);
                if (! is_string($balanceKey) || $balanceKey === '') {
                    continue;
                }
                $isUsageOnlyOtherType = $typeKey === 'other'
                    && in_array($typeId, $otherUsageOnlyTypeIds, true);

                $transactionDate = $application->hr_approved_at?->toDateString()
                    ?? $application->created_at?->toDateString();
                if ($transactionDate === null) {
                    continue;
                }

                $totalDays = round((float) ($application->total_days ?? 0), 2);
                $deductibleDays = round((float) ($application->deductible_days ?? $totalDays), 3);
                $payMode = strtoupper(trim((string) ($application->pay_mode ?? LeaveApplication::PAY_MODE_WITH_PAY)));
                if (! in_array($payMode, [LeaveApplication::PAY_MODE_WITH_PAY, LeaveApplication::PAY_MODE_WITHOUT_PAY], true)) {
                    $payMode = LeaveApplication::PAY_MODE_WITH_PAY;
                }

                $isMonetization = (bool) $application->is_monetization;
                $payAmounts = $this->resolveLedgerApplicationPayAmounts(
                    $application,
                    $totalDays,
                    $deductibleDays,
                    $payMode,
                    $isMonetization
                );
                $withPayAmount = round(max((float) ($payAmounts['with_pay'] ?? 0.0), 0.0), 3);
                $linkedVacationWithPayAmount = round(
                    max((float) ($application->linked_vacation_leave_deducted_days ?? 0), 0.0),
                    3
                );
                $linkedForcedWithPayAmount = round(
                    max((float) ($application->linked_forced_leave_deducted_days ?? 0), 0.0),
                    3
                );
                if ($isForcedLeave && $linkedForcedWithPayAmount <= 0.0) {
                    $linkedForcedWithPayAmount = round(
                        max((float) ($inferredForcedLeaveDeductions['by_application_id'][(int) $application->id] ?? 0.0), 0.0),
                        3
                    );
                }
                $linkedSickWithPayAmount = round(
                    max((float) ($application->linked_sick_leave_deducted_days ?? 0), 0.0),
                    3
                );
                if ($typeKey !== 'vacation' && ! $isForcedLeave) {
                    $linkedForcedWithPayAmount = 0.0;
                }
                if ($typeKey === 'vacation') {
                    $linkedVacationWithPayAmount = 0.0;
                }
                if ($typeKey === 'sick') {
                    $linkedSickWithPayAmount = 0.0;
                }
                $linkedForcedWithPayAmount = round(min($linkedForcedWithPayAmount, $withPayAmount), self::LEDGER_DECIMAL_PRECISION);
                $linkedVacationWithPayAmount = round(min($linkedVacationWithPayAmount, $withPayAmount), self::LEDGER_DECIMAL_PRECISION);
                $linkedSickWithPayAmount = round(min($linkedSickWithPayAmount, $withPayAmount), self::LEDGER_DECIMAL_PRECISION);
                $primaryWithPayAmount = $isForcedLeave
                    ? 0.0
                    : round(
                        max($withPayAmount - $linkedVacationWithPayAmount - $linkedSickWithPayAmount, 0.0),
                        self::LEDGER_DECIMAL_PRECISION
                    );

                $withoutPayAmount = round(
                    max((float) ($payAmounts['without_pay'] ?? 0.0), 0.0),
                    self::LEDGER_DECIMAL_PRECISION
                );

                $monetizationComponents = $isMonetization
                    ? $this->resolveLedgerMonetizationComponents(
                        $application,
                        $typeIdToKey,
                        $typeKey,
                        $deductibleDays
                    )
                    : [];

                if ($isMonetization && $monetizationComponents === []) {
                    continue;
                }

                if (
                    ! $isMonetization
                    && $primaryWithPayAmount <= 0
                    && $linkedForcedWithPayAmount <= 0
                    && $linkedVacationWithPayAmount <= 0
                    && $linkedSickWithPayAmount <= 0
                    && $withoutPayAmount <= 0
                ) {
                    continue;
                }

                // Ledger particulars should reflect the requested leave duration,
                // not schedule-inflated credit deductions. A whole-day leave stays
                // `1-0-0` here even when a 10-hour schedule deducts 1.25 credits.
                $otherTypeCode = $typeKey === 'other'
                    ? ($otherTypeCodeById[$typeId] ?? null)
                    : null;
                $leaveTypeCode = $this->resolveLedgerTypeCode(
                    $typeKey,
                    is_string($otherTypeCode) ? $otherTypeCode : null,
                    $isForcedLeave,
                );
                $particularsPrefixOverride = $this->resolveLedgerParticularsPrefixOverride(
                    $typeKey,
                    $linkedVacationWithPayAmount,
                    $linkedSickWithPayAmount
                );
                $particulars = $this->buildLedgerParticulars(
                    'deduction',
                    $typeKey,
                    $totalDays,
                    $isMonetization,
                    $isForcedLeave,
                    is_string($otherTypeCode) ? $otherTypeCode : null,
                    $particularsPrefixOverride
                );
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
                $mergeKey = 'app-'.$applicationId;

                if ($isMonetization) {
                    foreach ($monetizationComponents as $component) {
                        $componentTypeKey = (string) $component['type_key'];
                        $componentAmount = round(max((float) $component['days'], 0.0), self::LEDGER_DECIMAL_PRECISION);
                        if ($componentAmount <= 0.0) {
                            continue;
                        }

                        $transactions[] = [
                            'row_id' => $mergeKey.'-monetization-'.$componentTypeKey,
                            'merge_key' => $mergeKey,
                            'type_key' => $componentTypeKey,
                            'balance_key' => $this->resolveLedgerRunningBalanceKey($componentTypeKey),
                            'leave_type_code' => $this->resolveLedgerTypeCode($componentTypeKey),
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
                            'amount' => $componentAmount,
                            'balance_delta' => -$componentAmount,
                        ];
                    }
                } elseif ($primaryWithPayAmount > 0) {
                    $transactions[] = [
                        'row_id' => $mergeKey.'-wp',
                        'merge_key' => $mergeKey,
                        'type_key' => $typeKey,
                        'balance_key' => $balanceKey,
                        'leave_type_code' => $leaveTypeCode,
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
                        'amount' => $primaryWithPayAmount,
                        'balance_delta' => $isUsageOnlyOtherType ? 0.0 : -$primaryWithPayAmount,
                    ];
                }

                if (! $isMonetization && $linkedForcedWithPayAmount > 0 && is_int($forcedLeaveTypeId)) {
                    $forcedBalanceKey = $balanceKeyByTypeId[$forcedLeaveTypeId]
                        ?? $this->resolveLedgerRunningBalanceKey('other', $forcedLeaveTypeId, true);

                    if (is_string($forcedBalanceKey) && $forcedBalanceKey !== '') {
                        $transactions[] = [
                            'row_id' => $mergeKey.'-fl-linked',
                            'merge_key' => $mergeKey,
                            'type_key' => 'other',
                            'balance_key' => $forcedBalanceKey,
                            'leave_type_code' => $this->resolveLedgerTypeCode('other', 'FL', true),
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
                            'amount' => $linkedForcedWithPayAmount,
                            'balance_delta' => -$linkedForcedWithPayAmount,
                            'suppress_display' => true,
                        ];
                    }
                }

                if (! $isMonetization && $linkedVacationWithPayAmount > 0) {
                    $transactions[] = [
                        'row_id' => $mergeKey.'-vl-topup',
                        'merge_key' => $mergeKey,
                        'type_key' => 'vacation',
                        'balance_key' => 'vacation',
                        'leave_type_code' => $this->resolveLedgerTypeCode('vacation'),
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
                        'amount' => $linkedVacationWithPayAmount,
                        'balance_delta' => -$linkedVacationWithPayAmount,
                    ];
                }

                if (! $isMonetization && $linkedSickWithPayAmount > 0) {
                    $transactions[] = [
                        'row_id' => $mergeKey.'-sl-topup',
                        'merge_key' => $mergeKey,
                        'type_key' => 'sick',
                        'balance_key' => 'sick',
                        'leave_type_code' => $this->resolveLedgerTypeCode('sick'),
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
                        'amount' => $linkedSickWithPayAmount,
                        'balance_delta' => -$linkedSickWithPayAmount,
                    ];
                }

                if (! $isMonetization && $withoutPayAmount > 0) {
                    $transactions[] = [
                        'row_id' => $mergeKey.'-wop',
                        'merge_key' => $mergeKey,
                        'type_key' => $isForcedLeave ? 'vacation' : $typeKey,
                        'balance_key' => $isForcedLeave ? 'vacation' : $balanceKey,
                        'leave_type_code' => $isForcedLeave ? $this->resolveLedgerTypeCode('vacation') : $leaveTypeCode,
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

                $storedRecallDateKeys = $this->resolveLedgerStoredRecallDateKeys($application);
                if ($storedRecallDateKeys !== []) {
                    $recallLog = $application->relationLoaded('logs')
                        ? $application->logs->first(function (LeaveApplicationLog $log): bool {
                            return $log->action === LeaveApplicationLog::ACTION_HR_RECALLED
                                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR;
                        })
                        : null;

                    $recallOccurredAt = $recallLog?->created_at
                        ? CarbonImmutable::instance($recallLog->created_at)
                        : ($application->updated_at ? CarbonImmutable::instance($application->updated_at) : null);
                    $recallEffectiveAt = $application->recall_effective_date
                        ? CarbonImmutable::parse((string) $application->recall_effective_date)->startOfDay()
                        : (! empty($storedRecallDateKeys) ? CarbonImmutable::parse($storedRecallDateKeys[0])->startOfDay() : $recallOccurredAt);
                    $recallDetails = $this->resolveLedgerRecallRestorableDetails($application, $storedRecallDateKeys, $recallEffectiveAt);
                    $restoredAmount = (float) ($recallDetails['days'] ?? 0.0);
                    $restoredDates = is_array($recallDetails['dates'] ?? null)
                        ? $recallDetails['dates']
                        : [];

                    $primaryRestoredAmount = $isMonetization
                        ? $restoredAmount
                        : round(min($restoredAmount, $primaryWithPayAmount), self::LEDGER_DECIMAL_PRECISION);
                    $linkedForcedRestoredAmount = $isMonetization
                        ? 0.0
                        : round(min($restoredAmount, $linkedForcedWithPayAmount), self::LEDGER_DECIMAL_PRECISION);
                    $linkedVacationRestoredAmount = $isMonetization
                        ? 0.0
                        : round(min($restoredAmount, $linkedVacationWithPayAmount), self::LEDGER_DECIMAL_PRECISION);
                    $linkedSickRestoredAmount = $isMonetization
                        ? 0.0
                        : round(min($restoredAmount, $linkedSickWithPayAmount), self::LEDGER_DECIMAL_PRECISION);
                    $recallDate = $recallEffectiveAt?->toDateString() ?? $recallOccurredAt?->toDateString();
                    $recallSortTimestamp = (string) (
                        $recallOccurredAt?->toIso8601String()
                        ?? $recallEffectiveAt?->toIso8601String()
                        ?? $recallDate
                    );
                    $recallParticulars = $this->buildLedgerParticulars(
                        'recall',
                        $typeKey,
                        $restoredAmount,
                        false,
                        $isForcedLeave,
                        is_string($otherTypeCode) ? $otherTypeCode : null,
                        $particularsPrefixOverride
                    );

                    $restoreTypeKey = $typeKey;
                    $restoreBalanceKey = $this->resolveLedgerRunningBalanceKey(
                        $restoreTypeKey,
                        $typeId,
                        $isForcedLeave
                    );
                    if (
                        $restoreTypeKey !== null
                        && is_string($restoreBalanceKey)
                        && $restoreBalanceKey !== ''
                        && array_key_exists($restoreBalanceKey, $runningBalances)
                        && $primaryRestoredAmount > 0.0
                        && $recallDate !== null
                    ) {
                        $transactions[] = [
                            'row_id' => $mergeKey.'-recall',
                            'merge_key' => $mergeKey.'-recall',
                            'type_key' => $restoreTypeKey,
                            'balance_key' => $restoreBalanceKey,
                            'leave_type_code' => $this->resolveLedgerTypeCode(
                                $restoreTypeKey,
                                is_string($otherTypeCode) ? $otherTypeCode : null,
                                $isForcedLeave,
                            ),
                            'transaction_date' => $recallDate,
                            'sort_date' => $recallDate,
                            'sort_timestamp' => $recallSortTimestamp,
                            'particulars' => $recallParticulars,
                            'action_taken' => 'Recalled by HR',
                            'inclusive_dates' => $restoredDates,
                            'category' => 'earned',
                            'amount' => $primaryRestoredAmount,
                            'balance_delta' => $isUsageOnlyOtherType ? 0.0 : $primaryRestoredAmount,
                            'suppress_display' => $isForcedLeave,
                        ];
                    }

                    if ($linkedForcedRestoredAmount > 0.0 && is_int($forcedLeaveTypeId) && $recallDate !== null) {
                        $forcedBalanceKey = $balanceKeyByTypeId[$forcedLeaveTypeId]
                            ?? $this->resolveLedgerRunningBalanceKey('other', $forcedLeaveTypeId, true);

                        if (
                            is_string($forcedBalanceKey)
                            && $forcedBalanceKey !== ''
                            && array_key_exists($forcedBalanceKey, $runningBalances)
                        ) {
                            $transactions[] = [
                                'row_id' => $mergeKey.'-fl-linked-recall',
                                'merge_key' => $mergeKey.'-recall',
                                'type_key' => 'other',
                                'balance_key' => $forcedBalanceKey,
                                'leave_type_code' => $this->resolveLedgerTypeCode('other', 'FL', true),
                                'transaction_date' => $recallDate,
                                'sort_date' => $recallDate,
                                'sort_timestamp' => $recallSortTimestamp,
                                'particulars' => $recallParticulars,
                                'action_taken' => 'Recalled by HR',
                                'inclusive_dates' => $restoredDates,
                                'category' => 'earned',
                                'amount' => $linkedForcedRestoredAmount,
                                'balance_delta' => $linkedForcedRestoredAmount,
                                'suppress_display' => true,
                            ];
                        }
                    }

                    if ($linkedVacationRestoredAmount > 0.0 && $recallDate !== null) {
                        $transactions[] = [
                            'row_id' => $mergeKey.'-vl-linked-recall',
                            'merge_key' => $mergeKey.'-recall',
                            'type_key' => 'vacation',
                            'balance_key' => 'vacation',
                            'leave_type_code' => $this->resolveLedgerTypeCode('vacation'),
                            'transaction_date' => $recallDate,
                            'sort_date' => $recallDate,
                            'sort_timestamp' => $recallSortTimestamp,
                            'particulars' => $recallParticulars,
                            'action_taken' => 'Recalled by HR',
                            'inclusive_dates' => $restoredDates,
                            'category' => 'earned',
                            'amount' => $linkedVacationRestoredAmount,
                            'balance_delta' => $linkedVacationRestoredAmount,
                        ];
                    }

                    if ($linkedSickRestoredAmount > 0.0 && $recallDate !== null) {
                        $transactions[] = [
                            'row_id' => $mergeKey.'-sl-linked-recall',
                            'merge_key' => $mergeKey.'-recall',
                            'type_key' => 'sick',
                            'balance_key' => 'sick',
                            'leave_type_code' => $this->resolveLedgerTypeCode('sick'),
                            'transaction_date' => $recallDate,
                            'sort_date' => $recallDate,
                            'sort_timestamp' => $recallSortTimestamp,
                            'particulars' => $recallParticulars,
                            'action_taken' => 'Recalled by HR',
                            'inclusive_dates' => $restoredDates,
                            'category' => 'earned',
                            'amount' => $linkedSickRestoredAmount,
                            'balance_delta' => $linkedSickRestoredAmount,
                        ];
                    }
                }
            }
        }

        usort($transactions, function (array $left, array $right): int {
            // Sort by actual transaction creation/approval timestamp first so
            // backdated accrual rows still follow true operation sequence.
            $leftTimestamp = (string) ($left['sort_timestamp'] ?? '');
            $rightTimestamp = (string) ($right['sort_timestamp'] ?? '');
            if ($leftTimestamp !== $rightTimestamp) {
                return $leftTimestamp < $rightTimestamp ? 1 : -1;
            }

            $leftDate = (string) ($left['sort_date'] ?? '');
            $rightDate = (string) ($right['sort_date'] ?? '');
            if ($leftDate !== $rightDate) {
                return $leftDate < $rightDate ? 1 : -1;
            }

            $leftId = (string) ($left['row_id'] ?? '');
            $rightId = (string) ($right['row_id'] ?? '');

            return $rightId <=> $leftId;
        });

        $ledgerRows = [];
        $rowIndexByMergeKey = [];
        foreach ($transactions as $transaction) {
            $typeKey = $transaction['type_key'] ?? null;
            $balanceKey = trim((string) ($transaction['balance_key'] ?? ''));
            if (
                ! is_string($typeKey)
                || $balanceKey === ''
                || ! array_key_exists($balanceKey, $runningBalances)
            ) {
                continue;
            }

            $category = (string) ($transaction['category'] ?? '');
            $amount = $this->roundLedgerValue($transaction['amount'] ?? 0);
            if ($amount <= 0 && ! ($transaction['allow_zero_display'] ?? false)) {
                continue;
            }

            $currentBalance = $this->roundLedgerValue($runningBalances[$balanceKey] ?? 0);
            $runningBalances[$balanceKey] = $this->roundLedgerValue(
                $currentBalance - (float) ($transaction['balance_delta'] ?? 0)
            );
            if ((bool) ($transaction['suppress_display'] ?? false)) {
                continue;
            }

            $actionDate = (string) ($transaction['transaction_date'] ?? '');
            $particulars = trim((string) ($transaction['particulars'] ?? ''));
            $actionTaken = trim((string) ($transaction['action_taken'] ?? ''));
            $inclusiveStartDate = trim((string) ($transaction['inclusive_start_date'] ?? ''));
            $inclusiveEndDate = trim((string) ($transaction['inclusive_end_date'] ?? ''));
            $inclusiveDates = $transaction['inclusive_dates'] ?? null;
            if (! is_array($inclusiveDates)) {
                $inclusiveDates = [];
            }
            $period = $this->formatLedgerPeriodLabel($actionDate);
            $leaveTypeCode = strtoupper(trim((string) ($transaction['leave_type_code'] ?? '')));
            $displayTypeKey = $this->resolveLedgerDisplayTypeKey($typeKey, $leaveTypeCode);

            $mergeKey = null;
            if ($category === 'earned') {
                $mergeKey = 'earned|'.mb_strtolower($actionDate.'|'.$particulars.'|'.$actionTaken);
            }

            $explicitMergeKey = trim((string) ($transaction['merge_key'] ?? ''));
            if ($explicitMergeKey !== '') {
                $mergeKey = 'tx|'.mb_strtolower($explicitMergeKey);
            }

            if ($mergeKey !== null && array_key_exists($mergeKey, $rowIndexByMergeKey)) {
                $rowIndex = $rowIndexByMergeKey[$mergeKey];
            } else {
                $rowIndex = count($ledgerRows);
                $ledgerRows[] = [
                    'id' => $transaction['row_id'] ?? null,
                    'accrual_ids' => [],
                    'period' => $period,
                    'particulars' => $particulars,
                    'leave_type_code' => $leaveTypeCode !== '' ? $leaveTypeCode : null,
                    'balance_key' => $balanceKey,
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

            $txRowId = (string) ($transaction['row_id'] ?? '');
            if (str_starts_with($txRowId, 'accrual-')) {
                $accrualId = (int) substr($txRowId, 8);
                if ($accrualId > 0 && ! in_array($accrualId, $ledgerRows[$rowIndex]['accrual_ids'], true)) {
                    $ledgerRows[$rowIndex]['accrual_ids'][] = $accrualId;
                }
            }

            if (
                $leaveTypeCode !== ''
                && trim((string) ($ledgerRows[$rowIndex]['leave_type_code'] ?? '')) === ''
            ) {
                $ledgerRows[$rowIndex]['leave_type_code'] = $leaveTypeCode;
            }

            if ($displayTypeKey === 'vacation') {
                if (! array_key_exists('vacation_balance', $ledgerRows[$rowIndex])) {
                    $ledgerRows[$rowIndex]['vacation_balance'] = $currentBalance;
                }
                if ($category === 'earned') {
                    $ledgerRows[$rowIndex]['vacation_earned'] = $this->roundLedgerValue(
                        (float) ($ledgerRows[$rowIndex]['vacation_earned'] ?? 0) + $amount
                    );
                } elseif ($category === 'deduction_with_pay') {
                    $ledgerRows[$rowIndex]['vacation_abs_und_wp'] = $this->roundLedgerValue(
                        (float) ($ledgerRows[$rowIndex]['vacation_abs_und_wp'] ?? 0) + $amount
                    );
                } elseif ($category === 'deduction_without_pay') {
                    $ledgerRows[$rowIndex]['vacation_abs_und_wop'] = $this->roundLedgerValue(
                        (float) ($ledgerRows[$rowIndex]['vacation_abs_und_wop'] ?? 0) + $amount
                    );
                }
            } elseif ($displayTypeKey === 'sick') {
                if (! array_key_exists('sick_balance', $ledgerRows[$rowIndex])) {
                    $ledgerRows[$rowIndex]['sick_balance'] = $currentBalance;
                }
                if ($category === 'earned') {
                    $ledgerRows[$rowIndex]['sick_earned'] = $this->roundLedgerValue(
                        (float) ($ledgerRows[$rowIndex]['sick_earned'] ?? 0) + $amount
                    );
                } elseif ($category === 'deduction_with_pay') {
                    $ledgerRows[$rowIndex]['sick_abs_und'] = $this->roundLedgerValue(
                        (float) ($ledgerRows[$rowIndex]['sick_abs_und'] ?? 0) + $amount
                    );
                } elseif ($category === 'deduction_without_pay') {
                    $ledgerRows[$rowIndex]['sick_abs_und_wop'] = $this->roundLedgerValue(
                        (float) ($ledgerRows[$rowIndex]['sick_abs_und_wop'] ?? 0) + $amount
                    );
                }
            } elseif ($displayTypeKey === 'other') {
                if (! array_key_exists('other_balance', $ledgerRows[$rowIndex])) {
                    $ledgerRows[$rowIndex]['other_balance'] = $currentBalance;
                }
                if ($category === 'earned') {
                    $ledgerRows[$rowIndex]['other_earned'] = $this->roundLedgerValue(
                        (float) ($ledgerRows[$rowIndex]['other_earned'] ?? 0) + $amount
                    );
                } elseif ($category === 'deduction_with_pay') {
                    $ledgerRows[$rowIndex]['other_abs_und'] = $this->roundLedgerValue(
                        (float) ($ledgerRows[$rowIndex]['other_abs_und'] ?? 0) + $amount
                    );
                } elseif ($category === 'deduction_without_pay') {
                    $ledgerRows[$rowIndex]['other_abs_und_wop'] = $this->roundLedgerValue(
                        (float) ($ledgerRows[$rowIndex]['other_abs_und_wop'] ?? 0) + $amount
                    );
                }
            }
        }

        $firstDayOfService = HrisEmployee::firstDayOfServiceByControlNo(
            (string) ($employee->control_no ?? $controlNo)
        ) ?? $this->normalizeLedgerDateString($employee->from_date ?? null);
        $ledgerBalanceBadges = $this->buildLedgerBalanceBadges(
            $currentLedgerBalances,
            $trackedTypeIdsByKey,
            $balanceKeyByTypeId,
            $otherTypeIds,
            $otherTypeCodeById
        );

        return response()->json([
            'employee' => [
                'control_no' => $employee->control_no,
                'firstname' => $employee->firstname,
                'surname' => $employee->surname,
                'middlename' => $employee->middlename,
                'first_day_of_service' => $firstDayOfService,
                'firstDayOfService' => $firstDayOfService,
                'office' => $employee->office,
                'designation' => $employee->designation,
                'status' => $employee->status,
            ],
            'ledger' => $ledgerRows,
            'leave_balance_ledger' => $ledgerRows,
            'leave_credits_ledger' => $ledgerRows,
            'leaveCreditsLedger' => $ledgerRows,
            'balances' => $ledgerBalanceBadges,
            'leave_balances' => $ledgerBalanceBadges,
            'ledger_balances' => $ledgerBalanceBadges,
            'leaveBalanceBadges' => $ledgerBalanceBadges,
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
            LeaveApplication::STATUS_CANCELLED => 'Cancelled',
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
        if (! $account instanceof DepartmentAdmin) {
            return response()->json([
                'message' => 'Only department admin accounts can manage employees.',
            ], 403);
        }

        $account->loadMissing('department');
        if (! $account->department_id || ! $account->department?->name) {
            return response()->json([
                'message' => 'Department admin account is not assigned to a department.',
            ], 403);
        }

        return $account;
    }

    /**
     * Validate incoming department head payload.
     */
    private function validateDepartmentHeadPayload(Request $request): array
    {
        if ($request->has('control_no')) {
            $request->merge([
                'control_no' => trim((string) $request->input('control_no')),
            ]);
        }

        return $request->validate([
            'control_no' => ['required', 'string', 'max:50', 'regex:/^\d+$/'],
        ]);
    }

    /**
     * Normalize and map HRIS employee data for tblDepartmentHeads writes.
     */
    private function normalizeDepartmentHeadPayload(object $employee, string $office): array
    {
        return [
            'control_no' => trim((string) ($employee->control_no ?? '')),
            'surname' => trim((string) ($employee->surname ?? '')),
            'firstname' => trim((string) ($employee->firstname ?? '')),
            'middlename' => $this->trimNullable($employee->middlename ?? null),
            'office' => $office,
            'status' => strtoupper(trim((string) ($employee->status ?? ''))),
            'designation' => $this->trimNullable($employee->designation ?? null),
            'rate_mon' => $employee->rate_mon !== null
                ? round((float) $employee->rate_mon, 2)
                : null,
        ];
    }

    /**
     * Shared serializer for employee payloads used by API responses.
     */
    private function serializeEmployee(object $employee): array
    {
        return [
            'control_no' => trim((string) ($employee->control_no ?? '')),
            'firstname' => trim((string) ($employee->firstname ?? '')),
            'surname' => trim((string) ($employee->surname ?? '')),
            'middlename' => $this->trimOrBlank($employee->middlename ?? null),
            'full_name' => $this->buildEmployeeFullName($employee),
            'designation' => $this->trimOrBlank($employee->designation ?? null),
            'office' => trim((string) ($employee->office ?? '')),
            'officeAcronym' => $this->trimOrBlank($employee->officeAcronym ?? null),
            'office_acronym' => $this->trimOrBlank($employee->officeAcronym ?? $employee->office_acronym ?? null),
            'hris_office' => $this->trimOrBlank($employee->hris_office ?? null),
            'hrisOfficeAcronym' => $this->trimOrBlank($employee->hrisOfficeAcronym ?? null),
            'hris_office_acronym' => $this->trimOrBlank($employee->hrisOfficeAcronym ?? $employee->hris_office_acronym ?? null),
            'assigned_department_name' => $this->trimOrBlank($employee->assigned_department_name ?? null),
            'assignedDepartmentAcronym' => $this->trimOrBlank($employee->assignedDepartmentAcronym ?? $employee->assigned_department_acronym ?? null),
            'assigned_department_acronym' => $this->trimOrBlank($employee->assignedDepartmentAcronym ?? $employee->assigned_department_acronym ?? null),
            'assigned_department_id' => $employee->assigned_department_id !== null
                ? (int) $employee->assigned_department_id
                : null,
            'is_department_reassigned' => (bool) ($employee->is_department_reassigned ?? false),
            'status' => trim((string) ($employee->status ?? '')),
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
            'middlename' => $this->trimOrBlank($departmentHead->middlename),
            'office' => $this->trimOrBlank($departmentHead->office),
            'status' => $this->trimOrBlank($departmentHead->status),
            'designation' => $this->trimOrBlank($departmentHead->designation),
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
        ?int $departmentId,
        ?string $searchTerm,
        ?bool $activeOnly,
        int $perPage,
        int $page,
        array $departmentHeadLookup = []
    ): LengthAwarePaginator {
        if ($departmentId === null || $departmentId <= 0) {
            return new LengthAwarePaginator(
                collect(),
                0,
                $perPage,
                $page,
                [
                    'path' => request()->url(),
                    'pageName' => 'page',
                ]
            );
        }

        $rows = $this->fetchDepartmentPulledEmployeeRows($departmentId, $searchTerm, $activeOnly)
            ->values()
            ->map(function (array $row) use ($departmentHeadLookup): array {
                $controlNo = trim((string) ($row['control_no'] ?? ''));

                return array_merge($row, [
                    'has_account' => false,
                    'is_department_head_record' => $controlNo !== '' && $this->hasControlNoInLookup($controlNo, $departmentHeadLookup),
                ]);
            });

        return new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => request()->url(),
                'pageName' => 'page',
            ]
        );
    }

    private function buildDepartmentAdminStatusCounts(
        ?int $departmentId,
        ?string $searchTerm,
        ?bool $activeOnly
    ): array {
        if ($departmentId === null || $departmentId <= 0) {
            return [];
        }

        $rows = $this->fetchDepartmentPulledEmployeeRows($departmentId, $searchTerm, $activeOnly)
            ->values();

        return $this->buildStatusCountsFromRows($rows);
    }

    private function buildHrEmployeeListing(
        Request $request,
        ?string $departmentName,
        ?int $departmentId,
        ?string $searchTerm,
        ?bool $activeOnly,
        int $perPage,
        int $page,
        array $departmentHeadLookup = []
    ): array {
        $employeeRows = $this->fetchHrisEmployeeRows($departmentName, $searchTerm, false, $activeOnly)
            ->map(function (array $employee) use ($departmentHeadLookup): array {
                $controlNo = trim((string) ($employee['control_no'] ?? ''));

                return array_merge($employee, [
                    'has_account' => false,
                    'is_department_head_record' => $controlNo !== '' && $this->hasControlNoInLookup($controlNo, $departmentHeadLookup),
                ]);
            });

        $existingEmployeeControlNoLookup = $this->buildControlNoLookup(
            $employeeRows
                ->map(fn (array $employee): string => trim((string) ($employee['control_no'] ?? '')))
                ->filter()
                ->values()
                ->all()
        );

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
                $searchTerm,
                $existingEmployeeControlNoLookup,
                $activeOnly
            ): bool {
                if (! $this->matchesDepartmentHeadEmployeeFilters($departmentHead, $searchTerm, true, $activeOnly)) {
                    return false;
                }

                $controlNo = trim((string) ($departmentHead->control_no ?? ''));
                if ($controlNo !== '' && $this->hasControlNoInLookup($controlNo, $existingEmployeeControlNoLookup)) {
                    return false;
                }

                return true;
            })
            ->map(fn (DepartmentHead $departmentHead): array => $this->serializeDepartmentHeadAsEmployee($departmentHead));

        $combinedRows = $employeeRows
            ->concat($departmentHeadRows)
            ->pipe(fn (Collection $rows): Collection => $this->sortEmployeeRows($rows))
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

    private function fetchHrisEmployeeRows(
        ?string $departmentName,
        ?string $searchTerm,
        bool $excludeContractual,
        ?bool $activeOnly = null,
        ?int $limit = null
    ): Collection {
        $normalizedDepartmentName = trim((string) ($departmentName ?? ''));
        $normalizedSearchTerm = trim((string) ($searchTerm ?? ''));

        $employees = $normalizedDepartmentName !== ''
            ? HrisEmployee::allByOffice($normalizedDepartmentName, $activeOnly)
            : HrisEmployee::allCached($activeOnly);

        $matchesEmployeeFilters = function (object $employee) use (
            $normalizedDepartmentName,
            $excludeContractual,
            $activeOnly,
            $normalizedSearchTerm
        ): bool {
            $employeeOffice = trim((string) ($employee->office ?? ''));
            $employeeStatus = strtoupper(trim((string) ($employee->status ?? '')));
            $isActive = filter_var($employee->is_active ?? false, FILTER_VALIDATE_BOOLEAN);

            if (
                $normalizedDepartmentName !== ''
                && strcasecmp($employeeOffice, $normalizedDepartmentName) !== 0
            ) {
                return false;
            }

            if ($excludeContractual && $employeeStatus === 'CONTRACTUAL') {
                return false;
            }

            if ($activeOnly === false && in_array($employeeStatus, ['HONORARIUM', 'CONTRACTUAL'], true)) {
                return false;
            }

            if (
                $activeOnly === null
                && ! $isActive
                && in_array($employeeStatus, ['HONORARIUM', 'CONTRACTUAL'], true)
            ) {
                return false;
            }

            return $this->matchesHrisEmployeeSearch($employee, $normalizedSearchTerm);
        };

        if ($limit !== null && $limit > 0) {
            $limitedRows = [];

            foreach ($employees as $employee) {
                if (! is_object($employee)) {
                    continue;
                }

                if (! $matchesEmployeeFilters($employee)) {
                    continue;
                }

                $limitedRows[] = $this->serializeEmployee($employee);
                if (count($limitedRows) >= $limit) {
                    break;
                }
            }

            return collect($limitedRows)->values();
        }

        $rows = $employees
            ->filter($matchesEmployeeFilters)
            ->map(fn (object $employee): array => $this->serializeEmployee($employee))
            ->pipe(fn (Collection $employeeRows): Collection => $this->sortEmployeeRows($employeeRows))
            ->values();

        if ($limit !== null && $limit > 0) {
            return $rows->take($limit)->values();
        }

        return $rows;
    }

    private function matchesHrisEmployeeSearch(object $employee, string $searchTerm): bool
    {
        if ($searchTerm === '') {
            return true;
        }

        $firstname = trim((string) ($employee->firstname ?? ''));
        $surname = trim((string) ($employee->surname ?? ''));
        $middlename = trim((string) ($employee->middlename ?? ''));
        $controlNo = trim((string) ($employee->control_no ?? ''));
        $status = trim((string) ($employee->status ?? ''));
        $office = trim((string) ($employee->office ?? ''));
        $designation = trim((string) ($employee->designation ?? ''));

        $baseFields = array_values(array_filter([
            $controlNo,
            $status,
            $office,
            $designation,
        ], static fn (string $value): bool => $value !== ''));

        $nameVariants = array_values(array_filter([
            trim(implode(' ', array_filter([$firstname, $middlename, $surname]))),
            trim(implode(' ', array_filter([$surname, $firstname, $middlename]))),
            trim($surname.', '.implode(' ', array_filter([$firstname, $middlename]))),
            trim(implode(' ', array_filter([$firstname, $surname]))),
            trim(implode(' ', array_filter([$surname, $firstname]))),
        ], static fn (string $value): bool => $value !== ''));

        $needle = mb_strtolower(trim($searchTerm));
        $rawHaystack = implode(' ', array_merge($nameVariants, $baseFields));
        if ($rawHaystack !== '' && mb_stripos($rawHaystack, $needle) !== false) {
            return true;
        }

        $normalizedNeedle = $this->normalizeEmployeeSearchText($searchTerm);
        if ($normalizedNeedle === '') {
            return true;
        }

        foreach (array_merge($nameVariants, $baseFields) as $fieldValue) {
            $normalizedFieldValue = $this->normalizeEmployeeSearchText($fieldValue);
            if ($normalizedFieldValue !== '' && str_contains($normalizedFieldValue, $normalizedNeedle)) {
                return true;
            }
        }

        $searchTokens = $this->tokenizeEmployeeSearchText($normalizedNeedle);
        if ($searchTokens === []) {
            return false;
        }

        $normalizedNameBag = $this->normalizeEmployeeSearchText(
            implode(' ', array_filter([$firstname, $middlename, $surname])),
        );
        if ($this->containsAllSearchTokens($normalizedNameBag, $searchTokens)) {
            return true;
        }

        $normalizedAllFieldsBag = $this->normalizeEmployeeSearchText(
            implode(' ', array_merge([$normalizedNameBag], $baseFields)),
        );

        return $this->containsAllSearchTokens($normalizedAllFieldsBag, $searchTokens);
    }

    private function normalizeEmployeeSearchText(string $value): string
    {
        $normalized = mb_strtolower(trim($value));
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeEmployeeSearchText(string $normalizedSearchText): array
    {
        if ($normalizedSearchText === '') {
            return [];
        }

        return array_values(array_filter(
            explode(' ', $normalizedSearchText),
            static fn (string $token): bool => $token !== '',
        ));
    }

    /**
     * @param  array<int, string>  $tokens
     */
    private function containsAllSearchTokens(string $normalizedText, array $tokens): bool
    {
        if ($normalizedText === '' || $tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! str_contains($normalizedText, $token)) {
                return false;
            }
        }

        return true;
    }

    private function sortEmployeeRows(Collection $rows): Collection
    {
        return $rows->sort(function (array $left, array $right): int {
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
        });
    }

    private function loadDepartmentAdminEmployeeControlNoLookup(): array
    {
        $controlNos = DepartmentAdmin::query()
            ->whereNotNull('employee_control_no')
            ->pluck('employee_control_no')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        return $this->buildControlNoLookup($controlNos);
    }

    private function loadPulledEmployeeControlNoLookup(): array
    {
        $controlNos = EmployeeDepartmentAssignment::query()
            ->whereHas('department', fn ($query) => $query->where('is_inactive', false))
            ->pluck('employee_control_no')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        return $this->buildControlNoLookup($controlNos);
    }

    private function fetchDepartmentPulledEmployeeRows(
        int $departmentId,
        ?string $searchTerm,
        ?bool $activeOnly
    ): Collection {
        $controlNos = EmployeeDepartmentAssignment::query()
            ->where('department_id', $departmentId)
            ->pluck('employee_control_no')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        if ($controlNos === []) {
            return collect();
        }

        return $this->fetchHrisEmployeeRowsByControlNos($controlNos, $searchTerm, false, $activeOnly);
    }

    private function fetchHrisEmployeeRowsByControlNos(
        array $controlNos,
        ?string $searchTerm,
        bool $excludeContractual,
        ?bool $activeOnly = null
    ): Collection {
        $lookupControlNos = collect($controlNos)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        if ($lookupControlNos === []) {
            return collect();
        }

        $normalizedSearchTerm = trim((string) ($searchTerm ?? ''));
        $employeesByControlNo = HrisEmployee::directoryByControlNos($lookupControlNos, $activeOnly);
        $rowsByControlNo = [];

        foreach ($lookupControlNos as $rawControlNo) {
            $employee = $this->resolveHrisDirectoryEmployee($employeesByControlNo, $rawControlNo);
            if (! $employee) {
                continue;
            }

            $employeeStatus = strtoupper(trim((string) ($employee->status ?? '')));
            $isActive = filter_var($employee->is_active ?? false, FILTER_VALIDATE_BOOLEAN);

            if ($excludeContractual && $employeeStatus === 'CONTRACTUAL') {
                continue;
            }

            if ($activeOnly === false && in_array($employeeStatus, ['HONORARIUM', 'CONTRACTUAL'], true)) {
                continue;
            }

            if (
                $activeOnly === null
                && ! $isActive
                && in_array($employeeStatus, ['HONORARIUM', 'CONTRACTUAL'], true)
            ) {
                continue;
            }

            if (! $this->matchesHrisEmployeeSearch($employee, $normalizedSearchTerm)) {
                continue;
            }

            $serializedRow = $this->serializeEmployee($employee);
            $serializedControlNo = trim((string) ($serializedRow['control_no'] ?? ''));
            if ($serializedControlNo === '') {
                continue;
            }

            $lookupKey = $this->normalizeLedgerControlNo($serializedControlNo) ?? $serializedControlNo;
            if (isset($rowsByControlNo[$lookupKey])) {
                continue;
            }

            $rowsByControlNo[$lookupKey] = $serializedRow;
        }

        return $this->sortEmployeeRows(collect(array_values($rowsByControlNo)))->values();
    }

    private function resolveHrisDirectoryEmployee(array $employeesByControlNo, string $controlNo): ?object
    {
        $rawControlNo = trim($controlNo);
        if ($rawControlNo === '') {
            return null;
        }

        $normalizedControlNo = $this->normalizeLedgerControlNo($rawControlNo);
        $lookupKeys = array_values(array_unique(array_filter([
            $rawControlNo,
            $normalizedControlNo,
        ], static fn (mixed $value): bool => $value !== null && trim((string) $value) !== '')));

        foreach ($lookupKeys as $lookupKey) {
            $employee = $employeesByControlNo[$lookupKey] ?? null;
            if (is_object($employee)) {
                return $employee;
            }
        }

        return null;
    }

    private function findPulledEmployeeAssignment(string $controlNo, ?int $departmentId = null): ?EmployeeDepartmentAssignment
    {
        $rawControlNo = trim($controlNo);
        if ($rawControlNo === '') {
            return null;
        }

        $normalizedControlNo = $this->normalizeLedgerControlNo($rawControlNo);
        if ($normalizedControlNo === null) {
            return null;
        }

        $query = EmployeeDepartmentAssignment::query();
        if ($departmentId !== null) {
            $query->where('department_id', $departmentId);
        }

        return $query
            ->get()
            ->first(function (EmployeeDepartmentAssignment $assignment) use ($rawControlNo, $normalizedControlNo): bool {
                $assignmentControlNo = trim((string) $assignment->employee_control_no);
                if ($assignmentControlNo === '') {
                    return false;
                }

                if ($assignmentControlNo === $rawControlNo) {
                    return true;
                }

                $normalizedAssignmentControlNo = $this->normalizeLedgerControlNo($assignmentControlNo);

                return $normalizedAssignmentControlNo !== null
                    && $normalizedAssignmentControlNo === $normalizedControlNo;
            });
    }

    private function matchesDepartmentHeadControlNo(int $departmentId, string $controlNo): bool
    {
        if ($departmentId <= 0) {
            return false;
        }

        $targetControlNo = trim($controlNo);
        if ($targetControlNo === '') {
            return false;
        }

        $departmentHead = DepartmentHead::query()
            ->where('department_id', $departmentId)
            ->first();

        if (! $departmentHead) {
            return false;
        }

        $storedControlNo = trim((string) $departmentHead->control_no);
        if ($storedControlNo === '') {
            return false;
        }

        if ($storedControlNo === $targetControlNo) {
            return true;
        }

        $normalizedTargetControlNo = $this->normalizeLedgerControlNo($targetControlNo);
        $normalizedStoredControlNo = $this->normalizeLedgerControlNo($storedControlNo);

        return $normalizedTargetControlNo !== null
            && $normalizedStoredControlNo !== null
            && $normalizedTargetControlNo === $normalizedStoredControlNo;
    }

    private function assignEmployeeToDepartment(DepartmentAdmin $admin, string $controlNo): JsonResponse
    {
        $employee = HrisEmployee::findByControlNo($controlNo, true);
        if (! $employee) {
            return response()->json([
                'message' => 'Employee not found in HRIS active records.',
            ], 422);
        }

        $storedControlNo = trim((string) ($employee->control_no ?? ''));
        $assignmentIdentityFields = $this->buildAssignmentIdentityFields($employee);
        $existingAssignment = EmployeeDepartmentAssignment::query()
            ->with('department:id,name,is_inactive')
            ->where('employee_control_no', $storedControlNo)
            ->first();

        if ($existingAssignment && ! ($existingAssignment->department?->is_inactive ?? false)) {
            if ((int) $existingAssignment->department_id === (int) $admin->department_id) {
                $existingAssignment->fill($assignmentIdentityFields);
                $existingAssignment->save();

                return response()->json([
                    'message' => 'Employee is already pulled into your office.',
                    'employee' => $this->serializeEmployee($employee),
                ]);
            }

            return response()->json([
                'message' => 'Employee is already pulled by another office in LMS.',
                'assigned_department_name' => trim((string) ($existingAssignment->department?->name ?? '')) ?: null,
            ], 422);
        }

        $updatedEmployee = HrisEmployee::findByControlNo($storedControlNo, true) ?? $employee;
        if ($existingAssignment) {
            $existingAssignment->fill([
                'department_id' => $admin->department_id,
                'assigned_by_department_admin_id' => $admin->id,
                'assigned_at' => now(),
                ...$assignmentIdentityFields,
            ]);
            $existingAssignment->save();

            return response()->json([
                'message' => 'Employee pulled to your office successfully.',
                'employee' => $this->serializeEmployee($updatedEmployee),
            ]);
        }

        EmployeeDepartmentAssignment::query()->create([
            'employee_control_no' => $storedControlNo,
            'department_id' => $admin->department_id,
            'assigned_by_department_admin_id' => $admin->id,
            'assigned_at' => now(),
            ...$assignmentIdentityFields,
        ]);

        return response()->json([
            'message' => 'Employee pulled to your office successfully.',
            'employee' => $this->serializeEmployee($updatedEmployee),
        ], 201);
    }

    private function buildAssignmentIdentityFields(object $employee): array
    {
        return [
            'surname' => $this->trimNullable($employee->surname ?? null),
            'firstname' => $this->trimNullable($employee->firstname ?? null),
            'middlename' => $this->trimNullable($employee->middlename ?? null),
            'department_acronym' => $this->trimNullable(
                $employee->officeAcronym
                    ?? $employee->hrisOfficeAcronym
                    ?? null
            ),
        ];
    }

    private function buildControlNoLookup(array $controlNos): array
    {
        $lookup = [];
        foreach ($controlNos as $controlNo) {
            $rawControlNo = trim((string) $controlNo);
            if ($rawControlNo === '') {
                continue;
            }

            $lookup[$rawControlNo] = true;
            $normalizedControlNo = $this->normalizeLedgerControlNo($rawControlNo);
            if ($normalizedControlNo !== null) {
                $lookup[$normalizedControlNo] = true;
            }
        }

        return $lookup;
    }

    private function attachManualLeaveCreditsUsageFlags(LengthAwarePaginator $employees): void
    {
        $rows = $employees->getCollection();
        if ($rows->isEmpty()) {
            return;
        }

        $rowControlNos = $rows
            ->map(fn (mixed $row): string => trim((string) (is_array($row) ? ($row['control_no'] ?? '') : '')))
            ->filter(fn (string $controlNo): bool => $controlNo !== '')
            ->values()
            ->all();

        $manualCreditUsageLookup = $this->loadManualLeaveCreditsUsageLookup($rowControlNos);
        $employees->setCollection(
            $rows->map(function (mixed $row) use ($manualCreditUsageLookup): mixed {
                if (! is_array($row)) {
                    return $row;
                }

                $controlNo = trim((string) ($row['control_no'] ?? ''));
                $row['has_manual_leave_credits'] = $controlNo !== ''
                    && $this->hasControlNoInLookup($controlNo, $manualCreditUsageLookup);

                return $row;
            })
        );
    }

    private function loadManualLeaveCreditsUsageLookup(array $controlNos): array
    {
        $controlNoLookup = $this->buildControlNoLookup($controlNos);
        if ($controlNoLookup === []) {
            return [];
        }

        $candidateControlNos = array_keys($controlNoLookup);
        $usedControlNos = LeaveBalanceAccrualHistory::query()
            ->join('tblLeaveBalances as lb', 'lb.id', '=', 'tblLeaveBalanceCreditHistories.leave_balance_id')
            ->whereIn('lb.employee_control_no', $candidateControlNos)
            ->where(function ($query): void {
                $query->whereRaw(
                    "UPPER(LTRIM(RTRIM(COALESCE(tblLeaveBalanceCreditHistories.source, '')))) = ?",
                    ['HR_ADD']
                )->orWhereRaw(
                    "UPPER(LTRIM(RTRIM(COALESCE(tblLeaveBalanceCreditHistories.source, '')))) LIKE ?",
                    ['HR_ADD:%']
                );
            })
            ->pluck('lb.employee_control_no')
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->unique()
            ->values()
            ->all();

        return $this->buildControlNoLookup($usedControlNos);
    }

    private function hasControlNoInLookup(string $controlNo, array $lookup): bool
    {
        $rawControlNo = trim($controlNo);
        if ($rawControlNo === '') {
            return false;
        }

        if (isset($lookup[$rawControlNo])) {
            return true;
        }

        $normalizedControlNo = $this->normalizeLedgerControlNo($rawControlNo);

        return $normalizedControlNo !== null && isset($lookup[$normalizedControlNo]);
    }

    private function isContractualStatus(?string $status): bool
    {
        return strtoupper(trim((string) ($status ?? ''))) === 'CONTRACTUAL';
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
                if ($controlNo === '') {
                    return [];
                }

                $keys = [$controlNo => $departmentHead];
                $normalizedControlNo = $this->normalizeLedgerControlNo($controlNo);
                if ($normalizedControlNo !== null) {
                    $keys[$normalizedControlNo] = $departmentHead;
                }

                return $keys;
            })
            ->all();
    }

    private function shouldIncludeDepartmentHeadInEmployeeSummary(
        DepartmentHead $departmentHead,
        ?string $departmentName,
        ?string $searchTerm,
        bool $isHrAccount,
        ?bool $activeOnly = null
    ): bool {
        if (! $this->matchesDepartmentHeadEmployeeFilters($departmentHead, $searchTerm, $isHrAccount, $activeOnly)) {
            return false;
        }

        $controlNo = trim((string) ($departmentHead->control_no ?? ''));
        if ($controlNo === '') {
            return true;
        }

        $employee = HrisEmployee::findByControlNo($controlNo);
        if (! $employee) {
            return true;
        }

        if ($departmentName !== null && strcasecmp(trim((string) ($employee->office ?? '')), trim($departmentName)) !== 0) {
            return true;
        }

        if ($isHrAccount && $this->isContractualStatus((string) ($employee->status ?? null))) {
            return true;
        }

        $term = strtolower(trim((string) ($searchTerm ?? '')));
        if ($term !== '') {
            $firstname = strtolower(trim((string) ($employee->firstname ?? '')));
            $surname = strtolower(trim((string) ($employee->surname ?? '')));

            if (! str_contains($firstname, $term) && ! str_contains($surname, $term)) {
                return true;
            }
        }

        return false;
    }

    private function matchesDepartmentHeadEmployeeFilters(
        DepartmentHead $departmentHead,
        ?string $searchTerm,
        bool $isHrAccount,
        ?bool $activeOnly = null
    ): bool {
        if ($activeOnly !== null) {
            $controlNo = trim((string) ($departmentHead->control_no ?? ''));
            if ($controlNo === '') {
                return false;
            }

            if (! HrisEmployee::existsByControlNo($controlNo, $activeOnly)) {
                return false;
            }
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

    private function resolveCityAdministratorEmployee(): ?object
    {
        $employee = HrisEmployee::query(true)
            ->whereRaw('LOWER(LTRIM(RTRIM(vp.Designation))) LIKE ?', ['%city administrator%'])
            ->orderByRaw('vp.ToDate DESC')
            ->orderByRaw('vp.FromDate DESC')
            ->orderByRaw('LTRIM(RTRIM(xp.Surname))')
            ->orderByRaw('LTRIM(RTRIM(xp.Firstname))')
            ->first();

        if ($employee) {
            return $employee;
        }

        return HrisEmployee::query(null)
            ->whereRaw('LOWER(LTRIM(RTRIM(vp.Designation))) LIKE ?', ['%city administrator%'])
            ->orderByRaw('CASE WHEN vp.FromDate IS NOT NULL AND vp.ToDate IS NOT NULL AND GETDATE() BETWEEN vp.FromDate AND vp.ToDate THEN 0 ELSE 1 END')
            ->orderByRaw('vp.ToDate DESC')
            ->orderByRaw('vp.FromDate DESC')
            ->orderByRaw('LTRIM(RTRIM(xp.Surname))')
            ->orderByRaw('LTRIM(RTRIM(xp.Firstname))')
            ->first();
    }

    private function resolveCityMayorEmployee(): ?object
    {
        $employee = HrisEmployee::query(true)
            ->whereRaw('LOWER(LTRIM(RTRIM(vp.Designation))) LIKE ?', ['%city mayor%'])
            ->whereRaw('LOWER(LTRIM(RTRIM(vp.Designation))) NOT LIKE ?', ['%vice%'])
            ->whereRaw('LOWER(LTRIM(RTRIM(vp.Status))) = ?', ['elective'])
            ->orderByRaw('vp.ToDate DESC')
            ->orderByRaw('vp.FromDate DESC')
            ->orderByRaw('LTRIM(RTRIM(xp.Surname))')
            ->orderByRaw('LTRIM(RTRIM(xp.Firstname))')
            ->first();

        if ($employee) {
            return $employee;
        }

        return HrisEmployee::query(null)
            ->whereRaw('LOWER(LTRIM(RTRIM(vp.Designation))) LIKE ?', ['%city mayor%'])
            ->whereRaw('LOWER(LTRIM(RTRIM(vp.Designation))) NOT LIKE ?', ['%vice%'])
            ->whereRaw('LOWER(LTRIM(RTRIM(vp.Status))) = ?', ['elective'])
            ->orderByRaw('CASE WHEN vp.FromDate IS NOT NULL AND vp.ToDate IS NOT NULL AND GETDATE() BETWEEN vp.FromDate AND vp.ToDate THEN 0 ELSE 1 END')
            ->orderByRaw('vp.ToDate DESC')
            ->orderByRaw('vp.FromDate DESC')
            ->orderByRaw('LTRIM(RTRIM(xp.Surname))')
            ->orderByRaw('LTRIM(RTRIM(xp.Firstname))')
            ->first();
    }

    private function resolveCityViceMayorEmployee(): ?object
    {
        $employee = HrisEmployee::query(true)
            ->whereRaw('LOWER(LTRIM(RTRIM(vp.Designation))) LIKE ?', ['%city vice mayor%'])
            ->whereRaw('LOWER(LTRIM(RTRIM(vp.Status))) = ?', ['elective'])
            ->orderByRaw('vp.ToDate DESC')
            ->orderByRaw('vp.FromDate DESC')
            ->orderByRaw('LTRIM(RTRIM(xp.Surname))')
            ->orderByRaw('LTRIM(RTRIM(xp.Firstname))')
            ->first();

        if ($employee) {
            return $employee;
        }

        return HrisEmployee::query(null)
            ->whereRaw('LOWER(LTRIM(RTRIM(vp.Designation))) LIKE ?', ['%city vice mayor%'])
            ->whereRaw('LOWER(LTRIM(RTRIM(vp.Status))) = ?', ['elective'])
            ->orderByRaw('CASE WHEN vp.FromDate IS NOT NULL AND vp.ToDate IS NOT NULL AND GETDATE() BETWEEN vp.FromDate AND vp.ToDate THEN 0 ELSE 1 END')
            ->orderByRaw('vp.ToDate DESC')
            ->orderByRaw('vp.FromDate DESC')
            ->orderByRaw('LTRIM(RTRIM(xp.Surname))')
            ->orderByRaw('LTRIM(RTRIM(xp.Firstname))')
            ->first();
    }

    private function serializeCityAdministratorEmployee(?object $employee): ?array
    {
        if (! $employee) {
            return null;
        }

        $designation = $this->trimOrBlank($employee->designation ?? null);

        return [
            'control_no' => trim((string) ($employee->control_no ?? '')),
            'firstname' => $this->trimOrBlank($employee->firstname ?? null),
            'surname' => $this->trimOrBlank($employee->surname ?? null),
            'middlename' => $this->trimOrBlank($employee->middlename ?? null),
            'full_name' => $this->buildEmployeeFullName($employee),
            'designation' => $designation !== '' ? $designation : 'City Administrator',
            'position' => $designation !== '' ? $designation : 'City Administrator',
            'office' => $this->trimOrBlank($employee->office ?? null),
        ];
    }

    private function serializeCityMayorEmployee(?object $employee): ?array
    {
        if (! $employee) {
            return null;
        }

        $designation = $this->trimOrBlank($employee->designation ?? null);

        return [
            'control_no' => trim((string) ($employee->control_no ?? '')),
            'firstname' => $this->trimOrBlank($employee->firstname ?? null),
            'surname' => $this->trimOrBlank($employee->surname ?? null),
            'middlename' => $this->trimOrBlank($employee->middlename ?? null),
            'full_name' => $this->buildEmployeeFullName($employee),
            'designation' => $designation !== '' ? $designation : 'City Mayor',
            'position' => $designation !== '' ? $designation : 'City Mayor',
            'office' => $this->trimOrBlank($employee->office ?? null),
        ];
    }

    private function serializeCityViceMayorEmployee(?object $employee): ?array
    {
        if (! $employee) {
            return null;
        }

        $designation = $this->trimOrBlank($employee->designation ?? null);

        return [
            'control_no' => trim((string) ($employee->control_no ?? '')),
            'firstname' => $this->trimOrBlank($employee->firstname ?? null),
            'surname' => $this->trimOrBlank($employee->surname ?? null),
            'middlename' => $this->trimOrBlank($employee->middlename ?? null),
            'full_name' => $this->buildEmployeeFullName($employee),
            'designation' => $designation !== '' ? $designation : 'City Vice Mayor',
            'position' => $designation !== '' ? $designation : 'City Vice Mayor',
            'office' => $this->trimOrBlank($employee->office ?? null),
        ];
    }

    private function buildDepartmentHeadFullName(array $attributes): string
    {
        return trim(implode(' ', array_filter([
            $attributes['firstname'] ?? null,
            $attributes['middlename'] ?? null,
            $attributes['surname'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && trim((string) $value) !== '')));
    }

    private function normalizeDepartmentLookupLabel(string $value): string
    {
        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            return '';
        }

        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/[^A-Z0-9 ]+/', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/^OFFICE OF THE\s+/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/^OFFICE OF\s+/', '', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return trim($normalized);
    }

    private function trimNullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function trimOrBlank(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function buildEmployeeFullName(object $employee): string
    {
        $parts = array_values(array_filter([
            trim((string) ($employee->firstname ?? '')),
            trim((string) ($employee->middlename ?? '')),
            trim((string) ($employee->surname ?? '')),
        ], static fn (string $part): bool => $part !== ''));

        if ($parts === []) {
            return trim((string) ($employee->control_no ?? ''));
        }

        return trim(implode(' ', $parts));
    }

    private function buildLedgerControlNoCandidates(string $controlNo, ?object $employee = null): array
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
            static fn (string $value): bool => trim($value) !== ''
        )));
    }

    private function normalizeLedgerControlNo(mixed $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }

        if (! preg_match('/^\d+$/', $raw)) {
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
            'mc06' => null,
            'wellness' => null,
            'mc06_related' => [],
            'other' => [],
            'other_usage_only' => [],
            'other_code_by_id' => [],
        ];
        $forcedLeaveNames = [
            'mandatory / forced leave',
            'mandatory forced leave',
            'forced leave',
        ];

        $leaveTypes = LeaveType::query()
            ->select(['id', 'name', 'category', 'is_credit_based'])
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
                && in_array($normalizedName, $forcedLeaveNames, true)
            ) {
                $typeIds['forced'] = $typeId;
            }

            if (
                $typeIds['wellness'] === null
                && in_array($normalizedName, ['wellness leave', 'wellness'], true)
            ) {
                $typeIds['wellness'] = $typeId;
            }

            if (LeaveType::isSpecialPrivilegeAliasName($leaveType->name ?? null)) {
                if ($typeIds['mc06'] === null) {
                    $typeIds['mc06'] = $typeId;
                }
                $typeIds['mc06_related'][] = $typeId;
            }

            $isCreditBased = (bool) ($leaveType->is_credit_based ?? false);
            $normalizedCategory = strtoupper(trim((string) ($leaveType->category ?? '')));
            $isCoreType = in_array($normalizedName, ['vacation leave', 'vacation', 'sick leave', 'sick'], true);
            $isOtherEligibleType = ! $isCoreType && ! in_array($normalizedName, $forcedLeaveNames, true);
            if ($isOtherEligibleType && $isCreditBased) {
                $typeIds['other'][] = $typeId;
                $typeIds['other_code_by_id'][$typeId] = $this->resolveLedgerOtherTypeCode(
                    $leaveType->name ?? null,
                    $typeId,
                );
            }
            if ($isOtherEligibleType && ! $isCreditBased && $normalizedCategory === 'EVENT') {
                $typeIds['other'][] = $typeId;
                $typeIds['other_usage_only'][] = $typeId;
                $typeIds['other_code_by_id'][$typeId] = $this->resolveLedgerOtherTypeCode(
                    $leaveType->name ?? null,
                    $typeId,
                );
            }
        }

        foreach (LeaveType::resolveSpecialPrivilegeRelatedTypeIds() as $typeId) {
            $normalizedTypeId = (int) $typeId;
            if ($normalizedTypeId > 0) {
                $typeIds['mc06_related'][] = $normalizedTypeId;
            }
        }

        $typeIds['mc06_related'] = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $typeId): int => (int) $typeId,
                $typeIds['mc06_related']
            ),
            static fn (int $typeId): bool => $typeId > 0
        )));
        if ($typeIds['mc06'] === null && $typeIds['mc06_related'] !== []) {
            $typeIds['mc06'] = (int) $typeIds['mc06_related'][0];
        }

        foreach ($typeIds['mc06_related'] as $typeId) {
            $typeIds['other'][] = $typeId;
            $typeIds['other_code_by_id'][$typeId] = 'MC06';
        }
        if (is_int($typeIds['mc06']) && $typeIds['mc06'] > 0) {
            $typeIds['other'][] = $typeIds['mc06'];
            $typeIds['other_code_by_id'][$typeIds['mc06']] = 'MC06';
        }
        if (is_int($typeIds['forced']) && $typeIds['forced'] > 0) {
            $typeIds['other'][] = $typeIds['forced'];
            $typeIds['other_code_by_id'][$typeIds['forced']] = 'FL';
        }
        if (is_int($typeIds['wellness']) && $typeIds['wellness'] > 0) {
            $typeIds['other'][] = $typeIds['wellness'];
            $typeIds['other_code_by_id'][$typeIds['wellness']] = 'WL';
        }

        $typeIds['other'] = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $typeId): int => (int) $typeId,
                $typeIds['other']
            ),
            static fn (int $typeId): bool => $typeId > 0
        )));
        $typeIds['other_usage_only'] = array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $typeId): int => (int) $typeId,
                $typeIds['other_usage_only']
            ),
            static fn (int $typeId): bool => $typeId > 0
        )));
        $normalizedOtherCodeById = [];
        foreach ($typeIds['other'] as $typeId) {
            $normalizedOtherCodeById[$typeId] = $this->normalizeLedgerOtherTypeCodeValue(
                $typeIds['other_code_by_id'][$typeId] ?? null,
                $typeId,
            );
        }
        $typeIds['other_code_by_id'] = $normalizedOtherCodeById;

        return $typeIds;
    }

    private function resolveLedgerTypeCode(
        ?string $typeKey,
        ?string $otherTypeCode = null,
        bool $isForcedLeave = false
    ): ?string {
        if ($isForcedLeave) {
            return 'FL';
        }

        return match ($typeKey) {
            'vacation' => 'VL',
            'sick' => 'SL',
            'other' => $this->normalizeLedgerOtherTypeCodeValue($otherTypeCode),
            default => null,
        };
    }

    private function resolveLedgerDisplayTypeKey(?string $typeKey, ?string $leaveTypeCode = null): ?string
    {
        return $typeKey;
    }

    private function resolveLedgerOtherTypeCode(mixed $leaveTypeName, ?int $leaveTypeId = null): ?string
    {
        if (LeaveType::isSpecialPrivilegeAliasName($leaveTypeName)) {
            return 'MC06';
        }

        $normalizedName = strtolower(trim((string) ($leaveTypeName ?? '')));
        if ($normalizedName === '') {
            return $this->normalizeLedgerOtherTypeCodeValue(null, $leaveTypeId);
        }

        if (in_array($normalizedName, ['wellness leave', 'wellness'], true)) {
            return 'WL';
        }
        if (in_array($normalizedName, ['solo parent leave', 'solo parent'], true)) {
            return 'SPL';
        }
        if (in_array($normalizedName, ['special emergency calamity leave', 'special emergency (calamity) leave', 'calamity leave'], true)) {
            return 'CL';
        }
        if (in_array($normalizedName, ['violence against women and children leave', 'vawc leave', 'vawc'], true)) {
            return 'VAWC';
        }

        $tokens = preg_split('/[^a-z0-9]+/', $normalizedName, -1, PREG_SPLIT_NO_EMPTY);
        if (! is_array($tokens) || $tokens === []) {
            return $this->normalizeLedgerOtherTypeCodeValue(null, $leaveTypeId);
        }

        $ignoredWords = [
            'leave',
            'special',
            'type',
            'of',
            'and',
            'for',
            'with',
            'without',
            'pay',
            'policy',
            'credited',
        ];
        $filteredTokens = array_values(array_filter(
            $tokens,
            static fn (string $token): bool => ! in_array($token, $ignoredWords, true)
        ));

        if ($filteredTokens === []) {
            $filteredTokens = $tokens;
        }

        if (count($filteredTokens) === 1) {
            $singleTokenCode = strtoupper(substr($filteredTokens[0], 0, 4));

            return $this->normalizeLedgerOtherTypeCodeValue($singleTokenCode, $leaveTypeId);
        }

        $initials = '';
        foreach ($filteredTokens as $token) {
            $initials .= strtoupper(substr($token, 0, 1));
            if (strlen($initials) >= 4) {
                break;
            }
        }

        return $this->normalizeLedgerOtherTypeCodeValue($initials, $leaveTypeId);
    }

    private function normalizeLedgerOtherTypeCodeValue(mixed $value, ?int $fallbackTypeId = null): ?string
    {
        $rawCode = strtoupper(trim((string) ($value ?? '')));
        $sanitizedCode = preg_replace('/[^A-Z0-9]+/', '', $rawCode);
        if (is_string($sanitizedCode) && $sanitizedCode !== '') {
            return substr($sanitizedCode, 0, 10);
        }

        if (is_int($fallbackTypeId) && $fallbackTypeId > 0) {
            return 'OT'.$fallbackTypeId;
        }

        return null;
    }

    /**
     * @param  array<int, string>  $typeIdToKey
     * @return array<int, array{leave_type_id:int, type_key:string, days:float}>
     */
    private function resolveLedgerMonetizationComponents(
        LeaveApplication $application,
        array $typeIdToKey,
        ?string $fallbackTypeKey,
        float $fallbackDays
    ): array {
        $rawComponents = $application->monetization_leave_credits ?? null;
        if (is_string($rawComponents)) {
            $decodedComponents = json_decode($rawComponents, true);
            $rawComponents = json_last_error() === JSON_ERROR_NONE ? $decodedComponents : null;
        }

        if ($rawComponents instanceof Collection) {
            $rawComponents = $rawComponents->all();
        }

        $componentsByKey = [];
        if (is_array($rawComponents)) {
            foreach ($rawComponents as $rawComponent) {
                if (! is_array($rawComponent)) {
                    continue;
                }

                $leaveTypeId = (int) ($rawComponent['leave_type_id'] ?? $rawComponent['leaveTypeId'] ?? 0);
                $days = round(max((float) ($rawComponent['days'] ?? $rawComponent['total_days'] ?? 0), 0.0), self::LEDGER_DECIMAL_PRECISION);
                if ($leaveTypeId <= 0 || $days <= 0.0) {
                    continue;
                }

                $canonicalLeaveTypeId = LeaveType::resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
                $typeKey = $typeIdToKey[$canonicalLeaveTypeId] ?? $typeIdToKey[$leaveTypeId] ?? null;
                if (! in_array($typeKey, ['vacation', 'sick'], true)) {
                    continue;
                }

                if (! array_key_exists($typeKey, $componentsByKey)) {
                    $componentsByKey[$typeKey] = [
                        'leave_type_id' => $canonicalLeaveTypeId,
                        'type_key' => $typeKey,
                        'days' => 0.0,
                    ];
                }

                $componentsByKey[$typeKey]['days'] = round(
                    (float) $componentsByKey[$typeKey]['days'] + $days,
                    self::LEDGER_DECIMAL_PRECISION
                );
            }
        }

        if ($componentsByKey !== []) {
            return array_values($componentsByKey);
        }

        $fallbackAmount = round(max($fallbackDays, 0.0), self::LEDGER_DECIMAL_PRECISION);
        if (! in_array($fallbackTypeKey, ['vacation', 'sick'], true) || $fallbackAmount <= 0.0) {
            return [];
        }

        return [[
            'leave_type_id' => (int) $application->leave_type_id,
            'type_key' => $fallbackTypeKey,
            'days' => $fallbackAmount,
        ]];
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

            if (! array_key_exists($typeId, $preferredBalancesByType)) {
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

    private function resolveLedgerRunningBalanceKey(
        ?string $typeKey,
        ?int $leaveTypeId = null,
        bool $isForcedLeave = false
    ): ?string {
        if ($typeKey === 'vacation') {
            return 'vacation';
        }

        if ($typeKey === 'sick') {
            return 'sick';
        }

        if ($typeKey !== 'other') {
            return null;
        }

        $canonicalLeaveTypeId = LeaveType::resolveCanonicalLeaveTypeId($leaveTypeId) ?? (int) ($leaveTypeId ?? 0);
        if ($canonicalLeaveTypeId <= 0) {
            return null;
        }

        return 'other:'.$canonicalLeaveTypeId;
    }

    private function shouldPreferLedgerBalanceRecord(LeaveBalance $candidate, LeaveBalance $current): bool
    {
        $candidateTypeId = (int) ($candidate->leave_type_id ?? 0);
        $currentTypeId = (int) ($current->leave_type_id ?? 0);
        $specialPrivilegeLeaveTypeId = LeaveType::resolveSpecialPrivilegeLeaveTypeId();
        if (
            $specialPrivilegeLeaveTypeId !== null
            && LeaveType::isSpecialPrivilegeType(null, $candidateTypeId)
            && LeaveType::isSpecialPrivilegeType(null, $currentTypeId)
            && $candidateTypeId !== $currentTypeId
        ) {
            if ($candidateTypeId === $specialPrivilegeLeaveTypeId) {
                return true;
            }

            if ($currentTypeId === $specialPrivilegeLeaveTypeId) {
                return false;
            }
        }

        $candidateUpdatedAt = $candidate->updated_at?->timestamp ?? 0;
        $currentUpdatedAt = $current->updated_at?->timestamp ?? 0;

        if ($candidateUpdatedAt !== $currentUpdatedAt) {
            return $candidateUpdatedAt > $currentUpdatedAt;
        }

        return (int) $candidate->id > (int) $current->id;
    }

    private function resolveLedgerBalanceByKey(array $balancesByKey, ?string $balanceKey): float
    {
        if (
            $balanceKey === null
            || ! isset($balancesByKey[$balanceKey])
            || ! $balancesByKey[$balanceKey] instanceof LeaveBalance
        ) {
            return 0.0;
        }

        return $this->roundLedgerValue($balancesByKey[$balanceKey]->balance ?? 0);
    }

    private function resolveLedgerBalance(array $balancesByType, ?int $leaveTypeId): float
    {
        if ($leaveTypeId === null || ! isset($balancesByType[$leaveTypeId])) {
            return 0.0;
        }

        return $this->roundLedgerValue($balancesByType[$leaveTypeId]->balance ?? 0);
    }

    /**
     * @param  array<string, float>  $currentLedgerBalances
     * @param  array<string, mixed>  $trackedTypeIdsByKey
     * @param  array<int, string>  $balanceKeyByTypeId
     * @param  array<int, int>  $otherTypeIds
     * @param  array<int, string|null>  $otherTypeCodeById
     * @return array<int, array{code:string, label:string, balance:float, leave_type_id:int|null, balance_key:string}>
     */
    private function buildLedgerBalanceBadges(
        array $currentLedgerBalances,
        array $trackedTypeIdsByKey,
        array $balanceKeyByTypeId,
        array $otherTypeIds,
        array $otherTypeCodeById
    ): array {
        $badges = [];
        $seenBalanceKeys = [];

        $addBadge = function (string $code, ?int $leaveTypeId, string $balanceKey) use (&$badges, &$seenBalanceKeys, $currentLedgerBalances): void {
            $normalizedBalanceKey = trim($balanceKey);
            if ($normalizedBalanceKey === '' || isset($seenBalanceKeys[$normalizedBalanceKey])) {
                return;
            }

            $seenBalanceKeys[$normalizedBalanceKey] = true;
            $normalizedCode = strtoupper(trim($code));
            $badges[] = [
                'code' => $normalizedCode,
                'label' => $normalizedCode,
                'balance' => $this->roundLedgerValue($currentLedgerBalances[$normalizedBalanceKey] ?? 0.0),
                'leave_type_id' => $leaveTypeId !== null && $leaveTypeId > 0 ? $leaveTypeId : null,
                'balance_key' => $normalizedBalanceKey,
            ];
        };

        $vacationLeaveTypeId = $trackedTypeIdsByKey['vacation'] ?? null;
        $sickLeaveTypeId = $trackedTypeIdsByKey['sick'] ?? null;
        $addBadge('VL', is_int($vacationLeaveTypeId) ? $vacationLeaveTypeId : null, 'vacation');
        $addBadge('SL', is_int($sickLeaveTypeId) ? $sickLeaveTypeId : null, 'sick');

        foreach ($otherTypeIds as $otherTypeId) {
            $normalizedTypeId = (int) $otherTypeId;
            if ($normalizedTypeId <= 0) {
                continue;
            }

            $balanceKey = $balanceKeyByTypeId[$normalizedTypeId]
                ?? $this->resolveLedgerRunningBalanceKey('other', $normalizedTypeId);
            if (! is_string($balanceKey) || $balanceKey === '') {
                continue;
            }

            $otherTypeCode = $otherTypeCodeById[$normalizedTypeId] ?? null;
            $addBadge(
                $this->resolveLedgerTypeCode('other', is_string($otherTypeCode) ? $otherTypeCode : null, false) ?? 'OT'.$normalizedTypeId,
                $normalizedTypeId,
                $balanceKey
            );
        }

        return $badges;
    }

    private function roundLedgerValue(mixed $value): float
    {
        return round((float) $value, self::LEDGER_DECIMAL_PRECISION);
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

    private function buildLedgerParticulars(
        string $entryKind,
        ?string $typeKey,
        float $days,
        bool $isMonetization = false,
        bool $isForcedLeave = false,
        ?string $otherTypeCode = null,
        ?string $prefixOverride = null
    ): string {
        $normalizedPrefixOverride = strtoupper(trim((string) $prefixOverride));
        $prefix = $normalizedPrefixOverride !== ''
            ? $normalizedPrefixOverride
            : match (true) {
                $isForcedLeave => 'FL',
                $typeKey === 'vacation' => 'VL',
                $typeKey === 'sick' => 'SL',
                $typeKey === 'other' && trim((string) $otherTypeCode) !== '' => strtoupper(trim((string) $otherTypeCode)),
                default => null,
            };

        if ($entryKind === 'earned') {
            return $prefix !== null ? $prefix.' 0-0-0' : '0-0-0';
        }

        $formattedDuration = $this->formatLedgerDaysHoursMinutes($days);
        if ($isMonetization) {
            return $formattedDuration.' Monetization';
        }

        $baseParticulars = $prefix !== null
            ? $prefix.' '.$formattedDuration
            : $formattedDuration;

        return $entryKind === 'recall'
            ? $baseParticulars.' Recalled'
            : $baseParticulars;
    }

    private function resolveLedgerParticularsPrefixOverride(
        ?string $typeKey,
        float $linkedVacationDays,
        float $linkedSickDays
    ): ?string {
        if ($typeKey === 'sick' && $linkedVacationDays > 0.0) {
            return 'VL/SL';
        }

        if ($typeKey === 'vacation' && $linkedSickDays > 0.0) {
            return 'SL/VL';
        }

        return null;
    }

    private function formatLedgerDaysHoursMinutes(float $days): string
    {
        $normalizedDays = round(max($days, 0.0), 4);
        if ($normalizedDays <= 0.0) {
            return '0-0-0';
        }

        $totalMinutes = (int) round(
            $normalizedDays
            * self::LEDGER_HOURS_PER_DAY
            * self::LEDGER_MINUTES_PER_HOUR
        );
        if ($totalMinutes <= 0) {
            return '0-0-0';
        }

        $minutesPerDay = self::LEDGER_HOURS_PER_DAY * self::LEDGER_MINUTES_PER_HOUR;
        $dayCount = intdiv($totalMinutes, $minutesPerDay);
        $remainingMinutes = $totalMinutes % $minutesPerDay;
        $hourCount = intdiv($remainingMinutes, self::LEDGER_MINUTES_PER_HOUR);
        $minuteCount = $remainingMinutes % self::LEDGER_MINUTES_PER_HOUR;

        return "{$dayCount}-{$hourCount}-{$minuteCount}";
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
            $stored = round((float) $application->deductible_days, 3);
            if ($stored < 0.0) {
                return 0.0;
            }

            return $stored;
        }

        if ((bool) $application->is_monetization) {
            return $totalDays;
        }

        return $this->normalizeLedgerPayMode($application->pay_mode ?? null, false) === LeaveApplication::PAY_MODE_WITHOUT_PAY
            ? 0.0
            : $totalDays;
    }

    /**
     * @return array{with_pay: float, without_pay: float}
     */
    private function resolveLedgerApplicationPayAmounts(
        LeaveApplication $application,
        float $totalDays,
        float $deductibleDays,
        string $payMode,
        bool $isMonetization
    ): array {
        $normalizedDeductibleDays = round(max($deductibleDays, 0.0), self::LEDGER_DECIMAL_PRECISION);
        if ($isMonetization) {
            return [
                'with_pay' => $normalizedDeductibleDays,
                'without_pay' => 0.0,
            ];
        }

        $storedWithoutPayDays = $application->without_pay_days !== null
            ? round(max((float) $application->without_pay_days, 0.0), self::LEDGER_DECIMAL_PRECISION)
            : null;
        if ($storedWithoutPayDays !== null) {
            return [
                'with_pay' => $normalizedDeductibleDays,
                'without_pay' => $storedWithoutPayDays,
            ];
        }

        $normalizedPayMode = $this->normalizeLedgerPayMode($payMode, false);
        $selectedDates = $application->resolvedSelectedDates();
        if (! is_array($selectedDates) || $selectedDates === []) {
            $withPayAmount = $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY
                ? 0.0
                : $normalizedDeductibleDays;

            return [
                'with_pay' => $withPayAmount,
                'without_pay' => round(max($totalDays - $withPayAmount, 0.0), self::LEDGER_DECIMAL_PRECISION),
            ];
        }

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
            round(max($totalDays, 0.0), self::LEDGER_DECIMAL_PRECISION),
            trim((string) ($application->employee_control_no ?? '')) ?: null
        );

        $weightedWithPay = 0.0;
        $weightedWithoutPay = 0.0;
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeLedgerDateKey($rawDate) ?? trim((string) $rawDate);
            if ($dateKey === '') {
                continue;
            }

            $weight = round(
                max((float) ($coverageWeights[$dateKey] ?? 0.0), 0.0),
                self::LEDGER_DECIMAL_PRECISION
            );
            if ($weight <= 0.0) {
                continue;
            }

            $effectiveMode = $normalizedPayStatus[$dateKey] ?? $normalizedPayMode;
            $resolvedMode = $this->resolveLedgerPayModeFromStatusValue($effectiveMode) ?? $normalizedPayMode;
            if ($resolvedMode === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                $weightedWithoutPay += $weight;

                continue;
            }

            $weightedWithPay += $weight;
        }

        $withPayAmount = $weightedWithPay > 0.0
            ? ($normalizedDeductibleDays > 0.0 ? $normalizedDeductibleDays : $weightedWithPay)
            : 0.0;

        return [
            'with_pay' => round(max($withPayAmount, 0.0), self::LEDGER_DECIMAL_PRECISION),
            'without_pay' => round(max($weightedWithoutPay, 0.0), self::LEDGER_DECIMAL_PRECISION),
        ];
    }

    /**
     * Older approved FL rows may not have stored their FL entitlement deduction.
     * Infer that movement from the linked VL paid days so historical ledgers
     * still show initial FL credits and the current FL balance correctly.
     *
     * @param  Collection<int, LeaveApplication>  $applications
     * @return array{by_application_id: array<int, float>, total: float}
     */
    private function resolveLedgerInferredForcedLeaveDeductions(
        Collection $applications,
        int $forcedLeaveTypeId,
        float $currentForcedLeaveBalance
    ): array {
        if ($forcedLeaveTypeId <= 0 || $currentForcedLeaveBalance <= 0.0) {
            return [
                'by_application_id' => [],
                'total' => 0.0,
            ];
        }

        $remainingBalance = $this->roundLedgerValue($currentForcedLeaveBalance);
        $deductionsByApplicationId = [];
        $totalInferred = 0.0;

        $forcedApplications = $applications
            ->filter(function ($application) use ($forcedLeaveTypeId): bool {
                if (! $application instanceof LeaveApplication) {
                    return false;
                }

                if ((bool) $application->is_monetization) {
                    return false;
                }

                if ((int) $application->leave_type_id !== $forcedLeaveTypeId) {
                    return false;
                }

                if (strtoupper(trim((string) ($application->status ?? ''))) !== LeaveApplication::STATUS_APPROVED) {
                    return false;
                }

                return $this->roundLedgerValue($application->linked_forced_leave_deducted_days ?? 0.0) <= 0.0;
            })
            ->sortBy(function (LeaveApplication $application): string {
                $timestamp = $application->hr_approved_at?->toIso8601String()
                    ?? $application->created_at?->toIso8601String()
                    ?? '';

                return $timestamp.'|'.str_pad((string) (int) $application->id, 12, '0', STR_PAD_LEFT);
            })
            ->values();

        foreach ($forcedApplications as $application) {
            if ($remainingBalance <= 0.0) {
                break;
            }

            $totalDays = round((float) ($application->total_days ?? 0.0), 2);
            $deductibleDays = round((float) ($application->deductible_days ?? $totalDays), self::LEDGER_DECIMAL_PRECISION);
            $payAmounts = $this->resolveLedgerApplicationPayAmounts(
                $application,
                $totalDays,
                $deductibleDays,
                LeaveApplication::PAY_MODE_WITH_PAY,
                false
            );
            $withPayAmount = $this->roundLedgerValue($payAmounts['with_pay'] ?? 0.0);
            $linkedVacationAmount = $this->roundLedgerValue($application->linked_vacation_leave_deducted_days ?? 0.0);
            $candidateAmount = $linkedVacationAmount > 0.0
                ? min($linkedVacationAmount, max($withPayAmount, $linkedVacationAmount))
                : $withPayAmount;
            $candidateAmount = $this->roundLedgerValue($candidateAmount);
            if ($candidateAmount <= 0.0) {
                continue;
            }

            $inferredAmount = $this->roundLedgerValue(min($candidateAmount, $remainingBalance));
            if ($inferredAmount <= 0.0) {
                continue;
            }

            $deductionsByApplicationId[(int) $application->id] = $inferredAmount;
            $totalInferred = $this->roundLedgerValue($totalInferred + $inferredAmount);
            $remainingBalance = $this->roundLedgerValue($remainingBalance - $inferredAmount);
        }

        return [
            'by_application_id' => $deductionsByApplicationId,
            'total' => $totalInferred,
        ];
    }

    private function resolveLedgerRecallRestorableDetails(
        LeaveApplication $application,
        array $selectedRecallDateKeys = [],
        ?CarbonImmutable $effectiveRecallDate = null
    ): array {
        $deductibleDays = $this->resolveLedgerApplicationDeductibleDays($application);
        if ($deductibleDays <= 0.0) {
            return ['days' => 0.0, 'dates' => []];
        }

        if ((bool) $application->is_monetization) {
            return ['days' => $deductibleDays, 'dates' => []];
        }

        $selectedDates = $application->resolvedSelectedDates();
        if (! is_array($selectedDates) || $selectedDates === []) {
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
            round((float) ($application->total_days ?? 0), 2),
            trim((string) ($application->employee_control_no ?? '')) ?: null
        );

        $selectedRecallDateSet = array_fill_keys(
            $this->normalizeLedgerRecallDateKeys(
                $selectedRecallDateKeys !== []
                    ? $selectedRecallDateKeys
                    : $this->resolveLedgerStoredRecallDateKeys($application, $effectiveRecallDate)
            ),
            true
        );
        if ($selectedRecallDateSet === []) {
            return ['days' => 0.0, 'dates' => []];
        }

        $restorableDays = 0.0;
        $restorableDates = [];

        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeLedgerDateKey($rawDate) ?? trim((string) $rawDate);
            if ($dateKey === '' || ! isset($selectedRecallDateSet[$dateKey])) {
                continue;
            }

            $weight = round(max((float) ($coverageWeights[$dateKey] ?? 1.0), 0.0), self::LEDGER_DECIMAL_PRECISION);
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

        $restorableDays = round(max($restorableDays, 0.0), self::LEDGER_DECIMAL_PRECISION);
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

    private function resolveLedgerStoredRecallDateKeys(
        LeaveApplication $application,
        ?CarbonImmutable $effectiveRecallDate = null
    ): array {
        $storedRecallDates = is_array($application->recall_selected_dates)
            ? $application->recall_selected_dates
            : null;
        $normalizedStoredRecallDates = $this->normalizeLedgerRecallDateKeys($storedRecallDates ?? []);
        if ($normalizedStoredRecallDates !== []) {
            return $normalizedStoredRecallDates;
        }

        $selectedDates = $application->resolvedSelectedDates();
        if (! is_array($selectedDates) || $selectedDates === []) {
            return [];
        }

        $effectiveDateKey = $application->recall_effective_date
            ? CarbonImmutable::parse((string) $application->recall_effective_date)->toDateString()
            : ($effectiveRecallDate?->toDateString() ?? null);
        if ($effectiveDateKey === null || $effectiveDateKey === '') {
            return [];
        }

        $resolvedDateKeys = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeLedgerDateKey($rawDate) ?? trim((string) $rawDate);
            if ($dateKey === '' || strcmp($dateKey, $effectiveDateKey) < 0) {
                continue;
            }

            $resolvedDateKeys[] = $dateKey;
        }

        $resolvedDateKeys = array_values(array_unique(array_filter($resolvedDateKeys)));
        sort($resolvedDateKeys);

        return $resolvedDateKeys;
    }

    private function normalizeLedgerRecallDateKeys(array $rawDates): array
    {
        $normalizedDateKeys = [];

        foreach ($rawDates as $rawDate) {
            $dateKey = $this->normalizeLedgerDateKey($rawDate) ?? trim((string) $rawDate);
            if ($dateKey === '') {
                continue;
            }

            $normalizedDateKeys[] = $dateKey;
        }

        $normalizedDateKeys = array_values(array_unique(array_filter($normalizedDateKeys)));
        sort($normalizedDateKeys);

        return $normalizedDateKeys;
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
        if (! is_array($selectedDatePayStatus) || $selectedDatePayStatus === []) {
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

            if ($restrictToSelectedDates && ! array_key_exists($dateKey, $dateSet)) {
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
        if (! is_array($selectedDateCoverage) || $selectedDateCoverage === []) {
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

            if ($restrictToSelectedDates && ! array_key_exists($dateKey, $dateSet)) {
                continue;
            }

            $coverage = strtolower(trim((string) $rawCoverage));
            if ($coverage === 'half') {
                $compacted[$dateKey] = 'half';

                continue;
            }

            if (! $restrictToSelectedDates && $coverage === 'whole') {
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
        float $totalDays,
        ?string $employeeControlNo = null
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

        $defaultWholeDayWeight = app(WorkScheduleService::class)
            ->resolveCoverageDeductionDays('whole', $employeeControlNo);
        $defaultHalfDayWeight = app(WorkScheduleService::class)
            ->resolveCoverageDeductionDays('half', $employeeControlNo);
        $defaultCoverageWeight = $defaultWholeDayWeight;
        $dateCount = count($resolvedDates);
        if ($dateCount > 0) {
            $halfMatch = abs(($dateCount * $defaultHalfDayWeight) - $totalDays) < 0.00001;
            $wholeMatch = abs(((float) $dateCount) - $totalDays) < 0.00001;
            if ($halfMatch) {
                $defaultCoverageWeight = $defaultHalfDayWeight;
            } elseif (! $wholeMatch) {
                $defaultCoverageWeight = max(
                    min($totalDays / $dateCount, $defaultWholeDayWeight),
                    $defaultHalfDayWeight
                );
            }
        }

        $weights = [];
        foreach ($resolvedDates as $dateKey) {
            $hasCoverageValue = array_key_exists($dateKey, $coverageMap);
            $coverage = strtolower(trim((string) ($coverageMap[$dateKey] ?? '')));
            if ($coverage === 'half') {
                $weights[$dateKey] = $defaultHalfDayWeight;

                continue;
            }

            if ($coverage === 'whole') {
                $weights[$dateKey] = $defaultWholeDayWeight;

                continue;
            }

            if ($hasCoverageOverrides && ! $hasCoverageValue) {
                $weights[$dateKey] = $defaultWholeDayWeight;

                continue;
            }

            $weights[$dateKey] = $defaultCoverageWeight;
        }

        return $weights;
    }
}
