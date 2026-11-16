<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RunRiseHypotenuseClosureCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RunRiseHypotenuseClosureCalculatorTest extends TestCase
{
    public function test_known_three_four_five_triangle_has_zero_closure_residual(): void
    {
        $result = RunRiseHypotenuseClosureCalculator::measure(3, 4, 5);

        $this->assertSame(RunRiseHypotenuseClosureCalculator::METHOD, $result['method']);
        $this->assertEqualsWithDelta(5.0, $result['calculated_hypotenuse'], 1e-12);
        $this->assertEqualsWithDelta(0.0, $result['closure_residual'], 1e-12);
        $this->assertArrayNotHasKey('accepted', $result, 'This helper must not apply an engineering tolerance.');
    }

    public function test_residual_is_measured_minus_calculated_without_acceptance_policy(): void
    {
        $measuredLong = RunRiseHypotenuseClosureCalculator::measure(3, 4, 5.02);
        $measuredShort = RunRiseHypotenuseClosureCalculator::measure(3, 4, 4.98);

        $this->assertEqualsWithDelta(0.02, $measuredLong['closure_residual'], 1e-12);
        $this->assertEqualsWithDelta(-0.02, $measuredShort['closure_residual'], 1e-12);
    }

    public function test_signed_coordinates_and_one_zero_leg_preserve_the_length_relation(): void
    {
        $negative = RunRiseHypotenuseClosureCalculator::measure(-3, -4, 5);
        $axisAligned = RunRiseHypotenuseClosureCalculator::measure(0, 4, 4);

        $this->assertEqualsWithDelta(0.0, $negative['closure_residual'], 1e-12);
        $this->assertEqualsWithDelta(0.0, $axisAligned['closure_residual'], 1e-12);
    }

    public function test_zero_run_and_rise_vector_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RunRiseHypotenuseClosureCalculator::measure(0, 0, 1);
    }

    public function test_nonpositive_measured_hypotenuse_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RunRiseHypotenuseClosureCalculator::measure(3, 4, 0);
    }

    public function test_non_finite_inputs_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RunRiseHypotenuseClosureCalculator::measure(3, INF, 5);
    }

    public function test_unrepresentable_calculated_hypotenuse_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        RunRiseHypotenuseClosureCalculator::measure(1.7e308, 1.7e308, 1.0e308);
    }
}
