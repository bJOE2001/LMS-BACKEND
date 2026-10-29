<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\FiltersEmployeeControlNos;
use App\Http\Requests\HrLeaveApplicationIndexRequest;
use App\Http\Requests\HrVerifyLeaveApplicationDocumentRequest;
use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationUpdateRequest;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\Leave\LeaveCalculationService;
use App\Services\Leave\LeaveCertificationService;
use App\Services\Leave\LeaveCreditService;
use App\Services\Leave\LeaveEligibilityService;
use App\Services\Leave\LeaveWorkflowService;
use App\Services\LeaveApplicationDocumentVerificationService;
use App\Services\SmsGatewayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        return $this->leaveCreditService()->executeGetLeaveBalance(
            (string) $controlNo,
            $leaveTypeId,
            fn (string $no): ?object => $this->findEmployeeByControlNo($no),
            null,
            fn (string $no): array => $this->controlNoCandidates($no)
        );
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
        return $this->leaveCreditService()->handleErmsGetLeaveBalance(
            $request,
            $id,
            fn (string $no): ?object => $this->findEmployeeByControlNo($no),
            fn (string $no) => $this->assertErmsEmployeeIsPulled($no),
            null,
            fn (string $no): array => $this->employeeControlNoResponse($no),
            fn (string $no): array => $this->controlNoCandidates($no)
        );
    }

    /**
     * GET /erms/leave-balances/{controlNo}
     * Protected endpoint for loading all leave balances in one request.
     * Also returns the latest Vacation/Sick accrued credits for ERMS leave cards.
     */
    public function ermsGetLeaveBalances(Request $request, string $controlNo): JsonResponse
    {
        return $this->leaveCreditService()->executeErmsGetLeaveBalances(
            $controlNo,
            fn (string $no): ?object => $this->findEmployeeByControlNo($no),
            fn (string $no) => $this->assertErmsEmployeeIsPulled($no),
            fn (string $no): array => $this->employeeControlNoResponse($no),
            fn (string $no): array => $this->controlNoCandidates($no)
        );
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

        return $this->leaveWorkflowService()->executeErmsEmployeeAccess(
            $controlNo,
            fn (string $no): ?object => $this->findEmployeeByControlNo($no),
            fn (string $no): bool => $this->isPulledEmployeeControlNo($no),
            fn (string $no): bool => $this->isDepartmentAdminEmployeeControlNo($no),
            fn (string $no): array => $this->employeeControlNoResponse($no)
        );
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

        return $this->leaveWorkflowService()->executeErmsIndex(
            $controlNo,
            fn (string $no): ?object => $this->findEmployeeByControlNo($no),
            fn (string $no) => $this->assertErmsEmployeeIsPulled($no),
            fn ($query, string $no) => $this->applyApplicationOwnershipFilter($query, $no),
            fn ($applications): array => $this->buildWorkflowActorDirectory($applications),
            null,
            null,
            fn (LeaveApplication $app, array $actorDirectory = []): array => $this->formatErmsApplication($app, $actorDirectory),
            fn (string $no): array => $this->employeeControlNoResponse($no)
        );
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

        return $this->leaveWorkflowService()->executeErmsShow(
            $controlNo,
            $id,
            fn (string $no): ?object => $this->findEmployeeByControlNo($no),
            fn (string $no) => $this->assertErmsEmployeeIsPulled($no),
            fn ($query, string $no) => $this->applyApplicationOwnershipFilter($query, $no),
            fn ($applications): array => $this->buildWorkflowActorDirectory($applications),
            fn (LeaveApplication $application): array => $this->getApplicationLeaveBalanceSnapshot($application),
            fn (array $snapshot, int $typeId): ?array => $this->findLeaveTypeBalanceInSnapshot($snapshot, $typeId),
            fn (LeaveApplication $application, array $actorDirectory = []): array => $this->formatErmsApplication($application, $actorDirectory),
            fn (LeaveApplication $application, array $snapshot): ?array => $this->resolveCertificationLeaveCredits($application, $snapshot),
            fn (string $no): array => $this->employeeControlNoResponse($no)
        );
    }

    /**
     * POST /erms/apply-leave
     * Protected endpoint for ERMS-to-LMS leave application submission.
     */
    public function ermsStore(Request $request): JsonResponse
    {
        return $this->leaveWorkflowService()->executeErmsStore(
            $request,
            fn (string $value): array => $this->controlNoCandidates($value),
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (LeaveApplication $application): array => $this->formatApplication($application),
            fn (string $controlNo) => $this->assertErmsEmployeeIsPulled($controlNo)
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
        return $this->leaveWorkflowService()->handleErmsCancel(
            $request,
            $id,
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (string $controlNo) => $this->assertErmsEmployeeIsPulled($controlNo),
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
        return $this->leaveWorkflowService()->handleErmsRequestCancel(
            $request,
            $id,
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (string $controlNo) => $this->assertErmsEmployeeIsPulled($controlNo),
            fn (string $value): array => $this->controlNoCandidates($value),
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
        return $this->leaveWorkflowService()->handleErmsWithdrawRequest(
            $request,
            $id,
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (string $controlNo) => $this->assertErmsEmployeeIsPulled($controlNo),
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
        return $this->leaveWorkflowService()->handleErmsRequestUpdate(
            $request,
            $id,
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (string $controlNo) => $this->assertErmsEmployeeIsPulled($controlNo),
            fn (string $value): array => $this->controlNoCandidates($value),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    public function store(Request $request): JsonResponse
    {
        return $this->leaveWorkflowService()->executeStore(
            $request,
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
        return $this->leaveWorkflowService()->executeAdminViewAttachment(
            $request,
            $id,
            fn ($admin, $application): bool => $this->adminCanManageApplication($admin, $application)
        );
    }

    /**
     * Admin approves → status becomes PENDING_HR.
     */
    public function adminApprove(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->handleAdminApprove(
            $request,
            $id,
            fn ($admin, $app): bool => $this->adminCanManageApplication($admin, $app),
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    /**
     * Admin rejects → status becomes REJECTED.
     */
    public function adminReject(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->handleAdminReject(
            $request,
            $id,
            fn ($admin, $app): bool => $this->adminCanManageApplication($admin, $app),
            null,
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    public function adminRequestRecall(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->handleAdminRequestRecall(
            $request,
            $id,
            fn (string $controlNo): ?object => $this->findEmployeeByControlNo($controlNo),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    /**
     * Admin withdraws/cancels their pending recall request.
     * Application reverts back to APPROVED status.
     */
    public function adminCancelRecall(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->handleAdminCancelRecall(
            $request,
            $id,
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
        return $this->leaveWorkflowService()->handleExportReceivedApplications(
            $request,
            fn (LeaveApplication $app): array => $this->formatApplication($app)
        );
    }

    /**
     * Export Released Applications for HR module within a specific date range.
     */
    public function exportReleasedApplications(Request $request): JsonResponse
    {
        return $this->leaveWorkflowService()->handleExportReleasedApplications(
            $request,
            fn (LeaveApplication $app): array => $this->formatApplication($app)
        );
    }

    /**
     * List cancelled applications for HR module.
     * Supports tabs: 'ALL', 'APPROVED_CANCELLATION', 'EMPLOYEE_CANCELLED'.
     */
    public function hrCancelledApplications(Request $request): JsonResponse
    {
        return $this->leaveWorkflowService()->handleHrCancelledApplications(
            $request,
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn (LeaveApplication $app, array $actorDirectory) => $this->formatApplication($app, $actorDirectory)
        );
    }

    /**
     * Export Cancelled Applications for HR module within a specific date range / filter.
     */
    public function exportCancelledApplications(Request $request): JsonResponse
    {
        return $this->leaveWorkflowService()->handleExportCancelledApplications(
            $request,
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

    public function hrShow(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        return $this->leaveWorkflowService()->executeHrShow(
            $id,
            fn (array $applications): array => $this->buildWorkflowActorDirectory($applications),
            fn (LeaveApplication $application, array $actorDirectory = []): array => $this->formatErmsApplication($application, $actorDirectory),
            fn (LeaveApplication $application, array $actorDirectory = []): array => $this->formatApplication($application, $actorDirectory)
        );
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
        return $this->leaveWorkflowService()->executeHrViewAttachment($request, $id);
    }

    public function hrApplicationEditRequests(Request $request): JsonResponse
    {
        return $this->leaveWorkflowService()->handleHrApplicationEditRequests(
            $request,
            fn (LeaveApplicationUpdateRequest $editRequest): array => $this->formatHrApplicationEditRequest($editRequest)
        );
    }

    public function hrSaveApplicationEdit(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->handleHrSaveApplicationEdit(
            $request,
            $id,
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
        return $this->leaveWorkflowService()->handleHrApproveApplicationEditRequest(
            $request,
            $id,
            fn (string $controlNo): array => $this->controlNoCandidates($controlNo),
            fn (int $adminId): ?string => $this->findAdminEmployeeControlNo($adminId),
            fn (string $controlNo): ?object => $this->findEmployeeByControlNo($controlNo),
            fn (LeaveApplicationUpdateRequest $editRequest): array => $this->formatHrApplicationEditRequest($editRequest),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    public function hrRejectApplicationEditRequest(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->handleHrRejectApplicationEditRequest(
            $request,
            $id,
            fn (LeaveApplicationUpdateRequest $editRequest): array => $this->formatHrApplicationEditRequest($editRequest)
        );
    }

    /**
     * HR may override WP/WOP only during the initial CHRMO certification stage.
     */
    public function hrUpdatePayStatus(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->handleHrUpdatePayStatus(
            $request,
            $id,
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
            $validated
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
            $validated
        );
    }

    public function hrUndoReceive(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can undo received applications.'], 403);
        }

        return $this->leaveWorkflowService()->executeHrUndoReceive($id);
    }

    public function hrUndoRelease(Request $request, int $id): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can undo released applications.'], 403);
        }

        return $this->leaveWorkflowService()->executeHrUndoRelease($id);
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

        return $this->leaveWorkflowService()->executeHrCmoCbmoReview(
            $id,
            $hr,
            $request->input('remarks'),
            fn ($app) => $this->smsGatewayService()->sendLeaveReadyForReleaseMessage($app)
        );
    }

    /**
     * HR records batch/bulk CMO/CVMO review for multiple leave applications.
     */
    public function hrBulkCmoCbmoReview(Request $request): JsonResponse
    {
        return $this->leaveWorkflowService()->handleHrBulkCmoCbmoReview(
            $request,
            fn (LeaveApplication $app) => $this->smsGatewayService()->sendLeaveReadyForReleaseMessage($app),
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn ($app, $dir) => $this->formatErmsApplication($app, $dir)
        );
    }

    /**
     * HR confirms batch/bulk release of multiple leave applications.
     */
    public function hrBulkRelease(Request $request): JsonResponse
    {
        return $this->leaveWorkflowService()->handleHrBulkRelease(
            $request,
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn ($app, $dir) => $this->formatErmsApplication($app, $dir)
        );
    }

    /**
     * HR confirms receipt of the updated hard-copy leave application form
     * for the active approved-update request cycle.
     */
    public function hrReceiveUpdate(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->handleHrReceiveUpdate(
            $request,
            $id,
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn ($a, $dir) => $this->formatErmsApplication($a, $dir)
        );
    }

    /**
     * HR confirms release of the updated hard-copy leave application form
     * for the latest approved update-request cycle.
     */
    public function hrReleaseUpdate(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->handleHrReleaseUpdate(
            $request,
            $id,
            fn ($apps) => $this->buildWorkflowActorDirectory($apps),
            fn ($a, $dir) => $this->formatErmsApplication($a, $dir)
        );
    }

    /**
     * HR approves → status becomes APPROVED.
     * If leave type is credit-based OR is monetization, deduct from leave_balances inside a transaction.
     */
    public function hrApprove(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->handleHrApprove(
            $request,
            $id,
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
        return $this->leaveWorkflowService()->handleHrReject(
            $request,
            $id,
            fn (LeaveApplication $a) => $this->smsGatewayService()->sendLeaveRejectedMessage($a),
            fn ($freshApp) => $this->formatApplication($freshApp)
        );
    }

    /**
     * HR recalls an already approved leave application.
     */
    public function hrRecall(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->handleHrRecall(
            $request,
            $id,
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

        return $this->leaveWorkflowService()->executeAdminEmployees(
            $admin,
            fn ($hrisEmployees) => $this->buildLeaveBalanceLookupForEmployees($hrisEmployees)
        );
    }

    // ─── Admin: File leave on behalf of an employee ──────────────────

    /**
     * Admin submits a leave application on behalf of an employee in their
     * department. Skips admin-approval step → status goes to PENDING_HR.
     */
    public function adminStore(Request $request): JsonResponse
    {
        $admin = $request->user();
        if (! $admin instanceof DepartmentAdmin) {
            return response()->json(['message' => 'Only department admins can file leave on behalf of employees.'], 403);
        }

        return $this->leaveWorkflowService()->executeAdminStore(
            $request,
            $admin,
            fn (string $value): array => $this->controlNoCandidates($value),
            fn (string $value): ?object => $this->findEmployeeByControlNo($value),
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
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

    private function formatHrApplicationEditRequest(LeaveApplicationUpdateRequest $editRequest): array
    {
        return $this->leaveWorkflowService()->formatHrApplicationEditRequest(
            $editRequest,
            fn (LeaveApplication $application): array => $this->formatApplication($application)
        );
    }

    private function ermsStatusLabel(string $status): string
    {
        return $this->leaveWorkflowService()->ermsStatusLabel($status);
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

    private function buildWorkflowActorDirectory(iterable $applications): array
    {
        return $this->leaveWorkflowService()->buildWorkflowActorDirectory(
            $applications,
            fn ($app) => $this->resolveApplicationEmployee($app),
            fn ($val) => $this->normalizeControlNo($val)
        );
    }

    private function isCancelledRemark(mixed $remarks): bool
    {
        return $this->leaveWorkflowService()->isCancelledRemark($remarks);
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
        return $this->leaveWorkflowService()->hasPendingApprovedUpdateRequest($app);
    }

    private function isPendingApprovedUpdateRequest(LeaveApplication $app): bool
    {
        return $this->leaveWorkflowService()->isPendingApprovedUpdateRequest($app);
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

    /**
     * @return array<int, array{leave_type_id:int, leave_type_name:string, days:float}>
     */
    private function resolveStoredMonetizationLeaveCreditComponents(
        LeaveApplication $app,
        ?LeaveType $fallbackLeaveType = null
    ): array {
        return $this->leaveEligibilityService()->resolveStoredMonetizationLeaveCreditComponents($app, $fallbackLeaveType);
    }

    private function validateMonetizationRequestMinimum(float $requestedDays, string $errorKey = 'total_days'): ?JsonResponse
    {
        return $this->leaveEligibilityService()->validateMonetizationRequestMinimum($requestedDays, $errorKey);
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

    private function formatApplication(LeaveApplication $app, ?array $actorDirectory = null): array
    {
        return $this->leaveWorkflowService()->formatApplication(
            $app,
            $actorDirectory,
            fn (string $no): ?object => $this->findEmployeeByControlNo($no)
        );
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
        $this->leaveWorkflowService()->mergeEmployeeControlNoInput($request);
    }

    private function resolveValidatedEmployeeControlNo(array $validated): string
    {
        return $this->leaveWorkflowService()->resolveValidatedEmployeeControlNo($validated);
    }

    private function employeeControlNoResponse(?string $controlNo): array
    {
        return $this->leaveWorkflowService()->employeeControlNoResponse($controlNo);
    }

    /**
     * Log a print event for a leave application form (Sanctum auth).
     */
    public function logPrint(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->executeLogPrint($request, $id);
    }

    /**
     * Log a print event for a leave application form via ERMS.
     */
    public function ermsLogPrint(Request $request, int $id): JsonResponse
    {
        $this->mergeEmployeeControlNoInput($request);

        return $this->leaveWorkflowService()->executeErmsLogPrint($request, $id);
    }

    /**
     * Retrieve print logs for a leave application.
     */
    public function hrPrintLogs(Request $request, int $id): JsonResponse
    {
        return $this->leaveWorkflowService()->executeHrPrintLogs($id);
    }
}
