<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Models\DocumentRevision;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
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
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PipingQaDecisionTest extends TestCase
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

    public function test_hold_history_exact_retry_conflict_stale_head_and_localized_escaped_ui(): void
    {
        $p = $this->input();
        $id = $this->create($p);
        $this->assertSame($id, $this->create($p));
        $this->assertCount(1, $this->rows());
        $this->actingAs($this->owner)->get($this->endpoint())->assertOk()->assertSee('piping-qa-form')->assertSee('&lt;script&gt;unsafe-qa()', false)->assertDontSee('<script>unsafe-qa()</script>', false)->assertHeader('Cache-Control', 'max-age=0, no-store, private');
        $this->get(str_replace('lang=en', 'lang=fa', $this->endpoint()))->assertOk()->assertSee('تصمیم نهایی QA');
        $this->postJson($this->endpoint(), ['qa' => array_replace($p, ['reason' => 'Different'])])->assertUnprocessable();
        $this->postJson($this->endpoint(), ['qa' => $this->input()])->assertUnprocessable();
        $this->create($this->input(['previous_decision_id' => $id, 'decision' => 'REJECTED']));
        $this->assertCount(2, $this->rows());
    }

    public function test_no_inheritance_and_both_actual_actors_are_blocked_even_if_nominated(): void
    {
        foreach ([$this->buyer, $this->qc] as $actor) {
            $this->actingAs($actor)->postJson($this->endpoint(), ['qa' => $this->input()])->assertForbidden();
        }
        $this->configure(1, ['custody_mrr' => ['user:'.$this->buyer->id], 'receiving_qc' => ['user:'.$this->qc->id], 'acceptance_qa' => ['user:'.$this->buyer->id, 'user:'.$this->qc->id], 'post_grn' => ['user:'.$this->buyer->id]]);
        foreach ([$this->buyer, $this->qc] as $actor) {
            $p = $this->input();
            $p['expected_policy_id'] = app(PipingCustodyService::class)->policyId($this->project, $actor, 'acceptance_qa');
            $this->actingAs($actor)->postJson($this->endpoint(), ['qa' => $p])->assertUnprocessable();
        }
        $this->assertCount(0, $this->rows());
    }

    public function test_new_qc_invalidates_form_and_marks_old_qa_not_current_but_retry_is_historical(): void
    {
        $p = $this->input();
        $id = $this->create($p);
        $this->review = $this->qc($this->review);
        $this->actingAs($this->owner)->get($this->endpoint())->assertOk()->assertSee('QC has changed');
        $this->assertSame($id, $this->create($p));
        $this->actingAs($this->owner)->postJson($this->endpoint(), ['qa' => array_replace($p, ['request_id' => (string) Str::uuid(), 'previous_decision_id' => $id])])->assertUnprocessable();
    }

    public function test_acceptance_requires_every_attestation_no_conditions_and_both_citation_groups(): void
    {
        $this->issuedSource();
        $f = $this->file();
        $valid = $this->input(['decision' => 'ACCEPTED', 'open_conditions' => null, 'reason' => 'All applicable requirements manually verified', 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true), 'requirements_file_ids' => [$f], 'evidence_file_ids' => [$f]]);
        $this->actingAs($this->owner);
        foreach (PipingQaDecisionService::DECLARATIONS as $key) {
            $p = $valid;
            $p['declarations'][$key] = false;
            $this->postJson($this->endpoint(), ['qa' => $p])->assertUnprocessable();
        }
        foreach (['requirements_file_ids', 'evidence_file_ids'] as $key) {
            $p = $valid;
            $p[$key] = [];
            $this->postJson($this->endpoint(), ['qa' => $p])->assertUnprocessable();
        }
        $p = $valid;
        $p['open_conditions'] = 'Resolve later';
        $this->postJson($this->endpoint(), ['qa' => $p])->assertUnprocessable();
        $p = $valid;
        $p['decision'] = 'ACCEPTED_WITH_COMMENT';
        $this->postJson($this->endpoint(), ['qa' => $p])->assertUnprocessable();
        $id = $this->create($valid);
        $this->assertSame('ACCEPTED', $this->rows()->last()->decision);
        $this->assertSame($id, $this->create($valid));
        $this->assertSame(2, $this->scoped(fn () => DB::table('piping_qa_evidence')->where('decision_id', $id)->count()));
        $this->assertSame('QUARANTINE', $this->scoped(fn () => DB::table('piping_custody_records')->where('id', $this->receipt)->value('location_kind')));
        $this->assertSame('ISSUED', $this->scoped(fn () => DB::table('purchase_orders')->where('id', $this->rows()->last()->source_basis ? json_decode($this->rows()->last()->source_basis, true)['purchase_order_id'] : null)->value('state')));
    }

    public function test_incomplete_qc_unlinked_source_and_unknown_heat_cannot_be_released(): void
    {
        $f = $this->file();
        $p = $this->input(['decision' => 'ACCEPTED', 'open_conditions' => null, 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true), 'requirements_file_ids' => [$f], 'evidence_file_ids' => [$f]]);
        $this->actingAs($this->owner)->postJson($this->endpoint(), ['qa' => $p])->assertUnprocessable();
        $this->review = $this->qc($this->review, 'NO_NONCONFORMITY_OBSERVED');
        $p['review_id'] = $this->review;
        $this->postJson($this->endpoint(), ['qa' => $p])->assertUnprocessable();
        $this->assertCount(0, $this->rows());
    }

    public function test_unfrozen_unknown_and_corrupt_files_refused_atomically(): void
    {
        foreach (['WIP', 'SHARED'] as $state) {
            $f = $this->file($state);
            $this->actingAs($this->owner)->postJson($this->endpoint(), ['qa' => $this->input(['evidence_file_ids' => [$f]])])->assertUnprocessable();
        }
        $f = $this->file();
        $this->scoped(function () use ($f) {
            $file = DocumentFile::findOrFail($f);
            Storage::disk($file->disk)->put($file->storage_path, 'corrupt');
        });
        foreach ([$f, (string) Str::uuid()] as $id) {
            $this->actingAs($this->owner)->postJson($this->endpoint(), ['qa' => $this->input(['evidence_file_ids' => [$id]])])->assertUnprocessable();
        }
        $this->assertCount(0, $this->rows());
    }

    public function test_qa_does_not_grant_document_capability_and_citations_are_rechecked_on_read(): void
    {
        $f = $this->file();
        $id = $this->create($this->input(['evidence_file_ids' => [$f]]));
        $this->actingAs($this->owner)->get($this->endpoint())->assertOk()->assertSee('qa-bundle.txt');
        $this->scoped(fn () => ProjectMembership::where('user_id', $this->owner->id)->where('project_id', $this->project->id)->update(['role_codes' => ['qc_inspector']]));
        $this->actingAs($this->owner)->get($this->endpoint())->assertOk()->assertDontSee('qa-bundle.txt')->assertSee('Existing document read permission is not available');
        $this->postJson($this->endpoint(), ['qa' => $this->input(['previous_decision_id' => $id, 'evidence_file_ids' => [$f]])])->assertForbidden();
        $this->create($this->input(['previous_decision_id' => $id]));
        $this->assertCount(2, $this->rows());
    }

    public function test_unknown_fields_fake_metadata_and_non_boolean_declarations_rejected(): void
    {
        $this->actingAs($this->owner);
        foreach (['created_at', 'company_id', 'sequence', 'actor_name', 'source_basis'] as $key) {
            $this->postJson($this->endpoint(), ['qa' => $this->input([$key => 'forged'])])->assertUnprocessable();
        }
        $p = $this->input();
        $p['declarations']['source_match'] = 'yes';
        $this->postJson($this->endpoint(), ['qa' => $p])->assertUnprocessable();
        $p = $this->input();
        unset($p['previous_decision_id']);
        $this->postJson($this->endpoint(), ['qa' => $p])->assertUnprocessable();
    }

    public function test_sql_stamps_history_and_forbids_rewriting_or_late_evidence(): void
    {
        $p = $this->input();
        $id = $this->create($p);
        $row = $this->rows()->last();
        $this->assertSame($this->owner->name, $row->actor_name);
        $this->assertSame(1, $row->sequence);
        $this->assertSame(64, strlen($row->custody_fingerprint));
        foreach (['update', 'delete'] as $action) {
            $this->scoped(function () use ($action, $id) {
                $q = DB::table('piping_qa_decisions')->where('id', $id);
                $this->assertSame(0, $action === 'delete' ? $q->delete() : $q->update(['reason' => 'rewritten']));
            });
        }
        $f = $this->file();
        try {
            $this->scoped(fn () => DB::transaction(fn () => DB::table('piping_qa_evidence')->insert(['decision_id' => $id, 'project_id' => $this->project->id, 'file_id' => $f, 'kind' => 'EVIDENCE'])));
            $this->fail('Late evidence must be refused');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_QA_EVIDENCE_LATE', $e->getMessage());
        }
    }

    public function test_archive_is_read_only_and_revocation_blocks_even_exact_retry(): void
    {
        $p = $this->input();
        $this->create($p);
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Synthetic archive');
        $this->actingAs($this->owner)->get($this->endpoint())->assertOk()->assertDontSee('piping-qa-form');
        $this->postJson($this->endpoint(), ['qa' => $p])->assertUnprocessable();
    }

    /** Real worker connections observed blocked on the shared advisory domain. */
    private function blockedWorkers(string $custodyId, array $payload, int $count, callable $whileBlocked): array
    {
        $script = tempnam(sys_get_temp_dir(), 'epcc-custody-worker-');
        $appPath = base_path();
        file_put_contents($script, '<?php require '.var_export($appPath.'/vendor/autoload.php', true).'; $app=require '.var_export($appPath.'/bootstrap/app.php', true).'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '.<<<'WORKER'
if(config('database.connections.pgsql.database')!=='epcc_test' || !str_starts_with((string)config('database.connections.pgsql.host'),'/tmp/epcc-')) { throw new RuntimeException('Refusing non-isolated custody worker'); }
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name',?,false)",[$argv[4]]);
$project=App\Models\Project::whereKey($argv[1])->firstOrFail();
$actor=app(App\Support\TenantContext::class)->forIdentityLookup(fn()=>App\Models\User::whereKey($argv[2])->firstOrFail());
try {
 $id=app(App\Services\PipingQaDecisionService::class)->create($project,$actor,$argv[5],json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR));
 echo json_encode(['id'=>$id]);
} catch (Illuminate\Validation\ValidationException $e) { echo json_encode(['status'=>422]);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
 echo json_encode(['status'=>$e->getStatusCode()]);
} catch (Throwable $e) { fwrite(STDERR,(string)$e); exit(1); }
WORKER);
        $workers = [];
        $prefix = 'custody-'.Str::random(10);
        try {
            DB::beginTransaction();
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['piping-receipt-access:'.$this->project->id]);

            for ($i = 0; $i < $count; $i++) {
                $p = new Process([PHP_BINARY, $script, $this->project->id, $this->owner->id, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $prefix.'-'.$i, $custodyId], $appPath, ['APP_ENV' => 'testing', 'APP_BASE_PATH' => $appPath, 'DB_DATABASE' => 'epcc_test', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'), 'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password')]);
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

    public function test_real_duplicate_and_stale_qa_head_after_wait(): void
    {
        $p = $this->input();
        $result = $this->blockedWorkers($this->receipt, $p, 2, fn () => null);
        $this->assertSame($result[0]['id'], $result[1]['id']);
        $this->assertCount(1, $this->rows());
        $p = $this->input(['previous_decision_id' => $result[0]['id']]);
        $result = $this->blockedWorkers($this->receipt, $p, 1, fn () => $this->create($this->input(['previous_decision_id' => $p['previous_decision_id']])));
        $this->assertSame(422, $result[0]['status']);
        $this->assertCount(2, $this->rows());
    }

    public function test_real_new_qc_after_wait_requires_fresh_assessment(): void
    {
        $result = $this->blockedWorkers($this->receipt, $this->input(), 1, fn () => $this->qc($this->review));
        $this->assertSame(422, $result[0]['status']);
        $this->assertCount(0, $this->rows());
    }

    public function test_real_qa_revocation_after_wait_denies_write(): void
    {
        $result = $this->blockedWorkers($this->receipt, $this->input(), 1, fn () => $this->configure(1, ['custody_mrr' => ['user:'.$this->buyer->id], 'receiving_qc' => ['user:'.$this->qc->id], 'acceptance_qa' => ['user:'.$this->qc->id], 'post_grn' => ['user:'.$this->buyer->id]]));
        $this->assertSame(403, $result[0]['status']);
        $this->assertSame(0, $this->scoped(fn () => DB::table('piping_qa_decisions')->count(), $this->buyer));
    }

    public function test_original_cde_row_visibility_is_required_even_for_historical_citations(): void
    {
        $f = $this->file();
        $this->create($this->input(['evidence_file_ids' => [$f]]));
        $this->actingAs($this->qc)->get($this->endpoint())->assertOk()->assertSee('qa-bundle.txt');
        app(TenantContext::class)->runAs($this->buyer->company_id, function () use ($f) {
            $file = DocumentFile::findOrFail($f);
            DB::table('document_revisions')->where('id', $file->revision_id)->update(['cde_state' => 'WIP', 'suitability' => null]);
        }, $this->project->id);
        $this->actingAs($this->qc)->get($this->endpoint())->assertOk()->assertDontSee('qa-bundle.txt')->assertDontSee($f)->assertSee('MTC missing');
    }

    public function test_raw_sql_cannot_accept_without_evidence_and_late_failure_rolls_back_header_and_citations(): void
    {
        $this->issuedSource();
        $f = $this->file();
        $p = $this->input(['decision' => 'ACCEPTED', 'open_conditions' => null, 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true), 'requirements_file_ids' => [$f], 'evidence_file_ids' => [$f]]);
        $raw = ['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'custody_id' => $this->receipt, 'review_id' => $this->review, 'company_id' => $this->owner->company_id, 'decided_by' => $this->owner->id, 'policy_id' => $p['expected_policy_id'], 'previous_decision_id' => null, 'request_id' => (string) Str::uuid(), 'payload_hash' => str_repeat('a', 64), 'decision' => 'ACCEPTED', 'reason' => 'No narrative waiver', 'open_conditions' => null, 'declarations' => json_encode($p['declarations'])];
        try {
            $this->scoped(function () use ($raw) {
                DB::table('piping_qa_decisions')->insert($raw);
                DB::statement('SET CONSTRAINTS piping_qa_complete IMMEDIATE');
            });
            $this->fail('Missing citations must fail');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_QA_EVIDENCE_REQUIRED', $e->getMessage());
        }
        $this->assertCount(0, $this->rows());
        DB::unprepared("CREATE OR REPLACE FUNCTION qa_test_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'QA_TEST_LATE'; END $$; CREATE TRIGGER zz_qa_test_fail AFTER INSERT ON piping_qa_evidence FOR EACH ROW EXECUTE FUNCTION qa_test_fail();");
        try {
            $this->create($p);
            $this->fail('Late failure expected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('QA_TEST_LATE', $e->getMessage());
        } finally {
            DB::statement('DROP TRIGGER zz_qa_test_fail ON piping_qa_evidence');
            DB::statement('DROP FUNCTION qa_test_fail()');
        }
        $this->assertCount(0, $this->rows());
        $this->assertSame(0, $this->scoped(fn () => DB::table('piping_qa_evidence')->count()));
        $id = $this->create($p);
        $this->assertSame(2, $this->scoped(fn () => DB::table('piping_qa_evidence')->where('decision_id', $id)->count()));
    }

    public function test_unknown_heat_and_over_delivery_remain_hold_without_an_exception_route(): void
    {
        $this->issuedSource();
        $source = $this->scoped(fn () => DB::table('piping_custody_records')->where('id', $this->receipt)->value('source_line_id'));
        $f = $this->file();
        foreach ([['heat_ref' => null], ['quantity' => '7.000']] as $changes) {
            $p = array_replace($this->arrival(), ['source_line_id' => $source, 'unlinked_reason' => null, 'unit' => 'M'], $changes);
            $this->receipt = app(PipingCustodyService::class)->create($this->project, $this->buyer, $p);
            $this->review = $this->qc(null, 'NO_NONCONFORMITY_OBSERVED');
            $data = $this->input(['decision' => 'ACCEPTED', 'open_conditions' => null, 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true), 'requirements_file_ids' => [$f], 'evidence_file_ids' => [$f]]);
            $this->actingAs($this->owner)->postJson($this->endpoint(), ['qa' => $data])->assertUnprocessable();
            $this->assertCount(0, $this->rows());
            $this->create($this->input());
            $this->assertSame('HOLD', $this->rows()->sole()->decision);
        }
    }

    public function test_forward_install_over_populated_archived_custody_and_qc_does_not_backfill_qa_or_stock(): void
    {
        $snapshot = fn () => $this->scoped(fn () => [DB::table('piping_custody_records')->orderBy('id')->get()->toJson(), DB::table('piping_receiving_reviews')->orderBy('id')->get()->toJson(), DB::table('material_lots')->orderBy('id')->get()->toJson(), DB::table('material_movements')->orderBy('id')->get()->toJson()]);
        $before = $snapshot();
        DB::statement('DROP TABLE piping_qa_amendment_source_bases CASCADE');
        DB::statement('DROP TABLE piping_custody_amendment_sources CASCADE');
        DB::statement('DROP TABLE piping_custody_amendments CASCADE');
        DB::table('migrations')->where('migration', '2026_10_23_110000_add_piping_custody_amendments')->delete();
        Schema::table('piping_receiving_reviews', fn ($t) => $t->dropColumn('amendment_id'));
        DB::statement('DROP TABLE piping_qa_evidence CASCADE');
        DB::statement('DROP TABLE piping_qa_decisions CASCADE');
        DB::table('migrations')->where('migration', '2026_10_23_100000_add_piping_qa_decisions')->delete();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Synthetic forward migration');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, $snapshot());
        $this->assertCount(0, $this->rows());
        $this->actingAs($this->owner)->get($this->endpoint())->assertOk()->assertDontSee('piping-qa-form');
    }

    public function test_capture_actual_bilingual_http_pages_and_no_stock_side_effects(): void
    {
        $snapshot = fn () => $this->scoped(fn () => [DB::table('material_lots')->orderBy('id')->get()->toJson(), DB::table('material_movements')->orderBy('id')->get()->toJson(), DB::table('purchase_orders')->orderBy('id')->get()->toJson()]);
        $this->issuedSource();
        $before = $snapshot();
        $f = $this->file();
        $this->create($this->input(['decision' => 'ACCEPTED', 'open_conditions' => null, 'declarations' => array_fill_keys(PipingQaDecisionService::DECLARATIONS, true), 'requirements_file_ids' => [$f], 'evidence_file_ids' => [$f]]));
        $this->assertSame($before, $snapshot());
        foreach (['en', 'fa'] as $lang) {
            $response = $this->actingAs($this->owner)->get(str_replace('lang=en', 'lang='.$lang, $this->endpoint()))->assertOk()->assertSee('qa-bundle.txt');
            if (getenv('EPCC_QA_EVIDENCE') === '1') {
                $html = preg_replace('/(<input[^>]*name="_token"[^>]*value=")[^"]*/', '$1REDACTED-CSRF', $response->getContent());
                file_put_contents(base_path('../docs/evidence/2026-10-23-piping-qa/qa-http-'.$lang.'.html'), $html);
            }
        }
    }

    public function test_html_form_parses_version_ids_and_preserves_scoped_old_input_without_rebasing(): void
    {
        $f = $this->file();
        $p = $this->input(['evidence_file_ids' => $f, 'requirements_file_ids' => '']);
        $this->actingAs($this->owner)->post($this->endpoint(), ['qa' => $p])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertCount(1, $this->rows());
        $this->get($this->endpoint())->assertOk()->assertSee('qa-bundle.txt');
        $this->withSession(['_old_input' => ['qa_project' => $this->project->id, 'qa_custody' => $this->receipt, 'qa' => ['previous_decision_id' => '', 'review_id' => $this->review, 'reason' => 'Scoped failed draft']]])->get($this->endpoint())->assertOk()->assertSee('Scoped failed draft')->assertSee('name="qa[previous_decision_id]" value=""', false);
        $this->withSession(['_old_input' => ['qa_project' => 'other-project', 'qa_custody' => $this->receipt, 'qa' => ['reason' => 'DO-NOT-LEAK']]])->get($this->endpoint())->assertOk()->assertDontSee('DO-NOT-LEAK');
    }
}
