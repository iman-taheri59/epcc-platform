<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-048 A.5b slice 1 — the notification<->test-pack gate.
 *
 * The gap this closes is not a missing feature, it is a missing consequence.
 * Since A.4d an ITP stage can be signed off — accepted, rejected, or waived by
 * the employer who owns the hold — and since A.4h slice 2 it can be signed
 * from a screen. Nothing in the database cared. A test pack could be marked
 * TESTED and then ACCEPTED while the client's HOLD point on that very test sat
 * unsigned, or sat REJECTED: the sign-off register was a diary, not a control.
 * A record that gates nothing is decoration, and this one was decorating the
 * exact claim a client relies on.
 *
 * Two things land together, because one without the other is theatre:
 *
 *   1. A LINK. `piping_test_pack_notifications` says "this notice was raised
 *      about this pack". It is append-only and there is no unlink: a link is a
 *      fact about what was asked for, and a withdrawn request is already
 *      recorded as a cancellation, which the gate honours.
 *
 *   2. A GATE. Transitioning a pack into TESTED or ACCEPTED — the two states
 *      that claim physical work was done and accepted — now requires every
 *      linked, issued, uncancelled HOLD ('H') notification to be cleared.
 *      Cleared means every notified party signed (ACCEPTED,
 *      ACCEPTED_WITH_COMMENTS, or WAIVED), or the project employer waived the
 *      hold: only the employer may waive an H, because waiving the client's
 *      control is the client's to do — the rule A.4d already enforces at the
 *      sign-off. A REJECTED sign-off is never cleared by anything: a rejection
 *      stands until a correction is recorded, and corrections are chained.
 *
 * Deliberately NOT gated: witness, review and monitor points ('W','R','M').
 * A hold stops work; the others are notices that work is happening. Gating on
 * them would make a pack un-acceptable because somebody did not turn up to
 * something that never required them to.
 *
 * Whose authority: the link may be made by the company the pack belongs to —
 * it is their claim — or by the project employer, who owns the holds. Nobody
 * else names someone else's inspection against someone else's pack.
 *
 * The ITR dossier learns about all of this: it now carries the linked
 * notifications with their sign-offs, and its completeness report enumerates
 * an uncleared hold as a gap instead of printing "complete" over it. The
 * dossier's schema_version moves to 2 because its shape changed, which is what
 * a version is for.
 *
 * Declared boundaries: the gate is evaluated over the notifications this
 * session can SEE (RLS inside the trigger), so a company that was never
 * notified and does not own the pack is not bound by a hold it cannot read —
 * and by the link rule it is also not a company that can create one. Freezing
 * a dossier is still allowed with an uncleared hold: a snapshot records
 * reality, including its gaps; what is gated is the CLAIM, not the record of
 * it. Receipts (GRN) and certificates (MTR) are still not assembled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piping_test_pack_notifications', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('test_pack_id')->constrained('piping_test_packs')->restrictOnDelete();
            $t->foreignUuid('notification_id')->constrained('inspection_notifications')->restrictOnDelete();
            $t->foreignUuid('linked_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('linked_at');
            $t->uuid('request_id');
            // One notice is linked to one pack once; the caller's intent is
            // named by request_id, so a double submit is a refusal, not a row.
            $t->unique(['project_id', 'test_pack_id', 'notification_id'], 'pack_notice_unique');
            $t->unique(['project_id', 'request_id'], 'pack_notice_request_unique');
            $t->unique(['project_id', 'id'], 'pack_notice_tenant_key');
            $t->index(['project_id', 'test_pack_id'], 'pack_notice_pack_idx');
        });

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_reject_pack_notice_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  RAISE EXCEPTION 'test-pack notification links are append-only; there is no unlink, because a withdrawn request is already a cancellation'
    USING ERRCODE = '55000';
END; $$;

-- What a notice means for the work: NOT_VISIBLE / NOT_ISSUED / CANCELLED /
-- REJECTED / NOT_SIGNED / CLEARED. One function, used by the gate, by the
-- dossier and by the completeness report, so the three can never disagree
-- about whether a hold was cleared.
CREATE OR REPLACE FUNCTION epcc_pack_notice_state(pid uuid, nid uuid)
RETURNS text LANGUAGE plpgsql STABLE SET search_path = public, pg_catalog AS $$
DECLARE
  -- Deliberately NOT called `n`: plpgsql resolves `n.id` in the SELECT list to
  -- a variable of that name before it considers a table alias, and an
  -- unassigned record then fails with "record n is not assigned yet".
  notice_row record; cid uuid; outcome text;
  total integer := 0; cleared integer := 0; rejected boolean := false;
BEGIN
  SELECT nt.id, nt.state, nt.recipient_company_ids,
         (SELECT p.owner_company_id FROM projects p WHERE p.id = nt.project_id) AS owner_company_id
    INTO notice_row
    FROM inspection_notifications nt
   WHERE nt.id = nid AND nt.project_id = pid;

  IF NOT FOUND THEN
    RETURN 'NOT_VISIBLE';
  END IF;
  -- A draft is not yet a request for attendance, so it cannot hold work up.
  IF notice_row.state <> 'ISSUED' THEN
    RETURN 'NOT_ISSUED';
  END IF;
  IF EXISTS (SELECT 1 FROM inspection_notification_cancellations c
              WHERE c.notification_id = nid AND c.project_id = pid) THEN
    RETURN 'CANCELLED';
  END IF;

  FOR cid IN SELECT x::uuid FROM jsonb_array_elements_text(notice_row.recipient_company_ids) x
  LOOP
    total := total + 1;
    -- Latest per company: a correction is a chained sign-off, never an edit.
    SELECT s.outcome INTO outcome
      FROM inspection_stage_signoffs s
     WHERE s.project_id = pid AND s.notification_id = nid AND s.company_id = cid
     ORDER BY s.recorded_at DESC, s.id DESC
     LIMIT 1;

    IF outcome IS NULL THEN
      NULL;                                   -- asked, and silent.
    ELSIF outcome = 'REJECTED' THEN
      rejected := true;
    ELSE
      cleared := cleared + 1;
    END IF;
  END LOOP;

  -- A rejection outranks everything else: nobody's waiver accepts failed work.
  IF rejected THEN
    RETURN 'REJECTED';
  END IF;
  -- The employer waiving its own hold releases the stop. A.4d refuses this to
  -- everyone else, and for an H point that refusal is what makes it a release
  -- rather than a deletion of someone else's control.
  IF EXISTS (SELECT 1 FROM inspection_stage_signoffs s
              WHERE s.project_id = pid AND s.notification_id = nid
                AND s.company_id = notice_row.owner_company_id
                AND s.outcome = 'WAIVED_NOT_ATTENDED') THEN
    RETURN 'CLEARED';
  END IF;
  IF total > 0 AND cleared = total THEN
    RETURN 'CLEARED';
  END IF;

  RETURN 'NOT_SIGNED';
END; $$;

SQL);

        // The two guards this slice touches: the new link guard, and the pack
        // transition guard that now carries the hold gate. Their text is the
        // file (ADR-059), applied here so the triggers below bind exactly what
        // database/guards says even though it was edited in the same change.
        GuardSql::apply([
            'epcc_guard_pack_notification_link',
            'epcc_guard_test_pack_transition',
        ]);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER pack_notice_immutable BEFORE UPDATE OR DELETE ON piping_test_pack_notifications
  FOR EACH ROW EXECUTE FUNCTION epcc_reject_pack_notice_mutation();
CREATE TRIGGER pack_notice_guard BEFORE INSERT ON piping_test_pack_notifications
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_pack_notification_link();

ALTER TABLE piping_test_pack_notifications ENABLE ROW LEVEL SECURITY;
ALTER TABLE piping_test_pack_notifications FORCE ROW LEVEL SECURITY;
CREATE POLICY pack_notice_read ON piping_test_pack_notifications FOR SELECT USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = piping_test_pack_notifications.project_id
                 AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid));
CREATE POLICY pack_notice_insert ON piping_test_pack_notifications FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc
               WHERE pc.project_id = piping_test_pack_notifications.project_id
                 AND pc.company_id = piping_test_pack_notifications.company_id));
SQL);

        // House rule: freeze the invoker read contract so a later migration
        // cannot widen visibility without saying so.
        $policies = DB::scalar("SELECT array_agg(polname::text ORDER BY polname)::text FROM pg_policy WHERE polrelid = 'piping_test_pack_notifications'::regclass AND polcmd IN ('r', '*')");
        if ($policies !== '{pack_notice_read}') {
            throw new RuntimeException('Unexpected read policy on piping_test_pack_notifications; reconcile before installing.');
        }

        DB::unprepared($this->dossierSql());
        DB::unprepared($this->completenessSql());
    }

    /**
     * The dossier, now with the notices raised about the pack and the
     * sign-offs they carry. schema_version 2: the shape changed, and a
     * consumer that does not know the new key should say so rather than
     * quietly reading an older document.
     */
    private function dossierSql(): string
    {
        return <<<'SQL'
CREATE OR REPLACE FUNCTION epcc_itr_test_pack_dossier(pid uuid, pack uuid)
RETURNS jsonb LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
  SELECT jsonb_build_object(
    'schema_version', 2,
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
    'notifications', (SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'notification_id', n.id, 'number', n.number, 'state', n.state,
        'point', s.point, 'stage_sequence', s.sequence,
        'stage_description_en', s.description_en, 'stage_description_fa', s.description_fa,
        'work_reference', n.work_reference, 'location', n.location,
        'scheduled_at', n.scheduled_at, 'issued_at', n.issued_at,
        'hold_state', epcc_pack_notice_state(pid, n.id),
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
      FROM piping_test_pack_notifications ln
      JOIN inspection_notifications n ON n.id = ln.notification_id AND n.project_id = pid
      JOIN inspection_itp_stages s ON s.id = n.stage_id AND s.project_id = pid
      WHERE ln.project_id = pid AND ln.test_pack_id = pack),
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
SQL;
    }

    /**
     * The completeness report, now enumerating an uncleared hold as a gap. A
     * dossier that says "complete" over an unsigned client hold is the lie
     * this whole layer exists to prevent.
     */
    private function completenessSql(): string
    {
        return <<<'SQL'
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
  notices AS (
    SELECT n.id, n.number, s.point, s.sequence, epcc_pack_notice_state(pid, n.id) AS hold_state
      FROM piping_test_pack_notifications ln
      JOIN inspection_notifications n ON n.id = ln.notification_id AND n.project_id = pid
      JOIN inspection_itp_stages s ON s.id = n.stage_id AND s.project_id = pid
     WHERE ln.project_id = pid AND ln.test_pack_id = pack),
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
  m_holds AS (
    SELECT COALESCE(jsonb_agg(jsonb_build_object(
        'notification_id', id, 'number', number, 'point', point, 'hold_state', hold_state)
      ORDER BY sequence, number), '[]'::jsonb) AS arr
    -- Only a hold that is genuinely uncleared is missing evidence: a draft is
    -- not yet a request and a cancelled one was withdrawn, so neither is a gap
    -- in the dossier.
    FROM notices WHERE point = 'H' AND hold_state IN ('NOT_SIGNED', 'REJECTED')),
  passed AS (
    SELECT EXISTS(SELECT 1 FROM test_pack_results r
      WHERE r.project_id = pid AND r.test_pack_id = pack AND r.result = 'PASS') AS ok),
  counts AS (
    SELECT (SELECT count(*) FROM lines) AS lines, (SELECT count(*) FROM pack_joints) AS joints,
      (SELECT count(*) FROM joint_weld_records w JOIN pack_joints pj ON pj.id = w.joint_id AND w.project_id = pid) AS welds,
      (SELECT count(*) FROM joint_ndt_results n JOIN pack_joints pj ON pj.id = n.joint_id AND n.project_id = pid) AS ndt,
      (SELECT count(*) FROM test_pack_results r WHERE r.project_id = pid AND r.test_pack_id = pack) AS test_results,
      (SELECT count(*) FROM notices) AS notifications,
      (SELECT count(*) FROM notices WHERE point = 'H') AS hold_points)
  SELECT jsonb_build_object(
    'schema_version', 2,
    'is_complete', (jsonb_array_length(m_lines.arr) = 0 AND jsonb_array_length(m_unfitted.arr) = 0
      AND jsonb_array_length(m_no_weld.arr) = 0 AND jsonb_array_length(m_no_ndt.arr) = 0
      AND jsonb_array_length(m_open.arr) = 0 AND jsonb_array_length(m_holds.arr) = 0 AND passed.ok),
    'counts', (SELECT jsonb_build_object('lines', lines, 'joints', joints, 'welds', welds, 'ndt', ndt,
      'test_results', test_results, 'notifications', notifications, 'hold_points', hold_points) FROM counts),
    'missing', jsonb_build_object(
      'lines_not_released', m_lines.arr,
      'joints_not_fitted', m_unfitted.arr,
      'joints_without_weld_record', m_no_weld.arr,
      'joints_without_ndt', m_no_ndt.arr,
      'joints_with_open_rejection', m_open.arr,
      'hold_notifications_not_cleared', m_holds.arr,
      'pack_without_passed_result', NOT passed.ok))
  FROM m_lines, m_unfitted, m_no_weld, m_no_ndt, m_open, m_holds, passed;
$$;
SQL;
    }

    public function down(): void
    {
        throw new RuntimeException('The notification<->test-pack gate is a forward-only safety rule.');
    }
};
