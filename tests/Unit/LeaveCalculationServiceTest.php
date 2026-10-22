<?php

namespace Tests\Unit;

use App\Models\LeaveApplication;
use App\Services\Leave\LeaveCalculationService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class LeaveCalculationServiceTest extends TestCase
{
    private LeaveCalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(LeaveCalculationService::class);
    }

    public function test_normalize_date_key(): void
    {
        $this->assertSame('2026-05-15', $this->service->normalizeDateKey('2026-05-15'));
        $this->assertSame('2026-05-15', $this->service->normalizeDateKey(CarbonImmutable::parse('2026-05-15 10:00:00')));
        $this->assertNull($this->service->normalizeDateKey('0'));
        $this->assertNull($this->service->normalizeDateKey('12'));
        $this->assertNull($this->service->normalizeDateKey(''));
        $this->assertNull($this->service->normalizeDateKey(null));
        $this->assertNull($this->service->normalizeDateKey('not-a-valid-date-xyz'));
    }

    public function test_normalize_pay_mode(): void
    {
        $this->assertSame(LeaveApplication::PAY_MODE_WITH_PAY, $this->service->normalizePayMode('WP'));
        $this->assertSame(LeaveApplication::PAY_MODE_WITHOUT_PAY, $this->service->normalizePayMode('WOP'));
        $this->assertSame(LeaveApplication::PAY_MODE_WITH_PAY, $this->service->normalizePayMode(null));
        $this->assertSame(LeaveApplication::PAY_MODE_WITH_PAY, $this->service->normalizePayMode('WOP', isMonetization: true));
    }

    public function test_resolve_pay_mode_from_status_value(): void
    {
        $this->assertSame(LeaveApplication::PAY_MODE_WITHOUT_PAY, $this->service->resolvePayModeFromStatusValue('wop'));
        $this->assertSame(LeaveApplication::PAY_MODE_WITHOUT_PAY, $this->service->resolvePayModeFromStatusValue('without pay'));
        $this->assertSame(LeaveApplication::PAY_MODE_WITHOUT_PAY, $this->service->resolvePayModeFromStatusValue('unpaid'));

        $this->assertSame(LeaveApplication::PAY_MODE_WITH_PAY, $this->service->resolvePayModeFromStatusValue('wp'));
        $this->assertSame(LeaveApplication::PAY_MODE_WITH_PAY, $this->service->resolvePayModeFromStatusValue('with pay'));
        $this->assertSame(LeaveApplication::PAY_MODE_WITH_PAY, $this->service->resolvePayModeFromStatusValue('paid'));

        $this->assertNull($this->service->resolvePayModeFromStatusValue('unknown'));
        $this->assertNull($this->service->resolvePayModeFromStatusValue(''));
    }

    public function test_compact_selected_date_half_day_portion_map(): void
    {
        $selectedDates = ['2026-06-01', '2026-06-02'];
        $rawPortion = [
            '2026-06-01' => 'AM',
            '2026-06-02' => 'afternoon',
            '2026-06-03' => 'AM', // Should be excluded because not in selectedDates
        ];

        $compacted = $this->service->compactSelectedDateHalfDayPortionMap($rawPortion, $selectedDates);

        $this->assertNotNull($compacted);
        $this->assertSame([
            '2026-06-01' => 'AM',
            '2026-06-02' => 'PM',
        ], $compacted);
    }

    public function test_merge_selected_date_half_day_portion_maps(): void
    {
        $primary = ['2026-06-01' => 'PM'];
        $fallback = ['2026-06-01' => 'AM', '2026-06-02' => 'AM'];

        $merged = $this->service->mergeSelectedDateHalfDayPortionMaps($primary, $fallback);

        $this->assertSame([
            '2026-06-01' => 'PM',
            '2026-06-02' => 'AM',
        ], $merged);
    }

    public function test_synchronize_selected_date_coverage_metadata(): void
    {
        $coverage = ['2026-06-01' => 'whole', '2026-06-02' => 'whole'];
        $portionMap = ['2026-06-02' => 'AM'];
        $selectedDates = ['2026-06-01', '2026-06-02'];

        $synced = $this->service->synchronizeSelectedDateCoverageMetadata($coverage, $portionMap, $selectedDates);

        $this->assertSame([
            '2026-06-02' => 'half',
        ], $synced['selectedDateCoverage']);
        $this->assertSame([
            '2026-06-02' => 'AM',
        ], $synced['selectedDateHalfDayPortion']);
    }

    public function test_compute_deductible_days(): void
    {
        // Whole days with pay
        $days = $this->service->computeDeductibleDays(
            totalDays: 2.0,
            selectedDates: ['2026-06-01', '2026-06-02'],
            selectedDatePayStatus: null,
            selectedDateCoverage: null,
            isMonetization: false,
            payMode: LeaveApplication::PAY_MODE_WITH_PAY
        );
        $this->assertEquals(2.0, $days);

        // Monetization always returns total days
        $daysMonetized = $this->service->computeDeductibleDays(
            totalDays: 10.0,
            selectedDates: [],
            selectedDatePayStatus: null,
            selectedDateCoverage: null,
            isMonetization: true,
            payMode: LeaveApplication::PAY_MODE_WITHOUT_PAY
        );
        $this->assertEquals(10.0, $daysMonetized);

        // Mixed pay status: 1 day with pay, 1 day without pay
        $daysMixed = $this->service->computeDeductibleDays(
            totalDays: 2.0,
            selectedDates: ['2026-06-01', '2026-06-02'],
            selectedDatePayStatus: [
                '2026-06-01' => 'WITH PAY',
                '2026-06-02' => 'WITHOUT PAY',
            ],
            selectedDateCoverage: null,
            isMonetization: false,
            payMode: LeaveApplication::PAY_MODE_WITH_PAY
        );
        $this->assertEquals(1.0, $daysMixed);

        // Half day coverage
        $daysHalf = $this->service->computeDeductibleDays(
            totalDays: 0.5,
            selectedDates: ['2026-06-01'],
            selectedDatePayStatus: null,
            selectedDateCoverage: ['2026-06-01' => 'half'],
            isMonetization: false,
            payMode: LeaveApplication::PAY_MODE_WITH_PAY
        );
        $this->assertEquals(0.5, $daysHalf);
    }

    public function test_resolve_application_deductible_days(): void
    {
        $app = new LeaveApplication;

        // Zero total days
        $app->total_days = 0;
        $this->assertSame(0.0, $this->service->resolveApplicationDeductibleDays($app));

        // Stored deductible_days takes precedence
        $app->total_days = 5;
        $app->deductible_days = 3.5;
        $this->assertSame(3.5, $this->service->resolveApplicationDeductibleDays($app));

        // Monetization returns total days
        $app->deductible_days = null;
        $app->is_monetization = true;
        $this->assertSame(5.0, $this->service->resolveApplicationDeductibleDays($app));

        // Normal with pay
        $app->is_monetization = false;
        $app->pay_mode = LeaveApplication::PAY_MODE_WITH_PAY;
        $this->assertSame(5.0, $this->service->resolveApplicationDeductibleDays($app));

        // Normal without pay
        $app->pay_mode = LeaveApplication::PAY_MODE_WITHOUT_PAY;
        $this->assertSame(0.0, $this->service->resolveApplicationDeductibleDays($app));
    }

    public function test_resolve_application_cto_deducted_hours(): void
    {
        $app = new LeaveApplication;

        // Stored hours returned directly
        $app->cto_deducted_hours = 16.0;
        $this->assertSame(16.0, $this->service->resolveApplicationCtoDeductedHours($app));

        // Non-CTO returns 0.0 when not stored
        $app->cto_deducted_hours = null;
        $app->leave_type_id = 9999;
        $this->assertSame(0.0, $this->service->resolveApplicationCtoDeductedHours($app));
    }

    public function test_resolve_date_coverage_weights_and_sick_leave_pay_status_overrides(): void
    {
        $selectedDates = ['2026-06-01', '2026-06-02', '2026-06-03'];
        $coverage = ['2026-06-02' => 'half'];

        $weights = $this->service->resolveDateCoverageWeights($selectedDates, $coverage, 2.5, null);
        $this->assertSame(1.0, $weights['2026-06-01']);
        $this->assertSame(0.5, $weights['2026-06-02']);
        $this->assertSame(1.0, $weights['2026-06-03']);

        $overrides = $this->service->buildSickLeavePayStatusOverrides($selectedDates, $coverage, 2.5, 1.5, null);
        $this->assertSame([
            '2026-06-01' => LeaveApplication::PAY_MODE_WITH_PAY,
            '2026-06-02' => LeaveApplication::PAY_MODE_WITH_PAY,
            '2026-06-03' => LeaveApplication::PAY_MODE_WITHOUT_PAY,
        ], $overrides);
    }
}
