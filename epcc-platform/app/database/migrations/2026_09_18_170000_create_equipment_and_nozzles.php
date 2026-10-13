<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-006 phase 3b / ADR-010 section 3 — equipment and nozzles.
 *
 * THE INSIGHT THAT MAKES THIS CHEAP
 * ---------------------------------
 * A nozzle is a PORT OWNER, exactly like a component. Once that is accepted,
 * "how does a joint terminate at equipment?" dissolves: a pipe-to-nozzle joint
 * is just two ports, and `joints` needs no change at all. Phase 2 left the seam
 * ready - `ports.owner_kind`, `ports.nozzle_id` and `ports_exactly_one_owner`
 * already exist - so the nozzle side arrives as an INSERT, not a migration of
 * the joint table. This migration only has to add the FK it could not add then.
 *
 * TAGGING: TWO MODES, CHOSEN PER PROJECT (the user's ruling, 2026-09-18)
 * ---------------------------------------------------------------------
 * The user's answer to "imported-and-validated, or allocated?" was BOTH,
 * selectable per project - the same posture as ISO vs AWP. So:
 *
 *   VALIDATE  the engineering contractor's tag list is authoritative. Tags are
 *             typed or imported; the system checks the pattern and refuses a
 *             duplicate, but never invents a number.
 *   ALLOCATE  the system issues the next sequence for a (unit, type) pair,
 *             the way piping lines already work.
 *
 * Both modes share one rule, which is the user's own analysis: the FULL tag is
 * unique per PROJECT, and the unit code is part of the tag. `11-P-101A` and
 * `21-P-101A` are different pumps; a bare `P-101A` is ambiguous across units
 * and is what gets the wrong pump hydrotested.
 *
 * WHY THE UNIQUE INDEX IS ON THE COMPOSED STRING
 * ----------------------------------------------
 * ADR-004 established that composed codes are NOT stored - they drift on
 * rename. A tag number is the exception, and deliberately so: it is not a
 * derived display of a hierarchy, it is an IDENTIFIER that appears on the
 * nameplate, the datasheet, the PO and the MTC. It must not change when
 * somebody renames an area. So `tag_number` is stored verbatim, and the unit
 * code inside it is a copy by design, not by accident.
 */
return new class extends Migration
{
    public function up(): void
    {
        // -------------------------------------------------------------------
        // equipment
        // -------------------------------------------------------------------
        Schema::create('equipment', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');

            // Same parent as piping lines, which buys the completion machinery
            // for free: punch items, MC certificates and material status are
            // all keyed to subsystems already. A vessel becomes MC-able with
            // no new plumbing (ADR-010 section 3.3).
            $t->uuid('subsystem_id')->nullable();

            // The unit this equipment is addressed by. Denormalised from the
            // area on purpose: the tag is composed from it, and a tag whose
            // unit silently changes when somebody re-parents an area is a tag
            // that no longer matches the nameplate.
            $t->uuid('area_id')->nullable();
            $t->string('unit_code', 64)->nullable();

            // The full tag as written on the nameplate - '11-P-101A'.
            $t->string('tag_number', 64);

            // The parts it was composed from, kept alongside so a register can
            // group by type or sort by sequence without re-parsing the string.
            // Parsing a tag to sort it is how a '101A' ends up after '1010'.
            $t->string('equipment_type', 32);
            $t->integer('tag_sequence')->nullable();
            $t->string('tag_suffix', 8)->nullable();

            $t->string('name_en')->nullable();
            $t->string('name_fa')->nullable();
            $t->text('service_description')->nullable();

            // Design data mirrors piping_lines so reports can treat a vessel
            // and a line alike. Canonical values, per ADR-014: the entered
            // value and unit live beside the canonical mirror.
            $t->decimal('design_pressure', 16, 6)->nullable();
            $t->string('design_pressure_unit', 16)->nullable();
            $t->decimal('design_pressure_kpa', 16, 6)->nullable();
            $t->decimal('design_temperature', 16, 6)->nullable();
            $t->string('design_temperature_unit', 16)->nullable();
            $t->decimal('design_temperature_c', 16, 6)->nullable();

            $t->string('design_code', 64)->nullable();
            $t->string('material_spec', 64)->nullable();
            $t->string('insulation_type', 32)->nullable();
            $t->decimal('weight_empty_kg', 14, 3)->nullable();
            $t->decimal('weight_operating_kg', 14, 3)->nullable();

            // Equipment is PROCURED, unlike a piping component. These tie the
            // physical item to its PO, vendor and datasheet.
            $t->uuid('po_item_id')->nullable();
            $t->uuid('vendor_company_id')->nullable();
            $t->uuid('datasheet_document_id')->nullable();
            $t->uuid('ga_drawing_document_id')->nullable();

            $t->string('state', 24)->default('PLANNED');
            $t->string('source', 16)->default('MANUAL');
            $t->string('external_ref', 128)->nullable();

            $t->uuid('created_by')->nullable();
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->nullOnDelete();
            $t->foreign('area_id')->references('id')->on('areas')->nullOnDelete();
            $t->foreign('vendor_company_id')->references('id')->on('companies')->nullOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            // THE RULE THE USER STATED: unique across the whole project.
            $t->unique(['project_id', 'tag_number'], 'equipment_project_tag_unique');

            $t->index(['project_id', 'subsystem_id'], 'equipment_project_subsystem_idx');
            $t->index(['project_id', 'equipment_type'], 'equipment_project_type_idx');
        });

        // -------------------------------------------------------------------
        // equipment_nozzles
        // -------------------------------------------------------------------
        Schema::create('equipment_nozzles', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('equipment_id');

            // N-1, M-1. Unique per equipment, not per project: every vessel on
            // the job has an N-1.
            $t->string('tag', 32);
            $t->string('nozzle_type', 24)->default('PROCESS');

            // The four-column size convention (ADR-009): the label people write
            // and the decimal arithmetic needs.
            $t->string('nominal_size', 16)->nullable();
            $t->decimal('nominal_size_decimal', 8, 4)->nullable();

            $t->string('end_type', 8)->nullable();
            $t->string('rating', 16)->nullable();   // 150#, 300#
            $t->string('facing', 16)->nullable();   // RF, FF, RTJ
            $t->string('schedule', 16)->nullable();

            $t->decimal('elevation_mm', 12, 3)->nullable();
            $t->string('orientation', 16)->nullable();

            // A capped spare nozzle is NOT an open end. Without this flag the
            // open-ends worklist fills with false positives and people stop
            // reading it.
            $t->boolean('is_spare')->default(false);

            $t->string('datasheet_ref', 128)->nullable();
            $t->string('external_ref', 128)->nullable();
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('equipment_id')->references('id')->on('equipment')->cascadeOnDelete();

            $t->unique(['equipment_id', 'tag'], 'equipment_nozzles_equipment_tag_unique');
            $t->index(['project_id', 'equipment_id'], 'equipment_nozzles_project_equipment_idx');
        });

        // -------------------------------------------------------------------
        // The FK phase 2 could not add, because the table did not exist yet.
        // -------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            ALTER TABLE ports
            ADD CONSTRAINT ports_nozzle_id_foreign
            FOREIGN KEY (nozzle_id) REFERENCES equipment_nozzles (id) ON DELETE CASCADE
        SQL);

        // -------------------------------------------------------------------
        // CHECKs
        // -------------------------------------------------------------------
        DB::unprepared(<<<'SQL'
            ALTER TABLE equipment ADD CONSTRAINT equipment_tag_not_blank CHECK (btrim(tag_number) <> '')
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE equipment ADD CONSTRAINT equipment_state_known CHECK (
                state IN ('PLANNED', 'ORDERED', 'DELIVERED', 'INSTALLED', 'COMMISSIONED')
            )
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE equipment ADD CONSTRAINT equipment_sequence_positive CHECK (
                tag_sequence IS NULL OR tag_sequence >= 1
            )
        SQL);

        // Same paired-or-both-null rule as components and ports: a decimal with
        // no label cannot be displayed, a label with no decimal cannot be
        // compared - and compatibility needs the comparison.
        DB::unprepared(<<<'SQL'
            ALTER TABLE equipment_nozzles ADD CONSTRAINT equipment_nozzles_size_pair_complete CHECK (
                (nominal_size IS NULL AND nominal_size_decimal IS NULL)
                OR (nominal_size IS NOT NULL AND nominal_size_decimal IS NOT NULL)
            )
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE equipment_nozzles ADD CONSTRAINT equipment_nozzles_tag_not_blank CHECK (btrim(tag) <> '')
        SQL);

        // -------------------------------------------------------------------
        // Per-project tagging policy (the user's "both, selectable" ruling)
        // -------------------------------------------------------------------
        Schema::table('project_piping_config', function (Blueprint $t): void {
            // VALIDATE | ALLOCATE. Default VALIDATE, because that is the safer
            // default on a project that already has an engineering tag list:
            // allocating over the top of one produces duplicate physical tags.
            $t->string('equipment_tag_mode', 16)->default('VALIDATE');
            $t->jsonb('equipment_tag_pattern')->nullable();
            $t->jsonb('equipment_types')->nullable();
        });

        DB::unprepared(<<<'SQL'
            ALTER TABLE project_piping_config ADD CONSTRAINT project_config_equipment_tag_mode_known CHECK (
                equipment_tag_mode IN ('VALIDATE', 'ALLOCATE')
            )
        SQL);

        // -------------------------------------------------------------------
        // RLS - copied from components/ports, not reinvented.
        // -------------------------------------------------------------------
        foreach (['equipment', 'equipment_nozzles'] as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    (
                        NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                        OR {$table}.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                    )
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {$table}.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
                WITH CHECK (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {$table}.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            SQL);

            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_archive_freeze ON {$table}
                AS RESTRICTIVE
                FOR ALL
                USING (true)
                WITH CHECK (epcc_project_is_writable(project_id))
            SQL);

            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_archive_no_delete ON {$table}
                AS RESTRICTIVE
                FOR DELETE
                USING (epcc_project_is_writable(project_id))
            SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE ports DROP CONSTRAINT IF EXISTS ports_nozzle_id_foreign');

        Schema::table('project_piping_config', function (Blueprint $t): void {
            $t->dropColumn(['equipment_tag_mode', 'equipment_tag_pattern', 'equipment_types']);
        });

        Schema::dropIfExists('equipment_nozzles');
        Schema::dropIfExists('equipment');
    }
};
