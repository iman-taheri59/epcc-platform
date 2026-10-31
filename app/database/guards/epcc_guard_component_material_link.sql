-- Piece-level custody — ADR-048 A.4b, the component material link.
--
-- The custody chain says "we received heat H". The dossier says "this joint is
-- in this test pack". Until now nothing connected the two: the platform could
-- name every heat it held and every joint it had welded, but it could not say
-- which joint was made from which heat — so an MTR could never be carried into
-- a dossier as evidence about a piece.
--
-- This guard closes that gap, and it does it the way the rest of the quality
-- register does: the heat is DERIVED, never declared. The caller names the
-- custody record the piece was cut from; the guard reads the heat (and the lot,
-- when the chain attests one) off the receipt's own effective state. A caller
-- who claims a different heat is corrected to the chain's claim, never trusted.
--
-- Two honest states, never a third:
--   * CUSTODY_HEAT / CUSTODY_LOT_HEAT — the piece is traced to a receipt.
--   * OBSERVED — there is no receipt behind it (the heat was read off the
--     piece, the paperwork is still travelling). That is a declared
--     observation, and it is only accepted with a stated reason.
-- The alternative — silently recording a blank heat, or letting a caller
-- invent one — is how a dossier comes to say "material certified" over
-- material nobody can identify.

CREATE OR REPLACE FUNCTION public.epcc_guard_component_material_link()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE eff jsonb; v_heat text; v_lot text; v_amendment uuid;
BEGIN
  PERFORM pg_advisory_xact_lock(hashtextextended('component-material:' || NEW.component_id::text, 0));

  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'COMPONENT_MATERIAL_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.linked_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'COMPONENT_MATERIAL_AUTHORITY' USING ERRCODE = '42501';
  END IF;

  -- Whose claim it is. "This piece is heat H" is a quality claim, so it is
  -- default-deny behind the results register, exactly like a binding.
  NEW.linked_policy_id := epcc_result_nomination(NEW.project_id, NEW.company_id, NEW.linked_by, 'link_component_material');
  IF NEW.linked_policy_id IS NULL THEN
    RAISE EXCEPTION 'COMPONENT_MATERIAL_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;

  -- The piece must be one of THIS project's components. Without this, a link
  -- could carry a heat across a tenant boundary and the dossier would print it
  -- as if it were the project's own material.
  IF NOT EXISTS (SELECT 1 FROM components c WHERE c.id = NEW.component_id AND c.project_id = NEW.project_id) THEN
    RAISE EXCEPTION 'COMPONENT_MATERIAL_COMPONENT_NOT_FOUND' USING ERRCODE = '42501';
  END IF;

  NEW.heat_ref := NULLIF(btrim(COALESCE(NEW.heat_ref, '')), '');
  NEW.lot_ref := NULLIF(btrim(COALESCE(NEW.lot_ref, '')), '');
  NEW.unlinked_reason := NULLIF(btrim(COALESCE(NEW.unlinked_reason, '')), '');

  IF NEW.custody_id IS NOT NULL THEN
    IF NOT EXISTS (SELECT 1 FROM piping_custody_records r
                    WHERE r.id = NEW.custody_id AND r.project_id = NEW.project_id) THEN
      RAISE EXCEPTION 'COMPONENT_MATERIAL_CUSTODY_NOT_FOUND' USING ERRCODE = '42501';
    END IF;

    eff := epcc_piping_effective_receipt(NEW.custody_id);
    IF eff IS NULL THEN
      RAISE EXCEPTION 'COMPONENT_MATERIAL_CUSTODY_NOT_FOUND' USING ERRCODE = '42501';
    END IF;

    -- A receipt that attests no heat cannot be the origin of a heat-traced
    -- piece. Recording the link anyway would put a blank heat on the dossier,
    -- which is indistinguishable from "we never checked".
    v_heat := NULLIF(btrim(COALESCE(eff->>'heat_ref', '')), '');
    IF v_heat IS NULL THEN
      RAISE EXCEPTION 'COMPONENT_MATERIAL_CUSTODY_NO_HEAT' USING ERRCODE = '42501';
    END IF;

    v_lot := NULLIF(btrim(COALESCE(eff->>'lot_ref', '')), '');
    v_amendment := NULLIF(eff->>'amendment_id', '')::uuid;

    NEW.heat_ref := v_heat;
    NEW.lot_ref := v_lot;
    NEW.custody_amendment_id := v_amendment;
    NEW.link := CASE WHEN v_lot IS NULL THEN 'CUSTODY_HEAT' ELSE 'CUSTODY_LOT_HEAT' END;
    NEW.unlinked_reason := NULL;
  ELSE
    NEW.custody_amendment_id := NULL;
    NEW.link := 'OBSERVED';

    IF NEW.heat_ref IS NULL THEN
      RAISE EXCEPTION 'COMPONENT_MATERIAL_HEAT_REQUIRED' USING ERRCODE = '42501';
    END IF;
    IF NEW.unlinked_reason IS NULL THEN
      RAISE EXCEPTION 'COMPONENT_MATERIAL_REASON_REQUIRED' USING ERRCODE = '42501';
    END IF;
  END IF;

  -- Append-only ordering. The newest statement about a piece is the truth, and
  -- the earlier ones stay as the record of what was said before.
  SELECT COALESCE(MAX(l.sequence), 0) + 1 INTO NEW.sequence
    FROM component_material_links l
   WHERE l.component_id = NEW.component_id;

  NEW.created_at := COALESCE(NEW.created_at, now());

  RETURN NEW;
END; $function$;
