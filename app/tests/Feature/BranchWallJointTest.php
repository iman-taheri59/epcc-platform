<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Component;
use App\Models\Joint;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ComponentService;
use App\Services\JointService;
use App\Services\PipingClassService;
use App\Services\PipingLineService;
use App\Support\Piping\BranchConnectionType;
use App\Support\Piping\JointBranchKind;
use App\Support\Piping\JointBranchRuleState;
use App\Support\Piping\P2pKind;
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
 * A joint on a header wall — ADR-006 §14, P-22 delivered.
 *
 * The fitting-less branch: a stub-in or stub-on, P2P in the branch table, a tap
 * connection in PCF, a tap weld in Plant 3D. It is stored as one branch-side
 * port plus a frozen header reference; no fake port is added to the header.
 *
 * The reason it is modelled as a frozen header reference rather than a
 * synthesised wall port is stated in JointBoundaryTest; this file proves the
 * reference works, and — more importantly — that the four invariants on `joints`
 * which all silently stop meaning anything when port_b_id becomes NULL have
 * actually been restated and not merely renamed.
 */
final class BranchWallJointTest extends TestCase
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
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    private function owner(): User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    private function asOwner(callable $fn): mixed
    {
        $project = $this->project();

        return $this->tenant->runAs($project->owner_company_id, $fn, $project->id);
    }

    /**
     * Two lines: a 6" header and a 2" branch, each with an issued PMS class.
     * The branch joint is cross-line by nature, which is the whole point.
     *
     * @param  list<array<string, mixed>>  $cells
     * @return array{header: PipingLine, branch: PipingLine, headerPipe: Component}
     */
    private function twoLines(array $cells): array
    {
        return $this->asOwner(function () use ($cells): array {
            $project = $this->project();
            $owner = $this->owner();
            $lines = app(PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);
            $subsystem = Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail();

            $class = PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'WALL-'.substr(md5((string) json_encode($cells)), 0, 8)],
                ['company_id' => $project->owner_company_id, 'description_en' => 'Wall branch class'],
            );

            $authoring = app(PipingClassService::class);

            foreach ($cells as $cell) {
                $authoring->addBranchRule(
                    $project, $owner, $class,
                    $cell['header'][0], $cell['header'][1],
                    $cell['branch'][0], $cell['branch'][1],
                    $cell['type'], 'fa',
                    $cell['p2p_kind'] ?? null,
                    $cell['reinforcement'] ?? 'NONE',
                    $cell['pad_thickness'] ?? null,
                );
            }

            ApprovedPmsClassFixture::ensure($project, $owner, $class);

            $header = $lines->create($subsystem, $owner, [
                'nominal_size' => '6', 'service_code' => 'P', 'piping_class_id' => $class->id,
            ]);
            $branch = $lines->create($subsystem, $owner, [
                'nominal_size' => '2', 'service_code' => 'P', 'piping_class_id' => $class->id,
            ]);

            // A header needs a length for a station to mean anything.
            $headerPipe = app(ComponentService::class)->place($header, $owner, 'PIPE', [
                'length_mm' => 6000,
            ]);

            return ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe];
        });
    }

    /** @return array{joint: Joint, branchPipe: Component} */
    private function weldOnWall(
        PipingLine $branch,
        Component $headerPipe,
        float $station = 3000.0,
        array $attributes = [],
    ): array {
        return $this->asOwner(function () use ($branch, $headerPipe, $station, $attributes): array {
            $branchPipe = app(ComponentService::class)->place($branch, $this->owner(), 'PIPE');

            $joint = app(JointService::class)->joinToWall(
                $branchPipe->ports->first(),
                $headerPipe,
                JointBranchKind::STUB_IN,
                $station,
                0.0,
                $this->owner(),
                $attributes,
            );

            return ['joint' => $joint, 'branchPipe' => $branchPipe];
        });
    }

    // ------------------------------------------------------------- the weld --

    public function test_a_branch_can_be_welded_to_a_header_wall(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        ['joint' => $joint] = $this->weldOnWall($branch, $headerPipe);

        $this->assertTrue($joint->isOnWall());
        $this->assertNull($joint->port_b_id, 'there is no port on a pipe wall');
        $this->assertSame($headerPipe->id, $joint->branch_header_component_id);
        $this->assertSame(3000.0, $joint->branch_station_mm);
        $this->assertSame(0.0, $joint->branch_offset_mm);

        // No owner override was supplied, so it uses the BRANCH default.
        $this->assertTrue($joint->isWeld());
        $this->assertSame(Joint::TYPE_BRANCH_STUB_IN, $joint->joint_type);
        $this->assertNotNull($joint->joint_seq);
        $this->assertSame($branch->id, $joint->piping_line_id);
        $this->assertNotSame($header->id, $joint->piping_line_id);
    }

    public function test_a_p2p_wall_root_can_be_numbered_on_the_header_line(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        $headerEndJoint = $this->asOwner(fn () => app(ComponentService::class)->attach(
            $headerPipe->ports->first(), $this->owner(), 'PIPE',
        )['joint']);
        ['joint' => $root, 'branchPipe' => $branchPipe] = $this->weldOnWall($branch, $headerPipe, 3000.0, [
            'line_owner' => 'HEADER',
        ]);

        $this->assertSame($header->id, $root->piping_line_id);
        $this->assertSame($headerEndJoint->joint_seq + 1, $root->joint_seq);
        $this->assertSame(BranchConnectionType::P2P, $root->branch_class_type);
        $this->assertSame(P2pKind::STUB_IN, $root->branch_class_p2p_kind);
        $this->assertSame('AGREES', $root->branchDeviation());
        $this->assertSame($branch->id, $branchPipe->piping_line_id);
        $this->assertSame(2, $this->asOwner(fn () => app(JointService::class)->lineMetrics($header->fresh())['welds']));
        $this->assertSame(0, $this->asOwner(fn () => app(JointService::class)->lineMetrics($branch->fresh())['welds']));
    }

    public function test_a_branch_segment_joint_can_be_manually_owned_by_its_roots_header_line(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        $headerEndJoint = $this->asOwner(fn () => app(ComponentService::class)->attach(
            $headerPipe->ports->first(), $this->owner(), 'PIPE',
        )['joint']);
        ['joint' => $root, 'branchPipe' => $branchPipe] = $this->weldOnWall($branch, $headerPipe);
        $openPort = $branchPipe->ports->first(fn (Port $port): bool => $port->id !== $root->port_a_id);
        $downstreamJoint = $this->asOwner(fn () => app(ComponentService::class)->attach(
            $openPort,
            $this->owner(),
            'PIPE',
            [
                'line_owner' => 'HEADER',
                'branch_root_joint_id' => $root->id,
            ],
        )['joint']);

        $this->assertSame($header->id, $downstreamJoint->piping_line_id);
        $this->assertSame($root->id, $downstreamJoint->branch_root_joint_id);
        $this->assertSame($headerEndJoint->joint_seq + 1, $downstreamJoint->joint_seq);
        $this->assertSame($branch->id, $branchPipe->piping_line_id);
        $this->assertSame(2, $this->asOwner(fn () => app(JointService::class)->lineMetrics($header->fresh())['welds']));
        $this->assertSame(1, $this->asOwner(fn () => app(JointService::class)->lineMetrics($branch->fresh())['welds']));
    }

    public function test_a_branch_root_does_not_authorize_a_cross_line_two_port_joint_in_the_database(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);
        ['joint' => $root, 'branchPipe' => $branchPipe] = $this->weldOnWall($branch, $headerPipe);
        $branchPort = $branchPipe->ports->first(fn (Port $port): bool => $port->id !== $root->port_a_id);
        $headerPort = $headerPipe->ports->first();
        $a = $branchPort->id;
        $b = $headerPort->id;
        if (strcmp($a, $b) > 0) {
            [$a, $b] = [$b, $a];
        }
        $project = $this->project();
        $owner = $this->owner();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/JT_LINE_MISMATCH/');

        $this->asOwner(function () use ($project, $owner, $header, $a, $b, $root): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);
            DB::table('joints')->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'company_id' => $owner->company_id,
                'piping_line_id' => $header->id,
                'port_a_id' => $a,
                'port_b_id' => $b,
                'branch_root_joint_id' => $root->id,
                'numbering_policy' => Joint::NUMBERING_NOT_APPLICABLE,
                'joint_type' => 'FLANGED',
                'state' => Joint::STATE_MODELLED,
                'compatibility_state' => 'VALID',
                'source' => 'MANUAL',
                'created_by' => $owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_the_database_rejects_a_direct_header_owner_on_an_ordinary_two_port_joint(): void
    {
        ['header' => $header, 'branch' => $branch] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);
        $ports = $this->asOwner(function () use ($branch): array {
            $components = app(ComponentService::class);
            $first = $components->place($branch, $this->owner(), 'PIPE')->ports->first();
            $second = $components->place($branch, $this->owner(), 'PIPE')->ports->first();

            return [$first->id, $second->id];
        });
        [$a, $b] = $ports;
        if (strcmp($a, $b) > 0) {
            [$a, $b] = [$b, $a];
        }
        $project = $this->project();
        $owner = $this->owner();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/JT_BRANCH_OWNER_INVALID/');

        $this->asOwner(function () use ($project, $owner, $header, $a, $b): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);
            DB::table('joints')->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $project->id,
                'company_id' => $owner->company_id,
                'piping_line_id' => $header->id,
                'port_a_id' => $a,
                'port_b_id' => $b,
                'branch_header_line_id' => $header->id,
                'numbering_policy' => Joint::NUMBERING_NOT_APPLICABLE,
                'joint_type' => 'BUTT_WELD',
                'state' => Joint::STATE_MODELLED,
                'compatibility_state' => 'VALID',
                'source' => 'MANUAL',
                'created_by' => $owner->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_separating_a_wall_root_rehomes_header_owned_joints_to_the_branch_line(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        ['joint' => $root, 'branchPipe' => $branchPipe] = $this->weldOnWall($branch, $headerPipe, 3000.0, [
            'line_owner' => 'HEADER',
        ]);
        $openPort = $branchPipe->ports->first(fn (Port $port): bool => $port->id !== $root->port_a_id);
        $dependent = $this->asOwner(fn () => app(ComponentService::class)->attach(
            $openPort,
            $this->owner(),
            'PIPE',
            [
                'line_owner' => 'HEADER',
                'branch_root_joint_id' => $root->id,
            ],
        )['joint']);

        $this->asOwner(fn () => app(JointService::class)->separate($root, $this->owner()));
        [$remainingRoot, $reassigned] = $this->asOwner(fn () => [
            Joint::query()->find($root->id),
            Joint::query()->findOrFail($dependent->id),
        ]);

        $this->assertNull($remainingRoot);
        $this->assertSame($branch->id, $reassigned->piping_line_id);
        $this->assertSame(1, $reassigned->joint_seq);
        $this->assertNull($reassigned->branch_root_joint_id);
        $this->assertSame(1, $this->asOwner(fn () => app(JointService::class)->lineMetrics($branch->fresh())['welds']));
        $this->assertSame(0, $this->asOwner(fn () => app(JointService::class)->lineMetrics($header->fresh())['welds']));
    }

    public function test_a_fitted_header_owned_branch_joint_prevents_separating_its_root(): void
    {
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        ['joint' => $root, 'branchPipe' => $branchPipe] = $this->weldOnWall($branch, $headerPipe, 3000.0, [
            'line_owner' => 'HEADER',
        ]);
        $openPort = $branchPipe->ports->first(fn (Port $port): bool => $port->id !== $root->port_a_id);
        $dependent = $this->asOwner(fn () => app(ComponentService::class)->attach(
            $openPort,
            $this->owner(),
            'PIPE',
            [
                'line_owner' => 'HEADER',
                'branch_root_joint_id' => $root->id,
            ],
        )['joint']);
        $this->asOwner(fn () => app(JointService::class)->markFitted($dependent, $this->owner(), 'FIT-ROOT'));

        try {
            $this->asOwner(fn () => app(JointService::class)->separate($root, $this->owner()));
            $this->fail('A fitted dependent joint must keep its explicit header-root ownership.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('branch_root_joint_id', $exception->errors());
        }

        [$remainingRoot, $remainingDependent] = $this->asOwner(fn () => [
            Joint::query()->findOrFail($root->id),
            Joint::query()->findOrFail($dependent->id),
        ]);

        $this->assertSame($root->id, $remainingDependent->branch_root_joint_id);
        $this->assertSame($header->id, $remainingDependent->piping_line_id);
        $this->assertTrue($remainingDependent->isFitted());
        $this->assertSame($header->id, $remainingRoot->piping_line_id);
    }

    public function test_a_p2p_wall_root_requires_a_branch_pipe_end(): void
    {
        ['branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($branch, $headerPipe): void {
            $tee = app(ComponentService::class)->place($branch, $this->owner(), 'TEE');

            app(JointService::class)->joinToWall(
                $tee->ports->firstWhere('role', 'BRANCH'),
                $headerPipe,
                JointBranchKind::STUB_IN,
                3000.0,
                0.0,
                $this->owner(),
            );
        });
    }

    public function test_the_p2p_wall_method_still_refuses_a_fitting_kind(): void
    {
        ['branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($branch, $headerPipe): void {
            $pipe = app(ComponentService::class)->place($branch, $this->owner(), 'PIPE');

            app(JointService::class)->joinToWall(
                $pipe->ports->first(), $headerPipe, JointBranchKind::OLET, 3000.0, 0.0, $this->owner(),
            );
        });
    }

    public function test_header_line_ownership_for_a_two_port_joint_requires_a_wall_root(): void
    {
        ['branch' => $branch] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($branch): void {
            $components = app(ComponentService::class);
            $first = $components->place($branch, $this->owner(), 'PIPE');
            $second = $components->place($branch, $this->owner(), 'PIPE');

            app(JointService::class)->join(
                $first->ports->first(),
                $second->ports->first(),
                $this->owner(),
                ['line_owner' => 'HEADER'],
            );
        });
    }

    public function test_an_olet_run_port_cannot_be_faked_into_a_p2p_stub(): void
    {
        ['branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::WELDOLET,
        ]]);

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($branch, $headerPipe): void {
            $olet = app(ComponentService::class)->place($branch, $this->owner(), 'OLET', [
                'nominal_size' => '6',
                'port_sizes' => [2 => '2'],
            ]);

            app(JointService::class)->joinToWall(
                $olet->ports->firstWhere('role', 'RUN'),
                $headerPipe,
                JointBranchKind::STUB_IN,
                3000.0,
                0.0,
                $this->owner(),
            );
        });
    }

    public function test_the_header_keeps_its_two_ends(): void
    {
        // THE reason this is a frozen reference and not a synthesised wall port.
        // openPorts() drives the worklist, the progress metric and the import
        // validator; a fake port would make a header with three branches look
        // like a pipe with five ends.
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        $this->weldOnWall($branch, $headerPipe, 1000.0);
        $this->weldOnWall($branch, $headerPipe, 2000.0);
        $this->weldOnWall($branch, $headerPipe, 3000.0);

        $open = $this->asOwner(fn () => app(ComponentService::class)->openPorts($header->fresh()));

        $this->assertCount(2, $open, 'three branches on the wall must not invent three ends');
    }

    public function test_the_class_verdict_and_pad_thickness_travel_to_the_wall_weld(): void
    {
        ['branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
            'reinforcement' => 'PAD', 'pad_thickness' => 6.0,
        ]]);

        ['joint' => $joint] = $this->weldOnWall($branch, $headerPipe);

        $this->assertSame(JointBranchRuleState::RULE, $joint->branch_rule_state);
        $this->assertSame(BranchConnectionType::P2P, $joint->branch_class_type);
        $this->assertSame(P2pKind::STUB_IN, $joint->branch_class_p2p_kind);
        $this->assertSame(6.0, $joint->branch_pad_thickness_mm);

        // The model and the class agree, for the first time on a wall weld.
        $this->assertSame('AGREES', $joint->branchDeviation());
    }

    public function test_a_wall_weld_is_charged_dia_inch_on_the_branch_size(): void
    {
        // Dia-inch is money. A joint with one port used to return null here and
        // vanish from the roll-up, which is exactly how the most expensive
        // welds on the line go unpaid.
        ['branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        ['joint' => $joint] = $this->weldOnWall($branch, $headerPipe);

        $this->assertSame(2.0, app(JointService::class)->diaInch($joint));

        $metrics = $this->asOwner(fn () => app(JointService::class)->lineMetrics($branch->fresh()));

        $this->assertSame(1, $metrics['welds']);
        $this->assertSame(2.0, $metrics['dia_inch']);
        $this->assertSame(1, $metrics['branch_welds']);
    }

    // ------------------------------------------- the invariants, restated --

    public function test_a_branch_port_cannot_be_welded_twice(): void
    {
        // joints_port_b_unique was left alone on purpose; the branch port lives
        // in port_a_id, whose unique index was never partial. This test is the
        // only thing standing between that reasoning and a future edit.
        ['branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($branch, $headerPipe): void {
            $pipe = app(ComponentService::class)->place($branch, $this->owner(), 'PIPE');
            $port = $pipe->ports->first();

            app(JointService::class)->joinToWall(
                $port, $headerPipe, JointBranchKind::STUB_IN, 1000.0, 0.0, $this->owner(),
            );
            app(JointService::class)->joinToWall(
                $port, $headerPipe, JointBranchKind::STUB_IN, 2000.0, 0.0, $this->owner(),
            );
        });
    }

    public function test_a_joint_with_neither_a_second_port_nor_a_header_is_unrepresentable(): void
    {
        // joints_other_side. Without it, "welded to nothing" is a legal row.
        ['branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        $this->expectException(QueryException::class);

        $this->asOwner(function () use ($branch): void {
            $pipe = app(ComponentService::class)->place($branch, $this->owner(), 'PIPE');
            $port = $pipe->ports->first();

            DB::table('joints')->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $branch->project_id,
                'company_id' => $this->owner()->company_id,
                'piping_line_id' => $branch->id,
                'port_a_id' => $port->id,
                'port_b_id' => null,
                'branch_header_component_id' => null,
                'joint_type' => 'BUTT_WELD',
                'numbering_policy' => 'NOT_APPLICABLE',
                'state' => 'MODELLED',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function test_a_branch_on_another_line_is_allowed_where_a_tie_in_is_not(): void
    {
        // The guard was rewritten for this. A branch is cross-line BY NATURE —
        // one line welds onto another — and that is not the same thing as a
        // tie-in, where two line ends meet and neither side owns the number.
        // This exercises the BRANCH default; header ownership is covered above.
        ['header' => $header, 'branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        ['joint' => $joint] = $this->weldOnWall($branch, $headerPipe);

        $this->assertNotSame($header->id, $joint->piping_line_id);
        $this->assertSame($branch->id, $joint->piping_line_id);

        // ...and the header really is on a different line.
        $this->assertSame($header->id, $headerPipe->piping_line_id);
    }

    // ------------------------------------------------------------ geometry --

    public function test_a_station_outside_the_header_is_refused(): void
    {
        ['branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        $this->expectException(ValidationException::class);

        // The header is 6000 mm long.
        $this->weldOnWall($branch, $headerPipe, 9000.0);
    }

    public function test_an_offset_that_misses_the_pipe_is_refused(): void
    {
        ['branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($branch, $headerPipe): void {
            $pipe = app(ComponentService::class)->place($branch, $this->owner(), 'PIPE');

            // The header is 6", so its outer radius is about 84 mm.
            app(JointService::class)->joinToWall(
                $pipe->ports->first(), $headerPipe, JointBranchKind::STUB_IN, 3000.0, 500.0, $this->owner(),
            );
        });
    }

    public function test_a_header_with_no_length_cannot_hold_a_station(): void
    {
        // A station that cannot be checked is a note, not a design. Refusing
        // sends someone to fill in the length.
        ['branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        $this->expectException(ValidationException::class);

        // Inside the scope: re-querying outside RLS returns null.
        $this->asOwner(function () use ($branch, $headerPipe): void {
            DB::table('components')->where('id', $headerPipe->id)->update(['length_mm' => null]);
            $headerPipe->length_mm = null;

            $pipe = app(ComponentService::class)->place($branch, $this->owner(), 'PIPE');

            app(JointService::class)->joinToWall(
                $pipe->ports->first(), $headerPipe, JointBranchKind::STUB_IN, 3000.0, 0.0, $this->owner(),
            );
        });
    }

    // ------------------------------------------------------- taking it apart --

    public function test_removing_the_header_separates_the_branches_landed_on_it(): void
    {
        // jointsTouching() had to learn to look at the header side. Without it,
        // removing the header would leave the branch flagged connected to
        // something that no longer exists — invisible to the open-ends worklist
        // and therefore never finished.
        ['branch' => $branch, 'headerPipe' => $headerPipe] = $this->twoLines([[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        ['joint' => $joint] = $this->weldOnWall($branch, $headerPipe);

        $this->asOwner(function () use ($headerPipe): void {
            app(ComponentService::class)->remove($headerPipe->fresh(), $this->owner());
        });

        $this->assertNull(Joint::query()->find($joint->id));

        $open = $this->asOwner(fn () => app(ComponentService::class)->openPorts($branch->fresh()));
        $this->assertNotEmpty($open, 'the branch end must come back to the worklist');
    }
}
