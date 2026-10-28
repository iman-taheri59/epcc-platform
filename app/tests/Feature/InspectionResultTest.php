<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Joint;
use App\Models\PipingClass;
use App\Models\PipingLine;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\User;
use App\Services\ComponentService;
use App\Services\InspectionResultService;
use App\Services\JointService;
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
use Tests\TestCase;

/**
 * ADR-048 slice 1 — inspection/test result records: weld + NDT per fitted
 * joint with honest ordering and re-examination lineage, hydro results per
 * ready test pack with retest lineage, the MTR registry bound to exact heats,
 * the two provenance modes, member-readable/foreign-writer-refused scoping,
 * and total append-only immutability.
 */
final class InspectionResultTest extends TestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->tenant = app(TenantContext::class);
    }

    // ------------------------------------------------------------------
    // fixtures (JointTest discipline)
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

    /** A numbered, FITTED weld joint — the only state results may bind to. */
    private function fittedJoint(): Joint
    {
        $line = $this->line();

        return $this->asOwner(function () use ($line) {
            $components = app(ComponentService::class);
            $tee = $components->place($line, $this->owner(), 'TEE');
            $branch = $tee->ports->firstWhere('role', 'BRANCH');
            $result = $components->attach($branch, $this->owner(), 'PIPE', ['length_mm' => 1500]);

            return app(JointService::class)->markFitted($result['joint'], $this->owner(), 'FIT-207');
        });
    }

    /** A MODELLED (unfitted) joint for the honest-ordering refusals. */
    private function modelledJoint(): Joint
    {
        $line = $this->line();

        return $this->asOwner(function () use ($line) {
            $components = app(ComponentService::class);
            $tee = $components->place($line, $this->owner(), 'TEE');
            $branch = $tee->ports->firstWhere('role', 'BRANCH');

            return $components->attach($branch, $this->owner(), 'PIPE', ['length_mm' => 1500])['joint'];
        });
    }

    private function draftPack(string $code = 'TP-RES-1'): \App\Models\PipingTestPack
    {
        return $this->asOwner(fn () => app(PipingTestPackService::class)->create($this->project(), $this->owner(), ['code' => $code]));
    }

    /** Fixture shortcut: the READY transition itself is PipingTestPackService's tested job. */
    private function readyPack(string $code = 'TP-RES-1'): \App\Models\PipingTestPack
    {
        $pack = $this->draftPack($code);
        $this->asOwner(function () use ($pack) {
            DB::table('piping_test_packs')->where('id', $pack->id)
                ->update(['state' => 'READY', 'ready_at' => now(), 'ready_by' => $this->owner()->id]);

            return $pack->refresh();
        });

        return $pack;
    }

    // ------------------------------------------------------------------
    // result helpers
    // ------------------------------------------------------------------

    private function results(): InspectionResultService
    {
        return app(InspectionResultService::class);
    }

    private function weld(Joint $joint, array $overrides = [], ?User $actor = null): string
    {
        return $this->results()->recordWeld($this->project(), $actor ?? $this->owner(), $joint->id, array_replace([
            'kind' => 'WELD', 'welded_by_code' => 'W-311', 'welded_at' => '2026-10-28T08:00:00+03:30',
            'weld_process' => 'GTAW', 'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    private function ndt(Joint $joint, array $overrides = [], ?User $actor = null): string
    {
        return $this->results()->recordNdt($this->project(), $actor ?? $this->owner(), $joint->id, array_replace([
            'method' => 'RT', 'result' => 'ACCEPTED', 'performed_by_code' => 'NDT-12',
            'performed_at' => '2026-10-28T10:00:00+03:30', 'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    private function packResult(\App\Models\PipingTestPack $pack, array $overrides = [], ?User $actor = null): string
    {
        return $this->results()->recordTestResult($this->project(), $actor ?? $this->owner(), $pack->id, array_replace([
            'test_type' => 'HYDROSTATIC', 'medium' => 'WATER', 'test_pressure' => '21.750', 'pressure_unit' => 'BAR',
            'hold_duration_minutes' => 120, 'started_at' => '2026-10-28T06:00:00+03:30',
            'completed_at' => '2026-10-28T09:30:00+03:30', 'result' => 'PASS', 'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    private function mtr(array $overrides = [], ?User $actor = null): string
    {
        return $this->results()->registerCertificate($this->project(), $actor ?? $this->owner(), array_replace([
            'heat_ref' => 'HEAT-77', 'certificate_type' => 'EN10204_3_1', 'certificate_ref' => 'MTR-2026-001',
            'issuer_name' => 'Isfahan Steel Mill', 'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    // ------------------------------------------------------------------
    // welds
    // ------------------------------------------------------------------

    public function test_weld_requires_fitted_joint_and_freezes_stamp_identity(): void
    {
        $modelled = $this->modelledJoint();
        try {
            $this->weld($modelled);
            $this->fail('Expected unfit refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('FITTED', $e->errors()['result'][0]);
        }

        $joint = $this->fittedJoint();
        $this->assertSame(Joint::STATE_FITTED, $joint->state);
        $id = $this->weld($joint);

        $row = $this->asOwner(fn () => DB::table('joint_weld_records')->where('id', $id)->first());
        $this->assertSame('WELD', $row->kind);
        $this->assertSame('W-311', $row->welded_by_code);
        $this->assertSame('GTAW', $row->weld_process);
        $this->assertSame('IN_SYSTEM', $row->mode);
        $this->assertSame($this->owner()->id, $row->recorded_by);
        $this->assertNull($row->performer_org);
    }

    public function test_repair_weld_requires_reason_and_result_tables_are_append_only(): void
    {
        $joint = $this->fittedJoint();
        $this->weld($joint);

        try {
            $this->weld($joint, ['kind' => 'REPAIR_WELD']);
            $this->fail('Expected reason requirement');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reason', $e->errors());
        }
        $this->weld($joint, ['kind' => 'REPAIR_WELD', 'reason' => 'Undercut removed and rewelded per WPS-14']);
        $this->ndt($joint);
        $pack = $this->readyPack();
        $this->packResult($pack);
        $this->mtr();

        // All four families refuse mutation and deletion: corrections are new records.
        foreach ([
            ['joint_weld_records', 'kind'], ['joint_ndt_results', 'result'],
            ['test_pack_results', 'result'], ['material_certificates', 'issuer_name'],
        ] as [$table, $column]) {
            $updated = $this->asOwner(fn () => DB::table($table)->where('project_id', $this->project()->id)->update([$column => 'TAMPERED']));
            $this->assertSame(0, $updated, "update on {$table}");
            $deleted = $this->asOwner(fn () => DB::table($table)->where('project_id', $this->project()->id)->delete());
            $this->assertSame(0, $deleted, "delete on {$table}");
        }
        $this->assertCount(2, $this->asOwner(fn () => DB::table('joint_weld_records')->get()));
    }

    // ------------------------------------------------------------------
    // NDT
    // ------------------------------------------------------------------

    public function test_ndt_requires_prior_weld_and_honest_reject_reason(): void
    {
        $joint = $this->fittedJoint();
        try {
            $this->ndt($joint);
            $this->fail('Expected weld-first refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('weld', strtolower($e->errors()['result'][0]));
        }
        $this->weld($joint);

        try {
            $this->ndt($joint, ['result' => 'REJECTED']);
            $this->fail('Expected reject-reason requirement');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reject_reason', $e->errors());
        }
        try {
            $this->ndt($joint, ['result' => 'ACCEPTED', 'reject_reason' => 'leftover text']);
            $this->fail('Accepted results carry no defect text');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('reject_reason', $e->errors());
        }
        try {
            $this->ndt($joint, ['method' => 'XX']);
            $this->fail('Expected method whitelist');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('method', $e->errors());
        }

        $rejected = $this->ndt($joint, ['result' => 'REJECTED', 'reject_reason' => 'Linear indication at 2 o-clock, porosity cluster']);
        $this->assertNotEmpty($rejected);
    }

    public function test_reexamination_must_follow_a_rejected_result_of_same_joint_and_method(): void
    {
        $joint = $this->fittedJoint();
        $this->weld($joint);
        $accepted = $this->ndt($joint);
        $rejected = $this->ndt($joint, ['method' => 'PT', 'result' => 'REJECTED', 'reject_reason' => 'Surface crack indication']);

        // Retesting over an ACCEPTED result is not a re-examination.
        try {
            $this->ndt($joint, ['previous_result_id' => $accepted]);
            $this->fail('Expected lineage refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('previous_result_id', $e->errors());
        }
        // Crossing methods is not the same examination either.
        try {
            $this->ndt($joint, ['method' => 'MT', 'previous_result_id' => $rejected]);
            $this->fail('Expected method-lineage refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('previous_result_id', $e->errors());
        }
        // Repair + re-examination of the same method over the rejected result.
        $this->weld($joint, ['kind' => 'REPAIR_WELD', 'reason' => 'Ground out and rewelded']);
        $reexam = $this->ndt($joint, ['method' => 'PT', 'previous_result_id' => $rejected]);
        $row = $this->asOwner(fn () => DB::table('joint_ndt_results')->where('id', $reexam)->first());
        $this->assertSame($rejected, $row->previous_result_id);
        $this->assertSame('ACCEPTED', $row->result);
    }

    public function test_external_register_mode_demands_performer_identity_and_internal_refuses_it(): void
    {
        $joint = $this->fittedJoint();
        $this->weld($joint);

        foreach ([
            ['mode' => 'EXTERNAL_REGISTER'],
            ['mode' => 'EXTERNAL_REGISTER', 'performer_org' => 'SGS NDT'],
            ['mode' => 'EXTERNAL_REGISTER', 'performer_org' => 'SGS NDT', 'performer_name' => 'A. Naderi'],
        ] as $partial) {
            try {
                $this->ndt($joint, $partial);
                $this->fail('Expected external-identity refusal for '.json_encode($partial));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        try {
            $this->ndt($joint, ['performer_org' => 'SGS NDT']);
            $this->fail('Internal mode must not smuggle external fields');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('mode', $e->errors());
        }

        $external = $this->ndt($joint, ['mode' => 'EXTERNAL_REGISTER', 'performer_org' => 'SGS NDT',
            'performer_name' => 'A. Naderi', 'report_ref' => 'RT-2026-4417', 'result' => 'REJECTED',
            'reject_reason' => 'Slag inclusion, report attached externally']);
        $row = $this->asOwner(fn () => DB::table('joint_ndt_results')->where('id', $external)->first());
        $this->assertSame('EXTERNAL_REGISTER', $row->mode);
        $this->assertSame('SGS NDT', $row->performer_org);
        $this->assertSame('RT-2026-4417', $row->report_ref);
    }

    public function test_results_are_member_readable_but_foreign_actors_cannot_write(): void
    {
        $joint = $this->fittedJoint();
        $this->weld($joint);
        $this->ndt($joint);

        // A different project-member company reads the same quality evidence.
        $history = $this->results()->jointHistory($this->project(), $this->buyer(), $joint->id);
        $this->assertCount(1, $history['welds']);
        $this->assertCount(1, $history['ndt']);

        // But it can never write a record naming another company's actor:
        // a buyer-company session claiming the owner as recorder is refused.
        try {
            $this->tenant->runAs($this->buyer()->company_id, function () use ($joint) {
                DB::transaction(function () use ($joint) {
                    DB::select('SELECT set_config(\'epcc.result_actor_id\',?,true)', [$this->buyer()->id]);
                    DB::table('joint_weld_records')->insert([
                        'id' => (string) Str::uuid(), 'project_id' => $this->project()->id,
                        'company_id' => $this->buyer()->company_id, 'joint_id' => $joint->id, 'kind' => 'WELD',
                        'welded_by_code' => 'W-999', 'welded_at' => now(), 'mode' => 'IN_SYSTEM',
                        'recorded_by' => $this->owner()->id, 'request_id' => (string) Str::uuid(), 'recorded_at' => now(),
                    ]);
                });
            }, $this->project()->id);
            $this->fail('Expected foreign-actor refusal');
        } catch (QueryException $e) {
            $this->assertSame('42501', (string) $e->getCode());
        }
    }

    // ------------------------------------------------------------------
    // test packs
    // ------------------------------------------------------------------

    public function test_pack_result_refuses_draft_pack_and_bad_time_window(): void
    {
        $draft = $this->draftPack();
        try {
            $this->packResult($draft);
            $this->fail('Expected not-ready refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('READY', $e->errors()['result'][0]);
        }

        $pack = $this->readyPack('TP-RES-2');
        try {
            $this->packResult($pack, ['completed_at' => '2026-10-28T05:00:00+03:30']);
            $this->fail('Expected time-window refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('completed_at', $e->errors());
        }
        try {
            $this->packResult($pack, ['test_pressure' => '0']);
            $this->fail('Expected positive-pressure requirement');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('test_pressure', $e->errors());
        }

        $id = $this->packResult($pack);
        $row = $this->asOwner(fn () => DB::table('test_pack_results')->where('id', $id)->first());
        $this->assertSame('PASS', $row->result);
        $this->assertSame('21.750', (string) $row->test_pressure);
        $this->assertSame(120, (int) $row->hold_duration_minutes);
    }

    public function test_retest_lineage_requires_a_failed_previous_result(): void
    {
        $pack = $this->readyPack();
        $passed = $this->packResult($pack);
        try {
            $this->packResult($pack, ['previous_result_id' => $passed]);
            $this->fail('Expected failed-lineage refusal');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('previous_result_id', $e->errors());
        }

        try {
            $this->packResult($pack, ['result' => 'FAIL']);
            $this->fail('Expected failure-cause requirement');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('failure_reason', $e->errors());
        }
        $failed = $this->packResult($pack, ['result' => 'FAIL', 'failure_reason' => 'Pressure drop 0.4 bar over 120 min at flange F-12']);
        $retest = $this->packResult($pack, ['previous_result_id' => $failed, 'started_at' => '2026-10-29T06:00:00+03:30', 'completed_at' => '2026-10-29T09:30:00+03:30']);

        $history = $this->results()->packResults($this->project(), $this->owner(), $pack->id);
        $this->assertCount(3, $history);
        $this->assertSame($failed, $history->last()->previous_result_id);
        $this->assertSame('PASS', $history->last()->result);
    }

    // ------------------------------------------------------------------
    // material certificates
    // ------------------------------------------------------------------

    public function test_mtr_requires_heat_issuer_visible_document_and_keeps_heats_separate(): void
    {
        foreach ([
            ['heat_ref' => '  '],
            ['issuer_name' => ''],
            ['certificate_ref' => ''],
            ['certificate_type' => 'BOGUS'],
        ] as $bad) {
            try {
                $this->mtr($bad);
                $this->fail('Expected MTR refusal for '.json_encode($bad));
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        try {
            $this->mtr(['document_id' => (string) Str::uuid()]);
            $this->fail('Expected dangling-document refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('document', strtolower($e->errors()['result'][0]));
        }

        $this->mtr();
        $this->mtr(['heat_ref' => 'HEAT-771', 'certificate_ref' => 'MTR-2026-002', 'lot_ref' => 'LOT-9']);
        $this->assertCount(1, $this->results()->certificatesForHeat($this->project(), $this->owner(), 'HEAT-77'));
        $this->assertCount(1, $this->results()->certificatesForHeat($this->project(), $this->owner(), 'HEAT-771'));
        $this->assertCount(0, $this->results()->certificatesForHeat($this->project(), $this->owner(), 'HEAT-7'));
    }

    public function test_duplicate_request_ids_are_refused_everywhere(): void
    {
        $joint = $this->fittedJoint();
        $pack = $this->readyPack();
        $rid = (string) Str::uuid();
        $this->weld($joint, ['request_id' => $rid]);
        try {
            $this->weld($joint, ['request_id' => $rid]);
            $this->fail('Expected duplicate weld refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->errors()['result'][0]));
        }
        $rid2 = (string) Str::uuid();
        $this->mtr(['request_id' => $rid2]);
        try {
            $this->mtr(['request_id' => $rid2]);
            $this->fail('Expected duplicate MTR refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->errors()['result'][0]));
        }
        $rid3 = (string) Str::uuid();
        $this->packResult($pack, ['request_id' => $rid3]);
        try {
            $this->packResult($pack, ['request_id' => $rid3]);
            $this->fail('Expected duplicate pack-result refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('duplicate', strtolower($e->errors()['result'][0]));
        }
    }
}
