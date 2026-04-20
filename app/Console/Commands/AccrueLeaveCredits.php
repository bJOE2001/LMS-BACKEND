<?php

namespace App\Console\Commands;

use App\Models\HrisEmployee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAccrualHistory;
use App\Models\LeaveType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Monthly accrual: add accrual_rate to ACCRUED leave balances on the 1st of every month.
 * Prevents double accrual within the same month.
 *
 * Schedule: $schedule->command('leave:accrue')->monthlyOn(1, '00:01');
 */
class AccrueLeaveCredits extends Command
{
    // SQL Server hard limit is 2100 bound parameters per statement.
    // Keep a safety margin so Laravel upsert() never exceeds the limit.
    private const SQL_SERVER_SAFE_PARAMETER_LIMIT = 2000;
    private const EXCLUDED_ACCRUAL_STATUS_KEYWORDS = ['HONORARIUM', 'CONTRACTUAL'];

    protected $signature = 'leave:accrue
        {--date= : Override accrual date (Y-m-d) for testing}
        {--hr-timeout=0 : Optional SQL Server login/query timeout in seconds for live HRIS lookup (0 = no forced timeout)}';

    protected $description = 'Accrue monthly leave credits for ACCRUED leave types (run on 1st of month)';

    public function handle(): int
    {
        $dateStr = $this->option('date');
        $now = $dateStr ? Carbon::parse($dateStr) : Carbon::now();
        $accrualMonth = $now->format('Y-m');
        $hrTimeout = max(0, (int) ($this->option('hr-timeout') ?? 0));

        $this->info("Running leave accrual for month: {$accrualMonth}");

        $accruedTypes = LeaveType::accrued()
            ->whereNotNull('accrual_rate')
            ->where('accrual_rate', '>', 0)
            ->get();

        if ($accruedTypes->isEmpty()) {
            $this->warn('No ACCRUED leave types found.');
            return self::SUCCESS;
        }

        $employeeDirectory = $this->buildEmployeeDirectory($hrTimeout);
        if ($employeeDirectory['employees'] === []) {
            $this->warn('No employee records found. Nothing to accrue.');
            return self::SUCCESS;
        }

        $totalAccrued = 0;
        $totalProvisioned = 0;

        foreach ($accruedTypes as $type) {
            $eligibleEmployeeControlNos = $this->eligibleEmployeeControlNosForType($type, $employeeDirectory['employees']);
            if ($eligibleEmployeeControlNos === []) {
                $this->line("  {$type->name}: no eligible employee records found for accrual");
                continue;
            }

            $provisioned = $this->provisionMissingAccruedBalances(
                $eligibleEmployeeControlNos,
                $type,
                $now,
                $employeeDirectory['lookup']
            );
            $totalProvisioned += $provisioned;
            if ($provisioned > 0) {
                $this->line("  {$type->name}: provisioned {$provisioned} missing balance(s)");
            }

            $balances = LeaveBalance::query()
                ->where('leave_type_id', $type->id)
                ->get();

            foreach ($balances as $balance) {
                if (! $this->balanceIsEligibleForTypeAccrual($balance, $type, $employeeDirectory['lookup'])) {
                    continue;
                }

                // Prevent double accrual for same month
                if ($balance->last_accrual_date && $balance->last_accrual_date->format('Y-m') === $accrualMonth) {
                    continue;
                }

                DB::transaction(function () use ($balance, $type, $now, $employeeDirectory): void {
                    $employeeName = $this->resolveEmployeeNameForBalance($balance, $employeeDirectory['lookup']);
                    $leaveTypeName = trim((string) ($balance->leave_type_name ?? $type->name ?? ''));

                    if ($employeeName !== null && trim((string) ($balance->employee_name ?? '')) === '') {
                        $balance->employee_name = $employeeName;
                    }
                    if ($leaveTypeName !== '' && trim((string) ($balance->leave_type_name ?? '')) === '') {
                        $balance->leave_type_name = $leaveTypeName;
                    }

                    $balance->balance = round((float) $balance->balance + (float) $type->accrual_rate, 2);
                    $balance->last_accrual_date = $now->toDateString();
                    if (!$balance->year) {
                        $balance->year = (int) $now->year;
                    }
                    $balance->save();

                    LeaveBalanceAccrualHistory::updateOrCreate(
                        [
                            'leave_balance_id' => $balance->id,
                            'accrual_date' => $now->toDateString(),
                            'source' => 'AUTOMATED',
                        ],
                        [
                            'employee_control_no' => trim((string) ($balance->employee_control_no ?? '')) ?: null,
                            'employee_name' => $employeeName,
                            'leave_type_name' => $leaveTypeName !== '' ? $leaveTypeName : null,
                            'credits_added' => (float) $type->accrual_rate,
                        ]
                    );
                });

                $totalAccrued++;
            }
        }

        if ($totalProvisioned > 0) {
            $this->info("Provisioned {$totalProvisioned} new balance row(s) before accrual.");
        }
        $this->info("Accrued {$totalAccrued} balance(s) across {$accruedTypes->count()} leave type(s).");

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     employees: array<int, array{control_no: string, status: mixed}>,
     *     lookup: array<string, array{name: string, status: mixed}>
     * }
     */
    private function buildEmployeeDirectory(int $hrTimeout): array
    {
        $employees = [];
        $lookup = [];

        $records = $this->resolveAccrualEmployeeRecords($hrTimeout);

        foreach ($records as $employee) {
            if (!is_object($employee)) {
                continue;
            }

            if (! $this->isEmployeeEligibleForAccrual($employee)) {
                continue;
            }

            $rawControlNo = trim((string) ($employee->control_no ?? ''));
            if ($rawControlNo === '') {
                continue;
            }

            $name = $this->formatEmployeeNameForStorage($employee);
            $entry = [
                'name' => $name,
                'status' => $employee->status,
            ];

            $employees[] = [
                'control_no' => $rawControlNo,
                'status' => $employee->status,
            ];

            $lookup[$rawControlNo] = $entry;

            $normalizedControlNo = $this->normalizeControlNo($rawControlNo);
            if ($normalizedControlNo !== null) {
                $lookup[$normalizedControlNo] = $entry;
            }
        }

        return [
            'employees' => $employees,
            'lookup' => $lookup,
        ];
    }

    private function resolveAccrualEmployeeRecords(int $hrTimeout): Collection
    {
        if ($hrTimeout > 0) {
            $this->configureHrTimeout($hrTimeout);
            $this->line("Trying live HRIS with {$hrTimeout}s timeout.");
        } else {
            $this->line('Trying live HRIS with no forced timeout.');
        }

        try {
            $records = HrisEmployee::query(true, false)->get();
        } catch (Throwable $exception) {
            report($exception);
            $this->warn('Live HRIS lookup failed or timed out. Skipping accrual source for this run.');
            $records = collect();
        }

        if ($records->isNotEmpty()) {
            return $records;
        }

        $this->warn('Live HRIS returned no active employee rows. Skipping accrual source for this run.');

        return collect();
    }

    private function configureHrTimeout(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        $connection = config('database.connections.hr', []);
        $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];

        if (defined('\PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            $options[\PDO::SQLSRV_ATTR_QUERY_TIMEOUT] = $seconds;
        }

        config([
            'database.connections.hr.login_timeout' => $seconds,
            'database.connections.hr.options' => $options,
        ]);

        DB::purge('hr');
    }

    /**
     * @param array<int, array{control_no: string, status: mixed}> $employees
     * @return array<int, string>
     */
    private function eligibleEmployeeControlNosForType(LeaveType $type, array $employees): array
    {
        return collect($employees)
            ->filter(function (array $employee) use ($type): bool {
                if ($this->isExcludedAccrualStatus($employee['status'] ?? null)) {
                    return false;
                }

                return $type->allowsEmploymentStatus($employee['status'] ?? null);
            })
            ->pluck('control_no')
            ->filter(fn (mixed $controlNo): bool => trim((string) $controlNo) !== '')
            ->values()
            ->all();
    }

    /**
     * @param array<string, array{name: string, status: mixed}> $employeeLookup
     */
    private function balanceIsEligibleForTypeAccrual(
        LeaveBalance $balance,
        LeaveType $type,
        array $employeeLookup
    ): bool {
        $rawControlNo = trim((string) ($balance->employee_control_no ?? ''));
        if ($rawControlNo === '') {
            return false;
        }

        if (isset($employeeLookup[$rawControlNo])) {
            if ($this->isExcludedAccrualStatus($employeeLookup[$rawControlNo]['status'] ?? null)) {
                return false;
            }

            return $type->allowsEmploymentStatus($employeeLookup[$rawControlNo]['status'] ?? null);
        }

        $normalizedControlNo = $this->normalizeControlNo($rawControlNo);
        if ($normalizedControlNo !== null && isset($employeeLookup[$normalizedControlNo])) {
            if ($this->isExcludedAccrualStatus($employeeLookup[$normalizedControlNo]['status'] ?? null)) {
                return false;
            }

            return $type->allowsEmploymentStatus($employeeLookup[$normalizedControlNo]['status'] ?? null);
        }

        return false;
    }

    /**
     * @param array<string, array{name: string, status: mixed}> $employeeLookup
     */
    private function provisionMissingAccruedBalances(
        array $employeeControlNos,
        LeaveType $type,
        Carbon $now,
        array $employeeLookup
    ): int
    {
        if ($employeeControlNos === []) {
            return 0;
        }

        $existingEmployeeControlNos = LeaveBalance::query()
            ->where('leave_type_id', $type->id)
            ->pluck('employee_control_no')
            ->map(static fn(mixed $employeeControlNo): string => trim((string) $employeeControlNo))
            ->filter(static fn(string $employeeControlNo): bool => $employeeControlNo !== '')
            ->values()
            ->all();

        $existingLookup = array_fill_keys($existingEmployeeControlNos, true);
        $rows = [];

        foreach ($employeeControlNos as $employeeControlNo) {
            if (isset($existingLookup[$employeeControlNo])) {
                continue;
            }

            $employeeName = trim((string) ($employeeLookup[$employeeControlNo]['name'] ?? ''));

            $rows[] = [
                'employee_control_no' => $employeeControlNo,
                'employee_name' => $employeeName !== '' ? $employeeName : null,
                'leave_type_id' => (int) $type->id,
                'leave_type_name' => trim((string) ($type->name ?? '')) ?: null,
                'balance' => 0.0,
                'last_accrual_date' => null,
                'year' => (int) $now->year,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return 0;
        }

        $this->upsertLeaveBalancesInChunks($rows);

        return count($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function upsertLeaveBalancesInChunks(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columnsPerRow = count($rows[0]);
        if ($columnsPerRow <= 0) {
            return;
        }

        $chunkSize = max(1, intdiv(self::SQL_SERVER_SAFE_PARAMETER_LIMIT, $columnsPerRow));

        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            LeaveBalance::query()->upsert(
                $chunk,
                ['employee_control_no', 'leave_type_id'],
                ['updated_at']
            );
        }
    }

    /**
     * @param array<string, array{name: string, status: mixed}> $employeeLookup
     */
    private function resolveEmployeeNameForBalance(LeaveBalance $balance, array $employeeLookup): ?string
    {
        $fromBalance = trim((string) ($balance->employee_name ?? ''));
        if ($fromBalance !== '') {
            return $fromBalance;
        }

        $rawControlNo = trim((string) ($balance->employee_control_no ?? ''));
        if ($rawControlNo !== '' && isset($employeeLookup[$rawControlNo])) {
            return trim((string) ($employeeLookup[$rawControlNo]['name'] ?? '')) ?: null;
        }

        $normalizedControlNo = $this->normalizeControlNo($rawControlNo);
        if ($normalizedControlNo !== null && isset($employeeLookup[$normalizedControlNo])) {
            return trim((string) ($employeeLookup[$normalizedControlNo]['name'] ?? '')) ?: null;
        }

        return null;
    }

    private function formatEmployeeNameForStorage(object $employee): string
    {
        $surname = trim((string) ($employee->surname ?? ''));
        $firstname = trim((string) ($employee->firstname ?? ''));
        $middlename = trim((string) ($employee->middlename ?? ''));

        $name = '';
        if ($surname !== '') {
            $name .= $surname;
        }

        if ($firstname !== '') {
            $name .= $name !== '' ? ', ' . $firstname : $firstname;
        }

        if ($middlename !== '') {
            $name .= ($name !== '' ? ' ' : '') . $middlename;
        }

        return trim($name);
    }

    private function normalizeControlNo(?string $value): ?string
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '' || !preg_match('/^\d+$/', $raw)) {
            return null;
        }

        $normalized = ltrim($raw, '0');
        return $normalized !== '' ? $normalized : '0';
    }

    private function isEmployeeEligibleForAccrual(object $employee): bool
    {
        if (! $this->isEmployeeActiveForAccrual($employee)) {
            return false;
        }

        return ! $this->isExcludedAccrualStatus($employee->status ?? null);
    }

    private function isEmployeeActiveForAccrual(object $employee): bool
    {
        $isActive = $employee->is_active ?? null;
        if (is_bool($isActive)) {
            return $isActive;
        }

        if (is_numeric($isActive)) {
            return (int) $isActive === 1;
        }

        $activityStatus = strtoupper(trim((string) ($employee->activity_status ?? '')));
        if ($activityStatus !== '') {
            return $activityStatus === 'ACTIVE';
        }

        // Query already requests active records; default to true when fields are missing.
        return true;
    }

    private function isExcludedAccrualStatus(mixed $status): bool
    {
        $normalizedStatus = strtoupper(trim((string) ($status ?? '')));
        if ($normalizedStatus === '') {
            return false;
        }

        foreach (self::EXCLUDED_ACCRUAL_STATUS_KEYWORDS as $keyword) {
            if (str_contains($normalizedStatus, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
