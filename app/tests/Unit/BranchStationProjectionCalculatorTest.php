<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BranchStationProjectionCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BranchStationProjectionCalculatorTest extends TestCase
{
    public function test_projection_is_translation_and_orientation_independent_and_keeps_both_endpoint_origins(): void
    {
        $projection = BranchStationProjectionCalculator::measure(
            [10.0, 20.0, 30.0],
            [16.0, 28.0, 30.0],
            [11.5, 22.0, 34.0],
        );

        self::assertEqualsWithDelta(10.0, $projection['header_length_from_pose_mm'], 1e-12);
        self::assertEqualsWithDelta(2.5, $projection['station_from_port_1_mm'], 1e-12);
        self::assertEqualsWithDelta(7.5, $projection['station_from_port_2_mm'], 1e-12);
        self::assertEqualsWithDelta(4.0, $projection['perpendicular_distance_to_header_axis_mm'], 1e-12);
    }

    public function test_degenerate_header_centreline_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BranchStationProjectionCalculator::measure(
            [1.0, 2.0, 3.0],
            [1.0, 2.0, 3.0],
            [4.0, 5.0, 6.0],
        );
    }
}
