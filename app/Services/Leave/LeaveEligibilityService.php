<?php

namespace App\Services\Leave;

use App\Http\Controllers\Concerns\FiltersEmployeeControlNos;
use App\Models\DepartmentAdmin;
use App\Models\EmployeeDepartmentAssignment;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class LeaveEligibilityService
{
    use FiltersEmployeeControlNos;

    public const CTO_MIN_WORKING_DAYS_BEFORE_AVAILMENT = 3;

    public const VL_MIN_WORKING_DAYS_BEFORE_AVAILMENT = 3;

    public const APPROVED_LEAVE_REQUEST_MIN_WORKING_DAYS_BEFORE_START = 5;

    public const SICK_LEAVE_FILING_WINDOW_WORKING_DAYS = 5;

    public function __construct(
        public LeaveCalculationService $calculationService,
        public ?LeaveCreditService $creditService = null
    ) {
        $this->creditService = $creditService ?? app(LeaveCreditService::class);
    }

    private function creditService(): LeaveCreditService
    {
        return $this->creditService ??= app(LeaveCreditService::class);
    }

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

    public function controlNoCandidates(string $controlNo, ?callable $employeeFinder = null): array
    {
        $rawControlNo = trim($controlNo);
        if ($rawControlNo === '') {
            return [];
        }

        $candidates = [
            $rawControlNo,
            $this->normalizeControlNo($rawControlNo),
        ];

        $employeeFinder ??= fn (string $no): ?object => HrisEmployee::findByControlNo($no);
        $employee = $employeeFinder($rawControlNo);
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

    public function isWeekendDateKey(string $dateKey): bool
    {
        try {
            $dayOfWeek = CarbonImmutable::parse($dateKey)->dayOfWeek;
        } catch (\Throwable) {
            return false;
        }

        return $dayOfWeek === 0 || $dayOfWeek === 6;
    }

    public function normalizeVacationDetailValue(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) ($value ?? '')));
        if ($normalized === '') {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9]+/', ' ', $normalized) ?? $normalized;
        $normalized = trim($normalized);
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, 'abroad')) {
            return 'Abroad';
        }

        if (str_contains($normalized, 'within') && str_contains($normalized, 'philippines')) {
            return 'Within the Philippines';
        }

        return null;
    }

    public function normalizeDetailsOfLeavePayload(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            $decodedValue = json_decode(trim($value), true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decodedValue)) {
                return null;
            }
            $value = $decodedValue;
        }

        if (! is_array($value)) {
            return null;
        }

        $fields = [
            'vacation_location',
            'vacation_detail',
            'sick_location',
            'sick_detail',
            'study_detail',
            'other_purpose',
            'commutation',
        ];

        $normalizedPayload = [];
        foreach ($fields as $fieldName) {
            $raw = $value[$fieldName] ?? null;
            $fieldValue = $raw !== null && trim((string) $raw) !== '' ? trim((string) $raw) : null;
            if ($fieldValue !== null) {
                $normalizedPayload[$fieldName] = $fieldValue;
            }
        }

        return $normalizedPayload === [] ? null : $normalizedPayload;
    }

    public function encodeDetailsOfLeavePayload(array $payload): ?string
    {
        $encodedPayload = json_encode($payload, JSON_UNESCAPED_UNICODE);

        return $encodedPayload === false ? null : $encodedPayload;
    }

    public function resolveVacationDetailFromDetailsOfLeave(mixed $detailsOfLeave): ?string
    {
        $normalizedPayload = $this->normalizeDetailsOfLeavePayload($detailsOfLeave);
        if (! is_array($normalizedPayload)) {
            return null;
        }

        return $this->normalizeVacationDetailValue($normalizedPayload['vacation_detail'] ?? null);
    }

    public function shouldForceAbroadWeekendWithoutPay(
        ?LeaveType $leaveType = null,
        ?int $leaveTypeId = null,
        mixed $detailsOfLeave = null
    ): bool {
        $resolvedLeaveTypeId = $leaveTypeId !== null && $leaveTypeId > 0
            ? $leaveTypeId
            : (($leaveType?->id !== null && (int) $leaveType->id > 0) ? (int) $leaveType->id : null);

        $isEligibleLeaveType = $this->isVacationLeaveType($leaveType, $resolvedLeaveTypeId)
            || LeaveType::isSpecialPrivilegeType($leaveType, $resolvedLeaveTypeId);

        if (! $isEligibleLeaveType) {
            return false;
        }

        return $this->resolveVacationDetailFromDetailsOfLeave($detailsOfLeave) === 'Abroad';
    }

    public function filterOutAbroadWeekendDates(
        ?array $selectedDates,
        ?LeaveType $leaveType = null,
        ?int $leaveTypeId = null,
        mixed $detailsOfLeave = null
    ): ?array {
        if (! $this->shouldForceAbroadWeekendWithoutPay($leaveType, $leaveTypeId, $detailsOfLeave)) {
            return $selectedDates;
        }

        if (! is_array($selectedDates) || $selectedDates === []) {
            return $selectedDates;
        }

        $filteredDates = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->calculationService->normalizeDateKey($rawDate) ?? trim((string) $rawDate);
            if ($dateKey !== '' && $this->isWeekendDateKey($dateKey)) {
                continue;
            }
            $filteredDates[] = $rawDate;
        }

        return $filteredDates;
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

    public function resolvePayModeFromSelectedDates(
        ?array $selectedDates,
        ?array $selectedDatePayStatus,
        string $fallbackPayMode
    ): string {
        $normalizedFallbackPayMode = $this->calculationService->normalizePayMode($fallbackPayMode, false);
        $selectedDateStatusMap = is_array($selectedDatePayStatus) ? $selectedDatePayStatus : [];
        if (! is_array($selectedDates) || $selectedDates === []) {
            return $normalizedFallbackPayMode;
        }

        $selectedDateLookup = [];
        foreach ($selectedDates as $rawDate) {
            $dateKey = $this->calculationService->normalizeDateKey($rawDate) ?? trim((string) $rawDate);
            if ($dateKey === '') {
                continue;
            }

            $selectedDateLookup[$dateKey] = true;
            $resolvedMode = $this->calculationService->resolvePayModeFromStatusValue($selectedDateStatusMap[$dateKey] ?? null)
                ?? $normalizedFallbackPayMode;
            if ($resolvedMode === LeaveApplication::PAY_MODE_WITH_PAY) {
                return LeaveApplication::PAY_MODE_WITH_PAY;
            }
        }

        return $selectedDateLookup === []
            ? $normalizedFallbackPayMode
            : LeaveApplication::PAY_MODE_WITHOUT_PAY;
    }

    public function applyRegularLeavePolicy(
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
        ?string $employeeControlNo = null,
        bool $allowPayStatusOverride = false,
        ?string $detailsOfLeave = null
    ): array|JsonResponse {
        $normalizedTotalDays = round(max((float) $totalDays, 0.0), 3);

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

        $normalizedPayMode = $this->calculationService->normalizePayMode($payMode, false);
        $normalizedSelectedDatePayStatus = is_array($selectedDatePayStatus)
            ? $selectedDatePayStatus
            : null;
        $normalizedSelectedDateCoverage = is_array($selectedDateCoverage)
            ? $selectedDateCoverage
            : null;

        $isSickLeave = $this->isSickLeaveType($leaveType, (int) $leaveType->id);
        $isVacationLeave = (int) $leaveType->id === $this->resolveVacationLeaveTypeId();
        $isCtoLeave = $this->calculationService->isCtoLeaveType($leaveType, (int) $leaveType->id);
        $isForcedLeave = LeaveType::isForcedLeaveType($leaveType, (int) $leaveType->id);
        $isEventLeave = strtoupper(trim((string) ($leaveType->category ?? ''))) === LeaveType::CATEGORY_EVENT;
        $attachmentRequired = $isSickLeave
            ? $normalizedTotalDays >= 5.0
            : (bool) ($leaveType->requires_documents ?? false);

        if ($enforceAttachment && $attachmentRequired && ! $attachmentSubmitted) {
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

        $deductibleSelectedDates = $this->filterOutAbroadWeekendDates(
            $selectedDates,
            $leaveType,
            (int) $leaveType->id,
            $detailsOfLeave
        );

        if ($isForcedLeave) {
            $flValidation = $this->validateVacationLeaveAdvanceFilingPolicy(
                $selectedDates,
                $normalizedSelectedDatePayStatus,
                $normalizedPayMode,
                $filedAt,
                $allowPayStatusOverride,
                $leaveType,
                true
            );
            if ($flValidation instanceof JsonResponse) {
                return $flValidation;
            }

            $normalizedPayMode = LeaveApplication::PAY_MODE_WITH_PAY;

            $deductibleDays = $this->calculationService->computeDeductibleDays(
                $normalizedTotalDays,
                $deductibleSelectedDates,
                $normalizedSelectedDatePayStatus,
                $normalizedSelectedDateCoverage,
                false,
                $normalizedPayMode,
                $employeeControlNo
            );

            if (! $attachmentRequired) {
                $attachmentSubmitted = false;
                $attachmentReference = null;
            }

            $ctoDeductedHours = null;
        } elseif ($isCtoLeave) {
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
            $deductibleDays = $this->calculationService->computeDeductibleDays(
                $normalizedTotalDays,
                $deductibleSelectedDates,
                null,
                $normalizedSelectedDateCoverage,
                false,
                $normalizedPayMode,
                $employeeControlNo
            );

            if (! $attachmentRequired) {
                $attachmentSubmitted = false;
                $attachmentReference = null;
            }

            $ctoDeductedHours = $this->calculationService->resolveCtoDeductedHours($deductibleDays);
        } elseif ($isEventLeave) {
            $normalizedPayMode = LeaveApplication::PAY_MODE_WITH_PAY;
            $normalizedSelectedDatePayStatus = null;

            $deductibleDays = $this->calculationService->computeDeductibleDays(
                $normalizedTotalDays,
                $deductibleSelectedDates,
                null,
                $normalizedSelectedDateCoverage,
                false,
                $normalizedPayMode,
                $employeeControlNo
            );

            if (! $attachmentRequired) {
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

            if ($graceWindowPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY && ! $allowPayStatusOverride) {
                $normalizedPayMode = LeaveApplication::PAY_MODE_WITHOUT_PAY;
                $normalizedSelectedDatePayStatus = null;
            } else {
                $normalizedSelectedDatePayStatus = $this->calculationService->compactSelectedDatePayStatusMap(
                    $normalizedSelectedDatePayStatus,
                    $selectedDates,
                    $normalizedPayMode
                );
            }

            $deductibleDays = $this->calculationService->computeDeductibleDays(
                $normalizedTotalDays,
                $deductibleSelectedDates,
                $normalizedSelectedDatePayStatus,
                $normalizedSelectedDateCoverage,
                false,
                $normalizedPayMode,
                $employeeControlNo
            );
            $ctoDeductedHours = null;
        } elseif ($isVacationLeave) {
            $vlValidation = $this->validateVacationLeaveAdvanceFilingPolicy(
                $selectedDates,
                $normalizedSelectedDatePayStatus,
                $normalizedPayMode,
                $filedAt,
                $allowPayStatusOverride,
                $leaveType,
                true
            );
            if ($vlValidation instanceof JsonResponse) {
                return $vlValidation;
            }

            $normalizedSelectedDatePayStatus = $this->calculationService->compactSelectedDatePayStatusMap(
                $normalizedSelectedDatePayStatus,
                $selectedDates,
                $normalizedPayMode
            );

            $deductibleDays = $this->calculationService->computeDeductibleDays(
                $normalizedTotalDays,
                $deductibleSelectedDates,
                $normalizedSelectedDatePayStatus,
                $normalizedSelectedDateCoverage,
                false,
                $normalizedPayMode,
                $employeeControlNo
            );

            if (! $attachmentRequired) {
                $attachmentSubmitted = false;
                $attachmentReference = null;
            }

            $ctoDeductedHours = null;
        } else {
            $otherValidation = $this->validateVacationLeaveAdvanceFilingPolicy(
                $selectedDates,
                $normalizedSelectedDatePayStatus,
                $normalizedPayMode,
                $filedAt,
                $allowPayStatusOverride,
                $leaveType,
                true
            );
            if ($otherValidation instanceof JsonResponse) {
                return $otherValidation;
            }

            $normalizedSelectedDatePayStatus = $this->calculationService->compactSelectedDatePayStatusMap(
                $normalizedSelectedDatePayStatus,
                $selectedDates,
                $normalizedPayMode
            );

            $deductibleDays = $this->calculationService->computeDeductibleDays(
                $normalizedTotalDays,
                $deductibleSelectedDates,
                $normalizedSelectedDatePayStatus,
                $normalizedSelectedDateCoverage,
                false,
                $normalizedPayMode,
                $employeeControlNo
            );

            if (! $attachmentRequired) {
                $attachmentSubmitted = false;
                $attachmentReference = null;
            }

            $ctoDeductedHours = null;
        }

        $normalizedPayMode = $this->resolvePayModeFromSelectedDates(
            $selectedDates,
            $normalizedSelectedDatePayStatus,
            $normalizedPayMode
        );
        $normalizedSelectedDatePayStatus = $this->calculationService->compactSelectedDatePayStatusMap(
            $normalizedSelectedDatePayStatus,
            $selectedDates,
            $normalizedPayMode
        );

        if (! $attachmentSubmitted) {
            $attachmentReference = null;
        }

        $trimmedReference = $attachmentReference !== null && trim($attachmentReference) !== ''
            ? trim($attachmentReference)
            : null;

        return [
            'pay_mode' => $normalizedPayMode,
            'selected_date_pay_status' => $normalizedSelectedDatePayStatus,
            'deductible_days' => round(max((float) $deductibleDays, 0.0), 3),
            'cto_deducted_hours' => $ctoDeductedHours !== null ? round(max((float) $ctoDeductedHours, 0.0), 2) : null,
            'attachment_required' => $attachmentRequired,
            'attachment_submitted' => $attachmentSubmitted,
            'attachment_reference' => $trimmedReference,
        ];
    }

    public function normalizeOffice(mixed $value): string
    {
        return strtoupper(trim((string) ($value ?? '')));
    }

    public function sameOffice(mixed $left, mixed $right): bool
    {
        $leftOffice = $this->normalizeOffice($left);
        $rightOffice = $this->normalizeOffice($right);

        return $leftOffice !== '' && $leftOffice === $rightOffice;
    }

    public function isPulledEmployeeControlNo(string $controlNo, ?callable $candidateResolver = null): bool
    {
        $candidateResolver ??= fn (string $no): array => $this->controlNoCandidates($no);
        $candidates = $candidateResolver($controlNo);
        if ($candidates === []) {
            return false;
        }

        return EmployeeDepartmentAssignment::query()
            ->whereIn('employee_control_no', $candidates)
            ->exists();
    }

    public function isDepartmentAdminEmployeeControlNo(string $controlNo, ?callable $candidateResolver = null): bool
    {
        $candidateResolver ??= fn (string $no): array => $this->controlNoCandidates($no);
        $candidates = $candidateResolver($controlNo);
        if ($candidates === []) {
            return false;
        }

        return DepartmentAdmin::query()
            ->whereIn('employee_control_no', $candidates)
            ->whereHas('department', fn ($query) => $query->where('is_inactive', false))
            ->exists();
    }

    public function assertErmsEmployeeIsPulled(string $controlNo, ?callable $candidateResolver = null): ?JsonResponse
    {
        if (
            $this->isPulledEmployeeControlNo($controlNo, $candidateResolver)
            || $this->isDepartmentAdminEmployeeControlNo($controlNo, $candidateResolver)
        ) {
            return null;
        }

        $normalized = trim($controlNo);

        return response()->json([
            'employee_control_no' => $normalized !== '' ? $normalized : null,
            'message' => 'This employee is not yet enabled for LMS leave modules. Please contact your department admin.',
            'is_pulled_employee' => false,
            'is_department_admin_employee' => false,
            'can_access_leave_modules' => false,
        ], 403);
    }

    public function resolveLatestAssignmentDepartmentMap(): array
    {
        $latestDepartmentByControlNo = [];

        $rows = EmployeeDepartmentAssignment::query()
            ->select(['employee_control_no', 'department_id', 'assigned_at', 'id'])
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->get();

        foreach ($rows as $row) {
            $normalizedControlNo = $this->normalizeControlNo((string) ($row->employee_control_no ?? ''));
            if ($normalizedControlNo === '' || array_key_exists($normalizedControlNo, $latestDepartmentByControlNo)) {
                continue;
            }

            $departmentId = (int) ($row->department_id ?? 0);
            if ($departmentId <= 0) {
                continue;
            }

            $latestDepartmentByControlNo[$normalizedControlNo] = $departmentId;
        }

        return $latestDepartmentByControlNo;
    }

    public function resolveLatestAssignedDepartmentIdForControlNo(?string $controlNo): ?int
    {
        $rawControlNo = trim((string) ($controlNo ?? ''));
        if ($rawControlNo === '') {
            return null;
        }

        $candidates = array_values(array_unique(array_filter([
            $rawControlNo,
            $this->normalizeControlNo($rawControlNo),
        ])));

        if ($candidates === []) {
            return null;
        }

        $departmentId = EmployeeDepartmentAssignment::query()
            ->whereIn('employee_control_no', $candidates)
            ->orderByDesc('assigned_at')
            ->orderByDesc('id')
            ->value('department_id');

        $resolvedDepartmentId = (int) ($departmentId ?? 0);

        return $resolvedDepartmentId > 0 ? $resolvedDepartmentId : null;
    }

    public function adminDepartmentCanAccessEmployee(
        ?int $departmentId,
        ?string $departmentName,
        string $employeeControlNo,
        ?object $employee = null
    ): bool {
        $resolvedDepartmentId = (int) ($departmentId ?? 0);
        if ($resolvedDepartmentId > 0) {
            $latestAssignedDepartmentId = $this->resolveLatestAssignedDepartmentIdForControlNo($employeeControlNo);
            if ($latestAssignedDepartmentId !== null) {
                return (int) $latestAssignedDepartmentId === $resolvedDepartmentId;
            }
        }

        if (! $employee) {
            return false;
        }

        return $this->sameOffice($employee->office ?? null, $departmentName);
    }

    public function findDepartmentEmployeeControlNos(
        ?string $departmentName,
        ?bool $activeOnly = null,
        ?int $departmentId = null
    ): array {
        $normalizedDepartmentName = trim((string) ($departmentName ?? ''));
        $resolvedDepartmentId = (int) ($departmentId ?? 0);
        $hrisControlNos = [];
        $latestAssignmentDepartmentMap = $this->resolveLatestAssignmentDepartmentMap();

        if ($normalizedDepartmentName !== '') {
            $hrisControlNos = HrisEmployee::controlNosByOffice($normalizedDepartmentName, $activeOnly);
        }

        $hrisControlNos = collect($hrisControlNos)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $controlNo): bool => $controlNo !== '')
            ->filter(function (string $controlNo) use ($resolvedDepartmentId, $latestAssignmentDepartmentMap): bool {
                if ($resolvedDepartmentId <= 0) {
                    return true;
                }

                $normalizedControlNo = $this->normalizeControlNo($controlNo);
                if ($normalizedControlNo === '') {
                    return false;
                }

                $latestAssignedDepartmentId = $latestAssignmentDepartmentMap[$normalizedControlNo] ?? null;
                if ($latestAssignedDepartmentId === null) {
                    return true;
                }

                return (int) $latestAssignedDepartmentId === $resolvedDepartmentId;
            })
            ->values()
            ->all();

        $assignedControlNos = $resolvedDepartmentId > 0
            ? collect($latestAssignmentDepartmentMap)
                ->filter(fn (int $assignedDepartmentId): bool => $assignedDepartmentId === $resolvedDepartmentId)
                ->keys()
                ->map(fn (mixed $value): string => trim((string) $value))
                ->filter(fn (string $controlNo): bool => $controlNo !== '')
                ->values()
                ->all()
            : [];

        $resolvedControlNos = array_values(array_unique([
            ...$hrisControlNos,
            ...$assignedControlNos,
        ]));

        if ($resolvedControlNos === []) {
            if ($resolvedDepartmentId > 0) {
                return [];
            }

            $resolvedControlNos = HrisEmployee::controlNosByOffice($departmentName, $activeOnly);
        }

        return collect($resolvedControlNos)
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $controlNo): bool => $controlNo !== '')
            ->flatMap(function (string $controlNo): array {
                $normalizedControlNo = $this->normalizeControlNo($controlNo);

                return array_values(array_unique([
                    $controlNo,
                    $normalizedControlNo,
                ]));
            })
            ->filter(fn (string $controlNo): bool => $controlNo !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, object>  $hrisEmployees
     * @return array<string, array<int, LeaveBalance>>
     */
    public function buildLeaveBalanceLookupForEmployees(Collection $hrisEmployees): array
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

        $balanceQuery = LeaveBalance::query()
            ->with('leaveType');

        $this->whereInEmployeeControlNos($balanceQuery, $candidateControlNos);

        $balances = $balanceQuery->get();

        $lookup = [];
        foreach ($balances as $balance) {
            if (! $balance instanceof LeaveBalance) {
                continue;
            }

            $normalizedControlNo = $this->normalizeControlNo((string) ($balance->employee_control_no ?? ''));
            if ($normalizedControlNo === '') {
                continue;
            }

            if (! isset($lookup[$normalizedControlNo])) {
                $lookup[$normalizedControlNo] = [];
            }

            $lookup[$normalizedControlNo][] = $balance;
        }

        return $lookup;
    }

    public function resolveCanonicalLeaveTypeId(?int $leaveTypeId): ?int
    {
        return LeaveType::resolveCanonicalLeaveTypeId($leaveTypeId);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<int, array{leave_type_id:int, leave_type_name:string, days:float}>|JsonResponse
     */
    public function normalizeMonetizationLeaveCreditComponents(
        array $validated,
        ?LeaveType $fallbackLeaveType = null
    ): array|JsonResponse {
        $rawComponents = $validated['monetization_leave_credits'] ?? null;
        if (! is_array($rawComponents) || $rawComponents === []) {
            $rawComponents = [[
                'leave_type_id' => $validated['leave_type_id'] ?? $fallbackLeaveType?->id,
                'days' => $validated['total_days'] ?? 0,
            ]];
        }

        $componentsByType = [];
        foreach ($rawComponents as $index => $rawComponent) {
            if (! is_array($rawComponent)) {
                return response()->json([
                    'message' => 'Invalid monetization leave credit entry.',
                    'errors' => [
                        "monetization_leave_credits.{$index}" => ['Each monetization leave credit entry must be an object.'],
                    ],
                ], 422);
            }

            $leaveTypeId = (int) ($rawComponent['leave_type_id'] ?? $rawComponent['leaveTypeId'] ?? 0);
            $days = round((float) ($rawComponent['days'] ?? $rawComponent['total_days'] ?? 0), 3);
            if ($days <= 0.0) {
                continue;
            }

            $leaveType = LeaveType::find($leaveTypeId);
            $eligibilityRestriction = $this->validateMonetizationLeaveTypeEligibility(
                $leaveType,
                "monetization_leave_credits.{$index}.leave_type_id"
            );
            if ($eligibilityRestriction instanceof JsonResponse) {
                return $eligibilityRestriction;
            }

            $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
            if (! array_key_exists($canonicalLeaveTypeId, $componentsByType)) {
                $componentsByType[$canonicalLeaveTypeId] = [
                    'leave_type_id' => $canonicalLeaveTypeId,
                    'leave_type_name' => (string) $leaveType->name,
                    'days' => 0.0,
                ];
            }

            $componentsByType[$canonicalLeaveTypeId]['days'] = round(
                (float) $componentsByType[$canonicalLeaveTypeId]['days'] + $days,
                3
            );
        }

        if ($componentsByType === []) {
            return response()->json([
                'message' => 'Select at least one leave type to monetize.',
                'errors' => [
                    'monetization_leave_credits' => ['Select at least one leave type to monetize.'],
                ],
            ], 422);
        }

        if (count($componentsByType) > 2) {
            return response()->json([
                'message' => 'Monetization may only include Vacation Leave and Sick Leave.',
                'errors' => [
                    'monetization_leave_credits' => ['Monetization may only include Vacation Leave and Sick Leave.'],
                ],
            ], 422);
        }

        $components = collect($componentsByType)
            ->sortBy(fn (array $component): int => $this->isVacationLeaveType(null, (int) $component['leave_type_id']) ? 0 : 1)
            ->values()
            ->all();

        $requestedDays = $this->sumMonetizationComponentDays($components);
        $declaredTotalDays = round((float) ($validated['total_days'] ?? 0), 3);
        if (abs($requestedDays - $declaredTotalDays) > 0.001) {
            return response()->json([
                'message' => 'Total monetization days must match the selected Vacation Leave and Sick Leave days.',
                'errors' => [
                    'total_days' => ['Total monetization days must match the selected Vacation Leave and Sick Leave days.'],
                ],
            ], 422);
        }

        $minimumRestriction = $this->validateMonetizationRequestMinimum($requestedDays);
        if ($minimumRestriction instanceof JsonResponse) {
            return $minimumRestriction;
        }

        return $components;
    }

    /**
     * @param  array<int, array{leave_type_id:int, leave_type_name:string, days:float}>  $components
     * @return array<int, array{leave_type_id:int, leave_type_name:string, days:float}>
     */
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

                $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;
                $leaveTypeName = $rawComponent['leave_type_name'] ?? null;
                if ($leaveTypeName === null || trim((string) $leaveTypeName) === '') {
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
        $fallbackLeaveTypeId = (int) ($this->resolveCanonicalLeaveTypeId((int) ($fallbackLeaveType?->id ?? $app->leave_type_id)) ?? (int) $app->leave_type_id);

        if ($fallbackLeaveTypeId <= 0) {
            return [];
        }

        return [[
            'leave_type_id' => $fallbackLeaveTypeId,
            'leave_type_name' => (string) ($fallbackLeaveType?->name ?? 'Leave'),
            'days' => round((float) ($app->deductible_days ?? $app->total_days ?? 0), 3),
        ]];
    }

    /**
     * @param  array<int, array{leave_type_id:int, leave_type_name:string, days:float}>  $components
     */
    public function sumMonetizationComponentDays(array $components): float
    {
        return round(array_reduce(
            $components,
            static fn (float $carry, array $component): float => $carry + max((float) ($component['days'] ?? 0), 0.0),
            0.0
        ), 3);
    }

    /**
     * @param  array<int, array{leave_type_id:int, leave_type_name:string, days:float}>  $components
     */
    public function resolvePrimaryMonetizationLeaveTypeId(array $components, int $requestedLeaveTypeId): int
    {
        $requestedLeaveTypeId = $this->resolveCanonicalLeaveTypeId($requestedLeaveTypeId) ?? $requestedLeaveTypeId;
        foreach ($components as $component) {
            if ((int) $component['leave_type_id'] === $requestedLeaveTypeId) {
                return $requestedLeaveTypeId;
            }
        }

        return (int) ($components[0]['leave_type_id'] ?? $requestedLeaveTypeId);
    }

    /**
     * @param  array<int, array{leave_type_id:int, leave_type_name:string, days:float}>  $components
     */
    public function formatMonetizationComponentsLabel(array $components, bool $includeDays = true): string
    {
        if ($components === []) {
            return 'leave credits';
        }

        return collect($components)
            ->map(function (array $component) use ($includeDays): string {
                $label = (string) ($component['leave_type_name'] ?? 'Leave');
                if (! $includeDays) {
                    return $label;
                }

                return $label.' ('.self::formatDays((float) ($component['days'] ?? 0)).')';
            })
            ->implode(', ');
    }

    /**
     * @param  array<int, array{leave_type_id:int, leave_type_name:string, days:float}>  $components
     */
    public function monetizationComponentsRequireAttachment(array $components): bool
    {
        foreach ($components as $component) {
            if ((float) $component['days'] > LeaveApplication::MONETIZATION_ATTACHMENT_THRESHOLD_DAYS) {
                return true;
            }
        }

        return false;
    }

    public function resolveCreditBasedPayAllocation(
        ?array $selectedDates,
        ?array $selectedDateCoverage,
        float $totalDays,
        float $availableCredits,
        ?array $preferredSelectedDatePayStatus = null,
        ?string $employeeControlNo = null,
        ?LeaveType $leaveType = null,
        ?string $detailsOfLeave = null,
        bool $allowSlVlCrossDeduction = false,
        ?float $crossDeductionAvailableCredits = null
    ): array {
        $normalizedTotalDays = round(max($totalDays, 0.0), 3);
        $normalizedAvailableCredits = round(max($availableCredits, 0.0), 3);
        $normalizedCrossDeductionAvailableCredits = round(max((float) ($crossDeductionAvailableCredits ?? 0.0), 0.0), 3);
        $canUseSlVlCrossDeductionCredits = $allowSlVlCrossDeduction
            && $normalizedCrossDeductionAvailableCredits > 0.0
            && $leaveType instanceof LeaveType
            && $this->creditService()->resolveSlVlCrossDeductionTargetLeaveTypeId(
                $leaveType,
                (int) ($leaveType->id ?? 0),
                false,
                true,
                $this->creditService()->resolveSickLeaveTypeId(),
                $this->creditService()->resolveVacationLeaveTypeId()
            ) !== null;

        if (
            $normalizedTotalDays <= 0.0
            || ($normalizedAvailableCredits <= 0.0 && ! $canUseSlVlCrossDeductionCredits)
        ) {
            return [
                'pay_mode' => LeaveApplication::PAY_MODE_WITHOUT_PAY,
                'selected_date_pay_status' => null,
                'deductible_days' => 0.0,
            ];
        }

        $preferredCompactedPayStatus = $this->calculationService->compactSelectedDatePayStatusMap(
            $preferredSelectedDatePayStatus,
            $selectedDates,
            LeaveApplication::PAY_MODE_WITH_PAY
        );
        $preferredPayMode = $this->resolvePayModeFromSelectedDates(
            $selectedDates,
            $preferredCompactedPayStatus,
            LeaveApplication::PAY_MODE_WITH_PAY
        );
        $preferredCompactedPayStatus = $this->calculationService->compactSelectedDatePayStatusMap(
            $preferredCompactedPayStatus,
            $selectedDates,
            $preferredPayMode
        );

        $deductibleSelectedDates = $this->filterOutAbroadWeekendDates(
            $selectedDates,
            $leaveType,
            $leaveType?->id !== null ? (int) $leaveType->id : null,
            $detailsOfLeave
        );

        $preferredDeductibleDays = $this->calculationService->computeDeductibleDays(
            $normalizedTotalDays,
            $deductibleSelectedDates,
            $preferredCompactedPayStatus,
            $selectedDateCoverage,
            false,
            $preferredPayMode,
            $employeeControlNo
        );
        $combinedAvailableCredits = round($normalizedAvailableCredits + $normalizedCrossDeductionAvailableCredits, 3);

        if (
            $normalizedAvailableCredits + 1e-9 >= $normalizedTotalDays
            || $preferredDeductibleDays <= $normalizedAvailableCredits + 1e-9
            || (
                $preferredDeductibleDays > 0.0
                && $canUseSlVlCrossDeductionCredits
                && $combinedAvailableCredits > $normalizedAvailableCredits + 1e-9
                && $preferredDeductibleDays <= $combinedAvailableCredits + 1e-9
            )
        ) {
            if ($preferredDeductibleDays <= 0.0) {
                return [
                    'pay_mode' => LeaveApplication::PAY_MODE_WITHOUT_PAY,
                    'selected_date_pay_status' => null,
                    'deductible_days' => 0.0,
                ];
            }

            return [
                'pay_mode' => $preferredPayMode,
                'selected_date_pay_status' => $preferredCompactedPayStatus,
                'deductible_days' => round(max($preferredDeductibleDays, 0.0), 3),
            ];
        }

        if (! is_array($selectedDates) || $selectedDates === []) {
            $availableCreditCap = $canUseSlVlCrossDeductionCredits
                ? round(min($normalizedTotalDays, $combinedAvailableCredits), 3)
                : $normalizedAvailableCredits;

            if ($availableCreditCap <= 0.0) {
                return [
                    'pay_mode' => LeaveApplication::PAY_MODE_WITHOUT_PAY,
                    'selected_date_pay_status' => null,
                    'deductible_days' => 0.0,
                ];
            }

            return [
                'pay_mode' => LeaveApplication::PAY_MODE_WITH_PAY,
                'selected_date_pay_status' => null,
                'deductible_days' => $availableCreditCap,
            ];
        }

        $payStatusOverrides = $this->calculationService->buildSickLeavePayStatusOverrides(
            $selectedDates,
            $selectedDateCoverage,
            $normalizedTotalDays,
            $normalizedAvailableCredits,
            $employeeControlNo
        );
        $compactedPayStatusOverrides = $this->calculationService->compactSelectedDatePayStatusMap(
            $payStatusOverrides,
            $selectedDates,
            LeaveApplication::PAY_MODE_WITH_PAY
        );
        $resolvedPayMode = $this->resolvePayModeFromSelectedDates(
            $selectedDates,
            $compactedPayStatusOverrides,
            LeaveApplication::PAY_MODE_WITH_PAY
        );
        $compactedPayStatusOverrides = $this->calculationService->compactSelectedDatePayStatusMap(
            $compactedPayStatusOverrides,
            $selectedDates,
            $resolvedPayMode
        );

        $deductibleDays = $this->calculationService->computeDeductibleDays(
            $normalizedTotalDays,
            $deductibleSelectedDates,
            $compactedPayStatusOverrides,
            $selectedDateCoverage,
            false,
            $resolvedPayMode,
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
            'pay_mode' => $resolvedPayMode,
            'selected_date_pay_status' => $compactedPayStatusOverrides,
            'deductible_days' => round(max($deductibleDays, 0.0), 3),
        ];
    }

    public function validateNoDuplicateLeaveDates(
        string $employeeControlNo,
        string $startDate,
        string $endDate,
        ?array $selectedDates = null,
        mixed $totalDays = null,
        ?int $excludeApplicationId = null,
        ?array $selectedDateCoverage = null,
        ?array $selectedDateHalfDayPortion = null,
        ?callable $candidatesResolver = null
    ): ?JsonResponse {
        $requestedDateOccupancy = LeaveApplication::resolveDateOccupancyMap(
            $startDate,
            $endDate,
            $selectedDates,
            $totalDays,
            $selectedDateCoverage,
            $selectedDateHalfDayPortion
        );
        if ($requestedDateOccupancy === []) {
            return null;
        }

        $candidates = $candidatesResolver !== null
            ? $candidatesResolver($employeeControlNo)
            : $this->controlNoCandidates($employeeControlNo);

        $existingApplications = LeaveApplication::query()
            ->select([
                'id',
                'start_date',
                'end_date',
                'selected_dates',
                'selected_date_coverage',
                'selected_date_half_day_portion',
                'total_days',
            ])
            ->whereIn('status', [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
                LeaveApplication::STATUS_APPROVED,
            ])
            ->whereIn('employee_control_no', $candidates)
            ->when($excludeApplicationId !== null, function ($query) use ($excludeApplicationId): void {
                $query->where('id', '<>', $excludeApplicationId);
            })
            ->get();

        $duplicateDateMap = [];
        foreach ($existingApplications as $existingApplication) {
            $existingDateOccupancy = LeaveApplication::resolveDateOccupancyMap(
                $existingApplication->start_date?->toDateString(),
                $existingApplication->end_date?->toDateString(),
                is_array($existingApplication->selected_dates) ? $existingApplication->selected_dates : null,
                $existingApplication->total_days,
                is_array($existingApplication->selected_date_coverage) ? $existingApplication->selected_date_coverage : null,
                is_array($existingApplication->selected_date_half_day_portion) ? $existingApplication->selected_date_half_day_portion : null
            );

            foreach (LeaveApplication::resolveOverlappingOccupancyDates($requestedDateOccupancy, $existingDateOccupancy) as $duplicateDate) {
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
            static fn (string $date): string => CarbonImmutable::parse($date)->format('M j, Y'),
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

    public function validateNoPendingApplicationForLeaveType(
        string $employeeControlNo,
        int $leaveTypeId,
        ?int $excludeApplicationId = null,
        ?callable $candidatesResolver = null
    ): ?JsonResponse {
        $controlNoCandidates = $candidatesResolver !== null
            ? $candidatesResolver($employeeControlNo)
            : $this->controlNoCandidates($employeeControlNo);
        $canonicalLeaveTypeId = $this->resolveCanonicalLeaveTypeId($leaveTypeId) ?? $leaveTypeId;

        $existingPending = LeaveApplication::query()
            ->with('leaveType')
            ->whereIn('employee_control_no', $controlNoCandidates)
            ->whereIn('status', [
                LeaveApplication::STATUS_PENDING_ADMIN,
                LeaveApplication::STATUS_PENDING_HR,
            ])
            ->when($excludeApplicationId !== null, function ($query) use ($excludeApplicationId): void {
                $query->where('id', '<>', $excludeApplicationId);
            })
            ->get()
            ->first(function (LeaveApplication $app) use ($canonicalLeaveTypeId): bool {
                $appLeaveTypeId = $this->resolveCanonicalLeaveTypeId((int) $app->leave_type_id) ?? (int) $app->leave_type_id;

                return $appLeaveTypeId === $canonicalLeaveTypeId;
            });

        if (! $existingPending instanceof LeaveApplication) {
            return null;
        }

        $leaveTypeName = $existingPending->leaveType?->name
            ?? LeaveType::query()->whereKey($canonicalLeaveTypeId)->value('name')
            ?? 'selected leave type';

        return response()->json([
            'message' => "You already have a pending {$leaveTypeName} application. Please wait for it to be approved first.",
            'errors' => [
                'leave_type_id' => ["You already have a pending {$leaveTypeName} application. Please wait for it to be approved first."],
            ],
            'pending_application_id' => $existingPending->id,
        ], 422);
    }

    public function validateRegularLeaveEligibility(
        string $employeeControlNo,
        int $leaveTypeId,
        float $requestedDays,
        string $payMode = LeaveApplication::PAY_MODE_WITH_PAY,
        ?float $requestedDeductibleDays = null,
        ?float $requestedCtoHours = null,
        bool $allowVacationLeaveTopUp = false,
        ?callable $employeeFinder = null,
        ?callable $balanceSnapshotResolver = null
    ): array|JsonResponse {
        $leaveType = LeaveType::find($leaveTypeId);
        if (! $leaveType) {
            return response()->json([
                'message' => 'Selected leave type is not available.',
                'errors' => [
                    'leave_type_id' => ['Selected leave type is not available.'],
                ],
            ], 422);
        }

        $employee = $employeeFinder !== null
            ? $employeeFinder($employeeControlNo)
            : HrisEmployee::findByControlNo($employeeControlNo);
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

        $forcedLeaveTypeId = $this->creditService()->resolveForcedLeaveTypeId();
        $vacationLeaveTypeId = $this->creditService()->resolveVacationLeaveTypeId();
        $isForcedLeaveType = LeaveType::isForcedLeaveType($leaveType, $leaveTypeId);
        $normalizedPayMode = $this->calculationService->normalizePayMode($payMode);
        $requiredBalanceDays = round(max((float) ($requestedDeductibleDays ?? $requestedDays), 0.0), 3);
        $requiresVacationLeaveTopUp = $allowVacationLeaveTopUp && $this->creditService()->shouldLeaveTypeUseVacationLeaveTopUpForScheduleExcess(
            $leaveType,
            $leaveTypeId,
            false,
            $vacationLeaveTypeId
        );
        $requiredForcedLeaveDays = $this->creditService()->resolveLeaveTypeForcedLeaveDeductionDays(
            $leaveType,
            $leaveTypeId,
            false,
            $requiredBalanceDays,
            $forcedLeaveTypeId
        );
        $requiredVacationLeaveDays = $requiresVacationLeaveTopUp
            ? $this->creditService()->resolveLeaveTypeVacationLeaveDeductionDays(
                $leaveType,
                $leaveTypeId,
                false,
                $requestedDays,
                $requiredBalanceDays,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId
            )
            : 0.0;
        if (! $requiresVacationLeaveTopUp) {
            $requiredVacationLeaveDays = $this->creditService()->resolveLeaveTypeVacationLeaveDeductionDays(
                $leaveType,
                $leaveTypeId,
                false,
                $requestedDays,
                $requiredBalanceDays,
                $forcedLeaveTypeId,
                $vacationLeaveTypeId
            );
        }
        $requiredBalanceDays = $this->creditService()->resolvePrimaryLeaveTrackedDeductionDays(
            $leaveType,
            $leaveTypeId,
            false,
            $requestedDays,
            $requiredBalanceDays,
            $forcedLeaveTypeId,
            $vacationLeaveTypeId
        );
        $isZeroBalanceRestricted = LeaveType::isZeroBalanceRestrictedName($leaveType->name);

        if (
            ! $isForcedLeaveType
            && ! $isZeroBalanceRestricted
            && (
                ! $leaveType->is_credit_based
                || ($requiredBalanceDays <= 0 && $requiredVacationLeaveDays <= 0)
                || $normalizedPayMode === LeaveApplication::PAY_MODE_WITHOUT_PAY
            )
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
                'required_forced_leave_days' => $requiredForcedLeaveDays,
                'required_vacation_leave_days' => $requiredVacationLeaveDays,
                'insufficient_balance' => false,
            ];
        }

        if ($this->calculationService->isCtoLeaveType($leaveType, (int) $leaveTypeId)) {
            $this->creditService()->syncEmployeeCtoBalance(
                $employeeControlNo,
                false,
                null,
                fn (string $no) => $this->controlNoCandidates($no, $employeeFinder),
                $employeeFinder
            );
        }

        $balanceSnapshot = $balanceSnapshotResolver !== null
            ? $balanceSnapshotResolver($employeeControlNo, $leaveTypeId)
            : $this->creditService()->resolveEmployeeLeaveBalanceSnapshot(
                $employeeControlNo,
                $leaveTypeId,
                fn (string $no) => $this->controlNoCandidates($no, $employeeFinder)
            );
        $currentBalance = (float) ($balanceSnapshot['current_balance'] ?? 0.0);
        $pendingReservedDays = (float) ($balanceSnapshot['pending_reserved_days'] ?? 0.0);
        $availableBalance = (float) ($balanceSnapshot['available_balance'] ?? 0.0);
        $currentBalanceHours = (float) ($balanceSnapshot['current_balance_hours'] ?? 0.0);
        $pendingReservedHours = (float) ($balanceSnapshot['pending_reserved_hours'] ?? 0.0);
        $availableBalanceHours = (float) ($balanceSnapshot['available_balance_hours'] ?? 0.0);

        if ($isZeroBalanceRestricted && $availableBalance <= 0.0) {
            return response()->json([
                'message' => "You cannot apply for {$leaveType->name} because your balance is 0.",
                'errors' => [
                    'leave_type_id' => ["Insufficient balance. {$leaveType->name} requires an available balance greater than 0."],
                ],
            ], 422);
        }

        if ($isForcedLeaveType && $availableBalance <= 0.0) {
            return [
                'leave_type' => $leaveType,
                'balance' => $currentBalance,
                'available_balance' => $availableBalance,
                'balance_hours' => $currentBalanceHours,
                'available_balance_hours' => $availableBalanceHours,
                'pending_reserved_days' => $pendingReservedDays,
                'pending_reserved_hours' => $pendingReservedHours,
                'required_balance_days' => round(max((float) ($requestedDeductibleDays ?? $requestedDays), 0.0), 3),
                'required_balance_hours' => null,
                'required_forced_leave_days' => $requiredForcedLeaveDays,
                'required_vacation_leave_days' => $requiredVacationLeaveDays,
                'insufficient_balance' => true,
            ];
        }

        if ($isForcedLeaveType) {
            if ($vacationLeaveTypeId === null) {
                return $this->creditService()->buildRequiredForcedLeaveVacationBalanceResponse(0.0);
            }

            $vacationBalanceSnapshot = $balanceSnapshotResolver !== null
                ? $balanceSnapshotResolver($employeeControlNo, $vacationLeaveTypeId)
                : $this->creditService()->resolveEmployeeLeaveBalanceSnapshot(
                    $employeeControlNo,
                    $vacationLeaveTypeId,
                    fn (string $no) => $this->controlNoCandidates($no, $employeeFinder)
                );
            $availableVacationBalance = (float) ($vacationBalanceSnapshot['available_balance'] ?? 0.0);
            if ($availableVacationBalance <= 0.0) {
                return $this->creditService()->buildRequiredForcedLeaveVacationBalanceResponse($availableVacationBalance);
            }

            $requestedForcedLeaveDays = round(max((float) ($requestedDeductibleDays ?? $requestedDays), 0.0), 3);
            $requiredBalanceDays = 0.0;
            $requiredVacationLeaveDays = $requestedForcedLeaveDays;
            $requiredForcedLeaveDays = 0.0;
        }

        $requiredBalanceHours = $this->calculationService->isCtoLeaveType($leaveType, $leaveTypeId)
            ? round(max((float) ($requestedCtoHours ?? $this->calculationService->resolveCtoDeductedHours($requiredBalanceDays)), 0.0), 2)
            : null;
        $insufficientBalance = $requiredBalanceHours !== null
            ? ($availableBalanceHours + 1e-9 < $requiredBalanceHours)
            : ($availableBalance + 1e-9 < $requiredBalanceDays);
        if ($requiresVacationLeaveTopUp) {
            $requiredVacationLeaveDays = round(max($requiredBalanceDays - max($availableBalance, 0.0), 0.0), 3);
            $requiredBalanceDays = round(max($requiredBalanceDays - $requiredVacationLeaveDays, 0.0), 3);
            $insufficientBalance = false;

            if ($vacationLeaveTypeId !== null && $requiredVacationLeaveDays > 0.0) {
                $vacationBalanceSnapshot = $balanceSnapshotResolver !== null
                    ? $balanceSnapshotResolver($employeeControlNo, $vacationLeaveTypeId)
                    : $this->creditService()->resolveEmployeeLeaveBalanceSnapshot(
                        $employeeControlNo,
                        $vacationLeaveTypeId,
                        fn (string $no) => $this->controlNoCandidates($no, $employeeFinder)
                    );
                $availableVacationBalance = (float) ($vacationBalanceSnapshot['available_balance'] ?? 0.0);
                if ($availableVacationBalance + 1e-9 < $requiredVacationLeaveDays) {
                    return $this->creditService()->buildInsufficientVacationLeaveTopUpResponse(
                        $leaveType,
                        $availableVacationBalance,
                        $requiredVacationLeaveDays
                    );
                }
            }
        }

        if (! $requiresVacationLeaveTopUp && $requiredVacationLeaveDays > 0.0) {
            $vacationBalanceSnapshot = $balanceSnapshotResolver !== null
                ? $balanceSnapshotResolver($employeeControlNo, $vacationLeaveTypeId ?? 0)
                : $this->creditService()->resolveEmployeeLeaveBalanceSnapshot(
                    $employeeControlNo,
                    $vacationLeaveTypeId ?? 0,
                    fn (string $no) => $this->controlNoCandidates($no, $employeeFinder)
                );
            $availableVacationBalance = (float) ($vacationBalanceSnapshot['available_balance'] ?? 0.0);
            if ($availableVacationBalance + 1e-9 < $requiredVacationLeaveDays) {
                return $this->creditService()->buildInsufficientLinkedVacationLeaveBalanceResponse(
                    $leaveType,
                    $availableVacationBalance,
                    $requiredVacationLeaveDays
                );
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
            'required_forced_leave_days' => $requiredForcedLeaveDays,
            'required_vacation_leave_days' => $requiredVacationLeaveDays,
            'insufficient_balance' => $insufficientBalance,
        ];
    }
}
