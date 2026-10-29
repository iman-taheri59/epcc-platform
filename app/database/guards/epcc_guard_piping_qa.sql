CREATE OR REPLACE FUNCTION public.epcc_guard_piping_qa()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE c piping_custody_records%ROWTYPE; q piping_receiving_reviews%ROWTYPE; head uuid; serial integer; k text; src record; eff jsonb;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PIPING_QA_IMMUTABLE' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('piping-receipt-access:'||NEW.project_id::text,0));
 PERFORM 1 FROM projects WHERE id=NEW.project_id FOR NO KEY UPDATE;
 IF NOT FOUND OR NOT epcc_project_is_writable(NEW.project_id)
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.decided_by IS DISTINCT FROM NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid
 OR NEW.policy_id IS NULL OR NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.decided_by,NEW.company_id,'acceptance_qa') THEN
 RAISE EXCEPTION 'PIPING_QA_AUTHORITY' USING ERRCODE='42501'; END IF;
 SELECT * INTO c FROM piping_custody_records WHERE id=NEW.custody_id AND project_id=NEW.project_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_QA_PARENT' USING ERRCODE='42501'; END IF;
 eff:=epcc_piping_effective_receipt(c.id);
 IF eff IS NULL THEN RAISE EXCEPTION 'PIPING_AMENDMENT_SOURCE' USING ERRCODE='42501'; END IF;
 IF NEW.amendment_id IS DISTINCT FROM (eff->>'amendment_id')::uuid THEN RAISE EXCEPTION 'PIPING_AMENDMENT_STALE' USING ERRCODE='23514'; END IF;
 c:=jsonb_populate_record(c,eff);
 SELECT * INTO q FROM piping_receiving_reviews WHERE custody_id=c.id AND project_id=NEW.project_id ORDER BY sequence DESC LIMIT 1;
 IF NOT FOUND OR NEW.review_id IS DISTINCT FROM q.id OR q.amendment_id IS DISTINCT FROM NEW.amendment_id THEN RAISE EXCEPTION 'PIPING_QA_QC_STALE' USING ERRCODE='23514'; END IF;
 IF c.created_by=NEW.decided_by OR q.reviewed_by=NEW.decided_by OR c.created_by=q.reviewed_by THEN
 RAISE EXCEPTION 'PIPING_QA_SEPARATION' USING ERRCODE='23514'; END IF;
 SELECT id,sequence INTO head,serial FROM piping_qa_decisions WHERE custody_id=c.id AND project_id=NEW.project_id ORDER BY sequence DESC LIMIT 1;
 IF NEW.previous_decision_id IS DISTINCT FROM head OR serial=2147483647 THEN RAISE EXCEPTION 'PIPING_QA_STALE' USING ERRCODE='23514'; END IF;
 IF jsonb_typeof(NEW.declarations) IS DISTINCT FROM 'object' OR (SELECT count(*) FROM jsonb_object_keys(NEW.declarations))<>6 THEN RAISE EXCEPTION 'PIPING_QA_DECLARATIONS' USING ERRCODE='23514'; END IF;
 FOREACH k IN ARRAY ARRAY['source_match','quantity_unit_match','heat_traceability','mandatory_documents_complete','nonconformities_closed','requirements_met'] LOOP
 IF jsonb_typeof(NEW.declarations->k) IS DISTINCT FROM 'boolean' THEN RAISE EXCEPTION 'PIPING_QA_DECLARATIONS' USING ERRCODE='23514'; END IF;
 IF NEW.decision='ACCEPTED' AND NEW.declarations->k<>'true'::jsonb THEN RAISE EXCEPTION 'PIPING_QA_ACCEPTANCE_BLOCKED' USING ERRCODE='23514'; END IF;
 END LOOP;
 NEW.source_basis:=NULL;
 IF NEW.decision='ACCEPTED' THEN
 IF NEW.open_conditions IS NOT NULL OR q.observed_result<>'NO_NONCONFORMITY_OBSERVED' OR c.source_line_id IS NULL OR c.heat_ref IS NULL OR btrim(c.heat_ref)='' THEN
 RAISE EXCEPTION 'PIPING_QA_ACCEPTANCE_BLOCKED' USING ERRCODE='23514'; END IF;
 SELECT po.state,l.quantity,l.unit,jsonb_build_object('purchase_order_id',po.id,'number',po.number,'state',po.state,'buyer_company_id',po.company_id,'issued_at',po.issued_at,
 'line_id',l.id,'line_no',l.line_no,'supersedes_id',l.supersedes_id,'item_code',l.item_code,'description_en',l.description_en,'description_fa',l.description_fa,'unit',l.unit,'ordered_quantity',l.quantity) AS basis INTO src
 FROM purchase_order_items l JOIN purchase_orders po ON po.id=l.purchase_order_id WHERE l.id=c.source_line_id AND po.id=c.purchase_order_id
 AND l.project_id=NEW.project_id AND po.project_id=NEW.project_id AND po.discipline='PIPING';
 IF NOT FOUND OR src.state NOT IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') OR src.unit<>c.unit OR c.quantity>src.quantity
 OR (src.basis-'state'-'issued_at') IS DISTINCT FROM (c.source_snapshot-'state'-'issued_at') THEN
 RAISE EXCEPTION 'PIPING_QA_SOURCE_BLOCKED' USING ERRCODE='23514'; END IF;
 IF NEW.amendment_id IS NULL THEN NEW.source_basis:=src.basis; END IF; -- Amended PO metadata is captured separately under its original source RLS.
 END IF;
 IF NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.decided_by,NEW.company_id,'acceptance_qa') THEN RAISE EXCEPTION 'PIPING_QA_AUTHORITY' USING ERRCODE='42501'; END IF;
 NEW.sequence:=COALESCE(serial,0)+1; NEW.custody_fingerprint:=epcc_piping_custody_fingerprint(c.id);
 SELECT name INTO NEW.actor_name FROM users WHERE id=NEW.decided_by AND company_id=NEW.company_id AND is_active;
 NEW.created_at:=clock_timestamp(); RETURN NEW;
END $function$;
