<?php

namespace App\Http\Controllers;

use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveApplicationUpdateRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAccrualHistory;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Services\CocLedgerService;
use App\Services\SmsGatewayService;
use App\Services\WorkScheduleService;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
    private const CTO_STANDARD_DAY_HOURS = WorkScheduleService::STANDARD_WORKDAY_HOURS;
    private const DETAILS_OF_LEAVE_FIELDS = [
        'vacation_detail',
        'vacation_specify',
        'sick_detail',
        'sick_specify',
        'women_specify',
        'study_detail',
        'other_purpose',
    ];
    private const QUEUE_GROUP_PENDING = 'PENDING';
    private const QUEUE_GROUP_APPROVED = 'APPROVED';
    private const QUEUE_GROUP_REJECTED = 'REJECTED';
    private const QUEUE_GROUP_RECALLED = 'RECALLED';
    private const QUEUE_GROUP_OTHER = 'OTHER';
    private const QUEUE_GROUP_PRIORITY = [
        self::QUEUE_GROUP_PENDING => 0,
        self::QUEUE_GROUP_APPROVED => 1,
        self::QUEUE_GROUP_REJECTED => 2,
        self::QUEUE_GROUP_RECALLED => 3,
        self::QUEUE_GROUP_OTHER => 4,
    ];
    private const QUEUE_STAGE_PRIORITY = [
        'PENDING_LATE_HR' => 1,
        'PENDING_HR_RECEIVE' => 2,
        'PENDING_HR_REVIEW' => 3,
        'PENDING_HR_CLASSIFICATION' => 4,
        'PENDING_ADMIN' => 5,
        'PENDING_ADMIN_REVIEW' => 6,
        'PENDING_RELEASE' => 7,
        'PENDING' => 8,
    ];
    private const QUEUE_NON_PENDING_STAGE_PRIORITY = 999;

    public function __construct()
    {
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

    private function hasLeaveApplicationCtoHoursColumn(): bool
    {
        static $resolved = false;
        static $hasColumn = false;

        if (!$resolved) {
            $hasColumn = Schema::hasColumn('tblLeaveApplications', 'cto_deducted_hours');
            $resolved = true;
        }

        return $hasColumn;
    }
    // ─── Employee: List own applications ──────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $account = $request->user();
        $controlNo = $account->employee_control_no ?? $account->erms_control_no ?? $account->employee_id ?? null;
        if (!is_object($account) || $controlNo === null) {
            return response()->json(['message' => 'Only employee accounts can list their leave applications.'], 403);
        }

        $applications = LeaveApplication::with(['leaveType', 'applicantAdmin.department', 'updateRequests'])
            ->where(fn($query) => $this->applyApplicationOwnershipFilter($query, (string) $controlNo))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'applications' => $applications->map(fn($app) => $this->formatApplication($app)),
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
        if (!is_object($account) || $controlNo === null) {
            return response()->json(['message' => 'Only employee accounts can access this endpoint.'], 403);
        }

        $employee = $this->findEmployeeByControlNo((string) $controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $resolvedLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        $leaveType = LeaveType::find($resolvedLeaveTypeId);
        if (!$leaveType) {
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
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $resolvedLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        $leaveType = LeaveType::find($resolvedLeaveTypeId);
        if (!$leaveType) {
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
        if (!preg_match('/^\d+$/', $controlNo)) {
            return response()->json(['message' => 'Invalid control number.'], 422);
        }

        $employee = $this->findEmployeeByControlNo($controlNo);
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
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
            ->filter(fn(LeaveType $leaveType): bool => !$this->employeeHasResolvedEmploymentStatus($employee)
                || $leaveType->allowsEmploymentStatus($employee->status ?? null))
            ->values();

        $typesByName = $types
            ->keyBy(fn(LeaveType $type) => strtolower(trim((string) $type->name)))
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
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $applications = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->where(fn($query) => $this->applyApplicationOwnershipFilter($query, $controlNo))
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
                ->map(fn(LeaveApplication $app) => $this->formatErmsApplication($app, $actorDirectory))
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
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $application = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->where('id', $id)
            ->where(fn($query) => $this->applyApplicationOwnershipFilter($query, $controlNo))
            ->first();

        if (!$application) {
            return response()->json(['message' => 'Leave application not found for this employee.'], 404);
        }

        $actorDirectory = $this->buildWorkflowActorDirectory(collect([$application]));

        return response()->json([
            ...$this->employeeControlNoResponse((string) $employee->control_no),
            'application' => $this->formatErmsApplication($application, $actorDirectory),
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

        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $actor = (object) ['id' => (int) ltrim((string) $employee->control_no, '0')];
        $isMonetization = (bool) ($baseValidated['is_monetization'] ?? false);

        if ($isMonetization) {
            return $this->storeMonetization($request, $employee, $actor);
        }

        $validated = $request->validate([
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_days' => ['required', 'numeric', 'min:0.5', 'max:365'],
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
        $resolvedDetailsOfLeave = $this->resolveDetailsOfLeaveFromRequest($request, $validated);

        $requestedPayMode = $this->resolveRequestedPayMode(
            $request,
            $validated,
            false,
            LeaveApplication::PAY_MODE_WITH_PAY
        );
        $selectedDatePayStatus = $this->normalizeSelectedDatePayStatusMap(
            array_key_exists('selected_date_pay_status', $validated)
                ? $validated['selected_date_pay_status']
                : $request->input('selected_date_pay_status')
        );
        $selectedDateCoverage = $this->normalizeSelectedDateCoverageMap(
            array_key_exists('selected_date_coverage', $validated)
                ? $validated['selected_date_coverage']
                : $request->input('selected_date_coverage')
        );
        $selectedDateHalfDayPortion = $this->normalizeSelectedDateHalfDayPortionMap(
            array_key_exists('selected_date_half_day_portion', $validated)
                ? $validated['selected_date_half_day_portion']
                : $request->input('selected_date_half_day_portion')
        );
        $resolvedSelectedDates = LeaveApplication::resolveSelectedDates(
            $validated['start_date'],
            $validated['end_date'],
            is_array($validated['selected_dates'] ?? null) ? $validated['selected_dates'] : null,
            (float) $validated['total_days']
        );
        $selectedDatePayStatus = $this->compactSelectedDatePayStatusMap(
            $selectedDatePayStatus,
            $resolvedSelectedDates,
            $requestedPayMode
        );
        $selectedDateCoverageMetadata = $this->synchronizeSelectedDateCoverageMetadata(
            $selectedDateCoverage,
            $selectedDateHalfDayPortion,
            $resolvedSelectedDates
        );
        $selectedDateCoverage = $selectedDateCoverageMetadata['selectedDateCoverage'];
        $selectedDateHalfDayPortion = $selectedDateCoverageMetadata['selectedDateHalfDayPortion'];
        $validated['leave_type_id'] = $this->resolveCanonicalLeaveTypeId((int) $validated['leave_type_id'])
            ?? (int) $validated['leave_type_id'];
        $leaveType = LeaveType::find((int) $validated['leave_type_id']);
        if (!$leaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
                'errors' => [
                    'leave_type_id' => ['Selected leave type is not available.'],
                ],
            ], 422);
        }

        $attachmentState = $this->resolveAttachmentStateFromRequest($request, $validated);
        $policyResolution = $this->applyRegularLeavePolicy(
            $leaveType,
            (float) $validated['total_days'],
            $resolvedSelectedDates,
            $selectedDateCoverage,
            $selectedDatePayStatus,
            $requestedPayMode,
            false,
            (bool) ($attachmentState['attachment_submitted'] ?? false),
            $attachmentState['attachment_reference'] ?? null,
            true,
            $request->input('date_filed') ?? $request->input('dateOfFiling') ?? now(),
            (string) $validated['start_date'],
            (string) $validated['end_date'],
            (string) $employee->control_no
        );
        if ($policyResolution instanceof JsonResponse) {
            return $policyResolution;
        }

        $requestedPayMode = $policyResolution['pay_mode'];
        $selectedDatePayStatus = $policyResolution['selected_date_pay_status'];
        $deductibleDays = (float) ($policyResolution['deductible_days'] ?? 0);
        $ctoDeductedHours = (float) ($policyResolution['cto_deducted_hours'] ?? 0);
        $attachmentRequired = (bool) ($policyResolution['attachment_required'] ?? false);
        $attachmentSubmitted = (bool) ($policyResolution['attachment_submitted'] ?? false);
        $attachmentReference = $policyResolution['attachment_reference'] ?? null;

        $duplicateDateValidation = $this->validateNoDuplicateLeaveDates(
            (string) $employee->control_no,
            (string) $validated['start_date'],
            (string) $validated['end_date'],
            $resolvedSelectedDates,
            $validated['total_days']
        );
        if ($duplicateDateValidation instanceof JsonResponse) {
            return $duplicateDateValidation;
        }

        $eligibility = $this->validateRegularLeaveEligibility(
            (string) $employee->control_no,
            (int) $validated['leave_type_id'],
            (float) $validated['total_days'],
            $requestedPayMode,
            $deductibleDays,
            $ctoDeductedHours > 0 ? $ctoDeductedHours : null
        );
        if ($eligibility instanceof JsonResponse) {
            return $eligibility;
        }
        if (($eligibility['insufficient_balance'] ?? false) === true) {
            if ($this->isCtoLeaveType($leaveType, (int) $validated['leave_type_id'])) {
                return $this->buildInsufficientCtoBalanceResponse($eligibility);
            }

            $allocation = $this->resolveCreditBasedPayAllocation(
                $resolvedSelectedDates,
                $selectedDateCoverage,
                (float) $validated['total_days'],
                (float) ($eligibility['available_balance'] ?? 0.0),
                $selectedDatePayStatus,
                (string) $employee->control_no
            );
            $requestedPayMode = $allocation['pay_mode'];
            $selectedDatePayStatus = $allocation['selected_date_pay_status'];
            $deductibleDays = (float) ($allocation['deductible_days'] ?? 0.0);
        }

        $app = DB::transaction(function () use (
            $validated,
            $employee,
            $actor,
            $requestedPayMode,
            $selectedDatePayStatus,
            $selectedDateCoverage,
            $selectedDateHalfDayPortion,
            $resolvedSelectedDates,
            $deductibleDays,
            $ctoDeductedHours,
            $resolvedDetailsOfLeave,
            $attachmentRequired,
            $attachmentSubmitted,
            $attachmentReference
        ) {
            $application = LeaveApplication::create([
                'employee_control_no' => (string) $employee->control_no,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'total_days' => $validated['total_days'],
                'deductible_days' => $deductibleDays,
                'cto_deducted_hours' => $this->hasLeaveApplicationCtoHoursColumn() && $ctoDeductedHours > 0 ? $ctoDeductedHours : null,
                'reason' => $validated['reason'] ?? null,
                'details_of_leave' => $resolvedDetailsOfLeave,
                'selected_dates' => $resolvedSelectedDates,
                'selected_date_pay_status' => $selectedDatePayStatus,
                'selected_date_coverage' => $selectedDateCoverage,
                'selected_date_half_day_portion' => $selectedDateHalfDayPortion,
                'commutation' => $validated['commutation'] ?? 'Not Requested',
                'pay_mode' => $requestedPayMode,
                'attachment_required' => $attachmentRequired,
                'attachment_submitted' => $attachmentSubmitted,
                'attachment_reference' => $attachmentReference,
                'status' => LeaveApplication::STATUS_PENDING_ADMIN,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $actor->id,
                'created_at' => now(),
            ]);

            return $application;
        });

        $app->load('leaveType');
        $admins = DepartmentAdmin::whereHas('department', fn($q) => $q->where('name', $employee->office))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_REQUEST,
                'New Leave Application',
                trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? '')) . " submitted a {$app->leaveType->name} leave request (" . self::formatDays($app->total_days) . ").",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Leave application submitted successfully.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ], 201);
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
        ], static fn($value) => $value !== null && $value !== ''));

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
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $app = LeaveApplication::query()
            ->with('leaveType')
            ->where('id', $applicationId)
            ->where(fn($query) => $this->applyApplicationOwnershipFilter($query, $controlNo))
            ->first();

        if (!$app) {
            return response()->json(['message' => 'Leave application not found for this employee.'], 404);
        }

        $cancelableStatuses = [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ];

        if (!in_array($app->status, $cancelableStatuses, true)) {
            return response()->json([
                'message' => "Cannot cancel: application status is '{$this->ermsStatusLabel($app->status)}'. Only pending applications can be cancelled.",
            ], 422);
        }

        $reason = trim((string) ($validated['cancellation_reason'] ?? ''));
        $remarks = $reason !== ''
            ? "Cancelled by employee: {$reason}"
            : 'Cancelled by employee';
        $statusBeforeCancel = $app->status;

        $performedById = (int) ltrim((string) $employee->control_no, '0');
        if ($performedById <= 0) {
            $performedById = (int) ($app->employee_control_no ?: 1);
        }

        DB::transaction(function () use ($app, $remarks, $performedById): void {
            $app->update([
                'status' => LeaveApplication::STATUS_REJECTED,
                'remarks' => $remarks,
            ]);

            // Reuse REJECTED action because current log schema has no dedicated CANCELLED enum.
            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_REJECTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $performedById,
                'remarks' => $remarks,
                'created_at' => now(),
            ]);
        });

        $employeeName = trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? ''));
        if ($employeeName === '') {
            $employeeName = 'Employee ' . (string) $employee->control_no;
        }
        $leaveTypeName = $app->leaveType?->name ?? 'leave';
        $pendingStage = $statusBeforeCancel === LeaveApplication::STATUS_PENDING_HR
            ? 'pending HR review'
            : 'pending department review';
        $message = "{$employeeName} cancelled a {$leaveTypeName} application ({$pendingStage}).";
        if ($reason !== '') {
            $message .= " Reason: {$reason}";
        }

        $title = 'Leave Application Cancelled by Employee';
        $admins = DepartmentAdmin::whereHas('department', fn($q) => $q->where('name', $employee->office))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_CANCELLED,
                $title,
                $message,
                $app->id
            );
        }

        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_CANCELLED,
                $title,
                $message,
                $app->id
            );
        }

        $application = $app->fresh(['leaveType', 'applicantAdmin.department', 'logs']);
        $actorDirectory = $this->buildWorkflowActorDirectory([$application]);

        return response()->json([
            'message' => 'Leave application cancelled successfully.',
            'application' => $this->formatErmsApplication($application, $actorDirectory),
        ]);
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
        ], static fn($value) => $value !== null && $value !== ''));

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
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $app = LeaveApplication::query()
            ->with('leaveType')
            ->where('id', $applicationId)
            ->where(function ($query) use ($controlNo): void {
                $query->whereIn('employee_control_no', $this->controlNoCandidates($controlNo));
            })
            ->first();

        if (!$app) {
            return response()->json(['message' => 'Leave application not found for this employee.'], 404);
        }

        if ($app->status !== LeaveApplication::STATUS_APPROVED) {
            return response()->json([
                'message' => "Cannot request cancellation: application status is '{$this->ermsStatusLabel($app->status)}'. Only approved applications can request cancellation.",
            ], 422);
        }

        if ($this->hasPendingApprovedUpdateRequest($app)) {
            return response()->json([
                'message' => 'There is already a pending approved leave request action for this application.',
            ], 422);
        }

        $requestReason = trim((string) (
            $validated['cancellation_reason']
            ?? $validated['remarks']
            ?? $validated['reason']
            ?? ''
        ));
        $remarksLine = $requestReason !== ''
            ? "Cancellation request submitted by employee. Reason: {$requestReason}"
            : 'Cancellation request submitted by employee.';

        $requestedUpdatePayload = $this->buildRequestedLeaveCancellationPayload($app, $requestReason);

        $performedById = (int) ltrim((string) $employee->control_no, '0');
        if ($performedById <= 0) {
            $performedById = (int) ($app->employee_control_no ?: 1);
        }

        DB::transaction(function () use ($app, $remarksLine, $performedById, $requestedUpdatePayload, $requestReason): void {
            $existingRemarks = trim((string) ($app->remarks ?? ''));
            $updatedRemarks = $existingRemarks === ''
                ? $remarksLine
                : "{$existingRemarks}\n{$remarksLine}";

            $app->update([
                'status' => LeaveApplication::STATUS_PENDING_ADMIN,
                'remarks' => $updatedRemarks,
            ]);

            $pendingRequest = LeaveApplicationUpdateRequest::query()
                ->where('leave_application_id', (int) $app->id)
                ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
                ->whereRaw('UPPER(LTRIM(RTRIM(previous_status))) = ?', [LeaveApplication::STATUS_APPROVED])
                ->latest('id')
                ->first();

            $requestPayload = [
                'employee_control_no' => $this->resolveLeaveApplicationUpdateEmployeeControlNo($app),
                'employee_name' => $this->resolveLeaveApplicationUpdateEmployeeName($app),
                'requested_payload' => $requestedUpdatePayload,
                'requested_reason' => $requestReason !== '' ? $requestReason : null,
                'previous_status' => LeaveApplication::STATUS_APPROVED,
                'requested_by_control_no' => (string) ($app->employee_control_no ?? ''),
                'requested_at' => now(),
            ];

            if ($pendingRequest) {
                $pendingRequest->update($requestPayload);
            } else {
                LeaveApplicationUpdateRequest::create(array_merge($requestPayload, [
                    'leave_application_id' => (int) $app->id,
                    'status' => LeaveApplicationUpdateRequest::STATUS_PENDING,
                ]));
            }

            // Reuse SUBMITTED action because current log schema has no dedicated CANCEL_REQUESTED enum.
            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $performedById,
                'remarks' => $remarksLine,
                'created_at' => now(),
            ]);
        });

        $employeeName = trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? ''));
        if ($employeeName === '') {
            $employeeName = 'Employee ' . (string) $employee->control_no;
        }

        $leaveTypeName = $app->leaveType?->name ?? 'leave';
        $message = "{$employeeName} requested cancellation for an approved {$leaveTypeName} application (pending department review).";
        if ($requestReason !== '') {
            $message .= " Reason: {$requestReason}";
        }

        $admins = DepartmentAdmin::whereHas('department', fn($q) => $q->where('name', $employee->office))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_EDIT_REQUEST,
                'Approved Leave Cancellation Requested',
                $message,
                $app->id
            );
        }

        return response()->json([
            'message' => 'Leave cancellation request submitted and forwarded to department admin for approval.',
            'application' => $this->formatApplication($app->fresh(['leaveType', 'applicantAdmin.department'])),
        ]);
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
        ], static fn($value) => $value !== null && $value !== ''));

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
        if (!$employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $app = LeaveApplication::query()
            ->with('leaveType')
            ->where('id', $applicationId)
            ->where(function ($query) use ($controlNo): void {
                $query->whereIn('employee_control_no', $this->controlNoCandidates($controlNo));
            })
            ->first();

        if (!$app) {
            return response()->json(['message' => 'Leave application not found for this employee.'], 404);
        }

        $requestReason = trim((string) (
            $validated['edit_reason']
            ?? $validated['update_reason']
            ?? $validated['remarks']
            ?? ''
        ));
        $remarksLine = $requestReason !== ''
            ? "Edit request submitted by employee. Reason: {$requestReason}"
            : 'Edit request submitted by employee.';

        $isApprovedApplication = $app->status === LeaveApplication::STATUS_APPROVED;
        $editableStatuses = [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ];

        if (!$isApprovedApplication && !in_array($app->status, $editableStatuses, true)) {
            return response()->json([
                'message' => "Cannot request edit: application status is '{$this->ermsStatusLabel($app->status)}'. Only pending or approved applications can request edits.",
            ], 422);
        }

        $requestedUpdatePayload = $this->buildRequestedLeaveUpdatePayload($request, $validated, $app);
        if ($requestedUpdatePayload instanceof JsonResponse) {
            return $requestedUpdatePayload;
        }
        $requestedUpdatePayload = $this->withRequestedLeaveActionType(
            $requestedUpdatePayload,
            LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE
        );

        $hasDataChanges = $this->hasRequestedLeaveUpdateChanges($app, $requestedUpdatePayload);

        if ($isApprovedApplication && !$hasDataChanges) {
            return response()->json([
                'message' => 'No editable field changes were detected for this approved application.',
            ], 422);
        }

        if ($isApprovedApplication && !(bool) ($requestedUpdatePayload['is_monetization'] ?? false)) {
            $duplicateDateValidation = $this->validateNoDuplicateLeaveDates(
                (string) $employee->control_no,
                (string) ($requestedUpdatePayload['start_date'] ?? ''),
                (string) ($requestedUpdatePayload['end_date'] ?? ''),
                is_array($requestedUpdatePayload['selected_dates'] ?? null)
                    ? $requestedUpdatePayload['selected_dates']
                    : null,
                $requestedUpdatePayload['total_days'] ?? null,
                (int) $app->id
            );
            if ($duplicateDateValidation instanceof JsonResponse) {
                return $duplicateDateValidation;
            }
        }

        $targetLeaveTypeId = $this->resolveCanonicalLeaveTypeId((int) ($requestedUpdatePayload['leave_type_id'] ?? 0))
            ?? (int) ($requestedUpdatePayload['leave_type_id'] ?? 0);
        $targetLeaveType = LeaveType::find($targetLeaveTypeId);
        if (!$targetLeaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
            ], 422);
        }
        $requestedUpdatePayload['leave_type_id'] = $targetLeaveTypeId;

        $currentLeaveTypeId = $this->resolveCanonicalLeaveTypeId((int) ($app->leave_type_id ?? 0))
            ?? (int) ($app->leave_type_id ?? 0);
        $skipEventBasedReuseRule = $currentLeaveTypeId > 0 && $currentLeaveTypeId === $targetLeaveTypeId;
        $leaveTypeRestriction = $this->assertEmployeeCanApplyForLeaveType(
            $employee,
            $targetLeaveType,
            $skipEventBasedReuseRule
        );
        if ($leaveTypeRestriction instanceof JsonResponse) {
            return $leaveTypeRestriction;
        }

        $requestedDays = (float) ($requestedUpdatePayload['total_days'] ?? 0);
        if ($targetLeaveType->max_days && $requestedDays > (float) $targetLeaveType->max_days) {
            return response()->json([
                'message' => "This leave type is limited to {$targetLeaveType->max_days} days per application.",
                'errors' => [
                    'total_days' => ["Maximum of {$targetLeaveType->max_days} days allowed for {$targetLeaveType->name}."],
                ],
            ], 422);
        }

        $performedById = (int) ltrim((string) $employee->control_no, '0');
        if ($performedById <= 0) {
            $performedById = (int) ($app->employee_control_no ?: 1);
        }

        DB::transaction(function () use (
            $app,
            $remarksLine,
            $performedById,
            $isApprovedApplication,
            $requestedUpdatePayload,
            $requestReason
        ): void {
            $existingRemarks = trim((string) ($app->remarks ?? ''));
            $updatedRemarks = $existingRemarks === ''
                ? $remarksLine
                : "{$existingRemarks}\n{$remarksLine}";

            if ($isApprovedApplication) {
                $app->update([
                    'status' => LeaveApplication::STATUS_PENDING_ADMIN,
                    'remarks' => $updatedRemarks,
                ]);

                $pendingRequest = LeaveApplicationUpdateRequest::query()
                    ->where('leave_application_id', (int) $app->id)
                    ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
                    ->whereRaw('UPPER(LTRIM(RTRIM(previous_status))) = ?', [LeaveApplication::STATUS_APPROVED])
                    ->latest('id')
                    ->first();

                $requestPayload = [
                    'employee_control_no' => $this->resolveLeaveApplicationUpdateEmployeeControlNo($app),
                    'employee_name' => $this->resolveLeaveApplicationUpdateEmployeeName($app),
                    'requested_payload' => $requestedUpdatePayload,
                    'requested_reason' => $requestReason !== '' ? $requestReason : null,
                    'previous_status' => LeaveApplication::STATUS_APPROVED,
                    'requested_by_control_no' => (string) ($app->employee_control_no ?? ''),
                    'requested_at' => now(),
                ];

                if ($pendingRequest) {
                    $pendingRequest->update($requestPayload);
                } else {
                    LeaveApplicationUpdateRequest::create(array_merge($requestPayload, [
                        'leave_application_id' => (int) $app->id,
                        'status' => LeaveApplicationUpdateRequest::STATUS_PENDING,
                    ]));
                }
            } else {
                // Legacy behavior for already-pending applications.
                $app->update([
                    'remarks' => $updatedRemarks,
                ]);
            }

            // Reuse SUBMITTED action because current log schema has no dedicated EDIT_REQUESTED enum.
            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $performedById,
                'remarks' => $remarksLine,
                'created_at' => now(),
            ]);
        });

        $employeeName = trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? ''));
        if ($employeeName === '') {
            $employeeName = 'Employee ' . (string) $employee->control_no;
        }
        $leaveTypeName = $app->leaveType?->name ?? 'leave';
        $pendingStage = $isApprovedApplication
            ? 'pending department review'
            : ($app->status === LeaveApplication::STATUS_PENDING_HR
                ? 'pending HR review'
                : 'pending department review');
        $message = $isApprovedApplication
            ? "{$employeeName} requested an update to an approved {$leaveTypeName} application ({$pendingStage})."
            : "{$employeeName} requested an edit for a {$leaveTypeName} application ({$pendingStage}).";
        if ($requestReason !== '') {
            $message .= " Reason: {$requestReason}";
        }

        $title = $isApprovedApplication
            ? 'Approved Leave Update Requested'
            : 'Leave Application Edit Requested';

        $admins = DepartmentAdmin::whereHas('department', fn($q) => $q->where('name', $employee->office))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_EDIT_REQUEST,
                $title,
                $message,
                $app->id
            );
        }

        if (!$isApprovedApplication) {
            $hrAccounts = HRAccount::all();
            foreach ($hrAccounts as $hrAccount) {
                Notification::send(
                    $hrAccount,
                    Notification::TYPE_LEAVE_EDIT_REQUEST,
                    $title,
                    $message,
                    $app->id
                );
            }
        }

        return response()->json([
            'message' => $isApprovedApplication
                ? 'Leave update request submitted and forwarded to department admin for approval.'
                : 'Leave edit request submitted successfully.',
            'application' => $this->formatApplication($app->fresh(['leaveType', 'applicantAdmin.department'])),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalizeSelectedDatesInput($request);
        $this->normalizeSelectedDatePolicyInput($request);

        $account = $request->user();
        if (!is_object($account)) {
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

        if (!$employee) {
            return response()->json(['error' => 'Employee not found'], 404);
        }

        // Detect monetization request
        $isMonetization = (bool) ($baseValidated['is_monetization'] ?? false);

        if ($isMonetization) {
            return $this->storeMonetization($request, $employee, $account);
        }

        $validated = $request->validate([
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_days' => ['required', 'numeric', 'min:0.5', 'max:365'],
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
        $resolvedDetailsOfLeave = $this->resolveDetailsOfLeaveFromRequest($request, $validated);

        $requestedPayMode = $this->resolveRequestedPayMode(
            $request,
            $validated,
            false,
            LeaveApplication::PAY_MODE_WITH_PAY
        );
        $selectedDatePayStatus = $this->normalizeSelectedDatePayStatusMap(
            array_key_exists('selected_date_pay_status', $validated)
                ? $validated['selected_date_pay_status']
                : $request->input('selected_date_pay_status')
        );
        $selectedDateCoverage = $this->normalizeSelectedDateCoverageMap(
            array_key_exists('selected_date_coverage', $validated)
                ? $validated['selected_date_coverage']
                : $request->input('selected_date_coverage')
        );
        $selectedDateHalfDayPortion = $this->normalizeSelectedDateHalfDayPortionMap(
            array_key_exists('selected_date_half_day_portion', $validated)
                ? $validated['selected_date_half_day_portion']
                : $request->input('selected_date_half_day_portion')
        );
        $resolvedSelectedDates = LeaveApplication::resolveSelectedDates(
            $validated['start_date'],
            $validated['end_date'],
            is_array($validated['selected_dates'] ?? null) ? $validated['selected_dates'] : null,
            (float) $validated['total_days']
        );
        $selectedDatePayStatus = $this->compactSelectedDatePayStatusMap(
            $selectedDatePayStatus,
            $resolvedSelectedDates,
            $requestedPayMode
        );
        $selectedDateCoverageMetadata = $this->synchronizeSelectedDateCoverageMetadata(
            $selectedDateCoverage,
            $selectedDateHalfDayPortion,
            $resolvedSelectedDates
        );
        $selectedDateCoverage = $selectedDateCoverageMetadata['selectedDateCoverage'];
        $selectedDateHalfDayPortion = $selectedDateCoverageMetadata['selectedDateHalfDayPortion'];
        $validated['leave_type_id'] = $this->resolveCanonicalLeaveTypeId((int) $validated['leave_type_id'])
            ?? (int) $validated['leave_type_id'];
        $leaveType = LeaveType::find((int) $validated['leave_type_id']);
        if (!$leaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
                'errors' => [
                    'leave_type_id' => ['Selected leave type is not available.'],
                ],
            ], 422);
        }

        $attachmentState = $this->resolveAttachmentStateFromRequest($request, $validated);
        $policyResolution = $this->applyRegularLeavePolicy(
            $leaveType,
            (float) $validated['total_days'],
            $resolvedSelectedDates,
            $selectedDateCoverage,
            $selectedDatePayStatus,
            $requestedPayMode,
            false,
            (bool) ($attachmentState['attachment_submitted'] ?? false),
            $attachmentState['attachment_reference'] ?? null,
            true,
            $request->input('date_filed') ?? $request->input('dateOfFiling') ?? now(),
            (string) $validated['start_date'],
            (string) $validated['end_date'],
            (string) $employee->control_no
        );
        if ($policyResolution instanceof JsonResponse) {
            return $policyResolution;
        }

        $requestedPayMode = $policyResolution['pay_mode'];
        $selectedDatePayStatus = $policyResolution['selected_date_pay_status'];
        $deductibleDays = (float) ($policyResolution['deductible_days'] ?? 0);
        $ctoDeductedHours = (float) ($policyResolution['cto_deducted_hours'] ?? 0);
        $attachmentRequired = (bool) ($policyResolution['attachment_required'] ?? false);
        $attachmentSubmitted = (bool) ($policyResolution['attachment_submitted'] ?? false);
        $attachmentReference = $policyResolution['attachment_reference'] ?? null;

        $duplicateDateValidation = $this->validateNoDuplicateLeaveDates(
            (string) $employee->control_no,
            (string) $validated['start_date'],
            (string) $validated['end_date'],
            $resolvedSelectedDates,
            $validated['total_days']
        );
        if ($duplicateDateValidation instanceof JsonResponse) {
            return $duplicateDateValidation;
        }

        $eligibility = $this->validateRegularLeaveEligibility(
            (string) $employee->control_no,
            (int) $validated['leave_type_id'],
            (float) $validated['total_days'],
            $requestedPayMode,
            $deductibleDays,
            $ctoDeductedHours > 0 ? $ctoDeductedHours : null
        );
        if ($eligibility instanceof JsonResponse) {
            return $eligibility;
        }
        if (($eligibility['insufficient_balance'] ?? false) === true) {
            if ($this->isCtoLeaveType($leaveType, (int) $validated['leave_type_id'])) {
                return $this->buildInsufficientCtoBalanceResponse($eligibility);
            }

            $allocation = $this->resolveCreditBasedPayAllocation(
                $resolvedSelectedDates,
                $selectedDateCoverage,
                (float) $validated['total_days'],
                (float) ($eligibility['available_balance'] ?? 0.0),
                $selectedDatePayStatus,
                (string) $employee->control_no
            );
            $requestedPayMode = $allocation['pay_mode'];
            $selectedDatePayStatus = $allocation['selected_date_pay_status'];
            $deductibleDays = (float) ($allocation['deductible_days'] ?? 0.0);
        }

        $app = DB::transaction(function () use (
            $validated,
            $employee,
            $account,
            $requestedPayMode,
            $selectedDatePayStatus,
            $selectedDateCoverage,
            $selectedDateHalfDayPortion,
            $resolvedSelectedDates,
            $deductibleDays,
            $ctoDeductedHours,
            $resolvedDetailsOfLeave,
            $attachmentRequired,
            $attachmentSubmitted,
            $attachmentReference
        ) {
            $application = LeaveApplication::create([
                'employee_control_no' => (string) $employee->control_no,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'total_days' => $validated['total_days'],
                'deductible_days' => $deductibleDays,
                'cto_deducted_hours' => $this->hasLeaveApplicationCtoHoursColumn() && $ctoDeductedHours > 0 ? $ctoDeductedHours : null,
                'reason' => $validated['reason'] ?? null,
                'details_of_leave' => $resolvedDetailsOfLeave,
                'selected_dates' => $resolvedSelectedDates,
                'selected_date_pay_status' => $selectedDatePayStatus,
                'selected_date_coverage' => $selectedDateCoverage,
                'selected_date_half_day_portion' => $selectedDateHalfDayPortion,
                'commutation' => $validated['commutation'] ?? 'Not Requested',
                'pay_mode' => $requestedPayMode,
                'attachment_required' => $attachmentRequired,
                'attachment_submitted' => $attachmentSubmitted,
                'attachment_reference' => $attachmentReference,
                'status' => LeaveApplication::STATUS_PENDING_ADMIN,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $account->id,
                'created_at' => now(),
            ]);

            return $application;
        });

        // Notify department admins about the new application
        $app->load('leaveType');
        $admins = DepartmentAdmin::whereHas('department', fn($q) => $q->where('name', $employee->office))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_REQUEST,
                'New Leave Application',
                trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? '')) . " submitted a {$app->leaveType->name} leave request (" . self::formatDays($app->total_days) . ").",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Leave application submitted successfully.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ], 201);
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
            'total_days' => ['required', 'numeric', 'min:' . LeaveApplication::MONETIZATION_MINIMUM_REQUEST_DAYS, 'max:999'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'details_of_leave' => ['nullable', 'string', 'max:2000'],
            'salary' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Validate that the selected leave type is either Vacation Leave or Sick Leave
        $leaveType = LeaveType::find($validated['leave_type_id']);
        $leaveTypeRestriction = $leaveType
            ? $this->assertEmployeeCanApplyForLeaveType($employee, $leaveType)
            : null;
        if ($leaveTypeRestriction instanceof JsonResponse) {
            return $leaveTypeRestriction;
        }

        // Retrieve current balance (always re-check in backend)
        $balance = LeaveBalance::where('employee_control_no', $employee->control_no)
            ->where('leave_type_id', $validated['leave_type_id'])
            ->first();

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

        // Compute equivalent amount if salary provided
        $equivalentAmount = null;
        $salary = $request->input('salary');
        if ($salary && is_numeric($salary) && (float) $salary > 0) {
            $dailyRate = (float) $salary / 22;
            $equivalentAmount = round($requestedDays * $dailyRate, 2);
        }

        $app = DB::transaction(function () use ($validated, $employee, $account, $equivalentAmount) {
            $application = LeaveApplication::create([
                'employee_control_no' => (string) $employee->control_no,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => null,
                'end_date' => null,
                'total_days' => $validated['total_days'],
                'deductible_days' => (float) $validated['total_days'],
                'reason' => $validated['reason'] ?? null,
                'details_of_leave' => $validated['details_of_leave'] ?? null,
                'status' => LeaveApplication::STATUS_PENDING_ADMIN,
                'is_monetization' => true,
                'commutation' => LeaveApplication::MONETIZATION_REQUIRED_COMMUTATION,
                'pay_mode' => LeaveApplication::PAY_MODE_WITH_PAY,
                'equivalent_amount' => $equivalentAmount,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $account->id,
                'created_at' => now(),
            ]);

            return $application;
        });

        // Notify department admins
        $app->load('leaveType');
        $admins = DepartmentAdmin::whereHas('department', fn($q) => $q->where('name', $employee->office))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_REQUEST,
                'Monetization Request',
                trim(($employee->firstname ?? '') . ' ' . ($employee->surname ?? '')) . " submitted a monetization request for {$app->leaveType->name} (" . self::formatDays($app->total_days) . ").",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Monetization request submitted successfully.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
            'equivalent_amount' => $equivalentAmount,
        ], 201);
    }

    // ─── Employee: View own single application ───────────────────────

    public function show(Request $request, LeaveApplication $leaveApplication): JsonResponse
    {
        $account = $request->user();
        $controlNo = $account->employee_control_no ?? $account->erms_control_no ?? $account->employee_id ?? null;
        if (!is_object($account) || $controlNo === null) {
            return response()->json(['message' => 'Only employee accounts can view leave applications.'], 403);
        }

        $allowedControlNos = $this->controlNoCandidates((string) $controlNo);
        if (!in_array((string) ($leaveApplication->employee_control_no ?? ''), $allowedControlNos, true)) {
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
     * List PENDING_ADMIN applications for the admin's department.
     */
    public function adminIndex(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $deptName = $admin->department?->name;
        $departmentEmployeeControlNos = $this->findDepartmentEmployeeControlNos($deptName, null);
        $departmentAdminIds = $admin->department_id !== null
            ? DepartmentAdmin::query()
                ->where('department_id', $admin->department_id)
                ->pluck('id')
                ->map(fn($value) => (int) $value)
                ->values()
                ->all()
            : [];

        $applications = LeaveApplication::with(['leaveType', 'applicantAdmin.department', 'updateRequests'])
            ->where('status', LeaveApplication::STATUS_PENDING_ADMIN)
            ->where(function ($query) use ($departmentEmployeeControlNos, $admin, $departmentAdminIds): void {
                $hasVisibilityConstraint = false;

                if ($departmentEmployeeControlNos !== []) {
                    $query->whereIn('employee_control_no', $departmentEmployeeControlNos);
                    $hasVisibilityConstraint = true;
                }

                if ($admin->department_id !== null) {
                    if ($hasVisibilityConstraint) {
                        $query->orWhereHas('applicantAdmin', fn($nestedQuery) => $nestedQuery->where('department_id', $admin->department_id));
                    } else {
                        $query->whereHas('applicantAdmin', fn($nestedQuery) => $nestedQuery->where('department_id', $admin->department_id));
                    }
                    $hasVisibilityConstraint = true;
                }

                if ($departmentAdminIds !== []) {
                    if ($hasVisibilityConstraint) {
                        $query->orWhereIn('admin_id', $departmentAdminIds);
                    } else {
                        $query->whereIn('admin_id', $departmentAdminIds);
                    }
                    $hasVisibilityConstraint = true;
                }

                if ($admin->id !== null) {
                    if ($hasVisibilityConstraint) {
                        $query->orWhere('admin_id', $admin->id);
                    } else {
                        $query->where('admin_id', $admin->id);
                    }
                    $hasVisibilityConstraint = true;
                }

                if (!$hasVisibilityConstraint) {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $applications = $this->sortLeaveApplicationsForHrQueue($applications);

        return response()->json([
            'applications' => $applications->map(fn($app) => $this->formatApplication($app)),
        ]);
    }

    public function adminShow(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $admin->loadMissing('department');
        $application = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);

        if (!$application) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if (!$this->adminCanManageApplication($admin, $application)) {
            return response()->json(['message' => 'You can only view applications from your department.'], 403);
        }

        $actorDirectory = $this->buildWorkflowActorDirectory([$application]);
        $workflowPayload = $this->formatErmsApplication($application, $actorDirectory);
        $detailPayload = $this->formatApplication($application);

        return response()->json([
            'application' => array_replace($workflowPayload, $detailPayload),
        ]);
    }

    public function adminViewAttachment(Request $request, int $id)
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can access this endpoint.'], 403);
        }

        $admin->loadMissing('department');
        $application = LeaveApplication::query()
            ->find($id);
        if (!$application) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if (!$this->adminCanManageApplication($admin, $application)) {
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
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can approve applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $admin->loadMissing('department');
        $app = LeaveApplication::find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        // Security: admin can only act on their own department's applications.
        if (!$this->adminCanManageApplication($admin, $app)) {
            return response()->json(['message' => 'You can only manage applications from your department.'], 403);
        }

        // Prevent double approval
        if ($app->status !== LeaveApplication::STATUS_PENDING_ADMIN) {
            return response()->json(['message' => "Cannot approve: application status is '{$app->status}', expected 'PENDING_ADMIN'."], 422);
        }

        $isPendingApprovedUpdateRequest = $this->hasPendingApprovedUpdateRequest($app);
        $pendingApprovedActionType = $this->resolvePendingApprovedUpdateActionType($app);

        DB::transaction(function () use (
            $app,
            $admin,
            $request,
            $isPendingApprovedUpdateRequest,
            $pendingApprovedActionType
        ) {
            $updateAttributes = [
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'remarks' => $request->input('remarks'),
            ];
            if (!$isPendingApprovedUpdateRequest) {
                $updateAttributes['admin_approved_at'] = now();
            }

            $app->update($updateAttributes);

            $logRemarks = $request->input('remarks');
            if ($this->trimNullableString($logRemarks) === null && $isPendingApprovedUpdateRequest) {
                $logRemarks = $pendingApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? 'Approved leave cancellation request and forwarded to HR.'
                    : 'Approved leave update request and forwarded to HR.';
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_APPROVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => $logRemarks,
                'created_at' => now(),
            ]);
        });

        // Notify the employee that admin approved
        $app->load(['leaveType']);
        $isMonetization = (bool) $app->is_monetization;
        $actionLabel = $isMonetization ? 'monetization request' : 'leave application';
        $titleLabel = $isMonetization ? 'Monetization' : 'Leave';
        $employeeName = $this->resolveEmployeeDisplayName($app);
        $notificationType = $isPendingApprovedUpdateRequest
            ? Notification::TYPE_LEAVE_EDIT_REQUEST
            : Notification::TYPE_LEAVE_REQUEST;
        $notificationTitle = $isPendingApprovedUpdateRequest
            ? ($pendingApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                ? "{$titleLabel} Cancellation Request Pending HR Review"
                : "{$titleLabel} Edit Request Pending HR Review")
            : "{$titleLabel} Application Pending HR Review";
        $notificationMessage = $isPendingApprovedUpdateRequest
            ? ($pendingApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                ? "{$employeeName} submitted a cancellation request for an approved {$app->leaveType->name} {$actionLabel}. The request has been approved by department admin and awaits your review."
                : "{$employeeName} submitted an update request for an approved {$app->leaveType->name} {$actionLabel}. The request has been approved by department admin and awaits your review.")
            : "{$employeeName} submitted a {$app->leaveType->name} {$actionLabel} (" . self::formatDays($app->total_days) . ") that has been approved by admin and awaits your review.";

        // Notify all HR accounts about the new pending application
        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                $notificationType,
                $notificationTitle,
                $notificationMessage,
                $app->id
            );
        }

        return response()->json([
            'message' => $isPendingApprovedUpdateRequest
                ? ($pendingApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? 'Leave cancellation request approved by admin. Forwarded to HR for final approval.'
                    : 'Leave update request approved by admin. Forwarded to HR for final approval.')
                : 'Application approved by admin. Forwarded to HR.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ]);
    }

    /**
     * Admin rejects → status becomes REJECTED.
     */
    public function adminReject(Request $request, int $id): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can reject applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $admin->loadMissing('department');
        $app = LeaveApplication::find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if (!$this->adminCanManageApplication($admin, $app)) {
            return response()->json(['message' => 'You can only manage applications from your department.'], 403);
        }

        if (!in_array($app->status, [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ], true)) {
            return response()->json([
                'message' => "Cannot reject: application status is '{$app->status}'. Expected 'PENDING_ADMIN' or 'PENDING_HR'."
            ], 422);
        }

        if ($this->hasPendingApprovedUpdateRequest($app)) {
            return $this->adminRejectPendingUpdateRequest($request, $app, $admin);
        }

        DB::transaction(function () use ($app, $admin, $request) {
            $app->update([
                'status' => LeaveApplication::STATUS_REJECTED,
                'admin_id' => $admin->id,
                'remarks' => $request->input('remarks'),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_REJECTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => $request->input('remarks'),
                'created_at' => now(),
            ]);
        });

        // Notify the employee that admin rejected
        $app->load('leaveType');

        return response()->json([
            'message' => 'Application rejected by admin.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ]);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HR ACTIONS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * List PENDING_HR applications (all departments).
     */
    public function hrIndex(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $applications = LeaveApplication::with(['leaveType', 'applicantAdmin.department', 'updateRequests', 'logs'])
            ->whereIn('status', [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
                LeaveApplication::STATUS_APPROVED,
                LeaveApplication::STATUS_REJECTED,
                LeaveApplication::STATUS_RECALLED,
            ])
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $applications = $this->sortLeaveApplicationsForHrQueue($applications);

        return response()->json([
            'applications' => $applications->map(fn($app) => $this->formatApplication($app)),
        ]);
    }

    private function hasHrReceivedForQueue(LeaveApplication $application): bool
    {
        $cycleRequestedAt = $this->hasPendingApprovedUpdateRequest($application)
            ? $this->resolvePendingApprovedUpdateCycleRequestedAt($application)
            : null;

        return $this->resolveLatestHrActionLogForCycle(
            $application,
            LeaveApplicationLog::ACTION_HR_RECEIVED,
            $cycleRequestedAt
        ) instanceof LeaveApplicationLog;
    }

    private function hasHrReleasedForQueue(LeaveApplication $application): bool
    {
        return $this->resolveLatestHrActionLogForCycle(
            $application,
            LeaveApplicationLog::ACTION_HR_RELEASED,
            $this->resolveLatestApprovedUpdateCycleRequestedAt($application)
        ) instanceof LeaveApplicationLog;
    }

    private function resolveLeaveQueueCreatedTimestamp(LeaveApplication $application): int
    {
        $createdAt = $application->created_at;
        if ($createdAt instanceof \DateTimeInterface) {
            return (int) $createdAt->getTimestamp();
        }

        $rawCreatedAt = trim((string) ($createdAt ?? ''));
        if ($rawCreatedAt !== '') {
            $parsed = strtotime($rawCreatedAt);
            if ($parsed !== false) {
                return (int) $parsed;
            }
        }

        return PHP_INT_MAX;
    }

    private function resolveLeaveHrQueueMeta(LeaveApplication $application): array
    {
        $rawStatus = strtoupper(trim((string) ($application->status ?? '')));
        $groupStatus = self::QUEUE_GROUP_OTHER;
        $stageKey = '';

        if ($rawStatus === LeaveApplication::STATUS_PENDING_HR) {
            $groupStatus = self::QUEUE_GROUP_PENDING;
            $stageKey = $this->hasHrReceivedForQueue($application)
                ? 'PENDING_HR_REVIEW'
                : 'PENDING_HR_RECEIVE';
        } elseif ($rawStatus === LeaveApplication::STATUS_PENDING_ADMIN) {
            $groupStatus = self::QUEUE_GROUP_PENDING;
            $stageKey = $this->hasPendingApprovedUpdateRequest($application)
                ? 'PENDING_ADMIN_REVIEW'
                : 'PENDING_ADMIN';
        } elseif ($rawStatus === LeaveApplication::STATUS_APPROVED) {
            if ($this->hasHrReleasedForQueue($application)) {
                $groupStatus = self::QUEUE_GROUP_APPROVED;
            } else {
                $groupStatus = self::QUEUE_GROUP_PENDING;
                $stageKey = 'PENDING_RELEASE';
            }
        } elseif ($rawStatus === LeaveApplication::STATUS_REJECTED) {
            $groupStatus = self::QUEUE_GROUP_REJECTED;
        } elseif ($rawStatus === LeaveApplication::STATUS_RECALLED) {
            $groupStatus = self::QUEUE_GROUP_RECALLED;
        } elseif (str_contains($rawStatus, 'PENDING')) {
            $groupStatus = self::QUEUE_GROUP_PENDING;
            $stageKey = 'PENDING';
        }

        $groupPriority = self::QUEUE_GROUP_PRIORITY[$groupStatus] ?? self::QUEUE_GROUP_PRIORITY[self::QUEUE_GROUP_OTHER];
        $stagePriority = $groupStatus === self::QUEUE_GROUP_PENDING
            ? (self::QUEUE_STAGE_PRIORITY[$stageKey !== '' ? $stageKey : 'PENDING'] ?? self::QUEUE_STAGE_PRIORITY['PENDING'])
            : self::QUEUE_NON_PENDING_STAGE_PRIORITY;

        return [
            'group_status' => $groupStatus,
            'group_priority' => $groupPriority,
            'stage_key' => $stageKey,
            'stage_priority' => $stagePriority,
            'created_at_timestamp' => $this->resolveLeaveQueueCreatedTimestamp($application),
        ];
    }

    private function sortLeaveApplicationsForHrQueue(Collection $applications): Collection
    {
        return $applications
            ->sort(function (LeaveApplication $left, LeaveApplication $right): int {
                $leftQueueMeta = $this->resolveLeaveHrQueueMeta($left);
                $rightQueueMeta = $this->resolveLeaveHrQueueMeta($right);
                $leftGroupStatus = (string) $leftQueueMeta['group_status'];
                $rightGroupStatus = (string) $rightQueueMeta['group_status'];

                $groupPriorityDiff = ((int) $leftQueueMeta['group_priority']) <=> ((int) $rightQueueMeta['group_priority']);
                if ($groupPriorityDiff !== 0) {
                    return $groupPriorityDiff;
                }

                if (
                    $leftGroupStatus === self::QUEUE_GROUP_PENDING
                    && $rightGroupStatus === self::QUEUE_GROUP_PENDING
                ) {
                    $stagePriorityDiff = ((int) $leftQueueMeta['stage_priority']) <=> ((int) $rightQueueMeta['stage_priority']);
                    if ($stagePriorityDiff !== 0) {
                        return $stagePriorityDiff;
                    }
                }

                $createdAtDiff = ((int) $leftQueueMeta['created_at_timestamp']) <=> ((int) $rightQueueMeta['created_at_timestamp']);
                if ($createdAtDiff !== 0) {
                    $isLifoGroup = in_array($leftGroupStatus, [
                        self::QUEUE_GROUP_APPROVED,
                        self::QUEUE_GROUP_REJECTED,
                        self::QUEUE_GROUP_RECALLED,
                    ], true)
                        && $leftGroupStatus === $rightGroupStatus;

                    return $isLifoGroup ? (-1 * $createdAtDiff) : $createdAtDiff;
                }

                $idDiff = ((int) ($left->id ?? 0)) <=> ((int) ($right->id ?? 0));
                if ($idDiff === 0) {
                    return 0;
                }

                $isLifoGroup = in_array($leftGroupStatus, [
                    self::QUEUE_GROUP_APPROVED,
                    self::QUEUE_GROUP_REJECTED,
                    self::QUEUE_GROUP_RECALLED,
                ], true)
                    && $leftGroupStatus === $rightGroupStatus;

                return $isLifoGroup ? (-1 * $idDiff) : $idDiff;
            })
            ->values();
    }

    public function hrShow(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
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

        if (!$application) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $actorDirectory = $this->buildWorkflowActorDirectory([$application]);
        $workflowPayload = $this->formatErmsApplication($application, $actorDirectory);
        $detailPayload = $this->formatApplication($application);

        return response()->json([
            'application' => array_replace($workflowPayload, $detailPayload),
        ]);
    }

    public function hrViewAttachment(Request $request, int $id)
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
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
        if (!$application) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        return $this->streamApplicationAttachment($application);
    }

    /**
     * HR confirms the hard-copy leave application form was received.
     * This action is informational only and does not change approval status.
     */
    public function hrReceive(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can confirm received applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $isPendingApprovedUpdateRequest = $this->isPendingApprovedUpdateRequest($app);
        if ($app->status === LeaveApplication::STATUS_PENDING_ADMIN && !$isPendingApprovedUpdateRequest) {
            return response()->json([
                'message' => "Cannot mark as received: application status is '{$app->status}'.",
            ], 422);
        }

        $receivedLog = $app->logs->first(
            fn(LeaveApplicationLog $log) =>
                $log->action === LeaveApplicationLog::ACTION_HR_RECEIVED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );

        if (!$receivedLog) {
            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_RECEIVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $request->input('remarks') ?: 'Received hard copy leave application form.',
                'created_at' => now(),
            ]);
            $app->load('logs');
        }

        $actorDirectory = $this->buildWorkflowActorDirectory([$app]);

        return response()->json([
            'message' => $receivedLog
                ? 'Hard-copy receipt was already confirmed for this application.'
                : 'Hard-copy receipt confirmed.',
            'application' => $this->formatErmsApplication($app, $actorDirectory),
        ]);
    }

    /**
     * HR confirms the hard-copy leave application form was released.
     * This action is informational only and does not change approval status.
     */
    public function hrRelease(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can confirm released applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $isPendingApprovedUpdateRequest = $this->isPendingApprovedUpdateRequest($app);
        if ($app->status === LeaveApplication::STATUS_PENDING_ADMIN && !$isPendingApprovedUpdateRequest) {
            return response()->json([
                'message' => "Cannot mark as released: application status is '{$app->status}'.",
            ], 422);
        }

        $releasedLog = $app->logs->first(
            fn(LeaveApplicationLog $log) =>
                $log->action === LeaveApplicationLog::ACTION_HR_RELEASED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );

        if (!$releasedLog) {
            $receivedLog = $app->logs->first(
                fn(LeaveApplicationLog $log) =>
                    $log->action === LeaveApplicationLog::ACTION_HR_RECEIVED
                    && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
            );

            if (!$receivedLog) {
                return response()->json([
                    'message' => 'Cannot mark as released before confirming receipt of the hard-copy application.',
                ], 422);
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_RELEASED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $request->input('remarks') ?: 'Released hard copy leave application form.',
                'created_at' => now(),
            ]);
            $app->load('logs');
        }

        $actorDirectory = $this->buildWorkflowActorDirectory([$app]);

        return response()->json([
            'message' => $releasedLog
                ? 'Hard-copy release was already confirmed for this application.'
                : 'Hard-copy release confirmed.',
            'application' => $this->formatErmsApplication($app, $actorDirectory),
        ]);
    }

    /**
     * HR confirms receipt of the updated hard-copy leave application form
     * for the active approved-update request cycle.
     */
    public function hrReceiveUpdate(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can confirm received applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if (!$this->isPendingApprovedUpdateRequest($app)) {
            return response()->json([
                'message' => 'Cannot mark updated copy as received: application is not in pending approved-update review.',
            ], 422);
        }

        $cycleRequestedAt = $this->resolvePendingApprovedUpdateCycleRequestedAt($app);
        if (!$cycleRequestedAt) {
            return response()->json([
                'message' => 'Cannot locate the pending approved update-request cycle for this application.',
            ], 422);
        }
        $pendingActionType = $this->resolvePendingApprovedUpdateActionType($app);

        $receivedLog = $this->resolveLatestHrActionLogForCycle(
            $app,
            LeaveApplicationLog::ACTION_HR_RECEIVED,
            $cycleRequestedAt,
        );

        if (!$receivedLog) {
            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_RECEIVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $request->input('remarks') ?: (
                    $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                        ? 'Received leave cancellation request form.'
                        : 'Received updated hard copy leave application form.'
                ),
                'created_at' => now(),
            ]);
            $app->load('logs');
        }

        $actorDirectory = $this->buildWorkflowActorDirectory([$app]);

        return response()->json([
            'message' => $receivedLog
                ? ($pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? 'Cancellation request hard-copy receipt was already confirmed.'
                    : 'Updated hard-copy receipt was already confirmed for this update request.')
                : ($pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? 'Cancellation request hard-copy receipt confirmed.'
                    : 'Updated hard-copy receipt confirmed.'),
            'application' => $this->formatErmsApplication($app, $actorDirectory),
        ]);
    }

    /**
     * HR confirms release of the updated hard-copy leave application form
     * for the latest approved update-request cycle.
     */
    public function hrReleaseUpdate(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can confirm released applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $latestApprovedActionType = $this->resolveLatestApprovedUpdateActionType($app);
        $allowedStatuses = $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
            ? [LeaveApplication::STATUS_REJECTED, LeaveApplication::STATUS_APPROVED]
            : [LeaveApplication::STATUS_APPROVED];
        if (!in_array($app->status, $allowedStatuses, true)) {
            return response()->json([
                'message' => $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? "Cannot mark cancellation request copy as released: application status is '{$app->status}'."
                    : "Cannot mark updated copy as released: application status is '{$app->status}'.",
            ], 422);
        }

        $cycleRequestedAt = $this->resolveLatestApprovedUpdateCycleRequestedAt($app);
        if (!$cycleRequestedAt) {
            return response()->json([
                'message' => 'Cannot locate the latest approved update-request cycle for this application.',
            ], 422);
        }

        $releasedLog = $this->resolveLatestHrActionLogForCycle(
            $app,
            LeaveApplicationLog::ACTION_HR_RELEASED,
            $cycleRequestedAt,
        );

        if (!$releasedLog) {
            $receivedLog = $this->resolveLatestHrActionLogForCycle(
                $app,
                LeaveApplicationLog::ACTION_HR_RECEIVED,
                $cycleRequestedAt,
            );

            if (!$receivedLog) {
                return response()->json([
                    'message' => $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                        ? 'Cannot mark cancellation request copy as released before confirming cancellation request hard-copy receipt.'
                        : 'Cannot mark updated copy as released before confirming updated hard-copy receipt.',
                ], 422);
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_RELEASED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $request->input('remarks') ?: (
                    $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                        ? 'Released leave cancellation request form.'
                        : 'Released updated hard copy leave application form.'
                ),
                'created_at' => now(),
            ]);
            $app->load('logs');
        }

        $actorDirectory = $this->buildWorkflowActorDirectory([$app]);

        return response()->json([
            'message' => $releasedLog
                ? ($latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? 'Cancellation request hard-copy release was already confirmed.'
                    : 'Updated hard-copy release was already confirmed for this update request.')
                : ($latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? 'Cancellation request hard-copy release confirmed.'
                    : 'Updated hard-copy release confirmed.'),
            'application' => $this->formatErmsApplication($app, $actorDirectory),
        ]);
    }

    /**
     * HR approves → status becomes APPROVED.
     * If leave type is credit-based OR is monetization, deduct from leave_balances inside a transaction.
     */
    public function hrApprove(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can approve applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::with('leaveType')->find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if ($app->status !== LeaveApplication::STATUS_PENDING_HR) {
            return response()->json(['message' => "Cannot approve: application status is '{$app->status}', expected 'PENDING_HR'."], 422);
        }

        if ($this->isPendingApprovedUpdateRequest($app)) {
            return $this->hrApprovePendingUpdateRequest($request, $app, $hr);
        }

        $leaveType = $app->leaveType ?? LeaveType::find((int) $app->leave_type_id);
        if (!$leaveType) {
            return response()->json([
                'message' => 'Selected leave type is no longer available.',
            ], 422);
        }
        $app->setRelation('leaveType', $leaveType);

        $policyResolution = $this->applyRegularLeavePolicy(
            $leaveType,
            (float) $app->total_days,
            $app->resolvedSelectedDates(),
            is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
            is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null,
            $app->pay_mode ?? LeaveApplication::PAY_MODE_WITH_PAY,
            (bool) $app->is_monetization,
            (bool) ($app->attachment_submitted ?? false),
            $this->trimNullableString($app->attachment_reference ?? null),
            true,
            $app->created_at ?? null,
            $app->start_date?->toDateString(),
            $app->end_date?->toDateString(),
            (string) ($app->employee_control_no ?? '')
        );
        if ($policyResolution instanceof JsonResponse) {
            return $policyResolution;
        }

        $normalizedPayMode = $policyResolution['pay_mode'];
        $resolvedSelectedDatePayStatus = $policyResolution['selected_date_pay_status'];
        $daysToDeduct = (float) ($policyResolution['deductible_days'] ?? 0);
        $ctoDeductedHours = (float) ($policyResolution['cto_deducted_hours'] ?? 0);
        $attachmentRequired = (bool) ($policyResolution['attachment_required'] ?? false);
        $attachmentSubmitted = (bool) ($policyResolution['attachment_submitted'] ?? false);
        $attachmentReference = $policyResolution['attachment_reference'] ?? null;

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        $shouldDeductForcedLeave = $this->shouldDeductForcedLeaveWithVacation($app, $forcedLeaveTypeId);
        $shouldDeductVacationLeave = $this->shouldDeductVacationLeaveWithForced(
            $app,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );
        $usesVacationLeaveTopUpForScheduleExcess = $this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
            $leaveType,
            (int) $app->leave_type_id,
            (bool) $app->is_monetization,
            $vacationLeaveTypeId
        );
        $requestedTotalDays = round((float) ($app->total_days ?? $daysToDeduct), 2);
        $requiredPrimaryLeaveDays = $this->resolvePrimaryLeaveTrackedDeductionDays(
            $leaveType,
            (int) $app->leave_type_id,
            (bool) $app->is_monetization,
            $requestedTotalDays,
            $daysToDeduct,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );
        $requiredVacationLeaveDays = $this->resolveLeaveTypeVacationLeaveDeductionDays(
            $leaveType,
            (int) $app->leave_type_id,
            (bool) $app->is_monetization,
            $requestedTotalDays,
            $daysToDeduct,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );

        // Determine if balance deduction is needed
        $needsDeduction = $this->applicationDeductsEmployeeBalance(
            (bool) $app->is_monetization,
            $leaveType,
            $normalizedPayMode
        ) && $daysToDeduct > 0;
        $isCtoDeduction = $this->isCtoLeaveType($leaveType, (int) $app->leave_type_id);

        if ($needsDeduction && $app->employee_control_no && $isCtoDeduction) {
            $this->syncEmployeeCtoBalance((string) $app->employee_control_no);
        }

        if ((bool) $app->is_monetization) {
            $monetizationPolicyRestriction = $this->validateMonetizationPolicyRules(
                $leaveType,
                (float) ($app->total_days ?? 0.0)
            );
            if ($monetizationPolicyRestriction instanceof JsonResponse) {
                return $monetizationPolicyRestriction;
            }
        }

        if ($needsDeduction) {
            if ($app->employee_control_no) {
                // For monetization, deduct from the selected leave type balance.
                $deductTypeId = $this->resolveCanonicalLeaveTypeId((int) $app->leave_type_id)
                    ?? (int) $app->leave_type_id;

                $balance = $this->findPreferredEmployeeLeaveBalanceRecord(
                    (string) $app->employee_control_no,
                    $deductTypeId
                );

                if (!$balance || (float) $balance->balance < $requiredPrimaryLeaveDays) {
                    $currentBalance = $balance ? (float) $balance->balance : 0;
                    if ($usesVacationLeaveTopUpForScheduleExcess) {
                        return $this->buildInsufficientPrimaryLeaveBalanceResponse(
                            $leaveType,
                            $currentBalance,
                            $requiredPrimaryLeaveDays,
                            true
                        );
                    }

                    $label = $app->is_monetization ? 'monetization' : 'leave';
                    return response()->json([
                        'message' => "Insufficient leave balance for {$label}. Current: "
                            . self::formatDays($currentBalance)
                            . ', Requested: '
                            . self::formatDays($requiredPrimaryLeaveDays)
                        . '.',
                    ], 422);
                }

                if ($app->is_monetization) {
                    $monetizationBalanceRestriction = $this->validateMonetizationBalanceRules(
                        (float) $balance->balance,
                        (float) ($app->total_days ?? 0.0)
                    );
                    if ($monetizationBalanceRestriction instanceof JsonResponse) {
                        return $monetizationBalanceRestriction;
                    }
                }

                // Business rule: approving Forced Leave also consumes Vacation Leave credits.
                if ($shouldDeductVacationLeave && $vacationLeaveTypeId !== null && $requiredVacationLeaveDays > 0.0) {
                    $vacationBalance = $this->findPreferredEmployeeLeaveBalanceRecord(
                        (string) $app->employee_control_no,
                        $vacationLeaveTypeId
                    );
                    $currentVacationBalance = $vacationBalance ? (float) $vacationBalance->balance : 0.0;
                    if ($currentVacationBalance + 1e-9 < $requiredVacationLeaveDays) {
                        if ($usesVacationLeaveTopUpForScheduleExcess) {
                            return $this->buildInsufficientVacationLeaveTopUpResponse(
                                $leaveType,
                                $currentVacationBalance,
                                $requiredVacationLeaveDays
                            );
                        }

                        return response()->json([
                            'message' => 'Insufficient Vacation Leave balance for Mandatory / Forced Leave approval.'
                                . ' Current: ' . self::formatDays($currentVacationBalance)
                                . ', Required: ' . self::formatDays($requiredVacationLeaveDays) . '.',
                        ], 422);
                    }
                }
            } elseif ($app->applicant_admin_id) {
                $balance = $this->findAdminEmployeeLeaveBalance(
                    (int) $app->applicant_admin_id,
                    (int) $app->leave_type_id
                );

                if (!$balance || (float) $balance->balance < $requiredPrimaryLeaveDays) {
                    $currentBalance = $balance ? (float) $balance->balance : 0;
                    if ($usesVacationLeaveTopUpForScheduleExcess) {
                        return $this->buildInsufficientPrimaryLeaveBalanceResponse(
                            $leaveType,
                            $currentBalance,
                            $requiredPrimaryLeaveDays,
                            true
                        );
                    }

                    return response()->json([
                        'message' => 'Insufficient leave balance. Current: '
                            . self::formatDays($currentBalance)
                            . ', Requested: '
                            . self::formatDays($requiredPrimaryLeaveDays)
                        . '.',
                    ], 422);
                }

                if ($app->is_monetization) {
                    $monetizationBalanceRestriction = $this->validateMonetizationBalanceRules(
                        (float) $balance->balance,
                        (float) ($app->total_days ?? 0.0)
                    );
                    if ($monetizationBalanceRestriction instanceof JsonResponse) {
                        return $monetizationBalanceRestriction;
                    }
                }

                if ($shouldDeductVacationLeave && $vacationLeaveTypeId !== null && $requiredVacationLeaveDays > 0.0) {
                    $vacationBalance = $this->findAdminEmployeeLeaveBalance(
                        (int) $app->applicant_admin_id,
                        $vacationLeaveTypeId
                    );
                    $currentVacationBalance = $vacationBalance ? (float) $vacationBalance->balance : 0.0;
                    if ($currentVacationBalance + 1e-9 < $requiredVacationLeaveDays) {
                        if ($usesVacationLeaveTopUpForScheduleExcess) {
                            return $this->buildInsufficientVacationLeaveTopUpResponse(
                                $leaveType,
                                $currentVacationBalance,
                                $requiredVacationLeaveDays
                            );
                        }

                        return response()->json([
                            'message' => 'Insufficient Vacation Leave balance for Mandatory / Forced Leave approval.'
                                . ' Current: ' . self::formatDays($currentVacationBalance)
                                . ', Required: ' . self::formatDays($requiredVacationLeaveDays) . '.',
                        ], 422);
                    }
                }
            }
        }

        $balanceConflictError = 'HR_APPROVAL_BALANCE_CONFLICT';
        $linkedForcedLeaveDeductedDays = 0.0;
        $linkedVacationLeaveDeductedDays = 0.0;

        try {
            DB::transaction(function () use (
                $app,
                $hr,
                $request,
                $needsDeduction,
                $balanceConflictError,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId,
                $shouldDeductForcedLeave,
                $shouldDeductVacationLeave,
                $daysToDeduct,
                $requestedTotalDays,
                $ctoDeductedHours,
                $isCtoDeduction,
                $normalizedPayMode,
                $resolvedSelectedDatePayStatus,
                $attachmentRequired,
                $attachmentSubmitted,
                $attachmentReference,
                &$linkedForcedLeaveDeductedDays,
                &$linkedVacationLeaveDeductedDays
            ) {
                // Deduct balance for credit-based leave types and monetization.
                // Locking the exact target row avoids race conditions and cross-row side effects.
                if ($needsDeduction) {
                    $linkedDeductions = $this->deductApplicationTrackedBalances(
                        $app,
                        (int) $app->leave_type_id,
                        $daysToDeduct,
                        $requestedTotalDays,
                        $isCtoDeduction,
                        $shouldDeductForcedLeave,
                        $forcedLeaveTypeId,
                        $shouldDeductVacationLeave,
                        $vacationLeaveTypeId,
                        $balanceConflictError
                    );
                    $linkedForcedLeaveDeductedDays = (float) ($linkedDeductions['linked_forced_leave_deducted_days'] ?? 0.0);
                    $linkedVacationLeaveDeductedDays = (float) ($linkedDeductions['linked_vacation_leave_deducted_days'] ?? 0.0);
                }

                $app->update([
                    'status' => LeaveApplication::STATUS_APPROVED,
                    'hr_id' => $hr->id,
                    'hr_approved_at' => now(),
                    'remarks' => $request->input('remarks'),
                    'pay_mode' => $normalizedPayMode,
                    'selected_date_pay_status' => $resolvedSelectedDatePayStatus,
                    'deductible_days' => $daysToDeduct,
                    'cto_deducted_hours' => $this->hasLeaveApplicationCtoHoursColumn() && $isCtoDeduction && $ctoDeductedHours > 0 ? $ctoDeductedHours : null,
                    'linked_forced_leave_deducted_days' => $linkedForcedLeaveDeductedDays,
                    'linked_vacation_leave_deducted_days' => $linkedVacationLeaveDeductedDays,
                    'attachment_required' => $attachmentRequired,
                    'attachment_submitted' => $attachmentSubmitted,
                    'attachment_reference' => $attachmentReference,
                ]);

                LeaveApplicationLog::create([
                    'leave_application_id' => $app->id,
                    'action' => LeaveApplicationLog::ACTION_HR_APPROVED,
                    'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                    'performed_by_id' => $hr->id,
                    'remarks' => $request->input('remarks'),
                    'created_at' => now(),
                ]);
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== $balanceConflictError) {
                throw $exception;
            }

            if ($app->employee_control_no) {
                if ($isCtoDeduction) {
                    $this->syncEmployeeCtoBalance((string) $app->employee_control_no);
                }

                $currentBalance = (float) (
                    $this->findPreferredEmployeeLeaveBalanceRecord(
                        (string) $app->employee_control_no,
                        (int) $app->leave_type_id
                    )?->balance ?? 0
                );

                if ($app->is_monetization) {
                    $monetizationBalanceRestriction = $this->validateMonetizationBalanceRules(
                        $currentBalance,
                        (float) ($app->total_days ?? 0.0)
                    );
                    if ($monetizationBalanceRestriction instanceof JsonResponse) {
                        return $monetizationBalanceRestriction;
                    }
                }

                if ($shouldDeductVacationLeave && $vacationLeaveTypeId !== null && $requiredVacationLeaveDays > 0.0) {
                    $currentVacationBalance = (float) (
                        $this->findPreferredEmployeeLeaveBalanceRecord(
                            (string) $app->employee_control_no,
                            $vacationLeaveTypeId
                        )?->balance ?? 0.0
                    );
                    if ($currentVacationBalance + 1e-9 < $requiredVacationLeaveDays) {
                        if ($usesVacationLeaveTopUpForScheduleExcess) {
                            return $this->buildInsufficientVacationLeaveTopUpResponse(
                                $leaveType,
                                $currentVacationBalance,
                                $requiredVacationLeaveDays
                            );
                        }

                        return response()->json([
                            'message' => 'Insufficient Vacation Leave balance for Mandatory / Forced Leave approval.'
                                . ' Current: ' . self::formatDays($currentVacationBalance)
                                . ', Required: ' . self::formatDays($requiredVacationLeaveDays) . '.',
                        ], 422);
                    }
                }

                if ($usesVacationLeaveTopUpForScheduleExcess && $currentBalance + 1e-9 < $requiredPrimaryLeaveDays) {
                    return $this->buildInsufficientPrimaryLeaveBalanceResponse(
                        $leaveType,
                        $currentBalance,
                        $requiredPrimaryLeaveDays,
                        true
                    );
                }

                $label = $app->is_monetization ? 'monetization' : 'leave';
                return response()->json([
                    'message' => "Insufficient leave balance for {$label}. Current: "
                        . self::formatDays($currentBalance)
                        . ', Requested: '
                        . self::formatDays($requiredPrimaryLeaveDays)
                        . '.',
                ], 422);
            }

            $currentBalance = (float) (
                $this->findAdminEmployeeLeaveBalance(
                    (int) $app->applicant_admin_id,
                    (int) $app->leave_type_id
                )?->balance ?? 0
            );

            if ($app->is_monetization) {
                $monetizationBalanceRestriction = $this->validateMonetizationBalanceRules(
                    $currentBalance,
                    (float) ($app->total_days ?? 0.0)
                );
                if ($monetizationBalanceRestriction instanceof JsonResponse) {
                    return $monetizationBalanceRestriction;
                }
            }

            if ($shouldDeductVacationLeave && $vacationLeaveTypeId !== null && $requiredVacationLeaveDays > 0.0) {
                $currentVacationBalance = (float) (
                    $this->findAdminEmployeeLeaveBalance(
                        (int) $app->applicant_admin_id,
                        $vacationLeaveTypeId
                    )?->balance ?? 0.0
                );
                if ($currentVacationBalance + 1e-9 < $requiredVacationLeaveDays) {
                    if ($usesVacationLeaveTopUpForScheduleExcess) {
                        return $this->buildInsufficientVacationLeaveTopUpResponse(
                            $leaveType,
                            $currentVacationBalance,
                            $requiredVacationLeaveDays
                        );
                    }

                    return response()->json([
                        'message' => 'Insufficient Vacation Leave balance for Mandatory / Forced Leave approval.'
                            . ' Current: ' . self::formatDays($currentVacationBalance)
                            . ', Required: ' . self::formatDays($requiredVacationLeaveDays) . '.',
                    ], 422);
                }
            }

            if ($usesVacationLeaveTopUpForScheduleExcess && $currentBalance + 1e-9 < $requiredPrimaryLeaveDays) {
                return $this->buildInsufficientPrimaryLeaveBalanceResponse(
                    $leaveType,
                    $currentBalance,
                    $requiredPrimaryLeaveDays,
                    true
                );
            }

            return response()->json([
                'message' => 'Insufficient leave balance. Current: '
                    . self::formatDays($currentBalance)
                    . ', Requested: '
                    . self::formatDays($requiredPrimaryLeaveDays)
                    . '.',
            ], 422);
        }

        if ($isCtoDeduction && $app->employee_control_no) {
            $this->syncEmployeeCtoBalance((string) $app->employee_control_no, true);
        }

        // Notify the applicant
        $isMonetization = (bool) $app->is_monetization;
        $actionLabel = $isMonetization ? 'monetization request' : 'leave application';
        $titleLabel = $isMonetization ? 'Monetization Approved' : 'Leave Application Approved';
        $msg = "Your {$app->leaveType->name} {$actionLabel} (" . self::formatDays($app->total_days) . ") has been fully approved!";
        if ($app->applicant_admin_id) {
            Notification::send($app->applicantAdmin, Notification::TYPE_LEAVE_APPROVED, $titleLabel, $msg, $app->id);
        }
        $this->smsGatewayService()->sendLeaveApprovedMessage($app);

        return response()->json([
            'message' => 'Application approved by HR.',
            'application' => $this->formatApplication($app->fresh(['leaveType', 'applicantAdmin'])),
        ]);
    }

    /**
     * HR rejects → status becomes REJECTED.
     */
    public function hrReject(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can reject applications.'], 403);
        }

        $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if ($app->status !== LeaveApplication::STATUS_PENDING_HR) {
            return response()->json(['message' => "Cannot reject: application status is '{$app->status}', expected 'PENDING_HR'."], 422);
        }

        if ($this->isPendingApprovedUpdateRequest($app)) {
            return $this->hrRejectPendingUpdateRequest($request, $app, $hr);
        }

        DB::transaction(function () use ($app, $hr, $request) {
            $app->update([
                'status' => LeaveApplication::STATUS_REJECTED,
                'hr_id' => $hr->id,
                'remarks' => $request->input('remarks'),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_REJECTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $request->input('remarks'),
                'created_at' => now(),
            ]);
        });

        // Notify the applicant that HR rejected
        $app->load('leaveType');
        $isMonetization = (bool) $app->is_monetization;
        $actionLabel = $isMonetization ? 'monetization request' : 'leave application';
        $titleLabel = $isMonetization ? 'Monetization' : 'Leave Application';
        $rejectMsg = "Your {$app->leaveType->name} {$actionLabel} was rejected by HR." . ($request->input('remarks') ? " Reason: {$request->input('remarks')}" : '');

        if ($app->applicant_admin_id) {
            Notification::send(
                $app->applicantAdmin,
                Notification::TYPE_LEAVE_REJECTED,
                "{$titleLabel} Rejected by HR",
                $rejectMsg,
                $app->id
            );
        }
        $this->smsGatewayService()->sendLeaveRejectedMessage($app);

        return response()->json([
            'message' => 'Application rejected by HR.',
            'application' => $this->formatApplication($app->fresh('leaveType')),
        ]);
    }

    /**
     * HR recalls an already approved leave application.
     * Tagum policy: restore VL only and never restore FL.
     */
    public function hrRecall(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (!$hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can recall applications.'], 403);
        }

        $validated = $request->validate([
            'recall_reason' => ['required', 'string', 'max:2000'],
            'recall_selected_dates' => ['required', 'array', 'min:1'],
            'recall_selected_dates.*' => ['required', 'date'],
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        $app = LeaveApplication::with('leaveType')->find($id);
        if (!$app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if ((bool) $app->is_monetization) {
            return response()->json([
                'message' => 'Monetization requests cannot be recalled.',
            ], 422);
        }

        if ($app->status !== LeaveApplication::STATUS_APPROVED) {
            return response()->json([
                'message' => "Cannot recall: application status is '{$app->status}', expected 'APPROVED'.",
            ], 422);
        }

        if ($this->hasPendingApprovedUpdateRequest($app)) {
            return response()->json([
                'message' => 'Cannot recall while there is a pending approved update request for this application.',
            ], 422);
        }

        $leaveType = $app->leaveType ?? LeaveType::find((int) $app->leave_type_id);
        if (!$leaveType) {
            return response()->json([
                'message' => 'Selected leave type is no longer available.',
            ], 422);
        }
        $app->setRelation('leaveType', $leaveType);

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        $normalizedLeaveTypeName = strtolower(trim((string) $leaveType->name));
        $isRecallableLeaveType = ($forcedLeaveTypeId !== null && (int) $app->leave_type_id === $forcedLeaveTypeId)
            || ($vacationLeaveTypeId !== null && (int) $app->leave_type_id === $vacationLeaveTypeId)
            || in_array($normalizedLeaveTypeName, ['mandatory / forced leave', 'vacation leave'], true);

        if (!$isRecallableLeaveType) {
            return response()->json([
                'message' => 'Only Vacation Leave and Mandatory / Forced Leave applications can be recalled.',
            ], 422);
        }

        $selectedRecallDateKeys = $this->resolveValidatedRecallSelectedDateKeys(
            $app,
            is_array($validated['recall_selected_dates'] ?? null)
                ? $validated['recall_selected_dates']
                : []
        );
        if ($selectedRecallDateKeys === null || $selectedRecallDateKeys === []) {
            return response()->json([
                'message' => 'Selected recall dates must match the application leave dates.',
            ], 422);
        }
        $existingRecallDateKeys = $this->resolveEffectiveStoredRecallDateKeys($app);
        $mergedRecallDateKeys = $this->normalizeRecallDateKeys(array_merge(
            $existingRecallDateKeys,
            $selectedRecallDateKeys
        ));
        $applicationDateKeys = $this->normalizeRecallDateKeys($app->resolvedSelectedDates() ?? []);
        $isFullyRecalled = $applicationDateKeys !== []
            && count(array_intersect($applicationDateKeys, $mergedRecallDateKeys)) >= count($applicationDateKeys);
        $effectiveRecallDate = \Carbon\CarbonImmutable::parse($selectedRecallDateKeys[0])->startOfDay();

        $normalizedPayMode = $this->normalizePayMode($app->pay_mode ?? null, (bool) $app->is_monetization);
        $deductsBalance = $this->applicationDeductsEmployeeBalance(
            (bool) $app->is_monetization,
            $leaveType,
            $normalizedPayMode
        );
        $recallDetails = $deductsBalance
            ? $this->resolveRecallRestorableDetails($app, $selectedRecallDateKeys)
            : ['days' => 0.0, 'dates' => []];
        $daysToRestore = round((float) ($recallDetails['days'] ?? 0.0), 2);

        $restoreLeaveTypeId = (int) $app->leave_type_id;
        if ($forcedLeaveTypeId !== null && (int) $app->leave_type_id === $forcedLeaveTypeId) {
            if ($vacationLeaveTypeId === null) {
                return response()->json([
                    'message' => 'Cannot recall Mandatory / Forced Leave because Vacation Leave type is not configured.',
                ], 422);
            }
            // Tagum policy: FL is not restored; restore VL only.
            $restoreLeaveTypeId = $vacationLeaveTypeId;
        }
        $mainRestoreDays = $daysToRestore;
        if ($forcedLeaveTypeId !== null && (int) $app->leave_type_id === $forcedLeaveTypeId) {
            $mainRestoreDays = min(
                $daysToRestore,
                $this->resolveStoredLinkedVacationLeaveDeduction($app, $leaveType, $this->resolveApplicationDeductibleDays($app))
            );
        }
        $reason = trim((string) ($validated['recall_reason'] ?? $validated['remarks'] ?? ''));
        $recallRemarks = $reason !== ''
            ? "Recalled by HR: {$reason}"
            : 'Recalled by HR';

        $balanceConflictError = 'HR_RECALL_BALANCE_CONFLICT';

        try {
            DB::transaction(function () use (
                $app,
                $hr,
                $mainRestoreDays,
                $restoreLeaveTypeId,
                $recallRemarks,
                $effectiveRecallDate,
                $selectedRecallDateKeys,
                $mergedRecallDateKeys,
                $isFullyRecalled
            ): void {
                if ($mainRestoreDays > 0.0) {
                    $this->restoreApplicationBalance($app, $restoreLeaveTypeId, $mainRestoreDays);
                }

                $updatePayload = [
                    'status' => $isFullyRecalled
                        ? LeaveApplication::STATUS_RECALLED
                        : LeaveApplication::STATUS_APPROVED,
                    'hr_id' => $hr->id,
                    'recall_effective_date' => $effectiveRecallDate->toDateString(),
                    'recall_selected_dates' => $mergedRecallDateKeys,
                ];
                if ($isFullyRecalled) {
                    $updatePayload['remarks'] = $recallRemarks;
                }

                $app->update($updatePayload);

                LeaveApplicationLog::create([
                    'leave_application_id' => $app->id,
                    'action' => LeaveApplicationLog::ACTION_HR_RECALLED,
                    'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                    'performed_by_id' => $hr->id,
                    'remarks' => $recallRemarks,
                    'created_at' => now(),
                ]);
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== $balanceConflictError) {
                throw $exception;
            }

            return response()->json([
                'message' => 'Unable to recall the application due to leave balance mismatch. Please refresh and try again.',
            ], 409);
        }

        $app->loadMissing('applicantAdmin', 'leaveType');
        if ($app->applicant_admin_id && $app->applicantAdmin) {
            Notification::send(
                $app->applicantAdmin,
                Notification::TYPE_LEAVE_CANCELLED,
                'Leave Recalled by HR',
                "Your {$app->leaveType->name} application was recalled by HR." . ($reason !== '' ? " Reason: {$reason}" : ''),
                $app->id
            );
        }

        return response()->json([
            'message' => $isFullyRecalled
                ? 'Application recalled by HR.'
                : 'Selected leave dates recalled by HR.',
            'application' => $this->formatApplication($app->fresh(['leaveType', 'applicantAdmin'])),
        ]);
    }

    // ─── Admin: List department employees for apply-leave form ────────

    /**
     * Return employees in the admin's department with leave types & balances.
     * Powers the admin "Apply Leave" form dropdowns.
     */
    public function adminEmployees(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (!$admin instanceof DepartmentAdmin) {
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
                'leave_balances' => collect($leaveBalances)->map(fn (LeaveBalance $leaveBalance) => [
                    'leave_type_id' => $leaveBalance->leave_type_id,
                    'leave_type_name' => trim((string) ($leaveBalance->leave_type_name ?? $leaveBalance->leaveType?->name ?? '')),
                    'balance' => (float) $leaveBalance->balance,
                ])->values(),
            ];
        })->values();

        $leaveTypes = LeaveType::orderBy('name')->get()->map(fn($lt) => [
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
        if (!$admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can file leave on behalf of employees.'], 403);
        }

        // Detect monetization request
        $isMonetization = (bool) $request->input('is_monetization', false);

        if ($isMonetization) {
            return $this->adminStoreMonetization($request, $admin);
        }

        $validated = $request->validate([
            'employee_control_no' => ['required', 'string', 'regex:/^\d+$/'],
            'leave_type_id' => ['required', 'integer', 'exists:tblLeaveTypes,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'total_days' => ['required', 'numeric', 'min:0.5', 'max:365'],
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
        $resolvedDetailsOfLeave = $this->resolveDetailsOfLeaveFromRequest($request, $validated);

        $requestedPayMode = $this->resolveRequestedPayMode(
            $request,
            $validated,
            false,
            LeaveApplication::PAY_MODE_WITH_PAY
        );
        $selectedDatePayStatus = $this->normalizeSelectedDatePayStatusMap(
            array_key_exists('selected_date_pay_status', $validated)
                ? $validated['selected_date_pay_status']
                : $request->input('selected_date_pay_status')
        );
        $selectedDateCoverage = $this->normalizeSelectedDateCoverageMap(
            array_key_exists('selected_date_coverage', $validated)
                ? $validated['selected_date_coverage']
                : $request->input('selected_date_coverage')
        );
        $selectedDateHalfDayPortion = $this->normalizeSelectedDateHalfDayPortionMap(
            array_key_exists('selected_date_half_day_portion', $validated)
                ? $validated['selected_date_half_day_portion']
                : $request->input('selected_date_half_day_portion')
        );
        $resolvedSelectedDates = LeaveApplication::resolveSelectedDates(
            $validated['start_date'],
            $validated['end_date'],
            is_array($validated['selected_dates'] ?? null) ? $validated['selected_dates'] : null,
            (float) $validated['total_days']
        );
        $selectedDatePayStatus = $this->compactSelectedDatePayStatusMap(
            $selectedDatePayStatus,
            $resolvedSelectedDates,
            $requestedPayMode
        );
        $selectedDateCoverageMetadata = $this->synchronizeSelectedDateCoverageMetadata(
            $selectedDateCoverage,
            $selectedDateHalfDayPortion,
            $resolvedSelectedDates
        );
        $selectedDateCoverage = $selectedDateCoverageMetadata['selectedDateCoverage'];
        $selectedDateHalfDayPortion = $selectedDateCoverageMetadata['selectedDateHalfDayPortion'];
        $validated['leave_type_id'] = $this->resolveCanonicalLeaveTypeId((int) $validated['leave_type_id'])
            ?? (int) $validated['leave_type_id'];
        $leaveType = LeaveType::find((int) $validated['leave_type_id']);
        if (!$leaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
                'errors' => [
                    'leave_type_id' => ['Selected leave type is not available.'],
                ],
            ], 422);
        }

        $attachmentState = $this->resolveAttachmentStateFromRequest($request, $validated);
        $policyResolution = $this->applyRegularLeavePolicy(
            $leaveType,
            (float) $validated['total_days'],
            $resolvedSelectedDates,
            $selectedDateCoverage,
            $selectedDatePayStatus,
            $requestedPayMode,
            false,
            (bool) ($attachmentState['attachment_submitted'] ?? false),
            $attachmentState['attachment_reference'] ?? null,
            true,
            $request->input('date_filed') ?? now(),
            (string) $validated['start_date'],
            (string) $validated['end_date'],
            (string) ($validated['employee_control_no'] ?? '')
        );
        if ($policyResolution instanceof JsonResponse) {
            return $policyResolution;
        }

        $requestedPayMode = $policyResolution['pay_mode'];
        $selectedDatePayStatus = $policyResolution['selected_date_pay_status'];
        $deductibleDays = (float) ($policyResolution['deductible_days'] ?? 0);
        $ctoDeductedHours = (float) ($policyResolution['cto_deducted_hours'] ?? 0);
        $attachmentRequired = (bool) ($policyResolution['attachment_required'] ?? false);
        $attachmentSubmitted = (bool) ($policyResolution['attachment_submitted'] ?? false);
        $attachmentReference = $policyResolution['attachment_reference'] ?? null;

        // Verify the employee belongs to the admin's department (by office name)
        $admin->loadMissing('department');
        $employee = $this->findEmployeeByControlNo((string) $validated['employee_control_no']);
        if (!$employee) {
            return response()->json([
                'message' => 'Employee not found in HRIS.',
                'errors' => ['employee_control_no' => ['Selected employee was not found in HRIS.']],
            ], 422);
        }

        if (!$this->sameOffice($employee->office ?? null, $admin->department?->name)) {
            return response()->json(['message' => 'You can only file leave for employees in your department.'], 403);
        }

        $duplicateDateValidation = $this->validateNoDuplicateLeaveDates(
            (string) $employee->control_no,
            (string) $validated['start_date'],
            (string) $validated['end_date'],
            $resolvedSelectedDates,
            $validated['total_days']
        );
        if ($duplicateDateValidation instanceof JsonResponse) {
            return $duplicateDateValidation;
        }

        $eligibility = $this->validateRegularLeaveEligibility(
            (string) $employee->control_no,
            (int) $validated['leave_type_id'],
            (float) $validated['total_days'],
            $requestedPayMode,
            $deductibleDays,
            $ctoDeductedHours > 0 ? $ctoDeductedHours : null
        );
        if ($eligibility instanceof JsonResponse) {
            return $eligibility;
        }
        if (($eligibility['insufficient_balance'] ?? false) === true) {
            if ($this->isCtoLeaveType($leaveType, (int) $validated['leave_type_id'])) {
                return $this->buildInsufficientCtoBalanceResponse($eligibility);
            }

            $allocation = $this->resolveCreditBasedPayAllocation(
                $resolvedSelectedDates,
                $selectedDateCoverage,
                (float) $validated['total_days'],
                (float) ($eligibility['available_balance'] ?? 0.0),
                $selectedDatePayStatus,
                (string) ($employee->control_no ?? $validated['employee_control_no'] ?? '')
            );
            $requestedPayMode = $allocation['pay_mode'];
            $selectedDatePayStatus = $allocation['selected_date_pay_status'];
            $deductibleDays = (float) ($allocation['deductible_days'] ?? 0.0);
        }

        $app = DB::transaction(function () use (
            $validated,
            $employee,
            $admin,
            $requestedPayMode,
            $selectedDatePayStatus,
            $selectedDateCoverage,
            $selectedDateHalfDayPortion,
            $resolvedSelectedDates,
            $deductibleDays,
            $ctoDeductedHours,
            $resolvedDetailsOfLeave,
            $attachmentRequired,
            $attachmentSubmitted,
            $attachmentReference
        ) {
            $application = LeaveApplication::create([
                'employee_control_no' => (string) $employee->control_no,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'total_days' => $validated['total_days'],
                'deductible_days' => $deductibleDays,
                'cto_deducted_hours' => $this->hasLeaveApplicationCtoHoursColumn() && $ctoDeductedHours > 0 ? $ctoDeductedHours : null,
                'reason' => $validated['reason'] ?? null,
                'details_of_leave' => $resolvedDetailsOfLeave,
                'selected_dates' => $resolvedSelectedDates,
                'selected_date_pay_status' => $selectedDatePayStatus,
                'selected_date_coverage' => $selectedDateCoverage,
                'selected_date_half_day_portion' => $selectedDateHalfDayPortion,
                'commutation' => $validated['commutation'] ?? 'Not Requested',
                'pay_mode' => $requestedPayMode,
                'attachment_required' => $attachmentRequired,
                'attachment_submitted' => $attachmentSubmitted,
                'attachment_reference' => $attachmentReference,
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'admin_approved_at' => now(),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'created_at' => now(),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_APPROVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => 'Filed by department admin on behalf of employee.',
                'created_at' => now(),
            ]);

            return $application;
        });

        $app->load('leaveType');
        $employeeName = trim((string) (($employee->firstname ?? '') . ' ' . ($employee->surname ?? '')));
        if ($employeeName === '') {
            $employeeName = $this->resolveEmployeeDisplayName($app);
        }

        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_REQUEST,
                'Leave Application Pending HR Review',
                "{$employeeName} filed a {$app->leaveType->name} leave (" . self::formatDays($app->total_days) . ") through department admin and it awaits your review.",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Leave application filed successfully and forwarded to HR.',
            'application' => $this->formatApplication($app->fresh(['leaveType'])),
        ], 201);
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
            'total_days' => ['required', 'numeric', 'min:' . LeaveApplication::MONETIZATION_MINIMUM_REQUEST_DAYS, 'max:999'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'details_of_leave' => ['nullable', 'string', 'max:2000'],
            'salary' => ['nullable', 'numeric', 'min:0'],
        ]);

        $admin->loadMissing('department');
        $employee = $this->findEmployeeByControlNo((string) $validated['employee_control_no']);
        if (!$employee) {
            return response()->json([
                'message' => 'Employee not found in HRIS.',
                'errors' => ['employee_control_no' => ['Selected employee was not found in HRIS.']],
            ], 422);
        }

        if (!$this->sameOffice($employee->office ?? null, $admin->department?->name)) {
            return response()->json(['message' => 'You can only file for employees in your department.'], 403);
        }

        $leaveType = LeaveType::find($validated['leave_type_id']);
        $leaveTypeRestriction = $leaveType
            ? $this->assertEmployeeCanApplyForLeaveType($employee, $leaveType)
            : null;
        if ($leaveTypeRestriction instanceof JsonResponse) {
            return $leaveTypeRestriction;
        }

        $balance = LeaveBalance::where('employee_control_no', $employee->control_no)
            ->where('leave_type_id', $validated['leave_type_id'])
            ->first();

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
        if ($salary && is_numeric($salary) && (float) $salary > 0) {
            $dailyRate = (float) $salary / 22;
            $equivalentAmount = round($requestedDays * $dailyRate, 2);
        }

        $app = DB::transaction(function () use ($validated, $employee, $admin, $equivalentAmount) {
            $application = LeaveApplication::create([
                'employee_control_no' => (string) $employee->control_no,
                'leave_type_id' => $validated['leave_type_id'],
                'start_date' => null,
                'end_date' => null,
                'total_days' => $validated['total_days'],
                'deductible_days' => (float) $validated['total_days'],
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
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'created_at' => now(),
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_APPROVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => 'Monetization filed by department admin on behalf of employee.',
                'created_at' => now(),
            ]);

            return $application;
        });

        $app->load('leaveType');
        $employeeName = trim((string) (($employee->firstname ?? '') . ' ' . ($employee->surname ?? '')));
        if ($employeeName === '') {
            $employeeName = $this->resolveEmployeeDisplayName($app);
        }

        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_REQUEST,
                'Monetization Request Pending HR Review',
                "{$employeeName} filed a {$app->leaveType->name} monetization request (" . self::formatDays($app->total_days) . ") through department admin and it awaits your review.",
                $app->id
            );
        }

        return response()->json([
            'message' => 'Monetization request filed successfully and forwarded to HR.',
            'application' => $this->formatApplication($app->fresh(['leaveType'])),
            'equivalent_amount' => $equivalentAmount,
        ], 201);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  HELPERS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * Calculate max consecutive days from an array of date strings.
     * @deprecated Use LeaveValidationService instead.
     */

    private function statusToFrontend(string $status): string
    {
        return match ($status) {
            LeaveApplication::STATUS_PENDING_ADMIN => 'Pending Admin',
            LeaveApplication::STATUS_PENDING_HR => 'Pending HR',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
            LeaveApplication::STATUS_RECALLED => 'Recalled',
            default => $status,
        };
    }


    /**
     * Format total days for display: remove trailing .00, proper singular/plural.
     * e.g. 4.00 → "4 days", 1.00 → "1 day", 0.50 → "0.5 days"
     */
    private static function formatDays($days): string
    {
        $num = (float) $days;
        $display = ($num == (int) $num) ? (int) $num : $num;
        return $display . ' ' . ($num == 1 ? 'day' : 'days');
    }

    private function findEmployeeByControlNo(string $controlNo): ?object
    {
        $controlNo = trim($controlNo);
        if ($controlNo === '') {
            return null;
        }

        return HrisEmployee::findByControlNo($controlNo);
    }

    private function findDepartmentEmployeeControlNos(?string $departmentName, ?bool $activeOnly = null): array
    {
        return HrisEmployee::controlNosByOffice($departmentName, $activeOnly);
    }

    /**
     * @param Collection<int, object> $hrisEmployees
     * @return array<string, array<int, LeaveBalance>>
     */
    private function buildLeaveBalanceLookupForEmployees(Collection $hrisEmployees): array
    {
        if ($hrisEmployees->isEmpty()) {
            return [];
        }

        $candidateControlNos = $hrisEmployees
            ->map(static fn (object $employee): string => trim((string) ($employee->control_no ?? '')))
            ->filter(static fn (string $controlNo): bool => $controlNo !== '')
            ->flatMap(fn (string $controlNo): array => array_values(array_unique([
                $controlNo,
                $this->normalizeControlNo($controlNo),
            ])))
            ->filter(static fn (string $controlNo): bool => $controlNo !== '')
            ->unique()
            ->values()
            ->all();

        if ($candidateControlNos === []) {
            return [];
        }

        $balances = LeaveBalance::query()
            ->with('leaveType')
            ->whereIn('employee_control_no', $candidateControlNos)
            ->get();

        $lookup = [];
        foreach ($balances as $balance) {
            if (!$balance instanceof LeaveBalance) {
                continue;
            }

            $normalizedControlNo = $this->normalizeControlNo((string) ($balance->employee_control_no ?? ''));
            if ($normalizedControlNo === '') {
                continue;
            }

            if (!isset($lookup[$normalizedControlNo])) {
                $lookup[$normalizedControlNo] = [];
            }

            $lookup[$normalizedControlNo][] = $balance;
        }

        return $lookup;
    }

    private function sameOffice(mixed $left, mixed $right): bool
    {
        $leftOffice = $this->normalizeOffice($left);
        $rightOffice = $this->normalizeOffice($right);

        return $leftOffice !== '' && $leftOffice === $rightOffice;
    }

    private function normalizeOffice(mixed $value): string
    {
        return strtoupper(trim((string) ($value ?? '')));
    }

    private function controlNoCandidates(string $controlNo): array
    {
        $rawControlNo = trim($controlNo);
        if ($rawControlNo === '') {
            return [];
        }

        $candidates = [
            $rawControlNo,
            $this->normalizeControlNo($rawControlNo),
        ];

        $employee = $this->findEmployeeByControlNo($rawControlNo);
        if ($employee && trim((string) ($employee->control_no ?? '')) !== '') {
            $candidates[] = trim((string) $employee->control_no);
        }

        return array_values(array_unique(array_filter(
            $candidates,
            static fn(string $value): bool => trim($value) !== ''
        )));
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
        if (!$admin) {
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
        return LeaveType::resolveCanonicalLeaveTypeId($leaveTypeId);
    }

    private function resolveBalanceLookupLeaveTypeIds(int $leaveTypeId): array
    {
        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        if ($canonicalLeaveTypeId <= 0) {
            return [];
        }

        if (LeaveType::isSpecialPrivilegeType(null, $canonicalLeaveTypeId)) {
            $relatedLeaveTypeIds = LeaveType::resolveSpecialPrivilegeRelatedTypeIds();
            if ($relatedLeaveTypeIds !== []) {
                return $relatedLeaveTypeIds;
            }
        }

        return [$canonicalLeaveTypeId];
    }

    private function shouldPreferLeaveBalanceRecord(LeaveBalance $candidate, LeaveBalance $current): bool
    {
        $candidateUpdatedAt = $candidate->updated_at?->timestamp ?? 0;
        $currentUpdatedAt = $current->updated_at?->timestamp ?? 0;

        if ($candidateUpdatedAt !== $currentUpdatedAt) {
            return $candidateUpdatedAt > $currentUpdatedAt;
        }

        return (int) $candidate->id > (int) $current->id;
    }

    private function mapLeaveBalancesByCanonicalTypeId(iterable $balances): array
    {
        $mappedBalances = [];

        foreach ($balances as $balance) {
            if (!$balance instanceof LeaveBalance) {
                continue;
            }

            $typeId = (int) ($balance->leave_type_id ?? 0);
            if ($typeId <= 0) {
                continue;
            }

            $canonicalTypeId = $this->resolveCanonicalLeaveTypeId($typeId) ?? $typeId;
            if (
                !array_key_exists($canonicalTypeId, $mappedBalances)
                || $this->shouldPreferLeaveBalanceRecord($balance, $mappedBalances[$canonicalTypeId])
            ) {
                $mappedBalances[$canonicalTypeId] = $balance;
            }
        }

        return $mappedBalances;
    }

    private function findPreferredEmployeeLeaveBalanceRecord(
        string $employeeControlNo,
        int $leaveTypeId,
        bool $lockForUpdate = false
    ): ?LeaveBalance {
        $lookupLeaveTypeIds = $this->resolveBalanceLookupLeaveTypeIds($leaveTypeId);
        if ($lookupLeaveTypeIds === []) {
            return null;
        }

        $query = $this->queryLeaveBalancesByEmployeeControlNo($employeeControlNo)
            ->whereIn('leave_type_id', $lookupLeaveTypeIds)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function queryLeaveBalancesByEmployeeControlNo(string $employeeControlNo)
    {
        $candidateEmployeeIds = $this->controlNoCandidates($employeeControlNo);
        if ($candidateEmployeeIds === []) {
            return LeaveBalance::query()->whereRaw('1 = 0');
        }

        return LeaveBalance::query()
            ->whereIn('employee_control_no', $candidateEmployeeIds);
    }

    private function findAdminEmployeeLeaveBalance(int $adminId, int $leaveTypeId, bool $lockForUpdate = false): ?LeaveBalance
    {
        $employeeControlNo = $this->findAdminEmployeeControlNo($adminId);
        if ($employeeControlNo === null) {
            return null;
        }

        return $this->findPreferredEmployeeLeaveBalanceRecord($employeeControlNo, $leaveTypeId, $lockForUpdate);
    }

    private function restoreApplicationBalance(
        LeaveApplication $app,
        int $restoreLeaveTypeId,
        float $daysToRestore
    ): void {
        $restoreLeaveTypeId = $this->resolveCanonicalLeaveTypeId($restoreLeaveTypeId) ?? $restoreLeaveTypeId;
        $restoreAmount = round(max($daysToRestore, 0.0), 2);
        if ($restoreLeaveTypeId <= 0 || $restoreAmount <= 0.0) {
            return;
        }

        if ($app->employee_control_no) {
            $balance = $this->findPreferredEmployeeLeaveBalanceRecord(
                (string) $app->employee_control_no,
                $restoreLeaveTypeId,
                true
            );

            if (!$balance) {
                throw new \RuntimeException('HR_RECALL_BALANCE_CONFLICT');
            }

            $balance->increment('balance', $restoreAmount);
            return;
        }

        if ($app->applicant_admin_id) {
            $balance = $this->findAdminEmployeeLeaveBalance(
                (int) $app->applicant_admin_id,
                $restoreLeaveTypeId,
                true
            );

            if (!$balance) {
                throw new \RuntimeException('HR_RECALL_BALANCE_CONFLICT');
            }

            $balance->increment('balance', $restoreAmount);
        }
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
            ->filter(fn(LeaveType $leaveType): bool => !$this->employeeHasResolvedEmploymentStatus($employee)
                || $leaveType->allowsEmploymentStatus($employee->status ?? null))
            ->map(fn(LeaveType $leaveType): array => [
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

    private function assertEmployeeCanApplyForLeaveType(
        object $employee,
        LeaveType $leaveType,
        bool $skipEventBasedReuseRule = false
    ): ?JsonResponse
    {
        $configuredRuleRestriction = $this->assertEmployeeCanUseConfiguredLeaveTypeRule(
            $employee,
            $leaveType,
            'This leave type is not available for the selected employee status.'
        );
        if ($configuredRuleRestriction instanceof JsonResponse) {
            return $configuredRuleRestriction;
        }

        if ($skipEventBasedReuseRule) {
            return null;
        }

        return $this->assertEmployeeCanReuseEventBasedLeaveType($employee, $leaveType);
    }

    private function assertEmployeeCanAccessLeaveTypeBalance(object $employee, LeaveType $leaveType): ?JsonResponse
    {
        return $this->assertEmployeeCanUseConfiguredLeaveTypeRule(
            $employee,
            $leaveType,
            'This leave type balance is not available for the selected employee status.'
        );
    }

    private function assertEmployeeCanUseConfiguredLeaveTypeRule(
        object $employee,
        LeaveType $leaveType,
        string $fallbackMessage
    ): ?JsonResponse {
        if (!$this->employeeHasResolvedEmploymentStatus($employee)) {
            return null;
        }

        if ($leaveType->allowsEmploymentStatus($employee->status ?? null)) {
            return null;
        }

        $statusLabel = $this->formatEmploymentStatusLabel($employee->status ?? null) ?? 'selected';
        $allowedStatusLabels = $leaveType->allowedStatusLabels();
        $message = $allowedStatusLabels !== []
            ? sprintf(
                '%s is only available for %s.',
                $leaveType->name,
                implode(', ', $allowedStatusLabels)
            )
            : sprintf(
                '%s is not available for %s employees.',
                $leaveType->name,
                $statusLabel
            );

        if (trim($message) === '') {
            $message = $fallbackMessage;
        }

        return response()->json([
            'message' => $message,
            'errors' => [
                'leave_type_id' => [$message],
            ],
        ], 422);
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

        $controlNoCandidates = $this->controlNoCandidates($employeeControlNo);
        if ($controlNoCandidates === []) {
            return null;
        }

        $lookupLeaveTypeIds = $this->resolveBalanceLookupLeaveTypeIds($leaveTypeId);
        if ($lookupLeaveTypeIds === []) {
            $lookupLeaveTypeIds = [$leaveTypeId];
        }

        $today = now()->toDateString();

        return LeaveApplication::query()
            ->whereIn('employee_control_no', $controlNoCandidates)
            ->whereIn('leave_type_id', $lookupLeaveTypeIds)
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
            $normalizedLastSelectedDate = $this->resolveIsoDate($lastSelectedDate);
            if ($normalizedLastSelectedDate !== null) {
                return $normalizedLastSelectedDate->toDateString();
            }
        }

        $endDate = $application->end_date?->toDateString();
        if (is_string($endDate) && $endDate !== '') {
            return $endDate;
        }

        $startDate = $application->start_date?->toDateString();
        return is_string($startDate) && $startDate !== '' ? $startDate : null;
    }

    private function employeeHasResolvedEmploymentStatus(object $employee): bool
    {
        return $this->resolveEmploymentStatusKey($employee->status ?? null) !== null;
    }

    private function resolveEmploymentStatusKey(?string $status): ?string
    {
        return LeaveType::normalizeEmploymentStatusKey($status);
    }

    private function formatEmploymentStatusLabel(?string $status): ?string
    {
        return LeaveType::formatEmploymentStatusLabel($status) ?? $this->trimNullableString($status);
    }

    private function normalizeDetailsOfLeavePayload(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decodedValue = json_decode(trim($value), true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decodedValue)) {
                return null;
            }
            $value = $decodedValue;
        }

        if (!is_array($value)) {
            return null;
        }

        $normalizedPayload = [];
        foreach (self::DETAILS_OF_LEAVE_FIELDS as $fieldName) {
            $fieldValue = $this->trimNullableString($value[$fieldName] ?? null);
            if ($fieldValue !== null) {
                $normalizedPayload[$fieldName] = $fieldValue;
            }
        }

        return $normalizedPayload === [] ? null : $normalizedPayload;
    }

    private function encodeDetailsOfLeavePayload(array $payload): ?string
    {
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        return $encodedPayload === false ? null : $encodedPayload;
    }

    private function resolveDetailsOfLeaveFromRequest(Request $request, array $validated): ?string
    {
        $validatedDetailsOfLeave = $this->trimNullableString($validated['details_of_leave'] ?? null);
        if ($validatedDetailsOfLeave !== null) {
            $normalizedValidatedPayload = $this->normalizeDetailsOfLeavePayload($validatedDetailsOfLeave);
            if ($normalizedValidatedPayload !== null) {
                return $this->encodeDetailsOfLeavePayload($normalizedValidatedPayload);
            }

            return $validatedDetailsOfLeave;
        }

        $normalizedRequestPayload = $this->normalizeDetailsOfLeavePayload($request->input('details'));
        if ($normalizedRequestPayload !== null) {
            return $this->encodeDetailsOfLeavePayload($normalizedRequestPayload);
        }

        return null;
    }

    private function resolveRequestedDetailsOfLeaveForUpdate(
        Request $request,
        array $validated,
        LeaveApplication $app
    ): ?string {
        if (array_key_exists('details_of_leave', $validated) || $request->exists('details')) {
            return $this->resolveDetailsOfLeaveFromRequest($request, $validated);
        }

        return $this->trimNullableString($app->details_of_leave);
    }

    private function trimNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));
        return $trimmed === '' ? null : $trimmed;
    }

    private function formatErmsLeaveBalancePayload(
        LeaveType $leaveType,
        ?LeaveBalance $balance,
        array $deductionHistory = [],
        ?float $ctoBalanceHours = null
    ): array
    {
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
    ): array
    {
        $type = $typesByName[strtolower(trim($leaveTypeName))] ?? null;
        if (!$type instanceof LeaveType) {
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
                if ($normalizedTypeId <= 0 || !is_array($entries) || $entries === []) {
                    continue;
                }

                if (!array_key_exists($normalizedTypeId, $merged)) {
                    $merged[$normalizedTypeId] = [];
                }

                foreach ($entries as $entry) {
                    if (!is_array($entry)) {
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
            if (!$application instanceof LeaveApplication) {
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
            if (!$deductsEmployeeBalance) {
                continue;
            }

            $approvedAt = $application->hr_approved_at ?? $application->created_at;
            $creditsDeducted = round((float) ($application->deductible_days ?? $application->total_days ?? 0), 2);
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
        return match ($status) {
            LeaveApplication::STATUS_PENDING_ADMIN, LeaveApplication::STATUS_PENDING_HR => 'Pending',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
            LeaveApplication::STATUS_RECALLED => 'Recalled',
            default => 'Pending',
        };
    }

    private function resolveEmployeeDisplayName(LeaveApplication $app): string
    {
        $employee = $this->resolveApplicationEmployee($app);
        $name = trim((string) (($employee?->firstname ?? '') . ' ' . ($employee?->surname ?? '')));
        if ($name !== '') {
            return $name;
        }

        $adminFallback = trim((string) ($app->applicantAdmin?->full_name ?? ''));
        if ($adminFallback !== '') {
            return $adminFallback;
        }

        if ($app->employee_control_no !== null) {
            return 'Employee ' . $this->normalizeControlNo((string) $app->employee_control_no);
        }

        return 'Employee';
    }

    private function adminCanManageApplication(DepartmentAdmin $admin, LeaveApplication $app): bool
    {
        $admin->loadMissing('department');
        $app->loadMissing('applicantAdmin.department');

        $applicationEmployee = $this->resolveApplicationEmployee($app);
        if (($applicationEmployee?->office ?? null) === $admin->department?->name) {
            return true;
        }

        if ((int) ($app->admin_id ?? 0) > 0 && (int) $app->admin_id === (int) $admin->id) {
            return true;
        }

        if ((int) ($app->admin_id ?? 0) > 0) {
            $reviewingAdminDepartmentId = DepartmentAdmin::query()
                ->whereKey((int) $app->admin_id)
                ->value('department_id');
            if ((int) ($reviewingAdminDepartmentId ?? 0) > 0
                && (int) $reviewingAdminDepartmentId === (int) ($admin->department_id ?? 0)
            ) {
                return true;
            }
        }

        return (int) ($app->applicantAdmin?->department_id ?? 0) > 0
            && (int) $app->applicantAdmin->department_id === (int) ($admin->department_id ?? 0);
    }

    private function resolveLeaveApplicationUpdateEmployeeControlNo(LeaveApplication $app): ?string
    {
        $controlNo = $this->resolveApplicationBalanceLookupControlNo($app);
        if ($controlNo !== null) {
            $controlNo = trim($controlNo);
            return $controlNo !== '' ? $controlNo : null;
        }

        $fallbackControlNo = trim((string) ($app->employee_control_no ?? ''));
        return $fallbackControlNo !== '' ? $fallbackControlNo : null;
    }

    private function resolveLeaveApplicationUpdateEmployeeName(LeaveApplication $app): ?string
    {
        $storedEmployeeName = trim((string) ($app->employee_name ?? ''));
        if ($storedEmployeeName !== '') {
            return $storedEmployeeName;
        }

        $employeeName = trim($this->formatEmployeeFullName($this->resolveApplicationEmployee($app)));
        if ($employeeName !== '') {
            return $employeeName;
        }

        $adminFallback = trim((string) ($app->applicantAdmin?->full_name ?? ''));
        return $adminFallback !== '' ? $adminFallback : null;
    }

    private function normalizeControlNo(mixed $value): string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return '';
        }

        if (preg_match('/^\d+$/', $normalized)) {
            $normalized = ltrim($normalized, '0');
            return $normalized !== '' ? $normalized : '0';
        }

        return $normalized;
    }

    private function formatEmployeeFullName(?object $employee): string
    {
        if (!is_object($employee)) {
            return '';
        }

        return trim(implode(' ', array_filter([
            trim((string) ($employee->firstname ?? $employee->first_name ?? '')),
            trim((string) ($employee->middlename ?? $employee->middle_name ?? '')),
            trim((string) ($employee->surname ?? $employee->last_name ?? '')),
            trim((string) ($employee->suffix ?? '')),
        ], static fn(string $part): bool => $part !== '')));
    }

    private function buildWorkflowActorDirectory(iterable $applications): array
    {
        $adminIds = [];
        $hrIds = [];
        $employeeNamesByControlNo = [];

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

            $employeeControlNo = $this->normalizeControlNo($application->employee_control_no);
            if ($employeeControlNo !== '') {
                $employeeName = $this->formatEmployeeFullName($this->resolveApplicationEmployee($application));
                if ($employeeName === '') {
                    $employeeName = trim((string) ($application->applicantAdmin?->full_name ?? ''));
                }
                if ($employeeName !== '') {
                    $employeeNamesByControlNo[$employeeControlNo] = $employeeName;
                }
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

        $adminNamesById = DepartmentAdmin::query()
            ->whereIn('id', $adminIds)
            ->pluck('full_name', 'id')
            ->map(fn($name) => trim((string) $name))
            ->all();

        $hrNamesById = HRAccount::query()
            ->whereIn('id', $hrIds)
            ->pluck('full_name', 'id')
            ->map(fn($name) => trim((string) $name))
            ->all();

        return [
            'admin' => $adminNamesById,
            'hr' => $hrNamesById,
            'employee' => $employeeNamesByControlNo,
        ];
    }

    private function resolveWorkflowPerformerName(
        ?LeaveApplicationLog $log,
        array $actorDirectory,
        string $fallbackEmployeeName = ''
    ): ?string {
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

    private function extractCancellationReason(mixed $remarks): ?string
    {
        $normalizedRemarks = trim((string) ($remarks ?? ''));
        if ($normalizedRemarks === '' || !$this->isCancelledRemark($normalizedRemarks)) {
            return null;
        }

        if (preg_match('/^cancelled(?:\s+via\s+[a-z0-9 _-]+)?\s*:\s*(.+)$/i', $normalizedRemarks, $matches)) {
            $reason = trim((string) ($matches[1] ?? ''));
            return $reason !== '' ? $reason : null;
        }

        return null;
    }

    private function mapWorkflowLogStage(LeaveApplicationLog $log): string
    {
        $remarks = trim((string) ($log->remarks ?? ''));
        $performerType = strtoupper((string) $log->performed_by_type);

        if ($log->action === LeaveApplicationLog::ACTION_SUBMITTED && preg_match('/^edit requested\b/i', $remarks)) {
            return 'employee requested edit';
        }

        if ($log->action === LeaveApplicationLog::ACTION_SUBMITTED && preg_match('/^cancellation request submitted by employee\b/i', $remarks)) {
            return 'employee requested cancellation';
        }

        if (
            $log->action === LeaveApplicationLog::ACTION_ADMIN_REJECTED
            && $performerType === LeaveApplicationLog::PERFORMER_EMPLOYEE
            && $this->isCancelledRemark($remarks)
        ) {
            return 'employee cancelled';
        }

        return match ($log->action) {
            LeaveApplicationLog::ACTION_SUBMITTED => match ($performerType) {
                LeaveApplicationLog::PERFORMER_ADMIN => 'department admin submitted',
                LeaveApplicationLog::PERFORMER_HR => 'hr submitted',
                default => 'employee submitted',
            },
            LeaveApplicationLog::ACTION_ADMIN_APPROVED => 'department admin approved',
            LeaveApplicationLog::ACTION_ADMIN_REJECTED => 'department admin rejected',
            LeaveApplicationLog::ACTION_HR_APPROVED => 'hr approved',
            LeaveApplicationLog::ACTION_HR_REJECTED => 'hr rejected',
            LeaveApplicationLog::ACTION_HR_RECALLED => 'hr recalled',
            LeaveApplicationLog::ACTION_HR_RECEIVED => 'received application',
            LeaveApplicationLog::ACTION_HR_RELEASED => 'released application',
            default => strtolower(str_replace('_', ' ', (string) $log->action)),
        };
    }

    private function formatErmsApplication(LeaveApplication $app, array $actorDirectory): array
    {
        $employeeName = trim((string) ($app->employee_name ?? ''));
        if ($employeeName === '') {
            $employeeName = $this->formatEmployeeFullName($this->resolveApplicationEmployee($app));
        }
        if ($employeeName === '') {
            $employeeName = trim((string) ($app->applicantAdmin?->full_name ?? ''));
        }
        if ($employeeName === '') {
            $employeeName = $this->resolveEmployeeDisplayName($app);
        }

        $logs = $app->relationLoaded('logs')
            ? $app->logs->sortBy(fn(LeaveApplicationLog $log) => $log->created_at?->timestamp ?? 0)->values()
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
        $hrReceivedLog = $logs->last(
            fn(LeaveApplicationLog $log) =>
                $log->action === LeaveApplicationLog::ACTION_HR_RECEIVED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );
        $hrReleasedLog = $logs->last(
            fn(LeaveApplicationLog $log) =>
                $log->action === LeaveApplicationLog::ACTION_HR_RELEASED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
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
        $cancelledLog = $logs->last(
            fn(LeaveApplicationLog $log) => $this->isCancelledRemark($log->remarks)
        );

        $filedBy = $this->resolveWorkflowPerformerName($submittedLog, $actorDirectory, $employeeName) ?? $employeeName;
        $adminActionBy = ($app->admin_id && isset($actorDirectory['admin'][(int) $app->admin_id]))
            ? $actorDirectory['admin'][(int) $app->admin_id]
            : $this->resolveWorkflowPerformerName($adminApprovedLog ?? $adminRejectedLog, $actorDirectory, $employeeName);
        $hrActionBy = $this->resolveWorkflowPerformerName($hrApprovedLog ?? $hrRejectedLog, $actorDirectory, $employeeName);
        if ($hrActionBy === null && $app->status !== LeaveApplication::STATUS_RECALLED && $app->hr_id && isset($actorDirectory['hr'][(int) $app->hr_id])) {
            $hrActionBy = $actorDirectory['hr'][(int) $app->hr_id];
        }
        $recallActionBy = $this->resolveWorkflowPerformerName($hrRecalledLog, $actorDirectory, $employeeName);
        if ($recallActionBy === null && $app->status === LeaveApplication::STATUS_RECALLED && $app->hr_id && isset($actorDirectory['hr'][(int) $app->hr_id])) {
            $recallActionBy = $actorDirectory['hr'][(int) $app->hr_id];
        }
        $receivedActionBy = $this->resolveWorkflowPerformerName($hrReceivedLog, $actorDirectory, $employeeName);
        $releasedActionBy = $this->resolveWorkflowPerformerName($hrReleasedLog, $actorDirectory, $employeeName);

        $isCancelled = $cancelledLog !== null || $this->isCancelledRemark($app->remarks);
        $hasPendingApprovedUpdateRequest = $this->hasPendingApprovedUpdateRequest($app);
        $displayStatus = $isCancelled
            ? 'Cancelled'
            : (
                $this->shouldPresentApprovedWhilePendingUpdate($app, $hasPendingApprovedUpdateRequest)
                    ? 'Approved'
                    : $this->ermsStatusLabel($app->status)
            );
        $cancellationReason = $this->extractCancellationReason($app->remarks);
        $cancelledBy = $cancelledLog
            ? ($this->resolveWorkflowPerformerName($cancelledLog, $actorDirectory, $employeeName) ?? $employeeName)
            : ($isCancelled ? $employeeName : null);

        $disapprovedBy = null;
        if ($app->status === LeaveApplication::STATUS_REJECTED) {
            if ($cancelledBy) {
                $disapprovedBy = $cancelledBy;
            } elseif ($hrRejectedLog || $app->hr_id) {
                $disapprovedBy = $this->resolveWorkflowPerformerName($hrRejectedLog, $actorDirectory, $employeeName)
                    ?? $hrActionBy;
            } elseif ($adminRejectedLog || $app->admin_id) {
                $disapprovedBy = $this->resolveWorkflowPerformerName($adminRejectedLog, $actorDirectory, $employeeName)
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
        $receivedActionAt = $hrReceivedLog?->created_at;
        $releasedActionAt = $hrReleasedLog?->created_at;
        $cancelledAt = $cancelledLog?->created_at ?? ($isCancelled ? $app->updated_at : null);

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

        $statusHistory = $logs->map(function (LeaveApplicationLog $log) use ($actorDirectory, $employeeName) {
            $actorName = $this->resolveWorkflowPerformerName($log, $actorDirectory, $employeeName);

            return [
                'action' => $log->action,
                'stage' => $this->mapWorkflowLogStage($log),
                'actor_name' => $actorName,
                'action_by_name' => $actorName,
                'action_by' => $actorName,
                'performed_by_type' => strtoupper((string) $log->performed_by_type),
                'remarks' => $log->remarks,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        })->values();

        $approverName = $processedBy ?? $hrActionBy ?? $adminActionBy;

        $selectedDates = $app->resolvedSelectedDates();
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);
        $latestUpdateMeta = $this->resolveLatestUpdateMeta($app);
        $pendingPayload = is_array($pendingUpdateMeta['payload'] ?? null) ? $pendingUpdateMeta['payload'] : [];
        $latestPayload = is_array($latestUpdateMeta['payload'] ?? null) ? $latestUpdateMeta['payload'] : [];
        $effectiveRecallDateKeys = $this->resolveEffectiveStoredRecallDateKeys($app);
        $normalizedPayMode = $this->normalizePayMode($app->pay_mode ?? null, (bool) $app->is_monetization);
        $withoutPay = $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY;
        $deductibleDays = $this->resolveApplicationDeductibleDays($app);
        $ctoDeductedHours = $this->resolveApplicationCtoDeductedHours($app);
        $resolvedReason = $this->trimNullableString($app->reason)
            ?? $this->trimNullableString($latestPayload['reason'] ?? null)
            ?? $this->trimNullableString($pendingPayload['reason'] ?? null);
        $resolvedDetailsOfLeave = $this->trimNullableString($app->details_of_leave)
            ?? $this->trimNullableString($latestPayload['details_of_leave'] ?? $latestPayload['detailsOfLeave'] ?? null)
            ?? $this->trimNullableString($pendingPayload['details_of_leave'] ?? $pendingPayload['detailsOfLeave'] ?? null);

        return [
            'id' => $app->id,
            'employee_control_no' => $app->employee_control_no ? (string) $app->employee_control_no : null,
            'leave_type_id' => $app->leave_type_id,
            'leave_type_name' => $app->leaveType?->name,
            'start_date' => $app->start_date?->toDateString(),
            'end_date' => $app->end_date?->toDateString(),
            'selected_dates' => $selectedDates,
            'selected_date_pay_status' => is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null,
            'selected_date_coverage' => is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
            'selected_date_half_day_portion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selectedDateHalfDayPortion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selected_date_half_day_period' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selectedDateHalfDayPeriod' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selected_date_halfday_period' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'total_days' => (float) $app->total_days,
            'deductible_days' => $deductibleDays,
            'cto_deducted_hours' => $ctoDeductedHours,
            'reason' => $resolvedReason,
            'reason_purpose' => $resolvedReason,
            'details_of_leave' => $resolvedDetailsOfLeave,
            'detailsOfLeave' => $resolvedDetailsOfLeave,
            'pay_mode' => $normalizedPayMode,
            'pay_status' => $withoutPay ? 'Without Pay' : 'With Pay',
            'without_pay' => $withoutPay,
            'with_pay' => !$withoutPay,
            'attachment_required' => (bool) ($app->attachment_required ?? false),
            'attachment_submitted' => (bool) ($app->attachment_submitted ?? false),
            'attachment_reference' => $this->trimNullableString($app->attachment_reference ?? null),
            'date_filed' => $app->created_at?->toDateString(),
            'filed_at' => $app->created_at?->toIso8601String(),
            'created_at' => $app->created_at?->toIso8601String(),
            'status' => $displayStatus,
            'raw_status' => $app->status,
            'remarks' => $app->remarks,
            'pending_update' => $pendingUpdateMeta['payload'],
            'pending_update_action_type' => $pendingUpdateMeta['action_type'],
            'pending_update_reason' => $pendingUpdateMeta['reason'],
            'pending_update_previous_status' => $pendingUpdateMeta['previous_status'],
            'pending_update_requested_by' => $pendingUpdateMeta['requested_by'],
            'pending_update_requested_at' => $pendingUpdateMeta['requested_at']?->toIso8601String(),
            'has_pending_update_request' => $hasPendingApprovedUpdateRequest,
            'latest_update_request_status' => $latestUpdateMeta['status'],
            'latest_update_request_payload' => $latestUpdateMeta['payload'],
            'latest_update_request_action_type' => $latestUpdateMeta['action_type'],
            'latest_update_request_reason' => $latestUpdateMeta['reason'],
            'latest_update_request_previous_status' => $latestUpdateMeta['previous_status'],
            'latest_update_requested_by' => $latestUpdateMeta['requested_by'],
            'latest_update_requested_at' => $latestUpdateMeta['requested_at']?->toIso8601String(),
            'latest_update_reviewed_at' => $latestUpdateMeta['reviewed_at']?->toIso8601String(),
            'latest_update_review_remarks' => $latestUpdateMeta['review_remarks'],
            'rejection_reason' => $app->status === LeaveApplication::STATUS_REJECTED && !$isCancelled ? $app->remarks : null,
            'employee_name' => $employeeName,
            'filed_by' => $filedBy,
            'approver_name' => $approverName,
            'admin_action_by' => $adminActionBy,
            'hr_action_by' => $hrActionBy,
            'received_by' => $receivedActionBy,
            'receivedBy' => $receivedActionBy,
            'hr_received_by' => $receivedActionBy,
            'released_by' => $releasedActionBy,
            'releasedBy' => $releasedActionBy,
            'hr_released_by' => $releasedActionBy,
            'recall_action_by' => $recallActionBy,
            'processed_by' => $processedBy,
            'disapproved_by' => $disapprovedBy,
            'cancelled_by' => $cancelledBy,
            'cancelled' => $isCancelled,
            'cancellation_reason' => $cancellationReason,
            'reviewed_at' => $reviewedAt?->toIso8601String(),
            'admin_action_at' => $adminActionAt?->toIso8601String(),
            'hr_action_at' => $hrActionAt?->toIso8601String(),
            'received_at' => $receivedActionAt?->toIso8601String(),
            'receivedAt' => $receivedActionAt?->toIso8601String(),
            'hr_received_at' => $receivedActionAt?->toIso8601String(),
            'released_at' => $releasedActionAt?->toIso8601String(),
            'releasedAt' => $releasedActionAt?->toIso8601String(),
            'hr_released_at' => $releasedActionAt?->toIso8601String(),
            'recall_action_at' => $recallActionAt?->toIso8601String(),
            'recallEffectiveDate' => $app->recall_effective_date?->toDateString(),
            'recall_effective_date' => $app->recall_effective_date?->toDateString(),
            'recallSelectedDates' => $effectiveRecallDateKeys !== [] ? $effectiveRecallDateKeys : null,
            'recall_selected_dates' => $effectiveRecallDateKeys !== [] ? $effectiveRecallDateKeys : null,
            'disapproved_at' => $disapprovedAt?->toIso8601String(),
            'cancelled_at' => $cancelledAt?->toIso8601String(),
            'has_hr_received' => $hrReceivedLog !== null,
            'hasHrReceived' => $hrReceivedLog !== null,
            'has_hr_released' => $hrReleasedLog !== null,
            'hasHrReleased' => $hrReleasedLog !== null,
            'status_history' => $statusHistory,
        ];
    }

    private function buildRequestedLeaveCancellationPayload(LeaveApplication $app, string $requestReason = ''): array
    {
        $leaveTypeId = $this->resolveCanonicalLeaveTypeId((int) ($app->leave_type_id ?? 0))
            ?? (int) ($app->leave_type_id ?? 0);

        return $this->withRequestedLeaveActionType([
            'leave_type_id' => $leaveTypeId > 0 ? $leaveTypeId : null,
            'leave_type_name' => $this->trimNullableString($app->leaveType?->name ?? null),
            'start_date' => $app->start_date?->toDateString(),
            'end_date' => $app->end_date?->toDateString(),
            'selected_dates' => $app->resolvedSelectedDates(),
            'selected_date_pay_status' => is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null,
            'selected_date_coverage' => is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
            'selected_date_half_day_portion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'total_days' => round((float) ($app->total_days ?? 0), 2),
            'deductible_days' => $this->resolveApplicationDeductibleDays($app),
            'reason' => $this->trimNullableString($requestReason) ?? $this->trimNullableString($app->reason ?? null),
            'details_of_leave' => $this->trimNullableString($app->details_of_leave ?? null),
            'cancel_reason' => $this->trimNullableString($requestReason),
            'commutation' => $this->trimNullableString($app->commutation ?? null) ?? 'Not Requested',
            'pay_mode' => $this->normalizePayMode($app->pay_mode ?? null, (bool) $app->is_monetization),
            'is_monetization' => (bool) $app->is_monetization,
            'attachment_required' => (bool) ($app->attachment_required ?? false),
            'attachment_submitted' => (bool) ($app->attachment_submitted ?? false),
            'attachment_reference' => $this->trimNullableString($app->attachment_reference ?? null),
        ], LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL);
    }

    private function buildRequestedLeaveUpdatePayload(Request $request, array $validated, LeaveApplication $app): array|JsonResponse
    {
        $requestedIsMonetization = array_key_exists('is_monetization', $validated)
            ? (bool) $validated['is_monetization']
            : (bool) $app->is_monetization;

        // Keep update flow scoped to editing values, not changing application mode.
        if ($requestedIsMonetization !== (bool) $app->is_monetization) {
            return response()->json([
                'message' => 'Switching between leave and monetization modes is not allowed in update requests.',
            ], 422);
        }

        $requestedReason = array_key_exists('reason', $validated) || array_key_exists('reason_purpose', $validated)
            ? $this->trimNullableString($validated['reason'] ?? $validated['reason_purpose'] ?? null)
            : $this->trimNullableString($app->reason);
        $requestedDetailsOfLeave = $this->resolveRequestedDetailsOfLeaveForUpdate($request, $validated, $app);

        $requestedSelectedDatePayStatus = $requestedIsMonetization
            ? null
            : $this->normalizeSelectedDatePayStatusMap(
                array_key_exists('selected_date_pay_status', $validated)
                    ? $validated['selected_date_pay_status']
                    : (
                        $request->input('selected_date_pay_status')
                        ?? (is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null)
                    )
            );
        $requestedSelectedDateCoverage = $requestedIsMonetization
            ? null
            : $this->normalizeSelectedDateCoverageMap(
                array_key_exists('selected_date_coverage', $validated)
                    ? $validated['selected_date_coverage']
                    : (
                        $request->input('selected_date_coverage')
                        ?? (is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null)
                    )
            );
        $requestedSelectedDateHalfDayPortion = $requestedIsMonetization
            ? null
            : $this->normalizeSelectedDateHalfDayPortionMap(
                array_key_exists('selected_date_half_day_portion', $validated)
                    ? $validated['selected_date_half_day_portion']
                    : (
                        $request->input('selected_date_half_day_portion')
                        ?? (is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null)
                    )
            );

        $resolvedStartDate = $requestedIsMonetization
            ? null
            : ($validated['start_date'] ?? $app->start_date?->toDateString());
        $resolvedEndDate = $requestedIsMonetization
            ? null
            : ($validated['end_date'] ?? $app->end_date?->toDateString());
        $resolvedSelectedDates = $requestedIsMonetization
            ? null
            : LeaveApplication::resolveSelectedDates(
                $resolvedStartDate,
                $resolvedEndDate,
                array_key_exists('selected_dates', $validated)
                    ? (is_array($validated['selected_dates']) ? $validated['selected_dates'] : null)
                    : $app->resolvedSelectedDates(),
                (float) ($validated['total_days'] ?? $app->total_days)
            );
        $resolvedTotalDays = array_key_exists('total_days', $validated)
            ? (float) $validated['total_days']
            : (float) $app->total_days;
        $resolvedPayMode = $this->resolveRequestedPayMode(
            $request,
            $validated,
            $requestedIsMonetization,
            $app->pay_mode ?? LeaveApplication::PAY_MODE_WITH_PAY
        );
        $requestedSelectedDatePayStatus = $requestedIsMonetization
            ? null
            : $this->compactSelectedDatePayStatusMap(
                $requestedSelectedDatePayStatus,
                $resolvedSelectedDates,
                $resolvedPayMode
            );
        $selectedDateCoverageMetadata = $requestedIsMonetization
            ? [
                'selectedDateCoverage' => null,
                'selectedDateHalfDayPortion' => null,
            ]
            : $this->synchronizeSelectedDateCoverageMetadata(
                $requestedSelectedDateCoverage,
                $requestedSelectedDateHalfDayPortion,
                $resolvedSelectedDates
            );
        $requestedSelectedDateCoverage = $selectedDateCoverageMetadata['selectedDateCoverage'];
        $requestedSelectedDateHalfDayPortion = $selectedDateCoverageMetadata['selectedDateHalfDayPortion'];

        $targetLeaveTypeId = $this->resolveCanonicalLeaveTypeId((int) ($validated['leave_type_id'] ?? $app->leave_type_id))
            ?? (int) ($validated['leave_type_id'] ?? $app->leave_type_id);
        $targetLeaveType = LeaveType::find($targetLeaveTypeId);
        if (!$targetLeaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
                'errors' => [
                    'leave_type_id' => ['Selected leave type is not available.'],
                ],
            ], 422);
        }

        if ($requestedIsMonetization) {
            $monetizationPolicyRestriction = $this->validateMonetizationPolicyRules($targetLeaveType, $resolvedTotalDays);
            if ($monetizationPolicyRestriction instanceof JsonResponse) {
                return $monetizationPolicyRestriction;
            }
        }

        $attachmentState = $this->resolveAttachmentStateFromRequest(
            $request,
            $validated,
            (bool) ($app->attachment_submitted ?? false),
            $this->trimNullableString($app->attachment_reference ?? null)
        );

        $policyResolution = $this->applyRegularLeavePolicy(
            $targetLeaveType,
            $resolvedTotalDays,
            is_array($resolvedSelectedDates) ? $resolvedSelectedDates : null,
            $requestedSelectedDateCoverage,
            $requestedSelectedDatePayStatus,
            $resolvedPayMode,
            $requestedIsMonetization,
            (bool) ($attachmentState['attachment_submitted'] ?? false),
            $attachmentState['attachment_reference'] ?? null,
            true,
            $request->input('date_filed') ?? $request->input('dateOfFiling') ?? now(),
            $resolvedStartDate,
            $resolvedEndDate,
            (string) ($app->employee_control_no ?? '')
        );
        if ($policyResolution instanceof JsonResponse) {
            return $policyResolution;
        }

        $resolvedPayMode = $policyResolution['pay_mode'];
        $requestedSelectedDatePayStatus = $policyResolution['selected_date_pay_status'];
        $resolvedDeductibleDays = (float) ($policyResolution['deductible_days'] ?? 0);
        $resolvedCtoDeductedHours = (float) ($policyResolution['cto_deducted_hours'] ?? 0);
        $attachmentRequired = (bool) ($policyResolution['attachment_required'] ?? false);
        $attachmentSubmitted = (bool) ($policyResolution['attachment_submitted'] ?? false);
        $attachmentReference = $policyResolution['attachment_reference'] ?? null;

        $rawPayload = [
            'leave_type_id' => $targetLeaveTypeId,
            'start_date' => $resolvedStartDate,
            'end_date' => $resolvedEndDate,
            'selected_dates' => $resolvedSelectedDates,
            'selected_date_pay_status' => $requestedSelectedDatePayStatus,
            'selected_date_coverage' => $requestedSelectedDateCoverage,
            'selected_date_half_day_portion' => $requestedSelectedDateHalfDayPortion,
            'total_days' => $resolvedTotalDays,
            'deductible_days' => $resolvedDeductibleDays,
            'cto_deducted_hours' => $resolvedCtoDeductedHours,
            'reason' => $requestedReason,
            'details_of_leave' => $requestedDetailsOfLeave,
            'commutation' => $requestedIsMonetization
                ? LeaveApplication::MONETIZATION_REQUIRED_COMMUTATION
                : (
                    array_key_exists('commutation', $validated)
                        ? $validated['commutation']
                        : ($app->commutation ?? 'Not Requested')
                ),
            'pay_mode' => $resolvedPayMode,
            'is_monetization' => $requestedIsMonetization,
            'attachment_required' => $attachmentRequired,
            'attachment_submitted' => $attachmentSubmitted,
            'attachment_reference' => $attachmentReference,
        ];

        $payload = $this->normalizePendingUpdatePayload($rawPayload);
        if ($payload === null) {
            return response()->json([
                'message' => 'The update request payload is invalid.',
            ], 422);
        }

        if ($payload['leave_type_id'] <= 0) {
            return response()->json([
                'message' => 'The leave_type_id field is required for update requests.',
            ], 422);
        }

        if (($payload['total_days'] ?? 0) <= 0) {
            return response()->json([
                'message' => 'The total_days field must be greater than zero.',
            ], 422);
        }

        if (!(bool) $payload['is_monetization']) {
            if (($payload['start_date'] ?? null) === null || ($payload['end_date'] ?? null) === null) {
                return response()->json([
                    'message' => 'Both start_date and end_date are required for leave update requests.',
                ], 422);
            }
        }

        return $payload;
    }

    private function hasRequestedLeaveUpdateChanges(LeaveApplication $app, array $requestedPayload): bool
    {
        $currentPayload = $this->normalizePendingUpdatePayload([
            'leave_type_id' => (int) $app->leave_type_id,
            'start_date' => $app->start_date?->toDateString(),
            'end_date' => $app->end_date?->toDateString(),
            'selected_dates' => $app->resolvedSelectedDates(),
            'selected_date_pay_status' => is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null,
            'selected_date_coverage' => is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
            'selected_date_half_day_portion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'total_days' => (float) $app->total_days,
            'deductible_days' => $this->resolveApplicationDeductibleDays($app),
            'cto_deducted_hours' => $this->resolveApplicationCtoDeductedHours($app),
            'reason' => $app->reason,
            'details_of_leave' => $app->details_of_leave,
            'commutation' => $app->commutation ?? 'Not Requested',
            'pay_mode' => $this->normalizePayMode($app->pay_mode ?? null, (bool) $app->is_monetization),
            'is_monetization' => (bool) $app->is_monetization,
            'attachment_required' => (bool) ($app->attachment_required ?? false),
            'attachment_submitted' => (bool) ($app->attachment_submitted ?? false),
            'attachment_reference' => $this->trimNullableString($app->attachment_reference ?? null),
        ]);

        $normalizedRequestedPayload = $this->normalizePendingUpdatePayload($requestedPayload);

        if ($currentPayload === null || $normalizedRequestedPayload === null) {
            return true;
        }

        if ((int) $currentPayload['leave_type_id'] !== (int) $normalizedRequestedPayload['leave_type_id']) {
            return true;
        }

        if ((bool) $currentPayload['is_monetization'] !== (bool) $normalizedRequestedPayload['is_monetization']) {
            return true;
        }

        if (($currentPayload['start_date'] ?? null) !== ($normalizedRequestedPayload['start_date'] ?? null)) {
            return true;
        }

        if (($currentPayload['end_date'] ?? null) !== ($normalizedRequestedPayload['end_date'] ?? null)) {
            return true;
        }

        if (round((float) ($currentPayload['total_days'] ?? 0), 2) !== round((float) ($normalizedRequestedPayload['total_days'] ?? 0), 2)) {
            return true;
        }

        if (($currentPayload['selected_dates'] ?? null) !== ($normalizedRequestedPayload['selected_dates'] ?? null)) {
            return true;
        }

        if (($currentPayload['selected_date_pay_status'] ?? null) !== ($normalizedRequestedPayload['selected_date_pay_status'] ?? null)) {
            return true;
        }

        if (($currentPayload['selected_date_coverage'] ?? null) !== ($normalizedRequestedPayload['selected_date_coverage'] ?? null)) {
            return true;
        }

        if (($currentPayload['selected_date_half_day_portion'] ?? null) !== ($normalizedRequestedPayload['selected_date_half_day_portion'] ?? null)) {
            return true;
        }

        if ($this->trimNullableString($currentPayload['reason'] ?? null) !== $this->trimNullableString($normalizedRequestedPayload['reason'] ?? null)) {
            return true;
        }

        if (
            $this->trimNullableString($currentPayload['details_of_leave'] ?? null)
            !== $this->trimNullableString($normalizedRequestedPayload['details_of_leave'] ?? null)
        ) {
            return true;
        }

        if (round((float) ($currentPayload['deductible_days'] ?? 0), 2) !== round((float) ($normalizedRequestedPayload['deductible_days'] ?? 0), 2)) {
            return true;
        }

        if (round((float) ($currentPayload['cto_deducted_hours'] ?? 0), 2) !== round((float) ($normalizedRequestedPayload['cto_deducted_hours'] ?? 0), 2)) {
            return true;
        }

        if (($currentPayload['pay_mode'] ?? LeaveApplication::PAY_MODE_WITH_PAY) !== ($normalizedRequestedPayload['pay_mode'] ?? LeaveApplication::PAY_MODE_WITH_PAY)) {
            return true;
        }

        if ((bool) ($currentPayload['attachment_required'] ?? false) !== (bool) ($normalizedRequestedPayload['attachment_required'] ?? false)) {
            return true;
        }

        if ((bool) ($currentPayload['attachment_submitted'] ?? false) !== (bool) ($normalizedRequestedPayload['attachment_submitted'] ?? false)) {
            return true;
        }

        if (
            $this->trimNullableString($currentPayload['attachment_reference'] ?? null)
            !== $this->trimNullableString($normalizedRequestedPayload['attachment_reference'] ?? null)
        ) {
            return true;
        }

        return ($currentPayload['commutation'] ?? 'Not Requested') !== ($normalizedRequestedPayload['commutation'] ?? 'Not Requested');
    }

    private function hasPendingApprovedUpdateRequest(LeaveApplication $app): bool
    {
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);

        return ($pendingUpdateMeta['payload'] ?? null) !== null
            && strtoupper(trim((string) ($pendingUpdateMeta['previous_status'] ?? ''))) === LeaveApplication::STATUS_APPROVED;
    }

    private function shouldPresentApprovedWhilePendingUpdate(
        LeaveApplication $app,
        bool $hasPendingApprovedUpdateRequest
    ): bool {
        if (!$hasPendingApprovedUpdateRequest) {
            return false;
        }

        return in_array($app->status, [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ], true);
    }

    private function normalizeRequestedLeaveActionTypeToken(mixed $value): string
    {
        $normalized = strtoupper(trim((string) ($value ?? '')));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        if (
            in_array($normalized, [
                LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL,
                'CANCEL',
                'REQUEST_CANCELLATION',
                'REQUEST_CANCELATION',
                'CANCEL_REQUEST',
            ], true)
        ) {
            return LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL;
        }

        return LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE;
    }

    private function resolveUpdateRequestActionTypeFromPayload(mixed $payload): string
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        if (!is_array($payload)) {
            return LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE;
        }

        $flagValues = [
            $payload['cancel_leave'] ?? null,
            $payload['request_cancel'] ?? null,
            $payload['is_cancel_request'] ?? null,
        ];
        foreach ($flagValues as $value) {
            if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
                return LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL;
            }
        }

        $actionType = $this->normalizeRequestedLeaveActionTypeToken(
            $payload['action_type']
            ?? $payload['request_type']
            ?? $payload['request_kind']
            ?? $payload['mode']
            ?? ''
        );

        return $actionType;
    }

    private function withRequestedLeaveActionType(array $payload, string $actionType): array
    {
        $normalizedActionType = $this->normalizeRequestedLeaveActionTypeToken($actionType);
        $requestKind = $normalizedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
            ? 'cancel'
            : 'update';

        return array_merge($payload, [
            'action_type' => $normalizedActionType,
            'request_kind' => $requestKind,
            'cancel_leave' => $normalizedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL,
        ]);
    }

    private function resolvePendingApprovedUpdateActionType(LeaveApplication $app): string
    {
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);
        return $this->resolveUpdateRequestActionTypeFromPayload($pendingUpdateMeta['payload'] ?? null);
    }

    private function resolveLatestApprovedUpdateActionType(LeaveApplication $app): string
    {
        $latestUpdateMeta = $this->resolveLatestUpdateMeta($app);
        $latestStatus = strtoupper(trim((string) ($latestUpdateMeta['status'] ?? '')));
        $previousStatus = strtoupper(trim((string) ($latestUpdateMeta['previous_status'] ?? '')));

        if (
            $latestStatus !== LeaveApplicationUpdateRequest::STATUS_APPROVED
            || $previousStatus !== LeaveApplication::STATUS_APPROVED
        ) {
            return LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE;
        }

        return $this->resolveUpdateRequestActionTypeFromPayload($latestUpdateMeta['payload'] ?? null);
    }

    private function isPendingApprovedUpdateRequest(LeaveApplication $app): bool
    {
        if ($app->status !== LeaveApplication::STATUS_PENDING_HR) {
            return false;
        }

        return $this->hasPendingApprovedUpdateRequest($app);
    }

    private function hrApprovePendingUpdateRequest(Request $request, LeaveApplication $app, HRAccount $hr): JsonResponse
    {
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);
        $payload = $pendingUpdateMeta['payload'] ?? null;
        $pendingUpdateRequest = $pendingUpdateMeta['request_record'] ?? null;

        if ($payload === null) {
            return response()->json([
                'message' => 'No pending update payload was found for this application.',
            ], 422);
        }
        $pendingActionType = $this->resolveUpdateRequestActionTypeFromPayload($payload);
        if ($pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL) {
            return $this->hrApprovePendingCancellationRequest(
                $request,
                $app,
                $hr,
                $pendingUpdateRequest,
                $pendingUpdateMeta
            );
        }

        $targetLeaveTypeId = $this->resolveCanonicalLeaveTypeId((int) ($payload['leave_type_id'] ?? 0))
            ?? (int) ($payload['leave_type_id'] ?? 0);
        $targetLeaveType = LeaveType::find($targetLeaveTypeId);
        if (!$targetLeaveType) {
            return response()->json([
                'message' => 'The requested leave type for this update no longer exists.',
            ], 422);
        }

        $targetIsMonetization = (bool) ($payload['is_monetization'] ?? $app->is_monetization);
        if ($targetIsMonetization !== (bool) $app->is_monetization) {
            return response()->json([
                'message' => 'This update request contains an unsupported application mode change.',
            ], 422);
        }
        $targetPayMode = $this->normalizePayMode($payload['pay_mode'] ?? null, $targetIsMonetization);

        $targetTotalDays = round((float) ($payload['total_days'] ?? 0), 2);
        if ($targetTotalDays <= 0) {
            return response()->json([
                'message' => 'Invalid total_days value in the pending update request.',
            ], 422);
        }

        $targetStartDate = $targetIsMonetization ? null : $this->trimNullableString($payload['start_date'] ?? null);
        $targetEndDate = $targetIsMonetization ? null : $this->trimNullableString($payload['end_date'] ?? null);
        $targetSelectedDates = $targetIsMonetization
            ? null
            : LeaveApplication::resolveSelectedDates(
                $targetStartDate,
                $targetEndDate,
                is_array($payload['selected_dates'] ?? null) ? $payload['selected_dates'] : null,
                $targetTotalDays
            );
        $targetSelectedDatePayStatus = $targetIsMonetization
            ? null
            : $this->compactSelectedDatePayStatusMap(
                $this->normalizeSelectedDatePayStatusMap($payload['selected_date_pay_status'] ?? null),
                $targetSelectedDates,
                $targetPayMode
            );
        $targetSelectedDateCoverageMetadata = $targetIsMonetization
            ? [
                'selectedDateCoverage' => null,
                'selectedDateHalfDayPortion' => null,
            ]
            : $this->synchronizeSelectedDateCoverageMetadata(
                $this->normalizeSelectedDateCoverageMap($payload['selected_date_coverage'] ?? null),
                $this->mergeSelectedDateHalfDayPortionMaps(
                    $this->normalizeSelectedDateHalfDayPortionMap($payload['selected_date_half_day_portion'] ?? null),
                    $this->normalizeSelectedDateHalfDayPortionMap($payload['selected_date_coverage'] ?? null)
                ),
                $targetSelectedDates
            );
        $targetSelectedDateCoverage = $targetSelectedDateCoverageMetadata['selectedDateCoverage'];
        $targetSelectedDateHalfDayPortion = $targetSelectedDateCoverageMetadata['selectedDateHalfDayPortion'];
        $existingRecallDateKeys = $this->resolveEffectiveStoredRecallDateKeys($app);
        $targetSelectedDateKeys = $this->normalizeRecallDateKeys(
            is_array($targetSelectedDates) ? $targetSelectedDates : []
        );
        $updatedRecallDateKeys = array_values(array_diff($existingRecallDateKeys, $targetSelectedDateKeys));
        sort($updatedRecallDateKeys);
        $updatedRecallEffectiveDate = $updatedRecallDateKeys !== []
            ? $updatedRecallDateKeys[0]
            : null;

        $targetAttachmentState = $this->resolveAttachmentStateFromPayload(
            is_array($payload) ? $payload : [],
            (bool) ($app->attachment_submitted ?? false),
            $this->trimNullableString($app->attachment_reference ?? null)
        );
        $policyResolution = $this->applyRegularLeavePolicy(
            $targetLeaveType,
            $targetTotalDays,
            is_array($targetSelectedDates) ? $targetSelectedDates : null,
            $targetSelectedDateCoverage,
            $targetSelectedDatePayStatus,
            $targetPayMode,
            $targetIsMonetization,
            (bool) ($targetAttachmentState['attachment_submitted'] ?? false),
            $targetAttachmentState['attachment_reference'] ?? null,
            true,
            $pendingUpdateRequest?->created_at ?? now(),
            $targetStartDate,
            $targetEndDate,
            (string) ($app->employee_control_no ?? '')
        );
        if ($policyResolution instanceof JsonResponse) {
            return $policyResolution;
        }

        $targetPayMode = $policyResolution['pay_mode'];
        $targetSelectedDatePayStatus = $policyResolution['selected_date_pay_status'];
        $targetDeductibleDays = (float) ($policyResolution['deductible_days'] ?? 0);
        $targetCtoDeductedHours = (float) ($policyResolution['cto_deducted_hours'] ?? 0);
        $targetAttachmentRequired = (bool) ($policyResolution['attachment_required'] ?? false);
        $targetAttachmentSubmitted = (bool) ($policyResolution['attachment_submitted'] ?? false);
        $targetAttachmentReference = $policyResolution['attachment_reference'] ?? null;

        if (!$targetIsMonetization && ($targetStartDate === null || $targetEndDate === null)) {
            return response()->json([
                'message' => 'Pending update request is missing start_date or end_date.',
            ], 422);
        }

        if (!$targetIsMonetization) {
            $duplicateDateValidation = $this->validateNoDuplicateLeaveDates(
                (string) ($app->employee_control_no ?? ''),
                (string) $targetStartDate,
                (string) $targetEndDate,
                is_array($targetSelectedDates) ? $targetSelectedDates : null,
                $targetTotalDays,
                (int) $app->id
            );
            if ($duplicateDateValidation instanceof JsonResponse) {
                return $duplicateDateValidation;
            }
        }

        if ($targetLeaveType->max_days && $targetTotalDays > (float) $targetLeaveType->max_days) {
            return response()->json([
                'message' => "This leave type is limited to {$targetLeaveType->max_days} days per application.",
                'errors' => [
                    'total_days' => ["Maximum of {$targetLeaveType->max_days} days allowed for {$targetLeaveType->name}."],
                ],
            ], 422);
        }

        $applicationEmployee = $this->resolveApplicationEmployee($app);
        if ($applicationEmployee) {
            $currentLeaveTypeId = $this->resolveCanonicalLeaveTypeId((int) ($app->leave_type_id ?? 0))
                ?? (int) ($app->leave_type_id ?? 0);
            $skipEventBasedReuseRule = $currentLeaveTypeId > 0 && $currentLeaveTypeId === $targetLeaveTypeId;
            $leaveTypeRestriction = $this->assertEmployeeCanApplyForLeaveType(
                $applicationEmployee,
                $targetLeaveType,
                $skipEventBasedReuseRule
            );
            if ($leaveTypeRestriction instanceof JsonResponse) {
                return $leaveTypeRestriction;
            }
        }

        $sourceLeaveType = LeaveType::find((int) $app->leave_type_id);
        $sourceDeductibleDays = $this->resolveApplicationDeductibleDays($app);
        $sourceDeductsBalance = $this->applicationDeductsEmployeeBalance(
            (bool) $app->is_monetization,
            $sourceLeaveType,
            $app->pay_mode
        );
        $targetDeductsBalance = $this->applicationDeductsEmployeeBalance(
            $targetIsMonetization,
            $targetLeaveType,
            $targetPayMode
        );

        if ($targetIsMonetization) {
            $monetizationPolicyRestriction = $this->validateMonetizationPolicyRules($targetLeaveType, $targetTotalDays);
            if ($monetizationPolicyRestriction instanceof JsonResponse) {
                return $monetizationPolicyRestriction;
            }

            $effectiveMonetizationBalance = $this->resolveMonetizationApprovalAvailableBalance(
                $app,
                $targetLeaveTypeId,
                $sourceLeaveType,
                $sourceDeductibleDays,
                $sourceDeductsBalance
            );
            $monetizationBalanceRestriction = $this->validateMonetizationBalanceRules(
                $effectiveMonetizationBalance,
                $targetTotalDays
            );
            if ($monetizationBalanceRestriction instanceof JsonResponse) {
                return $monetizationBalanceRestriction;
            }
        }

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        $targetShouldDeductForcedLeave = $this->shouldLeaveTypeDeductForcedLeave(
            $targetLeaveType,
            $targetLeaveTypeId,
            $targetIsMonetization,
            $forcedLeaveTypeId
        );
        $targetShouldDeductVacationLeave = $this->shouldLeaveTypeDeductVacationLeave(
            $targetLeaveType,
            $targetLeaveTypeId,
            $targetIsMonetization,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );
        $targetIsCtoDeduction = $this->isCtoLeaveType($targetLeaveType, $targetLeaveTypeId);

        if ($app->employee_control_no) {
            $this->syncEmployeeCtoBalance((string) $app->employee_control_no);
        }

        $balanceConflictError = 'HR_UPDATE_BALANCE_CONFLICT';
        $linkedForcedLeaveDeductedDays = 0.0;
        $linkedVacationLeaveDeductedDays = 0.0;

        try {
            DB::transaction(function () use (
                $app,
                $hr,
                $request,
                $pendingUpdateRequest,
                $payload,
                $targetLeaveTypeId,
                $targetStartDate,
                $targetEndDate,
                $targetSelectedDates,
                $targetSelectedDatePayStatus,
                $targetSelectedDateCoverage,
                $targetSelectedDateHalfDayPortion,
                $targetTotalDays,
                $targetDeductibleDays,
                $targetCtoDeductedHours,
                $targetIsMonetization,
                $targetPayMode,
                $targetAttachmentRequired,
                $targetAttachmentSubmitted,
                $targetAttachmentReference,
                $sourceLeaveType,
                $sourceDeductibleDays,
                $sourceDeductsBalance,
                $targetLeaveType,
                $targetDeductsBalance,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId,
                $targetShouldDeductForcedLeave,
                $targetShouldDeductVacationLeave,
                $targetIsCtoDeduction,
                $balanceConflictError,
                $updatedRecallDateKeys,
                $updatedRecallEffectiveDate,
                &$linkedForcedLeaveDeductedDays,
                &$linkedVacationLeaveDeductedDays
            ): void {
                if ($sourceDeductsBalance && $sourceDeductibleDays > 0.0) {
                    $this->refundApplicationTrackedDeductions(
                        $app,
                        $sourceLeaveType,
                        $sourceDeductibleDays,
                        $balanceConflictError
                    );
                }

                if ($targetDeductsBalance && $targetDeductibleDays > 0.0) {
                    $linkedDeductions = $this->deductApplicationTrackedBalances(
                        $app,
                        $targetLeaveTypeId,
                        $targetDeductibleDays,
                        $targetTotalDays,
                        $targetIsCtoDeduction,
                        $targetShouldDeductForcedLeave,
                        $forcedLeaveTypeId,
                        $targetShouldDeductVacationLeave,
                        $vacationLeaveTypeId,
                        $balanceConflictError
                    );
                    $linkedForcedLeaveDeductedDays = (float) ($linkedDeductions['linked_forced_leave_deducted_days'] ?? 0.0);
                    $linkedVacationLeaveDeductedDays = (float) ($linkedDeductions['linked_vacation_leave_deducted_days'] ?? 0.0);
                }

                $app->update([
                    'leave_type_id' => $targetLeaveTypeId,
                    'start_date' => $targetIsMonetization ? null : $targetStartDate,
                    'end_date' => $targetIsMonetization ? null : $targetEndDate,
                    'total_days' => $targetTotalDays,
                    'deductible_days' => $targetDeductibleDays,
                    'cto_deducted_hours' => $this->hasLeaveApplicationCtoHoursColumn() && $targetIsCtoDeduction && $targetCtoDeductedHours > 0 ? $targetCtoDeductedHours : null,
                    'reason' => $this->trimNullableString($payload['reason'] ?? null),
                    'details_of_leave' => $this->trimNullableString($payload['details_of_leave'] ?? null),
                    'selected_dates' => $targetIsMonetization ? null : $targetSelectedDates,
                    'selected_date_pay_status' => $targetIsMonetization ? null : $targetSelectedDatePayStatus,
                    'selected_date_coverage' => $targetIsMonetization ? null : $targetSelectedDateCoverage,
                    'selected_date_half_day_portion' => $targetIsMonetization ? null : $targetSelectedDateHalfDayPortion,
                    'commutation' => (string) ($payload['commutation'] ?? 'Not Requested'),
                    'pay_mode' => $targetPayMode,
                    'linked_forced_leave_deducted_days' => $linkedForcedLeaveDeductedDays,
                    'linked_vacation_leave_deducted_days' => $linkedVacationLeaveDeductedDays,
                    'attachment_required' => $targetAttachmentRequired,
                    'attachment_submitted' => $targetAttachmentSubmitted,
                    'attachment_reference' => $targetAttachmentReference,
                    'is_monetization' => $targetIsMonetization,
                    'status' => LeaveApplication::STATUS_APPROVED,
                    'hr_id' => $hr->id,
                    'hr_approved_at' => now(),
                    'recall_effective_date' => $updatedRecallEffectiveDate,
                    'recall_selected_dates' => $updatedRecallDateKeys !== [] ? $updatedRecallDateKeys : null,
                    'remarks' => $request->input('remarks'),
                ]);

                if ($app->employee_control_no) {
                    $this->syncEmployeeCtoBalance((string) $app->employee_control_no, true);
                }

                if ($pendingUpdateRequest instanceof LeaveApplicationUpdateRequest) {
                    $pendingUpdateRequest->update([
                        'status' => LeaveApplicationUpdateRequest::STATUS_APPROVED,
                        'reviewed_by_hr_id' => $hr->id,
                        'reviewed_at' => now(),
                        'review_remarks' => $request->input('remarks'),
                    ]);
                }

                LeaveApplicationLog::create([
                    'leave_application_id' => $app->id,
                    'action' => LeaveApplicationLog::ACTION_HR_APPROVED,
                    'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                    'performed_by_id' => $hr->id,
                    'remarks' => $request->input('remarks') ?: 'Approved leave update request and applied changes.',
                    'created_at' => now(),
                ]);
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== $balanceConflictError) {
                throw $exception;
            }

            return response()->json([
                'message' => 'Insufficient leave balance to apply this approved update request.',
            ], 422);
        }

        $app = $app->fresh(['leaveType', 'applicantAdmin']);
        $this->smsGatewayService()->sendLeaveApprovedMessage($app);

        return response()->json([
            'message' => 'Leave update request approved by HR and changes were applied.',
            'application' => $this->formatApplication($app),
        ]);
    }

    private function hrApprovePendingCancellationRequest(
        Request $request,
        LeaveApplication $app,
        HRAccount $hr,
        mixed $pendingUpdateRequest = null,
        array $pendingUpdateMeta = []
    ): JsonResponse {
        $sourceLeaveType = LeaveType::find((int) $app->leave_type_id);
        $sourceDeductibleDays = $this->resolveApplicationDeductibleDays($app);
        $sourceDeductsBalance = $this->applicationDeductsEmployeeBalance(
            (bool) $app->is_monetization,
            $sourceLeaveType,
            $app->pay_mode
        );
        $cancelReason = trim((string) (
            $pendingUpdateMeta['reason']
            ?? $request->input('remarks')
            ?? ''
        ));
        $cancelRemarks = $cancelReason !== ''
            ? "Cancelled via approved leave cancellation request: {$cancelReason}"
            : 'Cancelled via approved leave cancellation request.';
        $balanceConflictError = 'HR_CANCEL_REQUEST_BALANCE_CONFLICT';

        if ($app->employee_control_no) {
            $this->syncEmployeeCtoBalance((string) $app->employee_control_no);
        }

        try {
            DB::transaction(function () use (
                $app,
                $hr,
                $request,
                $pendingUpdateRequest,
                $sourceLeaveType,
                $sourceDeductibleDays,
                $sourceDeductsBalance,
                $cancelRemarks,
                $balanceConflictError
            ): void {
                if ($sourceDeductsBalance && $sourceDeductibleDays > 0.0) {
                    $this->refundApplicationTrackedDeductions(
                        $app,
                        $sourceLeaveType,
                        $sourceDeductibleDays,
                        $balanceConflictError
                    );
                }

                $app->update([
                    'status' => LeaveApplication::STATUS_REJECTED,
                    'hr_id' => $hr->id,
                    'hr_approved_at' => now(),
                    'remarks' => $cancelRemarks,
                    'deductible_days' => 0,
                    'linked_forced_leave_deducted_days' => 0,
                    'linked_vacation_leave_deducted_days' => 0,
                ]);

                if ($app->employee_control_no) {
                    $this->syncEmployeeCtoBalance((string) $app->employee_control_no, true);
                }

                if ($pendingUpdateRequest instanceof LeaveApplicationUpdateRequest) {
                    $pendingUpdateRequest->update([
                        'status' => LeaveApplicationUpdateRequest::STATUS_APPROVED,
                        'reviewed_by_hr_id' => $hr->id,
                        'reviewed_at' => now(),
                        'review_remarks' => $request->input('remarks'),
                    ]);
                }

                $hrLogRemarks = $this->trimNullableString($request->input('remarks'));
                $hrLogRemarks = $hrLogRemarks !== null
                    ? "Cancelled via approved leave cancellation request: {$hrLogRemarks}"
                    : $cancelRemarks;

                LeaveApplicationLog::create([
                    'leave_application_id' => $app->id,
                    'action' => LeaveApplicationLog::ACTION_HR_APPROVED,
                    'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                    'performed_by_id' => $hr->id,
                    'remarks' => $hrLogRemarks,
                    'created_at' => now(),
                ]);
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== $balanceConflictError) {
                throw $exception;
            }

            return response()->json([
                'message' => 'Unable to apply this leave cancellation request due to leave balance mismatch. Please refresh and try again.',
            ], 409);
        }

        $app = $app->fresh(['leaveType', 'applicantAdmin']);

        return response()->json([
            'message' => 'Leave cancellation request approved by HR. Application has been cancelled.',
            'application' => $this->formatApplication($app),
        ]);
    }

    private function hrRejectPendingUpdateRequest(Request $request, LeaveApplication $app, HRAccount $hr): JsonResponse
    {
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);
        $pendingUpdateRequest = $pendingUpdateMeta['request_record'] ?? null;
        $pendingActionType = $this->resolveUpdateRequestActionTypeFromPayload($pendingUpdateMeta['payload'] ?? null);

        $previousStatus = strtoupper(trim((string) ($pendingUpdateMeta['previous_status'] ?? '')));
        if ($previousStatus !== LeaveApplication::STATUS_APPROVED) {
            $previousStatus = LeaveApplication::STATUS_APPROVED;
        }

        DB::transaction(function () use (
            $app,
            $request,
            $hr,
            $previousStatus,
            $pendingUpdateRequest,
            $pendingActionType
        ): void {
            $app->update([
                'status' => $previousStatus,
                'remarks' => $request->input('remarks'),
            ]);

            if ($pendingUpdateRequest instanceof LeaveApplicationUpdateRequest) {
                $pendingUpdateRequest->update([
                    'status' => LeaveApplicationUpdateRequest::STATUS_REJECTED,
                    'reviewed_by_hr_id' => $hr->id,
                    'reviewed_at' => now(),
                    'review_remarks' => $request->input('remarks'),
                ]);
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_REJECTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $request->input('remarks') ?: (
                    $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                        ? 'Rejected leave cancellation request.'
                        : 'Rejected leave update request.'
                ),
                'created_at' => now(),
            ]);
        });

        $app = $app->fresh(['leaveType', 'applicantAdmin']);

        return response()->json([
            'message' => $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                ? 'Leave cancellation request rejected by HR. Original approved application remains unchanged.'
                : 'Leave update request rejected by HR. Original approved application remains unchanged.',
            'application' => $this->formatApplication($app),
        ]);
    }

    private function adminRejectPendingUpdateRequest(
        Request $request,
        LeaveApplication $app,
        DepartmentAdmin $admin
    ): JsonResponse {
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);
        $pendingUpdateRequest = $pendingUpdateMeta['request_record'] ?? null;
        $pendingActionType = $this->resolveUpdateRequestActionTypeFromPayload($pendingUpdateMeta['payload'] ?? null);

        $previousStatus = strtoupper(trim((string) ($pendingUpdateMeta['previous_status'] ?? '')));
        if ($previousStatus !== LeaveApplication::STATUS_APPROVED) {
            $previousStatus = LeaveApplication::STATUS_APPROVED;
        }

        DB::transaction(function () use (
            $app,
            $request,
            $admin,
            $previousStatus,
            $pendingUpdateRequest,
            $pendingActionType
        ): void {
            $app->update([
                'status' => $previousStatus,
                'admin_id' => $admin->id,
                'remarks' => $request->input('remarks'),
            ]);

            if ($pendingUpdateRequest instanceof LeaveApplicationUpdateRequest) {
                $pendingUpdateRequest->update([
                    'status' => LeaveApplicationUpdateRequest::STATUS_REJECTED,
                    'reviewed_at' => now(),
                    'review_remarks' => $request->input('remarks'),
                ]);
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_REJECTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => $request->input('remarks') ?: (
                    $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                        ? 'Rejected leave cancellation request.'
                        : 'Rejected leave update request.'
                ),
                'created_at' => now(),
            ]);
        });

        $app = $app->fresh(['leaveType', 'applicantAdmin']);

        return response()->json([
            'message' => $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                ? 'Leave cancellation request rejected by admin. Original approved application remains unchanged.'
                : 'Leave update request rejected by admin. Original approved application remains unchanged.',
            'application' => $this->formatApplication($app),
        ]);
    }

    private function getPendingApprovedUpdateRequestRecord(LeaveApplication $app): ?LeaveApplicationUpdateRequest
    {
        if (!$app->id) {
            return null;
        }

        if ($app->relationLoaded('updateRequests')) {
            $record = $app->updateRequests
                ->filter(fn($item) => $item instanceof LeaveApplicationUpdateRequest)
                ->sortByDesc(fn(LeaveApplicationUpdateRequest $item) => (int) $item->id)
                ->first(function (LeaveApplicationUpdateRequest $item): bool {
                    return strtoupper(trim((string) $item->status)) === LeaveApplicationUpdateRequest::STATUS_PENDING
                        && strtoupper(trim((string) ($item->previous_status ?? ''))) === LeaveApplication::STATUS_APPROVED;
                });

            return $record instanceof LeaveApplicationUpdateRequest ? $record : null;
        }

        $record = LeaveApplicationUpdateRequest::query()
            ->where('leave_application_id', (int) $app->id)
            ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
            ->latest('id')
            ->first();

        if (!$record) {
            return null;
        }

        $previousStatus = strtoupper(trim((string) ($record->previous_status ?? '')));
        return $previousStatus === LeaveApplication::STATUS_APPROVED ? $record : null;
    }

    private function resolvePendingApprovedUpdateCycleRequestedAt(LeaveApplication $app): ?\DateTimeInterface
    {
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);
        $requestedAt = $pendingUpdateMeta['requested_at'] ?? null;

        return $requestedAt instanceof \DateTimeInterface ? $requestedAt : null;
    }

    private function resolveLatestApprovedUpdateCycleRequestedAt(LeaveApplication $app): ?\DateTimeInterface
    {
        $latestUpdateMeta = $this->resolveLatestUpdateMeta($app);
        $latestStatus = strtoupper(trim((string) ($latestUpdateMeta['status'] ?? '')));
        $previousStatus = strtoupper(trim((string) ($latestUpdateMeta['previous_status'] ?? '')));
        $requestedAt = $latestUpdateMeta['requested_at'] ?? null;

        if (
            $latestStatus !== LeaveApplicationUpdateRequest::STATUS_APPROVED
            || $previousStatus !== LeaveApplication::STATUS_APPROVED
            || !$requestedAt instanceof \DateTimeInterface
        ) {
            return null;
        }

        return $requestedAt;
    }

    private function resolveLatestHrActionLogForCycle(
        LeaveApplication $app,
        string $action,
        ?\DateTimeInterface $cycleRequestedAt = null
    ): ?LeaveApplicationLog {
        $logs = $app->relationLoaded('logs')
            ? $app->logs
            : $app->logs()->get();

        if (!$logs instanceof Collection || $logs->isEmpty()) {
            return null;
        }

        $cycleRequestedTimestamp = $cycleRequestedAt?->getTimestamp();
        $log = $logs
            ->filter(function (mixed $entry) use ($action, $cycleRequestedTimestamp): bool {
                if (!$entry instanceof LeaveApplicationLog) {
                    return false;
                }

                if (
                    $entry->action !== $action
                    || strtoupper((string) $entry->performed_by_type) !== LeaveApplicationLog::PERFORMER_HR
                ) {
                    return false;
                }

                if ($cycleRequestedTimestamp === null) {
                    return true;
                }

                $entryTimestamp = $entry->created_at?->getTimestamp();
                if ($entryTimestamp === null) {
                    return false;
                }

                return $entryTimestamp >= $cycleRequestedTimestamp;
            })
            ->sortByDesc(fn(LeaveApplicationLog $entry) => $entry->created_at?->getTimestamp() ?? PHP_INT_MIN)
            ->first();

        return $log instanceof LeaveApplicationLog ? $log : null;
    }

    private function resolvePendingUpdateMeta(LeaveApplication $app): array
    {
        $pendingRequest = $this->getPendingApprovedUpdateRequestRecord($app);
        if ($pendingRequest instanceof LeaveApplicationUpdateRequest) {
            return [
                'payload' => $this->normalizePendingUpdatePayload($pendingRequest->requested_payload),
                'action_type' => $this->resolveUpdateRequestActionTypeFromPayload($pendingRequest->requested_payload),
                'reason' => $this->trimNullableString($pendingRequest->requested_reason),
                'previous_status' => strtoupper(trim((string) ($pendingRequest->previous_status ?? ''))),
                'requested_by' => $this->trimNullableString($pendingRequest->requested_by_control_no),
                'requested_at' => $pendingRequest->requested_at,
                'request_record' => $pendingRequest,
            ];
        }

        return [
            'payload' => null,
            'action_type' => null,
            'reason' => null,
            'previous_status' => null,
            'requested_by' => null,
            'requested_at' => null,
            'request_record' => null,
        ];
    }

    private function getLatestApprovedUpdateRequestRecord(LeaveApplication $app): ?LeaveApplicationUpdateRequest
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

        $previousStatus = strtoupper(trim((string) ($record->previous_status ?? '')));
        return $previousStatus === LeaveApplication::STATUS_APPROVED ? $record : null;
    }

    private function resolveLatestUpdateMeta(LeaveApplication $app): array
    {
        $latestRequest = $this->getLatestApprovedUpdateRequestRecord($app);
        if ($latestRequest instanceof LeaveApplicationUpdateRequest) {
            return [
                'status' => strtoupper(trim((string) ($latestRequest->status ?? ''))),
                'payload' => $this->normalizePendingUpdatePayload($latestRequest->requested_payload),
                'action_type' => $this->resolveUpdateRequestActionTypeFromPayload($latestRequest->requested_payload),
                'reason' => $this->trimNullableString($latestRequest->requested_reason),
                'previous_status' => strtoupper(trim((string) ($latestRequest->previous_status ?? ''))),
                'requested_by' => $this->trimNullableString($latestRequest->requested_by_control_no),
                'requested_at' => $latestRequest->requested_at,
                'reviewed_at' => $latestRequest->reviewed_at,
                'review_remarks' => $this->trimNullableString($latestRequest->review_remarks),
            ];
        }

        return [
            'status' => null,
            'payload' => null,
            'action_type' => null,
            'reason' => null,
            'previous_status' => null,
            'requested_by' => null,
            'requested_at' => null,
            'reviewed_at' => null,
            'review_remarks' => null,
        ];
    }

    private function normalizePendingUpdatePayload(mixed $payload): ?array
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
        $actionType = $this->resolveUpdateRequestActionTypeFromPayload($payload);

        if ($actionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL) {
            $isMonetization = (bool) ($payload['is_monetization'] ?? false);
            $payMode = $this->normalizePayMode($payload['pay_mode'] ?? null, $isMonetization);
            $selectedDatesInput = is_array($payload['selected_dates'] ?? null)
                ? LeaveApplication::resolveDateSet(null, null, $payload['selected_dates'], null)
                : null;
            $startDate = $isMonetization ? null : $this->trimNullableString($payload['start_date'] ?? null);
            $endDate = $isMonetization ? null : $this->trimNullableString($payload['end_date'] ?? null);
            $totalDays = round((float) ($payload['total_days'] ?? 0), 2);
            $selectedDates = $isMonetization
                ? null
                : LeaveApplication::resolveSelectedDates(
                    $startDate,
                    $endDate,
                    is_array($selectedDatesInput) ? $selectedDatesInput : null,
                    $totalDays > 0 ? $totalDays : null
                );
            if (!$isMonetization && $totalDays <= 0 && is_array($selectedDates) && $selectedDates !== []) {
                $totalDays = (float) count($selectedDates);
            }
            $leaveTypeId = $this->resolveCanonicalLeaveTypeId((int) ($payload['leave_type_id'] ?? 0))
                ?? (int) ($payload['leave_type_id'] ?? 0);
            $leaveTypeName = $this->trimNullableString($payload['leave_type_name'] ?? null);
            $cancelReason = $this->trimNullableString(
                $payload['cancel_reason']
                ?? $payload['cancellation_reason']
                ?? $payload['reason']
                ?? $payload['reason_purpose']
                ?? $payload['remarks']
                ?? null
            );
            $withoutPay = $payMode === LeaveApplication::PAY_MODE_WITHOUT_PAY;

            return [
                'action_type' => LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL,
                'request_kind' => 'cancel',
                'cancel_leave' => true,
                'leave_type_id' => $leaveTypeId > 0 ? $leaveTypeId : null,
                'leave_type_name' => $leaveTypeName,
                'start_date' => $startDate,
                'end_date' => $endDate,
                'selected_dates' => $selectedDates,
                'selected_date_half_day_portion' => $this->mergeSelectedDateHalfDayPortionMaps(
                    $this->normalizeSelectedDateHalfDayPortionMap($payload['selected_date_half_day_portion'] ?? null),
                    $this->normalizeSelectedDateHalfDayPortionMap($payload['selected_date_coverage'] ?? null)
                ),
                'total_days' => $totalDays,
                'deductible_days' => round((float) ($payload['deductible_days'] ?? $totalDays), 2),
                'reason' => $cancelReason,
                'details_of_leave' => $this->trimNullableString($payload['details_of_leave'] ?? $payload['detailsOfLeave'] ?? null),
                'cancel_reason' => $cancelReason,
                'pay_mode' => $payMode,
                'pay_status' => $withoutPay ? 'Without Pay' : 'With Pay',
                'without_pay' => $withoutPay,
                'with_pay' => !$withoutPay,
                'is_monetization' => $isMonetization,
            ];
        }

        $isMonetization = (bool) ($payload['is_monetization'] ?? false);
        $payMode = $this->normalizePayMode($payload['pay_mode'] ?? null, $isMonetization);
        $selectedDatesInput = is_array($payload['selected_dates'] ?? null)
            ? LeaveApplication::resolveDateSet(null, null, $payload['selected_dates'], null)
            : null;
        $selectedDatePayStatus = $isMonetization
            ? null
            : $this->normalizeSelectedDatePayStatusMap($payload['selected_date_pay_status'] ?? null);
        $selectedDateCoverage = $isMonetization
            ? null
            : $this->normalizeSelectedDateCoverageMap($payload['selected_date_coverage'] ?? null);
        $selectedDateHalfDayPortion = $isMonetization
            ? null
            : $this->mergeSelectedDateHalfDayPortionMaps(
                $this->normalizeSelectedDateHalfDayPortionMap($payload['selected_date_half_day_portion'] ?? null),
                $this->normalizeSelectedDateHalfDayPortionMap($payload['selected_date_coverage'] ?? null)
            );

        $startDate = $isMonetization ? null : $this->trimNullableString($payload['start_date'] ?? null);
        $endDate = $isMonetization ? null : $this->trimNullableString($payload['end_date'] ?? null);

        $commutation = $isMonetization
            ? LeaveApplication::MONETIZATION_REQUIRED_COMMUTATION
            : ($this->trimNullableString($payload['commutation'] ?? null) ?? 'Not Requested');
        $leaveTypeId = $this->resolveCanonicalLeaveTypeId((int) ($payload['leave_type_id'] ?? 0))
            ?? (int) ($payload['leave_type_id'] ?? 0);
        $totalDays = round((float) ($payload['total_days'] ?? 0), 2);
        $selectedDates = $isMonetization
            ? null
            : LeaveApplication::resolveSelectedDates(
                $startDate,
                $endDate,
                is_array($selectedDatesInput) ? $selectedDatesInput : null,
                $totalDays
            );

        if (!$isMonetization && is_array($selectedDates) && $selectedDates !== []) {
            $startDate ??= $selectedDates[0];
            $endDate ??= $selectedDates[count($selectedDates) - 1];
        }

        $selectedDatePayStatus = $isMonetization
            ? null
            : $this->compactSelectedDatePayStatusMap(
                $selectedDatePayStatus,
                $selectedDates,
                $payMode
            );
        $selectedDateCoverageMetadata = $isMonetization
            ? [
                'selectedDateCoverage' => null,
                'selectedDateHalfDayPortion' => null,
            ]
            : $this->synchronizeSelectedDateCoverageMetadata(
                $selectedDateCoverage,
                $selectedDateHalfDayPortion,
                $selectedDates
            );
        $selectedDateCoverage = $selectedDateCoverageMetadata['selectedDateCoverage'];
        $selectedDateHalfDayPortion = $selectedDateCoverageMetadata['selectedDateHalfDayPortion'];
        $attachmentState = $this->resolveAttachmentStateFromPayload(
            is_array($payload) ? $payload : [],
            (bool) ($payload['attachment_submitted'] ?? false),
            $this->trimNullableString($payload['attachment_reference'] ?? null)
        );
        $attachmentRequired = (bool) ($payload['attachment_required'] ?? false);
        $attachmentSubmitted = (bool) ($attachmentState['attachment_submitted'] ?? false);
        $attachmentReference = $attachmentState['attachment_reference'] ?? null;

        static $leaveTypeNameCache = [];
        $leaveTypeName = null;
        $leaveType = null;
        if ($leaveTypeId > 0) {
            if (!array_key_exists($leaveTypeId, $leaveTypeNameCache)) {
                $leaveTypeNameCache[$leaveTypeId] = LeaveType::query()
                    ->whereKey($leaveTypeId)
                    ->value('name');
            }

            $resolvedLeaveTypeName = $leaveTypeNameCache[$leaveTypeId];
            $leaveTypeName = is_string($resolvedLeaveTypeName) ? trim($resolvedLeaveTypeName) : null;
            $leaveType = LeaveType::find($leaveTypeId);
        }

        if ($leaveType instanceof LeaveType) {
            $policyResolution = $this->applyRegularLeavePolicy(
                $leaveType,
                $totalDays,
                is_array($selectedDates) ? $selectedDates : null,
                $selectedDateCoverage,
                $selectedDatePayStatus,
                $payMode,
                $isMonetization,
                $attachmentSubmitted,
                $attachmentReference,
                false,
                $payload['date_filed'] ?? null,
                $startDate,
                $endDate,
                isset($payload['employee_control_no']) ? (string) $payload['employee_control_no'] : null
            );

            if (!($policyResolution instanceof JsonResponse)) {
                $payMode = $policyResolution['pay_mode'];
                $selectedDatePayStatus = $policyResolution['selected_date_pay_status'];
                $deductibleDays = (float) ($policyResolution['deductible_days'] ?? 0);
                $ctoDeductedHours = (float) ($policyResolution['cto_deducted_hours'] ?? 0);
                $attachmentRequired = (bool) ($policyResolution['attachment_required'] ?? false);
                $attachmentSubmitted = (bool) ($policyResolution['attachment_submitted'] ?? false);
                $attachmentReference = $policyResolution['attachment_reference'] ?? null;
            } else {
                $deductibleDays = $this->computeDeductibleDays(
                    $totalDays,
                    is_array($selectedDates) ? $selectedDates : null,
                    $selectedDatePayStatus,
                    $selectedDateCoverage,
                    $isMonetization,
                    $payMode,
                    isset($payload['employee_control_no']) ? (string) $payload['employee_control_no'] : null
                );
                $ctoDeductedHours = $leaveType && $this->isCtoLeaveType($leaveType, $leaveTypeId)
                    ? $this->resolveCtoDeductedHours($deductibleDays)
                    : 0.0;
            }
        } else {
            $deductibleDays = $this->computeDeductibleDays(
                $totalDays,
                is_array($selectedDates) ? $selectedDates : null,
                $selectedDatePayStatus,
                $selectedDateCoverage,
                $isMonetization,
                $payMode,
                isset($payload['employee_control_no']) ? (string) $payload['employee_control_no'] : null
            );
            $ctoDeductedHours = 0.0;
        }

        $withoutPay = $payMode === LeaveApplication::PAY_MODE_WITHOUT_PAY;

        return [
            'leave_type_id' => $leaveTypeId,
            'leave_type_name' => $leaveTypeName,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'selected_dates' => $selectedDates,
            'selected_date_pay_status' => $selectedDatePayStatus,
            'selected_date_coverage' => $selectedDateCoverage,
            'selected_date_half_day_portion' => $selectedDateHalfDayPortion,
            'total_days' => $totalDays,
            'deductible_days' => $deductibleDays,
            'cto_deducted_hours' => round(max((float) ($ctoDeductedHours ?? 0.0), 0.0), 2),
            'reason' => $this->trimNullableString($payload['reason'] ?? null),
            'details_of_leave' => $this->trimNullableString($payload['details_of_leave'] ?? $payload['detailsOfLeave'] ?? null),
            'commutation' => $commutation,
            'pay_mode' => $payMode,
            'pay_status' => $withoutPay ? 'Without Pay' : 'With Pay',
            'without_pay' => $withoutPay,
            'with_pay' => !$withoutPay,
            'action_type' => $actionType,
            'is_monetization' => $isMonetization,
            'attachment_required' => $attachmentRequired,
            'attachment_submitted' => $attachmentSubmitted,
            'attachment_reference' => $this->trimNullableString($attachmentReference),
        ];
    }

    private function refundApplicationTrackedDeductions(
        LeaveApplication $app,
        ?LeaveType $sourceLeaveType,
        float $sourceDeductibleDays,
        string $balanceConflictError
    ): void {
        $employeeControlNo = $this->resolveApplicationBalanceOwnerControlNo($app);
        if ($employeeControlNo === null) {
            throw new \RuntimeException($balanceConflictError);
        }

        $primaryRefundDays = $this->resolveStoredPrimaryLeaveDeduction(
            $app,
            $sourceLeaveType,
            $sourceDeductibleDays
        );
        if ((int) $app->leave_type_id > 0 && $primaryRefundDays > 0.0) {
            $this->incrementEmployeeLeaveBalance($employeeControlNo, (int) $app->leave_type_id, $primaryRefundDays);
        }

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        $linkedForcedRefundDays = $this->resolveStoredLinkedForcedLeaveDeduction($app, $sourceLeaveType, $sourceDeductibleDays);
        if ($forcedLeaveTypeId !== null && $linkedForcedRefundDays > 0.0) {
            $this->incrementEmployeeLeaveBalance($employeeControlNo, $forcedLeaveTypeId, $linkedForcedRefundDays);
        }

        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        $linkedVacationRefundDays = $this->resolveStoredLinkedVacationLeaveDeduction($app, $sourceLeaveType, $sourceDeductibleDays);
        if ($vacationLeaveTypeId !== null && $linkedVacationRefundDays > 0.0) {
            $this->incrementEmployeeLeaveBalance($employeeControlNo, $vacationLeaveTypeId, $linkedVacationRefundDays);
        }
    }

    private function deductApplicationTrackedBalances(
        LeaveApplication $app,
        int $primaryLeaveTypeId,
        float $daysToDeduct,
        ?float $requestedTotalDays,
        bool $isCtoDeduction,
        bool $shouldDeductForcedLeave,
        ?int $forcedLeaveTypeId,
        bool $shouldDeductVacationLeave,
        ?int $vacationLeaveTypeId,
        string $balanceConflictError
    ): array {
        $employeeControlNo = $this->resolveApplicationBalanceOwnerControlNo($app);
        if ($employeeControlNo === null) {
            throw new \RuntimeException($balanceConflictError);
        }

        $primaryLeaveTypeId = $this->resolveCanonicalLeaveTypeId($primaryLeaveTypeId) ?? $primaryLeaveTypeId;
        $normalizedDaysToDeduct = round(max($daysToDeduct, 0.0), 2);
        if ($primaryLeaveTypeId <= 0 || $normalizedDaysToDeduct <= 0.0) {
            return [
                'linked_forced_leave_deducted_days' => 0.0,
                'linked_vacation_leave_deducted_days' => 0.0,
            ];
        }

        $resolvedRequestedTotalDays = round(
            max((float) ($requestedTotalDays ?? $app->total_days ?? $normalizedDaysToDeduct), 0.0),
            2
        );
        $primaryLeaveType = $app->leaveType;
        if (
            !$primaryLeaveType instanceof LeaveType
            || (int) ($this->resolveCanonicalLeaveTypeId((int) $primaryLeaveType->id) ?? (int) $primaryLeaveType->id) !== $primaryLeaveTypeId
        ) {
            $primaryLeaveType = LeaveType::query()->find($primaryLeaveTypeId);
        }

        $primaryDaysToDeduct = $this->resolvePrimaryLeaveTrackedDeductionDays(
            $primaryLeaveType,
            $primaryLeaveTypeId,
            (bool) $app->is_monetization,
            $resolvedRequestedTotalDays,
            $normalizedDaysToDeduct,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );
        $linkedVacationLeaveRequiredDays = $shouldDeductVacationLeave && $primaryLeaveType instanceof LeaveType
            ? $this->resolveLeaveTypeVacationLeaveDeductionDays(
                $primaryLeaveType,
                $primaryLeaveTypeId,
                (bool) $app->is_monetization,
                $resolvedRequestedTotalDays,
                $normalizedDaysToDeduct,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId
            )
            : 0.0;

        if ($isCtoDeduction) {
            $this->syncEmployeeCtoBalance($employeeControlNo, true);
        }

        $primaryBalance = $this->lockEmployeeLeaveBalance($employeeControlNo, $primaryLeaveTypeId);
        if (!$primaryBalance || (float) $primaryBalance->balance < $primaryDaysToDeduct) {
            throw new \RuntimeException($balanceConflictError);
        }

        if ($isCtoDeduction) {
            return [
                'linked_forced_leave_deducted_days' => 0.0,
                'linked_vacation_leave_deducted_days' => 0.0,
            ];
        }

        $primaryBalance->decrement('balance', $primaryDaysToDeduct);

        $linkedForcedLeaveDeductedDays = 0.0;
        if ($shouldDeductForcedLeave && $forcedLeaveTypeId !== null) {
            $forcedBalance = $this->lockEmployeeLeaveBalance($employeeControlNo, $forcedLeaveTypeId);
            $forcedAvailable = $forcedBalance ? (float) $forcedBalance->balance : 0.0;
            $linkedForcedLeaveDeductedDays = round(min($primaryDaysToDeduct, max($forcedAvailable, 0.0)), 2);

            if ($forcedBalance && $linkedForcedLeaveDeductedDays > 0.0) {
                $forcedBalance->decrement('balance', $linkedForcedLeaveDeductedDays);
            }
        }

        $linkedVacationLeaveDeductedDays = 0.0;
        if ($shouldDeductVacationLeave && $vacationLeaveTypeId !== null && $linkedVacationLeaveRequiredDays > 0.0) {
            $vacationBalance = $this->lockEmployeeLeaveBalance($employeeControlNo, $vacationLeaveTypeId);
            if (!$vacationBalance || (float) $vacationBalance->balance < $linkedVacationLeaveRequiredDays) {
                throw new \RuntimeException($balanceConflictError);
            }

            $vacationBalance->decrement('balance', $linkedVacationLeaveRequiredDays);
            $linkedVacationLeaveDeductedDays = $linkedVacationLeaveRequiredDays;
        }

        return [
            'linked_forced_leave_deducted_days' => $linkedForcedLeaveDeductedDays,
            'linked_vacation_leave_deducted_days' => $linkedVacationLeaveDeductedDays,
        ];
    }

    private function resolveApplicationBalanceOwnerControlNo(LeaveApplication $app): ?string
    {
        $directControlNo = trim((string) ($app->employee_control_no ?? ''));
        if ($directControlNo !== '') {
            return $directControlNo;
        }

        if ($app->applicant_admin_id) {
            return $this->findAdminEmployeeControlNo((int) $app->applicant_admin_id);
        }

        return null;
    }

    private function lockEmployeeLeaveBalance(string $employeeControlNo, int $leaveTypeId): ?LeaveBalance
    {
        return $this->findPreferredEmployeeLeaveBalanceRecord($employeeControlNo, $leaveTypeId, true);
    }

    private function incrementEmployeeLeaveBalance(string $employeeControlNo, int $leaveTypeId, float $daysToAdd): void
    {
        $leaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        $normalizedDaysToAdd = round(max($daysToAdd, 0.0), 2);
        if ($leaveTypeId <= 0 || $normalizedDaysToAdd <= 0.0) {
            return;
        }

        $balance = $this->lockEmployeeLeaveBalance($employeeControlNo, $leaveTypeId);
        if (!$balance) {
            $employee = $this->findEmployeeByControlNo($employeeControlNo);
            $leaveType = LeaveType::query()->find($leaveTypeId);

            $balance = LeaveBalance::query()->create([
                'employee_control_no' => trim((string) ($employee?->control_no ?? $employeeControlNo)),
                'employee_name' => $employee ? $this->formatEmployeeNameForBalance($employee) : null,
                'leave_type_id' => $leaveTypeId,
                'leave_type_name' => $leaveType?->name,
                'balance' => 0,
                'year' => (int) now()->year,
            ]);
        }

        $balance->increment('balance', $normalizedDaysToAdd);
    }

    private function formatEmployeeNameForBalance(?object $employee): ?string
    {
        if (!$employee) {
            return null;
        }

        $surname = trim((string) ($employee->surname ?? ''));
        $firstname = trim((string) ($employee->firstname ?? ''));
        $middlename = trim((string) ($employee->middlename ?? ''));
        $fullName = trim(implode(', ', array_filter([$surname, trim($firstname . ' ' . $middlename)])));

        return $fullName !== '' ? $fullName : null;
    }

    private function resolveStoredPrimaryLeaveDeduction(
        LeaveApplication $app,
        ?LeaveType $sourceLeaveType,
        float $fallbackDeductibleDays
    ): float {
        $normalizedFallbackDeductibleDays = round(max($fallbackDeductibleDays, 0.0), 2);
        if (!$sourceLeaveType instanceof LeaveType) {
            return $normalizedFallbackDeductibleDays;
        }

        $leaveTypeId = $this->resolveCanonicalLeaveTypeId((int) $app->leave_type_id)
            ?? (int) $app->leave_type_id;
        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        if (!$this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
            $sourceLeaveType,
            $leaveTypeId,
            (bool) $app->is_monetization,
            $vacationLeaveTypeId
        )) {
            return $normalizedFallbackDeductibleDays;
        }

        $linkedVacationRefundDays = $this->resolveStoredLinkedVacationLeaveDeduction(
            $app,
            $sourceLeaveType,
            $fallbackDeductibleDays
        );

        return round(max($normalizedFallbackDeductibleDays - $linkedVacationRefundDays, 0.0), 2);
    }

    private function resolveStoredLinkedForcedLeaveDeduction(
        LeaveApplication $app,
        ?LeaveType $sourceLeaveType,
        float $fallbackDeductibleDays
    ): float {
        if ($app->linked_forced_leave_deducted_days !== null) {
            return round(max((float) $app->linked_forced_leave_deducted_days, 0.0), 2);
        }

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        if (!$sourceLeaveType || !$this->shouldLeaveTypeDeductForcedLeave(
            $sourceLeaveType,
            (int) $app->leave_type_id,
            (bool) $app->is_monetization,
            $forcedLeaveTypeId
        )) {
            return 0.0;
        }

        return round(max($fallbackDeductibleDays, 0.0), 2);
    }

    private function resolveStoredLinkedVacationLeaveDeduction(
        LeaveApplication $app,
        ?LeaveType $sourceLeaveType,
        float $fallbackDeductibleDays
    ): float {
        if ($app->linked_vacation_leave_deducted_days !== null) {
            return round(max((float) $app->linked_vacation_leave_deducted_days, 0.0), 2);
        }

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        if (!$sourceLeaveType || !$this->shouldLeaveTypeDeductVacationLeave(
            $sourceLeaveType,
            (int) $app->leave_type_id,
            (bool) $app->is_monetization,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        )) {
            return 0.0;
        }

        return $this->resolveLeaveTypeVacationLeaveDeductionDays(
            $sourceLeaveType,
            (int) $app->leave_type_id,
            (bool) $app->is_monetization,
            round((float) ($app->total_days ?? $fallbackDeductibleDays), 2),
            $fallbackDeductibleDays,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );
    }

    private function resolveCtoLeaveTypeId(): ?int
    {
        static $resolved = false;
        static $cachedValue = null;

        if ($resolved) {
            return $cachedValue;
        }

        $value = LeaveType::query()
            ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['cto leave'])
            ->value('id');

        $cachedValue = $value !== null ? (int) $value : null;
        $resolved = true;

        return $cachedValue;
    }

    private function isCtoLeaveType(?LeaveType $leaveType = null, ?int $leaveTypeId = null): bool
    {
        $ctoLeaveTypeId = $this->resolveCtoLeaveTypeId();
        if ($ctoLeaveTypeId === null) {
            return false;
        }

        if ($leaveType instanceof LeaveType && (int) $leaveType->id === $ctoLeaveTypeId) {
            return true;
        }

        if ($leaveTypeId !== null && (int) $leaveTypeId === $ctoLeaveTypeId) {
            return true;
        }

        return false;
    }

    private function syncEmployeeCtoBalance(
        string $employeeControlNo,
        bool $lockForUpdate = false,
        ?\Carbon\CarbonImmutable $asOfDate = null
    ): ?float {
        $ctoLeaveTypeId = $this->resolveCtoLeaveTypeId();
        if ($ctoLeaveTypeId === null) {
            return null;
        }

        $controlNoCandidates = $this->controlNoCandidates($employeeControlNo);
        if ($controlNoCandidates === []) {
            return null;
        }

        $effectiveBalance = round(max(
            $this->cocLedgerService()->getAvailableDays($employeeControlNo, $ctoLeaveTypeId, $asOfDate),
            0.0
        ), 2);

        $query = LeaveBalance::query()
            ->whereIn('employee_control_no', $controlNoCandidates)
            ->where('leave_type_id', $ctoLeaveTypeId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $balance = $query->first();

        if (!$balance && $effectiveBalance <= 0) {
            return 0.0;
        }

        if (!$balance) {
            $canonicalControlNo = trim((string) ($this->findEmployeeByControlNo($employeeControlNo)?->control_no ?? $employeeControlNo));
            if ($canonicalControlNo === '') {
                return $effectiveBalance;
            }

            $balance = LeaveBalance::query()->create([
                'employee_control_no' => $canonicalControlNo,
                'leave_type_id' => $ctoLeaveTypeId,
                'balance' => $effectiveBalance,
                'year' => (int) now()->year,
            ]);

            return (float) $balance->balance;
        }

        if (round((float) $balance->balance, 2) !== $effectiveBalance) {
            $balance->balance = $effectiveBalance;
            if (!$balance->year) {
                $balance->year = (int) now()->year;
            }
            $balance->save();
        }

        return $effectiveBalance;
    }

    private function computeEmployeeCtoAvailableBalance(
        string $employeeControlNo,
        int $ctoLeaveTypeId,
        ?\Carbon\CarbonImmutable $asOfDate = null
    ): float {
        if ($ctoLeaveTypeId <= 0) {
            return 0.0;
        }

        return round(max(
            $this->cocLedgerService()->getAvailableDays($employeeControlNo, $ctoLeaveTypeId, $asOfDate),
            0.0
        ), 2);
    }

    private function resolveCocExpiryDate(\Carbon\CarbonImmutable $creditedOn): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::create($creditedOn->year + 1, 12, 31)->endOfDay();
    }

    private function resolveForcedLeaveTypeId(): ?int
    {
        $value = LeaveType::query()
            ->whereRaw('LOWER(name) = ?', ['mandatory / forced leave'])
            ->value('id');

        return $value !== null ? (int) $value : null;
    }

    private function resolveVacationLeaveTypeId(): ?int
    {
        $value = LeaveType::query()
            ->whereRaw('LOWER(name) = ?', ['vacation leave'])
            ->value('id');

        return $value !== null ? (int) $value : null;
    }

    private function shouldDeductForcedLeaveWithVacation(LeaveApplication $app, ?int $forcedLeaveTypeId): bool
    {
        $leaveType = $app->leaveType;
        if (!$leaveType instanceof LeaveType) {
            $leaveType = LeaveType::query()->find((int) $app->leave_type_id);
        }

        return $leaveType instanceof LeaveType
            && $this->shouldLeaveTypeDeductForcedLeave(
                $leaveType,
                (int) $app->leave_type_id,
                (bool) $app->is_monetization,
                $forcedLeaveTypeId
            );
    }

    private function shouldDeductVacationLeaveWithForced(
        LeaveApplication $app,
        ?int $forcedLeaveTypeId,
        ?int $vacationLeaveTypeId
    ): bool {
        $leaveType = $app->leaveType;
        if (!$leaveType instanceof LeaveType) {
            $leaveType = LeaveType::query()->find((int) $app->leave_type_id);
        }

        return $leaveType instanceof LeaveType
            && $this->shouldLeaveTypeDeductVacationLeave(
                $leaveType,
                (int) $app->leave_type_id,
                (bool) $app->is_monetization,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId
            );
    }

    private function shouldLeaveTypeDeductForcedLeave(
        LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        ?int $forcedLeaveTypeId
    ): bool {
        if ($forcedLeaveTypeId === null || $isMonetization) {
            return false;
        }

        if ($leaveTypeId === $forcedLeaveTypeId) {
            return false;
        }

        return strcasecmp(trim((string) ($leaveType->name ?? '')), 'Vacation Leave') === 0;
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

        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;

        return $this->isWellnessLeaveType($leaveType, $canonicalLeaveTypeId)
            || LeaveType::isSpecialPrivilegeType($leaveType, $canonicalLeaveTypeId);
    }

    private function resolveLeaveTypeVacationLeaveDeductionDays(
        LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        float $requestedTotalDays,
        float $deductibleDays,
        ?int $forcedLeaveTypeId,
        ?int $vacationLeaveTypeId
    ): float {
        $normalizedDeductibleDays = round(max($deductibleDays, 0.0), 2);
        if ($normalizedDeductibleDays <= 0.0) {
            return 0.0;
        }

        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        if (!$this->shouldLeaveTypeDeductVacationLeave(
            $leaveType,
            $canonicalLeaveTypeId,
            $isMonetization,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        )) {
            return 0.0;
        }

        if ($forcedLeaveTypeId !== null && $canonicalLeaveTypeId === $forcedLeaveTypeId) {
            return $normalizedDeductibleDays;
        }

        if (!$this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
            $leaveType,
            $canonicalLeaveTypeId,
            $isMonetization,
            $vacationLeaveTypeId
        )) {
            return 0.0;
        }

        $normalizedRequestedTotalDays = round(max($requestedTotalDays, 0.0), 2);

        return round(max($normalizedDeductibleDays - $normalizedRequestedTotalDays, 0.0), 2);
    }

    private function resolvePrimaryLeaveTrackedDeductionDays(
        ?LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        float $requestedTotalDays,
        float $deductibleDays,
        ?int $forcedLeaveTypeId,
        ?int $vacationLeaveTypeId
    ): float {
        $normalizedDeductibleDays = round(max($deductibleDays, 0.0), 2);
        if (!$leaveType instanceof LeaveType) {
            return $normalizedDeductibleDays;
        }

        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        if (!$this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
            $leaveType,
            $canonicalLeaveTypeId,
            $isMonetization,
            $vacationLeaveTypeId
        )) {
            return $normalizedDeductibleDays;
        }

        $linkedVacationLeaveDays = $this->resolveLeaveTypeVacationLeaveDeductionDays(
            $leaveType,
            $canonicalLeaveTypeId,
            $isMonetization,
            $requestedTotalDays,
            $normalizedDeductibleDays,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );

        return round(max($normalizedDeductibleDays - $linkedVacationLeaveDays, 0.0), 2);
    }

    private function shouldLeaveTypeDeductVacationLeave(
        LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        ?int $forcedLeaveTypeId,
        ?int $vacationLeaveTypeId
    ): bool {
        if ($vacationLeaveTypeId === null || $isMonetization) {
            return false;
        }

        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        if ($forcedLeaveTypeId !== null && $canonicalLeaveTypeId === $forcedLeaveTypeId) {
            return true;
        }

        return $this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
            $leaveType,
            $canonicalLeaveTypeId,
            $isMonetization,
            $vacationLeaveTypeId
        );
    }

    private function isSickLeaveType(?LeaveType $leaveType = null, ?int $leaveTypeId = null): bool
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

        return strcasecmp((string) ($leaveTypeName ?? ''), 'Sick Leave') === 0;
    }

    private function isVacationLeaveType(?LeaveType $leaveType = null, ?int $leaveTypeId = null): bool
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

        return strcasecmp((string) ($leaveTypeName ?? ''), 'Vacation Leave') === 0;
    }

    private function validateMonetizationPolicyRules(?LeaveType $leaveType, float $requestedDays): ?JsonResponse
    {
        $isEligibleMonetizationType = $leaveType instanceof LeaveType
            && (
                $this->isVacationLeaveType($leaveType, (int) $leaveType->id)
                || $this->isSickLeaveType($leaveType, (int) $leaveType->id)
            );

        if (!$isEligibleMonetizationType) {
            return response()->json([
                'message' => 'Monetization is only allowed for Vacation Leave or Sick Leave.',
                'errors' => [
                    'leave_type_id' => ['Monetization is only allowed for Vacation Leave or Sick Leave.'],
                ],
            ], 422);
        }

        $normalizedRequestedDays = round(max($requestedDays, 0.0), 2);
        if ($normalizedRequestedDays + 1e-9 < LeaveApplication::MONETIZATION_MINIMUM_REQUEST_DAYS) {
            $minimumDays = self::formatDays(LeaveApplication::MONETIZATION_MINIMUM_REQUEST_DAYS);

            return response()->json([
                'message' => "A minimum of {$minimumDays} is required for monetization.",
                'errors' => [
                    'total_days' => ["A minimum of {$minimumDays} is required for monetization."],
                ],
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
                    . ' of leave credits are required to apply monetization.',
                'errors' => [
                    'total_days' => [
                        'At least '
                        . self::formatDays($requiredMinimumBalance)
                        . ' of leave credits are required. Current balance: '
                        . self::formatDays($normalizedCurrentBalance)
                        . '.',
                    ],
                ],
            ], 422);
        }

        if ($normalizedRequestedDays > $normalizedCurrentBalance + 1e-9) {
            return response()->json([
                'message' => 'Requested monetization days exceed available leave credits.',
                'errors' => [
                    'total_days' => [
                        'Requested monetization days ('
                        . self::formatDays($normalizedRequestedDays)
                        . ') exceed available leave credits ('
                        . self::formatDays($normalizedCurrentBalance)
                        . ').',
                    ],
                ],
            ], 422);
        }

        return null;
    }

    private function resolveMonetizationApprovalAvailableBalance(
        LeaveApplication $app,
        int $targetLeaveTypeId,
        ?LeaveType $sourceLeaveType = null,
        float $sourceDeductibleDays = 0.0,
        bool $sourceDeductsBalance = false
    ): float {
        $currentBalance = 0.0;
        if ($app->employee_control_no) {
            $currentBalance = (float) (
                $this->findPreferredEmployeeLeaveBalanceRecord(
                    (string) $app->employee_control_no,
                    $targetLeaveTypeId
                )?->balance ?? 0.0
            );
        } elseif ($app->applicant_admin_id) {
            $currentBalance = (float) (
                $this->findAdminEmployeeLeaveBalance(
                    (int) $app->applicant_admin_id,
                    $targetLeaveTypeId
                )?->balance ?? 0.0
            );
        }

        $sourceLeaveTypeId = $this->resolveCanonicalLeaveTypeId((int) ($app->leave_type_id ?? 0))
            ?? (int) ($app->leave_type_id ?? 0);
        $canonicalTargetLeaveTypeId = $this->resolveCanonicalLeaveTypeId($targetLeaveTypeId) ?? $targetLeaveTypeId;

        if (
            $sourceDeductsBalance
            && $sourceLeaveTypeId > 0
            && $sourceLeaveTypeId === $canonicalTargetLeaveTypeId
        ) {
            $currentBalance += $this->resolveStoredPrimaryLeaveDeduction(
                $app,
                $sourceLeaveType,
                $sourceDeductibleDays
            );
        }

        return round(max($currentBalance, 0.0), 2);
    }

    private function resolveAttachmentStateFromRequest(
        Request $request,
        array $validated,
        ?bool $fallbackSubmitted = null,
        ?string $fallbackReference = null
    ): array {
        $submitted = $fallbackSubmitted === true;
        $reference = $this->trimNullableString($fallbackReference);

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

            $flag = $this->normalizeBooleanFlag(
                array_key_exists($key, $validated)
                    ? $validated[$key]
                    : $request->input($key)
            );

            if ($flag !== null) {
                $submitted = $flag;
            }
        }

        $referenceKeys = [
            'attachment_reference',
        ];
        foreach ($referenceKeys as $key) {
            $candidate = $this->trimNullableString(
                array_key_exists($key, $validated)
                    ? $validated[$key]
                    : $request->input($key)
            );

            if ($candidate !== null) {
                $reference = $candidate;
                $submitted = true;
                break;
            }
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

    private function resolveAttachmentStateFromPayload(
        array $payload,
        ?bool $fallbackSubmitted = null,
        ?string $fallbackReference = null
    ): array {
        $submitted = $fallbackSubmitted === true;
        $reference = $this->trimNullableString($fallbackReference);

        $booleanKeys = [
            'attachment_submitted',
            'attachment_attached',
            'has_attachment',
            'with_attachment',
        ];
        foreach ($booleanKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $flag = $this->normalizeBooleanFlag($payload[$key]);
            if ($flag !== null) {
                $submitted = $flag;
            }
        }

        $referenceKeys = [
            'attachment_reference',
        ];
        foreach ($referenceKeys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $candidate = $this->trimNullableString($payload[$key]);
            if ($candidate !== null) {
                $reference = $candidate;
                $submitted = true;
                break;
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
        ?string $employeeControlNo = null
    ): array|JsonResponse {
        $normalizedTotalDays = round(max((float) $totalDays, 0.0), 2);

        if ($isMonetization) {
            return [
                'pay_mode' => LeaveApplication::PAY_MODE_WITH_PAY,
                'selected_date_pay_status' => null,
                'deductible_days' => $normalizedTotalDays,
                'cto_deducted_hours' => null,
                'attachment_required' => false,
                'attachment_submitted' => false,
                'attachment_reference' => null,
            ];
        }

        $normalizedPayMode = $this->normalizePayMode($payMode, false);
        $normalizedSelectedDatePayStatus = is_array($selectedDatePayStatus)
            ? $selectedDatePayStatus
            : null;
        $normalizedSelectedDateCoverage = is_array($selectedDateCoverage)
            ? $selectedDateCoverage
            : null;

        $isSickLeave = $this->isSickLeaveType($leaveType, (int) $leaveType->id);
        $isCtoLeave = $this->isCtoLeaveType($leaveType, (int) $leaveType->id);
        $isEventLeave = strtoupper(trim((string) ($leaveType->category ?? ''))) === LeaveType::CATEGORY_EVENT;
        $attachmentRequired = $isSickLeave
            ? $normalizedTotalDays >= 5.0
            : (bool) ($leaveType->requires_documents ?? false);

        if ($enforceAttachment && $attachmentRequired && !$attachmentSubmitted) {
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

        if ($isCtoLeave) {
            if ($normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY || $this->hasWithoutPaySelectedDates($normalizedSelectedDatePayStatus)) {
                return response()->json([
                    'message' => 'CTO applications must be availed with pay only.',
                    'errors' => [
                        'selected_date_pay_status' => ['CTO applications must be availed with pay only.'],
                    ],
                ], 422);
            }

            $ctoValidation = $this->validateCtoAvailmentPolicy(
                $normalizedTotalDays,
                $selectedDates,
                $normalizedSelectedDateCoverage,
                $filedAt
            );
            if ($ctoValidation instanceof JsonResponse) {
                return $ctoValidation;
            }

            $normalizedPayMode = LeaveApplication::PAY_MODE_WITH_PAY;
            $normalizedSelectedDatePayStatus = null;
            $deductibleDays = $this->computeDeductibleDays(
                $normalizedTotalDays,
                $selectedDates,
                null,
                $normalizedSelectedDateCoverage,
                false,
                $normalizedPayMode,
                $employeeControlNo
            );

            if (!$attachmentRequired) {
                $attachmentSubmitted = false;
                $attachmentReference = null;
            }

            $ctoDeductedHours = $this->resolveCtoDeductedHours($deductibleDays);
        } elseif ($isEventLeave) {
            $normalizedPayMode = LeaveApplication::PAY_MODE_WITH_PAY;
            $normalizedSelectedDatePayStatus = null;

            $deductibleDays = $this->computeDeductibleDays(
                $normalizedTotalDays,
                $selectedDates,
                null,
                $normalizedSelectedDateCoverage,
                false,
                $normalizedPayMode,
                $employeeControlNo
            );

            if (!$attachmentRequired) {
                $attachmentSubmitted = false;
                $attachmentReference = null;
            }

            $ctoDeductedHours = null;
        } elseif ($isSickLeave) {
            $graceWindowPayMode = $this->resolveSickLeavePayModeFromFilingWindow(
                $selectedDates,
                $filedAt,
                $absenceStartDate,
                $absenceEndDate
            );

            if ($graceWindowPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                $normalizedPayMode = LeaveApplication::PAY_MODE_WITHOUT_PAY;
                $normalizedSelectedDatePayStatus = null;
            } else {
                $normalizedSelectedDatePayStatus = $this->compactSelectedDatePayStatusMap(
                    $normalizedSelectedDatePayStatus,
                    $selectedDates,
                    $normalizedPayMode
                );
            }

            $deductibleDays = $this->computeDeductibleDays(
                $normalizedTotalDays,
                $selectedDates,
                $normalizedSelectedDatePayStatus,
                $normalizedSelectedDateCoverage,
                false,
                $normalizedPayMode,
                $employeeControlNo
            );
            $ctoDeductedHours = null;
        } else {
            $normalizedSelectedDatePayStatus = $this->compactSelectedDatePayStatusMap(
                $normalizedSelectedDatePayStatus,
                $selectedDates,
                $normalizedPayMode
            );

            $deductibleDays = $this->computeDeductibleDays(
                $normalizedTotalDays,
                $selectedDates,
                $normalizedSelectedDatePayStatus,
                $normalizedSelectedDateCoverage,
                false,
                $normalizedPayMode,
                $employeeControlNo
            );

            if (!$attachmentRequired) {
                $attachmentSubmitted = false;
                $attachmentReference = null;
            }

            $ctoDeductedHours = null;
        }

        if (!$attachmentSubmitted) {
            $attachmentReference = null;
        }

        return [
            'pay_mode' => $normalizedPayMode,
            'selected_date_pay_status' => $normalizedSelectedDatePayStatus,
            'deductible_days' => round(max((float) $deductibleDays, 0.0), 2),
            'cto_deducted_hours' => $ctoDeductedHours !== null ? round(max((float) $ctoDeductedHours, 0.0), 2) : null,
            'attachment_required' => $attachmentRequired,
            'attachment_submitted' => $attachmentSubmitted,
            'attachment_reference' => $this->trimNullableString($attachmentReference),
        ];
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

    private function hasWithoutPaySelectedDates(?array $selectedDatePayStatus): bool
    {
        if (!is_array($selectedDatePayStatus) || $selectedDatePayStatus === []) {
            return false;
        }

        foreach ($selectedDatePayStatus as $status) {
            if ($this->resolvePayModeFromStatusValue($status) === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                return true;
            }
        }

        return false;
    }

    private function validateCtoAvailmentPolicy(
        float $totalDays,
        ?array $selectedDates,
        ?array $selectedDateCoverage,
        mixed $filedAt = null
    ): ?JsonResponse {
        $normalizedDateKeys = $this->normalizeSelectedDateKeys($selectedDates);
        if ($normalizedDateKeys === []) {
            return response()->json([
                'message' => 'CTO applications require at least one selected availment date.',
                'errors' => [
                    'selected_dates' => ['CTO applications require at least one selected availment date.'],
                ],
            ], 422);
        }

        $coverageTotalDays = $this->computeCoverageDayTotal($normalizedDateKeys, $selectedDateCoverage);
        if (abs($coverageTotalDays - round(max($totalDays, 0.0), 2)) > 0.01) {
            return response()->json([
                'message' => 'CTO applications must use whole-day or half-day blocks only.',
                'errors' => [
                    'total_days' => ['CTO applications must use whole-day or half-day blocks only.'],
                ],
            ], 422);
        }

        $firstAvailmentDate = $this->resolveIsoDate($normalizedDateKeys[0]);
        if ($firstAvailmentDate === null) {
            return response()->json([
                'message' => 'The first CTO availment date is invalid.',
                'errors' => [
                    'selected_dates' => ['The first CTO availment date is invalid.'],
                ],
            ], 422);
        }

        $filedDate = $this->resolvePolicyFilingDate($filedAt);
        $daysBeforeAvailment = $this->countDaysBeforeDate($filedDate, $firstAvailmentDate);
        if ($daysBeforeAvailment < 5) {
            return response()->json([
                'message' => 'CTO applications must be submitted at least 5 days before the first availment date.',
                'errors' => [
                    'start_date' => ['CTO applications must be submitted at least 5 days before the first availment date.'],
                ],
            ], 422);
        }

        $maxConsecutiveDays = $this->calculateMaxConsecutiveDateSpan($normalizedDateKeys);
        if ($maxConsecutiveDays > 5) {
            return response()->json([
                'message' => 'CTO may only be availed for up to 5 consecutive days per application.',
                'errors' => [
                    'selected_dates' => ['CTO may only be availed for up to 5 consecutive days per application.'],
                ],
            ], 422);
        }

        return null;
    }

    private function resolveCtoDeductedHours(float $deductibleDays): float
    {
        return round(max($deductibleDays, 0.0) * self::CTO_STANDARD_DAY_HOURS, 2);
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
                $parsed = \Carbon\CarbonImmutable::parse($dateKey)->startOfDay();
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

    private function resolveIsoDate(?string $rawDate): ?\Carbon\CarbonImmutable
    {
        $raw = trim((string) ($rawDate ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return \Carbon\CarbonImmutable::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolvePolicyFilingDate(mixed $filedAt = null): \Carbon\CarbonImmutable
    {
        if ($filedAt instanceof \DateTimeInterface) {
            return \Carbon\CarbonImmutable::instance($filedAt)->startOfDay();
        }

        $raw = trim((string) ($filedAt ?? ''));
        if ($raw !== '') {
            try {
                return \Carbon\CarbonImmutable::parse($raw)->startOfDay();
            } catch (\Throwable) {
                // Fall back to now() when parsing fails.
            }
        }

        return \Carbon\CarbonImmutable::now()->startOfDay();
    }

    private function countWorkingDaysFromNextDay(
        \Carbon\CarbonImmutable $lastAbsentDate,
        \Carbon\CarbonImmutable $filedDate
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

    private function normalizeSelectedDateKeys(?array $selectedDates): array
    {
        if (!is_array($selectedDates) || $selectedDates === []) {
            return [];
        }

        $normalized = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeDateKey($rawDate);
            if ($dateKey === null) {
                $dateKey = trim((string) $rawDate);
            }
            if ($dateKey === '') {
                continue;
            }

            $normalized[$dateKey] = true;
        }

        $normalizedKeys = array_keys($normalized);
        sort($normalizedKeys);

        return $normalizedKeys;
    }

    private function computeCoverageDayTotal(array $selectedDateKeys, ?array $selectedDateCoverage): float
    {
        if ($selectedDateKeys === []) {
            return 0.0;
        }

        $normalizedCoverage = $this->compactSelectedDateCoverageMap($selectedDateCoverage, $selectedDateKeys);
        $total = 0.0;
        foreach ($selectedDateKeys as $dateKey) {
            $coverage = strtolower(trim((string) ($normalizedCoverage[$dateKey] ?? 'whole')));
            $total += $coverage === 'half' ? 0.5 : 1.0;
        }

        return round($total, 2);
    }

    private function countDaysBeforeDate(
        \Carbon\CarbonImmutable $filedDate,
        \Carbon\CarbonImmutable $targetDate
    ): int {
        $normalizedFiledDate = $filedDate->startOfDay();
        $normalizedTargetDate = $targetDate->startOfDay();
        if ($normalizedTargetDate->lte($normalizedFiledDate)) {
            return 0;
        }

        return $normalizedFiledDate->addDay()->diffInDays($normalizedTargetDate);
    }

    private function nextDate(\Carbon\CarbonImmutable $date): \Carbon\CarbonImmutable
    {
        return $date->addDay()->startOfDay();
    }

    private function calculateMaxConsecutiveDateSpan(array $selectedDateKeys): int
    {
        if ($selectedDateKeys === []) {
            return 0;
        }

        $parsedDates = [];
        foreach ($selectedDateKeys as $dateKey) {
            $parsed = $this->resolveIsoDate($dateKey);
            if ($parsed === null) {
                continue;
            }

            $parsedDates[] = $parsed->startOfDay();
        }

        if ($parsedDates === []) {
            return 0;
        }

        usort(
            $parsedDates,
            static fn(\Carbon\CarbonImmutable $left, \Carbon\CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp()
        );

        $maxStreak = 1;
        $currentStreak = 1;

        for ($index = 1, $count = count($parsedDates); $index < $count; $index++) {
            $expectedNextDate = $this->nextDate($parsedDates[$index - 1]);
            if ($parsedDates[$index]->equalTo($expectedNextDate)) {
                $currentStreak++;
            } else {
                $currentStreak = 1;
            }

            if ($currentStreak > $maxStreak) {
                $maxStreak = $currentStreak;
            }
        }

        return $maxStreak;
    }

    private function buildSickLeavePayStatusOverrides(
        ?array $selectedDates,
        ?array $selectedDateCoverage,
        float $totalDays,
        float $withPayCap = 5.0,
        ?string $employeeControlNo = null
    ): ?array {
        if (!is_array($selectedDates) || $selectedDates === []) {
            return null;
        }

        $resolvedDates = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeDateKey($rawDate);
            if ($dateKey === null) {
                $dateKey = trim((string) $rawDate);
            }
            if ($dateKey === '') {
                continue;
            }

            $resolvedDates[$dateKey] = true;
        }

        $resolvedDates = array_keys($resolvedDates);
        sort($resolvedDates);

        if ($resolvedDates === []) {
            return null;
        }

        $coverageWeights = $this->resolveDateCoverageWeights(
            $resolvedDates,
            $selectedDateCoverage,
            $totalDays,
            $employeeControlNo
        );

        $remainingWithPay = round(max($withPayCap, 0.0), 2);
        $statusOverrides = [];

        foreach ($resolvedDates as $dateKey) {
            $weight = round(max((float) ($coverageWeights[$dateKey] ?? 1.0), 0.0), 2);
            if ($weight <= 0) {
                $statusOverrides[$dateKey] = LeaveApplication::PAY_MODE_WITHOUT_PAY;
                continue;
            }

            if ($remainingWithPay <= 0.0) {
                $statusOverrides[$dateKey] = LeaveApplication::PAY_MODE_WITHOUT_PAY;
                continue;
            }

            if ($remainingWithPay + 1e-9 < $weight) {
                $statusOverrides[$dateKey] = LeaveApplication::PAY_MODE_WITHOUT_PAY;
                continue;
            }

            $statusOverrides[$dateKey] = LeaveApplication::PAY_MODE_WITH_PAY;
            $remainingWithPay = round(max($remainingWithPay - $weight, 0.0), 2);
        }

        return $statusOverrides;
    }

    private function resolveCreditBasedPayAllocation(
        ?array $selectedDates,
        ?array $selectedDateCoverage,
        float $totalDays,
        float $availableCredits,
        ?array $preferredSelectedDatePayStatus = null,
        ?string $employeeControlNo = null
    ): array {
        $normalizedTotalDays = round(max($totalDays, 0.0), 2);
        $normalizedAvailableCredits = round(max($availableCredits, 0.0), 2);

        if ($normalizedTotalDays <= 0.0 || $normalizedAvailableCredits <= 0.0) {
            return [
                'pay_mode' => LeaveApplication::PAY_MODE_WITHOUT_PAY,
                'selected_date_pay_status' => null,
                'deductible_days' => 0.0,
            ];
        }

        if ($normalizedAvailableCredits + 1e-9 >= $normalizedTotalDays) {
            return [
                'pay_mode' => LeaveApplication::PAY_MODE_WITH_PAY,
                'selected_date_pay_status' => $this->compactSelectedDatePayStatusMap(
                    $preferredSelectedDatePayStatus,
                    $selectedDates,
                    LeaveApplication::PAY_MODE_WITH_PAY
                ),
                'deductible_days' => $normalizedTotalDays,
            ];
        }

        $preferredCompactedPayStatus = $this->compactSelectedDatePayStatusMap(
            $preferredSelectedDatePayStatus,
            $selectedDates,
            LeaveApplication::PAY_MODE_WITH_PAY
        );
        if ($preferredCompactedPayStatus !== null) {
            $preferredDeductibleDays = $this->computeDeductibleDays(
                $normalizedTotalDays,
                $selectedDates,
                $preferredCompactedPayStatus,
                $selectedDateCoverage,
                false,
                LeaveApplication::PAY_MODE_WITH_PAY,
                $employeeControlNo
            );

            if ($preferredDeductibleDays <= $normalizedAvailableCredits + 1e-9) {
                if ($preferredDeductibleDays <= 0.0) {
                    return [
                        'pay_mode' => LeaveApplication::PAY_MODE_WITHOUT_PAY,
                        'selected_date_pay_status' => null,
                        'deductible_days' => 0.0,
                    ];
                }

                return [
                    'pay_mode' => LeaveApplication::PAY_MODE_WITH_PAY,
                    'selected_date_pay_status' => $preferredCompactedPayStatus,
                    'deductible_days' => round(max($preferredDeductibleDays, 0.0), 2),
                ];
            }
        }

        if (!is_array($selectedDates) || $selectedDates === []) {
            return [
                'pay_mode' => LeaveApplication::PAY_MODE_WITH_PAY,
                'selected_date_pay_status' => null,
                'deductible_days' => $normalizedAvailableCredits,
            ];
        }

        $payStatusOverrides = $this->buildSickLeavePayStatusOverrides(
            $selectedDates,
            $selectedDateCoverage,
            $normalizedTotalDays,
            $normalizedAvailableCredits,
            $employeeControlNo
        );
        $compactedPayStatusOverrides = $this->compactSelectedDatePayStatusMap(
            $payStatusOverrides,
            $selectedDates,
            LeaveApplication::PAY_MODE_WITH_PAY
        );

        $deductibleDays = $this->computeDeductibleDays(
            $normalizedTotalDays,
            $selectedDates,
            $compactedPayStatusOverrides,
            $selectedDateCoverage,
            false,
            LeaveApplication::PAY_MODE_WITH_PAY,
            $employeeControlNo
        );

        if ($deductibleDays > $normalizedAvailableCredits) {
            $deductibleDays = $normalizedAvailableCredits;
        }

        if ($deductibleDays <= 0.0) {
            return [
                'pay_mode' => LeaveApplication::PAY_MODE_WITHOUT_PAY,
                'selected_date_pay_status' => null,
                'deductible_days' => 0.0,
            ];
        }

        return [
            'pay_mode' => LeaveApplication::PAY_MODE_WITH_PAY,
            'selected_date_pay_status' => $compactedPayStatusOverrides,
            'deductible_days' => round(max($deductibleDays, 0.0), 2),
        ];
    }

    private function resolveDateCoverageWeights(
        array $selectedDates,
        ?array $selectedDateCoverage,
        float $totalDays,
        ?string $employeeControlNo = null
    ): array {
        $resolvedDates = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeDateKey($rawDate);
            if ($dateKey === null) {
                $dateKey = trim((string) $rawDate);
            }
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

        $defaultWholeDayWeight = $this->workScheduleService()->resolveCoverageDeductionDays('whole', $employeeControlNo);
        $defaultHalfDayWeight = $this->workScheduleService()->resolveCoverageDeductionDays('half', $employeeControlNo);
        $defaultCoverageWeight = $defaultWholeDayWeight;
        $dateCount = count($resolvedDates);
        if ($dateCount > 0) {
            $halfMatch = abs(($dateCount * $defaultHalfDayWeight) - $totalDays) < 0.00001;
            $wholeMatch = abs(((float) $dateCount) - $totalDays) < 0.00001;
            if ($halfMatch) {
                $defaultCoverageWeight = $defaultHalfDayWeight;
            } elseif (!$wholeMatch) {
                $defaultCoverageWeight = round(max(min($totalDays / $dateCount, $defaultWholeDayWeight), $defaultHalfDayWeight), 2);
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

            if ($hasCoverageOverrides && !$hasCoverageValue) {
                $weights[$dateKey] = $defaultWholeDayWeight;
                continue;
            }

            $weights[$dateKey] = $defaultCoverageWeight;
        }

        return $weights;
    }

    private function normalizeDateKey(mixed $rawDate): ?string
    {
        if ($rawDate === null || $rawDate === '') {
            return null;
        }

        if ($rawDate instanceof \DateTimeInterface) {
            return \Carbon\CarbonImmutable::instance($rawDate)->toDateString();
        }

        $raw = trim((string) $rawDate);
        if ($raw === '') {
            return null;
        }

        // Keep short numeric indexes (e.g. "0", "1") available for selected-date lookup mapping.
        if (preg_match('/^\d+$/', $raw) === 1 && strlen($raw) <= 3) {
            return null;
        }

        try {
            return \Carbon\CarbonImmutable::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
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

    private function resolveRequestedPayMode(
        Request $request,
        array $validated,
        bool $isMonetization = false,
        mixed $fallback = null
    ): string {
        if ($isMonetization) {
            return LeaveApplication::PAY_MODE_WITH_PAY;
        }

        $explicitPayMode = array_key_exists('pay_mode', $validated)
            ? $validated['pay_mode']
            : $request->input('pay_mode');
        if ($explicitPayMode !== null && trim((string) $explicitPayMode) !== '') {
            return $this->normalizePayMode($explicitPayMode);
        }

        $withoutPayFlag = $this->normalizeBooleanFlag(
            array_key_exists('without_pay', $validated)
                ? $validated['without_pay']
                : $request->input('without_pay')
        );
        if ($withoutPayFlag !== null) {
            return $withoutPayFlag
                ? LeaveApplication::PAY_MODE_WITHOUT_PAY
                : LeaveApplication::PAY_MODE_WITH_PAY;
        }

        $withPayFlag = $this->normalizeBooleanFlag(
            array_key_exists('with_pay', $validated)
                ? $validated['with_pay']
                : $request->input('with_pay')
        );
        if ($withPayFlag !== null) {
            return $withPayFlag
                ? LeaveApplication::PAY_MODE_WITH_PAY
                : LeaveApplication::PAY_MODE_WITHOUT_PAY;
        }

        $payStatusMode = $this->resolvePayModeFromStatusValue(
            array_key_exists('pay_status', $validated)
                ? $validated['pay_status']
                : ($request->input('pay_status') ?? $request->input('payStatus'))
        );
        if ($payStatusMode !== null) {
            return $payStatusMode;
        }

        $selectedDatePayStatus = array_key_exists('selected_date_pay_status', $validated)
            ? $validated['selected_date_pay_status']
            : ($request->input('selected_date_pay_status') ?? $request->input('selectedDatePayStatus'));

        if (is_array($selectedDatePayStatus) && $selectedDatePayStatus !== []) {
            $hasWithPay = false;
            $hasWithoutPay = false;

            foreach ($selectedDatePayStatus as $value) {
                $mode = $this->resolvePayModeFromStatusValue($value);
                if ($mode === LeaveApplication::PAY_MODE_WITH_PAY) {
                    $hasWithPay = true;
                } elseif ($mode === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                    $hasWithoutPay = true;
                }
            }

            if ($hasWithoutPay && !$hasWithPay) {
                return LeaveApplication::PAY_MODE_WITHOUT_PAY;
            }

            if ($hasWithPay) {
                return LeaveApplication::PAY_MODE_WITH_PAY;
            }
        }

        return $this->normalizePayMode($fallback);
    }

    private function resolvePayModeFromStatusValue(mixed $value): ?string
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

    private function normalizeBooleanFlag(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (float) $value !== 0.0;
        }

        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }

        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }

        return null;
    }

    private function normalizeSelectedDatePayStatusMap(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (!is_array($value) || $value === []) {
            return null;
        }

        $normalized = [];
        foreach ($value as $rawDate => $rawStatus) {
            $dateKey = $this->normalizeDateKey($rawDate);
            if ($dateKey === null) {
                $dateKey = trim((string) $rawDate);
            }
            if ($dateKey === '') {
                continue;
            }

            $resolvedMode = $this->resolvePayModeFromStatusValue($rawStatus);
            if ($resolvedMode === null) {
                continue;
            }

            $normalized[$dateKey] = $resolvedMode;
        }

        if ($normalized === []) {
            return null;
        }

        ksort($normalized);
        return $normalized;
    }

    private function normalizeSelectedDateCoverageValue(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace([' ', '-'], '_', $normalized);
        if (in_array($normalized, ['whole', 'whole_day', 'wholeday'], true)) {
            return 'whole';
        }

        if (in_array($normalized, ['half', 'half_day', 'halfday'], true)) {
            return 'half';
        }

        return $this->normalizeSelectedDateHalfDayPortionValue($value) !== null ? 'half' : null;
    }

    private function normalizeSelectedDateHalfDayPortionValue(mixed $value): ?string
    {
        $normalized = strtoupper(str_replace([' ', '-', '_'], '', trim((string) $value)));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'AM', 'MORNING' => 'AM',
            'PM', 'AFTERNOON' => 'PM',
            default => null,
        };
    }

    private function normalizeSelectedDateCoverageMap(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (!is_array($value) || $value === []) {
            return null;
        }

        $normalized = [];
        foreach ($value as $rawDate => $rawCoverage) {
            $dateKey = $this->normalizeDateKey($rawDate);
            if ($dateKey === null) {
                $dateKey = trim((string) $rawDate);
            }
            if ($dateKey === '') {
                continue;
            }

            $coverage = $this->normalizeSelectedDateCoverageValue($rawCoverage);
            if ($coverage === null) {
                continue;
            }

            $normalized[$dateKey] = $coverage;
        }

        if ($normalized === []) {
            return null;
        }

        ksort($normalized);
        return $normalized;
    }

    private function normalizeSelectedDateHalfDayPortionMap(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (!is_array($value) || $value === []) {
            return null;
        }

        $normalized = [];
        foreach ($value as $rawDate => $rawPortion) {
            $dateKey = $this->normalizeDateKey($rawDate);
            if ($dateKey === null) {
                $dateKey = trim((string) $rawDate);
            }
            if ($dateKey === '') {
                continue;
            }

            $portion = $this->normalizeSelectedDateHalfDayPortionValue($rawPortion);
            if ($portion === null) {
                continue;
            }

            $normalized[$dateKey] = $portion;
        }

        if ($normalized === []) {
            return null;
        }

        ksort($normalized);
        return $normalized;
    }

    private function compactSelectedDateHalfDayPortionMap(
        ?array $selectedDateHalfDayPortion,
        ?array $selectedDates
    ): ?array {
        if (!is_array($selectedDateHalfDayPortion) || $selectedDateHalfDayPortion === []) {
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

                $dateKey = $this->normalizeDateKey($rawDate);
                if ($dateKey === null) {
                    $dateKey = $rawKey;
                }

                $dateSet[$dateKey] = true;
                $selectedDateLookup[(string) $index] = $dateKey;
                $selectedDateLookup[$rawKey] = $dateKey;
            }
        }
        $restrictToSelectedDates = $dateSet !== [];

        $compacted = [];
        foreach ($selectedDateHalfDayPortion as $rawDate => $rawPortion) {
            $rawKey = trim((string) $rawDate);
            $dateKey = $this->normalizeDateKey($rawDate);
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

            $portion = $this->normalizeSelectedDateHalfDayPortionValue($rawPortion);
            if ($portion === null) {
                continue;
            }

            $compacted[$dateKey] = $portion;
        }

        if ($compacted === []) {
            return null;
        }

        ksort($compacted);
        return $compacted;
    }

    private function mergeSelectedDateHalfDayPortionMaps(?array $primaryMap, ?array $fallbackMap): ?array
    {
        $resolvedMap = [];
        if (is_array($fallbackMap) && $fallbackMap !== []) {
            $resolvedMap = $fallbackMap;
        }

        if (is_array($primaryMap) && $primaryMap !== []) {
            foreach ($primaryMap as $dateKey => $portion) {
                $resolvedMap[$dateKey] = $portion;
            }
        }

        if ($resolvedMap === []) {
            return null;
        }

        ksort($resolvedMap);
        return $resolvedMap;
    }

    private function synchronizeSelectedDateCoverageMetadata(
        ?array $selectedDateCoverage,
        ?array $selectedDateHalfDayPortion,
        ?array $selectedDates
    ): array {
        $resolvedCoverage = $this->compactSelectedDateCoverageMap($selectedDateCoverage, $selectedDates);
        $resolvedHalfDayPortion = $this->compactSelectedDateHalfDayPortionMap($selectedDateHalfDayPortion, $selectedDates);

        if (is_array($resolvedHalfDayPortion) && $resolvedHalfDayPortion !== []) {
            $coverageMap = is_array($resolvedCoverage) ? $resolvedCoverage : [];
            foreach (array_keys($resolvedHalfDayPortion) as $dateKey) {
                $coverageMap[$dateKey] = 'half';
            }
            ksort($coverageMap);
            $resolvedCoverage = $coverageMap === [] ? null : $coverageMap;
        }

        if (is_array($resolvedHalfDayPortion) && $resolvedHalfDayPortion !== []) {
            foreach ($resolvedHalfDayPortion as $dateKey => $portion) {
                if (($resolvedCoverage[$dateKey] ?? null) !== 'half') {
                    unset($resolvedHalfDayPortion[$dateKey]);
                }
            }

            if ($resolvedHalfDayPortion === []) {
                $resolvedHalfDayPortion = null;
            }
        }

        return [
            'selectedDateCoverage' => $resolvedCoverage,
            'selectedDateHalfDayPortion' => $resolvedHalfDayPortion,
        ];
    }

    private function compactSelectedDatePayStatusMap(
        ?array $selectedDatePayStatus,
        ?array $selectedDates,
        string $payMode
    ): ?array {
        if (!is_array($selectedDatePayStatus) || $selectedDatePayStatus === []) {
            return null;
        }

        $defaultMode = $this->normalizePayMode($payMode, false);
        $dateSet = [];
        $selectedDateLookup = [];
        if (is_array($selectedDates)) {
            foreach ($selectedDates as $index => $rawDate) {
                $rawKey = trim((string) $rawDate);
                if ($rawKey === '') {
                    continue;
                }

                $dateKey = $this->normalizeDateKey($rawDate);
                if ($dateKey === null) {
                    $dateKey = $rawKey;
                }

                $dateSet[$dateKey] = true;
                $selectedDateLookup[(string) $index] = $dateKey;
                $selectedDateLookup[$rawKey] = $dateKey;
            }
        }
        $restrictToSelectedDates = $dateSet !== [];

        $compacted = [];
        foreach ($selectedDatePayStatus as $rawDate => $rawStatus) {
            $rawKey = trim((string) $rawDate);
            $dateKey = $this->normalizeDateKey($rawDate);
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

            $resolvedMode = $this->resolvePayModeFromStatusValue($rawStatus);
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

    private function compactSelectedDateCoverageMap(
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

                $dateKey = $this->normalizeDateKey($rawDate);
                if ($dateKey === null) {
                    $dateKey = $rawKey;
                }

                $dateSet[$dateKey] = true;
                $selectedDateLookup[(string) $index] = $dateKey;
                $selectedDateLookup[$rawKey] = $dateKey;
            }
        }
        $restrictToSelectedDates = $dateSet !== [];

        $compacted = [];
        foreach ($selectedDateCoverage as $rawDate => $rawCoverage) {
            $rawKey = trim((string) $rawDate);
            $dateKey = $this->normalizeDateKey($rawDate);
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
                continue;
            }

            if ($restrictToSelectedDates) {
                continue;
            }
        }

        if ($compacted === []) {
            return null;
        }

        ksort($compacted);
        return $compacted;
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
        $normalizedTotalDays = round(max($totalDays, 0.0), 2);
        if ($normalizedTotalDays <= 0) {
            return 0.0;
        }

        if ($isMonetization) {
            return $normalizedTotalDays;
        }

        $normalizedPayMode = $this->normalizePayMode($payMode, false);

        $resolvedDates = [];
        $selectedDateLookup = [];
        if (is_array($selectedDates)) {
            foreach ($selectedDates as $index => $rawDate) {
                $rawKey = trim((string) $rawDate);
                if ($rawKey === '') {
                    continue;
                }

                $dateKey = $this->normalizeDateKey($rawDate);
                if ($dateKey === null) {
                    $dateKey = $rawKey;
                }

                $resolvedDates[] = $dateKey;
                $selectedDateLookup[(string) $index] = $dateKey;
                $selectedDateLookup[$rawKey] = $dateKey;
            }
        }
        $resolvedDates = array_values(array_unique($resolvedDates));
        sort($resolvedDates);

        $payStatusMap = [];
        if (is_array($selectedDatePayStatus)) {
            foreach ($selectedDatePayStatus as $rawDate => $status) {
                $rawKey = trim((string) $rawDate);
                $dateKey = $this->normalizeDateKey($rawDate);
                if ($dateKey === null && $rawKey !== '' && array_key_exists($rawKey, $selectedDateLookup)) {
                    $dateKey = $selectedDateLookup[$rawKey];
                }
                if ($dateKey === null) {
                    $dateKey = $rawKey;
                }
                if ($dateKey === '') {
                    continue;
                }
                $payStatusMap[$dateKey] = $status;
            }
        }

        $coverageMap = [];
        if (is_array($selectedDateCoverage)) {
            foreach ($selectedDateCoverage as $rawDate => $coverage) {
                $rawKey = trim((string) $rawDate);
                $dateKey = $this->normalizeDateKey($rawDate);
                if ($dateKey === null && $rawKey !== '' && array_key_exists($rawKey, $selectedDateLookup)) {
                    $dateKey = $selectedDateLookup[$rawKey];
                }
                if ($dateKey === null) {
                    $dateKey = $rawKey;
                }
                if ($dateKey === '') {
                    continue;
                }
                $coverageMap[$dateKey] = $coverage;
            }
        }
        $hasCoverageOverrides = $coverageMap !== [];

        if ($resolvedDates === []) {
            $resolvedDates = array_values(array_unique(array_merge(
                array_keys($payStatusMap),
                array_keys($coverageMap)
            )));
            sort($resolvedDates);
        }

        if ($resolvedDates === []) {
            return $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY ? 0.0 : $normalizedTotalDays;
        }

        $defaultWholeDayWeight = $this->workScheduleService()->resolveCoverageDeductionDays('whole', $employeeControlNo);
        $defaultHalfDayWeight = $this->workScheduleService()->resolveCoverageDeductionDays('half', $employeeControlNo);
        $defaultCoverageWeight = $defaultWholeDayWeight;
        $dateCount = count($resolvedDates);
        if ($dateCount > 0) {
            $halfMatch = abs(($dateCount * $defaultHalfDayWeight) - $normalizedTotalDays) < 0.00001;
            $wholeMatch = abs(((float) $dateCount) - $normalizedTotalDays) < 0.00001;
            if ($halfMatch) {
                $defaultCoverageWeight = $defaultHalfDayWeight;
            } elseif (!$wholeMatch) {
                $defaultCoverageWeight = round(max(min($normalizedTotalDays / $dateCount, $defaultWholeDayWeight), $defaultHalfDayWeight), 2);
            }
        }

        $weightedTotal = 0.0;
        $weightedPaid = 0.0;
        foreach ($resolvedDates as $dateKey) {
            $hasCoverageValue = array_key_exists($dateKey, $coverageMap);
            $coverage = $hasCoverageValue ? strtolower(trim((string) ($coverageMap[$dateKey] ?? ''))) : '';
            if ($coverage === 'half') {
                $weight = $defaultHalfDayWeight;
            } elseif ($coverage === 'whole') {
                $weight = $defaultWholeDayWeight;
            } elseif ($hasCoverageOverrides) {
                // Stored coverage maps are sparse (half-day overrides only), so missing keys mean whole-day.
                $weight = $defaultWholeDayWeight;
            } else {
                $weight = $defaultCoverageWeight;
            }

            $weightedTotal += $weight;

            $status = $payStatusMap[$dateKey] ?? $normalizedPayMode;
            $resolvedMode = $this->resolvePayModeFromStatusValue($status);
            $effectiveMode = $resolvedMode ?? $normalizedPayMode;
            if ($effectiveMode === LeaveApplication::PAY_MODE_WITH_PAY) {
                $weightedPaid += $weight;
            }
        }

        if ($weightedTotal <= 0.0) {
            return $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY ? 0.0 : $normalizedTotalDays;
        }

        return round(max($weightedPaid, 0.0), 2);
    }

    private function resolveApplicationDeductibleDays(LeaveApplication $app): float
    {
        $totalDays = round((float) ($app->total_days ?? 0), 2);
        if ($totalDays <= 0) {
            return 0.0;
        }

        if ($app->deductible_days !== null) {
            $stored = round((float) $app->deductible_days, 2);
            if ($stored < 0) {
                return 0.0;
            }
            return $stored;
        }

        if ((bool) $app->is_monetization) {
            return $totalDays;
        }

        $storedCtoHours = round((float) ($app->cto_deducted_hours ?? 0), 2);
        if ($storedCtoHours > 0 && $this->isCtoLeaveType($app->leaveType, (int) $app->leave_type_id)) {
            return round($storedCtoHours / self::CTO_STANDARD_DAY_HOURS, 2);
        }

        return $this->normalizePayMode($app->pay_mode ?? null, false) === LeaveApplication::PAY_MODE_WITHOUT_PAY
            ? 0.0
            : $totalDays;
    }

    private function resolveApplicationCtoDeductedHours(LeaveApplication $app): float
    {
        $storedHours = round((float) ($app->cto_deducted_hours ?? 0), 2);
        if ($storedHours > 0) {
            return $storedHours;
        }

        if (!$this->isCtoLeaveType($app->leaveType, (int) $app->leave_type_id)) {
            return 0.0;
        }

        return $this->resolveCtoDeductedHours($this->resolveApplicationDeductibleDays($app));
    }

    private function resolveRecallRestorableDetails(
        LeaveApplication $app,
        array $selectedRecallDateKeys = []
    ): array {
        $deductibleDays = $this->resolveApplicationDeductibleDays($app);
        if ($deductibleDays <= 0.0) {
            return ['days' => 0.0, 'dates' => []];
        }

        if ((bool) $app->is_monetization) {
            return ['days' => $deductibleDays, 'dates' => []];
        }

        $selectedDates = $app->resolvedSelectedDates();
        if (!is_array($selectedDates) || $selectedDates === []) {
            return ['days' => $deductibleDays, 'dates' => []];
        }

        $normalizedPayMode = $this->normalizePayMode($app->pay_mode ?? null, false);
        $normalizedPayStatus = $this->compactSelectedDatePayStatusMap(
            is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null,
            $selectedDates,
            $normalizedPayMode
        );
        $normalizedCoverage = $this->compactSelectedDateCoverageMap(
            is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
            $selectedDates
        );
        $coverageWeights = $this->resolveDateCoverageWeights(
            $selectedDates,
            $normalizedCoverage,
            round((float) ($app->total_days ?? 0), 2),
            (string) ($app->employee_control_no ?? '')
        );

        $selectedRecallDateSet = array_fill_keys(
            $this->normalizeRecallDateKeys($selectedRecallDateKeys),
            true
        );
        if ($selectedRecallDateSet === []) {
            return ['days' => 0.0, 'dates' => []];
        }

        $restorableDays = 0.0;
        $restorableDateKeys = [];

        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeDateKey($rawDate);
            if ($dateKey === null) {
                $dateKey = trim((string) $rawDate);
            }
            if ($dateKey === '' || !isset($selectedRecallDateSet[$dateKey])) {
                continue;
            }

            $weight = round(max((float) ($coverageWeights[$dateKey] ?? 1.0), 0.0), 2);
            if ($weight <= 0.0) {
                continue;
            }

            $effectiveMode = $normalizedPayStatus[$dateKey] ?? $normalizedPayMode;
            $resolvedMode = $this->resolvePayModeFromStatusValue($effectiveMode) ?? $normalizedPayMode;
            if ($resolvedMode !== LeaveApplication::PAY_MODE_WITH_PAY) {
                continue;
            }

            $restorableDays += $weight;
            $restorableDateKeys[] = $dateKey;
        }

        $restorableDays = round(max($restorableDays, 0.0), 2);
        if ($restorableDays > $deductibleDays) {
            $restorableDays = $deductibleDays;
        }

        $restorableDateKeys = array_values(array_unique(array_filter($restorableDateKeys)));
        sort($restorableDateKeys);

        return [
            'days' => $restorableDays,
            'dates' => $restorableDateKeys,
        ];
    }

    private function resolveValidatedRecallSelectedDateKeys(
        LeaveApplication $app,
        array $requestedDates = []
    ): ?array {
        $allowedDateKeys = $this->resolveRecallSelectedDateKeys($app);
        $requestedDateKeys = $this->normalizeRecallDateKeys($requestedDates);

        if ($requestedDateKeys === []) {
            return null;
        }

        if ($allowedDateKeys === []) {
            return null;
        }

        $allowedDateSet = array_fill_keys($allowedDateKeys, true);
        foreach ($requestedDateKeys as $dateKey) {
            if (!isset($allowedDateSet[$dateKey])) {
                return null;
            }
        }

        return $requestedDateKeys;
    }

    private function resolveRecallSelectedDateKeys(LeaveApplication $app): array
    {
        $selectedDates = $app->resolvedSelectedDates();
        if (!is_array($selectedDates) || $selectedDates === []) {
            return [];
        }

        $storedRecallDateSet = array_fill_keys(
            $this->resolveEffectiveStoredRecallDateKeys($app),
            true
        );

        $normalizedDateKeys = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->normalizeDateKey($rawDate);
            if ($dateKey === null) {
                $dateKey = trim((string) $rawDate);
            }
            if ($dateKey === '') {
                continue;
            }
            if (isset($storedRecallDateSet[$dateKey])) {
                continue;
            }

            $normalizedDateKeys[] = $dateKey;
        }

        $normalizedDateKeys = array_values(array_unique($normalizedDateKeys));
        sort($normalizedDateKeys);

        return $normalizedDateKeys;
    }

    private function resolveEffectiveStoredRecallDateKeys(LeaveApplication $app): array
    {
        $storedRecallDateKeys = $this->normalizeRecallDateKeys(
            is_array($app->recall_selected_dates) ? $app->recall_selected_dates : []
        );
        if ($storedRecallDateKeys === []) {
            return [];
        }

        $selectedDateKeys = $this->normalizeRecallDateKeys(
            is_array($app->resolvedSelectedDates()) ? $app->resolvedSelectedDates() : []
        );
        if ($selectedDateKeys === []) {
            return $storedRecallDateKeys;
        }

        $latestUpdateMeta = $this->resolveLatestUpdateMeta($app);
        $latestUpdateStatus = strtoupper(trim((string) ($latestUpdateMeta['status'] ?? '')));
        $latestUpdateReviewedAt = $latestUpdateMeta['reviewed_at'] ?? null;
        $latestRecallLoggedAt = $this->resolveLatestRecallLoggedAt($app);

        if (
            $latestUpdateStatus === LeaveApplicationUpdateRequest::STATUS_APPROVED
            && $latestUpdateReviewedAt instanceof \DateTimeInterface
            && $latestRecallLoggedAt instanceof \DateTimeInterface
            && $latestUpdateReviewedAt->getTimestamp() > $latestRecallLoggedAt->getTimestamp()
        ) {
            $selectedDateSet = array_fill_keys($selectedDateKeys, true);
            $storedRecallDateKeys = array_values(array_filter(
                $storedRecallDateKeys,
                static fn (string $dateKey): bool => !isset($selectedDateSet[$dateKey])
            ));
        }

        $storedRecallDateKeys = array_values(array_unique($storedRecallDateKeys));
        sort($storedRecallDateKeys);

        return $storedRecallDateKeys;
    }

    private function resolveLatestRecallLoggedAt(LeaveApplication $app): ?\DateTimeInterface
    {
        $logs = $app->relationLoaded('logs')
            ? $app->logs
            : $app->logs()->get();

        if (!$logs instanceof Collection || $logs->isEmpty()) {
            return null;
        }

        $latestRecallLog = $logs
            ->filter(fn (mixed $entry): bool => $entry instanceof LeaveApplicationLog
                && $entry->action === LeaveApplicationLog::ACTION_HR_RECALLED)
            ->sortByDesc(fn (LeaveApplicationLog $entry) => $entry->created_at?->getTimestamp() ?? PHP_INT_MIN)
            ->first();

        return $latestRecallLog instanceof LeaveApplicationLog
            ? $latestRecallLog->created_at
            : null;
    }

    private function normalizeRecallDateKeys(array $rawDates): array
    {
        $normalizedDateKeys = [];

        foreach ($rawDates as $rawDate) {
            $dateKey = $this->normalizeDateKey($rawDate);
            if ($dateKey === null) {
                $dateKey = trim((string) $rawDate);
            }
            if ($dateKey === '') {
                continue;
            }

            $normalizedDateKeys[] = $dateKey;
        }

        $normalizedDateKeys = array_values(array_unique($normalizedDateKeys));
        sort($normalizedDateKeys);

        return $normalizedDateKeys;
    }

    private function applicationDeductsEmployeeBalance(
        bool $isMonetization,
        ?LeaveType $leaveType,
        mixed $payMode
    ): bool {
        if ($isMonetization) {
            return true;
        }

        if ($this->normalizePayMode($payMode, false) === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
            return false;
        }

        return (bool) ($leaveType?->is_credit_based);
    }

    private function validateNoDuplicateLeaveDates(
        string $employeeControlNo,
        string $startDate,
        string $endDate,
        ?array $selectedDates = null,
        mixed $totalDays = null,
        ?int $excludeApplicationId = null
    ): ?JsonResponse {
        $requestedDates = $this->resolveLeaveDateSet($startDate, $endDate, $selectedDates, $totalDays);
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
            ->whereIn('employee_control_no', $this->controlNoCandidates($employeeControlNo))
            ->when($excludeApplicationId !== null, function ($query) use ($excludeApplicationId): void {
                $query->where('id', '<>', $excludeApplicationId);
            })
            ->get();

        $duplicateDateMap = [];
        foreach ($existingApplications as $existingApplication) {
            $existingDates = $this->resolveLeaveDateSet(
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
            static fn(string $date): string => \Carbon\CarbonImmutable::parse($date)->format('M j, Y'),
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

    private function resolveLeaveDateSet(
        ?string $startDate,
        ?string $endDate,
        ?array $selectedDates = null,
        mixed $totalDays = null
    ): array
    {
        return LeaveApplication::resolveDateSet($startDate, $endDate, $selectedDates, $totalDays);
    }

    private function validateRegularLeaveEligibility(
        string $employeeControlNo,
        int $leaveTypeId,
        float $requestedDays,
        string $payMode = LeaveApplication::PAY_MODE_WITH_PAY,
        ?float $requestedDeductibleDays = null,
        ?float $requestedCtoHours = null
    ): array|JsonResponse {
        $leaveType = LeaveType::find($leaveTypeId);
        if (!$leaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
                'errors' => [
                    'leave_type_id' => ['Selected leave type is not available.'],
                ],
            ], 422);
        }

        $employee = $this->findEmployeeByControlNo($employeeControlNo);
        if ($employee) {
            $leaveTypeRestriction = $this->assertEmployeeCanApplyForLeaveType($employee, $leaveType);
            if ($leaveTypeRestriction instanceof JsonResponse) {
                return $leaveTypeRestriction;
            }
        }

        if ($leaveType->max_days && $requestedDays > (float) $leaveType->max_days) {
            return response()->json([
                'message' => "This leave type is limited to {$leaveType->max_days} days per application.",
                'errors' => [
                    'total_days' => ["Maximum of {$leaveType->max_days} days allowed for {$leaveType->name}."],
                ],
            ], 422);
        }

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        if ($forcedLeaveTypeId !== null && (int) $leaveTypeId === $forcedLeaveTypeId) {
            $requiredVacationLeaveDays = round(max($requestedDays, 0.0), 2);
            if ($requiredVacationLeaveDays > 0) {
                if ($vacationLeaveTypeId !== null) {
                    $vacationBalanceSnapshot = $this->resolveEmployeeLeaveBalanceSnapshot(
                        $employeeControlNo,
                        $vacationLeaveTypeId
                    );
                    $availableVacationBalance = (float) ($vacationBalanceSnapshot['available_balance'] ?? 0.0);
                    if ($availableVacationBalance + 1e-9 < $requiredVacationLeaveDays) {
                        return response()->json([
                            'message' => 'Insufficient Vacation Leave balance to apply Mandatory / Forced Leave.',
                            'errors' => [
                                'leave_type_id' => ['Mandatory / Forced Leave requires enough Vacation Leave balance.'],
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
            }
        }

        $normalizedPayMode = $this->normalizePayMode($payMode);
        $requiredBalanceDays = round(max((float) ($requestedDeductibleDays ?? $requestedDays), 0.0), 2);
        $requiresVacationLeaveTopUp = $this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
            $leaveType,
            $leaveTypeId,
            false,
            $vacationLeaveTypeId
        );
        $requiredVacationLeaveDays = $requiresVacationLeaveTopUp
            ? $this->resolveLeaveTypeVacationLeaveDeductionDays(
                $leaveType,
                $leaveTypeId,
                false,
                $requestedDays,
                $requiredBalanceDays,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId
            )
            : 0.0;
        if ($requiresVacationLeaveTopUp && $requiredVacationLeaveDays > 0.0) {
            $requiredBalanceDays = $this->resolvePrimaryLeaveTrackedDeductionDays(
                $leaveType,
                $leaveTypeId,
                false,
                $requestedDays,
                $requiredBalanceDays,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId
            );
        }
        if (
            !$leaveType->is_credit_based
            || $requiredBalanceDays <= 0
            || $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY
        ) {
            return [
                'leave_type' => $leaveType,
                'balance' => null,
                'available_balance' => null,
                'balance_hours' => null,
                'available_balance_hours' => null,
                'pending_reserved_days' => 0.0,
                'pending_reserved_hours' => 0.0,
                'required_balance_days' => $requiredBalanceDays,
                'required_balance_hours' => $requestedCtoHours !== null ? round(max($requestedCtoHours, 0.0), 2) : null,
                'insufficient_balance' => false,
            ];
        }

        if ($this->isCtoLeaveType($leaveType, (int) $leaveTypeId)) {
            $this->syncEmployeeCtoBalance($employeeControlNo);
        }

        $balanceSnapshot = $this->resolveEmployeeLeaveBalanceSnapshot($employeeControlNo, $leaveTypeId);
        $currentBalance = (float) ($balanceSnapshot['current_balance'] ?? 0.0);
        $pendingReservedDays = (float) ($balanceSnapshot['pending_reserved_days'] ?? 0.0);
        $availableBalance = (float) ($balanceSnapshot['available_balance'] ?? 0.0);
        $currentBalanceHours = (float) ($balanceSnapshot['current_balance_hours'] ?? 0.0);
        $pendingReservedHours = (float) ($balanceSnapshot['pending_reserved_hours'] ?? 0.0);
        $availableBalanceHours = (float) ($balanceSnapshot['available_balance_hours'] ?? 0.0);
        $requiredBalanceHours = $this->isCtoLeaveType($leaveType, $leaveTypeId)
            ? round(max((float) ($requestedCtoHours ?? $this->resolveCtoDeductedHours($requiredBalanceDays)), 0.0), 2)
            : null;
        $insufficientBalance = $requiredBalanceHours !== null
            ? ($availableBalanceHours + 1e-9 < $requiredBalanceHours)
            : ($availableBalance + 1e-9 < $requiredBalanceDays);
        if ($requiresVacationLeaveTopUp) {
            if ($insufficientBalance) {
                return $this->buildInsufficientPrimaryLeaveBalanceResponse(
                    $leaveType,
                    $availableBalance,
                    $requiredBalanceDays,
                    true
                );
            }

            if ($vacationLeaveTypeId !== null && $requiredVacationLeaveDays > 0.0) {
                $vacationBalanceSnapshot = $this->resolveEmployeeLeaveBalanceSnapshot(
                    $employeeControlNo,
                    $vacationLeaveTypeId
                );
                $availableVacationBalance = (float) ($vacationBalanceSnapshot['available_balance'] ?? 0.0);
                if ($availableVacationBalance + 1e-9 < $requiredVacationLeaveDays) {
                    return $this->buildInsufficientVacationLeaveTopUpResponse(
                        $leaveType,
                        $availableVacationBalance,
                        $requiredVacationLeaveDays
                    );
                }
            }
        }

        return [
            'leave_type' => $leaveType,
            'balance' => $currentBalance,
            'available_balance' => $availableBalance,
            'balance_hours' => $currentBalanceHours,
            'available_balance_hours' => $availableBalanceHours,
            'pending_reserved_days' => $pendingReservedDays,
            'pending_reserved_hours' => $pendingReservedHours,
            'required_balance_days' => $requiredBalanceDays,
            'required_balance_hours' => $requiredBalanceHours,
            'required_vacation_leave_days' => $requiredVacationLeaveDays,
            'insufficient_balance' => $insufficientBalance,
        ];
    }

    private function buildInsufficientCtoBalanceResponse(array $eligibility): JsonResponse
    {
        $availableBalance = round(max((float) ($eligibility['available_balance'] ?? 0.0), 0.0), 2);
        $requiredBalance = round(max((float) ($eligibility['required_balance_days'] ?? 0.0), 0.0), 2);
        $availableBalanceHours = round(max((float) ($eligibility['available_balance_hours'] ?? 0.0), 0.0), 2);
        $requiredBalanceHours = round(max((float) ($eligibility['required_balance_hours'] ?? 0.0), 0.0), 2);
        $message = 'Insufficient CTO balance. Available CTO is '
            . number_format($availableBalanceHours, 2)
            . ' hour(s)'
            . ($availableBalance > 0 ? ' (' . self::formatDays($availableBalance) . ' display balance)' : '')
            . ', but this request needs '
            . number_format($requiredBalanceHours, 2)
            . ' hour(s). CTO applications must stay with pay and can only use available, unexpired CTO credits.';

        return response()->json([
            'message' => $message,
            'errors' => [
                'leave_type_id' => [
                    $message,
                ],
                'available_cto_balance' => [
                    'Available CTO balance is '
                    . self::formatDays($availableBalance)
                    . ($availableBalanceHours > 0 ? ' (' . number_format($availableBalanceHours, 2) . ' hour(s))' : '')
                    . ', but '
                    . self::formatDays($requiredBalance)
                    . ($requiredBalanceHours > 0 ? ' (' . number_format($requiredBalanceHours, 2) . ' hour(s))' : '')
                    . ' is required.',
                ],
            ],
            'available_cto_balance' => $availableBalance,
            'required_cto_balance' => $requiredBalance,
            'available_cto_balance_hours' => $availableBalanceHours,
            'required_cto_balance_hours' => $requiredBalanceHours,
        ], 422);
    }

    private function buildInsufficientPrimaryLeaveBalanceResponse(
        LeaveType $leaveType,
        float $availableBalance,
        float $requiredBalanceDays,
        bool $requiresVacationLeaveTopUp = false
    ): JsonResponse {
        $message = $requiresVacationLeaveTopUp
            ? "Insufficient {$leaveType->name} balance. Available {$leaveType->name} is "
                . self::formatDays($availableBalance)
                . ', but '
                . self::formatDays($requiredBalanceDays)
                . ' is required before Vacation Leave can cover the schedule-based excess deduction.'
            : "Insufficient {$leaveType->name} balance. Available {$leaveType->name} is "
                . self::formatDays($availableBalance)
                . ', but '
                . self::formatDays($requiredBalanceDays)
                . ' is required.';

        return response()->json([
            'message' => $message,
            'errors' => [
                'leave_type_id' => [$message],
                'available_leave_balance' => [
                    'Available '
                    . $leaveType->name
                    . ' is '
                    . self::formatDays($availableBalance)
                    . ', but '
                    . self::formatDays($requiredBalanceDays)
                    . ' is required.',
                ],
            ],
            'available_balance' => $availableBalance,
            'required_balance_days' => $requiredBalanceDays,
        ], 422);
    }

    private function buildInsufficientVacationLeaveTopUpResponse(
        LeaveType $leaveType,
        float $availableVacationBalance,
        float $requiredVacationLeaveDays
    ): JsonResponse {
        $message = 'Insufficient Vacation Leave balance to cover the schedule-based excess deduction for '
            . $leaveType->name
            . '.';

        return response()->json([
            'message' => $message,
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

    private function resolveEmployeeLeaveBalanceSnapshot(string $employeeControlNo, int $leaveTypeId): array
    {
        $controlNoCandidates = $this->controlNoCandidates($employeeControlNo);
        $lookupLeaveTypeIds = $this->resolveBalanceLookupLeaveTypeIds($leaveTypeId);
        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;

        $isCtoSnapshot = $this->isCtoLeaveType(null, $canonicalLeaveTypeId);

        if ($isCtoSnapshot) {
            $pendingReservedHoursExpression = $this->hasLeaveApplicationCtoHoursColumn()
                ? 'COALESCE(cto_deducted_hours, COALESCE(deductible_days, total_days) * 8.0)'
                : 'COALESCE(deductible_days, total_days) * 8.0';
            $currentBalanceHours = round($this->cocLedgerService()->getAvailableHours($employeeControlNo, $canonicalLeaveTypeId), 2);
            $pendingReservedHours = round((float) LeaveApplication::query()
                ->whereIn('leave_type_id', $lookupLeaveTypeIds === [] ? [$canonicalLeaveTypeId] : $lookupLeaveTypeIds)
                ->whereIn('status', [
                    LeaveApplication::STATUS_PENDING_ADMIN,
                    LeaveApplication::STATUS_PENDING_HR,
                ])
                ->where(function ($query): void {
                    $query->where('is_monetization', true)
                        ->orWhereRaw(
                            'UPPER(LTRIM(RTRIM(COALESCE(pay_mode, ?)))) <> ?',
                            [LeaveApplication::PAY_MODE_WITH_PAY, LeaveApplication::PAY_MODE_WITHOUT_PAY]
                        );
                })
                ->whereIn('employee_control_no', $controlNoCandidates)
                ->sum(DB::raw($pendingReservedHoursExpression)), 2);

            $availableBalanceHours = max(round($currentBalanceHours - $pendingReservedHours, 2), 0.0);
            $currentBalance = round($currentBalanceHours / self::CTO_STANDARD_DAY_HOURS, 2);
            $pendingReservedDays = round($pendingReservedHours / self::CTO_STANDARD_DAY_HOURS, 2);
            $availableBalance = round($availableBalanceHours / self::CTO_STANDARD_DAY_HOURS, 2);

            return [
                'current_balance' => $currentBalance,
                'current_balance_hours' => $currentBalanceHours,
                'pending_reserved_days' => $pendingReservedDays,
                'pending_reserved_hours' => $pendingReservedHours,
                'available_balance' => $availableBalance,
                'available_balance_hours' => $availableBalanceHours,
            ];
        }

        $balance = $this->findPreferredEmployeeLeaveBalanceRecord($employeeControlNo, $canonicalLeaveTypeId);
        $currentBalance = $balance ? (float) $balance->balance : 0.0;

        $pendingReservedDays = $this->resolvePendingReservedDaysForTrackedLeaveType(
            $employeeControlNo,
            $canonicalLeaveTypeId
        );

        $availableBalance = max(round($currentBalance - $pendingReservedDays, 2), 0.0);

        return [
            'current_balance' => $currentBalance,
            'current_balance_hours' => 0.0,
            'pending_reserved_days' => $pendingReservedDays,
            'pending_reserved_hours' => 0.0,
            'available_balance' => $availableBalance,
            'available_balance_hours' => 0.0,
        ];
    }

    private function resolvePendingReservedDaysForTrackedLeaveType(string $employeeControlNo, int $leaveTypeId): float
    {
        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        if ($canonicalLeaveTypeId <= 0) {
            return 0.0;
        }

        $controlNoCandidates = $this->controlNoCandidates($employeeControlNo);
        if ($controlNoCandidates === []) {
            return 0.0;
        }

        $pendingApplications = LeaveApplication::query()
            ->with('leaveType')
            ->whereIn('status', [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
            ])
            ->where(function ($query): void {
                $query->where('is_monetization', true)
                    ->orWhereRaw(
                        'UPPER(LTRIM(RTRIM(COALESCE(pay_mode, ?)))) <> ?',
                        [LeaveApplication::PAY_MODE_WITH_PAY, LeaveApplication::PAY_MODE_WITHOUT_PAY]
                    );
            })
            ->whereIn('employee_control_no', $controlNoCandidates)
            ->get();

        if ($pendingApplications->isEmpty()) {
            return 0.0;
        }

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        $pendingReservedDays = 0.0;

        foreach ($pendingApplications as $pendingApplication) {
            $applicationLeaveType = $pendingApplication->leaveType
                ?? LeaveType::query()->find((int) $pendingApplication->leave_type_id);
            if (!$applicationLeaveType instanceof LeaveType) {
                continue;
            }

            $applicationLeaveTypeId = $this->resolveCanonicalLeaveTypeId((int) $pendingApplication->leave_type_id)
                ?? (int) $pendingApplication->leave_type_id;
            $applicationDeductibleDays = $this->resolveApplicationDeductibleDays($pendingApplication);
            $applicationTotalDays = round((float) ($pendingApplication->total_days ?? $applicationDeductibleDays), 2);

            $pendingReservedDays += $this->resolvePendingReservedDaysContributionForTrackedLeaveType(
                $pendingApplication,
                $applicationLeaveType,
                $applicationLeaveTypeId,
                $canonicalLeaveTypeId,
                $applicationTotalDays,
                $applicationDeductibleDays,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId
            );
        }

        return round(max($pendingReservedDays, 0.0), 2);
    }

    private function resolvePendingReservedDaysContributionForTrackedLeaveType(
        LeaveApplication $app,
        LeaveType $applicationLeaveType,
        int $applicationLeaveTypeId,
        int $targetLeaveTypeId,
        float $applicationTotalDays,
        float $applicationDeductibleDays,
        ?int $forcedLeaveTypeId,
        ?int $vacationLeaveTypeId
    ): float {
        if ($targetLeaveTypeId <= 0) {
            return 0.0;
        }

        $primaryReservedDays = $this->resolvePrimaryLeaveTrackedDeductionDays(
            $applicationLeaveType,
            $applicationLeaveTypeId,
            (bool) $app->is_monetization,
            $applicationTotalDays,
            $applicationDeductibleDays,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );
        if ($applicationLeaveTypeId === $targetLeaveTypeId) {
            return $primaryReservedDays;
        }

        if ($forcedLeaveTypeId !== null && $targetLeaveTypeId === $forcedLeaveTypeId) {
            return $this->resolveStoredLinkedForcedLeaveDeduction(
                $app,
                $applicationLeaveType,
                $applicationDeductibleDays
            );
        }

        if ($vacationLeaveTypeId !== null && $targetLeaveTypeId === $vacationLeaveTypeId) {
            return $this->resolveStoredLinkedVacationLeaveDeduction(
                $app,
                $applicationLeaveType,
                $applicationDeductibleDays
            );
        }

        return 0.0;
    }

    private function formatApplication(LeaveApplication $app): array
    {
        $resolvedEmployee = $this->resolveApplicationEmployee($app);
        $employeeName = trim((string) ($app->employee_name ?? ''));
        if ($employeeName === '') {
            $employeeName = $this->formatEmployeeFullName($resolvedEmployee);
        }
        $applicantName = $employeeName !== ''
            ? $employeeName
            : trim((string) ($app->applicantAdmin?->full_name ?? ''));
        if ($applicantName === '') {
            $applicantName = $this->resolveEmployeeDisplayName($app);
        }
        $office = $resolvedEmployee?->office ?? ($app->applicantAdmin?->department?->name ?? '');
        $durationDays = (float) $app->total_days;
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);
        $latestUpdateMeta = $this->resolveLatestUpdateMeta($app);
        $leaveBalanceSnapshot = $this->getApplicationLeaveBalanceSnapshot($app);
        $currentLeaveBalance = $this->findLeaveTypeBalanceInSnapshot($leaveBalanceSnapshot, (int) $app->leave_type_id);
        $normalizedPayMode = $this->normalizePayMode($app->pay_mode ?? null, (bool) $app->is_monetization);
        $withoutPay = $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY;
        $deductibleDays = $this->resolveApplicationDeductibleDays($app);
        $ctoDeductedHours = $this->resolveApplicationCtoDeductedHours($app);
        $hasPendingApprovedUpdateRequest = $this->hasPendingApprovedUpdateRequest($app);
        $displayStatus = $this->shouldPresentApprovedWhilePendingUpdate($app, $hasPendingApprovedUpdateRequest)
            ? $this->statusToFrontend(LeaveApplication::STATUS_APPROVED)
            : $this->statusToFrontend($app->status);
        $queueMeta = $this->resolveLeaveHrQueueMeta($app);

        $effectiveRecallDateKeys = $this->resolveEffectiveStoredRecallDateKeys($app);

        $data = [
            'id' => $app->id,
            'employee_control_no' => $app->employee_control_no,
            'applicant_admin_id' => $app->applicant_admin_id,
            'leave_type_id' => $app->leave_type_id,
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
            'status' => $displayStatus,
            'rawStatus' => $app->status,
            'queue_group_status' => $queueMeta['group_status'],
            'queueGroupStatus' => $queueMeta['group_status'],
            'queue_group_priority' => $queueMeta['group_priority'],
            'queueGroupPriority' => $queueMeta['group_priority'],
            'queue_stage_key' => $queueMeta['stage_key'],
            'queueStageKey' => $queueMeta['stage_key'],
            'queue_stage_priority' => $queueMeta['stage_priority'],
            'queueStagePriority' => $queueMeta['stage_priority'],
            'dateFiled' => $app->created_at ? $app->created_at->toDateString() : '',
            'filedAt' => $app->created_at?->toIso8601String(),
            'filed_at' => $app->created_at?->toIso8601String(),
            'createdAt' => $app->created_at?->toIso8601String(),
            'created_at' => $app->created_at?->toIso8601String(),
            'remarks' => $app->remarks,
            'pending_update' => $pendingUpdateMeta['payload'],
            'pending_update_action_type' => $pendingUpdateMeta['action_type'],
            'pending_update_reason' => $pendingUpdateMeta['reason'],
            'pending_update_previous_status' => $pendingUpdateMeta['previous_status'],
            'pending_update_requested_by' => $pendingUpdateMeta['requested_by'],
            'pending_update_requested_at' => $pendingUpdateMeta['requested_at']?->toIso8601String(),
            'has_pending_update_request' => $hasPendingApprovedUpdateRequest,
            'latest_update_request_status' => $latestUpdateMeta['status'],
            'latest_update_request_payload' => $latestUpdateMeta['payload'],
            'latest_update_request_action_type' => $latestUpdateMeta['action_type'],
            'latest_update_request_reason' => $latestUpdateMeta['reason'],
            'latest_update_request_previous_status' => $latestUpdateMeta['previous_status'],
            'latest_update_requested_by' => $latestUpdateMeta['requested_by'],
            'latest_update_requested_at' => $latestUpdateMeta['requested_at']?->toIso8601String(),
            'latest_update_reviewed_at' => $latestUpdateMeta['reviewed_at']?->toIso8601String(),
            'latest_update_review_remarks' => $latestUpdateMeta['review_remarks'],
            'selected_dates' => $app->resolvedSelectedDates(),
            'selected_date_pay_status' => is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null,
            'selected_date_coverage' => is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
            'selected_date_half_day_portion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selectedDateHalfDayPortion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selected_date_half_day_period' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selectedDateHalfDayPeriod' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selected_date_halfday_period' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'commutation' => $app->commutation ?? 'Not Requested',
            'pay_mode' => $normalizedPayMode,
            'pay_status' => $withoutPay ? 'Without Pay' : 'With Pay',
            'without_pay' => $withoutPay,
            'with_pay' => !$withoutPay,
            'attachment_required' => (bool) ($app->attachment_required ?? false),
            'attachment_submitted' => (bool) ($app->attachment_submitted ?? false),
            'attachment_reference' => $this->trimNullableString($app->attachment_reference ?? null),
            'attachment_available' => $this->trimNullableString($app->attachment_reference ?? null) !== null,
            'has_attachment' => $this->trimNullableString($app->attachment_reference ?? null) !== null,
            'is_monetization' => (bool) $app->is_monetization,
            'equivalent_amount' => $app->equivalent_amount ? (float) $app->equivalent_amount : null,
            'deductible_days' => $deductibleDays,
            'cto_deducted_hours' => $ctoDeductedHours,
            'leaveBalance' => $currentLeaveBalance,
            'leaveBalances' => $leaveBalanceSnapshot,
            'leave_balances' => $leaveBalanceSnapshot,
            'employee_leave_balances' => $leaveBalanceSnapshot,
            'certificationLeaveCredits' => $this->buildCertificationLeaveCredits($app, $leaveBalanceSnapshot),
            'admin_id' => $app->admin_id,
            'hr_id' => $app->hr_id,
            'admin_approved_at' => $app->admin_approved_at?->toIso8601String(),
            'hr_approved_at' => $app->hr_approved_at?->toIso8601String(),
            'recallEffectiveDate' => $app->recall_effective_date?->toDateString(),
            'recall_effective_date' => $app->recall_effective_date?->toDateString(),
            'recallSelectedDates' => $effectiveRecallDateKeys !== [] ? $effectiveRecallDateKeys : null,
            'recall_selected_dates' => $effectiveRecallDateKeys !== [] ? $effectiveRecallDateKeys : null,
            'employeeName' => $applicantName,
            'employee_name' => $applicantName,
            'applicantName' => $applicantName,
            'applicant_name' => $applicantName,
            'office' => $office,
            'officeAcronym' => $resolvedEmployee?->officeAcronym,
            'office_acronym' => $resolvedEmployee?->officeAcronym,
            'hrisOfficeAcronym' => $resolvedEmployee?->hrisOfficeAcronym,
            'hris_office_acronym' => $resolvedEmployee?->hrisOfficeAcronym,
        ];

        if ($resolvedEmployee) {
            $data['employee'] = [
                'control_no' => $resolvedEmployee->control_no,
                'firstname' => $resolvedEmployee->firstname,
                'middlename' => $resolvedEmployee->middlename,
                'surname' => $resolvedEmployee->surname,
                'full_name' => $this->formatEmployeeFullName($resolvedEmployee),
                'designation' => $resolvedEmployee->designation,
                'office' => $resolvedEmployee->office,
                'officeAcronym' => $resolvedEmployee->officeAcronym,
                'office_acronym' => $resolvedEmployee->officeAcronym,
                'hrisOfficeAcronym' => $resolvedEmployee->hrisOfficeAcronym,
                'hris_office_acronym' => $resolvedEmployee->hrisOfficeAcronym,
            ];
        }

        return $data;
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
        if (!str_starts_with($normalizedReference, 'public/')) {
            $candidatePaths[] = 'public/' . $normalizedReference;
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
                if (!$storage->exists($path)) {
                    continue;
                }

                $filename = basename($path);
                $mimeType = $storage->mimeType($path) ?: 'application/octet-stream';

                return $storage->response(
                    $path,
                    $filename,
                    [
                        'Content-Type' => $mimeType,
                        'Content-Disposition' => 'inline; filename="' . $filename . '"',
                        'X-Content-Type-Options' => 'nosniff',
                    ]
                );
            }
        }

        return response()->json(['message' => 'Attachment file not found.'], 404);
    }

    /**
     * Reprice future leave applications using the current work-schedule
     * deductions and reconcile affected leave balances when needed.
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
        $stats = [
            'checked' => 0,
            'repriced' => 0,
            'skipped' => 0,
            'failed' => 0,
            'updated_application_ids' => [],
            'errors' => [],
        ];

        $query = LeaveApplication::query()
            ->with('leaveType')
            ->whereIn('status', [
                LeaveApplication::STATUS_APPROVED,
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
            ])
            ->where(function ($nestedQuery): void {
                $nestedQuery->whereNull('is_monetization')
                    ->orWhere('is_monetization', false);
            });

        $targetControlNo = trim((string) ($employeeControlNo ?? ''));
        if ($targetControlNo !== '') {
            $controlNoCandidates = $this->controlNoCandidates($targetControlNo);
            if ($controlNoCandidates === []) {
                return $stats;
            }

            $query->whereIn('employee_control_no', $controlNoCandidates);
        }

        $applications = $query
            ->orderBy('id')
            ->get();

        if ($applications->isEmpty()) {
            return $stats;
        }

        $today = now()->toDateString();
        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();

        foreach ($applications as $application) {
            $stats['checked']++;

            $lastLeaveDate = $this->resolveApplicationLastLeaveDate($application);
            if ($lastLeaveDate === null || $lastLeaveDate < $today) {
                $stats['skipped']++;
                continue;
            }

            $leaveType = $application->leaveType
                ?? LeaveType::query()->find((int) $application->leave_type_id);
            if (!$leaveType instanceof LeaveType) {
                $stats['failed']++;
                $stats['errors'][] = [
                    'application_id' => (int) $application->id,
                    'reason' => 'Leave type could not be resolved.',
                ];
                continue;
            }
            $application->setRelation('leaveType', $leaveType);

            $policyResolution = $this->applyRegularLeavePolicy(
                $leaveType,
                (float) ($application->total_days ?? 0),
                $application->resolvedSelectedDates(),
                is_array($application->selected_date_coverage) ? $application->selected_date_coverage : null,
                is_array($application->selected_date_pay_status) ? $application->selected_date_pay_status : null,
                $application->pay_mode ?? LeaveApplication::PAY_MODE_WITH_PAY,
                false,
                (bool) ($application->attachment_submitted ?? false),
                $this->trimNullableString($application->attachment_reference ?? null),
                false,
                $application->created_at ?? null,
                $application->start_date?->toDateString(),
                $application->end_date?->toDateString(),
                (string) ($application->employee_control_no ?? '')
            );

            if ($policyResolution instanceof JsonResponse) {
                $stats['failed']++;
                $stats['errors'][] = [
                    'application_id' => (int) $application->id,
                    'reason' => 'Policy resolution failed during repricing.',
                ];
                continue;
            }

            $targetPayMode = $policyResolution['pay_mode'];
            $targetSelectedDatePayStatus = $policyResolution['selected_date_pay_status'];
            $targetDeductibleDays = round(max((float) ($policyResolution['deductible_days'] ?? 0.0), 0.0), 2);
            $targetCtoDeductedHours = round(max((float) ($policyResolution['cto_deducted_hours'] ?? 0.0), 0.0), 2);

            $sourcePayMode = $this->normalizePayMode($application->pay_mode ?? null, false);
            $sourceDeductibleDays = $this->resolveApplicationDeductibleDays($application);
            $sourceCtoDeductedHours = $this->resolveApplicationCtoDeductedHours($application);
            $applicationStatus = strtoupper(trim((string) ($application->status ?? '')));
            $isPendingStatus = in_array($applicationStatus, [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
            ], true);
            $shouldReconcileBalances = $applicationStatus === LeaveApplication::STATUS_APPROVED
                || ($isPendingStatus && $this->hasPendingApprovedUpdateRequest($application));

            $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId((int) $application->leave_type_id)
                ?? (int) $application->leave_type_id;
            $requestedTotalDays = round((float) ($application->total_days ?? $sourceDeductibleDays), 2);

            $sourceDeductsBalance = $this->applicationDeductsEmployeeBalance(
                false,
                $leaveType,
                $sourcePayMode
            );
            $targetDeductsBalance = $this->applicationDeductsEmployeeBalance(
                false,
                $leaveType,
                $targetPayMode
            );

            $sourcePrimaryDeduction = $sourceDeductsBalance
                ? $this->resolveStoredPrimaryLeaveDeduction($application, $leaveType, $sourceDeductibleDays)
                : 0.0;
            $sourceLinkedForcedDeduction = $sourceDeductsBalance
                ? $this->resolveStoredLinkedForcedLeaveDeduction($application, $leaveType, $sourceDeductibleDays)
                : 0.0;
            $sourceLinkedVacationDeduction = $sourceDeductsBalance
                ? $this->resolveStoredLinkedVacationLeaveDeduction($application, $leaveType, $sourceDeductibleDays)
                : 0.0;

            $targetPrimaryDeduction = $targetDeductsBalance
                ? $this->resolvePrimaryLeaveTrackedDeductionDays(
                    $leaveType,
                    $canonicalLeaveTypeId,
                    false,
                    $requestedTotalDays,
                    $targetDeductibleDays,
                    $forcedLeaveTypeId,
                    $vacationLeaveTypeId
                )
                : 0.0;

            $targetLinkedForcedDeduction = ($targetDeductsBalance && $this->shouldLeaveTypeDeductForcedLeave(
                $leaveType,
                $canonicalLeaveTypeId,
                false,
                $forcedLeaveTypeId
            ))
                ? round(max($targetDeductibleDays, 0.0), 2)
                : 0.0;

            $targetLinkedVacationDeduction = $targetDeductsBalance
                ? $this->resolveLeaveTypeVacationLeaveDeductionDays(
                    $leaveType,
                    $canonicalLeaveTypeId,
                    false,
                    $requestedTotalDays,
                    $targetDeductibleDays,
                    $forcedLeaveTypeId,
                    $vacationLeaveTypeId
                )
                : 0.0;

            $deltaPrimaryDeduction = round($targetPrimaryDeduction - $sourcePrimaryDeduction, 2);
            $deltaLinkedForcedDeduction = round($targetLinkedForcedDeduction - $sourceLinkedForcedDeduction, 2);
            $deltaLinkedVacationDeduction = round($targetLinkedVacationDeduction - $sourceLinkedVacationDeduction, 2);

            if (abs($deltaPrimaryDeduction) < 0.01) {
                $deltaPrimaryDeduction = 0.0;
            }
            if (abs($deltaLinkedForcedDeduction) < 0.01) {
                $deltaLinkedForcedDeduction = 0.0;
            }
            if (abs($deltaLinkedVacationDeduction) < 0.01) {
                $deltaLinkedVacationDeduction = 0.0;
            }

            if (!$shouldReconcileBalances) {
                $deltaPrimaryDeduction = 0.0;
                $deltaLinkedForcedDeduction = 0.0;
                $deltaLinkedVacationDeduction = 0.0;
            }

            $targetStoredCtoDeductedHours = $this->hasLeaveApplicationCtoHoursColumn()
                && $this->isCtoLeaveType($leaveType, $canonicalLeaveTypeId)
                && $targetCtoDeductedHours > 0
                    ? $targetCtoDeductedHours
                    : null;

            $hasApplicationFieldChanges = $sourcePayMode !== $targetPayMode
                || round($sourceDeductibleDays, 2) !== $targetDeductibleDays
                || round($sourceCtoDeductedHours, 2) !== round(max((float) ($targetStoredCtoDeductedHours ?? 0.0), 0.0), 2)
                || (is_array($application->selected_date_pay_status) ? $application->selected_date_pay_status : null) !== $targetSelectedDatePayStatus
                || round(max((float) ($application->linked_forced_leave_deducted_days ?? 0.0), 0.0), 2) !== $targetLinkedForcedDeduction
                || round(max((float) ($application->linked_vacation_leave_deducted_days ?? 0.0), 0.0), 2) !== $targetLinkedVacationDeduction;

            if (
                !$hasApplicationFieldChanges
                && $deltaPrimaryDeduction === 0.0
                && $deltaLinkedForcedDeduction === 0.0
                && $deltaLinkedVacationDeduction === 0.0
            ) {
                $stats['skipped']++;
                continue;
            }

            $balanceConflictCode = 'HR_SCHEDULE_REPRICE_BALANCE_CONFLICT_' . (int) $application->id;

            try {
                DB::transaction(function () use (
                    $application,
                    $canonicalLeaveTypeId,
                    $forcedLeaveTypeId,
                    $vacationLeaveTypeId,
                    $deltaPrimaryDeduction,
                    $deltaLinkedForcedDeduction,
                    $deltaLinkedVacationDeduction,
                    $shouldReconcileBalances,
                    $balanceConflictCode,
                    $targetPayMode,
                    $targetSelectedDatePayStatus,
                    $targetDeductibleDays,
                    $targetStoredCtoDeductedHours,
                    $targetLinkedForcedDeduction,
                    $targetLinkedVacationDeduction,
                    $leaveType
                ): void {
                    if ($shouldReconcileBalances) {
                        $this->applyScheduleRepricingBalanceDelta(
                            $application,
                            $canonicalLeaveTypeId,
                            $deltaPrimaryDeduction,
                            $balanceConflictCode
                        );

                        if ($forcedLeaveTypeId !== null) {
                            $this->applyScheduleRepricingBalanceDelta(
                                $application,
                                $forcedLeaveTypeId,
                                $deltaLinkedForcedDeduction,
                                $balanceConflictCode
                            );
                        }

                        if ($vacationLeaveTypeId !== null) {
                            $this->applyScheduleRepricingBalanceDelta(
                                $application,
                                $vacationLeaveTypeId,
                                $deltaLinkedVacationDeduction,
                                $balanceConflictCode
                            );
                        }
                    }

                    $application->update([
                        'pay_mode' => $targetPayMode,
                        'selected_date_pay_status' => $targetSelectedDatePayStatus,
                        'deductible_days' => $targetDeductibleDays,
                        'cto_deducted_hours' => $targetStoredCtoDeductedHours,
                        'linked_forced_leave_deducted_days' => $targetLinkedForcedDeduction,
                        'linked_vacation_leave_deducted_days' => $targetLinkedVacationDeduction,
                    ]);

                    if ($application->employee_control_no && $this->isCtoLeaveType($leaveType, $canonicalLeaveTypeId)) {
                        $this->syncEmployeeCtoBalance((string) $application->employee_control_no, true);
                    }
                });
            } catch (\RuntimeException $exception) {
                if ($exception->getMessage() !== $balanceConflictCode) {
                    throw $exception;
                }

                $stats['failed']++;
                $stats['errors'][] = [
                    'application_id' => (int) $application->id,
                    'reason' => 'Insufficient balance while repricing leave.',
                ];
                continue;
            }

            $stats['repriced']++;
            $stats['updated_application_ids'][] = (int) $application->id;
        }

        return $stats;
    }

    private function applyScheduleRepricingBalanceDelta(
        LeaveApplication $application,
        int $leaveTypeId,
        float $deltaDays,
        string $balanceConflictCode
    ): void {
        $normalizedDeltaDays = round($deltaDays, 2);
        if ($leaveTypeId <= 0 || abs($normalizedDeltaDays) < 0.01) {
            return;
        }

        if ($application->employee_control_no) {
            $balance = $this->findPreferredEmployeeLeaveBalanceRecord(
                (string) $application->employee_control_no,
                $leaveTypeId,
                true
            );

            if (!$balance) {
                if ($normalizedDeltaDays > 0) {
                    throw new \RuntimeException($balanceConflictCode);
                }

                $canonicalControlNo = trim((string) (
                    $this->findEmployeeByControlNo((string) $application->employee_control_no)?->control_no
                    ?? $application->employee_control_no
                ));
                if ($canonicalControlNo === '') {
                    throw new \RuntimeException($balanceConflictCode);
                }

                $balance = LeaveBalance::query()->create([
                    'employee_control_no' => $canonicalControlNo,
                    'leave_type_id' => $leaveTypeId,
                    'balance' => 0,
                    'year' => (int) now()->year,
                ]);
            }

            if ($normalizedDeltaDays > 0) {
                if ((float) $balance->balance + 1e-9 < $normalizedDeltaDays) {
                    throw new \RuntimeException($balanceConflictCode);
                }

                $balance->decrement('balance', $normalizedDeltaDays);
                return;
            }

            $balance->increment('balance', abs($normalizedDeltaDays));
            return;
        }

        if ($application->applicant_admin_id) {
            $balance = $this->findAdminEmployeeLeaveBalance(
                (int) $application->applicant_admin_id,
                $leaveTypeId,
                true
            );

            if (!$balance) {
                if ($normalizedDeltaDays > 0) {
                    throw new \RuntimeException($balanceConflictCode);
                }

                return;
            }

            if ($normalizedDeltaDays > 0) {
                if ((float) $balance->balance + 1e-9 < $normalizedDeltaDays) {
                    throw new \RuntimeException($balanceConflictCode);
                }

                $balance->decrement('balance', $normalizedDeltaDays);
                return;
            }

            $balance->increment('balance', abs($normalizedDeltaDays));
        }
    }

    private function getApplicationLeaveBalanceSnapshot(LeaveApplication $app): array
    {
        static $balanceSnapshotCache = [];
        static $ctoSyncedControlNos = [];

        $lookupControlNo = $this->resolveApplicationBalanceLookupControlNo($app);
        if ($lookupControlNo === null) {
            return [];
        }

        if (!array_key_exists($lookupControlNo, $ctoSyncedControlNos)) {
            $this->syncEmployeeCtoBalance($lookupControlNo);
            $ctoSyncedControlNos[$lookupControlNo] = true;
        }

        if (array_key_exists($lookupControlNo, $balanceSnapshotCache)) {
            return $balanceSnapshotCache[$lookupControlNo];
        }

        $controlNoCandidates = $this->controlNoCandidates($lookupControlNo);
        if ($controlNoCandidates === []) {
            $balanceSnapshotCache[$lookupControlNo] = [];
            return [];
        }

        $snapshot = collect($this->mapLeaveBalancesByCanonicalTypeId(LeaveBalance::query()
            ->with('leaveType')
            ->whereIn('employee_control_no', $controlNoCandidates)
            ->get()))
            ->sortBy(fn(LeaveBalance $balance) => strtolower(trim((string) ($balance->leaveType?->name ?? ''))))
            ->values()
            ->map(fn(LeaveBalance $balance) => [
                'leave_type_id' => $this->resolveCanonicalLeaveTypeId((int) $balance->leave_type_id) ?? (int) $balance->leave_type_id,
                'leave_type_name' => LeaveType::canonicalizeLeaveTypeName($balance->leaveType?->name ?? 'Unknown') ?? 'Unknown',
                'balance' => (float) $balance->balance,
                'year' => $balance->year !== null ? (int) $balance->year : null,
                'updated_at' => $balance->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $balanceSnapshotCache[$lookupControlNo] = $snapshot;
        return $snapshot;
    }

    private function resolveApplicationBalanceLookupControlNo(LeaveApplication $app): ?string
    {
        $directControlNo = trim((string) ($app->employee_control_no ?? ''));
        if ($directControlNo !== '') {
            return $directControlNo;
        }

        $resolvedEmployee = $this->resolveApplicationEmployee($app);
        $employeeControlNo = trim((string) ($resolvedEmployee?->control_no ?? ''));
        return $employeeControlNo !== '' ? $employeeControlNo : null;
    }

    private function findLeaveTypeBalanceInSnapshot(array $snapshot, int $leaveTypeId): ?float
    {
        if ($leaveTypeId <= 0 || $snapshot === []) {
            return null;
        }

        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        foreach ($snapshot as $entry) {
            if ((int) ($entry['leave_type_id'] ?? 0) === $canonicalLeaveTypeId) {
                return (float) ($entry['balance'] ?? 0.0);
            }
        }

        return null;
    }

    private function findLeaveTypeBalanceByNameInSnapshot(array $snapshot, string $leaveTypeName): float
    {
        foreach ($snapshot as $entry) {
            if (strcasecmp((string) ($entry['leave_type_name'] ?? ''), $leaveTypeName) === 0) {
                return round(max((float) ($entry['balance'] ?? 0.0), 0.0), 2);
            }
        }

        return 0.0;
    }

    private function buildCertificationLeaveCredits(LeaveApplication $app, array $leaveBalanceSnapshot = []): array
    {
        $vacationCurrentBalance = $this->findLeaveTypeBalanceByNameInSnapshot($leaveBalanceSnapshot, 'Vacation Leave');
        $sickCurrentBalance = $this->findLeaveTypeBalanceByNameInSnapshot($leaveBalanceSnapshot, 'Sick Leave');
        $applicationStatus = strtoupper(trim((string) ($app->status ?? '')));
        $isPendingStatus = in_array($applicationStatus, [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ], true);
        $deductionAlreadyApplied = $applicationStatus === LeaveApplication::STATUS_APPROVED
            || ($isPendingStatus && $this->hasPendingApprovedUpdateRequest($app));

        $normalizedPayMode = $this->normalizePayMode($app->pay_mode ?? null, (bool) $app->is_monetization);
        $deductibleDays = $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY
            ? 0.0
            : round(max($this->resolveApplicationDeductibleDays($app), 0.0), 2);

        $leaveTypeName = trim((string) ($app->leaveType?->name ?? ''));
        $linkedVacationDeduction = round(max((float) ($app->linked_vacation_leave_deducted_days ?? 0.0), 0.0), 2);

        $vacationLessThisApplication = 0.0;
        $sickLessThisApplication = 0.0;

        if (strcasecmp($leaveTypeName, 'Vacation Leave') === 0) {
            $vacationLessThisApplication = $deductibleDays;
        } elseif (strcasecmp($leaveTypeName, 'Sick Leave') === 0) {
            $sickLessThisApplication = $deductibleDays;
        } elseif ($linkedVacationDeduction > 0.0) {
            $vacationLessThisApplication = $linkedVacationDeduction;
        }

        $vacationTotalEarned = $deductionAlreadyApplied
            ? round($vacationCurrentBalance + $vacationLessThisApplication, 2)
            : $vacationCurrentBalance;
        $sickTotalEarned = $deductionAlreadyApplied
            ? round($sickCurrentBalance + $sickLessThisApplication, 2)
            : $sickCurrentBalance;
        $vacationBalanceAfterApplication = $deductionAlreadyApplied
            ? $vacationCurrentBalance
            : round(max($vacationCurrentBalance - $vacationLessThisApplication, 0.0), 2);
        $sickBalanceAfterApplication = $deductionAlreadyApplied
            ? $sickCurrentBalance
            : round(max($sickCurrentBalance - $sickLessThisApplication, 0.0), 2);

        return [
            'vacation' => [
                'total_earned' => $vacationTotalEarned,
                'less_this_application' => $vacationLessThisApplication,
                'balance' => $vacationCurrentBalance,
                'balance_after_application' => $vacationBalanceAfterApplication,
            ],
            'sick' => [
                'total_earned' => $sickTotalEarned,
                'less_this_application' => $sickLessThisApplication,
                'balance' => $sickCurrentBalance,
                'balance_after_application' => $sickBalanceAfterApplication,
            ],
            'as_of_date' => $app->created_at?->format('F j, Y') ?? now()->format('F j, Y'),
        ];
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
}
