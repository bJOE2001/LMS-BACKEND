<?php

namespace App\Models\Attendance;

use Illuminate\Database\Eloquent\Model;

/**
 * Model representing stored biometric templates isolated by communication_mode (ADMS vs LAN_POLL) in BIO_DB.
 */
class BiometricTemplate extends Model
{
    protected $connection = 'bio';

    protected $table = 'tblBiometricTemplates';

    public const MODE_ADMS = 'ADMS';

    public const MODE_LAN_POLL = 'LAN_POLL';

    public const TYPE_FINGERPRINT = 'FINGERPRINT';

    public const TYPE_FACE = 'FACE';

    public const TYPE_PALM = 'PALM';

    protected $fillable = [
        'employee_control_no',
        'communication_mode',
        'biometric_type',
        'finger_id',
        'template_size',
        'valid',
        'template_version',
        'template_data',
        'raw_payload',
        'source_device_sn',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'finger_id' => 'integer',
            'template_size' => 'integer',
            'valid' => 'integer',
        ];
    }
}
