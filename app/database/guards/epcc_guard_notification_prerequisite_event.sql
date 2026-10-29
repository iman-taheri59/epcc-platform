CREATE OR REPLACE FUNCTION public.epcc_guard_notification_prerequisite_event()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
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
END $function$;
