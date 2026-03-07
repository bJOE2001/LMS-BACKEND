<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Log entry for each HR leave balance CSV import.
 * LOCAL LMS_DB only.
 */
class LeaveBalanceImportLog extends Model
{
    public $timestamps = false;

    protected $table = 'tblLeaveBalanceImportLogs';

    protected $fillable = [
        'hr_id',
        'filename',
        'total_records',
        'successful_records',
        'failed_records',
    ];

    protected function casts(): array
    {
        return [
            'total_records'      => 'integer',
            'successful_records' => 'integer',
            'failed_records'     => 'integer',
        ];
    }

    public function hrAccount(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'hr_id');
    }
}
