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
use App\Services\PipingCustodyService;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PipingCustodyAmendmentTest extends TestCase
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
        return ['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'), 'request_id' => (string) Str::uuid(), 'source_line_id' => null, 'unlinked_reason' => 'Source pending', 'shipment_ref' => 'SYNTHETIC-QA', 'package_ref' => null, 'received_at' => '2026-10-23T08:00:00+03:30', 'observed_description' => 'Synthetic receipt', 'quantity' => '2.000', 'unit' => 'M', 'heat_ref' => null, 'location_kind' => 'QUARANTINE', 'location_ref' => 'Bay Q', 'document_status' => 'MISSING', 'notes' => null];
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

    private function amendInput(array $changes = []): array
    {
        return array_replace(['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'), 'previous_amendment_id' => null, 'request_id' => (string) Str::uuid(), 'source_line_id' => null, 'unlinked_reason' => 'Source still being reconciled', 'heat_ref' => 'HEAT-CORRECTED', 'reason' => 'Synthetic correction <script>amend-unsafe()</script>'], $changes);
    }

    private function amendment(array $data): string
    {
        return app(PipingCustodyAmendmentService::class)->create($this->project, $this->buyer, $this->receipt, $data);
    }

    private function amendUrl(): string
    {
        return str_replace('/qa?', '/amendments?', $this->endpoint());
    }

    private function amendRows()
    {
        return $this->scoped(fn () => DB::table('piping_custody_amendments')->where('custody_id', $this->receipt)->orderBy('sequence')->get(), $this->buyer);
    }

    private function source(): string
    {
        $receipt = $this->receipt;
        $review = $this->review;
        $this->issuedSource();
        $line = $this->scoped(fn () => DB::table('piping_custody_records')->where('id', $this->receipt)->value('source_line_id'));
        $this->receipt = $receipt;
        $this->review = $review;

        return $line;
    }

    private function freshQc(?string $amendment): string
    {
        return app(PipingReceivingReviewService::class)->create($this->project, $this->qc, $this->receipt, ['amendment_id' => $amendment, 'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'receiving_qc'), 'previous_review_id' => $this->review, 'request_id' => (string) Str::uuid(), 'reviewed_at' => '2026-10-23T12:00:00+03:30', 'observed_result' => 'NO_NONCONFORMITY_OBSERVED', 'findings' => 'Fresh review on exact corrected source and heat']);
    }

    public function test_only_actual_original_preparer_with_current_mrr_grant_not_replacement_nominees_can_amend(): void
    {
        foreach ([$this->owner, $this->qc] as $actor) {
            $this->actingAs($actor)->postJson($this->amendUrl(), ['amendment' => $this->amendInput()])->assertForbidden();
        }
        $this->configure(1, ['custody_mrr' => ['user:'.$this->buyer->id, 'user:'.$this->qc->id], 'receiving_qc' => ['user:'.$this->qc->id], 'acceptance_qa' => ['user:'.$this->owner->id], 'post_grn' => ['user:'.$this->buyer->id]]);
        $p = $this->amendInput();
        $p['expected_policy_id'] = app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'custody_mrr');
        $this->actingAs($this->qc)->postJson($this->amendUrl(), ['amendment' => $p])->assertForbidden();
        $this->assertCount(0, $this->amendRows());
        $this->amendment($this->amendInput());
        $this->assertCount(1, $this->amendRows());
    }

    public function test_immutable_arrival_and_no_stock_side_effects_retry_and_stale_head(): void
    {
        $snapshot = fn () => $this->scoped(fn () => [DB::table('piping_custody_records')->orderBy('id')->get()->toJson(), DB::table('material_lots')->orderBy('id')->get()->toJson(), DB::table('material_movements')->orderBy('id')->get()->toJson(), DB::table('purchase_orders')->orderBy('id')->get()->toJson()]);
        $before = $snapshot();
        $p = $this->amendInput();
        $id = $this->amendment($p);
        $this->assertSame($id, $this->amendment($p));
        $this->assertSame($before, $snapshot());
        $this->assertSame('HEAT-CORRECTED', app(PipingCustodyService::class)->basis($this->project, $this->buyer, $this->receipt)['heat_ref']);
        $this->actingAs($this->buyer)->postJson($this->amendUrl(), ['amendment' => $this->amendInput()])->assertUnprocessable();
        $p['reason'] = 'Changed same key';
        $this->postJson($this->amendUrl(), ['amendment' => $p])->assertUnprocessable();
        $this->amendment($this->amendInput(['previous_amendment_id' => $id, 'heat_ref' => null]));
        $this->assertCount(2, $this->amendRows());
    }

    public function test_correction_requires_fresh_exact_qc_then_qa_and_preserves_three_actor_chain(): void
    {
        $line = $this->source();
        $oldQa = $this->input();
        $oldId = $this->create($oldQa);
        $f = $this->file();
        $amendment = $this->amendment($this->amendInput(['source_line_id' => $line, 'unlinked_reason' => null]));
        $this->actingAs($this->owner)->get($this->endpoint())->assertOk()->assertSee('QC has changed')->assertDontSee('piping-qa-form');
        $this->assertSame($oldId, $this->create($oldQa)); // historical receipt, not reactivation
        $this->postJson($this->endpoint(), ['qa' => $this->input(['previous_decision_id' => $oldId])])->assertUnprocessable();
        try {
            $this->freshQc(null);
            $this->fail('Old basis must fail');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('review', $e->errors());
        }
        $this->review = $this->freshQc($amendment);
        $data = $this->input(['amendment_id' => $amendment, 'previous_decision_id' => $oldId, 'decision' => 'ACCEPTED', 'open_conditions' => null, 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true), 'requirements_file_ids' => [$f], 'evidence_file_ids' => [$f]]);
        $accepted = $this->create($data);
        $row = $this->rows()->last();
        $this->assertSame($amendment, $row->amendment_id);
        $this->assertSame('ACCEPTED', $row->decision);
        $this->assertNull($row->source_basis);
        $private = $this->scoped(fn () => DB::table('piping_qa_amendment_source_bases')->where('decision_id', $accepted)->first());
        $this->assertSame($line, $private->source_line_id);
        $this->assertSame($this->owner->id, $row->decided_by);
        $this->assertSame($this->buyer->id, $this->amendRows()->sole()->created_by);
        $next = $this->amendment($this->amendInput(['previous_amendment_id' => $amendment, 'source_line_id' => $line, 'unlinked_reason' => null, 'heat_ref' => 'HEAT-NEW']));
        $this->assertNotSame($amendment, $next);
        $this->actingAs($this->owner)->get($this->endpoint())->assertOk()->assertSee('QC has changed')->assertDontSee('piping-qa-form');
        $this->assertSame($accepted, $this->create($data));
        $this->assertCount(2, $this->rows());
    }

    public function test_original_so_d_still_blocks_preparer_qc_and_qc_reviewer_qa_after_correction(): void
    {
        $a = $this->amendment($this->amendInput());
        $this->configure(1, ['custody_mrr' => ['user:'.$this->buyer->id], 'receiving_qc' => ['user:'.$this->buyer->id, 'user:'.$this->qc->id], 'acceptance_qa' => ['user:'.$this->qc->id, 'user:'.$this->owner->id], 'post_grn' => ['user:'.$this->buyer->id]]);
        $p = ['amendment_id' => $a, 'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'receiving_qc'), 'previous_review_id' => $this->review, 'request_id' => (string) Str::uuid(), 'reviewed_at' => '2026-10-23T12:00:00+03:30', 'observed_result' => 'INCOMPLETE', 'findings' => 'Not self review'];
        $this->actingAs($this->buyer)->postJson(str_replace('/qa?', '/receiving-qc?', $this->endpoint()), ['review' => $p])->assertUnprocessable();
        $this->review = $this->freshQc($a);
        $qa = $this->input(['amendment_id' => $a]);
        $qa['expected_policy_id'] = app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'acceptance_qa');
        $this->actingAs($this->qc)->postJson($this->endpoint(), ['qa' => $qa])->assertUnprocessable();
    }

    public function test_forbidden_arrival_fields_unknown_source_wrong_unit_and_blank_reason(): void
    {
        $this->actingAs($this->buyer);
        foreach (['quantity', 'unit', 'received_at', 'created_by', 'company_id', 'source_snapshot', 'custody_fingerprint', 'sequence'] as $key) {
            $this->postJson($this->amendUrl(), ['amendment' => $this->amendInput([$key => 'forged'])])->assertUnprocessable();
        }
        $this->postJson($this->amendUrl(), ['amendment' => $this->amendInput(['source_line_id' => (string) Str::uuid(), 'unlinked_reason' => null])])->assertUnprocessable();
        $line = $this->source();
        // Construct a second legitimate, independently visible source using another unit.
        $po = app(PurchaseOrderEntryService::class)->create($this->project, $this->buyer, ['title_en' => 'Different unit', 'title_fa' => 'واحد متفاوت', 'discipline' => 'PIPING', 'material_category' => 'PIPE', 'vendor_company_id' => Company::where('code', 'VEN-AZAR')->firstOrFail()->id, 'lines' => [['item_code' => 'EACH', 'description_en' => 'Item', 'description_fa' => 'قلم', 'unit' => 'EA', 'quantity' => '2.000']]]);
        $bad = $this->scoped(fn () => DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->value('id'), $this->buyer);
        $this->postJson($this->amendUrl(), ['amendment' => $this->amendInput(['source_line_id' => $bad, 'unlinked_reason' => null])])->assertUnprocessable();
        $this->postJson($this->amendUrl(), ['amendment' => $this->amendInput(['reason' => '   '])])->assertUnprocessable();
        $this->assertCount(0, $this->amendRows());
    }

    public function test_archive_and_revoked_mrr_grant_block_exact_retry(): void
    {
        $p = $this->amendInput();
        $id = $this->amendment($p);
        $this->configure(1, ['custody_mrr' => ['user:'.$this->qc->id], 'receiving_qc' => ['user:'.$this->qc->id], 'acceptance_qa' => ['user:'.$this->owner->id], 'post_grn' => ['user:'.$this->buyer->id]]);
        $this->actingAs($this->buyer)->postJson($this->amendUrl(), ['amendment' => $p])->assertForbidden();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Synthetic archive');
        $this->get($this->amendUrl())->assertOk()->assertDontSee('piping-custody-amendments-form');
        $this->assertSame($id, $this->amendRows()->sole()->id);
    }

    public function test_raw_sql_missing_source_cannot_commit_and_late_child_cannot_rewrite_history(): void
    {
        $line = $this->source();
        $p = $this->amendInput();
        $raw = ['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'custody_id' => $this->receipt, 'created_by' => $this->buyer->id, 'company_id' => $this->buyer->company_id, 'policy_id' => $p['expected_policy_id'], 'previous_amendment_id' => null, 'request_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'source_kind' => 'LINKED', 'unlinked_reason' => null, 'heat_ref' => 'H', 'reason' => 'Missing child'];
        try {
            $this->scoped(function () use ($raw) {
                DB::table('piping_custody_amendments')->insert($raw);
                DB::statement('SET CONSTRAINTS piping_amendment_complete IMMEDIATE');
            }, $this->buyer);
            $this->fail('Incomplete linked amendment must fail');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_AMENDMENT_INCOMPLETE', $e->getMessage());
        }
        $this->assertCount(0, $this->amendRows());
        $id = $this->amendment($this->amendInput());
        $this->scoped(function () use ($id) {
            $this->assertSame(0, DB::table('piping_custody_amendments')->where('id', $id)->update(['heat_ref' => 'rewrite']));
            $this->assertSame(0, DB::table('piping_custody_amendments')->where('id', $id)->delete());
        }, $this->buyer);
        try {
            $this->scoped(fn () => DB::table('piping_custody_amendment_sources')->insert(['amendment_id' => $id, 'project_id' => $this->project->id, 'source_line_id' => $line]), $this->buyer);
            $this->fail('Late child must fail');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_AMENDMENT_SOURCE', $e->getMessage());
        }
    }

    public function test_bilingual_private_ui_escaped_history_and_scoped_empty_basis_restoration(): void
    {
        $id = $this->amendment($this->amendInput());
        foreach (['en', 'fa'] as $lang) {
            $res = $this->actingAs($this->buyer)->get(str_replace('lang=en', 'lang='.$lang, $this->amendUrl()))->assertOk()->assertSee('piping-custody-amendments-form')->assertDontSee('<script>amend-unsafe()</script>', false)->assertSee('&lt;script&gt;amend-unsafe()', false)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
            if (getenv('EPCC_AMENDMENT_EVIDENCE') === '1') {
                file_put_contents(base_path('../docs/evidence/2026-10-23-piping-custody-amendments/http-'.$lang.'.html'), preg_replace('/(<input[^>]*name="_token"[^>]*value=")[^"]*/', '$1REDACTED-CSRF', $res->getContent()));
            }
        }
        $old = ['amendment_project' => $this->project->id, 'amendment_custody' => $this->receipt, 'amendment' => ['previous_amendment_id' => null, 'heat_ref' => null, 'reason' => 'Failed draft']];
        $this->withSession(['_old_input' => $old])->get($this->amendUrl())->assertOk()->assertSee('name="amendment[previous_amendment_id]" value=""', false)->assertSee('name="amendment[heat_ref]" value=""', false);
        $this->actingAs($this->qc)->withSession(['_old_input' => ['receiving_qc_project' => $this->project->id, 'receiving_qc_custody' => $this->receipt, 'review' => ['amendment_id' => null]]])->get(str_replace('/qa?', '/receiving-qc?', $this->endpoint()))->assertOk()->assertSee('name="review[amendment_id]" value=""', false);
    }

    public function test_real_duplicate_and_stale_amendment_waits_and_revocation(): void
    {
        $p = $this->amendInput();
        $result = $this->blockedWorkers($this->receipt, $p, 2, fn () => null);
        $this->assertSame($result[0]['id'], $result[1]['id']);
        $this->assertCount(1, $this->amendRows());
        $p = $this->amendInput(['previous_amendment_id' => $result[0]['id']]);
        $result = $this->blockedWorkers($this->receipt, $p, 1, fn () => $this->amendment($this->amendInput(['previous_amendment_id' => $p['previous_amendment_id']])));
        $this->assertSame(422, $result[0]['status']);
        $p = $this->amendInput(['previous_amendment_id' => $this->amendRows()->last()->id]);
        $result = $this->blockedWorkers($this->receipt, $p, 1, fn () => $this->configure(1, ['custody_mrr' => ['user:'.$this->qc->id], 'receiving_qc' => ['user:'.$this->qc->id], 'acceptance_qa' => ['user:'.$this->owner->id], 'post_grn' => ['user:'.$this->buyer->id]]));
        $this->assertSame(403, $result[0]['status']);
    }

    /** Real worker connections observed blocked on the shared advisory domain. */
    private function blockedWorkers(string $custodyId, array $payload, int $count, callable $whileBlocked, string $operation = 'amend'): array
    {
        $script = tempnam(sys_get_temp_dir(), 'epcc-custody-worker-');
        $appPath = base_path();
        file_put_contents($script, '<?php require '.var_export($appPath.'/vendor/autoload.php', true).'; $app=require '.var_export($appPath.'/bootstrap/app.php', true).'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '.<<<'WORKER'
if(config('database.connections.pgsql.database')!=='epcc_test' || !str_starts_with((string)config('database.connections.pgsql.host'),'/tmp/epcc-')) { throw new RuntimeException('Refusing non-isolated custody worker'); }
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name',?,false)",[$argv[4]]);
$project=App\Models\Project::whereKey($argv[1])->firstOrFail();
$actor=app(App\Support\TenantContext::class)->forIdentityLookup(fn()=>App\Models\User::whereKey($argv[2])->firstOrFail());
try {
 $service=match($argv[6]){'qa'=>App\Services\PipingQaDecisionService::class,'qc'=>App\Services\PipingReceivingReviewService::class,default=>App\Services\PipingCustodyAmendmentService::class};
 $id=app($service)->create($project,$actor,$argv[5],json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR));
 echo json_encode(['id'=>$id]);
} catch (Illuminate\Validation\ValidationException $e) { echo json_encode(['status'=>422]);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
 echo json_encode(['status'=>$e->getStatusCode()]);
} catch (Throwable $e) { fwrite(STDERR,(string)$e); exit(1); }
WORKER);
        $workers = [];
        $prefix = 'custody-'.Str::random(10);
        $workerActor = match ($operation) {
            'qa' => $this->owner,'qc' => $this->qc,default => $this->buyer
        };
        try {
            DB::beginTransaction();
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['piping-receipt-access:'.$this->project->id]);

            for ($i = 0; $i < $count; $i++) {
                $p = new Process([PHP_BINARY, $script, $this->project->id, $workerActor->id, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $prefix.'-'.$i, $custodyId, $operation], $appPath, ['APP_ENV' => 'testing', 'APP_BASE_PATH' => $appPath, 'DB_DATABASE' => 'epcc_test', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'), 'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password')]);
                $p->setTimeout(20);
                $p->start();
                $workers[] = $p;
            }
            $deadline = microtime(true) + 10;
            do {
                $blocked = (int) DB::selectOne("WITH k AS (SELECT hashtextextended(?,0) v) SELECT count(*) AS n FROM pg_locks,k WHERE locktype='advisory' AND NOT granted AND classid::bigint=((v >> 32) & 4294967295) AND objid::bigint=(v & 4294967295) AND objsubid=1", ['piping-receipt-access:'.$this->project->id])->n;
                if ($blocked === $count) {
                    break;
                }
                usleep(25000);
            } while (microtime(true) < $deadline);
            $this->assertSame($count, $blocked, 'Real worker connections must be observed waiting before release: '.implode(' | ', array_map(fn ($p) => $p->getOutput().$p->getErrorOutput(), $workers)));
            $whileBlocked();
            DB::commit();
            $result = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $result[] = json_decode($worker->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return $result;
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(0);
                }
            }
            unlink($script);
        }
    }

    public function test_hidden_latest_source_never_falls_back_and_qa_snapshot_keeps_original_source_rls(): void
    {
        $line = $this->source();
        $first = $this->amendment($this->amendInput());
        $second = $this->amendment($this->amendInput(['previous_amendment_id' => $first, 'source_line_id' => $line, 'unlinked_reason' => null]));
        $this->review = $this->freshQc($second);
        $f = $this->file();
        $accepted = $this->create($this->input(['amendment_id' => $second, 'decision' => 'ACCEPTED', 'open_conditions' => null, 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true), 'requirements_file_ids' => [$f], 'evidence_file_ids' => [$f]]));
        $private = $this->scoped(fn () => DB::table('piping_qa_amendment_source_bases')->where('decision_id', $accepted)->first());
        $number = json_decode($private->snapshot, true)['number'];
        DB::table('companies')->where('id', $this->qc->company_id)->update(['tenant_type' => 'TPI']);
        $this->assertNotNull(app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'receiving_qc'));
        $this->assertNull(app(PipingCustodyService::class)->basis($this->project, $this->qc, $this->receipt));
        $this->assertSame(0, $this->scoped(fn () => DB::table('piping_custody_amendment_sources')->count(), $this->qc));
        $this->assertSame(0, $this->scoped(fn () => DB::table('piping_qa_amendment_source_bases')->count(), $this->qc));
        $this->assertNull($this->scoped(fn () => DB::table('piping_qa_decisions')->where('id', $accepted)->value('source_basis'), $this->qc));
        $this->actingAs($this->qc)->get(str_replace('/qa?', '/receiving-qc?', $this->endpoint()))->assertOk()->assertDontSee($number)->assertDontSee($line)->assertDontSee('piping-receiving-qc-form')->assertSee('No fallback to an older source');
        $this->get($this->endpoint())->assertOk()->assertDontSee($number)->assertDontSee($line);
        $this->get($this->amendUrl())->assertOk()->assertDontSee($number)->assertDontSee($line)->assertSee('Source details are hidden');
        try {
            $this->freshQc($second);
            $this->fail('Cannot review an invisible corrected source');
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_amendment_during_real_qc_and_qa_waits_invalidates_their_old_basis(): void
    {
        $qc = ['amendment_id' => null, 'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'receiving_qc'), 'previous_review_id' => $this->review, 'request_id' => (string) Str::uuid(), 'reviewed_at' => '2026-10-23T12:00:00+03:30', 'observed_result' => 'INCOMPLETE', 'findings' => 'Waiting on original basis'];
        $result = $this->blockedWorkers($this->receipt, $qc, 1, fn () => $this->amendment($this->amendInput()), 'qc');
        $this->assertSame(422, $result[0]['status']);
        $head = $this->amendRows()->sole()->id;
        $this->review = $this->freshQc($head);
        $qa = $this->input(['amendment_id' => $head]);
        $result = $this->blockedWorkers($this->receipt, $qa, 1, fn () => $this->amendment($this->amendInput(['previous_amendment_id' => $head, 'heat_ref' => 'NEW-WHILE-QA-WAITS'])), 'qa');
        $this->assertSame(422, $result[0]['status']);
        $this->assertCount(0, $this->rows());
    }

    public function test_raw_qc_and_qa_cannot_submit_the_old_basis_and_late_amendment_failure_is_atomic(): void
    {
        $oldQa = $this->create($this->input());
        $line = $this->source();
        DB::unprepared("CREATE OR REPLACE FUNCTION amend_test_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'AMEND_TEST_LATE'; END $$; CREATE TRIGGER zz_amend_test_fail AFTER INSERT ON piping_custody_amendment_sources FOR EACH ROW EXECUTE FUNCTION amend_test_fail();");
        try {
            $this->amendment($this->amendInput(['source_line_id' => $line, 'unlinked_reason' => null]));
            $this->fail('Late failure expected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('AMEND_TEST_LATE', $e->getMessage());
        } finally {
            DB::statement('DROP TRIGGER zz_amend_test_fail ON piping_custody_amendment_sources');
            DB::statement('DROP FUNCTION amend_test_fail()');
        }
        $this->assertCount(0, $this->amendRows());
        $a = $this->amendment($this->amendInput());
        $this->assertSame(1, $this->amendRows()->sole()->sequence);
        $rawQc = (array) $this->scoped(fn () => DB::table('piping_receiving_reviews')->where('id', $this->review)->first());
        $rawQc['id'] = (string) Str::uuid();
        $rawQc['request_id'] = (string) Str::uuid();
        $rawQc['previous_review_id'] = $this->review;
        try {
            $this->scoped(fn () => DB::table('piping_receiving_reviews')->insert($rawQc), $this->qc);
            $this->fail('Old QC basis must fail in SQL');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_AMENDMENT_STALE', $e->getMessage());
        }
        $rawQa = (array) $this->rows()->sole();
        $rawQa['id'] = (string) Str::uuid();
        $rawQa['request_id'] = (string) Str::uuid();
        $rawQa['previous_decision_id'] = $oldQa;
        try {
            $this->scoped(fn () => DB::table('piping_qa_decisions')->insert($rawQa));
            $this->fail('Old QA basis must fail in SQL');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_AMENDMENT_STALE', $e->getMessage());
        }
    }

    public function test_forward_install_on_populated_archived_receipts_qc_qa_has_no_backfill(): void
    {
        $this->create($this->input());
        $snapshot = fn () => $this->scoped(fn () => [DB::table('piping_custody_records')->orderBy('id')->get()->toJson(), DB::table('piping_receiving_reviews')->orderBy('id')->get()->toJson(), DB::table('piping_qa_decisions')->orderBy('id')->get()->toJson(), DB::table('material_lots')->orderBy('id')->get()->toJson(), DB::table('material_movements')->orderBy('id')->get()->toJson()]);
        $before = $snapshot();
        DB::statement('DROP TRIGGER piping_qa_capture_amended_source ON piping_qa_decisions');
        DB::statement('DROP TABLE piping_qa_amendment_source_bases CASCADE');
        DB::statement('DROP TABLE piping_custody_amendment_sources CASCADE');
        DB::statement('DROP TABLE piping_custody_amendments CASCADE');
        Schema::table('piping_receiving_reviews', fn ($t) => $t->dropColumn('amendment_id'));
        Schema::table('piping_qa_decisions', fn ($t) => $t->dropColumn('amendment_id'));
        DB::table('migrations')->where('migration', '2026_10_23_110000_add_piping_custody_amendments')->delete();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Synthetic forward install');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, $snapshot());
        $this->assertCount(0, $this->amendRows());
        $this->actingAs($this->buyer)->get($this->amendUrl())->assertOk()->assertDontSee('piping-custody-amendments-form');
    }

    public function test_sql_cannot_delegate_preparer_and_server_stamps_supplied_metadata(): void
    {
        $this->configure(1, ['custody_mrr' => ['user:'.$this->buyer->id, 'user:'.$this->qc->id], 'receiving_qc' => ['user:'.$this->qc->id], 'acceptance_qa' => ['user:'.$this->owner->id], 'post_grn' => ['user:'.$this->buyer->id]]);
        $p = $this->amendInput();
        $id = $this->amendment($p);
        $row = (array) $this->amendRows()->sole();
        $row['id'] = (string) Str::uuid();
        $row['request_id'] = (string) Str::uuid();
        $row['previous_amendment_id'] = $id;
        $row['sequence'] = 999;
        $row['actor_name'] = 'FORGED';
        $row['custody_fingerprint'] = str_repeat('0', 64);
        $row['created_at'] = '1990-01-01T00:00:00+00:00';
        $this->scoped(fn () => DB::table('piping_custody_amendments')->insert($row), $this->buyer);
        $stored = $this->amendRows()->last();
        $this->assertSame(2, $stored->sequence);
        $this->assertSame($this->buyer->name, $stored->actor_name);
        $this->assertNotSame(str_repeat('0', 64), $stored->custody_fingerprint);
        $this->assertStringNotContainsString('1990', $stored->created_at);
        $row['id'] = (string) Str::uuid();
        $row['request_id'] = (string) Str::uuid();
        $row['previous_amendment_id'] = $stored->id;
        $row['created_by'] = $this->qc->id;
        $row['company_id'] = $this->qc->company_id;
        $row['policy_id'] = app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'custody_mrr');
        try {
            $this->scoped(fn () => DB::table('piping_custody_amendments')->insert($row), $this->qc);
            $this->fail('Raw SQL cannot delegate the preparer');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_AMENDMENT_PREPARER', $e->getMessage());
        }
    }
}
