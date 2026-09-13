<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Company;
use App\Models\Facility;
use App\Models\Project;
use App\Models\Subsystem;
use App\Support\FacilityKind;
use App\Support\TenantContext;
use Database\Seeders\AuthSeeder;
use Database\Seeders\EpccDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ADR-004: the physical breakdown — project → facility → (area) → subsystem.
 *
 * What is actually at stake here is not "can we store a tree". It is that the
 * MC gate, the punch register and the material shortage report all roll up
 * through this structure, so a subsystem attached to the wrong parent, or an
 * area belonging to another plant, is a number that is quietly wrong on a
 * report somebody signs.
 */
final class BreakdownTest extends TestCase
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
        return $this->tenant->forIdentityLookup(fn () => Project::where('code', 'PH-14')->firstOrFail());
    }

    private function company(string $code): Company
    {
        return $this->tenant->forIdentityLookup(fn () => Company::where('code', $code)->firstOrFail());
    }

    /** @param callable():mixed $fn */
    private function asPars(callable $fn): mixed
    {
        return $this->tenant->runAs($this->company('EPC-PARS')->id, $fn, $this->project()->id);
    }

    // -----------------------------------------------------------------------
    // structure
    // -----------------------------------------------------------------------

    public function test_a_project_can_hold_several_facilities(): void
    {
        $codes = $this->asPars(fn () => Facility::orderBy('code')->pluck('code')->all());

        $this->assertSame(['PH-14-PL-01', 'PH-14-PL-02'], $codes);
    }

    public function test_every_subsystem_has_a_facility(): void
    {
        $orphans = $this->asPars(fn () => Subsystem::whereNull('facility_id')->count());

        $this->assertSame(0, $orphans, 'a subsystem with no parent is the flat structure ADR-004 removed');
    }

    public function test_a_facility_can_hold_several_areas(): void
    {
        $areas = $this->asPars(function () {
            $plant = Facility::where('code', 'PH-14-PL-01')->firstOrFail();

            return $plant->areas()->orderBy('code')->pluck('code')->all();
        });

        $this->assertSame(['AR-22', 'AR-33'], $areas);
    }

    /**
     * The optionality is the design, not an oversight: Plant 3D's own refinery
     * example has 13 areas, but a booster station with 12 subsystems must not
     * be forced to invent 12 areas to use the software.
     */
    public function test_a_subsystem_may_skip_the_area_level(): void
    {
        $withoutArea = $this->asPars(
            fn () => Subsystem::whereNull('area_id')->orderBy('code')->pluck('code')->all()
        );

        $this->assertContains('PH-14-SS-5501', $withoutArea, 'a subsystem inside a plant that does use areas');
        $this->assertContains('PH-14-SS-6601', $withoutArea, 'a subsystem in a facility with no areas at all');
    }

    public function test_a_facility_can_have_no_areas_at_all(): void
    {
        $this->asPars(function () {
            $utility = Facility::where('code', 'PH-14-PL-02')->firstOrFail();

            $this->assertFalse($utility->usesAreas());
            $this->assertCount(0, $utility->areas);
            $this->assertCount(2, $utility->subsystems, 'it still owns subsystems directly');
        });
    }

    public function test_unassigned_subsystems_are_listed_for_a_facility_that_uses_areas(): void
    {
        $this->asPars(function () {
            $plant = Facility::where('code', 'PH-14-PL-01')->firstOrFail();

            $this->assertTrue($plant->usesAreas());
            $this->assertSame(
                ['PH-14-SS-5501'],
                $plant->unassignedSubsystems()->pluck('code')->all(),
            );
        });
    }

    // -----------------------------------------------------------------------
    // numbering — the user's decision
    // -----------------------------------------------------------------------

    /**
     * The whole point of the numbering change: two plants in one project may
     * legitimately both hold an SS-2201.
     */
    public function test_the_same_subsystem_code_may_repeat_in_another_facility(): void
    {
        $this->asPars(function () {
            $utility = Facility::where('code', 'PH-14-PL-02')->firstOrFail();

            $twin = Subsystem::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->company('EPC-PARS')->id,
                'facility_id' => $utility->id,
                'code' => 'PH-14-SS-2201', // already exists under PL-01
                'name_en' => 'Utility twin',
                'name_fa' => 'همتای سرویس جانبی',
                'discipline' => 'MECHANICAL',
            ]);

            $this->assertNotNull($twin->id);
        });

        $count = $this->asPars(fn () => Subsystem::where('code', 'PH-14-SS-2201')->count());
        $this->assertSame(2, $count);
    }

    public function test_a_subsystem_code_is_still_unique_inside_one_facility(): void
    {
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->asPars(function () {
            $plant = Facility::where('code', 'PH-14-PL-01')->firstOrFail();

            Subsystem::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->company('EPC-PARS')->id,
                'facility_id' => $plant->id,
                'code' => 'PH-14-SS-2201', // duplicate within the SAME facility
                'name_en' => 'Duplicate',
                'name_fa' => 'تکراری',
                'discipline' => 'MECHANICAL',
            ]);
        });
    }

    /**
     * Because codes are only unique per facility, printing the bare code in a
     * register that spans facilities is now ambiguous.
     */
    public function test_full_code_names_the_facility(): void
    {
        $this->asPars(function () {
            $ss = Subsystem::where('code', 'PH-14-SS-2201')->firstOrFail();

            $this->assertSame('PH-14-PL-01 / PH-14-SS-2201', $ss->fullCode());
        });
    }

    // -----------------------------------------------------------------------
    // integrity
    // -----------------------------------------------------------------------

    /**
     * A subsystem in plant A must not sit in an area belonging to plant B.
     * Nothing on screen would reveal it, but every roll-up would be wrong.
     */
    public function test_a_subsystem_cannot_sit_in_an_area_of_another_facility(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asPars(function () {
            $utility = Facility::where('code', 'PH-14-PL-02')->firstOrFail();
            $foreignArea = Area::where('code', 'AR-22')->firstOrFail(); // belongs to PL-01

            Subsystem::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->company('EPC-PARS')->id,
                'facility_id' => $utility->id,
                'area_id' => $foreignArea->id,
                'code' => 'PH-14-SS-9999',
                'name_en' => 'Cross-wired',
                'name_fa' => 'اتصال نادرست',
                'discipline' => 'PIPING',
            ]);
        });
    }

    public function test_deleting_an_area_keeps_its_subsystems(): void
    {
        $this->asPars(function () {
            $area = Area::where('code', 'AR-33')->firstOrFail();
            $before = Subsystem::where('area_id', $area->id)->pluck('code')->all();
            $this->assertNotEmpty($before, 'fixture must have a subsystem in this area');

            $area->delete();

            foreach ($before as $code) {
                $ss = Subsystem::where('code', $code)->first();
                $this->assertNotNull($ss, 'the subsystem survives its area');
                $this->assertNull($ss->area_id, 'and falls back to hanging off the facility');
                $this->assertNotNull($ss->facility_id);
            }
        });
    }

    public function test_deleting_a_facility_removes_its_subsystems(): void
    {
        $this->asPars(function () {
            $utility = Facility::where('code', 'PH-14-PL-02')->firstOrFail();

            $utility->delete();

            $this->assertSame(0, Subsystem::where('facility_id', $utility->id)->count());
            // and the other plant is untouched
            $this->assertSame(3, Subsystem::count());
        });
    }

    // -----------------------------------------------------------------------
    // FacilityKind — the value object
    // -----------------------------------------------------------------------

    public function test_pipeline_is_known_but_not_yet_selectable(): void
    {
        $this->assertContains(FacilityKind::PIPELINE, FacilityKind::all());
        $this->assertNotContains(
            FacilityKind::PIPELINE,
            FacilityKind::selectable(),
            'pipelines were deferred to a later phase; nothing may offer one yet',
        );
        $this->assertTrue(FacilityKind::isValid(FacilityKind::PIPELINE));
        $this->assertFalse(FacilityKind::isSelectable(FacilityKind::PIPELINE));
    }

    public function test_plant_is_selectable(): void
    {
        $this->assertTrue(FacilityKind::isSelectable(FacilityKind::PLANT));
        $this->assertTrue(FacilityKind::isSelectable(FacilityKind::UTILITY));
    }

    public function test_only_a_pipeline_is_addressed_by_stationing(): void
    {
        $this->assertTrue(FacilityKind::requiresStationing(FacilityKind::PIPELINE));

        foreach ([FacilityKind::PLANT, FacilityKind::OFFSITE, FacilityKind::MARINE, FacilityKind::UTILITY] as $kind) {
            $this->assertFalse(FacilityKind::requiresStationing($kind), $kind.' is bounded by area, not KP');
        }
    }

    public function test_the_database_refuses_an_unknown_kind(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->asPars(fn () => Facility::create([
            'project_id' => $this->project()->id,
            'company_id' => $this->company('EPC-PARS')->id,
            'code' => 'PH-14-PL-99',
            'name_en' => 'Nonsense',
            'name_fa' => 'بی‌معنا',
            'kind' => 'SPACE_STATION',
        ]));
    }

    public function test_kind_labels_are_bilingual(): void
    {
        foreach (FacilityKind::all() as $kind) {
            $en = FacilityKind::label($kind, 'en');
            $fa = FacilityKind::label($kind, 'fa');

            $this->assertNotSame('', $en);
            $this->assertNotSame('', $fa);
            $this->assertNotSame($en, $fa, $kind.' must actually be translated, not echoed');
        }
    }

    /**
     * A plant divides into areas; a pipeline into sections. One table, but the
     * word on the screen has to follow the kind.
     */
    public function test_the_intermediate_level_is_named_per_kind(): void
    {
        $this->assertSame('Area', FacilityKind::areaLabel(FacilityKind::PLANT, 'en'));
        $this->assertSame('Section', FacilityKind::areaLabel(FacilityKind::PIPELINE, 'en'));
        $this->assertSame('Berth', FacilityKind::areaLabel(FacilityKind::MARINE, 'en'));
        $this->assertSame('ناحیه', FacilityKind::areaLabel(FacilityKind::PLANT, 'fa'));
    }

    // -----------------------------------------------------------------------
    // addressing
    // -----------------------------------------------------------------------

    public function test_addressing_round_trips_as_a_structure(): void
    {
        $this->asPars(function () {
            $plant = Facility::where('code', 'PH-14-PL-01')->firstOrFail();

            $this->assertIsArray($plant->addressing);
            $this->assertSame('E 512400 / N 3318600', $plant->addressing['grid']);
        });
    }

    public function test_addressing_defaults_to_an_empty_structure(): void
    {
        $this->asPars(function () {
            $f = Facility::create([
                'project_id' => $this->project()->id,
                'company_id' => $this->company('EPC-PARS')->id,
                'code' => 'PH-14-PL-03',
                'name_en' => 'Flare area',
                'name_fa' => 'محوطه فلر',
                'kind' => FacilityKind::OFFSITE,
            ]);

            // Reading a missing key must give null, not a TypeError.
            $this->assertSame([], $f->refresh()->addressing);
            $this->assertNull($f->addressing['from_kp'] ?? null);
        });
    }

    // -----------------------------------------------------------------------
    // area display
    // -----------------------------------------------------------------------

    /**
     * Plant 3D carries Area and Unit as two separate tag subparts because one
     * area can host several process units. Flattening them on import would
     * lose that, so they are stored — and displayed — separately.
     */
    public function test_area_shows_its_unit_code_when_there_is_one(): void
    {
        $this->asPars(function () {
            $withUnit = Area::where('code', 'AR-22')->firstOrFail();
            $withoutUnit = Area::where('code', 'AR-33')->firstOrFail();

            $this->assertSame('AR-22 / U-2200', $withUnit->displayCode());
            $this->assertSame('AR-33', $withoutUnit->displayCode());
        });
    }

    // -----------------------------------------------------------------------
    // RLS
    // -----------------------------------------------------------------------

    /**
     * The breakdown is the map the whole project works from, not commercial
     * data: every participant reads it. That is the same call already made for
     * subsystems, and the opposite of requisitions.
     */
    public function test_every_participant_can_read_the_breakdown(): void
    {
        foreach (['NIOC-AFF', 'PMC-TEHRAN', 'MC-KARAN'] as $code) {
            $count = $this->tenant->runAs(
                $this->company($code)->id,
                fn () => Facility::count(),
                $this->project()->id,
            );

            $this->assertSame(2, $count, $code.' must be able to see the breakdown');
        }
    }

    public function test_a_non_participant_sees_no_facilities(): void
    {
        $outsider = $this->company('EPC-ARVAND');
        $project = $this->project();

        $count = $this->tenant->runAs($outsider->id, fn () => Facility::count(), $project->id);

        $this->assertSame(0, $count);
    }

    /**
     * Reads are open to participants; writes are not. The consultant must be
     * able to audit the breakdown without being able to redraw it.
     */
    public function test_a_participant_cannot_write_another_companys_facility(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $pmc = $this->company('PMC-TEHRAN');
        $pars = $this->company('EPC-PARS');
        $project = $this->project();

        $this->tenant->runAs(
            $pmc->id,
            fn () => Facility::create([
                'project_id' => $project->id,
                'company_id' => $pars->id, // not its own
                'code' => 'PH-14-PL-98',
                'name_en' => 'Smuggled',
                'name_fa' => 'قاچاقی',
                'kind' => FacilityKind::PLANT,
            ]),
            $project->id,
        );
    }

    // -----------------------------------------------------------------------
    // the migration's own backfill
    // -----------------------------------------------------------------------

    /**
     * The backfill had to disable RLS to see the rows it was fixing. If the
     * flags were not put back, every table would be readable by anyone —
     * silently, with the whole suite still green.
     */
    public function test_row_level_security_is_still_forced_after_the_migration(): void
    {
        foreach (['subsystems', 'facilities', 'areas'] as $table) {
            $flags = DB::selectOne(
                'select relrowsecurity as enabled, relforcerowsecurity as forced from pg_class where relname = ?',
                [$table],
            );

            $this->assertTrue((bool) $flags->enabled, $table.' must have RLS enabled');
            $this->assertTrue((bool) $flags->forced, $table.' must FORCE RLS, or the owner bypasses its own policies');
        }
    }
}
