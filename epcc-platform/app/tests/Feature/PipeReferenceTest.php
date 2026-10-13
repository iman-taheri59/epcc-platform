<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Reference\Exceptions\UnknownScheduleException;
use App\Support\Reference\Exceptions\UnknownSizeException;
use App\Support\Reference\PipeReference;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-007 - engineering reference data.
 *
 * The assertions that matter most here are the DIVERGENCE ones in the second
 * group. A test that only checks 2" and 6" passes on a completely wrong
 * large-bore table, because STD and SCH 40 happen to be identical at small
 * sizes. Several published tables print STD values under a "SCH 40" heading;
 * this suite is what stops one of those sources being copied in.
 */
final class PipeReferenceTest extends TestCase
{
    private function ref(): PipeReference
    {
        return $this->app->make(PipeReference::class);
    }

    // ------------------------------------------------------------ sizes ---

    public function test_it_loads_the_full_ladder_from_half_inch_to_forty_eight(): void
    {
        $sizes = $this->ref()->sizes();

        $this->assertGreaterThanOrEqual(30, count($sizes));
        $this->assertSame('1/8', $sizes[0]->nps);
        $this->assertSame('48', $sizes[count($sizes) - 1]->nps);
    }

    public function test_sizes_are_ordered_ascending_by_dn(): void
    {
        $dns = array_map(static fn ($s): int => $s->dn, $this->ref()->sizes());
        $sorted = $dns;
        sort($sorted);

        $this->assertSame($sorted, $dns, 'sizes() must come back in ascending DN order');
    }

    public function test_it_returns_the_anchor_rows_from_asme_b36_10m(): void
    {
        $ref = $this->ref();

        $half = $ref->size('1/2');
        $this->assertSame(15, $half->dn);
        $this->assertSame(21.3, $half->odMm);

        $six = $ref->size('6');
        $this->assertSame(150, $six->dn);
        $this->assertSame(168.3, $six->odMm);

        $twentyFour = $ref->size('24');
        $this->assertSame(600, $twentyFour->dn);
        $this->assertSame(610.0, $twentyFour->odMm);
    }

    public function test_od_is_not_derived_from_nps(): void
    {
        // NPS 1/2 is a NAME, not a dimension: the pipe is 21.3 mm OD, not 12.7.
        $half = $this->ref()->size('1/2');

        $this->assertNotEqualsWithDelta(0.5 * 25.4, $half->odMm, 1.0);
        $this->assertSame(0.840, $half->odIn);
    }

    public function test_nps_14_and_above_have_od_equal_to_nps(): void
    {
        // Above 12" the name becomes the actual outside diameter in inches.
        foreach (['14', '16', '24', '48'] as $nps) {
            $size = $this->ref()->size($nps);
            $this->assertSame((float) $nps, $size->odIn, "NPS {$nps} OD should equal its NPS");
        }
    }

    public function test_dn_lookup_works_the_european_way_in(): void
    {
        $this->assertSame('2 1/2', $this->ref()->byDn(65)->nps);
        $this->assertSame('12', $this->ref()->byDn(300)->nps);
    }

    public function test_unknown_size_throws_rather_than_returning_null(): void
    {
        $this->expectException(UnknownSizeException::class);
        $this->ref()->size('7');
    }

    public function test_unknown_dn_throws(): void
    {
        $this->expectException(UnknownSizeException::class);
        $this->ref()->byDn(175);
    }

    // ------------------------------------------ the summable column --------

    public function test_nps_decimal_is_the_only_summable_column(): void
    {
        $ref = $this->ref();

        // This is the whole reason the column exists (ADR-007 9a): dia-inch.
        $this->assertSame(2.5, $ref->size('2 1/2')->npsDecimal);
        $this->assertSame(1.25, $ref->size('1 1/4')->npsDecimal);
        $this->assertSame(0.75, $ref->size('3/4')->npsDecimal);

        // Summing five bores only works on the decimal column.
        $total = 0.0;
        foreach (['2 1/2', '6', '1/2', '12', '1 1/4'] as $nps) {
            $total += $ref->size($nps)->npsDecimal;
        }
        $this->assertSame(22.25, $total);
    }

    public function test_decimal_ordering_differs_from_label_ordering(): void
    {
        $labels = ['1 1/4', '1/2', '12', '2 1/2', '6'];

        // Sorted as strings this is already "sorted" - and wrong.
        $asStrings = $labels;
        sort($asStrings);
        $this->assertSame(['1 1/4', '1/2', '12', '2 1/2', '6'], $asStrings);

        // Sorted numerically it is the order an engineer expects.
        $ref = $this->ref();
        usort($labels, fn (string $a, string $b): int => $ref->size($a)->npsDecimal <=> $ref->size($b)->npsDecimal);
        $this->assertSame(['1/2', '1 1/4', '2 1/2', '6', '12'], $labels);
    }

    // ------------------------------------------------- THE DIVERGENCE ------

    public function test_std_is_not_an_alias_of_schedule_40_above_ten_inch(): void
    {
        $ref = $this->ref();

        // Equal up to 10" ...
        $this->assertSame($ref->wall('1/2', '40'), $ref->wall('1/2', 'STD'));
        $this->assertSame($ref->wall('6', '40'), $ref->wall('6', 'STD'));
        $this->assertSame($ref->wall('10', '40'), $ref->wall('10', 'STD'));

        // ... and different from 12" up. This is the assertion ADR-007 section 8
        // requires: a spot-check at 2" would pass on a wrong table.
        $this->assertNotSame($ref->wall('12', '40'), $ref->wall('12', 'STD'));
        $this->assertSame(10.31, $ref->wall('12', '40'));
        $this->assertSame(9.53, $ref->wall('12', 'STD'));

        $this->assertSame(17.48, $ref->wall('24', '40'));
        $this->assertSame(9.53, $ref->wall('24', 'STD'));
    }

    public function test_xs_is_not_an_alias_of_schedule_80_above_eight_inch(): void
    {
        $ref = $this->ref();

        $this->assertSame($ref->wall('1/2', '80'), $ref->wall('1/2', 'XS'));
        $this->assertSame($ref->wall('8', '80'), $ref->wall('8', 'XS'));

        $this->assertNotSame($ref->wall('10', '80'), $ref->wall('10', 'XS'));
        $this->assertSame(15.09, $ref->wall('10', '80'));
        $this->assertSame(12.70, $ref->wall('10', 'XS'));
    }

    public function test_std_and_xs_are_pinned_above_the_divergence_point(): void
    {
        $ref = $this->ref();

        foreach (['14', '16', '20', '24', '36', '48'] as $nps) {
            $this->assertSame(9.53, $ref->wall($nps, 'STD'), "STD at {$nps} should be pinned at 9.53");
            $this->assertSame(12.70, $ref->wall($nps, 'XS'), "XS at {$nps} should be pinned at 12.70");
        }
    }

    // --------------------------------------------------------- schedules --

    public function test_it_returns_anchor_wall_thicknesses(): void
    {
        $ref = $this->ref();

        $this->assertSame(2.77, $ref->wall('1/2', '40'));
        $this->assertSame(3.73, $ref->wall('1/2', '80'));
        $this->assertSame(4.78, $ref->wall('1/2', '160'));
        $this->assertSame(7.47, $ref->wall('1/2', 'XXS'));
        $this->assertSame(7.11, $ref->wall('6', '40'));
        $this->assertSame(10.97, $ref->wall('6', '80'));
    }

    public function test_absence_is_data_and_a_missing_combination_throws(): void
    {
        // 1/2" SCH 10 exists; 1/2" SCH 30 does not. A silent zero here becomes a
        // wall-thickness check that passes everything.
        $this->assertSame(2.11, $this->ref()->wall('1/2', '10'));

        $this->expectException(UnknownScheduleException::class);
        $this->ref()->wall('1/2', '100');
    }

    public function test_it_never_falls_back_between_std_and_a_numbered_schedule(): void
    {
        // The reachable version of the alias bug. ASME defines STD at 22" and
        // 26" but defines NO SCH 40 there at all. An implementation that
        // "helpfully" resolves a missing schedule to its small-bore equivalent
        // would hand back 9.53 mm for a schedule that does not exist - a
        // plausible-looking number, wrong, and impossible to notice downstream.
        //
        // Found by mutation M1, which survived the first version of this suite.
        $ref = $this->ref();

        $this->assertSame(9.53, $ref->wall('22', 'STD'));
        $this->assertFalse($ref->hasSchedule('22', '40'));

        $this->expectException(UnknownScheduleException::class);
        $ref->wall('22', '40');
    }

    public function test_it_never_falls_back_between_xs_and_schedule_80(): void
    {
        $ref = $this->ref();

        $this->assertSame(12.70, $ref->wall('30', 'XS'));
        $this->assertFalse($ref->hasSchedule('30', '80'));

        $this->expectException(UnknownScheduleException::class);
        $ref->wall('30', '80');
    }

    public function test_the_exception_names_what_is_available(): void
    {
        try {
            $this->ref()->wall('1/2', '100');
            $this->fail('expected UnknownScheduleException');
        } catch (UnknownScheduleException $e) {
            $this->assertStringContainsString('1/2', $e->getMessage());
            $this->assertStringContainsString('160', $e->getMessage(), 'message should list real alternatives');
        }
    }

    public function test_schedules_for_a_size_lists_only_what_exists(): void
    {
        $ref = $this->ref();

        $small = $ref->schedulesFor('1/2');
        $this->assertContains('40', $small);
        $this->assertContains('XXS', $small);
        $this->assertNotContains('100', $small);

        $large = $ref->schedulesFor('8');
        $this->assertContains('100', $large);
        $this->assertContains('140', $large);
    }

    public function test_inside_diameter_is_od_minus_two_walls(): void
    {
        $ref = $this->ref();

        // 6" SCH 40: 168.3 - 2(7.11) = 154.08
        $this->assertEqualsWithDelta(154.08, $ref->insideDiameter('6', '40'), 0.001);

        // Heavier schedule, smaller bore - the direction matters for hydraulics.
        $this->assertLessThan(
            $ref->insideDiameter('6', '40'),
            $ref->insideDiameter('6', '80'),
        );
    }

    public function test_numbered_schedules_never_get_thinner_as_they_climb(): void
    {
        $ref = $this->ref();
        $ladder = ['10', '20', '30', '40', '60', '80', '100', '120', '140', '160'];

        foreach ($ref->sizes() as $size) {
            $previous = null;
            foreach ($ladder as $sched) {
                if (! $ref->hasSchedule($size->nps, $sched)) {
                    continue;
                }
                $wall = $ref->wall($size->nps, $sched);
                if ($previous !== null) {
                    $this->assertGreaterThan(
                        $previous,
                        $wall,
                        "NPS {$size->nps} SCH {$sched} is not heavier than the schedule below it",
                    );
                }
                $previous = $wall;
            }
        }
    }

    public function test_no_wall_thickness_closes_the_bore(): void
    {
        $ref = $this->ref();

        foreach ($ref->sizes() as $size) {
            foreach ($ref->schedulesFor($size->nps) as $sched) {
                $this->assertGreaterThan(
                    0,
                    $ref->insideDiameter($size->nps, $sched),
                    "NPS {$size->nps} SCH {$sched} leaves no bore",
                );
            }
        }
    }

    // ------------------------------------------------------ normalisation --

    public function test_it_folds_the_ways_engineers_write_a_size(): void
    {
        $ref = $this->ref();

        foreach (['1 1/4', '1-1/4', ' 1 1/4 ', '1  1/4', '1 1/4"'] as $written) {
            $this->assertSame('1 1/4', $ref->size($written)->nps, "failed on '{$written}'");
        }
    }

    public function test_it_accepts_persian_digits(): void
    {
        // The UI is bilingual; a Farsi keyboard produces these.
        $this->assertSame('2 1/2', $this->ref()->size('۲ ۱/۲')->nps);
        $this->assertSame('12', $this->ref()->size('۱۲')->nps);
    }

    public function test_resolve_label_accepts_the_decimal_form(): void
    {
        $ref = $this->ref();

        $this->assertSame('2 1/2', $ref->resolveLabel('2.5')?->nps);
        $this->assertSame('1 1/4', $ref->resolveLabel('1.25')?->nps);
    }

    public function test_resolve_label_returns_null_instead_of_throwing(): void
    {
        // Line-number parsing needs to ask "is this token a size?" without an
        // exception, because "not a size" is a normal answer there.
        $this->assertNull($this->ref()->resolveLabel('XYZ'));
        $this->assertNull($this->ref()->resolveLabel('7'));
    }

    public function test_schedule_lookup_is_case_insensitive(): void
    {
        $ref = $this->ref();

        $this->assertSame($ref->wall('6', 'STD'), $ref->wall('6', 'std'));
        $this->assertSame($ref->wall('6', 'XXS'), $ref->wall('6', ' xxs '));
    }

    // ---------------------------------------------------------- PCF bore ---

    public function test_it_resolves_a_pcf_bore_in_each_declared_unit(): void
    {
        $ref = $this->ref();

        // UNITS-BORE INCH
        $this->assertSame('6', $ref->resolveBore(6.0, 'INCH')?->nps);

        // UNITS-BORE INCH-SIXTEENTH - 96/16 = 6"
        $this->assertSame('6', $ref->resolveBore(96.0, 'INCH-SIXTEENTH')?->nps);

        // UNITS-BORE MM - matched on outside diameter
        $this->assertSame('6', $ref->resolveBore(168.3, 'MM')?->nps);
    }

    public function test_misreading_the_bore_unit_would_corrupt_the_size(): void
    {
        $ref = $this->ref();

        // 96 read as INCH is not a pipe size at all; read as sixteenths it is 6".
        // This is exactly the silent corruption ADR-005 warns about, and the
        // reason UNITS-BORE must be read from the header rather than assumed.
        $this->assertNull($ref->resolveBore(96.0, 'INCH'));
        $this->assertSame('6', $ref->resolveBore(96.0, 'INCH-SIXTEENTH')?->nps);
    }

    public function test_an_unsupported_bore_unit_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->ref()->resolveBore(6.0, 'CUBITS');
    }

    // --------------------------------------------------------- end types ---

    public function test_end_types_cover_the_pcf_vocabulary(): void
    {
        $codes = array_column($this->ref()->endTypes(), 'code');

        foreach (['BW', 'SW', 'FL', 'SC', 'PL', 'CP', 'PF', 'GL'] as $expected) {
            $this->assertContains($expected, $codes);
        }
    }

    public function test_a_flanged_connection_is_not_a_weld_and_is_never_numbered(): void
    {
        $ref = $this->ref();

        // This is what keeps bolted joints out of the dia-inch figure.
        $this->assertFalse($ref->isWeldable('FL'));
        $this->assertFalse($ref->isNumbered('FL'));

        $this->assertTrue($ref->isWeldable('BW'));
        $this->assertTrue($ref->isNumbered('BW'));
        $this->assertTrue($ref->isWeldable('SW'));
    }

    public function test_every_numbered_end_type_is_weldable(): void
    {
        // A joint number implies a weld. The inverse is allowed: a plain end is
        // weldable but is not a prepared joint, so it carries no number.
        foreach ($this->ref()->endTypes() as $end) {
            if ($end['numbered']) {
                $this->assertTrue($end['weldable'], "{$end['code']} is numbered but not weldable");
            }
        }
    }

    public function test_socket_weld_requires_gender_but_butt_weld_does_not(): void
    {
        $ref = $this->ref();

        $this->assertTrue($ref->endType('SW')['genderRequired']);
        $this->assertFalse($ref->endType('BW')['genderRequired']);
    }

    public function test_the_pcf_wildcard_exists_but_is_inert(): void
    {
        // '**' must be recognisable on import so it can be rejected, and must
        // never be weldable - a port whose end type is "anything" defeats every
        // compatibility check.
        $wildcard = $this->ref()->endType('**');

        $this->assertTrue($wildcard['wildcard']);
        $this->assertFalse($wildcard['weldable']);
        $this->assertFalse($wildcard['numbered']);
    }

    // ------------------------------------------------ component templates --

    public function test_a_tee_has_three_ports_without_anyone_typing_three(): void
    {
        // The user's question: how does the system know a 6x6x2 tee has three
        // connection points? Answer: the template declares it.
        $ref = $this->ref();

        $this->assertSame(3, $ref->portCount('TEE'));
        $this->assertSame(2, $ref->portCount('ELBOW'));
        $this->assertSame(2, $ref->portCount('PIPE'));
        $this->assertSame(4, $ref->portCount('CROSS'));
        $this->assertSame(1, $ref->portCount('CAP'));
        $this->assertSame(1, $ref->portCount('NOZZLE'));
    }

    public function test_tee_ports_carry_run_run_branch_roles(): void
    {
        $roles = array_column($this->ref()->template('TEE')['ports'], 'role');

        $this->assertSame(['RUN', 'RUN', 'BRANCH'], $roles);
    }

    public function test_equal_and_reducing_tees_are_one_component_not_two(): void
    {
        // Explicit user decision (ADR-009 section 6).
        $codes = $this->ref()->universalTemplateCodes();

        $this->assertContains('TEE', $codes);
        $this->assertNotContains('EQUAL_TEE', $codes);
        $this->assertNotContains('REDUCING_TEE', $codes);
        $this->assertTrue($this->ref()->template('TEE')['qualifierDerived']);
    }

    public function test_a_reducer_is_the_only_two_port_fitting_with_unequal_ends(): void
    {
        $reducer = $this->ref()->template('REDUCER');

        $this->assertSame('LARGE_GT_SMALL', $reducer['sizeRule']);
        $this->assertSame(['LARGE', 'SMALL'], array_column($reducer['ports'], 'role'));
    }

    public function test_a_valve_is_directional(): void
    {
        // A check valve installed backwards is a real defect, so the two ports
        // are distinct roles even at the same size.
        $valve = $this->ref()->template('VALVE');

        $this->assertTrue($valve['directional']);
        $this->assertSame(['INLET', 'OUTLET'], array_column($valve['ports'], 'role'));
    }

    public function test_a_nozzle_is_a_port_owner_not_a_line_component(): void
    {
        // ADR-010 section 3 - this is what lets a pipe-to-nozzle joint use the
        // joints table unchanged.
        $nozzle = $this->ref()->template('NOZZLE');

        $this->assertSame('NOZZLE', $nozzle['ownerKind']);
        $this->assertSame('EQUIPMENT', $nozzle['category']);
        $this->assertCount(1, $nozzle['ports']);
    }

    public function test_terminating_components_have_exactly_one_port(): void
    {
        foreach ($this->ref()->templates() as $t) {
            if ($t['terminates'] ?? false) {
                $this->assertCount(1, $t['ports'], "{$t['code']} terminates but has more than one port");
            }
        }
    }

    public function test_every_template_default_end_is_a_known_end_type(): void
    {
        $ref = $this->ref();
        $known = array_column($ref->endTypes(), 'code');

        foreach ($ref->templates() as $t) {
            foreach ($t['ports'] as $port) {
                $this->assertContains(
                    $port['defaultEnd'],
                    $known,
                    "{$t['code']} port {$port['seq']} has unknown end type {$port['defaultEnd']}",
                );
                $this->assertNotSame('**', $port['defaultEnd'], "{$t['code']} must not default to the wildcard");
            }
        }
    }

    public function test_every_template_is_bilingual(): void
    {
        foreach ($this->ref()->templates() as $t) {
            $this->assertArrayHasKey('fa', $t['name'], "{$t['code']} has no Persian name");
            $this->assertNotSame('', trim($t['name']['fa']));
        }
    }

    public function test_unknown_template_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->ref()->template('FLUX_CAPACITOR');
    }

    // -------------------------------------------------------- provenance --

    public function test_every_pack_states_its_edition(): void
    {
        // A disputed number must be traceable to a named edition, not to
        // "a website" (ADR-007 section 2).
        $provenance = $this->ref()->provenance();

        $this->assertCount(4, $provenance);
        $this->assertStringContainsString('B36.10M', $provenance['pipe-sizes']);
        $this->assertStringContainsString('B36.10M', $provenance['pipe-schedules']);
    }

    public function test_the_repository_is_a_singleton(): void
    {
        // ~500 rows decoded once per process, not once per port.
        $this->assertSame(
            $this->app->make(PipeReference::class),
            $this->app->make(PipeReference::class),
        );
    }

    public function test_a_pack_is_decoded_once_and_then_served_from_memory(): void
    {
        // Instance identity alone does not prove memoisation - a singleton that
        // re-reads and re-decodes ~500 rows on every port lookup is still a
        // singleton. Proven observably: warm the cache, then remove the file.
        // A second lookup that still succeeds can only have come from memory.
        //
        // Found by mutation M14, which survived the first version of this suite.
        $dir = sys_get_temp_dir().'/piperef-'.uniqid();
        mkdir($dir);
        $source = base_path('../standards-packs/reference');
        foreach (['pipe-sizes', 'pipe-schedules', 'end-types', 'component-templates'] as $id) {
            copy("{$source}/{$id}.ref.json", "{$dir}/{$id}.ref.json");
        }

        try {
            $ref = new PipeReference($dir);
            $this->assertSame(10.31, $ref->wall('12', '40'));

            unlink("{$dir}/pipe-sizes.ref.json");
            unlink("{$dir}/pipe-schedules.ref.json");

            $this->assertSame(10.31, $ref->wall('12', '40'), 'second lookup should be served from memory');
            $this->assertSame(9.53, $ref->wall('12', 'STD'));
            $this->assertSame(150, $ref->size('6')->dn);

            // A pack never touched before the files went away must still fail,
            // otherwise this test would pass on an implementation that eagerly
            // loads everything and the assertion above would prove nothing.
            $fresh = new PipeReference($dir);
            $this->expectException(RuntimeException::class);
            $fresh->wall('12', '40');
        } finally {
            foreach (glob("{$dir}/*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_it_touches_no_database(): void
    {
        // Reference data must not become the first cross-tenant table by
        // accident. If a future refactor moves it into the database, this fails.
        \DB::enableQueryLog();
        \DB::flushQueryLog();

        $ref = $this->ref();
        $ref->wall('12', 'STD');
        $ref->size('6');
        $ref->portCount('TEE');
        $ref->endTypes();

        $this->assertSame([], \DB::getQueryLog(), 'reference lookups must not query the database');
    }
}
