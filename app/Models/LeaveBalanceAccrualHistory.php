<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Monthly accrual ledger for employee leave balances.
 */
class LeaveBalanceAccrualHistory extends Model
{
    protected $table = 'tblLeaveBalanceAccrualHistories';

    protected $fillable = [
        'leave_balance_id',
        'credits_added',
        'accrual_date',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'leave_balance_id' => 'integer',
            'credits_added' => 'decimal:2',
            'accrual_date' => 'date',
        ];
    }

    public function leaveBalance(): BelongsTo
    {
        return $this->belongsTo(LeaveBalance::class);
    }
}
