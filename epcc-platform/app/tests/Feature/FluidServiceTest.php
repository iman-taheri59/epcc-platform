<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\Reference\FluidService;
use Tests\TestCase;

/**
 * ASME B31.3-2024, slice 1: the fluid services and the examination extent.
 *
 * The platform has leaned on B31.3 in four places since step 4 — the material
 * grouping, the fluid service category, the wall thickness and the NDT
 * percentages — without anyone having read it. This is the first slice, chosen
 * because it unblocks the PMS → ITP path.
 *
 * It corrects a modelling error the Code makes plain: **a condition is not a
 * service**.
 *
 * No database.
 */
final class FluidServiceTest extends TestCase
{
    private function fs(): FluidService
    {
        return app(FluidService::class);
    }

    // ------------------------------------- a condition is not a service

    public function test_severe_cyclic_is_a_condition_and_not_a_fluid_service(): void
    {
        // The header enum held it as one of five services for five rounds.
        // B31.3 300.2 defines "severe cyclic conditions" separately: conditions
        // the owner or designer designates on specific components or joints to
        // better resist fatigue. A Category M line can be under severe cyclic
        // conditions and so can a Normal one - one enum cannot say that.
        self::assertFalse($this->fs()->isService('SEVERE_CYCLIC'));
        self::assertTrue($this->fs()->isCondition('SEVERE_CYCLIC'));

        self::assertNotContains('SEVERE_CYCLIC', $this->fs()->services());
    }

    public function test_the_code_names_six_services_and_we_held_four_of_them(): void
    {
        // ELEVATED_TEMPERATURE (300.2(c)) and HIGH_PURITY (300.2(e)) were
        // missing entirely - a class in either could not be stated at all.
        $services = $this->fs()->services();

        self::assertCount(6, $services);
        foreach (['NORMAL', 'CATEGORY_D', 'CATEGORY_M', 'HIGH_PRESSURE'] as $held) {
            self::assertContains($held, $services);
        }
        foreach (['ELEVATED_TEMPERATURE', 'HIGH_PURITY'] as $added) {
            self::assertContains($added, $services, "{$added} was missing");
        }
    }

    // ------------------------------- only one service has numbers in it

    public function test_category_d_is_the_only_service_the_platform_can_contradict(): void
    {
        // B31.3 300.2(a) bounds it: 1 035 kPa gauge, 186 C, -29 C. Every other
        // service is a judgement - "the owner determines", "the engineering
        // design specifies" - and this engine has no standing to second-guess
        // one.
        foreach ($this->fs()->services() as $code) {
            $s = $this->fs()->get($code);
            self::assertSame(
                $code === 'CATEGORY_D',
                $s['definitionIsTestable'],
                "{$code} claims the wrong kind of definition",
            );
            self::assertSame($code === 'CATEGORY_D', $s['bounds'] !== null);
        }
    }

    public function test_a_category_d_class_that_breaks_its_own_definition_is_caught(): void
    {
        $fs = $this->fs();

        // Inside the bounds: nothing to say.
        self::assertSame([], $fs->categoryDViolations('CATEGORY_D', 150.0, 900.0));

        // Over 186 C.
        $hot = $fs->categoryDViolations('CATEGORY_D', 200.0, 900.0);
        self::assertSame(['maxDesignTemperature'], array_column($hot, 'bound'));

        // Over 1 035 kPa.
        $high = $fs->categoryDViolations('CATEGORY_D', 150.0, 2000.0);
        self::assertSame(['maxDesignGaugePressure'], array_column($high, 'bound'));

        // Below -29 C.
        $cold = $fs->categoryDViolations('CATEGORY_D', 150.0, 900.0, -40.0);
        self::assertSame(['minFluidTemperature'], array_column($cold, 'bound'));
    }

    public function test_no_other_service_is_second_guessed(): void
    {
        // Null, not [] - "there is nothing here to check" is a different
        // answer from "I checked and it is fine".
        self::assertNull($this->fs()->categoryDViolations('NORMAL', 500.0, 9000.0));
        self::assertNull($this->fs()->categoryDViolations('HIGH_PRESSURE', 500.0, 40000.0));
    }

    // ---------------------------------------------- the examination extent

    public function test_normal_fluid_service_buys_five_percent_radiography(): void
    {
        // 341.4.1: at least 5% of circumferential butt and miter groove welds
        // within each designated lot, by random radiography.
        self::assertSame(5, $this->fs()->minimumRadiographyPercent('NORMAL'));
    }

    public function test_category_d_is_visual_only_and_carries_no_percentage(): void
    {
        // 341.4.2 and Table 341.3.2-1 note (5). A percentage against Category D
        // is a Normal Fluid Service figure that has wandered.
        self::assertNull($this->fs()->minimumRadiographyPercent('CATEGORY_D'));

        $reqs = $this->fs()->examinationFor('CATEGORY_D');
        self::assertCount(1, $reqs);
        self::assertStringContainsString('VISUAL EXAMINATION ONLY', $reqs[0]['text']);
    }

    public function test_severe_cyclic_conditions_add_to_whatever_service_is_there(): void
    {
        // THE POINT OF SPLITTING THE AXIS. 341.4.3 demands 100% radiography,
        // and it applies on top of the fluid service rather than instead of
        // it - so a Normal line under severe cyclic conditions gets both sets
        // of requirements and the stricter percentage.
        $plain = $this->fs()->examinationFor('NORMAL');
        $cyclic = $this->fs()->examinationFor('NORMAL', true);

        self::assertGreaterThan(count($plain), count($cyclic));
        self::assertSame(5, $this->fs()->minimumRadiographyPercent('NORMAL'));
        self::assertSame(100, $this->fs()->minimumRadiographyPercent('NORMAL', true));

        // And it works on a service that is not Normal, which the old enum
        // made impossible to express at all.
        self::assertSame(100, $this->fs()->minimumRadiographyPercent('CATEGORY_M', true));
    }

    public function test_every_extent_rule_points_at_something_real(): void
    {
        $known = array_merge($this->fs()->services(), $this->fs()->conditions());

        $pack = json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/fluid-service.ref.json'),
        ), true);

        foreach ($pack['examinationExtent'] as $e) {
            self::assertContains($e['appliesTo'], $known);
            self::assertTrue($e['verified']);
            self::assertStringContainsString('341.4', $e['clause']);
        }
    }

    public function test_the_slice_says_what_it_did_not_build(): void
    {
        // Three slices were proposed. Slice 1 took the fluid services and the
        // examination extent; slice 2 took the thickness equation and the
        // Table A-1 product forms, so 'material groupings' left this list -
        // which is what a slice list is FOR. Slice 3 is the allowable
        // stresses, and the pack still says so.
        $pack = json_decode((string) file_get_contents(
            base_path('../standards-packs/reference/fluid-service.ref.json'),
        ), true);

        self::assertStringContainsString('SLICE 1', $pack['source']);
        self::assertStringContainsString('SLICE 2', $pack['source']);
        self::assertStringContainsString('are slice 3 and are NOT here', $pack['source']);

        self::assertContains(
            'Table A-1 BASIC ALLOWABLE STRESSES (the S in the equation)',
            $pack['notBuiltInThisSlice']['items'],
        );
        self::assertNotContains(
            'Table A-1 material groupings',
            $pack['notBuiltInThisSlice']['items'],
            'slice 2 built the product-form groupings; the list must move with the work',
        );
    }
}
