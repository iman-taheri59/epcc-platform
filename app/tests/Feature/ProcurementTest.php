<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\MaterialRequisition;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Services\ProcurementService;
use App\Support\ProcurementState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * تهیه - requisition to purchase order.
 *
 * The segregation-of-duties rules are the reason this module needs tests
 * rather than just a screen. A requisition commits the employer's money, and
 * the controls that stop one person doing that alone are exactly the controls
 * that are invisible until somebody tries to break them.
 */
final class ProcurementTest extends TestCase
{
    private ProcurementService $proc;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->proc = app(ProcurementService::class);
        $this->tenant = app(TenantContext::class);
    }

    private function project(): Project
    {
        return $this->tenant->forIdentityLookup(
            fn () => Project::where('code', 'PH-14')->firstOrFail()
        );
    }

    private function company(string $code): Company
    {
        return $this->tenant->forIdentityLookup(
            fn () => Company::where('code', $code)->firstOrFail()
        );
    }

    /** @param list<array<string,mixed>>|null $lines */
    private function raise(?array $lines = null): MaterialRequisition
    {
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        return $this->tenant->runAs($pars->id, fn () => $this->proc->raiseRequisition(
            $this->project(), $rezaei, 'Valves', 'شیرآلات', 'MECHANICAL',
            $lines ?? [[
                'item_code' => 'VLV-1',
                'description_en' => 'Valve',
                'description_fa' => 'شیر',
                'unit' => 'EA',
                'quantity' => 3,
            ]],
        ), $this->project()->id);
    }

    // ---- numbering ---------------------------------------------------------

    public function test_requisitions_are_numbered_per_project(): void
    {
        $a = $this->raise();
        $b = $this->raise();

        $this->assertSame('PH-14-MR-0001', $a->number);
        $this->assertSame('PH-14-MR-0002', $b->number);
    }

    public function test_a_requisition_starts_as_a_draft(): void
    {
        $this->assertSame(ProcurementState::MR_DRAFT, $this->raise()->state);
    }

    /** An empty requisition wastes an approver's time; refuse it at source. */
    public function test_a_requisition_needs_at_least_one_line(): void
    {
        $this->expectException(ValidationException::class);
        $this->raise([]);
    }

    public function test_a_line_needs_a_positive_quantity(): void
    {
        $this->expectException(ValidationException::class);
        $this->raise([[
            'item_code' => 'VLV-1', 'description_en' => 'Valve',
            'description_fa' => 'شیر', 'unit' => 'EA', 'quantity' => 0,
        ]]);
    }

    // ---- segregation of duties --------------------------------------------

    /**
     * The control an auditor actually tests. Without it one engineer can move
     * a purchase from idea to committed spend with nobody else involved.
     */
    public function test_the_raiser_cannot_technically_approve_their_own_requisition(): void
    {
        $mr = $this->raise();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $this->tenant->runAs($pars->id, fn () => $this->proc->submitRequisition($mr, $rezaei), $this->project()->id);

        $this->expectException(ValidationException::class);
        $this->tenant->runAs(
            $pars->id,
            fn () => $this->proc->approveTechnically($mr->refresh(), $rezaei),
            $this->project()->id
        );
    }

    public function test_the_raiser_cannot_commercially_approve_their_own_requisition(): void
    {
        $mr = $this->approvedTechnically();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $this->expectException(ValidationException::class);
        $this->tenant->runAs(
            $pars->id,
            fn () => $this->proc->approveCommercially($mr->refresh(), $rezaei),
            $this->project()->id
        );
    }

    /**
     * The second, different rule: one person may not sign BOTH approvals even
     * when they did not raise the request.
     */
    public function test_one_person_cannot_sign_both_approvals(): void
    {
        $mr = $this->approvedTechnically();
        $pars = $this->company('EPC-PARS');
        $sadeghi = $this->userByEmail('sadeghi@pars.example');

        $this->expectException(ValidationException::class);
        $this->tenant->runAs(
            $pars->id,
            fn () => $this->proc->approveCommercially($mr->refresh(), $sadeghi),
            $this->project()->id
        );
    }

    public function test_the_two_approvals_are_recorded_separately(): void
    {
        $mr = $this->fullyApproved();

        $sadeghi = $this->userByEmail('sadeghi@pars.example');
        $karimi = $this->userByEmail('karimi@nioc.example');

        $this->assertSame($sadeghi->id, $mr->technical_approved_by);
        $this->assertSame($karimi->id, $mr->commercial_approved_by);
        $this->assertNotNull($mr->technical_approved_at);
        $this->assertNotNull($mr->commercial_approved_at);
        $this->assertSame(ProcurementState::MR_APPROVED, $mr->state);
    }

    // ---- state machine -----------------------------------------------------

    public function test_a_draft_cannot_skip_straight_to_approved(): void
    {
        $mr = $this->raise();
        $pars = $this->company('EPC-PARS');
        $sadeghi = $this->userByEmail('sadeghi@pars.example');

        $this->expectException(ValidationException::class);
        $this->tenant->runAs(
            $pars->id,
            fn () => $this->proc->approveTechnically($mr, $sadeghi),
            $this->project()->id
        );
    }

    public function test_a_rejection_must_state_a_reason(): void
    {
        $mr = $this->raise();
        $pars = $this->company('EPC-PARS');
        $nioc = $this->company('NIOC-AFF');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $karimi = $this->userByEmail('karimi@nioc.example');

        $this->tenant->runAs($pars->id, fn () => $this->proc->submitRequisition($mr, $rezaei), $this->project()->id);

        $this->expectException(ValidationException::class);
        $this->tenant->runAs(
            $nioc->id,
            fn () => $this->proc->rejectRequisition($mr->refresh(), $karimi, '   '),
            $this->project()->id
        );
    }

    /** Rejection is not a dead end - the usual fix is a corrected quantity. */
    public function test_a_rejected_requisition_can_go_back_to_draft(): void
    {
        $this->assertTrue(ProcurementState::mrCanTransition(
            ProcurementState::MR_REJECTED,
            ProcurementState::MR_DRAFT
        ));
    }

    // ---- purchase orders ---------------------------------------------------

    public function test_a_purchase_order_can_only_go_to_a_vendor(): void
    {
        $pars = $this->company('EPC-PARS');
        $sahand = $this->company('EPC-SAHAND'); // an EPC, not a vendor
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $this->expectException(ValidationException::class);
        $this->tenant->runAs($pars->id, fn () => $this->proc->createPurchaseOrder(
            $this->project(), $rezaei, $sahand, 'X', 'ایکس',
            [['item_code' => 'A', 'description_en' => 'A', 'description_fa' => 'الف', 'unit' => 'EA', 'quantity' => 1]],
        ), $this->project()->id);
    }

    /**
     * Naming a non-participant vendor would hand that company read access to
     * the order through the vendor clause in the RLS policy.
     */
    public function test_the_vendor_must_participate_in_the_project(): void
    {
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $outsider = $this->tenant->forIdentityLookup(fn () => Company::create([
            'code' => 'VEN-OUTSIDER', 'name_en' => 'Outsider', 'name_fa' => 'بیرونی',
            'tenant_type' => 'VEN', 'is_active' => true,
        ]));

        $this->expectException(ValidationException::class);
        $this->tenant->runAs($pars->id, fn () => $this->proc->createPurchaseOrder(
            $this->project(), $rezaei, $outsider, 'X', 'ایکس',
            [['item_code' => 'A', 'description_en' => 'A', 'description_fa' => 'الف', 'unit' => 'EA', 'quantity' => 1]],
        ), $this->project()->id);
    }

    /** Ordering off an unapproved MR would defeat both approvals. */
    public function test_only_an_approved_requisition_can_become_an_order(): void
    {
        $mr = $this->raise();
        $pars = $this->company('EPC-PARS');
        $azar = $this->company('VEN-AZAR');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $this->expectException(ValidationException::class);
        $this->tenant->runAs($pars->id, fn () => $this->proc->createPurchaseOrder(
            $this->project(), $rezaei, $azar, 'X', 'ایکس',
            [['item_code' => 'A', 'description_en' => 'A', 'description_fa' => 'الف', 'unit' => 'EA', 'quantity' => 1]],
            $mr,
        ), $this->project()->id);
    }

    public function test_ordering_closes_the_requisition(): void
    {
        $mr = $this->fullyApproved();
        $this->orderFrom($mr);

        // refresh() issues its own query, so it needs a tenant binding too -
        // outside one, RLS hides the row and Eloquent reports it as missing.
        $state = $this->tenant->runAs(
            $this->company('EPC-PARS')->id,
            fn () => MaterialRequisition::findOrFail($mr->id)->state,
            $this->project()->id
        );

        $this->assertSame(ProcurementState::MR_ORDERED, $state);
    }

    /**
     * An unpriced order must not report a total of zero - that renders as
     * "free" in every downstream report.
     */
    public function test_an_unpriced_order_has_no_total_rather_than_zero(): void
    {
        $pars = $this->company('EPC-PARS');
        $azar = $this->company('VEN-AZAR');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $po = $this->tenant->runAs($pars->id, fn () => $this->proc->createPurchaseOrder(
            $this->project(), $rezaei, $azar, 'X', 'ایکس',
            [['item_code' => 'A', 'description_en' => 'A', 'description_fa' => 'الف', 'unit' => 'EA', 'quantity' => 5]],
        ), $this->project()->id);

        $this->assertNull($po->total_value);
    }

    public function test_a_priced_order_totals_its_lines(): void
    {
        $pars = $this->company('EPC-PARS');
        $azar = $this->company('VEN-AZAR');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $po = $this->tenant->runAs($pars->id, fn () => $this->proc->createPurchaseOrder(
            $this->project(), $rezaei, $azar, 'X', 'ایکس',
            [
                ['item_code' => 'A', 'description_en' => 'A', 'description_fa' => 'الف', 'unit' => 'EA', 'quantity' => 2, 'unit_price' => 100],
                ['item_code' => 'B', 'description_en' => 'B', 'description_fa' => 'ب', 'unit' => 'EA', 'quantity' => 3, 'unit_price' => 10],
            ],
        ), $this->project()->id);

        $this->assertSame('230.00', (string) $po->total_value);
    }

    // ---- expediting --------------------------------------------------------

    /**
     * The promise is what the contract says; the forecast is what the vendor
     * now admits. A system that lets one overwrite the other makes every
     * order look on time.
     */
    public function test_a_forecast_does_not_overwrite_the_promised_date(): void
    {
        $po = $this->issuedOrder('2026-08-01');
        $pars = $this->company('EPC-PARS');

        $this->tenant->runAs($pars->id, fn () => $this->proc->forecast($po, '2026-08-21'), $this->project()->id);

        $fresh = $this->tenant->runAs(
            $pars->id,
            fn () => PurchaseOrder::findOrFail($po->id),
            $this->project()->id
        );

        $this->assertSame('2026-08-01', $fresh->promised_date->format('Y-m-d'));
        $this->assertSame(20, $fresh->slipDays());
        $this->assertTrue($fresh->isLate());
    }

    /** "No forecast" and "on time" are different facts. */
    public function test_slip_is_null_when_there_is_no_forecast(): void
    {
        $po = $this->issuedOrder('2026-08-01');

        $this->assertNull($po->slipDays());
        $this->assertFalse($po->isLate());
    }

    // ---- RLS: the commercial boundary --------------------------------------

    /**
     * The rule that makes this module safe to sell. Vendors on one project are
     * frequently competitors, and a PO carries prices.
     */
    public function test_a_vendor_sees_its_own_order_and_not_another_vendors(): void
    {
        $pars = $this->company('EPC-PARS');
        $azar = $this->company('VEN-AZAR');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $project = $this->project();

        $mine = $this->tenant->runAs($pars->id, fn () => $this->proc->createPurchaseOrder(
            $project, $rezaei, $azar, 'Mine', 'مال من',
            [['item_code' => 'A', 'description_en' => 'A', 'description_fa' => 'الف', 'unit' => 'EA', 'quantity' => 1, 'unit_price' => 999]],
        ), $project->id);

        // A second vendor, also on the project, with its own order.
        $rival = $this->tenant->forIdentityLookup(fn () => Company::create([
            'code' => 'VEN-RIVAL', 'name_en' => 'Rival', 'name_fa' => 'رقیب',
            'tenant_type' => 'VEN', 'is_active' => true,
        ]));

        // id and timestamps are explicit: project_company has no model and
        // therefore no HasUuid boot hook to fill them in.
        \Illuminate\Support\Facades\DB::table('project_company')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'project_id' => $project->id,
            'company_id' => $rival->id,
            'role' => 'VENDOR',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $theirs = $this->tenant->runAs($pars->id, fn () => $this->proc->createPurchaseOrder(
            $project, $rezaei, $rival, 'Theirs', 'مال آنها',
            [['item_code' => 'B', 'description_en' => 'B', 'description_fa' => 'ب', 'unit' => 'EA', 'quantity' => 1, 'unit_price' => 111]],
        ), $project->id);

        $visible = $this->tenant->runAs(
            $azar->id,
            fn () => PurchaseOrder::pluck('number')->all(),
            $project->id
        );

        $this->assertContains($mine->number, $visible, 'A vendor must see the order addressed to it.');
        $this->assertNotContains(
            $theirs->number,
            $visible,
            'A vendor must NOT see a competitor\'s order - it carries their prices.'
        );
    }

    /** A requisition is the buyer's own planning; vendors never see it. */
    public function test_a_vendor_cannot_see_requisitions_at_all(): void
    {
        $this->raise();
        $azar = $this->company('VEN-AZAR');

        $visible = $this->tenant->runAs(
            $azar->id,
            fn () => MaterialRequisition::count(),
            $this->project()->id
        );

        $this->assertSame(0, $visible);
    }

    /** The employer approves the spend, so it must be able to read them. */
    public function test_the_employer_can_see_requisitions(): void
    {
        $this->raise();
        $nioc = $this->company('NIOC-AFF');

        $visible = $this->tenant->runAs(
            $nioc->id,
            fn () => MaterialRequisition::count(),
            $this->project()->id
        );

        $this->assertSame(1, $visible);
    }

    // ---- helpers -----------------------------------------------------------

    private function approvedTechnically(): MaterialRequisition
    {
        $mr = $this->raise();
        $pars = $this->company('EPC-PARS');
        $rezaei = $this->userByEmail('rezaei@pars.example');
        $sadeghi = $this->userByEmail('sadeghi@pars.example');

        $this->tenant->runAs($pars->id, fn () => $this->proc->submitRequisition($mr, $rezaei), $this->project()->id);

        return $this->tenant->runAs(
            $pars->id,
            fn () => $this->proc->approveTechnically($mr->refresh(), $sadeghi),
            $this->project()->id
        );
    }

    private function fullyApproved(): MaterialRequisition
    {
        $mr = $this->approvedTechnically();
        $nioc = $this->company('NIOC-AFF');
        $karimi = $this->userByEmail('karimi@nioc.example');

        return $this->tenant->runAs(
            $nioc->id,
            fn () => $this->proc->approveCommercially($mr->refresh(), $karimi),
            $this->project()->id
        );
    }

    private function orderFrom(MaterialRequisition $mr): PurchaseOrder
    {
        $pars = $this->company('EPC-PARS');
        $azar = $this->company('VEN-AZAR');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        return $this->tenant->runAs($pars->id, fn () => $this->proc->createPurchaseOrder(
            $this->project(), $rezaei, $azar, 'X', 'ایکس',
            [['item_code' => 'A', 'description_en' => 'A', 'description_fa' => 'الف', 'unit' => 'EA', 'quantity' => 1]],
            $mr,
        ), $this->project()->id);
    }

    private function issuedOrder(?string $promised = null): PurchaseOrder
    {
        $pars = $this->company('EPC-PARS');
        $azar = $this->company('VEN-AZAR');
        $rezaei = $this->userByEmail('rezaei@pars.example');

        $po = $this->tenant->runAs($pars->id, fn () => $this->proc->createPurchaseOrder(
            $this->project(), $rezaei, $azar, 'X', 'ایکس',
            [['item_code' => 'A', 'description_en' => 'A', 'description_fa' => 'الف', 'unit' => 'EA', 'quantity' => 10]],
            null, 'IRR', $promised,
        ), $this->project()->id);

        return $this->tenant->runAs(
            $pars->id,
            fn () => $this->proc->issuePurchaseOrder($po, $rezaei),
            $this->project()->id
        );
    }
}
