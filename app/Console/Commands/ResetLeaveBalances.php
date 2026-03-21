<?php

namespace App\Console\Commands;

use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Yearly reset: set RESETTABLE leave balances back to max_days on January 1.
 *
 * Schedule: $schedule->command('leave:reset')->yearlyOn(1, 1, '00:05');
 */
class ResetLeaveBalances extends Command
{
    private const FORCED_LEAVE_NAME = 'Mandatory / Forced Leave';

    private const VACATION_LEAVE_NAME = 'Vacation Leave';

    protected $signature = 'leave:reset {--year= : Override reset year for testing}';

    protected $description = 'Reset yearly leave balances for RESETTABLE leave types (run on Jan 1)';

    public function handle(): int
    {
        $year = (int) ($this->option('year') ?: Carbon::now()->year);

        $this->info("Resetting RESETTABLE leave balances for year: {$year}");

        $resettableTypes = LeaveType::resettable()
            ->where('resets_yearly', true)
            ->get();

        if ($resettableTypes->isEmpty()) {
            $this->warn('No RESETTABLE leave types found.');
            return self::SUCCESS;
        }

        $forcedLeaveType = $resettableTypes->first(
            fn(LeaveType $type): bool => strcasecmp(trim((string) $type->name), self::FORCED_LEAVE_NAME) === 0
        );
        if ($forcedLeaveType instanceof LeaveType) {
            $vacationLeaveTypeId = $this->resolveLeaveTypeIdByName(self::VACATION_LEAVE_NAME);
            if ($vacationLeaveTypeId === null) {
                $this->warn('Vacation Leave type not found. Skipping year-end FL-to-VL deduction.');
            } else {
                $forfeitureSummary = $this->deductUnusedForcedLeaveFromVacation(
                    (int) $forcedLeaveType->id,
                    $vacationLeaveTypeId,
                    $year
                );

                $this->line(
                    sprintf(
                        '  Year-end FL->VL deduction: evaluated %d row(s), deducted %s day(s) across %d row(s), uncovered %s day(s).',
                        (int) ($forfeitureSummary['rows_evaluated'] ?? 0),
                        $this->formatDays((float) ($forfeitureSummary['total_deducted'] ?? 0)),
                        (int) ($forfeitureSummary['rows_deducted'] ?? 0),
                        $this->formatDays((float) ($forfeitureSummary['total_uncovered'] ?? 0))
                    )
                );
            }
        }

        $totalReset = 0;

        foreach ($resettableTypes as $type) {
            $maxDays = $type->max_days ?? 0;

            $updated = LeaveBalance::where('leave_type_id', $type->id)
                ->update([
                    'balance' => $maxDays,
                    'year'    => $year,
                ]);

            $totalReset += $updated;
            $this->line("  {$type->name}: reset {$updated} balance(s) to {$maxDays} days");
        }

        $this->info("Reset {$totalReset} balance(s) across {$resettableTypes->count()} leave type(s).");

        return self::SUCCESS;
    }

    private function resolveLeaveTypeIdByName(string $name): ?int
    {
        $value = LeaveType::query()
            ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', [strtolower(trim($name))])
            ->value('id');

        return $value !== null ? (int) $value : null;
    }

    private function deductUnusedForcedLeaveFromVacation(
        int $forcedLeaveTypeId,
        int $vacationLeaveTypeId,
        int $targetYear
    ): array {
        $summary = [
            'rows_evaluated' => 0,
            'rows_deducted' => 0,
            'total_deducted' => 0.0,
            'total_uncovered' => 0.0,
        ];

        LeaveBalance::query()
            ->where('leave_type_id', $forcedLeaveTypeId)
            ->where('balance', '>', 0)
            ->where(function ($query) use ($targetYear): void {
                $query->whereNull('year')
                    ->orWhere('year', '<', $targetYear);
            })
            ->orderBy('id')
            ->chunkById(200, function ($forcedBalances) use (&$summary, $vacationLeaveTypeId): void {
                foreach ($forcedBalances as $forcedBalance) {
                    if (!$forcedBalance instanceof LeaveBalance) {
                        continue;
                    }

                    $summary['rows_evaluated']++;

                    $unusedForcedDays = round(max((float) $forcedBalance->balance, 0.0), 2);
                    if ($unusedForcedDays <= 0) {
                        continue;
                    }

                    $employeeControlNo = trim((string) $forcedBalance->employee_control_no);
                    if ($employeeControlNo === '') {
                        $summary['total_uncovered'] = round(
                            (float) $summary['total_uncovered'] + $unusedForcedDays,
                            2
                        );
                        continue;
                    }

                    DB::transaction(function () use (
                        $employeeControlNo,
                        $vacationLeaveTypeId,
                        $unusedForcedDays,
                        &$summary
                    ): void {
                        $vacationBalance = LeaveBalance::query()
                            ->where('employee_control_no', $employeeControlNo)
                            ->where('leave_type_id', $vacationLeaveTypeId)
                            ->lockForUpdate()
                            ->first();

                        $availableVacationDays = $vacationBalance
                            ? round(max((float) $vacationBalance->balance, 0.0), 2)
                            : 0.0;

                        $daysToDeduct = round(min($unusedForcedDays, $availableVacationDays), 2);
                        $uncoveredDays = round(max($unusedForcedDays - $daysToDeduct, 0.0), 2);

                        if ($vacationBalance && $daysToDeduct > 0.0) {
                            $vacationBalance->decrement('balance', $daysToDeduct);
                            $summary['rows_deducted']++;
                            $summary['total_deducted'] = round(
                                (float) $summary['total_deducted'] + $daysToDeduct,
                                2
                            );
                        }

                        if ($uncoveredDays > 0.0) {
                            $summary['total_uncovered'] = round(
                                (float) $summary['total_uncovered'] + $uncoveredDays,
                                2
                            );
                        }
                    });
                }
            });

        return $summary;
    }

    private function formatDays(float $value): string
    {
        $normalized = round(max($value, 0.0), 2);
        return number_format($normalized, 2, '.', '');
    }
}
