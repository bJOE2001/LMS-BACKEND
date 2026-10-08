<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRawLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service to synchronize biometric punch transactions in real time
 * between ZKBio Time (zkbiotime.dbo.iclock_transaction) and LMS (BIO_DB.dbo.tblAttendanceRawLogs).
 */
class ZkBioPunchSyncService
{
    public function __construct(
        private readonly DtrCalculationService $dtrService
    ) {}

    /**
     * Incrementally sync new punches from ZKBio Time into LMS BIO_DB
     * and trigger DTR calculation for all affected employee dates.
     *
     * @return int Number of newly imported punches.
     */
    public function syncPunches(?int $limit = 1000): int
    {
        try {
            $query = DB::connection('zkbio')->table('iclock_transaction as t')
                ->select([
                    't.id',
                    't.emp_code',
                    't.punch_time',
                    't.punch_state',
                    't.verify_type',
                    't.work_code',
                    't.terminal_sn',
                ])
                ->orderBy('t.id', 'asc');

            if ($limit !== null) {
                $query->limit($limit);
            }

            $transactions = $query->get();
            if ($transactions->isEmpty()) {
                return 0;
            }

            $importedCount = 0;
            $affectedDates = [];

            foreach ($transactions as $tx) {
                $pin = trim((string) $tx->emp_code);
                $punchTime = trim((string) $tx->punch_time);

                if ($pin === '' || $punchTime === '') {
                    continue;
                }

                $punchDate = substr($punchTime, 0, 10);
                $deviceSn = trim((string) ($tx->terminal_sn ?? ''));
                $punchState = is_numeric($tx->punch_state) ? (int) $tx->punch_state : 0;
                $verifyType = is_numeric($tx->verify_type) ? (int) $tx->verify_type : 15;
                $workCode = $tx->work_code ? trim((string) $tx->work_code) : null;

                // Check if already in tblAttendanceRawLogs
                $exists = AttendanceRawLog::query()
                    ->where('biometric_pin', $pin)
                    ->where('punch_time', $punchTime)
                    ->exists();

                if (! $exists) {
                    AttendanceRawLog::query()->create([
                        'device_serial_number' => $deviceSn ?: null,
                        'biometric_pin' => $pin,
                        'employee_control_no' => $pin,
                        'punch_time' => $punchTime,
                        'punch_state' => $punchState,
                        'verify_type' => $verifyType,
                        'work_code' => $workCode,
                        'sync_source' => 'ZKBIO_REALTIME',
                        'raw_payload' => "ID={$tx->id};SN={$deviceSn}",
                    ]);

                    $importedCount++;
                    $affectedDates[$pin][$punchDate] = true;
                }
            }

            // Recalculate DTR for all affected employees and dates
            foreach ($affectedDates as $pin => $dates) {
                foreach (array_keys($dates) as $date) {
                    try {
                        $this->dtrService->calculateForEmployeeDate($pin, $date);
                    } catch (Throwable $e) {
                        Log::warning('ZkBioPunchSyncService: Failed to recalculate DTR', [
                            'pin' => $pin,
                            'date' => $date,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            if ($importedCount > 0) {
                Log::info("ZkBioPunchSyncService: Synchronized {$importedCount} punch(es) from ZKBio Time to LMS.");
            }

            return $importedCount;
        } catch (Throwable $e) {
            Log::error('ZkBioPunchSyncService: Punch sync error', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Install SQL Server Trigger on zkbiotime.dbo.iclock_transaction
     * to replicate punches immediately upon insert.
     */
    public function installTrigger(): bool
    {
        try {
            DB::connection('zkbio')->statement("
                CREATE OR ALTER TRIGGER trg_zkbio_sync_to_lms
                ON dbo.iclock_transaction
                AFTER INSERT
                AS
                BEGIN
                    SET NOCOUNT ON;

                    INSERT INTO BIO_DB.dbo.tblAttendanceRawLogs (
                        device_serial_number,
                        biometric_pin,
                        employee_control_no,
                        punch_time,
                        punch_state,
                        verify_type,
                        work_code,
                        sync_source,
                        raw_payload,
                        created_at,
                        updated_at
                    )
                    SELECT 
                        i.terminal_sn,
                        i.emp_code,
                        i.emp_code,
                        i.punch_time,
                        CASE WHEN ISNUMERIC(i.punch_state) = 1 THEN CAST(i.punch_state AS smallint) ELSE 0 END,
                        CASE WHEN ISNUMERIC(i.verify_type) = 1 THEN CAST(i.verify_type AS smallint) ELSE 15 END,
                        i.work_code,
                        'ZKBIO_TRIGGER',
                        CONCAT('ID=', i.id, ';SN=', i.terminal_sn),
                        GETDATE(),
                        GETDATE()
                    FROM inserted i
                    WHERE NOT EXISTS (
                        SELECT 1 FROM BIO_DB.dbo.tblAttendanceRawLogs r
                        WHERE r.biometric_pin = i.emp_code AND r.punch_time = i.punch_time
                    );
                END;
            ");

            Log::info('ZkBioPunchSyncService: Installed SQL Server trigger trg_zkbio_sync_to_lms.');

            return true;
        } catch (Throwable $e) {
            Log::warning('ZkBioPunchSyncService: Failed to create SQL trigger', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
