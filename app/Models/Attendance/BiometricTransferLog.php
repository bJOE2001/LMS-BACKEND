<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model representing audit records for biometric transfers and terminal assignments.
 *
 * @property int $id
 * @property string $employee_control_no
 * @property string|null $employee_name
 * @property string $action_type
 * @property int|null $source_department_id
 * @property string|null $source_office_name
 * @property string|null $source_device_sn
 * @property int|null $source_area_id
 * @property int|null $target_department_id
 * @property string|null $target_office_name
 * @property string|null $target_device_sn
 * @property int|null $target_area_id
 * @property string $status
 * @property string|null $remarks
 * @property int|null $performed_by_user_id
 * @property string|null $performed_by_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class BiometricTransferLog extends Model
{
    public const ACTION_TRANSFER = 'TRANSFER';

    public const ACTION_ASSIGN = 'ASSIGN';

    public const ACTION_REMOVE = 'REMOVE';

    public const ACTION_REGISTRATION = 'REGISTRATION';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_PENDING = 'PENDING';

    /**
     * Database connection dedicated to biometric and attendance logging.
     *
     * @var string
     */
    protected $connection = 'bio';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'tblBiometricTransferLogs';

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'employee_control_no',
        'employee_name',
        'action_type',
        'source_department_id',
        'source_office_name',
        'source_device_sn',
        'source_area_id',
        'target_department_id',
        'target_office_name',
        'target_device_sn',
        'target_area_id',
        'status',
        'remarks',
        'performed_by_user_id',
        'performed_by_name',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'source_department_id' => 'integer',
            'source_area_id' => 'integer',
            'target_department_id' => 'integer',
            'target_area_id' => 'integer',
            'performed_by_user_id' => 'integer',
        ];
    }
}
