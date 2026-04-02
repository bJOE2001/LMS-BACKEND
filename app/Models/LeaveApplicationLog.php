<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit log for leave application workflow actions.
 * LOCAL LMS_DB only.
 */
class LeaveApplicationLog extends Model
{
    public $timestamps = false;

    protected $table = 'tblLeaveApplicationLogs';

    protected $fillable = [
        'leave_application_id',
        'action',
        'performed_by_type',
        'performed_by_id',
        'remarks',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    // ─── Action Constants ────────────────────────────────────────────

    public const ACTION_SUBMITTED = 'SUBMITTED';
    public const ACTION_ADMIN_APPROVED = 'ADMIN_APPROVED';
    public const ACTION_ADMIN_REJECTED = 'ADMIN_REJECTED';
    public const ACTION_HR_APPROVED = 'HR_APPROVED';
    public const ACTION_HR_REJECTED = 'HR_REJECTED';
    public const ACTION_HR_RECALLED = 'HR_RECALLED';
    public const ACTION_HR_RECEIVED = 'HR_RECEIVED';
    public const ACTION_HR_RELEASED = 'HR_RELEASED';

    public const PERFORMER_EMPLOYEE = 'EMPLOYEE';
    public const PERFORMER_ADMIN = 'ADMIN';
    public const PERFORMER_HR = 'HR';

    // ─── Relationships ───────────────────────────────────────────────

    public function leaveApplication(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class);
    }
}
