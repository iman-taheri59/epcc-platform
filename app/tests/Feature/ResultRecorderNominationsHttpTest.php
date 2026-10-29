<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\ResultRecorderPolicyService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-048 A.4g slice 1 — the nomination register gets a surface.
 *
 * Recording inspection evidence has been default-deny since A.4b, and that is
 * right. But the register that grants the authority could only ever be written
 * by a test fixture inserting rows directly into `result_recorder_policies` —
 * so in production the whole quality stack was switched off at the wall, with
 * the only key being SQL.
 *
 * These tests nominate the way a project actually will: through HTTP. Nothing
 * here touches the table directly except to count rows afterwards.
 */
final class ResultRecorderNominationsHttpTest extends TestCase
{
    private User $owner;

    private User $buyer;

    private User $qc;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->buyer = $this->userByEmail('rezaei@pars.example');
        $this->qc = $this->userByEmail('tehrani@pmc.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        // The routes default to Persian; make the in-test __() calls agree.
        app()->setLocale('fa');
    }

    // ------------------------------------------------------------------ surface

    public function test_a_project_member_sees_the_register_and_that_nothing_is_nominated_yet(): void
    {
        $response = $this->actingAs($this->buyer)->get($this->route());

        $response->assertOk();
        $response->assertSee(__('quality_nominations.title'), false);
        $response->assertSee('id="register-empty"', false);
        $response->assertSee('id="nominated-record_weld"', false);
        $response->assertSee(__('quality_nominations.not_nominated'), false);
    }

    public function test_the_screen_switches_to_english(): void
    {
        $response = $this->actingAs($this->buyer)->get($this->route('en'));

        $response->assertOk();
        $response->assertSee('Inspection result nominations', false);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get($this->route())->assertRedirect(route('login'));
    }

    public function test_someone_who_is_not_a_project_member_does_not_get_the_screen(): void
    {
        $outsider = $this->userByEmail('hosseini@arvand.example');

        $this->actingAs($outsider)->get($this->route())->assertForbidden();
    }

    // ---------------------------------------------------------------- nominate

    public function test_a_company_nominates_its_own_people_and_then_holds_the_authority(): void
    {
        $before = $this->rows();

        $response = $this->actingAs($this->buyer)->post($this->storeRoute(), [
            'policy' => [
                'version' => 0,
                'target_company_id' => $this->buyer->company_id,
                'action' => 'record_weld',
                'selectors' => ['user:'.$this->buyer->id],
                'suspend' => '0',
                'reason' => 'Weld maps are recorded by the fabrication QC.',
            ],
        ]);

        $response->assertRedirect($this->route());
        $this->assertSame($before + 1, $this->rows());

        $policy = $this->policyRow($this->buyer->company_id, 'record_weld');

        $this->assertSame(1, (int) $policy->version);
        $this->assertFalse((bool) $policy->suspended);
        $this->assertSame($this->buyer->id, $policy->created_by);
        $this->assertSame($this->buyer->company_id, $policy->company_id);

        // The guard's own question, not ours: is this actor nominated?
        $this->assertSame((string) $policy->id, $this->nominationFor($this->buyer, 'record_weld'));
        $this->assertNull($this->nominationFor($this->buyer, 'record_ndt'), 'one action is not another');
    }

    public function test_nominating_again_writes_a_new_version_and_keeps_the_old_one(): void
    {
        $this->nominate($this->buyer, $this->buyer->company_id, 'record_weld', ['user:'.$this->buyer->id]);

        $response = $this->actingAs($this->buyer)->post($this->storeRoute(), [
            'policy' => [
                'version' => 1,
                'target_company_id' => $this->buyer->company_id,
                'action' => 'record_weld',
                'selectors' => ['user:'.$this->qc->id],
                'suspend' => '0',
                'reason' => 'Handing weld recording to the PMC inspector.',
            ],
        ]);

        $response->assertRedirect();

        $this->assertSame([1, 2], $this->versionsFor($this->buyer->company_id, 'record_weld'), 'the superseded nomination stays; nothing is edited');
        $this->assertNull($this->nominationFor($this->buyer, 'record_weld'), 'the new version names someone else');
    }

    public function test_the_employer_may_nominate_another_company(): void
    {
        $response = $this->actingAs($this->owner)->post($this->storeRoute(), [
            'policy' => [
                'version' => 0,
                'target_company_id' => $this->qc->company_id,
                'action' => 'record_ndt',
                'selectors' => ['user:'.$this->qc->id],
                'suspend' => '0',
                'reason' => 'NDT is witnessed by the PMC.',
            ],
        ]);

        $response->assertRedirect();
        $this->assertNotNull($this->nominationFor($this->qc, 'record_ndt'));
    }

    public function test_a_company_cannot_nominate_another_company(): void
    {
        $before = $this->rows();

        $response = $this->actingAs($this->buyer)->post($this->storeRoute(), [
            'policy' => [
                'version' => 0,
                'target_company_id' => $this->qc->company_id,
                'action' => 'record_ndt',
                'selectors' => ['user:'.$this->qc->id],
                'suspend' => '0',
                'reason' => 'Trying to nominate someone else.',
            ],
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors();
        $this->assertSame($before, $this->rows(), 'nothing was written');
        $this->assertNull($this->nominationFor($this->qc, 'record_ndt'));
    }

    public function test_a_nomination_must_name_someone(): void
    {
        $before = $this->rows();

        $response = $this->actingAs($this->buyer)->post($this->storeRoute(), [
            'policy' => [
                'version' => 0,
                'target_company_id' => $this->buyer->company_id,
                'action' => 'record_weld',
                'selectors' => [],
                'suspend' => '0',
                'reason' => 'Nominating nobody in particular.',
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame($before, $this->rows());
    }

    public function test_a_nomination_must_name_someone_the_project_actually_has(): void
    {
        $before = $this->rows();

        $response = $this->actingAs($this->buyer)->post($this->storeRoute(), [
            'policy' => [
                'version' => 0,
                'target_company_id' => $this->buyer->company_id,
                'action' => 'record_weld',
                'selectors' => ['user:'.$this->userByEmail('hosseini@arvand.example')->id],
                'suspend' => '0',
                'reason' => 'Nominating someone outside the project.',
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame($before, $this->rows());
    }

    public function test_a_reason_is_required(): void
    {
        $before = $this->rows();

        $response = $this->actingAs($this->buyer)->post($this->storeRoute(), [
            'policy' => [
                'version' => 0,
                'target_company_id' => $this->buyer->company_id,
                'action' => 'record_weld',
                'selectors' => ['user:'.$this->buyer->id],
                'suspend' => '0',
                'reason' => '   ',
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame($before, $this->rows());
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $before = $this->rows();

        $response = $this->actingAs($this->buyer)->post($this->storeRoute(), [
            'policy' => [
                'version' => 0,
                'target_company_id' => $this->buyer->company_id,
                'action' => 'approve_everything',
                'selectors' => ['user:'.$this->buyer->id],
                'suspend' => '0',
                'reason' => 'A action that does not exist.',
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame($before, $this->rows());
    }

    public function test_a_stale_version_is_refused_rather_than_overwriting_a_newer_nomination(): void
    {
        $this->nominate($this->buyer, $this->buyer->company_id, 'record_weld', ['user:'.$this->buyer->id]);
        $before = $this->rows();

        $response = $this->actingAs($this->buyer)->post($this->storeRoute(), [
            'policy' => [
                'version' => 0, // the page they were looking at, before version 1 existed
                'target_company_id' => $this->buyer->company_id,
                'action' => 'record_weld',
                'selectors' => ['user:'.$this->buyer->id],
                'suspend' => '0',
                'reason' => 'A second tab, working from a stale page.',
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame($before, $this->rows(), 'the newer nomination was not overwritten');
    }

    // ----------------------------------------------------------------- suspend

    public function test_suspending_writes_a_new_version_and_the_authority_is_gone(): void
    {
        $this->nominate($this->buyer, $this->buyer->company_id, 'record_weld', ['user:'.$this->buyer->id]);
        $before = $this->rows();

        $response = $this->actingAs($this->buyer)->post($this->storeRoute(), [
            'policy' => [
                'version' => 1,
                'target_company_id' => $this->buyer->company_id,
                'action' => 'record_weld',
                'suspend' => '1',
                'reason' => 'The fabrication QC left the site.',
            ],
        ]);

        $response->assertRedirect();
        $this->assertSame($before + 1, $this->rows(), 'suspension is a version, not a delete or an edit');
        $this->assertNull($this->nominationFor($this->buyer, 'record_weld'), 'the authority is withdrawn from here on');

        $this->assertSame([1, 2], $this->versionsFor($this->buyer->company_id, 'record_weld'));
    }

    public function test_suspending_still_needs_a_reason(): void
    {
        $this->nominate($this->buyer, $this->buyer->company_id, 'record_weld', ['user:'.$this->buyer->id]);
        $before = $this->rows();

        $response = $this->actingAs($this->buyer)->post($this->storeRoute(), [
            'policy' => [
                'version' => 1,
                'target_company_id' => $this->buyer->company_id,
                'action' => 'record_weld',
                'suspend' => '1',
                'reason' => '',
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame($before, $this->rows());
    }

    // ------------------------------------------------------------------ helpers

    private function nominate(User $actor, string $targetCompanyId, string $action, array $selectors): void
    {
        app(ResultRecorderPolicyService::class)->configure($this->project, $actor, [
            'version' => (int) (DB::table('result_recorder_policies')->where('project_id', $this->project->id)
                ->where('target_company_id', $targetCompanyId)->where('action', $action)->max('version') ?? 0),
            'target_company_id' => $targetCompanyId,
            'action' => $action,
            'selectors' => $selectors,
            'suspend' => '0',
            'reason' => 'Fixture nomination',
        ], 'en');
    }

    /**
     * Rows are counted inside the session contract: the table is RLS+FORCE, so
     * a bare count outside it returns zero and would prove nothing.
     */
    private function rows(): int
    {
        return (int) app(TenantContext::class)->runAs(
            $this->buyer->company_id,
            fn (): int => DB::table('result_recorder_policies')->count(),
            $this->project->id
        );
    }

    /** @return list<int> */
    private function versionsFor(string $companyId, string $action): array
    {
        return array_map('intval', app(TenantContext::class)->runAs(
            $this->buyer->company_id,
            fn (): array => DB::table('result_recorder_policies')
                ->where('project_id', $this->project->id)
                ->where('target_company_id', $companyId)
                ->where('action', $action)->orderBy('version')->pluck('version')->all(),
            $this->project->id
        ));
    }

    private function policyRow(string $companyId, string $action): ?object
    {
        return app(TenantContext::class)->runAs(
            $this->buyer->company_id,
            fn (): ?object => DB::table('result_recorder_policies')
                ->where('project_id', $this->project->id)
                ->where('target_company_id', $companyId)
                ->where('action', $action)->orderByDesc('version')->first(),
            $this->project->id
        );
    }

    /** The guard's own question, asked inside the session contract. */
    private function nominationFor(User $actor, string $action): ?string
    {
        return app(TenantContext::class)->runAs(
            $actor->company_id,
            fn (): ?string => app(ResultRecorderPolicyService::class)->nominationFor($this->project, $actor, $action),
            $this->project->id
        );
    }

    private function route(string $lang = 'fa'): string
    {
        return route('quality.nominations', ['projectCode' => $this->project->code, 'lang' => $lang]);
    }

    private function storeRoute(string $lang = 'fa'): string
    {
        return route('quality.nominations.store', ['projectCode' => $this->project->code, 'lang' => $lang]);
    }


}
