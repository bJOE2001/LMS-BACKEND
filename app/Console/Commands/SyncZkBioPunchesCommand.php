<?php

namespace App\Console\Commands;

use App\Services\Attendance\ZkBioPunchSyncService;
use Illuminate\Console\Command;

class SyncZkBioPunchesCommand extends Command
{
    protected $signature = 'bio:sync-punches
                            {--install-trigger : Install the real-time SQL Server trigger on zkbiotime.dbo.iclock_transaction}
                            {--limit=1000 : Maximum number of transactions to pull}';

    protected $description = 'Synchronize punch logs from ZKBio Time (zkbiotime.dbo.iclock_transaction) into LMS BIO_DB';

    public function handle(ZkBioPunchSyncService $service): int
    {
        if ($this->option('install-trigger')) {
            $this->info('Installing real-time SQL Server trigger on zkbiotime.dbo.iclock_transaction...');
            $success = $service->installTrigger();
            if ($success) {
                $this->info('Trigger trg_zkbio_sync_to_lms installed successfully!');
            } else {
                $this->error('Failed to install SQL trigger. Check application logs.');
            }
        }

        $limit = (int) $this->option('limit');
        $this->info("Synchronizing punches from ZKBio Time (limit: {$limit})...");

        $synced = $service->syncPunches($limit);
        $this->info("Successfully synchronized {$synced} punch(es) from ZKBio Time into LMS and updated DTR records.");

        return self::SUCCESS;
    }
}
