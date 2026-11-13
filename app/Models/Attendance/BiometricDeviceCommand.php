<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model representing outgoing ADMS commands queued for ZKTeco MB360 terminals in BIO_DB.
 * Wire format delivered on GET /iclock/getrequest: C:<id>:<command_payload>
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

    public const CMD_UPDATE_TEMPLATE = 'UPDATE_TEMPLATE';

    public const CMD_DELETE_USER = 'DELETE_USER';

    public const CMD_QUERY = 'QUERY';

    public const CMD_INFO = 'INFO';

    protected $fillable = [
        'device_serial_number',
        'command_type',
        'command_payload',
        'employee_control_no',
        'employee_name',
        'status',
        'return_code',
        'response_payload',
        'sent_at',
        'executed_at',
        'created_by_user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'return_code' => 'integer',
            'sent_at' => 'datetime',
            'executed_at' => 'datetime',
            'created_by_user_id' => 'integer',
        ];
    }

    /**
     * Build an ADMS command string for creating or updating a user profile on the terminal.
     */
    public static function buildDataUserCommand(string $controlNo, string $name, int $privilege = 0, string $password = '', string $card = ''): string
    {
        $cleanName = trim(preg_replace('/[^a-zA-Z0-9\s.,-]/', '', $name) ?? 'EMPLOYEE');
        if ($cleanName === '') {
            $cleanName = 'EMP '.$controlNo;
        }
        $cleanName = substr($cleanName, 0, 24);

        return "DATA UPDATE USERINFO PIN={$controlNo}\tName={$cleanName}\tPri={$privilege}\tPasswd={$password}\tCard={$card}\tGrp=1\tTZ=0000000100000000";
    }

    /**
     * Build an ADMS command string to delete a user and all their biometric templates from a terminal.
     */
    public static function buildDeleteUserCommand(string $controlNo): string
    {
        return "DATA DELETE USERINFO PIN={$controlNo}";
    }

    /**
     * Build an ADMS command string for pushing a fingerprint (ZKFinger VX10.0), face (ZKFace VX7.0), or biodata template.
     */
    public static function buildTemplateCommand(BiometricTemplate $template): ?string
    {
        $tmp = str_replace(["\r", "\n"], '', trim((string) $template->template_data));
        if ($tmp === '') {
            return null;
        }

        $pin = $template->employee_control_no;
        $fid = (int) $template->finger_id;
        $valid = (int) ($template->valid ?? 1);
        $size = strlen($tmp);
        $ver = (string) ($template->template_version ?? '10');

        if (str_starts_with($ver, 'biodata:')) {
            $parts = explode(':', $ver);
            $bioType = (int) ($parts[1] ?? ($template->biometric_type === BiometricTemplate::TYPE_FACE ? 9 : 1));
            $verNums = explode('.', $parts[2] ?? '10.0');
            $majorVer = (int) ($verNums[0] ?? 10);
            $minorVer = (int) ($verNums[1] ?? 0);

            return "DATA UPDATE BIODATA Pin={$pin}\tNo={$fid}\tIndex=0\tValid={$valid}\tDuress=0\tType={$bioType}\tMajorVer={$majorVer}\tMinorVer={$minorVer}\tFormat=0\tTmp={$tmp}";
        }

        if ($template->biometric_type === BiometricTemplate::TYPE_FACE) {
            return "DATA UPDATE FACE PIN={$pin}\tFID={$fid}\tSize={$size}\tValid={$valid}\tTMP={$tmp}";
        }

        return "DATA UPDATE FINGERTMP PIN={$pin}\tFID={$fid}\tSize={$size}\tValid={$valid}\tTMP={$tmp}";
    }
}
