CREATE OR REPLACE FUNCTION public.epcc_guard_po_execution_header()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE basis jsonb;
BEGIN
    IF TG_OP='INSERT' THEN
        IF NEW.author_policy_id IS NOT NULL AND NEW.state<>'DRAFT' THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUE_DECISION_REQUIRED' USING ERRCODE='23514';
        END IF;
        RETURN NEW;
    END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
    basis:=epcc_po_issued_guard_basis(NEW.id);
    IF basis IS NOT NULL THEN
        IF epcc_po_execution_digest(epcc_po_execution_content(to_jsonb(NEW))) IS DISTINCT FROM (basis->>'content_hash') THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUED_CONTENT_FROZEN' USING ERRCODE='23514';
        END IF;
        IF NEW.state NOT IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') THEN
            RAISE EXCEPTION 'PO_EXEC_CANCELLATION_PENDING' USING ERRCODE='23514';
        END IF;
        IF OLD.state='DRAFT' AND NEW.state='ISSUED' THEN NEW.issued_by:=(basis->>'actor_id')::uuid; NEW.issued_at:=(basis->>'created_at')::timestamptz;
        ELSIF (NEW.issued_by,NEW.issued_at) IS DISTINCT FROM (OLD.issued_by,OLD.issued_at) OR NEW.state='DRAFT' THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUE_BASIS_IMMUTABLE' USING ERRCODE='23514';
        END IF;
    ELSIF NEW.author_policy_id IS NOT NULL AND NEW.state IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') AND (OLD.state NOT IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') OR OLD.author_policy_id IS NULL) THEN
        RAISE EXCEPTION 'PO_EXEC_ISSUE_DECISION_REQUIRED' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $function$;
