<?php

namespace Tests\Feature;

use App\Models\AttendanceRawLog;
use App\Models\DailyTimeRecord;
use App\Services\Attendance\ZkBioPunchSyncService;
use Tests\TestCase;

class ZkBioPunchSyncTest extends TestCase
{
    public function test_sync_punches_is_idempotent_and_updates_dtr(): void
    {
        $service = app(ZkBioPunchSyncService::class);

        // First run or repeated run
        $firstCount = $service->syncPunches(100);
        $secondCount = $service->syncPunches(100);

        // Second run should import 0 because everything is already synchronized
        $this->assertEquals(0, $secondCount);

        // Verify that today's punch for 022936 exists in AttendanceRawLog
        $rawLog = AttendanceRawLog::query()
            ->where('biometric_pin', '022936')
            ->whereDate('punch_time', '2026-10-08')
            ->first();

        $this->assertNotNull($rawLog);
        $this->assertEquals('022936', $rawLog->employee_control_no);

        // Verify that today's DTR record was computed for 022936
        $dtr = DailyTimeRecord::query()
            ->where('employee_control_no', '022936')
            ->where('record_date', '2026-10-08')
            ->first();

        $this->assertNotNull($dtr);
        $this->assertNotNull($dtr->am_arrival);
    }
}
