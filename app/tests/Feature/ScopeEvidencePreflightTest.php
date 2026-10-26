<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcceptedMtoBatch;
use App\Models\Company;
use App\Models\Document;
use App\Models\MtoDocument;
use App\Models\MtoRevision;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\DemandReaderService;
use App\Services\DocumentFileStore;
use App\Services\DocumentRegister;
use App\Services\MtoAcceptanceService;
use App\Services\MtoStagingService;
use App\Services\ProjectArchiveService;
use App\Services\ScopeAdmissionAuthorityService;
use App\Services\ScopeEvidencePreflightService;
use App\Services\ScopeProposalEnvelopeService;
use App\Services\ScopeStructureDeclarationService;
use App\Services\ScopeStructureReviewService;
use App\Support\CdeState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real PostgreSQL observations are not engineering admission. */
final class ScopeEvidencePreflightTest extends TestCase
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

    private function evidenceFixture(bool $published = true): array
    {
        return $this->asPars(function () use ($published) {
            $doc = app(DocumentRegister::class)->register($this->project(), $this->reader, ['discipline' => 'PIPING', 'doc_type' => 'DWG', 'title_en' => 'Synthetic preflight only', 'title_fa' => 'شاهد آزمایشی']);
            $f = app(DocumentFileStore::class)->attach($doc, $this->reader, UploadedFile::fake()->createWithContent('evidence.pdf', '%PDF-1.4 '.Str::uuid()));
            if ($published) {
                app(DocumentRegister::class)->transition($doc->fresh(), $this->reader, CdeState::SHARED, 'S2');
                app(DocumentRegister::class)->transition($doc->fresh(), $this->reader, CdeState::PUBLISHED, 'A1');
            }

            return [['document_id' => $doc->id, 'revision_id' => $f->revision_id, 'file_id' => $f->id, 'sha256' => $f->sha256], $f, $doc->fresh()];
        });
    }

    private function readyCandidate(bool $published = true): array
    {
        Storage::fake('local');
        $this->grants();
        $d = $this->declaration($this->submit());
        [$a,$fa] = $this->evidenceFixture($published);
        [$b,$fb] = $this->evidenceFixture($published);

        return [$d, ['topology' => $a, 'coverage' => $b], [$fa, $fb]];
    }

    private function preflight(array $d, array $refs, array $extra = [], ?User $actor = null): array
    {
        return app(ScopeEvidencePreflightService::class)->inspect($this->project(), $actor ?? $this->reader, array_replace(['declaration_id' => $d['id'],
            'expected_declaration_version' => $d['version'], 'expected_declaration_digest' => $d['digest'], 'expected_proposal_id' => $d['proposal_id'],
            'expected_proposal_version' => $d['version'], 'expected_proposal_digest' => $d['proposal_digest'], 'references' => $refs], $extra));
    }

    private function opaque(callable $f): void
    {
        try {
            $f();
            $this->fail('Expected opaque refusal');
        } catch (HttpException $x) {
            $this->assertSame(404, $x->getStatusCode());
        }
    }

    public function test_full_original_caller_byte_check_is_not_attachment_package_identity_or_admission(): void
    {
        [$d,$refs] = $this->readyCandidate();
        $before = $this->asPars(fn () => DB::table('mto_scope_observations')->orderBy('id')->get()->toJson());
        $r = $this->preflight($d, $refs);
        $this->assertSame('BYTES_CHECKED_UNRETAINED', $r['status']);
        $this->assertTrue($r['all_evidence_bytes_verified']);
        $this->assertSame($d['digest'], $r['declaration_digest']);
        $this->assertSame($d['proposal_digest'], $r['proposal_digest']);
        foreach (['package_persisted', 'package_identity_issued', 'evidence_attached', 'evidence_relationship_verified', 'evidence_validity_verified', 'retention_verified', 'package_verified', 'engineering_approval', 'ready_to_admit', 'operationally_authorized'] as $key) {
            $this->assertFalse($r[$key]);
        }
        $this->assertSame('UNADMITTED', $r['admission_state']);
        $this->assertNull($r['gross']);
        foreach ($r['evidence'] as $role => $e) {
            $this->assertSame($refs[$role]['file_id'], $e['source']['file_id']);
            $this->assertArrayNotHasKey('storage_path', $e['source']);
            $this->assertArrayNotHasKey('disk', $e['source']);
        }
        $this->assertSame($before, $this->asPars(fn () => DB::table('mto_scope_observations')->orderBy('id')->get()->toJson()));
        $this->assertArrayNotHasKey('package_id', $r);
        $this->assertArrayNotHasKey('digest', $r);
    }

    public function test_review_nomination_never_grants_cde_read_and_denial_audit_commits_before_refusal(): void
    {
        [$d,$refs] = $this->readyCandidate();
        Storage::shouldReceive('disk')->never();
        $this->opaque(fn () => $this->preflight($d, $refs, actor: $this->reviewer()));
        $this->assertSame(1, DB::table('authorization_denials')->where('user_id', $this->reviewer()->id)->where('capability', 'doc.view')->count());
    }

    public function test_all_references_are_authorized_before_any_bytes_and_partial_dependency_is_opaque(): void
    {
        [$d,$refs] = $this->readyCandidate();
        $hidden = $refs['coverage']['file_id'];
        DB::unprepared("CREATE POLICY preflight_hide ON document_files AS RESTRICTIVE FOR SELECT USING(id<>'{$hidden}'::uuid)");
        Storage::shouldReceive('disk')->never();
        try {
            $this->opaque(fn () => $this->preflight($d, $refs));
        } finally {
            DB::unprepared('DROP POLICY preflight_hide ON document_files');
        }
    }

    public function test_actual_cde_discipline_scope_is_enforced_not_the_mto_nomination_scope(): void
    {
        [$d,$refs] = $this->readyCandidate();
        $this->asPars(fn () => ProjectMembership::where('project_id', $this->project()->id)->where('user_id', $this->reader->id)->update(['discipline_scope' => ['CIVIL']]));
        Storage::shouldReceive('disk')->never();
        $this->opaque(fn () => $this->preflight($d, $refs));
    }

    public function test_cached_actor_and_loaded_membership_cannot_keep_revoked_cde_capability(): void
    {
        [$d,$refs] = $this->readyCandidate();
        $this->asPars(fn () => $this->reader->load('company.plan', 'memberships'));
        $this->asPars(fn () => ProjectMembership::where('project_id', $this->project()->id)->where('user_id', $this->reader->id)->update(['role_codes' => ['qc_inspector']]));
        Storage::shouldReceive('disk')->never();
        $this->opaque(fn () => $this->preflight($d, $refs));
    }

    public function test_unknown_uri_actor_state_and_nested_fields_or_incomplete_roles_are_rejected(): void
    {
        [$d,$refs] = $this->readyCandidate();
        Storage::shouldReceive('disk')->never();
        foreach (['uri' => 'https://hidden.invalid', 'actor_id' => $this->owner->id, 'state' => 'ADMITTED', 'evidence_verified' => true] as $k => $v) {
            $this->invalidClaim(fn () => $this->preflight($d, $refs, [$k => $v]));
        }
        $bad = $refs;
        $bad['coverage']['storage_path'] = '../.env';
        $this->invalidClaim(fn () => $this->preflight($d, $bad));
        unset($bad['coverage']);
        $this->invalidClaim(fn () => $this->preflight($d, $bad));
    }

    public function test_every_exact_pair_precondition_and_supersession_is_checked_before_storage(): void
    {
        [$d,$refs] = $this->readyCandidate();
        Storage::shouldReceive('disk')->never();
        foreach (['expected_declaration_digest' => str_repeat('a', 64), 'expected_proposal_digest' => str_repeat('a', 64), 'expected_declaration_version' => 2, 'expected_proposal_version' => 2, 'expected_proposal_id' => (string) Str::uuid()] as $k => $v) {
            $this->invalidClaim(fn () => $this->preflight($d, $refs, [$k => $v]));
        }$this->submit(['expected_version' => 1]);
        $this->invalidClaim(fn () => $this->preflight($d, $refs));
    }

    public function test_wrong_exact_tuple_and_hidden_ancestor_fail_before_any_storage(): void
    {
        [$d,$refs] = $this->readyCandidate();
        Storage::shouldReceive('disk')->never();
        $bad = $refs;
        $bad['coverage']['revision_id'] = $refs['topology']['revision_id'];
        $this->opaque(fn () => $this->preflight($d, $bad));
        DB::unprepared('CREATE POLICY preflight_hide ON facilities AS RESTRICTIVE FOR SELECT USING(false)');
        try {
            $this->opaque(fn () => $this->preflight($d, $refs));
        } finally {
            DB::unprepared('DROP POLICY preflight_hide ON facilities');
        }
    }

    public function test_wrong_hash_missing_or_tampered_bytes_return_no_partial_provenance(): void
    {
        [$d,$refs,$files] = $this->readyCandidate();
        $bad = $refs;
        $bad['coverage']['sha256'] = str_repeat('a', 64);
        $r = $this->preflight($d, $bad);
        $this->assertSame('BLOCKED', $r['status']);
        $this->assertSame([], $r['evidence']);
        Storage::disk('local')->put($files[1]->storage_path, str_repeat('X', $files[1]->size_bytes));
        $this->assertSame([], $this->preflight($d, $refs)['evidence']);
        Storage::disk('local')->delete($files[1]->storage_path);
        $this->assertSame([], $this->preflight($d, $refs)['evidence']);
    }

    public function test_wip_and_shared_file_purposes_never_certify_immutability_or_relationship(): void
    {
        [$d,$refs] = $this->readyCandidate(false);
        $refs['coverage'] = $refs['topology'];
        $r = $this->preflight($d, $refs);
        $this->assertTrue($r['all_evidence_bytes_verified']);
        $this->assertFalse($r['retention_verified']);
        $this->assertFalse($r['evidence_relationship_verified']);
        $this->assertContains('REVISION_CONTENT_MUTABLE', $r['evidence']['coverage']['findings']);
    }

    public function test_archived_exact_revision_not_current_substitution_and_archived_project_is_readable(): void
    {
        [$d,$refs,$files] = $this->readyCandidate();
        $this->asPars(function () use ($refs) {
            $doc = Document::findOrFail($refs['topology']['document_id']);
            app(DocumentRegister::class)->issueRevision($doc, $this->reader, 'B');
        });
        app(ProjectArchiveService::class)->archive($this->project(), $this->owner, 'Read-only preflight fixture');
        $r = $this->preflight($d, $refs);
        $this->assertSame(CdeState::ARCHIVED, $r['evidence']['topology']['source']['cde_state']);
        $this->assertSame($files[0]->revision_id, $r['evidence']['topology']['source']['revision_id']);
        $this->assertFalse($r['package_identity_issued']);
    }

    public function test_existing_pair_bound_opinion_is_not_upgraded_and_diagnostic_writes_no_domain_records(): void
    {
        [$d,$refs] = $this->readyCandidate();
        $r = app(ScopeStructureReviewService::class)->review($this->project(), $this->reviewer(), ['declaration_id' => $d['id'], 'expected_declaration_version' => $d['version'], 'expected_declaration_digest' => $d['digest'], 'expected_proposal_id' => $d['proposal_id'], 'expected_proposal_version' => $d['version'], 'expected_proposal_digest' => $d['proposal_digest'], 'outcome' => 'COMMENTED', 'reason' => 'Not evidence-bound']);
        DB::enableQueryLog();
        $this->preflight($d, $refs);
        $q = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $sql = strtolower(implode(' ', array_column($q, 'query')));
        foreach (['insert into', 'update "', 'delete from', '"material_lots"', '"material_movements"'] as $token) {
            $this->assertStringNotContainsString($token, $sql);
        }
        $after = app(ScopeStructureReviewService::class)->inspect($this->project(), $this->reader, $r['id']);
        $this->assertSame($r['digest'], $after['digest']);
        $this->assertFalse($after['evidence_verified']);
        $this->assertFalse($after['engineering_approval']);
        $this->assertFalse($this->inspectStructure($d['id'])['evidence_attached']);
    }

    private function streamHook(array $files, callable $hook): void
    {
        $disk = Storage::disk('local');
        $paths = [];
        foreach ($files as $f) {
            $paths[$f->storage_path] = $disk->path($f->storage_path);
        }$mock = \Mockery::mock();
        $mock->shouldReceive('readStream')->twice()->andReturnUsing(function (string $canonical) use ($paths, $files, $hook) {
            if ($canonical === $files[1]->storage_path) {
                $hook();
            }

            return fopen($paths[$canonical], 'rb');
        });
        Storage::shouldReceive('disk')->with('local')->twice()->andReturn($mock);
    }

    public function test_cde_revocation_during_second_file_read_discards_all_provenance(): void
    {
        [$d,$refs,$files] = $this->readyCandidate();
        $this->streamHook($files, fn () => ProjectMembership::where('project_id', $this->project()->id)->where('user_id', $this->reader->id)->update(['role_codes' => ['qc_inspector']]));
        $this->opaque(fn () => $this->preflight($d, $refs));
    }

    public function test_first_file_locator_drift_during_second_file_read_discards_all_provenance(): void
    {
        [$d,$refs,$files] = $this->readyCandidate();
        $this->streamHook($files, fn () => DB::table('document_files')->where('id', $files[0]->id)->update(['storage_path' => '../not-a-valid-retained-object']));
        $r = $this->preflight($d, $refs);
        $this->assertSame('BLOCKED', $r['status']);
        $this->assertSame([], $r['evidence']);
        $this->assertFalse($r['all_evidence_bytes_verified']);
    }

    public function test_independent_cde_entitlement_required_even_when_procurement_plan_still_valid(): void
    {
        [$d,$refs] = $this->readyCandidate();
        $this->asPars(function () {
            $plan = $this->reader->company->plan;
            Plan::whereKey($plan->id)->update(['modules' => ['core', 'proc']]);
        });
        Storage::shouldReceive('disk')->never();
        $this->opaque(fn () => $this->preflight($d, $refs));
    }

    public function test_foreign_private_cde_is_hidden_but_normal_published_visibility_is_not_widened(): void
    {
        [$d,$refs] = $this->readyCandidate();
        [$foreign,$doc] = $this->tenant->runAs($this->owner->company_id, function () {
            $doc = app(DocumentRegister::class)->register($this->project(), $this->owner, ['discipline' => 'PIPING', 'doc_type' => 'DWG', 'title_en' => 'Foreign synthetic evidence', 'title_fa' => 'شاهد آزمایشی شرکت دیگر']);
            $f = app(DocumentFileStore::class)->attach($doc, $this->owner, UploadedFile::fake()->createWithContent('foreign.pdf', '%PDF-1.4 synthetic foreign bytes'));

            return [['document_id' => $doc->id, 'revision_id' => $f->revision_id, 'file_id' => $f->id, 'sha256' => $f->sha256], $doc];
        }, $this->project()->id);
        $refs['coverage'] = $foreign;
        $manager = Storage::getFacadeRoot();
        Storage::shouldReceive('disk')->never();
        $this->opaque(fn () => $this->preflight($d, $refs));
        Storage::swap($manager);
        $this->tenant->runAs($this->owner->company_id, function () use ($doc) {
            app(DocumentRegister::class)->transition($doc->fresh(), $this->owner, CdeState::SHARED, 'S2');
            app(DocumentRegister::class)->transition($doc->fresh(), $this->owner, CdeState::PUBLISHED, 'A1');
        }, $this->project()->id);
        $r = $this->preflight($d, $refs);
        $this->assertSame($foreign['file_id'], $r['evidence']['coverage']['source']['file_id']);
        $this->assertFalse($r['evidence_attached']);
    }
}
