<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DtrRemark extends Model
{
    protected $table = 'tblDtrRemarks';

    protected $fillable = [
        'abbrv',
        'description',
        'order_no',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'order_no' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
