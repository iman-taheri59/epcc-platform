<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\LegacyMaterialReconciliationService;
use App\Services\PipingCustodyService;
use App\Services\PipingReceiptAccessService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResultRecorderPolicyFixture;
use Tests\TestCase;

/**
 * ADR-048 A.4f slice 1 — the legacy warehouse finally has a way through the
 * cutover gate.
 *
 * ADR-047 closed `material_lots` behind a cutoff and made the GRN guard refuse
 * any purchase order that still has legacy lots. Right, but it was a wall, not
 * a gate: nothing could ever be done about those lots, so a project that used
 * the legacy warehouse before cutover could never post a controlled GRN against
 * those orders.
 *
 * A reconciliation is the way through, and it is a decision — TAKE_OVER (this
 * custody record re-receives the material) or RETIRE (it is not coming across,
 * and here is why) — not a migration of old rows into new ones.
 */
final class LegacyMaterialReconciliationTest extends TestCase
{
    private User $owner;

    private User $buyer;

    private User $qc;

    private Project $project;

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

        app(PipingReceiptAccessService::class)->configure($this->project, $this->owner, [
            'version' => 0, 'suspend' => false, 'reason' => 'Synthetic reconciliation matrix',
            'custodian_company_id' => $this->buyer->company_id,
            'actions' => [
                'custody_mrr' => ['user:'.$this->buyer->id],
                'receiving_qc' => ['user:'.$this->qc->id],
                'acceptance_qa' => ['user:'.$this->owner->id],
                'post_grn' => ['user:'.$this->buyer->id],
            ],
        ]);

        ResultRecorderPolicyFixture::ensure(
            app(TenantContext::class), $this->project, $this->buyer, $this->buyer->company_id,
            'reconcile_legacy', ['user:'.$this->buyer->id],
        );
    }

    // ------------------------------------------------------------------ fixtures

    /** A legacy lot, inserted the only way it can be: with the cutoff lifted. */
    private function legacyLot(string $itemCode = 'LEGACY-PIPE', ?string $heat = 'HEAT-77'): string
    {
        $id = (string) Str::uuid();

        $this->asBuyer(function () use ($id, $itemCode, $heat): void {
            DB::unprepared('ALTER TABLE material_lots DISABLE TRIGGER aa_piping_legacy_stock_cutoff');
            try {
                DB::table('material_lots')->insert([
                    'id' => $id, 'project_id' => $this->project->id, 'company_id' => $this->buyer->company_id,
                    'lot_code' => 'LEG-'.$itemCode, 'item_code' => $itemCode,
                    'description_en' => 'Legacy fixture', 'description_fa' => 'قدیمی', 'unit' => 'M',
                    'heat_number' => $heat, 'created_at' => now(), 'updated_at' => now(),
                ]);
            } finally {
                DB::unprepared('ALTER TABLE material_lots ENABLE TRIGGER aa_piping_legacy_stock_cutoff');
            }
        });

        return $id;
    }

    private function custody(string $heat): string
    {
        return app(PipingCustodyService::class)->create($this->project, $this->buyer, [
            'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'),
            'request_id' => (string) Str::uuid(), 'source_line_id' => null, 'unlinked_reason' => 'Source pending',
            'shipment_ref' => 'SYNTHETIC-LEGACY', 'package_ref' => null,
            'received_at' => '2026-10-29T08:00:00+03:30', 'observed_description' => 'Synthetic receipt',
            'quantity' => '2.000', 'unit' => 'M', 'heat_ref' => $heat,
            'location_kind' => 'QUARANTINE', 'location_ref' => 'Bay Q',
            'document_status' => 'MISSING', 'notes' => null,
        ]);
    }

    private function asBuyer(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->buyer->company_id, $fn, $this->project->id);
    }

    private function reconciliations(): LegacyMaterialReconciliationService
    {
        return app(LegacyMaterialReconciliationService::class);
    }

    /** @param  array<string,mixed>  $overrides */
    private function decide(string $lotId, array $overrides = [], ?User $actor = null): array
    {
        return $this->reconciliations()->reconcile($this->project, $actor ?? $this->buyer, $lotId, array_replace([
            'decision' => 'RETIRE', 'reason' => 'Scrapped before cutover', 'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    private function rowCount(): int
    {
        return $this->asBuyer(fn () => DB::table('material_legacy_reconciliations')->count());
    }

    // ------------------------------------------------------------------ authority

    public function test_reconciling_a_legacy_lot_is_default_deny_without_a_nomination(): void
    {
        $lot = $this->legacyLot();

        try {
            $this->decide($lot, ['reason' => 'The owner decides anyway'], actor: $this->owner);
            $this->fail('Expected default-deny refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not nominated', $e->errors()['reconciliation'][0]);
        }
        $this->assertSame(0, $this->rowCount());
    }

    // ------------------------------------------------------------------ decisions

    public function test_taking_a_lot_over_names_the_custody_that_attests_its_heat(): void
    {
        $lot = $this->legacyLot('LEGACY-PIPE', 'HEAT-77');
        $custody = $this->custody('HEAT-77');

        $result = $this->decide($lot, [
            'decision' => 'TAKE_OVER', 'custody_id' => $custody,
            'reason' => 'Re-received and counted at cutover',
        ]);

        $this->assertSame('TAKE_OVER', $result['decision']);
        $this->assertSame($custody, $result['custody_id']);
        $this->assertSame('LEGACY-PIPE', $result['item_code'], 'the item is the lot\'s, not the caller\'s');
        $this->assertSame('HEAT-77', $result['heat_ref'], 'the heat is the lot\'s, not the caller\'s');
        $this->assertSame(1, $result['sequence']);
        $this->assertSame(1, $this->rowCount());
    }

    public function test_taking_a_lot_over_custody_of_a_different_heat_is_refused(): void
    {
        $lot = $this->legacyLot('LEGACY-PIPE', 'HEAT-77');
        $custody = $this->custody('HEAT-99');

        try {
            $this->decide($lot, ['decision' => 'TAKE_OVER', 'custody_id' => $custody, 'reason' => 'Close enough']);
            $this->fail('Expected refusal: different heat, different steel');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not the same material', $e->errors()['reconciliation'][0]);
        }
        $this->assertSame(0, $this->rowCount());
    }

    public function test_retiring_a_lot_records_the_reason_and_points_at_nothing(): void
    {
        $lot = $this->legacyLot();

        $result = $this->decide($lot, ['reason' => 'Consumed before cutover; no controlled receipt exists']);

        $this->assertSame('RETIRE', $result['decision']);
        $this->assertNull($result['custody_id'], 'a retirement names no receipt');
        $this->assertSame(1, $this->rowCount());
    }

    public function test_a_decision_without_a_reason_is_refused(): void
    {
        $lot = $this->legacyLot();

        try {
            $this->decide($lot, ['reason' => '   ']);
            $this->fail('Expected refusal: a decision about legacy material must be reasoned');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $this->assertSame(0, $this->rowCount());
    }

    // ------------------------------------------------------------------ lineage and immutability

    public function test_a_later_decision_chains_to_the_earlier_one(): void
    {
        $lot = $this->legacyLot();
        $first = $this->decide($lot)['id'];

        // A second decision is a correction, so it must chain — never a free-floating row.
        try {
            $this->decide($lot, ['reason' => 'Actually it was re-received', 'decision' => 'RETIRE']);
            $this->fail('Expected refusal: the new decision must chain to the latest');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('chain to the latest', $e->errors()['reconciliation'][0]);
        }

        $second = $this->decide($lot, [
            'reason' => 'Actually it was re-received', 'decision' => 'TAKE_OVER',
            'custody_id' => $this->custody('HEAT-77'), 'previous_reconciliation_id' => $first,
        ]);

        $this->assertSame(2, $second['sequence']);
        $chain = $this->reconciliations()->reconciliationsForLot($this->project, $this->buyer, $lot);
        $this->assertCount(2, $chain);
        $this->assertSame('RETIRE', $chain[0]->decision, 'the superseded decision stays on the record');
        $this->assertSame('TAKE_OVER', $chain[1]->decision);
        $this->assertSame($first, (string) $chain[1]->previous_reconciliation_id);
    }

    public function test_reconciliations_are_append_only(): void
    {
        $lot = $this->legacyLot();
        $result = $this->decide($lot);

        $this->asBuyer(function () use ($result): void {
            $before = DB::table('material_legacy_reconciliations')->where('id', $result['id'])->first();
            // No UPDATE/DELETE policy exists, so RLS makes the write match nothing;
            // the mutation trigger is the second line of defence.
            $this->assertSame(0, DB::table('material_legacy_reconciliations')->where('id', $result['id'])->update(['decision' => 'TAKE_OVER']));
            $this->assertSame(0, DB::table('material_legacy_reconciliations')->where('id', $result['id'])->delete());
            $this->assertEquals($before, DB::table('material_legacy_reconciliations')->where('id', $result['id'])->first());
        });
    }

    public function test_duplicate_request_ids_are_refused(): void
    {
        $lot = $this->legacyLot('PIPE-A');
        $other = $this->legacyLot('PIPE-B');
        $requestId = (string) Str::uuid();
        $this->decide($lot, ['request_id' => $requestId]);

        try {
            $this->decide($other, ['request_id' => $requestId, 'reason' => 'Same request id, different lot']);
            $this->fail('Expected duplicate refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already recorded', strtolower($e->errors()['reconciliation'][0]));
        }

        // A retry of the same decision is not applied twice either: the lot already
        // carries a decision, and a second one has to chain to it.
        try {
            $this->decide($lot, ['request_id' => $requestId]);
            $this->fail('Expected refusal: the lot already has a decision');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already exists', $e->errors()['reconciliation'][0]);
        }
        $this->assertSame(1, $this->rowCount());
    }

    public function test_another_company_cannot_decide_in_another_name(): void
    {
        $lot = $this->legacyLot();

        $this->asBuyer(function () use ($lot): void {
            DB::select('SELECT set_config(\'epcc.receipt_actor_id\', ?, true)', [$this->buyer->id]);
            DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$this->buyer->id]);
            $this->expectException(QueryException::class);
            DB::table('material_legacy_reconciliations')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $this->project->id,
                'company_id' => $this->buyer->company_id, 'lot_id' => $lot, 'custody_id' => null,
                'decision' => 'RETIRE', 'item_code' => 'LEGACY-PIPE', 'heat_ref' => 'HEAT-77',
                'reason' => 'Signed by somebody else', 'policy_id' => (string) Str::uuid(),
                'decided_by' => $this->owner->id, 'sequence' => 1,
                'previous_reconciliation_id' => null, 'request_id' => (string) Str::uuid(),
            ]);
        });
    }

    public function test_the_identity_comes_from_the_lot_never_from_the_caller(): void
    {
        $lot = $this->legacyLot('LEGACY-PIPE', 'HEAT-77');

        $row = $this->asBuyer(function () use ($lot): object {
            DB::select('SELECT set_config(\'epcc.receipt_actor_id\', ?, true)', [$this->buyer->id]);
            DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$this->buyer->id]);
            $id = (string) DB::table('material_legacy_reconciliations')->insertGetId([
                'id' => (string) Str::uuid(), 'project_id' => $this->project->id,
                'company_id' => $this->buyer->company_id, 'lot_id' => $lot, 'custody_id' => null,
                'decision' => 'RETIRE', 'item_code' => 'SOMETHING-ELSE', 'heat_ref' => 'HEAT-00',
                'reason' => 'Trying to rename the material on the way across',
                'policy_id' => (string) Str::uuid(), 'decided_by' => $this->buyer->id,
                'sequence' => 1, 'previous_reconciliation_id' => null, 'request_id' => (string) Str::uuid(),
            ], 'id');

            return DB::table('material_legacy_reconciliations')->where('id', $id)->first();
        });

        $this->assertSame('LEGACY-PIPE', (string) $row->item_code, 'the lot says what it is');
        $this->assertSame('HEAT-77', (string) $row->heat_ref);
    }

    // ------------------------------------------------------------------ the cutover worklist

    public function test_pending_lists_only_the_lots_nobody_has_decided_about(): void
    {
        $first = $this->legacyLot('PIPE-A');
        $second = $this->legacyLot('PIPE-B');

        $pending = $this->reconciliations()->pending($this->project, $this->buyer);
        $this->assertCount(2, $pending);
        $this->assertSame(['PIPE-A', 'PIPE-B'], $pending->pluck('item_code')->all());

        $this->decide($first);

        $pending = $this->reconciliations()->pending($this->project, $this->buyer);
        $this->assertCount(1, $pending);
        $this->assertSame($second, (string) $pending->first()->id, 'only the undecided lot is still blocking');
        $this->assertTrue($this->reconciliations()->isReconciled($this->project, $this->buyer, $first));
    }
}
