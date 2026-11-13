<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Department model — local LMS_DB table (tblDepartments).
 * Represents both top-level offices and attached divisions.
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
        'main_department_id',
        'is_inactive',
    ];

    protected function casts(): array
    {
        return [
            'main_department_id' => 'integer',
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

    /**
     * The Main Office this division belongs to (if this department is an attached division).
     */
    public function mainDepartment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'main_department_id');
    }

    /**
     * Divisions attached to this Main Office.
     */
    public function divisions(): HasMany
    {
        return $this->hasMany(self::class, 'main_department_id')->active();
    }

    public function isDivision(): bool
    {
        return $this->main_department_id !== null;
    }

    public function isMainOffice(): bool
    {
        return $this->divisions()->exists();
    }

    /**
     * Resolve the effective department head: returns the direct head if assigned,
     * otherwise cascades to the main office's department head.
     */
    public function effectiveDepartmentHead(): ?DepartmentHead
    {
        if ($this->relationLoaded('departmentHead') && $this->departmentHead !== null) {
            return $this->departmentHead;
        }

        $directHead = $this->departmentHead()->first();
        if ($directHead !== null) {
            return $directHead;
        }

        if ($this->main_department_id !== null) {
            $mainOffice = $this->relationLoaded('mainDepartment')
                ? $this->mainDepartment
                : $this->mainDepartment()->first();

            return $mainOffice?->departmentHead()->first();
        }

        return null;
    }
}
