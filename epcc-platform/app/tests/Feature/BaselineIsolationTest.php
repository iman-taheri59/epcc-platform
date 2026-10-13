<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BaselineIsolationTest extends TestCase
{
    private Project $project;
    private string $creator;
    private string $reader;
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->creator = Company::where('code', 'EPC-PARS')->value('id');
        $this->reader = $this->project->owner_company_id;
        $actor = $this->userByEmail('rezaei@pars.example');
        foreach (['baseline_batches', 'engineering_items', 'baseline_discrepancies', 'model_imports', 'model_import_diffs'] as $table) {
            $this->ids[$table] = (string) Str::uuid();
        }
        $this->bound($this->creator, function () use ($actor): void {
            $this->insert('baseline_batches', ['source_kind' => 'MANUAL', 'imported_by' => $actor->id]);
            $this->insert('engineering_items', ['baseline_batch_id' => $this->ids['baseline_batches'], 'item_code' => 'PIPE', 'quantity' => 1, 'unit' => 'M', 'source_kind' => 'MANUAL']);
            $this->insert('baseline_discrepancies', ['item_code' => 'PIPE', 'unit' => 'M', 'kind' => 'QUANTITY', 'source_a' => 'MANUAL', 'source_b' => 'PCF']);
            $this->insert('model_imports', ['filename' => 'test.pcf', 'content_hash' => str_repeat('a', 64), 'imported_by' => $actor->id]);
            $this->insert('model_import_diffs', ['model_import_id' => $this->ids['model_imports'], 'change' => 'CREATED']);
        });
    }

    private function insert(string $table, array $values): void
    {
        DB::table($table)->insert($values + ['id' => $this->ids[$table], 'project_id' => $this->project->id, 'company_id' => $this->creator]);
    }

    private function bound(string $company, callable $fn, ?string $project = null): mixed
    {
        return app(TenantContext::class)->runAs($company, $fn, $project ?? $this->project->id);
    }

    private function assertVisible(bool $visible): void
    {
        foreach ($this->ids as $table => $id) {
            $this->assertSame($visible, DB::table($table)->where('id', $id)->exists(), $table);
        }
    }

    public function test_creator_and_other_project_participant_can_read(): void
    {
        $this->bound($this->creator, fn () => $this->assertVisible(true));
        $this->bound($this->reader, fn () => $this->assertVisible(true));
    }

    public function test_nonparticipant_and_wrong_project_cannot_read(): void
    {
        $outsider = Company::where('code', 'EPC-ARVAND')->value('id');
        $this->bound($outsider, fn () => $this->assertVisible(false));
        $other = Project::where('code', 'BB-2')->value('id');
        $this->bound($this->creator, fn () => $this->assertVisible(false), $other);
    }

    public function test_removal_revokes_creator_access_even_with_stale_user_membership(): void
    {
        DB::table('project_company')->where('project_id', $this->project->id)->where('company_id', $this->creator)->delete();
        $this->bound($this->creator, fn () => $this->assertVisible(false));
    }

    public function test_shared_read_does_not_grant_update_or_delete_of_another_company_rows(): void
    {
        $this->bound($this->reader, function (): void {
            $this->assertVisible(true);
            foreach ($this->ids as $table => $id) {
                $this->assertSame(0, DB::table($table)->where('id', $id)->update(['updated_at' => now()]), $table);
                $this->assertSame(0, DB::table($table)->where('id', $id)->delete(), $table);
            }
        });
    }

    public function test_creator_can_update_active_rows_and_archive_preserves_read_but_blocks_writes(): void
    {
        $this->bound($this->creator, function (): void {
            foreach ($this->ids as $table => $id) {
                $this->assertSame(1, DB::table($table)->where('id', $id)->update(['updated_at' => now()]), $table);
            }
        });
        $owner = $this->userByEmail('karimi@nioc.example');
        app(\App\Services\ProjectArchiveService::class)->archive($this->project, $owner, 'Test close-out');
        $this->assertSame('ARCHIVED', $this->project->fresh()->state);
        $this->bound($this->creator, function (): void {
            $this->assertVisible(true);
            foreach ($this->ids as $table => $id) {
                $this->assertSame(0, DB::table($table)->where('id', $id)->update(['updated_at' => now()]), $table);
                $this->assertSame(0, DB::table($table)->where('id', $id)->delete(), $table);
            }
        });
    }

    public function test_missing_tenant_fails_closed_without_uuid_cast_error(): void
    {
        $this->assertVisible(false);
    }

    public function test_creator_cannot_insert_into_wrong_project_scope(): void
    {
        $other = Project::where('code', 'BB-2')->value('id');
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('row-level security');
        $this->bound($this->creator, function (): void {
            $this->ids['baseline_batches'] = (string) Str::uuid();
            $this->insert('baseline_batches', ['source_kind' => 'MANUAL', 'imported_by' => $this->userByEmail('rezaei@pars.example')->id]);
        }, $other);
    }
    public function test_shared_reader_gets_explicit_refusal_when_accepting_another_company_batch(): void
    {
        $actor = $this->userByEmail('karimi@nioc.example');
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->expectExceptionMessage('Only the creating company');
        $this->bound($this->reader, function () use ($actor): void {
            app(\App\Services\BaselineService::class)->accept(
                \App\Models\BaselineBatch::findOrFail($this->ids['baseline_batches']), $actor,
            );
        });
    }

    public function test_shared_reader_gets_explicit_refusal_when_resolving_another_company_discrepancy(): void
    {
        $actor = $this->userByEmail('karimi@nioc.example');
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        $this->expectExceptionMessage('Only the creating company');
        $this->bound($this->reader, function () use ($actor): void {
            app(\App\Services\BaselineService::class)->resolve(
                \App\Models\BaselineDiscrepancy::findOrFail($this->ids['baseline_discrepancies']),
                $actor, 'DISMISSED', 'Not an actual difference',
            );
        });
    }

    public function test_shared_reader_cannot_acknowledge_another_company_import_diff(): void
    {
        $this->actingAs($this->userByEmail('karimi@nioc.example'))
            ->post('/projects/PH-14/piping/model-import/diff/'.$this->ids['model_import_diffs'].'/ack')
            ->assertForbidden();
    }

    public function test_archive_acknowledgement_is_refused_explicitly(): void
    {
        app(\App\Services\ProjectArchiveService::class)->archive(
            $this->project, $this->userByEmail('karimi@nioc.example'), 'Closed',
        );
        $this->actingAs($this->userByEmail('rezaei@pars.example'))
            ->post('/projects/PH-14/piping/model-import/diff/'.$this->ids['model_import_diffs'].'/ack')
            ->assertSessionHasErrors('project');
        $this->bound($this->creator, fn () => $this->assertFalse(
            (bool) DB::table('model_import_diffs')->where('id', $this->ids['model_import_diffs'])->value('acknowledged')
        ));
    }

    public function test_company_portfolio_reads_work_without_a_project_scope(): void
    {
        app(TenantContext::class)->runAs($this->reader, fn () => $this->assertVisible(true));
    }

    public function test_archive_blocks_inserts_on_all_five_tables(): void
    {
        $copies = $this->bound($this->creator, function (): array {
            $rows = [];
            foreach ($this->ids as $table => $id) {
                $rows[$table] = (array) DB::table($table)->where('id', $id)->first();
                $rows[$table]['id'] = (string) Str::uuid();
            }
            return $rows;
        });
        app(\App\Services\ProjectArchiveService::class)->archive(
            $this->project, $this->userByEmail('karimi@nioc.example'), 'Closed',
        );
        foreach ($copies as $table => $row) {
            try {
                $this->bound($this->creator, fn () => DB::table($table)->insert($row));
                $this->fail('Archive allowed INSERT on '.$table);
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0], $table);
            }
        }
        $this->bound($this->creator, fn () => $this->assertVisible(true));
    }

    public function test_creator_cannot_transfer_row_ownership_to_another_company(): void
    {
        foreach ($this->ids as $table => $id) {
            try {
                $this->bound($this->creator, fn () => DB::table($table)->where('id', $id)
                    ->update(['company_id' => $this->reader]));
                $this->fail('Ownership transfer allowed on '.$table);
            } catch (QueryException $e) {
                $this->assertSame('42501', $e->errorInfo[0], $table);
            }
        }
    }

}
