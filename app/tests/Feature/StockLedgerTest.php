<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentRevision;
use App\Models\MaterialLot;
use App\Models\MaterialMovement;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\PipingCustodyService;
use App\Services\PipingGrnService;
use App\Services\PipingQaDecisionService;
use App\Services\PipingReceiptAccessService;
use App\Services\PipingReceivingReviewService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Services\PurchaseOrderExecutionAccessService;
use App\Services\PurchaseOrderExecutionService;
use App\Services\StockLedgerService;
use App\Services\WarehouseService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-047 slice 1 — the unified stock ledger: append-only journal, ordinal
 * clock, dual person authority, insufficient-balance refusal, holder-company
 * scoping, the derived-only RECEIPT path from a real controlled GRN credit,
 * and the fail-closed legacy cutover flag on WarehouseService.
 */
final class StockLedgerTest extends TestCase
{
    private User $owner;

    private User $buyer;

    private User $colleague;

    private User $qc;

    private Project $project;

    private string $receipt = '';

    private string $review = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->buyer = $this->userByEmail('rezaei@pars.example');
        $this->colleague = $this->userByEmail('sadeghi@pars.example');
        $this->qc = $this->userByEmail('tehrani@pmc.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
    }

    // ------------------------------------------------------------------
    // ledger helpers
    // ------------------------------------------------------------------

    private function ledger(): StockLedgerService
    {
        return app(StockLedgerService::class);
    }

    private function ledgerScoped(callable $fn, ?User $actor = null): mixed
    {
        return $this->ledger()->scoped($this->project, $actor ?? $this->buyer, $fn);
    }

    private function postEntry(array $overrides = [], ?User $actor = null): string
    {
        return $this->ledger()->post($this->project, $actor ?? $this->buyer, array_replace([
            'kind' => 'OPENING_COUNT', 'item_code' => 'PIPE-10', 'heat_ref' => 'HEAT-1', 'unit' => 'M',
            'quantity' => '100.000', 'approver_user_id' => $this->colleague->id,
            'reason' => 'Opening physical count at cutover', 'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    private function balance(string $item = 'PIPE-10', ?string $heat = 'HEAT-1', string $unit = 'M', ?User $actor = null): string
    {
        return $this->ledger()->balance($this->project, $actor ?? $this->buyer, $item, $heat, $unit);
    }

    private function journal(?User $actor = null)
    {
        return $this->ledger()->journal($this->project, $actor ?? $this->buyer);
    }

    private function rawEntry(array $overrides = []): void
    {
        $this->ledgerScoped(fn () => DB::table('stock_ledger_entries')->insert(array_replace([
            'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'company_id' => $this->buyer->company_id,
            'kind' => 'ISSUE', 'item_code' => 'PIPE-10', 'heat_ref' => 'HEAT-1', 'unit' => 'M', 'quantity' => '1.000',
            'request_id' => (string) Str::uuid(), 'recorded_by' => $this->buyer->id, 'approved_by' => $this->colleague->id,
            'reason' => 'Raw probe entry', 'recorded_at' => now(),
        ], $overrides)));
    }

    // ------------------------------------------------------------------
    // balances, clock and refusals
    // ------------------------------------------------------------------

    public function test_opening_count_then_issue_keeps_exact_balance_and_one_clock(): void
    {
        $opening = $this->postEntry();
        $this->postEntry(['kind' => 'ISSUE', 'quantity' => '30.500', 'reason' => 'Issued to site area A']);

        $this->assertSame('69.500', $this->balance());
        $journal = $this->journal();
        $this->assertCount(2, $journal);
        $this->assertSame([1, 2], $journal->pluck('ordinal')->all());
        $this->assertSame($opening, $journal->first()->id);
        $this->assertSame('OPENING_COUNT', $journal->first()->kind);
        $this->assertSame($this->buyer->id, $journal->first()->recorded_by);
        $this->assertSame($this->colleague->id, $journal->first()->approved_by);
        $this->assertSame('ISSUE', $journal->last()->kind);
        $this->assertSame(2, (int) $this->ledgerScoped(fn () => DB::table('stock_ledger_clocks')->where('project_id', $this->project->id)->value('last_ordinal')));
    }

    public function test_issue_or_adjustment_beyond_balance_is_refused_and_records_nothing(): void
    {
        $this->postEntry(['quantity' => '10.000']);

        try {
            $this->postEntry(['kind' => 'ISSUE', 'quantity' => '10.001', 'reason' => 'Overdraw attempt']);
            $this->fail('Expected insufficient-balance refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
            $this->assertStringContainsString('insufficient', $e->errors()['quantity'][0]);
        }
        try {
            $this->postEntry(['kind' => 'ADJUSTMENT_OUT', 'quantity' => '11.000', 'reason' => 'Overdraw attempt']);
            $this->fail('Expected insufficient-balance refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('quantity', $e->errors());
        }

        $this->assertCount(1, $this->journal());
        $this->assertSame('10.000', $this->balance());
        $this->assertSame(1, (int) $this->ledgerScoped(fn () => DB::table('stock_ledger_clocks')->where('project_id', $this->project->id)->value('last_ordinal')));
    }

    public function test_self_approval_blank_reason_bad_quantity_and_receipt_kind_are_refused(): void
    {
        foreach ([
            ['approver_user_id' => $this->buyer->id],
            ['reason' => '   '],
            ['quantity' => '0'],
            ['quantity' => '1.2345'],
            ['quantity' => '-5'],
            ['kind' => 'RECEIPT'],
            ['request_id' => 'not-a-uuid'],
        ] as $overrides) {
            try {
                $this->postEntry($overrides);
                $this->fail('Expected refusal for '.json_encode($overrides));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertCount(0, $this->journal());
    }

    public function test_approver_must_be_an_active_colleague_of_the_holder_company(): void
    {
        // A different company's user, even an owner-company engineer, cannot approve.
        try {
            $this->postEntry(['approver_user_id' => $this->owner->id]);
            $this->fail('Expected cross-company approver refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approver_user_id', $e->errors());
        }

        // A deactivated colleague fails the freshness check at post time.
        $former = $this->userByEmail('former@pars.example');
        try {
            $this->postEntry(['approver_user_id' => $former->id]);
            $this->fail('Expected inactive approver refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('approver_user_id', $e->errors());
        }

        // The database re-checks the same fact: a raw insert naming a foreign
        // approver is refused by the guard trigger, not merely by the service.
        try {
            $this->rawEntry(['approved_by' => $this->owner->id]);
            $this->fail('Expected SQL approver refusal');
        } catch (QueryException $e) {
            $this->assertSame('42501', (string) $e->getCode());
            $this->assertStringContainsString('STOCK_LEDGER_APPROVER_NOT_COLLEAGUE', $e->getMessage());
        }
        $this->assertCount(0, $this->journal());
    }

    public function test_entries_are_immutable_and_receipt_kind_is_derived_only(): void
    {
        $this->postEntry();

        // No UPDATE/DELETE policy exists, so under RLS+FORCE a mutation attempt
        // matches zero rows and changes nothing; the append-only trigger still
        // refuses any path that could reach the row (definer/superuser).
        $updated = $this->ledgerScoped(fn () => DB::table('stock_ledger_entries')->where('ordinal', 1)->update(['quantity' => '999.000']));
        $this->assertSame(0, $updated);
        $deleted = $this->ledgerScoped(fn () => DB::table('stock_ledger_entries')->where('ordinal', 1)->delete());
        $this->assertSame(0, $deleted);
        $this->assertSame('100.000', $this->balance());
        $this->assertCount(1, $this->journal());

        // A hand-written RECEIPT at direct depth is refused before any FK check:
        // receipts are born only inside the GRN credit trigger chain.
        try {
            $this->rawEntry(['kind' => 'RECEIPT', 'grn_id' => (string) Str::uuid(), 'request_id' => null,
                'approved_by' => null, 'reason' => null, 'source_snapshot' => json_encode(['fake' => true])]);
            $this->fail('Expected derived-only refusal');
        } catch (QueryException $e) {
            $this->assertSame('55000', (string) $e->getCode());
            $this->assertStringContainsString('STOCK_LEDGER_RECEIPT_DERIVED_ONLY', $e->getMessage());
        }
        $this->assertSame('100.000', $this->balance());
    }

    public function test_raw_insert_with_foreign_actor_or_company_is_refused(): void
    {
        try {
            $this->rawEntry(['recorded_by' => $this->colleague->id]);
            $this->fail('Expected foreign-actor refusal');
        } catch (QueryException $e) {
            $this->assertSame('42501', (string) $e->getCode());
        }
        try {
            $this->rawEntry(['company_id' => $this->owner->company_id]);
            $this->fail('Expected foreign-company refusal');
        } catch (QueryException $e) {
            $this->assertSame('42501', (string) $e->getCode());
        }
        $this->assertCount(0, $this->journal());
    }

    public function test_journal_and_balance_are_holder_company_scoped(): void
    {
        $this->postEntry();

        $this->assertCount(0, $this->journal($this->owner));
        $this->assertSame('0.000', $this->balance('PIPE-10', 'HEAT-1', 'M', $this->owner));
        // An unknown heat is its own honest bucket, never merged with a known one.
        $this->assertSame('0.000', $this->balance('PIPE-10', 'HEAT-OTHER'));
        $this->assertSame('0.000', $this->balance('PIPE-10', null));
    }

    public function test_duplicate_request_id_is_refused(): void
    {
        $requestId = (string) Str::uuid();
        $this->postEntry(['request_id' => $requestId]);

        try {
            $this->postEntry(['kind' => 'ADJUSTMENT_IN', 'quantity' => '5.000', 'request_id' => $requestId, 'reason' => 'Retry of the same posting']);
            $this->fail('Expected duplicate refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('duplicate', strtolower(implode(' ', array_map('implode', $e->errors()))));
        }
        $this->assertCount(1, $this->journal());
    }

    // ------------------------------------------------------------------
    // the controlled GRN credit journals itself
    // ------------------------------------------------------------------

    public function test_grn_credit_journals_exactly_one_frozen_receipt_entry(): void
    {
        $this->configureReceiptAccess();
        $this->receipt = app(PipingCustodyService::class)->create($this->project, $this->buyer, $this->arrival());
        $this->review = $this->qcReview();
        $qa = $this->acceptedQa();
        $grnId = app(PipingGrnService::class)->post($this->project, $this->buyer, $this->receipt, $this->grnInput($qa));

        $entries = $this->ledgerScoped(fn () => DB::table('stock_ledger_entries')->where('project_id', $this->project->id)->orderBy('ordinal')->get());
        $this->assertCount(1, $entries);
        $entry = $entries->first();
        $this->assertSame('RECEIPT', $entry->kind);
        $this->assertSame(1, (int) $entry->ordinal);
        $this->assertSame($grnId, $entry->grn_id);
        $this->assertSame($this->buyer->company_id, $entry->company_id);
        $this->assertSame($this->buyer->id, $entry->recorded_by);
        $this->assertSame('QA-PIPE', $entry->item_code);
        $this->assertSame('HEAT-QA', $entry->heat_ref);
        $this->assertSame('M', $entry->unit);
        $this->assertSame('2.000', (string) $entry->quantity);
        $this->assertNull($entry->approved_by);
        $this->assertNull($entry->reason);
        $this->assertNull($entry->request_id);
        $snapshot = json_decode((string) $entry->source_snapshot, true);
        $this->assertSame('QA-PIPE', $snapshot['item_code']);
        $this->assertNotEmpty($snapshot['grn_number']);
        $this->assertSame('2.000', $this->balance('QA-PIPE', 'HEAT-QA', 'M'));
    }

    // ------------------------------------------------------------------
    // ADR-047 cutover flag on the legacy core
    // ------------------------------------------------------------------

    public function test_cutover_flag_closes_every_legacy_stock_write_fail_closed(): void
    {
        config(['epcc.warehouse.legacy_writes_refused' => true]);
        $warehouse = app(WarehouseService::class);
        $lot = new MaterialLot;
        $calls = [
            fn () => $warehouse->receive($this->project, $this->buyer, new PurchaseOrder, 'X', 'X', 'X', 'M', 1.0, lang: 'en'),
            fn () => $warehouse->issue($this->project, $this->buyer, $lot, 1.0, lang: 'en'),
            fn () => $warehouse->deliverToSite($this->project, $this->buyer, $lot, new Subsystem, 1.0, lang: 'en'),
            fn () => $warehouse->returnToStore($this->project, $this->buyer, $lot, 1.0, lang: 'en'),
            fn () => $warehouse->reverse(new MaterialMovement, $this->buyer, 'probe', 'en'),
        ];
        foreach ($calls as $i => $call) {
            try {
                $call();
                $this->fail("Expected cutover refusal on legacy writer #{$i}");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('warehouse', $e->errors());
                $this->assertStringContainsString('ADR-047', $e->errors()['warehouse'][0]);
            }
        }

        // With the flag off, the legacy path is reached again — proven by the
        // ordinary quantity validation firing instead of the cutover message.
        config(['epcc.warehouse.legacy_writes_refused' => false]);
        try {
            $warehouse->deliverToSite($this->project, $this->buyer, $lot, new Subsystem, 0.0, lang: 'en');
            $this->fail('Expected ordinary legacy validation');
        } catch (ValidationException $e) {
            $this->assertArrayNotHasKey('warehouse', $e->errors());
        }
    }

    // ------------------------------------------------------------------
    // controlled receipt chain (same fixture discipline as PipingGrnTest)
    // ------------------------------------------------------------------

    private function configureReceiptAccess(): void
    {
        app(PipingReceiptAccessService::class)->configure($this->project, $this->owner, [
            'version' => 0, 'suspend' => false, 'reason' => 'Synthetic ledger QA matrix',
            'custodian_company_id' => $this->buyer->company_id,
            'actions' => ['custody_mrr' => ['user:'.$this->buyer->id], 'receiving_qc' => ['user:'.$this->qc->id],
                'acceptance_qa' => ['user:'.$this->owner->id], 'post_grn' => ['user:'.$this->buyer->id]],
        ]);
    }

    private function arrival(): array
    {
        return ['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'),
            'request_id' => (string) Str::uuid(), 'source_line_id' => null, 'unlinked_reason' => 'Source pending',
            'shipment_ref' => 'SYNTHETIC-LEDGER', 'package_ref' => null, 'received_at' => '2026-10-27T08:00:00+03:30',
            'observed_description' => 'Synthetic receipt', 'quantity' => '2.000', 'unit' => 'm', 'heat_ref' => 'HEAT-QA',
            'location_kind' => 'QUARANTINE', 'location_ref' => 'Bay Q', 'document_status' => 'MISSING', 'notes' => null];
    }

    private function qcReview(?string $head = null, string $result = 'INCOMPLETE'): string
    {
        return app(PipingReceivingReviewService::class)->create($this->project, $this->qc, $this->receipt, [
            'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'receiving_qc'),
            'previous_review_id' => $head, 'request_id' => (string) Str::uuid(), 'reviewed_at' => '2026-10-27T09:00:00+03:30',
            'observed_result' => $result, 'findings' => 'Synthetic receiving observation']);
    }

    private function custodyScoped(callable $fn, ?User $actor = null): mixed
    {
        return app(PipingCustodyService::class)->scoped($this->project, $actor ?? $this->owner, $fn);
    }

    private function evidenceFile(): string
    {
        return app(TenantContext::class)->runAs($this->buyer->company_id, function (): string {
            $d = Document::create(['project_id' => $this->project->id, 'company_id' => $this->buyer->company_id,
                'number' => 'LEDGER-SPEC-'.Str::random(5), 'discipline' => 'PIPING', 'doc_type' => 'SPEC', 'sequence' => 900,
                'title_en' => 'Synthetic ledger evidence', 'title_fa' => 'مدرک آزمایشی دفتر']);
            $r = DocumentRevision::create(['document_id' => $d->id, 'project_id' => $this->project->id,
                'company_id' => $this->buyer->company_id, 'revision' => 'A', 'ordinal' => 1, 'cde_state' => 'PUBLISHED',
                'suitability' => 'A1', 'issued_by' => $this->buyer->id, 'issued_at' => now()]);
            $d->update(['current_revision_id' => $r->id]);
            $path = 'qa/'.Str::uuid().'.txt';
            $bytes = 'Synthetic requirement/certificate bundle';
            Storage::disk('local')->put($path, $bytes);

            return DocumentFile::create(['document_id' => $d->id, 'revision_id' => $r->id, 'project_id' => $this->project->id,
                'company_id' => $this->buyer->company_id, 'original_name' => 'qa-bundle.txt', 'storage_path' => $path,
                'disk' => 'local', 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'mime_type' => 'text/plain',
                'extension' => 'txt', 'uploaded_by' => $this->buyer->id, 'uploaded_at' => now()])->id;
        }, $this->project->id);
    }

    private function issuedSourceLine(): string
    {
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, [
            'version' => 0, 'selectors' => ['user:'.$this->buyer->id], 'suspend' => false, 'reason' => 'Synthetic ledger source']);
        $vendor = Company::where('code', 'VEN-AZAR')->firstOrFail();
        $po = app(PurchaseOrderEntryService::class)->create($this->project, $this->buyer, [
            'currency' => 'USD', 'title_en' => 'Synthetic ledger PO', 'title_fa' => 'منبع آزمایشی دفتر',
            'discipline' => 'PIPING', 'material_category' => 'PIPE', 'vendor_company_id' => $vendor->id,
            'lines' => [['item_code' => 'QA-PIPE', 'description_en' => 'Pipe', 'description_fa' => 'لوله',
                'unit' => 'M', 'quantity' => '5.000', 'unit_price' => '10.01']]]);
        $issuer = $this->colleague;
        app(PurchaseOrderExecutionAccessService::class)->configure($this->project, $this->owner, [
            'buyer_company_id' => $this->buyer->company_id, 'version' => 0,
            'finance_selectors' => ['user:'.$this->qc->id], 'issue_selectors' => ['user:'.$issuer->id],
            'suspend' => false, 'reason' => 'Synthetic ledger issue nominations']);
        $exec = app(PurchaseOrderExecutionService::class);
        $hash = app(TenantContext::class)->runAs($this->buyer->company_id, fn () => $exec->hash($po), $this->project->id);
        $exec->act($this->project, $this->qc, $this->buyer->company_id, $po->id, 'finance', ['content_hash' => $hash, 'reason' => 'Synthetic financial review']);
        $approval = app(TenantContext::class)->runAs($this->buyer->company_id, fn () => DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->value('id'), $this->project->id);
        $exec->act($this->project, $issuer, $this->buyer->company_id, $po->id, 'issue', ['content_hash' => $hash, 'reason' => 'Synthetic issue', 'approval_id' => $approval]);
        $line = app(TenantContext::class)->runAs($this->buyer->company_id, fn () => DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->value('id'), $this->project->id);

        // A fresh custody bound to the issued source, with a clean QC review.
        $arrival = $this->arrival();
        $arrival['source_line_id'] = $line;
        $arrival['unit'] = 'M';
        $arrival['unlinked_reason'] = null;
        $this->receipt = app(PipingCustodyService::class)->create($this->project, $this->buyer, $arrival);
        $this->review = $this->qcReview(null, 'NO_NONCONFORMITY_OBSERVED');

        return $line;
    }

    private function acceptedQa(): \stdClass
    {
        $this->issuedSourceLine();
        $file = $this->evidenceFile();
        app(PipingQaDecisionService::class)->create($this->project, $this->owner, $this->receipt, [
            'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->owner, 'acceptance_qa'),
            'previous_decision_id' => null, 'review_id' => $this->review, 'request_id' => (string) Str::uuid(),
            'decision' => 'ACCEPTED', 'reason' => 'Complete manual assessment', 'open_conditions' => null,
            'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true),
            'requirements_file_ids' => [$file], 'evidence_file_ids' => [$file]]);

        return $this->custodyScoped(fn () => DB::table('piping_qa_decisions')->where('custody_id', $this->receipt)->orderBy('sequence')->get()->last(), $this->buyer);
    }

    private function grnInput(\stdClass $qa): array
    {
        return ['request_id' => (string) Str::uuid(),
            'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'post_grn'),
            'qa_id' => $qa->id, 'qa_payload_hash' => $qa->payload_hash, 'location_ref' => 'WH-A/RACK-1',
            'reason' => 'Whole receipt accepted into storage', 'warehouse_confirmed' => true];
    }
}
