<?php

namespace App\Console\Commands;

use App\Models\COCApplication;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\CocLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

class SyncCocExpiry extends Command
{
    protected $signature = 'coc:sync-expiry
        {--date= : Override as-of date (Y-m-d) for testing}
        {--control-no= : Sync only one employee control number}
        {--chunk=200 : Number of employees to process per batch}';

    protected $description = 'Synchronize COC/CTO ledgers and apply expirations to leave balances.';

    public function handle(CocLedgerService $cocLedgerService): int
    {
        $asOfDate = $this->resolveAsOfDate((string) ($this->option('date') ?? ''));
        if (!$asOfDate instanceof CarbonImmutable) {
            $this->error('Invalid --date value. Use Y-m-d format.');
            return self::FAILURE;
        }

        $chunkSize = max((int) ($this->option('chunk') ?? 200), 1);
        $requestedControlNo = trim((string) ($this->option('control-no') ?? ''));

        $ctoLeaveTypeId = $this->resolveCtoLeaveTypeId();
        if ($ctoLeaveTypeId === null) {
            $this->warn('CTO Leave type was not found. Nothing to sync.');
            return self::SUCCESS;
        }

        $controlNos = $requestedControlNo !== ''
            ? collect([$requestedControlNo])
            : $this->resolveTargetControlNos($ctoLeaveTypeId);

        if ($controlNos->isEmpty()) {
            $this->info('No CTO/COC control numbers found to sync.');
            return self::SUCCESS;
        }

        $total = $controlNos->count();
        $this->info("Syncing {$total} employee CTO ledger(s) as of {$asOfDate->toDateString()}.");

        $synced = 0;
        $failed = 0;

        foreach ($controlNos->chunk($chunkSize) as $batch) {
            foreach ($batch as $controlNo) {
                $controlNoText = trim((string) $controlNo);
                if ($controlNoText === '') {
                    continue;
                }

                try {
                    $cocLedgerService->syncEmployeeLedger(
                        $controlNoText,
                        $ctoLeaveTypeId,
                        $asOfDate,
                        true
                    );
                    $synced++;
                } catch (Throwable $exception) {
                    $failed++;
                    report($exception);
                    $this->warn("Failed to sync control no {$controlNoText}: {$exception->getMessage()}");
                }
            }
        }

        $this->info("COC expiry sync completed. Synced: {$synced}, Failed: {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveAsOfDate(string $dateOption): ?CarbonImmutable
    {
        $dateOption = trim($dateOption);
        if ($dateOption === '') {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::parse($dateOption)->startOfDay();
        } catch (Throwable) {
            return null;
        }
    }

    private function resolveCtoLeaveTypeId(): ?int
    {
        $value = LeaveType::query()
            ->whereRaw('LOWER(LTRIM(RTRIM(name))) = ?', ['cto leave'])
            ->value('id');

        return $value !== null ? (int) $value : null;
    }

    private function resolveTargetControlNos(int $ctoLeaveTypeId): Collection
    {
        $fromApprovedCoc = COCApplication::query()
            ->where('status', COCApplication::STATUS_APPROVED)
            ->where('cto_leave_type_id', $ctoLeaveTypeId)
            ->pluck('employee_control_no');

        $fromCtoBalances = LeaveBalance::query()
            ->where('leave_type_id', $ctoLeaveTypeId)
            ->pluck('employee_control_no');

        return $fromApprovedCoc
            ->concat($fromCtoBalances)
            ->map(fn (mixed $controlNo): string => trim((string) $controlNo))
            ->filter(fn (string $controlNo): bool => $controlNo !== '')
            ->unique()
            ->values();
    }
}

