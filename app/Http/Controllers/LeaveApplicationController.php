<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FiltersEmployeeControlNos;
use App\Http\Requests\HrLeaveApplicationIndexRequest;
use App\Http\Requests\HrVerifyLeaveApplicationDocumentRequest;
use App\Models\DepartmentAdmin;
use App\Models\DepartmentHead;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveApplicationPrintLog;
use App\Models\LeaveApplicationUpdateRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAccrualHistory;
use App\Models\LeaveType;
use App\Services\CocLedgerService;
use App\Services\Leave\LeaveCalculationService;
use App\Services\Leave\LeaveCertificationService;
use App\Services\Leave\LeaveCreditService;
use App\Services\Leave\LeaveEligibilityService;
use App\Services\Leave\LeaveWorkflowService;
use App\Services\LeaveApplicationDocumentVerificationService;
use App\Services\SmsGatewayService;
use App\Services\WorkScheduleService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * Leave Application Workflow Controller.
 *
 * Flow: Employee submits → Admin approves/rejects → HR approves/rejects.
 * On HR approval, credit-based leave balances are deducted.
 * This system uses ERMS ControlNo as the authoritative employee identifier.
 * Employee records are resolved from HRIS (xPersonal + vwpartitionforseparated).
 */
class LeaveApplicationController extends Controller
{
    use FiltersEmployeeControlNos;

    private const TERMINAL_LEAVE_ESTIMATE_FACTOR = LeaveCertificationService::TERMINAL_LEAVE_ESTIMATE_FACTOR;

    private const TERMINAL_LEAVE_AMOUNT_PRECISION = LeaveCertificationService::TERMINAL_LEAVE_AMOUNT_PRECISION;

    private const CERTIFICATION_SELECTED_LEAVE_TYPE_BUCKET = LeaveCertificationService::CERTIFICATION_SELECTED_LEAVE_TYPE_BUCKET;

    private const QUEUE_GROUP_PENDING = LeaveWorkflowService::QUEUE_GROUP_PENDING;

    private const QUEUE_GROUP_APPROVED = LeaveWorkflowService::QUEUE_GROUP_APPROVED;

    private const QUEUE_GROUP_REJECTED = LeaveWorkflowService::QUEUE_GROUP_REJECTED;

    private const QUEUE_GROUP_RECALLED = LeaveWorkflowService::QUEUE_GROUP_RECALLED;

    private const QUEUE_GROUP_OTHER = LeaveWorkflowService::QUEUE_GROUP_OTHER;

    private const QUEUE_GROUP_PRIORITY = LeaveWorkflowService::QUEUE_GROUP_PRIORITY;

    private const QUEUE_STAGE_PRIORITY = LeaveWorkflowService::QUEUE_STAGE_PRIORITY;

    private const QUEUE_NON_PENDING_STAGE_PRIORITY = LeaveWorkflowService::QUEUE_NON_PENDING_STAGE_PRIORITY;

    public function __construct(
        private LeaveApplicationDocumentVerificationService $documentVerificationService,
        private ?LeaveCalculationService $leaveCalculationService = null,
        private ?LeaveEligibilityService $leaveEligibilityService = null,
        private ?LeaveCertificationService $leaveCertificationService = null,
        private ?LeaveCreditService $leaveCreditService = null,
        private ?LeaveWorkflowService $leaveWorkflowService = null
    ) {
        $this->leaveCalculationService = $this->leaveCalculationService ?? app(LeaveCalculationService::class);
        $this->leaveEligibilityService = $this->leaveEligibilityService ?? app(LeaveEligibilityService::class);
        $this->leaveCertificationService = $this->leaveCertificationService ?? app(LeaveCertificationService::class);
        $this->leaveCreditService = $this->leaveCreditService ?? app(LeaveCreditService::class);
        $this->leaveWorkflowService = $this->leaveWorkflowService ?? app(LeaveWorkflowService::class);
    }

    private function leaveWorkflowService(): LeaveWorkflowService
    {
        return $this->leaveWorkflowService ??= app(LeaveWorkflowService::class);
    }

    private function leaveCalculationService(): LeaveCalculationService
    {
        return $this->leaveCalculationService ??= app(LeaveCalculationService::class);
    }

    private function leaveEligibilityService(): LeaveEligibilityService
    {
        return $this->leaveEligibilityService ??= app(LeaveEligibilityService::class);
    }

    private function leaveCertificationService(): LeaveCertificationService
    {
        return $this->leaveCertificationService ??= app(LeaveCertificationService::class);
    }

    private function leaveCreditService(): LeaveCreditService
    {
        return $this->leaveCreditService ??= app(LeaveCreditService::class);
    }

    private function workScheduleService(): WorkScheduleService
    {
        return app(WorkScheduleService::class);
    }

    private function cocLedgerService(): CocLedgerService
    {
        return app(CocLedgerService::class);
    }

    private function smsGatewayService(): SmsGatewayService
    {
        return app(SmsGatewayService::class);
    }

    // ─── Employee: List own applications ──────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $account = $request->user();
        $controlNo = $account->employee_control_no ?? $account->erms_control_no ?? $account->employee_id ?? null;
        if (! is_object($account) || $controlNo === null) {
            return response()->json(['message' => 'Only employee accounts can list their leave applications.'], 403);
        }

        $applications = LeaveApplication::with(['leaveType', 'applicantAdmin.department', 'updateRequests'])
            ->where(fn ($query) => $this->applyApplicationOwnershipFilter($query, (string) $controlNo))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn ($app) => $this->formatApplication($app)),
        ]);
    }

    // ─── Employee: Fetch leave balance for monetization ─────────────

    /**
     * GET /employee/leave-balance/{leaveTypeId}
     * Returns the current balance for a specific leave type.
     * Used by the monetization form to show available credits.
     */
    public function getLeaveBalance(Request $request, int $leaveTypeId): JsonResponse
    {
        $account = $request->user();
        $controlNo = $account->employee_control_no ?? $account->erms_control_no ?? $account->employee_id ?? null;
        if (! is_object($account) || $controlNo === null) {
            return response()->json(['message' => 'Only employee accounts can access this endpoint.'], 403);
        }

        $employee = $this->findEmployeeByControlNo((string) $controlNo);
        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $resolvedLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        $leaveType = LeaveType::find($resolvedLeaveTypeId);
        if (! $leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        $leaveTypeBalanceAccess = $this->assertEmployeeCanAccessLeaveTypeBalance($employee, $leaveType);
        if ($leaveTypeBalanceAccess instanceof JsonResponse) {
            return $leaveTypeBalanceAccess;
        }

        $ctoBalanceHours = null;
        if ($this->isCtoLeaveType($leaveType, (int) $leaveType->id)) {
            $this->syncEmployeeCtoBalance((string) $employee->control_no);
            $ctoSnapshot = $this->cocLedgerService()->syncEmployeeLedger(
                (string) $employee->control_no,
                (int) $leaveType->id,
                null,
                false
            );
            $ctoBalanceHours = isset($ctoSnapshot['availableHours'])
                ? (float) $ctoSnapshot['availableHours']
                : null;
        }

        $balance = $this->findPreferredEmployeeLeaveBalanceRecord((string) $employee->control_no, (int) $leaveType->id);
        if ($balance instanceof LeaveBalance) {
            $balance->loadMissing('accrualHistories');
        }

        return response()->json([
            'leave_type_id' => $leaveType->id,
            'leave_type_name' => $leaveType->name,
            'balance' => $balance ? (float) $balance->balance : 0,
            'balance_hours' => $this->isCtoLeaveType($leaveType, (int) $leaveType->id)
                ? $this->resolveCtoDeductedHours((float) ($balance?->balance ?? 0.0))
                : null,
        ]);
    }

    /**
     * GET /erms/leave-balance/{id}
     * Protected endpoint for ERMS-to-LMS integration.
     *
     * Supports either:
     * - /erms/leave-balance/{leaveTypeId}?employee_control_no={controlNo}
     * - /erms/leave-balance/{controlNo}?leave_type_id={leaveTypeId}
     *
     * Includes accrual metadata so ERMS can show the latest Vacation/Sick leave credits.
     */
    public function ermsGetLeaveBalance(Request $request, int $id): JsonResponse
    {
        $this->mergeEmployeeControlNoInput($request);

        $validated = $request->validate([
            'employee_control_no' => ['nullable', 'string', 'regex:/^\d+$/'],
            'leave_type_id' => ['nullable', 'integer', 'exists:tblLeaveTypes,id'],
        ]);

        $queryControlNo = $validated['employee_control_no'] ?? null;
        $queryLeaveTypeId = $validated['leave_type_id'] ?? null;

        if ($queryControlNo !== null && $queryLeaveTypeId === null) {
            $controlNo = trim((string) $queryControlNo);
            $leaveTypeId = $id;
        } elseif ($queryControlNo === null && $queryLeaveTypeId !== null) {
            $controlNo = (string) $id;
            $leaveTypeId = (int) $queryLeaveTypeId;
        } elseif ($queryControlNo !== null && $queryLeaveTypeId !== null) {
            $controlNo = trim((string) $queryControlNo);
            $leaveTypeId = (int) $queryLeaveTypeId;
        } else {
            return response()->json([
                'message' => 'Provide either employee_control_no, or leave_type_id query parameter.',
            ], 422);
        }

        $employee = $this->findEmployeeByControlNo($controlNo);
        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }
        $pulledAccess = $this->assertErmsEmployeeIsPulled((string) $employee->control_no);
        if ($pulledAccess instanceof JsonResponse) {
            return $pulledAccess;
        }

        $resolvedLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        $leaveType = LeaveType::find($resolvedLeaveTypeId);
        if (! $leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        $leaveTypeBalanceAccess = $this->assertEmployeeCanAccessLeaveTypeBalance($employee, $leaveType);
        if ($leaveTypeBalanceAccess instanceof JsonResponse) {
            return $leaveTypeBalanceAccess;
        }

        $ctoBalanceHours = null;
        if ($this->isCtoLeaveType($leaveType, (int) $leaveType->id)) {
            $this->syncEmployeeCtoBalance((string) $employee->control_no);
            $ctoSnapshot = $this->cocLedgerService()->syncEmployeeLedger(
                (string) $employee->control_no,
                (int) $leaveType->id,
                null,
                false
            );
            $ctoBalanceHours = isset($ctoSnapshot['availableHours'])
                ? (float) $ctoSnapshot['availableHours']
                : null;
        }

        $balance = $this->findPreferredEmployeeLeaveBalanceRecord((string) $employee->control_no, (int) $leaveType->id);
        if ($balance instanceof LeaveBalance) {
            $balance->loadMissing('accrualHistories');
        }

        $deductionHistoryByType = $this->loadEmployeeLeaveDeductionHistoryByType((string) $employee->control_no, (int) $leaveType->id);
        $cocCreditHistoryByType = $this->loadEmployeeCOCCreditHistoryByType((string) $employee->control_no, (int) $leaveType->id);
        $creditHistoryByType = $this->mergeCreditHistoriesByType(
            $deductionHistoryByType,
            $cocCreditHistoryByType
        );

        return response()->json(array_merge(
            $this->employeeControlNoResponse((string) $employee->control_no),
            $this->formatErmsLeaveBalancePayload(
                $leaveType,
                $balance,
                $creditHistoryByType[(int) $leaveType->id] ?? [],
                $ctoBalanceHours
            )
        ));
    }

    /**
     * GET /erms/leave-balances/{controlNo}
     * Protected endpoint for loading all leave balances in one request.
     * Also returns the latest Vacation/Sick accrued credits for ERMS leave cards.
     */
    public function ermsGetLeaveBalances(Request $request, string $controlNo): JsonResponse
    {
        if (! preg_match('/^\d+$/', $controlNo)) {
            return response()->json(['message' => 'Invalid control number.'], 422);
        }

        $employee = $this->findEmployeeByControlNo($controlNo);
        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }
        $pulledAccess = $this->assertErmsEmployeeIsPulled((string) $employee->control_no);
        if ($pulledAccess instanceof JsonResponse) {
            return $pulledAccess;
        }

        $types = LeaveType::query()
            ->withoutLegacySpecialPrivilegeAliases()
            ->select([
                'id',
                'name',
                'category',
                'accrual_rate',
                'accrual_day_of_month',
                'is_credit_based',
                'allowed_status',
            ])
            ->orderBy('name')
            ->get()
            ->filter(fn (LeaveType $leaveType): bool => ! $this->employeeHasResolvedEmploymentStatus($employee)
                || $leaveType->allowsEmploymentStatus($employee->status ?? null))
            ->values();

        $typesByName = $types
            ->keyBy(fn (LeaveType $type) => strtolower(trim((string) $type->name)))
            ->all();

        $this->syncEmployeeCtoBalance((string) $employee->control_no);
        $ctoLeaveTypeId = $this->resolveCtoLeaveTypeId();
        $ctoBalanceHours = null;
        if ($ctoLeaveTypeId !== null) {
            $ctoSnapshot = $this->cocLedgerService()->syncEmployeeLedger(
                (string) $employee->control_no,
                $ctoLeaveTypeId,
                null,
                false
            );
            $ctoBalanceHours = isset($ctoSnapshot['availableHours'])
                ? (float) $ctoSnapshot['availableHours']
                : null;
        }

        $balanceRecordsByType = $this->mapLeaveBalancesByCanonicalTypeId(LeaveBalance::query()
            ->with('accrualHistories')
            ->where('employee_control_no', $employee->control_no)
            ->get()
        );
        $deductionHistoryByType = $this->loadEmployeeLeaveDeductionHistoryByType((string) $employee->control_no);
        $cocCreditHistoryByType = $this->loadEmployeeCOCCreditHistoryByType((string) $employee->control_no);
        $creditHistoryByType = $this->mergeCreditHistoriesByType(
            $deductionHistoryByType,
            $cocCreditHistoryByType
        );

        $balances = $types->map(function (LeaveType $type) use ($balanceRecordsByType, $creditHistoryByType, $ctoLeaveTypeId, $ctoBalanceHours) {
            $balance = $balanceRecordsByType[(int) $type->id] ?? null;

            return $this->formatErmsLeaveBalancePayload(
                $type,
                $balance instanceof LeaveBalance ? $balance : null,
                $creditHistoryByType[(int) $type->id] ?? [],
                $ctoLeaveTypeId !== null && (int) $type->id === $ctoLeaveTypeId ? $ctoBalanceHours : null
            );
        })->values();

        return response()->json([
            ...$this->employeeControlNoResponse((string) $employee->control_no),
            'salary' => $employee->rate_mon !== null ? (float) $employee->rate_mon : null,
            'rate_mon' => $employee->rate_mon !== null ? (float) $employee->rate_mon : null,
            'balances' => $balances,
            'latest_accrued_credits' => $this->buildErmsLatestAccruedCreditsPayload(
                $employee,
                $typesByName,
                $balanceRecordsByType,
                $creditHistoryByType
            ),
        ]);
    }

    /**
     * GET /erms/employee-access
     * Resolve whether an employee is allowed to access LMS leave modules.
     */
    public function ermsEmployeeAccess(Request $request): JsonResponse
    {
        $this->mergeEmployeeControlNoInput($request);

        $validated = $request->validate([
            'employee_control_no' => ['nullable', 'string', 'regex:/^\d+$/'],
        ]);

        $controlNo = $this->resolveValidatedEmployeeControlNo($validated);
        if ($controlNo === '') {
            return response()->json([
                'message' => 'The employee_control_no query parameter is required.',
            ], 422);
        }

        $employee = $this->findEmployeeByControlNo($controlNo);
        if (! $employee) {
            return response()->json([
                'message' => 'Employee record not found.',
                ...$this->employeeControlNoResponse($controlNo),
                'is_pulled_employee' => false,
                'is_department_admin_employee' => false,
                'can_access_leave_modules' => false,
            ], 404);
        }

        $isPulledEmployee = $this->isPulledEmployeeControlNo((string) $employee->control_no);
        $isDepartmentAdminEmployee = $this->isDepartmentAdminEmployeeControlNo((string) $employee->control_no);
        $canAccessLeaveModules = $isPulledEmployee || $isDepartmentAdminEmployee;

        return response()->json([
            ...$this->employeeControlNoResponse((string) $employee->control_no),
            'is_pulled_employee' => $isPulledEmployee,
            'is_department_admin_employee' => $isDepartmentAdminEmployee,
            'can_access_leave_modules' => $canAccessLeaveModules,
        ]);
    }

    /**
     * GET /erms/apply-leave
     * Protected endpoint for ERMS/HRPDS personal leave records listing.
     */
    public function ermsIndex(Request $request): JsonResponse
    {
        $this->mergeEmployeeControlNoInput($request);

        $validated = $request->validate([
            'employee_control_no' => ['nullable', 'string', 'regex:/^\d+$/'],
        ]);

        $controlNo = $this->resolveValidatedEmployeeControlNo($validated);
        if ($controlNo === '') {
            return response()->json([
                'message' => 'The employee_control_no query parameter is required.',
            ], 422);
        }

        $employee = $this->findEmployeeByControlNo($controlNo);
        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }
        $pulledAccess = $this->assertErmsEmployeeIsPulled((string) $employee->control_no);
        if ($pulledAccess instanceof JsonResponse) {
            return $pulledAccess;
        }

        $applications = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->where(fn ($query) => $this->applyApplicationOwnershipFilter($query, $controlNo))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $applications = $this->sortLeaveApplicationsForHrQueue($applications);

        $actorDirectory = $this->buildWorkflowActorDirectory($applications);

        $employeeContext = $this->formatErmsEmployeeContext($employee);
        $leaveTypes = $this->getAllowedErmsLeaveTypesForEmployee($employee);

        return response()->json([
            ...$this->employeeControlNoResponse((string) $employee->control_no),
            'employment_status' => $employeeContext['status'],
            'employment_status_key' => $employeeContext['employment_status_key'],
            'ui_variant' => $employeeContext['ui_variant'],
            'employee' => $employeeContext,
            'leave_types' => $leaveTypes,
            'applications' => $applications
                ->map(fn (LeaveApplication $app) => $this->formatErmsApplication($app, $actorDirectory))
                ->values(),
        ]);
    }

    /**
     * GET /erms/apply-leave/{id}
     * GET /erms/leave-applications/{id}
     * Protected endpoint for ERMS/HRPDS leave application detail payload.
     */
    public function ermsShow(Request $request, int $id): JsonResponse
    {
        $this->mergeEmployeeControlNoInput($request);

        $validated = $request->validate([
            'employee_control_no' => ['nullable', 'string', 'regex:/^\d+$/'],
        ]);

        $controlNo = $this->resolveValidatedEmployeeControlNo($validated);
        if ($controlNo === '') {
            return response()->json([
                'message' => 'The employee_control_no query parameter is required.',
            ], 422);
        }

        $employee = $this->findEmployeeByControlNo($controlNo);
        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }
        $pulledAccess = $this->assertErmsEmployeeIsPulled((string) $employee->control_no);
        if ($pulledAccess instanceof JsonResponse) {
            return $pulledAccess;
        }

        $application = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->where('id', $id)
            ->where(fn ($query) => $this->applyApplicationOwnershipFilter($query, $controlNo))
            ->first();

        if (! $application) {
            return response()->json(['message' => 'Leave application not found for this employee.'], 404);
        }

        $actorDirectory = $this->buildWorkflowActorDirectory(collect([$application]));
        $leaveBalanceSnapshot = $this->getApplicationLeaveBalanceSnapshot($application);
        $currentLeaveBalance = $this->findLeaveTypeBalanceInSnapshot(
            $leaveBalanceSnapshot,
            (int) $application->leave_type_id
        );
        $applicationPayload = $this->formatErmsApplication($application, $actorDirectory);
        $certificationLeaveCredits = $this->resolveCertificationLeaveCredits(
            $application,
            $leaveBalanceSnapshot
        );

        $applicationPayload['leaveBalance'] = $currentLeaveBalance;
        $applicationPayload['leaveBalances'] = $leaveBalanceSnapshot;
        $applicationPayload['leave_balances'] = $leaveBalanceSnapshot;
        $applicationPayload['employee_leave_balances'] = $leaveBalanceSnapshot;
        $applicationPayload['certificationLeaveCredits'] = $certificationLeaveCredits;
        $applicationPayload['certification_leave_credits'] = $certificationLeaveCredits;

        return response()->json([
            ...$this->employeeControlNoResponse((string) $employee->control_no),
            'application' => $applicationPayload,
        ]);
    }

    /**
     * POST /erms/apply-leave
     * Protected endpoint for ERMS-to-LMS leave application submission.
     */
    public function ermsStore(Request $request): JsonResponse
    {
        $this->normalizeSelectedDatesInput($request);
        $this->normalizeSelectedDatePolicyInput($request);
        $this->mergeEmployeeControlNoInput($request);

        $baseValidated = $request->validate([
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'is_monetization' => ['nullable', 'boolean'],
        ]);

        $controlNo = $this->resolveValidatedEmployeeControlNo($baseValidated);

        $employee = $this->findEmployeeByControlNo($controlNo);

        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }
        $pulledAccess = $this->assertErmsEmployeeIsPulled((string) $employee->control_no);
        if ($pulledAccess instanceof JsonResponse) {
            return $pulledAccess;
        }

        $actor = (object) ['id' => (int) ltrim((string) $employee->control_no, '0')];
        $isMonetization = (bool) ($baseValidated['is_monetization'] ?? false);

        if ($isMonetization) {
            return $this->storeMonetization($request, $employee, $actor);
        }

        $isTerminalLeave = $this->isTerminalLeaveTypeId(
            $this->resolveCanonicalLeaveTypeId((int) $request->input('leave_type_id'))
                ?? (int) $request->input('leave_type_id')
        );

        $validated = $request->validate([
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'start_date' => $isTerminalLeave ? ['nullable', 'date'] : ['required', 'date'],
            'end_date' => $isTerminalLeave ? ['nullable', 'date'] : ['required', 'date', 'after_or_equal:start_date'],
            'total_days' => ['required', 'numeric', 'min:0.5', 'max:'.($isTerminalLeave ? '999' : '365')],
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
            'allow_sl_vl_cross_deduction' => ['nullable', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:10240'],
            'attachment_submitted' => ['nullable', 'boolean'],
            'attachment_attached' => ['nullable', 'boolean'],
            'has_attachment' => ['nullable', 'boolean'],
            'with_attachment' => ['nullable', 'boolean'],
            'attachment_reference' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->leaveWorkflowService()->executeRegularLeaveSubmission(
            $request,
            $validated,
            $actor,
            $employee,
            false,
            fn (string $value): array => $this->controlNoCandidates($value),
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    // ─── Employee: Submit new leave application ──────────────────────

    /**
     * POST /erms/leave-applications/{id}/cancel
     *
     * Protected endpoint for ERMS-to-LMS leave cancellation.
     * Cancels only pending applications owned by the provided employee.
     */
    public function ermsCancel(Request $request, ?int $id = null): JsonResponse
    {
        $routeId = $id;

        $request->merge(array_filter([
            'leave_application_id' => $request->input('leave_application_id')
                ?? $routeId,
        ], static fn ($value) => $value !== null && $value !== ''));

        $this->mergeEmployeeControlNoInput($request);

        $validated = $request->validate([
            'leave_application_id' => ['required', 'integer'],
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'cancellation_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $applicationId = (int) $validated['leave_application_id'];
        if ($routeId !== null && $applicationId !== $routeId) {
            return response()->json([
                'message' => 'Route ID and payload leave_application_id must match.',
            ], 422);
        }

        $controlNo = $this->resolveValidatedEmployeeControlNo($validated);
        $employee = $this->findEmployeeByControlNo($controlNo);
        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }
        $pulledAccess = $this->assertErmsEmployeeIsPulled((string) $employee->control_no);
        if ($pulledAccess instanceof JsonResponse) {
            return $pulledAccess;
        }

        return $this->leaveWorkflowService()->executeErmsCancel(
            $applicationId,
            $employee,
            $controlNo,
            (string) ($validated['cancellation_reason'] ?? ''),
            fn ($query, $no) => $this->applyApplicationOwnershipFilter($query, $no),
            fn ($status) => $this->ermsStatusLabel($status),
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn ($app, $dir) => $this->formatErmsApplication($app, $dir)
        );
    }

    /**
     * POST /erms/leave-applications/{id}/request-edit
     *
     * Backward-compatible alias for the newer request-update flow.
     */
    public function ermsRequestEdit(Request $request, ?int $id = null): JsonResponse
    {
        return $this->ermsRequestUpdate($request, $id);
    }

    /**
     * POST /erms/leave-applications/{id}/request-cancel
     *
     * Protected endpoint for ERMS-to-LMS cancellation request of
     * already-approved leave applications.
     */
    public function ermsRequestCancel(Request $request, ?int $id = null): JsonResponse
    {
        $routeId = $id;

        $request->merge(array_filter([
            'leave_application_id' => $request->input('leave_application_id')
                ?? $routeId,
        ], static fn ($value) => $value !== null && $value !== ''));

        $this->mergeEmployeeControlNoInput($request);

        $validated = $request->validate([
            'leave_application_id' => ['required', 'integer'],
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'cancellation_reason' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $applicationId = (int) $validated['leave_application_id'];
        if ($routeId !== null && $applicationId !== $routeId) {
            return response()->json([
                'message' => 'Route ID and payload leave_application_id must match.',
            ], 422);
        }

        $controlNo = $this->resolveValidatedEmployeeControlNo($validated);
        $employee = $this->findEmployeeByControlNo($controlNo);
        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }
        $pulledAccess = $this->assertErmsEmployeeIsPulled((string) $employee->control_no);
        if ($pulledAccess instanceof JsonResponse) {
            return $pulledAccess;
        }

        return $this->leaveWorkflowService()->executeErmsRequestCancel(
            $validated,
            $employee,
            $controlNo,
            fn (string $value): array => $this->controlNoCandidates($value),
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    /**
     * POST /erms/leave-applications/{id}/withdraw-request
     *
     * Protected endpoint for ERMS-to-LMS employee withdrawal of a pending
     * update or cancellation request for an approved application.
     */
    public function ermsWithdrawRequest(Request $request, ?int $id = null): JsonResponse
    {
        $routeId = $id;

        $request->merge(array_filter([
            'leave_application_id' => $request->input('leave_application_id')
                ?? $routeId,
        ], static fn ($value) => $value !== null && $value !== ''));

        $this->mergeEmployeeControlNoInput($request);

        $validated = $request->validate([
            'leave_application_id' => ['required', 'integer'],
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $applicationId = (int) $validated['leave_application_id'];
        if ($routeId !== null && $applicationId !== $routeId) {
            return response()->json([
                'message' => 'Route ID and payload leave_application_id must match.',
            ], 422);
        }

        $controlNo = $this->resolveValidatedEmployeeControlNo($validated);
        $employee = $this->findEmployeeByControlNo($controlNo);
        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }
        $pulledAccess = $this->assertErmsEmployeeIsPulled((string) $employee->control_no);
        if ($pulledAccess instanceof JsonResponse) {
            return $pulledAccess;
        }

        return $this->leaveWorkflowService()->executeErmsWithdrawRequest(
            $validated,
            $employee,
            $controlNo,
            fn (string $value): array => $this->controlNoCandidates($value),
            fn (LeaveApplication $application, array $actorDirectory): array => $this->formatErmsApplication($application, $actorDirectory),
            fn (iterable $applications): array => $this->buildWorkflowActorDirectory($applications)
        );
    }

    /**
     * POST /erms/leave-applications/{id}/request-update
     *
     * Protected endpoint for ERMS-to-LMS leave update request.
     * - Pending applications: logs a legacy edit request note.
     * - Approved applications: stores requested updates for admin review, then HR review.
     */
    public function ermsRequestUpdate(Request $request, ?int $id = null): JsonResponse
    {
        $this->normalizeSelectedDatesInput($request);
        $this->normalizeSelectedDatePolicyInput($request);

        $routeId = $id;

        $request->merge(array_filter([
            'leave_application_id' => $request->input('leave_application_id')
                ?? $routeId,
        ], static fn ($value) => $value !== null && $value !== ''));

        $this->mergeEmployeeControlNoInput($request);

        $validated = $request->validate([
            'leave_application_id' => ['required', 'integer'],
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'request_update' => ['nullable', 'boolean'],
            'edit_reason' => ['nullable', 'string', 'max:2000'],
            'update_reason' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'reason_purpose' => ['nullable', 'string', 'max:2000'],
            'details_of_leave' => ['nullable', 'string', 'max:2000'],
            'leave_type_id' => ['nullable', 'integer', 'exists:tblLeaveTypes,id'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'selected_dates' => ['nullable', 'array'],
            'selected_dates.*' => ['date'],
            'selected_date_pay_status' => ['nullable', 'array'],
            'selected_date_pay_status.*' => ['nullable', 'string', 'in:WP,WOP'],
            'selected_date_coverage' => ['nullable', 'array'],
            'selected_date_coverage.*' => ['nullable', 'string', 'in:whole,half'],
            'selected_date_half_day_portion' => ['nullable', 'array'],
            'selected_date_half_day_portion.*' => ['nullable', 'string', 'in:AM,PM'],
            'total_days' => ['nullable', 'numeric', 'min:0.5', 'max:365'],
            'commutation' => ['nullable', 'string', 'in:Not Requested,Requested'],
            'pay_mode' => ['nullable', 'string', 'in:WP,WOP'],
            'allow_sl_vl_cross_deduction' => ['nullable', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:10240'],
            'attachment_submitted' => ['nullable', 'boolean'],
            'attachment_attached' => ['nullable', 'boolean'],
            'has_attachment' => ['nullable', 'boolean'],
            'with_attachment' => ['nullable', 'boolean'],
            'attachment_reference' => ['nullable', 'string', 'max:500'],
            'is_monetization' => ['nullable', 'boolean'],
        ]);

        $applicationId = (int) $validated['leave_application_id'];
        if ($routeId !== null && $applicationId !== $routeId) {
            return response()->json([
                'message' => 'Route ID and payload leave_application_id must match.',
            ], 422);
        }

        $controlNo = $this->resolveValidatedEmployeeControlNo($validated);
        $employee = $this->findEmployeeByControlNo($controlNo);
        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }
        $pulledAccess = $this->assertErmsEmployeeIsPulled((string) $employee->control_no);
        if ($pulledAccess instanceof JsonResponse) {
            return $pulledAccess;
        }

        return $this->leaveWorkflowService()->executeErmsRequestUpdate(
            $request,
            $validated,
            $employee,
            $controlNo,
            fn (string $value): array => $this->controlNoCandidates($value),
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeSelectedDatesInput($request);
        $this->normalizeSelectedDatePolicyInput($request);

        $account = $request->user();
        if (! is_object($account)) {
            return response()->json(['message' => 'Only employee accounts can submit leave applications.'], 403);
        }

        $this->mergeEmployeeControlNoInput($request);

        $baseValidated = $request->validate([
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'is_monetization' => ['nullable', 'boolean'],
        ]);

        // This system uses ERMS ControlNo as the authoritative employee identifier.
        // Employee records are resolved from HRIS (xPersonal + vwpartitionforseparated).
        $employee = $this->findEmployeeByControlNo($this->resolveValidatedEmployeeControlNo($baseValidated));

        if (! $employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        // Detect monetization request
        $isMonetization = (bool) ($baseValidated['is_monetization'] ?? false);

        if ($isMonetization) {
            return $this->storeMonetization($request, $employee, $account);
        }

        $isTerminalLeave = $this->isTerminalLeaveTypeId(
            $this->resolveCanonicalLeaveTypeId((int) $request->input('leave_type_id'))
                ?? (int) $request->input('leave_type_id')
        );

        $validated = $request->validate([
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'start_date' => $isTerminalLeave ? ['nullable', 'date'] : ['required', 'date'],
            'end_date' => $isTerminalLeave ? ['nullable', 'date'] : ['required', 'date', 'after_or_equal:start_date'],
            'total_days' => ['required', 'numeric', 'min:0.5', 'max:'.($isTerminalLeave ? '999' : '365')],
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
            'allow_sl_vl_cross_deduction' => ['nullable', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:10240'],
            'attachment_submitted' => ['nullable', 'boolean'],
            'attachment_attached' => ['nullable', 'boolean'],
            'has_attachment' => ['nullable', 'boolean'],
            'with_attachment' => ['nullable', 'boolean'],
            'attachment_reference' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->leaveWorkflowService()->executeRegularLeaveSubmission(
            $request,
            $validated,
            $account,
            $employee,
            false,
            fn (string $value): array => $this->controlNoCandidates($value),
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    // ─── Employee: Submit monetization of leave credits ──────────────

    /**
     * Handle monetization submission with specific validation rules.
     * City Hall of Tagum policy: Vacation Leave or Sick Leave only, at least 15 credits available,
     * and a minimum request of 10 days.
     */
    private function storeMonetization(Request $request, object $employee, object $account): JsonResponse
    {
        $this->mergeEmployeeControlNoInput($request);

        $validated = $request->validate([
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'total_days' => ['required', 'numeric', 'min:'.LeaveApplication::MONETIZATION_MINIMUM_REQUEST_DAYS, 'max:999'],
            'monetization_leave_credits' => ['nullable', 'array', 'min:1', 'max:2'],
            'monetization_leave_credits.*.leave_type_id' => ['required_with:monetization_leave_credits', 'integer', 'exists:tblLeaveTypes,id'],
            'monetization_leave_credits.*.days' => ['required_with:monetization_leave_credits', 'numeric', 'min:0', 'max:999'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'details_of_leave' => ['nullable', 'string', 'max:2000'],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'attachment' => ['nullable', 'file', 'max:10240'],
            'attachment_submitted' => ['nullable', 'boolean'],
            'attachment_attached' => ['nullable', 'boolean'],
            'has_attachment' => ['nullable', 'boolean'],
            'with_attachment' => ['nullable', 'boolean'],
            'attachment_reference' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->leaveWorkflowService()->executeMonetizationSubmission(
            $request,
            $validated,
            $account,
            $employee,
            false,
            fn (string $value): array => $this->controlNoCandidates($value),
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    // ─── Employee: View own single application ───────────────────────

    public function show(Request $request, LeaveApplication $leaveApplication): JsonResponse
    {
        $account = $request->user();
        $controlNo = $account->employee_control_no ?? $account->erms_control_no ?? $account->employee_id ?? null;
        if (! is_object($account) || $controlNo === null) {
            return response()->json(['message' => 'Only employee accounts can view leave applications.'], 403);
        }

        $allowedControlNos = $this->controlNoCandidates((string) $controlNo);
        if (! in_array((string) ($leaveApplication->employee_control_no ?? ''), $allowedControlNos, true)) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $leaveApplication->load(['leaveType', 'logs']);

        return response()->json([
            'application' => $this->formatApplication($leaveApplication),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  ADMIN ACTIONS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * List visible applications for the admin's department.
     * Pending items are still prioritized by queue sorting.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (! $admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        return $this->leaveWorkflowService()->executeAdminIndex(
            $admin,
            fn (?string $name, ?int $id) => $this->findDepartmentEmployeeControlNos($name, null, $id),
            fn ($app) => $this->formatApplication($app)
        );
    }

    public function adminShow(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        if (! $admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        return $this->leaveWorkflowService()->executeAdminShow(
            $admin,
            $id,
            fn ($adm, $app) => $this->adminCanManageApplication($adm, $app),
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn ($app, $dir) => $this->formatErmsApplication($app, $dir),
            fn ($app) => $this->formatApplication($app)
        );
    }

    public function adminViewAttachment(Request $request, int $id)
    {
        $admin = $request->user();
        if (! $admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $admin->loadMissing('department');
        $application = LeaveApplication::query()
            ->find($id);
        if (! $application) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if (! $this->adminCanManageApplication($admin, $application)) {
            return response()->json(['message' => 'You can only view attachments from your department.'], 403);
        }

        return $this->streamApplicationAttachment($application);
    }

    /**
     * Admin approves → status becomes PENDING_HR.
     */
    public function adminApprove(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        if (! $admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can approve applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $admin->loadMissing('department');
        $app = LeaveApplication::find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        // Security: admin can only act on their own department's applications.
        if (! $this->adminCanManageApplication($admin, $app)) {
            return response()->json(['message' => 'You can only manage applications from your department.'], 403);
        }

        // Prevent double approval
        if ($app->status !== LeaveApplication::STATUS_PENDING_ADMIN) {
            return response()->json(['message' => "Cannot approve: application status is '{$app->status}', expected 'PENDING_ADMIN'."], 422);
        }

        $isPendingApprovedUpdateRequest = $this->hasPendingApprovedUpdateRequest($app);
        $pendingApprovedActionType = $this->resolvePendingApprovedUpdateActionType($app);

        $this->leaveWorkflowService()->adminApproveApplication(
            $app,
            $admin,
            $request->input('remarks'),
            $isPendingApprovedUpdateRequest,
            $pendingApprovedActionType
        );

        $employeeName = $this->resolveEmployeeDisplayName($app);
        $this->leaveWorkflowService()->notifyHrAccountsOfAdminApproval(
            $app,
            $employeeName,
            $isPendingApprovedUpdateRequest,
            $pendingApprovedActionType
        );

        return response()->json([
            'message' => $this->leaveWorkflowService()->resolveAdminApprovalResponseMessage(
                $isPendingApprovedUpdateRequest,
                $pendingApprovedActionType
            ),
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ]);
    }

    /**
     * Admin rejects → status becomes REJECTED.
     */
    public function adminReject(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        if (! $admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can reject applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $admin->loadMissing('department');
        $app = LeaveApplication::find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if (! $this->adminCanManageApplication($admin, $app)) {
            return response()->json(['message' => 'You can only manage applications from your department.'], 403);
        }

        if (! in_array($app->status, [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ], true)) {
            return response()->json([
                'message' => "Cannot reject: application status is '{$app->status}'. Expected 'PENDING_ADMIN' or 'PENDING_HR'.",
            ], 422);
        }

        if ($this->hasPendingApprovedUpdateRequest($app)) {
            return $this->adminRejectPendingUpdateRequest($request, $app, $admin);
        }

        $remarks = $request->input('remarks');
        $rejectionResult = $this->leaveWorkflowService()->adminRejectApplication($app, $admin, $remarks);
        $status = $rejectionResult['status'];

        // Notify the employee that admin rejected
        $app->load('leaveType');

        return response()->json([
            'message' => $status === LeaveApplication::STATUS_CANCELLED
                ? 'Application cancelled by admin.'
                : 'Application rejected by admin.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ]);
    }

    public function adminRequestRecall(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        if (! $admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can request recalls.'], 403);
        }

        $validated = $request->validate([
            'recall_reason' => ['required', 'string', 'max:2000'],
            'recall_selected_dates' => ['required', 'array', 'min:1'],
            'recall_selected_dates.*' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::with(['leaveType', 'applicantAdmin'])->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        return $this->leaveWorkflowService()->executeAdminRequestRecall(
            $admin,
            $app,
            $validated,
            fn (string $controlNo): ?object => $this->findEmployeeByControlNo($controlNo),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HR ACTIONS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Export Received Applications for HR module within a specific date range.
     */
    public function exportReceivedApplications(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        $fromDate = $validated['from_date'] ?? now()->toDateString();
        $toDate = $validated['to_date'] ?? now()->toDateString();
        $start = $fromDate.' 00:00:00';
        $end = $toDate.' 23:59:59';

        $applications = LeaveApplication::query()
            ->select([
                'id',
                'applicant_admin_id',
                'employee_control_no',
                'employee_name',
                'leave_type_id',
                'start_date',
                'end_date',
                'selected_dates',
                'total_days',
                'status',
                'remarks',
                'hr_id',
                'created_at',
            ])
            ->with([
                'leaveType:id,name',
                'applicantAdmin:id,department_id,employee_control_no,full_name',
                'applicantAdmin.department:id,name',
                'updateRequests:id,leave_application_id,requested_payload,requested_reason,previous_status,requested_by_control_no,requested_at,status,reviewed_at,review_remarks',
                'logs:id,leave_application_id,action,performed_by_type,performed_by_id,remarks,created_at',
            ])
            ->whereHas('logs', function ($q) use ($start, $end) {
                $q->where('action', LeaveApplicationLog::ACTION_HR_RECEIVED)
                    ->whereBetween('created_at', [$start, $end]);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn (LeaveApplication $app) => $this->formatApplication($app)),
        ]);
    }

    /**
     * Export Released Applications for HR module within a specific date range.
     */
    public function exportReleasedApplications(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $validated = $request->validate([
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date', 'after_or_equal:from_date'],
        ]);

        $fromDate = $validated['from_date'] ?? now()->toDateString();
        $toDate = $validated['to_date'] ?? now()->toDateString();
        $start = $fromDate.' 00:00:00';
        $end = $toDate.' 23:59:59';

        $applications = LeaveApplication::query()
            ->select([
                'id',
                'applicant_admin_id',
                'employee_control_no',
                'employee_name',
                'leave_type_id',
                'start_date',
                'end_date',
                'selected_dates',
                'total_days',
                'status',
                'remarks',
                'hr_id',
                'created_at',
            ])
            ->with([
                'leaveType:id,name',
                'applicantAdmin:id,department_id,employee_control_no,full_name',
                'applicantAdmin.department:id,name',
                'updateRequests:id,leave_application_id,requested_payload,requested_reason,previous_status,requested_by_control_no,requested_at,status,reviewed_at,review_remarks',
                'logs:id,leave_application_id,action,performed_by_type,performed_by_id,remarks,created_at',
            ])
            ->whereHas('logs', function ($q) use ($start, $end) {
                $q->where('action', LeaveApplicationLog::ACTION_HR_RELEASED)
                    ->whereBetween('created_at', [$start, $end]);
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn (LeaveApplication $app) => $this->formatApplication($app)),
        ]);
    }

    /**
     * List cancelled applications for HR module.
     * Supports tabs: 'ALL', 'APPROVED_CANCELLATION', 'EMPLOYEE_CANCELLED'.
     */
    public function hrCancelledApplications(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $validated = $request->validate([
            'tab' => ['nullable', 'string', 'in:ALL,APPROVED_CANCELLATION,EMPLOYEE_CANCELLED'],
            'search' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->leaveWorkflowService()->executeHrCancelledApplications(
            $validated,
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn (LeaveApplication $app, array $actorDirectory) => $this->formatApplication($app, $actorDirectory)
        );
    }

    /**
     * Export Cancelled Applications for HR module within a specific date range / filter.
     */
    public function exportCancelledApplications(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $validated = $request->validate([
            'tab' => ['nullable', 'string', 'in:ALL,APPROVED_CANCELLATION,EMPLOYEE_CANCELLED'],
            'search' => ['nullable', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'from_date' => ['nullable', 'date'],
            'to_date' => ['nullable', 'date'],
        ]);

        return $this->leaveWorkflowService()->executeExportCancelledApplications(
            $validated,
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn (LeaveApplication $app, array $actorDirectory) => $this->formatApplication($app, $actorDirectory)
        );
    }

    /**
     * List PENDING_HR applications (all departments).
     */
    public function hrIndex(HrLeaveApplicationIndexRequest $request): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        return $this->leaveWorkflowService()->executeHrIndex(
            $hr,
            $request->validated(),
            fn (string $controlNo): ?object => $this->findEmployeeByControlNo($controlNo),
            fn (LeaveApplication $app): array => $this->formatApplication($app)
        );
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeHrApplicationSearch(mixed $search): array
    {
        $normalizedSearch = $this->normalizeHrApplicationSearchValue($search);
        if ($normalizedSearch === '') {
            return [];
        }

        return array_values(array_filter(explode(' ', $normalizedSearch)));
    }

    private function normalizeHrApplicationSearchValue(mixed $value): string
    {
        return $this->leaveWorkflowService()->normalizeHrApplicationSearchValue($value);
    }

    private function hasHrReceivedForQueue(LeaveApplication $application): bool
    {
        return $this->leaveWorkflowService()->hasHrReceivedForQueue(
            $application,
            fn ($app) => $this->hasPendingApprovedUpdateRequest($app),
            fn ($app) => $this->resolvePendingApprovedUpdateCycleRequestedAt($app)
        );
    }

    private function hasHrReleasedForQueue(LeaveApplication $application): bool
    {
        return $this->leaveWorkflowService()->hasHrReleasedForQueue(
            $application,
            fn ($app) => $this->resolveLatestApprovedUpdateCycleRequestedAt($app)
        );
    }

    private function hasCmoCbmoReviewedForQueue(LeaveApplication $application): bool
    {
        return $this->leaveWorkflowService()->hasCmoCbmoReviewedForQueue(
            $application,
            fn ($app) => $this->resolveLatestApprovedUpdateCycleRequestedAt($app)
        );
    }

    private function resolveLeaveHrQueueMeta(LeaveApplication $application): array
    {
        return $this->leaveWorkflowService()->resolveLeaveHrQueueMeta(
            $application,
            fn (LeaveApplication $app) => $this->hasHrReceivedForQueue($app),
            fn (LeaveApplication $app) => $this->hasHrReleasedForQueue($app),
            fn (LeaveApplication $app) => $this->hasCmoCbmoReviewedForQueue($app),
            fn (LeaveApplication $app) => $this->hasPendingApprovedUpdateRequest($app)
        );
    }

    private function sortLeaveApplicationsForHrQueue(Collection $applications): Collection
    {
        return $this->leaveWorkflowService()->sortLeaveApplicationsForHrQueue(
            $applications,
            fn (LeaveApplication $app) => $this->resolveLeaveHrQueueMeta($app)
        );
    }

    public function hrShow(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $application = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->where(function ($query): void {
                $query
                    ->where('status', '!=', LeaveApplication::STATUS_PENDING_ADMIN)
                    ->orWhere(function ($nestedQuery): void {
                        $nestedQuery
                            ->where('status', LeaveApplication::STATUS_PENDING_ADMIN)
                            ->whereHas('updateRequests', function ($updateRequestQuery): void {
                                $updateRequestQuery
                                    ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
                                    ->whereRaw(
                                        'UPPER(LTRIM(RTRIM(COALESCE(previous_status, ?)))) = ?',
                                        ['', LeaveApplication::STATUS_APPROVED]
                                    );
                            });
                    });
            })
            ->find($id);

        if (! $application) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $actorDirectory = $this->buildWorkflowActorDirectory([$application]);
        $workflowPayload = $this->formatErmsApplication($application, $actorDirectory);
        $detailPayload = $this->formatApplication($application, $actorDirectory);

        return response()->json([
            'application' => array_replace($workflowPayload, $detailPayload),
        ]);
    }

    public function hrVerifyDocument(HrVerifyLeaveApplicationDocumentRequest $request): JsonResponse
    {
        return $this->leaveWorkflowService()->executeHrVerifyDocument(
            $request->validated('token'),
            $this->documentVerificationService,
            fn ($app) => $this->isPendingApprovedUpdateRequest($app),
            fn ($r) => $this->isCancelledRemark($r),
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn ($app, $dir) => $this->formatErmsApplication($app, $dir)
        );
    }

    public function hrViewAttachment(Request $request, int $id)
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $application = LeaveApplication::query()
            ->where(function ($query): void {
                $query
                    ->where('status', '!=', LeaveApplication::STATUS_PENDING_ADMIN)
                    ->orWhere(function ($nestedQuery): void {
                        $nestedQuery
                            ->where('status', LeaveApplication::STATUS_PENDING_ADMIN)
                            ->whereHas('updateRequests', function ($updateRequestQuery): void {
                                $updateRequestQuery
                                    ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
                                    ->whereRaw(
                                        'UPPER(LTRIM(RTRIM(COALESCE(previous_status, ?)))) = ?',
                                        ['', LeaveApplication::STATUS_APPROVED]
                                    );
                            });
                    });
            })
            ->find($id);
        if (! $application) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        return $this->streamApplicationAttachment($application);
    }

    public function hrApplicationEditRequests(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can view application edit requests.'], 403);
        }

        if (! $this->isHrAccessControlOwner($hr)) {
            return response()->json(['message' => 'Only HR Admin accounts can view application edit requests.'], 403);
        }

        $status = strtoupper(trim((string) $request->query('status', LeaveApplicationUpdateRequest::STATUS_PENDING)));
        $allowedStatuses = [
            LeaveApplicationUpdateRequest::STATUS_PENDING,
            LeaveApplicationUpdateRequest::STATUS_APPROVED,
            LeaveApplicationUpdateRequest::STATUS_REJECTED,
            'ALL',
        ];
        if (! in_array($status, $allowedStatuses, true)) {
            $status = LeaveApplicationUpdateRequest::STATUS_PENDING;
        }

        $query = LeaveApplicationUpdateRequest::query()
            ->with(['leaveApplication.leaveType', 'reviewedByHr'])
            ->latest('id');

        if ($status !== 'ALL') {
            $query->where('status', $status);
        }

        $requests = $query
            ->limit(500)
            ->get()
            ->filter(fn (LeaveApplicationUpdateRequest $editRequest): bool => $this->isHrApplicationEditRequestRecord($editRequest))
            ->values();

        return response()->json([
            'requests' => $requests
                ->map(fn (LeaveApplicationUpdateRequest $editRequest): array => $this->formatHrApplicationEditRequest($editRequest))
                ->all(),
            'count' => $requests->count(),
        ]);
    }

    public function hrSaveApplicationEdit(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can edit leave applications.'], 403);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'pay_status_rows' => ['nullable', 'array', 'min:1'],
            'payStatusRows' => ['nullable', 'array', 'min:1'],
            'selected_dates' => ['nullable', 'array', 'min:1'],
            'selectedDates' => ['nullable', 'array', 'min:1'],
            'selected_date_pay_status' => ['nullable', 'array'],
            'selectedDatePayStatus' => ['nullable', 'array'],
            'selected_date_coverage' => ['nullable', 'array'],
            'selectedDateCoverage' => ['nullable', 'array'],
            'selected_date_half_day_portion' => ['nullable', 'array'],
            'selectedDateHalfDayPortion' => ['nullable', 'array'],
            'deductible_days_by_date' => ['nullable', 'array'],
            'without_pay_days_by_date' => ['nullable', 'array'],
            'allow_sl_vl_cross_deduction' => ['nullable', 'boolean'],
        ]);

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        return $this->leaveWorkflowService()->executeHrSaveApplicationEdit(
            $request,
            $hr,
            $app,
            $validated,
            fn (string $controlNo): array => $this->controlNoCandidates($controlNo),
            fn (string $controlNo): ?object => $this->findEmployeeByControlNo($controlNo),
            fn (LeaveApplication $application): array => $this->formatApplication($application),
            fn (LeaveApplicationUpdateRequest $editRequest): array => $this->formatHrApplicationEditRequest($editRequest),
            function (LeaveApplication $application): void {
                $this->lockApprovedApplicationCertificationSnapshot($application);
            },
            fn (int $adminId): ?string => $this->findAdminEmployeeControlNo($adminId)
        );
    }

    public function hrApproveApplicationEditRequest(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can approve application edit requests.'], 403);
        }

        if (! $this->isHrAccessControlOwner($hr)) {
            return response()->json(['message' => 'Only HR Admin accounts can approve application edit requests.'], 403);
        }

        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $editRequest = LeaveApplicationUpdateRequest::query()
            ->with(['leaveApplication.leaveType', 'reviewedByHr'])
            ->find($id);
        if (! $editRequest || ! $this->isHrApplicationEditRequestRecord($editRequest)) {
            return response()->json(['message' => 'Application edit request not found.'], 404);
        }

        if (strtoupper(trim((string) $editRequest->status)) !== LeaveApplicationUpdateRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending application edit requests can be approved.'], 422);
        }

        $app = $editRequest->leaveApplication;
        if (! $app instanceof LeaveApplication) {
            return response()->json(['message' => 'Leave application for this edit request no longer exists.'], 404);
        }

        if (! in_array($app->status, [LeaveApplication::STATUS_PENDING_HR, LeaveApplication::STATUS_APPROVED], true)) {
            return response()->json([
                'message' => 'Only Pending HR and Approved applications can receive an approved HR edit.',
            ], 422);
        }

        $normalizedPayload = $this->normalizeHrApplicationEditPayload(
            is_array($editRequest->requested_payload) ? $editRequest->requested_payload : [],
            $app
        );
        if ($normalizedPayload instanceof JsonResponse) {
            return $normalizedPayload;
        }

        $applied = $this->applyHrApplicationEdit(
            $app,
            $hr,
            $normalizedPayload,
            $this->trimNullableString($editRequest->requested_reason) ?? 'Approved HR staff edit request.',
            $editRequest,
            $this->trimNullableString($validated['remarks'] ?? null)
        );

        if ($applied instanceof JsonResponse) {
            return $applied;
        }

        $editRequest->refresh()->load(['leaveApplication.leaveType', 'reviewedByHr']);

        return response()->json([
            'message' => 'Application edit request approved and applied.',
            'edit_request' => $this->formatHrApplicationEditRequest($editRequest),
            'application' => $this->formatApplication($applied->fresh(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])),
        ]);
    }

    public function hrRejectApplicationEditRequest(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can reject application edit requests.'], 403);
        }

        if (! $this->isHrAccessControlOwner($hr)) {
            return response()->json(['message' => 'Only HR Admin accounts can reject application edit requests.'], 403);
        }

        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $editRequest = LeaveApplicationUpdateRequest::query()
            ->with(['leaveApplication.leaveType', 'reviewedByHr'])
            ->find($id);
        if (! $editRequest || ! $this->isHrApplicationEditRequestRecord($editRequest)) {
            return response()->json(['message' => 'Application edit request not found.'], 404);
        }

        if (strtoupper(trim((string) $editRequest->status)) !== LeaveApplicationUpdateRequest::STATUS_PENDING) {
            return response()->json(['message' => 'Only pending application edit requests can be rejected.'], 422);
        }

        $this->leaveWorkflowService()->executeHrRejectApplicationEditRequest(
            $editRequest,
            $hr,
            $validated['remarks'] ?? null
        );

        $editRequest->refresh()->load(['leaveApplication.leaveType', 'reviewedByHr']);

        return response()->json([
            'message' => 'Application edit request rejected.',
            'edit_request' => $this->formatHrApplicationEditRequest($editRequest),
        ]);
    }

    /**
     * HR may override WP/WOP only during the initial CHRMO certification stage.
     */
    public function hrUpdatePayStatus(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can override pay status.'], 403);
        }

        $validated = $request->validate([
            'pay_mode' => ['nullable', 'string', 'in:WP,WOP'],
            'allow_sl_vl_cross_deduction' => ['nullable', 'boolean'],
            'selected_date_pay_status' => ['nullable', 'array'],
            'selected_date_pay_status.*' => ['nullable', 'string', 'in:WP,WOP'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $rawSelectedDatePayStatus = array_key_exists('selected_date_pay_status', $validated)
            ? $validated['selected_date_pay_status']
            : $request->input('selected_date_pay_status');
        $requestedAllowSlVlCrossDeduction = $this->resolveRequestSlVlCrossDeductionFlag(
            $request,
            $validated,
            (bool) ($app->allow_sl_vl_cross_deduction ?? false)
        );
        $requestedPayMode = $this->resolveRequestedPayMode(
            $request,
            $validated,
            false,
            $app->pay_mode ?? LeaveApplication::PAY_MODE_WITH_PAY
        );

        return $this->leaveWorkflowService()->executeHrUpdatePayStatus(
            $app,
            $hr,
            is_array($rawSelectedDatePayStatus) ? $rawSelectedDatePayStatus : null,
            $requestedPayMode,
            $requestedAllowSlVlCrossDeduction,
            $request->has('allow_sl_vl_cross_deduction'),
            $request->input('remarks'),
            fn (string $no) => $this->findEmployeeByControlNo($no),
            fn (string $no) => $this->controlNoCandidates($no),
            fn (int $adminId) => $this->findAdminEmployeeControlNo($adminId),
            function (LeaveApplication $freshApplication): array {
                $actorDirectory = $this->buildWorkflowActorDirectory([$freshApplication]);

                return array_replace(
                    $this->formatErmsApplication($freshApplication, $actorDirectory),
                    $this->formatApplication($freshApplication)
                );
            }
        );
    }

    /**
     * HR confirms the hard-copy leave application form was received.
     * This action is informational only and does not change approval status.
     */
    public function hrReceive(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can confirm received applications.'], 403);
        }

        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
            'verification_token' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->leaveWorkflowService()->executeHrReceive(
            $id,
            $hr,
            $validated,
            $this->documentVerificationService,
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn ($app, $dir) => $this->formatErmsApplication($app, $dir),
            fn ($r) => $this->isCancelledRemark($r),
            fn ($app) => $this->isPendingApprovedUpdateRequest($app)
        );
    }

    /**
     * HR confirms the hard-copy leave application form was released.
     * This action is informational only and does not change approval status.
     */
    public function hrRelease(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can confirm released applications.'], 403);
        }

        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
            'verification_token' => ['nullable', 'string', 'max:255'],
        ]);

        return $this->leaveWorkflowService()->executeHrRelease(
            $id,
            $hr,
            $validated,
            $this->documentVerificationService,
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn ($app, $dir) => $this->formatErmsApplication($app, $dir),
            fn ($r) => $this->isCancelledRemark($r),
            fn ($app) => $this->isPendingApprovedUpdateRequest($app)
        );
    }

    public function hrUndoReceive(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can undo received applications.'], 403);
        }

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $cycleRequestedAt = $this->resolveCurrentHrDocumentCycleRequestedAt($app);
        $undoResult = $this->leaveWorkflowService()->undoHardCopyReceipt($app, $cycleRequestedAt);

        if (! $undoResult['ok']) {
            return response()->json([
                'message' => $undoResult['message'],
            ], 422);
        }

        $actorDirectory = $this->buildWorkflowActorDirectory([$app]);

        return response()->json([
            'message' => $undoResult['was_undone']
                ? 'Hard-copy receipt was undone.'
                : 'Hard-copy receipt is not currently confirmed for this application.',
            'application' => $this->formatErmsApplication($app, $actorDirectory),
        ]);
    }

    public function hrUndoRelease(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can undo released applications.'], 403);
        }

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $wasUndone = $this->leaveWorkflowService()->undoHardCopyRelease(
            $app,
            $this->resolveCurrentHrDocumentCycleRequestedAt($app)
        );

        $actorDirectory = $this->buildWorkflowActorDirectory([$app]);

        return response()->json([
            'message' => $wasUndone
                ? 'Hard-copy release was undone.'
                : 'Hard-copy release is not currently confirmed for this application.',
            'application' => $this->formatErmsApplication($app, $actorDirectory),
        ]);
    }

    /**
     * HR records CMO/CVMO review after HR certification and before release.
     */
    public function hrCmoCbmoReview(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can confirm CMO/CVMO review.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $reviewResult = $this->leaveWorkflowService()->confirmCmoCbmoReview(
            $app,
            $hr,
            $request->input('remarks')
        );

        if (! $reviewResult['ok']) {
            return response()->json([
                'message' => $reviewResult['message'],
            ], 422);
        }

        if (! $reviewResult['already_reviewed']) {
            $this->smsGatewayService()->sendLeaveReadyForReleaseMessage($app);
        }

        $actorDirectory = $this->buildWorkflowActorDirectory([$app]);

        return response()->json([
            'message' => $reviewResult['already_reviewed']
                ? 'CMO/CVMO review was already completed for this application.'
                : 'CMO/CVMO review completed.',
            'application' => $this->formatErmsApplication($app, $actorDirectory),
        ]);
    }

    /**
     * HR records batch/bulk CMO/CVMO review for multiple leave applications.
     */
    public function hrBulkCmoCbmoReview(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can confirm CMO/CVMO review.'], 403);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $ids = array_values(array_unique(array_filter(array_map('intval', $validated['ids']))));
        if (empty($ids)) {
            return response()->json(['message' => 'No valid application IDs provided.'], 422);
        }

        $remarks = trim((string) ($validated['remarks'] ?? '')) ?: 'CMO/CVMO review completed.';

        $applications = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->whereIn('id', $ids)
            ->get();

        $result = $this->leaveWorkflowService()->executeHrBulkCmoCbmoReview(
            $applications,
            $hr,
            $remarks,
            fn (LeaveApplication $app) => $this->smsGatewayService()->sendLeaveReadyForReleaseMessage($app)
        );

        $processedCount = $result['processed_count'];
        $actorDirectory = $this->buildWorkflowActorDirectory($applications);
        $formattedApplications = $applications->map(fn (LeaveApplication $application) => $this->formatErmsApplication($application, $actorDirectory))->values();

        return response()->json([
            'message' => "Successfully processed {$processedCount} leave application(s) for CMO/CVMO review.",
            'processed_count' => $processedCount,
            'skipped_count' => $result['skipped_count'],
            'processed_ids' => $result['processed_ids'],
            'applications' => $formattedApplications,
        ]);
    }

    /**
     * HR confirms batch/bulk release of multiple leave applications.
     */
    public function hrBulkRelease(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can confirm released applications.'], 403);
        }

        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer', 'min:1'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $ids = array_values(array_unique(array_filter(array_map('intval', $validated['ids']))));
        if (empty($ids)) {
            return response()->json(['message' => 'No valid application IDs provided.'], 422);
        }

        $remarks = trim((string) ($validated['remarks'] ?? '')) ?: 'Released hard copy leave application form.';

        $applications = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->whereIn('id', $ids)
            ->get();

        $result = $this->leaveWorkflowService()->executeHrBulkRelease(
            $applications,
            $hr,
            $remarks
        );

        $processedCount = $result['processed_count'];
        $actorDirectory = $this->buildWorkflowActorDirectory($applications);
        $formattedApplications = $applications->map(fn (LeaveApplication $application) => $this->formatErmsApplication($application, $actorDirectory))->values();

        return response()->json([
            'message' => "Successfully released {$processedCount} leave application(s).",
            'processed_count' => $processedCount,
            'skipped_count' => $result['skipped_count'],
            'processed_ids' => $result['processed_ids'],
            'applications' => $formattedApplications,
        ]);
    }

    /**
     * HR confirms receipt of the updated hard-copy leave application form
     * for the active approved-update request cycle.
     */
    public function hrReceiveUpdate(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can confirm received applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        return $this->leaveWorkflowService()->executeHrReceiveUpdate(
            $app,
            $hr,
            $request->input('remarks'),
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn ($a, $dir) => $this->formatErmsApplication($a, $dir),
            fn ($a) => $this->resolvePendingUpdateMeta($a)
        );
    }

    /**
     * HR confirms release of the updated hard-copy leave application form
     * for the latest approved update-request cycle.
     */
    public function hrReleaseUpdate(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can confirm released applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        return $this->leaveWorkflowService()->executeHrReleaseUpdate(
            $app,
            $hr,
            $request->input('remarks'),
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn ($a, $dir) => $this->formatErmsApplication($a, $dir),
            fn ($a) => $this->resolveLatestUpdateMeta($a)
        );
    }

    /**
     * HR approves → status becomes APPROVED.
     * If leave type is credit-based OR is monetization, deduct from leave_balances inside a transaction.
     */
    public function hrApprove(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can approve applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::with('leaveType')->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if ($app->status !== LeaveApplication::STATUS_PENDING_HR) {
            return response()->json(['message' => "Cannot approve: application status is '{$app->status}', expected 'PENDING_HR'."], 422);
        }

        if ($this->isPendingApprovedUpdateRequest($app)) {
            return $this->hrApprovePendingUpdateRequest($request, $app, $hr);
        }

        return $this->leaveWorkflowService()->executeHrApprove(
            $app,
            $hr,
            $request->input('remarks'),
            fn (LeaveApplication $a) => $this->resolveApplicationEmployee($a),
            fn (string $no) => $this->controlNoCandidates($no),
            fn (int $adminId) => $this->findAdminEmployeeControlNo($adminId),
            fn (string $no) => $this->findEmployeeByControlNo($no),
            fn (LeaveApplication $a) => $this->lockCertificationLeaveCreditsSnapshot($a),
            fn (LeaveApplication $a) => $this->smsGatewayService()->sendLeaveApprovedMessage($a),
            fn (LeaveApplication $a) => $this->formatApplication($a)
        );
    }

    /**
     * HR rejects → status becomes REJECTED.
     */
    public function hrReject(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can reject applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        return $this->leaveWorkflowService()->executeHrReject(
            $app,
            $hr,
            $request->input('remarks'),
            fn (LeaveApplication $a) => $this->smsGatewayService()->sendLeaveRejectedMessage($a),
            fn ($freshApp) => $this->formatApplication($freshApp),
            fn ($pendingApp) => $this->hrRejectPendingUpdateRequest($request, $pendingApp, $hr)
        );
    }

    /**
     * HR recalls an already approved leave application.
     */
    public function hrRecall(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can recall applications.'], 403);
        }

        $validated = $request->validate([
            'recall_reason' => ['required', 'string', 'max:2000'],
            'recall_selected_dates' => ['required', 'array', 'min:1'],
            'recall_selected_dates.*' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::with('leaveType')->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if ($this->hasPendingApprovedUpdateRequest($app)) {
            return response()->json([
                'message' => 'Cannot recall while there is a pending approved update request for this application.',
            ], 422);
        }

        $reason = trim((string) ($validated['recall_reason'] ?? $validated['remarks'] ?? ''));
        $recallSelectedDates = is_array($validated['recall_selected_dates'] ?? null)
            ? $validated['recall_selected_dates']
            : [];

        return $this->executeHrRecall($app, $hr, $reason, $recallSelectedDates);
    }

    private function executeHrRecall(
        LeaveApplication $app,
        HRAccount $hr,
        string $reason,
        array $requestedRecallDates,
        ?LeaveApplicationUpdateRequest $pendingUpdateRequest = null,
        mixed $pendingReviewRemarks = null
    ): JsonResponse {
        return $this->leaveWorkflowService()->executeHrRecall(
            $app,
            $hr,
            $reason,
            $requestedRecallDates,
            $pendingUpdateRequest,
            $pendingReviewRemarks,
            fn (LeaveApplication $freshApp) => $this->formatApplication($freshApp)
        );
    }

    // ─── Admin: List department employees for apply-leave form ────────

    /**
     * Return employees in the admin's department with leave types & balances.
     * Powers the admin "Apply Leave" form dropdowns.
     */
    public function adminEmployees(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (! $admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $admin->loadMissing('department');
        $deptName = $admin->department?->name;
        $hrisEmployees = HrisEmployee::allByOffice($deptName, null);
        $leaveBalanceLookup = $this->buildLeaveBalanceLookupForEmployees($hrisEmployees);

        $employees = $hrisEmployees->map(function ($employee) use ($leaveBalanceLookup): array {
            $controlNo = trim((string) ($employee->control_no ?? ''));
            $fullName = trim(implode(' ', array_filter([
                trim((string) ($employee->firstname ?? '')),
                trim((string) ($employee->middlename ?? '')),
                trim((string) ($employee->surname ?? '')),
            ])));
            $normalizedControlNo = $this->normalizeControlNo($controlNo);
            $leaveBalances = $leaveBalanceLookup[$normalizedControlNo] ?? [];

            return [
                'control_no' => $controlNo,
                'full_name' => $fullName,
                'firstname' => $employee->firstname ?? null,
                'surname' => $employee->surname ?? null,
                'status' => $this->formatEmploymentStatusLabel($employee->status ?? null),
                'raw_status' => $this->trimNullableString($employee->status ?? null),
                'employment_status_key' => $this->resolveEmploymentStatusKey($employee->status ?? null),
                'activity_status' => strtoupper(trim((string) ($employee->activity_status ?? 'INACTIVE'))),
                'is_active' => (bool) ($employee->is_active ?? false),
                'designation' => $employee->designation ?? null,
                'office' => $employee->office ?? null,
                'salary' => $employee->rate_mon !== null ? (float) $employee->rate_mon : null,
                'rate_mon' => $employee->rate_mon !== null ? (float) $employee->rate_mon : null,
                'leave_balances' => collect($leaveBalances)->map(fn (LeaveBalance $leaveBalance) => [
                    'leave_type_id' => $leaveBalance->leave_type_id,
                    'leave_type_name' => trim((string) ($leaveBalance->leave_type_name ?? $leaveBalance->leaveType?->name ?? '')),
                    'balance' => (float) $leaveBalance->balance,
                ])->values(),
            ];
        })->values();

        $leaveTypes = LeaveType::orderBy('name')->get()->map(fn ($lt) => [
            'id' => $lt->id,
            'name' => $lt->name,
            'is_credit_based' => $lt->is_credit_based,
            'max_days' => $lt->max_days,
            'requires_documents' => (bool) $lt->requires_documents,
            'allowed_status' => $lt->normalizedAllowedStatuses(),
            'allowed_status_labels' => $lt->allowedStatusLabels(),
        ]);

        return response()->json([
            'employees' => $employees,
            'leave_types' => $leaveTypes,
        ]);
    }

    // ─── Admin: File leave on behalf of an employee ──────────────────

    /**
     * Admin submits a leave application on behalf of an employee in their
     * department. Skips admin-approval step → status goes to PENDING_HR.
     */
    public function adminStore(Request $request): JsonResponse
    {
        $this->normalizeSelectedDatesInput($request);
        $this->normalizeSelectedDatePolicyInput($request);

        $admin = $request->user();
        if (! $admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can file leave on behalf of employees.'], 403);
        }

        // Detect monetization request
        $isMonetization = (bool) $request->input('is_monetization', false);

        if ($isMonetization) {
            return $this->adminStoreMonetization($request, $admin);
        }

        $isTerminalLeave = $this->isTerminalLeaveTypeId(
            $this->resolveCanonicalLeaveTypeId((int) $request->input('leave_type_id'))
                ?? (int) $request->input('leave_type_id')
        );

        $validated = $request->validate([
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'start_date' => $isTerminalLeave ? ['nullable', 'date'] : ['required', 'date'],
            'end_date' => $isTerminalLeave ? ['nullable', 'date'] : ['required', 'date', 'after_or_equal:start_date'],
            'total_days' => ['required', 'numeric', 'min:0.5', 'max:'.($isTerminalLeave ? '999' : '365')],
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
            'allow_sl_vl_cross_deduction' => ['nullable', 'boolean'],
            'attachment' => ['nullable', 'file', 'max:10240'],
            'attachment_submitted' => ['nullable', 'boolean'],
            'attachment_attached' => ['nullable', 'boolean'],
            'has_attachment' => ['nullable', 'boolean'],
            'with_attachment' => ['nullable', 'boolean'],
            'attachment_reference' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->leaveWorkflowService()->executeRegularLeaveSubmission(
            $request,
            $validated,
            $admin,
            null,
            true,
            fn (string $value): array => $this->controlNoCandidates($value),
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    // ─── Admin: File monetization on behalf of an employee ─────────────

    /**
     * Handle monetization filed by admin on behalf of an employee.
     */
    private function adminStoreMonetization(Request $request, DepartmentAdmin $admin): JsonResponse
    {
        $validated = $request->validate([
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'total_days' => ['required', 'numeric', 'min:'.LeaveApplication::MONETIZATION_MINIMUM_REQUEST_DAYS, 'max:999'],
            'monetization_leave_credits' => ['nullable', 'array', 'min:1', 'max:2'],
            'monetization_leave_credits.*.leave_type_id' => ['required_with:monetization_leave_credits', 'integer', 'exists:tblLeaveTypes,id'],
            'monetization_leave_credits.*.days' => ['required_with:monetization_leave_credits', 'numeric', 'min:0', 'max:999'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'details_of_leave' => ['nullable', 'string', 'max:2000'],
            'salary' => ['nullable', 'numeric', 'min:0'],
            'attachment' => ['nullable', 'file', 'max:10240'],
            'attachment_submitted' => ['nullable', 'boolean'],
            'attachment_attached' => ['nullable', 'boolean'],
            'has_attachment' => ['nullable', 'boolean'],
            'with_attachment' => ['nullable', 'boolean'],
            'attachment_reference' => ['nullable', 'string', 'max:500'],
        ]);

        return $this->leaveWorkflowService()->executeMonetizationSubmission(
            $request,
            $validated,
            $admin,
            null,
            true,
            fn (string $value): array => $this->controlNoCandidates($value),
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Calculate max consecutive days from an array of date strings.
     *
     * @deprecated Use LeaveValidationService instead.
     */
    private function statusToFrontend(string $status): string
    {
        return $this->leaveWorkflowService()->statusToFrontend($status);
    }

    /**
     * Format total days for display: remove trailing .00, proper singular/plural.
     * e.g. 4.00 → "4 days", 1.00 → "1 day", 0.50 → "0.5 days"
     */
    private static function formatDays($days): string
    {
        return LeaveWorkflowService::formatDays($days);
    }

    private function findEmployeeByControlNo(string $controlNo): ?object
    {
        $controlNo = trim($controlNo);
        if ($controlNo === '') {
            return null;
        }

        return HrisEmployee::findByControlNo($controlNo);
    }

    private function assertErmsEmployeeIsPulled(string $controlNo): ?JsonResponse
    {
        return $this->leaveEligibilityService()->assertErmsEmployeeIsPulled($controlNo, fn (string $no): array => $this->controlNoCandidates($no));
    }

    private function isPulledEmployeeControlNo(string $controlNo): bool
    {
        return $this->leaveEligibilityService()->isPulledEmployeeControlNo($controlNo, fn (string $no): array => $this->controlNoCandidates($no));
    }

    private function isDepartmentAdminEmployeeControlNo(string $controlNo): bool
    {
        return $this->leaveEligibilityService()->isDepartmentAdminEmployeeControlNo($controlNo, fn (string $no): array => $this->controlNoCandidates($no));
    }

    private function resolveLatestAssignedDepartmentIdForControlNo(?string $controlNo): ?int
    {
        return $this->leaveEligibilityService()->resolveLatestAssignedDepartmentIdForControlNo($controlNo);
    }

    private function findDepartmentEmployeeControlNos(
        ?string $departmentName,
        ?bool $activeOnly = null,
        ?int $departmentId = null
    ): array {
        return $this->leaveEligibilityService()->findDepartmentEmployeeControlNos($departmentName, $activeOnly, $departmentId);
    }

    /**
     * @param  Collection<int, object>  $hrisEmployees
     * @return array<string, array<int, LeaveBalance>>
     */
    private function buildLeaveBalanceLookupForEmployees(Collection $hrisEmployees): array
    {
        return $this->leaveEligibilityService()->buildLeaveBalanceLookupForEmployees($hrisEmployees);
    }

    private function controlNoCandidates(string $controlNo): array
    {
        return $this->leaveEligibilityService()->controlNoCandidates($controlNo, fn (string $no): ?object => $this->findEmployeeByControlNo($no));
    }

    private function resolveApplicationEmployee(LeaveApplication $app): ?object
    {
        if ($app->employee_control_no === null) {
            return null;
        }

        return $this->findEmployeeByControlNo((string) $app->employee_control_no);
    }

    private function applyApplicationOwnershipFilter($query, string $controlNo): void
    {
        $controlNoCandidates = $this->controlNoCandidates($controlNo);
        if ($controlNoCandidates === []) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function ($nestedQuery) use ($controlNoCandidates): void {
            $nestedQuery->whereIn('employee_control_no', $controlNoCandidates)
                ->orWhereHas('applicantAdmin', function ($adminQuery) use ($controlNoCandidates): void {
                    $adminQuery->whereIn('employee_control_no', $controlNoCandidates);
                });
        });
    }

    private function findAdminEmployeeControlNo(int $adminId): ?string
    {
        if ($adminId <= 0) {
            return null;
        }

        $admin = DepartmentAdmin::query()->find($adminId);
        if (! $admin) {
            return null;
        }

        $rawControlNo = trim((string) $admin->employee_control_no);
        if ($rawControlNo === '') {
            return null;
        }

        $employee = $this->findEmployeeByControlNo($rawControlNo);

        return trim((string) ($employee?->control_no ?? $rawControlNo));
    }

    private function resolveCanonicalLeaveTypeId(?int $leaveTypeId): ?int
    {
        return $this->leaveCreditService()->resolveCanonicalLeaveTypeId($leaveTypeId);
    }

    private function resolveBalanceLookupLeaveTypeIds(int $leaveTypeId): array
    {
        return $this->leaveCreditService()->resolveBalanceLookupLeaveTypeIds($leaveTypeId);
    }

    private function mapLeaveBalancesByCanonicalTypeId(iterable $balances): array
    {
        return $this->leaveCreditService()->mapLeaveBalancesByCanonicalTypeId($balances);
    }

    private function findPreferredEmployeeLeaveBalanceRecord(
        string $employeeControlNo,
        int $leaveTypeId,
        bool $lockForUpdate = false
    ): ?LeaveBalance {
        return $this->leaveCreditService()->findPreferredEmployeeLeaveBalanceRecord(
            $employeeControlNo,
            $leaveTypeId,
            $lockForUpdate,
            fn (string $c) => $this->controlNoCandidates($c)
        );
    }

    private function formatErmsEmployeeContext(object $employee): array
    {
        $statusKey = $this->resolveEmploymentStatusKey($employee->status ?? null);
        $resolvedSchedule = $this->workScheduleService()->resolveScheduleForEmployee(
            (string) ($employee->control_no ?? '')
        );

        return [
            'control_no' => (string) ($employee->control_no ?? ''),
            'firstname' => $employee->firstname ?? null,
            'surname' => $employee->surname ?? null,
            'middlename' => $employee->middlename ?? null,
            'office' => $employee->office ?? null,
            'designation' => $employee->designation ?? null,
            'status' => $this->formatEmploymentStatusLabel($employee->status ?? null),
            'raw_status' => $this->trimNullableString($employee->status ?? null),
            'employment_status_key' => $statusKey,
            'ui_variant' => $statusKey === LeaveType::EMPLOYMENT_STATUS_CONTRACTUAL ? 'contractual' : 'default',
            'allowed_leave_scope' => $this->employeeHasResolvedEmploymentStatus($employee) ? 'configured' : 'unverified',
            'is_contractual' => $statusKey === LeaveType::EMPLOYMENT_STATUS_CONTRACTUAL,
            'working_hours_per_day' => round((float) ($resolvedSchedule['working_hours_per_day'] ?? WorkScheduleService::STANDARD_WORKDAY_HOURS), 2),
            'whole_day_leave_deduction' => round((float) ($resolvedSchedule['whole_day_leave_deduction'] ?? 1.0), 3),
            'half_day_leave_deduction' => round((float) ($resolvedSchedule['half_day_leave_deduction'] ?? 0.5), 3),
        ];
    }

    private function getAllowedErmsLeaveTypesForEmployee(object $employee)
    {
        return LeaveType::query()
            ->withoutLegacySpecialPrivilegeAliases()
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'category',
                'max_days',
                'is_credit_based',
                'requires_documents',
                'allowed_status',
            ])
            ->filter(fn (LeaveType $leaveType): bool => ! $this->employeeHasResolvedEmploymentStatus($employee)
                || $leaveType->allowsEmploymentStatus($employee->status ?? null))
            ->map(fn (LeaveType $leaveType): array => [
                'id' => (int) $leaveType->id,
                'name' => $leaveType->name,
                'category' => $leaveType->category,
                'max_days' => $leaveType->max_days !== null ? (int) $leaveType->max_days : null,
                'is_credit_based' => (bool) $leaveType->is_credit_based,
                'requires_documents' => (bool) $leaveType->requires_documents,
                'allowed_status' => $leaveType->normalizedAllowedStatuses(),
                'allowed_status_labels' => $leaveType->allowedStatusLabels(),
            ])
            ->values();
    }

    private function assertEmployeeCanAccessLeaveTypeBalance(object $employee, LeaveType $leaveType): ?JsonResponse
    {
        return $this->leaveEligibilityService()->assertEmployeeCanAccessLeaveTypeBalance($employee, $leaveType);
    }

    private function employeeHasResolvedEmploymentStatus(object $employee): bool
    {
        return $this->leaveEligibilityService()->employeeHasResolvedEmploymentStatus($employee);
    }

    private function resolveEmploymentStatusKey(?string $status): ?string
    {
        return $this->leaveEligibilityService()->resolveEmploymentStatusKey($status);
    }

    private function formatEmploymentStatusLabel(?string $status): ?string
    {
        return $this->leaveEligibilityService()->formatEmploymentStatusLabel($status);
    }

    private function trimNullableString(mixed $value): ?string
    {
        return $this->leaveWorkflowService()->trimNullableString($value);
    }

    private function isHrAccessControlOwner(HRAccount $hr): bool
    {
        return $this->leaveWorkflowService()->isHrAccessControlOwner($hr);
    }

    private function normalizeHrApplicationEditPayload(array $input, LeaveApplication $app): array|JsonResponse
    {
        return $this->leaveWorkflowService()->normalizeHrApplicationEditPayload($input, $app);
    }

    private function isHrApplicationEditRequestRecord(LeaveApplicationUpdateRequest $editRequest): bool
    {
        return $this->leaveWorkflowService()->isHrApplicationEditRequestRecord($editRequest);
    }

    private function isHrApplicationEditWorkflowLog(LeaveApplicationLog $log): bool
    {
        return $this->leaveWorkflowService()->isHrApplicationEditWorkflowLog($log);
    }

    private function formatHrApplicationEditRequest(LeaveApplicationUpdateRequest $editRequest): array
    {
        return $this->leaveWorkflowService()->formatHrApplicationEditRequest(
            $editRequest,
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    private function applyHrApplicationEdit(
        LeaveApplication $app,
        HRAccount $hr,
        array $payload,
        ?string $reason,
        ?LeaveApplicationUpdateRequest $editRequest = null,
        ?string $reviewRemarks = null
    ): LeaveApplication|JsonResponse {
        return $this->leaveWorkflowService()->applyHrApplicationEdit(
            $app,
            $hr,
            $payload,
            $reason,
            $editRequest,
            $reviewRemarks,
            fn (string $no) => $this->controlNoCandidates($no),
            fn (int $adminId) => $this->findAdminEmployeeControlNo($adminId),
            fn (string $no) => $this->findEmployeeByControlNo($no)
        );
    }

    private function formatErmsLeaveBalancePayload(
        LeaveType $leaveType,
        ?LeaveBalance $balance,
        array $deductionHistory = [],
        ?float $ctoBalanceHours = null
    ): array {
        $accrualHistory = [];
        if ($balance) {
            $historyEntries = $balance->relationLoaded('accrualHistories')
                ? $balance->accrualHistories
                : $balance->accrualHistories()->get();

            $accrualHistory = $historyEntries
                ->map(function (LeaveBalanceAccrualHistory $entry): array {
                    return [
                        'accrual_date' => $entry->accrual_date?->toDateString(),
                        'transaction_date' => $entry->accrual_date?->toDateString(),
                        'credits_added' => (float) $entry->credits_added,
                        'entry_type' => 'ACCRUAL',
                        'transaction_type' => 'ACCRUAL',
                        'label' => 'Monthly accrual',
                        'description' => 'Monthly accrual',
                        'source' => $entry->source,
                        'created_at' => $entry->created_at?->toIso8601String(),
                    ];
                })
                ->values()
                ->all();
        }

        $creditHistory = array_merge($accrualHistory, $deductionHistory);
        usort($creditHistory, function (array $left, array $right): int {
            $leftDate = (string) ($left['transaction_date'] ?? $left['accrual_date'] ?? $left['created_at'] ?? '');
            $rightDate = (string) ($right['transaction_date'] ?? $right['accrual_date'] ?? $right['created_at'] ?? '');
            if ($leftDate !== $rightDate) {
                return $leftDate < $rightDate ? 1 : -1;
            }

            $leftCreatedAt = (string) ($left['created_at'] ?? '');
            $rightCreatedAt = (string) ($right['created_at'] ?? '');
            if ($leftCreatedAt !== $rightCreatedAt) {
                return $leftCreatedAt < $rightCreatedAt ? 1 : -1;
            }

            return ((int) ($right['leave_application_id'] ?? 0)) <=> ((int) ($left['leave_application_id'] ?? 0));
        });

        $resolvedBalanceHours = $this->isCtoLeaveType($leaveType, (int) $leaveType->id)
            ? (
                $ctoBalanceHours !== null
                    ? round(max((float) $ctoBalanceHours, 0.0), 2)
                    : $this->resolveCtoDeductedHours((float) ($balance?->balance ?? 0.0))
            )
            : null;

        return [
            'leave_type_id' => (int) $leaveType->id,
            'leave_type_name' => $leaveType->name,
            'balance' => $balance ? (float) $balance->balance : 0.0,
            'balance_hours' => $resolvedBalanceHours,
            'available_balance_hours' => $resolvedBalanceHours,
            'is_credit_based' => (bool) $leaveType->is_credit_based,
            'is_accrued' => $leaveType->category === LeaveType::CATEGORY_ACCRUED,
            'accrual_rate' => $leaveType->accrual_rate !== null ? (float) $leaveType->accrual_rate : null,
            'accrual_day_of_month' => $leaveType->accrual_day_of_month !== null ? (int) $leaveType->accrual_day_of_month : null,
            'last_accrual_date' => $balance?->last_accrual_date?->toDateString(),
            'accrual_history' => $accrualHistory,
            'accrualHistory' => $accrualHistory,
            'credit_history' => $creditHistory,
            'creditHistory' => $creditHistory,
            'updated_at' => $balance?->updated_at?->toIso8601String(),
            'year' => $balance?->year !== null ? (int) $balance->year : null,
        ];
    }

    private function buildErmsAccruedLeaveCard(
        array $typesByName,
        array $balanceRecordsByType,
        array $deductionHistoryByType,
        string $leaveTypeName
    ): array {
        $type = $typesByName[strtolower(trim($leaveTypeName))] ?? null;
        if (! $type instanceof LeaveType) {
            return $this->emptyErmsLeaveBalancePayload($leaveTypeName);
        }

        $balance = $balanceRecordsByType[(int) $type->id] ?? null;

        return $this->formatErmsLeaveBalancePayload(
            $type,
            $balance instanceof LeaveBalance ? $balance : null,
            $deductionHistoryByType[(int) $type->id] ?? []
        );
    }

    private function buildErmsLatestAccruedCreditsPayload(
        object $employee,
        array $typesByName,
        array $balanceRecordsByType,
        array $deductionHistoryByType
    ): array {
        $isContractual = $this->resolveEmploymentStatusKey($employee->status ?? null)
            === LeaveType::EMPLOYMENT_STATUS_CONTRACTUAL;

        return [
            'vacation' => $isContractual
                ? $this->emptyErmsLeaveBalancePayload('Vacation Leave')
                : $this->buildErmsAccruedLeaveCard(
                    $typesByName,
                    $balanceRecordsByType,
                    $deductionHistoryByType,
                    'Vacation Leave'
                ),
            'sick' => $isContractual
                ? $this->emptyErmsLeaveBalancePayload('Sick Leave')
                : $this->buildErmsAccruedLeaveCard(
                    $typesByName,
                    $balanceRecordsByType,
                    $deductionHistoryByType,
                    'Sick Leave'
                ),
            'wellness' => $this->buildErmsAccruedLeaveCard(
                $typesByName,
                $balanceRecordsByType,
                $deductionHistoryByType,
                'Wellness Leave'
            ),
        ];
    }

    private function emptyErmsLeaveBalancePayload(string $leaveTypeName): array
    {
        return [
            'leave_type_id' => null,
            'leave_type_name' => $leaveTypeName,
            'balance' => 0.0,
            'is_credit_based' => null,
            'is_accrued' => null,
            'accrual_rate' => null,
            'accrual_day_of_month' => null,
            'last_accrual_date' => null,
            'accrual_history' => [],
            'accrualHistory' => [],
            'credit_history' => [],
            'creditHistory' => [],
            'updated_at' => null,
            'year' => null,
        ];
    }

    private function mergeCreditHistoriesByType(array ...$historyGroups): array
    {
        $merged = [];

        foreach ($historyGroups as $group) {
            foreach ($group as $typeId => $entries) {
                $normalizedTypeId = (int) $typeId;
                if ($normalizedTypeId <= 0 || ! is_array($entries) || $entries === []) {
                    continue;
                }

                if (! array_key_exists($normalizedTypeId, $merged)) {
                    $merged[$normalizedTypeId] = [];
                }

                foreach ($entries as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    $merged[$normalizedTypeId][] = $entry;
                }
            }
        }

        return $merged;
    }

    private function loadEmployeeLeaveDeductionHistoryByType(string $controlNo, ?int $leaveTypeId = null): array
    {
        $lookupLeaveTypeIds = $leaveTypeId !== null
            ? $this->resolveBalanceLookupLeaveTypeIds($leaveTypeId)
            : [];

        $applications = LeaveApplication::query()
            ->with(['leaveType:id,name,is_credit_based'])
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->whereIn('employee_control_no', $this->controlNoCandidates($controlNo))
            ->when($leaveTypeId !== null, function ($query) use ($leaveTypeId, $lookupLeaveTypeIds): void {
                $query->whereIn(
                    'leave_type_id',
                    $lookupLeaveTypeIds !== [] ? $lookupLeaveTypeIds : [$leaveTypeId]
                );
            })
            ->orderByDesc('hr_approved_at')
            ->orderByDesc('id')
            ->get([
                'id',
                'employee_control_no',
                'leave_type_id',
                'total_days',
                'deductible_days',
                'pay_mode',
                'is_monetization',
                'status',
                'hr_approved_at',
                'created_at',
            ]);

        $historyByType = [];

        foreach ($applications as $application) {
            if (! $application instanceof LeaveApplication) {
                continue;
            }

            $typeId = $this->resolveCanonicalLeaveTypeId((int) $application->leave_type_id)
                ?? (int) $application->leave_type_id;
            if ($typeId <= 0) {
                continue;
            }

            if ($this->isCtoLeaveType($application->leaveType, $typeId)) {
                continue;
            }

            $deductsEmployeeBalance = $this->applicationDeductsEmployeeBalance(
                (bool) $application->is_monetization,
                $application->leaveType,
                $application->pay_mode
            );
            if (! $deductsEmployeeBalance) {
                continue;
            }

            $approvedAt = $application->hr_approved_at ?? $application->created_at;
            $creditsDeducted = round((float) ($application->deductible_days ?? $application->total_days ?? 0), 3);
            if ($approvedAt === null || $creditsDeducted <= 0) {
                continue;
            }

            $historyByType[$typeId][] = [
                'accrual_date' => $approvedAt->toDateString(),
                'transaction_date' => $approvedAt->toDateString(),
                'credits_added' => -$creditsDeducted,
                'entry_type' => 'DEDUCTION',
                'transaction_type' => 'DEDUCTION',
                'label' => $application->is_monetization ? 'Monetization approved' : 'Leave approved',
                'description' => $application->is_monetization
                    ? 'Approved monetization'
                    : 'Approved leave application',
                'application_id' => (int) $application->id,
                'leave_application_id' => (int) $application->id,
                'source' => 'LEAVE_APPLICATION',
                'created_at' => $approvedAt->toIso8601String(),
            ];
        }

        return $historyByType;
    }

    private function loadEmployeeCOCCreditHistoryByType(string $controlNo, ?int $leaveTypeId = null): array
    {
        return $this->cocLedgerService()->getHistoryByLeaveType($controlNo, $leaveTypeId);
    }

    private function ermsStatusLabel(string $status): string
    {
        return $this->leaveWorkflowService()->ermsStatusLabel($status);
    }

    private function resolveEmployeeDisplayName(LeaveApplication $app): string
    {
        return $this->leaveWorkflowService()->resolveEmployeeDisplayName(
            $app,
            fn (string $controlNo): ?object => $this->findEmployeeByControlNo($controlNo)
        );
    }

    private function adminCanManageApplication(DepartmentAdmin $admin, LeaveApplication $app): bool
    {
        return $this->leaveWorkflowService()->adminCanManageApplication(
            $admin,
            $app,
            fn (string $controlNo) => $this->resolveLatestAssignedDepartmentIdForControlNo($controlNo),
            fn (LeaveApplication $application) => $this->resolveApplicationEmployee($application)
        );
    }

    private function normalizeControlNo(mixed $value): string
    {
        return $this->leaveEligibilityService()->normalizeControlNo($value);
    }

    private function isDepartmentHeadRecord(?string $controlNo): bool
    {
        $normalizedControlNo = $this->normalizeControlNo($controlNo);
        if ($normalizedControlNo === '') {
            return false;
        }

        $departmentHeads = DepartmentHead::query()->get(['control_no']);
        foreach ($departmentHeads as $head) {
            $headControlNo = $this->normalizeControlNo((string) $head->control_no);
            if ($headControlNo !== '' && $headControlNo === $normalizedControlNo) {
                return true;
            }
        }

        return false;
    }

    private function formatEmployeeFullName(?object $employee): string
    {
        return LeaveWorkflowService::formatEmployeeFullName($employee);
    }

    private function buildWorkflowActorDirectory(iterable $applications): array
    {
        return $this->leaveWorkflowService()->buildWorkflowActorDirectory(
            $applications,
            fn ($app) => $this->resolveApplicationEmployee($app),
            fn ($val) => $this->normalizeControlNo($val)
        );
    }

    private function resolveWorkflowPerformerName(
        ?LeaveApplicationLog $log,
        array $actorDirectory,
        string $fallbackEmployeeName = ''
    ): ?string {
        return $this->leaveWorkflowService()->resolveWorkflowPerformerName(
            $log,
            $actorDirectory,
            $fallbackEmployeeName,
            fn ($val) => $this->normalizeControlNo($val)
        );
    }

    private function isCancelledRemark(mixed $remarks): bool
    {
        return $this->leaveWorkflowService()->isCancelledRemark($remarks);
    }

    private function extractCancellationReason(mixed $remarks): ?string
    {
        return $this->leaveWorkflowService()->extractCancellationReason($remarks);
    }

    private function mapWorkflowLogStage(LeaveApplicationLog $log): string
    {
        return $this->leaveWorkflowService()->mapWorkflowLogStage($log);
    }

    private function formatErmsApplication(LeaveApplication $app, array $actorDirectory): array
    {
        return $this->leaveWorkflowService()->formatErmsApplication(
            $app,
            $actorDirectory,
            fn (string $no): ?object => $this->findEmployeeByControlNo($no)
        );
    }

    private function hasPendingApprovedUpdateRequest(LeaveApplication $app): bool
    {
        return $this->leaveWorkflowService()->hasPendingApprovedUpdateRequest(
            $app,
            fn ($a) => $this->resolvePendingUpdateMeta($a)
        );
    }

    private function shouldPresentApprovedWhilePendingUpdate(
        LeaveApplication $app,
        bool $hasPendingApprovedUpdateRequest
    ): bool {
        return $this->leaveWorkflowService()->shouldPresentApprovedWhilePendingUpdate(
            $app,
            $hasPendingApprovedUpdateRequest,
            fn ($a) => $this->resolvePendingUpdateMeta($a)
        );
    }

    private function resolveUpdateRequestActionTypeFromPayload(mixed $payload): string
    {
        return $this->leaveWorkflowService()->resolveUpdateRequestActionTypeFromPayload($payload);
    }

    private function resolvePendingApprovedUpdateActionType(LeaveApplication $app): string
    {
        return $this->leaveWorkflowService()->resolvePendingApprovedUpdateActionType(
            $app,
            fn ($a) => $this->resolvePendingUpdateMeta($a)
        );
    }

    private function isPendingApprovedUpdateRequest(LeaveApplication $app): bool
    {
        return $this->leaveWorkflowService()->isPendingApprovedUpdateRequest(
            $app,
            fn ($a) => $this->hasPendingApprovedUpdateRequest($a)
        );
    }

    private function hrApprovePendingUpdateRequest(Request $request, LeaveApplication $app, HRAccount $hr): JsonResponse
    {
        return $this->leaveWorkflowService()->executeHrApprovePendingUpdateRequest(
            $app,
            $hr,
            $request->input('remarks'),
            fn (LeaveApplication $a) => $this->resolveApplicationEmployee($a),
            fn (string $no) => $this->controlNoCandidates($no),
            fn (int $adminId) => $this->findAdminEmployeeControlNo($adminId),
            fn (string $no) => $this->findEmployeeByControlNo($no),
            fn (LeaveApplication $a) => $this->lockCertificationLeaveCreditsSnapshot($a),
            fn (LeaveApplication $a) => $this->smsGatewayService()->sendLeaveApprovedMessage($a),
            fn (LeaveApplication $a) => $this->formatApplication($a)
        );
    }

    private function hrRejectPendingUpdateRequest(Request $request, LeaveApplication $app, HRAccount $hr): JsonResponse
    {
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);

        return $this->leaveWorkflowService()->executeHrRejectPendingUpdateRequest(
            $app,
            $hr,
            $request->input('remarks'),
            $pendingUpdateMeta,
            fn (LeaveApplication $freshApp) => $this->formatApplication($freshApp)
        );
    }

    private function adminRejectPendingUpdateRequest(
        Request $request,
        LeaveApplication $app,
        DepartmentAdmin $admin
    ): JsonResponse {
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);

        return $this->leaveWorkflowService()->executeAdminRejectPendingUpdateRequest(
            $app,
            $admin,
            $request->input('remarks'),
            $pendingUpdateMeta,
            fn (LeaveApplication $freshApp) => $this->formatApplication($freshApp)
        );
    }

    private function resolvePendingApprovedUpdateCycleRequestedAt(LeaveApplication $app): ?\DateTimeInterface
    {
        return $this->leaveWorkflowService()->resolvePendingApprovedUpdateCycleRequestedAt(
            $app,
            fn ($a) => $this->resolvePendingUpdateMeta($a)
        );
    }

    private function resolveLatestApprovedUpdateCycleRequestedAt(LeaveApplication $app): ?\DateTimeInterface
    {
        return $this->leaveWorkflowService()->resolveLatestApprovedUpdateCycleRequestedAt(
            $app,
            fn ($a) => $this->resolveLatestUpdateMeta($a)
        );
    }

    private function resolveCurrentHrDocumentCycleRequestedAt(LeaveApplication $app): ?\DateTimeInterface
    {
        return $this->leaveWorkflowService()->resolveCurrentHrDocumentCycleRequestedAt(
            $app,
            fn ($a) => $this->resolvePendingUpdateMeta($a),
            fn ($a) => $this->resolveLatestUpdateMeta($a)
        );
    }

    private function resolvePendingUpdateMeta(LeaveApplication $app): array
    {
        return $this->leaveWorkflowService()->resolvePendingUpdateMeta(
            $app,
            fn (...$args) => $this->applyRegularLeavePolicy(...$args)
        );
    }

    private function resolveLatestUpdateMeta(LeaveApplication $app): array
    {
        return $this->leaveWorkflowService()->resolveLatestUpdateMeta(
            $app,
            fn (...$args) => $this->applyRegularLeavePolicy(...$args)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function formatApplicationUpdateRequests(LeaveApplication $app): array
    {
        $updateRequests = null;

        if ($app->relationLoaded('updateRequests')) {
            $updateRequests = $app->updateRequests;
        } elseif ($app->id) {
            $updateRequests = LeaveApplicationUpdateRequest::query()
                ->where('leave_application_id', (int) $app->id)
                ->latest('id')
                ->get();
        }

        if (! $updateRequests) {
            return [];
        }

        return $updateRequests
            ->filter(fn ($req) => $req instanceof LeaveApplicationUpdateRequest)
            ->sortByDesc(fn (LeaveApplicationUpdateRequest $req) => (int) $req->id)
            ->map(fn (LeaveApplicationUpdateRequest $r): array => [
                'id' => (int) $r->id,
                'status' => strtoupper(trim((string) $r->status)),
                'action_type' => $this->resolveUpdateRequestActionTypeFromPayload($r->requested_payload),
                'payload' => $this->normalizePendingUpdatePayload($r->requested_payload),
                'reason' => $this->trimNullableString($r->requested_reason),
                'previous_status' => strtoupper(trim((string) ($r->previous_status ?? ''))),
                'requested_by' => $this->trimNullableString($r->requested_by_control_no),
                'requested_at' => $r->requested_at?->toIso8601String(),
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'review_remarks' => $this->trimNullableString($r->review_remarks),
            ])
            ->values()
            ->all();
    }

    private function normalizePendingUpdatePayload(mixed $payload): ?array
    {
        return $this->leaveWorkflowService()->normalizePendingUpdatePayload(
            $payload,
            fn (...$args) => $this->applyRegularLeavePolicy(...$args)
        );
    }

    private function resolveStoredPrimaryLeaveDeduction(
        LeaveApplication $app,
        ?LeaveType $sourceLeaveType,
        float $fallbackDeductibleDays
    ): float {
        return $this->leaveCreditService()->resolveStoredPrimaryLeaveDeduction(
            $app,
            $sourceLeaveType,
            $fallbackDeductibleDays
        );
    }

    private function resolveCtoLeaveTypeId(): ?int
    {
        return $this->leaveCreditService()->resolveCtoLeaveTypeId();
    }

    private function isCtoLeaveType(?LeaveType $leaveType = null, ?int $leaveTypeId = null): bool
    {
        return $this->leaveCreditService()->isCtoLeaveType($leaveType, $leaveTypeId);
    }

    private function syncEmployeeCtoBalance(
        string $employeeControlNo,
        bool $lockForUpdate = false,
        ?\Carbon\CarbonImmutable $asOfDate = null
    ): ?float {
        return $this->leaveCreditService()->syncEmployeeCtoBalance(
            $employeeControlNo,
            $lockForUpdate,
            $asOfDate,
            fn (string $c) => $this->controlNoCandidates($c),
            fn (string $c) => $this->findEmployeeByControlNo($c)
        );
    }

    private function isTerminalLeaveTypeId(?int $leaveTypeId): bool
    {
        return $this->leaveCreditService()->isTerminalLeaveTypeId($leaveTypeId);
    }

    /**
     * @return array<int, array{leave_type_id:int, leave_type_name:string, days:float}>
     */
    private function resolveStoredMonetizationLeaveCreditComponents(
        LeaveApplication $app,
        ?LeaveType $fallbackLeaveType = null
    ): array {
        return $this->leaveEligibilityService()->resolveStoredMonetizationLeaveCreditComponents($app, $fallbackLeaveType);
    }

    /**
     * @param  array<int, array{leave_type_id:int, leave_type_name:string, days:float}>  $components
     */
    private function formatMonetizationComponentsLabel(array $components, bool $includeDays = true): string
    {
        return $this->leaveEligibilityService()->formatMonetizationComponentsLabel($components, $includeDays);
    }

    private function validateMonetizationRequestMinimum(float $requestedDays, string $errorKey = 'total_days'): ?JsonResponse
    {
        return $this->leaveEligibilityService()->validateMonetizationRequestMinimum($requestedDays, $errorKey);
    }

    private function applyRegularLeavePolicy(
        LeaveType $leaveType,
        float $totalDays,
        ?array $selectedDates,
        ?array $selectedDateCoverage,
        ?array $selectedDatePayStatus,
        string $payMode,
        bool $isMonetization,
        bool $attachmentSubmitted = false,
        ?string $attachmentReference = null,
        bool $enforceAttachment = true,
        mixed $filedAt = null,
        ?string $absenceStartDate = null,
        ?string $absenceEndDate = null,
        ?string $employeeControlNo = null,
        bool $allowPayStatusOverride = false,
        ?string $detailsOfLeave = null
    ): array|JsonResponse {
        return $this->leaveEligibilityService()->applyRegularLeavePolicy(
            $leaveType,
            $totalDays,
            $selectedDates,
            $selectedDateCoverage,
            $selectedDatePayStatus,
            $payMode,
            $isMonetization,
            $attachmentSubmitted,
            $attachmentReference,
            $enforceAttachment,
            $filedAt,
            $absenceStartDate,
            $absenceEndDate,
            $employeeControlNo,
            $allowPayStatusOverride,
            $detailsOfLeave
        );
    }

    private function resolveSickLeavePayModeFromFilingWindow(
        ?array $selectedDates,
        mixed $filedAt = null,
        ?string $absenceStartDate = null,
        ?string $absenceEndDate = null
    ): string {
        return $this->leaveEligibilityService()->resolveSickLeavePayModeFromFilingWindow(
            $selectedDates,
            $filedAt,
            $absenceStartDate,
            $absenceEndDate
        );
    }

    private function resolveCtoDeductedHours(float $deductibleDays): float
    {
        return $this->leaveCalculationService()->resolveCtoDeductedHours($deductibleDays);
    }

    private function normalizePayMode(mixed $payMode, bool $isMonetization = false): string
    {
        return $this->leaveCalculationService()->normalizePayMode($payMode, $isMonetization);
    }

    private function resolveRequestedPayMode(
        Request $request,
        array $validated,
        bool $isMonetization = false,
        mixed $fallback = null
    ): string {
        return $this->leaveWorkflowService()->resolveRequestedPayMode(
            $request,
            $validated,
            $isMonetization,
            $fallback
        );
    }

    private function resolveRequestSlVlCrossDeductionFlag(
        Request $request,
        array $validated,
        ?bool $fallback = null
    ): bool {
        return $this->leaveWorkflowService()->resolveRequestSlVlCrossDeductionFlag(
            $request,
            $validated,
            $fallback
        );
    }

    private function normalizeSelectedDateCoverageMap(mixed $value): ?array
    {
        return $this->leaveCalculationService()->normalizeSelectedDateCoverageMap($value);
    }

    private function normalizeSelectedDateHalfDayPortionMap(mixed $value): ?array
    {
        return $this->leaveCalculationService()->normalizeSelectedDateHalfDayPortionMap($value);
    }

    private function mergeSelectedDateHalfDayPortionMaps(?array $primaryMap, ?array $fallbackMap): ?array
    {
        return $this->leaveCalculationService()->mergeSelectedDateHalfDayPortionMaps($primaryMap, $fallbackMap);
    }

    private function computeDeductibleDays(
        float $totalDays,
        ?array $selectedDates,
        ?array $selectedDatePayStatus,
        ?array $selectedDateCoverage,
        bool $isMonetization,
        string $payMode,
        ?string $employeeControlNo = null
    ): float {
        return $this->leaveCalculationService()->computeDeductibleDays(
            $totalDays,
            $selectedDates,
            $selectedDatePayStatus,
            $selectedDateCoverage,
            $isMonetization,
            $payMode,
            $employeeControlNo
        );
    }

    private function resolveApplicationDeductibleDays(LeaveApplication $app): float
    {
        return $this->leaveCalculationService()->resolveApplicationDeductibleDays($app);
    }

    private function resolveApplicationCtoDeductedHours(LeaveApplication $app): float
    {
        return $this->leaveCalculationService()->resolveApplicationCtoDeductedHours($app);
    }

    private function resolveEffectiveStoredRecallDateKeys(LeaveApplication $app): array
    {
        return $this->leaveWorkflowService()->resolveEffectiveStoredRecallDateKeys(
            $app,
            fn ($a) => $this->resolveLatestUpdateMeta($a)
        );
    }

    private function applicationDeductsEmployeeBalance(
        bool $isMonetization,
        ?LeaveType $leaveType,
        mixed $payMode
    ): bool {
        return $this->leaveCreditService()->applicationDeductsEmployeeBalance($isMonetization, $leaveType, $payMode);
    }

    private function formatApplication(LeaveApplication $app, ?array $actorDirectory = null): array
    {
        return $this->leaveWorkflowService()->formatApplication(
            $app,
            $actorDirectory,
            fn (string $no): ?object => $this->findEmployeeByControlNo($no)
        );
    }

    private function streamApplicationAttachment(LeaveApplication $application)
    {
        $reference = $this->trimNullableString($application->attachment_reference ?? null);
        if ($reference === null) {
            return response()->json(['message' => 'No uploaded attachment found for this leave application.'], 404);
        }

        if (filter_var($reference, FILTER_VALIDATE_URL)) {
            return redirect()->away($reference);
        }

        $normalizedReference = ltrim(str_replace('\\', '/', $reference), '/');
        if ($normalizedReference === '' || str_contains($normalizedReference, '..')) {
            return response()->json(['message' => 'Attachment file not found.'], 404);
        }

        if (str_starts_with($normalizedReference, 'storage/')) {
            $normalizedReference = ltrim(substr($normalizedReference, strlen('storage/')), '/');
        }

        $candidatePaths = [$normalizedReference];
        if (! str_starts_with($normalizedReference, 'public/')) {
            $candidatePaths[] = 'public/'.$normalizedReference;
        }

        $candidateDisks = array_values(array_unique(array_filter([
            (string) config('filesystems.default', 'local'),
            'public',
            'local',
        ])));

        foreach ($candidateDisks as $disk) {
            /** @var FilesystemAdapter $storage */
            $storage = Storage::disk($disk);
            foreach ($candidatePaths as $path) {
                if (! $storage->exists($path)) {
                    continue;
                }

                $filename = basename($path);
                $mimeType = $storage->mimeType($path) ?: 'application/octet-stream';

                return $storage->response(
                    $path,
                    $filename,
                    [
                        'Content-Type' => $mimeType,
                        'Content-Disposition' => 'inline; filename="'.$filename.'"',
                        'X-Content-Type-Options' => 'nosniff',
                    ]
                );
            }
        }

        return response()->json(['message' => 'Attachment file not found.'], 404);
    }

    /**
     * Reprice future pending leave applications using the current
     * work-schedule deductions.
     *
     * Approved applications are intentionally excluded so historical
     * approved records remain unchanged when schedules are updated.
     *
     * @return array{
     *   checked:int,
     *   repriced:int,
     *   skipped:int,
     *   failed:int,
     *   updated_application_ids:array<int,int>,
     *   errors:array<int,array{application_id:int,reason:string}>
     * }
     */
    public function repriceFutureApprovedApplicationsForScheduleChange(?string $employeeControlNo = null): array
    {
        return $this->leaveWorkflowService()->repriceFutureApprovedApplicationsForScheduleChange(
            $employeeControlNo,
            fn (string $no) => $this->controlNoCandidates($no),
            fn (int $adminId) => $this->findAdminEmployeeControlNo($adminId),
            fn (string $no) => $this->findEmployeeByControlNo($no)
        );
    }

    private function getApplicationLeaveBalanceSnapshot(LeaveApplication $app): array
    {
        return $this->leaveCreditService()->getApplicationLeaveBalanceSnapshot(
            $app,
            fn (LeaveApplication $a) => $this->resolveApplicationBalanceLookupControlNo($a),
            fn (string $controlNo) => $this->controlNoCandidates($controlNo),
            fn (string $controlNo) => $this->resolveEmployeeRecordByControlNo($controlNo)
        );
    }

    private function getFreshApplicationLeaveBalanceSnapshot(LeaveApplication $app, bool $syncCtoBalance = true): array
    {
        return $this->leaveCreditService()->getFreshApplicationLeaveBalanceSnapshot(
            $app,
            $syncCtoBalance,
            fn (LeaveApplication $a) => $this->resolveApplicationBalanceLookupControlNo($a),
            fn (string $controlNo) => $this->controlNoCandidates($controlNo),
            fn (string $controlNo) => $this->resolveEmployeeRecordByControlNo($controlNo)
        );
    }

    private function lockCertificationLeaveCreditsSnapshot(LeaveApplication $app): void
    {
        $this->leaveCertificationService()->lockCertificationLeaveCreditsSnapshot(
            $app,
            null,
            fn (LeaveApplication $a) => $this->getFreshApplicationLeaveBalanceSnapshot($a, false),
            fn (LeaveApplication $a, ?LeaveType $lt, float $days) => $this->resolveStoredPrimaryLeaveDeduction($a, $lt, $days),
            fn (LeaveApplication $a) => $this->resolveStoredMonetizationLeaveCreditComponents($a),
            fn (LeaveApplication $a) => $this->hasPendingApprovedUpdateRequest($a)
        );
    }

    private function resolveApplicationBalanceLookupControlNo(LeaveApplication $app): ?string
    {
        return $this->leaveCertificationService()->resolveApplicationBalanceLookupControlNo(
            $app,
            fn (LeaveApplication $a) => $this->resolveApplicationEmployee($a)
        );
    }

    private function findLeaveTypeBalanceInSnapshot(array $snapshot, int $leaveTypeId): ?float
    {
        return $this->leaveCertificationService()->findLeaveTypeBalanceInSnapshot($snapshot, $leaveTypeId);
    }

    private function findLeaveTypeBalanceByNameInSnapshot(array $snapshot, string $leaveTypeName): float
    {
        return $this->leaveCertificationService()->findLeaveTypeBalanceByNameInSnapshot($snapshot, $leaveTypeName);
    }

    private function resolveTerminalLeaveEstimatedAmount(
        ?float $monthlyRate,
        float $vacationLeaveBalance,
        float $sickLeaveBalance
    ): ?float {
        return $this->leaveCertificationService()->resolveTerminalLeaveEstimatedAmount(
            $monthlyRate,
            $vacationLeaveBalance,
            $sickLeaveBalance
        );
    }

    private function resolveCertificationLeaveCredits(LeaveApplication $app, array $leaveBalanceSnapshot = []): array
    {
        return $this->leaveCertificationService()->resolveCertificationLeaveCredits(
            $app,
            $leaveBalanceSnapshot,
            fn (LeaveApplication $a) => $this->getApplicationLeaveBalanceSnapshot($a),
            fn (string $controlNo) => $this->controlNoCandidates($controlNo),
            fn (LeaveApplication $a, ?LeaveType $lt, float $days) => $this->resolveStoredPrimaryLeaveDeduction($a, $lt, $days),
            fn (LeaveApplication $a) => $this->resolveStoredMonetizationLeaveCreditComponents($a),
            fn (LeaveApplication $a) => $this->hasPendingApprovedUpdateRequest($a)
        );
    }

    private function mergeEmployeeControlNoInput(Request $request): void
    {
        $employeeControlNo = trim((string) $request->input('employee_control_no', ''));
        if ($employeeControlNo === '') {
            return;
        }

        $request->merge([
            'employee_control_no' => $employeeControlNo,
        ]);
    }

    private function resolveValidatedEmployeeControlNo(array $validated): string
    {
        return trim((string) ($validated['employee_control_no'] ?? ''));
    }

    private function employeeControlNoResponse(?string $controlNo): array
    {
        $normalized = trim((string) ($controlNo ?? ''));

        return [
            'employee_control_no' => $normalized !== '' ? $normalized : null,
        ];
    }

    private function normalizeSelectedDatesInput(Request $request): void
    {
        $selectedDates = $request->input('selected_dates');
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
        $selectedDateCoverage = $request->input('selected_date_coverage');
        if ($selectedDateCoverage === null || $selectedDateCoverage === '') {
            $selectedDateCoverage = $request->input('selected_date_coverages');
        }

        $selectedDateHalfDayPortion = $request->input('selected_date_half_day_portion');
        if ($selectedDateHalfDayPortion === null || $selectedDateHalfDayPortion === '') {
            $selectedDateHalfDayPortion = $request->input('selectedDateHalfDayPortion');
        }
        if ($selectedDateHalfDayPortion === null || $selectedDateHalfDayPortion === '') {
            $selectedDateHalfDayPortion = $request->input('selected_date_half_day_period');
        }
        if ($selectedDateHalfDayPortion === null || $selectedDateHalfDayPortion === '') {
            $selectedDateHalfDayPortion = $request->input('selectedDateHalfDayPeriod');
        }
        if ($selectedDateHalfDayPortion === null || $selectedDateHalfDayPortion === '') {
            $selectedDateHalfDayPortion = $request->input('selected_date_halfday_period');
        }
        if ($selectedDateHalfDayPortion === null || $selectedDateHalfDayPortion === '') {
            $selectedDateHalfDayPortion = $request->input('selected_date_half_day_portions');
        }

        $normalizedCoverage = $this->normalizeSelectedDateCoverageMap($selectedDateCoverage);
        $normalizedHalfDayPortion = $this->mergeSelectedDateHalfDayPortionMaps(
            $this->normalizeSelectedDateHalfDayPortionMap($selectedDateHalfDayPortion),
            $this->normalizeSelectedDateHalfDayPortionMap($selectedDateCoverage)
        );

        if (is_array($normalizedHalfDayPortion) && $normalizedHalfDayPortion !== []) {
            $coverageMap = is_array($normalizedCoverage) ? $normalizedCoverage : [];
            foreach (array_keys($normalizedHalfDayPortion) as $dateKey) {
                $coverageMap[$dateKey] = 'half';
            }
            ksort($coverageMap);
            $normalizedCoverage = $coverageMap;
        }

        if ($normalizedCoverage !== null) {
            $request->merge(['selected_date_coverage' => $normalizedCoverage]);
        }

        if ($normalizedHalfDayPortion !== null) {
            $request->merge(['selected_date_half_day_portion' => $normalizedHalfDayPortion]);
        }
    }

    /**
     * Log a print event for a leave application form (Sanctum auth).
     */
    public function logPrint(Request $request, int $id): JsonResponse
    {
        $app = LeaveApplication::find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $user = $request->user();
        $performerType = LeaveApplicationPrintLog::PERFORMER_EMPLOYEE;
        $performerId = null;
        $performerName = null;

        if ($user instanceof HRAccount || $user instanceof DepartmentAdmin) {
            $performerType = LeaveApplicationPrintLog::PERFORMER_ADMIN;
            $performerId = (string) $user->id;
            $performerName = $user->name ?? $user->username ?? $user->full_name ?? null;
        } elseif ($user) {
            $performerType = LeaveApplicationPrintLog::PERFORMER_EMPLOYEE;
            $performerId = (string) (data_get($user, 'control_no') ?? data_get($user, 'id') ?? '');
            $performerName = data_get($user, 'full_name') ?? data_get($user, 'name') ?? null;
        }

        if ($request->filled('printed_by_name') && empty($performerName)) {
            $performerName = (string) $request->input('printed_by_name');
        }

        $printLog = LeaveApplicationPrintLog::create([
            'leave_application_id' => $app->id,
            'printed_by_type' => $performerType,
            'printed_by_id' => $performerId,
            'printed_by_name' => $performerName,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'remarks' => $request->input('remarks'),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Leave application print logged successfully.',
            'data' => $printLog,
        ], 201);
    }

    /**
     * Log a print event for a leave application form via ERMS.
     */
    public function ermsLogPrint(Request $request, int $id): JsonResponse
    {
        $this->mergeEmployeeControlNoInput($request);

        $app = LeaveApplication::find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $performerId = (string) ($request->input('printed_by_id') ?? $request->input('employee_control_no') ?? $request->input('control_no') ?? $app->employee_control_no);
        $performerName = (string) ($request->input('printed_by_name') ?? $app->employee_name);

        $printLog = LeaveApplicationPrintLog::create([
            'leave_application_id' => $app->id,
            'printed_by_type' => LeaveApplicationPrintLog::PERFORMER_EMPLOYEE,
            'printed_by_id' => $performerId,
            'printed_by_name' => $performerName,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'remarks' => $request->input('remarks', 'Printed via ERMS'),
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'ERMS leave application print logged successfully.',
            'data' => $printLog,
        ], 201);
    }

    /**
     * Retrieve print logs for a leave application.
     */
    public function hrPrintLogs(Request $request, int $id): JsonResponse
    {
        $app = LeaveApplication::find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $printLogs = LeaveApplicationPrintLog::where('leave_application_id', $app->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $printLogs,
        ]);
    }
}
