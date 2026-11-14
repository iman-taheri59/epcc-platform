<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Exact outer-cylinder contact curve for a centred, perpendicular branch on a header.
 *
 * This calculates the ideal intersection of the two OD cylinders only. It is
 * not the pad's outer perimeter, a through-wall cut drawing, or a fabrication
 * profile: no wall offset, fit-up gap, kerf, or manufacturing tolerance is
 * applied. Its local axes are only a mathematical frame for this special case;
 * they do not establish a project-wide coordinate or rotation convention.
 */
final class BranchPadSaddleContactProfileCalculator
{
    public const METHOD = 'CENTERED_90_DEGREE_OUTER_CYLINDER_CONTACT_V1';

    /** Number of line segments used by the stored ideal-curve preview samples. */
    public const DEFAULT_SAMPLE_SEGMENTS = 64;

    /**
     * Return the exact parametric definition and bounding dimensions.
     *
     * Local convention: header axis is +X, branch axis is +Z, the header
     * circumferential coordinate is +Y, and the crown is at +Z. The branch
     * profile parameter u walks around the branch OD; it is not θ, clocking,
     * or component rotation.
     *
     * @return array{
     *   method:string,
     *   units:string,
     *   local_coordinate_convention:string,
     *   equations:array{header_shell:string,branch_shell:string,intersection_curve:string},
     *   header_radius_mm:float,
     *   branch_radius_mm:float,
     *   header_circumferential_angle_limit_rad:float,
     *   developed_contact_arc_span_mm:float,
     *   header_axis_contact_span_mm:float,
     *   branch_surface_station_min_mm:float,
     *   branch_surface_station_max_mm:float,
     *   branch_surface_station_range_mm:float,
     *   profile_parameter_range_rad:array{0:float,1:float},
     *   uses_user_theta:bool,
     *   uses_component_rotation:bool,
     *   includes_wall_thickness:bool,
     *   includes_fit_up_clearance:bool,
     *   includes_fabrication_tolerance:bool,
     *   is_fabrication_outline:bool,
     *   outer_pad_perimeter_defined:bool
     * }
     */
    public static function forCenteredRightAngle(float $headerOdMm, float $branchOdMm): array
    {
        self::assertDiameters($headerOdMm, $branchOdMm);

        $headerRadius = $headerOdMm / 2.0;
        $branchRadius = $branchOdMm / 2.0;
        $angleLimit = asin(min(1.0, $branchOdMm / $headerOdMm));
        $stationMin = sqrt(max(0.0, $headerRadius ** 2 - $branchRadius ** 2));

        return [
            'method' => self::METHOD,
            'units' => 'mm',
            'local_coordinate_convention' => 'HEADER_AXIS=+X; BRANCH_AXIS=+Z; HEADER_CIRCUMFERENTIAL=+Y; HEADER_CROWN=+Z; u is a branch-surface profile parameter, not user theta or component rotation.',
            'equations' => [
                'header_shell' => 'y^2 + z^2 = Rh^2',
                'branch_shell' => 'x^2 + y^2 = Rb^2',
                'intersection_curve' => 'x = Rb*cos(u); y = Rb*sin(u); z = sqrt(Rh^2 - y^2); u in [0, 2*pi]',
            ],
            'header_radius_mm' => $headerRadius,
            'branch_radius_mm' => $branchRadius,
            'header_circumferential_angle_limit_rad' => $angleLimit,
            'developed_contact_arc_span_mm' => 2.0 * $headerRadius * $angleLimit,
            'header_axis_contact_span_mm' => $branchOdMm,
            'branch_surface_station_min_mm' => $stationMin,
            'branch_surface_station_max_mm' => $headerRadius,
            'branch_surface_station_range_mm' => $headerRadius - $stationMin,
            'profile_parameter_range_rad' => [0.0, 2.0 * M_PI],
            'uses_user_theta' => false,
            'uses_component_rotation' => false,
            'includes_wall_thickness' => false,
            'includes_fit_up_clearance' => false,
            'includes_fabrication_tolerance' => false,
            'is_fabrication_outline' => false,
            'outer_pad_perimeter_defined' => false,
        ];
    }

    /**
     * Sample the analytic contact curve in one closed cycle around the branch OD.
     *
     * The first and final points coincide geometrically. Coordinates are
     * [header-axis x, circumferential y, radial z] in the local convention
     * returned by forCenteredRightAngle(). Samples are a display/audit aid;
     * the parametric equation remains the analytic definition.
     *
     * @return list<array{
     *   branch_profile_parameter_rad:float,
     *   header_axis_x_mm:float,
     *   header_circumferential_y_mm:float,
     *   header_radial_z_mm:float,
     *   header_circumferential_angle_rad:float,
     *   header_developed_arc_mm:float
     * }>
     */
    public static function sampledPoints(
        float $headerOdMm,
        float $branchOdMm,
        int $segments = self::DEFAULT_SAMPLE_SEGMENTS,
    ): array {
        if ($segments < 8 || $segments % 4 !== 0) {
            throw new InvalidArgumentException('Profile sample segments must be at least 8 and divisible by 4.');
        }

        $profile = self::forCenteredRightAngle($headerOdMm, $branchOdMm);
        $headerRadius = $profile['header_radius_mm'];
        $branchRadius = $profile['branch_radius_mm'];
        $points = [];
        $radicandTolerance = 1e-12 * max(1.0, $headerRadius ** 2);

        for ($index = 0; $index <= $segments; $index++) {
            $parameter = 2.0 * M_PI * $index / $segments;
            $x = $branchRadius * cos($parameter);
            $y = $branchRadius * sin($parameter);
            $radicand = $headerRadius ** 2 - $y ** 2;

            if ($radicand < -$radicandTolerance) {
                throw new InvalidArgumentException('The sampled branch OD lies outside the header cylinder.');
            }

            $z = sqrt(max(0.0, $radicand));
            $circumferentialAngle = atan2($y, $z);

            $points[] = [
                'branch_profile_parameter_rad' => $parameter,
                'header_axis_x_mm' => $x,
                'header_circumferential_y_mm' => $y,
                'header_radial_z_mm' => $z,
                'header_circumferential_angle_rad' => $circumferentialAngle,
                'header_developed_arc_mm' => $headerRadius * $circumferentialAngle,
            ];
        }

        return $points;
    }

    private static function assertDiameters(float $headerOdMm, float $branchOdMm): void
    {
        foreach (compact('headerOdMm', 'branchOdMm') as $name => $value) {
            if (! is_finite($value) || $value <= 0.0) {
                throw new InvalidArgumentException("$name must be finite and positive.");
            }
        }

        if ($branchOdMm > $headerOdMm) {
            throw new InvalidArgumentException('A centred closed cylinder-intersection profile requires branch OD no greater than header OD.');
        }
    }
}
