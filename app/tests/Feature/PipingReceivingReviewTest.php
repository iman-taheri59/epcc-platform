<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\PipingCustodyService;
use App\Services\PipingReceiptAccessService;
use App\Services\PipingReceivingReviewService;
use App\Services\ProjectArchiveService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class PipingReceivingReviewTest extends TestCase
{
    private User $owner;

    private User $buyer;

    private User $qc;

    private Project $project;

    private string $url = '/projects/PH-14/materials/piping-custody?lang=en';

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
    }

    private function tenant(User $actor, callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($actor->company_id, $fn, $this->project->id);
    }

    private function nomination(int $version = 0): array
    {
        return ['version' => $version, 'suspend' => false, 'reason' => 'Synthetic custody responsibility matrix', 'custodian_company_id' => $this->buyer->company_id,
            'actions' => ['custody_mrr' => ['user:'.$this->buyer->id], 'receiving_qc' => ['user:'.$this->qc->id], 'acceptance_qa' => ['user:'.$this->qc->id], 'post_grn' => ['user:'.$this->buyer->id]]];
    }

    private function configure(?array $data = null): void
    {
        app(PipingReceiptAccessService::class)->configure($this->project, $this->owner, $data ?? $this->nomination());
    }

    private function payload(): array
    {
        return ['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'), 'request_id' => (string) Str::uuid(),
            'source_line_id' => null, 'unlinked_reason' => 'PO/MTC/IRN not resolved at arrival', 'shipment_ref' => 'SYNTHETIC-SHIPMENT-1',
            'package_ref' => null, 'received_at' => '2026-10-22T14:30:00+03:30', 'observed_description' => 'Observed pipe package',
            'quantity' => '2.125', 'unit' => 'm', 'heat_ref' => null, 'location_kind' => 'QUARANTINE', 'location_ref' => 'Receiving bay Q-1',
            'document_status' => 'MISSING', 'notes' => 'Reported failed inspection; physical custody only <script>unsafe()</script>'];
    }

    private function records(User $actor)
    {
        return app(PipingCustodyService::class)->scoped($this->project, $actor, fn () => DB::table('piping_custody_records')->orderBy('sequence')->get());
    }

    private function line(): object
    {
        $query = fn () => DB::table('purchase_order_items as l')->join('purchase_orders as p', 'p.id', '=', 'l.purchase_order_id')
            ->where('p.project_id', $this->project->id)->where('p.discipline', 'PIPING')->orderBy('l.id')->select('l.*', 'p.number')->first();
        $line = $this->tenant($this->buyer, $query);
        if ($line) {
            return $line;
        }
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, ['version' => 0, 'suspend' => false, 'selectors' => ['user:'.$this->buyer->id], 'reason' => 'Synthetic exact source fixture']);
        $vendor = Company::where('code', 'VEN-AZAR')->firstOrFail();
        app(PurchaseOrderEntryService::class)->create($this->project, $this->buyer, [
            'title_en' => 'Custody source', 'title_fa' => 'منبع تحویل‌گیری', 'discipline' => 'PIPING', 'material_category' => 'PIPE', 'vendor_company_id' => $vendor->id,
            'lines' => [['item_code' => 'CUSTODY-PIPE', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '5.000']]]);

        return $this->tenant($this->buyer, $query);
    }

    private function arrival(): string
    {
        return app(PipingCustodyService::class)->create($this->project, $this->buyer, $this->payload());
    }

    private function endpoint(string $id): string
    {
        return '/projects/PH-14/materials/piping-custody/'.$id.'/receiving-qc?lang=en';
    }

    private function input(?string $head = null): array
    {
        return ['expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'receiving_qc'),
            'previous_review_id' => $head, 'request_id' => (string) Str::uuid(), 'reviewed_at' => '2026-10-22T16:00:00+03:30',
            'observed_result' => 'INCOMPLETE', 'findings' => 'Synthetic scope: visual review; MTC missing; <script>unsafe-qc()</script>'];
    }

    private function reviews(User $actor)
    {
        return app(PipingCustodyService::class)->scoped($this->project, $actor, fn () => DB::table('piping_receiving_reviews')->orderBy('sequence')->get());
    }

    private function review(string $id, array $data, ?User $actor = null): void
    {
        $this->actingAs($actor ?? $this->qc)->post($this->endpoint($id), ['review' => $data])->assertRedirect($this->endpoint($id))->assertSessionHasNoErrors();
    }

    public function test_no_qc_inheritance_from_owner_custody_qa_grn_or_po_inspection(): void
    {
        $this->configure();
        $id = $this->arrival();
        foreach ([$this->buyer, $this->owner] as $actor) {
            $this->actingAs($actor)->postJson($this->endpoint($id), ['review' => $this->input()])->assertForbidden();
        }
        $this->actingAs($this->buyer)->get($this->endpoint($id))->assertOk()->assertDontSee('piping-receiving-qc-form');
        $this->actingAs($this->owner)->get($this->endpoint($id))->assertForbidden();
        $this->assertCount(0, $this->reviews($this->qc));
    }

    public function test_independently_nominated_mrr_preparer_cannot_self_review_even_after_nomination_change(): void
    {
        $this->configure();
        $id = $this->arrival();
        $n = $this->nomination(1);
        $n['actions']['receiving_qc'] = ['user:'.$this->buyer->id, 'user:'.$this->qc->id];
        $this->configure($n);
        $p = $this->input();
        $p['expected_policy_id'] = app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'receiving_qc');
        $this->actingAs($this->buyer)->get($this->endpoint($id))->assertOk()->assertSee('receiving-qc-self-review-blocked')->assertDontSee('piping-receiving-qc-form');
        $this->postJson($this->endpoint($id), ['review' => $p])->assertUnprocessable();
        $this->assertCount(0, $this->reviews($this->qc));
        $this->review($id, $this->input());
        $this->assertCount(1, $this->reviews($this->buyer));
    }

    public function test_bilingual_scoped_page_private_link_and_real_qc_record_without_custody_commercial_or_stock_changes(): void
    {
        $this->configure();
        $id = $this->arrival();
        $snapshot = fn () => $this->tenant($this->buyer, fn () => [DB::table('material_lots')->orderBy('id')->get()->toJson(), DB::table('material_movements')->orderBy('id')->get()->toJson(), DB::table('purchase_orders')->orderBy('id')->get()->toJson(), DB::table('material_inspections')->orderBy('id')->get()->toJson()]);
        $before = $snapshot();
        $receiptBefore = $this->records($this->buyer)->sole();
        foreach (['en', 'fa'] as $lang) {
            $this->actingAs($this->qc)->get(str_replace('lang=en', 'lang='.$lang, $this->endpoint($id)))->assertOk()->assertSee('piping-receiving-qc-form')->assertSee('sb-burger')->assertSee('lang="'.$lang.'"', false)->assertHeader('cache-control', 'max-age=0, no-store, private');
        }
        $this->get('/projects/PH-14/materials/piping-custody/'.$id.'?lang=en')->assertOk()->assertSee('piping-receiving-qc-link');
        $this->review($id, $this->input());
        $row = $this->reviews($this->buyer)->sole();
        $this->assertSame($this->qc->id, $row->reviewed_by);
        $this->assertSame($this->qc->company_id, $row->company_id);
        $this->assertSame($this->qc->name, $row->actor_name);
        $this->assertSame($id, $row->custody_id);
        $this->assertSame(1, $row->sequence);
        $this->assertSame(64, strlen($row->custody_fingerprint));
        $this->assertStringContainsString('12:30:00', $row->reviewed_at);
        $this->assertNull($row->previous_review_id);
        $this->assertEquals($receiptBefore, $this->records($this->buyer)->sole());
        $this->assertSame('CUSTODY_ONLY', $receiptBefore->state);
        $this->assertSame($before, $snapshot());
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->buyer)->get(str_replace('lang=en', 'lang='.$lang, $this->endpoint($id)))->assertOk()->assertSee('receiving-qc-latest')->assertDontSee('<script>unsafe-qc()</script>', false)->assertSee('&lt;script&gt;unsafe-qc()&lt;/script&gt;', false);
            if (getenv('EPCC_RECEIVING_QC_EVIDENCE')) {
                file_put_contents(base_path('../docs/evidence/2026-10-22-piping-receiving-qc/review-'.$lang.'.html'), preg_replace('/<input[^>]+name="_token"[^>]*>/i', '', $r->getContent()));
            }
        }
    }

    public function test_three_observation_results_never_accept_material_and_replacement_keeps_history(): void
    {
        $this->configure();
        $id = $this->arrival();
        $head = null;
        foreach (PipingReceivingReviewService::RESULTS as $result) {
            $p = $this->input($head);
            $p['observed_result'] = $result;
            $this->review($id, $p);
            $head = $this->reviews($this->qc)->last()->id;
        }
        $this->assertCount(3, $this->reviews($this->buyer));
        $this->assertSame([1, 2, 3], $this->reviews($this->buyer)->pluck('sequence')->all());
        $this->assertSame('CUSTODY_ONLY', $this->records($this->buyer)->sole()->state);
        app(PipingCustodyService::class)->scoped($this->project, $this->qc, function (): void {
            $this->assertSame(0, DB::table('piping_receiving_reviews')->update(['findings' => 'rewrite']));
            $this->assertSame(0, DB::table('piping_receiving_reviews')->delete());
        });
    }

    public function test_retry_is_one_review_changed_key_payload_and_stale_head_policy_are_refused(): void
    {
        $this->configure();
        $id = $this->arrival();
        $p = $this->input();
        $this->review($id, $p);
        $this->review($id, $p);
        $first = $this->reviews($this->qc)->sole();
        $changed = $p;
        $changed['findings'] = 'Changed';
        $this->actingAs($this->qc)->postJson($this->endpoint($id), ['review' => $changed])->assertUnprocessable();
        $this->postJson($this->endpoint($id), ['review' => $this->input()])->assertUnprocessable();
        $this->review($id, $this->input($first->id));
        $this->review($id, $p);
        $this->assertCount(2, $this->reviews($this->qc));
        $this->configure($this->nomination(1));
        $this->postJson($this->endpoint($id), ['review' => $p])->assertUnprocessable();
    }

    public function test_hidden_original_source_hides_qc_history_and_blocks_recording_without_read_delegation(): void
    {
        $this->configure();
        $line = $this->line();
        $p = $this->payload();
        $p['source_line_id'] = $line->id;
        $p['unit'] = $line->unit;
        $p['unlinked_reason'] = null;
        $id = app(PipingCustodyService::class)->create($this->project, $this->buyer, $p);
        $this->review($id, $this->input());
        DB::table('companies')->where('id', $this->qc->company_id)->update(['tenant_type' => 'TPI']);
        $this->assertNotNull(app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'receiving_qc'));
        $this->actingAs($this->qc)->get($this->endpoint($id))->assertNotFound()->assertDontSee('unsafe-qc');
        $this->postJson($this->endpoint($id), ['review' => $this->input()])->assertNotFound();
        $this->assertCount(0, $this->reviews($this->qc));
        $this->assertCount(1, $this->reviews($this->buyer));
    }

    public function test_archive_keeps_existing_reviews_readonly_and_suspension_revokes_live_read_and_write(): void
    {
        $this->configure();
        $id = $this->arrival();
        $this->review($id, $this->input());
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Synthetic QC archive');
        $this->actingAs($this->qc)->get($this->endpoint($id))->assertOk()->assertSee('unsafe-qc')->assertDontSee('piping-receiving-qc-form');
        $this->postJson($this->endpoint($id), ['review' => $this->input($this->reviews($this->qc)->sole()->id)])->assertUnprocessable();
    }

    public function test_suspension_and_current_role_or_personnel_revocation_are_not_cached(): void
    {
        $n = $this->nomination();
        $role = $this->tenant($this->qc, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->qc->id)->first()->role_codes[0]);
        $n['actions']['receiving_qc'] = ['role:'.$this->qc->company_id.':'.$role];
        $this->configure($n);
        $id = $this->arrival();
        $p = $this->input();
        $this->tenant($this->qc, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->qc->id)->update(['role_codes' => '[]']));
        $this->actingAs($this->qc)->postJson($this->endpoint($id), ['review' => $p])->assertForbidden();
        $this->configure($this->nomination(1));
        $this->tenant($this->qc, fn () => User::whereKey($this->qc->id)->update(['is_active' => false]));
        $this->postJson($this->endpoint($id), ['review' => $this->input()])->assertForbidden();
        $this->tenant($this->qc, fn () => User::whereKey($this->qc->id)->update(['is_active' => true]));
        $this->configure(['version' => 2, 'suspend' => true, 'reason' => 'Suspend QC']);
        $this->get($this->endpoint($id))->assertForbidden();
        $this->postJson($this->endpoint($id), ['review' => $p])->assertForbidden();
    }

    public function test_invalid_nested_forged_server_fields_dates_approval_results_and_blank_findings_rejected(): void
    {
        $this->configure();
        $id = $this->arrival();
        foreach (['reviewed_by' => [$this->owner->id], 'company_id' => [$this->buyer->company_id], 'custody_id' => [(string) Str::uuid()], 'actor_name' => ['forged'], 'sequence' => [3], 'custody_fingerprint' => ['forged'], 'created_at' => ['1970-01-01'],
            'findings' => ['   ', [], "bad\0value"], 'observed_result' => ['ACCEPTED', 'GRN', 'PASS', 'ACCEPTED_WITH_COMMENT'],
            'reviewed_at' => ['2026-02-31T14:00:00+03:30', '2026-10-22T14:00:00', 'bad'], 'previous_review_id' => ['not-uuid']] as $field => $values) {
            foreach ($values as $value) {
                $p = $this->input();
                $p[$field] = $value;
                $this->actingAs($this->qc)->postJson($this->endpoint($id), ['review' => $p])->assertUnprocessable();
                $this->assertCount(0, $this->reviews($this->qc));
            }
        }
    }

    public function test_scoped_recovery_preserves_empty_stale_head_and_request_without_cross_receipt_restore(): void
    {
        $this->configure();
        $id = $this->arrival();
        $old = $this->input();
        $this->review($id, $this->input());
        $this->actingAs($this->qc)->withSession(['_old_input' => ['receiving_qc_project' => $this->project->id, 'receiving_qc_custody' => $id, 'review' => $old]])->get($this->endpoint($id))->assertOk()
            ->assertSee('name="review[previous_review_id]" value=""', false)->assertSee($old['request_id'])->assertSee($old['expected_policy_id']);
        $this->withSession(['_old_input' => ['receiving_qc_project' => $this->project->id, 'receiving_qc_custody' => (string) Str::uuid(), 'review' => $old]])->get($this->endpoint($id))->assertOk()->assertDontSee($old['request_id']);
    }

    public function test_rls_missing_actor_foreign_project_and_missing_parent_fail_closed_and_sql_stamps_server_metadata(): void
    {
        $this->configure();
        $id = $this->arrival();
        $this->review($id, $this->input());
        $row = $this->reviews($this->qc)->sole();
        $this->assertSame(0, $this->tenant($this->qc, fn () => DB::table('piping_receiving_reviews')->count()));
        $other = Project::where('code', 'BB-2')->firstOrFail();
        $this->assertSame(0, app(PipingCustodyService::class)->scoped($other, $this->qc, fn () => DB::table('piping_receiving_reviews')->count()));
        $this->actingAs($this->qc)->get($this->endpoint((string) Str::uuid()))->assertNotFound();
        $raw = (array) $row;
        $raw['id'] = (string) Str::uuid();
        $raw['request_id'] = (string) Str::uuid();
        $raw['previous_review_id'] = $row->id;
        $raw['actor_name'] = 'FORGED';
        $raw['sequence'] = 999;
        $raw['custody_fingerprint'] = 'FORGED';
        $raw['created_at'] = '1970-01-01';
        app(PipingCustodyService::class)->scoped($this->project, $this->qc, fn () => DB::table('piping_receiving_reviews')->insert($raw));
        $next = $this->reviews($this->qc)->last();
        $this->assertSame(2, $next->sequence);
        $this->assertSame($this->qc->name, $next->actor_name);
        $this->assertSame($row->custody_fingerprint, $next->custody_fingerprint);
        $this->assertStringNotContainsString('1970', $next->created_at);
        app(PipingCustodyService::class)->scoped($this->project, $this->qc, function () use ($row, $id): void {
            DB::statement("SET LOCAL TIME ZONE 'Asia/Tehran'");
            $hash = DB::selectOne('SELECT epcc_piping_custody_fingerprint(?::uuid) AS h', [$id])->h;
            $this->assertSame($row->custody_fingerprint, $hash);
        });
    }

    public function test_direct_sql_self_review_and_stale_parent_guard_and_late_failure_are_atomic(): void
    {
        $this->configure();
        $id = $this->arrival();
        $this->review($id, $this->input());
        $raw = (array) $this->reviews($this->qc)->sole();
        $raw['id'] = (string) Str::uuid();
        $raw['request_id'] = (string) Str::uuid();
        try {
            app(PipingCustodyService::class)->scoped($this->project, $this->qc, fn () => DB::table('piping_receiving_reviews')->insert($raw));
            $this->fail('Stale SQL head should fail');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_RECEIVING_QC_STALE', $e->getMessage());
        }
        $n = $this->nomination(1);
        $n['actions']['receiving_qc'] = ['user:'.$this->buyer->id, 'user:'.$this->qc->id];
        $this->configure($n);
        $raw['reviewed_by'] = $this->buyer->id;
        $raw['company_id'] = $this->buyer->company_id;
        $raw['policy_id'] = app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'receiving_qc');
        try {
            app(PipingCustodyService::class)->scoped($this->project, $this->buyer, fn () => DB::table('piping_receiving_reviews')->insert($raw));
            $this->fail('Self-review should fail');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_RECEIVING_QC_SELF_REVIEW', $e->getMessage());
        }
        DB::unprepared("CREATE OR REPLACE FUNCTION qc_test_late_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'QC_TEST_LATE'; END $$; CREATE TRIGGER zz_qc_test_late_fail AFTER INSERT ON piping_receiving_reviews FOR EACH ROW EXECUTE FUNCTION qc_test_late_fail();");
        try {
            app(PipingReceivingReviewService::class)->create($this->project, $this->qc, $id, $this->input($this->reviews($this->qc)->sole()->id));
            $this->fail('Late failure expected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('QC_TEST_LATE', $e->getMessage());
        } finally {
            DB::statement('DROP TRIGGER zz_qc_test_late_fail ON piping_receiving_reviews');
        }
        $this->assertCount(1, $this->reviews($this->qc));
        $this->review($id, $this->input($this->reviews($this->qc)->sole()->id));
        $this->assertSame(2, $this->reviews($this->qc)->last()->sequence);
    }

    public function test_forward_install_over_populated_archived_custody_creates_no_review_or_stock(): void
    {
        $this->configure();
        $id = $this->arrival();
        $before = $this->records($this->buyer)->sole();
        DB::statement('DROP TABLE piping_qa_amendment_source_bases CASCADE');
        DB::statement('DROP TABLE piping_custody_amendment_sources CASCADE');
        DB::statement('DROP TABLE piping_custody_amendments CASCADE');
        DB::statement('DROP TABLE piping_qa_evidence CASCADE');
        DB::statement('DROP TABLE piping_qa_decisions CASCADE');
        DB::table('migrations')->whereIn('migration', ['2026_10_23_100000_add_piping_qa_decisions', '2026_10_23_110000_add_piping_custody_amendments'])->delete();
        DB::statement('DROP TABLE piping_receiving_reviews CASCADE');
        DB::table('migrations')->where('migration', '2026_10_22_120000_add_piping_receiving_reviews')->delete();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Forward receiving evidence migration');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertCount(0, $this->reviews($this->qc));
        $this->assertEquals($before, $this->records($this->buyer)->sole());
        $this->actingAs($this->qc)->get($this->endpoint($id))->assertOk()->assertDontSee('piping-receiving-qc-form');
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
 $id=app(App\Services\PipingReceivingReviewService::class)->create($project,$actor,$argv[5],json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR));
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
                $p = new Process([PHP_BINARY, $script, $this->project->id, $this->qc->id, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $prefix.'-'.$i, $custodyId], $appPath, ['APP_ENV' => 'testing', 'APP_BASE_PATH' => $appPath, 'DB_DATABASE' => 'epcc_test', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'), 'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password')]);
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

    public function test_real_concurrent_different_reviews_have_one_winner_and_one_stale_refusal(): void
    {
        $this->configure();
        $id = $this->arrival();
        $result = $this->blockedWorkers($id, $this->input(), 2, fn () => null);
        // Both workers deliberately use the same request: one idempotent entry.
        $this->assertSame($result[0]['id'], $result[1]['id']);
        $this->assertCount(1, $this->reviews($this->qc));
        $p = $this->input($this->reviews($this->qc)->sole()->id);
        $result = $this->blockedWorkers($id, $p, 1, fn () => $this->review($id, $this->input($p['previous_review_id'])));
        $this->assertSame(422, $result[0]['status']);
        $this->assertCount(2, $this->reviews($this->qc));
    }

    public function test_real_nomination_revocation_during_lock_wait_denies_new_review(): void
    {
        $this->configure();
        $id = $this->arrival();
        $n = $this->nomination(1);
        $n['actions']['receiving_qc'] = ['user:'.$this->owner->id];
        $result = $this->blockedWorkers($id, $this->input(), 1, fn () => $this->configure($n));
        $this->assertSame(403, $result[0]['status']);
        $this->assertCount(0, $this->reviews($this->buyer));
    }

    public function test_distinct_actor_ids_in_same_company_can_review_even_with_identical_display_names(): void
    {
        $this->configure();
        $id = $this->arrival();
        $person = app(TenantContext::class)->forIdentityLookup(fn () => User::create(['company_id' => $this->buyer->company_id, 'name' => $this->buyer->name, 'email' => 'samecompany-qc@example.test', 'password' => 'test-only', 'is_active' => true]));
        $this->tenant($this->buyer, fn () => DB::table('project_memberships')->insert(['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'user_id' => $person->id, 'role_codes' => '[]', 'created_at' => now(), 'updated_at' => now()]));
        $n = $this->nomination(1);
        $n['actions']['receiving_qc'] = ['user:'.$person->id];
        $this->configure($n);
        $p = $this->input();
        $p['expected_policy_id'] = app(PipingCustodyService::class)->policyId($this->project, $person, 'receiving_qc');
        $this->review($id, $p, $person);
        $row = $this->reviews($this->buyer)->sole();
        $this->assertSame($this->buyer->company_id, $row->company_id);
        $this->assertSame($this->buyer->name, $row->actor_name);
        $this->assertNotSame($this->buyer->id, $row->reviewed_by);
    }
}
