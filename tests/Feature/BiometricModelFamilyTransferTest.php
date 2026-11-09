<?php

namespace Tests\Feature;

use App\Models\BiometricDevice;
use App\Models\BiometricDeviceCommand;
use App\Models\BiometricEnrollment;
use App\Models\BiometricTemplate;
use App\Models\BiometricTransferLog;
use App\Services\Attendance\BiometricTransferService;
use App\Services\Attendance\LanBioTemplateSyncService;
use App\Services\Attendance\ZkSocketClient;
use Mockery;
use Tests\TestCase;

class BiometricModelFamilyTransferTest extends TestCase
{
    private string $testPin = '998771';

    private string $chrmoAdmsSn;

    private string $officeAdmsSn;

    private string $chrmoLanSn;

    private string $officeLanSn;

    private int $admsDeptId = 99101;

    private int $lanDeptId = 99102;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = uniqid();
        $this->chrmoAdmsSn = 'CHRMO_ADMS_'.$suffix;
        $this->officeAdmsSn = 'OFFICE_ADMS_'.$suffix;
        $this->chrmoLanSn = 'CHRMO_LAN_'.$suffix;
        $this->officeLanSn = 'OFFICE_LAN_'.$suffix;

        $this->cleanupTestRecords();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestRecords();
        Mockery::close();
        parent::tearDown();
    }

    private function cleanupTestRecords(): void
    {
        BiometricTemplate::query()->where('employee_control_no', $this->testPin)->delete();
        BiometricDeviceCommand::query()->where('employee_control_no', $this->testPin)->delete();
        BiometricEnrollment::query()->where('employee_control_no', $this->testPin)->delete();
        BiometricTransferLog::query()->where('employee_control_no', $this->testPin)->delete();
        BiometricDevice::query()->whereIn('serial_number', [
            $this->chrmoAdmsSn,
            $this->officeAdmsSn,
            $this->chrmoLanSn,
            $this->officeLanSn,
        ])->delete();
    }

    public function test_adms_to_adms_transfers_zkfinger_vx10_and_zkface_vx7_subtemplates_via_adms(): void
    {
        BiometricDevice::query()->create([
            'device_name' => 'CHRMO Enroll Terminal',
            'serial_number' => $this->chrmoAdmsSn,
            'communication_mode' => 'ADMS',
            'department_id' => 99100,
            'is_active' => true,
            'is_primary' => true,
        ]);

        BiometricDevice::query()->create([
            'device_name' => 'Target Office Terminal',
            'serial_number' => $this->officeAdmsSn,
            'communication_mode' => 'ADMS',
            'department_id' => $this->admsDeptId,
            'is_active' => true,
            'is_primary' => true,
        ]);

        // 1. Simulate ADMS terminal pushing ZKFinger VX10.0 (FID 0 & 1) + ZKFace VX7.0 sub-templates (FID 0, 1, 2)
        $operlogPayload = implode("\r\n", [
            "USER PIN={$this->testPin}\tName=MARIA SANTOS\tPri=0\tPasswd=\tCard=\tGrp=1",
            "FP PIN={$this->testPin}\tFID=0\tSize=24\tValid=1\tTMP=VlhxMF9GUF9URU1QTEFURV8w",
            "FP PIN={$this->testPin}\tFID=1\tSize=24\tValid=1\tTMP=VlhxMF9GUF9URU1QTEFURV8x",
            "FACE PIN={$this->testPin}\tFID=0\tSIZE=24\tVALID=1\tTMP=WktGQUNFX1ZYN19TVUJfMA==",
            "FACE PIN={$this->testPin}\tFID=1\tSIZE=24\tVALID=1\tTMP=WktGQUNFX1ZYN19TVUJfMQ==",
            "FACE PIN={$this->testPin}\tFID=2\tSIZE=24\tVALID=1\tTMP=WktGQUNFX1ZYN19TVUJfMg==",
        ]);

        $pushResp = $this->call(
            'POST',
            "/iclock/cdata?SN={$this->chrmoAdmsSn}&table=OPERLOG",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $operlogPayload
        );

        $pushResp->assertStatus(200);
        $pushResp->assertSee('OK: 6');

        // Verify all 5 templates stored under ADMS communication_mode with no hardcoded model name
        $storedTemplates = BiometricTemplate::query()
            ->where('employee_control_no', $this->testPin)
            ->where('communication_mode', BiometricTemplate::MODE_ADMS)
            ->get();
        $this->assertCount(5, $storedTemplates);

        $enrollment = BiometricEnrollment::query()->where('employee_control_no', $this->testPin)->first();
        $this->assertNotNull($enrollment);
        $this->assertSame(BiometricEnrollment::STATUS_REGISTERED, $enrollment->status);

        // 2. Transfer employee to Target Office ADMS terminal
        $transferService = app(BiometricTransferService::class);
        $result = $transferService->transferOnDepartmentAssignment(
            $this->testPin,
            $this->admsDeptId,
            null,
            99100
        );

        $this->assertTrue($result['transferred']);
        $this->assertSame(5, $result['templates_transferred']);
        $this->assertFalse($result['requires_local_scan']);

        // Source ADMS terminal must have DATA DELETE USERINFO queued
        $deleteCmd = BiometricDeviceCommand::query()
            ->where('device_serial_number', $this->chrmoAdmsSn)
            ->where('employee_control_no', $this->testPin)
            ->first();
        $this->assertNotNull($deleteCmd);
        $this->assertStringContainsString("DATA DELETE USERINFO PIN={$this->testPin}", $deleteCmd->command_payload);

        // Target ADMS terminal polls /iclock/getrequest (batched max 5 per poll, pure LF, no Set-Cookie)
        $poll1 = $this->get("/iclock/getrequest?SN={$this->officeAdmsSn}");
        $poll1->assertStatus(200);
        $poll1Body = $poll1->getContent();
        $this->assertStringNotContainsString("\r", $poll1Body);
        $this->assertStringContainsString("DATA UPDATE USERINFO PIN={$this->testPin}", $poll1Body);
        $this->assertStringContainsString("DATA UPDATE FACE PIN={$this->testPin}\tFID=0\tSize=", $poll1Body);

        $poll2 = $this->get("/iclock/getrequest?SN={$this->officeAdmsSn}");
        $poll2->assertStatus(200);
        $this->assertStringNotContainsString("\r", $poll2->getContent());
        $this->assertStringContainsString("DATA UPDATE FINGERTMP PIN={$this->testPin}", $poll2->getContent());

        // Acknowledge command execution via POST /iclock/devicecmd
        $sentCmds = BiometricDeviceCommand::query()
            ->where('device_serial_number', $this->officeAdmsSn)
            ->where('employee_control_no', $this->testPin)
            ->get();
        $this->assertCount(6, $sentCmds);

        $ackLines = $sentCmds->map(fn ($c) => "ID={$c->id}&Return=0&CMD=DATA")->implode("\n");
        $ackResp = $this->call(
            'POST',
            "/iclock/devicecmd?SN={$this->officeAdmsSn}",
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $ackLines
        );
        $ackResp->assertStatus(200);
        $ackResp->assertSee('OK');

        $pendingLeft = BiometricDeviceCommand::query()
            ->where('device_serial_number', $this->officeAdmsSn)
            ->where('employee_control_no', $this->testPin)
            ->where('status', '!=', BiometricDeviceCommand::STATUS_SUCCESS)
            ->count();
        $this->assertSame(0, $pendingLeft);
    }

    public function test_lan_to_lan_pulls_and_pushes_fingerprint_templates_over_lan_socket(): void
    {
        $sourceLan = BiometricDevice::query()->create([
            'device_name' => 'CHRMO LAN Scanner',
            'serial_number' => $this->chrmoLanSn,
            'communication_mode' => 'LAN_POLL',
            'ip_address' => '192.168.1.210',
            'port' => 4370,
            'department_id' => 99100,
            'is_active' => true,
            'is_primary' => true,
        ]);

        $targetLan = BiometricDevice::query()->create([
            'device_name' => 'Engineering LAN Scanner',
            'serial_number' => $this->officeLanSn,
            'communication_mode' => 'LAN_POLL',
            'ip_address' => '192.168.1.211',
            'port' => 4370,
            'department_id' => $this->lanDeptId,
            'is_active' => true,
            'is_primary' => true,
        ]);

        $rawFpBytes = 'RAW_LAN_FINGER_BINARY_PAYLOAD_BYTES';

        // Step 1: Pull template from source LAN device into BIO_DB under communication_mode = 'LAN_POLL'
        $mockSourceSocket = Mockery::mock(ZkSocketClient::class);
        $mockSourceSocket->shouldReceive('connect')->once()->andReturn(true);
        $mockSourceSocket->shouldReceive('getUsers')->once()->andReturn([
            ['uid' => 12, 'pin' => $this->testPin, 'name' => 'PEDRO', 'privilege' => 0, 'password' => '', 'card' => 0],
        ]);
        $mockSourceSocket->shouldReceive('getFingerprintTemplates')->once()->andReturn([
            ['uid' => 12, 'fid' => 6, 'valid' => 1, 'size' => strlen($rawFpBytes), 'template' => $rawFpBytes],
        ]);

        $lanTplService = new LanBioTemplateSyncService;
        $pullResult = $lanTplService->pullTemplatesFromDevice($sourceLan, $this->testPin, $mockSourceSocket);

        $this->assertTrue($pullResult['success']);
        $this->assertSame(1, $pullResult['templates_saved']);
        $this->assertSame(
            1,
            BiometricTemplate::query()
                ->where('employee_control_no', $this->testPin)
                ->where('communication_mode', BiometricTemplate::MODE_LAN_POLL)
                ->count()
        );

        // Step 2: Push cached LAN template to target LAN device
        $mockTargetSocket = Mockery::mock(ZkSocketClient::class);
        $mockTargetSocket->shouldReceive('connect')->once()->andReturn(true);
        $mockTargetSocket->shouldReceive('getUsers')->once()->andReturn([]);
        $mockTargetSocket->shouldReceive('setUser')
            ->once()
            ->with(1, $this->testPin, 'PEDRO PENDUKO', 0, '', 0)
            ->andReturn(true);
        $mockTargetSocket->shouldReceive('setFingerprintTemplate')
            ->once()
            ->with(1, 6, $rawFpBytes, 1)
            ->andReturn(true);

        $pushResult = $lanTplService->pushEmployeeToDevice($targetLan, $this->testPin, 'PEDRO PENDUKO', true, $mockTargetSocket);

        $this->assertTrue($pushResult['success']);
        $this->assertTrue($pushResult['user_pushed']);
        $this->assertSame(1, $pushResult['templates_pushed']);
    }

    public function test_cross_protocol_isolation_never_sends_adms_templates_to_lan_or_vice_versa(): void
    {
        $targetLan = BiometricDevice::query()->create([
            'device_name' => 'Target Office LAN Scanner',
            'serial_number' => $this->officeLanSn,
            'communication_mode' => 'LAN_POLL',
            'ip_address' => '192.168.1.222',
            'port' => 4370,
            'department_id' => $this->lanDeptId,
            'is_active' => true,
            'is_primary' => true,
        ]);

        // Employee only has ADMS (ZKFinger VX10.0 + ZKFace VX7.0) templates
        BiometricTemplate::query()->create([
            'employee_control_no' => $this->testPin,
            'communication_mode' => BiometricTemplate::MODE_ADMS,
            'biometric_type' => BiometricTemplate::TYPE_FINGERPRINT,
            'finger_id' => 0,
            'template_size' => 20,
            'valid' => 1,
            'template_version' => '10',
            'template_data' => base64_encode('ADMS_VX10_FP'),
        ]);
        BiometricTemplate::query()->create([
            'employee_control_no' => $this->testPin,
            'communication_mode' => BiometricTemplate::MODE_ADMS,
            'biometric_type' => BiometricTemplate::TYPE_FACE,
            'finger_id' => 0,
            'template_size' => 20,
            'valid' => 1,
            'template_version' => '7',
            'template_data' => base64_encode('ADMS_VX7_FACE'),
        ]);

        // Pushing to LAN device MUST write user profile ONLY and MUST NOT call setFingerprintTemplate with ADMS data
        $mockLanSocket = Mockery::mock(ZkSocketClient::class);
        $mockLanSocket->shouldReceive('connect')->once()->andReturn(true);
        $mockLanSocket->shouldReceive('getUsers')->once()->andReturn([]);
        $mockLanSocket->shouldReceive('setUser')->once()->andReturn(true);
        $mockLanSocket->shouldNotReceive('setFingerprintTemplate');

        $lanTplService = new LanBioTemplateSyncService;
        $res = $lanTplService->pushEmployeeToDevice($targetLan, $this->testPin, 'CROSS PROTOCOL EMP', true, $mockLanSocket);

        $this->assertTrue($res['success']);
        $this->assertTrue($res['user_pushed']);
        $this->assertSame(0, $res['templates_pushed']);
    }
}
