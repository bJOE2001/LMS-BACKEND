<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Leave Application — multi-step approval workflow.
 * Employee → Department Admin → HR
 * LOCAL LMS_DB only.
 */
class LeaveApplication extends Model
{
    protected $table = 'leave_applications';

    protected $fillable = [
        'applicant_admin_id',
        'employee_id',
        'leave_type_id',
        'start_date',
        'end_date',
        'total_days',
        'reason',
        'status',
        'admin_id',
        'hr_id',
        'admin_approved_at',
        'hr_approved_at',
        'remarks',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'total_days' => 'decimal:2',
            'admin_approved_at' => 'datetime',
            'hr_approved_at' => 'datetime',
        ];
    }

    // ─── Status Constants ────────────────────────────────────────────

    public const STATUS_PENDING_ADMIN = 'PENDING_ADMIN';
    public const STATUS_PENDING_HR = 'PENDING_HR';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    // ─── Relationships ───────────────────────────────────────────────

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType(): BelongsTo
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(LeaveApplicationLog::class);
    }

    public function applicantAdmin(): BelongsTo
    {
        return $this->belongsTo(DepartmentAdmin::class, 'applicant_admin_id');
    }
}
