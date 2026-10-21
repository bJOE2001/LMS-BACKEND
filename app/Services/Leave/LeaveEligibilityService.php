<?php

namespace App\Services\Leave;

use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;

class LeaveEligibilityService
{
    public const CTO_MIN_WORKING_DAYS_BEFORE_AVAILMENT = 3;

    public const VL_MIN_WORKING_DAYS_BEFORE_AVAILMENT = 3;

    public const APPROVED_LEAVE_REQUEST_MIN_WORKING_DAYS_BEFORE_START = 5;

    public const SICK_LEAVE_FILING_WINDOW_WORKING_DAYS = 5;

    public function __construct(
        public LeaveCalculationService $calculationService
    ) {}

    public static function formatDays(mixed $days): string
    {
        $num = (float) $days;
        $display = ($num == (int) $num) ? (int) $num : $num;

        return $display.' '.($num == 1 ? 'day' : 'days');
    }

    public function countWorkingDaysFromNextDay(
        CarbonImmutable $lastAbsentDate,
        CarbonImmutable $filedDate
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

    public function countWorkingDaysFromFiledDateBeforeDate(
        CarbonImmutable $filedDate,
        CarbonImmutable $targetDate
    ): int {
        $normalizedFiledDate = $filedDate->startOfDay();
        $normalizedTargetDate = $targetDate->startOfDay();
        if ($normalizedTargetDate->lte($normalizedFiledDate)) {
            return 0;
        }

        $workingDays = 0;
        $cursor = $normalizedFiledDate;

        while ($cursor->lt($normalizedTargetDate)) {
            if ($cursor->isWeekday()) {
                $workingDays++;
            }

            $cursor = $cursor->addDay();
        }

        return $workingDays;
    }

    public function calculateMaxConsecutiveDateSpan(array $selectedDateKeys): int
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
            static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp()
        );

        $maxStreak = 1;
        $currentStreak = 1;

        for ($index = 1, $count = count($parsedDates); $index < $count; $index++) {
            $expectedNextDate = $parsedDates[$index - 1]->addDay()->startOfDay();
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

    public function resolvePolicyFilingDate(mixed $filedAt = null): CarbonImmutable
    {
        if ($filedAt instanceof DateTimeInterface) {
            return CarbonImmutable::instance($filedAt)->startOfDay();
        }

        $raw = trim((string) ($filedAt ?? ''));
        if ($raw !== '') {
            try {
                return CarbonImmutable::parse($raw)->startOfDay();
            } catch (\Throwable) {
                // Fall back to now() when parsing fails.
            }
        }

        return CarbonImmutable::now()->startOfDay();
    }

    public function resolveIsoDate(?string $rawDate): ?CarbonImmutable
    {
        $raw = trim((string) ($rawDate ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function resolveEffectiveLeaveStartDate(LeaveApplication $app): ?CarbonImmutable
    {
        $candidateDate = null;
        if (! empty($app->selected_dates) && is_array($app->selected_dates)) {
            $sortedDates = collect($app->selected_dates)->filter()->sort()->values();
            if ($sortedDates->isNotEmpty()) {
                $candidateDate = (string) $sortedDates->first();
            }
        }

        if (! $candidateDate && $app->start_date) {
            $candidateDate = $app->start_date instanceof DateTimeInterface
                ? $app->start_date->format('Y-m-d')
                : (string) $app->start_date;
        }

        if (! $candidateDate) {
            return null;
        }

        try {
            return CarbonImmutable::parse($candidateDate)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public function resolveSickLeaveAbsenceDateRange(?array $selectedDates): array
    {
        if (! is_array($selectedDates) || $selectedDates === []) {
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
                $parsed = CarbonImmutable::parse($dateKey)->startOfDay();
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

    public function normalizeSelectedDateKeys(?array $selectedDates): array
    {
        if (! is_array($selectedDates) || $selectedDates === []) {
            return [];
        }

        $normalized = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->calculationService->normalizeDateKey($rawDate);
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

    public function computeCoverageDayTotal(array $selectedDateKeys, ?array $selectedDateCoverage): float
    {
        if ($selectedDateKeys === []) {
            return 0.0;
        }

        $normalizedCoverage = $this->calculationService->compactSelectedDateCoverageMap($selectedDateCoverage, $selectedDateKeys);
        $total = 0.0;
        foreach ($selectedDateKeys as $dateKey) {
            $coverage = strtolower(trim((string) ($normalizedCoverage[$dateKey] ?? 'whole')));
            $total += $coverage === 'half' ? 0.5 : 1.0;
        }

        return round($total, 2);
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

    public function validateApprovedLeaveRequestLeadTime(LeaveApplication $app, string $actionLabel = 'cancel'): ?JsonResponse
    {
        if ($app->status !== LeaveApplication::STATUS_APPROVED) {
            return null;
        }

        if ((bool) $app->is_monetization) {
            return null;
        }

        $startDate = $this->resolveEffectiveLeaveStartDate($app);
        if (! $startDate) {
            return null;
        }

        $today = CarbonImmutable::today();
        $workingDaysBeforeStart = $this->countWorkingDaysFromFiledDateBeforeDate($today, $startDate);

        if ($workingDaysBeforeStart < self::APPROVED_LEAVE_REQUEST_MIN_WORKING_DAYS_BEFORE_START) {
            $actionVerb = strtolower($actionLabel) === 'update' ? 'edit or update' : 'cancel';

            return response()->json([
                'message' => "Requests to {$actionVerb} an approved leave application must be submitted at least ".self::APPROVED_LEAVE_REQUEST_MIN_WORKING_DAYS_BEFORE_START.' working days before the leave date. Please contact the HR.',
            ], 422);
        }

        return null;
    }

    public function validateCtoAvailmentPolicy(
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
        $workingDaysBeforeAvailment = $this->countWorkingDaysFromFiledDateBeforeDate($filedDate, $firstAvailmentDate);
        if ($workingDaysBeforeAvailment < self::CTO_MIN_WORKING_DAYS_BEFORE_AVAILMENT) {
            $message = 'CTO applications must be submitted at least '
                .self::CTO_MIN_WORKING_DAYS_BEFORE_AVAILMENT
                .' working days before the first availment date.';

            return response()->json([
                'message' => $message,
                'errors' => [
                    'start_date' => [$message],
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

    public function validateVacationLeaveAdvanceFilingPolicy(
        ?array $selectedDates,
        ?array $selectedDatePayStatus,
        string $payMode,
        mixed $filedAt = null,
        bool $allowPayStatusOverride = false,
        ?LeaveType $leaveType = null,
        bool $enforceAdvanceFiling = true
    ): ?JsonResponse {
        if ($allowPayStatusOverride) {
            return null;
        }

        $normalizedDateKeys = $this->normalizeSelectedDateKeys($selectedDates);
        if ($normalizedDateKeys === []) {
            return null;
        }

        $filedDate = $this->resolvePolicyFilingDate($filedAt);
        $normalizedFiledDate = $filedDate->startOfDay();

        $shortNoticeDates = [];
        $latePastDates = [];

        foreach ($normalizedDateKeys as $dateKey) {
            $availmentDate = $this->resolveIsoDate($dateKey);
            if ($availmentDate === null) {
                continue;
            }

            $normalizedAvailmentDate = $availmentDate->startOfDay();

            $isWithPay = false;
            if (is_array($selectedDatePayStatus) && array_key_exists($dateKey, $selectedDatePayStatus)) {
                $statusVal = $selectedDatePayStatus[$dateKey];
                $resolvedMode = $this->calculationService->resolvePayModeFromStatusValue($statusVal);
                if ($resolvedMode !== null) {
                    $isWithPay = $resolvedMode === LeaveApplication::PAY_MODE_WITH_PAY;
                } else {
                    $isWithPay = $statusVal === true || $statusVal === LeaveApplication::PAY_MODE_WITH_PAY;
                }
            } else {
                $isWithPay = $this->calculationService->normalizePayMode($payMode, false) === LeaveApplication::PAY_MODE_WITH_PAY;
            }

            if ($normalizedAvailmentDate->lt($normalizedFiledDate)) {
                if ($isWithPay) {
                    $latePastDates[] = $availmentDate->format('M j, Y');
                }
            } elseif ($enforceAdvanceFiling) {
                $workingDaysBeforeAvailment = $this->countWorkingDaysFromFiledDateBeforeDate($filedDate, $availmentDate);

                if ($workingDaysBeforeAvailment < self::VL_MIN_WORKING_DAYS_BEFORE_AVAILMENT) {
                    if ($isWithPay) {
                        $shortNoticeDates[] = $availmentDate->format('M j, Y');
                    }
                }
            }
        }

        if (count($shortNoticeDates) > 0 && count($latePastDates) > 0) {
            $allFormattedDates = implode(', ', array_merge($latePastDates, $shortNoticeDates));
            $typeName = $leaveType?->name ?? 'Leave';
            $message = "{$typeName} for past dates or applied less than "
                .self::VL_MIN_WORKING_DAYS_BEFORE_AVAILMENT
                ." working days prior to the intended leave date must be filed as Without Pay (WOP). Please change the pay status for: {$allFormattedDates}.";

            return response()->json([
                'message' => $message,
                'errors' => [
                    'selected_date_pay_status' => [$message],
                ],
            ], 422);
        }

        if (count($shortNoticeDates) > 0) {
            $formattedDates = implode(', ', $shortNoticeDates);
            $typeName = $leaveType?->name ?? 'Leave';
            $message = "{$typeName} applied less than "
                .self::VL_MIN_WORKING_DAYS_BEFORE_AVAILMENT
                ." working days prior to the intended leave date must be filed as Without Pay (WOP). Please change the pay status for: {$formattedDates}.";

            return response()->json([
                'message' => $message,
                'errors' => [
                    'selected_date_pay_status' => [$message],
                ],
            ], 422);
        }

        if (count($latePastDates) > 0) {
            $formattedDates = implode(', ', $latePastDates);
            $typeName = $leaveType?->name ?? 'Leave';
            $message = "{$typeName} for past dates must be filed as Without Pay (WOP). Please change the pay status for: {$formattedDates}.";

            return response()->json([
                'message' => $message,
                'errors' => [
                    'selected_date_pay_status' => [$message],
                ],
            ], 422);
        }

        return null;
    }

    public function resolveSickLeavePayModeFromFilingWindow(
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
        if (! $startDate || ! $lastAbsentDate) {
            return LeaveApplication::PAY_MODE_WITH_PAY;
        }

        $filedDate = $this->resolvePolicyFilingDate($filedAt);
        if ($filedDate->lt($startDate)) {
            return LeaveApplication::PAY_MODE_WITH_PAY;
        }

        $workingDaysElapsed = $this->countWorkingDaysFromNextDay($lastAbsentDate, $filedDate);

        return $workingDaysElapsed <= self::SICK_LEAVE_FILING_WINDOW_WORKING_DAYS
            ? LeaveApplication::PAY_MODE_WITH_PAY
            : LeaveApplication::PAY_MODE_WITHOUT_PAY;
    }

    public function hasWithoutPaySelectedDates(?array $selectedDatePayStatus): bool
    {
        if (! is_array($selectedDatePayStatus) || $selectedDatePayStatus === []) {
            return false;
        }

        foreach ($selectedDatePayStatus as $status) {
            if ($this->calculationService->resolvePayModeFromStatusValue($status) === LeaveApplication::PAY_MODE_WITHOUT_PAY) {
                return true;
            }
        }

        return false;
    }

    public function validateMonetizationLeaveTypeEligibility(?LeaveType $leaveType, string $errorKey = 'leave_type_id'): ?JsonResponse
    {
        $isEligibleMonetizationType = $leaveType instanceof LeaveType
            && (
                $this->isVacationLeaveType($leaveType, (int) $leaveType->id)
                || $this->isSickLeaveType($leaveType, (int) $leaveType->id)
            );

        if (! $isEligibleMonetizationType) {
            return response()->json([
                'message' => 'Monetization is only allowed for Vacation Leave or Sick Leave.',
                'errors' => [
                    $errorKey => ['Monetization is only allowed for Vacation Leave or Sick Leave.'],
                ],
            ], 422);
        }

        return null;
    }

    public function validateMonetizationRequestMinimum(float $requestedDays, string $errorKey = 'total_days'): ?JsonResponse
    {
        $normalizedRequestedDays = round(max($requestedDays, 0.0), 3);
        if ($normalizedRequestedDays + 1e-9 < LeaveApplication::MONETIZATION_MINIMUM_REQUEST_DAYS) {
            $minimumDays = self::formatDays(LeaveApplication::MONETIZATION_MINIMUM_REQUEST_DAYS);

            return response()->json([
                'message' => "A minimum of {$minimumDays} is required for monetization.",
                'errors' => [
                    $errorKey => ["A minimum of {$minimumDays} is required for monetization."],
                ],
            ], 422);
        }

        return null;
    }

    public function validateMonetizationPolicyRules(?LeaveType $leaveType, float $requestedDays): ?JsonResponse
    {
        $eligibilityRestriction = $this->validateMonetizationLeaveTypeEligibility($leaveType);
        if ($eligibilityRestriction instanceof JsonResponse) {
            return $eligibilityRestriction;
        }

        return $this->validateMonetizationRequestMinimum($requestedDays);
    }

    public function validateMonetizationBalanceRules(
        float $currentBalance,
        float $requestedDays,
        string $errorKey = 'total_days'
    ): ?JsonResponse {
        $normalizedCurrentBalance = round(max($currentBalance, 0.0), 3);
        $normalizedRequestedDays = round(max($requestedDays, 0.0), 3);
        $requiredMinimumBalance = LeaveApplication::MONETIZATION_MINIMUM_VACATION_LEAVE_BALANCE_DAYS;

        if ($normalizedCurrentBalance + 1e-9 < $requiredMinimumBalance) {
            return response()->json([
                'message' => 'At least '
                    .self::formatDays($requiredMinimumBalance)
                    .' of leave credits are required to apply monetization.',
                'errors' => [
                    $errorKey => [
                        'At least '
                        .self::formatDays($requiredMinimumBalance)
                        .' of leave credits are required. Current balance: '
                        .self::formatDays($normalizedCurrentBalance)
                        .'.',
                    ],
                ],
            ], 422);
        }

        if ($normalizedRequestedDays > $normalizedCurrentBalance + 1e-9) {
            return response()->json([
                'message' => 'Requested monetization days exceed available leave credits.',
                'errors' => [
                    $errorKey => [
                        'Requested monetization days ('
                        .self::formatDays($normalizedRequestedDays)
                        .') exceed available leave credits ('
                        .self::formatDays($normalizedCurrentBalance)
                        .').',
                    ],
                ],
            ], 422);
        }

        return null;
    }

    public function assertEmployeeCanApplyForLeaveType(
        object $employee,
        LeaveType $leaveType,
        bool $skipEventBasedReuseRule = false
    ): ?JsonResponse {
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

    public function assertEmployeeCanAccessLeaveTypeBalance(object $employee, LeaveType $leaveType): ?JsonResponse
    {
        return $this->assertEmployeeCanUseConfiguredLeaveTypeRule(
            $employee,
            $leaveType,
            'This leave type balance is not available for the selected employee status.'
        );
    }

    public function assertEmployeeCanUseConfiguredLeaveTypeRule(
        object $employee,
        LeaveType $leaveType,
        string $fallbackMessage
    ): ?JsonResponse {
        if (! $this->employeeHasResolvedEmploymentStatus($employee)) {
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

    public function assertEmployeeCanReuseEventBasedLeaveType(object $employee, LeaveType $leaveType): ?JsonResponse
    {
        if (! $this->shouldEnforceEventBasedMaxDaysReuseRule($leaveType)) {
            return null;
        }

        $activeApprovedApplication = $this->findActiveApprovedEventBasedLeaveApplication($employee, (int) $leaveType->id);
        if (! $activeApprovedApplication instanceof LeaveApplication) {
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

    public function shouldEnforceEventBasedMaxDaysReuseRule(LeaveType $leaveType): bool
    {
        $category = strtoupper(trim((string) ($leaveType->category ?? '')));
        $maxDays = round((float) ($leaveType->max_days ?? 0), 2);

        return $category === LeaveType::CATEGORY_EVENT && $maxDays > 0.0;
    }

    public function findActiveApprovedEventBasedLeaveApplication(object $employee, int $leaveTypeId): ?LeaveApplication
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

    public function resolveEmployeeControlNoForLeaveTypeRule(object $employee): ?string
    {
        $rawControlNo = trim((string) (
            $employee->control_no
            ?? $employee->employee_control_no
            ?? $employee->erms_control_no
            ?? ''
        ));

        return $rawControlNo !== '' ? $rawControlNo : null;
    }

    public function resolveApplicationLastLeaveDate(LeaveApplication $application): ?string
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

    public function employeeHasResolvedEmploymentStatus(object $employee): bool
    {
        return $this->resolveEmploymentStatusKey($employee->status ?? null) !== null;
    }

    public function resolveEmploymentStatusKey(?string $status): ?string
    {
        return LeaveType::normalizeEmploymentStatusKey($status);
    }

    public function formatEmploymentStatusLabel(?string $status): ?string
    {
        $formatted = LeaveType::formatEmploymentStatusLabel($status);
        if ($formatted !== null) {
            return $formatted;
        }

        $trimmed = trim((string) ($status ?? ''));

        return $trimmed !== '' ? $trimmed : null;
    }

    public function controlNoCandidates(string $controlNo): array
    {
        $rawControlNo = trim($controlNo);
        if ($rawControlNo === '') {
            return [];
        }

        $candidates = [
            $rawControlNo,
            $this->normalizeControlNo($rawControlNo),
        ];

        $employee = HrisEmployee::findByControlNo($rawControlNo);
        if ($employee && trim((string) ($employee->control_no ?? '')) !== '') {
            $candidates[] = trim((string) $employee->control_no);
        }

        return array_values(array_unique(array_filter(
            $candidates,
            static fn (string $value): bool => trim($value) !== ''
        )));
    }

    public function normalizeControlNo(mixed $value): string
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

    public function resolveBalanceLookupLeaveTypeIds(int $leaveTypeId): array
    {
        if ($leaveTypeId <= 0) {
            return [];
        }

        if (LeaveType::isSpecialPrivilegeType(null, $leaveTypeId)) {
            $relatedLeaveTypeIds = LeaveType::resolveSpecialPrivilegeRelatedTypeIds();
            if ($relatedLeaveTypeIds !== []) {
                return $relatedLeaveTypeIds;
            }
        }

        return [$leaveTypeId];
    }
}
