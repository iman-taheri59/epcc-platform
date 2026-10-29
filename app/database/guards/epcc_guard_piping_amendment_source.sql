CREATE OR REPLACE FUNCTION public.epcc_guard_piping_amendment_source()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE a piping_custody_amendments%ROWTYPE; snap jsonb; original_unit text;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PIPING_AMENDMENT_IMMUTABLE' USING ERRCODE='23514'; END IF;
 SELECT * INTO a FROM piping_custody_amendments WHERE id=NEW.amendment_id AND project_id=NEW.project_id;
 IF NOT FOUND OR a.source_kind<>'LINKED' OR a.created_by IS DISTINCT FROM NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid
 OR a.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid OR NOT epcc_project_is_writable(a.project_id)
 OR a.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(a.project_id,a.created_by,a.company_id,'custody_mrr')
 OR NOT EXISTS(SELECT 1 FROM piping_custody_amendments WHERE id=a.id AND pg_xact_status(xmin::text::xid8)='in progress') THEN RAISE EXCEPTION 'PIPING_AMENDMENT_SOURCE' USING ERRCODE='42501'; END IF;
 snap:=epcc_piping_source_snapshot(NEW.project_id,NEW.source_line_id);
 SELECT unit INTO original_unit FROM piping_custody_records WHERE id=a.custody_id AND created_by=a.created_by;
 IF snap IS NULL OR original_unit IS NULL OR snap->>'unit' IS DISTINCT FROM original_unit THEN RAISE EXCEPTION 'PIPING_AMENDMENT_UNIT_SOURCE' USING ERRCODE='23514'; END IF;
 NEW.purchase_order_id:=(snap->>'purchase_order_id')::uuid; NEW.source_snapshot:=snap;
 NEW.source_fingerprint:=encode(sha256(convert_to(snap::text,'UTF8')),'hex'); RETURN NEW;
END $function$;
