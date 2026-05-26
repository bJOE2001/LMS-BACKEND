<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HRModulePermission extends Model
{
    protected $table = 'tblHRModulePermissions';

    protected $fillable = [
        'hr_account_id',
        'module_key',
        'granted_by_hr_account_id',
    ];

    protected function casts(): array
    {
        return [
            'hr_account_id' => 'integer',
            'granted_by_hr_account_id' => 'integer',
        ];
    }

    public function hrAccount(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'hr_account_id');
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'granted_by_hr_account_id');
    }
}
