<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BaselineBatch;
use App\Models\BaselineDiscrepancy;
use App\Models\EngineeringItem;
use App\Models\MaterialLot;
use App\Models\MaterialMovement;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\BaselineService;
use App\Services\ModelImportService;
use App\Services\PipingLineService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Reconciling the MTO against the 3D model (ADR-005 §2.1, §2.2).
 *
 * The point of the whole phase: a line list, an MTO and a PCF parse are three
 * statements about the same physical reality. Give each its own table and
 * "how much 6-inch pipe does this line need?" has three answers and no way to
 * choose. One neutral table, every row carrying its source.
 *
 * THE USER'S RULING: nobody wins automatically. When the numbers disagree the
 * system records both and raises a finding; the baseline has NO agreed figure
 * until a person chooses one and says why. These tests exist mainly to prove
 * that the system never quietly picks.
 */
final class BaselineReconciliationTest extends TestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);

        $this->tenant = app(TenantContext::class);
    }

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    private function owner(): User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    private function asOwner(callable $fn): mixed
    {
        $project = $this->project();

        return $this->tenant->runAs($project->owner_company_id, $fn, $project->id);
    }

    private function svc(): BaselineService
    {
        return app(BaselineService::class);
    }

    private function line(): PipingLine
    {
        return $this->asOwner(function () {
            $project = $this->project();
            $lines = app(PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
            );

            $number = '6-P-A1-PH-14-SS-2201-001';

            return PipingLine::where('line_number', $number)->first()
                ?? $lines->register(
                    Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                    $this->owner(),
                    $number,
                    ['nominal_size' => '6', 'service_code' => 'P'],
                );
        });
    }

    /** Write a CSV MTO and return its path. */
    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'mto').'.csv';
        file_put_contents($path, $body);

        return $path;
    }

    private function importMto(string $body, ?PipingLine $line = null): BaselineBatch
    {
        $path = $this->csv($body);

        return $this->asOwner(fn () => $this->svc()->importMto(
            $this->project(), $this->owner(), $path, basename($path), $line,
        ));
    }

    /** Model the line from the PCF fixture, so a real take-off exists. */
    private function modelTheLine(PipingLine $line): void
    {
        $pcf = (string) file_get_contents(__DIR__.'/../Fixtures/pcf/line-6-P.pcf');

        $this->asOwner(fn () => app(ModelImportService::class)->importPcf(
            $this->project(), $this->owner(), $pcf, 'line-6-P.pcf', false,
            Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
        ));
    }

    // ------------------------------------------------------- MTO import --

    public function test_it_imports_an_mto_csv(): void
    {
        $batch = $this->importMto(
            "Item code,Description,Size,Quantity,Unit\n"
            ."PIPE-CS-A106B-STD,6in pipe,6,120,M\n"
            ."ELBO-90-LR-CS,90deg elbow,6,4,EA\n"
        );

        $this->assertSame(2, $batch->row_count);
        $this->assertSame(BaselineBatch::DRAFT, $batch->state);
        $this->assertSame(2, $this->asOwner(fn () => EngineeringItem::count()));
    }

    public function test_headers_may_be_in_farsi(): void
    {
        // An MTO produced in Iran will not have English headers, and demanding
        // them would guarantee the importer is never used.
        $batch = $this->importMto(
            "کد کالا,شرح,سایز,مقدار,واحد\n"
            ."PIPE-CS-A106B-STD,لوله ۶ اینچ,6,120,متر\n"
        );

        $this->assertSame(1, $batch->row_count);

        $item = $this->asOwner(fn () => EngineeringItem::firstOrFail());
        $this->assertSame('M', $item->unit);
    }

    public function test_persian_digits_and_separators_are_parsed(): void
    {
        // (float) "۱٬۲۰۰٫۵" is 0.0 - silently. The line would look as though it
        // needed nothing at all.
        $batch = $this->importMto(
            "Item code,Quantity,Unit\n"
            ."PIPE-CS-A106B-STD,۱۲۰٫۵,M\n"
        );

        $item = $this->asOwner(fn () => EngineeringItem::firstOrFail());

        $this->assertEqualsWithDelta(120.5, $item->quantity, 0.0001);
        $this->assertSame(1, $batch->row_count);
    }

    public function test_thousands_separators_do_not_truncate_the_quantity(): void
    {
        $this->importMto("Item code,Quantity,Unit\nPIPE-X,\"1,200\",M\n");

        $this->assertEqualsWithDelta(
            1200.0,
            $this->asOwner(fn () => EngineeringItem::firstOrFail()->quantity),
            0.0001,
        );
    }

    public function test_units_are_normalised(): void
    {
        $this->importMto(
            "Item code,Quantity,Unit\nA,1,mtr\nB,2,PCS\nC,3,کیلوگرم\n"
        );

        $units = $this->asOwner(fn () => EngineeringItem::orderBy('item_code')->pluck('unit')->all());

        $this->assertSame(['M', 'EA', 'KG'], $units);
    }

    public function test_an_incomplete_row_is_reported_not_silently_dropped(): void
    {
        $batch = $this->importMto(
            "Item code,Quantity,Unit\nPIPE-X,120,M\n,40,M\nPIPE-Y,,M\n"
        );

        $this->assertSame(1, $batch->row_count);
        $this->assertSame(2, $batch->rejected_count);
        $this->assertCount(2, $batch->warnings);
    }

    public function test_a_negative_quantity_is_rejected(): void
    {
        $batch = $this->importMto("Item code,Quantity,Unit\nPIPE-X,-5,M\n");

        $this->assertSame(0, $batch->row_count);
        $this->assertSame(1, $batch->rejected_count);
    }

    public function test_a_file_without_a_usable_header_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->importMto("Widget,Thing,Stuff\nA,B,C\n");
    }

    public function test_a_file_with_only_a_header_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->importMto("Item code,Quantity,Unit\n");
    }

    // ------------------------------------------------------ acceptance --

    public function test_an_import_is_a_draft_until_somebody_accepts_it(): void
    {
        $batch = $this->importMto("Item code,Quantity,Unit\nPIPE-X,120,M\n");

        $this->assertSame(BaselineBatch::DRAFT, $batch->state);

        $accepted = $this->asOwner(fn () => $this->svc()->accept($batch, $this->owner()));

        $this->assertSame(BaselineBatch::ACCEPTED, $accepted->state);
        $this->assertSame($this->owner()->id, $accepted->accepted_by);
        $this->assertNotNull($accepted->accepted_at);
    }

    public function test_accepting_a_new_batch_supersedes_the_previous_one(): void
    {
        // The old batch is kept, not deleted: "which revision did we buy
        // against" must remain answerable.
        $line = $this->line();

        $revB = $this->importMto("Item code,Quantity,Unit\nPIPE-X,120,M\n", $line);
        $this->asOwner(fn () => $this->svc()->accept($revB, $this->owner()));

        $revC = $this->importMto("Item code,Quantity,Unit\nPIPE-X,134,M\n", $line);
        $this->asOwner(fn () => $this->svc()->accept($revC, $this->owner()));

        $this->assertSame(
            BaselineBatch::SUPERSEDED,
            $this->asOwner(fn () => $revB->fresh()->state),
        );
        $this->assertSame(2, $this->asOwner(fn () => BaselineBatch::count()));
    }

    public function test_the_database_refuses_an_accepted_batch_without_provenance(): void
    {
        $batch = $this->importMto("Item code,Quantity,Unit\nPIPE-X,120,M\n");

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => \Illuminate\Support\Facades\DB::table('baseline_batches')
            ->where('id', $batch->id)->update(['state' => 'ACCEPTED']));
    }

    // ------------------------------------------------- take-off from 3D --

    public function test_a_take_off_is_derived_from_the_model(): void
    {
        $line = $this->line();
        $this->modelTheLine($line);

        $batch = $this->asOwner(fn () => $this->svc()->takeOffFromModel($line->fresh(), $this->owner()));

        $this->assertSame(BaselineBatch::SOURCE_PCF, $batch->source_kind);
        $this->assertGreaterThan(0, $batch->row_count);
    }

    public function test_pipe_is_taken_off_by_length_and_fittings_by_count(): void
    {
        // The single most common way a take-off goes wrong. Counting pipe as
        // "2 pipes" makes the comparison against an MTO meaningless.
        $line = $this->line();
        $this->modelTheLine($line);

        $batch = $this->asOwner(fn () => $this->svc()->takeOffFromModel($line->fresh(), $this->owner()));

        $items = $this->asOwner(fn () => EngineeringItem::where('baseline_batch_id', $batch->id)->get());

        $pipe = $items->firstWhere('component_type', 'PIPE');
        $elbow = $items->firstWhere('component_type', 'ELBOW');

        $this->assertSame('M', $pipe->unit);
        // 3 m + 2 m of pipe in the fixture.
        $this->assertEqualsWithDelta(5.0, $pipe->quantity, 0.01);

        $this->assertSame('EA', $elbow->unit);
        $this->assertEqualsWithDelta(1.0, $elbow->quantity, 0.0001);
    }

    public function test_taking_off_an_unmodelled_line_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->takeOffFromModel($this->line(), $this->owner()));
    }

    // --------------------------------------------------- reconciliation --

    /** @return array{0: PipingLine, 1: BaselineBatch, 2: BaselineBatch} */
    private function twoAcceptedBatches(string $mtoQty, string $modelQty): array
    {
        $line = $this->line();

        $mto = $this->importMto("Item code,Quantity,Unit\nPIPE-CS-A106B-STD,{$mtoQty},M\n", $line);
        $this->asOwner(fn () => $this->svc()->accept($mto, $this->owner()));

        // A hand-built "model" batch, so the quantity is exactly controllable.
        $model = $this->asOwner(function () use ($line, $modelQty) {
            $b = BaselineBatch::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'source_kind' => BaselineBatch::SOURCE_PCF,
                'piping_line_id' => $line->id,
                'subsystem_id' => $line->subsystem_id,
                'state' => BaselineBatch::DRAFT,
                'imported_by' => $this->owner()->id,
            ]);

            EngineeringItem::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'baseline_batch_id' => $b->id,
                'piping_line_id' => $line->id,
                'subsystem_id' => $line->subsystem_id,
                'item_code' => 'PIPE-CS-A106B-STD',
                'quantity' => (float) $modelQty,
                'unit' => 'M',
                'source_kind' => BaselineBatch::SOURCE_PCF,
                'confidence' => 90,
            ]);

            return $this->svc()->accept($b->fresh(), $this->owner());
        });

        // Re-read INSIDE a tenant binding. `$mto->fresh()` outside runAs() has
        // no `epcc.company_id` set, and RLS then renders it as the empty
        // string - which Postgres rejects as a uuid. The failure looks like a
        // data bug and is really a missing tenant scope.
        return [$line, $this->asOwner(fn () => $mto->fresh()), $model];
    }

    public function test_a_disagreement_raises_a_discrepancy_and_picks_no_winner(): void
    {
        // THE RULING. 120 against 134 is a finding, not a number to choose.
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '134');

        $found = $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $this->assertCount(1, $found);

        $d = $found[0];
        $this->assertSame(BaselineDiscrepancy::QUANTITY, $d->kind);
        $this->assertSame(BaselineDiscrepancy::OPEN, $d->state);
        $this->assertEqualsWithDelta(120.0, $d->quantity_a, 0.001);
        $this->assertEqualsWithDelta(134.0, $d->quantity_b, 0.001);
        $this->assertNull($d->resolved_quantity);
        $this->assertNull($d->agreedQuantity());
    }

    public function test_a_difference_within_tolerance_is_not_a_discrepancy(): void
    {
        // Pipe is cut from stock lengths and never matches to the metre.
        // Flagging 1% would bury the real findings in noise.
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '121');

        $found = $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $this->assertSame([], $found);
    }

    public function test_the_tolerance_is_configurable_per_project(): void
    {
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '124');

        // 3.2% - inside the default 2%? No. So it is a finding by default.
        $this->assertCount(1, $this->asOwner(
            fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model)
        ));

        $this->asOwner(fn () => app(PipingLineService::class)
            ->configFor($this->project())->update(['baseline_tolerance_pct' => 10.0]));

        $this->assertSame([], $this->asOwner(
            fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model)
        ));
    }

    public function test_an_item_only_the_mto_knows_is_reported_as_missing_in_the_model(): void
    {
        $line = $this->line();

        $mto = $this->importMto(
            "Item code,Quantity,Unit\nPIPE-CS-A106B-STD,120,M\nGASK-SPW-150-RF,8,EA\n", $line
        );
        $this->asOwner(fn () => $this->svc()->accept($mto, $this->owner()));

        $model = $this->asOwner(function () use ($line) {
            $b = BaselineBatch::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'source_kind' => BaselineBatch::SOURCE_PCF,
                'piping_line_id' => $line->id,
                'state' => BaselineBatch::DRAFT,
                'imported_by' => $this->owner()->id,
            ]);

            EngineeringItem::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'baseline_batch_id' => $b->id,
                'piping_line_id' => $line->id,
                'item_code' => 'PIPE-CS-A106B-STD',
                'quantity' => 120.0, 'unit' => 'M',
                'source_kind' => BaselineBatch::SOURCE_PCF, 'confidence' => 90,
            ]);

            return $this->svc()->accept($b->fresh(), $this->owner());
        });

        $found = $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto->fresh(), $model));

        $this->assertCount(1, $found);
        $this->assertSame(BaselineDiscrepancy::MISSING_IN_MODEL, $found[0]->kind);
        $this->assertSame('GASK-SPW-150-RF', $found[0]->item_code);
    }

    public function test_the_same_code_in_different_units_is_not_compared(): void
    {
        // "10 EA" and "10 M" of one code are two different physical things;
        // subtracting them would be arithmetic on nonsense.
        $line = $this->line();

        $mto = $this->importMto("Item code,Quantity,Unit\nX,10,M\n", $line);
        $this->asOwner(fn () => $this->svc()->accept($mto, $this->owner()));

        $model = $this->asOwner(function () use ($line) {
            $b = BaselineBatch::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'source_kind' => BaselineBatch::SOURCE_PCF,
                'piping_line_id' => $line->id,
                'state' => BaselineBatch::DRAFT,
                'imported_by' => $this->owner()->id,
            ]);
            EngineeringItem::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'baseline_batch_id' => $b->id,
                'piping_line_id' => $line->id,
                'item_code' => 'X', 'quantity' => 10.0, 'unit' => 'EA',
                'source_kind' => BaselineBatch::SOURCE_PCF, 'confidence' => 90,
            ]);

            return $this->svc()->accept($b->fresh(), $this->owner());
        });

        $found = $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto->fresh(), $model));

        // Two findings, each "missing on the other side" - NOT one quantity
        // match of 10 versus 10.
        $this->assertCount(2, $found);
        foreach ($found as $d) {
            $this->assertNotSame(BaselineDiscrepancy::QUANTITY, $d->kind);
        }
    }

    public function test_reconciling_against_a_draft_batch_is_refused(): void
    {
        $line = $this->line();
        $mto = $this->importMto("Item code,Quantity,Unit\nX,10,M\n", $line);

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $mto));
    }

    public function test_reconciling_again_replaces_open_findings_but_keeps_resolved_ones(): void
    {
        // A resolved discrepancy is somebody's decision; it must not evaporate
        // because a new revision arrived.
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '134');

        $first = $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $this->asOwner(fn () => $this->svc()->resolve(
            $first[0], $this->owner(), BaselineDiscrepancy::ACCEPTED_B, 'Iso went to rev C'
        ));

        $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $this->assertSame(1, $this->asOwner(
            fn () => BaselineDiscrepancy::where('state', BaselineDiscrepancy::ACCEPTED_B)->count()
        ));
    }

    // ------------------------------------------------------- resolution --

    public function test_resolving_records_the_number_the_person_and_the_reason(): void
    {
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '134');
        $found = $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $resolved = $this->asOwner(fn () => $this->svc()->resolve(
            $found[0], $this->owner(), BaselineDiscrepancy::ACCEPTED_B,
            'The isometric went to rev C and the MTO was not re-issued.',
        ));

        $this->assertEqualsWithDelta(134.0, $resolved->resolved_quantity, 0.001);
        $this->assertSame($this->owner()->id, $resolved->resolved_by);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertEqualsWithDelta(134.0, $resolved->agreedQuantity(), 0.001);
    }

    public function test_a_reason_is_required(): void
    {
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '134');
        $found = $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->resolve(
            $found[0], $this->owner(), BaselineDiscrepancy::ACCEPTED_A, '   '
        ));
    }

    public function test_a_third_number_may_be_entered(): void
    {
        // The two sources are not the only possible answers: a site
        // measurement may beat both.
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '134');
        $found = $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $resolved = $this->asOwner(fn () => $this->svc()->resolve(
            $found[0], $this->owner(), BaselineDiscrepancy::ACCEPTED_OTHER,
            'Measured on site after the re-route.', 128.0,
        ));

        $this->assertEqualsWithDelta(128.0, $resolved->resolved_quantity, 0.001);
    }

    public function test_resolving_twice_is_refused(): void
    {
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '134');
        $found = $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $this->asOwner(fn () => $this->svc()->resolve(
            $found[0], $this->owner(), BaselineDiscrepancy::ACCEPTED_A, 'MTO is right'
        ));

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->resolve(
            $found[0]->fresh(), $this->owner(), BaselineDiscrepancy::ACCEPTED_B, 'Changed my mind'
        ));
    }

    public function test_the_database_refuses_a_resolution_with_no_quantity(): void
    {
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '134');
        $found = $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => \Illuminate\Support\Facades\DB::table('baseline_discrepancies')
            ->where('id', $found[0]->id)
            ->update([
                'state' => 'ACCEPTED_A',
                'resolved_by' => $this->owner()->id,
                'resolved_at' => now(),
            ]));
    }

    // ------------------------------------------------- the agreed answer --

    public function test_a_disputed_item_has_no_agreed_quantity(): void
    {
        // The honest answer. A number here would be a guess presented as fact.
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '134');
        $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $baseline = $this->asOwner(fn () => $this->svc()->agreedBaseline($line));

        $this->assertCount(1, $baseline);
        $this->assertTrue($baseline[0]['disputed']);
        $this->assertNull($baseline[0]['quantity']);
    }

    public function test_an_undisputed_item_takes_the_highest_confidence_source(): void
    {
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '121');
        $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $baseline = $this->asOwner(fn () => $this->svc()->agreedBaseline($line));

        $this->assertFalse($baseline[0]['disputed']);
        // PCF outranks a typed MTO, and the two agree within tolerance anyway.
        $this->assertEqualsWithDelta(121.0, $baseline[0]['quantity'], 0.001);
    }

    public function test_once_resolved_the_baseline_has_the_chosen_number(): void
    {
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '134');
        $found = $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $this->asOwner(fn () => $this->svc()->resolve(
            $found[0], $this->owner(), BaselineDiscrepancy::ACCEPTED_B, 'Iso rev C'
        ));

        $baseline = $this->asOwner(fn () => $this->svc()->agreedBaseline($line));

        $this->assertFalse($baseline[0]['disputed']);
        $this->assertEqualsWithDelta(134.0, $baseline[0]['quantity'], 0.001);
    }

    public function test_a_draft_batch_does_not_reach_the_baseline(): void
    {
        $line = $this->line();
        $this->importMto("Item code,Quantity,Unit\nPIPE-X,120,M\n", $line);

        $this->assertSame([], $this->asOwner(fn () => $this->svc()->agreedBaseline($line)));
    }

    // ---------------------------------------------------------- shortage --

    public function test_the_shortage_report_subtracts_orders_from_the_baseline(): void
    {
        // The number WarehouseService cannot produce: it compares ordered with
        // delivered, so 120 ordered against a baseline of 134 reads as 100%
        // delivered while the line is 14 m short.
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '134');
        $found = $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $this->asOwner(fn () => $this->svc()->resolve(
            $found[0], $this->owner(), BaselineDiscrepancy::ACCEPTED_B, 'Iso rev C'
        ));

        $this->asOwner(function () use ($line) {
            $po = PurchaseOrder::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'vendor_company_id' => \App\Models\Company::where('id', '!=', $this->project()->owner_company_id)->firstOrFail()->id,
                'number' => 'PO-BASE-1',
                'sequence' => 9001,
                'title_en' => 'Line pipe',
                'title_fa' => 'لولهٔ خط',
                'state' => 'ISSUED',
                'created_by' => $this->owner()->id,
            ]);

            PurchaseOrderItem::create([
                'project_id' => $this->project()->id,
                'purchase_order_id' => $po->id,
                'subsystem_id' => $line->subsystem_id,
                'line_no' => 1,
                'item_code' => 'PIPE-CS-A106B-STD',
                'description_en' => '6in pipe',
                'description_fa' => 'لولهٔ ۶ اینچ',
                'unit' => 'M',
                'quantity' => 120,
            ]);
        });

        $report = $this->asOwner(fn () => $this->svc()->shortageReport($line));

        $this->assertCount(1, $report);
        $this->assertEqualsWithDelta(134.0, $report[0]['required'], 0.001);
        $this->assertEqualsWithDelta(120.0, $report[0]['ordered'], 0.001);
        $this->assertEqualsWithDelta(14.0, $report[0]['to_order'], 0.001);
    }

    public function test_a_disputed_item_reports_a_null_shortage_not_zero(): void
    {
        // A shortage of "0" against an unknown requirement is a statement the
        // data does not support - and it is exactly the figure somebody would
        // put in front of a client.
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '134');
        $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $report = $this->asOwner(fn () => $this->svc()->shortageReport($line));

        $this->assertTrue($report[0]['disputed']);
        $this->assertNull($report[0]['required']);
        $this->assertNull($report[0]['to_order']);
    }

    public function test_received_material_counts_towards_the_requirement(): void
    {
        [$line, $mto, $model] = $this->twoAcceptedBatches('120', '121');
        $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $this->asOwner(function () use ($line) {
            $lot = MaterialLot::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'subsystem_id' => $line->subsystem_id,
                'lot_code' => 'LOT-B-1',
                'item_code' => 'PIPE-CS-A106B-STD',
                'description_en' => '6in pipe',
                'description_fa' => 'لولهٔ ۶ اینچ',
                'unit' => 'M',
            ]);

            MaterialMovement::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'lot_id' => $lot->id,
                'subsystem_id' => $line->subsystem_id,
                'number' => 'MV-B-1',
                'sequence' => 1,
                'type' => \App\Support\MovementType::SITE,
                'quantity' => 100,
                'performed_by' => $this->owner()->id,
                'performed_at' => now(),
            ]);
        });

        $report = $this->asOwner(fn () => $this->svc()->shortageReport($line));

        $this->assertEqualsWithDelta(100.0, $report[0]['received'], 0.001);
        $this->assertEqualsWithDelta(21.0, $report[0]['to_receive'], 0.001);
    }

    // ---------------------------------------------------------------- screen

    /**
     * The screen exists and speaks both languages.
     *
     * A reconciliation that only a service can perform is not a feature; the
     * person who has to argue about the number never sees it.
     */
    public function test_the_reconciliation_screen_renders_in_both_languages(): void
    {
        [$line] = $this->twoAcceptedBatches('134', '120');

        $this->asOwner(fn () => $this->svc()->reconcile(
            $line,
            $this->owner(),
            BaselineBatch::where('source_kind', BaselineBatch::SOURCE_MTO)->where('state', BaselineBatch::ACCEPTED)->firstOrFail(),
            BaselineBatch::where('source_kind', BaselineBatch::SOURCE_PCF)->where('state', BaselineBatch::ACCEPTED)->firstOrFail(),
        ));

        $en = $this->actingAs($this->owner())
            ->get("/projects/PH-14/piping/{$line->id}/baseline?lang=en");
        $en->assertOk();
        $en->assertSee('MTO versus model');
        $en->assertSee('PIPE-CS-A106B-STD');
        // Both claims are on the page. Neither is presented as the answer.
        $en->assertSee('134');
        $en->assertSee('120');

        $fa = $this->actingAs($this->owner())
            ->get("/projects/PH-14/piping/{$line->id}/baseline?lang=fa");
        $fa->assertOk();
        $fa->assertSee('آشتی‌دادن MTO با مدل');
    }

    /** A company with no membership cannot read another project's baseline. */
    public function test_a_non_participant_is_refused(): void
    {
        [$line] = $this->twoAcceptedBatches('134', '120');

        $outsider = $this->tenant->forIdentityLookup(
            fn () => User::where('email', 'hosseini@arvand.example')->firstOrFail()
        );

        $this->actingAs($outsider)
            ->get("/projects/PH-14/piping/{$line->id}/baseline?lang=en")
            ->assertNotFound();
    }

    /**
     * The whole loop through HTTP: upload, accept, reconcile, decide.
     *
     * Exercised end to end because the service being right is not the same as
     * the buttons being wired to it.
     */
    public function test_the_full_loop_works_through_the_screen(): void
    {
        [$line] = $this->twoAcceptedBatches('134', '120');
        $owner = $this->owner();

        $this->actingAs($owner)
            ->post("/projects/PH-14/piping/{$line->id}/baseline/reconcile?lang=en")
            ->assertRedirect();

        $open = $this->asOwner(fn () => BaselineDiscrepancy::where('piping_line_id', $line->id)->firstOrFail());
        $this->assertSame(BaselineDiscrepancy::OPEN, $open->state);

        $this->actingAs($owner)
            ->post("/projects/PH-14/piping/{$line->id}/baseline/{$open->id}/resolve?lang=en", [
                'state' => BaselineDiscrepancy::ACCEPTED_B,
                'resolution_note' => 'Isometric rev C added a spool; the MTO was never re-issued.',
            ])
            ->assertRedirect();

        $resolved = $this->asOwner(fn () => $open->fresh());
        $this->assertSame(BaselineDiscrepancy::ACCEPTED_B, $resolved->state);
        $this->assertEqualsWithDelta(120.0, (float) $resolved->resolved_quantity, 0.001);

        // And the baseline now carries the chosen number rather than a dispute.
        $agreed = $this->asOwner(fn () => $this->svc()->agreedBaseline($line));
        $this->assertFalse($agreed[0]['disputed']);
        $this->assertEqualsWithDelta(120.0, (float) $agreed[0]['quantity'], 0.001);
    }

    /** Reconciling without two accepted sources says so, rather than crashing. */
    public function test_reconciling_too_early_explains_itself(): void
    {
        $line = $this->line();

        $this->actingAs($this->owner())
            ->post("/projects/PH-14/piping/{$line->id}/baseline/reconcile?lang=en")
            ->assertRedirect()
            ->assertSessionHasErrors('file');
    }

    /**
     * An item inherits its line from its batch.
     *
     * Every read is filtered by `piping_line_id`. A row saved without one is
     * not an error anywhere: it sits in an ACCEPTED batch and simply never
     * appears in a baseline or a shortage report. It was a demo-data mistake
     * that exposed this, and a demo-data mistake is exactly how it would reach
     * production - so the model now fills it in from the batch.
     */
    public function test_an_item_inherits_its_line_and_subsystem_from_its_batch(): void
    {
        $line = $this->line();

        $item = $this->asOwner(function () use ($line) {
            $batch = BaselineBatch::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'source_kind' => BaselineBatch::SOURCE_PCF,
                'piping_line_id' => $line->id,
                'subsystem_id' => $line->subsystem_id,
                'state' => BaselineBatch::DRAFT,
                'row_count' => 1,
                'rejected_count' => 0,
                'content_hash' => hash('sha256', 'inherit'),
                'imported_by' => $this->owner()->id,
            ]);

            // Deliberately omits piping_line_id and subsystem_id.
            return EngineeringItem::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'baseline_batch_id' => $batch->id,
                'item_code' => 'OLET-3-6-BW',
                'component_type' => 'OLET',
                'quantity' => 2,
                'unit' => 'EA',
                'source_kind' => BaselineBatch::SOURCE_PCF,
                'confidence' => 90,
            ]);
        });

        $this->assertSame($line->id, $item->piping_line_id);
        $this->assertSame($line->subsystem_id, $item->subsystem_id);
    }

    /**
     * An item only the model knows about still reaches the baseline.
     *
     * The MTO-only direction is the obvious one; this is the direction that
     * matters on site, because it is a real component somebody has to buy that
     * no purchase order has ever heard of.
     */
    public function test_an_item_present_only_in_the_model_appears_in_the_baseline(): void
    {
        [$line, $mto, $model] = $this->twoAcceptedBatches('134', '120');

        $this->asOwner(function () use ($line, $model) {
            EngineeringItem::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->project()->owner_company_id,
                'baseline_batch_id' => $model->id,
                'item_code' => 'OLET-3-6-BW',
                'component_type' => 'OLET',
                'quantity' => 2,
                'unit' => 'EA',
                'source_kind' => BaselineBatch::SOURCE_PCF,
                'confidence' => 90,
            ]);
        });

        $this->asOwner(fn () => $this->svc()->reconcile($line, $this->owner(), $mto, $model));

        $agreed = $this->asOwner(fn () => $this->svc()->agreedBaseline($line));
        $codes = array_column($agreed, 'item_code');

        $this->assertContains('OLET-3-6-BW', $codes);

        $olet = $agreed[array_search('OLET-3-6-BW', $codes, true)];
        $this->assertTrue($olet['disputed'], 'Present in the model and absent from the MTO is itself a disagreement.');
    }
}
