<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-048 A.4f slice 1 — reconciling the legacy warehouse with the custody chain.
 *
 * ADR-047 built a controlled custody chain for piping and closed the legacy
 * warehouse behind a cutoff trigger: no new `material_lots` rows, and no silent
 * mapping of old rows onto new ones. The GRN guard enforces the second half —
 * `PIPING_GRN_LEGACY_RECONCILIATION_REQUIRED` — by refusing to post a GRN
 * against a purchase order that still has legacy lots.
 *
 * That refusal had no way through. A project that used the legacy warehouse
 * before cutover could never post a controlled GRN against those purchase
 * orders at all: the gate was a wall, not a gate.
 *
 * This migration builds the path, not a bypass:
 *
 *   `material_legacy_reconciliations` is append-only and chained per lot. Each
 *   row is an explicit, nominated, reasoned decision about one legacy lot —
 *   TAKE_OVER (this custody record re-receives it) or RETIRE (it is not coming
 *   across, and here is why). The item and heat are DERIVED from the lot, and a
 *   TAKE_OVER must point at custody that attests the same heat, so nothing is
 *   mapped from one vocabulary into another by assumption.
 *
 * The GRN guard is then re-declared — byte-for-byte except one IF — so it
 * refuses only lots that are still UNreconciled. The refusal keeps its code and
 * its meaning; it simply stops being permanent.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Teach the nomination register the new action. Deciding the fate of
        //    legacy material is a claim about material identity, so it sits in the
        //    same register as registering and binding certificates — not in the
        //    receipt register, whose every action must always carry a nominee
        //    (a project with no legacy material should never have to nominate a
        //    reconciler). Here, an un-nominated actor is simply refused.
        DB::unprepared(<<<'SQL'
DO $$
DECLARE c name;
BEGIN
  SELECT conname INTO c FROM pg_constraint
   WHERE conrelid = 'result_recorder_policies'::regclass AND contype = 'c'
     AND pg_get_constraintdef(oid) LIKE '%action%';
  IF c IS NULL THEN
    RAISE EXCEPTION 'result_recorder_policies action CHECK not found; refusing to widen it blindly';
  END IF;
  EXECUTE format('ALTER TABLE result_recorder_policies DROP CONSTRAINT %I', c);
  ALTER TABLE result_recorder_policies ADD CONSTRAINT result_recorder_policies_action_check
    CHECK (action IN ('record_weld','record_ndt','record_pack_result','register_certificate','sign_off_stage','bind_certificate','reconcile_legacy'));
END $$;
SQL);

        // 2. The reconciliation register itself.
        Schema::create('material_legacy_reconciliations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('lot_id')->constrained('material_lots')->restrictOnDelete();
            // TAKE_OVER points at the controlled receipt that re-receives the
            // material; RETIRE points at nothing and owes a reason instead.
            $t->foreignUuid('custody_id')->nullable()->constrained('piping_custody_records')->restrictOnDelete();
            $t->string('decision', 16);
            // Derived from the lot: the identity being reconciled is the lot's,
            // never the caller's.
            $t->string('item_code', 64)->nullable();
            $t->string('heat_ref', 200)->nullable();
            $t->text('reason');
            $t->uuid('policy_id');
            $t->foreignUuid('decided_by')->constrained('users')->restrictOnDelete();
            $t->integer('sequence');
            $t->uuid('previous_reconciliation_id')->nullable();
            $t->uuid('request_id');
            $t->timestampTz('decided_at', 6)->useCurrent();
            $t->unique(['project_id', 'request_id'], 'legacy_reconcile_request_unique');
            $t->unique(['project_id', 'id'], 'legacy_reconcile_tenant_key');
            $t->unique(['lot_id', 'sequence'], 'legacy_reconcile_lot_sequence_unique');
            $t->index(['project_id', 'lot_id'], 'legacy_reconcile_lot_idx');
        });

        // Self-reference in a second pass (the PK does not exist during create).
        DB::unprepared('ALTER TABLE material_legacy_reconciliations
            ADD CONSTRAINT legacy_reconcile_chain_fk
            FOREIGN KEY (previous_reconciliation_id)
            REFERENCES material_legacy_reconciliations(id) ON DELETE RESTRICT');

        DB::unprepared(<<<'SQL'
ALTER TABLE material_legacy_reconciliations ADD CONSTRAINT legacy_reconcile_shape CHECK (
  decision IN ('TAKE_OVER','RETIRE')
  AND reason ~ '[^[:space:]]' AND char_length(reason) <= 4000
  -- Taking material over names the receipt that re-receives it; retiring it
  -- names a reason. Neither may be silent.
  AND (decision = 'TAKE_OVER') = (custody_id IS NOT NULL)
);

ALTER TABLE material_legacy_reconciliations ENABLE ROW LEVEL SECURITY;
ALTER TABLE material_legacy_reconciliations FORCE ROW LEVEL SECURITY;

CREATE POLICY legacy_reconcile_read ON material_legacy_reconciliations FOR SELECT USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = material_legacy_reconciliations.project_id
                 AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid)
  AND EXISTS (SELECT 1 FROM material_lots l
               WHERE l.id = material_legacy_reconciliations.lot_id
                 AND l.project_id = material_legacy_reconciliations.project_id)
);

CREATE POLICY legacy_reconcile_insert ON material_legacy_reconciliations FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND decided_by = NULLIF(current_setting('epcc.receipt_actor_id', true), '')::uuid
  AND epcc_project_is_writable(project_id)
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = material_legacy_reconciliations.project_id
                 AND pc.company_id = material_legacy_reconciliations.company_id)
);

CREATE OR REPLACE FUNCTION epcc_reject_legacy_reconcile_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  RAISE EXCEPTION 'Legacy reconciliations are append-only; a correction is a new chained decision, never an edit'
    USING ERRCODE = '55000';
END; $$;

CREATE OR REPLACE FUNCTION epcc_legacy_reconcile_insert_path() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  IF pg_trigger_depth() <> 1 THEN
    RAISE EXCEPTION 'LEGACY_RECONCILE_MANUAL_DIRECT_ONLY' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END; $$;

CREATE OR REPLACE FUNCTION epcc_guard_legacy_reconciliation() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE l material_lots%ROWTYPE; head uuid; seq integer; c_heat text;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('legacy-reconcile:' || NEW.lot_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'LEGACY_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.decided_by IS DISTINCT FROM NULLIF(current_setting('epcc.receipt_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'LEGACY_RECONCILE_AUTHORITY' USING ERRCODE = '42501';
  END IF;

  NEW.policy_id := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.decided_by, 'reconcile_legacy');
  IF NEW.policy_id IS NULL THEN
    RAISE EXCEPTION 'LEGACY_RECONCILE_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;

  SELECT * INTO l FROM material_lots WHERE id = NEW.lot_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'LEGACY_LOT_NOT_FOUND' USING ERRCODE = '42501';
  END IF;

  -- Chained, like the custody amendments: a later decision supersedes an
  -- earlier one, and both stay on the record.
  SELECT id, sequence INTO head, seq
    FROM material_legacy_reconciliations
   WHERE lot_id = NEW.lot_id AND project_id = NEW.project_id
   ORDER BY sequence DESC LIMIT 1;
  IF NEW.previous_reconciliation_id IS DISTINCT FROM head THEN
    RAISE EXCEPTION 'LEGACY_RECONCILE_STALE' USING ERRCODE = '23514';
  END IF;
  NEW.sequence := COALESCE(seq, 0) + 1;

  -- The identity being reconciled is the lot's own.
  NEW.item_code := l.item_code;
  NEW.heat_ref := l.heat_number;
  NEW.reason := btrim(NEW.reason);

  IF NEW.decision = 'TAKE_OVER' THEN
    -- The controlled receipt must attest the same heat the legacy lot carried.
    -- Different heat, different steel: refuse rather than translate.
    SELECT e.eff->>'heat_ref' INTO c_heat
      FROM piping_custody_records r
      JOIN LATERAL (SELECT epcc_piping_effective_receipt(r.id) AS eff) e ON true
     WHERE r.id = NEW.custody_id AND r.project_id = NEW.project_id;
    IF NOT FOUND THEN
      RAISE EXCEPTION 'LEGACY_CUSTODY_NOT_FOUND' USING ERRCODE = '42501';
    END IF;
    IF NEW.heat_ref IS NOT NULL AND btrim(COALESCE(c_heat, '')) <> NEW.heat_ref THEN
      RAISE EXCEPTION 'LEGACY_HEAT_MISMATCH' USING ERRCODE = '23514';
    END IF;
  ELSE
    NEW.custody_id := NULL;
  END IF;

  NEW.decided_at := clock_timestamp();
  RETURN NEW;
END; $$;

CREATE TRIGGER legacy_reconcile_immutable BEFORE UPDATE OR DELETE ON material_legacy_reconciliations
  FOR EACH ROW EXECUTE FUNCTION epcc_reject_legacy_reconcile_mutation();
CREATE TRIGGER legacy_reconcile_insert_path BEFORE INSERT ON material_legacy_reconciliations
  FOR EACH ROW EXECUTE FUNCTION epcc_legacy_reconcile_insert_path();
CREATE TRIGGER legacy_reconcile_guard BEFORE INSERT ON material_legacy_reconciliations
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_legacy_reconciliation();
SQL);

        // The GRN guard, re-declared. Identical to the ADR-046 version except for
        // one IF: a legacy lot that has been reconciled no longer blocks the GRN.
        // The body is copied verbatim from the installed function so nothing
        // else about that guard changes.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_guard_piping_grn() RETURNS trigger LANGUAGE plpgsql AS $$

DECLARE c piping_custody_records%ROWTYPE; q piping_qa_decisions%ROWTYPE; qc piping_receiving_reviews%ROWTYPE; eff jsonb; snap jsonb; expected jsonb; req jsonb; ev jsonb; pcode text; cust uuid; total numeric;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PIPING_GRN_IMMUTABLE' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('piping-receipt-access:'||NEW.project_id::text,0));
 SELECT code INTO pcode FROM projects WHERE id=NEW.project_id FOR NO KEY UPDATE;
 IF NOT FOUND OR NOT epcc_project_is_writable(NEW.project_id) OR NOT epcc_piping_grn_inventory_ok()
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.posted_by IS DISTINCT FROM NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid
 OR NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.posted_by,NEW.company_id,'post_grn') OR NEW.policy_id IS NULL THEN
 RAISE EXCEPTION 'PIPING_GRN_AUTHORITY_OR_INVENTORY' USING ERRCODE='42501'; END IF;
 SELECT * INTO c FROM piping_custody_records WHERE id=NEW.custody_id AND project_id=NEW.project_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_GRN_PARENT' USING ERRCODE='42501'; END IF;
 SELECT custodian_company_id INTO cust FROM piping_receipt_nomination_index WHERE project_id=NEW.project_id AND policy_id=NEW.policy_id AND company_id=NEW.company_id AND action='post_grn' LIMIT 1;
 IF cust IS DISTINCT FROM c.custodian_company_id THEN RAISE EXCEPTION 'PIPING_GRN_CUSTODIAN_CHANGED' USING ERRCODE='23514'; END IF;
 eff:=epcc_piping_effective_receipt(c.id); IF eff IS NULL THEN RAISE EXCEPTION 'PIPING_GRN_SOURCE' USING ERRCODE='42501'; END IF;
 SELECT * INTO q FROM piping_qa_decisions WHERE custody_id=c.id AND project_id=NEW.project_id ORDER BY sequence DESC LIMIT 1;
 IF NOT FOUND OR q.id IS DISTINCT FROM NEW.qa_id OR q.payload_hash IS DISTINCT FROM NEW.qa_payload_hash OR q.decision<>'ACCEPTED'
 OR q.amendment_id IS DISTINCT FROM (eff->>'amendment_id')::uuid OR q.open_conditions IS NOT NULL
 OR q.custody_fingerprint IS DISTINCT FROM epcc_piping_custody_fingerprint(c.id) THEN RAISE EXCEPTION 'PIPING_GRN_QA_STALE_OR_NOT_ACCEPTED' USING ERRCODE='23514'; END IF;
 SELECT * INTO qc FROM piping_receiving_reviews WHERE custody_id=c.id AND project_id=NEW.project_id ORDER BY sequence DESC LIMIT 1;
 IF NOT FOUND OR qc.id IS DISTINCT FROM q.review_id OR qc.amendment_id IS DISTINCT FROM q.amendment_id OR qc.observed_result<>'NO_NONCONFORMITY_OBSERVED'
 OR c.created_by=qc.reviewed_by OR c.created_by=q.decided_by OR qc.reviewed_by=q.decided_by THEN RAISE EXCEPTION 'PIPING_GRN_QC_STALE' USING ERRCODE='23514'; END IF;
 IF q.declarations IS DISTINCT FROM '{"source_match":true,"quantity_unit_match":true,"heat_traceability":true,"mandatory_documents_complete":true,"nonconformities_closed":true,"requirements_met":true}'::jsonb THEN RAISE EXCEPTION 'PIPING_GRN_QA_DECLARATIONS' USING ERRCODE='23514'; END IF;
 snap:=epcc_piping_source_snapshot(NEW.project_id,(eff->>'source_line_id')::uuid);
 IF snap IS NULL OR snap->>'state' NOT IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') OR snap->>'unit'<>c.unit OR c.quantity>(snap->>'ordered_quantity')::numeric
 OR (snap-'state'-'issued_at') IS DISTINCT FROM ((eff->'source_snapshot')-'state'-'issued_at') OR NULLIF(btrim(eff->>'heat_ref'),'') IS NULL THEN RAISE EXCEPTION 'PIPING_GRN_SOURCE' USING ERRCODE='23514'; END IF;
 IF q.amendment_id IS NULL THEN expected:=q.source_basis; ELSE SELECT snapshot INTO expected FROM piping_qa_amendment_source_bases WHERE decision_id=q.id; END IF;
 IF (expected-'state'-'issued_at') IS DISTINCT FROM (snap-'state'-'issued_at') THEN RAISE EXCEPTION 'PIPING_GRN_SOURCE_CHANGED' USING ERRCODE='23514'; END IF;
 SELECT COALESCE(jsonb_agg(file_id::text ORDER BY file_id::text),'[]'::jsonb) INTO req FROM piping_qa_evidence WHERE decision_id=q.id AND kind='REQUIREMENT';
 SELECT COALESCE(jsonb_agg(file_id::text ORDER BY file_id::text),'[]'::jsonb) INTO ev FROM piping_qa_evidence WHERE decision_id=q.id AND kind='EVIDENCE';
 expected:=jsonb_build_object('expected_policy_id',q.policy_id,'previous_decision_id',q.previous_decision_id,'review_id',q.review_id,'request_id',q.request_id,'decision',q.decision,'reason',q.reason,'open_conditions',q.open_conditions,'declarations',q.declarations,'requirements_file_ids',req,'evidence_file_ids',ev);
 IF q.amendment_id IS NOT NULL THEN expected:=expected||jsonb_build_object('amendment_id',q.amendment_id); END IF;
 IF NEW.qa_payload IS NULL OR NEW.qa_payload::jsonb IS DISTINCT FROM expected OR encode(sha256(convert_to(NEW.qa_payload,'UTF8')),'hex')<>q.payload_hash
 OR jsonb_array_length(req)=0 OR jsonb_array_length(ev)=0 THEN RAISE EXCEPTION 'PIPING_GRN_EVIDENCE_INVENTORY' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM piping_qa_evidence e LEFT JOIN document_files f ON f.id=e.file_id LEFT JOIN document_revisions r ON r.id=f.revision_id AND r.document_id=f.document_id LEFT JOIN documents d ON d.id=f.document_id
 LEFT JOIN project_memberships m ON m.project_id=NEW.project_id AND m.user_id=NEW.posted_by
 WHERE e.decision_id=q.id AND (f.id IS NULL OR r.id IS NULL OR d.id IS NULL OR f.project_id<>NEW.project_id OR r.project_id<>NEW.project_id OR d.project_id<>NEW.project_id
 OR r.cde_state NOT IN ('PUBLISHED','ARCHIVED') OR f.sha256 IS DISTINCT FROM e.snapshot->>'sha256' OR f.size_bytes IS DISTINCT FROM (e.snapshot->>'size_bytes')::bigint
 OR f.id IS DISTINCT FROM (e.snapshot->>'id')::uuid OR f.original_name IS DISTINCT FROM e.snapshot->>'original_name'
 OR d.number IS DISTINCT FROM e.snapshot->>'number' OR r.revision IS DISTINCT FROM e.snapshot->>'revision' OR r.ordinal IS DISTINCT FROM (e.snapshot->>'ordinal')::integer OR r.suitability IS DISTINCT FROM e.snapshot->>'suitability'
 OR f.document_id IS DISTINCT FROM (e.snapshot->>'document_id')::uuid OR f.revision_id IS DISTINCT FROM (e.snapshot->>'revision_id')::uuid
 OR (m.discipline_scope IS NOT NULL AND NOT jsonb_exists(m.discipline_scope,d.discipline)))) THEN RAISE EXCEPTION 'PIPING_GRN_EVIDENCE_CHANGED' USING ERRCODE='23514'; END IF;
 -- No legacy source receipts are silently mapped, subtracted or imported.
 -- A.4f: the same refusal, but with a way through. A legacy lot that has been
 -- explicitly reconciled (taken over into the controlled chain, or retired with a
 -- reason) no longer blocks the GRN -- the reconciliation is the evidence, not a
 -- silent mapping of old rows onto new ones.
 IF EXISTS(SELECT 1 FROM material_lots l WHERE l.project_id=NEW.project_id AND l.purchase_order_id=(snap->>'purchase_order_id')::uuid
   AND NOT EXISTS(SELECT 1 FROM material_legacy_reconciliations r WHERE r.lot_id=l.id AND r.project_id=l.project_id)) THEN RAISE EXCEPTION 'PIPING_GRN_LEGACY_RECONCILIATION_REQUIRED' USING ERRCODE='23514'; END IF;
 SELECT COALESCE(sum(quantity),0) INTO total FROM piping_grn_records WHERE project_id=NEW.project_id AND source_line_id=(snap->>'line_id')::uuid;
 IF total+c.quantity>(snap->>'ordered_quantity')::numeric THEN RAISE EXCEPTION 'PIPING_GRN_ORDERED_QUANTITY_EXCEEDED' USING ERRCODE='23514'; END IF;
 NEW.source_line_id:=(snap->>'line_id')::uuid; NEW.purchase_order_id:=(snap->>'purchase_order_id')::uuid; NEW.source_snapshot:=snap;
 NEW.quantity:=c.quantity; NEW.unit:=c.unit; NEW.heat_ref:=eff->>'heat_ref'; NEW.custodian_company_id:=c.custodian_company_id;
 NEW.review_id:=q.review_id; NEW.amendment_id:=q.amendment_id; NEW.qa_payload:=NULL; NEW.number:=pcode||'-GRN-C-'||NEW.id::text;
 NEW.state:='POSTED'; NEW.created_at:=clock_timestamp(); RETURN NEW;
END 
$$;
SQL);

        // Freeze the invoker read contract, house style.
        $policies = DB::scalar("SELECT array_agg(polname::text ORDER BY polname)::text FROM pg_policy WHERE polrelid = 'material_legacy_reconciliations'::regclass AND polcmd IN ('r','*')");
        if ($policies !== '{legacy_reconcile_read}') {
            throw new RuntimeException('Unexpected read policy on material_legacy_reconciliations; reconcile before installing.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Legacy reconciliations are forward-only quality records.');
    }
};
