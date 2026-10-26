<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcceptedMtoBatch;
use App\Models\AcceptedMtoRow;
use App\Models\MtoAcceptanceJournal;
use App\Models\MtoScopeObservation;
use App\Models\Subsystem;
use App\Services\MtoScopeReadinessService;
use App\Services\ProjectArchiveService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use App\Models\Company;
use App\Models\MaterialItem;
use App\Models\MtoDocument;
use App\Models\MtoRevision;
use App\Models\MtoStagingRow;
use App\Models\Project;
use App\Services\MtoAcceptanceService;
use App\Services\MtoStagingService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/** Real PostgreSQL observations are not engineering admission. */
final class MtoScopeObservationTest extends TestCase
{
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

    public function test_commit_captures_exact_rows_and_digest_but_no_admission(): void
    {
        $revision = $this->draftRevision([$this->row(['raw_quantity' => '120.125'])]);
        $batch = $this->acceptObserved($revision);
        $this->asPars(function () use ($batch): void {
            $o = $batch->scopeObservation()->firstOrFail();
            $row = $batch->rows()->firstOrFail();
            $this->assertSame('UNADMITTED', $o->admission_state);
            $this->assertSame('DATABASE_OBSERVATION', $o->origin);
            $this->assertSame($batch->id, $o->payload['batch_id']);
            $this->assertSame($row->id, $o->payload['rows'][0]['id']);
            $this->assertSame('120.125', $o->payload['rows'][0]['quantity']);
            $this->assertSame($row->material_item_id, $o->payload['rows'][0]['material_item_id']);
            $this->assertSame($row->source_row_hash, $o->payload['rows'][0]['source_row_hash']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $o->payload_hash);
            $r = app(MtoScopeReadinessService::class)->assess($batch);
            $this->assertFalse($r['ready']);
            $this->assertNull($r['gross']);
            $this->assertContains('NO_ADMITTED_TOPOLOGY', $r['findings']);
            $this->assertContains('MISSING_ROW_SCOPE', $r['findings']);
            $this->assertNotContains('SOURCE_ROWS_DRIFT', $r['findings']);
            $this->assertNotContains('PAYLOAD_DIGEST_MISMATCH', $r['findings']);
        });
    }

    public function test_before_commit_readiness_does_not_invent_observation(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $this->asPars(function () use ($revision): void {
            $batch = $this->acceptance->accept($revision, 'en');
            $r = app(MtoScopeReadinessService::class)->assess($batch);
            $this->assertSame(['SCOPE_OBSERVATION_MISSING'], $r['findings']);
            DB::statement('SET CONSTRAINTS mto_scope_observation_capture IMMEDIATE');
            $this->assertSame(1, MtoScopeObservation::count());
        });
    }

    public function test_rollback_leaves_no_observation(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        try {
            $this->asPars(function () use ($revision): void {
                $this->acceptance->accept($revision, 'en');
                DB::statement('SET CONSTRAINTS mto_scope_observation_capture IMMEDIATE');
                $this->assertSame(1, MtoScopeObservation::count());
                throw new RuntimeException('rollback observation');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('rollback observation', $e->getMessage());
        }
        $this->asPars(fn () => $this->assertSame(0, MtoScopeObservation::count()));
    }

    public function test_optional_area_is_valid_and_later_reparent_does_not_rewrite_snapshot(): void
    {
        $leaf = $this->asPars(fn () => Subsystem::where('project_id', $this->project()->id)->firstOrFail());
        $this->asPars(fn () => $leaf->update(['area_id' => null]));
        $revision = $this->draftRevision([$this->row(['row_scope_id' => $leaf->id])]);
        $this->asPars(fn () => $revision->update(['hierarchy_revision_id' => 'unverified-claim']));
        $batch = $this->acceptObserved($revision);
        $this->asPars(function () use ($batch, $leaf): void {
            $o = $batch->scopeObservation()->firstOrFail();
            $this->assertSame($leaf->facility_id, $o->payload['subsystems'][0]['facility_id']);
            $this->assertNull($o->payload['subsystems'][0]['area_id']);
            $this->assertCount(1, $o->payload['facilities']);
            $hash = $o->payload_hash;
            $newFacility = \App\Models\Facility::create([
                'project_id' => $batch->project_id, 'company_id' => $batch->company_id,
                'code' => 'OBS-REPARENT', 'name_en' => 'Observation reparent', 'name_fa' => 'آزمایش', 'kind' => 'PLANT',
            ]);
            $leaf->update(['facility_id' => $newFacility->id]);
            $this->assertSame($hash, $o->fresh()->payload_hash);
            $r = app(MtoScopeReadinessService::class)->assess($batch);
            $this->assertContains('HIERARCHY_PIN_NOT_ADMITTED', $r['findings']);
            $this->assertNotContains('AREA_PARENT_NOT_RESOLVED_AT_CAPTURE', $r['findings']);
            $this->assertNotContains('FACILITY_NOT_RESOLVED_AT_CAPTURE', $r['findings']);
            $this->assertFalse($r['ready']);
        });
    }

    public function test_cross_project_facility_is_reported_without_promoting_to_project_root(): void
    {
        $leaf = $this->asPars(fn () => Subsystem::where('project_id', $this->project()->id)->firstOrFail());
        $other = $this->tenant->forIdentityLookup(fn () => Project::where('code', 'BB-2')->firstOrFail());
        $facility = $this->tenant->runAs($other->owner_company_id, fn () => \App\Models\Facility::create(['project_id' => $other->id, 'company_id' => $other->owner_company_id, 'code' => 'OBS-FOREIGN', 'name_en' => 'Other project', 'name_fa' => 'آزمایش', 'kind' => 'PLANT']), $other->id);
        $this->asPars(fn () => $leaf->update(['facility_id' => $facility->id, 'area_id' => null]));
        $batch = $this->acceptObserved($this->draftRevision([$this->row(['row_scope_id' => $leaf->id])]));
        $this->asPars(function () use ($batch): void {
            $r = app(MtoScopeReadinessService::class)->assess($batch);
            $this->assertContains('FACILITY_NOT_RESOLVED_AT_CAPTURE', $r['findings']);
            $this->assertFalse($r['ready']);
            $this->assertNull($r['gross']);
        });
    }

    public function test_late_raw_row_addition_is_detected_not_silently_added_to_frozen_coverage(): void
    {
        $batch = $this->acceptObserved($this->draftRevision([$this->row()]));
        $other = $this->draftRevision([$this->row()]);
        $this->asPars(function () use ($batch, $other): void {
            $before = $batch->scopeObservation()->firstOrFail();
            $attrs = $batch->rows()->firstOrFail()->getAttributes();
            $attrs['id'] = (string) \Illuminate\Support\Str::uuid();
            $attrs['source_staging_row_id'] = $other->rows()->firstOrFail()->id;
            DB::table('accepted_mto_rows')->insert($attrs);
            $r = app(MtoScopeReadinessService::class)->assess($batch);
            $this->assertContains('SOURCE_ROWS_DRIFT', $r['findings']);
            $this->assertFalse($r['ready']);
            $this->assertSame($before->payload_hash, $before->fresh()->payload_hash);
            $this->assertCount(1, $before->fresh()->payload['rows']);
            $this->assertSame(2, $batch->rows()->count());
        });
    }

    public function test_sql_mutation_and_fabricated_insert_are_refused(): void
    {
        $batch = $this->acceptObserved($this->draftRevision([$this->row()]));
        $attrs = $this->asPars(function () use ($batch): array {
            $this->assertSame(0, DB::table('mto_scope_observations')->update(['admission_state' => 'ADMITTED']));
            $this->assertSame(0, DB::table('mto_scope_observations')->delete());
            return $batch->scopeObservation()->firstOrFail()->getAttributes();
        });
        $attrs['id'] = (string) \Illuminate\Support\Str::uuid();
        $this->assertSqlState('55000', fn () => $this->asPars(fn () => DB::table('mto_scope_observations')->insert($attrs)));
    }

    public function test_outsider_cannot_read_observation_or_use_forged_model_to_read_source(): void
    {
        $batch = $this->acceptObserved($this->draftRevision([$this->row()]));
        $other = $this->tenant->forIdentityLookup(fn () => Company::create([
            'code' => 'SCOPE-OUT', 'name_en' => 'Outside', 'name_fa' => 'بیرونی', 'tenant_type' => 'EPC', 'is_active' => true,
        ]));
        $this->tenant->runAs($other->id, function () use ($batch): void {
            $this->assertSame(0, MtoScopeObservation::count());
            $r = app(MtoScopeReadinessService::class)->assess($batch);
            $this->assertSame(['SOURCE_NOT_VISIBLE'], $r['findings']);
            $this->assertNull($r['gross']);
        }, $this->project()->id);
    }

    public function test_deferred_capture_restores_outer_owner_context(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $pars = $this->company('EPC-PARS');
        $project = $this->project();
        $this->tenant->runAs($project->owner_company_id, function () use ($revision, $pars, $project): void {
            $this->tenant->runAs($pars->id, fn () => $this->acceptance->accept($revision, 'en'), $project->id);
            DB::statement('SET CONSTRAINTS mto_scope_observation_capture IMMEDIATE');
            $this->assertSame($project->owner_company_id, DB::selectOne("SELECT current_setting('epcc.company_id') AS value")->value);
            $this->assertSame($project->id, DB::selectOne("SELECT current_setting('epcc.project_id') AS value")->value);
            $this->assertSame(1, MtoScopeObservation::count());
        }, $project->id);
    }

    public function test_expansion_does_not_backfill_even_existing_new_journal(): void
    {
        $new = '2026_10_18_110000_capture_mto_scope_observations.php';
        $paths = array_map(fn ($p) => 'database/migrations/'.basename($p), array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) < $new));
        $this->artisan('migrate:fresh', ['--force' => true, '--path' => $paths]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->seed(MaterialCatalogueSeeder::class);
        $batch = $this->acceptObserved($this->draftRevision([$this->row()]));
        $this->artisan('migrate', ['--force' => true, '--path' => ['database/migrations/'.$new]]);
        $this->asPars(function () use ($batch): void {
            $this->assertSame(1, MtoAcceptanceJournal::count());
            $this->assertSame(0, MtoScopeObservation::count());
            $r = app(MtoScopeReadinessService::class)->assess($batch);
            $this->assertSame(['SCOPE_OBSERVATION_MISSING'], $r['findings']);
        });
    }

    public function test_model_cannot_mutate_observation(): void
    {
        $batch = $this->acceptObserved($this->draftRevision([$this->row()]));
        $this->expectException(LogicException::class);
        $this->asPars(function () use ($batch): void {
            $o = $batch->scopeObservation()->firstOrFail();
            $o->admission_state = 'ADMITTED';
            $o->save();
        });
    }
    public function test_area_edge_and_multiple_rows_are_captured_without_collapsing_coverage(): void
    {
        $leaf = $this->asPars(fn () => Subsystem::where('project_id', $this->project()->id)->firstOrFail());
        $this->asPars(function () use ($leaf): void {
            $area = \App\Models\Area::create(['project_id' => $leaf->project_id, 'company_id' => $leaf->company_id,
                'facility_id' => $leaf->facility_id, 'code' => 'OBS-AREA', 'name_en' => 'Area', 'name_fa' => 'ناحیه']);
            $leaf->update(['area_id' => $area->id]);
        });
        $batch = $this->acceptObserved($this->draftRevision([
            $this->row(['row_scope_id' => $leaf->id]),
            $this->row(['mto_item_no' => '20', 'raw_quantity' => '7.001']),
        ]));
        $this->asPars(function () use ($batch, $leaf): void {
            $p = $batch->scopeObservation()->firstOrFail()->payload;
            $this->assertCount(2, $p['rows']);
            $this->assertCount(1, $p['subsystems']);
            $this->assertCount(1, $p['areas']);
            $this->assertSame($leaf->facility_id, $p['areas'][0]['facility_id']);
            $this->assertSame($p['areas'][0]['id'], $p['subsystems'][0]['area_id']);
            $r = app(MtoScopeReadinessService::class)->assess($batch);
            $this->assertContains('MISSING_ROW_SCOPE', $r['findings']);
            $this->assertNotContains('AREA_PARENT_NOT_RESOLVED_AT_CAPTURE', $r['findings']);
            $this->assertFalse($r['ready']);
        });
    }

    public function test_archival_preserves_read_only_unadmitted_observation(): void
    {
        $batch = $this->acceptObserved($this->draftRevision([$this->row()]));
        $project = $this->project();
        $owner = $this->provisionIdentity(fn () => \App\Models\User::where('company_id', $project->owner_company_id)->firstOrFail());
        app(ProjectArchiveService::class)->archive($project, $owner, 'Scope observation read regression');
        $this->asPars(function () use ($batch): void {
            $this->assertSame(1, MtoScopeObservation::count());
            $this->assertSame('UNRESOLVED', app(MtoScopeReadinessService::class)->assess($batch)['status']);
        });
    }

    public function test_down_refuses_to_destroy_observation_history(): void
    {
        $migration = require database_path('migrations/2026_10_18_110000_capture_mto_scope_observations.php');
        $this->expectException(LogicException::class);
        $migration->down();
    }

}
