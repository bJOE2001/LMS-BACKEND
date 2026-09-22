<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRawLog;
use App\Models\HrisEmployee;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service to parse offline USB attendance logs (e.g. 1_attlog.dat or attlog.dat)
 * exported directly from ZKTeco MB360 devices.
 */
class UsbAttlogParserService
{
    /**
     * Parse and import attendance logs from an uploaded file or string content.
     *
     * @return array{total_lines: int, imported: int, duplicates_skipped: int, errors: int}
     */
    public function importFromContent(string $content, ?string $deviceSerialNumber = null): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($content));
        if ($lines === false || count($lines) === 0) {
            return [
                'total_lines' => 0,
                'imported' => 0,
                'duplicates_skipped' => 0,
                'errors' => 0,
            ];
        }

        $totalLines = 0;
        $imported = 0;
        $duplicates = 0;
        $errors = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $totalLines++;
            $parsed = $this->parseLine($line);

            if ($parsed === null) {
                $errors++;

                continue;
            }

            try {
                $pin = trim((string) $parsed['biometric_pin']);
                $emp = HrisEmployee::findByControlNo($pin, true);
                $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $pin;

                $exists = AttendanceRawLog::query()
                    ->where(function ($q) use ($parsed, $canonicalControlNo): void {
                        $q->where('biometric_pin', $parsed['biometric_pin'])
                            ->orWhere('employee_control_no', $canonicalControlNo);
                    })
                    ->where('punch_time', $parsed['punch_time'])
                    ->exists();

                if ($exists) {
                    $duplicates++;

                    continue;
                }

                AttendanceRawLog::query()->create([
                    'device_serial_number' => $deviceSerialNumber,
                    'biometric_pin' => $parsed['biometric_pin'],
                    'employee_control_no' => $canonicalControlNo,
                    'punch_time' => $parsed['punch_time'],
                    'punch_state' => $parsed['punch_state'],
                    'verify_type' => $parsed['verify_type'],
                    'work_code' => $parsed['work_code'],
                    'sync_source' => 'USB_IMPORT',
                    'raw_payload' => mb_substr($line, 0, 255),
                ]);

                $imported++;
                $affectedDates[$canonicalControlNo][mb_substr($parsed['punch_time'], 0, 10)] = true;
            } catch (Throwable $e) {
                $errors++;
                Log::warning('UsbAttlogParserService: Failed to save record', [
                    'line' => $line,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if (isset($affectedDates) && $affectedDates !== []) {
            $dtrService = app(DtrCalculationService::class);
            foreach ($affectedDates as $pin => $dates) {
                foreach (array_keys($dates) as $date) {
                    try {
                        $dtrService->calculateForEmployeeDate($pin, $date);
                    } catch (Throwable $e) {
                        Log::warning('UsbAttlogParserService: Failed to calculate DTR', [
                            'pin' => $pin,
                            'date' => $date,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return [
            'total_lines' => $totalLines,
            'imported' => $imported,
            'duplicates_skipped' => $duplicates,
            'errors' => $errors,
        ];
    }

    /**
     * Parse a single line from a ZKTeco attlog.dat file.
     *
     * Standard USB format:
     * PIN \t Timestamp \t DeviceID \t State \t VerifyType \t WorkCode
     * e.g. "1002\t2026-09-21 08:01:23\t1\t0\t15\t0"
     *
     * @return array{biometric_pin: string, punch_time: string, punch_state: int, verify_type: int, work_code: ?string}|null
     */
    public function parseLine(string $line): ?array
    {
        $parts = preg_split('/\t+|\s{2,}/', $line);
        if ($parts === false || count($parts) < 2) {
            // Regex fallback for space-padded lines: PIN YYYY-MM-DD HH:MM:SS
            if (preg_match('/^(\S+)\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})(?:\s+(\d+))?(?:\s+(\d+))?/', $line, $matches)) {
                return [
                    'biometric_pin' => trim($matches[1]),
                    'punch_time' => trim($matches[2]),
                    'punch_state' => isset($matches[3]) ? (int) $matches[3] : 0,
                    'verify_type' => isset($matches[4]) ? (int) $matches[4] : 15,
                    'work_code' => null,
                ];
            }

            return null;
        }

        $pin = trim($parts[0] ?? '');
        $timeRaw = trim($parts[1] ?? '');

        if ($pin === '' || $timeRaw === '') {
            return null;
        }

        try {
            $punchTime = Carbon::parse($timeRaw)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }

        // Column 2 may be DeviceID or PunchState depending on export options
        $punchState = 0;
        $verifyType = 15;
        $workCode = null;

        if (count($parts) >= 5) {
            // Format: PIN, Time, DeviceID, State, VerifyType
            $punchState = is_numeric(trim($parts[3])) ? (int) trim($parts[3]) : 0;
            $verifyType = is_numeric(trim($parts[4])) ? (int) trim($parts[4]) : 15;
            $workCode = isset($parts[5]) ? trim($parts[5]) : null;
        } elseif (count($parts) >= 3) {
            // Format: PIN, Time, State, VerifyType
            $punchState = is_numeric(trim($parts[2])) ? (int) trim($parts[2]) : 0;
            $verifyType = isset($parts[3]) && is_numeric(trim($parts[3])) ? (int) trim($parts[3]) : 15;
        }

        return [
            'biometric_pin' => $pin,
            'punch_time' => $punchTime,
            'punch_state' => $punchState,
            'verify_type' => $verifyType,
            'work_code' => $workCode !== '' ? $workCode : null,
        ];
    }
}
