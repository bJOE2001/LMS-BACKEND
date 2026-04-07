<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CocLedgerEntry extends Model
{
    protected $table = 'tblCOCLedgerEntries';

    protected $fillable = [
        'employee_control_no',
        'leave_type_id',
        'sequence_no',
        'entry_type',
        'reference_type',
        'coc_application_id',
        'leave_application_id',
        'hours',
        'balance_after_hours',
        'effective_at',
        'expires_on',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'leave_type_id' => 'integer',
            'sequence_no' => 'integer',
            'coc_application_id' => 'integer',
            'leave_application_id' => 'integer',
            'hours' => 'decimal:2',
            'balance_after_hours' => 'decimal:2',
            'effective_at' => 'datetime',
            'expires_on' => 'date',
        ];
    }
}
