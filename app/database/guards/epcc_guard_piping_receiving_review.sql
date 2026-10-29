CREATE OR REPLACE FUNCTION public.epcc_guard_piping_receiving_review()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE receipt piping_custody_records%ROWTYPE; head uuid; serial integer; person text; eff jsonb;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PIPING_RECEIVING_QC_IMMUTABLE' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('piping-receipt-access:'||NEW.project_id::text,0));
 PERFORM 1 FROM projects WHERE id=NEW.project_id FOR NO KEY UPDATE;
 IF NOT FOUND OR NOT epcc_project_is_writable(NEW.project_id)
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.reviewed_by IS DISTINCT FROM NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid THEN
  RAISE EXCEPTION 'PIPING_RECEIVING_QC_SCOPE' USING ERRCODE='42501'; END IF;
 IF NEW.policy_id IS NULL OR NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.reviewed_by,NEW.company_id,'receiving_qc') THEN
  RAISE EXCEPTION 'PIPING_RECEIVING_QC_NOMINATION' USING ERRCODE='42501'; END IF;
 SELECT * INTO receipt FROM piping_custody_records WHERE id=NEW.custody_id AND project_id=NEW.project_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_RECEIVING_QC_RECEIPT' USING ERRCODE='42501'; END IF;
 eff:=epcc_piping_effective_receipt(NEW.custody_id);
 IF eff IS NULL THEN RAISE EXCEPTION 'PIPING_AMENDMENT_SOURCE' USING ERRCODE='42501'; END IF;
 IF NEW.amendment_id IS DISTINCT FROM (eff->>'amendment_id')::uuid THEN RAISE EXCEPTION 'PIPING_AMENDMENT_STALE' USING ERRCODE='23514'; END IF;
 -- Parent custody is immutable. FOR SHARE would require an UPDATE RLS policy; do not weaken it.
 -- Refresh personnel/entitlement/source visibility after the parent lookup in new SPI snapshots.
 IF NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.reviewed_by,NEW.company_id,'receiving_qc') THEN
  RAISE EXCEPTION 'PIPING_RECEIVING_QC_NOMINATION' USING ERRCODE='42501'; END IF;
 IF NOT EXISTS(SELECT 1 FROM piping_custody_records WHERE id=NEW.custody_id AND project_id=NEW.project_id) THEN
  RAISE EXCEPTION 'PIPING_RECEIVING_QC_RECEIPT' USING ERRCODE='42501'; END IF;
 IF receipt.created_by=NEW.reviewed_by THEN RAISE EXCEPTION 'PIPING_RECEIVING_QC_SELF_REVIEW' USING ERRCODE='23514'; END IF;
 SELECT id,sequence INTO head,serial FROM piping_receiving_reviews WHERE custody_id=NEW.custody_id AND project_id=NEW.project_id ORDER BY sequence DESC LIMIT 1;
 IF NEW.previous_review_id IS DISTINCT FROM head THEN RAISE EXCEPTION 'PIPING_RECEIVING_QC_STALE' USING ERRCODE='23514'; END IF;
 IF serial=2147483647 THEN RAISE EXCEPTION 'PIPING_RECEIVING_QC_STALE' USING ERRCODE='23514'; END IF;
 NEW.sequence:=COALESCE(serial,0)+1;
 NEW.custody_fingerprint:=epcc_piping_custody_fingerprint(NEW.custody_id);
 SELECT name INTO person FROM users WHERE id=NEW.reviewed_by AND company_id=NEW.company_id AND is_active;
 IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_RECEIVING_QC_NOMINATION' USING ERRCODE='42501'; END IF;
 NEW.actor_name:=person; NEW.created_at:=clock_timestamp();
 RETURN NEW;
END $function$;
