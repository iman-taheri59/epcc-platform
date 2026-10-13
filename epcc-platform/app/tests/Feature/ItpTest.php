<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Document;
use App\Models\Itp;
use App\Models\ItpRecord;
use App\Models\ItpStage;
use App\Models\ItpStageDocument;
use App\Models\ItpStageParty;
use App\Models\ItpTemplate;
use App\Models\MaterialLot;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ItpService;
use App\Services\WarehouseService;
use App\Support\Itp\InterventionCode;
use App\Support\Itp\StageResult;
use App\Support\TenantContext;
use App\Support\TenantType;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\ItpTemplateSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Inspection and Test Plans.
 *
 * The centre of gravity of these tests is the difference between a HOLD and a
 * WITNESS point, because that difference is the entire commercial content of
 * an ITP and it is exactly what a naive "requires inspection" boolean erases.
 */
final class ItpTest extends TestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->seed(ItpTemplateSeeder::class);

        $this->tenant = app(TenantContext::class);
    }

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    private function owner(): User
    {
        return $this->tenant->forIdentityLookup(
            fn () => User::where('email', 'karimi@nioc.example')->firstOrFail()
        );
    }

    private function contractor(): User
    {
        return $this->tenant->forIdentityLookup(
            fn () => User::where('email', 'rezaei@pars.example')->firstOrFail()
        );
    }

    private function asOwner(callable $fn): mixed
    {
        $project = $this->project();

        return $this->tenant->runAs($project->owner_company_id, $fn, $project->id);
    }

    private function svc(): ItpService
    {
        return app(ItpService::class);
    }

    /** The seeded PIPING plan, activated, with its citations loaded. */
    private function activePipingItp(): Itp
    {
        $itp = $this->svc()->createFromTemplate(
            $this->project(),
            $this->owner(),
            ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
        );
        $this->svc()->activate($itp, $this->owner());

        return $itp->fresh(['stages.documents.document', 'stages.parties']);
    }

    // ------------------------------------------------------------- vocabulary

    public function test_only_a_hold_point_blocks(): void
    {
        $this->assertTrue(InterventionCode::blocks(InterventionCode::HOLD));
        $this->assertFalse(InterventionCode::blocks(InterventionCode::WITNESS));
        $this->assertFalse(InterventionCode::blocks(InterventionCode::MONITOR));
        $this->assertFalse(InterventionCode::blocks(InterventionCode::REVIEW));
    }

    public function test_hold_and_witness_both_require_notice(): void
    {
        $this->assertTrue(InterventionCode::requiresNotice(InterventionCode::HOLD));
        $this->assertTrue(InterventionCode::requiresNotice(InterventionCode::WITNESS));
        $this->assertFalse(InterventionCode::requiresNotice(InterventionCode::MONITOR));
    }

    /** A conditional acceptance lets material move; that is what it means. */
    public function test_conditional_and_waived_satisfy_a_stage_but_failed_does_not(): void
    {
        $this->assertTrue(StageResult::satisfied(StageResult::PASSED));
        $this->assertTrue(StageResult::satisfied(StageResult::CONDITIONAL));
        $this->assertTrue(StageResult::satisfied(StageResult::WAIVED));
        $this->assertFalse(StageResult::satisfied(StageResult::FAILED));
        $this->assertFalse(StageResult::satisfied(StageResult::PENDING));
        $this->assertFalse(StageResult::satisfied(StageResult::NOTIFIED));
    }

    // ------------------------------------------------------------- templates

    public function test_piping_and_pipeline_have_separate_templates(): void
    {
        $piping = ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail();
        $pipeline = ItpTemplate::where('code', 'STD-PIPELINE-MAT-01')->firstOrFail();

        $this->assertSame('PIPING', $piping->discipline);
        $this->assertSame('PIPELINE', $pipeline->discipline);

        // Not the same plan wearing two labels: the pipeline one inspects
        // coating, the plant one does PMI.
        $pipingTitles = implode(' ', array_column($piping->stages, 'title_en'));
        $pipelineTitles = implode(' ', array_column($pipeline->stages, 'title_en'));

        $this->assertStringContainsString('Positive material identification', $pipingTitles);
        $this->assertStringNotContainsString('Positive material identification', $pipelineTitles);
        $this->assertStringContainsString('coating', strtolower($pipelineTitles));
    }

    public function test_a_template_is_copied_into_the_project_not_referenced(): void
    {
        $template = ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail();

        $itp = $this->asOwner(fn () => $this->svc()->createFromTemplate(
            $this->project(), $this->owner(), $template
        ));

        $this->assertSame(5, $this->asOwner(fn () => $itp->stages()->count()));
        $this->assertSame($template->id, $itp->source_template_id);

        // Editing the library afterwards must not touch the project.
        $template->update(['title_en' => 'CHANGED IN THE LIBRARY']);

        $reloaded = $this->asOwner(fn () => Itp::findOrFail($itp->id));
        $this->assertNotSame('CHANGED IN THE LIBRARY', $reloaded->title_en);
    }

    public function test_the_party_grid_survives_the_copy(): void
    {
        $template = ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail();

        $itp = $this->asOwner(fn () => $this->svc()->createFromTemplate(
            $this->project(), $this->owner(), $template
        ));

        $pmi = $this->asOwner(
            fn () => ItpStage::with('parties')->where('itp_id', $itp->id)->where('clause', '1.4')->firstOrFail()
        );

        $this->assertTrue($pmi->isHoldPoint());
        $this->assertContains(TenantType::TPI, $pmi->holdingParties());
        $this->assertContains(TenantType::PMC, $pmi->holdingParties());

        // The EPC witnesses it; it does not hold it.
        $epc = $pmi->parties->firstWhere('tenant_type', TenantType::EPC);
        $this->assertSame(InterventionCode::WITNESS, $epc->intervention);
        $this->assertFalse($epc->blocks());
    }

    public function test_an_inactive_template_cannot_be_copied(): void
    {
        $template = ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail();
        $template->update(['is_active' => false]);

        $this->expectException(ValidationException::class);

        $this->asOwner(fn () => $this->svc()->createFromTemplate(
            $this->project(), $this->owner(), $template
        ));
    }

    // ----------------------------------------------------------- the ITP doc

    public function test_only_one_itp_per_discipline_can_be_in_force(): void
    {
        [$first, $second] = $this->asOwner(function () {
            $template = ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail();

            $a = $this->svc()->createFromTemplate($this->project(), $this->owner(), $template, 'PH-14-ITP-PIPING-A');
            $b = $this->svc()->createFromTemplate($this->project(), $this->owner(), $template, 'PH-14-ITP-PIPING-B');

            $this->svc()->activate($a, $this->owner());
            $this->svc()->activate($b, $this->owner());

            return [$a->fresh(), $b->fresh()];
        });

        $this->assertSame(Itp::SUPERSEDED, $first->state);
        $this->assertSame(Itp::ACTIVE, $second->state);
    }

    /**
     * Piping being in force must not put pipeline in force.
     *
     * The partial unique index is per (project, discipline); if it were per
     * project, activating the pipeline ITP would silently retire the piping
     * one and a whole discipline would lose its gate.
     */
    public function test_activating_one_discipline_leaves_the_other_alone(): void
    {
        [$piping, $pipeline] = $this->asOwner(function () {
            $p = $this->svc()->createFromTemplate(
                $this->project(), $this->owner(), ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );
            $l = $this->svc()->createFromTemplate(
                $this->project(), $this->owner(), ItpTemplate::where('code', 'STD-PIPELINE-MAT-01')->firstOrFail()
            );

            $this->svc()->activate($p, $this->owner());
            $this->svc()->activate($l, $this->owner());

            return [$p->fresh(), $l->fresh()];
        });

        $this->assertSame(Itp::ACTIVE, $piping->state);
        $this->assertSame(Itp::ACTIVE, $pipeline->state);
    }

    public function test_the_database_refuses_two_active_itps_for_one_discipline(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(function () {
            $template = ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail();
            $a = $this->svc()->createFromTemplate($this->project(), $this->owner(), $template, 'PH-14-ITP-X1');
            $b = $this->svc()->createFromTemplate($this->project(), $this->owner(), $template, 'PH-14-ITP-X2');

            // Bypass the service and force the state directly.
            DB::table('itps')->where('id', $a->id)->update(['state' => Itp::ACTIVE]);
            DB::table('itps')->where('id', $b->id)->update(['state' => Itp::ACTIVE]);
        });
    }

    public function test_an_empty_itp_cannot_be_put_in_force(): void
    {
        $this->expectException(ValidationException::class);

        $this->asOwner(function () {
            $itp = $this->svc()->createBlank(
                $this->project(), $this->owner(), 'PIPING', 'PH-14-ITP-EMPTY', 'Empty', 'خالی'
            );

            $this->svc()->activate($itp, $this->owner());
        });
    }

    public function test_a_duplicate_clause_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->asOwner(function () {
            $itp = $this->svc()->createBlank(
                $this->project(), $this->owner(), 'PIPING', 'PH-14-ITP-DUP', 'Dup', 'تکراری'
            );

            $this->svc()->addStage($itp, $this->owner(), ['clause' => '1.1', 'title_en' => 'A', 'title_fa' => 'الف']);
            $this->svc()->addStage($itp, $this->owner(), ['clause' => '1.1', 'title_en' => 'B', 'title_fa' => 'ب']);
        });
    }

    public function test_an_unknown_intervention_code_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->asOwner(function () {
            $itp = $this->svc()->createBlank(
                $this->project(), $this->owner(), 'PIPING', 'PH-14-ITP-BAD', 'Bad', 'بد'
            );

            $this->svc()->addStage($itp, $this->owner(), [
                'clause' => '1.1', 'title_en' => 'A', 'title_fa' => 'الف',
                'parties' => [TenantType::TPI => 'Z'],
            ]);
        });
    }

    /** A bad party code must not leave half a stage behind. */
    public function test_a_refused_stage_writes_nothing(): void
    {
        $itp = $this->asOwner(fn () => $this->svc()->createBlank(
            $this->project(), $this->owner(), 'PIPING', 'PH-14-ITP-ATOMIC', 'Atomic', 'اتمی'
        ));

        try {
            $this->asOwner(fn () => $this->svc()->addStage($itp, $this->owner(), [
                'clause' => '9.9', 'title_en' => 'A', 'title_fa' => 'الف',
                'parties' => [TenantType::TPI => 'NOPE'],
            ]));
        } catch (ValidationException) {
            // expected
        }

        $this->assertSame(0, $this->asOwner(fn () => ItpStage::where('itp_id', $itp->id)->count()));
    }

    public function test_a_superseded_itp_is_read_only(): void
    {
        $this->expectException(ValidationException::class);

        $this->asOwner(function () {
            $template = ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail();
            $a = $this->svc()->createFromTemplate($this->project(), $this->owner(), $template, 'PH-14-ITP-S1');
            $b = $this->svc()->createFromTemplate($this->project(), $this->owner(), $template, 'PH-14-ITP-S2');

            $this->svc()->activate($a, $this->owner());
            $this->svc()->activate($b, $this->owner());

            $this->svc()->addStage($a->fresh(), $this->owner(), [
                'clause' => '99', 'title_en' => 'late', 'title_fa' => 'دیر',
            ]);
        });
    }

    // ----------------------------------------------------------- application

    /** @return array{0:MaterialLot,1:Itp} */
    private function lotWithPlan(string $discipline = 'PIPING'): array
    {
        return $this->asOwner(function () use ($discipline) {
            $project = $this->project();
            $code = $discipline === 'PIPING' ? 'STD-PIPING-MAT-01' : 'STD-PIPELINE-MAT-01';

            $itp = $this->svc()->createFromTemplate(
                $project, $this->owner(), ItpTemplate::where('code', $code)->firstOrFail()
            );
            $this->svc()->activate($itp, $this->owner());

            $lot = MaterialLot::create([
                'project_id' => $project->id,
                'company_id' => $project->owner_company_id,
                'subsystem_id' => Subsystem::first()->id,
                'lot_code' => 'LOT-ITP-1',
                'item_code' => 'PIPE-CS-A106B-STD',
                'description_en' => '6in pipe',
                'description_fa' => 'لولهٔ ۶ اینچ',
                'unit' => 'M',
            ]);

            $this->svc()->openForLot($lot, $discipline);

            return [$lot->fresh(), $itp->fresh()];
        });
    }

    public function test_opening_a_lot_creates_one_pending_record_per_stage(): void
    {
        [$lot] = $this->lotWithPlan();

        $records = $this->asOwner(fn () => ItpRecord::where('material_lot_id', $lot->id)->get());

        $this->assertCount(5, $records);
        $this->assertTrue($records->every(fn (ItpRecord $r): bool => $r->result === StageResult::PENDING));
    }

    /** A revised ITP adds a row mid-project; re-opening must not duplicate. */
    public function test_opening_twice_adds_only_what_is_missing(): void
    {
        [$lot, $itp] = $this->lotWithPlan();

        $this->asOwner(function () use ($lot, $itp) {
            $this->svc()->addStage($itp, $this->owner(), [
                'clause' => '1.6', 'sequence' => 60,
                'title_en' => 'Extra check', 'title_fa' => 'بازرسی اضافه',
                'parties' => [TenantType::TPI => InterventionCode::WITNESS],
            ]);

            $this->svc()->openForLot($lot, 'PIPING');
        });

        $this->assertSame(6, $this->asOwner(fn () => ItpRecord::where('material_lot_id', $lot->id)->count()));
    }

    public function test_a_lot_cannot_be_opened_without_an_active_itp(): void
    {
        $this->expectException(ValidationException::class);

        $this->asOwner(function () {
            $project = $this->project();

            $lot = MaterialLot::create([
                'project_id' => $project->id,
                'company_id' => $project->owner_company_id,
                'lot_code' => 'LOT-NO-ITP',
                'item_code' => 'X',
                'description_en' => 'x',
                'description_fa' => 'ایکس',
                'unit' => 'EA',
            ]);

            $this->svc()->openForLot($lot, 'PIPING');
        });
    }

    // ------------------------------------------------------------- signature

    private function recordFor(MaterialLot $lot, string $clause): ItpRecord
    {
        return $this->asOwner(
            fn () => ItpRecord::whereHas('stage', fn ($q) => $q->where('clause', $clause))
                ->where('material_lot_id', $lot->id)
                ->firstOrFail()
        );
    }

    public function test_a_party_with_no_role_at_a_stage_cannot_sign_it(): void
    {
        [$lot] = $this->lotWithPlan();

        // Clause 1.5 has no TPI row at all in the template.
        $record = $this->recordFor($lot, '1.5');

        $tpiUser = $this->tenant->forIdentityLookup(function () {
            $tpi = Company::where('tenant_type', TenantType::TPI)->first();

            return $tpi === null ? null : User::where('company_id', $tpi->id)->first();
        });

        if ($tpiUser === null) {
            $this->markTestSkipped('No TPI user in the fixture.');
        }

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->svc()->sign($record, $tpiUser, StageResult::PASSED));
    }

    public function test_signing_records_who_signed_and_for_which_party(): void
    {
        [$lot] = $this->lotWithPlan();
        $record = $this->recordFor($lot, '1.1');

        $signed = $this->asOwner(fn () => $this->svc()->sign(
            $record, $this->owner(), StageResult::PASSED
        ));

        $this->assertSame(StageResult::PASSED, $signed->result);
        $this->assertSame($this->owner()->id, $signed->signed_by);
        $this->assertSame(TenantType::OWN, $signed->signed_tenant_type);
        $this->assertNotNull($signed->signed_at);
    }

    public function test_a_failure_needs_a_written_reason(): void
    {
        [$lot] = $this->lotWithPlan();
        $record = $this->recordFor($lot, '1.1');

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->svc()->sign($record, $this->owner(), StageResult::FAILED));
    }

    public function test_a_conditional_acceptance_needs_a_reason_too(): void
    {
        [$lot] = $this->lotWithPlan();
        $record = $this->recordFor($lot, '1.1');

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->svc()->sign($record, $this->owner(), StageResult::CONDITIONAL));
    }

    public function test_a_stage_cannot_be_signed_twice(): void
    {
        [$lot] = $this->lotWithPlan();
        $record = $this->recordFor($lot, '1.1');

        $this->asOwner(fn () => $this->svc()->sign($record, $this->owner(), StageResult::PASSED));

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->svc()->sign($record->fresh(), $this->owner(), StageResult::FAILED));
    }

    public function test_signing_to_a_pending_state_is_not_a_signature(): void
    {
        [$lot] = $this->lotWithPlan();
        $record = $this->recordFor($lot, '1.1');

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->svc()->sign($record, $this->owner(), StageResult::PENDING));
    }

    /** The database, not just the service, insists a verdict is attributable. */
    public function test_the_database_refuses_a_settled_record_with_no_signer(): void
    {
        [$lot] = $this->lotWithPlan();
        $record = $this->recordFor($lot, '1.1');

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => DB::table('itp_records')
            ->where('id', $record->id)
            ->update(['result' => StageResult::PASSED]));
    }

    // ------------------------------------------------------- hold vs witness

    /**
     * The distinction the whole document exists to express.
     *
     * A witness point the party skipped can be waived and the work proceeds.
     * A hold point cannot: it waits, indefinitely if necessary.
     */
    public function test_a_witness_point_can_be_waived_but_a_hold_point_cannot(): void
    {
        [$lot] = $this->lotWithPlan();

        // 1.2 is a witness point the OWNER attends; 1.4 (PMI) is a hold.
        // Clause 1.3 would be the obvious "witness" pick but the owner holds
        // no role there at all, so signing it fails for an unrelated reason -
        // the guard under test would never be reached.
        $witness = $this->recordFor($lot, '1.2');
        $hold = $this->recordFor($lot, '1.4');

        $waived = $this->asOwner(function () use ($witness) {
            $notified = $this->svc()->notify($witness, $this->owner(), 'LTR-2026-0912');

            return $this->svc()->sign(
                $notified, $this->owner(), StageResult::WAIVED,
                findingsEn: 'Invited 48h ahead; did not attend.',
                findingsFa: 'با ۴۸ ساعت اعلام دعوت شد؛ حاضر نشد.',
            );
        });

        $this->assertSame(StageResult::WAIVED, $waived->result);

        // Now the hold point. It has to be attempted BY A PARTY THAT HOLDS IT,
        // or the refusal would come from "you have no role here" and prove
        // nothing about hold-versus-witness. At clause 1.4 the owner only
        // monitors, so the PMC signs.
        $pmc = $this->tenant->forIdentityLookup(function () {
            $company = Company::where('tenant_type', TenantType::PMC)->first();

            return $company === null ? null : User::where('company_id', $company->id)->first();
        });

        if ($pmc === null) {
            $this->markTestSkipped('No PMC user in the fixture.');
        }

        $refused = false;

        try {
            $this->asOwner(function () use ($hold, $pmc) {
                $notified = $this->svc()->notify($hold, $pmc, 'LTR-2026-0913');

                $this->svc()->sign(
                    $notified, $pmc, StageResult::WAIVED,
                    findingsEn: 'Did not attend.',
                    findingsFa: 'حاضر نشد.',
                );
            });
        } catch (ValidationException $e) {
            $refused = true;
            $this->assertSame(
                __('epcc.itp.hold_cannot_be_waived'),
                $e->errors()['result'][0] ?? '',
                'The refusal must be about the hold point, not about the signer having no role.',
            );
        }

        $this->assertTrue($refused, 'A hold point must never be waivable.');
    }

    public function test_a_waiver_without_a_recorded_invitation_is_refused(): void
    {
        [$lot] = $this->lotWithPlan();
        $witness = $this->recordFor($lot, '1.2');

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->svc()->sign(
            $witness, $this->owner(), StageResult::WAIVED,
            findingsEn: 'Nobody came.', findingsFa: 'کسی نیامد.',
        ));
    }

    /** And the database says so too, independently of the service. */
    public function test_the_database_refuses_a_waiver_with_no_notice(): void
    {
        [$lot] = $this->lotWithPlan();
        $record = $this->recordFor($lot, '1.2');

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asOwner(fn () => DB::table('itp_records')->where('id', $record->id)->update([
            'result' => StageResult::WAIVED,
            'signed_by' => $this->owner()->id,
            'signed_tenant_type' => TenantType::OWN,
            'signed_at' => now(),
        ]));
    }

    public function test_a_notice_needs_a_reference(): void
    {
        [$lot] = $this->lotWithPlan();
        $record = $this->recordFor($lot, '1.2');

        $this->expectException(ValidationException::class);
        $this->asOwner(fn () => $this->svc()->notify($record, $this->owner(), '   '));
    }

    // -------------------------------------------------------------- the gate

    public function test_an_open_hold_point_blocks_the_release(): void
    {
        [$lot] = $this->lotWithPlan();

        $this->assertNotSame([], $this->asOwner(fn () => $this->svc()->openHoldsFor($lot)));
        $this->assertFalse($this->asOwner(fn () => $this->svc()->releaseAllowed($lot)));
    }

    /**
     * ...but it does NOT block the goods receipt.
     *
     * The user's ruling, and the one placement that survives a real site: the
     * truck arrives at 2am and the material must be counted whether or not the
     * TPI has been.
     */
    public function test_an_open_hold_point_does_not_block_the_goods_receipt(): void
    {
        $this->lotWithPlan();

        $received = $this->asOwner(function () {
            $project = $this->project();
            $warehouse = app(WarehouseService::class);

            $po = PurchaseOrder::create([
                'project_id' => $project->id,
                'company_id' => $project->owner_company_id,
                'vendor_company_id' => Company::where('id', '!=', $project->owner_company_id)->firstOrFail()->id,
                'number' => 'PO-ITP-1',
                'sequence' => 7001,
                'title_en' => 'Pipe',
                'title_fa' => 'لوله',
                'state' => 'ISSUED',
                'created_by' => $this->owner()->id,
            ]);

            return $warehouse->receive(
                $project, $this->owner(), $po,
                itemCode: 'PIPE-CS-A106B-STD',
                descriptionEn: '6in pipe', descriptionFa: 'لولهٔ ۶ اینچ',
                unit: 'M', quantity: 100,
                lang: 'en',
            );
        });

        $this->assertNotNull($received);
    }

    public function test_releasing_material_is_refused_while_a_hold_is_open(): void
    {
        [$lot] = $this->lotWithPlan();

        $this->expectException(ValidationException::class);

        $this->asOwner(function () use ($lot) {
            app(WarehouseService::class)->issue(
                $this->project(), $this->owner(), $lot->fresh(), 10.0, lang: 'en'
            );
        });
    }

    /** A pending WITNESS point must not block anything. */
    public function test_a_pending_witness_point_alone_does_not_block_release(): void
    {
        $lot = $this->asOwner(function () {
            $project = $this->project();

            $itp = $this->svc()->createBlank(
                $project, $this->owner(), 'PIPING', 'PH-14-ITP-W', 'Witness only', 'فقط شاهد'
            );

            $this->svc()->addStage($itp, $this->owner(), [
                'clause' => '1.1', 'title_en' => 'Visual', 'title_fa' => 'چشمی',
                'parties' => [TenantType::TPI => InterventionCode::WITNESS, TenantType::OWN => InterventionCode::MONITOR],
            ]);

            $this->svc()->activate($itp, $this->owner());

            $lot = MaterialLot::create([
                'project_id' => $project->id,
                'company_id' => $project->owner_company_id,
                'lot_code' => 'LOT-W-1',
                'item_code' => 'GASKET-SW-6-150',
                'description_en' => 'gasket',
                'description_fa' => 'واشر',
                'unit' => 'EA',
            ]);

            $this->svc()->openForLot($lot, 'PIPING');

            return $lot->fresh();
        });

        $this->assertSame([], $this->asOwner(fn () => $this->svc()->openHoldsFor($lot)));
        $this->assertTrue($this->asOwner(fn () => $this->svc()->releaseAllowed($lot)));
    }

    public function test_signing_off_every_hold_opens_the_gate(): void
    {
        [$lot] = $this->lotWithPlan();

        $this->asOwner(function () use ($lot) {
            foreach ($this->svc()->openHoldsFor($lot) as $hold) {
                $this->svc()->sign($hold['record'], $this->owner(), StageResult::PASSED);
            }
        });

        $this->assertTrue($this->asOwner(fn () => $this->svc()->releaseAllowed($lot->fresh())));
    }

    /** A conditional acceptance releases the material - that is its meaning. */
    public function test_a_conditionally_accepted_hold_releases_the_material(): void
    {
        [$lot] = $this->lotWithPlan();

        $this->asOwner(function () use ($lot) {
            foreach ($this->svc()->openHoldsFor($lot) as $hold) {
                $this->svc()->sign(
                    $hold['record'], $this->owner(), StageResult::CONDITIONAL,
                    findingsEn: 'Accepted with reservation; 2 lengths to be re-bevelled.',
                    findingsFa: 'با قید پذیرفته شد؛ پخ دو شاخه اصلاح شود.',
                );
            }
        });

        $this->assertTrue($this->asOwner(fn () => $this->svc()->releaseAllowed($lot->fresh())));
    }

    /** A failed hold point keeps the gate shut. */
    public function test_a_failed_hold_point_keeps_the_material_locked(): void
    {
        [$lot] = $this->lotWithPlan();

        $this->asOwner(function () use ($lot) {
            foreach ($this->svc()->openHoldsFor($lot) as $hold) {
                $this->svc()->sign(
                    $hold['record'], $this->owner(), StageResult::FAILED,
                    findingsEn: 'PMI reports 316L where 321 was ordered.',
                    findingsFa: 'نتیجهٔ PMI جنس 316L است در حالی که 321 سفارش شده بود.',
                );
            }
        });

        $this->assertFalse($this->asOwner(fn () => $this->svc()->releaseAllowed($lot->fresh())));
    }

    public function test_progress_counts_stages_and_open_holds(): void
    {
        [$lot] = $this->lotWithPlan();

        $before = $this->asOwner(fn () => $this->svc()->progressFor($lot));
        $this->assertSame(5, $before['total']);
        $this->assertSame(0, $before['settled']);
        $this->assertSame(2, $before['open_holds']);

        $this->asOwner(fn () => $this->svc()->sign(
            $this->recordFor($lot, '1.1'), $this->owner(), StageResult::PASSED
        ));

        $after = $this->asOwner(fn () => $this->svc()->progressFor($lot));
        $this->assertSame(1, $after['settled']);
        $this->assertSame(1, $after['open_holds']);
        $this->assertEqualsWithDelta(20.0, $after['percent'], 0.01);
    }

    // ------------------------------------------------------------------ RLS

    public function test_a_non_participant_cannot_read_another_projects_itp(): void
    {
        [, $itp] = $this->lotWithPlan();

        $outsider = $this->tenant->forIdentityLookup(
            fn () => User::where('email', 'hosseini@arvand.example')->firstOrFail()
        );

        $visible = $this->tenant->runAs(
            $outsider->company_id,
            fn () => Itp::where('id', $itp->id)->count(),
        );

        $this->assertSame(0, $visible);
    }

    // ----------------------------------------------------------------- screen

    public function test_the_itp_register_and_grid_render_in_both_languages(): void
    {
        [, $itp] = $this->lotWithPlan();

        foreach (['en', 'fa'] as $language) {
            $this->actingAs($this->owner())
                ->get("/projects/PH-14/itp?lang={$language}")
                ->assertOk()
                ->assertSee($itp->number);

            $grid = $this->actingAs($this->owner())
                ->get("/projects/PH-14/itp/{$itp->id}?lang={$language}");

            $grid->assertOk();
            // The party grid must actually be on the page: the H of the PMI
            // hold point, and the TPI column it sits in.
            $grid->assertSee('TPI');
            $grid->assertSee('PMI');
        }
    }

    public function test_the_lot_screen_states_plainly_that_release_is_blocked(): void
    {
        [$lot] = $this->lotWithPlan();

        $this->actingAs($this->owner())
            ->get("/projects/PH-14/itp/lots/{$lot->id}?lang=en")
            ->assertOk()
            ->assertSee('This lot cannot be released');

        $this->actingAs($this->owner())
            ->get("/projects/PH-14/itp/lots/{$lot->id}?lang=fa")
            ->assertOk()
            ->assertSee('قابل ترخیص نیست');
    }

    public function test_the_lot_screen_says_released_once_the_holds_are_signed(): void
    {
        [$lot] = $this->lotWithPlan();

        $this->asOwner(function () use ($lot) {
            foreach ($this->svc()->openHoldsFor($lot) as $hold) {
                $this->svc()->sign($hold['record'], $this->owner(), StageResult::PASSED);
            }
        });

        $this->actingAs($this->owner())
            ->get("/projects/PH-14/itp/lots/{$lot->id}?lang=en")
            ->assertOk()
            ->assertSee('Released for installation');
    }

    /** The whole loop through HTTP, not just the service. */
    public function test_a_stage_can_be_signed_through_the_screen(): void
    {
        [$lot] = $this->lotWithPlan();
        $record = $this->recordFor($lot, '1.1');

        $this->actingAs($this->owner())
            ->post("/projects/PH-14/itp/lots/{$lot->id}/{$record->id}/sign?lang=en", [
                'result' => StageResult::PASSED,
            ])
            ->assertRedirect();

        $this->assertSame(
            StageResult::PASSED,
            $this->asOwner(fn () => $record->fresh()->result),
        );
    }

    public function test_a_non_participant_cannot_open_the_itp_screen(): void
    {
        $this->lotWithPlan();

        $outsider = $this->tenant->forIdentityLookup(
            fn () => User::where('email', 'hosseini@arvand.example')->firstOrFail()
        );

        $this->actingAs($outsider)
            ->get('/projects/PH-14/itp?lang=en')
            ->assertNotFound();
    }

    // =====================================================================
    //  Row references: standards, project specs, and the report form
    //
    //  The user's requirement: "each ITP contains the row number, activities,
    //  standards, the related project references and specs, the numbers of the
    //  relevant inspection report forms, and the role of each party."
    // =====================================================================

    public function test_a_stage_cites_a_project_specification_by_foreign_key(): void
    {
        $this->asOwner(function (): void {
            $itp = $this->activePipingItp();
            $stage = $itp->stages->firstWhere('clause', '1.1');

            $spec = $stage->documentsFor(ItpStageDocument::SPEC)->first();

            $this->assertNotNull($spec, 'clause 1.1 should cite the piping supply specification');
            $this->assertSame('PH-14-PIPING-SPEC-0001', $spec->document->number);
            $this->assertSame('§7.3', $spec->locator);
        });
    }

    public function test_the_standards_column_holds_external_codes_only(): void
    {
        $this->asOwner(function (): void {
            $itp = $this->activePipingItp();
            $stage = $itp->stages->firstWhere('clause', '1.1');

            // An external code stays a plain string: we do not own EN 10204 and
            // it is not in anybody's document register.
            $this->assertStringContainsString('EN 10204', (string) $stage->reference);

            // ...and it must NOT have leaked into the project-document table.
            $this->assertSame(
                0,
                $stage->documents->filter(
                    fn ($d) => str_contains((string) $d->document?->number, 'EN 10204')
                )->count()
            );
        });
    }

    public function test_a_registered_form_wins_over_the_printed_form_number(): void
    {
        $this->asOwner(function (): void {
            $itp = $this->activePipingItp();

            // 1.4 PMI cites a registered form document.
            $pmi = $itp->stages->firstWhere('clause', '1.4');
            $this->assertSame('PH-14-PIPING-FORM-0002', $pmi->formLabel());

            // 1.5 storage has only a printed number - most client forms never
            // reach our register, and the row must still say which form to use.
            $storage = $itp->stages->firstWhere('clause', '1.5');
            $this->assertSame('QCF-PIP-022', $storage->formLabel());
            $this->assertCount(0, $storage->documentsFor(ItpStageDocument::FORM));
        });
    }

    public function test_a_template_citing_a_document_this_project_lacks_is_skipped_not_fatal(): void
    {
        $this->asOwner(function (): void {
            $svc = $this->svc();
            $itp = $svc->createBlank($this->project(), $this->owner(), 'CIVIL', 'PH-14-ITP-CIVIL-99', 'T', 'ت');

            // A standard template legitimately names a spec a given project
            // never issued. Refusing the whole copy would make the library
            // unusable, so the citation is dropped and the stage survives.
            $stage = $svc->addStage($itp, $this->owner(), [
                'clause' => '1.1',
                'title_en' => 'Concrete pour',
                'title_fa' => 'بتن‌ریزی',
                'documents' => [
                    ['document_number' => 'PH-14-CIVIL-SPEC-9999', 'usage' => 'SPEC'],
                    ['document_number' => 'PH-14-PIPING-SPEC-0001', 'usage' => 'SPEC'],
                ],
            ]);

            $this->assertCount(1, $stage->documents, 'the unresolvable number should be skipped, the real one kept');
        });
    }

    public function test_naming_a_document_id_from_another_project_is_refused(): void
    {
        $this->asOwner(function (): void {
            $svc = $this->svc();
            $itp = $this->activePipingItp();
            $stage = $itp->stages->first();

            // A concrete id that is not ours is a caller error, not a template
            // quirk: fail loudly rather than silently citing nothing.
            try {
                $svc->citeDocument($stage, [
                    'document_id' => (string) Str::uuid(),
                    'usage' => 'SPEC',
                ]);
                $this->fail('a document id outside the project should be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('document_id', $e->errors());
            }
        });
    }

    public function test_a_pinned_revision_must_belong_to_the_cited_document(): void
    {
        $this->asOwner(function (): void {
            $svc = $this->svc();
            $itp = $this->activePipingItp();
            $stage = $itp->stages->firstWhere('clause', '1.3');

            $spec = Document::where('project_id', $this->project()->id)
                ->where('number', 'PH-14-PIPING-SPEC-0001')->firstOrFail();
            $other = Document::where('project_id', $this->project()->id)
                ->where('number', 'PH-14-PIPING-DWG-0001')->firstOrFail();

            // Pinning revision B *of a different drawing* would make the ITP
            // resolve to a revision of something else entirely.
            try {
                $svc->citeDocument($stage, [
                    'document_id' => $spec->id,
                    'usage' => 'DRAWING',
                    'pinned_revision_id' => $other->current_revision_id,
                ]);
                $this->fail('a revision of another document should be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('pinned_revision_id', $e->errors());
            }
        });
    }

    public function test_an_unpinned_citation_follows_the_register_forward(): void
    {
        $this->asOwner(function (): void {
            $itp = $this->activePipingItp();
            $spec = $itp->stages->firstWhere('clause', '1.1')
                ->documentsFor(ItpStageDocument::SPEC)->first();

            $this->assertNull($spec->pinned_revision_id);

            // Unpinned means "whatever the register currently says", which is
            // what a QA manager expects when a spec is reissued.
            $this->assertSame(
                $spec->document->current_revision_id,
                $spec->effectiveRevisionId()
            );
        });
    }

    public function test_an_unknown_usage_is_refused(): void
    {
        $this->asOwner(function (): void {
            $svc = $this->svc();
            $itp = $this->activePipingItp();
            $spec = Document::where('project_id', $this->project()->id)
                ->where('number', 'PH-14-PIPING-SPEC-0001')->firstOrFail();

            try {
                $svc->citeDocument($itp->stages->first(), [
                    'document_id' => $spec->id,
                    'usage' => 'WHATEVER',
                ]);
                $this->fail('an unknown usage should be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('usage', $e->errors());
            }
        });
    }

    public function test_the_grid_screen_renders_standards_specs_and_form_columns(): void
    {
        $project = $this->project();
        $owner = $this->owner();
        $itpId = $this->asOwner(fn () => $this->activePipingItp()->id);

        foreach (['en' => 'Project references', 'fa' => 'مدارک پروژه'] as $lang => $heading) {
            $res = $this->actingAs($owner)
                ->get("/projects/{$project->code}/itp/{$itpId}?lang={$lang}");

            $res->assertOk();
            $res->assertSee($heading, false);
            // The spec number and the registered form both reach the page.
            $res->assertSee('PH-14-PIPING-SPEC-0001', false);
            $res->assertSee('PH-14-PIPING-FORM-0002', false);
            // ...and so does a form that exists only as a printed number.
            $res->assertSee('QCF-PIP-022', false);
        }
    }

    // =====================================================================
    //  The ITP is itself a project document; the report forms are its annexes
    //
    //  The user's ruling. Before this the ITP had its own number and its own
    //  lifecycle beside the register - a parallel registry, which drifts, and
    //  which keeps the plan off the transmittal and out of the client's MDR.
    // =====================================================================

    public function test_registering_a_plan_puts_it_in_the_document_register(): void
    {
        $this->asOwner(function (): void {
            $itp = $this->svc()->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );

            $this->assertFalse($itp->isRegistered(), 'a fresh plan is not yet a controlled document');

            $document = $this->svc()->registerAsDocument($itp, $this->owner());
            $itp->refresh();

            $this->assertTrue($itp->isRegistered());
            $this->assertSame('ITP', $document->doc_type);
            $this->assertSame('PIPING', $document->discipline);
            // The REGISTER mints the number - that is the whole point.
            $this->assertSame('PH-14-PIPING-ITP-0001', $document->number);
            $this->assertSame($document->number, $itp->displayNumber());
        });
    }

    public function test_a_registered_plan_enters_the_cde_at_wip(): void
    {
        $this->asOwner(function (): void {
            $itp = $this->svc()->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );
            $this->svc()->registerAsDocument($itp, $this->owner());

            // An ITP is a deliverable: it starts WIP and is governed by the
            // same review cycle as any datasheet.
            $this->assertSame('WIP', $itp->fresh()->cdeState());
        });
    }

    public function test_registering_twice_does_not_mint_a_second_number(): void
    {
        $this->asOwner(function (): void {
            $itp = $this->svc()->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );

            $first = $this->svc()->registerAsDocument($itp, $this->owner());
            $second = $this->svc()->registerAsDocument($itp->fresh(), $this->owner());

            $this->assertSame($first->id, $second->id);
            $this->assertSame(1, Document::where('project_id', $this->project()->id)
                ->where('doc_type', 'ITP')->count());
        });
    }

    public function test_the_gate_state_and_the_cde_state_stay_separate(): void
    {
        $this->asOwner(function (): void {
            $itp = $this->svc()->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );
            $this->svc()->registerAsDocument($itp, $this->owner());
            $this->svc()->activate($itp->fresh(), $this->owner());

            $itp = $itp->fresh();

            // ACTIVE is about which plan holds the gate; WIP is about
            // publication. A plan can be in force while its document is still
            // awaiting the client's stamp, and conflating the two would let a
            // publication step silently switch off a hold point.
            $this->assertSame('ACTIVE', $itp->state);
            $this->assertSame('WIP', $itp->cdeState());
        });
    }

    public function test_the_report_forms_become_annexes_of_the_plan(): void
    {
        $this->asOwner(function (): void {
            $itp = $this->svc()->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );
            $this->svc()->syncAnnexesFromStages($itp);

            $annexes = $itp->fresh(['annexes.document'])->annexes;

            // Two distinct forms across five rows: the incoming inspection
            // report and the PMI record. Not five - a forty-row ITP has two or
            // three annexes, not forty.
            $this->assertCount(2, $annexes);
            $this->assertSame(['A', 'B'], $annexes->pluck('label')->all());
            $this->assertSame(
                ['PH-14-PIPING-FORM-0001', 'PH-14-PIPING-FORM-0002'],
                $annexes->map(fn ($a) => $a->document->number)->all(),
            );
        });
    }

    public function test_syncing_annexes_twice_does_not_duplicate_them(): void
    {
        $this->asOwner(function (): void {
            $itp = $this->svc()->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );
            $this->svc()->syncAnnexesFromStages($itp);
            $this->svc()->syncAnnexesFromStages($itp->fresh());

            $this->assertCount(2, $itp->fresh('annexes')->annexes);
        });
    }

    public function test_the_plan_cannot_be_its_own_annex(): void
    {
        $this->asOwner(function (): void {
            $itp = $this->svc()->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );
            $own = $this->svc()->registerAsDocument($itp, $this->owner());

            try {
                $this->svc()->attachAnnex($itp->fresh(), $own);
                $this->fail('a plan must not be bound in as its own annex');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('document_id', $e->errors());
            }
        });
    }

    public function test_an_annex_from_another_project_is_refused(): void
    {
        $this->asOwner(function (): void {
            $itp = $this->svc()->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );

            // Forged rather than fetched: a document of another project is
            // invisible here under RLS, which is the point.
            $alien = new Document(['project_id' => (string) Str::uuid()]);
            $alien->id = (string) Str::uuid();

            try {
                $this->svc()->attachAnnex($itp, $alien);
                $this->fail('an annex from another project should be refused');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('document_id', $e->errors());
            }
        });
    }

    public function test_the_screen_shows_the_register_number_and_the_annexes(): void
    {
        $project = $this->project();
        $owner = $this->owner();

        $itpId = $this->asOwner(function (): string {
            $itp = $this->svc()->createFromTemplate(
                $this->project(), $this->owner(),
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            );
            $this->svc()->registerAsDocument($itp, $this->owner());
            $this->svc()->syncAnnexesFromStages($itp->fresh());

            return $itp->id;
        });

        foreach (['en' => 'Annexes', 'fa' => 'پیوست‌ها'] as $lang => $heading) {
            $res = $this->actingAs($owner)->get("/projects/{$project->code}/itp/{$itpId}?lang={$lang}");

            $res->assertOk();
            $res->assertSee($heading, false);
            $res->assertSee('PH-14-PIPING-ITP-0001', false);
            $res->assertSee('PH-14-PIPING-FORM-0001', false);
        }
    }

    public function test_an_unregistered_plan_says_so_on_the_screen(): void
    {
        $project = $this->project();
        $owner = $this->owner();

        $itpId = $this->asOwner(fn () => $this->svc()->createFromTemplate(
            $this->project(), $this->owner(),
            ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
        )->id);

        $res = $this->actingAs($owner)->get("/projects/{$project->code}/itp/{$itpId}?lang=fa");

        $res->assertOk();
        // It must be obvious that this is not yet a controlled document.
        $res->assertSee('در مدارک ثبت نشده', false);
        $res->assertSee('ثبت در مدارک پروژه', false);
    }

    public function test_a_plan_can_be_registered_over_http(): void
    {
        $project = $this->project();
        $owner = $this->owner();

        $itpId = $this->asOwner(fn () => $this->svc()->createFromTemplate(
            $this->project(), $this->owner(),
            ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
        )->id);

        $this->actingAs($owner)
            ->post("/projects/{$project->code}/itp/{$itpId}/register", ['lang' => 'fa'])
            ->assertRedirect("/projects/{$project->code}/itp/{$itpId}?lang=fa");

        $itp = $this->asOwner(fn () => Itp::with('annexes')->findOrFail($itpId));

        $this->assertTrue($itp->isRegistered(), 'the HTTP route must register the plan');
        // ...and bind the forms in, because that is one intent, not two.
        $this->assertCount(2, $itp->annexes);
    }

    // =====================================================================
    //  RLS repair: a member is not the same thing as the creator
    //
    //  The six ITP tables were created with a policy reading
    //  project_memberships.company_id - a column that does not exist. Postgres
    //  resolved the name against the outer table instead of erroring, so the
    //  policy degenerated to "rows my own company created". Every existing ITP
    //  test acts as the owner, who IS the creator, so all 63 passed while the
    //  TPI could not see the plan naming it.
    // =====================================================================

    public function test_a_third_party_inspector_can_read_the_plan_it_is_named_on(): void
    {
        $project = $this->project();

        $this->asOwner(fn () => $this->svc()->createFromTemplate(
            $this->project(), $this->owner(),
            ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
        ));

        $tpi = Company::where('tenant_type', TenantType::TPI)->firstOrFail();

        [$itps, $stages] = $this->tenant->runAs(
            $tpi->id,
            fn () => [Itp::count(), ItpStage::count()],
            $project->id,
        );

        // The whole point of an ITP is that the nominated inspector can read
        // it. Before the repair these were both 0.
        $this->assertSame(1, $itps, 'a TPI that is a project member must see the plan');
        $this->assertSame(5, $stages);
    }

    public function test_every_member_of_the_project_sees_the_same_plan(): void
    {
        $project = $this->project();

        $this->asOwner(fn () => $this->svc()->createFromTemplate(
            $this->project(), $this->owner(),
            ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
        ));

        foreach ([TenantType::EPC, TenantType::PMC, TenantType::MC, TenantType::TPI] as $type) {
            $company = Company::where('tenant_type', $type)->firstOrFail();

            $seen = $this->tenant->runAs($company->id, fn () => Itp::count(), $project->id);

            $this->assertSame(1, $seen, "{$type} is a member and must see the plan");
        }
    }

    public function test_a_company_outside_the_project_still_sees_nothing(): void
    {
        $project = $this->project();

        $this->asOwner(fn () => $this->svc()->createFromTemplate(
            $this->project(), $this->owner(),
            ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
        ));

        // The repair widens read access to members. It must not widen it
        // to everybody.
        $outsider = $this->tenant->forIdentityLookup(
            fn () => Company::whereNotIn('id', function ($q) use ($project): void {
                $q->select('company_id')->from('project_company')->where('project_id', $project->id);
            })->firstOrFail()
        );

        $seen = $this->tenant->runAs($outsider->id, fn () => Itp::count(), $project->id);

        $this->assertSame(0, $seen);
    }

    public function test_a_member_cannot_write_rows_belonging_to_another_company(): void
    {
        $project = $this->project();

        $itp = $this->asOwner(fn () => $this->svc()->createFromTemplate(
            $this->project(), $this->owner(),
            ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
        ));

        $tpi = Company::where('tenant_type', TenantType::TPI)->firstOrFail();

        // Reading the plan is allowed; rewriting the owner's text is not.
        // The WITH CHECK clause pins company_id to the acting company, and an
        // RLS-blocked write is silent, so assert on the stored value.
        $this->tenant->runAs($tpi->id, function () use ($itp): void {
            try {
                Itp::where('id', $itp->id)->update(['title_en' => 'REWRITTEN BY THE TPI']);
            } catch (\Throwable) {
                // Either a refusal or a silent no-op is acceptable here.
            }
        }, $project->id);

        $this->assertNotSame(
            'REWRITTEN BY THE TPI',
            $this->asOwner(fn () => Itp::findOrFail($itp->id))->title_en,
        );
    }

    public function test_a_nominated_party_can_sign_while_bound_as_itself(): void
    {
        /*
         * The reason itp_records keeps a wider write rule than the five tables
         * holding the plan text: a signature is an UPDATE performed by a
         * company that is deliberately NOT the record's owner - openForLot()
         * stamps the record with the lot owner's company, and the TPI then
         * signs it.
         *
         * Note this test binds the tenant as the TPI. Every other signing test
         * signs inside asOwner(), which is exactly the blind spot that let the
         * broken RLS policy survive 63 green tests.
         */
        [$lot] = $this->lotWithPlan();
        $record = $this->recordFor($lot, '1.4');   // PMI - a TPI hold point

        $tpiUser = $this->tenant->forIdentityLookup(function () {
            $tpi = Company::where('tenant_type', TenantType::TPI)->firstOrFail();

            return User::where('company_id', $tpi->id)->firstOrFail();
        });

        $this->tenant->runAs($tpiUser->company_id, function () use ($record, $tpiUser): void {
            $this->svc()->sign($record, $tpiUser, StageResult::PASSED, 'PMI verified');
        }, $this->project()->id);

        $signed = $this->asOwner(fn () => ItpRecord::findOrFail($record->id));

        $this->assertSame(StageResult::PASSED, $signed->result);
        $this->assertSame(TenantType::TPI, $signed->signed_tenant_type);
        $this->assertSame($tpiUser->id, $signed->signed_by);
    }

    // =====================================================================
    //  Who may set the roles of the project parties
    //
    //  The user's ruling: only the employer, the management contractor and the
    //  consultant. The party grid is an allocation of authority - a TPI able to
    //  edit it can write itself out of a hold point.
    // =====================================================================

    public function test_a_third_party_inspector_cannot_assign_party_roles(): void
    {
        $project = $this->project();
        $tpi = $this->tenant->forIdentityLookup(
            fn () => User::where('email', 'naderi@bazrasi.example')->firstOrFail()
        );

        $this->tenant->runAs($tpi->company_id, function () use ($tpi): void {
            try {
                $this->svc()->createFromTemplate(
                    $this->project(), $tpi,
                    ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
                );
                $this->fail('a TPI must not be able to assign everyone their hold points');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('parties', $e->errors());
            }
        }, $project->id);

        $this->assertSame(0, $this->asOwner(fn () => Itp::count()));
    }

    public function test_the_main_contractor_cannot_assign_party_roles_either(): void
    {
        $project = $this->project();
        $epc = $this->contractor();

        $this->tenant->runAs($epc->company_id, function () use ($epc): void {
            try {
                $this->svc()->createFromTemplate(
                    $this->project(), $epc,
                    ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
                );
                $this->fail('the EPC must not be able to remove the client’s witness');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('parties', $e->errors());
            }
        }, $project->id);
    }

    public function test_the_employer_the_mc_and_the_consultant_may_assign_roles(): void
    {
        $project = $this->project();

        $allowed = [
            'karimi@nioc.example',   // OWN - کارفرما
            'jafari@karan.example',  // MC  - مدیریت پیمان
            'tehrani@pmc.example',   // PMC - مشاور
        ];

        foreach ($allowed as $email) {
            $user = $this->tenant->forIdentityLookup(
                fn () => User::where('email', $email)->firstOrFail()
            );

            $itp = $this->tenant->runAs($user->company_id, fn () => $this->svc()->createFromTemplate(
                $this->project(), $user,
                ItpTemplate::where('code', 'STD-PIPING-MAT-01')->firstOrFail()
            ), $project->id);

            $this->assertNotNull($itp->id, "{$email} must be able to set the party grid");

            // Only one ACTIVE plan per discipline is allowed, and these are
            // drafts, so clear up before the next actor.
            $this->tenant->runAs($user->company_id, fn () => Itp::where('id', $itp->id)->delete(), $project->id);
        }
    }

    public function test_the_rule_is_declared_in_one_place(): void
    {
        // A rule about authority spread across call sites rots. It lives on
        // TenantType, next to the party types it talks about.
        $this->assertSame(
            [TenantType::OWN, TenantType::MC, TenantType::PMC],
            TenantType::MAY_ASSIGN_PARTY_ROLES,
        );

        $this->assertTrue(TenantType::mayAssignPartyRoles(TenantType::OWN));
        $this->assertTrue(TenantType::mayAssignPartyRoles(TenantType::MC));
        $this->assertTrue(TenantType::mayAssignPartyRoles(TenantType::PMC));

        $this->assertFalse(TenantType::mayAssignPartyRoles(TenantType::TPI));
        $this->assertFalse(TenantType::mayAssignPartyRoles(TenantType::EPC));
        $this->assertFalse(TenantType::mayAssignPartyRoles(TenantType::VEN));
        $this->assertFalse(TenantType::mayAssignPartyRoles(null));
    }
}
