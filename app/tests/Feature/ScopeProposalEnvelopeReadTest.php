<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcceptedMtoBatch;
use App\Models\Company;
use App\Models\MtoDocument;
use App\Models\MtoRevision;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\DemandReaderService;
use App\Services\MtoAcceptanceService;
use App\Services\MtoStagingService;
use App\Services\ProjectArchiveService;
use App\Services\ScopeAdmissionAuthorityService;
use App\Services\ScopeProposalEnvelopeReader;
use App\Services\ScopeProposalEnvelopeService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real PostgreSQL observations are not engineering admission. */
final class ScopeProposalEnvelopeReadTest extends TestCase
{
    private User $owner;

    private User $reader;

    private string $scope;

    private AcceptedMtoBatch $batch;

    private MtoStagingService $staging;

    private MtoAcceptanceService $acceptance;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->seed(MaterialCatalogueSeeder::class);

        $this->staging = app(MtoStagingService::class);
        $this->acceptance = app(MtoAcceptanceService::class);
        $this->tenant = app(TenantContext::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->reader = $this->userByEmail('rezaei@pars.example');
        $this->scope = $this->asPars(fn () => Subsystem::where('project_id', $this->project()->id)->value('id'));
        $doc = $this->document();
        $revision = $this->asPars(fn () => $this->staging->createRevision($doc, $this->reader, 'A', [$this->row(['row_scope_id' => $this->scope]), $this->row(['mto_item_no' => '20', 'row_scope_id' => $this->scope])], hierarchyRevisionId: 'H1', lang: 'en'));
        $this->batch = $this->acceptObserved($revision);
    }

    private function project(): Project
    {
        return $this->tenant->forIdentityLookup(
            fn () => Project::where('code', 'PH-14')->firstOrFail()
        );
    }

    private function company(string $code): Company
    {
        return $this->tenant->forIdentityLookup(
            fn () => Company::where('code', $code)->firstOrFail()
        );
    }

    private function document(): MtoDocument
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        return $this->tenant->runAs($pars->id, fn () => app(MtoStagingService::class)->createDocument(
            $project,
            $rezaei,
            'PH-14-PIP-MTO-'.fake()->unique()->numerify('####'),
            'Piping bulk MTO',
            'MTO حجمی پایپینگ',
            'PIPING',
            MtoDocument::SOURCE_MANUAL,
            lang: 'en'
        ), $project->id);
    }

    /** @param array<string,mixed> $override */
    private function row(array $override = []): array
    {
        return $override + [
            'mto_item_no' => '10',
            'commodity_code' => 'PIP-CS-6-SCH40',
            'raw_description' => '6" CS pipe, SCH 40, API 5L X60 PSL2',
            'raw_quantity' => 120,
            'raw_unit' => 'M',
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private function draftRevision(array $rows): MtoRevision
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $document = $this->document();

        return $this->tenant->runAs($pars->id, fn () => $this->staging->createRevision(
            $document,
            $rezaei,
            'A',
            $rows,
            revisionDate: '2026-10-06',
            lang: 'en'
        ), $project->id);
    }

    private function asPars(callable $fn): mixed
    {
        return $this->tenant->runAs($this->company('EPC-PARS')->id, $fn, $this->project()->id);
    }

    private function assertSqlState(string $state, callable $fn): void
    {
        try {
            $fn();
            $this->fail('Expected SQLSTATE '.$state);
        } catch (\PDOException $e) {
            $this->assertSame($state, $e->errorInfo[0]);
        }
    }

    private function acceptObserved(MtoRevision $revision): AcceptedMtoBatch
    {
        // Commit is outside this callback. Observation is intentionally deferred.
        return $this->asPars(fn () => $this->acceptance->accept($revision, 'en'));
    }

    private function input(array $changes = []): array
    {
        return array_replace(['target_company_id' => $this->reader->company_id, 'document_id' => $this->batch->mto_document_id, 'scope_id' => $this->scope, 'hierarchy_revision_id' => 'H1', 'version' => 0, 'suspended' => false, 'selectors' => ['user:'.$this->reader->id], 'reason' => 'Explicit synthetic reader'], $changes);
    }

    private function configure(array $changes = [], ?User $actor = null): string
    {
        return app(DemandReaderService::class)->configure($this->project(), $actor ?? $this->owner, $this->input($changes));
    }

    private function assess(?User $actor = null, ?string $batch = null, ?string $document = null, ?string $scope = null, string $hierarchy = 'H1'): array
    {
        return app(DemandReaderService::class)->assess($this->project(), $actor ?? $this->reader, $batch ?? $this->batch->id, $document ?? $this->batch->mto_document_id, $scope ?? $this->scope, $hierarchy);
    }

    private function denied(callable $f): void
    {
        try {
            $f();
            $this->fail('Expected deny');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    private function reviewer(): User
    {
        return $this->userByEmail('sadeghi@pars.example');
    }

    private function grants(): void
    {
        $users = ['user:'.$this->reader->id, 'user:'.$this->reviewer()->id];
        $this->configure(['selectors' => $users]);
        foreach (['propose', 'review'] as $a) {
            app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['selectors' => $users]) + ['batch_id' => $this->batch->id, 'action' => $a]);
        }
    }

    private function body(array $extra = []): array
    {
        return array_replace(['batch_id' => $this->batch->id, 'document_id' => $this->batch->mto_document_id, 'scope_id' => $this->scope, 'hierarchy_revision_id' => 'H1', 'expected_version' => 0, 'statement' => 'Unverified engineering narrative — شرح فنی پیشنهادی'], $extra);
    }

    private function submit(array $extra = [], ?User $actor = null): array
    {
        return app(ScopeProposalEnvelopeService::class)->submit($this->project(), $actor ?? $this->reader, $this->body($extra));
    }

    private function review(array $e, array $extra = [], ?User $actor = null): array
    {
        return app(ScopeProposalEnvelopeService::class)->review($this->project(), $actor ?? $this->reviewer(), array_replace(['proposal_id' => $e['id'], 'expected_version' => $e['version'], 'expected_digest' => $e['digest'], 'outcome' => 'COMMENTED', 'reason' => 'Independent comment, no technical admission'], $extra));
    }

    private function record(string $id): object
    {
        return $this->asPars(fn () => DB::table('scope_proposal_envelopes')->where('id', $id)->first());
    }

    private function countRows(string $table = 'scope_proposal_envelopes'): int
    {
        return $this->asPars(fn () => DB::table($table)->count());
    }

    private function invalid(callable $f, string $key): void
    {
        try {
            $f();
            $this->fail('Expected validation');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($key, $e->errors());
        }
    }

    private function url(string $id, array $extra = []): string
    {
        return route('materials.scope-proposals.show', ['projectCode' => 'PH-14', 'proposalId' => $id] + $extra + ['lang' => 'en']);
    }

    private function capture(string $name, $res): void
    {
        if (getenv('SCOPE_READ_EVIDENCE') === '1') {
            file_put_contents(base_path('../docs/evidence/2026-10-24-scope-envelope-read/'.$name.'.html'), preg_replace('/(<input[^>]*name="_token"[^>]*value=")[^"]*/', '$1REDACTED-CSRF', $res->getContent()));
        }
    }

    public function test_guest_redirect_and_only_get_route_exists(): void
    {
        $id = (string) Str::uuid();
        $this->get($this->url($id))->assertRedirect('/login');
        $this->actingAs($this->reader)->post($this->url($id), [])->assertStatus(405);
        $this->delete($this->url($id))->assertStatus(405);
    }

    public function test_original_proposer_and_independent_reviewer_read_bilingual_private_false_readiness(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->review($e);
        foreach (['en', 'fa'] as $lang) {
            foreach ([$this->reader, $this->reviewer()] as $actor) {
                $res = $this->actingAs($actor)->get($this->url($e['id'], ['lang' => $lang]))->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private')->assertSee('<html lang="'.$lang.'" dir="'.($lang === 'fa' ? 'rtl' : 'ltr').'">', false)->assertSee($e['digest'])->assertSee('Unverified engineering narrative')->assertSee('Independent comment, no technical admission')->assertSee('data-ready-to-admit="false"', false)->assertSee('data-package-verified="false"', false)->assertSee('data-operationally-authorized="false"', false);
                $this->capture(($actor->id === $this->reader->id ? 'proposer-' : 'reviewer-').$lang, $res);
            }
        }
    }

    public function test_configurator_company_and_cross_project_ids_are_opaque_not_found(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->actingAs($this->owner)->get($this->url($e['id']))->assertNotFound()->assertDontSee('Unverified engineering narrative');
        $this->actingAs($this->reader)->get(route('materials.scope-proposals.show', ['projectCode' => 'BB-2', 'proposalId' => $e['id']]))->assertNotFound();
        $this->get($this->url((string) Str::uuid()))->assertNotFound();
        $this->get('/projects/PH-14/materials/scope-proposals/not-a-uuid')->assertNotFound();
    }

    public function test_source_reader_alone_does_not_read_envelope_or_opinions(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->review($e);
        foreach (['propose', 'review'] as $a) {
            app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['version' => 1, 'suspended' => true, 'selectors' => []]) + ['action' => $a, 'batch_id' => $this->batch->id]);
        }
        foreach ([$this->reader, $this->reviewer()] as $u) {
            $this->actingAs($u)->get($this->url($e['id']))->assertNotFound()->assertDontSee('Unverified engineering narrative')->assertDontSee('Independent comment');
        }
    }

    public function test_record_only_nomination_does_not_create_read_channel_for_this_increment(): void
    {
        $this->grants();
        $e = $this->submit();
        app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['version' => 1, 'suspended' => true, 'selectors' => []]) + ['action' => 'review', 'batch_id' => $this->batch->id]);
        app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['selectors' => ['user:'.$this->reviewer()->id]]) + ['action' => 'record', 'batch_id' => $this->batch->id]);
        $this->actingAs($this->reviewer())->get($this->url($e['id']))->assertNotFound();
    }

    public function test_original_proposer_cannot_use_self_review_nomination_after_propose_revocation(): void
    {
        $this->grants();
        $e = $this->submit();
        app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['version' => 1, 'suspended' => true, 'selectors' => []]) + ['action' => 'propose', 'batch_id' => $this->batch->id]);
        $this->actingAs($this->reader)->get($this->url($e['id']))->assertNotFound();
        $this->actingAs($this->reviewer())->get($this->url($e['id']))->assertOk();
    }

    public function test_reader_revocation_blocks_both_read_paths_even_live_action_grants(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->configure(['version' => 1, 'suspended' => true, 'selectors' => []]);
        foreach ([$this->reader, $this->reviewer()] as $u) {
            $this->actingAs($u)->get($this->url($e['id']))->assertNotFound();
        }
    }

    public function test_hidden_or_partial_source_returns_opaque_404_no_stale_content(): void
    {
        $this->grants();
        $e = $this->submit();
        $id = $this->asPars(fn () => DB::table('accepted_mto_rows')->where('accepted_mto_batch_id', $this->batch->id)->value('id'));
        foreach (['false', "id<>'{$id}'::uuid"] as $predicate) {
            DB::unprepared('CREATE POLICY read_hide ON accepted_mto_rows AS RESTRICTIVE FOR SELECT USING('.$predicate.')');
            try {
                $this->actingAs($this->reviewer())->get($this->url($e['id']))->assertNotFound()->assertDontSee('Unverified engineering narrative')->assertDontSee($e['digest']);
            } finally {
                DB::unprepared('DROP POLICY read_hide ON accepted_mto_rows');
            }
        }
    }

    public function test_historical_version_never_uses_newer_opinions_or_latest_instead(): void
    {
        $this->grants();
        $old = $this->submit(['statement' => 'OLD-NARRATIVE']);
        $this->review($old, ['reason' => 'OLD-EXACT-OPINION']);
        $new = $this->submit(['expected_version' => 1, 'statement' => 'NEW-NARRATIVE']);
        $this->review($new, ['reason' => 'NEW-EXACT-OPINION']);
        $this->actingAs($this->reviewer())->get($this->url($old['id']))->assertOk()->assertSee('Historical proposal version')->assertSee('OLD-NARRATIVE')->assertSee('OLD-EXACT-OPINION')->assertDontSee('NEW-NARRATIVE')->assertDontSee('NEW-EXACT-OPINION')->assertSee($this->url($new['id']), false);
        $this->get($this->url($new['id']))->assertOk()->assertSee('NEW-NARRATIVE')->assertSee('NEW-EXACT-OPINION')->assertDontSee('OLD-EXACT-OPINION')->assertSee($this->url($old['id']), false);
    }

    public function test_no_implicit_opinion_carry_forward_when_latest_has_none(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->review($e, ['reason' => 'HISTORICAL-ONLY-COMMENT']);
        $n = $this->submit(['expected_version' => 1]);
        $this->actingAs($this->reader)->get($this->url($n['id']))->assertOk()->assertDontSee('HISTORICAL-ONLY-COMMENT')->assertSee('No visible opinions for this exact version');
    }

    public function test_archive_remains_readable_with_no_mutation_form(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->review($e);
        app(ProjectArchiveService::class)->archive($this->project(), $this->owner, 'Read-only fixture');
        $res = $this->actingAs($this->reviewer())->get($this->url($e['id']))->assertOk()->assertSee('Archived project: read-only history')->assertDontSee('name="proposal[', false)->assertDontSee('name="review[', false);
        $this->assertFalse($res->viewData('inspection')['writable']);
    }

    public function test_claimed_actor_source_and_action_query_params_cannot_change_authorization(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->actingAs($this->owner)->get($this->url($e['id'], ['proposer_id' => $this->reader->id, 'reviewer_id' => $this->reviewer()->id, 'action' => 'review', 'company_id' => $this->reader->company_id, 'source_binding' => 'invented']))->assertNotFound();
        $res = $this->actingAs($this->reviewer())->get($this->url($e['id'], ['action' => 'record', 'expected_digest' => str_repeat('a', 64)]))->assertOk();
        $this->assertFalse($res->viewData('inspection')['ready_to_admit']);
    }

    public function test_untrusted_statement_and_review_are_escaped_no_external_link_or_image(): void
    {
        $this->grants();
        $e = $this->submit(['statement' => '<img src="https://example.invalid/track" onerror="alert(1)">']);
        $this->review($e, ['reason' => '<script>untrusted()</script>']);
        $this->actingAs($this->reader)->get($this->url($e['id']))->assertOk()->assertSee('&lt;img', false)->assertSee('&lt;script&gt;', false)->assertDontSee('<img src="https://example.invalid/track"', false)->assertDontSee('<script>untrusted()', false);
    }

    public function test_inactive_account_current_role_and_participation_changes_block_reads(): void
    {
        $this->grants();
        $e = $this->submit();
        $u = $this->reviewer();
        $this->asPars(fn () => User::whereKey($u->id)->update(['is_active' => false]));
        try {
            app(ScopeProposalEnvelopeReader::class)->inspect($this->project(), $u, $e['id']);
            $this->fail('Expected hidden');
        } catch (HttpException $x) {
            $this->assertSame(404, $x->getStatusCode());
        }
        $this->asPars(fn () => User::whereKey($u->id)->update(['is_active' => true]));
        app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['version' => 1, 'selectors' => ['role:'.$u->company_id.':qc_inspector']]) + ['batch_id' => $this->batch->id, 'action' => 'review']);
        $this->actingAs($u)->get($this->url($e['id']))->assertOk();
        $this->asPars(fn () => ProjectMembership::where('user_id', $u->id)->where('project_id', $this->project()->id)->update(['role_codes' => []]));
        $this->get($this->url($e['id']))->assertNotFound();
        DB::table('project_company')->where('project_id', $this->project()->id)->where('company_id', $u->company_id)->delete();
        $this->actingAs($this->reader)->get($this->url($e['id']))->assertNotFound();
    }

    public function test_read_model_has_no_mutations_source_quantities_payload_or_cde_stock_queries(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->review($e);
        DB::enableQueryLog();
        $res = $this->actingAs($this->reviewer())->get($this->url($e['id']))->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $sql = strtolower(implode(' ', array_column($queries, 'query')));
        foreach (['insert into', 'delete from', 'update "', '"mto_scope_observations"', '"document_files"', '"material_lots"', '"material_movements"'] as $t) {
            $this->assertStringNotContainsString($t, $sql);
        }
        $r = $res->viewData('inspection');
        $this->assertArrayNotHasKey('envelope', $r['proposal']);
        $this->assertArrayNotHasKey('payload', $r['proposal']['binding']);
        $this->assertArrayNotHasKey('rows', $r['proposal']['binding']);
        $this->assertNull($r['gross']);
    }

    public function test_no_proposal_narrative_or_review_query_before_human_action_gate(): void
    {
        $this->grants();
        $e = $this->submit();
        app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['version' => 1, 'suspended' => true, 'selectors' => []]) + ['batch_id' => $this->batch->id, 'action' => 'review']);
        DB::enableQueryLog();
        $this->actingAs($this->reviewer())->get($this->url($e['id']))->assertNotFound();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $sql = strtolower(implode(' ', array_column($queries, 'query')));
        $this->assertStringNotContainsString('scope_proposal_reviews', $sql);
        $this->assertStringNotContainsString('"statement"', $sql);
        $this->assertStringNotContainsString('select *,', $sql);
    }

    public function test_corrupt_proposal_or_opinion_fails_closed_without_partial_page(): void
    {
        $this->grants();
        $e = $this->submit();
        $r = $this->review($e);
        foreach ([['scope_proposal_envelopes', $e['id'], 'statement', 'Corrupt narrative'], ['scope_proposal_reviews', $r['id'], 'reason', 'Corrupt opinion']] as [$table,$id,$field,$value]) {
            try {
                $this->asPars(function () use ($e, $table, $id, $field, $value) {
                    DB::transaction(function () use ($e, $table, $id, $field, $value) {
                        // Controlled corruption fixture only; all DDL/data rolled back, never applied by app code.
                        DB::unprepared("CREATE POLICY read_corrupt_fixture ON {$table} FOR UPDATE USING(true) WITH CHECK(true); ALTER TABLE {$table} DISABLE TRIGGER dossier_immutable");
                        DB::table($table)->where('id', $id)->update([$field => $value]);
                        try {
                            app(ScopeProposalEnvelopeReader::class)->inspect($this->project(), $this->reviewer(), $e['id']);
                            $this->fail('Corrupt content must not render');
                        } catch (HttpException $x) {
                            $this->assertSame(404, $x->getStatusCode());
                        }
                        throw new \RuntimeException('rollback corruption fixture');
                    });
                });
            } catch (\RuntimeException $x) {
                $this->assertSame('rollback corruption fixture', $x->getMessage());
            }
        }
        $this->actingAs($this->reviewer())->get($this->url($e['id']))->assertOk()->assertDontSee('Corrupt narrative')->assertDontSee('Corrupt opinion');
    }

    public function test_bounded_opinion_display_is_explicit_not_a_completeness_certificate(): void
    {
        $this->grants();
        $e = $this->submit();
        $people = [];
        $password = bcrypt('synthetic-only-fixture');
        for ($i = 1; $i <= 21; $i++) {
            $people[] = $this->asPars(function () use ($i, $password) {
                $u = User::create(['company_id' => $this->reader->company_id, 'email' => 'scope-review-'.$i.'@example.test', 'name' => 'Synthetic reviewer '.$i, 'password' => $password, 'is_active' => true]);
                ProjectMembership::create(['user_id' => $u->id, 'project_id' => $this->project()->id, 'role_codes' => ['qc_inspector']]);

                return $u;
            });
        }
        $selectors = ['user:'.$this->reader->id, 'role:'.$this->reader->company_id.':qc_inspector'];
        $this->configure(['version' => 1, 'selectors' => $selectors]);
        app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['version' => 1, 'selectors' => $selectors]) + ['action' => 'review', 'batch_id' => $this->batch->id]);
        foreach ($people as $i => $u) {
            $this->review($e, ['reason' => 'VISIBLE-OPINION-'.($i + 1).'-END'], actor: $u);
        }
        $res = $this->actingAs($this->reader)->get($this->url($e['id']))->assertOk()->assertSee('More visible opinions exist')->assertDontSee('VISIBLE-OPINION-21-END');
        $inspection = $res->viewData('inspection');
        $this->assertCount(20, $inspection['reviews']);
        $this->assertTrue($inspection['reviews_has_more']);
        $this->assertFalse($inspection['package_verified']);
    }
}
