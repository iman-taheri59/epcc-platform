<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AcceptedMtoRow;
use App\Models\Company;
use App\Models\Facility;
use App\Models\MaterialItem;
use App\Models\MtoDocument;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Subsystem;
use App\Services\MtoAcceptanceService;
use App\Services\MtoStagingService;
use App\Services\OrderGapService;
use App\Services\ProcurementService;
use App\Support\ProcurementState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Slice 6 (ADR-015): PO attribution, amendment lineage, scope allocation and
 * the order gap. Domain rules under test: draft/cancelled POs count nothing,
 * an amendment replaces the previous quantity (lineage head only), there is
 * no tolerance and no proration, over-ordering is OVER_PLAN, and unmatched
 * lines stay unmatched.
 */
final class OrderGapTest extends TestCase
{
    private TenantContext $tenant;

    private OrderGapService $gaps;

    private ProcurementService $procurement;

    private MtoStagingService $staging;

    private MtoAcceptanceService $acceptance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->seed(MaterialCatalogueSeeder::class);

        $this->tenant = app(TenantContext::class);
        $this->gaps = app(OrderGapService::class);
        $this->procurement = app(ProcurementService::class);
        $this->staging = app(MtoStagingService::class);
        $this->acceptance = app(MtoAcceptanceService::class);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function project(): Project
    {
        return $this->tenant->forIdentityLookup(
            fn () => Project::where('code', 'PH-14')->firstOrFail()
        );
    }

    private function company(string $code): Company
    {
        return $this->tenant->forIdentityLookup(
            fn () => Company::where('code', $code)->firstOrFail()
        );
    }

    private function vendor(): Company
    {
        $project = $this->project();

        return $this->tenant->forIdentityLookup(
            fn () => Company::where('tenant_type', 'VEN')
                ->whereIn('id', DB::table('project_company')->where('project_id', $project->id)->pluck('company_id'))
                ->firstOrFail()
        );
    }

    /** A company on this installation but NOT a participant of PH-14. */
    private function outsider(): Company
    {
        $project = $this->project();

        return $this->tenant->forIdentityLookup(
            fn () => Company::whereNotIn(
                'id',
                DB::table('project_company')->where('project_id', $project->id)->pluck('company_id')
            )->firstOrFail()
        );
    }

    /** Accept one MTO revision carrying the given rows; returns the rows. */
    private function acceptRows(array $rows): Collection
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $document = $this->tenant->runAs($pars->id, fn () => $this->staging->createDocument(
            $project,
            $rezaei,
            'PH-14-PIP-MTO-'.fake()->unique()->numerify('####'),
            'Piping bulk MTO',
            'MTO حجمی پایپینگ',
            'PIPING',
            MtoDocument::SOURCE_MANUAL,
            lang: 'en',
        ), $project->id);

        $revision = $this->tenant->runAs($pars->id, fn () => $this->staging->createRevision(
            $document,
            $rezaei,
            'A',
            $rows,
            revisionDate: '2026-10-06',
            lang: 'en',
        ), $project->id);

        $this->tenant->runAs($pars->id, fn () => $this->acceptance->accept($revision, 'en'), $project->id);

        return $this->tenant->runAs(
            $pars->id,
            fn () => AcceptedMtoRow::where('project_id', $project->id)->get(),
            $project->id,
        );
    }

    /** @return array<string,mixed> */
    private function mtoRow(string $commodity, float $qty, string $unit, string $itemNo = '10'): array
    {
        return [
            'mto_item_no' => $itemNo,
            'commodity_code' => $commodity,
            'raw_description' => 'Bulk '.$commodity,
            'raw_quantity' => $qty,
            'raw_unit' => $unit,
        ];
    }

    /** @param list<array<string,mixed>> $lines */
    private function makePo(array $lines, string $title = 'Slice 6 PO'): PurchaseOrder
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $vendor = $this->vendor();

        return $this->tenant->runAs(
            $pars->id,
            fn () => $this->procurement->createPurchaseOrder(
                $project, $rezaei, $vendor, $title, 'سفارش اسلایس ۶', $lines, lang: 'en'
            ),
            $project->id,
        );
    }

    private function issue(PurchaseOrder $po): PurchaseOrder
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        return $this->tenant->runAs(
            $pars->id,
            fn () => $this->procurement->issuePurchaseOrder($po, $rezaei, 'en'),
            $project->id,
        );
    }

    private function poLine(string $itemCode, float $qty, string $unit, ?string $description = null): array
    {
        return [
            'item_code' => $itemCode,
            'description_en' => $description ?? $itemCode.' line',
            'description_fa' => $description ?? $itemCode,
            'unit' => $unit,
            'quantity' => $qty,
        ];
    }

    private function material(string $commodity): MaterialItem
    {
        $project = $this->project();

        return $this->tenant->forIdentityLookup(
            fn () => MaterialItem::where('commodity_code', $commodity)->firstOrFail()
        );
    }

    private function subsystem(): Subsystem
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        $existing = $this->tenant->runAs(
            $pars->id,
            fn () => Subsystem::where('project_id', $project->id)->first(),
            $project->id,
        );

        if ($existing !== null) {
            return $existing;
        }

        $facility = $this->tenant->forIdentityLookup(
            fn () => Facility::where('project_id', $project->id)->firstOrFail()
        );

        return $this->tenant->runAs($pars->id, fn () => Subsystem::create([
            'project_id' => $project->id,
            'company_id' => $pars->id,
            'facility_id' => $facility->id,
            'code' => 'SS-OG-01',
            'name_en' => 'Order gap subsystem',
            'name_fa' => 'زیرسیستم شکاف سفارش',
            'discipline' => 'PIPING',
        ]), $project->id);
    }

    private function board(): array
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        return $this->tenant->runAs($pars->id, fn () => $this->gaps->board($project), $project->id);
    }

    private function attribute(string $itemCode, string $commodity): PurchaseOrderItem
    {
        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        return $this->tenant->runAs($pars->id, function () use ($itemCode, $commodity, $project) {
            $item = PurchaseOrderItem::where('item_code', $itemCode)->firstOrFail();

            return $this->gaps->attribute($project, $item, $this->material($commodity)->id, 'en');
        }, $project->id);
    }

    /** @return array<string,mixed>|null */
    private function rowFor(string $commodity): ?array
    {
        foreach ($this->board()['rows'] as $row) {
            if ($row['commodity_code'] === $commodity) {
                return $row;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Attribution
    // ------------------------------------------------------------------

    public function test_amendment_uses_fresh_fields_instead_of_stale_model_values(): void
    {
        $po = $this->makePo([$this->poLine('STALE', 10, 'M')]);
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $this->tenant->runAs($pars->id, function () use ($po): void {
            $stale = $po->items()->firstOrFail();
            $po->items()->whereKey($stale->id)->update(['description_en' => 'Fresh authoritative description', 'unit_price' => 17]);
            $next = $this->gaps->amend($stale, 9, 'en');
            $this->assertSame('Fresh authoritative description', $next->description_en);
            $this->assertEquals(17, $next->unit_price);
            $this->assertSame($stale->id, $next->supersedes_id);
        }, $project->id);
    }

    public function test_amendment_rechecks_parent_state_even_with_cached_draft_relation(): void
    {
        $po = $this->makePo([$this->poLine('CANCELLED', 10, 'M')]);
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $this->tenant->runAs($pars->id, function () use ($po): void {
            $stale = $po->items()->firstOrFail();
            $stale->setRelation('purchaseOrder', $po);
            $po->newQuery()->whereKey($po->id)->update(['state' => 'CANCELLED']);
            try {
                $this->gaps->amend($stale, 9, 'en');
                $this->fail('A cached draft relation cannot override the locked parent');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('purchase_order_id', $e->errors());
            }
            $this->assertSame(1, $po->items()->count());
        }, $project->id);
    }

    public function test_attribution_checks_fresh_unit_not_stale_unit(): void
    {
        $this->acceptRows([$this->mtoRow('PIP-CS-6-SCH40', 120, 'M')]);
        $po = $this->makePo([$this->poLine('STALE', 10, 'M')]);
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $material = $this->tenant->runAs($pars->id, fn () => $this->material('PIP-CS-6-SCH40'), $project->id);
        $this->tenant->runAs($pars->id, function () use ($po, $project, $material): void {
            $stale = $po->items()->firstOrFail();
            $po->items()->whereKey($stale->id)->update(['unit' => 'EA']);
            try {
                $this->gaps->attribute($project, $stale, $material->id, 'en');
                $this->fail('Fresh incompatible unit must be rejected');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('unit', $e->errors());
            }
            $this->assertNull($stale->fresh()->material_item_id);
        }, $project->id);
    }

    public function test_all_mutators_lock_project_before_order_before_item_and_reject_stale_heads(): void
    {
        $this->acceptRows([$this->mtoRow('PIP-CS-6-SCH40', 120, 'M')]);
        $po = $this->makePo([$this->poLine('LOCKS', 10, 'M')]);
        $po = $this->issue($po);
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $material = $this->tenant->runAs($pars->id, fn () => $this->material('PIP-CS-6-SCH40'), $project->id);
        $user = $this->userByEmail('rezaei@pars.example');
        $scope = $this->subsystem();
        $this->tenant->runAs($pars->id, function () use ($po, $project, $material, $user, $scope): void {
            $stale = $po->items()->firstOrFail();
            foreach (['attribute', 'allocate', 'amend'] as $method) {
                DB::flushQueryLog();
                DB::enableQueryLog();
                try {
                    if ($method === 'attribute') {
                        $this->gaps->attribute($project, $stale, $material->id, 'en');
                    } elseif ($method === 'allocate') {
                        // The caller still holds the unattributed model from before attribution.
                        $this->gaps->allocate($project, $user, $stale, $scope->id, 2, 'en');
                    } else {
                        $this->gaps->amend($stale, 9, 'en');
                    }
                    $locks = array_values(array_filter(array_column(DB::getQueryLog(), 'query'),
                        fn ($sql) => str_contains($sql, 'pg_advisory_xact_lock') || str_contains($sql, 'for update')));
                    $this->assertStringContainsString('pg_advisory_xact_lock', $locks[0]);
                    $this->assertStringContainsString('"projects"', $locks[1]);
                    $this->assertStringContainsString('"purchase_orders"', $locks[2]);
                    $this->assertStringContainsString('"purchase_order_items"', $locks[3]);
                } finally {
                    DB::disableQueryLog();
                    DB::flushQueryLog();
                }
            }
            foreach (['attribute', 'allocate'] as $method) {
                try {
                    if ($method === 'attribute') {
                        $this->gaps->attribute($project, $stale, $material->id, 'en');
                    } else {
                        $this->gaps->allocate($project, $user, $stale, $scope->id, 1, 'en');
                    }
                    $this->fail('A stale superseded head must be rejected');
                } catch (ValidationException $e) {
                    $this->assertArrayHasKey('supersedes_id', $e->errors());
                }
            }
        }, $project->id);
    }

    public function test_legacy_http_attribution_and_amendment_keep_existing_lineage_behavior(): void
    {
        $po = $this->makePo([$this->poLine('LEGACY-HTTP', 10, 'M')]);
        $project = $this->project();
        $user = $this->userByEmail('rezaei@pars.example');
        [$line, $material] = $this->tenant->runAs($user->company_id, fn () => [$po->items()->firstOrFail(), $this->material('PIP-CS-6-SCH40')], $project->id);
        $this->actingAs($user)->postJson('/projects/PH-14/procurement/order-gap/attribute?lang=en', ['item_id' => $line->id, 'material_item_id' => $material->id])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson('/projects/PH-14/procurement/order-gap/amend?lang=en', ['item_id' => $line->id, 'quantity' => '9.000'])->assertRedirect()->assertSessionHasNoErrors();
        $this->tenant->runAs($user->company_id, function () use ($po, $line, $material): void {
            $this->assertSame(2, $po->items()->count());
            $this->assertSame('10.000', $line->fresh()->quantity);
            $next = $po->items()->where('supersedes_id', $line->id)->firstOrFail();
            $this->assertSame('9.000', $next->quantity);
            $this->assertSame($material->id, $next->material_item_id);
        }, $project->id);
    }

    public function test_attribution_is_exact_and_rejects_unit_mismatch(): void
    {
        $this->acceptRows([$this->mtoRow('PIP-CS-6-SCH40', 120, 'M')]);
        $po = $this->makePo([
            $this->poLine('PIP-CS-6-SCH40', 90, 'M'),
            $this->poLine('VLV-GT-6-150', 2, 'EA'),
        ]);

        $item = $this->attribute('PIP-CS-6-SCH40', 'PIP-CS-6-SCH40');
        $this->assertNotNull($item->material_item_id);

        // The valve line carries EA; attributing it to the pipe (base unit M)
        // is a validation failure, not a conversion.
        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        $this->expectException(ValidationException::class);
        $this->tenant->runAs($pars->id, function () use ($po, $project) {
            $valve = PurchaseOrderItem::where('purchase_order_id', $po->id)
                ->where('item_code', 'VLV-GT-6-150')->firstOrFail();

            $this->gaps->attribute($project, $valve, $this->material('PIP-CS-6-SCH40')->id, 'en');
        }, $project->id);
    }

    // ------------------------------------------------------------------
    // States
    // ------------------------------------------------------------------

    public function test_draft_po_counts_nothing_until_issued(): void
    {
        $this->acceptRows([$this->mtoRow('PIP-CS-6-SCH40', 120, 'M')]);
        $po = $this->makePo([$this->poLine('PIP-CS-6-SCH40', 90, 'M')]);
        $this->attribute('PIP-CS-6-SCH40', 'PIP-CS-6-SCH40');

        $row = $this->rowFor('PIP-CS-6-SCH40');
        $this->assertSame('120.000', $row['gross']);
        $this->assertSame('0.000', $row['ordered']);
        $this->assertSame('SHORTAGE', $row['state']);
        $this->assertSame('120.000', $row['gap']);

        $this->issue($po);

        $row = $this->rowFor('PIP-CS-6-SCH40');
        $this->assertSame('90.000', $row['ordered']);
        $this->assertSame('30.000', $row['gap']);
        $this->assertSame('SHORTAGE', $row['state']);
    }

    public function test_cancelled_po_is_excluded_from_the_gap(): void
    {
        $this->acceptRows([$this->mtoRow('PIP-CS-6-SCH40', 120, 'M')]);
        $po = $this->makePo([$this->poLine('PIP-CS-6-SCH40', 120, 'M')]);
        $this->attribute('PIP-CS-6-SCH40', 'PIP-CS-6-SCH40');
        $po = $this->issue($po);

        $this->assertSame('COVERED', $this->rowFor('PIP-CS-6-SCH40')['state']);

        $pars = $this->company('EPC-PARS');
        $this->tenant->runAs(
            $pars->id,
            fn () => PurchaseOrder::whereKey($po->id)->update(['state' => ProcurementState::PO_CANCELLED]),
            $po->project_id,
        );

        $row = $this->rowFor('PIP-CS-6-SCH40');
        $this->assertSame('0.000', $row['ordered']);
        $this->assertSame('SHORTAGE', $row['state']);
        $this->assertSame('120.000', $row['gap']);
    }

    public function test_covered_and_over_plan_are_distinct_and_zero_tolerance(): void
    {
        $this->acceptRows([
            $this->mtoRow('PIP-CS-6-SCH40', 120, 'M'),
            $this->mtoRow('VLV-GT-6-150', 4, 'EA', '20'),
        ]);

        $po = $this->makePo([
            $this->poLine('PIP-CS-6-SCH40', 120, 'M'),
            $this->poLine('VLV-GT-6-150', 5, 'EA'),
        ]);
        $this->attribute('PIP-CS-6-SCH40', 'PIP-CS-6-SCH40');
        $this->attribute('VLV-GT-6-150', 'VLV-GT-6-150');
        $this->issue($po);

        $pipe = $this->rowFor('PIP-CS-6-SCH40');
        $this->assertSame('COVERED', $pipe['state']);
        $this->assertSame('0.000', $pipe['gap']);

        // Ordered above plan is OVER_PLAN with the excess reported — never
        // clamped to zero, never a negative shortage.
        $valve = $this->rowFor('VLV-GT-6-150');
        $this->assertSame('OVER_PLAN', $valve['state']);
        $this->assertSame('-1.000', $valve['gap']);
    }

    // ------------------------------------------------------------------
    // Amendment lineage
    // ------------------------------------------------------------------

    public function test_amendment_replaces_the_previous_quantity_and_keeps_history(): void
    {
        $this->acceptRows([$this->mtoRow('PIP-CS-6-SCH40', 120, 'M')]);
        $po = $this->makePo([$this->poLine('PIP-CS-6-SCH40', 90, 'M')]);
        $this->attribute('PIP-CS-6-SCH40', 'PIP-CS-6-SCH40');
        $this->issue($po);

        $project = $this->project();
        $pars = $this->company('EPC-PARS');

        $old = $this->tenant->runAs($pars->id, function () use ($po) {
            return PurchaseOrderItem::where('purchase_order_id', $po->id)
                ->where('item_code', 'PIP-CS-6-SCH40')->firstOrFail();
        }, $project->id);

        $new = $this->tenant->runAs($pars->id, function () use ($old) {
            return $this->gaps->amend($old, 110.0, 'en');
        }, $project->id);

        $this->assertSame($old->id, $new->supersedes_id);
        $this->assertNotSame($old->id, $new->id);

        $row = $this->rowFor('PIP-CS-6-SCH40');
        $this->assertSame('110.000', $row['ordered']);
        $this->assertSame('10.000', $row['gap']);

        // History is preserved: the superseded row is still readable with its
        // original quantity, and it cannot be amended again.
        $freshOld = $this->tenant->runAs($pars->id, function () use ($old) {
            return PurchaseOrderItem::with('supersededBy')->whereKey($old->id)->firstOrFail();
        }, $project->id);
        $this->assertSame('90.000', $freshOld->quantity);
        $this->assertTrue($this->tenant->runAs($pars->id,
            fn () => $freshOld->supersededBy()->exists(), $project->id));

        $this->expectException(ValidationException::class);
        $this->tenant->runAs($pars->id, function () use ($old) {
            $this->gaps->amend($old->refresh(), 115.0, 'en');
        }, $project->id);
    }

    // ------------------------------------------------------------------
    // Scope allocation
    // ------------------------------------------------------------------

    public function test_allocation_is_recorded_exactly_and_refuses_over_allocation(): void
    {
        $this->acceptRows([$this->mtoRow('PIP-CS-6-SCH40', 120, 'M')]);
        $po = $this->makePo([$this->poLine('PIP-CS-6-SCH40', 100, 'M')]);
        $this->attribute('PIP-CS-6-SCH40', 'PIP-CS-6-SCH40');
        $po = $this->issue($po);

        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $subsystem = $this->subsystem();

        $item = $this->tenant->runAs($pars->id, function () use ($po) {
            return PurchaseOrderItem::where('purchase_order_id', $po->id)
                ->where('item_code', 'PIP-CS-6-SCH40')->firstOrFail();
        }, $project->id);

        $this->tenant->runAs($pars->id, function () use ($project, $rezaei, $item, $subsystem) {
            $this->gaps->allocate($project, $rezaei, $item, $subsystem->id, 60.0, 'en');
        }, $project->id);

        $row = $this->rowFor('PIP-CS-6-SCH40');
        $this->assertSame('60.000', $row['allocated']);
        $this->assertSame('40.000', $row['unallocated']);

        // 60 + 50 would exceed the 100 ordered: refused, no proration.
        $this->expectException(ValidationException::class);
        $this->tenant->runAs($pars->id, function () use ($project, $rezaei, $item, $subsystem) {
            $this->gaps->allocate($project, $rezaei, $item, $subsystem->id, 50.0, 'en');
        }, $project->id);
    }

    public function test_allocation_requires_attribution_and_an_effective_order(): void
    {
        $this->acceptRows([$this->mtoRow('PIP-CS-6-SCH40', 120, 'M')]);
        $po = $this->makePo([
            $this->poLine('PIP-CS-6-SCH40', 100, 'M'),
            $this->poLine('OTHER-CODE-01', 5, 'EA'),
        ]);
        $project = $this->project();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $subsystem = $this->subsystem();

        // Draft PO: allocation refused even for a correct item.
        $this->tenant->runAs($pars->id, function () use ($po, $project, $rezaei, $subsystem) {
            $item = PurchaseOrderItem::where('purchase_order_id', $po->id)
                ->where('item_code', 'PIP-CS-6-SCH40')->firstOrFail();

            try {
                $this->gaps->allocate($project, $rezaei, $item, $subsystem->id, 10.0, 'en');
                $this->fail('Allocation on a draft PO must be refused.');
            } catch (ValidationException $e) {
                // expected
            }

            // Unattributed line: attribution is demanded first.
            $other = PurchaseOrderItem::where('purchase_order_id', $po->id)
                ->where('item_code', 'OTHER-CODE-01')->firstOrFail();

            $this->expectException(ValidationException::class);
            $this->gaps->allocate($project, $rezaei, $other, $subsystem->id, 1.0, 'en');
        }, $project->id);
    }

    // ------------------------------------------------------------------
    // Unmatched bucket
    // ------------------------------------------------------------------

    public function test_unmatched_lines_stay_in_their_bucket_and_are_never_guessed(): void
    {
        $this->acceptRows([$this->mtoRow('PIP-CS-6-SCH40', 120, 'M')]);
        $po = $this->makePo([
            $this->poLine('PIP-CS-6-SCH40', 120, 'M'),
            $this->poLine('MISC-GASKET-99', 30, 'EA'),
        ]);
        $this->attribute('PIP-CS-6-SCH40', 'PIP-CS-6-SCH40');
        $this->issue($po);

        $board = $this->board();

        $pipe = null;
        $misc = null;
        foreach ($board['rows'] as $row) {
            if ($row['commodity_code'] === 'PIP-CS-6-SCH40') {
                $pipe = $row;
            }
            if ($row['commodity_code'] === 'MISC-GASKET-99') {
                $misc = $row;
            }
        }

        $this->assertSame('COVERED', $pipe['state']);
        $this->assertNotNull($misc);
        $this->assertSame('UNMATCHED', $misc['state']);
        $this->assertNull($misc['gross']);
        $this->assertNull($misc['gap']);
        $this->assertSame('30.000', $misc['ordered']);
        $this->assertSame(1, $board['totals']['unmatched']);
    }

    // ------------------------------------------------------------------
    // Tenancy mirror
    // ------------------------------------------------------------------

    public function test_a_non_participant_company_sees_nothing_through_the_board(): void
    {
        $this->acceptRows([$this->mtoRow('PIP-CS-6-SCH40', 120, 'M')]);
        $po = $this->makePo([$this->poLine('PIP-CS-6-SCH40', 120, 'M')]);
        $this->attribute('PIP-CS-6-SCH40', 'PIP-CS-6-SCH40');
        $this->issue($po);

        $project = $this->project();
        $outsider = $this->outsider();

        $board = $this->tenant->runAs($outsider->id, fn () => $this->gaps->board($project), $project->id);

        $this->assertSame([], $board['rows']);
        $this->assertSame(0, $board['totals']['materials']);
    }

    public function test_the_board_renders_for_a_participant(): void
    {
        $this->acceptRows([$this->mtoRow('PIP-CS-6-SCH40', 120, 'M')]);
        $po = $this->makePo([$this->poLine('PIP-CS-6-SCH40', 90, 'M')]);
        $this->attribute('PIP-CS-6-SCH40', 'PIP-CS-6-SCH40');
        $this->issue($po);

        $rezaei = $this->userByEmail('rezaei@pars.example');

        $this->actingAs($rezaei)
            ->get('/projects/PH-14/procurement/order-gap?lang=en')
            ->assertOk()
            ->assertSee('PIP-CS-6-SCH40')
            ->assertSee('Shortage');
    }
}
