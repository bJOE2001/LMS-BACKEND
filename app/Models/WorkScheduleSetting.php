<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkScheduleSetting extends Model
{
    public const GLOBAL_SETTING_KEY = 'GLOBAL_DEFAULT';

    protected $table = 'tblWorkScheduleSettings';

    protected $fillable = [
        'setting_key',
        'work_start_time',
        'work_end_time',
        'break_start_time',
        'break_end_time',
        'working_hours_per_day',
        'whole_day_leave_deduction',
        'half_day_leave_deduction',
        'notes',
        'updated_by_hr_account_id',
    ];

    protected function casts(): array
    {
        return [
            'working_hours_per_day' => 'decimal:2',
            'whole_day_leave_deduction' => 'decimal:3',
            'half_day_leave_deduction' => 'decimal:3',
        ];
    }

    public function updatedByHr(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'updated_by_hr_account_id');
    }
}
