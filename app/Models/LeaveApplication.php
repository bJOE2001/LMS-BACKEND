<?php

namespace App\Models;

use App\Services\WorkScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Leave Application — multi-step approval workflow.
 * Employee → Department Admin → HR
 * LOCAL LMS_DB only.
 */
class LeaveApplication extends Model
{
    protected $table = 'tblLeaveApplications';

    public const PAY_MODE_WITH_PAY = 'WP';

    public const PAY_MODE_WITHOUT_PAY = 'WOP';

    public const MONETIZATION_REQUIRED_COMMUTATION = 'Requested';

    public const MONETIZATION_MINIMUM_VACATION_LEAVE_BALANCE_DAYS = 15.0;

    public const MONETIZATION_MINIMUM_REQUEST_DAYS = 10.0;

    public const MONETIZATION_ATTACHMENT_THRESHOLD_DAYS = 10.0;

    protected static function booted(): void
    {
        static::saving(function (self $application): void {
            if (trim((string) ($application->employee_name ?? '')) === '') {
                $application->employee_name = self::resolveSnapshotEmployeeName($application);
            }

            $rawPayMode = strtoupper(trim((string) ($application->pay_mode ?? self::PAY_MODE_WITH_PAY)));
            $application->pay_mode = in_array($rawPayMode, [self::PAY_MODE_WITH_PAY, self::PAY_MODE_WITHOUT_PAY], true)
                ? $rawPayMode
                : self::PAY_MODE_WITH_PAY;

            if ((bool) $application->is_monetization) {
                $application->pay_mode = self::PAY_MODE_WITH_PAY;
                $application->commutation = self::MONETIZATION_REQUIRED_COMMUTATION;
                $application->selected_dates = null;
                $application->selected_date_pay_status = null;
                $application->selected_date_coverage = null;
                $application->selected_date_half_day_portion = null;
                $application->deductible_days = round((float) ($application->total_days ?? 0), 3);
                $application->without_pay_days = 0.0;
                $application->cto_deducted_hours = null;

                return;
            }

            $application->monetization_leave_credits = null;
            $application->selected_dates = self::resolveSelectedDates(
                $application->start_date,
                $application->end_date,
                $application->selected_dates,
                $application->total_days
            );

            $totalDays = round((float) ($application->total_days ?? 0), 2);
            $fallbackDeductible = $application->pay_mode === self::PAY_MODE_WITHOUT_PAY ? 0.0 : $totalDays;
            $deductibleDays = $application->deductible_days !== null
                ? round((float) $application->deductible_days, 3)
                : $fallbackDeductible;

            if ($deductibleDays < 0) {
                $deductibleDays = 0.0;
            }

            $application->deductible_days = $deductibleDays;
            if (self::shouldRefreshWithoutPayDaysSnapshot($application)) {
                $employeeControlNo = trim((string) ($application->employee_control_no ?? ''));
                $resolvedEmployeeControlNo = $employeeControlNo !== '' ? $employeeControlNo : null;
                $workScheduleService = app(WorkScheduleService::class);

                $application->without_pay_days = self::calculateWithoutPayDays(
                    $application->total_days,
                    $application->deductible_days,
                    $application->pay_mode,
                    $application->selected_dates,
                    $application->selected_date_pay_status,
                    $application->selected_date_coverage,
                    $application->selected_date_half_day_portion,
                    $workScheduleService->resolveCoverageDeductionDays('whole', $resolvedEmployeeControlNo),
                    $workScheduleService->resolveCoverageDeductionDays('half', $resolvedEmployeeControlNo)
                );
            } elseif ($application->without_pay_days !== null) {
                $application->without_pay_days = round(max((float) $application->without_pay_days, 0.0), 3);
            }
            $application->cto_deducted_hours = $application->cto_deducted_hours !== null
                ? round(max((float) $application->cto_deducted_hours, 0.0), 2)
                : null;
        });
    }

    // This system uses ERMS ControlNo as the authoritative employee identifier.
    // Employee master records are resolved from HRIS employee sources.
    protected $fillable = [
        'applicant_admin_id',
        'employee_control_no',
        'erms_control_no',
        'employee_name',
        'leave_type_id',
        'start_date',
        'end_date',
        'total_days',
        'reason',
        'details_of_leave',
        'deductible_days',
        'without_pay_days',
        'cto_deducted_hours',
        'status',
        'admin_id',
        'hr_id',
        'admin_approved_at',
        'hr_approved_at',
        'recall_effective_date',
        'recall_selected_dates',
        'remarks',
        'selected_dates',
        'selected_date_pay_status',
        'selected_date_coverage',
        'selected_date_half_day_portion',
        'commutation',
        'pay_mode',
        'allow_sl_vl_cross_deduction',
        'linked_forced_leave_deducted_days',
        'linked_vacation_leave_deducted_days',
        'linked_sick_leave_deducted_days',
        'requires_documents',
        'attachment_required',
        'attachment_submitted',
        'attachment_reference',
        'certification_leave_credits_snapshot',
        'is_monetization',
        'equivalent_amount',
        'monetization_leave_credits',
    ];

    protected function casts(): array
    {
        return [
            'employee_control_no' => 'string',
            'start_date' => 'date',
            'end_date' => 'date',
            'total_days' => 'decimal:2',
            'deductible_days' => 'decimal:3',
            'without_pay_days' => 'decimal:3',
            'cto_deducted_hours' => 'decimal:2',
            'admin_approved_at' => 'datetime',
            'hr_approved_at' => 'datetime',
            'recall_effective_date' => 'date',
            'recall_selected_dates' => 'array',
            'selected_dates' => 'array',
            'selected_date_pay_status' => 'array',
            'selected_date_coverage' => 'array',
            'selected_date_half_day_portion' => 'array',
            'pay_mode' => 'string',
            'allow_sl_vl_cross_deduction' => 'boolean',
            'linked_forced_leave_deducted_days' => 'decimal:3',
            'linked_vacation_leave_deducted_days' => 'decimal:3',
            'linked_sick_leave_deducted_days' => 'decimal:3',
            'requires_documents' => 'boolean',
            'attachment_required' => 'boolean',
            'attachment_submitted' => 'boolean',
            'attachment_reference' => 'string',
            'certification_leave_credits_snapshot' => 'array',
            'is_monetization' => 'boolean',
            'equivalent_amount' => 'decimal:2',
            'monetization_leave_credits' => 'array',
        ];
    }

    // ─── Status Constants ────────────────────────────────────────────

    public const STATUS_PENDING_ADMIN = 'PENDING_ADMIN';

    public const STATUS_PENDING_HR = 'PENDING_HR';

    public const STATUS_APPROVED = 'APPROVED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_RECALLED = 'RECALLED';

    // ─── Relationships ───────────────────────────────────────────────

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(LeaveApplicationLog::class);
    }

    public function updateRequests(): HasMany
    {
        return $this->hasMany(LeaveApplicationUpdateRequest::class, 'leave_application_id');
    }

    public function applicantAdmin(): BelongsTo
    {
        return $this->belongsTo(DepartmentAdmin::class, 'applicant_admin_id');
    }

    protected $hidden = [
        'employee_control_no',
    ];

    protected $appends = [
        'erms_control_no',
    ];

    public function getErmsControlNoAttribute(): ?string
    {
        $rawControlNo = $this->attributes['employee_control_no']
            ?? $this->attributes['erms_control_no']
            ?? null;

        if ($rawControlNo === null) {
            return null;
        }

        $controlNo = trim((string) $rawControlNo);

        return $controlNo !== '' ? $controlNo : null;
    }

    public function setErmsControlNoAttribute(mixed $value): void
    {
        $this->attributes['employee_control_no'] = $value;
    }

    public static function resolveSelectedDates(
        mixed $startDate,
        mixed $endDate,
        mixed $selectedDates = null,
        mixed $totalDays = null
    ): ?array {
        $normalizedSelectedDates = self::normalizeDateList($selectedDates);
        if ($normalizedSelectedDates !== []) {
            return $normalizedSelectedDates;
        }

        $rangeDates = self::buildDateRange($startDate, $endDate);
        if ($rangeDates === []) {
            return null;
        }

        return self::canInferConsecutiveDateRange($rangeDates, $totalDays) ? $rangeDates : null;
    }

    public static function resolveDateSet(
        mixed $startDate,
        mixed $endDate,
        mixed $selectedDates = null,
        mixed $totalDays = null
    ): array {
        return self::resolveSelectedDates($startDate, $endDate, $selectedDates, $totalDays) ?? [];
    }

    public function resolvedSelectedDates(): ?array
    {
        return self::resolveSelectedDates(
            $this->start_date,
            $this->end_date,
            $this->selected_dates,
            $this->total_days
        );
    }

    public static function calculateWithoutPayDays(
        mixed $totalDays,
        mixed $deductibleDays = null,
        mixed $payMode = null,
        mixed $selectedDates = null,
        mixed $selectedDatePayStatus = null,
        mixed $selectedDateCoverage = null,
        mixed $selectedDateHalfDayPortion = null,
        ?float $wholeDayWeight = null,
        ?float $halfDayWeight = null
    ): float {
        $normalizedTotalDays = round(max((float) ($totalDays ?? 0), 0.0), 3);
        if ($normalizedTotalDays <= 0.0) {
            return 0.0;
        }

        $normalizedPayMode = strtoupper(trim((string) ($payMode ?? self::PAY_MODE_WITH_PAY)));
        if (! in_array($normalizedPayMode, [self::PAY_MODE_WITH_PAY, self::PAY_MODE_WITHOUT_PAY], true)) {
            $normalizedPayMode = self::PAY_MODE_WITH_PAY;
        }

        $normalizedDeductibleDays = $deductibleDays !== null
            ? round(max((float) $deductibleDays, 0.0), 3)
            : ($normalizedPayMode === self::PAY_MODE_WITHOUT_PAY ? 0.0 : $normalizedTotalDays);

        $resolvedSelectedDates = self::normalizeDateList($selectedDates);
        if ($resolvedSelectedDates === []) {
            if ($normalizedPayMode === self::PAY_MODE_WITHOUT_PAY) {
                return $normalizedTotalDays;
            }

            return round(max($normalizedTotalDays - $normalizedDeductibleDays, 0.0), 3);
        }

        $normalizedCoverage = self::normalizeSelectedDateCoverageMap($selectedDateCoverage);
        $normalizedHalfDayPortion = self::mergeSelectedDateHalfDayPortionMaps(
            self::normalizeSelectedDateHalfDayPortionMap($selectedDateHalfDayPortion),
            self::normalizeSelectedDateHalfDayPortionMap($selectedDateCoverage)
        );
        foreach (array_keys($normalizedHalfDayPortion) as $dateKey) {
            $normalizedCoverage[$dateKey] = 'half';
        }

        $normalizedPayStatus = self::normalizeSelectedDatePayStatusMap($selectedDatePayStatus);
        $resolvedWholeDayWeight = round(max((float) ($wholeDayWeight ?? 1.0), 0.0), 3);
        $resolvedHalfDayWeight = round(
            max((float) ($halfDayWeight ?? ($resolvedWholeDayWeight / 2)), 0.0),
            3
        );

        $withoutPayDays = 0.0;
        foreach ($resolvedSelectedDates as $dateKey) {
            $weight = ($normalizedCoverage[$dateKey] ?? 'whole') === 'half'
                ? $resolvedHalfDayWeight
                : $resolvedWholeDayWeight;
            if ($weight <= 0.0) {
                continue;
            }

            $effectivePayMode = $normalizedPayStatus[$dateKey] ?? $normalizedPayMode;
            if ($effectivePayMode === self::PAY_MODE_WITHOUT_PAY) {
                $withoutPayDays += $weight;
            }
        }

        return round(max($withoutPayDays, 0.0), 3);
    }

    /**
     * @return array<string, array{AM: bool, PM: bool}>
     */
    public static function resolveDateOccupancyMap(
        mixed $startDate,
        mixed $endDate,
        mixed $selectedDates = null,
        mixed $totalDays = null,
        mixed $selectedDateCoverage = null,
        mixed $selectedDateHalfDayPortion = null
    ): array {
        $resolvedDates = self::resolveSelectedDates($startDate, $endDate, $selectedDates, $totalDays)
            ?? self::buildDateRange($startDate, $endDate);
        if ($resolvedDates === []) {
            return [];
        }

        $coverageMap = self::normalizeSelectedDateCoverageMap($selectedDateCoverage);
        $halfDayPortionMap = self::mergeSelectedDateHalfDayPortionMaps(
            self::normalizeSelectedDateHalfDayPortionMap($selectedDateHalfDayPortion),
            self::normalizeSelectedDateHalfDayPortionMap($selectedDateCoverage)
        );

        $occupancyMap = [];
        foreach ($resolvedDates as $resolvedDate) {
            $dateKey = self::normalizeDateKey($resolvedDate);
            if ($dateKey === null) {
                continue;
            }

            $coverage = $coverageMap[$dateKey] ?? null;
            $halfDayPortion = $halfDayPortionMap[$dateKey] ?? null;

            $occupancyMap[$dateKey] = match (true) {
                $coverage === 'half' && $halfDayPortion === 'AM' => ['AM' => true, 'PM' => false],
                $coverage === 'half' && $halfDayPortion === 'PM' => ['AM' => false, 'PM' => true],
                default => ['AM' => true, 'PM' => true],
            };
        }

        ksort($occupancyMap);

        return $occupancyMap;
    }

    /**
     * @param  array<string, array{AM: bool, PM: bool}>  $requestedDateOccupancy
     * @param  array<string, array{AM: bool, PM: bool}>  $existingDateOccupancy
     * @return array<int, string>
     */
    public static function resolveOverlappingOccupancyDates(
        array $requestedDateOccupancy,
        array $existingDateOccupancy
    ): array {
        $overlappingDates = [];

        foreach ($requestedDateOccupancy as $dateKey => $requestedSlots) {
            $existingSlots = $existingDateOccupancy[$dateKey] ?? null;
            if ($existingSlots === null) {
                continue;
            }

            $amConflict = (bool) ($requestedSlots['AM'] ?? false) && (bool) ($existingSlots['AM'] ?? false);
            $pmConflict = (bool) ($requestedSlots['PM'] ?? false) && (bool) ($existingSlots['PM'] ?? false);

            if ($amConflict || $pmConflict) {
                $overlappingDates[$dateKey] = true;
            }
        }

        $resolvedDates = array_keys($overlappingDates);
        sort($resolvedDates);

        return $resolvedDates;
    }

    private static function normalizeDateList(mixed $selectedDates): array
    {
        if ($selectedDates === null || $selectedDates === '') {
            return [];
        }

        if (is_string($selectedDates)) {
            $decoded = json_decode($selectedDates, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $selectedDates = $decoded;
            } else {
                $selectedDates = [$selectedDates];
            }
        }

        if (! is_iterable($selectedDates)) {
            return [];
        }

        $normalizedDates = [];
        foreach ($selectedDates as $selectedDate) {
            if ($selectedDate === null || $selectedDate === '') {
                continue;
            }

            try {
                $normalizedDates[] = CarbonImmutable::parse((string) $selectedDate)->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        if ($normalizedDates === []) {
            return [];
        }

        $normalizedDates = array_values(array_unique($normalizedDates));
        sort($normalizedDates);

        return $normalizedDates;
    }

    private static function normalizeDateKey(mixed $rawDate): ?string
    {
        if ($rawDate === null || $rawDate === '') {
            return null;
        }

        if ($rawDate instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($rawDate)->toDateString();
        }

        try {
            return CarbonImmutable::parse((string) $rawDate)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function normalizeSelectedDateCoverageValue(mixed $value): ?string
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

        return self::normalizeSelectedDateHalfDayPortionValue($value) !== null ? 'half' : null;
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeSelectedDatePayStatusMap(mixed $value): array
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

        $normalized = [];
        foreach ($value as $rawDate => $rawStatus) {
            $dateKey = self::normalizeDateKey($rawDate);
            if ($dateKey === null) {
                continue;
            }

            $status = strtoupper(trim((string) $rawStatus));
            if (! in_array($status, [self::PAY_MODE_WITH_PAY, self::PAY_MODE_WITHOUT_PAY], true)) {
                continue;
            }

            $normalized[$dateKey] = $status;
        }

        ksort($normalized);

        return $normalized;
    }

    private static function normalizeSelectedDateHalfDayPortionValue(mixed $value): ?string
    {
        $normalized = strtoupper(str_replace([' ', '-', '_'], '', trim((string) $value)));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'AM', 'MORNING' => 'AM',
            'PM', 'AFTERNOON' => 'PM',
            default => null,
        };
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeSelectedDateCoverageMap(mixed $value): array
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

        $normalized = [];
        foreach ($value as $rawDate => $rawCoverage) {
            $dateKey = self::normalizeDateKey($rawDate);
            if ($dateKey === null) {
                continue;
            }

            $coverage = self::normalizeSelectedDateCoverageValue($rawCoverage);
            if ($coverage === null) {
                continue;
            }

            $normalized[$dateKey] = $coverage;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @return array<string, string>
     */
    private static function normalizeSelectedDateHalfDayPortionMap(mixed $value): array
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

        $normalized = [];
        foreach ($value as $rawDate => $rawPortion) {
            $dateKey = self::normalizeDateKey($rawDate);
            if ($dateKey === null) {
                continue;
            }

            $portion = self::normalizeSelectedDateHalfDayPortionValue($rawPortion);
            if ($portion === null) {
                continue;
            }

            $normalized[$dateKey] = $portion;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * @param  array<string, string>  ...$maps
     * @return array<string, string>
     */
    private static function mergeSelectedDateHalfDayPortionMaps(array ...$maps): array
    {
        $merged = [];

        foreach ($maps as $map) {
            foreach ($map as $dateKey => $portion) {
                if ($portion !== 'AM' && $portion !== 'PM') {
                    continue;
                }

                $merged[$dateKey] = $portion;
            }
        }

        ksort($merged);

        return $merged;
    }

    private static function buildDateRange(mixed $startDate, mixed $endDate): array
    {
        if ($startDate === null || $startDate === '' || $endDate === null || $endDate === '') {
            return [];
        }

        try {
            $cursor = CarbonImmutable::parse((string) $startDate)->startOfDay();
            $lastDate = CarbonImmutable::parse((string) $endDate)->startOfDay();
        } catch (\Throwable) {
            return [];
        }

        if ($cursor->gt($lastDate)) {
            return [];
        }

        $dates = [];
        while ($cursor->lte($lastDate)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    private static function canInferConsecutiveDateRange(array $rangeDates, mixed $totalDays): bool
    {
        if ($rangeDates === [] || ! is_numeric($totalDays)) {
            return false;
        }

        $normalizedTotalDays = (float) $totalDays;
        $roundedTotalDays = round($normalizedTotalDays);
        if (abs($normalizedTotalDays - $roundedTotalDays) > 0.00001) {
            return false;
        }

        return (int) $roundedTotalDays === count($rangeDates);
    }

    private static function resolveSnapshotEmployeeName(self $application): ?string
    {
        $controlNo = trim((string) ($application->employee_control_no ?? $application->erms_control_no ?? ''));
        if ($controlNo !== '') {
            $employee = HrisEmployee::findByControlNo($controlNo);
            $employeeName = self::formatSnapshotEmployeeName($employee);
            if ($employeeName !== null) {
                return $employeeName;
            }
        }

        $applicantAdminId = (int) ($application->applicant_admin_id ?? 0);
        if ($applicantAdminId > 0) {
            $admin = DepartmentAdmin::query()->find($applicantAdminId);
            $employee = $admin ? HrisEmployee::findByControlNo((string) ($admin->employee_control_no ?? '')) : null;
            $employeeName = self::formatSnapshotEmployeeName($employee);
            if ($employeeName !== null) {
                return $employeeName;
            }

            $adminName = trim((string) ($admin?->full_name ?? ''));

            return $adminName !== '' ? $adminName : null;
        }

        return null;
    }

    private static function formatSnapshotEmployeeName(?object $employee): ?string
    {
        if (! $employee) {
            return null;
        }

        $fullName = trim(implode(' ', array_filter([
            trim((string) ($employee->firstname ?? '')),
            trim((string) ($employee->middlename ?? '')),
            trim((string) ($employee->surname ?? '')),
        ], static fn (string $part): bool => $part !== '')));

        return $fullName !== '' ? $fullName : null;
    }

    private static function shouldRefreshWithoutPayDaysSnapshot(self $application): bool
    {
        if ($application->isDirty('without_pay_days')) {
            return false;
        }

        if (! $application->exists || $application->without_pay_days === null) {
            return true;
        }

        return $application->isDirty([
            'employee_control_no',
            'start_date',
            'end_date',
            'total_days',
            'deductible_days',
            'selected_dates',
            'selected_date_pay_status',
            'selected_date_coverage',
            'selected_date_half_day_portion',
            'pay_mode',
            'is_monetization',
        ]);
    }
}
