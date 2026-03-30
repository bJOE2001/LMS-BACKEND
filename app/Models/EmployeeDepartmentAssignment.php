<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDepartmentAssignment extends Model
{
    protected $table = 'tblEmployeeDepartmentAssignments';

    protected $fillable = [
        'employee_control_no',
        'department_id',
        'assigned_by_department_admin_id',
        'assigned_at',
    ];

    protected function casts(): array
    {
        return [
            'assigned_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saved(static function (): void {
            HrisEmployee::flushCache();
        });

        static::deleted(static function (): void {
            HrisEmployee::flushCache();
        });
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(DepartmentAdmin::class, 'assigned_by_department_admin_id');
    }
}
