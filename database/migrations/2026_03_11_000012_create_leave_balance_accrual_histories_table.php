<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('tblLeaveBalanceAccrualHistories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('leave_balance_id')
                ->constrained('tblLeaveBalances')
                ->cascadeOnDelete();
            $table->decimal('credits_added', 8, 2);
            $table->date('accrual_date');
            $table->string('source', 32)->default('AUTOMATED');
            $table->timestamps();

            $table->unique(['leave_balance_id', 'accrual_date']);
        });

        $this->backfillExistingAccrualHistory();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tblLeaveBalanceAccrualHistories');
    }

    private function backfillExistingAccrualHistory(): void
    {
        $now = now();
        $rowsToInsert = [];

        $balances = DB::table('tblLeaveBalances')
            ->join('tblLeaveTypes', 'tblLeaveTypes.id', '=', 'tblLeaveBalances.leave_type_id')
            ->select([
                'tblLeaveBalances.id as leave_balance_id',
                'tblLeaveBalances.initialized_at',
                'tblLeaveBalances.created_at',
                'tblLeaveBalances.last_accrual_date',
                'tblLeaveTypes.accrual_rate',
                'tblLeaveTypes.accrual_day_of_month',
            ])
            ->where('tblLeaveTypes.category', 'ACCRUED')
            ->whereNotNull('tblLeaveTypes.accrual_rate')
            ->where('tblLeaveTypes.accrual_rate', '>', 0)
            ->whereNotNull('tblLeaveBalances.last_accrual_date')
            ->orderBy('tblLeaveBalances.id')
            ->get();

        foreach ($balances as $balance) {
            $creditsAdded = round((float) $balance->accrual_rate, 2);
            if ($creditsAdded <= 0) {
                continue;
            }

            foreach ($this->resolveBackfillAccrualDates($balance) as $accrualDate) {
                $rowsToInsert[] = [
                    'leave_balance_id' => (int) $balance->leave_balance_id,
                    'credits_added' => $creditsAdded,
                    'accrual_date' => $accrualDate,
                    'source' => 'BACKFILL',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rowsToInsert, 500) as $chunk) {
            DB::table('tblLeaveBalanceAccrualHistories')->upsert(
                $chunk,
                ['leave_balance_id', 'accrual_date'],
                ['credits_added', 'source', 'updated_at']
            );
        }
    }

    private function resolveBackfillAccrualDates(object $balance): array
    {
        $lastAccrualDateRaw = trim((string) ($balance->last_accrual_date ?? ''));
        if ($lastAccrualDateRaw === '') {
            return [];
        }

        $lastAccrualDate = Carbon::parse($lastAccrualDateRaw)->startOfDay();
        $initialReference = $balance->initialized_at ?? $balance->created_at ?? null;
        if ($initialReference === null) {
            return [$lastAccrualDate->toDateString()];
        }

        $initializedAt = Carbon::parse($initialReference)->startOfDay();
        if ($initializedAt->gte($lastAccrualDate)) {
            return [$lastAccrualDate->toDateString()];
        }

        $accrualDay = (int) ($balance->accrual_day_of_month ?: $lastAccrualDate->day ?: 1);
        $cursor = Carbon::create(
            $initializedAt->year,
            $initializedAt->month,
            1,
            0,
            0,
            0,
            $initializedAt->getTimezone()
        )->startOfDay();
        $cursor->day(min($accrualDay, $cursor->daysInMonth));

        if ($cursor->lt($initializedAt)) {
            $cursor = $cursor->copy()->addMonthNoOverflow()->startOfDay();
            $cursor->day(min($accrualDay, $cursor->daysInMonth));
        }

        $dates = [];
        while ($cursor->format('Y-m') < $lastAccrualDate->format('Y-m')) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->copy()->addMonthNoOverflow()->startOfDay();
            $cursor->day(min($accrualDay, $cursor->daysInMonth));
        }

        $dates[] = $lastAccrualDate->toDateString();

        return array_values(array_unique($dates));
    }
};
