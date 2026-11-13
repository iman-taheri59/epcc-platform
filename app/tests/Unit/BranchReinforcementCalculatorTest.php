<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BranchReinforcementCalculator as Calc;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * ASME B31.3 para 304.3.3 branch-reinforcement calculator fixtures.
 *
 * Fixtures 1–3 reproduce the worked example published on pipingtoolset.com
 * (Weldolet/fabricated-branch calculator, ¶304.3.3, 2026) in INCHES — the
 * two run-wall variants and the pad recovery — so the weld-throat cap is
 * passed as 6 mm converted to inches. Fixtures 4–7 are hand-verified from
 * the same inputs. Requirement: ADR-008 s9.3.
 */
class BranchReinforcementCalculatorTest extends TestCase
{
    /** Common geometry of the published example (inches). */
    private const DH = 6.625;

    private const DB = 4.500;

    private const TB = 0.237;

    private const TB_REQ = 0.077;

    private const TH_REQ = 0.147;

    private const IN_MM = 6.0 / 25.4; // 6 mm weld-throat cap, in inches

    /** Fixture 1 — thick run wall: passes with no reinforcement at all. */
    public function test_thick_run_passes_without_reinforcement(): void
    {
        $r = Calc::evaluate(
            Dh: self::DH, Th: 0.322, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, creditWelds: false,
        );

        $this->assertEqualsWithDelta(4.026, $r['d1'], 1e-6);
        $this->assertEqualsWithDelta(4.026, $r['d2'], 1e-6);
        $this->assertEqualsWithDelta(0.5925, $r['L4'], 1e-6);
        $this->assertEqualsWithDelta(0.591822, $r['A1'], 1e-6);
        $this->assertEqualsWithDelta(0.70455, $r['A2'], 1e-6);
        $this->assertEqualsWithDelta(0.1896, $r['A3'], 1e-6);
        $this->assertEqualsWithDelta(0.302328, $r['margin'], 1e-6);
        $this->assertTrue($r['pass']);
    }

    /** Fixture 2 — thinner run wall: fails, shortfall 0.2184 in². */
    public function test_thin_run_fails_without_reinforcement(): void
    {
        $r = Calc::evaluate(
            Dh: self::DH, Th: 0.200, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, creditWelds: false,
        );

        $this->assertEqualsWithDelta(0.5, $r['L4'], 1e-6);
        $this->assertEqualsWithDelta(0.213378, $r['A2'], 1e-6);
        $this->assertEqualsWithDelta(0.16, $r['A3'], 1e-6);
        $this->assertEqualsWithDelta(-0.218444, $r['margin'], 1e-6);
        $this->assertFalse($r['pass']);
    }

    /** Fixture 3 — a 0.25" pad restores the balance (plus minimum welds). */
    public function test_pad_restores_the_balance(): void
    {
        $r = Calc::evaluate(
            Dh: self::DH, Th: 0.200, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, padThickness: 0.25, padSpanAlongRun: 8.052,
            weldThroatCap: self::IN_MM,
        );

        $this->assertEqualsWithDelta(3.552, $r['pad_effective_width'], 1e-6);
        $this->assertEqualsWithDelta(0.25, $r['pad_effective_height'], 1e-6);
        $this->assertEqualsWithDelta(0.888, $r['A4_pad'], 1e-6);
        $this->assertEqualsWithDelta(0.0550456, $r['A4_root_welds'], 1e-6);
        $this->assertEqualsWithDelta(0.03125, $r['A4_pad_edge_welds'], 1e-12);
        $this->assertEqualsWithDelta(0.0862956, $r['A4_welds'], 1e-6);
        $this->assertEqualsWithDelta(0.7558516, $r['margin'], 1e-6);
        $this->assertTrue($r['pass']);
    }

    /** Appendix H H302: pressure balance, pipe-saddle minimum wall, and two credited weld sets. */
    public function test_h302_uses_pipe_saddle_minimum_wall_and_pad_edge_weld_credit(): void
    {
        // Published maximum pressure is 4.347 MPa. Recalculate t from the
        // exact Eq. (3a) inputs instead of substituting an arbitrary t/tb;
        // the standard rounds intermediate dimensions and area to 3 figures.
        $pressureMpa = 4.347;
        $th = Calc::pressureDesignThickness(P: $pressureMpa, D: 323.8, S: 55.2);
        $tb = Calc::pressureDesignThickness(P: $pressureMpa, D: 219.1, S: 55.2);
        $inputs = [
            'Dh' => 323.8, 'Th' => 15.3, 'th' => $th,
            'Db' => 219.1, 'Tb' => 11.1, 'tb' => $tb,
            'c' => 0.0, 'betaDeg' => 90.0,
            'padThickness' => 17.48,
            'padSpanAlongRun' => 350.0,
            'sPadOverRun' => 39300.0 / 55200.0,
            'branchNominalThicknessForWeld' => 12.7,
            'padEffectiveThicknessFactor' => 0.875,
        ];
        $r = Calc::evaluate(...$inputs);

        $this->assertEqualsWithDelta(196.9, $r['d1'], 1e-12);
        $this->assertEqualsWithDelta(196.9, $r['d2'], 1e-12);
        $this->assertEqualsWithDelta(38.25, $r['L4'], 1e-12);
        $this->assertEqualsWithDelta(15.295, $r['pad_effective_thickness'], 1e-12);
        $this->assertEqualsWithDelta(130.9, $r['pad_effective_width'], 1e-9);
        $this->assertEqualsWithDelta(15.295, $r['pad_effective_height'], 1e-9);
        $this->assertEqualsWithDelta(1425.4191875, $r['A4_pad'], 1e-9);
        $this->assertEqualsWithDelta(72.0, $r['A4_root_welds'], 1e-12);
        $this->assertEqualsWithDelta(152.7752, $r['A4_pad_edge_welds'], 1e-9);
        $this->assertEqualsWithDelta(224.7752, $r['A4_welds'], 1e-9);
        $this->assertEqualsWithDelta(1650.1943875, $r['A4'], 1e-9);
        $this->assertEqualsWithDelta(2433.7384028, $r['A1'], 1e-6);
        $this->assertEqualsWithDelta(578.8315972, $r['A2'], 1e-6);
        $this->assertEqualsWithDelta(209.3339251, $r['A3'], 1e-6);
        $this->assertEqualsWithDelta(4.6215070, $r['margin'], 1e-6);
        $this->assertTrue($r['pass']);

        // The rounded H302 result is 4.347 MPa. Our exact 0.875-derived PAD
        // wall is 15.295 rather than the printed 15.30 mm, so a 5 kPa increase
        // is expected to cross the area-balance limit.
        $overPressure = Calc::pressureDesignThickness(P: 4.352, D: 323.8, S: 55.2);
        $overBranchPressure = Calc::pressureDesignThickness(P: 4.352, D: 219.1, S: 55.2);
        $overInputs = $inputs;
        $overInputs['th'] = $overPressure;
        $overInputs['tb'] = $overBranchPressure;
        $over = Calc::evaluate(...$overInputs);
        $this->assertFalse($over['pass']);
        $this->assertEqualsWithDelta(-1.5194216, $over['margin'], 1e-6);

        // H302 displays 1,426 mm² ring area using its printed 15.30 mm
        // minimum wall. Exact 17.48 × 0.875 is 15.295 mm, so the PAD-area
        // difference is under 1 mm².
        $this->assertEqualsWithDelta(1426.0, $r['A4_pad'], 1.0);
        $this->assertEqualsWithDelta(225.0, $r['A4_welds'], 0.3);
    }

    public function test_shape_ratio_uses_full_pad_thickness_even_when_l4_clips_a4_area_height(): void
    {
        $r = Calc::evaluate(
            Dh: 168.3, Th: 6.22125, th: 0.1,
            Db: 21.3, Tb: 2.42375, tb: 0.1,
            c: 0.0, betaDeg: 90.0,
            padThickness: 100.0,
            padSpanAlongRun: 33.7425,
            creditWelds: false,
            padEffectiveThicknessFactor: 0.875,
        );

        $this->assertEqualsWithDelta(15.553125, $r['L4'], 1e-12);
        $this->assertEqualsWithDelta(15.553125, $r['pad_effective_height'], 1e-12, 'A4 area is clipped by L4.');
        $this->assertEqualsWithDelta(12.4425, $r['pad_effective_width'], 1e-12);
        $this->assertEqualsWithDelta(87.5, $r['pad_effective_thickness'], 1e-12);
        $this->assertEqualsWithDelta(12.4425 / 87.5, $r['pad_width_to_height_ratio'], 1e-12, 'Figure note (2) uses physical PAD thickness, not the clipped area height.');
    }

    public function test_pad_effective_thickness_factor_must_be_in_zero_to_one(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Calc::evaluate(
            Dh: 100.0, Th: 20.0, th: 10.0,
            Db: 40.0, Tb: 8.0, tb: 3.0,
            c: 0.0, padThickness: 6.0,
            padEffectiveThicknessFactor: 1.01,
        );
    }

    /** Fixture 4 — pad wider than the zone is capped at the zone width. */
    public function test_pad_wider_than_zone_is_capped(): void
    {
        $wide = Calc::evaluate(
            Dh: self::DH, Th: 0.200, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, padThickness: 0.25, padSpanAlongRun: 10.0, creditWelds: false,
        );
        $this->assertEqualsWithDelta(3.552, $wide['pad_effective_width'], 1e-6);

        // A narrow pad contributes proportionally less area.
        $narrow = Calc::evaluate(
            Dh: self::DH, Th: 0.200, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, padThickness: 0.25, padSpanAlongRun: 6.0, creditWelds: false,
        );
        $this->assertEqualsWithDelta(1.5, $narrow['pad_effective_width'], 1e-6);
        $this->assertEqualsWithDelta(0.375, $narrow['A4_pad'], 1e-6);
        $this->assertEqualsWithDelta(0.156556, $narrow['margin'], 1e-6);
        $this->assertTrue($narrow['pass']);
    }

    /** Fixture 5 — minimum required pad thickness solves the shortfall. */
    public function test_required_pad_thickness_solves_the_shortfall(): void
    {
        $required = Calc::requiredPadThickness(
            Dh: self::DH, Th: 0.200, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, creditWelds: false,
        );
        // shortfall 0.218444 in² over zone width 3.552 in
        $this->assertEqualsWithDelta(0.218444 / 3.552, $required, 5e-4);

        // And a connection that already passes asks for nothing.
        $zero = Calc::requiredPadThickness(
            Dh: self::DH, Th: 0.322, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, creditWelds: false,
        );
        $this->assertSame(0.0, $zero);
    }

    /** Fixture 6 — weaker branch material scales A3 down by the ratio. */
    public function test_allowable_stress_ratio_reduces_branch_credit(): void
    {
        $r = Calc::evaluate(
            Dh: self::DH, Th: 0.200, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, creditWelds: false, sBranchOverRun: 0.9,
        );
        $this->assertEqualsWithDelta(0.144, $r['A3'], 1e-6);
        $this->assertEqualsWithDelta(-0.234444, $r['margin'], 1e-6);
    }

    public function test_allowable_stress_above_run_does_not_increase_branch_or_pad_credit(): void
    {
        $baseline = Calc::evaluate(
            Dh: self::DH, Th: 0.200, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, padThickness: 0.25, padSpanAlongRun: 10.0,
            creditWelds: false, sBranchOverRun: 1.0, sPadOverRun: 1.0,
        );
        $strongerMaterials = Calc::evaluate(
            Dh: self::DH, Th: 0.200, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, padThickness: 0.25, padSpanAlongRun: 10.0,
            creditWelds: false, sBranchOverRun: 1.2, sPadOverRun: 1.3,
        );

        $this->assertSame($baseline['A3'], $strongerMaterials['A3']);
        $this->assertSame($baseline['A4_pad'], $strongerMaterials['A4_pad']);
    }

    public function test_run_wall_below_pressure_thickness_plus_allowance_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('run wall after mill tolerance');
        Calc::evaluate(
            Dh: self::DH, Th: 0.322, th: 0.147, Db: self::DB,
            Tb: self::TB, tb: 0.077, c: 0.176,
        );
    }

    public function test_branch_wall_below_pressure_thickness_plus_allowance_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('branch wall after mill tolerance');
        Calc::evaluate(
            Dh: self::DH, Th: 0.322, th: 0.147, Db: self::DB,
            Tb: self::TB, tb: 0.077, c: 0.161,
        );
    }

    /** Fixture 7 — a 45-degree lateral: longer opening, (2−sin β) penalty. */
    public function test_lateral_branch_at_45_degrees(): void
    {
        $r = Calc::evaluate(
            Dh: self::DH, Th: 0.322, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, betaDeg: 45.0, creditWelds: false,
        );

        $this->assertEqualsWithDelta(5.693624, $r['d1'], 1e-6);
        $this->assertEqualsWithDelta(5.693624, $r['d2'], 1e-6);
        $this->assertEqualsWithDelta(1.082103, $r['A1'], 1e-6);
        $this->assertEqualsWithDelta(0.996384, $r['A2'], 1e-6);
        $this->assertEqualsWithDelta(0.268135, $r['A3'], 1e-6);
        $this->assertEqualsWithDelta(0.182416, $r['margin'], 1e-6);
        $this->assertTrue($r['pass']);
    }

    public function test_critical_beta_sweep_catches_an_interior_piecewise_minimum(): void
    {
        $angles = Calc::criticalBetaAngles(
            Dh: 100.0, Th: 20.0, Db: 80.0, Tb: 15.0, c: 0.0,
        );

        // K=50 and (Th-c)+(Tb-c)=35, so d1 overtakes the other d2
        // candidate at csc(beta)=70/50=1.4, beta=45.5847 deg.
        $transitionBeta = rad2deg(asin(1.0 / 1.4));
        $this->assertCount(3, $angles);
        $this->assertSame(45.0, $angles[0]);
        $this->assertEqualsWithDelta($transitionBeta, $angles[1], 1e-9);
        $this->assertSame(90.0, $angles[2]);

        $margins = [];
        foreach ($angles as $beta) {
            $result = Calc::evaluate(
                Dh: 100.0, Th: 20.0, th: 10.0,
                Db: 80.0, Tb: 15.0, tb: 3.0,
                c: 0.0, betaDeg: $beta, creditWelds: false,
            );
            $margins[] = ['beta' => $beta, 'margin' => $result['margin']];
        }

        $marginValues = array_column($margins, 'margin');
        $minimumMargin = min($marginValues);
        $worstIndex = array_search($minimumMargin, $marginValues, true);
        $this->assertNotFalse($worstIndex);
        $this->assertEqualsWithDelta($transitionBeta, $margins[$worstIndex]['beta'], 1e-9);
        $this->assertEqualsWithDelta(1060.0, $minimumMargin, 1e-9);
        $this->assertGreaterThan($minimumMargin, $margins[0]['margin']);
        $this->assertGreaterThan($minimumMargin, $margins[2]['margin']);
    }

    public function test_critical_beta_candidates_include_zone_cap_and_finite_pad_width_transitions(): void
    {
        // NPS 1/2 header XXS and branch SCH 40 after the 0.875 wall factor.
        $Dh = 21.3;
        $Th = 7.47 * 0.875;
        $Db = 21.3;
        $Tb = 2.77 * 0.875;
        $padSpanAlongRun = 38.0;
        $K = $Db - 2.0 * $Tb;
        $sumWalls = $Th + $Tb;

        $angles = Calc::criticalBetaAngles(
            Dh: $Dh, Th: $Th, Db: $Db, Tb: $Tb, c: 0.0, padSpanAlongRun: $padSpanAlongRun,
        );

        $zoneCapBeta = rad2deg(asin($K / $Dh));
        $padWidthCapBeta = rad2deg(asin($K / ($padSpanAlongRun / 2.0)));
        $zoneBranchSwitchBeta = rad2deg(asin($K / (2.0 * $sumWalls)));

        $this->assertCount(5, $angles);
        $this->assertSame(45.0, $angles[0]);
        $this->assertEqualsWithDelta($zoneCapBeta, $angles[1], 1e-9);
        $this->assertEqualsWithDelta($padWidthCapBeta, $angles[2], 1e-9);
        $this->assertEqualsWithDelta($zoneBranchSwitchBeta, $angles[3], 1e-9);
        $this->assertSame(90.0, $angles[4]);
    }

    public function test_zone_geometry_refuses_branch_larger_than_run(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Calc::zoneGeometry(Dh: 100.0, Th: 10.0, Db: 120.0, Tb: 8.0, c: 0.0);
    }

    public function test_area_balance_refuses_branch_larger_than_run(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Calc::evaluate(
            Dh: 100.0, Th: 10.0, th: 2.0,
            Db: 120.0, Tb: 8.0, tb: 2.0,
            c: 0.0,
        );
    }

    public function test_zone_geometry_refuses_non_finite_inputs(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Calc::zoneGeometry(Dh: INF, Th: 10.0, Db: 40.0, Tb: 8.0, c: 0.0);
    }

    /** The method is only defined for branch angles 45..90 degrees. */
    public function test_angle_outside_45_to_90_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Calc::evaluate(
            Dh: self::DH, Th: 0.322, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, betaDeg: 30.0,
        );
    }

    /** A finite physical PAD span along the run must exceed the branch OD. */
    public function test_pad_span_along_run_not_larger_than_branch_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Calc::evaluate(
            Dh: self::DH, Th: 0.322, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, padThickness: 0.25, padSpanAlongRun: 4.0,
        );
    }

    /** Eq (3a): pressure-design thickness of a straight pipe, SI fixture. */
    public function test_pressure_design_thickness_eq_3a(): void
    {
        // P=3 MPa, D=168.3 mm (DN150), S=118 MPa, E=W=1, Y=0.4
        $this->assertEqualsWithDelta(
            504.9 / 238.4,
            Calc::pressureDesignThickness(P: 3.0, D: 168.3, S: 118.0),
            1e-9,
        );

        // Same conditions on the DN50 branch.
        $this->assertEqualsWithDelta(
            0.758809,
            Calc::pressureDesignThickness(P: 3.0, D: 60.3, S: 118.0),
            1e-6,
        );

        // Reduced joint/strength factors and a hotter-service Y.
        $this->assertEqualsWithDelta(
            3.211832,
            Calc::pressureDesignThickness(P: 3.0, D: 168.3, S: 100.0, E: 0.85, W: 0.9, Y: 0.7),
            1e-6,
        );
    }

    /** Eq (3a) only holds while t < D/6; beyond it the code changes regime. */
    public function test_eq_3a_refuses_the_thick_wall_regime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        // P/(S·E)=0.35 is within its own limit, but t=17.5 mm > D/6.
        Calc::pressureDesignThickness(P: 35.0, D: 100.0, S: 100.0, Y: 0.0);
    }

    /** §304.1.2(b) also limits P/(S·E), even when t itself is below D/6. */
    public function test_eq_3a_refuses_p_over_se_above_0385_even_when_t_is_below_d_over_6(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('P/(S·E) > 0.385');
        Calc::pressureDesignThickness(P: 40.0, D: 100.0, S: 100.0, Y: 0.7);
    }

    public function test_p_over_se_equal_to_0385_is_not_rejected_by_that_limit(): void
    {
        $t = Calc::pressureDesignThickness(P: 38.5, D: 100.0, S: 100.0, Y: 0.7);

        $this->assertTrue($t < 100.0 / 6.0);
    }

    /** End-to-end: eq (3a) feeds the reinforcement check, all in mm. */
    public function test_eq_3a_feeds_the_reinforcement_check(): void
    {
        // DN150 SCH40 header (wall 7.11), DN50 SCH40 branch (3.91),
        // 3 MPa, S=118 MPa, c=1.5 mm, perpendicular, minimum welds.
        $th = Calc::pressureDesignThickness(P: 3.0, D: 168.3, S: 118.0);
        $tb = Calc::pressureDesignThickness(P: 3.0, D: 60.3, S: 118.0);

        $r = Calc::evaluate(
            Dh: 168.3, Th: 7.11, th: $th,
            Db: 60.3, Tb: 3.91, tb: $tb,
            c: 1.5,
        );

        $this->assertEqualsWithDelta(55.48, $r['d1'], 1e-6);
        $this->assertEqualsWithDelta(6.025, $r['L4'], 1e-6);
        $this->assertEqualsWithDelta(117.499379, $r['A1'], 1e-6);
        $this->assertEqualsWithDelta(193.743421, $r['A2'], 1e-6);
        $this->assertEqualsWithDelta(19.896855, $r['A3'], 1e-6);
        $this->assertEqualsWithDelta(14.982338, $r['A4_welds'], 1e-6);
        $this->assertEqualsWithDelta(111.123234, $r['margin'], 1e-6);
        $this->assertTrue($r['pass']);
    }

    /** Regression anchor: ASME B31.3-2024 Appendix H, Example H301 (rounded source inputs). */
    public function test_appendix_h_h301_uses_nominal_branch_wall_for_the_weld_credit(): void
    {
        $r = Calc::evaluate(
            Dh: 219.1, Th: 7.16, th: 2.04,
            Db: 114.3, Tb: 5.27, tb: 1.07,
            c: 2.5,
            branchNominalThicknessForWeld: 6.02,
        );

        // H301 publishes approximately d1=108.8, L4=6.93, A1=222,
        // A2=285, A3=24, and two-weld A4=36 mm². Rounded printed inputs
        // produce these unrounded values in the pure calculation.
        $this->assertEqualsWithDelta(108.76, $r['d1'], 1e-9);
        $this->assertEqualsWithDelta(6.925, $r['L4'], 1e-9);
        $this->assertEqualsWithDelta(221.8704, $r['A1'], 1e-9);
        $this->assertEqualsWithDelta(284.9512, $r['A2'], 1e-9);
        $this->assertEqualsWithDelta(23.545, $r['A3'], 1e-9);
        $this->assertEqualsWithDelta(35.515592, $r['A4_welds'], 1e-9);
        $this->assertTrue($r['pass']);
    }
}
