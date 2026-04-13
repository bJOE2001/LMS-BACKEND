<?php

namespace App\Services;

use App\Models\COCApplication;
use App\Models\COCApplicationRow;
use App\Models\CocLedgerEntry;
use App\Models\HrisEmployee;
use App\Models\LeaveApplication;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CocLedgerService
{
    private const HOURS_PER_DAY = 8.0;
    private const MINUTES_PER_HOUR = 60;
    private const MINUTES_PER_WORKDAY = 480;
    private const MINIMUM_CREDITABLE_EXCESS_MINUTES = 20;

    public function syncEmployeeLedger(
        string $employeeControlNo,
        ?int $leaveTypeId = null,
        ?CarbonImmutable $asOfDate = null,
        bool $syncLeaveBalance = true
    ): array {
        $resolvedLeaveTypeId = $leaveTypeId ?: $this->resolveCtoLeaveTypeId();
        if ($resolvedLeaveTypeId === null) {
            return $this->emptySnapshot();
        }

        $controlNoCandidates = $this->controlNoCandidates($employeeControlNo);
        if ($controlNoCandidates === []) {
            return $this->emptySnapshot($resolvedLeaveTypeId);
        }

        $snapshot = $this->buildLedgerSnapshot($employeeControlNo, $resolvedLeaveTypeId, $asOfDate);

        if ($this->hasLedgerTable()) {
            $this->persistLedgerEntries($controlNoCandidates, $snapshot);
        }

        if ($syncLeaveBalance) {
            $this->syncLeaveBalanceRow($controlNoCandidates, $snapshot);
        }

        return $snapshot;
    }

    public function getAvailableHours(
        string $employeeControlNo,
        ?int $leaveTypeId = null,
        ?CarbonImmutable $asOfDate = null
    ): float {
        return (float) ($this->syncEmployeeLedger($employeeControlNo, $leaveTypeId, $asOfDate, false)['availableHours'] ?? 0.0);
    }

    public function getAvailableDays(
        string $employeeControlNo,
        ?int $leaveTypeId = null,
        ?CarbonImmutable $asOfDate = null
    ): float {
        return (float) ($this->syncEmployeeLedger($employeeControlNo, $leaveTypeId, $asOfDate, false)['availableDays'] ?? 0.0);
    }

    public function getHistoryByLeaveType(
        string $employeeControlNo,
        ?int $leaveTypeId = null,
        ?CarbonImmutable $asOfDate = null
    ): array {
        $snapshot = $this->syncEmployeeLedger($employeeControlNo, $leaveTypeId, $asOfDate, false);
        $resolvedLeaveTypeId = (int) ($snapshot['leaveTypeId'] ?? 0);
        if ($resolvedLeaveTypeId <= 0) {
            return [];
        }

        $entries = collect($snapshot['entries'] ?? [])
            ->map(fn(array $entry) => $this->formatHistoryEntry($entry))
            ->filter()
            ->values()
            ->all();

        return [
            $resolvedLeaveTypeId => $entries,
        ];
    }

    private function buildLedgerSnapshot(
        string $employeeControlNo,
        int $leaveTypeId,
        ?CarbonImmutable $asOfDate = null
    ): array {
        $controlNoCandidates = $this->controlNoCandidates($employeeControlNo);
        if ($controlNoCandidates === []) {
            return $this->emptySnapshot($leaveTypeId);
        }

        $canonicalControlNo = $this->resolveCanonicalControlNo($employeeControlNo);
        $asOf = $asOfDate ?? CarbonImmutable::now();

        $earnedEvents = COCApplication::query()
            ->where('status', COCApplication::STATUS_APPROVED)
            ->whereIn('employee_control_no', $controlNoCandidates)
            ->where('cto_leave_type_id', $leaveTypeId)
            ->where(function ($query): void {
                $query
                    ->whereNotNull('credited_hours')
                    ->where('credited_hours', '>', 0)
                    ->orWhere(function ($nestedQuery): void {
                        $nestedQuery
                            ->whereNotNull('cto_credited_days')
                            ->where('cto_credited_days', '>', 0);
                    });
            })
            ->orderBy('cto_credited_at')
            ->orderBy('reviewed_at')
            ->orderBy('id')
            ->get([
                'id',
                'credited_hours',
                'cto_credited_days',
                'cto_credited_at',
                'reviewed_at',
                'created_at',
            ])
            ->map(function (COCApplication $application): ?array {
                $hours = $this->resolveEarnedHours($application);
                if ($hours <= 0) {
                    return null;
                }

                $effectiveAtRaw = $application->cto_credited_at ?? $application->reviewed_at ?? $application->created_at;
                if ($effectiveAtRaw === null) {
                    return null;
                }

                $effectiveAt = CarbonImmutable::parse((string) $effectiveAtRaw);
                $expiresOn = $this->resolveCocExpiryDate($effectiveAt->startOfDay());

                return [
                    'sourceType' => 'EARNED',
                    'sourceId' => (int) $application->id,
                    'effectiveAt' => $effectiveAt,
                    'expiresOn' => $expiresOn,
                    'hours' => $hours,
                ];
            })
            ->filter()
            ->values();

        $usedEvents = LeaveApplication::query()
            ->where('status', LeaveApplication::STATUS_APPROVED)
            ->whereIn('employee_control_no', $controlNoCandidates)
            ->where('leave_type_id', $leaveTypeId)
            ->where(function ($query): void {
                $query->where('is_monetization', true)
                    ->orWhereRaw(
                        'UPPER(LTRIM(RTRIM(COALESCE(pay_mode, ?)))) <> ?',
                        [LeaveApplication::PAY_MODE_WITH_PAY, LeaveApplication::PAY_MODE_WITHOUT_PAY]
                    );
            })
            ->orderBy('hr_approved_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get($this->resolveUsedLeaveApplicationColumns())
            ->map(function (LeaveApplication $application): ?array {
                $hours = $this->resolveUsedHours($application);
                if ($hours <= 0) {
                    return null;
                }

                $effectiveAtRaw = $application->hr_approved_at ?? $application->created_at;
                if ($effectiveAtRaw === null) {
                    return null;
                }

                return [
                    'sourceType' => 'USED',
                    'sourceId' => (int) $application->id,
                    'effectiveAt' => CarbonImmutable::parse((string) $effectiveAtRaw),
                    'hours' => $hours,
                ];
            })
            ->filter()
            ->values();

        $timelineEvents = $earnedEvents
            ->concat($usedEvents)
            ->filter(fn(array $event): bool => $event['effectiveAt']->lt($asOf) || $event['effectiveAt']->equalTo($asOf))
            ->sort(function (array $left, array $right): int {
                $dateComparison = $left['effectiveAt']->getTimestamp() <=> $right['effectiveAt']->getTimestamp();
                if ($dateComparison !== 0) {
                    return $dateComparison;
                }

                $priority = ['EARNED' => 0, 'USED' => 1];
                $leftPriority = $priority[$left['sourceType']] ?? 99;
                $rightPriority = $priority[$right['sourceType']] ?? 99;
                if ($leftPriority !== $rightPriority) {
                    return $leftPriority <=> $rightPriority;
                }

                return ((int) ($left['sourceId'] ?? 0)) <=> ((int) ($right['sourceId'] ?? 0));
            })
            ->values();

        $creditBuckets = [];
        $entries = [];
        $runningBalanceHours = 0.0;
        $sequenceNo = 1;

        $expireBuckets = function (CarbonImmutable $cutoff) use (
            &$creditBuckets,
            &$entries,
            &$runningBalanceHours,
            &$sequenceNo
        ): void {
            $creditBuckets = collect($creditBuckets)
                ->sortBy([
                    fn(array $bucket) => $bucket['expiresOn']->getTimestamp(),
                    fn(array $bucket) => $bucket['effectiveAt']->getTimestamp(),
                    fn(array $bucket) => $bucket['cocApplicationId'] ?? 0,
                ])
                ->values()
                ->all();

            foreach ($creditBuckets as &$bucket) {
                $remainingHours = round((float) ($bucket['remainingHours'] ?? 0), 2);
                if ($remainingHours <= 0) {
                    continue;
                }

                if (!$bucket['expiresOn']->lt($cutoff)) {
                    continue;
                }

                $runningBalanceHours = round(max($runningBalanceHours - $remainingHours, 0.0), 2);
                $entries[] = [
                    'sequenceNo' => $sequenceNo++,
                    'entryType' => 'EXPIRED',
                    'referenceType' => 'COC_EXPIRY',
                    'cocApplicationId' => $bucket['cocApplicationId'],
                    'leaveApplicationId' => null,
                    'hours' => $remainingHours,
                    'balanceAfterHours' => $runningBalanceHours,
                    'effectiveAt' => $bucket['expiresOn'],
                    'expiresOn' => $bucket['expiresOn'],
                    'remarks' => 'Unused COC expired after the immediately succeeding year.',
                ];

                $bucket['remainingHours'] = 0.0;
            }
            unset($bucket);
        };

        foreach ($timelineEvents as $event) {
            $expireBuckets($event['effectiveAt']);

            if (($event['sourceType'] ?? '') === 'EARNED') {
                $hours = round((float) ($event['hours'] ?? 0), 2);
                if ($hours <= 0) {
                    continue;
                }

                $creditBuckets[] = [
                    'cocApplicationId' => (int) ($event['sourceId'] ?? 0),
                    'effectiveAt' => $event['effectiveAt'],
                    'expiresOn' => $event['expiresOn'],
                    'remainingHours' => $hours,
                ];

                $runningBalanceHours = round($runningBalanceHours + $hours, 2);
                $entries[] = [
                    'sequenceNo' => $sequenceNo++,
                    'entryType' => 'EARNED',
                    'referenceType' => 'COC_APPLICATION',
                    'cocApplicationId' => (int) ($event['sourceId'] ?? 0),
                    'leaveApplicationId' => null,
                    'hours' => $hours,
                    'balanceAfterHours' => $runningBalanceHours,
                    'effectiveAt' => $event['effectiveAt'],
                    'expiresOn' => $event['expiresOn'],
                    'remarks' => 'Approved COC application converted to CTO credits.',
                ];

                continue;
            }

            if (($event['sourceType'] ?? '') !== 'USED') {
                continue;
            }

            $remainingToDeduct = round((float) ($event['hours'] ?? 0), 2);
            if ($remainingToDeduct <= 0) {
                continue;
            }

            $creditBuckets = collect($creditBuckets)
                ->sortBy([
                    fn(array $bucket) => $bucket['expiresOn']->getTimestamp(),
                    fn(array $bucket) => $bucket['effectiveAt']->getTimestamp(),
                    fn(array $bucket) => $bucket['cocApplicationId'] ?? 0,
                ])
                ->values()
                ->all();

            foreach ($creditBuckets as &$bucket) {
                if ($remainingToDeduct <= 0) {
                    break;
                }

                $bucketRemainingHours = round((float) ($bucket['remainingHours'] ?? 0), 2);
                if ($bucketRemainingHours <= 0 || $bucket['expiresOn']->lt($event['effectiveAt'])) {
                    continue;
                }

                $consumedHours = min($bucketRemainingHours, $remainingToDeduct);
                $bucket['remainingHours'] = round($bucketRemainingHours - $consumedHours, 2);
                $remainingToDeduct = round($remainingToDeduct - $consumedHours, 2);
            }
            unset($bucket);

            $appliedHours = round((float) ($event['hours'] ?? 0) - $remainingToDeduct, 2);
            if ($appliedHours <= 0) {
                continue;
            }

            $runningBalanceHours = round(max($runningBalanceHours - $appliedHours, 0.0), 2);
            $entries[] = [
                'sequenceNo' => $sequenceNo++,
                'entryType' => 'USED',
                'referenceType' => 'CTO_LEAVE_APPLICATION',
                'cocApplicationId' => null,
                'leaveApplicationId' => (int) ($event['sourceId'] ?? 0),
                'hours' => $appliedHours,
                'balanceAfterHours' => $runningBalanceHours,
                'effectiveAt' => $event['effectiveAt'],
                'expiresOn' => null,
                'remarks' => 'Approved CTO leave deducted from available COC balance.',
            ];
        }

        $expireBuckets($asOf);

        $availableHours = round(max($runningBalanceHours, 0.0), 2);

        return [
            'canonicalControlNo' => $canonicalControlNo,
            'leaveTypeId' => $leaveTypeId,
            'entries' => collect($entries)->values(),
            'availableHours' => $availableHours,
            'availableDays' => $this->hoursToDays($availableHours),
            'asOfDate' => $asOf->toDateString(),
        ];
    }

    private function persistLedgerEntries(array $controlNoCandidates, array $snapshot): void
    {
        $leaveTypeId = (int) ($snapshot['leaveTypeId'] ?? 0);
        if ($leaveTypeId <= 0) {
            return;
        }

        CocLedgerEntry::query()
            ->whereIn('employee_control_no', $controlNoCandidates)
            ->where('leave_type_id', $leaveTypeId)
            ->delete();

        $entries = collect($snapshot['entries'] ?? []);
        if ($entries->isEmpty()) {
            return;
        }

        $timestamp = now();
        $canonicalControlNo = (string) ($snapshot['canonicalControlNo'] ?? '');

        CocLedgerEntry::query()->insert($entries->map(function (array $entry) use ($canonicalControlNo, $leaveTypeId, $timestamp): array {
            return [
                'employee_control_no' => $canonicalControlNo,
                'leave_type_id' => $leaveTypeId,
                'sequence_no' => (int) ($entry['sequenceNo'] ?? 0),
                'entry_type' => (string) ($entry['entryType'] ?? ''),
                'reference_type' => $entry['referenceType'] ?? null,
                'coc_application_id' => $entry['cocApplicationId'] ?? null,
                'leave_application_id' => $entry['leaveApplicationId'] ?? null,
                'hours' => round((float) ($entry['hours'] ?? 0), 2),
                'balance_after_hours' => round((float) ($entry['balanceAfterHours'] ?? 0), 2),
                'effective_at' => $entry['effectiveAt'] instanceof CarbonImmutable
                    ? $entry['effectiveAt']->toDateTimeString()
                    : null,
                'expires_on' => $entry['expiresOn'] instanceof CarbonImmutable
                    ? $entry['expiresOn']->toDateString()
                    : null,
                'remarks' => $entry['remarks'] ?? null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        })->all());
    }

    private function syncLeaveBalanceRow(array $controlNoCandidates, array $snapshot): void
    {
        $leaveTypeId = (int) ($snapshot['leaveTypeId'] ?? 0);
        if ($leaveTypeId <= 0 || $controlNoCandidates === []) {
            return;
        }

        $availableDays = round((float) ($snapshot['availableDays'] ?? 0), 2);
        $canonicalControlNo = (string) ($snapshot['canonicalControlNo'] ?? '');
        if ($canonicalControlNo === '') {
            return;
        }

        $balance = LeaveBalance::query()
            ->whereIn('employee_control_no', $controlNoCandidates)
            ->where('leave_type_id', $leaveTypeId)
            ->first();

        if (!$balance && $availableDays <= 0) {
            return;
        }

        if (!$balance) {
            LeaveBalance::query()->create([
                'employee_control_no' => $canonicalControlNo,
                'leave_type_id' => $leaveTypeId,
                'balance' => $availableDays,
                'year' => (int) now()->year,
            ]);

            return;
        }

        if (round((float) $balance->balance, 2) !== $availableDays || !$balance->year) {
            $balance->balance = $availableDays;
            if (!$balance->year) {
                $balance->year = (int) now()->year;
            }
            $balance->save();
        }
    }

    private function formatHistoryEntry(array $entry): ?array
    {
        $effectiveAt = $entry['effectiveAt'] ?? null;
        if (!$effectiveAt instanceof CarbonImmutable) {
            return null;
        }

        $entryType = strtoupper((string) ($entry['entryType'] ?? ''));
        $hours = round((float) ($entry['hours'] ?? 0), 2);
        if ($hours <= 0) {
            return null;
        }

        $signedDays = in_array($entryType, ['USED', 'EXPIRED', 'FORFEITED'], true)
            ? -$this->hoursToDays($hours)
            : $this->hoursToDays($hours);

        return [
            'accrual_date' => $effectiveAt->toDateString(),
            'transaction_date' => $effectiveAt->toDateString(),
            'credits_added' => $signedDays,
            'entry_type' => $entryType,
            'transaction_type' => in_array($entryType, ['USED', 'EXPIRED', 'FORFEITED'], true) ? 'DEDUCTION' : 'CREDIT',
            'label' => $this->resolveHistoryLabel($entryType),
            'description' => $entry['remarks'] ?? null,
            'expires_on' => ($entry['expiresOn'] ?? null) instanceof CarbonImmutable
                ? $entry['expiresOn']->toDateString()
                : null,
            'is_expired' => $entryType === 'EXPIRED',
            'application_id' => $this->resolveHistoryApplicationId($entry),
            'coc_application_id' => $entry['cocApplicationId'] ?? null,
            'leave_application_id' => $entry['leaveApplicationId'] ?? null,
            'source' => $entry['referenceType'] ?? null,
            'hours' => $hours,
            'balance_after' => $this->hoursToDays((float) ($entry['balanceAfterHours'] ?? 0)),
            'running_balance' => $this->hoursToDays((float) ($entry['balanceAfterHours'] ?? 0)),
            'balance_after_hours' => round((float) ($entry['balanceAfterHours'] ?? 0), 2),
            'created_at' => $effectiveAt->toIso8601String(),
        ];
    }

    private function resolveHistoryLabel(string $entryType): string
    {
        return match ($entryType) {
            'USED' => 'CTO used',
            'EXPIRED' => 'COC expired',
            'FORFEITED' => 'COC forfeited',
            default => 'COC converted to CTO',
        };
    }

    private function resolveHistoryApplicationId(array $entry): ?string
    {
        if (!empty($entry['cocApplicationId'])) {
            return 'COC-' . (int) $entry['cocApplicationId'];
        }

        if (!empty($entry['leaveApplicationId'])) {
            return 'LEAVE-' . (int) $entry['leaveApplicationId'];
        }

        return null;
    }

    private function resolveEarnedHours(COCApplication $application): float
    {
        $applicationRows = $application->relationLoaded('rows')
            ? $application->rows->values()
            : $application->rows()->get()->values();

        if ($applicationRows->isNotEmpty()) {
            $rowCreditedHours = round(
                $applicationRows->sum(
                    fn (COCApplicationRow $row): float => $this->resolveEffectiveRowCreditedHours($row)
                ),
                2
            );
            if ($rowCreditedHours > 0) {
                return $rowCreditedHours;
            }
        }

        $creditedHours = round((float) ($application->credited_hours ?? 0), 2);
        if ($creditedHours > 0) {
            return $creditedHours;
        }

        $creditedDays = round((float) ($application->cto_credited_days ?? 0), 2);
        if ($creditedDays > 0) {
            return round($creditedDays * self::HOURS_PER_DAY, 2);
        }

        return 0.0;
    }

    private function calculateCreditableMinutes(int $minutes): int
    {
        if ($minutes <= 0) {
            return 0;
        }

        $wholeHoursMinutes = intdiv($minutes, self::MINUTES_PER_HOUR) * self::MINUTES_PER_HOUR;
        $excessMinutes = $minutes % self::MINUTES_PER_HOUR;
        $creditableExcessMinutes = $excessMinutes >= self::MINIMUM_CREDITABLE_EXCESS_MINUTES
            ? $excessMinutes
            : 0;

        return min(self::MINUTES_PER_WORKDAY, $wholeHoursMinutes + $creditableExcessMinutes);
    }

    private function resolveEffectiveRowCreditableMinutes(COCApplicationRow $row): int
    {
        $minutes = (int) ($row->minutes ?? 0);
        if ($minutes <= 0) {
            return 0;
        }

        $breakMinutes = max((int) ($row->break_minutes ?? 0), 0);
        return $this->calculateCreditableMinutes(max($minutes - $breakMinutes, 0));
    }

    private function resolveEffectiveRowCreditMultiplier(COCApplicationRow $row): float
    {
        $creditCategory = strtoupper(trim((string) ($row->credit_category ?? '')));

        if ($creditCategory === 'SPECIAL') {
            return 1.5;
        }

        if ($creditCategory === 'REGULAR') {
            return 1.0;
        }

        $storedMultiplier = (float) ($row->credit_multiplier ?? 0);
        if ($storedMultiplier >= 1.5) {
            return 1.5;
        }

        if ($storedMultiplier > 0) {
            return 1.0;
        }

        return 0.0;
    }

    private function resolveEffectiveRowCreditedHours(COCApplicationRow $row): float
    {
        $creditMultiplier = $this->resolveEffectiveRowCreditMultiplier($row);
        if ($creditMultiplier <= 0) {
            return 0.0;
        }

        $creditableMinutes = $this->resolveEffectiveRowCreditableMinutes($row);
        if ($creditableMinutes <= 0) {
            return 0.0;
        }

        return round(($creditableMinutes / self::MINUTES_PER_HOUR) * $creditMultiplier, 2);
    }

    private function resolveUsedHours(LeaveApplication $application): float
    {
        $storedHours = round((float) ($application->cto_deducted_hours ?? 0), 2);
        if ($storedHours > 0) {
            return $storedHours;
        }

        $days = round((float) ($application->deductible_days ?? $application->total_days ?? 0), 2);
        return $days > 0 ? round($days * self::HOURS_PER_DAY, 2) : 0.0;
    }

    /**
     * @return array<int, string>
     */
    private function resolveUsedLeaveApplicationColumns(): array
    {
        $columns = [
            'id',
            'total_days',
            'deductible_days',
            'hr_approved_at',
            'created_at',
        ];

        if ($this->hasLeaveApplicationCtoHoursColumn()) {
            $columns[] = 'cto_deducted_hours';
        }

        return $columns;
    }

    private function resolveCocExpiryDate(CarbonImmutable $creditedOn): CarbonImmutable
    {
        return CarbonImmutable::create($creditedOn->year + 1, 12, 31)->endOfDay();
    }

    private function hoursToDays(float $hours): float
    {
        return $hours > 0 ? round($hours / self::HOURS_PER_DAY, 2) : 0.0;
    }

    private function resolveCtoLeaveTypeId(): ?int
    {
        static $resolved = false;
        static $cachedValue = null;

        if ($resolved) {
            return $cachedValue;
        }

        $value = LeaveType::query()
            ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['cto leave'])
            ->value('id');

        $cachedValue = $value !== null ? (int) $value : null;
        $resolved = true;

        return $cachedValue;
    }

    private function resolveCanonicalControlNo(string $employeeControlNo): string
    {
        $resolvedEmployee = HrisEmployee::findByControlNo($employeeControlNo);
        $canonicalControlNo = trim((string) ($resolvedEmployee?->control_no ?? $employeeControlNo));

        return $canonicalControlNo !== '' ? $canonicalControlNo : trim($employeeControlNo);
    }

    private function controlNoCandidates(string $employeeControlNo): array
    {
        $trimmed = trim($employeeControlNo);
        if ($trimmed === '') {
            return [];
        }

        $candidates = [$trimmed];

        if (preg_match('/^\d+$/', $trimmed)) {
            $normalized = ltrim($trimmed, '0');
            $candidates[] = $normalized !== '' ? $normalized : '0';
        }

        $resolvedEmployee = HrisEmployee::findByControlNo($trimmed);
        if ($resolvedEmployee) {
            $resolvedControlNo = trim((string) ($resolvedEmployee->control_no ?? ''));
            if ($resolvedControlNo !== '') {
                $candidates[] = $resolvedControlNo;
            }
        }

        return collect($candidates)
            ->map(fn(string $candidate): string => trim($candidate))
            ->filter(fn(string $candidate): bool => $candidate !== '')
            ->unique()
            ->values()
            ->all();
    }

    private function hasLedgerTable(): bool
    {
        static $resolved = false;
        static $hasTable = false;

        if ($resolved) {
            return $hasTable;
        }

        $hasTable = Schema::hasTable('tblCOCLedgerEntries');
        $resolved = true;

        return $hasTable;
    }

    private function hasLeaveApplicationCtoHoursColumn(): bool
    {
        static $resolved = false;
        static $hasColumn = false;

        if (!$resolved) {
            $hasColumn = Schema::hasColumn('tblLeaveApplications', 'cto_deducted_hours');
            $resolved = true;
        }

        return $hasColumn;
    }

    private function emptySnapshot(?int $leaveTypeId = null): array
    {
        return [
            'canonicalControlNo' => null,
            'leaveTypeId' => $leaveTypeId,
            'entries' => collect(),
            'availableHours' => 0.0,
            'availableDays' => 0.0,
            'asOfDate' => CarbonImmutable::today()->toDateString(),
        ];
    }
}
