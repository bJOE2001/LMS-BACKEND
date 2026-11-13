<?php

namespace App\Models\Attendance;

use App\Models\Department;
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

    public const STATUS_PENDING_APPROVAL = 'PENDING_APPROVAL';

    public const STATUS_ONLINE = 'ONLINE';

    public const STATUS_OFFLINE = 'OFFLINE';

    public const STATUS_BLOCKED = 'BLOCKED';

    public const MODE_ADMS = 'ADMS';

    public const MODE_LAN_POLL = 'LAN_POLL';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'model_name' => '',
    ];

    protected $fillable = [
        'device_name',
        'serial_number',
        'ip_address',
        'port',
        'comm_key',
        'communication_mode',
        'department_id',
        'department_name',
        'is_primary',
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
            'is_primary' => 'boolean',
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
        if (! $this->is_active || $this->status === self::STATUS_PENDING_APPROVAL || $this->status === self::STATUS_BLOCKED) {
            return false;
        }

        if ($this->last_heartbeat_at) {
            return $this->last_heartbeat_at->diffInMinutes(now()) <= 3;
        }

        return false;
    }

    public function getModelAttribute(): ?string
    {
        return $this->model_name ?: null;
    }

    /**
     * Resolve normalized template communication mode (ADMS vs LAN_POLL).
     */
    public function getTemplateCommunicationMode(): string
    {
        return $this->communication_mode === self::MODE_LAN_POLL
            ? self::MODE_LAN_POLL
            : self::MODE_ADMS;
    }

    public function getLocationAttribute(): string
    {
        return $this->department_name ?: 'Tagum City Hall';
    }

    public function department(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
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
