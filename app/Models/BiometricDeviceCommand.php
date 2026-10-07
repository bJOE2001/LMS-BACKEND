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

    public const CMD_CHECK = 'CHECK';

    public const CMD_DELETE_USER = 'DELETE_USER';

    public const CMD_INFO = 'INFO';

    public const CMD_UPDATE_TEMPLATE = 'UPDATE_TEMPLATE';

    public const CMD_DATA_FP = 'DATA_FP';

    public const CMD_QUERY = 'QUERY';

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

    /**
     * Build an ADMS command to delete a user from a biometric terminal.
     * Format: DATA DELETE user Pin={controlNo}
     */
    public static function buildDeleteUserCommand(string $controlNo): string
    {
        return "DATA DELETE user Pin={$controlNo}";
    }

    /**
     * Build an ADMS command to upload a native fingerprint template to an MB360 device.
     * Format: DATA FP PIN={controlNo}\tFID={fingerId}\tSize={size}\tValid={valid}\tTMP={templateData}
     */
    public static function buildDataFpCommand(string $controlNo, int $fingerId, int $size, int $valid, string $templateData): string
    {
        return "DATA UPDATE FINGERTMP PIN={$controlNo}\tFID={$fingerId}\tSize={$size}\tValid={$valid}\tTMP={$templateData}";
    }

    /**
     * Build an ADMS command to upload a V10 fingerprint template to a device.
     */
    public static function buildDataTemplateV10Command(string $controlNo, int $fingerId, string $templateData, ?int $size = null, int $valid = 1): string
    {
        $sizePart = $size !== null ? "Size={$size}\t" : '';

        return "DATA UPDATE templatev10 Pin={$controlNo}\t{$sizePart}FingerID={$fingerId}\tValid={$valid}\tTemplate={$templateData}";
    }

    /**
     * Build an ADMS command to upload a biodata (face or fingerprint) template to a device.
     */
    public static function buildDataBiodataCommand(string $controlNo, int $type, int $index, string $templateData, int $valid = 1): string
    {
        return "DATA UPDATE biodata Pin={$controlNo}\tNo=0\tIndex={$index}\tValid={$valid}\tDuress=0\tType={$type}\tTmp={$templateData}";
    }

    /**
     * Build an ADMS query command to request templatev10 from a terminal for a user.
     */
    public static function buildQueryTemplateCommand(string $controlNo): string
    {
        return "DATA QUERY tablename=templatev10,fielddesc=*,filter=Pin={$controlNo}";
    }

    /**
     * Build an ADMS query command to request biodata from a terminal for a user.
     */
    public static function buildQueryBiodataCommand(string $controlNo): string
    {
        return "DATA QUERY tablename=biodata,fielddesc=*,filter=Pin={$controlNo}";
    }

    /**
     * Build an ADMS query command to request user info from a terminal.
     */
    public static function buildQueryUserCommand(string $controlNo): string
    {
        return "DATA QUERY tablename=user,fielddesc=*,filter=Pin={$controlNo}";
    }

    /**
     * Build an ADMS command to retrieve device information/options.
     */
    public static function buildInfoCommand(): string
    {
        return 'INFO';
    }
}
