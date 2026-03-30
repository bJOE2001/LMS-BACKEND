<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Leave type classification: ACCRUED, RESETTABLE, EVENT.
 * LOCAL LMS_DB only.
 */
class LeaveType extends Model
{
    public const SPECIAL_PRIVILEGE_LEAVE_NAME = 'Special Privilege Leave';
    public const SPECIAL_PRIVILEGE_LEGACY_NAMES = [
        'MCO6 Leave',
        'MC06 Leave',
        'MO6 Leave',
    ];

    protected $table = 'tblLeaveTypes';

    protected $fillable = [
        'name',
        'category',
        'accrual_rate',
        'accrual_day_of_month',
        'max_days',
        'is_credit_based',
        'resets_yearly',
        'requires_documents',
        'allowed_status',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'accrual_rate'        => 'decimal:2',
            'accrual_day_of_month' => 'integer',
            'max_days'            => 'integer',
            'is_credit_based'     => 'boolean',
            'resets_yearly'       => 'boolean',
            'requires_documents'  => 'boolean',
            'allowed_status'    => 'array',
        ];
    }

    public const CATEGORY_ACCRUED    = 'ACCRUED';
    public const CATEGORY_RESETTABLE = 'RESETTABLE';
    public const CATEGORY_EVENT      = 'EVENT';

    public const EMPLOYMENT_STATUS_REGULAR = 'regular';
    public const EMPLOYMENT_STATUS_ELECTIVE = 'elective';
    public const EMPLOYMENT_STATUS_CO_TERMINOUS = 'co_terminous';
    public const EMPLOYMENT_STATUS_CASUAL = 'casual';
    public const EMPLOYMENT_STATUS_CONTRACTUAL = 'contractual';

    public const EMPLOYMENT_STATUS_LABELS = [
        self::EMPLOYMENT_STATUS_REGULAR => 'Regular',
        self::EMPLOYMENT_STATUS_ELECTIVE => 'Elective',
        self::EMPLOYMENT_STATUS_CO_TERMINOUS => 'Co-Terminous',
        self::EMPLOYMENT_STATUS_CASUAL => 'Casual',
        self::EMPLOYMENT_STATUS_CONTRACTUAL => 'Contractual',
    ];

    // ─── Scopes ──────────────────────────────────────────────────────

    public function scopeAccrued($query)
    {
        return $query->where('category', self::CATEGORY_ACCRUED);
    }

    public function scopeResettable($query)
    {
        return $query->where('category', self::CATEGORY_RESETTABLE);
    }

    public function scopeEventBased($query)
    {
        return $query->where('category', self::CATEGORY_EVENT);
    }

    public function scopeWithoutLegacySpecialPrivilegeAliases($query)
    {
        return $query->whereNotIn('name', self::SPECIAL_PRIVILEGE_LEGACY_NAMES);
    }

    // ─── Relationships ───────────────────────────────────────────────

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveApplications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class);
    }

    public static function employmentStatusOptions(): array
    {
        $options = [];

        foreach (self::EMPLOYMENT_STATUS_LABELS as $value => $label) {
            $options[] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return $options;
    }

    public static function normalizeEmploymentStatusKey(mixed $status): ?string
    {
        $normalizedStatus = strtoupper(trim((string) ($status ?? '')));

        return match ($normalizedStatus) {
            '' => null,
            'REGULAR' => self::EMPLOYMENT_STATUS_REGULAR,
            'ELECTIVE' => self::EMPLOYMENT_STATUS_ELECTIVE,
            'CO-TERMINOUS', 'CO TERMINOUS', 'COTERMINOUS' => self::EMPLOYMENT_STATUS_CO_TERMINOUS,
            'CASUAL' => self::EMPLOYMENT_STATUS_CASUAL,
            'CONTRACTUAL' => self::EMPLOYMENT_STATUS_CONTRACTUAL,
            default => array_key_exists(
                strtolower(str_replace([' ', '-'], '_', $normalizedStatus)),
                self::EMPLOYMENT_STATUS_LABELS
            )
                ? strtolower(str_replace([' ', '-'], '_', $normalizedStatus))
                : null,
        };
    }

    public static function formatEmploymentStatusLabel(mixed $status): ?string
    {
        $statusKey = self::normalizeEmploymentStatusKey($status);
        if ($statusKey === null) {
            return null;
        }

        return self::EMPLOYMENT_STATUS_LABELS[$statusKey] ?? null;
    }

    public static function normalizeAllowedStatusesArray(mixed $allowedStatuses): ?array
    {
        if (is_string($allowedStatuses)) {
            $decoded = json_decode($allowedStatuses, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $allowedStatuses = $decoded;
            }
        }

        if (!is_array($allowedStatuses) || $allowedStatuses === []) {
            return null;
        }

        $normalized = [];
        foreach ($allowedStatuses as $status) {
            $statusKey = self::normalizeEmploymentStatusKey($status);
            if ($statusKey === null) {
                continue;
            }

            $normalized[$statusKey] = true;
        }

        if ($normalized === []) {
            return null;
        }

        $orderedKeys = [];
        foreach (array_keys(self::EMPLOYMENT_STATUS_LABELS) as $statusKey) {
            if (array_key_exists($statusKey, $normalized)) {
                $orderedKeys[] = $statusKey;
            }
        }

        return $orderedKeys !== [] ? $orderedKeys : null;
    }

    public function normalizedAllowedStatuses(): array
    {
        return self::normalizeAllowedStatusesArray($this->getAttribute('allowed_status')) ?? [];
    }

    public function allowedStatusLabels(): array
    {
        $labels = [];

        foreach ($this->normalizedAllowedStatuses() as $statusKey) {
            $label = self::EMPLOYMENT_STATUS_LABELS[$statusKey] ?? null;
            if ($label !== null) {
                $labels[] = $label;
            }
        }

        return $labels;
    }

    public function allowsEmploymentStatus(mixed $status): bool
    {
        $allowedStatuses = $this->normalizedAllowedStatuses();
        if ($allowedStatuses === []) {
            return true;
        }

        $statusKey = self::normalizeEmploymentStatusKey($status);
        if ($statusKey === null) {
            return false;
        }

        return in_array($statusKey, $allowedStatuses, true);
    }

    public static function normalizeLeaveTypeName(mixed $name): string
    {
        $normalized = preg_replace('/\s+/', ' ', strtoupper(trim((string) ($name ?? ''))));

        return is_string($normalized) ? $normalized : '';
    }

    public static function specialPrivilegeAliasNames(): array
    {
        return [
            self::SPECIAL_PRIVILEGE_LEAVE_NAME,
            ...self::SPECIAL_PRIVILEGE_LEGACY_NAMES,
        ];
    }

    public static function isSpecialPrivilegeAliasName(mixed $name): bool
    {
        $normalizedName = self::normalizeLeaveTypeName($name);
        if ($normalizedName === '') {
            return false;
        }

        foreach (self::specialPrivilegeAliasNames() as $alias) {
            if ($normalizedName === self::normalizeLeaveTypeName($alias)) {
                return true;
            }
        }

        return false;
    }

    public static function isLegacySpecialPrivilegeAliasName(mixed $name): bool
    {
        $normalizedName = self::normalizeLeaveTypeName($name);
        if ($normalizedName === '') {
            return false;
        }

        foreach (self::SPECIAL_PRIVILEGE_LEGACY_NAMES as $alias) {
            if ($normalizedName === self::normalizeLeaveTypeName($alias)) {
                return true;
            }
        }

        return false;
    }

    public static function canonicalizeLeaveTypeName(mixed $name): ?string
    {
        $trimmedName = trim((string) ($name ?? ''));
        if ($trimmedName === '') {
            return null;
        }

        return self::isSpecialPrivilegeAliasName($trimmedName)
            ? self::SPECIAL_PRIVILEGE_LEAVE_NAME
            : $trimmedName;
    }

    public static function resolveSpecialPrivilegeLeaveTypeId(): ?int
    {
        static $resolved = false;
        static $cachedValue = null;

        if ($resolved) {
            return $cachedValue;
        }

        $value = self::query()
            ->whereRaw('UPPER(LTRIM(RTRIM(name))) = ?', [self::normalizeLeaveTypeName(self::SPECIAL_PRIVILEGE_LEAVE_NAME)])
            ->value('id');

        $cachedValue = $value !== null ? (int) $value : null;
        $resolved = true;

        return $cachedValue;
    }

    public static function resolveSpecialPrivilegeRelatedTypeIds(): array
    {
        static $resolved = false;
        static $cachedValues = [];

        if ($resolved) {
            return $cachedValues;
        }

        $query = self::query()->select(['id', 'name']);
        $query->where(function ($nestedQuery): void {
            foreach (self::specialPrivilegeAliasNames() as $index => $name) {
                $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                $nestedQuery->{$method}(
                    'UPPER(LTRIM(RTRIM(name))) = ?',
                    [self::normalizeLeaveTypeName($name)]
                );
            }
        });

        $cachedValues = $query
            ->get()
            ->map(fn (self $leaveType): int => (int) $leaveType->id)
            ->filter(fn (int $leaveTypeId): bool => $leaveTypeId > 0)
            ->values()
            ->all();

        $resolved = true;

        return $cachedValues;
    }

    public static function resolveCanonicalLeaveTypeId(?int $leaveTypeId): ?int
    {
        $normalizedLeaveTypeId = (int) ($leaveTypeId ?? 0);
        if ($normalizedLeaveTypeId <= 0) {
            return null;
        }

        static $nameCache = [];
        if (!array_key_exists($normalizedLeaveTypeId, $nameCache)) {
            $nameCache[$normalizedLeaveTypeId] = self::query()
                ->whereKey($normalizedLeaveTypeId)
                ->value('name');
        }

        $leaveTypeName = $nameCache[$normalizedLeaveTypeId];
        if (!self::isSpecialPrivilegeAliasName($leaveTypeName)) {
            return $normalizedLeaveTypeId;
        }

        return self::resolveSpecialPrivilegeLeaveTypeId() ?? $normalizedLeaveTypeId;
    }

    public static function isSpecialPrivilegeType(?self $leaveType = null, ?int $leaveTypeId = null): bool
    {
        if ($leaveType instanceof self && self::isSpecialPrivilegeAliasName($leaveType->name)) {
            return true;
        }

        $normalizedLeaveTypeId = (int) ($leaveTypeId ?? 0);
        if ($normalizedLeaveTypeId <= 0) {
            return false;
        }

        $canonicalLeaveTypeId = self::resolveCanonicalLeaveTypeId($normalizedLeaveTypeId);
        $specialPrivilegeLeaveTypeId = self::resolveSpecialPrivilegeLeaveTypeId();

        return $canonicalLeaveTypeId !== null
            && $specialPrivilegeLeaveTypeId !== null
            && $canonicalLeaveTypeId === $specialPrivilegeLeaveTypeId;
    }
}
