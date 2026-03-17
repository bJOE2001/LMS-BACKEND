<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class COCApplicationRow extends Model
{
    protected $table = 'tblCOCApplicationRows';

    protected $fillable = [
        'coc_application_id',
        'line_no',
        'overtime_date',
        'nature_of_overtime',
        'time_from',
        'time_to',
        'minutes',
        'cumulative_minutes',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'overtime_date' => 'date',
            'minutes' => 'integer',
            'cumulative_minutes' => 'integer',
        ];
    }

    public function application(): BelongsTo
    {
        return $this->belongsTo(COCApplication::class, 'coc_application_id');
    }
}
