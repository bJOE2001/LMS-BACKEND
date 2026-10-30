<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRawLog;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service to synchronize biometric punch transactions in real time
 * between ZKBio Time (zkbiotime.dbo.iclock_transaction) and LMS (BIO_DB.dbo.tblAttendanceLogs).
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
    public function syncPunches(?int $limit = 1000, bool $force = false): int
    {
        if (! $force && Cache::has('zkbio_sync_cooldown')) {
            return 0;
        }

        // 30s cooldown prevents rapid subsequent GET requests from hammering ZKBio
        Cache::put('zkbio_sync_cooldown', true, 30);

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

            $pins = $transactions->pluck('emp_code')->map(static fn ($p): string => trim((string) $p))->filter()->unique()->values()->all();
            $punchTimes = $transactions->pluck('punch_time')->map(static fn ($t): string => substr(trim((string) $t), 0, 19))->filter()->unique()->values()->all();

            $existingLogs = AttendanceRawLog::query()
                ->whereIn('biometric_pin', $pins)
                ->whereIn('punch_time', $punchTimes)
                ->get(['biometric_pin', 'punch_time'])
                ->map(static fn ($r): string => $r->biometric_pin.'_'.substr((string) $r->punch_time, 0, 19))
                ->flip()
                ->all();

            $importedCount = 0;
            $affectedDates = [];

            foreach ($transactions as $tx) {
                $pin = trim((string) $tx->emp_code);
                $punchTime = trim((string) $tx->punch_time);

                if ($pin === '' || $punchTime === '') {
                    continue;
                }

                $punchTimeStandard = substr($punchTime, 0, 19);
                $lookupKey = $pin.'_'.$punchTimeStandard;

                if (isset($existingLogs[$lookupKey])) {
                    continue;
                }

                $punchDate = substr($punchTimeStandard, 0, 10);
                $deviceSn = trim((string) ($tx->terminal_sn ?? ''));
                $punchState = is_numeric($tx->punch_state) ? (int) $tx->punch_state : 0;
                $verifyType = is_numeric($tx->verify_type) ? (int) $tx->verify_type : 15;
                $workCode = $tx->work_code ? trim((string) $tx->work_code) : null;

                AttendanceRawLog::query()->create([
                    'device_serial_number' => $deviceSn ?: null,
                    'biometric_pin' => $pin,
                    'employee_control_no' => $pin,
                    'punch_time' => $punchTimeStandard,
                    'punch_state' => $punchState,
                    'verify_type' => $verifyType,
                    'work_code' => $workCode,
                    'sync_source' => 'ZKBIO_SYNC',
                    'raw_payload' => "ID={$tx->id};SN={$deviceSn}",
                ]);

                $importedCount++;
                $affectedDates[$pin][$punchDate] = true;
                $existingLogs[$lookupKey] = true;
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

                    INSERT INTO BIO_DB.dbo.tblAttendanceLogs (
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
                        SELECT 1 FROM BIO_DB.dbo.tblAttendanceLogs r
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
