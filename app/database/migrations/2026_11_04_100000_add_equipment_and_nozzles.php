<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * ADR-010 §3 — equipment and nozzles.
 *
 * THE GAP
 *
 * A piping line ends at `from_ref` / `to_ref` free text. Nobody knows what a
 * line actually connects to, so nothing can answer "which lines land on V-101",
 * and a 6" 300# nozzle bolted to a 6" 150# flange is discovered at bolt-up,
 * on site, with the crane already hired.
 *
 * THE INSIGHT THAT SHAPES EVERYTHING
 *
 * A nozzle is a PORT OWNER, exactly like a component. `ports` was built for
 * that in 2026-09-17: it already carries `owner_kind` with a CHECK allowing
 * NOZZLE + nozzle_id, and `joints` links two ports with no knowledge of who
 * owns them. So a pipe-to-nozzle joint needs NO change to `joints` at all —
 * which is the whole point, and this migration proves it rather than asserts
 * it.
 *
 * WHY EQUIPMENT SITS ON A SUBSYSTEM
 *
 * `subsystem_id` is the same parent a piping line uses. That means equipment
 * joins the existing completion machinery for free: punch items, MC
 * certificates and material status are keyed to subsystems already, so a
 * vessel becomes MC-able without new plumbing.
 *
 * DERIVED, NEVER DECLARED
 *
 * Whether a nozzle is an OPEN END is derived: an unconnected nozzle that is
 * not marked spare. A capped spare is not an open end, and calling it one
 * would flood the worklist with false positives.
 *
 * Declared boundary: this is equipment as far as PIPING needs it. No
 * internals, no trays, no seals, no drivers as separate tags, no foundations,
 * no preservation. Each is real; none is needed for a line to terminate
 * correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');

            // The same parent a piping line uses, so equipment inherits the
            // completion machinery instead of needing its own.
            $t->uuid('subsystem_id')->nullable();

            $t->string('tag_number', 64);            // V-101, P-201A, E-301
            $t->string('equipment_type', 24);        // VESSEL|PUMP|EXCHANGER|TANK|COMPRESSOR|FILTER|SKID|OTHER
            $t->string('name_en', 200);
            $t->string('name_fa', 200);
            $t->text('service_description')->nullable();

            // Design data, mirroring piping_lines so reports can treat the two
            // alike rather than special-casing one of them.
            $t->string('design_pressure', 32)->nullable();
            $t->string('design_temperature', 32)->nullable();
            $t->string('operating_pressure', 32)->nullable();
            $t->string('operating_temperature', 32)->nullable();
            $t->string('design_code', 64)->nullable();   // ASME VIII Div.1, API 610, TEMA
            $t->string('material_spec', 120)->nullable();
            $t->string('insulation_type', 64)->nullable();
            $t->decimal('weight_empty_kg', 12, 3)->nullable();
            $t->decimal('weight_operating_kg', 12, 3)->nullable();

            // Commercial / traceability: equipment is PROCURED, so the chain
            // that materials already has must be reachable from here.
            $t->uuid('po_item_id')->nullable();
            $t->uuid('vendor_company_id')->nullable();
            $t->uuid('datasheet_document_id')->nullable();
            $t->uuid('ga_drawing_document_id')->nullable();
            $t->string('external_ref', 200)->nullable();

            $t->string('state', 24)->default('PLANNED');

            $t->timestampsTz();
            $t->uuid('created_by');

            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->restrictOnDelete();
            $t->foreign('po_item_id')->references('id')->on('purchase_order_items')->restrictOnDelete();
            $t->foreign('vendor_company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('datasheet_document_id')->references('id')->on('documents')->restrictOnDelete();
            $t->foreign('ga_drawing_document_id')->references('id')->on('documents')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            // A tag means one thing on a project. Two vessels called V-101 is
            // how a nozzle schedule report lies.
            $t->unique(['project_id', 'tag_number'], 'equipment_project_tag_unique');
            $t->index(['project_id', 'subsystem_id'], 'equipment_project_subsystem_idx');
            $t->index(['project_id', 'equipment_type'], 'equipment_project_type_idx');
        });

        Schema::create('equipment_nozzles', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('equipment_id');

            $t->string('tag', 32);                   // N-1, N-2, M-1
            $t->string('nozzle_type', 24);           // PROCESS|VENT|DRAIN|MANWAY|INSTRUMENT|RELIEF|SPARE
            $t->string('nps_label', 16)->nullable();
            $t->decimal('nps_decimal', 8, 4)->nullable();
            $t->string('dn', 16)->nullable();
            $t->string('end_type', 8)->nullable();   // FL / BW / SW — PCF vocabulary
            $t->string('rating', 16)->nullable();    // 150#, 300#
            $t->string('facing', 8)->nullable();     // RF / FF / RTJ
            $t->string('schedule', 16)->nullable();
            $t->string('wall_thickness', 16)->nullable();
            $t->string('elevation', 32)->nullable();
            $t->string('orientation', 32)->nullable();

            // A capped spare is not an open end. Without this flag every spare
            // nozzle on the plant shows up as unfinished work.
            $t->boolean('is_spare')->default(false);

            $t->string('datasheet_ref', 200)->nullable();
            $t->string('external_ref', 200)->nullable();

            $t->timestampsTz();
            $t->uuid('created_by');

            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('equipment_id')->references('id')->on('equipment')->cascadeOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();

            $t->unique(['equipment_id', 'tag'], 'nozzle_equipment_tag_unique');
            $t->index(['project_id', 'equipment_id'], 'nozzle_project_equipment_idx');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE equipment ADD CONSTRAINT equipment_shape CHECK (
    equipment_type IN ('VESSEL','PUMP','EXCHANGER','TANK','COMPRESSOR','FILTER','SKID','OTHER')
    AND state IN ('PLANNED','ORDERED','DELIVERED','INSTALLED','COMMISSIONED')
    AND length(btrim(tag_number)) >= 2
    AND length(btrim(name_en)) >= 2
    AND length(btrim(name_fa)) >= 2
);

ALTER TABLE equipment_nozzles ADD CONSTRAINT nozzle_shape CHECK (
    nozzle_type IN ('PROCESS','VENT','DRAIN','MANWAY','INSTRUMENT','RELIEF','SPARE')
    AND length(btrim(tag)) >= 2
);
SQL);

        // The FK the ports table has been waiting for since 2026-09-17. Now a
        // NOZZLE port can only point at a nozzle that really exists.
        DB::statement(<<<'SQL'
ALTER TABLE ports ADD CONSTRAINT ports_nozzle_id_foreign
    FOREIGN KEY (nozzle_id) REFERENCES equipment_nozzles(id) ON DELETE CASCADE
SQL);

        // A nozzle's ports are numbered once, for the same reason a
        // component's are: a retry must not give one nozzle two port 1s.
        DB::statement(<<<'SQL'
CREATE UNIQUE INDEX ports_nozzle_seq_unique ON ports (nozzle_id, seq) WHERE nozzle_id IS NOT NULL
SQL);

        DB::unprepared(<<<'SQL'
ALTER TABLE equipment ENABLE ROW LEVEL SECURITY;
ALTER TABLE equipment FORCE ROW LEVEL SECURITY;

CREATE POLICY equipment_read ON equipment FOR SELECT USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = equipment.project_id
                 AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid)
);

CREATE POLICY equipment_insert ON equipment FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND created_by = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND epcc_project_is_writable(project_id)
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = equipment.project_id
                 AND pc.company_id = equipment.company_id)
);

CREATE POLICY equipment_update ON equipment FOR UPDATE USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND epcc_project_is_writable(project_id)
) WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND epcc_project_is_writable(project_id)
);

ALTER TABLE equipment_nozzles ENABLE ROW LEVEL SECURITY;
ALTER TABLE equipment_nozzles FORCE ROW LEVEL SECURITY;

CREATE POLICY nozzle_read ON equipment_nozzles FOR SELECT USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = equipment_nozzles.project_id
                 AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid)
);

CREATE POLICY nozzle_insert ON equipment_nozzles FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND created_by = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND epcc_project_is_writable(project_id)
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = equipment_nozzles.project_id
                 AND pc.company_id = equipment_nozzles.company_id)
);

CREATE POLICY nozzle_update ON equipment_nozzles FOR UPDATE USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND epcc_project_is_writable(project_id)
) WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND epcc_project_is_writable(project_id)
);
SQL);

        GuardSql::apply(['epcc_guard_equipment']);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER equipment_guard BEFORE INSERT OR UPDATE ON equipment
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_equipment();
CREATE TRIGGER nozzle_guard BEFORE INSERT OR UPDATE ON equipment_nozzles
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_equipment();
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS nozzle_guard ON equipment_nozzles');
        DB::unprepared('DROP TRIGGER IF EXISTS equipment_guard ON equipment');
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_guard_equipment()');
        DB::statement('DROP INDEX IF EXISTS ports_nozzle_seq_unique');
        DB::statement('ALTER TABLE ports DROP CONSTRAINT IF EXISTS ports_nozzle_id_foreign');
        Schema::dropIfExists('equipment_nozzles');
        Schema::dropIfExists('equipment');
    }
};
