<?php

namespace App\Services\Attendance;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Low-level TCP socket client for ZKTeco biometric terminals (F7 / standalone LAN devices).
 * Implements the standard ZK protocol over port 4370.
 */
class ZkSocketClient
{
    public const CMD_DB_RRQ = 7;

    public const CMD_USER_WRQ = 8;

    public const CMD_USERTEMP_RRQ = 9;

    public const CMD_USERTEMP_WRQ = 10;

    public const CMD_ATTLOG_RRQ = 13;

    public const CMD_CLEAR_ATTLOG = 14;

    public const CMD_DELETE_USER = 18;

    public const CMD_DELETE_USERTEMP = 19;

    public const CMD_TMP_WRITE = 87;

    public const CMD_GET_USERTEMP = 88;

    public const CMD_GET_TIME = 201;

    public const CMD_CONNECT = 1000;

    public const CMD_EXIT = 1001;

    public const CMD_ENABLEDEVICE = 1002;

    public const CMD_DISABLEDEVICE = 1003;

    public const CMD_RESTART = 1004;

    public const CMD_POWEROFF = 1005;

    public const CMD_REFRESHDATA = 1013;

    public const CMD_AUTH = 1102;

    public const CMD_PREPARE_DATA = 1500;

    public const CMD_DATA = 1501;

    public const CMD_FREE_DATA = 1502;

    public const CMD_PREPARE_BUFFER = 1503;

    public const CMD_READ_BUFFER = 1504;

    public const CMD_ACK_OK = 2000;

    public const CMD_ACK_ERROR = 2001;

    public const CMD_ACK_DATA = 2002;

    public const CMD_ACK_UNAUTH = 2005;

    public const FCT_FINGERTMP = 2;

    public const FCT_USER = 5;

    public const TCP_HEADER_MAGIC = "\x50\x50\x82\x7D";

    /**
     * @var resource|null
     */
    protected $socket = null;

    protected int $sessionId = 0;

    protected int $replyId = 0;

    protected bool $isConnected = false;

    protected ?string $lastError = null;

    protected int $detectedUserPacketSize = 72;

    public function __construct(
        public readonly string $ip,
        public readonly int $port = 4370,
        public readonly float $timeout = 3.0,
        public readonly int $commKey = 0
    ) {}

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function getDetectedUserPacketSize(): int
    {
        return $this->detectedUserPacketSize;
    }

    /**
     * Test basic reachability and handshake latency with the terminal.
     *
     * @return array{success: bool, latency_ms: int|null, message: string}
     */
    public function testConnection(): array
    {
        $startTime = microtime(true);

        try {
            if (! $this->connect()) {
                $reason = $this->lastError ?? 'Connection timed out or refused';

                return [
                    'success' => false,
                    'latency_ms' => null,
                    'message' => "Unable to connect to {$this->ip}:{$this->port} ({$reason}).",
                ];
            }

            $latency = (int) round((microtime(true) - $startTime) * 1000);
            $this->disconnect();

            return [
                'success' => true,
                'latency_ms' => $latency,
                'message' => "Successfully connected to {$this->ip}:{$this->port} ({$latency}ms).",
            ];
        } catch (Exception $e) {
            $this->disconnect();

            return [
                'success' => false,
                'latency_ms' => null,
                'message' => "Connection failed: {$e->getMessage()}",
            ];
        }
    }

    /**
     * Open TCP socket connection and complete ZK handshake.
     */
    public function connect(): bool
    {
        $this->lastError = null;

        if ($this->isConnected && is_resource($this->socket)) {
            return true;
        }

        $errno = 0;
        $errstr = '';

        $socket = @fsockopen($this->ip, $this->port, $errno, $errstr, $this->timeout);
        if (! $socket) {
            $this->lastError = $errstr !== '' ? "{$errstr} ({$errno})" : 'Connection timed out or refused';
            try {
                Log::debug("ZkSocketClient: Failed to connect to {$this->ip}:{$this->port} - {$errstr} ({$errno})");
            } catch (\Throwable) {
            }

            return false;
        }

        $this->socket = $socket;
        $sec = (int) floor($this->timeout);
        $usec = (int) (($this->timeout - $sec) * 1000000);
        stream_set_timeout($this->socket, $sec, $usec);

        $this->sessionId = 0;
        $this->replyId = 0;

        // Send CMD_CONNECT (1000)
        $resp = $this->sendCommand(self::CMD_CONNECT);
        if (! $resp) {
            $this->lastError = 'Terminal did not respond to connect handshake';
            $this->disconnect();

            return false;
        }

        if ($resp['code'] === self::CMD_ACK_OK) {
            $this->sessionId = $resp['session_id'];
            $this->isConnected = true;

            return true;
        }

        // Handle CMD_ACK_UNAUTH (2005) - Terminal requires Comm Key authentication
        if ($resp['code'] === self::CMD_ACK_UNAUTH) {
            $this->sessionId = $resp['session_id'];
            $authData = self::makeCommKey($this->commKey, $this->sessionId);
            $authResp = $this->sendCommand(self::CMD_AUTH, $authData);

            if ($authResp && $authResp['code'] === self::CMD_ACK_OK) {
                $this->isConnected = true;

                return true;
            }

            $this->lastError = 'Authentication failed (Invalid Comm Key)';
            $this->disconnect();

            return false;
        }

        $this->lastError = "Terminal returned unexpected handshake code ({$resp['code']})";
        $this->disconnect();

        return false;
    }

    /**
     * Disconnect cleanly from the terminal.
     */
    public function disconnect(): void
    {
        if ($this->isConnected && is_resource($this->socket)) {
            try {
                $this->sendCommand(self::CMD_EXIT);
            } catch (Exception) {
                // Ignore errors on exit
            }
        }

        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
        $this->isConnected = false;
        $this->sessionId = 0;
        $this->replyId = 0;
    }

    /**
     * Fetch user profiles stored on the standalone F7 / LAN terminal.
     *
     * @return array<int, array{uid: int, pin: string, name: string, privilege: int, password: string, card: int}>
     */
    public function getUsers(): array
    {
        if (! $this->isConnected && ! $this->connect()) {
            return [];
        }

        try {
            $this->sendCommand(self::CMD_DISABLEDEVICE);
            $rawPayload = $this->readBulkDataset(self::CMD_USERTEMP_RRQ, self::FCT_USER);
            $this->sendCommand(self::CMD_ENABLEDEVICE);

            if ($rawPayload === '') {
                return [];
            }

            return $this->parseUserRecords($rawPayload);
        } catch (Exception $e) {
            try {
                Log::warning("ZkSocketClient: Error reading users from {$this->ip}", ['error' => $e->getMessage()]);
            } catch (\Throwable) {
            }
            try {
                $this->sendCommand(self::CMD_ENABLEDEVICE);
            } catch (Exception) {
            }

            return [];
        }
    }

    /**
     * Fetch all fingerprint templates stored on the standalone F7 / LAN terminal.
     *
     * @return array<int, array{uid: int, fid: int, valid: int, size: int, template: string}>
     */
    public function getFingerprintTemplates(): array
    {
        if (! $this->isConnected && ! $this->connect()) {
            return [];
        }

        try {
            $this->sendCommand(self::CMD_DISABLEDEVICE);
            $rawPayload = $this->readBulkDataset(self::CMD_DB_RRQ, self::FCT_FINGERTMP);
            if ($rawPayload === '') {
                $rawPayload = $this->readBulkDataset(self::CMD_USERTEMP_RRQ, self::FCT_FINGERTMP);
            }
            $this->sendCommand(self::CMD_ENABLEDEVICE);

            if ($rawPayload === '') {
                return [];
            }

            return $this->parseFingerprintTemplates($rawPayload);
        } catch (Exception $e) {
            try {
                Log::warning("ZkSocketClient: Error reading fingerprint templates from {$this->ip}", ['error' => $e->getMessage()]);
            } catch (\Throwable) {
            }
            try {
                $this->sendCommand(self::CMD_ENABLEDEVICE);
            } catch (Exception) {
            }

            return [];
        }
    }

    /**
     * Write or update a user record on an F7 / LAN terminal.
     */
    public function setUser(
        int $uid,
        string $pin,
        string $name,
        int $privilege = 0,
        string $password = '',
        int $card = 0,
        ?int $packetSize = null
    ): bool {
        if (! $this->isConnected && ! $this->connect()) {
            return false;
        }

        $targetSize = $packetSize ?? $this->detectedUserPacketSize;
        $payload = $this->packUserRecord($uid, $pin, $name, $privilege, $password, $card, $targetSize);

        try {
            $this->sendCommand(self::CMD_DISABLEDEVICE);
            $resp = $this->sendCommand(self::CMD_USER_WRQ, $payload);

            // Fallback to 28-byte classic ZK6 structure if 72-byte rejected by older F7 firmware
            if ((! $resp || $resp['code'] !== self::CMD_ACK_OK) && $packetSize === null && $targetSize === 72) {
                $fallbackPayload = $this->packUserRecord($uid, $pin, $name, $privilege, $password, $card, 28);
                $resp = $this->sendCommand(self::CMD_USER_WRQ, $fallbackPayload);
                if ($resp && $resp['code'] === self::CMD_ACK_OK) {
                    $this->detectedUserPacketSize = 28;
                }
            }

            $this->sendCommand(self::CMD_REFRESHDATA);
            $this->sendCommand(self::CMD_ENABLEDEVICE);

            return (bool) ($resp && $resp['code'] === self::CMD_ACK_OK);
        } catch (Exception) {
            try {
                $this->sendCommand(self::CMD_ENABLEDEVICE);
            } catch (Exception) {
            }

            return false;
        }
    }

    /**
     * Upload a raw binary fingerprint template to a user's finger slot (0-9) on an F7 / LAN terminal.
     */
    public function setFingerprintTemplate(int $uid, int $fid, string $rawTemplateBytes, int $valid = 1): bool
    {
        if ($rawTemplateBytes === '' || (! $this->isConnected && ! $this->connect())) {
            return false;
        }

        $size = strlen($rawTemplateBytes);

        try {
            $this->sendCommand(self::CMD_DISABLEDEVICE);

            // Method 1: Buffered write (CMD_PREPARE_DATA -> CMD_DATA -> CMD_TMP_WRITE) for classic ZK6/ZK8 F7
            $prep = $this->sendCommand(self::CMD_PREPARE_DATA, pack('V', $size));
            $ok = false;

            if ($prep && in_array($prep['code'], [self::CMD_ACK_OK, self::CMD_PREPARE_DATA], true)) {
                $dataResp = $this->sendCommand(self::CMD_DATA, $rawTemplateBytes);
                if ($dataResp && in_array($dataResp['code'], [self::CMD_ACK_OK, self::CMD_ACK_DATA], true)) {
                    $writeMeta = pack('vCCv', $uid, $fid, $valid, $size);
                    $writeResp = $this->sendCommand(self::CMD_TMP_WRITE, $writeMeta);
                    $ok = (bool) ($writeResp && $writeResp['code'] === self::CMD_ACK_OK);
                }
            }

            // Method 2: Direct CMD_USERTEMP_WRQ (10) fallback
            if (! $ok) {
                $directPayload = $this->packFingerprintRecord($uid, $fid, $rawTemplateBytes, $valid);
                $directResp = $this->sendCommand(self::CMD_USERTEMP_WRQ, $directPayload);
                $ok = (bool) ($directResp && $directResp['code'] === self::CMD_ACK_OK);
            }

            $this->sendCommand(self::CMD_REFRESHDATA);
            $this->sendCommand(self::CMD_ENABLEDEVICE);

            return $ok;
        } catch (Exception) {
            try {
                $this->sendCommand(self::CMD_ENABLEDEVICE);
            } catch (Exception) {
            }

            return false;
        }
    }

    /**
     * Delete a user (and associated templates) from an F7 / LAN terminal by internal 2-byte UID.
     */
    public function deleteUser(int $uid): bool
    {
        if ($uid <= 0 || (! $this->isConnected && ! $this->connect())) {
            return false;
        }

        try {
            $this->sendCommand(self::CMD_DISABLEDEVICE);
            $resp = $this->sendCommand(self::CMD_DELETE_USER, pack('v', $uid));
            $this->sendCommand(self::CMD_REFRESHDATA);
            $this->sendCommand(self::CMD_ENABLEDEVICE);

            return (bool) ($resp && $resp['code'] === self::CMD_ACK_OK);
        } catch (Exception) {
            try {
                $this->sendCommand(self::CMD_ENABLEDEVICE);
            } catch (Exception) {
            }

            return false;
        }
    }

    /**
     * Delete a user from an F7 / LAN terminal by matching their employee PIN / Control Number.
     */
    public function deleteUserByPin(string $pin): bool
    {
        $normalizedTarget = ltrim(trim($pin), '0');
        $users = $this->getUsers();

        foreach ($users as $user) {
            $candidatePin = trim($user['pin']);
            if ($candidatePin === trim($pin) || ($normalizedTarget !== '' && ltrim($candidatePin, '0') === $normalizedTarget)) {
                return $this->deleteUser($user['uid']);
            }
        }

        return false;
    }

    /**
     * Parse raw binary user table into structured user records (auto-detects 28-byte ZK6 vs 72-byte ZK8).
     *
     * @return array<int, array{uid: int, pin: string, name: string, privilege: int, password: string, card: int}>
     */
    public function parseUserRecords(string $data): array
    {
        $len = strlen($data);
        if ($len < 28) {
            return [];
        }

        $offset = 0;
        $declaredSize = unpack('V', substr($data, 0, 4))[1] ?? 0;
        if ($declaredSize === $len - 4 || (($len - 4) > 0 && ((($len - 4) % 72 === 0) || (($len - 4) % 28 === 0)))) {
            $offset = 4;
        }

        $remaining = $len - $offset;
        if ($remaining < 28) {
            return [];
        }

        $packetSize = 72;
        if ($remaining % 72 === 0 && $remaining % 28 !== 0) {
            $packetSize = 72;
        } elseif ($remaining % 28 === 0 && $remaining % 72 !== 0) {
            $packetSize = 28;
        } elseif ($remaining < 72) {
            $packetSize = 28;
        }

        $this->detectedUserPacketSize = $packetSize;
        $users = [];

        while ($offset + $packetSize <= $len) {
            $chunk = substr($data, $offset, $packetSize);
            $offset += $packetSize;

            if ($packetSize === 28) {
                $uid = unpack('v', substr($chunk, 0, 2))[1] ?? 0;
                $privilege = ord($chunk[2] ?? "\x00");
                $password = trim(explode("\x00", substr($chunk, 3, 5))[0]);
                $name = trim(preg_replace('/[^\x20-\x7E]/', '', explode("\x00", substr($chunk, 8, 8))[0]) ?? '');
                $card = unpack('V', substr($chunk, 16, 4))[1] ?? 0;
                $pinInt = unpack('V', substr($chunk, 24, 4))[1] ?? 0;
                $pin = $pinInt > 0 ? (string) $pinInt : (string) $uid;
            } else {
                $uid = unpack('v', substr($chunk, 0, 2))[1] ?? 0;
                $privilege = ord($chunk[2] ?? "\x00");
                $password = trim(explode("\x00", substr($chunk, 3, 8))[0]);
                $name = trim(preg_replace('/[^\x20-\x7E]/', '', explode("\x00", substr($chunk, 11, 24))[0]) ?? '');
                $card = unpack('V', substr($chunk, 35, 4))[1] ?? 0;
                $pinRaw = trim(preg_replace('/[^\x20-\x7E]/', '', explode("\x00", substr($chunk, 48, 24))[0]) ?? '');
                $pin = $pinRaw !== '' ? $pinRaw : (string) $uid;
            }

            if ($uid > 0 && $pin !== '') {
                $users[] = [
                    'uid' => $uid,
                    'pin' => $pin,
                    'name' => $name,
                    'privilege' => $privilege,
                    'password' => $password,
                    'card' => $card,
                ];
            }
        }

        return $users;
    }

    /**
     * Pack a user record into 28-byte (classic F7 ZK6) or 72-byte (F7-C ZK8) binary layout.
     */
    public function packUserRecord(
        int $uid,
        string $pin,
        string $name,
        int $privilege = 0,
        string $password = '',
        int $card = 0,
        int $packetSize = 72
    ): string {
        $cleanName = trim(preg_replace('/[^\x20-\x7E]/', '', $name) ?? '');

        if ($packetSize === 28) {
            $digitsOnly = preg_replace('/\D/', '', $pin) ?? '';
            $numericPin = $digitsOnly !== '' ? (int) $digitsOnly : $uid;

            return pack(
                'vCa5a8VxCvV',
                $uid,
                $privilege,
                substr($password, 0, 5),
                substr($cleanName, 0, 8),
                $card,
                1,
                0,
                $numericPin
            );
        }

        return pack(
            'vCa8a24Va1a7xa24',
            $uid,
            $privilege,
            substr($password, 0, 8),
            substr($cleanName, 0, 24),
            $card,
            "\x00",
            '1',
            substr(trim($pin), 0, 24)
        );
    }

    /**
     * Parse raw binary fingerprint template stream (`<HHbb` 6-byte header + template bytes).
     *
     * @return array<int, array{uid: int, fid: int, valid: int, size: int, template: string}>
     */
    public function parseFingerprintTemplates(string $data): array
    {
        $len = strlen($data);
        if ($len < 6) {
            return [];
        }

        $offset = 0;
        while ($offset + 4 <= $len) {
            $declaredTotal = unpack('V', substr($data, $offset, 4))[1] ?? 0;
            if ($declaredTotal === ($len - $offset - 4)) {
                $offset += 4;
            } else {
                break;
            }
        }

        $templates = [];
        while ($offset + 6 <= $len) {
            $header = unpack('vsize/vuid/Cfid/Cvalid', substr($data, $offset, 6));
            $recordSize = (int) ($header['size'] ?? 0);
            $uid = (int) ($header['uid'] ?? 0);
            $fid = (int) ($header['fid'] ?? 0);
            $valid = (int) ($header['valid'] ?? 1);

            if ($recordSize <= 6 || $offset + $recordSize > $len) {
                break;
            }

            $rawTemplate = substr($data, $offset + 6, $recordSize - 6);
            $offset += $recordSize;

            if ($uid > 0 && $fid <= 9 && $rawTemplate !== '') {
                $templates[] = [
                    'uid' => $uid,
                    'fid' => $fid,
                    'valid' => $valid,
                    'size' => strlen($rawTemplate),
                    'template' => $rawTemplate,
                ];
            }
        }

        return $templates;
    }

    /**
     * Pack a single fingerprint template record with 6-byte ZK header (`size`, `uid`, `fid`, `valid`).
     */
    public function packFingerprintRecord(int $uid, int $fid, string $rawTemplate, int $valid = 1): string
    {
        $totalRecordSize = strlen($rawTemplate) + 6;

        return pack('vvCC', $totalRecordSize, $uid, $fid, $valid).$rawTemplate;
    }

    /**
     * Read bulk dataset (users or fingerprint templates) using buffered or direct ZK protocol.
     */
    protected function readBulkDataset(int $command, int $fct): string
    {
        // Try buffered read first (CMD_PREPARE_BUFFER = 1503) used by ZK6/ZK8 terminals
        $bufReq = pack('CvVV', 1, $command, $fct, 0);
        $resp = $this->sendCommand(self::CMD_PREPARE_BUFFER, $bufReq);

        if ($resp && $resp['code'] === self::CMD_ACK_DATA && strlen($resp['data']) >= 5) {
            return substr($resp['data'], 1);
        }

        if ($resp && $resp['code'] === self::CMD_DATA && strlen($resp['data']) >= 4) {
            return $resp['data'];
        }

        if ($resp && $resp['code'] === self::CMD_ACK_OK && strlen($resp['data']) >= 5) {
            $totalSize = unpack('V', substr($resp['data'], 1, 4))[1] ?? 0;
            if ($totalSize > 0) {
                $buffer = '';
                $offset = 0;
                $maxChunk = 16384;

                while ($offset < $totalSize) {
                    $readLen = min($maxChunk, $totalSize - $offset);
                    $chunkReq = $this->sendCommand(self::CMD_READ_BUFFER, pack('VV', $offset, $readLen));
                    if (! $chunkReq) {
                        break;
                    }

                    if ($chunkReq['code'] === self::CMD_PREPARE_DATA) {
                        $expected = unpack('V', substr($chunkReq['data'], 0, 4))[1] ?? $readLen;
                        $chunkData = $this->readDataStream($expected);
                    } else {
                        $chunkData = $chunkReq['data'];
                    }

                    if ($chunkData === '') {
                        break;
                    }

                    $buffer .= $chunkData;
                    $offset += strlen($chunkData);
                }

                $this->sendCommand(self::CMD_FREE_DATA);
                if ($buffer !== '') {
                    return pack('V', strlen($buffer)).$buffer;
                }
            }
        }

        // Fallback to direct command read (CMD_USERTEMP_RRQ / CMD_DB_RRQ)
        $directResp = $this->sendCommand($command, pack('C', $fct));
        if (! $directResp) {
            return '';
        }

        if (in_array($directResp['code'], [self::CMD_ACK_OK, self::CMD_ACK_DATA, self::CMD_DATA], true) && $directResp['data'] !== '') {
            return $directResp['data'];
        }

        if ($directResp['code'] === self::CMD_PREPARE_DATA) {
            $expectedBytes = unpack('V', substr($directResp['data'], 0, 4))[1] ?? 0;
            $stream = $this->readDataStream($expectedBytes);
            $this->sendCommand(self::CMD_FREE_DATA);

            return $stream;
        }

        return '';
    }

    /**
     * Fetch attendance records stored on the terminal.
     *
     * @return array<int, array{pin: string, time: string, state: int, type: int, work_code: string|null}>
     */
    public function getAttendanceLogs(): array
    {
        if (! $this->isConnected && ! $this->connect()) {
            return [];
        }

        $logs = [];

        try {
            // Optional: disable device during download to avoid concurrency issues
            $this->sendCommand(self::CMD_DISABLEDEVICE);

            $resp = $this->sendCommand(self::CMD_ATTLOG_RRQ);
            if (! $resp) {
                $this->sendCommand(self::CMD_ENABLEDEVICE);

                return [];
            }

            $rawPayload = '';

            if ($resp['code'] === self::CMD_ACK_OK && ! empty($resp['data'])) {
                $rawPayload = $resp['data'];
            } elseif ($resp['code'] === self::CMD_PREPARE_DATA) {
                // Read continuous data stream until CMD_ACK_OK
                $expectedBytes = unpack('V', substr($resp['data'], 0, 4))[1] ?? 0;
                $rawPayload = $this->readDataStream($expectedBytes);
                $this->sendCommand(self::CMD_FREE_DATA);
            }

            $this->sendCommand(self::CMD_ENABLEDEVICE);

            if ($rawPayload !== '') {
                $logs = $this->parseAttendanceData($rawPayload);
            }
        } catch (Exception $e) {
            try {
                Log::warning("ZkSocketClient: Error fetching attendance logs from {$this->ip}", ['error' => $e->getMessage()]);
            } catch (\Throwable) {
            }
            try {
                $this->sendCommand(self::CMD_ENABLEDEVICE);
            } catch (Exception) {
            }
        }

        return $logs;
    }

    /**
     * Read the binary data stream for bulk data transfers.
     */
    protected function readDataStream(int $expectedBytes): string
    {
        $buffer = '';
        $timeout = time() + 10;

        while (time() < $timeout) {
            $packet = $this->receivePacket();
            if (! $packet) {
                break;
            }

            if ($packet['code'] === self::CMD_DATA) {
                $buffer .= $packet['data'];
            } elseif ($packet['code'] === self::CMD_ACK_OK) {
                break;
            }

            // If we have received at least 4 bytes, check the internal record payload size
            if (strlen($buffer) >= 4) {
                $internalSize = unpack('V', substr($buffer, 0, 4))[1] ?? 0;
                if ($internalSize > 0 && strlen($buffer) >= $internalSize + 4) {
                    break;
                }
            }

            // Check if buffer covers expectedBytes
            if ($expectedBytes > 0 && (strlen($buffer) >= $expectedBytes || strlen($buffer) + 8 >= $expectedBytes)) {
                break;
            }
        }

        return $buffer;
    }

    /**
     * Parse raw binary attendance log stream into structured array.
     *
     * Supports both standard 40-byte record format and 14-byte legacy record format.
     *
     * 40-byte format with UID prefix:
     * - [0-1]   UID (2 bytes unsigned short)
     * - [2-25]  User PIN (null-padded string, up to 24 chars)
     * - [26]    Verify State (0=Check-In, 1=Check-Out, etc.)
     * - [27-30] Timestamp (Unix 32-bit or encoded format)
     * - [31]    Verify Type (1=Finger, 15=Face, 4=Card, 0=Password)
     * - [32-39] WorkCode
     *
     * @return array<int, array{pin: string, time: string, state: int, type: int, work_code: string|null}>
     */
    public function parseAttendanceData(string $data): array
    {
        $logs = [];
        $len = strlen($data);

        // Discard first 4 bytes if payload is prefixed with internal record size
        $offset = 0;
        if ($len >= 4) {
            $internalSize = unpack('V', substr($data, 0, 4))[1] ?? 0;
            if ($internalSize === $len - 4 || ($len % 40 === 4) || ($len % 14 === 4) || (ord($data[0]) === 0x00 || ord($data[1]) === 0x00)) {
                $offset = 4;
            }
        }

        // Determine record length (standard 40 bytes or legacy 14 bytes)
        $recordSize = 40;
        $remaining = $len - $offset;
        if ($remaining > 0 && ($remaining % 40 !== 0) && ($remaining % 14 === 0)) {
            $recordSize = 14;
        }

        // For 40-byte format, check whether the payload uses 2-byte UID prefix
        $hasUidPrefix = false;
        if ($recordSize === 40 && $remaining >= 40) {
            $testRecord = substr($data, $offset, 40);
            if (($testRecord[24] === "\x00" && $testRecord[25] === "\x00" && ord($testRecord[26]) <= 15)
                || ($testRecord[1] === "\x00" && ctype_alnum($testRecord[2]) && ord($testRecord[26]) <= 15)) {
                $hasUidPrefix = true;
            }
        }

        while ($offset + $recordSize <= $len) {
            $record = substr($data, $offset, $recordSize);
            $offset += $recordSize;

            if ($recordSize === 40) {
                if ($hasUidPrefix) {
                    $pinRaw = substr($record, 2, 24);
                    $state = ord($record[26] ?? "\x00");
                    $timeRaw = unpack('V', substr($record, 27, 4))[1] ?? 0;
                    $type = ord($record[31] ?? "\x00");
                    $workCodeRaw = substr($record, 32, 8);
                } else {
                    $pinRaw = substr($record, 0, 24);
                    $state = ord($record[24] ?? "\x00");
                    $type = ord($record[25] ?? "\x00");
                    $timeRaw = unpack('V', substr($record, 26, 4))[1] ?? 0;
                    $workCodeRaw = substr($record, 30, 8);
                }

                $pinClean = trim(preg_replace('/[^\x20-\x7E]/', '', explode("\x00", $pinRaw)[0]));
                $pin = mb_convert_encoding($pinClean, 'UTF-8', 'UTF-8');

                $formattedTime = $this->decodeTime($timeRaw);

                $workClean = trim(preg_replace('/[^\x20-\x7E]/', '', explode("\x00", $workCodeRaw)[0]));
                $workCode = $workClean !== '' ? mb_convert_encoding($workClean, 'UTF-8', 'UTF-8') : null;
            } else {
                // 14-byte legacy format
                $pin = (string) (unpack('v', substr($record, 0, 2))[1] ?? 0);
                $timeRaw = unpack('V', substr($record, 4, 4))[1] ?? 0;
                $formattedTime = $this->decodeTime($timeRaw);
                $state = ord($record[8] ?? "\x00");
                $type = ord($record[9] ?? "\x00");
                $workCode = null;
            }

            if ($pin !== '' && $formattedTime !== null) {
                $logs[] = [
                    'pin' => $pin,
                    'time' => $formattedTime,
                    'state' => $state,
                    'type' => $type,
                    'work_code' => $workCode,
                ];
            }
        }

        return $logs;
    }

    /**
     * Decode ZKTeco encoded integer timestamp or Unix timestamp to YYYY-MM-DD HH:MM:SS.
     */
    public function decodeTime(int $encoded): ?string
    {
        if ($encoded <= 0) {
            return null;
        }

        // Check if standard Unix timestamp (e.g. between year 2020 and 2040)
        if ($encoded >= 1577836800 && $encoded <= 2208988800) {
            return date('Y-m-d H:i:s', $encoded);
        }

        // ZKTeco bit-packed timestamp format:
        $second = $encoded % 60;
        $minute = (int) floor($encoded / 60) % 60;
        $hour = (int) floor($encoded / 3600) % 24;
        $day = ((int) floor($encoded / 86400) % 31) + 1;
        $month = ((int) floor($encoded / (86400 * 31)) % 12) + 1;
        $year = (int) floor($encoded / (86400 * 31 * 12)) + 2000;

        if ($year < 2000 || $year > 2099 || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }

        return sprintf('%04d-%02d-%02d %02d:%02d:%02d', $year, $month, $day, $hour, $minute, $second);
    }

    /**
     * Send command packet to terminal and return reply.
     *
     * @return array{code: int, session_id: int, reply_id: int, data: string}|null
     */
    protected function sendCommand(int $command, string $data = ''): ?array
    {
        if (! is_resource($this->socket)) {
            return null;
        }

        $packet = $this->createPacket($command, $data);
        $written = @fwrite($this->socket, $packet);

        if ($written === false || $written < strlen($packet)) {
            return null;
        }

        return $this->receivePacket();
    }

    /**
     * Construct binary packet with 8-byte TCP header and checksum.
     */
    public function createPacket(int $command, string $data = ''): string
    {
        $payload = pack('vvvv', $command, 0, $this->sessionId, $this->replyId).$data;
        $checksum = self::calculateChecksum($payload);
        $payload = pack('vvvv', $command, $checksum, $this->sessionId, $this->replyId).$data;

        // 8-byte TCP Header: Magic (4 bytes) + Length (4 bytes, little-endian)
        $length = strlen($payload);

        return self::TCP_HEADER_MAGIC.pack('V', $length).$payload;
    }

    /**
     * Read and unpack a single packet from the TCP socket.
     *
     * @return array{code: int, session_id: int, reply_id: int, data: string}|null
     */
    protected function receivePacket(): ?array
    {
        if (! is_resource($this->socket)) {
            return null;
        }

        // Read 8-byte TCP Header
        $header = @fread($this->socket, 8);
        if ($header === false || strlen($header) < 8) {
            return null;
        }

        // Verify TCP Magic header: \x50\x50\x82\x7D
        if (substr($header, 0, 4) !== self::TCP_HEADER_MAGIC) {
            return null;
        }

        $length = unpack('V', substr($header, 4, 4))[1] ?? 0;
        if ($length < 8) {
            return null;
        }

        // Read packet body
        $body = '';
        $bytesRemaining = $length;
        while ($bytesRemaining > 0) {
            $chunk = @fread($this->socket, min($bytesRemaining, 4096));
            if ($chunk === false || $chunk === '') {
                break;
            }
            $body .= $chunk;
            $bytesRemaining -= strlen($chunk);
        }

        if (strlen($body) < 8) {
            return null;
        }

        $unpacked = unpack('vcode/vchecksum/vsession_id/vreply_id', substr($body, 0, 8));
        $data = substr($body, 8);

        $this->replyId = ($unpacked['reply_id'] ?? 0) + 1;

        return [
            'code' => $unpacked['code'] ?? 0,
            'session_id' => $unpacked['session_id'] ?? 0,
            'reply_id' => $unpacked['reply_id'] ?? 0,
            'data' => $data,
        ];
    }

    /**
     * Standard ZKTeco 16-bit one's complement checksum.
     */
    public static function calculateChecksum(string $packet): int
    {
        $sum = 0;
        $len = strlen($packet);

        for ($i = 0; $i < $len; $i += 2) {
            if ($i + 1 < $len) {
                $word = ord($packet[$i]) + (ord($packet[$i + 1]) << 8);
            } else {
                $word = ord($packet[$i]);
            }

            $sum += $word;
            while ($sum > 0xFFFF) {
                $sum = ($sum & 0xFFFF) + ($sum >> 16);
            }
        }

        return (~$sum) & 0xFFFF;
    }

    /**
     * Scramble communication key and session ID for terminal authentication (CMD_AUTH).
     * Ported from standard ZKTeco protocol MakeKey (commpro.c / pyzk).
     */
    public static function makeCommKey(int $key, int $sessionId, int $ticks = 50): string
    {
        $k = 0;
        for ($i = 0; $i < 32; $i++) {
            if ($key & (1 << $i)) {
                $k = ($k << 1) | 1;
            } else {
                $k = $k << 1;
            }
        }
        $k = ($k + $sessionId) & 0xFFFFFFFF;

        $packed = pack('V', $k);
        $bytes = array_values(unpack('C4', $packed));

        $bytes[0] ^= ord('Z');
        $bytes[1] ^= ord('K');
        $bytes[2] ^= ord('S');
        $bytes[3] ^= ord('O');

        $packed2 = pack('C4', ...$bytes);
        $words = array_values(unpack('v2', $packed2));
        $swapped = pack('v2', $words[1], $words[0]);

        $b = 0xFF & $ticks;
        $finalBytes = array_values(unpack('C4', $swapped));
        $finalBytes[0] ^= $b;
        $finalBytes[1] ^= $b;
        $finalBytes[2] = $b;
        $finalBytes[3] ^= $b;

        return pack('C4', ...$finalBytes);
    }
}
