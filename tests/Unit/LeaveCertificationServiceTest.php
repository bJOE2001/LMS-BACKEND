<?php

namespace Tests\Unit;

use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Services\Leave\LeaveCalculationService;
use App\Services\Leave\LeaveCertificationService;
use App\Services\WorkScheduleService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class LeaveCertificationServiceTest extends TestCase
{
    private LeaveCertificationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $calcService = new LeaveCalculationService(new WorkScheduleService);
        $this->service = new LeaveCertificationService($calcService);
    }

    public function test_round_leave_credit_value(): void
    {
        $this->assertSame(0.0, $this->service->roundLeaveCreditValue(-5.0));
        $this->assertSame(1.235, $this->service->roundLeaveCreditValue(1.23456));
        $this->assertSame(10.0, $this->service->roundLeaveCreditValue(10.0));
    }

    public function test_trim_nullable_string(): void
    {
        $this->assertNull($this->service->trimNullableString(null));
        $this->assertNull($this->service->trimNullableString('   '));
        $this->assertSame('Hello', $this->service->trimNullableString('  Hello  '));
    }

    public function test_find_leave_type_balance_by_name_in_snapshot(): void
    {
        $snapshot = [
            ['leave_type_name' => 'Vacation Leave', 'balance' => 12.5],
            ['leave_type_name' => 'Sick Leave', 'balance' => 8.25],
        ];

        $this->assertSame(12.5, $this->service->findLeaveTypeBalanceByNameInSnapshot($snapshot, 'Vacation Leave'));
        $this->assertSame(12.5, $this->service->findLeaveTypeBalanceByNameInSnapshot($snapshot, 'vacation leave'));
        $this->assertSame(8.25, $this->service->findLeaveTypeBalanceByNameInSnapshot($snapshot, 'Sick Leave'));
        $this->assertSame(0.0, $this->service->findLeaveTypeBalanceByNameInSnapshot($snapshot, 'Maternity Leave'));
    }

    public function test_find_leave_type_balance_in_snapshot(): void
    {
        $snapshot = [
            ['leave_type_id' => 1, 'balance' => 10.0],
            ['leave_type_id' => 2, 'balance' => 5.5],
        ];

        $this->assertSame(10.0, $this->service->findLeaveTypeBalanceInSnapshot($snapshot, 1));
        $this->assertSame(5.5, $this->service->findLeaveTypeBalanceInSnapshot($snapshot, 2));
        $this->assertNull($this->service->findLeaveTypeBalanceInSnapshot($snapshot, 999));
        $this->assertNull($this->service->findLeaveTypeBalanceInSnapshot([], 1));
    }

    public function test_resolve_terminal_leave_estimated_amount(): void
    {
        $monthlyRate = 30000.0;
        $vacationBalance = 15.0;
        $sickBalance = 10.0;

        // (15 + 10) * 30000 * 0.0478087 = 25 * 30000 * 0.0478087 = 750000 * 0.0478087 = 35856.525
        $estimated = $this->service->resolveTerminalLeaveEstimatedAmount($monthlyRate, $vacationBalance, $sickBalance);

        $this->assertNotNull($estimated);
        $this->assertSame(35856.525, $estimated);

        // Null monthly rate returns null
        $this->assertNull($this->service->resolveTerminalLeaveEstimatedAmount(null, 10.0, 10.0));
        $this->assertNull($this->service->resolveTerminalLeaveEstimatedAmount(0.0, 10.0, 10.0));
    }

    public function test_normalize_certification_leave_credits_bucket(): void
    {
        $raw = [
            'total_earned' => 20.1234,
            'less_this_application' => 2.5,
            'balance' => 17.6234,
            'balance_after_application' => 17.6234,
            'leave_type_name' => 'Vacation Leave',
            'leave_type_id' => 1,
        ];

        $normalized = $this->service->normalizeCertificationLeaveCreditsBucket($raw);
        $this->assertNotNull($normalized);
        $this->assertSame(20.123, $normalized['total_earned']);
        $this->assertSame(2.5, $normalized['less_this_application']);
        $this->assertSame(17.623, $normalized['balance']);
        $this->assertSame(17.623, $normalized['balance_after_application']);
        $this->assertSame('Vacation Leave', $normalized['leave_type_name']);
        $this->assertSame(1, $normalized['leave_type_id']);

        // Non-array returns null
        $this->assertNull($this->service->normalizeCertificationLeaveCreditsBucket('invalid'));
    }

    public function test_normalize_certification_leave_credits_payload(): void
    {
        $app = new LeaveApplication;
        $app->created_at = CarbonImmutable::parse('2026-05-10');

        $rawPayload = [
            'vacation' => [
                'total_earned' => 15.0,
                'less_this_application' => 2.0,
                'balance' => 13.0,
            ],
            'sick' => [
                'total_earned' => 10.0,
                'less_this_application' => 0.0,
                'balance' => 10.0,
            ],
            'as_of_date' => 'May 10, 2026',
        ];

        $normalized = $this->service->normalizeCertificationLeaveCreditsPayload($rawPayload, $app);
        $this->assertNotNull($normalized);
        $this->assertArrayHasKey('vacation', $normalized);
        $this->assertArrayHasKey('sick', $normalized);
        $this->assertSame('May 10, 2026', $normalized['as_of_date']);

        // Missing vacation or sick bucket returns null
        $invalidPayload = [
            'vacation' => null,
            'sick' => ['total_earned' => 10],
        ];
        $this->assertNull($this->service->normalizeCertificationLeaveCreditsPayload($invalidPayload, $app));
    }

    public function test_certification_leave_credits_bucket_needs_refresh(): void
    {
        $bucket = [
            'total_earned' => 15.0,
            'less_this_application' => 2.0,
            'balance' => 13.0,
            'balance_after_application' => 13.0,
        ];

        // Same deduction: no refresh needed
        $this->assertFalse($this->service->certificationLeaveCreditsBucketNeedsRefresh($bucket, 2.0));

        // Different deduction: refresh needed
        $this->assertTrue($this->service->certificationLeaveCreditsBucketNeedsRefresh($bucket, 3.0));

        // Total earned doesn't match balance_after + less_this_app
        $inconsistentBucket = [
            'total_earned' => 20.0, // Should be 13 + 2 = 15
            'less_this_application' => 2.0,
            'balance_after_application' => 13.0,
        ];
        $this->assertTrue($this->service->certificationLeaveCreditsBucketNeedsRefresh($inconsistentBucket, 2.0));
    }

    public function test_build_selected_certification_leave_type_bucket(): void
    {
        $selectedLeaveTypeDeduction = [
            'leave_type_id' => 5,
            'leave_type_name' => 'Special Privilege Leave',
            'less_this_application' => 3.0,
        ];

        // When deduction already applied (approved):
        $bucketApplied = $this->service->buildSelectedCertificationLeaveTypeBucket(
            currentBalance: 7.0,
            selectedLeaveTypeDeduction: $selectedLeaveTypeDeduction,
            deductionAlreadyApplied: true
        );
        $this->assertSame(10.0, $bucketApplied['total_earned']);
        $this->assertSame(3.0, $bucketApplied['less_this_application']);
        $this->assertSame(7.0, $bucketApplied['balance']);
        $this->assertSame(7.0, $bucketApplied['balance_after_application']);

        // When deduction not yet applied (pending):
        $bucketPending = $this->service->buildSelectedCertificationLeaveTypeBucket(
            currentBalance: 10.0,
            selectedLeaveTypeDeduction: $selectedLeaveTypeDeduction,
            deductionAlreadyApplied: false
        );
        $this->assertSame(10.0, $bucketPending['total_earned']);
        $this->assertSame(3.0, $bucketPending['less_this_application']);
        $this->assertSame(10.0, $bucketPending['balance']);
        $this->assertSame(7.0, $bucketPending['balance_after_application']);
    }

    public function test_serialize_monetization_leave_credit_components(): void
    {
        $components = [
            ['leave_type_id' => '1', 'leave_type_name' => 'Vacation Leave', 'days' => '5.5555'],
            ['leave_type_id' => 2, 'leave_type_name' => 'Sick Leave', 'days' => 4.0],
        ];

        $serialized = $this->service->serializeMonetizationLeaveCreditComponents($components);
        $this->assertCount(2, $serialized);
        $this->assertSame(1, $serialized[0]['leave_type_id']);
        $this->assertSame(5.556, $serialized[0]['days']);
        $this->assertSame(2, $serialized[1]['leave_type_id']);
        $this->assertSame(4.0, $serialized[1]['days']);
    }

    public function test_is_sick_and_vacation_leave_type(): void
    {
        $vacationType = new LeaveType(['name' => 'Vacation Leave']);
        $sickType = new LeaveType(['name' => 'Sick Leave']);
        $otherType = new LeaveType(['name' => 'Maternity Leave']);

        $this->assertTrue($this->service->isVacationLeaveType($vacationType));
        $this->assertFalse($this->service->isVacationLeaveType($sickType));
        $this->assertFalse($this->service->isVacationLeaveType($otherType));

        $this->assertTrue($this->service->isSickLeaveType($sickType));
        $this->assertFalse($this->service->isSickLeaveType($vacationType));
        $this->assertFalse($this->service->isSickLeaveType($otherType));
    }
}
