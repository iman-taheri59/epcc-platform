<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentRevision;
use App\Models\MaterialItem;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\MaterialRequisitionAccessService;
use App\Services\MaterialRequisitionEntryService;
use App\Services\PipingCustodyAmendmentService;
use App\Services\PipingCustodyService;
use App\Services\PipingQaDecisionService;
use App\Services\PipingReceiptAccessService;
use App\Services\PipingReceivingReviewService;
use App\Services\PipingTraceabilityService;
use App\Services\ProcurementService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Services\PurchaseOrderExecutionAccessService;
use App\Services\PurchaseOrderExecutionService;
use App\Services\PurchaseOrderPricingService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PipingTraceabilityTest extends TestCase
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
        return ['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'), 'request_id' => (string) Str::uuid(), 'source_line_id' => null, 'unlinked_reason' => 'Source pending', 'shipment_ref' => 'SYNTHETIC-QA', 'package_ref' => null, 'received_at' => '2026-10-23T08:00:00+03:30', 'observed_description' => 'Synthetic receipt', 'quantity' => '2.000', 'unit' => 'M', 'heat_ref' => 'HEAT-QA', 'location_kind' => 'QUARANTINE', 'location_ref' => 'Bay Q', 'document_status' => 'MISSING', 'notes' => null];
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
        Storage::fake('local');

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

    private function traceUrl(bool $detail = false): string
    {
        return $detail ? str_replace('/qa?', '/traceability?', $this->endpoint()) : '/projects/PH-14/materials/piping-traceability?lang=en';
    }

    private function trace(?User $actor = null, bool $detail = true): array
    {
        return app(PipingTraceabilityService::class)->report($this->project, $actor ?? $this->buyer, $detail ? $this->receipt : null);
    }

    private function row(?User $actor = null): array
    {
        return $this->trace($actor)['records']->sole();
    }

    private function amend(?string $source = null, ?string $head = null): string
    {
        return app(PipingCustodyAmendmentService::class)->create($this->project, $this->buyer, $this->receipt, ['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'), 'previous_amendment_id' => $head, 'request_id' => (string) Str::uuid(), 'source_line_id' => $source, 'unlinked_reason' => $source === null ? 'Source unresolved' : null, 'heat_ref' => 'H-TRACE', 'reason' => 'Synthetic trace basis correction']);
    }

    private function mrFlow(bool $issue = true): array
    {
        $buyer = $this->buyer;
        app(MaterialRequisitionAccessService::class)->configure($this->project, $this->owner, ['version' => 0, 'selectors' => ['user:'.$buyer->id], 'suspend' => false, 'reason' => 'Synthetic trace MR']);
        $material = $this->scoped(fn () => MaterialItem::create(['project_id' => $this->project->id, 'discipline' => 'PIPING', 'category' => 'PIPE', 'commodity_code' => 'TRACE-SAME', 'description_en' => 'Synthetic trace material', 'description_fa' => 'متریال آزمایشی', 'base_unit' => 'M', 'source' => 'PROJECT_CATALOGUE', 'status' => 'ACTIVE']), $buyer);
        $scope = $this->scoped(fn () => DB::table('subsystems')->where('project_id', $this->project->id)->value('id'), $buyer);
        $mr = app(MaterialRequisitionEntryService::class)->create($this->project, $buyer, ['title_en' => 'Trace MR <script>mr-secret()</script>', 'title_fa' => 'درخواست ردیابی', 'discipline' => 'PIPING', 'material_category' => 'PIPE', 'lines' => [
            ['item_code' => 'TRACE-SAME', 'description_en' => 'Pipe A', 'description_fa' => 'لوله الف', 'unit' => 'M', 'quantity' => '5.000', 'material_item_id' => $material->id, 'subsystem_id' => $scope],
            ['item_code' => 'TRACE-SAME', 'description_en' => 'Pipe B', 'description_fa' => 'لوله ب', 'unit' => 'M', 'quantity' => '7.000'],
        ]]);
        $this->scoped(function () use (&$mr, $buyer) {
            $svc = app(ProcurementService::class);
            $svc->submitRequisition($mr, $buyer);
            $svc->approveTechnically($mr->fresh(), $this->qc);
            $mr = $svc->approveCommercially($mr->fresh(), $this->owner);
        }, $buyer);
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, ['version' => 0, 'selectors' => ['user:'.$buyer->id], 'suspend' => false, 'reason' => 'Trace PO draft']);
        $vendor = Company::where('code', 'VEN-AZAR')->firstOrFail()->id;
        $po = app(PurchaseOrderEntryService::class)->createFromRequisition($this->project, $buyer, ['requisition_id' => $mr->id, 'vendor_company_id' => $vendor, 'promised_date' => null]);
        $lines = $this->scoped(fn () => $po->items()->orderBy('line_no')->get(), $buyer);
        if ($issue) {
            $exec = app(PurchaseOrderExecutionService::class);
            $issuer = $this->userByEmail('sadeghi@pars.example');
            $pricing = $this->scoped(fn () => ['content_hash' => $exec->hash($po), 'currency' => 'USD', 'reason' => 'Synthetic prices', 'lines' => $lines->map(fn ($l) => ['id' => $l->id, 'unit_price' => '987.65'])->all()], $buyer);
            app(PurchaseOrderPricingService::class)->price($this->project, $buyer, $po->id, $pricing);
            app(PurchaseOrderExecutionAccessService::class)->configure($this->project, $this->owner, ['buyer_company_id' => $buyer->company_id, 'version' => 0, 'finance_selectors' => ['user:'.$this->qc->id], 'issue_selectors' => ['user:'.$issuer->id], 'suspend' => false, 'reason' => 'Trace issue nominees']);
            $hash = $this->scoped(fn () => $exec->hash($po), $buyer);
            $exec->act($this->project, $this->qc, $buyer->company_id, $po->id, 'finance', ['content_hash' => $hash, 'reason' => 'Trace finance']);
            $id = $this->scoped(fn () => DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->value('id'), $buyer);
            $exec->act($this->project, $issuer, $buyer->company_id, $po->id, 'issue', ['content_hash' => $hash, 'reason' => 'Trace issue', 'approval_id' => $id]);
        }

        return [$mr, $po, $lines, $material, $scope];
    }

    private function linkedArrival(string $line): string
    {
        $p = $this->arrival();
        $p['source_line_id'] = $line;
        $p['unlinked_reason'] = null;

        return app(PipingCustodyService::class)->create($this->project, $this->buyer, $p);
    }

    public function test_unlinked_arrival_reports_unknown_not_zero_or_automatic_mto_or_stock(): void
    {
        $r = $this->row();
        $this->assertSame('UNRESOLVED', $r['source_status']);
        $this->assertSame('NOT_ASSESSED', $r['mr_status']);
        $this->assertSame('NOT_CAPTURED', $r['mto_basis']);
        foreach (['effective_demand', 'posted_quantity', 'usable_quantity'] as $key) {
            $this->assertNull($r[$key]);
        }
        $report = $this->trace();
        $this->assertFalse($report['legacyStockAssessed']);
        $this->assertSame('CALLER_VISIBLE_RECEIPTS_ONLY', $report['coverage']);
        $this->assertSame('NOT_IMPLEMENTED', $report['controlledPosting']);
        $this->actingAs($this->buyer)->get($this->traceUrl())->assertOk()->assertSee('unknown, not zero')->assertDontSee('id="piping-custody-form"', false)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_exact_mr_po_line_and_material_ids_and_buyer_evidence_without_price_exposure(): void
    {
        [$mr,$po,$lines,$material,$scope] = $this->mrFlow();
        $this->receipt = $this->linkedArrival($lines[0]->id);
        $r = $this->row();
        $this->assertSame('LINKED', $r['source_status']);
        $this->assertSame('LINKED', $r['mr_status']);
        $this->assertSame($mr->id, $r['mr']['id']);
        $this->assertSame($lines[0]->requisition_item_id, $r['mr']['line_id']);
        $this->assertSame($material->id, $r['material']['id']);
        $this->assertSame($scope, $r['scope']['id']);
        $this->assertSame('RECORDED_LINE_EVIDENCE', $r['issue_evidence']);
        $this->assertSame('NOT_CAPTURED', $r['mto_basis']);
        $this->assertStringNotContainsString('987.65', json_encode($this->trace()['records']->items()));
        $this->actingAs($this->buyer)->get($this->traceUrl(true))->assertOk()->assertSee($mr->number)->assertSee($po->number)->assertDontSee('987.65')->assertDontSee('unit_price');
        $this->assertSame('VISIBILITY_LIMITED', $this->row($this->owner)['issue_evidence']);
    }

    public function test_duplicate_item_codes_do_not_guess_mr_or_share_source_line_identity(): void
    {
        [$mr,$po,$lines] = $this->mrFlow(false);
        $first = $this->receipt = $this->linkedArrival($lines[0]->id);
        $one = $this->row();
        $this->receipt = $this->linkedArrival($lines[1]->id);
        $two = $this->row();
        $this->assertNotSame($one['po']['line_id'], $two['po']['line_id']);
        $this->assertNotSame($one['mr']['line_id'], $two['mr']['line_id']);
        $this->assertSame('UNRESOLVED', $two['material']['status']);
        $this->assertSame('UNVERIFIED', $two['issue_evidence']);
        $this->assertSame('5.000', $this->scoped(fn () => DB::table('purchase_order_items')->where('id', $one['po']['line_id'])->value('quantity')));
    }

    public function test_hidden_latest_amended_source_never_falls_back_or_leaks_ancestry(): void
    {
        [$mr,$po,$lines] = $this->mrFlow(false);
        $first = $this->amend();
        $second = $this->amend($lines[0]->id, $first);
        $this->assertSame($lines[0]->id, $this->row($this->qc)['po']['line_id']);
        DB::table('companies')->where('id', $this->qc->company_id)->update(['tenant_type' => 'TPI']);
        $r = $this->row($this->qc);
        $this->assertSame('UNAVAILABLE', $r['basis']['status']);
        $this->assertSame($second, $r['basis']['amendment_id']);
        $this->assertNull($r['po']);
        $this->assertNull($r['mr']);
        $this->assertNull($r['material']['id']);
        $this->assertSame('UNAVAILABLE_BASIS', $r['qc']['binding']);
        $this->actingAs($this->qc)->get($this->traceUrl(true))->assertOk()->assertDontSee($po->number)->assertDontSee($mr->number)->assertDontSee($lines[0]->id)->assertDontSee('987.65')->assertSee('no fallback');
    }

    public function test_original_hidden_source_hides_receipt_instead_of_claiming_zero_demand(): void
    {
        [$mr,$po,$lines] = $this->mrFlow(false);
        $this->receipt = $this->linkedArrival($lines[0]->id);
        DB::table('companies')->where('id', $this->qc->company_id)->update(['tenant_type' => 'TPI']);
        $this->actingAs($this->qc)->get($this->traceUrl(true))->assertNotFound();
        $this->get($this->traceUrl())->assertOk()->assertDontSee($po->number)->assertSee('Hidden or unknown does not mean zero');
    }

    public function test_no_nomination_inheritance_and_live_suspension(): void
    {
        $issuer = $this->userByEmail('sadeghi@pars.example');
        $this->actingAs($issuer)->get($this->traceUrl())->assertForbidden();
        $this->configure(1, ['custody_mrr' => ['user:'.$this->buyer->id], 'receiving_qc' => ['user:'.$this->qc->id], 'acceptance_qa' => ['user:'.$this->qc->id], 'post_grn' => ['user:'.$this->buyer->id]]);
        $this->actingAs($this->owner)->get($this->traceUrl())->assertForbidden();
        app(PipingReceiptAccessService::class)->configure($this->project, $this->owner, ['version' => 2, 'suspend' => true, 'reason' => 'Trace read revocation']);
        $this->actingAs($this->buyer)->get($this->traceUrl())->assertForbidden();
    }

    public function test_latest_qc_and_qa_heads_not_pagination_or_extra_arrival_quantities(): void
    {
        $qa = $this->create($this->input());
        $r = $this->row();
        $this->assertSame('CURRENT', $r['qc']['binding']);
        $this->assertSame('CURRENT', $r['qa']['binding']);
        $this->assertSame('HOLD', $r['qa']['result']);
        $this->review = $this->qc($this->review);
        $this->assertSame('STALE', $this->row()['qa']['binding']);
        $a = $this->amend();
        $r = $this->row();
        $this->assertSame('STALE', $r['qc']['binding']);
        $this->assertSame('STALE', $r['qa']['binding']);
        $this->assertSame(1, $this->trace()['records']->total());
        $this->assertSame('2.000', $r['receipt']['quantity']);
        $this->assertNull($r['posted_quantity']);
    }

    public function test_reads_do_not_query_mto_cde_or_stock_or_mutate_any_domain_table(): void
    {
        $tables = ['piping_custody_records', 'piping_custody_amendments', 'piping_receiving_reviews', 'piping_qa_decisions', 'purchase_orders', 'material_requisitions', 'po_mr_allocations', 'accepted_mto_rows', 'material_lots', 'material_movements'];
        $snapshot = fn () => $this->scoped(function () use ($tables) {
            $out = [];
            foreach ($tables as $t) {
                $out[$t] = DB::table($t)->orderBy('id')->get()->toJson();
            }

            return $out;
        });
        $before = $snapshot();
        DB::enableQueryLog();
        $this->trace();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();
        $sql = strtolower(implode("\n", array_column($queries, 'query')));
        $this->assertCount(1, array_filter($queries, fn ($q) => str_contains($q['query'], ' AS observed_at')));
        $this->assertStringNotContainsString('epcc_piping_effective_receipt', $sql);
        foreach (['accepted_mto', 'mto_acceptance', 'mto_scope', 'document_files', 'document_revisions', 'material_lots', 'material_movements', 'insert into', 'update "', 'delete from'] as $token) {
            $this->assertStringNotContainsString($token, $sql);
        }
        $this->assertSame($before, $snapshot());
    }

    public function test_archive_bilingual_private_escaped_pages_and_no_write_route(): void
    {
        $p = $this->arrival();
        $p['shipment_ref'] = '<script>trace-xss()</script>';
        $this->receipt = app(PipingCustodyService::class)->create($this->project, $this->buyer, $p);
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Read-only trace archive');
        foreach (['en', 'fa'] as $lang) {
            $res = $this->actingAs($this->buyer)->get(str_replace('lang=en', 'lang='.$lang, $this->traceUrl(true)))->assertOk()->assertDontSee('<script>trace-xss()</script>', false)->assertSee('&lt;script&gt;trace-xss()', false)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            if (getenv('EPCC_TRACE_EVIDENCE') === '1') {
                file_put_contents(base_path('../docs/evidence/2026-10-23-piping-traceability/http-'.$lang.'.html'), preg_replace('/(<input[^>]*name="_token"[^>]*value=")[^"]*/', '$1REDACTED-CSRF', $res->getContent()));
            }
        }
        $this->postJson($this->traceUrl(), [])->assertStatus(405);
        $this->postJson($this->traceUrl(true), [])->assertStatus(405);
    }

    public function test_pagination_does_not_report_page_counts_as_project_coverage_or_sum_units(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $p = $this->arrival();
            $p['shipment_ref'] = 'PAGE-'.$i;
            $p['unit'] = $i % 2 === 0 ? 'EA' : 'M';
            app(PipingCustodyService::class)->create($this->project, $this->buyer, $p);
        }
        $r = $this->trace(null, false);
        $this->assertSame(26, $r['records']->total());
        $this->assertCount(25, $r['records']);
        $this->assertNull($r['effectiveDemand']);
        $this->assertNull($r['usableQuantity']);
        $this->actingAs($this->buyer)->get($this->traceUrl().'&page=2')->assertOk()->assertSee('SYNTHETIC-QA')->assertSee('not a complete project-material total');
    }

    public function test_unknown_target_uuid_and_wrong_project_are_not_disclosed(): void
    {
        $this->actingAs($this->buyer)->get(str_replace($this->receipt, (string) Str::uuid(), $this->traceUrl(true)))->assertNotFound();
        $this->get(str_replace($this->receipt, 'not-a-uuid', $this->traceUrl(true)))->assertNotFound();
        $other = Project::where('code', '!=', 'PH-14')->first();
        $this->assertNotNull($other);
        $this->get(str_replace('PH-14', $other->code, $this->traceUrl(true)))->assertStatus(403);
    }

    public function test_po_visibility_does_not_unlock_independent_hidden_mr(): void
    {
        [$mr,$po,$lines] = $this->mrFlow(false);
        $this->scoped(fn () => DB::table('purchase_orders')->where('id', $po->id)->update(['vendor_company_id' => $this->qc->company_id]), $this->buyer);
        $this->receipt = $this->linkedArrival($lines[0]->id);
        DB::table('companies')->where('id', $this->qc->company_id)->update(['tenant_type' => 'VEN']);
        $r = $this->row($this->qc);
        $this->assertSame($po->id, $r['po']['id']);
        $this->assertSame('UNAVAILABLE_OR_UNRESOLVED', $r['mr_status']);
        $this->assertNull($r['mr']);
        $this->assertSame('VISIBILITY_LIMITED', $r['issue_evidence']);
        $this->actingAs($this->qc)->get($this->traceUrl(true))->assertOk()->assertSee($po->number)->assertDontSee($mr->number)->assertDontSee($lines[0]->requisition_item_id);
    }

    public function test_legacy_incomplete_conflicting_and_changed_refs_are_not_normalized_to_complete(): void
    {
        [$mr,$source,$lines] = $this->mrFlow(false);
        $mr2 = app(MaterialRequisitionEntryService::class)->create($this->project, $this->buyer, ['title_en' => 'Other MR', 'title_fa' => 'دیگر', 'discipline' => 'PIPING', 'material_category' => 'PIPE', 'lines' => [['item_code' => 'TRACE-SAME', 'description_en' => 'Other', 'description_fa' => 'دیگر', 'unit' => 'M', 'quantity' => '1']]]);
        foreach ([[$mr->id, null, 'HEADER_ONLY'], [null, $lines[0]->requisition_item_id, 'LINE_ONLY'], [$mr2->id, $lines[0]->requisition_item_id, 'REFERENCE_CONFLICT'], [$mr->id, $lines[0]->requisition_item_id, 'LINKED'], [null, null, 'NOT_LINKED']] as $i => [$header,$line,$status]) {
            // Trusted legacy fixtures only, NOT a new application writer or permission bypass.
            $id = $this->scoped(function () use ($source, $header, $line, $i) {
                $p = new PurchaseOrder;
                $p->forceFill(['project_id' => $this->project->id, 'company_id' => $this->buyer->company_id, 'vendor_company_id' => $source->vendor_company_id, 'number' => 'TRACE-LEGACY-'.$i, 'sequence' => 100 + $i, 'state' => 'DRAFT', 'requisition_id' => $header, 'discipline' => 'PIPING', 'material_category' => 'PIPE', 'title_en' => 'Legacy fixture', 'title_fa' => 'آزمایشی'])->save();

                return PurchaseOrderItem::create(['project_id' => $this->project->id, 'purchase_order_id' => $p->id, 'line_no' => 1, 'item_code' => 'TRACE-SAME', 'description_en' => 'Legacy fixture', 'description_fa' => 'آزمایشی', 'unit' => 'M', 'quantity' => '999', 'requisition_item_id' => $line])->id;
            }, $this->buyer);
            $this->receipt = $this->linkedArrival($id);
            $r = $this->row();
            $this->assertSame($status, $r['mr_status']);
            if ($status === 'LINKED') {
                $this->assertSame('DIFFERENCES', $r['visible_field_alignment']);
                $this->scoped(fn () => DB::table('purchase_order_items')->where('id', $id)->update(['quantity' => '998']));
                $this->assertSame('CHANGED', $this->row()['source_status']);
            }
            if (in_array($status, ['REFERENCE_CONFLICT', 'NOT_LINKED'], true)) {
                $this->assertNull($r['mr']);
            }
        }
    }
}
