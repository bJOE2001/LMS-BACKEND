<?php

namespace Tests\Unit;

use App\Models\HRAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveApplicationUpdateRequest;
use App\Services\Leave\LeaveWorkflowService;
use Carbon\Carbon;
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
}
