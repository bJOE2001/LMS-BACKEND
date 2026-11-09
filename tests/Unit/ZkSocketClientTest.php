<?php

namespace Tests\Unit;

use App\Services\Attendance\ZkSocketClient;
use Tests\TestCase;

class ZkSocketClientTest extends TestCase
{
    public function test_checksum_calculation(): void
    {
        $payload = pack('vvvv', 1000, 0, 0, 0);
        $checksum = ZkSocketClient::calculateChecksum($payload);

        $this->assertIsInt($checksum);
        $this->assertGreaterThanOrEqual(0, $checksum);
        $this->assertLessThanOrEqual(0xFFFF, $checksum);
    }

    public function test_create_packet_contains_tcp_header(): void
    {
        $client = new ZkSocketClient('127.0.0.1', 4370);
        $packet = $client->createPacket(ZkSocketClient::CMD_CONNECT);

        // TCP header magic: \x50\x50\x82\x7D
        $this->assertSame(ZkSocketClient::TCP_HEADER_MAGIC, substr($packet, 0, 4));

        // Total packet length must be >= 16 bytes (8 bytes TCP header + 8 bytes ZK payload)
        $this->assertGreaterThanOrEqual(16, strlen($packet));
    }

    public function test_decode_unix_timestamp(): void
    {
        $client = new ZkSocketClient('127.0.0.1', 4370);
        $unix = strtotime('2026-11-03 08:30:00');

        $decoded = $client->decodeTime($unix);
        $this->assertSame('2026-11-03 08:30:00', $decoded);
    }

    public function test_decode_invalid_timestamp_returns_null(): void
    {
        $client = new ZkSocketClient('127.0.0.1', 4370);

        $this->assertNull($client->decodeTime(0));
        $this->assertNull($client->decodeTime(-50));
    }

    public function test_parse_attendance_data_40_byte_format(): void
    {
        $client = new ZkSocketClient('127.0.0.1', 4370);

        // Build mock 40-byte record: 24 (pin) + 1 (state) + 1 (type) + 4 (timestamp) + 10 (workcode/reserved)
        $pin = str_pad("1002\x00", 24, "\x00");
        $state = chr(0); // Check-in
        $type = chr(15); // Face
        $timestamp = pack('V', strtotime('2026-11-03 07:45:00'));
        $workCode = str_pad("0\x00", 10, "\x00");
        $mockRecord = $pin.$state.$type.$timestamp.$workCode;

        $this->assertSame(40, strlen($mockRecord));

        $parsed = $client->parseAttendanceData($mockRecord);

        $this->assertCount(1, $parsed);
        $this->assertSame('1002', $parsed[0]['pin']);
        $this->assertSame('2026-11-03 07:45:00', $parsed[0]['time']);
        $this->assertSame(0, $parsed[0]['state']);
        $this->assertSame(15, $parsed[0]['type']);
    }

    public function test_offline_connection_fails_gracefully(): void
    {
        // Connecting to unreachable local port with 0.2s timeout
        $client = new ZkSocketClient('127.0.0.1', 4379, 0.2);
        $result = $client->testConnection();

        $this->assertFalse($result['success']);
        $this->assertNull($result['latency_ms']);
        $this->assertStringContainsString('Unable to connect', $result['message']);
    }

    public function test_make_comm_key_generates_scrambled_auth_token(): void
    {
        $token = ZkSocketClient::makeCommKey(0, 51420);

        $this->assertSame(4, strlen($token));
        $this->assertSame('617d32b1', bin2hex($token));
    }

    public function test_parse_attendance_data_with_uid_prefix(): void
    {
        $client = new ZkSocketClient('127.0.0.1', 4370);

        // 44-byte stream: 4 bytes payload size (40) + 2 (uid) + 24 (pin) + 1 (state) + 4 (timestamp) + 1 (type) + 8 (workcode)
        $header = pack('V', 40);
        $uid = pack('v', 1);
        $pin = str_pad("022936\x00", 24, "\x00");
        $state = chr(1);
        $timestamp = pack('V', strtotime('2026-11-03 08:30:00'));
        $type = chr(255);
        $workCode = str_repeat("\x00", 8);

        $payload = $header.$uid.$pin.$state.$timestamp.$type.$workCode;

        $parsed = $client->parseAttendanceData($payload);

        $this->assertCount(1, $parsed);
        $this->assertSame('022936', $parsed[0]['pin']);
        $this->assertSame('2026-11-03 08:30:00', $parsed[0]['time']);
        $this->assertSame(1, $parsed[0]['state']);
    }

    public function test_pack_and_parse_f7_user_records_28_and_72_bytes(): void
    {
        $client = new ZkSocketClient('127.0.0.1', 4370);

        $packed72 = $client->packUserRecord(3, '022936', 'JUAN DELA CRUZ', 0, '', 0, 72);
        $this->assertSame(72, strlen($packed72));

        $parsed72 = $client->parseUserRecords(pack('V', 72).$packed72);
        $this->assertCount(1, $parsed72);
        $this->assertSame(3, $parsed72[0]['uid']);
        $this->assertSame('022936', $parsed72[0]['pin']);
        $this->assertSame('JUAN DELA CRUZ', $parsed72[0]['name']);

        $packed28 = $client->packUserRecord(5, '22936', 'JUAN DC', 0, '', 0, 28);
        $this->assertSame(28, strlen($packed28));

        $parsed28 = $client->parseUserRecords(pack('V', 28).$packed28);
        $this->assertCount(1, $parsed28);
        $this->assertSame(5, $parsed28[0]['uid']);
        $this->assertSame('22936', $parsed28[0]['pin']);
        $this->assertSame('JUAN DC', $parsed28[0]['name']);
    }

    public function test_pack_and_parse_f7_fingerprint_template_records(): void
    {
        $client = new ZkSocketClient('127.0.0.1', 4370);
        $rawFpBytes = random_bytes(512);

        $record = $client->packFingerprintRecord(7, 2, $rawFpBytes, 1);
        $stream = pack('V', strlen($record)).$record;

        $parsed = $client->parseFingerprintTemplates($stream);
        $this->assertCount(1, $parsed);
        $this->assertSame(7, $parsed[0]['uid']);
        $this->assertSame(2, $parsed[0]['fid']);
        $this->assertSame(1, $parsed[0]['valid']);
        $this->assertSame(512, $parsed[0]['size']);
        $this->assertSame($rawFpBytes, $parsed[0]['template']);
    }
}
