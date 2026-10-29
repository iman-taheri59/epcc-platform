CREATE OR REPLACE FUNCTION public.epcc_guard_piping_amendment()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE c piping_custody_records%ROWTYPE; head uuid; serial integer;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PIPING_AMENDMENT_IMMUTABLE' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('piping-receipt-access:'||NEW.project_id::text,0));
 PERFORM 1 FROM projects WHERE id=NEW.project_id FOR NO KEY UPDATE;
 IF NOT FOUND OR NOT epcc_project_is_writable(NEW.project_id) OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid OR NEW.created_by IS DISTINCT FROM NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid
 OR NEW.policy_id IS NULL OR NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.created_by,NEW.company_id,'custody_mrr') THEN RAISE EXCEPTION 'PIPING_AMENDMENT_AUTHORITY' USING ERRCODE='42501'; END IF;
 SELECT * INTO c FROM piping_custody_records WHERE id=NEW.custody_id AND project_id=NEW.project_id;
 IF NOT FOUND OR c.created_by IS DISTINCT FROM NEW.created_by OR c.company_id IS DISTINCT FROM NEW.company_id THEN RAISE EXCEPTION 'PIPING_AMENDMENT_PREPARER' USING ERRCODE='42501'; END IF;
 IF epcc_piping_effective_receipt(c.id) IS NULL THEN RAISE EXCEPTION 'PIPING_AMENDMENT_SOURCE' USING ERRCODE='42501'; END IF;
 SELECT id,sequence INTO head,serial FROM piping_custody_amendments WHERE custody_id=c.id AND project_id=NEW.project_id ORDER BY sequence DESC LIMIT 1;
 IF NEW.previous_amendment_id IS DISTINCT FROM head OR serial=2147483647 THEN RAISE EXCEPTION 'PIPING_AMENDMENT_STALE' USING ERRCODE='23514'; END IF;
 IF NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.created_by,NEW.company_id,'custody_mrr') THEN RAISE EXCEPTION 'PIPING_AMENDMENT_AUTHORITY' USING ERRCODE='42501'; END IF;
 NEW.sequence:=COALESCE(serial,0)+1; NEW.custody_fingerprint:=epcc_piping_custody_fingerprint(c.id);
 SELECT name INTO NEW.actor_name FROM users WHERE id=NEW.created_by AND company_id=NEW.company_id AND is_active;
 NEW.created_at:=clock_timestamp(); RETURN NEW;
END $function$;
