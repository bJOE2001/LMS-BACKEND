<?php

namespace App\Models;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only HRIS office directory backed by pmis2003.dbo.yOffice.
 *
 * This model must never be used for inserts, updates, or deletes.
 */
class HrisOffice extends Model
{
    private const CACHE_KEY = 'hris_offices.v1.all';

    private const STALE_CACHE_KEY = 'hris_offices.v1.all.stale';

    private const COOLDOWN_CACHE_KEY = 'hris_offices.v1.cooldown';

    private const CACHE_TTL_MINUTES = 10;

    private const STALE_CACHE_TTL_MINUTES = 120;

    private const CACHE_LOCK_SECONDS = 10;

    private const CACHE_LOCK_WAIT_SECONDS = 5;

    private const FAILURE_COOLDOWN_SECONDS = 30;

    protected $connection = 'hr';

    protected $table = 'yOffice';

    protected $primaryKey = 'Codes';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @var array<int, array{code: string, name: string, acronym: ?string, raw_acronym: ?string, is_active: bool}>|null */
    private static ?array $allRowsMemo = null;

    /** @var array<string, string>|null */
    private static ?array $acronymsByNameMemo = null;

    /** @var array<string, string>|null */
    private static ?array $acronymsByCodeMemo = null;

    /**
     * Scope query to active HRIS offices.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('Active', '1');
    }

    /**
     * Load all normalized yOffice rows using in-request memoization and resilient cache.
     *
     * @return Collection<int, object{code: string, name: string, acronym: ?string, raw_acronym: ?string, is_active: bool}>
     */
    public static function allCached(bool $activeOnly = false): Collection
    {
        $rows = self::loadNormalizedRows();

        $collection = collect($rows)->map(static fn (array $row): object => (object) $row);

        if ($activeOnly) {
            return $collection->filter(static fn (object $office): bool => (bool) $office->is_active)->values();
        }

        return $collection->values();
    }

    /**
     * Map normalized uppercase office Descriptions to normalized acronyms.
     *
     * @return array<string, string>
     */
    public static function acronymsByName(): array
    {
        if (self::$acronymsByNameMemo !== null) {
            return self::$acronymsByNameMemo;
        }

        $acronymsByName = [];

        foreach (self::loadNormalizedRows() as $row) {
            $lookupKey = self::normalizeLookupKey($row['name'] ?? null);
            $acronym = $row['acronym'] ?? null;

            if ($lookupKey === '' || $acronym === null) {
                continue;
            }

            if (! array_key_exists($lookupKey, $acronymsByName)) {
                $acronymsByName[$lookupKey] = $acronym;
            }
        }

        return self::$acronymsByNameMemo = $acronymsByName;
    }

    /**
     * Map trimmed yOffice.Codes values to normalized acronyms.
     *
     * @return array<string, string>
     */
    public static function acronymsByCode(): array
    {
        if (self::$acronymsByCodeMemo !== null) {
            return self::$acronymsByCodeMemo;
        }

        $acronymsByCode = [];

        foreach (self::loadNormalizedRows() as $row) {
            $code = self::trimNullableString($row['code'] ?? null);
            $acronym = $row['acronym'] ?? null;

            if ($code === null || $acronym === null) {
                continue;
            }

            if (! array_key_exists($code, $acronymsByCode)) {
                $acronymsByCode[$code] = $acronym;
            }
        }

        return self::$acronymsByCodeMemo = $acronymsByCode;
    }

    /**
     * Resolve an HRIS yOffice acronym from an office name / description.
     */
    public static function acronymForName(?string $officeName): ?string
    {
        $lookupKey = self::normalizeLookupKey($officeName);
        if ($lookupKey === '') {
            return null;
        }

        return self::acronymsByName()[$lookupKey] ?? null;
    }

    /**
     * Resolve an HRIS yOffice acronym from an office code (yOffice.Codes).
     */
    public static function acronymForCode(?string $officeCode): ?string
    {
        $code = self::trimNullableString($officeCode);
        if ($code === null) {
            return null;
        }

        return self::acronymsByCode()[$code] ?? null;
    }

    /**
     * Resolve an HRIS yOffice acronym for an LMS Department model (or fallback office name).
     */
    public static function acronymForDepartment(?Department $department, ?string $fallbackName = null): ?string
    {
        if ($department !== null) {
            $code = self::trimNullableString($department->getAttribute('code'));
            if ($code !== null) {
                $byCode = self::acronymForCode($code);
                if ($byCode !== null) {
                    return $byCode;
                }
            }

            $byName = self::acronymForName($department->name);
            if ($byName !== null) {
                return $byName;
            }
        }

        return self::acronymForName($fallbackName);
    }

    /**
     * Resolve the display label (HRIS acronym when available, otherwise office/department name).
     */
    public static function labelForDepartment(?Department $department, ?string $fallbackName = null): ?string
    {
        $acronym = self::acronymForDepartment($department, $fallbackName);
        if ($acronym !== null) {
            return $acronym;
        }

        return self::trimNullableString($department?->name) ?? self::trimNullableString($fallbackName);
    }

    /**
     * Normalize raw yOffice.Abbr values; falls back to initials when Abbr repeats the full name.
     */
    public static function normalizeAcronym(mixed $officeAcronym, mixed $officeName = null): ?string
    {
        $acronym = self::trimNullableString($officeAcronym);
        if ($acronym === null) {
            return null;
        }

        $officeNameText = self::trimNullableString($officeName);
        if (
            $officeNameText !== null
            && self::normalizeLookupKey($acronym) === self::normalizeLookupKey($officeNameText)
        ) {
            return self::buildOfficeInitials($officeNameText) ?? $acronym;
        }

        return $acronym;
    }

    /**
     * Normalize office name keys for case- and whitespace-insensitive lookups.
     */
    public static function normalizeLookupKey(mixed $officeName): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim((string) ($officeName ?? '')));
        if (! is_string($normalized) || $normalized === '') {
            return '';
        }

        return mb_strtoupper($normalized, 'UTF-8');
    }

    /**
     * Clear in-request memos and persistent cache entries for HRIS offices.
     */
    public static function flushCache(): void
    {
        self::$allRowsMemo = null;
        self::$acronymsByNameMemo = null;
        self::$acronymsByCodeMemo = null;

        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::STALE_CACHE_KEY);
        Cache::forget(self::COOLDOWN_CACHE_KEY);
    }

    /**
     * @return array<int, array{code: string, name: string, acronym: ?string, raw_acronym: ?string, is_active: bool}>
     */
    private static function loadNormalizedRows(): array
    {
        if (self::$allRowsMemo !== null) {
            return self::$allRowsMemo;
        }

        if (Cache::has(self::CACHE_KEY)) {
            $cached = Cache::get(self::CACHE_KEY, []);
            if (is_array($cached)) {
                return self::$allRowsMemo = $cached;
            }
        }

        $lockKey = self::CACHE_KEY.'.lock';

        try {
            $rows = Cache::lock($lockKey, self::CACHE_LOCK_SECONDS)->block(
                self::CACHE_LOCK_WAIT_SECONDS,
                static function (): array {
                    if (Cache::has(self::CACHE_KEY)) {
                        $cached = Cache::get(self::CACHE_KEY, []);
                        if (is_array($cached)) {
                            return $cached;
                        }
                    }

                    if (Cache::has(self::COOLDOWN_CACHE_KEY)) {
                        return self::resolveStaleOrEmpty();
                    }

                    try {
                        $freshRows = self::fetchLiveRows();
                        Cache::put(self::CACHE_KEY, $freshRows, now()->addMinutes(self::CACHE_TTL_MINUTES));
                        Cache::put(self::STALE_CACHE_KEY, $freshRows, now()->addMinutes(self::STALE_CACHE_TTL_MINUTES));
                        Cache::forget(self::COOLDOWN_CACHE_KEY);

                        return $freshRows;
                    } catch (Throwable $exception) {
                        Log::warning('HrisOffice: Failed to query HRIS yOffice directory.', [
                            'error' => $exception->getMessage(),
                        ]);
                        Cache::put(self::COOLDOWN_CACHE_KEY, true, now()->addSeconds(self::FAILURE_COOLDOWN_SECONDS));

                        return self::resolveStaleOrEmpty();
                    }
                }
            );

            return self::$allRowsMemo = $rows;
        } catch (LockTimeoutException $exception) {
            Log::warning('HrisOffice: Cache lock timeout while loading HRIS yOffice.', [
                'error' => $exception->getMessage(),
            ]);

            return self::$allRowsMemo = self::resolveStaleOrEmpty();
        }
    }

    /**
     * @return array<int, array{code: string, name: string, acronym: ?string, raw_acronym: ?string, is_active: bool}>
     */
    private static function fetchLiveRows(): array
    {
        return self::query()
            ->orderByDesc('Active')
            ->orderBy('Descriptions')
            ->get(['Codes', 'Descriptions', 'Abbr', 'Active'])
            ->map(static function (self $row): ?array {
                $name = self::trimNullableString($row->getAttribute('Descriptions'));
                if ($name === null) {
                    return null;
                }

                $rawAcronym = self::trimNullableString($row->getAttribute('Abbr'));
                $activeFlag = self::trimNullableString($row->getAttribute('Active'));

                return [
                    'code' => self::trimNullableString($row->getAttribute('Codes')) ?? '',
                    'name' => $name,
                    'raw_acronym' => $rawAcronym,
                    'acronym' => self::normalizeAcronym($rawAcronym, $name),
                    'is_active' => $activeFlag === '1',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{code: string, name: string, acronym: ?string, raw_acronym: ?string, is_active: bool}>
     */
    private static function resolveStaleOrEmpty(): array
    {
        if (Cache::has(self::STALE_CACHE_KEY)) {
            $stale = Cache::get(self::STALE_CACHE_KEY, []);
            if (is_array($stale)) {
                Cache::put(self::CACHE_KEY, $stale, now()->addMinute());

                return $stale;
            }
        }

        return [];
    }

    private static function buildOfficeInitials(string $officeName): ?string
    {
        $normalizedOfficeName = preg_replace('/\s+/', ' ', trim($officeName));
        if ($normalizedOfficeName === null || $normalizedOfficeName === '') {
            return null;
        }

        $normalizedOfficeName = preg_replace('/^OFFICE\s+OF\s+(?:THE\s+)?/i', '', $normalizedOfficeName) ?? $normalizedOfficeName;
        $words = preg_split('/[^A-Za-z0-9]+/', $normalizedOfficeName, -1, PREG_SPLIT_NO_EMPTY);
        if ($words === false || $words === []) {
            return null;
        }

        $initials = collect($words)
            ->reject(static fn (string $word): bool => in_array(strtoupper($word), ['AND', 'OF', 'THE'], true))
            ->map(static fn (string $word): string => strtoupper(substr($word, 0, 1)))
            ->implode('');

        return $initials !== '' ? $initials : null;
    }

    private static function trimNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed !== '' ? $trimmed : null;
    }
}
