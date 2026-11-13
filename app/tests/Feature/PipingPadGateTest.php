<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PipingClass;
use App\Models\Project;
use App\Models\User;
use App\Services\BranchConnectionPadGate;
use App\Services\PipingClassService;
use App\Support\Piping\PipingRuleKind;
use App\Support\Reference\MaterialReference;
use App\Support\Reference\PipeReference;
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
 *   NONE on [50,250)x[15,50)         worst margin +18.8 (DN200 x DN15) -> adequate
 *   PAD 6.35 on [250,350)x[125,175)  worst margin +448.7 (DN300 x DN125) -> adequate
 *   NONE on [250,350)x[125,175)      worst margin -371.4 (DN300 x DN150) -> refused
 *   PAD 0.2 on the same footprint    worst margin -343.5 (DN300 x DN150) -> refused
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
            $this->classes->setFluidServiceCategory($this->project(), $this->user(), $class, 'NORMAL', 'en');
            $this->classes->setDesignBasis($this->project(), $this->user(), $class, 3.0, 200.0, 'en');

            return $class;
        });
    }

    public function test_publish_passes_when_every_cell_is_adequate(): void
    {
        $class = $this->readyClass();

        $this->asPars(function () use ($class): void {
            // Bare connection is genuinely adequate across the swept beta
            // domain for this narrower header/branch footprint (worst +18.8).
            $this->classes->addBranchRule($this->project(), $this->user(), $class, 50, 250, 15, 50, 'TEE', 'en');
            // Pad of 6.35 mm covers the heavier footprint (worst +448.7).
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

    public function test_publish_reduces_pad_credit_for_a_weaker_explicit_pms_pad_material(): void
    {
        $class = $this->readyClass('WEAKPADMAT-CLS');

        $this->asPars(fn () => $this->classes->addBranchRule(
            $this->project(), $this->user(), $class, 250, 350, 125, 175,
            'P2P', 'en', 'STUB_IN', 'PAD', 6.35,
            value: ['pad_material' => [
                'spec' => 'A333', 'grade' => '6', 's_mpa_override' => 20.0,
                'p_no' => '1', 'group_no' => 1,
            ]],
        ));

        try {
            $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $class, 'en'));
            $this->fail('A weak explicit PAD material must reduce A4 metal credit and can make the thickness insufficient.');
        } catch (ValidationException $exception) {
            $message = implode(' ', $exception->errors()['branch_rules'] ?? []);
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

    public function test_publish_refuses_a_pipe_wall_below_pressure_thickness_plus_allowance(): void
    {
        $class = $this->readyClass('THINWALL-CLS');

        $this->asPars(function () use ($class): void {
            $this->classes->addBranchRule(
                $this->project(), $this->user(), $class, 15, 250, 15, 250, 'TEE', 'en'
            );
            // This keeps P/(S·E) and t<D/6 inside the simple formula domain,
            // but SCH 40 with the class corrosion allowance is too thin.
            $this->classes->setDesignBasis($this->project(), $this->user(), $class, 35.0, 200.0, 'en');
        });

        try {
            $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $class, 'en'));
            $this->fail('A pipe wall below t+c must not be accepted through a branch area balance.');
        } catch (ValidationException $e) {
            $message = implode(' ', $e->errors()['branch_rules'] ?? []);
            $this->assertStringContainsString('wall after mill tolerance', $message);
            $this->assertStringContainsString('DN', $message);
        }
    }

    public function test_gate_reduces_branch_wall_area_by_the_branch_to_run_allowable_stress_ratio(): void
    {
        $gate = new BranchConnectionPadGate(
            app(PipeReference::class),
            app(MaterialReference::class),
        );

        // DN300 run: A106 B, SCH 20, S=138 MPa; DN150 branch: A106 A,
        // SCH 120, S=110 MPa. At 2.9 MPa, the controlling 45-degree case
        // fails when A3 is scaled by 110/138; omitting §304.3.3(c)(2) would
        // incorrectly turn this exact pair into a pass.
        $result = $gate->checkCell(
            pressureMpa: 2.9,
            temperatureC: 200.0,
            headerDns: [300],
            branchDns: [150],
            scheduleFor: static fn (int $dn): string => $dn === 300 ? '20' : '120',
            caFor: static fn (int $dn): float => 0.0,
            materialFor: static fn (int $dn): array => [
                'spec' => 'A106',
                'grade' => $dn === 300 ? 'B' : 'A',
                'psl' => null,
                'pipe_type' => null,
                's_mpa_override' => $dn === 300 ? 138.0 : 110.0,
                'ej_override' => 1.0,
                'p_no' => '1',
                'group_no' => '1',
            ],
            reinforcement: 'NONE',
            padThickness: null,
        );

        $this->assertFalse($result['pass']);
        $this->assertNotNull($result['worst']);
        $this->assertSame(45.0, $result['worst']['beta_deg']);
        $this->assertEqualsWithDelta(-47.5379, $result['worst']['margin'], 0.02);
        $this->assertStringContainsString('fails para 304.3.3', $result['violations'][0]);
    }

    public function test_pad_material_defaults_to_header_material_unless_the_pms_branch_rule_overrides_it(): void
    {
        $gate = new BranchConnectionPadGate(
            app(PipeReference::class),
            app(MaterialReference::class),
        );
        $inputs = [
            'pressureMpa' => 3.0,
            'temperatureC' => 200.0,
            'headerDns' => [250, 300],
            'branchDns' => [125, 150],
            'scheduleFor' => static fn (int $dn): string => '40',
            'caFor' => static fn (int $dn): float => $dn >= 250 ? 3.0 : 1.5,
            'materialFor' => static fn (int $dn): array => [
                'spec' => 'A106',
                'grade' => $dn >= 250 ? 'B' : 'A',
                'psl' => null,
                'pipe_type' => null,
                's_mpa_override' => $dn >= 250 ? 138.0 : 110.0,
                'ej_override' => 1.0,
                'p_no' => '1',
                'group_no' => '1',
            ],
            'reinforcement' => 'PAD',
            'padThickness' => 6.35,
        ];

        // No pad_material is declared, so the fallback is the header's 138 MPa
        // A106 B material, not the branch pipe's weaker 110 MPa material.
        $defaulted = $gate->checkCell(...$inputs);
        $this->assertTrue($defaulted['pass']);
        $this->assertNotNull($defaulted['worst']);
        $this->assertSame('HEADER_PIPE_MATERIAL', $defaulted['worst']['pad_material_source']);
        $this->assertSame(1.0, $defaulted['worst']['s_pad_over_run']);

        // A declared weaker PAD material is honored and its A4 metal credit is
        // reduced by min(1, S_pad/S_run).
        $explicit = $gate->checkCell(...[
            ...$inputs,
            'padMaterial' => [
                'spec' => 'A333', 'grade' => '6', 's_mpa_override' => 20.0,
                'p_no' => '1', 'group_no' => '1',
            ],
        ]);
        $this->assertFalse($explicit['pass']);
        $this->assertNotNull($explicit['worst']);
        $this->assertSame('BRANCH_RULE_PAD_MATERIAL', $explicit['worst']['pad_material_source']);
        $this->assertEqualsWithDelta(20.0 / 138.0, $explicit['worst']['s_pad_over_run'], 1e-12);
        $this->assertStringContainsString('insufficient', implode(' ', $explicit['violations']));

        // A stronger declared material cannot earn more A4 credit than the run.
        $stronger = $gate->checkCell(...[
            ...$inputs,
            'padMaterial' => [
                'spec' => 'A106', 'grade' => 'B', 's_mpa_override' => 200.0,
                'p_no' => '1', 'group_no' => '1',
            ],
        ]);
        $this->assertTrue($stronger['pass']);
        $this->assertNotNull($stronger['worst']);
        $this->assertSame('BRANCH_RULE_PAD_MATERIAL', $stronger['worst']['pad_material_source']);
        $this->assertSame(1.0, $stronger['worst']['s_pad_over_run']);

        // Reject a malformed optional field even when a valid stress override
        // is present; it must not silently erase the invalid material identity.
        $invalid = $gate->checkCell(...[
            ...$inputs,
            'padMaterial' => [
                'spec' => 'A106', 'grade' => 'B', 'psl' => 2,
                's_mpa_override' => 20.0,
            ],
        ]);
        $this->assertFalse($invalid['pass']);
        $this->assertStringContainsString('valid optional PSL', implode(' ', $invalid['violations']));
    }

    public function test_gate_uses_the_interior_beta_transition_as_the_worst_case(): void
    {
        $gate = new BranchConnectionPadGate(
            app(PipeReference::class),
            app(MaterialReference::class),
        );

        // The branch/header pair has a d2-regime transition at about 56.42°;
        // its area-balance margin is lower there than at either 45° or 90°.
        $result = $gate->checkCell(
            pressureMpa: 2.0,
            temperatureC: 200.0,
            headerDns: [150],
            branchDns: [40],
            scheduleFor: static fn (int $dn): string => $dn === 150 ? 'XXS' : '80',
            caFor: static fn (int $dn): float => 0.0,
            materialFor: static fn (int $dn): array => [
                'spec' => 'A106',
                'grade' => $dn === 150 ? 'B' : 'A',
                'psl' => null,
                'pipe_type' => null,
                's_mpa_override' => null,
                'ej_override' => null,
                'p_no' => '1',
                'group_no' => '1',
            ],
            reinforcement: 'NONE',
            padThickness: null,
        );

        $this->assertTrue($result['pass']);
        $this->assertNotNull($result['worst']);
        $this->assertEqualsWithDelta(56.4235215672, $result['worst']['beta_deg'], 1e-8);
        $this->assertEqualsWithDelta(894.7587236, $result['worst']['margin'], 1e-4);
    }

    public function test_gate_refuses_a_pad_below_the_figure_minimum_width_to_height_ratio(): void
    {
        $gate = new BranchConnectionPadGate(
            app(PipeReference::class),
            app(MaterialReference::class),
        );

        // NPS 6 x 1/2, SCH 40, c=0: the PAD is much thicker than L4.
        // The A4 area height is clipped to L4, but note (2)'s shape ratio is
        // physical span-minus-opening over the full minimum PAD thickness.
        $result = $gate->checkCell(
            pressureMpa: 1.0,
            temperatureC: 200.0,
            headerDns: [150],
            branchDns: [15],
            scheduleFor: static fn (int $dn): string => '40',
            caFor: static fn (int $dn): float => 0.0,
            materialFor: static fn (int $dn): array => [
                'spec' => 'A106',
                'grade' => 'B',
                'psl' => null,
                'pipe_type' => null,
                's_mpa_override' => 138.0,
                'ej_override' => 1.0,
                'p_no' => '1',
                'group_no' => '1',
            ],
            reinforcement: 'PAD',
            padThickness: 100.0,
        );

        $this->assertFalse($result['pass']);
        $this->assertStringContainsString('width-to-height ratio 0.142:1', $result['violations'][0]);
        $this->assertStringContainsString('304.3.3-1 note (2)', $result['violations'][0]);
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
            $this->project(), $this->user(), $class, 50, 250, 15, 50, 'TEE', 'en'
        ));

        $published = $this->asPars(fn () => $this->classes->publish($this->project(), $this->user(), $class, 'en'));
        $this->assertSame('PUBLISHED', $published->state);
    }
}
