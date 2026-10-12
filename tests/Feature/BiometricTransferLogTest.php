<?php

namespace Tests\Feature;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\BiometricTransferLog;
use App\Models\Department;
use App\Models\HRAccount;
use App\Services\Attendance\BiometricTransferService;
use App\Services\Attendance\ZkBioTimeReconciliationService;
use Mockery;
use Tests\TestCase;

class BiometricTransferLogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        BiometricDevice::firstOrCreate(
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

        BiometricDevice::firstOrCreate(
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

        BiometricEnrollment::firstOrCreate(
            ['employee_control_no' => '022936'],
            [
                'employee_name' => 'BELLY JOE B. BASADRE',
                'status' => BiometricEnrollment::STATUS_REGISTERED,
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
                'username' => 'hr_transfer_admin',
                'full_name' => 'HR Transfer Admin',
                'password' => bcrypt('password123'),
                'must_change_password' => false,
            ]);
        } else {
            $hrUser->must_change_password = false;
            $hrUser->save();
        }

        return $hrUser;
    }

    public function test_transfer_creates_audit_log_in_biometric_transfer_logs(): void
    {
        $mockReconciliation = Mockery::mock(ZkBioTimeReconciliationService::class)->makePartial();
        $mockReconciliation->shouldReceive('assignEmployeeExclusivelyToArea')->andReturn(['success' => true, 'message' => 'Success']);
        $this->app->instance(ZkBioTimeReconciliationService::class, $mockReconciliation);

        $testControlNo = 'TEST_LOG_EMP';
        BiometricEnrollment::updateOrCreate(
            ['employee_control_no' => $testControlNo],
            [
                'employee_name' => 'Test Log Employee',
                'status' => BiometricEnrollment::STATUS_REGISTERED,
                'enrollment_device_sn' => 'KMY2252000112',
                'synced_devices' => ['KMY2252000112'],
            ]
        );

        $service = app(BiometricTransferService::class);
        $result = $service->transferOnDepartmentAssignment($testControlNo, 19, 1, 18);

        $this->assertTrue($result['transferred']);
        $this->assertNotNull($result['transfer_log_id']);

        $log = BiometricTransferLog::query()->find($result['transfer_log_id']);
        $this->assertNotNull($log);
        $this->assertEquals($testControlNo, $log->employee_control_no);
        $this->assertEquals(BiometricTransferLog::ACTION_TRANSFER, $log->action_type);
        $this->assertEquals(BiometricTransferLog::STATUS_SUCCESS, $log->status);
        $this->assertEquals(18, $log->source_department_id);
        $this->assertEquals(19, $log->target_department_id);
        $this->assertEquals('KMY2252000117', $log->target_device_sn);
        $this->assertEquals(2, $log->target_area_id);
    }

    public function test_remove_creates_audit_log_in_biometric_transfer_logs(): void
    {
        $mockReconciliation = Mockery::mock(ZkBioTimeReconciliationService::class)->makePartial();
        $mockReconciliation->shouldReceive('assignEmployeeExclusivelyToArea')->andReturn(['success' => true, 'message' => 'Success']);
        $this->app->instance(ZkBioTimeReconciliationService::class, $mockReconciliation);

        $testControlNo = 'TEST_LOG_EMP';
        $service = app(BiometricTransferService::class);
        $result = $service->removeOnDepartmentDeassignment($testControlNo, 19, 1);

        $this->assertTrue($result['removed']);
        $this->assertNotNull($result['transfer_log_id']);

        $log = BiometricTransferLog::query()->find($result['transfer_log_id']);
        $this->assertNotNull($log);
        $this->assertEquals($testControlNo, $log->employee_control_no);
        $this->assertEquals(BiometricTransferLog::ACTION_REMOVE, $log->action_type);
        $this->assertEquals(BiometricTransferLog::STATUS_SUCCESS, $log->status);
        $this->assertEquals(19, $log->source_department_id);
        $this->assertEquals(BiometricTransferService::CHRMO_DEPARTMENT_ID, $log->target_department_id);
        $this->assertEquals('CHRMO', $log->target_office_name);
        $this->assertEquals(BiometricTransferService::CHRMO_AREA_ID, $log->target_area_id);
    }

    public function test_failed_transfer_creates_audit_log_with_failed_status(): void
    {
        $emptyDept = Department::create([
            'name' => 'EMPTY TEST OFFICE FOR AUDIT LOG',
            'acronym' => 'EMPTY_AUDIT',
            'is_inactive' => false,
        ]);

        $testControlNo = 'TEST_LOG_EMP';
        $service = app(BiometricTransferService::class);
        $result = $service->transferOnDepartmentAssignment($testControlNo, $emptyDept->id, 1);

        $this->assertFalse($result['transferred']);

        $failedLog = BiometricTransferLog::query()
            ->where('employee_control_no', $testControlNo)
            ->where('target_department_id', $emptyDept->id)
            ->where('status', BiometricTransferLog::STATUS_FAILED)
            ->latest('id')
            ->first();

        $this->assertNotNull($failedLog);
        $this->assertStringContainsString('does not have an active biometric device configured', $failedLog->remarks);

        $emptyDept->delete();
        BiometricEnrollment::where('employee_control_no', $testControlNo)->delete();
        BiometricTransferLog::where('employee_control_no', $testControlNo)->delete();
    }

    public function test_transfer_logs_endpoint_returns_paginated_records(): void
    {
        $hrUser = $this->getHrUser();
        \Laravel\Sanctum\Sanctum::actingAs($hrUser);

        $response = $this->getJson('/api/attendance/biometric/transfer-logs');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'logs' => [
                '*' => [
                    'id',
                    'employee_control_no',
                    'employee_name',
                    'action_type',
                    'source_office_name',
                    'target_office_name',
                    'status',
                    'created_at',
                ],
            ],
            'total',
            'current_page',
            'last_page',
        ]);
    }
}
