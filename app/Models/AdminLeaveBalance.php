<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminLeaveBalance extends Model
{
    protected $table = 'tblAdminLeaveBalances';

    protected $fillable = [
        'admin_id',
        'leave_type_id',
        'balance',
        'year',
        'initialized_at',
    ];

    protected $casts = [
        'balance' => 'float',
        'initialized_at' => 'datetime',
        'year' => 'integer',
    ];

    public function admin(): BelongsTo
    {
        return $this->belongsTo(DepartmentAdmin::class, 'admin_id');
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }
}
