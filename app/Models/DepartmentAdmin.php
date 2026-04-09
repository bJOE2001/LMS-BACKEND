<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

/**
 * Department admin account (default seeded account + optional assigned employee account).
 * LOCAL LMS_DB only. Used for login (no users table).
 */
class DepartmentAdmin extends Model implements AuthenticatableContract
{
    use HasApiTokens;

    protected $table = 'tblDepartmentAdmins';

    protected $fillable = [
        'department_id',
        'is_default_account',
        'employee_control_no',
        'full_name',
        'username',
        'password',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_default_account' => 'boolean',
            'must_change_password' => 'boolean',
            'active_personal_access_token_id' => 'integer',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
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
