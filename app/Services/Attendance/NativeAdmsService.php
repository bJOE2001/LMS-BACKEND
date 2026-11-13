<?php

namespace App\Services\Attendance;

use App\Models\Attendance\AttendanceRawLog;
use App\Models\Attendance\BiometricDevice;
use App\Models\Attendance\BiometricDeviceCommand;
use App\Models\Attendance\BiometricEnrollment;
use App\Models\Attendance\BiometricTemplate;
use App\Models\Attendance\BiometricTransferLog;
use App\Models\HrisEmployee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class NativeAdmsService
{
    private const MAX_COMMANDS_PER_POLL = 5;

    public function __construct(
        private readonly DtrCalculationService $dtrService
    ) {}

    /**
     * Handle initial handshake from ZKTeco terminal (GET /iclock/cdata).
     */
    public function handleHandshake(Request $request, string $serialNumber): string
    {
        $this->updateDeviceTelemetry($request, $serialNumber);
        $opStamp = (int) Cache::get("adms_opstamp_{$serialNumber}", 0);

        return "GET OPTION FROM: {$serialNumber}\n"
            ."Stamp=9999\n"
            ."OpStamp={$opStamp}\n"
            ."PhotoStamp=9999\n"
            ."ErrorDelay=30\n"
            ."Delay=10\n"
            ."TransTimes=00:00;14:05\n"
            ."TransInterval=1\n"
            ."TransFlag=1111111111\n"
            ."TimeZone=8\n"
            ."Realtime=1\n"
            ."Encrypt=0\n"
            ."ServerVersion=3.0.1\n";
    }

    /**
     * Handle incoming data pushes from ZKTeco terminal (POST /iclock/cdata or /iclock/querydata).
     *
     * @return array{
     *     success: bool,
     *     table: string,
     *     imported: int,
     *     duplicates: int,
     *     response_text: string
     * }
     */
    public function handleDataPush(Request $request, string $serialNumber): array
    {
        $device = $this->updateDeviceTelemetry($request, $serialNumber);
        $table = strtoupper(trim((string) ($request->query('table') ?: $request->input('table') ?: 'ATTLOG')));
        $rawContent = (string) $request->getContent();

        $opStamp = $request->query('OpStamp') ?? $request->input('OpStamp');
        if ($opStamp !== null && is_numeric($opStamp)) {
            Cache::forever("adms_opstamp_{$serialNumber}", (int) $opStamp);
        }

        if ($table === 'ATTLOG') {
            return $this->processAttendanceLogs($device, $serialNumber, $rawContent);
        }

        if (in_array($table, ['OPERLOG', 'BIODATA', 'USERINFO', 'USER', 'FINGERTMP', 'FP', 'FACE'], true)) {
            if (! $device || ! $device->is_active) {
                Log::warning("NativeAdmsService: Rejected biometric push from unapproved or inactive terminal: {$serialNumber}");

                return [
                    'success' => false,
                    'table' => $table,
                    'imported' => 0,
                    'duplicates' => 0,
                    'response_text' => 'OK',
                ];
            }

            return $this->processBiometricDataPush($device, $serialNumber, $table, $rawContent);
        }

        return [
            'success' => true,
            'table' => $table,
            'imported' => 0,
            'duplicates' => 0,
            'response_text' => 'OK',
        ];
    }

    /**
     * Handle terminal heartbeat and command polling (GET /iclock/getrequest).
     */
    public function handleGetRequest(Request $request, string $serialNumber): string
    {
        $device = $this->updateDeviceTelemetry($request, $serialNumber);
        $this->updateDeviceInfoFromQuery($request, $device);

        try {
            BiometricDeviceCommand::query()
                ->where('device_serial_number', $serialNumber)
                ->where('status', BiometricDeviceCommand::STATUS_SENT)
                ->where('sent_at', '<', now()->subMinutes(5))
                ->update(['status' => BiometricDeviceCommand::STATUS_PENDING]);

            $commands = BiometricDeviceCommand::query()
                ->where('device_serial_number', $serialNumber)
                ->where('status', BiometricDeviceCommand::STATUS_PENDING)
                ->orderBy('id')
                ->limit(self::MAX_COMMANDS_PER_POLL)
                ->get();

            if ($commands->isEmpty()) {
                return 'OK';
            }

            $lines = [];
            foreach ($commands as $cmd) {
                $cmd->update([
                    'status' => BiometricDeviceCommand::STATUS_SENT,
                    'sent_at' => now(),
                ]);
                $cleanPayload = str_replace(["\r", "\n"], '', (string) $cmd->command_payload);
                $lines[] = "C:{$cmd->id}:{$cleanPayload}";
            }

            return implode("\n", $lines);
        } catch (Throwable $e) {
            Log::warning("NativeAdmsService: Command poll failed for SN {$serialNumber}", [
                'error' => $e->getMessage(),
            ]);

            return 'OK';
        }
    }

    /**
     * Handle terminal command execution response (POST /iclock/devicecmd).
     */
    public function handleDeviceCmd(Request $request, string $serialNumber): string
    {
        $this->updateDeviceTelemetry($request, $serialNumber);
        $rawContent = trim((string) $request->getContent());

        $ackItems = [];
        if ($rawContent !== '') {
            $lines = preg_split('/\r\n|\r|\n/', $rawContent) ?: [];
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed === '') {
                    continue;
                }
                parse_str($trimmed, $parsed);
                if (isset($parsed['ID']) || isset($parsed['id'])) {
                    $ackItems[] = [
                        'id' => (int) ($parsed['ID'] ?? $parsed['id']),
                        'return' => (int) ($parsed['Return'] ?? $parsed['return'] ?? -1),
                        'raw' => $trimmed,
                    ];
                }
            }
        }

        if ($ackItems === [] && ($request->has('ID') || $request->has('id'))) {
            $ackItems[] = [
                'id' => (int) ($request->input('ID') ?? $request->input('id')),
                'return' => (int) ($request->input('Return') ?? $request->input('return') ?? -1),
                'raw' => http_build_query($request->all()),
            ];
        }

        foreach ($ackItems as $ack) {
            if ($ack['id'] <= 0) {
                continue;
            }

            try {
                $command = BiometricDeviceCommand::query()
                    ->where('id', $ack['id'])
                    ->where('device_serial_number', $serialNumber)
                    ->first();

                if (! $command) {
                    continue;
                }

                $isSuccess = $ack['return'] === 0;
                $command->update([
                    'status' => $isSuccess ? BiometricDeviceCommand::STATUS_SUCCESS : BiometricDeviceCommand::STATUS_FAILED,
                    'return_code' => $ack['return'],
                    'response_payload' => substr($ack['raw'], 0, 1000),
                    'executed_at' => now(),
                ]);

                if ($isSuccess && $command->employee_control_no && in_array($command->command_type, [
                    BiometricDeviceCommand::CMD_DATA_USER,
                    BiometricDeviceCommand::CMD_UPDATE_TEMPLATE,
                ], true)) {
                    $enrollment = BiometricEnrollment::query()
                        ->where('employee_control_no', $command->employee_control_no)
                        ->first();
                    $enrollment?->markSyncedToDevice($serialNumber);
                } elseif (! $isSuccess) {
                    Log::error("NativeAdmsService: Terminal {$serialNumber} rejected command ID {$command->id} ({$command->command_type}) with Return={$ack['return']}", [
                        'employee_control_no' => $command->employee_control_no,
                        'response' => $ack['raw'],
                    ]);

                    if ($command->employee_control_no && $command->command_type === BiometricDeviceCommand::CMD_UPDATE_TEMPLATE) {
                        $latestLog = BiometricTransferLog::query()
                            ->where('employee_control_no', $command->employee_control_no)
                            ->where('target_device_sn', $serialNumber)
                            ->orderByDesc('id')
                            ->first();

                        $latestLog?->update([
                            'status' => BiometricTransferLog::STATUS_FAILED,
                            'remarks' => "Terminal {$serialNumber} rejected biometric template push (Return={$ack['return']}).",
                        ]);
                    }
                }
            } catch (Throwable $e) {
                Log::warning("NativeAdmsService: Failed processing devicecmd ID {$ack['id']}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return 'OK';
    }

    /**
     * Handle terminal registration request (GET / POST /iclock/registry).
     */
    public function handleRegistry(Request $request, string $serialNumber): string
    {
        $this->updateDeviceTelemetry($request, $serialNumber);

        return "RegistryCode=OK\n";
    }

    /**
     * Parse and store biometric templates (USER, FP, FACE, BIODATA) pushed from MB360 / ADMS terminals.
     *
     * @return array{
     *     success: bool,
     *     table: string,
     *     imported: int,
     *     duplicates: int,
     *     response_text: string
     * }
     */
    private function processBiometricDataPush(
        ?BiometricDevice $device,
        string $serialNumber,
        string $table,
        string $rawContent
    ): array {
        $lines = preg_split('/\r\n|\r|\n/', trim($rawContent)) ?: [];
        $communicationMode = $device?->getTemplateCommunicationMode() ?? BiometricDevice::MODE_ADMS;
        $imported = 0;
        $processedLines = 0;

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }
            $processedLines++;

            $recordType = $table;
            $kvBody = $line;

            if (preg_match('/^(USERINFO|USER|FINGERTMP|FP|FACE|BIODATA|OPLOG)\s+(.+)$/i', $line, $matches)) {
                $recordType = strtoupper($matches[1]);
                $kvBody = $matches[2];
            }

            if ($recordType === 'OPLOG') {
                continue;
            }

            $fields = $this->parseKeyValueLine($kvBody);
            $rawPin = trim((string) ($fields['PIN'] ?? ''));
            if ($rawPin === '') {
                continue;
            }

            $emp = HrisEmployee::findByControlNo($rawPin, true);
            $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $rawPin;
            $fallbackName = trim((string) ($fields['NAME'] ?? ''));
            $fullName = $emp
                ? trim(($emp->firstname ?? '').' '.($emp->middlename ? $emp->middlename[0].'. ' : '').($emp->surname ?? ''))
                : ($fallbackName !== '' ? $fallbackName : 'EMP '.$canonicalControlNo);

            if (in_array($recordType, ['USER', 'USERINFO'], true)) {
                $this->upsertUserEnrollmentFromPush($canonicalControlNo, $fullName, $serialNumber, false);

                continue;
            }

            if (in_array($recordType, ['FP', 'FINGERTMP'], true)) {
                $tmp = trim((string) ($fields['TMP'] ?? ''));
                if ($tmp === '') {
                    continue;
                }

                $fid = isset($fields['FID']) && is_numeric($fields['FID']) ? (int) $fields['FID'] : 0;
                $size = isset($fields['SIZE']) && is_numeric($fields['SIZE']) ? (int) $fields['SIZE'] : strlen($tmp);
                $valid = isset($fields['VALID']) && is_numeric($fields['VALID']) ? (int) $fields['VALID'] : 1;

                BiometricTemplate::query()->updateOrCreate(
                    [
                        'employee_control_no' => $canonicalControlNo,
                        'communication_mode' => $communicationMode,
                        'biometric_type' => BiometricTemplate::TYPE_FINGERPRINT,
                        'finger_id' => $fid,
                    ],
                    [
                        'template_size' => $size,
                        'valid' => $valid,
                        'template_version' => '10',
                        'template_data' => $tmp,
                        'raw_payload' => $line,
                        'source_device_sn' => $serialNumber,
                    ]
                );

                $this->upsertUserEnrollmentFromPush($canonicalControlNo, $fullName, $serialNumber, true);
                $imported++;

                continue;
            }

            if ($recordType === 'FACE') {
                $tmp = trim((string) ($fields['TMP'] ?? ''));
                if ($tmp === '') {
                    continue;
                }

                $fid = isset($fields['FID']) && is_numeric($fields['FID']) ? (int) $fields['FID'] : 0;
                $size = isset($fields['SIZE']) && is_numeric($fields['SIZE']) ? (int) $fields['SIZE'] : strlen($tmp);
                $valid = isset($fields['VALID']) && is_numeric($fields['VALID']) ? (int) $fields['VALID'] : 1;

                BiometricTemplate::query()->updateOrCreate(
                    [
                        'employee_control_no' => $canonicalControlNo,
                        'communication_mode' => $communicationMode,
                        'biometric_type' => BiometricTemplate::TYPE_FACE,
                        'finger_id' => $fid,
                    ],
                    [
                        'template_size' => $size,
                        'valid' => $valid,
                        'template_version' => '7',
                        'template_data' => $tmp,
                        'raw_payload' => $line,
                        'source_device_sn' => $serialNumber,
                    ]
                );

                $this->upsertUserEnrollmentFromPush($canonicalControlNo, $fullName, $serialNumber, true);
                $imported++;

                continue;
            }

            if ($recordType === 'BIODATA') {
                $tmp = trim((string) ($fields['TMP'] ?? ''));
                if ($tmp === '') {
                    continue;
                }

                $no = isset($fields['NO']) && is_numeric($fields['NO'])
                    ? (int) $fields['NO']
                    : (isset($fields['FID']) && is_numeric($fields['FID']) ? (int) $fields['FID'] : 0);
                $valid = isset($fields['VALID']) && is_numeric($fields['VALID']) ? (int) $fields['VALID'] : 1;
                $bioCode = isset($fields['TYPE']) && is_numeric($fields['TYPE']) ? (int) $fields['TYPE'] : 1;
                $majorVer = isset($fields['MAJORVER']) && is_numeric($fields['MAJORVER']) ? (int) $fields['MAJORVER'] : 10;
                $minorVer = isset($fields['MINORVER']) && is_numeric($fields['MINORVER']) ? (int) $fields['MINORVER'] : 0;

                $bioType = match ($bioCode) {
                    9, 2 => BiometricTemplate::TYPE_FACE,
                    8 => BiometricTemplate::TYPE_PALM,
                    default => BiometricTemplate::TYPE_FINGERPRINT,
                };

                BiometricTemplate::query()->updateOrCreate(
                    [
                        'employee_control_no' => $canonicalControlNo,
                        'communication_mode' => $communicationMode,
                        'biometric_type' => $bioType,
                        'finger_id' => $no,
                    ],
                    [
                        'template_size' => strlen($tmp),
                        'valid' => $valid,
                        'template_version' => "biodata:{$bioCode}:{$majorVer}.{$minorVer}",
                        'template_data' => $tmp,
                        'raw_payload' => $line,
                        'source_device_sn' => $serialNumber,
                    ]
                );

                $this->upsertUserEnrollmentFromPush($canonicalControlNo, $fullName, $serialNumber, true);
                $imported++;
            }
        }

        return [
            'success' => true,
            'table' => $table,
            'imported' => $imported,
            'duplicates' => 0,
            'response_text' => $processedLines > 0 ? "OK: {$processedLines}" : 'OK',
        ];
    }

    /**
     * Parse tab-separated or space-separated Key=Value pairs from ADMS OPERLOG / BIODATA lines.
     *
     * @return array<string, string>
     */
    private function parseKeyValueLine(string $kvBody): array
    {
        $result = [];
        $parts = str_contains($kvBody, "\t")
            ? explode("\t", $kvBody)
            : (preg_split('/\s+(?=[A-Za-z0-9_]+=)/', $kvBody) ?: []);

        foreach ($parts as $part) {
            $eqPos = strpos($part, '=');
            if ($eqPos === false) {
                continue;
            }
            $key = strtoupper(trim(substr($part, 0, $eqPos)));
            $val = trim(substr($part, $eqPos + 1));
            if ($key !== '') {
                $result[$key] = $val;
            }
        }

        return $result;
    }

    /**
     * Upsert employee enrollment status when terminal reports USER or biometric template upload.
     */
    private function upsertUserEnrollmentFromPush(
        string $controlNo,
        string $fullName,
        string $serialNumber,
        bool $hasBiometricTemplate
    ): void {
        try {
            $enrollment = BiometricEnrollment::query()
                ->where('employee_control_no', $controlNo)
                ->first();

            if (! $enrollment) {
                $enrollment = new BiometricEnrollment([
                    'employee_control_no' => $controlNo,
                    'employee_name' => $fullName,
                    'status' => $hasBiometricTemplate
                        ? BiometricEnrollment::STATUS_REGISTERED
                        : BiometricEnrollment::STATUS_PENDING_ENROLLMENT,
                    'enrollment_device_sn' => $serialNumber,
                    'enrolled_at' => $hasBiometricTemplate ? now() : null,
                    'synced_devices' => [$serialNumber],
                ]);
                $enrollment->save();

                return;
            }

            if ($enrollment->employee_name === '' || str_starts_with((string) $enrollment->employee_name, 'EMP ')) {
                $enrollment->employee_name = $fullName;
            }

            if ($hasBiometricTemplate) {
                $enrollment->status = BiometricEnrollment::STATUS_REGISTERED;
                $enrollment->enrolled_at = $enrollment->enrolled_at ?? now();
                $enrollment->enrollment_device_sn = $enrollment->enrollment_device_sn ?: $serialNumber;
            }

            $synced = is_array($enrollment->synced_devices) ? $enrollment->synced_devices : [];
            if (! in_array($serialNumber, $synced, true)) {
                $synced[] = $serialNumber;
                $enrollment->synced_devices = $synced;
            }

            $enrollment->save();
        } catch (Throwable $e) {
            Log::warning("NativeAdmsService: Failed upserting enrollment for {$controlNo}", [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Parse INFO parameter sent on GET /iclock/getrequest?SN=...&INFO=Ver,Users,FPs,Logs,IP,FPAlg,FaceAlg,FaceEnroll,Faces
     */
    private function updateDeviceInfoFromQuery(Request $request, ?BiometricDevice $device): void
    {
        if (! $device) {
            return;
        }

        $info = trim((string) ($request->query('INFO') ?: $request->input('INFO') ?: ''));
        if ($info === '') {
            return;
        }

        $parts = array_map('trim', explode(',', $info));
        $updates = [];

        if (! empty($parts[0])) {
            $updates['firmware_version'] = substr($parts[0], 0, 100);
        }
        if (isset($parts[1]) && is_numeric($parts[1])) {
            $updates['device_user_count'] = (int) $parts[1];
        }
        if (isset($parts[2]) && is_numeric($parts[2])) {
            $updates['device_finger_count'] = (int) $parts[2];
        }
        if (isset($parts[3]) && is_numeric($parts[3])) {
            $updates['device_log_count'] = (int) $parts[3];
        }
        if (isset($parts[8]) && is_numeric($parts[8])) {
            $updates['device_face_count'] = (int) $parts[8];
        } elseif (isset($parts[7]) && is_numeric($parts[7])) {
            $updates['device_face_count'] = (int) $parts[7];
        }

        if ($updates !== []) {
            try {
                $device->update($updates);
            } catch (Throwable) {
                // Ignore non-critical telemetry update error
            }
        }
    }

    /**
     * Parse and persist raw attendance logs (ATTLOG) pushed by the terminal.
     *
     * @return array{
     *     success: bool,
     *     table: string,
     *     imported: int,
     *     duplicates: int,
     *     response_text: string
     * }
     */
    private function processAttendanceLogs(?BiometricDevice $device, string $serialNumber, string $rawContent): array
    {
        if (! $device || ! $device->is_active) {
            Log::warning("NativeAdmsService: Rejected attendance push from unapproved or inactive terminal: {$serialNumber}");

            return [
                'success' => false,
                'table' => 'ATTLOG',
                'imported' => 0,
                'duplicates' => 0,
                'response_text' => 'OK',
            ];
        }

        $lines = preg_split('/\r\n|\r|\n/', trim($rawContent));
        if (empty($lines)) {
            return [
                'success' => true,
                'table' => 'ATTLOG',
                'imported' => 0,
                'duplicates' => 0,
                'response_text' => 'OK',
            ];
        }

        $imported = 0;
        $duplicates = 0;
        $totalValid = 0;
        $affectedDates = [];

        foreach ($lines as $rawLine) {
            $line = trim($rawLine);
            if ($line === '') {
                continue;
            }

            $punch = $this->parseAttlogLine($line);
            if ($punch === null) {
                continue;
            }

            $totalValid++;
            $pin = $punch['pin'];
            $punchTime = $punch['time'];
            $punchState = $punch['state'];
            $verifyType = $punch['verify_type'];
            $workCode = $punch['work_code'];

            $emp = HrisEmployee::findByControlNo($pin, true);
            $canonicalControlNo = $emp?->control_no ? (string) $emp->control_no : $pin;

            $exists = AttendanceRawLog::query()
                ->where(function ($q) use ($pin, $canonicalControlNo): void {
                    $q->where('biometric_pin', $pin)
                        ->orWhere('employee_control_no', $canonicalControlNo);
                })
                ->where('punch_time', $punchTime)
                ->exists();

            if ($exists) {
                $duplicates++;

                continue;
            }

            AttendanceRawLog::query()->create([
                'device_serial_number' => $serialNumber,
                'biometric_pin' => $pin,
                'employee_control_no' => $canonicalControlNo,
                'punch_time' => $punchTime,
                'punch_state' => $punchState,
                'verify_type' => $verifyType,
                'work_code' => $workCode,
                'sync_source' => 'ADMS',
                'raw_payload' => $line,
            ]);

            $imported++;
            $affectedDates[$canonicalControlNo][substr($punchTime, 0, 10)] = true;
        }

        if ($device && $imported > 0) {
            try {
                $device->increment('device_log_count', $imported);
                $device->update(['last_sync_at' => now()]);
            } catch (Throwable) {
                // Ignore device counter increment errors
            }
        }

        // Recalculate DTR for all affected employees and dates
        foreach ($affectedDates as $controlNo => $dates) {
            foreach (array_keys($dates) as $dateStr) {
                try {
                    $this->dtrService->calculateForEmployeeDate($controlNo, $dateStr);
                } catch (Throwable $e) {
                    Log::warning("NativeAdmsService: DTR recalculate error for {$controlNo} on {$dateStr}", [
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // ZKTeco protocol response: 'OK: <count>' tells the device all records were accepted
        return [
            'success' => true,
            'table' => 'ATTLOG',
            'imported' => $imported,
            'duplicates' => $duplicates,
            'response_text' => $totalValid > 0 ? "OK: {$totalValid}" : 'OK',
        ];
    }

    /**
     * Parse a single ATTLOG text line into structured punch components.
     * Supports tab-delimited, space-delimited, and comma-delimited ZKTeco formats.
     *
     * @return array{
     *     pin: string,
     *     time: string,
     *     state: int,
     *     verify_type: int,
     *     work_code: ?string
     * }|null
     */
    public function parseAttlogLine(string $line): ?array
    {
        // 1. Try tab-delimited (Standard ZKTeco ADMS format)
        $tokens = explode("\t", $line);
        if (count($tokens) < 2) {
            // 2. Try comma-delimited
            $tokens = explode(',', $line);
        }

        // 3. Fallback: split by multiple whitespace
        if (count($tokens) < 2) {
            $tokens = preg_split('/\s+/', $line) ?: [];
        }

        $tokens = array_values(array_map('trim', $tokens));
        if (empty($tokens)) {
            return null;
        }

        $pin = trim(preg_replace('/[^\x20-\x7E]/', '', (string) ($tokens[0] ?? '')));
        if ($pin === '') {
            return null;
        }

        // Extract Date and Time
        $dateTimeStr = null;
        $nextIdx = 1;

        if (isset($tokens[1])) {
            $cand = $tokens[1];
            // Format: YYYY-MM-DD HH:MM:SS
            if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}/', $cand)) {
                $dateTimeStr = str_replace('T', ' ', substr($cand, 0, 19));
                $nextIdx = 2;
            } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cand) && isset($tokens[2]) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $tokens[2])) {
                // Date and Time are in two separate tokens
                $dateTimeStr = "{$cand} {$tokens[2]}";
                $nextIdx = 3;
            }
        }

        if (! $dateTimeStr) {
            return null;
        }

        try {
            $parsedCarbon = Carbon::parse($dateTimeStr);
            $normalizedTime = $parsedCarbon->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }

        $state = isset($tokens[$nextIdx]) && is_numeric($tokens[$nextIdx]) ? (int) $tokens[$nextIdx] : 0;
        $verifyType = isset($tokens[$nextIdx + 1]) && is_numeric($tokens[$nextIdx + 1]) ? (int) $tokens[$nextIdx + 1] : 1;
        $workCode = isset($tokens[$nextIdx + 2]) && trim((string) $tokens[$nextIdx + 2]) !== ''
            ? trim((string) $tokens[$nextIdx + 2])
            : null;

        return [
            'pin' => $pin,
            'time' => $normalizedTime,
            'state' => $state,
            'verify_type' => $verifyType,
            'work_code' => $workCode,
        ];
    }

    /**
     * Update device heartbeat, online status, and IP address in tblBiometricDevices.
     * Auto-detects and inserts new terminals awaiting HR office assignment.
     */
    private function updateDeviceTelemetry(Request $request, string $serialNumber): ?BiometricDevice
    {
        $sn = trim($serialNumber);
        if ($sn === '') {
            return null;
        }

        $clientIp = (string) ($request->header('X-Forwarded-For') ?: $request->ip());
        if (str_contains($clientIp, ',')) {
            $clientIp = trim(explode(',', $clientIp)[0]);
        }

        try {
            $device = BiometricDevice::query()->where('serial_number', $sn)->first();

            if ($device) {
                $updates = [
                    'status' => BiometricDevice::STATUS_ONLINE,
                    'last_heartbeat_at' => now(),
                ];

                if (! empty($clientIp) && $clientIp !== '127.0.0.1' && $clientIp !== '::1') {
                    $updates['ip_address'] = $clientIp;
                }

                $pushVer = (string) ($request->query('pushver') ?: $request->input('pushver'));
                if (! empty($pushVer) && empty($device->firmware_version)) {
                    $updates['firmware_version'] = "Push v{$pushVer}";
                }

                $device->update($updates);

                return $device;
            }

            // Auto-detect new terminal knocking on ALMS
            return BiometricDevice::query()->create([
                'serial_number' => $sn,
                'device_name' => "Terminal {$sn}",
                'communication_mode' => 'ADMS',
                'status' => BiometricDevice::STATUS_ONLINE,
                'is_active' => false, // Awaiting HR office assignment & approval in ALMS
                'last_heartbeat_at' => now(),
                'ip_address' => ($clientIp !== '127.0.0.1' && $clientIp !== '::1') ? $clientIp : null,
                'port' => 4370,
                'comm_key' => '0',
                'model_name' => '',
            ]);
        } catch (Throwable $e) {
            Log::warning("NativeAdmsService: Telemetry update failed for SN {$sn}", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
