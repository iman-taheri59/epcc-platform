<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-008 §11.9 / §11.12 step 6 — the speciality (SP) register.
 *
 * SP items break the component axis DELIBERATELY. A pig trap is not one of our
 * 22 templates and must never become one: every project invents new specials,
 * and widening an enum to admit a new case is a trap this repo has already been
 * caught by. So a class body holds two kinds of row —
 *
 *   standard : component type x qualifier x size -> material library reference
 *   special  : SP number x size                  -> SP register reference
 *
 * — and this migration builds the register plus the second row kind.
 *
 * USER DECISIONS ENCODED HERE (2026-09-24):
 *
 *   - SP numbers are **unique across the whole project**. One register,
 *     referenced by many classes. Hence UNIQUE (project_id, sp_number) rather
 *     than anything narrower.
 *
 *   - The SP number is recorded **both on the MTO and on the datasheet** — one
 *     number, two uses. `datasheet_document_id` is that second use.
 *
 *   - SP numbers are **validated, never allocated.** The standing withdrawal of
 *     automatic numbering covered equipment tags and line numbers only, but a
 *     PMS is transcribed rather than authored, so the number is born in the
 *     client's document either way. Nothing here generates one.
 *
 * DELIBERATELY NOT BUILT: the tagged instance. §11.9 says a tagged special
 * acquires two identities — SP-014 (what it is) and V-2301 (which one it is) —
 * and that "which register owns which must be settled before build". It has not
 * been settled, so this register holds the SPECIFICATION only. `is_tagged`
 * records that instances will exist; where they live is still open, and no
 * column here presumes an answer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('specialty_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');

            // 'SP-014'. A label from the client's document, never parsed and
            // never generated - the same discipline as the piping class code.
            $t->string('sp_number', 32);

            $t->string('description_en')->nullable();
            $t->string('description_fa')->nullable();

            // A pig trap, a restriction orifice, a steam trap, a strainer...
            // free text ON PURPOSE. An enumeration here would have to grow
            // every time a project invents a special, which is the trap SP
            // items exist to avoid.
            $t->string('kind_as_written', 64)->nullable();

            // Whether instances of this special carry their own tag. Six
            // component templates are isTagged and specials split the same
            // way; a restriction orifice is tagged, a strainer element is not.
            $t->boolean('is_tagged')->default(false);

            // The datasheet. The user's instruction is that the SP number
            // appears on the MTO *and* the datasheet, so the register is where
            // those two meet.
            $t->uuid('datasheet_document_id')->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('datasheet_document_id')->references('id')->on('documents')
                ->nullOnDelete();

            // The user's decision, exactly: unique across the whole project.
            $t->unique(['project_id', 'sp_number']);
        });

        // An SP number with no content is a reference to nothing, and an MTO
        // line citing it would price a blank.
        DB::statement(<<<'SQL'
            ALTER TABLE specialty_items
            ADD CONSTRAINT specialty_items_sp_number_not_blank
            CHECK (NULLIF(TRIM(sp_number), '') IS NOT NULL)
        SQL);

        DB::statement('ALTER TABLE specialty_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE specialty_items FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY specialty_items_read ON specialty_items
            FOR SELECT USING (
                specialty_items.project_id = current_setting('epcc.project_id', true)::uuid
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY specialty_items_write ON specialty_items
            FOR ALL USING (
                specialty_items.project_id = current_setting('epcc.project_id', true)::uuid
                AND specialty_items.company_id = current_setting('epcc.company_id', true)::uuid
            )
            WITH CHECK (
                specialty_items.project_id = current_setting('epcc.project_id', true)::uuid
                AND specialty_items.company_id = current_setting('epcc.company_id', true)::uuid
            )
        SQL);

        // ------------------------------------------------------------------
        // The second row kind in the body.
        // ------------------------------------------------------------------
        Schema::table('piping_class_items', function (Blueprint $t): void {
            $t->uuid('specialty_item_id')->nullable();
            $t->foreign('specialty_item_id')->references('id')->on('specialty_items')
                ->cascadeOnDelete();
        });

        // component_type was NOT NULL because every row was a standard row.
        // A special row has no component type at all - that is the point.
        DB::statement('ALTER TABLE piping_class_items ALTER COLUMN component_type DROP NOT NULL');

        // Exactly one kind. A row that is both is two rows wearing one key; a
        // row that is neither prices nothing.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_items
            ADD CONSTRAINT piping_class_items_exactly_one_kind
            CHECK ((component_type IS NOT NULL AND specialty_item_id IS NULL)
                OR (component_type IS NULL AND specialty_item_id IS NOT NULL))
        SQL);

        // A special row keys on SP number x size, so the same special may not
        // be listed twice at one size in one class.
        //
        // A PARTIAL INDEX, not a table constraint. Written first as
        // `UNIQUE NULLS NOT DISTINCT (piping_class_id, specialty_item_id, dn)`
        // and that was wrong in a way worth recording: standard rows carry
        // `specialty_item_id IS NULL`, so NULLS NOT DISTINCT made every
        // standard row at one size collide with every other - a class could
        // hold a weld-neck flange at DN 150 or a blind, but never both. The
        // very case §11.4 built the qualifier key for. Caught by
        // PipingClassBodyTest::test_one_size_carries_several_rows_of_the_same_component.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX piping_class_items_special_axis_unique
            ON piping_class_items (piping_class_id, specialty_item_id, dn)
            WHERE specialty_item_id IS NOT NULL
        SQL);

        // The "says something" CHECK was written when every row carried a
        // material. A special row carries neither a material nor, necessarily,
        // prose: it points at the register, and the register says what it is.
        DB::statement('ALTER TABLE piping_class_items '
            .'DROP CONSTRAINT IF EXISTS piping_class_items_says_something_check');
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_items
            ADD CONSTRAINT piping_class_items_says_something_check
            CHECK (specialty_item_id IS NOT NULL
                   OR material_spec_code IS NOT NULL
                   OR NULLIF(TRIM(description_as_written), '') IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS piping_class_items_special_axis_unique');

        foreach ([
            'piping_class_items_exactly_one_kind',
            'piping_class_items_says_something_check',
        ] as $c) {
            DB::statement("ALTER TABLE piping_class_items DROP CONSTRAINT IF EXISTS {$c}");
        }

        Schema::table('piping_class_items', function (Blueprint $t): void {
            $t->dropForeign(['specialty_item_id']);
            $t->dropColumn('specialty_item_id');
        });

        DB::statement("DELETE FROM piping_class_items WHERE component_type IS NULL");
        DB::statement('ALTER TABLE piping_class_items ALTER COLUMN component_type SET NOT NULL');
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_items
            ADD CONSTRAINT piping_class_items_says_something_check
            CHECK (material_spec_code IS NOT NULL
                   OR NULLIF(TRIM(description_as_written), '') IS NOT NULL)
        SQL);

        Schema::dropIfExists('specialty_items');
    }
};
