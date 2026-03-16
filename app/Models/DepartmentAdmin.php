<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * Department admin — exactly one per department, has login credentials.
 * LOCAL LMS_DB only. Used for login (no users table).
 */
class DepartmentAdmin extends Model implements AuthenticatableContract
{
    use HasApiTokens;

    protected $table = 'tblDepartmentAdmins';

    protected $fillable = [
        'department_id',
        'employee_control_no',
        'full_name',
        'username',
        'password',
        'leave_initialized',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'leave_initialized' => 'boolean',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_control_no', 'control_no');
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(AdminLeaveBalance::class, 'admin_id');
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }
}
