<?php

namespace Tests\Feature;

use App\Http\Controllers\LeaveApplicationController;
use App\Models\LeaveApplication;
use ReflectionClass;
use Tests\TestCase;

class LeaveApplicationCalculationDelegationTest extends TestCase
{
    public function test_controller_can_be_resolved_from_container(): void
    {
        $controller = app(LeaveApplicationController::class);
        $this->assertInstanceOf(LeaveApplicationController::class, $controller);
    }

    public function test_delegated_calculation_methods_on_controller(): void
    {
        $controller = app(LeaveApplicationController::class);
        $reflection = new ReflectionClass($controller);

        $computeMethod = $reflection->getMethod('computeDeductibleDays');
        $computeMethod->setAccessible(true);

        $result = $computeMethod->invoke(
            $controller,
            2.0,
            ['2026-06-01', '2026-06-02'],
            ['2026-06-01' => 'WITH PAY', '2026-06-02' => 'WITHOUT PAY'],
            null,
            false,
            LeaveApplication::PAY_MODE_WITH_PAY,
            null
        );

        $this->assertEquals(1.0, $result);

        $resolveDeductibleMethod = $reflection->getMethod('resolveApplicationDeductibleDays');
        $resolveDeductibleMethod->setAccessible(true);

        $app = new LeaveApplication;
        $app->total_days = 3.0;
        $app->deductible_days = 2.5;

        $deductible = $resolveDeductibleMethod->invoke($controller, $app);
        $this->assertEquals(2.5, $deductible);
    }

    public function test_delegated_eligibility_methods_on_controller(): void
    {
        $controller = app(LeaveApplicationController::class);
        $reflection = new ReflectionClass($controller);

        $monetizationMinMethod = $reflection->getMethod('validateMonetizationRequestMinimum');
        $monetizationMinMethod->setAccessible(true);

        $response = $monetizationMinMethod->invoke($controller, 5.0);
        $this->assertNotNull($response);
        $this->assertSame(422, $response->getStatusCode());

        $this->assertNull($monetizationMinMethod->invoke($controller, 10.0));

        $sickLeavePayModeMethod = $reflection->getMethod('resolveSickLeavePayModeFromFilingWindow');
        $sickLeavePayModeMethod->setAccessible(true);

        $mode = $sickLeavePayModeMethod->invoke($controller, ['2026-06-01'], '2026-06-03');
        $this->assertSame(LeaveApplication::PAY_MODE_WITH_PAY, $mode);
    }
}
