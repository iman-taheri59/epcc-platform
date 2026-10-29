CREATE OR REPLACE FUNCTION public.epcc_guard_legacy_reconciliation()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE l material_lots%ROWTYPE; head uuid; seq integer; c_heat text;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('legacy-reconcile:' || NEW.lot_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'LEGACY_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.decided_by IS DISTINCT FROM NULLIF(current_setting('epcc.receipt_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'LEGACY_RECONCILE_AUTHORITY' USING ERRCODE = '42501';
  END IF;

  NEW.policy_id := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.decided_by, 'reconcile_legacy');
  IF NEW.policy_id IS NULL THEN
    RAISE EXCEPTION 'LEGACY_RECONCILE_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;

  SELECT * INTO l FROM material_lots WHERE id = NEW.lot_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'LEGACY_LOT_NOT_FOUND' USING ERRCODE = '42501';
  END IF;

  -- Chained, like the custody amendments: a later decision supersedes an
  -- earlier one, and both stay on the record.
  SELECT id, sequence INTO head, seq
    FROM material_legacy_reconciliations
   WHERE lot_id = NEW.lot_id AND project_id = NEW.project_id
   ORDER BY sequence DESC LIMIT 1;
  IF NEW.previous_reconciliation_id IS DISTINCT FROM head THEN
    RAISE EXCEPTION 'LEGACY_RECONCILE_STALE' USING ERRCODE = '23514';
  END IF;
  NEW.sequence := COALESCE(seq, 0) + 1;

  -- The identity being reconciled is the lot's own.
  NEW.item_code := l.item_code;
  NEW.heat_ref := l.heat_number;
  NEW.reason := btrim(NEW.reason);

  IF NEW.decision = 'TAKE_OVER' THEN
    -- The controlled receipt must attest the same heat the legacy lot carried.
    -- Different heat, different steel: refuse rather than translate.
    SELECT e.eff->>'heat_ref' INTO c_heat
      FROM piping_custody_records r
      JOIN LATERAL (SELECT epcc_piping_effective_receipt(r.id) AS eff) e ON true
     WHERE r.id = NEW.custody_id AND r.project_id = NEW.project_id;
    IF NOT FOUND THEN
      RAISE EXCEPTION 'LEGACY_CUSTODY_NOT_FOUND' USING ERRCODE = '42501';
    END IF;
    IF NEW.heat_ref IS NOT NULL AND btrim(COALESCE(c_heat, '')) <> NEW.heat_ref THEN
      RAISE EXCEPTION 'LEGACY_HEAT_MISMATCH' USING ERRCODE = '23514';
    END IF;
  ELSE
    NEW.custody_id := NULL;
  END IF;

  NEW.decided_at := clock_timestamp();
  RETURN NEW;
END; $function$;
