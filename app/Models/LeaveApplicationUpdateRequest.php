<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pending update request for an already-filed leave application.
 */
class LeaveApplicationUpdateRequest extends Model
{
    protected $table = 'tblLeaveApplicationUpdateRequests';

    public const STATUS_PENDING = 'PENDING';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    protected $fillable = [
        'leave_application_id',
        'requested_payload',
        'requested_reason',
        'previous_status',
        'requested_by_control_no',
        'requested_at',
        'status',
        'reviewed_by_hr_id',
        'reviewed_at',
        'review_remarks',
    ];

    protected function casts(): array
    {
        return [
            'leave_application_id' => 'integer',
            'requested_payload' => 'array',
            'requested_at' => 'datetime',
            'reviewed_by_hr_id' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function leaveApplication(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class, 'leave_application_id');
    }

    public function reviewedByHr(): BelongsTo
    {
        return $this->belongsTo(HRAccount::class, 'reviewed_by_hr_id');
    }
}
