<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\User;
use App\Services\PipingCustodyService;
use App\Services\PipingReceiptAccessService;
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

final class PipingCustodyTest extends TestCase
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

    private function submit(array $p, ?User $actor = null): string
    {
        $r = $this->actingAs($actor ?? $this->buyer)->post($this->url, ['receipt' => $p])->assertRedirect();

        return basename(parse_url($r->headers->get('location'), PHP_URL_PATH));
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

    public function test_no_configuration_means_no_default_read_or_write_not_even_owner_configurator(): void
    {
        foreach ([$this->buyer, $this->owner, $this->qc] as $actor) {
            $this->assertNull(app(PipingCustodyService::class)->policyId($this->project, $actor));
            $this->actingAs($actor)->get($this->url)->assertForbidden();
            $this->postJson($this->url, ['receipt' => []])->assertForbidden();
        }
    }

    public function test_bilingual_form_private_cache_shell_no_material_defaults_and_current_link(): void
    {
        $this->configure();
        foreach (['en', 'fa'] as $lang) {
            $r = $this->actingAs($this->buyer)->get(str_replace('lang=en', 'lang='.$lang, $this->url))->assertOk()
                ->assertSee('piping-custody-form')->assertSee('sb-burger')->assertSee('lang="'.$lang.'"', false)
                ->assertHeader('cache-control', 'max-age=0, no-store, private');
            $this->assertStringNotContainsString(' selected', $r->getContent());
        }
        $this->get('/projects/PH-14/materials?lang=en')->assertOk()->assertSee('piping-custody-link');
        $this->actingAs($this->owner)->get('/projects/PH-14/materials?lang=en')->assertOk()->assertDontSee('piping-custody-link')->assertSee('piping-receipt-access-link');
    }

    public function test_unresolved_custody_despite_missing_docs_failed_inspection_is_immutable_not_stock_or_acceptance(): void
    {
        $this->configure();
        $before = $this->tenant($this->buyer, fn () => [DB::table('material_lots')->orderBy('id')->get(), DB::table('material_movements')->orderBy('id')->get(), DB::table('purchase_orders')->orderBy('id')->get()]);
        $id = $this->submit($this->payload());
        $row = $this->records($this->buyer)->sole();
        $this->assertSame($id, $row->id);
        $this->assertSame('CUSTODY_ONLY', $row->state);
        $this->assertSame('2.125', $row->quantity);
        $this->assertSame($this->buyer->id, $row->created_by);
        $this->assertSame($this->buyer->name, $row->actor_name);
        $this->assertNull($row->source_snapshot);
        $this->assertNull($row->source_line_id);
        $this->assertNull($row->heat_ref);
        $this->assertStringContainsString('11:00:00', $row->received_at);
        $this->assertSame('MISSING', $row->document_status);
        $this->assertSame('QUARANTINE', $row->location_kind);
        $after = $this->tenant($this->buyer, fn () => [DB::table('material_lots')->orderBy('id')->get(), DB::table('material_movements')->orderBy('id')->get(), DB::table('purchase_orders')->orderBy('id')->get()]);
        $this->assertEquals($before, $after);
        foreach (['en', 'fa'] as $lang) {
            $r = $this->get('/projects/PH-14/materials/piping-custody/'.$id.'?lang='.$lang)->assertOk()->assertSee('piping-custody-record')->assertDontSee('<script>unsafe()</script>', false);
            if (getenv('EPCC_CUSTODY_EVIDENCE')) {
                $html = preg_replace('/<input[^>]+name="_token"[^>]*>/i', '', $r->getContent());
                file_put_contents(base_path('../docs/evidence/2026-10-22-piping-physical-custody/record-'.$lang.'.html'), $html);
            }
        }
        app(PipingCustodyService::class)->scoped($this->project, $this->buyer, function () use ($id): void {
            $this->assertSame(0, DB::table('piping_custody_records')->where('id', $id)->update(['quantity' => '3']));
            $this->assertSame(0, DB::table('piping_custody_records')->where('id', $id)->delete());
        });
        $this->assertEquals($row, $this->records($this->buyer)->sole());
    }

    public function test_cross_company_explicit_recorder_is_not_impersonated_or_forced_to_be_custodian(): void
    {
        $n = $this->nomination();
        $n['actions']['custody_mrr'] = ['user:'.$this->qc->id];
        $this->configure($n);
        $p = $this->payload();
        $p['expected_policy_id'] = app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'custody_mrr');
        $id = $this->submit($p, $this->qc);
        $row = $this->records($this->qc)->sole();
        $this->assertSame($this->qc->id, $row->created_by);
        $this->assertSame($this->qc->company_id, $row->company_id);
        $this->assertSame($this->buyer->company_id, $row->custodian_company_id);
        $this->assertSame($this->qc->name, $row->actor_name);
        $this->assertSame($id, $row->id);
        $this->actingAs($this->buyer)->postJson($this->url, ['receipt' => $p])->assertForbidden();
    }

    public function test_only_current_nominated_readers_not_configurator_and_no_automatic_qc_write(): void
    {
        $this->configure();
        $id = $this->submit($this->payload());
        $this->actingAs($this->qc)->get($this->url)->assertOk()->assertDontSee('piping-custody-form')->assertSee('SYNTHETIC-SHIPMENT-1');
        $this->get('/projects/PH-14/materials/piping-custody/'.$id.'?lang=en')->assertOk();
        $this->postJson($this->url, ['receipt' => $this->payload()])->assertForbidden();
        $this->actingAs($this->owner)->get($this->url)->assertForbidden();
        $this->get('/projects/PH-14/materials/piping-custody/'.$id.'?lang=en')->assertForbidden();
        $this->assertCount(0, $this->records($this->owner));
        $this->assertCount(0, $this->tenant($this->qc, fn () => DB::table('piping_receipt_author_policies')->get()));
        $index = $this->tenant($this->qc, fn () => DB::table('piping_receipt_nomination_index')->get());
        $this->assertNotEmpty($index);
        foreach ($index as $entry) {
            $this->assertSame($this->qc->company_id, $entry->company_id);
            $this->assertFalse(property_exists($entry, 'label'));
        }
    }

    public function test_visible_exact_line_unit_snapshot_hash_and_multiple_heat_splits_without_source_changes(): void
    {
        $this->configure();
        $line = $this->line();
        $this->assertNotNull($line);
        $p = $this->payload();
        $p['source_line_id'] = $line->id;
        $p['unit'] = $line->unit;
        $p['unlinked_reason'] = null;
        $p['heat_ref'] = 'HEAT-A';
        $this->submit($p);
        $p['request_id'] = (string) Str::uuid();
        $p['heat_ref'] = 'HEAT-B';
        $this->submit($p);
        $rows = $this->records($this->buyer);
        $this->assertCount(2, $rows);
        $this->assertSame([1, 2], $rows->pluck('sequence')->all());
        $src = json_decode($rows->first()->source_snapshot, true);
        $this->assertSame($line->id, $src['line_id']);
        $this->assertSame($line->purchase_order_id, $src['purchase_order_id']);
        $this->assertSame($line->unit, $src['unit']);
        $this->assertArrayHasKey('supersedes_id', $src);
        $this->assertSame(64, strlen($rows->first()->source_fingerprint));
        $this->assertArrayNotHasKey('unit_price', $src);
        $this->assertEquals($line, $this->line());
    }

    public function test_nomination_does_not_delegate_hidden_po_or_leak_linked_record_snapshot(): void
    {
        $this->configure();
        $line = $this->line();
        $p = $this->payload();
        $p['source_line_id'] = $line->id;
        $p['unit'] = $line->unit;
        $p['unlinked_reason'] = null;
        $id = $this->submit($p);
        $this->actingAs($this->qc)->get('/projects/PH-14/materials/piping-custody/'.$id.'?lang=en')->assertOk();
        // TPI still eligible for explicit receiving nomination but not general oversight PO SELECT.
        DB::table('companies')->where('id', $this->qc->company_id)->update(['tenant_type' => 'TPI']);
        $this->assertNotNull(app(PipingCustodyService::class)->policyId($this->project, $this->qc));
        $this->assertCount(0, $this->records($this->qc));
        $this->get('/projects/PH-14/materials/piping-custody/'.$id.'?lang=en')->assertNotFound()->assertDontSee($line->number);
        $this->get($this->url)->assertOk()->assertDontSee('SYNTHETIC-SHIPMENT-1');
        $n = $this->nomination(1);
        $n['actions']['custody_mrr'] = ['user:'.$this->buyer->id, 'user:'.$this->qc->id];
        $this->configure($n);
        $p['expected_policy_id'] = app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'custody_mrr');
        $p['request_id'] = (string) Str::uuid();
        $this->actingAs($this->qc)->postJson($this->url, ['receipt' => $p])->assertUnprocessable();
        $p['source_line_id'] = null;
        $p['unlinked_reason'] = 'Not resolved without source access';
        $unlinked = $this->submit($p, $this->qc);
        $this->get('/projects/PH-14/materials/piping-custody/'.$unlinked.'?lang=en')->assertOk();
        $this->assertCount(1, $this->records($this->qc));
        $this->assertCount(2, $this->records($this->buyer));
    }

    public function test_retry_same_request_is_one_record_changed_payload_is_refused_without_overwrite(): void
    {
        $this->configure();
        $p = $this->payload();
        $id = $this->submit($p);
        $this->assertSame($id, $this->submit($p));
        $this->assertCount(1, $this->records($this->buyer));
        $p['quantity'] = '4';
        $this->actingAs($this->buyer)->postJson($this->url, ['receipt' => $p])->assertUnprocessable();
        $this->assertSame('2.125', $this->records($this->buyer)->sole()->quantity);
    }

    public function test_version_change_suspension_and_archive_recheck_current_authority_without_defaults(): void
    {
        $this->configure();
        $p = $this->payload();
        $id = $this->submit($p);
        $this->configure($this->nomination(1));
        $this->actingAs($this->buyer)->postJson($this->url, ['receipt' => $p])->assertUnprocessable();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Synthetic custody archive');
        $this->get($this->url)->assertOk()->assertDontSee('piping-custody-form')->assertSee('piping-custody-readonly');
        $this->get('/projects/PH-14/materials/piping-custody/'.$id.'?lang=en')->assertOk();
        $this->postJson($this->url, ['receipt' => $this->payload()])->assertUnprocessable();
    }

    public function test_suspension_revokes_read_write_and_retains_original_owner_history(): void
    {
        $this->configure();
        $id = $this->submit($this->payload());
        $this->configure(['version' => 1, 'suspend' => true, 'reason' => 'Synthetic suspension']);
        $this->assertNull(app(PipingCustodyService::class)->policyId($this->project, $this->buyer));
        $this->actingAs($this->buyer)->get($this->url)->assertForbidden();
        $this->get('/projects/PH-14/materials/piping-custody/'.$id.'?lang=en')->assertForbidden();
        $this->assertSame(2, $this->tenant($this->owner, fn () => DB::table('piping_receipt_author_policies')->count()));
        $this->assertSame(0, $this->tenant($this->owner, fn () => DB::table('piping_receipt_nomination_index')->count()));
    }

    public function test_invalid_nested_server_owned_unit_quantity_date_and_missing_source_inputs_are_rejected(): void
    {
        $this->configure();
        $line = $this->line();
        foreach (['quantity' => ['0', '-1', '0.0001', '1e3', '1,000', '1000000000000000', [], 2.125], 'received_at' => ['2026-02-31T14:00:00+03:30', '2026-10-22T14:30:00', 'bad'],
            'unlinked_reason' => [null], 'source_line_id' => [(string) Str::uuid()], 'shipment_ref' => [[], '   ', "bad\0value"], 'location_kind' => ['ACCEPTED'], 'document_status' => ['APPROVED'],
            'created_by' => [$this->owner->id], 'custodian_company_id' => [$this->owner->company_id], 'state' => ['ACCEPTED'], 'sequence' => [300], 'source_snapshot' => [['forged' => 'x']]] as $field => $values) {
            foreach ($values as $value) {
                $p = $this->payload();
                $p[$field] = $value;
                $this->actingAs($this->buyer)->postJson($this->url, ['receipt' => $p])->assertUnprocessable();
                $this->assertCount(0, $this->records($this->buyer));
            }
        }
        $p = $this->payload();
        $p['source_line_id'] = $line->id;
        $p['unlinked_reason'] = null;
        $p['unit'] = 'NOT-THE-SOURCE-UNIT';
        $this->postJson($this->url, ['receipt' => $p])->assertUnprocessable();
    }

    public function test_company_bound_role_live_membership_discipline_and_identity_revocations(): void
    {
        $n = $this->nomination();
        $role = $this->tenant($this->buyer, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->buyer->id)->first()->role_codes[0]);
        $n['actions']['custody_mrr'] = ['role:'.$this->buyer->company_id.':'.$role];
        $this->configure($n);
        $this->assertNotNull(app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'));
        $this->tenant($this->buyer, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->buyer->id)->update(['role_codes' => '[]']));
        $this->assertNull(app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'));
        $this->configure($this->nomination(1));
        $this->assertNotNull(app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'));
        $this->tenant($this->buyer, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->buyer->id)->update(['discipline_scope' => '["CIVIL"]']));
        $this->assertNull(app(PipingCustodyService::class)->policyId($this->project, $this->buyer));
        $this->tenant($this->buyer, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->buyer->id)->update(['discipline_scope' => null]));
        $this->tenant($this->buyer, fn () => User::whereKey($this->buyer->id)->update(['is_active' => false]));
        $this->assertNull(app(PipingCustodyService::class)->policyId($this->project, $this->buyer));
    }

    public function test_rls_requires_actor_and_project_scope_restored_on_success_and_exception(): void
    {
        $this->configure();
        $this->submit($this->payload());
        $this->assertSame(0, $this->tenant($this->buyer, fn () => DB::table('piping_custody_records')->count()));
        $svc = app(PipingCustodyService::class);
        $this->tenant($this->buyer, function () use ($svc): void {
            DB::select("SELECT set_config('epcc.receipt_actor_id',?,true)", [$this->owner->id]);
            $this->assertSame($this->owner->id, DB::selectOne("SELECT current_setting('epcc.receipt_actor_id',true) AS id")->id);
            $this->assertNotNull($svc->policyId($this->project, $this->buyer));
            $this->assertSame($this->owner->id, DB::selectOne("SELECT current_setting('epcc.receipt_actor_id',true) AS id")->id);
            try {
                $svc->scoped($this->project, $this->buyer, fn () => DB::statement('SELECT 1/0'));
                $this->fail('Synthetic SQL exception expected');
            } catch (QueryException $e) {
                $this->assertStringContainsString('division by zero', $e->getMessage());
            }
            $this->assertSame($this->owner->id, DB::selectOne("SELECT current_setting('epcc.receipt_actor_id',true) AS id")->id);
        });
        $other = Project::where('code', 'BB-2')->firstOrFail();
        $this->assertNull($svc->policyId($other, $this->buyer));
        $this->actingAs($this->buyer)->get(str_replace('PH-14', 'BB-2', $this->url))->assertForbidden();
    }

    public function test_direct_projection_and_counter_mutation_denied_and_late_failure_rolls_back_counter_and_record(): void
    {
        $this->configure();
        try {
            $this->tenant($this->owner, fn () => DB::transaction(fn () => DB::table('piping_receipt_nomination_index')->where('project_id', $this->project->id)->delete()));
            $this->fail('Derived index cannot be directly altered');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_NOMINATION_DERIVED_ONLY', $e->getMessage());
        }
        DB::unprepared("CREATE OR REPLACE FUNCTION custody_test_fail() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'CUSTODY_TEST_LATE'; END $$; CREATE TRIGGER zz_custody_test_fail AFTER INSERT ON piping_custody_records FOR EACH ROW EXECUTE FUNCTION custody_test_fail();");
        try {
            app(PipingCustodyService::class)->create($this->project, $this->buyer, $this->payload());
            $this->fail('Late failure should roll back');
        } catch (QueryException $e) {
            $this->assertStringContainsString('CUSTODY_TEST_LATE', $e->getMessage());
        } finally {
            DB::statement('DROP TRIGGER zz_custody_test_fail ON piping_custody_records');
        }
        $this->assertCount(0, $this->records($this->buyer));
        $this->submit($this->payload());
        $this->assertSame(1, $this->records($this->buyer)->sole()->sequence);
        try {
            app(PipingCustodyService::class)->scoped($this->project, $this->buyer, fn () => DB::table('piping_custody_counters')->where('project_id', $this->project->id)->update(['last_sequence' => 1000]));
            $this->fail('Counter cannot be directly altered');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PIPING_CUSTODY_COUNTER_INTERNAL', $e->getMessage());
        }
    }

    public function test_current_participation_company_binding_and_custodian_entitlements_are_not_snapshots(): void
    {
        $this->configure();
        $this->assertNotNull(app(PipingCustodyService::class)->policyId($this->project, $this->qc));
        $custodian = Company::whereKey($this->buyer->company_id)->firstOrFail();
        $plan = Plan::whereKey($custodian->plan_id)->firstOrFail();
        $original = $plan->modules;
        $plan->update(['modules' => ['core']]);
        $this->assertNull(app(PipingCustodyService::class)->policyId($this->project, $this->qc));
        $plan->update(['modules' => $original]);
        $participation = (array) DB::table('project_company')->where('project_id', $this->project->id)->where('company_id', $this->buyer->company_id)->first();
        $this->tenant($this->buyer, fn () => DB::table('project_company')->where('project_id', $this->project->id)->where('company_id', $this->buyer->company_id)->delete());
        $this->assertNull(app(PipingCustodyService::class)->policyId($this->project, $this->qc));
        DB::table('project_company')->insert($participation);
        $this->assertNotNull(app(PipingCustodyService::class)->policyId($this->project, $this->qc));
        app(TenantContext::class)->forIdentityLookup(fn () => User::whereKey($this->buyer->id)->update(['company_id' => $this->qc->company_id]));
        $moved = app(TenantContext::class)->forIdentityLookup(fn () => User::whereKey($this->buyer->id)->firstOrFail());
        $this->assertNull(app(PipingCustodyService::class)->policyId($this->project, $moved, 'custody_mrr'));
    }

    public function test_forward_projection_install_over_existing_configuration_does_not_create_receipts_or_defaults(): void
    {
        DB::statement('DROP TRIGGER piping_nomination_project ON piping_receipt_author_policies');
        foreach (['piping_custody_records', 'piping_custody_counters', 'piping_receipt_nomination_index'] as $table) {
            DB::statement('DROP TABLE '.$table.' CASCADE');
        }
        DB::table('migrations')->where('migration', '2026_10_22_110000_add_piping_physical_custody')->delete();
        $this->configure();
        $before = $this->tenant($this->owner, fn () => DB::table('piping_receipt_author_policies')->get()->toJson());
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertNotNull(app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'));
        $this->assertCount(0, $this->records($this->buyer));
        $this->assertSame($before, $this->tenant($this->owner, fn () => DB::table('piping_receipt_author_policies')->get()->toJson()));
        $this->assertNull(app(PipingCustodyService::class)->policyId($this->project, $this->owner));
    }

    /** Real worker connections observed blocked on the shared advisory domain. */
    private function blockedWorkers(array $payload, int $count, callable $whileBlocked, ?string $sourceToLock = null): array
    {
        $script = tempnam(sys_get_temp_dir(), 'epcc-custody-worker-');
        $appPath = base_path();
        file_put_contents($script, '<?php require '.var_export($appPath.'/vendor/autoload.php', true).'; $app=require '.var_export($appPath.'/bootstrap/app.php', true).'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); '.<<<'WORKER'
if(config('database.connections.pgsql.database')!=='epcc_test' || !str_starts_with((string)config('database.connections.pgsql.host'),'/tmp/epcc-')) { throw new RuntimeException('Refusing non-isolated custody worker'); }
Illuminate\Support\Facades\DB::select("SELECT set_config('application_name',?,false)",[$argv[4]]);
$project=App\Models\Project::whereKey($argv[1])->firstOrFail();
$actor=app(App\Support\TenantContext::class)->forIdentityLookup(fn()=>App\Models\User::whereKey($argv[2])->firstOrFail());
try {
 $id=app(App\Services\PipingCustodyService::class)->create($project,$actor,json_decode(base64_decode($argv[3]),true,512,JSON_THROW_ON_ERROR));
 echo json_encode(['id'=>$id]);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
 echo json_encode(['status'=>$e->getStatusCode()]);
} catch (Throwable $e) { fwrite(STDERR,(string)$e); exit(1); }
WORKER);
        $workers = [];
        $prefix = 'custody-'.Str::random(10);
        try {
            DB::beginTransaction();
            if ($sourceToLock === null) {
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['piping-receipt-access:'.$this->project->id]);
            } else {
                $this->tenant($this->buyer, fn () => DB::select('SELECT id FROM purchase_orders WHERE id=? FOR UPDATE', [$sourceToLock]));
            }

            for ($i = 0; $i < $count; $i++) {
                $p = new Process([PHP_BINARY, $script, $this->project->id, $this->buyer->id, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), $prefix.'-'.$i], $appPath, ['APP_ENV' => 'testing', 'APP_BASE_PATH' => $appPath, 'DB_DATABASE' => 'epcc_test', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'), 'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password')]);
                $p->setTimeout(20);
                $p->start();
                $workers[] = $p;
            }
            $deadline = microtime(true) + 10;
            do {
                $blocked = (int) DB::selectOne("WITH k AS (SELECT hashtextextended(?,0) v) SELECT count(*) AS n FROM pg_locks,k WHERE locktype='advisory' AND NOT granted AND classid::bigint=((v >> 32) & 4294967295) AND objid::bigint=(v & 4294967295) AND objsubid=1", ['piping-receipt-access:'.$this->project->id])->n;
                if ($sourceToLock !== null) {
                    DB::select('SELECT pg_stat_clear_snapshot()');
                    $blocked = (int) DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type='Lock' AND pg_backend_pid()=ANY(pg_blocking_pids(pid))", [$prefix.'%'])->n;
                }
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

    public function test_real_concurrent_duplicate_is_single_creditless_record_and_revocation_while_waiting_denies_action(): void
    {
        $this->configure();
        $results = $this->blockedWorkers($this->payload(), 2, fn () => null);
        $this->assertSame($results[0]['id'], $results[1]['id']);
        $this->assertCount(1, $this->records($this->buyer));
        $this->assertSame(1, $this->records($this->buyer)->sole()->sequence);
        $results = $this->blockedWorkers($this->payload(), 1, fn () => $this->configure(['version' => 1, 'suspend' => true, 'reason' => 'Revoke while real worker waits']));
        $this->assertSame(403, $results[0]['status']);
        $this->configure($this->nomination(2));
        $this->assertCount(1, $this->records($this->buyer));
    }

    public function test_form_recovery_preserves_request_policy_and_unavailable_exact_source_without_cross_project_restore(): void
    {
        $this->configure();
        $old = $this->payload();
        $old['source_line_id'] = (string) Str::uuid();
        $old['shipment_ref'] = '<script>old-input()</script>';
        $old['unlinked_reason'] = null;
        $this->actingAs($this->buyer)->withSession(['_old_input' => ['custody_project' => $this->project->id, 'receipt' => $old]])->get($this->url)->assertOk()
            ->assertSee('value="'.$old['source_line_id'].'" selected', false)->assertSee($old['request_id'])->assertSee($old['expected_policy_id'])
            ->assertDontSee('<script>old-input()</script>', false)->assertSee('&lt;script&gt;old-input()&lt;/script&gt;', false);
        $this->withSession(['_old_input' => ['custody_project' => (string) Str::uuid(), 'receipt' => $old]])->get($this->url)->assertOk()->assertDontSee($old['request_id'])->assertDontSee('old-input()');
    }

    public function test_hidden_existing_request_collision_is_generic_validation_not_sql_detail_or_snapshot(): void
    {
        $this->configure();
        $line = $this->line();
        $p = $this->payload();
        $p['source_line_id'] = $line->id;
        $p['unit'] = $line->unit;
        $p['unlinked_reason'] = null;
        $this->submit($p);
        $n = $this->nomination(1);
        $n['actions']['custody_mrr'] = ['user:'.$this->buyer->id, 'user:'.$this->qc->id];
        $this->configure($n);
        DB::table('companies')->where('id', $this->qc->company_id)->update(['tenant_type' => 'TPI']);
        $p['expected_policy_id'] = app(PipingCustodyService::class)->policyId($this->project, $this->qc, 'custody_mrr');
        $p['source_line_id'] = null;
        $p['unlinked_reason'] = 'Unresolved current source';
        $this->actingAs($this->qc)->postJson(str_replace('lang=en', 'lang=fa', $this->url), ['receipt' => $p])->assertUnprocessable()->assertDontSee('SQLSTATE')->assertDontSee($line->number);
        $this->assertCount(0, $this->records($this->qc));
        $this->assertCount(1, $this->records($this->buyer));
    }

    public function test_role_revocation_during_real_source_row_lock_wait_is_rechecked_before_custody_insert(): void
    {
        $line = $this->line();
        $role = $this->tenant($this->buyer, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->buyer->id)->first()->role_codes[0]);
        $n = $this->nomination();
        $n['actions']['custody_mrr'] = ['role:'.$this->buyer->company_id.':'.$role];
        $this->configure($n);
        $p = $this->payload();
        $p['source_line_id'] = $line->id;
        $p['unit'] = $line->unit;
        $p['unlinked_reason'] = null;
        $result = $this->blockedWorkers($p, 1, fn () => $this->tenant($this->buyer, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->buyer->id)->update(['role_codes' => '[]'])), $line->purchase_order_id);
        $this->assertSame(403, $result[0]['status']);
        $this->assertCount(0, $this->records($this->buyer));
    }
}
