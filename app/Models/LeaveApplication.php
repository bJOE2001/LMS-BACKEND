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
    protected $table = 'tblLeaveApplications';

    // This system uses ERMS ControlNo as the authoritative employee identifier.
    // Employee master records are stored and managed in LMS tblEmployees.
    protected $fillable = [
        'applicant_admin_id',
        'erms_control_no',
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
        'selected_dates',
        'commutation',
        'is_monetization',
        'equivalent_amount',
    ];

    protected function casts(): array
    {
        return [
            'erms_control_no' => 'integer',
            'start_date' => 'date',
            'end_date' => 'date',
            'total_days' => 'decimal:2',
            'admin_approved_at' => 'datetime',
            'hr_approved_at' => 'datetime',
            'selected_dates' => 'array',
            'is_monetization' => 'boolean',
            'equivalent_amount' => 'decimal:2',
        ];
    }

    // ─── Status Constants ────────────────────────────────────────────

    public const STATUS_PENDING_ADMIN = 'PENDING_ADMIN';
    public const STATUS_PENDING_HR = 'PENDING_HR';
    public const STATUS_APPROVED = 'APPROVED';
    public const STATUS_REJECTED = 'REJECTED';

    // ─── Relationships ───────────────────────────────────────────────

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

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'erms_control_no', 'control_no');
    }
}
