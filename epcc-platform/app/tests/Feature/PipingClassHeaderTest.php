<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PipingClass;
use App\Models\PipingClassNote;
use App\Models\Project;
use App\Services\PipingClassHeader;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The piping class HEADER — ADR-008 §11.12 step 4.
 *
 * The governing rule under test is the user's: the PMS is transcribed from an
 * outside document, so a header that disagrees with our references is RECORDED
 * and then reported, never refused. Most of this file exists to pin that down,
 * because the natural instinct when writing a validator is to refuse.
 */
final class PipingClassHeaderTest extends TestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate:fresh', ['--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\EpccDemoSeeder', '--force' => true]);
        Artisan::call('db:seed', ['--class' => 'Database\\Seeders\\AuthSeeder', '--force' => true]);

        $this->project = app(TenantContext::class)->forIdentityLookup(
            fn (): Project => Project::where('code', 'PH-14')->firstOrFail(),
        );
    }

    private function header(): PipingClassHeader
    {
        return app(PipingClassHeader::class);
    }

    /** Re-read a row inside the tenant binding; RLS hides it outside one. */
    private function reread(PipingClass $class): PipingClass
    {
        return app(TenantContext::class)->runAs(
            $this->project->owner_company_id,
            fn (): PipingClass => PipingClass::findOrFail($class->id),
            $this->project->id,
        );
    }

    /** @param array<string,mixed> $attrs */
    private function makeClass(array $attrs = []): PipingClass
    {
        $project = $this->project;

        return app(TenantContext::class)->runAs(
            $project->owner_company_id,
            fn (): PipingClass => PipingClass::create(array_merge([
                'project_id' => $project->id,
                'company_id' => $project->owner_company_id,
                'code' => 'A1A-'.Str::random(5),
            ], $attrs)),
            $project->id,
        );
    }

    // ---------------------------------------------- record, never refuse

    public function test_a_material_the_library_does_not_know_is_still_recorded(): void
    {
        // The document is the authority on what the document says. If the
        // transcriber cannot enter it, the project's own PMS cannot be held by
        // the system built to hold it.
        $class = $this->makeClass([
            'material_as_written' => 'ASTM A999 Gr. ZZ (a spec we do not carry)',
        ]);

        self::assertSame(
            'ASTM A999 Gr. ZZ (a spec we do not carry)',
            $this->reread($class)->material_as_written,
        );
    }

    public function test_an_unknown_material_raises_a_discrepancy_rather_than_silence(): void
    {
        $class = $this->makeClass(['material_as_written' => 'Unobtainium 400']);

        $codes = array_column($this->header()->findings($class), 'code');

        self::assertContains(PipingClassHeader::MATERIAL_UNRESOLVED, $codes);
        self::assertFalse(
            $this->header()->isConsumable($class),
            'MTO must not order from prose.'
        );
    }

    public function test_a_resolved_material_produces_no_material_finding(): void
    {
        $class = $this->makeClass([
            'material_group_code' => 'PIPE',
            'material_spec_code' => 'A106',
            'material_grade_key' => 'B',
            'material_as_written' => 'ASTM A106 Gr. B',
        ]);

        $material = array_filter(
            $this->header()->findings($class),
            static fn (array $f): bool => $f['field'] === 'material',
        );

        self::assertSame([], $material, 'A106 Gr. B is in the library.');
        self::assertTrue($this->header()->isConsumable($class));
    }

    public function test_the_transcribers_words_survive_a_successful_resolution(): void
    {
        // The reference is what MTO consumes; the prose is what the document
        // said. Losing the prose loses the ability to audit the transcription.
        $class = $this->makeClass([
            'material_group_code' => 'PIPE',
            'material_spec_code' => 'A106',
            'material_grade_key' => 'B',
            'material_as_written' => 'A106 GR.B SMLS',
        ]);

        self::assertTrue($class->hasResolvedMaterial());
        self::assertSame('A106 GR.B SMLS', $class->materialLabel());
    }

    // ---------------------------------------------------- what is refused

    public function test_a_maximum_design_temperature_below_the_minimum_is_refused(): void
    {
        // No document contains this. It is arithmetic, not judgement, so it is
        // a CHECK constraint rather than a finding.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->makeClass([
            'design_temp_min_c' => 120.0,
            'design_temp_max_c' => -20.0,
        ]);
    }

    public function test_a_negative_corrosion_allowance_is_refused(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->makeClass(['corrosion_allowance_mm' => -1.5]);
    }

    public function test_a_grade_without_its_spec_is_refused(): void
    {
        // A grade key alone cannot be looked up, so it is not a partial
        // record, it is an unusable one.
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->makeClass(['material_grade_key' => 'B']);
    }

    // ------------------------------------------------- corrosion allowance

    public function test_a_corrosion_allowance_is_a_number_not_a_string(): void
    {
        $class = $this->makeClass(['corrosion_allowance_mm' => 3.0]);

        self::assertSame(3.0, $this->reread($class)->corrosion_allowance_mm);
    }

    public function test_an_unreadable_allowance_is_kept_and_reported(): void
    {
        // "1/8 in" is what the document says. It is recorded verbatim and
        // flagged, not dropped and not guessed at.
        $class = $this->makeClass(['corrosion_allowance_as_written' => '1/8 in']);

        $codes = array_column($this->header()->findings($class), 'code');

        self::assertContains(PipingClassHeader::CORROSION_ALLOWANCE_UNREADABLE, $codes);
        self::assertSame('1/8 in', $this->reread($class)->corrosion_allowance_as_written);
    }

    public function test_a_missing_allowance_is_incomplete_not_a_discrepancy(): void
    {
        // Nothing disagrees with anything; the header simply is not finished.
        $class = $this->makeClass();

        $f = $this->findingFor($class, PipingClassHeader::CORROSION_ALLOWANCE_ABSENT);

        self::assertSame(PipingClassHeader::SEVERITY_INCOMPLETE, $f['severity']);
    }

    // -------------------------------------------------------- flange series

    public function test_a_class_above_nps_24_must_say_which_b1647_series(): void
    {
        // Series A and B are different flanges at the same size, so a 30 inch
        // take-off cannot be priced until the class chooses.
        $class = $this->makeClass(['pressure_rating' => '300#']);

        $codes = array_column($this->header()->findings($class), 'code');

        self::assertContains(PipingClassHeader::SERIES_NOT_STATED, $codes);
    }

    public function test_stating_the_series_settles_it(): void
    {
        $class = $this->makeClass([
            'pressure_rating' => '300#',
            'default_flange_series' => 'A',
        ]);

        $codes = array_column($this->header()->findings($class), 'code');

        self::assertNotContains(PipingClassHeader::SERIES_NOT_STATED, $codes);
    }

    public function test_a_series_other_than_a_or_b_is_refused(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->makeClass(['default_flange_series' => 'C']);
    }

    // ------------------------------------------------------- fluid service

    public function test_fluid_service_is_constrained_to_the_b313_categories(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->makeClass(['fluid_service_category' => 'CATEGORY_Q']);
    }

    public function test_a_missing_fluid_service_is_reported_because_ndt_depends_on_it(): void
    {
        $class = $this->makeClass();

        $codes = array_column($this->header()->findings($class), 'code');

        self::assertContains(PipingClassHeader::FLUID_SERVICE_ABSENT, $codes);
    }

    // --------------------------------------------------------------- notes

    public function test_notes_are_numbered_rows_so_a_body_row_can_cite_one(): void
    {
        $class = $this->makeClass();

        app(TenantContext::class)->runAs(
            $this->project->owner_company_id,
            function () use ($class): void {
                foreach ([1 => 'Stress relieve all welds.', 2 => 'NACE MR0175 applies.'] as $n => $text) {
                    PipingClassNote::create([
                        'project_id' => $class->project_id,
                        'company_id' => $class->company_id,
                        'piping_class_id' => $class->id,
                        'number' => $n,
                        'text_en' => $text,
                    ]);
                }
            },
            $this->project->id,
        );

        $notes = app(TenantContext::class)->runAs(
            $this->project->owner_company_id,
            fn () => $class->notes()->get(),
            $this->project->id,
        );

        self::assertCount(2, $notes);
        self::assertSame(1, $notes[0]->number);
        self::assertSame('NACE MR0175 applies.', $notes[1]->text('en'));
    }

    public function test_the_same_note_number_cannot_be_used_twice_in_one_class(): void
    {
        $class = $this->makeClass();

        $this->expectException(\Illuminate\Database\QueryException::class);

        app(TenantContext::class)->runAs(
            $this->project->owner_company_id,
            function () use ($class): void {
                foreach ([1, 1] as $n) {
                    PipingClassNote::create([
                        'project_id' => $class->project_id,
                        'company_id' => $class->company_id,
                        'piping_class_id' => $class->id,
                        'number' => $n,
                        'text_en' => 'Anything.',
                    ]);
                }
            },
            $this->project->id,
        );
    }

    public function test_a_note_with_no_text_in_any_language_is_refused(): void
    {
        // A number with nothing behind it makes every body row citing it say
        // nothing at all.
        $class = $this->makeClass();

        $this->expectException(\Illuminate\Database\QueryException::class);

        app(TenantContext::class)->runAs(
            $this->project->owner_company_id,
            fn () => PipingClassNote::create([
                'project_id' => $class->project_id,
                'company_id' => $class->company_id,
                'piping_class_id' => $class->id,
                'number' => 9,
                'text_en' => '   ',
            ]),
            $this->project->id,
        );
    }

    // ------------------------------------------------------ the old columns

    public function test_the_free_text_material_column_is_gone(): void
    {
        // The user's instruction: the material library exists to be consumed
        // by PMS and MTO, so this must not be free text any more.
        $columns = array_column(DB::select(
            "SELECT column_name FROM information_schema.columns
             WHERE table_name = 'piping_classes'"
        ), 'column_name');

        self::assertNotContains('material', $columns);
        self::assertNotContains('corrosion_allowance', $columns);
        self::assertContains('material_group_code', $columns);
        self::assertContains('corrosion_allowance_mm', $columns);
    }

    // ------------------------------------------------------------ resolving

    public function test_a_spec_in_exactly_one_group_resolves_its_group(): void
    {
        // The library is keyed by PRODUCT FORM, not by metallurgy: A106 lives in
        // the PIPE group. A class header's material is therefore its BASE (pipe)
        // material; flange and fitting specs arrive with the body in step 5.
        self::assertSame('PIPE', $this->header()->groupForSpec('A106'));
    }

    public function test_an_unknown_spec_resolves_to_nothing_rather_than_a_guess(): void
    {
        self::assertNull($this->header()->groupForSpec('A999'));
    }

    /** @return array{code:string,severity:string,field:string,message:string} */
    private function findingFor(PipingClass $class, string $code): array
    {
        foreach ($this->header()->findings($class) as $f) {
            if ($f['code'] === $code) {
                return $f;
            }
        }

        self::fail("No finding {$code}.");
    }

    // ------------------------------------- B31.3 slice 1: the fluid service

    public function test_a_category_d_class_that_breaks_b313s_own_bounds_is_flagged(): void
    {
        // B31.3 300.2(a) bounds Category D at 1 035 kPa gauge and 186 C. A
        // Class 300 carbon steel class designed to 250 C is over the
        // temperature bound - it is simply not Category D, whatever the sheet
        // says, and the examination extent turns on which of the two is wrong.
        $class = $this->makeClass([
            'pressure_rating' => '300#',
            'fluid_service_category' => 'CATEGORY_D',
            'design_temp_max_c' => 250,
            'material_group_code' => 'PIPE',
            'material_spec_code' => 'A106',
            'material_grade_key' => 'B',
        ]);

        $f = $this->findingFor($class, PipingClassHeader::CATEGORY_D_BOUNDS_EXCEEDED);

        self::assertSame('DISCREPANCY', $f['severity']);
        self::assertStringContainsString('186', $f['message']);
    }

    public function test_an_ordinary_category_d_class_raises_nothing_about_its_service(): void
    {
        // Class 150 carbon steel at 100 C: 19.6 bar and 100 C, both inside the
        // bounds. The check fires on the contradiction, not on the category.
        $class = $this->makeClass([
            'pressure_rating' => '150#',
            'fluid_service_category' => 'CATEGORY_D',
            'design_temp_max_c' => 100,
            'material_group_code' => 'PIPE',
            'material_spec_code' => 'A106',
            'material_grade_key' => 'B',
        ]);

        $codes = array_column($this->header()->findings($class), 'code');

        self::assertNotContains(PipingClassHeader::CATEGORY_D_BOUNDS_EXCEEDED, $codes);
    }

    public function test_no_other_fluid_service_is_second_guessed(): void
    {
        // Every service but Category D is a judgement the owner or the
        // engineering design makes. A Normal class at 500 C is unusual and it
        // is not this engine's business.
        $class = $this->makeClass([
            'pressure_rating' => '300#',
            'fluid_service_category' => 'NORMAL',
            'design_temp_max_c' => 500,
            'material_group_code' => 'PIPE',
            'material_spec_code' => 'A106',
            'material_grade_key' => 'B',
        ]);

        $codes = array_column($this->header()->findings($class), 'code');

        self::assertNotContains(PipingClassHeader::CATEGORY_D_BOUNDS_EXCEEDED, $codes);
    }

    public function test_severe_cyclic_left_the_service_column_for_a_flag_of_its_own(): void
    {
        // It was one of five values in fluid_service_category until B31.3 was
        // read. Now it is a boolean beside the service, so "Category M under
        // severe cyclic conditions" is recordable - and it was not.
        $class = $this->makeClass([
            'fluid_service_category' => 'CATEGORY_M',
            'severe_cyclic_conditions' => true,
        ]);

        $fresh = $this->reread($class);

        self::assertSame('CATEGORY_M', $fresh->fluid_service_category);
        self::assertTrue($fresh->severe_cyclic_conditions);
    }

    public function test_the_old_severe_cyclic_value_is_no_longer_a_service(): void
    {
        // The CHECK constraint carries the correction into the database, so an
        // importer cannot put the condition back into the service column.
        $this->expectException(QueryException::class);

        $this->makeClass(['fluid_service_category' => 'SEVERE_CYCLIC']);
    }
}
