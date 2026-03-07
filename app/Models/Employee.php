<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employee model for local LMS employee records.
 *
 * Active columns:
 * - control_no (PK)
 * - surname
 * - firstname
 * - middlename
 * - office
 * - status
 * - designation
 * - rate_mon
 */
class Employee extends Model
{
    use HasFactory;

    protected $table = 'tblEmployees';

    protected $primaryKey = 'control_no';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'control_no',
        'surname',
        'firstname',
        'middlename',
        'office',
        'status',
        'designation',
        'rate_mon',
    ];

    protected function casts(): array
    {
        return [
            'rate_mon' => 'decimal:2',
        ];
    }

    /**
     * Resolve department by matching office name to departments.name.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'office', 'name');
    }

    public function leaveApplications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class, 'erms_control_no', 'control_no');
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class, 'employee_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->firstname} {$this->surname}");
    }

    /**
     * HRIS-to-LMS field map for supported employee columns.
     */
    public static function hrisColumnMap(): array
    {
        return [
            'ControlNo' => 'control_no',
            'Surname' => 'surname',
            'Firstname' => 'firstname',
            'Middlename' => 'middlename',
            'Office' => 'office',
            'Status' => 'status',
            'Designation' => 'designation',
            'RateMon' => 'rate_mon',
        ];
    }
}
