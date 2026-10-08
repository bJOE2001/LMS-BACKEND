<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Department model — local LMS_DB table.
 */
class Department extends Model
{
    use HasFactory;

    protected $connection = 'sqlsrv';

    protected $table = 'tblDepartments';

    protected $fillable = [
        'name',
        'code',
        'acronym',
        'is_inactive',
    ];

    protected function casts(): array
    {
        return [
            'is_inactive' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_inactive', false);
    }

    public function admin(): HasOne
    {
        return $this->hasOne(DepartmentAdmin::class, 'department_id');
    }

    public function departmentHead(): HasOne
    {
        return $this->hasOne(DepartmentHead::class, 'department_id');
    }

    public function employeeAssignments(): HasMany
    {
        return $this->hasMany(EmployeeDepartmentAssignment::class, 'department_id');
    }

    public function biometricDevices(): HasMany
    {
        return $this->hasMany(BiometricDevice::class, 'department_id');
    }

    public function primaryBiometricDevice(): HasOne
    {
        return $this->hasOne(BiometricDevice::class, 'department_id')
            ->where('is_active', true)
            ->where('is_primary', true);
    }
}
