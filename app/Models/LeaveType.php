<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Leave type classification: ACCRUED, RESETTABLE, EVENT.
 * LOCAL LMS_DB only.
 */
class LeaveType extends Model
{
    protected $table = 'tblLeaveTypes';

    protected $fillable = [
        'name',
        'category',
        'accrual_rate',
        'accrual_day_of_month',
        'max_days',
        'is_credit_based',
        'resets_yearly',
        'requires_documents',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'accrual_rate'        => 'decimal:2',
            'accrual_day_of_month' => 'integer',
            'max_days'            => 'integer',
            'is_credit_based'     => 'boolean',
            'resets_yearly'       => 'boolean',
            'requires_documents'  => 'boolean',
        ];
    }

    public const CATEGORY_ACCRUED    = 'ACCRUED';
    public const CATEGORY_RESETTABLE = 'RESETTABLE';
    public const CATEGORY_EVENT      = 'EVENT';

    // ─── Scopes ──────────────────────────────────────────────────────

    public function scopeAccrued($query)
    {
        return $query->where('category', self::CATEGORY_ACCRUED);
    }

    public function scopeResettable($query)
    {
        return $query->where('category', self::CATEGORY_RESETTABLE);
    }

    public function scopeEventBased($query)
    {
        return $query->where('category', self::CATEGORY_EVENT);
    }

    // ─── Relationships ───────────────────────────────────────────────

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveApplications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class);
    }

    public function adminLeaveBalances(): HasMany
    {
        return $this->hasMany(AdminLeaveBalance::class);
    }
}
