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
final class ScopeStructureDeclarationTest extends TestCase
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

    public function test_persisted_claim_canonical_and_source_bound_but_never_technical_approval(): void
    {
        $this->grants();
        $e = $this->submit();
        $d = $this->declaration($e);
        $r = $this->inspectStructure($d['id']);
        $this->assertSame($e['digest'], $r['proposal_digest']);
        $this->assertSame('UNVERIFIED_STRUCTURE', $r['state']);
        $this->assertFalse($r['ready_to_admit']);
        $this->assertFalse($r['package_verified']);
        $this->assertFalse($r['evidence_attached']);
        $this->assertFalse($r['technical_review_bound']);
        $this->assertNull($r['gross']);
        $this->assertCount(count($this->manifest()['nodes']), $r['nodes']);
        $ok = $this->asPars(fn () => DB::selectOne("SELECT digest=encode(sha256(convert_to(envelope::text,'UTF8')),'hex') AS ok FROM scope_structure_declarations WHERE id=?", [$d['id']]));
        $this->assertTrue($ok->ok);
    }

    public function test_input_order_and_uuid_case_normalize_without_duplicate_identity_loophole(): void
    {
        $this->grants();
        $e = $this->submit();
        $m = $this->manifest();
        $m['nodes'] = array_reverse($m['nodes']);
        foreach ($m['nodes'] as &$n) {
            $n['id'] = strtoupper($n['id']);
            $n['project_id'] = strtoupper($n['project_id']);
            if ($n['parent_id']) {
                $n['parent_id'] = strtoupper($n['parent_id']);
            }
        }unset($n);
        $m['coverage'][0]['row_ids'] = array_map('strtoupper', array_reverse($m['coverage'][0]['row_ids']));
        $d = $this->declaration($e, $m);
        $r = $this->inspectStructure($d['id']);
        $this->assertSame($this->scope, $r['coverage'][0]->scope_id);
        $n = $this->submit(['expected_version' => 1]);
        $m = $this->manifest();
        $dup = $m['nodes'][0];
        $dup['id'] = strtoupper($dup['id']);
        $m['nodes'][] = $dup;
        $this->invalidClaim(fn () => $this->declaration($n, $m));
    }

    public function test_declared_source_actor_hash_state_or_evidence_cannot_be_injected(): void
    {
        $this->grants();
        $e = $this->submit();
        foreach (['declared_by' => $this->owner->id, 'state' => 'ADMITTED', 'source_binding' => [], 'evidence' => ['retained_uri' => 'https://hidden.invalid/file'], 'topology_evidence' => ['record_id' => (string) Str::uuid()]] as $k => $v) {
            $this->invalidClaim(fn () => $this->declaration($e, [$k => $v]));
        }
        $this->assertSame(0, $this->countRows('scope_structure_declarations'));
    }

    public function test_scope_kind_parent_and_ancestry_closure_are_explicit(): void
    {
        $this->grants();
        $e = $this->submit();
        $base = $this->manifest();
        $leaf = array_search('SUBSYSTEM', array_column($base['nodes'], 'kind'));
        $m = $base;
        $m['nodes'][$leaf]['parent_id'] = $this->scope;
        $this->invalidClaim(fn () => $this->declaration($e, $m));
        $m = $base;
        $m['nodes'][$leaf]['kind'] = 'AREA';
        $this->invalidClaim(fn () => $this->declaration($e, $m));
        $m = $base;
        $m['nodes'][0]['project_id'] = (string) Str::uuid();
        $this->invalidClaim(fn () => $this->declaration($e, $m));
        $m = $base;
        $m['nodes'][0]['title'] = 'hidden name';
        $this->invalidClaim(fn () => $this->declaration($e, $m));
        $m = $base;
        array_pop($m['nodes']);
        $this->invalidClaim(fn () => $this->declaration($e, $m));
        $m = $base;
        $m['nodes'][$leaf]['id'] = (string) Str::uuid();
        $this->invalidClaim(fn () => $this->declaration($e, $m));
    }

    public function test_row_coverage_exact_once_no_omission_unknown_or_duplicate_rows(): void
    {
        $this->grants();
        $e = $this->submit();
        $base = $this->manifest();
        $m = $base;
        array_pop($m['coverage'][0]['row_ids']);
        $this->invalidClaim(fn () => $this->declaration($e, $m));
        $m = $base;
        $m['coverage'][0]['row_ids'][] = $m['coverage'][0]['row_ids'][0];
        $this->invalidClaim(fn () => $this->declaration($e, $m));
        $m = $base;
        $m['coverage'][0]['row_ids'][0] = (string) Str::uuid();
        $this->invalidClaim(fn () => $this->declaration($e, $m));
        $m = $base;
        $m['coverage'][0]['scope_id'] = (string) Str::uuid();
        $this->invalidClaim(fn () => $this->declaration($e, $m));
        $m = $base;
        $m['coverage'][0]['row_ids'] = [];
        $this->invalidClaim(fn () => $this->declaration($e, $m));
    }

    public function test_resource_limits_strict_nested_shape_and_malformed_uuids(): void
    {
        $this->grants();
        $e = $this->submit();
        $m = $this->manifest();
        $m['nodes'] = array_fill(0, 501, $m['nodes'][0]);
        $this->invalidClaim(fn () => $this->declaration($e, $m));
        $m = $this->manifest();
        $m['nodes'][0]['id'] = 'invalid';
        $this->invalidClaim(fn () => $this->declaration($e, $m));
        $m = $this->manifest();
        $m['nodes'] = ['not-list' => $m['nodes'][0]];
        $this->invalidClaim(fn () => $this->declaration($e, $m));
        $m = $this->manifest();
        unset($m['nodes'][0]['parent_id']);
        $this->invalidClaim(fn () => $this->declaration($e, $m));
    }

    public function test_exact_current_author_only_and_stale_digest_version_or_duplicate_fail(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->invalidClaim(fn () => $this->declaration($e, ['expected_proposal_digest' => str_repeat('a', 64)]));
        $this->invalidClaim(fn () => $this->declaration($e, ['expected_proposal_version' => 2]));
        $this->denied(fn () => $this->declaration($e, actor: $this->reviewer()));
        $this->declaration($e);
        $this->invalidClaim(fn () => $this->declaration($e));
        $this->assertSame(1, $this->countRows('scope_structure_declarations'));
    }

    public function test_narrative_opinion_before_structure_forces_a_new_proposal_version(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->review($e);
        $this->invalidClaim(fn () => $this->declaration($e));
        $n = $this->submit(['expected_version' => 1]);
        $d = $this->declaration($n);
        $this->assertSame(2, $d['version']);
        $this->assertFalse($this->inspectStructure($d['id'])['technical_review_bound']);
    }

    public function test_structure_before_narrative_opinion_blocks_old_review_api_and_raw_sql(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->declaration($e);
        $this->invalidClaim(fn () => $this->review($e));
        $r = ['id' => (string) Str::uuid(), 'project_id' => $this->project()->id, 'company_id' => $this->reader->company_id, 'proposal_id' => $e['id'], 'reviewer_id' => $this->reviewer()->id, 'proposal_version' => 1, 'proposal_digest' => $e['digest'], 'outcome' => 'COMMENTED', 'reason' => 'Cannot misbind narrative review'];
        $this->assertSqlState('55000', fn () => $this->asPars(fn () => DB::transaction(fn () => DB::table('scope_proposal_reviews')->insert($r))));
        $this->assertSame(0, $this->countRows('scope_proposal_reviews'));
    }

    public function test_new_version_preserves_old_claim_and_never_reuses_it_as_current(): void
    {
        $this->grants();
        $e = $this->submit();
        $d = $this->declaration($e);
        $n = $this->submit(['expected_version' => 1]);
        $this->invalidClaim(fn () => $this->declaration($e));
        $next = $this->declaration($n);
        $old = $this->inspectStructure($d['id']);
        $fresh = $this->inspectStructure($next['id']);
        $this->assertFalse($old['is_latest']);
        $this->assertSame($d['id'], $fresh['previous_id']);
        $this->assertSame(2, $fresh['version']);
        $this->assertNotSame($old['digest'], $fresh['digest']);
    }

    public function test_independent_node_visibility_required_for_read_and_write_not_just_source_rows(): void
    {
        $this->grants();
        $e = $this->submit();
        $d = $this->declaration($e);
        DB::unprepared('CREATE POLICY structure_hide ON facilities AS RESTRICTIVE FOR SELECT USING(false)');
        try {
            try {
                $this->inspectStructure($d['id']);
                $this->fail('Expected hidden structure');
            } catch (HttpException $x) {
                $this->assertSame(404, $x->getStatusCode());
            }
            $n = $this->submit(['expected_version' => 1]);
            $this->invalidClaim(fn () => $this->declaration($n));
        } finally {
            DB::unprepared('DROP POLICY structure_hide ON facilities');
        }
    }

    public function test_hidden_partial_source_and_revoked_nomination_block_no_payload_leak(): void
    {
        $this->grants();
        $e = $this->submit();
        $d = $this->declaration($e);
        $id = $this->asPars(fn () => DB::table('accepted_mto_rows')->where('accepted_mto_batch_id', $this->batch->id)->value('id'));
        DB::unprepared("CREATE POLICY structure_source_hide ON accepted_mto_rows AS RESTRICTIVE FOR SELECT USING(id<>'{$id}'::uuid)");
        try {
            try {
                $this->inspectStructure($d['id']);
                $this->fail('Expected hidden');
            } catch (HttpException $x) {
                $this->assertSame(404, $x->getStatusCode());
            }
        } finally {
            DB::unprepared('DROP POLICY structure_source_hide ON accepted_mto_rows');
        }
        $this->configure(['version' => 1, 'suspended' => true, 'selectors' => []]);
        try {
            $this->inspectStructure($d['id']);
            $this->fail('Expected revoked');
        } catch (HttpException $x) {
            $this->assertSame(404, $x->getStatusCode());
        }
    }

    public function test_archive_readonly_company_boundary_rls_and_immutable_history(): void
    {
        $this->grants();
        $e = $this->submit();
        $d = $this->declaration($e);
        $this->assertSame(0, $this->tenant->runAs($this->owner->company_id, fn () => DB::table('scope_structure_declarations')->count(), $this->project()->id));
        $this->assertSame(0, $this->asPars(fn () => DB::table('scope_structure_declarations')->update(['digest' => str_repeat('a', 64)])));
        $this->assertSame(0, $this->asPars(fn () => DB::table('scope_structure_declarations')->delete()));
        app(ProjectArchiveService::class)->archive($this->project(), $this->owner, 'Structure fixture');
        $this->assertFalse($this->inspectStructure($d['id'])['ready_to_admit']);
        $this->invalidClaim(fn () => $this->declaration($e));
    }

    public function test_transaction_rollback_and_populated_migration_preserve_existing_observations(): void
    {
        $this->grants();
        $e = $this->submit();
        $before = $this->asPars(fn () => DB::table('mto_scope_observations')->orderBy('id')->get()->toJson());
        try {
            DB::transaction(function () use ($e) {
                $this->declaration($e);
                throw new \RuntimeException('rollback declaration');
            });
        } catch (\RuntimeException $x) {
            $this->assertSame('rollback declaration', $x->getMessage());
        }$this->assertSame(0, $this->countRows('scope_structure_declarations'));
        $reviews = require database_path('migrations/2026_10_25_100000_add_scope_structure_reviews.php');
        $reviews->down();
        $m = require database_path('migrations/2026_10_24_120000_add_scope_structure_declarations.php');
        try {
            $m->down();
            $m->up();
        } finally {
            $reviews->up();
        }
        $this->assertSame($before, $this->asPars(fn () => DB::table('mto_scope_observations')->orderBy('id')->get()->toJson()));
        $this->assertSame(0, $this->countRows('scope_structure_declarations'));
        $this->assertFalse($this->declaration($e)['ready_to_admit']);
    }

    public function test_two_connections_serialize_both_orders_of_declaration_opinion_cutoff(): void
    {
        $this->grants();
        $e = $this->submit();
        $m = $this->manifest();
        $pdo = new \PDO('pgsql:host='.config('database.connections.pgsql.host').';port='.config('database.connections.pgsql.port').';dbname='.config('database.connections.pgsql.database'), config('database.connections.pgsql.username'), config('database.connections.pgsql.password'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $bind = function () use ($pdo): void {
            $pdo->prepare("SELECT set_config('epcc.company_id',?,true),set_config('epcc.project_id',?,true),set_config('lock_timeout','150ms',true)")->execute([$this->reader->company_id, $this->project()->id]);
        };
        $d = ['id' => (string) Str::uuid(), 'project_id' => $this->project()->id, 'company_id' => $this->reader->company_id, 'proposal_id' => $e['id'], 'declared_by' => $this->reader->id, 'version' => 1, 'proposal_digest' => $e['digest'], 'nodes' => json_encode($m['nodes']), 'coverage' => json_encode($m['coverage'])];
        $sql = 'INSERT INTO scope_structure_declarations ('.implode(',', array_keys($d)).') VALUES ('.implode(',', array_fill(0, count($d), '?')).')';
        DB::transaction(function () use ($e, $pdo, $bind, $sql, $d) {
            $this->review($e);
            $pdo->beginTransaction();
            $bind();
            try {
                $this->assertSqlState('55P03', fn () => $pdo->prepare($sql)->execute(array_values($d)));
            } finally {
                $pdo->rollBack();
            }
        });
        $pdo->beginTransaction();
        $bind();
        try {
            $this->assertSqlState('23514', fn () => $pdo->prepare($sql)->execute(array_values($d)));
        } finally {
            $pdo->rollBack();
        }
        $n = $this->submit(['expected_version' => 1]);
        $r = ['id' => (string) Str::uuid(), 'project_id' => $this->project()->id, 'company_id' => $this->reader->company_id, 'proposal_id' => $n['id'], 'reviewer_id' => $this->reviewer()->id, 'proposal_version' => 2, 'proposal_digest' => $n['digest'], 'outcome' => 'COMMENTED', 'reason' => 'Not a structure-bound review'];
        $sql = 'INSERT INTO scope_proposal_reviews ('.implode(',', array_keys($r)).') VALUES ('.implode(',', array_fill(0, count($r), '?')).')';
        DB::transaction(function () use ($n, $pdo, $bind, $sql, $r) {
            $this->declaration($n);
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
            $this->assertSqlState('55000', fn () => $pdo->prepare($sql)->execute(array_values($r)));
        } finally {
            $pdo->rollBack();
        }$this->assertSame(1, $this->countRows('scope_structure_declarations'));
        $this->assertSame(1, $this->countRows('scope_proposal_reviews'));
    }

    public function test_raw_sql_does_not_bypass_shape_source_or_positive_state_checks(): void
    {
        $this->grants();
        $e = $this->submit();
        $m = $this->manifest();
        $d = ['id' => (string) Str::uuid(), 'project_id' => $this->project()->id, 'company_id' => $this->reader->company_id, 'proposal_id' => $e['id'], 'declared_by' => $this->reader->id, 'version' => 1, 'proposal_digest' => $e['digest'], 'nodes' => json_encode($m['nodes']), 'coverage' => '[]'];
        $this->assertSqlState('23514', fn () => $this->asPars(fn () => DB::transaction(fn () => DB::table('scope_structure_declarations')->insert($d))));
        $d['coverage'] = json_encode($m['coverage']);
        $d['state'] = 'ADMITTED';
        $this->assertSqlState('23514', fn () => $this->asPars(fn () => DB::transaction(fn () => DB::table('scope_structure_declarations')->insert($d))));
        $this->assertSame(0, $this->countRows('scope_structure_declarations'));
    }
}
