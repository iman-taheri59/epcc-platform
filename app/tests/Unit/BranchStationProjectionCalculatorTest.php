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
        self::assertTrue($projection['axial_projection_within_endpoint_span']);
        self::assertSame(0.0, $projection['axial_overrun_beyond_port_1_mm']);
        self::assertSame(0.0, $projection['axial_overrun_beyond_port_2_mm']);
    }

    public function test_projection_reports_endpoint_overrun_without_an_acceptance_verdict(): void
    {
        foreach ([
            [
                'point' => [-3.0, 4.0, 0.0],
                'station_1' => -3.0,
                'station_2' => 13.0,
                'overrun_1' => 3.0,
                'overrun_2' => 0.0,
            ],
            [
                'point' => [13.0, 4.0, 0.0],
                'station_1' => 13.0,
                'station_2' => -3.0,
                'overrun_1' => 0.0,
                'overrun_2' => 3.0,
            ],
        ] as $case) {
            $projection = BranchStationProjectionCalculator::measure(
                [0.0, 0.0, 0.0],
                [10.0, 0.0, 0.0],
                $case['point'],
            );

            self::assertFalse($projection['axial_projection_within_endpoint_span']);
            self::assertEqualsWithDelta($case['station_1'], $projection['station_from_port_1_mm'], 1e-12);
            self::assertEqualsWithDelta($case['station_2'], $projection['station_from_port_2_mm'], 1e-12);
            self::assertEqualsWithDelta($case['overrun_1'], $projection['axial_overrun_beyond_port_1_mm'], 1e-12);
            self::assertEqualsWithDelta($case['overrun_2'], $projection['axial_overrun_beyond_port_2_mm'], 1e-12);
        }
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
