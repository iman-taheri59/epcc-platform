CREATE OR REPLACE FUNCTION public.epcc_guard_joint_result()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE j joints%ROWTYPE; prev joint_ndt_results%ROWTYPE;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('joint-result:' || NEW.joint_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.recorded_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  -- Membership in the project is not authority to create inspection evidence.
  NEW.recorder_policy_id := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.recorded_by,
    CASE WHEN TG_TABLE_NAME = 'joint_weld_records' THEN 'record_weld' ELSE 'record_ndt' END);
  IF NEW.recorder_policy_id IS NULL THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;
  SELECT * INTO j FROM joints WHERE id = NEW.joint_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'RESULT_JOINT_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  -- Honest order: fit-up, then weld, then examination. A result on a MODELLED
  -- joint is a record of something that has not physically happened.
  IF j.state <> 'FITTED' THEN
    RAISE EXCEPTION 'RESULT_JOINT_NOT_FITTED' USING ERRCODE = '23514';
  END IF;
  IF TG_TABLE_NAME = 'joint_ndt_results' THEN
    IF NOT EXISTS (SELECT 1 FROM joint_weld_records w WHERE w.joint_id = NEW.joint_id AND w.project_id = NEW.project_id) THEN
      RAISE EXCEPTION 'RESULT_NDT_NEEDS_WELD' USING ERRCODE = '23514';
    END IF;
    IF NEW.previous_result_id IS NOT NULL THEN
      SELECT * INTO prev FROM joint_ndt_results WHERE id = NEW.previous_result_id AND project_id = NEW.project_id;
      IF NOT FOUND OR prev.joint_id <> NEW.joint_id OR prev.method <> NEW.method OR prev.result <> 'REJECTED' THEN
        RAISE EXCEPTION 'RESULT_PREVIOUS_NOT_REJECTED' USING ERRCODE = '23514';
      END IF;
    END IF;
    IF NEW.report_document_id IS NOT NULL AND NOT EXISTS (
        SELECT 1 FROM documents d WHERE d.id = NEW.report_document_id AND d.project_id = NEW.project_id) THEN
      RAISE EXCEPTION 'RESULT_REPORT_DOCUMENT_NOT_VISIBLE' USING ERRCODE = '42501';
    END IF;
  END IF;
  RETURN NEW;
END; $function$;
