<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcceptedMtoBatch;
use App\Models\AcceptedMtoRow;
use App\Models\MtoAcceptanceJournal;
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

/** Slice 4: accepted immutable MTO snapshots and atomic acceptance. */
final class MtoAcceptanceJournalTest extends TestCase
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

    public function test_real_snapshot_has_exact_automatic_unresolved_new_provenance(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $this->asPars(function () use ($revision): void {
            $batch = $this->acceptance->accept($revision, 'en');
            $journal = $batch->acceptanceJournal()->firstOrFail();
            $this->assertSame($batch->project_id, $journal->project_id);
            $this->assertSame($batch->company_id, $journal->company_id);
            $this->assertSame($revision->id, $journal->mto_revision_id);
            $this->assertSame($revision->mto_document_id, $journal->mto_document_id);
            $this->assertSame(1, $journal->ordinal);
            $this->assertSame('NEW', $journal->origin);
            $this->assertSame('UNRESOLVED', $journal->selection_state);
            $this->assertNull($batch->hierarchy_revision_id);
            $this->assertCount(1, $batch->rows);
            DB::statement('SET CONSTRAINTS mto_new_acceptance_complete IMMEDIATE');
        });
    }

    public function test_equal_timestamp_revisions_receive_distinct_project_ordinals(): void
    {
        $a = $this->draftRevision([$this->row(['raw_quantity' => 100])]);
        $user = $this->userByEmail('rezaei@pars.example');
        $this->asPars(function () use ($a, $user): void {
            $b = $this->staging->createRevision($a->document, $user, 'B', [$this->row(['raw_quantity' => 110])], lang: 'en');
            $this->travelTo(now()->startOfSecond());
            try {
                $first = $this->acceptance->accept($a, 'en');
                $second = $this->acceptance->accept($b, 'en');
            } finally {
                $this->travelBack();
            }
            $this->assertSame($first->accepted_at->toIso8601String(), $second->accepted_at->toIso8601String());
            $this->assertSame([1, 2], MtoAcceptanceJournal::orderBy('ordinal')->pluck('ordinal')->all());
            $this->assertSame('100.000', $first->rows->first()->gross_quantity);
            $this->assertSame('110.000', $second->rows->first()->gross_quantity);
            $this->assertSame(2, MtoAcceptanceJournal::where('selection_state', 'UNRESOLVED')->count());
        });
    }

    public function test_rejected_rows_create_no_clock_or_journal(): void
    {
        $revision = $this->draftRevision([$this->row(['commodity_code' => 'NO-SUCH-MATERIAL'])]);
        try {
            $this->asPars(fn () => $this->acceptance->accept($revision, 'en'));
            $this->fail('Expected invalid rows');
        } catch (ValidationException) {
            $this->asPars(function (): void {
                $this->assertSame(0, MtoAcceptanceJournal::count());
                $this->assertSame(0, DB::table('mto_acceptance_clocks')->count());
            });
        }
    }

    public function test_outer_failure_rolls_back_actual_rows_journal_clock_and_revision(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        try {
            $this->asPars(function () use ($revision): void {
                $this->acceptance->accept($revision, 'en');
                throw new RuntimeException('deliberate caller failure');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('deliberate caller failure', $e->getMessage());
        }
        $this->asPars(function () use ($revision): void {
            $this->assertSame(0, AcceptedMtoBatch::count());
            $this->assertSame(0, AcceptedMtoRow::count());
            $this->assertSame(0, MtoAcceptanceJournal::count());
            $this->assertSame(0, DB::table('mto_acceptance_clocks')->count());
            $this->assertSame(MtoRevision::DRAFT, $revision->fresh()->state);
        });
    }

    public function test_commit_guard_rejects_incomplete_snapshot_and_rolls_back_auto_journal(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $this->assertSqlState('23514', fn () => $this->asPars(function () use ($revision): void {
            AcceptedMtoBatch::create([
                'project_id' => $revision->project_id, 'company_id' => $revision->company_id,
                'mto_document_id' => $revision->mto_document_id, 'mto_revision_id' => $revision->id,
                'document_number' => $revision->document->document_number, 'revision_code' => 'A',
                'state' => 'ACCEPTED', 'scope_mode' => $revision->scope_mode, 'accepted_at' => now(),
            ]);
            // No frozen rows / ACCEPTED source state. Checked at outer COMMIT.
        }));
        $this->asPars(fn () => $this->assertSame(0, MtoAcceptanceJournal::count()));
    }

    public function test_deferred_integrity_pins_its_source_and_restores_nested_tenant_context(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $pars = $this->company('EPC-PARS');
        $project = $this->project();
        $this->tenant->runAs($project->owner_company_id, function () use ($revision, $pars, $project): void {
            $this->tenant->runAs($pars->id, fn () => $this->acceptance->accept($revision, 'en'), $project->id);
            DB::statement('SET CONSTRAINTS mto_new_acceptance_complete IMMEDIATE');
            $this->assertSame($project->owner_company_id, DB::selectOne("SELECT current_setting('epcc.company_id') AS value")->value);
            $this->assertSame($project->id, DB::selectOne("SELECT current_setting('epcc.project_id') AS value")->value);
            $this->assertSame(1, MtoAcceptanceJournal::count());
        }, $project->id);
    }

    public function test_archived_real_project_refuses_acceptance_without_provenance(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $project = $this->project();
        $owner = $this->provisionIdentity(fn () => \App\Models\User::where('company_id', $project->owner_company_id)->firstOrFail());
        app(ProjectArchiveService::class)->archive($project, $owner, 'Synthetic journal regression');
        try {
            $this->asPars(fn () => $this->acceptance->accept($revision, 'en'));
            $this->fail('Expected archive rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('project', $e->errors());
        }
        $this->asPars(fn () => $this->assertSame(0, MtoAcceptanceJournal::count()));
    }

    public function test_outsider_reads_no_new_provenance_or_clock(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $this->asPars(fn () => $this->acceptance->accept($revision, 'en'));
        $other = $this->tenant->forIdentityLookup(fn () => Company::create([
            'code' => 'JOURNAL-OUT', 'name_en' => 'Outside', 'name_fa' => 'بیرونی', 'tenant_type' => 'EPC', 'is_active' => true,
        ]));
        $this->tenant->runAs($other->id, function (): void {
            $this->assertSame(0, MtoAcceptanceJournal::count());
            $this->assertSame(0, DB::table('mto_acceptance_clocks')->count());
        }, $this->project()->id);
    }

    public function test_journal_model_cannot_be_updated(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $this->expectException(LogicException::class);
        $this->asPars(function () use ($revision): void {
            $j = $this->acceptance->accept($revision, 'en')->acceptanceJournal()->firstOrFail();
            $j->ordinal = 9;
            $j->save();
        });
    }

    public function test_sql_history_cannot_be_updated_or_deleted_and_clock_cannot_reverse(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $this->asPars(function () use ($revision): void {
            $this->acceptance->accept($revision, 'en');
            $this->assertSame(0, DB::table('mto_acceptance_journal')->update(['ordinal' => 8]));
            $this->assertSame(0, DB::table('mto_acceptance_journal')->delete());
        });
        $this->assertSqlState('23514', fn () => $this->asPars(fn () => DB::table('mto_acceptance_clocks')->update(['last_ordinal' => 0])));
    }

    public function test_direct_journal_insert_cannot_fabricate_legacy_or_new_provenance(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $batch = $this->asPars(fn () => $this->acceptance->accept($revision, 'en'));
        $this->assertSqlState('55000', fn () => $this->asPars(fn () => DB::table('mto_acceptance_journal')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'project_id' => $batch->project_id, 'company_id' => $batch->company_id,
            'accepted_mto_batch_id' => $batch->id, 'mto_document_id' => $batch->mto_document_id,
            'mto_revision_id' => $batch->mto_revision_id, 'ordinal' => 99,
        ])));
    }

    public function test_forward_only_migration_refuses_history_destroying_down(): void
    {
        $migration = require database_path('migrations/2026_10_18_100000_record_new_mto_acceptance_order.php');
        $this->expectException(LogicException::class);
        $migration->down();
    }

    public function test_expansion_does_not_fabricate_ordinals_for_existing_accepted_batches(): void
    {
        $new = '2026_10_18_100000_record_new_mto_acceptance_order.php';
        $paths = array_map(fn ($p) => 'database/migrations/'.basename($p), array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) < $new));
        $this->artisan('migrate:fresh', ['--force' => true, '--path' => $paths]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->seed(MaterialCatalogueSeeder::class);
        $old = $this->draftRevision([$this->row()]);
        $batch = $this->asPars(fn () => $this->acceptance->accept($old, 'en'));
        $this->artisan('migrate', ['--force' => true, '--path' => array_map(fn ($p) => 'database/migrations/'.basename($p), array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) >= $new))]);
        $this->asPars(function () use ($batch): void {
            $this->assertSame(1, AcceptedMtoBatch::count());
            $this->assertSame(0, MtoAcceptanceJournal::count());
            $this->assertSame(0, DB::table('mto_acceptance_clocks')->count());
            $this->assertNull($batch->acceptanceJournal()->first());
        });
    }
    public function test_new_frozen_row_must_reference_exact_batch_document_tuple(): void
    {
        $first = $this->draftRevision([$this->row()]);
        $other = $this->draftRevision([$this->row()]);
        $batch = $this->asPars(fn () => $this->acceptance->accept($first, 'en'));
        $this->assertSqlState('23503', fn () => $this->asPars(function () use ($batch, $other): void {
            $attrs = $batch->rows->first()->getAttributes();
            $attrs['id'] = (string) \Illuminate\Support\Str::uuid();
            $attrs['mto_document_id'] = $other->mto_document_id;
            $attrs['source_staging_row_id'] = $other->rows()->firstOrFail()->id;
            DB::table('accepted_mto_rows')->insert($attrs);
        }));
    }

    public function test_archived_project_keeps_journal_readable_but_denies_clock_write(): void
    {
        $revision = $this->draftRevision([$this->row()]);
        $this->asPars(fn () => $this->acceptance->accept($revision, 'en'));
        $project = $this->project();
        $owner = $this->provisionIdentity(fn () => \App\Models\User::where('company_id', $project->owner_company_id)->firstOrFail());
        app(ProjectArchiveService::class)->archive($project, $owner, 'Synthetic archive read');
        $this->asPars(fn () => $this->assertSame(1, MtoAcceptanceJournal::count()));
        $this->assertSqlState('42501', fn () => $this->asPars(fn () => DB::table('mto_acceptance_clocks')->update(['last_ordinal' => 2])));
    }

}
