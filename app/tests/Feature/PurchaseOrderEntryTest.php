<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Services\MaterialRequisitionAccessService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseOrderEntryTest extends TestCase
{
    private $writer;

    private $owner;

    private Project $project;

    private string $create = '/projects/PH-14/procurement/orders?lang=en';

    private string $configure = '/projects/PH-14/procurement/order-author-access?lang=en';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $this->owner = $this->userByEmail('karimi@nioc.example');
    }

    private function bound(callable $fn, $actor = null): mixed
    {
        return app(TenantContext::class)->runAs(($actor ?? $this->writer)->company_id, $fn, $this->project->id);
    }

    private function policy(int $version = 0): array
    {
        return ['version' => $version, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Synthetic PO nomination'];
    }

    private function nominate(): void
    {
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, $this->policy());
    }

    private function payload(): array
    {
        $vendor = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail());

        return ['vendor_company_id' => $vendor->id, 'title_en' => 'Human order', 'title_fa' => 'سفارش انسانی', 'discipline' => 'PIPING', 'material_category' => 'VALVE',
            'currency' => 'IRR', 'promised_date' => '2026-11-01', 'lines' => [['item_code' => 'VALVE-PO', 'description_en' => 'Valve',
                'description_fa' => 'شیر', 'quantity' => '0002.125', 'unit' => 'EA', 'unit_price' => '12.40']]];
    }

    public function test_no_default_author_or_inheritance_from_mr_and_guests_denied(): void
    {
        $this->post($this->create, ['po' => $this->payload()])->assertRedirect('/login');
        app(MaterialRequisitionAccessService::class)->configure($this->project, $this->owner, $this->policy());
        $this->actingAs($this->writer)->postJson($this->create, ['po' => $this->payload()])->assertForbidden();
        $this->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertDontSee('id="po-create-form"', false);
    }

    public function test_owner_configures_and_nominee_creates_only_draft_with_exact_sources(): void
    {
        $this->actingAs($this->owner)->post($this->configure, ['po_access' => $this->policy()])->assertRedirect()->assertSessionHasNoErrors();
        $this->get('/projects/PH-14/procurement?lang=fa')->assertOk()->assertSee('po-access-form');
        $this->actingAs($this->writer)->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertSee('po-create-form');
        $this->post($this->create, ['po' => $this->payload()])->assertRedirect()->assertSessionHasNoErrors();
        $this->bound(function (): void {
            $po = PurchaseOrder::firstOrFail();
            $line = $po->items()->firstOrFail();
            $this->assertSame('DRAFT', $po->state);
            $this->assertNull($po->issued_by);
            $this->assertNull($po->issued_at);
            $this->assertNull($po->requisition_id);
            $this->assertSame($this->writer->id, $po->drafted_by);
            $this->assertNotNull($po->author_policy_id);
            $this->assertSame('PIPING', $po->discipline);
            $this->assertSame('0002.125', $line->source_quantity);
            $this->assertSame('2.125', $line->quantity);
            $this->assertSame('26.35', $po->total_value);
            $this->assertSame('PH-14-PO-0001', $po->number);
            $this->get('/projects/PH-14/procurement/orders/'.$po->id.'?lang=fa')->assertOk()->assertSee('سفارش انسانی');
        });
    }

    public function test_stale_configuration_and_nonowner_configuration_are_rejected(): void
    {
        $this->actingAs($this->writer)->postJson($this->configure, ['po_access' => $this->policy()])->assertForbidden();
        $this->nominate();
        $this->actingAs($this->owner)->postJson($this->configure, ['po_access' => $this->policy()])->assertUnprocessable();
        $this->bound(fn () => $this->assertSame(1, DB::table('po_author_policies')->count()), $this->owner);
    }

    public function test_suspension_revokes_entry_immediately(): void
    {
        $this->nominate();
        $p = $this->policy(1);
        $p['suspend'] = true;
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, $p);
        $this->actingAs($this->writer)->postJson($this->create, ['po' => $this->payload()])->assertForbidden();
    }

    public function test_unknown_or_protected_fields_and_non_decimal_quantities_do_not_write(): void
    {
        $this->nominate();
        $p = $this->payload();
        foreach (['state' => 'ISSUED', 'company_id' => $this->owner->company_id, 'issued_by' => $this->owner->id, 'requisition_id' => $this->project->id] as $k => $v) {
            $x = $p;
            $x[$k] = $v;
            $this->actingAs($this->writer)->postJson($this->create, ['po' => $x])->assertUnprocessable();
        }
        foreach (['0', '-1', '1e2', ' 2', '2.0001', '9999999999999999'] as $q) {
            $x = $p;
            $x['lines'][0]['quantity'] = $q;
            $this->postJson($this->create, ['po' => $x])->assertUnprocessable();
        }
        $this->bound(fn () => $this->assertSame(0, PurchaseOrder::count()));
    }

    public function test_vendor_and_subsystem_cannot_cross_scope(): void
    {
        $this->nominate();
        $p = $this->payload();
        $p['vendor_company_id'] = $this->writer->company_id;
        $this->actingAs($this->writer)->postJson($this->create, ['po' => $p])->assertUnprocessable();
        $p = $this->payload();
        $p['lines'][0]['subsystem_id'] = (string) Str::uuid();
        $this->postJson($this->create, ['po' => $p])->assertUnprocessable();
        $this->bound(fn () => $this->assertSame(0, PurchaseOrder::count()));
    }

    public function test_missing_and_partial_pricing_stay_unknown_and_complete_pricing_is_exact(): void
    {
        $this->nominate();
        $p = $this->payload();
        unset($p['lines'][0]['unit_price'],$p['currency']);
        $po = app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, $p, 'en');
        $this->assertNull($po->total_value);
        $p = $this->payload();
        $p['lines'][] = $p['lines'][0];
        unset($p['lines'][1]['unit_price']);
        $po = app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, $p, 'en');
        $this->assertNull($po->total_value);
        $p = $this->payload();
        $p['lines'][0]['quantity'] = '999999999999999';
        $p['lines'][0]['unit_price'] = '9999999999999999.99';
        $this->actingAs($this->writer)->postJson($this->create, ['po' => $p])->assertUnprocessable();
    }

    public function test_live_membership_discipline_and_entitlement_revocation(): void
    {
        $this->nominate();
        $this->bound(fn () => $this->writer->memberships()->where('project_id', $this->project->id)->update(['discipline_scope' => json_encode(['CIVIL'])]));
        $this->actingAs($this->writer)->postJson($this->create, ['po' => $this->payload()])->assertForbidden();
        $this->bound(fn () => $this->writer->memberships()->where('project_id', $this->project->id)->delete());
        $this->postJson($this->create, ['po' => $this->payload()])->assertNotFound();
    }

    public function test_archive_blocks_policy_and_entry_but_preserves_read(): void
    {
        $this->nominate();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Synthetic');
        $this->actingAs($this->writer)->postJson($this->create, ['po' => $this->payload()])->assertUnprocessable();
        $this->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertDontSee('id="po-create-form"', false);
        $this->actingAs($this->owner)->postJson($this->configure, ['po_access' => $this->policy(1)])->assertForbidden();
    }

    public function test_late_failure_rolls_back_header_items_and_counter(): void
    {
        $this->nominate();
        $this->bound(fn () => DB::unprepared("CREATE OR REPLACE FUNCTION po_test_reject() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.source_quantity IS NOT NULL THEN RAISE EXCEPTION 'synthetic'; END IF; RETURN NEW; END $$; CREATE TRIGGER po_test_reject BEFORE UPDATE ON purchase_order_items FOR EACH ROW EXECUTE FUNCTION po_test_reject();"));
        try {
            app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, $this->payload(), 'en');
            $this->fail('Expected rollback');
        } catch (QueryException $e) {
            $this->assertStringContainsString('synthetic', $e->getMessage());
        }
        $this->bound(function (): void {
            $this->assertSame(0, PurchaseOrder::count());
            $this->assertSame(0, PurchaseOrderItem::count());
            $this->assertSame(0, (int) $this->project->fresh()->purchase_order_sequence);
        });
    }

    public function test_policy_history_and_entry_basis_cannot_be_rewritten(): void
    {
        $this->nominate();
        $po = app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, $this->payload(), 'en');
        $this->bound(function () use ($po): void {
            $this->assertSame(0, DB::table('po_author_policies')->update(['reason' => 'rewrite']));
            $this->assertSame(0, DB::table('po_author_policies')->delete());
            $this->expectException(QueryException::class);
            $po->author_policy_id = null;
            $po->save();
        });
    }

    public function test_role_nomination_live_revocation_and_global_cross_company_numbers(): void
    {
        $p = $this->policy();
        $p['selectors'] = ['role:'.$this->writer->company_id.':project_manager', 'user:'.$this->owner->id];
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, $p);
        $one = app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, $this->payload(), 'en');
        $two = app(PurchaseOrderEntryService::class)->create($this->project, $this->owner, $this->payload(), 'en');
        $this->assertSame('PH-14-PO-0001', $one->number);
        $this->assertSame('PH-14-PO-0002', $two->number);
        $this->bound(fn () => $this->writer->memberships()->where('project_id', $this->project->id)->update(['role_codes' => json_encode([])]));
        $this->actingAs($this->writer)->postJson($this->create, ['po' => $this->payload()])->assertForbidden();
    }

    public function test_plan_revocation_blocks_entry_and_direct_detail(): void
    {
        $this->nominate();
        $po = app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, $this->payload(), 'en');
        $this->bound(function (): void {
            $plan = Plan::where('code', 'starter')->firstOrFail();
            $this->writer->company->update(['plan_id' => $plan->id]);
        });
        $this->actingAs($this->writer)->postJson($this->create, ['po' => $this->payload()])->assertForbidden();
        $this->get('/projects/PH-14/procurement/orders/'.$po->id.'?lang=en')->assertForbidden();
    }
}
