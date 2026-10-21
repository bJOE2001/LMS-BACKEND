<?php

namespace App\Services\Leave;

use App\Models\LeaveApplication;
use App\Models\LeaveType;
use Illuminate\Support\Facades\Schema;

class LeaveCertificationService
{
    public const TERMINAL_LEAVE_ESTIMATE_FACTOR = 0.0478087;

    public const TERMINAL_LEAVE_AMOUNT_PRECISION = 12;

    public const CERTIFICATION_SELECTED_LEAVE_TYPE_BUCKET = 'selected_leave_type';

    public function __construct(
        public LeaveCalculationService $calculationService
    ) {}

    public function roundLeaveCreditValue(float $value): float
    {
        return round(max($value, 0.0), 3);
    }

    public function trimNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    public function hasLeaveApplicationCertificationCreditsSnapshotColumn(): bool
    {
        static $resolved = false;
        static $hasColumn = false;

        if (! $resolved) {
            $hasColumn = Schema::hasColumn('tblLeaveApplications', 'certification_leave_credits_snapshot');
            $resolved = true;
        }

        return $hasColumn;
    }

    public function resolveApplicationBalanceLookupControlNo(
        LeaveApplication $app,
        ?callable $employeeResolver = null
    ): ?string {
        $directControlNo = trim((string) ($app->employee_control_no ?? ''));
        if ($directControlNo !== '') {
            return $directControlNo;
        }

        $resolvedEmployee = $employeeResolver !== null ? $employeeResolver($app) : null;
        $employeeControlNo = trim((string) ($resolvedEmployee?->control_no ?? ''));

        return $employeeControlNo !== '' ? $employeeControlNo : null;
    }

    public function findLeaveTypeBalanceInSnapshot(array $snapshot, int $leaveTypeId): ?float
    {
        if ($leaveTypeId <= 0 || $snapshot === []) {
            return null;
        }

        $canonicalLeaveTypeId = LeaveType::resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
        foreach ($snapshot as $entry) {
            if ((int) ($entry['leave_type_id'] ?? 0) === $canonicalLeaveTypeId) {
                return (float) ($entry['balance'] ?? 0.0);
            }
        }

        return null;
    }

    public function findLeaveTypeBalanceByNameInSnapshot(array $snapshot, string $leaveTypeName): float
    {
        foreach ($snapshot as $entry) {
            if (strcasecmp((string) ($entry['leave_type_name'] ?? ''), $leaveTypeName) === 0) {
                return $this->roundLeaveCreditValue((float) ($entry['balance'] ?? 0.0));
            }
        }

        return 0.0;
    }

    public function resolveTerminalLeaveEstimatedAmount(
        ?float $monthlyRate,
        float $vacationLeaveBalance,
        float $sickLeaveBalance
    ): ?float {
        if ($monthlyRate === null || $monthlyRate <= 0) {
            return null;
        }

        return round(
            ($vacationLeaveBalance + $sickLeaveBalance)
                * $monthlyRate
                * self::TERMINAL_LEAVE_ESTIMATE_FACTOR,
            self::TERMINAL_LEAVE_AMOUNT_PRECISION
        );
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

    public function serializeMonetizationLeaveCreditComponents(array $components): array
    {
        return array_values(array_map(static fn (array $component): array => [
            'leave_type_id' => (int) $component['leave_type_id'],
            'leave_type_name' => (string) $component['leave_type_name'],
            'days' => round((float) $component['days'], 3),
        ], $components));
    }

    /**
     * @return array<int, array{leave_type_id:int, leave_type_name:string, days:float}>
     */
    public function resolveStoredMonetizationLeaveCreditComponents(
        LeaveApplication $app,
        ?LeaveType $fallbackLeaveType = null
    ): array {
        $rawComponents = $app->monetization_leave_credits ?? null;
        if (is_string($rawComponents)) {
            $decodedComponents = json_decode($rawComponents, true);
            $rawComponents = json_last_error() === JSON_ERROR_NONE ? $decodedComponents : null;
        }

        $components = [];
        if (is_array($rawComponents)) {
            foreach ($rawComponents as $rawComponent) {
                if (! is_array($rawComponent)) {
                    continue;
                }

                $leaveTypeId = (int) ($rawComponent['leave_type_id'] ?? $rawComponent['leaveTypeId'] ?? 0);
                $days = round((float) ($rawComponent['days'] ?? $rawComponent['total_days'] ?? 0), 3);
                if ($leaveTypeId <= 0 || $days <= 0.0) {
                    continue;
                }

                $canonicalLeaveTypeId = LeaveType::resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
                $leaveTypeName = $this->trimNullableString($rawComponent['leave_type_name'] ?? null);
                if ($leaveTypeName === null) {
                    $leaveTypeName = LeaveType::query()->whereKey($canonicalLeaveTypeId)->value('name');
                }

                $components[] = [
                    'leave_type_id' => $canonicalLeaveTypeId,
                    'leave_type_name' => (string) ($leaveTypeName ?: 'Leave'),
                    'days' => $days,
                ];
            }
        }

        if ($components !== []) {
            return $this->serializeMonetizationLeaveCreditComponents($components);
        }

        $fallbackLeaveType ??= $app->leaveType instanceof LeaveType
            ? $app->leaveType
            : LeaveType::query()->find((int) $app->leave_type_id);
        $fallbackLeaveTypeId = (int) (LeaveType::resolveCanonicalLeaveTypeId((int) ($fallbackLeaveType?->id ?? $app->leave_type_id)) ?? (int) $app->leave_type_id);

        if ($fallbackLeaveTypeId <= 0) {
            return [];
        }

        return [[
            'leave_type_id' => $fallbackLeaveTypeId,
            'leave_type_name' => (string) ($fallbackLeaveType?->name ?? 'Leave'),
            'days' => round((float) ($app->deductible_days ?? $app->total_days ?? 0), 3),
        ]];
    }

    public function resolveFallbackPrimaryLeaveDeduction(LeaveApplication $app, float $deductibleDays): float
    {
        $normalizedFallbackDeductibleDays = round(max($deductibleDays, 0.0), 3);
        $forcedLeaveTypeId = LeaveType::resolveForcedLeaveTypeId();
        $leaveTypeId = LeaveType::resolveCanonicalLeaveTypeId((int) $app->leave_type_id) ?? (int) $app->leave_type_id;

        if (
            ! (bool) $app->is_monetization
            && $forcedLeaveTypeId !== null
            && $leaveTypeId === $forcedLeaveTypeId
        ) {
            return 0.0;
        }

        if ((bool) ($app->allow_sl_vl_cross_deduction ?? false)) {
            $linkedVacation = (float) ($app->linked_vacation_leave_deducted_days ?? 0.0);
            $linkedSick = (float) ($app->linked_sick_leave_deducted_days ?? 0.0);

            return round(max($normalizedFallbackDeductibleDays - $linkedVacation - $linkedSick, 0.0), 3);
        }

        return $normalizedFallbackDeductibleDays;
    }

    public function resolveCertificationLeaveDeductionsByType(
        LeaveApplication $app,
        ?callable $primaryDeductionResolver = null,
        ?callable $monetizationComponentsResolver = null
    ): array {
        $normalizedPayMode = $this->calculationService->normalizePayMode($app->pay_mode ?? null, (bool) $app->is_monetization);
        $isForcedLeave = LeaveType::isForcedLeaveType($app->leaveType, (int) $app->leave_type_id);
        if ($isForcedLeave) {
            $normalizedPayMode = LeaveApplication::PAY_MODE_WITH_PAY;
        }
        $deductibleDays = $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY
            ? 0.0
            : $this->roundLeaveCreditValue($this->calculationService->resolveApplicationDeductibleDays($app));
        $linkedVacationDeduction = $this->roundLeaveCreditValue((float) ($app->linked_vacation_leave_deducted_days ?? 0.0));
        $linkedSickDeduction = $this->roundLeaveCreditValue((float) ($app->linked_sick_leave_deducted_days ?? 0.0));
        $leaveTypeName = trim((string) ($app->leaveType?->name ?? ''));

        $vacation = 0.0;
        $sick = 0.0;
        if ((bool) $app->is_monetization) {
            $components = $monetizationComponentsResolver !== null
                ? $monetizationComponentsResolver($app)
                : $this->resolveStoredMonetizationLeaveCreditComponents($app);

            foreach ($components as $component) {
                if ($this->isVacationLeaveType(null, (int) $component['leave_type_id'])) {
                    $vacation += (float) $component['days'];
                } elseif ($this->isSickLeaveType(null, (int) $component['leave_type_id'])) {
                    $sick += (float) $component['days'];
                }
            }
        } elseif (strcasecmp($leaveTypeName, 'Vacation Leave') === 0) {
            $primaryDeductionDays = $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY
                ? 0.0
                : $this->roundLeaveCreditValue(
                    $primaryDeductionResolver !== null
                        ? $primaryDeductionResolver($app, $app->leaveType, $deductibleDays)
                        : $this->resolveFallbackPrimaryLeaveDeduction($app, $deductibleDays)
                );
            $vacation = $primaryDeductionDays;
            $sick = $linkedSickDeduction;
        } elseif (strcasecmp($leaveTypeName, 'Sick Leave') === 0) {
            $primaryDeductionDays = $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY
                ? 0.0
                : $this->roundLeaveCreditValue(
                    $primaryDeductionResolver !== null
                        ? $primaryDeductionResolver($app, $app->leaveType, $deductibleDays)
                        : $this->resolveFallbackPrimaryLeaveDeduction($app, $deductibleDays)
                );
            $sick = $primaryDeductionDays;
            $vacation = $linkedVacationDeduction;
        } elseif ($isForcedLeave) {
            $vacation = $linkedVacationDeduction > 0.0
                ? $linkedVacationDeduction
                : $deductibleDays;
        } elseif ($linkedVacationDeduction > 0.0) {
            $vacation = $linkedVacationDeduction;
        }

        return [
            'vacation' => $this->roundLeaveCreditValue($vacation),
            'sick' => $this->roundLeaveCreditValue($sick),
        ];
    }

    /**
     * @return array{leave_type_id:int, leave_type_name:string, less_this_application:float}|null
     */
    public function resolveSelectedCertificationLeaveTypeDeduction(LeaveApplication $app): ?array
    {
        $leaveType = $app->leaveType;
        if (! $leaveType instanceof LeaveType || (bool) $app->is_monetization || ! (bool) $leaveType->is_credit_based) {
            return null;
        }

        $leaveTypeName = LeaveType::canonicalizeLeaveTypeName($leaveType->name ?? null);
        if ($leaveTypeName === null || strcasecmp($leaveTypeName, 'Mandatory / Forced Leave') === 0) {
            return null;
        }

        if (in_array(strtolower($leaveTypeName), ['vacation leave', 'sick leave'], true)) {
            return null;
        }

        $leaveTypeId = (int) ($leaveType->id ?? $app->leave_type_id);
        if ($leaveTypeId <= 0) {
            $leaveTypeId = (int) $app->leave_type_id;
        }
        if ($leaveTypeId <= 0) {
            return null;
        }

        $normalizedPayMode = $this->calculationService->normalizePayMode($app->pay_mode ?? null, false);
        $deductibleDays = $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY
            ? 0.0
            : $this->roundLeaveCreditValue($this->calculationService->resolveApplicationDeductibleDays($app));
        $linkedVacationDeduction = $this->roundLeaveCreditValue((float) ($app->linked_vacation_leave_deducted_days ?? 0.0));
        $linkedSickDeduction = $this->roundLeaveCreditValue((float) ($app->linked_sick_leave_deducted_days ?? 0.0));
        $lessThisApplication = $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY
            ? 0.0
            : $this->roundLeaveCreditValue(max($deductibleDays - $linkedVacationDeduction - $linkedSickDeduction, 0.0));

        return [
            'leave_type_id' => $leaveTypeId,
            'leave_type_name' => $leaveTypeName,
            'less_this_application' => $lessThisApplication,
        ];
    }

    /**
     * @param  array{leave_type_id:int, leave_type_name:string, less_this_application:float}  $selectedLeaveTypeDeduction
     * @return array{leave_type_id:int, leave_type_name:string, total_earned:float, less_this_application:float, balance:float, balance_after_application:float}
     */
    public function buildSelectedCertificationLeaveTypeBucket(
        float $currentBalance,
        array $selectedLeaveTypeDeduction,
        bool $deductionAlreadyApplied
    ): array {
        $selectedLeaveTypeBalance = $this->roundLeaveCreditValue($currentBalance);
        $selectedLeaveTypeLessThisApplication = $this->roundLeaveCreditValue(
            (float) $selectedLeaveTypeDeduction['less_this_application']
        );
        $selectedLeaveTypeTotalEarned = $this->roundLeaveCreditValue($deductionAlreadyApplied
            ? ($selectedLeaveTypeBalance + $selectedLeaveTypeLessThisApplication)
            : $selectedLeaveTypeBalance);
        $selectedLeaveTypeBalanceAfterApplication = $this->roundLeaveCreditValue($deductionAlreadyApplied
            ? $selectedLeaveTypeBalance
            : ($selectedLeaveTypeBalance - $selectedLeaveTypeLessThisApplication));

        return [
            'leave_type_id' => (int) $selectedLeaveTypeDeduction['leave_type_id'],
            'leave_type_name' => (string) $selectedLeaveTypeDeduction['leave_type_name'],
            'total_earned' => $selectedLeaveTypeTotalEarned,
            'less_this_application' => $selectedLeaveTypeLessThisApplication,
            'balance' => $selectedLeaveTypeBalance,
            'balance_after_application' => $selectedLeaveTypeBalanceAfterApplication,
        ];
    }

    public function normalizeCertificationLeaveCreditsBucket(mixed $bucket): ?array
    {
        if (! is_array($bucket)) {
            return null;
        }

        $totalEarned = $this->roundLeaveCreditValue((float) ($bucket['total_earned'] ?? 0.0));
        $lessThisApplication = $this->roundLeaveCreditValue((float) ($bucket['less_this_application'] ?? 0.0));
        $balance = $this->roundLeaveCreditValue((float) ($bucket['balance'] ?? 0.0));
        $balanceAfterApplication = $this->roundLeaveCreditValue(
            (float) (
                $bucket['balance_after_application']
                ?? $bucket['balance']
                ?? max($balance - $lessThisApplication, 0.0)
            )
        );

        $normalizedBucket = [
            'total_earned' => $totalEarned,
            'less_this_application' => $lessThisApplication,
            'balance' => $balance,
            'balance_after_application' => $balanceAfterApplication,
        ];

        $leaveTypeName = LeaveType::canonicalizeLeaveTypeName(
            $this->trimNullableString($bucket['leave_type_name'] ?? null)
        );
        if ($leaveTypeName !== null) {
            $normalizedBucket['leave_type_name'] = $leaveTypeName;
        }

        $leaveTypeId = (int) ($bucket['leave_type_id'] ?? 0);
        if ($leaveTypeId > 0) {
            $normalizedBucket['leave_type_id'] = $leaveTypeId;
        }

        return $normalizedBucket;
    }

    public function normalizeCertificationLeaveCreditsPayload(array $payload, LeaveApplication $app): ?array
    {
        $vacation = $this->normalizeCertificationLeaveCreditsBucket($payload['vacation'] ?? null);
        $sick = $this->normalizeCertificationLeaveCreditsBucket($payload['sick'] ?? null);
        if ($vacation === null || $sick === null) {
            return null;
        }

        $asOfDate = $this->trimNullableString($payload['as_of_date'] ?? null)
            ?? $app->hr_approved_at?->format('F j, Y')
            ?? $app->created_at?->format('F j, Y')
            ?? now()->format('F j, Y');

        $normalizedPayload = [
            'vacation' => $vacation,
            'sick' => $sick,
            'as_of_date' => $asOfDate,
        ];

        foreach ($payload as $key => $bucket) {
            if (in_array($key, ['vacation', 'sick', 'as_of_date'], true)) {
                continue;
            }

            $normalizedBucket = $this->normalizeCertificationLeaveCreditsBucket($bucket);
            if ($normalizedBucket === null) {
                continue;
            }

            $normalizedPayload[(string) $key] = $normalizedBucket;
        }

        return $normalizedPayload;
    }

    public function certificationLeaveCreditsBucketNeedsRefresh(
        array $bucket,
        float $expectedLessThisApplication
    ): bool {
        $normalizedExpectedLessThisApplication = $this->roundLeaveCreditValue($expectedLessThisApplication);
        $storedLessThisApplication = $this->roundLeaveCreditValue(
            (float) ($bucket['less_this_application'] ?? 0.0)
        );
        if (abs($storedLessThisApplication - $normalizedExpectedLessThisApplication) > 0.0005) {
            return true;
        }

        $storedBalanceAfterApplication = $this->roundLeaveCreditValue(
            (float) (
                $bucket['balance_after_application']
                ?? $bucket['balance']
                ?? 0.0
            )
        );
        $storedTotalEarned = $this->roundLeaveCreditValue((float) ($bucket['total_earned'] ?? 0.0));
        $expectedTotalEarned = $this->roundLeaveCreditValue(
            $storedBalanceAfterApplication + $normalizedExpectedLessThisApplication
        );

        return abs($storedTotalEarned - $expectedTotalEarned) > 0.0005;
    }

    public function shouldRefreshStoredCertificationLeaveCreditsSnapshot(
        LeaveApplication $app,
        array $snapshot,
        ?callable $primaryDeductionResolver = null,
        ?callable $monetizationComponentsResolver = null
    ): bool {
        if (strtoupper(trim((string) ($app->status ?? ''))) !== LeaveApplication::STATUS_APPROVED) {
            return false;
        }

        $vacationBucket = $snapshot['vacation'] ?? null;
        $sickBucket = $snapshot['sick'] ?? null;
        if (! is_array($vacationBucket) || ! is_array($sickBucket)) {
            return true;
        }

        $expectedDeductions = $this->resolveCertificationLeaveDeductionsByType(
            $app,
            $primaryDeductionResolver,
            $monetizationComponentsResolver
        );
        $selectedLeaveTypeDeduction = $this->resolveSelectedCertificationLeaveTypeDeduction($app);
        if ($selectedLeaveTypeDeduction !== null) {
            $selectedBucket = $snapshot[self::CERTIFICATION_SELECTED_LEAVE_TYPE_BUCKET] ?? null;
            if (! is_array($selectedBucket)) {
                return true;
            }

            $storedSelectedLeaveTypeName = LeaveType::canonicalizeLeaveTypeName(
                $this->trimNullableString($selectedBucket['leave_type_name'] ?? null)
            );
            if ($storedSelectedLeaveTypeName !== (string) $selectedLeaveTypeDeduction['leave_type_name']) {
                return true;
            }

            if ($this->certificationLeaveCreditsBucketNeedsRefresh(
                $selectedBucket,
                (float) $selectedLeaveTypeDeduction['less_this_application']
            )) {
                return true;
            }
        }

        return $this->certificationLeaveCreditsBucketNeedsRefresh(
            $vacationBucket,
            (float) ($expectedDeductions['vacation'] ?? 0.0)
        ) || $this->certificationLeaveCreditsBucketNeedsRefresh(
            $sickBucket,
            (float) ($expectedDeductions['sick'] ?? 0.0)
        );
    }

    public function buildCertificationLeaveCredits(
        LeaveApplication $app,
        array $leaveBalanceSnapshot = [],
        ?callable $hasPendingApprovedUpdateResolver = null,
        ?callable $primaryDeductionResolver = null,
        ?callable $monetizationComponentsResolver = null
    ): array {
        $vacationCurrentBalance = $this->findLeaveTypeBalanceByNameInSnapshot($leaveBalanceSnapshot, 'Vacation Leave');
        $sickCurrentBalance = $this->findLeaveTypeBalanceByNameInSnapshot($leaveBalanceSnapshot, 'Sick Leave');
        $applicationStatus = strtoupper(trim((string) ($app->status ?? '')));
        $isPendingStatus = in_array($applicationStatus, [
            LeaveApplication::STATUS_PENDING_ADMIN,
            LeaveApplication::STATUS_PENDING_HR,
        ], true);
        $hasPendingApprovedUpdate = $hasPendingApprovedUpdateResolver !== null
            && $hasPendingApprovedUpdateResolver($app);
        $deductionAlreadyApplied = $applicationStatus === LeaveApplication::STATUS_APPROVED
            || ($isPendingStatus && $hasPendingApprovedUpdate);

        $deductions = $this->resolveCertificationLeaveDeductionsByType(
            $app,
            $primaryDeductionResolver,
            $monetizationComponentsResolver
        );
        $vacationLessThisApplication = (float) ($deductions['vacation'] ?? 0.0);
        $sickLessThisApplication = (float) ($deductions['sick'] ?? 0.0);

        $vacationTotalEarned = $this->roundLeaveCreditValue($deductionAlreadyApplied
            ? ($vacationCurrentBalance + $vacationLessThisApplication)
            : $vacationCurrentBalance);
        $sickTotalEarned = $this->roundLeaveCreditValue($deductionAlreadyApplied
            ? ($sickCurrentBalance + $sickLessThisApplication)
            : $sickCurrentBalance);
        $vacationBalanceAfterApplication = $this->roundLeaveCreditValue($deductionAlreadyApplied
            ? $vacationCurrentBalance
            : ($vacationCurrentBalance - $vacationLessThisApplication));
        $sickBalanceAfterApplication = $this->roundLeaveCreditValue($deductionAlreadyApplied
            ? $sickCurrentBalance
            : ($sickCurrentBalance - $sickLessThisApplication));

        $snapshotPayload = [
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

        $selectedLeaveTypeDeduction = $this->resolveSelectedCertificationLeaveTypeDeduction($app);
        if ($selectedLeaveTypeDeduction !== null) {
            $selectedLeaveTypeCurrentBalance = $this->findLeaveTypeBalanceByNameInSnapshot(
                $leaveBalanceSnapshot,
                (string) $selectedLeaveTypeDeduction['leave_type_name']
            );

            $snapshotPayload[self::CERTIFICATION_SELECTED_LEAVE_TYPE_BUCKET] = $this->buildSelectedCertificationLeaveTypeBucket(
                (float) ($selectedLeaveTypeCurrentBalance ?? 0.0),
                $selectedLeaveTypeDeduction,
                $deductionAlreadyApplied
            );
        }

        return $snapshotPayload;
    }

    public function persistCertificationLeaveCreditsSnapshot(LeaveApplication $app, array $snapshot): void
    {
        if (! $this->hasLeaveApplicationCertificationCreditsSnapshotColumn()) {
            return;
        }

        $normalizedSnapshot = $this->normalizeCertificationLeaveCreditsPayload($snapshot, $app);
        if ($normalizedSnapshot === null) {
            return;
        }

        $encodedSnapshot = json_encode($normalizedSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedSnapshot === false) {
            return;
        }

        LeaveApplication::query()
            ->whereKey((int) $app->id)
            ->update([
                'certification_leave_credits_snapshot' => $encodedSnapshot,
            ]);

        $app->setAttribute('certification_leave_credits_snapshot', $normalizedSnapshot);
    }

    public function resolveStoredCertificationLeaveCreditsSnapshot(LeaveApplication $app): ?array
    {
        if (! $this->hasLeaveApplicationCertificationCreditsSnapshotColumn()) {
            return null;
        }

        $rawSnapshot = $app->certification_leave_credits_snapshot ?? null;
        if (is_string($rawSnapshot)) {
            $decodedSnapshot = json_decode($rawSnapshot, true);
            $rawSnapshot = json_last_error() === JSON_ERROR_NONE ? $decodedSnapshot : null;
        }

        if (! is_array($rawSnapshot)) {
            return null;
        }

        return $this->normalizeCertificationLeaveCreditsPayload($rawSnapshot, $app);
    }

    public function reconstructApprovedCertificationLeaveCredits(
        LeaveApplication $app,
        array $leaveBalanceSnapshot = [],
        ?callable $balanceSnapshotResolver = null,
        ?callable $controlNoCandidatesResolver = null,
        ?callable $primaryDeductionResolver = null,
        ?callable $monetizationComponentsResolver = null
    ): ?array {
        if (strtoupper(trim((string) ($app->status ?? ''))) !== LeaveApplication::STATUS_APPROVED) {
            return null;
        }

        $referenceDateTime = $app->hr_approved_at ?? $app->created_at;
        if ($referenceDateTime === null) {
            return null;
        }

        $employeeControlNo = trim((string) ($app->employee_control_no ?? ''));
        if ($employeeControlNo === '') {
            return null;
        }

        $snapshot = $leaveBalanceSnapshot !== []
            ? $leaveBalanceSnapshot
            : ($balanceSnapshotResolver !== null ? $balanceSnapshotResolver($app) : []);

        $currentVacationBalance = $this->findLeaveTypeBalanceByNameInSnapshot($snapshot, 'Vacation Leave');
        $currentSickBalance = $this->findLeaveTypeBalanceByNameInSnapshot($snapshot, 'Sick Leave');
        $selectedLeaveTypeDeduction = $this->resolveSelectedCertificationLeaveTypeDeduction($app);

        $controlCandidates = $controlNoCandidatesResolver !== null
            ? $controlNoCandidatesResolver($employeeControlNo)
            : [$employeeControlNo];

        $laterApprovedApplications = LeaveApplication::query()
            ->with('leaveType:id,name,is_credit_based')
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->whereIn('employee_control_no', $controlCandidates)
            ->where(function ($query) use ($referenceDateTime, $app): void {
                $query->where('hr_approved_at', '>', $referenceDateTime)
                    ->orWhere(function ($sameTimeQuery) use ($referenceDateTime, $app): void {
                        $sameTimeQuery->where('hr_approved_at', '=', $referenceDateTime)
                            ->where('id', '>', (int) $app->id);
                    });
            })
            ->get([
                'id',
                'leave_type_id',
                'total_days',
                'deductible_days',
                'linked_vacation_leave_deducted_days',
                'linked_sick_leave_deducted_days',
                'pay_mode',
                'is_monetization',
                'monetization_leave_credits',
                'status',
                'hr_approved_at',
            ]);

        $laterVacationDeductions = 0.0;
        $laterSickDeductions = 0.0;
        $laterSelectedLeaveTypeDeductions = 0.0;
        foreach ($laterApprovedApplications as $laterApplication) {
            if (! $laterApplication instanceof LeaveApplication) {
                continue;
            }

            $laterDeductions = $this->resolveCertificationLeaveDeductionsByType(
                $laterApplication,
                $primaryDeductionResolver,
                $monetizationComponentsResolver
            );
            $laterVacationDeductions += (float) ($laterDeductions['vacation'] ?? 0.0);
            $laterSickDeductions += (float) ($laterDeductions['sick'] ?? 0.0);

            if ($selectedLeaveTypeDeduction === null) {
                continue;
            }

            $laterSelectedLeaveTypeDeduction = $this->resolveSelectedCertificationLeaveTypeDeduction($laterApplication);
            if (
                $laterSelectedLeaveTypeDeduction !== null
                && strcasecmp(
                    (string) $laterSelectedLeaveTypeDeduction['leave_type_name'],
                    (string) $selectedLeaveTypeDeduction['leave_type_name']
                ) === 0
            ) {
                $laterSelectedLeaveTypeDeductions += (float) $laterSelectedLeaveTypeDeduction['less_this_application'];
            }
        }

        $appDeductions = $this->resolveCertificationLeaveDeductionsByType(
            $app,
            $primaryDeductionResolver,
            $monetizationComponentsResolver
        );
        $vacationLessThisApplication = (float) ($appDeductions['vacation'] ?? 0.0);
        $sickLessThisApplication = (float) ($appDeductions['sick'] ?? 0.0);

        $vacationBalanceAfterApplication = $this->roundLeaveCreditValue(
            $currentVacationBalance + $this->roundLeaveCreditValue($laterVacationDeductions)
        );
        $sickBalanceAfterApplication = $this->roundLeaveCreditValue(
            $currentSickBalance + $this->roundLeaveCreditValue($laterSickDeductions)
        );

        $vacationTotalEarned = $this->roundLeaveCreditValue(
            $vacationBalanceAfterApplication + $vacationLessThisApplication
        );
        $sickTotalEarned = $this->roundLeaveCreditValue(
            $sickBalanceAfterApplication + $sickLessThisApplication
        );

        $snapshotPayload = [
            'vacation' => [
                'total_earned' => $vacationTotalEarned,
                'less_this_application' => $vacationLessThisApplication,
                'balance' => $vacationBalanceAfterApplication,
                'balance_after_application' => $vacationBalanceAfterApplication,
            ],
            'sick' => [
                'total_earned' => $sickTotalEarned,
                'less_this_application' => $sickLessThisApplication,
                'balance' => $sickBalanceAfterApplication,
                'balance_after_application' => $sickBalanceAfterApplication,
            ],
            'as_of_date' => $referenceDateTime->format('F j, Y'),
        ];

        if ($selectedLeaveTypeDeduction !== null) {
            $currentSelectedLeaveTypeBalance = $this->findLeaveTypeBalanceByNameInSnapshot(
                $snapshot,
                (string) $selectedLeaveTypeDeduction['leave_type_name']
            );

            $selectedLeaveTypeBalanceAfterApplication = $this->roundLeaveCreditValue(
                (float) ($currentSelectedLeaveTypeBalance ?? 0.0)
                    + $this->roundLeaveCreditValue($laterSelectedLeaveTypeDeductions)
            );

            $snapshotPayload[self::CERTIFICATION_SELECTED_LEAVE_TYPE_BUCKET] = $this->buildSelectedCertificationLeaveTypeBucket(
                $selectedLeaveTypeBalanceAfterApplication,
                $selectedLeaveTypeDeduction,
                true
            );
        }

        return $snapshotPayload;
    }

    public function resolveCertificationLeaveCredits(
        LeaveApplication $app,
        array $leaveBalanceSnapshot = [],
        ?callable $balanceSnapshotResolver = null,
        ?callable $controlNoCandidatesResolver = null,
        ?callable $primaryDeductionResolver = null,
        ?callable $monetizationComponentsResolver = null,
        ?callable $hasPendingApprovedUpdateResolver = null
    ): array {
        $storedSnapshot = $this->resolveStoredCertificationLeaveCreditsSnapshot($app);
        $storedSnapshotNeedsRefresh = $storedSnapshot !== null
            && $this->shouldRefreshStoredCertificationLeaveCreditsSnapshot(
                $app,
                $storedSnapshot,
                $primaryDeductionResolver,
                $monetizationComponentsResolver
            );

        if ($storedSnapshot !== null && ! $storedSnapshotNeedsRefresh) {
            return $storedSnapshot;
        }

        $reconstructedSnapshot = $this->reconstructApprovedCertificationLeaveCredits(
            $app,
            $leaveBalanceSnapshot,
            $balanceSnapshotResolver,
            $controlNoCandidatesResolver,
            $primaryDeductionResolver,
            $monetizationComponentsResolver
        );
        if ($reconstructedSnapshot !== null) {
            $this->persistCertificationLeaveCreditsSnapshot($app, $reconstructedSnapshot);

            return $reconstructedSnapshot;
        }

        if ($storedSnapshot !== null) {
            return $storedSnapshotNeedsRefresh
                ? $this->buildCertificationLeaveCredits(
                    $app,
                    $leaveBalanceSnapshot,
                    $hasPendingApprovedUpdateResolver,
                    $primaryDeductionResolver,
                    $monetizationComponentsResolver
                )
                : $storedSnapshot;
        }

        return $this->buildCertificationLeaveCredits(
            $app,
            $leaveBalanceSnapshot,
            $hasPendingApprovedUpdateResolver,
            $primaryDeductionResolver,
            $monetizationComponentsResolver
        );
    }

    public function lockCertificationLeaveCreditsSnapshot(
        LeaveApplication $app,
        ?array $leaveBalanceSnapshot = null,
        ?callable $freshBalanceResolver = null,
        ?callable $primaryDeductionResolver = null,
        ?callable $monetizationComponentsResolver = null,
        ?callable $hasPendingApprovedUpdateResolver = null
    ): void {
        if (! $this->hasLeaveApplicationCertificationCreditsSnapshotColumn()) {
            return;
        }

        $app->loadMissing('leaveType');
        $resolvedSnapshot = $leaveBalanceSnapshot ?? ($freshBalanceResolver !== null ? $freshBalanceResolver($app) : []);
        $snapshot = $this->buildCertificationLeaveCredits(
            $app,
            $resolvedSnapshot,
            $hasPendingApprovedUpdateResolver,
            $primaryDeductionResolver,
            $monetizationComponentsResolver
        );
        $snapshot['as_of_date'] = $app->hr_approved_at?->format('F j, Y') ?? now()->format('F j, Y');

        $normalizedSnapshot = $this->normalizeCertificationLeaveCreditsPayload($snapshot, $app);
        if ($normalizedSnapshot === null) {
            return;
        }

        $encodedSnapshot = json_encode($normalizedSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encodedSnapshot === false) {
            return;
        }

        LeaveApplication::query()
            ->whereKey((int) $app->id)
            ->update([
                'certification_leave_credits_snapshot' => $encodedSnapshot,
            ]);

        $app->setAttribute('certification_leave_credits_snapshot', $normalizedSnapshot);
    }
}
