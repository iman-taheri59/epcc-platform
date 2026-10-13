<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Reference\ValvePtRatings;
use App\Support\Reference\ValveTesting;
use Tests\TestCase;

/**
 * API Standard 598 (2023) — what has to be witnessed before a valve is accepted.
 *
 * The third leg. B16.34 rates the valve, B16.10 measures it, API 598 tests it.
 * This is the first reference pack in the platform that produces **ITP rows**
 * instead of drawing figures, and the first calculation that needs two
 * purchased standards at once: the shell test pressure is not printed anywhere
 * in API 598, it is 1.5 × the B16.34 rating at 38 °C.
 *
 * No database.
 */
final class ValveTestingTest extends TestCase
{
    private function api598(): ValveTesting
    {
        return app(ValveTesting::class);
    }

    // ------------------------------- the pressure nobody has to look up again

    public function test_the_shell_test_pressure_is_computed_from_b1634(): void
    {
        // Table 2 gives steel valves a rule, not a number: 1.5 x the pressure
        // rating at 38 C, rounded UP to the next whole bar. Group 1.1 is
        // A216 WCB, the commonest valve body there is.
        //
        //   class 150  : 19.6 bar x 1.5 = 29.4  -> 30
        //   class 300  : 51.0 bar x 1.5 = 76.5  -> 77
        //   class 600  : 102.0 bar x 1.5 = 153  -> 153
        //   class 1500 : 255.1 bar x 1.5 = 382.65 -> 383
        $expected = ['150' => 30.0, '300' => 77.0, '600' => 153.0, '1500' => 383.0];

        foreach ($expected as $class => $barg) {
            self::assertSame(
                $barg,
                $this->api598()->shellTestPressureBarG((string) $class, '1.1'),
                "class {$class}",
            );
        }
    }

    public function test_the_rounding_is_upward_and_not_to_nearest(): void
    {
        // Class 300 is 76.5 bar exactly. Rounded to nearest that is 76 or 77
        // depending on the tie rule; API 598 says "the next higher bar", so it
        // is 77 and there is nothing to argue about.
        $rating = app(ValvePtRatings::class)->ratingAt('1.1', '300', 38.0);

        self::assertSame(5100, $rating);
        self::assertSame(76.5, $rating / 100 * 1.5);
        self::assertSame(77.0, $this->api598()->shellTestPressureBarG('300', '1.1'));
    }

    public function test_without_a_material_group_there_is_no_shell_pressure(): void
    {
        // The pressure depends on the RATING, and the rating depends on the
        // material group. A figure derived from the class alone would be right
        // for group 1.1 and wrong for the other forty-nine.
        self::assertNull($this->api598()->shellTestPressureBarG('600', null));
    }

    public function test_the_backseat_pressure_is_110_percent_of_the_rating(): void
    {
        $tests = $this->api598()->testsFor('GATE', 150, '600', null, 'METAL', '1.1');
        $backseat = $this->pick($tests, ValveTesting::BACKSEAT);

        self::assertNotNull($backseat);
        self::assertSame(112.2, $backseat['pressureBarG']);   // 102 bar x 1.1
    }

    // ------------------------------------------------ two axes the PMS lacks

    public function test_a_ball_valve_cannot_be_tested_until_it_says_which_kind(): void
    {
        // API 598 tests a FLOATING ball differently from a TRUNNION MOUNTED
        // one. A PMS row says "BALL" and stops. Picking one silently would put
        // the wrong test on the ITP, so the pack refuses and names the choice.
        self::assertTrue($this->api598()->requiresVariant('BALL'));
        self::assertSame(
            ['FLOATING', 'TRUNNION_MOUNTED'],
            $this->api598()->variantsFor('BALL'),
        );
        self::assertSame('VARIANT_REQUIRED', $this->api598()->refusalFor('BALL'));
        self::assertSame([], $this->api598()->testsFor('BALL', 150, '600'));

        self::assertNotSame([], $this->api598()->testsFor('BALL', 150, '600', 'FLOATING'));
    }

    public function test_a_butterfly_needs_its_api_609_category(): void
    {
        self::assertTrue($this->api598()->requiresVariant('BUTTERFLY'));
        self::assertSame(['CATEGORY_A', 'CATEGORY_B'], $this->api598()->variantsFor('BUTTERFLY'));
    }

    public function test_a_gate_valve_has_a_variant_but_does_not_require_one(): void
    {
        // "Parallel slide gate" is an extra route into the GLOBE column, not a
        // missing answer: a plain gate valve has a column of its own. Demanding
        // a variant here would nag about every gate valve in the project.
        self::assertFalse($this->api598()->requiresVariant('GATE'));
        self::assertSame(['PARALLEL_SLIDE'], $this->api598()->variantsFor('GATE'));
        self::assertNull($this->api598()->refusalFor('GATE'));
    }

    public function test_a_valve_type_api598_does_not_cover_says_so(): void
    {
        self::assertSame(
            'VALVE_TYPE_NOT_IN_API598',
            $this->api598()->refusalFor('NEEDLE'),
        );
    }

    // -------------------------------------------------------- Table 1 itself

    public function test_the_shell_test_is_required_on_every_valve(): void
    {
        foreach ([['GATE', null], ['GLOBE', null], ['PLUG', null], ['CHECK', null],
                  ['BALL', 'FLOATING'], ['BALL', 'TRUNNION_MOUNTED'],
                  ['BUTTERFLY', 'CATEGORY_A'], ['BUTTERFLY', 'CATEGORY_B']] as [$type, $variant]) {
            $shell = $this->pick(
                $this->api598()->testsFor($type, 150, '600', $variant, 'METAL', '1.1'),
                ValveTesting::SHELL,
            );
            self::assertNotNull($shell, "{$type} has no shell test");
            self::assertSame('REQUIRED', $shell['requirement']);
        }
    }

    public function test_the_closure_tests_invert_as_the_class_rises(): void
    {
        // The structure of Table 1: where the low-pressure closure test becomes
        // optional, the high-pressure one becomes required. For a 6 inch gate
        // valve the switch is at Class 600.
        $at600 = $this->api598()->testsFor('GATE', 150, '600', null, 'METAL', '1.1');
        self::assertSame('REQUIRED', $this->pick($at600, ValveTesting::LOW_PRESSURE_CLOSURE)['requirement']);
        self::assertSame('OPTIONAL', $this->pick($at600, ValveTesting::HIGH_PRESSURE_CLOSURE)['requirement']);

        $at900 = $this->api598()->testsFor('GATE', 150, '900', null, 'METAL', '1.1');
        self::assertSame('OPTIONAL', $this->pick($at900, ValveTesting::LOW_PRESSURE_CLOSURE)['requirement']);
        self::assertSame('REQUIRED', $this->pick($at900, ValveTesting::HIGH_PRESSURE_CLOSURE)['requirement']);
    }

    public function test_the_class_threshold_moves_with_the_size(): void
    {
        // THE TRAP IN TABLE 1. The closure rows split at Class 1500 for valves
        // DN 100 and below, and at Class 600 for larger ones. A Class 900 valve
        // is therefore on opposite sides of the line at NPS 4 and at NPS 6, and
        // reading it as one threshold mis-states every large valve between 600
        // and 1500.
        $small = $this->api598()->testsFor('GATE', 100, '900', null, 'METAL', '1.1');
        $large = $this->api598()->testsFor('GATE', 150, '900', null, 'METAL', '1.1');

        self::assertSame('REQUIRED', $this->pick($small, ValveTesting::LOW_PRESSURE_CLOSURE)['requirement']);
        self::assertSame('OPTIONAL', $this->pick($large, ValveTesting::LOW_PRESSURE_CLOSURE)['requirement']);
    }

    public function test_a_check_valve_has_no_backseat(): void
    {
        // It has no stem, so there is nothing to backseat. The pack says
        // NOT_APPLICABLE and the test simply does not appear on the ITP.
        $tests = $this->api598()->testsFor('CHECK', 150, '600', null, 'METAL', '1.1');

        self::assertNull($this->pick($tests, ValveTesting::BACKSEAT));
        self::assertNotNull($this->pick($tests, ValveTesting::SHELL));
    }

    public function test_a_required_test_carries_the_footnote_that_qualifies_it(): void
    {
        // Note (d): a gear-actuated globe valve is closure-tested at 110 % of
        // the DESIGN DIFFERENTIAL used to size the operator, not of the rating.
        // The footnote has to travel with the row or the test is set up wrong.
        $tests = $this->api598()->testsFor('GLOBE', 150, '600', null, 'METAL', '1.1');
        $hp = $this->pick($tests, ValveTesting::HIGH_PRESSURE_CLOSURE);

        self::assertSame('REQUIRED', $hp['requirement']);
        self::assertContains('d', $hp['noteRefs']);
        self::assertTrue((bool) preg_grep('/design differential/i', $hp['notes']));
    }

    // ------------------------------------------------- duration and leakage

    public function test_duration_comes_from_the_size_and_the_valve_kind(): void
    {
        // Table 4. A check valve holds a closure test longer than anything else
        // at the small sizes: 60 seconds against 15.
        self::assertSame(15, $this->api598()->durationSeconds(ValveTesting::SHELL, 50));
        self::assertSame(60, $this->api598()->durationSeconds(ValveTesting::SHELL, 150));
        self::assertSame(300, $this->api598()->durationSeconds(ValveTesting::SHELL, 600));
        self::assertSame(600, $this->api598()->durationSeconds(ValveTesting::SHELL, 900));

        self::assertSame(15, $this->api598()->durationSeconds(ValveTesting::LOW_PRESSURE_CLOSURE, 50, 'GATE'));
        self::assertSame(60, $this->api598()->durationSeconds(ValveTesting::LOW_PRESSURE_CLOSURE, 50, 'CHECK'));
    }

    public function test_a_resilient_seat_allows_nothing_at_any_size(): void
    {
        // Not a rate that grows with the valve. A resilient seat is defined by
        // sealing completely, so the figure is zero at NPS 2 and at NPS 48.
        foreach ([50, 150, 600, 1200] as $dn) {
            $leak = $this->api598()->leakageFor($dn, 'BALL', 'RESILIENT');
            self::assertSame(0.0, $leak['dropsPerMin'], "DN {$dn}");
            self::assertSame(0.0, $leak['bubblesPerMin'], "DN {$dn}");
        }
    }

    public function test_metal_seat_leakage_is_proportional_to_size(): void
    {
        // Table 5 is 2 drops per minute per inch of NPS. NPS 6 is 12 drops,
        // NPS 24 is 48 - which is the formula the standard uses above NPS 48
        // stated as a table below it.
        self::assertSame(12.0, $this->api598()->leakageFor(150)['dropsPerMin']);
        self::assertSame(48.0, $this->api598()->leakageFor(600)['dropsPerMin']);
    }

    public function test_above_the_table_the_formulas_take_over(): void
    {
        // Table 5 stops at DN 1200 (NPS 48) and gives formulas beyond it.
        // Extrapolating the table instead would be a different, wrong answer.
        $big = $this->api598()->leakageFor(1500, 'GATE');

        self::assertSame('FORMULA', $big['basis']);
        self::assertSame(120.0, $big['dropsPerMin']);     // 2 x NPS 60
        self::assertSame(240.0, $big['bubblesPerMin']);   // 4 x NPS 60

        $check = $this->api598()->leakageFor(1500, 'CHECK');
        self::assertSame(180.0, $check['liquidMlPerMin']); // 3 x NPS 60
    }

    public function test_a_check_valve_is_allowed_more_leakage_than_a_gate(): void
    {
        // Different columns of Table 5 entirely - a check valve is measured in
        // mL/min and a gate in drops/min, and reading across the wrong pair is
        // an order-of-magnitude error on an acceptance criterion.
        $gate = $this->api598()->leakageFor(150, 'GATE');
        $check = $this->api598()->leakageFor(150, 'CHECK');

        self::assertArrayHasKey('dropsPerMin', $gate);
        self::assertArrayNotHasKey('dropsPerMin', $check);
        self::assertSame(18.0, $check['liquidMlPerMin']);
        self::assertGreaterThan($gate['liquidMlPerMin'], $check['liquidMlPerMin']);
    }

    /**
     * @param  list<array<string,mixed>>  $tests
     * @return array<string,mixed>|null
     */
    private function pick(array $tests, string $name): ?array
    {
        foreach ($tests as $t) {
            if ($t['test'] === $name) {
                return $t;
            }
        }

        return null;
    }
}
