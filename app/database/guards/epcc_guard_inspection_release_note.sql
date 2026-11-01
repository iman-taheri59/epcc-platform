-- Inspection Release Notes (IRN) — ADR-060.
--
-- THE GAP THIS CLOSES. The platform could record that an inspector LOOKED at
-- material (material_inspections: PASS / FAIL / CONDITIONAL) and it could
-- record that the material ARRIVED (the custody chain). It could not record
-- the act that connects the two: the inspector's release, which is what tells
-- a warehouse "this may ship" and a site "this may be received". ADR-041 made
-- inspection advisory on purpose, and that was the right call at the time —
-- but it left a FAIL verdict with nowhere to land. Nothing downstream of a
-- failed inspection asked whether anyone had released the material, so the
-- answer was always yes.
--
-- WHAT A RELEASE NOTE IS, AND IS NOT. An inspection OBSERVATION says what the
-- inspector saw. A RELEASE says what may now happen to the material. They are
-- different acts by different authorities, which is why they are different
-- tables with different nomination registers: the engineer who records an
-- observation is not automatically the party who may release goods for
-- shipment.
--
-- THE ACRONYM COLLISION, RESOLVED ONCE. `%s-IRN-%04d` already exists in this
-- platform as the number series for inspection OBSERVATIONS (ADR-041). The
-- owner's "IRN" means the third-party RELEASE certificate. One acronym for two
-- documents is exactly how a warehouse ends up releasing material against an
-- observation number, so the release note takes its own series, `-IRL-`, and
-- the observation series is left untouched.
--
-- THIRD PARTIES ARE NOT ALWAYS USERS. The inspector may be a platform user,
-- or a person at an inspection firm who will never hold an account. Both are
-- representable, and neither may be anonymous: the guard accepts a user, or a
-- named person AT a firm — never a blank attribution.
--
-- A RELEASE THAT SAYS NOTHING CANNOT BE ACTED ON. RELEASED_WITH_HOLDS must
-- state its holds in at least one language; NOT_RELEASED must name the
-- inspection it refuses. This is the same rule the inspection entry already
-- applies to a failed verdict (ADR-041), carried across to the release.

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

  -- Whose release it is. Releasing material for shipment is a quality claim,
  -- so it is default-deny behind its own register, exactly like a result.
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

  IF NEW.source_inspection_id IS NOT NULL
     AND NOT EXISTS (SELECT 1 FROM material_inspections mi
                      WHERE mi.id = NEW.source_inspection_id
                        AND mi.project_id = NEW.project_id
                        AND mi.purchase_order_id = v_po) THEN
    RAISE EXCEPTION 'IRN_INSPECTION_NOT_FOUND' USING ERRCODE = '42501';
  END IF;

  NEW.inspector_name := NULLIF(btrim(COALESCE(NEW.inspector_name, '')), '');
  NEW.holds_note_en := NULLIF(btrim(COALESCE(NEW.holds_note_en, '')), '');
  NEW.holds_note_fa := NULLIF(btrim(COALESCE(NEW.holds_note_fa, '')), '');

  -- Third-party identity: a platform user, or a named person at a firm.
  IF NEW.inspector_user_id IS NULL
     AND (NEW.inspector_name IS NULL OR NEW.inspector_company_id IS NULL) THEN
    RAISE EXCEPTION 'IRN_INSPECTOR_UNATTRIBUTED' USING ERRCODE = '23514';
  END IF;

  IF NEW.outcome = 'RELEASED_WITH_HOLDS'
     AND NEW.holds_note_en IS NULL AND NEW.holds_note_fa IS NULL THEN
    RAISE EXCEPTION 'IRN_HOLDS_UNSTATED' USING ERRCODE = '23514';
  END IF;

  -- A refusal is a response to something. Releasing nothing into a refusal
  -- would leave a block with no origin to clear.
  IF NEW.outcome = 'NOT_RELEASED' AND NEW.source_inspection_id IS NULL THEN
    RAISE EXCEPTION 'IRN_REFUSAL_WITHOUT_INSPECTION' USING ERRCODE = '23514';
  END IF;

  SELECT COALESCE(MAX(sequence), 0) + 1 INTO v_seq
    FROM inspection_release_notes WHERE project_id = NEW.project_id;
  NEW.sequence := v_seq;
  SELECT code INTO v_code FROM projects WHERE id = NEW.project_id;
  NEW.number := v_code || '-IRL-' || lpad(v_seq::text, 4, '0');
  NEW.released_at := COALESCE(NEW.released_at, now());

  RETURN NEW;
END;
$function$;
