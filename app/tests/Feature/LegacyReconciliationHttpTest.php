<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\PipingReceiptAccessService;
use App\Services\StockLedgerService;
use App\Services\ResultRecorderPolicyService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LegacyLotFixture;
use Tests\Support\MtrBindingFixture;
use Tests\TestCase;

/**
 * ADR-048 A.4h slice 4 — the cutover gate finally gets a handle.
 *
 * ADR-047 closed the legacy warehouse and made the GRN guard refuse any
 * purchase order that still has legacy lots. Correct, and it was a wall: A.4f
 * made the decision recordable but only from SQL, so a project carrying legacy
 * material still could not get through.
 *
 * A reconciliation is a decision, not a migration: TAKE_OVER (this custody
 * record re-receives it, and must attest the lot's own heat — a different heat
 * is different steel) or RETIRE (it is not coming across, and here is why).
 * Each is nominated, reasoned and chained; none is ever edited.
 */
final class LegacyReconciliationHttpTest extends TestCase
{
    private User $owner;

    private User $buyer;

    private User $qc;

    private Project $project;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->tenant = app(TenantContext::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->buyer = $this->userByEmail('rezaei@pars.example');
        $this->qc = $this->userByEmail('tehrani@pmc.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();

        app(PipingReceiptAccessService::class)->configure($this->project, $this->owner, [
            'version' => 0,
            'suspend' => false,
            'reason' => 'Legacy reconciliation fixture receipt register',
            'custodian_company_id' => $this->buyer->company_id,
            'actions' => [
                'custody_mrr' => ['user:'.$this->buyer->id],
                'receiving_qc' => ['user:'.$this->qc->id],
                'acceptance_qa' => ['user:'.$this->owner->id],
                'post_grn' => ['user:'.$this->buyer->id],
            ],
        ]);
        app()->setLocale('fa');
    }

    // ------------------------------------------------------------------ surface

    public function test_the_worklist_shows_the_lot_and_says_nothing_is_decided(): void
    {
        $lot = $this->legacyLot('HEAT-77');

        $response = $this->actingAs($this->buyer)->get($this->route(['lot' => $lot]));

        $response->assertOk();
        $response->assertSee('id="no-decided"', false);
        $response->assertSee('id="decision-denied"', false);
        $response->assertSee(__('quality_legacy.not_nominated'), false);
        $response->assertSee('LEG-LEGACY-PIPE', false);
    }

    public function test_the_form_appears_only_once_you_are_nominated(): void
    {
        $lot = $this->legacyLot('HEAT-77');

        $this->actingAs($this->buyer)->get($this->route(['lot' => $lot]))
            ->assertSee('id="decision-denied"', false);

        $this->nominate();

        $this->actingAs($this->buyer)->get($this->route(['lot' => $lot]))
            ->assertSee('id="decision-form"', false)
            ->assertDontSee('id="decision-denied"', false);
    }

    public function test_the_screen_switches_to_english(): void
    {
        $this->actingAs($this->buyer)->get($this->route([], 'en'))
            ->assertOk()->assertSee('Legacy warehouse reconciliation', false);
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get($this->route())->assertRedirect(route('login'));
    }

    public function test_someone_outside_the_project_does_not_get_the_screen(): void
    {
        $outsider = $this->userByEmail('hosseini@arvand.example');

        $this->actingAs($outsider)->get($this->route())->assertForbidden();
    }

    // -------------------------------------------------------------- take over

    public function test_taking_a_lot_over_binds_it_to_custody_attesting_its_own_heat(): void
    {
        $lot = $this->legacyLot('HEAT-77');
        $custody = $this->custody('HEAT-77');
        $this->nominate();

        $this->actingAs($this->buyer)->post($this->route(), [
            'lot_id' => $lot,
            'reconciliation' => $this->payload(['decision' => 'TAKE_OVER', 'custody_id' => $custody]),
        ])->assertRedirect();

        $row = $this->rows()->first();
        $this->assertSame('TAKE_OVER', $row->decision);
        $this->assertSame($custody, (string) $row->custody_id);
        $this->assertSame('HEAT-77', (string) $row->heat_ref, 'the heat is the lot’s own, never the caller’s');
        $this->assertSame('LEGACY-PIPE', (string) $row->item_code, 'the item is derived from the lot');

        // Decided, so it leaves the worklist that blocks a controlled GRN.
        $this->actingAs($this->buyer)->get($this->route())
            ->assertSee('id="no-pending"', false)
            ->assertDontSee('id="no-decided"', false);
    }

    public function test_taking_a_lot_over_with_a_different_heat_is_refused(): void
    {
        $lot = $this->legacyLot('HEAT-77');
        $custody = $this->custody('HEAT-OTHER');
        $this->nominate();

        $response = $this->actingAs($this->buyer)->post($this->route(), [
            'lot_id' => $lot,
            'reconciliation' => $this->payload(['decision' => 'TAKE_OVER', 'custody_id' => $custody]),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows()->count(), 'a different heat is different steel');
    }

    public function test_the_form_offers_only_the_receipts_that_could_take_it_over(): void
    {
        $lot = $this->legacyLot('HEAT-77');
        $right = $this->custody('HEAT-77');
        $wrong = $this->custody('HEAT-OTHER');
        $this->nominate();

        $screen = $this->actingAs($this->buyer)->get($this->route(['lot' => $lot]));
        $content = $screen->getContent();

        // Offering a receipt that cannot take the lot over is asking someone to
        // make a decision in order to be told it was never theirs to make.
        $this->assertStringContainsString($right, $content);
        $this->assertStringNotContainsString($wrong, $content);
    }

    public function test_taking_a_lot_over_without_naming_custody_is_refused(): void
    {
        $lot = $this->legacyLot('HEAT-77');
        $this->nominate();

        $response = $this->actingAs($this->buyer)->post($this->route(), [
            'lot_id' => $lot,
            'reconciliation' => $this->payload(['decision' => 'TAKE_OVER']),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows()->count());
    }

    // ----------------------------------------------------------------- retire

    public function test_retiring_a_lot_without_a_reason_is_refused(): void
    {
        $lot = $this->legacyLot('HEAT-77');
        $this->nominate();

        $response = $this->actingAs($this->buyer)->post($this->route(), [
            'lot_id' => $lot,
            'reconciliation' => $this->payload(['decision' => 'RETIRE', 'reason' => '']),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows()->count(), 'every decision about legacy material must be reasoned');

        $this->actingAs($this->buyer)->post($this->route(), [
            'lot_id' => $lot,
            'reconciliation' => $this->payload(['decision' => 'RETIRE', 'reason' => 'Consumed before cutover']),
        ])->assertRedirect();

        $this->assertSame(1, $this->rows()->count());
        $this->assertSame('RETIRE', $this->rows()->first()->decision);
    }

    public function test_deciding_without_a_nomination_writes_nothing(): void
    {
        $lot = $this->legacyLot('HEAT-77');

        $response = $this->actingAs($this->buyer)->post($this->route(), [
            'lot_id' => $lot,
            'reconciliation' => $this->payload(['decision' => 'RETIRE']),
        ]);

        $response->assertSessionHasErrors();
        $this->assertSame(0, $this->rows()->count(), 'default-deny held');
    }

    // ------------------------------------------------------------------ chain

    public function test_a_later_decision_supersedes_the_head_and_both_stay(): void
    {
        $lot = $this->legacyLot('HEAT-77');
        $this->nominate();

        $this->actingAs($this->buyer)->post($this->route(), [
            'lot_id' => $lot,
            'reconciliation' => $this->payload(['decision' => 'RETIRE', 'reason' => 'Consumed before cutover']),
        ])->assertRedirect();

        $head = (string) $this->rows()->first()->id;

        // A decision that does not name the current head is stale, not an edit.
        $this->actingAs($this->buyer)->post($this->route(), [
            'lot_id' => $lot,
            'reconciliation' => $this->payload(['decision' => 'RETIRE', 'reason' => 'Changed my mind']),
        ])->assertSessionHasErrors();

        $this->assertSame(1, $this->rows()->count());

        $this->actingAs($this->buyer)->post($this->route(), [
            'lot_id' => $lot,
            'reconciliation' => $this->payload([
                'decision' => 'RETIRE',
                'reason' => 'Changed my mind',
                'previous_reconciliation_id' => $head,
            ]),
        ])->assertRedirect();

        $this->assertSame(2, $this->rows()->count());
        $this->assertSame($head, (string) $this->rows()->last()->previous_reconciliation_id);
        $this->assertSame(2, (int) $this->rows()->last()->sequence);
    }

    public function test_the_same_request_id_is_not_decided_twice(): void
    {
        $lot = $this->legacyLot('HEAT-77');
        $this->nominate();
        $payload = $this->payload(['decision' => 'RETIRE', 'reason' => 'Consumed before cutover']);

        $this->actingAs($this->buyer)->post($this->route(), [
            'lot_id' => $lot, 'reconciliation' => $payload,
        ])->assertRedirect();

        $this->actingAs($this->buyer)->post($this->route(), [
            'lot_id' => $lot, 'reconciliation' => $payload,
        ])->assertSessionHasErrors();

        $this->assertSame(1, $this->rows()->count(), 'a resubmission did not double-decide the lot');
    }

    // -------------------------------------------------------- quantity report

    public function test_the_report_marks_a_receipt_that_matches_the_legacy_balance(): void
    {
        $lot = $this->legacyLot('HEAT-Q1', 'QTY-PIPE');
        $custody = $this->custody('HEAT-Q1', quantity: '5.000');
        $this->openingCount('QTY-PIPE', 'HEAT-Q1', '5.000');
        $this->nominate();

        $this->decide($lot, ['decision' => 'TAKE_OVER', 'custody_id' => $custody]);

        $row = $this->reportRow($lot);
        $this->assertSame('MATCH', $row['verdict']);
        $this->assertSame('0.000', $row['delta']);

        $this->actingAs($this->buyer)->get($this->route())
            ->assertSee('id="quantity-report"', false)
            ->assertSee(__('quality_legacy.quantity_report'), false);
    }

    public function test_the_report_shows_a_receipt_short_of_the_legacy_balance(): void
    {
        $lot = $this->legacyLot('HEAT-Q2', 'QTY-PIPE');
        $custody = $this->custody('HEAT-Q2', quantity: '4.000');
        $this->openingCount('QTY-PIPE', 'HEAT-Q2', '10.000');
        $this->nominate();

        $this->decide($lot, ['decision' => 'TAKE_OVER', 'custody_id' => $custody]);

        $row = $this->reportRow($lot);
        $this->assertSame('SHORT', $row['verdict']);
        $this->assertSame('-6.000', $row['delta'], 'a short receipt is reported, not rounded away');
    }

    public function test_the_report_shows_a_receipt_above_the_legacy_balance(): void
    {
        $lot = $this->legacyLot('HEAT-Q3', 'QTY-PIPE');
        $custody = $this->custody('HEAT-Q3', quantity: '12.000');
        $this->openingCount('QTY-PIPE', 'HEAT-Q3', '10.000');
        $this->nominate();

        $this->decide($lot, ['decision' => 'TAKE_OVER', 'custody_id' => $custody]);

        $this->assertSame('OVER', $this->reportRow($lot)['verdict']);
        $this->assertSame('2.000', $this->reportRow($lot)['delta']);
    }

    public function test_units_that_differ_are_reported_and_never_converted(): void
    {
        $lot = $this->legacyLot('HEAT-Q4', 'QTY-PIPE', unit: 'M');
        $custody = $this->custody('HEAT-Q4', quantity: '4.000', unit: 'EA');
        $this->openingCount('QTY-PIPE', 'HEAT-Q4', '10.000');
        $this->nominate();

        $this->decide($lot, ['decision' => 'TAKE_OVER', 'custody_id' => $custody]);

        $row = $this->reportRow($lot);
        $this->assertSame('UNIT_MISMATCH', $row['verdict'], 'the platform does not invent a conversion factor');
        $this->assertSame('EA', $row['receipt_unit']);
        $this->assertSame('M', $row['unit']);
    }

    public function test_a_lot_with_no_opening_count_is_reported_as_one_not_as_zero(): void
    {
        $lot = $this->legacyLot('HEAT-Q5', 'QTY-PIPE');
        $custody = $this->custody('HEAT-Q5', quantity: '4.000');
        $this->nominate();

        $this->decide($lot, ['decision' => 'TAKE_OVER', 'custody_id' => $custody]);

        $row = $this->reportRow($lot);
        $this->assertSame('NO_OPENING_COUNT', $row['verdict']);
        $this->assertSame(0, $row['legacy_entries']);
    }

    public function test_a_retired_lot_shows_the_balance_it_released(): void
    {
        $lot = $this->legacyLot('HEAT-Q6', 'QTY-PIPE');
        $this->openingCount('QTY-PIPE', 'HEAT-Q6', '7.000');
        $this->nominate();

        $this->decide($lot, ['decision' => 'RETIRE', 'reason' => 'Consumed before cutover']);

        $row = $this->reportRow($lot);
        $this->assertSame('NOT_BROUGHT_ACROSS', $row['verdict']);
        $this->assertSame('7.000', $row['legacy_quantity'], 'what we let go is on the record');
        $this->assertNull($row['receipt_quantity']);
    }

    // ------------------------------------------------------------------ helpers

    private function legacyLot(?string $heat = 'HEAT-77', string $itemCode = 'LEGACY-PIPE', string $unit = 'M'): string
    {
        return LegacyLotFixture::legacyLot($this->tenant, $this->project, $this->buyer, $itemCode, $heat, '', $unit);
    }

    private function custody(string $heat, string $quantity = '2.000', string $unit = 'M'): string
    {
        return MtrBindingFixture::custody(
            $this->tenant, $this->project, $this->buyer, $heat, null, false, null, null, $quantity, $unit,
        );
    }

    /** The legacy side of the comparison: an opening count at cutover. */
    private function openingCount(string $item, string $heat, string $quantity): string
    {
        return app(StockLedgerService::class)->post($this->project, $this->buyer, [
            'kind' => 'OPENING_COUNT',
            'item_code' => $item,
            'heat_ref' => $heat,
            'unit' => 'M',
            'quantity' => $quantity,
            'approver_user_id' => $this->userByEmail('sadeghi@pars.example')->id,
            'reason' => 'Opening physical count at cutover',
            'request_id' => (string) Str::uuid(),
        ], 'en');
    }

    /** @param  array<string,mixed>  $overrides */
    private function decide(string $lot, array $overrides): void
    {
        $this->actingAs($this->buyer)->post($this->route(), [
            'lot_id' => $lot,
            'reconciliation' => $this->payload($overrides),
        ])->assertRedirect();
    }

    /** @return array<string,mixed> */
    private function reportRow(string $lot): array
    {
        $report = $this->tenant->runAs(
            $this->buyer->company_id,
            fn () => app(\App\Services\LegacyMaterialReconciliationService::class)->quantityReport($this->project, $this->buyer),
            $this->project->id
        );

        foreach ($report as $row) {
            if ($row['lot_id'] === $lot) {
                return $row;
            }
        }

        $this->fail('The lot is missing from the quantity reconciliation report.');
    }


    private function nominate(): void
    {
        app(ResultRecorderPolicyService::class)->configure($this->project, $this->buyer, [
            'version' => 0,
            'target_company_id' => $this->buyer->company_id,
            'action' => 'reconcile_legacy',
            'selectors' => ['user:'.$this->buyer->id],
            'suspend' => false,
            'reason' => 'Fixture nomination for legacy reconciliation',
        ], 'en');
    }

    /** @param  array<string,mixed>  $overrides */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'decision' => 'RETIRE',
            'reason' => 'Consumed before cutover',
            'request_id' => (string) Str::uuid(),
        ], $overrides);
    }

    private function rows(): \Illuminate\Support\Collection
    {
        return $this->tenant->runAs(
            $this->buyer->company_id,
            fn () => DB::table('material_legacy_reconciliations')
                ->where('project_id', $this->project->id)->orderBy('sequence')->orderBy('id')->get(),
            $this->project->id
        );
    }

    /** @param  array<string,mixed>  $query */
    private function route(array $query = [], string $lang = 'fa'): string
    {
        return route('quality.legacy', ['projectCode' => $this->project->code, 'lang' => $lang] + $query);
    }
}
