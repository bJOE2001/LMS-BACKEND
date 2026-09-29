<?php

namespace Tests\Feature;

use App\Models\BiometricDevice;
use App\Models\BiometricDeviceCommand;
use App\Models\BiometricEnrollment;
use App\Models\BiometricTemplate;
use App\Services\Attendance\ZkAdmsService;
use Illuminate\Http\Request;
use Tests\TestCase;

class BiometricTemplateTest extends TestCase
{
    private const TEST_PIN = '022936';

    private const DEV_PRIMARY = 'TEST-PRIMARY-001';

    private const DEV_SECONDARY = 'TEST-SECONDARY-002';

    protected function setUp(): void
    {
        parent::setUp();

        // Clean up test records
        BiometricDevice::query()->whereIn('serial_number', [self::DEV_PRIMARY, self::DEV_SECONDARY])->delete();
        BiometricDeviceCommand::query()->whereIn('device_serial_number', [self::DEV_PRIMARY, self::DEV_SECONDARY])->delete();
        BiometricDeviceCommand::query()->where('employee_control_no', self::TEST_PIN)->delete();
        BiometricTemplate::query()->where('employee_control_no', self::TEST_PIN)->delete();
        BiometricEnrollment::query()->where('employee_control_no', self::TEST_PIN)->delete();

        // Create primary and secondary devices
        BiometricDevice::query()->create([
            'serial_number' => self::DEV_PRIMARY,
            'device_name' => 'HR Primary Terminal',
            'is_active' => true,
            'status' => 'ONLINE',
        ]);
        BiometricDevice::query()->create([
            'serial_number' => self::DEV_SECONDARY,
            'device_name' => 'Engineering Branch Terminal',
            'is_active' => true,
            'status' => 'ONLINE',
        ]);
    }

    protected function tearDown(): void
    {
        BiometricDevice::query()->whereIn('serial_number', [self::DEV_PRIMARY, self::DEV_SECONDARY])->delete();
        BiometricDeviceCommand::query()->whereIn('device_serial_number', [self::DEV_PRIMARY, self::DEV_SECONDARY])->delete();
        BiometricDeviceCommand::query()->where('employee_control_no', self::TEST_PIN)->delete();
        BiometricTemplate::query()->where('employee_control_no', self::TEST_PIN)->delete();
        BiometricEnrollment::query()->where('employee_control_no', self::TEST_PIN)->delete();

        parent::tearDown();
    }

    public function test_ingests_fingerprint_template_and_auto_broadcasts_to_secondary_devices(): void
    {
        $admsService = app(ZkAdmsService::class);

        $payload = 'PIN='.self::TEST_PIN."\tFID=0\tSize=568\tValid=1\tTMP=SAMPLE_BASE64_FP_TEMPLATE_BYTES_HERE\n";

        $request = Request::create(
            '/iclock/cdata?SN='.self::DEV_PRIMARY.'&table=template',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $payload
        );

        $res = $admsService->handleAttendancePush($request);

        $this->assertSame(1, $res['count']);
        $this->assertStringContainsString('OK: 1', $res['response']);

        // Assert template was saved in tblBiometricTemplates
        $savedTpl = BiometricTemplate::query()
            ->where('employee_control_no', self::TEST_PIN)
            ->where('template_type', BiometricTemplate::TYPE_FP)
            ->where('finger_index', 0)
            ->first();

        $this->assertNotNull($savedTpl);
        $this->assertSame('SAMPLE_BASE64_FP_TEMPLATE_BYTES_HERE', $savedTpl->template_data);
        $this->assertSame(568, $savedTpl->template_size);

        // Assert enrollment is registered
        $enrollment = BiometricEnrollment::query()->where('employee_control_no', self::TEST_PIN)->first();
        $this->assertNotNull($enrollment);
        $this->assertTrue($enrollment->isRegistered());

        // Assert broadcast queued commands for secondary terminal (skipping primary source)
        $commands = BiometricDeviceCommand::query()
            ->where('device_serial_number', self::DEV_SECONDARY)
            ->where('employee_control_no', self::TEST_PIN)
            ->get();

        $this->assertCount(2, $commands); // 1 DATA USER + 1 DATA FP
        $this->assertTrue($commands->contains('command_type', BiometricDeviceCommand::CMD_DATA_USER));
        $this->assertTrue($commands->contains('command_type', BiometricDeviceCommand::CMD_DATA_FP));

        $fpCmd = $commands->firstWhere('command_type', BiometricDeviceCommand::CMD_DATA_FP);
        $this->assertStringContainsString('DATA FP PIN='.self::TEST_PIN, $fpCmd->command_payload);
        $this->assertStringContainsString('FID=0', $fpCmd->command_payload);
        $this->assertStringContainsString('TMP=SAMPLE_BASE64_FP_TEMPLATE_BYTES_HERE', $fpCmd->command_payload);
    }

    public function test_ingests_biodata_face_template_and_auto_broadcasts(): void
    {
        $admsService = app(ZkAdmsService::class);

        $payload = 'PIN='.self::TEST_PIN."\tNo=0\tIndex=0\tValid=1\tType=9\tMajorVer=1\tMinorVer=0\tFormat=0\tTmp=SAMPLE_BASE64_FACE_TEMPLATE_DATA\n";

        $request = Request::create(
            '/iclock/cdata?SN='.self::DEV_PRIMARY.'&table=biodata',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $payload
        );

        $res = $admsService->handleAttendancePush($request);

        $this->assertSame(1, $res['count']);

        $savedFace = BiometricTemplate::query()
            ->where('employee_control_no', self::TEST_PIN)
            ->where('template_type', BiometricTemplate::TYPE_BIODATA)
            ->first();

        $this->assertNotNull($savedFace);
        $this->assertSame('SAMPLE_BASE64_FACE_TEMPLATE_DATA', $savedFace->template_data);

        $faceCmd = BiometricDeviceCommand::query()
            ->where('device_serial_number', self::DEV_SECONDARY)
            ->where('employee_control_no', self::TEST_PIN)
            ->where('command_type', BiometricDeviceCommand::CMD_DATA_BIODATA)
            ->first();

        $this->assertNotNull($faceCmd);
        $this->assertStringContainsString('DATA BIODATA PIN='.self::TEST_PIN, $faceCmd->command_payload);
    }

    public function test_broadcast_employee_clones_all_fingers_and_face_templates(): void
    {
        $admsService = app(ZkAdmsService::class);

        // Pre-create 2 fingers (Right Thumb = 0, Right Index = 1) and 1 Face
        BiometricTemplate::query()->create([
            'employee_control_no' => self::TEST_PIN,
            'biometric_pin' => self::TEST_PIN,
            'template_type' => BiometricTemplate::TYPE_FP,
            'finger_index' => 0,
            'template_data' => 'FP_THUMB_BYTES',
            'template_size' => 500,
            'valid' => 1,
        ]);
        BiometricTemplate::query()->create([
            'employee_control_no' => self::TEST_PIN,
            'biometric_pin' => self::TEST_PIN,
            'template_type' => BiometricTemplate::TYPE_FP,
            'finger_index' => 1,
            'template_data' => 'FP_INDEX_BYTES',
            'template_size' => 500,
            'valid' => 1,
        ]);
        BiometricTemplate::query()->create([
            'employee_control_no' => self::TEST_PIN,
            'biometric_pin' => self::TEST_PIN,
            'template_type' => BiometricTemplate::TYPE_BIODATA,
            'finger_index' => null,
            'template_data' => 'FACE_BYTES',
            'template_size' => 1200,
            'valid' => 1,
        ]);

        $queued = $admsService->broadcastEmployeeToAllDevices(self::TEST_PIN, 'Juan Dela Cruz');

        // Should queue 1 USER + 2 FP + 1 BIODATA = 4 commands per device (x 2 test devices = 8 commands)
        $this->assertGreaterThanOrEqual(8, $queued);

        $secCommands = BiometricDeviceCommand::query()
            ->where('device_serial_number', self::DEV_SECONDARY)
            ->where('employee_control_no', self::TEST_PIN)
            ->get();

        $this->assertCount(4, $secCommands);
        $this->assertSame(1, $secCommands->where('command_type', BiometricDeviceCommand::CMD_DATA_USER)->count());
        $this->assertSame(2, $secCommands->where('command_type', BiometricDeviceCommand::CMD_DATA_FP)->count());
        $this->assertSame(1, $secCommands->where('command_type', BiometricDeviceCommand::CMD_DATA_BIODATA)->count());
    }

    public function test_pull_device_templates_queues_query_commands(): void
    {
        $admsService = app(ZkAdmsService::class);

        $result = $admsService->requestTemplatesFromDevice(self::DEV_PRIMARY);

        $this->assertSame(self::DEV_PRIMARY, $result['device_serial_number']);
        $this->assertSame(3, $result['commands_queued']); // DATA QUERY FINGERTMP + DATA QUERY USERINFO + CHECK

        $queryCommands = BiometricDeviceCommand::query()
            ->where('device_serial_number', self::DEV_PRIMARY)
            ->whereIn('command_type', [BiometricDeviceCommand::CMD_DATA_QUERY, BiometricDeviceCommand::CMD_CHECK])
            ->get();

        $this->assertCount(3, $queryCommands);
        $this->assertTrue($queryCommands->contains('command_payload', 'DATA QUERY FINGERTMP'));
        $this->assertTrue($queryCommands->contains('command_payload', 'DATA QUERY USERINFO'));
        $this->assertTrue($queryCommands->contains('command_payload', 'CHECK'));
    }

    public function test_handles_querydata_push_for_fingerprint_templates(): void
    {
        $admsService = app(ZkAdmsService::class);

        $payload = "PIN=022936\tFID=0\tSize=568\tValid=1\tTMP=QUERYDATA_FP_TEMPLATE_BASE64\n"
            ."PIN=022936\tFID=1\tSize=568\tValid=1\tTMP=QUERYDATA_FP_TEMPLATE_FINGER_1\n";

        $request = Request::create(
            '/iclock/querydata?SN='.self::DEV_PRIMARY.'&type=tabledata&tablename=FINGERTMP',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $payload
        );

        $res = $admsService->handleQueryData($request);

        $this->assertSame(2, $res['count']);
        $this->assertStringContainsString('OK: 2', $res['response']);

        $templates = BiometricTemplate::query()
            ->where('employee_control_no', self::TEST_PIN)
            ->where('template_type', BiometricTemplate::TYPE_FP)
            ->get();

        $this->assertCount(2, $templates);
        $this->assertTrue($templates->contains('template_data', 'QUERYDATA_FP_TEMPLATE_BASE64'));
        $this->assertTrue($templates->contains('template_data', 'QUERYDATA_FP_TEMPLATE_FINGER_1'));
    }

    public function test_handles_querydata_push_with_space_delimited_format(): void
    {
        $admsService = app(ZkAdmsService::class);

        $payload = "PIN=022936 FingerID=2 Size=600 Valid=1 TMP=SPACE_DELIMITED_TEMPLATE\n";

        $request = Request::create(
            '/iclock/querydata?SN='.self::DEV_PRIMARY.'&type=tabledata&tablename=templatev10',
            'POST',
            [],
            [],
            [],
            ['CONTENT_TYPE' => 'text/plain'],
            $payload
        );

        $res = $admsService->handleQueryData($request);

        $this->assertSame(1, $res['count']);

        $saved = BiometricTemplate::query()
            ->where('employee_control_no', self::TEST_PIN)
            ->where('finger_index', 2)
            ->first();

        $this->assertNotNull($saved);
        $this->assertSame('SPACE_DELIMITED_TEMPLATE', $saved->template_data);
    }
}
