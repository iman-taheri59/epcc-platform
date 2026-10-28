<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ITR compiler — slice 1 (ADR-048 decision point 5: itr_generation=both).
 *
 * On-demand compilation is a pure read (SQL functions, no storage). This
 * migration adds the frozen artefact: `itr_snapshots`, digest-bound like the
 * scope-proposal envelopes. The dossier and its completeness report are
 * derived BY THE TRIGGER from live tables — a client declaration can never
 * override them, so a snapshot cannot claim results that do not exist.
 *
 * House honesty rules baked in:
 *   - The dossier kind is literally ITR_COMPILATION_NOT_A_CERTIFICATE.
 *   - The completeness report ENUMERATES every missing item (unreleased
 *     lines, unfitted joints, joints without weld/NDT, open rejections,
 *     packs without a passed result) — never silently omitted.
 *   - digest = sha256(dossier::text), trigger-derived; append-only; RLS+FORCE.
 *
 * Declared slice boundaries (recorded in ADR-048 / TODO A.5b):
 *   - Scope is TEST_PACK only; system/dossier scopes come later.
 *   - ITP notifications and receipts (GRN) are NOT assembled: no pack↔
 *     notification link exists in the schema, and receipt binding needs
 *     piece-level custody (A.4b lot/heat pairing). Enumerating unbound data
 *     would be padding, not honesty.
 *   - Certificates (MTR) are not bound to packs for the same reason; the
 *     exact-heat reader from ADR-048 slice 1 remains the traceability tool.
 *   - PDF export is optional at runtime (existing PdfRenderer/Chromium);
 *     the snapshot itself is data, not bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('itr_snapshots', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->string('scope_kind', 24);
            $t->uuid('test_pack_id')->nullable();
            // All three are trigger-derived on insert; placeholders are overwritten.
            $t->jsonb('dossier');
            $t->jsonb('completeness');
            $t->char('digest', 64);
            $t->string('state', 32);
            $t->uuid('compiled_by');
            $t->uuid('request_id');
            $t->timestampTz('compiled_at', 6)->useCurrent();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('test_pack_id')->references('id')->on('piping_test_packs')->restrictOnDelete();
            $t->foreign('compiled_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['project_id', 'request_id']);
            $t->unique(['project_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE itr_snapshots ADD CHECK (
  scope_kind = 'TEST_PACK' AND test_pack_id IS NOT NULL AND state = 'FROZEN'
  AND digest ~ '^[a-f0-9]{64}$'
  AND jsonb_typeof(dossier) = 'object' AND jsonb_typeof(completeness) = 'object');

-- The ONE compilation body: used by the freeze trigger AND by on-demand reads,
-- so a preview and a frozen snapshot of the same moment are the same document.
CREATE OR REPLACE FUNCTION epcc_itr_test_pack_dossier(pid uuid, pack uuid)
RETURNS jsonb LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
  SELECT jsonb_build_object(
    'schema_version', 1,
    'kind', 'ITR_COMPILATION_NOT_A_CERTIFICATE',
    'project_id', pid,
    'test_pack_id', pack,
    'pack', (SELECT jsonb_build_object(
        'code', tp.code, 'title_en', tp.title_en, 'title_fa', tp.title_fa, 'state', tp.state,
        'test_pressure', tp.test_pressure, 'test_pressure_unit', tp.test_pressure_unit,
        'subsystem_code', s.code, 'ready_at', tp.ready_at, 'tested_at', tp.tested_at,
        'accepted_at', tp.accepted_at)
      FROM piping_test_packs tp LEFT JOIN subsystems s ON s.id = tp.subsystem_id
      WHERE tp.id = pack AND tp.project_id = pid),
    'lines', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'line_id', l.id, 'line_number', l.line_number, 'release_state', l.release_state,
        'nominal_size', l.nominal_size) ORDER BY l.line_number), '[]'::jsonb)
      FROM piping_lines l WHERE l.project_id = pid AND l.test_pack_id = pack),
    'joints', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'joint_id', j.id, 'joint_seq', j.joint_seq, 'state', j.state,
        'line_number', l.line_number, 'fitted_by_code', j.fitted_by_code, 'fitted_at', j.fitted_at,
        'welds', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
            'id', w.id, 'kind', w.kind, 'welded_by_code', w.welded_by_code, 'welded_at', w.welded_at,
            'weld_process', w.weld_process, 'consumable_lot_ref', w.consumable_lot_ref,
            'reason', w.reason, 'mode', w.mode, 'performer_org', w.performer_org,
            'performer_name', w.performer_name, 'report_ref', w.report_ref,
            'recorded_at', w.recorded_at) ORDER BY w.recorded_at, w.id), '[]'::jsonb)
          FROM joint_weld_records w WHERE w.project_id = pid AND w.joint_id = j.id),
        'ndt', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
            'id', n.id, 'method', n.method, 'result', n.result, 'reject_reason', n.reject_reason,
            'previous_result_id', n.previous_result_id, 'performed_by_code', n.performed_by_code,
            'performed_at', n.performed_at, 'technique_ref', n.technique_ref,
            'acceptance_criteria_ref', n.acceptance_criteria_ref, 'mode', n.mode,
            'performer_org', n.performer_org, 'performer_name', n.performer_name,
            'report_ref', n.report_ref, 'recorded_at', n.recorded_at)
          ORDER BY n.recorded_at, n.id), '[]'::jsonb)
          FROM joint_ndt_results n WHERE n.project_id = pid AND n.joint_id = j.id))
        ORDER BY l.line_number, j.joint_seq NULLS LAST), '[]'::jsonb)
      FROM joints j JOIN piping_lines l ON l.id = j.piping_line_id
      WHERE j.project_id = pid AND l.test_pack_id = pack),
    'test_results', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'id', r.id, 'test_type', r.test_type, 'medium', r.medium, 'test_pressure', r.test_pressure,
        'pressure_unit', r.pressure_unit, 'hold_duration_minutes', r.hold_duration_minutes,
        'started_at', r.started_at, 'completed_at', r.completed_at, 'result', r.result,
        'failure_reason', r.failure_reason, 'previous_result_id', r.previous_result_id,
        'mode', r.mode, 'performer_org', r.performer_org, 'performer_name', r.performer_name,
        'report_ref', r.report_ref, 'recorded_at', r.recorded_at)
      ORDER BY r.recorded_at, r.id), '[]'::jsonb)
      FROM test_pack_results r WHERE r.project_id = pid AND r.test_pack_id = pack));
$$;

-- The completeness report: an explicit enumeration, NOT a certificate.
CREATE OR REPLACE FUNCTION epcc_itr_test_pack_completeness(pid uuid, pack uuid)
RETURNS jsonb LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
  WITH lines AS (
    SELECT id, line_number, release_state FROM piping_lines
    WHERE project_id = pid AND test_pack_id = pack),
  pack_joints AS (
    SELECT j.id, j.joint_seq, j.state, l.line_number,
      EXISTS(SELECT 1 FROM joint_weld_records w WHERE w.project_id = pid AND w.joint_id = j.id) AS has_weld,
      EXISTS(SELECT 1 FROM joint_ndt_results n WHERE n.project_id = pid AND n.joint_id = j.id) AS has_ndt
    FROM joints j JOIN lines l ON l.id = j.piping_line_id),
  open_rejections AS (
    SELECT DISTINCT ON (n.joint_id, n.method) n.joint_id, n.method, n.result
    FROM joint_ndt_results n JOIN pack_joints pj ON pj.id = n.joint_id
    WHERE n.project_id = pid
    ORDER BY n.joint_id, n.method, n.performed_at DESC, n.recorded_at DESC, n.id DESC),
  m_lines AS (
    SELECT COALESCE(jsonb_agg(jsonb_build_object('line_number', line_number, 'release_state', release_state)
      ORDER BY line_number), '[]'::jsonb) AS arr
    FROM lines WHERE release_state <> 'RELEASED'),
  m_unfitted AS (
    SELECT COALESCE(jsonb_agg(jsonb_build_object('line_number', line_number, 'joint_seq', joint_seq, 'state', state)
      ORDER BY line_number, joint_seq NULLS LAST), '[]'::jsonb) AS arr
    FROM pack_joints WHERE state <> 'FITTED'),
  m_no_weld AS (
    SELECT COALESCE(jsonb_agg(jsonb_build_object('line_number', line_number, 'joint_seq', joint_seq)
      ORDER BY line_number, joint_seq NULLS LAST), '[]'::jsonb) AS arr
    FROM pack_joints WHERE NOT has_weld),
  m_no_ndt AS (
    SELECT COALESCE(jsonb_agg(jsonb_build_object('line_number', line_number, 'joint_seq', joint_seq)
      ORDER BY line_number, joint_seq NULLS LAST), '[]'::jsonb) AS arr
    FROM pack_joints WHERE NOT has_ndt),
  m_open AS (
    SELECT COALESCE(jsonb_agg(DISTINCT jsonb_build_object('line_number', pj.line_number, 'joint_seq', pj.joint_seq)
      ), '[]'::jsonb) AS arr
    FROM open_rejections o JOIN pack_joints pj ON pj.id = o.joint_id WHERE o.result = 'REJECTED'),
  passed AS (
    SELECT EXISTS(SELECT 1 FROM test_pack_results r
      WHERE r.project_id = pid AND r.test_pack_id = pack AND r.result = 'PASS') AS ok),
  counts AS (
    SELECT (SELECT count(*) FROM lines) AS lines, (SELECT count(*) FROM pack_joints) AS joints,
      (SELECT count(*) FROM joint_weld_records w JOIN pack_joints pj ON pj.id = w.joint_id AND w.project_id = pid) AS welds,
      (SELECT count(*) FROM joint_ndt_results n JOIN pack_joints pj ON pj.id = n.joint_id AND n.project_id = pid) AS ndt,
      (SELECT count(*) FROM test_pack_results r WHERE r.project_id = pid AND r.test_pack_id = pack) AS test_results)
  SELECT jsonb_build_object(
    'schema_version', 1,
    'is_complete', (jsonb_array_length(m_lines.arr) = 0 AND jsonb_array_length(m_unfitted.arr) = 0
      AND jsonb_array_length(m_no_weld.arr) = 0 AND jsonb_array_length(m_no_ndt.arr) = 0
      AND jsonb_array_length(m_open.arr) = 0 AND passed.ok),
    'counts', (SELECT jsonb_build_object('lines', lines, 'joints', joints, 'welds', welds, 'ndt', ndt,
      'test_results', test_results) FROM counts),
    'missing', jsonb_build_object(
      'lines_not_released', m_lines.arr,
      'joints_not_fitted', m_unfitted.arr,
      'joints_without_weld_record', m_no_weld.arr,
      'joints_without_ndt', m_no_ndt.arr,
      'joints_with_open_rejection', m_open.arr,
      'pack_without_passed_result', NOT passed.ok))
  FROM m_lines, m_unfitted, m_no_weld, m_no_ndt, m_open, passed;
$$;

CREATE OR REPLACE FUNCTION epcc_reject_itr_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  RAISE EXCEPTION 'itr snapshots are append-only; corrections are new frozen snapshots, never edits'
    USING ERRCODE = '55000';
END; $$;

CREATE OR REPLACE FUNCTION epcc_itr_insert_path() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  IF pg_trigger_depth() <> 1 THEN
    RAISE EXCEPTION 'ITR_MANUAL_DIRECT_ONLY' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END; $$;

CREATE OR REPLACE FUNCTION epcc_guard_itr_snapshot() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE p piping_test_packs%ROWTYPE;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('itr-snapshot:' || NEW.test_pack_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'ITR_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.compiled_by IS DISTINCT FROM NULLIF(current_setting('epcc.itr_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'ITR_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  SELECT * INTO p FROM piping_test_packs WHERE id = NEW.test_pack_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'ITR_PACK_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  -- A handover snapshot of a pack nobody declared ready is premature fiction.
  IF p.state = 'DRAFT' THEN
    RAISE EXCEPTION 'ITR_PACK_NOT_READY' USING ERRCODE = '23514';
  END IF;
  -- Trigger independently derives dossier/completeness/digest; client
  -- declarations cannot override them (scope-proposal envelope precedent).
  NEW.dossier := epcc_itr_test_pack_dossier(NEW.project_id, NEW.test_pack_id);
  NEW.completeness := epcc_itr_test_pack_completeness(NEW.project_id, NEW.test_pack_id);
  NEW.digest := encode(sha256(convert_to(NEW.dossier::text, 'UTF8')), 'hex');
  NEW.state := 'FROZEN';
  NEW.compiled_at := clock_timestamp();
  RETURN NEW;
END; $$;

ALTER TABLE itr_snapshots ENABLE ROW LEVEL SECURITY;
ALTER TABLE itr_snapshots FORCE ROW LEVEL SECURITY;
CREATE POLICY itr_snapshot_read ON itr_snapshots FOR SELECT USING (
  (NULLIF(current_setting('epcc.project_id', true), '') IS NULL OR project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = itr_snapshots.project_id AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid));
CREATE POLICY itr_snapshot_insert ON itr_snapshots FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND compiled_by = NULLIF(current_setting('epcc.itr_actor_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = itr_snapshots.project_id AND pc.company_id = itr_snapshots.company_id));

CREATE TRIGGER itr_snapshots_immutable BEFORE UPDATE OR DELETE ON itr_snapshots FOR EACH ROW EXECUTE FUNCTION epcc_reject_itr_mutation();
CREATE TRIGGER itr_snapshots_insert_path BEFORE INSERT ON itr_snapshots FOR EACH ROW EXECUTE FUNCTION epcc_itr_insert_path();
CREATE TRIGGER itr_snapshots_guard BEFORE INSERT ON itr_snapshots FOR EACH ROW EXECUTE FUNCTION epcc_guard_itr_snapshot();
SQL);

        // Freeze the invoker read contract, house style.
        $policies = DB::scalar("SELECT array_agg(polname::text ORDER BY polname)::text FROM pg_policy WHERE polrelid = 'itr_snapshots'::regclass AND polcmd IN ('r', '*')");
        if ($policies !== '{itr_snapshot_read}') {
            throw new RuntimeException('Unexpected read policy on itr_snapshots; reconcile before installing.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Frozen ITR snapshots are forward-only.');
    }
};
