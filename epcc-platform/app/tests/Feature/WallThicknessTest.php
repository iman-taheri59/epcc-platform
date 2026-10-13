<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Reference\WallThickness;
use Tests\TestCase;

/**
 * ASME B31.3-2024 clause 304.1.2 — slice 2.
 *
 * `PipingClassHeader` has been saying *"no B31.3 wall thickness can be
 * calculated"* since step 4 without anyone having read the clause. Now it is
 * read: the equation, its coefficient, and — the part that matters most — the
 * two places where the Code withdraws it.
 *
 * No database.
 */
final class WallThicknessTest extends TestCase
{
    private function wt(): WallThickness
    {
        return app(WallThickness::class);
    }

    // ------------------------------------------------- the equation itself

    public function test_the_equation_reproduces_a_hand_calculation(): void
    {
        // NPS 6 (D = 168.3 mm), ferritic steel at 100 C so Y = 0.4, Class 150
        // shell pressure 1 960 kPa, S = 137 900 kPa, E = W = 1, c = 3 mm.
        //
        //   t = 1960 x 168.3 / (2 x (137900 + 1960 x 0.4))
        //     = 329 868 / 277 368 = 1.1893 mm
        //   tm = t + c = 4.1893 mm
        $r = $this->wt()->calculate(1960.0, 168.3, 137900.0, 'FERRITIC_STEEL', 100.0, 3.0);

        self::assertNull($r['refusal']);
        self::assertEqualsWithDelta(1.1893, $r['t'], 0.0005);
        self::assertEqualsWithDelta(4.1893, $r['tm'], 0.0005);
        self::assertSame(0.4, $r['Y']);
    }

    public function test_the_allowance_is_added_and_not_multiplied_in(): void
    {
        // tm = t + c. A corrosion allowance is metal that is allowed to
        // disappear, not a factor on the pressure design thickness.
        $bare = $this->wt()->calculate(1960.0, 168.3, 137900.0, 'FERRITIC_STEEL', 100.0, 0.0);
        $with = $this->wt()->calculate(1960.0, 168.3, 137900.0, 'FERRITIC_STEEL', 100.0, 3.0);

        self::assertSame($bare['t'], $with['t']);
        self::assertEqualsWithDelta(3.0, $with['tm'] - $bare['tm'], 1e-9);
    }

    // --------------------------------------------------- coefficient Y

    public function test_the_first_and_last_y_columns_are_open_bands(): void
    {
        // Table 304.1.1-1's first column is "482 C AND BELOW" and its last is
        // "677 C AND ABOVE". Treating them as points would leave a ferritic
        // line at 20 C with no coefficient at all.
        self::assertSame(0.4, $this->wt()->coefficientY('FERRITIC_STEEL', 20.0));
        self::assertSame(0.4, $this->wt()->coefficientY('FERRITIC_STEEL', 482.0));
        self::assertSame(0.7, $this->wt()->coefficientY('FERRITIC_STEEL', 900.0));
    }

    public function test_y_is_interpolated_between_columns(): void
    {
        // The symbol list in 304.1.2 permits it: "the value of Y may be
        // interpolated for intermediate temperatures". Ferritic runs 0.5 at
        // 510 C and 0.7 at 538 C, so 524 C is halfway: 0.6.
        self::assertEqualsWithDelta(0.6, $this->wt()->coefficientY('FERRITIC_STEEL', 524.0), 1e-9);

        // Austenitic runs 0.5 at 593 C and 0.7 at 621 C.
        self::assertEqualsWithDelta(0.55, $this->wt()->coefficientY('AUSTENITIC_STEEL', 600.0), 1e-9);
    }

    public function test_ferritic_and_austenitic_diverge_where_it_matters(): void
    {
        // They agree at and below 482 C and part company from 510 C up. A
        // transposition between these two rows is the easiest silent error in
        // the table, because the low-temperature end looks identical.
        self::assertSame(
            $this->wt()->coefficientY('FERRITIC_STEEL', 400.0),
            $this->wt()->coefficientY('AUSTENITIC_STEEL', 400.0),
        );
        self::assertNotSame(
            $this->wt()->coefficientY('FERRITIC_STEEL', 538.0),
            $this->wt()->coefficientY('AUSTENITIC_STEEL', 538.0),
        );
    }

    public function test_gray_iron_has_no_coefficient_above_the_first_band(): void
    {
        // Y is 0.0 at and below 482 C and the table is blank beyond it -
        // gray iron is not used there, and a null is that fact rather than a
        // gap to fill.
        self::assertSame(0.0, $this->wt()->coefficientY('GRAY_IRON', 200.0));
        self::assertNull($this->wt()->coefficientY('GRAY_IRON', 600.0));
    }

    // ------------------------------------------ what the Code withdraws

    public function test_without_an_allowable_stress_it_refuses(): void
    {
        // THE HONEST STATE OF SLICE 2. The equation is transcribed; Table A-1
        // is not. S has to arrive from a source the caller can cite, and a
        // guessed S gives a wrong thickness that looks exactly like a right
        // one.
        $r = $this->wt()->calculate(1960.0, 168.3, null, 'FERRITIC_STEEL', 100.0, 3.0);

        self::assertNull($r['t']);
        self::assertSame('ALLOWABLE_STRESS_NOT_SUPPLIED', $r['refusal']);
        self::assertStringContainsString('Table A-1', $this->wt()->explain($r['refusal']));
    }

    public function test_the_thin_wall_equation_stops_where_the_code_stops_it(): void
    {
        // Clause 304.1.2(b): for P/SE > 0.385 the equation is withdrawn and
        // the Code calls for special consideration of failure theory, fatigue
        // and thermal stress. A calculator that keeps multiplying past its own
        // validity limit is worse than none, because the answer still looks
        // like an answer.
        $r = $this->wt()->calculate(60000.0, 168.3, 137900.0, 'FERRITIC_STEEL', 100.0, 3.0);

        self::assertNull($r['t']);
        self::assertSame('P_OVER_SE_ABOVE_0_385', $r['refusal']);
    }

    public function test_the_two_limits_in_304_1_2b_are_one_limit(): void
    {
        // A pleasing consequence worth recording. With Y = 0.4,
        //   t/D = (P/SE) / (2 x (1 + 0.4 x P/SE))
        // and at P/SE = 0.385 that is 0.385 / 2.308 = 0.1668, which is 1/6 to
        // three decimals. So "t >= D/6" and "P/SE > 0.385" describe the same
        // edge, and the Code states it twice because a designer may arrive
        // from either direction.
        $ratio = 0.385;
        $tOverD = $ratio / (2 * (1 + 0.4 * $ratio));

        self::assertEqualsWithDelta(1 / 6, $tOverD, 0.001);

        // Just inside the edge the calculation still runs.
        $inside = $this->wt()->calculate(52000.0, 168.3, 137900.0, 'FERRITIC_STEEL', 100.0, 0.0);
        self::assertNull($inside['refusal']);
        self::assertLessThan(168.3 / 6, $inside['t']);
    }

    public function test_an_unknown_material_family_is_refused_not_guessed(): void
    {
        $r = $this->wt()->calculate(1960.0, 168.3, 137900.0, 'UNOBTAINIUM', 100.0);

        self::assertSame('COEFFICIENT_Y_NOT_TABULATED', $r['refusal']);
    }

    // -------------------------------------------- the slice declares itself

    public function test_the_pack_says_the_allowable_stresses_are_not_in_it(): void
    {
        // Slice 2 transcribed the equation and left S alone on purpose. The
        // pack says so, so the next reader does not wire a calculation to a
        // number that is not there.
        $pack = json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/fluid-service.ref.json'),
        ), true);

        self::assertStringContainsString(
            'NOT TRANSCRIBED',
            $pack['wallThickness']['symbols']['S'],
        );
        self::assertContains(
            'Table A-1 BASIC ALLOWABLE STRESSES (the S in the equation)',
            $pack['notBuiltInThisSlice']['items'],
        );
        self::assertStringContainsString(
            'NOTHING downstream can tell',
            $pack['notBuiltInThisSlice']['reason'],
        );
    }

    public function test_the_library_keys_on_product_form_and_so_does_table_a1(): void
    {
        // materials.ref.json has cited "ASME B31.3 Table A-1 material
        // groupings" as its source since it was built, and the library is
        // keyed by PRODUCT FORM rather than by metallurgy. That claim is now
        // checked: Table A-1 really is organised that way.
        $pack = json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/fluid-service.ref.json'),
        ), true);

        $forms = array_column($pack['tableA1ProductForms']['forms'], 'form');

        foreach (['Castings', 'Forgings', 'Plate', 'Bar'] as $expected) {
            self::assertContains($expected, $forms);
        }
        self::assertTrue($pack['tableA1ProductForms']['verified']);
    }
}
