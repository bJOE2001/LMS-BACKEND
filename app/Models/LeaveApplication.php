<?php

namespace App\Models;

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

    protected static function booted(): void
    {
        static::saving(function (self $application): void {
            $rawPayMode = strtoupper(trim((string) ($application->pay_mode ?? self::PAY_MODE_WITH_PAY)));
            $application->pay_mode = in_array($rawPayMode, [self::PAY_MODE_WITH_PAY, self::PAY_MODE_WITHOUT_PAY], true)
                ? $rawPayMode
                : self::PAY_MODE_WITH_PAY;

            if ((bool) $application->is_monetization) {
                $application->pay_mode = self::PAY_MODE_WITH_PAY;
                $application->selected_dates = null;
                $application->selected_date_pay_status = null;
                $application->selected_date_coverage = null;
                $application->deductible_days = round((float) ($application->total_days ?? 0), 2);
                return;
            }

            $application->selected_dates = self::resolveSelectedDates(
                $application->start_date,
                $application->end_date,
                $application->selected_dates,
                $application->total_days
            );

            $totalDays = round((float) ($application->total_days ?? 0), 2);
            $fallbackDeductible = $application->pay_mode === self::PAY_MODE_WITHOUT_PAY ? 0.0 : $totalDays;
            $deductibleDays = $application->deductible_days !== null
                ? round((float) $application->deductible_days, 2)
                : $fallbackDeductible;

            if ($deductibleDays < 0) {
                $deductibleDays = 0.0;
            }

            if ($totalDays > 0 && $deductibleDays > $totalDays) {
                $deductibleDays = $totalDays;
            }

            $application->deductible_days = $deductibleDays;
        });
    }

    // This system uses ERMS ControlNo as the authoritative employee identifier.
    // Employee master records are stored and managed in LMS tblEmployees.
    protected $fillable = [
        'applicant_admin_id',
        'erms_control_no',
        'leave_type_id',
        'start_date',
        'end_date',
        'total_days',
        'reason',
        'deductible_days',
        'status',
        'admin_id',
        'hr_id',
        'admin_approved_at',
        'hr_approved_at',
        'remarks',
        'selected_dates',
        'selected_date_pay_status',
        'selected_date_coverage',
        'commutation',
        'pay_mode',
        'requires_documents',
        'attachment_required',
        'attachment_submitted',
        'attachment_reference',
        'is_monetization',
        'equivalent_amount',
    ];

    protected function casts(): array
    {
        return [
            'erms_control_no' => 'string',
            'start_date' => 'date',
            'end_date' => 'date',
            'total_days' => 'decimal:2',
            'deductible_days' => 'decimal:2',
            'admin_approved_at' => 'datetime',
            'hr_approved_at' => 'datetime',
            'selected_dates' => 'array',
            'selected_date_pay_status' => 'array',
            'selected_date_coverage' => 'array',
            'pay_mode' => 'string',
            'requires_documents' => 'boolean',
            'attachment_required' => 'boolean',
            'attachment_submitted' => 'boolean',
            'attachment_reference' => 'string',
            'is_monetization' => 'boolean',
            'equivalent_amount' => 'decimal:2',
        ];
    }

    // ─── Status Constants ────────────────────────────────────────────

    public const STATUS_PENDING_ADMIN = 'PENDING_ADMIN';
    public const STATUS_PENDING_HR = 'PENDING_HR';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'erms_control_no', 'control_no');
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

        if (!is_iterable($selectedDates)) {
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
        if ($rangeDates === [] || !is_numeric($totalDays)) {
            return false;
        }

        $normalizedTotalDays = (float) $totalDays;
        $roundedTotalDays = round($normalizedTotalDays);
        if (abs($normalizedTotalDays - $roundedTotalDays) > 0.00001) {
            return false;
        }

        return (int) $roundedTotalDays === count($rangeDates);
    }
}

