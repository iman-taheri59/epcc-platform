CREATE OR REPLACE FUNCTION public.epcc_guard_test_pack_transition()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE latest text; n integer;
BEGIN
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'PACK_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  -- An accepted pack is a handover artefact. Reopening it silently would let a
  -- completed dossier change under the client who already signed for it.
  IF OLD.state = 'ACCEPTED' THEN
    RAISE EXCEPTION 'PACK_ACCEPTED_TERMINAL' USING ERRCODE = '23514';
  END IF;
  -- Metadata-only edits (notes, titles, planned pressure) are not claims.
  IF NEW.state IS NOT DISTINCT FROM OLD.state THEN
    RETURN NEW;
  END IF;
  IF NOT ((OLD.state = 'DRAFT' AND NEW.state = 'READY')
       OR (OLD.state = 'READY' AND NEW.state = 'TESTED')
       OR (OLD.state = 'TESTED' AND NEW.state = 'ACCEPTED')) THEN
    RAISE EXCEPTION 'PACK_INVALID_TRANSITION' USING ERRCODE = '23514';
  END IF;
  IF NEW.state = 'TESTED' THEN
    SELECT count(*) INTO n FROM test_pack_results r
     WHERE r.project_id = NEW.project_id AND r.test_pack_id = NEW.id;
    IF n = 0 THEN
      RAISE EXCEPTION 'PACK_TESTED_WITHOUT_RESULT' USING ERRCODE = '23514';
    END IF;
  END IF;
  IF NEW.state = 'ACCEPTED' THEN
    -- Latest recorded result decides, so FAIL -> repair -> PASS lineage can
    -- culminate in acceptance, but a lone FAIL never can.
    SELECT r.result INTO latest FROM test_pack_results r
     WHERE r.project_id = NEW.project_id AND r.test_pack_id = NEW.id
     ORDER BY r.recorded_at DESC, r.id DESC LIMIT 1;
    IF latest IS NULL OR latest <> 'PASS' THEN
      RAISE EXCEPTION 'PACK_ACCEPTED_WITHOUT_PASS' USING ERRCODE = '23514';
    END IF;
  END IF;
  RETURN NEW;
END; $function$;
