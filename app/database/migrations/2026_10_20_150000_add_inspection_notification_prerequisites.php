<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE inspection_notification_prerequisites (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
 company_id uuid NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
 notification_id uuid NOT NULL REFERENCES inspection_notifications(id) ON DELETE RESTRICT,
 issued_version integer NOT NULL CHECK (issued_version>=2),
 discipline text NOT NULL,
 company_code text NOT NULL, company_name_en text NOT NULL, company_name_fa text NOT NULL,
 created_by uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 created_name text NOT NULL, created_at timestamptz NOT NULL,
 version integer NOT NULL CHECK (version>0),
 state text NOT NULL CHECK (state IN ('OPEN','REPORTED','FULFILLED','WITHDRAWN')),
 item_reference text NOT NULL CHECK (length(item_reference) BETWEEN 1 AND 1000 AND item_reference ~ '[^[:space:]]'),
 requirement text NOT NULL CHECK (length(requirement) BETWEEN 1 AND 4000 AND requirement ~ '[^[:space:]]'),
 latest_action text NOT NULL CHECK (latest_action IN ('CREATE','EDIT','WITHDRAW','REPORT','CONFIRM','REJECT')),
 latest_note text CHECK (length(latest_note)<=4000),
 latest_actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 latest_actor_company_id uuid NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
 latest_actor_name text NOT NULL, latest_company_code text NOT NULL,
 latest_company_name_en text NOT NULL, latest_company_name_fa text NOT NULL,
 latest_at timestamptz NOT NULL
);
CREATE INDEX notification_prerequisite_scope ON inspection_notification_prerequisites(project_id,notification_id,created_at,id);
CREATE TABLE inspection_notification_prerequisite_events (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
 prerequisite_id uuid NOT NULL REFERENCES inspection_notification_prerequisites(id) ON DELETE RESTRICT,
 version integer NOT NULL CHECK (version>0),
 action text NOT NULL,
 actor_company_id uuid NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
 before_snapshot jsonb,
 after_snapshot jsonb NOT NULL CHECK (jsonb_typeof(after_snapshot)='object'),
 recorded_at timestamptz NOT NULL,
 UNIQUE(prerequisite_id,version),
 CHECK ((version=1 AND action='CREATE' AND before_snapshot IS NULL) OR (version>1 AND action IN ('EDIT','WITHDRAW','REPORT','CONFIRM','REJECT') AND jsonb_typeof(before_snapshot)='object'))
);
CREATE OR REPLACE FUNCTION epcc_notification_prerequisite_snapshot(p jsonb) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object('schema_version',1,
 'id',p->'id','project_id',p->'project_id','company_id',p->'company_id','notification_id',p->'notification_id',
 'issued_version',p->'issued_version','discipline',p->'discipline','company_code',p->'company_code','company_name_en',p->'company_name_en','company_name_fa',p->'company_name_fa',
 'created_by',p->'created_by','created_name',p->'created_name','created_at',to_char((p->>'created_at')::timestamptz AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"+00:00"'),
 'version',p->'version','state',p->'state','item_reference',p->'item_reference','requirement',p->'requirement',
 'latest_action',p->'latest_action','latest_note',p->'latest_note','latest_actor_id',p->'latest_actor_id','latest_actor_company_id',p->'latest_actor_company_id',
 'latest_actor_name',p->'latest_actor_name','latest_company_code',p->'latest_company_code','latest_company_name_en',p->'latest_company_name_en','latest_company_name_fa',p->'latest_company_name_fa',
 'latest_at',to_char((p->>'latest_at')::timestamptz AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"+00:00"'));
$$;
CREATE OR REPLACE FUNCTION epcc_guard_notification_prerequisite() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent record; pr record; actor_name_now text; company_now record; own_recipient boolean;
BEGIN
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_NO_DELETE' USING ERRCODE='23514'; END IF;
 IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid
 OR NEW.latest_actor_company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_SCOPE' USING ERRCODE='42501'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('inspection-notification:'||NEW.project_id::text,0));
 SELECT * INTO pr FROM projects WHERE id=NEW.project_id FOR NO KEY UPDATE;
 IF NOT FOUND OR NOT epcc_project_is_writable(NEW.project_id) THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_ARCHIVED' USING ERRCODE='42501'; END IF;
 SELECT * INTO parent FROM inspection_notifications WHERE id=NEW.notification_id AND project_id=NEW.project_id;
 IF NOT FOUND OR parent.state<>'ISSUED' OR parent.version<>NEW.issued_version
 OR EXISTS(SELECT 1 FROM inspection_notification_cancellations WHERE notification_id=parent.id)
 THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_INACTIVE' USING ERRCODE='23514'; END IF;
 SELECT name INTO actor_name_now FROM users WHERE id=NEW.latest_actor_id AND company_id=NEW.latest_actor_company_id AND is_active FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_ACTOR' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM project_memberships WHERE project_id=NEW.project_id AND user_id=NEW.latest_actor_id
 AND (discipline_scope IS NULL OR jsonb_exists(discipline_scope,parent.discipline)) FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_DISCIPLINE' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM project_company WHERE project_id=NEW.project_id AND company_id=NEW.latest_actor_company_id FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_PARTICIPATION' USING ERRCODE='42501'; END IF;
 SELECT c.code,c.name_en,c.name_fa INTO company_now FROM companies c JOIN plans pl ON pl.id=c.plan_id
 WHERE c.id=NEW.latest_actor_company_id AND (jsonb_exists(pl.modules::jsonb,'qaqc') OR jsonb_exists(pl.modules::jsonb,'*'))
 AND (jsonb_exists(pl.standards_packs::jsonb,pr.standards_pack) OR jsonb_exists(pl.standards_packs::jsonb,'*')) FOR SHARE OF c,pl;
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_ENTITLEMENT' USING ERRCODE='42501'; END IF;
 own_recipient:=NEW.company_id=NEW.latest_actor_company_id AND jsonb_exists(parent.recipient_company_ids,NEW.company_id::text);
 IF TG_OP='INSERT' THEN
  IF NOT own_recipient THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_NOT_RECIPIENT' USING ERRCODE='42501'; END IF;
  NEW.version:=1; NEW.state:='OPEN'; NEW.latest_action:='CREATE'; NEW.latest_note:=NULL;
  NEW.company_code:=company_now.code; NEW.company_name_en:=company_now.name_en; NEW.company_name_fa:=company_now.name_fa;
  NEW.created_by:=NEW.latest_actor_id; NEW.created_name:=actor_name_now; NEW.created_at:=clock_timestamp();
 ELSE
  IF (to_jsonb(NEW)-ARRAY['version','state','item_reference','requirement','latest_action','latest_note','latest_actor_id','latest_actor_company_id','latest_actor_name','latest_company_code','latest_company_name_en','latest_company_name_fa','latest_at'])
   IS DISTINCT FROM (to_jsonb(OLD)-ARRAY['version','state','item_reference','requirement','latest_action','latest_note','latest_actor_id','latest_actor_company_id','latest_actor_name','latest_company_code','latest_company_name_en','latest_company_name_fa','latest_at'])
  OR OLD.version=2147483647 OR NEW.version<>OLD.version+1
  THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_IMMUTABLE_OR_STALE' USING ERRCODE='23514'; END IF;
  IF NEW.latest_action='EDIT' AND own_recipient AND OLD.state='OPEN' THEN NEW.state:='OPEN';
  ELSIF NEW.latest_action='WITHDRAW' AND own_recipient AND OLD.state IN ('OPEN','REPORTED') THEN NEW.state:='WITHDRAWN';
  ELSIF NEW.latest_action='REPORT' AND OLD.state IN ('OPEN','REPORTED') AND parent.company_id=NEW.latest_actor_company_id
   AND epcc_notification_setting(NEW.project_id,NEW.latest_actor_id,NEW.latest_actor_company_id,'issue',parent.discipline) IS NOT NULL THEN NEW.state:='REPORTED';
  ELSIF NEW.latest_action='CONFIRM' AND own_recipient AND OLD.state='REPORTED' THEN NEW.state:='FULFILLED';
  ELSIF NEW.latest_action='REJECT' AND own_recipient AND OLD.state='REPORTED' THEN NEW.state:='OPEN';
  ELSE RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_TRANSITION' USING ERRCODE='42501'; END IF;
  IF NEW.latest_action<>'EDIT' AND (NEW.item_reference IS DISTINCT FROM OLD.item_reference OR NEW.requirement IS DISTINCT FROM OLD.requirement)
  THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_CONTENT' USING ERRCODE='23514'; END IF;
  IF NEW.latest_action IN ('EDIT','WITHDRAW','REPORT','REJECT') AND (NEW.latest_note IS NULL OR NEW.latest_note !~ '[^[:space:]]')
  THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_NOTE' USING ERRCODE='23514'; END IF;
 END IF;
 NEW.discipline:=parent.discipline; NEW.latest_actor_name:=actor_name_now;
 NEW.latest_company_code:=company_now.code; NEW.latest_company_name_en:=company_now.name_en; NEW.latest_company_name_fa:=company_now.name_fa;
 NEW.latest_at:=clock_timestamp();
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION epcc_guard_notification_prerequisite_event() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent record;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_HISTORY_IMMUTABLE' USING ERRCODE='23514'; END IF;
 IF pg_trigger_depth()<>2 THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_CAPTURE_ONLY' USING ERRCODE='42501'; END IF;
 SELECT * INTO parent FROM inspection_notification_prerequisites WHERE id=NEW.prerequisite_id AND project_id=NEW.project_id;
 IF NOT FOUND OR NEW.version<>parent.version OR NEW.action<>parent.latest_action OR NEW.actor_company_id<>parent.latest_actor_company_id
 OR NEW.after_snapshot IS DISTINCT FROM epcc_notification_prerequisite_snapshot(to_jsonb(parent))
 THEN RAISE EXCEPTION 'NOTIFICATION_PREREQUISITE_HISTORY_MISMATCH' USING ERRCODE='23514'; END IF;
 NEW.recorded_at:=parent.latest_at;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION epcc_capture_notification_prerequisite() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 INSERT INTO inspection_notification_prerequisite_events(project_id,prerequisite_id,version,action,actor_company_id,before_snapshot,after_snapshot,recorded_at)
 VALUES(NEW.project_id,NEW.id,NEW.version,NEW.latest_action,NEW.latest_actor_company_id,
 CASE WHEN TG_OP='UPDATE' THEN epcc_notification_prerequisite_snapshot(to_jsonb(OLD)) ELSE NULL END,
 epcc_notification_prerequisite_snapshot(to_jsonb(NEW)),NEW.latest_at);
 RETURN NEW;
END $$;
ALTER TABLE inspection_notification_prerequisites ENABLE ROW LEVEL SECURITY;
ALTER TABLE inspection_notification_prerequisites FORCE ROW LEVEL SECURITY;
CREATE POLICY notification_prerequisite_read ON inspection_notification_prerequisites FOR SELECT USING (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND EXISTS(SELECT 1 FROM inspection_notifications n WHERE n.id=notification_id AND n.project_id=inspection_notification_prerequisites.project_id AND n.discipline=inspection_notification_prerequisites.discipline));
CREATE POLICY notification_prerequisite_create ON inspection_notification_prerequisites FOR INSERT WITH CHECK (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM inspection_notifications n WHERE n.id=notification_id AND n.project_id=inspection_notification_prerequisites.project_id));
CREATE POLICY notification_prerequisite_update ON inspection_notification_prerequisites FOR UPDATE USING (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND (company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid OR EXISTS(SELECT 1 FROM inspection_notifications n WHERE n.id=notification_id AND n.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)))
 WITH CHECK (project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND latest_actor_company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid);
CREATE POLICY notification_prerequisite_delete ON inspection_notification_prerequisites FOR DELETE USING (project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid);
ALTER TABLE inspection_notification_prerequisite_events ENABLE ROW LEVEL SECURITY;
ALTER TABLE inspection_notification_prerequisite_events FORCE ROW LEVEL SECURITY;
CREATE POLICY notification_prerequisite_event_read ON inspection_notification_prerequisite_events FOR SELECT USING (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND EXISTS(SELECT 1 FROM inspection_notification_prerequisites p WHERE p.id=prerequisite_id AND p.project_id=inspection_notification_prerequisite_events.project_id));
CREATE POLICY notification_prerequisite_event_capture ON inspection_notification_prerequisite_events FOR INSERT WITH CHECK (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND actor_company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND EXISTS(SELECT 1 FROM inspection_notification_prerequisites p WHERE p.id=prerequisite_id AND p.project_id=inspection_notification_prerequisite_events.project_id));
CREATE POLICY notification_prerequisite_event_update ON inspection_notification_prerequisite_events FOR UPDATE USING (project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND actor_company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid);
CREATE POLICY notification_prerequisite_event_delete ON inspection_notification_prerequisite_events FOR DELETE USING (project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND actor_company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid);
CREATE TRIGGER notification_prerequisite_guard BEFORE INSERT OR UPDATE OR DELETE ON inspection_notification_prerequisites FOR EACH ROW EXECUTE FUNCTION epcc_guard_notification_prerequisite();
CREATE TRIGGER notification_prerequisite_capture AFTER INSERT OR UPDATE ON inspection_notification_prerequisites FOR EACH ROW EXECUTE FUNCTION epcc_capture_notification_prerequisite();
CREATE TRIGGER notification_prerequisite_event_guard BEFORE INSERT OR UPDATE OR DELETE ON inspection_notification_prerequisite_events FOR EACH ROW EXECUTE FUNCTION epcc_guard_notification_prerequisite_event();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retained prerequisite history must not be discarded.');
    }
};
