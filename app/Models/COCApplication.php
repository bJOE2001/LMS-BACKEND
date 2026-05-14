<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class COCApplication extends Model
{
    protected $table = 'tblCOCApplications';

    protected static function booted(): void
    {
        static::saving(function (self $application): void {
            if (trim((string) ($application->employee_name ?? '')) === '') {
                $application->employee_name = self::resolveSnapshotEmployeeName($application);
            }
        });
    }

    protected $fillable = [
        'employee_control_no',
        'employee_name',
        'status',
        'is_late_filed',
        'late_filing_status',
        'late_filing_reviewed_by_hr_id',
        'late_filing_reviewed_at',
        'late_filing_review_remarks',
        'reviewed_by_admin_id',
        'admin_reviewed_at',
        'reviewed_by_hr_id',
        'hr_received_by_id',
        'hr_received_at',
        'cmo_cbmo_reviewed_by_id',
        'cmo_cbmo_reviewed_at',
        'hr_released_by_id',
        'hr_released_at',
        'reviewed_at',
        'cto_leave_type_id',
        'cto_credited_days',
        'cto_credited_at',
        'total_minutes',
        'application_year',
        'application_month',
        'credited_hours',
        'certificate_number',
        'certificate_issued_at',
        'remarks',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_by_admin_id' => 'integer',
            'is_late_filed' => 'boolean',
            'late_filing_reviewed_by_hr_id' => 'integer',
            'late_filing_reviewed_at' => 'datetime',
            'admin_reviewed_at' => 'datetime',
            'reviewed_by_hr_id' => 'integer',
            'hr_received_by_id' => 'integer',
            'hr_received_at' => 'datetime',
            'cmo_cbmo_reviewed_by_id' => 'integer',
            'cmo_cbmo_reviewed_at' => 'datetime',
            'hr_released_by_id' => 'integer',
            'hr_released_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'cto_leave_type_id' => 'integer',
            'cto_credited_days' => 'decimal:2',
            'cto_credited_at' => 'datetime',
            'total_minutes' => 'integer',
            'application_year' => 'integer',
            'application_month' => 'integer',
            'credited_hours' => 'decimal:2',
            'certificate_issued_at' => 'date',
            'submitted_at' => 'datetime',
        ];
    }

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    public function getErmsControlNoAttribute(): ?string
    {
        $value = $this->attributes['employee_control_no']
            ?? $this->attributes['erms_control_no']
            ?? null;

        return $value !== null ? (string) $value : null;
    }

    public function setErmsControlNoAttribute($value): void
    {
        $this->attributes['employee_control_no'] = $value;
    }

    public static function resolveSnapshotEmployeeName(self $application): ?string
    {
        $rawControlNo = trim((string) ($application->employee_control_no ?? ''));
        if ($rawControlNo === '') {
            return null;
        }

        $resolvedEmployee = HrisEmployee::findByControlNo($rawControlNo);
        if (!is_object($resolvedEmployee)) {
            return null;
        }

        $employeeName = trim(implode(' ', array_filter([
            trim((string) ($resolvedEmployee->firstname ?? '')),
            trim((string) ($resolvedEmployee->middlename ?? '')),
            trim((string) ($resolvedEmployee->surname ?? '')),
        ])));

        return $employeeName !== '' ? $employeeName : null;
    }

    public function rows(): HasMany
    {
        return $this->hasMany(COCApplicationRow::class, 'coc_application_id')->orderBy('line_no');
    }

    public function reviewedByHr(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'reviewed_by_hr_id');
    }

    public function lateFilingReviewedByHr(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'late_filing_reviewed_by_hr_id');
    }

    public function reviewedByAdmin(): BelongsTo
    {
        return $this->belongsTo(DepartmentAdmin::class, 'reviewed_by_admin_id');
    }

    public function receivedByHr(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'hr_received_by_id');
    }

    public function releasedByHr(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'hr_released_by_id');
    }

    public function cmoCbmoReviewedByHr(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'cmo_cbmo_reviewed_by_id');
    }

    public function ctoLeaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'cto_leave_type_id');
    }

    public function scopeMatchingControlNo(Builder $query, mixed $controlNo): Builder
    {
        $rawControlNo = trim((string) ($controlNo ?? ''));
        if ($rawControlNo === '') {
            return $query->whereRaw('1 = 0');
        }

        $normalizedControlNo = self::normalizeControlNoInt($rawControlNo);

        return $query->where(function (Builder $nestedQuery) use ($rawControlNo, $normalizedControlNo): void {
            $nestedQuery->where('employee_control_no', $rawControlNo);

            if ($normalizedControlNo !== null && $normalizedControlNo !== $rawControlNo) {
                $nestedQuery->orWhere('employee_control_no', $normalizedControlNo);
            }
        });
    }

    private static function normalizeControlNoInt(mixed $controlNo): ?string
    {
        $normalized = ltrim(trim((string) ($controlNo ?? '')), '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        return preg_match('/^\d+$/', $normalized) ? $normalized : null;
    }
}
