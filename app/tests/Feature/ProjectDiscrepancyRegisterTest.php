<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Project;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\PurchaseOrderAccessService;
use App\Services\PurchaseOrderEntryService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ADR-031 — the project-level TQ/NCR register.
 *
 * The point of this register is that a refusal is no longer a dead end. A
 * release note can say "not released", and now that refusal can start a
 * process with a recorded answer, a disposition and a closure.
 *
 * These tests hold the parts that would otherwise decay into decoration:
 * the source must belong to this project, the two provenance modes must never
 * mix, history must be append-only, and state must be read off events rather
 * than believed from a column.
 */
final class ProjectDiscrepancyRegisterTest extends TestCase
{
    private User $owner;

    private User $buyer;

    private Project $project;

    private Project $other;

    private PurchaseOrderItem $line;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->other = Project::where('code', 'BB-2')->firstOrFail();
        $this->buyer = $this->userByEmail('rezaei@pars.example');
        $this->owner = $this->userByEmail('karimi@nioc.example');

        app(PurchaseOrderAccessService::class)->configure($this->project, $this->owner, [
            'version' => 0, 'selectors' => ['user:'.$this->buyer->id], 'suspend' => false, 'reason' => 'Discrepancy fixture',
        ]);

        $vendor = $this->bound(fn () => Company::where('code', 'VEN-AZAR')->firstOrFail()->id);
        $order = app(PurchaseOrderEntryService::class)->create($this->project, $this->buyer, [
            'title_en' => 'Discrepancy source', 'title_fa' => 'منبعِ مغایرت',
            'discipline' => 'PIPING', 'material_category' => 'PIPE', 'vendor_company_id' => $vendor,
            'lines' => [['item_code' => 'DR-1', 'description_en' => 'Pipe', 'description_fa' => 'لوله', 'unit' => 'M', 'quantity' => '1.000']],
        ]);
        $this->line = $this->bound(fn () => PurchaseOrderItem::where('purchase_order_id', $order->id)->firstOrFail());
    }

    private function bound(callable $fn, ?Project $project = null): mixed
    {
        return app(TenantContext::class)->runAs($this->buyer->company_id, $fn, $project?->id ?? $this->project->id);
    }

    /** @param  array<string, mixed>  $source  override which single source is used */
    /** @param  array<string, mixed>  $external  provenance of an already-issued report */
    private function raise(string $kind = 'NCR', array $source = [], array $external = []): string
    {
        $reportId = (string) Str::uuid();

        $this->bound(function () use ($reportId, $kind, $source, $external): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$this->buyer->id]);

            DB::table('project_discrepancy_reports')->insert(array_merge([
                'id' => $reportId, 'project_id' => $this->project->id, 'company_id' => $this->buyer->company_id,
                'kind' => $kind, 'origin' => $external === [] ? 'IN_SYSTEM' : 'EXTERNAL_REGISTER',
                'purchase_order_item_id' => $this->line->id,
                'recorded_by' => $this->buyer->id, 'created_at' => now(),
            ], $source, $external));

            DB::table('project_discrepancy_events')->insert([
                'id' => (string) Str::uuid(), 'report_id' => $reportId, 'project_id' => $this->project->id,
                'company_id' => $this->buyer->company_id, 'event' => 'RAISED',
                'note' => 'Refused at pre-shipment inspection', 'actor_id' => $this->buyer->id, 'created_at' => now(),
            ]);
        });

        return $reportId;
    }

    private function event(string $reportId, string $event): void
    {
        $this->bound(function () use ($reportId, $event): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$this->buyer->id]);
            DB::table('project_discrepancy_events')->insert([
                'id' => (string) Str::uuid(), 'report_id' => $reportId, 'project_id' => $this->project->id,
                'company_id' => $this->buyer->company_id, 'event' => $event,
                'actor_id' => $this->buyer->id, 'created_at' => now(),
            ]);
        });
    }

    private function state(string $reportId): ?string
    {
        return $this->bound(fn () => DB::selectOne(
            'SELECT epcc_discrepancy_state(?, ?) AS s', [$this->project->id, $reportId]
        )->s);
    }

    private function externalClaim(): array
    {
        return ['report_number' => 'EXT-NCR-9001', 'reported_issuer' => 'Pars Inspection Co',
            'reported_signer' => 'Eng. Ahmadi', 'reported_signer_capacity' => 'Third-party inspector',
            'reported_issued_at' => now()->subDay(), 'reported_signed_at' => now()->subDay(),
            'external_register_ref' => 'Vendor document control, register NCR-2026'];
    }

    public function test_a_refusal_can_start_a_record_with_an_answer_and_a_disposition(): void
    {
        $id = $this->raise('NCR');

        $this->assertSame('OPEN', $this->state($id));

        $this->event($id, 'DISPOSED');
        $this->assertSame('ANSWERED', $this->state($id), 'a disposition is recorded, but the matter is not closed by it');

        $this->event($id, 'CLOSED');
        $this->assertSame('CLOSED', $this->state($id));
    }

    public function test_a_tq_is_answered_and_an_ncr_is_disposed_and_each_knows_the_difference(): void
    {
        $tq = $this->raise('TQ');
        $ncr = $this->raise('NCR');

        $this->event($tq, 'DISPOSED');
        $this->assertSame('OPEN', $this->state($tq), 'an NCR disposition does not answer a technical query');

        $this->event($tq, 'ANSWERED');
        $this->assertSame('ANSWERED', $this->state($tq));

        $this->event($ncr, 'ANSWERED');
        $this->assertSame('OPEN', $this->state($ncr), 'an answer does not dispose of a non-conformance');
    }

    public function test_a_discrepancy_cannot_hang_off_a_line_that_is_not_this_projects(): void
    {
        // Prefer a real line belonging to the other project; if the demo data
        // has none, a line that simply does not exist is refused for the same
        // reason — it is not one of THIS project's lines.
        $foreign = $this->bound(fn () => DB::table('purchase_order_items')
            ->where('project_id', $this->other->id)->value('id'), $this->other);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/DR_SOURCE_NOT_FOUND/');

        $this->raise('NCR', ['purchase_order_item_id' => $foreign ?? (string) Str::uuid()]);
    }

    public function test_a_discrepancy_must_name_what_it_is_about(): void
    {
        // No source at all, so the report is about nothing. The CHECK refuses
        // it before anything can read a state off it.
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/discrepancy_report_shape/');

        $this->raise('NCR', ['purchase_order_item_id' => null]);
    }

    public function test_an_in_system_report_may_not_claim_a_number_an_issuer_or_a_signature(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/discrepancy_report_shape/');

        $this->raise('NCR', [], ['report_number' => 'PLATFORM-1']);
    }

    public function test_an_external_report_must_say_who_issued_and_signed_it(): void
    {
        $claim = $this->externalClaim();
        unset($claim['reported_signer']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/discrepancy_report_shape/');

        $this->raise('NCR', [], $claim);
    }

    public function test_an_external_report_is_registered_but_not_described_as_verified(): void
    {
        $id = $this->raise('NCR', [], $this->externalClaim());

        $row = $this->bound(fn () => DB::table('project_discrepancy_reports')->where('id', $id)->first());

        $this->assertSame('EXTERNAL_REGISTER', $row->origin);
        $this->assertSame('EXT-NCR-9001', $row->report_number);
        $this->assertSame('OPEN', $this->state($id), 'metadata captured is not acceptance');

        $this->event($id, 'EXTERNAL_VERIFIED');
        $this->assertSame('OPEN', $this->state($id), 'verification is a record, not a disposition');
    }

    public function test_history_is_append_only(): void
    {
        $id = $this->raise('NCR');

        $touched = $this->bound(function () use ($id): int {
            return DB::table('project_discrepancy_reports')->where('id', $id)->update(['kind' => 'TQ']);
        });
        $this->assertSame(0, $touched, 'a correction is a new event, never an edit');

        $deleted = $this->bound(function () use ($id): int {
            return DB::table('project_discrepancy_events')->where('report_id', $id)->delete();
        });
        $this->assertSame(0, $deleted, 'the event log has no deletions');
    }

    public function test_nothing_happens_before_the_report_is_raised(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/DR_EVENT_WITHOUT_RAISED/');

        $orphan = (string) Str::uuid();
        $this->raise('NCR');

        $this->bound(function () use ($orphan): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$this->buyer->id]);
            DB::table('project_discrepancy_reports')->insert([
                'id' => $orphan, 'project_id' => $this->project->id, 'company_id' => $this->buyer->company_id,
                'kind' => 'NCR', 'origin' => 'IN_SYSTEM', 'purchase_order_item_id' => $this->line->id,
                'recorded_by' => $this->buyer->id, 'created_at' => now(),
            ]);
        });

        $this->event($orphan, 'DISPOSED');
    }

    public function test_a_company_outside_the_project_records_and_sees_nothing(): void
    {
        $id = $this->raise('NCR');
        $outsider = $this->userByEmail('ahmadi@nigc.example');

        $seen = app(TenantContext::class)->runAs($outsider->company_id, fn () => DB::table('project_discrepancy_reports')->count(), $this->project->id);
        $this->assertSame(0, $seen, 'a company that is not on the project does not read its discrepancies');

        $this->expectException(QueryException::class);
        app(TenantContext::class)->runAs($outsider->company_id, function () use ($outsider): void {
            DB::select("SELECT set_config('epcc.result_actor_id', ?, true)", [$outsider->id]);
            DB::table('project_discrepancy_reports')->insert([
                'id' => (string) Str::uuid(), 'project_id' => $this->project->id, 'company_id' => $outsider->company_id,
                'kind' => 'NCR', 'origin' => 'IN_SYSTEM', 'purchase_order_item_id' => $this->line->id,
                'recorded_by' => $outsider->id, 'created_at' => now(),
            ]);
        }, $this->project->id);
    }

    public function test_an_unresolved_discrepancy_holds_the_line_and_a_disposition_releases_it(): void
    {
        $orderId = $this->line->purchase_order_id;

        $before = $this->bound(fn () => DB::selectOne('SELECT epcc_po_release_status(?) AS s', [$orderId])->s);
        $this->assertSame('NOT_REQUIRED', $before);

        $id = $this->raise('NCR');
        $held = $this->bound(fn () => DB::selectOne('SELECT epcc_po_release_status(?) AS s', [$orderId])->s);
        $this->assertSame('BLOCKED', $held, 'a raised matter no one has answered holds the line');

        $this->event($id, 'DISPOSED');
        $cleared = $this->bound(fn () => DB::selectOne('SELECT epcc_po_release_status(?) AS s', [$orderId])->s);
        $this->assertSame('NOT_REQUIRED', $cleared, 'a disposition clears the hold; it does not itself release material');
    }

    public function test_a_report_of_another_project_does_not_leak_into_this_one(): void
    {
        $this->raise('NCR');

        $inOther = $this->bound(fn () => DB::table('project_discrepancy_reports')->count(), $this->other);
        $this->assertSame(0, $inOther);
    }
}
