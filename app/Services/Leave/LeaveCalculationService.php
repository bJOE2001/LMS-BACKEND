<?php

namespace App\Services\Leave;

use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Services\WorkScheduleService;
use Carbon\CarbonImmutable;
use DateTimeInterface;

class LeaveCalculationService
{
    public function __construct(
        public WorkScheduleService $workScheduleService
    ) {}

    public function normalizeDateKey(mixed $rawDate): ?string
    {
        if ($rawDate === null || $rawDate === '') {
            return null;
        }

        if ($rawDate instanceof DateTimeInterface) {
            return CarbonImmutable::instance($rawDate)->toDateString();
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
            return CarbonImmutable::parse($raw)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    public function normalizePayMode(mixed $payMode, bool $isMonetization = false): string
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

    public function resolvePayModeFromStatusValue(mixed $value): ?string
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

    public function normalizeSelectedDateCoverageValue(mixed $value): ?string
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

    public function normalizeSelectedDateHalfDayPortionValue(mixed $value): ?string
    {
        $normalized = strtoupper(str_replace([' ', '-', '_', '(', ')'], '', trim((string) $value)));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'AM', 'MORNING', 'HALFDAYAM', 'HALFAM' => 'AM',
            'PM', 'AFTERNOON', 'HALFDAYPM', 'HALFPM' => 'PM',
            default => null,
        };
    }

    public function normalizeSelectedDateCoverageMap(mixed $value): ?array
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

    public function normalizeSelectedDateHalfDayPortionMap(mixed $value): ?array
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

    public function compactSelectedDateHalfDayPortionMap(
        ?array $selectedDateHalfDayPortion,
        ?array $selectedDates
    ): ?array {
        if (! is_array($selectedDateHalfDayPortion) || $selectedDateHalfDayPortion === []) {
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

            if ($restrictToSelectedDates && ! array_key_exists($dateKey, $dateSet)) {
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

    public function mergeSelectedDateHalfDayPortionMaps(?array $primaryMap, ?array $fallbackMap): ?array
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

    public function compactSelectedDateCoverageMap(
        ?array $selectedDateCoverage,
        ?array $selectedDates
    ): ?array {
        if (! is_array($selectedDateCoverage) || $selectedDateCoverage === []) {
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

            if ($restrictToSelectedDates && ! array_key_exists($dateKey, $dateSet)) {
                continue;
            }

            $coverage = strtolower(trim((string) $rawCoverage));
            if ($coverage === 'half') {
                $compacted[$dateKey] = 'half';

                continue;
            }

            if (! $restrictToSelectedDates && $coverage === 'whole') {
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

    public function synchronizeSelectedDateCoverageMetadata(
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

    public function compactSelectedDatePayStatusMap(
        ?array $selectedDatePayStatus,
        ?array $selectedDates,
        string $payMode
    ): ?array {
        if (! is_array($selectedDatePayStatus) || $selectedDatePayStatus === []) {
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

            if ($restrictToSelectedDates && ! array_key_exists($dateKey, $dateSet)) {
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

    public function computeDeductibleDays(
        float $totalDays,
        ?array $selectedDates,
        ?array $selectedDatePayStatus,
        ?array $selectedDateCoverage,
        bool $isMonetization,
        string $payMode,
        ?string $employeeControlNo = null
    ): float {
        $normalizedTotalDays = round(max($totalDays, 0.0), 3);
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

        $defaultWholeDayWeight = $this->workScheduleService->resolveCoverageDeductionDays('whole', $employeeControlNo);
        $defaultHalfDayWeight = $this->workScheduleService->resolveCoverageDeductionDays('half', $employeeControlNo);
        $defaultCoverageWeight = $defaultWholeDayWeight;
        $dateCount = count($resolvedDates);
        if ($dateCount > 0) {
            $halfMatch = abs(($dateCount * $defaultHalfDayWeight) - $normalizedTotalDays) < 0.00001;
            $wholeMatch = abs(((float) $dateCount) - $normalizedTotalDays) < 0.00001;
            if ($halfMatch) {
                $defaultCoverageWeight = $defaultHalfDayWeight;
            } elseif (! $wholeMatch) {
                $defaultCoverageWeight = round(max(min($normalizedTotalDays / $dateCount, $defaultWholeDayWeight), $defaultHalfDayWeight), 3);
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

        return round(max($weightedPaid, 0.0), 3);
    }

    public function isCtoLeaveType(?LeaveType $leaveType = null, ?int $leaveTypeId = null): bool
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

    public function resolveCtoLeaveTypeId(): ?int
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

    public function resolveCtoDeductedHours(float $deductibleDays): float
    {
        return round(max($deductibleDays, 0.0) * WorkScheduleService::STANDARD_WORKDAY_HOURS, 2);
    }

    public function resolveApplicationDeductibleDays(LeaveApplication $app): float
    {
        $totalDays = round((float) ($app->total_days ?? 0), 3);
        if ($totalDays <= 0) {
            return 0.0;
        }

        if (LeaveType::isForcedLeaveType($app->leaveType, (int) $app->leave_type_id) && $app->deductible_days === null) {
            return $totalDays;
        }

        if ($app->deductible_days !== null) {
            $stored = round((float) $app->deductible_days, 3);
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
            return round($storedCtoHours / WorkScheduleService::STANDARD_WORKDAY_HOURS, 3);
        }

        return $this->normalizePayMode($app->pay_mode ?? null, false) === LeaveApplication::PAY_MODE_WITHOUT_PAY
            ? 0.0
            : $totalDays;
    }

    public function resolveApplicationCtoDeductedHours(LeaveApplication $app): float
    {
        $storedHours = round((float) ($app->cto_deducted_hours ?? 0), 2);
        if ($storedHours > 0) {
            return $storedHours;
        }

        if (! $this->isCtoLeaveType($app->leaveType, (int) $app->leave_type_id)) {
            return 0.0;
        }

        return $this->resolveCtoDeductedHours($this->resolveApplicationDeductibleDays($app));
    }
}
