<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Persistent gateway attempt log for employee SMS notifications.
 */
class SmsLog extends Model
{
    protected $table = 'tblSmsLogs';

    protected $fillable = [
        'employee_control_no',
        'message_type',
        'leave_application_id',
        'coc_application_id',
        'destination',
        'message',
        'status',
        'gateway_http_status',
        'gateway_response',
        'error_message',
        'sent_at',
    ];

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_SENT = 'SENT';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_SKIPPED = 'SKIPPED';

    public const TYPE_LEAVE_APPROVED = 'LEAVE_APPROVED';

    public const TYPE_LEAVE_REJECTED = 'LEAVE_REJECTED';

    public const TYPE_COC_APPROVED = 'COC_APPROVED';

    public const TYPE_COC_REJECTED = 'COC_REJECTED';

    public const TYPE_LEAVE_READY_FOR_RELEASE = 'LEAVE_READY_FOR_RELEASE';

    public const TYPE_COC_READY_FOR_RELEASE = 'COC_READY_FOR_RELEASE';

    protected function casts(): array
    {
        return [
            'gateway_http_status' => 'integer',
            'sent_at' => 'datetime',
        ];
    }

    public function leaveApplication(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class);
    }

    public function cocApplication(): BelongsTo
    {
        return $this->belongsTo(COCApplication::class);
    }
}
