<?php

namespace App\Http\Controllers;

use App\Models\HRAccount;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HRAnalyticsController extends Controller
{
    /**
     * @var array<int, string>
     */
    private const EMPLOYMENT_STATUS_LABELS = [
        'Regular',
        'Casual',
        'Co-terminous',
        'Contractual / Temporary',
        'Elective',
        'Others',
    ];

    /**
     * @var array<int, string>
     */
    private const GENERATION_LABELS = [
        'Gen Z',
        'Millennial',
        'Gen X',
        'Baby Boomer',
        'Silent Generation',
        'Unknown',
    ];

    /**
     * @var array<int, string>
     */
    private const AGE_GROUP_LABELS = [
        '18-24',
        '25-34',
        '35-44',
        '45-54',
        '55-64',
        '65+',
        'Unknown',
    ];

    /**
     * @var array<int, string>
     */
    private const GENDER_LABELS = [
        'Male',
        'Female',
        'Unknown',
    ];

    /**
     * HR analytics dataset consumed by /hr/analytics frontend charts.
     */
    public function index(Request $request): JsonResponse
    {
        $hr = $request->user();
        if (! $hr instanceof HRAccount) {
            return response()->json(['message' => 'Only HR accounts can access this endpoint.'], 403);
        }

        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);

        [$rangeStart, $rangeEnd] = $this->resolveAnalyticsDateRange(
            $validated['date_from'] ?? null,
            $validated['date_to'] ?? null
        );

        $activeEmployees = HrisEmployee::allCached(true);
        $activeSexByControlNo = $this->fetchSexByControlNos(
            $activeEmployees
                ->pluck('control_no')
                ->all()
        );
        $activeProfiles = $this->buildDemographicProfiles(
            $activeEmployees,
            $activeSexByControlNo
        );

        $applications = LeaveApplication::query()
            ->with(['leaveType:id,name'])
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->whereBetween('created_at', [$rangeStart->startOfDay(), $rangeEnd->endOfDay()])
            ->orderBy('created_at')
            ->get();

        $applicationDirectory = HrisEmployee::directoryByControlNos(
            $applications
                ->pluck('employee_control_no')
                ->all()
        );
        $applicationSexByControlNo = $this->fetchSexByControlNos(
            array_keys($applicationDirectory)
        );
        $applicationProfiles = $this->buildDemographicProfiles(
            collect(array_values($applicationDirectory)),
            $applicationSexByControlNo
        );

        $monthLabels = $this->buildAnalyticsMonthLabels($rangeStart, $rangeEnd);
        $monthKeys = $this->buildAnalyticsMonthKeys($rangeStart, $rangeEnd);
        $monthKeyIndexMap = array_flip($monthKeys);
        $monthBucketCount = count($monthLabels);

        $workforceEmploymentCounts = $this->initializeCountMap(self::EMPLOYMENT_STATUS_LABELS);
        $workforceGenerationCounts = $this->initializeCountMap(self::GENERATION_LABELS);
        $workforceAgeGroupCounts = $this->initializeCountMap(self::AGE_GROUP_LABELS);
        $workforceGenderCounts = $this->initializeCountMap(self::GENDER_LABELS);

        foreach ($activeProfiles as $profile) {
            $workforceEmploymentCounts[$profile['employment_status']]++;
            $workforceGenerationCounts[$profile['generation']]++;
            $workforceAgeGroupCounts[$profile['age_group']]++;
            $workforceGenderCounts[$profile['gender']]++;
        }

        $leaveUsageByEmploymentStatus = $this->initializeFloatMap(self::EMPLOYMENT_STATUS_LABELS);
        $leaveUsageByGeneration = $this->initializeFloatMap(self::GENERATION_LABELS);
        $leaveUsageByAgeGroup = $this->initializeFloatMap(self::AGE_GROUP_LABELS);

        $employmentTrendSeries = $this->initializeTrendSeries(self::EMPLOYMENT_STATUS_LABELS, $monthBucketCount);
        $generationTrendSeries = $this->initializeTrendSeries(self::GENERATION_LABELS, $monthBucketCount);
        $genderTrendSeries = $this->initializeTrendSeries(self::GENDER_LABELS, $monthBucketCount);

        $leaveTypeByGenderCounts = [];
        $leaveDaysByAgeGroupTotals = $this->initializeFloatMap(self::AGE_GROUP_LABELS);
        $leaveDaysByAgeGroupCounts = $this->initializeCountMap(self::AGE_GROUP_LABELS);

        foreach ($applications as $application) {
            if (! $application instanceof LeaveApplication) {
                continue;
            }

            $normalizedControlNo = $this->normalizeControlNo($application->employee_control_no ?? null);
            $profile = $normalizedControlNo !== '' && array_key_exists($normalizedControlNo, $applicationProfiles)
                ? $applicationProfiles[$normalizedControlNo]
                : $this->unknownDemographicProfile();

            $usageDate = $this->resolveAnalyticsUsageDate($application);
            if (! $usageDate) {
                continue;
            }

            $monthKey = $usageDate->format('Y-m');
            if (! array_key_exists($monthKey, $monthKeyIndexMap)) {
                continue;
            }
            $monthIndex = (int) $monthKeyIndexMap[$monthKey];

            $employmentLabel = $profile['employment_status'];
            $generationLabel = $profile['generation'];
            $ageGroupLabel = $profile['age_group'];
            $genderLabel = $profile['gender'];

            $leaveDurationDays = (float) ($application->total_days ?? 0);

            $leaveUsageByEmploymentStatus[$employmentLabel] += $leaveDurationDays;
            $leaveUsageByGeneration[$generationLabel] += $leaveDurationDays;
            $leaveUsageByAgeGroup[$ageGroupLabel] += $leaveDurationDays;

            $employmentTrendSeries[$employmentLabel][$monthIndex]++;
            $generationTrendSeries[$generationLabel][$monthIndex]++;
            $genderTrendSeries[$genderLabel][$monthIndex]++;

            $leaveTypeName = trim((string) ($application->leaveType?->name ?? ''));
            if ($leaveTypeName === '') {
                $leaveTypeName = 'Unknown';
            }
            if (! array_key_exists($leaveTypeName, $leaveTypeByGenderCounts)) {
                $leaveTypeByGenderCounts[$leaveTypeName] = [
                    'Male' => 0,
                    'Female' => 0,
                    'Unknown' => 0,
                ];
            }
            $leaveTypeByGenderCounts[$leaveTypeName][$genderLabel]++;

            if ($leaveDurationDays > 0) {
                $leaveDaysByAgeGroupTotals[$ageGroupLabel] += $leaveDurationDays;
                $leaveDaysByAgeGroupCounts[$ageGroupLabel]++;
            }
        }

        $averageLeaveDaysByAgeGroup = $this->initializeFloatMap(self::AGE_GROUP_LABELS);
        foreach (self::AGE_GROUP_LABELS as $ageGroupLabel) {
            $count = $leaveDaysByAgeGroupCounts[$ageGroupLabel];
            if ($count <= 0) {
                continue;
            }

            $averageLeaveDaysByAgeGroup[$ageGroupLabel] = round(
                $leaveDaysByAgeGroupTotals[$ageGroupLabel] / $count,
                2
            );
        }

        ksort($leaveTypeByGenderCounts, SORT_NATURAL | SORT_FLAG_CASE);
        $leaveTypeLabels = array_values(array_keys($leaveTypeByGenderCounts));
        $maleLeaveTypeSeries = [];
        $femaleLeaveTypeSeries = [];
        foreach ($leaveTypeLabels as $leaveTypeLabel) {
            $maleLeaveTypeSeries[] = (int) ($leaveTypeByGenderCounts[$leaveTypeLabel]['Male'] ?? 0);
            $femaleLeaveTypeSeries[] = (int) ($leaveTypeByGenderCounts[$leaveTypeLabel]['Female'] ?? 0);
        }

        $charts = [
            'employmentStatusDistribution' => $this->buildCountChart($workforceEmploymentCounts),
            'leaveUsageByEmploymentStatus' => $this->buildCountChart($leaveUsageByEmploymentStatus),
            'employmentStatusTrend' => $this->buildTrendChart($monthLabels, $employmentTrendSeries),
            'generationDistribution' => $this->buildCountChart($workforceGenerationCounts),
            'leaveUsageByGeneration' => $this->buildCountChart($leaveUsageByGeneration),
            'generationLeaveTrend' => $this->buildTrendChart($monthLabels, $generationTrendSeries),
            'ageGroupDistribution' => $this->buildCountChart($workforceAgeGroupCounts),
            'leaveUsageByAgeGroup' => $this->buildCountChart($leaveUsageByAgeGroup),
            'averageLeaveDaysByAgeGroup' => $this->buildCountChart($averageLeaveDaysByAgeGroup),
            'genderDistribution' => $this->buildCountChart($workforceGenderCounts),
            'leaveTypeByGender' => [
                'labels' => $leaveTypeLabels,
                'series' => [
                    [
                        'name' => 'Male',
                        'data' => $maleLeaveTypeSeries,
                    ],
                    [
                        'name' => 'Female',
                        'data' => $femaleLeaveTypeSeries,
                    ],
                ],
            ],
            'genderLeaveTrend' => $this->buildTrendChart($monthLabels, $genderTrendSeries),
        ];

        return response()->json([
            'date_from' => $rangeStart->toDateString(),
            'date_to' => $rangeEnd->toDateString(),
            'charts' => $charts,
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function resolveAnalyticsDateRange(mixed $rawDateFrom, mixed $rawDateTo): array
    {
        $now = CarbonImmutable::now();

        $parsedDateFrom = $this->parseAnalyticsDate($rawDateFrom);
        $parsedDateTo = $this->parseAnalyticsDate($rawDateTo);

        if (! $parsedDateFrom && ! $parsedDateTo) {
            return [$now->startOfYear(), $now->endOfYear()];
        }

        $dateFrom = $parsedDateFrom ?? $parsedDateTo;
        $dateTo = $parsedDateTo ?? $parsedDateFrom;

        if (! $dateFrom || ! $dateTo) {
            return [$now->startOfYear(), $now->endOfYear()];
        }

        if ($dateFrom->gt($dateTo)) {
            return [$dateTo, $dateFrom];
        }

        return [$dateFrom, $dateTo];
    }

    private function parseAnalyticsDate(mixed $value): ?CarbonImmutable
    {
        $trimmedValue = trim((string) ($value ?? ''));
        if ($trimmedValue === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($trimmedValue)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    private function buildAnalyticsMonthLabels(CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $labels = [];
        $cursor = $rangeStart->startOfMonth();
        $lastMonth = $rangeEnd->startOfMonth();

        while ($cursor->lte($lastMonth)) {
            $labels[] = $cursor->format('M Y');
            $cursor = $cursor->addMonth();
        }

        return $labels;
    }

    /**
     * @return array<int, string>
     */
    private function buildAnalyticsMonthKeys(CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd): array
    {
        $keys = [];
        $cursor = $rangeStart->startOfMonth();
        $lastMonth = $rangeEnd->startOfMonth();

        while ($cursor->lte($lastMonth)) {
            $keys[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $keys;
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<string, int>
     */
    private function initializeCountMap(array $labels): array
    {
        return array_fill_keys($labels, 0);
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<string, float>
     */
    private function initializeFloatMap(array $labels): array
    {
        return array_fill_keys($labels, 0.0);
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<string, array<int, int>>
     */
    private function initializeTrendSeries(array $labels, int $bucketCount): array
    {
        $series = [];
        foreach ($labels as $label) {
            $series[$label] = array_fill(0, $bucketCount, 0);
        }

        return $series;
    }

    /**
     * @param  array<string, int|float>  $counts
     * @return array{labels: array<int, string>, series: array<int, int|float>}
     */
    private function buildCountChart(array $counts): array
    {
        return [
            'labels' => array_values(array_keys($counts)),
            'series' => array_values(array_map(
                static fn (int|float $value): int|float => $value,
                $counts
            )),
        ];
    }

    /**
     * @param  array<int, string>  $labels
     * @param  array<string, array<int, int>>  $seriesByName
     * @return array{
     *     labels: array<int, string>,
     *     series: array<int, array{name:string,data:array<int, int>}>
     * }
     */
    private function buildTrendChart(array $labels, array $seriesByName): array
    {
        $series = [];
        foreach ($seriesByName as $name => $data) {
            $series[] = [
                'name' => $name,
                'data' => array_values(array_map(
                    static fn (int $value): int => $value,
                    $data
                )),
            ];
        }

        return [
            'labels' => $labels,
            'series' => $series,
        ];
    }

    /**
     * @param  iterable<object>  $employees
     * @param  array<string, string>  $sexByControlNo
     * @return array<string, array{
     *     employment_status:string,
     *     generation:string,
     *     age_group:string,
     *     gender:string
     * }>
     */
    private function buildDemographicProfiles(iterable $employees, array $sexByControlNo): array
    {
        $profiles = [];

        foreach ($employees as $employee) {
            $rawControlNo = trim((string) ($employee->control_no ?? $employee->employee_control_no ?? ''));
            $normalizedControlNo = $this->normalizeControlNo($rawControlNo);
            if ($normalizedControlNo === '') {
                continue;
            }

            $age = $this->resolveAgeFromBirthDate($employee->birth_date ?? null);
            $profiles[$normalizedControlNo] = [
                'employment_status' => $this->normalizeEmploymentStatusLabel($employee->status ?? null),
                'generation' => $this->normalizeGenerationLabel($age),
                'age_group' => $this->normalizeAgeGroupLabel($age),
                'gender' => $sexByControlNo[$normalizedControlNo] ?? 'Unknown',
            ];
        }

        return $profiles;
    }

    /**
     * @return array{
     *     employment_status:string,
     *     generation:string,
     *     age_group:string,
     *     gender:string
     * }
     */
    private function unknownDemographicProfile(): array
    {
        return [
            'employment_status' => 'Others',
            'generation' => 'Unknown',
            'age_group' => 'Unknown',
            'gender' => 'Unknown',
        ];
    }

    private function normalizeEmploymentStatusLabel(mixed $rawStatus): string
    {
        $normalizedStatus = strtoupper(trim((string) ($rawStatus ?? '')));

        return match ($normalizedStatus) {
            'REGULAR', 'PERMANENT', 'APPOINTED' => 'Regular',
            'CASUAL' => 'Casual',
            'CO-TERMINOUS', 'CO TERMINOUS', 'COTERMINOUS' => 'Co-terminous',
            'CONTRACTUAL', 'TEMPORARY' => 'Contractual / Temporary',
            'ELECTIVE' => 'Elective',
            default => 'Others',
        };
    }

    private function normalizeGenerationLabel(?int $age): string
    {
        if ($age === null) {
            return 'Unknown';
        }

        if ($age <= 29) {
            return 'Gen Z';
        }

        if ($age <= 45) {
            return 'Millennial';
        }

        if ($age <= 61) {
            return 'Gen X';
        }

        if ($age <= 80) {
            return 'Baby Boomer';
        }

        return 'Silent Generation';
    }

    private function normalizeAgeGroupLabel(?int $age): string
    {
        if ($age === null) {
            return 'Unknown';
        }

        if ($age < 25) {
            return '18-24';
        }

        if ($age < 35) {
            return '25-34';
        }

        if ($age < 45) {
            return '35-44';
        }

        if ($age < 55) {
            return '45-54';
        }

        if ($age < 65) {
            return '55-64';
        }

        return '65+';
    }

    private function normalizeGenderLabel(mixed $rawSex): string
    {
        $normalizedSex = strtoupper(trim((string) ($rawSex ?? '')));

        return match ($normalizedSex) {
            'M', 'MALE' => 'Male',
            'F', 'FEMALE' => 'Female',
            default => 'Unknown',
        };
    }

    private function resolveAgeFromBirthDate(mixed $birthDate): ?int
    {
        if ($birthDate === null || $birthDate === '') {
            return null;
        }

        try {
            $resolvedBirthDate = $birthDate instanceof \DateTimeInterface
                ? CarbonImmutable::instance($birthDate)
                : CarbonImmutable::parse((string) $birthDate);

            $now = CarbonImmutable::now();
            if ($resolvedBirthDate->gt($now)) {
                return null;
            }

            return (int) $resolvedBirthDate->diffInYears($now);
        } catch (\Throwable) {
            return null;
        }
    }

    private function resolveAnalyticsUsageDate(LeaveApplication $application): ?CarbonImmutable
    {
        $candidates = [
            $application->getAttribute('date_filed'),
            $application->getAttribute('created_at'),
            $application->getAttribute('start_date'),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }

            try {
                if ($candidate instanceof \DateTimeInterface) {
                    return CarbonImmutable::instance($candidate)->startOfDay();
                }

                return CarbonImmutable::parse((string) $candidate)->startOfDay();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $controlNos
     * @return array<string, string>
     */
    private function fetchSexByControlNos(array $controlNos): array
    {
        $lookupValues = collect($controlNos)
            ->flatMap(function (mixed $controlNo): array {
                $rawControlNo = trim((string) ($controlNo ?? ''));
                if ($rawControlNo === '') {
                    return [];
                }

                return $this->controlNoCandidates($rawControlNo);
            })
            ->uniqueStrict()
            ->values()
            ->all();

        if ($lookupValues === []) {
            return [];
        }

        $sexByControlNo = [];

        foreach (array_chunk($lookupValues, 500) as $lookupChunk) {
            try {
                $rows = DB::connection('hr')
                    ->table('xPersonal as xp')
                    ->selectRaw('LTRIM(RTRIM(CONVERT(VARCHAR(64), xp.ControlNo))) as control_no')
                    ->selectRaw('xp.Sex as sex')
                    ->whereIn('xp.ControlNo', $lookupChunk)
                    ->get();
            } catch (\Throwable $exception) {
                Log::warning('Unable to fetch HRIS sex data for analytics charts.', [
                    'lookup_count' => count($lookupChunk),
                    'exception_class' => $exception::class,
                    'message' => trim($exception->getMessage()),
                ]);

                continue;
            }

            foreach ($rows as $row) {
                $normalizedControlNo = $this->normalizeControlNo($row->control_no ?? null);
                if ($normalizedControlNo === '') {
                    continue;
                }

                $sexByControlNo[$normalizedControlNo] = $this->normalizeGenderLabel($row->sex ?? null);
            }
        }

        return $sexByControlNo;
    }

    private function normalizeControlNo(mixed $controlNo): string
    {
        $normalized = ltrim(trim((string) ($controlNo ?? '')), '0');
        if ($normalized === '') {
            $normalized = '0';
        }

        return preg_match('/^\d+$/', $normalized) ? $normalized : '';
    }

    /**
     * @return array<int, string>
     */
    private function controlNoCandidates(string $controlNo): array
    {
        $rawControlNo = trim($controlNo);
        if ($rawControlNo === '') {
            return [];
        }

        $normalizedControlNo = ltrim($rawControlNo, '0');
        if ($normalizedControlNo === '') {
            $normalizedControlNo = '0';
        }

        return array_values(array_unique(array_filter(
            [$rawControlNo, $normalizedControlNo],
            fn (string $value): bool => $value !== ''
        )));
    }
}
