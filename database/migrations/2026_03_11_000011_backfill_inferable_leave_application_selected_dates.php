<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tblLeaveApplications')) {
            return;
        }

        DB::table('tblLeaveApplications')
            ->select(['id', 'start_date', 'end_date', 'total_days'])
            ->whereNull('selected_dates')
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->chunkById(100, function ($applications): void {
                foreach ($applications as $application) {
                    $selectedDates = $this->inferSelectedDates(
                        $application->start_date,
                        $application->end_date,
                        $application->total_days
                    );

                    if ($selectedDates === null) {
                        continue;
                    }

                    DB::table('tblLeaveApplications')
                        ->where('id', $application->id)
                        ->update([
                            'selected_dates' => json_encode($selectedDates),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Intentionally left blank. Reverting would discard recovered historical data.
    }

    private function inferSelectedDates(mixed $startDate, mixed $endDate, mixed $totalDays): ?array
    {
        $rangeDates = $this->buildDateRange($startDate, $endDate);
        if ($rangeDates === []) {
            return null;
        }

        if (!$this->matchesTotalDays($rangeDates, $totalDays)) {
            return null;
        }

        return $rangeDates;
    }

    private function buildDateRange(mixed $startDate, mixed $endDate): array
    {
        try {
            $cursor = CarbonImmutable::parse((string) $startDate)->startOfDay();
            $lastDate = CarbonImmutable::parse((string) $endDate)->startOfDay();
        } catch (\Throwable) {
            return [];
        }

        if ($cursor->gt($lastDate)) {
            return [];
        }

        $dates = [];
        while ($cursor->lte($lastDate)) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }

    private function matchesTotalDays(array $rangeDates, mixed $totalDays): bool
    {
        if ($rangeDates === [] || !is_numeric($totalDays)) {
            return false;
        }

        $normalizedTotalDays = (float) $totalDays;
        $roundedTotalDays = round($normalizedTotalDays);
        if (abs($normalizedTotalDays - $roundedTotalDays) > 0.00001) {
            return false;
        }

        return (int) $roundedTotalDays === count($rangeDates);
    }
};
