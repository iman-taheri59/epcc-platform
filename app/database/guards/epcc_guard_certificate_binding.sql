CREATE OR REPLACE FUNCTION public.epcc_guard_certificate_binding()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE c material_certificates%ROWTYPE;
        m_custody uuid; m_amendment uuid; m_item text; m_heat boolean := false;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('mtr-binding:' || NEW.certificate_id::text, 0));
  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'MTR_BIND_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.bound_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'MTR_BIND_AUTHORITY' USING ERRCODE = '42501';
  END IF;

  NEW.bound_policy_id := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.bound_by, 'bind_certificate');
  IF NEW.bound_policy_id IS NULL THEN
    RAISE EXCEPTION 'MTR_BIND_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;

  -- The claim comes from the certificate; the caller states none of it.
  SELECT * INTO c FROM material_certificates
   WHERE id = NEW.certificate_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN
    RAISE EXCEPTION 'MTR_CERTIFICATE_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  NEW.heat_ref := c.heat_ref;
  NEW.lot_ref := c.lot_ref;
  NEW.item_code := NULL;
  NEW.unlinked_reason := NULLIF(btrim(COALESCE(NEW.unlinked_reason, '')), '');

  -- Does custody attest this (lot, heat)? Exact match, never fuzzy: HEAT-77
  -- never merges with HEAT-7.
  SELECT r.id, (e.eff->>'amendment_id')::uuid, poi.item_code
    INTO m_custody, m_amendment, m_item
    FROM piping_custody_records r
    JOIN LATERAL (SELECT epcc_piping_effective_receipt(r.id) AS eff) e ON true
    LEFT JOIN purchase_order_items poi
           ON poi.id = NULLIF(e.eff->>'source_line_id', '')::uuid
   WHERE r.project_id = NEW.project_id
     AND btrim(COALESCE(e.eff->>'heat_ref', '')) = NEW.heat_ref
     AND (NEW.lot_ref IS NULL OR btrim(COALESCE(e.eff->>'lot_ref', '')) = NEW.lot_ref)
   ORDER BY r.id
   LIMIT 1;

  IF m_custody IS NOT NULL THEN
    NEW.binding := CASE WHEN NEW.lot_ref IS NULL THEN 'CUSTODY_HEAT' ELSE 'CUSTODY_LOT_HEAT' END;
    NEW.custody_id := m_custody;
    NEW.custody_amendment_id := m_amendment;
    NEW.item_code := m_item;
    NEW.unlinked_reason := NULL;
    RETURN NEW;
  END IF;

  -- The certificate claims a lot that custody does not attest: recording it at
  -- heat level would make the data say "this lot is covered" when custody only
  -- ever knew the heat. Either amend the custody chain with the lot, or record
  -- the certificate as unresolved and say why.
  SELECT true INTO m_heat
    FROM piping_custody_records r
    JOIN LATERAL (SELECT epcc_piping_effective_receipt(r.id) AS eff) e ON true
   WHERE r.project_id = NEW.project_id
     AND btrim(COALESCE(e.eff->>'heat_ref', '')) = NEW.heat_ref
   LIMIT 1;

  -- Nothing bound. With a reason the certificate is still a fact worth keeping,
  -- as an unresolved one; without a reason the refusal says exactly what is
  -- missing, so nobody has to guess why the bind failed.
  IF NEW.unlinked_reason IS NULL THEN
    IF m_heat THEN
      RAISE EXCEPTION 'MTR_LOT_NOT_ATTESTED' USING ERRCODE = '42501';
    END IF;
    RAISE EXCEPTION 'MTR_HEAT_NOT_IN_CUSTODY' USING ERRCODE = '42501';
  END IF;
  NEW.binding := 'UNRESOLVED';
  NEW.custody_id := NULL;
  NEW.custody_amendment_id := NULL;

  RETURN NEW;
END; $function$;
