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

    public function test_is_weekend_date_key(): void
    {
        // 2026-06-06 is Saturday, 2026-06-07 is Sunday, 2026-06-08 is Monday
        $this->assertTrue($this->service->isWeekendDateKey('2026-06-06'));
        $this->assertTrue($this->service->isWeekendDateKey('2026-06-07'));
        $this->assertFalse($this->service->isWeekendDateKey('2026-06-08'));
        $this->assertFalse($this->service->isWeekendDateKey('invalid-date'));
    }

    public function test_normalize_vacation_detail_value(): void
    {
        $this->assertSame('Abroad', $this->service->normalizeVacationDetailValue('abroad'));
        $this->assertSame('Abroad', $this->service->normalizeVacationDetailValue('Travel Abroad'));
        $this->assertSame('Within the Philippines', $this->service->normalizeVacationDetailValue('Within the Philippines'));
        $this->assertNull($this->service->normalizeVacationDetailValue(''));
        $this->assertNull($this->service->normalizeVacationDetailValue(null));
        $this->assertNull($this->service->normalizeVacationDetailValue('other text'));
    }

    public function test_should_force_abroad_weekend_without_pay(): void
    {
        $vlType = new LeaveType;
        $vlType->id = 1;
        $vlType->name = 'Vacation Leave';

        $slType = new LeaveType;
        $slType->id = 2;
        $slType->name = 'Sick Leave';

        $detailsAbroad = json_encode(['vacation_detail' => 'Abroad']);
        $detailsLocal = json_encode(['vacation_detail' => 'Within the Philippines']);

        $this->assertTrue($this->service->shouldForceAbroadWeekendWithoutPay($vlType, 1, $detailsAbroad));
        $this->assertFalse($this->service->shouldForceAbroadWeekendWithoutPay($vlType, 1, $detailsLocal));
        $this->assertFalse($this->service->shouldForceAbroadWeekendWithoutPay($slType, 2, $detailsAbroad));
    }

    public function test_filter_out_abroad_weekend_dates(): void
    {
        $vlType = new LeaveType;
        $vlType->id = 1;
        $vlType->name = 'Vacation Leave';

        $detailsAbroad = json_encode(['vacation_detail' => 'Abroad']);
        // 2026-06-05 (Fri), 2026-06-06 (Sat), 2026-06-07 (Sun), 2026-06-08 (Mon)
        $dates = ['2026-06-05', '2026-06-06', '2026-06-07', '2026-06-08'];

        $filtered = $this->service->filterOutAbroadWeekendDates($dates, $vlType, 1, $detailsAbroad);
        $this->assertEquals(['2026-06-05', '2026-06-08'], $filtered);

        // Not abroad - keeps all dates
        $unfiltered = $this->service->filterOutAbroadWeekendDates($dates, $vlType, 1, null);
        $this->assertEquals($dates, $unfiltered);
    }

    public function test_resolve_pay_mode_from_selected_dates(): void
    {
        $dates = ['2026-06-01', '2026-06-02'];

        // If any selected date is with pay, resolves to WITH_PAY
        $payStatus = [
            '2026-06-01' => LeaveApplication::PAY_MODE_WITH_PAY,
            '2026-06-02' => LeaveApplication::PAY_MODE_WITHOUT_PAY,
        ];
        $this->assertSame(
            LeaveApplication::PAY_MODE_WITH_PAY,
            $this->service->resolvePayModeFromSelectedDates($dates, $payStatus, LeaveApplication::PAY_MODE_WITHOUT_PAY)
        );

        // If all are without pay, resolves to WITHOUT_PAY
        $payStatusAllWithout = [
            '2026-06-01' => LeaveApplication::PAY_MODE_WITHOUT_PAY,
            '2026-06-02' => LeaveApplication::PAY_MODE_WITHOUT_PAY,
        ];
        $this->assertSame(
            LeaveApplication::PAY_MODE_WITHOUT_PAY,
            $this->service->resolvePayModeFromSelectedDates($dates, $payStatusAllWithout, LeaveApplication::PAY_MODE_WITH_PAY)
        );
    }

    public function test_apply_regular_leave_policy_monetization(): void
    {
        $leaveType = new LeaveType;
        $leaveType->id = 1;
        $leaveType->name = 'Vacation Leave';

        $result = $this->service->applyRegularLeavePolicy(
            leaveType: $leaveType,
            totalDays: 10.0,
            selectedDates: null,
            selectedDateCoverage: null,
            selectedDatePayStatus: null,
            payMode: 'with_pay',
            isMonetization: true
        );

        $this->assertIsArray($result);
        $this->assertSame(LeaveApplication::PAY_MODE_WITH_PAY, $result['pay_mode']);
        $this->assertSame(10.0, $result['deductible_days']);
        $this->assertFalse($result['attachment_required']);
        $this->assertNull($result['cto_deducted_hours']);
    }

    public function test_apply_regular_leave_policy_attachment_required_missing(): void
    {
        $sickLeave = new LeaveType;
        $sickLeave->id = 2;
        $sickLeave->name = 'Sick Leave';

        // 5 or more days requires attachment
        $result = $this->service->applyRegularLeavePolicy(
            leaveType: $sickLeave,
            totalDays: 5.0,
            selectedDates: ['2026-06-01', '2026-06-02', '2026-06-03', '2026-06-04', '2026-06-05'],
            selectedDateCoverage: null,
            selectedDatePayStatus: null,
            payMode: 'with_pay',
            isMonetization: false,
            attachmentSubmitted: false,
            enforceAttachment: true
        );

        $this->assertInstanceOf(JsonResponse::class, $result);
        $this->assertSame(422, $result->getStatusCode());
        $this->assertStringContainsString('Medical certificate is required', $result->getData(true)['message']);
    }

    public function test_encode_details_of_leave_payload(): void
    {
        $payload = ['vacation_location' => 'Within the Philippines', 'vacation_detail' => 'Cebu'];
        $encoded = $this->service->encodeDetailsOfLeavePayload($payload);
        $this->assertSame(json_encode($payload, JSON_UNESCAPED_UNICODE), $encoded);
    }

    public function test_normalize_office_and_same_office(): void
    {
        $this->assertSame('ACCOUNTING', $this->service->normalizeOffice(' accounting '));
        $this->assertSame('', $this->service->normalizeOffice(null));

        $this->assertTrue($this->service->sameOffice('Accounting Office', ' accounting office '));
        $this->assertFalse($this->service->sameOffice('Accounting', 'HRMO'));
        $this->assertFalse($this->service->sameOffice('', ''));
        $this->assertFalse($this->service->sameOffice(null, null));
    }

    public function test_control_no_candidates(): void
    {
        $this->assertSame([], $this->service->controlNoCandidates(''));
        $this->assertSame([], $this->service->controlNoCandidates('   '));

        $mockEmployee = (object) ['control_no' => '00123'];
        $candidates = $this->service->controlNoCandidates('123', fn ($no) => $mockEmployee);
        $this->assertContains('123', $candidates);
        $this->assertContains('00123', $candidates);
    }

    public function test_assert_erms_employee_is_pulled(): void
    {
        $response = $this->service->assertErmsEmployeeIsPulled('NON_EXISTENT_999999', fn ($no) => []);
        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('not yet enabled for LMS leave modules', $response->getData(true)['message']);
    }

    public function test_admin_department_can_access_employee(): void
    {
        $employee = (object) ['office' => 'City Health Office'];
        $this->assertTrue($this->service->adminDepartmentCanAccessEmployee(null, 'City Health Office', '123', $employee));
        $this->assertFalse($this->service->adminDepartmentCanAccessEmployee(null, 'Mayor Office', '123', $employee));
        $this->assertFalse($this->service->adminDepartmentCanAccessEmployee(null, 'City Health Office', '123', null));
    }

    public function test_monetization_components_helpers(): void
    {
        $components = [
            ['leave_type_id' => 1, 'leave_type_name' => 'Vacation Leave', 'days' => 5.0],
            ['leave_type_id' => 2, 'leave_type_name' => 'Sick Leave', 'days' => 10.0],
        ];

        $this->assertSame(15.0, $this->service->sumMonetizationComponentDays($components));
        $this->assertSame('Vacation Leave (5 days), Sick Leave (10 days)', $this->service->formatMonetizationComponentsLabel($components, true));
        $this->assertSame('Vacation Leave, Sick Leave', $this->service->formatMonetizationComponentsLabel($components, false));
        $this->assertSame('leave credits', $this->service->formatMonetizationComponentsLabel([], true));

        $this->assertFalse($this->service->monetizationComponentsRequireAttachment($components));
        $highDaysComponents = [
            ['leave_type_id' => 1, 'leave_type_name' => 'Vacation Leave', 'days' => 31.0],
        ];
        $this->assertTrue($this->service->monetizationComponentsRequireAttachment($highDaysComponents));

        $serialized = $this->service->serializeMonetizationLeaveCreditComponents($components);
        $this->assertCount(2, $serialized);
        $this->assertSame(1, $serialized[0]['leave_type_id']);
        $this->assertSame(5.0, $serialized[0]['days']);

        $primaryId = $this->service->resolvePrimaryMonetizationLeaveTypeId($components, 2);
        $this->assertSame(2, $primaryId);
    }

    public function test_resolve_credit_based_pay_allocation(): void
    {
        $selectedDates = ['2026-06-01', '2026-06-02', '2026-06-03'];

        // Zero credits -> WITHOUT_PAY
        $zeroAlloc = $this->service->resolveCreditBasedPayAllocation(
            selectedDates: $selectedDates,
            selectedDateCoverage: null,
            totalDays: 3.0,
            availableCredits: 0.0
        );
        $this->assertSame(LeaveApplication::PAY_MODE_WITHOUT_PAY, $zeroAlloc['pay_mode']);
        $this->assertSame(0.0, $zeroAlloc['deductible_days']);
        $this->assertNull($zeroAlloc['selected_date_pay_status']);

        // Sufficient credits -> WITH_PAY for all 3 days
        $fullAlloc = $this->service->resolveCreditBasedPayAllocation(
            selectedDates: $selectedDates,
            selectedDateCoverage: null,
            totalDays: 3.0,
            availableCredits: 5.0
        );
        $this->assertSame(LeaveApplication::PAY_MODE_WITH_PAY, $fullAlloc['pay_mode']);
        $this->assertSame(3.0, $fullAlloc['deductible_days']);

        // Partial credits -> 2 days WITH_PAY, 1 day WITHOUT_PAY
        $partialAlloc = $this->service->resolveCreditBasedPayAllocation(
            selectedDates: $selectedDates,
            selectedDateCoverage: null,
            totalDays: 3.0,
            availableCredits: 2.0
        );
        $this->assertSame(LeaveApplication::PAY_MODE_WITH_PAY, $partialAlloc['pay_mode']);
        $this->assertSame(2.0, $partialAlloc['deductible_days']);
        $this->assertSame([
            '2026-06-03' => LeaveApplication::PAY_MODE_WITHOUT_PAY,
        ], $partialAlloc['selected_date_pay_status']);
    }

    public function test_validate_regular_leave_eligibility_unavailable_leave_type(): void
    {
        $response = $this->service->validateRegularLeaveEligibility(
            employeeControlNo: 'EMP-999',
            leaveTypeId: 999999,
            requestedDays: 1.0
        );

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Selected leave type is not available.', $response->getData(true)['message']);
    }
}
