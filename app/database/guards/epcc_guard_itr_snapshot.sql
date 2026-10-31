CREATE OR REPLACE FUNCTION public.epcc_guard_itr_snapshot()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE p piping_test_packs%ROWTYPE;
BEGIN
  -- Two dossiers about the same thing frozen at once would disagree with each
  -- other; the lock is per scope, which is what a dossier is about.
  PERFORM pg_advisory_xact_lock(hashtextextended(
    'itr-snapshot:' || NEW.scope_kind || ':' || COALESCE(NEW.scope_ref::text, NEW.project_id::text), 0));

  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'ITR_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;

  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.compiled_by IS DISTINCT FROM NULLIF(current_setting('epcc.itr_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'ITR_AUTHORITY' USING ERRCODE = '42501';
  END IF;

  -- What the dossier is about, and whether it may yet be frozen. A subsystem
  -- has no "not ready" state — it is not a claim anybody makes — so the
  -- premature-evidence refusal belongs to the pack scope alone.
  IF NEW.scope_kind = 'TEST_PACK' THEN
    IF NEW.scope_ref IS NULL OR NEW.test_pack_id IS DISTINCT FROM NEW.scope_ref THEN
      RAISE EXCEPTION 'ITR_SCOPE_REF_MISMATCH' USING ERRCODE = '23514';
    END IF;
    SELECT * INTO p FROM piping_test_packs WHERE id = NEW.scope_ref AND project_id = NEW.project_id;
    IF NOT FOUND THEN
      RAISE EXCEPTION 'ITR_PACK_NOT_FOUND' USING ERRCODE = '42501';
    END IF;
    -- A handover snapshot of a pack nobody declared ready is premature fiction.
    IF p.state = 'DRAFT' THEN
      RAISE EXCEPTION 'ITR_PACK_NOT_READY' USING ERRCODE = '23514';
    END IF;
  ELSIF NEW.scope_kind = 'SUBSYSTEM' THEN
    IF NEW.scope_ref IS NULL OR NEW.test_pack_id IS NOT NULL THEN
      RAISE EXCEPTION 'ITR_SCOPE_REF_MISMATCH' USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM subsystems ss WHERE ss.id = NEW.scope_ref AND ss.project_id = NEW.project_id) THEN
      RAISE EXCEPTION 'ITR_SUBSYSTEM_NOT_FOUND' USING ERRCODE = '42501';
    END IF;
  ELSIF NEW.scope_kind = 'SYSTEM' THEN
    IF NEW.scope_ref IS NULL OR NEW.test_pack_id IS NOT NULL THEN
      RAISE EXCEPTION 'ITR_SCOPE_REF_MISMATCH' USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM systems sy WHERE sy.id = NEW.scope_ref AND sy.project_id = NEW.project_id) THEN
      RAISE EXCEPTION 'ITR_SYSTEM_NOT_FOUND' USING ERRCODE = '42501';
    END IF;
  ELSIF NEW.scope_kind = 'PROJECT' THEN
    -- The project needs no reference: the row already names it, and accepting
    -- one would allow two rows to claim the same dossier under different names.
    IF NEW.scope_ref IS NOT NULL OR NEW.test_pack_id IS NOT NULL THEN
      RAISE EXCEPTION 'ITR_SCOPE_REF_MISMATCH' USING ERRCODE = '23514';
    END IF;
  ELSE
    RAISE EXCEPTION 'ITR_SCOPE_UNKNOWN' USING ERRCODE = '23514';
  END IF;

  -- Trigger independently derives dossier/completeness/digest; client
  -- declarations cannot override them (scope-proposal envelope precedent).
  NEW.dossier := epcc_itr_scope_dossier(NEW.project_id, NEW.scope_kind, NEW.scope_ref);
  NEW.completeness := epcc_itr_scope_completeness(NEW.project_id, NEW.scope_kind, NEW.scope_ref);
  NEW.digest := encode(sha256(convert_to(NEW.dossier::text, 'UTF8')), 'hex');
  NEW.state := 'FROZEN';
  NEW.compiled_at := clock_timestamp();
  RETURN NEW;
END; $function$;
