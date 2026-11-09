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
     * Weld a line's component to a nozzle, through the ordinary `joints`
     * columns. This is ADR-010 §3's whole claim: no discriminator, no new
     * table — the nozzle is simply another port owner.
     */
    private function weldLineToNozzle(PipingLine $line, string $nozzleId): void
    {
        $project = $this->project();
        $owner = $this->owner();

        $this->tenant->runAs($owner->company_id, function () use ($project, $owner, $line, $nozzleId): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$owner->id]);

            $pipe = app(\App\Services\ComponentService::class)->place($line, $owner, 'PIPE');
            $componentPort = $pipe->ports->first()->id;

            $nozzlePort = (string) Str::uuid();
            DB::table('ports')->insert([
                'id' => $nozzlePort, 'project_id' => $project->id, 'company_id' => $owner->company_id,
                'owner_kind' => 'NOZZLE', 'nozzle_id' => $nozzleId, 'seq' => 1, 'role' => 'END',
                'is_connected' => false, 'created_at' => now(), 'updated_at' => now(),
            ]);

            // A joint stores its two ends in a fixed order, so sort them the
            // way the CHECK constraint expects rather than trusting the draw.
            [$first, $second] = [(string) $componentPort, $nozzlePort];
            if (strcmp($first, $second) > 0) {
                [$first, $second] = [$second, $first];
            }

            DB::table('joints')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $project->id, 'company_id' => $owner->company_id,
                'piping_line_id' => $line->id,
                'port_a_id' => $first, 'port_b_id' => $second,
                'numbering_policy' => 'REQUIRED', 'joint_seq' => 1, 'joint_type' => 'BW', 'state' => 'FITTED',
                'compatibility_state' => 'VALID', 'source' => 'MANUAL',
                'fitted_by_code' => 'W-1', 'fitted_at' => now(),
                'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('ports')->whereIn('id', [$first, $second])->update(['is_connected' => true]);
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

        $this->weldLineToNozzle($line, $nozzleId);

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
