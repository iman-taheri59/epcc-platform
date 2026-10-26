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
        // Derived current nominations only; never copy owner directory labels/history to participants.
        Schema::create('piping_receipt_nomination_index', function (Blueprint $t): void {
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('policy_id')->constrained('piping_receipt_author_policies')->restrictOnDelete();
            $t->foreignUuid('custodian_company_id')->constrained('companies')->restrictOnDelete();
            $t->string('action', 24);
            $t->string('selector', 160);
            $t->primary(['project_id', 'company_id', 'action', 'selector'], 'piping_receipt_nomination_index_pk');
        });
        // Bounded administrative migration context, restored even on failure; no runtime owner impersonation.
        $prior = DB::selectOne("SELECT current_setting('epcc.company_id',true) AS company,current_setting('epcc.project_id',true) AS project");
        try {
            foreach (DB::table('projects')->get(['id', 'owner_company_id']) as $project) {
                DB::select("SELECT set_config('epcc.company_id',?,true),set_config('epcc.project_id',?,true)", [$project->owner_company_id, $project->id]);
                DB::statement(<<<'SQL'
INSERT INTO piping_receipt_nomination_index(project_id,company_id,policy_id,custodian_company_id,action,selector)
SELECT p.project_id,(v.value->>'company_id')::uuid,p.id,p.custodian_company_id,a.key,v.value->>'selector'
FROM (SELECT * FROM piping_receipt_author_policies WHERE project_id=? ORDER BY version DESC LIMIT 1) p
CROSS JOIN LATERAL jsonb_each(p.actions) a CROSS JOIN LATERAL jsonb_array_elements(a.value) v
WHERE NOT p.suspended
SQL, [$project->id]);
            }
        } finally {
            DB::select("SELECT set_config('epcc.company_id',?,true),set_config('epcc.project_id',?,true)", [$prior->company ?? '', $prior->project ?? '']);
        }
        DB::unprepared(<<<'SQL'
ALTER TABLE piping_receipt_nomination_index ENABLE ROW LEVEL SECURITY;
ALTER TABLE piping_receipt_nomination_index FORCE ROW LEVEL SECURITY;
CREATE POLICY piping_nomination_read ON piping_receipt_nomination_index FOR SELECT USING (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id=piping_receipt_nomination_index.project_id AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)
 AND (company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid OR EXISTS(SELECT 1 FROM projects p WHERE p.id=piping_receipt_nomination_index.project_id AND p.owner_company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid))
);
CREATE POLICY piping_nomination_write ON piping_receipt_nomination_index FOR ALL USING (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM projects p WHERE p.id=piping_receipt_nomination_index.project_id AND p.owner_company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)
) WITH CHECK (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM projects p WHERE p.id=piping_receipt_nomination_index.project_id AND p.owner_company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid)
);
CREATE OR REPLACE FUNCTION epcc_guard_piping_nomination_index() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF pg_trigger_depth()<>2 THEN RAISE EXCEPTION 'PIPING_NOMINATION_DERIVED_ONLY' USING ERRCODE='23514'; END IF;
 IF TG_OP='DELETE' THEN RETURN OLD; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER piping_nomination_derived_only BEFORE INSERT OR UPDATE OR DELETE ON piping_receipt_nomination_index FOR EACH ROW EXECUTE FUNCTION epcc_guard_piping_nomination_index();
CREATE OR REPLACE FUNCTION epcc_project_piping_nominations() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 DELETE FROM piping_receipt_nomination_index WHERE project_id=NEW.project_id;
 IF NOT NEW.suspended THEN
  INSERT INTO piping_receipt_nomination_index(project_id,company_id,policy_id,custodian_company_id,action,selector)
  SELECT NEW.project_id,(v.value->>'company_id')::uuid,NEW.id,NEW.custodian_company_id,a.key,v.value->>'selector'
  FROM jsonb_each(NEW.actions) a CROSS JOIN LATERAL jsonb_array_elements(a.value) v;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER piping_nomination_project AFTER INSERT ON piping_receipt_author_policies FOR EACH ROW EXECUTE FUNCTION epcc_project_piping_nominations();

CREATE OR REPLACE FUNCTION epcc_piping_receipt_policy(pid uuid,aid uuid,cid uuid,act text) RETURNS uuid LANGUAGE sql STABLE AS $$
 SELECT n.policy_id FROM piping_receipt_nomination_index n
 JOIN users u ON u.id=aid AND u.company_id=cid AND u.is_active
 JOIN project_memberships pm ON pm.user_id=u.id AND pm.project_id=pid
 JOIN companies co ON co.id=cid JOIN plans pl ON pl.id=co.plan_id JOIN projects p ON p.id=pid
 JOIN companies cust ON cust.id=n.custodian_company_id JOIN plans cp ON cp.id=cust.plan_id
 WHERE n.project_id=pid AND n.company_id=cid AND (act IS NULL OR n.action=act)
 AND pid=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND cid=NULLIF(current_setting('epcc.company_id',true),'')::uuid
 AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id=pid AND pc.company_id=cid)
 AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id=pid AND pc.company_id=cust.id)
 AND (pm.discipline_scope IS NULL OR jsonb_exists(pm.discipline_scope,'PIPING'))
 AND (jsonb_exists(pl.modules::jsonb,'proc') OR jsonb_exists(pl.modules::jsonb,'*'))
 AND (jsonb_exists(pl.standards_packs::jsonb,p.standards_pack) OR jsonb_exists(pl.standards_packs::jsonb,'*'))
 AND (jsonb_exists(cp.modules::jsonb,'proc') OR jsonb_exists(cp.modules::jsonb,'*'))
 AND (jsonb_exists(cp.standards_packs::jsonb,p.standards_pack) OR jsonb_exists(cp.standards_packs::jsonb,'*'))
 AND (n.selector='user:' || aid::text OR EXISTS(SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code) AND n.selector='role:' || cid::text || ':' || r.code))
 ORDER BY n.action LIMIT 1
$$;
SQL);
        Schema::create('piping_custody_records', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('actor_name');
            $t->foreignUuid('policy_id')->constrained('piping_receipt_author_policies')->restrictOnDelete();
            $t->foreignUuid('custodian_company_id')->constrained('companies')->restrictOnDelete();
            $t->jsonb('custodian_snapshot');
            $t->uuid('request_id');
            $t->bigInteger('sequence');
            $t->foreignUuid('source_line_id')->nullable()->constrained('purchase_order_items')->restrictOnDelete();
            $t->foreignUuid('purchase_order_id')->nullable()->constrained('purchase_orders')->restrictOnDelete();
            $t->jsonb('source_snapshot')->nullable();
            $t->string('source_fingerprint', 64)->nullable();
            $t->string('unlinked_reason', 2000)->nullable();
            $t->string('shipment_ref', 200);
            $t->string('package_ref', 200)->nullable();
            $t->timestampTz('received_at');
            $t->string('observed_description', 2000);
            $t->decimal('quantity', 18, 3);
            $t->string('unit', 32);
            $t->string('heat_ref', 200)->nullable();
            $t->string('location_kind', 16);
            $t->string('location_ref', 200);
            $t->string('document_status', 24);
            $t->text('notes')->nullable();
            $t->string('state', 24)->default('CUSTODY_ONLY');
            $t->timestampTz('created_at');
            $t->unique(['project_id', 'sequence']);
            $t->unique(['project_id', 'request_id']);
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE piping_custody_records ADD CONSTRAINT piping_custody_shape CHECK (
 sequence>0 AND quantity>0 AND state='CUSTODY_ONLY' AND location_kind IN ('RECEIVING','QUARANTINE')
 AND document_status IN ('MISSING','PRESENT_UNVERIFIED') AND shipment_ref ~ '[^[:space:]]' AND observed_description ~ '[^[:space:]]' AND unit ~ '[^[:space:]]' AND location_ref ~ '[^[:space:]]'
 AND ((source_line_id IS NULL AND purchase_order_id IS NULL AND source_snapshot IS NULL AND source_fingerprint IS NULL AND unlinked_reason ~ '[^[:space:]]')
 OR (source_line_id IS NOT NULL AND purchase_order_id IS NOT NULL AND jsonb_typeof(source_snapshot)='object' AND char_length(source_fingerprint)=64 AND unlinked_reason IS NULL))
);
ALTER TABLE piping_custody_records ENABLE ROW LEVEL SECURITY;
ALTER TABLE piping_custody_records FORCE ROW LEVEL SECURITY;
CREATE POLICY piping_custody_read ON piping_custody_records FOR SELECT USING (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
 AND epcc_piping_receipt_policy(project_id,NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid,NULLIF(current_setting('epcc.company_id',true),'')::uuid,NULL) IS NOT NULL
 AND (purchase_order_id IS NULL OR EXISTS(SELECT 1 FROM purchase_orders po JOIN purchase_order_items li ON li.purchase_order_id=po.id AND li.id=piping_custody_records.source_line_id AND li.project_id=piping_custody_records.project_id WHERE po.id=piping_custody_records.purchase_order_id AND po.project_id=piping_custody_records.project_id AND po.discipline='PIPING'))
);
CREATE POLICY piping_custody_insert ON piping_custody_records FOR INSERT WITH CHECK (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
 AND created_by=NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid AND epcc_project_is_writable(project_id)
 AND policy_id=epcc_piping_receipt_policy(project_id,created_by,company_id,'custody_mrr')
);
CREATE OR REPLACE FUNCTION epcc_guard_piping_custody() RETURNS trigger LANGUAGE plpgsql AS $$
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
END $$;
SQL);
        Schema::create('piping_custody_counters', function (Blueprint $t): void {
            $t->foreignUuid('project_id')->primary()->constrained('projects')->restrictOnDelete();
            $t->bigInteger('last_sequence');
        });
        DB::unprepared(<<<'SQL'
ALTER TABLE piping_custody_counters ENABLE ROW LEVEL SECURITY;
ALTER TABLE piping_custody_counters FORCE ROW LEVEL SECURITY;
CREATE POLICY piping_custody_counter ON piping_custody_counters FOR ALL USING (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND epcc_piping_receipt_policy(project_id,NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid,NULLIF(current_setting('epcc.company_id',true),'')::uuid,'custody_mrr') IS NOT NULL
) WITH CHECK (
 project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND epcc_piping_receipt_policy(project_id,NULLIF(current_setting('epcc.receipt_actor_id',true),'')::uuid,NULLIF(current_setting('epcc.company_id',true),'')::uuid,'custody_mrr') IS NOT NULL
);
CREATE OR REPLACE FUNCTION epcc_guard_piping_custody_counter() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF pg_trigger_depth()<>2 OR TG_OP='DELETE' THEN RAISE EXCEPTION 'PIPING_CUSTODY_COUNTER_INTERNAL' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER piping_custody_counter_internal BEFORE INSERT OR UPDATE OR DELETE ON piping_custody_counters FOR EACH ROW EXECUTE FUNCTION epcc_guard_piping_custody_counter();
CREATE TRIGGER piping_custody_guard BEFORE INSERT OR UPDATE OR DELETE ON piping_custody_records FOR EACH ROW EXECUTE FUNCTION epcc_guard_piping_custody();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Custody evidence and nomination provenance are forward-only.');
    }
};
