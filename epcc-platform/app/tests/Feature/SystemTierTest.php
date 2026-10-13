<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Facility;
use App\Models\Project;
use App\Models\Subsystem;
use App\Models\System;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-004 rev 2 — the System tier.
 *
 * The user's breakdown diagram has five levels; the schema had four. What this
 * file pins down is the reason the fifth one is not decoration:
 *
 *   A SYSTEM is functional, an AREA is geographic, and they do not nest.
 *
 * Commissioning is organised by system, construction by area. Without the tier
 * a subsystem's only parent is geographic, so "what is outstanding before Fuel
 * Gas can be commissioned?" has no query at all - and nobody discovers that
 * until commissioning starts, with tens of thousands of rows to re-parent.
 */
final class SystemTierTest extends TestCase
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

    private function asPars(callable $fn): mixed
    {
        $project = $this->project();
        $pars = \App\Models\Company::where('code', 'EPC-PARS')->firstOrFail();

        return $this->tenant->runAs($pars->id, $fn, $project->id);
    }

    // ------------------------------------------------- the tier exists --

    public function test_every_seeded_subsystem_belongs_to_a_system(): void
    {
        $orphans = $this->asPars(
            fn () => Subsystem::whereNotNull('facility_id')->whereNull('system_id')->count()
        );

        $this->assertSame(0, $orphans);
    }

    public function test_a_subsystem_cannot_be_created_without_a_system(): void
    {
        // Mandatory, unlike area. Every tagged asset belongs to some functional
        // system even if the project calls it "General"; optional would let two
        // projects grow incompatible shapes.
        $this->expectException(QueryException::class);

        $this->asPars(function () {
            $plant = Facility::where('code', 'PH-14-PL-01')->firstOrFail();

            Subsystem::create([
                'project_id' => $this->project()->id,
                'company_id' => \App\Models\Company::where('code', 'EPC-PARS')->firstOrFail()->id,
                'facility_id' => $plant->id,
                'code' => 'PH-14-SS-9901',
                'name_en' => 'No system',
                'name_fa' => 'بدون سیستم',
                'discipline' => 'MECHANICAL',
            ]);
        });
    }

    public function test_a_subsystem_cannot_join_a_system_of_another_facility(): void
    {
        // The composite FK, same trick as areas: the corrupt state is
        // unrepresentable rather than merely discouraged. A subsystem in the
        // plant pointing at a utilities system would be invisible on screen
        // and wrong in every roll-up.
        $this->expectException(QueryException::class);

        $this->asPars(function () {
            $plant = Facility::where('code', 'PH-14-PL-01')->firstOrFail();
            $utility = Facility::where('code', 'PH-14-PL-02')->firstOrFail();
            $foreign = System::where('facility_id', $utility->id)->firstOrFail();

            Subsystem::create([
                'project_id' => $this->project()->id,
                'company_id' => \App\Models\Company::where('code', 'EPC-PARS')->firstOrFail()->id,
                'facility_id' => $plant->id,
                'system_id' => $foreign->id,
                'code' => 'PH-14-SS-9902',
                'name_en' => 'Cross facility',
                'name_fa' => 'میان‌تأسیساتی',
                'discipline' => 'MECHANICAL',
            ]);
        });
    }

    // ------------------------------------ functional is not geographic --

    public function test_one_system_spans_more_than_one_area(): void
    {
        // THE POINT OF THE WHOLE TIER. Cooling water covers a subsystem in
        // AR-22 and another with no area at all. If the fixture had one system
        // per area, every confusion between the two axes would pass unnoticed.
        $areas = $this->asPars(function () {
            $cw = System::where('code', 'CW')->firstOrFail();

            return Subsystem::where('system_id', $cw->id)->pluck('area_id')->all();
        });

        $this->assertCount(2, $areas);
        $this->assertNotSame($areas[0], $areas[1]);
    }

    public function test_one_area_hosts_subsystems_of_more_than_one_system(): void
    {
        $systems = $this->asPars(function () {
            $plant = Facility::where('code', 'PH-14-PL-01')->firstOrFail();

            return Subsystem::where('facility_id', $plant->id)
                ->pluck('system_id')->unique()->values()->all();
        });

        $this->assertGreaterThan(1, count($systems));
    }

    // -------------------------------------------------------- scoping --

    public function test_the_same_system_code_may_repeat_in_another_facility(): void
    {
        $this->asPars(function () {
            $utility = Facility::where('code', 'PH-14-PL-02')->firstOrFail();

            System::create([
                'project_id' => $this->project()->id,
                'company_id' => \App\Models\Company::where('code', 'EPC-PARS')->firstOrFail()->id,
                'facility_id' => $utility->id,
                'code' => 'CW', // already exists under PL-01
                'name_en' => 'Cooling water (utilities)',
                'name_fa' => 'آب خنک‌کننده (جانبی)',
            ]);
        });

        $count = $this->asPars(fn () => System::where('code', 'CW')->count());

        $this->assertSame(2, $count);
    }

    public function test_a_system_code_is_unique_inside_one_facility(): void
    {
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->asPars(function () {
            $plant = Facility::where('code', 'PH-14-PL-01')->firstOrFail();

            System::create([
                'project_id' => $this->project()->id,
                'company_id' => \App\Models\Company::where('code', 'EPC-PARS')->firstOrFail()->id,
                'facility_id' => $plant->id,
                'code' => 'CW',
                'name_en' => 'Duplicate',
                'name_fa' => 'تکراری',
            ]);
        });
    }

    public function test_the_full_code_names_the_facility(): void
    {
        // The bare code is unsafe to print anywhere a reader might compare
        // across facilities, and it is composed at read time so a rename
        // cannot leave a stale copy behind.
        $full = $this->asPars(
            fn () => System::with('facility')->where('code', 'CW')->firstOrFail()->fullCode()
        );

        $this->assertSame('PH-14-PL-01-CW', $full);
    }

    // ------------------------------------------------- commissioning --

    public function test_systems_sort_by_commissioning_sequence_with_unplanned_last(): void
    {
        // A system with no sequence is not "first", it is unplanned. Sorting
        // it to the top of a commissioning schedule is the kind of wrong that
        // looks like a decision.
        //
        // NOTE: on PostgreSQL, ORDER BY ... ASC already places NULLs last, so
        // dropping the explicit NULLS LAST changes nothing here (mutant M86
        // survives, and is equivalent). The clause stays because it makes the
        // intent explicit and because it is NOT a no-op under DESC or on other
        // engines - but this test cannot be said to pin it.
        $codes = $this->asPars(
            fn () => System::query()->inCommissioningOrder()->pluck('code')->all()
        );

        $this->assertSame(['EL', 'CW', 'IA'], $codes);
    }

    public function test_deleting_a_system_with_subsystems_is_refused(): void
    {
        // ON DELETE RESTRICT, not CASCADE. Deleting a functional system must
        // not silently take the delivered subsystems - and their certificates
        // and punch items - with it.
        $this->expectException(QueryException::class);

        $this->asPars(function () {
            System::where('code', 'CW')->firstOrFail()->delete();
        });
    }

    // ---------------------------------------------------- unit codes --

    public function test_every_area_carries_a_unit_code(): void
    {
        $missing = $this->asPars(
            fn () => Area::whereNull('unit_code')->count()
        );

        $this->assertSame(0, $missing);
    }

    public function test_an_explicit_null_unit_code_is_refused(): void
    {
        // Counting the seeded rows is not enough: the seeder never writes a
        // null, so that assertion passes with or without the NOT NULL. The
        // constraint has to be attacked directly. (Found by mutant M83.)
        $this->expectException(QueryException::class);

        $this->asPars(function () {
            $plant = Facility::where('code', 'PH-14-PL-01')->firstOrFail();

            Area::create([
                'project_id' => $this->project()->id,
                'company_id' => \App\Models\Company::where('code', 'EPC-PARS')->firstOrFail()->id,
                'facility_id' => $plant->id,
                'code' => 'AR-77',
                'name_en' => 'Null unit',
                'name_fa' => 'واحد تهی',
                'unit_code' => null,
            ]);
        });
    }

    public function test_a_whitespace_only_unit_code_is_refused(): void
    {
        // NOT NULL alone does not catch '   ', and a tag built from it would
        // read 11--P-101A. (Found by mutant M85.)
        $this->expectException(QueryException::class);

        $this->asPars(function () {
            $plant = Facility::where('code', 'PH-14-PL-01')->firstOrFail();

            Area::create([
                'project_id' => $this->project()->id,
                'company_id' => \App\Models\Company::where('code', 'EPC-PARS')->firstOrFail()->id,
                'facility_id' => $plant->id,
                'code' => 'AR-78',
                'name_en' => 'Blank unit',
                'name_fa' => 'واحد خالی',
                'unit_code' => '   ',
            ]);
        });
    }

    public function test_two_areas_in_one_facility_cannot_share_a_unit_code(): void
    {
        // This is the failure the user described: with two areas both coded
        // '11', the tag 11-P-101A is ambiguous and a technician cannot tell
        // which pump is being hydrotested.
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->asPars(function () {
            $plant = Facility::where('code', 'PH-14-PL-01')->firstOrFail();

            Area::create([
                'project_id' => $this->project()->id,
                'company_id' => \App\Models\Company::where('code', 'EPC-PARS')->firstOrFail()->id,
                'facility_id' => $plant->id,
                'code' => 'AR-44',
                'name_en' => 'Clashing unit',
                'name_fa' => 'واحد متداخل',
                'unit_code' => 'U-2200', // already used by AR-22
            ]);
        });
    }

    public function test_the_same_unit_code_may_repeat_in_another_facility(): void
    {
        // Uniqueness is per facility, because the facility is part of the tag
        // context. Two plants in one project may each run a unit '11'.
        $this->asPars(function () {
            $utility = Facility::where('code', 'PH-14-PL-02')->firstOrFail();

            Area::create([
                'project_id' => $this->project()->id,
                'company_id' => \App\Models\Company::where('code', 'EPC-PARS')->firstOrFail()->id,
                'facility_id' => $utility->id,
                'code' => 'AR-55',
                'name_en' => 'Utility area',
                'name_fa' => 'ناحیه جانبی',
                'unit_code' => 'U-2200',
            ]);
        });

        $this->assertSame(2, $this->asPars(fn () => Area::where('unit_code', 'U-2200')->count()));
    }

    // ---------------------------------------------------------- RLS --

    public function test_systems_are_hidden_from_a_non_participant(): void
    {
        $arvand = \App\Models\Company::where('code', 'EPC-ARVAND')->firstOrFail();

        $visible = $this->tenant->runAs($arvand->id, fn () => System::count(), $this->project()->id);

        $this->assertSame(0, $visible);
    }

    public function test_row_level_security_is_forced_on_systems(): void
    {
        // FORCE, not merely ENABLE: without it the table owner bypasses every
        // policy, and the application role IS the owner here.
        $forced = DB::selectOne(
            "SELECT relrowsecurity, relforcerowsecurity FROM pg_class WHERE relname = 'systems'"
        );

        $this->assertTrue($forced->relrowsecurity);
        $this->assertTrue($forced->relforcerowsecurity);
    }
}
