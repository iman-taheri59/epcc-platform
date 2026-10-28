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
use Tests\Support\ResultRecorderPolicyFixture;
use Tests\TestCase;

/**
 * ADR-048 A.4b slice 1 — two enforcement gaps that an application check cannot
 * close, because anyone holding a psql prompt can walk around a service class:
 *
 *   1. Recording quality evidence is DEFAULT DENY. Being a project member is
 *      not authority to create the inspection record a client will read; a
 *      nomination naming a person or a role is, and it is stamped on the row.
 *   2. A test pack cannot claim TESTED without a recorded result, nor ACCEPTED
 *      without a PASS. Claims about physical reality are held to the evidence.
 */
final class ResultAuthorityAndGatingTest extends TestCase
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
    // cast: karimi = employer rep, rezaei = EPC project manager,
    //       sadeghi = EPC QC inspector, tehrani = PMC consultant,
    //       mousavi = Sahand site engineer scoped to CIVIL.
    // ------------------------------------------------------------------

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    private function owner(): User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    private function pm(): User
    {
        return $this->userByEmail('rezaei@pars.example');
    }

    private function qc(): User
    {
        return $this->userByEmail('sadeghi@pars.example');
    }

    private function consultant(): User
    {
        return $this->userByEmail('tehrani@pmc.example');
    }

    private function civilEngineer(): User
    {
        return $this->userByEmail('mousavi@sahand.example');
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

    private function readyPack(string $code = 'TP-AUTH-1'): PipingTestPack
    {
        $pack = $this->asOwner(fn () => app(PipingTestPackService::class)
            ->create($this->project(), $this->owner(), ['code' => $code]));
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

    private function weldBy(User $actor, Joint $joint, array $overrides = []): string
    {
        return $this->results()->recordWeld($this->project(), $actor, $joint->id, array_replace([
            'kind' => 'WELD', 'welded_by_code' => 'W-311', 'welded_at' => '2026-10-28T08:00:00+03:30',
            'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    private function packResultBy(User $actor, PipingTestPack $pack, array $overrides = []): string
    {
        return $this->results()->recordTestResult($this->project(), $actor, $pack->id, array_replace([
            'test_type' => 'HYDROSTATIC', 'medium' => 'WATER', 'test_pressure' => '21.750', 'pressure_unit' => 'BAR',
            'hold_duration_minutes' => 120, 'started_at' => '2026-10-28T06:00:00+03:30',
            'completed_at' => '2026-10-28T09:30:00+03:30', 'result' => 'PASS', 'request_id' => (string) Str::uuid(),
        ], $overrides), 'en');
    }

    private function weldCount(): int
    {
        return (int) $this->asOwner(fn () => DB::table('joint_weld_records')->count());
    }

    // ------------------------------------------------------------------
    // 1. nominated authority
    // ------------------------------------------------------------------

    public function test_recording_evidence_is_default_deny_without_a_nomination(): void
    {
        $joint = $this->fittedJoint();

        // sadeghi IS the QC inspector, and still cannot record until nominated.
        try {
            $this->weldBy($this->qc(), $joint);
            $this->fail('Expected default-deny refusal');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not nominated', $e->errors()['result'][0]);
        }
        $this->assertSame(0, $this->weldCount(), 'nothing was written');
    }

    public function test_a_role_nomination_authorises_the_holder_and_is_stamped_on_the_row(): void
    {
        $joint = $this->fittedJoint();
        $policyId = ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->pm(), $this->qc()->company_id,
            'record_weld', ['role:'.$this->qc()->company_id.':qc_inspector'],
        );

        $weldId = $this->weldBy($this->qc(), $joint);

        $this->assertSame(1, $this->weldCount());
        $stamped = $this->asOwner(fn () => DB::table('joint_weld_records')->where('id', $weldId)->value('recorder_policy_id'));
        $this->assertSame($policyId, (string) $stamped, 'the authorising nomination is auditable on the row');
    }

    public function test_a_role_outside_the_nomination_is_still_refused(): void
    {
        $joint = $this->fittedJoint();
        ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->pm(), $this->pm()->company_id,
            'record_weld', ['role:'.$this->pm()->company_id.':qc_inspector'],
        );

        // The PM nominated the QC role, not himself.
        try {
            $this->weldBy($this->pm(), $joint);
            $this->fail('Expected refusal for an un-nominated role');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not nominated', $e->errors()['result'][0]);
        }
        $this->assertSame(0, $this->weldCount());
    }

    public function test_suspending_a_nomination_revokes_the_authority(): void
    {
        $joint = $this->fittedJoint();
        ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->pm(), $this->qc()->company_id,
            'record_weld', ['role:'.$this->qc()->company_id.':qc_inspector'],
        );
        $this->weldBy($this->qc(), $joint);

        // Version 2 suspends the nomination: the reason is recorded, the
        // selector set is emptied, and the QC can no longer post.
        ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->pm(), $this->qc()->company_id,
            'record_weld', [], suspended: true,
        );

        $second = $this->fittedJoint();
        try {
            $this->weldBy($this->qc(), $second);
            $this->fail('Expected refusal after suspension');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not nominated', $e->errors()['result'][0]);
        }
        $this->assertSame(1, $this->weldCount(), 'the earlier, properly authorised record stands');
    }

    public function test_a_user_nomination_covers_exactly_that_person(): void
    {
        $joint = $this->fittedJoint();
        ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->pm(), $this->pm()->company_id,
            'record_weld', ['user:'.$this->pm()->id],
        );

        $this->weldBy($this->pm(), $joint);
        $this->assertSame(1, $this->weldCount());

        // Same company, same project, not named.
        try {
            $this->weldBy($this->qc(), $joint);
            $this->fail('Expected refusal for a colleague not named');
        } catch (ValidationException) {
            // expected
        }
        $this->assertSame(1, $this->weldCount());
    }

    public function test_discipline_scope_is_honoured_a_civil_engineer_cannot_sign_a_weld(): void
    {
        $joint = $this->fittedJoint();
        // Nominate the site_engineer role of Sahand; mousavi holds it but is
        // scoped to CIVIL, so piping evidence is outside his authority.
        ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->owner(), $this->civilEngineer()->company_id,
            'record_weld', ['role:'.$this->civilEngineer()->company_id.':site_engineer'],
        );

        try {
            $this->weldBy($this->civilEngineer(), $joint);
            $this->fail('Expected refusal for a CIVIL-scoped engineer');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not nominated', $e->errors()['result'][0]);
        }
        $this->assertSame(0, $this->weldCount());
    }

    public function test_an_independent_examiner_records_under_its_own_nomination(): void
    {
        $joint = $this->fittedJoint();
        // The EPC records its own weld (its own policy), the PMC consultant
        // records the independent examination under a separate nomination.
        ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->pm(), $this->qc()->company_id,
            'record_weld', ['role:'.$this->qc()->company_id.':qc_inspector'],
        );
        ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->consultant(), $this->consultant()->company_id,
            'record_ndt', ['role:'.$this->consultant()->company_id.':consultant'],
        );
        $this->weldBy($this->qc(), $joint);

        $ndtId = $this->results()->recordNdt($this->project(), $this->consultant(), $joint->id, [
            'method' => 'RT', 'result' => 'ACCEPTED', 'performed_by_code' => 'TPI-07',
            'performed_at' => '2026-10-28T12:00:00+03:30', 'request_id' => (string) Str::uuid(),
        ], 'en');

        $row = $this->asOwner(fn () => DB::table('joint_ndt_results')->where('id', $ndtId)->first());
        $this->assertNotNull($row);
        $this->assertSame($this->consultant()->company_id, (string) $row->company_id);
        $this->assertNotNull($row->recorder_policy_id);
    }

    // ------------------------------------------------------------------
    // 2. evidence-gated pack transitions
    // ------------------------------------------------------------------

    public function test_a_pack_cannot_be_tested_without_a_recorded_result(): void
    {
        $pack = $this->readyPack('TP-AUTH-2');

        try {
            $this->asOwner(fn () => app(PipingTestPackService::class)
                ->transition($pack, $this->owner(), PipingTestPack::TESTED, 'en'));
            $this->fail('Expected refusal: TESTED with no result');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no test result', $e->errors()['state'][0]);
        }
        $this->assertSame('READY', $this->packState($pack));
    }

    public function test_a_failed_result_allows_tested_but_never_acceptance(): void
    {
        $pack = $this->readyPack('TP-AUTH-3');
        ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->owner(), $this->owner()->company_id,
            'record_pack_result', ['user:'.$this->owner()->id],
        );
        $this->packResultBy($this->owner(), $pack, ['result' => 'FAIL', 'failure_reason' => 'Pressure drop at F-12']);

        // The test happened, so TESTED is honest.
        $this->asOwner(fn () => app(PipingTestPackService::class)
            ->transition($pack->refresh(), $this->owner(), PipingTestPack::TESTED, 'en'));
        $this->assertSame('TESTED', $this->packState($pack));

        // But acceptance is not: a FAIL is not a PASS.
        try {
            $this->asOwner(fn () => app(PipingTestPackService::class)
                ->transition($pack->refresh(), $this->owner(), PipingTestPack::ACCEPTED, 'en'));
            $this->fail('Expected refusal: ACCEPTED without a PASS');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('PASS', $e->errors()['state'][0]);
        }
        $this->assertSame('TESTED', $this->packState($pack));
    }

    public function test_repair_lineage_ending_in_pass_unlocks_acceptance(): void
    {
        $pack = $this->readyPack('TP-AUTH-4');
        ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->owner(), $this->owner()->company_id,
            'record_pack_result', ['user:'.$this->owner()->id],
        );
        $failed = $this->packResultBy($this->owner(), $pack, [
            'result' => 'FAIL', 'failure_reason' => 'Weep at weld W-4',
            'started_at' => '2026-10-28T06:00:00+03:30', 'completed_at' => '2026-10-28T07:00:00+03:30',
        ]);
        $this->packResultBy($this->owner(), $pack, [
            'previous_result_id' => $failed, 'started_at' => '2026-10-28T08:00:00+03:30',
            'completed_at' => '2026-10-28T09:00:00+03:30',
        ]);

        $this->asOwner(fn () => app(PipingTestPackService::class)
            ->transition($pack->refresh(), $this->owner(), PipingTestPack::TESTED, 'en'));
        $this->asOwner(fn () => app(PipingTestPackService::class)
            ->transition($pack->refresh(), $this->owner(), PipingTestPack::ACCEPTED, 'en'));

        $this->assertSame('ACCEPTED', $this->packState($pack));
    }

    public function test_an_accepted_pack_is_terminal(): void
    {
        $pack = $this->readyPack('TP-AUTH-5');
        ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->owner(), $this->owner()->company_id,
            'record_pack_result', ['user:'.$this->owner()->id],
        );
        $this->packResultBy($this->owner(), $pack);
        $this->asOwner(fn () => app(PipingTestPackService::class)->transition($pack->refresh(), $this->owner(), PipingTestPack::TESTED, 'en'));
        $this->asOwner(fn () => app(PipingTestPackService::class)->transition($pack->refresh(), $this->owner(), PipingTestPack::ACCEPTED, 'en'));

        // Even a harmless note cannot be edited: the client may already hold it.
        // (A trigger RAISEs; unlike an RLS policy it does not quietly match 0 rows.)
        try {
            $this->asOwner(fn () => DB::table('piping_test_packs')
                ->where('id', $pack->id)->update(['notes' => 'reopened quietly']));
            $this->fail('Expected the accepted pack to refuse any change');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PACK_ACCEPTED_TERMINAL', $e->getMessage());
        }
        $this->assertNull($this->asOwner(fn () => DB::table('piping_test_packs')->where('id', $pack->id)->value('notes')));
    }

    public function test_the_state_order_is_enforced_even_against_direct_sql(): void
    {
        $pack = $this->readyPack('TP-AUTH-6');

        // READY -> ACCEPTED skips the test; no service class is involved here.
        try {
            $this->asOwner(fn () => DB::table('piping_test_packs')->where('id', $pack->id)->update(['state' => 'ACCEPTED']));
            $this->fail('Expected refusal of the skipped transition');
        } catch (QueryException $e) {
            $this->assertStringContainsString('PACK_INVALID_TRANSITION', $e->getMessage());
        }
        $this->assertSame('READY', $this->packState($pack));
    }

    public function test_metadata_edits_that_are_not_claims_still_work(): void
    {
        $pack = $this->readyPack('TP-AUTH-7');

        $affected = $this->asOwner(fn () => DB::table('piping_test_packs')
            ->where('id', $pack->id)->update(['notes' => 'client witness required']));
        $this->assertSame(1, $affected, 'a pack may still be annotated while its state is unchanged');
        $this->assertSame('READY', $this->packState($pack));
    }

    private function packState(PipingTestPack $pack): string
    {
        return (string) $this->asOwner(fn () => DB::table('piping_test_packs')->where('id', $pack->id)->value('state'));
    }
}
