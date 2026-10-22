<?php

namespace Tests\Unit;

use App\Models\LeaveApplication;
use App\Models\LeaveBalance;
use App\Models\LeaveType;
use App\Services\CocLedgerService;
use App\Services\Leave\LeaveCalculationService;
use App\Services\Leave\LeaveCreditService;
use App\Services\WorkScheduleService;
use Carbon\Carbon;
use Tests\TestCase;

class LeaveCreditServiceTest extends TestCase
{
    private LeaveCreditService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $calcService = new LeaveCalculationService(new WorkScheduleService);
        $cocLedgerService = new CocLedgerService;
        $this->service = new LeaveCreditService($calcService, $cocLedgerService);
    }

    public function test_format_employee_name_for_balance(): void
    {
        $this->assertNull($this->service->formatEmployeeNameForBalance(null));

        $employee = (object) [
            'surname' => 'Dela Cruz',
            'firstname' => 'Juan',
            'middlename' => 'Protacio',
        ];

        $this->assertSame('Dela Cruz, Juan Protacio', $this->service->formatEmployeeNameForBalance($employee));

        $employeeNoMiddle = (object) [
            'surname' => 'Dela Cruz',
            'firstname' => 'Juan',
            'middlename' => null,
        ];

        $this->assertSame('Dela Cruz, Juan', $this->service->formatEmployeeNameForBalance($employeeNoMiddle));
    }

    public function test_should_prefer_leave_balance_record_by_updated_at(): void
    {
        $older = new LeaveBalance;
        $older->id = 1;
        $older->updated_at = Carbon::parse('2026-01-01 10:00:00');

        $newer = new LeaveBalance;
        $newer->id = 2;
        $newer->updated_at = Carbon::parse('2026-01-02 10:00:00');

        $this->assertTrue($this->service->shouldPreferLeaveBalanceRecord($newer, $older));
        $this->assertFalse($this->service->shouldPreferLeaveBalanceRecord($older, $newer));
    }

    public function test_should_prefer_leave_balance_record_by_id_tiebreaker(): void
    {
        $timestamp = Carbon::parse('2026-01-01 10:00:00');

        $record1 = new LeaveBalance;
        $record1->id = 10;
        $record1->updated_at = $timestamp;

        $record2 = new LeaveBalance;
        $record2->id = 20;
        $record2->updated_at = $timestamp;

        $this->assertTrue($this->service->shouldPreferLeaveBalanceRecord($record2, $record1));
        $this->assertFalse($this->service->shouldPreferLeaveBalanceRecord($record1, $record2));
    }

    public function test_resolve_application_balance_owner_control_no(): void
    {
        $app = new LeaveApplication;
        $app->employee_control_no = 'EMP-001';
        $app->applicant_admin_id = 42;

        $this->assertSame('EMP-001', $this->service->resolveApplicationBalanceOwnerControlNo($app));

        $adminApp = new LeaveApplication;
        $adminApp->employee_control_no = null;
        $adminApp->applicant_admin_id = 42;

        $resolved = $this->service->resolveApplicationBalanceOwnerControlNo(
            $adminApp,
            fn (int $adminId) => 'ADM-'.$adminId
        );

        $this->assertSame('ADM-42', $resolved);
    }

    public function test_should_apply_linked_vacation_leave_deduction(): void
    {
        $this->assertFalse($this->service->shouldApplyLinkedVacationLeaveDeduction(true, true, 0.0));
        $this->assertFalse($this->service->shouldApplyLinkedVacationLeaveDeduction(true, true, -1.0));
        $this->assertTrue($this->service->shouldApplyLinkedVacationLeaveDeduction(true, false, 1.5));
        $this->assertTrue($this->service->shouldApplyLinkedVacationLeaveDeduction(false, true, 1.5));
        $this->assertFalse($this->service->shouldApplyLinkedVacationLeaveDeduction(false, false, 1.5));
    }

    public function test_should_leave_type_deduct_forced_leave(): void
    {
        $vlType = new LeaveType(['name' => 'Vacation Leave']);
        $slType = new LeaveType(['name' => 'Sick Leave']);

        // Vacation leave when forced leave type ID is 5, application is for type 1 (VL)
        $this->assertTrue($this->service->shouldLeaveTypeDeductForcedLeave($vlType, 1, false, 5));

        // When application is for monetization, should not deduct forced leave
        $this->assertFalse($this->service->shouldLeaveTypeDeductForcedLeave($vlType, 1, true, 5));

        // When application is directly for forced leave (leaveTypeId === forcedLeaveTypeId)
        $this->assertFalse($this->service->shouldLeaveTypeDeductForcedLeave($vlType, 5, false, 5));

        // Non-vacation leave
        $this->assertFalse($this->service->shouldLeaveTypeDeductForcedLeave($slType, 2, false, 5));
    }

    public function test_resolve_leave_type_forced_leave_deduction_days(): void
    {
        $vlType = new LeaveType(['name' => 'Vacation Leave']);

        $days = $this->service->resolveLeaveTypeForcedLeaveDeductionDays($vlType, 1, false, 3.5, 5);
        $this->assertSame(3.5, $days);

        $zeroDays = $this->service->resolveLeaveTypeForcedLeaveDeductionDays($vlType, 1, true, 3.5, 5);
        $this->assertSame(0.0, $zeroDays);
    }

    public function test_map_leave_balances_by_canonical_type_id(): void
    {
        $b1 = new LeaveBalance;
        $b1->id = 1;
        $b1->leave_type_id = 1;
        $b1->balance = 10.0;
        $b1->updated_at = Carbon::parse('2026-01-01');

        $b2 = new LeaveBalance;
        $b2->id = 2;
        $b2->leave_type_id = 1;
        $b2->balance = 15.0;
        $b2->updated_at = Carbon::parse('2026-01-02');

        $mapped = $this->service->mapLeaveBalancesByCanonicalTypeId([$b1, $b2]);

        $this->assertArrayHasKey(1, $mapped);
        $this->assertSame(2, $mapped[1]->id);
        $this->assertSame(15.0, (float) $mapped[1]->balance);
    }

    public function test_resolve_sl_vl_cross_deduction_breakdown_fallback(): void
    {
        // When not a cross-deductible scenario
        $breakdown = $this->service->resolveSlVlCrossDeductionBreakdown(
            null,
            1,
            false,
            5.0,
            false,
            2,
            1,
            5.0,
            5.0
        );

        $this->assertSame(5.0, $breakdown['primary_deduction_days']);
        $this->assertSame(0.0, $breakdown['linked_vacation_deduction_days']);
        $this->assertSame(0.0, $breakdown['linked_sick_deduction_days']);
    }

    public function test_build_insufficient_primary_leave_balance_response(): void
    {
        $leaveType = new LeaveType;
        $leaveType->name = 'Vacation Leave';

        $response = $this->service->buildInsufficientPrimaryLeaveBalanceResponse($leaveType, 2.0, 5.0, false);
        $this->assertSame(422, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertStringContainsString('Insufficient Vacation Leave balance', $data['message']);
        $this->assertSame(2.0, (float) $data['available_balance']);
        $this->assertSame(5.0, (float) $data['required_balance_days']);

        $topUpResponse = $this->service->buildInsufficientPrimaryLeaveBalanceResponse($leaveType, 1.0, 3.0, true);
        $this->assertSame(422, $topUpResponse->getStatusCode());
        $topUpData = $topUpResponse->getData(true);
        $this->assertStringContainsString('before Vacation Leave can cover the schedule-based excess deduction', $topUpData['message']);
    }

    public function test_build_insufficient_vacation_leave_top_up_response(): void
    {
        $leaveType = new LeaveType;
        $leaveType->name = 'Special Privilege Leave';

        $response = $this->service->buildInsufficientVacationLeaveTopUpResponse($leaveType, 0.5, 2.0);
        $this->assertSame(422, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertStringContainsString('needs Vacation Leave to cover the schedule-based excess deduction', $data['errors']['leave_type_id'][0]);
        $this->assertSame(0.5, (float) $data['available_vacation_leave_days']);
        $this->assertSame(2.0, (float) $data['required_vacation_leave_days']);
    }

    public function test_build_insufficient_linked_vacation_leave_balance_response(): void
    {
        $leaveType = new LeaveType;
        $leaveType->name = 'Mandatory / Forced Leave';

        $response = $this->service->buildInsufficientLinkedVacationLeaveBalanceResponse($leaveType, 1.0, 3.0);
        $this->assertSame(422, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertStringContainsString('Insufficient Vacation Leave balance to apply Mandatory / Forced Leave.', $data['message']);
    }

    public function test_build_required_forced_leave_vacation_balance_response(): void
    {
        $response = $this->service->buildRequiredForcedLeaveVacationBalanceResponse(-1.0);
        $this->assertSame(422, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame(0.0, (float) $data['available_vacation_leave_days']);
        $this->assertStringContainsString('Mandatory / Forced Leave requires available Vacation Leave balance.', $data['message']);
    }

    public function test_validate_approval_leave_balances_forced_leave_missing_vacation_type(): void
    {
        $app = new LeaveApplication;
        $app->employee_control_no = 'EMP-001';
        $app->leave_type_id = 999;
        $app->is_monetization = false;

        $leaveType = new LeaveType;
        $leaveType->name = 'Mandatory / Forced Leave';

        $reqPrimary = 0.0;
        $reqVacation = 0.0;

        $response = $this->service->validateApprovalLeaveBalances(
            $app,
            $leaveType,
            3.0,
            true, // isForcedLeaveApplication
            false,
            $reqPrimary,
            $reqVacation,
            0.0,
            null // vacationLeaveTypeId is null
        );

        $this->assertNotNull($response);
        $this->assertSame(422, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertStringContainsString('Mandatory / Forced Leave requires available Vacation Leave balance.', $data['message']);
    }

    public function test_build_insufficient_cto_balance_response(): void
    {
        $eligibility = [
            'available_balance' => 1.0,
            'required_balance_days' => 2.0,
            'available_balance_hours' => 8.0,
            'required_balance_hours' => 16.0,
        ];

        $response = $this->service->buildInsufficientCtoBalanceResponse($eligibility);
        $this->assertSame(422, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertStringContainsString('Insufficient CTO balance', $data['message']);
        $this->assertSame(8.0, (float) $data['available_cto_balance_hours']);
        $this->assertSame(16.0, (float) $data['required_cto_balance_hours']);
    }

    public function test_resolve_tracked_leave_balance_before_application_deduction_without_owner(): void
    {
        $app = new LeaveApplication;
        $app->employee_control_no = null;
        $app->applicant_admin_id = null;

        $this->assertSame(
            2.5,
            $this->service->resolveTrackedLeaveBalanceBeforeApplicationDeduction($app, 1, 2.5)
        );
        $this->assertSame(
            0.0,
            $this->service->resolveTrackedLeaveBalanceBeforeApplicationDeduction($app, null, 2.5)
        );
    }
}
