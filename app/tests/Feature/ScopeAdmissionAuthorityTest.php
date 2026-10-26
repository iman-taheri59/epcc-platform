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
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Real PostgreSQL observations are not engineering admission. */
final class ScopeAdmissionAuthorityTest extends TestCase
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
        $revision = $this->asPars(fn () => $this->staging->createRevision($doc, $this->reader, 'A', [$this->row(['row_scope_id' => $this->scope])], hierarchyRevisionId: 'H1', lang: 'en'));
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

    private function actionPolicy(string $action = 'propose', array $changes = [], ?User $admin = null): string
    {
        return app(ScopeAdmissionAuthorityService::class)->configure($this->project(), $admin ?? $this->owner, $this->input($changes) + ['batch_id' => $this->batch->id, 'action' => $action]);
    }

    private function source(): array
    {
        return ['batch_id' => $this->batch->id, 'document_id' => $this->batch->mto_document_id, 'scope_id' => $this->scope, 'hierarchy_revision_id' => 'H1'];
    }

    private function check(string $action = 'propose', ?string $proposer = null, ?string $reviewer = null, ?User $actor = null): array
    {
        return app(ScopeAdmissionAuthorityService::class)->preflight($this->project(), $actor ?? $this->reader, $this->source(), $action, $proposer, $reviewer);
    }

    private function policyHead(string $action = 'propose'): object
    {
        return $this->tenant->runAs($this->owner->company_id, fn () => DB::table('scope_admission_actor_policies')->where('action', $action)->orderByDesc('version')->first(), $this->project()->id);
    }

    public function test_reader_grant_and_admin_do_not_inherit_admission_actions(): void
    {
        $this->configure();
        $this->denied(fn () => $this->check());
        $this->denied(fn () => $this->check(actor: $this->owner));
    }

    public function test_action_nomination_does_not_create_independent_read_permission(): void
    {
        $this->actionPolicy();
        $this->denied(fn () => $this->check());
        $this->configure();
        $r = $this->check();
        $this->assertTrue($r['capability_preflight']);
        $this->assertFalse($r['ready_to_admit']);
        $this->assertSame('UNADMITTED', $r['admission_state']);
        $this->assertNull($r['gross']);
        $this->assertSame(0, $r['writes_performed']);
    }

    public function test_actions_are_independent_not_shared_or_defaulted(): void
    {
        $this->configure();
        $this->actionPolicy();
        $this->denied(fn () => $this->check('review', $this->owner->id));
        $this->denied(fn () => $this->check('record', $this->reader->id, $this->owner->id));
        $this->assertFalse($this->check()['ready_to_admit']);
    }

    public function test_nonowner_cannot_nominate(): void
    {
        $this->denied(fn () => $this->actionPolicy(admin: $this->reader));
    }

    public function test_action_policy_suspension_and_replacement_do_not_fallback(): void
    {
        $this->configure();
        $this->actionPolicy();
        $this->actionPolicy(changes: ['version' => 1, 'suspended' => true, 'selectors' => []]);
        $this->denied(fn () => $this->check());
    }

    public function test_suspension_is_source_free_for_recorded_key_only(): void
    {
        $this->actionPolicy();
        DB::unprepared('CREATE POLICY authority_hide ON accepted_mto_rows AS RESTRICTIVE FOR SELECT USING(false)');
        try {
            $this->actionPolicy(changes: ['version' => 1, 'suspended' => true, 'selectors' => [], 'batch_id' => null]);
            $this->assertTrue($this->policyHead()->suspended);
            $this->denied(fn () => $this->actionPolicy(changes: ['version' => 2]));
        } finally {
            DB::unprepared('DROP POLICY authority_hide ON accepted_mto_rows');
        }
    }

    public function test_role_loss_is_live_and_not_expanded_permanently(): void
    {
        $this->configure();
        $role = $this->asPars(fn () => ProjectMembership::where('project_id', $this->project()->id)->where('user_id', $this->reader->id)->firstOrFail()->role_codes[0]);
        $this->actionPolicy(changes: ['selectors' => ['role:'.$this->reader->company_id.':'.$role]]);
        $this->assertTrue($this->check()['capability_preflight']);
        $this->asPars(fn () => ProjectMembership::where('project_id', $this->project()->id)->where('user_id', $this->reader->id)->update(['role_codes' => []]));
        $this->denied(fn () => $this->check());
    }

    public function test_review_cannot_be_self_review_even_uuid_case_changed(): void
    {
        $this->configure();
        $this->actionPolicy('review');
        $this->denied(fn () => $this->check('review', $this->reader->id));
        $this->denied(fn () => $this->check('review', strtoupper($this->reader->id)));
        $this->assertTrue($this->check('review', $this->owner->id)['capability_preflight']);
    }

    public function test_record_requires_separate_grant_distinct_review_and_recorder_one_of_two(): void
    {
        $this->configure();
        $this->actionPolicy('review');
        $this->denied(fn () => $this->check('record', $this->reader->id, $this->owner->id));
        $this->actionPolicy('record');
        $this->denied(fn () => $this->check('record', $this->reader->id, $this->reader->id));
        $this->denied(fn () => $this->check('record', $this->owner->id, $this->userByEmail('tehrani@pmc.example')->id));
        $this->assertFalse($this->check('record', $this->reader->id, $this->owner->id)['ready_to_admit']);
        $this->assertFalse($this->check('record', $this->owner->id, $this->reader->id)['ready_to_admit']);
    }

    public function test_hidden_or_unknown_source_cannot_preflight(): void
    {
        $this->configure();
        $this->actionPolicy();
        DB::unprepared('CREATE POLICY authority_hide ON accepted_mto_rows AS RESTRICTIVE FOR SELECT USING(false)');
        try {
            $this->denied(fn () => $this->check());
        } finally {
            DB::unprepared('DROP POLICY authority_hide ON accepted_mto_rows');
        }
    }

    public function test_stale_policy_version_does_not_append(): void
    {
        $this->actionPolicy();
        try {
            $this->actionPolicy();
            $this->fail('Expected stale');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('version', $e->errors());
        }$this->assertSame(1, (int) $this->policyHead()->version);
    }

    public function test_ineligible_other_company_selector_is_rejected(): void
    {
        try {
            $this->actionPolicy(changes: ['selectors' => ['user:'.$this->owner->id]]);
            $this->fail('Expected invalid');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('selectors', $e->errors());
        }
    }

    public function test_read_revocation_still_blocks_with_live_admission_nomination(): void
    {
        $this->configure();
        $this->actionPolicy();
        $this->configure(['version' => 1, 'suspended' => true, 'selectors' => []]);
        $this->denied(fn () => $this->check());
    }

    public function test_archived_project_blocks_action_preflight_and_configuration(): void
    {
        $this->configure();
        $this->actionPolicy();
        app(ProjectArchiveService::class)->archive($this->project(), $this->owner, 'Admission fixture archive');
        foreach ([fn () => $this->check(), fn () => $this->actionPolicy(changes: ['version' => 1])] as $f) {
            try {
                $f();
                $this->fail('Expected archive');
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_force_rls_immutability_and_foreign_metadata(): void
    {
        $this->actionPolicy();
        $this->assertSame(0, $this->tenant->runAs($this->owner->company_id, fn () => DB::table('scope_admission_actor_policies')->update(['reason' => 'rewrite']), $this->project()->id));
        $third = $this->userByEmail('tehrani@pmc.example');
        $this->assertSame(0, $this->tenant->runAs($third->company_id, fn () => DB::table('scope_admission_actor_policies')->count(), $this->project()->id));
        $flags = DB::selectOne("SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE relname='scope_admission_actor_policies'");
        $this->assertTrue($flags->relrowsecurity);
        $this->assertTrue($flags->relforcerowsecurity);
    }

    public function test_populated_upgrade_no_default_authorities_or_observation_changes(): void
    {
        $before = $this->asPars(fn () => DB::table('mto_scope_observations')->orderBy('id')->get()->toJson());
        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, $this->asPars(fn () => DB::table('mto_scope_observations')->orderBy('id')->get()->toJson()));
        $this->configure();
        $this->denied(fn () => $this->check());
        $this->actionPolicy();
        $this->assertFalse($this->check()['ready_to_admit']);
    }

    public function test_preflight_is_readonly_no_cde_library_stock_or_admission_writes(): void
    {
        $this->configure();
        $this->actionPolicy();
        DB::enableQueryLog();
        $this->check();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $sql = strtolower(implode("\n", array_column($queries, 'query')));
        foreach (['insert into', 'delete from', 'update "', '"document_files"', '"material_items"', '"material_lots"', '"material_movements"'] as $token) {
            $this->assertStringNotContainsString($token, $sql);
        }
    }
}
