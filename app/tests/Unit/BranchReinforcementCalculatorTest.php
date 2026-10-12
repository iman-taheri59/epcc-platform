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
            c: 0.0, padThickness: 0.25, padOd: 8.052,
            weldThroatCap: self::IN_MM,
        );

        $this->assertEqualsWithDelta(3.552, $r['pad_effective_width'], 1e-6);
        $this->assertEqualsWithDelta(0.25, $r['pad_effective_height'], 1e-6);
        $this->assertEqualsWithDelta(0.888, $r['A4_pad'], 1e-6);
        $this->assertEqualsWithDelta(0.0550456, $r['A4_welds'], 1e-6);
        $this->assertEqualsWithDelta(0.7246016, $r['margin'], 1e-6);
        $this->assertTrue($r['pass']);
    }

    /** Fixture 4 — pad wider than the zone is capped at the zone width. */
    public function test_pad_wider_than_zone_is_capped(): void
    {
        $wide = Calc::evaluate(
            Dh: self::DH, Th: 0.200, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, padThickness: 0.25, padOd: 10.0, creditWelds: false,
        );
        $this->assertEqualsWithDelta(3.552, $wide['pad_effective_width'], 1e-6);

        // A narrow pad contributes proportionally less area.
        $narrow = Calc::evaluate(
            Dh: self::DH, Th: 0.200, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, padThickness: 0.25, padOd: 6.0, creditWelds: false,
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

    /** A pad OD not exceeding the branch OD is a corrupt input. */
    public function test_pad_od_not_larger_than_branch_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Calc::evaluate(
            Dh: self::DH, Th: 0.322, th: self::TH_REQ,
            Db: self::DB, Tb: self::TB, tb: self::TB_REQ,
            c: 0.0, padThickness: 0.25, padOd: 4.0,
        );
    }
}
