<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\HRAccount;
use App\Services\Attendance\BiometricTransferService;
use Tests\TestCase;

class BiometricDeviceOfficeResolutionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        \App\Models\BiometricDevice::firstOrCreate(
            ['serial_number' => 'KMY2252000112'],
            [
                'device_name' => 'CHRMO',
                'department_id' => 18,
                'department_name' => 'City Human Resource Management Office',
                'zkbio_area_id' => 3,
                'is_primary' => true,
                'status' => 'ONLINE',
                'is_active' => true,
            ]
        );

        \App\Models\BiometricDevice::firstOrCreate(
            ['serial_number' => 'KMY2252000117'],
            [
                'device_name' => 'CICTMO',
                'department_id' => 19,
                'department_name' => 'City Information and Communications Technology Management Office',
                'zkbio_area_id' => 2,
                'is_primary' => true,
                'status' => 'ONLINE',
                'is_active' => true,
            ]
        );

        \App\Models\BiometricEnrollment::firstOrCreate(
            ['employee_control_no' => '022936'],
            [
                'employee_name' => 'BELLY JOE B. BASADRE',
                'status' => \App\Models\BiometricEnrollment::STATUS_REGISTERED,
                'enrollment_device_sn' => 'KMY2252000112',
                'synced_devices' => ['KMY2252000112'],
            ]
        );
    }

    protected function getHrUser(): HRAccount
    {
        $hrUser = HRAccount::first();
        if (! $hrUser) {
            $hrUser = HRAccount::create([
                'username' => 'hr_test_admin',
                'full_name' => 'HR Admin Test',
                'password' => bcrypt('password123'),
                'must_change_password' => false,
            ]);
        } else {
            $hrUser->must_change_password = false;
            $hrUser->save();
        }

        return $hrUser;
    }

    public function test_list_devices_returns_office_acronym_and_zkbio_area(): void
    {
        $hrUser = $this->getHrUser();
        \Laravel\Sanctum\Sanctum::actingAs($hrUser);

        $response = $this->getJson('/api/attendance/devices');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'devices' => [
                '*' => [
                    'id',
                    'device_name',
                    'serial_number',
                    'department_id',
                    'department_name',
                    'office_acronym',
                    'zkbio_area_id',
                    'zkbio_area_name',
                    'is_primary',
                ],
            ],
            'pending_count',
        ]);

        $devices = collect($response->json('devices'));
        $chrmo = $devices->firstWhere('serial_number', 'KMY2252000112');
        $cictmo = $devices->firstWhere('serial_number', 'KMY2252000117');

        $this->assertNotNull($chrmo);
        $this->assertEquals('CHRMO', $chrmo['office_acronym']);
        $this->assertEquals(3, $chrmo['zkbio_area_id']);

        $this->assertNotNull($cictmo);
        $this->assertEquals('CICTMO', $cictmo['office_acronym']);
        $this->assertEquals(2, $cictmo['zkbio_area_id']);
    }

    public function test_list_zkbio_areas_flags_area_1_as_prohibited(): void
    {
        $hrUser = $this->getHrUser();
        \Laravel\Sanctum\Sanctum::actingAs($hrUser);

        $response = $this->getJson('/api/attendance/zkbio-areas');

        $response->assertStatus(200);
        $areas = collect($response->json('areas'));

        $area1 = $areas->firstWhere('id', 1);
        if ($area1) {
            $this->assertTrue($area1['is_prohibited']);
        }

        $area2 = $areas->firstWhere('id', 2);
        if ($area2) {
            $this->assertFalse($area2['is_prohibited']);
        }

        $area3 = $areas->firstWhere('id', 3);
        if ($area3) {
            $this->assertFalse($area3['is_prohibited']);
        }
    }

    public function test_store_device_prohibits_area_1(): void
    {
        $hrUser = $this->getHrUser();
        \Laravel\Sanctum\Sanctum::actingAs($hrUser);

        $response = $this->postJson('/api/attendance/devices', [
            'serial_number' => 'TEST_DEVICE_001',
            'device_name' => 'Test Terminal',
            'department_id' => 18,
            'zkbio_area_id' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment([
            'message' => 'Area 1 is the default non-synchronizing area in ZKBio Time and cannot be assigned to devices. Please select an active area such as Area 2 or Area 3.',
        ]);
    }

    public function test_transfer_workflow_rejects_office_without_active_device(): void
    {
        $emptyDept = Department::create([
            'name' => 'EMPTY TEST OFFICE WITHOUT DEVICE',
            'acronym' => 'EMPTY',
            'is_inactive' => false,
        ]);

        $service = app(BiometricTransferService::class);
        $result = $service->transferOnDepartmentAssignment('022936', $emptyDept->id);

        $this->assertFalse($result['transferred']);
        $this->assertStringContainsString('does not have an active biometric device configured', $result['message']);

        $emptyDept->delete();
    }

    public function test_transfer_workflow_resolves_destination_device_and_area_for_cictmo(): void
    {
        $mockReconciliation = \Mockery::mock(\App\Services\Attendance\ZkBioTimeReconciliationService::class)->makePartial();
        $mockReconciliation->shouldReceive('assignEmployeeExclusivelyToArea')->andReturn(['success' => true, 'message' => 'Success']);
        $this->app->instance(\App\Services\Attendance\ZkBioTimeReconciliationService::class, $mockReconciliation);

        $service = app(BiometricTransferService::class);
        $result = $service->transferOnDepartmentAssignment('022936', 19);

        // 19 is CICTMO, which has device KMY2252000117 mapped to Area 2
        $this->assertTrue($result['transferred']);
        $this->assertContains('CICTMO', $result['target_devices']);

        // Verify that enrollment records only the target device (exclusive, no city-wide accumulation)
        $enrollment = \App\Models\BiometricEnrollment::query()->where('employee_control_no', '022936')->first();
        $this->assertNotNull($enrollment);
        $this->assertEquals(['KMY2252000117'], $enrollment->synced_devices);
        $this->assertEquals(\App\Models\BiometricEnrollment::STATUS_REGISTERED, $enrollment->status);
    }

    public function test_remove_workflow_clears_synced_devices_and_resets_to_central_pool(): void
    {
        $mockReconciliation = \Mockery::mock(\App\Services\Attendance\ZkBioTimeReconciliationService::class)->makePartial();
        $mockReconciliation->shouldReceive('assignEmployeeExclusivelyToArea')->andReturn(['success' => true, 'message' => 'Success']);
        $this->app->instance(\App\Services\Attendance\ZkBioTimeReconciliationService::class, $mockReconciliation);

        \App\Models\BiometricEnrollment::updateOrCreate(
            ['employee_control_no' => 'TEST_REMOVE_EMP'],
            [
                'employee_name' => 'Test Remove Employee',
                'status' => \App\Models\BiometricEnrollment::STATUS_REGISTERED,
                'synced_devices' => ['KMY2252000117'],
            ]
        );

        $service = app(BiometricTransferService::class);
        $result = $service->removeOnDepartmentDeassignment('TEST_REMOVE_EMP', 19);

        $this->assertTrue($result['removed']);

        // Verify that synced_devices is cleared
        $enrollment = \App\Models\BiometricEnrollment::query()->where('employee_control_no', 'TEST_REMOVE_EMP')->first();
        $this->assertNotNull($enrollment);
        $this->assertEmpty($enrollment->synced_devices);

        $enrollment->delete();
    }

    public function test_hr_register_pushes_to_chrmo_device(): void
    {
        $mockReconciliation = \Mockery::mock(\App\Services\Attendance\ZkBioTimeReconciliationService::class)->makePartial();
        $mockReconciliation->shouldReceive('assignEmployeeExclusivelyToArea')->andReturn(['success' => true, 'message' => 'Success']);
        $this->app->instance(\App\Services\Attendance\ZkBioTimeReconciliationService::class, $mockReconciliation);

        $hrUser = $this->getHrUser();
        \Laravel\Sanctum\Sanctum::actingAs($hrUser);

        $response = $this->postJson('/api/hr/biometric-registration/register', [
            'employee_control_no' => 'TEST_REG_EMP',
            'device_serial_number' => 'KMY2252000112',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['message', 'command_id', 'enrollment']);

        \App\Models\BiometricEnrollment::where('employee_control_no', 'TEST_REG_EMP')->delete();
        \App\Models\BiometricTransferLog::where('employee_control_no', 'TEST_REG_EMP')->delete();
    }

    public function test_hr_index_returns_not_registered_employees(): void
    {
        $hrUser = $this->getHrUser();
        \Laravel\Sanctum\Sanctum::actingAs($hrUser);

        $response = $this->getJson('/api/hr/biometric-registration?status=NOT_REGISTERED');
        $response->assertStatus(200);
        $response->assertJsonStructure([
            'employees',
            'total',
            'stats' => ['total_employees', 'registered', 'pending', 'not_registered'],
        ]);

        $this->assertNotEmpty($response->json('employees'));
        $first = $response->json('employees.0');
        $this->assertSame('NOT REGISTERED', $first['biometric_status']);
        $this->assertGreaterThan(0, $response->json('total'));
        $this->assertGreaterThan(0, $response->json('stats.not_registered'));

        // Case-insensitive check
        $responseLower = $this->getJson('/api/hr/biometric-registration?status=not_registered');
        $responseLower->assertStatus(200);
        $this->assertNotEmpty($responseLower->json('employees'));
    }
}
