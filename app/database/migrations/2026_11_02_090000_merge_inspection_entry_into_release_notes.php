<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One record, not two — ADR-060 as amended; ADR-041's observation is retired.
 *
 * WHY THIS EXISTS. The platform held two overlapping records about the same
 * attendance. `material_inspections` said what the inspector SAW (PASS / FAIL /
 * CONDITIONAL, with findings, at WORKS / PRE_SHIPMENT / ON_ARRIVAL). The release
 * note said what may now HAPPEN to the material. The observation even carried
 * the -IRN- number series, which is the owner's name for the release note — so
 * the series and the semantics disagreed with each other, and neither record
 * could answer the question the other existed to answer: an observation with no
 * release changed nothing, and a release with no observation could not say why.
 *
 * The owner's decision: the observation was always meant to BE the release note
 * and was built wrong. So the two become one, in the -IRN- series.
 *
 * WHAT IS CARRIED ACROSS, NOT DROPPED. Deleting the observation outright would
 * have taken two real capabilities with it, and the owner asked for them to be
 * checked rather than assumed:
 *
 *   * ON_ARRIVAL inspection — inspecting goods AFTER they arrive, which is the
 *     only way the platform could ever inspect received material. Kept as a
 *     stage, alongside WORKS.
 *   * FINDINGS — mandatory for anything other than a clean release, because a
 *     refusal that says nothing cannot be acted on by the vendor. This is
 *     ADR-041's own rule, carried forward rather than reinvented.
 *
 * HOW THE LOT WORLD IS KEPT WORKING. `material_lots.inspection_result` feeds a
 * live warehouse screen and `uninspectedLots()`. The observation table was its
 * only source, so the foreign key is repointed at the merged register and the
 * verdict is derived from the outcome (RELEASED → PASS, RELEASED_WITH_HOLDS →
 * CONDITIONAL, NOT_RELEASED → FAIL), preserving the vocabulary the screen and
 * the filter already speak.
 *
 * WHAT IS ACTUALLY DELETED. `material_inspections`; `inspection_author_policies`
 * and its resolver (release authority is one register, `irn_issuer_policies`,
 * not two); `source_inspection_id`, because a release no longer answers a
 * separate observation — it IS the observation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. The merged record gains the findings it must now carry.
        Schema::table('inspection_release_notes', function (Blueprint $t): void {
            $t->text('findings_en')->nullable();
            $t->text('findings_fa')->nullable();
        });

        // 2. WORKS and ON_ARRIVAL become representable. WORKS is inspecting at
        //    the vendor's works; ON_ARRIVAL is inspecting goods that have
        //    already landed — the received-goods inspection this merge had to
        //    preserve.
        DB::statement('ALTER TABLE inspection_release_notes DROP CONSTRAINT irn_stage_known');
        DB::statement("ALTER TABLE inspection_release_notes ADD CONSTRAINT irn_stage_known CHECK
            (stage IN ('WORKS','PRE_SHIPMENT','ON_ARRIVAL'))");

        // 3. The series becomes -IRN-. Existing -IRL- numbers are rewritten, not
        //    abandoned: a number already quoted on paperwork must stay findable.
        DB::statement("UPDATE inspection_release_notes SET number = replace(number, '-IRL-', '-IRN-') WHERE number LIKE '%-IRL-%'");

        // 4. A release no longer answers a separate observation.
        DB::statement('ALTER TABLE inspection_release_notes DROP CONSTRAINT IF EXISTS inspection_release_notes_source_inspection_id_foreign');
        Schema::table('inspection_release_notes', function (Blueprint $t): void {
            $t->dropColumn('source_inspection_id');
        });

        // 5. Repoint the lot at the merged register before the old table goes.
        DB::statement('ALTER TABLE material_lots DROP CONSTRAINT IF EXISTS material_lots_inspection_id_foreign');
        DB::statement('ALTER TABLE material_lots RENAME COLUMN inspection_id TO release_note_id');
        DB::statement('ALTER TABLE material_lots ADD CONSTRAINT material_lots_release_note_id_foreign
            FOREIGN KEY (release_note_id) REFERENCES inspection_release_notes(id) ON DELETE RESTRICT');

        // 6. The gate now reads the merged register, per line. A line is held
        //    when its LATEST note is a refusal; a release on one line does not
        //    release another.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_po_release_status(pid uuid)
RETURNS text LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
 WITH per_line AS (
   SELECT DISTINCT ON (purchase_order_item_id) purchase_order_item_id, outcome
     FROM inspection_release_notes
    WHERE purchase_order_id = pid
    ORDER BY purchase_order_item_id, sequence DESC
 ) SELECT CASE
   WHEN EXISTS (SELECT 1 FROM per_line WHERE outcome = 'NOT_RELEASED') THEN 'BLOCKED'
   WHEN EXISTS (SELECT 1 FROM per_line WHERE outcome IN ('RELEASED','RELEASED_WITH_HOLDS')) THEN 'RELEASED'
   ELSE 'NOT_REQUIRED'
 END
$$;
SQL);

        // 7. The guard file has the last word, and it changed in this slice.
        GuardSql::apply(['epcc_guard_inspection_release_note']);

        // 8. Retire the observation and its register. Dropping the table
        //    silently leaves the guard FUNCTION behind, and a guard that is no
        //    longer bound to any trigger is exactly the orphan the guard test
        //    exists to catch.
        // The triggers do not carry the guard's name; they carry the table's.
        DB::unprepared('DROP TRIGGER IF EXISTS inspection_entry_guard ON material_inspections');
        DB::unprepared('DROP TRIGGER IF EXISTS inspection_author_policy_guard ON inspection_author_policies');
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_guard_inspection_entry()');
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_guard_inspection_author_policy()');
        Schema::dropIfExists('material_inspections');
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_inspection_author_policy(uuid, uuid, uuid, uuid)');
        Schema::dropIfExists('inspection_author_policies');
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_po_release_status(uuid)');
        Schema::dropIfExists('inspection_release_notes');
        Schema::dropIfExists('irn_issuer_policies');
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_irn_nomination(uuid, uuid, uuid)');
        DB::statement('ALTER TABLE material_lots DROP CONSTRAINT IF EXISTS material_lots_release_note_id_foreign');
        DB::statement('ALTER TABLE material_lots RENAME COLUMN release_note_id TO inspection_id');
    }
};
