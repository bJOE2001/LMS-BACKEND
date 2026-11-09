<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRawLog;
use App\Models\BiometricDevice;
use App\Models\HrisEmployee;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class NativeAdmsService
{
    public function __construct(
        private readonly DtrCalculationService $dtrService
    ) {}

    /**
     * Handle initial handshake from ZKTeco terminal (GET /iclock/cdata).
     */
    public function handleHandshake(Request $request, string $serialNumber): string
    {
        $this->updateDeviceTelemetry($request, $serialNumber);

        return "GET OPTION FROM: {$serialNumber}\r\n"
            ."Stamp=9999\r\n"
            ."OpStamp=9999\r\n"
            ."PhotoStamp=9999\r\n"
            ."ErrorDelay=30\r\n"
            ."Delay=10\r\n"
            ."TransTimes=00:00;14:05\r\n"
            ."TransInterval=1\r\n"
            ."TransFlag=1111000000\r\n"
            ."TimeZone=8\r\n"
            ."Realtime=1\r\n"
            ."Encrypt=0\r\n"
            ."ServerVersion=3.0.1\r\n";
    }

    /**
     * Handle incoming data pushes from ZKTeco terminal (POST /iclock/cdata).
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

        if ($table === 'ATTLOG') {
            return $this->processAttendanceLogs($device, $serialNumber, $rawContent);
        }

        // For OPERLOG, BIODATA, options or other operational pushes, acknowledge OK
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
        $this->updateDeviceTelemetry($request, $serialNumber);

        // When no commands are pending, ZKTeco protocol standard response is "OK"
        return 'OK';
    }

    /**
     * Handle terminal command execution response (POST /iclock/devicecmd).
     */
    public function handleDeviceCmd(Request $request, string $serialNumber): string
    {
        $this->updateDeviceTelemetry($request, $serialNumber);

        return 'OK';
    }

    /**
     * Handle terminal registration request (GET / POST /iclock/registry).
     */
    public function handleRegistry(Request $request, string $serialNumber): string
    {
        $this->updateDeviceTelemetry($request, $serialNumber);

        return "RegistryCode=OK\r\n";
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
                'model_name' => 'MB360',
            ]);
        } catch (Throwable $e) {
            Log::warning("NativeAdmsService: Telemetry update failed for SN {$sn}", [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
