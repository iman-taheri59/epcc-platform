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
        Schema::create('piping_grn_records', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('posted_by')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('policy_id')->constrained('piping_receipt_author_policies')->restrictOnDelete();
            $t->foreignUuid('custody_id')->unique()->constrained('piping_custody_records')->restrictOnDelete();
            $t->foreignUuid('qa_id')->constrained('piping_qa_decisions')->restrictOnDelete();
            $t->char('qa_payload_hash', 64);
            $t->text('qa_payload')->nullable(); // transient proof, erased by guard
            $t->foreignUuid('review_id')->constrained('piping_receiving_reviews')->restrictOnDelete();
            $t->foreignUuid('amendment_id')->nullable()->constrained('piping_custody_amendments')->restrictOnDelete();
            $t->foreignUuid('custodian_company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->foreignUuid('source_line_id')->constrained('purchase_order_items')->restrictOnDelete();
            $t->uuid('request_id');
            $t->char('request_hash', 64);
            $t->string('number', 128)->unique();
            $t->decimal('quantity', 18, 3);
            $t->string('unit', 16);
            $t->string('heat_ref', 200);
            $t->string('location_ref', 200);
            $t->text('reason');
            $t->jsonb('source_snapshot');
            $t->string('state', 16)->default('POSTED');
            $t->timestampTz('created_at');
            $t->unique(['project_id', 'request_id']);
            $t->unique(['project_id', 'id']);
        });
        Schema::create('piping_accepted_stock_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('grn_id')->unique();
            $t->foreignUuid('custodian_company_id')->constrained('companies')->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            $t->string('unit', 16);
            $t->timestampTz('created_at');
            $t->foreign(['project_id', 'grn_id'])->references(['project_id', 'id'])->on('piping_grn_records')->restrictOnDelete();
        });
        Schema::create('piping_grn_posted_index', function (Blueprint $t): void {
            $t->uuid('project_id');
            $t->uuid('custody_id');
            $t->uuid('grn_id');
            $t->primary(['project_id', 'custody_id']);
            $t->foreign(['project_id', 'grn_id'])->references(['project_id', 'id'])->on('piping_grn_records')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE piping_grn_records ADD CHECK(state='POSTED' AND quantity>0 AND qa_payload IS NULL AND qa_payload_hash ~ '^[a-f0-9]{64}$' AND request_hash ~ '^[a-f0-9]{64}$'
 AND length(reason)<=4000 AND reason ~ '[^[:space:]]' AND location_ref ~ '[^[:space:]]');
ALTER TABLE piping_accepted_stock_entries ADD CHECK(quantity>0);
ALTER TABLE piping_grn_records ENABLE ROW LEVEL SECURITY; ALTER TABLE piping_grn_records FORCE ROW LEVEL SECURITY;
ALTER TABLE piping_accepted_stock_entries ENABLE ROW LEVEL SECURITY; ALTER TABLE piping_accepted_stock_entries FORCE ROW LEVEL SECURITY;
-- All metadata for a currently visible exact source is available to explicit receipt readers,
-- independently of the recorder's company or visibility of the original pre-amendment source.
CREATE POLICY piping_grn_read ON piping_grn_records FOR SELECT USING (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND epcc_piping_receipt_policy(project_id,NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid,NULLIF(current_setting('epcc.company_id',true),'')::uuid,NULL) IS NOT NULL
 AND EXISTS(SELECT 1 FROM purchase_order_items l JOIN purchase_orders p ON p.id=l.purchase_order_id WHERE l.id=source_line_id AND p.id=piping_grn_records.purchase_order_id AND l.project_id=piping_grn_records.project_id AND p.project_id=l.project_id AND p.discipline='PIPING'));
CREATE POLICY piping_grn_insert ON piping_grn_records FOR INSERT WITH CHECK(
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
 AND posted_by=NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid AND policy_id=epcc_piping_receipt_policy(project_id,posted_by,company_id,'post_grn'));
CREATE POLICY piping_stock_read ON piping_accepted_stock_entries FOR SELECT USING(EXISTS(SELECT 1 FROM piping_grn_records g WHERE g.id=grn_id AND g.project_id=piping_accepted_stock_entries.project_id));
CREATE POLICY piping_stock_insert ON piping_accepted_stock_entries FOR INSERT WITH CHECK(EXISTS(SELECT 1 FROM piping_grn_records g WHERE g.id=grn_id AND g.project_id=piping_accepted_stock_entries.project_id AND g.posted_by=NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid AND g.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid));
ALTER TABLE piping_grn_posted_index ENABLE ROW LEVEL SECURITY; ALTER TABLE piping_grn_posted_index FORCE ROW LEVEL SECURITY;
CREATE POLICY piping_posted_read ON piping_grn_posted_index FOR SELECT USING(project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND EXISTS(SELECT 1 FROM piping_custody_records c WHERE c.id=custody_id AND c.project_id=piping_grn_posted_index.project_id));
CREATE POLICY piping_posted_insert ON piping_grn_posted_index FOR INSERT WITH CHECK(EXISTS(SELECT 1 FROM piping_grn_records g WHERE g.id=grn_id AND g.project_id=piping_grn_posted_index.project_id AND g.custody_id=piping_grn_posted_index.custody_id));
CREATE OR REPLACE FUNCTION epcc_guard_piping_posted_index() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF TG_OP<>'INSERT' OR pg_trigger_depth()<>2 THEN RAISE EXCEPTION 'PIPING_POSTED_INDEX_DERIVED_ONLY' USING ERRCODE='23514'; END IF; RETURN NEW;
END $$;
CREATE TRIGGER piping_posted_guard BEFORE INSERT OR UPDATE OR DELETE ON piping_grn_posted_index FOR EACH ROW EXECUTE FUNCTION epcc_guard_piping_posted_index();
CREATE OR REPLACE FUNCTION epcc_piping_grn_policy_digest(tab regclass) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT encode(sha256(convert_to(COALESCE(jsonb_agg(jsonb_build_array(polname,polcmd,polpermissive,polroles::text,pg_get_expr(polqual,polrelid)) ORDER BY polname)::text,'[]'),'UTF8')),'hex') FROM pg_policy WHERE polrelid=tab AND polcmd IN ('r','*')
$$;
CREATE OR REPLACE FUNCTION epcc_guard_piping_grn() RETURNS trigger LANGUAGE plpgsql AS $$
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
 IF EXISTS(SELECT 1 FROM material_lots WHERE project_id=NEW.project_id AND purchase_order_id=(snap->>'purchase_order_id')::uuid) THEN RAISE EXCEPTION 'PIPING_GRN_LEGACY_RECONCILIATION_REQUIRED' USING ERRCODE='23514'; END IF;
 SELECT COALESCE(sum(quantity),0) INTO total FROM piping_grn_records WHERE project_id=NEW.project_id AND source_line_id=(snap->>'line_id')::uuid;
 IF total+c.quantity>(snap->>'ordered_quantity')::numeric THEN RAISE EXCEPTION 'PIPING_GRN_ORDERED_QUANTITY_EXCEEDED' USING ERRCODE='23514'; END IF;
 NEW.source_line_id:=(snap->>'line_id')::uuid; NEW.purchase_order_id:=(snap->>'purchase_order_id')::uuid; NEW.source_snapshot:=snap;
 NEW.quantity:=c.quantity; NEW.unit:=c.unit; NEW.heat_ref:=eff->>'heat_ref'; NEW.custodian_company_id:=c.custodian_company_id;
 NEW.review_id:=q.review_id; NEW.amendment_id:=q.amendment_id; NEW.qa_payload:=NULL; NEW.number:=pcode||'-GRN-C-'||NEW.id::text;
 NEW.state:='POSTED'; NEW.created_at:=clock_timestamp(); RETURN NEW;
END $$;
CREATE TRIGGER piping_grn_guard BEFORE INSERT OR UPDATE OR DELETE ON piping_grn_records FOR EACH ROW EXECUTE FUNCTION epcc_guard_piping_grn();
CREATE OR REPLACE FUNCTION epcc_guard_piping_stock_credit() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE g piping_grn_records%ROWTYPE;
BEGIN
 IF TG_OP<>'INSERT' OR pg_trigger_depth()<>2 THEN RAISE EXCEPTION 'PIPING_STOCK_DERIVED_IMMUTABLE' USING ERRCODE='23514'; END IF;
 SELECT * INTO g FROM piping_grn_records WHERE id=NEW.grn_id AND project_id=NEW.project_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_STOCK_PARENT' USING ERRCODE='42501'; END IF;
 NEW.quantity:=g.quantity; NEW.unit:=g.unit; NEW.custodian_company_id:=g.custodian_company_id; NEW.created_at:=g.created_at; RETURN NEW;
END $$;
CREATE TRIGGER piping_stock_guard BEFORE INSERT OR UPDATE OR DELETE ON piping_accepted_stock_entries FOR EACH ROW EXECUTE FUNCTION epcc_guard_piping_stock_credit();
CREATE OR REPLACE FUNCTION epcc_post_piping_stock_credit() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 INSERT INTO piping_accepted_stock_entries(id,project_id,grn_id) VALUES(gen_random_uuid(),NEW.project_id,NEW.id);
 INSERT INTO piping_grn_posted_index(project_id,custody_id,grn_id) VALUES(NEW.project_id,NEW.custody_id,NEW.id); RETURN NEW;
END $$;
CREATE TRIGGER piping_grn_credit AFTER INSERT ON piping_grn_records FOR EACH ROW EXECUTE FUNCTION epcc_post_piping_stock_credit();
CREATE OR REPLACE FUNCTION epcc_finalize_piping_grn() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NOT EXISTS(SELECT 1 FROM piping_accepted_stock_entries e WHERE e.grn_id=NEW.id AND e.project_id=NEW.project_id AND e.quantity=NEW.quantity AND e.unit=NEW.unit AND e.custodian_company_id=NEW.custodian_company_id)
 OR NOT EXISTS(SELECT 1 FROM piping_grn_posted_index i WHERE i.grn_id=NEW.id AND i.project_id=NEW.project_id AND i.custody_id=NEW.custody_id) THEN RAISE EXCEPTION 'PIPING_GRN_INCOMPLETE_CREDIT' USING ERRCODE='23514'; END IF; RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER piping_grn_complete AFTER INSERT ON piping_grn_records DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION epcc_finalize_piping_grn();
CREATE OR REPLACE FUNCTION epcc_piping_posted_cutoff() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('piping-receipt-access:'||NEW.project_id::text,0));
 PERFORM 1 FROM projects WHERE id=NEW.project_id FOR NO KEY UPDATE;
 IF NOT epcc_piping_grn_posted_index_ok() OR EXISTS(SELECT 1 FROM piping_grn_posted_index WHERE custody_id=NEW.custody_id AND project_id=NEW.project_id) THEN RAISE EXCEPTION 'PIPING_GRN_POSTED_CORRECTION_WORKFLOW_REQUIRED' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER aa_grn_posted_cutoff BEFORE INSERT ON piping_qa_decisions FOR EACH ROW EXECUTE FUNCTION epcc_piping_posted_cutoff();
CREATE TRIGGER aa_grn_posted_cutoff BEFORE INSERT ON piping_receiving_reviews FOR EACH ROW EXECUTE FUNCTION epcc_piping_posted_cutoff();
CREATE TRIGGER aa_grn_posted_cutoff BEFORE INSERT ON piping_custody_amendments FOR EACH ROW EXECUTE FUNCTION epcc_piping_posted_cutoff();
-- Known PIPING sources cannot continue through the advisory legacy warehouse.
CREATE OR REPLACE FUNCTION epcc_piping_legacy_stock_cutoff() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE pid uuid; poid uuid; v text;
BEGIN
 IF TG_TABLE_NAME='material_lots' THEN
  IF TG_OP<>'INSERT' THEN
   SELECT discipline INTO v FROM purchase_orders WHERE id=OLD.purchase_order_id;
   IF OLD.purchase_order_id IS NOT NULL AND (NOT FOUND OR v='PIPING') THEN RAISE EXCEPTION 'PIPING_GRN_CONTROLLED_LEDGER_REQUIRED' USING ERRCODE='23514'; END IF;
  END IF;
  IF TG_OP<>'DELETE' THEN
   SELECT discipline INTO v FROM purchase_orders WHERE id=NEW.purchase_order_id;
   IF NEW.purchase_order_id IS NOT NULL AND (NOT FOUND OR v='PIPING') THEN RAISE EXCEPTION 'PIPING_GRN_CONTROLLED_LEDGER_REQUIRED' USING ERRCODE='23514'; END IF;
  END IF;
 ELSE
  IF TG_OP='DELETE' THEN pid:=OLD.lot_id; ELSE pid:=NEW.lot_id; END IF;
  SELECT purchase_order_id INTO poid FROM material_lots WHERE id=pid;
  IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_GRN_LEGACY_LOT_NOT_VISIBLE' USING ERRCODE='42501'; END IF;
  SELECT discipline INTO v FROM purchase_orders WHERE id=poid;
  IF poid IS NOT NULL AND (NOT FOUND OR v='PIPING') THEN RAISE EXCEPTION 'PIPING_GRN_CONTROLLED_LEDGER_REQUIRED' USING ERRCODE='23514'; END IF;
 END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF; RETURN NEW;
END $$;
CREATE TRIGGER aa_piping_legacy_stock_cutoff BEFORE INSERT OR UPDATE OR DELETE ON material_lots FOR EACH ROW EXECUTE FUNCTION epcc_piping_legacy_stock_cutoff();
CREATE TRIGGER aa_piping_legacy_stock_cutoff BEFORE INSERT OR UPDATE OR DELETE ON material_movements FOR EACH ROW EXECUTE FUNCTION epcc_piping_legacy_stock_cutoff();
SQL);
        // Freeze the normal invoker metadata contract; any later added/changed SELECT
        // policy fails closed instead of silently undercounting a hidden ledger/head.
        $tables = ['material_lots', 'piping_grn_records', 'piping_accepted_stock_entries', 'piping_grn_posted_index', 'piping_qa_decisions', 'piping_receiving_reviews', 'piping_custody_amendments'];
        if (DB::scalar("SELECT array_agg(polname::text ORDER BY polname)::text FROM pg_policy WHERE polrelid='material_lots'::regclass AND polcmd IN ('r','*')") !== '{material_lots_archive_freeze,material_lots_tenant_isolation}') {
            throw new RuntimeException('Unexpected legacy inventory policy; reconcile before installing GRN.');
        }
        // Parse the accepted baseline through PostgreSQL rather than blessing names
        // or relying on server-version-specific pg_get_expr whitespace.
        DB::unprepared('CREATE TEMP TABLE _grn_read_probe(project_id uuid,custody_id uuid) ON COMMIT DROP;');
        $assertRead = function (string $table, string $name, string $body, string $cmd = 'r', bool $permissive = true): void {
            DB::unprepared('DROP POLICY IF EXISTS expected_read ON _grn_read_probe');
            DB::unprepared('CREATE POLICY expected_read ON _grn_read_probe FOR SELECT USING ('.str_replace('{T}', '_grn_read_probe', $body).')');
            $expected = str_replace('_grn_read_probe', $table, DB::scalar("SELECT pg_get_expr(polqual,polrelid) FROM pg_policy WHERE polrelid='_grn_read_probe'::regclass AND polname='expected_read'"));
            $rows = DB::select("SELECT polname,polcmd,polpermissive,polroles::text AS roles,pg_get_expr(polqual,polrelid) AS body FROM pg_policy WHERE polrelid=?::regclass AND polcmd IN ('r','*')", [$table]);
            $row = collect($rows)->firstWhere('polname', $name);
            if (! $row || $row->polcmd !== $cmd || (bool) $row->polpermissive !== $permissive || $row->roles !== '{0}' || $row->body !== $expected) {
                throw new RuntimeException('Unrecognized GRN completeness baseline: '.$table);
            }
        };
        $assertRead('material_lots', 'material_lots_archive_freeze', 'true', '*', false);
        $assertRead('material_lots', 'material_lots_tenant_isolation', <<<'SQL'
{T}.project_id IN (
 SELECT pc.project_id FROM project_company pc WHERE pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND (NULLIF(current_setting('epcc.project_id',true),'') IS NULL OR pc.project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid)
 UNION SELECT p.id FROM projects p WHERE p.owner_company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND (NULLIF(current_setting('epcc.project_id',true),'') IS NULL OR p.id=NULLIF(current_setting('epcc.project_id',true),'')::uuid)
) AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id={T}.project_id AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)
SQL, '*');
        foreach (['piping_qa_decisions' => 'piping_qa_read', 'piping_receiving_reviews' => 'piping_receiving_review_read', 'piping_custody_amendments' => 'piping_amendments_read'] as $table => $name) {
            if ((int) DB::scalar("SELECT count(*) FROM pg_policy WHERE polrelid=?::regclass AND polcmd IN ('r','*')", [$table]) !== 1) {
                throw new RuntimeException('Unexpected GRN head policy: '.$table);
            }
            $assertRead($table, $name, "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND EXISTS(SELECT 1 FROM piping_custody_records c WHERE c.id={T}.custody_id AND c.project_id={T}.project_id)");
        }
        DB::unprepared('DROP TABLE _grn_read_probe');
        $checks = [];
        foreach ($tables as $table) {
            $digest = DB::scalar('SELECT epcc_piping_grn_policy_digest(?::regclass)', [$table]);
            $checks[] = "epcc_piping_grn_policy_digest('{$table}'::regclass)='{$digest}'";
        }
        $postedDigest = DB::scalar("SELECT epcc_piping_grn_policy_digest('piping_grn_posted_index'::regclass)");
        DB::unprepared("CREATE OR REPLACE FUNCTION epcc_piping_grn_posted_index_ok() RETURNS boolean LANGUAGE sql STABLE AS \$\$ SELECT epcc_piping_grn_policy_digest('piping_grn_posted_index'::regclass)='{$postedDigest}' \$\$;");
        DB::unprepared('CREATE OR REPLACE FUNCTION epcc_piping_grn_inventory_ok() RETURNS boolean LANGUAGE sql STABLE AS $$ SELECT '.implode(' AND ', $checks).' $$;');
    }

    public function down(): void
    {
        throw new RuntimeException('Controlled GRN and accepted-stock history are forward-only.');
    }
};
