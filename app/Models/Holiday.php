<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $table = 'tblHolidays';

    protected $fillable = [
        'holiday_date',
        'name',
        'type',
        'is_recurring',
        'is_active',
        'description',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'holiday_date' => 'date:Y-m-d',
            'is_recurring' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
