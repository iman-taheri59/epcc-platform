<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Plan;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PurchaseOrder;
use App\Services\MaterialRequisitionAccessService;
use App\Services\MaterialRequisitionEntryService;
use App\Services\ProcurementService;
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
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseOrderExecutionTest extends TestCase
{
    private $owner;

    private $writer;

    private $finance;

    private $issuer;

    private Project $project;

    private string $buyer;

    private PurchaseOrderExecutionAccessService $access;

    private string $url = '/projects/PH-14/procurement/order-execution-access?lang=en';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $this->finance = $this->userByEmail('tehrani@pmc.example');
        $this->issuer = $this->userByEmail('sadeghi@pars.example');
        $this->buyer = $this->writer->company_id;
        $this->access = app(PurchaseOrderExecutionAccessService::class);
    }

    private function bound(callable $fn, $actor = null): mixed
    {
        return app(TenantContext::class)->runAs(($actor ?? $this->owner)->company_id, $fn, $this->project->id);
    }

    private function payload(array $changes = []): array
    {
        return array_replace(['buyer_company_id' => $this->buyer, 'version' => 0, 'finance_selectors' => ['user:'.$this->finance->id],
            'issue_selectors' => ['user:'.$this->issuer->id], 'suspend' => false, 'reason' => 'Explicit target-buyer financial and issue nominations'], $changes);
    }

    private function configure(array $changes = []): void
    {
        $this->access->configure($this->project, $this->owner, $this->payload($changes));
    }

    private function nominee($actor, string $purpose, ?string $buyer = null, ?string $disc = 'PIPING'): ?string
    {
        return $this->access->nominationId($this->project, $actor, $buyer ?? $this->buyer, $purpose, $disc);
    }

    private function draft(array $changes = []): PurchaseOrder
    {
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, ['version' => 0, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'Execution fixture']);
        $this->configure();
        $vendor = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id);

        return app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, array_replace([
            'title_en' => 'Version-bound commercial PO', 'title_fa' => 'سفارش نسخه‌دار', 'discipline' => 'PIPING', 'material_category' => 'PIPE',
            'vendor_company_id' => $vendor, 'currency' => 'USD', 'lines' => [['item_code' => 'EXEC-PIPE', 'description_en' => 'Reviewed pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '2.345', 'unit_price' => '10.01']],
        ], $changes));
    }

    private function executionUrl(PurchaseOrder $po, ?string $purpose = null): string
    {
        return '/projects/PH-14/procurement/execution/'.$this->buyer.'/'.$po->id.($purpose ? '/'.$purpose : '').'?lang=en';
    }

    private function decision(PurchaseOrder $po): array
    {
        return $this->bound(fn () => [
            'content_hash' => app(PurchaseOrderExecutionService::class)->hash($po),
            'reason' => 'Reviewed exact commercial version',
        ], $this->writer);
    }

    private function latest(PurchaseOrder $po): object
    {
        return $this->bound(fn () => DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->orderByDesc('sequence')->first(), $this->writer);
    }

    public function test_financial_approval_keeps_draft_then_three_people_issue_the_exact_version(): void
    {
        $po = $this->draft();
        $input = $this->decision($po);
        $this->actingAs($this->finance)->get($this->executionUrl($po))->assertOk()->assertSee('EXEC-PIPE')->assertSee('Approve this financial version');
        $this->postJson($this->executionUrl($po, 'finance'), ['execution' => $input])->assertRedirect()->assertSessionHasNoErrors();
        $finance = $this->latest($po);
        $this->assertSame('finance', $finance->purpose);
        $this->assertSame($this->finance->id, $finance->actor_id);
        $this->assertSame('23.47', $this->bound(fn () => $po->fresh()->total_value, $this->writer));
        $this->assertSame('DRAFT', $this->bound(fn () => $po->fresh()->state, $this->writer));
        $this->assertNull($this->bound(fn () => $po->fresh()->issued_by, $this->writer));
        $snapshot = json_decode($finance->snapshot, true);
        $this->assertSame(23.47, $snapshot['header']['total_value']);
        $this->assertCount(1, $snapshot['lines']);
        $this->actingAs($this->issuer)->get($this->executionUrl($po))->assertOk()->assertSee('Issue this approved version');
        $this->postJson($this->executionUrl($po, 'issue'), ['execution' => $input + ['approval_id' => $finance->id]])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('ISSUED', $this->bound(fn () => $po->fresh()->state, $this->writer));
        $this->assertSame($this->issuer->id, $this->bound(fn () => $po->fresh()->issued_by, $this->writer));
        $this->assertSame('issue', $this->latest($po)->purpose);
        $this->assertSame($finance->id, $this->latest($po)->approval_id);
    }

    private function approve(PurchaseOrder $po): array
    {
        $input = $this->decision($po);
        $this->actingAs($this->finance)->postJson($this->executionUrl($po, 'finance'), ['execution' => $input])->assertRedirect()->assertSessionHasNoErrors();

        return $input + ['approval_id' => $this->latest($po)->id];
    }

    private function assertSqlRefused(callable $fn, string $code): void
    {
        try {
            $this->bound(fn () => DB::transaction($fn), $this->writer);
            $this->fail('Expected SQL refusal '.$code);
        } catch (QueryException $e) {
            $this->assertStringContainsString($code, $e->getMessage());
        }
    }

    public function test_hidden_issue_journal_never_unfreezes_visible_order_content_for_nonbuyers(): void
    {
        $po = $this->draft();
        $approval = $this->approve($po);
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $approval])->assertRedirect()->assertSessionHasNoErrors();
        $before = $this->bound(fn () => [$po->fresh()->toArray(), $po->items()->get()->toArray(), DB::table('po_execution_decisions')->orderBy('sequence')->get()->map(fn ($row) => (array) $row)->all()], $this->writer);
        $vendor = (object) ['company_id' => $po->vendor_company_id];
        foreach ([$this->owner, $this->finance, $vendor] as $actor) {
            $this->bound(function () use ($po, $actor): void {
                $this->assertSame(1, DB::table('purchase_orders')->where('id', $po->id)->count());
                $this->assertSame(1, $po->items()->count());
                $this->assertSame(0, DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->count());
                $line = (array) DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->first();
                $operations = [
                    fn () => DB::table('purchase_order_items')->where('id', $line['id'])->update(['unit_price' => 99]),
                    fn () => DB::table('purchase_order_items')->insert(array_replace($line, ['id' => (string) Str::uuid(), 'line_no' => 2])),
                    fn () => DB::table('purchase_order_items')->where('id', $line['id'])->delete(),
                    fn () => DB::table('purchase_orders')->where('id', $po->id)->update(['title_en' => 'Forbidden commercial mutation']),
                ];
                foreach ($operations as $operation) {
                    try {
                        DB::transaction($operation);
                        $this->fail('Hidden issue evidence must not mean mutable commercial content');
                    } catch (QueryException $e) {
                        $this->assertStringContainsString('PO_EXEC_ISSUED_CONTENT_FROZEN', $e->getMessage());
                    }
                    $this->assertSame($actor->company_id, DB::scalar("SELECT current_setting('epcc.company_id')"));
                    $this->assertSame($this->project->id, DB::scalar("SELECT current_setting('epcc.project_id')"));
                    $this->assertSame(0, DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->count());
                }
            }, $actor);
        }
        $after = $this->bound(fn () => [$po->fresh()->toArray(), $po->items()->get()->toArray(), DB::table('po_execution_decisions')->orderBy('sequence')->get()->map(fn ($row) => (array) $row)->all()], $this->writer);
        $this->assertSame($before, $after);
    }

    public function test_integrity_helper_is_trigger_only_and_does_not_delegate_journal_reads(): void
    {
        $po = $this->draft();
        $approval = $this->approve($po);
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $approval])->assertRedirect();
        $this->bound(function () use ($po): void {
            try {
                DB::transaction(fn () => DB::select('SELECT epcc_po_issued_guard_basis(?)', [$po->id]));
                $this->fail('Integrity helper must not be a report API');
            } catch (QueryException $e) {
                $this->assertStringContainsString('PO_EXEC_GUARD_TRIGGER_ONLY', $e->getMessage());
                $this->assertSame('55000', $e->errorInfo[0]);
            }
            $this->assertSame($this->owner->company_id, DB::scalar("SELECT current_setting('epcc.company_id')"));
            $this->assertSame($this->project->id, DB::scalar("SELECT current_setting('epcc.project_id')"));
            $this->assertSame(0, DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->count());
        });
        $this->assertFalse((bool) DB::scalar("SELECT prosecdef FROM pg_proc WHERE proname='epcc_po_issued_guard_basis'"));
    }

    private function installGuardProbe(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TEMP TABLE epcc_test_guard_probe(oid uuid, found boolean, company_after text, project_after text);
CREATE OR REPLACE FUNCTION epcc_test_guard_probe_fn() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 NEW.found:=epcc_po_issued_guard_basis(NEW.oid) IS NOT NULL;
 NEW.company_after:=current_setting('epcc.company_id',true);
 NEW.project_after:=current_setting('epcc.project_id',true);
 RETURN NEW;
END $$;
CREATE TRIGGER epcc_test_guard_probe BEFORE INSERT ON epcc_test_guard_probe FOR EACH ROW EXECUTE FUNCTION epcc_test_guard_probe_fn();
SQL);
    }

    private function removeGuardProbe(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS epcc_test_guard_probe; DROP FUNCTION IF EXISTS epcc_test_guard_probe_fn();');
    }

    public function test_trigger_integrity_lookup_caps_original_visibility_and_restores_scope_on_success(): void
    {
        $po = $this->draft();
        $approval = $this->approve($po);
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $approval])->assertRedirect();
        $this->installGuardProbe();
        try {
            $cases = [
                [$this->owner->company_id, $this->project->id, true],
                [$this->finance->company_id, $this->project->id, true],
                [$po->vendor_company_id, $this->project->id, true],
                [$this->owner->company_id, '', true],
                [$this->owner->company_id, (string) Str::uuid(), false],
                ['', $this->project->id, false],
                [(string) Str::uuid(), $this->project->id, false],
            ];
            foreach ($cases as [$company, $project, $expected]) {
                DB::transaction(function () use ($company, $project, $expected, $po): void {
                    DB::select("SELECT set_config('epcc.company_id',?,true),set_config('epcc.project_id',?,true)", [$company, $project]);
                    $row = DB::selectOne('INSERT INTO epcc_test_guard_probe(oid) VALUES (?) RETURNING *', [$po->id]);
                    $this->assertSame($expected, $row->found);
                    $this->assertSame($company, $row->company_after);
                    $this->assertSame($project, $row->project_after);
                    $this->assertSame(0, DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->count());
                });
            }
        } finally {
            $this->removeGuardProbe();
        }
    }

    public function test_guard_lookup_restores_scope_when_journal_query_raises_inside_trigger(): void
    {
        $po = $this->draft();
        $approval = $this->approve($po);
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $approval])->assertRedirect();
        $this->installGuardProbe();
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_test_guard_lookup_failure() RETURNS boolean LANGUAGE plpgsql AS $$
BEGIN RAISE EXCEPTION 'PO_EXEC_TEST_LOOKUP_FAILURE' USING ERRCODE='23514'; END $$;
CREATE POLICY epcc_test_guard_lookup_failure ON po_execution_decisions AS RESTRICTIVE FOR SELECT USING (epcc_test_guard_lookup_failure());
CREATE OR REPLACE FUNCTION epcc_test_guard_probe_fn() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 BEGIN
  PERFORM epcc_po_issued_guard_basis(NEW.oid);
  RAISE EXCEPTION 'Expected injected lookup refusal';
 EXCEPTION WHEN check_violation THEN NEW.found:=false;
 END;
 NEW.company_after:=current_setting('epcc.company_id',true);
 NEW.project_after:=current_setting('epcc.project_id',true);
 RETURN NEW;
END $$;
SQL);
        try {
            $this->bound(function () use ($po): void {
                $row = DB::selectOne('INSERT INTO epcc_test_guard_probe(oid) VALUES (?) RETURNING *', [$po->id]);
                $this->assertFalse($row->found);
                $this->assertSame($this->owner->company_id, $row->company_after);
                $this->assertSame($this->project->id, $row->project_after);
            });
        } finally {
            DB::unprepared('DROP POLICY epcc_test_guard_lookup_failure ON po_execution_decisions; DROP FUNCTION epcc_test_guard_lookup_failure();');
            $this->removeGuardProbe();
        }
        $this->bound(fn () => $this->assertSame(0, DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->count()));
    }

    public function test_freeze_checks_both_old_and_new_parents_when_a_line_is_reparented(): void
    {
        $po = $this->draft();
        $approval = $this->approve($po);
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $approval])->assertRedirect();
        $other = $this->bound(fn () => app(ProcurementService::class)->createPurchaseOrder($this->project, $this->writer,
            Company::findOrFail($po->vendor_company_id), 'Legacy draft target', 'قدیمی', [['item_code' => 'LEGACY', 'description_en' => 'Legacy', 'description_fa' => 'قدیمی', 'unit' => 'M', 'quantity' => 1]], lang: 'en'), $this->writer);
        $this->bound(function () use ($po, $other): void {
            foreach ([[$po, $other], [$other, $po]] as [$from, $to]) {
                $line = $from->items()->firstOrFail();
                try {
                    DB::transaction(fn () => DB::table('purchase_order_items')->where('id', $line->id)->update(['purchase_order_id' => $to->id, 'line_no' => 50]));
                    $this->fail('Both sides of reparenting must respect issue freeze');
                } catch (QueryException $e) {
                    $this->assertStringContainsString('PO_EXEC_ISSUED_CONTENT_FROZEN', $e->getMessage());
                }
                $this->assertSame($from->id, $line->fresh()->purchase_order_id);
            }
        });
    }

    public function test_integrity_migration_preserves_archived_populated_orders_and_evidence(): void
    {
        $paths = array_values(array_filter(glob(database_path('migrations/*.php')), fn ($p) => basename($p) < '2026_10_19_180000'));
        $this->artisan('migrate:fresh', ['--force' => true, '--path' => $paths, '--realpath' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->writer = $this->userByEmail('rezaei@pars.example');
        $this->finance = $this->userByEmail('tehrani@pmc.example');
        $this->issuer = $this->userByEmail('sadeghi@pars.example');
        $this->buyer = $this->writer->company_id;
        $po = $this->draft();
        $approval = $this->approve($po);
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $approval])->assertRedirect();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Guard upgrade data-preservation fixture');
        $snapshot = fn () => $this->bound(function (): array {
            $result = [];
            foreach (['purchase_orders', 'purchase_order_items', 'po_execution_policies', 'po_execution_decisions', 'po_pricing_revisions', 'po_mr_allocations'] as $table) {
                $result[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            }

            return $result;
        }, $this->writer);
        $before = $snapshot();
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame($before, $snapshot());
        $this->assertSame(2, $this->bound(fn () => DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->count(), $this->writer));
        $this->bound(fn () => $this->assertSame(0, DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->count()));
    }

    public function test_stale_form_and_changed_header_require_renewed_financial_approval(): void
    {
        $po = $this->draft();
        $old = $this->approve($po);
        $this->bound(fn () => DB::table('purchase_orders')->where('id', $po->id)->update(['title_en' => 'Changed terms']), $this->writer);
        $this->actingAs($this->finance)->postJson($this->executionUrl($po, 'finance'), ['execution' => array_diff_key($old, ['approval_id' => true])])->assertUnprocessable()->assertSee('PO_EXEC_STALE_CONTENT');
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $old])->assertUnprocessable()->assertSee('PO_EXEC_STALE_CONTENT');
        $this->postJson($this->executionUrl($po, 'issue'), ['execution' => $this->decision($po) + ['approval_id' => $old['approval_id']]])->assertUnprocessable()->assertSee('PO_EXEC_APPROVAL_STALE');
        $fresh = $this->approve($po);
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $fresh])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3, $this->latest($po)->sequence);
    }

    public function test_same_hash_but_superseded_approval_and_policy_version_are_rejected(): void
    {
        $po = $this->draft();
        $old = $this->approve($po);
        $latest = $this->approve($po);
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $old])->assertUnprocessable()->assertSee('PO_EXEC_APPROVAL_STALE');
        $this->configure(['version' => 1, 'reason' => 'Same selectors, new authority version']);
        $this->postJson($this->executionUrl($po, 'issue'), ['execution' => $latest])->assertUnprocessable()->assertSee('PO_EXEC_APPROVAL_STALE');
        $fresh = $this->approve($po);
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $fresh])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_current_financial_signer_must_remain_active_and_in_discipline_and_plan(): void
    {
        $po = $this->draft();
        $input = $this->approve($po);
        $membership = $this->bound(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->finance->id)->firstOrFail());
        foreach (['inactive', 'discipline', 'plan', 'membership'] as $case) {
            $this->bound(function () use ($case): void {
                if ($case === 'inactive') {
                    app(TenantContext::class)->forIdentityLookup(fn () => DB::table('users')->where('id', $this->finance->id)->update(['is_active' => false]));
                }
                if ($case === 'discipline') {
                    ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->finance->id)->update(['discipline_scope' => ['CIVIL']]);
                }
                if ($case === 'plan') {
                    Company::whereKey($this->finance->company_id)->update(['plan_id' => Plan::where('code', 'starter')->firstOrFail()->id]);
                }
                if ($case === 'membership') {
                    ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->finance->id)->delete();
                }
            });
            $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $input])->assertUnprocessable()->assertSee('PO_EXEC_APPROVAL_STALE');
            $this->bound(function () use ($case, $membership): void {
                if ($case === 'inactive') {
                    app(TenantContext::class)->forIdentityLookup(fn () => DB::table('users')->where('id', $this->finance->id)->update(['is_active' => true]));
                }
                if ($case === 'discipline') {
                    ProjectMembership::whereKey($membership->id)->update(['discipline_scope' => null]);
                }
                if ($case === 'plan') {
                    Company::whereKey($this->finance->company_id)->update(['plan_id' => Plan::where('code', 'full')->firstOrFail()->id]);
                }
                if ($case === 'membership') {
                    DB::table('project_memberships')->insert($membership->getRawOriginal());
                }
            });
        }
        $this->postJson($this->executionUrl($po, 'issue'), ['execution' => $input])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_nominees_cannot_combine_drafter_financial_and_issue_people(): void
    {
        $po = $this->draft();
        $this->configure(['version' => 1, 'finance_selectors' => ['user:'.$this->writer->id, 'user:'.$this->finance->id], 'issue_selectors' => ['user:'.$this->writer->id, 'user:'.$this->finance->id, 'user:'.$this->issuer->id]]);
        $input = $this->decision($po);
        $this->actingAs($this->writer)->postJson($this->executionUrl($po, 'finance'), ['execution' => $input])->assertForbidden();
        $issue = $this->approve($po);
        foreach ([$this->writer, $this->finance] as $person) {
            $this->actingAs($person)->postJson($this->executionUrl($po, 'issue'), ['execution' => $issue])->assertForbidden();
        }
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $issue])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_unpriced_and_mismatched_exact_totals_are_not_approved(): void
    {
        $po = $this->draft(['currency' => null, 'lines' => [['item_code' => 'N', 'description_en' => 'No price', 'description_fa' => 'بی‌قیمت', 'unit' => 'M', 'quantity' => '1.000']]]);
        $this->actingAs($this->finance)->postJson($this->executionUrl($po, 'finance'), ['execution' => $this->decision($po)])->assertUnprocessable()->assertSee('PO_EXEC_PRICING');
        $this->bound(function () use ($po): void {
            DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->update(['unit_price' => '1.01']);
            DB::table('purchase_orders')->where('id', $po->id)->update(['total_value' => '1.02', 'currency' => 'USD']);
        }, $this->writer);
        $this->postJson($this->executionUrl($po, 'finance'), ['execution' => $this->decision($po)])->assertUnprocessable()->assertSee('PO_EXEC_PRICING');
        $this->bound(fn () => DB::table('purchase_orders')->where('id', $po->id)->update(['total_value' => '1.01']), $this->writer);
        $this->approve($po);
    }

    public function test_scoped_workspace_does_not_delegate_generic_detail_other_buyer_or_warehouse(): void
    {
        $po = $this->draft();
        $counterparty = $this->userByEmail('mousavi@sahand.example');
        $this->bound(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $counterparty->id)->update(['discipline_scope' => null]));
        $this->configure(['version' => 1, 'finance_selectors' => ['user:'.$counterparty->id]]);
        $this->actingAs($this->writer)->get($this->executionUrl($po))->assertForbidden();
        $this->actingAs($counterparty)->get($this->executionUrl($po))->assertOk()->assertSee('EXEC-PIPE');
        $this->get('/projects/PH-14/procurement/orders/'.$po->id.'?lang=en')->assertNotFound();
        $this->get('/projects/PH-14/materials?lang=en')->assertOk()->assertDontSee('EXEC-PIPE');
        $this->get(str_replace($this->buyer, $counterparty->company_id, $this->executionUrl($po)))->assertForbidden();
        $this->get(str_replace('PH-14', 'BB-2', $this->executionUrl($po)))->assertNotFound();
        $this->bound(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $counterparty->id)->update(['discipline_scope' => ['CIVIL']]));
        $this->get($this->executionUrl($po))->assertNotFound();
    }

    public function test_financial_role_revocation_blocks_issue_without_policy_edit(): void
    {
        $po = $this->draft();
        $role = $this->bound(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->finance->id)->firstOrFail()->role_codes[0]);
        $this->configure(['version' => 1, 'finance_selectors' => ['role:'.$this->finance->company_id.':'.$role]]);
        $input = $this->approve($po);
        $this->bound(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $this->finance->id)->update(['role_codes' => []]));
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $input])->assertUnprocessable()->assertSee('PO_EXEC_APPROVAL_STALE');
    }

    public function test_archive_or_revoked_issuer_grant_blocks_action_and_delegated_read(): void
    {
        $po = $this->draft();
        $input = $this->approve($po);
        $this->configure(['version' => 1, 'issue_selectors' => []]);
        $this->actingAs($this->issuer)->get($this->executionUrl($po))->assertForbidden();
        $this->postJson($this->executionUrl($po, 'issue'), ['execution' => $input])->assertForbidden();
        app(ProjectArchiveService::class)->archive($this->project, $this->owner, 'Execution test archive');
        $this->actingAs($this->finance)->get($this->executionUrl($po))->assertForbidden();
        $this->postJson($this->executionUrl($po, 'finance'), ['execution' => array_diff_key($input, ['approval_id' => true])])->assertUnprocessable();
        $this->assertSame('finance', $this->latest($po)->purpose);
    }

    public function test_journal_is_append_only_and_issued_commercial_data_frozen_but_progress_is_not(): void
    {
        $po = $this->draft();
        $input = $this->approve($po);
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $input])->assertRedirect()->assertSessionHasNoErrors();
        $this->postJson($this->executionUrl($po, 'issue'), ['execution' => $input])->assertUnprocessable();
        $this->bound(function () use ($po): void {
            $this->assertSame(0, DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->update(['reason' => 'Rewrite']));
            $this->assertSame(0, DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->delete());
            DB::table('purchase_orders')->where('id', $po->id)->update(['forecast_date' => '2027-01-01', 'state' => 'PARTIALLY_RECEIVED']);
        }, $this->writer);
        $this->assertSqlRefused(fn () => DB::table('purchase_orders')->where('id', $po->id)->update(['total_value' => '0.00']), 'PO_EXEC_ISSUED_CONTENT_FROZEN');
        $this->assertSqlRefused(fn () => DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->update(['quantity' => '9.000']), 'PO_EXEC_ISSUED_CONTENT_FROZEN');
        $this->assertSqlRefused(fn () => DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->delete(), 'PO_EXEC_ISSUED_CONTENT_FROZEN');
        $this->assertSqlRefused(fn () => DB::table('purchase_orders')->where('id', $po->id)->update(['issued_by' => $this->writer->id]), 'PO_EXEC_ISSUE_BASIS_IMMUTABLE');
        $this->assertSqlRefused(fn () => DB::table('purchase_orders')->where('id', $po->id)->update(['state' => 'CANCELLED']), 'PO_EXEC_CANCELLATION_PENDING');
        $this->assertSame($input['content_hash'], $this->decision($po)['content_hash']);
    }

    public function test_sql_refuses_human_direct_issue_and_journal_only_issue_rolls_back(): void
    {
        $po = $this->draft();
        $input = $this->approve($po);
        foreach (['ISSUED', 'PARTIALLY_RECEIVED', 'RECEIVED'] as $state) {
            $this->assertSqlRefused(fn () => DB::table('purchase_orders')->where('id', $po->id)->update(['state' => $state]), 'PO_EXEC_ISSUE_DECISION_REQUIRED');
        }
        $data = (array) $this->latest($po);
        $data['id'] = (string) Str::uuid();
        $data['purpose'] = 'issue';
        $data['actor_id'] = $this->issuer->id;
        $data['actor_company_id'] = $this->issuer->company_id;
        $data['approval_id'] = $input['approval_id'];
        $this->assertSqlRefused(function () use ($data): void {
            DB::table('po_execution_decisions')->insert($data);
            DB::statement('SET CONSTRAINTS po_issue_applied IMMEDIATE');
        }, 'PO_EXEC_ISSUE_NOT_APPLIED');
        $this->assertSame('finance', $this->latest($po)->purpose);
        $this->assertSame('DRAFT', $this->bound(fn () => $po->fresh()->state, $this->writer));
    }

    public function test_superseded_line_history_changes_fingerprint_and_is_retained(): void
    {
        $po = $this->draft();
        $old = $this->approve($po);
        $this->bound(function () use ($po): void {
            $line = (array) DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->first();
            $line['supersedes_id'] = $line['id'];
            $line['id'] = (string) Str::uuid();
            $line['line_no'] = 2;
            DB::table('purchase_order_items')->insert($line);
        }, $this->writer);
        $this->assertNotSame($old['content_hash'], $this->decision($po)['content_hash']);
        $new = $this->approve($po);
        $this->assertCount(2, json_decode($this->latest($po)->snapshot, true)['lines']);
        $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $new])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_bilingual_workspace_escapes_notes_and_rejects_forged_fields(): void
    {
        $po = $this->draft();
        $input = $this->decision($po);
        $this->actingAs($this->finance)->postJson($this->executionUrl($po, 'finance'), ['execution' => $input + ['actor_id' => $this->owner->id]])->assertUnprocessable();
        $this->postJson($this->executionUrl($po, 'finance'), ['execution' => array_replace($input, ['reason' => ' '])])->assertUnprocessable();
        $this->postJson($this->executionUrl($po, 'finance'), ['execution' => array_replace($input, ['reason' => '<script>attack()</script>'])])->assertRedirect()->assertSessionHasNoErrors();
        $this->get($this->executionUrl($po))->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>attack()</script>', false);
        $this->get(str_replace('lang=en', 'lang=fa', $this->executionUrl($po)))->assertOk()->assertSee('کارتابل تأیید مالی و صدور PO');
    }

    public function test_mr_linked_draft_requires_controlled_pricing_not_trusted_raw_prices(): void
    {
        $this->draft();
        app(MaterialRequisitionAccessService::class)->configure($this->project, $this->owner, ['version' => 0, 'selectors' => ['user:'.$this->writer->id], 'suspend' => false, 'reason' => 'MR source fixture']);
        $mr = app(MaterialRequisitionEntryService::class)->create($this->project, $this->writer, [
            'title_en' => 'Source MR', 'title_fa' => 'منبع', 'discipline' => 'PIPING', 'material_category' => 'PIPE',
            'lines' => [['item_code' => 'SRC', 'description_en' => 'Source pipe', 'description_fa' => 'لوله منبع', 'unit' => 'M', 'quantity' => '2.000']],
        ]);
        $this->bound(function () use (&$mr): void {
            $svc = app(ProcurementService::class);
            $svc->submitRequisition($mr, $this->writer);
            $svc->approveTechnically($mr->fresh(), $this->finance);
            $mr = $svc->approveCommercially($mr->fresh(), $this->owner);
        }, $this->writer);
        $before = $this->bound(fn () => [$mr->fresh()->toArray(), $mr->items()->get()->toArray()], $this->writer);
        $vendor = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id);
        $po = app(PurchaseOrderEntryService::class)->createFromRequisition($this->project, $this->writer, ['requisition_id' => $mr->id, 'vendor_company_id' => $vendor]);
        $this->bound(function () use ($po): void {
            DB::table('purchase_orders')->where('id', $po->id)->update(['total_value' => '20.00', 'currency' => 'USD']);
            DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->update(['unit_price' => '10.00']);
        }, $this->writer);
        $this->actingAs($this->finance)->postJson($this->executionUrl($po, 'finance'), ['execution' => $this->decision($po)])->assertUnprocessable()->assertSee('PO_EXEC_MR_PRICING_REQUIRED');
        $this->assertSame($before, $this->bound(fn () => [$mr->fresh()->toArray(), $mr->items()->get()->toArray()], $this->writer));
    }

    public function test_unattributed_draft_cannot_be_financially_certified_and_journal_read_is_buyer_scoped(): void
    {
        $po = $this->draft();
        $this->approve($po);
        $this->bound(fn () => $this->assertSame(0, DB::table('po_execution_decisions')->count()), $this->finance);
        app(TenantContext::class)->runAs($this->buyer, fn () => $this->assertSame(0, DB::table('po_execution_decisions')->count()));
        $po = $this->bound(fn () => app(ProcurementService::class)->createPurchaseOrder($this->project, $this->writer, Company::where('code', 'VEN-AZAR')->firstOrFail(), 'Legacy', 'قدیمی', [
            ['item_code' => 'OLD', 'description_en' => 'Old pipe', 'description_fa' => 'قدیمی', 'quantity' => '2.000', 'unit' => 'M', 'unit_price' => '10.00'],
        ], null, 'USD'), $this->writer);
        $this->actingAs($this->finance)->postJson($this->executionUrl($po, 'finance'), ['execution' => $this->decision($po)])->assertUnprocessable()->assertSee('PO_EXEC_NOT_ELIGIBLE');
        $this->get($this->executionUrl($po))->assertNotFound();
    }

    public function test_issue_header_failure_rolls_back_its_journal_insert(): void
    {
        $po = $this->draft();
        $input = $this->approve($po);
        DB::unprepared("CREATE OR REPLACE FUNCTION epcc_test_issue_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.state='ISSUED' THEN RAISE EXCEPTION 'PO_EXEC_TEST_ATOMIC_REFUSAL' USING ERRCODE='23514'; END IF; RETURN NEW; END $$; CREATE TRIGGER zzzz_test_issue_failure BEFORE UPDATE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION epcc_test_issue_failure();");
        try {
            $this->actingAs($this->issuer)->postJson($this->executionUrl($po, 'issue'), ['execution' => $input])->assertUnprocessable()->assertSee('PO_EXEC_TEST_ATOMIC_REFUSAL');
            $this->assertSame('finance', $this->latest($po)->purpose);
            $this->assertSame('DRAFT', $this->bound(fn () => $po->fresh()->state, $this->writer));
        } finally {
            DB::unprepared('DROP TRIGGER zzzz_test_issue_failure ON purchase_orders; DROP FUNCTION epcc_test_issue_failure();');
        }
    }

    public function test_execution_list_retains_category_separation(): void
    {
        $pipe = $this->draft();
        $flange = app(PurchaseOrderEntryService::class)->create($this->project, $this->writer, [
            'title_en' => 'Separate flange order', 'title_fa' => 'فلنج جداگانه', 'discipline' => 'PIPING', 'material_category' => 'FLANGE',
            'vendor_company_id' => $pipe->vendor_company_id, 'currency' => 'USD',
            'lines' => [['item_code' => 'EXEC-FLANGE', 'description_en' => 'Flange', 'description_fa' => 'فلنج', 'unit' => 'EA', 'quantity' => '1.000', 'unit_price' => '2.00']],
        ]);
        $list = '/projects/PH-14/procurement/execution/'.$this->buyer.'?lang=en';
        $this->actingAs($this->finance)->get($list)->assertOk()->assertSee($pipe->number)->assertDontSee($flange->number);
        $this->get($list.'&category=FLANGE')->assertOk()->assertSee($flange->number)->assertDontSee($pipe->number);
        $this->get($this->executionUrl($flange))->assertOk()->assertSee('EXEC-FLANGE')->assertDontSee($pipe->number);
    }

    public function test_manual_order_cross_company_issuer_deferred_commit_uses_immutable_event_scope(): void
    {
        $po = $this->draft();
        $counter = $this->userByEmail('mousavi@sahand.example');
        $this->bound(fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $counter->id)->update(['discipline_scope' => null]));
        $this->configure(['version' => 1, 'issue_selectors' => ['user:'.$counter->id]]);
        $input = $this->approve($po);
        $this->actingAs($counter)->postJson($this->executionUrl($po, 'issue'), ['execution' => $input])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($counter->id, $this->bound(fn () => $po->fresh()->issued_by, $this->writer));
        $this->assertNull($this->latest($po)->source_hash);
        $this->get('/projects/PH-14/procurement/orders/'.$po->id.'?lang=en')->assertNotFound();
    }
}
