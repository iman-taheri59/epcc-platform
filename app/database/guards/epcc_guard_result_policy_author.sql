CREATE OR REPLACE FUNCTION public.epcc_guard_result_policy_author()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE owner_company uuid;
BEGIN
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_POLICY_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.created_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'RESULT_POLICY_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  SELECT p.owner_company_id INTO owner_company FROM projects p WHERE p.id = NEW.project_id;
  IF NEW.company_id <> NEW.target_company_id AND NEW.company_id IS DISTINCT FROM owner_company THEN
    RAISE EXCEPTION 'RESULT_POLICY_AUTHOR_NOT_PERMITTED' USING ERRCODE = '42501';
  END IF;
  IF NOT EXISTS (SELECT 1 FROM project_memberships pm
                 WHERE pm.user_id = NEW.created_by AND pm.project_id = NEW.project_id) THEN
    RAISE EXCEPTION 'RESULT_POLICY_AUTHOR_NOT_MEMBER' USING ERRCODE = '42501';
  END IF;
  RETURN NEW;
END; $function$;
