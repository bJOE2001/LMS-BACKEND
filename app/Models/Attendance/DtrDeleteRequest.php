<?php

namespace App\Models\Attendance;

use Illuminate\Database\Eloquent\Model;

/**
 * Tracks DTR slot deletion requests submitted by Department Admins for HR approval.
 */
class DtrDeleteRequest extends Model
{
    protected $connection = 'bio';

    protected $table = 'tblDtrDeleteRequests';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'daily_time_record_id',
        'employee_control_no',
        'employee_name',
        'department_id',
        'office_name',
        'record_date',
        'period',
        'slots',
        'previous_snapshot',
        'reason',
        'status',
        'requested_by_admin_id',
        'requested_by_name',
        'reviewed_by_hr_id',
        'reviewed_by_name',
        'reviewed_at',
        'hr_remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'daily_time_record_id' => 'integer',
            'department_id' => 'integer',
            'record_date' => 'date:Y-m-d',
            'slots' => 'array',
            'previous_snapshot' => 'array',
            'requested_by_admin_id' => 'integer',
            'reviewed_by_hr_id' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }
}
