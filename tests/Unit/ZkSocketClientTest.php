<?php

namespace Tests\Unit;

use App\Services\Attendance\ZkSocketClient;
use PHPUnit\Framework\TestCase;

class ZkSocketClientTest extends TestCase
{
    public function test_it_parses_40_byte_uid_prefixed_attendance_records_with_correct_verify_type_and_punch_state(): void
    {
        $client = new ZkSocketClient('127.0.0.1');

        // UID=12, PIN="022936", verify_type=1 (Fingerprint) at [26], timestamp=1794530020 at [27..30], punch_state=255 (Auto) at [31]
        $fingerprintAutoRecord = pack(
            'va24CVCa8',
            12,
            str_pad("022936\0", 24, "\0"),
            1,
            1794530020,
            255,
            str_repeat("\0", 8)
        );

        // UID=13, PIN="012733", verify_type=15 (Face) at [26], timestamp=1794530080 at [27..30], punch_state=0 (Check-In) at [31]
        $faceCheckInRecord = pack(
            'va24CVCa8',
            13,
            str_pad("012733\0", 24, "\0"),
            15,
            1794530080,
            0,
            str_repeat("\0", 8)
        );

        $records = $fingerprintAutoRecord.$faceCheckInRecord;
        $logs = $client->parseAttendanceData(pack('V', strlen($records)).$records);

        $this->assertCount(2, $logs);

        $this->assertSame('022936', $logs[0]['pin']);
        $this->assertSame(date('Y-m-d H:i:s', 1794530020), $logs[0]['time']);
        $this->assertSame(1, $logs[0]['type']);
        $this->assertSame(255, $logs[0]['state']);

        $this->assertSame('012733', $logs[1]['pin']);
        $this->assertSame(date('Y-m-d H:i:s', 1794530080), $logs[1]['time']);
        $this->assertSame(15, $logs[1]['type']);
        $this->assertSame(0, $logs[1]['state']);
    }
}
