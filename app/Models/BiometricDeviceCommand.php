<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model representing outgoing ADMS commands queued for ZKTeco terminals in BIO_DB.
 * Formatted as: C:<id>:<command_payload>
 */
class BiometricDeviceCommand extends Model
{
    protected $connection = 'bio';

    protected $table = 'tblBiometricDeviceCommands';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_SENT = 'SENT';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const CMD_DATA_USER = 'DATA_USER';

    public const CMD_DELETE_USER = 'DELETE_USER';

    public const CMD_INFO = 'INFO';

    protected $fillable = [
        'device_serial_number',
        'command_type',
        'command_payload',
        'employee_control_no',
        'employee_name',
        'status',
        'sent_at',
        'executed_at',
        'response_payload',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'executed_at' => 'datetime',
        ];
    }

    /**
     * Build an ADMS formatted command string for creating/updating a user.
     * Format: DATA USER PIN={controlNo}\tName={name}\tPri=0\tPasswd=\tCard=\tGrp=1\tTZ=0000000100000000
     */
    public static function buildDataUserCommand(string $controlNo, string $name): string
    {
        $cleanName = trim(preg_replace('/[^a-zA-Z0-9\s.,-]/', '', $name) ?? 'EMPLOYEE');
        if ($cleanName === '') {
            $cleanName = 'EMP '.$controlNo;
        }

        return "DATA USER PIN={$controlNo}\tName={$cleanName}\tPri=0\tPasswd=\tCard=\tGrp=1\tTZ=0000000100000000";
    }
}
