<?php

namespace Tests\Feature;

use App\Models\AttendanceRawLog;
use App\Models\BiometricDevice;
use Tests\TestCase;

class NativeAdmsPushTest extends TestCase
{
    private string $testSn;

    private string $testPin = '99988';

    protected function setUp(): void
    {
        parent::setUp();
        $this->testSn = 'NATIVE_ADMS_SN_'.uniqid();
        AttendanceRawLog::query()->where('biometric_pin', $this->testPin)->delete();
    }

    protected function tearDown(): void
    {
        AttendanceRawLog::query()->where('biometric_pin', $this->testPin)->delete();
        BiometricDevice::query()->where('serial_number', $this->testSn)->delete();
        parent::tearDown();
    }

    public function test_handshake_returns_zkteco_options_and_marks_device_online(): void
    {
        $response = $this->get('/iclock/cdata?SN='.$this->testSn);

        $response->assertStatus(200);
        $response->assertSee('GET OPTION FROM: '.$this->testSn);
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));

        $device = BiometricDevice::query()->where('serial_number', $this->testSn)->first();
        $this->assertNotNull($device);
        $this->assertSame('ONLINE', $device->status);
        $this->assertSame('ADMS', $device->communication_mode);
        $this->assertNotNull($device->last_heartbeat_at);
    }

    public function test_getrequest_returns_ok_and_updates_heartbeat(): void
    {
        $response = $this->get('/iclock/getrequest?SN='.$this->testSn);

        $response->assertStatus(200);
        $response->assertSee('OK');

        $device = BiometricDevice::query()->where('serial_number', $this->testSn)->first();
        $this->assertNotNull($device);
        $this->assertSame('ONLINE', $device->status);
    }

    public function test_attlog_post_ingests_punches_and_deduplicates(): void
    {
        $rawAttlog = "{$this->testPin}\t2026-11-06 08:00:00\t0\t1\t0\t0\r\n"
            ."{$this->testPin}\t2026-11-06 12:00:00\t1\t15\t0\t0\r\n";

        // 1. Initial push of 2 records
        $response = $this->call(
            'POST',
            '/iclock/cdata?SN='.$this->testSn.'&table=ATTLOG',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $rawAttlog
        );

        $response->assertStatus(200);
        $response->assertSee('OK: 2');

        $logs = AttendanceRawLog::query()
            ->where('biometric_pin', $this->testPin)
            ->where('device_serial_number', $this->testSn)
            ->orderBy('punch_time')
            ->get();

        $this->assertCount(2, $logs);
        $this->assertSame('ADMS', $logs[0]->sync_source);
        $this->assertSame(0, $logs[0]->punch_state);
        $this->assertSame(1, $logs[0]->verify_type);
        $this->assertSame(1, $logs[1]->punch_state);
        $this->assertSame(15, $logs[1]->verify_type);

        // 2. Resending same batch should not duplicate records, but returns OK: 2 so terminal marks batch complete
        $response2 = $this->call(
            'POST',
            '/iclock/cdata?SN='.$this->testSn.'&table=ATTLOG',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $rawAttlog
        );

        $response2->assertStatus(200);
        $response2->assertSee('OK: 2');

        $logsAfterDuplicate = AttendanceRawLog::query()
            ->where('biometric_pin', $this->testPin)
            ->where('device_serial_number', $this->testSn)
            ->count();

        $this->assertSame(2, $logsAfterDuplicate);
    }

    public function test_devicecmd_and_registry_endpoints(): void
    {
        $cmdResponse = $this->post('/iclock/devicecmd?SN='.$this->testSn, [
            'ID' => '1',
            'Return' => '0',
            'CMD' => 'INFO',
        ]);
        $cmdResponse->assertStatus(200);
        $cmdResponse->assertSee('OK');

        $regResponse = $this->get('/iclock/registry?SN='.$this->testSn);
        $regResponse->assertStatus(200);
        $regResponse->assertSee('RegistryCode=OK');
    }
}
