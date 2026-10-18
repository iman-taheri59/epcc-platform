<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Reference\Exceptions\UnknownMaterialException;
use App\Support\Reference\MaterialReference;
use PHPUnit\Framework\TestCase;

/**
 * ASME B31.3-2024 materials reference pack accessor.
 *
 * Every expected value below is either a raw row of the extracted Table A-1
 * (position-parsed from the owner-uploaded PDF, anchors: Appendix H examples
 * H301/H304) or a direct transcription of Tables 304.1.1-1, 302.3.5-1 and
 * A-3. Requirement: ADR-008 s9.3 Step B (P-24).
 */
class MaterialReferenceTest extends TestCase
{
    private function ref(): MaterialReference
    {
        return new MaterialReference(dirname(__DIR__, 3) . '/standards-packs/reference');
    }

    // --------------------------------------------------------- find/S -----

    public function test_a106_b_stress_matches_table_row(): void
    {
        $ref = $this->ref();

        // Table A-1 line 33: 415/240 MPa, max 593 deg C.
        $material = $ref->find('A106', 'B');
        $this->assertSame(415, $material->tensileMpa);
        $this->assertSame(240, $material->yieldMpa);
        $this->assertSame(593, $material->maxTempC);

        $this->assertSame(138.0, $ref->allowableStress('A106', 'B', 200.0));
        $this->assertSame(138.0, $ref->allowableStress('A106', 'B', 40.0));
        $this->assertSame(132.0, $ref->allowableStress('A106', 'B', 250.0));
        // Linear interpolation between columns (pack's stated semantics).
        $this->assertEqualsWithDelta(135.0, $ref->allowableStress('A106', 'B', 225.0), 1e-9);
    }

    /** H301: API 5L Gr A seamless, S = 110 MPa at 200 deg C. */
    public function test_api5l_grade_a_anchor_from_appendix_h301(): void
    {
        $this->assertSame(110.0, $this->ref()->allowableStress('API5L', 'A', 200.0));
        $this->assertSame(110.0, $this->ref()->allowableStress('API 5L', 'A', 100.0));
    }

    public function test_api5l_x_grades_resolve_via_iso_alias(): void
    {
        $ref = $this->ref();

        // Table A-1 publishes ISO names: X42 = L290, X60 = L415.
        // Both have PSL1 and PSL2 entries: never choose a PSL implicitly.
        $this->assertSame('L290', $ref->find('API5L', 'X42', 'PSL1')->typeGrade);
        $this->assertSame('L415', $ref->find('API5L', 'X60', 'PSL1')->typeGrade);
        $this->assertSame(138.0, $ref->allowableStress('API5L', 'X42', 200.0, 'PSL1'));

        // Explicit PSL picks the PSL row; ISO name and alias both work.
        $this->assertSame('PSL2', $ref->find('API5L', 'X42', 'PSL2')->psl);
        $this->assertSame('PSL2', $ref->find('API5L', 'L290', 'PSL2')->psl);
        $this->assertSame(138.0, $ref->allowableStress('API5L', 'L290', 250.0, 'PSL2')); // max use is 260 C
    }

    public function test_austenitic_ladder_uses_32_columns(): void
    {
        $ref = $this->ref();

        // TP304: 138 flat to 150 deg C, then falling - raw A-1 row, p234.
        $this->assertSame(138.0, $ref->allowableStress('A312', 'TP304', 150.0));
        $this->assertSame(129.0, $ref->allowableStress('A312', 'TP304', 200.0));
        $this->assertSame(11.2, $ref->allowableStress('A312', 'TP304', 800.0));
        // Interpolation near the top of the ladder.
        $this->assertEqualsWithDelta(10.58, $ref->allowableStress('A312', 'TP304', 810.0), 1e-9);
    }

    public function test_temperature_above_max_use_throws(): void
    {
        $this->expectException(UnknownMaterialException::class);
        $this->ref()->allowableStress('A106', 'B', 700.0); // max 593
    }

    public function test_temperature_above_last_column_throws(): void
    {
        $this->expectException(UnknownMaterialException::class);
        $this->ref()->allowableStress('A312', 'TP304', 817.0); // max use 816, last column 825
    }

    public function test_temperature_past_publication_range_throws(): void
    {
        // L290 PSL2 publishes values only to 300 deg C and allows use to
        // 260 deg C; 350 deg C is refused (never extrapolated).
        $this->expectException(UnknownMaterialException::class);
        $this->ref()->allowableStress('API5L', 'L290', 350.0, 'PSL2');
    }

    public function test_grade_with_several_psl_levels_requires_choice(): void
    {
        $this->expectException(UnknownMaterialException::class);
        $this->ref()->find('API5L', 'L290'); // PSL1 and PSL2 both published
    }

    public function test_unknown_material_throws(): void
    {
        $this->expectException(UnknownMaterialException::class);
        $this->ref()->find('A999', 'X');
    }

    public function test_section_nine_identity_p_no_and_group_no(): void
    {
        $ref = $this->ref();

        // P-No: the column Table A-1 itself prints (raw rows pp.208-220).
        $this->assertSame('1', $ref->find('A106', 'B')->pNo);
        $this->assertSame('1', $ref->find('API5L', 'X42', 'PSL2')->pNo);
        $this->assertSame('8', $ref->find('A312', 'TP304')->pNo);
        $this->assertSame('4', $ref->find('A335', 'P11')->pNo);
        $this->assertSame('5A', $ref->find('A335', 'P22')->pNo);
        $this->assertSame('5B', $ref->find('A335', 'P5')->pNo);
        $this->assertSame('15E', $ref->find('A335', 'P91')->pNo);

        // Group No: Section IX Table QW/QB-422 (2021, owner-uploaded).
        $this->assertSame('1', $ref->find('A106', 'B')->groupNo);
        $this->assertSame('2', $ref->find('A106', 'C')->groupNo);
        $this->assertSame('2', $ref->find('A516', '70')->groupNo);
        $this->assertSame('1', $ref->find('A516', '65')->groupNo);
        $this->assertSame('2', $ref->find('API5L', 'X60', 'PSL2')->groupNo); // L415
        $this->assertSame('3', $ref->find('API5L', 'X70', 'PSL1')->groupNo); // L485
        $this->assertSame('1', $ref->find('A312', 'TP304')->groupNo);
        $this->assertSame('1', $ref->find('A335', 'P91')->groupNo);
    }

    // ------------------------------------------------------------ Ej -------

    public function test_quality_factors_by_type(): void
    {
        $ref = $this->ref();

        $this->assertSame(1.00, $ref->ej('A53', 'S'));
        $this->assertSame(0.85, $ref->ej('A53', 'E'));
        $this->assertSame(0.60, $ref->ej('A53', 'F'));
        $this->assertSame(1.00, $ref->ej('A106'));
        $this->assertSame(1.00, $ref->ej('A335'));
    }

    public function test_quality_factors_by_description(): void
    {
        $ref = $this->ref();

        $this->assertSame(1.00, $ref->ej('API5L', null, 'Seamless'));
        $this->assertSame(0.85, $ref->ej('API5L', null, 'Electric welded pipe'));
        $this->assertSame(0.95, $ref->ej('API5L', null, 'double butt'));
        $this->assertSame(1.00, $ref->ej('A312', null, '100% radiographed'));
        $this->assertSame(0.80, $ref->ej('A312', null, 'single butt'));
        $this->assertSame(1.00, $ref->ej('A516', null, 'Plate'));
    }

    public function test_ambiguous_ej_throws_without_narrowing(): void
    {
        $this->expectException(UnknownMaterialException::class);
        $this->ref()->ej('A312');
    }

    // ------------------------------------------------------------ Y --------

    public function test_y_coefficient(): void
    {
        $ref = $this->ref();

        $this->assertSame(0.4, $ref->yCoefficient('ferritic', 200.0));
        $this->assertSame(0.4, $ref->yCoefficient('ferritic', 482.0));
        $this->assertEqualsWithDelta(0.45, $ref->yCoefficient('ferritic', 496.0), 1e-9);
        $this->assertSame(0.5, $ref->yCoefficient('ferritic', 510.0));
        $this->assertSame(0.7, $ref->yCoefficient('ferritic', 700.0)); // >= 677
        $this->assertSame(0.4, $ref->yCoefficient('austenitic', 566.0));
        $this->assertSame(0.5, $ref->yCoefficient('austenitic', 593.0));
    }

    // ------------------------------------------------------------ W --------

    public function test_w_factor(): void
    {
        $ref = $this->ref();

        // Carbon steel: W = 1 through 593 deg C, below-range rule is 1.0.
        $this->assertSame(1.0, $ref->wFactor('carbon_steel', 200.0));
        $this->assertSame(1.0, $ref->wFactor('carbon_steel', 593.0));
        // CrMo interpolation between 510 (0.86) and 538 (0.82).
        $this->assertEqualsWithDelta(0.84, $ref->wFactor('cr_mo', 524.0), 1e-9);
        // CSEF N+T starts at 510; below that, General Note (a) gives 1.0.
        $this->assertSame(1.0, $ref->wFactor('csef_normalised_and_tempered', 450.0));
        $this->assertSame(1.0, $ref->wFactor('csef_normalised_and_tempered', 510.0));
        // Filled austenitic runs to 816 with 0.5 at the end.
        $this->assertSame(0.5, $ref->wFactor('filled_austenitic', 816.0));
    }

    public function test_w_factor_above_range_throws(): void
    {
        $this->expectException(UnknownMaterialException::class);
        $this->ref()->wFactor('carbon_steel', 700.0); // dashes after 593
    }

    // ------------------------------------------------- mill tolerance -----

    public function test_mill_tolerance_factor_is_owner_decision(): void
    {
        $this->assertSame(0.875, $this->ref()->millToleranceFactor());
    }

    public function test_pack_identity(): void
    {
        $this->assertStringContainsString('B31.3-2024', $this->ref()->code());
    }
}
