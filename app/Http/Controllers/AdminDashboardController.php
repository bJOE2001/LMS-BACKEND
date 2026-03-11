<?php

namespace App\Http\Controllers;

use App\Models\AdminLeaveBalance;
use App\Models\DepartmentAdmin;
use App\Models\Employee;
use App\Models\HRAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Models\Notification;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                $query->whereIn('erms_control_no', function ($q) use ($deptName) {
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

        return response()->json([
            'pending_count' => $pending,
            'approved_today' => $approvedToday,
            'total_approved' => $totalApproved,
            'total_count' => $applications->count(),
            'kpi_breakdown' => $kpiBreakdown,
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

        $balances = AdminLeaveBalance::where('admin_id', $admin->id)
            ->where('year', now()->year)
            ->with('leaveType')
            ->get()
            ->keyBy('leave_type_id');

        // ACCRUED (Vacation Leave, Sick Leave)
        $accruedTypes = LeaveType::accrued()->orderBy('name')->get();
        $accrued = [];
        foreach ($accruedTypes as $type) {
            $bal = $balances->get($type->id);
            $accrued[] = [
                'id' => $type->id,
                'name' => $type->name,
                'balance' => $bal ? (float) $bal->balance : 0,
                'accrual_rate' => (float) $type->accrual_rate,
                'is_credit_based' => $type->is_credit_based,
            ];
        }

        // RESETTABLE
        $resettableTypes = LeaveType::resettable()->orderBy('name')->get();
        $resettable = $resettableTypes->map(function (LeaveType $type) use ($balances) {
            $bal = $balances->get($type->id);
            return [
                'id' => $type->id,
                'name' => $type->name,
                'balance' => $bal ? (float) $bal->balance : 0,
                'max_days' => $type->max_days,
                'description' => $type->description,
            ];
        })->values();

        // EVENT-BASED
        $eventTypes = LeaveType::eventBased()->orderBy('name')->get();
        $eventBased = $eventTypes->map(fn(LeaveType $type) => [
            'id' => $type->id,
            'name' => $type->name,
            'max_days' => $type->max_days,
            'requires_documents' => $type->requires_documents,
            'description' => $type->description,
        ])->values();

        return response()->json([
            'leave_initialized' => (bool) $admin->leave_initialized,
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

        if ($admin->leave_initialized) {
            return response()->json([
                'message' => 'Leave balances already initialized.',
                'leave_initialized' => true,
            ]);
        }

        $types = LeaveType::whereIn('category', [
            LeaveType::CATEGORY_ACCRUED,
            LeaveType::CATEGORY_RESETTABLE,
        ])
            ->orderBy('category')
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'accrual_rate', 'max_days', 'description']);

        return response()->json([
            'leave_initialized' => false,
            'leave_types' => $types,
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

        if ($admin->leave_initialized) {
            return response()->json(['message' => 'Leave balances already initialized.'], 422);
        }

        $allowedTypeIds = LeaveType::whereIn('category', [
            LeaveType::CATEGORY_ACCRUED,
            LeaveType::CATEGORY_RESETTABLE,
        ])->pluck('id')->toArray();

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

        DB::transaction(function () use ($admin, $balances) {
            $now = now();
            foreach ($balances as $typeId => $value) {
                AdminLeaveBalance::create([
                    'admin_id' => $admin->id,
                    'leave_type_id' => (int) $typeId,
                    'balance' => (float) $value,
                    'initialized_at' => $now,
                    'year' => $now->year,
                ]);
            }
            $admin->update(['leave_initialized' => true]);
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

        $balance = AdminLeaveBalance::where('admin_id', $admin->id)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', now()->year)
            ->first();

        return response()->json([
            'leave_type_id' => $leaveType->id,
            'leave_type_name' => $leaveType->name,
            'balance' => $balance ? (float) $balance->balance : 0,
        ]);
    }

    /**
     * GET /admin/employee-leave-balance/{employeeId}/{leaveTypeId}
     * Return a department employee's balance for a specific leave type.
     * Used by the admin on-behalf monetization form.
     */
    public function employeeLeaveBalance(Request $request, string $employeeId, int $leaveTypeId): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $admin->loadMissing('department');
        $employee = Employee::find($employeeId);
        if (!$employee || $employee->office !== $admin->department?->name) {
            return response()->json(['message' => 'Employee not found in your department.'], 404);
        }

        $leaveType = LeaveType::find($leaveTypeId);
        if (!$leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        $balance = LeaveBalance::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
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
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        if (!$admin->leave_initialized) {
            return response()->json(['message' => 'Please initialize your leave balances first.'], 422);
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
            'reason' => 'required|string',
            'selected_dates' => ['nullable', 'array'],
            'selected_dates.*' => ['date'],
        ]);

        $leaveType = LeaveType::find($validated['leave_type_id']);

        if (!$leaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
                'errors' => [
                    'leave_type_id' => ['Selected leave type is not available.'],
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

        // Check balance if credit-based
        if ($leaveType->is_credit_based) {
            $balance = AdminLeaveBalance::where('admin_id', $admin->id)
                ->where('leave_type_id', $leaveType->id)
                ->where('year', now()->year)
                ->first();

            if (!$balance) {
                return response()->json([
                    'message' => "{$leaveType->name} is not available for this account.",
                    'errors' => [
                        'leave_type_id' => ["{$leaveType->name} is not available for this account."],
                    ],
                ], 422);
            }

            $currentBalance = (float) $balance->balance;

            if ($currentBalance < (float) $validated['total_days']) {
                $fmtAvail = self::formatDays($currentBalance);
                return response()->json([
                    'message' => "Insufficient leave balance. Available: {$fmtAvail}.",
                    'errors' => [
                        'total_days' => ["Insufficient leave balance. Available: {$fmtAvail}."]
                    ],
                ], 422);
            }
        }

        // 3. Create the application
        $application = DB::transaction(function () use ($validated, $admin, $leaveType) {
            $app = LeaveApplication::create([
                'applicant_admin_id' => $admin->id,
                'erms_control_no' => null,
                'leave_type_id' => $leaveType->id,
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'total_days' => $validated['total_days'],
                'reason' => $validated['reason'],
                'selected_dates' => $validated['selected_dates'] ?? null,
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
            'message' => 'Leave application submitted successfully.',
            'application' => $this->formatApplication($application->fresh(['leaveType', 'applicantAdmin'])),
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

        $balance = AdminLeaveBalance::where('admin_id', $admin->id)
            ->where('leave_type_id', $validated['leave_type_id'])
            ->where('year', now()->year)
            ->first();

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
        if ($salary && is_numeric($salary) && (float) $salary > 0) {
            $dailyRate = (float) $salary / 22;
            $equivalentAmount = round($requestedDays * $dailyRate, 2);
        }

        $application = DB::transaction(function () use ($validated, $admin, $equivalentAmount) {
            $app = LeaveApplication::create([
                'applicant_admin_id' => $admin->id,
                'erms_control_no' => null,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => null,
                'end_date' => null,
                'total_days' => $validated['total_days'],
                'reason' => $validated['reason'] ?? null,
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'admin_approved_at' => now(),
                'is_monetization' => true,
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
            $rawControlNo = $application->getRawOriginal('erms_control_no') ?: $application->erms_control_no;
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
        ];

        $employee = $app->employee;
        if (!$employee) {
            $rawControlNo = $app->getRawOriginal('erms_control_no') ?: $app->erms_control_no;
            $employee = $employeesByControlNo[$this->normalizeControlNo($rawControlNo)] ?? null;
        }

        // Determine employee name & office (could be admin self-apply)
        $employeeFullName = $employee ? $this->formatEmployeeFullName($employee) : '';
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
        $hrActionBy = ($app->hr_id && isset($actorDirectory['hr'][(int) $app->hr_id]))
            ? $actorDirectory['hr'][(int) $app->hr_id]
            : $this->resolvePerformerName($hrApprovedLog ?? $hrRejectedLog, $actorDirectory, $employeeName);

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
        } elseif ($app->status === LeaveApplication::STATUS_REJECTED) {
            $processedBy = $disapprovedBy;
            $reviewedAt = $disapprovedAt ?? $hrActionAt ?? $adminActionAt;
        }

        $currentLeaveBalances = $this->getCurrentLeaveBalancesForApp($app, $leaveBalanceDirectory);

        return [
            'id' => $app->id,
            'employee_id' => $app->erms_control_no,
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
            'days' => (float) $app->total_days,
            'reason' => $app->reason,
            'status' => $statusMap[$app->status] ?? $app->status,
            'rawStatus' => $app->status,
            'dateFiled' => $app->created_at ? $app->created_at->toDateString() : '',
            'remarks' => $app->remarks,
            'selected_dates' => $app->selected_dates,
            'commutation' => $app->commutation ?? 'Not Requested',
            'is_monetization' => (bool) $app->is_monetization,
            'equivalent_amount' => $app->equivalent_amount ? (float) $app->equivalent_amount : null,
            'admin_id' => $app->admin_id,
            'hr_id' => $app->hr_id,
            'filedBy' => $filedBy,
            'adminActionBy' => $adminActionBy,
            'hrActionBy' => $hrActionBy,
            'disapprovedBy' => $disapprovedBy,
            'cancelledBy' => $cancelledBy,
            'processedBy' => $processedBy,
            'reviewedAt' => $reviewedAt?->toIso8601String(),
            'adminActionAt' => $adminActionAt?->toIso8601String(),
            'hrActionAt' => $hrActionAt?->toIso8601String(),
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
                ->whereIn('employee_id', $employeeControlNos->all())
                ->get()
                ->groupBy(fn(LeaveBalance $balance) => $this->normalizeControlNo($balance->employee_id))
                ->map(fn(Collection $balances) => $this->formatLeaveBalanceSnapshot($balances))
                ->all();

        $adminIds = $applications
            ->pluck('applicant_admin_id')
            ->filter()
            ->map(fn(mixed $id) => (int) $id)
            ->unique()
            ->values();

        $adminBalances = $adminIds->isEmpty()
            ? []
            : AdminLeaveBalance::query()
                ->with('leaveType:id,name')
                ->whereIn('admin_id', $adminIds->all())
                ->where('year', now()->year)
                ->get()
                ->groupBy(fn(AdminLeaveBalance $balance) => (int) $balance->admin_id)
                ->map(fn(Collection $balances) => $this->formatLeaveBalanceSnapshot($balances))
                ->all();

        return [
            'employee' => $employeeBalances,
            'admin' => $adminBalances,
        ];
    }

    private function formatLeaveBalanceSnapshot(Collection $balances): array
    {
        return $balances
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
        if ($app->erms_control_no) {
            $employeeKey = $this->normalizeControlNo($app->erms_control_no);
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
}
