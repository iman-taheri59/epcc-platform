<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-010 §3 — equipment, nozzles, and the claim the whole section rests on.
 *
 * The claim is that a nozzle is a PORT OWNER, exactly like a component, and
 * that once that is accepted a pipe-to-nozzle joint needs NO change to the
 * `joints` table at all. That is a structural claim about a table built in
 * September, so it is worth proving rather than asserting: the first test here
 * writes a joint between a component port and a nozzle port through the
 * ordinary columns, and if `joints` had needed a discriminator it would have
 * needed a migration, not a test.
 *
 * The rest hold the parts that make the model honest: a nozzle belongs to the
 * equipment it was cut into, a spare nozzle is not an open end, and equipment
 * cannot quietly borrow another project's subsystem or purchase order line.
 */
final class EquipmentNozzleTest extends TestCase
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

    private function owner(): User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    private function asOwner(callable $fn, ?Project $project = null): mixed
    {
        $p = $project ?? $this->project();

        return $this->tenant->runAs($p->owner_company_id, $fn, $p->id);
    }

    /** @param  array<string, mixed>  $overrides */
    private function equipment(array $overrides = []): string
    {
        $id = (string) Str::uuid();

        $this->asOwner(function () use ($id, $overrides): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$this->owner()->id]);
            DB::table('equipment')->insert(array_merge([
                'id' => $id, 'project_id' => $this->project()->id, 'company_id' => $this->project()->owner_company_id,
                'tag_number' => 'V-101', 'equipment_type' => 'VESSEL',
                'name_en' => 'Separator vessel', 'name_fa' => 'مخزن جداکننده',
                'created_by' => $this->owner()->id, 'created_at' => now(), 'updated_at' => now(),
            ], $overrides));
        });

        return $id;
    }

    /** @param  array<string, mixed>  $overrides */
    private function nozzle(string $equipmentId, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        $this->asOwner(function () use ($id, $equipmentId, $overrides): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$this->owner()->id]);
            DB::table('equipment_nozzles')->insert(array_merge([
                'id' => $id, 'project_id' => $this->project()->id, 'company_id' => $this->project()->owner_company_id,
                'equipment_id' => $equipmentId, 'tag' => 'N-1', 'nozzle_type' => 'PROCESS',
                'nps_label' => '6', 'nps_decimal' => 6.0, 'end_type' => 'FL', 'rating' => '150#', 'facing' => 'RF',
                'is_spare' => false, 'created_by' => $this->owner()->id, 'created_at' => now(), 'updated_at' => now(),
            ], $overrides));
        });

        return $id;
    }

    /** @param  array<string, mixed>  $overrides */
    private function port(string $ownerKind, ?string $ownerId, array $overrides = []): string
    {
        $id = (string) Str::uuid();

        $this->asOwner(function () use ($id, $ownerKind, $ownerId, $overrides): void {
            DB::table('ports')->insert(array_merge([
                'id' => $id, 'project_id' => $this->project()->id, 'company_id' => $this->project()->owner_company_id,
                'owner_kind' => $ownerKind, 'seq' => 1, 'role' => 'END', 'is_connected' => false,
                'created_at' => now(), 'updated_at' => now(),
            ], $ownerKind === 'NOZZLE' ? ['nozzle_id' => $ownerId] : ['component_id' => $ownerId], $overrides));
        });

        return $id;
    }

    private function line(string $size = '6'): \App\Models\PipingLine
    {
        $project = $this->project();
        $owner = $this->owner();

        return $this->asOwner(function () use ($project, $owner, $size) {
            $lines = app(\App\Services\PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            $class = \App\Models\PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
            );

            \Tests\Support\ApprovedPmsClassFixture::ensure($project, $owner, $class);

            return $lines->create(
                \App\Models\Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $owner,
                ['nominal_size' => $size, 'service_code' => 'P', 'piping_class_id' => $class->id],
            );
        });
    }

    public function test_a_pipe_lands_on_a_nozzle_without_touching_the_joints_table(): void
    {
        $line = $this->line();
        $pipe = $this->asOwner(fn () => app(\App\Services\ComponentService::class)->place($line, $this->owner(), 'PIPE'));

        $nozzleId = $this->nozzle($this->equipment());

        // The component's port is the one the template gave it. The nozzle's
        // port is hand-made here only because no nozzle service exists yet.
        $componentPort = $pipe->ports->first()->id;
        $nozzlePort = $this->port('NOZZLE', $nozzleId);

        // The ordinary columns, nothing added for nozzles. If `joints` had
        // needed a discriminator, this insert would not be the whole story.
        $this->asOwner(function () use ($componentPort, $nozzlePort, $line): void {
            DB::table('joints')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'piping_line_id' => $line->id,
                'port_a_id' => $componentPort, 'port_b_id' => $nozzlePort,
                'numbering_policy' => 'REQUIRED', 'joint_seq' => 1, 'joint_type' => 'BW', 'state' => 'FITTED',
                'compatibility_state' => 'VALID', 'source' => 'MANUAL',
                // A fitted joint has to say who fitted it and when, the same
                // as any other — the nozzle end gets no exemption.
                'fitted_by_code' => 'W-1', 'fitted_at' => now(),
                'created_by' => $this->owner()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('ports')->whereIn('id', [$componentPort, $nozzlePort])->update(['is_connected' => true]);
        });

        $joint = $this->asOwner(fn () => DB::table('joints')->first());
        $this->assertNotNull($joint);
        $this->assertSame($componentPort, $joint->port_a_id);
        $this->assertSame($nozzlePort, $joint->port_b_id);

        $owners = $this->asOwner(fn () => DB::table('ports')
            ->whereIn('id', [$componentPort, $nozzlePort])->pluck('owner_kind')->sort()->values()->all());
        $this->assertSame(['COMPONENT', 'NOZZLE'], $owners, 'one joint, two different port owners, one table');
    }

    public function test_a_port_cannot_claim_two_owners_at_once(): void
    {
        $nozzleId = $this->nozzle($this->equipment());
        $component = $this->asOwner(fn () => DB::table('components')->value('id'));

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/ports_exactly_one_owner/');

        // A COMPONENT port carrying a nozzle_id is unrepresentable rather than
        // merely discouraged — that CHECK is what makes one ports table safe.
        $this->port('COMPONENT', $component, ['nozzle_id' => $nozzleId]);
    }

    public function test_an_unconnected_nozzle_is_an_open_end_but_a_capped_spare_is_not(): void
    {
        $equipmentId = $this->equipment();
        $processNozzle = $this->nozzle($equipmentId, ['tag' => 'N-1']);
        $spareNozzle = $this->nozzle($equipmentId, ['tag' => 'N-2', 'nozzle_type' => 'SPARE', 'is_spare' => true]);

        $this->port('NOZZLE', $processNozzle);
        $this->port('NOZZLE', $spareNozzle);

        $open = $this->asOwner(fn () => DB::table('ports')
            ->join('equipment_nozzles', 'equipment_nozzles.id', '=', 'ports.nozzle_id')
            ->where('ports.is_connected', false)
            ->where('equipment_nozzles.is_spare', false)
            ->pluck('equipment_nozzles.tag')->all());

        $this->assertSame(['N-1'], $open, 'a capped spare is not unfinished work');
    }

    public function test_a_nozzle_belongs_to_equipment_of_the_same_project(): void
    {
        $other = $this->other();
        $foreign = $this->tenant->runAs($other->owner_company_id, function () use ($other): string {
            $actor = User::where('is_active', true)
                ->where('company_id', $other->owner_company_id)->firstOrFail();
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$actor->id]);

            return DB::table('equipment')->insertGetId([
                'id' => (string) Str::uuid(), 'project_id' => $other->id, 'company_id' => $other->owner_company_id,
                'tag_number' => 'V-999', 'equipment_type' => 'VESSEL',
                'name_en' => 'Other project vessel', 'name_fa' => 'مخزن پروژهٔ دیگر',
                'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }, $other->id);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/EQ_EQUIPMENT_NOT_FOUND/');

        $this->nozzle($foreign);
    }

    public function test_equipment_cannot_borrow_another_projects_subsystem(): void
    {
        $foreignSubsystem = $this->asOwner(fn () => DB::table('subsystems')
            ->where('project_id', $this->other()->id)->value('id'), $this->other());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/EQ_SUBSYSTEM_NOT_FOUND/');

        $this->equipment(['subsystem_id' => $foreignSubsystem ?? (string) Str::uuid()]);
    }

    public function test_two_pieces_of_equipment_may_not_share_a_tag(): void
    {
        $this->equipment(['tag_number' => 'V-101']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/equipment_project_tag_unique/');

        $this->equipment(['tag_number' => 'V-101']);
    }

    public function test_the_same_nozzle_tag_is_fine_on_different_equipment(): void
    {
        $a = $this->equipment(['tag_number' => 'V-101']);
        $b = $this->equipment(['tag_number' => 'V-102']);

        $this->nozzle($a, ['tag' => 'N-1']);
        $this->nozzle($b, ['tag' => 'N-1']);

        $count = $this->asOwner(fn () => DB::table('equipment_nozzles')->where('tag', 'N-1')->count());
        $this->assertSame(2, $count, 'N-1 is unique to its vessel, not to the plant');
    }

    public function test_a_nozzle_port_is_numbered_once(): void
    {
        $nozzleId = $this->nozzle($this->equipment());
        $this->port('NOZZLE', $nozzleId, ['seq' => 1]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/ports_nozzle_seq_unique/');

        $this->port('NOZZLE', $nozzleId, ['seq' => 1, 'role' => 'BRANCH']);
    }

    public function test_equipment_joins_the_completion_machinery_through_its_subsystem(): void
    {
        $subsystem = $this->asOwner(fn () => Subsystem::where('project_id', $this->project()->id)->firstOrFail());
        $equipmentId = $this->equipment(['subsystem_id' => $subsystem->id]);

        // Nothing new was built for this: punch items and MC certificates are
        // keyed to subsystems already, so a vessel is MC-able for free.
        $linked = $this->asOwner(fn () => DB::table('equipment')
            ->join('subsystems', 'subsystems.id', '=', 'equipment.subsystem_id')
            ->where('equipment.id', $equipmentId)->value('subsystems.code'));

        $this->assertSame($subsystem->code, $linked);
    }
}
