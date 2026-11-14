<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BranchPadSaddleContactProfileCalculator as ContactProfile;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BranchPadSaddleContactProfileCalculatorTest extends TestCase
{
    public function test_sampled_contour_lies_on_both_outer_cylinders_and_closes(): void
    {
        $headerOd = 168.3;
        $branchOd = 60.3;
        $headerRadius = $headerOd / 2.0;
        $branchRadius = $branchOd / 2.0;
        $profile = ContactProfile::forCenteredRightAngle($headerOd, $branchOd);
        $points = ContactProfile::sampledPoints($headerOd, $branchOd);

        $this->assertSame(ContactProfile::METHOD, $profile['method']);
        $this->assertCount(ContactProfile::DEFAULT_SAMPLE_SEGMENTS + 1, $points);
        $this->assertEqualsWithDelta(61.67089800541906, $profile['developed_contact_arc_span_mm'], 1e-9);
        $this->assertEqualsWithDelta($branchOd, $profile['header_axis_contact_span_mm'], 1e-12);
        $this->assertEqualsWithDelta(asin($branchOd / $headerOd), $profile['header_circumferential_angle_limit_rad'], 1e-12);
        $this->assertFalse($profile['uses_user_theta']);
        $this->assertFalse($profile['uses_component_rotation']);
        $this->assertFalse($profile['includes_wall_thickness']);
        $this->assertFalse($profile['includes_fit_up_clearance']);
        $this->assertFalse($profile['includes_fabrication_tolerance']);
        $this->assertFalse($profile['is_fabrication_outline']);
        $this->assertFalse($profile['outer_pad_perimeter_defined']);

        foreach ($points as $point) {
            $x = $point['header_axis_x_mm'];
            $y = $point['header_circumferential_y_mm'];
            $z = $point['header_radial_z_mm'];

            // Branch outer cylinder: x² + y² = Rb².
            $this->assertEqualsWithDelta($branchRadius ** 2, $x ** 2 + $y ** 2, 1e-9);
            // Header outer cylinder: y² + z² = Rh².
            $this->assertEqualsWithDelta($headerRadius ** 2, $y ** 2 + $z ** 2, 1e-9);
            $this->assertGreaterThanOrEqual(0.0, $z);
        }

        $first = $points[0];
        $last = $points[array_key_last($points)];
        foreach (['header_axis_x_mm', 'header_circumferential_y_mm', 'header_radial_z_mm'] as $coordinate) {
            $this->assertEqualsWithDelta($first[$coordinate], $last[$coordinate], 1e-12);
        }

        $this->assertEqualsWithDelta($branchRadius, max(array_map(
            static fn (array $point): float => abs($point['header_axis_x_mm']),
            $points,
        )), 1e-12);
        $this->assertEqualsWithDelta($headerRadius, max(array_column($points, 'header_radial_z_mm')), 1e-12);
        $this->assertEqualsWithDelta(
            $profile['branch_surface_station_min_mm'],
            min(array_column($points, 'header_radial_z_mm')),
            1e-12,
        );
        $this->assertEqualsWithDelta(
            $profile['developed_contact_arc_span_mm'] / 2.0,
            max(array_map(
                static fn (array $point): float => abs($point['header_developed_arc_mm']),
                $points,
            )),
            1e-12,
        );
    }

    public function test_independent_reference_values_cover_multiple_od_pairs_and_profile_symmetry(): void
    {
        $references = [
            [200.0, 100.0, 104.71975511965978, 86.60254037844386],
            [100.0, 80.0, 92.72952180016122, 30.0],
            [219.1, 60.3, 61.08841022966208, 105.31941891218352],
        ];

        foreach ($references as [$headerOd, $branchOd, $contactArc, $stationMin]) {
            $profile = ContactProfile::forCenteredRightAngle($headerOd, $branchOd);
            $points = ContactProfile::sampledPoints($headerOd, $branchOd, segments: 64);

            $this->assertEqualsWithDelta($contactArc, $profile['developed_contact_arc_span_mm'], 1e-9);
            $this->assertEqualsWithDelta($branchOd, $profile['header_axis_contact_span_mm'], 1e-12);
            $this->assertEqualsWithDelta($stationMin, $profile['branch_surface_station_min_mm'], 1e-9);
            $this->assertCount(65, $points);

            for ($index = 0; $index <= 64; $index++) {
                $mirror = $points[64 - $index];
                $this->assertEqualsWithDelta($points[$index]['header_axis_x_mm'], $mirror['header_axis_x_mm'], 1e-10);
                $this->assertEqualsWithDelta(-$points[$index]['header_circumferential_y_mm'], $mirror['header_circumferential_y_mm'], 1e-10);
                $this->assertEqualsWithDelta($points[$index]['header_radial_z_mm'], $mirror['header_radial_z_mm'], 1e-10);
                $this->assertEqualsWithDelta(-$points[$index]['header_developed_arc_mm'], $mirror['header_developed_arc_mm'], 1e-9);
            }
        }
    }

    public function test_equal_header_and_branch_ods_have_a_defined_closed_profile(): void
    {
        $profile = ContactProfile::forCenteredRightAngle(100.0, 100.0);
        $points = ContactProfile::sampledPoints(100.0, 100.0, segments: 32);

        $this->assertEqualsWithDelta(M_PI / 2.0, $profile['header_circumferential_angle_limit_rad'], 1e-12);
        $this->assertEqualsWithDelta(100.0 * M_PI / 2.0, $profile['developed_contact_arc_span_mm'], 1e-10);
        $this->assertEqualsWithDelta(0.0, $profile['branch_surface_station_min_mm'], 1e-12);
        $this->assertCount(33, $points);
    }

    public function test_branch_od_larger_than_header_od_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ContactProfile::forCenteredRightAngle(60.0, 73.0);
    }

    public function test_non_finite_od_and_invalid_sample_resolution_are_refused(): void
    {
        try {
            ContactProfile::forCenteredRightAngle(INF, 60.3);
            $this->fail('A non-finite header OD must be refused.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('finite and positive', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        ContactProfile::sampledPoints(168.3, 60.3, segments: 10);
    }
}
