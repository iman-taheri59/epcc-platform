<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piping_qa_decisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('custody_id')->constrained('piping_custody_records')->restrictOnDelete();
            $t->foreignUuid('review_id')->constrained('piping_receiving_reviews')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('decided_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('policy_id')->constrained('piping_receipt_author_policies')->restrictOnDelete();
            $t->uuid('previous_decision_id')->nullable();
            $t->uuid('request_id');
            $t->string('payload_hash', 64);
            $t->integer('sequence');
            $t->string('actor_name');
            $t->string('custody_fingerprint', 64);
            $t->jsonb('source_basis')->nullable();
            $t->string('decision', 16);
            $t->text('reason');
            $t->text('open_conditions')->nullable();
            $t->jsonb('declarations');
            $t->timestampTz('created_at');
            $t->unique(['custody_id', 'sequence']);
            $t->unique(['project_id', 'request_id']);
        });
        Schema::table('piping_qa_decisions', fn (Blueprint $t) => $t->foreign('previous_decision_id')->references('id')->on('piping_qa_decisions')->restrictOnDelete());
        Schema::create('piping_qa_evidence', function (Blueprint $t): void {
            $t->foreignUuid('decision_id')->constrained('piping_qa_decisions')->restrictOnDelete();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('file_id')->constrained('document_files')->restrictOnDelete();
            $t->string('kind', 16);
            $t->jsonb('snapshot');
            $t->primary(['decision_id', 'kind', 'file_id']);
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_piping_qa_document_reader(pid uuid,aid uuid,cid uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
 SELECT EXISTS(SELECT 1 FROM users u JOIN project_memberships m ON m.user_id=u.id JOIN companies c ON c.id=u.company_id JOIN plans pl ON pl.id=c.plan_id JOIN projects p ON p.id=m.project_id
 WHERE u.id=aid AND u.company_id=cid AND u.is_active AND m.project_id=pid
 AND (jsonb_exists(pl.modules::jsonb,'doc') OR jsonb_exists(pl.modules::jsonb,'*'))
 AND (jsonb_exists(pl.standards_packs::jsonb,p.standards_pack) OR jsonb_exists(pl.standards_packs::jsonb,'*'))
 AND EXISTS(SELECT 1 FROM roles r WHERE jsonb_exists(m.role_codes,r.code) AND (jsonb_exists(r.capabilities,'doc.view') OR jsonb_exists(r.capabilities,'doc.*') OR jsonb_exists(r.capabilities,'*'))));
$$;
ALTER TABLE piping_qa_decisions ADD CONSTRAINT piping_qa_shape CHECK (
 sequence>0 AND decision IN ('ACCEPTED','HOLD','REJECTED') AND char_length(custody_fingerprint)=64
 AND char_length(reason) BETWEEN 1 AND 4000 AND reason ~ '[^[:space:]]'
 AND (open_conditions IS NULL OR char_length(open_conditions)<=4000) AND jsonb_typeof(declarations)='object'
);
ALTER TABLE piping_qa_decisions ENABLE ROW LEVEL SECURITY;
ALTER TABLE piping_qa_decisions FORCE ROW LEVEL SECURITY;
CREATE POLICY piping_qa_read ON piping_qa_decisions FOR SELECT USING (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM piping_custody_records c WHERE c.id=piping_qa_decisions.custody_id AND c.project_id=piping_qa_decisions.project_id)
);
CREATE POLICY piping_qa_insert ON piping_qa_decisions FOR INSERT WITH CHECK (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
 AND decided_by=NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid AND epcc_project_is_writable(project_id)
 AND policy_id=epcc_piping_receipt_policy(project_id,decided_by,company_id,'acceptance_qa')
);
ALTER TABLE piping_qa_evidence ENABLE ROW LEVEL SECURITY;
ALTER TABLE piping_qa_evidence FORCE ROW LEVEL SECURITY;
CREATE POLICY piping_qa_evidence_read ON piping_qa_evidence FOR SELECT USING (
 epcc_piping_qa_document_reader(project_id,NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid,NULLIF(current_setting('epcc.company_id',true),'')::uuid) AND
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM piping_qa_decisions d WHERE d.id=piping_qa_evidence.decision_id AND d.project_id=piping_qa_evidence.project_id)
 AND EXISTS(SELECT 1 FROM document_files f JOIN document_revisions r ON r.id=f.revision_id JOIN documents doc ON doc.id=r.document_id
 WHERE f.id=piping_qa_evidence.file_id AND f.project_id=piping_qa_evidence.project_id AND r.project_id=f.project_id AND doc.project_id=f.project_id)
);
CREATE POLICY piping_qa_evidence_insert ON piping_qa_evidence FOR INSERT WITH CHECK (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM piping_qa_decisions d WHERE d.id=piping_qa_evidence.decision_id AND d.project_id=piping_qa_evidence.project_id
 AND d.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND d.decided_by=NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid)
);
CREATE OR REPLACE FUNCTION epcc_guard_piping_qa() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE c piping_custody_records%ROWTYPE; q piping_receiving_reviews%ROWTYPE; head uuid; serial integer; k text; src record;
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
 SELECT * INTO q FROM piping_receiving_reviews WHERE custody_id=c.id AND project_id=NEW.project_id ORDER BY sequence DESC LIMIT 1;
 IF NOT FOUND OR NEW.review_id IS DISTINCT FROM q.id THEN RAISE EXCEPTION 'PIPING_QA_QC_STALE' USING ERRCODE='23514'; END IF;
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
 NEW.source_basis:=src.basis;
 END IF;
 IF NEW.policy_id IS DISTINCT FROM epcc_piping_receipt_policy(NEW.project_id,NEW.decided_by,NEW.company_id,'acceptance_qa') THEN RAISE EXCEPTION 'PIPING_QA_AUTHORITY' USING ERRCODE='42501'; END IF;
 NEW.sequence:=COALESCE(serial,0)+1; NEW.custody_fingerprint:=epcc_piping_custody_fingerprint(c.id);
 SELECT name INTO NEW.actor_name FROM users WHERE id=NEW.decided_by AND company_id=NEW.company_id AND is_active;
 NEW.created_at:=clock_timestamp(); RETURN NEW;
END $$;
CREATE TRIGGER piping_qa_guard BEFORE INSERT OR UPDATE OR DELETE ON piping_qa_decisions FOR EACH ROW EXECUTE FUNCTION epcc_guard_piping_qa();
CREATE OR REPLACE FUNCTION epcc_guard_piping_qa_evidence() RETURNS trigger LANGUAGE plpgsql AS $$
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
END $$;
CREATE TRIGGER piping_qa_evidence_guard BEFORE INSERT OR UPDATE OR DELETE ON piping_qa_evidence FOR EACH ROW EXECUTE FUNCTION epcc_guard_piping_qa_evidence();
CREATE OR REPLACE FUNCTION epcc_finalize_piping_qa() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.decision='ACCEPTED' AND (
 NOT EXISTS(SELECT 1 FROM piping_qa_evidence WHERE decision_id=NEW.id AND kind='REQUIREMENT') OR NOT EXISTS(SELECT 1 FROM piping_qa_evidence WHERE decision_id=NEW.id AND kind='EVIDENCE')
 ) THEN RAISE EXCEPTION 'PIPING_QA_EVIDENCE_REQUIRED' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER piping_qa_complete AFTER INSERT ON piping_qa_decisions DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION epcc_finalize_piping_qa();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('QA decision/evidence history is forward-only.');
    }
};
