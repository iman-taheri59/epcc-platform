<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Component;
use App\Models\Joint;
use App\Models\PipingClass;
use App\Models\PipingLine;
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
use Tests\Support\ApprovedPmsClassFixture;
use Tests\TestCase;

/**
 * The class verdict that travels with a branch weld.
 *
 * ADR-008 §9.2 asks for three things that all need the class to be consulted at
 * the moment the joint is made: branch welds counted separately, the mandatory
 * pad thickness traceable to the weld, and "a joint contradicting its class is
 * a deviation". What the model OBSERVES is covered in JointTest; this file
 * covers what the class DECLARES, and the space between the two.
 */
final class BranchClassVerdictTest extends TestCase
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
     * A line whose issued PMS class carries the given branch cells.
     *
     * The cells have to be written BEFORE the class is published, because
     * publishing is what captures the snapshot - and it is the snapshot the
     * joint reads, never the live rules.
     *
     * @param  list<array<string, mixed>>  $cells
     */
    private function lineWithBranchCells(string $size, array $cells): PipingLine
    {
        return $this->asOwner(function () use ($size, $cells): PipingLine {
            $project = $this->project();
            $owner = $this->owner();

            $lines = app(PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            $class = PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'BR'.$size.'-'.substr(md5((string) json_encode($cells)), 0, 8)],
                ['company_id' => $project->owner_company_id, 'description_en' => 'Branch test class'],
            );

            $authoring = app(PipingClassService::class);

            foreach ($cells as $cell) {
                $authoring->addBranchRule(
                    $project,
                    $owner,
                    $class,
                    $cell['header'][0],
                    $cell['header'][1],
                    $cell['branch'][0],
                    $cell['branch'][1],
                    $cell['type'],
                    'fa',
                    $cell['p2p_kind'] ?? null,
                    $cell['reinforcement'] ?? 'NONE',
                    $cell['pad_thickness'] ?? null,
                );
            }

            ApprovedPmsClassFixture::ensure($project, $owner, $class);

            return $lines->create(
                Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $owner,
                ['nominal_size' => $size, 'service_code' => 'P', 'piping_class_id' => $class->id],
            );
        });
    }

    /**
     * Place a tee and weld a branch pipe to its branch port.
     *
     * @return array{joint: Joint, line: PipingLine}
     */
    private function teeBranchJoint(PipingLine $line, string $branchSize = '2'): array
    {
        return $this->asOwner(function () use ($line, $branchSize): array {
            $tee = app(ComponentService::class)->place($line, $this->owner(), 'TEE', [
                'port_sizes' => [3 => $branchSize],
            ]);

            $attached = app(ComponentService::class)->attach(
                $tee->ports->firstWhere('role', 'BRANCH'),
                $this->owner(),
                'PIPE',
            );

            return ['joint' => $attached['joint'], 'line' => $line];
        });
    }

    /**
     * Place a 6x2 OLET on a separate 2-inch branch line and connect both
     * interfaces. The root weld carries the branch classification; the outlet
     * butt weld does not count it a second time.
     *
     * @return array{branchLine: PipingLine, olet: Component, headerJoint: Joint, outletJoint: Joint}
     */
    private function oletBranchAssembly(
        PipingLine $headerLine,
        string $branchSize = '2',
        bool $legacyOutletClassification = false,
    ): array {
        return $this->asOwner(function () use ($headerLine, $branchSize, $legacyOutletClassification): array {
            $branchLine = app(PipingLineService::class)->create(
                Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $this->owner(),
                [
                    'nominal_size' => $branchSize,
                    'service_code' => 'P',
                    'piping_class_id' => $headerLine->piping_class_id,
                ],
            );

            $components = app(ComponentService::class);
            $header = $components->place($headerLine, $this->owner(), 'PIPE', ['length_mm' => 6000]);
            $olet = $components->place($branchLine, $this->owner(), 'OLET', [
                'nominal_size' => $headerLine->nominal_size,
                'port_sizes' => [2 => $branchSize],
            ]);
            $outletJoint = $components->attach(
                $olet->ports->firstWhere('role', 'BRANCH'), $this->owner(), 'PIPE',
            )['joint'];

            if ($legacyOutletClassification) {
                // Pre-OLET-wall data classified this outlet weld as the branch
                // connection. Exercise the atomic transfer to the root weld.
                DB::table('joints')->where('id', $outletJoint->id)->update([
                    'branch_kind' => JointBranchKind::OLET,
                    'branch_class_type' => BranchConnectionType::WELDOLET,
                    'branch_pad_thickness_mm' => 6.0,
                    'branch_rule_state' => JointBranchRuleState::RULE,
                ]);
            }

            $headerJoint = app(JointService::class)->joinOletToWall(
                $olet, $header, 3000.0, 0.0, $this->owner(),
            );
            $outletJoint = $outletJoint->fresh();

            return [
                'branchLine' => $branchLine,
                'olet' => $olet,
                'headerJoint' => $headerJoint,
                'outletJoint' => $outletJoint,
            ];
        });
    }

    // ----------------------------------------------------------- the verdict --

    public function test_a_branch_weld_carries_what_its_class_declared(): void
    {
        $line = $this->lineWithBranchCells('6', [[
            'header' => [100, 300], 'branch' => [15, 100], 'type' => BranchConnectionType::TEE,
        ]]);

        ['joint' => $joint] = $this->teeBranchJoint($line);

        $this->assertSame(JointBranchKind::TEE, $joint->branch_kind, 'what the model saw');
        $this->assertSame(BranchConnectionType::TEE, $joint->branch_class_type, 'what the class declared');
        $this->assertSame(JointBranchRuleState::RULE, $joint->branch_rule_state);
        $this->assertSame('AGREES', $joint->branchDeviation());
    }

    public function test_the_pad_thickness_travels_to_the_weld(): void
    {
        // ADR-008 §9.2: "the mandatory pad thickness of a PAD rule must stay
        // traceable to the weld". Neither Plant 3D nor PCF knows reinforcement
        // pads exist, so this is the one datum here that no 3D system can give
        // a fabricator.
        $line = $this->lineWithBranchCells('6', [[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
            'reinforcement' => 'PAD', 'pad_thickness' => 6.0,
        ]]);

        ['joint' => $joint] = $this->teeBranchJoint($line);

        $this->assertSame(6.0, $joint->branch_pad_thickness_mm);
    }

    public function test_a_p2p_verdict_is_recorded_even_though_no_fitting_exists(): void
    {
        // The class says P2P: no component at this branch. The model cannot
        // represent that today - a stub-in has no port on the header - so a tee
        // gets placed instead. Recording the verdict anyway is the point: it is
        // the evidence that a purchased fitting appears in the MTO that the
        // class never called for.
        $line = $this->lineWithBranchCells('6', [[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::P2P, 'p2p_kind' => P2pKind::STUB_IN,
        ]]);

        ['joint' => $joint] = $this->teeBranchJoint($line);

        $this->assertSame(BranchConnectionType::P2P, $joint->branch_class_type);
        $this->assertSame(P2pKind::STUB_IN, $joint->branch_class_p2p_kind);

        // The model saw a tee; the class asked for none.
        $this->assertSame('FITTING_WHERE_CLASS_SAYS_NONE', $joint->branchDeviation());
    }

    public function test_a_weldolet_cell_is_recorded_on_the_olet_header_weld_not_twice(): void
    {
        $headerLine = $this->lineWithBranchCells('6', [[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::WELDOLET,
            'reinforcement' => 'PAD', 'pad_thickness' => 6.0,
        ]]);

        $assembly = $this->oletBranchAssembly($headerLine);
        $headerJoint = $assembly['headerJoint'];
        $outletJoint = $assembly['outletJoint'];

        $this->assertSame(Joint::TYPE_OLET_HEADER_WELD, $headerJoint->joint_type);
        $this->assertSame(JointBranchKind::OLET, $headerJoint->branch_kind, 'the model observed an OLET at the header');
        $this->assertSame(BranchConnectionType::WELDOLET, $headerJoint->branch_class_type, 'the issued class declared WELDOLET');
        $this->assertSame(JointBranchRuleState::RULE, $headerJoint->branch_rule_state);
        $this->assertSame(6.0, $headerJoint->branch_pad_thickness_mm, 'the pad follows the root branch weld');
        $this->assertSame('AGREES', $headerJoint->branchDeviation());

        $this->assertSame('BUTT_WELD', $outletJoint->joint_type);
        $this->assertNull($outletJoint->branch_kind, 'the OLET outlet butt weld is not a second branch connection');
        $this->assertNull($outletJoint->branch_class_type);
        $this->assertNull($outletJoint->branch_rule_state);
        $this->assertNull($outletJoint->branchDeviation());

        $metrics = $this->asOwner(fn () => app(JointService::class)->lineMetrics($assembly['branchLine']->fresh()));
        $this->assertSame(2, $metrics['welds'], 'both physical welds remain in the total');
        $this->assertSame(4.0, $metrics['dia_inch'], 'both physical welds are measured on the 2-inch branch');
        $this->assertSame(1, $metrics['branch_welds'], 'the branch connection is counted once');
        $this->assertSame(2.0, $metrics['branch_dia_inch']);
        $this->assertSame(1, $metrics['branch_padded']);
        $this->assertSame(['OLET' => ['welds' => 1, 'dia_inch' => 2.0]], $metrics['branch_by_kind']);
    }

    public function test_an_unfitted_legacy_olet_outlet_verdict_moves_to_the_root_weld(): void
    {
        $headerLine = $this->lineWithBranchCells('6', [[
            'header' => [100, 300], 'branch' => [15, 100],
            'type' => BranchConnectionType::WELDOLET,
            'reinforcement' => 'PAD', 'pad_thickness' => 6.0,
        ]]);

        $assembly = $this->oletBranchAssembly($headerLine, '2', true);

        $this->assertSame(JointBranchKind::OLET, $assembly['headerJoint']->branch_kind);
        $this->assertSame(BranchConnectionType::WELDOLET, $assembly['headerJoint']->branch_class_type);
        $this->assertSame(6.0, $assembly['headerJoint']->branch_pad_thickness_mm);
        $this->assertNull($assembly['outletJoint']->branch_kind);
        $this->assertNull($assembly['outletJoint']->branch_class_type);
        $this->assertNull($assembly['outletJoint']->branch_pad_thickness_mm);

        $metrics = $this->asOwner(fn () => app(JointService::class)->lineMetrics($assembly['branchLine']->fresh()));
        $this->assertSame(1, $metrics['branch_welds'], 'legacy data is not double-counted after completion');
        $this->assertSame(1, $metrics['branch_padded']);
    }

    // --------------------------------------------------------- when there is nothing to ask --

    public function test_a_line_with_no_issued_class_says_so_rather_than_guessing(): void
    {
        // NO_SNAPSHOT is an upstream data gap - someone has to classify the
        // line. It is deliberately not the same answer as NO_RULE, which is a
        // branch-table authoring gap and is chased by a different person.
        //
        // This state cannot be produced by using the application: PmsLine
        // Eligibility refuses to create a line whose class has no approved
        // revision, and a database guard refuses to un-pin one that has (see
        // the test below). It exists for rows written OUTSIDE the service -
        // importers, data repair, lines predating the PMS feature - so the
        // question here is what the service answers, not how the row arose.
        // Hence an in-memory copy rather than a persisted one.
        $line = $this->lineWithBranchCells('6', [[
            'header' => [100, 300], 'branch' => [15, 100], 'type' => BranchConnectionType::TEE,
        ]]);

        ['joint' => $joint] = $this->teeBranchJoint($line);

        // clone, not fresh(): re-querying outside the owner's RLS scope
        // returns null, and the line we already have is fully loaded.
        $unpinned = clone $line;
        $unpinned->pms_class_snapshot_id = null;

        $verdict = $this->asOwner(fn () => app(JointService::class)->branchVerdictFor(
            $unpinned,
            $joint->portA,
            $joint->portB,
        ));

        $this->assertSame(JointBranchRuleState::NO_SNAPSHOT, $verdict['branch_rule_state']);
        $this->assertNull($verdict['branch_class_type']);
        $this->assertNull($verdict['branch_pad_thickness_mm']);
    }

    public function test_an_issued_pms_pin_cannot_be_removed_from_a_line(): void
    {
        // Why the state above is unreachable in normal use. A line that has
        // been given an issued class keeps it: un-pinning would silently
        // re-open every verdict already recorded on the line's welds.
        $line = $this->lineWithBranchCells('6', [[
            'header' => [100, 300], 'branch' => [15, 100], 'type' => BranchConnectionType::TEE,
        ]]);

        $this->expectException(QueryException::class);

        $this->asOwner(function () use ($line): void {
            DB::table('piping_lines')->where('id', $line->id)
                ->update(['pms_class_snapshot_id' => null]);
        });
    }

    public function test_a_class_that_says_nothing_about_this_size_pair_says_so(): void
    {
        // The cell covers DN100-300 headers. This line is 6" = DN150, so the
        // header matches - but the branch is 1/2", well below the cell's floor.
        $line = $this->lineWithBranchCells('6', [[
            'header' => [100, 300], 'branch' => [40, 100], 'type' => BranchConnectionType::TEE,
        ]]);

        ['joint' => $joint] = $this->teeBranchJoint($line, '1/2');

        $this->assertSame(JointBranchRuleState::NO_RULE, $joint->branch_rule_state);
        $this->assertNull($joint->branch_class_type);
        $this->assertSame('UNGOVERNED', $joint->branchDeviation());
    }

    // ------------------------------------------------------------- reporting --

    public function test_ungoverned_branch_welds_are_counted_separately(): void
    {
        $line = $this->lineWithBranchCells('6', [[
            'header' => [100, 300], 'branch' => [40, 100], 'type' => BranchConnectionType::TEE,
        ]]);

        // One governed branch (2" = DN50, inside the cell) and one outside it.
        $this->teeBranchJoint($line, '2');
        $this->teeBranchJoint($line, '1/2');

        $metrics = $this->asOwner(fn () => app(JointService::class)->lineMetrics($line->fresh()));

        $this->assertSame(2, $metrics['branch_welds']);
        $this->assertSame(1, $metrics['branch_ungoverned'], 'the report that gets chased');
    }

    public function test_a_weld_in_the_run_does_not_consult_the_branch_table(): void
    {
        // Branch rules are a header x branch question. A pipe welded to a tee's
        // RUN port is a weld in the line, and has no verdict to contradict.
        $line = $this->lineWithBranchCells('6', [[
            'header' => [100, 300], 'branch' => [15, 100], 'type' => BranchConnectionType::TEE,
        ]]);

        $joint = $this->asOwner(function () use ($line): Joint {
            $tee = app(ComponentService::class)->place($line, $this->owner(), 'TEE');

            return app(ComponentService::class)
                ->attach($tee->ports->firstWhere('role', 'RUN'), $this->owner(), 'PIPE')['joint'];
        });

        $this->assertNull($joint->branch_kind);
        $this->assertNull($joint->branch_rule_state);
        $this->assertNull($joint->branch_class_type);
        $this->assertNull($joint->branchDeviation());
    }
}
