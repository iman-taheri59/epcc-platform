<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PipingClass;
use App\Models\Project;
use App\Models\User;
use App\Services\PipingClassService;
use App\Support\Piping\PipingRuleKind;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * P-24 (flange step): the publish gate rates every FLANGE_TYPE cell against
 * the class design conditions per the ASME B16.5-2025 tables.
 *
 * Fixture basis (all ratings read from the extracted pack):
 *   - design basis 3.0 MPa / 200 deg C -> 30 bar required;
 *   - Group 1.1 (A105) at 200 deg C: Class 150 = 13.8 bar (fails),
 *     Class 300 = 43.8 bar (passes);
 *   - Group 2.1 (A182 F304) ladder top is 816 deg C, and Class 150 is
 *     retired (ellipsis) from 550 deg C up.
 */
final class PipingFlangeRatingGateTest extends TestCase
{
    private TenantContext $tenant;

    private PipingClassService $classes;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->tenant = app(TenantContext::class);
        $this->classes = app(PipingClassService::class);
    }

    private function project(): Project
    {
        return $this->tenant->forIdentityLookup(
            fn () => Project::where('code', 'PH-14')->firstOrFail()
        );
    }

    private function company(string $code): Company
    {
        return $this->tenant->forIdentityLookup(
            fn () => Company::where('code', $code)->firstOrFail()
        );
    }

    private function user(): User
    {
        return $this->userByEmail('rezaei@pars.example');
    }

    /** @return mixed */
    private function asPars(callable $fn): mixed
    {
        $pars = $this->company('EPC-PARS');

        return $this->tenant->runAs($pars->id, $fn, $this->project()->id);
    }

    /**
     * Draft class fully covered by the mandatory one-dimensional rules and a
     * design basis; the FLANGE_TYPE cell and the basis are left to the test.
     */
    private function readyClass(string $code, float $designMpa, float $designTempC): PipingClass
    {
        return $this->asPars(function () use ($code, $designMpa, $designTempC): PipingClass {
            $class = $this->classes->create(
                $this->project(), $this->user(), $code, 'Class '.$code, 'کلاس '.$code,
                15, 600, 'en'
            );

            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::PIPE_SCHEDULE, 15, 600, ['schedule' => '40'], 'en');
            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::CORROSION_ALLOWANCE, 15, 600, ['ca_mm' => 1.5], 'en');
            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::PIPE_MATERIAL, 15, 600, ['spec' => 'A106', 'grade' => 'B'], 'en');
            $this->classes->setDesignBasis($this->project(), $this->user(), $class, $designMpa, $designTempC, 'en');

            return $class;
        });
    }

    public function test_publish_refuses_an_underrated_flange_class(): void
    {
        // 3.0 MPa = 30 bar; A105 Class 150 at 200 deg C is rated 13.8 bar.
        $class = $this->readyClass('FLG-LOW', 3.0, 200.0);

        $this->asPars(function () use ($class): void {
            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::FLANGE_TYPE, 15, 600,
                ['rating_class' => 150, 'spec' => 'A105'], 'en');
        });

        try {
            $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $class, 'en'));
            $this->fail('publish must refuse an under-rated flange class');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('flange_rules', $e->errors());
            $message = implode(' ', $e->errors()['flange_rules']);
            $this->assertStringContainsString('Class 150', $message);
            $this->assertStringContainsString('13.8', $message);
        }
    }

    public function test_publish_passes_when_the_flange_class_covers_the_design_basis(): void
    {
        // A105 Class 300 at 200 deg C is rated 43.8 bar >= 30 bar required.
        $class = $this->readyClass('FLG-OK', 3.0, 200.0);

        $this->asPars(function () use ($class): void {
            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::FLANGE_TYPE, 15, 600,
                ['rating_class' => 300, 'spec' => 'A105'], 'en');
        });

        $published = $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $class, 'en'));

        $this->assertSame('PUBLISHED', $published->state);
    }

    public function test_flange_rule_rejects_unknown_material_at_entry(): void
    {
        $class = $this->readyClass('FLG-SPEC', 3.0, 200.0);

        try {
            $this->asPars(function () use ($class): void {
                $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::FLANGE_TYPE, 15, 600,
                    ['rating_class' => 300, 'spec' => 'A999'], 'en');
            });
            $this->fail('an unknown flange material must be refused at entry');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('spec', $e->errors());
        }
    }

    public function test_flange_rule_rejects_a_class_off_the_b16_5_ladder(): void
    {
        $class = $this->readyClass('FLG-CLS', 3.0, 200.0);

        try {
            $this->asPars(function () use ($class): void {
                $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::FLANGE_TYPE, 15, 600,
                    ['rating_class' => 175, 'spec' => 'A105'], 'en');
            });
            $this->fail('a non-standard rating class must be refused at entry');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rating_class', $e->errors());
        }
    }

    public function test_publish_refuses_design_temperature_above_the_rating_ladder(): void
    {
        // A182 F304 (group 2.1) is rated to 816 deg C; 820 has no rating.
        $class = $this->readyClass('FLG-HOT', 3.0, 820.0);

        $this->asPars(function () use ($class): void {
            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::FLANGE_TYPE, 15, 600,
                ['rating_class' => 300, 'spec' => 'A182', 'grade' => 'F304'], 'en');
        });

        try {
            $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $class, 'en'));
            $this->fail('publish must refuse a temperature above the ladder top');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('flange_rules', $e->errors());
            $this->assertStringContainsString('816', implode(' ', $e->errors()['flange_rules']));
        }
    }

    public function test_publish_refuses_a_class_retired_at_the_design_temperature(): void
    {
        // Group 2.1 retires Class 150 from 550 deg C up (printed ellipsis).
        $class = $this->readyClass('FLG-CUT', 0.5, 550.0);

        $this->asPars(function () use ($class): void {
            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::FLANGE_TYPE, 15, 600,
                ['rating_class' => 150, 'spec' => 'A182', 'grade' => 'F304'], 'en');
        });

        try {
            $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $class, 'en'));
            $this->fail('publish must refuse a retired pressure class');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('flange_rules', $e->errors());
            $this->assertStringContainsString('retires Class 150', implode(' ', $e->errors()['flange_rules']));
        }
    }
}
