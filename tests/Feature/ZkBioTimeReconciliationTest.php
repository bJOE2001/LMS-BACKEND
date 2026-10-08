<?php

namespace Tests\Feature;

use App\Models\BiometricEnrollment;
use App\Services\Attendance\ZkBioTimeReconciliationService;
use Carbon\Carbon;
use Tests\TestCase;

class ZkBioTimeReconciliationTest extends TestCase
{
    private const TEST_PIN = 'TEST_REC_001';

    protected function setUp(): void
    {
        parent::setUp();
        BiometricEnrollment::query()->where('employee_control_no', self::TEST_PIN)->delete();
    }

    protected function tearDown(): void
    {
        BiometricEnrollment::query()->where('employee_control_no', self::TEST_PIN)->delete();
        parent::tearDown();
    }

    public function test_reconcile_leaves_pending_when_no_fingerprint(): void
    {
        $enrollment = BiometricEnrollment::query()->create([
            'employee_control_no' => self::TEST_PIN,
            'employee_name' => 'Test Employee',
            'status' => BiometricEnrollment::STATUS_PENDING_ENROLLMENT,
            'enrollment_device_sn' => 'KMY2252000112',
        ]);

        $mockService = $this->getMockBuilder(ZkBioTimeReconciliationService::class)
            ->onlyMethods(['getEmployeeBiometricState', 'getSyncedTargetDevices'])
            ->getMock();

        $mockService->method('getEmployeeBiometricState')
            ->willReturn([
                'found' => true,
                'has_fingerprint' => false,
                'fingerprint' => '-',
                'enroll_sn' => null,
                'enrolled_at' => null,
                'zkbio_id' => 999,
            ]);

        $mockService->method('getSyncedTargetDevices')
            ->willReturn([]);

        $result = $mockService->reconcileEnrollment($enrollment);

        $this->assertSame(BiometricEnrollment::STATUS_PENDING_ENROLLMENT, $result->status);
        $this->assertFalse($result->isRegistered());
        $this->assertFalse($result->isFingerprintEnrolled());
    }

    public function test_reconcile_transitions_to_fingerprint_enrolled_when_no_target_sync(): void
    {
        $enrollment = BiometricEnrollment::query()->create([
            'employee_control_no' => self::TEST_PIN,
            'employee_name' => 'Test Employee',
            'status' => BiometricEnrollment::STATUS_PENDING_ENROLLMENT,
            'enrollment_device_sn' => 'KMY2252000112',
        ]);

        $mockService = $this->getMockBuilder(ZkBioTimeReconciliationService::class)
            ->onlyMethods(['getEmployeeBiometricState', 'getSyncedTargetDevices'])
            ->getMock();

        $enrolledAt = Carbon::parse('2026-10-07 14:43:00');

        $mockService->method('getEmployeeBiometricState')
            ->willReturn([
                'found' => true,
                'has_fingerprint' => true,
                'fingerprint' => 'Ver 10:1',
                'enroll_sn' => 'KMY2252000112',
                'enrolled_at' => $enrolledAt,
                'zkbio_id' => 999,
            ]);

        $mockService->method('getSyncedTargetDevices')
            ->willReturn([]); // No target devices synced yet

        $result = $mockService->reconcileEnrollment($enrollment);

        $this->assertSame(BiometricEnrollment::STATUS_FINGERPRINT_ENROLLED, $result->status);
        $this->assertTrue($result->isFingerprintEnrolled());
        $this->assertFalse($result->isRegistered());
        $this->assertSame('KMY2252000112', $result->enrollment_device_sn);
        $this->assertContains('KMY2252000112', $result->synced_devices);
    }

    public function test_reconcile_transitions_to_registered_when_target_synced(): void
    {
        $enrollment = BiometricEnrollment::query()->create([
            'employee_control_no' => self::TEST_PIN,
            'employee_name' => 'Test Employee',
            'status' => BiometricEnrollment::STATUS_PENDING_ENROLLMENT,
            'enrollment_device_sn' => 'KMY2252000112',
        ]);

        $mockService = $this->getMockBuilder(ZkBioTimeReconciliationService::class)
            ->onlyMethods(['getEmployeeBiometricState', 'getSyncedTargetDevices'])
            ->getMock();

        $enrolledAt = Carbon::parse('2026-10-07 14:43:00');

        $mockService->method('getEmployeeBiometricState')
            ->willReturn([
                'found' => true,
                'has_fingerprint' => true,
                'fingerprint' => 'Ver 10:1',
                'enroll_sn' => 'KMY2252000112',
                'enrolled_at' => $enrolledAt,
                'zkbio_id' => 999,
            ]);

        $mockService->method('getSyncedTargetDevices')
            ->willReturn(['KMY2252000117']); // CICTMO target device acknowledged sync

        $result = $mockService->reconcileEnrollment($enrollment);

        $this->assertSame(BiometricEnrollment::STATUS_REGISTERED, $result->status);
        $this->assertTrue($result->isRegistered());
        $this->assertSame('KMY2252000112', $result->enrollment_device_sn);
        $this->assertContains('KMY2252000112', $result->synced_devices);
        $this->assertContains('KMY2252000117', $result->synced_devices);
    }

    public function test_reconciliation_is_idempotent(): void
    {
        $enrollment = BiometricEnrollment::query()->create([
            'employee_control_no' => self::TEST_PIN,
            'employee_name' => 'Test Employee',
            'status' => BiometricEnrollment::STATUS_PENDING_ENROLLMENT,
            'enrollment_device_sn' => 'KMY2252000112',
        ]);

        $mockService = $this->getMockBuilder(ZkBioTimeReconciliationService::class)
            ->onlyMethods(['getEmployeeBiometricState', 'getSyncedTargetDevices'])
            ->getMock();

        $mockService->method('getEmployeeBiometricState')
            ->willReturn([
                'found' => true,
                'has_fingerprint' => true,
                'fingerprint' => 'Ver 10:1',
                'enroll_sn' => 'KMY2252000112',
                'enrolled_at' => Carbon::parse('2026-10-07 14:43:00'),
                'zkbio_id' => 999,
            ]);

        $mockService->method('getSyncedTargetDevices')
            ->willReturn(['KMY2252000117']);

        $first = $mockService->reconcileEnrollment($enrollment);
        $this->assertSame(BiometricEnrollment::STATUS_REGISTERED, $first->status);

        // Run second time on same enrollment
        $second = $mockService->reconcileEnrollment($first);
        $this->assertSame(BiometricEnrollment::STATUS_REGISTERED, $second->status);
        $this->assertEquals($first->synced_devices, $second->synced_devices);
    }
}
