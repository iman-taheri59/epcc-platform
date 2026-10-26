<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcceptedMtoBatch;
use App\Models\Company;
use App\Models\MtoDocument;
use App\Models\MtoRevision;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\DemandReaderService;
use App\Services\MtoAcceptanceService;
use App\Services\MtoStagingService;
use App\Services\ProjectArchiveService;
use App\Services\ScopeAdmissionAuthorityService;
use App\Services\ScopeProposalEnvelopeService;
use App\Services\ScopeStructureDeclarationService;
use App\Services\ScopeStructureReviewService;
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
final class ScopeStructureReviewTest extends TestCase
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

    private function manifest(): array
    {
        $o = $this->asPars(fn () => DB::table('mto_scope_observations')->where('accepted_mto_batch_id', $this->batch->id)->first());
        $p = json_decode($o->payload, true);
        $nodes = [['id' => $this->project()->id, 'project_id' => $this->project()->id, 'kind' => 'PROJECT', 'parent_id' => null]];
        foreach ($p['facilities'] as $f) {
            $nodes[] = ['id' => $f['id'], 'project_id' => $f['project_id'], 'kind' => 'FACILITY', 'parent_id' => $this->project()->id];
        }
        foreach ($p['areas'] as $a) {
            $nodes[] = ['id' => $a['id'], 'project_id' => $a['project_id'], 'kind' => 'AREA', 'parent_id' => $a['facility_id']];
        }
        foreach ($p['subsystems'] as $u) {
            $nodes[] = ['id' => $u['id'], 'project_id' => $u['project_id'], 'kind' => 'SUBSYSTEM', 'parent_id' => $u['area_id'] ?? $u['facility_id']];
        }

        return ['nodes' => $nodes, 'coverage' => [['scope_id' => $this->scope, 'row_ids' => array_column($p['rows'], 'id')]]];
    }

    private function declaration(array $e, array $extra = [], ?User $actor = null): array
    {
        return app(ScopeStructureDeclarationService::class)->declare($this->project(), $actor ?? $this->reader, array_replace(['proposal_id' => $e['id'], 'expected_proposal_version' => $e['version'], 'expected_proposal_digest' => $e['digest']] + $this->manifest(), $extra));
    }

    private function inspectStructure(string $id, ?User $actor = null): array
    {
        return app(ScopeStructureDeclarationService::class)->inspect($this->project(), $actor ?? $this->reviewer(), $id);
    }

    private function invalidClaim(callable $f): void
    {
        try {
            $f();
            $this->fail('Expected blocked declaration');
        } catch (ValidationException $x) {
            $this->assertNotEmpty($x->errors());
        }
    }

    private function opinion(array $d, array $extra = [], ?User $actor = null): array
    {
        return app(ScopeStructureReviewService::class)->review($this->project(), $actor ?? $this->reviewer(), array_replace(['declaration_id' => $d['id'], 'expected_declaration_version' => $d['version'], 'expected_declaration_digest' => $d['digest'],
            'expected_proposal_id' => $d['proposal_id'], 'expected_proposal_version' => $d['version'], 'expected_proposal_digest' => $d['proposal_digest'], 'outcome' => 'COMMENTED', 'reason' => 'Independent exact structure opinion — نظر مستقل؛ نه تأیید'], $extra));
    }

    private function inspectOpinion(string $id, ?User $actor = null): array
    {
        return app(ScopeStructureReviewService::class)->inspect($this->project(), $actor ?? $this->reader, $id);
    }

    private function ready(): array
    {
        $this->grants();
        $e = $this->submit();

        return $this->declaration($e);
    }

    public function test_independent_opinion_binds_both_real_content_identities_and_never_admits(): void
    {
        $d = $this->ready();
        $r = $this->opinion($d);
        $view = $this->inspectOpinion($r['id']);
        $this->assertSame($d['digest'], $view['declaration_digest']);
        $this->assertSame($d['proposal_digest'], $view['proposal_digest']);
        $this->assertSame($this->reviewer()->id, $view['reviewer_id']);
        $this->assertTrue($view['content_binding_verified']);
        $this->assertFalse($view['engineering_approval']);
        $this->assertFalse($view['evidence_verified']);
        $this->assertFalse($view['ready_to_admit']);
        $this->assertFalse($view['package_verified']);
        $this->assertFalse($view['operationally_authorized']);
        $this->assertSame('UNADMITTED', $view['admission_state']);
        $this->assertSame('UNVERIFIED_OPINION', $view['state']);
        $this->assertNull($view['gross']);
        $check = $this->asPars(fn () => DB::selectOne("SELECT digest=encode(sha256(convert_to(envelope::text,'UTF8')),'hex') AS ok FROM scope_structure_reviews WHERE id=?", [$r['id']]));
        $this->assertTrue($check->ok);
    }

    public function test_proposer_cannot_review_own_structure_with_a_review_nomination(): void
    {
        $d = $this->ready();
        $this->denied(fn () => $this->opinion($d, actor: $this->reader));
        $this->assertSame(0, $this->countRows('scope_structure_reviews'));
    }

    public function test_forged_actor_evidence_approval_or_reader_policy_not_accepted(): void
    {
        $d = $this->ready();
        foreach (['reviewer_id' => $this->owner->id, 'proposer_id' => $this->owner->id, 'evidence' => ['uri' => 'https://hidden.invalid'], 'source_binding' => [], 'reader_policy_id' => (string) Str::uuid(), 'state' => 'ADMITTED'] as $k => $v) {
            $this->invalidClaim(fn () => $this->opinion($d, [$k => $v]));
        }
        $this->invalidClaim(fn () => $this->opinion($d, ['outcome' => 'APPROVED']));
        $this->assertSame(0, $this->countRows('scope_structure_reviews'));
    }

    public function test_all_exact_versions_hashes_and_parent_id_are_required_not_latest_fallback(): void
    {
        $d = $this->ready();
        foreach (['expected_declaration_version' => 2, 'expected_proposal_version' => 2, 'expected_declaration_digest' => str_repeat('a', 64), 'expected_proposal_digest' => str_repeat('b', 64), 'expected_proposal_id' => (string) Str::uuid()] as $k => $v) {
            $this->invalidClaim(fn () => $this->opinion($d, [$k => $v]));
        }$this->assertSame(0, $this->countRows('scope_structure_reviews'));
    }

    public function test_duplicate_comment_or_changed_outcome_cannot_edit_or_replay(): void
    {
        $d = $this->ready();
        $this->opinion($d);
        $this->invalidClaim(fn () => $this->opinion($d));
        $this->invalidClaim(fn () => $this->opinion($d, ['outcome' => 'CHANGES_REQUESTED', 'reason' => 'Not an edit']));
        $this->assertSame(1, $this->countRows('scope_structure_reviews'));
    }

    public function test_changes_requested_is_nonpositive_and_stored_exactly(): void
    {
        $d = $this->ready();
        $r = $this->opinion($d, ['outcome' => 'CHANGES_REQUESTED']);
        $this->assertSame('CHANGES_REQUESTED', $r['outcome']);
        $this->assertFalse($this->inspectOpinion($r['id'])['engineering_approval']);
    }

    public function test_review_grant_and_reader_grant_are_independent_and_current(): void
    {
        $d = $this->ready();
        app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['version' => 1, 'suspended' => true, 'selectors' => []]) + ['action' => 'review', 'batch_id' => $this->batch->id]);
        try {
            $this->opinion($d);
            $this->fail('Expected hidden');
        } catch (HttpException $x) {
            $this->assertSame(404, $x->getStatusCode());
        }
        $this->configure(['version' => 1, 'suspended' => true, 'selectors' => []]);
        try {
            $this->opinion($d);
            $this->fail('Expected hidden');
        } catch (HttpException $x) {
            $this->assertSame(404, $x->getStatusCode());
        }$this->assertSame(0, $this->countRows('scope_structure_reviews'));
    }

    public function test_partly_hidden_source_or_hidden_ancestor_cannot_be_reviewed_or_read(): void
    {
        $d = $this->ready();
        $r = $this->opinion($d);
        $row = $this->asPars(fn () => DB::table('accepted_mto_rows')->where('accepted_mto_batch_id', $this->batch->id)->value('id'));
        foreach ([['facilities', 'false'], ['accepted_mto_rows', "id<>'{$row}'::uuid"]] as [$table,$predicate]) {
            DB::unprepared("CREATE POLICY bound_review_hide ON {$table} AS RESTRICTIVE FOR SELECT USING({$predicate})");
            try {
                foreach ([fn () => $this->opinion($d), fn () => $this->inspectOpinion($r['id'])] as $f) {
                    try {
                        $f();
                        $this->fail('Expected unavailable');
                    } catch (HttpException $x) {
                        $this->assertSame(404, $x->getStatusCode());
                    }
                }
            } finally {
                DB::unprepared("DROP POLICY bound_review_hide ON {$table}");
            }
        }
    }

    public function test_new_parent_and_declaration_never_inherit_old_opinion(): void
    {
        $d = $this->ready();
        $r = $this->opinion($d);
        $e = $this->submit(['expected_version' => 1, 'statement' => 'Second unverified narrative']);
        $n = $this->declaration($e);
        $this->invalidClaim(fn () => $this->opinion($d));
        $old = $this->inspectOpinion($r['id']);
        $this->assertFalse($old['is_latest']);
        $this->assertSame($d['id'], $old['declaration_id']);
        $new = $this->opinion($n);
        $this->assertSame($n['id'], $new['declaration_id']);
        $this->assertNotSame($r['digest'], $new['digest']);
        $this->assertSame(2, $this->countRows('scope_structure_reviews'));
    }

    public function test_old_narrative_opinion_api_stays_disabled_even_after_bound_opinion(): void
    {
        $d = $this->ready();
        $this->opinion($d);
        $this->invalidClaim(fn () => $this->review(['id' => $d['proposal_id'], 'version' => $d['version'], 'digest' => $d['proposal_digest']]));
        $this->assertSame(0, $this->countRows('scope_proposal_reviews'));
    }

    public function test_original_proposer_losing_current_read_path_does_not_gain_access_from_opinion(): void
    {
        $d = $this->ready();
        $r = $this->opinion($d);
        app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['version' => 1, 'suspended' => true, 'selectors' => []]) + ['action' => 'propose', 'batch_id' => $this->batch->id]);
        try {
            $this->inspectOpinion($r['id']);
            $this->fail('Expected hidden');
        } catch (HttpException $x) {
            $this->assertSame(404, $x->getStatusCode());
        }$this->assertSame($r['digest'], $this->inspectOpinion($r['id'], $this->reviewer())['digest']);
    }

    public function test_archive_readonly_and_owner_or_wrong_project_has_no_inherited_path(): void
    {
        $d = $this->ready();
        $r = $this->opinion($d);
        try {
            $this->inspectOpinion($r['id'], $this->owner);
            $this->fail('Expected hidden');
        } catch (HttpException $x) {
            $this->assertSame(404, $x->getStatusCode());
        }
        $other = $this->tenant->forIdentityLookup(fn () => Project::where('code', 'BB-2')->firstOrFail());
        try {
            app(ScopeStructureReviewService::class)->inspect($other, $this->reader, $r['id']);
            $this->fail('Expected hidden');
        } catch (HttpException $x) {
            $this->assertSame(404, $x->getStatusCode());
        }
        app(ProjectArchiveService::class)->archive($this->project(), $this->owner, 'Bound opinion fixture');
        $this->assertFalse($this->inspectOpinion($r['id'])['ready_to_admit']);
        $this->invalidClaim(fn () => $this->opinion($d));
    }

    public function test_new_table_force_rls_and_no_update_delete_or_foreign_company_metadata(): void
    {
        $d = $this->ready();
        $this->opinion($d);
        $this->assertSame(0, $this->asPars(fn () => DB::table('scope_structure_reviews')->update(['reason' => 'Rewrite'])));
        $this->assertSame(0, $this->asPars(fn () => DB::table('scope_structure_reviews')->delete()));
        $this->assertSame(0, $this->tenant->runAs($this->owner->company_id, fn () => DB::table('scope_structure_reviews')->count(), $this->project()->id));
        $f = DB::selectOne("SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE relname='scope_structure_reviews'");
        $this->assertTrue($f->relrowsecurity);
        $this->assertTrue($f->relforcerowsecurity);
    }

    public function test_transaction_rollback_and_populated_upgrade_create_no_opinions_or_source_changes(): void
    {
        $d = $this->ready();
        $before = $this->asPars(fn () => DB::table('mto_scope_observations')->orderBy('id')->get()->toJson());
        try {
            DB::transaction(function () use ($d) {
                $this->opinion($d);
                throw new \RuntimeException('rollback opinion');
            });
        } catch (\RuntimeException $x) {
            $this->assertSame('rollback opinion', $x->getMessage());
        }$this->assertSame(0, $this->countRows('scope_structure_reviews'));
        $m = require database_path('migrations/2026_10_25_100000_add_scope_structure_reviews.php');
        $m->down();
        $m->up();
        $this->assertSame($before, $this->asPars(fn () => DB::table('mto_scope_observations')->orderBy('id')->get()->toJson()));
        $this->assertSame(0, $this->countRows('scope_structure_reviews'));
        $this->assertFalse($this->opinion($d)['ready_to_admit']);
    }

    public function test_no_evidence_cde_stock_or_source_mutation_in_append_path(): void
    {
        $d = $this->ready();
        DB::enableQueryLog();
        $this->opinion($d);
        $q = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $sql = strtolower(implode(' ', array_column($q, 'query')));
        foreach (['"document_files"', '"document_revisions"', '"material_lots"', '"material_movements"', 'update "', 'delete from', 'insert into "accepted_mto', 'insert into "mto_scope_observations"'] as $t) {
            $this->assertStringNotContainsString($t, $sql);
        }
    }

    private function rawOpinion(array $d, array $change = []): array
    {
        return array_replace(['id' => (string) Str::uuid(), 'project_id' => $this->project()->id, 'company_id' => $this->reader->company_id,
            'declaration_id' => $d['id'], 'declaration_version' => $d['version'], 'declaration_digest' => $d['digest'],
            'proposal_id' => $d['proposal_id'], 'proposal_version' => $d['version'], 'proposal_digest' => $d['proposal_digest'],
            'reviewer_id' => $this->reviewer()->id, 'outcome' => 'COMMENTED', 'reason' => 'Raw SQL defensive bound opinion'], $change);
    }

    public function test_raw_sql_exact_content_self_review_and_positive_state_are_rejected(): void
    {
        $d = $this->ready();
        foreach ([['reviewer_id' => $this->reader->id], ['declaration_digest' => str_repeat('a', 64)], ['proposal_digest' => str_repeat('a', 64)], ['proposal_version' => 2], ['declaration_version' => 2], ['outcome' => 'APPROVED'], ['state' => 'ADMITTED'], ['reason' => "\n\t  "]] as $change) {
            $r = $this->rawOpinion($d, $change);
            $this->assertSqlState('23514', fn () => $this->asPars(fn () => DB::transaction(fn () => DB::table('scope_structure_reviews')->insert($r))));
        }
        $this->assertSame(0, $this->countRows('scope_structure_reviews'));
    }

    public function test_raw_sql_rechecks_hidden_dependency_and_live_review_authority(): void
    {
        $d = $this->ready();
        $r = $this->rawOpinion($d);
        DB::unprepared('CREATE POLICY bound_sql_hide ON facilities AS RESTRICTIVE FOR SELECT USING(false)');
        try {
            $this->assertSqlState('42501', fn () => $this->asPars(fn () => DB::transaction(fn () => DB::table('scope_structure_reviews')->insert($r))));
        } finally {
            DB::unprepared('DROP POLICY bound_sql_hide ON facilities');
        }
        app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['version' => 1, 'suspended' => true, 'selectors' => []]) + ['action' => 'review', 'batch_id' => $this->batch->id]);
        $this->assertSqlState('42501', fn () => $this->asPars(fn () => DB::transaction(fn () => DB::table('scope_structure_reviews')->insert($r))));
    }

    public function test_reader_revocation_or_inactive_reviewer_blocks_even_with_live_review_nomination(): void
    {
        $d = $this->ready();
        $u = $this->reviewer();
        $this->asPars(fn () => User::whereKey($u->id)->update(['is_active' => false]));
        try {
            $this->opinion($d);
            $this->fail('Expected hidden');
        } catch (HttpException $x) {
            $this->assertSame(404, $x->getStatusCode());
        }
        $this->asPars(fn () => User::whereKey($u->id)->update(['is_active' => true]));
        $r = $this->opinion($d);
        $this->configure(['version' => 1, 'suspended' => true, 'selectors' => []]);
        foreach ([fn () => $this->opinion($d), fn () => $this->inspectOpinion($r['id'])] as $f) {
            try {
                $f();
                $this->fail('Expected hidden');
            } catch (HttpException $x) {
                $this->assertSame(404, $x->getStatusCode());
            }
        }
    }

    public function test_two_connections_serialize_duplicate_opinion_and_new_parent_supersession(): void
    {
        $d = $this->ready();
        $pdo = new \PDO('pgsql:host='.config('database.connections.pgsql.host').';port='.config('database.connections.pgsql.port').';dbname='.config('database.connections.pgsql.database'), config('database.connections.pgsql.username'), config('database.connections.pgsql.password'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $bind = function () use ($pdo): void {
            $pdo->prepare("SELECT set_config('epcc.company_id',?,true),set_config('epcc.project_id',?,true),set_config('lock_timeout','150ms',true)")->execute([$this->reader->company_id, $this->project()->id]);
        };
        $r = $this->rawOpinion($d);
        $sql = 'INSERT INTO scope_structure_reviews ('.implode(',', array_keys($r)).') VALUES ('.implode(',', array_fill(0, count($r), '?')).')';
        DB::transaction(function () use ($d, $pdo, $bind, $sql, $r) {
            $this->opinion($d);
            $pdo->beginTransaction();
            $bind();
            try {
                $this->assertSqlState('55P03', fn () => $pdo->prepare($sql)->execute(array_values($r)));
            } finally {
                $pdo->rollBack();
            }
        });
        $pdo->beginTransaction();
        $bind();
        try {
            $this->assertSqlState('23505', fn () => $pdo->prepare($sql)->execute(array_values($r)));
        } finally {
            $pdo->rollBack();
        }
        DB::transaction(function () use ($pdo, $bind, $sql, $r) {
            $this->submit(['expected_version' => 1]);
            $pdo->beginTransaction();
            $bind();
            try {
                $this->assertSqlState('55P03', fn () => $pdo->prepare($sql)->execute(array_values($r)));
            } finally {
                $pdo->rollBack();
            }
        });
        $pdo->beginTransaction();
        $bind();
        try {
            $this->assertSqlState('23514', fn () => $pdo->prepare($sql)->execute(array_values($r)));
        } finally {
            $pdo->rollBack();
        }$this->assertSame(1, $this->countRows('scope_structure_reviews'));
    }

    public function test_corrupt_opinion_content_is_not_projected_as_bound_review(): void
    {
        $d = $this->ready();
        $r = $this->opinion($d);
        try {
            $this->asPars(function () use ($r) {
                DB::transaction(function () use ($r) {
                    DB::unprepared('CREATE POLICY structure_review_corrupt_fixture ON scope_structure_reviews FOR UPDATE USING(true) WITH CHECK(true); ALTER TABLE scope_structure_reviews DISABLE TRIGGER scope_bound_opinion_immutable');
                    DB::table('scope_structure_reviews')->where('id', $r['id'])->update(['reason' => 'Corrupt fixture reason']);
                    try {
                        $this->inspectOpinion($r['id']);
                        $this->fail('Expected integrity refusal');
                    } catch (HttpException $x) {
                        $this->assertSame(404, $x->getStatusCode());
                    }throw new \RuntimeException('rollback corrupt bound opinion');
                });
            });
        } catch (\RuntimeException $x) {
            $this->assertSame('rollback corrupt bound opinion', $x->getMessage());
        }$this->assertStringNotContainsString('Corrupt fixture reason', $this->inspectOpinion($r['id'])['reason']);
    }
}
