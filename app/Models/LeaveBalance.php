<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Per-employee leave balance for each leave type.
 * LOCAL LMS_DB only.
 */
class LeaveBalance extends Model
{
    protected $table = 'tblLeaveBalances';

    protected $fillable = [
        'employee_id',
        'leave_type_id',
        'balance',
        'initialized_at',
        'last_accrual_date',
        'year',
    ];

    protected function casts(): array
    {
        return [
            'balance'           => 'decimal:2',
            'initialized_at'    => 'datetime',
            'last_accrual_date' => 'date',
            'year'              => 'integer',
        ];
    }

    // ─── Relationships ───────────────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function accrualHistories(): HasMany
    {
        return $this->hasMany(LeaveBalanceAccrualHistory::class)
            ->orderByDesc('accrual_date')
            ->orderByDesc('id');
    }
}
