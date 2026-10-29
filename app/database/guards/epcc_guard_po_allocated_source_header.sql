CREATE OR REPLACE FUNCTION public.epcc_guard_po_allocated_source_header()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
 IF NEW.state IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') THEN
  PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
  IF (EXISTS(SELECT 1 FROM po_mr_allocations a WHERE a.requisition_id=NEW.requisition_id)
   OR EXISTS(SELECT 1 FROM purchase_order_items l JOIN po_mr_allocations a ON a.requisition_item_id=l.requisition_item_id WHERE l.purchase_order_id=NEW.id))
   AND NOT EXISTS(SELECT 1 FROM po_execution_decisions e WHERE e.purchase_order_id=NEW.id AND e.purpose='issue' AND e.source_hash IS NOT NULL) THEN
   RAISE EXCEPTION 'PO_EXEC_MR_CONTROLLED_SOURCE' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $function$;
