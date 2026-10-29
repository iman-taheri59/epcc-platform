CREATE OR REPLACE FUNCTION public.epcc_guard_notification_acknowledgment()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE parent record; pr record; actor_name_now text; company_now record; cancelled uuid;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'NOTIFICATION_ACK_IMMUTABLE' USING ERRCODE='23514'; END IF;
 IF NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid
 THEN RAISE EXCEPTION 'NOTIFICATION_ACK_SCOPE' USING ERRCODE='42501'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('inspection-notification:'||NEW.project_id::text,0));
 SELECT * INTO pr FROM projects WHERE id=NEW.project_id FOR NO KEY UPDATE;
 IF NOT FOUND OR NOT epcc_project_is_writable(NEW.project_id)
 THEN RAISE EXCEPTION 'NOTIFICATION_ACK_ARCHIVED' USING ERRCODE='42501'; END IF;
 -- Parent body is already immutable and the common advisory lock serializes
 -- issue/cancel/ack. Do NOT require recipient UPDATE/row-lock rights on sender data.
 SELECT * INTO parent FROM inspection_notifications WHERE id=NEW.notification_id AND project_id=NEW.project_id;
 IF NOT FOUND OR parent.state<>'ISSUED' OR parent.version<>NEW.issued_version
 THEN RAISE EXCEPTION 'NOTIFICATION_ACK_STALE' USING ERRCODE='23514'; END IF;
 IF NOT jsonb_exists(parent.recipient_company_ids,NEW.company_id::text)
 THEN RAISE EXCEPTION 'NOTIFICATION_ACK_NOT_RECIPIENT' USING ERRCODE='42501'; END IF;
 SELECT name INTO actor_name_now FROM users WHERE id=NEW.actor_id AND company_id=NEW.company_id AND is_active FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_ACK_ACTOR' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM project_memberships WHERE project_id=NEW.project_id AND user_id=NEW.actor_id
 AND (discipline_scope IS NULL OR jsonb_exists(discipline_scope,parent.discipline)) FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_ACK_DISCIPLINE' USING ERRCODE='42501'; END IF;
 PERFORM 1 FROM project_company WHERE project_id=NEW.project_id AND company_id=NEW.company_id FOR SHARE;
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_ACK_PARTICIPATION' USING ERRCODE='42501'; END IF;
 SELECT c.code,c.name_en,c.name_fa INTO company_now FROM companies c JOIN plans pl ON pl.id=c.plan_id
 WHERE c.id=NEW.company_id
 AND (jsonb_exists(pl.modules::jsonb,'qaqc') OR jsonb_exists(pl.modules::jsonb,'*'))
 AND (jsonb_exists(pl.standards_packs::jsonb,pr.standards_pack) OR jsonb_exists(pl.standards_packs::jsonb,'*')) FOR SHARE OF c,pl;
 IF NOT FOUND THEN RAISE EXCEPTION 'NOTIFICATION_ACK_ENTITLEMENT' USING ERRCODE='42501'; END IF;
 SELECT id INTO cancelled FROM inspection_notification_cancellations WHERE notification_id=parent.id AND project_id=NEW.project_id;
 IF (NEW.event_kind='ISSUE' AND (cancelled IS NOT NULL OR NEW.cancellation_id IS NOT NULL))
 OR (NEW.event_kind='CANCELLATION' AND (cancelled IS NULL OR NEW.cancellation_id IS DISTINCT FROM cancelled))
 OR NEW.event_kind NOT IN ('ISSUE','CANCELLATION')
 THEN RAISE EXCEPTION 'NOTIFICATION_ACK_TARGET' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM inspection_notification_acknowledgments WHERE notification_id=parent.id AND company_id=NEW.company_id AND event_kind=NEW.event_kind)
 THEN RAISE EXCEPTION 'NOTIFICATION_ACK_DUPLICATE' USING ERRCODE='23514'; END IF;
 NEW.discipline:=parent.discipline; NEW.actor_name:=actor_name_now;
 NEW.company_code:=company_now.code; NEW.company_name_en:=company_now.name_en; NEW.company_name_fa:=company_now.name_fa;
 NEW.authority_model:='AUTHORIZED_RECIPIENT_READER'; NEW.recorded_at:=clock_timestamp();
 RETURN NEW;
END $function$;
