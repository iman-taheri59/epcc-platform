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
final class DemandReaderTest extends TestCase
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

    public function test_no_default_reader_or_configurator_inheritance(): void
    {
        $this->denied(fn () => $this->assess());
        $this->configure();
        $this->denied(fn () => $this->assess($this->owner));
    }

    public function test_explicit_reader_still_has_no_demand_capacity_or_authority(): void
    {
        $this->configure();
        $r = $this->assess();
        $this->assertFalse($r['ready']);
        $this->assertFalse($r['operationally_authorized']);
        $this->assertNull($r['gross']);
        $this->assertNull($r['remaining']);
        $this->assertSame('UNRESOLVED', $r['status']);
        $this->assertContains('EFFECTIVE_SELECTION_ADAPTER_NOT_IMPLEMENTED', $r['findings']);
    }

    public function test_nonowner_cannot_configure(): void
    {
        $this->denied(fn () => $this->configure([], $this->reader));
    }

    public function test_scope_document_and_hierarchy_are_exact_not_wildcards(): void
    {
        $this->configure();
        $this->denied(fn () => $this->assess(document: (string) Str::uuid()));
        $this->denied(fn () => $this->assess(scope: (string) Str::uuid()));
        $this->denied(fn () => $this->assess(hierarchy: 'H2'));
    }

    public function test_suspension_never_falls_back_to_old_grant(): void
    {
        $this->configure();
        $this->configure(['version' => 1, 'suspended' => true, 'selectors' => []]);
        $this->denied(fn () => $this->assess());
    }

    public function test_replacement_with_other_nominee_revokes_original_reader(): void
    {
        $this->configure();
        $this->configure(['version' => 1, 'selectors' => ['user:'.$this->userByEmail('sadeghi@pars.example')->id]]);
        $this->denied(fn () => $this->assess());
    }

    public function test_policy_version_conflict_does_not_append(): void
    {
        $this->configure();
        try {
            $this->configure();
            $this->fail('Expected stale');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('version', $e->errors());
        }$this->assertSame(1, $this->tenant->runAs($this->owner->company_id, fn () => DB::table('demand_reader_policies')->count(), $this->project()->id));
    }

    public function test_identity_and_membership_are_fresh(): void
    {
        $this->configure();
        $this->asPars(fn () => DB::table('users')->where('id', $this->reader->id)->update(['is_active' => false]));
        $this->denied(fn () => $this->assess());
    }

    public function test_discipline_loss_is_fresh(): void
    {
        $this->configure();
        $this->asPars(fn () => ProjectMembership::where('user_id', $this->reader->id)->where('project_id', $this->project()->id)->update(['discipline_scope' => ['CIVIL']]));
        $this->denied(fn () => $this->assess());
    }

    public function test_company_scoped_role_membership_is_fresh(): void
    {
        $role = $this->asPars(fn () => ProjectMembership::where('project_id', $this->project()->id)->where('user_id', $this->reader->id)->firstOrFail()->role_codes[0]);
        $this->configure(['selectors' => ['role:'.$this->reader->company_id.':'.$role]]);
        $this->assertFalse($this->assess()['ready']);
        $this->asPars(fn () => ProjectMembership::where('project_id', $this->project()->id)->where('user_id', $this->reader->id)->update(['role_codes' => []]));
        $this->denied(fn () => $this->assess());
    }

    public function test_hidden_original_source_is_not_unlocked_by_nomination(): void
    {
        $this->configure();
        DB::unprepared('CREATE POLICY demand_test_hide ON accepted_mto_rows AS RESTRICTIVE FOR SELECT USING(false)');
        try {
            $r = $this->assess();
            $this->assertSame('UNAVAILABLE', $r['status']);
            $this->assertNull($r['gross']);
            $this->assertStringNotContainsString($this->batch->id, json_encode($r));
        } finally {
            DB::unprepared('DROP POLICY demand_test_hide ON accepted_mto_rows');
        }
    }

    public function test_archive_remains_readable_but_configuration_is_blocked(): void
    {
        $this->configure();
        app(ProjectArchiveService::class)->archive($this->project(), $this->owner, 'Synthetic read archive');
        $this->assertFalse($this->assess()['ready']);
        try {
            $this->configure(['version' => 1]);
            $this->fail('Expected archived');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_policy_is_immutable_and_force_rls(): void
    {
        $id = $this->configure();
        $this->assertSame(0, $this->tenant->runAs($this->owner->company_id, fn () => DB::table('demand_reader_policies')->where('id', $id)->update(['reason' => 'rewrite']), $this->project()->id));
        $flags = DB::selectOne("SELECT relrowsecurity,relforcerowsecurity FROM pg_class WHERE relname='demand_reader_policies'");
        $this->assertTrue($flags->relrowsecurity);
        $this->assertTrue($flags->relforcerowsecurity);
    }

    public function test_invalid_selector_cannot_be_used_as_new_role_or_cross_company_grant(): void
    {
        try {
            $this->configure(['selectors' => ['user:'.$this->owner->id]]);
            $this->fail('Expected invalid');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('selectors', $e->errors());
        }
    }

    public function test_unknown_batch_is_generic_unavailable_not_zero(): void
    {
        $this->configure();
        $r = $this->assess(batch: (string) Str::uuid());
        $this->assertSame('UNAVAILABLE', $r['status']);
        $this->assertNull($r['remaining']);
    }

    public function test_configuration_requires_original_visible_exact_source(): void
    {
        $this->denied(fn () => $this->configure(['hierarchy_revision_id' => 'H2']));
        $this->denied(fn () => $this->configure(['document_id' => (string) Str::uuid()]));
        DB::unprepared('CREATE POLICY demand_test_hide ON accepted_mto_rows AS RESTRICTIVE FOR SELECT USING(false)');
        try {
            $this->denied(fn () => $this->configure());
        } finally {
            DB::unprepared('DROP POLICY demand_test_hide ON accepted_mto_rows');
        }
    }

    public function test_target_company_participation_loss_revokes_reader(): void
    {
        $this->configure();
        DB::table('project_company')->where('project_id', $this->project()->id)->where('company_id', $this->reader->company_id)->delete();
        $this->denied(fn () => $this->assess());
    }

    public function test_install_on_populated_source_does_not_seed_grants_or_change_snapshots(): void
    {
        $before = $this->asPars(fn () => DB::table('accepted_mto_rows')->orderBy('id')->get()->toJson());
        $this->artisan('migrate:rollback', ['--step' => 1, '--force' => true])->assertExitCode(0);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, $this->asPars(fn () => DB::table('accepted_mto_rows')->orderBy('id')->get()->toJson()));
        $this->denied(fn () => $this->assess());
        $this->configure();
        $this->assertFalse($this->assess()['ready']);
    }

    public function test_cross_company_policy_history_is_not_visible(): void
    {
        $this->configure();
        $third = $this->userByEmail('tehrani@pmc.example');
        $n = $this->tenant->runAs($third->company_id, fn () => DB::table('demand_reader_policies')->count(), $this->project()->id);
        $this->assertSame(0, $n);
        $this->denied(fn () => $this->assess($third));
    }

    public function test_readiness_does_not_query_cde_library_stock_or_mutate_domain(): void
    {
        $this->configure();
        DB::enableQueryLog();
        $this->assess();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $sql = strtolower(implode("\n", array_column($queries, 'query')));
        foreach (['insert into', 'delete from', 'update "', '"document_files"', '"document_revisions"', '"material_items"', '"material_lots"', '"material_movements"'] as $token) {
            $this->assertStringNotContainsString($token, $sql);
        }
    }
}
