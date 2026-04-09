<?php

namespace App\Http\Controllers;

use App\Models\COCApplication;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveApplicationUpdateRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Services\WorkScheduleService;

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

    private function workScheduleService(): WorkScheduleService
    {
        return app(WorkScheduleService::class);
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
        $departmentEmployeeControlNos = $this->departmentEmployeeControlNoCandidates($deptName);

        $applications = LeaveApplication::with(['leaveType', 'applicantAdmin', 'logs', 'updateRequests'])
            ->where(function ($query) use ($departmentEmployeeControlNos, $admin) {
                // Include employee leaves for this department (matched by office)
                $query->when(
                    $departmentEmployeeControlNos !== [],
                    fn($nestedQuery) => $nestedQuery->whereIn('employee_control_no', $departmentEmployeeControlNos),
                    fn($nestedQuery) => $nestedQuery->whereRaw('1 = 0')
                )
                    // OR admin self-apply leaves for this department
                    ->orWhereHas('applicantAdmin', fn($q) => $q->where('department_id', $admin->department_id));
            })
            ->orderByDesc('created_at')
            ->get();

        $cocApplications = COCApplication::query()
            ->with(['rows', 'reviewedByAdmin', 'reviewedByHr', 'ctoLeaveType'])
            ->when(
                $departmentEmployeeControlNos !== [],
                fn($query) => $query->whereIn('employee_control_no', $departmentEmployeeControlNos),
                fn($query) => $query->whereRaw('1 = 0')
            )
            ->orderByDesc('created_at')
            ->get();

        $pendingApps = $applications->where('status', LeaveApplication::STATUS_PENDING_ADMIN);
        $pendingCocApps = $cocApplications->filter(
            fn(COCApplication $app): bool => $this->deriveCocRawStatus($app) === 'PENDING_ADMIN'
        );
        $pending = $pendingApps->count() + $pendingCocApps->count();

        $approvedTodayApps = $applications->filter(function (LeaveApplication $app): bool {
            return in_array($app->status, [
                LeaveApplication::STATUS_PENDING_HR,
                LeaveApplication::STATUS_APPROVED,
            ], true) && (bool) $app->admin_approved_at?->isToday();
        });
        $approvedTodayCocApps = $cocApplications->filter(function (COCApplication $app): bool {
            return in_array($this->deriveCocRawStatus($app), [
                'PENDING_HR',
                COCApplication::STATUS_APPROVED,
            ], true) && (bool) $app->admin_reviewed_at?->isToday();
        });
        $approvedToday = $approvedTodayApps->count() + $approvedTodayCocApps->count();

        $totalApprovedApps = $applications->whereIn('status', [
            LeaveApplication::STATUS_PENDING_HR,
            LeaveApplication::STATUS_APPROVED,
        ]);
        $totalApprovedCocApps = $cocApplications->filter(
            fn(COCApplication $app): bool => in_array(
                $this->deriveCocRawStatus($app),
                ['PENDING_HR', COCApplication::STATUS_APPROVED],
                true
            )
        );
        $totalApproved = $totalApprovedApps->count() + $totalApprovedCocApps->count();

        $employeesByControlNo = $this->loadDepartmentEmployeesByControlNo($deptName);

        $employeeStatusByControlNo = collect($employeesByControlNo)
            ->map(fn(object $employee) => $employee->status ?? null)
            ->all();

        $actorDirectory = $this->buildActorDirectory($applications, $employeesByControlNo);
        $leaveBalanceDirectory = $this->buildLeaveBalanceDirectory($applications, $employeesByControlNo);
        $formatted = $applications->map(
            fn($app) => $this->formatApplication($app, $employeesByControlNo, $actorDirectory, $leaveBalanceDirectory)
        );
        $kpiBreakdown = [
            'pending' => $this->buildEmploymentStatusBreakdown($pendingApps->concat($pendingCocApps), $employeeStatusByControlNo),
            'approved_today' => $this->buildEmploymentStatusBreakdown($approvedTodayApps->concat($approvedTodayCocApps), $employeeStatusByControlNo),
            'total_approved' => $this->buildEmploymentStatusBreakdown($totalApprovedApps->concat($totalApprovedCocApps), $employeeStatusByControlNo),
            'total' => $this->buildEmploymentStatusBreakdown($applications->concat($cocApplications), $employeeStatusByControlNo),
        ];
        $analytics = $this->buildDashboardTrendAnalytics($applications);

        return response()->json([
            'pending_count' => $pending,
            'approved_today' => $approvedToday,
            'total_approved' => $totalApproved,
            'total_count' => $applications->count() + $cocApplications->count(),
            'kpi_breakdown' => $kpiBreakdown,
            'analytics' => $analytics,
            'applications' => $formatted,
        ]);
    }

    private function deriveCocRawStatus(COCApplication $app): string
    {
        if ($app->status !== COCApplication::STATUS_PENDING) {
            return (string) $app->status;
        }

        return $app->admin_reviewed_at ? 'PENDING_HR' : 'PENDING_ADMIN';
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

        $adminEmployee = $this->resolveAdminEmployee($admin);
        if (!$leaveType->allowsEmploymentStatus($adminEmployee?->status)) {
            return response()->json([
                'message' => "{$leaveType->name} is not available for your employment status.",
                'errors' => [
                    'leave_type_id' => ["{$leaveType->name} is not available for your employment status."],
                ],
            ], 422);
        }

        if ($adminEmployee) {
            $eventBasedReuseRestriction = $this->assertEmployeeCanReuseEventBasedLeaveType($adminEmployee, $leaveType);
            if ($eventBasedReuseRestriction instanceof JsonResponse) {
                return $eventBasedReuseRestriction;
            }
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
        $employee = HrisEmployee::findByControlNo($employeeControlNo);
        if (!$employee || !$this->sameOffice($employee->office ?? null, $admin->department?->name)) {
            return response()->json(['message' => 'Employee not found in your department.'], 404);
        }

        $leaveType = LeaveType::find($leaveTypeId);
        if (!$leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        if (!$leaveType->allowsEmploymentStatus($employee->status)) {
            $employeeName = $this->formatEmployeeFullName($employee);
            return response()->json([
                'message' => "{$leaveType->name} is not available for {$employeeName}.",
                'errors' => [
                    'leave_type_id' => ["{$leaveType->name} is not available for {$employeeName}."],
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
            'details_of_leave' => ['nullable', 'string', 'max:2000'],
            'selected_dates' => ['nullable', 'array'],
            'selected_dates.*' => ['date'],
            'selected_date_pay_status' => ['nullable', 'array'],
            'selected_date_pay_status.*' => ['nullable', 'string', 'in:WP,WOP'],
            'selected_date_coverage' => ['nullable', 'array'],
            'selected_date_coverage.*' => ['nullable', 'string', 'in:whole,half'],
            'selected_date_half_day_portion' => ['nullable', 'array'],
            'selected_date_half_day_portion.*' => ['nullable', 'string', 'in:AM,PM'],
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
        $selectedDateCoverageMetadata = $this->synchronizeSelectedDateCoverageMetadata(
            $this->normalizeSelectedDateCoverageMap($validated['selected_date_coverage'] ?? null),
            $this->normalizeSelectedDateHalfDayPortionMap($validated['selected_date_half_day_portion'] ?? null),
            $resolvedSelectedDates
        );
        $selectedDateCoverage = $selectedDateCoverageMetadata['selectedDateCoverage'];
        $selectedDateHalfDayPortion = $selectedDateCoverageMetadata['selectedDateHalfDayPortion'];
        $adminEmployeeControlNo = $this->resolveAdminEmployeeControlNo($admin);
        $requestedDeductibleDays = $this->resolveRequestedDeductibleDays(
            $resolvedSelectedDates,
            $selectedDateCoverage,
            $selectedDatePayStatus,
            $requestedPayMode,
            $requestedTotalDays,
            $adminEmployeeControlNo
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

        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        $isForcedLeave = strcasecmp(trim((string) $leaveType->name), 'Mandatory / Forced Leave') === 0;
        if ($isForcedLeave) {
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

        $usesVacationLeaveTopUpForScheduleExcess = $this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
            $leaveType,
            (int) $leaveType->id,
            false,
            $vacationLeaveTypeId
        );
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
        $requiredPrimaryLeaveDays = $this->resolvePrimaryLeaveTrackedDeductionDays(
            $leaveType,
            (int) $leaveType->id,
            false,
            $requestedTotalDays,
            $deductibleDays,
            $vacationLeaveTypeId
        );
        $requiredVacationLeaveDays = $this->resolveVacationLeaveTopUpDays(
            $leaveType,
            (int) $leaveType->id,
            false,
            $requestedTotalDays,
            $deductibleDays,
            $vacationLeaveTypeId
        );
        $autoConvertedToWop = false;
        $autoWithoutPayDays = 0.0;

        // Check balance if credit-based
        if (
            $leaveType->is_credit_based
            && $requestedPayMode !== LeaveApplication::PAY_MODE_WITHOUT_PAY
        ) {
            $balance = $this->findAdminEmployeeBalanceByLeaveType($admin, (int) $leaveType->id);
            $currentBalance = $balance ? (float) $balance->balance : 0.0;

            if ($usesVacationLeaveTopUpForScheduleExcess) {
                if ($currentBalance + 1e-9 < $requiredPrimaryLeaveDays) {
                    $message = "Insufficient {$leaveType->name} balance. Available {$leaveType->name} is "
                        . self::formatDays($currentBalance)
                        . ', but '
                        . self::formatDays($requiredPrimaryLeaveDays)
                        . ' is required before Vacation Leave can cover the schedule-based excess deduction.';

                    return response()->json([
                        'message' => $message,
                        'errors' => [
                            'leave_type_id' => [$message],
                            'available_leave_balance' => [
                                'Available '
                                . $leaveType->name
                                . ' is '
                                . self::formatDays($currentBalance)
                                . ', but '
                                . self::formatDays($requiredPrimaryLeaveDays)
                                . ' is required.',
                            ],
                        ],
                        'available_balance' => $currentBalance,
                        'required_balance_days' => $requiredPrimaryLeaveDays,
                    ], 422);
                }

                if ($vacationLeaveTypeId !== null && $requiredVacationLeaveDays > 0.0) {
                    $vacationBalance = $this->findAdminEmployeeBalanceByLeaveType($admin, $vacationLeaveTypeId);
                    $availableVacationBalance = $vacationBalance ? (float) $vacationBalance->balance : 0.0;
                    if ($availableVacationBalance + 1e-9 < $requiredVacationLeaveDays) {
                        return response()->json([
                            'message' => 'Insufficient Vacation Leave balance to cover the schedule-based excess deduction for '
                                . $leaveType->name
                                . '.',
                            'errors' => [
                                'leave_type_id' => [
                                    $leaveType->name
                                    . ' needs Vacation Leave to cover the schedule-based excess deduction.',
                                ],
                                'vacation_leave_balance' => [
                                    'Available Vacation Leave is '
                                    . self::formatDays($availableVacationBalance)
                                    . ', but '
                                    . self::formatDays($requiredVacationLeaveDays)
                                    . ' is required.',
                                ],
                            ],
                            'available_vacation_leave_days' => $availableVacationBalance,
                            'required_vacation_leave_days' => $requiredVacationLeaveDays,
                        ], 422);
                    }
                }
            } elseif ($currentBalance + 1e-9 < $deductibleDays) {
                $targetDeductibleDays = round(min($deductibleDays, max($currentBalance, 0.0)), 2);
                $autoWithoutPayDays = round(max($deductibleDays - $targetDeductibleDays, 0.0), 2);
                $autoConvertedToWop = $autoWithoutPayDays > 0;
                $deductibleDays = $targetDeductibleDays;

                if (is_array($resolvedSelectedDates) && $resolvedSelectedDates !== []) {
                    $selectedDatePayStatus = $this->rebalanceSelectedDatePayStatusToDeductibleDays(
                        $resolvedSelectedDates,
                        $selectedDateCoverage,
                        $selectedDatePayStatus,
                        $deductibleDays,
                        $adminEmployeeControlNo
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
            $selectedDateHalfDayPortion,
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
                'details_of_leave' => $validated['details_of_leave'] ?? null,
                'selected_dates' => $resolvedSelectedDates,
                'selected_date_pay_status' => $selectedDatePayStatus !== [] ? $selectedDatePayStatus : null,
                'selected_date_coverage' => $selectedDateCoverage !== [] ? $selectedDateCoverage : null,
                'selected_date_half_day_portion' => $selectedDateHalfDayPortion !== [] ? $selectedDateHalfDayPortion : null,
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
            'total_days' => ['required', 'numeric', 'min:' . LeaveApplication::MONETIZATION_MINIMUM_REQUEST_DAYS, 'max:999'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'details_of_leave' => ['nullable', 'string', 'max:2000'],
            'salary' => ['nullable', 'numeric', 'min:0'],
        ]);

        $leaveType = LeaveType::find($validated['leave_type_id']);
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

        $requestedDays = (float) $validated['total_days'];
        $monetizationPolicyRestriction = $this->validateMonetizationPolicyRules($leaveType, $requestedDays);
        if ($monetizationPolicyRestriction instanceof JsonResponse) {
            return $monetizationPolicyRestriction;
        }

        $monetizationBalanceRestriction = $this->validateMonetizationBalanceRules($currentBalance, $requestedDays);
        if ($monetizationBalanceRestriction instanceof JsonResponse) {
            return $monetizationBalanceRestriction;
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
                'details_of_leave' => $validated['details_of_leave'] ?? null,
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'admin_approved_at' => now(),
                'is_monetization' => true,
                'commutation' => LeaveApplication::MONETIZATION_REQUIRED_COMMUTATION,
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

            if ($application->status !== LeaveApplication::STATUS_APPROVED) {
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

    private function isVacationLeaveType(?LeaveType $leaveType = null): bool
    {
        $name = trim((string) ($leaveType?->name ?? ''));
        return strcasecmp($name, 'Vacation Leave') === 0;
    }

    private function validateMonetizationPolicyRules(?LeaveType $leaveType, float $requestedDays): ?JsonResponse
    {
        if (!$leaveType instanceof LeaveType || !$this->isVacationLeaveType($leaveType)) {
            return response()->json([
                'message' => 'Monetization is only allowed for Vacation Leave.',
                'errors' => ['leave_type_id' => ['Monetization is only allowed for Vacation Leave.']],
            ], 422);
        }

        $normalizedRequestedDays = round(max($requestedDays, 0.0), 2);
        if ($normalizedRequestedDays + 1e-9 < LeaveApplication::MONETIZATION_MINIMUM_REQUEST_DAYS) {
            $minimumDays = self::formatDays(LeaveApplication::MONETIZATION_MINIMUM_REQUEST_DAYS);

            return response()->json([
                'message' => "A minimum of {$minimumDays} is required for monetization.",
                'errors' => ['total_days' => ["A minimum of {$minimumDays} is required for monetization."]],
            ], 422);
        }

        return null;
    }

    private function validateMonetizationBalanceRules(float $currentBalance, float $requestedDays): ?JsonResponse
    {
        $normalizedCurrentBalance = round(max($currentBalance, 0.0), 2);
        $normalizedRequestedDays = round(max($requestedDays, 0.0), 2);
        $requiredMinimumBalance = LeaveApplication::MONETIZATION_MINIMUM_VACATION_LEAVE_BALANCE_DAYS;

        if ($normalizedCurrentBalance + 1e-9 < $requiredMinimumBalance) {
            return response()->json([
                'message' => 'At least '
                    . self::formatDays($requiredMinimumBalance)
                    . ' of Vacation Leave credits are required to apply monetization.',
                'errors' => [
                    'total_days' => [
                        'At least '
                        . self::formatDays($requiredMinimumBalance)
                        . ' of Vacation Leave credits are required. Current balance: '
                        . self::formatDays($normalizedCurrentBalance)
                        . '.',
                    ],
                ],
            ], 422);
        }

        if ($normalizedRequestedDays > $normalizedCurrentBalance + 1e-9) {
            return response()->json([
                'message' => 'Requested monetization days exceed available Vacation Leave credits.',
                'errors' => [
                    'total_days' => [
                        'Requested monetization days ('
                        . self::formatDays($normalizedRequestedDays)
                        . ') exceed available Vacation Leave credits ('
                        . self::formatDays($normalizedCurrentBalance)
                        . ').',
                    ],
                ],
            ], 422);
        }

        return null;
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
            if (!is_object($application)) {
                continue;
            }

            $bucket = $this->employmentStatusToBucket($application, $employeeStatusByControlNo);
            if ($bucket !== null) {
                $breakdown[$bucket]++;
            }
        }

        return $breakdown;
    }

    private function employmentStatusToBucket(object $application, array $employeeStatusByControlNo): ?string
    {
        $rawControlNo = $application->employee_control_no ?? null;
        $normalizedControlNo = $this->normalizeControlNo($rawControlNo);
        $employeeStatus = $employeeStatusByControlNo[$normalizedControlNo] ?? null;

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

    /**
     * @return array<int, string>
     */
    private function departmentEmployeeControlNoCandidates(?string $departmentName): array
    {
        $departmentControlNos = HrisEmployee::controlNosByOffice($departmentName);
        if ($departmentControlNos === []) {
            return [];
        }

        return collect($departmentControlNos)
            ->flatMap(fn (string $controlNo): array => $this->buildControlNoCandidates($controlNo))
            ->filter(fn (string $controlNo): bool => $controlNo !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, object>
     */
    private function loadDepartmentEmployeesByControlNo(?string $departmentName): array
    {
        $directory = [];
        $employees = HrisEmployee::allByOffice($departmentName);

        foreach ($employees as $employee) {
            if (!is_object($employee)) {
                continue;
            }

            $controlNo = trim((string) ($employee->control_no ?? ''));
            if ($controlNo === '') {
                continue;
            }

            $directory[$this->normalizeControlNo($controlNo)] = $employee;
        }

        return $directory;
    }

    private function sameOffice(mixed $left, mixed $right): bool
    {
        $leftOffice = $this->normalizeOffice($left);
        $rightOffice = $this->normalizeOffice($right);

        return $leftOffice !== '' && $leftOffice === $rightOffice;
    }

    private function normalizeOffice(mixed $value): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim((string) $value));
        return strtoupper($normalized ?? '');
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

        $employee = null;
        $rawControlNo = trim((string) ($app->employee_control_no ?? ''));
        if ($rawControlNo !== '') {
            $employee = $employeesByControlNo[$this->normalizeControlNo($rawControlNo)] ?? null;
            if (!$employee) {
                $employee = HrisEmployee::findByControlNo($rawControlNo);
            }
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
        $pendingApprovedUpdateRequest = $this->resolvePendingApprovedUpdateRequestRecord($app);
        $latestApprovedUpdateRequest = $this->resolveLatestApprovedUpdateRequestRecord($app);
        $pendingUpdatePayload = $this->normalizeUpdateRequestPayload(
            $pendingApprovedUpdateRequest?->requested_payload
        );
        $latestUpdatePayload = $this->normalizeUpdateRequestPayload(
            $latestApprovedUpdateRequest?->requested_payload
        );
        $pendingUpdateActionType = $this->resolveUpdateRequestActionTypeFromPayload($pendingUpdatePayload);
        $latestUpdateActionType = $this->resolveUpdateRequestActionTypeFromPayload($latestUpdatePayload);
        $pendingUpdatePreviousStatus = $pendingApprovedUpdateRequest
            ? strtoupper(trim((string) ($pendingApprovedUpdateRequest->previous_status ?? '')))
            : null;
        $latestUpdatePreviousStatus = $latestApprovedUpdateRequest
            ? strtoupper(trim((string) ($latestApprovedUpdateRequest->previous_status ?? '')))
            : null;
        $pendingUpdateRequestedBy = $this->trimNullableString(
            $pendingApprovedUpdateRequest?->requested_by_control_no ?? null
        );
        $latestUpdateRequestedBy = $this->trimNullableString(
            $latestApprovedUpdateRequest?->requested_by_control_no ?? null
        );
        $pendingUpdateReason = $this->trimNullableString(
            $pendingApprovedUpdateRequest?->requested_reason ?? null
        );
        $latestUpdateReason = $this->trimNullableString(
            $latestApprovedUpdateRequest?->requested_reason ?? null
        );
        $latestUpdateStatus = $latestApprovedUpdateRequest
            ? strtoupper(trim((string) ($latestApprovedUpdateRequest->status ?? '')))
            : null;
        $latestUpdateReviewRemarks = $this->trimNullableString(
            $latestApprovedUpdateRequest?->review_remarks ?? null
        );
        $statusHistory = $logs->map(function (LeaveApplicationLog $log) use ($actorDirectory, $employeeName) {
            $actorName = $this->resolvePerformerName($log, $actorDirectory, $employeeName);

            return [
                'action' => $log->action,
                'stage' => $log->action,
                'actor_name' => $actorName,
                'action_by_name' => $actorName,
                'action_by' => $actorName,
                'performed_by_type' => strtoupper((string) $log->performed_by_type),
                'remarks' => $log->remarks,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        })->values();

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
            'details_of_leave' => $app->details_of_leave,
            'detailsOfLeave' => $app->details_of_leave,
            'status' => $statusMap[$app->status] ?? $app->status,
            'rawStatus' => $app->status,
            'dateFiled' => $app->created_at ? $app->created_at->toDateString() : '',
            'filedAt' => $app->created_at?->toIso8601String(),
            'filed_at' => $app->created_at?->toIso8601String(),
            'createdAt' => $app->created_at?->toIso8601String(),
            'created_at' => $app->created_at?->toIso8601String(),
            'remarks' => $app->remarks,
            'pending_update' => $pendingUpdatePayload,
            'pending_update_action_type' => $pendingUpdateActionType,
            'pending_update_reason' => $pendingUpdateReason,
            'pending_update_previous_status' => $pendingUpdatePreviousStatus,
            'pending_update_requested_by' => $pendingUpdateRequestedBy,
            'pending_update_requested_at' => $pendingApprovedUpdateRequest?->requested_at?->toIso8601String(),
            'has_pending_update_request' => $pendingApprovedUpdateRequest !== null,
            'latest_update_request_status' => $latestUpdateStatus,
            'latest_update_request_payload' => $latestUpdatePayload,
            'latest_update_request_action_type' => $latestUpdateActionType,
            'latest_update_request_reason' => $latestUpdateReason,
            'latest_update_request_previous_status' => $latestUpdatePreviousStatus,
            'latest_update_requested_by' => $latestUpdateRequestedBy,
            'latest_update_requested_at' => $latestApprovedUpdateRequest?->requested_at?->toIso8601String(),
            'latest_update_reviewed_at' => $latestApprovedUpdateRequest?->reviewed_at?->toIso8601String(),
            'latest_update_review_remarks' => $latestUpdateReviewRemarks,
            'selected_dates' => $selectedDates,
            'selected_date_pay_status' => $selectedDatePayStatus,
            'selected_date_coverage' => $selectedDateCoverage,
            'selected_date_half_day_portion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selectedDateHalfDayPortion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'recallEffectiveDate' => $app->recall_effective_date?->toDateString(),
            'recall_effective_date' => $app->recall_effective_date?->toDateString(),
            'recallSelectedDates' => is_array($app->recall_selected_dates) ? array_values($app->recall_selected_dates) : null,
            'recall_selected_dates' => is_array($app->recall_selected_dates) ? array_values($app->recall_selected_dates) : null,
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
            'status_history' => $statusHistory,
        ];
    }

    private function resolvePendingApprovedUpdateRequestRecord(LeaveApplication $app): ?LeaveApplicationUpdateRequest
    {
        if (!$app->id) {
            return null;
        }

        if ($app->relationLoaded('updateRequests')) {
            $record = $app->updateRequests
                ->filter(fn($item) => $item instanceof LeaveApplicationUpdateRequest)
                ->sortByDesc(fn(LeaveApplicationUpdateRequest $item) => (int) $item->id)
                ->first(function (LeaveApplicationUpdateRequest $item): bool {
                    return strtoupper(trim((string) ($item->status ?? ''))) === LeaveApplicationUpdateRequest::STATUS_PENDING
                        && strtoupper(trim((string) ($item->previous_status ?? ''))) === LeaveApplication::STATUS_APPROVED;
                });

            return $record instanceof LeaveApplicationUpdateRequest ? $record : null;
        }

        return LeaveApplicationUpdateRequest::query()
            ->where('leave_application_id', (int) $app->id)
            ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
            ->where('previous_status', LeaveApplication::STATUS_APPROVED)
            ->latest('id')
            ->first();
    }

    private function resolveLatestApprovedUpdateRequestRecord(LeaveApplication $app): ?LeaveApplicationUpdateRequest
    {
        if (!$app->id) {
            return null;
        }

        if ($app->relationLoaded('updateRequests')) {
            $record = $app->updateRequests
                ->filter(fn($item) => $item instanceof LeaveApplicationUpdateRequest)
                ->sortByDesc(fn(LeaveApplicationUpdateRequest $item) => (int) $item->id)
                ->first(function (LeaveApplicationUpdateRequest $item): bool {
                    return strtoupper(trim((string) ($item->previous_status ?? ''))) === LeaveApplication::STATUS_APPROVED;
                });

            return $record instanceof LeaveApplicationUpdateRequest ? $record : null;
        }

        $record = LeaveApplicationUpdateRequest::query()
            ->where('leave_application_id', (int) $app->id)
            ->latest('id')
            ->first();

        if (!$record) {
            return null;
        }

        return strtoupper(trim((string) ($record->previous_status ?? ''))) === LeaveApplication::STATUS_APPROVED
            ? $record
            : null;
    }

    private function normalizeUpdateRequestPayload(mixed $payload): ?array
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        if (!is_array($payload) || $payload === []) {
            return null;
        }

        return $payload;
    }

    private function resolveUpdateRequestActionTypeFromPayload(?array $payload): ?string
    {
        if (!is_array($payload) || $payload === []) {
            return null;
        }

        $candidates = [
            $payload['request_action_type'] ?? null,
            $payload['requestActionType'] ?? null,
            $payload['update_action_type'] ?? null,
            $payload['updateActionType'] ?? null,
            $payload['action_type'] ?? null,
            $payload['actionType'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = strtoupper(trim((string) $candidate));
            $normalized = str_replace([' ', '-'], '_', $normalized);
            if ($normalized === '') {
                continue;
            }

            if (in_array($normalized, [
                LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE,
                'UPDATE_REQUEST',
                'EDIT_REQUEST',
                'REQUEST_EDIT',
            ], true)) {
                return LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE;
            }

            if (in_array($normalized, [
                LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL,
                'CANCEL_REQUEST',
                'REQUEST_CANCELLATION',
                'CANCELLATION_REQUEST',
            ], true)) {
                return LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL;
            }
        }

        return null;
    }

    private function trimNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        return $trimmed !== '' ? $trimmed : null;
    }

    private function formatEmployeeFullName(?object $employee): string
    {
        if (!$employee) {
            return '';
        }

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
            ->mapWithKeys(function (object $employee, string $normalizedControlNo) {
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
            ->map(fn(object $employee) => trim((string) ($employee->control_no ?? '')))
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
        $canonicalLeaveTypeId = LeaveType::resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        $lookupLeaveTypeIds = LeaveType::isSpecialPrivilegeType(null, $canonicalLeaveTypeId)
            ? LeaveType::resolveSpecialPrivilegeRelatedTypeIds()
            : [$canonicalLeaveTypeId];

        return $this->queryAdminEmployeeBalances($admin)
            ->whereIn('leave_type_id', $lookupLeaveTypeIds === [] ? [$canonicalLeaveTypeId] : $lookupLeaveTypeIds)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    private function resolveVacationLeaveTypeId(): ?int
    {
        $value = LeaveType::query()
            ->whereRaw('LOWER(name) = ?', ['vacation leave'])
            ->value('id');

        return $value !== null ? (int) $value : null;
    }

    private function isWellnessLeaveType(?LeaveType $leaveType = null, ?int $leaveTypeId = null): bool
    {
        static $leaveTypeNameCache = [];

        $leaveTypeName = null;
        if ($leaveType instanceof LeaveType) {
            $leaveTypeName = trim((string) ($leaveType->name ?? ''));
        } elseif ($leaveTypeId !== null && $leaveTypeId > 0) {
            if (!array_key_exists($leaveTypeId, $leaveTypeNameCache)) {
                $leaveTypeNameCache[$leaveTypeId] = LeaveType::query()
                    ->whereKey((int) $leaveTypeId)
                    ->value('name');
            }

            $resolvedName = $leaveTypeNameCache[$leaveTypeId];
            $leaveTypeName = is_string($resolvedName) ? trim($resolvedName) : null;
        }

        return strcasecmp((string) ($leaveTypeName ?? ''), 'Wellness Leave') === 0;
    }

    private function shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
        LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        ?int $vacationLeaveTypeId
    ): bool {
        if ($vacationLeaveTypeId === null || $isMonetization) {
            return false;
        }

        $canonicalLeaveTypeId = LeaveType::resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;

        return $this->isWellnessLeaveType($leaveType, $canonicalLeaveTypeId)
            || LeaveType::isSpecialPrivilegeType($leaveType, $canonicalLeaveTypeId);
    }

    private function resolveVacationLeaveTopUpDays(
        LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        float $requestedTotalDays,
        float $deductibleDays,
        ?int $vacationLeaveTypeId
    ): float {
        if (!$this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
            $leaveType,
            $leaveTypeId,
            $isMonetization,
            $vacationLeaveTypeId
        )) {
            return 0.0;
        }

        $normalizedRequestedTotalDays = round(max($requestedTotalDays, 0.0), 2);
        $normalizedDeductibleDays = round(max($deductibleDays, 0.0), 2);

        return round(max($normalizedDeductibleDays - $normalizedRequestedTotalDays, 0.0), 2);
    }

    private function resolvePrimaryLeaveTrackedDeductionDays(
        ?LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        float $requestedTotalDays,
        float $deductibleDays,
        ?int $vacationLeaveTypeId
    ): float {
        $normalizedDeductibleDays = round(max($deductibleDays, 0.0), 2);
        if (!$leaveType instanceof LeaveType) {
            return $normalizedDeductibleDays;
        }

        $linkedVacationLeaveDays = $this->resolveVacationLeaveTopUpDays(
            $leaveType,
            $leaveTypeId,
            $isMonetization,
            $requestedTotalDays,
            $normalizedDeductibleDays,
            $vacationLeaveTypeId
        );

        return round(max($normalizedDeductibleDays - $linkedVacationLeaveDays, 0.0), 2);
    }

    private function resolveAdminEmployeeControlNo(DepartmentAdmin $admin): ?string
    {
        $rawControlNo = trim((string) $admin->employee_control_no);
        if ($rawControlNo === '') {
            return null;
        }

        $employee = HrisEmployee::findByControlNo($rawControlNo);
        if ($employee) {
            return trim((string) $employee->control_no);
        }

        return $rawControlNo;
    }

    private function resolveAdminEmployee(DepartmentAdmin $admin): ?object
    {
        $employeeControlNo = $this->resolveAdminEmployeeControlNo($admin);
        if ($employeeControlNo === null) {
            return null;
        }

        return HrisEmployee::findByControlNo($employeeControlNo);
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

    private function assertEmployeeCanReuseEventBasedLeaveType(object $employee, LeaveType $leaveType): ?JsonResponse
    {
        if (!$this->shouldEnforceEventBasedMaxDaysReuseRule($leaveType)) {
            return null;
        }

        $activeApprovedApplication = $this->findActiveApprovedEventBasedLeaveApplication($employee, (int) $leaveType->id);
        if (!$activeApprovedApplication instanceof LeaveApplication) {
            return null;
        }

        $activeThroughDate = $this->resolveApplicationLastLeaveDate($activeApprovedApplication);
        if ($activeThroughDate === null) {
            return null;
        }

        $message = sprintf(
            '%s cannot be filed again until the current approved leave period ends on %s.',
            $leaveType->name,
            $activeThroughDate
        );

        return response()->json([
            'message' => $message,
            'errors' => [
                'leave_type_id' => [$message],
            ],
            'active_approved_application_id' => (int) $activeApprovedApplication->id,
            'active_through_date' => $activeThroughDate,
        ], 422);
    }

    private function shouldEnforceEventBasedMaxDaysReuseRule(LeaveType $leaveType): bool
    {
        $category = strtoupper(trim((string) ($leaveType->category ?? '')));
        $maxDays = round((float) ($leaveType->max_days ?? 0), 2);

        return $category === LeaveType::CATEGORY_EVENT && $maxDays > 0.0;
    }

    private function findActiveApprovedEventBasedLeaveApplication(object $employee, int $leaveTypeId): ?LeaveApplication
    {
        $employeeControlNo = $this->resolveEmployeeControlNoForLeaveTypeRule($employee);
        if ($employeeControlNo === null || $leaveTypeId <= 0) {
            return null;
        }

        $canonicalLeaveTypeId = LeaveType::resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        $today = now()->toDateString();

        return LeaveApplication::query()
            ->whereIn('employee_control_no', $this->buildControlNoCandidates($employeeControlNo))
            ->where('leave_type_id', $canonicalLeaveTypeId)
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->orderByDesc('end_date')
            ->orderByDesc('id')
            ->get()
            ->first(function (LeaveApplication $application) use ($today): bool {
                $lastLeaveDate = $this->resolveApplicationLastLeaveDate($application);

                return $lastLeaveDate !== null && $lastLeaveDate >= $today;
            });
    }

    private function resolveEmployeeControlNoForLeaveTypeRule(object $employee): ?string
    {
        $rawControlNo = trim((string) (
            $employee->control_no
            ?? $employee->employee_control_no
            ?? $employee->erms_control_no
            ?? ''
        ));

        return $rawControlNo !== '' ? $rawControlNo : null;
    }

    private function resolveApplicationLastLeaveDate(LeaveApplication $application): ?string
    {
        $resolvedSelectedDates = $application->resolvedSelectedDates();
        if (is_array($resolvedSelectedDates) && $resolvedSelectedDates !== []) {
            $lastSelectedDate = max($resolvedSelectedDates);
            try {
                return CarbonImmutable::parse((string) $lastSelectedDate)->toDateString();
            } catch (\Throwable) {
            }
        }

        $endDate = $application->end_date?->toDateString();
        if (is_string($endDate) && $endDate !== '') {
            return $endDate;
        }

        $startDate = $application->start_date?->toDateString();
        return is_string($startDate) && $startDate !== '' ? $startDate : null;
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

        $employee = HrisEmployee::findByControlNo($controlNo);
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
        $selectedDateHalfDayPortion = $request->input('selected_date_half_day_portion');
        if ($selectedDateHalfDayPortion === null || $selectedDateHalfDayPortion === '') {
            $selectedDateHalfDayPortion = $request->input('selectedDateHalfDayPortion');
        }
        if ($selectedDateHalfDayPortion === null || $selectedDateHalfDayPortion === '') {
            $selectedDateHalfDayPortion = $request->input('selected_date_half_day_portions');
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
        $normalizedHalfDayPortion = $this->mergeSelectedDateHalfDayPortionMaps(
            $this->normalizeSelectedDateHalfDayPortionMap($selectedDateHalfDayPortion),
            $this->normalizeSelectedDateHalfDayPortionMap($selectedDateCoverage)
        );
        foreach (array_keys($normalizedHalfDayPortion) as $dateKey) {
            $normalizedCoverage[$dateKey] = 'half';
        }
        if ($normalizedCoverage !== []) {
            $request->merge(['selected_date_coverage' => $normalizedCoverage]);
        }
        if ($normalizedHalfDayPortion !== []) {
            $request->merge(['selected_date_half_day_portion' => $normalizedHalfDayPortion]);
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
            'am',
            'morning',
            'pm',
            'afternoon' => 'half',
            default => null,
        };
    }

    private function normalizeSelectedDateHalfDayPortionValue(mixed $value): ?string
    {
        $token = strtoupper(str_replace([' ', '-', '_'], '', trim((string) $value)));

        return match ($token) {
            'AM', 'MORNING' => 'AM',
            'PM', 'AFTERNOON' => 'PM',
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

    private function normalizeSelectedDateHalfDayPortionMap(mixed $value): array
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
        foreach ($value as $rawDate => $rawPortion) {
            $dateKey = $this->normalizeSelectedDateMapKey($rawDate);
            $portion = $this->normalizeSelectedDateHalfDayPortionValue($rawPortion);
            if ($dateKey === null || $portion === null) {
                continue;
            }

            $normalized[$dateKey] = $portion;
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

    private function compactSelectedDateHalfDayPortionMap(array $selectedDateHalfDayPortion, ?array $selectedDates): array
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

            if (!array_key_exists($dateKey, $selectedDateHalfDayPortion)) {
                continue;
            }

            $resolved[$dateKey] = $selectedDateHalfDayPortion[$dateKey];
        }

        ksort($resolved);
        return $resolved;
    }

    private function mergeSelectedDateHalfDayPortionMaps(array $primaryMap, array $fallbackMap): array
    {
        $resolvedMap = $fallbackMap;
        foreach ($primaryMap as $dateKey => $portion) {
            $resolvedMap[$dateKey] = $portion;
        }

        ksort($resolvedMap);
        return $resolvedMap;
    }

    private function synchronizeSelectedDateCoverageMetadata(
        array $selectedDateCoverage,
        array $selectedDateHalfDayPortion,
        ?array $selectedDates
    ): array {
        $resolvedCoverage = $this->compactSelectedDateCoverageMap($selectedDateCoverage, $selectedDates);
        $resolvedHalfDayPortion = $this->compactSelectedDateHalfDayPortionMap($selectedDateHalfDayPortion, $selectedDates);

        foreach (array_keys($resolvedHalfDayPortion) as $dateKey) {
            $resolvedCoverage[$dateKey] = 'half';
        }

        foreach ($resolvedHalfDayPortion as $dateKey => $portion) {
            if (($resolvedCoverage[$dateKey] ?? null) !== 'half') {
                unset($resolvedHalfDayPortion[$dateKey]);
            }
        }

        ksort($resolvedCoverage);
        ksort($resolvedHalfDayPortion);

        return [
            'selectedDateCoverage' => $resolvedCoverage,
            'selectedDateHalfDayPortion' => $resolvedHalfDayPortion,
        ];
    }

    private function resolveCoverageDurationDays(mixed $coverage, ?string $employeeControlNo = null): float
    {
        return $this->workScheduleService()->resolveCoverageDeductionDays(
            $this->normalizeSelectedDateCoverageValue($coverage) === 'half' ? 'half' : 'whole',
            $employeeControlNo
        );
    }

    private function resolveRequestedDeductibleDays(
        ?array $selectedDates,
        array $selectedDateCoverage,
        array $selectedDatePayStatus,
        string $requestedPayMode,
        float $requestedTotalDays,
        ?string $employeeControlNo = null
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

            $deductible += $this->resolveCoverageDurationDays(
                $selectedDateCoverage[$dateKey] ?? 'whole',
                $employeeControlNo
            );
        }

        return round(max($deductible, 0.0), 2);
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
        float $targetDeductibleDays,
        ?string $employeeControlNo = null
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

            $durationDays = $this->resolveCoverageDurationDays(
                $selectedDateCoverage[$dateKey] ?? 'whole',
                $employeeControlNo
            );
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
