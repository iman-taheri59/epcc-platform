<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\ProcurementService;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PurchaseOrderEntryMigrationTest extends TestCase
{
    public function test_populated_archived_upgrade_preserves_rows_initializes_global_counter_without_authors(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) < '2026_10_19_100000'));
        $this->artisan('migrate:fresh', ['--force' => true, '--path' => $paths, '--realpath' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $project = Project::where('code', 'PH-14')->firstOrFail();
        $tenant = app(TenantContext::class);
        $writer = $this->userByEmail('rezaei@pars.example');
        $owner = $this->userByEmail('karimi@nioc.example');
        $before = [];
        foreach ([[$writer, 5], [$owner, 12]] as [$actor,$seq]) {
            $before[] = $tenant->runAs($actor->company_id, function () use ($project, $actor, $seq): array {
                $v = Company::where('code', 'VEN-AZAR')->firstOrFail();
                $p = PurchaseOrder::create(['project_id' => $project->id, 'company_id' => $actor->company_id, 'vendor_company_id' => $v->id,
                    'number' => 'LEGACY-'.$seq, 'sequence' => $seq, 'state' => 'DRAFT', 'title_en' => 'Old', 'title_fa' => 'قدیمی']);
                $i = PurchaseOrderItem::create(['project_id' => $project->id, 'purchase_order_id' => $p->id, 'line_no' => 1,
                    'item_code' => 'OLD', 'description_en' => 'Old line', 'description_fa' => 'قدیمی', 'quantity' => '2.100', 'unit' => 'EA']);

                return [$p->toArray(), $i->toArray()];
            }, $project->id);
        }
        app(ProjectArchiveService::class)->archive($project, $owner, 'Synthetic upgrade');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame(12, (int) $project->fresh()->purchase_order_sequence);
        $tenant->runAs($owner->company_id, function () use ($before): void {
            foreach ($before as [$h,$i]) {
                $p = PurchaseOrder::findOrFail($h['id']);
                $line = PurchaseOrderItem::findOrFail($i['id']);
                foreach ($h as $k => $v) {
                    $this->assertSame($v, $p->toArray()[$k]);
                }
                foreach ($i as $k => $v) {
                    $this->assertSame($v, $line->toArray()[$k]);
                }
                $this->assertNull($p->author_policy_id);
                $this->assertNull($p->drafted_by);
                $this->assertNull($line->source_quantity);
            }
            $this->assertSame(0, DB::table('po_author_policies')->count());
        }, $project->id);
        app(ProjectArchiveService::class)->reopen($project->fresh(), $owner);
        $po = $tenant->runAs($writer->company_id, fn () => app(ProcurementService::class)->createPurchaseOrder($project, $writer,
            Company::where('code', 'VEN-AZAR')->firstOrFail(), 'Next', 'بعدی', [
                ['item_code' => 'NEXT', 'description_en' => 'Next', 'description_fa' => 'بعدی', 'quantity' => '1', 'unit' => 'EA']]), $project->id);
        $this->assertSame('PH-14-PO-0013',$po->number);
    }
}
