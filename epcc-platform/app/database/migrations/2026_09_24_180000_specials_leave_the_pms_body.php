<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CORRECTION — user instruction, 2026-09-24:
 * «نیازی به ثبت special items در pms نیست» — special items do not need to be
 * recorded in the PMS.
 *
 * The previous migration gave `piping_class_items` a second row kind pointing
 * at the SP register, on the strength of ADR-008 §11.9's claim that "a class
 * body holds two kinds of row". That claim was OUR analysis of real PMS
 * documents, not a user decision. What the user actually decided was narrower
 * and is unchanged: SP numbers are unique across the project, and the number is
 * recorded **on the MTO and on the datasheet**. Neither of those is the PMS.
 *
 * So the register stays — MTO and datasheets both need it — and the PMS body
 * goes back to one kind of row: component type x qualifier x size.
 *
 * The lesson, recorded in HANDOFF §8: **before building from a design document,
 * check whether the claim came from the user or from our own reading.** §11.9
 * mixed both in one section and only the paragraphs headed "User decision" were
 * binding.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('DROP INDEX IF EXISTS piping_class_items_special_axis_unique');
        DB::statement('ALTER TABLE piping_class_items '
            .'DROP CONSTRAINT IF EXISTS piping_class_items_exactly_one_kind');
        DB::statement('ALTER TABLE piping_class_items '
            .'DROP CONSTRAINT IF EXISTS piping_class_items_says_something_check');

        // Nothing has shipped, but a body row that is only a special would be
        // left with no component type and no way to describe itself, so it is
        // removed rather than silently turned into a half row.
        DB::statement('DELETE FROM piping_class_items WHERE specialty_item_id IS NOT NULL');

        Schema::table('piping_class_items', function (Blueprint $t): void {
            $t->dropForeign(['specialty_item_id']);
            $t->dropColumn('specialty_item_id');
        });

        DB::statement('ALTER TABLE piping_class_items ALTER COLUMN component_type SET NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_items
            ADD CONSTRAINT piping_class_items_says_something_check
            CHECK (material_spec_code IS NOT NULL
                   OR NULLIF(TRIM(description_as_written), '') IS NOT NULL)
        SQL);
    }

    public function down(): void
    {
        Schema::table('piping_class_items', function (Blueprint $t): void {
            $t->uuid('specialty_item_id')->nullable();
            $t->foreign('specialty_item_id')->references('id')->on('specialty_items')
                ->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE piping_class_items ALTER COLUMN component_type DROP NOT NULL');
        DB::statement('ALTER TABLE piping_class_items '
            .'DROP CONSTRAINT IF EXISTS piping_class_items_says_something_check');
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_items
            ADD CONSTRAINT piping_class_items_exactly_one_kind
            CHECK ((component_type IS NOT NULL AND specialty_item_id IS NULL)
                OR (component_type IS NULL AND specialty_item_id IS NOT NULL))
        SQL);
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX piping_class_items_special_axis_unique
            ON piping_class_items (piping_class_id, specialty_item_id, dn)
            WHERE specialty_item_id IS NOT NULL
        SQL);
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_items
            ADD CONSTRAINT piping_class_items_says_something_check
            CHECK (specialty_item_id IS NOT NULL
                   OR material_spec_code IS NOT NULL
                   OR NULLIF(TRIM(description_as_written), '') IS NOT NULL)
        SQL);
    }
};
