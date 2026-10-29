CREATE OR REPLACE FUNCTION public.epcc_guard_notification_draft_history()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
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
END $function$;
