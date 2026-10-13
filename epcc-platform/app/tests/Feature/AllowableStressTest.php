<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Reference\AllowableStress;
use App\Support\Reference\WallThickness;
use Tests\TestCase;

/**
 * ASME B31.3-2024 Table A-1 — slice 3, and the end of the B31.3 sequence.
 *
 * S is the most consequential number in the Code and the one this repo
 * refused to guess at for two slices. Table A-1 joins a material's identity to
 * its stresses across separate page blocks by a line number that **restarts in
 * every material section**, so a mis-join is possible and entirely silent.
 *
 * These tests are about the two gates a value must clear before it may be
 * cited, and about what happens to the 721 that clear only one.
 *
 * No database.
 */
final class AllowableStressTest extends TestCase
{
    private function s(): AllowableStress
    {
        return app(AllowableStress::class);
    }

    // ------------------------------------------- values a piping engineer knows

    public function test_the_classic_carbon_steel_values_come_out_right(): void
    {
        // A106 Grade B at room temperature is 138 MPa — 20.0 ksi, the most
        // quoted allowable stress in process piping. A216 WCB is 161 MPa.
        // If the join had slipped, these are the first numbers that would
        // look wrong to anyone who has specified a line.
        self::assertSame(138000, $this->s()->stressAt('A106', 'B', 38.0));
        self::assertSame(161000, $this->s()->stressAt('A216', 'WCB', 38.0));
    }

    public function test_stress_falls_as_temperature_rises_and_is_interpolated(): void
    {
        $cold = $this->s()->stressAt('A106', 'B', 38.0);
        $warm = $this->s()->stressAt('A106', 'B', 400.0);

        self::assertNotNull($cold);
        self::assertNotNull($warm);
        self::assertLessThan($cold, $warm);

        // Between two tabulated temperatures the value is interpolated, so it
        // must sit strictly between its neighbours rather than snapping.
        $a = $this->s()->stressAt('A106', 'B', 300.0);
        $mid = $this->s()->stressAt('A106', 'B', 312.0);
        $b = $this->s()->stressAt('A106', 'B', 325.0);
        self::assertGreaterThan($b, $mid);
        self::assertLessThan($a, $mid);
    }

    public function test_there_is_no_stress_above_the_last_tabulated_temperature(): void
    {
        // Extrapolating an allowable stress is how a line gets specified for a
        // service it cannot hold.
        self::assertNull($this->s()->stressAt('A106', 'B', 700.0));
        self::assertSame(
            'ABOVE_THE_LAST_TABULATED_TEMPERATURE',
            $this->s()->refusalFor('A106', 'B', 700.0),
        );
    }

    // --------------------------------------------- the two gates

    public function test_parsing_is_not_verification(): void
    {
        // 798 materials survived the structural join. Only 77 also agree with
        // the Code's own basis — para. 302.3.2, S = min(UTS/3, two-thirds
        // yield) — computed from tensile and yield figures that came from the
        // ASTM specifications rather than from this table. Only those 77 are
        // citable.
        $c = $this->s()->coverage();

        self::assertSame(77, $c['citable']);
        self::assertSame(721, $c['held']);
        self::assertSame(798, $c['citable'] + $c['held']);
    }

    public function test_a_held_material_refuses_and_says_which_gate_it_failed(): void
    {
        // A105 is in the table and structurally sound, and its figure does not
        // match the simplified basis, so it is held rather than cited. A
        // plausible stress is indistinguishable from a right one.
        self::assertNull($this->s()->stressAt('A105', null, 38.0));
        self::assertSame(
            'HELD_NOT_INDEPENDENTLY_CONFIRMED',
            $this->s()->refusalFor('A105', null, 38.0),
        );
        self::assertStringContainsString(
            'not citable',
            (string) $this->s()->heldBecause('A105', null),
        );
    }

    public function test_a_material_that_is_not_in_the_table_says_so_differently(): void
    {
        // Three different nulls with three different meanings: never heard of
        // it, heard of it but cannot vouch for it, and past the end of its
        // curve.
        self::assertSame(
            'MATERIAL_NOT_IN_TABLE_A1',
            $this->s()->refusalFor('A999', 'Z', 38.0),
        );
    }

    public function test_nothing_in_the_pack_exceeds_the_codes_own_basis(): void
    {
        // THE EVIDENCE THE JOIN HELD. B31.3 never allows more than
        // min(UTS/3, two-thirds yield). A mis-join scatters values in BOTH
        // directions, so a single value above the basis would be a red flag —
        // and there are none. The 21 that differ are all BELOW it, where a
        // class, a weld joint efficiency or a time-dependent criterion
        // governs instead.
        $pack = json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/allowable-stress.ref.json'),
        ), true);

        $above = $below = 0;
        foreach ($pack['materials'] as $m) {
            if ($m['basisCheck'] === null) {
                continue;
            }
            $d = $m['basisCheck']['deltaFraction'];
            if ($d > $pack['basisClause']['toleranceFraction']) {
                $above++;
            } elseif ($d < -$pack['basisClause']['toleranceFraction']) {
                $below++;
            }
        }

        self::assertSame(0, $above, 'a value above the basis is impossible under B31.3');
        self::assertGreaterThan(0, $below);
    }

    public function test_stress_never_rises_with_temperature_anywhere_in_the_pack(): void
    {
        // The other signature of a mis-join, checked across all 798 curves
        // rather than the handful the other tests touch.
        $pack = json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/allowable-stress.ref.json'),
        ), true);

        $checked = 0;
        foreach ($pack['materials'] as $m) {
            $pts = $m['points'];
            for ($i = 1; $i < count($pts); $i++) {
                self::assertGreaterThan(
                    $pts[$i - 1]['temperatureC'],
                    $pts[$i]['temperatureC'],
                );
                self::assertLessThanOrEqual(
                    $pts[$i - 1]['stressMpa'],
                    $pts[$i]['stressMpa'],
                    "{$m['spec']} {$m['grade']} rises at {$pts[$i]['temperatureC']} C",
                );
                $checked++;
            }
        }
        self::assertGreaterThan(5000, $checked);
    }

    public function test_an_ambiguous_spec_without_a_grade_refuses_to_guess(): void
    {
        // A182 covers F11, F12, F304L and a dozen more, each with its own
        // curve. Returning the first would hand the caller another grade's
        // allowable stress, which is exactly the failure this pack is built
        // to avoid.
        self::assertNull($this->s()->stressAt('A182', null, 38.0));
    }

    // ------------------------------------- the chain the three slices built

    public function test_the_three_slices_now_calculate_a_wall_thickness(): void
    {
        // Slice 1 gave the fluid service, slice 2 the equation and Y, slice 3
        // the S. NPS 6 (168.3 mm) A106 B at 100 C, Class 150 shell pressure
        // 1 960 kPa, 3 mm corrosion allowance:
        //
        //   t  = 1960 x 168.3 / (2 x (138000 + 1960 x 0.4)) = 1.188 mm
        //   tm = 4.188 mm
        //
        // Schedule 40 at NPS 6 is 7.11 mm, so an ordinary class is
        // comfortable — which is the answer a reviewer expects and the reason
        // a wrong S would be so hard to notice.
        $s = $this->s()->stressAt('A106', 'B', 100.0);
        self::assertNotNull($s);

        $r = app(WallThickness::class)->calculate(
            1960.0, 168.3, (float) $s, 'FERRITIC_STEEL', 100.0, 3.0,
        );

        self::assertNull($r['refusal']);
        self::assertEqualsWithDelta(1.188, $r['t'], 0.002);
        self::assertEqualsWithDelta(4.188, $r['tm'], 0.002);
        self::assertLessThan(7.11, $r['tm']);
    }

    public function test_a_held_material_stops_the_chain_rather_than_bending_it(): void
    {
        // The whole point of the two gates. A105 is held, so there is no S,
        // so there is no thickness — and the refusal names the missing input
        // instead of the calculation producing a number from a guess.
        $s = $this->s()->stressAt('A105', null, 100.0);
        self::assertNull($s);

        $r = app(WallThickness::class)->calculate(
            1960.0, 168.3, $s, 'FERRITIC_STEEL', 100.0, 3.0,
        );

        self::assertNull($r['t']);
        self::assertSame('ALLOWABLE_STRESS_NOT_SUPPLIED', $r['refusal']);
    }
}
