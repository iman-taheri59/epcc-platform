<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE inspection_notification_cancellations (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
 company_id uuid NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
 notification_id uuid NOT NULL UNIQUE REFERENCES inspection_notifications(id) ON DELETE RESTRICT,
 discipline text NOT NULL,
 issued_version integer NOT NULL CHECK (issued_version>=2),
 actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 actor_name text NOT NULL,
 cancel_setting_id uuid NOT NULL REFERENCES inspection_notification_settings(id) ON DELETE RESTRICT,
 reason text NOT NULL CHECK (length(btrim(reason)) BETWEEN 1 AND 4000 AND reason ~ '[^[:space:]]'),
 recorded_at timestamptz NOT NULL
);
CREATE INDEX notification_cancel_scope ON inspection_notification_cancellations(project_id,company_id,notification_id);
CREATE OR REPLACE FUNCTION epcc_guard_notification_cancellation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent record; sid uuid; captured_name text;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'NOTIFICATION_CANCELLATION_IMMUTABLE' USING ERRCODE='23514'; END IF;
 IF NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid
 THEN RAISE EXCEPTION 'NOTIFICATION_CANCELLATION_SCOPE' USING ERRCODE='42501'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('inspection-notification:'||NEW.project_id::text,0));
 PERFORM 1 FROM projects WHERE id=NEW.project_id FOR NO KEY UPDATE;
 IF NOT FOUND OR NOT epcc_project_is_writable(NEW.project_id)
 THEN RAISE EXCEPTION 'NOTIFICATION_CANCELLATION_ARCHIVED' USING ERRCODE='42501'; END IF;
 SELECT * INTO parent FROM inspection_notifications WHERE id=NEW.notification_id AND project_id=NEW.project_id AND company_id=NEW.company_id FOR UPDATE;
 IF NOT FOUND OR parent.state<>'ISSUED' OR parent.version<>NEW.issued_version
 THEN RAISE EXCEPTION 'NOTIFICATION_CANCELLATION_STALE' USING ERRCODE='23514'; END IF;
 -- Serialize current identity, membership and entitlement changes through commit.
 SELECT name INTO captured_name FROM users WHERE id=NEW.actor_id AND company_id=NEW.company_id AND is_active FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_CANCELLATION_ACTOR' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM project_memberships WHERE project_id=NEW.project_id AND user_id=NEW.actor_id FOR SHARE;
 PERFORM 1 FROM companies c JOIN plans pl ON pl.id=c.plan_id WHERE c.id=NEW.company_id FOR SHARE OF c,pl;
 PERFORM 1 FROM project_company WHERE project_id=NEW.project_id AND company_id=NEW.company_id FOR SHARE;
 sid:=epcc_notification_setting(NEW.project_id,NEW.actor_id,NEW.company_id,'issue',parent.discipline);
 IF sid IS NULL THEN RAISE EXCEPTION 'NOTIFICATION_CANCELLATION_FORBIDDEN' USING ERRCODE='42501'; END IF;
 IF EXISTS(SELECT 1 FROM inspection_notification_cancellations WHERE notification_id=parent.id)
 THEN RAISE EXCEPTION 'NOTIFICATION_CANCELLATION_DUPLICATE' USING ERRCODE='23514'; END IF;
 NEW.discipline:=parent.discipline; NEW.actor_name:=captured_name; NEW.cancel_setting_id:=sid;
 NEW.reason:=btrim(NEW.reason); NEW.recorded_at:=clock_timestamp();
 RETURN NEW;
END $$;
ALTER TABLE inspection_notification_cancellations ENABLE ROW LEVEL SECURITY;
ALTER TABLE inspection_notification_cancellations FORCE ROW LEVEL SECURITY;
-- A cancellation discloses no new source/body: exactly the parent's existing notice visibility.
CREATE POLICY notification_cancel_read ON inspection_notification_cancellations FOR SELECT USING (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM inspection_notifications n WHERE n.id=notification_id AND n.project_id=inspection_notification_cancellations.project_id AND n.company_id=inspection_notification_cancellations.company_id AND n.discipline=inspection_notification_cancellations.discipline));
CREATE POLICY notification_cancel_insert ON inspection_notification_cancellations FOR INSERT WITH CHECK (
 company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM inspection_notifications n WHERE n.id=notification_id AND n.project_id=inspection_notification_cancellations.project_id AND n.company_id=inspection_notification_cancellations.company_id));
-- Reach the immutable guard for own-company attempts; never authorize mutation.
CREATE POLICY notification_cancel_update ON inspection_notification_cancellations FOR UPDATE USING (
 company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid)
 WITH CHECK (company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid);
CREATE POLICY notification_cancel_delete ON inspection_notification_cancellations FOR DELETE USING (
 company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid);
CREATE TRIGGER notification_cancel_guard BEFORE INSERT OR UPDATE OR DELETE ON inspection_notification_cancellations FOR EACH ROW EXECUTE FUNCTION epcc_guard_notification_cancellation();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retained cancellation evidence must not be discarded.');
    }
};
