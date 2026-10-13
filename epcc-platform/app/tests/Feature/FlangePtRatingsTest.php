<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Reference\FlangePtRatings;
use Tests\TestCase;

/**
 * ASME B16.5 / B16.47 pressure-temperature ratings (ADR-008 section 11).
 *
 * Reference data only: no database, no tenant. These tests are mostly about
 * what the class REFUSES to do, because the pack is unverified and the
 * dangerous failure here is a confident wrong number rather than an error.
 */
final class FlangePtRatingsTest extends TestCase
{
    private function pt(): FlangePtRatings
    {
        return app(FlangePtRatings::class);
    }

    // --------------------------------------------- it answers, and refuses

    public function test_a_verified_curve_answers_with_the_transcribed_figure(): void
    {
        $pt = $this->pt();

        // ASME B16.5-2025 Table 2-1.1: Group 1.1, Class 300, 38 C = 51.0 bar.
        // This assertion used to read assertNull. The pack was verified against
        // a purchased copy on 2026-09-24 and not one line of the class changed
        // to make it start answering - the refusal lived in the data.
        $this->assertTrue($pt->isVerified('B16.5', '1.1'));
        $this->assertSame(5100, $pt->ratingAt('B16.5', '1.1', '300', 38));
    }

    public function test_b1647_was_purchased_too_and_now_answers(): void
    {
        $pt = $this->pt();

        // ASME B16.47-2025, Table 3. Same group, same figure as B16.5 - the two
        // standards share their ratings, which is now verified from the
        // document rather than taken from a vendor page.
        $this->assertTrue($pt->isVerified('B16.47', '1.1'));
        $this->assertSame(5100, $pt->ratingAt('B16.47', '1.1', '300', 38));
        $this->assertSame(2025, $pt->editionYear('B16.47', '1.1'));
    }

    public function test_an_unrated_group_still_refuses(): void
    {
        $pt = $this->pt();

        // B16.47 rates 27 groups; B16.5 rates 45. The nickel alloys (group 3.x)
        // are absent from B16.47 entirely, and absent must stay a refusal or
        // buying one standard quietly answers for the other.
        $this->assertNull($pt->ratingAt('B16.47', '3.4', '300', 38));
        $this->assertNotNull($pt->ratingAt('B16.5', '3.4', '300', 38));
    }

    public function test_the_indicative_value_exists_for_display_only(): void
    {
        // 51.0 bar at 38 C for Class 300 carbon steel, the single most quoted
        // figure in the standard. Reconstruction had said 51.1.
        $this->assertSame(5100, $this->pt()->indicativeRatingAt('B16.5', '1.1', '300', 38));
    }

    public function test_sufficiency_is_now_decided_rather_than_unknown(): void
    {
        $pt = $this->pt();

        // Null still means "cannot say". It must no longer be the answer for a
        // verified curve, or the check is decorative.
        $this->assertTrue($pt->sufficientFor('B16.5', '1.1', '300', 1000.0, 38));
        $this->assertFalse($pt->sufficientFor('B16.5', '1.1', '300', 9000.0, 38));
        // B16.47 used to be the unverified example here; it was purchased too.
        // The "cannot say" answer now needs a group B16.47 genuinely does not
        // rate - it stops at the 2.x stainlesses and has no nickel alloys.
        $this->assertNull($pt->sufficientFor('B16.47', '3.4', '300', 1000.0, 38));
    }

    public function test_it_refuses_to_extrapolate_beyond_the_table(): void
    {
        $pt = $this->pt();

        // B16.5 permits interpolation and forbids extrapolation. A caller
        // asking for 700 C of a carbon steel curve has a design problem.
        $this->assertNull($pt->indicativeRatingAt('B16.5', '1.1', '300', 700));
        $this->assertNull($pt->indicativeRatingAt('B16.5', '1.1', '300', -100));
    }

    public function test_an_unknown_standard_group_or_class_is_null(): void
    {
        $pt = $this->pt();

        // 2.1 and Class 400 used to be absent and were the examples here.
        // Both are now transcribed, so the test needs things the standard
        // genuinely does not have: B16.5 numbers its groups with gaps (there
        // is no 1.6, no 1.8, no 3.18) and it has no Class 2000.
        $this->assertNull($pt->indicativeRatingAt('B16.5', '1.6', '300', 38));
        $this->assertNull($pt->indicativeRatingAt('B16.5', '3.18', '300', 38));
        $this->assertNull($pt->indicativeRatingAt('B16.5', '1.1', '2000', 38));
        $this->assertNull($pt->indicativeRatingAt('NOPE', '1.1', '300', 38));
    }

    // ------------------------------------------------------- interpolation

    public function test_it_interpolates_linearly_between_tabulated_points(): void
    {
        $pt = $this->pt();

        // The real table has a 50 C row the reconstruction did not, so 69 C
        // now interpolates 50->100 (4980 -> 4670), not 38->100. That row is
        // exactly the kind of detail a confident reconstruction loses.
        $at69 = $pt->indicativeRatingAt('B16.5', '1.1', '300', 69);
        $this->assertNotNull($at69);
        $this->assertEqualsWithDelta(4980 + (4670 - 4980) * (19 / 50), $at69, 2.0);
    }

    public function test_a_tabulated_temperature_is_returned_exactly(): void
    {
        $this->assertSame(4670, $this->pt()->indicativeRatingAt('B16.5', '1.1', '300', 100));
    }

    // ------------------------------------------------- derived classes (800)

    public function test_class_800_is_a_formula_and_not_a_table(): void
    {
        $pt = $this->pt();

        $this->assertTrue($pt->isDerived('800'));
        $this->assertFalse($pt->isDerived('300'));

        $d = $pt->derivation('800');
        $this->assertNotNull($d);
        $this->assertSame('API 602', $d['definedBy']);
        $this->assertSame(['600', '900'], array_column($d['derivedFrom'], 'class'));
    }

    public function test_the_derived_class_800_reproduces_the_published_figure(): void
    {
        // One third of Class 600 plus two thirds of Class 900. At 38 C that is
        // 13617 kPa = 1975 psi, which is what a manufacturer's catalogue
        // prints for a Class 800 forged valve. Two independent routes to one
        // number - corroboration, not verification.
        $kpa = $this->pt()->indicativeRatingAt('B16.5', '1.1', '800', 38);
        $this->assertNotNull($kpa);
        $this->assertEqualsWithDelta(1975, $kpa * 0.145038, 2.0);
    }

    public function test_a_derived_class_is_null_when_a_component_is_missing(): void
    {
        // A formula is only as available as its inputs. Beyond the table there
        // is no Class 600 to build Class 800 from.
        $this->assertNull($this->pt()->indicativeRatingAt('B16.5', '1.1', '800', 700));
    }

    // ------------------------------------------------------------ the map

    public function test_b1647_stops_at_class_900_and_b165_reaches_2500(): void
    {
        $pt = $this->pt();

        // Nothing in the system currently stops a 1500 class being written
        // against a 30 inch flange. This is the question that check will ask.
        $this->assertSame(2500, $pt->maxClassFor('B16.5'));
        $this->assertSame(900, $pt->maxClassFor('B16.47'));
        $this->assertFalse($pt->definesClass('B16.47', '1500'));
        $this->assertTrue($pt->definesClass('B16.47', '900'));
    }

    public function test_class_75_belongs_to_b1647_and_not_to_b165(): void
    {
        $pt = $this->pt();

        $this->assertTrue($pt->definesClass('B16.47', '75'));
        $this->assertFalse($pt->definesClass('B16.5', '75'));
    }

    public function test_every_curve_names_an_edition_year(): void
    {
        // Three findings in one day turned on an edition number. The copy that
        // was purchased and read is B16.5-2025, revising B16.5-2020 - so this
        // assertion had to change with the transcription, which is the point.
        $this->assertSame(2025, $this->pt()->editionYear('B16.5', '1.1'));
    }

    // ------------------------------------------ the join to the material pack

    public function test_a_material_group_from_the_library_reaches_a_curve(): void
    {
        // The whole two-hop point: A105 resolves to group 1.1 in the material
        // library, and 1.1 is a group this pack has a curve for. If either
        // side renamed its groups this would break, which is why it is here
        // rather than in two separate tests that both pass.
        $group = app(\App\Support\Reference\MaterialLibrary::class)
            ->b165MaterialGroup('FORGINGS', 'A105', null);

        $this->assertSame('1.1', $group);
        $this->assertContains($group, $this->pt()->groupsWithCurves('B16.5'));
        $this->assertNotNull($this->pt()->indicativeRatingAt('B16.5', $group, '300', 38));
    }

    // ------------------------------------------- B16.47, series, discrepancies

    public function test_class_75_belongs_to_series_b_alone(): void
    {
        $pt = $this->pt();

        // B16.47-2025 tabulates Class 75 dimensions under Series B (Table 37);
        // Series A starts at Class 150 (Table 32). definesClass() is too coarse
        // to catch a Series A line asking for Class 75.
        $this->assertTrue($pt->definesClass('B16.47', '75'));
        $this->assertNotContains('75', $pt->classesForSeries('B16.47', 'A'));
        $this->assertContains('75', $pt->classesForSeries('B16.47', 'B'));

        // B16.5 has no series axis at all, and that is a different answer from
        // "this series offers nothing".
        $this->assertNull($pt->classesForSeries('B16.5', 'A'));
    }

    public function test_the_two_b1647_series_share_one_rating_table(): void
    {
        $pt = $this->pt();

        // Verified from the document: the 27 rating tables are indexed by
        // material group with no series axis, while the dimension tables are
        // per series. Series A and B differ in bolt circle, not in pressure.
        $this->assertTrue($pt->seriesShareRatings('B16.47'));
        $this->assertFalse($pt->seriesShareRatings('B16.5'));
    }

    public function test_class_75_is_rated_and_stops_earlier_than_class_150(): void
    {
        $pt = $this->pt();

        // Class 75 is not simply half of Class 150. It is 9.8 vs 19.6 bar at
        // 38 C, but it drops off the table entirely above 350 C while Class 150
        // runs to 538 C - so halving Class 150 would invent a rating.
        $this->assertSame(980, $pt->ratingAt('B16.47', '1.1', '75', 38));
        $this->assertSame(1960, $pt->ratingAt('B16.47', '1.1', '150', 38));
        $this->assertNull($pt->ratingAt('B16.47', '1.1', '75', 400));
        $this->assertNotNull($pt->ratingAt('B16.47', '1.1', '150', 400));
    }

    public function test_the_two_standards_agree_almost_everywhere(): void
    {
        $pt = $this->pt();

        // The point of holding both: they are the same numbers. If a future
        // transcription drifts, this is where it shows up.
        foreach ([['1.1', '300', 38, 5100], ['1.1', '900', 200, 13150],
                  ['2.1', '600', 100, 8160], ['1.10', '400', 300, 6000]] as [$g, $c, $t, $v]) {
            $this->assertSame($v, $pt->ratingAt('B16.5', $g, $c, $t), "B16.5 {$g}/{$c}/{$t}");
            $this->assertSame($v, $pt->ratingAt('B16.47', $g, $c, $t), "B16.47 {$g}/{$c}/{$t}");
        }
    }

    public function test_a_disagreement_is_reported_not_reconciled(): void
    {
        $pt = $this->pt();

        // Group 2.5, Class 900, 38 C. B16.5-2025 Table 2-2.5 prints 149.1 bar;
        // B16.47-2025 Table 21 prints 148.1. Each pack holds what its own
        // document says - correcting one to match the other would destroy the
        // evidence that they differ.
        $this->assertSame(14910, $pt->ratingAt('B16.5', '2.5', '900', 38));
        $this->assertSame(14810, $pt->ratingAt('B16.47', '2.5', '900', 38));

        $d = $pt->discrepancyFor('2.5', '900', 38);
        $this->assertNotNull($d);
        $this->assertSame(14910, $d['b165kPa']);
        $this->assertSame(14810, $d['b1647kPa']);
        $this->assertSame('Table 21', $d['b1647Table']);

        // And the arbitration must be recorded, not left to the reader.
        $this->assertSame('B16.5', $d['consistentWithItsNeighbours']);
    }

    public function test_agreement_reports_no_discrepancy(): void
    {
        // The guard on the guard: discrepancyFor must not return something for
        // every cell, or the check above would pass against a broken lookup.
        $this->assertNull($this->pt()->discrepancyFor('1.1', '300', 38));
    }
}
