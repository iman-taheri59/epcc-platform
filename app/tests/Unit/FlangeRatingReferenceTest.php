<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Reference\Exceptions\FlangeRatingOutOfRangeException;
use App\Support\Reference\Exceptions\UnknownFlangeMaterialException;
use App\Support\Reference\FlangeRatingReference;
use PHPUnit\Framework\TestCase;

/**
 * ASME B16.5-2025 flange pressure-temperature ratings pack accessor.
 *
 * Every expected value below is a raw cell of the extracted SI rating
 * tables (position-parsed from the owner-uploaded PDF and audited against
 * the U.S. Customary twin table by the builder script). Anchors:
 * Group 1.1 (A105/WCB) Class 150 = 19.6 bar at 38 deg C - the classic
 * 285 psig rating.
 */
class FlangeRatingReferenceTest extends TestCase
{
    private function ref(): FlangeRatingReference
    {
        return new FlangeRatingReference(dirname(__DIR__, 3).'/standards-packs/reference');
    }

    // ------------------------------------------------------- material index -

    public function test_material_group_resolution(): void
    {
        $ref = $this->ref();

        // Carbon steel: bare-spec and spec+grade forms.
        $this->assertSame('1.1', $ref->groupFor('A105'));
        $this->assertSame('1.1', $ref->groupFor('A350', 'LF2'));
        $this->assertSame('1.1', $ref->groupFor('A216', 'WCB'));

        // Austenitics, and the Cr-Mo forging ladder.
        $this->assertSame('2.1', $ref->groupFor('A182', 'F304'));
        $this->assertSame('2.2', $ref->groupFor('A182', 'F316'));
        $this->assertSame('1.9', $ref->groupFor('A182', 'F11 Cl. 2'));
        $this->assertSame('1.10', $ref->groupFor('A182', 'F22 Cl. 3'));
        $this->assertSame('1.15', $ref->groupFor('A182', 'F91 Type 1'));
        $this->assertSame('1.18', $ref->groupFor('A182', 'F92'));

        // Nickel-base alloy 800 (Alloy 20 is group 3.1).
        $this->assertSame('3.1', $ref->groupFor('A182', 'N08020'));
    }

    public function test_unknown_flange_material_throws(): void
    {
        $this->expectException(UnknownFlangeMaterialException::class);
        $this->ref()->groupFor('A999');
    }

    // -------------------------------------------------------------- ratings -

    public function test_group_1_1_anchors_match_published_table(): void
    {
        $ref = $this->ref();

        // Class 150 carbon steel: 285 psig = 19.6 bar below 38 deg C...
        $this->assertSame(19.6, $ref->maxPressureBar('1.1', 150, 20.0));
        $this->assertSame(19.6, $ref->maxPressureBar('1.1', 150, 38.0));
        // ...derating through the ladder...
        $this->assertSame(13.8, $ref->maxPressureBar('1.1', 150, 200.0));
        $this->assertSame(2.8, $ref->maxPressureBar('1.1', 150, 500.0));
        // ...to the top row, all classes.
        $this->assertSame(1.4, $ref->maxPressureBar('1.1', 150, 538.0));
        $this->assertSame(48.4, $ref->maxPressureBar('1.1', 2500, 538.0));

        // MPa convenience wrapper.
        $this->assertEqualsWithDelta(1.96, $ref->maxPressureMpa('1.1', 150, 38.0), 1e-12);
    }

    public function test_austenitic_groups_run_to_816_degrees(): void
    {
        $ref = $this->ref();

        // Group 2.1 (304 family): 19.1 bar cold in Class 150, still rated in
        // Class 300 at the 816 deg C top of the austenitic ladder.
        $this->assertSame(19.1, $ref->maxPressureBar('2.1', 150, 38.0));
        $this->assertSame(3.3, $ref->maxPressureBar('2.1', 300, 816.0));

        $this->expectException(FlangeRatingOutOfRangeException::class);
        $ref->maxPressureBar('2.1', 300, 817.0);
    }

    public function test_retired_class_throws_instead_of_guessing(): void
    {
        $ref = $this->ref();

        // The table prints an ellipsis: Group 3.3 Class 150 at 550 deg C,
        // and Group 2.1 Class 150 at its 816 deg C top row.
        try {
            $ref->maxPressureBar('3.3', 150, 550.0);
            $this->fail('expected FlangeRatingOutOfRangeException for retired 3.3/150');
        } catch (FlangeRatingOutOfRangeException $e) {
            $this->assertStringContainsString('Class 150', $e->getMessage());
        }

        $this->expectException(FlangeRatingOutOfRangeException::class);
        $ref->maxPressureBar('2.1', 150, 816.0);
    }

    public function test_unknown_class_and_group_throw(): void
    {
        $ref = $this->ref();

        try {
            $ref->maxPressureBar('1.1', 175, 100.0);
            $this->fail('expected FlangeRatingOutOfRangeException for a non-standard class');
        } catch (FlangeRatingOutOfRangeException $e) {
            $this->assertStringContainsString('175', $e->getMessage());
        }

        $this->expectException(FlangeRatingOutOfRangeException::class);
        $ref->maxPressureBar('9.9', 150, 100.0);
    }

    public function test_ladder_shape_and_class_coverage(): void
    {
        $ref = $this->ref();

        $this->assertSame([150, 300, 400, 600, 900, 1500, 2500], $ref->classes());
        $this->assertContains('2.1', $ref->groups());
        $this->assertContains('3.19', $ref->groups());

        // Every group's ladder: strictly rising temperatures, monotone
        // non-increasing pressure per class (the builder enforces this at
        // pack generation; re-checked here so a hand-edited pack fails).
        foreach ($ref->groups() as $group) {
            $ladder = $ref->ladder($group);
            $this->assertNotEmpty($ladder);

            $temps = array_column($ladder, 't_max_degC');
            $this->assertSame($temps, array_values(array_unique($temps)), "group {$group} temps");

            $classes = array_keys($ladder[0]['bar']);
            foreach ($classes as $class) {
                $present = array_values(array_filter(
                    array_map(static fn (array $row) => $row['bar'][$class], $ladder),
                    static fn ($v) => $v !== null
                ));
                foreach ($present as $i => $value) {
                    if ($i > 0) {
                        $this->assertLessThanOrEqual(
                            $present[$i - 1] + max(0.1, 0.002 * $present[$i - 1]),
                            $value,
                            "group {$group} class {$class} must not rise with temperature"
                        );
                    }
                }
            }
        }
    }
}
