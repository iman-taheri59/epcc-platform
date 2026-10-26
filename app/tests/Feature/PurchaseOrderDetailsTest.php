<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialInspection;
use App\Models\MaterialLot;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\ProjectArchiveService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PurchaseOrderDetailsTest extends TestCase
{
    private $actor;

    private Project $project;

    private PurchaseOrder $order;

    private PurchaseOrderItem $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->actor = $this->userByEmail('rezaei@pars.example');
        $this->project = app(TenantContext::class)->forIdentityLookup(fn () => Project::where('code', 'PH-14')->firstOrFail());
        $this->bound(function (): void {
            $vendor = Company::where('code', 'VEN-AZAR')->firstOrFail();
            $this->order = PurchaseOrder::create(['project_id' => $this->project->id, 'company_id' => $this->actor->company_id,
                'vendor_company_id' => $vendor->id, 'number' => 'PH-14-PO-TEST', 'sequence' => 900, 'state' => 'ISSUED',
                'title_en' => 'Synthetic valve order', 'title_fa' => 'سفارش آزمایشی شیرآلات', 'currency' => 'IRR',
                'total_value' => '1234.50', 'promised_date' => '2026-10-01', 'forecast_date' => '2026-10-05']);
            $this->line = PurchaseOrderItem::create(['project_id' => $this->project->id, 'purchase_order_id' => $this->order->id,
                'line_no' => 1, 'item_code' => 'DETAIL-VALVE', 'description_en' => 'Valve <script>alert(1)</script>',
                'description_fa' => 'شیر آزمایشی', 'unit' => 'EA', 'quantity' => '2.125', 'unit_price' => '10.25']);
        });
    }

    private function bound(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->actor->company_id, $fn, $this->project->id);
    }

    private function url(string $lang = 'en'): string
    {
        return '/projects/PH-14/procurement/orders/'.$this->order->id.'?lang='.$lang;
    }

    public function test_bilingual_sheet_link_shell_dates_and_escaped_descriptions(): void
    {
        $this->actingAs($this->actor)->get('/projects/PH-14/procurement?lang=en')->assertOk()
            ->assertSee('/procurement/orders/'.$this->order->id, false);
        $this->get($this->url())->assertOk()->assertSee('Purchase order details')->assertSee('dir="ltr"', false)
            ->assertSee('DETAIL-VALVE')->assertSee('2.125')->assertSee('10.25')->assertSee('2026-10-01')
            ->assertSee('Valve &lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('id="sb-burger"', false)->assertSee('id="po-lines"', false)->assertSee('No visible lots');
        $this->get($this->url('fa'))->assertOk()->assertSee('جزئیات سفارش خرید')->assertSee('dir="rtl"', false)
            ->assertSee('شیر آزمایشی')->assertSee('۲.۱۲۵')->assertSee('۱۴۰۵')->assertDontSee('purchase_order.title');
    }

    public function test_superseded_rows_and_mixed_units_are_kept_separate(): void
    {
        $next = $this->bound(fn () => PurchaseOrderItem::create(['project_id' => $this->project->id, 'purchase_order_id' => $this->order->id,
            'line_no' => 2, 'item_code' => 'DETAIL-VALVE', 'description_en' => 'Replacement', 'description_fa' => 'اصلاح',
            'quantity' => '3.000', 'unit' => 'EA', 'supersedes_id' => $this->line->id]));
        $this->bound(fn () => PurchaseOrderItem::create(['project_id' => $this->project->id, 'purchase_order_id' => $this->order->id,
            'line_no' => 3, 'item_code' => 'DETAIL-PIPE', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'quantity' => '5.000', 'unit' => 'M']));
        $this->actingAs($this->actor)->get($this->url())->assertOk()->assertSee('Superseded')->assertSee('Latest stored line')
            ->assertSee($this->line->id)->assertSee($next->id)->assertSee('2.125')->assertSee('3.000')->assertSee('5.000')
            ->assertSee('quantities with different units are not added');
    }

    public function test_lots_without_exact_line_and_advisory_failed_inspection_are_explicit(): void
    {
        $this->bound(function (): void {
            MaterialLot::create(['project_id' => $this->project->id, 'company_id' => $this->actor->company_id,
                'purchase_order_id' => $this->order->id, 'lot_code' => 'LOT-DETAIL', 'item_code' => 'DETAIL-VALVE',
                'description_en' => 'Lot', 'description_fa' => 'محموله', 'unit' => 'EA', 'heat_number' => 'HEAT-99', 'inspection_result' => 'FAIL']);
            MaterialInspection::create(['project_id' => $this->project->id, 'company_id' => $this->actor->company_id,
                'purchase_order_id' => $this->order->id, 'number' => 'IRN-DETAIL', 'sequence' => 901, 'result' => 'FAIL', 'stage' => 'ON_ARRIVAL',
                'inspected_by' => $this->actor->id, 'inspected_at' => now(), 'findings_en' => 'Synthetic failed inspection']);
        });
        $this->actingAs($this->actor)->get($this->url())->assertOk()->assertSee('LOT-DETAIL')->assertSee('HEAT-99')
            ->assertSee('Exact PO line not linked')->assertSee('Synthetic failed inspection')
            ->assertSee('Failed')->assertSee('do not establish usable stock');
    }

    public function test_guests_invalid_ids_and_cross_project_paths_are_rejected(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        $this->actingAs($this->actor)->get('/projects/PH-14/procurement/orders/not-a-uuid')->assertNotFound();
        $this->get('/projects/BB-2/procurement/orders/'.$this->order->id)->assertNotFound();
    }

    public function test_nonparticipant_and_same_project_competitor_cannot_read_order(): void
    {
        $this->actingAs($this->userByEmail('hosseini@arvand.example'))->get($this->url())->assertNotFound();
        $this->actingAs($this->userByEmail('mousavi@sahand.example'))->get($this->url())->assertNotFound();
        $this->actingAs($this->actor)->get($this->url())->assertOk()->assertSee('DETAIL-VALVE');
    }

    public function test_inactive_and_removed_member_are_denied(): void
    {
        $this->actingAs($this->userByEmail('former@pars.example'))->get($this->url())->assertForbidden();
        $this->bound(fn () => $this->actor->memberships()->where('project_id', $this->project->id)->delete());
        $this->actingAs($this->actor)->get($this->url())->assertNotFound();
    }

    public function test_archived_order_remains_readable_and_get_does_not_mutate_business_data(): void
    {
        app(ProjectArchiveService::class)->archive($this->project, $this->userByEmail('karimi@nioc.example'), 'Synthetic archive');
        $snapshot = fn () => $this->bound(fn () => array_map(fn ($t) => DB::table($t)->orderBy('id')->get()->toJson(),
            ['purchase_orders', 'purchase_order_items', 'material_lots', 'material_inspections', 'material_movements']));
        $before = $snapshot();
        $this->actingAs($this->actor)->get($this->url())->assertOk();
        $this->assertSame($before, $snapshot());
    }

    public function test_named_vendor_can_open_its_order_without_inheriting_buyer_membership(): void
    {
        $vendor = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail());
        $user = app(TenantContext::class)->forIdentityLookup(function () use ($vendor) {
            $u = User::create(['company_id' => $vendor->id, 'name' => 'Synthetic vendor',
                'email' => 'po-details@vendor.example', 'password' => 'synthetic-only', 'locale' => 'en', 'is_active' => true]);
            ProjectMembership::create(['user_id' => $u->id, 'project_id' => $this->project->id, 'role_codes' => []]);

            return $u;
        });
        $this->actingAs($user)->get($this->url())->assertForbidden();
        app(TenantContext::class)->forIdentityLookup(function () use ($vendor): void {
            $vendor->plan_id = Plan::where('code', 'full')->firstOrFail()->id;
            $vendor->save();
        });
        $this->actingAs($user->unsetRelations())->get($this->url())->assertOk()->assertSee('DETAIL-VALVE');
        app(TenantContext::class)->forIdentityLookup(fn () => $user->memberships()->delete());
        $this->get($this->url())->assertNotFound();
    }
}
