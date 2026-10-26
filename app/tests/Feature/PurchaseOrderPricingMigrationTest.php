<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Services\MaterialRequisitionAccessService;
use App\Services\MaterialRequisitionEntryService;
use App\Services\ProcurementService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PurchaseOrderPricingMigrationTest extends TestCase
{
    public function test_archived_linked_draft_upgrade_preserves_source_order_signatures_and_creates_no_pricing_history(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) < '2026_10_19_160000'));
        $this->artisan('migrate:fresh', ['--force' => true, '--path' => $paths, '--realpath' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $writer = $this->userByEmail('rezaei@pars.example');
        $owner = $this->userByEmail('karimi@nioc.example');
        $finance = $this->userByEmail('tehrani@pmc.example');
        $policy = ['version' => 0, 'selectors' => ['user:'.$writer->id], 'suspend' => false, 'reason' => 'Existing linked source fixture'];
        app(MaterialRequisitionAccessService::class)->configure($project, $owner, $policy);
        app(PurchaseOrderAccessService::class)->configure($project, $owner, $policy);
        $tenant = app(TenantContext::class);
        $mr = app(MaterialRequisitionEntryService::class)->create($project, $writer, ['title_en' => 'Existing MR', 'title_fa' => 'درخواست قبلی', 'discipline' => 'PIPING', 'material_category' => 'PIPE', 'lines' => [['item_code' => 'UPGRADE', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '2.100']]]);
        $tenant->runAs($writer->company_id, function () use (&$mr, $writer, $finance, $owner): void {
            $svc = app(ProcurementService::class);
            $svc->submitRequisition($mr, $writer);
            $svc->approveTechnically($mr->fresh(), $finance);
            $mr = $svc->approveCommercially($mr->fresh(), $owner);
        }, $project->id);
        $vendor = $tenant->runAs($writer->company_id, fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id, $project->id);
        $po = app(PurchaseOrderEntryService::class)->createFromRequisition($project, $writer, ['requisition_id' => $mr->id, 'vendor_company_id' => $vendor]);
        $snapshot = fn () => [$po->fresh()->toArray(), $po->items()->get()->toArray(), $mr->fresh()->toArray(), $mr->items()->get()->toArray()];
        $before = $tenant->runAs($writer->company_id, $snapshot, $project->id);
        app(ProjectArchiveService::class)->archive($project, $owner, 'Pricing upgrade preservation');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $after = $tenant->runAs($writer->company_id, $snapshot, $project->id);
        $this->assertSame($before, $after);
        $tenant->runAs($writer->company_id, function (): void {
            $this->assertSame(0, DB::table('po_pricing_revisions')->count());
            $this->assertSame(0, DB::table('po_execution_decisions')->count());
        }, $project->id);
        $this->actingAs($writer)->get('/projects/PH-14/procurement/orders/'.$po->id.'?lang=en')->assertOk()->assertDontSee('name="pricing[content_hash]"', false);
    }
}
