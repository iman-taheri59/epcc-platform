CREATE OR REPLACE FUNCTION public.epcc_guard_itr_snapshot()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE p piping_test_packs%ROWTYPE;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('itr-snapshot:' || NEW.test_pack_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'ITR_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.compiled_by IS DISTINCT FROM NULLIF(current_setting('epcc.itr_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'ITR_AUTHORITY' USING ERRCODE = '42501';
  END IF;
  SELECT * INTO p FROM piping_test_packs WHERE id = NEW.test_pack_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'ITR_PACK_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  -- A handover snapshot of a pack nobody declared ready is premature fiction.
  IF p.state = 'DRAFT' THEN
    RAISE EXCEPTION 'ITR_PACK_NOT_READY' USING ERRCODE = '23514';
  END IF;
  -- Trigger independently derives dossier/completeness/digest; client
  -- declarations cannot override them (scope-proposal envelope precedent).
  NEW.dossier := epcc_itr_test_pack_dossier(NEW.project_id, NEW.test_pack_id);
  NEW.completeness := epcc_itr_test_pack_completeness(NEW.project_id, NEW.test_pack_id);
  NEW.digest := encode(sha256(convert_to(NEW.dossier::text, 'UTF8')), 'hex');
  NEW.state := 'FROZEN';
  NEW.compiled_at := clock_timestamp();
  RETURN NEW;
END; $function$;
