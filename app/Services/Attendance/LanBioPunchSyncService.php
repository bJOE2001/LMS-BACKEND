<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRawLog;
use App\Models\BiometricDevice;
use App\Models\HrisEmployee;
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
        private readonly DtrCalculationService $dtrService
    ) {}

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
    public function syncDevice(BiometricDevice $device, ?ZkSocketClient $client = null): array
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

            $rawLogs = $zk->getAttendanceLogs();
            $zk->disconnect();

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

            $imported = 0;
            $duplicates = 0;
            $affectedDates = [];

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

                // Match canonical employee control number if available
                $emp = HrisEmployee::findByControlNo($pin, true);
                $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $pin;

                $punchTimeStandard = substr($punchTime, 0, 19);

                $exists = AttendanceRawLog::query()
                    ->where(function ($q) use ($pin, $canonicalControlNo): void {
                        $q->where('biometric_pin', $pin)
                            ->orWhere('employee_control_no', $canonicalControlNo);
                    })
                    ->where('punch_time', $punchTimeStandard)
                    ->exists();

                if ($exists) {
                    $duplicates++;

                    continue;
                }

                AttendanceRawLog::query()->create([
                    'device_serial_number' => $device->serial_number,
                    'biometric_pin' => $pin,
                    'employee_control_no' => $canonicalControlNo,
                    'punch_time' => $punchTimeStandard,
                    'punch_state' => $log['state'] ?? 0,
                    'verify_type' => $log['type'] ?? 1,
                    'work_code' => $workCode,
                    'sync_source' => 'LAN_POLL',
                    'raw_payload' => "IP={$device->ip_address};SN={$device->serial_number}",
                ]);

                $imported++;
                $affectedDates[$canonicalControlNo][substr($punchTimeStandard, 0, 10)] = true;
            }

            // Recalculate DTR for all affected employees and dates
            foreach ($affectedDates as $controlNo => $dates) {
                foreach (array_keys($dates) as $date) {
                    try {
                        $this->dtrService->calculateForEmployeeDate($controlNo, $date);
                    } catch (Throwable $e) {
                        Log::warning('LanBioPunchSyncService: Failed to recalculate DTR', [
                            'control_no' => $controlNo,
                            'date' => $date,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

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
}
