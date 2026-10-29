<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingTestPack;
use App\Models\Project;
use App\Models\User;
use App\Services\InspectionResultService;
use App\Services\PipingTestPackService;
use App\Support\TenantContext;
use App\Support\Units\UnitRegistry;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ResultRecorderPolicyFixture;
use Tests\TestCase;

/**
 * ADR-048 A.4c slice 1 — the two gaps the roadmap carried since slice 1:
 *
 *   1. A recorded pressure must be a PRESSURE, in a unit this platform knows.
 *      The pack's planned pressure was unit-checked; the recorded result was
 *      not, and `knows()` alone accepts a temperature as a pressure. The ITR
 *      dossier prints this string next to a number a client reads.
 *   2. Who may author a nomination: your own company, or the project employer.
 *      A.4b made recording default-deny but left authoring open, so a rival
 *      contractor could in principle nominate someone else's inspectors.
 */
final class ResultUnitsAndPolicyAuthorshipTest extends TestCase
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

    private function project(): Project
    {
        return Project::where('code', 'PH-14')->firstOrFail();
    }

    /** NIOC — the employer/owner company of PH-14. */
    private function employer(): User
    {
        return $this->userByEmail('karimi@nioc.example');
    }

    /** EPC-PARS project manager. */
    private function contractorPm(): User
    {
        return $this->userByEmail('rezaei@pars.example');
    }

    /** EPC-PARS QC inspector. */
    private function contractorQc(): User
    {
        return $this->userByEmail('sadeghi@pars.example');
    }

    /** PMC-TEHRAN consultant — a third party, not the employer. */
    private function consultant(): User
    {
        return $this->userByEmail('tehrani@pmc.example');
    }

    private function asOwner(callable $fn): mixed
    {
        $project = $this->project();

        return $this->tenant->runAs($project->owner_company_id, $fn, $project->id);
    }

    private function readyPack(string $code = 'TP-UNIT-1'): PipingTestPack
    {
        $pack = $this->asOwner(fn () => app(PipingTestPackService::class)
            ->create($this->project(), $this->employer(), ['code' => $code]));
        $this->asOwner(function () use ($pack) {
            DB::table('piping_test_packs')->where('id', $pack->id)
                ->update(['state' => 'READY', 'ready_at' => now(), 'ready_by' => $this->employer()->id]);

            return $pack->refresh();
        });

        return $pack;
    }

    private function nominateForPackResults(User $target): void
    {
        // The employer nominates: that is exactly the control A.4c adds.
        ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->employer(), $target->company_id,
            'record_pack_result', ['user:'.$target->id],
        );
    }

    /** @param  array<string,mixed>  $overrides */
    private function record(PipingTestPack $pack, array $overrides = [], ?User $actor = null): string
    {
        return app(InspectionResultService::class)->recordTestResult(
            $this->project(),
            $actor ?? $this->employer(),
            $pack->id,
            array_replace([
                'test_type' => 'HYDROSTATIC', 'medium' => 'WATER', 'test_pressure' => '21.750',
                'pressure_unit' => 'BAR', 'hold_duration_minutes' => 120,
                'started_at' => '2026-10-28T06:00:00+03:30',
                'completed_at' => '2026-10-28T09:30:00+03:30',
                'result' => 'PASS', 'request_id' => (string) Str::uuid(),
            ], $overrides),
            'en',
        );
    }

    private function resultCount(): int
    {
        return (int) $this->asOwner(fn () => DB::table('test_pack_results')->count());
    }

    // ------------------------------------------------------------------
    // 1. units
    // ------------------------------------------------------------------

    public function test_an_unknown_pressure_unit_is_refused(): void
    {
        $pack = $this->readyPack('TP-UNIT-2');
        $this->nominateForPackResults($this->employer());

        try {
            $this->record($pack, ['pressure_unit' => 'BANANA']);
            $this->fail('Expected refusal of an unknown unit');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Unknown pressure unit', $e->errors()['pressure_unit'][0]);
        }
        $this->assertSame(0, $this->resultCount());
    }

    public function test_a_known_unit_of_the_wrong_quantity_is_refused(): void
    {
        $pack = $this->readyPack('TP-UNIT-3');
        $this->nominateForPackResults($this->employer());

        // "C" is a unit this platform knows - it is a temperature, not a pressure.
        $this->assertTrue(app(UnitRegistry::class)->knows('C'));

        try {
            $this->record($pack, ['pressure_unit' => 'C']);
            $this->fail('Expected refusal: a temperature is not a pressure');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('not a pressure quantity', $e->errors()['pressure_unit'][0]);
        }
        $this->assertSame(0, $this->resultCount());
    }

    public function test_every_pressure_unit_in_the_reference_pack_is_accepted_in_any_case(): void
    {
        $pack = $this->readyPack('TP-UNIT-4');
        $this->nominateForPackResults($this->employer());
        $registry = app(UnitRegistry::class);

        $pressureUnits = [];
        foreach ($registry->unitsFor('PRESSURE') as $unit) {
            $pressureUnits[] = is_array($unit) ? (string) $unit['code'] : (string) $unit;
        }
        $this->assertNotSame([], $pressureUnits, 'the reference pack defines pressure units');

        foreach ($pressureUnits as $i => $code) {
            // Mixed case on purpose: the service stores uppercase, the CHECK is
            // case-insensitive, and both must agree with the registry.
            $this->record($pack, [
                'pressure_unit' => $i % 2 === 0 ? strtoupper($code) : $code,
                'request_id' => (string) Str::uuid(),
            ]);
        }
        $this->assertSame(count($pressureUnits), $this->resultCount());
    }

    public function test_the_database_holds_the_same_unit_rule_against_direct_sql(): void
    {
        $pack = $this->readyPack('TP-UNIT-5');
        $policyId = ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->employer(), $this->employer()->company_id,
            'record_pack_result', ['user:'.$this->employer()->id],
        );

        try {
            $this->asOwner(function () use ($pack, $policyId): void {
                // The result guard pins recorded_by to the session actor.
                DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$this->employer()->id]);
                DB::table('test_pack_results')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => $this->project()->id,
                    'company_id' => $this->employer()->company_id, 'test_pack_id' => $pack->id,
                    'test_type' => 'HYDROSTATIC', 'medium' => 'WATER', 'test_pressure' => '21.750',
                    'pressure_unit' => 'NOPE', 'hold_duration_minutes' => 60,
                    'started_at' => now(), 'completed_at' => now(), 'result' => 'PASS',
                    'mode' => 'IN_SYSTEM', 'performer_name' => 'X', 'report_ref' => 'R-1',
                    'recorded_by' => $this->employer()->id, 'recorder_policy_id' => $policyId,
                    'request_id' => (string) Str::uuid(), 'recorded_at' => now(),
                ]);
            });
            $this->fail('Expected the CHECK to refuse an unknown unit');
        } catch (QueryException $e) {
            $this->assertStringContainsString('test_pack_results_pressure_unit_is_pressure', $e->getMessage());
        }
        $this->assertSame(0, $this->resultCount());
    }

    public function test_the_planned_pack_pressure_is_held_to_the_same_quantity_rule(): void
    {
        try {
            $this->asOwner(fn () => app(PipingTestPackService::class)->create(
                $this->project(), $this->employer(),
                ['code' => 'TP-UNIT-6', 'test_pressure' => '21.75', 'test_pressure_unit' => 'C'],
            ));
            $this->fail('Expected refusal: the plan is a pressure, not a temperature');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Not a pressure unit', $e->errors()['test_pressure_unit'][0]);
        }
        $this->assertNull($this->asOwner(fn () => DB::table('piping_test_packs')
            ->where('code', 'TP-UNIT-6')->value('id')));
    }

    // ------------------------------------------------------------------
    // 2. who may author a nomination
    // ------------------------------------------------------------------

    public function test_a_company_may_nominate_its_own_people(): void
    {
        $policyId = ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->contractorPm(), $this->contractorPm()->company_id,
            'record_weld', ['user:'.$this->contractorPm()->id],
        );
        $this->assertNotEmpty($policyId);
        $this->assertSame(1, (int) $this->asOwner(fn () => DB::table('result_recorder_policies')->count()));
    }

    public function test_the_employer_may_nominate_a_contractors_people(): void
    {
        // The client deciding who is qualified to produce the evidence it reads.
        $policyId = ResultRecorderPolicyFixture::ensure(
            $this->tenant, $this->project(), $this->employer(), $this->contractorQc()->company_id,
            'record_weld', ['user:'.$this->contractorQc()->id],
        );
        $row = $this->asOwner(fn () => DB::table('result_recorder_policies')->where('id', $policyId)->first());
        $this->assertSame($this->employer()->company_id, (string) $row->company_id);
        $this->assertSame($this->contractorQc()->company_id, (string) $row->target_company_id);
    }

    public function test_a_third_party_cannot_nominate_another_companys_people(): void
    {
        // PMC is a project participant, but it is not the employer and these
        // are not its people: its nomination must be refused.
        try {
            ResultRecorderPolicyFixture::ensure(
                $this->tenant, $this->project(), $this->consultant(), $this->contractorQc()->company_id,
                'record_weld', ['user:'.$this->contractorQc()->id],
            );
            $this->fail('Expected refusal of a third-party nomination');
        } catch (QueryException $e) {
            $this->assertStringContainsString('RESULT_POLICY_AUTHOR_NOT_PERMITTED', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->asOwner(fn () => DB::table('result_recorder_policies')->count()));
    }

    public function test_a_nomination_must_be_authored_by_a_project_member(): void
    {
        // tehrani (PMC) is a member of PH-14; hosseini (EPC-ARVAND) is not in
        // this project at all, so even self-nomination is refused here.
        $outsider = $this->userByEmail('hosseini@arvand.example');

        try {
            ResultRecorderPolicyFixture::ensure(
                $this->tenant, $this->project(), $outsider, $outsider->company_id,
                'record_weld', ['user:'.$outsider->id],
            );
            $this->fail('Expected refusal for an author outside the project');
        } catch (QueryException $e) {
            $message = $e->getMessage();
            $this->assertTrue(
                str_contains($message, 'RESULT_POLICY_AUTHOR_NOT_MEMBER')
                || str_contains($message, 'RLS')
                || str_contains($message, 'row-level security'),
                'refused as a non-member author: '.$message,
            );
        }
        $this->assertSame(0, (int) $this->asOwner(fn () => DB::table('result_recorder_policies')->count()));
    }

    public function test_a_nomination_cannot_be_written_in_another_actors_name(): void
    {
        // The row must name the session actor: a policy that claims to have been
        // authored by someone else is refused by RLS before any trigger runs.
        try {
            $this->asOwner(function (): void {
                DB::select('SELECT set_config(\'epcc.result_actor_id\', ?, true)', [$this->employer()->id]);
                DB::table('result_recorder_policies')->insert([
                    'id' => (string) Str::uuid(), 'project_id' => $this->project()->id,
                    'company_id' => $this->employer()->company_id,
                    'target_company_id' => $this->employer()->company_id,
                    'created_by' => $this->contractorPm()->id, 'action' => 'record_weld',
                    'version' => 1, 'suspended' => false,
                    'selectors' => json_encode(['user:'.$this->contractorPm()->id]),
                    'reason' => 'Forged authorship attempt',
                ]);
            });
            $this->fail('Expected refusal of a policy naming another author');
        } catch (QueryException $e) {
            $this->assertSame('42501', (string) $e->getCode());
        }
        $this->assertSame(0, (int) $this->asOwner(fn () => DB::table('result_recorder_policies')->count()));
    }
}
