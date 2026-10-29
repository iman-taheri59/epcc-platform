CREATE OR REPLACE FUNCTION public.epcc_guard_notification_prerequisite()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
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
END $function$;
