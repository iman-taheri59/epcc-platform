CREATE OR REPLACE FUNCTION public.epcc_guard_po_entry_basis()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
    IF TG_OP='UPDATE' AND OLD.author_policy_id IS NOT NULL AND
        (NEW.author_policy_id IS DISTINCT FROM OLD.author_policy_id
         OR NEW.discipline IS DISTINCT FROM OLD.discipline
         OR NEW.drafted_by IS DISTINCT FROM OLD.drafted_by
         OR NEW.company_id IS DISTINCT FROM OLD.company_id
         OR NEW.project_id IS DISTINCT FROM OLD.project_id) THEN
        RAISE EXCEPTION 'PO_ENTRY_BASIS_IMMUTABLE' USING ERRCODE='23514';
    END IF;
    IF NEW.author_policy_id IS NOT NULL AND (TG_OP='INSERT' OR OLD.author_policy_id IS NULL) THEN
        PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:' || NEW.project_id::text,0));
        IF NEW.author_policy_id IS DISTINCT FROM epcc_po_author_policy(NEW.project_id,NEW.drafted_by,NEW.company_id,NEW.discipline) THEN
            RAISE EXCEPTION 'PO_AUTHOR_NOT_NOMINATED' USING ERRCODE='42501';
        END IF;
    END IF;
    RETURN NEW;
END $function$;
