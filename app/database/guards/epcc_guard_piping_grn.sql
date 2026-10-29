CREATE OR REPLACE FUNCTION public.epcc_guard_piping_grn()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$

DECLARE c piping_custody_records%ROWTYPE; q piping_qa_decisions%ROWTYPE; qc piping_receiving_reviews%ROWTYPE; eff jsonb; snap jsonb; expected jsonb; req jsonb; ev jsonb; pcode text; cust uuid; total numeric;
BEGIN
 IF TG_OP<>'INSERT' THEN RAISE EXCEPTION 'PIPING_GRN_IMMUTABLE' USING ERRCODE='23514'; END IF;
 PERFORM pg_advisory_xact_lock(hashtextextended('piping-receipt-access:'||NEW.project_id::text,0));
 SELECT code INTO pcode FROM projects WHERE id=NEW.project_id FOR NO KEY UPDATE;
 IF NOT FOUND OR NOT epcc_project_is_writable(NEW.project_id) OR NOT epcc_piping_grn_inventory_ok()
 OR NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid
 OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id',true),'')::uuid
 OR NEW.posted_by IS DISTINCT FROM NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid
 OR NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.posted_by,NEW.company_id,'post_grn') OR NEW.policy_id IS NULL THEN
 RAISE EXCEPTION 'PIPING_GRN_AUTHORITY_OR_INVENTORY' USING ERRCODE='42501'; END IF;
 SELECT * INTO c FROM piping_custody_records WHERE id=NEW.custody_id AND project_id=NEW.project_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_GRN_PARENT' USING ERRCODE='42501'; END IF;
 SELECT custodian_company_id INTO cust FROM piping_receipt_nomination_index WHERE project_id=NEW.project_id AND policy_id=NEW.policy_id AND company_id=NEW.company_id AND action='post_grn' LIMIT 1;
 IF cust IS DISTINCT FROM c.custodian_company_id THEN RAISE EXCEPTION 'PIPING_GRN_CUSTODIAN_CHANGED' USING ERRCODE='23514'; END IF;
 eff:=epcc_piping_effective_receipt(c.id); IF eff IS NULL THEN RAISE EXCEPTION 'PIPING_GRN_SOURCE' USING ERRCODE='42501'; END IF;
 SELECT * INTO q FROM piping_qa_decisions WHERE custody_id=c.id AND project_id=NEW.project_id ORDER BY sequence DESC LIMIT 1;
 IF NOT FOUND OR q.id IS DISTINCT FROM NEW.qa_id OR q.payload_hash IS DISTINCT FROM NEW.qa_payload_hash OR q.decision<>'ACCEPTED'
 OR q.amendment_id IS DISTINCT FROM (eff->>'amendment_id')::uuid OR q.open_conditions IS NOT NULL
 OR q.custody_fingerprint IS DISTINCT FROM epcc_piping_custody_fingerprint(c.id) THEN RAISE EXCEPTION 'PIPING_GRN_QA_STALE_OR_NOT_ACCEPTED' USING ERRCODE='23514'; END IF;
 SELECT * INTO qc FROM piping_receiving_reviews WHERE custody_id=c.id AND project_id=NEW.project_id ORDER BY sequence DESC LIMIT 1;
 IF NOT FOUND OR qc.id IS DISTINCT FROM q.review_id OR qc.amendment_id IS DISTINCT FROM q.amendment_id OR qc.observed_result<>'NO_NONCONFORMITY_OBSERVED'
 OR c.created_by=qc.reviewed_by OR c.created_by=q.decided_by OR qc.reviewed_by=q.decided_by THEN RAISE EXCEPTION 'PIPING_GRN_QC_STALE' USING ERRCODE='23514'; END IF;
 IF q.declarations IS DISTINCT FROM '{"source_match":true,"quantity_unit_match":true,"heat_traceability":true,"mandatory_documents_complete":true,"nonconformities_closed":true,"requirements_met":true}'::jsonb THEN RAISE EXCEPTION 'PIPING_GRN_QA_DECLARATIONS' USING ERRCODE='23514'; END IF;
 snap:=epcc_piping_source_snapshot(NEW.project_id,(eff->>'source_line_id')::uuid);
 IF snap IS NULL OR snap->>'state' NOT IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') OR snap->>'unit'<>c.unit OR c.quantity>(snap->>'ordered_quantity')::numeric
 OR (snap-'state'-'issued_at') IS DISTINCT FROM ((eff->'source_snapshot')-'state'-'issued_at') OR NULLIF(btrim(eff->>'heat_ref'),'') IS NULL THEN RAISE EXCEPTION 'PIPING_GRN_SOURCE' USING ERRCODE='23514'; END IF;
 IF q.amendment_id IS NULL THEN expected:=q.source_basis; ELSE SELECT snapshot INTO expected FROM piping_qa_amendment_source_bases WHERE decision_id=q.id; END IF;
 IF (expected-'state'-'issued_at') IS DISTINCT FROM (snap-'state'-'issued_at') THEN RAISE EXCEPTION 'PIPING_GRN_SOURCE_CHANGED' USING ERRCODE='23514'; END IF;
 SELECT COALESCE(jsonb_agg(file_id::text ORDER BY file_id::text),'[]'::jsonb) INTO req FROM piping_qa_evidence WHERE decision_id=q.id AND kind='REQUIREMENT';
 SELECT COALESCE(jsonb_agg(file_id::text ORDER BY file_id::text),'[]'::jsonb) INTO ev FROM piping_qa_evidence WHERE decision_id=q.id AND kind='EVIDENCE';
 expected:=jsonb_build_object('expected_policy_id',q.policy_id,'previous_decision_id',q.previous_decision_id,'review_id',q.review_id,'request_id',q.request_id,'decision',q.decision,'reason',q.reason,'open_conditions',q.open_conditions,'declarations',q.declarations,'requirements_file_ids',req,'evidence_file_ids',ev);
 IF q.amendment_id IS NOT NULL THEN expected:=expected||jsonb_build_object('amendment_id',q.amendment_id); END IF;
 IF NEW.qa_payload IS NULL OR NEW.qa_payload::jsonb IS DISTINCT FROM expected OR encode(sha256(convert_to(NEW.qa_payload,'UTF8')),'hex')<>q.payload_hash
 OR jsonb_array_length(req)=0 OR jsonb_array_length(ev)=0 THEN RAISE EXCEPTION 'PIPING_GRN_EVIDENCE_INVENTORY' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM piping_qa_evidence e LEFT JOIN document_files f ON f.id=e.file_id LEFT JOIN document_revisions r ON r.id=f.revision_id AND r.document_id=f.document_id LEFT JOIN documents d ON d.id=f.document_id
 LEFT JOIN project_memberships m ON m.project_id=NEW.project_id AND m.user_id=NEW.posted_by
 WHERE e.decision_id=q.id AND (f.id IS NULL OR r.id IS NULL OR d.id IS NULL OR f.project_id<>NEW.project_id OR r.project_id<>NEW.project_id OR d.project_id<>NEW.project_id
 OR r.cde_state NOT IN ('PUBLISHED','ARCHIVED') OR f.sha256 IS DISTINCT FROM e.snapshot->>'sha256' OR f.size_bytes IS DISTINCT FROM (e.snapshot->>'size_bytes')::bigint
 OR f.id IS DISTINCT FROM (e.snapshot->>'id')::uuid OR f.original_name IS DISTINCT FROM e.snapshot->>'original_name'
 OR d.number IS DISTINCT FROM e.snapshot->>'number' OR r.revision IS DISTINCT FROM e.snapshot->>'revision' OR r.ordinal IS DISTINCT FROM (e.snapshot->>'ordinal')::integer OR r.suitability IS DISTINCT FROM e.snapshot->>'suitability'
 OR f.document_id IS DISTINCT FROM (e.snapshot->>'document_id')::uuid OR f.revision_id IS DISTINCT FROM (e.snapshot->>'revision_id')::uuid
 OR (m.discipline_scope IS NOT NULL AND NOT jsonb_exists(m.discipline_scope,d.discipline)))) THEN RAISE EXCEPTION 'PIPING_GRN_EVIDENCE_CHANGED' USING ERRCODE='23514'; END IF;
 -- No legacy source receipts are silently mapped, subtracted or imported.
 -- A.4f: the same refusal, but with a way through. A legacy lot that has been
 -- explicitly reconciled (taken over into the controlled chain, or retired with a
 -- reason) no longer blocks the GRN -- the reconciliation is the evidence, not a
 -- silent mapping of old rows onto new ones.
 IF EXISTS(SELECT 1 FROM material_lots l WHERE l.project_id=NEW.project_id AND l.purchase_order_id=(snap->>'purchase_order_id')::uuid
   AND NOT EXISTS(SELECT 1 FROM material_legacy_reconciliations r WHERE r.lot_id=l.id AND r.project_id=l.project_id)) THEN RAISE EXCEPTION 'PIPING_GRN_LEGACY_RECONCILIATION_REQUIRED' USING ERRCODE='23514'; END IF;
 SELECT COALESCE(sum(quantity),0) INTO total FROM piping_grn_records WHERE project_id=NEW.project_id AND source_line_id=(snap->>'line_id')::uuid;
 IF total+c.quantity>(snap->>'ordered_quantity')::numeric THEN RAISE EXCEPTION 'PIPING_GRN_ORDERED_QUANTITY_EXCEEDED' USING ERRCODE='23514'; END IF;
 NEW.source_line_id:=(snap->>'line_id')::uuid; NEW.purchase_order_id:=(snap->>'purchase_order_id')::uuid; NEW.source_snapshot:=snap;
 NEW.quantity:=c.quantity; NEW.unit:=c.unit; NEW.heat_ref:=eff->>'heat_ref'; NEW.custodian_company_id:=c.custodian_company_id;
 NEW.review_id:=q.review_id; NEW.amendment_id:=q.amendment_id; NEW.qa_payload:=NULL; NEW.number:=pcode||'-GRN-C-'||NEW.id::text;
 NEW.state:='POSTED'; NEW.created_at:=clock_timestamp(); RETURN NEW;
END 
$function$;
