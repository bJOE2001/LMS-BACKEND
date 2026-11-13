<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Illness extends Model
{
    protected $table = 'tblIllnesses';

    protected $fillable = [
        'name',
        'is_inactive',
    ];

    protected function casts(): array
    {
        return [
            'is_inactive' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_inactive', false);
    }
}
