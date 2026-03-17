<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class COCApplication extends Model
{
    protected $table = 'tblCOCApplications';

    protected $fillable = [
        'erms_control_no',
        'status',
        'reviewed_by_admin_id',
        'admin_reviewed_at',
        'reviewed_by_hr_id',
        'reviewed_at',
        'cto_leave_type_id',
        'cto_credited_days',
        'cto_credited_at',
        'total_minutes',
        'remarks',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return [
            'reviewed_by_admin_id' => 'integer',
            'admin_reviewed_at' => 'datetime',
            'reviewed_by_hr_id' => 'integer',
            'reviewed_at' => 'datetime',
            'cto_leave_type_id' => 'integer',
            'cto_credited_days' => 'decimal:2',
            'cto_credited_at' => 'datetime',
            'total_minutes' => 'integer',
            'submitted_at' => 'datetime',
        ];
    }

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'erms_control_no', 'control_no');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(COCApplicationRow::class, 'coc_application_id')->orderBy('line_no');
    }

    public function reviewedByHr(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'reviewed_by_hr_id');
    }

    public function reviewedByAdmin(): BelongsTo
    {
        return $this->belongsTo(DepartmentAdmin::class, 'reviewed_by_admin_id');
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
            $nestedQuery->where('erms_control_no', $rawControlNo);

            if ($normalizedControlNo !== null && $normalizedControlNo !== $rawControlNo) {
                $nestedQuery->orWhere('erms_control_no', $normalizedControlNo);
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
