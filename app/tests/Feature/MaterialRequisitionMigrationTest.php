<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MaterialRequisition;
use App\Models\MaterialRequisitionItem;
use App\Models\Project;
use App\Services\ProcurementService;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class MaterialRequisitionMigrationTest extends TestCase
{
    public function test_upgrade_preserves_legacy_rows_and_initializes_global_counters_without_grants(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) < '2026_09_30_042713'));
        $this->artisan('migrate:fresh', ['--force' => true, '--path' => $paths, '--realpath' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $writer = $this->userByEmail('rezaei@pars.example');
        $owner = $this->userByEmail('karimi@nioc.example');
        $tenant = app(TenantContext::class);
        $rows = [];
        foreach ([[$writer, 4], [$owner, 11]] as [$actor,$sequence]) {
            $rows[] = $tenant->runAs($actor->company_id, function () use ($project, $actor, $sequence): array {
                $mr = MaterialRequisition::create(['project_id' => $project->id, 'company_id' => $actor->company_id, 'raised_by' => $actor->id, 'raised_at' => now(),
                    'sequence' => $sequence, 'number' => 'PH-14-MR-'.sprintf('%04d', $sequence), 'state' => 'DRAFT', 'title_en' => 'Historic', 'title_fa' => 'قدیمی', 'discipline' => 'PIPING']);
                $item = MaterialRequisitionItem::create(['project_id' => $project->id, 'requisition_id' => $mr->id, 'line_no' => 1, 'item_code' => 'HISTORIC', 'description_en' => 'Old line', 'description_fa' => 'قلم قدیمی', 'quantity' => '2.100', 'unit' => 'EA']);

                return [$mr->toArray(), $item->toArray()];
            }, $project->id);
        }
        $tenant->runAs($owner->company_id, fn () => app(ProjectArchiveService::class)->archive($project, $owner, 'Synthetic archived upgrade'), $project->id);
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame(11, (int) $project->fresh()->material_requisition_sequence);
        $tenant->runAs($owner->company_id, function () use ($rows): void {
            foreach ($rows as [$header,$item]) {
                $newHeader = MaterialRequisition::findOrFail($header['id']);
                $newItem = MaterialRequisitionItem::findOrFail($item['id']);
                foreach ($header as $k => $v) {
                    $this->assertSame($v, $newHeader->toArray()[$k]);
                }
                foreach ($item as $k => $v) {
                    $this->assertSame($v, $newItem->toArray()[$k]);
                }
                $this->assertNull($newHeader->author_policy_id);
                $this->assertNull($newItem->source_quantity);
            }
            $this->assertSame(0, DB::table('mr_author_policies')->count());
        }, $project->id);
        $tenant->runAs($owner->company_id, fn () => app(ProjectArchiveService::class)->reopen($project->fresh(), $owner), $project->id);
        $mr = $tenant->runAs($writer->company_id, fn () => app(ProcurementService::class)->raiseRequisition($project, $writer, 'Next', 'بعدی', 'PIPING', [
            ['item_code' => 'NEXT', 'description_en' => 'Next line', 'description_fa' => 'بعدی', 'quantity' => '1', 'unit' => 'EA'],
        ]), $project->id);
        $this->assertSame('PH-14-MR-0012', $mr->number);
    }
}
