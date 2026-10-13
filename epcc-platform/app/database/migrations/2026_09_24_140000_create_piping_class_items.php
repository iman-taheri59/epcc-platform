<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-008 §11.12 step 5 — the PMS BODY.
 *
 * The body of a piping class sheet is a list of items: for each component the
 * class permits, at each size, what to buy. §11.4 established the axis, and it
 * is NOT the six `rule_kind` values — those were a mixture of one component's
 * attribute, three qualifiers, one class-level policy and one header field.
 * The real axis is **component type x qualifier x size**, which
 * `component-templates.ref.json` has held all along and nothing consumed.
 *
 * Four decisions worth keeping in view.
 *
 * 1. UNIQUE NULLS NOT DISTINCT. One size carries several rows of the same
 *    component type — a flange sheet lists WN *and* BL, a valve list GATE
 *    *and* CHECK — so the key includes the qualifier. But most components have
 *    no qualifier, and in PostgreSQL two rows with `qualifier IS NULL` do not
 *    collide under a plain UNIQUE, so `PIPE` at DN 150 could be entered twice
 *    in silence. PG 15+ gives the exact fix and we are on 17.
 *
 * 2. THE MATERIAL IS A REFERENCE, and this is the row that finally consumes
 *    the material library for real. The header carries the class's base pipe
 *    material; here every component carries its own — A106 pipe, A105 flanges,
 *    A234 fittings — which is how a PMS actually reads.
 *
 * 3. BUT IT IS STILL TRANSCRIBED. `description_as_written` holds the source
 *    line verbatim, and an unresolvable material is recorded and reported, not
 *    refused. Same instruction as the header (§5al): the PMS comes from
 *    outside, and a transcriber must be able to finish the document.
 *
 * 4. NO INHERITANCE, NO DEFAULTS. A size absent from the body is absent from
 *    the class: the standing constraint is that a class states what it permits
 *    and silence means not permitted. Nothing here falls back to another size
 *    or another class.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piping_class_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('piping_class_id');

            // ---- the axis (§11.4) ------------------------------------------
            // A code from component-templates.ref.json. Not a foreign key: the
            // templates are a reference pack, not a table.
            $t->string('component_type', 32);
            // WN / BL / GATE / 90LR ... null where the template has no
            // qualifier axis, as PIPE does not.
            $t->string('qualifier', 32)->nullable();
            // Per size, not per band. ADR-008 §10: bands were amended away
            // because a size must never fall in two of them.
            $t->integer('dn');
            // The NPS the transcriber typed, kept because the user works in
            // NPS and DN is the storage key, not the language.
            $t->string('nps', 16)->nullable();

            // ---- what to buy ------------------------------------------------
            $t->string('material_group_code', 32)->nullable();
            $t->string('material_spec_code', 32)->nullable();
            $t->string('material_grade_key', 48)->nullable();
            $t->string('product_form', 32)->nullable();

            // 'ASME B36.10M', 'ASME B16.5', 'MSS SP-97'... a real MTO column.
            $t->string('dimensional_standard', 48)->nullable();
            $t->string('schedule', 16)->nullable();
            $t->string('pressure_rating', 16)->nullable();
            $t->string('end_type', 16)->nullable();
            $t->string('facing', 16)->nullable();

            // ---- transcription ---------------------------------------------
            // The source line, verbatim. The reference columns are what MTO
            // consumes; this is what the document said, and it is the only way
            // to audit a transcription afterwards.
            $t->text('description_as_written')->nullable();
            $t->string('source_ref', 128)->nullable();
            // Header note numbers this row cites, e.g. [1, 4].
            $t->jsonb('note_numbers')->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('piping_class_id')->references('id')->on('piping_classes')
                ->cascadeOnDelete();

            $t->index(['project_id', 'piping_class_id', 'component_type', 'dn']);
        });

        // The whole point of the key. Without NULLS NOT DISTINCT, PIPE at DN
        // 150 could be entered twice and neither the database nor a reader
        // would notice.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_items
            ADD CONSTRAINT piping_class_items_axis_unique
            UNIQUE NULLS NOT DISTINCT (piping_class_id, component_type, qualifier, dn)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_items
            ADD CONSTRAINT piping_class_items_dn_is_positive CHECK (dn > 0)
        SQL);

        // Same all-or-nothing ordering as the header: a spec cannot be looked
        // up without its group, and a grade without a spec is an orphan.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_items
            ADD CONSTRAINT piping_class_items_material_reference_check
            CHECK ((material_spec_code IS NULL OR material_group_code IS NOT NULL)
               AND (material_grade_key IS NULL OR material_spec_code IS NOT NULL))
        SQL);

        // A row that names neither a material nor a description says nothing
        // at all, and an MTO line generated from it would be blank.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_items
            ADD CONSTRAINT piping_class_items_says_something_check
            CHECK (material_spec_code IS NOT NULL
                   OR NULLIF(TRIM(description_as_written), '') IS NOT NULL)
        SQL);

        DB::statement('ALTER TABLE piping_class_items ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE piping_class_items FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY piping_class_items_read ON piping_class_items
            FOR SELECT USING (
                piping_class_items.project_id = current_setting('epcc.project_id', true)::uuid
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY piping_class_items_write ON piping_class_items
            FOR ALL USING (
                piping_class_items.project_id = current_setting('epcc.project_id', true)::uuid
                AND piping_class_items.company_id = current_setting('epcc.company_id', true)::uuid
            )
            WITH CHECK (
                piping_class_items.project_id = current_setting('epcc.project_id', true)::uuid
                AND piping_class_items.company_id = current_setting('epcc.company_id', true)::uuid
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('piping_class_items');
    }
};
