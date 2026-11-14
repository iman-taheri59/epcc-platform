<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BranchPadSaddlePadPerimeterCalculator as PadPerimeter;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BranchPadSaddlePadPerimeterCalculatorTest extends TestCase
{
    public function test_outer_perimeter_is_a_closed_shell_surface_offset_distinct_from_contact_curve(): void
    {
        $profile = PadPerimeter::forCenteredRightAngle(
            headerOdMm: 168.3,
            branchOdMm: 60.3,
            reinforcementZoneHalfSpanMm: 53.605,
        );
        $points = $profile['sampled_points_mm'];
        $openPoints = array_slice($points, 0, -1);
        $expectedContactArc = 61.67089800541906;
        $expectedExtension = 53.605 - 60.3 / 2.0;

        $this->assertSame(PadPerimeter::METHOD, $profile['method']);
        $this->assertSame('CLOSED_DISK_MINKOWSKI_OFFSET_OF_THE_IDEAL_CONTACT_FOOTPRINT_ON_THE_DEVELOPED_HEADER_OD', $profile['construction']);
        $this->assertEqualsWithDelta($expectedExtension, $profile['edge_extension_mm'], 1e-12);
        $this->assertSame('REINFORCEMENT_ZONE_HALF_SPAN_MINUS_BRANCH_OD_RADIUS', $profile['edge_extension_basis']);
        $this->assertEqualsWithDelta(53.605, $profile['header_axis_half_extent_mm'], 1e-12);
        $this->assertEqualsWithDelta($expectedContactArc / 2.0 + $expectedExtension, $profile['developed_circumferential_half_extent_mm'], 1e-9);
        $this->assertEqualsWithDelta(107.21, $profile['span_along_header_mm'], 1e-12);
        $this->assertEqualsWithDelta($expectedContactArc + 2.0 * $expectedExtension, $profile['developed_circumferential_span_mm'], 1e-9);
        $this->assertCount(2, $profile['sample_point_columns']);
        $this->assertGreaterThan(64, count($openPoints));
        $this->assertGreaterThanOrEqual(4, $profile['sample_offset_disk_segments']);
        $this->assertSame($openPoints[0], $points[array_key_last($points)]);
        $this->assertTrue($profile['sampled_points_are_visualization_aid_only']);
        $this->assertFalse($profile['uses_user_theta']);
        $this->assertFalse($profile['uses_component_rotation']);
        $this->assertFalse($profile['includes_wall_thickness']);
        $this->assertFalse($profile['includes_fit_up_clearance']);
        $this->assertFalse($profile['includes_fabrication_tolerance']);
        $this->assertFalse($profile['is_fabrication_outline']);

        $axisX = array_column($openPoints, 0);
        $developedArc = array_column($openPoints, 1);
        $this->assertEqualsWithDelta(53.605, max(array_map('abs', $axisX)), 1e-8);
        $this->assertEqualsWithDelta($expectedContactArc / 2.0 + $expectedExtension, max(array_map('abs', $developedArc)), 1e-8);

        foreach ($openPoints as [$x, $s]) {
            $this->assertHasPoint($openPoints, -$x, $s);
            $this->assertHasPoint($openPoints, $x, -$s);
        }
    }

    public function test_zero_extension_returns_the_closed_contact_footprint_without_inventing_a_margin(): void
    {
        $profile = PadPerimeter::forCenteredRightAngle(
            headerOdMm: 200.0,
            branchOdMm: 100.0,
            reinforcementZoneHalfSpanMm: 50.0,
            contactSegments: 32,
            offsetDiskSegments: 32,
        );

        $this->assertEqualsWithDelta(0.0, $profile['edge_extension_mm'], 1e-12);
        $this->assertCount(33, $profile['sampled_points_mm']);
        $this->assertEqualsWithDelta(100.0, $profile['span_along_header_mm'], 1e-12);
        $this->assertEqualsWithDelta(104.71975511965978, $profile['developed_circumferential_span_mm'], 1e-9);
        $this->assertSame($profile['sampled_points_mm'][0], $profile['sampled_points_mm'][array_key_last($profile['sampled_points_mm'])]);
    }

    public function test_equal_od_case_can_be_offset_without_using_a_user_rotation_frame(): void
    {
        $profile = PadPerimeter::forCenteredRightAngle(
            headerOdMm: 100.0,
            branchOdMm: 100.0,
            reinforcementZoneHalfSpanMm: 60.0,
        );

        $this->assertEqualsWithDelta(10.0, $profile['edge_extension_mm'], 1e-12);
        $this->assertEqualsWithDelta(120.0, $profile['span_along_header_mm'], 1e-12);
        $this->assertEqualsWithDelta(100.0 * M_PI / 2.0 + 20.0, $profile['developed_circumferential_span_mm'], 1e-9);
        $this->assertSame($profile['sampled_points_mm'][0], $profile['sampled_points_mm'][array_key_last($profile['sampled_points_mm'])]);
    }

    public function test_zone_span_outside_the_header_cap_and_bad_sample_resolutions_are_refused(): void
    {
        try {
            PadPerimeter::forCenteredRightAngle(168.3, 60.3, 168.301);
            $this->fail('The B31.3 reinforcement-zone span cannot exceed its header OD cap.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('header OD cap', $exception->getMessage());
        }

        try {
            PadPerimeter::forCenteredRightAngle(168.3, 60.3, 30.0);
            $this->fail('An outer PAD perimeter must not require a negative edge extension.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('non-negative PAD edge extension', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        PadPerimeter::forCenteredRightAngle(168.3, 60.3, 53.605, contactSegments: 10);
    }

    /** @param list<array{0:float,1:float}> $points */
    private function assertHasPoint(array $points, float $targetX, float $targetS): void
    {
        foreach ($points as [$x, $s]) {
            if (abs($x - $targetX) <= 1e-7 && abs($s - $targetS) <= 1e-7) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail(sprintf('No symmetric outline point found at (%.9f, %.9f).', $targetX, $targetS));
    }
}
