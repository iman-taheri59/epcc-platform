<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/**
 * ASME B31.3 para 304.3.3 — reinforcement of fabricated welded branch
 * connections, area-replacement method (internal pressure).
 *
 * Requirement: ADR-008 s9.3 (owner 2026-10-12). The pad thickness recorded
 * on a P2P branch rule must keep A2 + A3 + A4 >= A1.
 *
 * Pure function: no DB, no locale, no state — the ADR-006 s5.4 pattern.
 * All lengths in ONE consistent unit (the platform standard is mm); areas
 * come out squared in that unit. Angles in degrees, valid range 45..90.
 *
 * Nomenclature (B31.3 Fig 304.3.3):
 *   Dh/Db  outside diameter of run (header) / branch pipe
 *   Th/Tb  nominal wall of run / branch, mill tolerance already deducted
 *   th/tb  pressure-design thickness of run / branch per para 304.1.2(a)
 *   c      sum of mechanical allowances (corrosion, threading, grooving…)
 *   beta   smaller angle between branch and run axes (90 = perpendicular)
 *   Tr/Dp  reinforcing pad thickness / outside diameter
 *   d1     effective length of run removed at the branch
 *   d2     half-width of the reinforcement zone along the run
 *   L4     height of the reinforcement zone above the run OD
 *
 * Equations (B31.3 2020, para 304.3.3, eqs 6–8):
 *   d1 = [Db - 2(Tb - c)] / sin(beta)
 *   d2 = greater of d1 or (Tb - c) + (Th - c) + d1/2, but never > Dh
 *   L4 = lesser of 2.5(Th - c) or 2.5(Tb - c) + Tr
 *   A1 = th * d1 * (2 - sin beta)            (required area; half for
 *                                             external pressure, not here)
 *   A2 = (2*d2 - d1) * (Th - th - c)         (excess run wall)
 *   A3 = 2 * L4 * (Tb - tb - c) / sin beta   (excess branch wall)
 *   A4 = pad area + weld-metal area, all inside the zone
 *   PASS when A2 + A3 + A4 >= A1
 *
 * Weld credit per para 328.5.4 minimum dimensions: throat tc is the lesser
 * of 0.7*Tb and $weldThroatCap (6 mm in the code, passed in the caller's
 * unit), and the Appendix-H convention credits 2*tc^2 of weld metal. Pass
 * $creditWelds=false to reproduce sources that report only A2+A3.
 *
 * Allowable-stress reductions per para 304.3.3(f)(3): if branch or pad
 * material allows less stress than the run, its area contribution scales
 * down in the same ratio ($sBranchOverRun / $sPadOverRun, default 1.0).
 */
final class BranchReinforcementCalculator
{
    /** Bisection bound when solving for the minimum required pad thickness. */
    private const PAD_SEARCH_MAX = 250.0;

    /**
     * Evaluate one branch connection. Returns the zone limits, every area
     * term, the margin (available − required) and the verdict:
     *
     * [
     *   'd1' => float, 'd2' => float, 'L4' => float,
     *   'A1' => float,                      required area
     *   'A2' => float, 'A3' => float,
     *   'A4_pad' => float, 'A4_welds' => float, 'A4' => float,
     *   'available' => float, 'margin' => float, 'pass' => bool,
     *   'pad_effective_width' => ?float, 'pad_effective_height' => ?float,
     * ]
     *
     * @param float      $Dh             run outside diameter
     * @param float      $Th             run nominal wall (mill tol. deducted)
     * @param float      $th             run pressure-design thickness
     * @param float      $Db             branch outside diameter
     * @param float      $Tb             branch nominal wall (mill tol. ded.)
     * @param float      $tb             branch pressure-design thickness
     * @param float      $c              corrosion + mechanical allowances
     * @param float      $betaDeg        branch angle, 45..90
     * @param float|null $padThickness   pad thickness Tr (null = no pad)
     * @param float|null $padOd          pad OD Dp (null with a pad = pad
     *                                   fills the zone width 2*d2 — the
     *                                   maximum credit assumption, see
     *                                   ADR-008 s9.3)
     * @param bool       $creditWelds    count para 328.5.4 minimum welds
     * @param float      $weldThroatCap  code cap on weld throat, in the
     *                                   caller's unit (6 mm by default)
     * @param float      $sBranchOverRun allowable-stress ratio branch/run
     * @param float      $sPadOverRun    allowable-stress ratio pad/run
     */
    public static function evaluate(
        float $Dh,
        float $Th,
        float $th,
        float $Db,
        float $Tb,
        float $tb,
        float $c,
        float $betaDeg = 90.0,
        ?float $padThickness = null,
        ?float $padOd = null,
        bool $creditWelds = true,
        float $weldThroatCap = 6.0,
        float $sBranchOverRun = 1.0,
        float $sPadOverRun = 1.0,
    ): array {
        if ($betaDeg < 45.0 || $betaDeg > 90.0) {
            throw new InvalidArgumentException(
                'para 304.3.3 area-replacement covers branch angles 45..90 degrees.'
            );
        }
        foreach (compact('Dh', 'Th', 'Db', 'Tb') as $name => $value) {
            if ($value <= 0.0) {
                throw new InvalidArgumentException("$name must be positive.");
            }
        }
        if ($padThickness !== null && ($padThickness < 0.0 || $padOd !== null && $padOd <= $Db)) {
            throw new InvalidArgumentException(
                'pad thickness must be >= 0 and pad OD must exceed the branch OD.'
            );
        }

        $sinB = sin(deg2rad($betaDeg));

        // Zone limits -------------------------------------------------------
        $d1 = ($Db - 2.0 * ($Tb - $c)) / $sinB;
        $d2 = max($d1, ($Tb - $c) + ($Th - $c) + $d1 / 2.0);
        $d2 = min($d2, $Dh);
        $Tr = $padThickness ?? 0.0;
        $L4 = min(2.5 * ($Th - $c), 2.5 * ($Tb - $c) + $Tr);

        // Required area -----------------------------------------------------
        $A1 = $th * $d1 * (2.0 - $sinB);

        // Available areas ---------------------------------------------------
        $A2 = max(0.0, (2.0 * $d2 - $d1) * ($Th - $th - $c));
        $A3 = max(0.0, 2.0 * $L4 * ($Tb - $tb - $c) / $sinB) * $sBranchOverRun;

        // Pad area inside the zone: width limited by the zone, height by L4.
        $padWidth = null;
        $padHeight = null;
        $A4pad = 0.0;
        if ($padThickness !== null && $padThickness > 0.0) {
            $maxZoneWidth = max(0.0, 2.0 * $d2 - $Db);
            $padWidth = $padOd === null
                ? $maxZoneWidth
                : min($padOd - $Db, $maxZoneWidth);
            $padHeight = min($padThickness, max(0.0, $L4));
            $A4pad = $padWidth * $padHeight * $sPadOverRun;
        }

        // Weld-metal credit, para 328.5.4 minimum dimensions.
        $A4welds = 0.0;
        if ($creditWelds) {
            $tc = min(0.7 * $Tb, $weldThroatCap);
            $A4welds = 2.0 * $tc * $tc;
        }

        $A4 = $A4pad + $A4welds;
        $available = $A2 + $A3 + $A4;
        $margin = $available - $A1;

        return [
            'd1' => $d1,
            'd2' => $d2,
            'L4' => $L4,
            'A1' => $A1,
            'A2' => $A2,
            'A3' => $A3,
            'A4_pad' => $A4pad,
            'A4_welds' => $A4welds,
            'A4' => $A4,
            'available' => $available,
            'margin' => $margin,
            'pass' => $margin >= -1e-9,
            'pad_effective_width' => $padWidth,
            'pad_effective_height' => $padHeight,
        ];
    }

    /**
     * Smallest pad thickness that makes the connection PASS, 0.0 when the
     * bare connection already passes. Solves by bisection — pad thickness
     * also grows L4, so no closed form exists. $padOd=null means a
     * zone-filling pad (maximum credit), the PMS validation convention.
     */
    public static function requiredPadThickness(
        float $Dh,
        float $Th,
        float $th,
        float $Db,
        float $Tb,
        float $tb,
        float $c,
        float $betaDeg = 90.0,
        ?float $padOd = null,
        bool $creditWelds = true,
        float $weldThroatCap = 6.0,
        float $sBranchOverRun = 1.0,
        float $sPadOverRun = 1.0,
    ): float {
        $bare = self::evaluate(
            $Dh, $Th, $th, $Db, $Tb, $tb, $c, $betaDeg,
            null, $padOd, $creditWelds, $weldThroatCap, $sBranchOverRun, $sPadOverRun,
        );
        if ($bare['pass']) {
            return 0.0;
        }

        $lo = 0.0;
        $hi = self::PAD_SEARCH_MAX;
        for ($i = 0; $i < 100; $i++) {
            $mid = ($lo + $hi) / 2.0;
            $r = self::evaluate(
                $Dh, $Th, $th, $Db, $Tb, $tb, $c, $betaDeg,
                $mid, $padOd, $creditWelds, $weldThroatCap, $sBranchOverRun, $sPadOverRun,
            );
            if ($r['pass']) {
                $hi = $mid;
            } else {
                $lo = $mid;
            }
            if ($hi - $lo < 1e-4) {
                break;
            }
        }

        return $hi;
    }
}
