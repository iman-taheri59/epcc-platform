<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialLot;
use App\Models\MaterialMovement;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Subsystem;
use App\Services\ProcurementService;
use App\Services\WarehouseService;
use App\Support\MovementType;
use App\Support\ProcurementState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * بازرسی، ورود به انبار، خروج از انبار، ورود به سایت.
 *
 * The arithmetic is the product here. A warehouse module that can be talked
 * into a negative balance, or that double-counts a site delivery, is worse
 * than no module: people trust the number and stop counting the steel.
 */
final class WarehouseTest extends TestCase
{
    private WarehouseService $wh;

    private ProcurementService $proc;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->wh = app(WarehouseService::class);
        $this->proc = app(ProcurementService::class);
        $this->tenant = app(TenantContext::class);
    }

    private function project(): Project
    {
        return $this->tenant->forIdentityLookup(fn () => Project::where('code', 'PH-14')->firstOrFail());
    }

    private function company(string $code): Company
    {
        return $this->tenant->forIdentityLookup(fn () => Company::where('code', $code)->firstOrFail());
    }

    private function subsystem(string $code = 'PH-14-SS-2201'): Subsystem
    {
        return $this->tenant->runAs(
            $this->company('EPC-PARS')->id,
            fn () => Subsystem::where('code', $code)->firstOrFail(),
            $this->project()->id
        );
    }

    /** @param callable():mixed $fn */
    private function asPars(callable $fn): mixed
    {
        return $this->tenant->runAs($this->company('EPC-PARS')->id, $fn, $this->project()->id);
    }

    /** @param callable():mixed $fn */
    private function asKaran(callable $fn): mixed
    {
        return $this->tenant->runAs($this->company('MC-KARAN')->id, $fn, $this->project()->id);
    }

    private function issuedOrder(float $qty = 100): PurchaseOrder
    {
        $azar = $this->company('VEN-AZAR');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $po = $this->asPars(fn () => $this->proc->createPurchaseOrder(
            $this->project(), $rezaei, $azar, 'Pipe', 'لوله',
            [[
                'item_code' => 'PIP-1', 'description_en' => 'Pipe', 'description_fa' => 'لوله',
                'unit' => 'M', 'quantity' => $qty,
                'subsystem_id' => $this->subsystem()->id,
            ]],
        ));

        return $this->asPars(fn () => $this->proc->issuePurchaseOrder($po, $rezaei));
    }

    private function receive(float $qty = 100, ?PurchaseOrder $po = null, ?string $heat = null): MaterialLot
    {
        $po ??= $this->issuedOrder(max($qty, 100));
        $rezaei = $this->userByEmail('rezaei@pars.example');

        return $this->asPars(fn () => $this->wh->receive(
            $this->project(), $rezaei, $po->fresh(),
            'PIP-1', 'Pipe', 'لوله', 'M', $qty,
            heatNumber: $heat,
        ));
    }

    // ---- ورود به انبار -----------------------------------------------------

    public function test_a_receipt_creates_a_lot_and_a_movement(): void
    {
        $lot = $this->receive(100);

        $this->assertSame('PH-14-LOT-0001', $lot->lot_code);
        $this->assertSame(100.0, $this->asPars(fn () => $lot->fresh()->balance()));

        $movement = $this->asPars(fn () => MaterialMovement::where('lot_id', $lot->id)->firstOrFail());
        $this->assertSame(MovementType::RECEIPT, $movement->type);
        $this->assertStringStartsWith('PH-14-GRN-', $movement->number);
    }

    /**
     * Receiving against a draft order means material arrived for something
     * nobody ordered. That needs a human, not a silent stock increase.
     */
    public function test_goods_cannot_be_received_against_an_unissued_order(): void
    {
        $azar = $this->company('VEN-AZAR');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $draft = $this->asPars(fn () => $this->proc->createPurchaseOrder(
            $this->project(), $rezaei, $azar, 'Pipe', 'لوله',
            [['item_code' => 'PIP-1', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => 10]],
        ));

        $this->expectException(ValidationException::class);
        $this->asPars(fn () => $this->wh->receive(
            $this->project(), $rezaei, $draft, 'PIP-1', 'Pipe', 'لوله', 'M', 10
        ));
    }

    public function test_receiving_everything_marks_the_order_fully_received(): void
    {
        $po = $this->issuedOrder(100);
        $this->receive(100, $po);

        $fresh = $this->asPars(fn () => PurchaseOrder::findOrFail($po->id));
        $this->assertSame(ProcurementState::PO_RECEIVED, $fresh->state);
    }

    public function test_receiving_part_marks_the_order_partially_received(): void
    {
        $po = $this->issuedOrder(100);
        $this->receive(40, $po);

        $fresh = $this->asPars(fn () => PurchaseOrder::findOrFail($po->id));
        $this->assertSame(ProcurementState::PO_PARTIALLY_RECEIVED, $fresh->state);
    }

    // ---- خروج از انبار -----------------------------------------------------

    public function test_issuing_reduces_the_balance(): void
    {
        $lot = $this->receive(100);
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $this->asPars(fn () => $this->wh->issue(
            $this->project(), $rezaei, $lot->fresh(), 30, $this->subsystem()
        ));

        $this->assertSame(70.0, $this->asPars(fn () => $lot->fresh()->balance()));
    }

    /**
     * The rule that keeps the ledger honest. Without it the balance goes
     * negative and every downstream figure is fiction.
     */
    public function test_you_cannot_issue_more_than_the_warehouse_holds(): void
    {
        $lot = $this->receive(50);
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $this->expectException(ValidationException::class);
        $this->asPars(fn () => $this->wh->issue(
            $this->project(), $rezaei, $lot->fresh(), 51, $this->subsystem()
        ));
    }

    public function test_issuing_exactly_the_balance_is_allowed(): void
    {
        $lot = $this->receive(50);
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $this->asPars(fn () => $this->wh->issue(
            $this->project(), $rezaei, $lot->fresh(), 50, $this->subsystem()
        ));

        $this->assertSame(0.0, $this->asPars(fn () => $lot->fresh()->balance()));
    }

    // ---- ورود به سایت ------------------------------------------------------

    /**
     * SITE must not deduct stock a second time - the material already left at
     * ISSUE. This is the double-count that makes a warehouse module useless.
     */
    public function test_a_site_delivery_does_not_deduct_stock_twice(): void
    {
        $lot = $this->receive(100);
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $ss = $this->subsystem();

        $this->asPars(fn () => $this->wh->issue($this->project(), $rezaei, $lot->fresh(), 40, $ss));
        $this->assertSame(60.0, $this->asPars(fn () => $lot->fresh()->balance()));

        $this->asPars(fn () => $this->wh->deliverToSite($this->project(), $rezaei, $lot->fresh(), $ss, 40));

        $this->assertSame(
            60.0,
            $this->asPars(fn () => $lot->fresh()->balance()),
            'A site delivery tracks material already issued; it must not reduce stock again.'
        );
        $this->assertSame(40.0, $this->asPars(fn () => $lot->fresh()->deliveredToSite()));
    }

    /** Otherwise "in transit" goes negative and stops meaning anything. */
    public function test_you_cannot_deliver_to_site_more_than_was_issued(): void
    {
        $lot = $this->receive(100);
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $ss = $this->subsystem();

        $this->asPars(fn () => $this->wh->issue($this->project(), $rezaei, $lot->fresh(), 30, $ss));

        $this->expectException(ValidationException::class);
        $this->asPars(fn () => $this->wh->deliverToSite($this->project(), $rezaei, $lot->fresh(), $ss, 31));
    }

    public function test_material_can_sit_in_transit_between_issue_and_site(): void
    {
        $lot = $this->receive(100);
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $ss = $this->subsystem();

        $this->asPars(fn () => $this->wh->issue($this->project(), $rezaei, $lot->fresh(), 80, $ss));
        $this->asPars(fn () => $this->wh->deliverToSite($this->project(), $rezaei, $lot->fresh(), $ss, 50));

        $fresh = $this->asPars(fn () => $lot->fresh());
        $this->assertSame(20.0, $this->asPars(fn () => $fresh->balance()));
        $this->assertSame(50.0, $this->asPars(fn () => $fresh->deliveredToSite()));
        // 80 issued - 50 delivered = 30 somewhere between the gate and the rack.
    }

    // ---- reversal ----------------------------------------------------------

    public function test_a_mistake_is_corrected_by_a_reversing_movement(): void
    {
        $lot = $this->receive(100);
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $issue = $this->asPars(fn () => $this->wh->issue(
            $this->project(), $rezaei, $lot->fresh(), 30, $this->subsystem()
        ));

        $this->assertSame(70.0, $this->asPars(fn () => $lot->fresh()->balance()));

        $this->asPars(fn () => $this->wh->reverse($issue, $rezaei, 'اشتباه در ثبت مقدار'));

        $this->assertSame(
            100.0,
            $this->asPars(fn () => $lot->fresh()->balance()),
            'Reversing an issue must put the stock back.'
        );
    }

    /** The original row survives: both facts are part of the audit trail. */
    public function test_a_reversal_does_not_delete_the_original(): void
    {
        $lot = $this->receive(100);
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $issue = $this->asPars(fn () => $this->wh->issue(
            $this->project(), $rezaei, $lot->fresh(), 30, $this->subsystem()
        ));
        $this->asPars(fn () => $this->wh->reverse($issue, $rezaei, 'اشتباه'));

        $rows = $this->asPars(fn () => MaterialMovement::where('lot_id', $lot->id)->count());

        $this->assertSame(3, $rows, 'Receipt + issue + reversal - nothing is removed.');
        $this->assertNotNull($this->asPars(fn () => MaterialMovement::find($issue->id)));
    }

    public function test_a_reversal_must_state_a_reason(): void
    {
        $lot = $this->receive(100);
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $issue = $this->asPars(fn () => $this->wh->issue(
            $this->project(), $rezaei, $lot->fresh(), 30, $this->subsystem()
        ));

        $this->expectException(ValidationException::class);
        $this->asPars(fn () => $this->wh->reverse($issue, $rezaei, '  '));
    }

    public function test_the_same_movement_cannot_be_reversed_twice(): void
    {
        $lot = $this->receive(100);
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $issue = $this->asPars(fn () => $this->wh->issue(
            $this->project(), $rezaei, $lot->fresh(), 30, $this->subsystem()
        ));

        $this->asPars(fn () => $this->wh->reverse($issue, $rezaei, 'once'));

        $this->expectException(ValidationException::class);
        $this->asPars(fn () => $this->wh->reverse($issue, $rezaei, 'twice'));
    }

    // ---- بازرسی: advisory, by the user's explicit choice -------------------

    /**
     * THE decision of this module. A failed inspection records a warning and
     * lets the material in, because a system that refuses the receipt gets
     * bypassed with a paper note nobody ever types in.
     */
    public function test_a_failed_inspection_does_not_block_a_receipt(): void
    {
        $po = $this->issuedOrder(100);
        $jafari = $this->userByEmail('jafari@karan.example');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $fail = $this->asKaran(fn () => $this->wh->inspect(
            $this->project(), $jafari,
            ProcurementState::INSPECTION_FAIL,
            ProcurementState::STAGE_ON_ARRIVAL,
            $po->fresh(),
            'Wall thickness below tolerance.',
            'ضخامت جداره زیر رواداری است.',
        ));

        $lot = $this->asPars(fn () => $this->wh->receive(
            $this->project(), $rezaei, $po->fresh(),
            'PIP-1', 'Pipe', 'لوله', 'M', 100,
            inspection: $fail,
        ));

        $this->assertSame(100.0, $this->asPars(fn () => $lot->fresh()->balance()));
        $this->assertSame(ProcurementState::INSPECTION_FAIL, $lot->inspection_result);
    }

    /** Advisory is only meaningful if the warning is actually visible. */
    public function test_material_that_failed_or_was_never_inspected_is_reported(): void
    {
        $po = $this->issuedOrder(200);
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $jafari = $this->userByEmail('jafari@karan.example');

        $pass = $this->asKaran(fn () => $this->wh->inspect(
            $this->project(), $jafari,
            ProcurementState::INSPECTION_PASS,
            ProcurementState::STAGE_WORKS,
            $po->fresh(),
        ));

        // inspected and good
        $this->asPars(fn () => $this->wh->receive(
            $this->project(), $rezaei, $po->fresh(), 'PIP-1', 'Pipe', 'لوله', 'M', 50,
            heatNumber: 'A-1', inspection: $pass,
        ));

        // never inspected at all
        $this->asPars(fn () => $this->wh->receive(
            $this->project(), $rezaei, $po->fresh(), 'PIP-1', 'Pipe', 'لوله', 'M', 50,
            heatNumber: 'A-2',
        ));

        $flagged = $this->asPars(fn () => $this->wh->uninspectedLots($this->project()))
            ->pluck('heat_number')->all();

        $this->assertContains('A-2', $flagged, 'An uninspected lot must be reported.');
        $this->assertNotContains('A-1', $flagged);
    }

    /** Not inspected is NOT the same as passed and must never render as one. */
    public function test_never_inspected_counts_as_needing_attention(): void
    {
        $this->assertTrue(ProcurementState::needsAttention(null));
        $this->assertTrue(ProcurementState::needsAttention(ProcurementState::INSPECTION_FAIL));
        $this->assertTrue(ProcurementState::needsAttention(ProcurementState::INSPECTION_CONDITIONAL));
        $this->assertFalse(ProcurementState::needsAttention(ProcurementState::INSPECTION_PASS));
    }

    /**
     * Advisory applies to the VERDICT, not to who may give it. A contractor
     * signing off its own material would make the record look like assurance
     * while carrying none.
     */
    public function test_a_contractor_cannot_inspect_its_own_material(): void
    {
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $this->expectException(ValidationException::class);
        $this->asPars(fn () => $this->wh->inspect(
            $this->project(), $rezaei,
            ProcurementState::INSPECTION_PASS,
            ProcurementState::STAGE_WORKS,
        ));
    }

    public function test_a_failed_inspection_must_record_findings(): void
    {
        $jafari = $this->userByEmail('jafari@karan.example');

        $this->expectException(ValidationException::class);
        $this->asKaran(fn () => $this->wh->inspect(
            $this->project(), $jafari,
            ProcurementState::INSPECTION_FAIL,
            ProcurementState::STAGE_ON_ARRIVAL,
        ));
    }

    // ---- traceability ------------------------------------------------------

    /**
     * A heat number with no certificate behind it is not traceability - the
     * TPI cannot produce anything at the audit.
     */
    public function test_traceability_needs_both_an_identifier_and_a_certificate(): void
    {
        $lot = $this->receive(10, heat: 'A-4471');

        $this->assertFalse(
            $this->asPars(fn () => $lot->fresh()->isFullyTraceable()),
            'A heat number without an MTC is not full traceability.'
        );

        $doc = $this->asPars(fn () => \App\Models\Document::where('project_id', $this->project()->id)->first());
        $this->asPars(fn () => $lot->update(['mtc_document_id' => $doc->id]));

        $this->assertTrue($this->asPars(fn () => $lot->fresh()->isFullyTraceable()));
    }

    /** The MTC is a CDE document, so it inherits revisions and access control. */
    public function test_the_material_certificate_is_a_document_in_the_register(): void
    {
        $doc = $this->asPars(fn () => \App\Models\Document::where('project_id', $this->project()->id)->firstOrFail());
        $po = $this->issuedOrder(10);
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $lot = $this->asPars(fn () => $this->wh->receive(
            $this->project(), $rezaei, $po->fresh(), 'PIP-1', 'Pipe', 'لوله', 'M', 10,
            heatNumber: 'A-9', mtcDocumentId: $doc->id,
        ));

        $this->assertSame($doc->number, $this->asPars(fn () => $lot->fresh()->mtcDocument->number));
    }

    // ---- the MC link the user asked for ------------------------------------

    /**
     * The point of wiring material to subsystems: a subsystem whose valves are
     * still in the warehouse is not mechanically complete, however few punch
     * items it has.
     */
    public function test_material_still_in_the_warehouse_shows_as_a_shortage(): void
    {
        $ss = $this->subsystem();
        $po = $this->issuedOrder(100);
        $lot = $this->receive(100, $po);
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $status = $this->asPars(fn () => $this->wh->subsystemMaterialStatus($ss));
        $this->assertSame(100.0, $status['shortfall']);
        $this->assertFalse($status['complete']);

        $this->asPars(fn () => $this->wh->issue($this->project(), $rezaei, $lot->fresh(), 100, $ss));
        $this->asPars(fn () => $this->wh->deliverToSite($this->project(), $rezaei, $lot->fresh(), $ss, 100));

        $after = $this->asPars(fn () => $this->wh->subsystemMaterialStatus($ss));
        $this->assertSame(0.0, $after['shortfall']);
        $this->assertTrue($after['complete']);
    }

    /**
     * Issued-but-not-delivered must still count as short. This is the case the
     * whole two-step ISSUE/SITE split exists to catch: the storekeeper has
     * booked it out, and it is on a laydown area, not on the plant.
     */
    public function test_issued_but_not_yet_on_site_is_still_a_shortage(): void
    {
        $ss = $this->subsystem();
        $po = $this->issuedOrder(100);
        $lot = $this->receive(100, $po);
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $this->asPars(fn () => $this->wh->issue($this->project(), $rezaei, $lot->fresh(), 100, $ss));

        $status = $this->asPars(fn () => $this->wh->subsystemMaterialStatus($ss));

        $this->assertSame(100.0, $status['shortfall'], 'Material on a laydown area has not reached the subsystem.');
        $this->assertFalse($status['complete']);
    }

    /**
     * A subsystem that buys nothing is not short of anything. Same call as
     * closurePercent() returning 100 for an empty punch list - absence of a
     * requirement is not a failure.
     */
    public function test_a_subsystem_with_no_material_is_not_reported_short(): void
    {
        $civil = $this->subsystem('PH-14-SS-4401');

        $status = $this->asPars(fn () => $this->wh->subsystemMaterialStatus($civil));

        $this->assertSame(0.0, $status['ordered']);
        $this->assertTrue($status['complete']);
    }

    // ---- RLS ----------------------------------------------------------------

    /**
     * Stock is operational, not commercial: the employer is entitled to know
     * whether the valve is on site. That is the whole point of killing the
     * weekly spreadsheet.
     */
    public function test_the_employer_can_read_stock(): void
    {
        $this->receive(100);
        $nioc = $this->company('NIOC-AFF');

        $count = $this->tenant->runAs(
            $nioc->id,
            fn () => MaterialLot::count(),
            $this->project()->id
        );

        $this->assertSame(1, $count);
    }

    /** A company that is not on the project sees nothing. */
    public function test_a_non_participant_sees_no_stock(): void
    {
        $this->receive(100);
        $arvand = $this->company('EPC-ARVAND'); // KISH-1 only

        $count = $this->tenant->runAs(
            $arvand->id,
            fn () => MaterialLot::count(),
            $this->project()->id
        );

        $this->assertSame(0, $count);
    }

    // ---- movement semantics -------------------------------------------------

    public function test_the_sign_convention_lives_in_one_place(): void
    {
        $this->assertSame(1, MovementType::sign(MovementType::RECEIPT));
        $this->assertSame(-1, MovementType::sign(MovementType::ISSUE));
        $this->assertSame(1, MovementType::sign(MovementType::RETURN));
        $this->assertSame(
            0,
            MovementType::sign(MovementType::SITE),
            'SITE tracks material already issued - counting it again would double-deduct.'
        );
    }

    public function test_every_movement_type_is_numbered_with_its_own_prefix(): void
    {
        $this->assertSame('GRN', MovementType::prefix(MovementType::RECEIPT));
        $this->assertSame('MIV', MovementType::prefix(MovementType::ISSUE));
        $this->assertSame('SDN', MovementType::prefix(MovementType::SITE));
        $this->assertSame('MRN', MovementType::prefix(MovementType::RETURN));
    }

    public function test_movement_labels_are_bilingual(): void
    {
        foreach (MovementType::ALL as $type) {
            $label = MovementType::label($type);
            $this->assertNotSame('', $label['fa']);
            $this->assertNotSame('', $label['en']);
            $this->assertNotSame($label['fa'], $label['en']);
        }
    }
}
