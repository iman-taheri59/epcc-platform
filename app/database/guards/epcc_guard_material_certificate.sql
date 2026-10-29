CREATE OR REPLACE FUNCTION public.epcc_guard_material_certificate()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
BEGIN
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.registered_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  NEW.recorder_policy_id := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.registered_by, 'register_certificate');
  IF NEW.recorder_policy_id IS NULL THEN
    RAISE EXCEPTION 'RESULT_AUTHORITY_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;
  -- A cited document must exist and be visible to the registrar; a dangling
  -- evidence pointer is worse than no pointer.
  IF NEW.document_id IS NOT NULL AND NOT EXISTS (
      SELECT 1 FROM documents d WHERE d.id = NEW.document_id AND d.project_id = NEW.project_id) THEN
    RAISE EXCEPTION 'MTR_DOCUMENT_NOT_VISIBLE' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END; $function$;
