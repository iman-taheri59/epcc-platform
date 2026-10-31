<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADR-048 A.5b slice 3d — the two physical rungs: AREA and FACILITY.
 *
 * Slice 3c gave the dossier a SYSTEM scope, and the reason it could was that
 * the breakdown had grown the level to point at. The same argument now applies
 * to the levels that were there all along: a dossier could be compiled for a
 * test pack, a subsystem, a system or the whole project, but not for an area or
 * a facility — the two levels ADR-004 §4 introduced as the physical tree, and
 * the two a construction-side reader actually works in.
 *
 * Why they are worth having at all, given the system scope already exists:
 * they are the other direction of the same cross-reference. A system dossier
 * says which subsystems and facilities it gathers; an area dossier says which
 * subsystems it holds and — the part nothing else in the platform can say —
 * which SYSTEMS touch it. That is the question Advanced Work Packaging insists
 * be answerable at all times: "two maps, kept live", the area map and the
 * system map, with a maintained cross-reference between them. In most projects
 * that cross-reference is a spreadsheet that goes stale. Here it is a join
 * over the same rows, printed on the document.
 *
 * The fixture makes the point better than prose can: process area AR-22 holds
 * the cooling water pump set AND the firefighting pump house, so its dossier
 * names two systems, one of which also has a subsystem in the utilities block.
 * An area is not a system with a shorter name, and a document that printed only
 * subsystems would hide that.
 *
 * Two semantics that are decided here rather than left to be discovered:
 *
 *   - FACILITY gathers every subsystem that names it, including the ones that
 *     name no area at all. Otherwise a facility like PH-14-PL-02, which uses no
 *     areas, would have a dossier that silently omitted all of itself.
 *   - AREA gathers only the subsystems that name that area. A subsystem hanging
 *     straight off the facility is in the facility's dossier and in no area's,
 *     which is the honest answer: it has no area.
 *
 * `schema_version` 4 -> 5. The SYSTEM scope gained `area_codes` so that all
 * three grouping levels answer the same shape of question — what do you hold,
 * and where do you sit — and a reader of a system dossier can see which areas
 * to walk. The pack-scope functions remain wrappers.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. The CHECK admits the two levels the breakdown has always had.
        DB::unprepared(<<<'SQL'
DO $$
DECLARE c name;
BEGIN
  SELECT conname INTO c FROM pg_constraint
   WHERE conrelid = 'itr_snapshots'::regclass AND contype = 'c'
     AND pg_get_constraintdef(oid) LIKE '%%scope_kind%%';
  IF c IS NULL THEN
    RAISE EXCEPTION 'itr_snapshots scope CHECK not found; refusing to widen it blindly';
  END IF;
  EXECUTE format('ALTER TABLE itr_snapshots DROP CONSTRAINT %I', c);
  ALTER TABLE itr_snapshots ADD CONSTRAINT itr_snapshots_check CHECK (
    (state)::text = 'FROZEN'::text
    AND digest ~ '^[a-f0-9]{64}$'::text
    AND jsonb_typeof(dossier) = 'object'::text
    AND jsonb_typeof(completeness) = 'object'::text
    AND (
      -- A pack dossier is about one pack. (Rows frozen before slice 3a carry
      -- test_pack_id and no scope_ref; that is still exactly one pack.)
      ((scope_kind)::text = 'TEST_PACK'::text AND test_pack_id IS NOT NULL
       AND (scope_ref IS NULL OR scope_ref = test_pack_id))
      -- A subsystem dossier is about one subsystem, and no single pack.
      OR ((scope_kind)::text = 'SUBSYSTEM'::text AND scope_ref IS NOT NULL AND test_pack_id IS NULL)
      -- A system dossier: every line of every subsystem serving it, whatever
      -- facility those subsystems sit in.
      OR ((scope_kind)::text = 'SYSTEM'::text AND scope_ref IS NOT NULL AND test_pack_id IS NULL)
      -- An area dossier: every line of every subsystem inside that area. A
      -- subsystem that names no area is in no area's dossier — it belongs to
      -- its facility's, and only there.
      OR ((scope_kind)::text = 'AREA'::text AND scope_ref IS NOT NULL AND test_pack_id IS NULL)
      -- A facility dossier gathers its subsystems whether or not they name an
      -- area, so a facility without areas still has a dossier of all of itself.
      OR ((scope_kind)::text = 'FACILITY'::text AND scope_ref IS NOT NULL AND test_pack_id IS NULL)
      -- The project needs no reference: the row already names the project.
      OR ((scope_kind)::text = 'PROJECT'::text AND scope_ref IS NULL AND test_pack_id IS NULL)
    ));
END $$;
SQL);

        // 2. One resolver: which lines does this scope mean. Still the only
        //    definition of scope membership in the platform, and still read by
        //    both the dossier and the completeness report.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_itr_scope_lines(pid uuid, kind text, ref uuid)
RETURNS TABLE (line_id uuid, line_number character varying, release_state character varying,
               subsystem_id uuid, test_pack_id uuid)
LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
  SELECT l.id, l.line_number, l.release_state, l.subsystem_id, l.test_pack_id
    FROM piping_lines l
   WHERE l.project_id = pid
     AND CASE kind
           WHEN 'TEST_PACK' THEN ref IS NOT NULL AND l.test_pack_id = ref
           WHEN 'SUBSYSTEM' THEN ref IS NOT NULL AND l.subsystem_id  = ref
           WHEN 'SYSTEM'    THEN ref IS NOT NULL AND l.subsystem_id IN (
                                  SELECT ss.id FROM subsystems ss
                                   WHERE ss.system_id = ref AND ss.project_id = pid)
           WHEN 'AREA'      THEN ref IS NOT NULL AND l.subsystem_id IN (
                                  SELECT ss.id FROM subsystems ss
                                   WHERE ss.area_id = ref AND ss.project_id = pid)
           WHEN 'FACILITY'  THEN ref IS NOT NULL AND l.subsystem_id IN (
                                  SELECT ss.id FROM subsystems ss
                                   WHERE ss.facility_id = ref AND ss.project_id = pid)
           WHEN 'PROJECT'   THEN ref IS NULL
           ELSE false
         END;
$$;
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_itr_scope_dossier(pid uuid, kind text, ref uuid)
RETURNS jsonb LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
  WITH scope_lines AS (SELECT * FROM epcc_itr_scope_lines(pid, kind, ref)),
  scope_packs AS (
    -- One pack, or every pack that owns a line in scope. A pack with no lines
    -- assigned to it still belongs to its own dossier, as it always has.
    SELECT tp.id FROM piping_test_packs tp
     WHERE tp.project_id = pid AND kind = 'TEST_PACK' AND tp.id = ref
    UNION
    SELECT DISTINCT sl.test_pack_id FROM scope_lines sl
     WHERE sl.test_pack_id IS NOT NULL AND kind <> 'TEST_PACK'
  ),
  notices AS (
    SELECT n.id, n.stage_id, n.number, n.state, s.point, s.sequence, n.work_reference, n.location,
           n.scheduled_at, n.issued_at, epcc_pack_notice_state(pid, n.id) AS hold_state
      FROM piping_test_pack_notifications ln
      JOIN inspection_notifications n ON n.id = ln.notification_id AND n.project_id = pid
      JOIN inspection_itp_stages s ON s.id = n.stage_id AND s.project_id = pid
     WHERE ln.project_id = pid AND ln.test_pack_id IN (SELECT id FROM scope_packs))
  SELECT jsonb_build_object(
    'schema_version', 5,
    'kind', 'ITR_COMPILATION_NOT_A_CERTIFICATE',
    'project_id', pid,
    'scope_kind', kind,
    'scope_ref', ref,
    -- What A.5 published and what a pack dossier has always carried: null the
    -- moment the dossier is about more than one pack.
    'test_pack_id', CASE WHEN kind = 'TEST_PACK' THEN ref END,
    'scope', (CASE kind
      WHEN 'TEST_PACK' THEN (SELECT jsonb_build_object(
          'kind', 'TEST_PACK', 'code', tp.code, 'title_en', tp.title_en, 'title_fa', tp.title_fa,
          'state', tp.state, 'subsystem_code', s.code,
          'test_pressure', tp.test_pressure, 'test_pressure_unit', tp.test_pressure_unit,
          'ready_at', tp.ready_at, 'tested_at', tp.tested_at, 'accepted_at', tp.accepted_at)
        FROM piping_test_packs tp LEFT JOIN subsystems s ON s.id = tp.subsystem_id
        WHERE tp.id = ref AND tp.project_id = pid)
      WHEN 'SUBSYSTEM' THEN (SELECT jsonb_build_object(
          'kind', 'SUBSYSTEM', 'code', ss.code, 'name_en', ss.name_en, 'name_fa', ss.name_fa,
          'discipline', ss.discipline)
        FROM subsystems ss WHERE ss.id = ref AND ss.project_id = pid)
      WHEN 'SYSTEM' THEN (SELECT jsonb_build_object(
          'kind', 'SYSTEM', 'code', sy.code, 'name_en', sy.name_en, 'name_fa', sy.name_fa,
          -- Which subsystems the system gathers, and where they sit. Printed
          -- because a system is allowed to cross facilities: a reader holding
          -- this document is entitled to see that it spans two plants, and the
          -- area-side reader needs to know which areas to walk.
          'subsystem_codes', (SELECT COALESCE(jsonb_agg(ss.code ORDER BY ss.code), '[]'::jsonb)
                                FROM subsystems ss WHERE ss.system_id = ref AND ss.project_id = pid),
          'facility_codes', (SELECT COALESCE(jsonb_agg(c ORDER BY c), '[]'::jsonb)
                               FROM (SELECT DISTINCT f.code AS c
                                       FROM subsystems ss JOIN facilities f ON f.id = ss.facility_id
                                      WHERE ss.system_id = ref AND ss.project_id = pid) x),
          'area_codes', (SELECT COALESCE(jsonb_agg(c ORDER BY c), '[]'::jsonb)
                           FROM (SELECT DISTINCT a.code AS c
                                   FROM subsystems ss JOIN areas a ON a.id = ss.area_id
                                  WHERE ss.system_id = ref AND ss.project_id = pid) x))
        FROM systems sy WHERE sy.id = ref AND sy.project_id = pid)
      WHEN 'AREA' THEN (SELECT jsonb_build_object(
          'kind', 'AREA', 'code', ar.code, 'name_en', ar.name_en, 'name_fa', ar.name_fa,
          'facility_code', (SELECT f.code FROM facilities f WHERE f.id = ar.facility_id),
          -- The cross-reference the area-side reader needs: which subsystems
          -- sit here, and which SYSTEMS reach into this area from outside it.
          'subsystem_codes', (SELECT COALESCE(jsonb_agg(ss.code ORDER BY ss.code), '[]'::jsonb)
                                FROM subsystems ss WHERE ss.area_id = ref AND ss.project_id = pid),
          'system_codes', (SELECT COALESCE(jsonb_agg(c ORDER BY c), '[]'::jsonb)
                             FROM (SELECT DISTINCT sy.code AS c
                                     FROM subsystems ss JOIN systems sy ON sy.id = ss.system_id
                                    WHERE ss.area_id = ref AND ss.project_id = pid) x))
        FROM areas ar WHERE ar.id = ref AND ar.project_id = pid)
      WHEN 'FACILITY' THEN (SELECT jsonb_build_object(
          'kind', 'FACILITY', 'code', fc.code, 'name_en', fc.name_en, 'name_fa', fc.name_fa,
          -- Every subsystem that names this facility, including the ones with
          -- no area at all: a facility that uses no areas must still have a
          -- dossier of all of itself.
          'subsystem_codes', (SELECT COALESCE(jsonb_agg(ss.code ORDER BY ss.code), '[]'::jsonb)
                                FROM subsystems ss WHERE ss.facility_id = ref AND ss.project_id = pid),
          'area_codes', (SELECT COALESCE(jsonb_agg(a.code ORDER BY a.code), '[]'::jsonb)
                           FROM areas a WHERE a.facility_id = ref AND a.project_id = pid),
          'system_codes', (SELECT COALESCE(jsonb_agg(c ORDER BY c), '[]'::jsonb)
                             FROM (SELECT DISTINCT sy.code AS c
                                     FROM subsystems ss JOIN systems sy ON sy.id = ss.system_id
                                    WHERE ss.facility_id = ref AND ss.project_id = pid) x))
        FROM facilities fc WHERE fc.id = ref AND fc.project_id = pid)
      WHEN 'PROJECT' THEN (SELECT jsonb_build_object(
          'kind', 'PROJECT', 'code', pr.code, 'name_en', pr.name_en, 'name_fa', pr.name_fa)
        FROM projects pr WHERE pr.id = pid)
      ELSE 'null'::jsonb END),
    'pack', (SELECT jsonb_build_object(
        'code', tp.code, 'title_en', tp.title_en, 'title_fa', tp.title_fa, 'state', tp.state,
        'test_pressure', tp.test_pressure, 'test_pressure_unit', tp.test_pressure_unit,
        'subsystem_code', s.code, 'ready_at', tp.ready_at, 'tested_at', tp.tested_at,
        'accepted_at', tp.accepted_at)
      FROM piping_test_packs tp LEFT JOIN subsystems s ON s.id = tp.subsystem_id
      WHERE kind = 'TEST_PACK' AND tp.id = ref AND tp.project_id = pid),
    'test_packs', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'pack_id', tp.id, 'code', tp.code, 'state', tp.state,
        'title_en', tp.title_en, 'title_fa', tp.title_fa,
        'subsystem_code', s.code,
        'test_pressure', tp.test_pressure, 'test_pressure_unit', tp.test_pressure_unit,
        'ready_at', tp.ready_at, 'tested_at', tp.tested_at, 'accepted_at', tp.accepted_at)
      ORDER BY tp.code), '[]'::jsonb)
      FROM piping_test_packs tp LEFT JOIN subsystems s ON s.id = tp.subsystem_id
      WHERE tp.id IN (SELECT id FROM scope_packs)),
    'lines', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'line_id', sl.line_id, 'line_number', sl.line_number, 'release_state', sl.release_state,
        'nominal_size', l.nominal_size, 'subsystem_code', s.code, 'test_pack_code', tp.code)
      ORDER BY sl.line_number), '[]'::jsonb)
      FROM scope_lines sl
      JOIN piping_lines l ON l.id = sl.line_id
      LEFT JOIN subsystems s ON s.id = sl.subsystem_id
      LEFT JOIN piping_test_packs tp ON tp.id = sl.test_pack_id),
    'joints', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'joint_id', j.id, 'joint_seq', j.joint_seq, 'state', j.state,
        'line_number', sl.line_number, 'fitted_by_code', j.fitted_by_code, 'fitted_at', j.fitted_at,
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
      ORDER BY sl.line_number, j.joint_seq NULLS LAST), '[]'::jsonb)
      FROM joints j JOIN scope_lines sl ON sl.line_id = j.piping_line_id
      WHERE j.project_id = pid),
    'notifications', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'notification_id', n.id, 'number', n.number, 'state', n.state,
        'point', s.point, 'stage_sequence', s.sequence,
        'stage_description_en', s.description_en, 'stage_description_fa', s.description_fa,
        'work_reference', n.work_reference, 'location', n.location,
        'scheduled_at', n.scheduled_at, 'issued_at', n.issued_at,
        'hold_state', n.hold_state,
        'cancelled', EXISTS (SELECT 1 FROM inspection_notification_cancellations c
                              WHERE c.notification_id = n.id AND c.project_id = pid),
        'signoffs', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
            'id', so.id, 'outcome', so.outcome, 'point', so.point, 'reason', so.reason,
            'signed_at', so.signed_at, 'recorded_at', so.recorded_at,
            'previous_signoff_id', so.previous_signoff_id)
          ORDER BY so.recorded_at, so.id), '[]'::jsonb)
          FROM inspection_stage_signoffs so
          WHERE so.project_id = pid AND so.notification_id = n.id))
      ORDER BY s.sequence, n.number), '[]'::jsonb)
      FROM notices n JOIN inspection_itp_stages s ON s.id = n.stage_id),
    'test_results', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'id', r.id, 'test_pack_id', r.test_pack_id, 'test_pack_code', tp.code,
        'test_type', r.test_type, 'medium', r.medium, 'test_pressure', r.test_pressure,
        'pressure_unit', r.pressure_unit, 'hold_duration_minutes', r.hold_duration_minutes,
        'started_at', r.started_at, 'completed_at', r.completed_at, 'result', r.result,
        'failure_reason', r.failure_reason, 'previous_result_id', r.previous_result_id,
        'mode', r.mode, 'performer_org', r.performer_org, 'performer_name', r.performer_name,
        'report_ref', r.report_ref, 'recorded_at', r.recorded_at)
      ORDER BY r.recorded_at, r.id), '[]'::jsonb)
      FROM test_pack_results r JOIN piping_test_packs tp ON tp.id = r.test_pack_id
      WHERE r.project_id = pid AND r.test_pack_id IN (SELECT id FROM scope_packs)));
$$;
SQL);

        // 3. The completeness report is unchanged except for its version: it
        //    reads the same resolver, which is the whole point of having one.
        //    A wider scope inherits every gap of everything it gathers, and no
        //    "ready" rule exists outside the pack scope.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_itr_scope_completeness(pid uuid, kind text, ref uuid)
RETURNS jsonb LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
  WITH scope_lines AS (SELECT * FROM epcc_itr_scope_lines(pid, kind, ref)),
  scope_packs AS (
    SELECT tp.id, tp.code FROM piping_test_packs tp
     WHERE tp.project_id = pid AND kind = 'TEST_PACK' AND tp.id = ref
    UNION
    SELECT DISTINCT tp.id, tp.code FROM scope_lines sl
      JOIN piping_test_packs tp ON tp.id = sl.test_pack_id
     WHERE sl.test_pack_id IS NOT NULL AND kind <> 'TEST_PACK'),
  pack_joints AS (
    SELECT j.id, j.joint_seq, j.state, sl.line_number,
      EXISTS(SELECT 1 FROM joint_weld_records w WHERE w.project_id = pid AND w.joint_id = j.id) AS has_weld,
      EXISTS(SELECT 1 FROM joint_ndt_results n WHERE n.project_id = pid AND n.joint_id = j.id) AS has_ndt
    FROM joints j JOIN scope_lines sl ON sl.line_id = j.piping_line_id
    WHERE j.project_id = pid),
  open_rejections AS (
    SELECT DISTINCT ON (n.joint_id, n.method) n.joint_id, n.method, n.result
    FROM joint_ndt_results n JOIN pack_joints pj ON pj.id = n.joint_id
    WHERE n.project_id = pid
    ORDER BY n.joint_id, n.method, n.performed_at DESC, n.recorded_at DESC, n.id DESC),
  notices AS (
    SELECT n.id, n.number, s.point, s.sequence, epcc_pack_notice_state(pid, n.id) AS hold_state
      FROM piping_test_pack_notifications ln
      JOIN inspection_notifications n ON n.id = ln.notification_id AND n.project_id = pid
      JOIN inspection_itp_stages s ON s.id = n.stage_id AND s.project_id = pid
     WHERE ln.project_id = pid AND ln.test_pack_id IN (SELECT id FROM scope_packs)),
  m_lines AS (
    SELECT COALESCE(jsonb_agg(jsonb_build_object('line_number', line_number, 'release_state', release_state)
      ORDER BY line_number), '[]'::jsonb) AS arr
    FROM scope_lines WHERE release_state <> 'RELEASED'),
  m_nopack AS (
    SELECT COALESCE(jsonb_agg(jsonb_build_object('line_number', line_number) ORDER BY line_number),
      '[]'::jsonb) AS arr
    FROM scope_lines WHERE test_pack_id IS NULL),
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
  m_no_pass AS (
    SELECT COALESCE(jsonb_agg(jsonb_build_object('pack_code', sp.code)
      ORDER BY sp.code), '[]'::jsonb) AS arr
    FROM scope_packs sp
    WHERE NOT EXISTS (SELECT 1 FROM test_pack_results r
                       WHERE r.project_id = pid AND r.test_pack_id = sp.id AND r.result = 'PASS')),
  m_holds AS (
    SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'notification_id', id, 'number', number, 'point', point, 'hold_state', hold_state)
      ORDER BY sequence, number), '[]'::jsonb) AS arr
    FROM notices WHERE point = 'H' AND hold_state <> 'CLEARED'),
  counts AS (
    SELECT (SELECT count(*) FROM scope_lines) AS lines,
      (SELECT count(*) FROM pack_joints) AS joints,
      (SELECT count(*) FROM joint_weld_records w JOIN pack_joints pj ON pj.id = w.joint_id AND w.project_id = pid) AS welds,
      (SELECT count(*) FROM joint_ndt_results n JOIN pack_joints pj ON pj.id = n.joint_id AND n.project_id = pid) AS ndt,
      (SELECT count(*) FROM test_pack_results r WHERE r.project_id = pid AND r.test_pack_id IN (SELECT id FROM scope_packs)) AS test_results,
      (SELECT count(*) FROM notices) AS notifications,
      (SELECT count(*) FROM notices WHERE point = 'H') AS hold_points,
      (SELECT count(*) FROM scope_packs) AS packs)
  SELECT jsonb_build_object(
    'schema_version', 5,
    'is_complete', (jsonb_array_length(m_lines.arr) = 0 AND jsonb_array_length(m_nopack.arr) = 0
      AND jsonb_array_length(m_unfitted.arr) = 0 AND jsonb_array_length(m_no_weld.arr) = 0
      AND jsonb_array_length(m_no_ndt.arr) = 0 AND jsonb_array_length(m_open.arr) = 0
      AND jsonb_array_length(m_no_pass.arr) = 0 AND jsonb_array_length(m_holds.arr) = 0
      AND (SELECT count(*) FROM scope_packs) > 0),
    'counts', (SELECT jsonb_build_object(
        'lines', counts.lines, 'joints', counts.joints, 'welds', counts.welds, 'ndt', counts.ndt,
        'test_results', counts.test_results, 'notifications', counts.notifications,
        'hold_points', counts.hold_points, 'packs', counts.packs) FROM counts),
    'missing', jsonb_build_object(
      'lines_not_released', m_lines.arr,
      'lines_without_test_pack', m_nopack.arr,
      'joints_not_fitted', m_unfitted.arr,
      'joints_without_weld_record', m_no_weld.arr,
      'joints_without_ndt', m_no_ndt.arr,
      'joints_with_open_rejection', m_open.arr,
      'packs_without_passed_result', m_no_pass.arr,
      'hold_notifications_not_cleared', m_holds.arr))
  FROM m_lines, m_nopack, m_unfitted, m_no_weld, m_no_ndt, m_open, m_no_pass, m_holds;
$$;
SQL);

        // 4. The guard: an area and a facility must exist, in this project, and
        //    nothing else may ride along with them.
        GuardSql::apply(['epcc_guard_itr_snapshot']);
    }
};
