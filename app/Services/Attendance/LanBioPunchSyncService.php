<?php

namespace App\Services\Attendance;

use App\Models\Attendance\BiometricDevice;
use App\Models\Attendance\BiometricEnrollment;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service to synchronize biometric punch transactions over the City Hall local network (LAN)
 * from non-ADMS ZKTeco terminals into LMS BIO_DB (tblAttendanceLogs) and recalculate DTRs.
 */
class LanBioPunchSyncService
{
    public function __construct(
        private readonly DtrCalculationService $dtrService,
        private readonly ?LanBioTemplateSyncService $templateSyncService = null,
        private readonly ?AttendanceBatchIngestService $batchIngestService = null
    ) {}

    private function batchIngest(): AttendanceBatchIngestService
    {
        return $this->batchIngestService ?? app(AttendanceBatchIngestService::class);
    }

    /**
     * Poll a specific biometric device by ID or model instance.
     *
     * @return array{
     *     success: bool,
     *     message: string,
     *     device: string,
     *     imported: int,
     *     duplicates: int,
     *     total_read: int
     * }
     */
    public function syncDevice(BiometricDevice $device, ?ZkSocketClient $client = null, bool $syncTemplates = false): array
    {
        $deviceName = $device->device_name ?: "Terminal {$device->serial_number}";

        if (! $device->ip_address) {
            return [
                'success' => false,
                'message' => "Device '{$deviceName}' has no IP address configured.",
                'device' => $deviceName,
                'imported' => 0,
                'duplicates' => 0,
                'total_read' => 0,
            ];
        }

        // Hardware lock: prevent multiple parallel socket connections to the same physical terminal
        $lockKey = "zk_lan_poll_lock_{$device->id}";
        $lock = Cache::lock($lockKey, 30);

        if (! $lock->get()) {
            return [
                'success' => false,
                'message' => "Device '{$deviceName}' is currently busy with another sync operation.",
                'device' => $deviceName,
                'imported' => 0,
                'duplicates' => 0,
                'total_read' => 0,
            ];
        }

        try {
            $zk = $client ?? new ZkSocketClient(
                ip: $device->ip_address,
                port: $device->port ?: 4370,
                timeout: 3.5,
                commKey: (int) ($device->comm_key ?? 0)
            );

            if (! $zk->connect()) {
                $device->update([
                    'status' => BiometricDevice::STATUS_OFFLINE,
                ]);

                return [
                    'success' => false,
                    'message' => "Connection to {$deviceName} at {$device->ip_address}:{$device->port} failed (Terminal offline or unreachable).",
                    'device' => $deviceName,
                    'imported' => 0,
                    'duplicates' => 0,
                    'total_read' => 0,
                ];
            }

            try {
                $rawLogs = $zk->getAttendanceLogs();

                // Harvest missing fingerprint/face templates only when explicitly requested (manual UI sync),
                // reusing the open terminal socket instead of connecting a second time every minute.
                if ($client === null && $syncTemplates && $device->communication_mode === 'LAN_POLL') {
                    try {
                        ($this->templateSyncService ?? new LanBioTemplateSyncService)->pullTemplatesFromDevice($device, null, $zk);
                    } catch (Throwable $tplError) {
                        Log::warning('LanBioPunchSyncService: Non-critical template harvest skipped', [
                            'device' => $device->serial_number,
                            'error' => $tplError->getMessage(),
                        ]);
                    }
                }
            } finally {
                $zk->disconnect();
            }

            // Mark device online and timestamp heartbeat & sync
            $device->update([
                'status' => BiometricDevice::STATUS_ONLINE,
                'last_heartbeat_at' => now(),
                'last_sync_at' => now(),
                'device_log_count' => count($rawLogs),
            ]);

            if (empty($rawLogs)) {
                return [
                    'success' => true,
                    'message' => "Device '{$deviceName}' connected successfully. No new attendance logs found.",
                    'device' => $deviceName,
                    'imported' => 0,
                    'duplicates' => 0,
                    'total_read' => 0,
                ];
            }

            $candidates = [];

            foreach ($rawLogs as $log) {
                $pinClean = trim(preg_replace('/[^\x20-\x7E]/', '', (string) ($log['pin'] ?? '')));
                $pin = mb_convert_encoding($pinClean, 'UTF-8', 'UTF-8');
                $punchTime = trim((string) ($log['time'] ?? ''));

                if ($pin === '' || $punchTime === '') {
                    continue;
                }

                $workCode = null;
                if (! empty($log['work_code'])) {
                    $workClean = trim(preg_replace('/[^\x20-\x7E]/', '', (string) $log['work_code']));
                    $workCode = $workClean !== '' ? mb_convert_encoding($workClean, 'UTF-8', 'UTF-8') : null;
                }

                $candidates[] = [
                    'pin' => $pin,
                    'punch_time' => substr($punchTime, 0, 19),
                    'punch_state' => (int) ($log['state'] ?? 0),
                    'verify_type' => (int) ($log['type'] ?? 1),
                    'work_code' => $workCode,
                    'raw_payload' => "IP={$device->ip_address};SN={$device->serial_number}",
                ];
            }

            $ingestResult = $this->batchIngest()->ingestBatch($candidates, $device->serial_number, 'LAN_POLL');
            $imported = $ingestResult['imported'];
            $duplicates = $ingestResult['duplicates'];

            // Batch-confirm pending biometric enrollments for newly imported control numbers
            $this->confirmPendingEnrollments($ingestResult['imported_control_nos'], $device->serial_number);

            // Recalculate DTR in batch for all affected employees and dates
            $this->dtrService->calculateBatchForDates(
                $ingestResult['affected_dates'],
                $ingestResult['employees']
            );

            if ($imported > 0) {
                $logWord = $imported === 1 ? 'log' : 'logs';
                $msg = "Successfully synced {$imported} new attendance {$logWord}.";
            } else {
                $msg = 'Attendance logs are up to date ('.count($rawLogs).' logs checked).';
            }
            Log::info("LanBioPunchSyncService: Device '{$deviceName}' - {$msg}");

            return [
                'success' => true,
                'message' => $msg,
                'device' => $deviceName,
                'imported' => $imported,
                'duplicates' => $duplicates,
                'total_read' => count($rawLogs),
            ];
        } catch (Throwable $e) {
            Log::error('LanBioPunchSyncService: Exception during sync', [
                'device' => $deviceName,
                'ip' => $device->ip_address,
                'error' => $e->getMessage(),
            ]);

            $cleanError = mb_convert_encoding($e->getMessage(), 'UTF-8', 'UTF-8');
            $cleanError = preg_replace('/[^\x20-\x7E]/', ' ', $cleanError) ?? $cleanError;

            return [
                'success' => false,
                'message' => "Sync error on {$deviceName}: {$cleanError}",
                'device' => $deviceName,
                'imported' => 0,
                'duplicates' => 0,
                'total_read' => 0,
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * Poll all active City Hall devices configured for LAN_POLL mode.
     *
     * @return array{
     *     total_devices: int,
     *     successful_devices: int,
     *     failed_devices: int,
     *     total_imported: int,
     *     results: array<int, array{device: string, success: bool, message: string, imported: int}>
     * }
     */
    public function syncAllLanDevices(): array
    {
        $devices = BiometricDevice::query()
            ->where('communication_mode', 'LAN_POLL')
            ->where('is_active', true)
            ->whereNotNull('ip_address')
            ->get();

        $successful = 0;
        $failed = 0;
        $totalImported = 0;
        $results = [];

        foreach ($devices as $device) {
            $result = $this->syncDevice($device);

            if ($result['success']) {
                $successful++;
                $totalImported += $result['imported'];
            } else {
                $failed++;
            }

            $results[] = [
                'device' => $result['device'],
                'success' => $result['success'],
                'message' => $result['message'],
                'imported' => $result['imported'],
            ];
        }

        return [
            'total_devices' => $devices->count(),
            'successful_devices' => $successful,
            'failed_devices' => $failed,
            'total_imported' => $totalImported,
            'results' => $results,
        ];
    }

    /**
     * Auto-confirm pending biometric enrollments in batch for newly synced employees.
     *
     * @param  array<int, string>  $controlNos
     */
    private function confirmPendingEnrollments(array $controlNos, string $deviceSerialNumber): void
    {
        if ($controlNos === []) {
            return;
        }

        $now = now();

        foreach (array_chunk($controlNos, 400) as $chunk) {
            $pendingEnrollments = BiometricEnrollment::query()
                ->whereIn('employee_control_no', $chunk)
                ->where('status', BiometricEnrollment::STATUS_PENDING_ENROLLMENT)
                ->get();

            foreach ($pendingEnrollments as $enrollment) {
                $enrollment->status = BiometricEnrollment::STATUS_REGISTERED;
                $enrollment->enrolled_at = $enrollment->enrolled_at ?? $now;
                $enrollment->enrollment_device_sn = $enrollment->enrollment_device_sn ?: $deviceSerialNumber;
                $synced = is_array($enrollment->synced_devices) ? $enrollment->synced_devices : [];
                if (! in_array($deviceSerialNumber, $synced, true)) {
                    $synced[] = $deviceSerialNumber;
                    $enrollment->synced_devices = $synced;
                }
                $enrollment->save();
            }
        }
    }
}
