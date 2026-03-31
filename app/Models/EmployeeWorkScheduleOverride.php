<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeWorkScheduleOverride extends Model
{
    protected $table = 'tblEmployeeWorkScheduleOverrides';

    protected $fillable = [
        'employee_control_no',
        'employee_name',
        'office',
        'designation',
        'status',
        'work_start_time',
        'work_end_time',
        'break_start_time',
        'break_end_time',
        'working_hours_per_day',
        'whole_day_leave_deduction',
        'half_day_leave_deduction',
        'notes',
        'is_active',
        'updated_by_hr_account_id',
    ];

    protected function casts(): array
    {
        return [
            'working_hours_per_day' => 'decimal:2',
            'whole_day_leave_deduction' => 'decimal:3',
            'half_day_leave_deduction' => 'decimal:3',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function updatedByHr(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'updated_by_hr_account_id');
    }
}
