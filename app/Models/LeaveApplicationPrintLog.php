<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log for leave application PDF print actions.
 * LOCAL LMS_DB only.
 */
class LeaveApplicationPrintLog extends Model
{
    public $timestamps = false;

    protected $table = 'tblLeaveApplicationPrintLogs';

    protected $fillable = [
        'leave_application_id',
        'printed_by_type',
        'printed_by_id',
        'printed_by_name',
        'ip_address',
        'user_agent',
        'remarks',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    // ─── Performer Constants ──────────────────────────────────────────

    public const PERFORMER_EMPLOYEE = 'EMPLOYEE';

    public const PERFORMER_ADMIN = 'ADMIN';

    // ─── Relationships ───────────────────────────────────────────────

    public function leaveApplication(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }
}
