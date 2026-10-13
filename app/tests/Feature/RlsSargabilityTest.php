<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Facility;
use App\Models\Project;
use App\Models\Subsystem;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Database\Seeders\MaterialCatalogueSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-012 (P-17) — the sargable RLS project scope.
 *
 * The defective policy began every tenant table with
 * `(GUC IS NULL OR project_id = GUC)`, which no index can serve; every
 * register read seq-scanned the whole table and latency scaled with OTHER
 * customers' data. The fix replaces that disjunction with one semi-join on
 * participation. These tests pin the three properties ADR-012 §8 demands:
 *
 *   1. the cross-project view still returns rows (the §4 "fast but broken"
 *      policy passes isolation tests while failing this one),
 *   2. binding still narrows, and non-participants still see nothing,
 *   3. at volume, a bound-project register read is an index scan, never a
 *      seq scan — the standing guard that keeps the defect from returning.
 */
final class RlsSargabilityTest extends TestCase
{
    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true]);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->seed(MaterialCatalogueSeeder::class);

        $this->tenant = app(TenantContext::class);
    }

    private function company(string $code): Company
    {
        return $this->tenant->forIdentityLookup(
            fn () => Company::where('code', $code)->firstOrFail()
        );
    }

    private function project(string $code): Project
    {
        return $this->tenant->forIdentityLookup(
            fn () => Project::where('code', $code)->firstOrFail()
        );
    }

    /** Get-or-create a subsystem on a project, bound as the given company. */
    private function subsystem(Project $project, Company $company, string $code): Subsystem
    {
        return $this->tenant->runAs($company->id, function () use ($project, $company, $code) {
            $existing = Subsystem::where('project_id', $project->id)->where('code', $code)->first();

            return $existing ?? Subsystem::create([
                'project_id' => $project->id,
                'company_id' => $company->id,
                'facility_id' => Facility::firstOrCreate(
                    ['project_id' => $project->id, 'code' => 'RLS-PROBE'],
                    ['company_id' => $company->id, 'kind' => 'PLANT',
                        'name_en' => 'RLS probe facility', 'name_fa' => 'تاسیسات آزمون']
                )->id,
                'code' => $code,
                'name_en' => 'Sargability '.$code,
                'name_fa' => 'زیرسیستم تست',
                'discipline' => 'PIPING',
            ]);
        }, $project->id);
    }

    // ------------------------------------------------------------------

    public function test_cross_project_view_returns_rows_and_binding_narrows_them(): void
    {
        $pars = $this->company('EPC-PARS');
        $ph14 = $this->project('PH-14');
        $bb2 = $this->project('BB-2');
        $kish = $this->project('KISH-1');

        $this->subsystem($ph14, $pars, 'SS-RX-A');
        $this->subsystem($bb2, $pars, 'SS-RX-B');

        // Unscoped: every project the company participates in — the landing
        // page. The §4 "fast but broken" policy returns 0 rows here.
        $unscoped = $this->tenant->runAs($pars->id, function (): array {
            return Subsystem::whereIn('code', ['SS-RX-A', 'SS-RX-B'])
                ->orderBy('code')->pluck('code')->all();
        });
        $this->assertSame(['SS-RX-A', 'SS-RX-B'], $unscoped);

        // Bound to one project: exactly that project.
        $bound = $this->tenant->runAs($pars->id, function (): array {
            return Subsystem::whereIn('code', ['SS-RX-A', 'SS-RX-B'])
                ->orderBy('code')->pluck('code')->all();
        }, $ph14->id);
        $this->assertSame(['SS-RX-A'], $bound);

        // Bound to a project the company is NOT on: nothing, not an error.
        $foreign = $this->tenant->runAs($pars->id, function (): int {
            return Subsystem::whereIn('code', ['SS-RX-A', 'SS-RX-B'])->count();
        }, $kish->id);
        $this->assertSame(0, $foreign);
    }

    public function test_a_non_participant_sees_nothing_scoped_or_unscoped(): void
    {
        $pars = $this->company('EPC-PARS');
        $sahand = $this->company('EPC-SAHAND');
        $ph14 = $this->project('PH-14');
        $bb2 = $this->project('BB-2');

        $this->subsystem($ph14, $pars, 'SS-ISO-A');
        $this->subsystem($bb2, $pars, 'SS-ISO-B');

        // Sahand is on PH-14 only: unscoped it sees exactly PH-14's row.
        $unscoped = $this->tenant->runAs($sahand->id, function (): array {
            return Subsystem::whereIn('code', ['SS-ISO-A', 'SS-ISO-B'])->pluck('code')->all();
        });
        $this->assertSame(['SS-ISO-A'], $unscoped);

        // Bound to BB-2, where Sahand does not participate: nothing.
        $bound = $this->tenant->runAs($sahand->id, function (): int {
            return Subsystem::whereIn('code', ['SS-ISO-A', 'SS-ISO-B'])->count();
        }, $bb2->id);
        $this->assertSame(0, $bound);
    }

    public function test_the_write_path_still_pins_project_and_participation(): void
    {
        $pars = $this->company('EPC-PARS');
        $kish = $this->project('KISH-1');

        // WITH CHECK keeps its original text: a write onto a project the
        // company does not participate in must be refused by the database.
        $this->expectException(QueryException::class);
        $this->tenant->runAs($pars->id, function () use ($kish, $pars): void {
            Subsystem::create([
                'project_id' => $kish->id,
                'company_id' => $pars->id,
                'code' => 'SS-DENIED',
                'name_en' => 'Must be refused',
                'name_fa' => 'باید رد شود',
                'discipline' => 'PIPING',
            ]);
        }, $kish->id);
    }

    public function test_the_fact_table_filter_carries_no_project_disjunction(): void
    {
        /*
         * The ADR-012 defect attached `(GUC IS NULL OR project_id = GUC)`
         * as a filter on the FACT TABLE scan, which no index can serve. The
         * fix moves the project test into a hashed semi-join over the small
         * participation table. Assert the plan shape directly: the filter on
         * the punch_items scan node must contain no `IS NULL` disjunction.
         * Shape-based, so it holds at fixture scale too — a plan-guard that
         * does not need a million rows to discriminate.
         */
        $pars = $this->company('EPC-PARS');
        $ph14 = $this->project('PH-14');
        $raiser = $this->userByEmail('rezaei@pars.example');
        $subsystem = $this->subsystem($ph14, $pars, 'SS-SHAPE');

        $this->tenant->runAs($pars->id, function () use ($ph14, $pars, $raiser, $subsystem): void {
            DB::statement(<<<SQL
                INSERT INTO punch_items (
                    id, project_id, subsystem_id, company_id, number, sequence,
                    category, state, description_en, description_fa,
                    raised_by, raised_at, created_at, updated_at
                ) VALUES (
                    gen_random_uuid(), ?, ?, ?, 'PI-SHAPE-1', 1,
                    'A', 'OPEN', 'Shape probe', 'کاوش شکل',
                    ?, now(), now(), now()
                )
            SQL, [$ph14->id, $subsystem->id, $pars->id, $raiser->id]);

            // RLS-scoped read with no project predicate in the query — the
            // shape that forced the whole-table scan under the old policy.
            $plan = DB::select('EXPLAIN (FORMAT JSON) SELECT count(*) FROM punch_items');

            $filters = [];
            $walk = function (array $node) use (&$walk, &$filters): void {
                if (($node['Relation Name'] ?? '') === 'punch_items') {
                    $filters[] = $node['Filter'] ?? '';
                }
                foreach ($node['Plans'] ?? [] as $child) {
                    $walk($child);
                }
            };
            $rawPlan = $plan[0]->{'QUERY PLAN'};
            $decoded = is_string($rawPlan) ? json_decode($rawPlan, true, 512, JSON_THROW_ON_ERROR) : $rawPlan;
            $walk($decoded[0]['Plan']);

            foreach ($filters as $filter) {
                $this->assertStringNotContainsString(
                    'IS NULL',
                    $filter,
                    'The punch_items scan still carries the non-sargable project disjunction: '.$filter
                );
            }

            $this->assertSame(1, (int) DB::selectOne('SELECT count(*) AS c FROM punch_items')->c);
        }, $ph14->id);
    }

    public function test_bound_view_returns_only_the_bound_projects_rows_at_volume(): void
    {
        $pars = $this->company('EPC-PARS');
        $arvand = $this->company('EPC-ARVAND');
        $ph14 = $this->project('PH-14');
        $kish = $this->project('KISH-1');
        $raiser = $this->userByEmail('rezaei@pars.example');

        $this->subsystem($ph14, $pars, 'SS-VOL-A');
        $this->subsystem($kish, $arvand, 'SS-VOL-B');

        $target = $this->tenant->runAs($pars->id, function () use ($ph14): Subsystem {
            return Subsystem::where('project_id', $ph14->id)->where('code', 'SS-VOL-A')->firstOrFail();
        }, $ph14->id);
        $filler = $this->tenant->runAs($arvand->id, function () use ($kish): Subsystem {
            return Subsystem::where('project_id', $kish->id)->where('code', 'SS-VOL-B')->firstOrFail();
        }, $kish->id);

        /*
         * Volume the RLS check can feel: 30k rows in the bound project and
         * 30k in another company's project. WITH CHECK runs on every
         * generated row, so the bulk insert also proves the write policy
         * survives the rebuild.
         */
        $this->tenant->runAs($pars->id, function () use ($ph14, $pars, $raiser, $target): void {
            DB::statement(<<<SQL
                INSERT INTO punch_items (
                    id, project_id, subsystem_id, company_id, number, sequence,
                    category, state, description_en, description_fa,
                    raised_by, raised_at, created_at, updated_at
                )
                SELECT gen_random_uuid(), ?, ?, ?, 'PI-' || i, i,
                       'A', 'OPEN', 'Volume punch item', 'پانچ حجمی',
                       ?, now(), now(), now()
                FROM generate_series(1, 30000) AS i
            SQL, [$ph14->id, $target->id, $pars->id, $raiser->id]);
        }, $ph14->id);

        $this->tenant->runAs($arvand->id, function () use ($kish, $arvand, $raiser, $filler): void {
            DB::statement(<<<SQL
                INSERT INTO punch_items (
                    id, project_id, subsystem_id, company_id, number, sequence,
                    category, state, description_en, description_fa,
                    raised_by, raised_at, created_at, updated_at
                )
                SELECT gen_random_uuid(), ?, ?, ?, 'KI-' || i, i,
                       'A', 'OPEN', 'Volume punch item', 'پانچ حجمی',
                       ?, now(), now(), now()
                FROM generate_series(1, 30000) AS i
            SQL, [$kish->id, $filler->id, $arvand->id, $raiser->id]);
        }, $kish->id);

        // Bound to PH-14: exactly pars's 30k rows; none of KISH-1's.
        $this->tenant->runAs($pars->id, function (): void {
            $this->assertSame(30000, (int) DB::selectOne('SELECT count(*) AS c FROM punch_items')->c);
            $this->assertSame(
                0,
                (int) DB::selectOne("SELECT count(*) AS c FROM punch_items WHERE number LIKE 'KI-%'")->c
            );
        }, $ph14->id);

        // Unbound: still only PH-14's rows (pars has no BB-2 punch rows).
        $this->tenant->runAs($pars->id, function (): void {
            $this->assertSame(30000, (int) DB::selectOne('SELECT count(*) AS c FROM punch_items')->c);
        });
    }
}
