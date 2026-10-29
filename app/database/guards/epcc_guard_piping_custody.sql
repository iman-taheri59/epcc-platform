CREATE OR REPLACE FUNCTION public.epcc_guard_piping_custody()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE n record; src record; person text; last_seq bigint;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PIPING_CUSTODY_IMMUTABLE' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('piping-receipt-access:'||NEW.project_id::text,0));
 PERFORM 1 FROM projects WHERE id=NEW.project_id FOR NO KEY UPDATE;
 IF NOT FOUND OR NOT epcc_project_is_writable(NEW.project_id) OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid
 OR NEW.created_by IS DISTINCT FROM NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid THEN
  RAISE EXCEPTION 'PIPING_CUSTODY_SCOPE' USING ERRCODE='42501'; END IF;
 IF NEW.policy_id IS NULL OR NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.created_by,NEW.company_id,'custody_mrr') THEN
  RAISE EXCEPTION 'PIPING_CUSTODY_NOMINATION' USING ERRCODE='42501'; END IF;
 SELECT custodian_company_id INTO n FROM piping_receipt_nomination_index WHERE project_id=NEW.project_id AND company_id=NEW.company_id AND policy_id=NEW.policy_id LIMIT 1;
 NEW.custodian_company_id:=n.custodian_company_id;
 SELECT jsonb_build_object('code',code,'name_en',name_en,'name_fa',name_fa) INTO NEW.custodian_snapshot FROM companies WHERE id=n.custodian_company_id;
 IF NEW.source_line_id IS NOT NULL THEN
  SELECT po.id INTO src FROM purchase_orders po JOIN purchase_order_items l ON l.purchase_order_id=po.id
  WHERE l.id=NEW.source_line_id AND po.project_id=NEW.project_id AND l.project_id=NEW.project_id AND po.discipline='PIPING' FOR SHARE OF po;
  IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_CUSTODY_SOURCE' USING ERRCODE='42501'; END IF;
  SELECT po.id AS po_id,l.unit,jsonb_build_object('purchase_order_id',po.id,'number',po.number,'state',po.state,'buyer_company_id',po.company_id,'issued_at',po.issued_at,
   'line_id',l.id,'line_no',l.line_no,'supersedes_id',l.supersedes_id,'item_code',l.item_code,'description_en',l.description_en,'description_fa',l.description_fa,'unit',l.unit,'ordered_quantity',l.quantity) AS snapshot INTO src
  FROM purchase_order_items l JOIN purchase_orders po ON po.id=l.purchase_order_id WHERE l.id=NEW.source_line_id AND po.project_id=NEW.project_id FOR SHARE OF l;
  IF NOT FOUND OR NEW.unit IS DISTINCT FROM src.unit THEN RAISE EXCEPTION 'PIPING_CUSTODY_UNIT' USING ERRCODE='23514'; END IF;
  NEW.purchase_order_id:=src.po_id; NEW.source_snapshot:=src.snapshot;
  NEW.source_fingerprint:=encode(sha256(convert_to(src.snapshot::text,'UTF8')),'hex'); NEW.unlinked_reason:=NULL;
 ELSE
  NEW.purchase_order_id:=NULL; NEW.source_snapshot:=NULL; NEW.source_fingerprint:=NULL;
  IF NEW.unlinked_reason IS NULL OR NEW.unlinked_reason !~ '[^[:space:]]' THEN RAISE EXCEPTION 'PIPING_CUSTODY_UNLINKED_REASON' USING ERRCODE='23514'; END IF;
 END IF;
 -- Source row locks can wait while personnel/plan rights change independently of the project lock.
 IF NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.created_by,NEW.company_id,'custody_mrr') THEN
  RAISE EXCEPTION 'PIPING_CUSTODY_NOMINATION' USING ERRCODE='42501'; END IF;
 -- RLS intentionally hides other callers' receipt facts; a project-local sequence cannot use max(receipts).
 -- A separate advisory-locked counter avoids a privileged read of hidden receipt rows.
 INSERT INTO piping_custody_counters(project_id,last_sequence) VALUES(NEW.project_id,1)
 ON CONFLICT(project_id) DO UPDATE SET last_sequence=piping_custody_counters.last_sequence+1 RETURNING last_sequence INTO last_seq;
 NEW.sequence:=last_seq;
 SELECT name INTO person FROM users WHERE id=NEW.created_by AND company_id=NEW.company_id AND is_active;
 NEW.actor_name:=person; NEW.created_at:=clock_timestamp(); NEW.state:='CUSTODY_ONLY';
 RETURN NEW;
END $function$;
