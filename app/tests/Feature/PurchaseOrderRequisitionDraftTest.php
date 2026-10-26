<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Facility;
use App\Models\MaterialItem;
use App\Models\MaterialRequisition;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Subsystem;
use App\Services\MaterialRequisitionAccessService;
use App\Services\MaterialRequisitionEntryService;
use App\Services\OrderGapService;
use App\Services\ProcurementService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PurchaseOrderRequisitionDraftTest extends TestCase
{
    private $writer;

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

    public function test_independent_po_nomination_is_required_and_guest_cannot_copy(): void
    {
        $this->post($this->url, ['po_source' => $this->payload()])->assertRedirect('/login');
        $this->actingAs($this->writer)->postJson($this->url, ['po_source' => $this->payload()])->assertForbidden();
        $this->bound(fn () => $this->assertSame(0, PurchaseOrder::count()));
    }

    public function test_exact_full_copy_has_real_line_ids_and_never_consumes_or_approves_mr(): void
    {
        $this->nominate();
        $before = $this->bound(fn () => [$this->mr->fresh()->toArray(), $this->mr->items()->orderBy('line_no')->get()->toArray()]);
        $this->actingAs($this->writer)->post($this->url, ['po_source' => $this->payload()])->assertRedirect()->assertSessionHasNoErrors();
        $this->bound(function () use ($before): void {
            $po = PurchaseOrder::sole();
            $lines = $po->items()->orderBy('line_no')->get();
            $this->assertSame('DRAFT', $po->state);
            $this->assertSame('PIPE', $po->material_category);
            $this->assertSame($this->mr->id, $po->requisition_id);
            $this->assertSame($this->writer->id, $po->drafted_by);
            $this->assertNotNull($po->author_policy_id);
            $this->assertNull($po->total_value);
            $this->assertNull($po->issued_by);
            foreach ($before[1] as $i => $source) {
                $this->assertSame($source['id'], $lines[$i]->requisition_item_id);
                foreach (['item_code', 'description_en', 'description_fa', 'unit', 'quantity', 'source_quantity', 'subsystem_id', 'material_item_id'] as $field) {
                    $this->assertSame($source[$field], $lines[$i]->toArray()[$field]);
                }
                $this->assertNull($lines[$i]->unit_price);
            }
            $this->assertSame($before[0], $this->mr->fresh()->toArray());
            $this->assertSame($before[1], $this->mr->items()->orderBy('line_no')->get()->toArray());
            $this->assertSame('PH-14-PO-0001', $po->number);
        });
    }

    public function test_ui_and_detail_explain_draft_only_provenance_in_both_languages(): void
    {
        $this->nominate();
        $this->actingAs($this->writer)->get('/projects/PH-14/procurement?lang=en')->assertOk()->assertSee('PO draft from this MR')->assertSee('po_source[vendor_company_id]', false);
        $this->get('/projects/PH-14/procurement?lang=fa')->assertOk()->assertSee('پیش‌نویس PO از این MR');
        $po = $this->copy();
        $this->get('/projects/PH-14/procurement/orders/'.$po->id.'?lang=en')->assertOk()->assertSee($this->mr->number)->assertSee('Source MR line 1')->assertSee('Source MR line 2')->assertSee('does not itself reserve');
        $this->get('/projects/PH-14/procurement/orders/'.$po->id.'?lang=fa')->assertOk()->assertSee('قلم MR منبع')->assertSee('رزرو یا مصرف');
    }

    public function test_overrides_partial_selection_and_malformed_input_are_rejected_without_writes(): void
    {
        $this->nominate();
        $this->actingAs($this->writer);
        foreach (['lines' => [], 'material_category' => 'FLANGE', 'quantity' => '1', 'title_en' => 'Forged', 'state' => 'ISSUED', 'currency' => 'IRR', 'unit_price' => '1', 'requisition_item_ids' => []] as $key => $value) {
            $data = $this->payload();
            $data[$key] = $value;
            $this->postJson($this->url, ['po_source' => $data])->assertUnprocessable();
        }
        $data = $this->payload();
        $data['requisition_id'] = ['bad'];
        $this->postJson($this->url, ['po_source' => $data])->assertUnprocessable();
        $this->bound(fn () => $this->assertSame(0, PurchaseOrder::count()));
    }

    public function test_only_current_approved_classified_source_is_eligible(): void
    {
        $this->nominate();
        $this->actingAs($this->writer);
        foreach (['DRAFT', 'SUBMITTED', 'TECHNICAL_APPROVED', 'REJECTED', 'ORDERED'] as $state) {
            $this->bound(fn () => DB::table('material_requisitions')->where('id', $this->mr->id)->update(['state' => $state]));
            $this->postJson($this->url, ['po_source' => $this->payload()])->assertUnprocessable();
        }
        $this->bound(fn () => $this->assertSame(0, PurchaseOrder::count()));
    }

    public function test_visible_other_buyer_mr_is_not_a_cross_company_import_permission(): void
    {
        $ownerPolicy = $this->policy();
        $ownerPolicy['selectors'] = ['user:'.$this->owner->id];
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, $ownerPolicy);
        $this->actingAs($this->owner)->postJson($this->url, ['po_source' => $this->payload()])->assertNotFound();
        $this->actingAs($this->writer)->postJson('/projects/BB-2/procurement/orders/from-requisition', ['po_source' => $this->payload()])->assertForbidden();
    }

    public function test_live_revocation_and_archiving_block_copy(): void
    {
        $this->nominate();
        $this->actingAs($this->writer);
        $policy = $this->policy(1);
        $policy['suspend'] = true;
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, $policy);
        $this->postJson($this->url, ['po_source' => $this->payload()])->assertForbidden();
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, $this->policy(2));
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Synthetic copy archive');
        $this->postJson($this->url, ['po_source' => $this->payload()])->assertUnprocessable();
    }

    public function test_two_drafts_are_not_reservations_and_source_stays_approved(): void
    {
        $this->nominate();
        $a = $this->copy();
        $b = $this->copy();
        $this->assertNotSame($a->number, $b->number);
        $this->bound(function (): void {
            $this->assertSame('APPROVED', $this->mr->fresh()->state);
            $this->assertSame(2, PurchaseOrder::count());
        });
    }

    public function test_late_source_link_failure_rolls_back_header_lines_and_counter(): void
    {
        $this->nominate();
        DB::unprepared("CREATE OR REPLACE FUNCTION po_mr_copy_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'copy_rollback'; END $$; CREATE TRIGGER po_mr_copy_failure BEFORE UPDATE OF requisition_id ON purchase_orders FOR EACH ROW WHEN (NEW.requisition_id IS NOT NULL) EXECUTE FUNCTION po_mr_copy_fail();");
        try {
            $this->copy();
            $this->fail('Expected late failure');
        } catch (QueryException $e) {
            $this->assertStringContainsString('copy_rollback', $e->getMessage());
        }
        $this->bound(function (): void {
            $this->assertSame(0, PurchaseOrder::count());
            $this->assertSame(0, PurchaseOrderItem::count());
            $this->assertSame(0, (int) $this->project->fresh()->purchase_order_sequence);
            $this->assertSame('APPROVED', $this->mr->fresh()->state);
        });
    }

    public function test_source_header_and_line_references_cannot_be_cleared_or_moved(): void
    {
        $this->nominate();
        $po = $this->copy();
        foreach (['header', 'line'] as $part) {
            try {
                $this->bound(function () use ($po, $part): void {
                    if ($part === 'header') {
                        $po->requisition_id = null;
                        $po->save();
                    } else {
                        $po->items()->firstOrFail()->update(['requisition_item_id' => null]);
                    }
                });
                $this->fail('Expected provenance guard');
            } catch (QueryException $e) {
                $this->assertStringContainsString('IMMUTABLE', $e->getMessage());
            }
        }
    }

    public function test_existing_amendment_lineage_retains_source_reference_without_touching_mr(): void
    {
        $this->nominate();
        $po = $this->copy();
        $this->bound(function () use ($po): void {
            $original = $po->items()->orderBy('line_no')->firstOrFail();
            $next = app(OrderGapService::class)->amend($original, 4.0);
            $this->assertSame($original->requisition_item_id, $next->requisition_item_id);
            $this->assertSame($original->id, $next->supersedes_id);
            $this->assertSame('2.100', $original->fresh()->quantity);
            $this->assertSame('APPROVED', $this->mr->fresh()->state);
        });
    }

    public function test_unclassified_or_empty_or_over_limit_sources_are_not_silently_imported(): void
    {
        $this->nominate();
        $this->actingAs($this->writer);
        $legacy = $this->bound(function () {
            $mr = app(ProcurementService::class)->raiseRequisition($this->project, $this->writer, 'MR for Pipes', 'قدیمی', 'PIPING', [
                ['item_code' => 'OLD', 'description_en' => 'Old', 'description_fa' => 'قدیمی', 'unit' => 'M', 'quantity' => '2.100']]);
            $mr->state = 'APPROVED';
            $mr->save();

            return $mr;
        });
        $data = $this->payload();
        $data['requisition_id'] = $legacy->id;
        $this->postJson($this->url, ['po_source' => $data])->assertUnprocessable();
        $this->bound(function (): void {
            for ($i = 3; $i <= 21; $i++) {
                $this->mr->items()->create(['project_id' => $this->project->id, 'line_no' => $i, 'item_code' => 'EXTRA', 'description_en' => 'Extra', 'description_fa' => 'اضافی', 'unit' => 'M', 'quantity' => '1.000']);
            }
        });
        $this->postJson($this->url, ['po_source' => $this->payload()])->assertUnprocessable();
        $this->bound(fn () => $this->mr->items()->delete());
        $this->postJson($this->url, ['po_source' => $this->payload()])->assertUnprocessable();
        $this->bound(fn () => $this->assertSame(0, PurchaseOrder::count()));
    }

    public function test_catalogue_identity_and_subsystem_are_preserved_without_code_matching(): void
    {
        $this->nominate();
        [$materialId,$subsystemId] = $this->bound(function (): array {
            $m = MaterialItem::create(['project_id' => $this->project->id, 'discipline' => 'PIPING', 'category' => 'PIPE', 'commodity_code' => 'SAME-CODE', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'base_unit' => 'M', 'source' => 'PROJECT_CATALOGUE', 'status' => 'ACTIVE']);
            $base = ['project_id' => $this->project->id, 'company_id' => $this->writer->company_id, 'code' => 'COPY-SCOPE', 'name_en' => 'Copy scope', 'name_fa' => 'محدوده کپی'];
            $f = Facility::create($base + ['kind' => 'PLANT']);
            $scope = Subsystem::create($base + ['facility_id' => $f->id, 'discipline' => 'PIPING']);
            $line = $this->mr->items()->orderBy('line_no')->firstOrFail();
            $line->material_item_id = $m->id;
            $line->subsystem_id = $scope->id;
            $line->save();

            return [$m->id, $scope->id];
        });
        $po = $this->copy();
        $this->bound(function () use ($po, $materialId, $subsystemId): void {
            $lines = $po->items()->orderBy('line_no')->get();
            $this->assertSame($materialId, $lines[0]->material_item_id);
            $this->assertSame($subsystemId, $lines[0]->subsystem_id);
            $this->assertNull($lines[1]->material_item_id);
            $this->assertNotSame($lines[0]->requisition_item_id, $lines[1]->requisition_item_id);
        });
    }

    public function test_sql_rejects_an_inexact_initial_copy_and_moving_linked_line_to_unlinked_po(): void
    {
        $this->nominate();
        $linked = $this->copy();
        $manual = app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, [
            'vendor_company_id' => $this->payload()['vendor_company_id'], 'title_en' => 'Manual', 'title_fa' => 'دستی', 'discipline' => 'PIPING', 'material_category' => 'PIPE',
            'lines' => [['item_code' => 'WRONG', 'description_en' => 'Wrong', 'description_fa' => 'نادرست', 'unit' => 'M', 'quantity' => '1.000']],
        ]);
        try {
            $this->bound(function () use ($manual): void {
                $manual->items()->firstOrFail()->update(['requisition_item_id' => $this->mr->items()->orderBy('line_no')->firstOrFail()->id]);
                $manual->requisition_id = $this->mr->id;
                $manual->save();
            });
            $this->fail('Expected exact copy guard');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PO_DRAFT_SOURCE_COPY_MISMATCH', $e->getMessage());
        }
        try {
            $this->bound(fn () => $linked->items()->orderBy('line_no')->firstOrFail()->update(['purchase_order_id' => $manual->id]));
            $this->fail('Expected immutable source line');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PO_DRAFT_SOURCE_LINE_IMMUTABLE', $e->getMessage());
        }
    }

    public function test_classified_legacy_source_without_source_spelling_uses_stored_exact_quantity(): void
    {
        $this->nominate();
        $this->bound(fn () => DB::table('material_requisition_items')->where('requisition_id', $this->mr->id)->update(['source_quantity' => null]));
        $po = $this->copy();
        $this->bound(function () use ($po): void {
            $this->assertSame('2.100', $po->items()->orderBy('line_no')->firstOrFail()->source_quantity);
            $this->assertNull($this->mr->items()->orderBy('line_no')->firstOrFail()->source_quantity);
        });
    }
}
