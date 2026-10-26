<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Services\ProcurementService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Services\PurchaseOrderExecutionAccessService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PurchaseOrderExecutionMigrationTest extends TestCase
{
    public function test_archived_populated_upgrade_does_not_nominate_approve_issue_or_rewrite_existing_po(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) < '2026_10_19_140000'));
        $this->artisan('migrate:fresh', ['--force' => true, '--path' => $paths, '--realpath' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $writer = $this->userByEmail('rezaei@pars.example');
        $owner = $this->userByEmail('karimi@nioc.example');
        app(PurchaseOrderAccessService::class)->configure($project, $owner, ['version' => 0, 'selectors' => ['user:'.$writer->id], 'suspend' => false, 'reason' => 'Historical draft only']);
        $tenant = app(TenantContext::class);
        $vendor = $tenant->runAs($writer->company_id, fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id, $project->id);
        $po = app(PurchaseOrderEntryService::class)->create($project, $writer, ['vendor_company_id' => $vendor, 'title_en' => 'Existing priced draft', 'title_fa' => 'پیش‌نویس قیمت‌دار قبلی', 'discipline' => 'PIPING', 'material_category' => 'PIPE', 'currency' => 'IRR',
            'lines' => [['item_code' => 'OLD', 'description_en' => 'Old', 'description_fa' => 'قدیمی', 'unit' => 'M', 'quantity' => '2.100', 'unit_price' => '10.50']]]);
        $before = $tenant->runAs($writer->company_id, fn () => [$po->toArray(), $po->items()->get()->toArray()], $project->id);
        app(ProjectArchiveService::class)->archive($project, $owner, 'Synthetic nomination upgrade');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $tenant->runAs($writer->company_id, function () use ($po, $before): void {
            $this->assertSame($before[0], $po->fresh()->toArray());
            $this->assertSame($before[1], $po->items()->get()->toArray());
            $this->assertSame(0, DB::table('po_execution_policies')->count());
            $this->assertSame(0, DB::table('po_execution_decisions')->count());
        }, $project->id);
        foreach (['finance', 'issue'] as $purpose) {
            $this->assertNull(app(PurchaseOrderExecutionAccessService::class)->nominationId($project, $writer, $writer->company_id, $purpose, 'PIPING'));
        }
        $this->actingAs($owner)->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertSee('Not configured — no nomination')->assertSee('Nominations alone do not approve an order');
    }

    public function test_stage2_upgrade_retains_preexisting_issued_po_and_never_fabricates_financial_history(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) < '2026_10_19_150000'));
        $this->artisan('migrate:fresh', ['--force' => true, '--path' => $paths, '--realpath' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $writer = $this->userByEmail('rezaei@pars.example');
        $owner = $this->userByEmail('karimi@nioc.example');
        app(PurchaseOrderAccessService::class)->configure($project, $owner, ['version' => 0, 'selectors' => ['user:'.$writer->id], 'suspend' => false, 'reason' => 'Historical draft only']);
        $tenant = app(TenantContext::class);
        $vendor = $tenant->runAs($writer->company_id, fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id, $project->id);
        $po = app(PurchaseOrderEntryService::class)->create($project, $writer, ['vendor_company_id' => $vendor, 'title_en' => 'Existing priced draft', 'title_fa' => 'پیش‌نویس قیمت‌دار قبلی', 'discipline' => 'PIPING', 'material_category' => 'PIPE', 'currency' => 'IRR',
            'lines' => [['item_code' => 'OLD', 'description_en' => 'Old', 'description_fa' => 'قدیمی', 'unit' => 'M', 'quantity' => '2.100', 'unit_price' => '10.50']]]);
        app(PurchaseOrderExecutionAccessService::class)->configure($project, $owner, [
            'buyer_company_id' => $writer->company_id, 'version' => 0, 'finance_selectors' => ['user:'.$this->userByEmail('tehrani@pmc.example')->id],
            'issue_selectors' => ['user:'.$this->userByEmail('sadeghi@pars.example')->id], 'suspend' => false, 'reason' => 'Pre-stage2 nomination, not a financial decision',
        ]);
        // Historical trusted issuance before the new guard; not a newly certified 3-person transaction.
        $tenant->runAs($writer->company_id, fn () => app(ProcurementService::class)->issuePurchaseOrder($po, $writer), $project->id);
        $po = $tenant->runAs($writer->company_id, fn () => $po->fresh(), $project->id);
        $before = $tenant->runAs($writer->company_id, fn () => [$po->toArray(), $po->items()->get()->toArray()], $project->id);
        app(ProjectArchiveService::class)->archive($project, $owner, 'Synthetic nomination upgrade');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $tenant->runAs($writer->company_id, function () use ($po, $before): void {
            $this->assertSame($before[0], $po->fresh()->toArray());
            $this->assertSame($before[1], $po->items()->get()->toArray());
            $this->assertSame(1, DB::table('po_execution_policies')->count());
            $this->assertSame(0, DB::table('po_execution_decisions')->count());
        }, $project->id);
        foreach (['finance', 'issue'] as $purpose) {
            $this->assertNull(app(PurchaseOrderExecutionAccessService::class)->nominationId($project, $writer, $writer->company_id, $purpose, 'PIPING'));
        }
        $this->actingAs($owner)->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertSee('Nominations alone do not approve an order');
    }
}
