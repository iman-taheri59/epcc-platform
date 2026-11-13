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
 *   Tr      nominal reinforcing-pad thickness
 *   D_s     physical pad span measured along the run (Figure 304.3.3-1)
 *   d1     effective length of run removed at the branch
 *   d2     half-width of the reinforcement zone along the run
 *   L4     height of the reinforcement zone above the run OD
 *
 * Equations (B31.3-2024, para 304.3.3, eqs 6–8):
 *   d1 = [Db - 2(Tb - c)] / sin(beta)
 *   d2 = greater of d1 or (Tb - c) + (Th - c) + d1/2, but never > Dh
 *   L4 = lesser of 2.5(Th - c) or 2.5(Tb - c) + Tr
 *   A1 = th * d1 * (2 - sin beta)            (required area; half for
 *                                             external pressure, not here)
 *   A2 = (2*d2 - d1) * (Th - th - c)         (excess run wall)
 *   A3 = 2 * L4 * (Tb - tb - c) / sin beta   (excess branch wall)
 *   A4 = pad area + root-weld and PAD outer-edge weld areas, all inside the zone
 *   PASS when A2 + A3 + A4 >= A1
 *
 * Weld credit per para 328.5.4 and Appendix H H301/H302: the root cover-fillet
 * throat tc is the lesser of 0.7 times the nominal branch wall and
 * $weldThroatCap (6 mm in the code, passed in the caller's unit); its two
 * section cuts credit 2*tc^2. When a PAD is present, the outer-edge weld to
 * the run has throat 0.5*Tr and its two section cuts add
 * 2*(0.5*Tr_nominal)^2. The separate PAD-to-branch fillet is dimensioned by
 * `BranchPadWeldCalculator` but is not relied on for additional A4 credit.
 * The nominal branch wall is separate from mill-tolerance-reduced Tb used in
 * area equations. `padThickness` is the nominal pipe-saddle thickness; the
 * caller supplies its minimum-wall factor for L4 and PAD metal area, while
 * weld minima continue to use the nominal value. The default factor 1.0
 * preserves standalone/plate-input behavior. Pass $creditWelds=false to
 * reproduce area balances that exclude weld credit.
 *
 * Allowable-stress reductions per para 304.3.3(c)(2) and (f)(3): if branch
 * or pad material allows less stress than the run, its area contribution
 * scales down by that ratio; higher allowable stress earns no extra credit.
 */
final class BranchReinforcementCalculator
{
    /** Bisection bound when solving for the minimum required pad thickness. */
    private const PAD_SEARCH_MAX = 250.0;

    /**
     * Calculate the B31.3 reinforcement-zone opening and half-width without
     * evaluating or changing any area-replacement term. This is exposed for
     * geometric consumers such as the separate nominal saddle-pad envelope.
     *
     * @return array{d1:float,d2:float}
     */
    public static function zoneGeometry(
        float $Dh,
        float $Th,
        float $Db,
        float $Tb,
        float $c,
        float $betaDeg = 90.0,
    ): array {
        if (! is_finite($betaDeg) || $betaDeg < 45.0 || $betaDeg > 90.0) {
            throw new InvalidArgumentException(
                'para 304.3.3 area-replacement covers branch angles 45..90 degrees.'
            );
        }
        foreach (compact('Dh', 'Th', 'Db', 'Tb') as $name => $value) {
            if (! is_finite($value) || $value <= 0.0) {
                throw new InvalidArgumentException("$name must be finite and positive.");
            }
        }
        if (! is_finite($c) || $c < 0.0 || $Th <= $c || $Tb <= $c) {
            throw new InvalidArgumentException('c must be finite and non-negative, and smaller than both effective pipe walls.');
        }
        if ($Db > $Dh) {
            throw new InvalidArgumentException('The branch OD Db must not exceed the run OD Dh for the §304.3.3 area-replacement method.');
        }
        if ($Db - 2.0 * ($Tb - $c) <= 0.0) {
            throw new InvalidArgumentException('The effective branch opening d1 must be positive.');
        }

        $sinB = sin(deg2rad($betaDeg));
        $d1 = ($Db - 2.0 * ($Tb - $c)) / $sinB;
        $d2 = max($d1, ($Tb - $c) + ($Th - $c) + $d1 / 2.0);
        $d2 = min($d2, $Dh);

        return ['d1' => $d1, 'd2' => $d2];
    }

    /**
     * Return the exact beta candidates needed to find the worst area balance
     * over the §304.3.1(b) domain 45..90 degrees.
     *
     * Let x = csc(beta). Then d1 = K*x and d2 is the capped maximum of two
     * affine functions of x. A1 and A3 are affine in x; A2 and the finite PAD
     * width are piecewise affine. Therefore the minimum margin is attained at
     * an endpoint or at one of their regime transitions. This method returns
     * both endpoints plus all such in-domain transition angles.
     *
     * @return list<float> Sorted unique beta angles in degrees, including 45 and 90
     */
    public static function criticalBetaAngles(
        float $Dh,
        float $Th,
        float $Db,
        float $Tb,
        float $c,
        ?float $padSpanAlongRun = null,
    ): array {
        foreach (compact('Dh', 'Th', 'Db', 'Tb') as $name => $value) {
            if (! is_finite($value) || $value <= 0.0) {
                throw new InvalidArgumentException("$name must be finite and positive.");
            }
        }
        if (! is_finite($c) || $c < 0.0 || $Th <= $c || $Tb <= $c) {
            throw new InvalidArgumentException('c must be finite and non-negative, and smaller than both effective pipe walls.');
        }
        if ($Db > $Dh) {
            throw new InvalidArgumentException('The branch OD Db must not exceed the run OD Dh for the §304.3.3 area-replacement method.');
        }
        if ($Db - 2.0 * ($Tb - $c) <= 0.0) {
            throw new InvalidArgumentException('The effective branch opening d1 must be positive.');
        }
        if ($padSpanAlongRun !== null && (! is_finite($padSpanAlongRun) || $padSpanAlongRun <= $Db)) {
            throw new InvalidArgumentException('PAD span along the run must be finite and exceed the branch OD.');
        }

        $K = $Db - 2.0 * ($Tb - $c);
        $sumEffectiveWalls = ($Tb - $c) + ($Th - $c);
        $xMin = 1.0; // csc(90 deg)
        $xMax = 1.0 / sin(deg2rad(45.0));
        $xValues = [$xMin, $xMax];
        $addX = static function (float $x) use (&$xValues, $xMin, $xMax): void {
            if (is_finite($x) && $x > $xMin + 1e-12 && $x < $xMax - 1e-12) {
                $xValues[] = $x;
            }
        };

        // Switch where d1 overtakes the other half-width candidate.
        $addX(2.0 * $sumEffectiveWalls / $K);

        // A target d2 value can be reached through either affine candidate.
        // Retain only roots that actually lie on the capped max() envelope;
        // this avoids adding inactive algebraic crossings as fake transitions.
        $addD2Target = static function (float $target) use (
            &$xValues, $xMin, $xMax, $Dh, $K, $sumEffectiveWalls,
        ): void {
            foreach ([$target / $K, 2.0 * ($target - $sumEffectiveWalls) / $K] as $x) {
                if (! is_finite($x) || $x <= $xMin + 1e-12 || $x >= $xMax - 1e-12) {
                    continue;
                }

                $d1 = $K * $x;
                $actualD2 = min($Dh, max($d1, $sumEffectiveWalls + $d1 / 2.0));
                $tolerance = 1e-10 * max(1.0, abs($target));
                if (abs($actualD2 - $target) <= $tolerance) {
                    $xValues[] = $x;
                }
            }
        };

        // d2 cap; PAD width turns on at d2=Db/2 and, for a finite physical
        // span along the run, stops growing at d2=padSpanAlongRun/2.
        $addD2Target($Dh);
        $addD2Target($Db / 2.0);
        if ($padSpanAlongRun !== null) {
            $addD2Target($padSpanAlongRun / 2.0);
        }

        // A2 clips at zero only if the capped zone reaches d1/2.
        $addX(2.0 * $Dh / $K);

        sort($xValues, SORT_NUMERIC);
        $angles = [];
        foreach ($xValues as $x) {
            $beta = abs($x - $xMin) <= 1e-12
                ? 90.0
                : (abs($x - $xMax) <= 1e-12
                    ? 45.0
                    : rad2deg(asin(1.0 / $x)));
            $angles[number_format($beta, 10, '.', '')] = $beta;
        }
        $angles = array_values($angles);
        sort($angles, SORT_NUMERIC);

        return $angles;
    }

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
     *   'A4_root_welds' => float, 'A4_pad_edge_welds' => float,
     *   'pad_effective_thickness' => ?float,
     *   'pad_effective_width' => ?float, 'pad_effective_height' => ?float,
     *   'pad_width_to_height_ratio' => ?float (width / full minimum pad wall),
     * ]
     *
     * @param  float  $Dh  run outside diameter
     * @param  float  $Th  run nominal wall (mill tol. deducted)
     * @param  float  $th  run pressure-design thickness
     * @param  float  $Db  branch outside diameter
     * @param  float  $Tb  branch nominal wall (mill tol. ded.)
     * @param  float  $tb  branch pressure-design thickness
     * @param  float  $c  corrosion + mechanical allowances
     * @param  float  $betaDeg  branch angle, 45..90
     * @param  float|null  $padThickness  nominal pipe-saddle thickness Tr (null = no pad)
     * @param  float|null  $padSpanAlongRun  physical PAD span along the header/run;
     *                                       B31.3 Figure 304.3.3-1 D_s dimension.
     *                                       Null with a pad means full zone-width
     *                                       credit for legacy standalone callers.
     * @param  bool  $creditWelds  count para 328.5.4 minimum welds
     * @param  float  $weldThroatCap  code cap on weld throat, in the
     *                                caller's unit (6 mm by default)
     * @param  float  $sBranchOverRun  allowable-stress ratio branch/run,
     *                                 capped at 1.0 by para 304.3.3(c)(2)
     * @param  float  $sPadOverRun  allowable-stress ratio pad/run
     * @param  float|null  $branchNominalThicknessForWeld  nominal Tb used by
     *                                                     para 328.5.4(c), before mill tolerance;
     *                                                     defaults to $Tb for standalone callers
     * @param  float  $padEffectiveThicknessFactor  minimum/nominal Tr ratio
     *                                              used for L4 and pad metal area; the
     *                                              pipe-saddle gate passes 0.875.
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
        ?float $padSpanAlongRun = null,
        bool $creditWelds = true,
        float $weldThroatCap = 6.0,
        float $sBranchOverRun = 1.0,
        float $sPadOverRun = 1.0,
        ?float $branchNominalThicknessForWeld = null,
        float $padEffectiveThicknessFactor = 1.0,
    ): array {
        if (! is_finite($betaDeg) || $betaDeg < 45.0 || $betaDeg > 90.0) {
            throw new InvalidArgumentException(
                'para 304.3.3 area-replacement covers branch angles 45..90 degrees.'
            );
        }
        foreach (compact('Dh', 'Th', 'Db', 'Tb') as $name => $value) {
            if (! is_finite($value) || $value <= 0.0) {
                throw new InvalidArgumentException("$name must be finite and positive.");
            }
        }
        foreach (compact('th', 'tb') as $name => $value) {
            if (! is_finite($value) || $value <= 0.0) {
                throw new InvalidArgumentException("$name must be finite and positive.");
            }
        }
        if (! is_finite($c) || $c < 0.0) {
            throw new InvalidArgumentException('c must be finite and non-negative.');
        }
        if ($Db > $Dh) {
            throw new InvalidArgumentException('The branch OD Db must not exceed the run OD Dh for the §304.3.3 area-replacement method.');
        }
        if ($Db - 2.0 * ($Tb - $c) <= 0.0) {
            throw new InvalidArgumentException('The effective branch opening d1 must be positive.');
        }
        if ($Th + 1e-9 < $th + $c) {
            throw new InvalidArgumentException(
                'run wall after mill tolerance is below the pressure-design thickness plus allowances.'
            );
        }
        if ($Tb + 1e-9 < $tb + $c) {
            throw new InvalidArgumentException(
                'branch wall after mill tolerance is below the pressure-design thickness plus allowances.'
            );
        }
        if (! is_finite($sBranchOverRun) || $sBranchOverRun < 0.0
            || ! is_finite($sPadOverRun) || $sPadOverRun < 0.0) {
            throw new InvalidArgumentException('Allowable-stress ratios must be finite and non-negative.');
        }
        if (! is_finite($padEffectiveThicknessFactor)
            || $padEffectiveThicknessFactor <= 0.0
            || $padEffectiveThicknessFactor > 1.0) {
            throw new InvalidArgumentException('The PAD effective-thickness factor must be finite and in (0, 1].');
        }
        if ($creditWelds && (! is_finite($weldThroatCap) || $weldThroatCap <= 0.0)) {
            throw new InvalidArgumentException('The weld throat cap must be finite and positive when weld credit is enabled.');
        }
        if ($branchNominalThicknessForWeld !== null
            && (! is_finite($branchNominalThicknessForWeld)
                || $branchNominalThicknessForWeld <= 0.0
                || $branchNominalThicknessForWeld + 1e-9 < $Tb)) {
            throw new InvalidArgumentException(
                'nominal branch wall for weld sizing must be finite and at least the mill-tolerance-reduced branch wall.'
            );
        }
        if ($padThickness !== null && (! is_finite($padThickness) || $padThickness < 0.0)) {
            throw new InvalidArgumentException('Nominal PAD thickness must be finite and non-negative.');
        }
        if ($padSpanAlongRun !== null && (! is_finite($padSpanAlongRun) || $padSpanAlongRun <= $Db)) {
            throw new InvalidArgumentException('PAD span along the run must be finite and exceed the branch OD.');
        }

        $sinB = sin(deg2rad($betaDeg));

        // Zone limits -------------------------------------------------------
        $d1 = ($Db - 2.0 * ($Tb - $c)) / $sinB;
        $d2 = max($d1, ($Tb - $c) + ($Th - $c) + $d1 / 2.0);
        $d2 = min($d2, $Dh);
        $TrNominal = $padThickness ?? 0.0;
        $Tr = $TrNominal * $padEffectiveThicknessFactor;
        $L4 = min(2.5 * ($Th - $c), 2.5 * ($Tb - $c) + $Tr);

        // Required area -----------------------------------------------------
        $A1 = $th * $d1 * (2.0 - $sinB);

        // Available areas ---------------------------------------------------
        $A2 = max(0.0, (2.0 * $d2 - $d1) * ($Th - $th - $c));
        // The code reduces A3 only when the branch allowable stress is lower;
        // a stronger branch is not credited with extra replacement area.
        $A3 = max(0.0, 2.0 * $L4 * ($Tb - $tb - $c) / $sinB) * min(1.0, $sBranchOverRun);

        // Pad area inside the zone: width limited by the zone, height by L4.
        $padWidth = null;
        $padHeight = null;
        $A4pad = 0.0;
        if ($padThickness !== null && $padThickness > 0.0) {
            $maxZoneWidth = max(0.0, 2.0 * $d2 - $Db);
            $padWidth = $padSpanAlongRun === null
                ? $maxZoneWidth
                : min($padSpanAlongRun - $Db, $maxZoneWidth);
            $padHeight = min($Tr, max(0.0, $L4));
            // Para 304.3.3(f)(4): a stronger pad is not credited above run S.
            $A4pad = $padWidth * $padHeight * min(1.0, $sPadOverRun);
        }

        // Weld-metal credit, para 328.5.4 minimum dimensions. Appendix H
        // counts two section cuts of the root cover fillet (H301) and, with
        // a reinforcing ring/saddle, two cuts of its outer-edge weld (H302).
        $A4rootWelds = 0.0;
        $A4padEdgeWelds = 0.0;
        if ($creditWelds) {
            $branchWallForWeld = $branchNominalThicknessForWeld ?? $Tb;
            $tc = min(0.7 * $branchWallForWeld, $weldThroatCap);
            $A4rootWelds = 2.0 * $tc * $tc;

            if ($padThickness !== null && $padThickness > 0.0) {
                $padEdgeThroat = 0.5 * $TrNominal;
                $A4padEdgeWelds = 2.0 * $padEdgeThroat * $padEdgeThroat;
            }
        }
        $A4welds = $A4rootWelds + $A4padEdgeWelds;

        $A4 = $A4pad + $A4welds;
        $available = $A2 + $A3 + $A4;
        $margin = $available - $A1;
        // Figure 304.3.3-1 note (2) proportions the physical ring/saddle A4
        // width against its full minimum reinforcement thickness Tr. L4 can
        // limit the area credit, but it does not make an over-thick PAD
        // physically wider, so do not use the clipped A4-area height here.
        $padWidthToHeight = $padThickness !== null && $padThickness > 0.0 && $Tr > 0.0
            ? (float) $padWidth / $Tr
            : null;

        return [
            'd1' => $d1,
            'd2' => $d2,
            'L4' => $L4,
            'A1' => $A1,
            'A2' => $A2,
            'A3' => $A3,
            'A4_pad' => $A4pad,
            'A4_root_welds' => $A4rootWelds,
            'A4_pad_edge_welds' => $A4padEdgeWelds,
            'A4_welds' => $A4welds,
            'A4' => $A4,
            'available' => $available,
            'margin' => $margin,
            'pass' => $margin >= -1e-9,
            'pad_effective_thickness' => $padThickness === null ? null : $Tr,
            'pad_effective_width' => $padWidth,
            'pad_effective_height' => $padHeight,
            'pad_width_to_height_ratio' => $padWidthToHeight,
        ];
    }

    /**
     * ASME B31.3 para 304.1.2(a) eq (3a): the pressure-design thickness of
     * a straight pipe under internal pressure.
     *
     *   t = P · D / (2 · (S·E·W + P·Y))
     *
     * P design pressure, D outside diameter, S allowable stress at design
     * temperature (Table A-1), E longitudinal joint factor, W weld-joint
     * strength reduction factor, Y coefficient from Table 304.1.1-1 (B31.3-2024 reference pack).
     * Caller-consistent units; the result feeds `evaluate()` as $th/$tb.
     * Corrosion/mechanical allowances $c are NOT included here — they are
     * carried separately in the reinforcement equations, per the code.
     *
     * This implementation uses eq (3a) only when both t < D/6 and
     * P/(S·E) <= 0.385. Para 304.1.2(b) requires special consideration when
     * either limit is exceeded, so the method refuses those cases rather than
     * silently extrapolate.
     */
    public static function pressureDesignThickness(
        float $P,
        float $D,
        float $S,
        float $E = 1.0,
        float $W = 1.0,
        float $Y = 0.4,
    ): float {
        foreach (compact('P', 'D', 'S', 'E', 'W') as $name => $value) {
            if (! is_finite($value) || $value <= 0.0) {
                throw new InvalidArgumentException("$name must be finite and positive.");
            }
        }
        if (! is_finite($Y) || $Y < 0.0) {
            throw new InvalidArgumentException('Y must be finite and non-negative.');
        }

        // Para 304.1.2(b) also requires special consideration when P/SE is
        // above 0.385, even if the eq (3a) result happens to be below D/6.
        if ($P / ($S * $E) > 0.385) {
            throw new InvalidArgumentException(
                'P/(S·E) > 0.385: outside the simple pressure-design range; B31.3 para 304.1.2(b) requires special consideration.'
            );
        }

        $t = ($P * $D) / (2.0 * ($S * $E * $W + $P * $Y));

        if ($t >= $D / 6.0) {
            throw new InvalidArgumentException(
                't >= D/6: outside the range of eq (3a); B31.3 para 304.1.2(b) requires special consideration.'
            );
        }

        return $t;
    }

    /**
     * Smallest pad thickness that makes the connection PASS, 0.0 when the
     * bare connection already passes. Solves by bisection — pad thickness
     * also grows L4, so no closed form exists. The returned value is nominal
     * pipe-saddle thickness; `padEffectiveThicknessFactor` converts it to the
     * minimum Tr used for L4 and PAD metal area. A null span retains the
     * legacy zone-filling fallback for standalone callers; production gate
     * callers pass the two-axis geometry's physical span along the run.
     * `$branchNominalThicknessForWeld` supplies nominal Tb
     * for the §328.5.4(c) root-weld throat.
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
        ?float $padSpanAlongRun = null,
        bool $creditWelds = true,
        float $weldThroatCap = 6.0,
        float $sBranchOverRun = 1.0,
        float $sPadOverRun = 1.0,
        ?float $branchNominalThicknessForWeld = null,
        float $padEffectiveThicknessFactor = 1.0,
    ): float {
        $bare = self::evaluate(
            $Dh, $Th, $th, $Db, $Tb, $tb, $c, $betaDeg,
            null, $padSpanAlongRun, $creditWelds, $weldThroatCap, $sBranchOverRun, $sPadOverRun,
            $branchNominalThicknessForWeld, $padEffectiveThicknessFactor,
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
                $mid, $padSpanAlongRun, $creditWelds, $weldThroatCap, $sBranchOverRun, $sPadOverRun,
                $branchNominalThicknessForWeld, $padEffectiveThicknessFactor,
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
