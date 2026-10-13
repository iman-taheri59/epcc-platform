<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Reference\FlangePtRatings;
use App\Support\Reference\ValvePtRatings;
use Tests\TestCase;

/**
 * ASME B16.34-2025 valve ratings — the round that separated a VALVE's envelope
 * from a FLANGE's.
 *
 * The defect this closes: until now the platform had B16.5 and B16.47 and
 * nothing else, so a valve's pressure-temperature envelope was being taken from
 * the flange tables. That is the single most common mistake in piping
 * specification and it is wrong in three concrete ways, each of which has a
 * test here: Class 400 does not exist for valves, Class 4500 does, and Special
 * Class has no flange equivalent at all.
 *
 * No database. This is a reference pack and the tests read it directly.
 */
final class ValvePtRatingsTest extends TestCase
{
    private function ratings(): ValvePtRatings
    {
        return app(ValvePtRatings::class);
    }

    // -------------------------------------------- a valve is not a flange

    public function test_class_400_is_a_flange_class_with_no_valve_counterpart(): void
    {
        // B16.5 rates a Class 400 flange. B16.34 does not tabulate Class 400 at
        // all. A PMS line saying "Class 400 gate valve" has been copied off the
        // flange sheet, and answering it from the flange table would invent a
        // rating the valve standard declines to give.
        self::assertFalse($this->ratings()->tabulatesPressureClass('400'));
        self::assertNull($this->ratings()->ratingAt('1.1', '400', 38.0));
        self::assertSame(
            'PRESSURE_CLASS_NOT_TABULATED',
            $this->ratings()->refusalFor('1.1', '400', 38.0),
        );

        // ... and the flange pack does answer for it, which is the whole point.
        self::assertNotNull(app(FlangePtRatings::class)->ratingAt('B16.5', '1.1', '400', 38.0));
    }

    public function test_class_4500_exists_for_valves_and_nowhere_else(): void
    {
        self::assertTrue($this->ratings()->tabulatesPressureClass('4500'));
        self::assertNotNull($this->ratings()->ratingAt('1.1', '4500', 38.0));

        self::assertNull(app(FlangePtRatings::class)->ratingAt('B16.5', '1.1', '4500', 38.0));
    }

    public function test_special_class_is_higher_than_standard_class(): void
    {
        // The reason Special Class exists: a valve that has passed extra NDE is
        // allowed a higher working pressure. Group 1.1 at 38 C, Class 150:
        // 19.6 bar standard, 19.8 bar special, straight off Table 2-1.1.
        $std = $this->ratings()->ratingAt('1.1', '150', 38.0, ValvePtRatings::STANDARD);
        $spc = $this->ratings()->ratingAt('1.1', '150', 38.0, ValvePtRatings::SPECIAL);

        self::assertSame(1960, $std);
        self::assertSame(1980, $spc);
        self::assertGreaterThan($std, $spc);
    }

    public function test_limited_class_refuses_with_a_reason_rather_than_returning_null(): void
    {
        // Mandatory Appendix V computes it per valve from a stress area. There
        // is no table. A bare null would read as "this group is missing".
        self::assertSame(
            'LIMITED_CLASS_IS_COMPUTED',
            $this->ratings()->refusalFor('1.1', '150', 38.0, ValvePtRatings::LIMITED),
        );
    }

    // ------------------------------------------- the transcription itself

    public function test_every_group_carries_both_tabulated_rating_classes(): void
    {
        $groups = $this->ratings()->materialGroups();

        self::assertCount(50, $groups);

        foreach ($groups as $g) {
            self::assertNotNull(
                $this->ratings()->ratingAt($g, '150', 38.0, ValvePtRatings::STANDARD),
                "group {$g} has no Standard Class rating at 38 C",
            );
            self::assertNotNull(
                $this->ratings()->ratingAt($g, '150', 38.0, ValvePtRatings::SPECIAL),
                "group {$g} has no Special Class rating at 38 C",
            );
        }
    }

    public function test_a_known_row_matches_the_printed_table(): void
    {
        // Table 2-1.1, Standard Class, 38 C, read across all seven classes.
        // Transcribed in bar, stored in kPa.
        $expected = [
            '150' => 1960, '300' => 5100, '600' => 10200, '900' => 15310,
            '1500' => 25510, '2500' => 42510, '4500' => 76530,
        ];

        foreach ($expected as $class => $kpa) {
            // PHP turns a numeric string array key into an int, which is how a
            // '150' key arrives at a string parameter as 150 and blows up.
            self::assertSame($kpa, $this->ratings()->ratingAt('1.1', (string) $class, 38.0), "class {$class}");
        }
    }

    public function test_a_temperature_between_two_rows_is_interpolated(): void
    {
        // B16.34 para. 2.1.1 permits linear interpolation. Group 1.1 Class 150
        // is 19.1 bar at 50 C and 17.7 bar at 100 C, so 75 C is 18.4 bar.
        self::assertSame(1840, $this->ratings()->ratingAt('1.1', '150', 75.0));
    }

    public function test_there_is_no_rating_above_the_last_tabulated_temperature(): void
    {
        // Group 1.1 stops at 538 C. Extrapolating past the end of a rating
        // curve is how a valve gets specified for a service it cannot hold.
        self::assertNull($this->ratings()->ratingAt('1.1', '150', 600.0));
        self::assertSame(
            'ABOVE_THE_LAST_TABULATED_TEMPERATURE',
            $this->ratings()->refusalFor('1.1', '150', 600.0),
        );
    }

    public function test_an_unknown_material_group_is_null_and_says_so(): void
    {
        self::assertNull($this->ratings()->ratingAt('9.9', '150', 38.0));
        self::assertSame('MATERIAL_GROUP_UNKNOWN', $this->ratings()->refusalFor('9.9', '150', 38.0));
    }

    // ------------------------------------------------- the four bad cells

    public function test_the_four_suspected_printing_errors_are_withheld(): void
    {
        // The transcription found four cells in B16.34-2025 whose printed value
        // fails arithmetic - digit transpositions, all in the high classes.
        // They are stored AS PRINTED, because a transcription that silently
        // corrects its source stops being one, and they are refused on read.
        $anomalies = $this->ratings()->anomalies();

        self::assertCount(4, $anomalies);

        foreach ($anomalies as $a) {
            self::assertTrue($a['doNotCite']);
            self::assertArrayHasKey('corroboratedKpa', $a);
            self::assertNotSame($a['printedKpa'], $a['corroboratedKpa']);

            self::assertNull($this->ratings()->ratingAt(
                $a['materialGroup'], $a['pressureClass'],
                (float) $a['temperatureC'], $a['ratingClass'],
            ), "the withheld cell for group {$a['materialGroup']} still answers");

            self::assertSame(
                'CELL_WITHHELD_AS_SUSPECTED_PRINTING_ERROR',
                $this->ratings()->refusalFor(
                    $a['materialGroup'], $a['pressureClass'],
                    (float) $a['temperatureC'], $a['ratingClass'],
                ),
            );
        }
    }

    public function test_a_withheld_cell_poisons_an_interpolation_across_it(): void
    {
        // Group 1.18 Class 1500 at 500 C is withheld. Interpolating 490 C would
        // need it, so that answer is refused too - a plausible number produced
        // from a bad one is worse than no number.
        self::assertNull($this->ratings()->ratingAt('1.18', '1500', 490.0));

        // The neighbouring class is untouched: only the bad cell is lost.
        self::assertNotNull($this->ratings()->ratingAt('1.18', '2500', 490.0));
    }

    public function test_each_anomaly_carries_the_evidence_for_calling_it_one(): void
    {
        // A "suspected error" with no argument attached is just disagreement
        // with the standard. Every one of the four cites at least two
        // independent checks.
        foreach ($this->ratings()->anomalies() as $a) {
            self::assertGreaterThan(120, strlen($a['evidence']));
        }
    }

    // ------------------------------- the claim people actually rely on

    public function test_standard_class_reproduces_the_flange_ratings_almost_exactly(): void
    {
        // The received wisdom is "B16.34 Standard Class = B16.5". It is true in
        // 4,039 of 4,050 comparable cells and false in eleven, and this test
        // exists so that "almost" stays visible. Eleven cells is why the valve
        // pack had to be transcribed instead of aliased to the flange one.
        $diffs = $this->ratings()->crossStandardDiscrepancies();

        self::assertCount(11, $diffs);

        foreach ($diffs as $d) {
            self::assertNotSame($d['b1634StandardClassKpa'], $d['b165Kpa']);
            self::assertSame(
                $d['b1634StandardClassKpa'] - $d['b165Kpa'],
                $d['deltaKpa'],
            );
        }
    }

    public function test_a_material_resolves_to_its_group(): void
    {
        self::assertSame('1.1', $this->ratings()->groupFor('A216', 'WCB'));
        self::assertSame('1.2', $this->ratings()->groupFor('A216', 'WCC'));
        self::assertSame('2.2', $this->ratings()->groupFor('A182', 'F316'));
        self::assertSame('1.1', $this->ratings()->groupFor('A105'));
    }

    public function test_a_spec_spread_over_many_groups_refuses_to_guess(): void
    {
        // A182 runs from F1 to F91 across a dozen groups. Returning the first
        // one found would hand an F316 valve a low-alloy rating curve, and the
        // shape of the answer would look perfectly reasonable.
        self::assertNull($this->ratings()->groupFor('A182'));
    }

    public function test_a_material_outside_the_standard_is_null(): void
    {
        self::assertNull($this->ratings()->groupFor('A999', 'X'));
    }

    public function test_a_materials_own_notes_come_back_with_it(): void
    {
        // "Not to be used over 371 C" sits under a table that keeps printing
        // numbers above 371 C. The note has to travel with the material or the
        // grid over-rates the valve.
        $notes = $this->ratings()->notesForMaterial('A672', 'C70');

        self::assertNotSame([], $notes);
        self::assertTrue(
            (bool) preg_grep('/not to be used over/i', array_column($notes, 'text')),
            'A672 C70 should carry a temperature ceiling note',
        );
    }

    public function test_the_pack_declares_where_the_two_rating_classes_stop_apart(): void
    {
        // Group 3.8's Standard Class runs to 816 C and its Special Class stops
        // at 725 C. Two sections of one table with different temperature lists
        // is exactly the shape assumption that has bitten this repo before, so
        // it is declared rather than smoothed over.
        $kinds = array_column($this->ratings()->observations(), 'kind');

        self::assertContains('RATING_CLASSES_STOP_AT_DIFFERENT_TEMPERATURES', $kinds);

        self::assertNotNull($this->ratings()->ratingAt('3.8', '150', 800.0, ValvePtRatings::STANDARD));
        self::assertNull($this->ratings()->ratingAt('3.8', '150', 800.0, ValvePtRatings::SPECIAL));
    }

    public function test_class_800_stops_being_a_formula_off_the_web(): void
    {
        // Class 800 is tabulated by nobody. The platform derived it as
        // 1/3 x Class 600 + 2/3 x Class 900 from public sources, unverified.
        // API 602-2015 clause 4.1.3 now DEFINES it: the ratings "are a linear
        // interpolation of Standard Class 600 and Standard Class 900
        // pressure/temperature ratings listed in ASME B16.34". Class 800 sits
        // two thirds of the way from 600 to 900, which is the same weights -
        // the guess was right, and is now a citation.
        $pack = json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/flange-pt-ratings.ref.json'),
        ), true);

        $c800 = array_values(array_filter(
            $pack['derivedClasses'],
            static fn (array $d): bool => $d['class'] === '800',
        ))[0];

        self::assertTrue($c800['verified']);
        self::assertSame('API 602', $c800['definedBy']);
        self::assertStringContainsString('4.1.3', $c800['definedByClause']);

        // AND the base standard is B16.34, not B16.5. A Class 800 VALVE was
        // being derived from the FLANGE tables - the same error, in miniature,
        // that valve-pt-ratings exists to correct.
        self::assertSame('B16.34', $c800['appliesToStandard']);
        self::assertSame('B16.5', $c800['correctedFromGuess']['appliesToStandard']['was']);
    }

    public function test_the_class_800_rule_reproduces_the_api_602_table(): void
    {
        // Applying clause 4.1.3 to our verified B16.34 group 1.1 against the
        // figures printed in API 602-2015 Table 2a. Fourteen of sixteen rows
        // land within 0.023 MPa; two do not, and they are declared rather than
        // averaged away - API 602 is a decade older than B16.34-2025 and the
        // underlying ratings moved.
        $pack = json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/flange-pt-ratings.ref.json'),
        ), true);
        $corr = array_values(array_filter(
            $pack['derivedClasses'],
            static fn (array $d): bool => $d['class'] === '800',
        ))[0]['corroboration'];

        self::assertSame(16, $corr['rowsCompared']);
        self::assertSame(14, $corr['rowsWithin0_023MPa']);
        self::assertCount(2, $corr['deviatingRows']);

        // Spot-check the rule itself at 38 C rather than trusting the note:
        // 10.20 MPa and 15.31 MPa give 13.607, and API 602 prints 13.62.
        $r = app(ValvePtRatings::class);
        $c600 = $r->ratingAt('1.1', '600', 38.0);
        $c900 = $r->ratingAt('1.1', '900', 38.0);
        self::assertNotNull($c600);
        self::assertNotNull($c900);

        $derived = ($c600 / 3 + 2 * $c900 / 3) / 1000;   // kPa -> MPa
        self::assertEqualsWithDelta(13.62, $derived, 0.02);
    }

    public function test_two_standards_key_the_seat_rating_on_different_axes(): void
    {
        // API 608 keys a BALL seat on the ball design and a DN band; API 609
        // keys a BUTTERFLY seat on the pressure class. Same idea, different
        // axes, which is why a curve carries a discriminators map instead of
        // fixed fields - and why a caller that does not supply them gets null
        // rather than whichever curve happened to match first.
        $r = $this->ratings();

        self::assertSame(1970, $r->seatRatingAt(
            'BALL', 'PTFE', 38.0, ['ballDesign' => 'FLOATING'], 150,
        ));
        self::assertSame(10200, $r->seatRatingAt(
            'BUTTERFLY', 'RPTFE', 38.0, ['butterflyCategory' => 'CATEGORY_B'], null, '600',
        ));

        // No discriminators, no answer.
        self::assertNull($r->seatRatingAt('BALL', 'PTFE', 38.0, [], 150));
    }

    public function test_class_600_butterfly_has_no_ptfe_column_at_all(): void
    {
        // API 609 Table 1 gives Class 600 an R-PTFE column and no PTFE one.
        // The standard does not rate PTFE there; that is an answer, not a
        // missing cell, and inventing one would put a soft seat on a valve
        // the standard declines to rate.
        self::assertNull($this->ratings()->seatRatingAt(
            'BUTTERFLY', 'PTFE', 38.0, ['butterflyCategory' => 'CATEGORY_B'], null, '600',
        ));
        self::assertNotNull($this->ratings()->seatRatingAt(
            'BUTTERFLY', 'RPTFE', 38.0, ['butterflyCategory' => 'CATEGORY_B'], null, '600',
        ));
    }

    public function test_the_seat_governs_a_hot_soft_seated_butterfly_valve(): void
    {
        // Group 2.2 Class 600 shell at 149 C is 76.95 bar. The same valve with
        // an R-PTFE seat is 37.9 bar - less than half.
        $g = $this->ratings()->governingRatingAt(
            '2.2', '600', 149.0, 'RPTFE', 'BUTTERFLY',
            ['butterflyCategory' => 'CATEGORY_B'],
        );

        self::assertSame('SEAT', $g['governedBy']);
        self::assertSame(3790, $g['kPa']);
        self::assertGreaterThan($g['kPa'], $this->ratings()->ratingAt('2.2', '600', 149.0));
    }

    public function test_a_category_a_butterfly_valve_has_no_class_rating_to_look_up(): void
    {
        // API 609 clause 1.2 a): Category A is "manufacturer's rated cold
        // working pressure". Its class 125/150 figure is a FLANGE BOLTING
        // PATTERN, not a pressure class. The seat table is headed "for
        // Category B Valves", so there is nothing here for a Category A valve
        // and the pack says so instead of returning a Class 150 figure.
        self::assertNull($this->ratings()->seatRatingAt(
            'BUTTERFLY', 'RPTFE', 38.0, ['butterflyCategory' => 'CATEGORY_A'], null, '150',
        ));

        $rule = $this->ratings()->seatRatingRule();
        self::assertArrayHasKey('categoryAButterflyIsNotHere', $rule);
        self::assertStringContainsString('category error', $rule['categoryAButterflyIsNotHere']);
    }

    public function test_a_pressure_class_is_recognised_however_it_is_written(): void
    {
        // A transcriber types what the document shows: 150#, Class 150, 150.
        foreach (['150', '150#', 'Class 150', ' 150 '] as $written) {
            self::assertSame(1960, $this->ratings()->ratingAt('1.1', $written, 38.0), $written);
        }
    }
}
