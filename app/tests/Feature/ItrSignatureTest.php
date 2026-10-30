<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingTestPack;
use App\Models\Project;
use App\Models\User;
use App\Services\InspectionResultService;
use App\Services\ItrCompilerService;
use App\Services\ItrSignatureService;
use App\Services\PdfRenderer;
use App\Services\PipingTestPackService;
use App\Services\ResultRecorderPolicyService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ItrDossierFixture;
use Tests\Support\QualityResultFixture;
use Tests\TestCase;

/**
 * ADR-048 A.5b slice 2 — the formal signature layer on ITR snapshots.
 *
 * Everything before this could record evidence but could not put a name to a
 * finished dossier: the dossier's own `kind` says ITR_COMPILATION_NOT_A_
 * CERTIFICATE, and a compilation nobody can sign is a draft nobody can close.
 *
 * So a signature exists now. What this file is really about is that it is
 * CONSEQUENTIAL, because the previous slice's whole argument was that a record
 * which gates nothing is decoration:
 *
 *   - the digest it signs comes from the frozen document, not the caller;
 *   - an endorsement is refused while the completeness report enumerates a gap;
 *   - the comments are mandatory exactly when they are the only honest way to
 *     sign, and forbidden when there is nothing to qualify;
 *   - signing is default-deny behind the nomination register;
 *   - a second signature chains, so one company cannot hold two histories.
 *
 * And the declared boundary is tested too: a signature changes what the
 * document SAYS about itself, never what it IS. It is still not a certificate.
 */
final class ItrSignatureTest extends TestCase
{
    private User $owner;

    private User $contractor;

    private User $outsider;

    private Project $project;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->tenant = app(TenantContext::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');       // the employer
        $this->contractor = $this->userByEmail('rezaei@pars.example');  // another project company
        $this->outsider = $this->userByEmail('hosseini@arvand.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        app()->setLocale('fa');
    }

    // ------------------------------------------------------------- the signing

    public function test_a_nominated_signer_endorses_a_complete_dossier(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        $row = $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED']);

        $this->assertSame('ENDORSED', $row->outcome);
        // The digest is the document's, derived — never what the caller sent.
        $this->assertSame($snapshot['digest'], (string) $row->digest);
        $this->assertNotNull($row->signer_policy_id, 'the nomination that authorised it is stamped on the row');
        $this->assertNull($row->previous_signature_id);
        $this->assertNull($row->reason, 'a clean endorsement carries no qualification');
    }

    public function test_an_endorsement_is_refused_while_the_dossier_enumerates_a_gap(): void
    {
        // The fixture pack has a fitted joint and nothing else: no weld, no
        // examination, no result. The completeness report says so out loud.
        $pack = $this->pack();
        $this->assertFalse($this->compile($pack)['completeness']['is_complete']);
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        $this->assertSignatureRefused(
            fn () => $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED']),
            'a clean endorsement is not available',
        );
    }

    public function test_the_same_dossier_can_be_endorsed_with_the_comments_that_make_it_honest(): void
    {
        $pack = $this->pack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        $row = $this->sign($this->owner, $snapshot['id'], [
            'outcome' => 'ENDORSED_WITH_COMMENTS',
            'reason' => 'Two NDT results are outstanding and will be appended as a revision.',
        ]);

        $this->assertSame('ENDORSED_WITH_COMMENTS', $row->outcome);
        $this->assertStringContainsString('outstanding', (string) $row->reason);
    }

    public function test_a_refusal_carries_its_cause(): void
    {
        $pack = $this->pack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        $row = $this->sign($this->owner, $snapshot['id'], [
            'outcome' => 'REFUSED',
            'reason' => 'The hydrotest chart was not attached.',
        ]);

        $this->assertSame('REFUSED', $row->outcome);
    }

    public function test_a_clean_endorsement_is_refused_a_qualification_it_does_not_have(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        $this->assertSignatureRefused(
            fn () => $this->sign($this->owner, $snapshot['id'], [
                'outcome' => 'ENDORSED',
                'reason' => 'fine, apart from the missing chart',
            ]),
            'A clean endorsement carries no qualification',
        );
    }

    public function test_a_conditional_signature_without_its_reason_is_refused(): void
    {
        $pack = $this->pack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        $this->assertSignatureRefused(
            fn () => $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED_WITH_COMMENTS']),
            'never recorded without a reason',
        );

        $this->assertSignatureRefused(
            fn () => $this->sign($this->owner, $snapshot['id'], ['outcome' => 'REFUSED']),
            'never recorded without a reason',
        );
    }

    public function test_someone_without_a_nomination_cannot_sign_at_all(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);

        $this->assertSignatureRefused(
            fn () => $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED']),
            'requires a live nomination for sign_itr_snapshot',
        );
    }

    public function test_a_suspended_nomination_signs_nothing(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);
        $this->nominate($this->owner, suspend: true);

        $this->assertSignatureRefused(
            fn () => $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED']),
            'requires a live nomination for sign_itr_snapshot',
        );
    }

    // -------------------------------------------------------------- the chain

    public function test_a_second_signature_chains_onto_the_first_and_the_latter_is_the_word(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        $first = $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED']);
        $second = $this->sign($this->owner, $snapshot['id'], [
            'outcome' => 'REFUSED',
            'reason' => 'The endorsement was given against a superseded revision.',
            'previous_signature_id' => (string) $first->id,
        ]);

        $this->assertSame((string) $first->id, (string) $second->previous_signature_id);
        // Both rows stand: a second signature is a new chained row, never an
        // edit — and the reader is given one word per company, not the history.
        $this->assertCount(2, $this->rows($snapshot['id']));

        $latest = $this->signatures()->latestPerCompany($this->project, $this->owner, $snapshot['id']);
        $this->assertCount(1, $latest);
        $this->assertSame('REFUSED', $latest->first()->outcome);
    }

    public function test_a_signature_naming_a_stale_previous_is_refused(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        $first = $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED']);
        $this->sign($this->owner, $snapshot['id'], [
            'outcome' => 'REFUSED',
            'reason' => 'Changed my mind.',
            'previous_signature_id' => (string) $first->id,
        ]);

        // A stale submission would fork one history into two.
        $this->assertSignatureRefused(
            fn () => $this->sign($this->owner, $snapshot['id'], [
                'outcome' => 'ENDORSED',
                'previous_signature_id' => (string) $first->id,
            ]),
            'later signature on this snapshot',
        );
    }

    public function test_two_companies_sign_independently_and_both_are_read(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);
        $this->nominate($this->contractor);

        $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED']);
        $this->sign($this->contractor, $snapshot['id'], [
            'outcome' => 'ENDORSED_WITH_COMMENTS',
            'reason' => 'Accepted subject to the punch list.',
        ]);

        $latest = $this->signatures()->latestPerCompany($this->project, $this->owner, $snapshot['id']);
        $this->assertCount(2, $latest);
        $this->assertSame(
            ['ENDORSED', 'ENDORSED_WITH_COMMENTS'],
            $latest->map(fn (object $r): string => (string) $r->outcome)->sort()->values()->all(),
        );
    }

    public function test_one_request_id_cannot_sign_twice(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);
        $requestId = (string) Str::uuid();

        $first = $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED', 'request_id' => $requestId]);

        // Naming the head, so the chain check is satisfied and what refuses
        // this is the request id: a retry is a replay, never a second record.
        $this->assertSignatureRefused(
            fn () => $this->sign($this->owner, $snapshot['id'], [
                'outcome' => 'ENDORSED',
                'request_id' => $requestId,
                'previous_signature_id' => (string) $first->id,
            ]),
            'That request has already been recorded',
        );
    }

    public function test_a_signature_row_cannot_be_edited_or_deleted(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);
        $row = $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED']);

        $affected = $this->asOwner(fn () => DB::table('itr_snapshot_signatures')
            ->where('id', $row->id)->update(['outcome' => 'REFUSED']));
        $deleted = $this->asOwner(fn () => DB::table('itr_snapshot_signatures')
            ->where('id', $row->id)->delete());

        $this->assertSame(0, $affected, 'a signature is never edited');
        $this->assertSame(0, $deleted, 'and never deleted');
        $this->assertSame('ENDORSED', (string) $this->rows($snapshot['id'])->first()->outcome);

        // A correction is a NEW chained row, and this is the trigger that
        // makes it the only way to change your mind.
        $triggers = $this->asOwner(fn () => collect(DB::select(
            "SELECT tgname::text AS name FROM pg_trigger WHERE tgrelid = 'itr_snapshot_signatures'::regclass AND NOT tgisinternal"
        ))->map(fn (object $t): string => (string) $t->name)->all());
        $this->assertContains('itr_signature_immutable', $triggers);
    }

    // ----------------------------------------------------------- the document

    public function test_a_snapshot_that_no_longer_matches_its_digest_cannot_be_signed(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        // Tampering at the database level: the immutability trigger is lifted,
        // the document is changed, the trigger is put back. If a signature were
        // accepted here it would be a signature over something else.
        $this->tamper($snapshot['id']);

        $this->assertSignatureRefused(
            fn () => $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED']),
            'no longer matches its own digest',
        );
    }

    public function test_a_signature_changes_what_the_document_says_about_itself_not_what_it_is(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        $kindBefore = $this->compile($pack)['dossier']['kind'] ?? null;
        $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED']);

        // The declared boundary: a signed dossier is signed evidence, and it
        // is still not a certificate of acceptance.
        $this->assertSame('ITR_COMPILATION_NOT_A_CERTIFICATE', $kindBefore);
        $this->assertSame('ITR_COMPILATION_NOT_A_CERTIFICATE', $this->compile($pack)['dossier']['kind'] ?? null);
    }

    public function test_the_printed_dossier_says_it_is_not_a_certificate_until_someone_signs_it(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);

        $before = $this->renderedDocument($this->owner, $snapshot['id'], collect());
        $this->assertStringContainsString('This document is NOT a certificate', $before);
        $this->assertStringContainsString('Nobody has signed it.', $before);
        $this->assertStringNotContainsString('signed as evidence', $before);

        $this->nominate($this->owner);
        $this->sign($this->owner, $snapshot['id'], ['outcome' => 'ENDORSED']);

        $after = $this->renderedDocument(
            $this->owner,
            $snapshot['id'],
            $this->signatures()->latestPerCompany($this->project, $this->owner, $snapshot['id']),
        );
        $this->assertStringContainsString('signed as evidence', $after);
        $this->assertStringContainsString('Endorsed', $after);
        $this->assertStringNotContainsString('NOT a certificate', $after);
    }

    public function test_the_printed_dossier_carries_the_hold_evidence_it_was_compiled_against(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);

        $html = $this->renderedDocument($this->owner, $snapshot['id'], collect(), collect([
            [
                'number' => 'IN-SIG-1', 'point' => 'H', 'hold_state' => 'NOT_SIGNED',
                'stage_description' => 'Final visual', 'signoffs' => [],
            ],
        ]));

        $this->assertStringContainsString('IN-SIG-1', $html);
        $this->assertStringContainsString('not signed', $html);
    }

    // ---------------------------------------------------------------- HTTP

    public function test_the_screen_offers_signing_only_to_a_nominated_company(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);

        $this->actingAs($this->owner)->get($this->route(['pack' => $pack->id]))
            ->assertOk()
            ->assertSee('id="no-nomination"', false)
            ->assertDontSee('id="outcome-', false);

        $this->nominate($this->owner);

        $response = $this->actingAs($this->owner)->get($this->route(['pack' => $pack->id]));

        $response->assertOk();
        $response->assertSee('id="outcome-'.Str::of($snapshot['id'])->substr(0, 8).'"', false);
        $response->assertDontSee('id="no-nomination"', false);
        // And the outcome that is on offer is the one the guard will accept.
        $this->assertStringContainsString('value="ENDORSED"', $response->getContent());
    }

    public function test_a_dossier_with_gaps_offers_no_clean_endorsement_on_the_form(): void
    {
        $pack = $this->pack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        $response = $this->actingAs($this->owner)->get($this->route(['pack' => $pack->id]));

        $response->assertOk();
        // Not offered, not offered-and-refused: the form is built around what
        // the guard will accept, which is the whole point of the slice.
        $response->assertSee('id="gaps-note-'.Str::of($snapshot['id'])->substr(0, 8).'"', false);
        $this->assertStringNotContainsString('value="ENDORSED"', $response->getContent());
    }

    public function test_a_signature_posted_over_http_shows_on_the_screen(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        $response = $this->actingAs($this->owner)->post($this->signRoute(), [
            'test_pack_id' => $pack->id,
            'snapshot_id' => $snapshot['id'],
            'outcome' => 'ENDORSED',
            'request_id' => (string) Str::uuid(),
        ]);

        $response->assertRedirect($this->route(['pack' => $pack->id]));
        $this->actingAs($this->owner)->get($this->route(['pack' => $pack->id]))
            ->assertOk()
            ->assertSee(__('quality_itr.sig_ENDORSED'), false);
    }

    public function test_a_signature_posted_over_http_is_refused_with_a_sentence_not_a_code(): void
    {
        $pack = $this->pack(); // incomplete on purpose
        $snapshot = $this->freeze($pack);
        $this->nominate($this->owner);

        $response = $this->actingAs($this->owner)->post($this->signRoute(), [
            'test_pack_id' => $pack->id,
            'snapshot_id' => $snapshot['id'],
            'outcome' => 'ENDORSED',
            'request_id' => (string) Str::uuid(),
        ]);

        $response->assertSessionHasErrors('outcome');
        $this->assertStringContainsString(
            'clean endorsement is not available',
            (string) session('errors')->first('outcome'),
        );
    }

    public function test_someone_outside_the_project_cannot_sign(): void
    {
        $pack = $this->completePack();
        $snapshot = $this->freeze($pack);

        $this->actingAs($this->outsider)->post($this->signRoute(), [
            'test_pack_id' => $pack->id,
            'snapshot_id' => $snapshot['id'],
            'outcome' => 'ENDORSED',
            'request_id' => (string) Str::uuid(),
        ])->assertForbidden();

        $this->assertCount(0, $this->rows($snapshot['id']));
    }

    // ------------------------------------------------------------- plumbing

    /** @return Collection<int, object> every row on one snapshot, in order. */
    private function rows(string $snapshotId): Collection
    {
        return $this->asOwner(fn () => DB::table('itr_snapshot_signatures')
            ->where('project_id', $this->project->id)
            ->where('snapshot_id', $snapshotId)
            ->orderBy('recorded_at')->orderBy('id')
            ->get());
    }

    private function signatures(): ItrSignatureService
    {
        return app(ItrSignatureService::class);
    }

    private function itr(): ItrCompilerService
    {
        return app(ItrCompilerService::class);
    }

    /** A READY pack with a fitted joint and nothing else: gaps, by design. */
    private function pack(string $code = 'TP-SIG-1'): PipingTestPack
    {
        return ItrDossierFixture::pack($this->tenant, $this->project, $this->owner, 'READY', $code);
    }

    /** A pack whose dossier the completeness report calls complete. */
    private function completePack(string $code = 'TP-SIG-2'): PipingTestPack
    {
        // The joint is fitted before the line is released, because a released
        // line may not be edited — which is the point of releasing it.
        $line = QualityResultFixture::line($this->tenant, $this->project, $this->owner);
        $joint = QualityResultFixture::fittedJoint($this->tenant, $this->project, $this->owner, '6', $line);

        // Released with a direct UPDATE rather than through confirmAfc()+
        // release(), because issuing a line for construction demands isometric
        // and P&ID evidence that has its own tests and nothing to do with
        // signatures. The AFC provenance the CHECK demands is written too:
        // stepping around a guard is not what this fixture is for.
        $this->asOwner(function () use ($line): void {
            DB::table('piping_lines')->where('id', $line->id)->update([
                'afc_confirmed_at' => now(), 'afc_confirmed_by' => $this->owner->id,
                'release_state' => 'RELEASED', 'released_at' => now(), 'released_by' => $this->owner->id,
            ]);
        });

        $pack = $this->asOwner(fn () => app(PipingTestPackService::class)
            ->create($this->project, $this->owner, ['code' => $code]));
        $this->asOwner(fn () => app(PipingTestPackService::class)->assignLine($line, $this->owner, $pack));
        $this->asOwner(function () use ($pack): void {
            DB::table('piping_test_packs')->where('id', $pack->id)
                ->update(['state' => 'READY', 'ready_at' => now(), 'ready_by' => $this->owner->id]);
        });

        $this->nominate($this->owner, 'record_weld');
        $this->nominate($this->owner, 'record_ndt');
        $this->nominate($this->owner, 'record_pack_result');

        $this->asOwner(fn () => app(InspectionResultService::class)->recordWeld(
            $this->project, $this->owner, (string) $joint->id,
            ['kind' => 'WELD', 'welded_by_code' => 'W-311', 'welded_at' => '2026-10-28T08:00:00+03:30',
                'request_id' => (string) Str::uuid()], 'en'));
        $this->asOwner(fn () => app(InspectionResultService::class)->recordNdt(
            $this->project, $this->owner, (string) $joint->id,
            ['method' => 'RT', 'result' => 'ACCEPTED', 'performed_by_code' => 'NDT-12',
                'performed_at' => '2026-10-28T10:00:00+03:30', 'request_id' => (string) Str::uuid()], 'en'));
        $this->asOwner(fn () => app(InspectionResultService::class)->recordTestResult(
            $this->project, $this->owner, (string) $pack->id,
            ['test_type' => 'HYDROSTATIC', 'medium' => 'WATER', 'test_pressure' => '21.750', 'pressure_unit' => 'BAR',
                'hold_duration_minutes' => 120, 'started_at' => '2026-10-28T06:00:00+03:30',
                'completed_at' => '2026-10-28T09:30:00+03:30', 'result' => 'PASS',
                'request_id' => (string) Str::uuid()], 'en'));

        $fresh = $this->asOwner(fn () => PipingTestPack::whereKey($pack->id)->firstOrFail());
        $this->assertTrue(
            $this->compile($fresh)['completeness']['is_complete'],
            'the fixture must produce a complete dossier, or the endorsement test proves nothing',
        );

        return $fresh;
    }

    /** @return array{id: string, digest: string} */
    private function freeze(PipingTestPack $pack): array
    {
        return $this->asOwner(fn () => $this->itr()->freeze(
            $this->project, $this->owner, (string) $pack->id, (string) Str::uuid(), 'en'));
    }

    /** @return array{dossier: array<string,mixed>, completeness: array<string,mixed>} */
    private function compile(PipingTestPack $pack): array
    {
        return $this->asOwner(fn () => $this->itr()->compile($this->project, $this->owner, (string) $pack->id, 'en'));
    }

    /** @param  array<string,mixed>  $overrides */
    private function sign(User $actor, string $snapshotId, array $overrides = []): object
    {
        $outcome = (string) ($overrides['outcome'] ?? 'ENDORSED');
        $previous = $overrides['previous_signature_id'] ?? null;
        unset($overrides['outcome'], $overrides['previous_signature_id']);

        $signed = $this->as($actor, fn () => $this->signatures()->sign(
            $this->project, $actor, $snapshotId, array_replace([
                'outcome' => $outcome,
                'signed_at' => '2026-10-31T10:00:00+03:30',
                'request_id' => (string) Str::uuid(),
            ], array_filter(['previous_signature_id' => $previous], static fn ($v): bool => $v !== null), $overrides), 'en'));

        return $this->as($actor, fn () => DB::table('itr_snapshot_signatures')->where('id', $signed['id'])->first());
    }

    private function nominate(User $user, string $action = 'sign_itr_snapshot', bool $suspend = false): void
    {
        // The register is versioned and optimistic: what you send is the
        // version you loaded, and the service writes the next one. A second
        // nomination for the same (company, action) is therefore a new version,
        // never an edit of the row that authorised an earlier signature.
        $version = (int) $this->asOwner(fn () => DB::table('result_recorder_policies')
            ->where('project_id', $this->project->id)
            ->where('target_company_id', $user->company_id)
            ->where('action', $action)
            ->max('version'));

        app(ResultRecorderPolicyService::class)->configure($this->project, $this->owner, [
            'version' => $version,
            'target_company_id' => $user->company_id,
            'action' => $action,
            'selectors' => ['user:'.$user->id],
            'suspend' => $suspend,
            'reason' => 'ITR signature fixture nomination',
        ], 'en');
    }

    /**
     * Changes a frozen dossier underneath: the immutability trigger and row
     * security are lifted, the document is edited, and both are put back.
     *
     * This is tampering at the database level — the only way a stored dossier
     * can stop matching its digest — and it is worth a test precisely because
     * a signature accepted here would be a signature over something else.
     */
    private function tamper(string $snapshotId): void
    {
        $this->asOwner(function () use ($snapshotId): void {
            DB::statement('ALTER TABLE itr_snapshots DISABLE TRIGGER itr_snapshots_immutable');
            DB::statement('ALTER TABLE itr_snapshots NO FORCE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE itr_snapshots DISABLE ROW LEVEL SECURITY');

            DB::table('itr_snapshots')->where('id', $snapshotId)
                ->update(['dossier' => DB::raw("dossier || '{\"tampered\":true}'::jsonb")]);

            DB::statement('ALTER TABLE itr_snapshots ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE itr_snapshots FORCE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE itr_snapshots ENABLE TRIGGER itr_snapshots_immutable');
        });
    }

    /**
     * The document as the printer would see it: through renderPdf, with a
     * renderer that echoes its own input, so the wiring that carries the
     * evidence into the view is what is under test — not the view's markup.
     *
     * @param  Collection<int, object>  $signatures
     * @param  Collection<int, array<string,mixed>>|null  $notifications
     */
    private function renderedDocument(
        User $actor,
        string $snapshotId,
        Collection $signatures,
        ?Collection $notifications = null,
    ): string {
        $stub = $this->echoingRenderer();

        try {
            // A fresh compiler, so the substitution is what renders: the thing
            // under test is the wiring that carries evidence into the document.
            $compiler = new ItrCompilerService($this->tenant, $stub);
            $bytes = $this->as($actor, fn () => $compiler->renderPdf(
                $this->project, $actor, $snapshotId, 'en', $notifications ?? collect(), $signatures));
        } finally {
            @unlink((string) $stub->binary());
        }

        $this->assertNotNull($bytes, 'the dossier must render');

        return (string) $bytes;
    }

    /**
     * A stand-in for headless Chrome that writes its own INPUT into the "PDF",
     * so a test can read the document the compiler actually built. PdfRenderer
     * is final and takes its binaries in the constructor, so it is substituted
     * rather than mocked — no mocking framework needed, which is the point.
     */
    private function echoingRenderer(): PdfRenderer
    {
        $script = sys_get_temp_dir().'/epcc-itr-echo-'.Str::lower(Str::random(8)).'.sh';
        file_put_contents($script, <<<'SH'
#!/bin/sh
out=""
src=""
for arg in "$@"; do
  case "$arg" in --print-to-pdf=*) out="${arg#--print-to-pdf=}";; file://*) src="${arg#file://}";; esac
done
printf '%s\n' '%PDF-1.4 echo renderer' > "$out"
cat "$src" >> "$out"
i=0
while [ "$i" -lt 40 ]; do
  printf '%s\n' '0123456789abcdefghijklmnopqrstuvwxyz' >> "$out"
  i=$((i + 1))
done
SH);
        chmod($script, 0o700);

        return new PdfRenderer(30, [$script]);
    }

    private function assertSignatureRefused(callable $fn, string $needle): void
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->implode(' ');
            $this->assertStringContainsString($needle, $message);

            return;
        }

        $this->fail('The signature was accepted; it should have been refused.');
    }

    private function as(User $actor, callable $fn): mixed
    {
        return $this->tenant->runAs($actor->company_id, $fn, $this->project->id);
    }

    private function asOwner(callable $fn): mixed
    {
        return $this->as($this->owner, $fn);
    }

    /** @param  array<string,mixed>  $query */
    private function route(array $query = []): string
    {
        return route('quality.itr', array_replace(['projectCode' => 'PH-14', 'lang' => 'en'], $query));
    }

    private function signRoute(): string
    {
        return route('quality.itr.sign', ['projectCode' => 'PH-14', 'lang' => 'en']);
    }
}
