<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SignatorySetting extends Model
{
    public const KEY_CHRMO_LEAVE_IN_CHARGE = 'chrmo_leave_in_charge';

    protected $table = 'tblSignatorySettings';

    protected $fillable = [
        'signatory_key',
        'employee_control_no',
        'signatory_name',
        'signatory_position',
        'updated_by_hr_account_id',
    ];

    public function updatedByHr(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'updated_by_hr_account_id');
    }
}

