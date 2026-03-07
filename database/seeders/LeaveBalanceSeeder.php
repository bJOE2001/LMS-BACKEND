<?php

namespace Database\Seeders;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * LOCAL DEVELOPMENT ONLY — seeds leave_balances for every employee.
 * Assigns realistic balances for all credit-based leave types.
 */
class LeaveBalanceSeeder extends Seeder
{
    public function run(): void
    {
        if (!app()->environment('local')) {
            exit('Seeding allowed in local environment only.');
        }

        $year = (int) now()->format('Y');

        $creditBasedTypes = LeaveType::where('is_credit_based', true)->get();

        if ($creditBasedTypes->isEmpty()) {
            $this->command->warn('No credit-based leave types found. Run LeaveTypeSeeder first.');
            return;
        }

        $employees = Employee::all();

        if ($employees->isEmpty()) {
            $this->command->warn('No employees found. Run EmployeeSeeder first.');
            return;
        }

        $this->command->info("Seeding leave balances for {$employees->count()} employees across {$creditBasedTypes->count()} leave types...");

        $rows = [];

        foreach ($employees as $employee) {
            foreach ($creditBasedTypes as $type) {
                $balance = $this->generateBalance($type);
                $rows[] = [
                    'employee_id'     => $employee->control_no,
                    'leave_type_id'   => $type->id,
                    'balance'         => $balance,
                    'year'            => $year,
                    'initialized_at'  => now(),
                    'last_accrual_date' => $type->category === 'ACCRUED' ? now()->toDateString() : null,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ];
            }
        }

        DB::transaction(function () use ($rows): void {
            foreach (array_chunk($rows, 200) as $chunk) {
                LeaveBalance::upsert(
                    $chunk,
                    ['employee_id', 'leave_type_id'],
                    ['balance', 'year', 'initialized_at', 'last_accrual_date', 'updated_at']
                );
            }
        });

        $this->command->info("Seeded " . count($rows) . " leave balance records.");
    }

    /**
     * Generate a realistic balance based on leave type category and max_days.
     */
    private function generateBalance(LeaveType $type): float
    {
        if ($type->category === 'ACCRUED') {
            // Vacation / Sick: simulate 6-48 months of 1.25 accrual = 7.50 - 60.00
            return round(fake()->randomFloat(2, 7.50, 60.00), 2);
        }

        // RESETTABLE: initialize to max_days for the current year
        return round((float) ($type->max_days ?? 5), 2);
    }
}