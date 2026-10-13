<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-008 §11.12 step 4 — the piping class HEADER.
 *
 * Until now a class carried three free-text columns and a string corrosion
 * allowance. A real PMS header carries considerably more, and §11.6 lists what
 * we had no home for. This gives those fields a home.
 *
 * Four things here are deliberate and worth reading before changing them.
 *
 * 1. THE MATERIAL STOPS BEING FREE TEXT (user instruction, 2026-09-23). It
 *    becomes a reference into the material library — group, spec, optional
 *    grade — because that library exists precisely to be consumed by PMS and
 *    MTO. But the PMS is TRANSCRIBED from an outside document, not authored
 *    here, so a transcriber who meets a material the library does not know
 *    must still be able to record the document faithfully. The typed text is
 *    therefore kept alongside the reference in `material_as_written`, and an
 *    unresolvable material raises a DISCREPANCY rather than a refusal (user
 *    instruction, 2026-09-24: write-time refusals become "record it, then
 *    raise a discrepancy").
 *
 * 2. CORROSION ALLOWANCE BECOMES A NUMBER. The string accepted "3", "3 mm"
 *    and "1/8 in" alike, and a B31.3 wall calculation cannot consume that.
 *    Millimetres, because the project's canonical length is metric. The
 *    matching rule kind is dropped in the same migration: the user confirmed a
 *    single class never carries a different allowance at different sizes, so
 *    a per-size rule for it was a shape with no referent.
 *
 * 3. FLUID SERVICE CATEGORY IS LOAD-BEARING, not decoration. It is B31.3's
 *    own axis and it is what explains why one class takes 5 % random
 *    radiography and another 100 %. Without it the NDT percentages that
 *    arrive in step 7 are unexplainable numbers.
 *
 * 4. THE NOTES GET A TABLE. A PMS header ends in numbered notes that body rows
 *    cite by number. Storing them as prose in a column would make "note (4)"
 *    unresolvable, so they are rows with a number unique within the class.
 */
return new class extends Migration
{
    /** B31.3 fluid service categories. The engine never invents one. */
    private const FLUID_SERVICE = [
        'NORMAL',
        'CATEGORY_D',
        'CATEGORY_M',
        'HIGH_PRESSURE',
        'SEVERE_CYCLIC',
    ];

    public function up(): void
    {
        Schema::table('piping_classes', function (Blueprint $t): void {
            // --- what the class is for -----------------------------------
            $t->string('service_en')->nullable();
            $t->string('service_fa')->nullable();
            $t->string('fluid_service_category', 20)->nullable();

            // --- the design envelope -------------------------------------
            // Celsius: the UI offers C and F, the engine works in Kelvin, and
            // Celsius is what a PMS header prints.
            $t->decimal('design_temp_min_c', 6, 1)->nullable();
            $t->decimal('design_temp_max_c', 6, 1)->nullable();
            $t->decimal('corrosion_allowance_mm', 5, 2)->nullable();

            // --- the material, as a reference and as written --------------
            $t->string('material_group_code', 32)->nullable();
            $t->string('material_spec_code', 32)->nullable();
            $t->string('material_grade_key', 48)->nullable();
            // The transcriber's own words. Kept even when the reference
            // resolves, because the source document is the authority on what
            // it says and a later reader must be able to see it.
            $t->string('material_as_written', 128)->nullable();

            // --- special requirements -------------------------------------
            // NACE MR0175, IBR, LTCS, stress relieved, HIC... an open list
            // that differs by project and owner, so a text array rather than
            // an enumeration we would be wrong about.
            $t->jsonb('special_requirements')->nullable();

            // --- B16.47 series ---------------------------------------------
            // Above NPS 24 a class must say which series it buys, because
            // Series A and B are different flanges at the same size. Null
            // below NPS 26, where B16.5 is the only answer.
            $t->string('default_flange_series', 1)->nullable();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE piping_classes
            ADD CONSTRAINT piping_classes_fluid_service_category_check
            CHECK (fluid_service_category IS NULL
                   OR fluid_service_category IN
                      ('NORMAL','CATEGORY_D','CATEGORY_M','HIGH_PRESSURE','SEVERE_CYCLIC'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE piping_classes
            ADD CONSTRAINT piping_classes_default_flange_series_check
            CHECK (default_flange_series IS NULL OR default_flange_series IN ('A','B'))
        SQL);

        // A maximum below a minimum is not a narrow envelope, it is a typo.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_classes
            ADD CONSTRAINT piping_classes_design_temp_order_check
            CHECK (design_temp_min_c IS NULL
                   OR design_temp_max_c IS NULL
                   OR design_temp_min_c <= design_temp_max_c)
        SQL);

        // A negative corrosion allowance is metal appearing over time.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_classes
            ADD CONSTRAINT piping_classes_corrosion_allowance_check
            CHECK (corrosion_allowance_mm IS NULL OR corrosion_allowance_mm >= 0)
        SQL);

        // A spec without its group cannot be looked up, and a grade without a
        // spec is an orphan. The reference is all-or-nothing in that order.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_classes
            ADD CONSTRAINT piping_classes_material_reference_check
            CHECK ((material_spec_code IS NULL OR material_group_code IS NOT NULL)
               AND (material_grade_key IS NULL OR material_spec_code IS NOT NULL))
        SQL);

        // ------------------------------------------------------------------
        // Carry the old free-text material across before it is dropped.
        // ------------------------------------------------------------------
        DB::statement('UPDATE piping_classes SET material_as_written = material '
            .'WHERE material IS NOT NULL');

        // The old corrosion allowance was a string. Anything that parses as a
        // plain number is carried over; anything else is left null rather than
        // guessed, and the original survives in material_as_written's sibling
        // below so nothing is lost silently.
        Schema::table('piping_classes', function (Blueprint $t): void {
            $t->string('corrosion_allowance_as_written', 32)->nullable();
        });
        DB::statement('UPDATE piping_classes '
            .'SET corrosion_allowance_as_written = corrosion_allowance '
            .'WHERE corrosion_allowance IS NOT NULL');
        DB::statement(<<<'SQL'
            UPDATE piping_classes
            SET corrosion_allowance_mm = corrosion_allowance::numeric
            WHERE corrosion_allowance ~ '^[0-9]+(\.[0-9]+)?$'
        SQL);

        Schema::table('piping_classes', function (Blueprint $t): void {
            $t->dropColumn(['material', 'corrosion_allowance']);
        });

        // ------------------------------------------------------------------
        // Numbered notes. Body rows cite these by number, so the number is the
        // key inside a class and must be stable.
        // ------------------------------------------------------------------
        Schema::create('piping_class_notes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('piping_class_id');

            $t->unsignedSmallInteger('number');
            $t->text('text_en')->nullable();
            $t->text('text_fa')->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('piping_class_id')->references('id')->on('piping_classes')
                ->cascadeOnDelete();

            $t->unique(['piping_class_id', 'number']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_notes
            ADD CONSTRAINT piping_class_notes_number_check CHECK (number >= 1)
        SQL);

        // A note with no text in either language is a number with nothing
        // behind it, and a body row citing it would say nothing.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_notes
            ADD CONSTRAINT piping_class_notes_has_text_check
            CHECK (COALESCE(NULLIF(TRIM(text_en), ''), NULLIF(TRIM(text_fa), '')) IS NOT NULL)
        SQL);

        // ------------------------------------------------------------------
        // RLS, matching the piping tables it hangs off.
        // ------------------------------------------------------------------
        DB::statement('ALTER TABLE piping_class_notes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE piping_class_notes FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY piping_class_notes_read ON piping_class_notes
            FOR SELECT USING (
                piping_class_notes.project_id = current_setting('epcc.project_id', true)::uuid
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY piping_class_notes_write ON piping_class_notes
            FOR ALL USING (
                piping_class_notes.project_id = current_setting('epcc.project_id', true)::uuid
                AND piping_class_notes.company_id = current_setting('epcc.company_id', true)::uuid
            )
            WITH CHECK (
                piping_class_notes.project_id = current_setting('epcc.project_id', true)::uuid
                AND piping_class_notes.company_id = current_setting('epcc.company_id', true)::uuid
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('piping_class_notes');

        Schema::table('piping_classes', function (Blueprint $t): void {
            $t->string('material', 64)->nullable();
            $t->string('corrosion_allowance', 16)->nullable();
        });
        DB::statement('UPDATE piping_classes SET material = material_as_written');
        DB::statement('UPDATE piping_classes '
            .'SET corrosion_allowance = corrosion_allowance_as_written');

        foreach ([
            'piping_classes_fluid_service_category_check',
            'piping_classes_default_flange_series_check',
            'piping_classes_design_temp_order_check',
            'piping_classes_corrosion_allowance_check',
            'piping_classes_material_reference_check',
        ] as $c) {
            DB::statement("ALTER TABLE piping_classes DROP CONSTRAINT IF EXISTS {$c}");
        }

        Schema::table('piping_classes', function (Blueprint $t): void {
            $t->dropColumn([
                'service_en', 'service_fa', 'fluid_service_category',
                'design_temp_min_c', 'design_temp_max_c', 'corrosion_allowance_mm',
                'material_group_code', 'material_spec_code', 'material_grade_key',
                'material_as_written', 'corrosion_allowance_as_written',
                'special_requirements', 'default_flange_series',
            ]);
        });
    }
};
