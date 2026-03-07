<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Employee model — local LMS_DB employees table.
 *
 * This is separate from HrisEmployee which reads from the
 * remote pmis2003.vwActive view (READ-ONLY).
 */
class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'department_id',
        'first_name',
        'last_name',
        'birthdate',
        'position',
        'status',
        'leave_initialized',
    ];

    /**
     * The attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'birthdate'         => 'date',
            'status'            => 'string',
            'leave_initialized' => 'boolean',
        ];
    }

    /**
     * Valid status values.
     */
    public const STATUSES = [
        'CO-TERMINOUS',
        'ELECTIVE',
        'CASUAL',
        'REGULAR',
    ];

    // ─── Relationships ───────────────────────────────────────────────

    /**
     * The department this employee belongs to.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employeeAccount(): HasOne
    {
        return $this->hasOne(EmployeeAccount::class);
    }

    public function leaveApplications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    // ─── Accessors ───────────────────────────────────────────────────

    /**
     * Full name accessor.
     */
    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }
}
