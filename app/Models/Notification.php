<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Notification model — local LMS_DB notifications table.
 */
class Notification extends Model
{
    protected $table = 'tblNotifications';

    protected $fillable = [
        'notifiable_type',
        'notifiable_id',
        'type',
        'title',
        'message',
        'leave_application_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }

    // ─── Notification types ──────────────────────────────────────────

    public const TYPE_LEAVE_APPROVED = 'leave_approved';
    public const TYPE_LEAVE_REJECTED = 'leave_rejected';
    public const TYPE_LEAVE_CANCELLED = 'leave_cancelled';
    public const TYPE_LEAVE_EDIT_REQUEST = 'leave_edit_requested';
    public const TYPE_LEAVE_REQUEST = 'leave_request';
    public const TYPE_LEAVE_PENDING = 'leave_pending';
    public const TYPE_REMINDER = 'reminder';
    public const TYPE_SYSTEM = 'system';

    // ─── Relationships ───────────────────────────────────────────────

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    public function leaveApplication(): BelongsTo
    {
        return $this->belongsTo(LeaveApplication::class);
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * Create a notification for a specific user account.
     */
    public static function send(Model $recipient, string $type, string $title, string $message, ?int $leaveApplicationId = null): self
    {
        return static::create([
            'notifiable_type' => get_class($recipient),
            'notifiable_id' => $recipient->id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'leave_application_id' => $leaveApplicationId,
        ]);
    }
}
