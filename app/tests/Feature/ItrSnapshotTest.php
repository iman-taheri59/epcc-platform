<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Joint;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\PipingTestPack;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ComponentService;
use App\Services\InspectionResultService;
use App\Services\ItrCompilerService;
use App\Services\JointService;
use App\Services\PdfRenderer;
use App\Services\PipingLineService;
use App\Services\PipingTestPackService;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ApprovedPmsClassFixture;
use Tests\Support\ResultRecorderPolicyFixture;
use Tests\TestCase;

/**
 * ADR-048 A.5 slice 1 — the ITR compiler: on-demand compilation from the same
 * SQL body the freeze trigger uses, digest-bound frozen snapshots that cannot
 * be client-declared, the explicit completeness report that never hides a gap,
 * member-readable/foreign-writer-refused scoping, and append-only immutability.
 */
final class ItrSnapshotTest extends TestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->tenant = app(TenantContext::class);

        // Default-deny authority (A.4b slice 1): nominate the actor these tests
        // post results as; the compiler's own concerns are scope and freezing.
        ResultRecorderPolicyFixture::ensureAllForUser($this->tenant, $this->project(), $this->owner());
    }

    // ------------------------------------------------------------------
    // fixtures (JointTest / InspectionResultTest discipline)
    // ------------------------------------------------------------------

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    private function owner(): User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    private function buyer(): User
    {
        return $this->userByEmail('rezaei@pars.example');
    }

    private function asOwner(callable $fn): mixed
    {
        $project = $this->project();

        return $this->tenant->runAs($project->owner_company_id, $fn, $project->id);
    }

    private function line(string $size = '6'): PipingLine
    {
        $project = $this->project();
        $owner = $this->owner();

        return $this->asOwner(function () use ($project, $owner, $size) {
            $lines = app(PipingLineService::class);
            $lines->configFor($project)->update(['services' => ['P'], 'coatings' => ['CT1']]);

            $class = PipingClass::firstOrCreate(
                ['project_id' => $project->id, 'code' => 'A1'],
                ['company_id' => $project->owner_company_id, 'description_en' => 'CS 150#'],
            );

            ApprovedPmsClassFixture::ensure($project, $owner, $class);

            return $lines->create(
                Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail(),
                $owner,
                ['nominal_size' => $size, 'service_code' => 'P', 'piping_class_id' => $class->id],
            );
        });
    }

    private function fittedJointOn(PipingLine $line, string $fitCode = 'FIT-207'): Joint
    {
        return $this->asOwner(function () use ($line, $fitCode) {
            $components = app(ComponentService::class);
            $tee = $components->place($line, $this->owner(), 'TEE');
            $branch = $tee->ports->firstWhere('role', 'BRANCH');
            $result = $components->attach($branch, $this->owner(), 'PIPE', ['length_mm' => 1500]);

            return app(JointService::class)->markFitted($result['joint'], $this->owner(), $fitCode);
        });
    }

    private function readyPack(string $code = 'TP-ITR-1', array $lines = []): PipingTestPack
    {
        $pack = $this->asOwner(fn () => app(PipingTestPackService::class)
            ->create($this->project(), $this->owner(), ['code' => $code]));
        foreach ($lines as $line) {
            $this->asOwner(fn () => app(PipingTestPackService::class)->assignLine($line, $this->owner(), $pack));
        }
        $this->asOwner(function () use ($pack) {
            DB::table('piping_test_packs')->where('id', $pack->id)
                ->update(['state' => 'READY', 'ready_at' => now(), 'ready_by' => $this->owner()->id]);

            return $pack->refresh();
        });

        return $pack;
    }

    private function results(): InspectionResultService
    {
        return app(InspectionResultService::class);
    }

    private function itr(): ItrCompilerService
    {
        return app(ItrCompilerService::class);
    }

    private function weld(Joint $joint, array $overrides = []): string
    {
        return $this->results()->recordWeld($this->project(), $this->owner(), $joint->id, array_replace([
            'kind' => 'WELD', 'welded_by_code' => 'W-311', 'welded_at' => '2026-10-28T08:00:00+03:30',
            'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    private function ndt(Joint $joint, array $overrides = []): string
    {
        return $this->results()->recordNdt($this->project(), $this->owner(), $joint->id, array_replace([
            'method' => 'RT', 'result' => 'ACCEPTED', 'performed_by_code' => 'NDT-12',
            'performed_at' => '2026-10-28T10:00:00+03:30', 'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    private function packResult(PipingTestPack $pack, array $overrides = []): string
    {
        return $this->results()->recordTestResult($this->project(), $this->owner(), $pack->id, array_replace([
            'test_type' => 'HYDROSTATIC', 'medium' => 'WATER', 'test_pressure' => '21.750', 'pressure_unit' => 'BAR',
            'hold_duration_minutes' => 120, 'started_at' => '2026-10-28T06:00:00+03:30',
            'completed_at' => '2026-10-28T09:30:00+03:30', 'result' => 'PASS', 'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    // ------------------------------------------------------------------
    // on-demand compilation
    // ------------------------------------------------------------------

    public function test_compile_enumerates_scope_and_reports_missing_items_explicitly(): void
    {
        $lineA = $this->line('6');
        $lineB = $this->line('4');
        $jointA = $this->fittedJointOn($lineA, 'FIT-101');
        $jointB = $this->fittedJointOn($lineB, 'FIT-102');
        $pack = $this->readyPack('TP-ITR-1', [$lineA, $lineB]);

        $this->weld($jointA);
        $this->ndt($jointA);

        $c = $this->itr()->compile($this->project(), $this->owner(), $pack->id, 'en');
        $d = $c['dossier'];
        $m = $c['completeness'];

        $this->assertSame('ITR_COMPILATION_NOT_A_CERTIFICATE', $d['kind']);
        $this->assertSame('TP-ITR-1', $d['pack']['code']);
        $this->assertSame('READY', $d['pack']['state']);
        $this->assertCount(2, $d['lines']);
        $this->assertCount(2, $d['joints']);

        // Joint A carries its weld + NDT; joint B is honestly empty.
        $byJoint = collect($d['joints'])->keyBy('joint_id');
        $this->assertCount(1, $byJoint[$jointA->id]['welds']);
        $this->assertCount(1, $byJoint[$jointA->id]['ndt']);
        $this->assertCount(0, $byJoint[$jointB->id]['welds']);

        // The completeness report enumerates every gap — never silently omitted.
        $this->assertFalse($m['is_complete']);
        $this->assertSame(2, $m['counts']['lines']);
        $this->assertSame(2, $m['counts']['joints']);
        $this->assertSame(1, $m['counts']['welds']);
        $this->assertSame(1, $m['counts']['ndt']);
        $this->assertCount(2, $m['missing']['lines_not_released']);
        $this->assertSame([], $m['missing']['joints_not_fitted']);
        // jsonb objects have no key order — compare by value, not by memory layout.
        $this->assertEquals([['line_number' => $lineB->line_number, 'joint_seq' => $jointB->joint_seq]],
            $m['missing']['joints_without_weld_record']);
        $this->assertEquals([['line_number' => $lineB->line_number, 'joint_seq' => $jointB->joint_seq]],
            $m['missing']['joints_without_ndt']);
        $this->assertSame([], $m['missing']['joints_with_open_rejection']);
        // The pack with no PASS is NAMED, not flagged: a subsystem dossier has
        // more than one pack, and "something is missing" is not a work list.
        $this->assertEquals([['pack_code' => 'TP-ITR-1']], $m['missing']['packs_without_passed_result']);
    }

    public function test_open_rejection_is_enumerated_until_the_lineage_clears_it(): void
    {
        $line = $this->line();
        $joint = $this->fittedJointOn($line);
        $pack = $this->readyPack('TP-ITR-2', [$line]);
        $this->weld($joint);
        $rejected = $this->ndt($joint, ['result' => 'REJECTED', 'reject_reason' => 'Porosity cluster at 3 o-clock']);

        $m = $this->itr()->compile($this->project(), $this->owner(), $pack->id, 'en')['completeness'];
        $this->assertCount(1, $m['missing']['joints_with_open_rejection']);
        $this->assertSame($line->line_number, $m['missing']['joints_with_open_rejection'][0]['line_number']);

        // Repair + re-examination over the rejected result clears the gap.
        $this->weld($joint, ['kind' => 'REPAIR_WELD', 'reason' => 'Ground out and rewelded per WPS-14']);
        $this->ndt($joint, ['previous_result_id' => $rejected]);

        $c = $this->itr()->compile($this->project(), $this->owner(), $pack->id, 'en');
        $this->assertSame([], $c['completeness']['missing']['joints_with_open_rejection']);
        $this->assertSame(2, $c['completeness']['counts']['welds']);
        $this->assertSame(2, $c['completeness']['counts']['ndt']);
        // The rejected examination is still visible in the dossier — history is never pruned.
        $dossierJoint = collect($c['dossier']['joints'])->firstWhere('joint_id', $joint->id);
        $this->assertSame('REJECTED', $dossierJoint['ndt'][0]['result']);
        $this->assertSame('Porosity cluster at 3 o-clock', $dossierJoint['ndt'][0]['reject_reason']);
    }

    public function test_passed_and_failed_results_are_both_compiled_with_their_honesty_fields(): void
    {
        $line = $this->line();
        $this->fittedJointOn($line);
        $pack = $this->readyPack('TP-ITR-3', [$line]);

        $this->packResult($pack, ['result' => 'FAIL', 'failure_reason' => 'Pressure drop at flange F-12']);
        $m = $this->itr()->compile($this->project(), $this->owner(), $pack->id, 'en')['completeness'];
        $this->assertEquals([['pack_code' => 'TP-ITR-3']], $m['missing']['packs_without_passed_result'],
            'a FAIL is not a PASS');

        $this->packResult($pack);
        $c = $this->itr()->compile($this->project(), $this->owner(), $pack->id, 'en');
        $this->assertSame([], $c['completeness']['missing']['packs_without_passed_result']);
        $this->assertCount(2, $c['dossier']['test_results']);
        $this->assertSame('Pressure drop at flange F-12', $c['dossier']['test_results'][0]['failure_reason']);
        // numeric -> jsonb normalizes the trailing zero: 21.750 is stored as 21.75.
        $this->assertSame('21.75', (string) $c['dossier']['test_results'][1]['test_pressure']);
    }

    // ------------------------------------------------------------------
    // frozen snapshots
    // ------------------------------------------------------------------

    public function test_freeze_refuses_draft_packs_and_stores_a_trigger_derived_digest_bound_dossier(): void
    {
        $line = $this->line();
        $joint = $this->fittedJointOn($line);
        $draft = $this->asOwner(fn () => app(PipingTestPackService::class)
            ->create($this->project(), $this->owner(), ['code' => 'TP-ITR-DRAFT']));
        $this->asOwner(fn () => app(PipingTestPackService::class)->assignLine($line, $this->owner(), $draft));

        try {
            $this->itr()->freeze($this->project(), $this->owner(), $draft->id, (string) Str::uuid(), 'en');
            $this->fail('Expected premature-freeze refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('READY', $e->errors()['itr'][0]);
        }

        $this->asOwner(function () use ($draft) {
            DB::table('piping_test_packs')->where('id', $draft->id)
                ->update(['state' => 'READY', 'ready_at' => now(), 'ready_by' => $this->owner()->id]);
        });
        $this->weld($joint);
        $this->ndt($joint);

        $frozen = $this->itr()->freeze($this->project(), $this->owner(), $draft->id, (string) Str::uuid(), 'en');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $frozen['digest']);

        // The stored dossier is the trigger-derived compilation, not the '{}' placeholder.
        $row = $this->asOwner(fn () => DB::table('itr_snapshots')->where('id', $frozen['id'])->first());
        $dossier = json_decode((string) $row->dossier, true);
        $this->assertSame('ITR_COMPILATION_NOT_A_CERTIFICATE', $dossier['kind']);
        $this->assertSame('TP-ITR-DRAFT', $dossier['pack']['code']);
        $this->assertSame('FROZEN', $row->state);
        $this->assertSame('TEST_PACK', $row->scope_kind);

        // Digest binds exactly the stored dossier bytes (recomputed in SQL).
        $ok = $this->asOwner(fn () => DB::selectOne(
            "SELECT (digest = encode(sha256(convert_to(dossier::text,'UTF8')),'hex')) AS ok FROM itr_snapshots WHERE id = ?",
            [$frozen['id']])->ok);
        $this->assertTrue((bool) $ok);

        // The reader re-verifies too.
        $snap = $this->itr()->snapshot($this->project(), $this->owner(), $frozen['id']);
        $this->assertNotNull($snap);
        $this->assertTrue($snap['digest_valid']);
    }

    public function test_snapshots_are_append_only(): void
    {
        $line = $this->line();
        $this->fittedJointOn($line);
        $pack = $this->readyPack('TP-ITR-4', [$line]);
        $frozen = $this->itr()->freeze($this->project(), $this->owner(), $pack->id, (string) Str::uuid(), 'en');

        $updated = $this->asOwner(fn () => DB::table('itr_snapshots')->where('id', $frozen['id'])->update(['state' => 'TAMPERED']));
        $this->assertSame(0, $updated);
        $deleted = $this->asOwner(fn () => DB::table('itr_snapshots')->where('id', $frozen['id'])->delete());
        $this->assertSame(0, $deleted);
        $this->assertCount(1, $this->itr()->snapshotsForPack($this->project(), $this->owner(), $pack->id));
    }

    public function test_snapshots_are_member_readable_but_foreign_actors_cannot_freeze(): void
    {
        $line = $this->line();
        $this->fittedJointOn($line);
        $pack = $this->readyPack('TP-ITR-5', [$line]);
        $frozen = $this->itr()->freeze($this->project(), $this->owner(), $pack->id, (string) Str::uuid(), 'en');

        // Another project-member company reads the same handover evidence.
        $snap = $this->itr()->snapshot($this->project(), $this->buyer(), $frozen['id']);
        $this->assertNotNull($snap);
        $this->assertTrue($snap['digest_valid']);
        $this->assertCount(1, $this->itr()->snapshotsForPack($this->project(), $this->buyer(), $pack->id));

        // But it can never freeze a snapshot naming another company's actor.
        try {
            $this->tenant->runAs($this->buyer()->company_id, function () use ($pack) {
                DB::transaction(function () use ($pack) {
                    DB::select('SELECT set_config(\'epcc.itr_actor_id\',?,true)', [$this->buyer()->id]);
                    DB::table('itr_snapshots')->insert([
                        'id' => (string) Str::uuid(), 'project_id' => $this->project()->id,
                        'company_id' => $this->buyer()->company_id, 'scope_kind' => 'TEST_PACK',
                        'test_pack_id' => $pack->id, 'dossier' => '{}', 'completeness' => '{}',
                        'digest' => str_repeat('0', 64), 'state' => 'FROZEN',
                        'compiled_by' => $this->owner()->id, 'request_id' => (string) Str::uuid(),
                        'compiled_at' => now(),
                    ]);
                });
            }, $this->project()->id);
            $this->fail('Expected foreign-actor refusal');
        } catch (QueryException $e) {
            $this->assertSame('42501', (string) $e->getCode());
        }
    }

    public function test_duplicate_request_ids_are_refused(): void
    {
        $line = $this->line();
        $this->fittedJointOn($line);
        $pack = $this->readyPack('TP-ITR-6', [$line]);
        $rid = (string) Str::uuid();
        $this->itr()->freeze($this->project(), $this->owner(), $pack->id, $rid, 'en');
        try {
            $this->itr()->freeze($this->project(), $this->owner(), $pack->id, $rid, 'en');
            $this->fail('Expected duplicate refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->errors()['itr'][0]));
        }
        $this->assertCount(1, $this->itr()->snapshotsForPack($this->project(), $this->owner(), $pack->id));
    }

    public function test_a_snapshot_freezes_its_moment_later_results_do_not_rewrite_it(): void
    {
        $line = $this->line();
        $joint = $this->fittedJointOn($line);
        $pack = $this->readyPack('TP-ITR-7', [$line]);
        $this->weld($joint);

        $frozen = $this->itr()->freeze($this->project(), $this->owner(), $pack->id, (string) Str::uuid(), 'en');

        // New evidence lands AFTER the freeze.
        $this->ndt($joint);
        $this->packResult($pack);

        $snap = $this->itr()->snapshot($this->project(), $this->owner(), $frozen['id']);
        $dossier = json_decode((string) $snap['row']->dossier, true);
        $this->assertSame(0, count($dossier['joints'][0]['ndt']), 'the frozen moment had no NDT yet');
        $this->assertSame(0, count($dossier['test_results']), 'the frozen moment had no pack result yet');
        $this->assertTrue($snap['digest_valid']);

        // A fresh compilation sees the new evidence; a new freeze binds it.
        $live = $this->itr()->compile($this->project(), $this->owner(), $pack->id, 'en');
        $this->assertSame(1, count($live['dossier']['joints'][0]['ndt']));
        $second = $this->itr()->freeze($this->project(), $this->owner(), $pack->id, (string) Str::uuid(), 'en');
        $this->assertNotSame($frozen['digest'], $second['digest'], 'different evidence, different digest');
        $this->assertCount(2, $this->itr()->snapshotsForPack($this->project(), $this->owner(), $pack->id));
    }

    public function test_unknown_or_foreign_packs_are_refused_without_leaking(): void
    {
        $line = $this->line();
        $this->fittedJointOn($line);
        $pack = $this->readyPack('TP-ITR-8', [$line]);
        $this->itr()->freeze($this->project(), $this->owner(), $pack->id, (string) Str::uuid(), 'en');

        try {
            $this->itr()->compile($this->project(), $this->owner(), (string) Str::uuid(), 'en');
            $this->fail('Expected not-found refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not found', strtolower($e->errors()['test_pack_id'][0]));
        }
        $this->assertNull($this->itr()->snapshot($this->project(), $this->owner(), (string) Str::uuid()));
        $this->assertNull($this->itr()->renderPdf($this->project(), $this->owner(), (string) Str::uuid(), 'en'));
    }

    public function test_render_pdf_is_optional_data_first_bytes_when_a_renderer_exists(): void
    {
        $line = $this->line();
        $joint = $this->fittedJointOn($line);
        $pack = $this->readyPack('TP-ITR-9', [$line]);
        $this->weld($joint);
        $this->ndt($joint);
        $frozen = $this->itr()->freeze($this->project(), $this->owner(), $pack->id, (string) Str::uuid(), 'en');

        if (app(PdfRenderer::class)->isAvailable()) {
            $bytes = $this->itr()->renderPdf($this->project(), $this->owner(), $frozen['id'], 'fa');
            $this->assertNotNull($bytes);
            $this->assertSame('%PDF-', substr((string) $bytes, 0, 5));
        } else {
            // The house contract: the snapshot is data; bytes need a browser.
            $this->assertNull($this->itr()->renderPdf($this->project(), $this->owner(), $frozen['id'], 'fa'));
        }

        // The blade view itself always renders to HTML, browser or not.
        $html = view('print.itr-dossier', [
            'lang' => 'fa',
            'dossier' => $this->itr()->compile($this->project(), $this->owner(), $pack->id, 'fa')['dossier'],
            'completeness' => $this->itr()->compile($this->project(), $this->owner(), $pack->id, 'fa')['completeness'],
            'digest' => $frozen['digest'],
            'compiledAt' => now()->toIso8601String(),
        ])->render();
        $this->assertStringContainsString('ITR', $html);
        $this->assertStringContainsString('TP-ITR-9', $html);
        $this->assertStringContainsString($frozen['digest'], $html);
    }
}
