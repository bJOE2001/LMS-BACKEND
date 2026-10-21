<?php

namespace Tests\Unit;

use App\Models\LeaveApplication;
use App\Models\LeaveType;
use App\Services\Leave\LeaveEligibilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

class LeaveEligibilityServiceTest extends TestCase
{
    private LeaveEligibilityService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LeaveEligibilityService::class);
    }

    public function test_format_days(): void
    {
        $this->assertSame('1 day', LeaveEligibilityService::formatDays(1.0));
        $this->assertSame('2 days', LeaveEligibilityService::formatDays(2.0));
        $this->assertSame('1.5 days', LeaveEligibilityService::formatDays(1.5));
    }

    public function test_count_working_days_from_next_day(): void
    {
        // Monday 2026-06-01 to Friday 2026-06-05 (4 working days: Tue, Wed, Thu, Fri)
        $lastAbsent = CarbonImmutable::parse('2026-06-01');
        $filedDate = CarbonImmutable::parse('2026-06-05');
        $this->assertSame(4, $this->service->countWorkingDaysFromNextDay($lastAbsent, $filedDate));

        // Filed on same day or before
        $this->assertSame(0, $this->service->countWorkingDaysFromNextDay($lastAbsent, $lastAbsent));
    }

    public function test_count_working_days_from_filed_date_before_date(): void
    {
        // Monday 2026-06-01 to Friday 2026-06-05 (4 working days: Mon, Tue, Wed, Thu)
        $filedDate = CarbonImmutable::parse('2026-06-01');
        $targetDate = CarbonImmutable::parse('2026-06-05');
        $this->assertSame(4, $this->service->countWorkingDaysFromFiledDateBeforeDate($filedDate, $targetDate));

        // Target before filed date
        $this->assertSame(0, $this->service->countWorkingDaysFromFiledDateBeforeDate($targetDate, $filedDate));
    }

    public function test_calculate_max_consecutive_date_span(): void
    {
        $dates = ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-06', '2026-06-07'];
        $this->assertSame(3, $this->service->calculateMaxConsecutiveDateSpan($dates));

        $empty = [];
        $this->assertSame(0, $this->service->calculateMaxConsecutiveDateSpan($empty));
    }

    public function test_validate_approved_leave_request_lead_time(): void
    {
        $app = new LeaveApplication;
        $app->status = LeaveApplication::STATUS_PENDING_ADMIN;
        // Non-approved application has no lead time restriction
        $this->assertNull($this->service->validateApprovedLeaveRequestLeadTime($app));

        // Approved application starting tomorrow (less than 5 working days)
        $app->status = LeaveApplication::STATUS_APPROVED;
        $app->start_date = CarbonImmutable::now()->addDay();
        $response = $this->service->validateApprovedLeaveRequestLeadTime($app, 'cancel');
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
    }

    public function test_validate_cto_availment_policy(): void
    {
        // Empty selected dates
        $responseEmpty = $this->service->validateCtoAvailmentPolicy(1.0, [], null);
        $this->assertInstanceOf(JsonResponse::class, $responseEmpty);
        $this->assertSame(422, $responseEmpty->getStatusCode());

        // More than 5 consecutive days
        $sixConsecutiveDays = [
            '2026-07-01', '2026-07-02', '2026-07-03',
            '2026-07-04', '2026-07-05', '2026-07-06',
        ];
        $responseConsecutive = $this->service->validateCtoAvailmentPolicy(6.0, $sixConsecutiveDays, null, '2026-06-01');
        $this->assertInstanceOf(JsonResponse::class, $responseConsecutive);
        $this->assertSame(422, $responseConsecutive->getStatusCode());

        // Valid notice (> 3 working days) and <= 5 days
        $validDays = ['2026-07-10', '2026-07-11'];
        $responseValid = $this->service->validateCtoAvailmentPolicy(2.0, $validDays, null, '2026-07-01');
        $this->assertNull($responseValid);
    }

    public function test_resolve_sick_leave_pay_mode_from_filing_window(): void
    {
        $dates = ['2026-06-01']; // Monday
        // Filed within 5 working days (e.g. Friday 2026-06-05) -> With Pay
        $payModeWithPay = $this->service->resolveSickLeavePayModeFromFilingWindow($dates, '2026-06-05');
        $this->assertSame(LeaveApplication::PAY_MODE_WITH_PAY, $payModeWithPay);

        // Filed after 5 working days (e.g. Wednesday 2026-06-10) -> Without Pay
        $payModeWithoutPay = $this->service->resolveSickLeavePayModeFromFilingWindow($dates, '2026-06-10');
        $this->assertSame(LeaveApplication::PAY_MODE_WITHOUT_PAY, $payModeWithoutPay);
    }

    public function test_validate_monetization_eligibility_and_minimum(): void
    {
        $vlType = new LeaveType;
        $vlType->id = 1;
        $vlType->name = 'Vacation Leave';

        $slType = new LeaveType;
        $slType->id = 2;
        $slType->name = 'Sick Leave';

        $otherType = new LeaveType;
        $otherType->id = 3;
        $otherType->name = 'Maternity Leave';

        // VL and SL are eligible
        $this->assertNull($this->service->validateMonetizationLeaveTypeEligibility($vlType));
        $this->assertNull($this->service->validateMonetizationLeaveTypeEligibility($slType));

        // Other type rejected
        $rejectedType = $this->service->validateMonetizationLeaveTypeEligibility($otherType);
        $this->assertInstanceOf(JsonResponse::class, $rejectedType);
        $this->assertSame(422, $rejectedType->getStatusCode());

        // Minimum requested days: < 10 days rejected, >= 10 days accepted
        $rejectedMin = $this->service->validateMonetizationRequestMinimum(5.0);
        $this->assertInstanceOf(JsonResponse::class, $rejectedMin);
        $this->assertSame(422, $rejectedMin->getStatusCode());

        $this->assertNull($this->service->validateMonetizationRequestMinimum(10.0));
    }

    public function test_validate_monetization_balance_rules(): void
    {
        // Less than 15 credits balance rejected
        $responseLow = $this->service->validateMonetizationBalanceRules(currentBalance: 12.0, requestedDays: 10.0);
        $this->assertInstanceOf(JsonResponse::class, $responseLow);
        $this->assertSame(422, $responseLow->getStatusCode());

        // Requested exceeds available
        $responseExceed = $this->service->validateMonetizationBalanceRules(currentBalance: 20.0, requestedDays: 25.0);
        $this->assertInstanceOf(JsonResponse::class, $responseExceed);
        $this->assertSame(422, $responseExceed->getStatusCode());

        // Valid
        $responseValid = $this->service->validateMonetizationBalanceRules(currentBalance: 20.0, requestedDays: 10.0);
        $this->assertNull($responseValid);
    }
}
