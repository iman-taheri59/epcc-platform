CREATE OR REPLACE FUNCTION public.epcc_guard_stage_signoff()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
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
END; $function$;
