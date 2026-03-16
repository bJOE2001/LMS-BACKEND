<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Employee model for local LMS employee records.
 *
 * Active columns:
 * - control_no (PK)
 * - surname
 * - firstname
 * - middlename
 * - birth_date
 * - office
 * - status
 * - designation
 * - rate_mon
 */
class Employee extends Model
{
    use HasFactory;

    protected $table = 'tblEmployees';

    protected $primaryKey = 'control_no';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'control_no',
        'surname',
        'firstname',
        'middlename',
        'birth_date',
        'office',
        'status',
        'designation',
        'rate_mon',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'rate_mon' => 'decimal:2',
        ];
    }

    /**
     * Resolve department by matching office name to departments.name.
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'office', 'name');
    }

    public function leaveApplications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class, 'erms_control_no', 'control_no');
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class, 'employee_id');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->firstname} {$this->surname}");
    }

    public function scopeMatchingControlNo(Builder $query, mixed $controlNo): Builder
    {
        $rawControlNo = trim((string) ($controlNo ?? ''));
        if ($rawControlNo === '') {
            return $query->whereRaw('1 = 0');
        }

        $normalizedControlNo = self::normalizeControlNoInt($rawControlNo);

        return $query->where(function (Builder $nestedQuery) use ($rawControlNo, $normalizedControlNo): void {
            $nestedQuery->where('control_no', $rawControlNo);

            if ($normalizedControlNo !== null && $normalizedControlNo !== $rawControlNo) {
                $nestedQuery->orWhere('control_no', $normalizedControlNo);
            }
        });
    }

    public static function findByControlNo(mixed $controlNo): ?self
    {
        return self::query()
            ->matchingControlNo($controlNo)
            ->first();
    }

    private static function normalizeControlNoInt(mixed $controlNo): ?string
    {
        $normalized = ltrim(trim((string) ($controlNo ?? '')), '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        return preg_match('/^\d+$/', $normalized) ? $normalized : null;
    }

    /**
     * HRIS-to-LMS field map for supported employee columns.
     */
    public static function hrisColumnMap(): array
    {
        return [
            'ControlNo' => 'control_no',
            'Surname' => 'surname',
            'Firstname' => 'firstname',
            'Middlename' => 'middlename',
            'BirthDate' => 'birth_date',
            'Office' => 'office',
            'Status' => 'status',
            'Designation' => 'designation',
            'RateMon' => 'rate_mon',
        ];
    }
}
