<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Reference\ValveFaceToFace;
use App\Support\Reference\ValvePtRatings;
use Tests\TestCase;

/**
 * ASME B16.10-2022 — how long a valve is.
 *
 * A rating is not a drawing. B16.34 says a Class 600 gate valve in A216 WCB
 * holds 102 bar at 38 C; B16.10 says it is 559 mm long, and the second number
 * is the one that closes an isometric and sets the cut length of the pipe
 * either side of it.
 *
 * No database — a reference pack read directly.
 */
final class ValveFaceToFaceTest extends TestCase
{
    private function f2f(): ValveFaceToFace
    {
        return app(ValveFaceToFace::class);
    }

    // ------------------------------------------------- the figures themselves

    public function test_known_lengths_match_the_printed_tables(): void
    {
        // Spot checks across five tables. Every one of these is a number a
        // piping engineer knows by heart, which is exactly why they make good
        // canaries for a column map read off five lines of centred text.
        $cases = [
            ['GATE', '150', '6', 'RF', null, 267.0],    // Table 1.3-1 col 9
            ['GLOBE', '300', '6', 'RF', null, 444.0],   // Table 1.3-2 col 15
            ['GATE', '600', '6', 'RF', 'LONG', 559.0],  // Table 1.5-1 col 2
            ['GATE', '1500', '6', 'RF', 'LONG', 705.0], // Table 1.5-3 col 1
            ['GATE', '2500', '6', 'RF', 'LONG', 914.0], // Table 1.5-4 col 1
            ['BALL', '150', '6', 'RF', 'LONG', 394.0],  // Table 1.3-1 col 20
        ];

        foreach ($cases as [$type, $class, $nps, $end, $pattern, $mm]) {
            self::assertSame(
                $mm,
                $this->f2f()->lengthFor($type, $class, $nps, $end, $pattern),
                "{$type} class {$class} NPS {$nps}",
            );
        }
    }

    public function test_one_column_can_serve_several_valve_types(): void
    {
        // B16.10 groups valves that share a length: "Globe, Lift Check, and
        // Swing Check" is one column. In Class 600 at NPS 6 all three are the
        // same length, and that is the standard saying so, not a coincidence
        // this pack has flattened.
        $globe = $this->f2f()->lengthFor('GLOBE', '600', '6', 'RF', 'LONG');
        $lift = $this->f2f()->lengthFor('LIFT_CHECK', '600', '6', 'RF', 'LONG', 'A');
        $swing = $this->f2f()->lengthFor('SWING_CHECK', '600', '6', 'RF', 'LONG');

        self::assertSame(559.0, $globe);
        self::assertSame($globe, $lift);
        self::assertSame($globe, $swing);
    }

    public function test_a_lift_check_is_two_different_valves_and_must_say_which(): void
    {
        // The most useful thing B16.10 revealed about this platform's model.
        // A lift check appears TWICE in Table 1.5-1: with the globes as an
        // in-line body measured face-to-face (symbol A, 559 mm), and with the
        // angle valves measured centre-to-face (symbol D, 279 mm). Same valve
        // type, same class, same size, two bodies, and 280 mm between them.
        self::assertNull($this->f2f()->lengthFor('LIFT_CHECK', '600', '6', 'RF', 'LONG'));
        self::assertSame(
            'SEVERAL_BODY_STYLES_OFFERED',
            $this->f2f()->refusalFor('LIFT_CHECK', '600', '6', 'RF', 'LONG'),
        );

        self::assertSame(559.0, $this->f2f()->lengthFor('LIFT_CHECK', '600', '6', 'RF', 'LONG', 'A'));
        self::assertSame(279.0, $this->f2f()->lengthFor('LIFT_CHECK', '600', '6', 'RF', 'LONG', 'D'));
    }

    public function test_a_valve_gets_longer_with_class_at_a_fixed_size(): void
    {
        $previous = 0.0;
        foreach (['150', '300', '600', '900', '1500', '2500'] as $class) {
            $mm = $this->f2f()->lengthFor('GATE', $class, '6', 'RF', 'LONG')
                ?? $this->f2f()->lengthFor('GATE', $class, '6', 'RF');
            self::assertNotNull($mm, "no class {$class} gate length at NPS 6");
            self::assertGreaterThan($previous, $mm, "class {$class} is not longer");
            $previous = $mm;
        }
    }

    // ------------------------------------------------------ what it refuses

    public function test_a_rated_class_can_still_have_no_length(): void
    {
        // Class 4500 is rated by B16.34 and dimensioned by nobody. Holding two
        // packs makes that visible; holding one would have hidden it.
        self::assertContains('4500', app(ValvePtRatings::class)->pressureClasses());
        self::assertNotContains('4500', $this->f2f()->pressureClasses());

        self::assertNull($this->f2f()->lengthFor('GATE', '4500', '6'));
        self::assertSame(
            'CLASS_HAS_NO_TABULATED_LENGTH',
            $this->f2f()->refusalFor('GATE', '4500', '6'),
        );
    }

    public function test_two_patterns_are_a_refusal_and_not_a_coin_toss(): void
    {
        // A Class 600 gate valve is 559 mm in long pattern and 457 mm in short.
        // Returning the first would silently pick a pattern nobody chose, and
        // the spool either side would be 102 mm wrong.
        self::assertNull($this->f2f()->lengthFor('GATE', '600', '6', 'BW'));
        self::assertSame(
            'SEVERAL_PATTERNS_OFFERED',
            $this->f2f()->refusalFor('GATE', '600', '6', 'BW'),
        );

        self::assertSame(559.0, $this->f2f()->lengthFor('GATE', '600', '6', 'BW', 'LONG'));
        self::assertSame(457.0, $this->f2f()->lengthFor('GATE', '600', '6', 'BW', 'SHORT'));
    }

    public function test_a_valve_type_outside_the_standard_says_so(): void
    {
        self::assertSame(
            'VALVE_TYPE_NOT_IN_B1610',
            $this->f2f()->refusalFor('NEEDLE', '600', '6'),
        );
    }

    public function test_a_size_the_standard_does_not_reach_says_so(): void
    {
        self::assertSame(
            'NOT_OFFERED_AT_THIS_SIZE_OR_END',
            $this->f2f()->refusalFor('GATE', '600', '40'),
        );
    }

    public function test_cast_iron_is_not_in_the_pack_at_all(): void
    {
        // Class 125 and 250 cast iron columns were read and discarded. B16.34
        // does not rate cast iron and the material library holds no cast-iron
        // valve body, so a length for one could never be paired with anything.
        self::assertNotContains('125', $this->f2f()->pressureClasses());
        self::assertNotContains('250', $this->f2f()->pressureClasses());
    }

    // ----------------------------------------- the facing changes the length

    public function test_a_ring_joint_valve_is_not_the_length_in_the_table(): void
    {
        // Table 3.2-1 note (5): add X from Table 3.3-1 to the raised-face
        // figure. Class 900 NPS 6 is X = 3 mm, so the RTJ valve is 3 mm longer
        // than the table says. Small, and wrong is wrong.
        $rf = $this->f2f()->lengthFor('GATE', '900', '6', 'RF', 'LONG');
        $x = $this->f2f()->ringJointAdjustment('900', '6')['X'];

        self::assertNotNull($rf);
        self::assertSame(3.0, $x);
        self::assertSame($rf + 3.0, $this->f2f()->ringJointLengthFor('GATE', '900', '6', 'LONG'));
    }

    public function test_a_tongue_face_adds_and_a_groove_face_subtracts(): void
    {
        // The sign matters and it flips with the class: +13 mm for a tongue
        // face in Class 150, -3 mm for a groove face in Class 600.
        self::assertSame(13.0, $this->f2f()->facingAdjustmentMm('150', 'TONGUE_FACE'));
        self::assertSame(10.0, $this->f2f()->facingAdjustmentMm('300', 'GROOVE_FACE'));
        self::assertSame(-3.0, $this->f2f()->facingAdjustmentMm('600', 'GROOVE_FACE'));
    }

    public function test_ring_joint_is_not_a_single_adjustment_number(): void
    {
        // It varies by size, so the facing table cannot hold it and returns
        // null rather than a plausible constant.
        self::assertNull($this->f2f()->facingAdjustmentMm('600', 'RING_JOINT'));
    }

    // ------------------------------------------------- declared irregularity

    public function test_the_places_where_a_valve_shortens_as_it_grows_are_declared(): void
    {
        // Sixteen of them, and none is a transcription fault. Table 1.3-1
        // column 9 takes its large sizes from API 6D (note 14), so that column
        // is two standards spliced together; Class 900 valves NPS 2 1/2 and
        // smaller carry CLASS 1500 dimensions by note (2) of Table 1.5-2.
        $declared = $this->f2f()->anomalies()['lengthFallsWithSize'] ?? [];

        self::assertNotSame([], $declared);
        foreach ($declared as $d) {
            self::assertLessThan($d['fromMm'], $d['toMm']);
            self::assertArrayHasKey('sourceTable', $d);
        }
    }

    public function test_the_printed_unit_pairs_that_do_not_convert_are_declared(): void
    {
        // Three cells where the millimetre and inch columns disagree by more
        // than rounding - B16.10 para. 1.2 makes each unit system standalone,
        // so neither figure corrects the other and both are kept as printed.
        $declared = $this->f2f()->anomalies()['unitPairsThatDoNotConvert'] ?? [];

        self::assertCount(3, $declared);
        foreach ($declared as $d) {
            self::assertGreaterThan(1.0, abs($d['printedMm'] - $d['printedIn'] * 25.4));
        }
    }

    public function test_api_6d_confirms_the_figures_b1610_says_it_borrowed(): void
    {
        // B16.10 Table 1.3-1 note (14): its Class 150 gate figures for the
        // large sizes are "extracted from API 6D". Read from the other side,
        // they are - 17 of 19 sizes match to the millimetre, and the two that
        // do not are the same inch figure rounded differently (7.50 in is
        // 190.5 mm; one standard rounds up, the other down).
        $c = $this->f2f()->corroboration();

        self::assertSame(19, $c['sizesCompared']);
        self::assertSame(17, $c['sizesAgreeingExactly']);
        self::assertCount(2, $c['differences']);

        foreach ($c['differences'] as $d) {
            self::assertSame(1, abs($d['api6dMm'] - $d['b1610Mm']));
            self::assertArrayHasKey('bothPrintInches', $d);
        }
    }

    public function test_api_6d_explains_the_monotonicity_anomaly_we_declared(): void
    {
        // THE DECLARED ANOMALY, SETTLED. The B16.10 round recorded that Table
        // 1.3-1 column 9 shortens from 762 mm at NPS 34 to 711 mm at NPS 36
        // and attributed it to note (14). API 6D gives 813 mm at NPS 36 and
        // 660 mm at NPS 30 - and our B16.10 data holds BOTH values at each of
        // those sizes. The larger is the API 6D figure; the smaller is the
        // THROUGH-CONDUIT valve note (14) sends elsewhere. The column was
        // never non-monotonic: it was two valve constructions in one place.
        $lengths = static fn (string $nps): array => array_values(array_unique(
            array_column(
                app(ValveFaceToFace::class)->lengthsFor('GATE', '150', $nps, 'RF'),
                'mm',
            ),
        ));

        self::assertEqualsCanonicalizing([610.0, 660.0], $lengths('30'));
        self::assertEqualsCanonicalizing([711.0, 813.0], $lengths('36'));

        $c = $this->f2f()->corroboration();
        self::assertStringContainsString('THROUGH-CONDUIT', $c['explainsADeclaredAnomaly']);
        self::assertStringContainsString(
            'never non-monotonic',
            $c['explainsADeclaredAnomaly'],
        );
    }

    public function test_a_size_is_recognised_however_it_is_written(): void
    {
        foreach (['1 1/2', '1-1/2', '1.5', 'NPS 1 1/2'] as $written) {
            self::assertSame(
                241.0,
                $this->f2f()->lengthFor('GATE', '600', $written, 'RF', 'LONG'),
                $written,
            );
        }
    }
}
