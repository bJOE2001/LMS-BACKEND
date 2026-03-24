<?php

namespace App\Models;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
    private const PERSONAL_TABLE = 'xPersonal';
    private const PARTITION_VIEW = 'vwpartitionforseparated';
    private const CACHE_TTL_MINUTES = 5;

    /**
     * Build the canonical HRIS employee query with normalized aliases.
     */
    public static function query(?bool $activeOnly = null): Builder
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

        self::applyActiveWindowFilter($query, $activeOnly);

        return $query;
    }

    public static function allCached(?bool $activeOnly = null): Collection
    {
        $rows = Cache::remember(
            self::cacheKey('all', $activeOnly),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            static fn (): array => self::orderedQuery($activeOnly)
                ->get()
                ->map(static fn (object $employee): array => (array) $employee)
                ->values()
                ->all()
        );

        return collect($rows)->map(static fn (array $row): object => (object) $row);
    }

    public static function countCached(?bool $activeOnly = null): int
    {
        return self::allCached($activeOnly)->count();
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

        return self::allCached($activeOnly)
            ->filter(static function (object $employee) use ($normalizedOfficeName): bool {
                return strcasecmp(
                    trim((string) ($employee->office ?? '')),
                    $normalizedOfficeName
                ) === 0;
            })
            ->values();
    }

    /**
     * Get canonical control numbers for a given office/department.
     *
     * @return array<int, string>
     */
    public static function controlNosByOffice(?string $officeName, ?bool $activeOnly = null): array
    {
        return self::allByOffice($officeName, $activeOnly)
            ->pluck('control_no')
            ->map(static fn (mixed $value): string => trim((string) $value))
            ->filter(static fn (string $controlNo): bool => $controlNo !== '')
            ->unique()
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

        $normalizedControlNo = self::normalizeControlNoInt($controlNo);

        return self::allCached($activeOnly)->first(
            static function (object $employee) use ($controlNo, $normalizedControlNo): bool {
                $employeeControlNo = trim((string) ($employee->control_no ?? ''));
                if ($employeeControlNo === '') {
                    return false;
                }

                if ($employeeControlNo === $controlNo) {
                    return true;
                }

                return $normalizedControlNo !== null
                    && self::normalizeControlNoInt($employeeControlNo) === $normalizedControlNo;
            }
        );
    }

    /**
     * Check if an employee exists in HRIS by control number.
     */
    public static function existsByControlNo(string $controlNo, ?bool $activeOnly = null): bool
    {
        return self::findByControlNo($controlNo, $activeOnly) !== null;
    }

    private static function orderedQuery(?bool $activeOnly = null): Builder
    {
        return self::query($activeOnly)
            ->orderByRaw('LTRIM(RTRIM(xp.Surname))')
            ->orderByRaw('LTRIM(RTRIM(xp.Firstname))')
            ->orderByRaw('LTRIM(RTRIM(CONVERT(VARCHAR(64), xp.ControlNo)))');
    }

    private static function cacheKey(string $suffix, ?bool $activeOnly): string
    {
        $activityKey = match ($activeOnly) {
            true => 'active',
            false => 'inactive',
            default => 'all',
        };

        return "hris_employees.{$suffix}.{$activityKey}.v1";
    }

    private static function applyOfficeFilter(Builder $query, ?string $officeName): void
    {
        $officeName = trim((string) ($officeName ?? ''));
        if ($officeName === '') {
            $query->whereRaw('1 = 0');
            return;
        }

        $query->whereRaw('UPPER(LTRIM(RTRIM(vp.Office))) = UPPER(?)', [$officeName]);
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
        $rawControlNo = trim($controlNo);
        $normalizedControlNo = self::normalizeControlNoInt($rawControlNo);

        $query->where(function (Builder $nestedQuery) use ($rawControlNo, $normalizedControlNo): void {
            $nestedQuery->whereRaw(
                'LTRIM(RTRIM(CONVERT(VARCHAR(64), xp.ControlNo))) = ?',
                [$rawControlNo]
            );

            if ($normalizedControlNo === null) {
                return;
            }

            if ($normalizedControlNo !== $rawControlNo) {
                $nestedQuery->orWhereRaw(
                    'LTRIM(RTRIM(CONVERT(VARCHAR(64), xp.ControlNo))) = ?',
                    [$normalizedControlNo]
                );
            }
        });
    }

    private static function normalizeControlNoInt(mixed $controlNo): ?string
    {
        $normalized = ltrim(trim((string) ($controlNo ?? '')), '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        return preg_match('/^\d+$/', $normalized) ? $normalized : null;
    }
}
