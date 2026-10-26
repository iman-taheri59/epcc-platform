<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialRequisition;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\MaterialRequisitionAccessService;
use App\Services\MaterialRequisitionEntryService;
use App\Services\ProcurementService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Services\PurchaseOrderExecutionAccessService;
use App\Services\PurchaseOrderExecutionService;
use App\Services\PurchaseOrderPricingService;
use App\Services\PurchaseOrderSourceReviewService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseOrderSourceReviewTest extends TestCase
{
    private $writer;

    private $finance;

    private $issuer;

    private $owner;

    private Project $project;

    private MaterialRequisition $mr;

    private string $url = '/projects/PH-14/procurement/orders/from-requisition?lang=en';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $this->owner = $this->userByEmail('karimi@nioc.example');
        app(MaterialRequisitionAccessService::class)->configure($this->project, $this->owner, $this->policy());
        $this->mr = app(MaterialRequisitionEntryService::class)->create($this->project, $this->writer, [
            'title_en' => 'Approved pipe request', 'title_fa' => 'درخواست لوله تأییدشده', 'discipline' => 'PIPING', 'material_category' => 'PIPE',
            'lines' => [['item_code' => 'SAME-CODE', 'description_en' => 'Pipe A', 'description_fa' => 'لوله الف', 'unit' => 'M', 'quantity' => '0002.100'],
                ['item_code' => 'SAME-CODE', 'description_en' => 'Pipe B', 'description_fa' => 'لوله ب', 'unit' => 'EA', 'quantity' => '3.000']],
        ]);
        // Approved source fixture uses existing trusted transition primitives and distinct approvers, not new authority.
        $technical = $this->userByEmail('tehrani@pmc.example');
        $this->bound(function () use ($technical): void {
            $svc = app(ProcurementService::class);
            $svc->submitRequisition($this->mr, $this->writer);
            $svc->approveTechnically($this->mr->fresh(), $technical);
            $this->mr = $svc->approveCommercially($this->mr->fresh(), $this->owner);
        });
        $this->nominate();
        $this->finance = $this->userByEmail('tehrani@pmc.example');
        $this->issuer = $this->userByEmail('sadeghi@pars.example');
        app(PurchaseOrderExecutionAccessService::class)->configure($this->project, $this->owner, [
            'buyer_company_id' => $this->writer->company_id, 'version' => 0, 'finance_selectors' => ['user:'.$this->finance->id],
            'issue_selectors' => ['user:'.$this->issuer->id], 'suspend' => false, 'reason' => 'Explicit full-copy execution nominees',
        ]);
    }

    private function bound(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->writer->company_id, $fn, $this->project->id);
    }

    private function policy(int $version = 0): array
    {
        return ['version' => $version, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Synthetic source-copy author'];
    }

    private function nominate(): void
    {
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, $this->policy());
    }

    private function payload(): array
    {
        return ['requisition_id' => $this->mr->id, 'vendor_company_id' => $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id), 'promised_date' => '2026-11-12'];
    }

    private function copy(): PurchaseOrder
    {
        return app(PurchaseOrderEntryService::class)->createFromRequisition($this->project, $this->writer, $this->payload(), 'en');
    }

    private function draft(): PurchaseOrder
    {
        $po = $this->copy();
        app(PurchaseOrderPricingService::class)->price($this->project, $this->writer, $po->id, $this->prices($po));

        return $po;
    }

    private function pricingUrl(PurchaseOrder $po): string
    {
        return '/projects/PH-14/procurement/orders/'.$po->id.'/pricing?lang=en';
    }

    private function detailUrl(PurchaseOrder $po): string
    {
        return '/projects/PH-14/procurement/orders/'.$po->id.'?lang=en';
    }

    private function prices(PurchaseOrder $po): array
    {
        return $this->bound(fn () => [
            'content_hash' => app(PurchaseOrderExecutionService::class)->hash($po), 'currency' => 'USD', 'reason' => 'Verified supplier offer',
            'lines' => $po->items()->orderBy('line_no')->get()->map(fn ($l) => ['id' => $l->id, 'unit_price' => '10.01'])->all(),
        ]);
    }

    private function audit(PurchaseOrder $po): object
    {
        return $this->bound(fn () => DB::table('po_pricing_revisions')->where('purchase_order_id', $po->id)->orderByDesc('sequence')->first());
    }

    private function executeUrl(PurchaseOrder $po, ?string $purpose = null): string
    {
        return '/projects/PH-14/procurement/execution/'.$this->writer->company_id.'/'.$po->id.($purpose ? '/'.$purpose : '').'?lang=en';
    }

    private function financialInput(PurchaseOrder $po): array
    {
        return ['content_hash' => $this->prices($po)['content_hash'], 'reason' => 'Reviewed exact full-copy version'];
    }

    private function latest(PurchaseOrder $po): object
    {
        return $this->bound(fn () => DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->orderByDesc('sequence')->first());
    }

    private function approve(PurchaseOrder $po): array
    {
        $input = $this->financialInput($po);
        $this->actingAs($this->finance)->postJson($this->executeUrl($po, 'finance'), ['execution' => $input])->assertRedirect()->assertSessionHasNoErrors();

        return $input + ['approval_id' => $this->latest($po)->id];
    }

    private function issue(PurchaseOrder $po, array $input): void
    {
        $this->actingAs($this->issuer)->postJson($this->executeUrl($po, 'issue'), ['execution' => $input])->assertRedirect()->assertSessionHasNoErrors();
    }

    private function reviewUrl(PurchaseOrder $po): string
    {
        return '/projects/PH-14/procurement/orders/'.$po->id.'/source-review?lang=en';
    }

    private function report(PurchaseOrder $po, $actor = null): array
    {
        return app(PurchaseOrderSourceReviewService::class)->review($this->project, $actor ?? $this->writer, $po->id);
    }

    private function snapshot(): array
    {
        return $this->bound(function (): array {
            $out = [];
            foreach (['material_requisitions', 'material_requisition_items', 'purchase_orders', 'purchase_order_items', 'po_pricing_revisions', 'po_execution_decisions', 'po_mr_allocations'] as $table) {
                $out[$table] = DB::table($table)->where('project_id', $this->project->id)->orderBy('id')->get()->toJson();
            }

            return $out;
        });
    }

    private function legacy(?MaterialRequisition $mr = null): PurchaseOrder
    {
        return $this->bound(fn () => app(ProcurementService::class)->createPurchaseOrder($this->project, $this->writer, Company::where('code', 'VEN-AZAR')->firstOrFail(), 'Legacy source order', 'سفارش قدیمی', [
            ['item_code' => 'OLD', 'description_en' => 'Old', 'description_fa' => 'قدیمی', 'quantity' => '1.000', 'unit' => 'M'],
        ], $mr, 'USD'));
    }

    public function test_draft_source_review_is_read_only_and_shows_exact_visible_ids_not_a_remaining_balance(): void
    {
        $po = $this->draft();
        $before = $this->snapshot();
        $this->actingAs($this->writer)->get($this->reviewUrl($po))->assertOk()->assertSee('MR source commitment review')->assertSee('DRAFT without issuance markers')->assertSee('not remaining')->assertSee('SAME-CODE')->assertSee('2.100');
        $report = $this->report($po);
        $this->assertTrue($report['evidenceVisible']);
        $this->assertCount(2, $report['sourceLines']);
        $this->assertSame('draft', $report['rows']->first()['status']);
        $this->assertSame($before, $this->snapshot());
        $this->post($this->reviewUrl($po), ['resolve' => true])->assertStatus(405);
        $this->get($this->detailUrl($po))->assertOk()->assertSee('MR source commitment review');
    }

    public function test_cancelled_legacy_is_flagged_not_assumed_released_or_zero_consumption(): void
    {
        $po = $this->draft();
        $legacy = $this->legacy($this->mr);
        $this->bound(fn () => DB::table('purchase_orders')->where('id', $legacy->id)->update(['state' => 'CANCELLED']));
        $before = $this->snapshot();
        $row = $this->report($po)['rows']->firstWhere('order.id', $legacy->id);
        $this->assertSame('legacy', $row['status']);
        $this->assertFalse($row['line_only']);
        $this->actingAs($this->writer)->get($this->reviewUrl($po))->assertOk()->assertSee('Historical commitment needs reconciliation')->assertSee($legacy->number);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_headerless_source_line_reference_is_reported_without_repair(): void
    {
        $po = $this->draft();
        $legacy = $this->legacy();
        $id = $this->bound(fn () => $this->mr->items()->first()->id);
        $this->bound(function () use ($legacy, $id): void {
            DB::table('purchase_order_items')->where('purchase_order_id', $legacy->id)->update(['requisition_item_id' => $id]);
            app(ProcurementService::class)->issuePurchaseOrder($legacy->fresh(), $this->writer);
        });
        $before = $this->snapshot();
        $row = $this->report($po)['rows']->firstWhere('order.id', $legacy->id);
        $this->assertTrue($row['line_only']);
        $this->assertSame('legacy', $row['status']);
        $this->assertSame($before, $this->snapshot());
        $this->actingAs($this->writer)->get($this->reviewUrl($po))->assertOk()->assertSee('Line-only / mismatched header');
    }

    public function test_recorded_allocations_use_exact_decimals_and_historical_source_versions(): void
    {
        $po = $this->draft();
        $this->issue($po, $this->approve($po));
        $this->bound(fn () => DB::table('material_requisitions')->where('id', $this->mr->id)->update(['updated_at' => now()->addMinute()]));
        $before = $this->snapshot();
        $report = $this->report($po);
        $row = $report['rows']->firstWhere('order.id', $po->id);
        $this->assertSame('recorded', $row['status']);
        $this->assertCount(2, $row['allocations']);
        $this->assertSame('2.100', $report['recorded']->get($report['sourceLines']->first()->id)->first()->quantity);
        $this->actingAs($this->writer)->get($this->reviewUrl($po))->assertOk()->assertSee('matching active-line allocations recorded')->assertSee($row['decision']->source_hash);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_oversight_read_does_not_claim_hidden_buyer_evidence_is_missing(): void
    {
        $po = $this->draft();
        $this->issue($po, $this->approve($po));
        foreach ([$this->owner, $this->finance] as $actor) {
            $report = $this->report($po, $actor);
            $this->assertFalse($report['evidenceVisible']);
            $this->assertSame('evidence_hidden', $report['rows']->firstWhere('order.id', $po->id)['status']);
            $this->assertCount(0, $report['recorded']);
            $this->actingAs($actor)->get($this->reviewUrl($po))->assertOk()->assertSee('Evidence is not fully visible')->assertDontSee('Historical commitment needs reconciliation');
        }
    }

    public function test_counterparty_nomination_does_not_delegate_generic_source_review(): void
    {
        $po = $this->draft();
        $counter = $this->userByEmail('mousavi@sahand.example');
        app(TenantContext::class)->runAs($this->owner->company_id, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $counter->id)->update(['discipline_scope' => null]), $this->project->id);
        app(PurchaseOrderExecutionAccessService::class)->configure($this->project, $this->owner, ['buyer_company_id' => $this->writer->company_id, 'version' => 1, 'finance_selectors' => ['user:'.$counter->id], 'issue_selectors' => ['user:'.$this->issuer->id], 'suspend' => false, 'reason' => 'No generic legacy read delegation']);
        $this->actingAs($counter)->get($this->executeUrl($po))->assertOk()->assertDontSee('MR source commitment review');
        $this->get($this->reviewUrl($po))->assertNotFound();
    }

    public function test_discipline_limits_selected_order_and_related_legacy_rows(): void
    {
        $po = $this->draft();
        $legacy = $this->legacy($this->mr);
        $this->bound(fn () => DB::table('purchase_orders')->where('id', $legacy->id)->update(['discipline' => 'CIVIL', 'state' => 'CANCELLED']));
        $this->bound(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => ['PIPING']]));
        $this->actingAs($this->writer)->get($this->reviewUrl($po))->assertOk()->assertSee('Your discipline scope may omit')->assertDontSee($legacy->number);
        $this->get($this->reviewUrl($legacy))->assertNotFound();
    }

    public function test_archived_report_remains_read_only_and_bilingual_content_is_escaped(): void
    {
        $po = $this->draft();
        $this->bound(fn () => DB::table('purchase_orders')->where('id', $po->id)->update(['title_en' => '<script>unsafe()</script>']));
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Read-only source review');
        $before = $this->snapshot();
        $this->actingAs($this->writer)->get($this->reviewUrl($po))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>unsafe()</script>', false);
        $this->get(str_replace('lang=en', 'lang=fa', $this->reviewUrl($po)))->assertOk()->assertSee('بررسی تعهدات منبع MR');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_vendor_sees_only_its_existing_orders_not_mr_or_other_vendor_commitments(): void
    {
        $po = $this->draft();
        $other = $this->legacy($this->mr);
        $this->bound(function () use ($other): void {
            $another = Company::create(['code' => 'VEN-SOURCE-REVIEW', 'tenant_type' => 'VEN', 'name_en' => 'Another synthetic vendor', 'name_fa' => 'وندور آزمایشی دیگر']);
            DB::table('project_company')->insert(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'company_id' => $another->id, 'role' => 'VENDOR']);
            DB::table('purchase_orders')->where('id', $other->id)->update(['vendor_company_id' => $another->id]);
        });
        $vendor = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail());
        $user = app(TenantContext::class)->forIdentityLookup(function () use ($vendor) {
            $vendor->plan_id = Plan::where('code', 'full')->firstOrFail()->id;
            $vendor->save();

            return User::create(['company_id' => $vendor->id, 'name' => 'Synthetic source-review vendor', 'email' => 'source-review@vendor.example', 'password' => 'test-only', 'locale' => 'en', 'is_active' => true]);
        });
        app(TenantContext::class)->forIdentityLookup(fn () => ProjectMembership::create(['project_id' => $this->project->id, 'user_id' => $user->id, 'role_codes' => ['project_manager'], 'discipline_scope' => null]));
        $report = $this->report($po, $user);
        $this->assertNull($report['source']);
        $this->assertCount(0, $report['sourceLines']);
        $this->assertFalse($report['evidenceVisible']);
        $this->assertCount(1, $report['rows']);
        $this->actingAs($user)->get($this->reviewUrl($po))->assertOk()->assertSee('Source MR is not visible')->assertDontSee($this->mr->number)->assertDontSee($other->number);
    }

    public function test_report_pagination_is_bounded_and_keeps_language(): void
    {
        $po = $this->draft();
        for ($i = 0; $i < 26; $i++) {
            $this->copy();
        }
        $page = $this->actingAs($this->writer)->get($this->reviewUrl($po))->assertOk();
        $this->assertCount(25, $page->viewData('rows'));
        parse_str(parse_url($page->viewData('related')->url(2), PHP_URL_QUERY), $params);
        $this->assertSame('en', $params['lang']);
        $page = $this->get($this->reviewUrl($po).'&page=2')->assertOk();
        $this->assertCount(2, $page->viewData('rows'));
    }

    public function test_guests_current_plan_loss_and_other_project_or_manual_orders_do_not_expand_report_access(): void
    {
        $po = $this->draft();
        $this->get($this->reviewUrl($po))->assertRedirect('/login');
        $this->actingAs($this->writer)->get(str_replace('PH-14', 'BB-2', $this->reviewUrl($po)))->assertNotFound();
        $manual = $this->legacy();
        $this->get($this->reviewUrl($manual))->assertNotFound();
        $starter = Plan::where('code', 'starter')->firstOrFail()->id;
        $this->bound(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $starter]));
        $this->get($this->reviewUrl($po))->assertForbidden();
    }

    public function test_recorded_quantities_retain_historical_units_instead_of_silent_conversion(): void
    {
        $po = $this->draft();
        $this->issue($po, $this->approve($po));
        $line = $this->bound(fn () => $this->mr->items()->orderBy('line_no')->first());
        $this->bound(fn () => DB::table('material_requisition_items')->where('id', $line->id)->update(['unit' => 'EA']));
        $report = $this->report($po);
        $sum = $report['recorded']->get($line->id)->first();
        $this->assertSame('M', $sum->unit);
        $this->assertSame('2.100', $sum->quantity);
        $this->assertSame('EA', $report['sourceLines']->first()->unit);
        $this->assertSame('recorded', $report['rows']->firstWhere('order.id', $po->id)['status']);
        $this->actingAs($this->writer)->get($this->reviewUrl($po))->assertOk()->assertSee('2.100 M');
    }
}
