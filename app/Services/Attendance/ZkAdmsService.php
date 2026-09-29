<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRawLog;
use App\Models\BiometricDevice;
use App\Models\BiometricDeviceCommand;
use App\Models\BiometricEnrollment;
use App\Models\BiometricTemplate;
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
            ."TransFlag=1111111111\n"
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
        $table = strtolower(trim((string) $request->query('tablename', $request->query('table', 'attlog'))));

        if ($serialNumber !== '') {
            $this->registerOrUpdateDevice($serialNumber, $request, true);
        }

        if (trim($body) === '') {
            return ['response' => "OK\n", 'count' => 0];
        }

        // 1. Biometric Fingerprint Templates push (table=template, biotemplate, fingertmp, templatev10)
        if (in_array($table, ['template', 'biotemplate', 'fingertmp', 'templatev10'], true)) {
            return $this->handleTemplatePush($request, $serialNumber, $body);
        }

        // 2. Face / BioData Templates push (table=biodata, face, biophoto)
        if (in_array($table, ['biodata', 'face', 'biophoto'], true)) {
            return $this->handleBioDataPush($request, $serialNumber, $body);
        }

        // 3. User info push (table=user, userinfo)
        if (in_array($table, ['user', 'userinfo'], true)) {
            return $this->handleUserPush($request, $serialNumber, $body);
        }

        // 4. Auto-detect if table defaulted to attlog but payload is actually template or user data
        if (stripos($body, 'TMP=') !== false || stripos($body, 'Template=') !== false) {
            if (stripos($body, 'Type=9') !== false || stripos($body, 'MajorVer=') !== false) {
                return $this->handleBioDataPush($request, $serialNumber, $body);
            }

            return $this->handleTemplatePush($request, $serialNumber, $body);
        }

        if (stripos($body, 'Name=') !== false && stripos($body, 'Pri=') !== false) {
            return $this->handleUserPush($request, $serialNumber, $body);
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
                        // Automatically broadcast user to all other authorized office biometric devices
                        $this->broadcastEmployeeToAllDevices($canonicalControlNo, $fullName, $serialNumber);
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
     * Handle incoming querydata push from device (POST /iclock/querydata).
     * Device pushes queried user info, fingerprint templates, or facial templates.
     *
     * @return array{response: string, count: int}
     */
    public function handleQueryData(Request $request): array
    {
        $serialNumber = trim((string) $request->query('SN', $request->query('sn', '')));
        $body = (string) $request->getContent();
        $table = strtolower(trim((string) $request->query('tablename', $request->query('table', ''))));
        $type = strtolower(trim((string) $request->query('type', '')));

        if ($serialNumber !== '') {
            $this->registerOrUpdateDevice($serialNumber, $request, true);
        }

        Log::info("ZkAdmsService: QueryData received from [{$serialNumber}] tablename [{$table}] type [{$type}] length [".strlen($body).']', [
            'query' => $request->query(),
            'body_sample' => mb_substr($body, 0, 500),
        ]);

        if (trim($body) === '') {
            return ['response' => "OK\n", 'count' => 0];
        }

        // 1. Fingerprint templates
        if (in_array($table, ['fingertmp', 'template', 'biotemplate', 'templatev10'], true)) {
            return $this->handleTemplatePush($request, $serialNumber, $body);
        }

        // 2. Face / BioData templates
        if (in_array($table, ['biodata', 'face', 'biophoto'], true)) {
            return $this->handleBioDataPush($request, $serialNumber, $body);
        }

        // 3. User info
        if (in_array($table, ['userinfo', 'user'], true)) {
            return $this->handleUserPush($request, $serialNumber, $body);
        }

        // 4. Content sniffing fallback
        if (stripos($body, 'TMP=') !== false || stripos($body, 'Template=') !== false) {
            if (stripos($body, 'Type=9') !== false || stripos($body, 'MajorVer=') !== false) {
                return $this->handleBioDataPush($request, $serialNumber, $body);
            }

            return $this->handleTemplatePush($request, $serialNumber, $body);
        }

        if (stripos($body, 'Name=') !== false && (stripos($body, 'Pri=') !== false || stripos($body, 'PIN=') !== false)) {
            return $this->handleUserPush($request, $serialNumber, $body);
        }

        return ['response' => "OK\n", 'count' => 0];
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

    /**
     * Handle incoming fingerprint template upload from device (POST /iclock/cdata?table=template).
     * Format: PIN=022936\tFID=0\tSize=568\tValid=1\tTMP=...
     *
     * @return array{response: string, count: int}
     */
    public function handleTemplatePush(Request $request, string $serialNumber, string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        if ($lines === false || count($lines) === 0) {
            return ['response' => "OK\n", 'count' => 0];
        }

        $imported = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parsed = $this->parseTemplateLine($line);
            if (! $parsed) {
                continue;
            }

            $pin = $parsed['pin'];
            $emp = HrisEmployee::findByControlNo($pin, true);
            $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $pin;

            try {
                $template = BiometricTemplate::query()->updateOrCreate(
                    [
                        'employee_control_no' => $canonicalControlNo,
                        'template_type' => BiometricTemplate::TYPE_FP,
                        'finger_index' => $parsed['fid'],
                    ],
                    [
                        'biometric_pin' => $pin,
                        'template_data' => $parsed['tmp'],
                        'template_size' => $parsed['size'],
                        'template_version' => '10.0',
                        'valid' => $parsed['valid'],
                        'source_device_sn' => $serialNumber ?: null,
                    ]
                );

                // Auto-register employee in biometric directory
                $enrollment = BiometricEnrollment::query()->firstOrCreate(
                    ['employee_control_no' => $canonicalControlNo],
                    [
                        'employee_name' => $emp ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? '')) : 'EMP '.$pin,
                        'status' => BiometricEnrollment::STATUS_REGISTERED,
                        'enrolled_at' => now(),
                        'enrollment_device_sn' => $serialNumber ?: null,
                    ]
                );

                if (! $enrollment->isRegistered()) {
                    $enrollment->status = BiometricEnrollment::STATUS_REGISTERED;
                    $enrollment->enrolled_at = $enrollment->enrolled_at ?? now();
                    $enrollment->enrollment_device_sn = $enrollment->enrollment_device_sn ?: ($serialNumber ?: null);
                    $enrollment->save();
                }

                $imported++;

                // Automatically clone this newly captured template to all OTHER active devices!
                $this->broadcastTemplateToAllDevices($template, $serialNumber);
            } catch (Throwable $e) {
                Log::warning('ZkAdmsService: Failed to save biometric fingerprint template', [
                    'pin' => $pin,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'response' => "OK: {$imported}\n",
            'count' => $imported,
        ];
    }

    /**
     * Handle incoming face/biodata template upload from device (POST /iclock/cdata?table=biodata).
     * Format: PIN=022936\tNo=0\tIndex=0\tValid=1\tType=9\tMajorVer=1\tMinorVer=0\tFormat=0\tTmp=...
     *
     * @return array{response: string, count: int}
     */
    public function handleBioDataPush(Request $request, string $serialNumber, string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        if ($lines === false || count($lines) === 0) {
            return ['response' => "OK\n", 'count' => 0];
        }

        $imported = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parsed = $this->parseBioDataLine($line);
            if (! $parsed) {
                continue;
            }

            $pin = $parsed['pin'];
            $emp = HrisEmployee::findByControlNo($pin, true);
            $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $pin;

            try {
                $template = BiometricTemplate::query()->updateOrCreate(
                    [
                        'employee_control_no' => $canonicalControlNo,
                        'template_type' => BiometricTemplate::TYPE_BIODATA,
                        'finger_index' => null,
                    ],
                    [
                        'biometric_pin' => $pin,
                        'template_data' => $parsed['tmp'],
                        'template_size' => strlen($parsed['tmp']),
                        'template_version' => '1.0',
                        'valid' => $parsed['valid'],
                        'source_device_sn' => $serialNumber ?: null,
                    ]
                );

                $imported++;

                // Automatically clone this face template to all other devices!
                $this->broadcastTemplateToAllDevices($template, $serialNumber);
            } catch (Throwable $e) {
                Log::warning('ZkAdmsService: Failed to save biodata face template', [
                    'pin' => $pin,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'response' => "OK: {$imported}\n",
            'count' => $imported,
        ];
    }

    /**
     * Handle incoming user profile upload from device (POST /iclock/cdata?table=user).
     *
     * @return array{response: string, count: int}
     */
    public function handleUserPush(Request $request, string $serialNumber, string $body): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body));
        if ($lines === false || count($lines) === 0) {
            return ['response' => "OK\n", 'count' => 0];
        }

        $imported = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = preg_split('/\t+|\s{2,}/', $line);
            if (! $parts) {
                continue;
            }

            $kvMap = [];
            foreach ($parts as $part) {
                $kv = explode('=', trim($part), 2);
                if (count($kv) === 2) {
                    $kvMap[strtoupper(trim($kv[0]))] = trim($kv[1]);
                }
            }

            $pin = $kvMap['PIN'] ?? null;
            if (! $pin) {
                continue;
            }

            $emp = HrisEmployee::findByControlNo($pin, true);
            $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $pin;
            $name = $kvMap['NAME'] ?? ($emp ? trim(($emp->firstname ?? '').' '.($emp->surname ?? '')) : 'EMP '.$pin);

            $enrollment = BiometricEnrollment::query()->firstOrCreate(
                ['employee_control_no' => $canonicalControlNo],
                [
                    'employee_name' => $name,
                    'status' => BiometricEnrollment::STATUS_REGISTERED,
                    'enrolled_at' => now(),
                    'enrollment_device_sn' => $serialNumber ?: null,
                ]
            );

            if (! $enrollment->isRegistered()) {
                $enrollment->status = BiometricEnrollment::STATUS_REGISTERED;
                $enrollment->enrolled_at = $enrollment->enrolled_at ?? now();
                $enrollment->enrollment_device_sn = $enrollment->enrollment_device_sn ?: ($serialNumber ?: null);
                $enrollment->save();
            }

            $imported++;
        }

        return [
            'response' => "OK: {$imported}\n",
            'count' => $imported,
        ];
    }

    /**
     * Parse a single row of fingerprint template line.
     * Common formats:
     * PIN=022936\tFID=0\tSize=568\tValid=1\tTMP=...
     * PIN=022936 FingerID=0 Size=568 Valid=1 TMP=...
     *
     * @return array{pin: string, fid: int, size: int, valid: int, tmp: string}|null
     */
    public function parseTemplateLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        // 1. Try regex extraction (supports any whitespace/tab combination)
        $pin = null;
        if (preg_match('/(?:^|[\s\t])PIN=([^\s\t]+)/i', $line, $matches)) {
            $pin = trim($matches[1]);
        }

        $tmp = null;
        if (preg_match('/(?:^|[\s\t])(?:TMP|Template)=([^\s\t\r\n]+)/i', $line, $matches)) {
            $tmp = trim($matches[1]);
        }

        if ($pin !== null && $pin !== '' && $tmp !== null && $tmp !== '') {
            $fid = 0;
            if (preg_match('/(?:^|[\s\t])(?:FID|FingerID|Finger_ID)=([0-9]+)/i', $line, $matches)) {
                $fid = (int) $matches[1];
            }

            $size = strlen($tmp);
            if (preg_match('/(?:^|[\s\t])Size=([0-9]+)/i', $line, $matches)) {
                $size = (int) $matches[1];
            }

            $valid = 1;
            if (preg_match('/(?:^|[\s\t])Valid=([0-9]+)/i', $line, $matches)) {
                $valid = (int) $matches[1];
            }

            return [
                'pin' => $pin,
                'fid' => $fid,
                'size' => $size,
                'valid' => $valid,
                'tmp' => $tmp,
            ];
        }

        // 2. Fallback to kvMap parsing
        $parts = preg_split('/\t+|\s{2,}/', $line);
        if (! $parts || count($parts) === 0) {
            return null;
        }

        $kvMap = [];
        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $kvMap[strtoupper(trim($kv[0]))] = trim($kv[1]);
            }
        }

        $pin = $kvMap['PIN'] ?? null;
        $tmp = $kvMap['TMP'] ?? $kvMap['TEMPLATE'] ?? null;

        if ($pin === null || $pin === '' || $tmp === null || $tmp === '') {
            return null;
        }

        $fid = isset($kvMap['FID']) ? (int) $kvMap['FID'] : (isset($kvMap['FINGERID']) ? (int) $kvMap['FINGERID'] : 0);
        $size = isset($kvMap['SIZE']) ? (int) $kvMap['SIZE'] : strlen($tmp);
        $valid = isset($kvMap['VALID']) ? (int) $kvMap['VALID'] : 1;

        return [
            'pin' => $pin,
            'fid' => $fid,
            'size' => $size,
            'valid' => $valid,
            'tmp' => $tmp,
        ];
    }

    /**
     * Parse a single row of face/biodata template line.
     * Format: PIN=022936\tNo=0\tIndex=0\tValid=1\tType=9\tMajorVer=1\tMinorVer=0\tFormat=0\tTmp=...
     *
     * @return array{pin: string, type_id: int, valid: int, tmp: string}|null
     */
    public function parseBioDataLine(string $line): ?array
    {
        $line = trim($line);
        if ($line === '') {
            return null;
        }

        $pin = null;
        if (preg_match('/(?:^|[\s\t])PIN=([^\s\t]+)/i', $line, $matches)) {
            $pin = trim($matches[1]);
        }

        $tmp = null;
        if (preg_match('/(?:^|[\s\t])(?:TMP|Tmp|Template)=([^\s\t\r\n]+)/i', $line, $matches)) {
            $tmp = trim($matches[1]);
        }

        if ($pin !== null && $pin !== '' && $tmp !== null && $tmp !== '') {
            $type = 9;
            if (preg_match('/(?:^|[\s\t])Type=([0-9]+)/i', $line, $matches)) {
                $type = (int) $matches[1];
            }

            $valid = 1;
            if (preg_match('/(?:^|[\s\t])Valid=([0-9]+)/i', $line, $matches)) {
                $valid = (int) $matches[1];
            }

            return [
                'pin' => $pin,
                'type_id' => $type,
                'valid' => $valid,
                'tmp' => $tmp,
            ];
        }

        // Fallback to kvMap
        $parts = preg_split('/\t+|\s{2,}/', $line);
        if (! $parts || count($parts) === 0) {
            return null;
        }

        $kvMap = [];
        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $kvMap[strtoupper(trim($kv[0]))] = trim($kv[1]);
            }
        }

        $pin = $kvMap['PIN'] ?? null;
        $tmp = $kvMap['TMP'] ?? $kvMap['Tmp'] ?? null;

        if ($pin === null || $pin === '' || $tmp === null || $tmp === '') {
            return null;
        }

        $valid = isset($kvMap['VALID']) ? (int) $kvMap['VALID'] : 1;
        $type = isset($kvMap['TYPE']) ? (int) $kvMap['TYPE'] : 9;

        return [
            'pin' => $pin,
            'type_id' => $type,
            'valid' => $valid,
            'tmp' => $tmp,
        ];
    }

    /**
     * Broadcast a specific biometric template (fingerprint or face) to all active, authorized biometric devices.
     */
    public function broadcastTemplateToAllDevices(BiometricTemplate $template, ?string $skipDeviceSn = null): int
    {
        $devices = BiometricDevice::query()
            ->where('is_active', true)
            ->where('status', '!=', 'BLOCKED')
            ->get();

        if ($devices->isEmpty()) {
            return 0;
        }

        $queued = 0;
        $commandPayload = $template->toCommandPayload();
        $cmdType = ($template->template_type === BiometricTemplate::TYPE_BIODATA || $template->template_type === BiometricTemplate::TYPE_FACE)
            ? BiometricDeviceCommand::CMD_DATA_BIODATA
            : BiometricDeviceCommand::CMD_DATA_FP;

        $enrollment = BiometricEnrollment::query()
            ->where('employee_control_no', $template->employee_control_no)
            ->first();
        $employeeName = $enrollment?->employee_name ?? 'EMP '.$template->employee_control_no;

        foreach ($devices as $device) {
            if ($skipDeviceSn !== null && $device->serial_number === $skipDeviceSn) {
                continue;
            }

            // 1. Ensure DATA USER is also queued/present
            $userCommand = BiometricDeviceCommand::buildDataUserCommand($template->employee_control_no, $employeeName);
            $userQueued = BiometricDeviceCommand::query()
                ->where('device_serial_number', $device->serial_number)
                ->where('employee_control_no', $template->employee_control_no)
                ->where('command_type', BiometricDeviceCommand::CMD_DATA_USER)
                ->whereIn('status', [BiometricDeviceCommand::STATUS_PENDING, BiometricDeviceCommand::STATUS_SENT, BiometricDeviceCommand::STATUS_SUCCESS])
                ->exists();

            if (! $userQueued) {
                BiometricDeviceCommand::query()->create([
                    'device_serial_number' => $device->serial_number,
                    'command_type' => BiometricDeviceCommand::CMD_DATA_USER,
                    'command_payload' => $userCommand,
                    'employee_control_no' => $template->employee_control_no,
                    'employee_name' => $employeeName,
                    'status' => BiometricDeviceCommand::STATUS_PENDING,
                ]);
            }

            // 2. Queue template command
            $templateQueued = BiometricDeviceCommand::query()
                ->where('device_serial_number', $device->serial_number)
                ->where('employee_control_no', $template->employee_control_no)
                ->where('command_type', $cmdType)
                ->where('command_payload', $commandPayload)
                ->whereIn('status', [BiometricDeviceCommand::STATUS_PENDING, BiometricDeviceCommand::STATUS_SENT, BiometricDeviceCommand::STATUS_SUCCESS])
                ->exists();

            if (! $templateQueued) {
                BiometricDeviceCommand::query()->create([
                    'device_serial_number' => $device->serial_number,
                    'command_type' => $cmdType,
                    'command_payload' => $commandPayload,
                    'employee_control_no' => $template->employee_control_no,
                    'employee_name' => $employeeName,
                    'status' => BiometricDeviceCommand::STATUS_PENDING,
                ]);
                $queued++;
            }
        }

        return $queued;
    }

    /**
     * Broadcast an employee and all their biometric templates (fingerprints and faces)
     * to all active, authorized biometric devices across the city.
     */
    public function broadcastEmployeeToAllDevices(string $controlNo, string $name, ?string $skipDeviceSn = null): int
    {
        $devices = BiometricDevice::query()
            ->where('is_active', true)
            ->where('status', '!=', 'BLOCKED')
            ->get();

        if ($devices->isEmpty()) {
            return 0;
        }

        $commandString = BiometricDeviceCommand::buildDataUserCommand($controlNo, $name);
        $queuedCount = 0;

        // Fetch all templates for this employee
        $templates = BiometricTemplate::query()
            ->where('employee_control_no', $controlNo)
            ->get();

        foreach ($devices as $device) {
            if ($skipDeviceSn !== null && $device->serial_number === $skipDeviceSn) {
                continue;
            }

            // 1. Queue DATA USER command
            $alreadyUserQueued = BiometricDeviceCommand::query()
                ->where('device_serial_number', $device->serial_number)
                ->where('employee_control_no', $controlNo)
                ->where('command_type', BiometricDeviceCommand::CMD_DATA_USER)
                ->whereIn('status', [BiometricDeviceCommand::STATUS_PENDING, BiometricDeviceCommand::STATUS_SENT, BiometricDeviceCommand::STATUS_SUCCESS])
                ->exists();

            if (! $alreadyUserQueued) {
                BiometricDeviceCommand::query()->create([
                    'device_serial_number' => $device->serial_number,
                    'command_type' => BiometricDeviceCommand::CMD_DATA_USER,
                    'command_payload' => $commandString,
                    'employee_control_no' => $controlNo,
                    'employee_name' => $name,
                    'status' => BiometricDeviceCommand::STATUS_PENDING,
                ]);
                $queuedCount++;
            }

            // 2. Queue all biometric templates (fingerprints & face)
            foreach ($templates as $tpl) {
                $cmdType = ($tpl->template_type === BiometricTemplate::TYPE_BIODATA || $tpl->template_type === BiometricTemplate::TYPE_FACE)
                    ? BiometricDeviceCommand::CMD_DATA_BIODATA
                    : BiometricDeviceCommand::CMD_DATA_FP;
                $cmdPayload = $tpl->toCommandPayload();

                $alreadyTplQueued = BiometricDeviceCommand::query()
                    ->where('device_serial_number', $device->serial_number)
                    ->where('employee_control_no', $controlNo)
                    ->where('command_type', $cmdType)
                    ->where('command_payload', $cmdPayload)
                    ->whereIn('status', [BiometricDeviceCommand::STATUS_PENDING, BiometricDeviceCommand::STATUS_SENT, BiometricDeviceCommand::STATUS_SUCCESS])
                    ->exists();

                if (! $alreadyTplQueued) {
                    BiometricDeviceCommand::query()->create([
                        'device_serial_number' => $device->serial_number,
                        'command_type' => $cmdType,
                        'command_payload' => $cmdPayload,
                        'employee_control_no' => $controlNo,
                        'employee_name' => $name,
                        'status' => BiometricDeviceCommand::STATUS_PENDING,
                    ]);
                    $queuedCount++;
                }
            }
        }

        return $queuedCount;
    }

    /**
     * Broadcast all biometrically registered employees and their templates to all active, authorized devices.
     *
     * @return array{employees_count: int, devices_count: int, commands_queued: int}
     */
    public function broadcastAllRegisteredEmployees(): array
    {
        $enrollments = BiometricEnrollment::query()
            ->where('status', BiometricEnrollment::STATUS_REGISTERED)
            ->get();

        $queuedCommands = 0;
        $employeesCount = $enrollments->count();

        foreach ($enrollments as $enrollment) {
            $queuedCommands += $this->broadcastEmployeeToAllDevices(
                $enrollment->employee_control_no,
                $enrollment->employee_name
            );
        }

        $devicesCount = BiometricDevice::query()
            ->where('is_active', true)
            ->where('status', '!=', 'BLOCKED')
            ->count();

        return [
            'employees_count' => $employeesCount,
            'devices_count' => $devicesCount,
            'commands_queued' => $queuedCommands,
        ];
    }

    /**
     * Request/Pull biometric templates (fingerprints and faces) from a physical biometric terminal into the server.
     * Queues official ZKTeco Push SDK query commands verified on MB360 firmware:
     * 1. DATA QUERY FINGERTMP
     * 2. DATA QUERY USERINFO
     * 3. CHECK
     *
     * @return array{device_serial_number: string, commands_queued: int}
     */
    public function requestTemplatesFromDevice(string $deviceSn, ?string $pin = null): array
    {
        $device = BiometricDevice::query()->where('serial_number', $deviceSn)->first();
        if (! $device) {
            throw new \InvalidArgumentException("Device [{$deviceSn}] not found.");
        }

        $commandsQueued = 0;

        // 1. Query Fingerprint templates
        $fpPayload = $pin !== null && $pin !== '' ? "DATA QUERY FINGERTMP PIN={$pin}" : 'DATA QUERY FINGERTMP';
        BiometricDeviceCommand::query()->create([
            'device_serial_number' => $deviceSn,
            'command_type' => BiometricDeviceCommand::CMD_DATA_QUERY,
            'command_payload' => $fpPayload,
            'employee_control_no' => $pin,
            'employee_name' => $pin ? "EMP {$pin}" : null,
            'status' => BiometricDeviceCommand::STATUS_PENDING,
        ]);
        $commandsQueued++;

        // 2. Query User profile info
        $userPayload = $pin !== null && $pin !== '' ? "DATA QUERY USERINFO PIN={$pin}" : 'DATA QUERY USERINFO';
        BiometricDeviceCommand::query()->create([
            'device_serial_number' => $deviceSn,
            'command_type' => BiometricDeviceCommand::CMD_DATA_QUERY,
            'command_payload' => $userPayload,
            'employee_control_no' => $pin,
            'employee_name' => $pin ? "EMP {$pin}" : null,
            'status' => BiometricDeviceCommand::STATUS_PENDING,
        ]);
        $commandsQueued++;

        // 3. Flush buffers with CHECK
        BiometricDeviceCommand::query()->create([
            'device_serial_number' => $deviceSn,
            'command_type' => BiometricDeviceCommand::CMD_CHECK,
            'command_payload' => 'CHECK',
            'employee_control_no' => $pin,
            'employee_name' => $pin ? "EMP {$pin}" : null,
            'status' => BiometricDeviceCommand::STATUS_PENDING,
        ]);
        $commandsQueued++;

        return [
            'device_serial_number' => $deviceSn,
            'commands_queued' => $commandsQueued,
        ];
    }
}
