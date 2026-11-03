-- Inspection Release Notes (IRN) — ADR-060, as amended by the ADR-041 merge.
--
-- ONE RECORD, NOT TWO. The platform used to hold two overlapping things:
-- `material_inspections` — what the inspector SAW (PASS / FAIL / CONDITIONAL plus
-- findings, at WORKS / PRE_SHIPMENT / ON_ARRIVAL) — and this register, what may
-- now HAPPEN to the material. They were numbered from the same idea (the
-- observation even carried the -IRN- series) and neither could answer the
-- question the other existed to answer, because an observation with no release
-- changed nothing and a release with no observation could not say why.
--
-- The owner's decision: the observation was always meant to BE the release
-- note, and was built wrong. So there is one record now. It carries the
-- findings AND the decision, in the -IRN- series, at any of the three stages —
-- including ON_ARRIVAL, which is how goods that have already arrived get
-- inspected.
--
-- WHAT SURVIVES THE MERGE, AND WHY IT MATTERS. Two things the observation did
-- that the release note did not, both kept:
--   * FINDINGS are mandatory for anything other than a clean release. A
--     refusal that says nothing cannot be acted on by the vendor — the same
--     rule the inspection entry applied to a failed verdict (ADR-041).
--   * STAGE includes WORKS and ON_ARRIVAL, not just release points, because
--     inspecting goods at the vendor's works and inspecting them on arrival are
--     both real inspections of real material.
--
-- THIRD PARTIES ARE NOT ALWAYS USERS. The inspector may be a platform user, or
-- a person at an inspection firm who will never hold an account. Both are
-- representable, and neither may be anonymous: the guard accepts a user, or a
-- named person AT a firm — never a blank attribution.

CREATE OR REPLACE FUNCTION public.epcc_guard_inspection_release_note()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE v_code text; v_seq integer; v_po uuid;
BEGIN
  -- Numbering is project-scoped, so the lock is project-scoped. Locking on the
  -- line instead would let two lines in one project draw the same number.
  PERFORM pg_advisory_xact_lock(hashtextextended('inspection-release:' || NEW.project_id::text, 0));

  IF NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'IRN_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
     OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
     OR NEW.issued_by IS DISTINCT FROM NULLIF(current_setting('epcc.result_actor_id', true), '')::uuid THEN
    RAISE EXCEPTION 'IRN_AUTHORITY' USING ERRCODE = '42501';
  END IF;

  -- Whose release it is. Releasing material is a quality claim, so it is
  -- default-deny behind its own register, exactly like a result.
  NEW.issuer_policy_id := epcc_irn_nomination(NEW.project_id, NEW.company_id, NEW.issued_by);
  IF NEW.issuer_policy_id IS NULL THEN
    RAISE EXCEPTION 'IRN_NOT_NOMINATED' USING ERRCODE = '42501';
  END IF;

  -- The line must be one of THIS project's purchase order lines. Without this
  -- a release could be written against another tenant's order and the gate
  -- would then read it as this project's own.
  SELECT poi.purchase_order_id INTO v_po
    FROM purchase_order_items poi
   WHERE poi.id = NEW.purchase_order_item_id AND poi.project_id = NEW.project_id;
  IF v_po IS NULL THEN
    RAISE EXCEPTION 'IRN_LINE_NOT_FOUND' USING ERRCODE = '42501';
  END IF;
  -- Derived, never declared: the order is read off the line, not trusted.
  NEW.purchase_order_id := v_po;

  NEW.inspector_name := NULLIF(btrim(COALESCE(NEW.inspector_name, '')), '');
  NEW.holds_note_en := NULLIF(btrim(COALESCE(NEW.holds_note_en, '')), '');
  NEW.holds_note_fa := NULLIF(btrim(COALESCE(NEW.holds_note_fa, '')), '');
  NEW.findings_en := NULLIF(btrim(COALESCE(NEW.findings_en, '')), '');
  NEW.findings_fa := NULLIF(btrim(COALESCE(NEW.findings_fa, '')), '');

  -- Third-party identity: a platform user, or a named person at a firm.
  IF NEW.inspector_user_id IS NULL
     AND (NEW.inspector_name IS NULL OR NEW.inspector_company_id IS NULL) THEN
    RAISE EXCEPTION 'IRN_INSPECTOR_UNATTRIBUTED' USING ERRCODE = '23514';
  END IF;

  -- A REFUSAL must say why. A vendor handed "not released" with no stated
  -- reason has nothing to act on, which is how the same material comes back on
  -- the next truck. A release WITH HOLDS has already spoken: the holds note is
  -- its statement, and demanding findings as well would be demanding twice.
  IF NEW.outcome = 'NOT_RELEASED'
     AND NEW.findings_en IS NULL AND NEW.findings_fa IS NULL THEN
    RAISE EXCEPTION 'IRN_FINDINGS_REQUIRED' USING ERRCODE = '23514';
  END IF;

  IF NEW.outcome = 'RELEASED_WITH_HOLDS'
     AND NEW.holds_note_en IS NULL AND NEW.holds_note_fa IS NULL THEN
    RAISE EXCEPTION 'IRN_HOLDS_UNSTATED' USING ERRCODE = '23514';
  END IF;

  SELECT COALESCE(MAX(sequence), 0) + 1 INTO v_seq
    FROM inspection_release_notes WHERE project_id = NEW.project_id;
  NEW.sequence := v_seq;
  SELECT code INTO v_code FROM projects WHERE id = NEW.project_id;
  NEW.number := v_code || '-IRN-' || lpad(v_seq::text, 4, '0');
  NEW.released_at := COALESCE(NEW.released_at, now());

  RETURN NEW;
END;
$function$;
