<?php

namespace App\Services\Leave;

use App\Models\DepartmentAdmin;
use App\Models\HRAccount;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveApplicationUpdateRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LeaveWorkflowService
{
    public function __construct(
        public ?LeaveCreditService $creditService = null,
        public ?LeaveCalculationService $calculationService = null,
        public ?LeaveEligibilityService $eligibilityService = null,
    ) {
        $this->creditService = $creditService ?? app(LeaveCreditService::class);
        $this->calculationService = $calculationService ?? app(LeaveCalculationService::class);
        $this->eligibilityService = $eligibilityService ?? app(LeaveEligibilityService::class);
    }

    private function eligibilityService(): LeaveEligibilityService
    {
        return $this->eligibilityService ??= app(LeaveEligibilityService::class);
    }

    public const HR_APPLICATION_EDIT_REQUEST_KIND = LeaveApplicationUpdateRequest::REQUEST_KIND_HR_EDIT;

    public const QUEUE_GROUP_PENDING = 'PENDING';

    public const QUEUE_GROUP_APPROVED = 'APPROVED';

    public const QUEUE_GROUP_REJECTED = 'REJECTED';

    public const QUEUE_GROUP_RECALLED = 'RECALLED';

    public const QUEUE_GROUP_OTHER = 'OTHER';

    public const QUEUE_GROUP_PRIORITY = [
        self::QUEUE_GROUP_PENDING => 0,
        self::QUEUE_GROUP_APPROVED => 1,
        self::QUEUE_GROUP_REJECTED => 2,
        self::QUEUE_GROUP_RECALLED => 3,
        self::QUEUE_GROUP_OTHER => 4,
    ];

    public const QUEUE_STAGE_PRIORITY = [
        'PENDING_LATE_HR' => 1,
        'PENDING_HR_RECEIVE' => 2,
        'PENDING_HR_REVIEW' => 3,
        'PENDING_HR_CLASSIFICATION' => 4,
        'PENDING_ADMIN' => 5,
        'PENDING_ADMIN_REVIEW' => 6,
        'PENDING_CMO_CBMO_REVIEW' => 7,
        'PENDING_RELEASE' => 8,
        'PENDING' => 9,
    ];

    public const QUEUE_NON_PENDING_STAGE_PRIORITY = 999;

    public function statusToFrontend(string $status): string
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

    public function ermsStatusLabel(string $status): string
    {
        return match ($status) {
            LeaveApplication::STATUS_PENDING_ADMIN, LeaveApplication::STATUS_PENDING_HR => 'Pending',
            LeaveApplication::STATUS_APPROVED => 'Approved',
            LeaveApplication::STATUS_REJECTED => 'Rejected',
            LeaveApplication::STATUS_RECALLED => 'Recalled',
            LeaveApplication::STATUS_CANCELLED => 'Cancelled',
            default => 'Pending',
        };
    }

    public static function formatDays(mixed $days): string
    {
        $num = (float) $days;
        $display = ($num == (int) $num) ? (int) $num : $num;

        return $display.' '.($num == 1 ? 'day' : 'days');
    }

    public function isCancelledRemark(mixed $remarks): bool
    {
        return (bool) preg_match('/^cancelled\b/i', trim((string) ($remarks ?? '')));
    }

    public function adminRejectStatusForRemarks(mixed $remarks): string
    {
        return $this->isCancelledRemark($remarks)
            ? LeaveApplication::STATUS_CANCELLED
            : LeaveApplication::STATUS_REJECTED;
    }

    public function adminRejectLogActionForRemarks(mixed $remarks): string
    {
        return $this->isCancelledRemark($remarks)
            ? LeaveApplicationLog::ACTION_ADMIN_CANCELLED
            : LeaveApplicationLog::ACTION_ADMIN_REJECTED;
    }

    public function isAdminCancellationLog(LeaveApplicationLog $log): bool
    {
        $performerType = strtoupper((string) $log->performed_by_type);

        if ($log->action === LeaveApplicationLog::ACTION_ADMIN_CANCELLED) {
            return true;
        }

        return $log->action === LeaveApplicationLog::ACTION_ADMIN_REJECTED
            && $performerType === LeaveApplicationLog::PERFORMER_ADMIN
            && $this->isCancelledRemark($log->remarks);
    }

    public function isEmployeeCancellationLog(LeaveApplicationLog $log): bool
    {
        $performerType = strtoupper((string) $log->performed_by_type);

        if ($log->action === LeaveApplicationLog::ACTION_EMPLOYEE_CANCELLED) {
            return true;
        }

        return $log->action === LeaveApplicationLog::ACTION_ADMIN_REJECTED
            && $performerType === LeaveApplicationLog::PERFORMER_EMPLOYEE
            && $this->isCancelledRemark($log->remarks);
    }

    public function extractCancellationReason(mixed $remarks): ?string
    {
        $normalizedRemarks = trim((string) ($remarks ?? ''));
        if ($normalizedRemarks === '' || ! $this->isCancelledRemark($normalizedRemarks)) {
            return null;
        }

        if (preg_match('/^cancelled(?:\s+via\s+[a-z0-9 _-]+)?\s*:\s*(.+)$/i', $normalizedRemarks, $matches)) {
            $reason = trim((string) ($matches[1] ?? ''));

            return $reason !== '' ? $reason : null;
        }

        return null;
    }

    public function mapWorkflowLogStage(LeaveApplicationLog $log): string
    {
        $remarks = trim((string) ($log->remarks ?? ''));
        $performerType = strtoupper((string) $log->performed_by_type);

        if ($log->action === LeaveApplicationLog::ACTION_SUBMITTED && preg_match('/^edit requested\b/i', $remarks)) {
            return 'employee requested edit';
        }

        if ($log->action === LeaveApplicationLog::ACTION_SUBMITTED && preg_match('/^cancellation request submitted by employee\b/i', $remarks)) {
            return 'employee requested cancellation';
        }

        if ($this->isAdminCancellationLog($log)) {
            return 'department admin cancelled';
        }

        if ($this->isEmployeeCancellationLog($log)) {
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
            LeaveApplicationLog::ACTION_ADMIN_CANCELLED => 'department admin cancelled',
            LeaveApplicationLog::ACTION_EMPLOYEE_CANCELLED => 'employee cancelled',
            LeaveApplicationLog::ACTION_HR_APPROVED => 'hr approved',
            LeaveApplicationLog::ACTION_HR_REJECTED => 'hr rejected',
            LeaveApplicationLog::ACTION_HR_RECALLED => 'hr recalled',
            LeaveApplicationLog::ACTION_HR_RECEIVED => 'received application',
            LeaveApplicationLog::ACTION_HR_PAY_STATUS_OVERRIDDEN => 'hr updated pay status',
            LeaveApplicationLog::ACTION_HR_APPLICATION_EDITED => 'hr edited application',
            LeaveApplicationLog::ACTION_HR_APPLICATION_EDIT_REQUESTED => 'hr requested application edit',
            LeaveApplicationLog::ACTION_HR_APPLICATION_EDIT_REQUEST_APPROVED => 'hr approved application edit request',
            LeaveApplicationLog::ACTION_HR_APPLICATION_EDIT_REQUEST_REJECTED => 'hr rejected application edit request',
            LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED => 'cmo/cvmo reviewed',
            LeaveApplicationLog::ACTION_HR_RELEASED => 'released application',
            default => strtolower(str_replace('_', ' ', (string) $log->action)),
        };
    }

    public function resolveApplicationCancellationType(LeaveApplication $app): string
    {
        if ($app->relationLoaded('updateRequests')) {
            $approvedCancelRequest = $app->updateRequests->first(function (LeaveApplicationUpdateRequest $req): bool {
                if ($req->status !== LeaveApplicationUpdateRequest::STATUS_APPROVED) {
                    return false;
                }
                $payload = $req->requested_payload;
                $actionType = is_array($payload) ? ($payload['action_type'] ?? '') : '';
                $cancelLeave = is_array($payload) ? (bool) ($payload['cancel_leave'] ?? false) : false;
                $requestKind = is_array($payload) ? ($payload['request_kind'] ?? '') : '';

                return $actionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    || $cancelLeave
                    || $requestKind === 'cancel';
            });
            if ($approvedCancelRequest !== null) {
                return 'APPROVED_CANCELLATION';
            }
        }

        $remarks = trim((string) ($app->remarks ?? ''));
        if (preg_match('/(?:approved\s+leave\s+cancellation|cancelled\s+via\s+approved)/i', $remarks)) {
            return 'APPROVED_CANCELLATION';
        }

        if ($app->relationLoaded('logs')) {
            $hasHrApprovedCancel = $app->logs->contains(function (LeaveApplicationLog $log): bool {
                return $log->action === LeaveApplicationLog::ACTION_HR_APPROVED
                    && preg_match('/cancel/i', (string) ($log->remarks ?? ''));
            });
            if ($hasHrApprovedCancel) {
                return 'APPROVED_CANCELLATION';
            }
        }

        return 'EMPLOYEE_CANCELLED';
    }

    /**
     * @return array{date: ?string, actor: string, reason: string}
     */
    public function resolveApplicationCancellationDetails(LeaveApplication $app, string $type): array
    {
        $date = null;
        $actor = '';
        $reason = '';

        if ($type === 'APPROVED_CANCELLATION') {
            if ($app->relationLoaded('updateRequests')) {
                $approvedReq = $app->updateRequests->first(function (LeaveApplicationUpdateRequest $req): bool {
                    return $req->status === LeaveApplicationUpdateRequest::STATUS_APPROVED;
                });
                if ($approvedReq) {
                    $date = $approvedReq->reviewed_at?->toIso8601String() ?? $approvedReq->updated_at?->toIso8601String();
                    $reason = (string) ($approvedReq->requested_reason ?? '');
                    if ($approvedReq->relationLoaded('reviewedByHr') && $approvedReq->reviewedByHr) {
                        $actor = (string) ($approvedReq->reviewedByHr->name ?? $approvedReq->reviewedByHr->username ?? 'HR Admin');
                    } else {
                        $actor = 'HR Admin';
                    }
                }
            }
            if (! $date && $app->relationLoaded('logs')) {
                $hrLog = $app->logs->first(function (LeaveApplicationLog $log): bool {
                    return $log->action === LeaveApplicationLog::ACTION_HR_APPROVED;
                });
                if ($hrLog) {
                    $date = $hrLog->created_at?->toIso8601String();
                }
            }
        } else {
            if ($app->relationLoaded('logs')) {
                $empLog = $app->logs->first(function (LeaveApplicationLog $log): bool {
                    return $log->action === LeaveApplicationLog::ACTION_EMPLOYEE_CANCELLED;
                });
                if ($empLog) {
                    $date = $empLog->created_at?->toIso8601String();
                    $actor = 'Employee';
                }
            }
        }

        if (! $date) {
            $date = $app->updated_at?->toIso8601String() ?? $app->created_at?->toIso8601String();
        }

        if ($reason === '') {
            $remarks = (string) ($app->remarks ?? '');
            if (preg_match('/(?:Reason:\s*|Cancelled by employee:\s*|Cancellation request submitted by employee\.\s*Reason:\s*)(.+)$/i', $remarks, $matches)) {
                $reason = trim($matches[1]);
            } elseif ($remarks !== '') {
                $reason = $remarks;
            }
        }

        if ($actor === '') {
            $actor = $type === 'APPROVED_CANCELLATION' ? 'HR / Department Admin' : 'Employee';
        }

        return [
            'date' => $date,
            'actor' => $actor,
            'reason' => $reason,
        ];
    }

    public function isCancelledForHrQueue(LeaveApplication $application): bool
    {
        if ($application->status === LeaveApplication::STATUS_CANCELLED) {
            return true;
        }

        if ($this->isCancelledRemark($application->remarks)) {
            return true;
        }

        if ($application->relationLoaded('logs')) {
            return $application->logs
                ->filter(fn ($log) => $log instanceof LeaveApplicationLog)
                ->contains(fn (LeaveApplicationLog $log): bool => $this->isCancelledRemark($log->remarks));
        }

        return false;
    }

    public function isRejectedBeforeHrQueue(LeaveApplication $application): bool
    {
        if ($application->status !== LeaveApplication::STATUS_REJECTED) {
            return false;
        }

        if ((int) ($application->hr_id ?? 0) > 0) {
            return false;
        }

        if ($application->relationLoaded('logs')) {
            return ! $application->logs
                ->filter(fn ($log) => $log instanceof LeaveApplicationLog)
                ->contains(
                    fn (LeaveApplicationLog $log): bool => $log->action === LeaveApplicationLog::ACTION_HR_REJECTED
                        && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
                );
        }

        return true;
    }

    public function resolveLeaveQueueCreatedTimestamp(LeaveApplication $application): int
    {
        $createdAt = $application->created_at;
        if ($createdAt instanceof DateTimeInterface) {
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

    public function resolveLeaveHrQueueMeta(
        LeaveApplication $application,
        ?callable $hasHrReceivedResolver = null,
        ?callable $hasHrReleasedResolver = null,
        ?callable $hasCmoCbmoReviewedResolver = null,
        ?callable $hasPendingApprovedUpdateResolver = null
    ): array {
        $rawStatus = strtoupper(trim((string) ($application->status ?? '')));
        $groupStatus = self::QUEUE_GROUP_OTHER;
        $stageKey = '';

        $hasHrReceived = $hasHrReceivedResolver !== null ? $hasHrReceivedResolver($application) : false;
        $hasHrReleased = $hasHrReleasedResolver !== null ? $hasHrReleasedResolver($application) : false;
        $hasCmoCbmoReviewed = $hasCmoCbmoReviewedResolver !== null ? $hasCmoCbmoReviewedResolver($application) : false;
        $hasPendingApprovedUpdate = $hasPendingApprovedUpdateResolver !== null ? $hasPendingApprovedUpdateResolver($application) : false;

        if ($rawStatus === LeaveApplication::STATUS_PENDING_HR) {
            $groupStatus = self::QUEUE_GROUP_PENDING;
            $stageKey = $hasHrReceived
                ? 'PENDING_HR_REVIEW'
                : 'PENDING_HR_RECEIVE';
        } elseif ($rawStatus === LeaveApplication::STATUS_PENDING_ADMIN) {
            $groupStatus = self::QUEUE_GROUP_PENDING;
            $stageKey = $hasPendingApprovedUpdate
                ? 'PENDING_ADMIN_REVIEW'
                : 'PENDING_ADMIN';
        } elseif ($rawStatus === LeaveApplication::STATUS_APPROVED) {
            if ($hasHrReleased) {
                $groupStatus = self::QUEUE_GROUP_APPROVED;
            } elseif (! $hasCmoCbmoReviewed) {
                $groupStatus = self::QUEUE_GROUP_PENDING;
                $stageKey = 'PENDING_CMO_CBMO_REVIEW';
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

    public function sortLeaveApplicationsForHrQueue(Collection $applications, ?callable $metaResolver = null): Collection
    {
        return $applications
            ->sort(function (LeaveApplication $left, LeaveApplication $right) use ($metaResolver): int {
                $leftQueueMeta = $metaResolver !== null ? $metaResolver($left) : $this->resolveLeaveHrQueueMeta($left);
                $rightQueueMeta = $metaResolver !== null ? $metaResolver($right) : $this->resolveLeaveHrQueueMeta($right);
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

    /**
     * @return array<int, string>
     */
    public function tokenizeHrApplicationSearch(mixed $search): array
    {
        $normalized = $this->normalizeHrApplicationSearchValue($search);
        if ($normalized === '') {
            return [];
        }

        return array_values(array_filter(
            explode(' ', $normalized),
            static fn (string $token): bool => $token !== ''
        ));
    }

    public function normalizeHrApplicationSearchValue(mixed $value): string
    {
        $normalized = mb_strtolower(trim((string) ($value ?? '')));
        $normalized = preg_replace('/[^a-z0-9]+/u', ' ', $normalized);

        return is_string($normalized) ? trim($normalized) : '';
    }

    public function hrQueueStageSearchLabel(string $stageKey): string
    {
        return match ($stageKey) {
            'PENDING_ADMIN', 'PENDING_ADMIN_REVIEW' => 'Department Recommendation',
            'PENDING_HR_RECEIVE' => 'Pending Receive Pending Update Receive',
            'PENDING_HR_REVIEW' => 'CHRMO Certification Pending Update Review',
            'PENDING_CMO_CBMO_REVIEW' => 'CMO CVMO Review',
            'PENDING_RELEASE' => 'Pending Release Pending Update Release',
            default => '',
        };
    }

    public function resolveLatestHrActionLogForCycle(
        LeaveApplication $app,
        string $action,
        ?DateTimeInterface $cycleRequestedAt = null
    ): ?LeaveApplicationLog {
        $logs = $app->relationLoaded('logs')
            ? $app->logs
            : $app->logs()->get();

        if (! $logs instanceof Collection || $logs->isEmpty()) {
            return null;
        }

        $cycleRequestedTimestamp = $cycleRequestedAt?->getTimestamp();
        $log = $logs
            ->filter(function (mixed $entry) use ($action, $cycleRequestedTimestamp): bool {
                if (! $entry instanceof LeaveApplicationLog) {
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
            ->sortByDesc(fn (LeaveApplicationLog $entry) => $entry->created_at?->getTimestamp() ?? PHP_INT_MIN)
            ->first();

        return $log instanceof LeaveApplicationLog ? $log : null;
    }

    public function adminCanManageApplication(
        DepartmentAdmin $admin,
        LeaveApplication $app,
        ?callable $latestAssignedDepartmentIdResolver = null,
        ?callable $applicationEmployeeResolver = null
    ): bool {
        $admin->loadMissing('department');
        $app->loadMissing('applicantAdmin.department');

        $employeeControlNo = trim((string) ($app->employee_control_no ?? ''));
        if ($employeeControlNo !== '') {
            $latestAssignedDepartmentId = $latestAssignedDepartmentIdResolver !== null
                ? $latestAssignedDepartmentIdResolver($employeeControlNo)
                : null;

            if ($latestAssignedDepartmentId !== null) {
                return (int) $latestAssignedDepartmentId === (int) ($admin->department_id ?? 0);
            }

            $applicationEmployee = $applicationEmployeeResolver !== null
                ? $applicationEmployeeResolver($app)
                : null;

            if (($applicationEmployee?->office ?? null) === $admin->department?->name) {
                return true;
            }

            return false;
        }

        if ((int) ($app->admin_id ?? 0) > 0 && (int) $app->admin_id === (int) $admin->id) {
            return true;
        }

        if ((int) ($app->admin_id ?? 0) > 0) {
            $reviewingAdminDepartmentId = DepartmentAdmin::query()
                ->whereKey((int) $app->admin_id)
                ->value('department_id');
            if (
                (int) ($reviewingAdminDepartmentId ?? 0) > 0
                && (int) $reviewingAdminDepartmentId === (int) ($admin->department_id ?? 0)
            ) {
                return true;
            }
        }

        return (int) ($app->applicantAdmin?->department_id ?? 0) > 0
            && (int) $app->applicantAdmin->department_id === (int) ($admin->department_id ?? 0);
    }

    public function trimNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    public function normalizeRequestedLeaveActionTypeToken(mixed $value): string
    {
        $normalized = strtoupper(trim((string) ($value ?? '')));
        $normalized = str_replace([' ', '-'], '_', $normalized);

        if (
            in_array($normalized, [
                LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL,
                'RECALL',
                'RECALL_REQUEST',
                'LEAVE_RECALL_REQUEST',
            ], true)
        ) {
            return LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL;
        }

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

        if (
            in_array($normalized, [
                LeaveApplicationUpdateRequest::REQUEST_KIND_HR_EDIT,
                'HR_EDIT',
                'HR_APPLICATION_EDIT_REQUEST',
            ], true)
        ) {
            return LeaveApplicationUpdateRequest::REQUEST_KIND_HR_EDIT;
        }

        return LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE;
    }

    public function resolveUpdateRequestActionTypeFromPayload(mixed $payload): string
    {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        if (! is_array($payload)) {
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
        $recallFlagValues = [
            $payload['recall_leave'] ?? null,
            $payload['request_recall'] ?? null,
            $payload['is_recall_request'] ?? null,
        ];
        foreach ($recallFlagValues as $value) {
            if ($value === true || $value === 1 || $value === '1' || $value === 'true') {
                return LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL;
            }
        }

        return $this->normalizeRequestedLeaveActionTypeToken(
            $payload['action_type']
            ?? $payload['request_type']
            ?? $payload['request_kind']
            ?? $payload['mode']
            ?? ''
        );
    }

    public function withRequestedLeaveActionType(array $payload, string $actionType): array
    {
        $normalizedActionType = $this->normalizeRequestedLeaveActionTypeToken($actionType);
        $requestKind = $normalizedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
            ? 'cancel'
            : ($normalizedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL ? 'recall' : 'update');

        return array_merge($payload, [
            'action_type' => $normalizedActionType,
            'request_kind' => $requestKind,
            'cancel_leave' => $normalizedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL,
            'recall_leave' => $normalizedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL,
        ]);
    }

    public function isHrApplicationEditRequestRecord(LeaveApplicationUpdateRequest $editRequest): bool
    {
        return $editRequest->isHrApplicationEditRequest();
    }

    public function getPendingApprovedUpdateRequestRecord(LeaveApplication $app): ?LeaveApplicationUpdateRequest
    {
        if (! $app->id) {
            return null;
        }

        if ($app->relationLoaded('updateRequests')) {
            $record = $app->updateRequests
                ->filter(fn ($item) => $item instanceof LeaveApplicationUpdateRequest)
                ->sortByDesc(fn (LeaveApplicationUpdateRequest $item) => (int) $item->id)
                ->first(function (LeaveApplicationUpdateRequest $item): bool {
                    return strtoupper(trim((string) $item->status)) === LeaveApplicationUpdateRequest::STATUS_PENDING
                        && ! $this->isHrApplicationEditRequestRecord($item);
                });

            return $record instanceof LeaveApplicationUpdateRequest ? $record : null;
        }

        $record = LeaveApplicationUpdateRequest::query()
            ->where('leave_application_id', (int) $app->id)
            ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
            ->latest('id')
            ->get()
            ->first(fn (LeaveApplicationUpdateRequest $item): bool => ! $this->isHrApplicationEditRequestRecord($item));

        return $record instanceof LeaveApplicationUpdateRequest ? $record : null;
    }

    public function getLatestUpdateRequestRecord(LeaveApplication $app): ?LeaveApplicationUpdateRequest
    {
        if (! $app->id) {
            return null;
        }

        if ($app->relationLoaded('updateRequests')) {
            $record = $app->updateRequests
                ->filter(fn ($item) => $item instanceof LeaveApplicationUpdateRequest)
                ->sortByDesc(fn (LeaveApplicationUpdateRequest $item) => (int) $item->id)
                ->first(function (LeaveApplicationUpdateRequest $item): bool {
                    return ! $this->isHrApplicationEditRequestRecord($item);
                });

            return $record instanceof LeaveApplicationUpdateRequest ? $record : null;
        }

        $record = LeaveApplicationUpdateRequest::query()
            ->where('leave_application_id', (int) $app->id)
            ->latest('id')
            ->get()
            ->first(fn (LeaveApplicationUpdateRequest $item): bool => ! $this->isHrApplicationEditRequestRecord($item));

        return $record instanceof LeaveApplicationUpdateRequest ? $record : null;
    }

    public function resolvePendingApprovedUpdateCycleRequestedAt(
        LeaveApplication $app,
        ?callable $pendingUpdateMetaResolver = null
    ): ?DateTimeInterface {
        $pendingUpdateMeta = $pendingUpdateMetaResolver !== null
            ? $pendingUpdateMetaResolver($app)
            : null;

        $requestedAt = $pendingUpdateMeta['requested_at'] ?? null;
        if ($requestedAt === null) {
            $pendingRequest = $this->getPendingApprovedUpdateRequestRecord($app);
            $requestedAt = $pendingRequest?->requested_at;
        }

        return $requestedAt instanceof DateTimeInterface ? $requestedAt : null;
    }

    public function resolveLatestApprovedUpdateCycleRequestedAt(
        LeaveApplication $app,
        ?callable $latestUpdateMetaResolver = null
    ): ?DateTimeInterface {
        if ($latestUpdateMetaResolver !== null) {
            $latestUpdateMeta = $latestUpdateMetaResolver($app);
            $latestStatus = strtoupper(trim((string) ($latestUpdateMeta['status'] ?? '')));
            $requestedAt = $latestUpdateMeta['requested_at'] ?? null;

            if (
                $latestStatus !== LeaveApplicationUpdateRequest::STATUS_APPROVED
                || ! $requestedAt instanceof DateTimeInterface
            ) {
                return null;
            }

            return $requestedAt;
        }

        $latestRequest = $this->getLatestUpdateRequestRecord($app);
        if (
            ! $latestRequest
            || strtoupper(trim((string) ($latestRequest->status ?? ''))) !== LeaveApplicationUpdateRequest::STATUS_APPROVED
            || ! $latestRequest->requested_at instanceof DateTimeInterface
        ) {
            return null;
        }

        return $latestRequest->requested_at;
    }

    public function resolveCurrentHrDocumentCycleRequestedAt(
        LeaveApplication $app,
        ?callable $pendingUpdateMetaResolver = null,
        ?callable $latestUpdateMetaResolver = null
    ): ?DateTimeInterface {
        if ($this->isPendingApprovedUpdateRequest($app, fn ($a) => $this->hasPendingApprovedUpdateRequest($a, $pendingUpdateMetaResolver))) {
            return $this->resolvePendingApprovedUpdateCycleRequestedAt($app, $pendingUpdateMetaResolver);
        }

        return $this->resolveLatestApprovedUpdateCycleRequestedAt($app, $latestUpdateMetaResolver);
    }

    public function hasPendingApprovedUpdateRequest(
        LeaveApplication $app,
        ?callable $pendingUpdateMetaResolver = null
    ): bool {
        if ($pendingUpdateMetaResolver !== null) {
            $pendingUpdateMeta = $pendingUpdateMetaResolver($app);
            if (($pendingUpdateMeta['payload'] ?? null) === null) {
                return false;
            }

            $previousStatus = strtoupper(trim((string) ($pendingUpdateMeta['previous_status'] ?? '')));

            return $previousStatus === LeaveApplication::STATUS_APPROVED;
        }

        $pendingRequest = $this->getPendingApprovedUpdateRequestRecord($app);
        if (! $pendingRequest) {
            return false;
        }

        return strtoupper(trim((string) ($pendingRequest->previous_status ?? ''))) === LeaveApplication::STATUS_APPROVED;
    }

    public function isPendingApprovedUpdateRequest(
        LeaveApplication $app,
        ?callable $hasPendingApprovedUpdateResolver = null
    ): bool {
        if ($app->status !== LeaveApplication::STATUS_PENDING_HR) {
            return false;
        }

        return $hasPendingApprovedUpdateResolver !== null
            ? $hasPendingApprovedUpdateResolver($app)
            : $this->hasPendingApprovedUpdateRequest($app);
    }

    public function shouldPresentApprovedWhilePendingUpdate(
        LeaveApplication $app,
        bool $hasPendingApprovedUpdateRequest,
        ?callable $pendingUpdateMetaResolver = null
    ): bool {
        if (! $hasPendingApprovedUpdateRequest) {
            return false;
        }

        if ($pendingUpdateMetaResolver !== null) {
            $pendingUpdateMeta = $pendingUpdateMetaResolver($app);
            $previousStatus = strtoupper(trim((string) ($pendingUpdateMeta['previous_status'] ?? '')));
            if ($previousStatus !== LeaveApplication::STATUS_APPROVED) {
                return false;
            }
        } else {
            $pendingRequest = $this->getPendingApprovedUpdateRequestRecord($app);
            if (! $pendingRequest || strtoupper(trim((string) ($pendingRequest->previous_status ?? ''))) !== LeaveApplication::STATUS_APPROVED) {
                return false;
            }
        }

        return in_array($app->status, [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ], true);
    }

    public function resolvePendingApprovedUpdateActionType(
        LeaveApplication $app,
        ?callable $pendingUpdateMetaResolver = null
    ): string {
        if ($pendingUpdateMetaResolver !== null) {
            $pendingUpdateMeta = $pendingUpdateMetaResolver($app);

            return $this->resolveUpdateRequestActionTypeFromPayload($pendingUpdateMeta['payload'] ?? null);
        }

        $pendingRequest = $this->getPendingApprovedUpdateRequestRecord($app);

        return $this->resolveUpdateRequestActionTypeFromPayload($pendingRequest?->requested_payload ?? null);
    }

    public function resolveLatestApprovedUpdateActionType(
        LeaveApplication $app,
        ?callable $latestUpdateMetaResolver = null
    ): string {
        if ($latestUpdateMetaResolver !== null) {
            $latestUpdateMeta = $latestUpdateMetaResolver($app);
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

        $latestRequest = $this->getLatestUpdateRequestRecord($app);
        if (
            ! $latestRequest
            || strtoupper(trim((string) ($latestRequest->status ?? ''))) !== LeaveApplicationUpdateRequest::STATUS_APPROVED
            || strtoupper(trim((string) ($latestRequest->previous_status ?? ''))) !== LeaveApplication::STATUS_APPROVED
        ) {
            return LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE;
        }

        return $this->resolveUpdateRequestActionTypeFromPayload($latestRequest->requested_payload ?? null);
    }

    public function cleanRequestLinesFromRemarks(?string $remarks): ?string
    {
        if ($remarks === null || trim($remarks) === '') {
            return null;
        }

        $lines = explode("\n", $remarks);
        $filtered = array_filter($lines, static function (string $line): bool {
            $trimmed = trim($line);
            if ($trimmed === '') {
                return false;
            }
            if (preg_match('/^(?:Cancellation|Edit|Update|Recall)\s+request\s+submitted\b/i', $trimmed)) {
                return false;
            }

            return true;
        });

        $result = implode("\n", array_map('trim', $filtered));

        return $result !== '' ? $result : null;
    }

    public function normalizeRecallDateKeys(array $rawDates, ?callable $dateKeyNormalizer = null): array
    {
        $normalizedDateKeys = [];

        foreach ($rawDates as $rawDate) {
            $dateKey = $dateKeyNormalizer !== null
                ? $dateKeyNormalizer($rawDate)
                : trim((string) $rawDate);

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

    public static function formatEmployeeFullName(?object $employee): string
    {
        if (! is_object($employee)) {
            return '';
        }

        return trim(implode(' ', array_filter([
            trim((string) ($employee->firstname ?? $employee->first_name ?? '')),
            trim((string) ($employee->middlename ?? $employee->middle_name ?? '')),
            trim((string) ($employee->surname ?? $employee->last_name ?? '')),
            trim((string) ($employee->suffix ?? '')),
        ], static fn (string $part): bool => $part !== '')));
    }

    public function buildWorkflowActorDirectory(
        iterable $applications,
        ?callable $employeeResolver = null,
        ?callable $controlNoNormalizer = null
    ): array {
        $adminIds = [];
        $hrIds = [];
        $employeeNamesByControlNo = [];

        foreach ($applications as $application) {
            if (! $application instanceof LeaveApplication) {
                continue;
            }

            if ($application->admin_id) {
                $adminIds[] = (int) $application->admin_id;
            }

            if ($application->hr_id) {
                $hrIds[] = (int) $application->hr_id;
            }

            $employeeControlNo = $controlNoNormalizer !== null
                ? $controlNoNormalizer($application->employee_control_no)
                : trim((string) ($application->employee_control_no ?? ''));

            if ($employeeControlNo !== '') {
                $employee = $employeeResolver !== null ? $employeeResolver($application) : null;
                $employeeName = self::formatEmployeeFullName($employee);
                if ($employeeName === '') {
                    $employeeName = trim((string) ($application->applicantAdmin?->full_name ?? ''));
                }
                if ($employeeName !== '') {
                    $employeeNamesByControlNo[$employeeControlNo] = $employeeName;
                }
            }

            if (! $application->relationLoaded('logs')) {
                continue;
            }

            foreach ($application->logs as $log) {
                if (! $log instanceof LeaveApplicationLog || ! $log->performed_by_id) {
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
            ->map(fn ($name) => trim((string) $name))
            ->all();

        $hrNamesById = HRAccount::query()
            ->whereIn('id', $hrIds)
            ->pluck('full_name', 'id')
            ->map(fn ($name) => trim((string) $name))
            ->all();

        return [
            'admin' => $adminNamesById,
            'hr' => $hrNamesById,
            'employee' => $employeeNamesByControlNo,
        ];
    }

    public function resolveWorkflowPerformerName(
        ?LeaveApplicationLog $log,
        array $actorDirectory,
        string $fallbackEmployeeName = '',
        ?callable $controlNoNormalizer = null
    ): ?string {
        if (! $log) {
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
            $controlNo = $controlNoNormalizer !== null
                ? $controlNoNormalizer($performerId)
                : trim((string) $performerId);

            return $actorDirectory['employee'][$controlNo] ?? ($fallbackEmployeeName !== '' ? $fallbackEmployeeName : null);
        }

        return null;
    }

    public function buildWorkflowStatusHistory(
        iterable $logs,
        ?array $actorDirectory = null,
        string $applicantName = '',
        ?callable $controlNoNormalizer = null
    ): array {
        $history = [];

        foreach ($logs as $log) {
            if (! $log instanceof LeaveApplicationLog) {
                continue;
            }

            $actorName = $actorDirectory !== null
                ? $this->resolveWorkflowPerformerName($log, $actorDirectory, $applicantName, $controlNoNormalizer)
                : null;

            if (! $actorName) {
                $performerType = strtoupper((string) $log->performed_by_type);
                if ($performerType === LeaveApplicationLog::PERFORMER_EMPLOYEE) {
                    $actorName = $applicantName !== '' ? $applicantName : 'Employee';
                } elseif ($performerType === LeaveApplicationLog::PERFORMER_ADMIN) {
                    $actorName = 'Department Admin';
                } elseif ($performerType === LeaveApplicationLog::PERFORMER_HR) {
                    $actorName = 'HR Admin';
                }
            }

            $history[] = [
                'id' => (int) $log->id,
                'leave_application_id' => (int) $log->leave_application_id,
                'action' => $log->action,
                'stage' => $this->mapWorkflowLogStage($log),
                'performed_by_type' => strtoupper((string) $log->performed_by_type),
                'performed_by_id' => $log->performed_by_id !== null ? (int) $log->performed_by_id : null,
                'actor_name' => $actorName,
                'action_by_name' => $actorName,
                'action_by' => $actorName,
                'remarks' => $log->remarks,
                'created_at' => $log->created_at?->toIso8601String(),
            ];
        }

        return $history;
    }

    public function resolveApplicationMilestoneLogs(
        LeaveApplication $app,
        ?callable $isCancelledRemarkResolver = null
    ): array {
        $logs = $app->relationLoaded('logs')
            ? $app->logs->sortBy(fn (LeaveApplicationLog $log) => $log->created_at?->timestamp ?? 0)->values()
            : collect();

        $submittedLog = $logs->first(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_SUBMITTED
        );
        $adminApprovedLog = $logs->first(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_ADMIN_APPROVED
        );
        $hrApprovedLog = $logs->first(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_APPROVED
        );
        $hrReceivedLog = $logs->last(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_RECEIVED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );
        $cmoCbmoReviewedLog = $logs->last(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );
        $hrReleasedLog = $logs->last(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_RELEASED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );
        $hrRecalledLog = $logs->first(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_RECALLED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );
        $adminRejectedLog = $logs->first(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_ADMIN_REJECTED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_ADMIN
        );
        $hrRejectedLog = $logs->first(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_REJECTED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );
        $cancelledLog = $logs->last(
            fn (LeaveApplicationLog $log) => ($isCancelledRemarkResolver ? $isCancelledRemarkResolver($log->remarks) : $this->isCancelledRemark($log->remarks))
                || $this->isAdminCancellationLog($log)
                || $this->isEmployeeCancellationLog($log)
        );

        return [
            'submitted' => $submittedLog,
            'admin_approved' => $adminApprovedLog,
            'hr_approved' => $hrApprovedLog,
            'hr_received' => $hrReceivedLog,
            'cmo_cbmo_reviewed' => $cmoCbmoReviewedLog,
            'hr_released' => $hrReleasedLog,
            'hr_recalled' => $hrRecalledLog,
            'admin_rejected' => $adminRejectedLog,
            'hr_rejected' => $hrRejectedLog,
            'cancelled' => $cancelledLog,
        ];
    }

    public function adminApproveApplication(
        LeaveApplication $app,
        DepartmentAdmin $admin,
        ?string $remarks = null,
        bool $isPendingApprovedUpdateRequest = false,
        string $pendingApprovedActionType = ''
    ): void {
        DB::transaction(function () use (
            $app,
            $admin,
            $remarks,
            $isPendingApprovedUpdateRequest,
            $pendingApprovedActionType
        ) {
            $updateAttributes = [
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'remarks' => $remarks,
            ];
            if (! $isPendingApprovedUpdateRequest) {
                $updateAttributes['admin_approved_at'] = now();
            }

            $app->update($updateAttributes);

            $logRemarks = $remarks;
            if ($this->trimNullableString($logRemarks) === null && $isPendingApprovedUpdateRequest) {
                $logRemarks = $pendingApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? 'Approved leave cancellation request and forwarded to HR.'
                    : (
                        $pendingApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                            ? 'Approved leave recall request and forwarded to HR.'
                            : 'Approved leave update request and forwarded to HR.'
                    );
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
    }

    public function adminRejectApplication(
        LeaveApplication $app,
        DepartmentAdmin $admin,
        ?string $remarks = null
    ): array {
        $status = $this->adminRejectStatusForRemarks($remarks);
        $action = $this->adminRejectLogActionForRemarks($remarks);

        DB::transaction(function () use ($app, $admin, $remarks, $status, $action): void {
            $app->update([
                'status' => $status,
                'admin_id' => $admin->id,
                'remarks' => $remarks,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => $action,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => $remarks,
                'created_at' => now(),
            ]);
        });

        return [
            'status' => $status,
            'action' => $action,
        ];
    }

    public function confirmHardCopyReceipt(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null
    ): bool {
        $receivedLog = $app->logs->first(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_RECEIVED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );

        if (! $receivedLog) {
            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_RECEIVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $remarks ?: 'Received hard copy leave application form.',
                'created_at' => now(),
            ]);
            $app->load('logs');

            return true;
        }

        return false;
    }

    public function confirmHardCopyRelease(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null
    ): array {
        $releasedLog = $app->logs->first(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_RELEASED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );

        if ($releasedLog) {
            return [
                'ok' => true,
                'already_released' => true,
            ];
        }

        $receivedLog = $app->logs->first(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_RECEIVED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );

        if (! $receivedLog) {
            return [
                'ok' => false,
                'message' => 'Cannot mark as released before confirming receipt of the hard-copy application.',
            ];
        }

        $cmoCbmoReviewedLog = $app->logs->first(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );

        if (! $cmoCbmoReviewedLog) {
            return [
                'ok' => false,
                'message' => 'Cannot mark as released before completing CMO/CVMO review.',
            ];
        }

        LeaveApplicationLog::create([
            'leave_application_id' => $app->id,
            'action' => LeaveApplicationLog::ACTION_HR_RELEASED,
            'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
            'performed_by_id' => $hr->id,
            'remarks' => $remarks ?: 'Released hard copy leave application form.',
            'created_at' => now(),
        ]);
        $app->load('logs');

        return [
            'ok' => true,
            'already_released' => false,
        ];
    }

    public function undoHardCopyReceipt(
        LeaveApplication $app,
        ?DateTimeInterface $cycleRequestedAt = null
    ): array {
        $releasedLog = $this->resolveLatestHrActionLogForCycle(
            $app,
            LeaveApplicationLog::ACTION_HR_RELEASED,
            $cycleRequestedAt
        );
        if ($releasedLog instanceof LeaveApplicationLog) {
            return [
                'ok' => false,
                'message' => 'Cannot undo receipt after release. Undo the release first.',
            ];
        }

        $reviewedLog = $this->resolveLatestHrActionLogForCycle(
            $app,
            LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED,
            $cycleRequestedAt
        );
        if ($reviewedLog instanceof LeaveApplicationLog) {
            return [
                'ok' => false,
                'message' => 'Cannot undo receipt after CMO/CVMO review has been completed.',
            ];
        }

        $receivedLog = $this->resolveLatestHrActionLogForCycle(
            $app,
            LeaveApplicationLog::ACTION_HR_RECEIVED,
            $cycleRequestedAt
        );

        if ($receivedLog instanceof LeaveApplicationLog) {
            $receivedLog->delete();
            $app->load('logs');

            return [
                'ok' => true,
                'was_undone' => true,
            ];
        }

        return [
            'ok' => true,
            'was_undone' => false,
        ];
    }

    public function undoHardCopyRelease(
        LeaveApplication $app,
        ?DateTimeInterface $cycleRequestedAt = null
    ): bool {
        $releasedLog = $this->resolveLatestHrActionLogForCycle(
            $app,
            LeaveApplicationLog::ACTION_HR_RELEASED,
            $cycleRequestedAt
        );

        if ($releasedLog instanceof LeaveApplicationLog) {
            $releasedLog->delete();
            $app->load('logs');

            return true;
        }

        return false;
    }

    public function confirmCmoCbmoReview(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null
    ): array {
        if ($app->status !== LeaveApplication::STATUS_APPROVED) {
            return [
                'ok' => false,
                'message' => "Cannot complete CMO/CVMO review: application status is '{$app->status}'.",
            ];
        }

        $receivedLog = $app->logs->first(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_RECEIVED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );

        if (! $receivedLog) {
            return [
                'ok' => false,
                'message' => 'Cannot complete CMO/CVMO review before confirming receipt of the hard-copy application.',
            ];
        }

        $reviewedLog = $app->logs->first(
            fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );

        if (! $reviewedLog) {
            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $remarks ?: 'CMO/CVMO review completed.',
                'created_at' => now(),
            ]);
            $app->load('logs');

            return [
                'ok' => true,
                'already_reviewed' => false,
            ];
        }

        return [
            'ok' => true,
            'already_reviewed' => true,
        ];
    }

    public function buildRequestedLeaveCancellationPayload(
        LeaveApplication $app,
        string $requestReason = '',
        ?callable $canonicalLeaveTypeIdResolver = null,
        ?callable $deductibleDaysResolver = null,
        ?callable $payModeNormalizer = null
    ): array {
        $rawLeaveTypeId = (int) ($app->leave_type_id ?? 0);
        $leaveTypeId = $canonicalLeaveTypeIdResolver !== null
            ? ($canonicalLeaveTypeIdResolver($rawLeaveTypeId) ?? $rawLeaveTypeId)
            : $rawLeaveTypeId;

        $deductibleDays = $deductibleDaysResolver !== null
            ? $deductibleDaysResolver($app)
            : round((float) ($app->deductible_days ?? $app->total_days ?? 0), 2);

        $payMode = $payModeNormalizer !== null
            ? $payModeNormalizer($app->pay_mode ?? null, (bool) $app->is_monetization)
            : (string) ($app->pay_mode ?? 'WITH_PAY');

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
            'deductible_days' => $deductibleDays,
            'allow_sl_vl_cross_deduction' => (bool) ($app->allow_sl_vl_cross_deduction ?? false),
            'reason' => $this->trimNullableString($requestReason) ?? $this->trimNullableString($app->reason ?? null),
            'details_of_leave' => $this->trimNullableString($app->details_of_leave ?? null),
            'cancel_reason' => $this->trimNullableString($requestReason),
            'commutation' => $this->trimNullableString($app->commutation ?? null) ?? 'Not Requested',
            'pay_mode' => $payMode,
            'is_monetization' => (bool) $app->is_monetization,
            'attachment_required' => (bool) ($app->attachment_required ?? false),
            'attachment_submitted' => (bool) ($app->attachment_submitted ?? false),
            'attachment_reference' => $this->trimNullableString($app->attachment_reference ?? null),
        ], LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL);
    }

    public function buildRequestedLeaveRecallPayload(
        LeaveApplication $app,
        string $requestReason = '',
        array $selectedRecallDateKeys = [],
        ?callable $canonicalLeaveTypeIdResolver = null,
        ?callable $deductibleDaysResolver = null,
        ?callable $payModeNormalizer = null,
        ?callable $recallDateKeyNormalizer = null
    ): array {
        $rawLeaveTypeId = (int) ($app->leave_type_id ?? 0);
        $leaveTypeId = $canonicalLeaveTypeIdResolver !== null
            ? ($canonicalLeaveTypeIdResolver($rawLeaveTypeId) ?? $rawLeaveTypeId)
            : $rawLeaveTypeId;

        $normalizedRecallDateKeys = $recallDateKeyNormalizer !== null
            ? $recallDateKeyNormalizer($selectedRecallDateKeys)
            : $this->normalizeRecallDateKeys($selectedRecallDateKeys);

        $deductibleDays = $deductibleDaysResolver !== null
            ? $deductibleDaysResolver($app)
            : round((float) ($app->deductible_days ?? $app->total_days ?? 0), 2);

        $payMode = $payModeNormalizer !== null
            ? $payModeNormalizer($app->pay_mode ?? null, (bool) $app->is_monetization)
            : (string) ($app->pay_mode ?? 'WITH_PAY');

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
            'deductible_days' => $deductibleDays,
            'allow_sl_vl_cross_deduction' => (bool) ($app->allow_sl_vl_cross_deduction ?? false),
            'reason' => $this->trimNullableString($requestReason) ?? $this->trimNullableString($app->reason ?? null),
            'recall_reason' => $this->trimNullableString($requestReason),
            'recall_selected_dates' => $normalizedRecallDateKeys,
            'details_of_leave' => $this->trimNullableString($app->details_of_leave ?? null),
            'commutation' => $this->trimNullableString($app->commutation ?? null) ?? 'Not Requested',
            'pay_mode' => $payMode,
            'is_monetization' => (bool) $app->is_monetization,
            'attachment_required' => (bool) ($app->attachment_required ?? false),
            'attachment_submitted' => (bool) ($app->attachment_submitted ?? false),
            'attachment_reference' => $this->trimNullableString($app->attachment_reference ?? null),
        ], LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL);
    }

    public function employeeCancelPendingApplication(
        LeaveApplication $app,
        int $performedById,
        string $remarks
    ): void {
        DB::transaction(function () use ($app, $remarks, $performedById): void {
            $app->update([
                'status' => LeaveApplication::STATUS_CANCELLED,
                'remarks' => $remarks,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_EMPLOYEE_CANCELLED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $performedById,
                'remarks' => $remarks,
                'created_at' => now(),
            ]);
        });
    }

    public function submitApprovedLeaveCancellationRequest(
        LeaveApplication $app,
        array $requestedUpdatePayload,
        string $remarksLine,
        string $requestReason,
        int $performedById,
        ?string $employeeControlNo = null,
        ?string $employeeName = null
    ): void {
        DB::transaction(function () use (
            $app,
            $remarksLine,
            $performedById,
            $requestedUpdatePayload,
            $requestReason,
            $employeeControlNo,
            $employeeName
        ): void {
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
                'employee_control_no' => $employeeControlNo,
                'employee_name' => $employeeName,
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

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $performedById,
                'remarks' => $remarksLine,
                'created_at' => now(),
            ]);
        });
    }

    public function hrApprovePendingCancellationRequest(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null,
        ?LeaveApplicationUpdateRequest $pendingUpdateRequest = null,
        ?string $cancelReason = null,
        ?callable $refundCallback = null,
        ?callable $ctoSyncCallback = null
    ): void {
        $cancelRemarks = $cancelReason !== null && trim($cancelReason) !== ''
            ? "Cancelled via approved leave cancellation request: {$cancelReason}"
            : 'Cancelled via approved leave cancellation request.';

        DB::transaction(function () use (
            $app,
            $hr,
            $remarks,
            $pendingUpdateRequest,
            $cancelRemarks,
            $refundCallback,
            $ctoSyncCallback
        ): void {
            if ($refundCallback !== null) {
                $refundCallback();
            }

            $app->update([
                'status' => LeaveApplication::STATUS_CANCELLED,
                'hr_id' => $hr->id,
                'hr_approved_at' => now(),
                'remarks' => $cancelRemarks,
                'deductible_days' => 0,
            ]);

            if ($ctoSyncCallback !== null) {
                $ctoSyncCallback();
            }

            if ($pendingUpdateRequest instanceof LeaveApplicationUpdateRequest) {
                $pendingUpdateRequest->update([
                    'status' => LeaveApplicationUpdateRequest::STATUS_APPROVED,
                    'reviewed_by_hr_id' => $hr->id,
                    'reviewed_at' => now(),
                    'review_remarks' => $remarks,
                ]);
            }

            $hrLogRemarks = $this->trimNullableString($remarks);
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
    }

    public function hrRejectPendingUpdateRequest(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null,
        ?LeaveApplicationUpdateRequest $pendingUpdateRequest = null,
        string $previousStatus = LeaveApplication::STATUS_APPROVED,
        string $pendingActionType = LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE
    ): void {
        if ($previousStatus !== LeaveApplication::STATUS_APPROVED) {
            $previousStatus = LeaveApplication::STATUS_APPROVED;
        }

        DB::transaction(function () use (
            $app,
            $remarks,
            $hr,
            $previousStatus,
            $pendingUpdateRequest,
            $pendingActionType
        ): void {
            $app->update([
                'status' => $previousStatus,
                'remarks' => $remarks,
            ]);

            if ($pendingUpdateRequest instanceof LeaveApplicationUpdateRequest) {
                $pendingUpdateRequest->update([
                    'status' => LeaveApplicationUpdateRequest::STATUS_REJECTED,
                    'reviewed_by_hr_id' => $hr->id,
                    'reviewed_at' => now(),
                    'review_remarks' => $remarks,
                ]);
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_REJECTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $remarks ?: (
                    $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                        ? 'Rejected leave cancellation request.'
                        : (
                            $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                                ? 'Rejected leave recall request.'
                                : 'Rejected leave update request.'
                        )
                ),
                'created_at' => now(),
            ]);
        });
    }

    public function adminRejectPendingUpdateRequest(
        LeaveApplication $app,
        DepartmentAdmin $admin,
        ?string $remarks = null,
        ?LeaveApplicationUpdateRequest $pendingUpdateRequest = null,
        string $previousStatus = LeaveApplication::STATUS_APPROVED,
        string $pendingActionType = LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE
    ): void {
        if ($previousStatus !== LeaveApplication::STATUS_APPROVED) {
            $previousStatus = LeaveApplication::STATUS_APPROVED;
        }

        DB::transaction(function () use (
            $app,
            $remarks,
            $admin,
            $previousStatus,
            $pendingUpdateRequest,
            $pendingActionType
        ): void {
            $app->update([
                'status' => $previousStatus,
                'admin_id' => $admin->id,
                'remarks' => $remarks,
            ]);

            if ($pendingUpdateRequest instanceof LeaveApplicationUpdateRequest) {
                $pendingUpdateRequest->update([
                    'status' => LeaveApplicationUpdateRequest::STATUS_REJECTED,
                    'reviewed_at' => now(),
                    'review_remarks' => $remarks,
                ]);
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_ADMIN_REJECTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => $remarks ?: (
                    $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                        ? 'Rejected leave cancellation request.'
                        : (
                            $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                                ? 'Rejected leave recall request.'
                                : 'Rejected leave update request.'
                        )
                ),
                'created_at' => now(),
            ]);
        });
    }

    public function getApprovedCancellationCondition(): \Closure
    {
        $updatesTable = (new LeaveApplicationUpdateRequest)->getTable();
        $appsTable = (new LeaveApplication)->getTable();
        $logsTable = (new LeaveApplicationLog)->getTable();

        return static function ($q) use ($updatesTable, $appsTable, $logsTable): void {
            $q->where(function ($sub): void {
                $sub->where('remarks', 'LIKE', '%approved leave cancellation%')
                    ->orWhere('remarks', 'LIKE', '%Cancelled via approved%');
            })->orWhereExists(function ($reqQuery) use ($updatesTable, $appsTable): void {
                $reqQuery->select(DB::raw(1))
                    ->from("{$updatesTable} as req")
                    ->whereColumn('req.leave_application_id', "{$appsTable}.id")
                    ->where('req.status', LeaveApplicationUpdateRequest::STATUS_APPROVED)
                    ->where(function ($sub): void {
                        $sub->where('req.requested_payload', 'LIKE', '%REQUEST_CANCEL%')
                            ->orWhere('req.requested_payload', 'LIKE', '%REQUEST_CANCELLATION%')
                            ->orWhere('req.requested_payload', 'LIKE', '%"cancel_leave":true%')
                            ->orWhere('req.requested_payload', 'LIKE', '%"request_kind":"cancel"%');
                    });
            })->orWhereExists(function ($logQuery) use ($logsTable, $appsTable): void {
                $logQuery->select(DB::raw(1))
                    ->from("{$logsTable} as log")
                    ->whereColumn('log.leave_application_id', "{$appsTable}.id")
                    ->where('log.action', LeaveApplicationLog::ACTION_HR_APPROVED)
                    ->where('log.remarks', 'LIKE', '%cancel%');
            });
        };
    }

    public function buildCancelledApplicationsBaseQuery(
        array $searchTokens = [],
        string $department = '',
        ?string $fromDate = null,
        ?string $toDate = null
    ): Builder {
        $baseQuery = LeaveApplication::query()
            ->where('status', LeaveApplication::STATUS_CANCELLED);

        if ($searchTokens !== []) {
            $baseQuery->where(function ($q) use ($searchTokens): void {
                foreach ($searchTokens as $token) {
                    $likeToken = "%{$token}%";
                    $q->where(function ($sub) use ($likeToken): void {
                        $sub->where('id', 'like', $likeToken)
                            ->orWhere('employee_control_no', 'like', $likeToken)
                            ->orWhere('employee_name', 'like', $likeToken)
                            ->orWhere('total_days', 'like', $likeToken)
                            ->orWhere('remarks', 'like', $likeToken)
                            ->orWhereHas('applicantAdmin', function ($qAdmin) use ($likeToken): void {
                                $qAdmin->where('full_name', 'like', $likeToken)
                                    ->orWhereHas('department', function ($qDept) use ($likeToken): void {
                                        $qDept->where('name', 'like', $likeToken);
                                    });
                            })
                            ->orWhereHas('leaveType', function ($qType) use ($likeToken): void {
                                $qType->where('name', 'like', $likeToken);
                            });
                    });
                }
            });
        }

        if ($department !== '') {
            $baseQuery->whereHas('applicantAdmin.department', function ($q) use ($department): void {
                $q->where('name', $department);
            });
        }

        if ($fromDate && $toDate) {
            $baseQuery->where(function ($q) use ($fromDate, $toDate): void {
                $q->whereBetween('updated_at', [$fromDate.' 00:00:00', $toDate.' 23:59:59'])
                    ->orWhereBetween('created_at', [$fromDate.' 00:00:00', $toDate.' 23:59:59']);
            });
        }

        return $baseQuery;
    }

    public function hrRejectApplication(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null
    ): void {
        DB::transaction(function () use ($app, $hr, $remarks): void {
            $app->update([
                'status' => LeaveApplication::STATUS_REJECTED,
                'hr_id' => $hr->id,
                'remarks' => $remarks,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_REJECTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $remarks,
                'created_at' => now(),
            ]);
        });
    }

    public function hasHrReceivedForQueue(
        LeaveApplication $application,
        ?callable $hasPendingApprovedUpdateResolver = null,
        ?callable $pendingCycleResolver = null
    ): bool {
        $hasPending = $hasPendingApprovedUpdateResolver !== null
            ? $hasPendingApprovedUpdateResolver($application)
            : $this->hasPendingApprovedUpdateRequest($application);

        $cycleRequestedAt = $hasPending
            ? ($pendingCycleResolver !== null ? $pendingCycleResolver($application) : $this->resolvePendingApprovedUpdateCycleRequestedAt($application))
            : null;

        return $this->resolveLatestHrActionLogForCycle(
            $application,
            LeaveApplicationLog::ACTION_HR_RECEIVED,
            $cycleRequestedAt
        ) instanceof LeaveApplicationLog;
    }

    public function hasHrReleasedForQueue(
        LeaveApplication $application,
        ?callable $latestCycleResolver = null
    ): bool {
        $cycleRequestedAt = $latestCycleResolver !== null
            ? $latestCycleResolver($application)
            : $this->resolveLatestApprovedUpdateCycleRequestedAt($application);

        return $this->resolveLatestHrActionLogForCycle(
            $application,
            LeaveApplicationLog::ACTION_HR_RELEASED,
            $cycleRequestedAt
        ) instanceof LeaveApplicationLog;
    }

    public function hasCmoCbmoReviewedForQueue(
        LeaveApplication $application,
        ?callable $latestCycleResolver = null
    ): bool {
        $cycleRequestedAt = $latestCycleResolver !== null
            ? $latestCycleResolver($application)
            : $this->resolveLatestApprovedUpdateCycleRequestedAt($application);

        return $this->resolveLatestHrActionLogForCycle(
            $application,
            LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED,
            $cycleRequestedAt
        ) instanceof LeaveApplicationLog;
    }

    public function matchesHrApplicationFilters(
        LeaveApplication $application,
        array $searchTokens,
        string $employmentType,
        ?callable $employeeResolver = null,
        ?callable $queueMetaResolver = null
    ): bool {
        $employee = $employeeResolver !== null ? $employeeResolver($application) : null;

        if (
            $employmentType !== ''
            && LeaveType::normalizeEmploymentStatusKey($employee?->status) !== $employmentType
        ) {
            return false;
        }

        if ($searchTokens === []) {
            return true;
        }

        $queueMeta = $queueMetaResolver !== null
            ? $queueMetaResolver($application)
            : $this->resolveLeaveHrQueueMeta($application);

        $selectedDates = collect($application->resolvedSelectedDates() ?? [])
            ->flatMap(fn (string $date): array => [
                $date,
                CarbonImmutable::parse($date)->format('F j Y'),
            ])
            ->all();

        $searchableValues = [
            'application',
            $application->id,
            $application->employee_control_no,
            $application->employee_name,
            $application->applicantAdmin?->full_name,
            self::formatEmployeeFullName($employee),
            $employee?->office,
            $employee?->officeAcronym,
            $application->applicantAdmin?->department?->name,
            $application->leaveType?->name,
            $application->status,
            $this->statusToFrontend((string) $application->status),
            $queueMeta['group_status'] ?? null,
            $queueMeta['stage_key'] ?? null,
            $this->hrQueueStageSearchLabel((string) ($queueMeta['stage_key'] ?? '')),
            $application->total_days,
            self::formatDays($application->total_days),
            $application->created_at?->toDateString(),
            $application->created_at?->format('F j Y'),
            $application->start_date?->toDateString(),
            $application->start_date?->format('F j Y'),
            $application->end_date?->toDateString(),
            $application->end_date?->format('F j Y'),
            ...$selectedDates,
        ];
        $haystack = $this->normalizeHrApplicationSearchValue(implode(' ', array_filter(
            $searchableValues,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        )));

        foreach ($searchTokens as $searchToken) {
            if (! str_contains($haystack, $searchToken)) {
                return false;
            }
        }

        return true;
    }

    public function resolveLatestRecallLoggedAt(LeaveApplication $app): ?DateTimeInterface
    {
        $logs = $app->relationLoaded('logs')
            ? $app->logs
            : $app->logs()->get();

        if (! $logs instanceof Collection || $logs->isEmpty()) {
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

    public function resolveEffectiveStoredRecallDateKeys(
        LeaveApplication $app,
        ?callable $latestUpdateMetaResolver = null
    ): array {
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

        $latestUpdateMeta = is_callable($latestUpdateMetaResolver)
            ? $latestUpdateMetaResolver($app)
            : [
                'status' => $this->getLatestUpdateRequestRecord($app)?->status,
                'reviewed_at' => $this->getLatestUpdateRequestRecord($app)?->reviewed_at,
            ];
        $latestUpdateStatus = strtoupper(trim((string) ($latestUpdateMeta['status'] ?? '')));
        $latestUpdateReviewedAt = $latestUpdateMeta['reviewed_at'] ?? null;
        $latestRecallLoggedAt = $this->resolveLatestRecallLoggedAt($app);

        if (
            $latestUpdateStatus === LeaveApplicationUpdateRequest::STATUS_APPROVED
            && $latestUpdateReviewedAt instanceof DateTimeInterface
            && $latestRecallLoggedAt instanceof DateTimeInterface
            && $latestUpdateReviewedAt->getTimestamp() > $latestRecallLoggedAt->getTimestamp()
        ) {
            $selectedDateSet = array_fill_keys($selectedDateKeys, true);
            $storedRecallDateKeys = array_values(array_filter(
                $storedRecallDateKeys,
                static fn (string $dateKey): bool => ! isset($selectedDateSet[$dateKey])
            ));
        }

        $storedRecallDateKeys = array_values(array_unique($storedRecallDateKeys));
        sort($storedRecallDateKeys);

        return $storedRecallDateKeys;
    }

    public function resolveRecallSelectedDateKeys(
        LeaveApplication $app,
        ?callable $latestUpdateMetaResolver = null
    ): array {
        $selectedDates = $app->resolvedSelectedDates();
        if (! is_array($selectedDates) || $selectedDates === []) {
            return [];
        }

        $storedRecallDateSet = array_fill_keys(
            $this->resolveEffectiveStoredRecallDateKeys($app, $latestUpdateMetaResolver),
            true
        );

        $normalizedDateKeys = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->calculationService->normalizeDateKey($rawDate) ?? trim((string) $rawDate);
            if ($dateKey === '' || isset($storedRecallDateSet[$dateKey])) {
                continue;
            }

            $normalizedDateKeys[] = $dateKey;
        }

        $normalizedDateKeys = array_values(array_unique($normalizedDateKeys));
        sort($normalizedDateKeys);

        return $normalizedDateKeys;
    }

    public function resolveValidatedRecallSelectedDateKeys(
        LeaveApplication $app,
        array $requestedDates = [],
        ?callable $latestUpdateMetaResolver = null
    ): ?array {
        $allowedDateKeys = $this->resolveRecallSelectedDateKeys($app, $latestUpdateMetaResolver);
        $requestedDateKeys = $this->normalizeRecallDateKeys($requestedDates);

        if ($requestedDateKeys === [] || $allowedDateKeys === []) {
            return null;
        }

        $allowedDateSet = array_fill_keys($allowedDateKeys, true);
        foreach ($requestedDateKeys as $dateKey) {
            if (! isset($allowedDateSet[$dateKey])) {
                return null;
            }
        }

        return $requestedDateKeys;
    }

    public function resolveRecallRestorableDetails(
        LeaveApplication $app,
        array $selectedRecallDateKeys = []
    ): array {
        $deductibleDays = $this->calculationService->resolveApplicationDeductibleDays($app);
        if ($deductibleDays <= 0.0) {
            return ['days' => 0.0, 'dates' => []];
        }

        if ((bool) $app->is_monetization) {
            return ['days' => $deductibleDays, 'dates' => []];
        }

        $selectedDates = $app->resolvedSelectedDates();
        if (! is_array($selectedDates) || $selectedDates === []) {
            return ['days' => $deductibleDays, 'dates' => []];
        }

        $normalizedPayMode = $this->calculationService->normalizePayMode($app->pay_mode ?? null, false);
        $normalizedPayStatus = $this->calculationService->compactSelectedDatePayStatusMap(
            is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null,
            $selectedDates,
            $normalizedPayMode
        );
        $normalizedCoverage = $this->calculationService->compactSelectedDateCoverageMap(
            is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
            $selectedDates
        );
        $coverageWeights = $this->calculationService->resolveDateCoverageWeights(
            $selectedDates,
            $normalizedCoverage,
            round((float) ($app->total_days ?? 0), 3),
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
            $dateKey = $this->calculationService->normalizeDateKey($rawDate) ?? trim((string) $rawDate);
            if ($dateKey === '' || ! isset($selectedRecallDateSet[$dateKey])) {
                continue;
            }

            $weight = round(max((float) ($coverageWeights[$dateKey] ?? 1.0), 0.0), 3);
            if ($weight <= 0.0) {
                continue;
            }

            $effectiveMode = $normalizedPayStatus[$dateKey] ?? $normalizedPayMode;
            $resolvedMode = $this->calculationService->resolvePayModeFromStatusValue($effectiveMode) ?? $normalizedPayMode;
            if ($resolvedMode !== LeaveApplication::PAY_MODE_WITH_PAY) {
                continue;
            }

            $restorableDays += $weight;
            $restorableDateKeys[] = $dateKey;
        }

        $restorableDays = round(max($restorableDays, 0.0), 3);
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

    public function executeHrRecall(
        LeaveApplication $app,
        HRAccount $hr,
        string $reason,
        array $requestedRecallDates,
        ?LeaveApplicationUpdateRequest $pendingUpdateRequest = null,
        mixed $pendingReviewRemarks = null,
        ?callable $formatApplicationCallback = null
    ): JsonResponse {
        if ((bool) $app->is_monetization) {
            return response()->json([
                'message' => 'Monetization requests cannot be recalled.',
            ], 422);
        }

        $allowedStatuses = $pendingUpdateRequest instanceof LeaveApplicationUpdateRequest
            ? [LeaveApplication::STATUS_PENDING_HR, LeaveApplication::STATUS_APPROVED]
            : [LeaveApplication::STATUS_APPROVED];
        if (! in_array($app->status, $allowedStatuses, true)) {
            return response()->json([
                'message' => $pendingUpdateRequest instanceof LeaveApplicationUpdateRequest
                    ? "Cannot approve recall request: application status is '{$app->status}'."
                    : "Cannot recall: application status is '{$app->status}', expected 'APPROVED'.",
            ], 422);
        }

        $leaveType = $app->leaveType ?? LeaveType::find((int) $app->leave_type_id);
        if (! $leaveType) {
            return response()->json([
                'message' => 'Selected leave type is no longer available.',
            ], 422);
        }
        $app->setRelation('leaveType', $leaveType);

        $forcedLeaveTypeId = $this->creditService->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->creditService->resolveVacationLeaveTypeId();
        $normalizedLeaveTypeName = strtolower(trim((string) $leaveType->name));
        $isRecallableLeaveType = ($forcedLeaveTypeId !== null && (int) $app->leave_type_id === $forcedLeaveTypeId)
            || ($vacationLeaveTypeId !== null && (int) $app->leave_type_id === $vacationLeaveTypeId)
            || in_array($normalizedLeaveTypeName, ['mandatory / forced leave', 'vacation leave'], true);

        if (! $isRecallableLeaveType) {
            return response()->json([
                'message' => 'Only Vacation Leave and Mandatory / Forced Leave applications can be recalled.',
            ], 422);
        }

        $selectedRecallDateKeys = $this->resolveValidatedRecallSelectedDateKeys(
            $app,
            $requestedRecallDates
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
        $effectiveRecallDate = CarbonImmutable::parse($selectedRecallDateKeys[0])->startOfDay();

        $normalizedPayMode = $this->calculationService->normalizePayMode($app->pay_mode ?? null, (bool) $app->is_monetization);
        $deductsBalance = $this->creditService->applicationDeductsEmployeeBalance(
            (bool) $app->is_monetization,
            $leaveType,
            $normalizedPayMode
        );
        $recallDetails = $deductsBalance
            ? $this->resolveRecallRestorableDetails($app, $selectedRecallDateKeys)
            : ['days' => 0.0, 'dates' => []];
        $daysToRestore = round((float) ($recallDetails['days'] ?? 0.0), 3);

        $applicationDeductibleDays = $this->calculationService->resolveApplicationDeductibleDays($app);
        $primaryRestoreDays = min(
            $daysToRestore,
            $this->creditService->resolveStoredPrimaryLeaveDeduction(
                $app,
                $leaveType,
                $applicationDeductibleDays
            )
        );
        $linkedForcedRestoreDays = min(
            $daysToRestore,
            $this->creditService->resolveStoredLinkedForcedLeaveDeduction(
                $app,
                $leaveType,
                $applicationDeductibleDays
            )
        );
        $linkedVacationRestoreDays = min(
            $daysToRestore,
            $this->creditService->resolveStoredLinkedVacationLeaveDeduction(
                $app,
                $leaveType,
                $applicationDeductibleDays
            )
        );
        $recallRemarks = $reason !== ''
            ? "Recalled by HR: {$reason}"
            : 'Recalled by HR';

        $balanceConflictError = 'HR_RECALL_BALANCE_CONFLICT';

        try {
            DB::transaction(function () use (
                $app,
                $hr,
                $primaryRestoreDays,
                $linkedForcedRestoreDays,
                $linkedVacationRestoreDays,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId,
                $recallRemarks,
                $effectiveRecallDate,
                $mergedRecallDateKeys,
                $isFullyRecalled,
                $pendingUpdateRequest,
                $pendingReviewRemarks
            ): void {
                if ($primaryRestoreDays > 0.0) {
                    $this->creditService->restoreApplicationBalance($app, (int) $app->leave_type_id, $primaryRestoreDays);
                }

                if ($linkedForcedRestoreDays > 0.0 && $forcedLeaveTypeId !== null) {
                    $this->creditService->restoreApplicationBalance($app, $forcedLeaveTypeId, $linkedForcedRestoreDays);
                }

                if ($linkedVacationRestoreDays > 0.0 && $vacationLeaveTypeId !== null) {
                    $this->creditService->restoreApplicationBalance($app, $vacationLeaveTypeId, $linkedVacationRestoreDays);
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

                if ($pendingUpdateRequest instanceof LeaveApplicationUpdateRequest) {
                    $pendingUpdateRequest->update([
                        'status' => LeaveApplicationUpdateRequest::STATUS_APPROVED,
                        'reviewed_by_hr_id' => $hr->id,
                        'reviewed_at' => now(),
                        'review_remarks' => $this->trimNullableString($pendingReviewRemarks),
                    ]);
                }

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
                $pendingUpdateRequest instanceof LeaveApplicationUpdateRequest
                    ? 'Recall Request Approved by HR'
                    : 'Leave Recalled by HR',
                $pendingUpdateRequest instanceof LeaveApplicationUpdateRequest
                    ? "Your {$app->leaveType->name} recall request was approved by HR.".($reason !== '' ? " Reason: {$reason}" : '')
                    : "Your {$app->leaveType->name} application was recalled by HR.".($reason !== '' ? " Reason: {$reason}" : ''),
                $app->id
            );
        }

        $formattedApp = is_callable($formatApplicationCallback)
            ? $formatApplicationCallback($app->fresh(['leaveType', 'applicantAdmin']))
            : $app->fresh(['leaveType', 'applicantAdmin']);

        return response()->json([
            'message' => $pendingUpdateRequest instanceof LeaveApplicationUpdateRequest
                ? ($isFullyRecalled
                    ? 'Recall request approved by HR. Application recalled.'
                    : 'Recall request approved by HR. Selected leave dates were recalled.')
                : ($isFullyRecalled
                    ? 'Application recalled by HR.'
                    : 'Selected leave dates recalled by HR.'),
            'application' => $formattedApp,
        ]);
    }

    public function normalizeBooleanFlag(mixed $value): ?bool
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

    public function resolvePayloadSlVlCrossDeductionFlag(array $payload, ?bool $fallback = null): bool
    {
        $candidateKeys = [
            'allow_sl_vl_cross_deduction',
            'allowSlVlCrossDeduction',
            'use_other_sl_vl_balance',
            'useOtherSlVlBalance',
        ];

        foreach ($candidateKeys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $normalizedFlag = $this->normalizeBooleanFlag($payload[$key]);
            if ($normalizedFlag !== null) {
                return $normalizedFlag;
            }
        }

        return $fallback === true;
    }

    public function resolveAttachmentStateFromPayload(
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
            if (! array_key_exists($key, $payload)) {
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
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $candidate = $this->trimNullableString($payload[$key]);
            if ($candidate !== null) {
                $reference = $candidate;
                $submitted = true;
                break;
            }
        }

        if ($reference !== null) {
            $submitted = true;
        } elseif (! $submitted) {
            $reference = null;
        }

        return [
            'attachment_submitted' => $submitted,
            'attachment_reference' => $reference,
        ];
    }

    public function resolvePendingUpdateMeta(
        LeaveApplication $app,
        ?callable $policyResolver = null
    ): array {
        $pendingRequest = $this->getPendingApprovedUpdateRequestRecord($app);
        if ($pendingRequest instanceof LeaveApplicationUpdateRequest) {
            return [
                'payload' => $this->normalizePendingUpdatePayload($pendingRequest->requested_payload, $policyResolver),
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

    public function resolveLatestUpdateMeta(
        LeaveApplication $app,
        ?callable $policyResolver = null
    ): array {
        $latestRequest = $this->getLatestUpdateRequestRecord($app);
        if ($latestRequest instanceof LeaveApplicationUpdateRequest) {
            return [
                'status' => strtoupper(trim((string) ($latestRequest->status ?? ''))),
                'payload' => $this->normalizePendingUpdatePayload($latestRequest->requested_payload, $policyResolver),
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

    public function normalizePendingUpdatePayload(
        mixed $payload,
        ?callable $policyResolver = null
    ): ?array {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        if (! is_array($payload) || $payload === []) {
            return null;
        }
        $actionType = $this->resolveUpdateRequestActionTypeFromPayload($payload);

        if ($actionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL) {
            $leaveTypeId = $this->creditService->resolveCanonicalLeaveTypeId((int) ($payload['leave_type_id'] ?? 0))
                ?? (int) ($payload['leave_type_id'] ?? 0);
            $leaveTypeName = $this->trimNullableString($payload['leave_type_name'] ?? null);
            $recallReason = $this->trimNullableString(
                $payload['recall_reason']
                ?? $payload['reason']
                ?? $payload['reason_purpose']
                ?? $payload['remarks']
                ?? null
            );
            $selectedDates = is_array($payload['selected_dates'] ?? null)
                ? LeaveApplication::resolveDateSet(null, null, $payload['selected_dates'], null)
                : null;
            $recallSelectedDates = $this->normalizeRecallDateKeys(
                is_array($payload['recall_selected_dates'] ?? null)
                    ? $payload['recall_selected_dates']
                    : (is_array($selectedDates) ? $selectedDates : [])
            );
            $totalDays = round((float) (
                $payload['total_days']
                ?? ($recallSelectedDates !== [] ? count($recallSelectedDates) : 0)
            ), 2);

            return [
                'action_type' => LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL,
                'request_kind' => 'recall',
                'cancel_leave' => false,
                'recall_leave' => true,
                'leave_type_id' => $leaveTypeId > 0 ? $leaveTypeId : null,
                'leave_type_name' => $leaveTypeName,
                'start_date' => $this->trimNullableString($payload['start_date'] ?? null),
                'end_date' => $this->trimNullableString($payload['end_date'] ?? null),
                'selected_dates' => $selectedDates,
                'recall_selected_dates' => $recallSelectedDates,
                'total_days' => $totalDays,
                'deductible_days' => round((float) ($payload['deductible_days'] ?? $totalDays), 3),
                'reason' => $recallReason,
                'recall_reason' => $recallReason,
                'details_of_leave' => $this->trimNullableString(
                    $payload['details_of_leave'] ?? $payload['detailsOfLeave'] ?? null
                ),
                'commutation' => $this->trimNullableString($payload['commutation'] ?? null) ?? 'Not Requested',
                'pay_mode' => $this->calculationService->normalizePayMode(
                    $payload['pay_mode'] ?? null,
                    (bool) ($payload['is_monetization'] ?? false)
                ),
                'is_monetization' => (bool) ($payload['is_monetization'] ?? false),
            ];
        }

        if ($actionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL) {
            $isMonetization = (bool) ($payload['is_monetization'] ?? false);
            $payMode = $this->calculationService->normalizePayMode($payload['pay_mode'] ?? null, $isMonetization);
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
            if (! $isMonetization && $totalDays <= 0 && is_array($selectedDates) && $selectedDates !== []) {
                $totalDays = (float) count($selectedDates);
            }
            $leaveTypeId = $this->creditService->resolveCanonicalLeaveTypeId((int) ($payload['leave_type_id'] ?? 0))
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
                'selected_date_half_day_portion' => $this->calculationService->mergeSelectedDateHalfDayPortionMaps(
                    $this->calculationService->normalizeSelectedDateHalfDayPortionMap($payload['selected_date_half_day_portion'] ?? null),
                    $this->calculationService->normalizeSelectedDateHalfDayPortionMap($payload['selected_date_coverage'] ?? null)
                ),
                'total_days' => $totalDays,
                'deductible_days' => round((float) ($payload['deductible_days'] ?? $totalDays), 3),
                'reason' => $cancelReason,
                'details_of_leave' => $this->trimNullableString($payload['details_of_leave'] ?? $payload['detailsOfLeave'] ?? null),
                'cancel_reason' => $cancelReason,
                'pay_mode' => $payMode,
                'pay_status' => $withoutPay ? 'Without Pay' : 'With Pay',
                'without_pay' => $withoutPay,
                'with_pay' => ! $withoutPay,
                'is_monetization' => $isMonetization,
            ];
        }

        $isMonetization = (bool) ($payload['is_monetization'] ?? false);
        $payMode = $this->calculationService->normalizePayMode($payload['pay_mode'] ?? null, $isMonetization);
        $allowSlVlCrossDeduction = $this->resolvePayloadSlVlCrossDeductionFlag($payload);
        $selectedDatesInput = is_array($payload['selected_dates'] ?? null)
            ? LeaveApplication::resolveDateSet(null, null, $payload['selected_dates'], null)
            : null;
        $selectedDatePayStatus = $isMonetization
            ? null
            : $this->calculationService->normalizeSelectedDatePayStatusMap($payload['selected_date_pay_status'] ?? null);
        $selectedDateCoverage = $isMonetization
            ? null
            : $this->calculationService->normalizeSelectedDateCoverageMap($payload['selected_date_coverage'] ?? null);
        $selectedDateHalfDayPortion = $isMonetization
            ? null
            : $this->calculationService->mergeSelectedDateHalfDayPortionMaps(
                $this->calculationService->normalizeSelectedDateHalfDayPortionMap($payload['selected_date_half_day_portion'] ?? null),
                $this->calculationService->normalizeSelectedDateHalfDayPortionMap($payload['selected_date_coverage'] ?? null)
            );

        $startDate = $isMonetization ? null : $this->trimNullableString($payload['start_date'] ?? null);
        $endDate = $isMonetization ? null : $this->trimNullableString($payload['end_date'] ?? null);

        $commutation = $isMonetization
            ? LeaveApplication::MONETIZATION_REQUIRED_COMMUTATION
            : ($this->trimNullableString($payload['commutation'] ?? null) ?? 'Not Requested');
        $leaveTypeId = $this->creditService->resolveCanonicalLeaveTypeId((int) ($payload['leave_type_id'] ?? 0))
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

        if (! $isMonetization && is_array($selectedDates) && $selectedDates !== []) {
            $startDate ??= $selectedDates[0];
            $endDate ??= $selectedDates[count($selectedDates) - 1];
        }

        $selectedDatePayStatus = $isMonetization
            ? null
            : $this->calculationService->compactSelectedDatePayStatusMap(
                $selectedDatePayStatus,
                $selectedDates,
                $payMode
            );
        $selectedDateCoverageMetadata = $isMonetization
            ? [
                'selectedDateCoverage' => null,
                'selectedDateHalfDayPortion' => null,
            ]
            : $this->calculationService->synchronizeSelectedDateCoverageMetadata(
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
            if (! array_key_exists($leaveTypeId, $leaveTypeNameCache)) {
                $leaveTypeNameCache[$leaveTypeId] = LeaveType::query()
                    ->whereKey($leaveTypeId)
                    ->value('name');
            }

            $resolvedLeaveTypeName = $leaveTypeNameCache[$leaveTypeId];
            $leaveTypeName = is_string($resolvedLeaveTypeName) ? trim($resolvedLeaveTypeName) : null;
            $leaveType = LeaveType::find($leaveTypeId);
        }

        if ($leaveType instanceof LeaveType && is_callable($policyResolver)) {
            $policyResolution = $policyResolver(
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
                isset($payload['employee_control_no']) ? (string) $payload['employee_control_no'] : null,
                $payMode === LeaveApplication::PAY_MODE_WITH_PAY || (bool) ($payload['with_pay'] ?? false) || ((float) ($payload['without_pay_days'] ?? 1.0) === 0.0),
                $this->trimNullableString($payload['details_of_leave'] ?? $payload['detailsOfLeave'] ?? null)
            );

            if (! ($policyResolution instanceof JsonResponse)) {
                $payMode = $policyResolution['pay_mode'];
                $selectedDatePayStatus = $policyResolution['selected_date_pay_status'];
                $deductibleDays = (float) ($policyResolution['deductible_days'] ?? 0);
                $ctoDeductedHours = (float) ($policyResolution['cto_deducted_hours'] ?? 0);
                $attachmentRequired = (bool) ($policyResolution['attachment_required'] ?? false);
                $attachmentSubmitted = (bool) ($policyResolution['attachment_submitted'] ?? false);
                $attachmentReference = $policyResolution['attachment_reference'] ?? null;
            } else {
                $deductibleDays = $this->calculationService->computeDeductibleDays(
                    $totalDays,
                    is_array($selectedDates) ? $selectedDates : null,
                    $selectedDatePayStatus,
                    $selectedDateCoverage,
                    $isMonetization,
                    $payMode,
                    isset($payload['employee_control_no']) ? (string) $payload['employee_control_no'] : null
                );
                $ctoDeductedHours = $leaveType && $this->creditService->isCtoLeaveType($leaveType, $leaveTypeId)
                    ? $this->calculationService->resolveCtoDeductedHours($deductibleDays)
                    : 0.0;
            }
        } else {
            $deductibleDays = $this->calculationService->computeDeductibleDays(
                $totalDays,
                is_array($selectedDates) ? $selectedDates : null,
                $selectedDatePayStatus,
                $selectedDateCoverage,
                $isMonetization,
                $payMode,
                isset($payload['employee_control_no']) ? (string) $payload['employee_control_no'] : null
            );
            $ctoDeductedHours = $leaveType && $this->creditService->isCtoLeaveType($leaveType, $leaveTypeId)
                ? $this->calculationService->resolveCtoDeductedHours($deductibleDays)
                : 0.0;
        }

        $withoutPay = $payMode === LeaveApplication::PAY_MODE_WITHOUT_PAY;

        $previousLeaveTypeId = isset($payload['previous_leave_type_id'])
            ? (int) $payload['previous_leave_type_id']
            : (isset($payload['previousLeaveTypeId']) ? (int) $payload['previousLeaveTypeId'] : null);
        $previousLeaveTypeName = $this->trimNullableString($payload['previous_leave_type_name'] ?? $payload['previousLeaveTypeName'] ?? null);
        if ($previousLeaveTypeName === null && $previousLeaveTypeId > 0) {
            if (! array_key_exists($previousLeaveTypeId, $leaveTypeNameCache)) {
                $leaveTypeNameCache[$previousLeaveTypeId] = LeaveType::query()
                    ->whereKey($previousLeaveTypeId)
                    ->value('name');
            }
            $previousLeaveTypeName = $this->trimNullableString($leaveTypeNameCache[$previousLeaveTypeId] ?? null);
        }

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
            'allow_sl_vl_cross_deduction' => $allowSlVlCrossDeduction,
            'pay_status' => $withoutPay ? 'Without Pay' : 'With Pay',
            'without_pay' => $withoutPay,
            'with_pay' => ! $withoutPay,
            'action_type' => $actionType,
            'request_kind' => $this->trimNullableString($payload['request_kind'] ?? null),
            'is_monetization' => $isMonetization,
            'attachment_required' => $attachmentRequired,
            'attachment_submitted' => $attachmentSubmitted,
            'attachment_reference' => $this->trimNullableString($attachmentReference),
            'previous_leave_type_id' => $previousLeaveTypeId > 0 ? $previousLeaveTypeId : null,
            'previous_leave_type_name' => $previousLeaveTypeName,
            'previous_details_of_leave' => $this->trimNullableString($payload['previous_details_of_leave'] ?? $payload['previousDetailsOfLeave'] ?? null),
            'previous_reason' => $this->trimNullableString($payload['previous_reason'] ?? $payload['previousReason'] ?? null),
            'previous_start_date' => $this->trimNullableString($payload['previous_start_date'] ?? $payload['previousStartDate'] ?? null),
            'previous_end_date' => $this->trimNullableString($payload['previous_end_date'] ?? $payload['previousEndDate'] ?? null),
            'previous_selected_dates' => is_array($payload['previous_selected_dates'] ?? null)
                ? $payload['previous_selected_dates']
                : (is_array($payload['previousSelectedDates'] ?? null) ? $payload['previousSelectedDates'] : null),
            'previous_selected_date_pay_status' => is_array($payload['previous_selected_date_pay_status'] ?? null)
                ? $payload['previous_selected_date_pay_status']
                : (is_array($payload['previousSelectedDatePayStatus'] ?? null) ? $payload['previousSelectedDatePayStatus'] : null),
            'previous_selected_date_coverage' => is_array($payload['previous_selected_date_coverage'] ?? null)
                ? $payload['previous_selected_date_coverage']
                : (is_array($payload['previousSelectedDateCoverage'] ?? null) ? $payload['previousSelectedDateCoverage'] : null),
            'previous_selected_date_half_day_portion' => is_array($payload['previous_selected_date_half_day_portion'] ?? null)
                ? $payload['previous_selected_date_half_day_portion']
                : (is_array($payload['previousSelectedDateHalfDayPortion'] ?? null) ? $payload['previousSelectedDateHalfDayPortion'] : null),
            'previous_pay_mode' => $this->trimNullableString($payload['previous_pay_mode'] ?? $payload['previousPayMode'] ?? null),
            'previous_without_pay_days' => isset($payload['previous_without_pay_days'])
                ? (float) $payload['previous_without_pay_days']
                : (isset($payload['previousWithoutPayDays']) ? (float) $payload['previousWithoutPayDays'] : null),
            'previous_total_days' => isset($payload['previous_total_days'])
                ? (float) $payload['previous_total_days']
                : (isset($payload['previousTotalDays']) ? (float) $payload['previousTotalDays'] : null),
        ];
    }

    public function submitApprovedLeaveRecallRequest(
        LeaveApplication $app,
        DepartmentAdmin $admin,
        array $requestedUpdatePayload,
        string $remarksLine,
        string $reason,
        string $requestedByControlNo,
        ?string $employeeControlNo = null,
        ?string $employeeName = null
    ): void {
        DB::transaction(function () use (
            $app,
            $admin,
            $remarksLine,
            $requestedUpdatePayload,
            $reason,
            $requestedByControlNo,
            $employeeControlNo,
            $employeeName
        ): void {
            $existingRemarks = trim((string) ($app->remarks ?? ''));
            $updatedRemarks = $existingRemarks === ''
                ? $remarksLine
                : "{$existingRemarks}\n{$remarksLine}";

            $app->update([
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'remarks' => $updatedRemarks,
            ]);

            $pendingRequest = LeaveApplicationUpdateRequest::query()
                ->where('leave_application_id', (int) $app->id)
                ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
                ->whereRaw('UPPER(LTRIM(RTRIM(previous_status))) = ?', [LeaveApplication::STATUS_APPROVED])
                ->latest('id')
                ->first();

            $requestPayload = [
                'employee_control_no' => $employeeControlNo,
                'employee_name' => $employeeName,
                'requested_payload' => $requestedUpdatePayload,
                'requested_reason' => $reason !== '' ? $reason : null,
                'previous_status' => LeaveApplication::STATUS_APPROVED,
                'requested_by_control_no' => $requestedByControlNo !== '' ? $requestedByControlNo : (string) ($app->employee_control_no ?? ''),
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

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_ADMIN,
                'performed_by_id' => $admin->id,
                'remarks' => $remarksLine,
                'created_at' => now(),
            ]);
        });
    }

    public function hrApprovePendingRecallRequest(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null,
        mixed $pendingUpdateRequest = null,
        array $pendingUpdateMeta = [],
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $payload = is_array($pendingUpdateMeta['payload'] ?? null)
            ? $pendingUpdateMeta['payload']
            : [];
        $recallReason = trim((string) (
            $pendingUpdateMeta['reason']
            ?? $payload['recall_reason']
            ?? $payload['reason']
            ?? $remarks
            ?? ''
        ));
        $recallSelectedDates = is_array($payload['recall_selected_dates'] ?? null)
            ? $payload['recall_selected_dates']
            : (is_array($payload['selected_dates'] ?? null) ? $payload['selected_dates'] : []);

        return $this->executeHrRecall(
            $app,
            $hr,
            $recallReason,
            $recallSelectedDates,
            $pendingUpdateRequest instanceof LeaveApplicationUpdateRequest ? $pendingUpdateRequest : null,
            $remarks,
            $applicationFormatter
        );
    }

    public function executeHrApprovePendingCancellationRequest(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null,
        mixed $pendingUpdateRequest = null,
        array $pendingUpdateMeta = [],
        ?callable $refundCallback = null,
        ?callable $ctoSyncCallback = null,
        ?callable $applicationFormatter = null,
        string $balanceConflictError = 'HR_CANCEL_REQUEST_BALANCE_CONFLICT'
    ): JsonResponse {
        $cancelReason = trim((string) (
            $pendingUpdateMeta['reason']
            ?? $remarks
            ?? ''
        ));

        if ($ctoSyncCallback !== null && $app->employee_control_no) {
            $ctoSyncCallback();
        }

        try {
            $this->hrApprovePendingCancellationRequest(
                $app,
                $hr,
                $remarks,
                $pendingUpdateRequest instanceof LeaveApplicationUpdateRequest ? $pendingUpdateRequest : null,
                $cancelReason,
                $refundCallback,
                $ctoSyncCallback
            );
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
            'application' => $applicationFormatter ? $applicationFormatter($app) : $app->toArray(),
        ]);
    }

    public function executeHrRejectPendingUpdateRequest(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null,
        array $pendingUpdateMeta = [],
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $pendingUpdateRequest = $pendingUpdateMeta['request_record'] ?? null;
        $pendingActionType = $this->resolveUpdateRequestActionTypeFromPayload($pendingUpdateMeta['payload'] ?? null);

        $previousStatus = strtoupper(trim((string) ($pendingUpdateMeta['previous_status'] ?? '')));
        if ($previousStatus !== LeaveApplication::STATUS_APPROVED) {
            $previousStatus = LeaveApplication::STATUS_APPROVED;
        }

        $this->hrRejectPendingUpdateRequest(
            $app,
            $hr,
            $remarks,
            $pendingUpdateRequest instanceof LeaveApplicationUpdateRequest ? $pendingUpdateRequest : null,
            $previousStatus,
            $pendingActionType
        );

        $app = $app->fresh(['leaveType', 'applicantAdmin']);

        $message = $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
            ? 'Leave cancellation request rejected by HR. Original approved application remains unchanged.'
            : (
                $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                    ? 'Leave recall request rejected by HR. Original approved application remains unchanged.'
                    : 'Leave update request rejected by HR. Original approved application remains unchanged.'
            );

        return response()->json([
            'message' => $message,
            'application' => $applicationFormatter ? $applicationFormatter($app) : $app->toArray(),
        ]);
    }

    public function executeAdminRejectPendingUpdateRequest(
        LeaveApplication $app,
        DepartmentAdmin $admin,
        ?string $remarks = null,
        array $pendingUpdateMeta = [],
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $pendingUpdateRequest = $pendingUpdateMeta['request_record'] ?? null;
        $pendingActionType = $this->resolveUpdateRequestActionTypeFromPayload($pendingUpdateMeta['payload'] ?? null);

        $previousStatus = strtoupper(trim((string) ($pendingUpdateMeta['previous_status'] ?? '')));
        if ($previousStatus !== LeaveApplication::STATUS_APPROVED) {
            $previousStatus = LeaveApplication::STATUS_APPROVED;
        }

        $this->adminRejectPendingUpdateRequest(
            $app,
            $admin,
            $remarks,
            $pendingUpdateRequest instanceof LeaveApplicationUpdateRequest ? $pendingUpdateRequest : null,
            $previousStatus,
            $pendingActionType
        );

        $app = $app->fresh(['leaveType', 'applicantAdmin']);

        $message = $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
            ? 'Leave cancellation request rejected by admin. Original approved application remains unchanged.'
            : (
                $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                    ? 'Leave recall request rejected by admin. Original approved application remains unchanged.'
                    : 'Leave update request rejected by admin. Original approved application remains unchanged.'
            );

        return response()->json([
            'message' => $message,
            'application' => $applicationFormatter ? $applicationFormatter($app) : $app->toArray(),
        ]);
    }

    public function executeHrReceiveUpdate(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null,
        ?callable $actorDirectoryResolver = null,
        ?callable $applicationFormatter = null,
        ?callable $pendingMetaResolver = null
    ): JsonResponse {
        $metaResolver = $pendingMetaResolver ?? fn ($a) => $this->resolvePendingUpdateMeta($a);
        if (! $this->isPendingApprovedUpdateRequest($app, fn ($a) => $this->hasPendingApprovedUpdateRequest($a, $metaResolver))) {
            return response()->json([
                'message' => 'Cannot mark updated copy as received: application is not in pending approved-update review.',
            ], 422);
        }

        $cycleRequestedAt = $this->resolvePendingApprovedUpdateCycleRequestedAt($app, $metaResolver);
        if (! $cycleRequestedAt) {
            return response()->json([
                'message' => 'Cannot locate the pending approved update-request cycle for this application.',
            ], 422);
        }
        $pendingActionType = $this->resolvePendingApprovedUpdateActionType($app, $metaResolver);

        $receivedLog = $this->resolveLatestHrActionLogForCycle(
            $app,
            LeaveApplicationLog::ACTION_HR_RECEIVED,
            $cycleRequestedAt,
        );

        if (! $receivedLog) {
            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_RECEIVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $remarks ?: (
                    $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                        ? 'Received leave cancellation request form.'
                        : (
                            $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                                ? 'Received leave recall request form.'
                                : 'Received updated hard copy leave application form.'
                        )
                ),
                'created_at' => now(),
            ]);
            $app->load('logs');
        }

        $actorDirectory = $actorDirectoryResolver ? $actorDirectoryResolver([$app]) : [];

        return response()->json([
            'message' => $receivedLog
                ? ($pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? 'Cancellation request hard-copy receipt was already confirmed.'
                    : (
                        $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                            ? 'Recall request hard-copy receipt was already confirmed.'
                            : 'Updated hard-copy receipt was already confirmed for this update request.'
                    ))
                : ($pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? 'Cancellation request hard-copy receipt confirmed.'
                    : (
                        $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                            ? 'Recall request hard-copy receipt confirmed.'
                            : 'Updated hard-copy receipt confirmed.'
                    )),
            'application' => $applicationFormatter ? $applicationFormatter($app, $actorDirectory) : $app->toArray(),
        ]);
    }

    public function executeHrReleaseUpdate(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null,
        ?callable $actorDirectoryResolver = null,
        ?callable $applicationFormatter = null,
        ?callable $latestMetaResolver = null
    ): JsonResponse {
        $metaResolver = $latestMetaResolver ?? fn ($a) => $this->resolveLatestUpdateMeta($a);
        $latestApprovedActionType = $this->resolveLatestApprovedUpdateActionType($app, $metaResolver);
        $allowedStatuses = $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
            ? [LeaveApplication::STATUS_REJECTED, LeaveApplication::STATUS_APPROVED]
            : (
                $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                    ? [LeaveApplication::STATUS_APPROVED, LeaveApplication::STATUS_RECALLED]
                    : [LeaveApplication::STATUS_APPROVED]
            );
        if (! in_array($app->status, $allowedStatuses, true)) {
            return response()->json([
                'message' => $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? "Cannot mark cancellation request copy as released: application status is '{$app->status}'."
                    : (
                        $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                            ? "Cannot mark recall request copy as released: application status is '{$app->status}'."
                            : "Cannot mark updated copy as released: application status is '{$app->status}'."
                    ),
            ], 422);
        }

        $cycleRequestedAt = $this->resolveLatestApprovedUpdateCycleRequestedAt($app, $metaResolver);
        if (! $cycleRequestedAt) {
            return response()->json([
                'message' => 'Cannot locate the latest approved update-request cycle for this application.',
            ], 422);
        }

        $releasedLog = $this->resolveLatestHrActionLogForCycle(
            $app,
            LeaveApplicationLog::ACTION_HR_RELEASED,
            $cycleRequestedAt,
        );

        if (! $releasedLog) {
            $receivedLog = $this->resolveLatestHrActionLogForCycle(
                $app,
                LeaveApplicationLog::ACTION_HR_RECEIVED,
                $cycleRequestedAt,
            );

            if (! $receivedLog) {
                return response()->json([
                    'message' => $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                        ? 'Cannot mark cancellation request copy as released before confirming cancellation request hard-copy receipt.'
                        : (
                            $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                                ? 'Cannot mark recall request copy as released before confirming recall request hard-copy receipt.'
                                : 'Cannot mark updated copy as released before confirming updated hard-copy receipt.'
                        ),
                ], 422);
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_RELEASED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $remarks ?: (
                    $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                        ? 'Released leave cancellation request form.'
                        : (
                            $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                                ? 'Released leave recall request form.'
                                : 'Released updated hard copy leave application form.'
                        )
                ),
                'created_at' => now(),
            ]);
            $app->load('logs');
        }

        $actorDirectory = $actorDirectoryResolver ? $actorDirectoryResolver([$app]) : [];

        return response()->json([
            'message' => $releasedLog
                ? ($latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? 'Cancellation request hard-copy release was already confirmed.'
                    : (
                        $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                            ? 'Recall request hard-copy release was already confirmed.'
                            : 'Updated hard-copy release was already confirmed for this update request.'
                    ))
                : ($latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                    ? 'Cancellation request hard-copy release confirmed.'
                    : (
                        $latestApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                            ? 'Recall request hard-copy release confirmed.'
                            : 'Updated hard-copy release confirmed.'
                    )),
            'application' => $applicationFormatter ? $applicationFormatter($app, $actorDirectory) : $app->toArray(),
        ]);
    }

    public function applyApprovedUpdateRecord(
        LeaveApplication $app,
        HRAccount $hr,
        ?LeaveApplicationUpdateRequest $pendingUpdateRequest,
        array $targetAttributes,
        ?string $remarks = null,
        ?callable $refundCallback = null,
        ?callable $deductCallback = null,
        ?callable $ctoSyncCallback = null,
        ?callable $lockSnapshotCallback = null
    ): void {
        DB::transaction(function () use (
            $app,
            $hr,
            $pendingUpdateRequest,
            $targetAttributes,
            $remarks,
            $refundCallback,
            $deductCallback,
            $ctoSyncCallback,
            $lockSnapshotCallback
        ): void {
            if ($refundCallback !== null) {
                $refundCallback();
            }

            if ($deductCallback !== null) {
                $additionalAttributes = $deductCallback();
                if (is_array($additionalAttributes)) {
                    $targetAttributes = array_merge($targetAttributes, $additionalAttributes);
                }
            }

            $app->update($targetAttributes);

            if ($ctoSyncCallback !== null) {
                $ctoSyncCallback();
            }

            if ($lockSnapshotCallback !== null) {
                $lockSnapshotCallback();
            }

            if ($pendingUpdateRequest instanceof LeaveApplicationUpdateRequest) {
                $pendingUpdateRequest->update([
                    'status' => LeaveApplicationUpdateRequest::STATUS_APPROVED,
                    'reviewed_by_hr_id' => $hr->id,
                    'reviewed_at' => now(),
                    'review_remarks' => $remarks,
                ]);
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_APPROVED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $remarks ?: 'Approved leave update request and applied changes.',
                'created_at' => now(),
            ]);
        });
    }

    public function createEmployeeFiledApplication(
        object $account,
        array $attributes
    ): LeaveApplication {
        return DB::transaction(function () use ($account, $attributes): LeaveApplication {
            $application = LeaveApplication::create(array_merge($attributes, [
                'status' => LeaveApplication::STATUS_PENDING_ADMIN,
            ]));

            LeaveApplicationLog::create([
                'leave_application_id' => $application->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $account->id,
                'created_at' => now(),
            ]);

            return $application;
        });
    }

    public function notifyDepartmentAdminsOfEmployeeFiledApplication(
        LeaveApplication $app,
        object $employee,
        ?string $monetizationLabel = null
    ): void {
        $app->loadMissing('leaveType');
        $employeeName = trim(($employee->firstname ?? '').' '.($employee->surname ?? ''));
        $title = $monetizationLabel !== null ? 'Monetization Request' : 'New Leave Application';
        $message = $monetizationLabel !== null
            ? "{$employeeName} submitted a monetization request for {$monetizationLabel} (".self::formatDays($app->total_days).').'
            : "{$employeeName} submitted a {$app->leaveType?->name} leave request (".self::formatDays($app->total_days).').';

        $admins = DepartmentAdmin::whereHas('department', fn ($q) => $q->where('name', $employee->office))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_REQUEST,
                $title,
                $message,
                $app->id
            );
        }
    }

    public function createAdminFiledApplication(
        DepartmentAdmin $admin,
        array $attributes,
        ?string $remarks = null
    ): LeaveApplication {
        return DB::transaction(function () use ($admin, $attributes, $remarks): LeaveApplication {
            $application = LeaveApplication::create(array_merge($attributes, [
                'status' => LeaveApplication::STATUS_PENDING_HR,
                'admin_id' => $admin->id,
                'admin_approved_at' => now(),
            ]));

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
                'remarks' => $remarks ?: 'Filed by department admin on behalf of employee.',
                'created_at' => now(),
            ]);

            return $application;
        });
    }

    public function notifyHrAccountsOfAdminFiledApplication(
        LeaveApplication $app,
        string $employeeName,
        bool $isMonetization = false,
        ?string $monetizationLabel = null
    ): void {
        $app->loadMissing('leaveType');
        $title = $isMonetization ? 'Monetization Request Pending HR Review' : 'Leave Application Pending HR Review';
        $monetizationText = $monetizationLabel !== null ? "{$monetizationLabel} monetization request" : 'monetization request';
        $message = $isMonetization
            ? "{$employeeName} filed a {$monetizationText} (".self::formatDays($app->total_days).') through department admin and it awaits your review.'
            : "{$employeeName} filed a {$app->leaveType?->name} leave (".self::formatDays($app->total_days).') through department admin and it awaits your review.';

        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_REQUEST,
                $title,
                $message,
                $app->id
            );
        }
    }

    public function notifyPartiesOfEmployeeCancellation(
        LeaveApplication $app,
        object $employee,
        string $statusBeforeCancel,
        ?string $reason = null
    ): void {
        $app->loadMissing('leaveType');
        $employeeName = trim(($employee->firstname ?? '').' '.($employee->surname ?? ''));
        if ($employeeName === '') {
            $employeeName = 'Employee '.(string) ($employee->control_no ?? $app->employee_control_no);
        }
        $leaveTypeName = $app->leaveType?->name ?? 'leave';
        $pendingStage = $statusBeforeCancel === LeaveApplication::STATUS_PENDING_HR
            ? 'pending HR review'
            : 'pending department review';
        $message = "{$employeeName} cancelled a {$leaveTypeName} application ({$pendingStage}).";
        $reason = trim((string) ($reason ?? ''));
        if ($reason !== '') {
            $message .= " Reason: {$reason}";
        }

        $title = 'Leave Application Cancelled by Employee';
        $admins = DepartmentAdmin::whereHas('department', fn ($q) => $q->where('name', $employee->office ?? ''))->get();
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
    }

    public function notifyDepartmentAdminsOfApprovedLeaveCancellationRequest(
        LeaveApplication $app,
        object $employee,
        ?string $requestReason = null
    ): void {
        $app->loadMissing('leaveType');
        $employeeName = trim(($employee->firstname ?? '').' '.($employee->surname ?? ''));
        if ($employeeName === '') {
            $employeeName = 'Employee '.(string) ($employee->control_no ?? $app->employee_control_no);
        }

        $leaveTypeName = $app->leaveType?->name ?? 'leave';
        $message = "{$employeeName} requested cancellation for an approved {$leaveTypeName} application (pending department review).";
        $requestReason = trim((string) ($requestReason ?? ''));
        if ($requestReason !== '') {
            $message .= " Reason: {$requestReason}";
        }

        $admins = DepartmentAdmin::whereHas('department', fn ($q) => $q->where('name', $employee->office ?? ''))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_EDIT_REQUEST,
                'Approved Leave Cancellation Requested',
                $message,
                $app->id
            );
        }
    }

    public function withdrawPendingApprovedUpdateRequest(
        LeaveApplication $app,
        ?LeaveApplicationUpdateRequest $pendingUpdateRequest = null,
        ?string $previousStatus = null
    ): void {
        $statusToRestore = strtoupper(trim((string) ($previousStatus ?? '')));
        if (! in_array($statusToRestore, [LeaveApplication::STATUS_APPROVED, LeaveApplication::STATUS_PENDING_HR, LeaveApplication::STATUS_PENDING_ADMIN], true)) {
            $statusToRestore = LeaveApplication::STATUS_APPROVED;
        }

        DB::transaction(function () use ($app, $pendingUpdateRequest, $statusToRestore): void {
            $cleanedRemarks = $this->cleanRequestLinesFromRemarks($app->remarks);

            $app->update([
                'status' => $statusToRestore,
                'remarks' => $cleanedRemarks,
            ]);

            if ($pendingUpdateRequest instanceof LeaveApplicationUpdateRequest) {
                $pendingUpdateRequest->delete();
            }

            LeaveApplicationUpdateRequest::query()
                ->where('leave_application_id', (int) $app->id)
                ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
                ->delete();

            LeaveApplicationLog::query()
                ->where('leave_application_id', (int) $app->id)
                ->where(function ($query): void {
                    $query->where('remarks', 'LIKE', '%Cancellation request submitted%')
                        ->orWhere('remarks', 'LIKE', '%Edit request submitted%')
                        ->orWhere('remarks', 'LIKE', '%Update request submitted%')
                        ->orWhere('remarks', 'LIKE', '%Recall request submitted%');
                })
                ->delete();
        });
    }

    public function submitEmployeeLeaveUpdateRequest(
        LeaveApplication $app,
        array $requestedUpdatePayload,
        string $remarksLine,
        string $requestReason,
        int $performedById,
        ?string $employeeControlNo = null,
        ?string $employeeName = null,
        bool $isApprovedApplication = false
    ): void {
        DB::transaction(function () use (
            $app,
            $remarksLine,
            $performedById,
            $isApprovedApplication,
            $requestedUpdatePayload,
            $requestReason,
            $employeeControlNo,
            $employeeName
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
                    'employee_control_no' => $employeeControlNo,
                    'employee_name' => $employeeName,
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
                $app->update([
                    'remarks' => $updatedRemarks,
                ]);
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_SUBMITTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_EMPLOYEE,
                'performed_by_id' => $performedById,
                'remarks' => $remarksLine,
                'created_at' => now(),
            ]);
        });
    }

    public function notifyPartiesOfEmployeeUpdateRequest(
        LeaveApplication $app,
        object $employee,
        bool $isApprovedApplication,
        ?string $requestReason = null
    ): void {
        $app->loadMissing('leaveType');
        $employeeName = trim(($employee->firstname ?? '').' '.($employee->surname ?? ''));
        if ($employeeName === '') {
            $employeeName = 'Employee '.(string) ($employee->control_no ?? $app->employee_control_no);
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

        $requestReason = trim((string) ($requestReason ?? ''));
        if ($requestReason !== '') {
            $message .= " Reason: {$requestReason}";
        }

        $title = $isApprovedApplication
            ? 'Approved Leave Update Requested'
            : 'Leave Application Edit Requested';

        $admins = DepartmentAdmin::whereHas('department', fn ($q) => $q->where('name', $employee->office ?? ''))->get();
        foreach ($admins as $deptAdmin) {
            Notification::send(
                $deptAdmin,
                Notification::TYPE_LEAVE_EDIT_REQUEST,
                $title,
                $message,
                $app->id
            );
        }

        if (! $isApprovedApplication) {
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
    }

    public function notifyHrAccountsOfAdminApproval(
        LeaveApplication $app,
        string $employeeName,
        bool $isPendingApprovedUpdateRequest = false,
        ?string $pendingApprovedActionType = null
    ): void {
        $app->loadMissing('leaveType');
        $isMonetization = (bool) $app->is_monetization;
        $actionLabel = $isMonetization ? 'monetization request' : 'leave application';
        $titleLabel = $isMonetization ? 'Monetization' : 'Leave';
        $leaveTypeName = $app->leaveType?->name ?? 'leave';

        $notificationType = $isPendingApprovedUpdateRequest
            ? Notification::TYPE_LEAVE_EDIT_REQUEST
            : Notification::TYPE_LEAVE_REQUEST;

        $notificationTitle = $isPendingApprovedUpdateRequest
            ? ($pendingApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                ? "{$titleLabel} Cancellation Request Pending HR Review"
                : (
                    $pendingApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                        ? "{$titleLabel} Recall Request Pending HR Review"
                        : "{$titleLabel} Edit Request Pending HR Review"
                ))
            : "{$titleLabel} Application Pending HR Review";

        $notificationMessage = $isPendingApprovedUpdateRequest
            ? ($pendingApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                ? "{$employeeName} submitted a cancellation request for an approved {$leaveTypeName} {$actionLabel}. The request has been approved by department admin and awaits your review."
                : (
                    $pendingApprovedActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                        ? "{$employeeName} submitted a recall request for an approved {$leaveTypeName} {$actionLabel}. The request has been approved by department admin and awaits your review."
                        : "{$employeeName} submitted an update request for an approved {$leaveTypeName} {$actionLabel}. The request has been approved by department admin and awaits your review."
                ))
            : "{$employeeName} submitted a {$leaveTypeName} {$actionLabel} (".self::formatDays($app->total_days).') that has been approved by admin and awaits your review.';

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
    }

    public function resolveAdminApprovalResponseMessage(
        bool $isPendingApprovedUpdateRequest,
        ?string $pendingApprovedActionType = null
    ): string {
        if (! $isPendingApprovedUpdateRequest) {
            return 'Application approved by admin. Forwarded to HR.';
        }

        return match ($pendingApprovedActionType) {
            LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL => 'Leave cancellation request approved by admin. Forwarded to HR for final approval.',
            LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL => 'Leave recall request approved by admin. Forwarded to HR for final approval.',
            default => 'Leave update request approved by admin. Forwarded to HR for final approval.',
        };
    }

    public function notifyHrAccountsOfAdminRecallRequest(
        LeaveApplication $app,
        string $employeeName,
        ?string $reason = null
    ): void {
        $app->loadMissing('leaveType');
        $leaveTypeName = $app->leaveType?->name ?? 'leave';
        $message = "{$employeeName} requested a recall for an approved {$leaveTypeName} leave application.";
        $reason = trim((string) ($reason ?? ''));
        if ($reason !== '') {
            $message .= " Reason: {$reason}";
        }

        $hrAccounts = HRAccount::all();
        foreach ($hrAccounts as $hrAccount) {
            Notification::send(
                $hrAccount,
                Notification::TYPE_LEAVE_EDIT_REQUEST,
                'Recall Request Pending HR Review',
                $message,
                $app->id
            );
        }
    }

    public function isRecallableLeaveType(
        LeaveApplication $app,
        ?LeaveType $leaveType = null,
        ?int $forcedLeaveTypeId = null,
        ?int $vacationLeaveTypeId = null
    ): bool {
        $resolvedLeaveType = $leaveType ?? $app->leaveType;
        $normalizedLeaveTypeName = strtolower(trim((string) ($resolvedLeaveType?->name ?? '')));

        return ($forcedLeaveTypeId !== null && (int) $app->leave_type_id === $forcedLeaveTypeId)
            || ($vacationLeaveTypeId !== null && (int) $app->leave_type_id === $vacationLeaveTypeId)
            || in_array($normalizedLeaveTypeName, ['mandatory / forced leave', 'vacation leave'], true);
    }

    public function notifyApplicantOfHrApproval(
        LeaveApplication $app,
        ?callable $smsCallback = null
    ): void {
        $app->loadMissing(['leaveType', 'applicantAdmin']);
        $isMonetization = (bool) $app->is_monetization;
        $actionLabel = $isMonetization ? 'monetization request' : 'leave application';
        $titleLabel = $isMonetization ? 'Monetization Approved' : 'Leave Application Approved';
        $msg = "Your {$app->leaveType?->name} {$actionLabel} (".self::formatDays($app->total_days).') has been fully approved!';
        if ($app->applicant_admin_id && $app->applicantAdmin) {
            Notification::send($app->applicantAdmin, Notification::TYPE_LEAVE_APPROVED, $titleLabel, $msg, $app->id);
        }
        if ($smsCallback !== null) {
            $smsCallback($app);
        }
    }

    public function notifyApplicantOfHrRejection(
        LeaveApplication $app,
        ?string $remarks = null,
        ?callable $smsCallback = null
    ): void {
        $app->loadMissing(['leaveType', 'applicantAdmin']);
        $isMonetization = (bool) $app->is_monetization;
        $actionLabel = $isMonetization ? 'monetization request' : 'leave application';
        $titleLabel = $isMonetization ? 'Monetization' : 'Leave Application';
        $remarks = trim((string) ($remarks ?? ''));
        $rejectMsg = "Your {$app->leaveType?->name} {$actionLabel} was rejected by HR.".($remarks !== '' ? " Reason: {$remarks}" : '');

        if ($app->applicant_admin_id && $app->applicantAdmin) {
            Notification::send(
                $app->applicantAdmin,
                Notification::TYPE_LEAVE_REJECTED,
                "{$titleLabel} Rejected by HR",
                $rejectMsg,
                $app->id
            );
        }
        if ($smsCallback !== null) {
            $smsCallback($app);
        }
    }

    public function withHrApplicationEditRequestMeta(array $payload): array
    {
        $payload['request_kind'] = self::HR_APPLICATION_EDIT_REQUEST_KIND;
        $payload['action_type'] = LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE;

        return $payload;
    }

    public function isHrApplicationEditWorkflowLog(LeaveApplicationLog $log): bool
    {
        return in_array($log->action, [
            LeaveApplicationLog::ACTION_HR_APPLICATION_EDITED,
            LeaveApplicationLog::ACTION_HR_APPLICATION_EDIT_REQUESTED,
            LeaveApplicationLog::ACTION_HR_APPLICATION_EDIT_REQUEST_APPROVED,
            LeaveApplicationLog::ACTION_HR_APPLICATION_EDIT_REQUEST_REJECTED,
        ], true);
    }

    public function formatHrEditRequestActor(HRAccount $hr): string
    {
        $username = trim((string) ($hr->username ?? ''));

        return $username !== ''
            ? "HR:{$hr->id}:{$username}"
            : "HR:{$hr->id}";
    }

    public function resolveEditRequestPayloadRequestKind(LeaveApplicationUpdateRequest $editRequest): ?string
    {
        $payload = $editRequest->requested_payload;
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $payload = $decoded;
            }
        }

        if (! is_array($payload)) {
            return null;
        }

        $kind = trim((string) ($payload['request_kind'] ?? ''));

        return $kind !== '' ? $kind : null;
    }

    public function createHrStaffApplicationEditRequest(
        LeaveApplication $app,
        HRAccount $hr,
        array $normalizedPayload,
        ?string $reason,
        string $employeeDisplayName
    ): LeaveApplicationUpdateRequest {
        return DB::transaction(function () use ($app, $hr, $normalizedPayload, $reason, $employeeDisplayName): LeaveApplicationUpdateRequest {
            $payload = $this->withHrApplicationEditRequestMeta($normalizedPayload);

            $editRequest = LeaveApplicationUpdateRequest::create([
                'leave_application_id' => $app->id,
                'employee_control_no' => $app->employee_control_no,
                'employee_name' => $employeeDisplayName,
                'requested_payload' => $payload,
                'requested_reason' => $reason,
                'previous_status' => $app->status,
                'requested_by_control_no' => $this->formatHrEditRequestActor($hr),
                'requested_at' => now(),
                'status' => LeaveApplicationUpdateRequest::STATUS_PENDING,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_APPLICATION_EDIT_REQUESTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $reason ?? 'HR application override.',
                'created_at' => now(),
            ]);

            return $editRequest;
        });
    }

    public function executeHrRejectApplicationEditRequest(
        LeaveApplicationUpdateRequest $editRequest,
        HRAccount $hr,
        ?string $remarks = null
    ): LeaveApplicationUpdateRequest {
        $trimmedRemarks = $this->trimNullableString($remarks);

        DB::transaction(function () use ($editRequest, $hr, $trimmedRemarks): void {
            $editRequest->update([
                'status' => LeaveApplicationUpdateRequest::STATUS_REJECTED,
                'reviewed_by_hr_id' => $hr->id,
                'reviewed_at' => now(),
                'review_remarks' => $trimmedRemarks,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $editRequest->leave_application_id,
                'action' => LeaveApplicationLog::ACTION_HR_APPLICATION_EDIT_REQUEST_REJECTED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $trimmedRemarks ?? 'Rejected HR staff edit request.',
                'created_at' => now(),
            ]);
        });

        return $editRequest;
    }

    public function executeHrBulkCmoCbmoReview(
        Collection $applications,
        HRAccount $hr,
        string $remarks,
        ?callable $smsCallback = null
    ): array {
        $processedIds = [];
        $skippedCount = 0;
        $now = now();

        foreach ($applications as $app) {
            if ($app->status !== LeaveApplication::STATUS_APPROVED) {
                $skippedCount++;

                continue;
            }

            $receivedLog = $app->logs->first(
                fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_RECEIVED
                    && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
            );

            if (! $receivedLog) {
                $skippedCount++;

                continue;
            }

            $reviewedLog = $app->logs->first(
                fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED
                    && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
            );

            if ($reviewedLog) {
                $processedIds[] = (int) $app->id;

                continue;
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $remarks,
                'created_at' => $now,
            ]);

            $app->load('logs');
            if ($smsCallback !== null) {
                $smsCallback($app);
            }
            $processedIds[] = (int) $app->id;
        }

        return [
            'processed_count' => count($processedIds),
            'skipped_count' => $skippedCount,
            'processed_ids' => $processedIds,
        ];
    }

    public function executeHrBulkRelease(
        Collection $applications,
        HRAccount $hr,
        string $remarks
    ): array {
        $processedIds = [];
        $skippedCount = 0;
        $now = now();

        foreach ($applications as $app) {
            $isCancelled = $app->status === LeaveApplication::STATUS_CANCELLED
                || $app->logs->contains(
                    fn (LeaveApplicationLog $log): bool => $this->isCancelledRemark($log->remarks)
                ) || $this->isCancelledRemark($app->remarks);
            if ($isCancelled) {
                $skippedCount++;

                continue;
            }

            $isPendingApprovedUpdateRequest = $this->isPendingApprovedUpdateRequest($app);
            if ($app->status === LeaveApplication::STATUS_PENDING_ADMIN && ! $isPendingApprovedUpdateRequest) {
                $skippedCount++;

                continue;
            }

            if ($this->hasPendingApprovedUpdateRequest($app)) {
                $cycleRequestedAt = $this->resolveLatestApprovedUpdateCycleRequestedAt($app);
                if (! $cycleRequestedAt) {
                    $skippedCount++;

                    continue;
                }

                $releasedLog = $this->resolveLatestHrActionLogForCycle(
                    $app,
                    LeaveApplicationLog::ACTION_HR_RELEASED,
                    $cycleRequestedAt
                );

                if ($releasedLog) {
                    $processedIds[] = (int) $app->id;

                    continue;
                }

                $receivedLog = $this->resolveLatestHrActionLogForCycle(
                    $app,
                    LeaveApplicationLog::ACTION_HR_RECEIVED,
                    $cycleRequestedAt
                );

                if (! $receivedLog) {
                    $skippedCount++;

                    continue;
                }

                $pendingActionType = $this->resolveLatestApprovedUpdateActionType($app);

                LeaveApplicationLog::create([
                    'leave_application_id' => $app->id,
                    'action' => LeaveApplicationLog::ACTION_HR_RELEASED,
                    'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                    'performed_by_id' => $hr->id,
                    'remarks' => $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
                        ? 'Released leave cancellation request form.'
                        : (
                            $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                                ? 'Released leave recall request form.'
                                : 'Released updated hard copy leave application form.'
                        ),
                    'created_at' => $now,
                ]);

                $app->load('logs');
                $processedIds[] = (int) $app->id;

                continue;
            }

            $releasedLog = $app->logs->first(
                fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_RELEASED
                    && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
            );

            if ($releasedLog) {
                $processedIds[] = (int) $app->id;

                continue;
            }

            $receivedLog = $app->logs->first(
                fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_HR_RECEIVED
                    && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
            );

            if (! $receivedLog) {
                $skippedCount++;

                continue;
            }

            $cmoCbmoReviewedLog = $app->logs->first(
                fn (LeaveApplicationLog $log) => $log->action === LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED
                    && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
            );

            if (! $cmoCbmoReviewedLog) {
                $skippedCount++;

                continue;
            }

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_RELEASED,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $remarks,
                'created_at' => $now,
            ]);

            $app->load('logs');
            $processedIds[] = (int) $app->id;
        }

        return [
            'processed_count' => count($processedIds),
            'skipped_count' => $skippedCount,
            'processed_ids' => $processedIds,
        ];
    }

    public function hasRequestedLeaveUpdateChanges(
        LeaveApplication $app,
        array $requestedPayload,
        ?callable $policyResolver = null
    ): bool {
        $currentPayload = $this->normalizePendingUpdatePayload([
            'leave_type_id' => (int) $app->leave_type_id,
            'start_date' => $app->start_date?->toDateString(),
            'end_date' => $app->end_date?->toDateString(),
            'selected_dates' => $app->resolvedSelectedDates(),
            'selected_date_pay_status' => is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null,
            'selected_date_coverage' => is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
            'selected_date_half_day_portion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'total_days' => (float) $app->total_days,
            'deductible_days' => $this->calculationService->resolveApplicationDeductibleDays($app),
            'cto_deducted_hours' => $this->calculationService->resolveApplicationCtoDeductedHours($app),
            'reason' => $app->reason,
            'details_of_leave' => $app->details_of_leave,
            'commutation' => $app->commutation ?? 'Not Requested',
            'pay_mode' => $this->calculationService->normalizePayMode($app->pay_mode ?? null, (bool) $app->is_monetization),
            'is_monetization' => (bool) $app->is_monetization,
            'attachment_required' => (bool) ($app->attachment_required ?? false),
            'attachment_submitted' => (bool) ($app->attachment_submitted ?? false),
            'attachment_reference' => $this->trimNullableString($app->attachment_reference ?? null),
        ], $policyResolver);

        $normalizedRequestedPayload = $this->normalizePendingUpdatePayload($requestedPayload, $policyResolver);

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

        if (round((float) ($currentPayload['deductible_days'] ?? 0), 3) !== round((float) ($normalizedRequestedPayload['deductible_days'] ?? 0), 3)) {
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

    public function normalizeHrApplicationEditPayload(array $input, LeaveApplication $app): array|JsonResponse
    {
        $leaveType = $app->leaveType ?? LeaveType::find((int) $app->leave_type_id);
        if (! $leaveType instanceof LeaveType) {
            return response()->json([
                'message' => 'Selected leave type is no longer available.',
            ], 422);
        }
        $app->setRelation('leaveType', $leaveType);

        $rows = $this->normalizeHrApplicationEditRows($input, $app);
        if ($rows instanceof JsonResponse) {
            return $rows;
        }

        if ($rows === []) {
            return response()->json([
                'message' => 'Please add at least one leave date.',
                'errors' => ['pay_status_rows' => ['Please add at least one leave date.']],
            ], 422);
        }

        $selectedDates = array_keys($rows);
        sort($selectedDates);

        $payStatusByDate = [];
        $coverageByDate = [];
        $halfDayPortionByDate = [];
        $deductibleDaysByDate = [];
        $withoutPayDaysByDate = [];
        $totalDays = 0.0;
        $deductibleDays = 0.0;
        $withoutPayDays = 0.0;

        foreach ($selectedDates as $dateKey) {
            $row = $rows[$dateKey];
            $payStatusByDate[$dateKey] = $row['pay_status'];
            $coverageByDate[$dateKey] = $row['coverage'];
            if (($row['half_day_portion'] ?? null) !== null) {
                $halfDayPortionByDate[$dateKey] = $row['half_day_portion'];
            }

            $deductibleDaysByDate[$dateKey] = $row['with_pay_days'];
            $withoutPayDaysByDate[$dateKey] = $row['without_pay_days'];
            $totalDays += $row['coverage_days'];
            $deductibleDays += $row['with_pay_days'];
            $withoutPayDays += $row['without_pay_days'];
        }

        $isForcedLeave = LeaveType::isForcedLeaveType($leaveType, (int) $app->leave_type_id);
        $payMode = $isForcedLeave || $deductibleDays > 0.0
            ? LeaveApplication::PAY_MODE_WITH_PAY
            : LeaveApplication::PAY_MODE_WITHOUT_PAY;
        $selectedDatePayStatus = $this->calculationService->compactSelectedDatePayStatusMap(
            $payStatusByDate,
            $selectedDates,
            $payMode
        );
        $coverageMetadata = $this->calculationService->synchronizeSelectedDateCoverageMetadata(
            $coverageByDate,
            $halfDayPortionByDate,
            $selectedDates
        );

        return [
            'request_kind' => self::HR_APPLICATION_EDIT_REQUEST_KIND,
            'action_type' => LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE,
            'previous_start_date' => $app->start_date?->toDateString() ?: ($app->resolvedSelectedDates()[0] ?? null),
            'previous_end_date' => $app->end_date?->toDateString() ?: ($app->resolvedSelectedDates()[count($app->resolvedSelectedDates()) - 1] ?? null),
            'previous_selected_dates' => $app->resolvedSelectedDates(),
            'previous_selected_date_coverage' => is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
            'previous_selected_date_half_day_portion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'previous_total_days' => (float) ($app->total_days ?? 0.0),
            'leave_type_id' => (int) $app->leave_type_id,
            'leave_type_name' => $leaveType->name,
            'start_date' => $selectedDates[0] ?? null,
            'end_date' => $selectedDates[count($selectedDates) - 1] ?? null,
            'selected_dates' => $selectedDates,
            'selected_date_pay_status' => $selectedDatePayStatus,
            'selected_date_coverage' => $coverageMetadata['selectedDateCoverage'],
            'selected_date_half_day_portion' => $coverageMetadata['selectedDateHalfDayPortion'],
            'selected_date_deduction' => $this->compactHrEditNumericDateMap($deductibleDaysByDate, $selectedDates),
            'selected_date_without_pay' => $this->compactHrEditNumericDateMap($withoutPayDaysByDate, $selectedDates),
            'deductible_days_by_date' => $this->compactHrEditNumericDateMap($deductibleDaysByDate, $selectedDates),
            'without_pay_days_by_date' => $this->compactHrEditNumericDateMap($withoutPayDaysByDate, $selectedDates),
            'pay_status_rows' => array_values($rows),
            'total_days' => round(max($totalDays, 0.0), 2),
            'deductible_days' => round(max($deductibleDays, 0.0), 3),
            'without_pay_days' => round(max($withoutPayDays, 0.0), 3),
            'pay_mode' => $payMode,
            'allow_sl_vl_cross_deduction' => $this->resolvePayloadSlVlCrossDeductionFlag(
                $input,
                (bool) ($app->allow_sl_vl_cross_deduction ?? false)
            ),
            'is_monetization' => false,
        ];
    }

    public function normalizeHrApplicationEditRows(array $input, LeaveApplication $app): array|JsonResponse
    {
        $rawRows = $this->normalizeHrEditRowsInput(
            $input['pay_status_rows'] ?? $input['payStatusRows'] ?? null
        );

        if ($rawRows === []) {
            $rawRows = $this->buildHrApplicationEditRowsFromMaps($input, $app);
        }

        if ($rawRows === []) {
            return [];
        }

        $rowsByDate = [];
        $employeeControlNo = trim((string) ($app->employee_control_no ?? '')) ?: null;
        $fallbackPayMode = $this->calculationService->normalizePayMode($app->pay_mode ?? null, false);

        $shouldForceAbroadWeekendWithoutPay = $this->eligibilityService()->shouldForceAbroadWeekendWithoutPay(
            $app->leaveType,
            (int) $app->leave_type_id,
            $app->details_of_leave
        );

        foreach ($rawRows as $index => $rawRow) {
            if (! is_array($rawRow)) {
                continue;
            }

            $dateKey = $this->calculationService->normalizeDateKey(
                $rawRow['date_key']
                ?? $rawRow['dateKey']
                ?? $rawRow['date']
                ?? $rawRow['selected_date']
                ?? $rawRow['selectedDate']
                ?? null
            );
            if ($dateKey === null) {
                return response()->json([
                    'message' => 'Each edit row must include a valid date.',
                    'errors' => ["pay_status_rows.{$index}.date" => ['Each edit row must include a valid date.']],
                ], 422);
            }

            $isAbroadWeekend = $shouldForceAbroadWeekendWithoutPay && $this->eligibilityService()->isWeekendDateKey($dateKey);

            $coverageInput = $rawRow['coverage_code']
                ?? $rawRow['coverageCode']
                ?? $rawRow['coverage']
                ?? $rawRow['coverageLabel']
                ?? 'whole';
            $coverage = $this->calculationService->normalizeSelectedDateCoverageValue($coverageInput) ?? 'whole';
            $halfDayPortion = $coverage === 'half'
                ? ($this->calculationService->normalizeSelectedDateHalfDayPortionValue(
                    $rawRow['half_day_portion']
                    ?? $rawRow['halfDayPortion']
                    ?? $rawRow['portion']
                    ?? $coverageInput
                ) ?? 'AM')
                : null;
            $coverageDays = $isAbroadWeekend ? 0.0 : round(max(
                $this->calculationService->workScheduleService->resolveCoverageDeductionDays($coverage, $employeeControlNo),
                0.0
            ), 3);

            $rawPayStatus = $rawRow['pay_status']
                ?? $rawRow['payStatus']
                ?? $rawRow['status']
                ?? $fallbackPayMode;
            $payStatus = $this->calculationService->resolvePayModeFromStatusValue($rawPayStatus) ?? $fallbackPayMode;

            $rawWithPayDays = $rawRow['wp']
                ?? $rawRow['with_pay_days']
                ?? $rawRow['withPayDays']
                ?? $rawRow['deductible_days']
                ?? $rawRow['deduction_days']
                ?? $rawRow['deductionDays']
                ?? null;
            $rawWithoutPayDays = $rawRow['wop']
                ?? $rawRow['without_pay_days']
                ?? $rawRow['withoutPayDays']
                ?? $rawRow['without_pay']
                ?? $rawRow['wopDays']
                ?? null;

            $withPayDays = $isAbroadWeekend ? 0.0 : ($payStatus === LeaveApplication::PAY_MODE_WITH_PAY
                ? $this->normalizeHrEditDayValue($rawWithPayDays, $coverageDays)
                : 0.0);
            $withoutPayDays = $isAbroadWeekend ? 0.0 : ($payStatus === LeaveApplication::PAY_MODE_WITHOUT_PAY
                ? $this->normalizeHrEditDayValue($rawWithoutPayDays, $coverageDays)
                : 0.0);

            $rowsByDate[$dateKey] = [
                'date_key' => $dateKey,
                'dateKey' => $dateKey,
                'coverage' => $coverage,
                'coverage_code' => $halfDayPortion !== null ? 'half_'.strtolower($halfDayPortion) : 'whole',
                'coverageCode' => $halfDayPortion !== null ? 'half_'.strtolower($halfDayPortion) : 'whole',
                'half_day_portion' => $halfDayPortion,
                'halfDayPortion' => $halfDayPortion,
                'coverage_days' => $coverageDays,
                'coverageDays' => $coverageDays,
                'pay_status' => $payStatus,
                'payStatus' => $payStatus,
                'with_pay_days' => $withPayDays,
                'withPayDays' => $withPayDays,
                'without_pay_days' => $withoutPayDays,
                'withoutPayDays' => $withoutPayDays,
            ];
        }

        ksort($rowsByDate);

        return $rowsByDate;
    }

    public function normalizeHrEditRowsInput(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (! is_array($value) || $value === []) {
            return [];
        }

        if (array_is_list($value)) {
            return $value;
        }

        $rows = [];
        foreach ($value as $dateKey => $entry) {
            if (is_array($entry)) {
                $entry['date_key'] ??= $dateKey;
                $rows[] = $entry;

                continue;
            }

            $rows[] = [
                'date_key' => $dateKey,
                'pay_status' => $entry,
            ];
        }

        return $rows;
    }

    public function buildHrApplicationEditRowsFromMaps(array $input, LeaveApplication $app): array
    {
        $selectedDates = $this->normalizeHrEditSelectedDates(
            $input['selected_dates']
            ?? $input['selectedDates']
            ?? $app->resolvedSelectedDates()
        );
        if ($selectedDates === []) {
            return [];
        }

        $payStatusMap = $this->calculationService->normalizeSelectedDatePayStatusMap(
            $input['selected_date_pay_status']
            ?? $input['selectedDatePayStatus']
            ?? $app->selected_date_pay_status
        );
        $coverageMap = $this->calculationService->normalizeSelectedDateCoverageMap(
            $input['selected_date_coverage']
            ?? $input['selectedDateCoverage']
            ?? $app->selected_date_coverage
        );
        $halfDayPortionMap = $this->calculationService->normalizeSelectedDateHalfDayPortionMap(
            $input['selected_date_half_day_portion']
            ?? $input['selectedDateHalfDayPortion']
            ?? $app->selected_date_half_day_portion
        );
        $deductibleDaysByDate = $this->normalizeHrEditNumericDateMap(
            $input['deductible_days_by_date']
            ?? $input['selected_date_deduction']
            ?? $input['selectedDateDeduction']
            ?? $app->selected_date_deduction
            ?? null
        );
        $withoutPayDaysByDate = $this->normalizeHrEditNumericDateMap(
            $input['without_pay_days_by_date']
            ?? $input['selected_date_without_pay']
            ?? $input['selectedDateWithoutPay']
            ?? $app->selected_date_without_pay
            ?? null
        );

        $rows = [];
        $fallbackPayMode = $this->calculationService->normalizePayMode($app->pay_mode ?? null, false);
        foreach ($selectedDates as $dateKey) {
            $coverage = $coverageMap[$dateKey] ?? 'whole';
            $halfDayPortion = ($coverage === 'half')
                ? ($halfDayPortionMap[$dateKey] ?? 'AM')
                : null;
            $payStatus = $payStatusMap[$dateKey] ?? $fallbackPayMode;

            $rows[] = [
                'date_key' => $dateKey,
                'coverage' => $coverage,
                'half_day_portion' => $halfDayPortion,
                'pay_status' => $payStatus,
                'with_pay_days' => $deductibleDaysByDate[$dateKey] ?? null,
                'without_pay_days' => $withoutPayDaysByDate[$dateKey] ?? null,
            ];
        }

        return $rows;
    }

    public function normalizeHrEditSelectedDates(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (! is_array($value)) {
            return [];
        }

        $dates = [];
        foreach ($value as $rawDate) {
            $dateKey = $this->calculationService->normalizeDateKey($rawDate);
            if ($dateKey !== null) {
                $dates[] = $dateKey;
            }
        }

        $dates = array_values(array_unique($dates));
        sort($dates);

        return $dates;
    }

    public function normalizeHrEditNumericDateMap(mixed $value): ?array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $value = $decoded;
            }
        }

        if (! is_array($value) || $value === []) {
            return null;
        }

        $normalized = [];
        foreach ($value as $rawDate => $rawDays) {
            $dateKey = $this->calculationService->normalizeDateKey($rawDate);
            if ($dateKey === null) {
                $dateKey = trim((string) $rawDate);
            }
            if ($dateKey === '') {
                continue;
            }

            $normalized[$dateKey] = $this->normalizeHrEditDayValue($rawDays);
        }

        if ($normalized === []) {
            return null;
        }

        ksort($normalized);

        return $normalized;
    }

    public function compactHrEditNumericDateMap(?array $value, array $selectedDates): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        $selectedDateSet = array_fill_keys($selectedDates, true);
        $compacted = [];
        foreach ($value as $dateKey => $days) {
            if (! isset($selectedDateSet[$dateKey])) {
                continue;
            }

            $compacted[$dateKey] = $this->normalizeHrEditDayValue($days);
        }

        if ($compacted === []) {
            return null;
        }

        ksort($compacted);

        return $compacted;
    }

    public function normalizeHrEditDayValue(mixed $value, float $fallback = 0.0): float
    {
        $numericValue = is_numeric($value) ? (float) $value : $fallback;
        if (! is_finite($numericValue)) {
            $numericValue = $fallback;
        }

        return round(min(max($numericValue, 0.0), 999.0), 3);
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
    public function repriceFutureApprovedApplicationsForScheduleChange(
        ?string $employeeControlNo = null,
        ?callable $candidatesResolver = null,
        ?callable $adminControlNoResolver = null,
        ?callable $employeeResolver = null
    ): array {
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
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
            ])
            ->where(function ($nestedQuery): void {
                $nestedQuery->whereNull('is_monetization')
                    ->orWhere('is_monetization', false);
            });

        $targetControlNo = trim((string) ($employeeControlNo ?? ''));
        if ($targetControlNo !== '') {
            $controlNoCandidates = $candidatesResolver !== null
                ? $candidatesResolver($targetControlNo)
                : $this->eligibilityService()->controlNoCandidates($targetControlNo, $employeeResolver);
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
        $forcedLeaveTypeId = $this->creditService->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->creditService->resolveVacationLeaveTypeId();
        $sickLeaveTypeId = $this->creditService->resolveSickLeaveTypeId();

        foreach ($applications as $application) {
            $stats['checked']++;

            $lastLeaveDate = $this->eligibilityService()->resolveApplicationLastLeaveDate($application);
            if ($lastLeaveDate === null || $lastLeaveDate < $today) {
                $stats['skipped']++;

                continue;
            }

            $leaveType = $application->leaveType
                ?? LeaveType::query()->find((int) $application->leave_type_id);
            if (! $leaveType instanceof LeaveType) {
                $stats['failed']++;
                $stats['errors'][] = [
                    'application_id' => (int) $application->id,
                    'reason' => 'Leave type could not be resolved.',
                ];

                continue;
            }
            $application->setRelation('leaveType', $leaveType);

            $policyResolution = $this->eligibilityService()->applyRegularLeavePolicy(
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
                (string) ($application->employee_control_no ?? ''),
                false,
                $this->trimNullableString($application->details_of_leave ?? null)
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
            $targetDeductibleDays = round(max((float) ($policyResolution['deductible_days'] ?? 0.0), 0.0), 3);
            $targetCtoDeductedHours = round(max((float) ($policyResolution['cto_deducted_hours'] ?? 0.0), 0.0), 2);

            $sourcePayMode = $this->calculationService->normalizePayMode($application->pay_mode ?? null, false);
            $sourceDeductibleDays = $this->calculationService->resolveApplicationDeductibleDays($application);
            $sourceCtoDeductedHours = $this->calculationService->resolveApplicationCtoDeductedHours($application);
            $applicationStatus = strtoupper(trim((string) ($application->status ?? '')));
            $isPendingStatus = in_array($applicationStatus, [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
            ], true);
            $shouldReconcileBalances = $applicationStatus === LeaveApplication::STATUS_APPROVED
                || ($isPendingStatus && $this->hasPendingApprovedUpdateRequest($application));

            $canonicalLeaveTypeId = $this->creditService->resolveCanonicalLeaveTypeId((int) $application->leave_type_id)
                ?? (int) $application->leave_type_id;
            $requestedTotalDays = round((float) ($application->total_days ?? $sourceDeductibleDays), 2);

            $sourceDeductsBalance = $this->creditService->applicationDeductsEmployeeBalance(
                false,
                $leaveType,
                $sourcePayMode
            );
            $targetDeductsBalance = $this->creditService->applicationDeductsEmployeeBalance(
                false,
                $leaveType,
                $targetPayMode
            );

            $sourcePrimaryDeduction = $sourceDeductsBalance
                ? $this->creditService->resolveStoredPrimaryLeaveDeduction($application, $leaveType, $sourceDeductibleDays)
                : 0.0;
            $sourceLinkedForcedDeduction = $sourceDeductsBalance
                ? $this->creditService->resolveStoredLinkedForcedLeaveDeduction($application, $leaveType, $sourceDeductibleDays)
                : 0.0;
            $sourceLinkedVacationDeduction = $sourceDeductsBalance
                ? $this->creditService->resolveStoredLinkedVacationLeaveDeduction($application, $leaveType, $sourceDeductibleDays)
                : 0.0;
            $sourceLinkedSickDeduction = $sourceDeductsBalance
                ? $this->creditService->resolveStoredLinkedSickLeaveDeduction($application)
                : 0.0;

            $targetAllowSlVlCrossDeduction = (bool) ($application->allow_sl_vl_cross_deduction ?? false);
            $targetPrimaryAvailableBeforeApplication = $this->creditService->resolveTrackedLeaveBalanceBeforeApplicationDeduction(
                $application,
                $canonicalLeaveTypeId,
                $sourcePrimaryDeduction,
                $adminControlNoResolver,
                $candidatesResolver
            );
            $targetForcedAvailableBeforeApplication = $this->creditService->resolveTrackedLeaveBalanceBeforeApplicationDeduction(
                $application,
                $forcedLeaveTypeId,
                $sourceLinkedForcedDeduction,
                $adminControlNoResolver,
                $candidatesResolver
            );
            $targetVacationAvailableBeforeApplication = $this->creditService->resolveTrackedLeaveBalanceBeforeApplicationDeduction(
                $application,
                $vacationLeaveTypeId,
                $sourceLinkedVacationDeduction,
                $adminControlNoResolver,
                $candidatesResolver
            );
            $targetSickAvailableBeforeApplication = $this->creditService->resolveTrackedLeaveBalanceBeforeApplicationDeduction(
                $application,
                $sickLeaveTypeId,
                $sourceLinkedSickDeduction,
                $adminControlNoResolver,
                $candidatesResolver
            );
            $targetSlVlCrossLeaveTypeId = $leaveType instanceof LeaveType
                ? $this->creditService->resolveSlVlCrossDeductionTargetLeaveTypeId(
                    $leaveType,
                    $canonicalLeaveTypeId,
                    false,
                    $targetAllowSlVlCrossDeduction,
                    $sickLeaveTypeId,
                    $vacationLeaveTypeId
                )
                : null;
            $targetSlVlCrossBreakdown = $targetDeductsBalance && $leaveType instanceof LeaveType
                ? $this->creditService->resolveSlVlCrossDeductionBreakdown(
                    $leaveType,
                    $canonicalLeaveTypeId,
                    false,
                    $targetDeductibleDays,
                    $targetAllowSlVlCrossDeduction,
                    $sickLeaveTypeId,
                    $vacationLeaveTypeId,
                    $targetPrimaryAvailableBeforeApplication,
                    $targetSlVlCrossLeaveTypeId === $vacationLeaveTypeId
                        ? $targetVacationAvailableBeforeApplication
                        : $targetSickAvailableBeforeApplication,
                    $application->resolvedSelectedDates(),
                    $targetSelectedDatePayStatus,
                    is_array($application->selected_date_coverage) ? $application->selected_date_coverage : null,
                    $requestedTotalDays,
                    (string) ($application->employee_control_no ?? ''),
                    fn (array $d, ?array $c, float $t, ?string $e) => $this->calculationService->resolveDateCoverageWeights($d, $c, $t, $e)
                )
                : [
                    'primary_deduction_days' => 0.0,
                    'linked_vacation_deduction_days' => 0.0,
                    'linked_sick_deduction_days' => 0.0,
                ];

            $targetPrimaryDeduction = $targetDeductsBalance
                ? (
                    $targetAllowSlVlCrossDeduction && $targetSlVlCrossLeaveTypeId !== null
                        ? (float) ($targetSlVlCrossBreakdown['primary_deduction_days'] ?? 0.0)
                        : $this->creditService->resolvePrimaryLeaveTrackedDeductionDays(
                            $leaveType,
                            $canonicalLeaveTypeId,
                            false,
                            $requestedTotalDays,
                            $targetDeductibleDays,
                            $forcedLeaveTypeId,
                            $vacationLeaveTypeId
                        )
                )
                : 0.0;

            $isTargetForcedLeaveApplication = $forcedLeaveTypeId !== null
                && $canonicalLeaveTypeId === $forcedLeaveTypeId;
            $targetLinkedForcedDeductionBase = $isTargetForcedLeaveApplication
                ? $targetDeductibleDays
                : $targetPrimaryDeduction;
            $targetLinkedForcedDeduction = (
                $targetDeductsBalance
                && (
                    $isTargetForcedLeaveApplication
                    || $this->creditService->shouldLeaveTypeDeductForcedLeave(
                        $leaveType,
                        $canonicalLeaveTypeId,
                        false,
                        $forcedLeaveTypeId
                    )
                )
            )
                ? round(min(max($targetLinkedForcedDeductionBase, 0.0), $targetForcedAvailableBeforeApplication), 3)
                : 0.0;

            $targetLinkedVacationDeduction = $targetDeductsBalance
                ? (
                    $targetAllowSlVlCrossDeduction && $targetSlVlCrossLeaveTypeId === $vacationLeaveTypeId
                        ? (float) ($targetSlVlCrossBreakdown['linked_vacation_deduction_days'] ?? 0.0)
                        : $this->creditService->resolveLeaveTypeVacationLeaveDeductionDays(
                            $leaveType,
                            $canonicalLeaveTypeId,
                            false,
                            $requestedTotalDays,
                            $targetDeductibleDays,
                            $forcedLeaveTypeId,
                            $vacationLeaveTypeId
                        )
                )
                : 0.0;
            $targetLinkedSickDeduction = $targetDeductsBalance
                ? (float) ($targetSlVlCrossBreakdown['linked_sick_deduction_days'] ?? 0.0)
                : 0.0;

            $deltaPrimaryDeduction = round($targetPrimaryDeduction - $sourcePrimaryDeduction, 3);
            $deltaLinkedForcedDeduction = round($targetLinkedForcedDeduction - $sourceLinkedForcedDeduction, 3);
            $deltaLinkedVacationDeduction = round($targetLinkedVacationDeduction - $sourceLinkedVacationDeduction, 3);
            $deltaLinkedSickDeduction = round($targetLinkedSickDeduction - $sourceLinkedSickDeduction, 3);

            if (abs($deltaPrimaryDeduction) < 0.001) {
                $deltaPrimaryDeduction = 0.0;
            }
            if (abs($deltaLinkedForcedDeduction) < 0.001) {
                $deltaLinkedForcedDeduction = 0.0;
            }
            if (abs($deltaLinkedVacationDeduction) < 0.001) {
                $deltaLinkedVacationDeduction = 0.0;
            }
            if (abs($deltaLinkedSickDeduction) < 0.001) {
                $deltaLinkedSickDeduction = 0.0;
            }

            if (! $shouldReconcileBalances) {
                $deltaPrimaryDeduction = 0.0;
                $deltaLinkedForcedDeduction = 0.0;
                $deltaLinkedVacationDeduction = 0.0;
                $deltaLinkedSickDeduction = 0.0;
            }

            $targetStoredCtoDeductedHours = $this->creditService->hasLeaveApplicationCtoHoursColumn()
                && $this->creditService->isCtoLeaveType($leaveType, $canonicalLeaveTypeId)
                && $targetCtoDeductedHours > 0
                    ? $targetCtoDeductedHours
                    : null;

            $hasApplicationFieldChanges = $sourcePayMode !== $targetPayMode
                || round($sourceDeductibleDays, 3) !== $targetDeductibleDays
                || round($sourceCtoDeductedHours, 2) !== round(max((float) ($targetStoredCtoDeductedHours ?? 0.0), 0.0), 2)
                || (is_array($application->selected_date_pay_status) ? $application->selected_date_pay_status : null) !== $targetSelectedDatePayStatus
                || round(max((float) ($application->linked_forced_leave_deducted_days ?? 0.0), 0.0), 3) !== $targetLinkedForcedDeduction
                || round(max((float) ($application->linked_vacation_leave_deducted_days ?? 0.0), 0.0), 3) !== $targetLinkedVacationDeduction
                || round(max((float) ($application->linked_sick_leave_deducted_days ?? 0.0), 0.0), 3) !== $targetLinkedSickDeduction;

            if (
                ! $hasApplicationFieldChanges
                && $deltaPrimaryDeduction === 0.0
                && $deltaLinkedForcedDeduction === 0.0
                && $deltaLinkedVacationDeduction === 0.0
                && $deltaLinkedSickDeduction === 0.0
            ) {
                $stats['skipped']++;

                continue;
            }

            $balanceConflictCode = 'HR_SCHEDULE_REPRICE_BALANCE_CONFLICT_'.(int) $application->id;

            try {
                DB::transaction(function () use (
                    $application,
                    $canonicalLeaveTypeId,
                    $forcedLeaveTypeId,
                    $vacationLeaveTypeId,
                    $sickLeaveTypeId,
                    $deltaPrimaryDeduction,
                    $deltaLinkedForcedDeduction,
                    $deltaLinkedVacationDeduction,
                    $deltaLinkedSickDeduction,
                    $shouldReconcileBalances,
                    $balanceConflictCode,
                    $targetPayMode,
                    $targetSelectedDatePayStatus,
                    $targetDeductibleDays,
                    $targetStoredCtoDeductedHours,
                    $targetLinkedForcedDeduction,
                    $targetLinkedVacationDeduction,
                    $targetLinkedSickDeduction,
                    $leaveType,
                    $candidatesResolver,
                    $adminControlNoResolver,
                    $employeeResolver
                ): void {
                    if ($shouldReconcileBalances) {
                        $this->creditService->applyScheduleRepricingBalanceDelta(
                            $application,
                            $canonicalLeaveTypeId,
                            $deltaPrimaryDeduction,
                            $balanceConflictCode,
                            $candidatesResolver,
                            $adminControlNoResolver,
                            $employeeResolver
                        );

                        if ($forcedLeaveTypeId !== null) {
                            $this->creditService->applyScheduleRepricingBalanceDelta(
                                $application,
                                $forcedLeaveTypeId,
                                $deltaLinkedForcedDeduction,
                                $balanceConflictCode,
                                $candidatesResolver,
                                $adminControlNoResolver,
                                $employeeResolver
                            );
                        }

                        if ($vacationLeaveTypeId !== null) {
                            $this->creditService->applyScheduleRepricingBalanceDelta(
                                $application,
                                $vacationLeaveTypeId,
                                $deltaLinkedVacationDeduction,
                                $balanceConflictCode,
                                $candidatesResolver,
                                $adminControlNoResolver,
                                $employeeResolver
                            );
                        }

                        if ($sickLeaveTypeId !== null) {
                            $this->creditService->applyScheduleRepricingBalanceDelta(
                                $application,
                                $sickLeaveTypeId,
                                $deltaLinkedSickDeduction,
                                $balanceConflictCode,
                                $candidatesResolver,
                                $adminControlNoResolver,
                                $employeeResolver
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
                        'linked_sick_leave_deducted_days' => $targetLinkedSickDeduction,
                    ]);

                    if ($application->employee_control_no && $this->creditService->isCtoLeaveType($leaveType, $canonicalLeaveTypeId)) {
                        $this->creditService->syncEmployeeCtoBalance(
                            (string) $application->employee_control_no,
                            true,
                            null,
                            $candidatesResolver,
                            $employeeResolver
                        );
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
}
