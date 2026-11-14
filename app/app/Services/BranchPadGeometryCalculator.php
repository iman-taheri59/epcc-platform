<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Nominal developed-surface outline for a centred, 90-degree branch saddle pad.
 *
 * This is deliberately separate from the area-replacement verdict and does
 * not size reinforcement area. BranchPadSaddleContactProfileCalculator defines
 * the ideal OD contact curve; BranchPadSaddlePadPerimeterCalculator derives a
 * separate outer outline by offsetting that footprint in the developed header
 * surface. The stored major/minor spans are bounds of that outline, not the
 * contact curve itself. No wall compensation, weld prep, fit-up clearance,
 * kerf, or manufacturing tolerance is included, so this is not a fabrication
 * profile/drawing.
 */
final class BranchPadGeometryCalculator
{
    public const METHOD = 'CENTERED_90_DEGREE_SADDLE_PERIMETER_V2';

    public const SUPPORTED_BETA_DEG = 90.0;

    /**
     * Calculate the sampled outer perimeter and its two bounding spans in mm.
     *
     * Geometry convention:
     * - the exact OD contact curve is first mapped to the developed header
     *   surface by BranchPadSaddleContactProfileCalculator;
     * - a separate, uniform Minkowski offset in that developed surface uses
     *   edge extension e = d2 - Db/2. This gives a nominal physical run span
     *   D_s = 2*d2, while preserving the saddle-shaped contact contour;
     * - the returned major/minor values are coordinate bounds of that sampled
     *   outer perimeter, not a replacement for the B31.3 d1/d2 zone limits.
     *
     * The uniform edge extension is a geometric modelling convention, not a
     * B31.3-mandated outline or minimum pad dimension. Th/Tb are schedule walls
     * after the reference mill-tolerance factor; c is the governing corrosion
     * allowance. Only beta=90 degrees and zero offset are supported here.
     *
     * @return array{
     *   method:string,
     *   beta_deg:float,
     *   d1_mm:float,
     *   d2_mm:float,
     *   zone_extension_each_side_mm:float,
     *   saddle_contact_arc_mm:float,
     *   saddle_contact_profile:array<string,mixed>,
     *   pad_outer_perimeter_profile:array<string,mixed>,
     *   pad_major_axis_mm:float,
     *   pad_minor_axis_mm:float,
     *   pad_major_axis_direction:string,
     *   pad_minor_axis_direction:string
     * }
     */
    public static function forCenteredRightAngleSaddle(
        float $Dh,
        float $Th,
        float $Db,
        float $Tb,
        float $c,
    ): array {
        foreach (compact('Dh', 'Th', 'Db', 'Tb', 'c') as $name => $value) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException("$name must be finite.");
            }
        }

        if ($Dh <= 0.0 || $Db <= 0.0 || $Th <= 0.0 || $Tb <= 0.0 || $c < 0.0) {
            throw new InvalidArgumentException('Pipe ODs and effective walls must be positive; corrosion allowance cannot be negative.');
        }

        if ($Db > $Dh) {
            throw new InvalidArgumentException('A closed centred saddle requires branch OD Db no greater than header OD Dh.');
        }

        if ($Th <= $c || $Tb <= $c) {
            throw new InvalidArgumentException('Corrosion allowance must be smaller than both effective pipe walls.');
        }

        $zone = BranchReinforcementCalculator::zoneGeometry(
            Dh: $Dh,
            Th: $Th,
            Db: $Db,
            Tb: $Tb,
            c: $c,
            betaDeg: self::SUPPORTED_BETA_DEG,
        );
        $d1 = $zone['d1'];
        $d2 = $zone['d2'];

        if ($d1 <= 0.0 || $d2 < $d1 / 2.0) {
            throw new InvalidArgumentException('The inputs do not produce a positive opening and reinforcement-zone envelope.');
        }

        $zoneExtension = $d2 - $d1 / 2.0;
        $saddleContactProfile = BranchPadSaddleContactProfileCalculator::forCenteredRightAngle($Dh, $Db);
        $saddleContactArc = $saddleContactProfile['developed_contact_arc_span_mm'];

        $padOuterPerimeter = BranchPadSaddlePadPerimeterCalculator::forCenteredRightAngle(
            headerOdMm: $Dh,
            branchOdMm: $Db,
            reinforcementZoneHalfSpanMm: $d2,
        );
        $major = $padOuterPerimeter['developed_circumferential_span_mm'];
        $minor = $padOuterPerimeter['span_along_header_mm'];

        // asin(Db/Dh) > Db/Dh for 0 < Db/Dh <= 1, so the developed contact
        // arc exceeds the branch OD by construction. The separate perimeter
        // applies the same non-negative shell-surface offset in both directions.
        if (! is_finite($major) || ! is_finite($minor) || $major <= $minor) {
            throw new InvalidArgumentException('The calculated PAD outer perimeter does not have a valid pair of bounding spans.');
        }

        return [
            'method' => self::METHOD,
            'beta_deg' => self::SUPPORTED_BETA_DEG,
            'd1_mm' => $d1,
            'd2_mm' => $d2,
            'zone_extension_each_side_mm' => $zoneExtension,
            'saddle_contact_arc_mm' => $saddleContactArc,
            'saddle_contact_profile' => $saddleContactProfile,
            'pad_outer_perimeter_profile' => $padOuterPerimeter,
            'pad_major_axis_mm' => $major,
            'pad_minor_axis_mm' => $minor,
            'pad_major_axis_direction' => 'AROUND_HEADER',
            'pad_minor_axis_direction' => 'ALONG_HEADER',
        ];
    }
}
