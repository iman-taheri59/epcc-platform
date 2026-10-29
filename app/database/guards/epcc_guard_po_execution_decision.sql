CREATE OR REPLACE FUNCTION public.epcc_guard_po_execution_decision()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE po purchase_orders%ROWTYPE; approval po_execution_decisions%ROWTYPE; content jsonb; expected_policy uuid; n integer; total numeric;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
    PERFORM 1 FROM projects WHERE id=NEW.project_id FOR UPDATE;
    SELECT * INTO po FROM purchase_orders WHERE id=NEW.purchase_order_id AND project_id=NEW.project_id AND company_id=NEW.buyer_company_id FOR UPDATE;
    IF NOT FOUND OR po.state<>'DRAFT' OR po.author_policy_id IS NULL OR po.drafted_by IS NULL OR po.material_category IS NULL
        OR NOT epcc_project_is_writable(NEW.project_id) THEN
        RAISE EXCEPTION 'PO_EXEC_NOT_ELIGIBLE' USING ERRCODE='23514';
    END IF;
    expected_policy:=epcc_po_execution_live_policy(po.project_id,NEW.actor_id,NEW.actor_company_id,po.company_id,NEW.purpose,po.discipline);
    IF expected_policy IS NULL OR expected_policy IS DISTINCT FROM NEW.policy_id THEN RAISE EXCEPTION 'PO_EXEC_AUTHORITY' USING ERRCODE='42501'; END IF;
    IF NEW.actor_id=po.drafted_by THEN RAISE EXCEPTION 'PO_EXEC_SEPARATION' USING ERRCODE='42501'; END IF;
    IF NOT EXISTS(SELECT 1 FROM companies c JOIN project_company pc ON pc.company_id=c.id WHERE c.id=po.vendor_company_id AND c.tenant_type='VEN' AND pc.project_id=po.project_id) THEN
        RAISE EXCEPTION 'PO_EXEC_VENDOR' USING ERRCODE='23514';
    END IF;
    IF EXISTS(SELECT 1 FROM purchase_order_items l LEFT JOIN purchase_order_items prev ON prev.id=l.supersedes_id
        WHERE l.purchase_order_id=po.id AND (l.project_id<>po.project_id OR (l.supersedes_id IS NOT NULL AND (prev.id IS NULL OR prev.purchase_order_id<>po.id))))
        OR (SELECT count(*) FROM purchase_order_items WHERE purchase_order_id=po.id)>200 THEN
        RAISE EXCEPTION 'PO_EXEC_LINEAGE' USING ERRCODE='23514';
    END IF;
    IF EXISTS(WITH RECURSIVE walk AS (
        SELECT id,supersedes_id,ARRAY[id] AS path,false AS cycle FROM purchase_order_items WHERE purchase_order_id=po.id
        UNION ALL SELECT l.id,l.supersedes_id,w.path||l.id,l.id=ANY(w.path) FROM walk w JOIN purchase_order_items l ON l.id=w.supersedes_id
        WHERE NOT w.cycle AND l.purchase_order_id=po.id) SELECT 1 FROM walk WHERE cycle) THEN
        RAISE EXCEPTION 'PO_EXEC_LINEAGE' USING ERRCODE='23514';
    END IF;
    SELECT count(*),round(sum(l.quantity*l.unit_price),2) INTO n,total FROM purchase_order_items l WHERE l.purchase_order_id=po.id
        AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id);
    IF n=0 OR po.currency IS NULL OR po.currency !~ '^[A-Z]{3,8}$' OR po.total_value IS NULL OR total IS DISTINCT FROM po.total_value
        OR EXISTS(SELECT 1 FROM purchase_order_items l WHERE l.purchase_order_id=po.id
            AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id)
            AND (l.unit_price IS NULL OR l.unit_price<0 OR l.quantity<=0)) THEN
        RAISE EXCEPTION 'PO_EXEC_PRICING' USING ERRCODE='23514';
    END IF;
    IF po.requisition_id IS NOT NULL THEN
        PERFORM 1 FROM material_requisitions WHERE id=po.requisition_id AND project_id=po.project_id AND company_id=po.company_id FOR UPDATE;
        PERFORM 1 FROM material_requisition_items WHERE requisition_id=po.requisition_id ORDER BY id FOR SHARE;
        IF epcc_po_mr_refusal(po.id,NEW.purpose='issue') IS NOT NULL THEN
            RAISE EXCEPTION '%',epcc_po_mr_refusal(po.id,NEW.purpose='issue') USING ERRCODE='23514';
        END IF;
        NEW.source_hash:=epcc_po_mr_source_hash(po.requisition_id);
    ELSE
        NEW.source_hash:=NULL;
        IF EXISTS(SELECT 1 FROM purchase_order_items WHERE purchase_order_id=po.id AND requisition_item_id IS NOT NULL) THEN
            RAISE EXCEPTION 'PO_EXEC_MR_HEADER_REQUIRED' USING ERRCODE='23514';
        END IF;
    END IF;
    content:=epcc_po_execution_content(to_jsonb(po));
    IF NEW.content_hash IS DISTINCT FROM epcc_po_execution_digest(content) THEN RAISE EXCEPTION 'PO_EXEC_STALE_CONTENT' USING ERRCODE='23514'; END IF;
    IF NEW.purpose='issue' THEN
        SELECT * INTO approval FROM po_execution_decisions WHERE purchase_order_id=po.id AND purpose='finance' ORDER BY sequence DESC LIMIT 1;
        IF NOT FOUND OR approval.id IS DISTINCT FROM NEW.approval_id OR approval.content_hash IS DISTINCT FROM NEW.content_hash
            OR approval.source_hash IS DISTINCT FROM NEW.source_hash
            OR approval.policy_id IS DISTINCT FROM NEW.policy_id OR approval.policy_id IS DISTINCT FROM
            epcc_po_execution_live_policy(po.project_id,approval.actor_id,approval.actor_company_id,po.company_id,'finance',po.discipline) THEN
            RAISE EXCEPTION 'PO_EXEC_APPROVAL_STALE' USING ERRCODE='23514';
        END IF;
        IF approval.actor_id=NEW.actor_id OR approval.actor_id=po.drafted_by THEN RAISE EXCEPTION 'PO_EXEC_SEPARATION' USING ERRCODE='42501'; END IF;
    ELSIF NEW.purpose<>'finance' THEN RAISE EXCEPTION 'PO_EXEC_PURPOSE' USING ERRCODE='23514'; END IF;
    NEW.snapshot:=content;
    SELECT COALESCE(max(sequence),0)+1 INTO NEW.sequence FROM po_execution_decisions WHERE purchase_order_id=po.id;
    PERFORM set_config('epcc.company_id',NEW.actor_company_id::text,true);
    SELECT name INTO NEW.actor_name FROM users WHERE id=NEW.actor_id;
    PERFORM set_config('epcc.company_id',po.company_id::text,true);
    NEW.created_at:=CURRENT_TIMESTAMP;
    RETURN NEW;
END $function$;
