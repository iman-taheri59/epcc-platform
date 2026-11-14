<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BranchPad;
use App\Models\Component;
use App\Models\Joint;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\BranchPadService;
use App\Services\ComponentService;
use App\Services\JointService;
use App\Services\PipingClassService;
use App\Services\PipingLineService;
use App\Services\PmsPinnedClassSpecification;
use App\Support\Piping\BranchConnectionType;
use App\Support\Piping\JointBranchKind;
use App\Support\Piping\P2pKind;
use App\Support\Piping\PipingRuleKind;
use App\Support\Reference\MaterialReference;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ApprovedPmsClassFixture;
use Tests\TestCase;

/**
 * A reinforcement pad is a physical component with two distinct, numbered
 * fillet welds. The issued PMS class of the header line governs all three.
 */
final class BranchPadTest extends TestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->tenant = app(TenantContext::class);
    }

    private function project(): Project
    {
        return Project::query()->where('code', 'PH-14')->firstOrFail();
    }

    private function owner(): User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    private function asOwner(callable $callback): mixed
    {
        $project = $this->project();

        return $this->tenant->runAs($project->owner_company_id, $callback, $project->id);
    }

    /**
     * Create two differently classified lines and a header pipe. The header
     * line can require a pad even when the branch register owner's class does
     * not, which is the ownership regression this feature must prevent.
     *
     * @param  list<array<string, mixed>>  $headerCells
     * @param  list<array<string, mixed>>  $branchCells
     * @param  array<string,mixed>  $headerMaterial
     * @param  array<string,mixed>  $branchMaterial
     * @return array{header: PipingLine, branch: PipingLine, headerPipe: Component}
     */
    private function twoLines(
        array $headerCells,
        array $branchCells,
        array $headerMaterial = ['spec' => 'A106', 'grade' => 'B'],
        array $branchMaterial = ['spec' => 'A106', 'grade' => 'B'],
    ): array {
        return $this->asOwner(function () use ($headerCells, $branchCells, $headerMaterial, $branchMaterial): array {
            $project = $this->project();
            $owner = $this->owner();
            $lines = app(PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);
            $subsystem = Subsystem::query()->where('code', 'PH-14-SS-2201')->firstOrFail();
            $authoring = app(PipingClassService::class);

            $headerClass = $this->classWithCells($project, $owner, $authoring, 'PAD-H-', $headerCells, $headerMaterial);
            $branchClass = $this->classWithCells($project, $owner, $authoring, 'PAD-B-', $branchCells, $branchMaterial);

            $header = $lines->create($subsystem, $owner, [
                'nominal_size' => '6', 'service_code' => 'P', 'piping_class_id' => $headerClass->id,
            ]);
            $branch = $lines->create($subsystem, $owner, [
                'nominal_size' => '2', 'service_code' => 'P', 'piping_class_id' => $branchClass->id,
            ]);
            $headerPipe = app(ComponentService::class)->place($header, $owner, 'PIPE', ['length_mm' => 6000]);

            return ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     * @param  array<string,mixed>  $material
     */
    private function classWithCells(
        Project $project,
        User $owner,
        PipingClassService $authoring,
        string $prefix,
        array $cells,
        array $material,
    ): PipingClass {
        $class = PipingClass::query()->firstOrCreate(
            ['project_id' => $project->id, 'code' => $prefix.substr(md5((string) json_encode($cells)), 0, 8)],
            ['company_id' => $project->owner_company_id, 'description_en' => 'Branch pad test class'],
        );

        // Geometry needs the actual schedule walls and issued CA on both
        // physical lines; the header PAD rule alone is not a pipe specification.
        $authoring->addRule($project, $owner, $class, PipingRuleKind::PIPE_SCHEDULE, 15, 600, ['schedule' => '80'], 'en');
        $authoring->addRule($project, $owner, $class, PipingRuleKind::CORROSION_ALLOWANCE, 15, 600, ['ca_mm' => 1.5], 'en');
        $authoring->addRule($project, $owner, $class, PipingRuleKind::PIPE_MATERIAL, 15, 600, $material, 'en');
        $authoring->setDesignBasis($project, $owner, $class, 1.0, 200.0, 'en');

        foreach ($cells as $cell) {
            $authoring->addBranchRule(
                $project,
                $owner,
                $class,
                $cell['header'][0], $cell['header'][1],
                $cell['branch'][0], $cell['branch'][1],
                $cell['type'],
                'fa',
                $cell['p2p_kind'] ?? null,
                $cell['reinforcement'] ?? 'NONE',
                $cell['pad_thickness'] ?? null,
                $cell['value'] ?? null,
            );
        }

        ApprovedPmsClassFixture::ensure($project, $owner, $class);

        return $class;
    }

    /** @return array{root: Joint, branchPipe: Component} */
    private function directP2pRoot(
        PipingLine $branch,
        Component $headerPipe,
        string $owner = 'BRANCH',
        string $kind = JointBranchKind::STUB_IN,
        float $offsetMm = 0.0,
        float $betaDeg = 90.0,
    ): array {
        return $this->asOwner(function () use ($branch, $headerPipe, $owner, $kind, $offsetMm, $betaDeg): array {
            $branchPipe = app(ComponentService::class)->place($branch, $this->owner(), 'PIPE');
            $root = app(JointService::class)->joinToWall(
                $branchPipe->ports->first(),
                $headerPipe,
                $kind,
                3000.0,
                $offsetMm,
                $betaDeg,
                $this->owner(),
                ['line_owner' => $owner],
            );

            return ['root' => $root, 'branchPipe' => $branchPipe];
        });
    }

    /** @return list<array<string, mixed>> */
    private function padRule(
        float $thickness = 8.5,
        string $kind = P2pKind::STUB_IN,
        ?array $padMaterial = null,
    ): array {
        return [[
            'header' => [100, 300],
            'branch' => [15, 100],
            'type' => BranchConnectionType::P2P,
            'p2p_kind' => $kind,
            'reinforcement' => 'PAD',
            'pad_thickness' => $thickness,
            'value' => $padMaterial === null ? null : ['pad_material' => $padMaterial],
        ]];
    }

    /** @return list<array<string, mixed>> */
    private function noPadRule(string $kind = P2pKind::STUB_IN): array
    {
        return [[
            'header' => [100, 300],
            'branch' => [15, 100],
            'type' => BranchConnectionType::P2P,
            'p2p_kind' => $kind,
            'reinforcement' => 'NONE',
        ]];
    }

    public function test_install_creates_one_physical_pad_and_two_numbered_header_owned_fillet_welds(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(8.5),
            $this->noPadRule(),
        );
        ['root' => $root, 'branchPipe' => $branchPipe] = $this->directP2pRoot($branch, $headerPipe);

        // Give the header register one pre-existing number so the two pad
        // connections can be checked as independent, monotonic sequence entries.
        $headerEnd = $this->asOwner(fn () => app(ComponentService::class)->attach(
            $headerPipe->ports->first(), $this->owner(), 'PIPE',
        )['joint']);

        $pad = $this->asOwner(fn () => app(BranchPadService::class)->install($root, $this->owner()));
        $pad = $this->asOwner(fn () => $pad->fresh([
            'component.ports', 'headerLine', 'pmsClassSnapshot', 'branchRootJoint',
            'headerJoint.portA', 'branchJoint.portA',
        ]));
        $joints = $this->asOwner(fn () => Joint::query()
            ->where('branch_pad_id', $pad->id)
            ->orderBy('joint_seq')
            ->with(['portA', 'branchWallComponent'])
            ->get());
        $headerJoint = $joints->firstWhere('branch_pad_side', 'HEADER');
        $branchJoint = $joints->firstWhere('branch_pad_side', 'BRANCH');

        $this->assertSame('BRANCH_PAD', $pad->component->template_code);
        $this->assertSame($branch->id, $pad->component->piping_line_id, 'the pad remains on its physical branch line');
        $this->assertSame($root->id, $pad->branch_root_joint_id);
        $this->assertSame($headerPipe->id, $pad->header_pipe_component_id);
        $this->assertSame($branchPipe->id, $pad->branch_pipe_component_id);
        $this->assertSame($header->id, $pad->header_line_id);
        $this->assertSame($header->pms_class_snapshot_id, $pad->pms_class_snapshot_id);
        $this->assertSame(8.5, (float) $pad->pad_thickness_mm, 'thickness comes from the header-issued class, not the branch line');
        $this->assertSame(108.581, (float) $pad->pad_major_axis_mm);
        $this->assertSame(107.21, (float) $pad->pad_minor_axis_mm);
        $this->assertSame('AROUND_HEADER', $pad->pad_major_axis_direction);
        $this->assertSame('ALONG_HEADER', $pad->pad_minor_axis_direction);
        $this->assertSame(53.605, (float) $pad->pad_geometry_d2_mm);
        $this->assertSame(90.0, (float) $pad->pad_geometry_beta_deg);
        $this->assertSame('CENTERED_90_DEGREE_SADDLE_PERIMETER_V2', $pad->pad_geometry_method);
        $this->assertSame('JOINT_BRANCH_LONGITUDINAL_ANGLE_DEG', $pad->pad_geometry_snapshot['assumptions']['branch_angle_source']);
        $this->assertEqualsWithDelta(90.0, (float) $pad->pad_geometry_snapshot['assumptions']['branch_angle_deg'], 1e-9);
        $this->assertFalse($pad->pad_geometry_snapshot['assumptions']['circumferential_clocking_theta_used']);
        $this->assertEquals(0.0, (float) $pad->pad_geometry_snapshot['assumptions']['fit_up_clearance_mm']);
        $this->assertEquals(0.0, (float) $pad->pad_geometry_snapshot['assumptions']['fabrication_tolerance_mm']);
        $contactProfile = $pad->pad_geometry_snapshot['saddle']['contact_profile'];
        $outerPerimeter = $pad->pad_geometry_snapshot['saddle']['outer_perimeter_profile'];
        $this->assertSame('CENTERED_90_DEGREE_OUTER_CYLINDER_CONTACT_V1', $contactProfile['method']);
        $this->assertEqualsWithDelta(61.670898, (float) $contactProfile['developed_contact_arc_span_mm'], 0.000001);
        $this->assertCount(65, $contactProfile['sampled_points_mm']);
        $this->assertFalse($contactProfile['outer_pad_perimeter_defined']);
        $this->assertSame('CENTERED_90_DEGREE_DEVELOPED_CONTACT_OFFSET_V1', $outerPerimeter['method']);
        $this->assertEqualsWithDelta(23.455, (float) $outerPerimeter['edge_extension_mm'], 1e-6);
        $this->assertEqualsWithDelta(108.580898, (float) $outerPerimeter['developed_circumferential_span_mm'], 0.000001);
        $this->assertEqualsWithDelta(107.21, (float) $outerPerimeter['span_along_header_mm'], 0.000001);
        $this->assertGreaterThan(64, count($outerPerimeter['sampled_points_mm']));
        $this->assertFalse($outerPerimeter['is_fabrication_outline']);

        $materials = $pad->pad_geometry_snapshot['materials'];
        $this->assertSame('HEADER_LINE_ISSUED_PMS_PIPE_MATERIAL_RULE', $materials['header_pipe']['source']);
        $this->assertSame(['spec' => 'A106', 'grade' => 'B'], array_intersect_key(
            $materials['header_pipe']['identity'], ['spec' => true, 'grade' => true],
        ));
        $this->assertSame('HEADER_LINE_ISSUED_PMS_PIPE_MATERIAL_DEFAULT', $materials['pad']['source']);
        $this->assertTrue($materials['pad']['defaulted_from_header']);
        $this->assertSame($materials['header_pipe']['identity'], $materials['pad']['identity']);
        $this->assertSame(200.0, (float) $materials['pad']['design_temperature_c']);
        $this->assertEqualsWithDelta(138.0, (float) $materials['header_pipe']['allowable_stress_mpa_at_design_temperature'], 1e-9);
        $this->assertEqualsWithDelta(138.0, (float) $materials['pad']['allowable_stress_mpa_at_design_temperature'], 1e-9);
        $this->assertEqualsWithDelta(1.0, (float) $materials['pad']['allowable_stress_ratio_to_run'], 1e-12);

        $a4 = $pad->pad_geometry_snapshot['a4_area_check'];
        $this->assertEquals(0.875, (float) $pad->pad_geometry_snapshot['inputs']['pad_mill_tolerance_factor']);
        $this->assertEqualsWithDelta(7.438, (float) $pad->pad_geometry_snapshot['inputs']['pad_minimum_thickness_for_area_mm'], 0.001);
        $this->assertEqualsWithDelta(15.806, (float) $pad->pad_geometry_snapshot['reinforcement_zone']['l4_mm'], 0.001);
        $this->assertEqualsWithDelta(107.21, (float) $a4['pad_span_along_run_mm'], 0.001);
        $this->assertEqualsWithDelta(46.91, (float) $a4['pad_width_within_zone_mm'], 0.001);
        $this->assertEqualsWithDelta(7.438, (float) $a4['pad_height_within_zone_mm'], 0.001);
        $this->assertEqualsWithDelta(7.438, (float) $a4['pad_thickness_for_width_height_ratio_mm'], 0.001);
        $this->assertSame('PAD_WIDTH_WITHIN_REINFORCEMENT_ZONE / FULL_MINIMUM_PAD_THICKNESS', $a4['width_to_height_ratio_basis']);
        $this->assertEqualsWithDelta(6.307, (float) $a4['width_to_height_ratio'], 0.001);
        $this->assertSame(1.0, (float) $a4['minimum_width_to_height_ratio']);
        $this->assertSame(4.0, (float) $a4['preferred_width_to_height_ratio']);
        $this->assertTrue($a4['preferred_ratio_is_advisory']);
        $this->assertEqualsWithDelta(348.893, (float) $a4['pad_metal_area_mm2_before_stress_ratio'], 0.001);
        $this->assertEqualsWithDelta(348.893, (float) $a4['pad_metal_area_mm2_credited'], 0.001);
        $this->assertEqualsWithDelta(30.078, (float) $a4['root_weld_area_credit_mm2'], 0.001);
        $this->assertEqualsWithDelta(36.125, (float) $a4['pad_edge_weld_area_credit_mm2'], 0.001);
        $this->assertEqualsWithDelta(415.096, (float) $a4['total_pad_and_credited_weld_area_mm2'], 0.001);

        $weldRequirements = $pad->pad_geometry_snapshot['pad_weld_requirements'];
        $this->assertEqualsWithDelta(3.878, (float) $weldRequirements['root_branch_to_run']['minimum_throat_mm'], 0.001);
        $this->assertTrue($weldRequirements['root_branch_to_run']['credited_in_area_balance']);
        $this->assertEqualsWithDelta(4.25, (float) $weldRequirements['pad_to_header']['minimum_throat_mm'], 0.001);
        $this->assertSame('HEADER', $weldRequirements['pad_to_header']['joint_side']);
        $this->assertTrue($weldRequirements['pad_to_header']['credited_in_area_balance']);
        $this->assertEqualsWithDelta(3.878, (float) $weldRequirements['pad_to_branch']['minimum_throat_mm'], 0.001);
        $this->assertSame('BRANCH', $weldRequirements['pad_to_branch']['joint_side']);
        $this->assertFalse($weldRequirements['pad_to_branch']['credited_in_area_balance']);
        $this->assertStringContainsString('actual as-welded size is not recorded', $weldRequirements['basis']);

        $this->assertCount(2, $joints);
        $this->assertNotSame($headerJoint->id, $branchJoint->id);
        $this->assertNotSame($headerJoint->joint_seq, $branchJoint->joint_seq);
        $this->assertSame($headerEnd->joint_seq + 1, $headerJoint->joint_seq);
        $this->assertSame($headerJoint->joint_seq + 1, $branchJoint->joint_seq);
        $this->assertSame($header->id, $headerJoint->piping_line_id);
        $this->assertSame($header->id, $branchJoint->piping_line_id);
        $this->assertSame(Joint::TYPE_FILLET_WELD, $headerJoint->joint_type);
        $this->assertSame(Joint::TYPE_FILLET_WELD, $branchJoint->joint_type);
        $this->assertSame('HEADER', $headerJoint->branch_pad_side);
        $this->assertSame('BRANCH', $branchJoint->branch_pad_side);
        $this->assertSame($root->id, $headerJoint->branch_root_joint_id);
        $this->assertSame($root->id, $branchJoint->branch_root_joint_id);
        $this->assertSame($headerPipe->id, $headerJoint->branch_wall_component_id);
        $this->assertSame($branchPipe->id, $branchJoint->branch_wall_component_id);
        $this->assertSame($pad->id, $headerJoint->branch_pad_id);
        $this->assertSame($pad->id, $branchJoint->branch_pad_id);
        $this->assertSame($headerJoint->branch_pad_thickness_mm, $branchJoint->branch_pad_thickness_mm);
        $this->assertSame(8.5, (float) $headerJoint->branch_pad_thickness_mm);
        $this->assertSame(BranchConnectionType::P2P, $headerJoint->branch_class_type);
        $this->assertSame(P2pKind::STUB_IN, $headerJoint->branch_class_p2p_kind);
        $this->assertNull($headerJoint->branch_kind, 'a pad fillet is not an extra branch-root connection');
        $this->assertNull($branchJoint->branch_kind, 'a pad fillet is not an extra branch-root connection');
        $this->assertTrue($headerJoint->isBranchPadFillet());
        $this->assertTrue($branchJoint->isBranchPadFillet());
        $this->assertNull(app(JointService::class)->diaInch($headerJoint), 'fillet dia-inch is not inferred from NPS');
        $this->assertNull(app(JointService::class)->diaInch($branchJoint), 'fillet dia-inch is not inferred from NPS');
        $this->assertCount(2, $pad->component->ports);
        $this->assertTrue($pad->component->ports->every(fn (Port $port): bool => $port->is_connected));

        $reloadedRoot = $this->asOwner(fn () => Joint::query()->findOrFail($root->id));
        $this->assertSame($branch->id, $reloadedRoot->piping_line_id, 'creating a pad must not reassign the physical root');
        $this->assertSame($branch->id, $this->asOwner(fn () => Joint::query()->with('portA.component')->findOrFail($root->id)->portA->component->piping_line_id,
        ));

        $headerMetrics = $this->asOwner(fn () => app(JointService::class)->lineMetrics($header->fresh()));
        $branchMetrics = $this->asOwner(fn () => app(JointService::class)->lineMetrics($branch->fresh()));
        $this->assertSame(3, $headerMetrics['welds'], 'one ordinary weld plus two pad fillets are registered on the header');
        $this->assertSame(2, $headerMetrics['branch_pad_welds']);
        $this->assertSame(['HEADER' => 1, 'BRANCH' => 1], $headerMetrics['branch_pad_by_side']);
        $this->assertSame(0, $headerMetrics['branch_welds'], 'pad fillets are not root-branch metrics');
        $this->assertSame(6.0, $headerMetrics['dia_inch'], 'only the ordinary header weld contributes dia-inch');
        $this->assertSame(1, $branchMetrics['branch_welds'], 'the physical P2P root is counted once');
        $this->assertSame(1, $branchMetrics['welds'], 'header-owned pad welds do not inflate branch-line register totals');
        $this->assertSame(2, $branchMetrics['branch_pad_welds_on_line_registered_elsewhere']);

        $this->assertCount(2, $this->asOwner(fn () => app(ComponentService::class)->openPorts($header->fresh())));

        $this->actingAs($this->owner())
            ->get('/projects/PH-14/piping/'.$branch->id.'/model?lang=en')
            ->assertOk()
            ->assertSee('Major axis, around header')
            ->assertSee('Minor axis, along header')
            ->assertSee('data-outer-perimeter')
            ->assertSee('CENTERED_90_DEGREE_SADDLE_PERIMETER_V2')
            ->assertSee('108.581')
            ->assertSee('107.21')
            ->assertSee('Branch angle β')
            ->assertSee('A106 B')
            ->assertSee('defaulted from header')
            ->assertSee('PAD/run stress ratio')
            ->assertSee('PAD fillet-weld minimums')
            ->assertSee('Pad-to-header fillet')
            ->assertSee('Pad-to-branch-pipe fillet')
            ->assertSee('Minimum design throat')
            ->assertSee('Theoretical equal-leg equivalent')
            ->assertSee('4.25 mm')
            ->assertSee('3.878 mm')
            ->assertSee('Actual as-welded size and inspection acceptance are not recorded or verified here.')
            ->assertSee('ASME B31.3-2024 §328.5.4(f)')
            ->assertSee('ASME B31.3-2024 §328.5.4(e)(2)');

        $this->actingAs($this->owner())
            ->get('/projects/PH-14/piping/'.$branch->id.'/model?lang=fa')
            ->assertOk()
            ->assertSee('حداقل‌های دو جوش فیلت PAD')
            ->assertSee('حداقل گلویی طراحی')
            ->assertSee('معادل نظری اندازهٔ پای مساوی')
            ->assertSee('اندازهٔ واقعی جوش و پذیرش بازرسی در اینجا ثبت یا تأیید نمی‌شود.');
    }

    public function test_default_pad_material_comes_from_header_issued_pms_not_branch_line_material(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(),
            $this->noPadRule(),
            ['spec' => 'A106', 'grade' => 'B'],
            ['spec' => 'A106', 'grade' => 'A'],
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe);

        $branchMaterial = $this->asOwner(fn () => app(PmsPinnedClassSpecification::class)->ruleValue(
            $branch,
            PipingRuleKind::PIPE_MATERIAL,
            50,
        ));
        $this->assertSame('A', $branchMaterial['grade']);

        $pad = $this->asOwner(fn () => app(BranchPadService::class)->install($root, $this->owner()));
        $materials = $pad->pad_geometry_snapshot['materials'];

        $this->assertSame($header->id, $pad->header_line_id);
        $this->assertSame($header->pms_class_snapshot_id, $pad->pms_class_snapshot_id);
        $this->assertSame('B', $materials['header_pipe']['identity']['grade']);
        $this->assertSame('B', $materials['pad']['identity']['grade']);
        $this->assertSame('HEADER_LINE_ISSUED_PMS_PIPE_MATERIAL_DEFAULT', $materials['pad']['source']);
        $this->assertSame(200.0, (float) $materials['pad']['design_temperature_c']);
        $this->assertEqualsWithDelta(138.0, (float) $materials['pad']['allowable_stress_mpa_at_design_temperature'], 1e-9);
        $this->assertEqualsWithDelta(1.0, (float) $materials['pad']['allowable_stress_ratio_to_run'], 1e-9);
    }

    public function test_explicit_pms_pad_material_overrides_header_material_and_scales_a4_credit(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(8.5, P2pKind::STUB_IN, ['spec' => 'A106', 'grade' => 'A']),
            $this->noPadRule(),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe);

        $pad = $this->asOwner(fn () => app(BranchPadService::class)->install($root, $this->owner()));
        $snapshot = $pad->pad_geometry_snapshot;
        $materials = $snapshot['materials'];
        $padMaterial = $materials['pad'];
        $a4 = $snapshot['a4_area_check'];
        $reference = app(MaterialReference::class);
        $headerStress = $reference->allowableStress('A106', 'B', 200.0);
        $padStress = $reference->allowableStress('A106', 'A', 200.0);
        $expectedRatio = min(1.0, $padStress / $headerStress);

        $this->assertSame('HEADER_LINE_ISSUED_PMS_BRANCH_RULE_VALUE', $padMaterial['source']);
        $this->assertFalse($padMaterial['defaulted_from_header']);
        $this->assertSame('A106', $padMaterial['identity']['spec']);
        $this->assertSame('A', $padMaterial['identity']['grade']);
        $this->assertSame(200.0, (float) $padMaterial['design_temperature_c']);
        $this->assertEqualsWithDelta($padStress, (float) $padMaterial['allowable_stress_mpa_at_design_temperature'], 1e-9);
        $this->assertEqualsWithDelta($headerStress, (float) $materials['header_pipe']['allowable_stress_mpa_at_design_temperature'], 1e-9);
        $this->assertSame('A106', $materials['header_pipe']['identity']['spec']);
        $this->assertSame('B', $materials['header_pipe']['identity']['grade']);
        $this->assertEqualsWithDelta($expectedRatio, (float) $padMaterial['allowable_stress_ratio_to_run'], 1e-9);
        $this->assertEqualsWithDelta($expectedRatio, (float) $a4['pad_allowable_stress_ratio_to_run'], 1e-9);
        $this->assertEqualsWithDelta(
            (float) $a4['pad_metal_area_mm2_before_stress_ratio'] * $expectedRatio,
            (float) $a4['pad_metal_area_mm2_credited'],
            0.001,
        );
        $this->assertSame($header->id, $pad->header_line_id);
        $this->assertSame($header->pms_class_snapshot_id, $pad->pms_class_snapshot_id);
    }

    public function test_stub_on_pad_uses_the_matching_header_issued_rule(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(6.25, P2pKind::STUB_ON),
            $this->noPadRule(P2pKind::STUB_ON),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe, 'BRANCH', JointBranchKind::STUB_ON);

        $pad = $this->asOwner(fn () => app(BranchPadService::class)->install($root, $this->owner()));
        $fillets = $this->asOwner(fn () => Joint::query()->where('branch_pad_id', $pad->id)->get());

        $this->assertSame(Joint::TYPE_BRANCH_STUB_ON, $root->joint_type);
        $this->assertSame($branch->id, $root->piping_line_id);
        $this->assertSame($header->id, $pad->header_line_id);
        $this->assertSame(6.25, (float) $pad->pad_thickness_mm);
        $this->assertCount(2, $fillets);
        $this->assertTrue($fillets->every(fn (Joint $joint): bool => $joint->piping_line_id === $header->id
            && $joint->branch_class_type === BranchConnectionType::P2P
            && $joint->branch_class_p2p_kind === P2pKind::STUB_ON));
    }

    public function test_install_refuses_a_pad_below_the_figure_minimum_ratio_even_for_a_legacy_issued_class(): void
    {
        // The test helper marks this class published without the current
        // publish gate, modelling a legacy snapshot. Installation still
        // independently enforces Figure 304.3.3-1 note (2).
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(100.0),
            $this->noPadRule(),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe);

        $service = app(BranchPadService::class);
        $this->assertFalse($this->asOwner(fn () => $service->isEligibleForPad($root)));

        try {
            $this->asOwner(fn () => $service->install($root, $this->owner()));
            $this->fail('Installation must enforce the physical width-to-thickness ratio even for an already-issued legacy class.');
        } catch (ValidationException $exception) {
            $messages = implode(' ', $exception->errors()['branch_root_joint_id'] ?? []);
            $this->assertStringContainsString('width-to-height ratio is 0.536:1', $messages);
            $this->assertStringContainsString('304.3.3-1 note (2)', $messages);
        }

        $this->assertSame(0, $this->asOwner(fn () => BranchPad::query()->count()));
        $this->assertSame(0, $this->asOwner(fn () => Component::query()->where('template_code', 'BRANCH_PAD')->count()));
        $this->assertSame(0, $this->asOwner(fn () => Joint::query()->where('joint_type', Joint::TYPE_FILLET_WELD)->count()));
    }

    public function test_offset_branch_pad_is_refused_without_an_orientation_reference(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(),
            $this->noPadRule(),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe, offsetMm: 4.0);

        $service = app(BranchPadService::class);
        $this->assertFalse($this->asOwner(fn () => $service->isEligibleForPad($root)));

        try {
            $this->asOwner(fn () => $service->install($root, $this->owner()));
            $this->fail('A non-centred branch needs a defined circumferential reference before saddle dimensions can be calculated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_offset_mm', $exception->errors());
        }

        $this->assertSame(0, $this->asOwner(fn () => BranchPad::query()->count()));
    }

    public function test_oblique_beta_is_recorded_but_pad_v1_refuses_unapproved_saddle_geometry(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(),
            $this->noPadRule(),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe, betaDeg: 45.0);

        $this->assertSame(45.0, $root->branch_longitudinal_angle_deg);

        $service = app(BranchPadService::class);
        $this->assertFalse($this->asOwner(fn () => $service->isEligibleForPad($root)));

        try {
            $this->asOwner(fn () => $service->install($root, $this->owner()));
            $this->fail('PAD v1 must not infer an angled saddle envelope from an unapproved formula.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_longitudinal_angle_deg', $exception->errors());
        }

        $this->assertSame(0, $this->asOwner(fn () => BranchPad::query()->count()));
    }

    public function test_installed_pad_locks_the_root_beta_against_snapshot_drift(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(),
            $this->noPadRule(),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe);
        $this->asOwner(fn () => app(BranchPadService::class)->install($root, $this->owner()));

        $this->expectException(QueryException::class);
        $this->asOwner(fn () => DB::table('joints')->where('id', $root->id)
            ->update(['branch_longitudinal_angle_deg' => 45.0]));
    }

    public function test_olet_pad_eligibility_is_not_inferred(): void
    {
        $oletRule = [[
            'header' => [100, 300],
            'branch' => [15, 100],
            'type' => BranchConnectionType::WELDOLET,
            'reinforcement' => 'NONE',
        ]];
        ['branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines($oletRule, $oletRule);
        $owner = $this->owner();
        $olet = $this->asOwner(fn () => app(ComponentService::class)->place($branch, $owner, 'OLET', [
            'nominal_size' => '6',
            'port_sizes' => [2 => '2'],
        ]));
        $root = $this->asOwner(fn () => app(JointService::class)->joinOletToWall(
            $olet,
            $headerPipe,
            3000.0,
            0.0,
            $owner,
        ));

        try {
            $this->asOwner(fn () => app(BranchPadService::class)->install($root, $owner));
            $this->fail('OLET pad geometry and PMS behavior are not inferred by the direct-P2P pad workflow.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_root_joint_id', $exception->errors());
        }

        $this->assertSame(0, $this->asOwner(fn () => BranchPad::query()->count()));
        $this->assertSame(0, $this->asOwner(fn () => Joint::query()->where('joint_type', Joint::TYPE_FILLET_WELD)->count()));

        $this->actingAs($owner)
            ->get('/projects/PH-14/piping/'.$branch->id.'/model?lang=en')
            ->assertOk()
            ->assertDontSee('P2P reinforcement pad')
            ->assertDontSee('Install pad and two fillets');
    }

    public function test_pad_requires_the_header_lines_issued_pms_rule_to_require_pad_reinforcement(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->noPadRule(),
            $this->padRule(4.0),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe);

        try {
            $this->asOwner(fn () => app(BranchPadService::class)->install($root, $this->owner()));
            $this->fail('The branch class must not authorize a pad against the header class.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_root_joint_id', $exception->errors());
        }

        $this->assertSame(0, $this->asOwner(fn () => Component::query()->where('template_code', 'BRANCH_PAD')->count()));
        $this->assertSame(0, $this->asOwner(fn () => Joint::query()->where('joint_type', Joint::TYPE_FILLET_WELD)->count()));

        $this->actingAs($this->owner())
            ->get('/projects/PH-14/piping/'.$branch->id.'/model?lang=en')
            ->assertOk()
            ->assertDontSee('Install pad and two fillets');
    }

    public function test_branch_owned_p2p_root_stays_on_branch_but_pad_welds_use_header_class_and_register(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(10.25),
            $this->noPadRule(),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe, 'BRANCH');

        $pad = $this->asOwner(fn () => app(BranchPadService::class)->install($root, $this->owner()));
        $fillets = $this->asOwner(fn () => Joint::query()->where('branch_pad_id', $pad->id)->get());

        $this->assertSame($branch->id, $root->piping_line_id);
        $this->assertSame($header->id, $pad->header_line_id);
        $this->assertSame(10.25, (float) $pad->pad_thickness_mm);
        $this->assertCount(2, $fillets);
        $this->assertTrue($fillets->every(fn (Joint $joint): bool => $joint->piping_line_id === $header->id));
        $this->assertTrue($fillets->every(fn (Joint $joint): bool => $joint->branch_class_type === BranchConnectionType::P2P));
        $this->assertTrue($fillets->every(fn (Joint $joint): bool => (float) $joint->branch_pad_thickness_mm === 10.25));
        $this->assertNull($root->branch_pad_thickness_mm, 'the branch owner’s root verdict is not overwritten with the header pad snapshot');
    }

    public function test_a_pad_cannot_be_installed_twice_on_the_same_root(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(),
            $this->noPadRule(),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe);
        $this->asOwner(fn () => app(BranchPadService::class)->install($root, $this->owner()));

        try {
            $this->asOwner(fn () => app(BranchPadService::class)->install($root, $this->owner()));
            $this->fail('One direct P2P wall root can have at most one physical pad.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_root_joint_id', $exception->errors());
        }
    }

    public function test_a_wall_root_and_individual_pad_fillets_cannot_be_separated_independently(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(),
            $this->noPadRule(),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe);
        $pad = $this->asOwner(fn () => app(BranchPadService::class)->install($root, $this->owner()));
        $headerJoint = $this->asOwner(fn () => Joint::query()->where('branch_pad_id', $pad->id)->where('branch_pad_side', 'HEADER')->firstOrFail());

        foreach ([[$root, 'branch_root_joint_id'], [$headerJoint, 'branch_pad_id']] as [$joint, $field]) {
            try {
                $this->asOwner(fn () => app(JointService::class)->separate($joint, $this->owner()));
                $this->fail('The pad pair and its P2P wall root cannot be detached independently.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($field, $exception->errors());
            }
        }

        $this->assertSame(2, $this->asOwner(fn () => Joint::query()->where('branch_pad_id', $pad->id)->count()));
        $this->assertSame(1, $this->asOwner(fn () => Joint::query()->whereKey($root->id)->count()));
    }

    public function test_removing_the_physical_pad_removes_both_joints_together_and_keeps_the_branch_root(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(),
            $this->noPadRule(),
        );
        ['root' => $root, 'branchPipe' => $branchPipe] = $this->directP2pRoot($branch, $headerPipe);
        $pad = $this->asOwner(fn () => app(BranchPadService::class)->install($root, $this->owner()));
        $padComponentId = $pad->component_id;
        $rootPortId = $root->port_a_id;

        $this->asOwner(fn () => app(ComponentService::class)->remove(
            Component::query()->findOrFail($padComponentId), $this->owner(),
        ));

        $this->assertNull($this->asOwner(fn () => BranchPad::query()->find($pad->id)));
        $this->assertNull($this->asOwner(fn () => Component::query()->find($padComponentId)));
        $this->assertSame(0, $this->asOwner(fn () => Joint::query()->where('branch_pad_id', $pad->id)->count()));
        $this->assertSame(1, $this->asOwner(fn () => Joint::query()->whereKey($root->id)->count()));
        $this->assertTrue($this->asOwner(fn () => Port::query()->findOrFail($rootPortId)->is_connected));
        $this->assertSame($branch->id, $this->asOwner(fn () => Component::query()->findOrFail($branchPipe->id)->piping_line_id,
        ));
    }

    public function test_fitted_pad_fillet_prevents_removal_of_the_complete_pad(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(),
            $this->noPadRule(),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe);
        $pad = $this->asOwner(fn () => app(BranchPadService::class)->install($root, $this->owner()));
        $headerJoint = $this->asOwner(fn () => Joint::query()->where('branch_pad_id', $pad->id)->where('branch_pad_side', 'HEADER')->firstOrFail());
        $this->asOwner(fn () => app(JointService::class)->markFitted($headerJoint, $this->owner(), 'PAD-FITTER'));

        try {
            $this->asOwner(fn () => app(ComponentService::class)->remove($pad->component, $this->owner()));
            $this->fail('A fitted pad fillet must remain auditable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_pad_id', $exception->errors());
        }

        $this->assertSame(2, $this->asOwner(fn () => Joint::query()->where('branch_pad_id', $pad->id)->count()));
    }

    public function test_branch_pad_is_not_a_standalone_component_or_attachable_template(): void
    {
        ['branch' => $branch] = $this->twoLines($this->padRule(), $this->noPadRule());

        try {
            $this->asOwner(fn () => app(ComponentService::class)->place($branch, $this->owner(), 'BRANCH_PAD'));
            $this->fail('A pad may not exist without its root, PMS snapshot and two joints.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('template_code', $exception->errors());
        }

        $templates = $this->asOwner(fn () => app(ComponentService::class)->placeableTemplates());
        $this->assertNotContains('BRANCH_PAD', array_column($templates, 'code'));
    }

    public function test_model_page_exposes_the_atomic_p2p_pad_workflow(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(),
            $this->noPadRule(),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe);

        $this->actingAs($this->owner())
            ->get('/projects/PH-14/piping/'.$branch->id.'/model?lang=en')
            ->assertOk()
            ->assertSee('P2P reinforcement pad')
            ->assertSee('Install pad and two fillets')
            ->assertSee($root->displayNumber());
    }

    public function test_http_action_installs_both_header_owned_pad_fillet_joints(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines(
            $this->padRule(9.0),
            $this->noPadRule(),
        );
        ['root' => $root] = $this->directP2pRoot($branch, $headerPipe);

        $this->actingAs($this->owner())
            ->post('/projects/PH-14/piping/'.$branch->id.'/model/pads?lang=en', [
                'branch_root_joint_id' => $root->id,
            ])
            ->assertRedirect();

        $state = $this->asOwner(function () use ($header): array {
            $pad = BranchPad::query()->firstOrFail();
            $joints = Joint::query()->where('branch_pad_id', $pad->id)->get();

            return [
                'owner' => $pad->header_line_id,
                'thickness' => (float) $pad->pad_thickness_mm,
                'joint_count' => $joints->count(),
                'all_header_owned' => $joints->every(fn (Joint $joint): bool => $joint->piping_line_id === $header->id),
            ];
        });

        $this->assertSame($header->id, $state['owner']);
        $this->assertSame(9.0, $state['thickness']);
        $this->assertSame(2, $state['joint_count']);
        $this->assertTrue($state['all_header_owned']);
    }

    public function test_the_database_rejects_an_unassociated_fillet_weld(): void
    {
        ['branch' => $branch] = $this->twoLines($this->padRule(), $this->noPadRule());
        [$first, $second] = $this->asOwner(function () use ($branch): array {
            $components = app(ComponentService::class);

            return [
                $components->place($branch, $this->owner(), 'PIPE')->ports->first(),
                $components->place($branch, $this->owner(), 'PIPE')->ports->first(),
            ];
        });
        [$portA, $portB] = strcmp($first->id, $second->id) < 0
            ? [$first->id, $second->id]
            : [$second->id, $first->id];
        $project = $this->project();
        $owner = $this->owner();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/joints_fillet_weld_requires_branch_pad/');

        $this->asOwner(function () use ($branch, $project, $owner, $portA, $portB): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);
            DB::table('joints')->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'company_id' => $owner->company_id,
                'piping_line_id' => $branch->id,
                'port_a_id' => $portA,
                'port_b_id' => $portB,
                'joint_seq' => 1,
                'numbering_policy' => Joint::NUMBERING_REQUIRED,
                'joint_type' => Joint::TYPE_FILLET_WELD,
                'state' => Joint::STATE_MODELLED,
                'compatibility_state' => 'VALID',
                'source' => 'MANUAL',
                'created_by' => $owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
