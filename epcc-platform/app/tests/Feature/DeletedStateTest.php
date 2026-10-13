<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Equipment;
use App\Models\EquipmentNozzle;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ComponentService;
use App\Services\EquipmentService;
use App\Services\JointService;
use App\Services\PipingLineService;
use App\Support\LineReleaseState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The DELETED state (the user's ruling, 2026-09-18).
 *
 * WHAT THIS IS ACTUALLY FOR. Phase 3b withdrew automatic numbering after
 * discovering that `max(sequence)` cannot see rows that are gone, so deleting
 * `11-P-102` handed that tag straight to the next pump. That fixed allocation.
 * A hard DELETE reopens the identical hole from the other side: the tag string
 * goes free, and nothing stops somebody retyping a number that is printed on an
 * issued isometric or stamped on a nameplate.
 *
 * So nothing tagged is ever removed. It is marked DELETED, keeps its row, and
 * therefore keeps its tag - the unique index goes on refusing it forever.
 *
 * The three things worth proving:
 *   1. THE TAG STAYS RESERVED after deletion.
 *   2. A CONNECTED THING CANNOT BE DELETED - disconnect first.
 *   3. DELETED ROWS STAY VISIBLE but stop counting as work.
 */
final class DeletedStateTest extends TestCase
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

    private function svc(): EquipmentService
    {
        return app(EquipmentService::class);
    }

    /** @param array<string,mixed> $extra */
    private function register(array $extra = []): Equipment
    {
        return $this->asOwner(fn () => $this->svc()->register($this->project(), $this->owner(), array_merge([
            'equipment_type' => 'P',
            'unit_code' => '11',
            'tag_number' => '11-P-101',
        ], $extra)));
    }

    // ------------------------------------------- the tag stays reserved --

    public function test_a_deleted_tag_cannot_be_reused(): void
    {
        // THE WHOLE POINT. Before this change the row vanished and the tag came
        // back with it; the same number could then reach two nameplates.
        $pump = $this->register();

        $this->asOwner(fn () => $this->svc()->remove($pump, $this->owner(), 'Entered twice'));

        $this->expectException(ValidationException::class);

        $this->register();
    }

    public function test_the_deleted_row_survives_with_its_tag(): void
    {
        $pump = $this->register();
        $this->asOwner(fn () => $this->svc()->remove($pump, $this->owner(), 'Wrong unit'));

        $found = $this->asOwner(fn () => Equipment::where('tag_number', '11-P-101')->first());

        $this->assertNotNull($found);
        $this->assertSame('DELETED', $found->state);
        $this->assertTrue($found->isDeleted());
    }

    public function test_deletion_records_who_when_and_why(): void
    {
        // "Deleted by someone, at some time, for no recorded cause" is the
        // audit trail that answers nothing. WHY is the question people ask.
        $pump = $this->register();
        $actor = $this->owner();

        $deleted = $this->asOwner(fn () => $this->svc()->remove($pump, $actor, 'Duplicate of 11-P-104'));

        $this->assertSame($actor->id, $deleted->deleted_by);
        $this->assertNotNull($deleted->deleted_at);
        $this->assertSame('Duplicate of 11-P-104', $deleted->deletion_reason);
    }

    public function test_a_reason_is_required(): void
    {
        $pump = $this->register();

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->remove($pump, $this->owner(), '   '));
    }

    public function test_the_database_refuses_a_deleted_state_without_provenance(): void
    {
        // The service demands provenance, but the service is not the only way
        // in - an import or a fix-up script writes straight to the table. The
        // CHECK is what makes an unauditable row unrepresentable.
        $pump = $this->register();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => \Illuminate\Support\Facades\DB::table('equipment')
            ->where('id', $pump->id)
            ->update(['state' => 'DELETED']));
    }

    public function test_deleting_twice_is_refused(): void
    {
        $pump = $this->register();
        $this->asOwner(fn () => $this->svc()->remove($pump, $this->owner(), 'First'));

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->remove($pump->fresh(), $this->owner(), 'Second'));
    }

    // ------------------------------------------- connected things refuse --

    public function test_equipment_with_a_connected_nozzle_cannot_be_deleted(): void
    {
        // Deleting it would leave a live line joined to something that no
        // longer exists - the same class of orphan the component remove()
        // guards against.
        [$flange] = $this->lineWithFlange();

        $vessel = $this->asOwner(function () use ($flange) {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);

            $nozzle = $this->svc()->addNozzle($vessel, $this->owner(), [
                'tag' => 'N-1', 'nominal_size' => '6', 'end_type' => 'FL', 'rating' => '150#',
            ]);

            app(JointService::class)->join(
                $flange->ports->firstWhere('end_type', 'FL'), $nozzle->port, $this->owner()
            );

            return $vessel;
        });

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->remove($vessel->fresh(), $this->owner(), 'No longer needed'));
    }

    public function test_equipment_with_only_unconnected_nozzles_can_be_deleted(): void
    {
        $vessel = $this->asOwner(function () {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);
            $this->svc()->addNozzle($vessel, $this->owner(), ['tag' => 'N-1', 'nominal_size' => '6']);

            return $vessel;
        });

        $deleted = $this->asOwner(fn () => $this->svc()->remove($vessel, $this->owner(), 'Cancelled by client'));

        $this->assertTrue($deleted->isDeleted());
    }

    public function test_deleting_equipment_withdraws_its_nozzles_too(): void
    {
        // They are parts of the vessel, not independent objects. Leaving them
        // live would keep them in the nozzle schedule of something withdrawn.
        $vessel = $this->asOwner(function () {
            $v = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);
            $this->svc()->addNozzle($v, $this->owner(), ['tag' => 'N-1', 'nominal_size' => '6']);
            $this->svc()->addNozzle($v, $this->owner(), ['tag' => 'N-2', 'nominal_size' => '4']);

            return $v;
        });

        $this->asOwner(fn () => $this->svc()->remove($vessel, $this->owner(), 'Scope change'));

        $live = $this->asOwner(fn () => EquipmentNozzle::where('equipment_id', $vessel->id)
            ->where('is_deleted', false)->count());

        $this->assertSame(0, $live);
    }

    public function test_a_connected_nozzle_cannot_be_withdrawn_on_its_own(): void
    {
        [$flange] = $this->lineWithFlange();

        $nozzle = $this->asOwner(function () use ($flange) {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);
            $n = $this->svc()->addNozzle($vessel, $this->owner(), [
                'tag' => 'N-1', 'nominal_size' => '6', 'end_type' => 'FL', 'rating' => '150#',
            ]);

            app(JointService::class)->join(
                $flange->ports->firstWhere('end_type', 'FL'), $n->port, $this->owner()
            );

            return $n;
        });

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->removeNozzle($nozzle->fresh(), $this->owner(), 'Not required'));
    }

    public function test_a_withdrawn_nozzle_leaves_the_nozzle_schedule(): void
    {
        $schedule = $this->asOwner(function () {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);
            $this->svc()->addNozzle($vessel, $this->owner(), ['tag' => 'N-1', 'nominal_size' => '6']);
            $n2 = $this->svc()->addNozzle($vessel, $this->owner(), ['tag' => 'N-2', 'nominal_size' => '4']);

            $this->svc()->removeNozzle($n2, $this->owner(), 'Deleted from the datasheet at rev C');

            return $this->svc()->nozzleSchedule($vessel->fresh());
        });

        $this->assertSame(['N-1'], collect($schedule)->pluck('tag')->all());
    }

    public function test_a_nozzle_tag_stays_reserved_on_its_vessel(): void
    {
        $this->expectException(ValidationException::class);

        $this->asOwner(function () {
            $vessel = $this->svc()->register($this->project(), $this->owner(), [
                'equipment_type' => 'V', 'unit_code' => '11', 'tag_number' => '11-V-101',
            ]);
            $n = $this->svc()->addNozzle($vessel, $this->owner(), ['tag' => 'N-1', 'nominal_size' => '6']);

            $this->svc()->removeNozzle($n, $this->owner(), 'Wrong size');

            // N-1 appears on the issued nozzle schedule; it must not come back
            // meaning something else.
            $this->svc()->addNozzle($vessel, $this->owner(), ['tag' => 'N-1', 'nominal_size' => '8']);
        });
    }

    // --------------------------------------------------- still visible --

    public function test_a_deleted_pump_is_not_hidden_from_a_plain_query(): void
    {
        // Deliberately NOT Laravel's SoftDeletes. A global scope would answer
        // "where did P-101A go?" with silence. It must be found, and labelled.
        $pump = $this->register();
        $this->asOwner(fn () => $this->svc()->remove($pump, $this->owner(), 'Merged into 11-P-104'));

        $this->assertSame(1, $this->asOwner(fn () => Equipment::count()));
        $this->assertSame(0, $this->asOwner(fn () => Equipment::query()->live()->count()));
    }

    // ------------------------------------------------------------ lines --

    public function test_a_draft_line_can_be_withdrawn_and_keeps_its_number(): void
    {
        $line = $this->makeLine('6-P-A1-PH-14-SS-2201-001');

        $withdrawn = $this->asOwner(fn () => app(PipingLineService::class)
            ->withdraw($line, $this->owner(), 'Duplicated from the P&ID'));

        $this->assertSame(LineReleaseState::DELETED, $withdrawn->release_state);
        $this->assertNotNull($withdrawn->deleted_at);

        // The number is still taken.
        $this->expectException(ValidationException::class);
        $this->makeLine('6-P-A1-PH-14-SS-2201-001');
    }

    public function test_a_released_line_cannot_be_withdrawn(): void
    {
        // It has been issued to the field. Stopping construction is
        // revertRelease() with a reason - a different, visible act.
        $this->assertFalse(
            LineReleaseState::canTransition(LineReleaseState::RELEASED, LineReleaseState::DELETED)
        );
    }

    public function test_a_line_carrying_components_cannot_be_withdrawn(): void
    {
        // Their joints and dia-inch are somebody's progress claim. Strip the
        // model first, so what is being discarded is visible.
        $line = $this->makeLine('8-P-A1-PH-14-SS-2201-002');

        $this->asOwner(fn () => app(ComponentService::class)->place($line, $this->owner(), 'FLANGE'));

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => app(PipingLineService::class)
            ->withdraw($line->fresh(), $this->owner(), 'Not needed'));
    }

    public function test_deleted_is_terminal(): void
    {
        // Undelete would make the audit trail a lie: "deleted on the 3rd" -
        // and then what? Re-enter it, leaving mistake and correction visible.
        $this->assertSame([], LineReleaseState::nextStates(LineReleaseState::DELETED));
    }

    public function test_a_deleted_line_is_neither_buildable_nor_editable(): void
    {
        $this->assertFalse(LineReleaseState::isBuildable(LineReleaseState::DELETED));
        $this->assertFalse(LineReleaseState::isEditable(LineReleaseState::DELETED));
        $this->assertFalse(LineReleaseState::isAfc(LineReleaseState::DELETED));
    }

    public function test_deleted_does_not_sort_past_released(): void
    {
        // -1, not 3. A register sorted by progress must not show withdrawn
        // lines as the most advanced work on the job.
        $this->assertSame(-1, LineReleaseState::ordinalOf(LineReleaseState::DELETED));
    }

    public function test_deleted_has_a_bilingual_label(): void
    {
        $label = LineReleaseState::label(LineReleaseState::DELETED);

        $this->assertSame('Deleted', $label['en']);
        $this->assertNotSame('DELETED', $label['fa']);
    }

    public function test_the_database_refuses_a_deleted_line_without_provenance(): void
    {
        $line = $this->makeLine('4-P-A1-PH-14-SS-2201-003');

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => \Illuminate\Support\Facades\DB::table('piping_lines')
            ->where('id', $line->id)
            ->update(['release_state' => 'DELETED']));
    }

    // ----------------------------------------------------------- helpers --

    private function makeLine(string $number): PipingLine
    {
        return $this->asOwner(function () use ($number) {
            $lines = app(PipingLineService::class);
            $project = $this->project();

            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
            );

            return $lines->register(
                Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $this->owner(),
                $number,
                ['nominal_size' => '6', 'service_code' => 'P'],
            );
        });
    }

    /** @return array{0: \App\Models\Component} */
    private function lineWithFlange(): array
    {
        $line = $this->makeLine('6-P-A1-PH-14-SS-2201-009');

        $flange = $this->asOwner(fn () => app(ComponentService::class)
            ->place($line, $this->owner(), 'FLANGE'));

        return [$flange];
    }
}
