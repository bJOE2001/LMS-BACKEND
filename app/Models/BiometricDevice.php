<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Model representing physical or networked ZKTeco biometric devices.
 * Stored in the dedicated BIO_DB database.
 */
class BiometricDevice extends Model
{
    protected $connection = 'bio';

    protected $table = 'tblBiometricDevices';

    protected $fillable = [
        'device_name',
        'serial_number',
        'ip_address',
        'port',
        'comm_key',
        'communication_mode',
        'department_id',
        'department_name',
        'model_name',
        'firmware_version',
        'device_user_count',
        'device_finger_count',
        'device_face_count',
        'device_log_count',
        'last_heartbeat_at',
        'last_sync_at',
        'status',
        'is_active',
    ];

    protected $appends = [
        'last_activity_at',
        'is_online',
        'model',
        'location',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'device_user_count' => 'integer',
            'device_finger_count' => 'integer',
            'device_face_count' => 'integer',
            'device_log_count' => 'integer',
            'is_active' => 'boolean',
            'last_heartbeat_at' => 'datetime',
            'last_sync_at' => 'datetime',
        ];
    }

    public function getLastActivityAtAttribute(): ?\Carbon\Carbon
    {
        return $this->last_heartbeat_at ?? $this->last_sync_at;
    }

    public function getIsOnlineAttribute(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->last_heartbeat_at) {
            return $this->last_heartbeat_at->diffInMinutes(now()) <= 3;
        }

        return false;
    }

    public function getModelAttribute(): string
    {
        return $this->model_name ?: 'MB360';
    }

    public function getLocationAttribute(): string
    {
        return $this->department_name ?: 'Tagum City Hall';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('status', 'ONLINE');
    }

    /**
     * Raw punch logs recorded by this device.
     */
    public function rawLogs(): HasMany
    {
        return $this->hasMany(AttendanceRawLog::class, 'device_serial_number', 'serial_number');
    }
}
