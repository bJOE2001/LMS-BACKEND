<?php

namespace Tests\Unit;

use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveApplicationUpdateRequest;
use App\Models\LeaveType;
use App\Services\Leave\LeaveWorkflowService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeaveWorkflowServiceTest extends TestCase
{
    private LeaveWorkflowService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new LeaveWorkflowService;
    }

    public function test_status_to_frontend(): void
    {
        $this->assertSame('Pending Admin', $this->service->statusToFrontend(LeaveApplication::STATUS_PENDING_ADMIN));
        $this->assertSame('Pending HR', $this->service->statusToFrontend(LeaveApplication::STATUS_PENDING_HR));
        $this->assertSame('Approved', $this->service->statusToFrontend(LeaveApplication::STATUS_APPROVED));
        $this->assertSame('Rejected', $this->service->statusToFrontend(LeaveApplication::STATUS_REJECTED));
        $this->assertSame('Recalled', $this->service->statusToFrontend(LeaveApplication::STATUS_RECALLED));
        $this->assertSame('CustomStatus', $this->service->statusToFrontend('CustomStatus'));
    }

    public function test_erms_status_label(): void
    {
        $this->assertSame('Pending', $this->service->ermsStatusLabel(LeaveApplication::STATUS_PENDING_ADMIN));
        $this->assertSame('Pending', $this->service->ermsStatusLabel(LeaveApplication::STATUS_PENDING_HR));
        $this->assertSame('Approved', $this->service->ermsStatusLabel(LeaveApplication::STATUS_APPROVED));
        $this->assertSame('Rejected', $this->service->ermsStatusLabel(LeaveApplication::STATUS_REJECTED));
        $this->assertSame('Recalled', $this->service->ermsStatusLabel(LeaveApplication::STATUS_RECALLED));
        $this->assertSame('Cancelled', $this->service->ermsStatusLabel(LeaveApplication::STATUS_CANCELLED));
        $this->assertSame('Pending', $this->service->ermsStatusLabel('UNKNOWN'));
    }

    public function test_format_days(): void
    {
        $this->assertSame('1 day', LeaveWorkflowService::formatDays(1));
        $this->assertSame('1 day', LeaveWorkflowService::formatDays(1.0));
        $this->assertSame('2 days', LeaveWorkflowService::formatDays(2.0));
        $this->assertSame('0.5 days', LeaveWorkflowService::formatDays(0.5));
        $this->assertSame('3.5 days', LeaveWorkflowService::formatDays(3.5));
    }

    public function test_is_cancelled_remark(): void
    {
        $this->assertTrue($this->service->isCancelledRemark('cancelled'));
        $this->assertTrue($this->service->isCancelledRemark('Cancelled by user'));
        $this->assertTrue($this->service->isCancelledRemark('cancelled via admin'));
        $this->assertFalse($this->service->isCancelledRemark('rejected'));
        $this->assertFalse($this->service->isCancelledRemark(''));
        $this->assertFalse($this->service->isCancelledRemark(null));
    }

    public function test_admin_reject_status_for_remarks(): void
    {
        $this->assertSame(LeaveApplication::STATUS_CANCELLED, $this->service->adminRejectStatusForRemarks('cancelled by admin'));
        $this->assertSame(LeaveApplication::STATUS_REJECTED, $this->service->adminRejectStatusForRemarks('invalid supporting documents'));
    }

    public function test_admin_reject_log_action_for_remarks(): void
    {
        $this->assertSame(LeaveApplicationLog::ACTION_ADMIN_CANCELLED, $this->service->adminRejectLogActionForRemarks('cancelled by admin'));
        $this->assertSame(LeaveApplicationLog::ACTION_ADMIN_REJECTED, $this->service->adminRejectLogActionForRemarks('rejected reason'));
    }

    public function test_is_admin_cancellation_log(): void
    {
        $cancelledLog = new LeaveApplicationLog;
        $cancelledLog->action = LeaveApplicationLog::ACTION_ADMIN_CANCELLED;
        $cancelledLog->performed_by_type = LeaveApplicationLog::PERFORMER_ADMIN;
        $this->assertTrue($this->service->isAdminCancellationLog($cancelledLog));

        $rejectedWithCancelRemark = new LeaveApplicationLog;
        $rejectedWithCancelRemark->action = LeaveApplicationLog::ACTION_ADMIN_REJECTED;
        $rejectedWithCancelRemark->performed_by_type = LeaveApplicationLog::PERFORMER_ADMIN;
        $rejectedWithCancelRemark->remarks = 'cancelled upon request';
        $this->assertTrue($this->service->isAdminCancellationLog($rejectedWithCancelRemark));

        $regularRejectedLog = new LeaveApplicationLog;
        $regularRejectedLog->action = LeaveApplicationLog::ACTION_ADMIN_REJECTED;
        $regularRejectedLog->performed_by_type = LeaveApplicationLog::PERFORMER_ADMIN;
        $regularRejectedLog->remarks = 'insufficient balance';
        $this->assertFalse($this->service->isAdminCancellationLog($regularRejectedLog));
    }

    public function test_is_employee_cancellation_log(): void
    {
        $empCancelLog = new LeaveApplicationLog;
        $empCancelLog->action = LeaveApplicationLog::ACTION_EMPLOYEE_CANCELLED;
        $empCancelLog->performed_by_type = LeaveApplicationLog::PERFORMER_EMPLOYEE;
        $this->assertTrue($this->service->isEmployeeCancellationLog($empCancelLog));

        $otherLog = new LeaveApplicationLog;
        $otherLog->action = LeaveApplicationLog::ACTION_ADMIN_APPROVED;
        $otherLog->performed_by_type = LeaveApplicationLog::PERFORMER_ADMIN;
        $this->assertFalse($this->service->isEmployeeCancellationLog($otherLog));
    }

    public function test_extract_cancellation_reason(): void
    {
        $this->assertSame('Personal emergency', $this->service->extractCancellationReason('Cancelled: Personal emergency'));
        $this->assertSame('No longer needed', $this->service->extractCancellationReason('cancelled via portal: No longer needed'));
        $this->assertNull($this->service->extractCancellationReason('Regular approval remark'));
        $this->assertNull($this->service->extractCancellationReason(null));
    }

    public function test_map_workflow_log_stage(): void
    {
        $submitLog = new LeaveApplicationLog;
        $submitLog->action = LeaveApplicationLog::ACTION_SUBMITTED;
        $submitLog->performed_by_type = LeaveApplicationLog::PERFORMER_EMPLOYEE;
        $this->assertSame('employee submitted', $this->service->mapWorkflowLogStage($submitLog));

        $adminApproveLog = new LeaveApplicationLog;
        $adminApproveLog->action = LeaveApplicationLog::ACTION_ADMIN_APPROVED;
        $adminApproveLog->performed_by_type = LeaveApplicationLog::PERFORMER_ADMIN;
        $this->assertSame('department admin approved', $this->service->mapWorkflowLogStage($adminApproveLog));

        $hrApproveLog = new LeaveApplicationLog;
        $hrApproveLog->action = LeaveApplicationLog::ACTION_HR_APPROVED;
        $hrApproveLog->performed_by_type = LeaveApplicationLog::PERFORMER_HR;
        $this->assertSame('hr approved', $this->service->mapWorkflowLogStage($hrApproveLog));
    }

    public function test_tokenize_and_normalize_hr_application_search(): void
    {
        $tokens = $this->service->tokenizeHrApplicationSearch('  Vacation   Leave   2026 ');
        $this->assertSame(['vacation', 'leave', '2026'], $tokens);

        $this->assertSame([], $this->service->tokenizeHrApplicationSearch('   '));
        $this->assertSame([], $this->service->tokenizeHrApplicationSearch(null));
    }

    public function test_hr_queue_stage_search_label(): void
    {
        $this->assertSame('Department Recommendation', $this->service->hrQueueStageSearchLabel('PENDING_ADMIN'));
        $this->assertSame('Pending Receive Pending Update Receive', $this->service->hrQueueStageSearchLabel('PENDING_HR_RECEIVE'));
        $this->assertSame('CHRMO Certification Pending Update Review', $this->service->hrQueueStageSearchLabel('PENDING_HR_REVIEW'));
        $this->assertSame('CMO CVMO Review', $this->service->hrQueueStageSearchLabel('PENDING_CMO_CBMO_REVIEW'));
        $this->assertSame('Pending Release Pending Update Release', $this->service->hrQueueStageSearchLabel('PENDING_RELEASE'));
        $this->assertSame('', $this->service->hrQueueStageSearchLabel('UNKNOWN'));
    }

    public function test_resolve_leave_hr_queue_meta(): void
    {
        $pendingHrApp = new LeaveApplication;
        $pendingHrApp->status = LeaveApplication::STATUS_PENDING_HR;
        $pendingHrApp->created_at = Carbon::parse('2026-02-01 08:00:00');

        $metaNotReceived = $this->service->resolveLeaveHrQueueMeta(
            $pendingHrApp,
            hasHrReceivedResolver: fn () => false
        );
        $this->assertSame(LeaveWorkflowService::QUEUE_GROUP_PENDING, $metaNotReceived['group_status']);
        $this->assertSame('PENDING_HR_RECEIVE', $metaNotReceived['stage_key']);

        $metaReceived = $this->service->resolveLeaveHrQueueMeta(
            $pendingHrApp,
            hasHrReceivedResolver: fn () => true
        );
        $this->assertSame('PENDING_HR_REVIEW', $metaReceived['stage_key']);

        $approvedApp = new LeaveApplication;
        $approvedApp->status = LeaveApplication::STATUS_APPROVED;

        $metaApprovedReleased = $this->service->resolveLeaveHrQueueMeta(
            $approvedApp,
            hasHrReleasedResolver: fn () => true
        );
        $this->assertSame(LeaveWorkflowService::QUEUE_GROUP_APPROVED, $metaApprovedReleased['group_status']);
    }

    public function test_normalize_requested_leave_action_type_token(): void
    {
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL, $this->service->normalizeRequestedLeaveActionTypeToken('RECALL'));
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL, $this->service->normalizeRequestedLeaveActionTypeToken('recall_request'));
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL, $this->service->normalizeRequestedLeaveActionTypeToken('cancel'));
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL, $this->service->normalizeRequestedLeaveActionTypeToken('REQUEST_CANCELLATION'));
        $this->assertSame(LeaveWorkflowService::HR_APPLICATION_EDIT_REQUEST_KIND, $this->service->normalizeRequestedLeaveActionTypeToken('hr_edit'));
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE, $this->service->normalizeRequestedLeaveActionTypeToken('update'));
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE, $this->service->normalizeRequestedLeaveActionTypeToken('something_else'));
    }

    public function test_resolve_update_request_action_type_from_payload(): void
    {
        $this->assertSame(
            LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL,
            $this->service->resolveUpdateRequestActionTypeFromPayload(['cancel_leave' => true])
        );
        $this->assertSame(
            LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL,
            $this->service->resolveUpdateRequestActionTypeFromPayload(['request_cancel' => '1'])
        );
        $this->assertSame(
            LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL,
            $this->service->resolveUpdateRequestActionTypeFromPayload(['recall_leave' => true])
        );
        $this->assertSame(
            LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL,
            $this->service->resolveUpdateRequestActionTypeFromPayload(['request_recall' => 1])
        );
        $this->assertSame(
            LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL,
            $this->service->resolveUpdateRequestActionTypeFromPayload(json_encode(['action_type' => 'RECALL']))
        );
        $this->assertSame(
            LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE,
            $this->service->resolveUpdateRequestActionTypeFromPayload(['action_type' => 'REQUEST_UPDATE'])
        );
        $this->assertSame(
            LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE,
            $this->service->resolveUpdateRequestActionTypeFromPayload(null)
        );
    }

    public function test_with_requested_leave_action_type(): void
    {
        $payload = ['existing_field' => 'value'];

        $cancelPayload = $this->service->withRequestedLeaveActionType($payload, 'cancel');
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL, $cancelPayload['action_type']);
        $this->assertSame('cancel', $cancelPayload['request_kind']);
        $this->assertTrue($cancelPayload['cancel_leave']);
        $this->assertFalse($cancelPayload['recall_leave']);

        $recallPayload = $this->service->withRequestedLeaveActionType($payload, 'recall');
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL, $recallPayload['action_type']);
        $this->assertSame('recall', $recallPayload['request_kind']);
        $this->assertFalse($recallPayload['cancel_leave']);
        $this->assertTrue($recallPayload['recall_leave']);

        $updatePayload = $this->service->withRequestedLeaveActionType($payload, 'update');
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE, $updatePayload['action_type']);
        $this->assertSame('update', $updatePayload['request_kind']);
        $this->assertFalse($updatePayload['cancel_leave']);
        $this->assertFalse($updatePayload['recall_leave']);
    }

    public function test_clean_request_lines_from_remarks(): void
    {
        $remarks = "Cancellation request submitted by user\nReason for leave: Personal matters\nUpdate request submitted on 2026-03-01";
        $cleaned = $this->service->cleanRequestLinesFromRemarks($remarks);
        $this->assertSame('Reason for leave: Personal matters', $cleaned);

        $this->assertNull($this->service->cleanRequestLinesFromRemarks("Cancellation request submitted\nUpdate request submitted"));
        $this->assertNull($this->service->cleanRequestLinesFromRemarks(''));
        $this->assertNull($this->service->cleanRequestLinesFromRemarks(null));
    }

    public function test_normalize_recall_date_keys(): void
    {
        $raw = ['2026-03-05', '2026-03-01', '2026-03-05', '  2026-03-02  ', ''];
        $normalized = $this->service->normalizeRecallDateKeys($raw);
        $this->assertSame(['2026-03-01', '2026-03-02', '2026-03-05'], $normalized);

        $custom = $this->service->normalizeRecallDateKeys(['March 1, 2026'], fn ($d) => '2026-03-01');
        $this->assertSame(['2026-03-01'], $custom);
    }

    public function test_has_pending_approved_update_request_and_presentation(): void
    {
        $app = new LeaveApplication;
        $app->status = LeaveApplication::STATUS_PENDING_HR;

        $hasApprovedUpdate = $this->service->hasPendingApprovedUpdateRequest(
            $app,
            pendingUpdateMetaResolver: fn () => [
                'payload' => ['some' => 'change'],
                'previous_status' => LeaveApplication::STATUS_APPROVED,
            ]
        );
        $this->assertTrue($hasApprovedUpdate);

        $shouldPresent = $this->service->shouldPresentApprovedWhilePendingUpdate(
            $app,
            $hasApprovedUpdate,
            pendingUpdateMetaResolver: fn () => [
                'previous_status' => LeaveApplication::STATUS_APPROVED,
            ]
        );
        $this->assertTrue($shouldPresent);

        $hasNoPayload = $this->service->hasPendingApprovedUpdateRequest(
            $app,
            pendingUpdateMetaResolver: fn () => [
                'payload' => null,
                'previous_status' => LeaveApplication::STATUS_APPROVED,
            ]
        );
        $this->assertFalse($hasNoPayload);
    }

    public function test_resolve_pending_and_latest_approved_update_action_type(): void
    {
        $app = new LeaveApplication;

        $pendingAction = $this->service->resolvePendingApprovedUpdateActionType(
            $app,
            pendingUpdateMetaResolver: fn () => [
                'payload' => ['recall_leave' => true],
            ]
        );
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL, $pendingAction);

        $latestActionApproved = $this->service->resolveLatestApprovedUpdateActionType(
            $app,
            latestUpdateMetaResolver: fn () => [
                'status' => LeaveApplicationUpdateRequest::STATUS_APPROVED,
                'previous_status' => LeaveApplication::STATUS_APPROVED,
                'payload' => ['cancel_leave' => true],
            ]
        );
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL, $latestActionApproved);

        $latestActionRejected = $this->service->resolveLatestApprovedUpdateActionType(
            $app,
            latestUpdateMetaResolver: fn () => [
                'status' => LeaveApplicationUpdateRequest::STATUS_REJECTED,
                'previous_status' => LeaveApplication::STATUS_APPROVED,
                'payload' => ['cancel_leave' => true],
            ]
        );
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE, $latestActionRejected);
    }

    public function test_resolve_current_hr_document_cycle_requested_at(): void
    {
        $app = new LeaveApplication;
        $app->status = LeaveApplication::STATUS_PENDING_HR;
        $requestedAtPending = Carbon::parse('2026-03-01 10:00:00');
        $requestedAtLatest = Carbon::parse('2026-02-15 09:00:00');

        $cycleRequestedAt = $this->service->resolveCurrentHrDocumentCycleRequestedAt(
            $app,
            pendingUpdateMetaResolver: fn () => [
                'payload' => ['reason' => 'change'],
                'previous_status' => LeaveApplication::STATUS_APPROVED,
                'requested_at' => $requestedAtPending,
            ],
            latestUpdateMetaResolver: fn () => [
                'status' => LeaveApplicationUpdateRequest::STATUS_APPROVED,
                'previous_status' => LeaveApplication::STATUS_APPROVED,
                'requested_at' => $requestedAtLatest,
            ]
        );

        $this->assertSame($requestedAtPending, $cycleRequestedAt);
    }

    public function test_format_employee_full_name(): void
    {
        $employee = (object) [
            'firstname' => 'Juan',
            'middlename' => 'Protacio',
            'surname' => 'Dela Cruz',
            'suffix' => 'Jr.',
        ];

        $this->assertSame('Juan Protacio Dela Cruz Jr.', LeaveWorkflowService::formatEmployeeFullName($employee));

        $employeeWithoutMiddle = (object) [
            'firstname' => 'Maria',
            'surname' => 'Santos',
        ];
        $this->assertSame('Maria Santos', LeaveWorkflowService::formatEmployeeFullName($employeeWithoutMiddle));

        $this->assertSame('', LeaveWorkflowService::formatEmployeeFullName(null));
    }

    public function test_resolve_workflow_performer_name(): void
    {
        $actorDirectory = [
            'admin' => [1 => 'Admin Officer'],
            'hr' => [2 => 'HR Specialist'],
            'employee' => ['1001' => 'Juan Dela Cruz'],
        ];

        $adminLog = new LeaveApplicationLog;
        $adminLog->performed_by_type = LeaveApplicationLog::PERFORMER_ADMIN;
        $adminLog->performed_by_id = 1;

        $this->assertSame('Admin Officer', $this->service->resolveWorkflowPerformerName($adminLog, $actorDirectory));

        $hrLog = new LeaveApplicationLog;
        $hrLog->performed_by_type = LeaveApplicationLog::PERFORMER_HR;
        $hrLog->performed_by_id = 2;

        $this->assertSame('HR Specialist', $this->service->resolveWorkflowPerformerName($hrLog, $actorDirectory));

        $empLog = new LeaveApplicationLog;
        $empLog->performed_by_type = LeaveApplicationLog::PERFORMER_EMPLOYEE;
        $empLog->performed_by_id = 1001;

        $this->assertSame('Juan Dela Cruz', $this->service->resolveWorkflowPerformerName($empLog, $actorDirectory, controlNoNormalizer: fn ($v) => (string) $v));
        $this->assertNull($this->service->resolveWorkflowPerformerName(null, $actorDirectory));
    }

    public function test_build_workflow_status_history(): void
    {
        $log = new LeaveApplicationLog;
        $log->id = 10;
        $log->leave_application_id = 50;
        $log->action = LeaveApplicationLog::ACTION_ADMIN_APPROVED;
        $log->performed_by_type = LeaveApplicationLog::PERFORMER_ADMIN;
        $log->performed_by_id = 1;
        $log->remarks = 'Approved by admin';
        $log->created_at = Carbon::parse('2026-03-01 12:00:00');

        $actorDirectory = [
            'admin' => [1 => 'Department Admin User'],
            'hr' => [],
            'employee' => [],
        ];

        $history = $this->service->buildWorkflowStatusHistory([$log], $actorDirectory);

        $this->assertCount(1, $history);
        $this->assertSame(10, $history[0]['id']);
        $this->assertSame('Department Admin User', $history[0]['actor_name']);
        $this->assertSame('Approved by admin', $history[0]['remarks']);
    }

    public function test_resolve_application_milestone_logs(): void
    {
        $app = new LeaveApplication;

        $submitLog = new LeaveApplicationLog;
        $submitLog->action = LeaveApplicationLog::ACTION_SUBMITTED;
        $submitLog->performed_by_type = LeaveApplicationLog::PERFORMER_EMPLOYEE;
        $submitLog->created_at = Carbon::parse('2026-03-01 08:00:00');

        $adminApproveLog = new LeaveApplicationLog;
        $adminApproveLog->action = LeaveApplicationLog::ACTION_ADMIN_APPROVED;
        $adminApproveLog->performed_by_type = LeaveApplicationLog::PERFORMER_ADMIN;
        $adminApproveLog->created_at = Carbon::parse('2026-03-01 09:00:00');

        $hrReceivedLog = new LeaveApplicationLog;
        $hrReceivedLog->action = LeaveApplicationLog::ACTION_HR_RECEIVED;
        $hrReceivedLog->performed_by_type = LeaveApplicationLog::PERFORMER_HR;
        $hrReceivedLog->created_at = Carbon::parse('2026-03-01 10:00:00');

        $app->setRelation('logs', collect([$submitLog, $adminApproveLog, $hrReceivedLog]));

        $milestones = $this->service->resolveApplicationMilestoneLogs($app);

        $this->assertSame($submitLog, $milestones['submitted']);
        $this->assertSame($adminApproveLog, $milestones['admin_approved']);
        $this->assertSame($hrReceivedLog, $milestones['hr_received']);
        $this->assertNull($milestones['hr_approved']);
        $this->assertNull($milestones['admin_rejected']);
        $this->assertNull($milestones['cancelled']);
    }

    public function test_confirm_hard_copy_receipt_and_release_flow(): void
    {
        $app = new LeaveApplication;
        $app->status = LeaveApplication::STATUS_APPROVED;
        $app->setRelation('logs', collect());

        $hr = new HRAccount;
        $hr->id = 5;

        // Release fails before receipt
        $releaseResult = $this->service->confirmHardCopyRelease($app, $hr);
        $this->assertFalse($releaseResult['ok']);
        $this->assertSame('Cannot mark as released before confirming receipt of the hard-copy application.', $releaseResult['message']);

        // Receipt log
        $receivedLog = new LeaveApplicationLog;
        $receivedLog->action = LeaveApplicationLog::ACTION_HR_RECEIVED;
        $receivedLog->performed_by_type = LeaveApplicationLog::PERFORMER_HR;
        $app->setRelation('logs', collect([$receivedLog]));

        // Release fails before CMO/CVMO review
        $releaseResultAfterReceipt = $this->service->confirmHardCopyRelease($app, $hr);
        $this->assertFalse($releaseResultAfterReceipt['ok']);
        $this->assertSame('Cannot mark as released before completing CMO/CVMO review.', $releaseResultAfterReceipt['message']);

        // Already released case
        $releasedLog = new LeaveApplicationLog;
        $releasedLog->action = LeaveApplicationLog::ACTION_HR_RELEASED;
        $releasedLog->performed_by_type = LeaveApplicationLog::PERFORMER_HR;
        $app->setRelation('logs', collect([$receivedLog, $releasedLog]));

        $releaseResultAlreadyReleased = $this->service->confirmHardCopyRelease($app, $hr);
        $this->assertTrue($releaseResultAlreadyReleased['ok']);
        $this->assertTrue($releaseResultAlreadyReleased['already_released']);
    }

    public function test_undo_hard_copy_receipt_validation(): void
    {
        $app = new LeaveApplication;
        $app->status = LeaveApplication::STATUS_APPROVED;

        $releasedLog = new LeaveApplicationLog;
        $releasedLog->action = LeaveApplicationLog::ACTION_HR_RELEASED;
        $releasedLog->performed_by_type = LeaveApplicationLog::PERFORMER_HR;

        $app->setRelation('logs', collect([$releasedLog]));

        $undoResultWithRelease = $this->service->undoHardCopyReceipt($app);
        $this->assertFalse($undoResultWithRelease['ok']);
        $this->assertSame('Cannot undo receipt after release. Undo the release first.', $undoResultWithRelease['message']);

        $reviewedLog = new LeaveApplicationLog;
        $reviewedLog->action = LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED;
        $reviewedLog->performed_by_type = LeaveApplicationLog::PERFORMER_HR;

        $app->setRelation('logs', collect([$reviewedLog]));

        $undoResultWithReview = $this->service->undoHardCopyReceipt($app);
        $this->assertFalse($undoResultWithReview['ok']);
        $this->assertSame('Cannot undo receipt after CMO/CVMO review has been completed.', $undoResultWithReview['message']);
    }

    public function test_confirm_cmo_cbmo_review_validation(): void
    {
        $app = new LeaveApplication;
        $app->status = LeaveApplication::STATUS_PENDING_HR;
        $app->setRelation('logs', collect());

        $hr = new HRAccount;
        $hr->id = 5;

        $reviewResultNotApproved = $this->service->confirmCmoCbmoReview($app, $hr);
        $this->assertFalse($reviewResultNotApproved['ok']);
        $this->assertStringContainsString('Cannot complete CMO/CVMO review: application status is', $reviewResultNotApproved['message']);

        $app->status = LeaveApplication::STATUS_APPROVED;
        $reviewResultNotReceived = $this->service->confirmCmoCbmoReview($app, $hr);
        $this->assertFalse($reviewResultNotReceived['ok']);
        $this->assertSame('Cannot complete CMO/CVMO review before confirming receipt of the hard-copy application.', $reviewResultNotReceived['message']);
    }

    public function test_build_requested_leave_cancellation_payload(): void
    {
        $app = new LeaveApplication;
        $app->leave_type_id = 2;
        $app->start_date = Carbon::parse('2026-04-01');
        $app->end_date = Carbon::parse('2026-04-03');
        $app->total_days = 3;
        $app->deductible_days = 3;
        $app->pay_mode = 'WITH_PAY';
        $app->is_monetization = false;

        $payload = $this->service->buildRequestedLeaveCancellationPayload(
            $app,
            'Medical emergency',
            canonicalLeaveTypeIdResolver: fn ($id) => $id,
            deductibleDaysResolver: fn ($a) => 3.0,
            payModeNormalizer: fn ($mode, $mono) => $mode
        );

        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL, $payload['action_type']);
        $this->assertSame('cancel', $payload['request_kind']);
        $this->assertTrue($payload['cancel_leave']);
        $this->assertSame('Medical emergency', $payload['cancel_reason']);
        $this->assertSame(2, $payload['leave_type_id']);
    }

    public function test_build_requested_leave_recall_payload(): void
    {
        $app = new LeaveApplication;
        $app->leave_type_id = 1;
        $app->total_days = 5;
        $app->deductible_days = 5;
        $app->pay_mode = 'WITH_PAY';
        $app->is_monetization = false;

        $payload = $this->service->buildRequestedLeaveRecallPayload(
            $app,
            'Official mission recall',
            ['2026-04-02', '2026-04-01'],
            canonicalLeaveTypeIdResolver: fn ($id) => $id,
            deductibleDaysResolver: fn ($a) => 5.0,
            payModeNormalizer: fn ($mode, $mono) => $mode,
            recallDateKeyNormalizer: fn ($dates) => ['2026-04-01', '2026-04-02']
        );

        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL, $payload['action_type']);
        $this->assertSame('recall', $payload['request_kind']);
        $this->assertTrue($payload['recall_leave']);
        $this->assertSame('Official mission recall', $payload['recall_reason']);
        $this->assertSame(['2026-04-01', '2026-04-02'], $payload['recall_selected_dates']);
    }

    public function test_get_approved_cancellation_condition_and_base_query(): void
    {
        $condition = $this->service->getApprovedCancellationCondition();
        $this->assertInstanceOf(\Closure::class, $condition);

        $query = $this->service->buildCancelledApplicationsBaseQuery(
            ['test'],
            'Accounting',
            '2026-01-01',
            '2026-01-31'
        );
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $query);
    }

    public function test_has_hr_received_released_cmo_for_queue(): void
    {
        $app = new LeaveApplication;
        $app->status = LeaveApplication::STATUS_APPROVED;

        $receivedLog = new LeaveApplicationLog;
        $receivedLog->action = LeaveApplicationLog::ACTION_HR_RECEIVED;
        $receivedLog->performed_by_type = LeaveApplicationLog::PERFORMER_HR;

        $releasedLog = new LeaveApplicationLog;
        $releasedLog->action = LeaveApplicationLog::ACTION_HR_RELEASED;
        $releasedLog->performed_by_type = LeaveApplicationLog::PERFORMER_HR;

        $cmoLog = new LeaveApplicationLog;
        $cmoLog->action = LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED;
        $cmoLog->performed_by_type = LeaveApplicationLog::PERFORMER_HR;

        $app->setRelation('logs', collect([$receivedLog, $releasedLog, $cmoLog]));

        $this->assertTrue($this->service->hasHrReceivedForQueue($app));
        $this->assertTrue($this->service->hasHrReleasedForQueue($app));
        $this->assertTrue($this->service->hasCmoCbmoReviewedForQueue($app));
    }

    public function test_matches_hr_application_filters(): void
    {
        $app = new LeaveApplication;
        $app->id = 99;
        $app->employee_control_no = '12345';
        $app->employee_name = 'Juan Dela Cruz';
        $app->status = LeaveApplication::STATUS_PENDING_HR;
        $app->total_days = 2;
        $app->setRelation('logs', collect());

        $this->assertTrue($this->service->matchesHrApplicationFilters($app, [], ''));
        $this->assertTrue($this->service->matchesHrApplicationFilters($app, ['juan'], ''));
        $this->assertTrue($this->service->matchesHrApplicationFilters($app, ['12345'], ''));
        $this->assertFalse($this->service->matchesHrApplicationFilters($app, ['nonexistent'], ''));
    }

    public function test_resolve_latest_recall_logged_at(): void
    {
        $app = new LeaveApplication;
        $app->setRelation('logs', collect());
        $this->assertNull($this->service->resolveLatestRecallLoggedAt($app));

        $log1 = new LeaveApplicationLog;
        $log1->action = LeaveApplicationLog::ACTION_HR_RECALLED;
        $log1->created_at = Carbon::parse('2026-03-01 10:00:00');

        $log2 = new LeaveApplicationLog;
        $log2->action = LeaveApplicationLog::ACTION_HR_RECALLED;
        $log2->created_at = Carbon::parse('2026-03-05 15:00:00');

        $logOther = new LeaveApplicationLog;
        $logOther->action = LeaveApplicationLog::ACTION_HR_APPROVED;
        $logOther->created_at = Carbon::parse('2026-03-06 10:00:00');

        $app->setRelation('logs', collect([$log1, $log2, $logOther]));

        $latestRecallAt = $this->service->resolveLatestRecallLoggedAt($app);
        $this->assertNotNull($latestRecallAt);
        $this->assertSame('2026-03-05 15:00:00', $latestRecallAt->toDateTimeString());
    }

    public function test_resolve_effective_stored_recall_date_keys(): void
    {
        $app = new LeaveApplication;
        $app->recall_selected_dates = ['2026-03-10', '2026-03-11'];
        $app->selected_dates = ['2026-03-10', '2026-03-11', '2026-03-12'];
        $app->setRelation('logs', collect());

        $effectiveKeys = $this->service->resolveEffectiveStoredRecallDateKeys($app);
        $this->assertEquals(['2026-03-10', '2026-03-11'], $effectiveKeys);
    }

    public function test_resolve_recall_selected_date_keys_and_validated(): void
    {
        $app = new LeaveApplication;
        $app->selected_dates = ['2026-03-10', '2026-03-11', '2026-03-12'];
        $app->recall_selected_dates = ['2026-03-10'];
        $app->setRelation('logs', collect());

        $availableForRecall = $this->service->resolveRecallSelectedDateKeys($app);
        $this->assertEquals(['2026-03-11', '2026-03-12'], $availableForRecall);

        // Validated requested dates that match allowed
        $valid = $this->service->resolveValidatedRecallSelectedDateKeys($app, ['2026-03-11']);
        $this->assertEquals(['2026-03-11'], $valid);

        // Invalid: requested date not in allowed (2026-03-10 was already recalled)
        $invalid = $this->service->resolveValidatedRecallSelectedDateKeys($app, ['2026-03-10']);
        $this->assertNull($invalid);

        // Invalid: empty requested
        $empty = $this->service->resolveValidatedRecallSelectedDateKeys($app, []);
        $this->assertNull($empty);
    }

    public function test_execute_hr_recall_validation_failures(): void
    {
        $hr = new HRAccount;
        $hr->id = 1;

        // Monetization cannot be recalled
        $appMonetization = new LeaveApplication;
        $appMonetization->is_monetization = true;
        $appMonetization->status = LeaveApplication::STATUS_APPROVED;
        $responseMonetization = $this->service->executeHrRecall($appMonetization, $hr, 'Test', ['2026-03-10']);
        $this->assertSame(422, $responseMonetization->getStatusCode());
        $this->assertStringContainsString('Monetization requests cannot be recalled', $responseMonetization->getData(true)['message']);

        // Non-approved status
        $appPending = new LeaveApplication;
        $appPending->is_monetization = false;
        $appPending->status = LeaveApplication::STATUS_PENDING_ADMIN;
        $responsePending = $this->service->executeHrRecall($appPending, $hr, 'Test', ['2026-03-10']);
        $this->assertSame(422, $responsePending->getStatusCode());
        $this->assertStringContainsString('Cannot recall: application status is', $responsePending->getData(true)['message']);
    }

    public function test_resolve_pending_update_meta_empty(): void
    {
        $app = new LeaveApplication;
        $app->id = null;
        $meta = $this->service->resolvePendingUpdateMeta($app);

        $this->assertNull($meta['payload']);
        $this->assertNull($meta['action_type']);
        $this->assertNull($meta['reason']);
        $this->assertNull($meta['previous_status']);
        $this->assertNull($meta['request_record']);
    }

    public function test_execute_hr_receive_update_not_pending_approved_update(): void
    {
        $hr = new HRAccount;
        $hr->id = 1;

        $app = new LeaveApplication;
        $app->status = LeaveApplication::STATUS_APPROVED;

        $response = $this->service->executeHrReceiveUpdate(
            $app,
            $hr,
            'Test remarks',
            pendingMetaResolver: fn () => ['payload' => null]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('application is not in pending approved-update review', $response->getData(true)['message']);
    }

    public function test_execute_hr_release_update_invalid_status(): void
    {
        $hr = new HRAccount;
        $hr->id = 1;

        $app = new LeaveApplication;
        $app->status = LeaveApplication::STATUS_PENDING_ADMIN;

        $response = $this->service->executeHrReleaseUpdate(
            $app,
            $hr,
            'Test remarks',
            latestMetaResolver: fn () => ['action_type' => LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Cannot mark updated copy as released', $response->getData(true)['message']);
    }

    public function test_hr_approve_pending_recall_request_calls_recall(): void
    {
        $hr = new HRAccount;
        $hr->id = 1;

        $app = new LeaveApplication;
        $app->is_monetization = true; // Will trigger 422 immediately inside executeHrRecall
        $app->status = LeaveApplication::STATUS_APPROVED;

        $response = $this->service->hrApprovePendingRecallRequest(
            $app,
            $hr,
            'Remarks',
            pendingUpdateMeta: [
                'reason' => 'Need employee back',
                'payload' => [
                    'recall_selected_dates' => ['2026-03-10'],
                ],
            ]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Monetization requests cannot be recalled', $response->getData(true)['message']);
    }

    public function test_execute_hr_approve_pending_cancellation_request_catches_balance_conflict(): void
    {
        $hr = new HRAccount;
        $hr->id = 1;

        $app = new LeaveApplication;
        $app->employee_control_no = null;

        $response = $this->service->executeHrApprovePendingCancellationRequest(
            $app,
            $hr,
            'Remarks',
            refundCallback: function () {
                throw new \RuntimeException('HR_CANCEL_REQUEST_BALANCE_CONFLICT');
            }
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertStringContainsString('Unable to apply this leave cancellation request due to leave balance mismatch', $response->getData(true)['message']);
    }

    public function test_create_employee_filed_application(): void
    {
        DB::beginTransaction();
        try {
            $account = (object) ['id' => 99999];
            $app = $this->service->createEmployeeFiledApplication($account, [
                'employee_control_no' => '999999',
                'leave_type_id' => 1,
                'total_days' => 2.0,
                'deductible_days' => 2.0,
            ]);

            $this->assertInstanceOf(LeaveApplication::class, $app);
            $this->assertSame(LeaveApplication::STATUS_PENDING_ADMIN, $app->status);
            $this->assertSame('999999', $app->employee_control_no);

            $log = LeaveApplicationLog::where('leave_application_id', $app->id)
                ->where('action', LeaveApplicationLog::ACTION_SUBMITTED)
                ->first();

            $this->assertNotNull($log);
            $this->assertSame(LeaveApplicationLog::PERFORMER_EMPLOYEE, $log->performed_by_type);
            $this->assertSame(99999, (int) $log->performed_by_id);
        } finally {
            DB::rollBack();
        }
    }

    public function test_create_admin_filed_application(): void
    {
        DB::beginTransaction();
        try {
            $admin = DepartmentAdmin::first();
            if (! $admin) {
                $this->markTestSkipped('No DepartmentAdmin available in test database.');
            }

            $app = $this->service->createAdminFiledApplication($admin, [
                'employee_control_no' => '888888',
                'leave_type_id' => 1,
                'total_days' => 3.0,
                'deductible_days' => 3.0,
            ], 'Admin filed for employee');

            $this->assertInstanceOf(LeaveApplication::class, $app);
            $this->assertSame(LeaveApplication::STATUS_PENDING_HR, $app->status);
            $this->assertSame($admin->id, (int) $app->admin_id);
            $this->assertNotNull($app->admin_approved_at);

            $submitLog = LeaveApplicationLog::where('leave_application_id', $app->id)
                ->where('action', LeaveApplicationLog::ACTION_SUBMITTED)
                ->first();
            $this->assertNotNull($submitLog);
            $this->assertSame(LeaveApplicationLog::PERFORMER_ADMIN, $submitLog->performed_by_type);
            $this->assertSame($admin->id, (int) $submitLog->performed_by_id);

            $approvedLog = LeaveApplicationLog::where('leave_application_id', $app->id)
                ->where('action', LeaveApplicationLog::ACTION_ADMIN_APPROVED)
                ->first();
            $this->assertNotNull($approvedLog);
            $this->assertSame('Admin filed for employee', $approvedLog->remarks);
        } finally {
            DB::rollBack();
        }
    }

    public function test_notify_department_admins_of_employee_filed_application(): void
    {
        $app = new LeaveApplication;
        $app->id = 77777;
        $app->total_days = 2.0;

        $employee = (object) [
            'firstname' => 'Test',
            'surname' => 'Employee',
            'office' => 'NON_EXISTENT_TEST_OFFICE',
        ];

        $this->service->notifyDepartmentAdminsOfEmployeeFiledApplication($app, $employee);
        $this->service->notifyDepartmentAdminsOfEmployeeFiledApplication($app, $employee, 'VL (2 days)');

        $this->assertTrue(true);
    }

    public function test_notify_hr_accounts_of_admin_filed_application(): void
    {
        DB::beginTransaction();
        try {
            $account = (object) ['id' => 99999];
            $app = $this->service->createEmployeeFiledApplication($account, [
                'employee_control_no' => '999999',
                'leave_type_id' => 1,
                'total_days' => 1.0,
                'deductible_days' => 1.0,
            ]);

            $this->service->notifyHrAccountsOfAdminFiledApplication($app, 'Test Employee', false);
            $this->service->notifyHrAccountsOfAdminFiledApplication($app, 'Test Employee', true, 'SL (1 day)');

            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_notify_parties_of_employee_cancellation(): void
    {
        DB::beginTransaction();
        try {
            $account = (object) ['id' => 99999];
            $app = $this->service->createEmployeeFiledApplication($account, [
                'employee_control_no' => '999999',
                'leave_type_id' => 1,
                'total_days' => 1.0,
                'deductible_days' => 1.0,
            ]);

            $employee = (object) [
                'firstname' => 'Test',
                'surname' => 'Employee',
                'office' => 'NON_EXISTENT_TEST_OFFICE',
                'control_no' => '999999',
            ];

            $this->service->notifyPartiesOfEmployeeCancellation($app, $employee, LeaveApplication::STATUS_PENDING_HR, 'Personal reason');
            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_notify_department_admins_of_approved_leave_cancellation_request(): void
    {
        $app = new LeaveApplication;
        $app->id = 77777;

        $employee = (object) [
            'firstname' => 'Test',
            'surname' => 'Employee',
            'office' => 'NON_EXISTENT_TEST_OFFICE',
        ];

        $this->service->notifyDepartmentAdminsOfApprovedLeaveCancellationRequest($app, $employee, 'Family emergency');
        $this->assertTrue(true);
    }

    public function test_withdraw_pending_approved_update_request(): void
    {
        DB::beginTransaction();
        try {
            $account = (object) ['id' => 99999];
            $app = $this->service->createEmployeeFiledApplication($account, [
                'employee_control_no' => '999999',
                'leave_type_id' => 1,
                'total_days' => 2.0,
                'deductible_days' => 2.0,
                'remarks' => "Initial remark\nCancellation request submitted by employee. Reason: test",
            ]);

            $updateRequest = LeaveApplicationUpdateRequest::create([
                'leave_application_id' => (int) $app->id,
                'status' => LeaveApplicationUpdateRequest::STATUS_PENDING,
                'previous_status' => LeaveApplication::STATUS_APPROVED,
                'requested_payload' => ['total_days' => 2.0],
                'requested_reason' => 'test',
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => 99999,
                'remarks' => 'Cancellation request submitted by employee. Reason: test',
                'created_at' => now(),
            ]);

            $this->service->withdrawPendingApprovedUpdateRequest($app, $updateRequest, LeaveApplication::STATUS_APPROVED);

            $app->refresh();
            $this->assertSame(LeaveApplication::STATUS_APPROVED, $app->status);
            $this->assertStringNotContainsString('Cancellation request submitted', (string) $app->remarks);

            $this->assertNull(LeaveApplicationUpdateRequest::find($updateRequest->id));
            $this->assertSame(0, LeaveApplicationLog::where('leave_application_id', $app->id)
                ->where('remarks', 'LIKE', '%Cancellation request submitted%')
                ->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_submit_employee_leave_update_request_and_notify(): void
    {
        DB::beginTransaction();
        try {
            $account = (object) ['id' => 99999];
            $app = $this->service->createEmployeeFiledApplication($account, [
                'employee_control_no' => '999999',
                'leave_type_id' => 1,
                'total_days' => 2.0,
                'deductible_days' => 2.0,
            ]);

            $employee = (object) [
                'firstname' => 'Test',
                'surname' => 'Employee',
                'office' => 'NON_EXISTENT_TEST_OFFICE',
                'control_no' => '999999',
            ];

            // Test on approved application
            $this->service->submitEmployeeLeaveUpdateRequest(
                $app,
                ['total_days' => 3.0],
                'Edit request submitted by employee. Reason: test update',
                'test update',
                99999,
                '999999',
                'Test Employee',
                true
            );

            $app->refresh();
            $this->assertSame(LeaveApplication::STATUS_PENDING_ADMIN, $app->status);

            $updateReq = LeaveApplicationUpdateRequest::where('leave_application_id', $app->id)
                ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
                ->first();
            $this->assertNotNull($updateReq);
            $this->assertSame('test update', $updateReq->requested_reason);

            $this->service->notifyPartiesOfEmployeeUpdateRequest($app, $employee, true, 'test update');
            $this->service->notifyPartiesOfEmployeeUpdateRequest($app, $employee, false, 'test update');

            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_resolve_admin_approval_response_message(): void
    {
        $this->assertSame(
            'Application approved by admin. Forwarded to HR.',
            $this->service->resolveAdminApprovalResponseMessage(false)
        );
        $this->assertSame(
            'Leave cancellation request approved by admin. Forwarded to HR for final approval.',
            $this->service->resolveAdminApprovalResponseMessage(true, LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL)
        );
        $this->assertSame(
            'Leave recall request approved by admin. Forwarded to HR for final approval.',
            $this->service->resolveAdminApprovalResponseMessage(true, LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL)
        );
        $this->assertSame(
            'Leave update request approved by admin. Forwarded to HR for final approval.',
            $this->service->resolveAdminApprovalResponseMessage(true, LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE)
        );
    }

    public function test_is_recallable_leave_type(): void
    {
        $app = new LeaveApplication;
        $app->leave_type_id = 1;

        $vlType = new LeaveType;
        $vlType->id = 1;
        $vlType->name = 'Vacation Leave';

        $this->assertTrue($this->service->isRecallableLeaveType($app, $vlType));

        $flType = new LeaveType;
        $flType->id = 2;
        $flType->name = 'Mandatory / Forced Leave';
        $app->leave_type_id = 2;
        $this->assertTrue($this->service->isRecallableLeaveType($app, $flType));

        $slType = new LeaveType;
        $slType->id = 3;
        $slType->name = 'Sick Leave';
        $app->leave_type_id = 3;
        $this->assertFalse($this->service->isRecallableLeaveType($app, $slType));
    }

    public function test_notify_hr_accounts_of_admin_approval_and_recall(): void
    {
        DB::beginTransaction();
        try {
            $account = (object) ['id' => 99999];
            $app = $this->service->createEmployeeFiledApplication($account, [
                'employee_control_no' => '999999',
                'leave_type_id' => 1,
                'total_days' => 2.0,
                'deductible_days' => 2.0,
            ]);

            $this->service->notifyHrAccountsOfAdminApproval($app, 'Test Employee', false);
            $this->service->notifyHrAccountsOfAdminApproval($app, 'Test Employee', true, LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL);
            $this->service->notifyHrAccountsOfAdminApproval($app, 'Test Employee', true, LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL);
            $this->service->notifyHrAccountsOfAdminApproval($app, 'Test Employee', true, LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE);

            $this->service->notifyHrAccountsOfAdminRecallRequest($app, 'Test Employee', 'Need employee for urgent tasks');

            $this->assertTrue(true);
        } finally {
            DB::rollBack();
        }
    }

    public function test_notify_applicant_of_hr_approval_and_rejection(): void
    {
        $app = new LeaveApplication;
        $app->id = 12345;
        $app->applicant_admin_id = null;
        $app->is_monetization = false;
        $app->total_days = 2.0;

        $leaveType = new LeaveType;
        $leaveType->name = 'Vacation Leave';
        $app->setRelation('leaveType', $leaveType);

        $approvedSmsCalled = false;
        $this->service->notifyApplicantOfHrApproval($app, function ($calledApp) use (&$approvedSmsCalled, $app): void {
            $approvedSmsCalled = ($calledApp === $app);
        });
        $this->assertTrue($approvedSmsCalled);

        $rejectedSmsCalled = false;
        $this->service->notifyApplicantOfHrRejection($app, 'Missing requirements', function ($calledApp) use (&$rejectedSmsCalled, $app): void {
            $rejectedSmsCalled = ($calledApp === $app);
        });
        $this->assertTrue($rejectedSmsCalled);
    }

    public function test_hr_application_edit_request_workflow_helpers_and_rejection(): void
    {
        $payloadWithMeta = $this->service->withHrApplicationEditRequestMeta(['reason' => 'Fix date']);
        $this->assertSame(LeaveWorkflowService::HR_APPLICATION_EDIT_REQUEST_KIND, $payloadWithMeta['request_kind']);
        $this->assertSame(LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE, $payloadWithMeta['action_type']);

        $hr = new HRAccount;
        $hr->id = 42;
        $hr->username = 'hrstaff';
        $this->assertSame('HR:42:hrstaff', $this->service->formatHrEditRequestActor($hr));

        $hrNoUser = new HRAccount;
        $hrNoUser->id = 99;
        $hrNoUser->username = '';
        $this->assertSame('HR:99', $this->service->formatHrEditRequestActor($hrNoUser));

        $log = new LeaveApplicationLog;
        $log->action = LeaveApplicationLog::ACTION_HR_APPLICATION_EDITED;
        $this->assertTrue($this->service->isHrApplicationEditWorkflowLog($log));

        $log->action = LeaveApplicationLog::ACTION_HR_APPROVED;
        $this->assertFalse($this->service->isHrApplicationEditWorkflowLog($log));

        $editRequest = new LeaveApplicationUpdateRequest;
        $editRequest->requested_payload = ['request_kind' => LeaveApplicationUpdateRequest::REQUEST_KIND_HR_EDIT];
        $this->assertSame(LeaveApplicationUpdateRequest::REQUEST_KIND_HR_EDIT, $this->service->resolveEditRequestPayloadRequestKind($editRequest));

        $editRequest->requested_payload = json_encode(['request_kind' => LeaveApplicationUpdateRequest::REQUEST_KIND_HR_EDIT]);
        $this->assertSame(LeaveApplicationUpdateRequest::REQUEST_KIND_HR_EDIT, $this->service->resolveEditRequestPayloadRequestKind($editRequest));

        $editRequest->requested_payload = null;
        $this->assertNull($this->service->resolveEditRequestPayloadRequestKind($editRequest));
    }

    public function test_create_and_reject_hr_staff_application_edit_request(): void
    {
        DB::beginTransaction();
        try {
            $account = (object) ['id' => 99999];
            $app = $this->service->createEmployeeFiledApplication($account, [
                'employee_control_no' => '999999',
                'leave_type_id' => 1,
                'total_days' => 2.0,
                'deductible_days' => 2.0,
            ]);

            $hr = HRAccount::first() ?? new HRAccount(['id' => 1, 'username' => 'test_hr']);

            $editRequest = $this->service->createHrStaffApplicationEditRequest(
                $app,
                $hr,
                ['total_days' => 3.0],
                'Correcting days',
                'Test Employee'
            );

            $this->assertInstanceOf(LeaveApplicationUpdateRequest::class, $editRequest);
            $this->assertSame(LeaveApplicationUpdateRequest::STATUS_PENDING, $editRequest->status);
            $this->assertSame('HR:'.$hr->id.':'.$hr->username, $editRequest->requested_by_control_no);

            $rejected = $this->service->executeHrRejectApplicationEditRequest($editRequest, $hr, 'Incorrect details');
            $this->assertSame(LeaveApplicationUpdateRequest::STATUS_REJECTED, $rejected->status);
            $this->assertSame('Incorrect details', $rejected->review_remarks);
        } finally {
            DB::rollBack();
        }
    }

    public function test_hr_bulk_cmo_cbmo_review_and_release(): void
    {
        DB::beginTransaction();
        try {
            $account = (object) ['id' => 99999];
            $app = $this->service->createEmployeeFiledApplication($account, [
                'employee_control_no' => '999999',
                'leave_type_id' => 1,
                'total_days' => 2.0,
                'deductible_days' => 2.0,
            ]);

            $app->status = LeaveApplication::STATUS_APPROVED;
            $app->save();

            $hr = HRAccount::first() ?? new HRAccount(['id' => 1, 'username' => 'test_hr']);

            // Initially, not received by HR -> skipped in CMO review
            $app->load('logs');
            $reviewResult = $this->service->executeHrBulkCmoCbmoReview(
                collect([$app]),
                $hr,
                'Review remarks'
            );
            $this->assertSame(0, $reviewResult['processed_count']);
            $this->assertSame(1, $reviewResult['skipped_count']);

            // Add HR_RECEIVED log
            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_RECEIVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => 'Hard copy received',
                'created_at' => now(),
            ]);

            $app->load('logs');
            $smsCalled = false;
            $reviewResult = $this->service->executeHrBulkCmoCbmoReview(
                collect([$app]),
                $hr,
                'Review remarks',
                function ($calledApp) use (&$smsCalled, $app): void {
                    $smsCalled = ($calledApp->id === $app->id);
                }
            );
            $this->assertSame(1, $reviewResult['processed_count']);
            $this->assertTrue($smsCalled);

            // Now release
            $app->load('logs');
            $releaseResult = $this->service->executeHrBulkRelease(
                collect([$app]),
                $hr,
                'Release remarks'
            );
            $this->assertSame(1, $releaseResult['processed_count']);
            $this->assertSame(0, $releaseResult['skipped_count']);

            // Subsequent release should recognize it's already released and process without error
            $app->load('logs');
            $releaseResult2 = $this->service->executeHrBulkRelease(
                collect([$app]),
                $hr,
                'Release remarks'
            );
            $this->assertSame(1, $releaseResult2['processed_count']);
        } finally {
            DB::rollBack();
        }
    }

    public function test_has_requested_leave_update_changes(): void
    {
        $app = new LeaveApplication;
        $app->leave_type_id = 1;
        $app->start_date = Carbon::parse('2026-11-01');
        $app->end_date = Carbon::parse('2026-11-02');
        $app->selected_dates = ['2026-11-01', '2026-11-02'];
        $app->total_days = 2.0;
        $app->deductible_days = 2.0;
        $app->reason = 'Vacation';
        $app->details_of_leave = 'Within PH';
        $app->commutation = 'Not Requested';
        $app->pay_mode = LeaveApplication::PAY_MODE_WITH_PAY;
        $app->is_monetization = false;

        $identicalPayload = [
            'leave_type_id' => 1,
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-02',
            'selected_dates' => ['2026-11-01', '2026-11-02'],
            'total_days' => 2.0,
            'deductible_days' => 2.0,
            'reason' => 'Vacation',
            'details_of_leave' => 'Within PH',
            'commutation' => 'Not Requested',
            'pay_mode' => LeaveApplication::PAY_MODE_WITH_PAY,
            'is_monetization' => false,
        ];

        $this->assertFalse($this->service->hasRequestedLeaveUpdateChanges($app, $identicalPayload));

        $changedPayload = $identicalPayload;
        $changedPayload['reason'] = 'Different reason';
        $this->assertTrue($this->service->hasRequestedLeaveUpdateChanges($app, $changedPayload));

        $changedDaysPayload = $identicalPayload;
        $changedDaysPayload['total_days'] = 3.0;
        $this->assertTrue($this->service->hasRequestedLeaveUpdateChanges($app, $changedDaysPayload));
    }

    public function test_normalize_hr_edit_helpers(): void
    {
        $this->assertSame(2.5, $this->service->normalizeHrEditDayValue('2.5', 0.0));
        $this->assertSame(1.0, $this->service->normalizeHrEditDayValue('invalid', 1.0));
        $this->assertSame(0.0, $this->service->normalizeHrEditDayValue(-5, 0.0));
        $this->assertSame(999.0, $this->service->normalizeHrEditDayValue(1500, 0.0));

        $dates = $this->service->normalizeHrEditSelectedDates(json_encode(['2026-06-03', '2026-06-01', '2026-06-03']));
        $this->assertSame(['2026-06-01', '2026-06-03'], $dates);

        $numericMap = $this->service->normalizeHrEditNumericDateMap(['2026-06-03' => '1.5', '2026-06-01' => 1]);
        $this->assertSame(['2026-06-01' => 1.0, '2026-06-03' => 1.5], $numericMap);

        $compacted = $this->service->compactHrEditNumericDateMap($numericMap, ['2026-06-01']);
        $this->assertSame(['2026-06-01' => 1.0], $compacted);

        $rows = $this->service->normalizeHrEditRowsInput([
            '2026-06-01' => 'WITH PAY',
            '2026-06-02' => ['pay_status' => 'WITHOUT PAY'],
        ]);
        $this->assertCount(2, $rows);
        $this->assertSame('2026-06-01', $rows[0]['date_key']);
        $this->assertSame('WITH PAY', $rows[0]['pay_status']);
        $this->assertSame('2026-06-02', $rows[1]['date_key']);
    }

    public function test_normalize_hr_application_edit_payload(): void
    {
        $leaveType = new LeaveType;
        $leaveType->id = 1;
        $leaveType->name = 'Vacation Leave';

        $app = new LeaveApplication;
        $app->leave_type_id = 1;
        $app->start_date = Carbon::parse('2026-06-01');
        $app->end_date = Carbon::parse('2026-06-02');
        $app->selected_dates = ['2026-06-01', '2026-06-02'];
        $app->total_days = 2.0;
        $app->pay_mode = LeaveApplication::PAY_MODE_WITH_PAY;
        $app->setRelation('leaveType', $leaveType);

        $input = [
            'pay_status_rows' => [
                [
                    'date_key' => '2026-06-01',
                    'coverage' => 'whole',
                    'pay_status' => 'WITH PAY',
                    'with_pay_days' => 1.0,
                ],
                [
                    'date_key' => '2026-06-02',
                    'coverage' => 'half',
                    'half_day_portion' => 'PM',
                    'pay_status' => 'WITHOUT PAY',
                    'without_pay_days' => 0.5,
                ],
            ],
        ];

        $payload = $this->service->normalizeHrApplicationEditPayload($input, $app);
        $this->assertIsArray($payload);
        $this->assertSame(LeaveWorkflowService::HR_APPLICATION_EDIT_REQUEST_KIND, $payload['request_kind']);
        $this->assertSame(1.5, $payload['total_days']);
        $this->assertSame(1.0, $payload['deductible_days']);
        $this->assertSame(0.5, $payload['without_pay_days']);
        $this->assertSame(['2026-06-02' => 'half'], $payload['selected_date_coverage']);
        $this->assertSame(['2026-06-02' => 'PM'], $payload['selected_date_half_day_portion']);
    }

    public function test_application_has_hr_selected_date_ledger_values(): void
    {
        $app = new LeaveApplication;
        $this->assertFalse($this->service->applicationHasHrSelectedDateLedgerValues($app));

        $app->selected_date_deduction = ['2026-06-01' => 1.0];
        $this->assertTrue($this->service->applicationHasHrSelectedDateLedgerValues($app));
    }

    public function test_apply_hr_application_edit_validation_errors(): void
    {
        $hr = new HRAccount;
        $hr->id = 1;

        $leaveType = new LeaveType;
        $leaveType->id = 1;
        $leaveType->name = 'Vacation Leave';
        $leaveType->max_days = 5;

        $app = new LeaveApplication;
        $app->id = 10;
        $app->leave_type_id = 1;
        $app->setRelation('leaveType', $leaveType);

        // Zero total days
        $resZeroDays = $this->service->applyHrApplicationEdit($app, $hr, ['total_days' => 0], 'Test');
        $this->assertInstanceOf(JsonResponse::class, $resZeroDays);
        $this->assertSame(422, $resZeroDays->getStatusCode());
        $this->assertStringContainsString('must include at least one day', $resZeroDays->getData(true)['message']);

        // Empty selected dates
        $resNoDates = $this->service->applyHrApplicationEdit($app, $hr, ['total_days' => 1, 'selected_dates' => []], 'Test');
        $this->assertInstanceOf(JsonResponse::class, $resNoDates);
        $this->assertSame(422, $resNoDates->getStatusCode());
        $this->assertStringContainsString('must include at least one leave date', $resNoDates->getData(true)['message']);
    }

    public function test_build_requested_leave_update_payload_blocks_mode_switching(): void
    {
        $app = new LeaveApplication;
        $app->is_monetization = false;

        $res = $this->service->buildRequestedLeaveUpdatePayload(
            $app,
            ['is_monetization' => true],
            [],
            '',
            LeaveApplication::PAY_MODE_WITH_PAY,
            false,
            []
        );

        $this->assertInstanceOf(JsonResponse::class, $res);
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Switching between leave and monetization modes is not allowed', $res->getData(true)['message']);
    }

    public function test_execute_hr_update_pay_status_validation_errors(): void
    {
        $hr = new HRAccount;
        $hr->id = 1;

        // Invalid status
        $appApproved = new LeaveApplication;
        $appApproved->status = LeaveApplication::STATUS_APPROVED;
        $resStatus = $this->service->executeHrUpdatePayStatus($appApproved, $hr, null, 'WP', false, false);
        $this->assertSame(422, $resStatus->getStatusCode());
        $this->assertStringContainsString('only be overridden during CHRMO Certification', $resStatus->getData(true)['message']);

        // Monetization not allowed
        $appMonetization = new LeaveApplication;
        $appMonetization->status = LeaveApplication::STATUS_PENDING_HR;
        $appMonetization->is_monetization = true;
        $appMonetization->setRelation('updateRequests', collect());
        $resMonetization = $this->service->executeHrUpdatePayStatus($appMonetization, $hr, null, 'WP', false, false);
        $this->assertSame(422, $resMonetization->getStatusCode());
        $this->assertStringContainsString('Monetization applications must remain with pay', $resMonetization->getData(true)['message']);
    }

    public function test_execute_hr_approve_invalid_status(): void
    {
        $hr = new HRAccount;
        $hr->id = 1;

        $app = new LeaveApplication;
        $app->status = LeaveApplication::STATUS_PENDING_ADMIN;

        $res = $this->service->executeHrApprove($app, $hr, 'Remarks');
        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString("expected 'PENDING_HR'", $res->getData(true)['message']);
    }

    public function test_execute_hr_save_application_edit_validation_errors(): void
    {
        $hr = new HRAccount;
        $hr->id = 1;

        $appRejected = new LeaveApplication;
        $appRejected->status = LeaveApplication::STATUS_REJECTED;

        $resStatus = $this->service->executeHrSaveApplicationEdit(
            Request::create('/hr/applications/1/edit', 'POST'),
            $hr,
            $appRejected,
            []
        );
        $this->assertSame(422, $resStatus->getStatusCode());
        $this->assertStringContainsString('Only Pending HR and Approved applications can be edited by HR', $resStatus->getData(true)['message']);

        $appMonetization = new LeaveApplication;
        $appMonetization->status = LeaveApplication::STATUS_PENDING_HR;
        $appMonetization->is_monetization = true;

        $resMonetization = $this->service->executeHrSaveApplicationEdit(
            Request::create('/hr/applications/1/edit', 'POST'),
            $hr,
            $appMonetization,
            []
        );
        $this->assertSame(422, $resMonetization->getStatusCode());
        $this->assertStringContainsString('Monetization applications are not supported', $resMonetization->getData(true)['message']);
    }

    public function test_execute_admin_request_recall_validation_errors(): void
    {
        $admin = new DepartmentAdmin;
        $admin->id = 1;
        $admin->department_id = 10;

        $app = new LeaveApplication;
        $app->id = 100;
        $app->employee_control_no = '00123';
        $app->is_monetization = true;
        $app->status = LeaveApplication::STATUS_APPROVED;

        $resUnauth = $this->service->executeAdminRequestRecall(
            $admin,
            $app,
            ['recall_reason' => 'Need back', 'recall_selected_dates' => ['2026-06-01']],
            fn () => (object) ['control_no' => '00123', 'office_id' => 99]
        );
        $this->assertSame(403, $resUnauth->getStatusCode());

        $dept = new \App\Models\Department;
        $dept->id = 10;
        $dept->name = 'IT Department';
        $admin->setRelation('department', $dept);

        $resMonetization = $this->service->executeAdminRequestRecall(
            $admin,
            $app,
            ['recall_reason' => 'Need back', 'recall_selected_dates' => ['2026-06-01']],
            fn () => (object) ['control_no' => '00123', 'office' => 'IT Department']
        );
        $this->assertSame(422, $resMonetization->getStatusCode());
        $this->assertStringContainsString('Monetization requests cannot be recalled', $resMonetization->getData(true)['message']);
    }

    public function test_execute_regular_leave_submission_missing_leave_type(): void
    {
        $request = Request::create('/leave-applications', 'POST');
        $actor = (object) ['id' => 1];
        $employee = (object) ['control_no' => '00123'];

        $res = $this->service->executeRegularLeaveSubmission(
            $request,
            [
                'leave_type_id' => 999999,
                'start_date' => '2026-06-01',
                'end_date' => '2026-06-01',
                'total_days' => 1.0,
            ],
            $actor,
            $employee
        );

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Selected leave type is not available', $res->getData(true)['message']);
    }

    public function test_execute_monetization_submission_admin_missing_employee(): void
    {
        $request = Request::create('/admin/leave-applications', 'POST');
        $admin = new DepartmentAdmin;
        $admin->id = 1;
        $dept = new \App\Models\Department;
        $dept->id = 10;
        $dept->name = 'HR';
        $admin->setRelation('department', $dept);

        $res = $this->service->executeMonetizationSubmission(
            $request,
            [
                'employee_control_no' => '99999',
                'leave_type_id' => 1,
                'total_days' => 10,
            ],
            $admin,
            null,
            true,
            null,
            fn () => null
        );

        $this->assertSame(422, $res->getStatusCode());
        $this->assertStringContainsString('Employee not found in HRIS', $res->getData(true)['message']);
    }
}
