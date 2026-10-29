CREATE OR REPLACE FUNCTION public.epcc_guard_test_pack_result()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE p piping_test_packs%ROWTYPE; prev test_pack_results%ROWTYPE;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('pack-result:' || NEW.test_pack_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.recorded_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  NEW.recorder_policy_id := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.recorded_by, 'record_pack_result');
  IF NEW.recorder_policy_id IS NULL THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;
  SELECT * INTO p FROM piping_test_packs WHERE id = NEW.test_pack_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'RESULT_PACK_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  -- No test result for a pack nobody has declared ready.
  IF p.state = 'DRAFT' THEN
    RAISE EXCEPTION 'RESULT_PACK_NOT_READY' USING ERRCODE = '23514';
  END IF;
  IF NEW.previous_result_id IS NOT NULL THEN
    SELECT * INTO prev FROM test_pack_results WHERE id = NEW.previous_result_id AND project_id = NEW.project_id;
    IF NOT FOUND OR prev.test_pack_id <> NEW.test_pack_id OR prev.result <> 'FAIL' THEN
      RAISE EXCEPTION 'RESULT_PREVIOUS_NOT_FAILED' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END; $function$;
