<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveBalanceAccrualHistory;
use App\Models\LeaveType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Monthly accrual: add accrual_rate to ACCRUED leave balances on the 1st of every month.
 * Prevents double accrual within the same month.
 *
 * Schedule: $schedule->command('leave:accrue')->monthlyOn(1, '00:01');
 */
class AccrueLeaveCredits extends Command
{
    protected $signature = 'leave:accrue {--date= : Override accrual date (Y-m-d) for testing}';

    protected $description = 'Accrue monthly leave credits for ACCRUED leave types (run on 1st of month)';

    public function handle(): int
    {
        $dateStr = $this->option('date');
        $now = $dateStr ? Carbon::parse($dateStr) : Carbon::now();
        $accrualMonth = $now->format('Y-m');

        $this->info("Running leave accrual for month: {$accrualMonth}");

        $accruedTypes = LeaveType::accrued()
            ->whereNotNull('accrual_rate')
            ->where('accrual_rate', '>', 0)
            ->get();

        if ($accruedTypes->isEmpty()) {
            $this->warn('No ACCRUED leave types found.');
            return self::SUCCESS;
        }

        $employeeControlNos = Employee::query()
            ->pluck('control_no')
            ->map(static fn(mixed $controlNo): string => trim((string) $controlNo))
            ->filter(static fn(string $controlNo): bool => $controlNo !== '')
            ->values()
            ->all();

        if ($employeeControlNos === []) {
            $this->warn('No employee records found. Nothing to accrue.');
            return self::SUCCESS;
        }

        $employeeNameLookup = $this->buildEmployeeNameLookup();
        $totalAccrued = 0;
        $totalProvisioned = 0;

        foreach ($accruedTypes as $type) {
            $provisioned = $this->provisionMissingAccruedBalances($employeeControlNos, $type, $now);
            $totalProvisioned += $provisioned;
            if ($provisioned > 0) {
                $this->line("  {$type->name}: provisioned {$provisioned} missing balance(s)");
            }

            $balances = LeaveBalance::query()
                ->where('leave_type_id', $type->id)
                ->get();

            foreach ($balances as $balance) {
                // Prevent double accrual for same month
                if ($balance->last_accrual_date && $balance->last_accrual_date->format('Y-m') === $accrualMonth) {
                    continue;
                }

                DB::transaction(function () use ($balance, $type, $now, $employeeNameLookup): void {
                    $employeeName = $this->resolveEmployeeNameForBalance($balance, $employeeNameLookup);
                    $leaveTypeName = trim((string) ($balance->leave_type_name ?? $type->name ?? ''));

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

    private function provisionMissingAccruedBalances(array $employeeControlNos, LeaveType $type, Carbon $now): int
    {
        if ($employeeControlNos === []) {
            return 0;
        }

        $existingEmployeeIds = LeaveBalance::query()
            ->where('leave_type_id', $type->id)
            ->pluck('employee_id')
            ->map(static fn(mixed $employeeId): string => trim((string) $employeeId))
            ->filter(static fn(string $employeeId): bool => $employeeId !== '')
            ->values()
            ->all();

        $existingLookup = array_fill_keys($existingEmployeeIds, true);
        $rows = [];

        foreach ($employeeControlNos as $employeeControlNo) {
            if (isset($existingLookup[$employeeControlNo])) {
                continue;
            }

            $rows[] = [
                'employee_id' => $employeeControlNo,
                'leave_type_id' => (int) $type->id,
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

        LeaveBalance::query()->upsert(
            $rows,
            ['employee_id', 'leave_type_id'],
            ['updated_at']
        );

        return count($rows);
    }

    private function buildEmployeeNameLookup(): array
    {
        $lookup = [];

        $employees = Employee::query()->get(['control_no', 'firstname', 'middlename', 'surname']);
        foreach ($employees as $employee) {
            if (!$employee instanceof Employee) {
                continue;
            }

            $name = $this->formatEmployeeNameForStorage($employee);
            if ($name === '') {
                continue;
            }

            $rawControlNo = trim((string) ($employee->control_no ?? ''));
            if ($rawControlNo !== '') {
                $lookup[$rawControlNo] = $name;
            }

            $normalizedControlNo = $this->normalizeControlNo($rawControlNo);
            if ($normalizedControlNo !== null) {
                $lookup[$normalizedControlNo] = $name;
            }
        }

        return $lookup;
    }

    private function resolveEmployeeNameForBalance(LeaveBalance $balance, array $employeeNameLookup): ?string
    {
        $fromBalance = trim((string) ($balance->employee_name ?? ''));
        if ($fromBalance !== '') {
            return $fromBalance;
        }

        $rawControlNo = trim((string) ($balance->employee_id ?? ''));
        if ($rawControlNo !== '' && isset($employeeNameLookup[$rawControlNo])) {
            return (string) $employeeNameLookup[$rawControlNo];
        }

        $normalizedControlNo = $this->normalizeControlNo($rawControlNo);
        if ($normalizedControlNo !== null && isset($employeeNameLookup[$normalizedControlNo])) {
            return (string) $employeeNameLookup[$normalizedControlNo];
        }

        return null;
    }

    private function formatEmployeeNameForStorage(Employee $employee): string
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
}
