CREATE OR REPLACE FUNCTION public.epcc_guard_po_pricing_revision()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE po purchase_orders%ROWTYPE; content jsonb; total numeric; expected uuid;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
 PERFORM 1 FROM projects WHERE id=NEW.project_id FOR UPDATE;
 SELECT * INTO po FROM purchase_orders WHERE id=NEW.purchase_order_id AND project_id=NEW.project_id AND company_id=NEW.buyer_company_id FOR UPDATE;
 IF NOT FOUND OR po.state<>'DRAFT' OR po.author_policy_id IS NULL OR po.drafted_by IS DISTINCT FROM NEW.actor_id OR NOT epcc_project_is_writable(po.project_id) THEN
  RAISE EXCEPTION 'PO_PRICING_NOT_ELIGIBLE' USING ERRCODE='23514';
 END IF;
 expected:=epcc_po_author_policy(po.project_id,NEW.actor_id,po.company_id,po.discipline);
 IF expected IS NULL OR expected IS DISTINCT FROM NEW.policy_id OR NOT EXISTS(
  SELECT 1 FROM users u JOIN companies c ON c.id=u.company_id JOIN plans pl ON pl.id=c.plan_id JOIN projects p ON p.id=po.project_id
  WHERE u.id=NEW.actor_id AND u.company_id=po.company_id AND u.is_active AND (jsonb_exists(pl.modules,'proc') OR jsonb_exists(pl.modules,'*')) AND (jsonb_exists(pl.standards_packs,p.standards_pack) OR jsonb_exists(pl.standards_packs,'*')))
 THEN RAISE EXCEPTION 'PO_PRICING_AUTHORITY' USING ERRCODE='42501'; END IF;
 IF NOT epcc_po_pricing_source_valid(po.id,po.project_id,po.company_id) THEN RAISE EXCEPTION 'PO_PRICING_SOURCE' USING ERRCODE='23514'; END IF;
 SELECT round(sum(l.quantity*l.unit_price),2) INTO total FROM purchase_order_items l WHERE l.purchase_order_id=po.id AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id);
 IF po.currency IS NULL OR po.currency !~ '^[A-Z]{3,8}$' OR po.total_value IS NULL OR total IS DISTINCT FROM po.total_value OR EXISTS(
  SELECT 1 FROM purchase_order_items l WHERE l.purchase_order_id=po.id AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id) AND (l.unit_price IS NULL OR l.unit_price<0))
 THEN RAISE EXCEPTION 'PO_PRICING_TOTAL' USING ERRCODE='23514'; END IF;
 content:=epcc_po_execution_content(to_jsonb(po));
 IF NEW.content_hash IS DISTINCT FROM epcc_po_execution_digest(content) OR NEW.before_hash IS DISTINCT FROM epcc_po_execution_digest(NEW.before_snapshot) THEN
  RAISE EXCEPTION 'PO_PRICING_HASH' USING ERRCODE='23514'; END IF;
 IF NEW.before_snapshot->'schema' IS DISTINCT FROM content->'schema'
 OR ((NEW.before_snapshot->'header')-ARRAY['currency','total_value']) IS DISTINCT FROM ((content->'header')-ARRAY['currency','total_value'])
 OR (SELECT jsonb_agg(value-'unit_price' ORDER BY ord) FROM jsonb_array_elements(NEW.before_snapshot->'lines') WITH ORDINALITY AS x(value,ord))
 IS DISTINCT FROM (SELECT jsonb_agg(value-'unit_price' ORDER BY ord) FROM jsonb_array_elements(content->'lines') WITH ORDINALITY AS x(value,ord)) THEN
  RAISE EXCEPTION 'PO_PRICING_NONPRICE_CHANGE' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(NEW.before_snapshot->'lines') b
   JOIN purchase_order_items l ON l.id=(b->>'id')::uuid AND l.purchase_order_id=po.id
   WHERE EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id)
   AND b->'unit_price' IS DISTINCT FROM to_jsonb(l)->'unit_price') THEN
  RAISE EXCEPTION 'PO_PRICING_HISTORY_CHANGE' USING ERRCODE='23514'; END IF;
 NEW.snapshot:=content;
 SELECT COALESCE(max(sequence),0)+1 INTO NEW.sequence FROM po_pricing_revisions WHERE purchase_order_id=po.id;
 SELECT name INTO NEW.actor_name FROM users WHERE id=NEW.actor_id;
 NEW.created_at:=CURRENT_TIMESTAMP;
 RETURN NEW;
END $function$;
