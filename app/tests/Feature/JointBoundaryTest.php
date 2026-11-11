<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Port;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ComponentService;
use App\Services\EquipmentService;
use App\Services\JointService;
use App\Services\PipingLineService;
use App\Support\Piping\Pcf\PcfAdmission;
use App\Support\Piping\Pcf\PcfParser;
use App\Support\Piping\PortCompatibility;
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
 * What a joint can connect, and what it cannot — written down as tests so the
 * answer cannot drift away from the truth again.
 *
 * There are four cases a piping engineer might mean by "connect two pipes".
 * They are not the same case, and they do not have the same answer:
 *
 *   1. two components on ONE line            -> a joint is created
 *   2. a pipe on line A to a pipe on line B  -> refused (tie-in)
 *   3. a P2P branch onto a HEADER WALL        -> explicit one-port wall joint
 *   4. a pipe onto an EQUIPMENT NOZZLE       -> a joint is created
 *
 * A wall joint keeps the header at two end ports and stores a frozen header
 * reference instead. This boundary test pins that shape and the read-only PCF
 * warning; the PCF-to-model writer/UI is still absent. The cross-line tie-in in
 * (2) remains refused.
 */
final class JointBoundaryTest extends TestCase
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
        return $this->tenant->forIdentityLookup(fn () => User::where('email', 'karimi@nioc.example')->firstOrFail());
    }

    private function pm(): User
    {
        return $this->tenant->forIdentityLookup(fn () => User::where('email', 'rezaei@pars.example')->firstOrFail());
    }

    private function line(string $size = '6'): PipingLine
    {
        $project = $this->project();
        $owner = $this->owner();

        return $this->tenant->runAs($owner->company_id, function () use ($project, $owner, $size): PipingLine {
            $lines = app(PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            $class = PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $owner->company_id, 'description_en' => 'CS 150#'],
            );

            ApprovedPmsClassFixture::ensure($project, $owner, $class);

            return $lines->create(
                Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $owner,
                ['nominal_size' => $size, 'service_code' => 'P', 'piping_class_id' => $class->id],
            );
        }, $project->id);
    }

    /** @return string port id */
    private function pipePortOn(PipingLine $line): string
    {
        $project = $this->project();
        $owner = $this->owner();

        return $this->tenant->runAs($owner->company_id, function () use ($owner, $line): string {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);

            return (string) app(ComponentService::class)->place($line, $owner, 'PIPE')->ports->first()->id;
        }, $project->id);
    }

    // ── Case 0: the ordinary branch, which is what a branch usually means ──

    /**
     * A branch off a header through a TEE — the ordinary case, and it works.
     *
     * This is what "pipe to pipe" means on most jobs: one pipe running, another
     * branching off it. The branch is not welded to the header's wall; it is
     * welded to a tee let into the run, and a tee has a real BRANCH port. Two
     * joints come out of it, header-to-tee and tee-to-branch, both numbered on
     * the one line because everything here belongs to it.
     */
    public function test_case_0_a_branch_through_a_tee_is_fully_modelled(): void
    {
        $line = $this->line();
        $project = $this->project();
        $owner = $this->owner();

        $joints = $this->tenant->runAs(
            $owner->company_id,
            function () use ($owner, $line): int {
                DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);
                $components = app(ComponentService::class);

                // The header run.
                $header = $components->place($line, $owner, 'PIPE', ['length_mm' => 6000]);

                // The tee let into it. A pipe has two RUN ports and no END;
                // the branch side of a tee is the one with role BRANCH.
                $tee = $components->attach($header->ports->last(), $owner, 'TEE')['component'];

                $branchPort = $tee->ports->firstWhere('role', 'BRANCH');
                $this->assertNotNull($branchPort, 'A tee must offer a branch port.');

                // And the branch itself.
                $components->attach($branchPort, $owner, 'PIPE', ['length_mm' => 1500]);

                return (int) DB::table('joints')->where('piping_line_id', $line->id)->count();
            },
            $project->id,
        );

        $this->assertSame(2, $joints, 'A branch through a tee makes two joints.');
    }

    /**
     * The P2P wall path deliberately is not an OLET-header fitting path.
     *
     * An OLET carries a fabricated (FA) run port and a branch port. The normal
     * two-port `join()` refuses BW-to-FA, correctly avoiding a fake transition
     * fitting. `joinToWall()` currently represents fitting-less STUB_IN/ON
     * joints only; it does not yet place the OLET's own header-side weld. So an
     * OLET can be placed and a branch pipe can be joined to its BRANCH port,
     * but the OLET-to-header weld is still not modelled. This is separate from
     * the P2P wall joint delivered in ADR-006 §14.
     */
    public function test_case_0b_an_olets_header_side_connects_to_nothing(): void
    {
        $line = $this->line();
        $project = $this->project();
        $owner = $this->owner();

        $olet = $this->tenant->runAs(
            $owner->company_id,
            fn () => app(ComponentService::class)->place($line, $owner, 'OLET'),
            $project->id,
        );

        $runPort = $olet->ports->firstWhere('role', 'RUN');
        $branchPort = $olet->ports->firstWhere('role', 'BRANCH');

        $this->assertSame('FA', $runPort->end_type, 'The header side of an olet is a fabricated weld onto a wall.');
        $this->assertSame('BW', $branchPort->end_type);

        // A branch CAN be welded to the branch end...
        $this->tenant->runAs($owner->company_id, function () use ($owner, $branchPort): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);
            app(ComponentService::class)->attach($branchPort, $owner, 'PIPE', ['length_mm' => 1500]);
        }, $project->id);

        // ...but the header side cannot be welded to a pipe end at all.
        $verdict = app(PortCompatibility::class)->evaluate(
            $runPort,
            $this->tenant->runAs(
                $owner->company_id,
                fn () => app(ComponentService::class)->place($line, $owner, 'PIPE')->ports->first(),
                $project->id,
            ),
        );

        $this->assertTrue($verdict->isInvalid(), 'FA against BW must be refused, not warned about.');
    }

    // ── Case 1 ────────────────────────────────────────────────────────────

    public function test_case_1_two_components_on_one_line_do_join(): void
    {
        $line = $this->line();
        $owner = $this->owner();
        $project = $this->project();

        $joint = $this->tenant->runAs($owner->company_id, function () use ($owner, $line) {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);

            $tee = app(ComponentService::class)->place($line, $owner, 'TEE');
            $branch = $tee->ports->firstWhere('role', 'BRANCH');

            return app(ComponentService::class)
                ->attach($branch, $owner, 'PIPE', ['length_mm' => 1500])['joint'];
        }, $project->id);

        $this->assertSame($line->id, $joint->piping_line_id);
    }

    // ── Case 2 ────────────────────────────────────────────────────────────

    public function test_case_2_a_tie_in_between_two_lines_is_refused_by_the_service(): void
    {
        $first = $this->line();
        $second = $this->line('6');

        $this->assertNotSame($first->id, $second->id, 'Two genuinely different lines.');

        $portA = $this->pipePortOn($first);
        $portB = $this->pipePortOn($second);
        $project = $this->project();
        $owner = $this->owner();

        try {
            $this->tenant->runAs($owner->company_id, function () use ($owner, $portA, $portB): void {
                DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);
                app(JointService::class)->join(
                    Port::query()->findOrFail($portA),
                    Port::query()->findOrFail($portB),
                    $owner,
                );
            }, $project->id);

            $this->fail('A tie-in was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(
                __('epcc.joint.cross_line_not_supported'),
                $e->errors()['port_id'][0] ?? null,
            );
        }
    }

    public function test_case_2_the_database_refuses_it_too_even_written_by_hand(): void
    {
        $first = $this->line();
        $second = $this->line('6');

        $portA = $this->pipePortOn($first);
        $portB = $this->pipePortOn($second);
        $project = $this->project();
        $owner = $this->owner();

        [$a, $b] = [$portA, $portB];
        if (strcmp($a, $b) > 0) {
            [$a, $b] = [$b, $a];
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/JT_LINE_MISMATCH/');

        // Bypassing JointService on purpose: the point is that the rule holds
        // for a raw INSERT, not only for the one method that enforces it.
        $this->tenant->runAs($owner->company_id, function () use ($project, $owner, $a, $b, $first): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);
            DB::table('joints')->insert([
                'id' => (string) Str::uuid(),
                'project_id' => $project->id, 'company_id' => $owner->company_id,
                'piping_line_id' => $first->id,
                'port_a_id' => $a, 'port_b_id' => $b,
                'numbering_policy' => 'NOT_APPLICABLE', 'joint_type' => 'FLANGED',
                'state' => 'MODELLED', 'compatibility_state' => 'VALID', 'source' => 'MANUAL',
                'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, $project->id);
    }

    // ── Case 3 ────────────────────────────────────────────────────────────

    public function test_case_3_a_header_has_no_port_on_its_wall(): void
    {
        $line = $this->line();

        // The header is a plain pipe with nothing on it yet.
        $header = $this->pipePortOn($line);

        $headerPorts = $this->tenant->runAs(
            $this->owner()->company_id,
            fn () => Port::query()
                ->join('components', 'components.id', '=', 'ports.component_id')
                ->where('components.piping_line_id', $line->id)
                ->count(),
            $this->project()->id,
        );

        // A pipe still has exactly two end ports. The wall joint references
        // the header component instead of adding a synthetic third port; this
        // is what keeps openPorts() correct after any number of branches.
        $this->assertSame(2, $headerPorts, 'A pipe offers its two ends, and nothing else.');
        $this->assertNotNull($header);
    }

    public function test_case_3_a_tap_in_a_pcf_is_reported_rather_than_swallowed(): void
    {
        $pcf = "UNITS-BORE INCH\nUNITS-CO-ORDS MM\nPIPELINE-REFERENCE L-1\n"
            ."PIPE\n    END-POINT 0 0 0 6 BW\n    END-POINT 3000 0 0 6 BW\n"
            ."    UNIQUE-COMPONENT-IDENTIFIER HEADER-1\n"
            ."TAP-CONNECTION\n    CO-ORDS 1500 0 0 3 BW\n"
            ."    UNIQUE-COMPONENT-IDENTIFIER HEADER-1\n";

        $file = (new PcfAdmission(new PcfParser))->parse($pcf);

        $codes = array_map(static fn ($w): string => $w->code, $file->warnings);

        $this->assertContains('tap_connection', $codes);

        // And no joint was, or could be, created from it.
        $joints = $this->tenant->runAs(
            $this->owner()->company_id,
            fn () => DB::table('joints')->count(),
            $this->project()->id,
        );
        $this->assertSame(0, (int) $joints);
    }

    // ── Case 4 ────────────────────────────────────────────────────────────

    /**
     * A nozzle declared by one company cannot (yet) be welded by another.
     *
     * Joining marks both ports connected, and `ports` is row-level secured to
     * the company that owns the row. So the company that declares the nozzle
     * has to be the company that welds to it. Whether that is right is a real
     * question — on most jobs the EPC models the vessel and a subcontractor
     * welds the pipe to it — but it is the current answer, and it is better
     * stated than discovered.
     */
    public function test_case_4b_the_nozzle_owner_has_to_be_the_one_who_welds(): void
    {
        $project = $this->project();
        $pm = $this->pm();
        $owner = $this->owner();

        $this->tenant->runAs($pm->company_id, function () use ($project, $pm): void {
            app(EquipmentService::class)->create($project, $pm, [
                'tag_number' => 'V-102', 'equipment_type' => 'VESSEL',
                'name_en' => 'Cross company vessel', 'name_fa' => 'مخزنِ بین‌شرکتی',
            ]);
        }, $project->id);

        $row = $this->tenant->runAs(
            $project->owner_company_id,
            fn () => DB::table('equipment')->where('tag_number', 'V-102')->first(),
            $project->id,
        );

        $nozzleId = $this->tenant->runAs($pm->company_id, fn () => app(EquipmentService::class)
            ->addNozzle($project, $pm, $row->id, [
                'tag' => 'N1', 'nozzle_type' => 'PROCESS',
                'nps_label' => '6"', 'nps_decimal' => 6.0, 'end_type' => 'BW',
            ])->id, $project->id);

        $nozzlePort = $this->tenant->runAs(
            $project->owner_company_id,
            fn () => DB::table('ports')->where('nozzle_id', $nozzleId)->value('id'),
            $project->id,
        );

        $line = $this->line();

        $this->expectException(QueryException::class);

        $this->tenant->runAs($owner->company_id, function () use ($owner, $line, $nozzlePort): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);
            $pipePort = $this->pipePortOn($line);

            app(JointService::class)->join(
                Port::query()->findOrFail($pipePort),
                Port::query()->findOrFail($nozzlePort),
                $owner,
            );
        }, $project->id);
    }

    public function test_case_4_a_pipe_joins_an_equipment_nozzle(): void
    {
        $project = $this->project();
        $owner = $this->owner();

        $this->tenant->runAs($owner->company_id, function () use ($project, $owner): void {
            app(EquipmentService::class)->create($project, $owner, [
                'tag_number' => 'V-101', 'equipment_type' => 'VESSEL',
                'name_en' => 'Separator vessel', 'name_fa' => 'مخزن جداکننده',
            ]);
        }, $project->id);

        $row = $this->tenant->runAs(
            $project->owner_company_id,
            fn () => DB::table('equipment')->where('tag_number', 'V-101')->first(),
            $project->id,
        );

        $nozzleId = $this->tenant->runAs($owner->company_id, fn () => app(EquipmentService::class)
            ->addNozzle($project, $owner, $row->id, [
                'tag' => 'N1', 'nozzle_type' => 'PROCESS',
                'nps_label' => '6"', 'nps_decimal' => 6.0, 'end_type' => 'BW',
            ])->id, $project->id);

        $nozzlePort = $this->tenant->runAs(
            $project->owner_company_id,
            fn () => DB::table('ports')->where('nozzle_id', $nozzleId)->value('id'),
            $project->id,
        );

        $line = $this->line();

        $joint = $this->tenant->runAs($owner->company_id, function () use ($owner, $line, $nozzlePort) {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);
            $pipePort = $this->pipePortOn($line);

            return app(JointService::class)->join(
                Port::query()->findOrFail($pipePort),
                Port::query()->findOrFail($nozzlePort),
                $owner,
            );
        }, $project->id);

        $this->assertSame(
            $line->id,
            $joint->piping_line_id,
            'The joint takes its line from the pipe: a nozzle owns no line.',
        );
    }
}
