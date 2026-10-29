CREATE OR REPLACE FUNCTION public.epcc_guard_po_mr_allocation()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE e po_execution_decisions%ROWTYPE; po purchase_orders%ROWTYPE; l purchase_order_items%ROWTYPE; m material_requisition_items%ROWTYPE;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
 SELECT * INTO e FROM po_execution_decisions WHERE id=NEW.issue_decision_id AND purpose='issue';
 IF NOT FOUND THEN RAISE EXCEPTION 'PO_EXEC_MR_ISSUE_REQUIRED' USING ERRCODE='23514'; END IF;
 SELECT * INTO po FROM purchase_orders WHERE id=e.purchase_order_id AND state='DRAFT' FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'PO_EXEC_MR_ISSUE_REQUIRED' USING ERRCODE='23514'; END IF;
 SELECT * INTO l FROM purchase_order_items WHERE id=NEW.purchase_order_item_id AND purchase_order_id=po.id;
 IF NOT FOUND OR EXISTS(SELECT 1 FROM purchase_order_items WHERE supersedes_id=l.id) THEN RAISE EXCEPTION 'PO_EXEC_MR_ALLOCATION_LINE' USING ERRCODE='23514'; END IF;
 SELECT * INTO m FROM material_requisition_items WHERE id=l.requisition_item_id AND requisition_id=po.requisition_id FOR UPDATE;
 IF NOT FOUND OR (NEW.project_id,NEW.buyer_company_id,NEW.purchase_order_id,NEW.requisition_id,NEW.requisition_item_id,NEW.quantity,NEW.approved_quantity,NEW.unit,NEW.source_hash)
  IS DISTINCT FROM (po.project_id,po.company_id,po.id,po.requisition_id,m.id,l.quantity,m.quantity,m.unit,e.source_hash)
  OR e.project_id<>po.project_id OR e.buyer_company_id<>po.company_id OR e.source_hash IS NULL THEN
  RAISE EXCEPTION 'PO_EXEC_MR_ALLOCATION_LINE' USING ERRCODE='23514'; END IF;
 IF NEW.quantity+COALESCE((SELECT sum(quantity) FROM po_mr_allocations WHERE requisition_item_id=m.id),0)>m.quantity THEN
  RAISE EXCEPTION 'PO_EXEC_MR_QUANTITY_EXCEEDED' USING ERRCODE='23514'; END IF;
 NEW.created_at:=e.created_at;
 RETURN NEW;
END $function$;
