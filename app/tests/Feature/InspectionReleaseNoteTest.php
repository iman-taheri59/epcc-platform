<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InspectionReleaseNote;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\InspectionReleaseNoteService;
use App\Services\PipingCustodyService;
use App\Services\PipingReceiptAccessService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Support\ProcurementState;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * ADR-060, as amended by the ADR-041 merge.
 *
 * One record now carries both the finding and the decision. These tests are the
 * ones that decide whether the merge kept what mattered: goods that have
 * already ARRIVED can still be inspected (ON_ARRIVAL), a verdict still reaches
 * the lot in the vocabulary the warehouse screens speak, and a refusal holds
 * the order until a release covers it.
 */
final class InspectionReleaseNoteTest extends TestCase
{
    private User $owner;

    private User $buyer;

    private User $issuer;

    private Project $project;

    private PurchaseOrder $order;

    private PurchaseOrderItem $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->buyer = $this->userByEmail('rezaei@pars.example');
        $this->issuer = $this->userByEmail('tehrani@pmc.example');
        $this->owner = $this->userByEmail('karimi@nioc.example');

        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, [
            'version' => 0, 'selectors' => ['user:'.$this->buyer->id], 'suspend' => false, 'reason' => 'IRN fixture',
        ]);

        $vendor = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id);
        $this->order = app(PurchaseOrderEntryService::class)->create($this->project, $this->buyer, [
            'title_en' => 'Release note source', 'title_fa' => 'منبع تأییدیهٔ آزادسازی',
            'discipline' => 'PIPING', 'material_category' => 'PIPE', 'vendor_company_id' => $vendor,
            'lines' => [['item_code' => 'IRN-1', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '2.100']],
        ]);
        $this->line = $this->bound(fn () => PurchaseOrderItem::where('purchase_order_id', $this->order->id)->firstOrFail());
    }

    private function bound(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->issuer->company_id, $fn, $this->project->id);
    }

    private function nominateIssuer(?array $selectors = null): void
    {
        app(InspectionReleaseNoteService::class)->configure($this->project, $this->owner, [
            'selectors' => $selectors ?? ['user:'.$this->issuer->id],
            'reason' => 'Nominated to release material for shipment',
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function release(array $overrides = []): InspectionReleaseNote
    {
        return app(InspectionReleaseNoteService::class)->issue($this->project, $this->issuer, $this->order->id, $this->line->id, array_merge([
            'stage' => 'PRE_SHIPMENT', 'outcome' => 'RELEASED', 'released_at' => '2026-11-02',
            'inspector_user_id' => $this->issuer->id,
        ], $overrides));
    }

    private function statusFor(): string
    {
        return app(InspectionReleaseNoteService::class)->statusFor($this->project, $this->issuer, $this->order->id);
    }

    private function notes(): array
    {
        return $this->bound(fn () => DB::table('inspection_release_notes')->orderBy('sequence')->get()->all());
    }

    private function nominateCustody(): void
    {
        app(PipingReceiptAccessService::class)->configure($this->project, $this->owner, [
            'version' => 0, 'suspend' => false, 'reason' => 'IRN gate fixture',
            'custodian_company_id' => $this->buyer->company_id,
            'actions' => [
                'custody_mrr' => ['user:'.$this->buyer->id],
                'receiving_qc' => ['user:'.$this->issuer->id],
                'acceptance_qa' => ['user:'.$this->owner->id],
                'post_grn' => ['user:'.$this->buyer->id],
            ],
        ]);
    }

    /** A receipt tied to a purchase order line, the way a real one is. */
    private function receive(): string
    {
        return app(PipingCustodyService::class)->create($this->project, $this->buyer, [
            'expected_policy_id' => app(PipingCustodyService::class)->policyId($this->project, $this->buyer, 'custody_mrr'),
            'request_id' => (string) Str::uuid(),
            'source_line_id' => $this->line->id,
            'unlinked_reason' => null,
            'shipment_ref' => 'IRN-GATE', 'package_ref' => null,
            'received_at' => '2026-11-02T08:00:00+03:30',
            'observed_description' => 'Pipe received against the order line',
            'quantity' => '1.000', 'unit' => 'M', 'heat_ref' => 'H-IRN-1',
            'location_kind' => 'QUARANTINE', 'location_ref' => 'Bay Q',
            'document_status' => 'MISSING', 'notes' => null,
        ]);
    }

    public function test_release_is_refused_without_nomination(): void
    {
        try {
            $this->release();
            $this->fail('An un-nominated release was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame('IRN_NOT_NOMINATED', $e->validator->errors()->first('release'));
        }
    }

    public function test_numbering_carries_the_irn_series_the_owner_uses(): void
    {
        $this->nominateIssuer();

        $first = $this->release();
        $second = $this->release(['outcome' => 'RELEASED_WITH_HOLDS', 'holds_note_en' => 'MTC to follow within seven days.']);

        $this->assertSame('PH-14-IRN-0001', $first->number);
        $this->assertSame('PH-14-IRN-0002', $second->number);
        $this->assertSame([1, 2], array_map(fn ($r) => (int) $r->sequence, $this->notes()));
    }

    public function test_order_is_derived_from_the_line_never_declared(): void
    {
        $this->nominateIssuer();

        $note = $this->release();

        $this->assertSame($this->order->id, $note->purchase_order_id);
        $this->assertSame($this->line->id, $note->purchase_order_item_id);
    }

    public function test_a_refusal_blocks_the_order_and_a_later_release_clears_it(): void
    {
        $this->nominateIssuer();
        $this->assertSame('NOT_REQUIRED', $this->statusFor());

        $this->release(['outcome' => 'NOT_RELEASED', 'findings_en' => 'Wall thickness below the specified minimum on two lengths.']);
        $this->assertSame('BLOCKED', $this->statusFor());

        $this->release(['outcome' => 'RELEASED']);
        $this->assertSame('RELEASED', $this->statusFor());
    }

    public function test_the_gate_refuses_receipt_while_blocked(): void
    {
        $this->nominateIssuer();
        $this->release(['outcome' => 'NOT_RELEASED', 'findings_fa' => 'ضخامتِ دیواره کمتر از حدِ مقرر است.']);

        $this->expectException(ValidationException::class);

        app(InspectionReleaseNoteService::class)
            ->assertReceivable($this->project, $this->issuer, $this->order->id, 'en');
    }

    public function test_a_clean_or_conditional_release_does_not_block(): void
    {
        $this->nominateIssuer();
        $this->release(['outcome' => 'RELEASED']);
        $this->release(['outcome' => 'RELEASED_WITH_HOLDS', 'holds_note_en' => 'MTC to follow.']);

        // Only a refusal holds material. A hold travels with the material; it
        // does not stop it, and disposing of it belongs to TQ/NCR (ADR-031).
        $this->assertSame('RELEASED', $this->statusFor());
    }

    public function test_anything_other_than_a_clean_release_must_state_findings(): void
    {
        $this->nominateIssuer();

        try {
            $this->release(['outcome' => 'NOT_RELEASED']);
            $this->fail('A refusal with no stated findings was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame(__('inspection_release_notes.findings_required'), $e->validator->errors()->first('release'));
        }
    }

    public function test_the_guard_refuses_a_silent_refusal_even_bypassing_the_service(): void
    {
        $this->nominateIssuer();

        // Defence in depth: the service is not the only thing standing between
        // a silent refusal and a vendor who cannot act on it.
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/IRN_FINDINGS_REQUIRED/');

        $this->bound(function (): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$this->issuer->id]);
            DB::table('inspection_release_notes')->insert([
            'id' => (string) Str::uuid(), 'project_id' => $this->project->id,
            'company_id' => $this->issuer->company_id, 'purchase_order_id' => $this->order->id,
            'purchase_order_item_id' => $this->line->id, 'number' => 'BYPASS', 'sequence' => 500,
            'stage' => 'PRE_SHIPMENT', 'outcome' => 'NOT_RELEASED',
            'inspector_user_id' => $this->issuer->id,
            'released_at' => now(), 'issued_by' => $this->issuer->id, 'created_at' => now(),
        ]);
        });
    }

    public function test_a_release_with_holds_must_state_them(): void
    {
        $this->nominateIssuer();

        try {
            $this->release(['outcome' => 'RELEASED_WITH_HOLDS', 'findings_en' => 'Two lengths short.']);
            $this->fail('A release with unstated holds was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame('IRN_HOLDS_UNSTATED', $e->validator->errors()->first('release'));
        }
    }

    public function test_an_anonymous_inspector_is_unrepresentable(): void
    {
        $this->nominateIssuer();

        try {
            $this->release(['inspector_user_id' => null]);
            $this->fail('An unattributed release was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame('IRN_INSPECTOR_UNATTRIBUTED', $e->validator->errors()->first('release'));
        }
    }

    public function test_a_third_party_without_an_account_is_representable(): void
    {
        $this->nominateIssuer();
        $firm = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id);

        // The inspector is a person at a firm who will never hold an account.
        $note = $this->release([
            'inspector_user_id' => null, 'inspector_name' => 'M. Sadeghi', 'inspector_company_id' => $firm,
        ]);

        $this->assertNull($note->inspector_user_id);
        $this->assertSame('M. Sadeghi', $note->inspector_name);
        $this->assertTrue($note->releases());
    }

    public function test_goods_that_have_already_arrived_can_still_be_inspected(): void
    {
        $this->nominateIssuer();

        // The capability the merge had to preserve: ADR-041's ON_ARRIVAL stage
        // is how received goods get inspected. Losing it would have removed
        // inspection of received material altogether.
        $note = $this->release(['stage' => 'ON_ARRIVAL', 'outcome' => 'RELEASED']);
        $this->assertSame('ON_ARRIVAL', $note->stage);

        $works = $this->release(['stage' => 'WORKS', 'outcome' => 'RELEASED']);
        $this->assertSame('WORKS', $works->stage);
    }

    public function test_the_lot_verdict_keeps_the_vocabulary_the_warehouse_screens_speak(): void
    {
        $this->nominateIssuer();

        // material_lots.inspection_result feeds a live warehouse screen and
        // uninspectedLots(). Those were built around PASS/CONDITIONAL/FAIL, so
        // the merged record answers in that vocabulary rather than making every
        // screen learn a new one.
        $this->assertSame(ProcurementState::INSPECTION_PASS, $this->release(['outcome' => 'RELEASED'])->verdict());
        $this->assertSame(ProcurementState::INSPECTION_CONDITIONAL,
            $this->release(['outcome' => 'RELEASED_WITH_HOLDS', 'holds_note_en' => 'MTC to follow.'])->verdict());
        $this->assertSame(ProcurementState::INSPECTION_FAIL,
            $this->release(['outcome' => 'NOT_RELEASED', 'findings_en' => 'Damaged threading.'])->verdict());
    }

    public function test_a_release_cannot_be_written_against_a_line_the_caller_cannot_see(): void
    {
        $this->nominateIssuer();
        $this->release();

        // Rejected at the model layer, under RLS, before the guard is reached.
        $this->expectException(ModelNotFoundException::class);

        app(InspectionReleaseNoteService::class)->issue($this->project, $this->issuer, $this->order->id, '00000000-0000-0000-0000-000000000000', [
            'stage' => 'PRE_SHIPMENT', 'outcome' => 'RELEASED', 'inspector_user_id' => $this->issuer->id,
        ]);
    }

    public function test_releases_are_append_only(): void
    {
        $this->nominateIssuer();
        $note = $this->release();

        $touched = $this->bound(fn () => DB::table('inspection_release_notes')
            ->where('id', $note->id)->update(['outcome' => 'NOT_RELEASED']));
        $this->assertSame(0, $touched, 'A release was edited; a correction must be a new release.');
    }

    public function test_nomination_is_a_new_version_never_an_edit(): void
    {
        $this->nominateIssuer();
        $this->nominateIssuer();

        $versions = $this->bound(fn () => DB::table('irn_issuer_policies')
            ->where('project_id', $this->project->id)->orderBy('version')->pluck('version')->all());

        $this->assertSame([1, 2], array_map('intval', $versions));
    }

    public function test_withdrawing_the_nomination_takes_release_authority_away(): void
    {
        $this->nominateIssuer();
        $this->assertTrue(app(InspectionReleaseNoteService::class)->allows($this->project, $this->issuer));

        // An empty list is a suspension, not an error: authority is
        // default-deny, so an owner must be able to take it back.
        $this->nominateIssuer([]);
        $this->assertFalse(app(InspectionReleaseNoteService::class)->allows($this->project, $this->issuer));
    }

    public function test_a_non_owner_cannot_nominate_issuers(): void
    {
        $this->expectException(HttpException::class);

        app(InspectionReleaseNoteService::class)->configure($this->project, $this->buyer, [
            'selectors' => ['user:'.$this->issuer->id], 'reason' => 'Not mine to nominate',
        ]);
    }

    public function test_a_refusal_blocks_physical_receipt(): void
    {
        $this->nominateCustody();
        $this->nominateIssuer();
        $this->release(['outcome' => 'NOT_RELEASED', 'findings_en' => 'Wall thickness below the minimum.']);

        try {
            $this->receive();
            $this->fail('Material that was refused release was received.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('held', $e->errors()['receipt'][0]);
        }
    }

    public function test_the_receipt_proceeds_once_a_release_covers_the_refusal(): void
    {
        $this->nominateCustody();
        $this->nominateIssuer();

        $this->release(['outcome' => 'NOT_RELEASED', 'findings_en' => 'Wall thickness below the minimum.']);
        $this->release(['outcome' => 'RELEASED']);

        $this->assertNotSame('', $this->receive());
    }

    public function test_a_second_release_on_one_line_does_not_release_another(): void
    {
        $this->nominateIssuer();

        $second = $this->bound(fn () => PurchaseOrderItem::create([
            'project_id' => $this->project->id, 'purchase_order_id' => $this->order->id,
            'line_no' => 2, 'item_code' => 'IRN-2', 'description_en' => 'Gasket', 'description_fa' => 'واشر',
            'unit' => 'EA', 'quantity' => '4.000',
        ]));

        $this->release(['outcome' => 'RELEASED']);
        $this->assertSame('RELEASED', $this->statusFor());

        // The gate is per line: releasing the pipe says nothing about the gasket.
        app(InspectionReleaseNoteService::class)->issue($this->project, $this->issuer, $this->order->id, $second->id, [
            'stage' => 'PRE_SHIPMENT', 'outcome' => 'NOT_RELEASED',
            'findings_en' => 'Wrong material grade.', 'inspector_user_id' => $this->issuer->id,
        ]);

        $this->assertSame('BLOCKED', $this->statusFor());
    }
}
