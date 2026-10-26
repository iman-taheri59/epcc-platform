<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialRequisition;
use App\Models\Project;
use App\Models\ProjectMembership;
use App\Models\PurchaseOrder;
use App\Services\MaterialRequisitionAccessService;
use App\Services\MaterialRequisitionEntryService;
use App\Services\OrderGapService;
use App\Services\ProcurementService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Services\PurchaseOrderExecutionAccessService;
use App\Services\PurchaseOrderExecutionService;
use App\Services\PurchaseOrderPricingService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseOrderMrAllocationTest extends TestCase
{
    private $writer;

    private $finance;

    private $issuer;

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
        $this->nominate();
        $this->finance = $this->userByEmail('tehrani@pmc.example');
        $this->issuer = $this->userByEmail('sadeghi@pars.example');
        app(PurchaseOrderExecutionAccessService::class)->configure($this->project, $this->owner, [
            'buyer_company_id' => $this->writer->company_id, 'version' => 0, 'finance_selectors' => ['user:'.$this->finance->id],
            'issue_selectors' => ['user:'.$this->issuer->id], 'suspend' => false, 'reason' => 'Explicit full-copy execution nominees',
        ]);
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
        $po = $this->copy();
        app(PurchaseOrderPricingService::class)->price($this->project, $this->writer, $po->id, $this->prices($po));

        return $po;
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

    private function executeUrl(PurchaseOrder $po, ?string $purpose = null): string
    {
        return '/projects/PH-14/procurement/execution/'.$this->writer->company_id.'/'.$po->id.($purpose ? '/'.$purpose : '').'?lang=en';
    }

    private function financialInput(PurchaseOrder $po): array
    {
        return ['content_hash' => $this->prices($po)['content_hash'], 'reason' => 'Reviewed exact full-copy version'];
    }

    private function latest(PurchaseOrder $po): object
    {
        return $this->bound(fn () => DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->orderByDesc('sequence')->first());
    }

    private function approve(PurchaseOrder $po): array
    {
        $input = $this->financialInput($po);
        $this->actingAs($this->finance)->postJson($this->executeUrl($po, 'finance'), ['execution' => $input])->assertRedirect()->assertSessionHasNoErrors();

        return $input + ['approval_id' => $this->latest($po)->id];
    }

    private function issue(PurchaseOrder $po, array $input): void
    {
        $this->actingAs($this->issuer)->postJson($this->executeUrl($po, 'issue'), ['execution' => $input])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_finance_reserves_nothing_and_issue_allocates_exact_real_source_ids_without_mr_mutation(): void
    {
        $po = $this->draft();
        $before = $this->bound(fn () => [$this->mr->fresh()->toArray(), $this->mr->items()->orderBy('line_no')->get()->toArray()]);
        $this->actingAs($this->finance)->get($this->executeUrl($po))->assertOk()->assertSee('Approve this financial version');
        $input = $this->approve($po);
        $decision = $this->latest($po);
        $this->assertNotNull($decision->source_hash);
        $this->assertSame(64, strlen($decision->source_hash));
        $this->bound(function () use ($po): void {
            $this->assertSame('DRAFT', $po->fresh()->state);
            $this->assertSame(0, DB::table('po_mr_allocations')->count());
        });
        $this->issue($po, $input);
        $this->bound(function () use ($po, $before, $decision): void {
            $this->assertSame('ISSUED', $po->fresh()->state);
            $this->assertSame($this->issuer->id, $po->fresh()->issued_by);
            $this->assertSame($before, [$this->mr->fresh()->toArray(), $this->mr->items()->orderBy('line_no')->get()->toArray()]);
            $allocations = DB::table('po_mr_allocations')->where('purchase_order_id', $po->id)->get();
            $this->assertCount(2, $allocations);
            foreach ($before[1] as $source) {
                $a = $allocations->firstWhere('requisition_item_id', $source['id']);
                $this->assertNotNull($a);
                $this->assertSame($source['quantity'], $a->quantity);
                $this->assertSame($source['quantity'], $a->approved_quantity);
                $this->assertSame($source['unit'], $a->unit);
                $this->assertSame($decision->source_hash, $a->source_hash);
                $this->assertSame($this->latest($po)->id, $a->issue_decision_id);
            }
        });
        $this->actingAs($this->issuer)->get($this->executeUrl($po))->assertOk()->assertSee('Issued MR quantity allocations');
    }

    public function test_two_priced_and_financially_approved_drafts_do_not_reserve_but_second_issue_exceeds_source(): void
    {
        $first = $this->draft();
        $second = $this->draft();
        $one = $this->approve($first);
        $two = $this->approve($second);
        $this->assertSame(0, $this->bound(fn () => DB::table('po_mr_allocations')->count()));
        $this->issue($second, $two);
        $this->actingAs($this->issuer)->postJson($this->executeUrl($first, 'issue'), ['execution' => $one])->assertUnprocessable()->assertSee('PO_EXEC_MR_QUANTITY_EXCEEDED');
        $this->bound(function () use ($first): void {
            $this->assertSame('DRAFT', $first->fresh()->state);
            $this->assertSame(2, DB::table('po_mr_allocations')->count());
            $this->assertSame('finance', $this->latest($first)->purpose);
        });
        $this->get($this->executeUrl($first))->assertOk()->assertSee('Issuance would exceed')->assertDontSee('Issue this approved version');
    }

    public function test_same_po_issue_replay_never_duplicates_allocations(): void
    {
        $po = $this->draft();
        $input = $this->approve($po);
        $this->issue($po, $input);
        $this->actingAs($this->issuer)->postJson($this->executeUrl($po, 'issue'), ['execution' => $input])->assertUnprocessable();
        $this->assertSame(2, $this->bound(fn () => DB::table('po_mr_allocations')->count()));
    }

    public function test_changed_source_version_without_po_change_requires_financial_renewal(): void
    {
        $po = $this->draft();
        $input = $this->approve($po);
        $hash = $input['content_hash'];
        $source = $this->latest($po)->source_hash;
        $this->bound(fn () => DB::table('material_requisitions')->where('id', $this->mr->id)->update(['updated_at' => now()->addMinute()]));
        $this->assertSame($hash, $this->financialInput($po)['content_hash']);
        $this->actingAs($this->issuer)->postJson($this->executeUrl($po, 'issue'), ['execution' => $input])->assertUnprocessable()->assertSee('PO_EXEC_APPROVAL_STALE');
        $fresh = $this->approve($po);
        $this->assertNotSame($source, $this->latest($po)->source_hash);
        $this->issue($po, $fresh);
    }

    public function test_repricing_after_finance_requires_new_approval_of_exact_price_revision(): void
    {
        $po = $this->draft();
        $old = $this->approve($po);
        $prices = $this->prices($po);
        $prices['lines'][0]['unit_price'] = '12.34';
        app(PurchaseOrderPricingService::class)->price($this->project, $this->writer, $po->id, $prices);
        $current = $this->financialInput($po) + ['approval_id' => $old['approval_id']];
        $this->actingAs($this->issuer)->postJson($this->executeUrl($po, 'issue'), ['execution' => $current])->assertUnprocessable()->assertSee('PO_EXEC_APPROVAL_STALE');
        $fresh = $this->approve($po);
        $this->issue($po, $fresh);
    }

    public function test_trusted_prices_without_controlled_revision_cannot_enter_financial_flow(): void
    {
        $po = $this->copy();
        $this->bound(function () use ($po): void {
            DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->update(['unit_price' => '10.00']);
            DB::table('purchase_orders')->where('id', $po->id)->update(['total_value' => '51.00', 'currency' => 'USD']);
        });
        $this->actingAs($this->finance)->postJson($this->executeUrl($po, 'finance'), ['execution' => $this->financialInput($po)])->assertUnprocessable()->assertSee('PO_EXEC_MR_PRICING_REQUIRED');
    }

    public function test_changed_quantity_or_source_state_blocks_issue_without_allocating(): void
    {
        $po = $this->draft();
        $input = $this->approve($po);
        $this->bound(fn () => DB::table('material_requisitions')->where('id', $this->mr->id)->update(['state' => 'ORDERED']));
        $this->actingAs($this->issuer)->postJson($this->executeUrl($po, 'issue'), ['execution' => $input])->assertUnprocessable()->assertSee('PO_EXEC_MR_SOURCE');
        $this->bound(fn () => DB::table('material_requisitions')->where('id', $this->mr->id)->update(['state' => 'APPROVED']));
        $this->bound(fn () => DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->update(['quantity' => '1.000', 'source_quantity' => '1.000']));
        $this->postJson($this->executeUrl($po, 'issue'), ['execution' => $this->financialInput($po) + ['approval_id' => $input['approval_id']]])->assertUnprocessable();
        $this->assertSame(0, $this->bound(fn () => DB::table('po_mr_allocations')->count()));
    }

    public function test_ambiguous_legacy_commitment_blocks_even_when_cancelled_and_is_not_backfilled(): void
    {
        $po = $this->draft();
        $legacy = $this->bound(fn () => app(ProcurementService::class)->createPurchaseOrder($this->project, $this->writer, Company::where('code', 'VEN-AZAR')->firstOrFail(), 'Historical', 'قدیمی', [
            ['item_code' => 'SAME-CODE', 'description_en' => 'Historical line', 'description_fa' => 'قدیمی', 'quantity' => '1.000', 'unit' => 'M'],
        ], $this->mr, 'USD'));
        $this->bound(fn () => DB::table('purchase_orders')->where('id', $legacy->id)->update(['state' => 'CANCELLED']));
        $this->actingAs($this->finance)->postJson($this->executeUrl($po, 'finance'), ['execution' => $this->financialInput($po)])->assertUnprocessable()->assertSee('PO_EXEC_MR_LEGACY_RECONCILIATION_REQUIRED');
        $this->assertSame(0, $this->bound(fn () => DB::table('po_mr_allocations')->count()));
    }

    public function test_late_ledger_failure_rolls_back_earlier_allocations_issue_decision_and_state(): void
    {
        $po = $this->draft();
        $input = $this->approve($po);
        DB::unprepared("CREATE OR REPLACE FUNCTION epcc_test_allocation_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF (SELECT count(*) FROM po_mr_allocations WHERE issue_decision_id=NEW.issue_decision_id)>0 THEN RAISE EXCEPTION 'PO_EXEC_MR_TEST_LATE_FAILURE' USING ERRCODE='23514'; END IF; RETURN NEW; END $$; CREATE TRIGGER zzzz_allocation_failure BEFORE INSERT ON po_mr_allocations FOR EACH ROW EXECUTE FUNCTION epcc_test_allocation_failure();");
        try {
            $this->actingAs($this->issuer)->postJson($this->executeUrl($po, 'issue'), ['execution' => $input])->assertUnprocessable()->assertSee('PO_EXEC_MR_TEST_LATE_FAILURE');
            $this->bound(function () use ($po): void {
                $this->assertSame(0, DB::table('po_mr_allocations')->count());
                $this->assertSame('DRAFT', $po->fresh()->state);
                $this->assertSame('finance', $this->latest($po)->purpose);
            });
        } finally {
            DB::unprepared('DROP TRIGGER zzzz_allocation_failure ON po_mr_allocations; DROP FUNCTION epcc_test_allocation_failure();');
        }
    }

    public function test_ledger_is_append_only_and_buyer_project_scoped(): void
    {
        $po = $this->draft();
        $this->issue($po, $this->approve($po));
        $this->bound(function (): void {
            $this->assertSame(0, DB::table('po_mr_allocations')->update(['quantity' => '0.001']));
            $this->assertSame(0, DB::table('po_mr_allocations')->delete());
        });
        app(TenantContext::class)->runAs($this->finance->company_id, fn () => $this->assertSame(0, DB::table('po_mr_allocations')->count()), $this->project->id);
        app(TenantContext::class)->runAs($this->writer->company_id, fn () => $this->assertSame(0, DB::table('po_mr_allocations')->count()));
        $this->actingAs($this->issuer)->get(str_replace('lang=en', 'lang=fa', $this->executeUrl($po)))->assertOk()->assertSee('تخصیص مقدار MR');
    }

    public function test_legacy_primitive_cannot_issue_another_order_against_enrolled_source(): void
    {
        $po = $this->draft();
        $this->issue($po, $this->approve($po));
        $legacy = $this->bound(fn () => app(ProcurementService::class)->createPurchaseOrder($this->project, $this->writer, Company::where('code', 'VEN-AZAR')->firstOrFail(), 'Other', 'دیگر', [
            ['item_code' => 'SAME-CODE', 'description_en' => 'Other', 'description_fa' => 'دیگر', 'quantity' => '1.000', 'unit' => 'M'],
        ], $this->mr, 'USD'));
        try {
            $this->bound(fn () => DB::transaction(fn () => app(ProcurementService::class)->issuePurchaseOrder($legacy, $this->writer)));
            $this->fail('Expected enrolled source refusal');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PO_EXEC_MR_CONTROLLED_SOURCE', $e->getMessage());
        }
        $this->assertSame(2, $this->bound(fn () => DB::table('po_mr_allocations')->count()));
    }

    public function test_explicit_competing_company_issuer_commits_after_context_restore_without_generic_read_delegation(): void
    {
        $counter = $this->userByEmail('mousavi@sahand.example');
        app(TenantContext::class)->runAs($this->owner->company_id, fn () => ProjectMembership::where('project_id', $this->project->id)->where('user_id', $counter->id)->update(['discipline_scope' => null]), $this->project->id);
        $this->issuer = $counter;
        app(PurchaseOrderExecutionAccessService::class)->configure($this->project, $this->owner, [
            'buyer_company_id' => $this->writer->company_id, 'version' => 1, 'finance_selectors' => ['user:'.$this->finance->id],
            'issue_selectors' => ['user:'.$counter->id], 'suspend' => false, 'reason' => 'Explicit counterparty issuer for this buyer only',
        ]);
        $po = $this->draft();
        $this->issue($po, $this->approve($po));
        $this->actingAs($counter)->get($this->executeUrl($po))->assertOk()->assertSee('Issued MR quantity allocations');
        $this->get($this->detailUrl($po))->assertNotFound();
        $this->assertSame($counter->id, $this->bound(fn () => $po->fresh()->issued_by));
    }

    private function pdo(): \PDO
    {
        $c = config('database.connections.pgsql');

        return new \PDO('pgsql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'], $c['username'], $c['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    private function beginBuyer(\PDO $db): void
    {
        $db->beginTransaction();
        $q = $db->prepare("SELECT set_config('epcc.company_id',?,true),set_config('epcc.project_id',?,true)");
        $q->execute([$this->writer->company_id, $this->project->id]);
    }

    private function rawIssue(\PDO $db, PurchaseOrder $po, array $approval): void
    {
        $finance = $this->latest($po);
        $q = $db->prepare("INSERT INTO po_execution_decisions(id,project_id,buyer_company_id,purchase_order_id,policy_id,actor_id,actor_company_id,actor_name,purpose,approval_id,content_hash,snapshot,reason,created_at) VALUES(?,?,?,?,?,?,?,?,'issue',?,?,'{}','Two-connection issue test',CURRENT_TIMESTAMP)");
        $q->execute([(string) Str::uuid(), $this->project->id, $this->writer->company_id, $po->id, $finance->policy_id, $this->issuer->id, $this->issuer->company_id, $this->issuer->name, $approval['approval_id'], $approval['content_hash']]);
        $q = $db->prepare("UPDATE purchase_orders SET state='ISSUED' WHERE id=?");
        $q->execute([$po->id]);
    }

    public function test_two_real_connections_serialize_issue_then_reject_released_competing_source_quantity(): void
    {
        $one = $this->draft();
        $two = $this->draft();
        $a = $this->approve($one);
        $b = $this->approve($two);
        $first = $this->pdo();
        $second = $this->pdo();
        try {
            $this->beginBuyer($first);
            $this->rawIssue($first, $one, $a);
            $this->beginBuyer($second);
            $second->exec("SET LOCAL lock_timeout='150ms'");
            try {
                $this->rawIssue($second, $two, $b);
                $this->fail('Expected a real lock wait refusal');
            } catch (\PDOException $e) {
                $this->assertSame('55P03', $e->getCode());
            }
            $second->rollBack();
            $first->commit();
            $this->beginBuyer($second);
            try {
                $this->rawIssue($second, $two, $b);
                $this->fail('Expected source ceiling after first commit');
            } catch (\PDOException $e) {
                $this->assertStringContainsString('PO_EXEC_MR_QUANTITY_EXCEEDED', $e->getMessage());
            }
            $second->rollBack();
            $this->bound(function () use ($one, $two): void {
                $this->assertSame('ISSUED', $one->fresh()->state);
                $this->assertSame('DRAFT', $two->fresh()->state);
                $this->assertSame(2, DB::table('po_mr_allocations')->count());
            });
        } finally {
            if ($first->inTransaction()) {
                $first->rollBack();
            }if ($second->inTransaction()) {
                $second->rollBack();
            }
        }
    }

    public function test_issue_winner_blocks_amend_at_project_lock_then_freezes_retry(): void
    {
        $po = $this->draft();
        $approval = $this->approve($po);
        $stale = $this->bound(fn () => $po->items()->firstOrFail());
        $first = $this->pdo();
        try {
            $this->beginBuyer($first);
            $this->rawIssue($first, $po, $approval);
            try {
                $this->bound(function () use ($stale): void {
                    DB::statement("SET LOCAL lock_timeout='150ms'");
                    app(OrderGapService::class)->amend($stale, (float) $stale->quantity, 'en');
                });
                $this->fail('Amend must wait before taking an item row lock');
            } catch (QueryException $e) {
                $this->assertSame('55P03', $e->errorInfo[0]);
                $this->assertStringContainsString('pg_advisory_xact_lock', $e->getSql());
            }
            $first->commit();
            try {
                $this->bound(fn () => app(OrderGapService::class)->amend($stale, (float) $stale->quantity, 'en'));
                $this->fail('Controlled issued content must remain frozen');
            } catch (QueryException $e) {
                $this->assertStringContainsString('PO_EXEC_ISSUED_CONTENT_FROZEN', $e->getMessage());
            }
            $this->bound(function () use ($po): void {
                $this->assertSame('ISSUED', $po->fresh()->state);
                $this->assertSame(2, $po->items()->count());
                $this->assertSame(2, DB::table('po_mr_allocations')->count());
            });
        } finally {
            if ($first->inTransaction()) {
                $first->rollBack();
            }
        }
    }

    public function test_amend_winner_blocks_issue_then_invalidates_old_finance_basis(): void
    {
        $po = $this->draft();
        $approval = $this->approve($po);
        $stale = $this->bound(fn () => $po->items()->firstOrFail());
        $second = $this->pdo();
        try {
            $this->bound(function () use ($po, $approval, $stale, $second): void {
                app(OrderGapService::class)->amend($stale, (float) $stale->quantity, 'en');
                $this->beginBuyer($second);
                $second->exec("SET LOCAL lock_timeout='150ms'");
                try {
                    $this->rawIssue($second, $po, $approval);
                    $this->fail('Issue must wait for amendment project lock');
                } catch (\PDOException $e) {
                    $this->assertSame('55P03', $e->getCode());
                }
                $second->rollBack();
            });
            $this->beginBuyer($second);
            try {
                $this->rawIssue($second, $po, $approval);
                $this->fail('Old pricing and finance must not certify amended content');
            } catch (\PDOException $e) {
                $this->assertStringContainsString('PO_EXEC_MR_PRICING_REQUIRED', $e->getMessage());
            }
            $second->rollBack();
            $this->bound(function () use ($po): void {
                $this->assertSame('DRAFT', $po->fresh()->state);
                $this->assertSame(3, $po->items()->count());
                $this->assertSame(0, DB::table('po_mr_allocations')->count());
                $this->assertSame(0, DB::table('po_execution_decisions')->where('purpose', 'issue')->count());
            });
        } finally {
            if ($second->inTransaction()) {
                $second->rollBack();
            }
        }
    }

    private function assertRawIssueWaits(\PDO $connection, PurchaseOrder $po, array $approval): void
    {
        $this->beginBuyer($connection);
        $connection->exec("SET LOCAL lock_timeout='150ms'");
        try {
            $this->rawIssue($connection, $po, $approval);
            $this->fail('A competing issue must wait on the held project advisory lock');
        } catch (\PDOException $e) {
            $this->assertSame('55P03', $e->getCode());
            $this->assertStringContainsString('pg_advisory_xact_lock', $e->getMessage());
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }
    }

    private function suspendExecution(): void
    {
        app(PurchaseOrderExecutionAccessService::class)->configure($this->project, $this->owner, [
            'buyer_company_id' => $this->writer->company_id, 'version' => 1,
            'finance_selectors' => [], 'issue_selectors' => [], 'suspend' => true,
            'reason' => 'Explicit owner suspension for bounded two-connection verification',
        ]);
    }

    public function test_issue_rollback_releases_project_lock_and_uncommitted_allocations_for_competitor(): void
    {
        $one = $this->draft();
        $two = $this->draft();
        $a = $this->approve($one);
        $b = $this->approve($two);
        $sourceBefore = $this->bound(fn () => [$this->mr->fresh()->getRawOriginal(), $this->mr->items()->orderBy('id')->get()->map(fn ($line) => $line->getRawOriginal())->all()]);
        $first = $this->pdo();
        $second = $this->pdo();
        try {
            $this->beginBuyer($first);
            $this->rawIssue($first, $one, $a);
            $this->assertSame(2, (int) $first->query('SELECT count(*) FROM po_mr_allocations')->fetchColumn());
            $this->assertSame(0, $this->bound(fn () => DB::table('po_mr_allocations')->count()));
            $this->assertRawIssueWaits($second, $two, $b);
            $first->rollBack();
            $this->beginBuyer($second);
            $this->rawIssue($second, $two, $b);
            $second->commit();
            $this->bound(function () use ($one, $two, $sourceBefore): void {
                $this->assertSame('DRAFT', $one->fresh()->state);
                $this->assertNull($one->fresh()->issued_by);
                $this->assertSame('ISSUED', $two->fresh()->state);
                $this->assertSame(0, DB::table('po_mr_allocations')->where('purchase_order_id', $one->id)->count());
                $this->assertSame(2, DB::table('po_mr_allocations')->where('purchase_order_id', $two->id)->count());
                $this->assertSame(1, DB::table('po_execution_decisions')->where('purchase_order_id', $one->id)->count());
                $this->assertSame(2, DB::table('po_execution_decisions')->where('purchase_order_id', $two->id)->count());
                $this->assertSame($sourceBefore, [$this->mr->fresh()->getRawOriginal(), $this->mr->items()->orderBy('id')->get()->map(fn ($line) => $line->getRawOriginal())->all()]);
            });
        } finally {
            foreach ([$first, $second] as $db) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
            }
        }
    }

    public function test_execution_suspension_commit_wins_race_and_refuses_waiting_old_issue_basis(): void
    {
        $po = $this->draft();
        $approval = $this->approve($po);
        $second = $this->pdo();
        try {
            app(TenantContext::class)->runAs($this->owner->company_id, function () use ($po, $approval, $second): void {
                $this->suspendExecution();
                $this->assertRawIssueWaits($second, $po, $approval);
            }, $this->project->id);
            $this->beginBuyer($second);
            try {
                $this->rawIssue($second, $po, $approval);
                $this->fail('Committed suspension must reject the old issue basis');
            } catch (\PDOException $e) {
                $this->assertSame('42501', $e->getCode());
                $this->assertStringContainsString('PO_EXEC_AUTHORITY', $e->getMessage());
            }
            $second->rollBack();
            $this->bound(function () use ($po): void {
                $this->assertSame('DRAFT', $po->fresh()->state);
                $this->assertNull($po->fresh()->issued_by);
                $this->assertSame(0, DB::table('po_mr_allocations')->count());
                $this->assertSame(1, DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->count());
            });
            app(TenantContext::class)->runAs($this->owner->company_id, fn () => $this->assertSame(2, DB::table('po_execution_policies')->where('buyer_company_id', $this->writer->company_id)->count()), $this->project->id);
        } finally {
            if ($second->inTransaction()) {
                $second->rollBack();
            }
        }
    }

    public function test_execution_suspension_rollback_releases_waiter_without_revoking_old_live_basis(): void
    {
        $po = $this->draft();
        $approval = $this->approve($po);
        $second = $this->pdo();
        try {
            try {
                app(TenantContext::class)->runAs($this->owner->company_id, function () use ($po, $approval, $second): void {
                    $this->suspendExecution();
                    $this->assertRawIssueWaits($second, $po, $approval);
                    throw new \RuntimeException('TEST_ROLL_BACK_OWNER_SUSPENSION');
                }, $this->project->id);
                $this->fail('Expected the test-only owner transaction rollback');
            } catch (\RuntimeException $e) {
                $this->assertSame('TEST_ROLL_BACK_OWNER_SUSPENSION', $e->getMessage());
            }
            $this->beginBuyer($second);
            $this->rawIssue($second, $po, $approval);
            $second->commit();
            $this->bound(function () use ($po): void {
                $this->assertSame('ISSUED', $po->fresh()->state);
                $this->assertSame(2, DB::table('po_mr_allocations')->count());
                $this->assertSame(2, DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->count());
            });
            app(TenantContext::class)->runAs($this->owner->company_id, fn () => $this->assertSame(1, DB::table('po_execution_policies')->where('buyer_company_id', $this->writer->company_id)->count()), $this->project->id);
        } finally {
            if ($second->inTransaction()) {
                $second->rollBack();
            }
        }
    }

    public function test_issue_commit_wins_suspension_race_without_retroactive_rewrite_or_capacity_release(): void
    {
        $po = $this->draft();
        $approval = $this->approve($po);
        $first = $this->pdo();
        try {
            $this->beginBuyer($first);
            $this->rawIssue($first, $po, $approval);
            try {
                app(TenantContext::class)->runAs($this->owner->company_id, function (): void {
                    DB::statement("SET LOCAL lock_timeout='150ms'");
                    $this->suspendExecution();
                }, $this->project->id);
                $this->fail('Real configuration service must wait behind the active issue');
            } catch (QueryException $e) {
                $this->assertSame('55P03', $e->errorInfo[0]);
                $this->assertStringContainsString('pg_advisory_xact_lock', $e->getSql());
            }
            $first->commit();
            $snapshot = fn () => $this->bound(function () use ($po): array {
                return [$po->fresh()->getRawOriginal(), DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->orderBy('sequence')->get()->map(fn ($row) => (array) $row)->all(),
                    DB::table('po_mr_allocations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all()];
            });
            $before = $snapshot();
            $this->suspendExecution();
            $this->assertSame($before, $snapshot());
            $this->assertSame('ISSUED', $before[0]['state']);
            $this->assertCount(2, $before[2]);
            $this->assertNull(app(PurchaseOrderExecutionAccessService::class)->nominationId($this->project, $this->issuer, $this->writer->company_id, 'issue', 'PIPING'));
        } finally {
            if ($first->inTransaction()) {
                $first->rollBack();
            }
        }
    }

    public function test_header_failure_after_allocation_insert_rolls_back_ledger_and_decision(): void
    {
        $po = $this->draft();
        $input = $this->approve($po);
        DB::unprepared("CREATE OR REPLACE FUNCTION epcc_test_alloc_header_failure() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN IF NEW.state='ISSUED' THEN RAISE EXCEPTION 'PO_EXEC_MR_TEST_HEADER_FAILURE' USING ERRCODE='23514'; END IF; RETURN NEW; END $$; CREATE TRIGGER zzzz_alloc_header_failure BEFORE UPDATE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION epcc_test_alloc_header_failure();");
        try {
            $this->actingAs($this->issuer)->postJson($this->executeUrl($po, 'issue'), ['execution' => $input])->assertUnprocessable()->assertSee('PO_EXEC_MR_TEST_HEADER_FAILURE');
            $this->assertSame(0, $this->bound(fn () => DB::table('po_mr_allocations')->count()));
            $this->assertSame('finance', $this->latest($po)->purpose);
        } finally {
            DB::unprepared('DROP TRIGGER zzzz_alloc_header_failure ON purchase_orders; DROP FUNCTION epcc_test_alloc_header_failure();');
        }
    }

    public function test_deferred_check_refuses_missing_allocations_even_after_caller_scope_is_cleared(): void
    {
        $po = $this->draft();
        $input = $this->approve($po);
        $db = $this->pdo();
        DB::statement('ALTER TABLE po_execution_decisions DISABLE TRIGGER po_issue_mr_allocations');
        try {
            $this->beginBuyer($db);
            $this->rawIssue($db, $po, $input);
            $q = $db->prepare("SELECT set_config('epcc.company_id',?,true),set_config('epcc.project_id','',true)");
            $q->execute([$this->finance->company_id]);
            try {
                $db->exec('SET CONSTRAINTS po_issue_mr_allocated IMMEDIATE');
                $this->fail('Expected deferred allocation refusal despite restored caller scope');
            } catch (\PDOException $e) {
                $this->assertStringContainsString('PO_EXEC_MR_ALLOCATION_INCOMPLETE', $e->getMessage());
            }
            $db->rollBack();
            $this->assertSame('DRAFT', $this->bound(fn () => $po->fresh()->state));
            $this->assertSame('finance', $this->latest($po)->purpose);
        } finally {
            if ($db->inTransaction()) {
                $db->rollBack();
            }DB::statement('ALTER TABLE po_execution_decisions ENABLE TRIGGER po_issue_mr_allocations');
        }
    }

    public function test_active_successor_allocates_once_and_preserves_superseded_history(): void
    {
        $po = $this->copy();
        $old = $this->bound(function () use ($po): string {
            $l = (array) DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->orderBy('line_no')->first();
            $old = $l['id'];
            $l['id'] = (string) Str::uuid();
            $l['line_no'] = 3;
            $l['supersedes_id'] = $old;
            DB::table('purchase_order_items')->insert($l);

            return $old;
        });
        $prices = $this->prices($po);
        $prices['lines'] = array_values(array_filter($prices['lines'], fn ($l) => $l['id'] !== $old));
        app(PurchaseOrderPricingService::class)->price($this->project, $this->writer, $po->id, $prices);
        $this->issue($po, $this->approve($po));
        $this->bound(function () use ($old): void {
            $this->assertSame(2, DB::table('po_mr_allocations')->count());
            $this->assertSame(0, DB::table('po_mr_allocations')->where('purchase_order_item_id', $old)->count());
        });
    }

    public function test_source_metadata_evolution_and_receipt_state_progress_do_not_release_or_rewrite_issued_allocations(): void
    {
        $po = $this->draft();
        $this->issue($po, $this->approve($po));
        $before = $this->bound(fn () => DB::table('po_mr_allocations')->orderBy('id')->get()->toJson());
        $this->bound(function () use ($po): void {
            DB::table('material_requisitions')->where('id', $this->mr->id)->update(['updated_at' => now()->addMinute()]);
            DB::table('purchase_orders')->where('id', $po->id)->update(['forecast_date' => '2027-01-01', 'state' => 'PARTIALLY_RECEIVED']);
        });
        $this->assertSame($before, $this->bound(fn () => DB::table('po_mr_allocations')->orderBy('id')->get()->toJson()));
        try {
            $this->bound(fn () => DB::transaction(fn () => DB::table('purchase_orders')->where('id', $po->id)->update(['state' => 'CANCELLED'])));
            $this->fail('Expected controlled cancellation refusal');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PO_EXEC_CANCELLATION_PENDING', $e->getMessage());
        }
        $this->assertSame('PARTIALLY_RECEIVED', $this->bound(fn () => $po->fresh()->state));
    }

    public function test_allocation_cannot_be_inserted_against_only_financial_decision(): void
    {
        $po = $this->draft();
        $this->approve($po);
        $e = $this->latest($po);
        $line = $this->bound(fn () => $po->items()->first());
        $row = ['id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'buyer_company_id' => $this->writer->company_id, 'purchase_order_id' => $po->id,
            'purchase_order_item_id' => $line->id, 'requisition_id' => $this->mr->id, 'requisition_item_id' => $line->requisition_item_id, 'issue_decision_id' => $e->id,
            'quantity' => (string) $line->quantity, 'approved_quantity' => (string) $line->quantity, 'unit' => $line->unit, 'source_hash' => $e->source_hash, 'created_at' => now()];
        try {
            $this->bound(fn () => DB::transaction(fn () => DB::table('po_mr_allocations')->insert($row)));
            $this->fail('Financial approval cannot allocate');
        } catch (QueryException $error) {
            $this->assertStringContainsString('PO_EXEC_MR_ISSUE_REQUIRED', $error->getMessage());
        }
        $this->assertSame(0, $this->bound(fn () => DB::table('po_mr_allocations')->count()));
    }

    public function test_legacy_source_line_reference_without_mr_header_is_still_detected(): void
    {
        $po = $this->draft();
        $legacy = $this->bound(fn () => app(ProcurementService::class)->createPurchaseOrder($this->project, $this->writer, Company::where('code', 'VEN-AZAR')->firstOrFail(), 'Line-only legacy', 'قدیمی', [
            ['item_code' => 'OLD', 'description_en' => 'Old', 'description_fa' => 'قدیمی', 'quantity' => '1.000', 'unit' => 'M'],
        ], null, 'USD'));
        $source = $this->bound(fn () => $this->mr->items()->first()->id);
        $this->bound(function () use ($legacy, $source): void {
            DB::table('purchase_order_items')->where('purchase_order_id', $legacy->id)->update(['requisition_item_id' => $source]);
            app(ProcurementService::class)->issuePurchaseOrder($legacy->fresh(), $this->writer);
        });
        $this->actingAs($this->finance)->postJson($this->executeUrl($po, 'finance'), ['execution' => $this->financialInput($po)])->assertUnprocessable()->assertSee('PO_EXEC_MR_LEGACY_RECONCILIATION_REQUIRED');
    }
}
