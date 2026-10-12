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
 * P-24 Step B: the publish gate runs the ASME B31.3 para 304.3.3
 * area-replacement check on every branch-table cell at its worst case.
 *
 * Fixture class (computed from the reference packs, not invented):
 *   - A106 B seamless, SCH 40 everywhere; S = 138 MPa at 200 deg C,
 *     E = W = 1, Y = 0.4; mill tolerance 0.875;
 *   - design basis 3.0 MPa / 200 deg C (class attributes);
 *   - corrosion allowance 1.5 mm below DN250, 3.0 mm from DN250 up.
 *
 * Known verdicts of the footprint matrices at those inputs:
 *   NONE on [15,250)^2               worst margin +17.4 (DN20 x DN15)  -> adequate
 *   PAD 6.35 on [250,350)x[125,175)  worst margin +761.9 (DN300 x DN125) -> adequate
 *   NONE on [250,350)x[125,175)      worst margin -94.3 (DN300 x DN150) -> refused
 *   PAD 0.2 on the same footprint    worst margin -62.7 (DN300 x DN150) -> refused
 */
final class PipingPadGateTest extends TestCase
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
     * Draft class with schedule / corrosion allowance / material rules and
     * the design basis already in place — branch table left to the test.
     */
    private function readyClass(string $code = 'PAD-CLS', ?array $material = null): PipingClass
    {
        $material ??= ['spec' => 'A106', 'grade' => 'B'];

        return $this->asPars(function () use ($code, $material): PipingClass {
            $class = $this->classes->create(
                $this->project(), $this->user(), $code, 'Class '.$code, 'کلاس '.$code,
                15, 600, 'en'
            );

            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::PIPE_SCHEDULE, 15, 600, ['schedule' => '40'], 'en');
            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::CORROSION_ALLOWANCE, 15, 250, ['ca_mm' => 1.5], 'en');
            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::CORROSION_ALLOWANCE, 250, 600, ['ca_mm' => 3.0], 'en');
            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::PIPE_MATERIAL, 15, 600, $material, 'en');
            $this->classes->setDesignBasis($this->project(), $this->user(), $class, 3.0, 200.0, 'en');

            return $class;
        });
    }

    public function test_publish_passes_when_every_cell_is_adequate(): void
    {
        $class = $this->readyClass();

        $this->asPars(function () use ($class): void {
            // Bare connection is genuinely adequate here (worst +17.4).
            $this->classes->addBranchRule($this->project(), $this->user(), $class, 15, 250, 15, 250, 'TEE', 'en');
            // Pad of 6.35 mm covers the heavier footprint (worst +761.9).
            $this->classes->addBranchRule($this->project(), $this->user(), $class, 250, 350, 125, 175, 'P2P', 'en', 'STUB_IN', 'PAD', 6.35);
        });

        $published = $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $class, 'en'));

        $this->assertSame('PUBLISHED', $published->state);
    }

    public function test_publish_refuses_none_cell_where_reinforcement_is_required(): void
    {
        $class = $this->readyClass('NOGAP-CLS');

        $this->asPars(fn () => $this->classes->addBranchRule(
            $this->project(), $this->user(), $class, 250, 350, 125, 175, 'TEE', 'en'
        ));

        try {
            $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $class, 'en'));
            $this->fail('A NONE cell that fails para 304.3.3 must not publish.');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['branch_rules'] ?? []);
            $this->assertStringContainsString('304.3.3', $message);
            $this->assertStringContainsString('DN300 x DN150', $message);
            $this->assertStringContainsString('pad of at least', $message);
        }
    }

    public function test_publish_refuses_an_insufficient_recorded_pad(): void
    {
        $class = $this->readyClass('THINPAD-CLS');

        $this->asPars(fn () => $this->classes->addBranchRule(
            $this->project(), $this->user(), $class, 250, 350, 125, 175, 'P2P', 'en',
            'STUB_IN', 'PAD', 0.2
        ));

        try {
            $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $class, 'en'));
            $this->fail('A recorded pad thinner than required must not publish.');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['branch_rules'] ?? []);
            $this->assertStringContainsString('insufficient', $message);
            $this->assertStringContainsString('DN300 x DN150', $message);
        }
    }

    public function test_publish_refuses_when_a_supporting_rule_is_missing(): void
    {
        // Same class but WITHOUT corrosion-allowance rules: the gate cannot
        // compute the joint, and what it cannot check it must refuse.
        $class = $this->asPars(function (): PipingClass {
            $class = $this->classes->create(
                $this->project(), $this->user(), 'NOCA-CLS', 'No CA class', 'کلاس بدون اضافه‌ضخامت',
                15, 600, 'en'
            );

            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::PIPE_SCHEDULE, 15, 600, ['schedule' => '40'], 'en');
            $this->classes->addRule($this->project(), $this->user(), $class, PipingRuleKind::PIPE_MATERIAL, 15, 600, ['spec' => 'A106', 'grade' => 'B'], 'en');
            $this->classes->setDesignBasis($this->project(), $this->user(), $class, 3.0, 200.0, 'en');
            $this->classes->addBranchRule($this->project(), $this->user(), $class, 15, 250, 15, 250, 'TEE', 'en');

            return $class;
        });

        try {
            $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $class, 'en'));
            $this->fail('A cell whose inputs are incomplete must not publish.');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['branch_rules'] ?? []);
            $this->assertStringContainsString('CORROSION_ALLOWANCE', $message);
        }
    }

    public function test_manual_s_override_drives_the_check(): void
    {
        // Owner decision 2026-10-12: manual entry remains an override. A pack
        // row does not exist for "A333 grade 99" — so its Section IX P-No and
        // Group No must be entered with it — yet the override gives the gate
        // the same 138 MPa the A106 B fixture uses, so the same cells pass.
        $class = $this->readyClass('OVERRIDE-CLS', [
            'spec' => 'A333', 'grade' => '99', 's_mpa_override' => 138.0,
            'p_no' => '11', 'group_no' => 1,
        ]);

        $this->asPars(fn () => $this->classes->addBranchRule(
            $this->project(), $this->user(), $class, 15, 250, 15, 250, 'TEE', 'en'
        ));

        $published = $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $class, 'en'));
        $this->assertSame('PUBLISHED', $published->state);
    }
}
