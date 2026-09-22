<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model representing employee biometric enrollment status in BIO_DB.
 * Tracks HR registration, device enrollment timestamp, and synced office devices.
 */
class BiometricEnrollment extends Model
{
    protected $connection = 'bio';

    protected $table = 'tblBiometricEnrollments';

    public const STATUS_REGISTERED = 'REGISTERED';

    public const STATUS_PENDING_ENROLLMENT = 'PENDING_ENROLLMENT';

    public const STATUS_NOT_REGISTERED = 'NOT_REGISTERED';

    protected $fillable = [
        'employee_control_no',
        'employee_name',
        'status',
        'enrollment_device_sn',
        'enrolled_at',
        'enrolled_by_user_id',
        'synced_devices',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'synced_devices' => 'array',
        ];
    }

    public function isRegistered(): bool
    {
        return $this->status === self::STATUS_REGISTERED;
    }

    public function isSyncedToDevice(string $deviceSerialNumber): bool
    {
        $devices = $this->synced_devices ?? [];

        return in_array($deviceSerialNumber, $devices, true);
    }

    public function markSyncedToDevice(string $deviceSerialNumber): void
    {
        $devices = $this->synced_devices ?? [];
        if (! in_array($deviceSerialNumber, $devices, true)) {
            $devices[] = $deviceSerialNumber;
            $this->synced_devices = $devices;
            $this->save();
        }
    }
}
