<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeaveRestoration extends Model
{
    use HasFactory;

    protected $table = 'tblLeaveRestorations';

    protected $fillable = [
        'employee_control_no',
        'target_leave_type_id',
        'particulars',
        'start_date',
        'end_date',
        'selected_dates',
        'restored_days',
        'restored_by_hr_id',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'selected_dates' => 'array',
            'restored_days' => 'float',
            'restored_by_hr_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'target_leave_type_id');
    }

    public function restoredByHr(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'restored_by_hr_id');
    }
}
