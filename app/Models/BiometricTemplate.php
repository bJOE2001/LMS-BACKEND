<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricTemplate extends Model
{
    use HasFactory;

    protected $connection = 'bio';

    protected $table = 'tblBiometricTemplates';

    public const TYPE_FP = 'FP';

    public const TYPE_FACE = 'FACE';

    public const TYPE_BIODATA = 'BIODATA';

    public const TYPE_BIOPHOTO = 'BIOPHOTO';

    protected $fillable = [
        'employee_control_no',
        'biometric_pin',
        'template_type',
        'finger_index',
        'template_data',
        'template_size',
        'template_version',
        'valid',
        'source_device_sn',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'finger_index' => 'integer',
            'template_size' => 'integer',
            'valid' => 'integer',
        ];
    }

    /**
     * Relationship to Biometric Enrollment.
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(BiometricEnrollment::class, 'employee_control_no', 'employee_control_no');
    }

    /**
     * Format into ZKTeco ADMS payload command string.
     */
    public function toCommandPayload(): string
    {
        $pin = $this->biometric_pin ?: $this->employee_control_no;

        if ($this->template_type === self::TYPE_BIODATA || $this->template_type === self::TYPE_FACE) {
            return "DATA BIODATA PIN={$pin}\tNo=0\tIndex=0\tValid={$this->valid}\tType=9\tMajorVer=1\tMinorVer=0\tFormat=0\tTmp={$this->template_data}";
        }

        // Default: Fingerprint template (ZKFinger 10.0 / 9.0)
        $fid = $this->finger_index ?? 0;
        $size = $this->template_size ?: strlen($this->template_data);

        return "DATA FP PIN={$pin}\tFID={$fid}\tSize={$size}\tValid={$this->valid}\tTMP={$this->template_data}";
    }
}
