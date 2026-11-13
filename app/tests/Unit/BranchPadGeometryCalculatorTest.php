<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BranchPadGeometryCalculator as PadGeometry;
use App\Services\BranchReinforcementCalculator as Reinforcement;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BranchPadGeometryCalculatorTest extends TestCase
{
    public function test_centred_saddle_axes_follow_cylinder_arc_and_the_existing_d2_zone(): void
    {
        // NPS 6 x 2, Schedule 80 walls after the 0.875 mill factor;
        // 1.5 mm governing allowance. PAD geometry v1 is restricted to an
        // explicitly recorded beta of 90 degrees.
        $geometry = PadGeometry::forCenteredRightAngleSaddle(
            Dh: 168.3,
            Th: 9.59875,
            Db: 60.3,
            Tb: 4.8475,
            c: 1.5,
        );

        $this->assertEqualsWithDelta(53.605, $geometry['d1_mm'], 1e-9);
        $this->assertEqualsWithDelta(53.605, $geometry['d2_mm'], 1e-9);
        $this->assertEqualsWithDelta(26.8025, $geometry['zone_extension_each_side_mm'], 1e-9);
        $this->assertEqualsWithDelta(61.67089800541906, $geometry['saddle_contact_arc_mm'], 1e-9);
        $this->assertEqualsWithDelta(115.27589800541907, $geometry['pad_major_axis_mm'], 1e-9);
        $this->assertEqualsWithDelta(107.21, $geometry['pad_minor_axis_mm'], 1e-9);
        $this->assertSame('AROUND_HEADER', $geometry['pad_major_axis_direction']);
        $this->assertSame('ALONG_HEADER', $geometry['pad_minor_axis_direction']);
        $this->assertSame(90.0, $geometry['beta_deg']);
        $this->assertSame(PadGeometry::METHOD, $geometry['method']);

        // The zone values are the same d1/d2 used by the existing
        // area-replacement calculation; this geometry helper does not replace
        // its area balance or thickness logic.
        $areaCheck = Reinforcement::evaluate(
            Dh: 168.3,
            Th: 9.59875,
            th: 2.0,
            Db: 60.3,
            Tb: 4.8475,
            tb: 1.0,
            c: 1.5,
            betaDeg: 90.0,
        );
        $this->assertEqualsWithDelta($areaCheck['d1'], $geometry['d1_mm'], 1e-9);
        $this->assertEqualsWithDelta($areaCheck['d2'], $geometry['d2_mm'], 1e-9);
    }

    public function test_both_axis_values_change_when_header_geometry_changes_the_zone_cap(): void
    {
        $smallerHeader = PadGeometry::forCenteredRightAngleSaddle(
            Dh: 70.0, Th: 60.0, Db: 60.0, Tb: 15.0, c: 0.0,
        );
        $largerHeader = PadGeometry::forCenteredRightAngleSaddle(
            Dh: 100.0, Th: 60.0, Db: 60.0, Tb: 15.0, c: 0.0,
        );

        $this->assertEqualsWithDelta(70.0, $smallerHeader['d2_mm'], 1e-9);
        $this->assertEqualsWithDelta(90.0, $largerHeader['d2_mm'], 1e-9);
        $this->assertNotEquals($smallerHeader['pad_minor_axis_mm'], $largerHeader['pad_minor_axis_mm']);
        $this->assertNotEquals($smallerHeader['pad_major_axis_mm'], $largerHeader['pad_major_axis_mm']);
        $this->assertEqualsWithDelta(140.0, $smallerHeader['pad_minor_axis_mm'], 1e-9);
        $this->assertEqualsWithDelta(180.0, $largerHeader['pad_minor_axis_mm'], 1e-9);
    }

    public function test_branch_larger_than_header_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PadGeometry::forCenteredRightAngleSaddle(
            Dh: 60.0, Th: 6.0, Db: 73.0, Tb: 5.0, c: 0.0,
        );
    }

    public function test_allowance_cannot_consume_either_effective_wall(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PadGeometry::forCenteredRightAngleSaddle(
            Dh: 168.3, Th: 6.0, Db: 60.3, Tb: 4.0, c: 4.0,
        );
    }
}
