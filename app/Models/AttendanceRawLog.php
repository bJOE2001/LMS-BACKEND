<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model representing raw, immutable punch records ingested from ZKTeco MB360 devices
 * via ADMS real-time push, scheduled network polling, or offline USB .dat imports.
 * Stored in the dedicated BIO_DB database.
 */
class AttendanceRawLog extends Model
{
    protected $connection = 'bio';

    protected $table = 'tblAttendanceRawLogs';

    /** Punch state constants standard in ZKTeco firmware */
    public const STATE_CHECK_IN = 0;

    public const STATE_CHECK_OUT = 1;

    public const STATE_BREAK_OUT = 2;

    public const STATE_BREAK_IN = 3;

    public const STATE_OT_IN = 4;

    public const STATE_OT_OUT = 5;

    /** Verify type constants standard in ZKTeco firmware */
    public const VERIFY_PASSWORD = 0;

    public const VERIFY_FINGERPRINT = 1;

    public const VERIFY_CARD = 4;

    public const VERIFY_FACE = 15;

    protected $fillable = [
        'device_serial_number',
        'biometric_pin',
        'employee_control_no',
        'punch_time',
        'punch_state',
        'verify_type',
        'work_code',
        'sync_source',
        'raw_payload',
        'processed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'punch_time' => 'datetime',
            'punch_state' => 'integer',
            'verify_type' => 'integer',
            'processed_at' => 'datetime',
        ];
    }

    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }

    public function scopeForEmployee(Builder $query, string $controlNo): Builder
    {
        return $query->where(function (Builder $q) use ($controlNo): void {
            $q->where('employee_control_no', $controlNo)
                ->orWhere('biometric_pin', $controlNo);
        });
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_serial_number', 'serial_number');
    }
}
