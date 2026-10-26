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
final class ScopeProposalEnvelopeTest extends TestCase
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

    public function test_no_reader_or_action_defaults_and_no_configurator_inheritance(): void
    {
        $this->denied(fn () => $this->submit());
        $this->configure();
        $this->denied(fn () => $this->submit());
        $this->denied(fn () => $this->submit(actor: $this->owner));
        $this->assertSame(0, $this->countRows());
    }

    public function test_source_bound_canonical_envelope_has_real_actor_policies_and_remains_unverified(): void
    {
        $this->grants();
        $e = $this->submit();
        $r = $this->record($e['id']);
        $env = json_decode($r->envelope, true);
        $binding = json_decode($r->binding, true);
        $this->assertSame($this->reader->id, $env['proposer_id']);
        $this->assertSame($this->batch->id, $binding['batch_id']);
        $this->assertSame($this->scope, $binding['scope_id']);
        $this->assertSame($this->batch->mto_revision_id, $binding['revision_id']);
        $this->assertSame('UNVERIFIED_PROPOSAL', $r->state);
        $this->assertFalse($e['ready_to_admit']);
        $this->assertFalse($e['package_verified']);
        $this->assertFalse($e['operationally_authorized']);
        $this->assertNull($e['gross']);
        $hash = $this->asPars(fn () => DB::selectOne("SELECT digest=encode(sha256(convert_to(envelope::text,'UTF8')),'hex') AS ok FROM scope_proposal_envelopes WHERE id=?", [$e['id']]));
        $this->assertTrue($hash->ok);
        $this->assertNotEmpty($r->nomination_policy_id);
        $this->assertNotEmpty($r->reader_policy_id);
        $this->assertNotEmpty($r->observation_id);
    }

    public function test_client_identity_binding_hash_or_approved_state_cannot_be_supplied(): void
    {
        $this->grants();
        foreach (['proposer_id' => $this->owner->id, 'binding' => [], 'digest' => str_repeat('a', 64), 'state' => 'ADMITTED', 'nomination_policy_id' => (string) Str::uuid()] as $k => $v) {
            $this->invalid(fn () => $this->submit([$k => $v]), 'proposal');
        }$this->assertSame(0, $this->countRows());
    }

    public function test_independent_review_is_bound_to_exact_stored_author_version_and_digest(): void
    {
        $this->grants();
        $e = $this->submit();
        $r = $this->review($e);
        $review = $this->asPars(fn () => DB::table('scope_proposal_reviews')->where('id', $r['id'])->first());
        $this->assertSame($this->reviewer()->id, $review->reviewer_id);
        $this->assertSame($e['digest'], $review->proposal_digest);
        $this->assertSame('COMMENTED', $review->outcome);
        $this->assertFalse($r['ready_to_admit']);
        $this->assertFalse($r['package_verified']);
        $this->assertSame($this->reader->id, json_decode($this->record($e['id'])->envelope, true)['proposer_id']);
    }

    public function test_self_review_is_rejected_using_persisted_not_claimed_proposer(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->denied(fn () => $this->review($e, actor: $this->reader));
        $this->invalid(fn () => $this->review($e, ['proposer_id' => $this->owner->id]), 'review');
        $this->assertSame(0, $this->countRows('scope_proposal_reviews'));
    }

    public function test_review_is_never_approval_and_rejects_client_reviewer_and_positive_status(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->invalid(fn () => $this->review($e, ['outcome' => 'APPROVED']), 'review.outcome');
        $this->invalid(fn () => $this->review($e, ['reviewer_id' => $this->owner->id]), 'review');
        $r = $this->review($e, ['outcome' => 'CHANGES_REQUESTED']);
        $this->assertFalse($r['operationally_authorized']);
    }

    public function test_stale_version_digest_and_duplicate_review_fail_without_append(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->invalid(fn () => $this->submit(), 'expected_version');
        $this->invalid(fn () => $this->review($e, ['expected_version' => 2]), 'expected_version');
        $this->invalid(fn () => $this->review($e, ['expected_digest' => str_repeat('a', 64)]), 'expected_version');
        $this->review($e);
        $this->invalid(fn () => $this->review($e), 'expected_version');
        $this->assertSame(1, $this->countRows('scope_proposal_reviews'));
    }

    public function test_new_proposal_version_preserves_old_reviews_but_never_carries_them_forward(): void
    {
        $this->grants();
        $e = $this->submit();
        $r = $this->review($e);
        $new = $this->submit(['expected_version' => 1, 'statement' => 'Changed unverified narrative']);
        $this->assertSame(2, $new['version']);
        $this->assertNotSame($e['digest'], $new['digest']);
        $this->assertSame($e['id'], $this->record($new['id'])->previous_id);
        $this->invalid(fn () => $this->review($e), 'expected_version');
        $this->assertSame(1, $this->countRows('scope_proposal_reviews'));
        $this->review($new);
        $this->assertSame(2, $this->countRows('scope_proposal_reviews'));
        $this->assertFalse($new['ready_to_admit']);
    }

    public function test_hidden_source_is_unavailable_for_submission_and_review(): void
    {
        $this->grants();
        $e = $this->submit();
        DB::unprepared('CREATE POLICY dossier_hide ON accepted_mto_rows AS RESTRICTIVE FOR SELECT USING(false)');
        try {
            $this->denied(fn () => $this->submit(['expected_version' => 1]));
            $this->denied(fn () => $this->review($e));
        } finally {
            DB::unprepared('DROP POLICY dossier_hide ON accepted_mto_rows');
        }$this->assertSame(1, $this->countRows());
    }

    public function test_partial_row_visibility_cannot_bind_a_complete_package(): void
    {
        $this->grants();
        $e = $this->submit();
        $id = $this->asPars(fn () => DB::table('accepted_mto_rows')->where('accepted_mto_batch_id', $this->batch->id)->value('id'));
        DB::unprepared("CREATE POLICY dossier_partial ON accepted_mto_rows AS RESTRICTIVE FOR SELECT USING(id<>'{$id}'::uuid)");
        try {
            $this->denied(fn () => $this->submit(['expected_version' => 1]));
            $this->denied(fn () => $this->review($e));
        } finally {
            DB::unprepared('DROP POLICY dossier_partial ON accepted_mto_rows');
        }
        $this->assertSame(1, $this->countRows());
        $this->assertSame(0, $this->countRows('scope_proposal_reviews'));
    }

    public function test_current_reader_revocation_blocks_new_version_and_review(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->configure(['version' => 1, 'suspended' => true, 'selectors' => []]);
        $this->denied(fn () => $this->submit(['expected_version' => 1]));
        $this->denied(fn () => $this->review($e));
    }

    public function test_action_revocation_is_current_and_never_falls_back(): void
    {
        $this->grants();
        $e = $this->submit();
        foreach (['propose', 'review'] as $a) {
            app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['version' => 1, 'suspended' => true, 'selectors' => []]) + ['batch_id' => $this->batch->id, 'action' => $a]);
        }
        $this->denied(fn () => $this->submit(['expected_version' => 1]));
        $this->denied(fn () => $this->review($e));
    }

    public function test_company_boundary_and_owner_configuration_do_not_grant_dossier_reading(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->assertSame(0, $this->tenant->runAs($this->owner->company_id, fn () => DB::table('scope_proposal_envelopes')->count(), $this->project()->id));
        try {
            $this->review($e, actor: $this->owner);
            $this->fail('Expected not found');
        } catch (HttpException $x) {
            $this->assertSame(404, $x->getStatusCode());
        }
    }

    public function test_archive_blocks_dossier_writes(): void
    {
        $this->grants();
        $e = $this->submit();
        app(ProjectArchiveService::class)->archive($this->project(), $this->owner, 'Archive dossier fixture');
        foreach ([fn () => $this->submit(['expected_version' => 1]), fn () => $this->review($e)] as $f) {
            try {
                $f();
                $this->fail('Expected archive');
            } catch (ValidationException $x) {
                $this->assertNotEmpty($x->errors());
            }
        }
    }

    public function test_history_is_immutable_and_all_new_tables_force_rls(): void
    {
        $this->grants();
        $e = $this->submit();
        $this->review($e);
        foreach (['scope_proposal_envelopes', 'scope_proposal_reviews'] as $t) {
            $this->assertSame(0, $this->asPars(fn () => DB::table($t)->update(['digest' => str_repeat('a', 64)])));
            $this->assertSame(0, $this->asPars(fn () => DB::table($t)->delete()));
            $f = DB::selectOne('SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE relname=?', [$t]);
            $this->assertTrue($f->relrowsecurity);
            $this->assertTrue($f->relforcerowsecurity);
        }
    }

    public function test_rollback_of_transaction_leaves_no_proposal_or_review(): void
    {
        $this->grants();
        try {
            DB::transaction(function () {
                $e = $this->submit();
                $this->review($e);
                throw new \RuntimeException('rollback fixture');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback fixture', $e->getMessage());
        }$this->assertSame(0, $this->countRows());
        $this->assertSame(0, $this->countRows('scope_proposal_reviews'));
    }

    public function test_populated_upgrade_never_rewrites_observations_or_creates_envelopes(): void
    {
        $this->grants();
        $before = $this->asPars(fn () => DB::table('mto_scope_observations')->orderBy('id')->get()->toJson());
        // Roll back the newer dependent declaration FK before testing this exact parent migration.
        $reviews = require database_path('migrations/2026_10_25_100000_add_scope_structure_reviews.php');
        $reviews->down();
        $child = require database_path('migrations/2026_10_24_120000_add_scope_structure_declarations.php');
        $child->down();
        $m = require database_path('migrations/2026_10_24_110000_add_scope_proposal_envelopes.php');
        try {
            $m->down();
            $m->up();
        } finally {
            $child->up();
            $reviews->up();
        }
        $this->assertSame($before, $this->asPars(fn () => DB::table('mto_scope_observations')->orderBy('id')->get()->toJson()));
        $this->assertSame(0, $this->countRows());
        $this->assertFalse($this->submit()['ready_to_admit']);
    }

    private function rawProposal(array $change = []): array
    {
        return array_replace(['id' => (string) Str::uuid(), 'project_id' => $this->project()->id, 'company_id' => $this->reader->company_id, 'proposer_id' => $this->reader->id,
            'batch_id' => $this->batch->id, 'document_id' => $this->batch->mto_document_id, 'scope_id' => $this->scope, 'hierarchy_revision_id' => 'H1', 'version' => 1, 'statement' => 'Raw SQL defensive test'], $change);
    }

    public function test_raw_sql_cannot_override_server_source_digest_or_policy_attribution(): void
    {
        $this->grants();
        $d = $this->rawProposal(['binding' => '{"fake":true}', 'envelope' => '{"fake":true}', 'digest' => str_repeat('a', 64)]);
        $this->asPars(fn () => DB::table('scope_proposal_envelopes')->insert($d));
        $e = $this->record($d['id']);
        $this->assertArrayNotHasKey('fake', json_decode($e->binding, true));
        $this->assertArrayNotHasKey('fake', json_decode($e->envelope, true));
        $this->assertNotSame(str_repeat('a', 64), $e->digest);
    }

    public function test_raw_sql_self_review_fake_digest_and_positive_state_are_rejected(): void
    {
        $this->grants();
        $e = $this->submit();
        $d = ['id' => (string) Str::uuid(), 'project_id' => $this->project()->id, 'company_id' => $this->reader->company_id, 'proposal_id' => $e['id'], 'reviewer_id' => $this->reader->id, 'proposal_version' => 1, 'proposal_digest' => $e['digest'], 'outcome' => 'COMMENTED', 'reason' => 'Cannot self review'];
        $this->assertSqlState('23514', fn () => $this->asPars(fn () => DB::transaction(fn () => DB::table('scope_proposal_reviews')->insert($d))));
        $d['reviewer_id'] = $this->reviewer()->id;
        $d['proposal_digest'] = str_repeat('a', 64);
        $this->assertSqlState('23514', fn () => $this->asPars(fn () => DB::transaction(fn () => DB::table('scope_proposal_reviews')->insert($d))));
        $this->assertSqlState('23514', fn () => $this->asPars(fn () => DB::transaction(fn () => DB::table('scope_proposal_envelopes')->insert($this->rawProposal(['version' => 2, 'previous_id' => $e['id'], 'state' => 'ADMITTED'])))));
        $this->assertSame(0, $this->countRows('scope_proposal_reviews'));
        $this->assertSame(1, $this->countRows());
    }

    public function test_multiscope_batch_is_blocked_not_a_partial_scope_certificate(): void
    {
        $other = $this->asPars(fn () => Subsystem::where('project_id', $this->project()->id)->where('id', '<>', $this->scope)->value('id'));
        $this->assertNotNull($other);
        $doc = $this->document();
        $rev = $this->asPars(fn () => $this->staging->createRevision($doc, $this->reader, 'A', [$this->row(['row_scope_id' => $this->scope]), $this->row(['mto_item_no' => '20', 'row_scope_id' => $other])], hierarchyRevisionId: 'H1', lang: 'en'));
        $this->batch = $this->acceptObserved($rev);
        $this->grants();
        $this->denied(fn () => $this->submit());
        $this->assertSame(0, $this->countRows());
    }

    public function test_inactive_reviewer_and_role_removal_do_not_use_a_historical_nomination(): void
    {
        $this->grants();
        $e = $this->submit();
        $u = $this->reviewer();
        $this->asPars(fn () => User::whereKey($u->id)->update(['is_active' => false]));
        $this->denied(fn () => $this->review($e));
        $this->asPars(fn () => User::whereKey($u->id)->update(['is_active' => true]));
        app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $this->owner, $this->input(['version' => 1, 'selectors' => ['role:'.$u->company_id.':qc_inspector']]) + ['batch_id' => $this->batch->id, 'action' => 'review']);
        $this->asPars(fn () => ProjectMembership::where('user_id', $u->id)->where('project_id', $this->project()->id)->update(['role_codes' => []]));
        $this->denied(fn () => $this->review($e));
    }

    public function test_proposal_commit_and_competing_write_serialize_without_duplicate_version(): void
    {
        $this->grants();
        $pdo = new \PDO('pgsql:host='.config('database.connections.pgsql.host').';port='.config('database.connections.pgsql.port').';dbname='.config('database.connections.pgsql.database'), config('database.connections.pgsql.username'), config('database.connections.pgsql.password'), [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $d = $this->rawProposal();
        $sql = 'INSERT INTO scope_proposal_envelopes ('.implode(',', array_keys($d)).') VALUES ('.implode(',', array_fill(0, count($d), '?')).')';
        $bind = function () use ($pdo): void {
            $pdo->prepare("SELECT set_config('epcc.company_id',?,true),set_config('epcc.project_id',?,true),set_config('lock_timeout','150ms',true)")->execute([$this->reader->company_id, $this->project()->id]);
        };
        DB::transaction(function () use ($pdo, $sql, $d, $bind) {
            $this->submit();
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
        }$this->assertSame(1, $this->countRows());
    }
}
