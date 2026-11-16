<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PlanarRunRiseAngleCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlanarRunRiseAngleCalculatorTest extends TestCase
{
    public function test_signed_run_and_rise_return_the_planar_atan2_angle(): void
    {
        $result = PlanarRunRiseAngleCalculator::fromSignedRunRiseDeg(3, 4);

        $this->assertSame(PlanarRunRiseAngleCalculator::METHOD, $result['method']);
        $this->assertEqualsWithDelta(53.13010235415598, $result['angle_deg'], 1e-12);
    }

    public function test_signed_quadrants_are_preserved(): void
    {
        $cases = [
            [3.0, 4.0, 53.13010235415598],
            [3.0, -4.0, -53.13010235415598],
            [-3.0, 4.0, 126.86989764584402],
            [-3.0, -4.0, -126.86989764584402],
        ];

        foreach ($cases as [$run, $rise, $expected]) {
            $result = PlanarRunRiseAngleCalculator::fromSignedRunRiseDeg($run, $rise);

            $this->assertEqualsWithDelta($expected, $result['angle_deg'], 1e-12);
        }
    }

    public function test_axis_aligned_coordinates_return_quarter_turns_or_half_turn(): void
    {
        $cases = [
            [3.0, 0.0, 0.0],
            [0.0, 4.0, 90.0],
            [-3.0, 0.0, 180.0],
            [0.0, -4.0, -90.0],
        ];

        foreach ($cases as [$run, $rise, $expected]) {
            $result = PlanarRunRiseAngleCalculator::fromSignedRunRiseDeg($run, $rise);

            $this->assertEqualsWithDelta($expected, $result['angle_deg'], 1e-12);
        }
    }

    public function test_uniformly_scaled_coordinates_preserve_the_angle(): void
    {
        $small = PlanarRunRiseAngleCalculator::fromSignedRunRiseDeg(3, 4);
        $large = PlanarRunRiseAngleCalculator::fromSignedRunRiseDeg(300_000, 400_000);

        $this->assertEqualsWithDelta($small['angle_deg'], $large['angle_deg'], 1e-12);
    }

    public function test_zero_coordinate_vector_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanarRunRiseAngleCalculator::fromSignedRunRiseDeg(0, 0);
    }

    public function test_non_finite_run_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanarRunRiseAngleCalculator::fromSignedRunRiseDeg(INF, 4);
    }

    public function test_non_finite_rise_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PlanarRunRiseAngleCalculator::fromSignedRunRiseDeg(3, NAN);
    }
}
