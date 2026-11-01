<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inspection Release Notes (IRN) — ADR-060.
 *
 * THE GAP. The platform could record that an inspector LOOKED at material
 * (material_inspections: PASS / FAIL / CONDITIONAL) and it could record that
 * the material ARRIVED (the custody chain). It could not record the act that
 * joins them: the inspector's release, which is the thing that turns "an
 * inspector attended" into "this may ship" and then "this may be received".
 *
 * ADR-041 made inspection advisory on purpose, and that was the right call for
 * the slice it was written in. But the consequence was left unattended: a FAIL
 * verdict had nowhere to land. Nothing downstream ever asked whether anyone had
 * released the material, so the effective answer was always yes. This slice
 * gives the verdict somewhere to land.
 *
 * WHY A SEPARATE TABLE FROM material_inspections. An observation records what
 * was seen; a release records what may now happen. They are different acts,
 * taken at different times, by parties with different authority — the engineer
 * who records an observation is not automatically the party who may release
 * goods for shipment. Storing both in one row would force one authority to
 * stand for the other, and would make "released, then found non-conforming"
 * unrepresentable. Two append-only registers with separate nomination.
 *
 * WHY THE LINE AND NOT THE ORDER. material_inspections attaches to the purchase
 * order header, which is why the platform cannot answer "has THIS line been
 * released" — and an order whose valves were released says nothing about its
 * gaskets. A release is per line; the order-level view is derived by
 * epcc_po_release_status(), never stored.
 *
 * THE ACRONYM COLLISION. `%s-IRN-%04d` already exists as the number series for
 * inspection OBSERVATIONS (ADR-041). The owner's "IRN" means the third-party
 * release certificate. One acronym for two documents is exactly how a warehouse
 * comes to release material against an observation number, so the release note
 * takes its own series, `-IRL-`, and the observation series is untouched.
 *
 * WHY A SEPARATE NOMINATION REGISTER. The results register already answers
 * "who may record a quality result" and ADR-041's inspection register answers
 * "who may record an observation". Releasing material for shipment is a third
 * authority, and a third party's registration is a different trust question
 * from an internal engineer's. Reusing either register would silently grant
 * release authority to everyone nominated for the other two acts.
 *
 * Declared boundary: this slice records the release and makes it readable as a
 * gate. It does NOT model holds as tracked items with their own clearance
 * workflow — RELEASED_WITH_HOLDS states its holds in text, and disposing of
 * them belongs to the TQ/NCR slice (ADR-031), which is the honest place for a
 * nonconformance to travel.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Who may release material for shipment.
        //
        //    Mirrors inspection_author_policies (ADR-041): a versioned,
        //    replace-never-edit register, because replacing a nomination while
        //    earlier releases still point at the old version must not rewrite
        //    history. No UPDATE/DELETE policy at all.
        Schema::create('irn_issuer_policies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->integer('version');
            $t->jsonb('selectors');
            $t->text('reason');
            $t->timestampTz('created_at');
            $t->unique(['project_id', 'version']);
            $t->unique(['id', 'project_id']);
        });

        DB::statement("ALTER TABLE irn_issuer_policies ADD CONSTRAINT irn_issuer_policy_shape CHECK
            (version > 0 AND jsonb_typeof(selectors)='array' AND jsonb_array_length(selectors)<=100 AND length(btrim(reason))>0)");
        DB::statement('ALTER TABLE irn_issuer_policies ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE irn_issuer_policies FORCE ROW LEVEL SECURITY');
        $visible = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
            AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=irn_issuer_policies.project_id
            AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)";
        DB::statement("CREATE POLICY irn_issuer_policy_read ON irn_issuer_policies FOR SELECT USING ({$visible})");
        DB::statement("CREATE POLICY irn_issuer_policy_insert ON irn_issuer_policies FOR INSERT WITH CHECK ({$visible}
            AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
            AND created_by=NULLIF(current_setting('epcc.result_actor_id',true),'')::uuid
            AND epcc_project_is_writable(project_id)
            AND EXISTS (SELECT 1 FROM projects p WHERE p.id=irn_issuer_policies.project_id AND p.owner_company_id=irn_issuer_policies.company_id))");

        // 2. Resolve an actor to the nomination that authorises them to release.
        //
        //    The register is keyed by PROJECT, not by company: the owner writes
        //    one register naming people across the participating companies, so
        //    filtering it by the caller's company would find nothing for every
        //    third party it was written for. The caller's company is still
        //    checked — against their own user row, their project membership and
        //    any role selector — which is where it belongs.
        //
        //    Same shape as epcc_result_nomination(): the actor must be active,
        //    a member of the project, in scope for PIPING, and named by user or
        //    by role. Default-deny — an unnamed actor has no release authority
        //    however senior they are.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_irn_nomination(pid uuid, cid uuid, aid uuid)
RETURNS uuid LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
 WITH h AS (
   SELECT id, selectors FROM irn_issuer_policies
   WHERE project_id = pid
   ORDER BY version DESC LIMIT 1
 ) SELECT h.id FROM h
 JOIN users u ON u.id = aid AND u.company_id = cid AND u.is_active
 JOIN project_memberships pm ON pm.user_id = u.id AND pm.project_id = pid
 WHERE true
 AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = pid AND pc.company_id = cid)
 AND (pm.discipline_scope IS NULL OR jsonb_exists(pm.discipline_scope, 'PIPING'))
 AND (jsonb_exists(h.selectors, 'user:' || aid::text)
      OR EXISTS (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes, r.code)
                 AND jsonb_exists(h.selectors, 'role:' || cid::text || ':' || r.code)))
$$;
SQL);

        // 3. The release register itself.
        Schema::create('inspection_release_notes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->foreignUuid('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
            $t->foreignUuid('source_inspection_id')->nullable()->constrained('material_inspections')->restrictOnDelete();
            $t->string('number');
            $t->integer('sequence');
            $t->string('stage');
            $t->string('outcome');
            // A third party is a platform user, or a person at a firm who will
            // never hold an account. Both are representable; neither may be
            // anonymous, and the guard refuses the blank case.
            $t->foreignUuid('inspector_company_id')->nullable()->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('inspector_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $t->string('inspector_name')->nullable();
            $t->text('holds_note_en')->nullable();
            $t->text('holds_note_fa')->nullable();
            $t->timestampTz('released_at');
            $t->foreignUuid('issued_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('issuer_policy_id')->nullable()->constrained('irn_issuer_policies')->restrictOnDelete();
            $t->uuid('previous_release_note_id')->nullable();
            $t->timestampTz('created_at');
            $t->unique(['project_id', 'sequence']);
            $t->unique(['id', 'project_id']);
        });

        Schema::table('inspection_release_notes', fn (Blueprint $t) => $t->foreign('previous_release_note_id')
            ->references('id')->on('inspection_release_notes')->restrictOnDelete());

        DB::statement("ALTER TABLE inspection_release_notes ADD CONSTRAINT irn_stage_known CHECK
            (stage IN ('PRE_SHIPMENT','ON_ARRIVAL'))");
        DB::statement("ALTER TABLE inspection_release_notes ADD CONSTRAINT irn_outcome_known CHECK
            (outcome IN ('RELEASED','RELEASED_WITH_HOLDS','NOT_RELEASED'))");
        DB::statement('ALTER TABLE inspection_release_notes ADD CONSTRAINT irn_sequence_positive CHECK (sequence > 0)');
        DB::statement("ALTER TABLE inspection_release_notes ADD CONSTRAINT irn_number_present CHECK (length(btrim(number)) > 0)");

        DB::unprepared(<<<'SQL'
ALTER TABLE inspection_release_notes ENABLE ROW LEVEL SECURITY;
ALTER TABLE inspection_release_notes FORCE ROW LEVEL SECURITY;
SQL);

        DB::statement("CREATE POLICY irn_read ON inspection_release_notes FOR SELECT USING (
            project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
            AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=inspection_release_notes.project_id
                AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid))");
        DB::statement("CREATE POLICY irn_insert ON inspection_release_notes FOR INSERT WITH CHECK (
            project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
            AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
            AND issued_by=NULLIF(current_setting('epcc.result_actor_id',true),'')::uuid)");

        // 4. The guard. database/guards has the last word, so the trigger binds
        //    the file, not an inline copy.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_reject_irn_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  RAISE EXCEPTION 'Inspection release notes are append-only; a correction is a new release, never an edit'
    USING ERRCODE = '55000';
END; $$;
SQL);

        GuardSql::apply(['epcc_guard_inspection_release_note']);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inspection_release_note_immutable BEFORE UPDATE OR DELETE ON inspection_release_notes
  FOR EACH ROW EXECUTE FUNCTION epcc_reject_irn_mutation();
CREATE TRIGGER inspection_release_note_guard BEFORE INSERT ON inspection_release_notes
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_inspection_release_note();
SQL);

        // 5. The gate. One question, one answer, asked the same way everywhere:
        //    may material on this order be received?
        //
        //    BLOCKED — a failed inspection on the order that no release covers,
        //    or an explicit refusal no later release has superseded.
        //    RELEASED — a release exists and nothing is blocking.
        //    NOT_REQUIRED — no failed inspection and no release; nothing has
        //    asked for a release, so the order is not held.
        //
        //    Absence of inspection does NOT block. That is ADR-041's stance and
        //    it is still right: material nobody inspected is a planning gap,
        //    not a quality hold, and blocking it would break every project that
        //    has not switched inspection on.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_po_release_status(pid uuid)
RETURNS text LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
 SELECT CASE
  WHEN EXISTS (SELECT 1 FROM material_inspections mi
               WHERE mi.purchase_order_id = pid AND mi.result = 'FAIL'
                 AND NOT EXISTS (SELECT 1 FROM inspection_release_notes irn
                                 WHERE irn.source_inspection_id = mi.id
                                   AND irn.outcome IN ('RELEASED','RELEASED_WITH_HOLDS')))
    OR EXISTS (SELECT 1 FROM inspection_release_notes r
               WHERE r.purchase_order_id = pid AND r.outcome = 'NOT_RELEASED'
                 AND NOT EXISTS (SELECT 1 FROM inspection_release_notes n
                                 WHERE n.purchase_order_id = pid
                                   AND n.outcome IN ('RELEASED','RELEASED_WITH_HOLDS')
                                   AND n.sequence > r.sequence))
    THEN 'BLOCKED'
  WHEN EXISTS (SELECT 1 FROM inspection_release_notes irn
               WHERE irn.purchase_order_id = pid
                 AND irn.outcome IN ('RELEASED','RELEASED_WITH_HOLDS'))
    THEN 'RELEASED'
  ELSE 'NOT_REQUIRED'
 END
$$;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_po_release_status(uuid)');
        DB::unprepared('DROP TRIGGER IF EXISTS inspection_release_note_guard ON inspection_release_notes');
        DB::unprepared('DROP TRIGGER IF EXISTS inspection_release_note_immutable ON inspection_release_notes');
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_reject_irn_mutation()');
        Schema::dropIfExists('inspection_release_notes');
        DB::unprepared('DROP FUNCTION IF EXISTS epcc_irn_nomination(uuid, uuid, uuid)');
        Schema::dropIfExists('irn_issuer_policies');
    }
};
