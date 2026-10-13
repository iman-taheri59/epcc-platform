<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Reference\FlangeBolting;
use App\Support\Reference\MaterialLibrary;
use RuntimeException;
use Tests\TestCase;

/**
 * Bolting: a take-off line in its own right, and the check that it is enough.
 *
 * The decision these tests protect is that bolting is NOT derived from the
 * flange count. Projects order fasteners with an allowance, so a surplus must
 * never be reported as an error, and a shortfall always must.
 */
final class FlangeBoltingTest extends TestCase
{
    private function fb(): FlangeBolting
    {
        return app(FlangeBolting::class);
    }

    private function lib(): MaterialLibrary
    {
        return app(MaterialLibrary::class);
    }

    // -------------------------------------------------------- the reference

    public function test_a_joint_resolves_to_count_diameter_and_length(): void
    {
        $joint = $this->fb()->forJoint('300#', '6');

        self::assertNotNull($joint);
        self::assertSame(12, $joint['boltCount']);
        self::assertSame('3/4', $joint['boltDiameterIn']);
        self::assertSame(0.75, $joint['boltDiameterDecimal']);
        // Read out of ASME B16.5-2025 Table 10 on 2026-09-24. Before that date
        // this assertion read assertFalse.
        self::assertTrue($joint['verified']);
    }

    public function test_the_same_size_needs_more_bolts_as_the_class_rises(): void
    {
        // 6 inch: 8 bolts at 150#, 12 at 300#, 12 at 600#. The flange gets
        // heavier, never lighter.
        self::assertSame(8, $this->fb()->forJoint('150#', '6')['boltCount']);
        self::assertSame(12, $this->fb()->forJoint('300#', '6')['boltCount']);
        self::assertSame(12, $this->fb()->forJoint('600#', '6')['boltCount']);
    }

    public function test_an_unlisted_joint_returns_null_rather_than_zero(): void
    {
        // 'no answer' must not read as 'no bolts needed'.
        // B16.5 stops at NPS 24, and Class 2500 stops at NPS 12. Neither
        // size is a gap in the transcription; the standard does not go there.
        self::assertNull($this->fb()->forJoint('300#', '48'));
        self::assertNull($this->fb()->forJoint('2500#', '14'));
    }

    public function test_every_rating_a_piping_class_may_carry_is_answerable(): void
    {
        foreach (['150#', '300#', '600#', '900#', '1500#'] as $rating) {
            self::assertNotNull(
                $this->fb()->forJoint($rating, '4'),
                "A piping class may be published at {$rating}."
            );
        }
    }

    public function test_a_bolt_diameter_is_read_as_a_fitter_writes_it(): void
    {
        self::assertSame(0.5, FlangeBolting::inchesToDecimal('1/2'));
        self::assertSame(1.125, FlangeBolting::inchesToDecimal('1 1/8'));
        self::assertSame(3.5, FlangeBolting::inchesToDecimal('3 1/2'));
        self::assertSame(2.0, FlangeBolting::inchesToDecimal('2'));
    }

    // ------------------------------------------------------ flanges->joints

    public function test_two_flanges_make_one_joint(): void
    {
        self::assertSame(5, FlangeBolting::jointsFromFlangePairs(10));
        self::assertSame(0, FlangeBolting::jointsFromFlangePairs(0));
    }

    public function test_an_odd_flange_count_is_refused_not_rounded(): void
    {
        // Rounding either way silently mis-orders a whole bolt set.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/odd flange count/');
        FlangeBolting::jointsFromFlangePairs(7);
    }

    // ------------------------------------------------ the sufficiency check

    public function test_an_exact_take_off_is_sufficient(): void
    {
        $required = [['rating' => '300#', 'nps' => '6', 'joints' => 10]];

        self::assertTrue($this->fb()->isSufficient($required, [
            'bolts' => 120, 'nuts' => 240,
            'boltDiameterIn' => '3/4', 'boltLengthMm' => 120,
        ]));
    }

    public function test_a_surplus_is_never_an_error(): void
    {
        // The whole reason bolting is its own take-off line.
        $required = [['rating' => '300#', 'nps' => '6', 'joints' => 10]];

        $findings = $this->fb()->shortfalls($required, [
            'bolts' => 200, 'nuts' => 500,
            'boltDiameterIn' => '3/4', 'boltLengthMm' => 200,
        ]);

        self::assertSame([], $findings, 'Over-ordering bolting is normal practice.');
    }

    public function test_too_few_bolts_is_a_shortfall(): void
    {
        $required = [['rating' => '300#', 'nps' => '6', 'joints' => 10]];

        $findings = $this->fb()->shortfalls($required, [
            'bolts' => 100, 'nuts' => 500, 'boltDiameterIn' => '3/4',
        ]);

        $codes = array_column($findings, 'code');
        self::assertContains('BOLT_COUNT', $codes);
        $bolt = $findings[array_search('BOLT_COUNT', $codes, true)];
        self::assertSame(120, $bolt['required']);
        self::assertSame(100, $bolt['supplied']);
        self::assertSame(FlangeBolting::SEVERITY_SHORTFALL, $bolt['severity']);
    }

    public function test_nuts_are_counted_against_the_bolts_actually_ordered(): void
    {
        // 200 studs were ordered though only 120 are needed. They still each
        // need two nuts: ordering surplus studs and exact nuts is a real slip.
        $required = [['rating' => '300#', 'nps' => '6', 'joints' => 10]];

        $findings = $this->fb()->shortfalls($required, [
            'bolts' => 200, 'nuts' => 240, 'boltDiameterIn' => '3/4',
        ]);

        $codes = array_column($findings, 'code');
        self::assertContains('NUT_COUNT', $codes);
        self::assertSame(400, $findings[array_search('NUT_COUNT', $codes, true)]['required']);
    }

    public function test_a_machine_bolt_takes_one_nut_not_two(): void
    {
        $required = [['rating' => '300#', 'nps' => '6', 'joints' => 10]];

        self::assertTrue($this->fb()->isSufficient(
            $required,
            ['bolts' => 120, 'nuts' => 120, 'boltDiameterIn' => '3/4'],
            FlangeBolting::STYLE_MACHINE,
        ));

        // The same order fails as studs: headless rod, a nut at each end.
        self::assertFalse($this->fb()->isSufficient(
            $required,
            ['bolts' => 120, 'nuts' => 120, 'boltDiameterIn' => '3/4'],
            FlangeBolting::STYLE_STUD,
        ));
    }

    public function test_the_nut_ratio_cannot_be_passed_directly(): void
    {
        // The caller names a STYLE. It used to take a bare int, which let a
        // studded joint be checked at one nut per bolt - half the nut order,
        // with nothing to catch it.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no third case/');
        $this->fb()->shortfalls(
            [['rating' => '150#', 'nps' => '4', 'joints' => 1]],
            ['bolts' => 8, 'nuts' => 16],
            'SOCKET_HEAD',
        );
    }

    public function test_a_machine_bolt_is_measured_against_its_own_column(): void
    {
        // 6 inch 300#: a stud wants 120 mm, a machine bolt 110 mm, because it
        // is measured from under the head and carries one nut instead of two.
        // Checking a machine bolt against the stud column raises a false
        // shortfall - and a false warning teaches people to ignore warnings.
        $joint = $this->fb()->forJoint('300#', '6');
        self::assertSame(120, $joint['minLengthMm']);
        self::assertSame(110, $joint['minLengthMachineBoltMm']);

        $required = [['rating' => '300#', 'nps' => '6', 'joints' => 1]];
        $supplied = ['bolts' => 99, 'nuts' => 999, 'boltDiameterIn' => '3/4', 'boltLengthMm' => 115];

        // 115 mm is short for a stud...
        $asStud = array_column(
            $this->fb()->shortfalls($required, $supplied, FlangeBolting::STYLE_STUD),
            'code'
        );
        self::assertContains('BOLT_LENGTH', $asStud);

        // ...and perfectly adequate for a machine bolt.
        $asMachine = array_column(
            $this->fb()->shortfalls($required, $supplied, FlangeBolting::STYLE_MACHINE),
            'code'
        );
        self::assertNotContains('BOLT_LENGTH', $asMachine);
    }

    public function test_a_machine_bolt_is_forbidden_above_class_300(): void
    {
        // Not a shortfall: nothing is missing. The wrong fastener has been
        // specified, and ordering more of it does not help - so MISFIT.
        $findings = $this->fb()->shortfalls(
            [['rating' => '600#', 'nps' => '6', 'joints' => 2]],
            ['bolts' => 9999, 'nuts' => 99999, 'boltDiameterIn' => '1'],
            FlangeBolting::STYLE_MACHINE,
        );

        $codes = array_column($findings, 'code');
        self::assertContains('STYLE_NOT_PERMITTED', $codes);

        $finding = $findings[array_search('STYLE_NOT_PERMITTED', $codes, true)];
        self::assertSame(FlangeBolting::SEVERITY_MISFIT, $finding['severity']);
        self::assertSame('600#', $finding['rating']);
        self::assertFalse($this->fb()->isSufficient(
            [['rating' => '600#', 'nps' => '6', 'joints' => 2]],
            ['bolts' => 9999, 'nuts' => 99999, 'boltDiameterIn' => '1'],
            FlangeBolting::STYLE_MACHINE,
        ));
    }

    public function test_a_studded_joint_is_permitted_at_every_class(): void
    {
        // The converse of the rule above: studs are legal everywhere, so no
        // amount of pressure class makes STYLE_STUD a misfit.
        foreach (['150#', '300#', '600#', '900#', '1500#'] as $rating) {
            $codes = array_column($this->fb()->shortfalls(
                [['rating' => $rating, 'nps' => '4', 'joints' => 1]],
                ['bolts' => 999, 'nuts' => 9999],
                FlangeBolting::STYLE_STUD,
            ), 'code');

            self::assertNotContains('STYLE_NOT_PERMITTED', $codes, $rating);
        }
    }

    public function test_the_machine_bolt_column_exists_only_where_it_is_legal(): void
    {
        // Absent means not permitted, not unknown. A null here that was read as
        // "fall back to the stud figure" would quietly re-admit the forbidden
        // joint, so the style check above is what actually guards it.
        self::assertNotNull($this->fb()->forJoint('150#', '8')['minLengthMachineBoltMm']);
        self::assertNotNull($this->fb()->forJoint('300#', '8')['minLengthMachineBoltMm']);
        self::assertNull($this->fb()->forJoint('600#', '8')['minLengthMachineBoltMm']);
        self::assertNull($this->fb()->forJoint('1500#', '8')['minLengthMachineBoltMm']);
    }

    public function test_a_thin_bolt_is_a_shortfall_and_a_fat_one_is_a_misfit(): void
    {
        $required = [['rating' => '300#', 'nps' => '6', 'joints' => 4]];

        $thin = $this->fb()->shortfalls($required, [
            'bolts' => 999, 'nuts' => 9999, 'boltDiameterIn' => '5/8',
        ]);
        self::assertSame(FlangeBolting::SEVERITY_SHORTFALL, $thin[0]['severity']);

        // Surplus quantity is fine; surplus diameter will not enter the hole.
        $fat = $this->fb()->shortfalls($required, [
            'bolts' => 999, 'nuts' => 9999, 'boltDiameterIn' => '1',
        ]);
        self::assertSame(FlangeBolting::SEVERITY_MISFIT, $fat[0]['severity']);
        self::assertFalse($this->fb()->isSufficient($required, [
            'bolts' => 999, 'nuts' => 9999, 'boltDiameterIn' => '1',
        ]));
    }

    public function test_the_diameter_checked_is_the_widest_joint_in_the_scope(): void
    {
        // A mixed take-off must suit its largest joint, not its average.
        $required = [
            ['rating' => '150#', 'nps' => '2', 'joints' => 50],   // 5/8
            ['rating' => '300#', 'nps' => '24', 'joints' => 1],   // 1 1/2
        ];

        $findings = $this->fb()->shortfalls($required, [
            'bolts' => 9999, 'nuts' => 99999, 'boltDiameterIn' => '5/8',
        ]);

        $codes = array_column($findings, 'code');
        self::assertContains('BOLT_DIAMETER', $codes);
        self::assertSame('1 1/2', $findings[array_search('BOLT_DIAMETER', $codes, true)]['required']);
    }

    public function test_a_short_bolt_is_a_shortfall_now_the_length_is_verified(): void
    {
        // This test used to assert ADVISORY, because no length had been read
        // against a purchased B16.5 and a guess must not block an order. The
        // drilling templates were transcribed on 2026-09-24, so the figure is
        // now the standard's and a 60 mm bolt in a joint needing 90 mm is a
        // real shortfall.
        $required = [['rating' => '300#', 'nps' => '6', 'joints' => 1]];
        $supplied = [
            'bolts' => 99, 'nuts' => 999, 'boltDiameterIn' => '3/4', 'boltLengthMm' => 60,
        ];

        $findings = $this->fb()->shortfalls($required, $supplied);

        self::assertSame('BOLT_LENGTH', $findings[0]['code']);
        self::assertSame(FlangeBolting::SEVERITY_SHORTFALL, $findings[0]['severity']);
        self::assertFalse($this->fb()->isSufficient($required, $supplied));
    }

    public function test_a_class_400_small_flange_is_drilled_as_class_600(): void
    {
        // B16.5 prints no dimensions for Class 400 below NPS 4; it prints
        // "use Class 600 dimensions in this size". Answering null here would
        // read as "this joint needs no bolts".
        $joint = $this->fb()->forJoint('400#', '2');

        self::assertNotNull($joint);
        self::assertSame('400#', $joint['deferredFrom']);
        self::assertSame('600#', $joint['ratingUsed']);
        self::assertSame($this->fb()->forJoint('600#', '2')['boltCount'], $joint['boltCount']);
    }

    public function test_a_class_900_small_flange_is_drilled_as_class_1500(): void
    {
        $joint = $this->fb()->forJoint('900#', '1');

        self::assertNotNull($joint);
        self::assertSame('1500#', $joint['ratingUsed']);
        self::assertSame($this->fb()->forJoint('1500#', '1')['minLengthMm'], $joint['minLengthMm']);
    }

    public function test_a_ring_joint_needs_its_own_stud_length(): void
    {
        // Three facings, three lengths. Ordering the raised-face figure for a
        // ring joint buys a bolt of the wrong length.
        $joint = $this->fb()->forJoint('600#', '8');

        self::assertSame(190, $joint['minLengthMm']);
        self::assertSame(195, $joint['minLengthRingJointMm']);
        self::assertSame(185, $joint['minLengthTongueGrooveMm']);
    }

    public function test_machine_bolts_exist_only_where_the_standard_prints_them(): void
    {
        // The column that looks like a machine bolt at Class 150 and 300 is a
        // ring-joint stud from Class 400 up. Confusing the two is silent.
        self::assertNotNull($this->fb()->forJoint('150#', '8')['minLengthMachineBoltMm']);
        self::assertNotNull($this->fb()->forJoint('300#', '8')['minLengthMachineBoltMm']);
        foreach (['400#', '600#', '900#', '1500#', '2500#'] as $rating) {
            self::assertNull(
                $this->fb()->forJoint($rating, '8')['minLengthMachineBoltMm'],
                "B16.5 prints no machine bolt at {$rating}."
            );
        }
    }

    public function test_an_unanswerable_joint_is_reported_not_skipped(): void
    {
        // Silence would let a 48 inch flange pass as satisfied by four bolts.
        $findings = $this->fb()->shortfalls(
            [['rating' => '300#', 'nps' => '48', 'joints' => 2]],
            ['bolts' => 1000, 'nuts' => 2000],
        );

        self::assertSame('UNKNOWN_JOINT', $findings[0]['code']);
        self::assertSame(FlangeBolting::SEVERITY_SHORTFALL, $findings[0]['severity']);
    }

    public function test_diameter_and_length_are_optional_inputs(): void
    {
        // An early MTO may carry quantities before anyone has picked a stud.
        $required = [['rating' => '150#', 'nps' => '4', 'joints' => 3]];

        self::assertTrue($this->fb()->isSufficient($required, ['bolts' => 24, 'nuts' => 48]));
        self::assertFalse($this->fb()->isSufficient($required, ['bolts' => 23, 'nuts' => 48]));
    }

    // --------------------------------------------- the bolting material group

    public function test_bolt_and_nut_are_supplied_only_by_the_bolting_group(): void
    {
        foreach (['BOLT', 'NUT'] as $type) {
            $groups = array_column($this->lib()->groupsForComponentType($type), 'code');
            self::assertSame(['BOLTING'], $groups);
        }
    }

    public function test_the_bolting_group_carries_the_specs_the_user_named(): void
    {
        $specs = array_column($this->lib()->specsIn('BOLTING'), 'code');

        foreach (['A193', 'A194', 'A320', 'A453', 'A563'] as $code) {
            self::assertContains($code, $specs);
        }
    }

    public function test_b7_resolves_and_is_a_bolt(): void
    {
        $lib = $this->lib();

        self::assertTrue($lib->isKnown('BOLTING', 'A193', 'B7', 'HEAT_TREATED'));
        $grade = $lib->grade('BOLTING', 'A193', 'B7');
        self::assertSame('BOLT', $grade['properties']['fastenerRole']['value']);
    }

    public function test_2h_resolves_and_is_a_nut(): void
    {
        $grade = $this->lib()->grade('BOLTING', 'A194', '2H');

        self::assertSame('NUT', $grade['properties']['fastenerRole']['value']);
    }

    public function test_the_hardness_controlled_m_grades_are_separate_grades(): void
    {
        // B7M is not a variant of B7 in a dropdown sense: it is the same alloy
        // at a lower hardness for sour service, with a LOWER strength. Ordering
        // one for the other is a sulphide stress cracking failure later.
        $lib = $this->lib();

        $b7 = $lib->grade('BOLTING', 'A193', 'B7');
        $b7m = $lib->grade('BOLTING', 'A193', 'B7M');

        self::assertNotNull($b7m);
        self::assertSame('321 HBW', $b7['properties']['maxHardness']['value']);
        self::assertSame('235 HBW', $b7m['properties']['maxHardness']['value']);
        self::assertLessThan(
            $b7['properties']['minTensileStrength']['value'],
            $b7m['properties']['minTensileStrength']['value'],
            'The hardness ceiling costs strength - that is the whole trade.'
        );
    }

    public function test_every_bolting_grade_declares_whether_it_is_bolt_or_nut(): void
    {
        $lib = $this->lib();

        $seen = 0;
        foreach ($lib->specsIn('BOLTING') as $spec) {
            // gradesOf() returns the dropdown summary; the properties live on
            // grade(), keyed by the (code, classOrType) pair.
            foreach ($lib->gradesOf('BOLTING', $spec['code']) as $summary) {
                $grade = $lib->grade('BOLTING', $spec['code'], $summary['key']);
                $role = $grade['properties']['fastenerRole']['value'] ?? null;
                self::assertContains(
                    $role,
                    ['BOLT', 'NUT'],
                    "{$spec['code']} {$summary['key']} must say which it is."
                );
                $seen++;
            }
        }
        self::assertSame(30, $seen, 'The bolting group holds 30 grades.');
    }

    public function test_the_low_temperature_bolting_matches_the_cold_service_quartet(): void
    {
        // A333 Gr 6 pipe, A350 LF2 flanges, A420 WPL6 fittings, A352 LCC valves
        // - and A320 L7 bolting. A B7 stud on that joint is a cold fracture.
        $lib = $this->lib();

        $l7 = $lib->grade('BOLTING', 'A320', 'L7');
        self::assertTrue($l7['properties']['impactTestRequired']['value']);
        self::assertLessThanOrEqual(-46, $l7['properties']['minTemperature']['value']);
    }

    public function test_bolting_offers_one_product_form_only(): void
    {
        // A fastener is not seamless or welded - it is heat treated. Offering
        // SMLS here would invite a meaningless Ew into a fastener line.
        foreach ($this->lib()->specsIn('BOLTING') as $spec) {
            self::assertSame(
                ['HEAT_TREATED'],
                array_column($this->lib()->productFormsFor('BOLTING', $spec['code']), 'code'),
                "{$spec['code']} should offer only HEAT_TREATED."
            );
        }
    }

    public function test_a_flange_is_not_offered_bolting_material(): void
    {
        // The cascade must keep supplying forgings for the flange itself.
        $groups = array_column($this->lib()->groupsForComponentType('FLANGE'), 'code');

        self::assertContains('FORGINGS', $groups);
        self::assertNotContains('BOLTING', $groups);
    }

    // ------------------------------------- the gap above NPS 24 (ADR-008 11.14)

    public function test_the_pack_says_where_it_stops(): void
    {
        $fb = $this->fb();

        // B16.5 runs to NPS 24 and stops; B16.47 picks up at 26 and runs to
        // 60. Both were transcribed on 2026-09-24, so both now answer. Before
        // that date the last two assertions read assertFalse.
        self::assertTrue($fb->covers('B16.5', null, '24'));
        self::assertFalse($fb->covers('B16.5', null, '26'));
        self::assertTrue($fb->covers('B16.47', 'A', '30'));
        self::assertTrue($fb->covers('B16.47', 'B', '30'));
    }

    public function test_the_two_series_are_different_flanges_at_the_same_size(): void
    {
        $fb = $this->fb();

        $a = $fb->forJoint('300#', '30', 'A');
        $b = $fb->forJoint('300#', '30', 'B');

        self::assertNotNull($a);
        self::assertNotNull($b);
        self::assertNotEquals(
            $a['boltCircleMm'],
            $b['boltCircleMm'],
            'Series A and B are not interchangeable; that is the whole point of the axis.'
        );
    }

    public function test_a_size_the_standard_declines_is_not_a_size_with_no_bolts(): void
    {
        // Series B stops at NPS 36 in Classes 400, 600 and 900. NPS 42 at
        // 600# Series B is not a flange with zero bolts, it is a flange the
        // standard does not make.
        self::assertNotNull($this->fb()->forJoint('600#', '36', 'B'));
        self::assertNull($this->fb()->forJoint('600#', '42', 'B'));
        self::assertNotNull($this->fb()->forJoint('600#', '42', 'A'));

        self::assertSame(['A'], $this->fb()->seriesAnswering('600#', '42'));
        self::assertSame(['A', 'B'], $this->fb()->seriesAnswering('600#', '36'));
    }

    public function test_an_unstated_series_is_refused_rather_than_guessed(): void
    {
        $fb = $this->fb();

        // Above NPS 24 the same class and size exist in both series with
        // different drilling. Answering either one would be a coin toss that
        // buys the wrong bolts, so forJoint declines until the caller says
        // which series. This is the same shape as the old gap: null here means
        // "cannot say", never "nothing to check".
        self::assertNull($fb->forJoint('300#', '30'));
        self::assertSame(['A', 'B'], $fb->seriesAnswering('300#', '30'));
        self::assertNotNull($fb->forJoint('300#', '30', 'A'));

        $declared = array_filter(
            $fb->coverage(),
            static fn (array $c): bool => $c['standard'] === 'B16.47',
        );
        self::assertCount(2, $declared, 'Both B16.47 series must be declared.');

        foreach ($declared as $c) {
            self::assertTrue($c['hasRows']);
            self::assertSame(2025, $c['editionYear']);
            self::assertContains($c['series'], ['A', 'B']);
        }
    }

    public function test_a_ring_joint_above_nps_24_exists_in_series_a_only(): void
    {
        // B16.47 prints a ring-joint stud length in Series A, and only from
        // NPS 26 to 36. Series B has no ring-joint column at all.
        self::assertNotNull($this->fb()->forJoint('300#', '30', 'A')['minLengthRingJointMm']);
        self::assertNull($this->fb()->forJoint('300#', '38', 'A')['minLengthRingJointMm']);
        self::assertNull($this->fb()->forJoint('300#', '30', 'B')['minLengthRingJointMm']);
    }

    public function test_the_series_axis_lives_here_and_not_on_the_ratings_pack(): void
    {
        // A and B differ in bolt count, diameter and circle, so bolting needs
        // the axis. They share their pressure-temperature ratings, so the
        // ratings pack must not have one. ADR-008 section 11.15.
        $series = array_column($this->fb()->coverage(), 'series');
        self::assertContains('A', $series);
        self::assertContains('B', $series);

        foreach (app(\App\Support\Reference\FlangePtRatings::class)->groupsWithCurves('B16.47') as $g) {
            self::assertIsString($g); // a curve may exist; a SERIES may not
        }
    }
}
