<?php

namespace App\Models;

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

    protected $fillable = [
        'name',
    ];

    public function admin(): HasOne
    {
        return $this->hasOne(DepartmentAdmin::class, 'department_id');
    }

    public function head(): HasOne
    {
        return $this->hasOne(DepartmentHead::class, 'department_id');
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'department_id');
    }
}
