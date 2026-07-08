<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class SpecialPrivilegeReason extends Model
{
    protected $table = 'tblSpecialPrivilegeReasons';

    protected $fillable = [
        'description',
        'is_inactive',
    ];

    /**
     * Get casts array.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_inactive' => 'boolean',
        ];
    }

    /**
     * Scope query to only include active reasons.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_inactive', false);
    }
}
