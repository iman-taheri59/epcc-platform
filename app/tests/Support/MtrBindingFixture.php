<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Services\InspectionResultService;
use App\Services\PipingCustodyAmendmentService;
use App\Services\PipingCustodyService;
use App\Services\PipingReceiptAccessService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Services\PurchaseOrderExecutionAccessService;
use App\Services\PurchaseOrderExecutionService;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The two things an MTR binding connects: custody that attests a heat, and a
 * certificate that claims one.
 *
 * Receiving material into custody is not one insert either — the receipt
 * register has to nominate a custodian, and naming the ITEM (which the binding
 * then derives as `item_code`) needs a purchase-order line behind it, which
 * needs its own authority configuration, a financial review and an issue. None
 * of that is what a binding test is about, so it lives here and is built
 * through the real services.
 *
 * Two things are deliberately left explicit rather than defaulted:
 *
 * - `configureAccess()` is separate, because the receipt register demands a
 *   nominee for every action and a fixture that quietly configured it would
 *   hide the authority model from whoever reads the test.
 * - `linked: true` is not the default. A custody record with no source line is
 *   the ordinary case for material whose paperwork has not caught up, and the
 *   binding must still work — it just cannot name an item.
 */
final class MtrBindingFixture
{
    /**
     * Nominate a custodian, a receiving QC and a QA acceptor, so custody can
     * exist at all. Idempotent: calling it twice is the same configuration.
     */
    public static function configureAccess(TenantContext $tenant, Project $project, User $owner, User $buyer, User $qc): void
    {
        app(PipingReceiptAccessService::class)->configure($project, $owner, [
            'version' => 0,
            'suspend' => false,
            'reason' => 'MTR binding fixture receipt register',
            'custodian_company_id' => $buyer->company_id,
            'actions' => [
                'custody_mrr' => ['user:'.$buyer->id],
                'receiving_qc' => ['user:'.$qc->id],
                'acceptance_qa' => ['user:'.$owner->id],
                'post_grn' => ['user:'.$buyer->id],
            ],
        ]);
    }

    /**
     * Custody attesting $heat — and, when $lot is given, amended to attest the
     * lot too. $linked gives the record a purchase-order source line, which is
     * the only way the binding can derive an item code.
     */
    public static function custody(
        TenantContext $tenant,
        Project $project,
        User $buyer,
        string $heat,
        ?string $lot = null,
        bool $linked = false,
        ?User $reviewer = null,
        ?User $issuer = null,
    ): string {
        $arrival = [
            'expected_policy_id' => app(PipingCustodyService::class)->policyId($project, $buyer, 'custody_mrr'),
            'request_id' => (string) Str::uuid(),
            'source_line_id' => $linked ? self::sourceLine($tenant, $project, $buyer, $reviewer, $issuer) : null,
            'unlinked_reason' => $linked ? null : 'Source pending',
            'shipment_ref' => 'MTR-FIXTURE',
            'package_ref' => null,
            'received_at' => '2026-10-29T08:00:00+03:30',
            'observed_description' => 'Fixture receipt',
            'quantity' => '2.000',
            'unit' => 'M',
            'heat_ref' => $heat,
            'location_kind' => 'QUARANTINE',
            'location_ref' => 'Bay Q',
            'document_status' => 'MISSING',
            'notes' => null,
        ];

        $id = app(PipingCustodyService::class)->create($project, $buyer, $arrival);

        if ($lot !== null) {
            app(PipingCustodyAmendmentService::class)->create($project, $buyer, $id, [
                'expected_policy_id' => app(PipingCustodyService::class)->policyId($project, $buyer, 'custody_mrr'),
                'request_id' => (string) Str::uuid(),
                'previous_amendment_id' => null,
                'source_line_id' => $arrival['source_line_id'],
                'unlinked_reason' => $arrival['source_line_id'] === null ? 'Source pending' : null,
                'heat_ref' => $heat,
                'lot_ref' => $lot,
                'reason' => 'Record the lot for traceability',
            ]);
        }

        return $id;
    }

    /** Registers a certificate claiming $heat (and optionally $lot). */
    public static function certificate(Project $project, User $actor, string $heat, ?string $lot = null, string $ref = ''): string
    {
        return app(InspectionResultService::class)->registerCertificate($project, $actor, [
            'certificate_type' => 'EN10204_3_1',
            'heat_ref' => $heat,
            'lot_ref' => $lot,
            'certificate_ref' => $ref !== '' ? $ref : 'MTR-'.Str::upper(Str::random(6)),
            'issuer_name' => 'Fixture Mill',
            'request_id' => (string) Str::uuid(),
        ], 'en');
    }

    /**
     * A PIPING purchase-order item, so custody can name what it received.
     *
     * Issuing a PO needs two named people — a financial review and then an
     * issue — and the second may not be the person who drafted the order or the
     * one who reviewed it. Both must come from the caller: guessing them here
     * produced a bare 403 that looked like a rule about purchase orders when it
     * was really a rule about which names were picked.
     */
    private static function sourceLine(
        TenantContext $tenant,
        Project $project,
        User $buyer,
        ?User $reviewer,
        ?User $issuer,
    ): string {
        if ($reviewer === null || $issuer === null) {
            throw new \InvalidArgumentException(
                'Custody linked to a purchase-order line needs both a financial reviewer and an issuer; name them.'
            );
        }

        $owner = $tenant->forIdentityLookup(fn () => User::where('company_id', $project->owner_company_id)
            ->where('is_active', true)->firstOrFail());

        app(PurchaseOrderAccessService::class)->configure($project, $owner, [
            'version' => 0,
            'selectors' => ['user:'.$buyer->id],
            'suspend' => false,
            'reason' => 'MTR binding fixture source',
        ]);

        $vendor = Company::where('code', 'VEN-AZAR')->firstOrFail();
        $po = app(PurchaseOrderEntryService::class)->create($project, $buyer, [
            'currency' => 'USD',
            'title_en' => 'MTR fixture PO',
            'title_fa' => 'منبع آزمایشی',
            'discipline' => 'PIPING',
            'material_category' => 'PIPE',
            'vendor_company_id' => $vendor->id,
            'lines' => [[
                'item_code' => 'MTR-PIPE', 'description_en' => 'Pipe', 'description_fa' => 'لوله',
                'unit' => 'M', 'quantity' => '5.000', 'unit_price' => '10.01',
            ]],
        ]);

        $qc = $reviewer;

        app(PurchaseOrderExecutionAccessService::class)->configure($project, $owner, [
            'buyer_company_id' => $buyer->company_id,
            'version' => 0,
            'finance_selectors' => ['user:'.$qc->id],
            'issue_selectors' => ['user:'.$issuer->id],
            'suspend' => false,
            'reason' => 'MTR binding fixture PO issue',
        ]);

        $exec = app(PurchaseOrderExecutionService::class);
        $hash = $tenant->runAs($buyer->company_id, fn () => $exec->hash($po), $project->id);
        $exec->act($project, $qc, $buyer->company_id, $po->id, 'finance', [
            'content_hash' => $hash, 'reason' => 'Fixture financial review',
        ]);
        $approval = $tenant->runAs($buyer->company_id, fn () => DB::table('po_execution_decisions')
            ->where('purchase_order_id', $po->id)->value('id'), $project->id);
        $exec->act($project, $issuer, $buyer->company_id, $po->id, 'issue', [
            'content_hash' => $hash, 'reason' => 'Fixture issue', 'approval_id' => $approval,
        ]);

        return (string) $tenant->runAs($buyer->company_id, fn () => DB::table('purchase_order_items')
            ->where('purchase_order_id', $po->id)->value('id'), $project->id);
    }
}
