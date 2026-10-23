<?php

namespace App\Services\Leave;

use App\Http\Controllers\Concerns\FiltersEmployeeControlNos;
use App\Models\DepartmentAdmin;
use App\Models\DepartmentHead;
use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Models\LeaveApplicationLog;
use App\Models\LeaveApplicationPrintLog;
use App\Models\LeaveApplicationUpdateRequest;
use App\Models\LeaveType;
use App\Models\Notification;
use App\Services\LeaveApplicationDocumentVerificationService;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class LeaveWorkflowService
{
    use FiltersEmployeeControlNos;

    public function __construct(
        public ?LeaveCreditService $creditService = null,
        public ?LeaveCalculationService $calculationService = null,
        public ?LeaveEligibilityService $eligibilityService = null,
        public ?LeaveCertificationService $certificationService = null,
        public ?LeaveApplicationDocumentVerificationService $documentVerificationService = null,
    ) {
        $this->creditService = $creditService ?? app(LeaveCreditService::class);
        $this->calculationService = $calculationService ?? app(LeaveCalculationService::class);
        $this->eligibilityService = $eligibilityService ?? app(LeaveEligibilityService::class);
        $this->certificationService = $certificationService ?? app(LeaveCertificationService::class);
        $this->documentVerificationService = $documentVerificationService ?? app(LeaveApplicationDocumentVerificationService::class);
    }

    private function eligibilityService(): LeaveEligibilityService
    {
        return $this->eligibilityService ??= app(LeaveEligibilityService::class);
    }

    private function certificationService(): LeaveCertificationService
    {
        return $this->certificationService ??= app(LeaveCertificationService::class);
    }

    private function documentVerificationService(): LeaveApplicationDocumentVerificationService
    {
        return $this->documentVerificationService ??= app(LeaveApplicationDocumentVerificationService::class);
    }

    private function creditService(): LeaveCreditService
    {
        return $this->creditService ??= app(LeaveCreditService::class);
    }

    private function calculationService(): LeaveCalculationService
    {
        return $this->calculationService ??= app(LeaveCalculationService::class);
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

    public function hasLeaveApplicationSelectedDateLedgerValueColumns(): bool
    {
        return Schema::hasColumn('tblLeaveApplications', 'selected_date_deduction')
            && Schema::hasColumn('tblLeaveApplications', 'selected_date_without_pay');
    }

    public function applicationHasHrSelectedDateLedgerValues(LeaveApplication $app): bool
    {
        return is_array($app->selected_date_deduction)
            || is_array($app->selected_date_without_pay);
    }

    public function applyHrApplicationEdit(
        LeaveApplication $app,
        HRAccount $hr,
        array $payload,
        ?string $reason,
        ?LeaveApplicationUpdateRequest $editRequest = null,
        ?string $reviewRemarks = null,
        ?callable $candidatesResolver = null,
        ?callable $adminControlNoResolver = null,
        ?callable $employeeResolver = null
    ): LeaveApplication|JsonResponse {
        $leaveType = $app->leaveType ?? LeaveType::find((int) $app->leave_type_id);
        if (! $leaveType instanceof LeaveType) {
            return response()->json([
                'message' => 'Selected leave type is no longer available.',
            ], 422);
        }
        $app->setRelation('leaveType', $leaveType);

        $targetTotalDays = round((float) ($payload['total_days'] ?? 0.0), 2);
        if ($targetTotalDays <= 0.0) {
            return response()->json([
                'message' => 'The edited application must include at least one day.',
            ], 422);
        }

        $targetSelectedDates = is_array($payload['selected_dates'] ?? null)
            ? $this->normalizeHrEditSelectedDates($payload['selected_dates'])
            : [];
        if ($targetSelectedDates === []) {
            return response()->json([
                'message' => 'The edited application must include at least one leave date.',
            ], 422);
        }

        $targetStartDate = $targetSelectedDates[0];
        $targetEndDate = $targetSelectedDates[count($targetSelectedDates) - 1];
        $duplicateDateValidation = $this->eligibilityService()->validateNoDuplicateLeaveDates(
            (string) ($app->employee_control_no ?? ''),
            $targetStartDate,
            $targetEndDate,
            $targetSelectedDates,
            $targetTotalDays,
            (int) $app->id,
            is_array($payload['selected_date_coverage'] ?? null) ? $payload['selected_date_coverage'] : null,
            is_array($payload['selected_date_half_day_portion'] ?? null) ? $payload['selected_date_half_day_portion'] : null,
            $candidatesResolver
        );
        if ($duplicateDateValidation instanceof JsonResponse) {
            return $duplicateDateValidation;
        }

        if ($leaveType->max_days && $targetTotalDays > (float) $leaveType->max_days) {
            return response()->json([
                'message' => "This leave type is limited to {$leaveType->max_days} days per application.",
                'errors' => [
                    'total_days' => ["Maximum of {$leaveType->max_days} days allowed for {$leaveType->name}."],
                ],
            ], 422);
        }

        $targetPayMode = $this->calculationService->normalizePayMode($payload['pay_mode'] ?? null, false);
        $targetDeductibleDays = round(max((float) ($payload['deductible_days'] ?? 0.0), 0.0), 3);
        $targetWithoutPayDays = round(max((float) ($payload['without_pay_days'] ?? 0.0), 0.0), 3);
        $isApprovedStatus = $app->status === LeaveApplication::STATUS_APPROVED;
        $sourceLeaveType = LeaveType::find((int) $app->leave_type_id);
        $sourceDeductibleDays = $this->calculationService->resolveApplicationDeductibleDays($app);
        $sourceDeductsBalance = $isApprovedStatus
            && $this->creditService->applicationDeductsEmployeeBalance(false, $sourceLeaveType, $app->pay_mode);
        $targetDeductsBalance = $isApprovedStatus
            && $this->creditService->applicationDeductsEmployeeBalance(false, $leaveType, $targetPayMode);

        $forcedLeaveTypeId = $this->creditService->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->creditService->resolveVacationLeaveTypeId();
        $targetShouldDeductForcedLeave = $this->creditService->shouldLeaveTypeDeductForcedLeave(
            $leaveType,
            (int) $app->leave_type_id,
            false,
            $forcedLeaveTypeId
        );
        $targetShouldDeductVacationLeave = $this->creditService->shouldLeaveTypeDeductVacationLeave(
            $leaveType,
            (int) $app->leave_type_id,
            false,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );
        $targetIsCtoDeduction = $this->creditService->isCtoLeaveType($leaveType, (int) $app->leave_type_id);
        $balanceConflictError = 'HR_APPLICATION_EDIT_BALANCE_CONFLICT';

        if ($app->employee_control_no) {
            $this->creditService->syncEmployeeCtoBalance(
                (string) $app->employee_control_no,
                false,
                null,
                $candidatesResolver,
                $employeeResolver
            );
        }

        $updateAttributes = [
            'start_date' => $targetStartDate,
            'end_date' => $targetEndDate,
            'total_days' => $targetTotalDays,
            'deductible_days' => $targetDeductibleDays,
            'without_pay_days' => $targetWithoutPayDays,
            'cto_deducted_hours' => $this->creditService->hasLeaveApplicationCtoHoursColumn() && $targetIsCtoDeduction
                ? $this->calculationService->resolveCtoDeductedHours($targetDeductibleDays)
                : null,
            'selected_dates' => $targetSelectedDates,
            'selected_date_pay_status' => is_array($payload['selected_date_pay_status'] ?? null)
                ? $payload['selected_date_pay_status']
                : null,
            'selected_date_coverage' => is_array($payload['selected_date_coverage'] ?? null)
                ? $payload['selected_date_coverage']
                : null,
            'selected_date_half_day_portion' => is_array($payload['selected_date_half_day_portion'] ?? null)
                ? $payload['selected_date_half_day_portion']
                : null,
            'pay_mode' => $targetPayMode,
            'allow_sl_vl_cross_deduction' => (bool) ($payload['allow_sl_vl_cross_deduction'] ?? false),
        ];

        if ($this->hasLeaveApplicationSelectedDateLedgerValueColumns()) {
            $updateAttributes['selected_date_deduction'] = is_array($payload['selected_date_deduction'] ?? null)
                ? $payload['selected_date_deduction']
                : null;
            $updateAttributes['selected_date_without_pay'] = is_array($payload['selected_date_without_pay'] ?? null)
                ? $payload['selected_date_without_pay']
                : null;
        }

        try {
            DB::transaction(function () use (
                $app,
                $hr,
                $editRequest,
                $payload,
                $reason,
                $reviewRemarks,
                $updateAttributes,
                $leaveType,
                $targetStartDate,
                $targetEndDate,
                $targetSelectedDates,
                $sourceLeaveType,
                $sourceDeductibleDays,
                $sourceDeductsBalance,
                $targetDeductsBalance,
                $targetDeductibleDays,
                $targetTotalDays,
                $targetWithoutPayDays,
                $targetPayMode,
                $targetIsCtoDeduction,
                $targetShouldDeductForcedLeave,
                $forcedLeaveTypeId,
                $targetShouldDeductVacationLeave,
                $vacationLeaveTypeId,
                $balanceConflictError,
                $candidatesResolver,
                $adminControlNoResolver,
                $employeeResolver
            ): void {
                if ($sourceDeductsBalance && $sourceDeductibleDays > 0.0) {
                    $this->creditService->refundApplicationTrackedDeductions(
                        $app,
                        $sourceLeaveType,
                        $sourceDeductibleDays,
                        $balanceConflictError,
                        $adminControlNoResolver,
                        $candidatesResolver,
                        fn (LeaveApplication $a, ?LeaveType $lt = null) => $this->eligibilityService()->resolveStoredMonetizationLeaveCreditComponents($a, $lt),
                        $employeeResolver
                    );
                }

                $app->fill($updateAttributes);

                $linkedForcedLeaveDeductedDays = 0.0;
                $linkedVacationLeaveDeductedDays = 0.0;
                $linkedSickLeaveDeductedDays = 0.0;
                if ($targetDeductsBalance && $targetDeductibleDays > 0.0) {
                    $linkedDeductions = $this->creditService->deductApplicationTrackedBalances(
                        $app,
                        (int) $app->leave_type_id,
                        $targetDeductibleDays,
                        $targetTotalDays,
                        $targetIsCtoDeduction,
                        $targetShouldDeductForcedLeave,
                        $forcedLeaveTypeId,
                        $targetShouldDeductVacationLeave,
                        $vacationLeaveTypeId,
                        (bool) ($payload['allow_sl_vl_cross_deduction'] ?? false),
                        $this->creditService->resolveSickLeaveTypeId(),
                        $balanceConflictError,
                        false,
                        $adminControlNoResolver,
                        $candidatesResolver,
                        fn (LeaveApplication $a) => $this->eligibilityService()->resolveStoredMonetizationLeaveCreditComponents($a),
                        $employeeResolver,
                        fn (array $d, ?array $c, float $t, ?string $e) => $this->calculationService->resolveDateCoverageWeights($d, $c, $t, $e)
                    );
                    $linkedForcedLeaveDeductedDays = (float) ($linkedDeductions['linked_forced_leave_deducted_days'] ?? 0.0);
                    $linkedVacationLeaveDeductedDays = (float) ($linkedDeductions['linked_vacation_leave_deducted_days'] ?? 0.0);
                    $linkedSickLeaveDeductedDays = (float) ($linkedDeductions['linked_sick_leave_deducted_days'] ?? 0.0);
                } elseif ($targetDeductibleDays > 0.0) {
                    $sickLeaveTypeId = $this->creditService->resolveSickLeaveTypeId();
                    $employeeControlNo = (string) ($app->employee_control_no ?? '');
                    $allowCross = (bool) ($payload['allow_sl_vl_cross_deduction'] ?? false);
                    if (! $allowCross) {
                        $allowCross = $this->creditService->shouldLeaveTypeAllowSlVlCrossDeduction(
                            $leaveType,
                            (int) $app->leave_type_id,
                            (bool) $app->is_monetization,
                            true,
                            $sickLeaveTypeId,
                            $vacationLeaveTypeId
                        );
                    }

                    if ($allowCross) {
                        $primaryBalRecord = $this->creditService->findPreferredEmployeeLeaveBalanceRecord(
                            $employeeControlNo,
                            (int) $app->leave_type_id,
                            false,
                            $candidatesResolver
                        );
                        $primaryRaw = $primaryBalRecord ? (float) $primaryBalRecord->balance : 0.0;
                        $primaryPending = $this->creditService->resolvePendingReservedDaysForTrackedLeaveType(
                            $employeeControlNo,
                            (int) $app->leave_type_id,
                            (int) $app->id,
                            $candidatesResolver
                        );
                        $linkedPrimaryAvailable = max(round($primaryRaw - $primaryPending, 3), 0.0);

                        $linkedLeaveTypeId = $this->creditService->resolveSlVlCrossDeductionTargetLeaveTypeId(
                            $leaveType,
                            (int) $app->leave_type_id,
                            (bool) $app->is_monetization,
                            true,
                            $sickLeaveTypeId,
                            $vacationLeaveTypeId
                        );
                        $linkedAvailable = 0.0;
                        if ($linkedLeaveTypeId !== null) {
                            $linkedBalRecord = $this->creditService->findPreferredEmployeeLeaveBalanceRecord(
                                $employeeControlNo,
                                $linkedLeaveTypeId,
                                false,
                                $candidatesResolver
                            );
                            $linkedRaw = $linkedBalRecord ? (float) $linkedBalRecord->balance : 0.0;
                            $linkedPending = $this->creditService->resolvePendingReservedDaysForTrackedLeaveType(
                                $employeeControlNo,
                                $linkedLeaveTypeId,
                                (int) $app->id,
                                $candidatesResolver
                            );
                            $linkedAvailable = max(round($linkedRaw - $linkedPending, 3), 0.0);
                        }

                        $crossDeductionBreakdown = $this->creditService->resolveSlVlCrossDeductionBreakdown(
                            $leaveType,
                            (int) $app->leave_type_id,
                            (bool) $app->is_monetization,
                            $targetDeductibleDays,
                            true,
                            $sickLeaveTypeId,
                            $vacationLeaveTypeId,
                            $linkedPrimaryAvailable,
                            $linkedAvailable,
                            $targetSelectedDates,
                            is_array($payload['selected_date_pay_status'] ?? null) ? $payload['selected_date_pay_status'] : null,
                            is_array($payload['selected_date_coverage'] ?? null) ? $payload['selected_date_coverage'] : null,
                            $targetTotalDays,
                            $employeeControlNo,
                            fn (array $d, ?array $c, float $t, ?string $e) => $this->calculationService->resolveDateCoverageWeights($d, $c, $t, $e)
                        );
                        $linkedVacationLeaveDeductedDays = (float) ($crossDeductionBreakdown['linked_vacation_deduction_days'] ?? 0.0);
                        $linkedSickLeaveDeductedDays = (float) ($crossDeductionBreakdown['linked_sick_deduction_days'] ?? 0.0);
                        if ($linkedVacationLeaveDeductedDays > 0.0 || $linkedSickLeaveDeductedDays > 0.0) {
                            $app->allow_sl_vl_cross_deduction = true;
                        }
                    }
                }

                $app->linked_forced_leave_deducted_days = $linkedForcedLeaveDeductedDays;
                $app->linked_vacation_leave_deducted_days = $linkedVacationLeaveDeductedDays;
                $app->linked_sick_leave_deducted_days = $linkedSickLeaveDeductedDays;
                $app->save();

                if ($app->employee_control_no) {
                    $this->creditService->syncEmployeeCtoBalance(
                        (string) $app->employee_control_no,
                        true,
                        null,
                        $candidatesResolver,
                        $employeeResolver
                    );
                }

                if (
                    $editRequest instanceof LeaveApplicationUpdateRequest
                    && $editRequest->status === LeaveApplicationUpdateRequest::STATUS_PENDING
                    && $this->isHrApplicationEditRequestRecord($editRequest)
                ) {
                    $existingPayload = $this->normalizePendingUpdatePayload(
                        $editRequest->requested_payload,
                        fn (...$args) => $this->eligibilityService()->applyRegularLeavePolicy(...$args)
                    ) ?? [];
                    $isWithoutPay = $targetWithoutPayDays > 0.0 || $targetPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY;

                    $prevPayMode = $existingPayload['previous_pay_mode']
                        ?? $existingPayload['pay_mode']
                        ?? $app->pay_mode;
                    $prevWopDays = $existingPayload['previous_without_pay_days']
                        ?? $existingPayload['without_pay_days']
                        ?? $app->without_pay_days;
                    $prevDatePayStatus = $existingPayload['previous_selected_date_pay_status']
                        ?? $existingPayload['selected_date_pay_status']
                        ?? $app->selected_date_pay_status;

                    $updatedPayload = array_merge($existingPayload, [
                        'previous_pay_mode' => $prevPayMode,
                        'previous_without_pay_days' => $prevWopDays,
                        'previous_selected_date_pay_status' => $prevDatePayStatus,
                        'request_kind' => $this->resolveEditRequestPayloadRequestKind($editRequest) ?? LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE,
                        'start_date' => $targetStartDate,
                        'end_date' => $targetEndDate,
                        'total_days' => $targetTotalDays,
                        'deductible_days' => $targetDeductibleDays,
                        'without_pay_days' => $targetWithoutPayDays,
                        'selected_dates' => $targetSelectedDates,
                        'selected_date_pay_status' => is_array($payload['selected_date_pay_status'] ?? null)
                            ? $payload['selected_date_pay_status']
                            : null,
                        'selected_date_coverage' => is_array($payload['selected_date_coverage'] ?? null)
                            ? $payload['selected_date_coverage']
                            : null,
                        'selected_date_half_day_portion' => is_array($payload['selected_date_half_day_portion'] ?? null)
                            ? $payload['selected_date_half_day_portion']
                            : null,
                        'pay_mode' => $targetPayMode,
                        'pay_status' => $isWithoutPay ? 'Without Pay' : 'With Pay',
                        'without_pay' => $isWithoutPay,
                        'with_pay' => ! $isWithoutPay,
                    ]);

                    $editRequest->update([
                        'requested_payload' => $updatedPayload,
                        'review_remarks' => $reviewRemarks ?? 'HR updated requested dates.',
                        'status' => LeaveApplicationUpdateRequest::STATUS_APPROVED,
                        'reviewed_by_hr_id' => $hr->id,
                        'reviewed_at' => now(),
                    ]);
                }

                LeaveApplicationLog::create([
                    'leave_application_id' => $app->id,
                    'action' => LeaveApplicationLog::ACTION_HR_APPLICATION_EDITED,
                    'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                    'performed_by_id' => $hr->id,
                    'remarks' => $reviewRemarks ?? $reason ?? 'HR application override.',
                    'created_at' => now(),
                ]);
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== $balanceConflictError) {
                throw $exception;
            }

            return response()->json([
                'message' => 'Insufficient leave balance to apply this HR edit.',
            ], 422);
        }

        return $app->fresh(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests']);
    }

    public function executeHrApprovePendingUpdateRequest(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null,
        ?callable $employeeResolver = null,
        ?callable $candidatesResolver = null,
        ?callable $adminControlNoResolver = null,
        ?callable $findEmployeeByControlNo = null,
        ?callable $lockSnapshotCallback = null,
        ?callable $smsCallback = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $policyResolver = fn (...$args) => $this->eligibilityService()->applyRegularLeavePolicy(...$args);
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app, $policyResolver);
        $payload = $pendingUpdateMeta['payload'] ?? null;
        $pendingUpdateRequest = $pendingUpdateMeta['request_record'] ?? null;

        if ($payload === null) {
            return response()->json([
                'message' => 'No pending update payload was found for this application.',
            ], 422);
        }
        $pendingActionType = $this->resolveUpdateRequestActionTypeFromPayload($payload);
        if ($pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL) {
            $sourceLeaveType = LeaveType::find((int) $app->leave_type_id);
            $sourceDeductibleDays = $this->calculationService->resolveApplicationDeductibleDays($app);
            $sourceDeductsBalance = $this->creditService->applicationDeductsEmployeeBalance(
                (bool) $app->is_monetization,
                $sourceLeaveType,
                $app->pay_mode
            );
            $cancelConflictError = 'HR_CANCEL_REQUEST_BALANCE_CONFLICT';

            return $this->executeHrApprovePendingCancellationRequest(
                $app,
                $hr,
                $remarks,
                $pendingUpdateRequest,
                $pendingUpdateMeta,
                refundCallback: function () use (
                    $app,
                    $sourceLeaveType,
                    $sourceDeductibleDays,
                    $sourceDeductsBalance,
                    $cancelConflictError,
                    $adminControlNoResolver,
                    $candidatesResolver,
                    $findEmployeeByControlNo
                ): void {
                    if ($sourceDeductsBalance && $sourceDeductibleDays > 0.0) {
                        $this->creditService->refundApplicationTrackedDeductions(
                            $app,
                            $sourceLeaveType,
                            $sourceDeductibleDays,
                            $cancelConflictError,
                            $adminControlNoResolver,
                            $candidatesResolver,
                            fn (LeaveApplication $a, ?LeaveType $lt = null) => $this->eligibilityService()->resolveStoredMonetizationLeaveCreditComponents($a, $lt),
                            $findEmployeeByControlNo
                        );
                    }
                },
                ctoSyncCallback: function () use ($app, $candidatesResolver, $findEmployeeByControlNo): void {
                    if ($app->employee_control_no) {
                        $this->creditService->syncEmployeeCtoBalance(
                            (string) $app->employee_control_no,
                            true,
                            null,
                            $candidatesResolver,
                            $findEmployeeByControlNo
                        );
                    }
                },
                applicationFormatter: $applicationFormatter,
                balanceConflictError: $cancelConflictError
            );
        }
        if ($pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL) {
            return $this->hrApprovePendingRecallRequest(
                $app,
                $hr,
                $remarks,
                $pendingUpdateRequest,
                $pendingUpdateMeta,
                $applicationFormatter
            );
        }

        $targetLeaveTypeId = $this->creditService->resolveCanonicalLeaveTypeId((int) ($payload['leave_type_id'] ?? 0))
            ?? (int) ($payload['leave_type_id'] ?? 0);
        $targetLeaveType = LeaveType::find($targetLeaveTypeId);
        if (! $targetLeaveType) {
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
        $targetPayMode = $this->calculationService->normalizePayMode($payload['pay_mode'] ?? null, $targetIsMonetization);

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
            : $this->calculationService->compactSelectedDatePayStatusMap(
                $this->calculationService->normalizeSelectedDatePayStatusMap($payload['selected_date_pay_status'] ?? null),
                $targetSelectedDates,
                $targetPayMode
            );
        $targetSelectedDateCoverageMetadata = $targetIsMonetization
            ? [
                'selectedDateCoverage' => null,
                'selectedDateHalfDayPortion' => null,
            ]
            : $this->calculationService->synchronizeSelectedDateCoverageMetadata(
                $this->calculationService->normalizeSelectedDateCoverageMap($payload['selected_date_coverage'] ?? null),
                $this->calculationService->mergeSelectedDateHalfDayPortionMaps(
                    $this->calculationService->normalizeSelectedDateHalfDayPortionMap($payload['selected_date_half_day_portion'] ?? null),
                    $this->calculationService->normalizeSelectedDateHalfDayPortionMap($payload['selected_date_coverage'] ?? null)
                ),
                $targetSelectedDates
            );
        $targetSelectedDateCoverage = $targetSelectedDateCoverageMetadata['selectedDateCoverage'];
        $targetSelectedDateHalfDayPortion = $targetSelectedDateCoverageMetadata['selectedDateHalfDayPortion'];
        $existingRecallDateKeys = $this->resolveEffectiveStoredRecallDateKeys(
            $app,
            fn ($a) => $this->resolveLatestUpdateMeta($a, $policyResolver)
        );
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
        $policyResolution = $this->eligibilityService()->applyRegularLeavePolicy(
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
            $app->created_at ?? $pendingUpdateRequest?->created_at ?? now(),
            $targetStartDate,
            $targetEndDate,
            (string) ($app->employee_control_no ?? ''),
            true,
            $this->trimNullableString($payload['details_of_leave'] ?? $payload['detailsOfLeave'] ?? null)
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

        if (! $targetIsMonetization && ($targetStartDate === null || $targetEndDate === null)) {
            return response()->json([
                'message' => 'Pending update request is missing start_date or end_date.',
            ], 422);
        }

        if (! $targetIsMonetization) {
            $duplicateDateValidation = $this->eligibilityService()->validateNoDuplicateLeaveDates(
                (string) ($app->employee_control_no ?? ''),
                (string) $targetStartDate,
                (string) $targetEndDate,
                is_array($targetSelectedDates) ? $targetSelectedDates : null,
                $targetTotalDays,
                (int) $app->id,
                is_array($targetSelectedDateCoverage) ? $targetSelectedDateCoverage : null,
                is_array($targetSelectedDateHalfDayPortion) ? $targetSelectedDateHalfDayPortion : null,
                $candidatesResolver
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

        $applicationEmployee = $employeeResolver !== null ? $employeeResolver($app) : null;
        if ($applicationEmployee) {
            $currentLeaveTypeId = $this->creditService->resolveCanonicalLeaveTypeId((int) ($app->leave_type_id ?? 0))
                ?? (int) ($app->leave_type_id ?? 0);
            $skipEventBasedReuseRule = $currentLeaveTypeId > 0 && $currentLeaveTypeId === $targetLeaveTypeId;
            $leaveTypeRestriction = $this->eligibilityService()->assertEmployeeCanApplyForLeaveType(
                $applicationEmployee,
                $targetLeaveType,
                $skipEventBasedReuseRule
            );
            if ($leaveTypeRestriction instanceof JsonResponse) {
                return $leaveTypeRestriction;
            }
        }

        $sourceLeaveType = LeaveType::find((int) $app->leave_type_id);
        $sourceDeductibleDays = $this->calculationService->resolveApplicationDeductibleDays($app);
        $sourceDeductsBalance = $this->creditService->applicationDeductsEmployeeBalance(
            (bool) $app->is_monetization,
            $sourceLeaveType,
            $app->pay_mode
        );
        $targetDeductsBalance = $this->creditService->applicationDeductsEmployeeBalance(
            $targetIsMonetization,
            $targetLeaveType,
            $targetPayMode
        );

        if ($targetIsMonetization) {
            $monetizationPolicyRestriction = $this->eligibilityService()->validateMonetizationPolicyRules($targetLeaveType, $targetTotalDays);
            if ($monetizationPolicyRestriction instanceof JsonResponse) {
                return $monetizationPolicyRestriction;
            }

            $currentBalance = 0.0;
            if ($app->employee_control_no) {
                $currentBalance = (float) (
                    $this->creditService->findPreferredEmployeeLeaveBalanceRecord(
                        (string) $app->employee_control_no,
                        $targetLeaveTypeId,
                        false,
                        $candidatesResolver
                    )?->balance ?? 0.0
                );
            } elseif ($app->applicant_admin_id) {
                $currentBalance = (float) (
                    $this->creditService->findAdminEmployeeLeaveBalance(
                        (int) $app->applicant_admin_id,
                        $targetLeaveTypeId,
                        false,
                        $adminControlNoResolver,
                        $candidatesResolver
                    )?->balance ?? 0.0
                );
            }

            $sourceLeaveTypeId = $this->creditService->resolveCanonicalLeaveTypeId((int) ($app->leave_type_id ?? 0))
                ?? (int) ($app->leave_type_id ?? 0);
            $canonicalTargetLeaveTypeId = $this->creditService->resolveCanonicalLeaveTypeId($targetLeaveTypeId) ?? $targetLeaveTypeId;

            if (
                $sourceDeductsBalance
                && $sourceLeaveTypeId > 0
                && $sourceLeaveTypeId === $canonicalTargetLeaveTypeId
            ) {
                $currentBalance += $this->creditService->resolveStoredPrimaryLeaveDeduction(
                    $app,
                    $sourceLeaveType,
                    $sourceDeductibleDays
                );
            }

            $effectiveMonetizationBalance = round(max($currentBalance, 0.0), 3);
            $monetizationBalanceRestriction = $this->eligibilityService()->validateMonetizationBalanceRules(
                $effectiveMonetizationBalance,
                $targetTotalDays
            );
            if ($monetizationBalanceRestriction instanceof JsonResponse) {
                return $monetizationBalanceRestriction;
            }
        }

        $forcedLeaveTypeId = $this->creditService->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->creditService->resolveVacationLeaveTypeId();
        $targetShouldDeductForcedLeave = $this->creditService->shouldLeaveTypeDeductForcedLeave(
            $targetLeaveType,
            $targetLeaveTypeId,
            $targetIsMonetization,
            $forcedLeaveTypeId
        );
        $targetShouldDeductVacationLeave = $this->creditService->shouldLeaveTypeDeductVacationLeave(
            $targetLeaveType,
            $targetLeaveTypeId,
            $targetIsMonetization,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );
        $targetIsCtoDeduction = $this->creditService->isCtoLeaveType($targetLeaveType, $targetLeaveTypeId);

        if ($app->employee_control_no) {
            $this->creditService->syncEmployeeCtoBalance(
                (string) $app->employee_control_no,
                false,
                null,
                $candidatesResolver,
                $findEmployeeByControlNo
            );
        }

        $balanceConflictError = 'HR_UPDATE_BALANCE_CONFLICT';

        try {
            $this->applyApprovedUpdateRecord(
                $app,
                $hr,
                $pendingUpdateRequest instanceof LeaveApplicationUpdateRequest ? $pendingUpdateRequest : null,
                [
                    'leave_type_id' => $targetLeaveTypeId,
                    'start_date' => $targetIsMonetization ? null : $targetStartDate,
                    'end_date' => $targetIsMonetization ? null : $targetEndDate,
                    'total_days' => $targetTotalDays,
                    'deductible_days' => $targetDeductibleDays,
                    'cto_deducted_hours' => $this->creditService->hasLeaveApplicationCtoHoursColumn() && $targetIsCtoDeduction && $targetCtoDeductedHours > 0 ? $targetCtoDeductedHours : null,
                    'reason' => $this->trimNullableString($payload['reason'] ?? null),
                    'details_of_leave' => $this->trimNullableString($payload['details_of_leave'] ?? null),
                    'selected_dates' => $targetIsMonetization ? null : $targetSelectedDates,
                    'selected_date_pay_status' => $targetIsMonetization ? null : $targetSelectedDatePayStatus,
                    'selected_date_coverage' => $targetIsMonetization ? null : $targetSelectedDateCoverage,
                    'selected_date_half_day_portion' => $targetIsMonetization ? null : $targetSelectedDateHalfDayPortion,
                    'commutation' => (string) ($payload['commutation'] ?? 'Not Requested'),
                    'pay_mode' => $targetPayMode,
                    'allow_sl_vl_cross_deduction' => (bool) ($payload['allow_sl_vl_cross_deduction'] ?? $app->allow_sl_vl_cross_deduction ?? false),
                    'linked_forced_leave_deducted_days' => 0.0,
                    'linked_vacation_leave_deducted_days' => 0.0,
                    'linked_sick_leave_deducted_days' => 0.0,
                    'attachment_required' => $targetAttachmentRequired,
                    'attachment_submitted' => $targetAttachmentSubmitted,
                    'attachment_reference' => $targetAttachmentReference,
                    'is_monetization' => $targetIsMonetization,
                    'status' => LeaveApplication::STATUS_APPROVED,
                    'hr_id' => $hr->id,
                    'hr_approved_at' => $app->hr_approved_at ?? now(),
                    'recall_effective_date' => $updatedRecallEffectiveDate,
                    'recall_selected_dates' => $updatedRecallDateKeys !== [] ? $updatedRecallDateKeys : null,
                    'remarks' => $remarks,
                ],
                $remarks,
                refundCallback: function () use (
                    $app,
                    $sourceLeaveType,
                    $sourceDeductibleDays,
                    $sourceDeductsBalance,
                    $balanceConflictError,
                    $adminControlNoResolver,
                    $candidatesResolver,
                    $findEmployeeByControlNo
                ): void {
                    if ($sourceDeductsBalance && $sourceDeductibleDays > 0.0) {
                        $this->creditService->refundApplicationTrackedDeductions(
                            $app,
                            $sourceLeaveType,
                            $sourceDeductibleDays,
                            $balanceConflictError,
                            $adminControlNoResolver,
                            $candidatesResolver,
                            fn (LeaveApplication $a, ?LeaveType $lt = null) => $this->eligibilityService()->resolveStoredMonetizationLeaveCreditComponents($a, $lt),
                            $findEmployeeByControlNo
                        );
                    }
                },
                deductCallback: function () use (
                    $app,
                    $targetDeductsBalance,
                    $targetDeductibleDays,
                    $targetLeaveTypeId,
                    $targetTotalDays,
                    $targetIsCtoDeduction,
                    $targetShouldDeductForcedLeave,
                    $forcedLeaveTypeId,
                    $targetShouldDeductVacationLeave,
                    $vacationLeaveTypeId,
                    $payload,
                    $balanceConflictError,
                    $adminControlNoResolver,
                    $candidatesResolver,
                    $findEmployeeByControlNo
                ): array {
                    if (! ($targetDeductsBalance && $targetDeductibleDays > 0.0)) {
                        return [];
                    }

                    $linkedDeductions = $this->creditService->deductApplicationTrackedBalances(
                        $app,
                        $targetLeaveTypeId,
                        $targetDeductibleDays,
                        $targetTotalDays,
                        $targetIsCtoDeduction,
                        $targetShouldDeductForcedLeave,
                        $forcedLeaveTypeId,
                        $targetShouldDeductVacationLeave,
                        $vacationLeaveTypeId,
                        (bool) ($payload['allow_sl_vl_cross_deduction'] ?? $app->allow_sl_vl_cross_deduction ?? false),
                        $this->creditService->resolveSickLeaveTypeId(),
                        $balanceConflictError,
                        false,
                        $adminControlNoResolver,
                        $candidatesResolver,
                        fn (LeaveApplication $a) => $this->eligibilityService()->resolveStoredMonetizationLeaveCreditComponents($a),
                        $findEmployeeByControlNo,
                        fn (array $d, ?array $c, float $t, ?string $e) => $this->calculationService->resolveDateCoverageWeights($d, $c, $t, $e)
                    );

                    return [
                        'linked_forced_leave_deducted_days' => (float) ($linkedDeductions['linked_forced_leave_deducted_days'] ?? 0.0),
                        'linked_vacation_leave_deducted_days' => (float) ($linkedDeductions['linked_vacation_leave_deducted_days'] ?? 0.0),
                        'linked_sick_leave_deducted_days' => (float) ($linkedDeductions['linked_sick_leave_deducted_days'] ?? 0.0),
                    ];
                },
                ctoSyncCallback: function () use ($app, $candidatesResolver, $findEmployeeByControlNo): void {
                    if ($app->employee_control_no) {
                        $this->creditService->syncEmployeeCtoBalance(
                            (string) $app->employee_control_no,
                            true,
                            null,
                            $candidatesResolver,
                            $findEmployeeByControlNo
                        );
                    }
                },
                lockSnapshotCallback: $lockSnapshotCallback !== null
                    ? fn () => $lockSnapshotCallback($app)
                    : null
            );
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== $balanceConflictError) {
                throw $exception;
            }

            return response()->json([
                'message' => 'Insufficient leave balance to apply this approved update request.',
            ], 422);
        }

        $app = $app->fresh(['leaveType', 'applicantAdmin']);
        if ($smsCallback !== null) {
            $smsCallback($app);
        }

        return response()->json([
            'message' => 'Leave update request approved by HR and changes were applied.',
            'application' => $applicationFormatter !== null ? $applicationFormatter($app) : $app->toArray(),
        ]);
    }

    public function buildRequestedLeaveUpdatePayload(
        LeaveApplication $app,
        array $validated,
        array $requestInput,
        string $requestedDetailsOfLeave,
        string $resolvedPayMode,
        bool $requestedAllowSlVlCrossDeduction,
        array $attachmentState,
        mixed $filedAt = null
    ): array|JsonResponse {
        $requestedIsMonetization = array_key_exists('is_monetization', $validated)
            ? (bool) $validated['is_monetization']
            : (bool) $app->is_monetization;

        if ($requestedIsMonetization !== (bool) $app->is_monetization) {
            return response()->json([
                'message' => 'Switching between leave and monetization modes is not allowed in update requests.',
            ], 422);
        }

        $requestedReason = array_key_exists('reason', $validated) || array_key_exists('reason_purpose', $validated)
            ? $this->trimNullableString($validated['reason'] ?? $validated['reason_purpose'] ?? null)
            : $this->trimNullableString($app->reason);

        $requestedSelectedDatePayStatus = $requestedIsMonetization
            ? null
            : $this->calculationService->normalizeSelectedDatePayStatusMap(
                array_key_exists('selected_date_pay_status', $validated)
                    ? $validated['selected_date_pay_status']
                    : (
                        $requestInput['selected_date_pay_status']
                        ?? (is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null)
                    )
            );
        $requestedSelectedDateCoverage = $requestedIsMonetization
            ? null
            : $this->calculationService->normalizeSelectedDateCoverageMap(
                array_key_exists('selected_date_coverage', $validated)
                    ? $validated['selected_date_coverage']
                    : (
                        $requestInput['selected_date_coverage']
                        ?? (is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null)
                    )
            );
        $requestedSelectedDateHalfDayPortion = $requestedIsMonetization
            ? null
            : $this->calculationService->normalizeSelectedDateHalfDayPortionMap(
                array_key_exists('selected_date_half_day_portion', $validated)
                    ? $validated['selected_date_half_day_portion']
                    : (
                        $requestInput['selected_date_half_day_portion']
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

        $requestedSelectedDatePayStatus = $requestedIsMonetization
            ? null
            : $this->calculationService->compactSelectedDatePayStatusMap(
                $requestedSelectedDatePayStatus,
                $resolvedSelectedDates,
                $resolvedPayMode
            );
        $selectedDateCoverageMetadata = $requestedIsMonetization
            ? [
                'selectedDateCoverage' => null,
                'selectedDateHalfDayPortion' => null,
            ]
            : $this->calculationService->synchronizeSelectedDateCoverageMetadata(
                $requestedSelectedDateCoverage,
                $requestedSelectedDateHalfDayPortion,
                $resolvedSelectedDates
            );
        $requestedSelectedDateCoverage = $selectedDateCoverageMetadata['selectedDateCoverage'];
        $requestedSelectedDateHalfDayPortion = $selectedDateCoverageMetadata['selectedDateHalfDayPortion'];

        $targetLeaveTypeId = $this->creditService->resolveCanonicalLeaveTypeId((int) ($validated['leave_type_id'] ?? $app->leave_type_id))
            ?? (int) ($validated['leave_type_id'] ?? $app->leave_type_id);
        $targetLeaveType = LeaveType::find($targetLeaveTypeId);
        if (! $targetLeaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
                'errors' => [
                    'leave_type_id' => ['Selected leave type is not available.'],
                ],
            ], 422);
        }

        if ($requestedIsMonetization) {
            $monetizationPolicyRestriction = $this->eligibilityService()->validateMonetizationPolicyRules($targetLeaveType, $resolvedTotalDays);
            if ($monetizationPolicyRestriction instanceof JsonResponse) {
                return $monetizationPolicyRestriction;
            }
        }

        $policyResolution = $this->eligibilityService()->applyRegularLeavePolicy(
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
            $filedAt ?? $requestInput['date_filed'] ?? $requestInput['dateOfFiling'] ?? $app->created_at ?? now(),
            $resolvedStartDate,
            $resolvedEndDate,
            (string) ($app->employee_control_no ?? ''),
            false,
            $requestedDetailsOfLeave
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

        $previousLeaveTypeId = (int) $app->leave_type_id;
        $previousLeaveTypeName = (string) (
            $app->leaveType?->name
            ?? ($previousLeaveTypeId > 0 ? LeaveType::whereKey($previousLeaveTypeId)->value('name') : '')
            ?? ''
        );

        $rawPayload = [
            'previous_leave_type_id' => $previousLeaveTypeId > 0 ? $previousLeaveTypeId : null,
            'previous_leave_type_name' => $previousLeaveTypeName !== '' ? $previousLeaveTypeName : null,
            'previous_details_of_leave' => $app->details_of_leave,
            'previous_reason' => $app->reason,
            'previous_start_date' => $app->start_date?->toDateString() ?: ($app->resolvedSelectedDates()[0] ?? null),
            'previous_end_date' => $app->end_date?->toDateString() ?: ($app->resolvedSelectedDates()[count($app->resolvedSelectedDates()) - 1] ?? null),
            'previous_selected_dates' => $app->resolvedSelectedDates(),
            'previous_total_days' => (float) ($app->total_days ?? 0.0),
            'previous_pay_mode' => $app->pay_mode,
            'previous_selected_date_pay_status' => is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null,
            'previous_selected_date_coverage' => is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
            'previous_selected_date_half_day_portion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'previous_without_pay_days' => (float) ($app->without_pay_days ?? 0.0),
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
            'allow_sl_vl_cross_deduction' => $requestedAllowSlVlCrossDeduction,
            'is_monetization' => $requestedIsMonetization,
            'attachment_required' => $attachmentRequired,
            'attachment_submitted' => $attachmentSubmitted,
            'attachment_reference' => $attachmentReference,
        ];

        $payload = $this->normalizePendingUpdatePayload(
            $rawPayload,
            fn (...$args) => $this->eligibilityService()->applyRegularLeavePolicy(...$args)
        );
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

        if (! (bool) $payload['is_monetization']) {
            if (($payload['start_date'] ?? null) === null || ($payload['end_date'] ?? null) === null) {
                return response()->json([
                    'message' => 'Both start_date and end_date are required for leave update requests.',
                ], 422);
            }
        }

        return $payload;
    }

    public function executeHrUpdatePayStatus(
        LeaveApplication $app,
        HRAccount $hr,
        ?array $rawSelectedDatePayStatus,
        string $requestedPayMode,
        bool $requestedAllowSlVlCrossDeduction,
        bool $hasAllowSlVlCrossDeductionInput,
        ?string $remarks = null,
        ?callable $employeeResolver = null,
        ?callable $candidatesResolver = null,
        ?callable $adminControlNoResolver = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $policyResolver = fn (...$args) => $this->eligibilityService()->applyRegularLeavePolicy(...$args);
        if (
            $app->status !== LeaveApplication::STATUS_PENDING_HR
            || $this->hasPendingApprovedUpdateRequest($app, fn ($a) => $this->resolvePendingUpdateMeta($a, $policyResolver))
        ) {
            return response()->json([
                'message' => 'WOP/WP can only be overridden during CHRMO Certification before final approval.',
            ], 422);
        }

        if ((bool) $app->is_monetization) {
            return response()->json([
                'message' => 'Monetization applications must remain with pay.',
            ], 422);
        }

        $leaveType = $app->leaveType ?? LeaveType::find((int) $app->leave_type_id);
        if (! $leaveType) {
            return response()->json([
                'message' => 'Selected leave type is no longer available.',
            ], 422);
        }
        $app->setRelation('leaveType', $leaveType);

        $selectedDates = $app->resolvedSelectedDates();
        if (! is_array($selectedDates) || $selectedDates === []) {
            return response()->json([
                'message' => 'This application has no selected dates available for pay status override.',
            ], 422);
        }

        $requestedPayStatus = $this->calculationService->normalizeSelectedDatePayStatusMap($rawSelectedDatePayStatus);
        $requestedPayStatus = $this->calculationService->compactSelectedDatePayStatusMap(
            $requestedPayStatus,
            $selectedDates,
            $requestedPayMode
        );

        $policyResolution = $this->eligibilityService()->applyRegularLeavePolicy(
            $leaveType,
            (float) $app->total_days,
            $selectedDates,
            is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
            $requestedPayStatus,
            $requestedPayMode,
            false,
            (bool) ($app->attachment_submitted ?? false),
            $this->trimNullableString($app->attachment_reference ?? null),
            false,
            $app->created_at ?? null,
            $app->start_date?->toDateString(),
            $app->end_date?->toDateString(),
            (string) ($app->employee_control_no ?? ''),
            true,
            $this->trimNullableString($app->details_of_leave ?? null)
        );
        if ($policyResolution instanceof JsonResponse) {
            return $policyResolution;
        }

        $requestedPayMode = $policyResolution['pay_mode'];
        $requestedPayStatus = $policyResolution['selected_date_pay_status'];
        $deductibleDays = (float) ($policyResolution['deductible_days'] ?? 0.0);
        $ctoDeductedHours = ($policyResolution['cto_deducted_hours'] ?? null) !== null
            ? (float) $policyResolution['cto_deducted_hours']
            : null;
        $resolvedAllowSlVlCrossDeduction = false;
        $linkedForcedLeaveReservedDays = 0.0;
        $linkedVacationLeaveReservedDays = 0.0;
        $linkedSickLeaveReservedDays = 0.0;
        $sourcePayMode = $this->calculationService->normalizePayMode($app->pay_mode ?? null, false);
        $sourceDeductibleDays = $this->calculationService->resolveApplicationDeductibleDays($app);
        $sourceDeductsBalance = $this->creditService->applicationDeductsEmployeeBalance(
            false,
            $leaveType,
            $sourcePayMode
        );
        $sourcePrimaryDeduction = $sourceDeductsBalance
            ? $this->creditService->resolveStoredPrimaryLeaveDeduction($app, $leaveType, $sourceDeductibleDays)
            : 0.0;
        $sourceLinkedVacationDeduction = $sourceDeductsBalance
            ? $this->creditService->resolveStoredLinkedVacationLeaveDeduction($app, $leaveType, $sourceDeductibleDays)
            : 0.0;
        $sourceLinkedSickDeduction = $sourceDeductsBalance
            ? $this->creditService->resolveStoredLinkedSickLeaveDeduction($app)
            : 0.0;
        $effectivePrimaryAvailableBalance = $this->creditService->resolvePendingTrackedLeaveBalanceAvailableToApplication(
            $app,
            (int) $app->leave_type_id,
            $sourcePrimaryDeduction,
            $adminControlNoResolver,
            $candidatesResolver
        );

        $eligibility = $this->eligibilityService()->validateRegularLeaveEligibility(
            (string) ($app->employee_control_no ?? ''),
            (int) $app->leave_type_id,
            (float) $app->total_days,
            $requestedPayMode,
            $deductibleDays,
            $ctoDeductedHours !== null && $ctoDeductedHours > 0.0
                ? $ctoDeductedHours
                : null,
            false,
            $employeeResolver,
            fn (string $no, int $typeId) => $this->creditService->resolveEmployeeLeaveBalanceSnapshot($no, $typeId, $candidatesResolver)
        );
        if ($eligibility instanceof JsonResponse) {
            return $eligibility;
        }

        if (($eligibility['insufficient_balance'] ?? false) === true) {
            if ($this->creditService->isCtoLeaveType($leaveType, (int) $app->leave_type_id)) {
                return $this->creditService->buildInsufficientCtoBalanceResponse($eligibility);
            }

            if (LeaveType::isForcedLeaveType($leaveType, (int) $app->leave_type_id)) {
                if ($deductibleDays > $effectivePrimaryAvailableBalance + 1e-9) {
                    return $this->creditService->buildInsufficientPrimaryLeaveBalanceResponse(
                        $leaveType,
                        $effectivePrimaryAvailableBalance,
                        $deductibleDays
                    );
                }
            } elseif ($deductibleDays <= $effectivePrimaryAvailableBalance + 1e-9) {
                // This override is reusing credits already reserved by the same pending application.
            } else {
                $sickLeaveTypeId = $this->creditService->resolveSickLeaveTypeId();
                $vacationLeaveTypeId = $this->creditService->resolveVacationLeaveTypeId();
                $linkedLeaveTypeId = $this->creditService->resolveSlVlCrossDeductionTargetLeaveTypeId(
                    $leaveType,
                    (int) $app->leave_type_id,
                    false,
                    true,
                    $sickLeaveTypeId,
                    $vacationLeaveTypeId
                );

                $canCrossDeduct = $linkedLeaveTypeId !== null && (
                    $requestedAllowSlVlCrossDeduction
                    || ! $hasAllowSlVlCrossDeductionInput
                    || (bool) ($app->allow_sl_vl_cross_deduction ?? false)
                );

                if (! $canCrossDeduct) {
                    return $this->creditService->buildInsufficientPrimaryLeaveBalanceResponse(
                        $leaveType,
                        $effectivePrimaryAvailableBalance,
                        $deductibleDays
                    );
                }

                $crossDeductionAvailableCredits = $linkedLeaveTypeId === $vacationLeaveTypeId
                    ? $this->creditService->resolvePendingTrackedLeaveBalanceAvailableToApplication(
                        $app,
                        $vacationLeaveTypeId,
                        $sourceLinkedVacationDeduction,
                        $adminControlNoResolver,
                        $candidatesResolver
                    )
                    : (
                        $linkedLeaveTypeId === $sickLeaveTypeId
                            ? $this->creditService->resolvePendingTrackedLeaveBalanceAvailableToApplication(
                                $app,
                                $sickLeaveTypeId,
                                $sourceLinkedSickDeduction,
                                $adminControlNoResolver,
                                $candidatesResolver
                            )
                            : 0.0
                    );
                $allocation = $this->eligibilityService()->resolveCreditBasedPayAllocation(
                    $selectedDates,
                    is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
                    (float) $app->total_days,
                    $effectivePrimaryAvailableBalance,
                    $requestedPayStatus,
                    (string) ($app->employee_control_no ?? ''),
                    $leaveType,
                    $this->trimNullableString($app->details_of_leave ?? null),
                    $canCrossDeduct,
                    $crossDeductionAvailableCredits
                );

                if (
                    ($allocation['pay_mode'] ?? LeaveApplication::PAY_MODE_WITH_PAY) !== $requestedPayMode
                    || (($allocation['selected_date_pay_status'] ?? null) !== $requestedPayStatus)
                    || abs((float) ($allocation['deductible_days'] ?? 0.0) - $deductibleDays) > 0.0005
                ) {
                    return $this->creditService->buildInsufficientPrimaryLeaveBalanceResponse(
                        $leaveType,
                        $effectivePrimaryAvailableBalance,
                        $deductibleDays
                    );
                }

                $requestedPayMode = $allocation['pay_mode'];
                $requestedPayStatus = $allocation['selected_date_pay_status'];
                $deductibleDays = (float) ($allocation['deductible_days'] ?? 0.0);
                $resolvedAllowSlVlCrossDeduction = $canCrossDeduct
                    && $deductibleDays > $effectivePrimaryAvailableBalance + 1e-9;

                if ($resolvedAllowSlVlCrossDeduction) {
                    $crossBreakdown = $this->creditService->resolveSlVlCrossDeductionBreakdown(
                        $leaveType,
                        (int) $app->leave_type_id,
                        false,
                        $deductibleDays,
                        true,
                        $sickLeaveTypeId,
                        $vacationLeaveTypeId,
                        $effectivePrimaryAvailableBalance,
                        $crossDeductionAvailableCredits,
                        $selectedDates,
                        $requestedPayStatus,
                        is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
                        (float) $app->total_days,
                        (string) ($app->employee_control_no ?? ''),
                        fn (array $d, ?array $c, float $t, ?string $e) => $this->calculationService->resolveDateCoverageWeights($d, $c, $t, $e)
                    );
                    $linkedVacationLeaveReservedDays = (float) ($crossBreakdown['linked_vacation_deduction_days'] ?? 0.0);
                    $linkedSickLeaveReservedDays = (float) ($crossBreakdown['linked_sick_deduction_days'] ?? 0.0);
                }
            }
        }

        $forcedLeaveTypeId = $this->creditService->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->creditService->resolveVacationLeaveTypeId();
        $linkedForcedLeaveReservedDays = $this->creditService->resolveLeaveTypeForcedLeaveDeductionDays(
            $leaveType,
            (int) $app->leave_type_id,
            false,
            $deductibleDays,
            $forcedLeaveTypeId
        );
        if (LeaveType::isForcedLeaveType($leaveType, (int) $app->leave_type_id)) {
            $linkedVacationLeaveReservedDays = round(max($deductibleDays, 0.0), 3);
        } elseif (! $resolvedAllowSlVlCrossDeduction) {
            $linkedVacationLeaveReservedDays = $this->creditService->resolveLeaveTypeVacationLeaveDeductionDays(
                $leaveType,
                (int) $app->leave_type_id,
                false,
                (float) ($app->total_days ?? $deductibleDays),
                $deductibleDays,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId,
                $effectivePrimaryAvailableBalance
            );
        }

        DB::transaction(function () use (
            $app,
            $hr,
            $remarks,
            $requestedPayMode,
            $requestedPayStatus,
            $deductibleDays,
            $ctoDeductedHours,
            $resolvedAllowSlVlCrossDeduction,
            $linkedForcedLeaveReservedDays,
            $linkedVacationLeaveReservedDays,
            $linkedSickLeaveReservedDays
        ): void {
            $app->update([
                'pay_mode' => $requestedPayMode,
                'selected_date_pay_status' => $requestedPayStatus,
                'deductible_days' => $deductibleDays,
                'cto_deducted_hours' => $this->creditService->hasLeaveApplicationCtoHoursColumn()
                    ? $ctoDeductedHours
                    : null,
                'allow_sl_vl_cross_deduction' => $resolvedAllowSlVlCrossDeduction,
                'linked_forced_leave_deducted_days' => $linkedForcedLeaveReservedDays > 0.0
                    ? $linkedForcedLeaveReservedDays
                    : null,
                'linked_vacation_leave_deducted_days' => $linkedVacationLeaveReservedDays > 0.0
                    ? $linkedVacationLeaveReservedDays
                    : null,
                'linked_sick_leave_deducted_days' => $linkedSickLeaveReservedDays > 0.0
                    ? $linkedSickLeaveReservedDays
                    : null,
            ]);

            LeaveApplicationLog::create([
                'leave_application_id' => $app->id,
                'action' => LeaveApplicationLog::ACTION_HR_PAY_STATUS_OVERRIDDEN,
                'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                'performed_by_id' => $hr->id,
                'remarks' => $remarks ?: 'HR updated the application pay status.',
                'created_at' => now(),
            ]);
        });

        $freshApplication = $app->fresh(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests']);

        return response()->json([
            'message' => 'Application pay status updated.',
            'application' => $freshApplication
                ? ($applicationFormatter !== null ? $applicationFormatter($freshApplication) : $freshApplication->toArray())
                : null,
        ]);
    }

    public function executeHrApprove(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null,
        ?callable $employeeResolver = null,
        ?callable $candidatesResolver = null,
        ?callable $adminControlNoResolver = null,
        ?callable $findEmployeeByControlNo = null,
        ?callable $lockSnapshotCallback = null,
        ?callable $smsCallback = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        if ($app->status !== LeaveApplication::STATUS_PENDING_HR) {
            return response()->json(['message' => "Cannot approve: application status is '{$app->status}', expected 'PENDING_HR'."], 422);
        }

        $policyResolver = fn (...$args) => $this->eligibilityService()->applyRegularLeavePolicy(...$args);
        if ($this->isPendingApprovedUpdateRequest($app, fn ($a) => $this->hasPendingApprovedUpdateRequest($a, fn ($b) => $this->resolvePendingUpdateMeta($b, $policyResolver)))) {
            return $this->executeHrApprovePendingUpdateRequest(
                $app,
                $hr,
                $remarks,
                $employeeResolver,
                $candidatesResolver,
                $adminControlNoResolver,
                $findEmployeeByControlNo,
                $lockSnapshotCallback,
                $smsCallback,
                $applicationFormatter
            );
        }

        $leaveType = $app->leaveType ?? LeaveType::find((int) $app->leave_type_id);
        if (! $leaveType) {
            return response()->json([
                'message' => 'Selected leave type is no longer available.',
            ], 422);
        }
        $app->setRelation('leaveType', $leaveType);

        $policyResolution = $this->eligibilityService()->applyRegularLeavePolicy(
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
            (string) ($app->employee_control_no ?? ''),
            true,
            $this->trimNullableString($app->details_of_leave ?? null)
        );
        if ($policyResolution instanceof JsonResponse) {
            return $policyResolution;
        }

        $normalizedPayMode = $policyResolution['pay_mode'];
        $resolvedSelectedDatePayStatus = $policyResolution['selected_date_pay_status'];
        $daysToDeduct = (float) ($policyResolution['deductible_days'] ?? 0);
        $ctoDeductedHours = (float) ($policyResolution['cto_deducted_hours'] ?? 0);
        if (! (bool) $app->is_monetization && $this->applicationHasHrSelectedDateLedgerValues($app)) {
            $normalizedPayMode = $this->calculationService->normalizePayMode($app->pay_mode ?? LeaveApplication::PAY_MODE_WITH_PAY, false);
            $resolvedSelectedDatePayStatus = is_array($app->selected_date_pay_status)
                ? $app->selected_date_pay_status
                : null;
            $daysToDeduct = $this->calculationService->resolveApplicationDeductibleDays($app);
            $ctoDeductedHours = $this->creditService->isCtoLeaveType($leaveType, (int) $app->leave_type_id)
                ? $this->calculationService->resolveCtoDeductedHours($daysToDeduct)
                : 0.0;
        }
        $attachmentRequired = (bool) ($policyResolution['attachment_required'] ?? false);
        $attachmentSubmitted = (bool) ($policyResolution['attachment_submitted'] ?? false);
        $attachmentReference = $policyResolution['attachment_reference'] ?? null;
        if ((bool) $app->is_monetization) {
            $attachmentRequired = (bool) ($app->attachment_required ?? false);
            $attachmentSubmitted = (bool) ($app->attachment_submitted ?? false);
            $attachmentReference = $this->trimNullableString($app->attachment_reference ?? null);
        }

        $forcedLeaveTypeId = $this->creditService->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->creditService->resolveVacationLeaveTypeId();
        $shouldDeductForcedLeave = $this->creditService->shouldDeductForcedLeaveWithVacation($app, $forcedLeaveTypeId);
        $shouldDeductVacationLeave = $this->creditService->shouldDeductVacationLeaveWithForced(
            $app,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );
        $isForcedLeaveApplication = $forcedLeaveTypeId !== null
            && ($this->creditService->resolveCanonicalLeaveTypeId((int) $app->leave_type_id) ?? (int) $app->leave_type_id) === $forcedLeaveTypeId;
        $usesVacationLeaveTopUpForScheduleExcess = $this->creditService->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
            $leaveType,
            (int) $app->leave_type_id,
            (bool) $app->is_monetization,
            $vacationLeaveTypeId
        );
        $requestedTotalDays = round((float) ($app->total_days ?? $daysToDeduct), 2);
        $requiredPrimaryLeaveDays = $this->creditService->resolvePrimaryLeaveTrackedDeductionDays(
            $leaveType,
            (int) $app->leave_type_id,
            (bool) $app->is_monetization,
            $requestedTotalDays,
            $daysToDeduct,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );
        $requiredVacationLeaveDays = 0.0;
        $requiredLinkedSickLeaveDays = 0.0;
        $storedSlVlCrossDeductionBreakdown = $this->creditService->resolveStoredSlVlCrossDeductionBreakdown(
            $app,
            $leaveType,
            $daysToDeduct
        );
        if (is_array($storedSlVlCrossDeductionBreakdown)) {
            $requiredPrimaryLeaveDays = (float) ($storedSlVlCrossDeductionBreakdown['primary_deduction_days'] ?? $requiredPrimaryLeaveDays);
            $requiredVacationLeaveDays = (float) ($storedSlVlCrossDeductionBreakdown['linked_vacation_deduction_days'] ?? 0.0);
            $requiredLinkedSickLeaveDays = (float) ($storedSlVlCrossDeductionBreakdown['linked_sick_deduction_days'] ?? 0.0);
        } else {
            $requiredVacationLeaveDays = $this->creditService->resolveLeaveTypeVacationLeaveDeductionDays(
                $leaveType,
                (int) $app->leave_type_id,
                (bool) $app->is_monetization,
                $requestedTotalDays,
                $daysToDeduct,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId
            );
        }

        $needsDeduction = $this->creditService->applicationDeductsEmployeeBalance(
            (bool) $app->is_monetization,
            $leaveType,
            $normalizedPayMode
        ) && $daysToDeduct > 0;
        $isCtoDeduction = $this->creditService->isCtoLeaveType($leaveType, (int) $app->leave_type_id);

        if ($needsDeduction && $app->employee_control_no && $isCtoDeduction) {
            $this->creditService->syncEmployeeCtoBalance(
                (string) $app->employee_control_no,
                false,
                null,
                $candidatesResolver,
                $findEmployeeByControlNo
            );
        }

        $validateStoredMonetizationBalances = function () use (
            $app,
            $leaveType,
            $adminControlNoResolver,
            $candidatesResolver
        ): ?JsonResponse {
            $employeeControlNo = $this->creditService->resolveApplicationBalanceOwnerControlNo($app, $adminControlNoResolver);
            if ($employeeControlNo === null) {
                return response()->json([
                    'message' => 'Unable to identify the employee leave balances for this monetization request.',
                ], 422);
            }

            $components = $this->eligibilityService()->resolveStoredMonetizationLeaveCreditComponents($app, $leaveType);
            foreach ($components as $component) {
                $balance = $this->creditService->findPreferredEmployeeLeaveBalanceRecord(
                    $employeeControlNo,
                    (int) $component['leave_type_id'],
                    false,
                    $candidatesResolver
                );
                $currentBalance = $balance ? (float) $balance->balance : 0.0;

                $restriction = $this->eligibilityService()->validateMonetizationBalanceRules(
                    $currentBalance,
                    (float) $component['days'],
                    'monetization_leave_credits'
                );
                if ($restriction instanceof JsonResponse) {
                    return $restriction;
                }
            }

            return null;
        };

        if ((bool) $app->is_monetization) {
            $monetizationPolicyRestriction = $this->eligibilityService()->validateMonetizationPolicyRules(
                $leaveType,
                (float) ($app->total_days ?? 0.0)
            );
            if ($monetizationPolicyRestriction instanceof JsonResponse) {
                return $monetizationPolicyRestriction;
            }

            $monetizationBalanceRestriction = $validateStoredMonetizationBalances();
            if ($monetizationBalanceRestriction instanceof JsonResponse) {
                return $monetizationBalanceRestriction;
            }
        }

        if ($needsDeduction && ! (bool) $app->is_monetization) {
            $balanceValidationResponse = $this->creditService->validateApprovalLeaveBalances(
                $app,
                $leaveType,
                $daysToDeduct,
                $isForcedLeaveApplication,
                $usesVacationLeaveTopUpForScheduleExcess,
                $requiredPrimaryLeaveDays,
                $requiredVacationLeaveDays,
                $requiredLinkedSickLeaveDays,
                $vacationLeaveTypeId,
                fn (float $bal, float $req) => $this->eligibilityService()->validateMonetizationBalanceRules($bal, $req)
            );
            if ($balanceValidationResponse instanceof JsonResponse) {
                return $balanceValidationResponse;
            }
        }

        $balanceConflictError = 'HR_APPROVAL_BALANCE_CONFLICT';
        $linkedForcedLeaveDeductedDays = 0.0;
        $linkedVacationLeaveDeductedDays = 0.0;
        $linkedSickLeaveDeductedDays = 0.0;

        try {
            DB::transaction(function () use (
                $app,
                $hr,
                $remarks,
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
                $adminControlNoResolver,
                $candidatesResolver,
                $findEmployeeByControlNo,
                $lockSnapshotCallback,
                &$linkedForcedLeaveDeductedDays,
                &$linkedVacationLeaveDeductedDays,
                &$linkedSickLeaveDeductedDays
            ): void {
                if ($needsDeduction) {
                    $linkedDeductions = $this->creditService->deductApplicationTrackedBalances(
                        $app,
                        (int) $app->leave_type_id,
                        $daysToDeduct,
                        $requestedTotalDays,
                        $isCtoDeduction,
                        $shouldDeductForcedLeave,
                        $forcedLeaveTypeId,
                        $shouldDeductVacationLeave,
                        $vacationLeaveTypeId,
                        (bool) ($app->allow_sl_vl_cross_deduction ?? false),
                        $this->creditService->resolveSickLeaveTypeId(),
                        $balanceConflictError,
                        true,
                        $adminControlNoResolver,
                        $candidatesResolver,
                        fn (LeaveApplication $a) => $this->eligibilityService()->resolveStoredMonetizationLeaveCreditComponents($a),
                        $findEmployeeByControlNo,
                        fn (array $d, ?array $c, float $t, ?string $e) => $this->calculationService->resolveDateCoverageWeights($d, $c, $t, $e)
                    );
                    $linkedForcedLeaveDeductedDays = (float) ($linkedDeductions['linked_forced_leave_deducted_days'] ?? 0.0);
                    $linkedVacationLeaveDeductedDays = (float) ($linkedDeductions['linked_vacation_leave_deducted_days'] ?? 0.0);
                    $linkedSickLeaveDeductedDays = (float) ($linkedDeductions['linked_sick_leave_deducted_days'] ?? 0.0);
                }

                $app->update([
                    'status' => LeaveApplication::STATUS_APPROVED,
                    'hr_id' => $hr->id,
                    'hr_approved_at' => now(),
                    'remarks' => $remarks,
                    'pay_mode' => $normalizedPayMode,
                    'selected_date_pay_status' => $resolvedSelectedDatePayStatus,
                    'deductible_days' => $daysToDeduct,
                    'cto_deducted_hours' => $this->creditService->hasLeaveApplicationCtoHoursColumn() && $isCtoDeduction && $ctoDeductedHours > 0 ? $ctoDeductedHours : null,
                    'linked_forced_leave_deducted_days' => $linkedForcedLeaveDeductedDays,
                    'linked_vacation_leave_deducted_days' => $linkedVacationLeaveDeductedDays,
                    'linked_sick_leave_deducted_days' => $linkedSickLeaveDeductedDays,
                    'attachment_required' => $attachmentRequired,
                    'attachment_submitted' => $attachmentSubmitted,
                    'attachment_reference' => $attachmentReference,
                ]);
                if ($lockSnapshotCallback !== null) {
                    $lockSnapshotCallback($app);
                }

                LeaveApplicationLog::create([
                    'leave_application_id' => $app->id,
                    'action' => LeaveApplicationLog::ACTION_HR_APPROVED,
                    'performed_by_type' => LeaveApplicationLog::PERFORMER_HR,
                    'performed_by_id' => $hr->id,
                    'remarks' => $remarks,
                    'created_at' => now(),
                ]);
            });
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() !== $balanceConflictError) {
                throw $exception;
            }

            if ((bool) $app->is_monetization) {
                $monetizationBalanceRestriction = $validateStoredMonetizationBalances();
                if ($monetizationBalanceRestriction instanceof JsonResponse) {
                    return $monetizationBalanceRestriction;
                }

                return response()->json([
                    'message' => 'Unable to approve monetization because leave balances changed. Please review the current balances and try again.',
                ], 422);
            }

            return $this->creditService->resolveApprovalBalanceConflictResponse(
                $app,
                $leaveType,
                $daysToDeduct,
                $isForcedLeaveApplication,
                $shouldDeductVacationLeave,
                $usesVacationLeaveTopUpForScheduleExcess,
                $requiredPrimaryLeaveDays,
                $requiredVacationLeaveDays,
                $requiredLinkedSickLeaveDays,
                $vacationLeaveTypeId,
                $isCtoDeduction
            );
        }

        if ($isCtoDeduction && $app->employee_control_no) {
            $this->creditService->syncEmployeeCtoBalance(
                (string) $app->employee_control_no,
                true,
                null,
                $candidatesResolver,
                $findEmployeeByControlNo
            );
        }

        $this->notifyApplicantOfHrApproval($app, $smsCallback);

        return response()->json([
            'message' => 'Application approved by HR.',
            'application' => $applicationFormatter !== null
                ? $applicationFormatter($app->fresh(['leaveType', 'applicantAdmin']))
                : $app->fresh(['leaveType', 'applicantAdmin'])?->toArray(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  null|callable(string): array<int, string>  $candidatesResolver
     * @param  null|callable(string): ?object  $findEmployeeByControlNo
     * @param  null|callable(LeaveApplication): array<string, mixed>  $applicationFormatter
     */
    public function resolveDetailsOfLeaveFromRequest(Request $request, array $validated): ?string
    {
        $validatedDetailsOfLeave = $this->trimNullableString($validated['details_of_leave'] ?? null);
        if ($validatedDetailsOfLeave !== null) {
            $normalizedValidatedPayload = $this->eligibilityService()->normalizeDetailsOfLeavePayload($validatedDetailsOfLeave);
            if ($normalizedValidatedPayload !== null) {
                return $this->eligibilityService()->encodeDetailsOfLeavePayload($normalizedValidatedPayload);
            }

            return $validatedDetailsOfLeave;
        }

        $normalizedRequestPayload = $this->eligibilityService()->normalizeDetailsOfLeavePayload($request->input('details'));
        if ($normalizedRequestPayload !== null) {
            return $this->eligibilityService()->encodeDetailsOfLeavePayload($normalizedRequestPayload);
        }

        return null;
    }

    public function resolveRequestedDetailsOfLeaveForUpdate(
        Request $request,
        array $validated,
        LeaveApplication $app
    ): ?string {
        if (array_key_exists('details_of_leave', $validated) || $request->exists('details')) {
            return $this->resolveDetailsOfLeaveFromRequest($request, $validated);
        }

        return $this->trimNullableString($app->details_of_leave);
    }

    public function resolveRequestedPayMode(
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
            return $this->calculationService->normalizePayMode($explicitPayMode);
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

        $payStatusMode = $this->calculationService->resolvePayModeFromStatusValue(
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
                $mode = $this->calculationService->resolvePayModeFromStatusValue($value);
                if ($mode === LeaveApplication::PAY_MODE_WITH_PAY) {
                    $hasWithPay = true;
                } elseif ($mode === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                    $hasWithoutPay = true;
                }
            }

            if ($hasWithoutPay && ! $hasWithPay) {
                return LeaveApplication::PAY_MODE_WITHOUT_PAY;
            }

            if ($hasWithPay) {
                return LeaveApplication::PAY_MODE_WITH_PAY;
            }
        }

        return $this->calculationService->normalizePayMode($fallback);
    }

    public function resolveRequestSlVlCrossDeductionFlag(
        Request $request,
        array $validated,
        ?bool $fallback = null
    ): bool {
        $candidateKeys = [
            'allow_sl_vl_cross_deduction',
            'allowSlVlCrossDeduction',
            'use_other_sl_vl_balance',
            'useOtherSlVlBalance',
        ];

        foreach ($candidateKeys as $key) {
            $value = array_key_exists($key, $validated)
                ? $validated[$key]
                : $request->input($key);
            if ($value === null) {
                continue;
            }

            $normalizedFlag = $this->normalizeBooleanFlag($value);
            if ($normalizedFlag !== null) {
                return $normalizedFlag;
            }
        }

        return $fallback === true;
    }

    /**
     * @return array{attachment_submitted: bool, attachment_reference: ?string}
     */
    public function resolveAttachmentStateFromRequest(
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
            if (! array_key_exists($key, $validated) && $request->input($key) === null) {
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

        if (! $submitted) {
            $reference = null;
        }

        return [
            'attachment_submitted' => $submitted,
            'attachment_reference' => $reference,
        ];
    }

    /**
     * @param  array<int, array{leave_type_id:int, leave_type_name:string, days:float}>  $components
     * @param  null|callable(string): array<int, string>  $candidatesResolver
     */
    public function validateMonetizationComponentBalances(
        string $employeeControlNo,
        array $components,
        ?callable $candidatesResolver = null
    ): ?JsonResponse {
        foreach ($components as $component) {
            $balance = $this->creditService->findPreferredEmployeeLeaveBalanceRecord(
                $employeeControlNo,
                (int) $component['leave_type_id'],
                false,
                $candidatesResolver
            );
            $currentBalance = $balance ? (float) $balance->balance : 0.0;

            $restriction = $this->eligibilityService()->validateMonetizationBalanceRules(
                $currentBalance,
                (float) $component['days'],
                'monetization_leave_credits'
            );
            if ($restriction instanceof JsonResponse) {
                return $restriction;
            }
        }

        return null;
    }

    public function resolveEmployeeDisplayName(
        LeaveApplication $app,
        ?callable $findEmployeeByControlNo = null
    ): string {
        $controlNo = trim((string) ($app->employee_control_no ?? ''));
        $employee = $controlNo !== ''
            ? ($findEmployeeByControlNo !== null
                ? $findEmployeeByControlNo($controlNo)
                : HrisEmployee::findByControlNo($controlNo))
            : null;
        $name = trim((string) (($employee?->firstname ?? '').' '.($employee?->surname ?? '')));
        if ($name !== '') {
            return $name;
        }

        $adminFallback = trim((string) ($app->applicantAdmin?->full_name ?? ''));
        if ($adminFallback !== '') {
            return $adminFallback;
        }

        if ($app->employee_control_no !== null) {
            return 'Employee '.$this->eligibilityService()->normalizeControlNo((string) $app->employee_control_no);
        }

        return 'Employee';
    }

    public function resolveLeaveApplicationUpdateEmployeeControlNo(
        LeaveApplication $app,
        ?callable $findEmployeeByControlNo = null
    ): ?string {
        $controlNo = app(LeaveCertificationService::class)->resolveApplicationBalanceLookupControlNo(
            $app,
            null,
            $findEmployeeByControlNo
        );
        if ($controlNo !== null) {
            $controlNo = trim($controlNo);

            return $controlNo !== '' ? $controlNo : null;
        }

        $fallbackControlNo = trim((string) ($app->employee_control_no ?? ''));

        return $fallbackControlNo !== '' ? $fallbackControlNo : null;
    }

    public function resolveLeaveApplicationUpdateEmployeeName(
        LeaveApplication $app,
        ?callable $findEmployeeByControlNo = null
    ): ?string {
        $storedEmployeeName = trim((string) ($app->employee_name ?? ''));
        if ($storedEmployeeName !== '') {
            return $storedEmployeeName;
        }

        $controlNo = trim((string) ($app->employee_control_no ?? ''));
        $employee = $controlNo !== ''
            ? ($findEmployeeByControlNo !== null
                ? $findEmployeeByControlNo($controlNo)
                : HrisEmployee::findByControlNo($controlNo))
            : null;
        $employeeName = trim(self::formatEmployeeFullName($employee));
        if ($employeeName !== '') {
            return $employeeName;
        }

        $adminFallback = trim((string) ($app->applicantAdmin?->full_name ?? ''));

        return $adminFallback !== '' ? $adminFallback : null;
    }

    public function isHrAccessControlOwner(HRAccount $hr): bool
    {
        return app(\App\Services\HrAccessControlService::class)->isAccessControlOwner($hr);
    }

    public function formatHrApplicationEditRequest(
        LeaveApplicationUpdateRequest $editRequest,
        ?callable $applicationFormatter = null
    ): array {
        $application = $editRequest->leaveApplication;
        $payload = is_array($editRequest->requested_payload) ? $editRequest->requested_payload : [];

        return [
            'id' => $editRequest->id,
            'leave_application_id' => $editRequest->leave_application_id,
            'status' => strtoupper(trim((string) $editRequest->status)),
            'previous_status' => strtoupper(trim((string) ($editRequest->previous_status ?? ''))),
            'employee_control_no' => $editRequest->employee_control_no,
            'employee_name' => $editRequest->employee_name,
            'leave_type_name' => $payload['leave_type_name'] ?? $application?->leaveType?->name,
            'requested_by' => $this->trimNullableString($editRequest->requested_by_control_no),
            'requested_at' => $editRequest->requested_at?->toIso8601String(),
            'requested_reason' => $this->trimNullableString($editRequest->requested_reason),
            'reviewed_by_hr_id' => $editRequest->reviewed_by_hr_id,
            'reviewed_by' => $editRequest->reviewedByHr?->full_name,
            'reviewed_at' => $editRequest->reviewed_at?->toIso8601String(),
            'review_remarks' => $this->trimNullableString($editRequest->review_remarks),
            'payload' => $payload,
            'application' => $application instanceof LeaveApplication
                ? ($applicationFormatter !== null
                    ? $applicationFormatter($application->loadMissing(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests']))
                    : $application->loadMissing(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])->toArray())
                : null,
        ];
    }

    public function executeRegularLeaveSubmission(
        Request $request,
        array $validated,
        object $actor,
        ?object $employee,
        bool $isAdminFiling = false,
        ?callable $candidatesResolver = null,
        ?callable $findEmployeeByControlNo = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $resolvedDetailsOfLeave = $this->resolveDetailsOfLeaveFromRequest($request, $validated);

        $requestedPayMode = $this->resolveRequestedPayMode(
            $request,
            $validated,
            false,
            LeaveApplication::PAY_MODE_WITH_PAY
        );
        $selectedDatePayStatus = $this->calculationService->normalizeSelectedDatePayStatusMap(
            array_key_exists('selected_date_pay_status', $validated)
                ? $validated['selected_date_pay_status']
                : $request->input('selected_date_pay_status')
        );
        $selectedDateCoverage = $this->calculationService->normalizeSelectedDateCoverageMap(
            array_key_exists('selected_date_coverage', $validated)
                ? $validated['selected_date_coverage']
                : $request->input('selected_date_coverage')
        );
        $selectedDateHalfDayPortion = $this->calculationService->normalizeSelectedDateHalfDayPortionMap(
            array_key_exists('selected_date_half_day_portion', $validated)
                ? $validated['selected_date_half_day_portion']
                : $request->input('selected_date_half_day_portion')
        );
        $resolvedSelectedDates = LeaveApplication::resolveSelectedDates(
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            is_array($validated['selected_dates'] ?? null) ? $validated['selected_dates'] : null,
            (float) $validated['total_days']
        );
        $selectedDatePayStatus = $this->calculationService->compactSelectedDatePayStatusMap(
            $selectedDatePayStatus,
            $resolvedSelectedDates,
            $requestedPayMode
        );
        $selectedDateCoverageMetadata = $this->calculationService->synchronizeSelectedDateCoverageMetadata(
            $selectedDateCoverage,
            $selectedDateHalfDayPortion,
            $resolvedSelectedDates
        );
        $selectedDateCoverage = $selectedDateCoverageMetadata['selectedDateCoverage'];
        $selectedDateHalfDayPortion = $selectedDateCoverageMetadata['selectedDateHalfDayPortion'];
        $validated['leave_type_id'] = $this->creditService->resolveCanonicalLeaveTypeId((int) $validated['leave_type_id'])
            ?? (int) $validated['leave_type_id'];
        $leaveType = LeaveType::find((int) $validated['leave_type_id']);
        if (! $leaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
                'errors' => [
                    'leave_type_id' => ['Selected leave type is not available.'],
                ],
            ], 422);
        }

        $attachmentState = $this->resolveAttachmentStateFromRequest($request, $validated);
        $allowSlVlCrossDeduction = $isAdminFiling
            ? $this->resolveRequestSlVlCrossDeductionFlag($request, $validated)
            : false;
        $appliedAllowSlVlCrossDeduction = false;
        $linkedForcedLeaveReservedDays = 0.0;
        $linkedVacationLeaveReservedDays = 0.0;
        $linkedSickLeaveReservedDays = 0.0;

        $controlNoForPolicy = $isAdminFiling
            ? (string) ($validated['employee_control_no'] ?? $employee?->control_no ?? '')
            : (string) ($employee?->control_no ?? $validated['employee_control_no'] ?? '');

        $policyResolution = $this->eligibilityService()->applyRegularLeavePolicy(
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
            (string) ($validated['start_date'] ?? ''),
            (string) ($validated['end_date'] ?? ''),
            $controlNoForPolicy,
            false,
            $resolvedDetailsOfLeave
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

        if ($isAdminFiling) {
            if ($actor instanceof DepartmentAdmin) {
                $actor->loadMissing('department');
            }
            if (! $employee && $findEmployeeByControlNo !== null) {
                $employee = $findEmployeeByControlNo((string) ($validated['employee_control_no'] ?? ''));
            }
            if (! $employee) {
                return response()->json([
                    'message' => 'Employee not found in HRIS.',
                    'errors' => ['employee_control_no' => ['Selected employee was not found in HRIS.']],
                ], 422);
            }

            if ($actor instanceof DepartmentAdmin && ! $this->eligibilityService()->adminDepartmentCanAccessEmployee(
                $actor->department_id !== null ? (int) $actor->department_id : null,
                $actor->department?->name,
                (string) $employee->control_no,
                $employee
            )) {
                return response()->json(['message' => 'You can only file leave for employees in your department.'], 403);
            }
        }

        $employeeControlNo = (string) ($employee->control_no ?? $validated['employee_control_no'] ?? '');

        $duplicateDateValidation = $this->eligibilityService()->validateNoDuplicateLeaveDates(
            $employeeControlNo,
            (string) ($validated['start_date'] ?? ''),
            (string) ($validated['end_date'] ?? ''),
            $resolvedSelectedDates,
            $validated['total_days'],
            null,
            $selectedDateCoverage,
            $selectedDateHalfDayPortion,
            $candidatesResolver
        );
        if ($duplicateDateValidation instanceof JsonResponse) {
            return $duplicateDateValidation;
        }

        $pendingLeaveTypeValidation = $this->eligibilityService()->validateNoPendingApplicationForLeaveType(
            $employeeControlNo,
            (int) $validated['leave_type_id'],
            null,
            $candidatesResolver
        );
        if ($pendingLeaveTypeValidation instanceof JsonResponse) {
            return $pendingLeaveTypeValidation;
        }

        $eligibility = $this->eligibilityService()->validateRegularLeaveEligibility(
            $employeeControlNo,
            (int) $validated['leave_type_id'],
            (float) $validated['total_days'],
            $requestedPayMode,
            $deductibleDays,
            $ctoDeductedHours > 0 ? $ctoDeductedHours : null,
            false,
            $findEmployeeByControlNo,
            fn (string $no, int $typeId) => $this->creditService->resolveEmployeeLeaveBalanceSnapshot($no, $typeId, $candidatesResolver)
        );
        if ($eligibility instanceof JsonResponse) {
            return $eligibility;
        }
        if (($eligibility['insufficient_balance'] ?? false) === true) {
            if ($this->creditService->isCtoLeaveType($leaveType, (int) $validated['leave_type_id'])) {
                return $this->creditService->buildInsufficientCtoBalanceResponse($eligibility);
            }

            if (LeaveType::isForcedLeaveType($leaveType, (int) $validated['leave_type_id'])) {
                return $this->creditService->buildInsufficientPrimaryLeaveBalanceResponse(
                    $leaveType,
                    (float) ($eligibility['available_balance'] ?? 0.0),
                    (float) ($eligibility['required_balance_days'] ?? $deductibleDays)
                );
            }

            $crossDeductionAvailableCredits = $this->creditService->resolveAvailableSlVlCrossDeductionBalanceForControlNo(
                $employeeControlNo,
                $leaveType,
                (int) $validated['leave_type_id'],
                false,
                $allowSlVlCrossDeduction,
                $candidatesResolver,
                $findEmployeeByControlNo
            );
            $allocation = $this->eligibilityService()->resolveCreditBasedPayAllocation(
                $resolvedSelectedDates,
                $selectedDateCoverage,
                (float) $validated['total_days'],
                (float) ($eligibility['available_balance'] ?? 0.0),
                $selectedDatePayStatus,
                $employeeControlNo,
                $leaveType,
                $resolvedDetailsOfLeave,
                $allowSlVlCrossDeduction,
                $crossDeductionAvailableCredits
            );
            $requestedPayMode = $allocation['pay_mode'];
            $selectedDatePayStatus = $allocation['selected_date_pay_status'];
            $deductibleDays = (float) ($allocation['deductible_days'] ?? 0.0);
            $appliedAllowSlVlCrossDeduction = $allowSlVlCrossDeduction
                && $deductibleDays > round(max((float) ($eligibility['available_balance'] ?? 0.0), 0.0), 3) + 1e-9;
            if ($appliedAllowSlVlCrossDeduction) {
                $crossBreakdown = $this->creditService->resolveSlVlCrossDeductionBreakdown(
                    $leaveType,
                    (int) $validated['leave_type_id'],
                    false,
                    $deductibleDays,
                    true,
                    $this->creditService->resolveSickLeaveTypeId(),
                    $this->creditService->resolveVacationLeaveTypeId(),
                    (float) ($eligibility['available_balance'] ?? 0.0),
                    $crossDeductionAvailableCredits,
                    $resolvedSelectedDates,
                    $selectedDatePayStatus,
                    $selectedDateCoverage,
                    (float) $validated['total_days'],
                    $employeeControlNo,
                    fn (array $d, ?array $c, float $t, ?string $e) => $this->calculationService->resolveDateCoverageWeights($d, $c, $t, $e)
                );
                $linkedVacationLeaveReservedDays = (float) ($crossBreakdown['linked_vacation_deduction_days'] ?? 0.0);
                $linkedSickLeaveReservedDays = (float) ($crossBreakdown['linked_sick_deduction_days'] ?? 0.0);
            }
        }

        $forcedLeaveTypeId = $this->creditService->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->creditService->resolveVacationLeaveTypeId();
        if (LeaveType::isForcedLeaveType($leaveType, (int) $validated['leave_type_id'])) {
            $linkedVacationLeaveReservedDays = round(max($deductibleDays, 0.0), 3);
        } elseif ($isAdminFiling && ! $appliedAllowSlVlCrossDeduction) {
            $linkedVacationLeaveReservedDays = $this->creditService->resolveLeaveTypeVacationLeaveDeductionDays(
                $leaveType,
                (int) $validated['leave_type_id'],
                false,
                (float) $validated['total_days'],
                $deductibleDays,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId,
                (float) ($eligibility['available_balance'] ?? 0.0)
            );
        }

        $linkedForcedLeaveReservedDays = $this->creditService->resolveLeaveTypeForcedLeaveDeductionDays(
            $leaveType,
            (int) $validated['leave_type_id'],
            false,
            $deductibleDays,
            $forcedLeaveTypeId
        );

        $attributes = [
            'employee_control_no' => $employeeControlNo,
            'leave_type_id' => $validated['leave_type_id'],
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'total_days' => $validated['total_days'],
            'deductible_days' => $deductibleDays,
            'cto_deducted_hours' => $this->creditService->hasLeaveApplicationCtoHoursColumn() && $ctoDeductedHours > 0 ? $ctoDeductedHours : null,
            'reason' => $validated['reason'] ?? null,
            'details_of_leave' => $resolvedDetailsOfLeave,
            'selected_dates' => $resolvedSelectedDates,
            'selected_date_pay_status' => $selectedDatePayStatus,
            'selected_date_coverage' => $selectedDateCoverage,
            'selected_date_half_day_portion' => $selectedDateHalfDayPortion,
            'commutation' => $validated['commutation'] ?? 'Not Requested',
            'pay_mode' => $requestedPayMode,
            'allow_sl_vl_cross_deduction' => $appliedAllowSlVlCrossDeduction,
            'linked_forced_leave_deducted_days' => $linkedForcedLeaveReservedDays > 0.0 ? $linkedForcedLeaveReservedDays : null,
            'linked_vacation_leave_deducted_days' => $linkedVacationLeaveReservedDays > 0.0 ? $linkedVacationLeaveReservedDays : null,
            'linked_sick_leave_deducted_days' => $linkedSickLeaveReservedDays > 0.0 ? $linkedSickLeaveReservedDays : null,
            'attachment_required' => $attachmentRequired,
            'attachment_submitted' => $attachmentSubmitted,
            'attachment_reference' => $attachmentReference,
        ];

        if ($isAdminFiling && $actor instanceof DepartmentAdmin) {
            $app = $this->createAdminFiledApplication(
                $actor,
                $attributes,
                'Filed by department admin on behalf of employee.',
                $findEmployeeByControlNo
            );

            $employeeName = trim((string) (($employee->firstname ?? '').' '.($employee->surname ?? '')));
            if ($employeeName === '') {
                $employeeName = $this->resolveEmployeeDisplayName($app, $findEmployeeByControlNo);
            }

            $this->notifyHrAccountsOfAdminFiledApplication($app, $employeeName, false);

            return response()->json([
                'message' => 'Leave application filed successfully and forwarded to HR.',
                'application' => $applicationFormatter !== null
                    ? $applicationFormatter($app->fresh(['leaveType']))
                    : $app->fresh(['leaveType'])?->toArray(),
            ], 201);
        }

        $app = $this->createEmployeeFiledApplication($actor, $attributes, null, $findEmployeeByControlNo);
        if ($employee !== null) {
            $this->notifyDepartmentAdminsOfEmployeeFiledApplication($app, $employee);
        }

        return response()->json([
            'message' => 'Leave application submitted successfully.',
            'application' => $applicationFormatter !== null
                ? $applicationFormatter($app->fresh('leaveType'))
                : $app->fresh('leaveType')?->toArray(),
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  null|callable(string): array<int, string>  $candidatesResolver
     * @param  null|callable(string): ?object  $findEmployeeByControlNo
     * @param  null|callable(LeaveApplication): array<string, mixed>  $applicationFormatter
     */
    public function executeMonetizationSubmission(
        Request $request,
        array $validated,
        object $actor,
        ?object $employee,
        bool $isAdminFiling = false,
        ?callable $candidatesResolver = null,
        ?callable $findEmployeeByControlNo = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        if ($isAdminFiling) {
            if ($actor instanceof DepartmentAdmin) {
                $actor->loadMissing('department');
            }
            if (! $employee && $findEmployeeByControlNo !== null) {
                $employee = $findEmployeeByControlNo((string) ($validated['employee_control_no'] ?? ''));
            }
            if (! $employee) {
                return response()->json([
                    'message' => 'Employee not found in HRIS.',
                    'errors' => ['employee_control_no' => ['Selected employee was not found in HRIS.']],
                ], 422);
            }

            if ($actor instanceof DepartmentAdmin && ! $this->eligibilityService()->adminDepartmentCanAccessEmployee(
                $actor->department_id !== null ? (int) $actor->department_id : null,
                $actor->department?->name,
                (string) $employee->control_no,
                $employee
            )) {
                return response()->json(['message' => 'You can only file for employees in your department.'], 403);
            }
        }

        $leaveType = LeaveType::find($validated['leave_type_id']);
        $leaveTypeRestriction = ($leaveType && $employee)
            ? $this->eligibilityService()->assertEmployeeCanApplyForLeaveType(
                $employee,
                $leaveType,
                false
            )
            : null;
        if ($leaveTypeRestriction instanceof JsonResponse) {
            return $leaveTypeRestriction;
        }

        $monetizationComponents = $this->eligibilityService()->normalizeMonetizationLeaveCreditComponents($validated, $leaveType);
        if ($monetizationComponents instanceof JsonResponse) {
            return $monetizationComponents;
        }

        $requestedDays = $this->eligibilityService()->sumMonetizationComponentDays($monetizationComponents);
        $employeeControlNo = (string) ($employee->control_no ?? $validated['employee_control_no'] ?? '');

        $monetizationBalanceRestriction = $this->validateMonetizationComponentBalances(
            $employeeControlNo,
            $monetizationComponents,
            $candidatesResolver
        );
        if ($monetizationBalanceRestriction instanceof JsonResponse) {
            return $monetizationBalanceRestriction;
        }

        $attachmentState = $this->resolveAttachmentStateFromRequest($request, $validated);
        $attachmentRequired = $this->eligibilityService()->monetizationComponentsRequireAttachment($monetizationComponents);
        if ($attachmentRequired && ! (bool) ($attachmentState['attachment_submitted'] ?? false)) {
            $requiredDocumentMessage = 'Supporting attachment is required when Vacation Leave or Sick Leave monetization exceeds 10 days.';

            return response()->json([
                'message' => $requiredDocumentMessage,
                'errors' => [
                    'attachment' => [$requiredDocumentMessage],
                ],
            ], 422);
        }

        $equivalentAmount = null;
        $salary = $request->input('salary');
        if ($salary && is_numeric($salary) && (float) $salary > 0) {
            $dailyRate = (float) $salary / 22;
            $equivalentAmount = round($requestedDays * $dailyRate, 2);
        }

        $primaryLeaveTypeId = $this->eligibilityService()->resolvePrimaryMonetizationLeaveTypeId(
            $monetizationComponents,
            (int) $validated['leave_type_id']
        );
        $monetizationPayload = $this->eligibilityService()->serializeMonetizationLeaveCreditComponents($monetizationComponents);

        $attributes = [
            'employee_control_no' => $employeeControlNo,
            'leave_type_id' => $primaryLeaveTypeId,
            'start_date' => null,
            'end_date' => null,
            'total_days' => $requestedDays,
            'deductible_days' => $requestedDays,
            'reason' => $validated['reason'] ?? null,
            'details_of_leave' => $validated['details_of_leave'] ?? null,
            'is_monetization' => true,
            'commutation' => LeaveApplication::MONETIZATION_REQUIRED_COMMUTATION,
            'pay_mode' => LeaveApplication::PAY_MODE_WITH_PAY,
            'equivalent_amount' => $equivalentAmount,
            'monetization_leave_credits' => $monetizationPayload,
            'attachment_required' => $attachmentRequired,
            'attachment_submitted' => (bool) ($attachmentState['attachment_submitted'] ?? false),
            'attachment_reference' => $attachmentState['attachment_reference'] ?? null,
        ];

        $monetizationLabel = $this->eligibilityService()->formatMonetizationComponentsLabel($monetizationComponents);

        if ($isAdminFiling && $actor instanceof DepartmentAdmin) {
            $app = $this->createAdminFiledApplication(
                $actor,
                $attributes,
                'Monetization filed by department admin on behalf of employee.',
                $findEmployeeByControlNo
            );

            $employeeName = trim((string) (($employee->firstname ?? '').' '.($employee->surname ?? '')));
            if ($employeeName === '') {
                $employeeName = $this->resolveEmployeeDisplayName($app, $findEmployeeByControlNo);
            }

            $this->notifyHrAccountsOfAdminFiledApplication($app, $employeeName, true, $monetizationLabel);

            return response()->json([
                'message' => 'Monetization request filed successfully and forwarded to HR.',
                'application' => $applicationFormatter !== null
                    ? $applicationFormatter($app->fresh(['leaveType']))
                    : $app->fresh(['leaveType'])?->toArray(),
                'equivalent_amount' => $equivalentAmount,
            ], 201);
        }

        $app = $this->createEmployeeFiledApplication($actor, $attributes, null, $findEmployeeByControlNo);
        if ($employee !== null) {
            $this->notifyDepartmentAdminsOfEmployeeFiledApplication($app, $employee, $monetizationLabel);
        }

        return response()->json([
            'message' => 'Monetization request submitted successfully.',
            'application' => $applicationFormatter !== null
                ? $applicationFormatter($app->fresh('leaveType'))
                : $app->fresh('leaveType')?->toArray(),
            'equivalent_amount' => $equivalentAmount,
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  null|callable(string): array<int, string>  $candidatesResolver
     * @param  null|callable(string): ?object  $findEmployeeByControlNo
     * @param  null|callable(LeaveApplication): array<string, mixed>  $applicationFormatter
     */
    public function executeErmsRequestCancel(
        array $validated,
        object $employee,
        string $controlNo,
        ?callable $candidatesResolver = null,
        ?callable $findEmployeeByControlNo = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $applicationId = (int) $validated['leave_application_id'];
        $candidates = $candidatesResolver !== null
            ? $candidatesResolver($controlNo)
            : $this->eligibilityService()->controlNoCandidates($controlNo, $findEmployeeByControlNo);

        $app = LeaveApplication::query()
            ->with('leaveType')
            ->where('id', $applicationId)
            ->where(function ($query) use ($candidates): void {
                $query->whereIn('employee_control_no', $candidates)
                    ->orWhereHas('applicantAdmin', function ($adminQuery) use ($candidates): void {
                        $adminQuery->whereIn('employee_control_no', $candidates);
                    });
            })
            ->first();

        if (! $app) {
            return response()->json(['message' => 'Leave application not found for this employee.'], 404);
        }

        if ($app->status !== LeaveApplication::STATUS_APPROVED) {
            return response()->json([
                'message' => "Cannot request cancellation: application status is '{$this->ermsStatusLabel($app->status)}'. Only approved applications can request cancellation.",
            ], 422);
        }

        $policyResolver = fn (...$args) => $this->eligibilityService()->applyRegularLeavePolicy(...$args);
        if ($this->hasPendingApprovedUpdateRequest($app, fn ($a) => $this->resolvePendingUpdateMeta($a, $policyResolver))) {
            return response()->json([
                'message' => 'There is already a pending approved leave request action for this application.',
            ], 422);
        }

        $leadTimeValidation = $this->eligibilityService()->validateApprovedLeaveRequestLeadTime($app, 'cancel');
        if ($leadTimeValidation instanceof JsonResponse) {
            return $leadTimeValidation;
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

        $requestedUpdatePayload = $this->buildRequestedLeaveCancellationPayload(
            $app,
            $requestReason,
            fn ($id) => $this->creditService->resolveCanonicalLeaveTypeId($id),
            fn ($a) => $this->calculationService->resolveApplicationDeductibleDays($a),
            fn ($mode, $isMonetization) => $this->calculationService->normalizePayMode($mode, $isMonetization)
        );

        $performedById = (int) ltrim((string) $employee->control_no, '0');
        if ($performedById <= 0) {
            $performedById = (int) ($app->employee_control_no ?: 1);
        }

        $this->submitApprovedLeaveCancellationRequest(
            $app,
            $requestedUpdatePayload,
            $remarksLine,
            $requestReason,
            $performedById,
            $this->resolveLeaveApplicationUpdateEmployeeControlNo($app, $findEmployeeByControlNo),
            $this->resolveLeaveApplicationUpdateEmployeeName($app, $findEmployeeByControlNo)
        );

        $this->notifyDepartmentAdminsOfApprovedLeaveCancellationRequest(
            $app,
            $employee,
            $requestReason
        );

        return response()->json([
            'message' => 'Leave cancellation request submitted and forwarded to department admin for approval.',
            'application' => $applicationFormatter !== null
                ? $applicationFormatter($app->fresh(['leaveType', 'applicantAdmin.department']))
                : $app->fresh(['leaveType', 'applicantAdmin.department'])?->toArray(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  null|callable(string): array<int, string>  $candidatesResolver
     * @param  null|callable(LeaveApplication, array<string, mixed>): array<string, mixed>  $ermsApplicationFormatter
     * @param  null|callable(iterable<int, LeaveApplication>): array<string, mixed>  $actorDirectoryBuilder
     */
    public function executeErmsWithdrawRequest(
        array $validated,
        object $employee,
        string $controlNo,
        ?callable $candidatesResolver = null,
        ?callable $ermsApplicationFormatter = null,
        ?callable $actorDirectoryBuilder = null
    ): JsonResponse {
        $applicationId = (int) $validated['leave_application_id'];
        $candidates = $candidatesResolver !== null
            ? $candidatesResolver($controlNo)
            : $this->eligibilityService()->controlNoCandidates($controlNo);

        $app = LeaveApplication::query()
            ->with('leaveType')
            ->where('id', $applicationId)
            ->where(function ($query) use ($candidates): void {
                $query->whereIn('employee_control_no', $candidates)
                    ->orWhereHas('applicantAdmin', function ($adminQuery) use ($candidates): void {
                        $adminQuery->whereIn('employee_control_no', $candidates);
                    });
            })
            ->first();

        if (! $app) {
            return response()->json(['message' => 'Leave application not found for this employee.'], 404);
        }

        $policyResolver = fn (...$args) => $this->eligibilityService()->applyRegularLeavePolicy(...$args);
        if (! $this->hasPendingApprovedUpdateRequest($app, fn ($a) => $this->resolvePendingUpdateMeta($a, $policyResolver))) {
            return response()->json([
                'message' => 'There is no pending update or cancellation request for this application to withdraw.',
            ], 422);
        }

        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app, $policyResolver);
        $pendingUpdateRequest = $pendingUpdateMeta['request_record'] ?? null;
        $pendingActionType = $this->resolveUpdateRequestActionTypeFromPayload($pendingUpdateMeta['payload'] ?? null);

        $previousStatus = strtoupper(trim((string) ($pendingUpdateMeta['previous_status'] ?? '')));
        if (! in_array($previousStatus, [LeaveApplication::STATUS_APPROVED, LeaveApplication::STATUS_PENDING_HR, LeaveApplication::STATUS_PENDING_ADMIN], true)) {
            $previousStatus = LeaveApplication::STATUS_APPROVED;
        }

        $this->withdrawPendingApprovedUpdateRequest(
            $app,
            $pendingUpdateRequest,
            $previousStatus
        );

        $requestLabel = $pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_CANCEL
            ? 'cancellation request'
            : ($pendingActionType === LeaveApplicationUpdateRequest::ACTION_TYPE_RECALL
                ? 'recall request'
                : 'update request');

        $application = $app->fresh(['leaveType', 'applicantAdmin.department', 'logs']);
        $actorDirectory = $actorDirectoryBuilder !== null
            ? $actorDirectoryBuilder([$application])
            : $this->buildWorkflowActorDirectory([$application]);

        return response()->json([
            'message' => "Leave {$requestLabel} cancelled successfully. Application status restored to approved.",
            'application' => $ermsApplicationFormatter !== null
                ? $ermsApplicationFormatter($application, $actorDirectory)
                : $application?->toArray(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  null|callable(string): array<int, string>  $candidatesResolver
     * @param  null|callable(string): ?object  $findEmployeeByControlNo
     * @param  null|callable(LeaveApplication): array<string, mixed>  $applicationFormatter
     */
    public function executeErmsRequestUpdate(
        Request $request,
        array $validated,
        object $employee,
        string $controlNo,
        ?callable $candidatesResolver = null,
        ?callable $findEmployeeByControlNo = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $applicationId = (int) $validated['leave_application_id'];
        $candidates = $candidatesResolver !== null
            ? $candidatesResolver($controlNo)
            : $this->eligibilityService()->controlNoCandidates($controlNo, $findEmployeeByControlNo);

        $app = LeaveApplication::query()
            ->with('leaveType')
            ->where('id', $applicationId)
            ->where(function ($query) use ($candidates): void {
                $query->whereIn('employee_control_no', $candidates)
                    ->orWhereHas('applicantAdmin', function ($adminQuery) use ($candidates): void {
                        $adminQuery->whereIn('employee_control_no', $candidates);
                    });
            })
            ->first();

        if (! $app) {
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

        if (! $isApprovedApplication && ! in_array($app->status, $editableStatuses, true)) {
            return response()->json([
                'message' => "Cannot request edit: application status is '{$this->ermsStatusLabel($app->status)}'. Only pending or approved applications can request edits.",
            ], 422);
        }

        if ($isApprovedApplication) {
            $leadTimeValidation = $this->eligibilityService()->validateApprovedLeaveRequestLeadTime($app, 'update');
            if ($leadTimeValidation instanceof JsonResponse) {
                return $leadTimeValidation;
            }
        }

        $requestedIsMonetization = array_key_exists('is_monetization', $validated)
            ? (bool) $validated['is_monetization']
            : (bool) $app->is_monetization;

        $requestedDetailsOfLeave = $this->resolveRequestedDetailsOfLeaveForUpdate($request, $validated, $app);
        $resolvedPayMode = $this->resolveRequestedPayMode(
            $request,
            $validated,
            $requestedIsMonetization,
            $app->pay_mode ?? LeaveApplication::PAY_MODE_WITH_PAY
        );
        $requestedAllowSlVlCrossDeduction = $this->resolveRequestSlVlCrossDeductionFlag(
            $request,
            $validated,
            (bool) ($app->allow_sl_vl_cross_deduction ?? false)
        );
        $attachmentState = $this->resolveAttachmentStateFromRequest(
            $request,
            $validated,
            (bool) ($app->attachment_submitted ?? false),
            $this->trimNullableString($app->attachment_reference ?? null)
        );

        $requestedUpdatePayload = $this->buildRequestedLeaveUpdatePayload(
            $app,
            $validated,
            $request->input(),
            $requestedDetailsOfLeave,
            $resolvedPayMode,
            $requestedAllowSlVlCrossDeduction,
            $attachmentState,
            $request->input('date_filed') ?? $request->input('dateOfFiling') ?? $app->created_at ?? now()
        );
        if ($requestedUpdatePayload instanceof JsonResponse) {
            return $requestedUpdatePayload;
        }
        $requestedUpdatePayload = $this->withRequestedLeaveActionType(
            $requestedUpdatePayload,
            LeaveApplicationUpdateRequest::ACTION_TYPE_UPDATE
        );

        $policyResolver = fn (...$args) => $this->eligibilityService()->applyRegularLeavePolicy(...$args);
        $hasDataChanges = $this->hasRequestedLeaveUpdateChanges($app, $requestedUpdatePayload, $policyResolver);

        if ($isApprovedApplication && ! $hasDataChanges) {
            return response()->json([
                'message' => 'No editable field changes were detected for this approved application.',
            ], 422);
        }

        if ($isApprovedApplication && ! (bool) ($requestedUpdatePayload['is_monetization'] ?? false)) {
            $duplicateDateValidation = $this->eligibilityService()->validateNoDuplicateLeaveDates(
                (string) $employee->control_no,
                (string) ($requestedUpdatePayload['start_date'] ?? ''),
                (string) ($requestedUpdatePayload['end_date'] ?? ''),
                is_array($requestedUpdatePayload['selected_dates'] ?? null)
                    ? $requestedUpdatePayload['selected_dates']
                    : null,
                $requestedUpdatePayload['total_days'] ?? null,
                (int) $app->id,
                is_array($requestedUpdatePayload['selected_date_coverage'] ?? null)
                    ? $requestedUpdatePayload['selected_date_coverage']
                    : null,
                is_array($requestedUpdatePayload['selected_date_half_day_portion'] ?? null)
                    ? $requestedUpdatePayload['selected_date_half_day_portion']
                    : null,
                $candidatesResolver
            );
            if ($duplicateDateValidation instanceof JsonResponse) {
                return $duplicateDateValidation;
            }
        }

        $targetLeaveTypeId = $this->creditService->resolveCanonicalLeaveTypeId((int) ($requestedUpdatePayload['leave_type_id'] ?? 0))
            ?? (int) ($requestedUpdatePayload['leave_type_id'] ?? 0);
        $targetLeaveType = LeaveType::find($targetLeaveTypeId);
        if (! $targetLeaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
            ], 422);
        }
        $requestedUpdatePayload['leave_type_id'] = $targetLeaveTypeId;

        $currentLeaveTypeId = $this->creditService->resolveCanonicalLeaveTypeId((int) ($app->leave_type_id ?? 0))
            ?? (int) ($app->leave_type_id ?? 0);
        $skipEventBasedReuseRule = $currentLeaveTypeId > 0 && $currentLeaveTypeId === $targetLeaveTypeId;
        $leaveTypeRestriction = $this->eligibilityService()->assertEmployeeCanApplyForLeaveType(
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

        $this->submitEmployeeLeaveUpdateRequest(
            $app,
            $requestedUpdatePayload,
            $remarksLine,
            $requestReason,
            $performedById,
            $this->resolveLeaveApplicationUpdateEmployeeControlNo($app, $findEmployeeByControlNo),
            $this->resolveLeaveApplicationUpdateEmployeeName($app, $findEmployeeByControlNo),
            $isApprovedApplication
        );

        $this->notifyPartiesOfEmployeeUpdateRequest(
            $app,
            $employee,
            $isApprovedApplication,
            $requestReason
        );

        return response()->json([
            'message' => $isApprovedApplication
                ? 'Leave update request submitted and forwarded to department admin for approval.'
                : 'Leave edit request submitted successfully.',
            'application' => $applicationFormatter !== null
                ? $applicationFormatter($app->fresh(['leaveType', 'applicantAdmin.department']))
                : $app->fresh(['leaveType', 'applicantAdmin.department'])?->toArray(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  null|callable(string): ?object  $findEmployeeByControlNo
     * @param  null|callable(LeaveApplication): array<string, mixed>  $applicationFormatter
     */
    public function executeHrIndex(
        HRAccount $hr,
        array $validated,
        ?callable $findEmployeeByControlNo = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $perPage = (int) ($validated['per_page'] ?? 10);
        $requestedPage = (int) ($validated['page'] ?? 1);
        $normalizedSearch = $this->normalizeHrApplicationSearchValue($validated['search'] ?? null);
        $searchTokens = $normalizedSearch === ''
            ? []
            : array_values(array_filter(explode(' ', $normalizedSearch)));
        $employmentType = trim((string) ($validated['employment_type'] ?? ''));

        $accessControl = app(\App\Services\HrAccessControlService::class);
        $hasApplicationsModule = $accessControl->hasModuleAccess($hr, \App\Services\HrAccessControlService::MODULE_APPLICATIONS);
        $hasReceivingModule = $accessControl->hasModuleAccess($hr, \App\Services\HrAccessControlService::MODULE_RECEIVING);
        $hasReleasingModule = $accessControl->hasModuleAccess($hr, \App\Services\HrAccessControlService::MODULE_RELEASING);

        if ($hasReceivingModule && ! $hasApplicationsModule && ! $hasReleasingModule) {
            $pendingReceiveOnly = true;
            $pendingReleaseOnly = false;
        } elseif ($hasReleasingModule && ! $hasApplicationsModule && ! $hasReceivingModule) {
            $pendingReceiveOnly = false;
            $pendingReleaseOnly = true;
        } else {
            $pendingReceiveOnly = filter_var($validated['pending_receive'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $pendingReleaseOnly = filter_var($validated['pending_release'] ?? false, FILTER_VALIDATE_BOOLEAN);
        }

        $query = LeaveApplication::query();

        if ($pendingReceiveOnly) {
            $query->where('status', LeaveApplication::STATUS_PENDING_HR);
        } elseif ($pendingReleaseOnly) {
            $query->whereIn('status', [LeaveApplication::STATUS_APPROVED, LeaveApplication::STATUS_PENDING_HR]);
        } else {
            $query->whereIn('status', [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
                LeaveApplication::STATUS_APPROVED,
                LeaveApplication::STATUS_REJECTED,
                LeaveApplication::STATUS_RECALLED,
                LeaveApplication::STATUS_CANCELLED,
            ]);
        }

        $logsTable = (new LeaveApplicationLog)->getTable();
        $updatesTable = (new LeaveApplicationUpdateRequest)->getTable();
        $appsTable = (new LeaveApplication)->getTable();

        $cycleStatuses = [LeaveApplicationUpdateRequest::STATUS_PENDING, LeaveApplicationUpdateRequest::STATUS_APPROVED];
        $cycleSubquerySql = "lal.created_at > COALESCE((SELECT MAX(requested_at) FROM {$updatesTable} req WHERE req.leave_application_id = {$appsTable}.id AND req.status IN (?, ?) AND req.requested_payload NOT LIKE '%\"request_kind\":\"HR_APPLICATION_EDIT\"%'), '1970-01-01')";

        if ($pendingReleaseOnly) {
            $query->whereExists(function ($sub) use ($logsTable, $appsTable, $cycleSubquerySql, $cycleStatuses) {
                $sub->select(DB::raw(1))
                    ->from("{$logsTable} as lal")
                    ->whereColumn('lal.leave_application_id', "{$appsTable}.id")
                    ->where('lal.action', LeaveApplicationLog::ACTION_HR_RECEIVED)
                    ->whereRaw($cycleSubquerySql, $cycleStatuses);
            })
                ->whereExists(function ($sub) use ($logsTable, $appsTable) {
                    $sub->select(DB::raw(1))
                        ->from("{$logsTable} as lal")
                        ->whereColumn('lal.leave_application_id', "{$appsTable}.id")
                        ->where('lal.action', LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED);
                })
                ->whereNotExists(function ($sub) use ($logsTable, $appsTable, $cycleSubquerySql, $cycleStatuses) {
                    $sub->select(DB::raw(1))
                        ->from("{$logsTable} as lal")
                        ->whereColumn('lal.leave_application_id', "{$appsTable}.id")
                        ->where('lal.action', LeaveApplicationLog::ACTION_HR_RELEASED)
                        ->whereRaw($cycleSubquerySql, $cycleStatuses);
                });
        } elseif ($pendingReceiveOnly) {
            $query->whereNotExists(function ($sub) use ($logsTable, $appsTable, $cycleSubquerySql, $cycleStatuses) {
                $sub->select(DB::raw(1))
                    ->from("{$logsTable} as lal")
                    ->whereColumn('lal.leave_application_id', "{$appsTable}.id")
                    ->where('lal.action', LeaveApplicationLog::ACTION_HR_RECEIVED)
                    ->whereRaw($cycleSubquerySql, $cycleStatuses);
            });
        } else {
            $query->where(function ($q) use ($logsTable, $appsTable, $cycleSubquerySql, $cycleStatuses) {
                $q->where('status', '!=', LeaveApplication::STATUS_PENDING_HR)
                    ->orWhereExists(function ($sub) use ($logsTable, $appsTable, $cycleSubquerySql, $cycleStatuses) {
                        $sub->select(DB::raw(1))
                            ->from("{$logsTable} as lal")
                            ->whereColumn('lal.leave_application_id', "{$appsTable}.id")
                            ->where('lal.action', LeaveApplicationLog::ACTION_HR_RECEIVED)
                            ->whereRaw($cycleSubquerySql, $cycleStatuses);
                    });
            });

            $query->whereNot(function ($q) use ($logsTable, $appsTable, $cycleSubquerySql, $cycleStatuses) {
                $q->where('status', LeaveApplication::STATUS_APPROVED)
                    ->whereExists(function ($sub) use ($logsTable, $appsTable, $cycleSubquerySql, $cycleStatuses) {
                        $sub->select(DB::raw(1))
                            ->from("{$logsTable} as lal")
                            ->whereColumn('lal.leave_application_id', "{$appsTable}.id")
                            ->where('lal.action', LeaveApplicationLog::ACTION_HR_RECEIVED)
                            ->whereRaw($cycleSubquerySql, $cycleStatuses);
                    })
                    ->whereExists(function ($sub) use ($logsTable, $appsTable) {
                        $sub->select(DB::raw(1))
                            ->from("{$logsTable} as lal")
                            ->whereColumn('lal.leave_application_id', "{$appsTable}.id")
                            ->where('lal.action', LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED);
                    })
                    ->whereNotExists(function ($sub) use ($logsTable, $appsTable, $cycleSubquerySql, $cycleStatuses) {
                        $sub->select(DB::raw(1))
                            ->from("{$logsTable} as lal")
                            ->whereColumn('lal.leave_application_id', "{$appsTable}.id")
                            ->where('lal.action', LeaveApplicationLog::ACTION_HR_RELEASED)
                            ->whereRaw($cycleSubquerySql, $cycleStatuses);
                    });
            });
        }

        $query->where(function ($q) use ($logsTable, $appsTable) {
            $q->where('status', '!=', LeaveApplication::STATUS_CANCELLED)
                ->where(function ($q2) {
                    $q2->whereNull('remarks')->orWhere('remarks', 'NOT LIKE', 'cancelled%');
                })
                ->whereNotExists(function ($sub) use ($logsTable, $appsTable) {
                    $sub->select(DB::raw(1))
                        ->from("{$logsTable} as lal")
                        ->whereColumn('lal.leave_application_id', "{$appsTable}.id")
                        ->where('lal.remarks', 'LIKE', 'cancelled%');
                });
        });

        $query->whereNot(function ($q) use ($logsTable, $appsTable) {
            $q->where('status', LeaveApplication::STATUS_REJECTED)
                ->where(function ($q2) {
                    $q2->whereNull('hr_id')->orWhere('hr_id', '<=', 0);
                })
                ->whereNotExists(function ($sub) use ($logsTable, $appsTable) {
                    $sub->select(DB::raw(1))
                        ->from("{$logsTable} as lal")
                        ->whereColumn('lal.leave_application_id', "{$appsTable}.id")
                        ->where('lal.action', LeaveApplicationLog::ACTION_HR_REJECTED)
                        ->whereRaw('UPPER(lal.performed_by_type) = ?', [LeaveApplicationLog::PERFORMER_HR]);
                });
        });

        $query->whereNot(function ($q) use ($updatesTable, $appsTable) {
            $q->where('status', LeaveApplication::STATUS_PENDING_ADMIN)
                ->whereNotExists(function ($sub) use ($updatesTable, $appsTable) {
                    $sub->select(DB::raw(1))
                        ->from("{$updatesTable} as req")
                        ->whereColumn('req.leave_application_id', "{$appsTable}.id")
                        ->where('req.status', LeaveApplicationUpdateRequest::STATUS_PENDING);
                });
        });

        if ($searchTokens !== []) {
            $query->where(function ($q) use ($searchTokens) {
                foreach ($searchTokens as $token) {
                    $q->where(function ($sub) use ($token) {
                        $likeToken = "%{$token}%";
                        $sub->where('id', 'like', $likeToken)
                            ->orWhere('employee_control_no', 'like', $likeToken)
                            ->orWhere('employee_name', 'like', $likeToken)
                            ->orWhere('status', 'like', $likeToken)
                            ->orWhere('total_days', 'like', $likeToken)
                            ->orWhereHas('applicantAdmin', function ($qAdmin) use ($likeToken) {
                                $qAdmin->where('full_name', 'like', $likeToken)
                                    ->orWhereHas('department', function ($qDept) use ($likeToken) {
                                        $qDept->where('name', 'like', $likeToken);
                                    });
                            })
                            ->orWhereHas('leaveType', function ($qType) use ($likeToken) {
                                $qType->where('name', 'like', $likeToken);
                            });
                    });
                }
            });
        }

        $query->orderByRaw("
            CASE
                WHEN status IN ('PENDING_HR', 'PENDING_ADMIN') OR status LIKE '%PENDING%' THEN 1
                WHEN status = 'APPROVED' THEN 2
                WHEN status = 'REJECTED' THEN 3
                WHEN status = 'RECALLED' THEN 4
                ELSE 5
            END ASC
        ")->orderBy('created_at', 'ASC')->orderBy('id', 'ASC');

        DB::unprepared('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
        try {
            $paginator = $query->paginate($perPage, ['*'], 'page', $requestedPage);
            $pageApplications = $paginator->getCollection();
        } finally {
            DB::unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        $pageApplications->load(['leaveType', 'applicantAdmin.department', 'updateRequests', 'logs']);

        HrisEmployee::directoryByControlNos(
            $pageApplications
                ->pluck('employee_control_no')
                ->filter()
                ->unique()
                ->values()
                ->all()
        );

        if ($employmentType !== '') {
            $pageApplications = $pageApplications->filter(function (LeaveApplication $application) use ($employmentType, $findEmployeeByControlNo) {
                $controlNo = trim((string) ($application->employee_control_no ?? ''));
                $employee = $controlNo === ''
                    ? null
                    : ($findEmployeeByControlNo !== null
                        ? $findEmployeeByControlNo($controlNo)
                        : HrisEmployee::findByControlNo($controlNo));

                return LeaveType::normalizeEmploymentStatusKey($employee?->status) === $employmentType;
            })->values();
        }

        $formattedApplications = $pageApplications
            ->map(fn (LeaveApplication $app): array => $applicationFormatter !== null ? $applicationFormatter($app) : $app->toArray())
            ->values();

        return response()->json([
            'applications' => $formattedApplications,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  null|callable(string): array<int, string>  $candidatesResolver
     * @param  null|callable(string): ?object  $findEmployeeByControlNo
     * @param  null|callable(LeaveApplication): array<string, mixed>  $applicationFormatter
     * @param  null|callable(LeaveApplicationUpdateRequest): array<string, mixed>  $editRequestFormatter
     * @param  null|callable(LeaveApplication): void  $lockSnapshotCallback
     * @param  null|callable(int): ?string  $adminControlNoResolver
     */
    public function executeHrSaveApplicationEdit(
        Request $request,
        HRAccount $hr,
        LeaveApplication $app,
        array $validated,
        ?callable $candidatesResolver = null,
        ?callable $findEmployeeByControlNo = null,
        ?callable $applicationFormatter = null,
        ?callable $editRequestFormatter = null,
        ?callable $lockSnapshotCallback = null,
        ?callable $adminControlNoResolver = null
    ): JsonResponse {
        if (! in_array($app->status, [LeaveApplication::STATUS_PENDING_HR, LeaveApplication::STATUS_APPROVED], true)) {
            return response()->json([
                'message' => 'Only Pending HR and Approved applications can be edited by HR.',
            ], 422);
        }

        if ((bool) $app->is_monetization) {
            return response()->json([
                'message' => 'Monetization applications are not supported by this edit form.',
            ], 422);
        }

        $normalizedPayload = $this->normalizeHrApplicationEditPayload(
            $request->input(),
            $app
        );
        if ($normalizedPayload instanceof JsonResponse) {
            return $normalizedPayload;
        }

        $reason = $this->trimNullableString($validated['reason'] ?? null);

        $existingPendingHrStaffEditRequest = LeaveApplicationUpdateRequest::query()
            ->where('leave_application_id', (int) $app->id)
            ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
            ->latest('id')
            ->get()
            ->first(fn (LeaveApplicationUpdateRequest $editRequest): bool => $this->isHrApplicationEditRequestRecord($editRequest));

        if ($existingPendingHrStaffEditRequest instanceof LeaveApplicationUpdateRequest) {
            return response()->json([
                'message' => 'This application already has a pending HR staff edit request. Please approve or reject it first.',
            ], 422);
        }

        $pendingEmployeeUpdateRequest = LeaveApplicationUpdateRequest::query()
            ->where('leave_application_id', (int) $app->id)
            ->where('status', LeaveApplicationUpdateRequest::STATUS_PENDING)
            ->latest('id')
            ->get()
            ->first(fn (LeaveApplicationUpdateRequest $editRequest): bool => ! $this->isHrApplicationEditRequestRecord($editRequest));

        if ($pendingEmployeeUpdateRequest instanceof LeaveApplicationUpdateRequest) {
            return response()->json([
                'message' => 'This application has a pending employee update request. Please review the update request first.',
            ], 422);
        }

        if ($this->isHrAccessControlOwner($hr)) {
            $applied = $this->applyHrApplicationEdit(
                $app,
                $hr,
                $normalizedPayload,
                $reason,
                null,
                $this->trimNullableString($validated['remarks'] ?? null),
                $candidatesResolver,
                $adminControlNoResolver,
                $findEmployeeByControlNo
            );

            if ($applied instanceof JsonResponse) {
                return $applied;
            }

            if ($lockSnapshotCallback !== null) {
                $lockSnapshotCallback($applied);
            }

            $freshApp = $applied->fresh(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests']);

            return response()->json([
                'message' => 'Application edit applied successfully.',
                'application' => $applicationFormatter !== null
                    ? $applicationFormatter($freshApp)
                    : $freshApp?->toArray(),
            ]);
        }

        $editRequest = $this->createHrStaffApplicationEditRequest(
            $app,
            $hr,
            $normalizedPayload,
            $reason,
            $this->resolveEmployeeDisplayName($app, $findEmployeeByControlNo)
        );

        $editRequest->load(['leaveApplication.leaveType', 'reviewedByHr']);

        return response()->json([
            'message' => 'Application edit request submitted for HR Admin approval.',
            'edit_request' => $editRequestFormatter !== null
                ? $editRequestFormatter($editRequest)
                : $this->formatHrApplicationEditRequest($editRequest, $applicationFormatter),
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  null|callable(string): ?object  $findEmployeeByControlNo
     * @param  null|callable(LeaveApplication): array<string, mixed>  $applicationFormatter
     */
    public function executeAdminRequestRecall(
        DepartmentAdmin $admin,
        LeaveApplication $app,
        array $validated,
        ?callable $findEmployeeByControlNo = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $admin->loadMissing('department');

        if (! $this->adminCanManageApplication(
            $admin,
            $app,
            fn (string $controlNo): ?int => $this->eligibilityService()->resolveLatestAssignedDepartmentIdForControlNo($controlNo),
            function (LeaveApplication $application) use ($findEmployeeByControlNo): ?object {
                $controlNo = trim((string) ($application->employee_control_no ?? ''));
                if ($controlNo === '') {
                    return null;
                }

                return $findEmployeeByControlNo !== null
                    ? $findEmployeeByControlNo($controlNo)
                    : HrisEmployee::findByControlNo($controlNo);
            }
        )) {
            return response()->json(['message' => 'You can only manage applications from your department.'], 403);
        }

        if ((bool) $app->is_monetization) {
            return response()->json([
                'message' => 'Monetization requests cannot be recalled.',
            ], 422);
        }

        if ($app->status !== LeaveApplication::STATUS_APPROVED) {
            return response()->json([
                'message' => "Cannot request recall: application status is '{$app->status}', expected 'APPROVED'.",
            ], 422);
        }

        $policyResolver = fn (...$args) => $this->eligibilityService()->applyRegularLeavePolicy(...$args);
        if ($this->hasPendingApprovedUpdateRequest($app, fn ($a) => $this->resolvePendingUpdateMeta($a, $policyResolver))) {
            return response()->json([
                'message' => 'There is already a pending approved leave request action for this application.',
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
        if (! $this->isRecallableLeaveType($app, $leaveType, $forcedLeaveTypeId, $vacationLeaveTypeId)) {
            return response()->json([
                'message' => 'Only Vacation Leave and Mandatory / Forced Leave applications can be recalled.',
            ], 422);
        }

        $selectedRecallDateKeys = $this->resolveValidatedRecallSelectedDateKeys(
            $app,
            is_array($validated['recall_selected_dates'] ?? null)
                ? $validated['recall_selected_dates']
                : [],
            fn ($a) => $this->resolveLatestUpdateMeta($a, $policyResolver)
        );
        if ($selectedRecallDateKeys === null || $selectedRecallDateKeys === []) {
            return response()->json([
                'message' => 'Selected recall dates must match the application leave dates.',
            ], 422);
        }

        $reason = trim((string) ($validated['recall_reason'] ?? $validated['remarks'] ?? ''));
        $remarksLine = $reason !== ''
            ? "Recall request submitted by department admin. Reason: {$reason}"
            : 'Recall request submitted by department admin.';

        $requestedUpdatePayload = $this->buildRequestedLeaveRecallPayload(
            $app,
            $reason,
            $selectedRecallDateKeys,
            fn ($id) => $this->creditService->resolveCanonicalLeaveTypeId($id),
            fn ($a) => $this->calculationService->resolveApplicationDeductibleDays($a),
            fn ($mode, $isMonetization) => $this->calculationService->normalizePayMode($mode, $isMonetization),
            fn ($dates) => $this->normalizeRecallDateKeys($dates, fn ($d) => $this->calculationService->normalizeDateKey($d))
        );

        $requestedByControlNo = trim((string) (
            $admin->employee_control_no
            ?? $admin->control_no
            ?? $admin->employee_id
            ?? $app->employee_control_no
            ?? ''
        ));

        $this->submitApprovedLeaveRecallRequest(
            $app,
            $admin,
            $requestedUpdatePayload,
            $remarksLine,
            $reason,
            $requestedByControlNo,
            $this->resolveLeaveApplicationUpdateEmployeeControlNo($app, $findEmployeeByControlNo),
            $this->resolveLeaveApplicationUpdateEmployeeName($app, $findEmployeeByControlNo)
        );

        $employeeName = $this->resolveEmployeeDisplayName($app, $findEmployeeByControlNo);
        $this->notifyHrAccountsOfAdminRecallRequest(
            $app,
            $employeeName,
            $reason
        );

        return response()->json([
            'message' => 'Recall request submitted and forwarded to HR for approval.',
            'application' => $applicationFormatter !== null
                ? $applicationFormatter($app->fresh(['leaveType', 'applicantAdmin']))
                : $app->fresh(['leaveType', 'applicantAdmin'])?->toArray(),
        ]);
    }

    public function executeAdminIndex(
        DepartmentAdmin $admin,
        ?callable $departmentEmployeeControlNosResolver = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $deptName = $admin->department?->name;
        $departmentEmployeeControlNos = $departmentEmployeeControlNosResolver !== null
            ? $departmentEmployeeControlNosResolver($deptName, $admin->department_id !== null ? (int) $admin->department_id : null)
            : $this->eligibilityService()->findDepartmentEmployeeControlNos(
                $deptName,
                null,
                $admin->department_id !== null ? (int) $admin->department_id : null
            );
        $departmentAdminIds = $admin->department_id !== null
            ? DepartmentAdmin::query()
                ->where('department_id', $admin->department_id)
                ->pluck('id')
                ->map(fn ($value) => (int) $value)
                ->values()
                ->all()
            : [];

        $applications = LeaveApplication::with(['leaveType', 'applicantAdmin.department', 'updateRequests', 'logs'])
            ->whereIn('status', [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
                LeaveApplication::STATUS_APPROVED,
                LeaveApplication::STATUS_REJECTED,
                LeaveApplication::STATUS_RECALLED,
                LeaveApplication::STATUS_CANCELLED,
            ])
            ->where(function ($query) use ($departmentEmployeeControlNos, $admin, $departmentAdminIds): void {
                $hasVisibilityConstraint = false;

                if ($departmentEmployeeControlNos !== []) {
                    $this->whereInEmployeeControlNos($query, $departmentEmployeeControlNos);
                    $hasVisibilityConstraint = true;
                }

                if ($admin->department_id !== null) {
                    $selfDepartmentVisibility = function ($selfScopedQuery) use ($admin): void {
                        $selfScopedQuery
                            ->where(function ($employeeControlNoQuery): void {
                                $employeeControlNoQuery
                                    ->whereNull('employee_control_no')
                                    ->orWhere('employee_control_no', '');
                            })
                            ->whereHas('applicantAdmin', fn ($nestedQuery) => $nestedQuery->where('department_id', $admin->department_id));
                    };

                    if ($hasVisibilityConstraint) {
                        $query->orWhere($selfDepartmentVisibility);
                    } else {
                        $query->where($selfDepartmentVisibility);
                    }
                    $hasVisibilityConstraint = true;
                }

                if ($departmentAdminIds !== []) {
                    $selfAdminVisibility = function ($selfScopedQuery) use ($departmentAdminIds): void {
                        $selfScopedQuery
                            ->where(function ($employeeControlNoQuery): void {
                                $employeeControlNoQuery
                                    ->whereNull('employee_control_no')
                                    ->orWhere('employee_control_no', '');
                            })
                            ->whereIn('admin_id', $departmentAdminIds);
                    };

                    if ($hasVisibilityConstraint) {
                        $query->orWhere($selfAdminVisibility);
                    } else {
                        $query->where($selfAdminVisibility);
                    }
                    $hasVisibilityConstraint = true;
                }

                if (! $hasVisibilityConstraint) {
                    $query->whereRaw('1 = 0');
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        $applications = $this->sortLeaveApplicationsForHrQueue($applications);

        return response()->json([
            'applications' => $applications->map(fn ($app) => $applicationFormatter !== null ? $applicationFormatter($app) : $this->formatApplication($app)),
        ]);
    }

    public function executeAdminShow(
        DepartmentAdmin $admin,
        int $id,
        ?callable $adminCanManageCallback = null,
        ?callable $actorDirectoryBuilder = null,
        ?callable $ermsApplicationFormatter = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $admin->loadMissing('department');
        $application = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);

        if (! $application) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $canManage = $adminCanManageCallback !== null
            ? $adminCanManageCallback($admin, $application)
            : $this->adminCanManageApplication($admin, $application);

        if (! $canManage) {
            return response()->json(['message' => 'You can only view applications from your department.'], 403);
        }

        $actorDirectory = $actorDirectoryBuilder !== null
            ? $actorDirectoryBuilder([$application])
            : $this->buildWorkflowActorDirectory([$application]);

        $workflowPayload = $ermsApplicationFormatter !== null
            ? $ermsApplicationFormatter($application, $actorDirectory)
            : $this->formatErmsApplication($application, $actorDirectory);
        $detailPayload = $applicationFormatter !== null
            ? $applicationFormatter($application)
            : $this->formatApplication($application);

        return response()->json([
            'application' => array_replace($workflowPayload, $detailPayload),
        ]);
    }

    public function executeAdminApprove(
        DepartmentAdmin $admin,
        int $id,
        ?string $remarks = null,
        ?callable $adminCanManageCallback = null,
        ?callable $findEmployeeByControlNo = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $admin->loadMissing('department');
        $app = LeaveApplication::find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $canManage = $adminCanManageCallback !== null
            ? $adminCanManageCallback($admin, $app)
            : $this->adminCanManageApplication($admin, $app);

        if (! $canManage) {
            return response()->json(['message' => 'You can only manage applications from your department.'], 403);
        }

        if ($app->status !== LeaveApplication::STATUS_PENDING_ADMIN) {
            return response()->json(['message' => "Cannot approve: application status is '{$app->status}', expected 'PENDING_ADMIN'."], 422);
        }

        $isPendingApprovedUpdateRequest = $this->hasPendingApprovedUpdateRequest($app);
        $pendingApprovedActionType = $this->resolvePendingApprovedUpdateActionType($app);

        $this->adminApproveApplication(
            $app,
            $admin,
            $remarks,
            $isPendingApprovedUpdateRequest,
            $pendingApprovedActionType
        );

        $employeeName = $this->resolveEmployeeDisplayName($app, $findEmployeeByControlNo);
        $this->notifyHrAccountsOfAdminApproval(
            $app,
            $employeeName,
            $isPendingApprovedUpdateRequest,
            $pendingApprovedActionType
        );

        return response()->json([
            'message' => $this->resolveAdminApprovalResponseMessage(
                $isPendingApprovedUpdateRequest,
                $pendingApprovedActionType
            ),
            'application' => $applicationFormatter !== null
                ? $applicationFormatter($app->fresh('leaveType'))
                : $app->fresh('leaveType')?->toArray(),
        ]);
    }

    public function executeAdminReject(
        DepartmentAdmin $admin,
        int $id,
        ?string $remarks = null,
        ?callable $adminCanManageCallback = null,
        ?callable $rejectPendingUpdateRequestCallback = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $admin->loadMissing('department');
        $app = LeaveApplication::find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $canManage = $adminCanManageCallback !== null
            ? $adminCanManageCallback($admin, $app)
            : $this->adminCanManageApplication($admin, $app);

        if (! $canManage) {
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
            if ($rejectPendingUpdateRequestCallback !== null) {
                return $rejectPendingUpdateRequestCallback($app, $admin, $remarks);
            }

            $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);

            return $this->executeAdminRejectPendingUpdateRequest(
                $app,
                $admin,
                $remarks,
                $pendingUpdateMeta,
                $applicationFormatter
            );
        }

        $rejectionResult = $this->adminRejectApplication($app, $admin, $remarks);
        $status = $rejectionResult['status'];

        $app->load('leaveType');

        return response()->json([
            'message' => $status === LeaveApplication::STATUS_CANCELLED
                ? 'Application cancelled by admin.'
                : 'Application rejected by admin.',
            'application' => $applicationFormatter !== null
                ? $applicationFormatter($app->fresh('leaveType'))
                : $app->fresh('leaveType')?->toArray(),
        ]);
    }

    public function executeAdminEmployees(
        DepartmentAdmin $admin,
        ?callable $balanceLookupBuilder = null
    ): JsonResponse {
        $admin->loadMissing('department');
        $deptName = $admin->department?->name;
        $hrisEmployees = HrisEmployee::allByOffice($deptName, null);
        $leaveBalanceLookup = $balanceLookupBuilder !== null
            ? $balanceLookupBuilder($hrisEmployees)
            : $this->eligibilityService()->buildLeaveBalanceLookupForEmployees($hrisEmployees);

        $employees = $hrisEmployees->map(function ($employee) use ($leaveBalanceLookup): array {
            $controlNo = trim((string) ($employee->control_no ?? ''));
            $fullName = trim(implode(' ', array_filter([
                trim((string) ($employee->firstname ?? '')),
                trim((string) ($employee->middlename ?? '')),
                trim((string) ($employee->surname ?? '')),
            ])));
            $normalizedControlNo = $this->eligibilityService()->normalizeControlNo($controlNo);
            $leaveBalances = $leaveBalanceLookup[$normalizedControlNo] ?? [];

            return [
                'control_no' => $controlNo,
                'full_name' => $fullName,
                'firstname' => $employee->firstname ?? null,
                'surname' => $employee->surname ?? null,
                'status' => $this->eligibilityService()->formatEmploymentStatusLabel($employee->status ?? null),
                'raw_status' => $this->trimNullableString($employee->status ?? null),
                'employment_status_key' => $this->eligibilityService()->resolveEmploymentStatusKey($employee->status ?? null),
                'activity_status' => strtoupper(trim((string) ($employee->activity_status ?? 'INACTIVE'))),
                'is_active' => (bool) ($employee->is_active ?? false),
                'designation' => $employee->designation ?? null,
                'office' => $employee->office ?? null,
                'salary' => $employee->rate_mon !== null ? (float) $employee->rate_mon : null,
                'rate_mon' => $employee->rate_mon !== null ? (float) $employee->rate_mon : null,
                'leave_balances' => collect($leaveBalances)->map(fn ($leaveBalance) => [
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

    public function executeExportReceivedApplications(
        array $validated,
        ?callable $applicationFormatter = null
    ): JsonResponse {
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
            'applications' => $applications->map(fn (LeaveApplication $app) => $applicationFormatter !== null ? $applicationFormatter($app) : $app->toArray()),
        ]);
    }

    public function executeExportReleasedApplications(
        array $validated,
        ?callable $applicationFormatter = null
    ): JsonResponse {
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
            'applications' => $applications->map(fn (LeaveApplication $app) => $applicationFormatter !== null ? $applicationFormatter($app) : $app->toArray()),
        ]);
    }

    public function executeHrCancelledApplications(
        array $validated,
        ?callable $actorDirectoryBuilder = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $tab = strtoupper(trim((string) ($validated['tab'] ?? 'ALL')));
        $perPage = (int) ($validated['per_page'] ?? 10);
        $requestedPage = (int) ($validated['page'] ?? 1);
        $searchTokens = $this->tokenizeHrApplicationSearch($validated['search'] ?? null);
        $department = trim((string) ($validated['department'] ?? ''));
        $fromDate = $validated['from_date'] ?? null;
        $toDate = $validated['to_date'] ?? null;

        $approvedCancelCondition = $this->getApprovedCancellationCondition();
        $baseQuery = $this->buildCancelledApplicationsBaseQuery(
            $searchTokens,
            $department,
            $fromDate,
            $toDate
        );

        $totalAll = (clone $baseQuery)->count();
        $totalApproved = (clone $baseQuery)->where($approvedCancelCondition)->count();
        $totalPendingCancelled = (clone $baseQuery)->whereNot($approvedCancelCondition)->count();

        $listQuery = clone $baseQuery;
        if ($tab === 'APPROVED_CANCELLATION') {
            $listQuery->where($approvedCancelCondition);
        } elseif ($tab === 'EMPLOYEE_CANCELLED') {
            $listQuery->whereNot($approvedCancelCondition);
        }

        $listQuery->orderByDesc('updated_at')->orderByDesc('id');

        DB::unprepared('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
        try {
            $paginator = $listQuery->paginate($perPage, ['*'], 'page', $requestedPage);
            $pageApplications = $paginator->getCollection();
        } finally {
            DB::unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        $pageApplications->load([
            'leaveType',
            'applicantAdmin.department',
            'updateRequests',
            'logs',
        ]);

        $actorDirectory = $actorDirectoryBuilder !== null
            ? $actorDirectoryBuilder($pageApplications)
            : $this->buildWorkflowActorDirectory($pageApplications);

        $formatted = $pageApplications->map(function (LeaveApplication $app) use ($actorDirectory, $applicationFormatter): array {
            $formattedApp = $applicationFormatter !== null
                ? $applicationFormatter($app, $actorDirectory)
                : $this->formatApplication($app, $actorDirectory);
            $cancellationType = $this->resolveApplicationCancellationType($app);
            $cancellationDetails = $this->resolveApplicationCancellationDetails($app, $cancellationType);

            return array_merge($formattedApp, [
                'cancellation_type' => $cancellationType,
                'cancellation_type_label' => $cancellationType === 'APPROVED_CANCELLATION'
                    ? 'Approved Cancellation'
                    : 'Cancelled While Pending',
                'cancellation_date' => $cancellationDetails['date'],
                'cancellation_actor' => $cancellationDetails['actor'],
                'cancellation_reason' => $cancellationDetails['reason'],
            ]);
        });

        return response()->json([
            'applications' => $formatted->all(),
            'counts' => [
                'all' => $totalAll,
                'approved_cancellations' => $totalApproved,
                'employee_cancelled' => $totalPendingCancelled,
            ],
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function executeExportCancelledApplications(
        array $validated,
        ?callable $actorDirectoryBuilder = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        $tab = strtoupper(trim((string) ($validated['tab'] ?? 'ALL')));
        $searchTokens = $this->tokenizeHrApplicationSearch($validated['search'] ?? null);
        $department = trim((string) ($validated['department'] ?? ''));
        $fromDate = $validated['from_date'] ?? null;
        $toDate = $validated['to_date'] ?? null;

        $approvedCancelCondition = $this->getApprovedCancellationCondition();
        $query = $this->buildCancelledApplicationsBaseQuery(
            $searchTokens,
            $department,
            $fromDate,
            $toDate
        );

        if ($tab === 'APPROVED_CANCELLATION') {
            $query->where($approvedCancelCondition);
        } elseif ($tab === 'EMPLOYEE_CANCELLED') {
            $query->whereNot($approvedCancelCondition);
        }

        DB::unprepared('SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED');
        try {
            $applications = $query
                ->with([
                    'leaveType',
                    'applicantAdmin.department',
                    'updateRequests',
                    'logs',
                ])
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->limit(1000)
                ->get();
        } finally {
            DB::unprepared('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }

        $actorDirectory = $actorDirectoryBuilder !== null
            ? $actorDirectoryBuilder($applications)
            : $this->buildWorkflowActorDirectory($applications);

        $formatted = $applications->map(function (LeaveApplication $app) use ($actorDirectory, $applicationFormatter): array {
            $formattedApp = $applicationFormatter !== null
                ? $applicationFormatter($app, $actorDirectory)
                : $this->formatApplication($app, $actorDirectory);
            $cancellationType = $this->resolveApplicationCancellationType($app);
            $cancellationDetails = $this->resolveApplicationCancellationDetails($app, $cancellationType);

            return array_merge($formattedApp, [
                'cancellation_type' => $cancellationType,
                'cancellation_type_label' => $cancellationType === 'APPROVED_CANCELLATION'
                    ? 'Approved Cancellation'
                    : 'Cancelled While Pending',
                'cancellation_date' => $cancellationDetails['date'],
                'cancellation_actor' => $cancellationDetails['actor'],
                'cancellation_reason' => $cancellationDetails['reason'],
            ]);
        });

        return response()->json([
            'applications' => $formatted->all(),
        ]);
    }

    public function executeHrShow(
        int $id,
        ?callable $actorDirectoryBuilder = null,
        ?callable $ermsApplicationFormatter = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
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

        $actorDirectory = $actorDirectoryBuilder !== null
            ? $actorDirectoryBuilder([$application])
            : $this->buildWorkflowActorDirectory([$application]);

        $workflowPayload = $ermsApplicationFormatter !== null
            ? $ermsApplicationFormatter($application, $actorDirectory)
            : [];
        $detailPayload = $applicationFormatter !== null
            ? $applicationFormatter($application, $actorDirectory)
            : $application->toArray();

        return response()->json([
            'application' => array_replace($workflowPayload, $detailPayload),
        ]);
    }

    public function executeHrApplicationEditRequests(
        HRAccount $hr,
        ?string $status = null,
        ?callable $editRequestFormatter = null
    ): JsonResponse {
        if (! $this->isHrAccessControlOwner($hr)) {
            return response()->json(['message' => 'Only HR Admin accounts can view application edit requests.'], 403);
        }

        $normalizedStatus = strtoupper(trim((string) ($status ?? LeaveApplicationUpdateRequest::STATUS_PENDING)));
        $allowedStatuses = [
            LeaveApplicationUpdateRequest::STATUS_PENDING,
            LeaveApplicationUpdateRequest::STATUS_APPROVED,
            LeaveApplicationUpdateRequest::STATUS_REJECTED,
            'ALL',
        ];
        if (! in_array($normalizedStatus, $allowedStatuses, true)) {
            $normalizedStatus = LeaveApplicationUpdateRequest::STATUS_PENDING;
        }

        $query = LeaveApplicationUpdateRequest::query()
            ->with(['leaveApplication.leaveType', 'reviewedByHr'])
            ->latest('id');

        if ($normalizedStatus !== 'ALL') {
            $query->where('status', $normalizedStatus);
        }

        $requests = $query
            ->limit(500)
            ->get()
            ->filter(fn (LeaveApplicationUpdateRequest $editRequest): bool => $this->isHrApplicationEditRequestRecord($editRequest))
            ->values();

        return response()->json([
            'requests' => $requests
                ->map(fn (LeaveApplicationUpdateRequest $editRequest): array => $editRequestFormatter !== null ? $editRequestFormatter($editRequest) : $this->formatHrApplicationEditRequest($editRequest))
                ->all(),
            'count' => $requests->count(),
        ]);
    }

    public function executeHrApproveApplicationEditRequest(
        HRAccount $hr,
        int $id,
        ?string $remarks = null,
        ?callable $controlNoCandidatesResolver = null,
        ?callable $findAdminEmployeeControlNo = null,
        ?callable $findEmployeeByControlNo = null,
        ?callable $editRequestFormatter = null,
        ?callable $applicationFormatter = null
    ): JsonResponse {
        if (! $this->isHrAccessControlOwner($hr)) {
            return response()->json(['message' => 'Only HR Admin accounts can approve application edit requests.'], 403);
        }

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
            $this->trimNullableString($remarks),
            $controlNoCandidatesResolver,
            $findAdminEmployeeControlNo,
            $findEmployeeByControlNo
        );

        if ($applied instanceof JsonResponse) {
            return $applied;
        }

        $editRequest->refresh()->load(['leaveApplication.leaveType', 'reviewedByHr']);

        return response()->json([
            'message' => 'Application edit request approved and applied.',
            'edit_request' => $editRequestFormatter !== null
                ? $editRequestFormatter($editRequest)
                : $this->formatHrApplicationEditRequest($editRequest),
            'application' => $applicationFormatter !== null
                ? $applicationFormatter($applied->fresh(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests']))
                : $applied->fresh(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])?->toArray(),
        ]);
    }

    public function executeLogPrint(
        Request $request,
        int $id
    ): JsonResponse {
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

    public function executeErmsLogPrint(
        Request $request,
        int $id
    ): JsonResponse {
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

    public function executeHrPrintLogs(int $id): JsonResponse
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

    public function executeErmsEmployeeAccess(
        string $controlNo,
        ?callable $findEmployeeByControlNo = null,
        ?callable $isPulledEmployeeResolver = null,
        ?callable $isDepartmentAdminEmployeeResolver = null,
        ?callable $controlNoResponseBuilder = null
    ): JsonResponse {
        if ($controlNo === '') {
            return response()->json([
                'message' => 'The employee_control_no query parameter is required.',
            ], 422);
        }

        $controlNoPayload = $controlNoResponseBuilder !== null
            ? $controlNoResponseBuilder($controlNo)
            : ['employee_control_no' => $controlNo];

        $employee = $findEmployeeByControlNo !== null
            ? $findEmployeeByControlNo($controlNo)
            : null;

        if (! $employee) {
            return response()->json([
                'message' => 'Employee record not found.',
                ...$controlNoPayload,
                'is_pulled_employee' => false,
                'is_department_admin_employee' => false,
                'can_access_leave_modules' => false,
            ], 404);
        }

        $resolvedControlNo = (string) $employee->control_no;
        $isPulledEmployee = $isPulledEmployeeResolver !== null
            ? $isPulledEmployeeResolver($resolvedControlNo)
            : false;
        $isDepartmentAdminEmployee = $isDepartmentAdminEmployeeResolver !== null
            ? $isDepartmentAdminEmployeeResolver($resolvedControlNo)
            : false;
        $canAccessLeaveModules = $isPulledEmployee || $isDepartmentAdminEmployee;

        $resolvedControlNoPayload = $controlNoResponseBuilder !== null
            ? $controlNoResponseBuilder($resolvedControlNo)
            : ['employee_control_no' => $resolvedControlNo];

        return response()->json([
            ...$resolvedControlNoPayload,
            'is_pulled_employee' => $isPulledEmployee,
            'is_department_admin_employee' => $isDepartmentAdminEmployee,
            'can_access_leave_modules' => $canAccessLeaveModules,
        ]);
    }

    public function executeErmsIndex(
        string $controlNo,
        ?callable $findEmployeeByControlNo = null,
        ?callable $assertErmsEmployeeIsPulled = null,
        ?callable $applyApplicationOwnershipFilter = null,
        ?callable $actorDirectoryBuilder = null,
        ?callable $employeeContextFormatter = null,
        ?callable $allowedLeaveTypesResolver = null,
        ?callable $ermsApplicationFormatter = null,
        ?callable $controlNoResponseBuilder = null
    ): JsonResponse {
        if ($controlNo === '') {
            return response()->json([
                'message' => 'The employee_control_no query parameter is required.',
            ], 422);
        }

        $employee = $findEmployeeByControlNo !== null
            ? $findEmployeeByControlNo($controlNo)
            : null;

        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        if ($assertErmsEmployeeIsPulled !== null) {
            $pulledAccess = $assertErmsEmployeeIsPulled((string) $employee->control_no);
            if ($pulledAccess instanceof JsonResponse) {
                return $pulledAccess;
            }
        }

        $applications = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->where(function ($query) use ($applyApplicationOwnershipFilter, $controlNo): void {
                if ($applyApplicationOwnershipFilter !== null) {
                    $applyApplicationOwnershipFilter($query, $controlNo);
                } else {
                    $query->where('employee_control_no', $controlNo);
                }
            })
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $applications = $this->sortLeaveApplicationsForHrQueue($applications);

        $actorDirectory = $actorDirectoryBuilder !== null
            ? $actorDirectoryBuilder($applications)
            : $this->buildWorkflowActorDirectory($applications);

        $employeeContext = $employeeContextFormatter !== null
            ? $employeeContextFormatter($employee)
            : [];

        $leaveTypes = $allowedLeaveTypesResolver !== null
            ? $allowedLeaveTypesResolver($employee)
            : [];

        $controlNoPayload = $controlNoResponseBuilder !== null
            ? $controlNoResponseBuilder((string) $employee->control_no)
            : ['employee_control_no' => (string) $employee->control_no];

        return response()->json([
            ...$controlNoPayload,
            'employment_status' => $employeeContext['status'] ?? null,
            'employment_status_key' => $employeeContext['employment_status_key'] ?? null,
            'ui_variant' => $employeeContext['ui_variant'] ?? null,
            'employee' => $employeeContext,
            'leave_types' => $leaveTypes,
            'applications' => $applications
                ->map(fn (LeaveApplication $app) => $ermsApplicationFormatter !== null
                    ? $ermsApplicationFormatter($app, $actorDirectory)
                    : $app->toArray()
                )
                ->values(),
        ]);
    }

    public function executeErmsShow(
        string $controlNo,
        int $id,
        ?callable $findEmployeeByControlNo = null,
        ?callable $assertErmsEmployeeIsPulled = null,
        ?callable $applyApplicationOwnershipFilter = null,
        ?callable $actorDirectoryBuilder = null,
        ?callable $leaveBalanceSnapshotResolver = null,
        ?callable $findLeaveTypeBalanceInSnapshot = null,
        ?callable $ermsApplicationFormatter = null,
        ?callable $certificationLeaveCreditsResolver = null,
        ?callable $controlNoResponseBuilder = null
    ): JsonResponse {
        if ($controlNo === '') {
            return response()->json([
                'message' => 'The employee_control_no query parameter is required.',
            ], 422);
        }

        $employee = $findEmployeeByControlNo !== null
            ? $findEmployeeByControlNo($controlNo)
            : null;

        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        if ($assertErmsEmployeeIsPulled !== null) {
            $pulledAccess = $assertErmsEmployeeIsPulled((string) $employee->control_no);
            if ($pulledAccess instanceof JsonResponse) {
                return $pulledAccess;
            }
        }

        $application = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->where('id', $id)
            ->where(function ($query) use ($applyApplicationOwnershipFilter, $controlNo): void {
                if ($applyApplicationOwnershipFilter !== null) {
                    $applyApplicationOwnershipFilter($query, $controlNo);
                } else {
                    $query->where('employee_control_no', $controlNo);
                }
            })
            ->first();

        if (! $application) {
            return response()->json(['message' => 'Leave application not found for this employee.'], 404);
        }

        $actorDirectory = $actorDirectoryBuilder !== null
            ? $actorDirectoryBuilder(collect([$application]))
            : $this->buildWorkflowActorDirectory(collect([$application]));

        $leaveBalanceSnapshot = $leaveBalanceSnapshotResolver !== null
            ? $leaveBalanceSnapshotResolver($application)
            : [];

        $currentLeaveBalance = $findLeaveTypeBalanceInSnapshot !== null
            ? $findLeaveTypeBalanceInSnapshot($leaveBalanceSnapshot, (int) $application->leave_type_id)
            : null;

        $applicationPayload = $ermsApplicationFormatter !== null
            ? $ermsApplicationFormatter($application, $actorDirectory)
            : $application->toArray();

        $certificationLeaveCredits = $certificationLeaveCreditsResolver !== null
            ? $certificationLeaveCreditsResolver($application, $leaveBalanceSnapshot)
            : null;

        $applicationPayload['leaveBalance'] = $currentLeaveBalance;
        $applicationPayload['leaveBalances'] = $leaveBalanceSnapshot;
        $applicationPayload['leave_balances'] = $leaveBalanceSnapshot;
        $applicationPayload['employee_leave_balances'] = $leaveBalanceSnapshot;
        $applicationPayload['certificationLeaveCredits'] = $certificationLeaveCredits;
        $applicationPayload['certification_leave_credits'] = $certificationLeaveCredits;

        $controlNoPayload = $controlNoResponseBuilder !== null
            ? $controlNoResponseBuilder((string) $employee->control_no)
            : ['employee_control_no' => (string) $employee->control_no];

        return response()->json([
            ...$controlNoPayload,
            'application' => $applicationPayload,
        ]);
    }

    public function executeErmsCancel(
        int $applicationId,
        object $employee,
        string $controlNo,
        string $cancellationReason,
        callable $ownershipFilter,
        callable $statusLabelResolver,
        callable $actorDirectoryBuilder,
        callable $applicationFormatter
    ): JsonResponse {
        $app = LeaveApplication::query()
            ->with('leaveType')
            ->where('id', $applicationId)
            ->where(fn ($query) => $ownershipFilter($query, $controlNo))
            ->first();

        if (! $app) {
            return response()->json(['message' => 'Leave application not found for this employee.'], 404);
        }

        $cancelableStatuses = [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ];

        if (! in_array($app->status, $cancelableStatuses, true)) {
            $statusLabel = $statusLabelResolver($app->status);

            return response()->json([
                'message' => "Cannot cancel: application status is '{$statusLabel}'. Only pending applications can be cancelled.",
            ], 422);
        }

        $reason = trim($cancellationReason);
        $remarks = $reason !== ''
            ? "Cancelled by employee: {$reason}"
            : 'Cancelled by employee';
        $statusBeforeCancel = $app->status;

        $performedById = (int) ltrim((string) $employee->control_no, '0');
        if ($performedById <= 0) {
            $performedById = (int) ($app->employee_control_no ?: 1);
        }

        $this->employeeCancelPendingApplication(
            $app,
            $performedById,
            $remarks
        );

        $this->notifyPartiesOfEmployeeCancellation(
            $app,
            $employee,
            $statusBeforeCancel,
            $reason
        );

        $application = $app->fresh(['leaveType', 'applicantAdmin.department', 'logs']);
        $actorDirectory = $actorDirectoryBuilder([$application]);

        return response()->json([
            'message' => 'Leave application cancelled successfully.',
            'application' => $applicationFormatter($application, $actorDirectory),
        ]);
    }

    public function executeHrVerifyDocument(
        mixed $token,
        LeaveApplicationDocumentVerificationService $documentVerificationService,
        callable $isPendingApprovedUpdateRequest,
        callable $isCancelledRemark,
        callable $actorDirectoryBuilder,
        callable $applicationFormatter
    ): JsonResponse {
        $decodedToken = $documentVerificationService->decode((string) $token);
        if ($decodedToken === null) {
            return response()->json([
                'message' => 'The leave application QR code is invalid or has been altered.',
                'verification' => [
                    'status' => 'invalid',
                    'token_valid' => false,
                    'document_current' => false,
                    'can_receive' => false,
                ],
            ], 422);
        }

        $application = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($decodedToken['application_id']);

        if (! $application) {
            return response()->json([
                'message' => 'The verified QR code refers to a leave application that no longer exists.',
                'verification' => [
                    'status' => 'not_found',
                    'token_valid' => true,
                    'document_current' => false,
                    'can_receive' => false,
                ],
            ], 404);
        }

        $documentCurrent = $documentVerificationService->isCurrent(
            $application,
            $decodedToken['fingerprint']
        );
        $receivedLog = $application->logs->first(
            fn (LeaveApplicationLog $log): bool => $log->action === LeaveApplicationLog::ACTION_HR_RECEIVED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );
        $releasedLog = $application->logs->first(
            fn (LeaveApplicationLog $log): bool => $log->action === LeaveApplicationLog::ACTION_HR_RELEASED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );
        $cmoCbmoReviewedLog = $application->logs->first(
            fn (LeaveApplicationLog $log): bool => $log->action === LeaveApplicationLog::ACTION_CMO_CBMO_REVIEWED
                && strtoupper((string) $log->performed_by_type) === LeaveApplicationLog::PERFORMER_HR
        );
        $canReceiveByStatus = $application->status !== LeaveApplication::STATUS_PENDING_ADMIN
            || $isPendingApprovedUpdateRequest($application);
        $isCancelled = $application->status === LeaveApplication::STATUS_CANCELLED
            || $application->logs->contains(
                fn (LeaveApplicationLog $log): bool => $isCancelledRemark($log->remarks)
            ) || $isCancelledRemark($application->remarks);
        $currentVerification = $documentVerificationService->issue($application);
        $actorDirectory = $actorDirectoryBuilder([$application]);

        $canRelease = $documentCurrent
            && $releasedLog === null
            && $receivedLog !== null
            && $cmoCbmoReviewedLog !== null
            && $canReceiveByStatus
            && ! $isCancelled;

        return response()->json([
            'message' => $documentCurrent
                ? 'The QR code is valid. Compare the printed form with the official application details before receiving or releasing it.'
                : 'The QR code is authentic, but the printed form is no longer the current application version.',
            'verification' => [
                'status' => $documentCurrent ? 'verified' : 'outdated',
                'token_valid' => true,
                'document_current' => $documentCurrent,
                'scanned_reference' => $documentVerificationService->reference(
                    (int) $decodedToken['application_id'],
                    (string) $decodedToken['fingerprint']
                ),
                'current_reference' => $currentVerification['reference'],
                'format_version' => $decodedToken['format_version'],
                'already_received' => $receivedLog !== null,
                'already_released' => $releasedLog !== null,
                'has_cmo_cbmo_review' => $cmoCbmoReviewedLog !== null,
                'can_receive' => $documentCurrent && $receivedLog === null && $canReceiveByStatus && ! $isCancelled,
                'can_release' => $canRelease,
                'checked_at' => now()->toIso8601String(),
            ],
            'application' => $applicationFormatter($application, $actorDirectory),
        ]);
    }

    public function executeHrReceive(
        int $id,
        HRAccount $hr,
        array $validated,
        LeaveApplicationDocumentVerificationService $documentVerificationService,
        callable $actorDirectoryBuilder,
        callable $applicationFormatter,
        callable $isCancelledRemark,
        callable $isPendingApprovedUpdateRequest
    ): JsonResponse {
        $verificationToken = trim((string) ($validated['verification_token'] ?? ''));
        $decodedToken = null;
        if ($verificationToken !== '') {
            $decodedToken = $documentVerificationService->decode($verificationToken);
            if ($decodedToken === null || (int) $decodedToken['application_id'] !== $id) {
                return response()->json([
                    'message' => 'Scan and verify the QR code from this leave application form before receiving it.',
                ], 422);
            }
        }

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if ($decodedToken !== null && ! $documentVerificationService->isCurrent($app, (string) $decodedToken['fingerprint'])) {
            return response()->json([
                'message' => 'This printed form is outdated. Print the current leave application form and scan its QR code.',
            ], 422);
        }

        $isCancelled = $app->status === LeaveApplication::STATUS_CANCELLED
            || $app->logs->contains(
                fn (LeaveApplicationLog $log): bool => $isCancelledRemark($log->remarks)
            ) || $isCancelledRemark($app->remarks);
        if ($isCancelled) {
            return response()->json([
                'message' => 'Cannot mark as received: application is cancelled.',
            ], 422);
        }

        $pendingApprovedUpdate = $isPendingApprovedUpdateRequest($app);
        if ($app->status === LeaveApplication::STATUS_PENDING_ADMIN && ! $pendingApprovedUpdate) {
            return response()->json([
                'message' => "Cannot mark as received: application status is '{$app->status}'.",
            ], 422);
        }

        $wasNewlyReceived = $this->confirmHardCopyReceipt(
            $app,
            $hr,
            $validated['remarks'] ?? null
        );

        $actorDirectory = $actorDirectoryBuilder([$app]);

        return response()->json([
            'message' => $wasNewlyReceived
                ? 'Hard-copy receipt confirmed.'
                : 'Hard-copy receipt was already confirmed for this application.',
            'application' => $applicationFormatter($app, $actorDirectory),
        ]);
    }

    public function executeHrRelease(
        int $id,
        HRAccount $hr,
        array $validated,
        LeaveApplicationDocumentVerificationService $documentVerificationService,
        callable $actorDirectoryBuilder,
        callable $applicationFormatter,
        callable $isCancelledRemark,
        callable $isPendingApprovedUpdateRequest
    ): JsonResponse {
        $verificationToken = trim((string) ($validated['verification_token'] ?? ''));
        $decodedToken = null;
        if ($verificationToken !== '') {
            $decodedToken = $documentVerificationService->decode($verificationToken);
            if ($decodedToken === null || (int) $decodedToken['application_id'] !== $id) {
                return response()->json([
                    'message' => 'Scan and verify the QR code from this leave application form before releasing it.',
                ], 422);
            }
        }

        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        if ($decodedToken !== null && ! $documentVerificationService->isCurrent($app, (string) $decodedToken['fingerprint'])) {
            return response()->json([
                'message' => 'This printed form is outdated. Print the current leave application form and scan its QR code.',
            ], 422);
        }

        $isCancelled = $app->status === LeaveApplication::STATUS_CANCELLED
            || $app->logs->contains(
                fn (LeaveApplicationLog $log): bool => $isCancelledRemark($log->remarks)
            ) || $isCancelledRemark($app->remarks);
        if ($isCancelled) {
            return response()->json([
                'message' => 'Cannot mark as released: application is cancelled.',
            ], 422);
        }

        $pendingApprovedUpdate = $isPendingApprovedUpdateRequest($app);
        if ($app->status === LeaveApplication::STATUS_PENDING_ADMIN && ! $pendingApprovedUpdate) {
            return response()->json([
                'message' => "Cannot mark as released: application status is '{$app->status}'.",
            ], 422);
        }

        $releaseResult = $this->confirmHardCopyRelease(
            $app,
            $hr,
            $validated['remarks'] ?? null
        );

        if (! $releaseResult['ok']) {
            return response()->json([
                'message' => $releaseResult['message'],
            ], 422);
        }

        $actorDirectory = $actorDirectoryBuilder([$app]);

        return response()->json([
            'message' => $releaseResult['already_released']
                ? 'Hard-copy release was already confirmed for this application.'
                : 'Hard-copy release confirmed.',
            'application' => $applicationFormatter($app, $actorDirectory),
        ]);
    }

    public function executeHrUndoReceive(
        int $id,
        callable $cycleRequestedAtResolver,
        callable $actorDirectoryBuilder,
        callable $applicationFormatter
    ): JsonResponse {
        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $cycleRequestedAt = $cycleRequestedAtResolver($app);
        $undoResult = $this->undoHardCopyReceipt($app, $cycleRequestedAt);

        if (! $undoResult['ok']) {
            return response()->json([
                'message' => $undoResult['message'],
            ], 422);
        }

        $actorDirectory = $actorDirectoryBuilder([$app]);

        return response()->json([
            'message' => $undoResult['was_undone']
                ? 'Hard-copy receipt was undone.'
                : 'Hard-copy receipt is not currently confirmed for this application.',
            'application' => $applicationFormatter($app, $actorDirectory),
        ]);
    }

    public function executeHrUndoRelease(
        int $id,
        callable $cycleRequestedAtResolver,
        callable $actorDirectoryBuilder,
        callable $applicationFormatter
    ): JsonResponse {
        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $wasUndone = $this->undoHardCopyRelease(
            $app,
            $cycleRequestedAtResolver($app)
        );

        $actorDirectory = $actorDirectoryBuilder([$app]);

        return response()->json([
            'message' => $wasUndone
                ? 'Hard-copy release was undone.'
                : 'Hard-copy release is not currently confirmed for this application.',
            'application' => $applicationFormatter($app, $actorDirectory),
        ]);
    }

    public function executeHrCmoCbmoReview(
        int $id,
        HRAccount $hr,
        ?string $remarks,
        callable $smsNotificationCallback,
        callable $actorDirectoryBuilder,
        callable $applicationFormatter
    ): JsonResponse {
        $app = LeaveApplication::query()
            ->with(['leaveType', 'applicantAdmin.department', 'logs', 'updateRequests'])
            ->find($id);
        if (! $app) {
            return response()->json(['message' => 'Leave application not found.'], 404);
        }

        $reviewResult = $this->confirmCmoCbmoReview(
            $app,
            $hr,
            $remarks
        );

        if (! $reviewResult['ok']) {
            return response()->json([
                'message' => $reviewResult['message'],
            ], 422);
        }

        if (! $reviewResult['already_reviewed']) {
            $smsNotificationCallback($app);
        }

        $actorDirectory = $actorDirectoryBuilder([$app]);

        return response()->json([
            'message' => $reviewResult['already_reviewed']
                ? 'CMO/CVMO review was already completed for this application.'
                : 'CMO/CVMO review completed.',
            'application' => $applicationFormatter($app, $actorDirectory),
        ]);
    }

    public function executeHrReject(
        LeaveApplication $app,
        HRAccount $hr,
        ?string $remarks = null,
        ?callable $smsCallback = null,
        ?callable $applicationFormatter = null,
        ?callable $pendingUpdateRejectHandler = null
    ): JsonResponse {
        if ($app->status !== LeaveApplication::STATUS_PENDING_HR) {
            return response()->json(['message' => "Cannot reject: application status is '{$app->status}', expected 'PENDING_HR'."], 422);
        }

        if ($this->isPendingApprovedUpdateRequest($app)) {
            if (is_callable($pendingUpdateRejectHandler)) {
                return $pendingUpdateRejectHandler($app);
            }
        }

        $this->hrRejectApplication($app, $hr, $remarks);

        // Notify the applicant that HR rejected
        $this->notifyApplicantOfHrRejection(
            $app,
            $remarks,
            $smsCallback
        );

        $freshApp = $app->fresh('leaveType');
        $formatted = is_callable($applicationFormatter)
            ? $applicationFormatter($freshApp)
            : $this->formatApplication($freshApp);

        return response()->json([
            'message' => 'Application rejected by HR.',
            'application' => $formatted,
        ]);
    }

    public function streamApplicationAttachment(LeaveApplication $application)
    {
        $reference = trim((string) ($application->attachment_reference ?? ''));
        if ($reference === '') {
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
            /** @var \Illuminate\Filesystem\FilesystemAdapter $storage */
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

    public function formatApplicationUpdateRequests(
        LeaveApplication $app,
        ?callable $policyResolver = null
    ): array {
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
                'payload' => $this->normalizePendingUpdatePayload($r->requested_payload, $policyResolver),
                'reason' => trim((string) ($r->requested_reason ?? '')) !== '' ? trim((string) $r->requested_reason) : null,
                'previous_status' => strtoupper(trim((string) ($r->previous_status ?? ''))),
                'requested_by' => trim((string) ($r->requested_by_control_no ?? '')) !== '' ? trim((string) $r->requested_by_control_no) : null,
                'requested_at' => $r->requested_at?->toIso8601String(),
                'reviewed_at' => $r->reviewed_at?->toIso8601String(),
                'review_remarks' => trim((string) ($r->review_remarks ?? '')) !== '' ? trim((string) $r->review_remarks) : null,
            ])
            ->values()
            ->all();
    }

    public function normalizeControlNo(?string $value): string
    {
        return $this->eligibilityService()->normalizeControlNo($value);
    }

    public function resolveApplicationEmployee(
        LeaveApplication $app,
        ?callable $findEmployeeByControlNo = null
    ): ?object {
        if ($app->employee_control_no === null) {
            return null;
        }

        $controlNo = trim((string) $app->employee_control_no);
        if ($controlNo === '') {
            return null;
        }

        return $findEmployeeByControlNo !== null
            ? $findEmployeeByControlNo($controlNo)
            : HrisEmployee::findByControlNo($controlNo);
    }

    public function isDepartmentHeadRecord(?string $controlNo): bool
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

    public function formatErmsApplication(
        LeaveApplication $app,
        array $actorDirectory = [],
        ?callable $findEmployeeByControlNo = null
    ): array {
        $documentVerification = $this->documentVerificationService()->issue($app);
        $resolvedEmployee = $this->resolveApplicationEmployee($app, $findEmployeeByControlNo);
        $employeeName = trim((string) ($app->employee_name ?? ''));
        if ($employeeName === '') {
            $employeeName = $this->formatEmployeeFullName($resolvedEmployee);
        }
        if ($employeeName === '') {
            $employeeName = trim((string) ($app->applicantAdmin?->full_name ?? ''));
        }
        if ($employeeName === '') {
            $employeeName = $this->resolveEmployeeDisplayName($app, $findEmployeeByControlNo);
        }
        $office = $resolvedEmployee?->office ?? ($app->applicantAdmin?->department?->name ?? '');

        $milestoneLogs = $this->resolveApplicationMilestoneLogs(
            $app,
            fn ($r) => $this->isCancelledRemark($r)
        );
        $submittedLog = $milestoneLogs['submitted'];
        $adminApprovedLog = $milestoneLogs['admin_approved'];
        $hrApprovedLog = $milestoneLogs['hr_approved'];
        $hrReceivedLog = $milestoneLogs['hr_received'];
        $cmoCbmoReviewedLog = $milestoneLogs['cmo_cbmo_reviewed'];
        $hrReleasedLog = $milestoneLogs['hr_released'];
        $hrRecalledLog = $milestoneLogs['hr_recalled'];
        $adminRejectedLog = $milestoneLogs['admin_rejected'];
        $hrRejectedLog = $milestoneLogs['hr_rejected'];
        $cancelledLog = $milestoneLogs['cancelled'];

        $filedBy = $this->resolveWorkflowPerformerName($submittedLog, $actorDirectory, $employeeName);
        if (! $filedBy && $app->applicant_admin_id && $app->applicantAdmin) {
            $filedBy = trim((string) ($app->applicantAdmin->full_name ?? '')) ?: null;
        }
        if (! $filedBy) {
            $filedBy = $employeeName;
        }
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
        $cmoCbmoReviewActionBy = $this->resolveWorkflowPerformerName($cmoCbmoReviewedLog, $actorDirectory, $employeeName);
        $releasedActionBy = $this->resolveWorkflowPerformerName($hrReleasedLog, $actorDirectory, $employeeName);

        $isCancelled = $app->status === LeaveApplication::STATUS_CANCELLED || $cancelledLog !== null || $this->isCancelledRemark($app->remarks);
        $hasPendingApprovedUpdateRequest = $this->hasPendingApprovedUpdateRequest($app);
        $displayStatus = $isCancelled
            ? 'Cancelled'
            : (
                $this->shouldPresentApprovedWhilePendingUpdate($app, $hasPendingApprovedUpdateRequest)
                    ? 'Approved'
                    : $this->ermsStatusLabel($app->status)
            );
        $queueMeta = $this->resolveLeaveHrQueueMeta($app);
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
        $cmoCbmoReviewActionAt = $cmoCbmoReviewedLog?->created_at;
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

        $logs = $app->relationLoaded('logs')
            ? $app->logs->sortBy(fn (LeaveApplicationLog $log) => $log->created_at?->timestamp ?? 0)->values()
            : collect();

        $statusHistory = $logs
            ->filter(fn (LeaveApplicationLog $log): bool => ! $this->isHrApplicationEditWorkflowLog($log))
            ->map(function (LeaveApplicationLog $log) use ($actorDirectory, $employeeName) {
                $actorName = $this->resolveWorkflowPerformerName($log, $actorDirectory, $employeeName);

                return [
                    'id' => (int) $log->id,
                    'leave_application_id' => (int) $log->leave_application_id,
                    'action' => $log->action,
                    'stage' => $this->mapWorkflowLogStage($log),
                    'actor_name' => $actorName,
                    'action_by_name' => $actorName,
                    'action_by' => $actorName,
                    'performed_by_type' => strtoupper((string) $log->performed_by_type),
                    'performed_by_id' => $log->performed_by_id !== null ? (int) $log->performed_by_id : null,
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
        $isForcedLeave = LeaveType::isForcedLeaveType($app->leaveType, (int) $app->leave_type_id);
        $normalizedPayMode = $this->calculationService()->normalizePayMode($app->pay_mode ?? null, (bool) $app->is_monetization);
        if ($isForcedLeave) {
            $normalizedPayMode = LeaveApplication::PAY_MODE_WITH_PAY;
        }
        $withoutPay = $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY;
        $selectedDatePayStatus = $isForcedLeave
            ? null
            : (is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null);
        $deductibleDays = $isForcedLeave
            ? round(max((float) ($app->total_days ?? 0.0), 0.0), 3)
            : $this->calculationService()->resolveApplicationDeductibleDays($app);
        $ctoDeductedHours = $this->calculationService()->resolveApplicationCtoDeductedHours($app);
        $resolvedReason = $this->trimNullableString($app->reason)
            ?? $this->trimNullableString($latestPayload['reason'] ?? null)
            ?? $this->trimNullableString($pendingPayload['reason'] ?? null);
        $resolvedDetailsOfLeave = $this->trimNullableString($app->details_of_leave)
            ?? $this->trimNullableString($latestPayload['details_of_leave'] ?? $latestPayload['detailsOfLeave'] ?? null)
            ?? $this->trimNullableString($pendingPayload['details_of_leave'] ?? $pendingPayload['detailsOfLeave'] ?? null);
        $monetizationComponents = (bool) $app->is_monetization
            ? $this->certificationService()->resolveStoredMonetizationLeaveCreditComponents($app)
            : [];
        $displayLeaveTypeName = (bool) $app->is_monetization && $monetizationComponents !== []
            ? $this->eligibilityService()->formatMonetizationComponentsLabel($monetizationComponents, false)
            : $app->leaveType?->name;

        return [
            'id' => $app->id,
            'document_verification' => $documentVerification,
            'documentVerification' => $documentVerification,
            'employee_control_no' => $app->employee_control_no ? (string) $app->employee_control_no : null,
            'leave_type_id' => $app->leave_type_id,
            'leave_type_name' => $displayLeaveTypeName,
            'start_date' => $app->start_date?->toDateString(),
            'end_date' => $app->end_date?->toDateString(),
            'selected_dates' => $selectedDates,
            'selected_date_pay_status' => $selectedDatePayStatus,
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
            'allow_sl_vl_cross_deduction' => (bool) ($app->allow_sl_vl_cross_deduction ?? false),
            'pay_status' => $withoutPay ? 'Without Pay' : 'With Pay',
            'without_pay' => $withoutPay,
            'with_pay' => ! $withoutPay,
            'linked_forced_leave_deducted_days' => round(max((float) ($app->linked_forced_leave_deducted_days ?? 0.0), 0.0), 3),
            'linked_vacation_leave_deducted_days' => round(max((float) ($app->linked_vacation_leave_deducted_days ?? 0.0), 0.0), 3),
            'linked_sick_leave_deducted_days' => round(max((float) ($app->linked_sick_leave_deducted_days ?? 0.0), 0.0), 3),
            'attachment_required' => (bool) ($app->attachment_required ?? false),
            'attachment_submitted' => (bool) ($app->attachment_submitted ?? false),
            'attachment_reference' => $this->trimNullableString($app->attachment_reference ?? null),
            'is_monetization' => (bool) $app->is_monetization,
            'monetization_leave_credits' => $monetizationComponents,
            'monetization_leave_credit_label' => $monetizationComponents !== []
                ? $this->eligibilityService()->formatMonetizationComponentsLabel($monetizationComponents)
                : null,
            'date_filed' => $app->created_at?->toDateString(),
            'filed_at' => $app->created_at?->toIso8601String(),
            'created_at' => $app->created_at?->toIso8601String(),
            'status' => $displayStatus,
            'raw_status' => $app->status,
            'queue_group_status' => $queueMeta['group_status'],
            'queueGroupStatus' => $queueMeta['group_status'],
            'queue_group_priority' => $queueMeta['group_priority'],
            'queueGroupPriority' => $queueMeta['group_priority'],
            'queue_stage_key' => $queueMeta['stage_key'],
            'queueStageKey' => $queueMeta['stage_key'],
            'queue_stage_priority' => $queueMeta['stage_priority'],
            'queueStagePriority' => $queueMeta['stage_priority'],
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
            'update_requests' => $this->formatApplicationUpdateRequests($app),
            'updateRequests' => $this->formatApplicationUpdateRequests($app),
            'rejection_reason' => $app->status === LeaveApplication::STATUS_REJECTED && ! $isCancelled ? $app->remarks : null,
            'employee_name' => $employeeName,
            'office' => $office,
            'officeAcronym' => $resolvedEmployee?->officeAcronym,
            'office_acronym' => $resolvedEmployee?->officeAcronym,
            'hrisOfficeAcronym' => $resolvedEmployee?->hrisOfficeAcronym,
            'hris_office_acronym' => $resolvedEmployee?->hrisOfficeAcronym,
            'employment_status' => $resolvedEmployee?->status,
            'employment_status_key' => LeaveType::normalizeEmploymentStatusKey($resolvedEmployee?->status),
            'filed_by' => $filedBy,
            'approver_name' => $approverName,
            'admin_action_by' => $adminActionBy,
            'hr_action_by' => $hrActionBy,
            'received_by' => $receivedActionBy,
            'receivedBy' => $receivedActionBy,
            'hr_received_by' => $receivedActionBy,
            'cmo_cbmo_reviewed_by' => $cmoCbmoReviewActionBy,
            'cmoCbmoReviewedBy' => $cmoCbmoReviewActionBy,
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
            'cmo_cbmo_reviewed_at' => $cmoCbmoReviewActionAt?->toIso8601String(),
            'cmoCbmoReviewedAt' => $cmoCbmoReviewActionAt?->toIso8601String(),
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
            'has_cmo_cbmo_reviewed' => $cmoCbmoReviewedLog !== null,
            'hasCmoCbmoReviewed' => $cmoCbmoReviewedLog !== null,
            'has_hr_released' => $hrReleasedLog !== null,
            'hasHrReleased' => $hrReleasedLog !== null,
            'status_history' => $statusHistory,
            'logs' => $statusHistory,
            'employee' => $resolvedEmployee ? [
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
                'employment_status' => $resolvedEmployee->status,
                'employment_status_key' => LeaveType::normalizeEmploymentStatusKey($resolvedEmployee->status),
                'status' => $resolvedEmployee->status,
            ] : null,
            'is_department_head' => $this->isDepartmentHeadRecord($resolvedEmployee?->control_no ?? $app->employee_control_no),
        ];
    }

    public function formatApplication(
        LeaveApplication $app,
        ?array $actorDirectory = null,
        ?callable $findEmployeeByControlNo = null
    ): array {
        $documentVerification = $this->documentVerificationService()->issue($app);
        $resolvedEmployee = $this->resolveApplicationEmployee($app, $findEmployeeByControlNo);
        $employeeName = trim((string) ($app->employee_name ?? ''));
        if ($employeeName === '') {
            $employeeName = $this->formatEmployeeFullName($resolvedEmployee);
        }
        $applicantName = $employeeName !== ''
            ? $employeeName
            : trim((string) ($app->applicantAdmin?->full_name ?? ''));
        if ($applicantName === '') {
            $applicantName = $this->resolveEmployeeDisplayName($app, $findEmployeeByControlNo);
        }
        $office = $resolvedEmployee?->office ?? ($app->applicantAdmin?->department?->name ?? '');
        $durationDays = (float) $app->total_days;
        $pendingUpdateMeta = $this->resolvePendingUpdateMeta($app);
        $latestUpdateMeta = $this->resolveLatestUpdateMeta($app);
        $leaveBalanceSnapshot = $this->creditService()->getApplicationLeaveBalanceSnapshot($app);
        $vacationLeaveBalance = $this->certificationService()->findLeaveTypeBalanceByNameInSnapshot($leaveBalanceSnapshot, 'Vacation Leave');
        $sickLeaveBalance = $this->certificationService()->findLeaveTypeBalanceByNameInSnapshot($leaveBalanceSnapshot, 'Sick Leave');
        $monthlyRate = $resolvedEmployee?->rate_mon !== null ? (float) $resolvedEmployee->rate_mon : null;
        $terminalLeaveEstimatedAmount = $this->certificationService()->resolveTerminalLeaveEstimatedAmount(
            $monthlyRate,
            $vacationLeaveBalance,
            $sickLeaveBalance
        );
        $monetizationComponents = (bool) $app->is_monetization
            ? $this->certificationService()->resolveStoredMonetizationLeaveCreditComponents($app)
            : [];
        $displayLeaveTypeName = (bool) $app->is_monetization && $monetizationComponents !== []
            ? $this->eligibilityService()->formatMonetizationComponentsLabel($monetizationComponents, false)
            : ($app->leaveType?->name ?? 'Unknown');
        $isForcedLeave = LeaveType::isForcedLeaveType($app->leaveType, (int) $app->leave_type_id);
        $currentLeaveBalance = $this->certificationService()->findLeaveTypeBalanceInSnapshot($leaveBalanceSnapshot, (int) $app->leave_type_id);
        $normalizedPayMode = $this->calculationService()->normalizePayMode($app->pay_mode ?? null, (bool) $app->is_monetization);
        $hasForcedLeaveHrLedgerOverride = $isForcedLeave
            && (
                is_array($app->selected_date_pay_status)
                || is_array($app->selected_date_deduction)
                || is_array($app->selected_date_without_pay)
                || round(max((float) ($app->without_pay_days ?? 0.0), 0.0), 3) > 0.0
            );
        if ($isForcedLeave && ! $hasForcedLeaveHrLedgerOverride) {
            $normalizedPayMode = LeaveApplication::PAY_MODE_WITH_PAY;
        }
        $withoutPay = $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY;
        $selectedDatePayStatus = is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null;
        $deductibleDays = $isForcedLeave && ! $hasForcedLeaveHrLedgerOverride
            ? round(max((float) ($app->total_days ?? 0.0), 0.0), 3)
            : $this->calculationService()->resolveApplicationDeductibleDays($app);
        $withoutPayDays = round(max((float) ($app->without_pay_days ?? 0.0), 0.0), 3);
        $ctoDeductedHours = $this->calculationService()->resolveApplicationCtoDeductedHours($app);
        $hasPendingApprovedUpdateRequest = $this->hasPendingApprovedUpdateRequest($app);
        $displayStatus = $this->shouldPresentApprovedWhilePendingUpdate($app, $hasPendingApprovedUpdateRequest)
            ? $this->statusToFrontend(LeaveApplication::STATUS_APPROVED)
            : $this->statusToFrontend($app->status);
        $queueMeta = $this->resolveLeaveHrQueueMeta($app);
        $logs = $app->relationLoaded('logs')
            ? $app->logs
                ->filter(fn ($log) => $log instanceof LeaveApplicationLog)
                ->sort(function (LeaveApplicationLog $a, LeaveApplicationLog $b) {
                    $timeA = $a->created_at?->timestamp ?? 0;
                    $timeB = $b->created_at?->timestamp ?? 0;
                    if ($timeA === $timeB) {
                        return ($a->id ?? 0) <=> ($b->id ?? 0);
                    }

                    return $timeA <=> $timeB;
                })
                ->values()
            : collect();

        if ($actorDirectory === null && $logs->isNotEmpty()) {
            $actorDirectory = $this->buildWorkflowActorDirectory([$app]);
        }

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
        $statusHistory = $this->buildWorkflowStatusHistory(
            $logs,
            $actorDirectory,
            $applicantName,
            fn ($val) => $this->normalizeControlNo($val)
        );

        $effectiveRecallDateKeys = $this->resolveEffectiveStoredRecallDateKeys($app);

        $data = [
            'id' => $app->id,
            'document_verification' => $documentVerification,
            'documentVerification' => $documentVerification,
            'employee_control_no' => $app->employee_control_no,
            'applicant_admin_id' => $app->applicant_admin_id,
            'is_department_head' => $this->isDepartmentHeadRecord($resolvedEmployee?->control_no ?? $app->employee_control_no),
            'leave_type_id' => $app->leave_type_id,
            'leaveType' => $displayLeaveTypeName,
            'leave_type_name' => $displayLeaveTypeName,
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
            'pendingUpdateRequestedAt' => $pendingUpdateMeta['requested_at']?->toIso8601String(),
            'pendingUpdateRequestedBy' => $pendingUpdateMeta['requested_by'],
            'pendingUpdateReason' => $pendingUpdateMeta['reason'],
            'pendingUpdateActionType' => $pendingUpdateMeta['action_type'],
            'pendingUpdatePreviousStatus' => $pendingUpdateMeta['previous_status'],
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
            'update_requests' => $this->formatApplicationUpdateRequests($app),
            'updateRequests' => $this->formatApplicationUpdateRequests($app),
            'selected_dates' => $app->resolvedSelectedDates(),
            'selected_date_pay_status' => $selectedDatePayStatus,
            'selected_date_coverage' => is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
            'selected_date_half_day_portion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selectedDateHalfDayPortion' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selected_date_half_day_period' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selectedDateHalfDayPeriod' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selected_date_halfday_period' => is_array($app->selected_date_half_day_portion) ? $app->selected_date_half_day_portion : null,
            'selected_date_deduction' => is_array($app->selected_date_deduction) ? $app->selected_date_deduction : null,
            'selectedDateDeduction' => is_array($app->selected_date_deduction) ? $app->selected_date_deduction : null,
            'deductible_days_by_date' => is_array($app->selected_date_deduction) ? $app->selected_date_deduction : null,
            'selected_date_without_pay' => is_array($app->selected_date_without_pay) ? $app->selected_date_without_pay : null,
            'selectedDateWithoutPay' => is_array($app->selected_date_without_pay) ? $app->selected_date_without_pay : null,
            'without_pay_days_by_date' => is_array($app->selected_date_without_pay) ? $app->selected_date_without_pay : null,
            'wop_days_by_date' => is_array($app->selected_date_without_pay) ? $app->selected_date_without_pay : null,
            'commutation' => $app->commutation ?? 'Not Requested',
            'pay_mode' => $normalizedPayMode,
            'allow_sl_vl_cross_deduction' => (bool) ($app->allow_sl_vl_cross_deduction ?? false),
            'pay_status' => $withoutPay ? 'Without Pay' : 'With Pay',
            'without_pay' => $withoutPay,
            'with_pay' => ! $withoutPay,
            'linked_forced_leave_deducted_days' => round(max((float) ($app->linked_forced_leave_deducted_days ?? 0.0), 0.0), 3),
            'linked_vacation_leave_deducted_days' => round(max((float) ($app->linked_vacation_leave_deducted_days ?? 0.0), 0.0), 3),
            'linked_sick_leave_deducted_days' => round(max((float) ($app->linked_sick_leave_deducted_days ?? 0.0), 0.0), 3),
            'attachment_required' => (bool) ($app->attachment_required ?? false),
            'attachment_submitted' => (bool) ($app->attachment_submitted ?? false),
            'attachment_reference' => $this->trimNullableString($app->attachment_reference ?? null),
            'attachment_available' => $this->trimNullableString($app->attachment_reference ?? null) !== null,
            'has_attachment' => $this->trimNullableString($app->attachment_reference ?? null) !== null,
            'is_monetization' => (bool) $app->is_monetization,
            'monetization_leave_credits' => $monetizationComponents,
            'monetizationLeaveCredits' => $monetizationComponents,
            'monetization_leave_credit_label' => $monetizationComponents !== []
                ? $this->eligibilityService()->formatMonetizationComponentsLabel($monetizationComponents)
                : null,
            'equivalent_amount' => $app->equivalent_amount ? (float) $app->equivalent_amount : null,
            'rate_mon' => $monthlyRate,
            'vl_balance' => $vacationLeaveBalance,
            'sl_balance' => $sickLeaveBalance,
            'terminal_leave_estimated_amount' => $terminalLeaveEstimatedAmount,
            'deductible_days' => $deductibleDays,
            'with_pay_days' => $deductibleDays,
            'withPayDays' => $deductibleDays,
            'without_pay_days' => $withoutPayDays,
            'withoutPayDays' => $withoutPayDays,
            'cto_deducted_hours' => $ctoDeductedHours,
            'leaveBalance' => $currentLeaveBalance,
            'leaveBalances' => $leaveBalanceSnapshot,
            'leave_balances' => $leaveBalanceSnapshot,
            'employee_leave_balances' => $leaveBalanceSnapshot,
            'certificationLeaveCredits' => $this->certificationService()->resolveCertificationLeaveCredits($app, $leaveBalanceSnapshot),
            'admin_id' => $app->admin_id,
            'hr_id' => $app->hr_id,
            'admin_approved_at' => $app->admin_approved_at?->toIso8601String(),
            'hr_approved_at' => $app->hr_approved_at?->toIso8601String(),
            'received_at' => $hrReceivedLog?->created_at?->toIso8601String(),
            'receivedAt' => $hrReceivedLog?->created_at?->toIso8601String(),
            'hr_received_at' => $hrReceivedLog?->created_at?->toIso8601String(),
            'cmo_cbmo_reviewed_at' => $cmoCbmoReviewedLog?->created_at?->toIso8601String(),
            'cmoCbmoReviewedAt' => $cmoCbmoReviewedLog?->created_at?->toIso8601String(),
            'released_at' => $hrReleasedLog?->created_at?->toIso8601String(),
            'releasedAt' => $hrReleasedLog?->created_at?->toIso8601String(),
            'hr_released_at' => $hrReleasedLog?->created_at?->toIso8601String(),
            'has_hr_received' => $hrReceivedLog !== null,
            'hasHrReceived' => $hrReceivedLog !== null,
            'has_cmo_cbmo_reviewed' => $cmoCbmoReviewedLog !== null,
            'hasCmoCbmoReviewed' => $cmoCbmoReviewedLog !== null,
            'has_hr_released' => $hrReleasedLog !== null,
            'hasHrReleased' => $hrReleasedLog !== null,
            'status_history' => $statusHistory,
            'logs' => $statusHistory,
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
            'employment_status' => $resolvedEmployee?->status,
            'employment_status_key' => LeaveType::normalizeEmploymentStatusKey($resolvedEmployee?->status),
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
                'employment_status' => $resolvedEmployee->status,
                'employment_status_key' => LeaveType::normalizeEmploymentStatusKey($resolvedEmployee->status),
                'status' => $resolvedEmployee->status,
            ];
        }

        return $data;
    }
}
