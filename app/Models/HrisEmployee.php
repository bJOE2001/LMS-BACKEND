<?php

namespace App\Models;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only HRIS employee directory backed by:
 * - xPersonal
 * - vwpartitionforseparated (RN = 1)
 *
 * Active/inactive is derived from HRIS date windows:
 *   GETDATE() BETWEEN fromDate AND toDate
 */
class HrisEmployee
{
    private const HR_CONNECTION = 'hr';
    private const SNAPSHOT_TABLE = 'tblEmployees';
    private const PERSONAL_TABLE = 'xPersonal';
    private const PARTITION_VIEW = 'vwpartitionforseparated';
    private const OFFICE_TABLE = 'yOffice';
    private const SNAPSHOT_SYNC_CHUNK_SIZE = 250;
    private const SNAPSHOT_WRITE_CHUNK_SIZE = 100;
    private const CACHE_TTL_MINUTES = 5;
    private const STALE_CACHE_TTL_MINUTES = 60;
    private const CACHE_LOCK_SECONDS = 15;
    private const CACHE_LOCK_WAIT_SECONDS = 6;
    private const CACHE_FAILURE_COOLDOWN_SECONDS = 30;
    private const CACHE_VERSION_KEY = 'hris_employees.cache_version';
    /** @var array<string, object|null> */
    private static array $singleLookupMemo = [];
    /** @var array<string, array{department_id:int|null, department_name:string}>|null */
    private static ?array $assignedDepartmentNamesMemo = null;
    /** @var array<string, string>|null */
    private static ?array $officeAcronymsByOfficeNameMemo = null;
    private static ?string $cacheVersionMemo = null;

    /**
     * Build the canonical HRIS employee query with normalized aliases.
     */
    public static function query(?bool $activeOnly = null, bool $includeOfficeAcronym = true): Builder
    {
        $query = DB::connection(self::HR_CONNECTION)
            ->table(self::PERSONAL_TABLE.' as xp')
            ->join(self::PARTITION_VIEW.' as vp', function ($join): void {
                $join->on('xp.ControlNo', '=', 'vp.ControlNo')
                    ->where('vp.RN', '=', 1);
            })
            ->selectRaw('LTRIM(RTRIM(CONVERT(VARCHAR(64), xp.ControlNo))) as control_no')
            ->selectRaw('xp.Surname as surname')
            ->selectRaw('xp.Firstname as firstname')
            ->selectRaw('xp.MIddlename as middlename')
            ->selectRaw('xp.BirthDate as birth_date')
            ->selectRaw('vp.Office as office')
            ->selectRaw('vp.Status as status')
            ->selectRaw('vp.Designation as designation')
            ->selectRaw('vp.RateMon as rate_mon')
            ->selectRaw('vp.FromDate as from_date')
            ->selectRaw('vp.ToDate as to_date')
            ->selectRaw(
                "CASE WHEN vp.FromDate IS NOT NULL AND vp.ToDate IS NOT NULL AND GETDATE() BETWEEN vp.FromDate AND vp.ToDate THEN CAST(1 AS bit) ELSE CAST(0 AS bit) END as is_active"
            )
            ->selectRaw(
                "CASE WHEN vp.FromDate IS NOT NULL AND vp.ToDate IS NOT NULL AND GETDATE() BETWEEN vp.FromDate AND vp.ToDate THEN 'ACTIVE' ELSE 'INACTIVE' END as activity_status"
            );

        if ($includeOfficeAcronym) {
            $query->leftJoin(self::OFFICE_TABLE.' as yo', function ($join): void {
                $join->on(
                    DB::raw('LTRIM(RTRIM(vp.OffCode))'),
                    '=',
                    DB::raw('LTRIM(RTRIM(yo.Codes))')
                );
            })->selectRaw('NULLIF(LTRIM(RTRIM(CONVERT(VARCHAR(255), yo.Abbr))), \'\') as officeAcronym');
        } else {
            $query->selectRaw('CAST(NULL AS VARCHAR(255)) as officeAcronym');
        }

        self::applyActiveWindowFilter($query, $activeOnly);

        return $query;
    }

    public static function allCached(?bool $activeOnly = null): Collection
    {
        $cacheKey = self::cacheKey('all', $activeOnly);
        $fallbackRows = self::applyDepartmentAssignments(self::snapshotAllRows($activeOnly));

        $rows = self::rememberResilient(
            $cacheKey,
            static function () use ($activeOnly): array {
                $rows = self::orderedQuery($activeOnly, true)
                    ->get()
                    ->map(static fn (object $employee): array => (array) $employee)
                    ->values()
                    ->all();

                self::syncSnapshotRows($rows);

                return self::applyDepartmentAssignments($rows);
            },
            $fallbackRows,
            'allCached',
            [
                'active_only' => $activeOnly,
            ]
        );

        return collect($rows)->map(static fn (array $row): object => (object) $row);
    }

    public static function countCached(?bool $activeOnly = null): int
    {
        $cacheKey = self::cacheKey('count', $activeOnly);
        $fallbackCount = self::snapshotCount($activeOnly);

        return (int) self::rememberResilient(
            $cacheKey,
            static fn (): int => (int) self::partitionQuery($activeOnly)->count(),
            $fallbackCount,
            'countCached',
            [
                'active_only' => $activeOnly,
            ]
        );
    }

    public static function countSnapshot(?bool $activeOnly = null): int
    {
        return self::snapshotCount($activeOnly);
    }

    public static function allSnapshot(?bool $activeOnly = null): Collection
    {
        $rows = self::applyDepartmentAssignments(self::snapshotAllRows($activeOnly));

        return collect($rows)->map(static fn (array $row): object => (object) $row);
    }

    /**
     * Build an employee directory keyed by normalized control number.
     *
     * @param array<int, mixed> $controlNos
     * @return array<string, object>
     */
    public static function directoryByControlNos(array $controlNos, ?bool $activeOnly = null): array
    {
        $lookupValues = self::controlNoLookupValues($controlNos);
        if ($lookupValues === []) {
            return [];
        }

        $cacheKey = self::lookupCacheKey('directory_lookup', $lookupValues, $activeOnly);
        $fallbackRows = self::applyDepartmentAssignments(
            self::snapshotDirectoryRowsByControlNos($lookupValues, $activeOnly)
        );

        $rows = self::rememberResilient(
            $cacheKey,
            static function () use ($lookupValues, $activeOnly): array {
                return self::applyDepartmentAssignments(
                    self::fetchDirectoryRowsByControlNos($lookupValues, $activeOnly)
                );
            },
            $fallbackRows,
            'directoryByControlNos',
            [
                'active_only' => $activeOnly,
                'lookup_count' => count($lookupValues),
            ]
        );

        return self::keyEmployeeDirectory($rows, $activeOnly);
    }

    /**
     * Get all employees for a given office/department.
     */
    public static function allByOffice(?string $officeName, ?bool $activeOnly = null): Collection
    {
        $normalizedOfficeName = trim((string) ($officeName ?? ''));
        if ($normalizedOfficeName === '') {
            return collect();
        }

        return collect(self::directoryByOffice($normalizedOfficeName, $activeOnly))->values();
    }

    /**
     * Get canonical control numbers for a given office/department.
     *
     * @return array<int, string>
     */
    public static function controlNosByOffice(?string $officeName, ?bool $activeOnly = null): array
    {
        $normalizedOfficeName = trim((string) ($officeName ?? ''));
        if ($normalizedOfficeName === '') {
            return [];
        }

        return collect(self::directoryByOffice($normalizedOfficeName, $activeOnly))
            ->map(static fn (object $employee): string => trim((string) ($employee->control_no ?? '')))
            ->filter(static fn (string $controlNo): bool => $controlNo !== '')
            ->uniqueStrict()
            ->values()
            ->all();
    }

    /**
     * Find one employee by control number (supports zero-padded variants).
     */
    public static function findByControlNo(string $controlNo, ?bool $activeOnly = null): ?object
    {
        $controlNo = trim($controlNo);
        if ($controlNo === '') {
            return null;
        }

        $memoKey = self::singleLookupMemoKey($controlNo, $activeOnly);
        if (array_key_exists($memoKey, self::$singleLookupMemo)) {
            return self::$singleLookupMemo[$memoKey];
        }

        $directory = self::directoryByControlNos([$controlNo], $activeOnly);
        $normalizedControlNo = self::normalizeControlNoInt($controlNo);
        $lookupKey = $normalizedControlNo ?? trim($controlNo);
        $resolvedEmployee = $directory[$lookupKey] ?? null;

        self::$singleLookupMemo[$memoKey] = $resolvedEmployee instanceof \stdClass ? $resolvedEmployee : null;

        return self::$singleLookupMemo[$memoKey];
    }

    /**
     * Check if an employee exists in HRIS by control number.
     */
    public static function existsByControlNo(string $controlNo, ?bool $activeOnly = null): bool
    {
        return self::findByControlNo($controlNo, $activeOnly) !== null;
    }

    private static function orderedQuery(?bool $activeOnly = null, bool $includeOfficeAcronym = true): Builder
    {
        return self::query($activeOnly, $includeOfficeAcronym)
            ->orderByRaw('LTRIM(RTRIM(xp.Surname))')
            ->orderByRaw('LTRIM(RTRIM(xp.Firstname))')
            ->orderByRaw('LTRIM(RTRIM(CONVERT(VARCHAR(64), xp.ControlNo)))');
    }

    private static function partitionQuery(?bool $activeOnly = null): Builder
    {
        $query = DB::connection(self::HR_CONNECTION)
            ->table(self::PARTITION_VIEW.' as vp')
            ->where('vp.RN', 1);

        self::applyActiveWindowFilter($query, $activeOnly);

        return $query;
    }

    private static function cacheKey(string $suffix, ?bool $activeOnly): string
    {
        $activityKey = match ($activeOnly) {
            true => 'active',
            false => 'inactive',
            default => 'all',
        };

        return 'hris_employees.'.self::cacheVersion().".{$suffix}.{$activityKey}";
    }

    public static function flushCache(): void
    {
        Cache::forget(self::cacheKey('all', null));
        Cache::forget(self::cacheKey('all', true));
        Cache::forget(self::cacheKey('all', false));
        Cache::forget(self::cacheKey('count', null));
        Cache::forget(self::cacheKey('count', true));
        Cache::forget(self::cacheKey('count', false));
        self::$singleLookupMemo = [];
        self::$assignedDepartmentNamesMemo = null;
        self::$officeAcronymsByOfficeNameMemo = null;
        self::$cacheVersionMemo = 'v'.str_replace('.', '', (string) microtime(true));
        Cache::forever(self::CACHE_VERSION_KEY, self::$cacheVersionMemo);
    }

    public static function syncSnapshot(?string $officeName = null): int
    {
        if (!self::snapshotTableExists()) {
            return 0;
        }

        $normalizedOfficeName = trim((string) ($officeName ?? ''));

        if ($normalizedOfficeName === '') {
            $syncedRows = self::syncSnapshotInChunks();
        } else {
            $rows = self::fetchLiveDirectoryRowsByOffice($normalizedOfficeName, null);
            $syncedRows = self::syncSnapshotRows($rows);
        }

        self::flushCache();

        return $syncedRows;
    }

    public static function repairSnapshotMissingEmploymentFields(?string $officeName = null): int
    {
        if (!self::snapshotTableExists()) {
            return 0;
        }

        $repairControlNos = self::snapshotControlNosMissingEmploymentFields($officeName);
        $repairedRows = 0;

        foreach (array_chunk($repairControlNos, self::SNAPSHOT_SYNC_CHUNK_SIZE) as $controlNoChunk) {
            $missingBefore = array_keys(self::rowsByNormalizedControlNo(
                array_values(array_filter(
                    self::snapshotDirectoryRowsByControlNos($controlNoChunk, null),
                    static fn (array $row): bool => self::rowHasMissingEmploymentFields($row)
                ))
            ));

            if ($missingBefore === []) {
                continue;
            }

            try {
                self::fetchDirectoryRowsByControlNos($controlNoChunk, null);
            } catch (Throwable $exception) {
                self::reportFailure('repairSnapshotMissingEmploymentFields.chunk', $exception, [
                    'chunk_size' => count($controlNoChunk),
                    'first_control_no' => $controlNoChunk[0] ?? null,
                    'office' => trim((string) ($officeName ?? '')) ?: null,
                ]);

                continue;
            }

            $missingAfter = array_keys(self::rowsByNormalizedControlNo(
                array_values(array_filter(
                    self::snapshotDirectoryRowsByControlNos($controlNoChunk, null),
                    static fn (array $row): bool => self::rowHasMissingEmploymentFields($row)
                ))
            ));

            $repairedRows += count(array_diff($missingBefore, $missingAfter));
        }

        self::flushCache();

        return $repairedRows;
    }

    public static function refreshLocalSnapshot(): int
    {
        if (!self::snapshotTableExists()) {
            return 0;
        }

        $rows = self::snapshotAllRows(null);
        if ($rows === []) {
            self::flushCache();

            return 0;
        }

        $now = now();
        $records = collect(self::rowsByNormalizedControlNo($rows))
            ->map(static function (array $row) use ($now): array {
                $isActive = self::isCurrentlyActive($row['from_date'] ?? null, $row['to_date'] ?? null);

                return [
                    'control_no' => trim((string) ($row['control_no'] ?? '')),
                    'surname' => self::trimStringOrBlank($row['surname'] ?? null),
                    'firstname' => self::trimStringOrBlank($row['firstname'] ?? null),
                    'middlename' => self::trimStringOrBlank($row['middlename'] ?? null),
                    'office' => self::trimStringOrBlank($row['office'] ?? null),
                    'status' => self::trimStringOrBlank($row['status'] ?? null),
                    'designation' => self::trimStringOrBlank($row['designation'] ?? null),
                    'rate_mon' => $row['rate_mon'] !== null ? (float) $row['rate_mon'] : null,
                    'birth_date' => $row['birth_date'] ?? null,
                    'from_date' => $row['from_date'] ?? null,
                    'to_date' => $row['to_date'] ?? null,
                    'is_active' => $isActive,
                    'activity_status' => $isActive ? 'ACTIVE' : 'INACTIVE',
                    'updated_at' => $now,
                ];
            })
            ->values()
            ->all();

        foreach (array_chunk($records, self::SNAPSHOT_WRITE_CHUNK_SIZE) as $recordChunk) {
            DB::table(self::SNAPSHOT_TABLE)->upsert(
                $recordChunk,
                ['control_no'],
                [
                    'surname',
                    'firstname',
                    'middlename',
                    'office',
                    'status',
                    'designation',
                    'rate_mon',
                    'birth_date',
                    'from_date',
                    'to_date',
                    'is_active',
                    'activity_status',
                    'updated_at',
                ]
            );
        }

        self::flushCache();

        return count($records);
    }

    private static function syncSnapshotInChunks(): int
    {
        $syncedRows = 0;

        DB::connection(self::HR_CONNECTION)
            ->table(self::PERSONAL_TABLE.' as xp')
            ->select('xp.ControlNo as raw_control_no')
            ->chunkById(
                self::SNAPSHOT_SYNC_CHUNK_SIZE,
                static function (Collection $chunkRows) use (&$syncedRows): void {
                $controlNos = $chunkRows
                    ->map(static fn (object $row): string => trim((string) ($row->raw_control_no ?? '')))
                    ->filter(static fn (string $controlNo): bool => $controlNo !== '')
                    ->values()
                    ->all();

                if ($controlNos === []) {
                    return;
                }

                try {
                    $rows = self::fetchDirectoryRowsByControlNos($controlNos, null);
                    $syncedRows += count(self::rowsByNormalizedControlNo($rows));
                } catch (Throwable $exception) {
                    self::reportFailure('syncSnapshot.chunk', $exception, [
                        'chunk_size' => count($controlNos),
                        'first_control_no' => $controlNos[0] ?? null,
                    ]);
                }
                },
                'xp.ControlNo',
                'raw_control_no'
            );

        return $syncedRows;
    }

    private static function applyOfficeFilter(Builder $query, ?string $officeName): void
    {
        $officeName = trim((string) ($officeName ?? ''));
        if ($officeName === '') {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->where('vp.Office', $officeName);
    }

    private static function applyActiveWindowFilter(Builder $query, ?bool $activeOnly): void
    {
        if ($activeOnly === true) {
            $query->whereRaw(
                'vp.FromDate IS NOT NULL AND vp.ToDate IS NOT NULL AND GETDATE() BETWEEN vp.FromDate AND vp.ToDate'
            );
            return;
        }

        if ($activeOnly === false) {
            $query->where(function (Builder $nestedQuery): void {
                $nestedQuery->whereNull('vp.FromDate')
                    ->orWhereNull('vp.ToDate')
                    ->orWhereRaw('GETDATE() < vp.FromDate')
                    ->orWhereRaw('GETDATE() > vp.ToDate');
            });
        }
    }

    private static function applyControlNoFilter(Builder $query, string $controlNo): void
    {
        self::applyControlNoFilters($query, self::controlNoLookupValues([$controlNo]));
    }

    /**
     * @param array<int, string> $lookupValues
     */
    private static function applyControlNoFilters(Builder $query, array $lookupValues): void
    {
        if ($lookupValues === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        self::applyLiteralControlNoFilter($query, 'xp.ControlNo', $lookupValues);
    }

    /**
     * @param array<int, string> $lookupValues
     * @return array<int, array<string, mixed>>
     */
    private static function fetchDirectoryRowsByControlNos(array $lookupValues, ?bool $activeOnly = null): array
    {
        $lookupValues = self::controlNoLookupValues($lookupValues);
        if ($lookupValues === []) {
            return [];
        }

        $personalRows = DB::connection(self::HR_CONNECTION)
            ->table(self::PERSONAL_TABLE.' as xp')
            ->select('xp.ControlNo as raw_control_no', 'xp.Surname as surname', 'xp.Firstname as firstname', 'xp.MIddlename as middlename', 'xp.BirthDate as birth_date')
            ->tap(static function (Builder $query) use ($lookupValues): void {
                self::applyLiteralControlNoFilter($query, 'xp.ControlNo', $lookupValues);
            })
            ->get()
            ->map(static fn (object $employee): array => (array) $employee)
            ->values()
            ->all();

        $partitionQuery = self::partitionQuery(null)
            ->leftJoin(self::OFFICE_TABLE.' as yo', function ($join): void {
                $join->on(
                    DB::raw('LTRIM(RTRIM(vp.OffCode))'),
                    '=',
                    DB::raw('LTRIM(RTRIM(yo.Codes))')
                );
            })
            ->select(
                'vp.ControlNo as raw_control_no',
                'vp.Office as office',
                'vp.Status as status',
                'vp.Designation as designation',
                'vp.RateMon as rate_mon',
                'vp.FromDate as from_date',
                'vp.ToDate as to_date'
            )
            ->selectRaw('NULLIF(LTRIM(RTRIM(CONVERT(VARCHAR(255), yo.Abbr))), \'\') as officeAcronym');

        self::applyLiteralControlNoFilter($partitionQuery, 'vp.ControlNo', $lookupValues);
        $partitionLookupFailed = false;

        try {
            $partitionRows = $partitionQuery
                ->get()
                ->map(static fn (object $employee): array => (array) $employee)
                ->values()
                ->all();
        } catch (Throwable $exception) {
            $partitionLookupFailed = true;

            self::reportFailure('fetchDirectoryRowsByControlNos.partition', $exception, [
                'active_only' => $activeOnly,
                'lookup_count' => count($lookupValues),
                'fallback' => 'snapshot_or_personal_only',
            ]);

            $partitionRows = [];
        }

        $liveRows = self::mergeEmployeeRows($personalRows, $partitionRows, $activeOnly);
        self::syncSnapshotRows($liveRows);

        $snapshotRows = self::snapshotDirectoryRowsByControlNos($lookupValues, $activeOnly);

        return $partitionLookupFailed
            ? self::mergePreferredEmployeeRows($snapshotRows, $liveRows)
            : self::mergePreferredEmployeeRows($liveRows, $snapshotRows);
    }

    /**
     * Build an employee directory for one office and reuse it across office-level lookups.
     *
     * @return array<string, object>
     */
    private static function directoryByOffice(string $officeName, ?bool $activeOnly = null): array
    {
        $normalizedOfficeName = trim($officeName);
        if ($normalizedOfficeName === '') {
            return [];
        }

        $cacheKey = self::officeCacheKey('office_directory', $normalizedOfficeName, $activeOnly);
        $fallbackRows = self::filterRowsByResolvedOffice(
            self::applyDepartmentAssignments(
                self::snapshotDirectoryRowsByOffice($normalizedOfficeName, $activeOnly)
            ),
            $normalizedOfficeName
        );

        $rows = self::rememberResilient(
            $cacheKey,
            static function () use ($normalizedOfficeName, $activeOnly): array {
                return self::filterRowsByResolvedOffice(
                    self::applyDepartmentAssignments(
                        self::fetchDirectoryRowsByOffice($normalizedOfficeName, $activeOnly)
                    ),
                    $normalizedOfficeName
                );
            },
            $fallbackRows,
            'directoryByOffice',
            [
                'active_only' => $activeOnly,
                'office' => $normalizedOfficeName,
            ]
        );

        return self::keyEmployeeDirectory($rows, $activeOnly);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fetchDirectoryRowsByOffice(string $officeName, ?bool $activeOnly = null): array
    {
        $rows = [];
        $partitionLookupFailed = false;

        try {
            $rows = self::fetchLiveDirectoryRowsByOffice($officeName, $activeOnly);
            self::syncSnapshotRows($rows);
        } catch (Throwable $exception) {
            $partitionLookupFailed = true;

            self::reportFailure('fetchDirectoryRowsByOffice.partition', $exception, [
                'active_only' => $activeOnly,
                'office' => $officeName,
                'fallback' => 'snapshot_or_department_candidates',
            ]);
        }

        $snapshotRows = self::snapshotDirectoryRowsByOffice($officeName, $activeOnly);
        $rows = $partitionLookupFailed
            ? self::mergePreferredEmployeeRows($snapshotRows, $rows)
            : self::mergePreferredEmployeeRows($rows, $snapshotRows);

        $fallbackControlNoCandidates = self::assignedControlNoCandidatesByDepartment($officeName);

        if ($partitionLookupFailed || $rows === []) {
            $fallbackControlNoCandidates = array_values(array_unique([
                ...$fallbackControlNoCandidates,
                ...self::observedControlNoCandidatesByDepartment($officeName),
            ]));
        }

        if ($fallbackControlNoCandidates === []) {
            return $rows;
        }

        $existingNormalizedControlNos = array_fill_keys(
            array_keys(self::rowsByNormalizedControlNo($rows)),
            true
        );

        $missingFallbackControlNos = array_values(array_filter(
            $fallbackControlNoCandidates,
            static function (string $controlNo) use ($existingNormalizedControlNos): bool {
                $normalizedControlNo = self::normalizeControlNoInt($controlNo);

                return $normalizedControlNo !== null && !array_key_exists($normalizedControlNo, $existingNormalizedControlNos);
            }
        ));

        if ($missingFallbackControlNos === []) {
            return $rows;
        }

        return self::mergePreferredEmployeeRows(
            $rows,
            self::hydrateOfficeFallbackRows(
                self::fetchDirectoryRowsByControlNos($missingFallbackControlNos, $activeOnly),
                $officeName
            )
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fetchLiveDirectoryRowsByOffice(string $officeName, ?bool $activeOnly = null): array
    {
        $partitionRows = self::partitionQuery($activeOnly)
            ->leftJoin(self::OFFICE_TABLE.' as yo', function ($join): void {
                $join->on(
                    DB::raw('LTRIM(RTRIM(vp.OffCode))'),
                    '=',
                    DB::raw('LTRIM(RTRIM(yo.Codes))')
                );
            })
            ->select(
                'vp.ControlNo as raw_control_no',
                'vp.Office as office',
                'vp.Status as status',
                'vp.Designation as designation',
                'vp.RateMon as rate_mon',
                'vp.FromDate as from_date',
                'vp.ToDate as to_date'
            )
            ->selectRaw('NULLIF(LTRIM(RTRIM(CONVERT(VARCHAR(255), yo.Abbr))), \'\') as officeAcronym')
            ->tap(static function (Builder $query) use ($officeName): void {
                self::applyOfficeFilter($query, $officeName);
            })
            ->get()
            ->map(static fn (object $employee): array => (array) $employee)
            ->values()
            ->all();

        $lookupValues = self::rawControlNoValues($partitionRows);

        $personalRows = [];
        if ($lookupValues !== []) {
            $personalRows = DB::connection(self::HR_CONNECTION)
                ->table(self::PERSONAL_TABLE.' as xp')
                ->select(
                    'xp.ControlNo as raw_control_no',
                    'xp.Surname as surname',
                    'xp.Firstname as firstname',
                    'xp.MIddlename as middlename',
                    'xp.BirthDate as birth_date'
                )
                ->tap(static function (Builder $query) use ($lookupValues): void {
                    self::applyLiteralControlNoFilter($query, 'xp.ControlNo', $lookupValues);
                })
                ->get()
                ->map(static fn (object $employee): array => (array) $employee)
                ->values()
                ->all();
        }

        return self::mergeEmployeeRows($personalRows, $partitionRows, $activeOnly);
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
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function applyDepartmentAssignments(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        $departmentNamesByControlNo = self::loadAssignedDepartmentNames();
        if ($departmentNamesByControlNo === []) {
            return array_map(static function (array $row): array {
                $row['hris_office'] = trim((string) ($row['office'] ?? ''));
                $row['hrisOfficeAcronym'] = self::trimNullableString($row['officeAcronym'] ?? null);
                $row['assigned_department_name'] = null;
                $row['assignedDepartmentAcronym'] = null;
                $row['assigned_department_acronym'] = null;
                $row['assigned_department_id'] = null;
                $row['is_department_reassigned'] = false;

                return $row;
            }, $rows);
        }

        return array_map(static function (array $row) use ($departmentNamesByControlNo): array {
            $originalOffice = trim((string) ($row['office'] ?? ''));
            $originalOfficeAcronym = self::trimNullableString($row['officeAcronym'] ?? null);
            $normalizedControlNo = self::normalizeControlNoInt($row['control_no'] ?? null);
            $assignment = $normalizedControlNo !== null
                ? ($departmentNamesByControlNo[$normalizedControlNo] ?? null)
                : null;
            $assignedDepartmentName = trim((string) ($assignment['department_name'] ?? ''));
            $assignedDepartmentAcronym = self::resolveOfficeAcronymByName(
                $assignedDepartmentName !== '' ? $assignedDepartmentName : null
            );

            $row['hris_office'] = $originalOffice;
            $row['hrisOfficeAcronym'] = $originalOfficeAcronym;
            $row['assigned_department_name'] = $assignedDepartmentName !== '' ? $assignedDepartmentName : null;
            $row['assignedDepartmentAcronym'] = $assignedDepartmentAcronym;
            $row['assigned_department_acronym'] = $assignedDepartmentAcronym;
            $row['assigned_department_id'] = $assignment['department_id'] ?? null;
            $row['is_department_reassigned'] = $assignment !== null
                && strcasecmp($assignedDepartmentName, $originalOffice) !== 0;

            if ($assignment !== null && $assignedDepartmentName !== '') {
                $row['office'] = $assignedDepartmentName;
                $row['officeAcronym'] = $assignedDepartmentAcronym;
            }

            return $row;
        }, $rows);
    }

    /**
     * @return array<string, array{department_id:int|null, department_name:string}>
     */
    private static function loadAssignedDepartmentNames(): array
    {
        if (self::$assignedDepartmentNamesMemo !== null) {
            return self::$assignedDepartmentNamesMemo;
        }

        return self::$assignedDepartmentNamesMemo = EmployeeDepartmentAssignment::query()
            ->with(['department:id,name,is_inactive'])
            ->get()
            ->reduce(static function (array $carry, EmployeeDepartmentAssignment $assignment): array {
                $departmentName = trim((string) ($assignment->department?->name ?? ''));
                if ($departmentName === '' || ($assignment->department?->is_inactive ?? false)) {
                    return $carry;
                }

                $normalizedControlNo = self::normalizeControlNoInt($assignment->employee_control_no);
                if ($normalizedControlNo === null) {
                    return $carry;
                }

                $carry[$normalizedControlNo] = [
                    'department_id' => $assignment->department_id ? (int) $assignment->department_id : null,
                    'department_name' => $departmentName,
                ];

                return $carry;
            }, []);
    }

    private static function resolveOfficeAcronymByName(?string $officeName): ?string
    {
        $lookupKey = self::normalizeOfficeLookupKey($officeName);
        if ($lookupKey === '') {
            return null;
        }

        $acronymsByOfficeName = self::loadOfficeAcronymsByOfficeName();

        return $acronymsByOfficeName[$lookupKey] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private static function loadOfficeAcronymsByOfficeName(): array
    {
        if (self::$officeAcronymsByOfficeNameMemo !== null) {
            return self::$officeAcronymsByOfficeNameMemo;
        }

        try {
            $officeRows = DB::connection(self::HR_CONNECTION)
                ->table(self::OFFICE_TABLE.' as yo')
                ->selectRaw('NULLIF(LTRIM(RTRIM(CONVERT(VARCHAR(255), yo.Descriptions))), \'\') as office_name')
                ->selectRaw('NULLIF(LTRIM(RTRIM(CONVERT(VARCHAR(255), yo.Abbr))), \'\') as office_acronym')
                ->get();

            $acronymsByOfficeName = [];
            foreach ($officeRows as $officeRow) {
                $lookupKey = self::normalizeOfficeLookupKey($officeRow->office_name ?? null);
                $officeAcronym = self::trimNullableString($officeRow->office_acronym ?? null);

                if ($lookupKey === '' || $officeAcronym === null) {
                    continue;
                }

                $acronymsByOfficeName[$lookupKey] = $officeAcronym;
            }

            return self::$officeAcronymsByOfficeNameMemo = $acronymsByOfficeName;
        } catch (Throwable $exception) {
            self::reportFailure('loadOfficeAcronymsByOfficeName', $exception, [
                'fallback' => 'empty_map',
            ]);

            return self::$officeAcronymsByOfficeNameMemo = [];
        }
    }

    private static function snapshotTableExists(): bool
    {
        // Snapshot feature is intentionally disabled:
        // LMS no longer reads or writes tblEmployees as an HRIS fallback/cache.
        return false;
    }

    private static function snapshotQuery(?bool $activeOnly = null): Builder
    {
        $query = DB::table(self::SNAPSHOT_TABLE);

        if ($activeOnly === true) {
            $query->where('is_active', true);
            return $query;
        }

        if ($activeOnly === false) {
            $query->where(function (Builder $nestedQuery): void {
                $nestedQuery->where('is_active', false)
                    ->orWhereNull('is_active');
            });
        }

        return $query;
    }

    /**
     * @return array<int, string>
     */
    private static function snapshotControlNosMissingEmploymentFields(?string $officeName = null): array
    {
        if (!self::snapshotTableExists()) {
            return [];
        }

        $normalizedOfficeName = trim((string) ($officeName ?? ''));
        $query = self::snapshotQuery(null)->select('control_no');
        self::applyMissingEmploymentFieldFilter($query);

        if ($normalizedOfficeName !== '') {
            $departmentCandidates = array_values(array_unique([
                ...self::assignedControlNoCandidatesByDepartment($normalizedOfficeName),
                ...self::observedControlNoCandidatesByDepartment($normalizedOfficeName),
            ]));

            $query->where(function (Builder $nestedQuery) use ($normalizedOfficeName, $departmentCandidates): void {
                $nestedQuery->where('office', $normalizedOfficeName);

                if ($departmentCandidates === []) {
                    return;
                }

                $nestedQuery->orWhere(function (Builder $controlNoQuery) use ($departmentCandidates): void {
                    self::applyLiteralControlNoFilter(
                        $controlNoQuery,
                        'control_no',
                        self::controlNoLookupValues($departmentCandidates)
                    );
                });
            });
        }

        return $query
            ->orderBy('control_no')
            ->pluck('control_no')
            ->map(static fn (mixed $controlNo): string => trim((string) $controlNo))
            ->filter(static fn (string $controlNo): bool => $controlNo !== '')
            ->uniqueStrict()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function snapshotAllRows(?bool $activeOnly = null): array
    {
        if (!self::snapshotTableExists()) {
            return [];
        }

        return self::fetchSnapshotRows(
            self::snapshotQuery($activeOnly)
                ->orderBy('surname')
                ->orderBy('firstname')
                ->orderBy('control_no')
        );
    }

    private static function snapshotCount(?bool $activeOnly = null): int
    {
        if (!self::snapshotTableExists()) {
            return 0;
        }

        return (int) self::snapshotQuery($activeOnly)->count();
    }

    /**
     * @param array<int, string> $lookupValues
     * @return array<int, array<string, mixed>>
     */
    private static function snapshotDirectoryRowsByControlNos(array $lookupValues, ?bool $activeOnly = null): array
    {
        if (!self::snapshotTableExists() || $lookupValues === []) {
            return [];
        }

        $query = self::snapshotQuery($activeOnly);
        self::applyLiteralControlNoFilter($query, 'control_no', $lookupValues);

        return self::fetchSnapshotRows($query);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function snapshotDirectoryRowsByOffice(string $officeName, ?bool $activeOnly = null): array
    {
        if (!self::snapshotTableExists()) {
            return [];
        }

        $normalizedOfficeName = trim($officeName);
        if ($normalizedOfficeName === '') {
            return [];
        }

        return self::fetchSnapshotRows(
            self::snapshotQuery($activeOnly)
                ->where('office', $normalizedOfficeName)
                ->orderBy('surname')
                ->orderBy('firstname')
                ->orderBy('control_no')
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fetchSnapshotRows(Builder $query): array
    {
        return $query->get()
            ->map(static fn (object $employee): array => self::mapSnapshotRow((array) $employee))
            ->filter(static fn (array $row): bool => trim((string) ($row['control_no'] ?? '')) !== '')
            ->values()
            ->all();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapSnapshotRow(array $row): array
    {
        $isActive = filter_var($row['is_active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($isActive === null) {
            $isActive = self::isCurrentlyActive($row['from_date'] ?? null, $row['to_date'] ?? null);
        }

        $activityStatus = trim((string) ($row['activity_status'] ?? ''));
        if ($activityStatus === '') {
            $activityStatus = $isActive ? 'ACTIVE' : 'INACTIVE';
        }

        return [
            'control_no' => trim((string) ($row['control_no'] ?? $row['raw_control_no'] ?? '')),
            'surname' => self::trimStringOrBlank($row['surname'] ?? null),
            'firstname' => self::trimStringOrBlank($row['firstname'] ?? null),
            'middlename' => self::trimStringOrBlank($row['middlename'] ?? null),
            'birth_date' => $row['birth_date'] ?? null,
            'office' => self::trimStringOrBlank($row['office'] ?? null),
            'officeAcronym' => null,
            'status' => self::trimStringOrBlank($row['status'] ?? null),
            'designation' => self::trimStringOrBlank($row['designation'] ?? null),
            'rate_mon' => $row['rate_mon'] ?? null,
            'from_date' => $row['from_date'] ?? null,
            'to_date' => $row['to_date'] ?? null,
            'is_active' => $isActive,
            'activity_status' => self::trimStringOrBlank($activityStatus),
            'last_synced_at' => $row['last_synced_at'] ?? null,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function syncSnapshotRows(array $rows): int
    {
        if (!self::snapshotTableExists() || $rows === []) {
            return 0;
        }

        $incomingControlNos = collect($rows)
            ->map(static fn (array $row): string => trim((string) ($row['control_no'] ?? $row['raw_control_no'] ?? '')))
            ->filter(static fn (string $controlNo): bool => $controlNo !== '')
            ->values()
            ->all();

        if ($incomingControlNos === []) {
            return 0;
        }

        $mergedRows = self::mergePreferredEmployeeRows(
            $rows,
            self::snapshotDirectoryRowsByControlNos($incomingControlNos, null)
        );
        $records = self::buildSnapshotRecords($mergedRows);

        if ($records === []) {
            return 0;
        }

        foreach (array_chunk($records, self::SNAPSHOT_WRITE_CHUNK_SIZE) as $recordChunk) {
            DB::table(self::SNAPSHOT_TABLE)->upsert(
                $recordChunk,
                ['control_no'],
                [
                    'surname',
                    'firstname',
                    'middlename',
                    'office',
                    'status',
                    'designation',
                    'rate_mon',
                    'birth_date',
                    'from_date',
                    'to_date',
                    'is_active',
                    'activity_status',
                    'last_synced_at',
                    'updated_at',
                ]
            );
        }

        return count($records);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function replaceSnapshotRows(array $rows): int
    {
        if (!self::snapshotTableExists()) {
            return 0;
        }

        $records = self::buildSnapshotRecords($rows);

        DB::transaction(function () use ($records): void {
            DB::table(self::SNAPSHOT_TABLE)->delete();

            foreach (array_chunk($records, self::SNAPSHOT_WRITE_CHUNK_SIZE) as $recordChunk) {
                DB::table(self::SNAPSHOT_TABLE)->insert($recordChunk);
            }
        });

        return count($records);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function buildSnapshotRecords(array $rows): array
    {
        $now = now();

        return collect(self::rowsByNormalizedControlNo($rows))
            ->map(static function (array $row) use ($now): array {
                $controlNo = trim((string) ($row['control_no'] ?? ''));
                $isActive = filter_var($row['is_active'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if ($isActive === null) {
                    $isActive = self::isCurrentlyActive($row['from_date'] ?? null, $row['to_date'] ?? null);
                }

                $activityStatus = trim((string) ($row['activity_status'] ?? ''));
                if ($activityStatus === '') {
                    $activityStatus = $isActive ? 'ACTIVE' : 'INACTIVE';
                }

                return [
                    'control_no' => $controlNo,
                    'surname' => self::trimStringOrBlank($row['surname'] ?? null),
                    'firstname' => self::trimStringOrBlank($row['firstname'] ?? null),
                    'middlename' => self::trimStringOrBlank($row['middlename'] ?? null),
                    'office' => self::trimStringOrBlank($row['office'] ?? null),
                    'status' => self::trimStringOrBlank($row['status'] ?? null),
                    'designation' => self::trimStringOrBlank($row['designation'] ?? null),
                    'rate_mon' => $row['rate_mon'] !== null ? (float) $row['rate_mon'] : null,
                    'birth_date' => $row['birth_date'] ?? null,
                    'from_date' => $row['from_date'] ?? null,
                    'to_date' => $row['to_date'] ?? null,
                    'is_active' => $isActive,
                    'activity_status' => self::trimStringOrBlank($activityStatus),
                    'last_synced_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $primaryRows
     * @param array<int, array<string, mixed>> $fallbackRows
     * @return array<int, array<string, mixed>>
     */
    private static function mergePreferredEmployeeRows(array $primaryRows, array $fallbackRows): array
    {
        $resolvedRows = self::rowsByNormalizedControlNo($primaryRows);

        foreach (self::rowsByNormalizedControlNo($fallbackRows) as $normalizedControlNo => $fallbackRow) {
            if (!array_key_exists($normalizedControlNo, $resolvedRows)) {
                $resolvedRows[$normalizedControlNo] = $fallbackRow;
                continue;
            }

            $resolvedRows[$normalizedControlNo] = self::mergeEmployeeRowFields(
                $resolvedRows[$normalizedControlNo],
                $fallbackRow
            );
        }

        return array_values($resolvedRows);
    }

    /**
     * @param array<string, mixed> $primaryRow
     * @param array<string, mixed> $fallbackRow
     * @return array<string, mixed>
     */
    private static function mergeEmployeeRowFields(array $primaryRow, array $fallbackRow): array
    {
        $resolvedRow = $primaryRow;

        foreach ([
            'control_no',
            'surname',
            'firstname',
            'middlename',
            'birth_date',
            'office',
            'officeAcronym',
            'status',
            'designation',
            'rate_mon',
            'from_date',
            'to_date',
            'activity_status',
            'last_synced_at',
        ] as $field) {
            if (
                self::rowValueIsMissing($resolvedRow[$field] ?? null)
                && !self::rowValueIsMissing($fallbackRow[$field] ?? null)
            ) {
                $resolvedRow[$field] = $fallbackRow[$field];
            }
        }

        $primaryHasActiveWindow = !self::rowValueIsMissing($resolvedRow['from_date'] ?? null)
            || !self::rowValueIsMissing($resolvedRow['to_date'] ?? null);

        if (
            !$primaryHasActiveWindow
            && array_key_exists('is_active', $fallbackRow)
        ) {
            $resolvedRow['is_active'] = $fallbackRow['is_active'];
        } elseif (!array_key_exists('is_active', $resolvedRow) && array_key_exists('is_active', $fallbackRow)) {
            $resolvedRow['is_active'] = $fallbackRow['is_active'];
        }

        if (!array_key_exists('is_active', $resolvedRow)) {
            $resolvedRow['is_active'] = self::isCurrentlyActive(
                $resolvedRow['from_date'] ?? null,
                $resolvedRow['to_date'] ?? null
            );
        }

        if (self::rowValueIsMissing($resolvedRow['activity_status'] ?? null)) {
            $isActive = filter_var($resolvedRow['is_active'] ?? null, FILTER_VALIDATE_BOOLEAN);
            $resolvedRow['activity_status'] = $isActive ? 'ACTIVE' : 'INACTIVE';
        }

        return $resolvedRow;
    }

    private static function rowValueIsMissing(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return is_string($value) && trim($value) === '';
    }

    private static function rowHasMissingEmploymentFields(array $row): bool
    {
        return self::rowValueIsMissing($row['office'] ?? null)
            || self::rowValueIsMissing($row['status'] ?? null);
    }

    /**
     * @param array<int, array<string, mixed>> $personalRows
     * @param array<int, array<string, mixed>> $partitionRows
     * @return array<int, array<string, mixed>>
     */
    private static function mergeEmployeeRows(array $personalRows, array $partitionRows, ?bool $activeOnly = null): array
    {
        $personalByControlNo = self::rowsByNormalizedControlNo($personalRows);
        $partitionByControlNo = self::rowsByNormalizedControlNo($partitionRows);
        $normalizedControlNos = array_values(array_unique([
            ...array_keys($personalByControlNo),
            ...array_keys($partitionByControlNo),
        ]));

        $rows = [];

        foreach ($normalizedControlNos as $normalizedControlNo) {
            $personalRow = $personalByControlNo[$normalizedControlNo] ?? [];
            $partitionRow = $partitionByControlNo[$normalizedControlNo] ?? [];
            $resolvedControlNo = trim((string) ($personalRow['control_no'] ?? $partitionRow['control_no'] ?? ''));

            if ($resolvedControlNo === '') {
                continue;
            }

            $isActive = self::isCurrentlyActive($partitionRow['from_date'] ?? null, $partitionRow['to_date'] ?? null);

            $rows[] = [
                'control_no' => $resolvedControlNo,
                'surname' => $personalRow['surname'] ?? null,
                'firstname' => $personalRow['firstname'] ?? null,
                'middlename' => $personalRow['middlename'] ?? null,
                'birth_date' => $personalRow['birth_date'] ?? null,
                'office' => $partitionRow['office'] ?? null,
                'officeAcronym' => $partitionRow['officeAcronym'] ?? null,
                'status' => $partitionRow['status'] ?? null,
                'designation' => $partitionRow['designation'] ?? null,
                'rate_mon' => $partitionRow['rate_mon'] ?? null,
                'from_date' => $partitionRow['from_date'] ?? null,
                'to_date' => $partitionRow['to_date'] ?? null,
                'is_active' => $isActive,
                'activity_status' => $isActive ? 'ACTIVE' : 'INACTIVE',
            ];
        }

        return self::filterRowsByActivity($rows, $activeOnly);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function filterRowsByActivity(array $rows, ?bool $activeOnly): array
    {
        if ($activeOnly === true) {
            return array_values(array_filter(
                $rows,
                static fn (array $row): bool => filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ));
        }

        if ($activeOnly === false) {
            return array_values(array_filter(
                $rows,
                static fn (array $row): bool => !filter_var($row['is_active'] ?? false, FILTER_VALIDATE_BOOLEAN)
            ));
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function filterRowsByResolvedOffice(array $rows, string $officeName): array
    {
        $normalizedOfficeName = trim($officeName);
        if ($normalizedOfficeName === '') {
            return [];
        }

        return array_values(array_filter(
            $rows,
            static function (array $row) use ($normalizedOfficeName): bool {
                return strcasecmp(
                    trim((string) ($row['office'] ?? '')),
                    $normalizedOfficeName
                ) === 0;
            }
        ));
    }

    private static function singleLookupMemoKey(string $controlNo, ?bool $activeOnly): string
    {
        $activityKey = match ($activeOnly) {
            true => 'active',
            false => 'inactive',
            default => 'all',
        };

        $normalizedControlNo = self::normalizeControlNoInt($controlNo);
        $lookupKey = $normalizedControlNo ?? trim($controlNo);

        return "{$lookupKey}|{$activityKey}";
    }

    /**
     * @param array<int, mixed> $controlNos
     * @return array<int, string>
     */
    private static function controlNoLookupValues(array $controlNos): array
    {
        return collect($controlNos)
            ->flatMap(static function (mixed $controlNo): array {
                $rawControlNo = trim((string) ($controlNo ?? ''));
                if ($rawControlNo === '') {
                    return [];
                }

                $normalizedControlNo = self::normalizeControlNoInt($rawControlNo);
                if ($normalizedControlNo === null) {
                    return [$rawControlNo];
                }

                $paddedControlNo = str_pad(
                    $normalizedControlNo,
                    max(6, strlen($rawControlNo)),
                    '0',
                    STR_PAD_LEFT
                );

                return array_values(array_unique([
                    $rawControlNo,
                    $normalizedControlNo,
                    $paddedControlNo,
                ]));
            })
            ->uniqueStrict()
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, string>
     */
    private static function rawControlNoValues(array $rows): array
    {
        return collect($rows)
            ->map(static fn (array $row): string => trim((string) ($row['raw_control_no'] ?? $row['control_no'] ?? '')))
            ->filter(static fn (string $controlNo): bool => $controlNo !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, array<string, mixed>>
     */
    private static function rowsByNormalizedControlNo(array $rows): array
    {
        $resolvedRows = [];

        foreach ($rows as $row) {
            $normalizedControlNo = self::normalizeControlNoInt($row['raw_control_no'] ?? $row['control_no'] ?? null);
            if ($normalizedControlNo === null) {
                continue;
            }

            if (!array_key_exists('control_no', $row)) {
                $row['control_no'] = trim((string) ($row['raw_control_no'] ?? ''));
            }

            $resolvedRows[$normalizedControlNo] = $row;
        }

        return $resolvedRows;
    }

    private static function isCurrentlyActive(mixed $fromDate, mixed $toDate): bool
    {
        try {
            if ($fromDate === null || $toDate === null) {
                return false;
            }

            $now = now();
            $resolvedFromDate = $fromDate instanceof \DateTimeInterface ? $fromDate : new \DateTimeImmutable((string) $fromDate);
            $resolvedToDate = $toDate instanceof \DateTimeInterface ? $toDate : new \DateTimeImmutable((string) $toDate);

            return $resolvedFromDate <= $now && $resolvedToDate >= $now;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<int, string> $lookupValues
     */
    private static function applyLiteralControlNoFilter(Builder $query, string $qualifiedColumn, array $lookupValues): void
    {
        $literalValues = collect($lookupValues)
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '' && preg_match('/^\d+$/', $value) === 1)
            ->uniqueStrict()
            ->values()
            ->all();

        if ($literalValues === []) {
            $query->whereRaw('1 = 0');
            return;
        }

        $quotedValues = implode(', ', array_map(
            static fn (string $value): string => "'{$value}'",
            $literalValues
        ));

        $query->whereRaw("{$qualifiedColumn} IN ({$quotedValues})");
    }

    private static function applyMissingEmploymentFieldFilter(Builder $query): void
    {
        $query->where(function (Builder $nestedQuery): void {
            $nestedQuery->whereNull('office')
                ->orWhere('office', '')
                ->orWhereNull('status')
                ->orWhere('status', '');
        });
    }

    private static function reportFailure(string $operation, Throwable $exception, array $context = []): void
    {
        $message = trim($exception->getMessage());
        $logFingerprint = md5($operation.'|'.$message);
        $logKey = "hris_employee.failure.{$logFingerprint}";

        if (!Cache::add($logKey, true, now()->addMinute())) {
            return;
        }

        Log::warning('HRIS query failed; returning fallback data.', array_merge($context, [
            'operation' => $operation,
            'exception_class' => $exception::class,
            'message' => $message,
        ]));
    }

    private static function rememberResilient(
        string $cacheKey,
        callable $resolver,
        mixed $fallbackValue,
        string $operation,
        array $context = []
    ): mixed {
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey, $fallbackValue);
        }

        $staleKey = "{$cacheKey}.stale";
        $cooldownKey = "{$cacheKey}.cooldown";
        $lockKey = "{$cacheKey}.lock";

        if (Cache::has($cooldownKey)) {
            return self::resolveStaleOrFallback($cacheKey, $staleKey, $fallbackValue);
        }

        try {
            return Cache::lock($lockKey, self::CACHE_LOCK_SECONDS)->block(
                self::CACHE_LOCK_WAIT_SECONDS,
                function () use ($cacheKey, $staleKey, $cooldownKey, $resolver, $fallbackValue, $operation, $context): mixed {
                    if (Cache::has($cacheKey)) {
                        return Cache::get($cacheKey, $fallbackValue);
                    }

                    if (Cache::has($cooldownKey)) {
                        return self::resolveStaleOrFallback($cacheKey, $staleKey, $fallbackValue);
                    }

                    try {
                        $value = $resolver();
                        Cache::put($cacheKey, $value, now()->addMinutes(self::CACHE_TTL_MINUTES));
                        Cache::put($staleKey, $value, now()->addMinutes(self::STALE_CACHE_TTL_MINUTES));
                        Cache::forget($cooldownKey);

                        return $value;
                    } catch (Throwable $exception) {
                        self::reportFailure($operation, $exception, $context);
                        Cache::put($cooldownKey, true, now()->addSeconds(self::CACHE_FAILURE_COOLDOWN_SECONDS));

                        return self::resolveStaleOrFallback($cacheKey, $staleKey, $fallbackValue);
                    }
                }
            );
        } catch (LockTimeoutException $exception) {
            self::reportFailure($operation, $exception, array_merge($context, [
                'cache_key' => $cacheKey,
                'lock_timeout' => true,
            ]));

            return self::resolveStaleOrFallback($cacheKey, $staleKey, $fallbackValue);
        }
    }

    private static function resolveStaleOrFallback(string $cacheKey, string $staleKey, mixed $fallbackValue): mixed
    {
        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey, $fallbackValue);
        }

        if (Cache::has($staleKey)) {
            $staleValue = Cache::get($staleKey, $fallbackValue);
            Cache::put($cacheKey, $staleValue, now()->addMinute());

            return $staleValue;
        }

        Cache::put($cacheKey, $fallbackValue, now()->addMinute());

        return $fallbackValue;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, object>
     */
    private static function keyEmployeeDirectory(array $rows, ?bool $activeOnly): array
    {
        $directory = [];

        foreach ($rows as $row) {
            $rawControlNo = trim((string) ($row['control_no'] ?? ''));
            if ($rawControlNo === '') {
                continue;
            }

            $employee = (object) $row;
            $normalizedControlNo = self::normalizeControlNoInt($rawControlNo);
            $directoryKey = $normalizedControlNo ?? $rawControlNo;
            $lookupKeys = array_values(array_unique(array_filter([
                $rawControlNo,
                $normalizedControlNo,
            ])));

            $directory[$directoryKey] = $employee;

            foreach ($lookupKeys as $lookupKey) {
                self::$singleLookupMemo[self::singleLookupMemoKey($lookupKey, $activeOnly)] = $employee;
            }
        }

        return $directory;
    }

    private static function trimNullableString(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed !== '' ? $trimmed : null;
    }

    private static function trimStringOrBlank(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private static function normalizeOfficeLookupKey(mixed $officeName): string
    {
        $normalized = preg_replace('/\s+/', ' ', trim((string) ($officeName ?? '')));
        if (!is_string($normalized) || $normalized === '') {
            return '';
        }

        return mb_strtoupper($normalized, 'UTF-8');
    }

    /**
     * @return array<int, string>
     */
    private static function assignedControlNoCandidatesByDepartment(string $departmentName): array
    {
        $normalizedDepartmentName = trim($departmentName);
        if ($normalizedDepartmentName === '') {
            return [];
        }

        return collect(self::loadAssignedDepartmentNames())
            ->filter(static function (array $assignment) use ($normalizedDepartmentName): bool {
                return strcasecmp(
                    trim((string) ($assignment['department_name'] ?? '')),
                    $normalizedDepartmentName
                ) === 0;
            })
            ->keys()
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function observedControlNoCandidatesByDepartment(string $departmentName): array
    {
        $normalizedDepartmentName = trim($departmentName);
        if ($normalizedDepartmentName === '') {
            return [];
        }

        $departmentId = Department::query()
            ->where('name', $normalizedDepartmentName)
            ->value('id');

        if (!$departmentId) {
            return [];
        }

        $departmentAdmins = DepartmentAdmin::query()
            ->where('department_id', $departmentId)
            ->get(['id', 'employee_control_no']);

        $adminIds = $departmentAdmins
            ->pluck('id')
            ->map(static fn (mixed $adminId): int => (int) $adminId)
            ->filter(static fn (int $adminId): bool => $adminId > 0)
            ->values()
            ->all();

        $adminControlNos = $departmentAdmins
            ->pluck('employee_control_no')
            ->map(static fn (mixed $controlNo): string => trim((string) $controlNo))
            ->filter(static fn (string $controlNo): bool => $controlNo !== '')
            ->values()
            ->all();

        $leaveApplicationControlNos = $adminIds === []
            ? []
            : LeaveApplication::query()
                ->where(function ($query) use ($adminIds): void {
                    $query->whereIn('admin_id', $adminIds)
                        ->orWhereIn('applicant_admin_id', $adminIds);
                })
                ->pluck('employee_control_no')
                ->map(static fn (mixed $controlNo): string => trim((string) $controlNo))
                ->filter(static fn (string $controlNo): bool => $controlNo !== '')
                ->values()
                ->all();

        $cocApplicationControlNos = $adminIds === []
            ? []
            : COCApplication::query()
                ->whereIn('reviewed_by_admin_id', $adminIds)
                ->pluck('employee_control_no')
                ->map(static fn (mixed $controlNo): string => trim((string) $controlNo))
                ->filter(static fn (string $controlNo): bool => $controlNo !== '')
                ->values()
                ->all();

        return collect([
            ...$adminControlNos,
            ...$leaveApplicationControlNos,
            ...$cocApplicationControlNos,
        ])
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private static function hydrateOfficeFallbackRows(array $rows, string $officeName): array
    {
        $normalizedOfficeName = trim($officeName);
        if ($normalizedOfficeName === '') {
            return $rows;
        }

        return array_map(static function (array $row) use ($normalizedOfficeName): array {
            if (trim((string) ($row['office'] ?? '')) === '') {
                $row['office'] = $normalizedOfficeName;
            }

            return $row;
        }, $rows);
    }

    private static function officeCacheKey(string $suffix, string $officeName, ?bool $activeOnly): string
    {
        $normalizedOfficeName = strtoupper(trim($officeName));

        return self::cacheKey($suffix.'.'.md5($normalizedOfficeName), $activeOnly);
    }

    /**
     * @param array<int, string> $lookupValues
     */
    private static function lookupCacheKey(string $suffix, array $lookupValues, ?bool $activeOnly): string
    {
        $normalizedLookupValues = collect($lookupValues)
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $value): bool => $value !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();

        return self::cacheKey($suffix.'.'.md5(implode('|', $normalizedLookupValues)), $activeOnly);
    }

    private static function cacheVersion(): string
    {
        if (self::$cacheVersionMemo !== null) {
            return self::$cacheVersionMemo;
        }

        $cachedVersion = Cache::get(self::CACHE_VERSION_KEY);
        if (!is_string($cachedVersion) || trim($cachedVersion) === '') {
            $cachedVersion = 'v4';
            Cache::forever(self::CACHE_VERSION_KEY, $cachedVersion);
        }

        return self::$cacheVersionMemo = $cachedVersion;
    }
}
