<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\User;
use App\Services\InspectionResultService;
use App\Services\MaterialCertificateBindingService;
use App\Services\PipingCustodyAmendmentService;
use App\Services\PipingCustodyService;
use App\Services\PipingReceiptAccessService;
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
use Illuminate\Validation\ValidationException;
use Tests\Support\ResultRecorderPolicyFixture;
use Tests\TestCase;

/**
 * ADR-048 A.4e slice 1 — binding an MTR to the custody that attests its heat
 * (and, when the certificate claims one, its lot).
 *
 * Before this slice the certificate registry spoke free-text heat/lot while
 * custody spoke (item, heat), and nothing joined them: a certificate could be
 * registered for a heat nobody ever received, and the turnover question —
 * which heats we hold have no certificate — had no answer.
 */
final class MtrCustodyBindingTest extends TestCase
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
            'version' => 0, 'suspend' => false, 'reason' => 'Synthetic MTR binding matrix',
            'custodian_company_id' => $this->buyer->company_id,
            'actions' => [
                'custody_mrr' => ['user:'.$this->buyer->id],
                'receiving_qc' => ['user:'.$this->qc->id],
                'acceptance_qa' => ['user:'.$this->owner->id],
                'post_grn' => ['user:'.$this->buyer->id],
            ],
        ]);

        foreach (['register_certificate', 'bind_certificate'] as $action) {
            ResultRecorderPolicyFixture::ensure(
                app(TenantContext::class), $this->project, $this->buyer, $this->buyer->company_id,
                $action, ['user:'.$this->buyer->id],
            );
        }
    }

    // ------------------------------------------------------------------ fixtures

    /** Custody that attests a heat; optionally amended to attest a lot too. */
    private function custody(string $heat, ?string $lot = null, bool $linked = false): string
    {
        $arrival = [
            'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'),
            'request_id' => (string) Str::uuid(),
            'source_line_id' => null,
            'unlinked_reason' => 'Source pending',
            'shipment_ref' => 'SYNTHETIC-MTR', 'package_ref' => null,
            'received_at' => '2026-10-29T08:00:00+03:30',
            'observed_description' => 'Synthetic receipt', 'quantity' => '2.000', 'unit' => 'M',
            'heat_ref' => $heat, 'location_kind' => 'QUARANTINE', 'location_ref' => 'Bay Q',
            'document_status' => 'MISSING', 'notes' => null,
        ];

        if ($linked) {
            $arrival['source_line_id'] = $this->sourceLine();
            $arrival['unlinked_reason'] = null;
            $arrival['unit'] = 'M';
        }

        $id = app(PipingCustodyService::class)->create($this->project, $this->buyer, $arrival);

        if ($lot !== null) {
            app(PipingCustodyAmendmentService::class)->create($this->project, $this->buyer, $id, [
                'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'),
                'request_id' => (string) Str::uuid(), 'previous_amendment_id' => null,
                'source_line_id' => $arrival['source_line_id'],
                'unlinked_reason' => $arrival['source_line_id'] === null ? 'Source pending' : null,
                'heat_ref' => $heat, 'lot_ref' => $lot,
                'reason' => 'Record the lot for traceability',
            ]);
        }

        return $id;
    }

    /** A PIPING PO line, so custody can name the item it received. */
    private function sourceLine(): string
    {
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, [
            'version' => 0, 'selectors' => ['user:'.$this->buyer->id], 'suspend' => false, 'reason' => 'Synthetic source',
        ]);
        $vendor = Company::where('code', 'VEN-AZAR')->firstOrFail();
        $po = app(PurchaseOrderEntryService::class)->create($this->project, $this->buyer, [
            'currency' => 'USD', 'title_en' => 'Synthetic MTR PO', 'title_fa' => 'منبع آزمایشی',
            'discipline' => 'PIPING', 'material_category' => 'PIPE', 'vendor_company_id' => $vendor->id,
            'lines' => [['item_code' => 'MTR-PIPE', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '5.000', 'unit_price' => '10.01']],
        ]);
        $issuer = $this->userByEmail('sadeghi@pars.example');
        app(PurchaseOrderExecutionAccessService::class)->configure($this->project, $this->owner, [
            'buyer_company_id' => $this->buyer->company_id, 'version' => 0,
            'finance_selectors' => ['user:'.$this->qc->id], 'issue_selectors' => ['user:'.$issuer->id],
            'suspend' => false, 'reason' => 'Synthetic source issue nominations',
        ]);
        $exec = app(PurchaseOrderExecutionService::class);
        $hash = app(TenantContext::class)->runAs($this->buyer->company_id, fn () => $exec->hash($po), $this->project->id);
        $exec->act($this->project, $this->qc, $this->buyer->company_id, $po->id, 'finance', ['content_hash' => $hash, 'reason' => 'Synthetic financial review']);
        $approval = app(TenantContext::class)->runAs($this->buyer->company_id, fn () => DB::table('po_execution_decisions')->where('purchase_order_id', $po->id)->value('id'), $this->project->id);
        $exec->act($this->project, $issuer, $this->buyer->company_id, $po->id, 'issue', ['content_hash' => $hash, 'reason' => 'Synthetic issue', 'approval_id' => $approval]);

        return (string) app(TenantContext::class)->runAs(
            $this->buyer->company_id,
            fn () => DB::table('purchase_order_items')->where('purchase_order_id', $po->id)->value('id'),
            $this->project->id,
        );
    }

    private function certificate(string $heat, ?string $lot = null): string
    {
        return app(InspectionResultService::class)->registerCertificate($this->project, $this->buyer, [
            'certificate_type' => 'EN10204_3_1', 'heat_ref' => $heat, 'lot_ref' => $lot,
            'certificate_ref' => 'MTR-'.Str::upper(Str::random(6)),
            'issuer_name' => 'Synthetic Mill', 'request_id' => (string) Str::uuid(),
        ], 'en');
    }

    private function bindings(): MaterialCertificateBindingService
    {
        return app(MaterialCertificateBindingService::class);
    }

    /** @param  array<string,mixed>  $overrides */
    private function bind(string $certificateId, array $overrides = []): array
    {
        return $this->bindings()->bindCertificate($this->project, $this->buyer, $certificateId, array_replace([
            'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    private function bindingCount(): int
    {
        return app(TenantContext::class)->runAs(
            $this->buyer->company_id,
            fn () => DB::table('material_certificate_bindings')->count(),
            $this->project->id,
        );
    }

    // ------------------------------------------------------------------ authority

    public function test_binding_a_certificate_is_default_deny_without_a_nomination(): void
    {
        $this->custody('HEAT-A1');
        $id = $this->certificate('HEAT-A1');

        // The owner company is a project member and still cannot bind: nobody
        // nominates itself into someone else's quality claim.
        try {
            $this->bindings()->bindCertificate($this->project, $this->owner, $id, ['request_id' => (string) Str::uuid()], 'en');
            $this->fail('Expected default-deny refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not nominated', $e->errors()['binding'][0]);
        }
        $this->assertSame(0, $this->bindingCount());
    }

    // ------------------------------------------------------------------ honest binding

    public function test_a_certificate_binds_to_the_custody_that_attests_its_heat(): void
    {
        $custody = $this->custody('HEAT-B1', null, linked: true);
        $id = $this->certificate('HEAT-B1');

        $result = $this->bind($id);

        $this->assertSame('CUSTODY_HEAT', $result['binding']);
        $this->assertSame($custody, $result['custody_id'], 'the binding points at the custody that attests the heat');
        $this->assertSame('HEAT-B1', $result['heat_ref'], 'the heat is taken from the certificate, never declared');
        $this->assertSame('MTR-PIPE', $result['item_code'], 'the item is derived from the custody source line');
        $this->assertSame(1, $this->bindingCount());

        $row = app(TenantContext::class)->runAs($this->buyer->company_id, fn () => DB::table('material_certificate_bindings')->where('id', $result['id'])->first(), $this->project->id);
        $this->assertNotEmpty($row->bound_policy_id, 'the authorising nomination is stamped on the row');
        $this->assertNull($row->unlinked_reason);
    }

    public function test_a_certificate_whose_lot_custody_attests_binds_at_lot_level(): void
    {
        $this->custody('HEAT-C1', 'LOT-C1');
        $id = $this->certificate('HEAT-C1', 'LOT-C1');

        $result = $this->bind($id);

        $this->assertSame('CUSTODY_LOT_HEAT', $result['binding'], 'the exact (lot, heat) pair is what was bound');
        $this->assertSame('LOT-C1', $result['lot_ref']);
    }

    public function test_a_certificate_claiming_a_lot_custody_does_not_attest_is_refused(): void
    {
        $this->custody('HEAT-D1'); // custody knows the heat and no lot
        $id = $this->certificate('HEAT-D1', 'LOT-D1');

        try {
            $this->bind($id);
            $this->fail('Expected refusal: custody attests the heat but not this lot');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not this lot', $e->errors()['binding'][0]);
        }
        $this->assertSame(0, $this->bindingCount(), 'it is not silently bound at heat level');

        // The honest way out: say why it cannot be bound, and keep the fact.
        $result = $this->bind($id, ['unlinked_reason' => 'Custody has not recorded the lot yet']);
        $this->assertSame('UNRESOLVED', $result['binding']);
        $this->assertSame('HEAT-D1', $result['heat_ref']);
        $this->assertSame(1, $this->bindingCount());
    }

    public function test_a_certificate_for_a_heat_nobody_received_is_refused_unless_the_reason_is_given(): void
    {
        $this->custody('HEAT-E1');
        $id = $this->certificate('HEAT-NOBODY');

        try {
            $this->bind($id);
            $this->fail('Expected refusal: no material with this heat was received');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No material with this heat', $e->errors()['binding'][0]);
        }
        $this->assertSame(0, $this->bindingCount());

        // The certificate still has to be keepable — as an unresolved one.
        $result = $this->bind($id, ['unlinked_reason' => 'Mill sent the certificate ahead of the material']);
        $this->assertSame('UNRESOLVED', $result['binding']);
        $this->assertNull($result['custody_id']);
        $this->assertSame('HEAT-NOBODY', $result['heat_ref']);
    }

    public function test_the_heat_and_lot_are_derived_never_declared(): void
    {
        $custody = $this->custody('HEAT-F1');
        $id = $this->certificate('HEAT-F1');

        // Raw SQL trying to bind the certificate to a heat it does not claim:
        // the guard overwrites the claim from the certificate itself.
        $row = app(TenantContext::class)->runAs($this->buyer->company_id, function () use ($id): object {
            DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$this->buyer->id]);
            DB::select('SELECT set_config(\'epcc.receipt_actor_id\', ?, true)', [$this->buyer->id]);
            $new = (string) DB::table('material_certificate_bindings')->insertGetId([
                'id' => (string) Str::uuid(), 'project_id' => $this->project->id,
                'company_id' => $this->buyer->company_id, 'certificate_id' => $id,
                'custody_id' => null, 'custody_amendment_id' => null, 'binding' => 'CUSTODY_HEAT',
                'heat_ref' => 'A-DIFFERENT-HEAT', 'lot_ref' => null, 'item_code' => 'WHATEVER',
                'bound_policy_id' => (string) Str::uuid(), 'unlinked_reason' => null,
                'bound_by' => $this->buyer->id, 'request_id' => (string) Str::uuid(),
            ], 'id');

            return DB::table('material_certificate_bindings')->where('id', $new)->first();
        }, $this->project->id);

        $this->assertSame('HEAT-F1', (string) $row->heat_ref, 'the claim comes from the certificate');
        $this->assertSame('CUSTODY_HEAT', (string) $row->binding);
        $this->assertNotNull($row->custody_id);
    }

    // ------------------------------------------------------------------ immutability and idempotency

    public function test_bindings_are_append_only(): void
    {
        $this->custody('HEAT-G1');
        $result = $this->bind($this->certificate('HEAT-G1'));

        app(TenantContext::class)->runAs($this->buyer->company_id, function () use ($result): void {
            $before = DB::table('material_certificate_bindings')->where('id', $result['id'])->first();
            // There is no UPDATE/DELETE policy at all, so RLS makes the write
            // match nothing; the mutation trigger is the second line of defence.
            $this->assertSame(0, DB::table('material_certificate_bindings')->where('id', $result['id'])->update(['binding' => 'UNRESOLVED']));
            $this->assertSame(0, DB::table('material_certificate_bindings')->where('id', $result['id'])->delete());
            $this->assertEquals($before, DB::table('material_certificate_bindings')->where('id', $result['id'])->first());
        }, $this->project->id);
    }

    public function test_the_same_custody_cannot_be_bound_twice_to_one_certificate(): void
    {
        $this->custody('HEAT-H1');
        $id = $this->certificate('HEAT-H1');
        $this->bind($id);

        try {
            $this->bind($id);
            $this->fail('Expected refusal of a duplicate binding');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already bound', $e->errors()['binding'][0]);
        }
        $this->assertSame(1, $this->bindingCount());
    }

    public function test_duplicate_request_ids_are_refused(): void
    {
        $this->custody('HEAT-I1');
        $id = $this->certificate('HEAT-I1');
        $requestId = (string) Str::uuid();

        $this->bind($id, ['request_id' => $requestId]);
        try {
            $this->bind($id, ['request_id' => $requestId]);
            $this->fail('Expected duplicate refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('already recorded', strtolower($e->errors()['binding'][0]));
        }
        $this->assertSame(1, $this->bindingCount());
    }

    public function test_another_company_cannot_bind_in_another_name(): void
    {
        $this->custody('HEAT-J1');
        $id = $this->certificate('HEAT-J1');

        app(TenantContext::class)->runAs($this->buyer->company_id, function () use ($id): void {
            DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$this->buyer->id]);
            $this->expectException(QueryException::class);
            DB::table('material_certificate_bindings')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $this->project->id,
                'company_id' => $this->buyer->company_id, 'certificate_id' => $id,
                'custody_id' => null, 'custody_amendment_id' => null, 'binding' => 'CUSTODY_HEAT',
                'heat_ref' => 'HEAT-J1', 'lot_ref' => null, 'item_code' => null,
                'bound_policy_id' => (string) Str::uuid(), 'unlinked_reason' => null,
                'bound_by' => $this->owner->id, 'request_id' => (string) Str::uuid(),
            ]);
        }, $this->project->id);
    }

    // ------------------------------------------------------------------ the turnover question

    public function test_coverage_shows_which_heats_have_no_certificate(): void
    {
        $this->custody('HEAT-K1');            // will be covered
        $this->custody('HEAT-K2');            // will stay uncovered
        $this->bind($this->certificate('HEAT-K1'));
        $this->bind($this->certificate('HEAT-ORPHAN'), ['unlinked_reason' => 'Certificate ahead of delivery']);

        $coverage = $this->bindings()->coverage($this->project, $this->buyer);

        $rows = collect($coverage['attested'])->keyBy('heat_ref');
        $this->assertSame(1, (int) $rows['HEAT-K1']->certificates, 'the bound heat counts its certificate');
        $this->assertSame(0, (int) $rows['HEAT-K2']->certificates, 'the uncovered heat is reported as uncovered, not hidden');
        $this->assertCount(2, $coverage['attested']);

        $this->assertCount(1, $coverage['unresolved']);
        $this->assertStringContainsString('ahead of delivery', (string) $coverage['unresolved']->first()->unlinked_reason);
    }
}
