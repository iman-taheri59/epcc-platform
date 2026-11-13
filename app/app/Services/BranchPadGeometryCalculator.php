<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * Nominal two-axis envelope for a centred, 90-degree branch saddle pad.
 *
 * This is deliberately separate from the area-replacement verdict and does
 * not size reinforcement area. It derives the exact developed-surface span of
 * the ideal branch/header cylinder intersection, then extends that footprint
 * to the existing B31.3 reinforcement-zone limit d2. It does not add fit-up
 * clearance, a minimum fabrication allowance, kerf, or manufacturing
 * tolerance, and it is not a fabrication profile/drawing.
 */
final class BranchPadGeometryCalculator
{
    public const METHOD = 'CENTERED_90_DEGREE_SADDLE_ZONE_V1';

    public const SUPPORTED_BETA_DEG = 90.0;

    /**
     * Calculate the two outer-envelope axes in millimetres.
     *
     * Geometry convention:
     * - the major axis is circumferential, measured as developed arc length
     *   around the header shell;
     * - the minor axis is longitudinal, along the header pipe axis, and is
     *   exactly the B31.3 zone span 2*d2; this is the physical span along the
     *   run (`D_s` in Figure 304.3.3-1) used to limit the A4 pad width;
     * - the branch saddle contact arc is Dh*asin(Db/Dh);
     * - each side extension is w = d2 - d1/2, so the major envelope is the
     *   saddle contact arc plus 2*w.
     *
     * Th/Tb are nominal schedule walls after the reference mill-tolerance
     * factor has been applied. c is the governing corrosion allowance, in the
     * same length unit. Geometry method v1 is intentionally restricted to
     * beta=90 degrees; non-perpendicular roots need an approved angled-saddle
     * envelope method before those dimensions can be reported.
     *
     * @return array{
     *   method:string,
     *   beta_deg:float,
     *   d1_mm:float,
     *   d2_mm:float,
     *   zone_extension_each_side_mm:float,
     *   saddle_contact_arc_mm:float,
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

        $extension = $d2 - $d1 / 2.0;
        $saddleContactArc = $Dh * asin($Db / $Dh);
        $major = $saddleContactArc + 2.0 * $extension;
        $minor = 2.0 * $d2;

        // With a positive effective branch wall, d1 < Db and the developed
        // saddle arc is greater than Db. Therefore this construction has a
        // strictly larger circumferential envelope than its axial zone span.
        if (! is_finite($major) || ! is_finite($minor) || $major <= $minor) {
            throw new InvalidArgumentException('The calculated saddle envelope does not have a valid major/minor axis pair.');
        }

        return [
            'method' => self::METHOD,
            'beta_deg' => self::SUPPORTED_BETA_DEG,
            'd1_mm' => $d1,
            'd2_mm' => $d2,
            'zone_extension_each_side_mm' => $extension,
            'saddle_contact_arc_mm' => $saddleContactArc,
            'pad_major_axis_mm' => $major,
            'pad_minor_axis_mm' => $minor,
            'pad_major_axis_direction' => 'AROUND_HEADER',
            'pad_minor_axis_direction' => 'ALONG_HEADER',
        ];
    }
}
