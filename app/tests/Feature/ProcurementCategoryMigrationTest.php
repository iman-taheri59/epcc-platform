<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialRequisition;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Services\MaterialRequisitionAccessService;
use App\Services\ProcurementService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Tests\TestCase;

final class ProcurementCategoryMigrationTest extends TestCase
{
    public function test_archived_populated_upgrade_preserves_preexisting_authorized_drafts_and_does_not_guess_category(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) < '2026_10_19_120000'));
        $this->artisan('migrate:fresh', ['--force' => true, '--path' => $paths, '--realpath' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $writer = $this->userByEmail('rezaei@pars.example');
        $owner = $this->userByEmail('karimi@nioc.example');
        $tenant = app(TenantContext::class);
        $policy = ['version' => 0, 'selectors' => ['user:'.$writer->id], 'suspend' => false, 'reason' => 'Pre-category synthetic policy'];
        app(MaterialRequisitionAccessService::class)->configure($project, $owner, $policy);
        app(PurchaseOrderAccessService::class)->configure($project, $owner, $policy);
        $before = $tenant->runAs($writer->company_id, function () use ($project, $writer): array {
            $lines = [['item_code' => 'PIPE-OLD', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'quantity' => '2.100', 'unit' => 'M'],
                ['item_code' => 'FLANGE-OLD', 'description_en' => 'Flange', 'description_fa' => 'فلنج', 'quantity' => '3.000', 'unit' => 'EA']];
            $mr = app(ProcurementService::class)->raiseRequisition($project, $writer, 'MR for Pipes', 'عنوان قدیمی', 'PIPING', $lines);
            $mr->author_policy_id = app(MaterialRequisitionAccessService::class)->policyId($project, $writer, 'PIPING');
            $mr->save();
            $po = app(ProcurementService::class)->createPurchaseOrder($project, $writer, Company::where('code', 'VEN-AZAR')->firstOrFail(), 'PO for Pipes', 'عنوان قدیمی', $lines);
            $po->author_policy_id = app(PurchaseOrderAccessService::class)->policyId($project, $writer, 'PIPING');
            $po->drafted_by = $writer->id;
            $po->discipline = 'PIPING';
            $po->save();

            return [[$mr->toArray(), $mr->items()->orderBy('line_no')->get()->toArray()], [$po->toArray(), $po->items()->orderBy('line_no')->get()->toArray()]];
        }, $project->id);
        app(ProjectArchiveService::class)->archive($project, $owner, 'Synthetic category migration');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $tenant->runAs($writer->company_id, function () use ($before): void {
            foreach ([MaterialRequisition::class, PurchaseOrder::class] as $i => $model) {
                [$header,$lines] = $before[$i];
                $document = $model::findOrFail($header['id']);
                foreach ($header as $key => $value) {
                    $this->assertSame($value, $document->toArray()[$key]);
                }
                $this->assertNull($document->material_category);
                $newLines = $document->items()->orderBy('line_no')->get();
                foreach ($lines as $n => $line) {
                    foreach ($line as $key => $value) {
                        $this->assertSame($value, $newLines[$n]->toArray()[$key]);
                    }
                }
                $this->assertNotNull($document->author_policy_id);
            }
        }, $project->id);
        $this->actingAs($writer)->get('/projects/PH-14/procurement?lang=en&material_category=UNCLASSIFIED')->assertOk()->assertSee('MR for Pipes')->assertSee('PO for Pipes')->assertSee('Unclassified legacy document');
        $this->get('/projects/PH-14/procurement?lang=en&material_category=PIPE')->assertOk()->assertDontSee('MR for Pipes')->assertDontSee('PO for Pipes');
        // Existing grandfathered drafts remain readable/updatable after approved project reopening, without forced classification.
        app(ProjectArchiveService::class)->reopen($project->fresh(), $owner);
        $tenant->runAs($writer->company_id, function () use ($before): void {
            $mr = MaterialRequisition::findOrFail($before[0][0]['id']);
            $mr->state = 'SUBMITTED';
            $mr->save();
            $this->assertNull($mr->fresh()->material_category);
        }, $project->id);
    }
}
