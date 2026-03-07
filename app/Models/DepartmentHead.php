<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepartmentHead extends Model
{
    use HasFactory;

    protected $table = 'tblDepartmentHeads';

    protected $fillable = [
        'department_id',
        'control_no',
        'surname',
        'firstname',
        'middlename',
        'office',
        'status',
        'designation',
        'rate_mon',
        'full_name',
        'position',
    ];

    protected function casts(): array
    {
        return [
            'rate_mon' => 'decimal:2',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
