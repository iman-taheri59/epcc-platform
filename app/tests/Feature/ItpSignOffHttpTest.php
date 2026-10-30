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
use Illuminate\Support\Str;
use Tests\Support\QualitySignOffFixture;
use Tests\TestCase;

/**
 * ADR-048 A.4h slice 2 — signing an ITP stage, through a screen.
 *
 * The service has existed since A.4d and the guard has refused everything but
 * a nominated signer since then; what was missing was the same thing slice 1
 * fixed for results: a way to do it without SQL.
 *
 * Two refusals are built into the screen rather than left for the submit — a
 * non-nominated actor gets no form, and a contractor is not offered the waiver
 * on a HOLD point — because both would otherwise be the inspector filling in a
 * form to be told afterwards that the answer was always no.
 */
final class ItpSignOffHttpTest extends TestCase
{
    private User $employer;

    private User $writer;

    private User $recipient;

    private User $consultant;

    private Project $project;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->tenant = app(TenantContext::class);
        $this->employer = $this->userByEmail('karimi@nioc.example');
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $this->recipient = $this->userByEmail('jafari@karan.example');
        $this->consultant = $this->userByEmail('tehrani@pmc.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        app()->setLocale('fa');
    }

    // ------------------------------------------------------------------ surface

    public function test_the_worklist_shows_the_notice_addressed_to_your_company(): void
    {
        $id = $this->issue('W', [$this->recipient->company_id]);

        $response = $this->actingAs($this->recipient)->get($this->route(['notice' => $id]));

        $response->assertOk();
        $response->assertSee(__('quality_signoffs.title'), false);
        $response->assertSee('FIXTURE WORK', false);
        $response->assertSee('id="signoff-denied"', false);
        $response->assertSee(__('quality_signoffs.not_nominated'), false);
    }

    public function test_a_notice_you_were_never_sent_is_not_yours_to_sign(): void
    {
        $id = $this->issue('W', [$this->recipient->company_id]);

        // The consultant is on the project and was not notified, so the notice
        // is invisible to him — RLS says so, and the screen simply agrees.
        $this->actingAs($this->consultant)->get($this->route(['notice' => $id]))
            ->assertOk()
            ->assertSee('id="no-notices"', false)
            ->assertDontSee('FIXTURE WORK', false);
    }

    public function test_the_form_appears_only_once_you_are_nominated(): void
    {
        $id = $this->issue('W', [$this->recipient->company_id]);

        $this->actingAs($this->recipient)->get($this->route(['notice' => $id]))
            ->assertSee('id="signoff-denied"', false);

        $this->nominate($this->recipient);

        $this->actingAs($this->recipient)->get($this->route(['notice' => $id]))
            ->assertSee('id="signoff-form"', false)
            ->assertDontSee('id="signoff-denied"', false);
    }

    public function test_the_screen_switches_to_english(): void
    {
        $this->actingAs($this->recipient)->get($this->route([], 'en'))
            ->assertOk()->assertSee('ITP stage sign-offs', false);
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

    // ------------------------------------------------------------------ signing

    public function test_a_nominated_signer_signs_and_the_point_is_derived(): void
    {
        $id = $this->issue('W', [$this->recipient->company_id]);
        $this->nominate($this->recipient);

        $this->actingAs($this->recipient)->post($this->route([], 'fa', 'store'), [
            'notification_id' => $id,
            'sign_off' => $this->payload(),
        ])->assertRedirect();

        $this->assertSame(1, $this->rows());
        $this->assertSame('W', $this->pointOf($id));

        $this->actingAs($this->recipient)->get($this->route(['notice' => $id]))
            ->assertSee('id="signoff-history"', false)
            ->assertSee('ACCEPTED', false);
    }

    public function test_signing_without_a_nomination_writes_nothing(): void
    {
        $id = $this->issue('W', [$this->recipient->company_id]);

        $response = $this->actingAs($this->recipient)->post($this->route([], 'fa', 'store'), [
            'notification_id' => $id,
            'sign_off' => $this->payload(),
        ]);

        $response->assertRedirect();
        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows(), 'default-deny held');
    }

    public function test_a_rejection_without_a_cause_is_refused(): void
    {
        $id = $this->issue('W', [$this->recipient->company_id]);
        $this->nominate($this->recipient);

        $response = $this->actingAs($this->recipient)->post($this->route([], 'fa', 'store'), [
            'notification_id' => $id,
            'sign_off' => $this->payload(['outcome' => 'REJECTED', 'reason' => '']),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows());
    }

    public function test_a_correction_chains_to_the_rejection_it_replaces(): void
    {
        $id = $this->issue('W', [$this->recipient->company_id]);
        $this->nominate($this->recipient);

        $this->actingAs($this->recipient)->post($this->route([], 'fa', 'store'), [
            'notification_id' => $id,
            'sign_off' => $this->payload(['outcome' => 'REJECTED', 'reason' => 'Root gap on the second pass']),
        ])->assertRedirect();

        $rejected = $this->signOffRows($id)->firstWhere('outcome', 'REJECTED');

        // The correction is a NEW chained sign-off, never an edit of the old one.
        $this->actingAs($this->recipient)->post($this->route([], 'fa', 'store'), [
            'notification_id' => $id,
            'sign_off' => $this->payload(['previous_signoff_id' => $rejected->id]),
        ])->assertRedirect();

        $this->assertSame(2, $this->rows());
        $this->assertSame($rejected->id, (string) $this->signOffRows($id)->last()->previous_signoff_id);
    }

    public function test_the_same_request_id_is_not_signed_twice(): void
    {
        $id = $this->issue('W', [$this->recipient->company_id]);
        $this->nominate($this->recipient);
        $payload = $this->payload();

        $this->actingAs($this->recipient)->post($this->route([], 'fa', 'store'), [
            'notification_id' => $id, 'sign_off' => $payload,
        ])->assertRedirect();

        $this->actingAs($this->recipient)->post($this->route([], 'fa', 'store'), [
            'notification_id' => $id, 'sign_off' => $payload,
        ])->assertSessionHasErrors();

        $this->assertSame(1, $this->rows(), 'a resubmission did not double-sign the stage');
    }

    // -------------------------------------------------------------- hold points

    public function test_a_contractor_is_not_offered_the_waiver_on_a_hold_point(): void
    {
        $id = $this->issue('H', [$this->recipient->company_id, $this->employer->company_id]);
        $this->nominate($this->recipient);

        $screen = $this->actingAs($this->recipient)->get($this->route(['notice' => $id]))
            ->assertSee('id="hold-note"', false);

        // Offered and then refused would be the discovery-on-submit we refuse
        // to build; the option is simply not there for a contractor.
        $this->assertStringNotContainsString('WAIVED_NOT_ATTENDED', $screen->getContent());

        $this->actingAs($this->recipient)->post($this->route([], 'fa', 'store'), [
            'notification_id' => $id,
            'sign_off' => $this->payload([
                'outcome' => 'WAIVED_NOT_ATTENDED',
                'reason' => 'We were in a hurry',
            ]),
        ])->assertSessionHasErrors();

        $this->assertSame(0, $this->rows(), 'a contractor cannot remove the employer’s hold');
    }

    public function test_the_employer_may_waive_a_hold_point_and_must_say_why(): void
    {
        $id = $this->issue('H', [$this->recipient->company_id, $this->employer->company_id]);
        $this->nominate($this->employer);

        $this->actingAs($this->employer)->post($this->route([], 'fa', 'store'), [
            'notification_id' => $id,
            'sign_off' => $this->payload(['outcome' => 'WAIVED_NOT_ATTENDED', 'reason' => '']),
        ])->assertSessionHasErrors();

        $this->actingAs($this->employer)->post($this->route([], 'fa', 'store'), [
            'notification_id' => $id,
            'sign_off' => $this->payload([
                'outcome' => 'WAIVED_NOT_ATTENDED',
                'reason' => 'Hold released after document review',
            ]),
        ])->assertRedirect();

        $this->assertSame(1, $this->rows());
        $this->assertSame('WAIVED_NOT_ATTENDED', $this->signOffRows($id)->first()->outcome);
    }

    // ------------------------------------------------------------------ helpers

    /** @param  list<string>  $recipientCompanyIds */
    private function issue(string $point, array $recipientCompanyIds): string
    {
        return QualitySignOffFixture::issuedNotice(
            $this->tenant,
            $this->project,
            $this->writer,
            $this->employer,
            $recipientCompanyIds,
            $point,
        );
    }

    private function nominate(User $signer): void
    {
        app(ResultRecorderPolicyService::class)->configure($this->project, $signer, [
            'version' => 0,
            'target_company_id' => $signer->company_id,
            'action' => 'sign_off_stage',
            'selectors' => ['user:'.$signer->id],
            'suspend' => false,
            'reason' => 'Fixture nomination for stage sign-off',
        ], 'en');
    }

    /** @param  array<string,mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'outcome' => 'ACCEPTED',
            'signed_at' => '2026-10-25T10:30',
            'request_id' => (string) Str::uuid(),
        ], $overrides);
    }

    /** Sign-off rows for one notice, read inside the session contract. */
    private function signOffRows(string $notificationId): \Illuminate\Support\Collection
    {
        return $this->tenant->runAs(
            $this->recipient->company_id,
            fn () => DB::table('inspection_stage_signoffs')
                ->where('project_id', $this->project->id)
                ->where('notification_id', $notificationId)
                ->orderBy('recorded_at')->orderBy('id')->get(),
            $this->project->id
        );
    }

    private function rows(): int
    {
        return (int) $this->tenant->runAs(
            $this->recipient->company_id,
            fn (): int => DB::table('inspection_stage_signoffs')->count(),
            $this->project->id
        );
    }

    /** The point the database DERIVED for the notice's stage, not what we sent. */
    private function pointOf(string $notificationId): ?string
    {
        $row = $this->signOffRows($notificationId)->first();

        return $row !== null ? trim((string) $row->point) : null;
    }

    /** @param  array<string,mixed>  $query */
    private function route(array $query = [], string $lang = 'fa', string $action = ''): string
    {
        return route('quality.sign-offs'.($action === '' ? '' : '.'.$action), [
            'projectCode' => $this->project->code,
            'lang' => $lang,
        ] + $query);
    }
}
