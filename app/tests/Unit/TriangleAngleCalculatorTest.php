<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\TriangleAngleCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TriangleAngleCalculatorTest extends TestCase
{
    public function test_known_3_4_5_triangle_returns_a_right_included_angle(): void
    {
        $result = TriangleAngleCalculator::includedAngleDeg(3, 4, 5);

        $this->assertSame(TriangleAngleCalculator::METHOD, $result['method']);
        $this->assertEqualsWithDelta(90.0, $result['angle_deg'], 1e-12);
    }

    public function test_side_order_selects_the_angle_between_the_two_named_adjacent_sides(): void
    {
        // A 3-4-5 slope triangle: angle between run=3 and hypotenuse=5,
        // opposite rise=4.
        $result = TriangleAngleCalculator::includedAngleDeg(3, 5, 4);

        $this->assertEqualsWithDelta(rad2deg(atan2(4, 3)), $result['angle_deg'], 1e-12);
    }

    public function test_uniformly_scaled_sides_preserve_the_included_angle(): void
    {
        $millimetres = TriangleAngleCalculator::includedAngleDeg(300, 500, 400);
        $micrometres = TriangleAngleCalculator::includedAngleDeg(300_000, 500_000, 400_000);

        $this->assertEqualsWithDelta($millimetres['angle_deg'], $micrometres['angle_deg'], 1e-12);
    }

    public function test_non_triangle_side_lengths_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TriangleAngleCalculator::includedAngleDeg(1, 2, 3);
    }

    public function test_nonpositive_side_lengths_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TriangleAngleCalculator::includedAngleDeg(0, 4, 5);
    }

    public function test_non_finite_side_lengths_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        TriangleAngleCalculator::includedAngleDeg(3, 5, INF);
    }
}
