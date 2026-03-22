<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecycleBin extends Model
{
    protected $table = 'tblRecycleBin';

    protected $fillable = [
        'entity_type',
        'table_name',
        'record_primary_key',
        'record_primary_value',
        'record_title',
        'deleted_by_type',
        'deleted_by_id',
        'deleted_by_name',
        'delete_source',
        'delete_reason',
        'record_snapshot',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }
}
