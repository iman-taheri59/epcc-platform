<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BranchPadGeometryCalculator as PadGeometry;
use App\Services\BranchReinforcementCalculator as Reinforcement;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BranchPadGeometryCalculatorTest extends TestCase
{
    public function test_centred_saddle_perimeter_spans_follow_contact_curve_and_d2_zone(): void
    {
        // NPS 6 x 2, Schedule 80 walls after the 0.875 mill factor;
        // 1.5 mm governing allowance. PAD perimeter v2 is restricted to an
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
        $this->assertEqualsWithDelta(
            $geometry['saddle_contact_arc_mm'],
            $geometry['saddle_contact_profile']['developed_contact_arc_span_mm'],
            1e-12,
        );
        $this->assertFalse($geometry['saddle_contact_profile']['outer_pad_perimeter_defined']);
        $this->assertEqualsWithDelta(23.455, $geometry['pad_outer_perimeter_profile']['edge_extension_mm'], 1e-9);
        $this->assertSame('CENTERED_90_DEGREE_DEVELOPED_CONTACT_OFFSET_V1', $geometry['pad_outer_perimeter_profile']['method']);
        $this->assertEqualsWithDelta(108.58089800541906, $geometry['pad_major_axis_mm'], 1e-9);
        $this->assertEqualsWithDelta(107.21, $geometry['pad_minor_axis_mm'], 1e-9);
        $this->assertEqualsWithDelta($geometry['pad_major_axis_mm'], $geometry['pad_outer_perimeter_profile']['developed_circumferential_span_mm'], 1e-12);
        $this->assertEqualsWithDelta($geometry['pad_minor_axis_mm'], $geometry['pad_outer_perimeter_profile']['span_along_header_mm'], 1e-12);
        $this->assertFalse($geometry['pad_outer_perimeter_profile']['is_fabrication_outline']);
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
        $this->assertEqualsWithDelta(72.078776058642546, $smallerHeader['saddle_contact_arc_mm'], 1e-9);
        $this->assertEqualsWithDelta(64.35011087932844, $largerHeader['saddle_contact_arc_mm'], 1e-9);
        $this->assertEqualsWithDelta(152.07877605864255, $smallerHeader['pad_major_axis_mm'], 1e-9);
        $this->assertEqualsWithDelta(184.35011087932844, $largerHeader['pad_major_axis_mm'], 1e-9);
        $this->assertEqualsWithDelta(40.0, $smallerHeader['pad_outer_perimeter_profile']['edge_extension_mm'], 1e-9);
        $this->assertEqualsWithDelta(60.0, $largerHeader['pad_outer_perimeter_profile']['edge_extension_mm'], 1e-9);
        $this->assertNotEquals($smallerHeader['pad_minor_axis_mm'], $largerHeader['pad_minor_axis_mm']);
        $this->assertNotEquals($smallerHeader['pad_major_axis_mm'], $largerHeader['pad_major_axis_mm']);
        $this->assertEqualsWithDelta(140.0, $smallerHeader['pad_minor_axis_mm'], 1e-9);
        $this->assertEqualsWithDelta(180.0, $largerHeader['pad_minor_axis_mm'], 1e-9);
    }

    public function test_uncapped_minor_axis_remains_the_longitudinal_zone_span_when_header_od_changes(): void
    {
        $smallerHeader = PadGeometry::forCenteredRightAngleSaddle(
            Dh: 168.3, Th: 10.0, Db: 60.3, Tb: 5.0, c: 1.5,
        );
        $largerHeader = PadGeometry::forCenteredRightAngleSaddle(
            Dh: 219.1, Th: 10.0, Db: 60.3, Tb: 5.0, c: 1.5,
        );

        $this->assertEqualsWithDelta(53.3, $smallerHeader['d2_mm'], 1e-9);
        $this->assertEqualsWithDelta($smallerHeader['d2_mm'], $largerHeader['d2_mm'], 1e-9);
        $this->assertEqualsWithDelta(106.6, $smallerHeader['pad_minor_axis_mm'], 1e-9);
        $this->assertEqualsWithDelta($smallerHeader['pad_minor_axis_mm'], $largerHeader['pad_minor_axis_mm'], 1e-9);
        $this->assertEqualsWithDelta(61.67089800541906, $smallerHeader['saddle_contact_arc_mm'], 1e-9);
        $this->assertEqualsWithDelta(61.08841022966208, $largerHeader['saddle_contact_arc_mm'], 1e-9);
        $this->assertEqualsWithDelta(107.97089800541906, $smallerHeader['pad_major_axis_mm'], 1e-9);
        $this->assertEqualsWithDelta(107.38841022966208, $largerHeader['pad_major_axis_mm'], 1e-9);
        $this->assertEqualsWithDelta($smallerHeader['saddle_contact_arc_mm'], $smallerHeader['saddle_contact_profile']['developed_contact_arc_span_mm'], 1e-12);
        $this->assertEqualsWithDelta($largerHeader['saddle_contact_arc_mm'], $largerHeader['saddle_contact_profile']['developed_contact_arc_span_mm'], 1e-12);
        $this->assertNotEquals($smallerHeader['pad_major_axis_mm'], $largerHeader['pad_major_axis_mm']);
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
