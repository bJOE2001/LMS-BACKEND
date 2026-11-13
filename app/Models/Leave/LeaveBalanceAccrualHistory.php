<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Monthly accrual ledger for employee leave balances.
 */
class LeaveBalanceAccrualHistory extends Model
{
    protected $table = 'tblLeaveBalanceCreditHistories';

    protected $fillable = [
        'leave_balance_id',
        'employee_control_no',
        'employee_name',
        'leave_type_name',
        'credits_added',
        'accrual_date',
        'source',
    ];

    protected function casts(): array
    {
        return [
            'leave_balance_id' => 'integer',
            'employee_control_no' => 'string',
            'credits_added' => 'decimal:3',
            'accrual_date' => 'date',
        ];
    }

    public function leaveBalance(): BelongsTo
    {
        return $this->belongsTo(LeaveBalance::class);
    }
}
