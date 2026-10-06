<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model representing stored biometric templates (fingerprint, face) in BIO_DB.
 */
class BiometricTemplate extends Model
{
    protected $connection = 'bio';

    protected $table = 'tblBiometricTemplates';

    public const TYPE_FINGERPRINT = 'FINGERPRINT';

    public const TYPE_FACE = 'FACE';

    public const TYPE_PALM = 'PALM';

    protected $fillable = [
        'employee_control_no',
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
