<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Piece-level custody — ADR-048 A.4b.
 *
 * THE GAP. The custody chain (piping_custody_records -> amendments -> GRN ->
 * MTR bindings) answers every question about material that ARRIVED. The ITR
 * dossier answers every question about joints that were WELDED. Nothing
 * connects the two, so the platform cannot say which joint was made from which
 * heat — and without that, a material certificate can never be carried into a
 * dossier as evidence about the pieces in front of the client.
 *
 * WHY THE LINK SITS ON THE COMPONENT AND NOT ON THE JOINT. The piece is the
 * component: a length of pipe, an elbow, a flange. A joint joins two pieces, so
 * the joint's parent metal is the material of its two components — which is
 * exactly what a weld map reports side by side. Linking at the joint instead
 * would store the same fact twice per weld and lose it for flanged joints.
 *
 * WHY A REGISTER, NOT A COLUMN ON components. A component can be made of more
 * than one heat (a fabricated spool: pipe of one heat, flange of another), and
 * a statement about material is a quality claim that gets CORRECTED — "we said
 * heat A, the stamp says heat B". A mutable column would keep the last answer
 * and destroy the earlier one. Appendix-only register with a sequence keeps
 * both, and the effective row is the newest.
 *
 * DERIVED, NEVER DECLARED. When a receipt is named, the heat and lot come from
 * epcc_piping_effective_receipt() — the custody chain's own state at this
 * instant. When no receipt is named the heat is an OBSERVATION (read off the
 * piece) and is only accepted with a reason, so the dossier can always tell
 * "traced to a receipt" from "someone read a stamp".
 *
 * Declared boundary: this slice ties a piece to the material it came from. It
 * does NOT account for quantity — how much of a receipt's length was consumed,
 * and whether that overruns what was received, needs an issuance model this
 * project has not declared.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Teach the nomination register the new action.
        //
        //    Widened by DISCOVERY, not by copying a list. Every earlier widening
        //    re-declared the whole list from memory of what it held at the time,
        //    which is how this slice first silently dropped `reconcile_legacy`
        //    and `sign_itr_snapshot` — two actions added by migrations between
        //    the list being written and now. Copying a list is a deletion
        //    waiting to happen; reading the live set and union-ing into it
        //    cannot lose an action.
        DB::unprepared(<<<'SQL'
DO $$
DECLARE c name; def text; acts text[];
BEGIN
  SELECT conname, pg_get_constraintdef(oid) INTO c, def
    FROM pg_constraint
   WHERE conrelid = 'result_recorder_policies'::regclass AND contype = 'c'
     AND pg_get_constraintdef(oid) LIKE '%action%';

  IF c IS NULL THEN
    RAISE EXCEPTION 'result_recorder_policies action CHECK not found; refusing to widen it blindly';
  END IF;

  -- regexp_matches yields one row per match, each row a text[]; a[1] is the
  -- capture group, which is the action name itself.
  SELECT array_agg(DISTINCT a[1]) INTO acts FROM regexp_matches(def, '''([a-z][a-z0-9_]*)''', 'g') AS t(a);

  IF acts IS NULL OR array_length(acts, 1) < 4 THEN
    RAISE EXCEPTION 'The action CHECK does not look like an action list (%); refusing to widen it blindly', def;
  END IF;

  IF NOT ('link_component_material' = ANY(acts)) THEN
    acts := array_append(acts, 'link_component_material');
  END IF;

  EXECUTE format('ALTER TABLE result_recorder_policies DROP CONSTRAINT %I', c);
  EXECUTE format(
    'ALTER TABLE result_recorder_policies ADD CONSTRAINT result_recorder_policies_action_check CHECK (action IN (%s))',
    (SELECT string_agg(quote_literal(a), ',' ORDER BY a) FROM unnest(acts) AS u(a)));
END $$;
SQL);

        // 2. The register.
        Schema::create('component_material_links', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();

            // The piece.
            $t->foreignUuid('component_id')->constrained('components')->restrictOnDelete();

            // What it was cut from. Nullable: material is often on site before
            // its receipt is. All three below are DERIVED from the custody
            // chain when a custody is named.
            $t->foreignUuid('custody_id')->nullable()->constrained('piping_custody_records')->restrictOnDelete();
            $t->foreignUuid('custody_amendment_id')->nullable()->constrained('piping_custody_amendments')->restrictOnDelete();
            $t->string('link', 20);
            $t->string('heat_ref', 200);
            $t->string('lot_ref', 200)->nullable();

            $t->uuid('linked_policy_id');
            $t->foreignUuid('linked_by')->constrained('users')->restrictOnDelete();

            // "Effective" is the highest sequence for the component.
            $t->integer('sequence');

            $t->text('unlinked_reason')->nullable();
            $t->text('reason');
            $t->uuid('request_id');
            $t->timestampTz('created_at', 6)->useCurrent();

            $t->unique(['project_id', 'request_id'], 'component_material_request_unique');
            $t->unique(['project_id', 'id'], 'component_material_tenant_key');
            $t->unique(['component_id', 'sequence'], 'component_material_sequence_unique');
            $t->unique(['component_id', 'custody_id'], 'component_material_custody_unique');
            $t->index(['project_id', 'component_id'], 'component_material_component_idx');
            $t->index(['project_id', 'heat_ref'], 'component_material_heat_idx');
        });

        // 3. Nothing in this register is editable, and the heat must mean
        //    something. Written as constraints, not as application rules.
        DB::unprepared(<<<'SQL'
ALTER TABLE component_material_links
  ADD CONSTRAINT component_material_link_kind_check
  CHECK (link IN ('CUSTODY_HEAT','CUSTODY_LOT_HEAT','OBSERVED'));

ALTER TABLE component_material_links
  ADD CONSTRAINT component_material_heat_shape CHECK (heat_ref ~ '[^[:space:]]');
ALTER TABLE component_material_links
  ADD CONSTRAINT component_material_lot_shape CHECK (lot_ref IS NULL OR lot_ref ~ '[^[:space:]]');

-- Traced, or explained. There is no third state: a link that names no receipt
-- and no reason would put an unattributed heat on the client's dossier.
ALTER TABLE component_material_links
  ADD CONSTRAINT component_material_traceability_check
  CHECK ((custody_id IS NOT NULL AND unlinked_reason IS NULL)
      OR (custody_id IS NULL AND unlinked_reason IS NOT NULL));
ALTER TABLE component_material_links
  ADD CONSTRAINT component_material_unlinked_shape CHECK (unlinked_reason IS NULL OR unlinked_reason ~ '[^[:space:]]');
ALTER TABLE component_material_links
  ADD CONSTRAINT component_material_reason_shape CHECK (reason ~ '[^[:space:]]');

-- A lot is only ever the custody chain's own claim, never a caller's.
ALTER TABLE component_material_links
  ADD CONSTRAINT component_material_amendment_requires_custody
  CHECK (custody_amendment_id IS NULL OR custody_id IS NOT NULL);
SQL);

        // 4. Tenancy, and the append-only contract.
        DB::unprepared(<<<'SQL'
ALTER TABLE component_material_links ENABLE ROW LEVEL SECURITY;
ALTER TABLE component_material_links FORCE ROW LEVEL SECURITY;

CREATE POLICY component_material_read ON component_material_links FOR SELECT USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = component_material_links.project_id
                 AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid)
  -- The component is the parent record: no component the session cannot see,
  -- no material link to read.
  AND EXISTS (SELECT 1 FROM components c
               WHERE c.id = component_material_links.component_id
                 AND c.project_id = component_material_links.project_id)
);

CREATE POLICY component_material_insert ON component_material_links FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND linked_by = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND epcc_project_is_writable(project_id)
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = component_material_links.project_id
                 AND pc.company_id = component_material_links.company_id)
);

CREATE OR REPLACE FUNCTION epcc_reject_component_material_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  RAISE EXCEPTION 'Component material links are append-only; a correction is a new link, never an edit'
    USING ERRCODE = '55000';
END; $$;
SQL);

        // 5. The guard. database/guards has the last word, so the trigger binds
        //    the file, not an inline copy.
        GuardSql::apply(['epcc_guard_component_material_link']);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER component_material_immutable BEFORE UPDATE OR DELETE ON component_material_links
  FOR EACH ROW EXECUTE FUNCTION epcc_reject_component_material_mutation();
CREATE TRIGGER component_material_guard BEFORE INSERT ON component_material_links
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_component_material_link();
SQL);

        // 6. Two readers, so a caller asks one question instead of re-deriving
        //    the chain. The second is the one the dossier will ask.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_component_effective_material(cid uuid) RETURNS jsonb
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE l component_material_links%ROWTYPE;
BEGIN
  SELECT * INTO l FROM component_material_links
   WHERE component_id = cid
   ORDER BY sequence DESC, created_at DESC
   LIMIT 1;
  IF NOT FOUND THEN RETURN NULL; END IF;
  RETURN jsonb_build_object(
    'link_id', l.id,
    'custody_id', l.custody_id,
    'custody_amendment_id', l.custody_amendment_id,
    'link', l.link,
    'heat_ref', l.heat_ref,
    'lot_ref', l.lot_ref,
    'sequence', l.sequence,
    'unlinked_reason', l.unlinked_reason,
    'linked_at', l.created_at);
END; $$;

CREATE OR REPLACE FUNCTION epcc_joint_material_identity(jid uuid) RETURNS jsonb
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE a uuid; b uuid; am jsonb; bm jsonb; cons text;
BEGIN
  SELECT pa.component_id, pb.component_id INTO a, b
    FROM joints j
    LEFT JOIN ports pa ON pa.id = j.port_a_id
    LEFT JOIN ports pb ON pb.id = j.port_b_id
   WHERE j.id = jid;
  IF NOT FOUND THEN RETURN NULL; END IF;

  am := CASE WHEN a IS NULL THEN NULL ELSE epcc_component_effective_material(a) END;
  bm := CASE WHEN b IS NULL THEN NULL ELSE epcc_component_effective_material(b) END;

  SELECT NULLIF(btrim(COALESCE(w.consumable_lot_ref, '')), '') INTO cons
    FROM joint_weld_records w
   WHERE w.joint_id = jid
   ORDER BY w.recorded_at DESC, w.id DESC
   LIMIT 1;

  RETURN jsonb_build_object(
    'joint_id', jid,
    'side_a', am,
    'side_b', bm,
    'consumable_lot_ref', cons);
END; $$;
SQL);

        // Freeze the invoker read contract, house style.
        $policies = DB::scalar("SELECT array_agg(polname::text ORDER BY polname)::text FROM pg_policy WHERE polrelid = 'component_material_links'::regclass AND polcmd IN ('r','*')");
        if ($policies !== '{component_material_read}') {
            throw new RuntimeException('component_material_links read policy contract changed: ' . var_export($policies, true));
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Piece-level custody is forward-only: a statement about what a piece was made of has no prior definition to fall back to.');
    }
};
