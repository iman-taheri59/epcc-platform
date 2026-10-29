CREATE OR REPLACE FUNCTION public.epcc_guard_po_allocated_source_line()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
 IF NEW.requisition_item_id IS NOT NULL THEN
  PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
  IF EXISTS(SELECT 1 FROM po_mr_allocations WHERE requisition_item_id=NEW.requisition_item_id)
   AND EXISTS(SELECT 1 FROM purchase_orders p WHERE p.id=NEW.purchase_order_id AND p.state IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED'))
   AND NOT EXISTS(SELECT 1 FROM po_execution_decisions e WHERE e.purchase_order_id=NEW.purchase_order_id AND e.purpose='issue' AND e.source_hash IS NOT NULL) THEN
   RAISE EXCEPTION 'PO_EXEC_MR_CONTROLLED_SOURCE' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $function$;
