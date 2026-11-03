<?php

namespace App\Services\Attendance;

use Exception;
use Illuminate\Support\Facades\Log;

/**
 * Low-level TCP socket client for ZKTeco biometric terminals.
 * Implements the standard ZK protocol over port 4370.
 */
class ZkSocketClient
{
    public const CMD_CONNECT = 1000;

    public const CMD_EXIT = 1001;

    public const CMD_ENABLEDEVICE = 1002;

    public const CMD_DISABLEDEVICE = 1003;

    public const CMD_RESTART = 1004;

    public const CMD_POWEROFF = 1005;

    public const CMD_ATTLOG_RRQ = 13;

    public const CMD_CLEAR_ATTLOG = 14;

    public const CMD_GET_TIME = 201;

    public const CMD_PREPARE_DATA = 1500;

    public const CMD_DATA = 1501;

    public const CMD_FREE_DATA = 1502;

    public const CMD_ACK_OK = 2000;

    public const CMD_ACK_ERROR = 2001;

    public const CMD_ACK_DATA = 2002;

    public const CMD_ACK_UNAUTH = 2005;

    public const TCP_HEADER_MAGIC = "\x50\x50\x82\x7D";

    /**
     * @var resource|null
     */
    protected $socket = null;

    protected int $sessionId = 0;

    protected int $replyId = 0;

    protected bool $isConnected = false;

    public function __construct(
        public readonly string $ip,
        public readonly int $port = 4370,
        public readonly float $timeout = 3.0
    ) {}

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
                return [
                    'success' => false,
                    'latency_ms' => null,
                    'message' => "Unable to connect to {$this->ip}:{$this->port} (Connection timed out or refused).",
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
        if ($this->isConnected && is_resource($this->socket)) {
            return true;
        }

        $errno = 0;
        $errstr = '';

        $socket = @fsockopen($this->ip, $this->port, $errno, $errstr, $this->timeout);
        if (! $socket) {
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
        if (! $resp || $resp['code'] !== self::CMD_ACK_OK) {
            $this->disconnect();

            return false;
        }

        $this->sessionId = $resp['session_id'];
        $this->isConnected = true;

        return true;
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

        while (strlen($buffer) < $expectedBytes && time() < $timeout) {
            $packet = $this->receivePacket();
            if (! $packet) {
                break;
            }

            if ($packet['code'] === self::CMD_DATA) {
                $buffer .= $packet['data'];
            } elseif ($packet['code'] === self::CMD_ACK_OK) {
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
     * 40-byte format:
     * - [0-23]  User PIN (null-padded string, up to 24 chars)
     * - [24]    Verify State (0=Check-In, 1=Check-Out, etc.)
     * - [25]    Verify Type (1=Finger, 15=Face, 4=Card, 0=Password)
     * - [26-29] Timestamp (Unix 32-bit or encoded format)
     * - [30-37] WorkCode
     *
     * @return array<int, array{pin: string, time: string, state: int, type: int, work_code: string|null}>
     */
    public function parseAttendanceData(string $data): array
    {
        $logs = [];
        $len = strlen($data);

        // Discard first 4 bytes if payload is prefixed with internal record size
        $offset = 0;
        if ($len >= 4 && (ord($data[0]) === 0x00 || ord($data[1]) === 0x00)) {
            $offset = 4;
        }

        // Determine record length (standard 40 bytes or legacy 14 bytes)
        $recordSize = 40;
        $remaining = $len - $offset;
        if ($remaining > 0 && ($remaining % 40 !== 0) && ($remaining % 14 === 0)) {
            $recordSize = 14;
        }

        while ($offset + $recordSize <= $len) {
            $record = substr($data, $offset, $recordSize);
            $offset += $recordSize;

            if ($recordSize === 40) {
                $pinRaw = substr($record, 0, 24);
                $pin = trim(explode("\x00", $pinRaw)[0]);

                $state = ord($record[24] ?? "\x00");
                $type = ord($record[25] ?? "\x00");

                $timeRaw = unpack('V', substr($record, 26, 4))[1] ?? 0;
                $formattedTime = $this->decodeTime($timeRaw);

                $workCodeRaw = trim(explode("\x00", substr($record, 30, 8))[0]);
                $workCode = $workCodeRaw !== '' ? $workCodeRaw : null;
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
        // second = $encoded % 60
        // minute = ($encoded / 60) % 60
        // hour   = ($encoded / 3600) % 24
        // day    = (($encoded / 86400) % 31) + 1
        // month  = (($encoded / (86400 * 31)) % 12) + 1
        // year   = ($encoded / (86400 * 31 * 12)) + 2000
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
}
