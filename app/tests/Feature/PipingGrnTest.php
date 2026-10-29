<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentRevision;
use App\Models\Project;
use App\Models\User;
use App\Services\PipingCustodyAmendmentService;
use App\Services\LegacyMaterialReconciliationService;
use App\Services\PipingCustodyService;
use App\Services\PipingGrnService;
use App\Services\PipingQaDecisionService;
use App\Services\PipingReceiptAccessService;
use App\Services\PipingReceivingReviewService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Services\PurchaseOrderExecutionAccessService;
use App\Services\PurchaseOrderExecutionService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ResultRecorderPolicyFixture;
use Tests\TestCase;

final class PipingGrnTest extends TestCase
{
    private User $owner;

    private User $buyer;

    private User $qc;

    private Project $project;

    private string $receipt;

    private string $review;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->buyer = $this->userByEmail('rezaei@pars.example');
        $this->qc = $this->userByEmail('tehrani@pmc.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->configure();
        $this->receipt = app(PipingCustodyService::class)->create($this->project, $this->buyer, $this->arrival());
        $this->review = $this->qc();
    }

    private function configure(int $version = 0, ?array $actions = null): void
    {
        app(PipingReceiptAccessService::class)->configure($this->project, $this->owner, ['version' => $version, 'suspend' => false, 'reason' => 'Synthetic QA matrix', 'custodian_company_id' => $this->buyer->company_id,
            'actions' => $actions ?? ['custody_mrr' => ['user:'.$this->buyer->id], 'receiving_qc' => ['user:'.$this->qc->id], 'acceptance_qa' => ['user:'.$this->owner->id], 'post_grn' => ['user:'.$this->buyer->id]]]);
    }

    private function arrival(): array
    {
        return ['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'), 'request_id' => (string) Str::uuid(), 'source_line_id' => null, 'unlinked_reason' => 'Source pending', 'shipment_ref' => 'SYNTHETIC-QA', 'package_ref' => null, 'received_at' => '2026-10-23T08:00:00+03:30', 'observed_description' => 'Synthetic receipt', 'quantity' => '2.000', 'unit' => 'm', 'heat_ref' => 'HEAT-QA', 'location_kind' => 'QUARANTINE', 'location_ref' => 'Bay Q', 'document_status' => 'MISSING', 'notes' => null];
    }

    private function qc(?string $head = null, string $result = 'INCOMPLETE'): string
    {
        return app(PipingReceivingReviewService::class)->create($this->project, $this->qc, $this->receipt, ['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'receiving_qc'), 'previous_review_id' => $head, 'request_id' => (string) Str::uuid(), 'reviewed_at' => '2026-10-23T09:00:00+03:30', 'observed_result' => $result, 'findings' => 'Synthetic receiving observation']);
    }

    private function scoped(callable $fn, ?User $actor = null): mixed
    {
        return app(PipingCustodyService::class)->scoped($this->project, $actor ?? $this->owner, $fn);
    }

    private function input(array $changes = []): array
    {
        return array_replace(['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->owner, 'acceptance_qa'), 'previous_decision_id' => null, 'review_id' => $this->review, 'request_id' => (string) Str::uuid(), 'decision' => 'HOLD', 'reason' => 'MTC missing; no release <script>unsafe-qa()</script>', 'open_conditions' => 'Missing mandatory documents', 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, false), 'requirements_file_ids' => [], 'evidence_file_ids' => []], $changes);
    }

    private function endpoint(): string
    {
        return '/projects/PH-14/materials/piping-custody/'.$this->receipt.'/qa?lang=en';
    }

    private function create(array $data): string
    {
        return app(PipingQaDecisionService::class)->create($this->project, $this->owner, $this->receipt, $data);
    }

    private function rows()
    {
        return $this->scoped(fn () => DB::table('piping_qa_decisions')->where('custody_id', $this->receipt)->orderBy('sequence')->get());
    }

    private function file(string $state = 'PUBLISHED', ?User $actor = null): string
    {
        $actor ??= $this->buyer;

        return app(TenantContext::class)->runAs($actor->company_id, function () use ($actor, $state): string {
            $d = Document::create(['project_id' => $this->project->id, 'company_id' => $actor->company_id, 'number' => 'QA-SPEC-'.Str::random(5), 'discipline' => 'PIPING', 'doc_type' => 'SPEC', 'sequence' => 900, 'title_en' => 'Synthetic QA evidence', 'title_fa' => 'مدرک آزمایشی']);
            $r = DocumentRevision::create(['document_id' => $d->id, 'project_id' => $this->project->id, 'company_id' => $actor->company_id, 'revision' => 'A', 'ordinal' => 1, 'cde_state' => $state, 'suitability' => $state === 'WIP' ? null : 'A1', 'issued_by' => $actor->id, 'issued_at' => now()]);
            $d->update(['current_revision_id' => $r->id]);
            $path = 'qa/'.Str::uuid().'.txt';
            $bytes = 'Synthetic requirement/certificate bundle';
            Storage::disk('local')->put($path, $bytes);

            return DocumentFile::create(['document_id' => $d->id, 'revision_id' => $r->id, 'project_id' => $this->project->id, 'company_id' => $actor->company_id, 'original_name' => 'qa-bundle.txt', 'storage_path' => $path, 'disk' => 'local', 'sha256' => hash('sha256', $bytes), 'size_bytes' => strlen($bytes), 'mime_type' => 'text/plain', 'extension' => 'txt', 'uploaded_by' => $actor->id, 'uploaded_at' => now()])->id;
        }, $this->project->id);
    }

    private function issuedSource(): void
    {
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, ['version' => 0, 'selectors' => ['user:'.$this->buyer->id], 'suspend' => false, 'reason' => 'Synthetic source']);
        $vendor = Company::where('code', 'VEN-AZAR')->firstOrFail();
        $po = app(PurchaseOrderEntryService::class)->create($this->project, $this->buyer, ['currency' => 'USD', 'title_en' => 'Synthetic QA PO', 'title_fa' => 'منبع آزمایشی', 'discipline' => 'PIPING', 'material_category' => 'PIPE', 'vendor_company_id' => $vendor->id, 'lines' => [['item_code' => 'QA-PIPE', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '5.000', 'unit_price' => '10.01']]]);
        $issuer = $this->userByEmail('sadeghi@pars.example');
        app(PurchaseOrderExecutionAccessService::class)->configure($this->project, $this->owner, ['buyer_company_id' => $this->buyer->company_id, 'version' => 0, 'finance_selectors' => ['user:'.$this->qc->id], 'issue_selectors' => ['user:'.$issuer->id], 'suspend' => false, 'reason' => 'Synthetic source issue nominations']);
        $exec = app(PurchaseOrderExecutionService::class);
        $hash = app(TenantContext::class)->runAs($this->buyer->company_id, fn () => $exec->hash($po), $this->project->id);
        $exec->act($this->project, $this->qc, $this->buyer->company_id, $po->id, 'finance', ['content_hash' => $hash, 'reason' => 'Synthetic financial review']);
        $approval = app(TenantContext::class)->runAs($this->buyer->company_id, fn () => DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->value('id'), $this->project->id);
        $exec->act($this->project, $issuer, $this->buyer->company_id, $po->id, 'issue', ['content_hash' => $hash, 'reason' => 'Synthetic issue', 'approval_id' => $approval]);
        $line = app(TenantContext::class)->runAs($this->buyer->company_id, fn () => DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->value('id'), $this->project->id);
        $p = $this->arrival();
        $p['source_line_id'] = $line;
        $p['unit'] = 'M';
        $p['unlinked_reason'] = null;
        $this->receipt = app(PipingCustodyService::class)->create($this->project, $this->buyer, $p);
        $this->review = $this->qc(null, 'NO_NONCONFORMITY_OBSERVED');
    }

    private function accepted(): string
    {
        $this->issuedSource();
        $f = $this->file();

        return $this->create($this->input(['decision' => 'ACCEPTED', 'open_conditions' => null, 'reason' => 'Complete manual assessment', 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true), 'requirements_file_ids' => [$f], 'evidence_file_ids' => [$f]]));
    }

    private function grnInput(array $extra = []): array
    {
        $q = $this->rows()->last();

        return array_replace(['request_id' => (string) Str::uuid(), 'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'post_grn'), 'qa_id' => $q->id, 'qa_payload_hash' => $q->payload_hash, 'location_ref' => 'WH-A/RACK-1', 'reason' => 'Whole receipt accepted into storage <script>grn()</script>', 'warehouse_confirmed' => true], $extra);
    }

    private function postGrn(array $d, ?User $actor = null): string
    {
        return app(PipingGrnService::class)->post($this->project, $actor ?? $this->buyer, $this->receipt, $d);
    }

    private function grnUrl(): string
    {
        return '/projects/PH-14/materials/piping-custody/'.$this->receipt.'/grn?lang=en';
    }

    private function grnRows(string $table = 'piping_grn_records')
    {
        return $this->scoped(fn () => DB::table($table)->where('project_id', $this->project->id)->get(), $this->buyer);
    }

    public function test_whole_accepted_receipt_posts_exactly_one_credit_and_original_arrival_is_immutable(): void
    {
        $this->accepted();
        $before = $this->scoped(fn () => DB::table('piping_custody_records')->where('id', $this->receipt)->first());
        $input = $this->grnInput();
        $id = $this->postGrn($input);
        $g = $this->grnRows()->first();
        $credit = $this->grnRows('piping_accepted_stock_entries')->first();
        $this->assertSame($id, $g->id);
        $this->assertSame($g->quantity, $credit->quantity);
        $this->assertSame('2.000', $g->quantity);
        $this->assertSame('M', $g->unit);
        $this->assertNull($g->qa_payload);
        $this->assertSame($this->buyer->id, $g->posted_by);
        $this->assertSame($this->buyer->company_id, $g->custodian_company_id);
        $this->assertEquals($before, $this->scoped(fn () => DB::table('piping_custody_records')->where('id', $this->receipt)->first()));
        $this->assertSame(0, $this->scoped(fn () => DB::table('material_lots')->where('purchase_order_id', $g->purchase_order_id)->count()));
        $this->assertSame($id, $this->postGrn($input));
        $this->assertCount(1, $this->grnRows());
        $this->assertCount(1, $this->grnRows('piping_accepted_stock_entries'));
    }

    public function test_http_bilingual_no_store_form_receipt_link_and_escaped_posted_reason(): void
    {
        $this->accepted();
        $this->actingAs($this->buyer)->get($this->grnUrl())->assertOk()->assertSee('piping-grn-form')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->get(str_replace('lang=en', 'lang=fa', $this->grnUrl()))->assertOk()->assertSee('اعتبار موجودی');
        $this->postJson($this->grnUrl(), ['grn' => $this->grnInput()])->assertRedirect();
        $this->get($this->grnUrl())->assertOk()->assertSee('piping-grn-posted')->assertSee('&lt;script&gt;grn()', false)->assertDontSee('<script>grn()</script>', false)->assertDontSee('piping-grn-form');
    }

    public function test_hold_or_rejected_qa_cannot_credit_stock(): void
    {
        $this->create($this->input());
        $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $this->grnInput()])->assertUnprocessable();
        $this->create($this->input(['decision' => 'REJECTED', 'previous_decision_id' => $this->rows()->last()->id]));
        $this->postJson($this->grnUrl(), ['grn' => $this->grnInput()])->assertUnprocessable();
        $this->assertCount(0, $this->grnRows());
    }

    public function test_no_grn_inheritance_from_qa_qc_owner_or_receipt_roles(): void
    {
        $this->accepted();
        $d = $this->grnInput();
        foreach ([$this->owner, $this->qc] as $u) {
            $this->actingAs($u)->postJson($this->grnUrl(), ['grn' => $d])->assertForbidden();
        }$this->assertCount(0, $this->grnRows());
    }

    public function test_unknown_quantity_state_custodian_and_missing_confirmation_are_rejected(): void
    {
        $this->accepted();
        $d = $this->grnInput();
        foreach (['quantity' => '1.000', 'custodian_company_id' => $this->owner->company_id, 'state' => 'POSTED', 'source_line_id' => (string) Str::uuid()] as $k => $v) {
            $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $d + [$k => $v]])->assertUnprocessable();
        }$this->postJson($this->grnUrl(), ['grn' => array_replace($d, ['warehouse_confirmed' => false])])->assertUnprocessable();
        $this->assertCount(0, $this->grnRows());
    }

    public function test_stale_qa_or_changed_qc_block_before_credit(): void
    {
        $this->accepted();
        $d = $this->grnInput();
        $this->review = $this->qc($this->review, 'NO_NONCONFORMITY_OBSERVED');
        $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $d])->assertUnprocessable();
        $this->assertCount(0, $this->grnRows());
    }

    public function test_all_citations_must_be_visible_not_just_one_per_kind(): void
    {
        $this->issuedSource();
        $a = $this->file();
        $b = $this->file();
        $this->create($this->input(['decision' => 'ACCEPTED', 'open_conditions' => null, 'reason' => 'Two exact citations', 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true), 'requirements_file_ids' => [$a, $b], 'evidence_file_ids' => [$a, $b]]));
        DB::unprepared("CREATE POLICY grn_hide_citation ON piping_qa_evidence AS RESTRICTIVE FOR SELECT USING(file_id<>'{$b}'::uuid)");
        try {
            $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $this->grnInput()])->assertUnprocessable();
            $this->assertCount(0, $this->grnRows());
        } finally {
            DB::unprepared('DROP POLICY grn_hide_citation ON piping_qa_evidence');
        }
    }

    public function test_posted_receipt_cannot_be_revised_and_dashboards_disable_writers(): void
    {
        $this->accepted();
        $this->postGrn($this->grnInput());
        $this->assertFalse(app(PipingQaDecisionService::class)->dashboard($this->project, $this->owner, $this->receipt)['writable']);
        $this->actingAs($this->owner)->postJson('/projects/PH-14/materials/piping-custody/'.$this->receipt.'/qa?lang=en', ['qa' => $this->input(['previous_decision_id' => $this->rows()->last()->id])])->assertUnprocessable();
        $this->assertCount(1, $this->grnRows('piping_accepted_stock_entries'));
    }

    private function acceptedSameSource(string $quantity = '2.000'): void
    {
        $basis = app(PipingCustodyService::class)->basis($this->project, $this->buyer, $this->receipt);
        $a = $this->arrival();
        $a['source_line_id'] = $basis['source_line_id'];
        $a['unlinked_reason'] = null;
        $a['unit'] = 'M';
        $a['quantity'] = $quantity;
        $this->receipt = app(PipingCustodyService::class)->create($this->project, $this->buyer, $a);
        $this->review = $this->qc(null, 'NO_NONCONFORMITY_OBSERVED');
        $f = $this->file();
        $this->create($this->input(['decision' => 'ACCEPTED', 'open_conditions' => null, 'reason' => 'Another exact receipt assessment', 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true), 'requirements_file_ids' => [$f], 'evidence_file_ids' => [$f]]));
    }

    public function test_decimal_cumulative_line_ceiling_and_whole_receipts_no_tolerance(): void
    {
        $this->accepted();
        $this->postGrn($this->grnInput());
        $this->acceptedSameSource('3.000');
        $this->postGrn($this->grnInput());
        $this->acceptedSameSource('0.001');
        $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $this->grnInput()])->assertUnprocessable();
        $this->assertSame('5.000', $this->scoped(fn () => DB::table('piping_accepted_stock_entries')->sum('quantity'), $this->buyer));
        $this->assertCount(2, $this->grnRows());
    }

    public function test_independently_nominated_qa_actor_can_post_without_impersonating_custodian(): void
    {
        $this->accepted();
        $this->configure(1, ['custody_mrr' => ['user:'.$this->buyer->id], 'receiving_qc' => ['user:'.$this->qc->id], 'acceptance_qa' => ['user:'.$this->owner->id], 'post_grn' => ['user:'.$this->owner->id]]);
        $d = $this->grnInput(['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->owner, 'post_grn')]);
        $id = $this->postGrn($d, $this->owner);
        $g = $this->grnRows()->first();
        $this->assertSame($id, $g->id);
        $this->assertSame($this->owner->company_id, $g->company_id);
        $this->assertSame($this->owner->id, $g->posted_by);
        $this->assertSame($this->buyer->company_id, $g->custodian_company_id);
    }

    public function test_tampered_blob_prevents_post(): void
    {
        $this->accepted();
        $file = $this->scoped(fn () => DB::table('document_files')->whereIn('id', DB::table('piping_qa_evidence')->where('decision_id', $this->rows()->last()->id)->select('file_id'))->first(), $this->buyer);
        Storage::disk('local')->put($file->storage_path, str_repeat('X', $file->size_bytes));
        $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $this->grnInput()])->assertUnprocessable();
        $this->assertCount(0, $this->grnRows('piping_accepted_stock_entries'));
    }

    public function test_metadata_policy_drift_cannot_turn_hidden_previous_credit_into_available_capacity(): void
    {
        $this->accepted();
        $this->postGrn($this->grnInput());
        $this->acceptedSameSource();
        $d = $this->grnInput();
        DB::unprepared('CREATE POLICY grn_hidden_inventory ON piping_grn_records AS RESTRICTIVE FOR SELECT USING(false)');
        try {
            $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $d])->assertUnprocessable();
        } finally {
            DB::unprepared('DROP POLICY grn_hidden_inventory ON piping_grn_records');
        }$this->assertCount(1, $this->grnRows());
    }

    public function test_legacy_source_stock_is_never_silently_reconciled_and_legacy_piping_writer_is_closed(): void
    {
        $this->accepted();
        $basis = app(PipingCustodyService::class)->basis($this->project, $this->buyer, $this->receipt);
        $this->scoped(function () use ($basis) {
            DB::unprepared('ALTER TABLE material_lots DISABLE TRIGGER aa_piping_legacy_stock_cutoff');
            try {
                DB::table('material_lots')->insert(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'company_id' => $this->buyer->company_id, 'purchase_order_id' => $basis['purchase_order_id'], 'lot_code' => 'SYNTHETIC-PRE-CUTOVER', 'item_code' => 'LEGACY', 'description_en' => 'Legacy fixture', 'description_fa' => 'قدیمی', 'unit' => 'M']);
            } finally {
                DB::unprepared('ALTER TABLE material_lots ENABLE TRIGGER aa_piping_legacy_stock_cutoff');
            }
        }, $this->buyer);
        $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $this->grnInput()])->assertUnprocessable();
        $this->assertCount(0, $this->grnRows());
        try {
            $this->scoped(fn () => DB::table('material_lots')->insert(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'company_id' => $this->buyer->company_id, 'purchase_order_id' => $basis['purchase_order_id'], 'lot_code' => 'SHOULD-BLOCK', 'item_code' => 'X', 'description_en' => 'X', 'description_fa' => 'X', 'unit' => 'M']), $this->buyer);
            $this->fail('Expected SQL cutoff');
        } catch (QueryException $e) {
            $this->assertSame('23514', (string) $e->getCode());
        }
    }

    public function test_the_legacy_gate_opens_once_the_lot_is_reconciled_and_only_then(): void
    {
        $this->accepted();
        $basis = app(PipingCustodyService::class)->basis($this->project, $this->buyer, $this->receipt);

        $lot = (string) Str::uuid();
        $this->scoped(function () use ($basis, $lot): void {
            DB::unprepared('ALTER TABLE material_lots DISABLE TRIGGER aa_piping_legacy_stock_cutoff');
            try {
                DB::table('material_lots')->insert(['id' => $lot, 'project_id' => $this->project->id, 'company_id' => $this->buyer->company_id, 'purchase_order_id' => $basis['purchase_order_id'], 'lot_code' => 'PRE-CUTOVER-1', 'item_code' => 'LEGACY', 'description_en' => 'Legacy fixture', 'description_fa' => 'قدیمی', 'unit' => 'M']);
            } finally {
                DB::unprepared('ALTER TABLE material_lots ENABLE TRIGGER aa_piping_legacy_stock_cutoff');
            }
        }, $this->buyer);

        // The gate holds: nothing has been decided about that lot yet.
        $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $this->grnInput()])->assertUnprocessable();
        $this->assertCount(0, $this->grnRows());

        ResultRecorderPolicyFixture::ensure(
            app(TenantContext::class), $this->project, $this->buyer, $this->buyer->company_id,
            'reconcile_legacy', ['user:'.$this->buyer->id],
        );
        app(LegacyMaterialReconciliationService::class)->reconcile($this->project, $this->buyer, $lot, [
            'decision' => 'RETIRE', 'reason' => 'Issued to the site before cutover; no controlled receipt exists',
            'request_id' => (string) Str::uuid(),
        ], 'en');

        // And now it opens — not because old rows were mapped onto new ones, but
        // because somebody took a decision and said why.
        $this->postGrn($this->grnInput());
        $this->assertCount(1, $this->grnRows());
    }

    public function test_transaction_rollback_leaves_no_grn_credit_or_posted_cutoff(): void
    {
        $this->accepted();
        try {
            DB::transaction(function () {
                $this->postGrn($this->grnInput());
                throw new \RuntimeException('rollback controlled receipt');
            });
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback controlled receipt', $e->getMessage());
        }
        foreach (['piping_grn_records', 'piping_accepted_stock_entries', 'piping_grn_posted_index'] as $table) {
            $this->assertCount(0, $this->grnRows($table));
        }
    }

    public function test_posting_revocation_does_not_recredit_and_archived_read_remains_available(): void
    {
        $this->accepted();
        $d = $this->grnInput();
        $this->postGrn($d);
        $this->configure(1, ['custody_mrr' => ['user:'.$this->buyer->id], 'receiving_qc' => ['user:'.$this->qc->id], 'acceptance_qa' => ['user:'.$this->owner->id], 'post_grn' => ['user:'.$this->owner->id]]);
        $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $d])->assertForbidden();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'GRN archive fixture');
        $this->get($this->grnUrl())->assertOk();
        $this->assertCount(1, $this->grnRows('piping_accepted_stock_entries'));
    }

    public function test_independent_doc_view_is_required_even_with_live_grn_and_source_reading(): void
    {
        $this->accepted();
        $d = $this->grnInput();
        app(TenantContext::class)->runAs($this->buyer->company_id, fn () => DB::table('project_memberships')->where('project_id', $this->project->id)->where('user_id', $this->buyer->id)->update(['role_codes' => json_encode(['qc_inspector'])]), $this->project->id);
        $this->assertNotNull(app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'post_grn'));
        $this->assertNotNull(app(PipingCustodyService::class)->basis($this->project, $this->buyer, $this->receipt));
        $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $d])->assertUnprocessable();
        $this->assertCount(0, $this->grnRows());
    }

    public function test_effective_amendment_requires_fresh_qc_qa_then_posts_its_exact_heat(): void
    {
        $this->accepted();
        $old = $this->grnInput();
        $basis = app(PipingCustodyService::class)->basis($this->project, $this->buyer, $this->receipt);
        $am = app(PipingCustodyAmendmentService::class)->create($this->project, $this->buyer, $this->receipt, ['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'), 'request_id' => (string) Str::uuid(), 'previous_amendment_id' => null, 'source_line_id' => $basis['source_line_id'], 'unlinked_reason' => null, 'heat_ref' => 'CORRECTED-HEAT', 'reason' => 'Verified traceability correction']);
        $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $old])->assertUnprocessable();
        $this->review = app(PipingReceivingReviewService::class)->create($this->project, $this->qc, $this->receipt, ['amendment_id' => $am, 'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'receiving_qc'), 'previous_review_id' => $this->review, 'request_id' => (string) Str::uuid(), 'reviewed_at' => '2026-10-23T12:00:00+03:30', 'observed_result' => 'NO_NONCONFORMITY_OBSERVED', 'findings' => 'Fresh amended observation']);
        $f = $this->file();
        $this->create($this->input(['amendment_id' => $am, 'previous_decision_id' => $this->rows()->last()->id, 'decision' => 'ACCEPTED', 'open_conditions' => null, 'reason' => 'Fresh amended manual assessment', 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true), 'requirements_file_ids' => [$f], 'evidence_file_ids' => [$f]]));
        $this->postGrn($this->grnInput());
        $g = $this->grnRows()->first();
        $this->assertSame($am, $g->amendment_id);
        $this->assertSame('CORRECTED-HEAT', $g->heat_ref);
        $this->actingAs($this->buyer)->postJson('/projects/PH-14/materials/piping-custody/'.$this->receipt.'/amendments?lang=en', ['amendment' => []])->assertUnprocessable();
        $this->assertFalse(app(PipingCustodyAmendmentService::class)->dashboard($this->project, $this->buyer, $this->receipt)['writable']);
        $this->assertFalse(app(PipingReceivingReviewService::class)->dashboard($this->project, $this->qc, $this->receipt)['writable']);
    }

    private function rawGrn(array $input): array
    {
        return $this->scoped(function () use ($input) {
            $q = DB::table('piping_qa_decisions')->where('id', $input['qa_id'])->first();
            $proof = (new \ReflectionMethod(PipingGrnService::class, 'proof'))->invoke(app(PipingGrnService::class), $this->project, $this->buyer, $q);

            return ['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'company_id' => $this->buyer->company_id, 'posted_by' => $this->buyer->id, 'policy_id' => $input['expected_policy_id'], 'custody_id' => $this->receipt, 'qa_id' => $q->id, 'qa_payload_hash' => $q->payload_hash, 'qa_payload' => $proof, 'request_id' => $input['request_id'], 'request_hash' => str_repeat('a', 64), 'location_ref' => 'RAW-TEST', 'reason' => 'Synthetic SQL guard test'];
        }, $this->buyer);
    }

    public function test_database_overwrites_client_quantity_and_custodian_and_rejects_manual_credit_or_late_review(): void
    {
        $this->accepted();
        $raw = $this->rawGrn($this->grnInput());
        $raw['quantity'] = '999.999';
        $raw['unit'] = 'KG';
        $raw['custodian_company_id'] = $this->owner->company_id;
        $this->scoped(function () use ($raw) {
            DB::table('piping_grn_records')->insert($raw);
            DB::statement('SET CONSTRAINTS piping_grn_complete IMMEDIATE');
            DB::statement('SET CONSTRAINTS piping_grn_complete DEFERRED');
        }, $this->buyer);
        $g = $this->grnRows()->first();
        $this->assertSame('2.000', $g->quantity);
        $this->assertSame('M', $g->unit);
        $this->assertSame($this->buyer->company_id, $g->custodian_company_id);
        foreach (['piping_qa_decisions', 'piping_receiving_reviews', 'piping_custody_amendments'] as $table) {
            try {
                $this->scoped(fn () => DB::table($table)->insert(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'custody_id' => $this->receipt]), $this->buyer);
                $this->fail('Expected SQL posted cutoff');
            } catch (QueryException $e) {
                $this->assertSame('23514', (string) $e->getCode());
                $this->assertStringContainsString('PIPING_GRN_POSTED_CORRECTION', $e->getMessage());
            }
        }
        try {
            $this->scoped(fn () => DB::table('piping_accepted_stock_entries')->insert(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'grn_id' => $g->id]), $this->buyer);
            $this->fail('Expected derived credit guard');
        } catch (QueryException $e) {
            $this->assertSame('23514', (string) $e->getCode());
        }$this->assertCount(1, $this->grnRows('piping_accepted_stock_entries'));
    }

    public function test_competing_connection_waits_then_cannot_overdeliver_same_exact_line(): void
    {
        $this->accepted();
        $first = $this->receipt;
        $firstInput = $this->grnInput();
        $this->acceptedSameSource('4.000');
        $raw = $this->rawGrn($this->grnInput());
        $cfg = config('database.connections.pgsql');
        $pdo = new \PDO('pgsql:host='.$cfg['host'].';port='.$cfg['port'].';dbname='.$cfg['database'], $cfg['username'], $cfg['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $insert = 'INSERT INTO piping_grn_records ('.implode(',', array_keys($raw)).') VALUES ('.implode(',', array_fill(0, count($raw), '?')).')';
        $bind = function () use ($pdo) {
            $pdo->beginTransaction();
            foreach (['epcc.company_id' => $this->buyer->company_id, 'epcc.project_id' => $this->project->id, 'epcc.receipt_actor_id' => $this->buyer->id, 'lock_timeout' => '150ms'] as $k => $v) {
                $pdo->prepare('SELECT set_config(?, ?,true)')->execute([$k, $v]);
            }
        };
        DB::transaction(function () use ($pdo, $insert, $raw, $bind, $first, $firstInput) {
            app(PipingGrnService::class)->post($this->project, $this->buyer, $first, $firstInput);
            $bind();
            try {
                $pdo->prepare($insert)->execute(array_values($raw));
                $this->fail('Expected competing lock');
            } catch (\PDOException $e) {
                $this->assertSame('55P03', $e->getCode());
            } finally {
                $pdo->rollBack();
            }
        });
        $bind();
        try {
            $pdo->prepare($insert)->execute(array_values($raw));
            $this->fail('Expected exact-line ceiling after first commit');
        } catch (\PDOException $e) {
            $this->assertSame('23514', $e->getCode());
            $this->assertStringContainsString('PIPING_GRN_ORDERED_QUANTITY_EXCEEDED', $e->getMessage());
        } finally {
            $pdo->rollBack();
        }
        foreach (['piping_grn_records', 'piping_accepted_stock_entries', 'piping_grn_posted_index'] as $table) {
            $this->assertCount(1, $this->grnRows($table));
        }
    }

    public function test_posted_cutoff_survives_hidden_amended_source_without_revealing_grn_source(): void
    {
        $this->issuedSource();
        $source = app(PipingCustodyService::class)->basis($this->project, $this->buyer, $this->receipt);
        $arrival = $this->arrival();
        $arrival['unit'] = 'M';
        $this->receipt = app(PipingCustodyService::class)->create($this->project, $this->buyer, $arrival);
        $original = $this->receipt;
        $this->review = $this->qc();
        $oldReview = $this->review;
        $am = app(PipingCustodyAmendmentService::class)->create($this->project, $this->buyer, $this->receipt, ['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'), 'request_id' => (string) Str::uuid(), 'previous_amendment_id' => null, 'source_line_id' => $source['source_line_id'], 'unlinked_reason' => null, 'heat_ref' => 'RESOLVED-HEAT', 'reason' => 'Resolve initially unlinked arrival']);
        $this->review = app(PipingReceivingReviewService::class)->create($this->project, $this->qc, $this->receipt, ['amendment_id' => $am, 'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'receiving_qc'), 'previous_review_id' => $oldReview, 'request_id' => (string) Str::uuid(), 'reviewed_at' => '2026-10-23T12:00:00+03:30', 'observed_result' => 'NO_NONCONFORMITY_OBSERVED', 'findings' => 'Resolved source inspected']);
        $f = $this->file();
        $this->create($this->input(['amendment_id' => $am, 'decision' => 'ACCEPTED', 'open_conditions' => null, 'reason' => 'Resolved complete source assessed', 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true), 'requirements_file_ids' => [$f], 'evidence_file_ids' => [$f]]));
        $this->postGrn($this->grnInput());
        $po = $source['purchase_order_id'];
        DB::unprepared("CREATE POLICY grn_source_hidden ON purchase_orders AS RESTRICTIVE FOR SELECT USING(id<>'{$po}'::uuid)");
        try {
            $this->assertCount(0, $this->grnRows());
            $this->assertCount(1, $this->grnRows('piping_grn_posted_index'));
            try {
                $this->scoped(fn () => DB::table('piping_custody_amendments')->insert(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'custody_id' => $original]), $this->buyer);
                $this->fail('Posted cutoff must not disappear');
            } catch (QueryException $e) {
                $this->assertStringContainsString('PIPING_GRN_POSTED_CORRECTION', $e->getMessage());
            }
        } finally {
            DB::unprepared('DROP POLICY grn_source_hidden ON purchase_orders');
        }
    }

    public function test_different_request_cannot_repost_and_same_request_cannot_change_intent(): void
    {
        $this->accepted();
        $d = $this->grnInput();
        $id = $this->postGrn($d);
        $this->actingAs($this->buyer)->postJson($this->grnUrl(), ['grn' => $this->grnInput()])->assertUnprocessable();
        $this->postJson($this->grnUrl(), ['grn' => array_replace($d, ['location_ref' => 'DIFFERENT'])])->assertUnprocessable();
        $reordered = array_reverse($d, true);
        $this->assertSame($id, $this->postGrn($reordered));
        $this->assertCount(1, $this->grnRows('piping_accepted_stock_entries'));
    }
}
