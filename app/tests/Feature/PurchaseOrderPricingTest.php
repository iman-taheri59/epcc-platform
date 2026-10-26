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
use App\Services\OrderGapService;
use App\Services\ProcurementService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Services\PurchaseOrderExecutionAccessService;
use App\Services\PurchaseOrderExecutionService;
use App\Services\PurchaseOrderPricingHistoryService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseOrderPricingTest extends TestCase
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

    private function draft(): PurchaseOrder
    {
        $this->nominate();

        return $this->copy();
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

    public function test_order_gap_http_cannot_amend_controlled_source_quantity_even_for_original_drafter(): void
    {
        $po = $this->draft();
        $line = $this->bound(fn () => $po->items()->firstOrFail());
        $response = $this->actingAs($this->writer)->postJson('/projects/PH-14/procurement/order-gap/amend?lang=en', [
            'item_id' => $line->id, 'quantity' => '2.000',
        ]);
        $this->assertSame(2, $this->bound(fn () => $po->items()->count()), 'Human OrderGap must not create a successor changing the approved source quantity');
        $response->assertUnprocessable()->assertJsonValidationErrors('purchase_order_id');
    }

    public function test_order_gap_human_boundary_rejects_quantity_and_identity_for_all_visible_callers_without_writes(): void
    {
        $po = $this->draft();
        $line = $this->bound(fn () => $po->items()->firstOrFail());
        $vendor = app(TenantContext::class)->forIdentityLookup(fn () => User::create([
            'company_id' => $po->vendor_company_id, 'name' => 'Boundary vendor', 'email' => 'boundary@vendor.example', 'password' => 'test-only', 'is_active' => true,
        ]));
        $snapshot = fn () => $this->bound(function (): array {
            $rows = [];
            foreach (['material_requisitions', 'material_requisition_items', 'purchase_orders', 'purchase_order_items', 'po_pricing_revisions', 'po_execution_decisions', 'po_mr_allocations'] as $table) {
                $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }

            return $rows;
        });
        $before = $snapshot();
        foreach ([$this->writer, $this->owner, $this->userByEmail('tehrani@pmc.example'), $this->userByEmail('sadeghi@pars.example'), $vendor] as $actor) {
            foreach (['amend' => ['quantity' => '1.000'], 'attribute' => ['material_item_id' => (string) Str::uuid()]] as $action => $data) {
                $this->actingAs($actor)->postJson('/projects/PH-14/procurement/order-gap/'.$action.'?lang=en', ['item_id' => $line->id] + $data)
                    ->assertUnprocessable()->assertJsonValidationErrors('purchase_order_id')->assertSee('read-only in OrderGap');
            }
        }
        $this->assertSame($before, $snapshot());
    }

    public function test_order_gap_human_ui_hides_only_protected_source_forms_in_both_languages(): void
    {
        $po = $this->draft();
        $line = $this->bound(fn () => $po->items()->firstOrFail());
        foreach (['en', 'fa'] as $lang) {
            $response = $this->actingAs($this->writer)->get('/projects/PH-14/procurement/order-gap?lang='.$lang)->assertOk();
            $response->assertSee('data-source-readonly="'.$line->id.'"', false);
            $dom = new \DOMDocument;
            @$dom->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
            $xpath = new \DOMXPath($dom);
            foreach (['attribute' => 0, 'amend' => 0, 'allocate' => 1] as $action => $count) {
                $this->assertSame($count, $xpath->query('//form[contains(@action,"order-gap/'.$action.'") and input[@name="item_id" and @value="'.$line->id.'"]]')->length);
            }
            $response->assertSee($lang === 'fa' ? 'در شکاف سفارش قابل ویرایش نیست' : 'read-only in OrderGap');
        }
    }

    public function test_order_gap_human_checks_fresh_parent_not_forged_cached_source_markers(): void
    {
        $po = $this->draft();
        $this->bound(function () use ($po): void {
            $line = $po->items()->firstOrFail();
            $fakeParent = clone $po;
            $fakeParent->author_policy_id = null;
            $fakeParent->requisition_id = null;
            $line->setRelation('purchaseOrder', $fakeParent);
            foreach (['amend', 'attribute'] as $action) {
                try {
                    if ($action === 'amend') {
                        app(OrderGapService::class)->humanAmend($line, 1, 'fa');
                    } else {
                        app(OrderGapService::class)->humanAttribute($this->project, $line, (string) Str::uuid(), 'fa');
                    }
                    $this->fail('Cached source-marker removal must not bypass human protection');
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('purchase_order_id', $e->errors());
                    $this->assertStringContainsString('در شکاف سفارش قابل ویرایش نیست', $e->errors()['purchase_order_id'][0]);
                }
            }
            $this->assertSame(2, $po->items()->count());
        });
    }

    public function test_order_gap_human_refusal_does_not_block_accepted_controlled_pricing(): void
    {
        $po = $this->draft();
        $line = $this->bound(fn () => $po->items()->firstOrFail());
        $prices = $this->prices($po);
        $this->actingAs($this->writer)->postJson('/projects/PH-14/procurement/order-gap/amend?lang=en', ['item_id' => $line->id, 'quantity' => 1])->assertUnprocessable();
        $this->postJson($this->pricingUrl($po), ['pricing' => $prices])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(1, $this->bound(fn () => DB::table('po_pricing_revisions')->where('purchase_order_id', $po->id)->count()));
        $this->assertSame('APPROVED', $this->bound(fn () => $this->mr->fresh()->state));
        $this->assertSame('DRAFT', $this->bound(fn () => $po->fresh()->state));
        $this->assertSame(2, $this->bound(fn () => $po->items()->count()));
    }

    private function pricingInputValue($response, string $name): string
    {
        $doc = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML($response->getContent(), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $nodes = (new \DOMXPath($doc))->query('//input[@name="'.$name.'"]');
        $this->assertSame(1, $nodes->length, $name);

        return $nodes->item(0)->getAttribute('value');
    }

    public function test_pricing_form_recovers_validation_input_by_uuid_and_can_submit_corrected_complete_prices(): void
    {
        $po = $this->draft();
        $input = $this->prices($po);
        $ids = array_column($input['lines'], 'id');
        $input['lines'][0]['unit_price'] = '11.11';
        $input['lines'][1]['unit_price'] = '22.22';
        $input['lines'] = array_reverse($input['lines']);
        $input['currency'] = 'EUR';
        $input['reason'] = '';
        $this->actingAs($this->writer)->from($this->detailUrl($po))->post($this->pricingUrl($po), ['pricing' => $input])
            ->assertRedirect($this->detailUrl($po))->assertSessionHasErrors('pricing.reason');
        $page = $this->get($this->detailUrl($po))->assertOk()->assertDontSee('Previous pricing input was not restored');
        $this->assertSame('EUR', $this->pricingInputValue($page, 'pricing[currency]'));
        $corrected = ['content_hash' => $this->pricingInputValue($page, 'pricing[content_hash]'), 'currency' => 'EUR', 'reason' => 'Corrected reason', 'lines' => []];
        foreach (['11.11', '22.22'] as $index => $price) {
            $this->assertSame($ids[$index], $this->pricingInputValue($page, 'pricing[lines]['.$index.'][id]'));
            $this->assertSame($price, $this->pricingInputValue($page, 'pricing[lines]['.$index.'][unit_price]'));
            $corrected['lines'][] = ['id' => $ids[$index], 'unit_price' => $price];
        }
        $this->assertSame(0, $this->bound(fn () => DB::table('po_pricing_revisions')->count()));
        $this->assertNull($this->bound(fn () => $po->fresh()->total_value));
        $this->post($this->pricingUrl($po), ['pricing' => $corrected])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('89.99', $this->bound(fn () => $po->fresh()->total_value));
        $this->assertSame(1, $this->audit($po)->sequence);
    }

    public function test_pricing_form_preserves_a_blank_price_instead_of_refilling_the_stored_price(): void
    {
        $po = $this->draft();
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $this->prices($po)])->assertRedirect();
        $input = $this->prices($po);
        $input['lines'][0]['unit_price'] = '';
        $input['lines'][1]['unit_price'] = '0.00';
        $input['reason'] = 'Do not silently refill this blank price';
        $this->from($this->detailUrl($po))->post($this->pricingUrl($po), ['pricing' => $input])->assertSessionHasErrors('pricing.lines.0.unit_price');
        $page = $this->get(str_replace('lang=en', 'lang=fa', $this->detailUrl($po)))->assertOk()->assertSee($input['reason']);
        $this->assertSame('', $this->pricingInputValue($page, 'pricing[lines][0][unit_price]'));
        $this->assertSame('0.00', $this->pricingInputValue($page, 'pricing[lines][1][unit_price]'));
        $this->assertSame(1, $this->audit($po)->sequence);
        $this->assertSame('10.01', $this->bound(fn () => $po->items()->orderBy('line_no')->first()->unit_price));
    }

    public function test_pricing_form_does_not_rebase_old_input_onto_a_new_order_version(): void
    {
        $po = $this->draft();
        $stale = $this->prices($po);
        $stale['currency'] = 'EUR';
        $stale['reason'] = 'Do not restore this stale reason';
        $current = $this->prices($po);
        $current['lines'][0]['unit_price'] = '33.33';
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $current])->assertRedirect();
        $this->from($this->detailUrl($po))->post($this->pricingUrl($po), ['pricing' => $stale])->assertSessionHasErrors('pricing');
        $page = $this->get($this->detailUrl($po))->assertOk()->assertSee('Previous pricing input was not restored')->assertDontSee($stale['reason']);
        $this->assertSame('USD', $this->pricingInputValue($page, 'pricing[currency]'));
        $this->assertSame('33.33', $this->pricingInputValue($page, 'pricing[lines][0][unit_price]'));
        $this->assertNotSame($stale['content_hash'], $this->pricingInputValue($page, 'pricing[content_hash]'));
        $this->assertSame(1, $this->audit($po)->sequence);
        $this->from($this->detailUrl($po))->post($this->pricingUrl($po), ['pricing' => $stale])->assertSessionHasErrors('pricing');
        $this->get(str_replace('lang=en', 'lang=fa', $this->detailUrl($po)))->assertOk()->assertSee('ورودی قبلی قیمت‌گذاری بازگردانده نشد');
    }

    public function test_pricing_form_escapes_invalid_scalar_input_and_nested_or_foreign_line_inputs_are_discarded(): void
    {
        $po = $this->draft();
        $input = $this->prices($po);
        $input['lines'][0]['unit_price'] = '\"><img src=x onerror=alert(1)>';
        $this->actingAs($this->writer)->from($this->detailUrl($po))->post($this->pricingUrl($po), ['pricing' => $input])->assertSessionHasErrors();
        $page = $this->get($this->detailUrl($po))->assertOk()->assertDontSee('<img src=x onerror=alert(1)>', false);
        $this->assertSame($input['lines'][0]['unit_price'], $this->pricingInputValue($page, 'pricing[lines][0][unit_price]'));
        foreach (['nested', 'foreign', 'duplicate'] as $case) {
            $bad = $this->prices($po);
            if ($case === 'nested') {
                $bad['lines'][0]['unit_price'] = ['payload'];
            } elseif ($case === 'foreign') {
                $bad['lines'][0]['id'] = (string) Str::uuid();
            } else {
                $bad['lines'][0]['id'] = $bad['lines'][1]['id'];
            }
            $this->from($this->detailUrl($po))->post($this->pricingUrl($po), ['pricing' => $bad])->assertSessionHasErrors();
            $page = $this->get($this->detailUrl($po))->assertOk()->assertSee('Previous pricing input was not restored');
            $this->assertSame('', $this->pricingInputValue($page, 'pricing[lines][0][unit_price]'));
        }
        $this->assertSame(0, $this->bound(fn () => DB::table('po_pricing_revisions')->count()));
    }

    public function test_pricing_form_recovery_does_not_restore_write_access_after_nomination_suspension(): void
    {
        $po = $this->draft();
        $input = $this->prices($po);
        $input['reason'] = '';
        $this->actingAs($this->writer)->from($this->detailUrl($po))->post($this->pricingUrl($po), ['pricing' => $input])->assertSessionHasErrors();
        $policy = $this->policy(1);
        $policy['suspend'] = true;
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, $policy);
        $this->get($this->detailUrl($po))->assertOk()->assertDontSee('name="pricing[content_hash]"', false);
        $this->postJson($this->pricingUrl($po), ['pricing' => $this->prices($po)])->assertForbidden();
        $this->assertSame(0, $this->bound(fn () => DB::table('po_pricing_revisions')->count()));
    }

    public function test_structured_price_history_shows_before_after_by_uuid_not_repeated_item_code(): void
    {
        $po = $this->draft();
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $this->prices($po)])->assertRedirect();
        $second = $this->prices($po);
        $second['currency'] = 'EUR';
        $second['lines'][0]['unit_price'] = '0.00';
        $second['lines'][1]['unit_price'] = '25.25';
        $this->postJson($this->pricingUrl($po), ['pricing' => $second])->assertRedirect()->assertSessionHasNoErrors();
        $response = $this->get($this->detailUrl($po))->assertOk()->assertSee('Recorded pricing comparison')->assertSee('Not recorded / unknown');
        $comparison = $response->viewData('pricingHistory')->first()->comparison;
        $this->assertSame(['currency' => 'USD', 'total' => '51.05'], $comparison['before']);
        $this->assertSame(['currency' => 'EUR', 'total' => '75.75'], $comparison['after']);
        $byId = collect($comparison['lines'])->keyBy('id');
        $this->assertSame('0.00', $byId[$second['lines'][0]['id']]['after']);
        $this->assertSame('25.25', $byId[$second['lines'][1]['id']]['after']);
        $this->assertSame('10.01', $byId[$second['lines'][0]['id']]['before']);
        $this->get(str_replace('lang=en', 'lang=fa', $this->detailUrl($po)))->assertOk()->assertSee('مقایسهٔ قیمت‌گذاری ثبت‌شده')->assertSee('۷۵.۷۵')->assertSee('۰.۰۰');
    }

    public function test_structured_price_history_preserves_large_decimal_text_and_does_not_mutate_evidence(): void
    {
        $po = $this->draft();
        $prices = $this->prices($po);
        foreach ($prices['lines'] as &$line) {
            $line['unit_price'] = '99999999999999.99';
        }
        unset($line);
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $prices])->assertRedirect()->assertSessionHasNoErrors();
        $snapshot = fn () => $this->bound(function (): array {
            $rows = [];
            foreach (['material_requisitions', 'material_requisition_items', 'purchase_orders', 'purchase_order_items', 'po_pricing_revisions', 'po_execution_decisions', 'po_mr_allocations'] as $table) {
                $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }

            return $rows;
        });
        $before = $snapshot();
        $page = $this->get($this->detailUrl($po))->assertOk()->assertSee('509999999999999.95');
        $comparison = $page->viewData('pricingHistory')->first()->comparison;
        $this->assertSame('509999999999999.95', $comparison['after']['total']);
        $this->assertSame('99999999999999.99', $comparison['lines'][0]['after']);
        $this->assertNull($comparison['lines'][0]['before']);
        $this->assertSame($before, $snapshot());
    }

    public function test_structured_price_history_remains_hidden_outside_existing_buyer_project_scope(): void
    {
        $po = $this->draft();
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $this->prices($po)])->assertRedirect();
        $this->actingAs($this->owner)->get($this->detailUrl($po))->assertOk()->assertDontSee('Recorded pricing comparison')->assertViewHas('pricingHistory', fn ($history) => $history->isEmpty());
        app(TenantContext::class)->runAs($this->writer->company_id, fn () => $this->assertCount(0,
            app(PurchaseOrderPricingHistoryService::class)->forOrder($this->project->id, $po->id)), (string) Str::uuid());
        $this->actingAs($this->writer)->get($this->detailUrl($po))->assertOk()->assertViewHas('pricingHistory', fn ($history) => $history->count() === 1);
    }

    public function test_structured_price_history_keeps_latest_twenty_without_deleting_older_revisions(): void
    {
        $po = $this->draft();
        for ($i = 1; $i <= 21; $i++) {
            $prices = $this->prices($po);
            foreach ($prices['lines'] as &$line) {
                $line['unit_price'] = $i.'.01';
            }
            unset($line);
            $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $prices])->assertRedirect()->assertSessionHasNoErrors();
        }
        $history = $this->get($this->detailUrl($po))->assertOk()->viewData('pricingHistory');
        $this->assertCount(20, $history);
        $this->assertSame(21, $history->first()->sequence);
        $this->assertSame(2, $history->last()->sequence);
        $this->assertTrue($history->every(fn ($revision) => $revision->comparison !== null));
        $this->assertSame(21, $this->bound(fn () => DB::table('po_pricing_revisions')->where('purchase_order_id', $po->id)->count()));
    }

    public function test_original_drafter_prices_exact_full_copy_without_mutating_source_or_issuing(): void
    {
        $po = $this->draft();
        $data = $this->prices($po);
        $before = $this->bound(fn () => [$this->mr->fresh()->toArray(), $this->mr->items()->get()->toArray(), $po->items()->get()->map(fn ($l) => $l->getRawOriginal())->all()]);
        $this->actingAs($this->writer)->get($this->detailUrl($po))->assertOk()->assertSee('Controlled MR-linked PO pricing');
        $this->postJson($this->pricingUrl($po), ['pricing' => $data])->assertRedirect()->assertSessionHasNoErrors();
        $this->bound(function () use ($po, $before): void {
            $fresh = $po->fresh();
            $this->assertSame('DRAFT', $fresh->state);
            $this->assertNull($fresh->issued_by);
            $this->assertSame('51.05', $fresh->total_value);
            $this->assertSame('USD', $fresh->currency);
            $this->assertSame($before[0], $this->mr->fresh()->toArray());
            $this->assertSame($before[1], $this->mr->items()->get()->toArray());
            foreach ($po->items()->get() as $i => $line) {
                foreach (['id', 'quantity', 'source_quantity', 'requisition_item_id', 'supersedes_id', 'item_code', 'description_en', 'description_fa', 'unit', 'subsystem_id', 'material_item_id'] as $key) {
                    $this->assertSame($before[2][$i][$key], $line->getRawOriginal($key));
                }
            }
            $this->assertSame(0, DB::table('po_execution_decisions')->count());
        });
        $audit = $this->audit($po);
        $this->assertSame($data['content_hash'], $audit->before_hash);
        $this->assertSame($this->writer->id, $audit->actor_id);
        $this->assertSame(1, $audit->sequence);
        $this->assertNull(json_decode($audit->before_snapshot, true)['header']['total_value']);
        $this->assertSame(51.05, json_decode($audit->snapshot, true)['header']['total_value']);
        $this->get($this->detailUrl($po))->assertOk()->assertSee('Verified supplier offer')->assertSee('Pricing history');
    }

    public function test_same_company_other_drafter_owner_and_financial_nominee_cannot_price(): void
    {
        $po = $this->draft();
        $data = $this->prices($po);
        $other = $this->userByEmail('sadeghi@pars.example');
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, ['version' => 1, 'selectors' => ['user:'.$this->writer->id, 'user:'.$other->id], 'suspend' => false, 'reason' => 'Both can draft, not edit each other']);
        $this->actingAs($other)->postJson($this->pricingUrl($po), ['pricing' => $data])->assertForbidden();
        $finance = $this->userByEmail('tehrani@pmc.example');
        app(PurchaseOrderExecutionAccessService::class)->configure($this->project, $this->owner, [
            'buyer_company_id' => $this->writer->company_id, 'version' => 0, 'finance_selectors' => ['user:'.$finance->id], 'issue_selectors' => ['user:'.$other->id], 'suspend' => false, 'reason' => 'Financial grant is not price edit',
        ]);
        foreach ([$this->owner, $finance] as $person) {
            $this->actingAs($person)->postJson($this->pricingUrl($po), ['pricing' => $data])->assertNotFound();
        }
        $this->actingAs($other)->get($this->detailUrl($po))->assertOk()->assertDontSee('name="pricing[content_hash]"', false);
    }

    public function test_stale_replay_and_unchanged_prices_are_refused_without_extra_audit(): void
    {
        $po = $this->draft();
        $old = $this->prices($po);
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $old])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson($this->pricingUrl($po), ['pricing' => $old])->assertUnprocessable();
        $same = $this->prices($po);
        $this->postJson($this->pricingUrl($po), ['pricing' => $same])->assertUnprocessable()->assertSee('No commercial pricing change');
        $same['lines'][0]['unit_price'] = '0.00';
        $this->postJson($this->pricingUrl($po), ['pricing' => $same])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(2, $this->audit($po)->sequence);
        $this->assertSame('30.03', $this->bound(fn () => $po->fresh()->total_value));
    }

    public function test_partial_duplicate_foreign_or_quantity_price_overrides_are_refused(): void
    {
        $po = $this->draft();
        $base = $this->prices($po);
        $this->actingAs($this->writer);
        foreach (['partial', 'duplicate', 'foreign', 'quantity', 'extra_header', 'negative', 'float', 'missing', 'overflow', 'blank_reason'] as $case) {
            $data = $base;
            if ($case === 'partial') {
                array_pop($data['lines']);
            }
            if ($case === 'duplicate') {
                $data['lines'][1]['id'] = $data['lines'][0]['id'];
            }
            if ($case === 'foreign') {
                $data['lines'][0]['id'] = (string) Str::uuid();
            }
            if ($case === 'quantity') {
                $data['lines'][0]['quantity'] = '0.001';
            }
            if ($case === 'extra_header') {
                $data['state'] = 'ISSUED';
            }
            if ($case === 'negative') {
                $data['lines'][0]['unit_price'] = '-1.00';
            }
            if ($case === 'float') {
                $data['lines'][0]['unit_price'] = 1.23;
            }
            if ($case === 'missing') {
                unset($data['lines'][0]['unit_price']);
            }
            if ($case === 'overflow') {
                $data['lines'][0]['unit_price'] = '9999999999999999.99';
            }
            if ($case === 'blank_reason') {
                $data['reason'] = ' ';
            }
            $this->postJson($this->pricingUrl($po), ['pricing' => $data])->assertUnprocessable();
        }
        $this->bound(function () use ($po): void {
            $this->assertSame(0, DB::table('po_pricing_revisions')->count());
            $this->assertNull($po->fresh()->total_value);
            $this->assertNull($po->items()->first()->unit_price);
        });
    }

    public function test_current_draft_nomination_revocation_and_archive_block_pricing(): void
    {
        $po = $this->draft();
        $data = $this->prices($po);
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, ['version' => 1, 'selectors' => [], 'suspend' => true, 'reason' => 'Revoke']);
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $data])->assertForbidden();
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, ['version' => 2, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Restore']);
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Archived before price');
        $this->postJson($this->pricingUrl($po), ['pricing' => $data])->assertUnprocessable();
        $this->get($this->detailUrl($po))->assertOk()->assertDontSee('name="pricing[content_hash]"', false);
    }

    public function test_changed_source_or_changed_full_copy_must_be_reconciled(): void
    {
        $po = $this->draft();
        $data = $this->prices($po);
        $this->bound(fn () => DB::table('material_requisition_items')->where('requisition_id', $this->mr->id)->update(['description_en' => 'Changed source']));
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $data])->assertUnprocessable()->assertSee('current approved MR');
        $this->bound(fn () => DB::table('material_requisition_items')->where('requisition_id', $this->mr->id)->update(['description_en' => 'Pipe A']));
        $this->bound(fn () => DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->update(['quantity' => '1.000', 'source_quantity' => '1.000']));
        $this->postJson($this->pricingUrl($po), ['pricing' => $this->prices($po)])->assertUnprocessable()->assertSee('current approved MR');
        $this->assertSame(0, $this->bound(fn () => DB::table('po_pricing_revisions')->count()));
    }

    public function test_late_audit_failure_rolls_back_all_prices_and_total(): void
    {
        $po = $this->draft();
        $data = $this->prices($po);
        DB::unprepared("CREATE OR REPLACE FUNCTION epcc_test_price_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'PO_PRICING_TEST_LATE_FAILURE' USING ERRCODE='23514'; END $$; CREATE TRIGGER zzzz_price_failure BEFORE INSERT ON po_pricing_revisions FOR EACH ROW EXECUTE FUNCTION epcc_test_price_failure();");
        try {
            $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $data])->assertUnprocessable()->assertSee('PO_PRICING_TEST_LATE_FAILURE');
            $this->bound(function () use ($po): void {
                $this->assertNull($po->fresh()->total_value);
                $this->assertNull($po->items()->first()->unit_price);
                $this->assertSame(0, DB::table('po_pricing_revisions')->count());
            });
        } finally {
            DB::unprepared('DROP TRIGGER zzzz_price_failure ON po_pricing_revisions; DROP FUNCTION epcc_test_price_failure();');
        }
    }

    public function test_pricing_history_is_append_only_scoped_and_escaped_in_both_languages(): void
    {
        $po = $this->draft();
        $data = $this->prices($po);
        $data['reason'] = '<script>unsafe()</script>';
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $data])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($this->detailUrl($po))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>unsafe()</script>', false);
        $this->get(str_replace('lang=en', 'lang=fa', $this->detailUrl($po)))->assertOk()->assertSee('قیمت‌گذاری کنترل‌شده');
        $this->bound(function (): void {
            $this->assertSame(0, DB::table('po_pricing_revisions')->update(['reason' => 'rewrite']));
            $this->assertSame(0, DB::table('po_pricing_revisions')->delete());
        });
        app(TenantContext::class)->runAs($this->writer->company_id, fn () => $this->assertSame(0, DB::table('po_pricing_revisions')->count()));
        app(TenantContext::class)->runAs($this->owner->company_id, fn () => $this->assertSame(0, DB::table('po_pricing_revisions')->count()), $this->project->id);
    }

    public function test_priced_mr_linked_order_can_be_approved_but_finance_reserves_no_source(): void
    {
        $po = $this->draft();
        $data = $this->prices($po);
        $other = $this->userByEmail('sadeghi@pars.example');
        $finance = $this->userByEmail('tehrani@pmc.example');
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $data])->assertRedirect()->assertSessionHasNoErrors();
        app(PurchaseOrderExecutionAccessService::class)->configure($this->project, $this->owner, ['buyer_company_id' => $this->writer->company_id, 'version' => 0, 'finance_selectors' => ['user:'.$finance->id], 'issue_selectors' => ['user:'.$other->id], 'suspend' => false, 'reason' => 'Still not allocation']);
        $hash = $this->bound(fn () => app(PurchaseOrderExecutionService::class)->hash($po));
        $this->actingAs($finance)->postJson('/projects/PH-14/procurement/execution/'.$this->writer->company_id.'/'.$po->id.'/finance?lang=en', ['execution' => ['content_hash' => $hash, 'reason' => 'No allocation yet']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(0, $this->bound(fn () => DB::table('po_mr_allocations')->count()));
        $this->assertSame('DRAFT', $this->bound(fn () => $po->fresh()->state));
    }

    public function test_current_role_discipline_and_plan_are_rechecked_for_original_drafter(): void
    {
        $po = $this->draft();
        $data = $this->prices($po);
        $membership = $this->bound(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->writer->id)->firstOrFail());
        $role = $membership->role_codes[0];
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, ['version' => 1, 'selectors' => ['role:'.$this->writer->company_id.':'.$role], 'suspend' => false, 'reason' => 'Role scoped pricing fixture']);
        $this->bound(fn () => ProjectMembership::whereKey($membership->id)->update(['role_codes' => []]));
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $data])->assertForbidden();
        $this->bound(fn () => ProjectMembership::whereKey($membership->id)->update(['role_codes' => $membership->role_codes, 'discipline_scope' => ['CIVIL']]));
        $this->postJson($this->pricingUrl($po), ['pricing' => $data])->assertForbidden();
        $this->bound(fn () => ProjectMembership::whereKey($membership->id)->update(['discipline_scope' => null]));
        $starter = Plan::where('code', 'starter')->firstOrFail()->id;
        $this->bound(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $starter]));
        $this->postJson($this->pricingUrl($po), ['pricing' => $data])->assertForbidden();
        $full = Plan::where('code', 'full')->firstOrFail()->id;
        $this->bound(fn () => Company::whereKey($this->writer->company_id)->update(['plan_id' => $full]));
        $this->postJson($this->pricingUrl($po), ['pricing' => $data])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_sql_audit_guard_rejects_wrong_actor_and_nonprice_snapshot_changes(): void
    {
        $po = $this->draft();
        $data = $this->prices($po);
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $data])->assertRedirect()->assertSessionHasNoErrors();
        $original = (array) $this->audit($po);
        $other = $this->userByEmail('sadeghi@pars.example');
        foreach (['actor', 'protected_header', 'protected_line', 'hash'] as $case) {
            $row = $original;
            $row['id'] = (string) Str::uuid();
            if ($case === 'actor') {
                $row['actor_id'] = $other->id;
            }
            if (in_array($case, ['protected_header', 'protected_line'], true)) {
                $snapshot = json_decode($row['before_snapshot'], true);
                if ($case === 'protected_header') {
                    $snapshot['header']['title_en'] = 'Forged title';
                } else {
                    $snapshot['lines'][0]['quantity'] = 999;
                }
                $row['before_snapshot'] = json_encode($snapshot);
                $row['before_hash'] = $this->bound(fn () => DB::selectOne('SELECT epcc_po_execution_digest(?::jsonb) AS hash', [$row['before_snapshot']])->hash);
            }
            if ($case === 'hash') {
                $row['content_hash'] = str_repeat('a', 64);
            }
            try {
                $this->bound(fn () => DB::transaction(fn () => DB::table('po_pricing_revisions')->insert($row)));
                $this->fail('Expected SQL refusal');
            } catch (QueryException $e) {
                $this->assertStringContainsString(match ($case) {
                    'actor' => 'PO_PRICING_NOT_ELIGIBLE','hash' => 'PO_PRICING_HASH',default => 'PO_PRICING_NONPRICE_CHANGE'
                }, $e->getMessage());
            }
        }
        $this->assertSame(1, $this->audit($po)->sequence);
    }

    public function test_superseded_rows_are_retained_and_not_repriced(): void
    {
        $po = $this->draft();
        $oldId = $this->bound(function () use ($po): string {
            $line = (array) DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->orderBy('line_no')->first();
            $oldId = $line['id'];
            $line['id'] = (string) Str::uuid();
            $line['supersedes_id'] = $oldId;
            $line['line_no'] = 3;
            DB::table('purchase_order_items')->insert($line);

            return $oldId;
        });
        $data = $this->prices($po);
        $data['lines'] = array_values(array_filter($data['lines'], fn ($l) => $l['id'] !== $oldId));
        $this->actingAs($this->writer)->postJson($this->pricingUrl($po), ['pricing' => $data])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNull($this->bound(fn () => DB::table('purchase_order_items')->where('id', $oldId)->value('unit_price')));
        $this->assertCount(3, json_decode($this->audit($po)->snapshot, true)['lines']);
        $this->assertSame('51.05', $this->bound(fn () => $po->fresh()->total_value));
        $comparison = $this->get($this->detailUrl($po))->assertOk()->viewData('pricingHistory')->first()->comparison;
        $this->assertCount(3, $comparison['lines']);
        $this->assertNull(collect($comparison['lines'])->keyBy('id')[$oldId]['after']);
    }
}
