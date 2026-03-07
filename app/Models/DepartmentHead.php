<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Department head — exactly one per department. No login credentials.
 * LOCAL LMS_DB only.
 */
class DepartmentHead extends Model
{
    protected $table = 'department_heads';

    protected $fillable = [
        'department_id',
        'full_name',
        'position',
    ];

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }
}
