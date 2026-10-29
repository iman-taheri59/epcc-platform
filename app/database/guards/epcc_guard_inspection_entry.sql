CREATE OR REPLACE FUNCTION public.epcc_guard_inspection_entry()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE disc text; person_name text;
BEGIN
    IF TG_OP IN ('UPDATE','DELETE') AND OLD.entry_policy_id IS NOT NULL THEN
        RAISE EXCEPTION 'INSPECTION_ENTRY_IMMUTABLE' USING ERRCODE='23514';
    END IF;
    IF TG_OP='DELETE' THEN RETURN OLD; END IF;
    IF TG_OP='UPDATE' AND NEW.entry_policy_id IS NOT NULL THEN
        RAISE EXCEPTION 'INSPECTION_NO_LEGACY_BACKFILL' USING ERRCODE='23514';
    END IF;
    IF NEW.entry_policy_id IS NOT NULL THEN
        PERFORM pg_advisory_xact_lock(hashtextextended('inspection-entry:' || NEW.project_id::text,0));
        IF NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
            OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid THEN
            RAISE EXCEPTION 'INSPECTION_ENTRY_SCOPE' USING ERRCODE='42501';
        END IF;
        SELECT p.discipline INTO disc FROM purchase_orders p WHERE p.id=NEW.purchase_order_id AND p.project_id=NEW.project_id;
        IF NOT FOUND THEN RAISE EXCEPTION 'INSPECTION_PO_NOT_VISIBLE' USING ERRCODE='42501'; END IF;
        IF NEW.entry_policy_id IS DISTINCT FROM epcc_inspection_author_policy(NEW.project_id,NEW.inspected_by,NEW.company_id,disc) THEN
            RAISE EXCEPTION 'INSPECTION_AUTHOR_NOT_NOMINATED' USING ERRCODE='42501';
        END IF;
        SELECT name INTO person_name FROM users WHERE id=NEW.inspected_by AND company_id=NEW.company_id AND is_active;
        IF NOT FOUND THEN RAISE EXCEPTION 'INSPECTION_ACTOR_INVALID' USING ERRCODE='42501'; END IF;
        IF NEW.result NOT IN ('PASS','FAIL','CONDITIONAL') OR NEW.stage NOT IN ('WORKS','PRE_SHIPMENT','ON_ARRIVAL') THEN
            RAISE EXCEPTION 'INSPECTION_ENTRY_VERDICT' USING ERRCODE='23514';
        END IF;
        IF NEW.result <> 'PASS' AND length(btrim(COALESCE(NEW.findings_en,'')))=0 AND length(btrim(COALESCE(NEW.findings_fa,'')))=0 THEN
            RAISE EXCEPTION 'INSPECTION_FINDINGS_REQUIRED' USING ERRCODE='23514';
        END IF;
        NEW.recorded_actor_name:=person_name;
        NEW.created_at:=CURRENT_TIMESTAMP;
        NEW.updated_at:=CURRENT_TIMESTAMP;
    END IF;
    RETURN NEW;
END $function$;
