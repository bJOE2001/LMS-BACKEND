<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Model representing daily processed attendance records conforming to
 * Philippine Civil Service Commission (CSC) Form 48 specifications.
 * Stored in the dedicated BIO_DB database.
 */
class DailyTimeRecord extends Model
{
    protected $connection = 'bio';

    protected $table = 'tblDailyTimeRecords';

    /** Status constants */
    public const STATUS_PRESENT = 'PRESENT';

    public const STATUS_ABSENT = 'ABSENT';

    public const STATUS_ON_LEAVE = 'ON_LEAVE';

    public const STATUS_HALF_DAY = 'HALF_DAY';

    public const STATUS_HOLIDAY = 'HOLIDAY';

    public const STATUS_REST_DAY = 'REST_DAY';

    public const STATUS_OFFICIAL_BUSINESS = 'OFFICIAL_BUSINESS';

    public const STATUS_PASS_SLIP = 'PASS_SLIP';

    public const STATUS_PENDING = 'PENDING';

    protected $fillable = [
        'employee_control_no',
        'department_id',
        'record_date',
        'day_of_week',
        'am_arrival',
        'am_departure',
        'pm_arrival',
        'pm_departure',
        'ot_arrival',
        'ot_departure',
        'late_minutes',
        'undertime_minutes',
        'overtime_minutes',
        'rendered_hours',
        'status',
        'leave_type_code',
        'leave_application_id',
        'is_adjusted',
        'adjustment_reason',
        'adjustment_remarks',
        'adjusted_by_admin_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'department_id' => 'integer',
            'record_date' => 'date:Y-m-d',
            'late_minutes' => 'integer',
            'undertime_minutes' => 'integer',
            'overtime_minutes' => 'integer',
            'rendered_hours' => 'decimal:2',
            'leave_application_id' => 'integer',
            'is_adjusted' => 'boolean',
            'adjusted_by_admin_id' => 'integer',
        ];
    }

    public function scopeForEmployee(Builder $query, string $controlNo): Builder
    {
        return $query->where('employee_control_no', $controlNo);
    }

    public function scopeForDepartment(Builder $query, int $departmentId): Builder
    {
        return $query->where('department_id', $departmentId);
    }

    public function scopeInDateRange(Builder $query, string $startDate, string $endDate): Builder
    {
        return $query->whereBetween('record_date', [$startDate, $endDate]);
    }
}
