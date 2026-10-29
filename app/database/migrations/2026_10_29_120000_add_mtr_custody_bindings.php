<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-048 A.4e slice 1 — exact MTR binding to the custody chain ((lot) + heat).
 *
 * Three parts of this platform each speak about the same steel in a different
 * vocabulary, and nothing joined them:
 *
 *   - `material_certificates` (A.4) carries `heat_ref` and `lot_ref` as free
 *     text. You can register an MTR for a heat nobody ever received, and the
 *     database cannot tell.
 *   - `piping_custody_records` + their amendments (ADR-046) attest what was
 *     actually received, by heat — the custody chain never knew about lots.
 *   - `material_lots` (the legacy warehouse) carries (lot_code, heat_number).
 *
 * So the two questions a client asks at turnover had no honest answer: which
 * heats we hold have no certificate, and does this certificate cover material
 * we actually received?
 *
 * What this migration adds:
 *
 *   1. `piping_custody_amendments.lot_ref` — additive and nullable, so the
 *      custody chain can finally attest a lot as well as a heat. Existing rows
 *      simply know no lot; nothing is rewritten.
 *   2. `epcc_piping_effective_receipt()` now reports the effective lot too, so
 *      "what does custody say about this material" is one question, one call.
 *   3. `material_certificate_bindings` — append-only: a certificate is bound to
 *      custody that attests its (lot, heat), or it is recorded as UNRESOLVED
 *      with a reason. The heat, the lot and the item are DERIVED from the
 *      certificate and from custody; the caller cannot declare them.
 *
 * Declared boundaries: this binds certificates to the PIPING custody chain.
 * Reconciliation with the legacy `material_lots` warehouse (ADR-047 cutover)
 * is a separate item, and HTTP/UI remains open.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Teach the nomination register the new action: asserting that a
        //    certificate covers received material is a quality claim, so it is
        //    default-deny behind the same register as every other claim.
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
    CHECK (action IN ('record_weld','record_ndt','record_pack_result','register_certificate','sign_off_stage','bind_certificate'));
END $$;
SQL);

        // 2. Custody can now attest a lot as well as a heat. Additive: old
        //    amendments know no lot, and that stays visible as NULL rather than
        //    being back-filled with a guess.
        DB::unprepared(<<<'SQL'
ALTER TABLE piping_custody_amendments ADD COLUMN lot_ref varchar(200);
ALTER TABLE piping_custody_amendments
  ADD CONSTRAINT piping_amendment_lot_shape CHECK (lot_ref IS NULL OR lot_ref ~ '[^[:space:]]');
SQL);

        // 3. The effective receipt also reports the effective lot, so a reader
        //    asks one question instead of re-deriving the amendment chain.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_piping_effective_receipt(rid uuid) RETURNS jsonb LANGUAGE plpgsql AS $$
DECLARE c piping_custody_records%ROWTYPE; a piping_custody_amendments%ROWTYPE; s piping_custody_amendment_sources%ROWTYPE;
BEGIN
 SELECT * INTO c FROM piping_custody_records WHERE id=rid;
 IF NOT FOUND THEN RETURN NULL; END IF;
 SELECT * INTO a FROM piping_custody_amendments WHERE custody_id=rid AND project_id=c.project_id ORDER BY sequence DESC LIMIT 1;
 IF NOT FOUND THEN RETURN jsonb_build_object('amendment_id',NULL,'source_line_id',c.source_line_id,'purchase_order_id',c.purchase_order_id,'source_snapshot',c.source_snapshot,'heat_ref',c.heat_ref,'lot_ref',NULL,'unlinked_reason',c.unlinked_reason); END IF;
 IF a.source_kind='LINKED' THEN
   SELECT * INTO s FROM piping_custody_amendment_sources WHERE amendment_id=a.id AND project_id=c.project_id;
   IF NOT FOUND THEN RETURN NULL; END IF;
 END IF;
 RETURN jsonb_build_object('amendment_id',a.id,'source_line_id',s.source_line_id,'purchase_order_id',s.purchase_order_id,'source_snapshot',s.source_snapshot,'heat_ref',a.heat_ref,'lot_ref',a.lot_ref,'unlinked_reason',a.unlinked_reason);
END $$;
SQL);

        // 4. The binding register.
        Schema::create('material_certificate_bindings', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('certificate_id')->constrained('material_certificates')->restrictOnDelete();
            // What the binding points at. NULL only for an UNRESOLVED binding.
            $t->foreignUuid('custody_id')->nullable()->constrained('piping_custody_records')->restrictOnDelete();
            $t->foreignUuid('custody_amendment_id')->nullable()->constrained('piping_custody_amendments')->restrictOnDelete();
            $t->string('binding', 24);
            // Derived from the certificate: the claim being bound.
            $t->string('heat_ref', 200);
            $t->string('lot_ref', 200)->nullable();
            // Derived from custody: which item the received material is.
            $t->string('item_code', 64)->nullable();
            $t->uuid('bound_policy_id');
            $t->text('unlinked_reason')->nullable();
            $t->foreignUuid('bound_by')->constrained('users')->restrictOnDelete();
            $t->uuid('request_id');
            $t->timestampTz('bound_at', 6)->useCurrent();
            $t->unique(['project_id', 'request_id'], 'mtr_binding_request_unique');
            $t->unique(['project_id', 'id'], 'mtr_binding_tenant_key');
            $t->unique(['certificate_id', 'custody_id'], 'mtr_binding_custody_unique');
            $t->index(['project_id', 'certificate_id'], 'mtr_binding_certificate_idx');
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE material_certificate_bindings ADD CONSTRAINT mtr_binding_shape CHECK (
  binding IN ('CUSTODY_LOT_HEAT','CUSTODY_HEAT','UNRESOLVED')
  AND heat_ref ~ '[^[:space:]]'
  -- The mode and the lot the certificate claims must agree. Written as
  -- implications, not as an equality: an UNRESOLVED certificate may still
  -- claim a lot -- that is precisely why it could not be bound.
  AND (binding <> 'CUSTODY_LOT_HEAT' OR lot_ref IS NOT NULL)
  AND (binding <> 'CUSTODY_HEAT' OR lot_ref IS NULL)
  -- An unresolved binding points at nothing and must say why; a bound one
  -- points at custody and carries no apology.
  AND (binding = 'UNRESOLVED') = (custody_id IS NULL)
  AND (binding = 'UNRESOLVED') = (unlinked_reason IS NOT NULL)
  AND (unlinked_reason IS NULL OR length(btrim(unlinked_reason)) > 0)
);

ALTER TABLE material_certificate_bindings ENABLE ROW LEVEL SECURITY;
ALTER TABLE material_certificate_bindings FORCE ROW LEVEL SECURITY;

CREATE POLICY mtr_binding_read ON material_certificate_bindings FOR SELECT USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = material_certificate_bindings.project_id
                 AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid)
  -- The certificate is the parent record: no certificate the session cannot
  -- see, no binding to read.
  AND EXISTS (SELECT 1 FROM material_certificates m
               WHERE m.id = material_certificate_bindings.certificate_id
                 AND m.project_id = material_certificate_bindings.project_id)
);

CREATE POLICY mtr_binding_insert ON material_certificate_bindings FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND bound_by = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND epcc_project_is_writable(project_id)
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = material_certificate_bindings.project_id
                 AND pc.company_id = material_certificate_bindings.company_id)
);

-- ------------------------------------------------------------------- triggers
CREATE OR REPLACE FUNCTION epcc_reject_mtr_binding_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  RAISE EXCEPTION 'MTR bindings are append-only; a correction is a new binding, never an edit'
    USING ERRCODE = '55000';
END; $$;

CREATE OR REPLACE FUNCTION epcc_mtr_binding_insert_path() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  IF pg_trigger_depth() <> 1 THEN
    RAISE EXCEPTION 'MTR_BIND_MANUAL_DIRECT_ONLY' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END; $$;

CREATE OR REPLACE FUNCTION epcc_guard_certificate_binding() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE c material_certificates%ROWTYPE;
        m_custody uuid; m_amendment uuid; m_item text; m_heat boolean := false;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('mtr-binding:' || NEW.certificate_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'MTR_BIND_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.bound_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'MTR_BIND_AUTHORITY' USING ERRCODE = '42501';
  END IF;

  NEW.bound_policy_id := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.bound_by, 'bind_certificate');
  IF NEW.bound_policy_id IS NULL THEN
    RAISE EXCEPTION 'MTR_BIND_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;

  -- The claim comes from the certificate; the caller states none of it.
  SELECT * INTO c FROM material_certificates
   WHERE id = NEW.certificate_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'MTR_CERTIFICATE_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  NEW.heat_ref := c.heat_ref;
  NEW.lot_ref := c.lot_ref;
  NEW.item_code := NULL;
  NEW.unlinked_reason := NULLIF(btrim(COALESCE(NEW.unlinked_reason, '')), '');

  -- Does custody attest this (lot, heat)? Exact match, never fuzzy: HEAT-77
  -- never merges with HEAT-7.
  SELECT r.id, (e.eff->>'amendment_id')::uuid, poi.item_code
    INTO m_custody, m_amendment, m_item
    FROM piping_custody_records r
    JOIN LATERAL (SELECT epcc_piping_effective_receipt(r.id) AS eff) e ON true
    LEFT JOIN purchase_order_items poi
           ON poi.id = NULLIF(e.eff->>'source_line_id', '')::uuid
   WHERE r.project_id = NEW.project_id
     AND btrim(COALESCE(e.eff->>'heat_ref', '')) = NEW.heat_ref
     AND (NEW.lot_ref IS NULL OR btrim(COALESCE(e.eff->>'lot_ref', '')) = NEW.lot_ref)
   ORDER BY r.id
   LIMIT 1;

  IF m_custody IS NOT NULL THEN
    NEW.binding := CASE WHEN NEW.lot_ref IS NULL THEN 'CUSTODY_HEAT' ELSE 'CUSTODY_LOT_HEAT' END;
    NEW.custody_id := m_custody;
    NEW.custody_amendment_id := m_amendment;
    NEW.item_code := m_item;
    NEW.unlinked_reason := NULL;
    RETURN NEW;
  END IF;

  -- The certificate claims a lot that custody does not attest: recording it at
  -- heat level would make the data say "this lot is covered" when custody only
  -- ever knew the heat. Either amend the custody chain with the lot, or record
  -- the certificate as unresolved and say why.
  SELECT true INTO m_heat
    FROM piping_custody_records r
    JOIN LATERAL (SELECT epcc_piping_effective_receipt(r.id) AS eff) e ON true
   WHERE r.project_id = NEW.project_id
     AND btrim(COALESCE(e.eff->>'heat_ref', '')) = NEW.heat_ref
   LIMIT 1;

  -- Nothing bound. With a reason the certificate is still a fact worth keeping,
  -- as an unresolved one; without a reason the refusal says exactly what is
  -- missing, so nobody has to guess why the bind failed.
  IF NEW.unlinked_reason IS NULL THEN
    IF m_heat THEN
      RAISE EXCEPTION 'MTR_LOT_NOT_ATTESTED' USING ERRCODE = '42501';
    END IF;
    RAISE EXCEPTION 'MTR_HEAT_NOT_IN_CUSTODY' USING ERRCODE = '42501';
  END IF;
  NEW.binding := 'UNRESOLVED';
  NEW.custody_id := NULL;
  NEW.custody_amendment_id := NULL;

  RETURN NEW;
END; $$;

CREATE TRIGGER mtr_bindings_immutable BEFORE UPDATE OR DELETE ON material_certificate_bindings
  FOR EACH ROW EXECUTE FUNCTION epcc_reject_mtr_binding_mutation();
CREATE TRIGGER mtr_bindings_insert_path BEFORE INSERT ON material_certificate_bindings
  FOR EACH ROW EXECUTE FUNCTION epcc_mtr_binding_insert_path();
CREATE TRIGGER mtr_bindings_guard BEFORE INSERT ON material_certificate_bindings
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_certificate_binding();
SQL);

        // Freeze the invoker read contract, house style.
        $policies = DB::scalar("SELECT array_agg(polname::text ORDER BY polname)::text FROM pg_policy WHERE polrelid = 'material_certificate_bindings'::regclass AND polcmd IN ('r','*')");
        if ($policies !== '{mtr_binding_read}') {
            throw new RuntimeException('Unexpected read policy on material_certificate_bindings; reconcile before installing.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('MTR custody bindings are forward-only quality records.');
    }
};
