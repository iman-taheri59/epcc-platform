CREATE OR REPLACE FUNCTION public.epcc_guard_po_execution_lines()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE oid uuid; pid uuid;
BEGIN
    IF TG_OP<>'INSERT' THEN
        SELECT p.project_id INTO pid FROM purchase_orders p WHERE p.id=OLD.purchase_order_id;
        PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||pid::text,0));
        IF epcc_po_issued_guard_basis(OLD.purchase_order_id) IS NOT NULL THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUED_CONTENT_FROZEN' USING ERRCODE='23514';
        END IF;
    END IF;
    IF TG_OP<>'DELETE' THEN
        SELECT p.project_id INTO pid FROM purchase_orders p WHERE p.id=NEW.purchase_order_id;
        PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||pid::text,0));
        IF epcc_po_issued_guard_basis(NEW.purchase_order_id) IS NOT NULL THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUED_CONTENT_FROZEN' USING ERRCODE='23514';
        END IF;
        RETURN NEW;
    END IF;
    RETURN OLD;
END $function$;
