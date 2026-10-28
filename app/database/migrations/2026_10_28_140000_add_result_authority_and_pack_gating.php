<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-048 A.4b slice 1 — who may record quality evidence, and what a pack may
 * claim.
 *
 * Two enforcement gaps closed at the database level, because an application
 * check is a suggestion to anyone holding a psql prompt:
 *
 *   1. RESULT AUTHORITY IS NOW NOMINATED, NOT IMPLIED. Slice 1 let any project
 *      member record a weld, an NDT result, a hydrostatic test or a material
 *      certificate. In a real quality system that is a falsification path: the
 *      project manager's convenience login can create the inspection record the
 *      client will read. Recording now requires an explicit, versioned,
 *      suspendable `result_recorder_policies` nomination that names a user or a
 *      role (default deny). The nomination that authorised a record is stamped
 *      on the row itself, so "who was allowed to say this" is auditable after
 *      the fact — the scope-proposal envelope precedent.
 *
 *   2. A PACK CAN NO LONGER CLAIM WHAT IT DID NOT DO. TESTED now requires at
 *      least one recorded pack test result; ACCEPTED requires that the pack's
 *      LATEST recorded result is PASS (so FAIL -> repair -> PASS lineage
 *      culminates in acceptance, but a lone FAIL cannot). ACCEPTED is terminal,
 *      and the DRAFT -> READY -> TESTED -> ACCEPTED order is enforced.
 *
 * Declared boundary: READY stays an application-level self-declaration (a crew
 * saying "we are ready to test"), not a DB-enforced evidence claim; and who may
 * AUTHOR a nomination policy is itself unscoped in this slice (any project
 * member company may nominate its own people) — employer/owner-scoped
 * authoring is A.4b slice 2, together with the HTTP/UI surface.
 */
return new class extends Migration
{
    /** The four evidence kinds, and the policy action that authorises each. */
    private const ACTIONS = [
        'record_weld',
        'record_ndt',
        'record_pack_result',
        'register_certificate',
    ];

    public function up(): void
    {
        Schema::create('result_recorder_policies', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('target_company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('action', 32);
            $t->unsignedInteger('version');
            $t->boolean('suspended');
            $t->jsonb('selectors');
            $t->string('reason', 2000);
            $t->timestampTz('created_at')->useCurrent();
            $t->unique(['project_id', 'target_company_id', 'action', 'version'], 'result_recorder_version_key');
            $t->unique(['project_id', 'id'], 'result_recorder_tenant_key');
        });

        foreach (['joint_weld_records', 'joint_ndt_results', 'test_pack_results', 'material_certificates'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->foreignUuid('recorder_policy_id')->nullable()
                    ->constrained('result_recorder_policies')->restrictOnDelete();
            });
        }

        DB::unprepared($this->policySql());
        DB::unprepared($this->guardSql());
        DB::unprepared($this->packGatingSql());

        // Freeze the invoker read contract, house style.
        $policies = DB::scalar("SELECT array_agg(polname::text ORDER BY polname)::text FROM pg_policy WHERE polrelid = 'result_recorder_policies'::regclass AND polcmd IN ('r', '*')");
        if ($policies !== '{result_recorder_read}') {
            throw new RuntimeException('Unexpected read policy on result_recorder_policies; reconcile before installing.');
        }
    }

    private function policySql(): string
    {
        return <<<'SQL'
ALTER TABLE result_recorder_policies ADD CHECK (
  version > 0 AND length(btrim(reason)) > 0
  AND action IN ('record_weld','record_ndt','record_pack_result','register_certificate')
  AND jsonb_typeof(selectors) = 'array'
  AND ((suspended AND selectors = '[]'::jsonb) OR (NOT suspended AND jsonb_array_length(selectors) > 0)));

-- Default deny: the newest policy for (project, target company, action) decides,
-- and a suspended one nominates nobody. The actor must still be an active member
-- of the target company, in this project, and - where a discipline scope is
-- declared - scoped to PIPING. A CIVIL-scoped engineer cannot sign a weld.
CREATE OR REPLACE FUNCTION epcc_result_nomination(pid uuid, cid uuid, aid uuid, act text)
RETURNS uuid LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
 WITH h AS (
   SELECT id, suspended, selectors FROM result_recorder_policies
   WHERE action = act AND project_id = pid AND target_company_id = cid
   ORDER BY version DESC LIMIT 1
 ) SELECT h.id FROM h
 JOIN users u ON u.id = aid AND u.company_id = cid AND u.is_active
 JOIN project_memberships pm ON pm.user_id = u.id AND pm.project_id = pid
 WHERE NOT h.suspended
 AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = pid AND pc.company_id = cid)
 AND (pm.discipline_scope IS NULL OR jsonb_exists(pm.discipline_scope, 'PIPING'))
 AND (jsonb_exists(h.selectors, 'user:' || aid::text)
      OR EXISTS (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes, r.code)
                 AND jsonb_exists(h.selectors, 'role:' || cid::text || ':' || r.code)))
$$;

ALTER TABLE result_recorder_policies ENABLE ROW LEVEL SECURITY;
ALTER TABLE result_recorder_policies FORCE ROW LEVEL SECURITY;
CREATE POLICY result_recorder_read ON result_recorder_policies FOR SELECT USING (
  (NULLIF(current_setting('epcc.project_id', true), '') IS NULL OR project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = result_recorder_policies.project_id AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid));
CREATE POLICY result_recorder_insert ON result_recorder_policies FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND created_by = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = result_recorder_policies.project_id AND pc.company_id = result_recorder_policies.company_id));
SQL;
    }

    /**
     * Re-declares the slice-1 guards with one added duty: stamp and require the
     * nomination that authorised the record. Bodies are otherwise unchanged.
     */
    private function guardSql(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION epcc_guard_joint_result() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE j joints%ROWTYPE; prev joint_ndt_results%ROWTYPE;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('joint-result:' || NEW.joint_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.recorded_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  -- Membership in the project is not authority to create inspection evidence.
  NEW.recorder_policy_id := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.recorded_by,
    CASE WHEN TG_TABLE_NAME = 'joint_weld_records' THEN 'record_weld' ELSE 'record_ndt' END);
  IF NEW.recorder_policy_id IS NULL THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;
  SELECT * INTO j FROM joints WHERE id = NEW.joint_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'RESULT_JOINT_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  -- Honest order: fit-up, then weld, then examination. A result on a MODELLED
  -- joint is a record of something that has not physically happened.
  IF j.state <> 'FITTED' THEN
    RAISE EXCEPTION 'RESULT_JOINT_NOT_FITTED' USING ERRCODE = '23514';
  END IF;
  IF TG_TABLE_NAME = 'joint_ndt_results' THEN
    IF NOT EXISTS (SELECT 1 FROM joint_weld_records w WHERE w.joint_id = NEW.joint_id AND w.project_id = NEW.project_id) THEN
      RAISE EXCEPTION 'RESULT_NDT_NEEDS_WELD' USING ERRCODE = '23514';
    END IF;
    IF NEW.previous_result_id IS NOT NULL THEN
      SELECT * INTO prev FROM joint_ndt_results WHERE id = NEW.previous_result_id AND project_id = NEW.project_id;
      IF NOT FOUND OR prev.joint_id <> NEW.joint_id OR prev.method <> NEW.method OR prev.result <> 'REJECTED' THEN
        RAISE EXCEPTION 'RESULT_PREVIOUS_NOT_REJECTED' USING ERRCODE = '23514';
      END IF;
    END IF;
    IF NEW.report_document_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM documents d WHERE d.id = NEW.report_document_id AND d.project_id = NEW.project_id) THEN
      RAISE EXCEPTION 'RESULT_REPORT_DOCUMENT_NOT_VISIBLE' USING ERRCODE = '42501';
    END IF;
  END IF;
  RETURN NEW;
END; $$;

CREATE OR REPLACE FUNCTION epcc_guard_test_pack_result() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE p piping_test_packs%ROWTYPE; prev test_pack_results%ROWTYPE;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('pack-result:' || NEW.test_pack_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.recorded_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  NEW.recorder_policy_id := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.recorded_by, 'record_pack_result');
  IF NEW.recorder_policy_id IS NULL THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;
  SELECT * INTO p FROM piping_test_packs WHERE id = NEW.test_pack_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'RESULT_PACK_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  -- No test result for a pack nobody has declared ready.
  IF p.state = 'DRAFT' THEN
    RAISE EXCEPTION 'RESULT_PACK_NOT_READY' USING ERRCODE = '23514';
  END IF;
  IF NEW.previous_result_id IS NOT NULL THEN
    SELECT * INTO prev FROM test_pack_results WHERE id = NEW.previous_result_id AND project_id = NEW.project_id;
    IF NOT FOUND OR prev.test_pack_id <> NEW.test_pack_id OR prev.result <> 'FAIL' THEN
      RAISE EXCEPTION 'RESULT_PREVIOUS_NOT_FAILED' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END; $$;

CREATE OR REPLACE FUNCTION epcc_guard_material_certificate() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.registered_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  NEW.recorder_policy_id := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.registered_by, 'register_certificate');
  IF NEW.recorder_policy_id IS NULL THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;
  -- A cited document must exist and be visible to the registrar; a dangling
  -- evidence pointer is worse than no pointer.
  IF NEW.document_id IS NOT NULL AND NOT EXISTS (
      SELECT 1 FROM documents d WHERE d.id = NEW.document_id AND d.project_id = NEW.project_id) THEN
    RAISE EXCEPTION 'MTR_DOCUMENT_NOT_VISIBLE' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END; $$;
SQL;
    }

    private function packGatingSql(): string
    {
        return <<<'SQL'
-- A pack's state is a claim about physical reality, so the database holds it to
-- the evidence. READY remains an application-level declaration (see migration
-- header): a crew promising it is ready is not yet a falsifiable claim.
CREATE OR REPLACE FUNCTION epcc_guard_test_pack_transition() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE latest text; n integer;
BEGIN
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'PACK_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  -- An accepted pack is a handover artefact. Reopening it silently would let a
  -- completed dossier change under the client who already signed for it.
  IF OLD.state = 'ACCEPTED' THEN
    RAISE EXCEPTION 'PACK_ACCEPTED_TERMINAL' USING ERRCODE = '23514';
  END IF;
  -- Metadata-only edits (notes, titles, planned pressure) are not claims.
  IF NEW.state IS NOT DISTINCT FROM OLD.state THEN
    RETURN NEW;
  END IF;
  IF NOT ((OLD.state = 'DRAFT' AND NEW.state = 'READY')
       OR (OLD.state = 'READY' AND NEW.state = 'TESTED')
       OR (OLD.state = 'TESTED' AND NEW.state = 'ACCEPTED')) THEN
    RAISE EXCEPTION 'PACK_INVALID_TRANSITION' USING ERRCODE = '23514';
  END IF;
  IF NEW.state = 'TESTED' THEN
    SELECT count(*) INTO n FROM test_pack_results r
     WHERE r.project_id = NEW.project_id AND r.test_pack_id = NEW.id;
    IF n = 0 THEN
      RAISE EXCEPTION 'PACK_TESTED_WITHOUT_RESULT' USING ERRCODE = '23514';
    END IF;
  END IF;
  IF NEW.state = 'ACCEPTED' THEN
    -- Latest recorded result decides, so FAIL -> repair -> PASS lineage can
    -- culminate in acceptance, but a lone FAIL never can.
    SELECT r.result INTO latest FROM test_pack_results r
     WHERE r.project_id = NEW.project_id AND r.test_pack_id = NEW.id
     ORDER BY r.recorded_at DESC, r.id DESC LIMIT 1;
    IF latest IS NULL OR latest <> 'PASS' THEN
      RAISE EXCEPTION 'PACK_ACCEPTED_WITHOUT_PASS' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END; $$;

CREATE TRIGGER test_pack_transition_guard BEFORE UPDATE ON piping_test_packs FOR EACH ROW EXECUTE FUNCTION epcc_guard_test_pack_transition();
SQL;
    }

    public function down(): void
    {
        throw new RuntimeException('Result authority and pack gating are forward-only safety rules.');
    }
};
