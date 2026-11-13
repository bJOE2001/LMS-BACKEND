<?php

namespace App\Console\Commands;

use App\Models\Attendance\BiometricDevice;
use App\Services\Attendance\LanBioPunchSyncService;
use Illuminate\Console\Command;

class PollLanBiometricCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'bio:poll-lan
                            {--device= : Specific biometric device ID or serial number to poll}';

    /**
     * Aliases for easy recall.
     *
     * @var list<string>
     */
    protected $aliases = [
        'bio:sync-lan',
        'attendance:poll-lan',
    ];

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Poll attendance punches from non-ADMS biometric terminals over the local network (LAN)';

    /**
     * Execute the console command.
     */
    public function handle(LanBioPunchSyncService $service): int
    {
        $deviceIdentifier = $this->option('device');

        if ($deviceIdentifier) {
            $device = BiometricDevice::query()
                ->where('id', $deviceIdentifier)
                ->orWhere('serial_number', $deviceIdentifier)
                ->first();

            if (! $device) {
                $this->error("Biometric device '{$deviceIdentifier}' not found.");

                return self::FAILURE;
            }

            $this->info("Polling biometric terminal '{$device->device_name}' ({$device->ip_address}:{$device->port})...");
            $result = $service->syncDevice($device);

            if ($result['success']) {
                $this->info($result['message']);

                return self::SUCCESS;
            }

            $this->error($result['message']);

            return self::FAILURE;
        }

        $this->info('Polling all active LAN biometric terminals...');
        $summary = $service->syncAllLanDevices();

        $this->table(
            ['Device', 'Status', 'Imported', 'Message'],
            collect($summary['results'])->map(static fn (array $r): array => [
                $r['device'],
                $r['success'] ? 'SUCCESS' : 'FAILED',
                $r['imported'],
                $r['message'],
            ])
        );

        $this->info("Completed. {$summary['successful_devices']} of {$summary['total_devices']} devices polled successfully. Total imported: {$summary['total_imported']} punch(es).");

        return self::SUCCESS;
    }
}
