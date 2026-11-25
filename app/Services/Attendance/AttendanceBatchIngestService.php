<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceRawLog;
use App\Models\HrisEmployee;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bulk-ingests raw biometric punch records with chunked HRIS resolution,
 * bounded duplicate preloading, and chunked SQL inserts to stay well below
 * SQL Server's 2,100-parameter limit without requiring a queue worker.
 */
class AttendanceBatchIngestService
{
    /** Max HRIS control numbers resolved per IN-clause chunk. */
    private const HRIS_CHUNK_SIZE = 400;

    /** Max identifiers per duplicate-check chunk (2x IN clauses + 2 bounds << 2,100 params). */
    private const DUPLICATE_LOOKUP_CHUNK_SIZE = 200;

    /** Max rows per AttendanceRawLog bulk insert (11 columns * 150 = 1,650 < 2,100 params). */
    private const INSERT_CHUNK_SIZE = 150;

    /**
     * Load HRIS employees in chunks and index them by raw, normalized, and 6-digit padded control numbers.
     *
     * @param  array<int, string>  $controlNos
     * @return array<string, object>
     */
    public function loadEmployeeDirectory(array $controlNos, bool $activeOnly = true): array
    {
        $uniqueControlNos = [];

        foreach ($controlNos as $raw) {
            $trimmed = trim((string) $raw);
            if ($trimmed === '') {
                continue;
            }

            $uniqueControlNos[$trimmed] = true;
        }

        if ($uniqueControlNos === []) {
            return [];
        }

        $indexed = [];

        foreach (array_chunk(array_keys($uniqueControlNos), self::HRIS_CHUNK_SIZE) as $chunk) {
            $directory = HrisEmployee::directoryByControlNos($chunk, $activeOnly);

            foreach ($directory as $dirKey => $employee) {
                $dirKeyStr = trim((string) $dirKey);
                if ($dirKeyStr !== '') {
                    $indexed[$dirKeyStr] = $employee;
                }

                $canonical = trim((string) ($employee->control_no ?? ''));
                if ($canonical !== '') {
                    $indexed[$canonical] = $employee;
                    $unpaddedCanonical = ltrim($canonical, '0');
                    if ($unpaddedCanonical !== '') {
                        $indexed[$unpaddedCanonical] = $employee;
                        $indexed[str_pad($unpaddedCanonical, 6, '0', STR_PAD_LEFT)] = $employee;
                    }
                }
            }
        }

        return $indexed;
    }

    /**
     * Resolve a single employee from a directory map built by loadEmployeeDirectory().
     *
     * @param  array<string, object>  $directory
     */
    public function resolveFromDirectory(array $directory, string $controlNo): ?object
    {
        $trimmed = trim($controlNo);
        if ($trimmed === '') {
            return null;
        }

        if (isset($directory[$trimmed])) {
            return $directory[$trimmed];
        }

        $unpadded = ltrim($trimmed, '0');
        if ($unpadded !== '' && isset($directory[$unpadded])) {
            return $directory[$unpadded];
        }

        if ($unpadded !== '') {
            $padded = str_pad($unpadded, 6, '0', STR_PAD_LEFT);
            if (isset($directory[$padded])) {
                return $directory[$padded];
            }
        }

        return null;
    }

    /**
     * Batch-ingest raw punch candidates into tblAttendanceLogs.
     *
     * @param  array<int, array{
     *     pin:string,
     *     punch_time:string,
     *     punch_state?:int,
     *     verify_type?:int,
     *     raw_payload?:?string
     * }>  $candidates
     * @return array{
     *     imported:int,
     *     duplicates:int,
     *     affected_dates:array<string, array<string, bool>>,
     *     employees:array<string, object>,
     *     imported_control_nos:array<int, string>
     * }
     */
    public function ingestBatch(array $candidates, ?string $deviceSerialNumber, string $syncSource): array
    {
        if ($candidates === []) {
            return [
                'imported' => 0,
                'duplicates' => 0,
                'affected_dates' => [],
                'employees' => [],
                'imported_control_nos' => [],
            ];
        }

        $normalizedCandidates = [];
        $uniquePins = [];

        foreach ($candidates as $candidate) {
            $pin = trim((string) ($candidate['pin'] ?? ''));
            $punchTime = substr(trim((string) ($candidate['punch_time'] ?? '')), 0, 19);

            if ($pin === '' || $punchTime === '') {
                continue;
            }

            $uniquePins[$pin] = true;
            $normalizedCandidates[] = [
                'pin' => $pin,
                'punch_time' => $punchTime,
                'punch_state' => (int) ($candidate['punch_state'] ?? 0),
                'verify_type' => (int) ($candidate['verify_type'] ?? 1),
                'raw_payload' => $candidate['raw_payload'] ?? null,
            ];
        }

        if ($normalizedCandidates === []) {
            return [
                'imported' => 0,
                'duplicates' => 0,
                'affected_dates' => [],
                'employees' => [],
                'imported_control_nos' => [],
            ];
        }

        $employees = $this->loadEmployeeDirectory(array_keys($uniquePins), true);

        $lookupIdentifiers = [];
        $minPunchTime = null;
        $maxPunchTime = null;

        foreach ($normalizedCandidates as $idx => $item) {
            $emp = $this->resolveFromDirectory($employees, $item['pin']);
            $canonicalControlNo = $emp?->control_no ? trim((string) $emp->control_no) : $item['pin'];

            $normalizedCandidates[$idx]['canonical_control_no'] = $canonicalControlNo;
            $lookupIdentifiers[$item['pin']] = true;
            $lookupIdentifiers[$canonicalControlNo] = true;

            $time = $item['punch_time'];
            if ($minPunchTime === null || $time < $minPunchTime) {
                $minPunchTime = $time;
            }
            if ($maxPunchTime === null || $time > $maxPunchTime) {
                $maxPunchTime = $time;
            }
        }

        $existingKeys = $this->loadExistingPunchKeys(
            array_keys($lookupIdentifiers),
            (string) $minPunchTime,
            (string) $maxPunchTime
        );

        $now = now()->format('Y-m-d H:i:s');
        $seenInBatch = [];
        $pendingItems = [];
        $duplicates = 0;

        foreach ($normalizedCandidates as $item) {
            $pin = $item['pin'];
            $canonicalControlNo = $item['canonical_control_no'];
            $punchTime = $item['punch_time'];

            $pinKey = "{$pin}|{$punchTime}";
            $canonicalKey = "{$canonicalControlNo}|{$punchTime}";

            if (
                isset($existingKeys[$pinKey])
                || isset($existingKeys[$canonicalKey])
                || isset($seenInBatch[$pinKey])
                || isset($seenInBatch[$canonicalKey])
            ) {
                $duplicates++;

                continue;
            }

            $seenInBatch[$pinKey] = true;
            $seenInBatch[$canonicalKey] = true;

            $pendingItems[] = [
                'canonical_control_no' => $canonicalControlNo,
                'date' => substr($punchTime, 0, 10),
                'row' => [
                    'device_serial_number' => $deviceSerialNumber,
                    'biometric_pin' => $pin,
                    'employee_control_no' => $canonicalControlNo,
                    'punch_time' => $punchTime,
                    'punch_state' => $item['punch_state'],
                    'verify_type' => $item['verify_type'],
                    'sync_source' => $syncSource,
                    'raw_payload' => $item['raw_payload'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            ];
        }

        $imported = 0;
        $affectedDates = [];
        $importedControlNos = [];

        foreach (array_chunk($pendingItems, self::INSERT_CHUNK_SIZE) as $chunk) {
            $rows = array_column($chunk, 'row');

            try {
                AttendanceRawLog::query()->insert($rows);

                foreach ($chunk as $entry) {
                    $imported++;
                    $ctrl = $entry['canonical_control_no'];
                    $affectedDates[$ctrl][$entry['date']] = true;
                    $importedControlNos[$ctrl] = true;
                }
            } catch (Throwable $chunkError) {
                // Concurrent ADMS push or LAN poll may race on UQ_tblAttendanceLogs_pin_punch_time;
                // fall back to per-row insert only for the conflicting chunk.
                foreach ($chunk as $entry) {
                    try {
                        AttendanceRawLog::query()->insert([$entry['row']]);
                        $imported++;
                        $ctrl = $entry['canonical_control_no'];
                        $affectedDates[$ctrl][$entry['date']] = true;
                        $importedControlNos[$ctrl] = true;
                    } catch (Throwable $rowError) {
                        if ($this->isUniqueConstraintViolation($rowError)) {
                            $duplicates++;
                        } else {
                            Log::warning('AttendanceBatchIngestService: Row insert failed', [
                                'sync_source' => $syncSource,
                                'biometric_pin' => $entry['row']['biometric_pin'],
                                'punch_time' => $entry['row']['punch_time'],
                                'error' => $rowError->getMessage(),
                                'chunk_error' => $chunkError->getMessage(),
                            ]);
                        }
                    }
                }
            }
        }

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'affected_dates' => $affectedDates,
            'employees' => $employees,
            'imported_control_nos' => array_keys($importedControlNos),
        ];
    }

    /**
     * Load existing (pin|punch_time) and (control_no|punch_time) pairs in chunks.
     *
     * @param  array<int, string>  $identifiers
     * @return array<string, bool>
     */
    private function loadExistingPunchKeys(array $identifiers, string $minPunchTime, string $maxPunchTime): array
    {
        if ($identifiers === []) {
            return [];
        }

        $existingKeys = [];

        foreach (array_chunk($identifiers, self::DUPLICATE_LOOKUP_CHUNK_SIZE) as $chunk) {
            $rows = AttendanceRawLog::query()
                ->select(['biometric_pin', 'employee_control_no', 'punch_time'])
                ->whereBetween('punch_time', [$minPunchTime, $maxPunchTime])
                ->where(function ($q) use ($chunk): void {
                    $q->whereIn('biometric_pin', $chunk)
                        ->orWhereIn('employee_control_no', $chunk);
                })
                ->get();

            foreach ($rows as $row) {
                $timeStr = $row->punch_time instanceof DateTimeInterface
                    ? $row->punch_time->format('Y-m-d H:i:s')
                    : substr(trim((string) $row->punch_time), 0, 19);

                $pin = trim((string) ($row->biometric_pin ?? ''));
                if ($pin !== '') {
                    $existingKeys["{$pin}|{$timeStr}"] = true;
                }

                $ctrl = trim((string) ($row->employee_control_no ?? ''));
                if ($ctrl !== '') {
                    $existingKeys["{$ctrl}|{$timeStr}"] = true;
                }
            }
        }

        return $existingKeys;
    }

    private function isUniqueConstraintViolation(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, '2627')
            || str_contains($message, '2601')
            || str_contains($message, '23000')
            || stripos($message, 'UNIQUE') !== false
            || stripos($message, 'duplicate') !== false;
    }
}
