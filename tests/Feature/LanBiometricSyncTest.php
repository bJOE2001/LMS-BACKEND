<?php

namespace Tests\Feature;

use App\Models\AttendanceRawLog;
use App\Models\BiometricDevice;
use App\Models\HRAccount;
use App\Services\Attendance\DtrCalculationService;
use App\Services\Attendance\LanBioPunchSyncService;
use App\Services\Attendance\ZkSocketClient;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class LanBiometricSyncTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_lan_sync_imports_punches_and_sets_source(): void
    {
        $dtrService = Mockery::mock(DtrCalculationService::class);
        $dtrService->shouldReceive('calculateForEmployeeDate')->andReturnNull();

        $service = new LanBioPunchSyncService($dtrService);

        $device = new BiometricDevice([
            'device_name' => 'City Hall Lobby Terminal',
            'serial_number' => 'TEST_SN_LAN_001',
            'ip_address' => '192.168.1.100',
            'port' => 4370,
            'communication_mode' => 'LAN_POLL',
            'is_active' => true,
        ]);
        $device->id = 999;

        $mockClient = Mockery::mock(ZkSocketClient::class);
        $mockClient->shouldReceive('connect')->andReturn(true);
        $mockClient->shouldReceive('disconnect')->andReturnNull();
        $mockClient->shouldReceive('getAttendanceLogs')->andReturn([
            [
                'pin' => '99901',
                'time' => '2026-11-03 08:00:00',
                'state' => 0,
                'type' => 1,
                'work_code' => null,
            ],
            [
                'pin' => '99901',
                'time' => '2026-11-03 12:00:00',
                'state' => 1,
                'type' => 1,
                'work_code' => null,
            ],
        ]);

        // Cleanup any old test records
        AttendanceRawLog::query()->where('biometric_pin', '99901')->delete();

        $result = $service->syncDevice($device, $mockClient);

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['duplicates']);

        // Verify records in database
        $importedLogs = AttendanceRawLog::query()
            ->where('biometric_pin', '99901')
            ->orderBy('punch_time')
            ->get();

        $this->assertCount(2, $importedLogs);
        $this->assertSame('LAN_POLL', $importedLogs[0]->sync_source);
        $this->assertSame('TEST_SN_LAN_001', $importedLogs[0]->device_serial_number);

        // Second sync should report 2 duplicates and 0 new imports
        $result2 = $service->syncDevice($device, $mockClient);
        $this->assertSame(0, $result2['imported']);
        $this->assertSame(2, $result2['duplicates']);

        // Clean up
        AttendanceRawLog::query()->where('biometric_pin', '99901')->delete();
    }

    public function test_test_connection_requires_hr_authentication(): void
    {
        $response = $this->postJson('/api/attendance/devices/1/test-connection');
        $this->assertTrue(in_array($response->getStatusCode(), [401, 403], true));
    }

    public function test_sync_lan_fails_if_device_is_in_adms_mode(): void
    {
        $hr = new HRAccount([
            'username' => 'hr_admin_test',
            'full_name' => 'HR Tester',
            'must_change_password' => false,
        ]);
        $hr->id = 1;

        Sanctum::actingAs($hr);

        BiometricDevice::query()->where('serial_number', 'like', 'ADMS_MOCK_SN_%')->delete();

        $sn = 'ADMS_MOCK_SN_'.uniqid();
        $device = BiometricDevice::query()->create([
            'device_name' => 'Outside Office ADMS Terminal',
            'serial_number' => $sn,
            'communication_mode' => 'ADMS',
            'ip_address' => '192.168.1.150',
            'is_active' => true,
        ]);

        $response = $this->postJson("/api/attendance/devices/{$device->id}/sync-lan");

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'success' => false,
        ]);

        $device->delete();
    }
}
