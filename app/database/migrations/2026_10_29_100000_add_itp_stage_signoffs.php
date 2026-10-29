<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-048 A.4d slice 1 — structured hold/witness sign-offs on ITP stages.
 *
 * Until now an inspection notification recorded that a stage was NOTIFIED, never
 * what happened at it. A hold point that nobody attended and a hold point that
 * the client's inspector attended and accepted looked identical in the data —
 * which is exactly the ambiguity a quality record exists to remove.
 *
 * `inspection_stage_signoffs` is append-only and honest by construction:
 *   - The stage and its point (H/W/R/M) are DERIVED from the notification by
 *     the trigger, so a sign-off can never be attached to a stage it is not
 *     about, and the point cannot be mis-stated to dodge the hold rules.
 *   - Signing is default-deny behind the same `result_recorder_policies`
 *     register A.4b introduced (action `sign_off_stage`), and the authorising
 *     policy is stamped on the row: "who was allowed to sign" stays auditable.
 *   - Reason pairing is enforced: REJECTED needs a cause, ACCEPTED_WITH_COMMENTS
 *     needs the comments, and WAIVED_NOT_ATTENDED needs a waiver reason.
 *   - A HOLD ('H') point can only be WAIVED by the project employer. A
 *     contractor waiving the client's own hold point is not a waiver, it is a
 *     deletion of a control, so the database refuses it.
 *   - Only ISSUED notifications can be signed off. A draft is not yet a request.
 *   - Corrections are a new sign-off chained to the REJECTED one
 *     (`previous_signoff_id`), never an edit; the rejection stays on the record.
 *
 * Declared boundaries: this records sign-offs; it does NOT gate anything yet.
 * There is still no notification<->test-pack link in the schema, so a pack can
 * be accepted without a hold sign-off — that gate lands with A.5b, and is
 * listed there rather than silently assumed here. HTTP/UI is also still open.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Teach the nomination register the new action. The old CHECK is
        //    anonymous (auto-named), so find it by definition rather than guess.
        DB::unprepared(<<<'SQL'
DO $$
DECLARE c name;
BEGIN
  -- Postgres normalises "action IN (...)" to "action = ANY (ARRAY[...])",
  -- so match on the column name rather than the operator spelling.
  SELECT conname INTO c FROM pg_constraint
   WHERE conrelid = 'result_recorder_policies'::regclass AND contype = 'c'
     AND pg_get_constraintdef(oid) LIKE '%action%';
  IF c IS NULL THEN
    RAISE EXCEPTION 'result_recorder_policies action CHECK not found; refusing to widen it blindly';
  END IF;
  EXECUTE format('ALTER TABLE result_recorder_policies DROP CONSTRAINT %I', c);
  ALTER TABLE result_recorder_policies ADD CONSTRAINT result_recorder_policies_action_check
    CHECK (action IN ('record_weld','record_ndt','record_pack_result','register_certificate','sign_off_stage'));
END $$;
SQL);

        // 2. The sign-off register itself.
        Schema::create('inspection_stage_signoffs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('notification_id')->constrained('inspection_notifications')->restrictOnDelete();
            $t->foreignUuid('stage_id')->constrained('inspection_itp_stages')->restrictOnDelete();
            $t->char('point', 1);
            $t->string('outcome', 24);
            $t->text('reason')->nullable();
            $t->foreignUuid('signed_by')->constrained('users')->restrictOnDelete();
            $t->timestampTz('signed_at');
            $t->uuid('signer_policy_id');
            $t->uuid('previous_signoff_id')->nullable();
            $t->uuid('request_id');
            // Microsecond precision on purpose: the append order is the record. A
            // second-resolution timestamp would make two sign-offs in the same second
            // interchangeable, and the order in which they happened is exactly what an
            // auditor walks through.
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->unique(['project_id', 'request_id'], 'stage_signoff_request_unique');
            $t->unique(['project_id', 'id'], 'stage_signoff_tenant_key');
            $t->index(['project_id', 'notification_id'], 'stage_signoff_notification_idx');
        });

        // 3. Self-reference in a second pass: a self-FK declared inside
        //    Schema::create fails (the PK is applied after in-create FK ALTERs).
        DB::unprepared('ALTER TABLE inspection_stage_signoffs
  ADD FOREIGN KEY(previous_signoff_id) REFERENCES inspection_stage_signoffs(id) ON DELETE RESTRICT;');

        DB::unprepared(<<<'SQL'
ALTER TABLE inspection_stage_signoffs ADD CHECK (
  point IN ('H','W','R','M')
  AND outcome IN ('ACCEPTED','ACCEPTED_WITH_COMMENTS','REJECTED','WAIVED_NOT_ATTENDED')
  -- Reason pairing: an outcome that changes the work must say why.
  AND (outcome IN ('REJECTED','WAIVED_NOT_ATTENDED','ACCEPTED_WITH_COMMENTS')) = (reason IS NOT NULL)
  AND (length(btrim(COALESCE(reason,''))) > 0) = (reason IS NOT NULL));

CREATE OR REPLACE FUNCTION epcc_reject_stage_signoff_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  RAISE EXCEPTION 'ITP stage sign-offs are append-only; a correction is a new chained sign-off, never an edit'
    USING ERRCODE = '55000';
END; $$;

CREATE OR REPLACE FUNCTION epcc_stage_signoff_insert_path() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  IF pg_trigger_depth() <> 1 THEN
    RAISE EXCEPTION 'SIGNOFF_MANUAL_DIRECT_ONLY' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END; $$;

-- Visibility fix, deliberate and narrow. Reading an ITP stage currently requires read
-- access to the ITP *document revision*, so a company that has been formally notified of a
-- stage still cannot see that stage -- which makes signing it impossible for exactly the
-- parties the notice was sent to. This policy is additive (Postgres ORs permissive
-- policies): a project member may read a stage that a notification they can read refers to.
-- It leaks nothing new -- the notice already names the stage -- and it grants no write path.
CREATE POLICY stage_read_for_notified_parties ON inspection_itp_stages FOR SELECT
USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND EXISTS (
    SELECT 1 FROM inspection_notifications n
     WHERE n.stage_id = inspection_itp_stages.id
       AND n.project_id = inspection_itp_stages.project_id
  )
);

CREATE OR REPLACE FUNCTION epcc_guard_stage_signoff() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE n inspection_notifications%ROWTYPE;
        prev inspection_stage_signoffs%ROWTYPE; owner_company uuid;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('stage-signoff:' || NEW.notification_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'SIGNOFF_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.signed_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'SIGNOFF_AUTHORITY' USING ERRCODE = '42501';
  END IF;

  SELECT * INTO n FROM inspection_notifications
   WHERE id = NEW.notification_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'SIGNOFF_NOTIFICATION_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  -- A draft notification is not yet a request for attendance: nothing to sign.
  IF n.state <> 'ISSUED' THEN
    RAISE EXCEPTION 'SIGNOFF_NOTIFICATION_NOT_ISSUED' USING ERRCODE = '23514';
  END IF;

  -- Derived, never declarable: what stage is signed, and what kind of point it is.
  SELECT s.point INTO NEW.point
    FROM inspection_itp_stages s
   WHERE s.id = n.stage_id AND s.project_id = NEW.project_id;
  IF NEW.point IS NULL THEN
    RAISE EXCEPTION 'SIGNOFF_STAGE_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  NEW.stage_id := n.stage_id;

  NEW.signer_policy_id := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.signed_by, 'sign_off_stage');
  IF NEW.signer_policy_id IS NULL THEN
    RAISE EXCEPTION 'SIGNOFF_AUTHORITY_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;

  -- A contractor cannot waive the client's hold point. That is not a waiver,
  -- it is the removal of a control the employer owns.
  IF NEW.outcome = 'WAIVED_NOT_ATTENDED' AND NEW.point = 'H' THEN
    SELECT p.owner_company_id INTO owner_company FROM projects p WHERE p.id = NEW.project_id;
    IF NEW.company_id IS DISTINCT FROM owner_company THEN
      RAISE EXCEPTION 'SIGNOFF_HOLD_WAIVER_EMPLOYER_ONLY' USING ERRCODE = '42501';
    END IF;
  END IF;

  IF NEW.previous_signoff_id IS NOT NULL THEN
    SELECT * INTO prev FROM inspection_stage_signoffs
     WHERE id = NEW.previous_signoff_id AND project_id = NEW.project_id;
    IF NOT FOUND OR prev.notification_id <> NEW.notification_id
       OR prev.company_id <> NEW.company_id OR prev.outcome <> 'REJECTED' THEN
      RAISE EXCEPTION 'SIGNOFF_PREVIOUS_NOT_REJECTED' USING ERRCODE = '23514';
    END IF;
  END IF;

  NEW.recorded_at := clock_timestamp();
  RETURN NEW;
END; $$;

ALTER TABLE inspection_stage_signoffs ENABLE ROW LEVEL SECURITY;
ALTER TABLE inspection_stage_signoffs FORCE ROW LEVEL SECURITY;
CREATE POLICY stage_signoff_read ON inspection_stage_signoffs FOR SELECT USING (
  (NULLIF(current_setting('epcc.project_id', true), '') IS NULL OR project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = inspection_stage_signoffs.project_id AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid));
CREATE POLICY stage_signoff_insert ON inspection_stage_signoffs FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
  AND signed_by = NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid
  AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id = inspection_stage_signoffs.project_id AND pc.company_id = inspection_stage_signoffs.company_id));

CREATE TRIGGER stage_signoffs_immutable BEFORE UPDATE OR DELETE ON inspection_stage_signoffs
  FOR EACH ROW EXECUTE FUNCTION epcc_reject_stage_signoff_mutation();
CREATE TRIGGER stage_signoffs_insert_path BEFORE INSERT ON inspection_stage_signoffs
  FOR EACH ROW EXECUTE FUNCTION epcc_stage_signoff_insert_path();
CREATE TRIGGER stage_signoffs_guard BEFORE INSERT ON inspection_stage_signoffs
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_stage_signoff();
SQL);

        // Freeze the invoker read contract, house style.
        $policies = DB::scalar("SELECT array_agg(polname::text ORDER BY polname)::text FROM pg_policy WHERE polrelid = 'inspection_stage_signoffs'::regclass AND polcmd IN ('r', '*')");
        if ($policies !== '{stage_signoff_read}') {
            throw new RuntimeException('Unexpected read policy on inspection_stage_signoffs; reconcile before installing.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('ITP stage sign-offs are forward-only quality records.');
    }
};
