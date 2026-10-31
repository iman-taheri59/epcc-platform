<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingTestPack;
use App\Models\Project;
use App\Models\User;
use App\Services\InspectionNotificationService;
use App\Services\InspectionResultService;
use App\Services\ItrCompilerService;
use App\Services\ItpSignOffService;
use App\Services\PipingTestPackService;
use App\Services\ResultRecorderPolicyService;
use App\Services\TestPackNotificationService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ItrDossierFixture;
use Tests\Support\QualitySignOffFixture;
use Tests\TestCase;

/**
 * ADR-048 A.5b slice 1 — the notification<->test-pack gate.
 *
 * The gap this closes is a missing consequence, not a missing feature. Since
 * A.4d an ITP stage can be signed off; since A.4h slice 2 it can be signed
 * from a screen. Nothing in the database cared: a pack could be marked TESTED
 * and then ACCEPTED while the client's HOLD point on that very test sat
 * unsigned, or sat REJECTED. The sign-off register was a diary.
 *
 * A record that gates nothing is decoration, and this one was decorating the
 * exact claim a client relies on. Two things land together: a link (this notice
 * was raised about this pack) and a gate (a HOLD on a linked notice stops the
 * claim). The gate lives in the trigger; this file drives it through the real
 * services and through HTTP.
 */
final class PackNotificationGateTest extends TestCase
{
    private User $owner;

    private User $writer;

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
        $this->owner = $this->userByEmail('karimi@nioc.example');       // the employer, and the pack's company
        $this->writer = $this->userByEmail('rezaei@pars.example');      // issues the notices
        $this->consultant = $this->userByEmail('tehrani@pmc.example');  // on the project, notified of nothing
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        app()->setLocale('fa');
    }

    // ------------------------------------------------------------------- the gate

    public function test_a_linked_hold_notice_stops_the_pack_claiming_it_was_tested(): void
    {
        $pack = $this->pack();
        $this->passResult($pack);                       // so the result rule is not what refuses it
        $this->notice('H', [$this->owner->company_id]); // linked, and the employer is a recipient
        $this->linkLast($this->owner);

        // Everything the pack needs is present except the client's hold.
        $this->assertRefused(
            fn () => $this->transition($pack, 'TESTED'),
            'still unsigned',
        );
        $this->assertSame('READY', $this->stateOf($pack));
    }

    public function test_the_employer_signing_the_hold_releases_the_claim(): void
    {
        $pack = $this->pack();
        $this->passResult($pack);
        $notice = $this->notice('H', [$this->owner->company_id]);
        $this->linkLast($this->owner);
        $this->nominate($this->owner, 'sign_off_stage');

        $this->sign($this->owner, $notice, ['outcome' => 'ACCEPTED']);

        $this->assertSame('TESTED', $this->stateOf($this->transition($pack, 'TESTED')));
    }

    public function test_a_rejection_keeps_the_hold_shut_until_a_correction_is_recorded(): void
    {
        $pack = $this->pack();
        $this->passResult($pack);
        $notice = $this->notice('H', [$this->owner->company_id]);
        $this->linkLast($this->owner);
        $this->nominate($this->owner, 'sign_off_stage');

        $this->sign($this->owner, $notice, ['outcome' => 'REJECTED', 'reason' => 'The gauge was out of calibration.']);

        $this->assertRefused(fn () => $this->transition($pack, 'TESTED'), 'rejected');

        // A correction is a NEW chained sign-off, never an edit: once it is
        // recorded the lineage culminates in acceptance, as with pack results.
        $rejected = $this->signOffRows($notice)->firstWhere('outcome', 'REJECTED');
        $this->sign($this->owner, $notice, [
            'outcome' => 'ACCEPTED',
            'previous_signoff_id' => (string) $rejected->id,
        ]);

        $this->assertSame('TESTED', $this->stateOf($this->transition($pack, 'TESTED')));
    }

    public function test_the_employer_waiving_its_own_hold_releases_the_claim(): void
    {
        $pack = $this->pack();
        $this->passResult($pack);
        $notice = $this->notice('H', [$this->owner->company_id]);
        $this->linkLast($this->owner);
        $this->nominate($this->owner, 'sign_off_stage');

        // Only the employer may waive an H — the guard enforces it — and the
        // waiver is the release, not a deletion nobody can see.
        $this->sign($this->owner, $notice, [
            'outcome' => 'WAIVED_NOT_ATTENDED',
            'reason' => 'The client waived attendance; the test was video-recorded.',
        ]);

        $this->assertSame('TESTED', $this->stateOf($this->transition($pack, 'TESTED')));
    }

    public function test_an_unsigned_witness_point_records_but_does_not_gate(): void
    {
        $pack = $this->pack();
        $this->passResult($pack);
        $this->notice('W', [$this->owner->company_id]);
        $this->linkLast($this->owner);

        // A witness point records that work was observed. It does not license
        // stopping it, so it is not a gate.
        $this->assertSame('TESTED', $this->stateOf($this->transition($pack, 'TESTED')));
    }

    public function test_a_hold_notice_gates_acceptance_as_well_as_the_test(): void
    {
        $pack = $this->pack();
        $this->passResult($pack);
        $this->transition($pack, 'TESTED');             // no notice linked yet, so this is fine
        $notice = $this->notice('H', [$this->owner->company_id]);
        $this->linkLast($this->owner);

        $this->assertRefused(fn () => $this->transition($pack, 'ACCEPTED'), 'still unsigned');

        $this->nominate($this->owner, 'sign_off_stage');
        $this->sign($this->owner, $notice, ['outcome' => 'ACCEPTED']);

        $this->assertSame('ACCEPTED', $this->stateOf($this->transition($pack, 'ACCEPTED')));
    }

    // ------------------------------------------------------------------- the link

    public function test_a_draft_notice_cannot_be_linked(): void
    {
        // The pack belongs to the company that drafted the notice, which is the
        // ordinary case: a contractor raises the notice about its own work. A
        // draft is visible only to its own company, so this is also the only
        // actor that can be refused for the honest reason.
        $pack = $this->contractorPack();
        $draft = QualitySignOffFixture::issuedNotice(
            $this->tenant, $this->project, $this->writer, $this->owner,
            [$this->owner->company_id], 'H', 'DRAFT NOTICE', false,
        );

        $this->assertRefused(
            fn () => app(TestPackNotificationService::class)->link(
                $this->project, $this->writer, (string) $pack->id, $draft, (string) Str::uuid(), 'en',
            ),
            'not yet a request',
        );
        $this->assertSame(0, $this->linkRows($pack));
    }

    /**
     * The contractor may link its own pack — the employer is not the only
     * party with a claim to make, and a notice about your own work is yours to
     * name.
     */
    public function test_the_company_that_owns_the_pack_may_link_its_own_notice(): void
    {
        $pack = $this->contractorPack();
        $notice = $this->notice('H', [$this->owner->company_id]);

        app(TestPackNotificationService::class)->link(
            $this->project, $this->writer, (string) $pack->id, $notice, (string) Str::uuid(), 'en');

        $this->assertSame(1, $this->linkRows($pack));
    }

    public function test_a_cancelled_notice_cannot_be_linked_and_stops_gating(): void
    {
        $pack = $this->pack();
        $this->passResult($pack);
        $notice = $this->notice('H', [$this->owner->company_id]);
        $this->linkLast($this->owner);

        $this->tenant->runAs($this->writer->company_id, fn () => app(InspectionNotificationService::class)
            ->cancel($this->project, $this->writer, $notice, ['version' => 2, 'reason' => 'The scope moved to another pack.']),
            $this->project->id);

        // A withdrawn request is not a question anybody is answering, so the
        // gate lets go — and the link stays, because it happened.
        $this->assertSame('TESTED', $this->stateOf($this->transition($pack, 'TESTED')));

        $this->assertRefused(
            fn () => app(TestPackNotificationService::class)->link(
                $this->project, $this->owner, (string) $pack->id, $notice, (string) Str::uuid(), 'en',
            ),
            'withdrawn request',
        );
        $this->assertSame(1, $this->linkRows($pack));
    }

    public function test_only_the_pack_owner_or_the_employer_may_link(): void
    {
        $pack = $this->pack();
        $notice = $this->notice('H', [$this->owner->company_id]);

        // The writer issued the notice and can see it, but the pack is not
        // theirs and they are not the employer: naming someone else's
        // inspection against someone else's pack is not a right.
        $this->assertRefused(
            fn () => app(TestPackNotificationService::class)->link(
                $this->project, $this->writer, (string) $pack->id, $notice, (string) Str::uuid(), 'en',
            ),
            'project employer',
        );
        $this->assertSame(0, $this->linkRows($pack));
    }

    public function test_a_notice_your_company_cannot_see_cannot_be_linked(): void
    {
        $pack = $this->pack();
        // Addressed to the consultant only: the employer may link (authority)
        // but RLS hides the notice from everyone who was not sent it.
        $this->notice('H', [$this->consultant->company_id]);

        $this->assertRefused(
            fn () => app(TestPackNotificationService::class)->link(
                $this->project, $this->owner, (string) $pack->id, $this->lastNoticeId, (string) Str::uuid(), 'en',
            ),
            'not visible',
        );
        $this->assertSame(0, $this->linkRows($pack));
    }

    public function test_linking_the_same_notice_twice_writes_one_row(): void
    {
        $pack = $this->pack();
        $notice = $this->notice('H', [$this->owner->company_id]);

        app(TestPackNotificationService::class)->link(
            $this->project, $this->owner, (string) $pack->id, $notice, (string) Str::uuid(), 'en');
        $this->assertRefused(
            fn () => app(TestPackNotificationService::class)->link(
                $this->project, $this->owner, (string) $pack->id, $notice, (string) Str::uuid(), 'en',
            ),
            'already linked',
        );
        $this->assertSame(1, $this->linkRows($pack));
    }

    public function test_the_same_request_id_is_refused_rather_than_written_twice(): void
    {
        $pack = $this->pack();
        $first = $this->notice('H', [$this->owner->company_id]);
        $second = $this->notice('H', [$this->owner->company_id], 'SECOND FIXTURE WORK');
        $requestId = (string) Str::uuid();
        $link = fn (string $notice): array => app(TestPackNotificationService::class)
            ->link($this->project, $this->owner, (string) $pack->id, $notice, $requestId, 'en');

        $this->assertNotSame('', $link($first)['id']);
        $this->assertRefused(fn () => $link($second), 'already been recorded');
        $this->assertSame(1, $this->linkRows($pack));
    }

    // ----------------------------------------------------------------- the screen

    public function test_the_screen_shows_the_notice_and_says_the_hold_is_not_cleared(): void
    {
        $pack = $this->pack();
        $this->notice('H', [$this->owner->company_id]);
        $this->linkLast($this->owner);

        $response = $this->actingAs($this->owner)->get($this->route(['pack' => $pack->id]));

        $response->assertOk();
        $response->assertSee('id="notice-table"', false);
        $response->assertSee(__('quality_itr.hold_not_signed'), false);
        $response->assertSee(__('quality_itr.gap_hold_notifications_not_cleared'), false);
        $response->assertDontSee('id="no-notices"', false);
    }

    public function test_the_screen_offers_only_the_notices_that_could_be_linked(): void
    {
        $pack = $this->pack();
        $notice = $this->notice('H', [$this->owner->company_id]);

        $this->actingAs($this->owner)->get($this->route(['pack' => $pack->id]))
            ->assertOk()
            ->assertSee('id="link-form"', false)
            ->assertSee('id="notice-select"', false)
            ->assertSee($notice, false);

        $this->linkLast($this->owner);

        // Once it is linked there is nothing left to offer, and the screen
        // says so instead of showing an empty select.
        $this->actingAs($this->owner)->get($this->route(['pack' => $pack->id]))
            ->assertOk()
            ->assertSee('id="no-eligible"', false)
            ->assertDontSee('id="link-form"', false);
    }

    public function test_linking_from_the_screen_makes_the_notice_part_of_the_pack(): void
    {
        $pack = $this->pack();
        $this->notice('H', [$this->owner->company_id]);

        $response = $this->actingAs($this->owner)->post(route('quality.itr.link', [
            'projectCode' => 'PH-14', 'lang' => 'fa',
        ]), [
            'test_pack_id' => $pack->id,
            'notification_id' => $this->lastNoticeId,
            'request_id' => (string) Str::uuid(),
        ]);

        $response->assertRedirect($this->route(['pack' => $pack->id]));
        $this->assertSame(1, $this->linkRows($pack));

        $this->actingAs($this->owner)->get($this->route(['pack' => $pack->id]))
            ->assertSee('id="notice-table"', false);
    }

    public function test_a_guest_is_sent_to_login_and_a_non_member_is_refused(): void
    {
        $pack = $this->pack();
        $this->notice('H', [$this->owner->company_id]);

        $this->post(route('quality.itr.link', ['projectCode' => 'PH-14', 'lang' => 'fa']), [
            'test_pack_id' => $pack->id,
            'notification_id' => $this->lastNoticeId,
            'request_id' => (string) Str::uuid(),
        ])->assertRedirect(route('login'));

        $outsider = $this->userByEmail('hosseini@arvand.example');
        $this->actingAs($outsider)->post(route('quality.itr.link', ['projectCode' => 'PH-14', 'lang' => 'fa']), [
            'test_pack_id' => $pack->id,
            'notification_id' => $this->lastNoticeId,
            'request_id' => (string) Str::uuid(),
        ])->assertForbidden();

        $this->assertSame(0, $this->linkRows($pack));
    }

    // ------------------------------------------------------------- the dossier

    public function test_the_frozen_dossier_carries_the_notice_and_enumerates_the_hold(): void
    {
        $pack = $this->pack();
        $notice = $this->notice('H', [$this->owner->company_id]);
        $this->linkLast($this->owner);

        $snapshot = app(ItrCompilerService::class)->freeze(
            $this->project, $this->owner, (string) $pack->id, (string) Str::uuid(), 'fa');

        $row = $this->tenant->runAs($this->owner->company_id, fn () => DB::table('itr_snapshots')
            ->where('id', $snapshot['id'])->first(), $this->project->id);
        $dossier = json_decode((string) $row->dossier, true, 512, JSON_THROW_ON_ERROR);
        $complete = json_decode((string) $row->completeness, true, 512, JSON_THROW_ON_ERROR);

        // 2 -> 3 in A.5b slice 3a: the dossier names its scope and its packs,
        // and the completeness report gained the gap a pack scope cannot see.
        $this->assertSame(3, $dossier['schema_version']);
        $this->assertSame($notice, $dossier['notifications'][0]['notification_id']);
        $this->assertSame('H', $dossier['notifications'][0]['point']);
        $this->assertSame('NOT_SIGNED', $dossier['notifications'][0]['hold_state']);
        $this->assertFalse($dossier['notifications'][0]['cancelled']);

        $gaps = $complete['missing']['hold_notifications_not_cleared'];
        $this->assertCount(1, $gaps);
        $this->assertSame($notice, $gaps[0]['notification_id']);
        $this->assertSame('NOT_SIGNED', $gaps[0]['hold_state']);
        $this->assertFalse($complete['is_complete']);
        $this->assertSame(1, $complete['counts']['hold_points']);

        // Signed, the same pack compiles a dossier with no hold gap — the
        // dossier and the gate asking one SQL function, so they cannot drift.
        $this->nominate($this->owner, 'sign_off_stage');
        $this->sign($this->owner, $notice, ['outcome' => 'ACCEPTED']);

        $again = app(ItrCompilerService::class)
            ->compile($this->project, $this->owner, (string) $pack->id, 'fa')['completeness'];
        $this->assertSame([], $again['missing']['hold_notifications_not_cleared']);
    }

    // ----------------------------------------------------------------- plumbing

    private ?string $lastNoticeId = null;

    private function pack(): PipingTestPack
    {
        $pack = ItrDossierFixture::pack($this->tenant, $this->project, $this->owner);
        $this->packId = (string) $pack->id;

        return $pack;
    }

    private function notice(string $point, array $recipientCompanyIds, string $work = 'GATE FIXTURE WORK'): string
    {
        $this->lastNoticeId = QualitySignOffFixture::issuedNotice(
            $this->tenant, $this->project, $this->writer, $this->owner, $recipientCompanyIds, $point, $work,
        );

        return $this->lastNoticeId;
    }

    private function linkLast(User $actor): array
    {
        return app(TestPackNotificationService::class)->link(
            $this->project, $actor, (string) $this->packId(), $this->lastNoticeId, (string) Str::uuid(), 'en');
    }

    private string $packId = '';

    private function packId(): string
    {
        return $this->packId;
    }

    /** A pack belonging to the writer's company rather than the employer's. */
    private function contractorPack(): PipingTestPack
    {
        $pack = $this->tenant->runAs($this->writer->company_id, fn () => app(PipingTestPackService::class)
            ->create($this->project, $this->writer, ['code' => 'TP-GATE-1']), $this->project->id);
        $this->packId = (string) $pack->id;

        return $pack;
    }

    /** A PASS result, so the only thing that can refuse TESTED is the hold. */
    private function passResult(PipingTestPack $pack): void
    {
        $this->nominate($this->owner, 'record_pack_result');

        $this->tenant->runAs($this->owner->company_id, fn () => app(InspectionResultService::class)
            ->recordTestResult($this->project, $this->owner, (string) $pack->id, [
                'test_type' => 'HYDROSTATIC',
                'medium' => 'WATER',
                'test_pressure' => '21.750',
                'pressure_unit' => 'BAR',
                'hold_duration_minutes' => 120,
                'started_at' => '2026-10-28T06:00:00+03:30',
                'completed_at' => '2026-10-28T09:30:00+03:30',
                'result' => 'PASS',
                'request_id' => (string) Str::uuid(),
            ], 'en'), $this->project->id);
    }

    private function nominate(User $user, string $action): void
    {
        app(ResultRecorderPolicyService::class)->configure($this->project, $user, [
            'version' => 0,
            'target_company_id' => $user->company_id,
            'action' => $action,
            'selectors' => ['user:'.$user->id],
            'suspend' => false,
            'reason' => 'Pack-notification gate fixture nomination',
        ], 'en');
    }

    /** @param  array<string, mixed>  $overrides */
    private function sign(User $actor, string $notificationId, array $overrides = []): array
    {
        return $this->tenant->runAs($actor->company_id, fn () => app(ItpSignOffService::class)
            ->signOff($this->project, $actor, $notificationId, array_replace([
                'outcome' => 'ACCEPTED',
                'signed_at' => '2026-10-25T10:30',
                'request_id' => (string) Str::uuid(),
            ], $overrides), 'en'), $this->project->id);
    }

    private function signOffRows(string $notificationId): \Illuminate\Support\Collection
    {
        return $this->tenant->runAs($this->owner->company_id, fn () => DB::table('inspection_stage_signoffs')
            ->where('project_id', $this->project->id)
            ->where('notification_id', $notificationId)
            ->orderBy('recorded_at')->orderBy('id')->get(), $this->project->id);
    }

    private function transition(PipingTestPack $pack, string $to): PipingTestPack
    {
        // Re-read first: the service checks the transition against the state it
        // is given, and a model carried across two transitions is stale.
        $fresh = $this->tenant->runAs($this->owner->company_id, fn (): PipingTestPack => PipingTestPack
            ::whereKey($pack->id)->firstOrFail(), $this->project->id);

        return $this->tenant->runAs($this->owner->company_id, fn () => app(PipingTestPackService::class)
            ->transition($fresh, $this->owner, $to, 'en'), $this->project->id);
    }

    private function stateOf(PipingTestPack $pack): string
    {
        return (string) $this->tenant->runAs($this->owner->company_id, fn () => PipingTestPack::whereKey($pack->id)
            ->value('state'), $this->project->id);
    }

    private function linkRows(PipingTestPack $pack): int
    {
        return (int) $this->tenant->runAs($this->owner->company_id, fn () => DB::table('piping_test_pack_notifications')
            ->where('project_id', $this->project->id)->where('test_pack_id', $pack->id)
            ->count(), $this->project->id);
    }

    private function assertRefused(callable $fn, string $needle): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $this->assertStringContainsString($needle, json_encode($e->errors(), JSON_UNESCAPED_UNICODE));

            return;
        }

        $this->fail('Expected a refusal containing ['.$needle.'] but the call succeeded.');
    }

    private function route(array $extra = [], string $lang = 'fa'): string
    {
        return route('quality.itr', ['projectCode' => 'PH-14', 'lang' => $lang] + $extra);
    }
}
