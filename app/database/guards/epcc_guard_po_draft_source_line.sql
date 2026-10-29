CREATE OR REPLACE FUNCTION public.epcc_guard_po_draft_source_line()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE parent purchase_orders%ROWTYPE; predecessor purchase_order_items%ROWTYPE;
BEGIN
    IF TG_OP='UPDATE' THEN
        SELECT * INTO parent FROM purchase_orders WHERE id=OLD.purchase_order_id;
        IF parent.author_policy_id IS NOT NULL AND parent.requisition_id IS NOT NULL
            AND (OLD.requisition_item_id IS DISTINCT FROM NEW.requisition_item_id OR OLD.purchase_order_id IS DISTINCT FROM NEW.purchase_order_id
                OR OLD.project_id IS DISTINCT FROM NEW.project_id) THEN
            RAISE EXCEPTION 'PO_DRAFT_SOURCE_LINE_IMMUTABLE' USING ERRCODE='23514';
        END IF;
    END IF;
    SELECT * INTO parent FROM purchase_orders WHERE id=NEW.purchase_order_id;
    IF parent.author_policy_id IS NOT NULL AND parent.requisition_id IS NOT NULL THEN
        IF TG_OP='UPDATE' THEN
            IF OLD.requisition_item_id IS DISTINCT FROM NEW.requisition_item_id OR OLD.purchase_order_id IS DISTINCT FROM NEW.purchase_order_id
                OR OLD.project_id IS DISTINCT FROM NEW.project_id THEN
                RAISE EXCEPTION 'PO_DRAFT_SOURCE_LINE_IMMUTABLE' USING ERRCODE='23514';
            END IF;
        ELSE
            SELECT * INTO predecessor FROM purchase_order_items WHERE id=NEW.supersedes_id;
            IF NOT FOUND OR predecessor.purchase_order_id<>parent.id OR predecessor.requisition_item_id IS DISTINCT FROM NEW.requisition_item_id THEN
                RAISE EXCEPTION 'PO_DRAFT_SOURCE_AMENDMENT_REQUIRED' USING ERRCODE='23514';
            END IF;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM material_requisition_items s WHERE s.id=NEW.requisition_item_id
            AND s.requisition_id=parent.requisition_id AND s.project_id=NEW.project_id) THEN
            RAISE EXCEPTION 'PO_DRAFT_SOURCE_LINE_MISMATCH' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END $function$;
