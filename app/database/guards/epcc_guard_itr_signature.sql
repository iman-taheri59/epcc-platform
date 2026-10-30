CREATE OR REPLACE FUNCTION public.epcc_guard_itr_signature()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE
  -- Deliberately not named after any table alias: plpgsql resolves `n.id` in a
  -- select list to a variable of that name before it considers an alias.
  signed_doc record;
  head uuid;
  policy uuid;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('itr-signature:' || NEW.snapshot_id::text, 0));

  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'ITR_SIGN_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;

  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.signed_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'ITR_SIGN_AUTHORITY' USING ERRCODE = '42501';
  END IF;

  -- What is being signed, and the two things that decide whether a signature
  -- over it means anything. Both are DERIVED from the frozen document: a
  -- signature names the document it was given, it does not describe it.
  SELECT s.digest,
         (s.digest = encode(sha256(convert_to(s.dossier::text, 'UTF8')), 'hex')) AS digest_ok,
         (COALESCE((s.completeness -> 'is_complete')::text, 'false') = 'true') AS is_complete
    INTO signed_doc
    FROM itr_snapshots s
   WHERE s.id = NEW.snapshot_id AND s.project_id = NEW.project_id;

  IF signed_doc.digest IS NULL THEN
    RAISE EXCEPTION 'ITR_SIGN_SNAPSHOT_NOT_FOUND' USING ERRCODE = '42501';
  END IF;

  -- The snapshot table is append-only, so a dossier that no longer matches its
  -- digest was changed underneath. A signature over that is a signature over
  -- something else: refuse rather than vouch for it.
  IF NOT signed_doc.digest_ok THEN
    RAISE EXCEPTION 'ITR_SIGN_DIGEST_INVALID' USING ERRCODE = '23514';
  END IF;

  NEW.digest := signed_doc.digest;

  -- Signing is default-deny, asked of the same register every other quality
  -- action obeys, and the nomination that authorised this row is stamped on
  -- it: "who was allowed to say this" stays auditable afterwards.
  policy := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.signed_by, 'sign_itr_snapshot');
  IF policy IS NULL THEN
    RAISE EXCEPTION 'ITR_SIGN_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;
  NEW.signer_policy_id := policy;

  -- An endorsement is a claim that the document is finished. The completeness
  -- report enumerates every gap, so endorsing a dossier that has one is a
  -- false statement unless the signer says why it is acceptable — which is
  -- exactly what ENDORSED_WITH_COMMENTS is for, and why its comments are
  -- mandatory. This refusal is what makes a signature worth anything at all.
  IF NEW.outcome = 'ENDORSED' AND NOT signed_doc.is_complete THEN
    RAISE EXCEPTION 'ITR_SIGN_ENDORSEMENT_OVER_GAPS' USING ERRCODE = '23514';
  END IF;

  -- One chain per company. A second signature from the same company names the
  -- head, so a stale submission cannot fork the record into two histories:
  -- both rows stand, and the latest one is the company's word.
  SELECT g.id INTO head
    FROM itr_snapshot_signatures g
   WHERE g.project_id = NEW.project_id
     AND g.snapshot_id = NEW.snapshot_id
     AND g.company_id = NEW.company_id
   ORDER BY g.recorded_at DESC, g.id DESC
   LIMIT 1;

  IF head IS NULL THEN
    IF NEW.previous_signature_id IS NOT NULL THEN
      RAISE EXCEPTION 'ITR_SIGN_PREVIOUS_UNKNOWN' USING ERRCODE = '23514';
    END IF;
  ELSIF NEW.previous_signature_id IS NULL OR NEW.previous_signature_id <> head THEN
    RAISE EXCEPTION 'ITR_SIGN_PREVIOUS_STALE' USING ERRCODE = '23514';
  END IF;

  NEW.recorded_at := clock_timestamp();
  RETURN NEW;
END; $function$;
