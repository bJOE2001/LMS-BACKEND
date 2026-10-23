<?php

namespace App\Services\Leave;

use App\Models\LeaveApplication;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAccrualHistory;
use App\Models\LeaveType;
use App\Services\CocLedgerService;
use App\Services\WorkScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LeaveCreditService
{
    public const CTO_STANDARD_DAY_HOURS = WorkScheduleService::STANDARD_WORKDAY_HOURS;

    public function __construct(
        public LeaveCalculationService $calculationService,
        public CocLedgerService $cocLedgerService,
        public ?LeaveEligibilityService $eligibilityService = null
    ) {}

    private function eligibilityService(): LeaveEligibilityService
    {
        return $this->eligibilityService ??= app(LeaveEligibilityService::class);
    }

    public function hasLeaveApplicationCtoHoursColumn(): bool
    {
        static $resolved = false;
        static $hasColumn = false;

        if (! $resolved) {
            $hasColumn = Schema::hasColumn('tblLeaveApplications', 'cto_deducted_hours');
            $resolved = true;
        }

        return $hasColumn;
    }

    public function resolveCanonicalLeaveTypeId(?int $leaveTypeId): ?int
    {
        return LeaveType::resolveCanonicalLeaveTypeId($leaveTypeId);
    }

    public function resolveBalanceLookupLeaveTypeIds(int $leaveTypeId): array
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

    public function resolveForcedLeaveTypeId(): ?int
    {
        return LeaveType::resolveForcedLeaveTypeId();
    }

    public function resolveVacationLeaveTypeId(): ?int
    {
        static $resolved = false;
        static $cachedValue = null;

        if ($resolved) {
            return $cachedValue;
        }

        $value = LeaveType::query()
            ->whereRaw('LOWER(name) = ?', ['vacation leave'])
            ->value('id');

        $cachedValue = $value !== null ? (int) $value : null;
        $resolved = true;

        return $cachedValue;
    }

    public function resolveSickLeaveTypeId(): ?int
    {
        static $resolved = false;
        static $cachedValue = null;

        if ($resolved) {
            return $cachedValue;
        }

        $value = LeaveType::query()
            ->whereRaw('LOWER(name) = ?', ['sick leave'])
            ->value('id');

        $cachedValue = $value !== null ? (int) $value : null;
        $resolved = true;

        return $cachedValue;
    }

    public function resolveCtoLeaveTypeId(): ?int
    {
        return $this->calculationService->resolveCtoLeaveTypeId();
    }

    public function resolveTerminalLeaveTypeId(): ?int
    {
        static $resolved = false;
        static $cachedValue = null;

        if ($resolved) {
            return $cachedValue;
        }

        $value = LeaveType::query()
            ->whereRaw('LOWER(name) = ?', ['terminal leave'])
            ->value('id');

        $cachedValue = $value !== null ? (int) $value : null;
        $resolved = true;

        return $cachedValue;
    }

    public function isTerminalLeaveTypeId(?int $leaveTypeId): bool
    {
        if ($leaveTypeId === null || $leaveTypeId <= 0) {
            return false;
        }

        $terminalLeaveTypeId = $this->resolveTerminalLeaveTypeId();
        if ($terminalLeaveTypeId === null) {
            return false;
        }

        $resolvedLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;

        return $resolvedLeaveTypeId === $terminalLeaveTypeId;
    }

    public function resolveLeaveTypePolicyName(?LeaveType $leaveType = null, ?int $leaveTypeId = null): ?string
    {
        static $leaveTypeNameCache = [];

        if ($leaveType instanceof LeaveType) {
            return trim((string) ($leaveType->name ?? '')) ?: null;
        }

        if ($leaveTypeId === null || $leaveTypeId <= 0) {
            return null;
        }

        if (! array_key_exists($leaveTypeId, $leaveTypeNameCache)) {
            $leaveTypeNameCache[$leaveTypeId] = LeaveType::query()
                ->whereKey((int) $leaveTypeId)
                ->value('name');
        }

        $resolvedName = $leaveTypeNameCache[$leaveTypeId];

        return is_string($resolvedName) ? trim($resolvedName) : null;
    }

    public function isCtoLeaveType(?LeaveType $leaveType = null, ?int $leaveTypeId = null): bool
    {
        return $this->calculationService->isCtoLeaveType($leaveType, $leaveTypeId);
    }

    public function isSickLeaveType(?LeaveType $leaveType = null, ?int $leaveTypeId = null): bool
    {
        static $leaveTypeNameCache = [];

        $leaveTypeName = null;
        if ($leaveType instanceof LeaveType) {
            $leaveTypeName = trim((string) ($leaveType->name ?? ''));
        } elseif ($leaveTypeId !== null && $leaveTypeId > 0) {
            if (! array_key_exists($leaveTypeId, $leaveTypeNameCache)) {
                $leaveTypeNameCache[$leaveTypeId] = LeaveType::query()
                    ->whereKey((int) $leaveTypeId)
                    ->value('name');
            }

            $resolvedName = $leaveTypeNameCache[$leaveTypeId];
            $leaveTypeName = is_string($resolvedName) ? trim($resolvedName) : null;
        }

        return strcasecmp((string) ($leaveTypeName ?? ''), 'Sick Leave') === 0;
    }

    public function isVacationLeaveType(?LeaveType $leaveType = null, ?int $leaveTypeId = null): bool
    {
        static $leaveTypeNameCache = [];

        $leaveTypeName = null;
        if ($leaveType instanceof LeaveType) {
            $leaveTypeName = trim((string) ($leaveType->name ?? ''));
        } elseif ($leaveTypeId !== null && $leaveTypeId > 0) {
            if (! array_key_exists($leaveTypeId, $leaveTypeNameCache)) {
                $leaveTypeNameCache[$leaveTypeId] = LeaveType::query()
                    ->whereKey((int) $leaveTypeId)
                    ->value('name');
            }

            $resolvedName = $leaveTypeNameCache[$leaveTypeId];
            $leaveTypeName = is_string($resolvedName) ? trim($resolvedName) : null;
        }

        return strcasecmp((string) ($leaveTypeName ?? ''), 'Vacation Leave') === 0;
    }

    public function isWellnessLeaveType(?LeaveType $leaveType = null, ?int $leaveTypeId = null): bool
    {
        return strcasecmp((string) ($this->resolveLeaveTypePolicyName($leaveType, $leaveTypeId) ?? ''), 'Wellness Leave') === 0;
    }

    public function isSoloParentLeaveType(?LeaveType $leaveType = null, ?int $leaveTypeId = null): bool
    {
        return strcasecmp((string) ($this->resolveLeaveTypePolicyName($leaveType, $leaveTypeId) ?? ''), 'Solo Parent Leave') === 0;
    }

    public function isCalamityLeaveType(?LeaveType $leaveType = null, ?int $leaveTypeId = null): bool
    {
        $normalizedName = LeaveType::normalizeLeaveTypeName(
            $this->resolveLeaveTypePolicyName($leaveType, $leaveTypeId)
        );

        return str_contains($normalizedName, 'CALAMITY')
            || str_contains($normalizedName, 'SPECIAL EMERGENCY');
    }

    public function shouldLeaveTypeDeductForcedLeave(
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

    public function resolveLeaveTypeForcedLeaveDeductionDays(
        LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        float $deductibleDays,
        ?int $forcedLeaveTypeId
    ): float {
        if (! $this->shouldLeaveTypeDeductForcedLeave(
            $leaveType,
            $leaveTypeId,
            $isMonetization,
            $forcedLeaveTypeId
        )) {
            return 0.0;
        }

        return round(max($deductibleDays, 0.0), 3);
    }

    public function shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
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
            || LeaveType::isSpecialPrivilegeType($leaveType, $canonicalLeaveTypeId)
            || $this->isSoloParentLeaveType($leaveType, $canonicalLeaveTypeId)
            || $this->isCalamityLeaveType($leaveType, $canonicalLeaveTypeId);
    }

    public function shouldLeaveTypeDeductVacationLeave(
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

    public function shouldDeductForcedLeaveWithVacation(LeaveApplication $app, ?int $forcedLeaveTypeId): bool
    {
        $leaveType = $app->leaveType;
        if (! $leaveType instanceof LeaveType) {
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

    public function shouldDeductVacationLeaveWithForced(
        LeaveApplication $app,
        ?int $forcedLeaveTypeId,
        ?int $vacationLeaveTypeId
    ): bool {
        $leaveType = $app->leaveType;
        if (! $leaveType instanceof LeaveType) {
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

    public function resolveLeaveTypeVacationLeaveDeductionDays(
        LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        float $requestedTotalDays,
        float $deductibleDays,
        ?int $forcedLeaveTypeId,
        ?int $vacationLeaveTypeId,
        ?float $primaryAvailableDays = null
    ): float {
        $normalizedDeductibleDays = round(max($deductibleDays, 0.0), 3);
        if ($normalizedDeductibleDays <= 0.0) {
            return 0.0;
        }

        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        if (! $this->shouldLeaveTypeDeductVacationLeave(
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

        if (! $this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
            $leaveType,
            $canonicalLeaveTypeId,
            $isMonetization,
            $vacationLeaveTypeId
        )) {
            return 0.0;
        }

        $normalizedRequestedTotalDays = round(max($requestedTotalDays, 0.0), 3);
        $scheduleExcessDays = round(max($normalizedDeductibleDays - $normalizedRequestedTotalDays, 0.0), 3);
        if ($primaryAvailableDays !== null) {
            $shortageDays = round(max($normalizedDeductibleDays - max($primaryAvailableDays, 0.0), 0.0), 3);

            return max($scheduleExcessDays, $shortageDays);
        }

        return $scheduleExcessDays;
    }

    public function resolvePrimaryLeaveTrackedDeductionDays(
        ?LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        float $requestedTotalDays,
        float $deductibleDays,
        ?int $forcedLeaveTypeId,
        ?int $vacationLeaveTypeId
    ): float {
        $normalizedDeductibleDays = round(max($deductibleDays, 0.0), 3);
        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        if (! $isMonetization && $forcedLeaveTypeId !== null && $canonicalLeaveTypeId === $forcedLeaveTypeId) {
            return 0.0;
        }

        if (! $leaveType instanceof LeaveType) {
            return $normalizedDeductibleDays;
        }

        if (! $this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
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

        return round(max($normalizedDeductibleDays - $linkedVacationLeaveDays, 0.0), 3);
    }

    public function shouldLeaveTypeAllowSlVlCrossDeduction(
        LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        bool $allowSlVlCrossDeduction,
        ?int $sickLeaveTypeId,
        ?int $vacationLeaveTypeId
    ): bool {
        if (! $allowSlVlCrossDeduction || $isMonetization || $sickLeaveTypeId === null || $vacationLeaveTypeId === null) {
            return false;
        }

        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;

        return $this->isSickLeaveType($leaveType, $canonicalLeaveTypeId)
            || $this->isVacationLeaveType($leaveType, $canonicalLeaveTypeId)
            || $this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
                $leaveType,
                $canonicalLeaveTypeId,
                $isMonetization,
                $vacationLeaveTypeId
            );
    }

    public function resolveSlVlCrossDeductionTargetLeaveTypeId(
        LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        bool $allowSlVlCrossDeduction,
        ?int $sickLeaveTypeId,
        ?int $vacationLeaveTypeId
    ): ?int {
        if (! $this->shouldLeaveTypeAllowSlVlCrossDeduction(
            $leaveType,
            $leaveTypeId,
            $isMonetization,
            $allowSlVlCrossDeduction,
            $sickLeaveTypeId,
            $vacationLeaveTypeId
        )) {
            return null;
        }

        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;

        if ($this->isSickLeaveType($leaveType, $canonicalLeaveTypeId)) {
            return $vacationLeaveTypeId;
        }

        if ($this->isVacationLeaveType($leaveType, $canonicalLeaveTypeId)) {
            return $sickLeaveTypeId;
        }

        if ($this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
            $leaveType,
            $canonicalLeaveTypeId,
            $isMonetization,
            $vacationLeaveTypeId
        )) {
            return $vacationLeaveTypeId;
        }

        return null;
    }

    public function shouldApplyLinkedVacationLeaveDeduction(
        bool $shouldDeductVacationLeave,
        bool $allowSlVlCrossDeduction,
        float $linkedVacationLeaveRequiredDays
    ): bool {
        if (round(max($linkedVacationLeaveRequiredDays, 0.0), 3) <= 0.0) {
            return false;
        }

        return $shouldDeductVacationLeave || $allowSlVlCrossDeduction;
    }

    public function resolveStoredPrimaryLeaveDeduction(
        LeaveApplication $app,
        ?LeaveType $sourceLeaveType,
        float $fallbackDeductibleDays
    ): float {
        $normalizedFallbackDeductibleDays = round(max($fallbackDeductibleDays, 0.0), 3);
        $leaveTypeId = $this->resolveCanonicalLeaveTypeId((int) $app->leave_type_id)
            ?? (int) $app->leave_type_id;
        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        if (
            ! (bool) $app->is_monetization
            && $forcedLeaveTypeId !== null
            && $leaveTypeId === $forcedLeaveTypeId
        ) {
            return 0.0;
        }

        if (! $sourceLeaveType instanceof LeaveType) {
            return $normalizedFallbackDeductibleDays;
        }

        if ((bool) ($app->allow_sl_vl_cross_deduction ?? false)) {
            $linkedVacationRefundDays = $this->resolveStoredLinkedVacationLeaveDeduction(
                $app,
                $sourceLeaveType,
                $fallbackDeductibleDays
            );
            $linkedSickRefundDays = $this->resolveStoredLinkedSickLeaveDeduction($app);

            return round(max($normalizedFallbackDeductibleDays - $linkedVacationRefundDays - $linkedSickRefundDays, 0.0), 3);
        }

        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        if (! $this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
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

        return round(max($normalizedFallbackDeductibleDays - $linkedVacationRefundDays, 0.0), 3);
    }

    public function resolveStoredLinkedForcedLeaveDeduction(
        LeaveApplication $app,
        ?LeaveType $sourceLeaveType,
        float $fallbackDeductibleDays
    ): float {
        if ($app->linked_forced_leave_deducted_days !== null) {
            return round(max((float) $app->linked_forced_leave_deducted_days, 0.0), 3);
        }

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        if (! $sourceLeaveType || ! $this->shouldLeaveTypeDeductForcedLeave(
            $sourceLeaveType,
            (int) $app->leave_type_id,
            (bool) $app->is_monetization,
            $forcedLeaveTypeId
        )) {
            return 0.0;
        }

        return round(max($fallbackDeductibleDays, 0.0), 3);
    }

    public function resolveStoredLinkedVacationLeaveDeduction(
        LeaveApplication $app,
        ?LeaveType $sourceLeaveType,
        float $fallbackDeductibleDays
    ): float {
        if ($app->linked_vacation_leave_deducted_days !== null) {
            return round(max((float) $app->linked_vacation_leave_deducted_days, 0.0), 3);
        }

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        if (! $sourceLeaveType || ! $this->shouldLeaveTypeDeductVacationLeave(
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
            round((float) ($app->total_days ?? $fallbackDeductibleDays), 3),
            $fallbackDeductibleDays,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );
    }

    public function resolveStoredLinkedSickLeaveDeduction(LeaveApplication $app): float
    {
        if ($app->linked_sick_leave_deducted_days === null) {
            return 0.0;
        }

        return round(max((float) $app->linked_sick_leave_deducted_days, 0.0), 3);
    }

    /**
     * @return array{
     *     primary_deduction_days: float,
     *     linked_vacation_deduction_days: float,
     *     linked_sick_deduction_days: float
     * }|null
     */
    public function resolveStoredSlVlCrossDeductionBreakdown(
        LeaveApplication $app,
        ?LeaveType $sourceLeaveType,
        float $fallbackDeductibleDays
    ): ?array {
        if (
            (bool) $app->is_monetization
            || ! (bool) ($app->allow_sl_vl_cross_deduction ?? false)
            || (
                $app->linked_vacation_leave_deducted_days === null
                && $app->linked_sick_leave_deducted_days === null
            )
        ) {
            return null;
        }

        return [
            'primary_deduction_days' => $this->resolveStoredPrimaryLeaveDeduction(
                $app,
                $sourceLeaveType,
                $fallbackDeductibleDays
            ),
            'linked_vacation_deduction_days' => $this->resolveStoredLinkedVacationLeaveDeduction(
                $app,
                $sourceLeaveType,
                $fallbackDeductibleDays
            ),
            'linked_sick_deduction_days' => $this->resolveStoredLinkedSickLeaveDeduction($app),
        ];
    }

    public function shouldPreferLeaveBalanceRecord(LeaveBalance $candidate, LeaveBalance $current): bool
    {
        $candidateUpdatedAt = $candidate->updated_at?->timestamp ?? 0;
        $currentUpdatedAt = $current->updated_at?->timestamp ?? 0;

        if ($candidateUpdatedAt !== $currentUpdatedAt) {
            return $candidateUpdatedAt > $currentUpdatedAt;
        }

        return (int) $candidate->id > (int) $current->id;
    }

    public function mapLeaveBalancesByCanonicalTypeId(iterable $balances): array
    {
        $mappedBalances = [];

        foreach ($balances as $balance) {
            if (! $balance instanceof LeaveBalance) {
                continue;
            }

            $typeId = (int) ($balance->leave_type_id ?? 0);
            if ($typeId <= 0) {
                continue;
            }

            $canonicalTypeId = $this->resolveCanonicalLeaveTypeId($typeId) ?? $typeId;
            if (
                ! array_key_exists($canonicalTypeId, $mappedBalances)
                || $this->shouldPreferLeaveBalanceRecord($balance, $mappedBalances[$canonicalTypeId])
            ) {
                $mappedBalances[$canonicalTypeId] = $balance;
            }
        }

        return $mappedBalances;
    }

    public function queryLeaveBalancesByEmployeeControlNo(string $employeeControlNo, ?callable $candidatesResolver = null)
    {
        $candidates = $candidatesResolver !== null
            ? $candidatesResolver($employeeControlNo)
            : [$employeeControlNo];

        if ($candidates === []) {
            return LeaveBalance::query()->whereRaw('1 = 0');
        }

        return LeaveBalance::query()->whereIn('employee_control_no', $candidates);
    }

    public function findPreferredEmployeeLeaveBalanceRecord(
        string $employeeControlNo,
        int $leaveTypeId,
        bool $lockForUpdate = false,
        ?callable $candidatesResolver = null
    ): ?LeaveBalance {
        $lookupLeaveTypeIds = $this->resolveBalanceLookupLeaveTypeIds($leaveTypeId);
        if ($lookupLeaveTypeIds === []) {
            return null;
        }

        $query = $this->queryLeaveBalancesByEmployeeControlNo($employeeControlNo, $candidatesResolver)
            ->whereIn('leave_type_id', $lookupLeaveTypeIds)
            ->orderByDesc('updated_at')
            ->orderByDesc('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    public function findAdminEmployeeLeaveBalance(
        int $adminId,
        int $leaveTypeId,
        bool $lockForUpdate = false,
        ?callable $adminControlNoResolver = null,
        ?callable $candidatesResolver = null
    ): ?LeaveBalance {
        $employeeControlNo = $adminControlNoResolver !== null ? $adminControlNoResolver($adminId) : null;
        if ($employeeControlNo === null) {
            return null;
        }

        return $this->findPreferredEmployeeLeaveBalanceRecord($employeeControlNo, $leaveTypeId, $lockForUpdate, $candidatesResolver);
    }

    public function lockEmployeeLeaveBalance(
        string $employeeControlNo,
        int $leaveTypeId,
        ?callable $candidatesResolver = null
    ): ?LeaveBalance {
        return $this->findPreferredEmployeeLeaveBalanceRecord($employeeControlNo, $leaveTypeId, true, $candidatesResolver);
    }

    public function incrementEmployeeLeaveBalance(
        string $employeeControlNo,
        int $leaveTypeId,
        float $daysToAdd,
        ?callable $candidatesResolver = null,
        ?callable $employeeResolver = null
    ): void {
        $leaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        $normalizedDaysToAdd = round(max($daysToAdd, 0.0), 3);
        if ($leaveTypeId <= 0 || $normalizedDaysToAdd <= 0.0) {
            return;
        }

        $balance = $this->lockEmployeeLeaveBalance($employeeControlNo, $leaveTypeId, $candidatesResolver);
        if (! $balance) {
            $employee = $employeeResolver !== null ? $employeeResolver($employeeControlNo) : null;
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

    public function formatEmployeeNameForBalance(?object $employee): ?string
    {
        if (! $employee) {
            return null;
        }

        $surname = trim((string) ($employee->surname ?? ''));
        $firstname = trim((string) ($employee->firstname ?? ''));
        $middlename = trim((string) ($employee->middlename ?? ''));
        $fullName = trim(implode(', ', array_filter([$surname, trim($firstname.' '.$middlename)])));

        return $fullName !== '' ? $fullName : null;
    }

    public function resolveApplicationBalanceOwnerControlNo(
        LeaveApplication $app,
        ?callable $adminControlNoResolver = null
    ): ?string {
        $directControlNo = trim((string) ($app->employee_control_no ?? ''));
        if ($directControlNo !== '') {
            return $directControlNo;
        }

        if ($app->applicant_admin_id && $adminControlNoResolver !== null) {
            return $adminControlNoResolver((int) $app->applicant_admin_id);
        }

        return null;
    }

    public function restoreApplicationBalance(
        LeaveApplication $app,
        int $restoreLeaveTypeId,
        float $daysToRestore,
        ?callable $candidatesResolver = null,
        ?callable $adminControlNoResolver = null
    ): void {
        $restoreLeaveTypeId = $this->resolveCanonicalLeaveTypeId($restoreLeaveTypeId) ?? $restoreLeaveTypeId;
        $restoreAmount = round(max($daysToRestore, 0.0), 3);
        if ($restoreLeaveTypeId <= 0 || $restoreAmount <= 0.0) {
            return;
        }

        if ($app->employee_control_no) {
            $balance = $this->findPreferredEmployeeLeaveBalanceRecord(
                (string) $app->employee_control_no,
                $restoreLeaveTypeId,
                true,
                $candidatesResolver
            );

            if (! $balance) {
                throw new \RuntimeException('HR_RECALL_BALANCE_CONFLICT');
            }

            $balance->increment('balance', $restoreAmount);

            return;
        }

        if ($app->applicant_admin_id) {
            $balance = $this->findAdminEmployeeLeaveBalance(
                (int) $app->applicant_admin_id,
                $restoreLeaveTypeId,
                true,
                $adminControlNoResolver,
                $candidatesResolver
            );

            if (! $balance) {
                throw new \RuntimeException('HR_RECALL_BALANCE_CONFLICT');
            }

            $balance->increment('balance', $restoreAmount);
        }
    }

    public function syncEmployeeCtoBalance(
        string $employeeControlNo,
        bool $lockForUpdate = false,
        ?CarbonImmutable $asOfDate = null,
        ?callable $candidatesResolver = null,
        ?callable $employeeResolver = null
    ): ?float {
        $ctoLeaveTypeId = $this->resolveCtoLeaveTypeId();
        if ($ctoLeaveTypeId === null) {
            return null;
        }

        $candidates = $candidatesResolver !== null
            ? $candidatesResolver($employeeControlNo)
            : [$employeeControlNo];

        if ($candidates === []) {
            return null;
        }

        $effectiveBalance = round(max(
            $this->cocLedgerService->getAvailableDays($employeeControlNo, $ctoLeaveTypeId, $asOfDate),
            0.0
        ), 3);

        $query = LeaveBalance::query()
            ->whereIn('employee_control_no', $candidates)
            ->where('leave_type_id', $ctoLeaveTypeId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $balance = $query->first();

        if (! $balance && $effectiveBalance <= 0) {
            return 0.0;
        }

        if (! $balance) {
            $resolvedEmployee = $employeeResolver !== null ? $employeeResolver($employeeControlNo) : null;
            $canonicalControlNo = trim((string) ($resolvedEmployee?->control_no ?? $employeeControlNo));
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

        if (round((float) $balance->balance, 3) !== $effectiveBalance) {
            $balance->balance = $effectiveBalance;
            if (! $balance->year) {
                $balance->year = (int) now()->year;
            }
            $balance->save();
        }

        return $effectiveBalance;
    }

    public function computeEmployeeCtoAvailableBalance(
        string $employeeControlNo,
        int $ctoLeaveTypeId,
        ?CarbonImmutable $asOfDate = null
    ): float {
        if ($ctoLeaveTypeId <= 0) {
            return 0.0;
        }

        return round(max(
            $this->cocLedgerService->getAvailableDays($employeeControlNo, $ctoLeaveTypeId, $asOfDate),
            0.0
        ), 3);
    }

    public function resolvePendingReservedDaysForTrackedLeaveType(
        string $employeeControlNo,
        int $leaveTypeId,
        ?int $exceptAppId = null,
        ?callable $candidatesResolver = null
    ): float {
        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        if ($canonicalLeaveTypeId <= 0) {
            return 0.0;
        }

        $candidates = $candidatesResolver !== null
            ? $candidatesResolver($employeeControlNo)
            : [$employeeControlNo];

        if ($candidates === []) {
            return 0.0;
        }

        $query = LeaveApplication::query()
            ->with('leaveType')
            ->whereIn('status', [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
            ]);

        if ($exceptAppId !== null && $exceptAppId > 0) {
            $query->where('id', '<>', $exceptAppId);
        }

        $pendingApplications = $query
            ->where(function ($query): void {
                $query->where('is_monetization', true)
                    ->orWhereRaw(
                        'UPPER(LTRIM(RTRIM(COALESCE(pay_mode, ?)))) <> ?',
                        [LeaveApplication::PAY_MODE_WITH_PAY, LeaveApplication::PAY_MODE_WITHOUT_PAY]
                    );
            })
            ->whereIn('employee_control_no', $candidates)
            ->get();

        if ($pendingApplications->isEmpty()) {
            return 0.0;
        }

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        $sickLeaveTypeId = $this->resolveSickLeaveTypeId();
        $pendingReservedDays = 0.0;

        foreach ($pendingApplications as $pendingApplication) {
            $applicationLeaveType = $pendingApplication->leaveType
                ?? LeaveType::query()->find((int) $pendingApplication->leave_type_id);
            if (! $applicationLeaveType instanceof LeaveType) {
                continue;
            }

            $applicationLeaveTypeId = $this->resolveCanonicalLeaveTypeId((int) $pendingApplication->leave_type_id)
                ?? (int) $pendingApplication->leave_type_id;
            $applicationDeductibleDays = $this->calculationService->resolveApplicationDeductibleDays($pendingApplication);
            $applicationTotalDays = round((float) ($pendingApplication->total_days ?? $applicationDeductibleDays), 3);

            $pendingReservedDays += $this->resolvePendingReservedDaysContributionForTrackedLeaveType(
                $pendingApplication,
                $applicationLeaveType,
                $applicationLeaveTypeId,
                $canonicalLeaveTypeId,
                $applicationTotalDays,
                $applicationDeductibleDays,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId,
                $sickLeaveTypeId
            );
        }

        return round(max($pendingReservedDays, 0.0), 3);
    }

    public function resolvePendingReservedDaysContributionForTrackedLeaveType(
        LeaveApplication $app,
        LeaveType $applicationLeaveType,
        int $applicationLeaveTypeId,
        int $targetLeaveTypeId,
        float $applicationTotalDays,
        float $applicationDeductibleDays,
        ?int $forcedLeaveTypeId,
        ?int $vacationLeaveTypeId,
        ?int $sickLeaveTypeId
    ): float {
        if ($targetLeaveTypeId <= 0) {
            return 0.0;
        }

        $primaryReservedDays = $this->resolveStoredPrimaryLeaveDeduction(
            $app,
            $applicationLeaveType,
            $applicationDeductibleDays
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

        if ($sickLeaveTypeId !== null && $targetLeaveTypeId === $sickLeaveTypeId) {
            return $this->resolveStoredLinkedSickLeaveDeduction($app);
        }

        return 0.0;
    }

    public function getApplicationLeaveBalanceSnapshot(
        LeaveApplication $app,
        ?callable $lookupControlNoResolver = null,
        ?callable $candidatesResolver = null,
        ?callable $employeeResolver = null
    ): array {
        static $balanceSnapshotCache = [];
        static $ctoSyncedControlNos = [];

        $lookupControlNo = $lookupControlNoResolver !== null
            ? $lookupControlNoResolver($app)
            : trim((string) ($app->employee_control_no ?? ''));

        if ($lookupControlNo === '') {
            return [];
        }

        if (! array_key_exists($lookupControlNo, $ctoSyncedControlNos)) {
            $this->syncEmployeeCtoBalance($lookupControlNo, false, null, $candidatesResolver, $employeeResolver);
            $ctoSyncedControlNos[$lookupControlNo] = true;
        }

        if (array_key_exists($lookupControlNo, $balanceSnapshotCache)) {
            return $balanceSnapshotCache[$lookupControlNo];
        }

        $candidates = $candidatesResolver !== null
            ? $candidatesResolver($lookupControlNo)
            : [$lookupControlNo];

        if ($candidates === []) {
            $balanceSnapshotCache[$lookupControlNo] = [];

            return [];
        }

        $snapshot = collect($this->mapLeaveBalancesByCanonicalTypeId(LeaveBalance::query()
            ->with('leaveType')
            ->whereIn('employee_control_no', $candidates)
            ->get()))
            ->sortBy(fn (LeaveBalance $balance) => strtolower(trim((string) ($balance->leaveType?->name ?? ''))))
            ->values()
            ->map(function (LeaveBalance $balance) use ($lookupControlNo, $app, $candidatesResolver): array {
                $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId((int) $balance->leave_type_id) ?? (int) $balance->leave_type_id;
                $rawBalance = (float) $balance->balance;
                $pendingReservedDays = $this->resolvePendingReservedDaysForTrackedLeaveType(
                    $lookupControlNo,
                    $canonicalLeaveTypeId,
                    (int) $app->id,
                    $candidatesResolver
                );
                $availableBalance = max(round($rawBalance - $pendingReservedDays, 3), 0.0);

                return [
                    'leave_type_id' => $canonicalLeaveTypeId,
                    'leave_type_name' => LeaveType::canonicalizeLeaveTypeName($balance->leaveType?->name ?? 'Unknown') ?? 'Unknown',
                    'balance' => $availableBalance,
                    'current_balance' => $rawBalance,
                    'available_balance' => $availableBalance,
                    'pending_reserved_days' => $pendingReservedDays,
                    'year' => $balance->year !== null ? (int) $balance->year : null,
                    'updated_at' => $balance->updated_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        $balanceSnapshotCache[$lookupControlNo] = $snapshot;

        return $snapshot;
    }

    public function getFreshApplicationLeaveBalanceSnapshot(
        LeaveApplication $app,
        bool $syncCtoBalance = true,
        ?callable $lookupControlNoResolver = null,
        ?callable $candidatesResolver = null,
        ?callable $employeeResolver = null
    ): array {
        $lookupControlNo = $lookupControlNoResolver !== null
            ? $lookupControlNoResolver($app)
            : trim((string) ($app->employee_control_no ?? ''));

        if ($lookupControlNo === '') {
            return [];
        }

        if ($syncCtoBalance) {
            $this->syncEmployeeCtoBalance($lookupControlNo, false, null, $candidatesResolver, $employeeResolver);
        }

        $candidates = $candidatesResolver !== null
            ? $candidatesResolver($lookupControlNo)
            : [$lookupControlNo];

        if ($candidates === []) {
            return [];
        }

        return collect($this->mapLeaveBalancesByCanonicalTypeId(LeaveBalance::query()
            ->with('leaveType')
            ->whereIn('employee_control_no', $candidates)
            ->get()))
            ->sortBy(fn (LeaveBalance $balance) => strtolower(trim((string) ($balance->leaveType?->name ?? ''))))
            ->values()
            ->map(fn (LeaveBalance $balance) => [
                'leave_type_id' => $this->resolveCanonicalLeaveTypeId((int) $balance->leave_type_id) ?? (int) $balance->leave_type_id,
                'leave_type_name' => LeaveType::canonicalizeLeaveTypeName($balance->leaveType?->name ?? 'Unknown') ?? 'Unknown',
                'balance' => (float) $balance->balance,
                'year' => $balance->year !== null ? (int) $balance->year : null,
                'updated_at' => $balance->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function refundApplicationTrackedDeductions(
        LeaveApplication $app,
        ?LeaveType $sourceLeaveType,
        float $sourceDeductibleDays,
        string $balanceConflictError,
        ?callable $adminControlNoResolver = null,
        ?callable $candidatesResolver = null,
        ?callable $monetizationComponentsResolver = null,
        ?callable $employeeResolver = null
    ): void {
        $employeeControlNo = $this->resolveApplicationBalanceOwnerControlNo($app, $adminControlNoResolver);
        if ($employeeControlNo === null) {
            throw new \RuntimeException($balanceConflictError);
        }

        if ((bool) $app->is_monetization) {
            $components = $monetizationComponentsResolver !== null
                ? $monetizationComponentsResolver($app, $sourceLeaveType)
                : [];

            foreach ($components as $component) {
                $this->incrementEmployeeLeaveBalance(
                    $employeeControlNo,
                    (int) $component['leave_type_id'],
                    (float) $component['days'],
                    $candidatesResolver,
                    $employeeResolver
                );
            }

            return;
        }

        $primaryRefundDays = $this->resolveStoredPrimaryLeaveDeduction(
            $app,
            $sourceLeaveType,
            $sourceDeductibleDays
        );
        if ((int) $app->leave_type_id > 0 && $primaryRefundDays > 0.0) {
            $this->incrementEmployeeLeaveBalance($employeeControlNo, (int) $app->leave_type_id, $primaryRefundDays, $candidatesResolver, $employeeResolver);
        }

        $forcedLeaveTypeId = $this->resolveForcedLeaveTypeId();
        $linkedForcedRefundDays = $this->resolveStoredLinkedForcedLeaveDeduction($app, $sourceLeaveType, $sourceDeductibleDays);
        if ($forcedLeaveTypeId !== null && $linkedForcedRefundDays > 0.0) {
            $this->incrementEmployeeLeaveBalance($employeeControlNo, $forcedLeaveTypeId, $linkedForcedRefundDays, $candidatesResolver, $employeeResolver);
        }

        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        $linkedVacationRefundDays = $this->resolveStoredLinkedVacationLeaveDeduction($app, $sourceLeaveType, $sourceDeductibleDays);
        if ($vacationLeaveTypeId !== null && $linkedVacationRefundDays > 0.0) {
            $this->incrementEmployeeLeaveBalance($employeeControlNo, $vacationLeaveTypeId, $linkedVacationRefundDays, $candidatesResolver, $employeeResolver);
        }

        $sickLeaveTypeId = $this->resolveSickLeaveTypeId();
        $linkedSickRefundDays = $this->resolveStoredLinkedSickLeaveDeduction($app);
        if ($sickLeaveTypeId !== null && $linkedSickRefundDays > 0.0) {
            $this->incrementEmployeeLeaveBalance($employeeControlNo, $sickLeaveTypeId, $linkedSickRefundDays, $candidatesResolver, $employeeResolver);
        }
    }

    public function deductApplicationTrackedBalances(
        LeaveApplication $app,
        int $primaryLeaveTypeId,
        float $daysToDeduct,
        ?float $requestedTotalDays,
        bool $isCtoDeduction,
        bool $shouldDeductForcedLeave,
        ?int $forcedLeaveTypeId,
        bool $shouldDeductVacationLeave,
        ?int $vacationLeaveTypeId,
        bool $allowSlVlCrossDeduction,
        ?int $sickLeaveTypeId,
        string $balanceConflictError,
        bool $preferStoredSlVlCrossDeductionBreakdown = false,
        ?callable $adminControlNoResolver = null,
        ?callable $candidatesResolver = null,
        ?callable $monetizationComponentsResolver = null,
        ?callable $employeeResolver = null,
        ?callable $coverageWeightsResolver = null
    ): array {
        $employeeControlNo = $this->resolveApplicationBalanceOwnerControlNo($app, $adminControlNoResolver);
        if ($employeeControlNo === null) {
            throw new \RuntimeException($balanceConflictError);
        }

        $primaryLeaveTypeId = $this->resolveCanonicalLeaveTypeId($primaryLeaveTypeId) ?? $primaryLeaveTypeId;
        $normalizedDaysToDeduct = round(max($daysToDeduct, 0.0), 3);
        if ($primaryLeaveTypeId <= 0 || $normalizedDaysToDeduct <= 0.0) {
            return [
                'linked_forced_leave_deducted_days' => 0.0,
                'linked_vacation_leave_deducted_days' => 0.0,
                'linked_sick_leave_deducted_days' => 0.0,
            ];
        }
        $isForcedLeaveApplication = ! (bool) $app->is_monetization
            && $forcedLeaveTypeId !== null
            && $primaryLeaveTypeId === $forcedLeaveTypeId;

        if ((bool) $app->is_monetization) {
            $components = $monetizationComponentsResolver !== null
                ? $monetizationComponentsResolver($app)
                : [];

            foreach ($components as $component) {
                $balance = $this->lockEmployeeLeaveBalance($employeeControlNo, (int) $component['leave_type_id'], $candidatesResolver);
                $componentDays = round(max((float) $component['days'], 0.0), 3);
                if (! $balance || (float) $balance->balance + 1e-9 < $componentDays) {
                    throw new \RuntimeException($balanceConflictError);
                }

                if ($componentDays > 0.0) {
                    $balance->decrement('balance', $componentDays);
                }
            }

            return [
                'linked_forced_leave_deducted_days' => 0.0,
                'linked_vacation_leave_deducted_days' => 0.0,
                'linked_sick_leave_deducted_days' => 0.0,
            ];
        }

        $resolvedRequestedTotalDays = round(
            max((float) ($requestedTotalDays ?? $app->total_days ?? $normalizedDaysToDeduct), 0.0),
            2
        );
        $primaryLeaveType = $app->leaveType;
        if (
            ! $primaryLeaveType instanceof LeaveType
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
        $usesVacationLeaveTopUpForScheduleExcess = $shouldDeductVacationLeave
            && $vacationLeaveTypeId !== null
            && $primaryLeaveType instanceof LeaveType
            && $this->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
                $primaryLeaveType,
                $primaryLeaveTypeId,
                (bool) $app->is_monetization,
                $vacationLeaveTypeId
            );

        if ($isCtoDeduction) {
            $this->syncEmployeeCtoBalance($employeeControlNo, true, null, $candidatesResolver, $employeeResolver);
        }

        $primaryBalance = $this->lockEmployeeLeaveBalance($employeeControlNo, $primaryLeaveTypeId, $candidatesResolver);
        $vacationBalance = null;
        $sickBalance = null;
        $linkedSickLeaveRequiredDays = 0.0;
        $storedSlVlCrossDeductionBreakdown = $preferStoredSlVlCrossDeductionBreakdown
            ? $this->resolveStoredSlVlCrossDeductionBreakdown($app, $primaryLeaveType, $normalizedDaysToDeduct)
            : null;

        if ($usesVacationLeaveTopUpForScheduleExcess) {
            $primaryAvailable = $primaryBalance ? max((float) $primaryBalance->balance, 0.0) : 0.0;
            $primaryDaysToDeduct = round(min($normalizedDaysToDeduct, $primaryAvailable), 3);
            $linkedVacationLeaveRequiredDays = round(max($normalizedDaysToDeduct - $primaryDaysToDeduct, 0.0), 3);
        } elseif (is_array($storedSlVlCrossDeductionBreakdown)) {
            $primaryDaysToDeduct = (float) ($storedSlVlCrossDeductionBreakdown['primary_deduction_days'] ?? $primaryDaysToDeduct);
            $linkedVacationLeaveRequiredDays = (float) ($storedSlVlCrossDeductionBreakdown['linked_vacation_deduction_days'] ?? 0.0);
            $linkedSickLeaveRequiredDays = (float) ($storedSlVlCrossDeductionBreakdown['linked_sick_deduction_days'] ?? 0.0);
        } elseif ($primaryLeaveType instanceof LeaveType && $allowSlVlCrossDeduction) {
            $linkedPrimaryAvailable = $primaryBalance ? max((float) $primaryBalance->balance, 0.0) : 0.0;
            $linkedLeaveTypeId = $this->resolveSlVlCrossDeductionTargetLeaveTypeId(
                $primaryLeaveType,
                $primaryLeaveTypeId,
                (bool) $app->is_monetization,
                $allowSlVlCrossDeduction,
                $sickLeaveTypeId,
                $vacationLeaveTypeId
            );
            $linkedAvailable = 0.0;
            if ($linkedLeaveTypeId !== null) {
                if ($vacationLeaveTypeId !== null && $linkedLeaveTypeId === $vacationLeaveTypeId) {
                    $vacationBalance = $this->lockEmployeeLeaveBalance($employeeControlNo, $vacationLeaveTypeId, $candidatesResolver);
                    $linkedAvailable = $vacationBalance ? max((float) $vacationBalance->balance, 0.0) : 0.0;
                } elseif ($sickLeaveTypeId !== null && $linkedLeaveTypeId === $sickLeaveTypeId) {
                    $sickBalance = $this->lockEmployeeLeaveBalance($employeeControlNo, $sickLeaveTypeId, $candidatesResolver);
                    $linkedAvailable = $sickBalance ? max((float) $sickBalance->balance, 0.0) : 0.0;
                }
            }

            $crossDeductionBreakdown = $this->resolveSlVlCrossDeductionBreakdown(
                $primaryLeaveType,
                $primaryLeaveTypeId,
                (bool) $app->is_monetization,
                $normalizedDaysToDeduct,
                $allowSlVlCrossDeduction,
                $sickLeaveTypeId,
                $vacationLeaveTypeId,
                $linkedPrimaryAvailable,
                $linkedAvailable,
                $app->resolvedSelectedDates(),
                is_array($app->selected_date_pay_status) ? $app->selected_date_pay_status : null,
                is_array($app->selected_date_coverage) ? $app->selected_date_coverage : null,
                $resolvedRequestedTotalDays,
                $employeeControlNo,
                $coverageWeightsResolver
            );
            $primaryDaysToDeduct = (float) ($crossDeductionBreakdown['primary_deduction_days'] ?? $primaryDaysToDeduct);
            $linkedVacationLeaveRequiredDays = (float) ($crossDeductionBreakdown['linked_vacation_deduction_days'] ?? 0.0);
            $linkedSickLeaveRequiredDays = (float) ($crossDeductionBreakdown['linked_sick_deduction_days'] ?? 0.0);
        }

        if ($isForcedLeaveApplication) {
            $forcedAvailable = $primaryBalance ? max((float) $primaryBalance->balance, 0.0) : 0.0;
            if ($forcedAvailable <= 0.0) {
                throw new \RuntimeException($balanceConflictError);
            }

            $linkedVacationLeaveRequiredDays = $normalizedDaysToDeduct;
            $primaryDaysToDeduct = 0.0;

            if ($vacationLeaveTypeId === null) {
                throw new \RuntimeException($balanceConflictError);
            }

            $vacationBalance ??= $this->lockEmployeeLeaveBalance($employeeControlNo, $vacationLeaveTypeId, $candidatesResolver);
            $availableVacationBalance = $vacationBalance ? max((float) $vacationBalance->balance, 0.0) : 0.0;
            if ($availableVacationBalance <= 0.0) {
                throw new \RuntimeException($balanceConflictError);
            }
        }

        if (
            ! $usesVacationLeaveTopUpForScheduleExcess
            && $primaryDaysToDeduct > 0.0
            && (
                ! $primaryBalance
                || (float) $primaryBalance->balance + 1e-9 < $primaryDaysToDeduct
            )
        ) {
            throw new \RuntimeException($balanceConflictError);
        }

        if ($isCtoDeduction) {
            return [
                'linked_forced_leave_deducted_days' => 0.0,
                'linked_vacation_leave_deducted_days' => 0.0,
                'linked_sick_leave_deducted_days' => 0.0,
            ];
        }

        if ($primaryBalance && $primaryDaysToDeduct > 0.0) {
            $primaryBalance->decrement('balance', $primaryDaysToDeduct);
        }

        $linkedForcedLeaveDeductedDays = 0.0;
        if (($shouldDeductForcedLeave || $isForcedLeaveApplication) && $forcedLeaveTypeId !== null) {
            $forcedBalance = $isForcedLeaveApplication && $primaryLeaveTypeId === $forcedLeaveTypeId
                ? $primaryBalance
                : $this->lockEmployeeLeaveBalance($employeeControlNo, $forcedLeaveTypeId, $candidatesResolver);
            $forcedAvailable = $forcedBalance ? max((float) $forcedBalance->balance, 0.0) : 0.0;
            $forcedLeaveDeductionBaseDays = $isForcedLeaveApplication
                ? $normalizedDaysToDeduct
                : $primaryDaysToDeduct;
            $linkedForcedLeaveDeductedDays = round(min(max($forcedLeaveDeductionBaseDays, 0.0), $forcedAvailable), 3);

            if ($forcedBalance && $linkedForcedLeaveDeductedDays > 0.0) {
                $forcedBalance->decrement('balance', $linkedForcedLeaveDeductedDays);
            }
        }

        $linkedVacationLeaveDeductedDays = 0.0;
        if (
            $this->shouldApplyLinkedVacationLeaveDeduction(
                $shouldDeductVacationLeave,
                $allowSlVlCrossDeduction,
                $linkedVacationLeaveRequiredDays
            )
            && $vacationLeaveTypeId !== null
        ) {
            $vacationBalance ??= $this->lockEmployeeLeaveBalance($employeeControlNo, $vacationLeaveTypeId, $candidatesResolver);
            if (! $vacationBalance || (float) $vacationBalance->balance < $linkedVacationLeaveRequiredDays) {
                throw new \RuntimeException($balanceConflictError);
            }

            $vacationBalance->decrement('balance', $linkedVacationLeaveRequiredDays);
            $linkedVacationLeaveDeductedDays = $linkedVacationLeaveRequiredDays;
        }

        $linkedSickLeaveDeductedDays = 0.0;
        if ($sickLeaveTypeId !== null && $linkedSickLeaveRequiredDays > 0.0) {
            $sickBalance ??= $this->lockEmployeeLeaveBalance($employeeControlNo, $sickLeaveTypeId, $candidatesResolver);
            if (! $sickBalance || (float) $sickBalance->balance < $linkedSickLeaveRequiredDays) {
                throw new \RuntimeException($balanceConflictError);
            }

            $sickBalance->decrement('balance', $linkedSickLeaveRequiredDays);
            $linkedSickLeaveDeductedDays = $linkedSickLeaveRequiredDays;
        }

        return [
            'linked_forced_leave_deducted_days' => $linkedForcedLeaveDeductedDays,
            'linked_vacation_leave_deducted_days' => $linkedVacationLeaveDeductedDays,
            'linked_sick_leave_deducted_days' => $linkedSickLeaveDeductedDays,
        ];
    }

    public function resolveAvailableSlVlCrossDeductionBalanceForControlNo(
        string $employeeControlNo,
        ?LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        bool $allowSlVlCrossDeduction,
        ?callable $candidatesResolver = null
    ): float {
        if (! $leaveType instanceof LeaveType) {
            return 0.0;
        }

        $sickLeaveTypeId = $this->resolveSickLeaveTypeId();
        $vacationLeaveTypeId = $this->resolveVacationLeaveTypeId();
        $linkedLeaveTypeId = $this->resolveSlVlCrossDeductionTargetLeaveTypeId(
            $leaveType,
            $leaveTypeId,
            $isMonetization,
            $allowSlVlCrossDeduction,
            $sickLeaveTypeId,
            $vacationLeaveTypeId
        );
        if ($linkedLeaveTypeId === null) {
            return 0.0;
        }

        $balance = $this->findPreferredEmployeeLeaveBalanceRecord($employeeControlNo, $linkedLeaveTypeId, false, $candidatesResolver);

        return round(max((float) ($balance?->balance ?? 0.0), 0.0), 3);
    }

    /**
     * @return array{primary_deduction_days:float,linked_vacation_deduction_days:float,linked_sick_deduction_days:float}
     */
    public function resolveSlVlCrossDeductionBreakdown(
        ?LeaveType $leaveType,
        int $leaveTypeId,
        bool $isMonetization,
        float $deductibleDays,
        bool $allowSlVlCrossDeduction,
        ?int $sickLeaveTypeId,
        ?int $vacationLeaveTypeId,
        float $primaryAvailableDays,
        float $linkedAvailableDays,
        ?array $selectedDates = null,
        ?array $selectedDatePayStatus = null,
        ?array $selectedDateCoverage = null,
        ?float $requestedTotalDays = null,
        ?string $employeeControlNo = null,
        ?callable $coverageWeightsResolver = null
    ): array {
        $normalizedDeductibleDays = round(max($deductibleDays, 0.0), 3);
        $baseBreakdown = [
            'primary_deduction_days' => $normalizedDeductibleDays,
            'linked_vacation_deduction_days' => 0.0,
            'linked_sick_deduction_days' => 0.0,
        ];

        if (! $leaveType instanceof LeaveType || $normalizedDeductibleDays <= 0.0) {
            return $baseBreakdown;
        }

        $linkedLeaveTypeId = $this->resolveSlVlCrossDeductionTargetLeaveTypeId(
            $leaveType,
            $leaveTypeId,
            $isMonetization,
            $allowSlVlCrossDeduction,
            $sickLeaveTypeId,
            $vacationLeaveTypeId
        );
        if ($linkedLeaveTypeId === null) {
            return $baseBreakdown;
        }

        $normalizedPrimaryAvailableDays = round(max($primaryAvailableDays, 0.0), 3);
        $normalizedLinkedAvailableDays = round(max($linkedAvailableDays, 0.0), 3);
        $dateWeightBreakdown = $this->resolveSlVlCrossDeductionDateWeightBreakdown(
            $selectedDates,
            $selectedDatePayStatus,
            $selectedDateCoverage,
            (float) ($requestedTotalDays ?? $normalizedDeductibleDays),
            $normalizedDeductibleDays,
            $normalizedPrimaryAvailableDays,
            $employeeControlNo,
            $coverageWeightsResolver
        );
        if ($dateWeightBreakdown !== null) {
            $primaryDeductionDays = round(max((float) ($dateWeightBreakdown['primary_deduction_days'] ?? 0.0), 0.0), 3);
            $linkedDeductionDays = round(max((float) ($dateWeightBreakdown['linked_deduction_days'] ?? 0.0), 0.0), 3);
        } else {
            $primaryDeductionDays = round(min($normalizedDeductibleDays, $normalizedPrimaryAvailableDays), 3);
            $linkedDeductionDays = round(max($normalizedDeductibleDays - $primaryDeductionDays, 0.0), 3);
        }

        $breakdown = [
            'primary_deduction_days' => $primaryDeductionDays,
            'linked_vacation_deduction_days' => 0.0,
            'linked_sick_deduction_days' => 0.0,
        ];

        if ($linkedDeductionDays <= 0.0 || $linkedDeductionDays > $normalizedLinkedAvailableDays + 1e-9) {
            return $breakdown;
        }

        if ($linkedLeaveTypeId === $vacationLeaveTypeId) {
            $breakdown['linked_vacation_deduction_days'] = $linkedDeductionDays;
        } elseif ($linkedLeaveTypeId === $sickLeaveTypeId) {
            $breakdown['linked_sick_deduction_days'] = $linkedDeductionDays;
        }

        return $breakdown;
    }

    public function resolveSlVlCrossDeductionDateWeightBreakdown(
        ?array $selectedDates,
        ?array $selectedDatePayStatus,
        ?array $selectedDateCoverage,
        float $requestedTotalDays,
        float $deductibleDays,
        float $primaryAvailableDays,
        ?string $employeeControlNo = null,
        ?callable $coverageWeightsResolver = null
    ): ?array {
        if (! is_array($selectedDates) || $selectedDates === []) {
            return null;
        }

        $normalizedDeductibleDays = round(max($deductibleDays, 0.0), 3);
        if ($normalizedDeductibleDays <= 0.0) {
            return [
                'primary_deduction_days' => 0.0,
                'linked_deduction_days' => 0.0,
            ];
        }

        $normalizedRequestedTotalDays = round(max($requestedTotalDays, 0.0), 3);
        $compactedPayStatus = $this->calculationService->compactSelectedDatePayStatusMap(
            is_array($selectedDatePayStatus) ? $selectedDatePayStatus : null,
            $selectedDates,
            LeaveApplication::PAY_MODE_WITH_PAY
        );
        $normalizedEmployeeControlNo = trim((string) ($employeeControlNo ?? ''));

        if ($coverageWeightsResolver !== null) {
            $coverageWeights = $coverageWeightsResolver(
                $selectedDates,
                is_array($selectedDateCoverage) ? $selectedDateCoverage : null,
                $normalizedRequestedTotalDays,
                $normalizedEmployeeControlNo !== '' ? $normalizedEmployeeControlNo : null
            );
        } else {
            $coverageMap = is_array($selectedDateCoverage) ? $selectedDateCoverage : [];
            $defaultWholeDayWeight = 1.0;
            $defaultHalfDayWeight = 0.5;
            $defaultCoverageWeight = $defaultWholeDayWeight;
            $dateCount = count($selectedDates);
            if ($dateCount > 0) {
                $halfMatch = abs(($dateCount * $defaultHalfDayWeight) - $normalizedRequestedTotalDays) < 0.00001;
                $wholeMatch = abs(((float) $dateCount) - $normalizedRequestedTotalDays) < 0.00001;
                if ($halfMatch) {
                    $defaultCoverageWeight = $defaultHalfDayWeight;
                } elseif (! $wholeMatch) {
                    $defaultCoverageWeight = round(max(min(
                        $normalizedRequestedTotalDays / $dateCount,
                        $defaultWholeDayWeight
                    ), $defaultHalfDayWeight), 3);
                }
            }

            $coverageWeights = [];
            foreach ($selectedDates as $rawDate) {
                $dateKey = $this->calculationService->normalizeDateKey($rawDate) ?? trim((string) $rawDate);
                if ($dateKey === '') {
                    continue;
                }

                $coverage = strtolower(trim((string) ($coverageMap[$dateKey] ?? '')));
                if ($coverage === 'half') {
                    $coverageWeights[$dateKey] = $defaultHalfDayWeight;

                    continue;
                }

                $coverageWeights[$dateKey] = $defaultCoverageWeight;
            }
        }

        $sortedDates = array_values(array_filter(
            $selectedDates,
            static fn (mixed $date): bool => $date !== null && trim((string) $date) !== ''
        ));

        usort($sortedDates, static function (mixed $leftDate, mixed $rightDate): int {
            $leftTimestamp = strtotime((string) $leftDate);
            $rightTimestamp = strtotime((string) $rightDate);

            if ($leftTimestamp !== false && $rightTimestamp !== false && $leftTimestamp !== $rightTimestamp) {
                return $leftTimestamp <=> $rightTimestamp;
            }

            return strcmp((string) $leftDate, (string) $rightDate);
        });

        $primaryBudget = round(max($primaryAvailableDays, 0.0), 3);
        $accumulatedPrimaryDeduction = 0.0;
        $accumulatedLinkedDeduction = 0.0;

        foreach ($sortedDates as $rawDate) {
            $dateKey = $this->calculationService->normalizeDateKey($rawDate) ?? trim((string) $rawDate);
            $payStatus = strtoupper(trim((string) ($compactedPayStatus[$dateKey] ?? LeaveApplication::PAY_MODE_WITH_PAY)));
            if ($payStatus === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                continue;
            }

            $dateWeight = round(max((float) ($coverageWeights[$dateKey] ?? 1.0), 0.0), 3);
            if ($dateWeight <= 0.0) {
                continue;
            }

            if ($primaryBudget >= $dateWeight - 1e-9) {
                $primaryBudget = round(max($primaryBudget - $dateWeight, 0.0), 3);
                $accumulatedPrimaryDeduction = round($accumulatedPrimaryDeduction + $dateWeight, 3);
            } else {
                $accumulatedLinkedDeduction = round($accumulatedLinkedDeduction + $dateWeight, 3);
            }
        }

        $totalComputedDeduction = round($accumulatedPrimaryDeduction + $accumulatedLinkedDeduction, 3);
        if ($totalComputedDeduction > $normalizedDeductibleDays) {
            $excess = round($totalComputedDeduction - $normalizedDeductibleDays, 3);
            $trimmedLinked = min($accumulatedLinkedDeduction, $excess);
            $accumulatedLinkedDeduction = round($accumulatedLinkedDeduction - $trimmedLinked, 3);
            $remainingExcess = round($excess - $trimmedLinked, 3);
            if ($remainingExcess > 0.0) {
                $accumulatedPrimaryDeduction = round(max($accumulatedPrimaryDeduction - $remainingExcess, 0.0), 3);
            }
        }

        return [
            'primary_deduction_days' => $accumulatedPrimaryDeduction,
            'linked_deduction_days' => $accumulatedLinkedDeduction,
        ];
    }

    public function applicationDeductsEmployeeBalance(
        bool $isMonetization,
        ?LeaveType $leaveType,
        mixed $payMode
    ): bool {
        if ($isMonetization) {
            return true;
        }

        if ($this->calculationService->normalizePayMode($payMode, false) === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
            return false;
        }

        return (bool) ($leaveType?->is_credit_based);
    }

    public function buildInsufficientPrimaryLeaveBalanceResponse(
        LeaveType $leaveType,
        float $availableBalance,
        float $requiredBalanceDays,
        bool $requiresVacationLeaveTopUp = false
    ): JsonResponse {
        $formattedAvailable = LeaveWorkflowService::formatDays($availableBalance);
        $formattedRequired = LeaveWorkflowService::formatDays($requiredBalanceDays);
        $message = $requiresVacationLeaveTopUp
            ? "Insufficient {$leaveType->name} balance. Available {$leaveType->name} is "
                .$formattedAvailable
                .', but '
                .$formattedRequired
                .' is required before Vacation Leave can cover the schedule-based excess deduction.'
            : "Insufficient {$leaveType->name} balance. Available {$leaveType->name} is "
                .$formattedAvailable
                .', but '
                .$formattedRequired
                .' is required.';

        return response()->json([
            'message' => $message,
            'errors' => [
                'leave_type_id' => [$message],
                'available_leave_balance' => [
                    'Available '
                    .$leaveType->name
                    .' is '
                    .$formattedAvailable
                    .', but '
                    .$formattedRequired
                    .' is required.',
                ],
            ],
            'available_balance' => $availableBalance,
            'required_balance_days' => $requiredBalanceDays,
        ], 422);
    }

    public function buildInsufficientVacationLeaveTopUpResponse(
        LeaveType $leaveType,
        float $availableVacationBalance,
        float $requiredVacationLeaveDays
    ): JsonResponse {
        $formattedAvailable = LeaveWorkflowService::formatDays($availableVacationBalance);
        $formattedRequired = LeaveWorkflowService::formatDays($requiredVacationLeaveDays);
        $message = 'Insufficient Vacation Leave balance to cover the schedule-based excess deduction for '
            .$leaveType->name
            .'.';

        return response()->json([
            'message' => $message,
            'errors' => [
                'leave_type_id' => [
                    $leaveType->name
                    .' needs Vacation Leave to cover the schedule-based excess deduction.',
                ],
                'vacation_leave_balance' => [
                    'Available Vacation Leave is '
                    .$formattedAvailable
                    .', but '
                    .$formattedRequired
                    .' is required.',
                ],
            ],
            'available_vacation_leave_days' => $availableVacationBalance,
            'required_vacation_leave_days' => $requiredVacationLeaveDays,
        ], 422);
    }

    public function buildInsufficientLinkedVacationLeaveBalanceResponse(
        LeaveType $leaveType,
        float $availableVacationBalance,
        float $requiredVacationLeaveDays
    ): JsonResponse {
        $isForcedLeave = strcasecmp(trim((string) $leaveType->name), 'Mandatory / Forced Leave') === 0;
        $message = $isForcedLeave
            ? 'Insufficient Vacation Leave balance to apply Mandatory / Forced Leave.'
            : 'Insufficient Vacation Leave balance for '.$leaveType->name.'.';
        $leaveTypeError = $isForcedLeave
            ? 'Mandatory / Forced Leave requires enough Vacation Leave balance.'
            : $leaveType->name.' requires matching Vacation Leave balance.';

        $formattedAvailable = LeaveWorkflowService::formatDays($availableVacationBalance);
        $formattedRequired = LeaveWorkflowService::formatDays($requiredVacationLeaveDays);

        return response()->json([
            'message' => $message,
            'errors' => [
                'leave_type_id' => [$leaveTypeError],
                'vacation_leave_balance' => [
                    'Available Vacation Leave is '
                    .$formattedAvailable
                    .', but '
                    .$formattedRequired
                    .' is required.',
                ],
            ],
            'available_vacation_leave_days' => $availableVacationBalance,
            'required_vacation_leave_days' => $requiredVacationLeaveDays,
        ], 422);
    }

    public function buildRequiredForcedLeaveVacationBalanceResponse(float $availableVacationBalance): JsonResponse
    {
        return response()->json([
            'message' => 'Mandatory / Forced Leave requires available Vacation Leave balance.',
            'errors' => [
                'leave_type_id' => ['Mandatory / Forced Leave requires available Vacation Leave balance.'],
                'vacation_leave_balance' => ['Available Vacation Leave must be greater than 0.'],
            ],
            'available_vacation_leave_days' => round(max($availableVacationBalance, 0.0), 3),
        ], 422);
    }

    public function buildInsufficientCtoBalanceResponse(array $eligibility): JsonResponse
    {
        $availableBalance = round(max((float) ($eligibility['available_balance'] ?? 0.0), 0.0), 3);
        $requiredBalance = round(max((float) ($eligibility['required_balance_days'] ?? 0.0), 0.0), 3);
        $availableBalanceHours = round(max((float) ($eligibility['available_balance_hours'] ?? 0.0), 0.0), 2);
        $requiredBalanceHours = round(max((float) ($eligibility['required_balance_hours'] ?? 0.0), 0.0), 2);
        $message = 'Insufficient CTO balance. Available CTO is '
            .number_format($availableBalanceHours, 2)
            .' hour(s)'
            .($availableBalance > 0 ? ' ('.LeaveWorkflowService::formatDays($availableBalance).' display balance)' : '')
            .', but this request needs '
            .number_format($requiredBalanceHours, 2)
            .' hour(s). CTO applications must stay with pay and can only use available, unexpired CTO credits.';

        return response()->json([
            'message' => $message,
            'errors' => [
                'leave_type_id' => [
                    $message,
                ],
                'available_cto_balance' => [
                    'Available CTO balance is '
                    .LeaveWorkflowService::formatDays($availableBalance)
                    .($availableBalanceHours > 0 ? ' ('.number_format($availableBalanceHours, 2).' hour(s))' : '')
                    .', but '
                    .LeaveWorkflowService::formatDays($requiredBalance)
                    .($requiredBalanceHours > 0 ? ' ('.number_format($requiredBalanceHours, 2).' hour(s))' : '')
                    .' is required.',
                ],
            ],
            'available_cto_balance' => $availableBalance,
            'required_cto_balance' => $requiredBalance,
            'available_cto_balance_hours' => $availableBalanceHours,
            'required_cto_balance_hours' => $requiredBalanceHours,
        ], 422);
    }

    public function validateApprovalLeaveBalances(
        LeaveApplication $app,
        ?LeaveType $leaveType,
        float $daysToDeduct,
        bool $isForcedLeaveApplication,
        bool $usesVacationLeaveTopUpForScheduleExcess,
        float &$requiredPrimaryLeaveDays,
        float &$requiredVacationLeaveDays,
        float $requiredLinkedSickLeaveDays,
        ?int $vacationLeaveTypeId,
        ?callable $validateAdminMonetization = null
    ): ?JsonResponse {
        if ($app->employee_control_no) {
            $deductTypeId = $this->resolveCanonicalLeaveTypeId((int) $app->leave_type_id)
                ?? (int) $app->leave_type_id;

            $balance = $this->findPreferredEmployeeLeaveBalanceRecord(
                (string) $app->employee_control_no,
                $deductTypeId
            );
            $currentBalance = $balance ? (float) $balance->balance : 0.0;
            if ($isForcedLeaveApplication) {
                $requiredVacationLeaveDays = round(max($daysToDeduct, 0.0), 3);
                $requiredPrimaryLeaveDays = 0.0;
            } elseif ($usesVacationLeaveTopUpForScheduleExcess) {
                $requiredVacationLeaveDays = round(max($daysToDeduct - max($currentBalance, 0.0), 0.0), 3);
                $requiredPrimaryLeaveDays = round(max($daysToDeduct - $requiredVacationLeaveDays, 0.0), 3);
            }

            if (
                ! $usesVacationLeaveTopUpForScheduleExcess
                && $requiredPrimaryLeaveDays > 0.0
                && (! $balance || (float) $balance->balance < $requiredPrimaryLeaveDays)
            ) {
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
                        .LeaveWorkflowService::formatDays($currentBalance)
                        .', Requested: '
                        .LeaveWorkflowService::formatDays($requiredPrimaryLeaveDays)
                    .'.',
                ], 422);
            }

            if ($isForcedLeaveApplication && $vacationLeaveTypeId === null) {
                return $this->buildRequiredForcedLeaveVacationBalanceResponse(0.0);
            }

            if (($requiredVacationLeaveDays > 0.0 || $isForcedLeaveApplication) && $vacationLeaveTypeId !== null) {
                $vacationBalance = $this->findPreferredEmployeeLeaveBalanceRecord(
                    (string) $app->employee_control_no,
                    $vacationLeaveTypeId
                );
                $currentVacationBalance = $vacationBalance ? (float) $vacationBalance->balance : 0.0;
                if ($isForcedLeaveApplication && $currentVacationBalance <= 0.0) {
                    return $this->buildRequiredForcedLeaveVacationBalanceResponse($currentVacationBalance);
                }

                if ($currentVacationBalance + 1e-9 < $requiredVacationLeaveDays) {
                    if ($usesVacationLeaveTopUpForScheduleExcess) {
                        return $this->buildInsufficientVacationLeaveTopUpResponse(
                            $leaveType,
                            $currentVacationBalance,
                            $requiredVacationLeaveDays
                        );
                    }

                    return $this->buildInsufficientLinkedVacationLeaveBalanceResponse(
                        $leaveType,
                        $currentVacationBalance,
                        $requiredVacationLeaveDays
                    );
                }
            }

            if ($requiredLinkedSickLeaveDays > 0.0) {
                $sickLeaveTypeId = $this->resolveSickLeaveTypeId();
                $sickBalance = $sickLeaveTypeId !== null
                    ? $this->findPreferredEmployeeLeaveBalanceRecord(
                        (string) $app->employee_control_no,
                        $sickLeaveTypeId
                    )
                    : null;
                $currentSickBalance = $sickBalance ? (float) $sickBalance->balance : 0.0;
                if ($currentSickBalance + 1e-9 < $requiredLinkedSickLeaveDays) {
                    return response()->json([
                        'message' => 'Insufficient Sick Leave balance for leave approval.'
                            .' Current: '.LeaveWorkflowService::formatDays($currentSickBalance)
                            .', Required: '.LeaveWorkflowService::formatDays($requiredLinkedSickLeaveDays).'.',
                    ], 422);
                }
            }
        } elseif ($app->applicant_admin_id) {
            $balance = $this->findAdminEmployeeLeaveBalance(
                (int) $app->applicant_admin_id,
                (int) $app->leave_type_id
            );
            $currentBalance = $balance ? (float) $balance->balance : 0.0;
            if ($isForcedLeaveApplication) {
                $requiredVacationLeaveDays = round(max($daysToDeduct, 0.0), 3);
                $requiredPrimaryLeaveDays = 0.0;
            } elseif ($usesVacationLeaveTopUpForScheduleExcess) {
                $requiredVacationLeaveDays = round(max($daysToDeduct - max($currentBalance, 0.0), 0.0), 3);
                $requiredPrimaryLeaveDays = round(max($daysToDeduct - $requiredVacationLeaveDays, 0.0), 3);
            }

            if (
                ! $usesVacationLeaveTopUpForScheduleExcess
                && $requiredPrimaryLeaveDays > 0.0
                && (! $balance || (float) $balance->balance < $requiredPrimaryLeaveDays)
            ) {
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
                        .LeaveWorkflowService::formatDays($currentBalance)
                        .', Requested: '
                        .LeaveWorkflowService::formatDays($requiredPrimaryLeaveDays)
                    .'.',
                ], 422);
            }

            if ($isForcedLeaveApplication && $vacationLeaveTypeId === null) {
                return $this->buildRequiredForcedLeaveVacationBalanceResponse(0.0);
            }

            if (($requiredVacationLeaveDays > 0.0 || $isForcedLeaveApplication) && $vacationLeaveTypeId !== null) {
                $vacationBalance = $this->findAdminEmployeeLeaveBalance(
                    (int) $app->applicant_admin_id,
                    $vacationLeaveTypeId
                );
                $currentVacationBalance = $vacationBalance ? (float) $vacationBalance->balance : 0.0;
                if ($isForcedLeaveApplication && $currentVacationBalance <= 0.0) {
                    return $this->buildRequiredForcedLeaveVacationBalanceResponse($currentVacationBalance);
                }

                if ($currentVacationBalance + 1e-9 < $requiredVacationLeaveDays) {
                    if ($usesVacationLeaveTopUpForScheduleExcess) {
                        return $this->buildInsufficientVacationLeaveTopUpResponse(
                            $leaveType,
                            $currentVacationBalance,
                            $requiredVacationLeaveDays
                        );
                    }

                    return $this->buildInsufficientLinkedVacationLeaveBalanceResponse(
                        $leaveType,
                        $currentVacationBalance,
                        $requiredVacationLeaveDays
                    );
                }
            }

            if ($requiredLinkedSickLeaveDays > 0.0) {
                $sickLeaveTypeId = $this->resolveSickLeaveTypeId();
                $sickBalance = $sickLeaveTypeId !== null
                    ? $this->findAdminEmployeeLeaveBalance(
                        (int) $app->applicant_admin_id,
                        $sickLeaveTypeId
                    )
                    : null;
                $currentSickBalance = $sickBalance ? (float) $sickBalance->balance : 0.0;
                if ($currentSickBalance + 1e-9 < $requiredLinkedSickLeaveDays) {
                    return response()->json([
                        'message' => 'Insufficient Sick Leave balance for leave approval.'
                            .' Current: '.LeaveWorkflowService::formatDays($currentSickBalance)
                            .', Required: '.LeaveWorkflowService::formatDays($requiredLinkedSickLeaveDays).'.',
                    ], 422);
                }
            }

            if ($app->is_monetization && is_callable($validateAdminMonetization) && $balance) {
                $monetizationBalanceRestriction = $validateAdminMonetization(
                    (float) $balance->balance,
                    (float) ($app->total_days ?? 0.0)
                );
                if ($monetizationBalanceRestriction instanceof JsonResponse) {
                    return $monetizationBalanceRestriction;
                }
            }
        }

        return null;
    }

    public function resolveApprovalBalanceConflictResponse(
        LeaveApplication $app,
        LeaveType $leaveType,
        float $daysToDeduct,
        bool $isForcedLeaveApplication,
        bool $shouldDeductVacationLeave,
        bool $usesVacationLeaveTopUpForScheduleExcess,
        float $requiredPrimaryLeaveDays,
        float $requiredVacationLeaveDays,
        float $requiredLinkedSickLeaveDays,
        ?int $vacationLeaveTypeId,
        bool $isCtoDeduction
    ): JsonResponse {
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
            if ($isForcedLeaveApplication) {
                $requiredVacationLeaveDays = round(max($daysToDeduct, 0.0), 3);
                $requiredPrimaryLeaveDays = 0.0;
            } elseif ($usesVacationLeaveTopUpForScheduleExcess) {
                $requiredVacationLeaveDays = round(max($daysToDeduct - max($currentBalance, 0.0), 0.0), 3);
                $requiredPrimaryLeaveDays = round(max($daysToDeduct - $requiredVacationLeaveDays, 0.0), 3);
            }

            if ($isForcedLeaveApplication && $vacationLeaveTypeId === null) {
                return $this->buildRequiredForcedLeaveVacationBalanceResponse(0.0);
            }

            if (($shouldDeductVacationLeave || $isForcedLeaveApplication) && $vacationLeaveTypeId !== null && ($requiredVacationLeaveDays > 0.0 || $isForcedLeaveApplication)) {
                $currentVacationBalance = (float) (
                    $this->findPreferredEmployeeLeaveBalanceRecord(
                        (string) $app->employee_control_no,
                        $vacationLeaveTypeId
                    )?->balance ?? 0.0
                );
                if ($isForcedLeaveApplication && $currentVacationBalance <= 0.0) {
                    return $this->buildRequiredForcedLeaveVacationBalanceResponse($currentVacationBalance);
                }

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
                            .' Current: '.LeaveWorkflowService::formatDays($currentVacationBalance)
                            .', Required: '.LeaveWorkflowService::formatDays($requiredVacationLeaveDays).'.',
                    ], 422);
                }
            }

            if ($requiredLinkedSickLeaveDays > 0.0) {
                $sickLeaveTypeId = $this->resolveSickLeaveTypeId();
                $currentSickBalance = $sickLeaveTypeId !== null
                    ? (float) (
                        $this->findPreferredEmployeeLeaveBalanceRecord(
                            (string) $app->employee_control_no,
                            $sickLeaveTypeId
                        )?->balance ?? 0.0
                    )
                    : 0.0;
                if ($currentSickBalance + 1e-9 < $requiredLinkedSickLeaveDays) {
                    return response()->json([
                        'message' => 'Insufficient Sick Leave balance for leave approval.'
                            .' Current: '.LeaveWorkflowService::formatDays($currentSickBalance)
                            .', Required: '.LeaveWorkflowService::formatDays($requiredLinkedSickLeaveDays).'.',
                    ], 422);
                }
            }

            $label = $app->is_monetization ? 'monetization' : 'leave';

            return response()->json([
                'message' => "Insufficient leave balance for {$label}. Current: "
                    .LeaveWorkflowService::formatDays($currentBalance)
                    .', Requested: '
                    .LeaveWorkflowService::formatDays($requiredPrimaryLeaveDays)
                .'.',
            ], 422);
        }

        $currentBalance = (float) (
            $this->findAdminEmployeeLeaveBalance(
                (int) $app->applicant_admin_id,
                (int) $app->leave_type_id
            )?->balance ?? 0
        );
        if ($isForcedLeaveApplication) {
            $requiredVacationLeaveDays = round(max($daysToDeduct, 0.0), 3);
            $requiredPrimaryLeaveDays = 0.0;
        } elseif ($usesVacationLeaveTopUpForScheduleExcess) {
            $requiredVacationLeaveDays = round(max($daysToDeduct - max($currentBalance, 0.0), 0.0), 3);
            $requiredPrimaryLeaveDays = round(max($daysToDeduct - $requiredVacationLeaveDays, 0.0), 3);
        }

        if ($isForcedLeaveApplication && $vacationLeaveTypeId === null) {
            return $this->buildRequiredForcedLeaveVacationBalanceResponse(0.0);
        }

        if (($shouldDeductVacationLeave || $isForcedLeaveApplication) && $vacationLeaveTypeId !== null && ($requiredVacationLeaveDays > 0.0 || $isForcedLeaveApplication)) {
            $currentVacationBalance = (float) (
                $this->findAdminEmployeeLeaveBalance(
                    (int) $app->applicant_admin_id,
                    $vacationLeaveTypeId
                )?->balance ?? 0.0
            );
            if ($isForcedLeaveApplication && $currentVacationBalance <= 0.0) {
                return $this->buildRequiredForcedLeaveVacationBalanceResponse($currentVacationBalance);
            }

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
                        .' Current: '.LeaveWorkflowService::formatDays($currentVacationBalance)
                        .', Required: '.LeaveWorkflowService::formatDays($requiredVacationLeaveDays).'.',
                ], 422);
            }
        }

        if ($requiredLinkedSickLeaveDays > 0.0) {
            $sickLeaveTypeId = $this->resolveSickLeaveTypeId();
            $currentSickBalance = $sickLeaveTypeId !== null
                ? (float) (
                    $this->findAdminEmployeeLeaveBalance(
                        (int) $app->applicant_admin_id,
                        $sickLeaveTypeId
                    )?->balance ?? 0.0
                )
                : 0.0;
            if ($currentSickBalance + 1e-9 < $requiredLinkedSickLeaveDays) {
                return response()->json([
                    'message' => 'Insufficient Sick Leave balance for leave approval.'
                        .' Current: '.LeaveWorkflowService::formatDays($currentSickBalance)
                        .', Required: '.LeaveWorkflowService::formatDays($requiredLinkedSickLeaveDays).'.',
                ], 422);
            }
        }

        return response()->json([
            'message' => 'Insufficient leave balance. Current: '
                .LeaveWorkflowService::formatDays($currentBalance)
                .', Requested: '
                .LeaveWorkflowService::formatDays($requiredPrimaryLeaveDays)
            .'.',
        ], 422);
    }

    public function resolveEmployeeLeaveBalanceSnapshot(
        string $employeeControlNo,
        int $leaveTypeId,
        ?callable $candidatesResolver = null
    ): array {
        $controlNoCandidates = $candidatesResolver !== null
            ? $candidatesResolver($employeeControlNo)
            : [$employeeControlNo];
        $lookupLeaveTypeIds = $this->resolveBalanceLookupLeaveTypeIds($leaveTypeId);
        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;

        $isCtoSnapshot = $this->isCtoLeaveType(null, $canonicalLeaveTypeId);

        if ($isCtoSnapshot) {
            $pendingReservedHoursExpression = $this->hasLeaveApplicationCtoHoursColumn()
                ? 'COALESCE(cto_deducted_hours, COALESCE(deductible_days, total_days) * 8.0)'
                : 'COALESCE(deductible_days, total_days) * 8.0';
            $currentBalanceHours = round($this->cocLedgerService->getAvailableHours($employeeControlNo, $canonicalLeaveTypeId), 2);
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
            $currentBalance = round($currentBalanceHours / self::CTO_STANDARD_DAY_HOURS, 3);
            $pendingReservedDays = round($pendingReservedHours / self::CTO_STANDARD_DAY_HOURS, 3);
            $availableBalance = round($availableBalanceHours / self::CTO_STANDARD_DAY_HOURS, 3);

            return [
                'current_balance' => $currentBalance,
                'current_balance_hours' => $currentBalanceHours,
                'pending_reserved_days' => $pendingReservedDays,
                'pending_reserved_hours' => $pendingReservedHours,
                'available_balance' => $availableBalance,
                'available_balance_hours' => $availableBalanceHours,
            ];
        }

        $balance = $this->findPreferredEmployeeLeaveBalanceRecord(
            $employeeControlNo,
            $canonicalLeaveTypeId,
            false,
            $candidatesResolver
        );
        $currentBalance = $balance ? (float) $balance->balance : 0.0;

        $pendingReservedDays = $this->resolvePendingReservedDaysForTrackedLeaveType(
            $employeeControlNo,
            $canonicalLeaveTypeId,
            null,
            $candidatesResolver
        );

        $availableBalance = max(round($currentBalance - $pendingReservedDays, 3), 0.0);

        return [
            'current_balance' => $currentBalance,
            'current_balance_hours' => 0.0,
            'pending_reserved_days' => $pendingReservedDays,
            'pending_reserved_hours' => 0.0,
            'available_balance' => $availableBalance,
            'available_balance_hours' => 0.0,
        ];
    }

    public function resolveTrackedLeaveBalanceBeforeApplicationDeduction(
        LeaveApplication $app,
        ?int $leaveTypeId,
        float $storedDeduction,
        ?callable $adminControlNoResolver = null,
        ?callable $candidatesResolver = null
    ): float {
        $resolvedLeaveTypeId = $leaveTypeId !== null
            ? ($this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId)
            : null;
        if ($resolvedLeaveTypeId === null || $resolvedLeaveTypeId <= 0) {
            return 0.0;
        }

        $employeeControlNo = $this->resolveApplicationBalanceOwnerControlNo($app, $adminControlNoResolver);
        if ($employeeControlNo === null) {
            return round(max($storedDeduction, 0.0), 3);
        }

        $currentBalance = (float) (
            $this->findPreferredEmployeeLeaveBalanceRecord(
                $employeeControlNo,
                $resolvedLeaveTypeId,
                false,
                $candidatesResolver
            )?->balance ?? 0.0
        );

        return round(max($currentBalance + max($storedDeduction, 0.0), 0.0), 3);
    }

    public function resolvePendingTrackedLeaveBalanceAvailableToApplication(
        LeaveApplication $app,
        ?int $leaveTypeId,
        float $storedDeduction,
        ?callable $adminControlNoResolver = null,
        ?callable $candidatesResolver = null
    ): float {
        $resolvedLeaveTypeId = $leaveTypeId !== null
            ? ($this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId)
            : null;
        if ($resolvedLeaveTypeId === null || $resolvedLeaveTypeId <= 0) {
            return 0.0;
        }

        $employeeControlNo = $this->resolveApplicationBalanceOwnerControlNo($app, $adminControlNoResolver);
        if ($employeeControlNo === null) {
            return round(max($storedDeduction, 0.0), 3);
        }

        $balanceSnapshot = $this->resolveEmployeeLeaveBalanceSnapshot(
            $employeeControlNo,
            $resolvedLeaveTypeId,
            $candidatesResolver
        );
        $availableBalance = (float) ($balanceSnapshot['available_balance'] ?? 0.0);

        return round(max($availableBalance + max($storedDeduction, 0.0), 0.0), 3);
    }

    public function applyScheduleRepricingBalanceDelta(
        LeaveApplication $application,
        int $leaveTypeId,
        float $deltaDays,
        string $balanceConflictCode,
        ?callable $candidatesResolver = null,
        ?callable $adminControlNoResolver = null,
        ?callable $employeeResolver = null
    ): void {
        $normalizedDeltaDays = round($deltaDays, 3);
        if ($leaveTypeId <= 0 || abs($normalizedDeltaDays) < 0.001) {
            return;
        }

        if ($application->employee_control_no) {
            $balance = $this->findPreferredEmployeeLeaveBalanceRecord(
                (string) $application->employee_control_no,
                $leaveTypeId,
                true,
                $candidatesResolver
            );

            if (! $balance) {
                if ($normalizedDeltaDays > 0) {
                    throw new \RuntimeException($balanceConflictCode);
                }

                $resolvedEmployee = $employeeResolver !== null
                    ? $employeeResolver((string) $application->employee_control_no)
                    : null;
                $canonicalControlNo = trim((string) (
                    $resolvedEmployee?->control_no
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
                true,
                $adminControlNoResolver,
                $candidatesResolver
            );

            if (! $balance) {
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

    public function formatErmsLeaveBalancePayload(
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
                    : $this->calculationService->resolveCtoDeductedHours((float) ($balance?->balance ?? 0.0))
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

    public function emptyErmsLeaveBalancePayload(string $leaveTypeName): array
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

    public function buildErmsAccruedLeaveCard(
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

    public function buildErmsLatestAccruedCreditsPayload(
        object $employee,
        array $typesByName,
        array $balanceRecordsByType,
        array $deductionHistoryByType,
        ?callable $employmentStatusKeyResolver = null
    ): array {
        $statusKey = $employmentStatusKeyResolver !== null
            ? $employmentStatusKeyResolver($employee->status ?? null)
            : $this->eligibilityService()->resolveEmploymentStatusKey($employee->status ?? null);

        $isContractual = $statusKey === LeaveType::EMPLOYMENT_STATUS_CONTRACTUAL;

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

    public function mergeCreditHistoriesByType(array ...$historyGroups): array
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

    public function loadEmployeeLeaveDeductionHistoryByType(
        string $controlNo,
        ?int $leaveTypeId = null,
        ?callable $candidatesResolver = null
    ): array {
        $lookupLeaveTypeIds = $leaveTypeId !== null
            ? $this->resolveBalanceLookupLeaveTypeIds($leaveTypeId)
            : [];

        $candidates = $candidatesResolver !== null
            ? $candidatesResolver($controlNo)
            : [$controlNo];

        $applications = LeaveApplication::query()
            ->with(['leaveType:id,name,is_credit_based'])
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->whereIn('employee_control_no', $candidates)
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

    public function executeGetLeaveBalance(
        string $controlNo,
        int $leaveTypeId,
        ?callable $findEmployeeByControlNo = null,
        ?callable $assertEmployeeCanAccessLeaveTypeBalance = null,
        ?callable $candidatesResolver = null
    ): JsonResponse {
        $employee = $findEmployeeByControlNo !== null
            ? $findEmployeeByControlNo($controlNo)
            : null;

        if (! $employee) {
            return response()->json(['message' => 'Employee record not found.'], 404);
        }

        $resolvedLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        $leaveType = LeaveType::find($resolvedLeaveTypeId);
        if (! $leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        $assertAccess = $assertEmployeeCanAccessLeaveTypeBalance ?? fn ($emp, $lt) => $this->eligibilityService()->assertEmployeeCanAccessLeaveTypeBalance($emp, $lt);
        $accessResponse = $assertAccess($employee, $leaveType);
        if ($accessResponse instanceof JsonResponse) {
            return $accessResponse;
        }

        $ctoBalanceHours = null;
        if ($this->isCtoLeaveType($leaveType, (int) $leaveType->id)) {
            $this->syncEmployeeCtoBalance((string) $employee->control_no, false, null, $candidatesResolver, $findEmployeeByControlNo);
            $ctoSnapshot = $this->cocLedgerService->syncEmployeeLedger(
                (string) $employee->control_no,
                (int) $leaveType->id,
                null,
                false
            );
            $ctoBalanceHours = isset($ctoSnapshot['availableHours'])
                ? (float) $ctoSnapshot['availableHours']
                : null;
        }

        $balance = $this->findPreferredEmployeeLeaveBalanceRecord(
            (string) $employee->control_no,
            (int) $leaveType->id,
            $candidatesResolver
        );
        if ($balance instanceof LeaveBalance) {
            $balance->loadMissing('accrualHistories');
        }

        return response()->json([
            'leave_type_id' => $leaveType->id,
            'leave_type_name' => $leaveType->name,
            'balance' => $balance ? (float) $balance->balance : 0,
            'balance_hours' => $this->isCtoLeaveType($leaveType, (int) $leaveType->id)
                ? $this->calculationService->resolveCtoDeductedHours((float) ($balance?->balance ?? 0.0))
                : null,
        ]);
    }

    public function executeErmsGetLeaveBalance(
        string $controlNo,
        int $leaveTypeId,
        ?callable $findEmployeeByControlNo = null,
        ?callable $assertErmsEmployeeIsPulled = null,
        ?callable $assertEmployeeCanAccessLeaveTypeBalance = null,
        ?callable $controlNoResponseBuilder = null,
        ?callable $candidatesResolver = null
    ): JsonResponse {
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

        $resolvedLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        $leaveType = LeaveType::find($resolvedLeaveTypeId);
        if (! $leaveType) {
            return response()->json(['message' => 'Leave type not found.'], 404);
        }

        $assertAccess = $assertEmployeeCanAccessLeaveTypeBalance ?? fn ($emp, $lt) => $this->eligibilityService()->assertEmployeeCanAccessLeaveTypeBalance($emp, $lt);
        $leaveTypeBalanceAccess = $assertAccess($employee, $leaveType);
        if ($leaveTypeBalanceAccess instanceof JsonResponse) {
            return $leaveTypeBalanceAccess;
        }

        $ctoBalanceHours = null;
        if ($this->isCtoLeaveType($leaveType, (int) $leaveType->id)) {
            $this->syncEmployeeCtoBalance((string) $employee->control_no, false, null, $candidatesResolver, $findEmployeeByControlNo);
            $ctoSnapshot = $this->cocLedgerService->syncEmployeeLedger(
                (string) $employee->control_no,
                (int) $leaveType->id,
                null,
                false
            );
            $ctoBalanceHours = isset($ctoSnapshot['availableHours'])
                ? (float) $ctoSnapshot['availableHours']
                : null;
        }

        $balance = $this->findPreferredEmployeeLeaveBalanceRecord((string) $employee->control_no, (int) $leaveType->id, $candidatesResolver);
        if ($balance instanceof LeaveBalance) {
            $balance->loadMissing('accrualHistories');
        }

        $deductionHistoryByType = $this->loadEmployeeLeaveDeductionHistoryByType((string) $employee->control_no, (int) $leaveType->id, $candidatesResolver);
        $cocCreditHistoryByType = $this->cocLedgerService->getHistoryByLeaveType((string) $employee->control_no, (int) $leaveType->id);
        $creditHistoryByType = $this->mergeCreditHistoriesByType(
            $deductionHistoryByType,
            $cocCreditHistoryByType
        );

        $controlNoPayload = $controlNoResponseBuilder !== null
            ? $controlNoResponseBuilder((string) $employee->control_no)
            : ['employee_control_no' => (string) $employee->control_no];

        return response()->json(array_merge(
            $controlNoPayload,
            $this->formatErmsLeaveBalancePayload(
                $leaveType,
                $balance,
                $creditHistoryByType[(int) $leaveType->id] ?? [],
                $ctoBalanceHours
            )
        ));
    }

    public function handleErmsGetLeaveBalance(
        Request $request,
        int $id,
        ?callable $findEmployeeByControlNo = null,
        ?callable $assertErmsEmployeeIsPulled = null,
        ?callable $assertEmployeeCanAccessLeaveTypeBalance = null,
        ?callable $controlNoResponseBuilder = null,
        ?callable $candidatesResolver = null
    ): JsonResponse {
        $employeeControlNo = trim((string) $request->input('employee_control_no', ''));
        if ($employeeControlNo !== '') {
            $request->merge(['employee_control_no' => $employeeControlNo]);
        }

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

        return $this->executeErmsGetLeaveBalance(
            $controlNo,
            $leaveTypeId,
            $findEmployeeByControlNo,
            $assertErmsEmployeeIsPulled,
            $assertEmployeeCanAccessLeaveTypeBalance,
            $controlNoResponseBuilder,
            $candidatesResolver
        );
    }

    public function executeErmsGetLeaveBalances(
        string $controlNo,
        ?callable $findEmployeeByControlNo = null,
        ?callable $assertErmsEmployeeIsPulled = null,
        ?callable $controlNoResponseBuilder = null,
        ?callable $candidatesResolver = null,
        ?callable $employmentStatusFilter = null,
        ?callable $employmentStatusKeyResolver = null
    ): JsonResponse {
        if (! preg_match('/^\d+$/', $controlNo)) {
            return response()->json(['message' => 'Invalid control number.'], 422);
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
            ->filter(function (LeaveType $leaveType) use ($employee, $employmentStatusFilter): bool {
                if ($employmentStatusFilter !== null) {
                    return $employmentStatusFilter($employee, $leaveType);
                }

                return ! $this->eligibilityService()->employeeHasResolvedEmploymentStatus($employee)
                    || $leaveType->allowsEmploymentStatus($employee->status ?? null);
            })
            ->values();

        $typesByName = $types
            ->keyBy(fn (LeaveType $type) => strtolower(trim((string) $type->name)))
            ->all();

        $this->syncEmployeeCtoBalance((string) $employee->control_no, false, null, $candidatesResolver, $findEmployeeByControlNo);
        $ctoLeaveTypeId = $this->resolveCtoLeaveTypeId();
        $ctoBalanceHours = null;
        if ($ctoLeaveTypeId !== null) {
            $ctoSnapshot = $this->cocLedgerService->syncEmployeeLedger(
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
        $deductionHistoryByType = $this->loadEmployeeLeaveDeductionHistoryByType((string) $employee->control_no, null, $candidatesResolver);
        $cocCreditHistoryByType = $this->cocLedgerService->getHistoryByLeaveType((string) $employee->control_no);
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

        $controlNoPayload = $controlNoResponseBuilder !== null
            ? $controlNoResponseBuilder((string) $employee->control_no)
            : ['employee_control_no' => (string) $employee->control_no];

        return response()->json([
            ...$controlNoPayload,
            'salary' => $employee->rate_mon !== null ? (float) $employee->rate_mon : null,
            'rate_mon' => $employee->rate_mon !== null ? (float) $employee->rate_mon : null,
            'balances' => $balances,
            'latest_accrued_credits' => $this->buildErmsLatestAccruedCreditsPayload(
                $employee,
                $typesByName,
                $balanceRecordsByType,
                $creditHistoryByType,
                $employmentStatusKeyResolver
            ),
        ]);
    }
}
