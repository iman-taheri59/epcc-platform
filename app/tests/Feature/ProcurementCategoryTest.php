<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialItem;
use App\Models\MaterialRequisition;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Services\MaterialRequisitionAccessService;
use App\Services\MaterialRequisitionEntryService;
use App\Services\OrderGapService;
use App\Services\ProcurementService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProcurementCategoryTest extends TestCase
{
    private $writer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $owner = $this->userByEmail('karimi@nioc.example');
        $policy = ['version' => 0, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Synthetic category entry tests'];
        app(MaterialRequisitionAccessService::class)->configure($this->project, $owner, $policy);
        app(PurchaseOrderAccessService::class)->configure($this->project, $owner, $policy);
        $this->actingAs($this->writer);
    }

    private function bound(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->writer->company_id, $fn, $this->project->id);
    }

    private function payload(string $kind, string $category = 'PIPE'): array
    {
        $data = ['title_en' => 'CAT-'.$kind.'-'.$category, 'title_fa' => 'سند دسته کالا', 'discipline' => 'PIPING', 'material_category' => $category,
            'lines' => [['item_code' => 'CAT-PIPE', 'description_en' => 'Declared line', 'description_fa' => 'قلم اظهارشده', 'unit' => 'M', 'quantity' => '2.100']]];
        if ($kind === 'po') {
            $data['vendor_company_id'] = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id);
        }

        return $data;
    }

    private function url(string $kind): string
    {
        return '/projects/PH-14/procurement/'.($kind === 'mr' ? 'requisitions' : 'orders').'?lang=en';
    }

    private function create(string $kind, ?array $data = null): mixed
    {
        return app($kind === 'mr' ? MaterialRequisitionEntryService::class : PurchaseOrderEntryService::class)
            ->create($this->project, $this->writer, $data ?? $this->payload($kind));
    }

    private function material(string $category = 'PIPE', string $code = 'CAT-PIPE', ?Project $project = null): MaterialItem
    {
        $project ??= $this->project;

        return app(TenantContext::class)->runAs($this->writer->company_id, fn () => MaterialItem::create([
            'project_id' => $project->id, 'discipline' => 'PIPING', 'category' => $category, 'commodity_code' => $code,
            'description_en' => 'Exact identity', 'description_fa' => 'هویت دقیق', 'base_unit' => 'M', 'source' => 'PROJECT_CATALOGUE', 'status' => 'ACTIVE',
        ]), $project->id);
    }

    public function test_category_is_required_and_uses_mto_vocabulary_not_discipline_for_both_entry_paths(): void
    {
        foreach (['mr', 'po'] as $kind) {
            foreach ([null, '', 'PIPING', 'BOLTING', ['PIPE']] as $category) {
                $data = $this->payload($kind);
                $data['material_category'] = $category;
                $this->postJson($this->url($kind), [$kind => $data])->assertUnprocessable();
            }
        }
        $this->bound(function (): void {
            $this->assertSame(0, MaterialRequisition::count());
            $this->assertSame(0, PurchaseOrder::count());
            $this->assertSame(0, (int) $this->project->fresh()->material_requisition_sequence);
            $this->assertSame(0, (int) $this->project->fresh()->purchase_order_sequence);
        });
    }

    public function test_all_eight_categories_allow_separate_documents_and_repeated_same_category(): void
    {
        foreach (['mr', 'po'] as $kind) {
            foreach (MaterialItem::categories() as $category) {
                $document = $this->create($kind, $this->payload($kind, $category));
                $this->assertSame($category, $document->material_category);
                $this->assertSame('DRAFT', $document->state);
            }
            $again = $this->create($kind);
            $this->assertSame('PIPE', $again->material_category);
        }
        $this->bound(function (): void {
            $this->assertSame(9, MaterialRequisition::count());
            $this->assertSame(9, PurchaseOrder::count());
        });
    }

    public function test_mixed_explicit_line_categories_and_mismatching_catalogue_identity_are_rejected(): void
    {
        $flange = $this->material('FLANGE', 'CAT-FLANGE');
        foreach (['mr', 'po'] as $kind) {
            $data = $this->payload($kind);
            $data['lines'][0]['material_category'] = 'FLANGE';
            $this->postJson($this->url($kind), [$kind => $data])->assertUnprocessable()->assertJsonValidationErrors('lines.0.material_category');
            $data = $this->payload($kind);
            $data['lines'][0]['material_item_id'] = $flange->id;
            $this->postJson($this->url($kind), [$kind => $data])->assertUnprocessable()->assertJsonValidationErrors('lines.0.material_item_id');
        }
        $this->bound(fn () => $this->assertSame(0, PurchaseOrder::count() + MaterialRequisition::count()));
    }

    public function test_valid_identity_is_persisted_and_wrong_code_unit_discipline_or_withdrawal_is_rejected(): void
    {
        $material = $this->material();
        foreach (['mr', 'po'] as $kind) {
            $data = $this->payload($kind);
            $data['lines'][0]['material_item_id'] = $material->id;
            $doc = $this->create($kind, $data);
            $this->bound(fn () => $this->assertSame($material->id, $doc->items()->sole()->material_item_id));
            foreach (['item_code' => 'WRONG', 'unit' => 'EA', 'material_item_id' => (string) Str::uuid()] as $field => $value) {
                $bad = $data;
                $bad['lines'][0][$field] = $value;
                $this->postJson($this->url($kind), [$kind => $bad])->assertUnprocessable();
            }
            $bad = $data;
            $bad['discipline'] = 'MECHANICAL';
            $this->postJson($this->url($kind), [$kind => $bad])->assertUnprocessable();
        }
        $this->bound(fn () => $material->update(['status' => 'WITHDRAWN']));
        foreach (['mr', 'po'] as $kind) {
            $data = $this->payload($kind);
            $data['lines'][0]['material_item_id'] = $material->id;
            $this->postJson($this->url($kind), [$kind => $data])->assertUnprocessable();
        }
    }

    public function test_foreign_project_identity_cannot_be_used_even_for_a_shared_participant(): void
    {
        $other = Project::where('code', 'BB-2')->firstOrFail();
        $foreign = $this->material('PIPE', 'CAT-PIPE', $other);
        foreach (['mr', 'po'] as $kind) {
            $data = $this->payload($kind);
            $data['lines'][0]['material_item_id'] = $foreign->id;
            $this->postJson($this->url($kind), [$kind => $data])->assertUnprocessable();
        }
    }

    public function test_bilingual_forms_register_filter_details_and_manual_basis(): void
    {
        $pipeMr = $this->create('mr');
        $pipePo = $this->create('po');
        $flangeMr = $this->create('mr', $this->payload('mr', 'FLANGE'));
        $flangePo = $this->create('po', $this->payload('po', 'FLANGE'));
        $this->get('/projects/PH-14/procurement?lang=en&material_category=PIPE')->assertOk()
            ->assertSee('name="mr[material_category]"', false)->assertSee('name="po[material_category]"', false)
            ->assertSee($pipeMr->number)->assertSee($pipePo->number)->assertDontSee($flangeMr->number)->assertDontSee($flangePo->number)
            ->assertSee('Manually declared category');
        $this->get('/projects/PH-14/procurement?lang=fa&material_category=FLANGE')->assertOk()->assertSee('فلنج‌ها')->assertSee($flangeMr->number)->assertDontSee($pipeMr->number);
        $this->get('/projects/PH-14/procurement/orders/'.$pipePo->id.'?lang=en')->assertOk()->assertSee('Pipes')->assertSee('Manually declared category');
        $this->get('/projects/PH-14/procurement?lang=en&material_category=BAD')->assertSessionHasErrors('material_category');
        $this->getJson('/projects/PH-14/procurement?material_category[]=PIPE')->assertUnprocessable();
        $this->get('/projects/PH-14/procurement?material_category=UNCLASSIFIED')->assertOk()->assertDontSee($pipeMr->number)->assertDontSee($flangePo->number);
    }

    public function test_catalogue_basis_is_visible_for_both_document_types(): void
    {
        $material = $this->material();
        foreach (['mr', 'po'] as $kind) {
            $data = $this->payload($kind);
            $data['lines'][0]['material_item_id'] = $material->id;
            $doc = $this->create($kind, $data);
            $url = $kind === 'mr' ? '/projects/PH-14/procurement?lang=en' : '/projects/PH-14/procurement/orders/'.$doc->id.'?lang=en';
            $this->get($url)->assertOk()->assertSee('Catalogue-linked category');
        }
    }

    public function test_sql_backstops_prevent_reclassification_and_wrong_category_line_attribution(): void
    {
        $flange = $this->material('FLANGE', 'CAT-FLANGE');
        foreach (['mr', 'po'] as $kind) {
            $doc = $this->create($kind);
            try {
                $this->bound(fn () => $doc->update(['material_category' => 'FLANGE']));
                $this->fail('Expected immutable category');
            } catch (QueryException $e) {
                $this->assertStringContainsString('PROCUREMENT_CATEGORY_IMMUTABLE', $e->getMessage());
            }
            try {
                $this->bound(fn () => $doc->items()->firstOrFail()->update(['material_item_id' => $flange->id]));
                $this->fail('Expected category mismatch');
            } catch (QueryException $e) {
                $this->assertStringContainsString('PROCUREMENT_LINE_CATEGORY_MISMATCH', $e->getMessage());
            }
        }
    }

    public function test_existing_trusted_approved_mr_conversion_preserves_category_and_sql_rejects_wrong_source(): void
    {
        $mr = $this->create('mr');
        $this->bound(fn () => $mr->update(['state' => 'APPROVED']));
        $po = $this->bound(fn () => app(ProcurementService::class)->createPurchaseOrder($this->project, $this->writer,
            Company::where('code', 'VEN-AZAR')->firstOrFail(), 'Converted', 'تبدیل', $this->payload('po')['lines'], $mr->fresh()));
        $this->assertSame('PIPE', $po->material_category);
        $flange = $this->create('mr', $this->payload('mr', 'FLANGE'));
        try {
            $this->bound(fn () => $po->update(['requisition_id' => $flange->id]));
            $this->fail('Expected MR/PO category mismatch');
        } catch (QueryException $e) {
            $this->assertStringContainsString('MR_PO_CATEGORY_MISMATCH', $e->getMessage());
        }
    }

    public function test_category_is_preserved_when_existing_amendment_service_creates_successor(): void
    {
        $po = $this->create('po');
        $this->bound(function () use ($po): void {
            $next = app(OrderGapService::class)->amend($po->items()->firstOrFail(), 3.0);
            $this->assertSame('PIPE', $next->purchaseOrder->material_category);
            $this->assertNull($next->material_item_id);
        });
    }

    public function test_sql_requires_category_when_assigning_new_human_author_basis(): void
    {
        $this->bound(function (): void {
            foreach (['mr', 'po'] as $kind) {
                $data = $this->payload($kind);
                $doc = $kind === 'mr'
                    ? app(ProcurementService::class)->raiseRequisition($this->project, $this->writer, 'Legacy primitive', 'ابزار داخلی', 'PIPING', $data['lines'])
                    : app(ProcurementService::class)->createPurchaseOrder($this->project, $this->writer, Company::where('code', 'VEN-AZAR')->firstOrFail(), 'Legacy primitive', 'ابزار داخلی', $data['lines']);
                $policy = app($kind === 'mr' ? MaterialRequisitionAccessService::class : PurchaseOrderAccessService::class)->policyId($this->project, $this->writer, 'PIPING');
                try {
                    DB::transaction(function () use ($doc, $policy, $kind): void {
                        $doc->author_policy_id = $policy;
                        if ($kind === 'po') {
                            $doc->drafted_by = $this->writer->id;
                            $doc->discipline = 'PIPING';
                        }
                        $doc->save();
                    });
                    $this->fail('Expected category requirement');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('PROCUREMENT_CATEGORY_REQUIRED', $e->getMessage());
                }
            }
        });
    }

    public function test_filter_preserves_tenant_isolation_and_does_not_grant_authority(): void
    {
        $mr = $this->create('mr');
        $po = $this->create('po');
        $this->actingAs($this->userByEmail('mousavi@sahand.example'))
            ->get('/projects/PH-14/procurement?lang=en&material_category=PIPE')->assertOk()->assertDontSee($mr->number)->assertDontSee($po->number);
        $data = $this->payload('po');
        $this->postJson($this->url('po'), ['po' => $data])->assertForbidden();
        $this->actingAs($this->userByEmail('ahmadi@nigc.example'))
            ->get('/projects/PH-14/procurement?material_category=PIPE')->assertNotFound();
    }
}
