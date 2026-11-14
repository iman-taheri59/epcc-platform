<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Nominal outer PAD boundary on the developed header OD for a centred 90° root.
 *
 * This is deliberately separate from the analytic branch/header contact curve.
 * In the unrolled header-surface plane, the ideal contact footprint is dilated
 * by a closed disk of radius e = d2 - Db/2. This selects a uniform shell-surface
 * edge extension whose longitudinal half-span reaches the B31.3 zone limit.
 * It is a modelling convention, not a B31.3-mandated outline or a fabrication
 * profile; wall compensation, weld prep, clearance and tolerances are absent.
 */
final class BranchPadSaddlePadPerimeterCalculator
{
    public const METHOD = 'CENTERED_90_DEGREE_DEVELOPED_CONTACT_OFFSET_V1';

    /** Number of analytic contact-curve segments used for the sampled outline. */
    public const DEFAULT_CONTACT_SEGMENTS = BranchPadSaddleContactProfileCalculator::DEFAULT_SAMPLE_SEGMENTS;

    /** Number of directions in the polygonal approximation of the offset disk. */
    public const DEFAULT_OFFSET_DISK_SEGMENTS = 64;

    /**
     * Build the nominal developed-surface perimeter from the ideal OD contact.
     *
     * @return array{
     *   method:string,
     *   units:string,
     *   construction:string,
     *   coordinate_convention:string,
     *   edge_extension_mm:float,
     *   edge_extension_basis:string,
     *   header_axis_half_extent_mm:float,
     *   developed_circumferential_half_extent_mm:float,
     *   span_along_header_mm:float,
     *   developed_circumferential_span_mm:float,
     *   sample_contact_segments:int,
     *   sample_offset_disk_segments:int,
     *   sample_point_columns:array{0:string,1:string},
     *   sampled_points_mm:list<array{0:float,1:float}>,
     *   sampled_points_are_visualization_aid_only:bool,
     *   uses_user_theta:bool,
     *   uses_component_rotation:bool,
     *   includes_wall_thickness:bool,
     *   includes_fit_up_clearance:bool,
     *   includes_fabrication_tolerance:bool,
     *   is_fabrication_outline:bool
     * }
     */
    public static function forCenteredRightAngle(
        float $headerOdMm,
        float $branchOdMm,
        float $reinforcementZoneHalfSpanMm,
        int $contactSegments = self::DEFAULT_CONTACT_SEGMENTS,
        int $offsetDiskSegments = self::DEFAULT_OFFSET_DISK_SEGMENTS,
    ): array {
        if (! is_finite($reinforcementZoneHalfSpanMm) || $reinforcementZoneHalfSpanMm <= 0.0) {
            throw new InvalidArgumentException('The reinforcement-zone half-span must be finite and positive.');
        }

        if ($contactSegments < 8 || $contactSegments % 4 !== 0) {
            throw new InvalidArgumentException('Contact-profile sample segments must be at least 8 and divisible by 4.');
        }

        if ($offsetDiskSegments < 8 || $offsetDiskSegments % 4 !== 0) {
            throw new InvalidArgumentException('Offset-disk sample segments must be at least 8 and divisible by 4.');
        }

        $contactProfile = BranchPadSaddleContactProfileCalculator::forCenteredRightAngle($headerOdMm, $branchOdMm);
        if ($reinforcementZoneHalfSpanMm > $headerOdMm) {
            throw new InvalidArgumentException('The reinforcement-zone half-span cannot exceed the header OD cap.');
        }

        $branchRadius = $contactProfile['branch_radius_mm'];
        $edgeExtension = $reinforcementZoneHalfSpanMm - $branchRadius;

        if ($edgeExtension < 0.0) {
            throw new InvalidArgumentException('The reinforcement zone cannot contain the branch OD; a non-negative PAD edge extension cannot be derived.');
        }
        $contactPoints = BranchPadSaddleContactProfileCalculator::sampledPoints(
            headerOdMm: $headerOdMm,
            branchOdMm: $branchOdMm,
            segments: $contactSegments,
        );
        $contactFootprint = array_map(
            static fn (array $point): array => [
                $point['header_axis_x_mm'],
                $point['header_developed_arc_mm'],
            ],
            array_slice($contactPoints, 0, -1),
        );
        $perimeterPoints = self::offsetContactFootprint(
            $contactFootprint,
            $edgeExtension,
            $offsetDiskSegments,
        );
        $perimeterPoints[] = $perimeterPoints[0];

        $contactCircumferentialHalfSpan = $contactProfile['developed_contact_arc_span_mm'] / 2.0;
        $circumferentialHalfExtent = $contactCircumferentialHalfSpan + $edgeExtension;
        $headerAxisHalfExtent = $branchRadius + $edgeExtension;

        return [
            'method' => self::METHOD,
            'units' => 'mm',
            'construction' => 'CLOSED_DISK_MINKOWSKI_OFFSET_OF_THE_IDEAL_CONTACT_FOOTPRINT_ON_THE_DEVELOPED_HEADER_OD',
            'coordinate_convention' => 'header_axis_x_mm is along the header; header_developed_arc_mm is circumferential arc length on the unrolled header OD.',
            'edge_extension_mm' => $edgeExtension,
            'edge_extension_basis' => 'REINFORCEMENT_ZONE_HALF_SPAN_MINUS_BRANCH_OD_RADIUS',
            'header_axis_half_extent_mm' => $headerAxisHalfExtent,
            'developed_circumferential_half_extent_mm' => $circumferentialHalfExtent,
            'span_along_header_mm' => 2.0 * $headerAxisHalfExtent,
            'developed_circumferential_span_mm' => 2.0 * $circumferentialHalfExtent,
            'sample_contact_segments' => $contactSegments,
            'sample_offset_disk_segments' => $offsetDiskSegments,
            'sample_point_columns' => ['header_axis_x_mm', 'header_developed_arc_mm'],
            'sampled_points_mm' => $perimeterPoints,
            'sampled_points_are_visualization_aid_only' => true,
            'uses_user_theta' => false,
            'uses_component_rotation' => false,
            'includes_wall_thickness' => false,
            'includes_fit_up_clearance' => false,
            'includes_fabrication_tolerance' => false,
            'is_fabrication_outline' => false,
        ];
    }

    /**
     * Approximate the exact closed-disk offset as the convex hull of summed
     * contact samples and regular-disk samples in the developed header plane.
     *
     * @param list<array{0:float,1:float}> $contactFootprint
     * @return list<array{0:float,1:float}> Open, counter-clockwise hull vertices
     */
    private static function offsetContactFootprint(array $contactFootprint, float $edgeExtension, int $diskSegments): array
    {
        if ($edgeExtension === 0.0) {
            return $contactFootprint;
        }

        $summedPoints = [];
        for ($index = 0; $index < $diskSegments; $index++) {
            $angle = 2.0 * M_PI * $index / $diskSegments;
            $diskX = $edgeExtension * cos($angle);
            $diskS = $edgeExtension * sin($angle);

            foreach ($contactFootprint as [$x, $s]) {
                $summedPoints[] = [$x + $diskX, $s + $diskS];
            }
        }

        return self::convexHull($summedPoints);
    }

    /**
     * @param list<array{0:float,1:float}> $points
     * @return list<array{0:float,1:float}>
     */
    private static function convexHull(array $points): array
    {
        usort($points, static fn (array $left, array $right): int => ($left[0] <=> $right[0]) ?: ($left[1] <=> $right[1]));

        $unique = [];
        foreach ($points as $point) {
            $previous = $unique[count($unique) - 1] ?? null;
            if ($previous === null || $point[0] !== $previous[0] || $point[1] !== $previous[1]) {
                $unique[] = $point;
            }
        }

        if (count($unique) < 3) {
            throw new InvalidArgumentException('At least three distinct points are required for a closed PAD perimeter.');
        }

        $cross = static fn (array $origin, array $first, array $second): float =>
            ($first[0] - $origin[0]) * ($second[1] - $origin[1])
            - ($first[1] - $origin[1]) * ($second[0] - $origin[0]);

        $lower = [];
        foreach ($unique as $point) {
            while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $point) <= 1e-12) {
                array_pop($lower);
            }
            $lower[] = $point;
        }

        $upper = [];
        foreach (array_reverse($unique) as $point) {
            while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $point) <= 1e-12) {
                array_pop($upper);
            }
            $upper[] = $point;
        }

        return array_merge(array_slice($lower, 0, -1), array_slice($upper, 0, -1));
    }
}
