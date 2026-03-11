<?php

namespace App\Console\Commands;

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

        $totalAccrued = 0;

        foreach ($accruedTypes as $type) {
            $balances = LeaveBalance::where('leave_type_id', $type->id)
                ->whereNotNull('initialized_at')
                ->get();

            foreach ($balances as $balance) {
                // Prevent double accrual for same month
                if ($balance->last_accrual_date && $balance->last_accrual_date->format('Y-m') === $accrualMonth) {
                    continue;
                }

                DB::transaction(function () use ($balance, $type, $now): void {
                    $balance->balance += $type->accrual_rate;
                    $balance->last_accrual_date = $now->toDateString();
                    $balance->save();

                    LeaveBalanceAccrualHistory::updateOrCreate(
                        [
                            'leave_balance_id' => $balance->id,
                            'accrual_date' => $now->toDateString(),
                        ],
                        [
                            'credits_added' => (float) $type->accrual_rate,
                            'source' => 'AUTOMATED',
                        ]
                    );
                });

                $totalAccrued++;
            }
        }

        $this->info("Accrued {$totalAccrued} balance(s) across {$accruedTypes->count()} leave type(s).");

        return self::SUCCESS;
    }
}
