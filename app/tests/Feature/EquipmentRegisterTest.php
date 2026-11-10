<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Facility;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\System;
use App\Models\User;
use App\Services\EquipmentService;
use App\Support\FacilityKind;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ADR-010 §3, the service and the screen.
 *
 * The structural slice (EquipmentNozzleTest) proved a pipe can land on a
 * nozzle. This one is about the two things a register needs before anyone
 * trusts it: the right people may add to it, and the wrong ones may not —
 * while still being able to READ it, because a QC inspector who cannot see
 * what the nozzles are cannot inspect the welds on them.
 *
 * `equipment.maintain` is deliberately a MODELLING capability, not a viewing
 * one. It sits on employer_rep, project_manager, consultant and mc_supervisor,
 * and is withheld from qc_inspector and site_engineer. The first two tests
 * here are that decision, written down so it cannot be quietly reversed.
 */
final class EquipmentRegisterTest extends TestCase
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

    private function other(): Project
    {
        return Project::where('code', 'BB-2')->firstOrFail();
    }

    private function user(string $email): User
    {
        return $this->tenant->forIdentityLookup(fn () => User::where('email', $email)->firstOrFail());
    }

    /** PM on PH-14 — holds equipment.maintain. */
    private function pm(): User
    {
        return $this->user('rezaei@pars.example');
    }

    /** The employer's representative — the company the piping config belongs to. */
    private function owner(): User
    {
        return $this->user('karimi@nioc.example');
    }

    /** QC inspector on PH-14 — may read the register, may not add to it. */
    private function qc(): User
    {
        return $this->user('sadeghi@pars.example');
    }

    private function service(): EquipmentService
    {
        return app(EquipmentService::class);
    }

    private function indexUrl(): string
    {
        return route('equipment', ['projectCode' => 'PH-14']);
    }

    private function storeUrl(): string
    {
        return route('equipment.store', ['projectCode' => 'PH-14']);
    }

    private function subsystem(): Subsystem
    {
        return $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => Subsystem::where('project_id', $this->project()->id)->orderBy('code')->firstOrFail(),
            $this->project()->id,
        );
    }

    /** @param  array<string, mixed>  $overrides */
    private function equipment(array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $project = $this->project();

        $this->tenant->runAs($project->owner_company_id, function () use ($id, $overrides, $project): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$this->pm()->id]);
            DB::table('equipment')->insert(array_merge([
                'id' => $id, 'project_id' => $project->id, 'company_id' => $project->owner_company_id,
                'tag_number' => 'V-101', 'equipment_type' => 'VESSEL',
                'name_en' => 'Separator vessel', 'name_fa' => 'مخزن جداکننده',
                'created_by' => $this->pm()->id, 'created_at' => now(), 'updated_at' => now(),
            ], $overrides));
        }, $project->id);

        return $id;
    }

    /** @param  array<string, mixed>  $overrides */
    private function nozzle(string $equipmentId, array $overrides = []): string
    {
        $id = (string) Str::uuid();
        $project = $this->project();

        $this->tenant->runAs($project->owner_company_id, function () use ($id, $equipmentId, $overrides, $project): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$this->pm()->id]);
            DB::table('equipment_nozzles')->insert(array_merge([
                'id' => $id, 'equipment_id' => $equipmentId, 'project_id' => $project->id,
                'company_id' => $project->owner_company_id,
                'tag' => 'N1', 'nozzle_type' => 'PROCESS', 'nps_label' => '6"', 'is_spare' => false,
                'created_by' => $this->pm()->id, 'created_at' => now(), 'updated_at' => now(),
            ], $overrides));

            // The port, because a nozzle with no port is a nozzle nobody can
            // weld to — what the service does in declarePort().
            DB::table('ports')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $project->id,
                'company_id' => $project->owner_company_id,
                'owner_kind' => 'NOZZLE', 'nozzle_id' => $id, 'seq' => 1, 'role' => 'END',
                'nominal_size' => '6"', 'nominal_size_decimal' => 6.0,
                'end_type' => $overrides['end_type'] ?? 'BW',
                'is_connected' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, $project->id);

        return $id;
    }

    public function test_the_pm_may_register_equipment_through_the_screen(): void
    {
        $this->actingAs($this->pm())
            ->post($this->storeUrl(), [
                'tag_number' => 'P-101',
                'equipment_type' => 'PUMP',
                'name_en' => 'Produced water pump',
                'name_fa' => 'پمپ آبِ تولیدی',
                'subsystem_id' => $this->subsystem()->id,
                'state' => 'ORDERED',
            ])
            ->assertRedirect(route('equipment', ['projectCode' => 'PH-14']))
            ->assertSessionHas('status', __('equipment.created'));

        $count = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => DB::table('equipment')->where('project_id', $this->project()->id)->where('tag_number', 'P-101')->count(),
            $this->project()->id,
        );
        $this->assertSame(1, (int) $count);
    }

    public function test_the_qc_inspector_sees_the_register_but_gets_no_create_form(): void
    {
        $this->equipment();

        // He can read it — that is the point of withholding a MODELLING
        // capability rather than a viewing one.
        $body = $this->actingAs($this->qc())->get($this->indexUrl())
            ->assertOk()
            ->assertSee('V-101')
            ->getContent();

        // The add form is not rendered for him at all.
        $this->assertStringNotContainsString('equipment.store', $body);
    }

    public function test_the_qc_inspector_is_refused_if_he_posts_anyway(): void
    {
        $this->actingAs($this->qc())
            ->post($this->storeUrl(), [
                'tag_number' => 'P-999',
                'equipment_type' => 'PUMP',
                'name_en' => 'Sneaky pump',
                'name_fa' => 'پمپِ پنهانی',
            ])
            ->assertForbidden();

        $count = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => DB::table('equipment')->where('tag_number', 'P-999')->count(),
            $this->project()->id,
        );
        $this->assertSame(0, (int) $count, 'The refusal must be a refusal, not a warning.');
    }

    public function test_the_qc_inspector_may_read_a_single_equipment_record(): void
    {
        $id = $this->equipment();

        $this->actingAs($this->qc())
            ->get(route('equipment.show', ['projectCode' => 'PH-14', 'equipmentId' => $id]))
            ->assertOk()
            ->assertSee('V-101');
    }

    public function test_the_pm_may_add_a_nozzle_through_the_screen(): void
    {
        $id = $this->equipment();

        $this->actingAs($this->pm())
            ->post(route('equipment.nozzles.store', ['projectCode' => 'PH-14', 'equipmentId' => $id]), [
                'tag' => 'N1',
                'nozzle_type' => 'PROCESS',
                'nps_label' => '6"',
                'dn' => '150',
                'end_type' => 'FLANGE',
                'rating' => 'CL300',
                'facing' => 'RF',
            ])
            ->assertRedirect(route('equipment.show', ['projectCode' => 'PH-14', 'equipmentId' => $id]))
            ->assertSessionHas('status', __('equipment.nozzle_added'));

        $count = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => DB::table('equipment_nozzles')->where('equipment_id', $id)->count(),
            $this->project()->id,
        );
        $this->assertSame(1, (int) $count);
    }


    public function test_a_nozzle_is_declared_with_the_port_that_makes_it_connectable(): void
    {
        $id = $this->equipment();

        $this->actingAs($this->pm())
            ->post(route('equipment.nozzles.store', ['projectCode' => 'PH-14', 'equipmentId' => $id]), [
                'tag' => 'N1',
                'nozzle_type' => 'PROCESS',
                'nps_label' => '6"',
                'nps_decimal' => 6.0,
                'end_type' => 'BW',
                'schedule' => 'STD',
            ]);

        // ADR-010 §3.1 says a nozzle owns a port like a component does. That
        // is a claim about a table, and a claim is not a row: without this
        // there is nothing to weld to and the schedule could only ever say
        // UNCONNECTED no matter what the welders did.
        $port = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => DB::table('ports')
                ->join('equipment_nozzles', 'equipment_nozzles.id', '=', 'ports.nozzle_id')
                ->where('equipment_nozzles.equipment_id', $id)
                ->select('ports.*')
                ->first(),
            $this->project()->id,
        );

        $this->assertNotNull($port, 'Declaring a nozzle must create its port.');
        $this->assertSame('NOZZLE', $port->owner_kind);
        $this->assertSame('END', $port->role);
        $this->assertSame('BW', $port->end_type);
        $this->assertFalse((bool) $port->is_connected);
    }

    public function test_two_nozzles_are_refused_because_nothing_owns_the_number(): void
    {
        $id = $this->equipment();
        $a = $this->nozzleViaService($id, ['tag' => 'N1']);
        $b = $this->nozzleViaService($id, ['tag' => 'N2']);

        $portA = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => DB::table('ports')->where('nozzle_id', $a)->value('id'),
            $this->project()->id,
        );
        $portB = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => DB::table('ports')->where('nozzle_id', $b)->value('id'),
            $this->project()->id,
        );

        $this->expectException(\Illuminate\Validation\ValidationException::class);

        $this->tenant->runAs(
            $this->owner()->company_id,
            fn () => app(\App\Services\JointService::class)->join(
                \App\Models\Port::query()->findOrFail($portA),
                \App\Models\Port::query()->findOrFail($portB),
                $this->owner(),
            ),
            $this->project()->id,
        );
    }

    /** A nozzle made by the service, so it has its port. */
    private function nozzleViaService(string $equipmentId, array $overrides = []): string
    {
        $project = $this->project();
        $pm = $this->pm();

        return $this->tenant->runAs($pm->company_id, function () use ($project, $pm, $equipmentId, $overrides): string {
            return app(EquipmentService::class)
                ->addNozzle($project, $pm, $equipmentId, array_merge([
                    'tag' => 'N1',
                    'nozzle_type' => 'PROCESS',
                    'nps_label' => '6"',
                    'nps_decimal' => 6.0,
                    'end_type' => 'BW',
                ], $overrides))
                ->id;
        }, $project->id);
    }

    public function test_the_screen_refuses_a_subsystem_from_another_project(): void
    {
        $foreign = $this->foreignSubsystem();

        // The refusal is a 422 carrying the guard's own wording. Asserting on
        // the exception rather than the error page: a rendered 422 is not a
        // contract, the status and the message are.
        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->pm())->post($this->storeUrl(), [
                'tag_number' => 'V-666',
                'equipment_type' => 'VESSEL',
                'name_en' => 'Borrowed vessel',
                'name_fa' => 'مخزنِ قرضی',
                'subsystem_id' => $foreign->id,
            ]);
            $this->fail('A subsystem from another project was accepted.');
        } catch (HttpException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(__('equipment.subsystem_not_found'), $e->getMessage());
        }

        $count = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => DB::table('equipment')->where('tag_number', 'V-666')->count(),
            $this->project()->id,
        );
        $this->assertSame(0, (int) $count);
    }

    /**
     * A subsystem that genuinely belongs to another project.
     *
     * The demo seeder gives BB-2 no subsystems at all, so this one is built
     * here — inside BB-2's own tenant session, because the INSERT policy
     * rejects rows written from another project's. A facility is needed first:
     * `subsystems.facility_id` is NOT NULL, and a subsystem with no facility
     * is not a thing the model allows.
     *
     * This fixture is the reason the test below is worth having. A random UUID
     * would also be refused, but it would be refused by the foreign key, so a
     * deleted guard would still look like a passing test. With a real
     * subsystem from another project, nothing but the guard can stop it.
     */
    private function foreignSubsystem(): Subsystem
    {
        $other = $this->other();
        $pm = $this->pm();

        return $this->tenant->runAs($pm->company_id, function () use ($other, $pm): Subsystem {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$pm->id]);

            $facility = Facility::create([
                'id' => (string) Str::uuid(),
                'project_id' => $other->id,
                'company_id' => $pm->company_id,
                'code' => 'BB-PL-01',
                'name_en' => 'BB-2 process plant',
                'name_fa' => 'واحد فرآورش BB-2',
                'kind' => FacilityKind::PLANT,
            ]);

            // A subsystem hangs off a system, and `subsystems.system_id` is
            // NOT NULL. Systems carry no facility_id of their own — ADR-004
            // §8.4, left untouched — so the facility link lives down here.
            $system = System::create([
                'id' => (string) Str::uuid(),
                'project_id' => $other->id,
                'company_id' => $pm->company_id,
                'code' => 'BB-SYS-99',
                'name_en' => 'Foreign system',
                'name_fa' => 'سیستمِ بیگانه',
            ]);

            return Subsystem::create([
                'id' => (string) Str::uuid(),
                'project_id' => $other->id,
                'company_id' => $pm->company_id,
                'facility_id' => $facility->id,
                'system_id' => $system->id,
                'code' => 'BB-SUB-99',
                'name_en' => 'Foreign subsystem',
                'name_fa' => 'زیرسیستمِ بیگانه',
                'discipline' => 'PIPING',
            ]);
        }, $other->id);
    }


    /** A real piping line, built with the project's own service. */
    private function line(): PipingLine
    {
        $project = $this->project();
        $owner = $this->owner();

        return $this->tenant->runAs($owner->company_id, function () use ($project, $owner): PipingLine {
            $lines = app(\App\Services\PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            $class = PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $owner->company_id, 'description_en' => 'CS 150#'],
            );

            \Tests\Support\ApprovedPmsClassFixture::ensure($project, $owner, $class);

            return $lines->create(
                Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $owner,
                ['nominal_size' => '6', 'service_code' => 'P', 'piping_class_id' => $class->id],
            );
        }, $project->id);
    }

    /**
     * Weld a line's component to a nozzle, through JointService — the way the
     * application actually does it.
     *
     * Nothing here writes to `joints` by hand. That matters: the point of
     * ADR-010 §3.1 is that a pipe-to-nozzle weld needs no change to the joint
     * table, and a test that inserts the row itself proves nothing about
     * whether the application can make one.
     */
    private function weldLineToNozzle(PipingLine $line, string $nozzleId): \App\Models\Joint
    {
        $project = $this->project();
        $owner = $this->owner();

        return $this->tenant->runAs($owner->company_id, function () use ($project, $owner, $line, $nozzleId) {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);

            $pipePort = app(\App\Services\ComponentService::class)
                ->place($line, $owner, 'PIPE')
                ->ports->first()->id;

            $nozzlePort = DB::table('ports')->where('nozzle_id', $nozzleId)->value('id');
            $this->assertNotNull($nozzlePort, 'The nozzle must have a port to weld to.');

            return app(\App\Services\JointService::class)->join(
                \App\Models\Port::query()->findOrFail($pipePort),
                \App\Models\Port::query()->findOrFail($nozzlePort),
                $owner,
            );
        }, $project->id);
    }

    public function test_the_schedule_shows_an_unconnected_nozzle_as_an_open_end(): void
    {
        $id = $this->equipment();
        $this->nozzle($id, ['tag' => 'N1']);

        $schedule = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => $this->service()->nozzleSchedule($id),
            $this->project()->id,
        );

        $this->assertCount(1, $schedule);
        $this->assertNull($schedule[0]['connected_line']);
        $this->assertTrue($schedule[0]['is_open_end'], 'Nothing is welded to it, so it is unfinished.');
    }

    public function test_a_capped_spare_is_not_reported_as_an_open_end(): void
    {
        $id = $this->equipment();
        $this->nozzle($id, ['tag' => 'N1', 'is_spare' => true]);

        $schedule = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => $this->service()->nozzleSchedule($id),
            $this->project()->id,
        );

        $this->assertNull($schedule[0]['connected_line']);
        $this->assertFalse(
            $schedule[0]['is_open_end'],
            'A capped spare is a design decision, not an unfinished weld. This is the distinction the whole section exists to protect.',
        );
    }

    public function test_the_schedule_names_the_line_welded_to_a_nozzle(): void
    {
        $id = $this->equipment();
        $nozzleId = $this->nozzle($id, ['tag' => 'N1']);
        $line = $this->line();

        $joint = $this->weldLineToNozzle($line, $nozzleId);

        // The joint takes its line from the pipe side: a nozzle belongs to
        // equipment, not to a line, so it has no line number to contribute.
        $this->assertSame($line->id, $joint->piping_line_id);

        $schedule = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => $this->service()->nozzleSchedule($id),
            $this->project()->id,
        );

        $this->assertCount(1, $schedule);
        $this->assertSame(
            $line->line_number,
            $schedule[0]['connected_line'],
            'The schedule must name the line — that is the deliverable an engineer reads before a hydrotest.',
        );
        $this->assertFalse($schedule[0]['is_open_end'], 'It is welded, so it is not an open end.');
    }

    public function test_the_screen_shows_the_connected_line_and_the_open_ends(): void
    {
        $id = $this->equipment();
        $this->nozzle($id, ['tag' => 'N1']);
        $this->nozzle($id, ['tag' => 'N2']);
        $this->nozzle($id, ['tag' => 'N3', 'is_spare' => true]);

        $line = $this->line();
        $welded = $this->nozzle($id, ['tag' => 'N4']);
        $this->weldLineToNozzle($line, $welded);

        $this->actingAs($this->pm())
            ->get(route('equipment.show', ['projectCode' => 'PH-14', 'equipmentId' => $id]))
            ->assertOk()
            ->assertSee($line->line_number)
            ->assertSee(__('equipment.unconnected'))
            ->assertSee(__('equipment.spare'));
    }


    /**
     * The cross-line rule, held by the database rather than by PHP.
     *
     * The service has always refused a joint between two lines. What it could
     * not do is stop a direct INSERT, and the suite writes joints by hand in
     * more than one place. These two tests bypass JointService entirely: if
     * the rule is only in PHP, they pass while the database happily holds a
     * joint that no service would have made.
     */
    public function test_the_database_refuses_a_joint_spanning_two_lines(): void
    {
        $first = $this->line();
        $second = $this->anotherLine();

        $portA = $this->pipePortOn($first);
        $portB = $this->pipePortOn($second);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/JT_LINE_MISMATCH/');

        $this->insertJoint($portA, $portB, $first->id);
    }

    public function test_the_database_accepts_a_joint_whose_nozzle_side_owns_no_line(): void
    {
        $line = $this->line();
        $nozzleId = $this->nozzle($this->equipment());

        $pipePort = $this->pipePortOn($line);
        $nozzlePort = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => DB::table('ports')->where('nozzle_id', $nozzleId)->value('id'),
            $this->project()->id,
        );

        $this->insertJoint($pipePort, $nozzlePort, $line->id);

        $count = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => DB::table('joints')->count(),
            $this->project()->id,
        );
        $this->assertSame(1, (int) $count);
    }

    public function test_the_database_refuses_a_joint_between_two_nozzles(): void
    {
        $id = $this->equipment();
        $portA = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => DB::table('ports')->where('nozzle_id', $this->nozzle($id, ['tag' => 'N1']))->value('id'),
            $this->project()->id,
        );
        $portB = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => DB::table('ports')->where('nozzle_id', $this->nozzle($id, ['tag' => 'N2']))->value('id'),
            $this->project()->id,
        );

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/JT_NO_LINE_SIDE/');

        $this->insertJoint($portA, $portB, $this->line()->id);
    }

    /** A second line on the same project, so a cross-line pair is possible. */
    private function anotherLine(): \App\Models\PipingLine
    {
        $project = $this->project();
        $owner = $this->owner();

        return $this->tenant->runAs($owner->company_id, function () use ($project, $owner): \App\Models\PipingLine {
            $lines = app(\App\Services\PipingLineService::class);
            $class = \App\Models\PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $owner->company_id, 'description_en' => 'CS 150#'],
            );

            return $lines->create(
                \App\Models\Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $owner,
                ['nominal_size' => '6', 'service_code' => 'P', 'piping_class_id' => $class->id],
            );
        }, $project->id);
    }

    /** @return string port id */
    private function pipePortOn(\App\Models\PipingLine $line): string
    {
        $project = $this->project();
        $owner = $this->owner();

        return $this->tenant->runAs($owner->company_id, function () use ($project, $owner, $line): string {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);

            return (string) app(\App\Services\ComponentService::class)
                ->place($line, $owner, 'PIPE')
                ->ports->first()->id;
        }, $project->id);
    }

    /** Writes a joint by hand, bypassing JointService on purpose. */
    private function insertJoint(string $portA, string $portB, string $lineId): void
    {
        $project = $this->project();
        $owner = $this->owner();

        [$first, $second] = [$portA, $portB];
        if (strcmp($first, $second) > 0) {
            [$first, $second] = [$second, $first];
        }

        $this->tenant->runAs($owner->company_id, function () use ($project, $owner, $first, $second, $lineId): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);

            DB::table('joints')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'project_id' => $project->id, 'company_id' => $owner->company_id,
                'piping_line_id' => $lineId,
                'port_a_id' => $first, 'port_b_id' => $second,
                'numbering_policy' => 'NOT_APPLICABLE', 'joint_type' => 'FLANGED',
                'state' => 'MODELLED', 'compatibility_state' => 'VALID', 'source' => 'MANUAL',
                'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, $project->id);
    }

    public function test_the_register_is_scoped_to_its_own_project(): void
    {
        $this->equipment(['tag_number' => 'V-101']);
        $this->equipment(['tag_number' => 'V-102']);

        $register = $this->tenant->runAs(
            $this->project()->owner_company_id,
            fn () => $this->service()->register($this->project(), $this->pm()),
            $this->project()->id,
        );

        $this->assertSame(['V-101', 'V-102'], $register['equipment']->pluck('tag_number')->sort()->values()->all());
        $this->assertTrue($register['can_maintain']);
    }
}
