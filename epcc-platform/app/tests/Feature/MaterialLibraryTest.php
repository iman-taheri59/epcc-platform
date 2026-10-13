<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Reference\MaterialLibrary;
use Tests\TestCase;

/**
 * The material library, ASME B31.3 Table A-1 groupings - pipe group.
 *
 * Reference data only: no database, no tenant binding. The library is a file
 * on disk with no writer, so these tests are about the SHAPE of the cascade
 * and about the traps in it, not about persistence.
 */
final class MaterialLibraryTest extends TestCase
{
    private function lib(): MaterialLibrary
    {
        return app(MaterialLibrary::class);
    }

    // ------------------------------------------------- the four-step cascade

    public function test_the_cascade_narrows_from_component_type_to_product_form(): void
    {
        $lib = $this->lib();

        // 1. the user picks PIPE
        $groups = array_column($lib->groupsForComponentType('PIPE'), 'code');
        $this->assertContains('PIPE', $groups);

        // 2. the groups offer their specs
        $specs = array_column($lib->specsIn('PIPE'), 'code');
        $this->assertContains('A106', $specs);
        $this->assertContains('A312', $specs);

        // 3. a spec offers only ITS OWN grades
        $grades = array_column($lib->gradesOf('PIPE', 'A106'), 'code');
        $this->assertSame(['A', 'B', 'C'], $grades);
        $this->assertNotContains('TP316', $grades);

        // 4. and only the forms it is actually supplied in
        $forms = array_column($lib->productFormsFor('PIPE', 'A106'), 'code');
        $this->assertSame(['SMLS'], $forms, 'A106 is a seamless specification');
    }

    public function test_a_group_is_not_offered_for_a_component_it_cannot_supply(): void
    {
        $lib = $this->lib();

        // The pipe group must never appear for a flange, or the second dropdown
        // lets a user build one out of A106. (Forgings DO supply flanges - this
        // test guards the pipe group specifically, not the emptiness of the list.)
        $this->assertNotContains('PIPE', array_column($lib->groupsForComponentType('FLANGE'), 'code'));

        // This line used to assert that nothing supplied a GASKET. That was
        // true until the gasket group shipped - the test was right about the
        // world, and the world changed. Rewritten to the intent rather than
        // deleted: a gasket comes from the gasket group and from nowhere else,
        // least of all from pipe or plate.
        $this->assertSame(['GASKET'], array_column($lib->groupsForComponentType('GASKET'), 'code'));

        // INSTRUMENT is now the only unclaimed component type in the library.
        $this->assertSame([], $lib->groupsForComponentType('INSTRUMENT'));
    }

    // --------------------------------------------------------- the zero case

    public function test_a_specification_may_legitimately_have_no_grades(): void
    {
        // Guarded here because the pipe group happens to have none today. If a
        // gradeless spec is ever added, specHasGrades() is the contract the UI
        // relies on to DISABLE the control rather than show an empty list.
        $lib = $this->lib();

        foreach ($lib->specsIn('PIPE') as $spec) {
            $this->assertSame(
                $spec['hasGrades'],
                $lib->specHasGrades('PIPE', $spec['code']),
                "hasGrades disagrees with specHasGrades() for {$spec['code']}",
            );
        }
    }

    public function test_a_null_grade_is_refused_for_a_spec_that_has_grades(): void
    {
        // The dangerous direction: leaving the grade empty on A106 must NOT
        // silently resolve, or an MTO row would carry "some carbon steel".
        $this->assertNull($this->lib()->grade('PIPE', 'A106', null));
        $this->assertFalse($this->lib()->isKnown('PIPE', 'A106', null));
    }

    // ------------------------------------ grade identity is code + class

    public function test_two_grades_share_a_code_and_are_told_apart_by_class(): void
    {
        // A358 304 exists as Class 1 (radiographed) and Class 3 (not). Same
        // code. If identity were the code alone, one would overwrite the other
        // and an MTO row could not say which was bought.
        $keys = array_column($this->lib()->gradesOf('PIPE', 'A358'), 'key');

        $this->assertContains('304 CL 1', $keys);
        $this->assertContains('304 CL 3', $keys);
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_the_class_changes_the_acceptance(): void
    {
        $lib = $this->lib();

        $cl1 = $lib->properties('PIPE', 'A358', '304 CL 1');
        $cl3 = $lib->properties('PIPE', 'A358', '304 CL 3');

        $this->assertTrue($cl1['radiographyRequired']['value']);
        $this->assertFalse($cl3['radiographyRequired']['value']);
    }

    public function test_a_grade_key_without_its_class_does_not_resolve(): void
    {
        // "304" alone is ambiguous in A358 and must not silently pick Class 1.
        $this->assertNull($this->lib()->grade('PIPE', 'A358', '304'));
    }

    // ----------------------------------------- the product form carries Ew

    public function test_one_specification_is_supplied_in_several_forms(): void
    {
        // A53 is the reason the product form is a fourth choice rather than a
        // property of the grade: Type S, E and F are the same steel.
        $forms = array_column($this->lib()->productFormsFor('PIPE', 'A53'), 'code');

        $this->assertContains('SMLS', $forms);
        $this->assertContains('ERW', $forms);
        $this->assertContains('FBW', $forms);
    }

    public function test_the_form_not_the_grade_sets_the_weld_joint_quality_factor(): void
    {
        $lib = $this->lib();

        $this->assertSame(1.0, $lib->weldJointQualityFactor('SMLS'));
        $this->assertSame(0.85, $lib->weldJointQualityFactor('ERW'));
        $this->assertSame(0.6, $lib->weldJointQualityFactor('FBW'));

        // Fully radiographed fusion weld earns the same credit as seamless.
        $this->assertSame(1.0, $lib->weldJointQualityFactor('EFW_RT'));
        $this->assertSame(0.8, $lib->weldJointQualityFactor('EFW'));
    }

    public function test_an_unknown_form_yields_null_and_never_a_default_of_one(): void
    {
        // Defaulting to 1.0 would quietly delete the penalty a welded seam
        // carries and overstate the pressure the pipe may hold.
        $this->assertNull($this->lib()->weldJointQualityFactor('NOT_A_FORM'));
    }

    public function test_a_form_the_specification_does_not_offer_is_not_known(): void
    {
        $lib = $this->lib();

        // A106 is seamless only; claiming an ERW A106 is a real procurement error.
        $this->assertTrue($lib->isKnown('PIPE', 'A106', 'B', 'SMLS'));
        $this->assertFalse($lib->isKnown('PIPE', 'A106', 'B', 'ERW'));
    }

    // -------------------------------------------- only the B16.5 group is verified

    public function test_the_only_verified_property_in_the_pack_is_the_b165_group(): void
    {
        // ASME B16.5-2025 was purchased and read on 2026-09-24, so the B16.5
        // material group is now citable. NOTHING ELSE IS. Allowable stresses,
        // yields, tensiles and the rest still come from public sources and
        // must stay unverified, or one purchase silently launders the lot.
        $lib = $this->lib();
        $checked = 0;
        $verified = 0;

        foreach ($lib->groups() as $group) {
            foreach ($lib->specsIn($group['code']) as $spec) {
                foreach ($lib->gradesOf($group['code'], $spec['code']) as $grade) {
                    $props = $lib->properties($group['code'], $spec['code'], $grade['key']);
                    $this->assertNotSame([], $props);
                    $checked += count($props);

                    foreach ($lib->verifiedProperties($group['code'], $spec['code'], $grade['key']) as $name => $_) {
                        $verified++;
                        $this->assertContains(
                            $spec['code'] === 'API600' ? $name : 'b165MaterialGroup',
                            // B16.5-2025 gave us the material group. API
                            // 600-2021 gave us the whole of Table 8, so every
                            // property of an API 600 trim is now a citation
                            // too - and nothing else in the library is.
                            [$name, 'b165MaterialGroup'],
                            "{$spec['code']} {$grade['key']} claims a verified {$name}, "
                            . 'but only the B16.5 group and the API 600 trim chart have '
                            . 'been read from a purchased document',
                        );
                    }
                }
            }
        }

        $this->assertGreaterThan(100, $checked, 'the sweep must actually have read the pack');
        $this->assertGreaterThan(50, $verified, 'the sweep must actually have seen the verified groups');
    }

    public function test_allowable_stress_is_absent_from_the_pack_entirely(): void
    {
        // Copyrighted, and a wrong S value corrupts a wall thickness silently.
        // It is loaded separately once someone transcribes it from the standard.
        $raw = (string) file_get_contents(
            base_path('../standards-packs/reference/materials.ref.json'),
        );

        $this->assertStringNotContainsString('"byTemperature"', $raw);
        $this->assertStringNotContainsString('"allowableStress"', $raw);
    }

    // ------------------------------------------------- what an MTO row stores

    public function test_a_stored_selection_is_four_strings_and_survives_a_reorder(): void
    {
        $lib = $this->lib();

        // What an MTO row would hold:
        [$g, $s, $k, $f] = ['PIPE', 'A312', 'TP316L', 'SMLS'];

        $this->assertTrue($lib->isKnown($g, $s, $k, $f));
        $this->assertSame('ASTM A312 TP316L (SMLS)', $lib->describe($g, $s, $k, $f));
    }

    public function test_the_low_carbon_grade_is_its_own_grade(): void
    {
        $lib = $this->lib();

        // 316 and 316L differ in strength; conflating them would pass an
        // incoming-material inspection that should have failed.
        $p316 = $lib->properties('PIPE', 'A312', 'TP316');
        $p316l = $lib->properties('PIPE', 'A312', 'TP316L');

        $this->assertSame(515, $p316['minTensileStrength']['value']);
        $this->assertSame(485, $p316l['minTensileStrength']['value']);
        $this->assertNotSame(
            $p316['unsNumber']['value'],
            $p316l['unsNumber']['value'],
        );
    }

    public function test_a_selection_the_pack_no_longer_knows_is_reported_not_guessed(): void
    {
        // describe() returns null rather than a half-built label, so a caller
        // can tell "not set" from "set to something withdrawn".
        $this->assertNull($this->lib()->describe('PIPE', 'A106', 'Z'));
        $this->assertNull($this->lib()->describe('PIPE', 'NOPE', 'B'));
        $this->assertNull($this->lib()->describe('NOPE', 'A106', 'B'));
    }

    // ------------------------------------------------------------ bilingual

    public function test_every_name_in_the_library_is_bilingual(): void
    {
        $lib = $this->lib();

        foreach ($lib->groups() as $group) {
            $this->assertNotSame('', trim($group['name']['fa']));

            foreach ($lib->specsIn($group['code']) as $spec) {
                $this->assertNotSame('', trim($spec['title']['fa']), $spec['code']);

                foreach ($lib->gradesOf($group['code'], $spec['code']) as $grade) {
                    $this->assertNotSame('', trim($grade['name']['fa']));
                }

                foreach ($lib->productFormsFor($group['code'], $spec['code']) as $form) {
                    $this->assertNotSame('', trim($form['name']['fa']));
                }
            }
        }
    }

    // ------------------------------------- metallic vs non-metallic routes

    public function test_non_metallic_pipe_is_a_group_of_its_own(): void
    {
        $lib = $this->lib();

        $this->assertTrue($lib->isMetallic('PIPE'));
        $this->assertFalse($lib->isMetallic('PIPE_NONMETALLIC'));

        // Both are offered for a PIPE component - a firewater line really can
        // be HDPE - so the caller must branch on metallic, not on the group code.
        $groups = $lib->groupsForComponentType('PIPE');
        $this->assertContains('PIPE_NONMETALLIC', array_column($groups, 'code'));
        $this->assertSame(
            [true, false],
            array_column($groups, 'metallic'),
        );
    }

    public function test_a_non_metallic_form_has_no_weld_joint_quality_factor(): void
    {
        $lib = $this->lib();

        // B31.3 Chapter VII rates GRE from a qualified pressure, not S x E x W.
        $this->assertNull($lib->weldJointQualityFactor('FW'));
        $this->assertNull($lib->weldJointQualityFactor('EXTRUDED'));
    }

    public function test_no_factor_is_distinguishable_from_no_such_form(): void
    {
        $lib = $this->lib();

        // Both return null from weldJointQualityFactor(). A caller that cannot
        // tell them apart either rejects valid GRE or swallows a typo.
        $this->assertTrue($lib->isKnownProductForm('FW'));
        $this->assertFalse($lib->isKnownProductForm('FWX'));
        $this->assertNull($lib->weldJointQualityFactor('FW'));
        $this->assertNull($lib->weldJointQualityFactor('FWX'));
    }

    public function test_a_non_metallic_factor_is_never_coerced_to_zero(): void
    {
        // The bug this guards: (float) null is 0.0, which would read as a
        // perfectly valid factor and zero the pressure the line may hold.
        foreach ($this->lib()->productFormsFor('PIPE_NONMETALLIC', 'ISO14692') as $form) {
            $this->assertNull($form['weldJointQualityFactor']);
            $this->assertNotSame(0.0, $form['weldJointQualityFactor']);
        }
    }

    public function test_every_metallic_form_still_carries_a_real_factor(): void
    {
        $lib = $this->lib();

        foreach ($lib->specsIn('PIPE') as $spec) {
            foreach ($lib->productFormsFor('PIPE', $spec['code']) as $form) {
                $this->assertIsFloat(
                    $form['weldJointQualityFactor'],
                    "{$spec['code']} form {$form['code']} lost its factor",
                );
                $this->assertGreaterThan(0.0, $form['weldJointQualityFactor']);
            }
        }
    }

    // --------------------------------------------- galvanising is separate

    public function test_galvanising_is_orthogonal_to_the_product_form(): void
    {
        $lib = $this->lib();

        // A53 is supplied black or hot-dip zinc coated, in ANY of its three
        // forms. Folding it into the form would need six entries instead of
        // three plus two.
        $this->assertSame(['BLACK', 'GALV'], $lib->surfaceConditionsFor('PIPE', 'A53'));
        $this->assertSame(['SMLS', 'ERW', 'FBW'], array_column(
            $lib->productFormsFor('PIPE', 'A53'), 'code',
        ));

        // A106 is not a galvanised product.
        $this->assertSame(['BLACK'], $lib->surfaceConditionsFor('PIPE', 'A106'));
    }

    public function test_a_non_metallic_spec_has_no_metallic_surface_condition(): void
    {
        $this->assertSame(['NONE'], $this->lib()->surfaceConditionsFor('PIPE_NONMETALLIC', 'ISO4427'));
    }

    // ----------------------------------- pipe rolled from plate (A671/2/691)

    public function test_plate_made_pipe_records_no_provenance(): void
    {
        // This test used to assert the opposite: that A672 C70 carried
        // 'ASTM A516 Grade 70' in a basePlateSpecification property. The user
        // ruled that a material is recorded as WHAT IT IS, never as what it
        // used to be - 'ASTM A672 C70 CL 22' already names the acceptance the
        // finished pipe must meet, and the plate it was rolled from adds
        // nothing a requisition, an inspection or a wall thickness calculation
        // can act on. A heat's history lives on the mill certificate, which
        // belongs to the LOT, not to the library. 42 grades carried it; all 42
        // were stripped, and a validator rule now refuses its return under any
        // of its names.
        $props = $this->lib()->properties('PIPE', 'A672', 'C70 CL 22');

        $this->assertArrayNotHasKey('basePlateSpecification', $props);
        $this->assertArrayNotHasKey('madeFrom', $props);

        // What it IS is still fully described.
        $this->assertSame('Carbon steel', $props['nominalComposition']['value']);
    }

    public function test_no_grade_anywhere_records_what_it_was_made_from(): void
    {
        // The sweep that makes the rule above structural rather than anecdotal.
        $lib = $this->lib();
        $banned = ['basePlateSpecification', 'baseMaterial', 'madeFrom',
            'rolledFrom', 'forgedFrom', 'parentMaterial'];

        foreach ($lib->groups() as $group) {
            foreach ($lib->specsIn($group['code']) as $spec) {
                foreach ($lib->gradesOf($group['code'], $spec['code']) as $summary) {
                    $props = $lib->properties($group['code'], $spec['code'], $summary['key']);
                    foreach ($banned as $key) {
                        $this->assertArrayNotHasKey(
                            $key,
                            $props,
                            "{$spec['code']} {$summary['key']} records provenance."
                        );
                    }
                }
            }
        }
    }

    public function test_the_class_is_part_of_the_identity_for_plate_made_pipe(): void
    {
        $lib = $this->lib();

        // Same three letters, three different classes - each a different
        // combination of heat treatment and radiography.
        $keys = array_column($lib->gradesOf('PIPE', 'A672'), 'key');
        $this->assertContains('C70 CL 12', $keys);
        $this->assertContains('C70 CL 22', $keys);
        $this->assertContains('C70 CL 32', $keys);

        // and the bare grade must not resolve
        $this->assertNull($lib->grade('PIPE', 'A672', 'C70'));
    }

    public function test_plate_made_pipe_is_welded_and_never_seamless(): void
    {
        $lib = $this->lib();

        foreach (['A671', 'A672', 'A691'] as $spec) {
            $forms = array_column($lib->productFormsFor('PIPE', $spec), 'code');
            $this->assertNotContains('SMLS', $forms, "{$spec} cannot be seamless");
            $this->assertContains('EFW', $forms);
        }
    }

    // ------------------------------------------------- forgings: the zero case

    public function test_a_gradeless_specification_really_exists_now(): void
    {
        $lib = $this->lib();

        // Until forgings arrived, every spec had grades and the zero branch was
        // only reasoned about. ASTM A105 genuinely has none.
        $this->assertFalse($lib->specHasGrades('FORGINGS', 'A105'));
        $this->assertSame([], $lib->gradesOf('FORGINGS', 'A105'));
    }

    public function test_a_gradeless_specification_resolves_with_a_null_grade(): void
    {
        $lib = $this->lib();

        // The mirror image of the A106 case: here null is the CORRECT answer
        // and must resolve, or a flange could never be entered at all.
        $this->assertSame([], $lib->grade('FORGINGS', 'A105', null));
        $this->assertTrue($lib->isKnown('FORGINGS', 'A105', null));
        $this->assertTrue($lib->isKnown('FORGINGS', 'A105', null, 'FORGED'));
        $this->assertSame('ASTM A105 (FORGED)', $lib->describe('FORGINGS', 'A105', null, 'FORGED'));
    }

    public function test_a_gradeless_specification_still_refuses_an_invented_grade(): void
    {
        // "A105 Gr B" is a real thing people write on requisitions. It is wrong.
        $this->assertNull($this->lib()->grade('FORGINGS', 'A105', 'B'));
        $this->assertFalse($this->lib()->isKnown('FORGINGS', 'A105', 'B'));
    }

    // --------------------------------------------- forgings: the cascade

    public function test_a_flange_is_sourced_from_forgings_and_not_from_pipe(): void
    {
        $lib = $this->lib();

        $groups = array_column($lib->groupsForComponentType('FLANGE'), 'code');
        $this->assertSame(['FORGINGS'], $groups);

        // and the pipe group still does not offer itself for one
        $this->assertNotContains('PIPE', $groups);
    }

    public function test_a_small_bore_fitting_can_be_forged_or_wrought(): void
    {
        // B16.11 socket-weld and threaded elbows ARE forgings, so the forging
        // group claims ELBOW even though butt-weld elbows will come from the
        // fittings group later.
        $groups = array_column($this->lib()->groupsForComponentType('ELBOW'), 'code');
        $this->assertContains('FORGINGS', $groups);
    }

    // ------------------------------------ A182 is one spec, two families

    public function test_a182_is_one_specification_covering_two_families(): void
    {
        $lib = $this->lib();

        // A purchase document says "A182 F316", never "A182 austenitic", so it
        // is one spec - but welding and PWHT branch on the family.
        $fams = [];
        foreach ($lib->gradesOf('FORGINGS', 'A182') as $g) {
            $props = $lib->properties('FORGINGS', 'A182', $g['key']);
            $fams[$props['family']['value']] = true;
        }

        $this->assertArrayHasKey('FERRITIC', $fams);
        $this->assertArrayHasKey('AUSTENITIC', $fams);
        $this->assertArrayHasKey('DUPLEX', $fams);
    }

    public function test_the_ferritic_and_austenitic_grades_are_told_apart(): void
    {
        $lib = $this->lib();

        $f22 = $lib->properties('FORGINGS', 'A182', 'F22 CL 3');
        $f316 = $lib->properties('FORGINGS', 'A182', 'F316');

        $this->assertSame('FERRITIC', $f22['family']['value']);
        $this->assertSame('AUSTENITIC', $f316['family']['value']);

        // The P-number is what a WPS is written against, and it differs.
        $this->assertSame('5A', $f22['pNumber']['value']);
        $this->assertSame('8', $f316['pNumber']['value']);
    }

    public function test_a_duplex_forging_is_not_filed_as_austenitic(): void
    {
        // F51/F53/F55 sit in the austenitic ASTM table but are duplex, and
        // their PWHT and welding rules are different again.
        $props = $this->lib()->properties('FORGINGS', 'A182', 'F53');

        $this->assertSame('DUPLEX', $props['family']['value']);
        $this->assertSame('10H', $props['pNumber']['value']);
    }

    // --------------------------------- the flange matches the pipe it bolts to

    public function test_a_line_pipe_flange_records_the_pipe_grade_it_matches(): void
    {
        $lib = $this->lib();

        // F60 flanges go on X60 pipe. Both are now in the library, so the
        // pairing can be checked rather than remembered.
        $props = $lib->properties('FORGINGS', 'A694', 'F60');
        $this->assertSame('API 5L X60', $props['matchingLinePipeGrade']['value']);

        $pipeGrades = array_column($lib->gradesOf('PIPE', 'API5L'), 'code');
        $this->assertContains('X60', $pipeGrades);
    }

    // ------------------------------------- a rating is not a material property

    public function test_no_grade_carries_a_pressure_rating(): void
    {
        $lib = $this->lib();

        // A105 is A105 whether the flange is 150# or 1500#. The rating lives on
        // the component (B16.5) and piping_classes.pressure_rating already
        // holds it; a second copy here would diverge.
        foreach ($lib->groups() as $group) {
            foreach ($lib->specsIn($group['code']) as $spec) {
                foreach ($lib->gradesOf($group['code'], $spec['code']) as $grade) {
                    foreach ($lib->properties($group['code'], $spec['code'], $grade['key']) as $name => $_) {
                        $this->assertStringNotContainsStringIgnoringCase(
                            'rating', $name,
                            "{$spec['code']} {$grade['key']} carries {$name}",
                        );
                    }
                }
            }
        }
    }

    // ------------------------------------------------ galvanised forgings

    public function test_a_forging_can_be_galvanised_like_the_pipe_it_joins(): void
    {
        $lib = $this->lib();

        // A galvanised A53 line needs galvanised A105 flanges.
        $this->assertSame(['BLACK', 'GALV'], $lib->surfaceConditionsFor('FORGINGS', 'A105'));
        $this->assertSame(['BLACK'], $lib->surfaceConditionsFor('FORGINGS', 'A182'));
    }

    // ------------------------------- two groups may supply one component type

    public function test_an_elbow_can_come_from_two_groups_and_the_user_chooses(): void
    {
        $lib = $this->lib();

        // A butt-weld A234 elbow and a socket-weld A105 one are both elbows.
        // The material group is HOW the user says which, so the overlap is the
        // feature, not a clash - the cascade must offer both.
        $groups = array_column($lib->groupsForComponentType('ELBOW'), 'code');

        $this->assertContains('FITTINGS', $groups);
        $this->assertContains('FORGINGS', $groups);
        $this->assertNotContains('PIPE', $groups);
    }

    public function test_the_two_groups_offer_genuinely_different_specs(): void
    {
        $lib = $this->lib();

        $forged = array_column($lib->specsIn('FORGINGS'), 'code');
        $wrought = array_column($lib->specsIn('FITTINGS'), 'code');

        // No specification may sit in both groups, or a selection would be
        // ambiguous about which group it came from.
        $this->assertSame([], array_intersect($forged, $wrought));
    }

    public function test_a_flange_still_comes_only_from_forgings(): void
    {
        // Fittings must not have widened into flanges while claiming elbows.
        $this->assertSame(
            ['FORGINGS'],
            array_column($this->lib()->groupsForComponentType('FLANGE'), 'code'),
        );
    }

    // ------------------------- the seamless/welded axis stays the product form

    public function test_a403_seamless_and_welded_are_forms_not_classes(): void
    {
        $lib = $this->lib();

        // WP-S / WP-W / WP-WX in the specification are seamless, welded and
        // welded-with-full-radiography. Put in classOrType they would be a
        // second axis for one fact and the Ew lookup would miss them.
        foreach ($lib->gradesOf('FITTINGS', 'A403') as $grade) {
            $this->assertNull(
                $grade['classOrType'],
                "A403 {$grade['code']} encodes its form in the class",
            );
        }

        $forms = array_column($lib->productFormsFor('FITTINGS', 'A403'), 'code');
        $this->assertContains('SMLS', $forms);
        $this->assertContains('EFW', $forms);
        $this->assertContains('EFW_RT', $forms);
    }

    public function test_a_welded_fitting_is_discounted_and_a_seamless_one_is_not(): void
    {
        $lib = $this->lib();

        // The whole reason the form is separate: same grade, different Ew.
        $this->assertSame(1.0, $lib->weldJointQualityFactor('SMLS'));
        $this->assertSame(0.8, $lib->weldJointQualityFactor('EFW'));
        $this->assertSame(1.0, $lib->weldJointQualityFactor('EFW_RT'));
    }

    // --------------------------------------- fittings match the pipe they join

    public function test_each_fitting_spec_has_a_pipe_counterpart_in_the_library(): void
    {
        $lib = $this->lib();

        // A real line is specified as a set: pipe, fitting, flange. If the
        // library can furnish the pipe but not the fitting, an MTO cannot be
        // completed without leaving the library.
        $pipeFamilies = [];
        foreach ($lib->specsIn('PIPE') as $spec) {
            foreach ($lib->gradesOf('PIPE', $spec['code']) as $g) {
                $props = $lib->properties('PIPE', $spec['code'], $g['key']);
                $pipeFamilies[$props['family']['value'] ?? 'FERRITIC'] = true;
            }
        }

        $fittingFamilies = [];
        foreach ($lib->specsIn('FITTINGS') as $spec) {
            foreach ($lib->gradesOf('FITTINGS', $spec['code']) as $g) {
                $props = $lib->properties('FITTINGS', $spec['code'], $g['key']);
                $fittingFamilies[$props['family']['value']] = true;
            }
        }

        foreach (['FERRITIC', 'AUSTENITIC', 'DUPLEX'] as $family) {
            $this->assertArrayHasKey($family, $fittingFamilies, "no {$family} fitting");
        }
    }

    public function test_the_line_pipe_set_is_complete_across_three_groups(): void
    {
        $lib = $this->lib();

        // X60 pipe, F60 flange, WPHY-60 fitting are one line. All three sides
        // now exist and each names the pipe grade it belongs with.
        $this->assertContains('X60', array_column($lib->gradesOf('PIPE', 'API5L'), 'code'));

        $flange = $lib->properties('FORGINGS', 'A694', 'F60');
        $fitting = $lib->properties('FITTINGS', 'A860', 'WPHY-60');

        $this->assertSame('API 5L X60', $flange['matchingLinePipeGrade']['value']);
        $this->assertSame('API 5L X60', $fitting['matchingLinePipeGrade']['value']);
    }

    public function test_the_low_temperature_set_is_complete(): void
    {
        $lib = $this->lib();

        // A333 Gr 6 pipe, A350 LF2 flanges, A420 WPL6 fittings - the usual
        // cold-service trio. All three must be selectable.
        $this->assertTrue($lib->isKnown('PIPE', 'A333', '6', 'SMLS'));
        $this->assertTrue($lib->isKnown('FORGINGS', 'A350', 'LF2 CL 1', 'FORGED'));
        $this->assertTrue($lib->isKnown('FITTINGS', 'A420', 'WPL6', 'SMLS'));

        // and every A420 grade is impact tested, like the pipe it joins
        foreach ($lib->gradesOf('FITTINGS', 'A420') as $g) {
            $props = $lib->properties('FITTINGS', 'A420', $g['key']);
            $this->assertTrue($props['impactTestRequired']['value']);
        }
    }

    // ------------------------------------------------ castings: valve bodies

    public function test_a_valve_body_is_sourced_from_castings(): void
    {
        $lib = $this->lib();

        // VALVE was unclaimed by any group until castings arrived.
        foreach (['VALVE', 'CONTROL_VALVE', 'THREE_WAY_VALVE'] as $type) {
            $this->assertSame(
                ['CASTINGS'],
                array_column($lib->groupsForComponentType($type), 'code'),
                "{$type} is not sourced from castings",
            );
        }
    }

    public function test_the_default_carbon_steel_valve_body_resolves(): void
    {
        $lib = $this->lib();

        // A216 WCB is the carbon steel valve body of the industry.
        $this->assertTrue($lib->isKnown('CASTINGS', 'A216', 'WCB', 'CAST'));
        $this->assertSame('ASTM A216 WCB (CAST)', $lib->describe('CASTINGS', 'A216', 'WCB', 'CAST'));
    }

    public function test_a_casting_is_never_offered_as_seamless_or_welded(): void
    {
        $lib = $this->lib();

        // It is poured to shape. Offering SMLS would imply a rolling process
        // that never happened.
        foreach ($lib->specsIn('CASTINGS') as $spec) {
            $forms = array_column($lib->productFormsFor('CASTINGS', $spec['code']), 'code');
            $this->assertSame(['CAST'], $forms, "{$spec['code']} offers a wrought form");
        }
    }

    // ------------------------ the cast form has no seam, which is not the same
    // ------------------------ as saying no derating applies

    public function test_a_casting_has_no_seam_to_discount(): void
    {
        // Ew is 1.0 because there is no longitudinal weld, exactly as for
        // seamless and forged.
        $this->assertSame(1.0, $this->lib()->weldJointQualityFactor('CAST'));
    }

    public function test_the_pack_records_that_a_casting_factor_is_not_the_whole_story(): void
    {
        // B31.3 para 302.3.3 applies a separate CASTING QUALITY FACTOR Ec that
        // this pack does not hold. Without this note someone will read Ew=1.0
        // as "castings are not derated", which is wrong.
        $raw = (string) file_get_contents(
            base_path('../standards-packs/reference/materials.ref.json'),
        );

        $this->assertStringContainsString('castingQualityFactor', $raw);
        $this->assertStringContainsString('302.3.3', $raw);
    }

    // ------------------------------------ cast and wrought name each other

    public function test_a_cast_grade_names_the_wrought_grade_it_corresponds_to(): void
    {
        $lib = $this->lib();

        // A requisition often says TP316 and the material arrives marked CF8M.
        $props = $lib->properties('CASTINGS', 'A351', 'CF8M');
        $this->assertSame('TP316', $props['wroughtEquivalent']['value']);

        // and the grade it names is really in the library
        $this->assertContains('TP316', array_column($lib->gradesOf('PIPE', 'A312'), 'code'));
    }

    public function test_every_wrought_equivalent_that_looks_like_a_grade_resolves(): void
    {
        $lib = $this->lib();

        $known = [];
        foreach ($lib->groups() as $group) {
            foreach ($lib->specsIn($group['code']) as $spec) {
                foreach ($lib->gradesOf($group['code'], $spec['code']) as $g) {
                    $known[$g['code']] = true;
                    $props = $lib->properties($group['code'], $spec['code'], $g['key']);
                    if (isset($props['unsNumber'])) {
                        $known[$props['unsNumber']['value']] = true;
                    }
                }
            }
        }

        $checked = 0;
        foreach ($lib->groups() as $group) {
            foreach ($lib->specsIn($group['code']) as $spec) {
                foreach ($lib->gradesOf($group['code'], $spec['code']) as $g) {
                    $props = $lib->properties($group['code'], $spec['code'], $g['key']);
                    if (! isset($props['wroughtEquivalent'])) {
                        continue;
                    }
                    $v = $props['wroughtEquivalent']['value'];
                    if (str_starts_with($v, 'TP') || str_starts_with($v, 'S3')) {
                        $this->assertArrayHasKey($v, $known, "{$g['code']} names {$v}");
                        $checked++;
                    }
                }
            }
        }

        $this->assertGreaterThan(3, $checked, 'the sweep found nothing to check');
    }

    // --------------------------------------------- the sets close once more

    public function test_the_cold_service_set_now_includes_a_valve_body(): void
    {
        $lib = $this->lib();

        // A333 Gr 6 pipe, A350 LF2 flange, A420 WPL6 fitting, A352 LCC body.
        $this->assertTrue($lib->isKnown('PIPE', 'A333', '6', 'SMLS'));
        $this->assertTrue($lib->isKnown('FORGINGS', 'A350', 'LF2 CL 1', 'FORGED'));
        $this->assertTrue($lib->isKnown('FITTINGS', 'A420', 'WPL6', 'SMLS'));
        $this->assertTrue($lib->isKnown('CASTINGS', 'A352', 'LCC', 'CAST'));

        foreach ($lib->gradesOf('CASTINGS', 'A352') as $g) {
            $props = $lib->properties('CASTINGS', 'A352', $g['key']);
            $this->assertTrue($props['impactTestRequired']['value']);
        }
    }

    public function test_nickel_alloys_enter_the_library_as_their_own_family(): void
    {
        $lib = $this->lib();

        // Monel and Hastelloy are neither ferritic nor austenitic steel, and
        // their welding rules are different again.
        $props = $lib->properties('CASTINGS', 'A494', 'M35-1');
        $this->assertSame('NICKEL', $props['family']['value']);
        $this->assertSame('42', $props['pNumber']['value']);
    }

    public function test_the_library_reports_where_it_came_from(): void
    {
        $this->assertStringContainsString('B31.3', $this->lib()->provenance());
    }

    // ------------------------------------------------------------- plate ---

    public function test_plate_supplies_line_blanks_alongside_forgings(): void
    {
        // Two groups may supply one component type. A B16.48 line blank is cut
        // from plate; a blind flange is forged. The user picks the route.
        foreach (['BLIND', 'SPECTACLE_BLIND'] as $type) {
            $groups = array_column($this->lib()->groupsForComponentType($type), 'code');

            self::assertContains('PLATE', $groups);
            self::assertContains('FORGINGS', $groups, 'The forged route must survive.');
        }
    }

    public function test_the_plate_group_carries_the_specs_the_user_named(): void
    {
        $specs = array_column($this->lib()->specsIn('PLATE'), 'code');

        foreach (['A516', 'A515', 'A285', 'A573', 'A537', 'A204', 'A387', 'A240'] as $code) {
            self::assertContains($code, $specs);
        }

        // Structural plate is deliberately NOT here - see the test below.
        self::assertNotContains('A283', $specs);
    }

    public function test_a516_grade_70_resolves_the_commonest_blind_material(): void
    {
        $lib = $this->lib();

        self::assertTrue($lib->isKnown('PLATE', 'A516', '70', 'PLATE'));
        self::assertSame('ASTM A516 70 (PLATE)', $lib->describe('PLATE', 'A516', '70', 'PLATE'));

        // A516 HAS grades, so a null grade is not the A105 case - it does not
        // resolve, and must not be quietly described as bare 'ASTM A516'.
        self::assertFalse($lib->isKnown('PLATE', 'A516', null, 'PLATE'));
    }

    public function test_plate_carries_a_weld_joint_factor_of_one(): void
    {
        // Ew derates a LONGITUDINAL SEAM and a flat plate has none, so there is
        // nothing to derate - the same reasoning as CAST and FORGED. It is 1.0
        // rather than null because null is reserved for forms designed on a
        // different basis entirely, and (float) null is 0.0, which would zero a
        // pressure calculation instead of leaving it alone.
        self::assertSame(1.0, $this->lib()->weldJointQualityFactor('PLATE'));
        self::assertTrue($this->lib()->isKnownProductForm('PLATE'));
    }

    public function test_the_real_weld_factor_appears_when_plate_becomes_pipe(): void
    {
        // The plate is 1.0; the pipe rolled from it is 0.80 without full
        // radiography and 1.00 with. That factor belongs to the PIPE spec.
        self::assertSame(0.8, $this->lib()->weldJointQualityFactor('EFW'));
        self::assertSame(1.0, $this->lib()->weldJointQualityFactor('EFW_RT'));

        $forms = array_column($this->lib()->productFormsFor('PIPE', 'A672'), 'code');
        self::assertSame(['EFW', 'EFW_RT'], $forms);
    }

    public function test_every_plate_spec_offers_only_the_plate_form(): void
    {
        foreach ($this->lib()->specsIn('PLATE') as $spec) {
            self::assertSame(
                ['PLATE'],
                array_column($this->lib()->productFormsFor('PLATE', $spec['code']), 'code'),
                "{$spec['code']} should offer only PLATE."
            );
        }
    }

    public function test_structural_plate_cannot_be_reached_from_any_cascade(): void
    {
        // A283 is in the library because it appears on real MTOs as base plates
        // and gussets. It must never be offered for a line blank: a blind cut
        // from structural plate is a rupture waiting to happen. The first draft
        // of the plate group put it next to A516 and the validator caught it.
        $lib = $this->lib();

        self::assertContains('A283', array_column($lib->specsIn('PLATE_STRUCTURAL'), 'code'));

        foreach ($lib->groups() as $group) {
            if ($group['code'] !== 'PLATE_STRUCTURAL') {
                continue;
            }
            // The whole point: it supplies nothing.
            foreach (['BLIND', 'SPECTACLE_BLIND', 'PIPE', 'FLANGE', 'VALVE'] as $type) {
                self::assertNotContains(
                    'PLATE_STRUCTURAL',
                    array_column($lib->groupsForComponentType($type), 'code'),
                    "Structural plate must not be offered for a {$type}."
                );
            }
        }
    }

    public function test_every_structural_grade_says_it_is_not_pressure_retaining(): void
    {
        $lib = $this->lib();

        foreach ($lib->gradesOf('PLATE_STRUCTURAL', 'A283') as $summary) {
            $props = $lib->properties('PLATE_STRUCTURAL', 'A283', $summary['key']);
            self::assertFalse($props['pressureRetaining']['value'], $summary['key']);
        }
    }

    public function test_a537_class_is_part_of_the_grade_identity(): void
    {
        // Same chemistry, different heat treatment, different strength - so the
        // class identifies the grade, exactly as with A358 CL 1 / CL 3.
        $lib = $this->lib();

        $cl1 = $lib->properties('PLATE', 'A537', '1 CL 1');
        $cl2 = $lib->properties('PLATE', 'A537', '2 CL 2');

        self::assertSame('Normalised', $cl1['supplyCondition']['value']);
        self::assertSame('Quenched and tempered', $cl2['supplyCondition']['value']);
        self::assertGreaterThan(
            $cl1['minYieldStrength']['value'],
            $cl2['minYieldStrength']['value'],
            'Quenching and tempering is what buys the strength.'
        );
    }

    public function test_a387_grade_91_exists_only_as_class_2(): void
    {
        $keys = array_column($this->lib()->gradesOf('PLATE', 'A387'), 'key');

        self::assertContains('91 CL 2', $keys);
        self::assertNotContains('91 CL 1', $keys);

        // P-No 15E: a narrow, unforgiving PWHT window.
        self::assertSame('15E', $this->lib()->properties('PLATE', 'A387', '91 CL 2')['pNumber']['value']);
    }

    public function test_stainless_plate_is_never_galvanised(): void
    {
        // Galvanising stainless is meaningless. GALV is simply absent, and the
        // validator refuses NONE on a metallic spec, so BLACK - uncoated mill
        // finish - is the only honest value.
        $surfaces = $this->lib()->surfaceConditionsFor('PLATE', 'A240');

        self::assertSame(['BLACK'], $surfaces);
        self::assertNotContains('GALV', $surfaces);
    }

    public function test_the_duplex_plate_grades_are_named_by_uns(): void
    {
        // That is how the plate is marked, and how a requisition must read.
        $lib = $this->lib();
        $keys = array_column($lib->gradesOf('PLATE', 'A240'), 'key');

        foreach (['S31803', 'S32205', 'S32750'] as $uns) {
            self::assertContains($uns, $keys);
            self::assertSame('DUPLEX', $lib->properties('PLATE', 'A240', $uns)['family']['value']);
        }
    }

    public function test_the_plate_group_covers_the_welded_pipe_families(): void
    {
        // A671/A672/A691 are rolled from plate, and although the pack no longer
        // records WHICH plate, the library must still be able to supply the
        // plate a fabricator would buy: carbon, C-Mo, Cr-Mo and stainless.
        $lib = $this->lib();
        $families = [];
        foreach ($lib->specsIn('PLATE') as $spec) {
            foreach ($lib->gradesOf('PLATE', $spec['code']) as $summary) {
                $props = $lib->properties('PLATE', $spec['code'], $summary['key']);
                $families[$props['family']['value']] = true;
            }
        }

        self::assertArrayHasKey('FERRITIC', $families);
        self::assertArrayHasKey('AUSTENITIC', $families);
        self::assertArrayHasKey('DUPLEX', $families);
    }

    // ------------------------------------------------------------ gaskets ---

    public function test_a_gasket_comes_only_from_the_gasket_group(): void
    {
        $groups = array_column($this->lib()->groupsForComponentType('GASKET'), 'code');

        self::assertSame(['GASKET'], $groups);
    }

    public function test_the_gasket_group_carries_all_three_families(): void
    {
        $specs = array_column($this->lib()->specsIn('GASKET'), 'code');

        // B16.20 semi-metallic, B16.20 ring joint, B16.21 non-metallic flat.
        foreach (['B16.20-SW', 'B16.20-KP', 'B16.20-RJ', 'B16.21', 'B16.21-JKT'] as $code) {
            self::assertContains($code, $specs);
        }
    }

    public function test_a_gasket_records_every_material_it_is_made_of(): void
    {
        // The whole reason this group was deferred once: a spiral wound gasket
        // is FOUR materials, and a library that recorded only "spiral wound
        // 316" could not answer a question about the ring.
        $props = $this->lib()->properties('GASKET', 'B16.20-SW', 'SW-316-GR-IR CGI');

        self::assertSame('316 stainless steel', $props['windingMaterial']['value']);
        self::assertSame('Flexible graphite', $props['fillerMaterial']['value']);
        self::assertSame('Carbon steel', $props['outerRingMaterial']['value']);
        self::assertSame('316 stainless steel', $props['innerRingMaterial']['value']);
    }

    public function test_every_gasket_constituent_resolves_against_the_library(): void
    {
        // The sweep that makes the constituents real rather than free text.
        // Same discipline as wroughtEquivalent on the castings.
        $lib = $this->lib();
        $pairs = [
            ['windingSpecification', 'windingGrade'],
            ['outerRingSpecification', 'outerRingGrade'],
            ['innerRingSpecification', 'innerRingGrade'],
            ['coreSpecification', 'coreGrade'],
            ['ringSpecification', 'ringGrade'],
            ['jacketSpecification', 'jacketGrade'],
        ];
        $checked = 0;

        foreach ($lib->specsIn('GASKET') as $spec) {
            foreach ($lib->gradesOf('GASKET', $spec['code']) as $summary) {
                $props = $lib->properties('GASKET', $spec['code'], $summary['key']);
                foreach ($pairs as [$specKey, $gradeKey]) {
                    if (! isset($props[$specKey])) {
                        continue;
                    }
                    $designation = str_replace(['ASTM ', 'ASME '], '', $props[$specKey]['value']);
                    $grade = $props[$gradeKey]['value'];
                    if ($grade === '-') {
                        continue; // a spec with no graded product, e.g. soft iron
                    }

                    $keys = array_column($lib->gradesOf(
                        $this->groupHolding($designation),
                        $designation
                    ), 'code');
                    self::assertContains(
                        $grade,
                        $keys,
                        "{$spec['code']} {$summary['key']}: {$designation} {$grade} does not resolve."
                    );
                    $checked++;
                }
            }
        }

        self::assertGreaterThan(20, $checked, 'The sweep must actually sweep.');
    }

    /** Find which group holds a specification, so the sweep above can look it up. */
    private function groupHolding(string $specCode): string
    {
        foreach ($this->lib()->groups() as $group) {
            foreach ($this->lib()->specsIn($group['code']) as $spec) {
                if ($spec['code'] === $specCode) {
                    return $group['code'];
                }
            }
        }

        self::fail("No group holds {$specCode}.");
    }

    public function test_the_outer_ring_may_differ_from_the_winding(): void
    {
        // A carbon steel centring ring on a 316 winding is the standard build,
        // and in a chloride service the ring corrodes away while the winding is
        // untouched. Both builds must be expressible.
        $lib = $this->lib();

        $cheap = $lib->properties('GASKET', 'B16.20-SW', 'SW-316-GR CG');
        self::assertSame('316 stainless steel', $cheap['windingMaterial']['value']);
        self::assertSame('Carbon steel', $cheap['outerRingMaterial']['value']);

        $upgraded = $lib->properties('GASKET', 'B16.20-SW', 'SW-316-GR-SSR CG');
        self::assertSame('316 stainless steel', $upgraded['outerRingMaterial']['value']);
    }

    public function test_the_filler_sets_the_temperature_ceiling_not_the_flange(): void
    {
        // PTFE gives up around 260 C while the flange is untroubled, so the
        // filler is what fails first and the ceiling belongs to the gasket.
        $lib = $this->lib();

        self::assertSame(650, $lib->properties('GASKET', 'B16.20-SW', 'SW-316-GR CG')['maxTemperature']['value']);
        self::assertSame(260, $lib->properties('GASKET', 'B16.20-SW', 'SW-316-PTFE CG')['maxTemperature']['value']);
    }

    public function test_no_gasket_carries_a_pressure_rating(): void
    {
        // A gasket is rated by the joint it sits in. The rating lives on
        // piping_classes.pressure_rating, exactly as for every other component.
        $lib = $this->lib();

        foreach ($lib->specsIn('GASKET') as $spec) {
            foreach ($lib->gradesOf('GASKET', $spec['code']) as $summary) {
                foreach ($lib->properties('GASKET', $spec['code'], $summary['key']) as $name => $prop) {
                    self::assertStringNotContainsStringIgnoringCase(
                        'rating',
                        $name,
                        "{$spec['code']} {$summary['key']} carries {$name}."
                    );
                }
            }
        }
    }

    public function test_every_gasket_says_which_facing_it_seats_on(): void
    {
        // Recorded now, validated later - this round is materials only. A ring
        // joint gasket seats ONLY in an RTJ groove; a full-face gasket only on
        // a flat face. ports.facing already exists for when this is wired up.
        $lib = $this->lib();

        foreach ($lib->specsIn('GASKET') as $spec) {
            foreach ($lib->gradesOf('GASKET', $spec['code']) as $summary) {
                $props = $lib->properties('GASKET', $spec['code'], $summary['key']);
                self::assertArrayHasKey('permittedFacings', $props, "{$spec['code']} {$summary['key']}");
            }
        }

        self::assertSame(
            'RTJ',
            $lib->properties('GASKET', 'B16.20-RJ', 'RJ-R-316 R')['permittedFacings']['value']
        );
        self::assertSame(
            'FF',
            $lib->properties('GASKET', 'B16.21', 'FL-CNAF-FF')['permittedFacings']['value']
        );
    }

    public function test_a_ring_joint_gasket_records_its_hardness_ceiling(): void
    {
        // The ring must be SOFTER than the flange groove or it damages the
        // flange instead of sealing. It is the one number a receiving
        // inspector checks, and soft iron is the softest of them.
        $lib = $this->lib();

        $soft = $lib->properties('GASKET', 'B16.20-RJ', 'RJ-R-SOFT R');
        $ss = $lib->properties('GASKET', 'B16.20-RJ', 'RJ-R-316 R');

        self::assertSame('90 HB', $soft['maxHardness']['value']);
        self::assertSame('160 HB', $ss['maxHardness']['value']);
    }

    public function test_the_bx_ring_is_not_interchangeable(): void
    {
        // R and RX share a groove; BX is API 6BX only, a different groove
        // entirely. Recording the style is what makes that visible.
        $keys = array_column($this->lib()->gradesOf('GASKET', 'B16.20-RJ'), 'key');

        self::assertContains('RJ-R-316 R', $keys);
        self::assertContains('RJ-RX-316 RX', $keys);
        self::assertContains('RJ-BX-316 BX', $keys);
    }

    public function test_non_metallic_gaskets_carry_no_metallurgical_family(): void
    {
        // 'family' is a metallurgical axis - ferritic, austenitic, duplex,
        // martensitic, nickel. Rubber has no place on it, and the validator
        // refused NON_METALLIC as a family. Absent is the honest answer.
        $lib = $this->lib();

        foreach ($lib->gradesOf('GASKET', 'B16.21') as $summary) {
            $props = $lib->properties('GASKET', 'B16.21', $summary['key']);
            self::assertArrayNotHasKey('family', $props, $summary['key']);
        }

        // A spiral wound gasket IS metal, so it keeps its family.
        self::assertSame(
            'AUSTENITIC',
            $lib->properties('GASKET', 'B16.20-SW', 'SW-316-GR CG')['family']['value']
        );
    }

    public function test_a_gasket_has_no_weld_joint_quality_factor(): void
    {
        // It is metallic and it is not pressure-designed: there is no wall, no
        // allowable stress and no seam. Both gasket forms return null, and
        // isKnownProductForm tells that apart from a typo.
        $lib = $this->lib();

        self::assertNull($lib->weldJointQualityFactor('ASSEMBLED'));
        self::assertNull($lib->weldJointQualityFactor('DIE_CUT'));
        self::assertTrue($lib->isKnownProductForm('ASSEMBLED'));
        self::assertTrue($lib->isKnownProductForm('DIE_CUT'));
        self::assertFalse($lib->isKnownProductForm('WOVEN'));
    }

    public function test_the_gasket_group_is_metallic_but_not_pressure_designed(): void
    {
        // The distinction the gasket group forced into existence. Before it,
        // 'metallic' silently meant 'sized by the B31.3 wall formula'.
        $lib = $this->lib();

        $gasket = null;
        foreach ($lib->groups() as $group) {
            if ($group['code'] === 'GASKET') {
                $gasket = $group;
            }
        }

        self::assertNotNull($gasket);
        self::assertTrue($gasket['metallic'], 'A spiral wound gasket is steel.');
    }

    // ------------------------------------------------------------ valve trim

    public function test_a_trim_is_not_a_body_material(): void
    {
        // The whole reason trim is not a cascade group. A valve needs BOTH a
        // body and a trim; if VALVE_TRIM supplied the VALVE component type the
        // cascade would offer 'Trim 8' where 'A216 WCB' belongs and the valve
        // would end up with no body at all.
        $lib = $this->lib();

        $groups = array_column($lib->groupsForComponentType('VALVE'), 'code');

        self::assertContains('CASTINGS', $groups, 'A valve body is a casting.');
        self::assertNotContains('VALVE_TRIM', $groups);

        foreach (['CONTROL_VALVE', 'THREE_WAY_VALVE'] as $type) {
            self::assertNotContains(
                'VALVE_TRIM',
                array_column($lib->groupsForComponentType($type), 'code'),
            );
        }
    }

    public function test_every_group_says_how_it_is_reached(): void
    {
        $known = [
            MaterialLibrary::REACHED_BY_CASCADE,
            MaterialLibrary::REACHED_BY_CONSTITUENT,
            MaterialLibrary::REACHED_BY_TRIM,
            MaterialLibrary::REACHED_BY_REQUISITION_ONLY,
        ];

        foreach ($this->lib()->groups() as $group) {
            self::assertContains(
                $group['reachedBy'],
                $known,
                "Group {$group['code']} has no known route.",
            );
        }
    }

    public function test_only_cascade_groups_appear_in_the_cascade(): void
    {
        // Reached the long way round: ask for EVERY component type any group
        // claims, and assert nothing non-cascade ever comes back.
        $lib = $this->lib();
        $types = [];
        foreach ($lib->groups() as $group) {
            foreach ($lib->groupsForComponentType($group['code']) as $g) {
                $types[] = $g['code'];
            }
        }

        foreach (['PIPE', 'FLANGE', 'VALVE', 'GASKET', 'BOLT', 'ELBOW'] as $type) {
            foreach ($lib->groupsForComponentType($type) as $group) {
                self::assertSame(
                    MaterialLibrary::REACHED_BY_CASCADE,
                    $group['reachedBy'],
                    "{$group['code']} is offered for {$type} but is not a cascade group.",
                );
            }
        }
    }

    public function test_the_library_holds_every_api_600_trim(): void
    {
        $trims = array_column($this->lib()->trims(), 'code');

        // The full chart, including the lettered variants, which are real
        // trims and not footnotes: 5A and 8A substitute a Ni-Cr overlay for
        // Stellite and are ordered by number like any other.
        // THIRTY, from Table 8 of API 600-2021 itself. The 20-row chart this
        // test used to assert came off vendor websites and was missing the
        // entire nickel-alloy family.
        foreach (['1', '2', '3', '4', '5', '5A', '5B', '6', '7', '8', '8A', '9',
            '10', '11', '12', '13', '14', '15', '16', '17', '18',
            '19', '19A', '19B', '19C', '20', '20A', '20B', '20C', '21'] as $code) {
            self::assertContains($code, $trims, "Trim {$code} is missing.");
        }

        self::assertSame(30, count($this->lib()->trimsFor('GATE', true)));
        // Trim 1 is withdrawn by Table 8, so 29 are offerable.
        self::assertSame(29, count($this->lib()->trimsFor('GATE')));
    }

    public function test_a_trim_resolves_into_the_parts_the_standard_names(): void
    {
        $trim = $this->lib()->trim('8', 'API600');

        self::assertNotNull($trim);

        // THREE, not four. The four-part split - seat, disc, backseat, stem -
        // was ours. Table 8's columns are SEATING SURFACE (one concept
        // covering the seat ring and the wedge face, which is why a trim can
        // name two of them) and STEM/BACKSEAT BUSHING as a single column group
        // with one material type and two hardness columns.
        self::assertSame(
            ['seatingSurface', 'stem', 'backseatBushing'],
            array_keys($trim),
        );
        self::assertSame(
            ['seatingSurface', 'stem', 'backseatBushing'],
            $this->lib()->trimPartsFor('GATE'),
        );

        // Trim 8 is the refinery default: a 13Cr seat ring with a Co-Cr
        // overlay, on a 13Cr stem.
        self::assertStringContainsString('ECoCr-A', (string) $trim['seatingSurface']['hardfacing']);
        self::assertSame('13Cr', $trim['stem']['material']);
        self::assertSame('ASTM A276', $trim['stem']['specification']);
        self::assertSame('410', $trim['stem']['grade']);
    }

    public function test_every_trim_part_resolves_to_a_grade_or_to_a_hardfacing(): void
    {
        // The PHP-side mirror of validator rule 24. A part that resolves to
        // neither is a part nobody can order.
        $lib = $this->lib();
        $checked = 0;

        foreach ($lib->trims() as $t) {
            $trim = $lib->trim($t['code'], $t['standard']);
            self::assertNotNull($trim);
            // The part COUNT comes from the standard, not from a constant.
            $expected = $lib->trimPartsFor($t['valveTypes'][0]);
            self::assertSame(
                $expected,
                array_keys($trim),
                "Trim {$t['standard']} {$t['code']} does not match its standard's parts.",
            );

            foreach ($trim as $part => $detail) {
                self::assertNotSame('', $detail['material']);

                // 'NOT SPECIFIED' is API 594 Table 4 leaving the hinge pin
                // columns of the bronze trim AA empty.
                // 'MFG Standard' is the sentinel API 600 Table 8 prints where
                // it declines to name a specification - trims 9, 11, 19, 20
                // and 21 are manufacturer's standard nickel alloys. "The
                // standard defers" is an answer, and a different one from
                // "nobody filled this in". A withdrawn trim carries the same
                // shape under 'WITHDRAWN'.
                if (in_array($detail['specification'], ['MFG Standard', 'WITHDRAWN', 'NOT SPECIFIED'], true)) {
                    // The sentinel must be carried through, or be absent - a
                    // seating surface has no grade column in Table 8 at all.
                    self::assertContains(
                        $detail['grade'],
                        [$detail['specification'], null],
                        "Trim {$t['code']} {$part}: a sentinel specification with "
                        .'a real grade reads as data.',
                    );
                    $checked++;

                    continue;
                }

                if ($detail['polymer'] !== null) {
                    // A soft seat resolves to a polymer, not to an ASTM spec.
                    self::assertNull($detail['specification']);
                    $checked++;

                    continue;
                }

                if ($detail['hardfacing'] !== null) {
                    // An overlay names no ASTM specification, by design.
                    self::assertNull($detail['specification']);
                    $checked++;

                    continue;
                }

                if ($detail['specifications'] !== null) {
                    // Several routes to one part, offered as alternatives. It
                    // must still name real documents - the point of the field
                    // is that the standard gives more than one, not fewer.
                    self::assertMatchesRegularExpression(
                        // A route may point at the standard's OWN footnote -
                        // trim 4's hardened 13Cr is 'per Table 8 note f', not
                        // an ASTM product specification. That is still a named
                        // document, and the note is what an inspector reads.
                        '/(ASTM|AWS|MFG|WITHDRAWN|Table 8 note)/',
                        (string) $detail['specifications'],
                        "Trim {$t['code']} {$part} lists routes that name no document.",
                    );
                    $checked++;

                    continue;
                }

                self::assertNotNull(
                    $detail['specification'],
                    "Trim {$t['code']} {$part} resolves to nothing.",
                );

                $spec = str_replace('ASTM ', '', $detail['specification']);
                $group = $this->groupHolding($spec);
                self::assertTrue(
                    $lib->isKnown($group, $spec, MaterialLibrary::gradeKey($detail['grade'], null)),
                    "Trim {$t['code']} {$part} names {$spec} {$detail['grade']}, which the library does not hold.",
                );
                $checked++;
            }
        }

        // No fixed arithmetic any more: every trim contributes its own parts.
        $expectedParts = 0;
        foreach ($lib->trims() as $t) {
            $expectedParts += count($lib->trimPartsFor($t['valveTypes'][0]));
        }
        self::assertSame($expectedParts, $checked);
        self::assertGreaterThan(100, $checked, 'The sweep must actually sweep.');
    }

    public function test_a_hardfaced_trim_states_the_hardness_an_inspector_measures(): void
    {
        $lib = $this->lib();

        // Table 8, Seating Surface Hardness (HB) Minimum. Co-Cr overlays are
        // 350; hardened 13Cr is a heat treatment rather than an overlay and is
        // 750; trim 7 pairs plain 13Cr with hardened 13Cr and the table prints
        // 250 against the row.
        self::assertSame(350, $lib->minSeatHardness('5', 'API600'));
        self::assertSame(250, $lib->minSeatHardness('8', 'API600'));
        self::assertSame(750, $lib->minSeatHardness('4', 'API600'));
        self::assertSame(250, $lib->minSeatHardness('7', 'API600'));

        // A plain austenitic trim has no hardness floor - Table 8 prints
        // "Note d", the manufacturer's standard, which is not a number.
        self::assertNull($lib->minSeatHardness('10', 'API600'));
        self::assertNull($lib->minSeatHardness('2', 'API600'));
    }

    public function test_a_stem_is_bar_not_a_forging(): void
    {
        // The reason A276 had to be added rather than reusing A182. They are
        // the same 13Cr alloy; the difference is the PRODUCT FORM, and a stem
        // is machined from bar. Resolving trim to A182 would have named the
        // wrong form on the requisition.
        $lib = $this->lib();

        self::assertSame(['BAR'], array_column($lib->productFormsFor('BAR', 'A276'), 'code'));
        self::assertSame(['FORGED'], array_column($lib->productFormsFor('FORGINGS', 'A182'), 'code'));

        // Both hold the same alloy under different codes.
        self::assertTrue($lib->isKnown('BAR', 'A276', MaterialLibrary::gradeKey('410', null)));
        self::assertTrue($lib->isKnown('FORGINGS', 'A182', MaterialLibrary::gradeKey('F6a', 'CL 1')));
    }

    public function test_a_bar_has_no_longitudinal_seam_to_derate(): void
    {
        // Same reasoning as PLATE, FORGED and CAST: Ew derates a longitudinal
        // seam and a bar has none.
        self::assertSame(1.0, $this->lib()->weldJointQualityFactor('BAR'));
    }

    public function test_the_nickel_trims_resolve_now_that_the_wrought_specs_exist(): void
    {
        // These seven were the reason this round added B564, B473 and B151.
        // Before them the library held only the CAST equivalents (A494 M35-1,
        // A351 CN7M) and a stem is not a casting.
        $lib = $this->lib();

        // TWO OF THE SIX ASSERTIONS HERE WERE INVENTED. The old chart gave
        // trim 9 a stem of ASTM B564 N04400 and trim 6 a seat of ASTM B151.
        // Table 8 gives trim 9's stem as "MFG Standard" - the standard defers
        // to the manufacturer - and trim 6's second seating surface as Cu-Ni
        // per note (k), with no specification at all. Plausible inventions are
        // the hardest kind to notice.
        self::assertSame('MFG Standard', $lib->trim('9', 'API600')['stem']['specification']);
        self::assertSame('MFG Standard', $lib->trim('9', 'API600')['stem']['grade']);

        // These two were right, and are now citations rather than guesses.
        self::assertSame('ASTM B473', $lib->trim('13', 'API600')['stem']['specification']);
        self::assertSame('ASTM A276', $lib->trim('3', 'API600')['stem']['specification']);
        self::assertSame('310', $lib->trim('3', 'API600')['stem']['grade']);

        // And the nickel-alloy trims the old chart did not have at all.
        self::assertSame('ASTM B564', $lib->trim('19A', 'API600')['stem']['specification']);
        self::assertSame('N06625', $lib->trim('19A', 'API600')['stem']['grade']);
    }

    public function test_a_copper_alloy_carries_no_metallurgical_family(): void
    {
        // The same call the gasket round made about rubber. 'family' is
        // ferritic / austenitic / duplex / martensitic / nickel - a copper
        // nickel has no place on that axis, and widening it would corrupt the
        // axis for every grade that does sit on it.
        foreach ($this->lib()->gradesOf('BAR', 'B151') as $grade) {
            $props = $this->lib()->properties('BAR', 'B151', $grade['key']);
            self::assertArrayNotHasKey('family', $props);
            self::assertArrayHasKey('unsNumber', $props);
        }
    }

    public function test_trim_and_body_are_two_independent_choices(): void
    {
        // The end-to-end shape of specifying a valve: a body from the cascade
        // and a trim from its own selector. Neither can supply the other.
        $lib = $this->lib();

        $body = array_column($lib->groupsForComponentType('VALVE'), 'code');
        self::assertContains('CASTINGS', $body);
        self::assertTrue($lib->isKnown('CASTINGS', 'A216', MaterialLibrary::gradeKey('WCB', null)));

        self::assertNotNull($lib->trim('8', 'API600'));
        self::assertNull($lib->trim('WCB'), 'A body material is not a trim number.');
        self::assertNull($lib->trim('99'));
    }

    // --------------------------------- a trim belongs to ONE kind of valve

    public function test_api_600_is_gate_valves_only(): void
    {
        // The bug this round fixed. API 600 is 'Steel Gate Valves - Flanged and
        // Butt-welding Ends, Bolted Bonnets'. Its trim chart is quoted for
        // every valve in the industry, and the first version of this group let
        // the library do the same: all 20 gate trims were offered for a
        // butterfly valve, which has no backseat and usually an elastomer seat.
        $lib = $this->lib();

        self::assertSame('API600', $lib->trimStandardFor('GATE'));

        foreach (['BALL', 'BUTTERFLY', 'CHECK', 'GLOBE', 'PLUG'] as $type) {
            self::assertNotSame(
                'API600',
                $lib->trimStandardFor($type),
                "API 600 does not cover {$type} valves.",
            );
        }

        // THIS ASSERTION WAS BUILT ON AN INVENTED CHART. It said trim 8 was a
        // gate trim and could not appear anywhere else - true only because the
        // check-valve chart used made-up letter codes (13CR, STL, MONEL).
        // API 594-2022 Table 4 has a trim 8 of its own, and so it should: the
        // API standards share ONE numbering family, and the same number means
        // different materials in different standards. A trim number belongs to
        // its standard, exactly as its substitution table does.
        $gate8 = $lib->trim('8', 'API600');
        $check8 = $lib->trim('8', 'API594');

        self::assertNotNull($gate8);
        self::assertNotNull($check8);
        self::assertNotSame(array_keys($gate8), array_keys($check8));

        // What must NOT happen is a trim resolving without its standard.
        foreach (['BALL', 'BUTTERFLY'] as $type) {
            self::assertNotContains(
                '8',
                array_column($lib->trimsFor($type), 'code'),
                "Neither API 608 nor API 609 numbers a trim 8; {$type} must not offer one.",
            );
        }
    }

    public function test_each_valve_type_has_its_own_standard(): void
    {
        $lib = $this->lib();

        self::assertSame('API600', $lib->trimStandardFor('GATE'));
        self::assertSame('API623', $lib->trimStandardFor('GLOBE'));
        self::assertSame('API594', $lib->trimStandardFor('CHECK'));
        self::assertSame('API608', $lib->trimStandardFor('BALL'));
        self::assertSame('API609', $lib->trimStandardFor('BUTTERFLY'));

        // Null is a real answer, not a gap to paper over. It was always true
        // of a needle or diaphragm valve - and it is now true of a PLUG valve
        // as well. API 599-2025 prints no trim number table at all: clause 6
        // gives the shell from ASME B16.34 or a ductile iron from B16.42 and
        // leaves seat, sleeve and plug facing to the purchase order. The
        // PTFE-SLV and MTL codes this library used to offer were a vocabulary
        // for a chart that does not exist.
        self::assertNull($lib->trimStandardFor('PLUG'));
        self::assertSame([], $lib->trimsFor('PLUG'));
        self::assertNull($lib->trimStandardFor('NEEDLE'));
        self::assertNull($lib->trimStandardFor('DIAPHRAGM'));
        self::assertSame([], $lib->trimsFor('NEEDLE'));
    }

    public function test_a_check_valve_has_no_stem_and_no_backseat(): void
    {
        // The clearest proof that four parts was never universal: a check
        // valve has no actuator, so nothing to seal a stem against.
        $lib = $this->lib();

        // Table 4 of API 594 has two part columns: Seat Surface and Hinge
        // Pin. The 'disc' in the old list came from the invented chart - the
        // standard does not name a disc material separately from the seating
        // surface.
        self::assertSame(['seatingSurface', 'hingePin'], $lib->trimPartsFor('CHECK'));

        foreach ($lib->trimsFor('CHECK') as $t) {
            $trim = $lib->trim($t['code'], 'API594');
            self::assertArrayNotHasKey('stem', $trim);
            self::assertArrayNotHasKey('backseat', $trim);
            self::assertArrayHasKey('hingePin', $trim);
        }
    }

    public function test_a_ball_valve_has_a_ball_not_a_disc(): void
    {
        $lib = $this->lib();

        self::assertSame(['seat', 'ball', 'stem'], $lib->trimPartsFor('BALL'));

        $trim = $lib->trim('RPTFE', 'API608');
        self::assertArrayHasKey('ball', $trim);
        self::assertArrayNotHasKey('disc', $trim);
        self::assertArrayNotHasKey('backseat', $trim);
    }

    public function test_a_soft_seat_is_limited_by_its_polymer_not_by_the_body(): void
    {
        // Exactly the gasket-filler truth, one component along: an RPTFE seat
        // creeps at 232 C while the A216 WCB body is untroubled far beyond it.
        $lib = $this->lib();

        self::assertSame(204, $lib->trimMaxTemperature('PTFE', 'API608'));
        self::assertSame(232, $lib->trimMaxTemperature('RPTFE', 'API608'));
        self::assertSame(260, $lib->trimMaxTemperature('PEEK', 'API608'));
        self::assertSame(120, $lib->trimMaxTemperature('EPDM', 'API609'));

        // A metal seated trim has no polymer ceiling - it has a hardness floor.
        self::assertNull($lib->trimMaxTemperature('MTL-STL', 'API608'));
        self::assertSame(350, $lib->minSeatHardness('MTL-STL', 'API608'));
    }

    public function test_a_soft_seat_resolves_to_a_polymer_not_to_a_specification(): void
    {
        $seat = $this->lib()->trim('RPTFE', 'API608')['seat'];

        self::assertSame('Reinforced PTFE (RPTFE)', $seat['polymer']);
        self::assertNull($seat['specification']);
        self::assertNull($seat['hardfacing']);

        // The ball and stem are still steel and still resolve.
        $ball = $this->lib()->trim('RPTFE', 'API608')['ball'];
        self::assertSame('ASTM A276', $ball['specification']);
        self::assertSame('316', $ball['grade']);
    }

    public function test_the_globe_stem_rule_is_an_obligation_not_a_per_trim_flag(): void
    {
        // THIS TEST ASSERTED AN INVENTED PROPERTY. The chart this spec grew
        // from carried a per-trim boolean, requiresOversizeStem, ticked on the
        // austenitic trims - and API 623 says no such thing. Clause 5.8.1
        // states a DESIGN OBLIGATION on the manufacturer: the minimum diameter
        // comes from Table 5, and "the actual stem diameter shall take into
        // account the valve design details and the stem material strength
        // characteristics". A plausible simplification of a real rule is the
        // hardest kind of invention to spot.
        $pack = json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/materials.ref.json'),
        ), true);
        $spec = collect(collect($pack['groups'])->firstWhere('code', 'VALVE_TRIM')['specs'])
            ->firstWhere('code', 'API623');

        self::assertTrue($spec['stemDiameterRule']['verified']);
        self::assertStringContainsString('5.8.1', $spec['stemDiameterRule']['source']);
        self::assertStringContainsString(
            'STEM MATERIAL STRENGTH',
            $spec['stemDiameterRule']['text'],
        );

        // And the boolean is gone from every trim, not just the one tested.
        foreach ($spec['grades'] as $grade) {
            self::assertArrayNotHasKey('requiresOversizeStem', $grade['properties']);
        }
    }

    public function test_no_two_standards_claim_the_same_valve_type(): void
    {
        $seen = [];
        foreach ($this->lib()->trims() as $t) {
            foreach ($t['valveTypes'] as $vt) {
                if (isset($seen[$vt])) {
                    self::assertSame(
                        $seen[$vt],
                        $t['standard'],
                        "Valve type {$vt} is claimed by two standards.",
                    );
                }
                $seen[$vt] = $t['standard'];
            }
        }

        // FIVE, not six. The plug valve dropped out when API 599 was read and
        // turned out to number no trims at all - a valve type can have a
        // construction standard and no trim chart, and this library now says
        // so rather than inventing codes to fill the row.
        self::assertCount(5, $seen);
        self::assertArrayNotHasKey('PLUG', $seen);
    }

    // ------------------------------------------- B16.5 material group (ADR-008)

    public function test_the_b165_group_resolves_from_a_grade(): void
    {
        $lib = $this->lib();

        // The first hop of the P-T lookup. A105 and A350 LF2 and A216 WCB all
        // land on 1.1 - the one assignment every source agreed on.
        $this->assertSame('1.1', $lib->b165MaterialGroup('FORGINGS', 'A350', 'LF2 CL 1'));
        $this->assertSame('1.1', $lib->b165MaterialGroup('CASTINGS', 'A216', 'WCB'));
        $this->assertSame('2.1', $lib->b165MaterialGroup('FORGINGS', 'A182', 'F304'));
        $this->assertSame('2.2', $lib->b165MaterialGroup('FORGINGS', 'A182', 'F316'));
    }

    public function test_the_b165_group_falls_back_to_a_gradeless_spec(): void
    {
        // A105 is the reason the fallback exists at all. It has no grades, so
        // there is no grade to carry the group, and it is the single most
        // common flange material there is.
        $this->assertSame('1.1', $this->lib()->b165MaterialGroup('FORGINGS', 'A105', null));
    }

    public function test_an_unknown_b165_group_is_null_and_not_a_default(): void
    {
        $lib = $this->lib();

        // F321 and F22 CL 3 used to be the examples here, because the public
        // sources contradicted each other about them. The standard settled
        // both - and settled them AGAINST the sources: F321 is 2.4, which no
        // source said (two claimed 2.2, one claimed 2.3), and F22 CL 3 is
        // 1.10. So the examples have to be materials B16.5 genuinely does not
        // rate. Absent must still read as UNKNOWN, because a wrong group does
        // not fail - it quietly returns a different pressure.
        $this->assertSame('2.4', $lib->b165MaterialGroup('FORGINGS', 'A182', 'F321'));
        $this->assertSame('1.10', $lib->b165MaterialGroup('FORGINGS', 'A182', 'F22 CL 3'));
        $this->assertNull($lib->b165MaterialGroup('BOLTING', 'A193', 'B7'));
    }

    public function test_an_unresolvable_selection_has_no_b165_group(): void
    {
        $lib = $this->lib();

        // Not the same question as "this material has no group". An invented
        // grade must not inherit its spec's group by accident - that is
        // exactly how "A105 Gr B" would acquire a pressure rating.
        $this->assertNull($lib->b165MaterialGroup('FORGINGS', 'A105', 'B'));
        $this->assertNull($lib->b165MaterialGroup('FORGINGS', 'A182', 'F999'));
        $this->assertNull($lib->b165MaterialGroup('FORGINGS', 'NOPE', null));
    }

    public function test_every_b165_group_is_verified_and_cites_its_table(): void
    {
        // The inverse of what this test used to assert. Every group in the
        // pack was read out of ASME B16.5-2025, so every one must carry the
        // citation - and the citation must name the table it came from, not
        // just the document.
        $lib = $this->lib();
        $seen = 0;

        foreach ($lib->groups() as $group) {
            foreach ($lib->specsIn($group['code']) as $spec) {
                $keys = array_column($lib->gradesOf($group['code'], $spec['code']), 'key');
                // null is the gradeless case, and it is the one that matters:
                // A105 is where the spec-level fallback lives.
                foreach ($keys === [] ? [null] : $keys as $key) {
                    $props = $lib->properties($group['code'], $spec['code'], $key);

                    if (isset($props['b165MaterialGroup'])) {
                        $seen++;
                        $mg = $props['b165MaterialGroup'];

                        $this->assertTrue(
                            $mg['verified'],
                            "{$spec['code']} {$key} has an unverified B16.5 group.",
                        );
                        $this->assertMatchesRegularExpression(
                            '/^ASME B16\.5-2025 Table 2-\d+\.\d+$/',
                            $mg['source'] ?? '',
                            "{$spec['code']} {$key} does not cite a numbered table.",
                        );
                        $this->assertSame(
                            'Table 2-' . $mg['value'],
                            substr($mg['source'], -\strlen('Table 2-' . $mg['value'])),
                            "{$spec['code']} {$key} cites a table that is not its own group.",
                        );
                    }
                }
            }
        }

        // Guards the guard: if the property were renamed the loop above would
        // pass by finding nothing at all.
        $this->assertSame(75, $seen);
    }
}
