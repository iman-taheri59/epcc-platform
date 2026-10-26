<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE inspection_notification_draft_history (
 id uuid PRIMARY KEY DEFAULT gen_random_uuid(),
 project_id uuid NOT NULL REFERENCES projects(id) ON DELETE RESTRICT,
 company_id uuid NOT NULL REFERENCES companies(id) ON DELETE RESTRICT,
 notification_id uuid NOT NULL REFERENCES inspection_notifications(id) ON DELETE RESTRICT,
 discipline text NOT NULL,
 before_version integer NOT NULL CHECK (before_version>=1),
 after_version integer NOT NULL CHECK (after_version=before_version+1),
 actor_id uuid NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
 actor_name text NOT NULL,
 edit_setting_id uuid NOT NULL REFERENCES inspection_notification_settings(id) ON DELETE RESTRICT,
 recorded_at timestamptz NOT NULL,
 before_snapshot jsonb NOT NULL CHECK (jsonb_typeof(before_snapshot)='object'),
 after_snapshot jsonb NOT NULL CHECK (jsonb_typeof(after_snapshot)='object'),
 UNIQUE(notification_id,after_version),
 CHECK ((before_snapshot->>'version')::integer=before_version AND (after_snapshot->>'version')::integer=after_version
  AND before_snapshot->>'state'='DRAFT' AND after_snapshot->>'state'='DRAFT'
  AND before_snapshot->>'id'=notification_id::text AND after_snapshot->>'id'=notification_id::text)
);
CREATE INDEX notification_draft_history_scope ON inspection_notification_draft_history(project_id,company_id,notification_id,after_version DESC);
-- Explicit schema: never silently capture future source/commercial columns.
CREATE OR REPLACE FUNCTION epcc_notification_draft_snapshot(payload jsonb) RETURNS jsonb LANGUAGE sql STABLE AS $$
 SELECT jsonb_build_object(
'schema_version',1,
 'id',payload->'id',
 'project_id',payload->'project_id',
 'company_id',payload->'company_id',
 'stage_id',payload->'stage_id',
 'purchase_order_id',payload->'purchase_order_id',
 'created_by',payload->'created_by',
 'entry_setting_id',payload->'entry_setting_id',
 'discipline',payload->'discipline',
 'state',payload->'state',
 'version',payload->'version',
 'work_reference',payload->'work_reference',
 'location',payload->'location',
 'scheduled_input',payload->'scheduled_input',
 'scheduled_at',to_char((payload->>'scheduled_at')::timestamptz AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"+00:00"'),
 'recipient_company_ids',payload->'recipient_company_ids',
 'notes_en',payload->'notes_en',
 'notes_fa',payload->'notes_fa',
 'created_at',to_char((payload->>'created_at')::timestamptz AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"+00:00"'),
 'last_edited_by',payload->'last_edited_by',
 'last_edit_setting_id',payload->'last_edit_setting_id',
 'last_edited_at',to_char((payload->>'last_edited_at')::timestamptz AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"+00:00"'),
 'activity_start_input',payload->'activity_start_input',
 'activity_end_input',payload->'activity_end_input',
 'activity_start_at',to_char((payload->>'activity_start_at')::timestamptz AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"+00:00"'),
 'activity_end_at',to_char((payload->>'activity_end_at')::timestamptz AT TIME ZONE 'UTC','YYYY-MM-DD"T"HH24:MI:SS.US"+00:00"')
 );
$$;
CREATE OR REPLACE FUNCTION epcc_guard_notification_draft_history() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent record; captured_name text;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'NOTIFICATION_HISTORY_IMMUTABLE' USING ERRCODE='23514'; END IF;
 -- Only the parent AFTER UPDATE capture can insert; no public append/backfill API.
 IF pg_trigger_depth()<>2 THEN RAISE EXCEPTION 'NOTIFICATION_HISTORY_CAPTURE_ONLY' USING ERRCODE='42501'; END IF;
 SELECT * INTO parent FROM inspection_notifications WHERE id=NEW.notification_id AND project_id=NEW.project_id AND company_id=NEW.company_id;
 IF NOT FOUND OR parent.state<>'DRAFT' OR parent.version<>NEW.after_version OR parent.discipline<>NEW.discipline
 OR parent.last_edited_by IS DISTINCT FROM NEW.actor_id OR parent.last_edit_setting_id IS DISTINCT FROM NEW.edit_setting_id
 OR NEW.after_snapshot IS DISTINCT FROM epcc_notification_draft_snapshot(to_jsonb(parent))
 OR NEW.before_snapshot->>'project_id' IS DISTINCT FROM NEW.project_id::text OR NEW.before_snapshot->>'company_id' IS DISTINCT FROM NEW.company_id::text
 THEN RAISE EXCEPTION 'NOTIFICATION_HISTORY_INCONSISTENT' USING ERRCODE='23514'; END IF;
 SELECT name INTO captured_name FROM users WHERE id=NEW.actor_id AND company_id=NEW.company_id AND is_active;
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_HISTORY_ACTOR' USING ERRCODE='42501'; END IF;
 NEW.actor_name:=captured_name; NEW.recorded_at:=parent.last_edited_at;
 RETURN NEW;
END $$;
CREATE OR REPLACE FUNCTION epcc_capture_notification_draft_edit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 INSERT INTO inspection_notification_draft_history(project_id,company_id,notification_id,discipline,before_version,after_version,actor_id,actor_name,edit_setting_id,recorded_at,before_snapshot,after_snapshot)
 VALUES(NEW.project_id,NEW.company_id,NEW.id,NEW.discipline,OLD.version,NEW.version,NEW.last_edited_by,'',NEW.last_edit_setting_id,NEW.last_edited_at,
 epcc_notification_draft_snapshot(to_jsonb(OLD)),epcc_notification_draft_snapshot(to_jsonb(NEW)));
 RETURN NEW;
END $$;
ALTER TABLE inspection_notification_draft_history ENABLE ROW LEVEL SECURITY;
ALTER TABLE inspection_notification_draft_history FORCE ROW LEVEL SECURITY;
CREATE POLICY notification_draft_history_read ON inspection_notification_draft_history FOR SELECT USING (company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
 AND project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id=inspection_notification_draft_history.project_id AND pc.company_id=inspection_notification_draft_history.company_id)
 AND EXISTS(SELECT 1 FROM inspection_notifications n WHERE n.id=inspection_notification_draft_history.notification_id AND n.project_id=inspection_notification_draft_history.project_id AND n.company_id=inspection_notification_draft_history.company_id AND n.discipline=inspection_notification_draft_history.discipline));
CREATE POLICY notification_draft_history_capture ON inspection_notification_draft_history FOR INSERT WITH CHECK (company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
 AND project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id=inspection_notification_draft_history.project_id AND pc.company_id=inspection_notification_draft_history.company_id)
 AND EXISTS(SELECT 1 FROM inspection_notifications n WHERE n.id=inspection_notification_draft_history.notification_id AND n.project_id=inspection_notification_draft_history.project_id AND n.company_id=inspection_notification_draft_history.company_id AND n.discipline=inspection_notification_draft_history.discipline));
CREATE TRIGGER notification_draft_history_guard BEFORE INSERT OR UPDATE OR DELETE ON inspection_notification_draft_history FOR EACH ROW EXECUTE FUNCTION epcc_guard_notification_draft_history();
CREATE TRIGGER notification_capture_draft_edit AFTER UPDATE ON inspection_notifications FOR EACH ROW WHEN (OLD.state='DRAFT' AND NEW.state='DRAFT') EXECUTE FUNCTION epcc_capture_notification_draft_edit();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retained draft edit history must not be discarded.');
    }
};
