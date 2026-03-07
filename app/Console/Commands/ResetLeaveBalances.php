<?php

namespace App\Console\Commands;

use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Yearly reset: set RESETTABLE leave balances back to max_days on January 1.
 *
 * Schedule: $schedule->command('leave:reset')->yearlyOn(1, 1, '00:05');
 */
class ResetLeaveBalances extends Command
{
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

        $totalReset = 0;

        foreach ($resettableTypes as $type) {
            $maxDays = $type->max_days ?? 0;

            $updated = LeaveBalance::where('leave_type_id', $type->id)
                ->whereNotNull('initialized_at')
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
}
