<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingLine;
use App\Models\PipingTestPack;
use App\Models\Project;
use App\Models\User;
use App\Services\InspectionResultService;
use App\Services\ItrCompilerService;
use App\Services\PipingLineService;
use App\Services\PipingTestPackService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\QualityResultFixture;
use Tests\TestCase;

/**
 * ADR-048 A.5b slice 3a — a dossier is about a scope, not only about one pack.
 *
 * The ITR could only be compiled for a single test pack, which is the unit a
 * contractor tests and not the unit a client takes over: handover is discussed
 * per subsystem, and then for the plant. So a dossier may now be compiled for
 * TEST_PACK, SUBSYSTEM, SYSTEM, AREA, FACILITY or PROJECT — every one of them
 * a level the project declared, never one the platform invented. The two
 * physical rungs (AREA, FACILITY) are the other half of the system scope: a
 * system dossier names the facilities it spans, an area dossier names the
 * SYSTEMS that reach into it, and both answers come out of the same resolver,
 * so they cannot disagree.
 *
 * What makes this more than a bigger WHERE clause:
 *
 *   - ONE resolver decides which lines a scope means, and the dossier and the
 *     completeness report both read it, so they cannot disagree about what a
 *     subsystem dossier is about;
 *   - a subsystem dossier NAMES THE PACK each result came from, because a
 *     hydrotest without its pack is a document its reader has to guess at;
 *   - the completeness report gained the gap a pack scope could never see — a
 *     line assigned to no test pack has never been tested, and a subsystem
 *     dossier that stayed silent about it would be the most dangerous kind of
 *     green;
 *   - a subsystem has no "not ready" state, so the premature-evidence refusal
 *     belongs to the pack scope alone.
 */
final class ItrScopeTest extends TestCase
{
    private User $owner;

    private Project $project;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->tenant = app(TenantContext::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        app()->setLocale('fa');
    }

    // ------------------------------------------------------------- the resolver

    public function test_a_subsystem_dossier_is_about_every_line_of_that_subsystem(): void
    {
        [$subsystem] = $this->twoLinesInOneSubsystem();
        $dossier = $this->compile('SUBSYSTEM', $subsystem);

        $this->assertSame('SUBSYSTEM', $dossier['scope_kind']);
        $this->assertSame($subsystem, $dossier['scope_ref']);
        $this->assertNull($dossier['test_pack_id'], 'a subsystem dossier is not about one pack');
        $this->assertCount(2, $dossier['lines']);
        $this->assertSame(['PH-14-SCOPE-A', 'PH-14-SCOPE-B'], collect($dossier['lines'])->pluck('line_number')->all());
    }

    public function test_a_subsystem_dossier_does_not_reach_into_another_subsystem(): void
    {
        [$subsystem, $other] = $this->twoLinesInOneSubsystem();
        $this->lineIn($other);

        $dossier = $this->compile('SUBSYSTEM', $subsystem);

        $this->assertCount(2, $dossier['lines'], 'the other subsystem’s line is not this dossier’s business');
    }

    public function test_a_project_dossier_is_about_every_line_in_the_project(): void
    {
        [$subsystem, $other] = $this->twoLinesInOneSubsystem();
        $this->lineIn($other);

        $dossier = $this->compile('PROJECT', null);

        $this->assertSame('PROJECT', $dossier['scope_kind']);
        $this->assertNull($dossier['scope_ref'], 'the project needs no reference: the row names it');
        $this->assertCount(3, $dossier['lines']);
    }

    public function test_the_pack_scope_still_compiles_exactly_what_it_did(): void
    {
        $line = $this->lineIn($this->subsystemId());
        $pack = $this->readyPack('TP-SCOPE-1', [$line]);

        $dossier = $this->compile('TEST_PACK', $pack->id);

        $this->assertSame('TEST_PACK', $dossier['scope_kind']);
        $this->assertSame((string) $pack->id, $dossier['test_pack_id']);
        $this->assertSame('TP-SCOPE-1', $dossier['pack']['code']);
        $this->assertCount(1, $dossier['lines']);
    }

    public function test_the_wrapper_and_the_general_body_are_the_same_document(): void
    {
        // A preview and a frozen snapshot must still agree byte-for-byte, and
        // every caller written since A.5 asks by pack, so the pack function is
        // a wrapper over the general one — not a second body that can drift.
        $line = $this->lineIn($this->subsystemId());
        $pack = $this->readyPack('TP-SCOPE-2', [$line]);

        $both = $this->asOwner(fn () => DB::selectOne(
            'SELECT epcc_itr_test_pack_dossier(?,?) AS by_pack,
                    epcc_itr_scope_dossier(?,?,?) AS by_scope,
                    epcc_itr_test_pack_completeness(?,?) AS c_pack,
                    epcc_itr_scope_completeness(?,?,?) AS c_scope',
            [$this->project->id, $pack->id,
             $this->project->id, 'TEST_PACK', $pack->id,
             $this->project->id, $pack->id,
             $this->project->id, 'TEST_PACK', $pack->id],
        ));

        $this->assertSame((string) $both->by_pack, (string) $both->by_scope);
        $this->assertSame((string) $both->c_pack, (string) $both->c_scope);
    }

    // ---------------------------------------------------------- the honesty bits

    public function test_a_subsystem_dossier_names_the_pack_each_result_belongs_to(): void
    {
        $line = $this->lineIn($this->subsystemId());
        $pack = $this->readyPack('TP-SCOPE-3', [$line]);
        $this->nominate('record_pack_result');
        $this->asOwner(fn () => app(InspectionResultService::class)->recordTestResult(
            $this->project, $this->owner, (string) $pack->id,
            ['test_type' => 'HYDROSTATIC', 'medium' => 'WATER', 'test_pressure' => '21.750',
             'pressure_unit' => 'BAR', 'hold_duration_minutes' => 120,
             'started_at' => '2026-10-28T06:00:00+03:30', 'completed_at' => '2026-10-28T09:30:00+03:30',
             'result' => 'PASS', 'request_id' => (string) Str::uuid()], 'en'));

        $dossier = $this->compile('SUBSYSTEM', $this->subsystemId());

        // Without the pack code, a reader cannot tell which of the subsystem's
        // packs this hydrotest is evidence about.
        $this->assertSame('TP-SCOPE-3', $dossier['test_results'][0]['test_pack_code']);
        $this->assertSame('TP-SCOPE-3', $dossier['test_packs'][0]['code']);
    }

    public function test_a_line_with_no_test_pack_is_a_gap_a_pack_dossier_could_never_see(): void
    {
        $this->lineIn($this->subsystemId()); // never assigned to a pack

        $completeness = $this->completenessFor('SUBSYSTEM', $this->subsystemId());

        $this->assertCount(1, $completeness['missing']['lines_without_test_pack']);
        $this->assertFalse($completeness['is_complete']);
    }

    public function test_a_subsystem_dossier_reports_each_pack_in_scope_that_has_no_pass(): void
    {
        $lineA = $this->lineIn($this->subsystemId());
        $lineB = $this->lineIn($this->subsystemId());
        $this->readyPack('TP-SCOPE-4', [$lineA]);
        $this->readyPack('TP-SCOPE-5', [$lineB]);

        $completeness = $this->completenessFor('SUBSYSTEM', $this->subsystemId());

        // Named, not flagged: "something has no result" is not a work list.
        $this->assertEqualsCanonicalizing(
            [['pack_code' => 'TP-SCOPE-4'], ['pack_code' => 'TP-SCOPE-5']],
            $completeness['missing']['packs_without_passed_result'],
        );
        $this->assertSame(2, $completeness['counts']['packs']);
    }

    public function test_a_subsystem_dossier_is_complete_only_when_every_pack_in_scope_passed(): void
    {
        $lineA = $this->lineIn($this->subsystemId());
        $packA = $this->readyPack('TP-SCOPE-6', [$lineA]);
        $this->passResult($packA);

        $this->assertTrue($this->completenessFor('SUBSYSTEM', $this->subsystemId())['is_complete']);

        // A second pack with no result at all, and the subsystem is not done.
        $lineB = $this->lineIn($this->subsystemId());
        $this->readyPack('TP-SCOPE-7', [$lineB]);

        $this->assertFalse($this->completenessFor('SUBSYSTEM', $this->subsystemId())['is_complete']);
    }

    public function test_the_hold_gate_travels_with_the_scope(): void
    {
        // The gate is a function of the packs in scope: a subsystem dossier
        // inherits the uncleared holds of every pack it draws results from.
        $line = $this->lineIn($this->subsystemId());
        $this->readyPack('TP-SCOPE-8', [$line]);

        $completeness = $this->completenessFor('SUBSYSTEM', $this->subsystemId());

        $this->arrayHasKey('hold_notifications_not_cleared');
        $this->assertSame([], $completeness['missing']['hold_notifications_not_cleared']);
    }

    // ------------------------------------------------------------------ freezing

    public function test_a_subsystem_dossier_can_be_frozen_and_signed(): void
    {
        $this->lineIn($this->subsystemId());

        $snapshot = $this->asOwner(fn () => app(ItrCompilerService::class)->freeze(
            $this->project, $this->owner, $this->subsystemId(), (string) Str::uuid(), 'en', 'SUBSYSTEM'));

        $row = $this->asOwner(fn () => DB::table('itr_snapshots')->where('id', $snapshot['id'])->first());
        $this->assertSame('SUBSYSTEM', (string) $row->scope_kind);
        $this->assertSame($this->subsystemId(), (string) $row->scope_ref);
        $this->assertNull($row->test_pack_id);
        // 4 -> 5 when the two physical rungs landed: every grouping level now
        // answers the same shape of question — what it holds, and where it sits.
        // 5 -> 6 when the start-up priority landed on the document.
        $this->assertSame(6, json_decode((string) $row->dossier, true, 512, JSON_THROW_ON_ERROR)['schema_version']);
    }

    public function test_a_project_dossier_can_be_frozen(): void
    {
        $this->lineIn($this->subsystemId());

        $snapshot = $this->asOwner(fn () => app(ItrCompilerService::class)->freeze(
            $this->project, $this->owner, null, (string) Str::uuid(), 'en', 'PROJECT'));

        $row = $this->asOwner(fn () => DB::table('itr_snapshots')->where('id', $snapshot['id'])->first());
        $this->assertSame('PROJECT', (string) $row->scope_kind);
        $this->assertNull($row->scope_ref);
    }

    public function test_a_draft_pack_still_cannot_be_frozen_but_a_subsystem_can(): void
    {
        $line = $this->lineIn($this->subsystemId());
        $draft = $this->asOwner(fn () => app(PipingTestPackService::class)
            ->create($this->project, $this->owner, ['code' => 'TP-SCOPE-DRAFT']));

        $this->assertRefused(
            fn () => $this->asOwner(fn () => app(ItrCompilerService::class)->freeze(
                $this->project, $this->owner, (string) $draft->id, (string) Str::uuid(), 'en', 'TEST_PACK')),
            'premature',
        );

        // A subsystem is not a claim anybody declares ready, so there is no
        // "not ready" state to refuse — only a completeness report that will
        // say what is missing.
        $this->asOwner(fn () => app(ItrCompilerService::class)->freeze(
            $this->project, $this->owner, $this->subsystemId(), (string) Str::uuid(), 'en', 'SUBSYSTEM'));
        $this->assertSame(1, $this->asOwner(fn () => DB::table('itr_snapshots')
            ->where('scope_kind', 'SUBSYSTEM')->count()));
    }

    public function test_an_unknown_scope_is_refused_before_anything_is_stored(): void
    {
        // UNIT is the instructive refusal: it is a word every refinery uses and
        // it is NOT a level in this breakdown — a unit is a naming convention
        // inside a system code (SY-PL01-FIRE), not a rung a dossier hangs on.
        // Asking for one must be refused, not quietly answered by something
        // else that happens to sound like it.
        $this->assertRefused(
            fn () => $this->asOwner(fn () => app(ItrCompilerService::class)->freeze(
                $this->project, $this->owner, $this->subsystemId(), (string) Str::uuid(), 'en', 'UNIT')),
            'must be one of',
        );

        $this->assertSame(0, $this->asOwner(fn () => DB::table('itr_snapshots')->count()));
    }

    public function test_a_subsystem_from_another_project_cannot_be_frozen(): void
    {
        $foreign = (string) Str::uuid();

        $this->assertRefused(
            fn () => $this->asOwner(fn () => app(ItrCompilerService::class)->freeze(
                $this->project, $this->owner, $foreign, (string) Str::uuid(), 'en', 'SUBSYSTEM')),
            'subsystem',
        );
    }

    public function test_the_project_scope_needs_no_reference_and_refuses_one(): void
    {
        // Two rows claiming the same dossier under different names is exactly
        // what the resolver's one-definition rule exists to prevent.
        $this->assertRefused(
            fn () => $this->asOwner(fn () => app(ItrCompilerService::class)->freeze(
                $this->project, $this->owner, $this->subsystemId(), (string) Str::uuid(), 'en', 'PROJECT')),
            'The scope reference must be a UUID|scope',
        );
    }

    // ------------------------------------------------------------------ surface

    public function test_the_screen_offers_all_three_scopes_and_opens_the_one_chosen(): void
    {
        $line = $this->lineIn($this->subsystemId());
        $this->readyPack('TP-SCOPE-9', [$line]);

        $response = $this->actingAs($this->owner)->get($this->route(['sel' => 'SUB:'.$this->subsystemId()]));

        $response->assertOk();
        $response->assertSee('id="scope-select"', false);
        $response->assertSee('value="PROJECT"', false);
        $response->assertSee('value="SUB:'.$this->subsystemId().'"', false);
        $response->assertSee('id="scope-heading"', false);
        // The screen names the pack with no PASS: on a subsystem dossier that
        // is the work list, and it is why the gap is a list and not a flag.
        $response->assertSee('TP-SCOPE-9', false);
        $response->assertSee(__('quality_itr.packs').' 1', false);
        // Notices are linked to packs, so the link surface is the pack scope's.
        $response->assertDontSee('id="notices-heading"', false);

        $pack = $this->readyPack('TP-SCOPE-10', [$line]);
        $this->actingAs($this->owner)->get($this->route(['sel' => 'PACK:'.$pack->id]))
            ->assertOk()
            ->assertSee('id="notices-heading"', false)
            ->assertSee('id="freeze-form"', false);
    }

    public function test_freezing_a_subsystem_from_the_screen_stores_a_subsystem_snapshot(): void
    {
        $this->lineIn($this->subsystemId());

        $this->actingAs($this->owner)->post(route('quality.itr.freeze', ['projectCode' => 'PH-14', 'lang' => 'en']), [
            'scope_kind' => 'SUBSYSTEM',
            'scope_ref' => $this->subsystemId(),
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect($this->route(['scope' => 'SUBSYSTEM', 'ref' => $this->subsystemId()]));

        $this->assertSame(1, $this->asOwner(fn () => DB::table('itr_snapshots')
            ->where('scope_kind', 'SUBSYSTEM')->where('scope_ref', $this->subsystemId())->count()));
    }

    public function test_the_snapshot_list_follows_the_scope_and_not_the_underlying_pack(): void
    {
        $line = $this->lineIn($this->subsystemId());
        $pack = $this->readyPack('TP-SCOPE-11', [$line]);
        $itr = app(ItrCompilerService::class);

        $this->asOwner(fn () => $itr->freeze($this->project, $this->owner, (string) $pack->id, (string) Str::uuid(), 'en'));
        $this->asOwner(fn () => $itr->freeze($this->project, $this->owner, $this->subsystemId(), (string) Str::uuid(), 'en', 'SUBSYSTEM'));

        // One pack's dossier is not the subsystem's, even though that pack is
        // inside the subsystem: two scopes, two histories, no guessing.
        $this->assertSame(1, $this->asOwner(fn () => $itr->snapshotsForPack($this->project, $this->owner, (string) $pack->id)->count()));
        $this->assertSame(1, $this->asOwner(fn () => $itr->snapshotsForScope($this->project, $this->owner, 'SUBSYSTEM', $this->subsystemId())->count()));
    }

    // ------------------------------------------------------------------ systems

    public function test_a_system_dossier_is_about_every_line_of_every_subsystem_serving_it(): void
    {
        // The firefighting system in the fixture holds a subsystem in the
        // plant and one in the utilities block. No area or facility can say
        // that; the whole reason the level exists is that it can.
        $this->lineIn($this->subsystemId('PH-14-SS-4401'), 'PH-14-FF-A');
        $this->lineIn($this->subsystemId('PH-14-SS-4402'), 'PH-14-FF-B');

        $dossier = $this->compile('SYSTEM', $this->systemId('PH-14-SY-4400'));

        $this->assertSame('SYSTEM', $dossier['scope_kind']);
        $this->assertSame(['PH-14-FF-A', 'PH-14-FF-B'], collect($dossier['lines'])->pluck('line_number')->all());
        $this->assertEqualsCanonicalizing(['PH-14-PL-01', 'PH-14-PL-02'], $dossier['scope']['facility_codes']);
        $this->assertEqualsCanonicalizing(['PH-14-SS-4401', 'PH-14-SS-4402'], $dossier['scope']['subsystem_codes']);
    }

    public function test_a_system_dossier_does_not_reach_into_another_system(): void
    {
        $this->lineIn($this->subsystemId('PH-14-SS-4401'), 'PH-14-FF-A');
        $this->lineIn($this->subsystemId('PH-14-SS-2201'), 'PH-14-CW-A');

        $dossier = $this->compile('SYSTEM', $this->systemId('PH-14-SY-4400'));

        $this->assertSame(['PH-14-FF-A'], collect($dossier['lines'])->pluck('line_number')->all(),
            'the cooling water line serves another system');
    }

    public function test_a_system_dossier_sees_the_gaps_of_every_subsystem_it_gathers(): void
    {
        $this->lineIn($this->subsystemId('PH-14-SS-4401'), 'PH-14-FF-A');
        $this->lineIn($this->subsystemId('PH-14-SS-4402'), 'PH-14-FF-B');

        $completeness = $this->completenessFor('SYSTEM', $this->systemId('PH-14-SY-4400'));

        // Two lines, in two facilities, and neither has ever been inside a
        // pack: a system dossier must say so about both.
        $this->assertCount(2, $completeness['missing']['lines_without_test_pack']);
        $this->assertSame(2, $completeness['counts']['lines']);
    }

    public function test_a_system_dossier_can_be_frozen_and_carries_the_current_schema(): void
    {
        $this->lineIn($this->subsystemId('PH-14-SS-4401'));

        $snapshot = $this->asOwner(fn () => app(ItrCompilerService::class)->freeze(
            $this->project, $this->owner, $this->systemId('PH-14-SY-4400'), (string) Str::uuid(), 'en', 'SYSTEM'));

        $row = $this->asOwner(fn () => DB::table('itr_snapshots')->where('id', $snapshot['id'])->first());
        $this->assertSame('SYSTEM', (string) $row->scope_kind);
        $this->assertSame($this->systemId('PH-14-SY-4400'), (string) $row->scope_ref);
        $this->assertSame(6, json_decode((string) $row->dossier, true, 512, JSON_THROW_ON_ERROR)['schema_version']);
    }

    public function test_a_system_from_another_project_cannot_be_frozen(): void
    {
        $this->assertRefused(
            fn () => $this->asOwner(fn () => app(ItrCompilerService::class)->freeze(
                $this->project, $this->owner, (string) Str::uuid(), (string) Str::uuid(), 'en', 'SYSTEM')),
            'سیستم|system',
        );
    }

    public function test_the_screen_offers_the_system_scope_and_names_what_it_spans(): void
    {
        $this->lineIn($this->subsystemId('PH-14-SS-4401'), 'PH-14-FF-A');
        $systemId = $this->systemId('PH-14-SY-4400');

        $response = $this->actingAs($this->owner)->get($this->route(['sel' => 'SYS:'.$systemId]));

        $response->assertOk();
        $response->assertSee('value="SYS:'.$systemId.'"', false);
        $response->assertSee('id="scope-spans"', false);
        $response->assertSee('PH-14-SS-4401', false);
        $response->assertSee('PH-14-PL-02', false);
    }

    // --------------------------------------------------------- physical scopes

    public function test_an_area_dossier_is_about_every_line_of_every_subsystem_inside_it(): void
    {
        // AR-22 is the process area: the cooling water pump set and the
        // firefighting pump house both sit in it.
        $this->lineIn($this->subsystemId('PH-14-SS-2201'), 'PH-14-AR22-A');
        $this->lineIn($this->subsystemId('PH-14-SS-4402'), 'PH-14-AR22-B');

        $dossier = $this->compile('AREA', $this->areaId('AR-22'));

        $this->assertSame('AREA', $dossier['scope_kind']);
        $this->assertSame(['PH-14-AR22-A', 'PH-14-AR22-B'], collect($dossier['lines'])->pluck('line_number')->all());
        $this->assertEqualsCanonicalizing(['PH-14-SS-2201', 'PH-14-SS-4402'], $dossier['scope']['subsystem_codes']);
        $this->assertSame('PH-14-PL-01', $dossier['scope']['facility_code']);
    }

    public function test_an_area_dossier_does_not_reach_into_another_area(): void
    {
        $this->lineIn($this->subsystemId('PH-14-SS-2201'), 'PH-14-AR22-A');
        $this->lineIn($this->subsystemId('PH-14-SS-3301'), 'PH-14-AR33-A');

        $dossier = $this->compile('AREA', $this->areaId('AR-22'));

        $this->assertSame(['PH-14-AR22-A'], collect($dossier['lines'])->pluck('line_number')->all(),
            'the electrical area is another area’s business');
    }

    public function test_an_area_dossier_names_the_systems_that_reach_into_it(): void
    {
        // The reason these two rungs are worth having at all. AR-22 holds a
        // cooling water subsystem AND the firefighting pump house, and
        // firefighting also has a subsystem in the utilities block — so
        // firefighting is not this area’s system, it merely passes through it.
        // That is the question Advanced Work Packaging insists be answerable at
        // all times, and it is answered by a join over the same rows rather
        // than by a cross-reference somebody has to keep up to date by hand.
        $this->lineIn($this->subsystemId('PH-14-SS-2201'), 'PH-14-AR22-A');
        $this->lineIn($this->subsystemId('PH-14-SS-4402'), 'PH-14-AR22-B');

        $dossier = $this->compile('AREA', $this->areaId('AR-22'));

        $this->assertEqualsCanonicalizing(['PH-14-SY-2200', 'PH-14-SY-4400'], $dossier['scope']['system_codes']);
    }

    public function test_a_facility_dossier_gathers_the_subsystems_that_name_no_area(): void
    {
        // SS-5501 hangs straight off the plant with area_id null. It belongs in
        // the facility’s dossier and in no area’s, which is the honest answer:
        // it has no area. A facility scope that walked the areas instead would
        // silently lose it.
        $this->lineIn($this->subsystemId('PH-14-SS-5501'), 'PH-14-NOAREA-A');

        $facility = $this->compile('FACILITY', $this->facilityId('PH-14-PL-01'));
        $area = $this->compile('AREA', $this->areaId('AR-22'));

        $this->assertContains('PH-14-SS-5501', $facility['scope']['subsystem_codes']);
        $this->assertSame(['PH-14-NOAREA-A'], collect($facility['lines'])->pluck('line_number')->all());
        $this->assertNotContains('PH-14-SS-5501', $area['scope']['subsystem_codes']);
        $this->assertSame([], collect($area['lines'])->pluck('line_number')->all());
    }

    public function test_a_facility_without_any_area_still_has_a_dossier_of_all_of_itself(): void
    {
        // PH-14-PL-02 uses no areas at all. If the facility scope only walked
        // the areas, this facility’s dossier would quietly omit every part of
        // itself — the one thing a document about it must never do.
        $this->lineIn($this->subsystemId('PH-14-SS-4401'), 'PH-14-PL02-A');
        $this->lineIn($this->subsystemId('PH-14-SS-6601'), 'PH-14-PL02-B');

        $dossier = $this->compile('FACILITY', $this->facilityId('PH-14-PL-02'));

        $this->assertEqualsCanonicalizing(['PH-14-PL02-A', 'PH-14-PL02-B'], collect($dossier['lines'])->pluck('line_number')->all());
        $this->assertSame([], $dossier['scope']['area_codes']);
        $this->assertEqualsCanonicalizing(['PH-14-SY-4400', 'PH-14-SY-6600'], $dossier['scope']['system_codes']);
    }

    public function test_a_facility_dossier_names_the_areas_it_holds(): void
    {
        $dossier = $this->compile('FACILITY', $this->facilityId('PH-14-PL-01'));

        $this->assertSame(['AR-22', 'AR-33'], $dossier['scope']['area_codes']);
        $this->assertEqualsCanonicalizing(
            ['PH-14-SS-2201', 'PH-14-SS-3301', 'PH-14-SS-4402', 'PH-14-SS-5501'],
            $dossier['scope']['subsystem_codes'],
        );
    }

    public function test_a_physical_dossier_sees_the_gaps_of_every_subsystem_it_holds(): void
    {
        $this->lineIn($this->subsystemId('PH-14-SS-2201'), 'PH-14-AR22-A');
        $this->lineIn($this->subsystemId('PH-14-SS-4402'), 'PH-14-AR22-B');

        $completeness = $this->completenessFor('AREA', $this->areaId('AR-22'));

        // Two lines, both never inside a pack. The completeness report reads
        // the same resolver as the dossier, so it cannot report on a different
        // set of lines than the document it sits beside.
        $this->assertCount(2, $completeness['missing']['lines_without_test_pack']);
        $this->assertSame(2, $completeness['counts']['lines']);
    }

    public function test_both_physical_scopes_can_be_frozen_and_carry_the_current_schema(): void
    {
        $this->lineIn($this->subsystemId('PH-14-SS-2201'));

        foreach (['AREA' => $this->areaId('AR-22'), 'FACILITY' => $this->facilityId('PH-14-PL-01')] as $kind => $ref) {
            $snapshot = $this->asOwner(fn () => app(ItrCompilerService::class)->freeze(
                $this->project, $this->owner, $ref, (string) Str::uuid(), 'en', $kind));
            $row = $this->asOwner(fn () => DB::table('itr_snapshots')->where('id', $snapshot['id'])->first());
            $this->assertSame($kind, (string) $row->scope_kind);
            $this->assertNull($row->test_pack_id, 'a '.strtolower($kind).' dossier is not about one pack');
            $this->assertSame(6, json_decode((string) $row->dossier, true, 512, JSON_THROW_ON_ERROR)['schema_version']);
        }
    }

    public function test_an_area_from_another_project_cannot_be_frozen(): void
    {
        $this->assertRefused(
            fn () => $this->asOwner(fn () => app(ItrCompilerService::class)->freeze(
                $this->project, $this->owner, (string) Str::uuid(), (string) Str::uuid(), 'en', 'AREA')),
            'ناحیه|area',
        );
    }

    public function test_a_facility_from_another_project_cannot_be_frozen(): void
    {
        $this->assertRefused(
            fn () => $this->asOwner(fn () => app(ItrCompilerService::class)->freeze(
                $this->project, $this->owner, (string) Str::uuid(), (string) Str::uuid(), 'en', 'FACILITY')),
            'واحد|facility',
        );
    }

    public function test_the_screen_offers_both_physical_scopes_and_names_what_they_hold(): void
    {
        $this->lineIn($this->subsystemId('PH-14-SS-2201'), 'PH-14-AR22-A');
        $this->lineIn($this->subsystemId('PH-14-SS-4402'), 'PH-14-AR22-B');

        $areaId = $this->areaId('AR-22');
        $this->actingAs($this->owner)->get($this->route(['sel' => 'AREA:'.$areaId]))
            ->assertOk()
            ->assertSee('value="AREA:'.$areaId.'"', false)
            ->assertSee('id="scope-spans"', false)
            ->assertSee('PH-14-SS-4402', false)
            // The cross-reference, printed where the area-based reader will
            // see it — and in START-UP order, not alphabetical: firefighting
            // (P1) before cooling water (P3), even though it reaches in from
            // another facility.
            ->assertSee('Systems: PH-14-SY-4400, PH-14-SY-2200', false);

        $facilityId = $this->facilityId('PH-14-PL-02');
        $this->actingAs($this->owner)->get($this->route(['sel' => 'FAC:'.$facilityId]))
            ->assertOk()
            ->assertSee('value="FAC:'.$facilityId.'"', false)
            ->assertSee('id="scope-spans"', false)
            ->assertSee('PH-14-SS-6601', false);
    }

    public function test_a_system_dossier_carries_the_priority_it_was_compiled_under(): void
    {
        // A handover document that does not say where this system stands in the
        // start-up order leaves operations to guess at it. Firefighting is P1 in
        // the fixture: nothing starts before it.
        $this->lineIn($this->subsystemId('PH-14-SS-4401'), 'PH-14-FF-A');

        $dossier = $this->compile('SYSTEM', $this->systemId('PH-14-SY-4400'));

        $this->assertSame(1, $dossier['scope']['startup_priority']);
    }

    public function test_an_area_dossier_lists_the_systems_touching_it_in_start_up_order(): void
    {
        // The payoff of having both a sequence and a cross-reference. Process
        // area AR-22 holds the cooling water pump set (P3) and the firefighting
        // pump house (P1). Alphabetically the list would begin with cooling
        // water; in start-up order it begins with firefighting, and that is the
        // order the reader needs — power and safety before utilities, every
        // time, in every project.
        $this->lineIn($this->subsystemId('PH-14-SS-2201'), 'PH-14-AR22-A');
        $this->lineIn($this->subsystemId('PH-14-SS-4402'), 'PH-14-AR22-B');

        $dossier = $this->compile('AREA', $this->areaId('AR-22'));

        $this->assertSame(['PH-14-SY-4400', 'PH-14-SY-2200'], $dossier['scope']['system_codes'],
            'the systems are listed in the order commissioning needs them, not alphabetically');
    }

    // ---------------------------------------------------------------- plumbing

    private function itr(): ItrCompilerService
    {
        return app(ItrCompilerService::class);
    }

    /** @return array<string, mixed> */
    private function compile(string $kind, ?string $ref): array
    {
        return $this->asOwner(fn () => $this->itr()->compile($this->project, $this->owner, $ref, 'en', $kind))['dossier'];
    }

    /** @return array<string, mixed> */
    private function completenessFor(string $kind, ?string $ref): array
    {
        return $this->asOwner(fn () => $this->itr()->compile($this->project, $this->owner, $ref, 'en', $kind))['completeness'];
    }

    /**
     * Two lines in one subsystem, plus a second empty subsystem.
     *
     * @return array{0: string, 1: string} the populated subsystem, and another
     */
    private function twoLinesInOneSubsystem(): array
    {
        $first = $this->subsystemId();
        $this->lineIn($first, 'PH-14-SCOPE-A');
        $this->lineIn($first, 'PH-14-SCOPE-B');

        return [$first, $this->anotherSubsystemId()];
    }

    // RLS is on: a scope id is only an id for a session that can see the row.
    private function subsystemId(?string $code = null): string
    {
        return (string) $this->asOwner(fn () => DB::table('subsystems')
            ->when($code !== null, fn ($q) => $q->where('code', $code))
            ->where('project_id', $this->project->id)->orderBy('code')->value('id'));
    }

    private function systemId(string $code): string
    {
        return (string) $this->asOwner(fn () => DB::table('systems')
            ->where('project_id', $this->project->id)->where('code', $code)->value('id'));
    }

    private function areaId(string $code): string
    {
        return (string) $this->asOwner(fn () => DB::table('areas')
            ->where('project_id', $this->project->id)->where('code', $code)->value('id'));
    }

    private function facilityId(string $code): string
    {
        return (string) $this->asOwner(fn () => DB::table('facilities')
            ->where('project_id', $this->project->id)->where('code', $code)->value('id'));
    }

    private function anotherSubsystemId(): string
    {
        return (string) $this->asOwner(fn () => DB::table('subsystems')
            ->where('project_id', $this->project->id)->orderByDesc('code')->value('id'));
    }

    private function lineIn(string $subsystemId, ?string $number = null): PipingLine
    {
        $line = QualityResultFixture::line($this->tenant, $this->project, $this->owner);

        // The fixture creates its line in the demo subsystem; a scope test
        // needs to place lines deliberately, and the subsystem of a line is a
        // design fact rather than a claim, so it is set directly.
        $this->asOwner(function () use ($line, $subsystemId, $number): void {
            $update = ['subsystem_id' => $subsystemId];
            if ($number !== null) {
                $update['line_number'] = $number;
            }
            DB::table('piping_lines')->where('id', $line->id)->update($update);
        });

        // Released, because a dossier's first gap is an unreleased line and
        // these tests are about scope, not about release. The AFC provenance
        // the CHECK demands is written too: stepping around a guard is not
        // what a fixture is for.
        $this->asOwner(function () use ($line): void {
            DB::table('piping_lines')->where('id', $line->id)->update([
                'afc_confirmed_at' => now(), 'afc_confirmed_by' => $this->owner->id,
                'release_state' => 'RELEASED', 'released_at' => now(), 'released_by' => $this->owner->id,
            ]);
        });

        return $line;
    }

    private function readyPack(string $code, array $lines): PipingTestPack
    {
        $pack = $this->asOwner(fn () => app(PipingTestPackService::class)
            ->create($this->project, $this->owner, ['code' => $code]));
        foreach ($lines as $line) {
            $this->asOwner(fn () => app(PipingTestPackService::class)->assignLine($line, $this->owner, $pack));
        }
        $this->asOwner(function () use ($pack): void {
            DB::table('piping_test_packs')->where('id', $pack->id)
                ->update(['state' => 'READY', 'ready_at' => now(), 'ready_by' => $this->owner->id]);
        });

        return $this->asOwner(fn () => PipingTestPack::whereKey($pack->id)->firstOrFail());
    }

    private function passResult(PipingTestPack $pack): void
    {
        $this->nominate('record_pack_result');
        $this->asOwner(fn () => app(InspectionResultService::class)->recordTestResult(
            $this->project, $this->owner, (string) $pack->id,
            ['test_type' => 'HYDROSTATIC', 'medium' => 'WATER', 'test_pressure' => '21.750',
             'pressure_unit' => 'BAR', 'hold_duration_minutes' => 120,
             'started_at' => '2026-10-28T06:00:00+03:30', 'completed_at' => '2026-10-28T09:30:00+03:30',
             'result' => 'PASS', 'request_id' => (string) Str::uuid()], 'en'));
    }

    private function nominate(string $action): void
    {
        app(\App\Services\ResultRecorderPolicyService::class)->configure($this->project, $this->owner, [
            'version' => 0,
            'target_company_id' => $this->owner->company_id,
            'action' => $action,
            'selectors' => ['user:'.$this->owner->id],
            'suspend' => false,
            'reason' => 'ITR scope fixture nomination',
        ], 'en');
    }

    private function assertRefused(callable $fn, string $needle): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->implode(' ');
            $this->assertMatchesRegularExpression('/'.$needle.'/i', $message);

            return;
        }

        $this->fail('The scope was accepted; it should have been refused.');
    }

    private function asOwner(callable $fn): mixed
    {
        return $this->tenant->runAs($this->owner->company_id, $fn, $this->project->id);
    }

    /** @param  array<string,mixed>  $query */
    private function route(array $query = []): string
    {
        return route('quality.itr', array_replace(['projectCode' => 'PH-14', 'lang' => 'en'], $query));
    }
}
