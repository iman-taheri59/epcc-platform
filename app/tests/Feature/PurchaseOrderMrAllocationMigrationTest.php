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
use App\Services\PurchaseOrderExecutionAccessService;
use App\Services\PurchaseOrderExecutionService;
use App\Services\PurchaseOrderPricingService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PurchaseOrderMrAllocationMigrationTest extends TestCase
{
    public function test_archived_priced_linked_draft_and_manual_finance_history_are_preserved_without_allocation_backfill(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) < '2026_10_19_170000'));
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
        $pricingHash = $tenant->runAs($writer->company_id, fn () => app(PurchaseOrderExecutionService::class)->hash($po), $project->id);
        $pricingLines = $tenant->runAs($writer->company_id, fn () => $po->items()->get()->map(fn ($l) => ['id' => $l->id, 'unit_price' => '10.01'])->all(), $project->id);
        app(PurchaseOrderPricingService::class)->price($project, $writer, $po->id, ['content_hash' => $pricingHash, 'currency' => 'USD', 'reason' => 'Existing controlled prices', 'lines' => $pricingLines]);
        $manual = app(PurchaseOrderEntryService::class)->create($project, $writer, ['vendor_company_id' => $vendor, 'title_en' => 'Historical manual', 'title_fa' => 'دستی قبلی', 'discipline' => 'PIPING', 'material_category' => 'PIPE', 'currency' => 'USD', 'lines' => [['item_code' => 'MANUAL', 'description_en' => 'Manual', 'description_fa' => 'دستی', 'unit' => 'M', 'quantity' => '1.000', 'unit_price' => '1.00']]]);
        app(PurchaseOrderExecutionAccessService::class)->configure($project, $owner, ['buyer_company_id' => $writer->company_id, 'version' => 0, 'finance_selectors' => ['user:'.$finance->id], 'issue_selectors' => [], 'suspend' => false, 'reason' => 'Existing manual finance nomination']);
        $manualHash = $tenant->runAs($writer->company_id, fn () => app(PurchaseOrderExecutionService::class)->hash($manual), $project->id);
        app(PurchaseOrderExecutionService::class)->act($project, $finance, $writer->company_id, $manual->id, 'finance', ['content_hash' => $manualHash, 'reason' => 'Existing manual financial history']);
        $priorPricing = $tenant->runAs($writer->company_id, fn () => DB::table('po_pricing_revisions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), $project->id);
        $priorFinance = $tenant->runAs($writer->company_id, fn () => (array) DB::table('po_execution_decisions')->first(), $project->id);
        $snapshot = fn () => [$po->fresh()->toArray(), $po->items()->get()->toArray(), $mr->fresh()->toArray(), $mr->items()->get()->toArray()];
        $before = $tenant->runAs($writer->company_id, $snapshot, $project->id);
        app(ProjectArchiveService::class)->archive($project, $owner, 'Pricing upgrade preservation');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $after = $tenant->runAs($writer->company_id, $snapshot, $project->id);
        $this->assertSame($before, $after);
        $tenant->runAs($writer->company_id, function (): void {
            $this->assertSame(1, DB::table('po_pricing_revisions')->count());
            $this->assertSame(0, DB::table('po_mr_allocations')->count());
            $this->assertSame(1, DB::table('po_execution_decisions')->count());
        }, $project->id);
        $this->assertSame($priorPricing, $tenant->runAs($writer->company_id, fn () => DB::table('po_pricing_revisions')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), $project->id));
        $afterFinance = $tenant->runAs($writer->company_id, fn () => (array) DB::table('po_execution_decisions')->first(), $project->id);
        $this->assertNull($afterFinance['source_hash']);
        unset($afterFinance['source_hash']);
        $this->assertSame($priorFinance, $afterFinance);
        $this->actingAs($writer)->get('/projects/PH-14/procurement/orders/'.$po->id.'?lang=en')->assertOk()->assertDontSee('name="pricing[content_hash]"', false);
    }
}
