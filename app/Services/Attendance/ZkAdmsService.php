<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRawLog;
use App\Models\BiometricDevice;
use App\Models\BiometricDeviceCommand;
use App\Models\BiometricEnrollment;
use App\Models\HrisEmployee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Service to handle ZKTeco ADMS (Push Protocol / iClock protocol) communication.
 * Communicates directly with MB360 firmware over HTTP.
 */
class ZkAdmsService
{
    /**
     * Handle device handshake / configuration query (GET /iclock/cdata?options=all).
     */
    public function handleHandshake(Request $request): string
    {
        $serialNumber = trim((string) $request->query('SN', $request->query('sn', '')));
        if ($serialNumber === '') {
            return "ERROR: Missing SN\n";
        }

        $this->registerOrUpdateDevice($serialNumber, $request);

        // Standard ZKTeco ADMS configuration response
        return "GET OPTION FROM: {$serialNumber}\n"
            ."Stamp=9999\n"
            ."OpStamp=9999\n"
            ."ErrorDelay=30\n"
            ."Delay=10\n"
            ."TransTimes=00:00;14:05\n"
            ."TransInterval=1\n"
            ."TransFlag=1111000000\n"
            ."Realtime=1\n"
            ."Encrypt=0\n"
            ."PushOptionsFlag=1\n";
    }

    /**
     * Handle incoming punch push from device (POST /iclock/cdata?table=ATTLOG).
     *
     * @return array{response: string, count: int}
     */
    public function handleAttendancePush(Request $request): array
    {
        $serialNumber = trim((string) $request->query('SN', $request->query('sn', '')));
        $body = (string) $request->getContent();

        if ($serialNumber !== '') {
            $this->registerOrUpdateDevice($serialNumber, $request, true);
        }

        if (trim($body) === '') {
            return ['response' => "OK\n", 'count' => 0];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        if ($lines === false || count($lines) === 0) {
            return ['response' => "OK\n", 'count' => 0];
        }

        $importedCount = 0;
        $affectedDates = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parsed = $this->parseAttlogLine($line);
            if ($parsed === null) {
                continue;
            }

            // 1. Anti-Spoofing Timestamp Sanity Check
            try {
                $punchTimeCarbon = Carbon::parse($parsed['punch_time']);
                // Reject future punches (beyond 5 minutes clock skew)
                if ($punchTimeCarbon->greaterThan(now()->addMinutes(5))) {
                    Log::warning("ZkAdmsSecurity: Discarded future punch timestamp [{$parsed['punch_time']}] for PIN [{$parsed['biometric_pin']}] from device [{$serialNumber}]");

                    continue;
                }
                // Reject stale punches older than 90 days
                if ($punchTimeCarbon->lessThan(now()->subDays(90))) {
                    Log::warning("ZkAdmsSecurity: Discarded stale punch timestamp [{$parsed['punch_time']}] for PIN [{$parsed['biometric_pin']}] from device [{$serialNumber}]");

                    continue;
                }
            } catch (Throwable) {
                continue;
            }

            // 2. Employee Control Number / PIN Validation
            $pin = trim((string) $parsed['biometric_pin']);
            if ($pin === '') {
                continue;
            }

            // Resolve canonical control number from HRIS (e.g. PIN '11790' -> canonical '011790')
            $emp = HrisEmployee::findByControlNo($pin, true);
            $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $pin;

            $isValidEmployee = $emp !== null
                || BiometricEnrollment::query()->whereIn('employee_control_no', [$pin, $canonicalControlNo, ltrim($pin, '0')])->exists();

            if (! $isValidEmployee) {
                Log::warning("ZkAdmsSecurity: Discarded punch for unverified employee PIN [{$pin}] from device [{$serialNumber}]");

                continue;
            }

            try {
                // Deduplication via unique index [biometric_pin, punch_time]
                $existing = AttendanceRawLog::query()
                    ->where(function ($q) use ($parsed, $canonicalControlNo): void {
                        $q->where('biometric_pin', $parsed['biometric_pin'])
                            ->orWhere('employee_control_no', $canonicalControlNo);
                    })
                    ->where('punch_time', $parsed['punch_time'])
                    ->exists();

                if (! $existing) {
                    AttendanceRawLog::query()->create([
                        'device_serial_number' => $serialNumber ?: null,
                        'biometric_pin' => $parsed['biometric_pin'],
                        'employee_control_no' => $canonicalControlNo,
                        'punch_time' => $parsed['punch_time'],
                        'punch_state' => $parsed['punch_state'],
                        'verify_type' => $parsed['verify_type'],
                        'work_code' => $parsed['work_code'],
                        'sync_source' => 'ADMS',
                        'raw_payload' => mb_substr($line, 0, 255),
                    ]);

                    $importedCount++;
                    $affectedDates[$canonicalControlNo][mb_substr($parsed['punch_time'], 0, 10)] = true;
                }
            } catch (Throwable $e) {
                Log::warning('ZkAdmsService: Failed to save punch line', [
                    'line' => $line,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($affectedDates !== []) {
            $dtrService = app(DtrCalculationService::class);
            foreach ($affectedDates as $pin => $dates) {
                foreach (array_keys($dates) as $date) {
                    try {
                        $dtrService->calculateForEmployeeDate($pin, $date);
                    } catch (Throwable $e) {
                        Log::warning('ZkAdmsService: Failed to calculate DTR', [
                            'pin' => $pin,
                            'date' => $date,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            // Automatically mark punched employees as registered in biometric directory
            $punchedPins = array_keys($affectedDates);
            foreach ($punchedPins as $pin) {
                try {
                    $pinStr = (string) $pin;
                    $emp = HrisEmployee::findByControlNo($pinStr, true);
                    $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $pinStr;

                    $enrollment = BiometricEnrollment::query()
                        ->where('employee_control_no', $canonicalControlNo)
                        ->orWhere('employee_control_no', $pinStr)
                        ->orWhere('employee_control_no', ltrim($pinStr, '0'))
                        ->first();

                    if (! $enrollment) {
                        $enrollment = new BiometricEnrollment([
                            'employee_control_no' => $canonicalControlNo,
                        ]);
                    }

                    $needsSave = false;
                    if (! $enrollment->isRegistered()) {
                        $fullName = $emp
                            ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''))
                            : 'EMP '.$pinStr;

                        $enrollment->employee_name = $fullName;
                        $enrollment->status = BiometricEnrollment::STATUS_REGISTERED;
                        $enrollment->enrolled_at = $enrollment->enrolled_at ?? now();
                        $enrollment->enrollment_device_sn = $enrollment->enrollment_device_sn ?: ($serialNumber ?: null);
                        $enrollment->notes = $enrollment->notes ?: 'Auto-registered from physical biometric device punch';
                        $needsSave = true;
                    }

                    if ($serialNumber !== '') {
                        $synced = $enrollment->synced_devices ?? [];
                        if (! in_array($serialNumber, $synced, true)) {
                            $synced[] = $serialNumber;
                            $enrollment->synced_devices = $synced;
                            $needsSave = true;
                        }
                    }

                    if ($needsSave) {
                        $enrollment->save();
                    }
                } catch (Throwable $e) {
                    Log::warning('ZkAdmsService: Failed to auto-register punched employee', [
                        'pin' => $pin,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        if ($serialNumber !== '' && $importedCount > 0) {
            BiometricDevice::query()
                ->where('serial_number', $serialNumber)
                ->increment('device_log_count', $importedCount, [
                    'last_sync_at' => now(),
                ]);
        }

        return [
            'response' => "OK: {$importedCount}\n",
            'count' => $importedCount,
        ];
    }

    /**
     * Handle device polling for pending commands (GET /iclock/getrequest).
     */
    public function handleGetRequest(Request $request): string
    {
        $serialNumber = trim((string) $request->query('SN', $request->query('sn', '')));
        if ($serialNumber !== '') {
            $this->registerOrUpdateDevice($serialNumber, $request);

            // Fetch pending commands queued for this device
            $commands = BiometricDeviceCommand::query()
                ->where('device_serial_number', $serialNumber)
                ->where('status', BiometricDeviceCommand::STATUS_PENDING)
                ->orderBy('id')
                ->limit(10)
                ->get();

            if ($commands->isNotEmpty()) {
                $output = '';
                foreach ($commands as $cmd) {
                    $output .= "C:{$cmd->id}:{$cmd->command_payload}\n";
                    $cmd->status = BiometricDeviceCommand::STATUS_SENT;
                    $cmd->sent_at = now();
                    $cmd->save();
                }

                return $output;
            }
        }

        // Return OK when no commands are queued
        return "OK\n";
    }

    /**
     * Handle command execution result from device (POST /iclock/devicecmd).
     */
    public function handleDeviceCmd(Request $request): string
    {
        $serialNumber = trim((string) $request->query('SN', $request->query('sn', '')));
        if ($serialNumber !== '') {
            $this->registerOrUpdateDevice($serialNumber, $request);
        }

        $body = $request->getContent();
        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        if ($lines !== false && count($lines) > 0) {
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }

                parse_str($trimmed, $params);
                $id = $params['ID'] ?? null;
                $returnCode = $params['Return'] ?? null;

                if ($id !== null) {
                    $command = BiometricDeviceCommand::query()->find($id);
                    if ($command) {
                        $isSuccess = ((string) $returnCode === '0');
                        $command->status = $isSuccess ? BiometricDeviceCommand::STATUS_SUCCESS : BiometricDeviceCommand::STATUS_FAILED;
                        $command->response_payload = (string) $returnCode;
                        $command->executed_at = now();
                        $command->save();

                        if ($isSuccess && $command->employee_control_no) {
                            $enrollment = BiometricEnrollment::query()
                                ->where('employee_control_no', $command->employee_control_no)
                                ->first();
                            if ($enrollment) {
                                $enrollment->markSyncedToDevice($command->device_serial_number);
                            }
                        }
                    }
                }
            }
        }

        return "OK\n";
    }

    /**
     * Parse a single row of ZKTeco ATTLOG push payload.
     * Common formats:
     * 1) Tab separated: PIN \t Timestamp \t State \t Verify \t WorkCode \t Reserved1 \t Reserved2
     * 2) Space separated: PIN Timestamp State Verify WorkCode
     *
     * @return array{biometric_pin: string, punch_time: string, punch_state: int, verify_type: int, work_code: ?string}|null
     */
    public function parseAttlogLine(string $line): ?array
    {
        $parts = preg_split('/\t+|\s{2,}/', $line);
        if ($parts === false || count($parts) < 2) {
            // Fallback: match PIN followed by YYYY-MM-DD HH:MM:SS
            if (preg_match('/^(\S+)\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})(?:\s+(\d+))?(?:\s+(\d+))?/', $line, $matches)) {
                $pin = trim($matches[1]);
                $time = trim($matches[2]);
                $state = isset($matches[3]) ? (int) $matches[3] : 0;
                $verify = isset($matches[4]) ? (int) $matches[4] : 15;

                return [
                    'biometric_pin' => $pin,
                    'punch_time' => $time,
                    'punch_state' => $state,
                    'verify_type' => $verify,
                    'work_code' => null,
                ];
            }

            return null;
        }

        $pin = trim($parts[0] ?? '');
        $punchTimeRaw = trim($parts[1] ?? '');

        if ($pin === '' || $punchTimeRaw === '') {
            return null;
        }

        try {
            $punchTime = Carbon::parse($punchTimeRaw)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }

        $punchState = isset($parts[2]) && is_numeric(trim($parts[2])) ? (int) trim($parts[2]) : 0;
        $verifyType = isset($parts[3]) && is_numeric(trim($parts[3])) ? (int) trim($parts[3]) : 15;
        $workCode = isset($parts[4]) ? trim($parts[4]) : null;

        return [
            'biometric_pin' => $pin,
            'punch_time' => $punchTime,
            'punch_state' => $punchState,
            'verify_type' => $verifyType,
            'work_code' => $workCode !== '' ? $workCode : null,
        ];
    }

    /**
     * Ensure the active device in tblBiometricDevices is updated with heartbeat and IP.
     */
    private function registerOrUpdateDevice(string $serialNumber, Request $request, bool $isSync = false): void
    {
        try {
            $device = BiometricDevice::query()->where('serial_number', $serialNumber)->first();

            if (! $device || ! $device->is_active) {
                return;
            }

            $clientIp = $request->ip();
            if ($clientIp !== null && $clientIp !== '') {
                $device->ip_address = $clientIp;
            }

            $pushVer = $request->query('pushver');
            if ($pushVer !== null && is_string($pushVer) && trim($pushVer) !== '') {
                $device->firmware_version = trim($pushVer);
            }

            $device->last_heartbeat_at = now();
            $device->status = 'ONLINE';

            if ($isSync) {
                $device->last_sync_at = now();
            }

            $device->save();
        } catch (Throwable $e) {
            Log::warning('ZkAdmsService: Failed to record device heartbeat', [
                'sn' => $serialNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
