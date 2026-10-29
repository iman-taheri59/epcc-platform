CREATE OR REPLACE FUNCTION public.epcc_guard_piping_qa_evidence()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE d piping_qa_decisions%ROWTYPE; v_file record;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PIPING_QA_EVIDENCE_IMMUTABLE' USING ERRCODE='23514'; END IF;
 SELECT * INTO d FROM piping_qa_decisions WHERE id=NEW.decision_id AND project_id=NEW.project_id;
 IF NOT FOUND OR d.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid OR d.decided_by IS DISTINCT FROM NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid
 OR d.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(d.project_id,d.decided_by,d.company_id,'acceptance_qa') OR NOT epcc_project_is_writable(d.project_id) THEN RAISE EXCEPTION 'PIPING_QA_AUTHORITY' USING ERRCODE='42501'; END IF;
 -- A citation may only be attached in the same transaction that created its immutable decision.
 IF NOT EXISTS(SELECT 1 FROM piping_qa_decisions WHERE id=d.id AND pg_xact_status(xmin::text::xid8)='in progress') THEN RAISE EXCEPTION 'PIPING_QA_EVIDENCE_LATE' USING ERRCODE='23514'; END IF;
 IF NOT epcc_piping_qa_document_reader(NEW.project_id,d.decided_by,d.company_id) OR NEW.kind NOT IN ('REQUIREMENT','EVIDENCE') THEN RAISE EXCEPTION 'PIPING_QA_EVIDENCE_INVALID' USING ERRCODE='23514'; END IF;
 SELECT f.id,f.sha256,f.size_bytes,f.original_name,f.document_id,f.revision_id,r.revision,r.ordinal,r.cde_state,r.suitability,doc.number INTO v_file
 FROM document_files f JOIN document_revisions r ON r.id=f.revision_id AND r.document_id=f.document_id JOIN documents doc ON doc.id=f.document_id
 WHERE f.id=NEW.file_id AND f.project_id=NEW.project_id AND r.project_id=NEW.project_id AND doc.project_id=NEW.project_id AND r.cde_state IN ('PUBLISHED','ARCHIVED');
 IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_QA_EVIDENCE_INVALID' USING ERRCODE='23514'; END IF;
 NEW.snapshot:=to_jsonb(v_file); RETURN NEW;
END $function$;
