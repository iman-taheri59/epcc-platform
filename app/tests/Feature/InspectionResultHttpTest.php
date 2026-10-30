<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Joint;
use App\Models\PipingTestPack;
use App\Models\Project;
use App\Models\User;
use App\Services\ResultRecorderPolicyService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QualityResultFixture;
use Tests\TestCase;

/**
 * ADR-048 A.4h slice 1 — recording a result, through the screen.
 *
 * The services have existed since A.4a and the guards have refused everything
 * since A.4b, but until A.4g there was no way to BE nominated, so nothing
 * could be recorded at all. This screen is what that unlocks, and the one
 * property that matters most is that it is honest up front: a form you are not
 * nominated for is not offered at all, rather than offered and then refused.
 */
final class InspectionResultHttpTest extends TestCase
{
    private User $owner;

    private User $pm;

    private User $qc;

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
        $this->pm = $this->userByEmail('rezaei@pars.example');
        $this->qc = $this->userByEmail('sadeghi@pars.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        app()->setLocale('fa');
    }

    // ------------------------------------------------------------------ surface

    public function test_the_screen_lists_the_joints_and_says_nothing_is_nominated(): void
    {
        $joint = $this->fittedJoint();

        $response = $this->actingAs($this->qc)->get($this->route(['joint' => $joint->id]));

        $response->assertOk();
        $response->assertSee(__('quality_results.title'), false);
        // The row must name the joint AND the line it sits on, or an inspector
        // cannot tell which of forty identical joints this one is.
        $response->assertSee($joint->displayNumber() ?? $joint->id, false);
        $response->assertSee($this->lineNumberOf($joint), false);
        $response->assertSee('id="weld-denied"', false);
        $response->assertSee('id="ndt-denied"', false);
        $response->assertSee(__('quality_results.not_nominated'), false);
    }

    public function test_the_form_appears_only_once_you_are_nominated(): void
    {
        $joint = $this->fittedJoint();

        $this->actingAs($this->qc)->get($this->route(['joint' => $joint->id]))
            ->assertSee('id="weld-denied"', false);

        $this->nominate($this->qc, 'record_weld');

        $this->actingAs($this->qc)->get($this->route(['joint' => $joint->id]))
            ->assertSee('id="weld-form"', false)
            ->assertDontSee('id="weld-denied"', false)
            ->assertSee('id="ndt-denied"', false); // one action is not another
    }

    public function test_the_screen_switches_to_english(): void
    {
        $this->actingAs($this->qc)->get($this->route([], 'en'))
            ->assertOk()->assertSee('Record inspection and test results', false);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get($this->route())->assertRedirect(route('login'));
    }

    public function test_someone_outside_the_project_does_not_get_the_screen(): void
    {
        $outsider = $this->userByEmail('hosseini@arvand.example');

        $this->actingAs($outsider)->get($this->route())->assertForbidden();
    }

    // -------------------------------------------------------------------- weld

    public function test_a_nominated_inspector_records_a_weld_and_it_appears_in_the_history(): void
    {
        $joint = $this->fittedJoint();
        $this->nominate($this->qc, 'record_weld');

        $response = $this->actingAs($this->qc)->post($this->route(['joint' => $joint->id], 'fa', 'weld'), [
            'joint_id' => $joint->id,
            'weld' => $this->weld(),
        ]);

        $response->assertRedirect($this->route(['joint' => $joint->id]));
        $this->assertSame(1, $this->rows('joint_weld_records'));

        $this->actingAs($this->qc)->get($this->route(['joint' => $joint->id]))
            ->assertSee('W-77', false)
            ->assertSee('id="weld-history"', false);
    }

    public function test_recording_a_weld_without_a_nomination_writes_nothing(): void
    {
        $joint = $this->fittedJoint();

        $response = $this->actingAs($this->qc)->post($this->route(['joint' => $joint->id], 'fa', 'weld'), [
            'joint_id' => $joint->id,
            'weld' => $this->weld(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows('joint_weld_records'), 'default-deny held');
    }

    public function test_a_repair_weld_without_a_reason_is_refused(): void
    {
        $joint = $this->fittedJoint();
        $this->nominate($this->qc, 'record_weld');

        $response = $this->actingAs($this->qc)->post($this->route(['joint' => $joint->id], 'fa', 'weld'), [
            'joint_id' => $joint->id,
            'weld' => $this->weld(['kind' => 'REPAIR_WELD', 'reason' => '']),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows('joint_weld_records'));
    }

    public function test_the_same_request_id_is_not_recorded_twice(): void
    {
        $joint = $this->fittedJoint();
        $this->nominate($this->qc, 'record_weld');
        $payload = $this->weld();

        $this->actingAs($this->qc)->post($this->route(['joint' => $joint->id], 'fa', 'weld'), [
            'joint_id' => $joint->id, 'weld' => $payload,
        ])->assertRedirect();

        $this->actingAs($this->qc)->post($this->route(['joint' => $joint->id], 'fa', 'weld'), [
            'joint_id' => $joint->id, 'weld' => $payload,
        ])->assertSessionHasErrors();

        $this->assertSame(1, $this->rows('joint_weld_records'), 'a resubmission did not duplicate the record');
    }

    // --------------------------------------------------------------------- ndt

    public function test_an_examination_is_refused_until_the_joint_has_a_weld(): void
    {
        $joint = $this->fittedJoint();
        $this->nominate($this->qc, 'record_ndt');

        $response = $this->actingAs($this->qc)->post($this->route(['joint' => $joint->id], 'fa', 'ndt'), [
            'joint_id' => $joint->id,
            'ndt' => $this->ndt(),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows('joint_ndt_results'), 'an NDT result with nothing to examine was refused');
    }

    public function test_a_rejected_ndt_result_must_describe_the_defect(): void
    {
        $joint = $this->fittedJoint();
        $this->nominate($this->qc, 'record_weld');
        $this->nominate($this->qc, 'record_ndt');

        $this->actingAs($this->qc)->post($this->route(['joint' => $joint->id], 'fa', 'weld'), [
            'joint_id' => $joint->id,
            'weld' => $this->weld(),
        ])->assertRedirect();

        $response = $this->actingAs($this->qc)->post($this->route(['joint' => $joint->id], 'fa', 'ndt'), [
            'joint_id' => $joint->id,
            'ndt' => $this->ndt(['result' => 'REJECTED', 'reject_reason' => '']),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows('joint_ndt_results'));

        $this->actingAs($this->qc)->post($this->route(['joint' => $joint->id], 'fa', 'ndt'), [
            'joint_id' => $joint->id,
            'ndt' => $this->ndt(['result' => 'REJECTED', 'reject_reason' => 'Slag inclusion at the root']),
        ])->assertRedirect();

        $this->assertSame(1, $this->rows('joint_ndt_results'));
    }

    // --------------------------------------------------------------- test pack

    public function test_a_hydrostatic_result_is_recorded_against_a_ready_pack(): void
    {
        $pack = $this->readyPack();
        $this->nominate($this->qc, 'record_pack_result');

        $this->actingAs($this->qc)->post($this->route(['pack' => $pack->id], 'fa', 'pack'), [
            'test_pack_id' => $pack->id,
            'pack' => $this->pack(),
        ])->assertRedirect();

        $this->assertSame(1, $this->rows('test_pack_results'));

        $this->actingAs($this->qc)->get($this->route(['pack' => $pack->id]))
            ->assertSee('id="pack-results"', false)
            ->assertSee('HYDROSTATIC', false);
    }

    public function test_a_temperature_cannot_be_recorded_as_a_pressure(): void
    {
        $pack = $this->readyPack();
        $this->nominate($this->qc, 'record_pack_result');

        $response = $this->actingAs($this->qc)->post($this->route(['pack' => $pack->id], 'fa', 'pack'), [
            'test_pack_id' => $pack->id,
            'pack' => $this->pack(['pressure_unit' => 'DEG_C']),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows('test_pack_results'));
    }

    public function test_a_passed_result_carries_no_failure_cause(): void
    {
        $pack = $this->readyPack();
        $this->nominate($this->qc, 'record_pack_result');

        $response = $this->actingAs($this->qc)->post($this->route(['pack' => $pack->id], 'fa', 'pack'), [
            'test_pack_id' => $pack->id,
            'pack' => $this->pack(['result' => 'PASS', 'failure_reason' => 'It leaked a bit']),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows('test_pack_results'));
    }

    public function test_a_test_cannot_complete_before_it_starts(): void
    {
        $pack = $this->readyPack();
        $this->nominate($this->qc, 'record_pack_result');

        $response = $this->actingAs($this->qc)->post($this->route(['pack' => $pack->id], 'fa', 'pack'), [
            'test_pack_id' => $pack->id,
            'pack' => $this->pack(['started_at' => '2026-10-29T10:00', 'completed_at' => '2026-10-29T09:00']),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows('test_pack_results'));
    }

    // ------------------------------------------------------------------ helpers

    private function nominate(User $actor, string $action): void
    {
        app(ResultRecorderPolicyService::class)->configure($this->project, $actor, [
            'version' => 0,
            'target_company_id' => $actor->company_id,
            'action' => $action,
            'selectors' => ['user:'.$actor->id],
            'suspend' => false,
            'reason' => 'Fixture nomination for '.$action,
        ], 'en');
    }

    /** @param  array<string,mixed>  $overrides */
    private function weld(array $overrides = []): array
    {
        return array_replace([
            'kind' => 'WELD',
            'welded_by_code' => 'W-77',
            'welded_at' => '2026-10-29T08:30',
            'mode' => 'IN_SYSTEM',
            'request_id' => (string) Str::uuid(),
        ], $overrides);
    }

    /** @param  array<string,mixed>  $overrides */
    private function ndt(array $overrides = []): array
    {
        return array_replace([
            'method' => 'RT',
            'result' => 'ACCEPTED',
            'performed_by_code' => 'NDT-12',
            'performed_at' => '2026-10-29T09:30',
            'mode' => 'IN_SYSTEM',
            'request_id' => (string) Str::uuid(),
        ], $overrides);
    }

    /** @param  array<string,mixed>  $overrides */
    private function pack(array $overrides = []): array
    {
        return array_replace([
            'test_type' => 'HYDROSTATIC',
            'result' => 'PASS',
            'medium' => 'WATER',
            'test_pressure' => '21.500',
            'pressure_unit' => 'BAR',
            'hold_duration_minutes' => '30',
            'started_at' => '2026-10-29T10:00',
            'completed_at' => '2026-10-29T11:00',
            'mode' => 'IN_SYSTEM',
            'request_id' => (string) Str::uuid(),
        ], $overrides);
    }

    private function fittedJoint(): Joint
    {
        return QualityResultFixture::fittedJoint($this->tenant, $this->project, $this->owner);
    }

    private function readyPack(): PipingTestPack
    {
        return QualityResultFixture::readyPack($this->tenant, $this->project, $this->owner);
    }

    /**
     * Rows counted inside the session contract: these tables are RLS+FORCE, so
     * a bare count outside it returns zero and would prove nothing.
     */
    private function rows(string $table): int
    {
        return (int) $this->tenant->runAs(
            $this->qc->company_id,
            fn (): int => DB::table($table)->count(),
            $this->project->id
        );
    }

    private function lineNumberOf(Joint $joint): string
    {
        return (string) $this->tenant->runAs(
            $this->qc->company_id,
            fn () => (string) $joint->refresh()->line?->line_number,
            $this->project->id
        );
    }

    /** @param  array<string,mixed>  $query */
    private function route(array $query = [], string $lang = 'fa', string $action = ''): string
    {
        return route('quality.results'.($action === '' ? '' : '.'.$action), [
            'projectCode' => $this->project->code,
            'lang' => $lang,
        ] + $query);
    }
}
