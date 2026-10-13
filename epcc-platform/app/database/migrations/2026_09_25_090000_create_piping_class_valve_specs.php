<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MANUAL (non-automatic) VALVES in the PMS — ADR-008 §11.9.
 *
 * **User decision: non-automatic valves are to be added.** With that comes the
 * discriminator the user set: *if it has an actuator it is a datasheet; if it
 * does not, it is a PMS row.* That single sentence decides this whole table's
 * shape, and it is why there is **no actuator column here and no fail-safe
 * column** — not "nullable", not "deferred", absent. The vocabulary of
 * `operator` is fenced by a CHECK to the four manual operators, so the schema
 * itself cannot hold an actuated valve. An MOV is a datasheet and belongs to a
 * register this table has no opinion about.
 *
 * WHY A SIDE TABLE AND NOT SIX MORE COLUMNS ON `piping_class_items`.
 * The body table is the axis — which component, at which size, made of what.
 * A valve then carries a second layer that no other component has: a trim, a
 * construction standard and an operator. Put on the body table those are four
 * columns that are NULL on every pipe, flange, elbow and gasket row in the
 * system, plus a CHECK tying each of them to `component_type = 'VALVE'`. One
 * row per valve row, keyed 1:1, says the same thing without the litter.
 *
 * WHAT THIS FINALLY CONSUMES. Two assets were built and never wired:
 *
 *  - `VALVE_TRIM` (materials library, 6 specs / 43 trims) — named in HANDOFF
 *    as "the third built-and-unwired asset". `trim_standard` + `trim_code`
 *    point into it.
 *  - `actuators.ref.json` / `ValveActuators` — built for datasheets, never
 *    used. Its `travels` map (QUARTER_TURN: ball/butterfly/plug, MULTI_TURN:
 *    gate/globe/needle/diaphragm) is exactly what decides whether a lever or a
 *    handwheel is the right operator, so the manual half of that pack is the
 *    authority for `operator` rather than a second list typed in here.
 *
 * OPERATOR IS PER SIZE, which is why it lives on a per-size row and not on the
 * class header: a 2" ball valve takes a lever and a 12" one cannot, because the
 * torque passes what a person can apply. The row is already per size, so this
 * costs nothing structurally.
 *
 * STILL TRANSCRIBED (§11.10). Everything here is nullable and everything
 * questionable is a finding from `ManualValves`, not a refusal. The one thing
 * the schema does refuse is an operator outside the manual four — and that is
 * not a judgement about the document, it is the boundary of the module.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piping_class_valve_specs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');

            // 1:1 with the body row. The valve type, size and body material
            // are NOT repeated here - they are the body row's own columns
            // (qualifier, dn, material_*) and duplicating them would create
            // two answers to "what valve is this".
            $t->uuid('piping_class_item_id')->unique();

            // 'API 600', 'API 602', 'API 6D', 'BS 1868'... how the valve is
            // BUILT. Distinct from the body row's dimensional_standard, which
            // for a valve is the face-to-face standard (ASME B16.10). A PMS
            // valve line normally carries both and they are not the same
            // claim.
            $t->string('construction_standard', 32)->nullable();

            // Into VALVE_TRIM: standard = API600/API623/API594/API608/API609/
            // API599, code = the trim number or material ('8', 'PTFE'). Not a
            // foreign key - the material library is a reference pack, same as
            // component types on the body row.
            $t->string('trim_standard', 16)->nullable();
            $t->string('trim_code', 16)->nullable();

            // HANDWHEEL / LEVER / GEAR / CHAINWHEEL. Fenced below.
            $t->string('operator', 24)->nullable();

            // The source line for this layer, when the document gives the
            // valve its own descriptive line ("GATE VALVE, BOLTED BONNET,
            // OS&Y, API 600, TRIM 8, GEAR OPERATED ABOVE 8 INCH").
            $t->text('description_as_written')->nullable();

            $t->timestampsTz();

            $t->foreign('piping_class_item_id')
                ->references('id')->on('piping_class_items')
                ->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();

            $t->index(['project_id', 'operator']);
        });

        // THE BOUNDARY OF THE MODULE, in the schema rather than in a comment.
        // The user's discriminator is that an actuated valve is a datasheet,
        // so the four manual operators are the whole vocabulary. PNEUMATIC,
        // ELECTRIC and HYDRAULIC exist in actuators.ref.json and cannot be
        // written here.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_valve_specs
            ADD CONSTRAINT piping_class_valve_specs_operator_is_manual
            CHECK (operator IS NULL OR operator IN
                ('HANDWHEEL', 'LEVER', 'GEAR', 'CHAINWHEEL'))
        SQL);

        // A trim code without its standard cannot be resolved - 'Trim 8' means
        // nothing until you know it is API 600's chart and not API 623's, and
        // both charts contain an entry numbered 8.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_valve_specs
            ADD CONSTRAINT piping_class_valve_specs_trim_pair_check
            CHECK ((trim_code IS NULL) = (trim_standard IS NULL))
        SQL);

        // A spec row that adds nothing to its body row is noise: it would make
        // "does this valve have a spec" answer yes while answering nothing.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_valve_specs
            ADD CONSTRAINT piping_class_valve_specs_says_something_check
            CHECK (construction_standard IS NOT NULL
                   OR trim_standard IS NOT NULL
                   OR operator IS NOT NULL
                   OR NULLIF(TRIM(description_as_written), '') IS NOT NULL)
        SQL);

        DB::statement('ALTER TABLE piping_class_valve_specs ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE piping_class_valve_specs FORCE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY piping_class_valve_specs_read ON piping_class_valve_specs
            FOR SELECT USING (
                piping_class_valve_specs.project_id = current_setting('epcc.project_id', true)::uuid
            )
        SQL);
        DB::statement(<<<'SQL'
            CREATE POLICY piping_class_valve_specs_write ON piping_class_valve_specs
            FOR ALL USING (
                piping_class_valve_specs.project_id = current_setting('epcc.project_id', true)::uuid
                AND piping_class_valve_specs.company_id = current_setting('epcc.company_id', true)::uuid
            )
            WITH CHECK (
                piping_class_valve_specs.project_id = current_setting('epcc.project_id', true)::uuid
                AND piping_class_valve_specs.company_id = current_setting('epcc.company_id', true)::uuid
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('piping_class_valve_specs');
    }
};
