<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialRequisition;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
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
use Illuminate\Support\Str;
use Tests\TestCase;

final class MaterialRequisitionDetailsTest extends TestCase
{
    private $writer;

    private $owner;

    private Project $project;

    private MaterialRequisition $mr;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $policy = ['version' => 0, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Synthetic detail sheet fixture'];
        app(MaterialRequisitionAccessService::class)->configure($this->project, $this->owner, $policy);
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, $policy);
        $this->mr = app(MaterialRequisitionEntryService::class)->create($this->project, $this->writer, [
            'title_en' => 'MR <script>alert(1)</script>', 'title_fa' => 'درخواست آزمایشی لوله', 'discipline' => 'PIPING', 'material_category' => 'PIPE',
            'lines' => [['item_code' => 'MR-DETAIL', 'description_en' => 'Pipe <img src=x onerror=alert(1)>', 'description_fa' => 'لوله آزمایشی', 'unit' => 'M', 'quantity' => '0002.100'],
                ['item_code' => 'MR-EA', 'description_en' => 'Second unit', 'description_fa' => 'واحد دوم', 'unit' => 'EA', 'quantity' => '3.000']],
        ]);
        $technical = $this->userByEmail('tehrani@pmc.example');
        $this->bound(function () use ($technical): void {
            $service = app(ProcurementService::class);
            $service->submitRequisition($this->mr, $this->writer);
            $service->approveTechnically($this->mr->fresh(), $technical);
            $this->mr = $service->approveCommercially($this->mr->fresh(), $this->owner);
        });
    }

    private function bound(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->writer->company_id, $fn, $this->project->id);
    }

    private function url(string $lang = 'en'): string
    {
        return '/projects/PH-14/procurement/requisitions/'.$this->mr->id.'?lang='.$lang;
    }

    private function copy(): PurchaseOrder
    {
        $vendor = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id);

        return app(PurchaseOrderEntryService::class)->createFromRequisition($this->project, $this->writer, ['requisition_id' => $this->mr->id, 'vendor_company_id' => $vendor], 'en');
    }

    private function actionPayload(): array
    {
        return ['requisition_id' => $this->mr->id,
            'vendor_company_id' => $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id),
            'promised_date' => '2026-12-01'];
    }

    private function actionUrl(): string
    {
        return '/projects/PH-14/procurement/orders/from-requisition?lang=en';
    }

    public function test_detail_copy_action_uses_existing_full_copy_endpoint_without_source_consumption(): void
    {
        foreach (['en', 'fa'] as $lang) {
            $page = $this->actingAs($this->writer)->get($this->url($lang))->assertOk()->assertSee('id="po-from-mr-'.$this->mr->id.'"', false)
                ->assertSee('name="po_source[requisition_id]" value="'.$this->mr->id.'"', false)->assertSee('po_source[vendor_company_id]', false)
                ->assertDontSee('name="po_source[quantity]"', false)->assertDontSee('name="po_source[unit_price]"', false);
            $this->assertTrue($page->viewData('canRaisePo'));
        }
        $source = $this->bound(fn () => [$this->mr->fresh()->getRawOriginal(), $this->mr->items()->orderBy('line_no')->get()->map(fn ($line) => $line->getRawOriginal())->all()]);
        $response = $this->from($this->url())->post($this->actionUrl(), ['po_source' => $this->actionPayload()])->assertRedirect()->assertSessionHasNoErrors();
        $po = $this->bound(fn () => PurchaseOrder::where('requisition_id', $this->mr->id)->firstOrFail());
        $response->assertRedirect(route('procurement.orders.show', ['projectCode' => $this->project->code, 'orderId' => $po->id, 'lang' => 'en']));
        $this->bound(function () use ($po, $source): void {
            $this->assertSame($source, [$this->mr->fresh()->getRawOriginal(), $this->mr->items()->orderBy('line_no')->get()->map(fn ($line) => $line->getRawOriginal())->all()]);
            $this->assertSame('DRAFT', $po->state);
            $this->assertSame('2026-12-01', $po->promised_date->format('Y-m-d'));
            $this->assertNull($po->total_value);
            $this->assertNull($po->issued_by);
            $this->assertSame($this->mr->items()->orderBy('line_no')->pluck('id')->all(), $po->items()->orderBy('line_no')->pluck('requisition_item_id')->all());
            $this->assertSame(['2.100', '3.000'], $po->items()->orderBy('line_no')->pluck('quantity')->all());
            $this->assertTrue($po->items()->get()->every(fn ($line) => $line->unit_price === null));
            foreach (['po_pricing_revisions', 'po_execution_decisions', 'po_mr_allocations'] as $table) {
                $this->assertSame(0, DB::table($table)->count());
            }
        });
    }

    public function test_detail_copy_action_never_inherits_mr_or_oversight_authority_and_rechecks_revocation(): void
    {
        foreach ([$this->owner, $this->userByEmail('tehrani@pmc.example')] as $actor) {
            $this->actingAs($actor)->get($this->url())->assertOk()->assertDontSee('id="po-from-mr-'.$this->mr->id.'"', false);
        }
        $this->actingAs($this->writer)->get($this->url())->assertOk()->assertSee('id="po-from-mr-'.$this->mr->id.'"', false);
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, ['version' => 1, 'selectors' => [], 'suspend' => true, 'reason' => 'Revoke independent PO grant after form display']);
        $this->assertTrue(app(MaterialRequisitionAccessService::class)->allows($this->project, $this->writer, 'PIPING'));
        $this->postJson($this->actionUrl(), ['po_source' => $this->actionPayload()])->assertForbidden();
        $this->get($this->url())->assertOk()->assertDontSee('id="po-from-mr-'.$this->mr->id.'"', false);
        $this->assertSame(0, $this->bound(fn () => PurchaseOrder::where('requisition_id', $this->mr->id)->count()));
    }

    public function test_detail_copy_action_rechecks_source_eligibility_after_display(): void
    {
        $this->actingAs($this->writer)->get($this->url())->assertOk()->assertSee('id="po-from-mr-'.$this->mr->id.'"', false);
        // Trusted fixture change simulates stale source eligibility; no new human rejection authority.
        $this->bound(fn () => DB::table('material_requisitions')->where('id', $this->mr->id)->update(['state' => 'REJECTED']));
        $this->postJson($this->actionUrl(), ['po_source' => $this->actionPayload()])->assertUnprocessable()->assertJsonValidationErrors('po_source.requisition_id');
        $this->get($this->url())->assertOk()->assertDontSee('id="po-from-mr-'.$this->mr->id.'"', false);
        $this->assertSame(0, $this->bound(fn () => PurchaseOrder::where('requisition_id', $this->mr->id)->count()));
    }

    public function test_detail_copy_action_is_hidden_and_refused_after_archive(): void
    {
        $this->actingAs($this->writer)->get($this->url())->assertOk()->assertSee('id="po-from-mr-'.$this->mr->id.'"', false);
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Archive after MR action display');
        $this->get($this->url())->assertOk()->assertDontSee('id="po-from-mr-'.$this->mr->id.'"', false);
        $this->postJson($this->actionUrl(), ['po_source' => $this->actionPayload()])->assertUnprocessable();
        $this->assertSame(0, $this->bound(fn () => PurchaseOrder::where('requisition_id', $this->mr->id)->count()));
    }

    public function test_detail_copy_validation_returns_to_source_and_safely_renders_nested_old_input(): void
    {
        $data = $this->actionPayload();
        $data['vendor_company_id'] = ['<script>bad-old-input</script>'];
        $this->actingAs($this->writer)->from($this->url())->post($this->actionUrl(), ['po_source' => $data])->assertRedirect($this->url())->assertSessionHasErrors('po_source.vendor_company_id');
        $page = $this->get($this->url())->assertOk()->assertSee('role="alert"', false)
            ->assertDontSee('<script>bad-old-input</script>', false)->assertSee('name="po_source[requisition_id]" value="'.$this->mr->id.'"', false);
        $this->assertMatchesRegularExpression('/<details[^>]*id="po-from-mr-'.preg_quote($this->mr->id, '/').'"[^>]*\bopen\b/', $page->getContent());
        $this->assertSame(0, $this->bound(fn () => PurchaseOrder::where('requisition_id', $this->mr->id)->count()));
    }

    public function test_sheet_link_bilingual_exact_quantities_metadata_and_escaped_text(): void
    {
        $this->actingAs($this->writer)->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertSee('/procurement/requisitions/'.$this->mr->id, false);
        $technical = $this->userByEmail('tehrani@pmc.example');
        $line = $this->bound(fn () => $this->mr->items()->orderBy('line_no')->firstOrFail());
        $this->get($this->url())->assertOk()->assertSee('Material requisition details')->assertSee('dir="ltr"', false)->assertSee('id="sb-burger"', false)
            ->assertSee('0002.100')->assertSee('2.100')->assertSee('3.000')->assertSee($line->id)->assertSee($technical->id)->assertSee($this->owner->id)
            ->assertSee('MR &lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x onerror=', false)
            ->assertSee('Quantities with different units are not added')->assertSee('not verified legal signatures');
        $this->get($this->url('fa'))->assertOk()->assertSee('جزئیات درخواست خرید متریال')->assertSee('dir="rtl"', false)->assertSee('۰۰۰۲.۱۰۰')->assertSee('لوله آزمایشی')->assertDontSee('requisition.title');
    }

    public function test_read_does_not_mutate_seven_source_order_or_evidence_tables(): void
    {
        $po = $this->copy();
        $snapshot = fn () => $this->bound(function (): array {
            $rows = [];
            foreach (['material_requisitions', 'material_requisition_items', 'purchase_orders', 'purchase_order_items', 'po_pricing_revisions', 'po_execution_decisions', 'po_mr_allocations'] as $table) {
                $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }

            return $rows;
        });
        $before = $snapshot();
        $this->actingAs($this->writer)->get($this->url())->assertOk()->assertSee($po->number)->assertSee('/procurement/orders/'.$po->id, false);
        $this->get($this->url('fa'))->assertOk();
        $this->assertSame($before, $snapshot());
    }

    public function test_original_rls_oversight_visible_but_vendor_and_competing_buyer_refused(): void
    {
        $po = $this->copy();
        foreach ([$this->owner, $this->userByEmail('tehrani@pmc.example')] as $actor) {
            $this->actingAs($actor)->get($this->url())->assertOk()->assertSee($this->mr->number);
        }
        $vendor = app(TenantContext::class)->forIdentityLookup(fn () => User::create(['company_id' => $po->vendor_company_id, 'name' => 'Detail vendor', 'email' => 'mr-details@vendor.example', 'password' => 'test-only', 'is_active' => true]));
        app(TenantContext::class)->runAs($vendor->company_id, function () use ($vendor): void {
            DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'user_id' => $vendor->id, 'role_codes' => '["vendor"]', 'created_at' => now(), 'updated_at' => now()]);
        }, $this->project->id);
        app(TenantContext::class)->forIdentityLookup(fn () => Company::whereKey($vendor->company_id)->update(['plan_id' => Plan::where('code', 'full')->firstOrFail()->id]));
        app(TenantContext::class)->runAs($vendor->company_id, function () use ($po): void {
            $this->assertSame(1, PurchaseOrder::whereKey($po->id)->count());
            $this->assertSame(0, MaterialRequisition::whereKey($this->mr->id)->count());
        }, $this->project->id);
        $this->actingAs($vendor)->get($this->url())->assertNotFound();
        $other = $this->userByEmail('mousavi@sahand.example');
        app(TenantContext::class)->runAs($other->company_id, function () use ($other): void {
            ProjectMembership::where('project_id', $this->project->id)->where('user_id', $other->id)->update(['discipline_scope' => null]);
            $this->assertSame(0, MaterialRequisition::whereKey($this->mr->id)->count());
        }, $this->project->id);
        $this->actingAs($other)->get($this->url())->assertNotFound();
    }

    public function test_live_membership_and_discipline_scope_are_checked_without_draft_nomination(): void
    {
        $membership = $this->bound(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->firstOrFail());
        $this->bound(fn () => $membership->update(['discipline_scope' => ['CIVIL']]));
        $this->actingAs($this->writer)->get($this->url())->assertNotFound();
        $this->bound(fn () => $membership->update(['discipline_scope' => []]));
        $this->get($this->url())->assertNotFound();
        $this->bound(fn () => $membership->update(['discipline_scope' => null]));
        app(MaterialRequisitionAccessService::class)->configure($this->project, $this->owner, ['version' => 1, 'selectors' => [], 'suspend' => true, 'reason' => 'Reading is not draft editing']);
        $this->get($this->url())->assertOk();
        $this->bound(fn () => $membership->delete());
        $this->get($this->url())->assertNotFound();
    }

    public function test_guest_entitlement_and_unknown_identifiers_fail_closed(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        $this->actingAs($this->writer)->get('/projects/PH-14/procurement/requisitions/'.Str::uuid())->assertNotFound();
        $this->get('/projects/PH-14/procurement/requisitions/not-a-uuid')->assertNotFound();
        $full = $this->writer->company->plan_id;
        $this->bound(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => Plan::where('code', 'starter')->firstOrFail()->id]));
        $this->get($this->url())->assertForbidden();
        $this->bound(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $full]));
        Plan::whereKey($full)->update(['standards_packs' => ['NOT-THIS-PACK']]);
        $this->get($this->url())->assertForbidden();
    }

    public function test_related_orders_are_bounded_and_include_line_only_links_not_code_matching(): void
    {
        for ($i = 0; $i < 26; $i++) {
            $this->copy();
        }
        $lineOnly = $this->bound(function (): PurchaseOrder {
            $po = PurchaseOrder::create(['project_id' => $this->project->id, 'company_id' => $this->writer->company_id,
                'vendor_company_id' => Company::where('code', 'VEN-AZAR')->firstOrFail()->id, 'number' => 'LINE-ONLY-DETAIL', 'sequence' => 9000, 'state' => 'DRAFT', 'title_en' => 'Legacy link', 'title_fa' => 'پیوند قدیمی']);
            PurchaseOrderItem::create(['project_id' => $this->project->id, 'purchase_order_id' => $po->id, 'requisition_item_id' => $this->mr->items()->firstOrFail()->id, 'line_no' => 1, 'item_code' => 'MR-DETAIL', 'description_en' => 'Actual link', 'description_fa' => 'پیوند واقعی', 'unit' => 'M', 'quantity' => 1]);
            $other = $po->replicate();
            $other->number = 'UNRELATED-SAME-CODE';
            $other->sequence = 9001;
            $other->save();
            PurchaseOrderItem::create(['project_id' => $this->project->id, 'purchase_order_id' => $other->id, 'line_no' => 1, 'item_code' => 'MR-DETAIL', 'description_en' => 'No source link', 'description_fa' => 'بدون پیوند', 'unit' => 'M', 'quantity' => 1]);

            return $po;
        });
        $first = $this->actingAs($this->writer)->get($this->url())->assertOk()->assertSee($lineOnly->number)->assertSee('Source-line link / different or missing header MR')->assertDontSee('UNRELATED-SAME-CODE');
        $this->assertSame(27, $first->viewData('related')->total());
        $this->assertCount(25, $first->viewData('related')->items());
        $second = $this->get($this->url().'&orders_page=2')->assertOk();
        $this->assertCount(2, $second->viewData('related')->items());
    }

    public function test_real_foreign_project_id_cannot_be_read_through_another_project_url(): void
    {
        $other = Project::where('code', 'BB-2')->firstOrFail();
        $foreign = app(TenantContext::class)->runAs($this->writer->company_id, fn () => MaterialRequisition::create([
            'project_id' => $other->id, 'company_id' => $this->writer->company_id, 'number' => 'BB2-DETAIL-MR', 'sequence' => 9000,
            'state' => 'DRAFT', 'discipline' => 'PIPING', 'title_en' => 'Other project', 'title_fa' => 'پروژه دیگر', 'raised_by' => $this->writer->id, 'raised_at' => now(),
        ]), $other->id);
        $this->actingAs($this->writer)->get('/projects/BB-2/procurement/requisitions/'.$foreign->id)->assertOk();
        $this->get('/projects/PH-14/procurement/requisitions/'.$foreign->id)->assertNotFound();
        $this->get('/projects/BB-2/procurement/requisitions/'.$this->mr->id)->assertNotFound();
    }

    public function test_related_order_discipline_scope_does_not_expand_from_visible_mr(): void
    {
        $order = $this->bound(fn () => PurchaseOrder::forceCreate(['project_id' => $this->project->id, 'company_id' => $this->writer->company_id,
            'vendor_company_id' => Company::where('code', 'VEN-AZAR')->firstOrFail()->id, 'requisition_id' => $this->mr->id, 'material_category' => 'PIPE',
            'number' => 'CIVIL-LINK-DETAIL', 'sequence' => 9000, 'state' => 'DRAFT', 'discipline' => 'CIVIL', 'title_en' => 'Historical mismatched discipline', 'title_fa' => 'قدیمی']));
        $this->bound(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => ['PIPING']]));
        $this->actingAs($this->writer)->get($this->url())->assertOk()->assertDontSee($order->number)->assertSee('Your current discipline scope');
        $this->bound(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->update(['discipline_scope' => null]));
        $this->get($this->url())->assertOk()->assertSee($order->number);
    }

    public function test_archived_project_remains_readable_without_write_controls(): void
    {
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Read-only MR sheet');
        $this->actingAs($this->writer)->get($this->url())->assertOk()->assertSee('Material requisition details')->assertDontSee('name="quantity"', false)->assertDontSee('method="POST" action="/projects/PH-14/procurement/requisition', false);
    }

    public function test_legacy_unclassified_rejection_metadata_is_explicit_and_escaped(): void
    {
        $this->mr = $this->bound(fn () => MaterialRequisition::create(['project_id' => $this->project->id, 'company_id' => $this->writer->company_id, 'number' => 'LEGACY-REJECTED-MR', 'sequence' => 9000, 'state' => 'REJECTED', 'discipline' => 'PIPING', 'title_en' => 'Old rejected MR', 'title_fa' => 'درخواست قدیمی', 'raised_by' => $this->writer->id, 'raised_at' => '2026-10-01', 'rejected_by' => $this->owner->id, 'rejected_at' => '2026-10-02', 'rejection_reason' => '<script>rejection</script>']));
        $this->actingAs($this->writer)->get($this->url())->assertOk()->assertSee('Unclassified legacy document')->assertSee('2026-10-02')->assertSee('&lt;script&gt;rejection&lt;/script&gt;', false)->assertDontSee('<script>rejection</script>', false)->assertSee('Not recorded / not visible');
    }
}
