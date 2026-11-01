<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\InspectionReleaseNote;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\InspectionAccessService;
use App\Services\InspectionEntryService;
use App\Services\InspectionReleaseNoteService;
use App\Services\PipingCustodyService;
use App\Services\PipingReceiptAccessService;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * ADR-060. The release is the verdict an inspection could never carry: an
 * observation says what the inspector saw, a release says what may now happen
 * to the material. These tests exist because a FAIL with nowhere to land is the
 * same as no inspection at all — which is exactly what the platform had.
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

        $poPolicy = ['version' => 0, 'selectors' => ['user:'.$this->buyer->id], 'suspend' => false, 'reason' => 'IRN fixture'];
        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, $poPolicy);

        $vendor = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id);
        $this->order = app(PurchaseOrderEntryService::class)->create($this->project, $this->buyer, [
            'title_en' => 'Release note source', 'title_fa' => 'منبع تأییدیهٔ آزادسازی',
            'discipline' => 'PIPING', 'material_category' => 'PIPE', 'vendor_company_id' => $vendor,
            'lines' => [['item_code' => 'IRL-1', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '2.100']],
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

    private bool $authorNominated = false;

    /** Once: the inspection register refuses to re-configure on a stale version. */
    private function nominateInspectionAuthor(): void
    {
        if ($this->authorNominated) {
            return;
        }

        app(InspectionAccessService::class)->configure($this->project, $this->owner, [
            'version' => 0, 'selectors' => ['user:'.$this->issuer->id], 'suspend' => false,
            'reason' => 'Independent inspection fixture',
        ]);
        $this->authorNominated = true;
    }

    /** @param  array<string, mixed>  $overrides */
    private function inspection(array $overrides = []): mixed
    {
        $this->nominateInspectionAuthor();

        return app(InspectionEntryService::class)->create($this->project, $this->issuer, $this->order->id, array_merge([
            'purchase_order_id' => $this->order->id, 'result' => 'FAIL', 'stage' => 'PRE_SHIPMENT',
            'inspection_date' => '2026-11-01',
            'findings_en' => 'Wall thickness below the specified minimum on two lengths.',
            'findings_fa' => 'ضخامتِ دیواره در دو شاخه کمتر از حدِ مقرر است.',
        ], $overrides));
    }

    /** @param  array<string, mixed>  $overrides */
    private function release(array $overrides = []): InspectionReleaseNote
    {
        return app(InspectionReleaseNoteService::class)->issue($this->project, $this->issuer, $this->order->id, $this->line->id, array_merge([
            'stage' => 'PRE_SHIPMENT', 'outcome' => 'RELEASED', 'released_at' => '2026-11-01',
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

    public function test_release_is_refused_without_nomination(): void
    {
        $this->expectException(ValidationException::class);

        try {
            $this->release();
        } catch (ValidationException $e) {
            $this->assertSame('IRN_NOT_NOMINATED', $e->validator->errors()->first('release'));

            throw $e;
        }
    }

    public function test_numbering_is_project_scoped_and_carries_its_own_series(): void
    {
        $this->nominateIssuer();

        $first = $this->release();
        $second = $this->release(['outcome' => 'RELEASED_WITH_HOLDS', 'holds_note_en' => 'MTC to follow within seven days.']);

        // -IRL-, because -IRN- already belongs to inspection OBSERVATIONS.
        $this->assertSame('PH-14-IRL-0001', $first->number);
        $this->assertSame('PH-14-IRL-0002', $second->number);
        $this->assertSame([1, 2], array_map(fn ($r) => (int) $r->sequence, $this->notes()));
    }

    public function test_order_is_derived_from_the_line_never_declared(): void
    {
        $this->nominateIssuer();

        $note = $this->release();

        $this->assertSame($this->order->id, $note->purchase_order_id);
        $this->assertSame($this->line->id, $note->purchase_order_item_id);
    }

    public function test_a_failed_inspection_blocks_the_order_and_a_release_clears_it(): void
    {
        $this->nominateIssuer();
        $this->assertSame('NOT_REQUIRED', $this->statusFor());

        $inspection = $this->inspection();
        $this->assertSame('BLOCKED', $this->statusFor());

        $this->release(['source_inspection_id' => $inspection->id]);
        $this->assertSame('RELEASED', $this->statusFor());
    }

    public function test_the_gate_refuses_receipt_while_blocked(): void
    {
        $this->nominateIssuer();
        $this->inspection();

        $this->expectException(ValidationException::class);

        app(InspectionReleaseNoteService::class)
            ->assertReceivable($this->project, $this->issuer, $this->order->id, 'en');
    }

    public function test_a_passed_or_conditional_inspection_does_not_block(): void
    {
        $this->nominateIssuer();
        $this->inspection(['result' => 'PASS']);
        $this->inspection(['result' => 'CONDITIONAL']);

        // Only a failure holds material. Absence of inspection and a verdict
        // short of failure are planning matters, not quality holds.
        $this->assertSame('NOT_REQUIRED', $this->statusFor());
    }

    public function test_a_refusal_blocks_and_a_later_release_supersedes_it(): void
    {
        $this->nominateIssuer();
        $inspection = $this->inspection();

        $this->release(['outcome' => 'NOT_RELEASED', 'source_inspection_id' => $inspection->id]);
        $this->assertSame('BLOCKED', $this->statusFor());

        $this->release(['outcome' => 'RELEASED', 'source_inspection_id' => $inspection->id]);
        $this->assertSame('RELEASED', $this->statusFor());
    }

    public function test_a_refusal_must_name_the_inspection_it_refuses(): void
    {
        $this->nominateIssuer();

        try {
            $this->release(['outcome' => 'NOT_RELEASED']);
            $this->fail('A refusal without an inspection was accepted.');
        } catch (ValidationException $e) {
            $this->assertSame('IRN_REFUSAL_WITHOUT_INSPECTION', $e->validator->errors()->first('release'));
        }
    }

    public function test_a_release_with_holds_must_state_them(): void
    {
        $this->nominateIssuer();

        try {
            $this->release(['outcome' => 'RELEASED_WITH_HOLDS']);
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
        $note = $this->release(['inspector_user_id' => null, 'inspector_name' => 'M. Sadeghi', 'inspector_company_id' => $firm]);

        $this->assertNull($note->inspector_user_id);
        $this->assertSame('M. Sadeghi', $note->inspector_name);
        $this->assertTrue($note->releases());
    }

    public function test_a_release_cannot_be_written_against_a_line_the_caller_cannot_see(): void
    {
        $this->nominateIssuer();
        $this->release();

        // Rejected at the model layer, under RLS, before the guard is reached.
        // The guard's IRN_LINE_NOT_FOUND is the backstop for a caller who got
        // past it; the point is that neither layer lets it through.
        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

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

    public function test_a_non_owner_cannot_nominate_issuers(): void
    {
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        app(InspectionReleaseNoteService::class)->configure($this->project, $this->buyer, [
            'selectors' => ['user:'.$this->issuer->id], 'reason' => 'Not mine to nominate',
        ]);
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
            'received_at' => '2026-11-01T08:00:00+03:30',
            'observed_description' => 'Pipe received against the order line',
            'quantity' => '1.000', 'unit' => 'M', 'heat_ref' => 'H-IRN-1',
            'location_kind' => 'QUARANTINE', 'location_ref' => 'Bay Q',
            'document_status' => 'MISSING', 'notes' => null,
        ]);
    }

    public function test_a_failed_inspection_blocks_physical_receipt(): void
    {
        $this->nominateCustody();
        $this->nominateIssuer();
        $this->inspection();

        try {
            $this->receive();
            $this->fail('Material whose inspection failed was received.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('held', $e->errors()['receipt'][0]);
        }
    }

    public function test_the_receipt_proceeds_once_a_release_covers_the_failure(): void
    {
        $this->nominateCustody();
        $this->nominateIssuer();
        $inspection = $this->inspection();

        $this->release(['source_inspection_id' => $inspection->id]);

        $this->assertNotSame('', $this->receive());
    }
}
