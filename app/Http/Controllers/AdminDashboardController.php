<?php

namespace App\Http\Controllers;

use App\Models\DepartmentAdmin;
use App\Models\Employee;
use App\Models\HRAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Notification;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Admin Dashboard — department-level leave application statistics.
 * LOCAL LMS_DB only.
 */
class AdminDashboardController extends Controller
{
    public function __construct()
    {
    }

    /**
     * Dashboard data: pending count, approved today, total approved, all department applications.
     */
    public function index(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $admin->loadMissing('department');
        $deptName = $admin->department?->name;

        $applications = LeaveApplication::with(['leaveType', 'applicantAdmin', 'employee', 'logs'])
            ->where(function ($query) use ($deptName, $admin) {
                // Include employee leaves for this department (matched by office)
                $query->whereIn('employee_control_no', function ($q) use ($deptName) {
                    $q->select('control_no')->from('tblEmployees')->where('office', $deptName);
                })
                    // OR admin self-apply leaves for this department
                    ->orWhereHas('applicantAdmin', fn($q) => $q->where('department_id', $admin->department_id));
            })
            ->orderByDesc('created_at')
            ->get();

        $pendingApps = $applications->where('status', LeaveApplication::STATUS_PENDING_ADMIN);
        $pending = $pendingApps->count();

        $approvedTodayApps = $applications->filter(function (LeaveApplication $app): bool {
            return in_array($app->status, [
                LeaveApplication::STATUS_PENDING_HR,
                LeaveApplication::STATUS_APPROVED,
            ], true) && (bool) $app->admin_approved_at?->isToday();
        });
        $approvedToday = $approvedTodayApps->count();

        $totalApprovedApps = $applications->whereIn('status', [
            LeaveApplication::STATUS_PENDING_HR,
            LeaveApplication::STATUS_APPROVED,
        ]);
        $totalApproved = $totalApprovedApps->count();

        $employeesByControlNo = Employee::query()
            ->when($deptName, fn($query) => $query->where('office', $deptName))
            ->get(['control_no', 'status', 'surname', 'firstname', 'middlename', 'office', 'designation', 'rate_mon'])
            ->mapWithKeys(fn(Employee $employee) => [
                $this->normalizeControlNo($employee->control_no) => $employee,
            ])
            ->all();

        $employeeStatusByControlNo = collect($employeesByControlNo)
            ->map(fn(Employee $employee) => $employee->status)
            ->all();

        $actorDirectory = $this->buildActorDirectory($applications, $employeesByControlNo);
        $leaveBalanceDirectory = $this->buildLeaveBalanceDirectory($applications, $employeesByControlNo);
        $formatted = $applications->map(
            fn($app) => $this->formatApplication($app, $employeesByControlNo, $actorDirectory, $leaveBalanceDirectory)
        );
        $kpiBreakdown = [
            'pending' => $this->buildEmploymentStatusBreakdown($pendingApps, $employeeStatusByControlNo),
            'approved_today' => $this->buildEmploymentStatusBreakdown($approvedTodayApps, $employeeStatusByControlNo),
            'total_approved' => $this->buildEmploymentStatusBreakdown($totalApprovedApps, $employeeStatusByControlNo),
            'total' => $this->buildEmploymentStatusBreakdown($applications, $employeeStatusByControlNo),
        ];
        $analytics = $this->buildDashboardTrendAnalytics($applications);

        return response()->json([
            'pending_count' => $pending,
            'approved_today' => $approvedToday,
            'total_approved' => $totalApproved,
            'total_count' => $applications->count(),
            'kpi_breakdown' => $kpiBreakdown,
            'analytics' => $analytics,
            'applications' => $formatted,
        ]);
    }

    // ─── Admin's own leave credits ────────────────────────────────────

    /**
     * Return the admin's own leave summary (accrued, resettable, event-based).
     */
    public function leaveCredits(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $leaveInitialized = $this->resolveLeaveInitializedState($admin);
        $adminSalary = $this->resolveAdminEmployeeSalary($admin);
        $adminEmploymentStatus = $this->resolveAdminEmployee($admin)?->status;

        $balances = $this->queryAdminEmployeeBalances($admin)
            ->with('leaveType')
            ->get()
            ->keyBy('leave_type_id');

        // ACCRUED (Vacation Leave, Sick Leave)
        $accruedTypes = $this->filterLeaveTypesForEmploymentStatus(
            LeaveType::accrued()->orderBy('name')->get(),
            $adminEmploymentStatus
        );
        $accrued = [];
        foreach ($accruedTypes as $type) {
            $bal = $balances->get($type->id);
            $accrued[] = [
                'id' => $type->id,
                'name' => $type->name,
                'balance' => $bal ? (float) $bal->balance : 0,
                'accrual_rate' => (float) $type->accrual_rate,
                'is_credit_based' => $type->is_credit_based,
                'requires_documents' => (bool) $type->requires_documents,
                'allowed_status' => $type->normalizedAllowedStatuses(),
            ];
        }

        // RESETTABLE
        $resettableTypes = $this->filterLeaveTypesForEmploymentStatus(
            LeaveType::resettable()->orderBy('name')->get(),
            $adminEmploymentStatus
        );
        $resettable = $resettableTypes->map(function (LeaveType $type) use ($balances) {
            $bal = $balances->get($type->id);
            return [
                'id' => $type->id,
                'name' => $type->name,
                'balance' => $bal ? (float) $bal->balance : 0,
                'max_days' => $type->max_days,
                'is_credit_based' => (bool) $type->is_credit_based,
                'requires_documents' => (bool) $type->requires_documents,
                'allowed_status' => $type->normalizedAllowedStatuses(),
                'description' => $type->description,
            ];
        })->values();

        // EVENT-BASED
        $eventTypes = $this->filterLeaveTypesForEmploymentStatus(
            LeaveType::eventBased()->orderBy('name')->get(),
            $adminEmploymentStatus
        );
        $eventBased = $eventTypes->map(fn(LeaveType $type) => [
            'id' => $type->id,
            'name' => $type->name,
            'max_days' => $type->max_days,
            'is_credit_based' => (bool) $type->is_credit_based,
            'requires_documents' => (bool) $type->requires_documents,
            'allowed_status' => $type->normalizedAllowedStatuses(),
            'description' => $type->description,
        ])->values();

        return response()->json([
            'leave_initialized' => $leaveInitialized,
            'salary' => $adminSalary,
            'accrued' => $accrued,
            'resettable' => $resettable,
            'event_based' => $eventBased,
        ]);
    }

    /**
     * Get leave types available for admin's own balance initialization.
     */
    public function initializableTypes(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        if ($this->resolveLeaveInitializedState($admin)) {
            return response()->json([
                'message' => 'Leave balances already initialized.',
                'leave_initialized' => true,
            ]);
        }

        $types = $this->filterLeaveTypesForEmploymentStatus(
            LeaveType::whereIn('category', [
                LeaveType::CATEGORY_ACCRUED,
                LeaveType::CATEGORY_RESETTABLE,
            ])
                ->orderBy('category')
                ->orderBy('name')
                ->get(['id', 'name', 'category', 'accrual_rate', 'max_days', 'description', 'allowed_status']),
            $this->resolveAdminEmployee($admin)?->status
        );

        return response()->json([
            'leave_initialized' => false,
            'leave_types' => $types->map(fn(LeaveType $type) => [
                'id' => (int) $type->id,
                'name' => $type->name,
                'category' => $type->category,
                'accrual_rate' => $type->accrual_rate !== null ? (float) $type->accrual_rate : null,
                'max_days' => $type->max_days !== null ? (int) $type->max_days : null,
                'description' => $type->description,
                'allowed_status' => $type->normalizedAllowedStatuses(),
            ])->values(),
        ]);
    }

    /**
     * One-time initialization of the admin's own leave balances.
     */
    public function initializeBalance(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        if ($this->resolveLeaveInitializedState($admin)) {
            return response()->json(['message' => 'Leave balances already initialized.'], 422);
        }

        $adminEmployeeControlNo = $this->resolveAdminEmployeeControlNo($admin);
        if ($adminEmployeeControlNo === null) {
            return response()->json([
                'message' => 'Admin employee record not found. Cannot initialize leave balances.',
            ], 422);
        }

        $allowedTypeIds = $this->filterLeaveTypesForEmploymentStatus(
            LeaveType::whereIn('category', [
                LeaveType::CATEGORY_ACCRUED,
                LeaveType::CATEGORY_RESETTABLE,
            ])->get(['id', 'allowed_status']),
            $this->resolveAdminEmployee($admin)?->status
        )->pluck('id')->map(fn($id) => (int) $id)->all();

        $request->validate(['balances' => ['required', 'array', 'min:1']]);

        $balances = $request->input('balances');
        $errors = [];

        foreach ($balances as $typeId => $value) {
            if (!in_array((int) $typeId, $allowedTypeIds, true)) {
                $errors["balances.{$typeId}"] = ["Invalid leave type ID: {$typeId}"];
                continue;
            }
            if (!is_numeric($value) || $value < 0) {
                $errors["balances.{$typeId}"] = ['Balance must be a non-negative number.'];
                continue;
            }
            $leaveType = LeaveType::find((int) $typeId);
            if ($leaveType && $leaveType->max_days !== null && (float) $value > (float) $leaveType->max_days) {
                $errors["balances.{$typeId}"] = ["Balance cannot exceed {$leaveType->max_days} for {$leaveType->name}."];
            }
        }

        foreach ($allowedTypeIds as $id) {
            if (!array_key_exists((string) $id, $balances) && !array_key_exists($id, $balances)) {
                $errors["balances.{$id}"] = ['This leave type balance is required.'];
            }
        }

        if (!empty($errors)) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        DB::transaction(function () use ($admin, $balances, $adminEmployeeControlNo) {
            $now = now();
            foreach ($balances as $typeId => $value) {
                LeaveBalance::query()->updateOrCreate(
                    [
                        'employee_control_no' => $adminEmployeeControlNo,
                        'leave_type_id' => (int) $typeId,
                    ],
                    [
                        'balance' => (float) $value,
                        'year' => $now->year,
                    ]
                );
            }
        });

        return response()->json(['message' => 'Leave balances initialized successfully.']);
    }

    /**
     * GET /admin/self-leave-balance/{leaveTypeId}
     * Return the admin's own balance for a specific leave type.
     * Used by the monetization form.
     */
    public function selfLeaveBalance(Request $request, int $leaveTypeId): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $leaveType = LeaveType::find($leaveTypeId);
        if (!$leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        if (!$leaveType->allowsEmploymentStatus($this->resolveAdminEmployee($admin)?->status)) {
            return response()->json([
                'message' => "{$leaveType->name} is not available for your employment status.",
                'errors' => [
                    'leave_type_id' => ["{$leaveType->name} is not available for your employment status."],
                ],
            ], 422);
        }

        $balance = $this->findAdminEmployeeBalanceByLeaveType($admin, $leaveTypeId);

        return response()->json([
            'leave_type_id' => $leaveType->id,
            'leave_type_name' => $leaveType->name,
            'balance' => $balance ? (float) $balance->balance : 0,
            'salary' => $this->resolveAdminEmployeeSalary($admin),
        ]);
    }

    /**
     * GET /admin/employee-leave-balance/{employeeControlNo}/{leaveTypeId}
     * Return a department employee's balance for a specific leave type.
     * Used by the admin on-behalf monetization form.
     */
    public function employeeLeaveBalance(Request $request, string $employeeControlNo, int $leaveTypeId): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $admin->loadMissing('department');
        $employee = Employee::findByControlNo($employeeControlNo);
        if (!$employee || $employee->office !== $admin->department?->name) {
            return response()->json(['message' => 'Employee not found in your department.'], 404);
        }

        $leaveType = LeaveType::find($leaveTypeId);
        if (!$leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        if (!$leaveType->allowsEmploymentStatus($employee->status)) {
            return response()->json([
                'message' => "{$leaveType->name} is not available for {$employee->full_name}.",
                'errors' => [
                    'leave_type_id' => ["{$leaveType->name} is not available for {$employee->full_name}."],
                ],
            ], 422);
        }

        $employeeControlNo = trim((string) $employee->control_no);
        $balance = LeaveBalance::query()
            ->where('leave_type_id', $leaveTypeId)
            ->whereIn('employee_control_no', $this->buildControlNoCandidates($employeeControlNo))
            ->first();

        return response()->json([
            'leave_type_id' => $leaveType->id,
            'leave_type_name' => $leaveType->name,
            'balance' => $balance ? (float) $balance->balance : 0,
        ]);
    }

    /**
     * Store a leave application for the admin themselves.
     */
    public function storeSelfLeave(Request $request): JsonResponse
    {
        $this->normalizeSelectedDatesInput($request);
        $this->normalizeSelectedDatePolicyInput($request);

        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        // Detect monetization request
        $isMonetization = (bool) $request->input('is_monetization', false);

        if ($isMonetization) {
            return $this->storeSelfMonetization($request, $admin);
        }

        $validated = $request->validate([
            'leave_type_id' => 'required|exists:tblLeaveTypes,id',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'total_days' => 'required|numeric|min:0.5',
            'reason' => ['nullable', 'string', 'max:2000'],
            'selected_dates' => ['nullable', 'array'],
            'selected_dates.*' => ['date'],
            'selected_date_pay_status' => ['nullable', 'array'],
            'selected_date_pay_status.*' => ['nullable', 'string', 'in:WP,WOP'],
            'selected_date_coverage' => ['nullable', 'array'],
            'selected_date_coverage.*' => ['nullable', 'string', 'in:whole,half'],
            'commutation' => ['nullable', 'string', 'in:Not Requested,Requested'],
            'pay_mode' => ['nullable', 'string', 'in:WP,WOP'],
            'attachment' => ['nullable', 'file', 'max:10240'],
            'attachment_submitted' => ['nullable', 'boolean'],
            'attachment_attached' => ['nullable', 'boolean'],
            'has_attachment' => ['nullable', 'boolean'],
            'with_attachment' => ['nullable', 'boolean'],
            'attachment_reference' => ['nullable', 'string', 'max:500'],
        ]);

        $requestedPayMode = $this->normalizePayMode($validated['pay_mode'] ?? null);
        $requestedTotalDays = round((float) $validated['total_days'], 2);
        $resolvedSelectedDates = LeaveApplication::resolveSelectedDates(
            $validated['start_date'],
            $validated['end_date'],
            is_array($validated['selected_dates'] ?? null) ? $validated['selected_dates'] : null,
            $requestedTotalDays
        );
        $selectedDatePayStatus = $this->compactSelectedDatePayStatusMap(
            $this->normalizeSelectedDatePayStatusMap($validated['selected_date_pay_status'] ?? null),
            $resolvedSelectedDates,
            $requestedPayMode
        );
        $selectedDateCoverage = $this->compactSelectedDateCoverageMap(
            $this->normalizeSelectedDateCoverageMap($validated['selected_date_coverage'] ?? null),
            $resolvedSelectedDates
        );
        $requestedDeductibleDays = $this->resolveRequestedDeductibleDays(
            $resolvedSelectedDates,
            $selectedDateCoverage,
            $selectedDatePayStatus,
            $requestedPayMode,
            $requestedTotalDays
        );

        $leaveType = LeaveType::find($validated['leave_type_id']);

        if (!$leaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
                'errors' => [
                    'leave_type_id' => ['Selected leave type is not available.'],
                ],
            ], 422);
        }

        if (!$leaveType->allowsEmploymentStatus($this->resolveAdminEmployee($admin)?->status)) {
            return response()->json([
                'message' => "{$leaveType->name} is not available for your employment status.",
                'errors' => [
                    'leave_type_id' => ["{$leaveType->name} is not available for your employment status."],
                ],
            ], 422);
        }

        if ($leaveType->max_days && (float) $validated['total_days'] > (float) $leaveType->max_days) {
            return response()->json([
                'message' => "This leave type is limited to {$leaveType->max_days} days per application.",
                'errors' => [
                    'total_days' => ["Maximum of {$leaveType->max_days} days allowed for {$leaveType->name}."],
                ],
            ], 422);
        }

        $isForcedLeave = strcasecmp(trim((string) $leaveType->name), 'Mandatory / Forced Leave') === 0;
        if ($isForcedLeave) {
            $vacationLeaveTypeId = LeaveType::query()
                ->whereRaw('LOWER(name) = ?', ['vacation leave'])
                ->value('id');
            if ($vacationLeaveTypeId !== null) {
                $vacationBalance = $this->findAdminEmployeeBalanceByLeaveType($admin, (int) $vacationLeaveTypeId);
                $availableVacationBalance = $vacationBalance ? (float) $vacationBalance->balance : 0.0;
                if ($availableVacationBalance + 1e-9 < $requestedTotalDays) {
                    return response()->json([
                        'message' => 'Insufficient Vacation Leave balance to apply Mandatory / Forced Leave.',
                        'errors' => [
                            'leave_type_id' => ['Mandatory / Forced Leave requires enough Vacation Leave balance.'],
                            'vacation_leave_balance' => [
                                'Available Vacation Leave is '
                                . self::formatDays($availableVacationBalance)
                                . ', but '
                                . self::formatDays($requestedTotalDays)
                                . ' is required.',
                            ],
                        ],
                        'available_vacation_leave_days' => $availableVacationBalance,
                        'required_vacation_leave_days' => $requestedTotalDays,
                    ], 422);
                }
            }
        }

        $attachmentState = $this->resolveAttachmentStateFromRequest($request, $validated);
        $isSickLeave = $this->isSickLeaveType($leaveType);
        $attachmentRequired = $isSickLeave
            ? $requestedTotalDays >= 5.0
            : (bool) ($leaveType->requires_documents ?? false);
        if ($attachmentRequired && !(bool) ($attachmentState['attachment_submitted'] ?? false)) {
            $requiredDocumentMessage = $isSickLeave
                ? 'Medical certificate is required for Sick Leave applications of 5 days or more.'
                : 'Supporting document is required for the selected leave type.';
            return response()->json([
                'message' => $requiredDocumentMessage,
                'errors' => [
                    'attachment' => [$requiredDocumentMessage],
                ],
            ], 422);
        }

        if ($isSickLeave) {
            $graceWindowPayMode = $this->resolveSickLeavePayModeFromFilingWindow(
                $resolvedSelectedDates,
                $request->input('date_filed') ?? now(),
                (string) $validated['start_date'],
                (string) $validated['end_date']
            );

            if ($graceWindowPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                $requestedPayMode = LeaveApplication::PAY_MODE_WITHOUT_PAY;
                $requestedDeductibleDays = 0.0;
                $selectedDatePayStatus = $this->applyUniformSelectedDatePayStatus(
                    $resolvedSelectedDates,
                    LeaveApplication::PAY_MODE_WITHOUT_PAY
                );
            }
        }

        $deductibleDays = $requestedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY
            ? 0.0
            : $requestedDeductibleDays;
        $autoConvertedToWop = false;
        $autoWithoutPayDays = 0.0;

        // Check balance if credit-based
        if (
            $leaveType->is_credit_based
            && $requestedPayMode !== LeaveApplication::PAY_MODE_WITHOUT_PAY
        ) {
            $balance = $this->findAdminEmployeeBalanceByLeaveType($admin, (int) $leaveType->id);
            $currentBalance = $balance ? (float) $balance->balance : 0.0;

            if ($currentBalance + 1e-9 < $deductibleDays) {
                $targetDeductibleDays = round(min($deductibleDays, max($currentBalance, 0.0)), 2);
                $autoWithoutPayDays = round(max($deductibleDays - $targetDeductibleDays, 0.0), 2);
                $autoConvertedToWop = $autoWithoutPayDays > 0;
                $deductibleDays = $targetDeductibleDays;

                if (is_array($resolvedSelectedDates) && $resolvedSelectedDates !== []) {
                    $selectedDatePayStatus = $this->rebalanceSelectedDatePayStatusToDeductibleDays(
                        $resolvedSelectedDates,
                        $selectedDateCoverage,
                        $selectedDatePayStatus,
                        $deductibleDays
                    );
                }
            }
        }

        $requestedPayMode = $this->resolvePayModeFromSelectedDatePayStatus(
            $resolvedSelectedDates,
            $selectedDatePayStatus,
            $deductibleDays
        );
        if ($requestedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
            $deductibleDays = 0.0;
        }

        $adminEmployeeControlNo = $this->resolveAdminEmployeeControlNo($admin);
        if (!$adminEmployeeControlNo) {
            return response()->json([
                'message' => 'Admin employee record not found.',
            ], 422);
        }

        $duplicateDateValidation = $this->validateNoDuplicateLeaveDates(
            $adminEmployeeControlNo,
            (string) $validated['start_date'],
            (string) $validated['end_date'],
            $resolvedSelectedDates,
            $requestedTotalDays
        );
        if ($duplicateDateValidation instanceof JsonResponse) {
            return $duplicateDateValidation;
        }

        // 3. Create the application
        $application = DB::transaction(function () use (
            $validated,
            $admin,
            $leaveType,
            $adminEmployeeControlNo,
            $requestedPayMode,
            $deductibleDays,
            $resolvedSelectedDates,
            $selectedDatePayStatus,
            $selectedDateCoverage,
            $attachmentRequired,
            $attachmentState
        ) {
            $app = LeaveApplication::create([
                'applicant_admin_id' => $admin->id,
                'employee_control_no' => $this->canonicalizeControlNo($adminEmployeeControlNo),
                'leave_type_id' => $leaveType->id,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'total_days' => $validated['total_days'],
                'deductible_days' => $deductibleDays,
                'reason' => $validated['reason'] ?? null,
                'selected_dates' => $resolvedSelectedDates,
                'selected_date_pay_status' => $selectedDatePayStatus !== [] ? $selectedDatePayStatus : null,
                'selected_date_coverage' => $selectedDateCoverage !== [] ? $selectedDateCoverage : null,
                'commutation' => $validated['commutation'] ?? 'Not Requested',
                'pay_mode' => $requestedPayMode,
                'attachment_required' => $attachmentRequired,
                'attachment_submitted' => (bool) ($attachmentState['attachment_submitted'] ?? false),
                'attachment_reference' => $attachmentState['attachment_reference'] ?? null,
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'admin_approved_at' => now(),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'created_at' => now(),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_APPROVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => 'Personal application (Form No. 6). Auto-approved at department level.',
                'created_at' => now(),
            ]);

            return $app;
        });

        // 4. Notify all HR accounts
        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_REQUEST,
                'New Personal Leave Application',
                "{$admin->full_name} submitted a personal {$leaveType->name} leave application (" . self::formatDays($application->total_days) . ").",
                $application->id
            );
        }

        return response()->json([
            'message' => $autoConvertedToWop
                ? 'Leave application submitted successfully with partial Without Pay due to insufficient leave balance.'
                : 'Leave application submitted successfully.',
            'application' => $this->formatApplication($application->fresh(['leaveType', 'applicantAdmin'])),
            'auto_converted_to_wop' => $autoConvertedToWop,
            'auto_without_pay_days' => $autoWithoutPayDays,
        ]);
    }

    /**
     * Handle admin personal monetization submission.
     */
    private function storeSelfMonetization(Request $request, DepartmentAdmin $admin): JsonResponse
    {
        $validated = $request->validate([
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'total_days' => ['required', 'numeric', 'min:1', 'max:999'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'salary' => ['nullable', 'numeric', 'min:0'],
        ]);

        $leaveType = LeaveType::find($validated['leave_type_id']);
        if (!$leaveType || !in_array($leaveType->name, ['Vacation Leave', 'Sick Leave'], true)) {
            return response()->json([
                'message' => 'Monetization is only allowed for Vacation Leave or Sick Leave.',
                'errors' => ['leave_type_id' => ['Monetization is only allowed for Vacation Leave or Sick Leave.']],
            ], 422);
        }

        if (!$leaveType->allowsEmploymentStatus($this->resolveAdminEmployee($admin)?->status)) {
            return response()->json([
                'message' => "{$leaveType->name} is not available for your employment status.",
                'errors' => [
                    'leave_type_id' => ["{$leaveType->name} is not available for your employment status."],
                ],
            ], 422);
        }

        $balance = $this->findAdminEmployeeBalanceByLeaveType($admin, (int) $validated['leave_type_id']);

        $currentBalance = $balance ? (float) $balance->balance : 0;

        if ($currentBalance < 10) {
            return response()->json([
                'message' => 'Minimum of 10 leave credits required for monetization.',
                'errors' => ['total_days' => ['Minimum of 10 leave credits required for monetization. Current balance: ' . self::formatDays($currentBalance) . '.']],
            ], 422);
        }

        $requestedDays = (float) $validated['total_days'];
        if ($requestedDays > $currentBalance) {
            return response()->json([
                'message' => 'Requested monetization days exceed available leave credits.',
                'errors' => ['total_days' => ["Requested monetization days ({$requestedDays}) exceed available balance (" . self::formatDays($currentBalance) . ")."]],
            ], 422);
        }

        $equivalentAmount = null;
        $salary = $request->input('salary');
        if (!is_numeric($salary) || (float) $salary <= 0) {
            $salary = $this->resolveAdminEmployeeSalary($admin);
        }
        if (is_numeric($salary) && (float) $salary > 0) {
            $dailyRate = (float) $salary / 22;
            $equivalentAmount = round($requestedDays * $dailyRate, 2);
        }

        $adminEmployeeControlNo = $this->resolveAdminEmployeeControlNo($admin);

        $application = DB::transaction(function () use ($validated, $admin, $equivalentAmount, $adminEmployeeControlNo) {
            $app = LeaveApplication::create([
                'applicant_admin_id' => $admin->id,
                'employee_control_no' => $this->canonicalizeControlNo($adminEmployeeControlNo),
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => null,
                'end_date' => null,
                'total_days' => $validated['total_days'],
                'reason' => $validated['reason'] ?? null,
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'admin_approved_at' => now(),
                'is_monetization' => true,
                'pay_mode' => LeaveApplication::PAY_MODE_WITH_PAY,
                'equivalent_amount' => $equivalentAmount,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'created_at' => now(),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_APPROVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => 'Personal monetization request. Auto-approved at department level.',
                'created_at' => now(),
            ]);

            return $app;
        });

        $application->load('leaveType');
        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_REQUEST,
                'Monetization Request',
                "{$admin->full_name} submitted a personal monetization request for {$application->leaveType->name} (" . self::formatDays($application->total_days) . ").",
                $application->id
            );
        }

        return response()->json([
            'message' => 'Monetization request submitted successfully.',
            'application' => $this->formatApplication($application->fresh(['leaveType', 'applicantAdmin'])),
            'equivalent_amount' => $equivalentAmount,
        ], 201);
    }

    /**
     * Format total days for display.
     */
    private static function formatDays($days): string
    {
        $num = (float) $days;
        $display = ($num == (int) $num) ? (int) $num : $num;
        return $display . ' ' . ($num == 1 ? 'day' : 'days');
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    private function buildDashboardTrendAnalytics(Collection $applications): array
    {
        $trendYear = (int) now()->year;
        $monthlyTrend = array_fill(0, 12, 0);
        $leaveTypeMonthlyTrend = [];

        foreach ($applications as $application) {
            if (!$application instanceof LeaveApplication) {
                continue;
            }

            $trendDate = $this->resolveDashboardTrendDate($application);
            if (!$trendDate || (int) $trendDate->year !== $trendYear) {
                continue;
            }

            $monthIndex = max(0, min(11, (int) $trendDate->month - 1));
            $monthlyTrend[$monthIndex]++;

            $leaveTypeName = $this->resolveDashboardTrendLeaveTypeName($application);
            if (!array_key_exists($leaveTypeName, $leaveTypeMonthlyTrend)) {
                $leaveTypeMonthlyTrend[$leaveTypeName] = array_fill(0, 12, 0);
            }

            $leaveTypeMonthlyTrend[$leaveTypeName][$monthIndex]++;
        }

        if ($leaveTypeMonthlyTrend !== []) {
            ksort($leaveTypeMonthlyTrend, SORT_NATURAL | SORT_FLAG_CASE);
        }

        return [
            'trend_year' => $trendYear,
            'monthly_leave_trend' => $monthlyTrend,
            'leave_type_monthly_trend' => $leaveTypeMonthlyTrend,
        ];
    }

    private function resolveDashboardTrendDate(LeaveApplication $application): ?CarbonImmutable
    {
        $candidates = [
            $application->getAttribute('date_filed'),
            $application->getAttribute('created_at'),
            $application->getAttribute('start_date'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            try {
                if ($candidate instanceof \DateTimeInterface) {
                    return CarbonImmutable::instance($candidate)->startOfDay();
                }

                return CarbonImmutable::parse((string) $candidate)->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function resolveDashboardTrendLeaveTypeName(LeaveApplication $application): string
    {
        $name = trim((string) ($application->leaveType?->name ?? ''));
        return $name !== '' ? $name : 'Unknown';
    }

    private function normalizePayMode(mixed $payMode, bool $isMonetization = false): string
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

    private function isSickLeaveType(?LeaveType $leaveType = null): bool
    {
        $name = trim((string) ($leaveType?->name ?? ''));
        return strcasecmp($name, 'Sick Leave') === 0;
    }

    private function resolveSickLeavePayModeFromFilingWindow(
        ?array $selectedDates,
        mixed $filedAt = null,
        ?string $absenceStartDate = null,
        ?string $absenceEndDate = null
    ): string {
        [$startDate, $lastAbsentDate] = $this->resolveSickLeaveAbsenceDateRange($selectedDates);
        if ($absenceStartDate !== null) {
            $startDate = $this->resolveIsoDate($absenceStartDate) ?? $startDate;
        }
        if ($absenceEndDate !== null) {
            $lastAbsentDate = $this->resolveIsoDate($absenceEndDate) ?? $lastAbsentDate;
        }
        if (!$startDate || !$lastAbsentDate) {
            return LeaveApplication::PAY_MODE_WITH_PAY;
        }

        $filedDate = $this->resolvePolicyFilingDate($filedAt);
        if ($filedDate->lt($startDate)) {
            return LeaveApplication::PAY_MODE_WITH_PAY;
        }

        $workingDaysElapsed = $this->countWorkingDaysFromNextDay($lastAbsentDate, $filedDate);
        return $workingDaysElapsed <= 5
            ? LeaveApplication::PAY_MODE_WITH_PAY
            : LeaveApplication::PAY_MODE_WITHOUT_PAY;
    }

    private function resolveSickLeaveAbsenceDateRange(?array $selectedDates): array
    {
        if (!is_array($selectedDates) || $selectedDates === []) {
            return [null, null];
        }

        $earliest = null;
        $latest = null;

        foreach ($selectedDates as $rawDate) {
            $dateKey = trim((string) $rawDate);
            if ($dateKey === '') {
                continue;
            }

            try {
                $parsed = CarbonImmutable::parse($dateKey)->startOfDay();
            } catch (\Throwable) {
                continue;
            }

            if ($earliest === null || $parsed->lt($earliest)) {
                $earliest = $parsed;
            }

            if ($latest === null || $parsed->gt($latest)) {
                $latest = $parsed;
            }
        }

        return [$earliest, $latest];
    }

    private function resolveIsoDate(?string $rawDate): ?CarbonImmutable
    {
        $raw = trim((string) ($rawDate ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePolicyFilingDate(mixed $filedAt = null): CarbonImmutable
    {
        if ($filedAt instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($filedAt)->startOfDay();
        }

        $raw = trim((string) ($filedAt ?? ''));
        if ($raw !== '') {
            try {
                return CarbonImmutable::parse($raw)->startOfDay();
            } catch (\Throwable) {
                // Fall back to now() when parsing fails.
            }
        }

        return CarbonImmutable::now()->startOfDay();
    }

    private function countWorkingDaysFromNextDay(
        CarbonImmutable $lastAbsentDate,
        CarbonImmutable $filedDate
    ): int {
        if ($filedDate->lte($lastAbsentDate)) {
            return 0;
        }

        $count = 0;
        $cursor = $lastAbsentDate->addDay()->startOfDay();
        $end = $filedDate->startOfDay();

        while ($cursor->lte($end)) {
            if ($cursor->isWeekday()) {
                $count++;
            }

            $cursor = $cursor->addDay();
        }

        return $count;
    }

    private function resolveAttachmentStateFromRequest(Request $request, array $validated): array
    {
        $submitted = false;
        $reference = null;

        $booleanKeys = [
            'attachment_submitted',
            'attachment_attached',
            'has_attachment',
            'with_attachment',
        ];
        foreach ($booleanKeys as $key) {
            if (!array_key_exists($key, $validated) && $request->input($key) === null) {
                continue;
            }

            $flag = filter_var(
                array_key_exists($key, $validated) ? $validated[$key] : $request->input($key),
                FILTER_VALIDATE_BOOLEAN,
                FILTER_NULL_ON_FAILURE
            );

            if ($flag !== null) {
                $submitted = $flag;
            }
        }

        $candidateReference = trim((string) ($validated['attachment_reference'] ?? $request->input('attachment_reference') ?? ''));
        if ($candidateReference !== '') {
            $reference = $candidateReference;
            $submitted = true;
        }

        if ($request->hasFile('attachment')) {
            $uploadedFile = $request->file('attachment');
            if ($uploadedFile && $uploadedFile->isValid()) {
                $reference = $uploadedFile->store('leave-attachments');
                $submitted = true;
            }
        }

        if (!$submitted) {
            $reference = null;
        }

        return [
            'attachment_submitted' => $submitted,
            'attachment_reference' => $reference,
        ];
    }

    private function emptyEmploymentBreakdown(): array
    {
        return [
            'elective' => 0,
            'co_terminous' => 0,
            'regular' => 0,
            'casual' => 0,
        ];
    }

    private function buildEmploymentStatusBreakdown(Collection $applications, array $employeeStatusByControlNo): array
    {
        $breakdown = $this->emptyEmploymentBreakdown();

        foreach ($applications as $application) {
            if (!$application instanceof LeaveApplication) {
                continue;
            }

            $bucket = $this->employmentStatusToBucket($application, $employeeStatusByControlNo);
            if ($bucket !== null) {
                $breakdown[$bucket]++;
            }
        }

        return $breakdown;
    }

    private function employmentStatusToBucket(LeaveApplication $application, array $employeeStatusByControlNo): ?string
    {
        $employeeStatus = $application->employee?->status;

        if ($employeeStatus === null) {
            $rawControlNo = $application->employee_control_no;
            $normalizedControlNo = $this->normalizeControlNo($rawControlNo);
            $employeeStatus = $employeeStatusByControlNo[$normalizedControlNo] ?? null;
        }

        $rawStatus = strtoupper(trim((string) ($employeeStatus ?? '')));
        if ($rawStatus === '') {
            return null;
        }

        return match ($rawStatus) {
            'ELECTIVE' => 'elective',
            'CO-TERMINOUS', 'CO TERMINOUS', 'COTERMINOUS' => 'co_terminous',
            'REGULAR' => 'regular',
            'CASUAL' => 'casual',
            default => null,
        };
    }

    private function normalizeControlNo(mixed $controlNo): string
    {
        $normalized = ltrim(trim((string) ($controlNo ?? '')), '0');
        return $normalized === '' ? '0' : $normalized;
    }

    private function formatApplication(
        LeaveApplication $app,
        array $employeesByControlNo = [],
        array $actorDirectory = [],
        array $leaveBalanceDirectory = []
    ): array
    {
        $statusMap = [
            LeaveApplication::STATUS_PENDING_ADMIN => 'Pending Admin',
            LeaveApplication::STATUS_PENDING_HR => 'Pending HR',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
            LeaveApplication::STATUS_RECALLED => 'Recalled',
        ];

        $employee = $app->employee;
        if (!$employee) {
            $rawControlNo = $app->employee_control_no;
            $employee = $employeesByControlNo[$this->normalizeControlNo($rawControlNo)] ?? null;
        }

        // Determine employee name & office (could be admin self-apply)
        $employeeFullName = trim((string) ($app->employee_name ?? ''));
        if ($employeeFullName === '') {
            $employeeFullName = $employee ? $this->formatEmployeeFullName($employee) : '';
        }
        $employeeName = $employeeFullName !== ''
            ? $employeeFullName
            : ($app->applicantAdmin ? $app->applicantAdmin->full_name : 'Unknown');
        $office = $employee?->office ?? ($app->applicantAdmin?->department?->name ?? '');

        $position = $employee?->designation ?? '';
        $salary = $employee && $employee->rate_mon != null
            ? (float) $employee->rate_mon
            : null;

        $surname = $employee?->surname ?? '';
        $firstname = $employee?->firstname ?? '';
        $middlename = $employee?->middlename ?? '';
        $logs = $app->relationLoaded('logs')
            ? $app->logs->sortByDesc(fn(LeaveApplicationLog $log) => $log->created_at?->timestamp ?? 0)->values()
            : collect();

        $submittedLog = $logs->first(
            fn(LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_SUBMITTED
        );
        $adminApprovedLog = $logs->first(
            fn(LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_ADMIN_APPROVED
        );
        $hrApprovedLog = $logs->first(
            fn(LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_APPROVED
        );
        $hrRecalledLog = $logs->first(
            fn(LeaveApplicationLog $log) =>
                $log->action === LeaveApplicationLog::ACTION_HR_RECALLED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );
        $adminRejectedLog = $logs->first(
            fn(LeaveApplicationLog $log) =>
            $log->action === LeaveApplicationLog::ACTION_ADMIN_REJECTED
            && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_ADMIN
        );
        $hrRejectedLog = $logs->first(
            fn(LeaveApplicationLog $log) =>
            $log->action === LeaveApplicationLog::ACTION_HR_REJECTED
            && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );
        $cancelledLog = $logs->first(
            fn(LeaveApplicationLog $log) =>
            $this->isCancelledRemark($log->remarks)
            || (
                $log->action === LeaveApplicationLog::ACTION_ADMIN_REJECTED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_EMPLOYEE
            )
        );

        $filedBy = $this->resolvePerformerName($submittedLog, $actorDirectory, $employeeName) ?? $employeeName;
        $adminActionBy = ($app->admin_id && isset($actorDirectory['admin'][(int) $app->admin_id]))
            ? $actorDirectory['admin'][(int) $app->admin_id]
            : $this->resolvePerformerName($adminApprovedLog ?? $adminRejectedLog, $actorDirectory, $employeeName);
        $hrActionBy = $this->resolvePerformerName($hrApprovedLog ?? $hrRejectedLog, $actorDirectory, $employeeName);
        if ($hrActionBy === null && $app->status !== LeaveApplication::STATUS_RECALLED && $app->hr_id && isset($actorDirectory['hr'][(int) $app->hr_id])) {
            $hrActionBy = $actorDirectory['hr'][(int) $app->hr_id];
        }
        $recallActionBy = $this->resolvePerformerName($hrRecalledLog, $actorDirectory, $employeeName);
        if ($recallActionBy === null && $app->status === LeaveApplication::STATUS_RECALLED && $app->hr_id && isset($actorDirectory['hr'][(int) $app->hr_id])) {
            $recallActionBy = $actorDirectory['hr'][(int) $app->hr_id];
        }

        $isCancelled = $this->isCancelledRemark($app->remarks);
        $cancelledBy = $cancelledLog
            ? ($this->resolvePerformerName($cancelledLog, $actorDirectory, $employeeName) ?? $employeeName)
            : ($isCancelled ? $employeeName : null);

        $disapprovedBy = null;
        if ($app->status === LeaveApplication::STATUS_REJECTED) {
            if ($cancelledBy) {
                $disapprovedBy = $cancelledBy;
            } elseif ($hrRejectedLog || $app->hr_id) {
                $disapprovedBy = $this->resolvePerformerName($hrRejectedLog, $actorDirectory, $employeeName)
                    ?? $hrActionBy;
            } elseif ($adminRejectedLog || $app->admin_id) {
                $disapprovedBy = $this->resolvePerformerName($adminRejectedLog, $actorDirectory, $employeeName)
                    ?? $adminActionBy;
            }
        }

        $adminActionAt = $adminApprovedLog?->created_at
            ?? $adminRejectedLog?->created_at
            ?? $app->admin_approved_at;
        $hrActionAt = $hrApprovedLog?->created_at
            ?? $hrRejectedLog?->created_at
            ?? $app->hr_approved_at;
        $recallActionAt = $hrRecalledLog?->created_at
            ?? ($app->status === LeaveApplication::STATUS_RECALLED ? $app->updated_at : null);
        $cancelledAt = $cancelledLog?->created_at;

        $disapprovedAt = null;
        if ($app->status === LeaveApplication::STATUS_REJECTED) {
            if ($cancelledAt) {
                $disapprovedAt = $cancelledAt;
            } elseif ($hrRejectedLog?->created_at) {
                $disapprovedAt = $hrRejectedLog->created_at;
            } elseif ($adminRejectedLog?->created_at) {
                $disapprovedAt = $adminRejectedLog->created_at;
            }
        }

        $processedBy = null;
        $reviewedAt = null;

        if ($isCancelled) {
            $processedBy = $cancelledBy;
            $reviewedAt = $cancelledAt;
        } elseif ($app->status === LeaveApplication::STATUS_PENDING_HR) {
            $processedBy = $adminActionBy;
            $reviewedAt = $adminActionAt;
        } elseif ($app->status === LeaveApplication::STATUS_APPROVED) {
            $processedBy = $hrActionBy ?? $adminActionBy;
            $reviewedAt = $hrActionAt ?? $adminActionAt;
        } elseif ($app->status === LeaveApplication::STATUS_RECALLED) {
            $processedBy = $recallActionBy ?? $hrActionBy ?? $adminActionBy;
            $reviewedAt = $recallActionAt ?? $hrActionAt ?? $adminActionAt;
        } elseif ($app->status === LeaveApplication::STATUS_REJECTED) {
            $processedBy = $disapprovedBy;
            $reviewedAt = $disapprovedAt ?? $hrActionAt ?? $adminActionAt;
        }

        $currentLeaveBalances = $this->getCurrentLeaveBalancesForApp($app, $leaveBalanceDirectory);
        $durationDays = (float) $app->total_days;
        $selectedDates = $app->resolvedSelectedDates();
        $normalizedPayMode = $this->normalizePayMode($app->pay_mode ?? null, (bool) $app->is_monetization);
        $withoutPay = $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY;
        $selectedDatePayStatus = is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null;
        $selectedDateCoverage = is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null;
        $deductibleDays = $app->deductible_days !== null
            ? round((float) $app->deductible_days, 2)
            : ($withoutPay ? 0.0 : round(max($durationDays, 0.0), 2));
        if ($durationDays > 0 && $deductibleDays > $durationDays) {
            $deductibleDays = $durationDays;
        }
        $deductibleDays = round(max($deductibleDays, 0.0), 2);
        $withoutPayDays = round(max($durationDays - $deductibleDays, 0.0), 2);

        return [
            'id' => $app->id,
            'employee_control_no' => $app->employee_control_no,
            'employeeName' => $employeeName,
            'office' => $office,
            'position' => $position,
            'salary' => $salary,
            'surname' => $surname,
            'firstname' => $firstname,
            'middlename' => $middlename,
            'leaveType' => $app->leaveType?->name ?? 'Unknown',
            'startDate' => $app->start_date ? \Carbon\Carbon::parse($app->start_date)->toDateString() : null,
            'endDate' => $app->end_date ? \Carbon\Carbon::parse($app->end_date)->toDateString() : null,
            'days' => $durationDays,
            'duration_value' => $durationDays,
            'duration_unit' => 'day',
            'duration_label' => self::formatDays($durationDays),
            'reason' => $app->reason,
            'status' => $statusMap[$app->status] ?? $app->status,
            'rawStatus' => $app->status,
            'dateFiled' => $app->created_at ? $app->created_at->toDateString() : '',
            'filedAt' => $app->created_at?->toIso8601String(),
            'filed_at' => $app->created_at?->toIso8601String(),
            'createdAt' => $app->created_at?->toIso8601String(),
            'created_at' => $app->created_at?->toIso8601String(),
            'remarks' => $app->remarks,
            'selected_dates' => $selectedDates,
            'selected_date_pay_status' => $selectedDatePayStatus,
            'selected_date_coverage' => $selectedDateCoverage,
            'commutation' => $app->commutation ?? 'Not Requested',
            'pay_mode' => $normalizedPayMode,
            'pay_status' => $withoutPay ? 'Without Pay' : 'With Pay',
            'without_pay' => $withoutPay,
            'with_pay' => !$withoutPay,
            'deductible_days' => $deductibleDays,
            'with_pay_days' => $deductibleDays,
            'without_pay_days' => $withoutPayDays,
            'attachment_required' => (bool) ($app->attachment_required ?? false),
            'attachment_submitted' => (bool) ($app->attachment_submitted ?? false),
            'attachment_reference' => $app->attachment_reference ? (string) $app->attachment_reference : null,
            'is_monetization' => (bool) $app->is_monetization,
            'equivalent_amount' => $app->equivalent_amount ? (float) $app->equivalent_amount : null,
            'admin_id' => $app->admin_id,
            'hr_id' => $app->hr_id,
            'filedBy' => $filedBy,
            'adminActionBy' => $adminActionBy,
            'hrActionBy' => $hrActionBy,
            'recallActionBy' => $recallActionBy,
            'recall_action_by' => $recallActionBy,
            'disapprovedBy' => $disapprovedBy,
            'cancelledBy' => $cancelledBy,
            'processedBy' => $processedBy,
            'reviewedAt' => $reviewedAt?->toIso8601String(),
            'adminActionAt' => $adminActionAt?->toIso8601String(),
            'hrActionAt' => $hrActionAt?->toIso8601String(),
            'recallActionAt' => $recallActionAt?->toIso8601String(),
            'recall_action_at' => $recallActionAt?->toIso8601String(),
            'disapprovedAt' => $disapprovedAt?->toIso8601String(),
            'cancelledAt' => $cancelledAt?->toIso8601String(),
            'leaveBalance' => $this->getBalanceForApp($app, $leaveBalanceDirectory),
            'leaveBalances' => $currentLeaveBalances,
            'employee_leave_balances' => $currentLeaveBalances,
            'certificationLeaveCredits' => $this->getCertificationLeaveCredits($app, $leaveBalanceDirectory),
        ];
    }

    private function formatEmployeeFullName(Employee $employee): string
    {
        return trim(implode(' ', array_filter([
            trim((string) ($employee->firstname ?? '')),
            trim((string) ($employee->middlename ?? '')),
            trim((string) ($employee->surname ?? '')),
        ], fn(string $part) => $part !== '')));
    }

    private function buildActorDirectory(Collection $applications, array $employeesByControlNo): array
    {
        $adminIds = [];
        $hrIds = [];

        foreach ($applications as $application) {
            if (!$application instanceof LeaveApplication) {
                continue;
            }

            if ($application->admin_id) {
                $adminIds[] = (int) $application->admin_id;
            }

            if ($application->hr_id) {
                $hrIds[] = (int) $application->hr_id;
            }

            if (!$application->relationLoaded('logs')) {
                continue;
            }

            foreach ($application->logs as $log) {
                if (!$log instanceof LeaveApplicationLog || !$log->performed_by_id) {
                    continue;
                }

                $performerType = strtoupper((string) $log->performed_by_type);
                if ($performerType === LeaveApplicationLog::PERFORMER_ADMIN) {
                    $adminIds[] = (int) $log->performed_by_id;
                } elseif ($performerType === LeaveApplicationLog::PERFORMER_HR) {
                    $hrIds[] = (int) $log->performed_by_id;
                }
            }
        }

        $adminIds = array_values(array_unique(array_filter($adminIds)));
        $hrIds = array_values(array_unique(array_filter($hrIds)));

        $adminNamesById = empty($adminIds)
            ? []
            : DepartmentAdmin::query()
                ->whereIn('id', $adminIds)
                ->pluck('full_name', 'id')
                ->map(fn($name) => trim((string) $name))
                ->all();

        $hrNamesById = empty($hrIds)
            ? []
            : HRAccount::query()
                ->whereIn('id', $hrIds)
                ->pluck('full_name', 'id')
                ->map(fn($name) => trim((string) $name))
                ->all();

        $employeeNamesByControlNo = collect($employeesByControlNo)
            ->mapWithKeys(function (Employee $employee, string $normalizedControlNo) {
                $name = $this->formatEmployeeFullName($employee);
                return [$normalizedControlNo => $name !== '' ? $name : null];
            })
            ->all();

        return [
            'admin' => $adminNamesById,
            'hr' => $hrNamesById,
            'employee' => $employeeNamesByControlNo,
        ];
    }

    private function buildLeaveBalanceDirectory(Collection $applications, array $employeesByControlNo): array
    {
        $employeeControlNos = collect($employeesByControlNo)
            ->map(fn(Employee $employee) => trim((string) $employee->control_no))
            ->filter(fn(string $controlNo) => $controlNo !== '')
            ->unique()
            ->values();

        $employeeBalances = $employeeControlNos->isEmpty()
            ? []
            : LeaveBalance::query()
                ->with('leaveType:id,name')
                ->whereIn('employee_control_no', $employeeControlNos->all())
                ->get()
                ->groupBy(fn(LeaveBalance $balance) => $this->normalizeControlNo($balance->employee_control_no))
                ->map(fn(Collection $balances) => $this->formatLeaveBalanceSnapshot($balances))
                ->all();

        $adminIds = $applications
            ->pluck('applicant_admin_id')
            ->filter()
            ->map(fn(mixed $id) => (int) $id)
            ->unique()
            ->values();

        $adminBalances = [];
        if (!$adminIds->isEmpty()) {
            $admins = DepartmentAdmin::query()
                ->whereIn('id', $adminIds->all())
                ->get(['id', 'employee_control_no']);

            $adminEmployeeControlNoById = [];
            $adminEmployeeControlNoCandidates = [];

            foreach ($admins as $admin) {
                $employeeControlNo = $this->resolveAdminEmployeeControlNo($admin);
                if ($employeeControlNo === null) {
                    continue;
                }

                $adminEmployeeControlNoById[(int) $admin->id] = $employeeControlNo;
                $adminEmployeeControlNoCandidates = array_merge(
                    $adminEmployeeControlNoCandidates,
                    $this->buildControlNoCandidates($employeeControlNo)
                );
            }

            $adminEmployeeControlNoCandidates = array_values(array_unique($adminEmployeeControlNoCandidates));

            $adminEmployeeBalancesByControlNo = empty($adminEmployeeControlNoCandidates)
                ? []
                : LeaveBalance::query()
                    ->with('leaveType:id,name')
                    ->whereIn('employee_control_no', $adminEmployeeControlNoCandidates)
                    ->get()
                    ->groupBy(fn(LeaveBalance $balance) => $this->normalizeControlNo($balance->employee_control_no))
                    ->all();

            foreach ($adminEmployeeControlNoById as $adminId => $employeeControlNo) {
                $adminBalances[$adminId] = $this->formatLeaveBalanceSnapshot(
                    collect($adminEmployeeBalancesByControlNo[$this->normalizeControlNo($employeeControlNo)] ?? [])
                );
            }
        }

        return [
            'employee' => $employeeBalances,
            'admin' => $adminBalances,
        ];
    }

    private function formatLeaveBalanceSnapshot(Collection $balances): array
    {
        return $balances
            ->sortByDesc(fn($balance) => $balance->updated_at?->timestamp ?? 0)
            ->unique(fn($balance) => (int) $balance->leave_type_id)
            ->sortBy(fn($balance) => strtolower(trim((string) ($balance->leaveType?->name ?? ''))))
            ->values()
            ->map(fn($balance) => [
                'leave_type_id' => (int) $balance->leave_type_id,
                'leave_type_name' => $balance->leaveType?->name ?? 'Unknown',
                'balance' => (float) $balance->balance,
                'year' => $balance->year !== null ? (int) $balance->year : null,
                'updated_at' => $balance->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    private function resolvePerformerName(
        ?LeaveApplicationLog $log,
        array $actorDirectory,
        string $fallbackEmployeeName = ''
    ): ?string
    {
        if (!$log) {
            return null;
        }

        $performerType = strtoupper((string) $log->performed_by_type);
        $performerId = (int) $log->performed_by_id;
        if ($performerId <= 0) {
            return null;
        }

        if ($performerType === LeaveApplicationLog::PERFORMER_ADMIN) {
            return $actorDirectory['admin'][$performerId] ?? null;
        }

        if ($performerType === LeaveApplicationLog::PERFORMER_HR) {
            return $actorDirectory['hr'][$performerId] ?? null;
        }

        if ($performerType === LeaveApplicationLog::PERFORMER_EMPLOYEE) {
            $controlNo = $this->normalizeControlNo($performerId);
            return $actorDirectory['employee'][$controlNo] ?? ($fallbackEmployeeName !== '' ? $fallbackEmployeeName : null);
        }

        return null;
    }

    private function isCancelledRemark(mixed $remarks): bool
    {
        return (bool) preg_match('/^cancelled\b/i', trim((string) ($remarks ?? '')));
    }

    /**
     * Return Vacation and Sick leave credits for 7.A CERTIFICATION OF LEAVE CREDITS.
     * Keys: vacation, sick. Each has total_earned, less_this_application, balance (numbers).
     */
    private function getCertificationLeaveCredits(LeaveApplication $app, array $leaveBalanceDirectory = []): array
    {
        $currentLeaveBalances = $this->getCurrentLeaveBalancesForApp($app, $leaveBalanceDirectory);
        $vacBalance = $this->findLeaveBalanceByName($currentLeaveBalances, 'Vacation Leave');
        $sickBalance = $this->findLeaveBalanceByName($currentLeaveBalances, 'Sick Leave');

        $days = (float) $app->total_days;
        $leaveTypeName = trim((string) ($app->leaveType?->name ?? ''));
        $vacLess = strcasecmp($leaveTypeName, 'Vacation Leave') === 0 ? $days : 0.0;
        $sickLess = strcasecmp($leaveTypeName, 'Sick Leave') === 0 ? $days : 0.0;

        return [
            'vacation' => [
                'total_earned' => $vacBalance,
                'less_this_application' => $vacLess,
                'balance' => $vacBalance,
                'balance_after_application' => max($vacBalance - $vacLess, 0.0),
            ],
            'sick' => [
                'total_earned' => $sickBalance,
                'less_this_application' => $sickLess,
                'balance' => $sickBalance,
                'balance_after_application' => max($sickBalance - $sickLess, 0.0),
            ],
            'as_of_date' => $app->created_at?->format('F j, Y') ?? now()->format('F j, Y'),
        ];
    }

    private function getBalanceForApp(LeaveApplication $app, array $leaveBalanceDirectory = []): ?float
    {
        $balance = $this->findLeaveBalanceEntryForApp($app, $leaveBalanceDirectory);
        return $balance !== null ? (float) ($balance['balance'] ?? 0.0) : null;
    }

    private function getCurrentLeaveBalancesForApp(LeaveApplication $app, array $leaveBalanceDirectory = []): array
    {
        if ($app->employee_control_no) {
            $employeeKey = $this->normalizeControlNo($app->employee_control_no);
            return $leaveBalanceDirectory['employee'][$employeeKey] ?? [];
        }

        if ($app->applicant_admin_id) {
            return $leaveBalanceDirectory['admin'][(int) $app->applicant_admin_id] ?? [];
        }

        return [];
    }

    private function findLeaveBalanceEntryForApp(LeaveApplication $app, array $leaveBalanceDirectory = []): ?array
    {
        foreach ($this->getCurrentLeaveBalancesForApp($app, $leaveBalanceDirectory) as $balance) {
            if ((int) ($balance['leave_type_id'] ?? 0) === (int) $app->leave_type_id) {
                return $balance;
            }
        }

        return null;
    }

    private function findLeaveBalanceByName(array $balances, string $leaveTypeName): float
    {
        foreach ($balances as $balance) {
            if (strcasecmp((string) ($balance['leave_type_name'] ?? ''), $leaveTypeName) === 0) {
                return (float) ($balance['balance'] ?? 0.0);
            }
        }

        return 0.0;
    }

    private function resolveLeaveInitializedState(DepartmentAdmin $admin): bool
    {
        return $this->queryAdminEmployeeBalances($admin)->exists();
    }

    private function queryAdminEmployeeBalances(DepartmentAdmin $admin)
    {
        $employeeControlNo = $this->resolveAdminEmployeeControlNo($admin);
        $candidateEmployeeControlNos = $this->buildControlNoCandidates($employeeControlNo);

        $query = LeaveBalance::query();
        if ($candidateEmployeeControlNos === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('employee_control_no', $candidateEmployeeControlNos);
    }

    private function findAdminEmployeeBalanceByLeaveType(DepartmentAdmin $admin, int $leaveTypeId): ?LeaveBalance
    {
        return $this->queryAdminEmployeeBalances($admin)
            ->where('leave_type_id', $leaveTypeId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    private function resolveAdminEmployeeControlNo(DepartmentAdmin $admin): ?string
    {
        $rawControlNo = trim((string) $admin->employee_control_no);
        if ($rawControlNo === '') {
            return null;
        }

        $employee = Employee::findByControlNo($rawControlNo);
        if ($employee) {
            return trim((string) $employee->control_no);
        }

        return $rawControlNo;
    }

    private function resolveAdminEmployee(DepartmentAdmin $admin): ?Employee
    {
        $employeeControlNo = $this->resolveAdminEmployeeControlNo($admin);
        if ($employeeControlNo === null) {
            return null;
        }

        return Employee::query()
            ->matchingControlNo($employeeControlNo)
            ->first();
    }

    /**
     * @param iterable<int,LeaveType> $leaveTypes
     */
    private function filterLeaveTypesForEmploymentStatus(iterable $leaveTypes, mixed $employmentStatus): Collection
    {
        return collect($leaveTypes)
            ->filter(fn(LeaveType $leaveType): bool => $leaveType->allowsEmploymentStatus($employmentStatus))
            ->values();
    }

    private function resolveAdminEmployeeSalary(DepartmentAdmin $admin): ?float
    {
        $employee = $this->resolveAdminEmployee($admin);
        if (!$employee || $employee->rate_mon === null) {
            return null;
        }

        return round((float) $employee->rate_mon, 2);
    }

    private function buildControlNoCandidates(?string $controlNo): array
    {
        $controlNo = trim((string) $controlNo);
        if ($controlNo === '') {
            return [];
        }

        $normalized = ltrim($controlNo, '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        return array_values(array_unique(array_filter([
            $controlNo,
            $normalized,
        ], fn(string $value): bool => $value !== '')));
    }

    private function canonicalizeControlNo(?string $controlNo): ?string
    {
        $controlNo = trim((string) $controlNo);
        if ($controlNo === '' || !preg_match('/^\d+$/', $controlNo)) {
            return null;
        }

        $employee = Employee::findByControlNo($controlNo);
        if ($employee) {
            return trim((string) $employee->control_no);
        }

        return $controlNo;
    }

    private function normalizeSelectedDatesInput(Request $request): void
    {
        $selectedDates = $request->input('selected_dates') ?? $request->input('selectedDates');
        if (is_string($selectedDates)) {
            $decoded = json_decode($selectedDates, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $selectedDates = $decoded;
            }
        }

        if ($selectedDates !== null && $selectedDates !== '') {
            $request->merge(['selected_dates' => $selectedDates]);
        }
    }

    private function normalizeSelectedDatePolicyInput(Request $request): void
    {
        $selectedDatePayStatus = $request->input('selected_date_pay_status');
        if ($selectedDatePayStatus === null || $selectedDatePayStatus === '') {
            $selectedDatePayStatus = $request->input('selected_date_pay_status_codes');
        }
        if ($selectedDatePayStatus === null || $selectedDatePayStatus === '') {
            $selectedDatePayStatus = $request->input('selected_date_pay_statuses');
        }
        if (is_string($selectedDatePayStatus)) {
            $decoded = json_decode($selectedDatePayStatus, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $selectedDatePayStatus = $decoded;
            }
        }
        $normalizedPayStatus = $this->normalizeSelectedDatePayStatusMap($selectedDatePayStatus);
        if ($normalizedPayStatus !== []) {
            $request->merge(['selected_date_pay_status' => $normalizedPayStatus]);
        }

        $selectedDateCoverage = $request->input('selected_date_coverage');
        if ($selectedDateCoverage === null || $selectedDateCoverage === '') {
            $selectedDateCoverage = $request->input('selected_date_coverages');
        }
        if (is_string($selectedDateCoverage)) {
            $decoded = json_decode($selectedDateCoverage, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $selectedDateCoverage = $decoded;
            }
        }

        if (($selectedDateCoverage === null || $selectedDateCoverage === '') && is_array($request->input('selected_date_durations'))) {
            $selectedDateCoverage = [];
            foreach ($request->input('selected_date_durations') as $date => $duration) {
                $selectedDateCoverage[$date] = strtolower(trim((string) $duration)) === 'half_day' ? 'half' : 'whole';
            }
        }

        $normalizedCoverage = $this->normalizeSelectedDateCoverageMap($selectedDateCoverage);
        if ($normalizedCoverage !== []) {
            $request->merge(['selected_date_coverage' => $normalizedCoverage]);
        }
    }

    private function normalizeSelectedDateMapKey(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeSelectedDatePayStatusValue(mixed $value): ?string
    {
        $token = strtoupper(str_replace([' ', '-'], '_', trim((string) $value)));

        return match ($token) {
            LeaveApplication::PAY_MODE_WITH_PAY,
            'WITH_PAY',
            'WITHPAY' => LeaveApplication::PAY_MODE_WITH_PAY,
            LeaveApplication::PAY_MODE_WITHOUT_PAY,
            'WITHOUT_PAY',
            'WITHOUTPAY' => LeaveApplication::PAY_MODE_WITHOUT_PAY,
            default => null,
        };
    }

    private function normalizeSelectedDateCoverageValue(mixed $value): ?string
    {
        $token = strtolower(str_replace([' ', '-'], '_', trim((string) $value)));

        return match ($token) {
            'whole',
            'whole_day',
            'wholeday' => 'whole',
            'half',
            'half_day',
            'halfday' => 'half',
            default => null,
        };
    }

    private function normalizeSelectedDatePayStatusMap(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $rawDate => $rawStatus) {
            $dateKey = $this->normalizeSelectedDateMapKey($rawDate);
            $status = $this->normalizeSelectedDatePayStatusValue($rawStatus);
            if ($dateKey === null || $status === null) {
                continue;
            }

            $normalized[$dateKey] = $status;
        }

        ksort($normalized);
        return $normalized;
    }

    private function normalizeSelectedDateCoverageMap(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $value = $decoded;
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $rawDate => $rawCoverage) {
            $dateKey = $this->normalizeSelectedDateMapKey($rawDate);
            $coverage = $this->normalizeSelectedDateCoverageValue($rawCoverage);
            if ($dateKey === null || $coverage === null) {
                continue;
            }

            $normalized[$dateKey] = $coverage;
        }

        ksort($normalized);
        return $normalized;
    }

    private function compactSelectedDatePayStatusMap(
        array $selectedDatePayStatus,
        ?array $selectedDates,
        string $fallbackPayMode = LeaveApplication::PAY_MODE_WITH_PAY
    ): array {
        if (!is_array($selectedDates) || $selectedDates === []) {
            return [];
        }

        $defaultStatus = $this->normalizePayMode($fallbackPayMode) === LeaveApplication::PAY_MODE_WITHOUT_PAY
            ? LeaveApplication::PAY_MODE_WITHOUT_PAY
            : LeaveApplication::PAY_MODE_WITH_PAY;

        $resolved = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeSelectedDateMapKey($rawDate);
            if ($dateKey === null) {
                continue;
            }

            $resolved[$dateKey] = $selectedDatePayStatus[$dateKey] ?? $defaultStatus;
        }

        ksort($resolved);
        return $resolved;
    }

    private function compactSelectedDateCoverageMap(array $selectedDateCoverage, ?array $selectedDates): array
    {
        if (!is_array($selectedDates) || $selectedDates === []) {
            return [];
        }

        $resolved = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeSelectedDateMapKey($rawDate);
            if ($dateKey === null) {
                continue;
            }

            $resolved[$dateKey] = $selectedDateCoverage[$dateKey] ?? 'whole';
        }

        ksort($resolved);
        return $resolved;
    }

    private function resolveCoverageDurationDays(mixed $coverage): float
    {
        return $this->normalizeSelectedDateCoverageValue($coverage) === 'half' ? 0.5 : 1.0;
    }

    private function resolveRequestedDeductibleDays(
        ?array $selectedDates,
        array $selectedDateCoverage,
        array $selectedDatePayStatus,
        string $requestedPayMode,
        float $requestedTotalDays
    ): float {
        if ($this->normalizePayMode($requestedPayMode) === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
            return 0.0;
        }

        if (!is_array($selectedDates) || $selectedDates === []) {
            return round(max($requestedTotalDays, 0.0), 2);
        }

        $deductible = 0.0;
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeSelectedDateMapKey($rawDate);
            if ($dateKey === null) {
                continue;
            }

            $payStatus = $selectedDatePayStatus[$dateKey] ?? LeaveApplication::PAY_MODE_WITH_PAY;
            if ($payStatus === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                continue;
            }

            $deductible += $this->resolveCoverageDurationDays($selectedDateCoverage[$dateKey] ?? 'whole');
        }

        $deductible = round(max($deductible, 0.0), 2);
        if ($requestedTotalDays > 0 && $deductible > $requestedTotalDays) {
            $deductible = $requestedTotalDays;
        }

        return $deductible;
    }

    private function applyUniformSelectedDatePayStatus(?array $selectedDates, string $payMode): array
    {
        if (!is_array($selectedDates) || $selectedDates === []) {
            return [];
        }

        $normalizedPayMode = $this->normalizePayMode($payMode);
        $resolved = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeSelectedDateMapKey($rawDate);
            if ($dateKey === null) {
                continue;
            }

            $resolved[$dateKey] = $normalizedPayMode;
        }

        ksort($resolved);
        return $resolved;
    }

    private function rebalanceSelectedDatePayStatusToDeductibleDays(
        array $selectedDates,
        array $selectedDateCoverage,
        array $selectedDatePayStatus,
        float $targetDeductibleDays
    ): array {
        if ($selectedDates === []) {
            return [];
        }

        $remainingDeductible = round(max($targetDeductibleDays, 0.0), 2);
        $adjusted = [];

        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeSelectedDateMapKey($rawDate);
            if ($dateKey === null) {
                continue;
            }

            $currentStatus = $selectedDatePayStatus[$dateKey] ?? LeaveApplication::PAY_MODE_WITH_PAY;
            if ($currentStatus === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                $adjusted[$dateKey] = LeaveApplication::PAY_MODE_WITHOUT_PAY;
                continue;
            }

            $durationDays = $this->resolveCoverageDurationDays($selectedDateCoverage[$dateKey] ?? 'whole');
            if ($remainingDeductible + 1e-9 >= $durationDays) {
                $adjusted[$dateKey] = LeaveApplication::PAY_MODE_WITH_PAY;
                $remainingDeductible = round(max($remainingDeductible - $durationDays, 0.0), 2);
            } else {
                $adjusted[$dateKey] = LeaveApplication::PAY_MODE_WITHOUT_PAY;
            }
        }

        ksort($adjusted);
        return $adjusted;
    }

    private function resolvePayModeFromSelectedDatePayStatus(
        ?array $selectedDates,
        array $selectedDatePayStatus,
        float $deductibleDays
    ): string {
        if ($deductibleDays <= 0) {
            return LeaveApplication::PAY_MODE_WITHOUT_PAY;
        }

        if (!is_array($selectedDates) || $selectedDates === []) {
            return LeaveApplication::PAY_MODE_WITH_PAY;
        }

        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeSelectedDateMapKey($rawDate);
            if ($dateKey === null) {
                continue;
            }

            if (($selectedDatePayStatus[$dateKey] ?? LeaveApplication::PAY_MODE_WITH_PAY) !== LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                return LeaveApplication::PAY_MODE_WITH_PAY;
            }
        }

        return LeaveApplication::PAY_MODE_WITHOUT_PAY;
    }

    private function validateNoDuplicateLeaveDates(
        string $employeeControlNo,
        string $startDate,
        string $endDate,
        ?array $selectedDates = null,
        mixed $totalDays = null
    ): ?JsonResponse {
        $requestedDates = LeaveApplication::resolveDateSet($startDate, $endDate, $selectedDates, $totalDays);
        if ($requestedDates === []) {
            return null;
        }

        $existingApplications = LeaveApplication::query()
            ->select(['id', 'start_date', 'end_date', 'selected_dates', 'total_days'])
            ->whereIn('status', [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
                LeaveApplication::STATUS_APPROVED,
            ])
            ->whereIn('employee_control_no', $this->buildControlNoCandidates($employeeControlNo))
            ->get();

        $duplicateDateMap = [];
        foreach ($existingApplications as $existingApplication) {
            $existingDates = LeaveApplication::resolveDateSet(
                $existingApplication->start_date?->toDateString(),
                $existingApplication->end_date?->toDateString(),
                is_array($existingApplication->selected_dates) ? $existingApplication->selected_dates : null,
                $existingApplication->total_days
            );

            foreach (array_intersect($requestedDates, $existingDates) as $duplicateDate) {
                $duplicateDateMap[$duplicateDate] = true;
            }
        }

        if ($duplicateDateMap === []) {
            return null;
        }

        $duplicateDates = array_keys($duplicateDateMap);
        sort($duplicateDates);

        $previewDates = array_slice($duplicateDates, 0, 3);
        $formattedPreview = implode(', ', array_map(
            static fn(string $date): string => CarbonImmutable::parse($date)->format('M j, Y'),
            $previewDates
        ));
        if (count($duplicateDates) > count($previewDates)) {
            $remainingCount = count($duplicateDates) - count($previewDates);
            $formattedPreview .= " and {$remainingCount} more";
        }

        return response()->json([
            'message' => "Duplicate leave date detected. You already have a leave application for {$formattedPreview}.",
            'errors' => [
                'selected_dates' => ['Duplicate leave dates are not allowed for the same employee.'],
            ],
            'duplicate_dates' => $duplicateDates,
        ], 422);
    }
}
