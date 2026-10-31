<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Facility;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\System;
use App\Models\User;
use App\Support\FacilityKind;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-004 amendment — the breakdown gains a SYSTEM level.
 *
 * ADR-004 chose a physical breakdown (project → facility → area? → subsystem)
 * and left the functional question unanswered: `areas.unit_code` was a nullable
 * string on a physical level, which is to say a level the platform knew about
 * and did not model. This is that level, modelled.
 *
 * The distinction the tests below exist to hold:
 *
 *   - a system is FUNCTIONAL and may gather subsystems from more than one
 *     facility — the firefighting pumps sit in the plant and the tank
 *     foundation in the utilities block;
 *   - an area is PHYSICAL, optional, and cannot cross facilities;
 *   - a subsystem must name a system. Not "should": a subsystem with no system
 *     is a subsystem nobody can commission, and the cost of insisting is
 *     payable now while no project has been run on this platform.
 */
final class SystemTest extends TestCase
{
    private User $owner;

    private Project $project;

    private Company $pars;

    private TenantContext $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->seed(EpccDemoSeeder::class);
        $this->seed(AuthSeeder::class);
        $this->tenant = app(TenantContext::class);
        $this->owner = $this->userByEmail('karimi@nioc.example');
        $this->project = Project::where('code', 'PH-14')->firstOrFail();
        $this->pars = Company::where('code', 'EPC-PARS')->firstOrFail();
        app()->setLocale('fa');
    }

    // ------------------------------------------------------------- mandatory

    public function test_a_subsystem_cannot_be_created_without_naming_its_system(): void
    {
        $this->expectException(QueryException::class);

        $this->asPars(fn () => Subsystem::create($this->subsystemFields([])));
    }

    public function test_a_subsystem_carries_both_its_physical_and_its_functional_path(): void
    {
        $paths = $this->asPars(fn () => Subsystem::where('code', 'PH-14-SS-4402')->firstOrFail()
            ->load(['facility', 'system']));

        // Where it is, and what it does. Both are printed on handover
        // documents because they answer different questions about one piece of
        // metal.
        $this->assertSame('PH-14-PL-01 / PH-14-SS-4402', $paths->fullCode());
        $this->assertSame('PH-14-SY-4400 / PH-14-SS-4402', $paths->systemPath());
    }

    // --------------------------------------------------------------- spanning

    public function test_a_system_may_gather_subsystems_from_more_than_one_facility(): void
    {
        $spread = $this->asPars(function () {
            $firefighting = System::where('code', 'PH-14-SY-4400')->firstOrFail();

            return $firefighting->subsystems()->with('facility')->get()
                ->mapWithKeys(fn (Subsystem $s) => [$s->code => $s->facility?->code])->all();
        });

        $this->assertSame([
            'PH-14-SS-4401' => 'PH-14-PL-02',
            'PH-14-SS-4402' => 'PH-14-PL-01',
        ], $spread, 'the firefighting system spans both facilities, which no area can say');
    }

    public function test_a_system_that_covers_work_cannot_be_deleted(): void
    {
        $this->expectException(QueryException::class);

        // RESTRICT, not cascade: deleting a system would silently orphan every
        // subsystem's functional parent, and with it the handover dossier.
        $this->asPars(fn () => System::where('code', 'PH-14-SY-4400')->firstOrFail()->delete());
    }

    public function test_an_empty_system_can_be_deleted_a_used_one_cannot(): void
    {
        $spare = $this->asPars(fn () => System::create([
            'project_id' => $this->project->id,
            'company_id' => $this->pars->id,
            'code' => 'PH-14-SY-9999',
            'name_en' => 'Not yet populated',
            'name_fa' => 'هنوز پر نشده',
        ]));

        $this->asPars(fn () => $spare->delete());
        $this->assertSame(5, $this->asPars(fn () => System::where('project_id', $this->project->id)->count()));
    }

    // ------------------------------------------------------------------ codes

    public function test_a_system_code_is_unique_inside_a_project(): void
    {
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->asPars(fn () => System::create([
            'project_id' => $this->project->id,
            'company_id' => $this->pars->id,
            'code' => 'PH-14-SY-2200',
            'name_en' => 'Duplicate',
            'name_fa' => 'تکراری',
        ]));
    }

    public function test_the_same_system_code_may_be_used_in_another_project(): void
    {
        $other = Project::where('code', '!=', 'PH-14')->firstOrFail();

        $twin = $this->tenant->runAs($this->pars->id, fn () => System::create([
            'project_id' => $other->id,
            'company_id' => $this->pars->id,
            'code' => 'PH-14-SY-2200',
            'name_en' => 'Another project, same numbering',
            'name_fa' => 'پروژه‌ای دیگر، شماره‌گذاری یکسان',
        ]), $other->id);

        $this->assertNotNull($twin->id);
    }

    // -------------------------------------------------------------------- RLS

    public function test_a_company_outside_the_project_sees_no_systems(): void
    {
        $outsider = $this->userByEmail('hosseini@arvand.example');

        $visible = $this->tenant->runAs($outsider->company_id, fn () => System::count(), $this->project->id);

        $this->assertSame(0, $visible);
    }

    public function test_a_project_member_from_another_company_can_read_the_systems(): void
    {
        // The breakdown is the map the whole project works from: the MC body
        // must be able to see the systems it is certifying against.
        $mc = $this->userByEmail('jafari@karan.example');

        $visible = $this->tenant->runAs($mc->company_id, fn () => System::where('project_id', $this->project->id)->count(), $this->project->id);

        $this->assertSame(5, $visible);
    }

    public function test_a_company_cannot_write_a_system_into_a_project_it_is_not_in(): void
    {
        $this->expectException(QueryException::class);

        $foreign = Project::where('code', 'KISH-1')->firstOrFail();
        $this->tenant->runAs($this->pars->id, fn () => System::create([
            'project_id' => $foreign->id,
            'company_id' => $this->pars->id,
            'code' => 'SY-DENIED',
            'name_en' => 'Must be refused',
            'name_fa' => 'باید رد شود',
        ]), $foreign->id);
    }

    // --------------------------------------------------------------- plumbing

    /** @return array<string, mixed> */
    private function subsystemFields(array $overrides): array
    {
        $facility = Facility::where('project_id', $this->project->id)->orderBy('code')->firstOrFail();

        return array_replace([
            'project_id' => $this->project->id,
            'company_id' => $this->pars->id,
            'facility_id' => $facility->id,
            'code' => 'PH-14-SS-TEST',
            'name_en' => 'No system named',
            'name_fa' => 'بدون سیستم',
            'discipline' => 'PIPING',
        ], $overrides);
    }

    private function asPars(callable $fn): mixed
    {
        return $this->tenant->runAs($this->pars->id, $fn, $this->project->id);
    }
}
