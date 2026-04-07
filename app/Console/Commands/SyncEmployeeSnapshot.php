<?php

namespace App\Console\Commands;

use App\Models\HrisEmployee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class SyncEmployeeSnapshot extends Command
{
    protected $signature = 'hris:sync-employees
        {--office= : Sync only one office/department instead of the full HRIS directory}
        {--timeout=60 : SQL Server query timeout in seconds for this sync run}
        {--repair-missing : Retry only snapshot rows that are missing employment fields}
        {--local-only : Refresh tblEmployees using LMS local snapshot data only}';

    protected $description = 'Sync the local LMS employee snapshot table from HRIS';

    public function handle(): int
    {
        $officeName = trim((string) ($this->option('office') ?? ''));
        $queryTimeout = max(5, (int) ($this->option('timeout') ?? 60));
        $repairMissing = (bool) $this->option('repair-missing');
        $localOnly = (bool) $this->option('local-only');

        if (!$localOnly) {
            $this->configureHrQueryTimeout($queryTimeout);
        }

        $this->info(
            $localOnly
                ? 'Refreshing LMS employee snapshot from local tblEmployees data only'
                : ($repairMissing
                ? (
                    $officeName !== ''
                        ? "Repairing LMS employee snapshot rows with missing employment fields for office: {$officeName}"
                        : 'Repairing LMS employee snapshot rows with missing employment fields'
                )
                : (
                    $officeName !== ''
                        ? "Syncing LMS employee snapshot for office: {$officeName}"
                        : 'Syncing full LMS employee snapshot from HRIS'
                    )
                )
        );
        if ($localOnly) {
            $this->line('HRIS access: disabled for this run');
        } else {
            $this->line("Using HR query timeout: {$queryTimeout} seconds");
        }

        try {
            $syncedRows = $localOnly
                ? HrisEmployee::refreshLocalSnapshot()
                : (
                    $repairMissing
                        ? HrisEmployee::repairSnapshotMissingEmploymentFields($officeName !== '' ? $officeName : null)
                        : HrisEmployee::syncSnapshot($officeName !== '' ? $officeName : null)
                );
        } catch (Throwable $exception) {
            report($exception);
            $this->error(
                (
                    $localOnly
                        ? 'Employee snapshot local refresh failed: '
                        : ($repairMissing ? 'Employee snapshot repair failed: ' : 'Employee snapshot sync failed: ')
                )
                .$exception->getMessage()
            );

            return self::FAILURE;
        }

        $this->info(
            $localOnly
                ? "Employee snapshot local refresh complete. {$syncedRows} row(s) refreshed."
                : (
                    $repairMissing
                        ? "Employee snapshot repair complete. {$syncedRows} row(s) repaired."
                        : "Employee snapshot sync complete. {$syncedRows} row(s) refreshed."
                )
        );

        return self::SUCCESS;
    }

    private function configureHrQueryTimeout(int $seconds): void
    {
        if ($seconds <= 0 || !defined('\PDO::SQLSRV_ATTR_QUERY_TIMEOUT')) {
            return;
        }

        $connection = config('database.connections.hr', []);
        $options = is_array($connection['options'] ?? null) ? $connection['options'] : [];
        $options[\PDO::SQLSRV_ATTR_QUERY_TIMEOUT] = $seconds;

        config([
            'database.connections.hr.options' => $options,
        ]);

        DB::purge('hr');
    }
}
