CREATE OR REPLACE FUNCTION public.epcc_guard_notification_cancellation()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
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
END $function$;
