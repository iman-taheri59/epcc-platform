CREATE OR REPLACE FUNCTION public.epcc_guard_piping_qa_amended_source()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE q piping_qa_decisions%ROWTYPE; eff jsonb;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PIPING_AMENDMENT_IMMUTABLE' USING ERRCODE='23514'; END IF;
 SELECT * INTO q FROM piping_qa_decisions WHERE id=NEW.decision_id AND project_id=NEW.project_id;
 IF NOT FOUND OR q.decision<>'ACCEPTED' OR q.amendment_id IS NULL OR q.decided_by IS DISTINCT FROM NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid
 OR q.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR q.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(q.project_id,q.decided_by,q.company_id,'acceptance_qa')
 OR NOT EXISTS(SELECT 1 FROM piping_qa_decisions WHERE id=q.id AND pg_xact_status(xmin::text::xid8)='in progress') THEN RAISE EXCEPTION 'PIPING_AMENDMENT_SOURCE' USING ERRCODE='42501'; END IF;
 eff:=epcc_piping_effective_receipt(q.custody_id);
 IF eff IS NULL OR (eff->>'amendment_id')::uuid IS DISTINCT FROM q.amendment_id THEN RAISE EXCEPTION 'PIPING_AMENDMENT_STALE' USING ERRCODE='23514'; END IF;
 NEW.source_line_id:=(eff->>'source_line_id')::uuid; NEW.snapshot:=epcc_piping_source_snapshot(q.project_id,NEW.source_line_id);
 IF NEW.snapshot IS NULL THEN RAISE EXCEPTION 'PIPING_AMENDMENT_SOURCE' USING ERRCODE='42501'; END IF;
 NEW.purchase_order_id:=(NEW.snapshot->>'purchase_order_id')::uuid; RETURN NEW;
END $function$;
