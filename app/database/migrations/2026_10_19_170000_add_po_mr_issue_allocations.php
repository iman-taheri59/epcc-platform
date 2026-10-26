<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-038 step2: full-copy source commitment only at controlled issuance. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('po_execution_decisions', function (Blueprint $t): void {
            $t->string('source_hash', 64)->nullable();
        });
        DB::statement("ALTER TABLE po_execution_decisions ADD CONSTRAINT po_execution_source_hash_shape CHECK (source_hash IS NULL OR source_hash ~ '^[a-f0-9]{64}$')");
        Schema::create('po_mr_allocations', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->foreignUuid('purchase_order_item_id')->constrained('purchase_order_items')->restrictOnDelete();
            $t->foreignUuid('requisition_id')->constrained('material_requisitions')->restrictOnDelete();
            $t->foreignUuid('requisition_item_id')->constrained('material_requisition_items')->restrictOnDelete();
            $t->foreignUuid('issue_decision_id')->constrained('po_execution_decisions')->restrictOnDelete();
            $t->decimal('quantity', 18, 3);
            $t->decimal('approved_quantity', 18, 3);
            $t->string('unit', 16);
            $t->string('source_hash', 64);
            $t->timestampTz('created_at');
            $t->unique('purchase_order_item_id', 'po_mr_allocation_line_unique');
            $t->index(['project_id', 'buyer_company_id', 'requisition_item_id'], 'po_mr_allocation_source_index');
        });
        DB::statement("ALTER TABLE po_mr_allocations ADD CONSTRAINT po_mr_allocation_shape CHECK (quantity>0 AND approved_quantity>=quantity AND source_hash ~ '^[a-f0-9]{64}$')");
        DB::statement('ALTER TABLE po_mr_allocations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE po_mr_allocations FORCE ROW LEVEL SECURITY');
        $scope = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND buyer_company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id=po_mr_allocations.project_id AND pc.company_id=po_mr_allocations.buyer_company_id)";
        DB::statement("CREATE POLICY po_mr_allocation_read ON po_mr_allocations FOR SELECT USING ({$scope})");
        DB::statement("CREATE POLICY po_mr_allocation_insert ON po_mr_allocations FOR INSERT WITH CHECK ({$scope} AND epcc_project_is_writable(project_id))");
        DB::unprepared(<<<'SQL'
-- Opaque source version evidence; no MR contents are delegated to the execution UI.
CREATE OR REPLACE FUNCTION epcc_po_mr_source_hash(rid uuid) RETURNS text LANGUAGE sql STABLE AS $$
 SELECT epcc_po_execution_digest(jsonb_build_object('schema',1,'header',to_jsonb(r),'lines',
  COALESCE((SELECT jsonb_agg(to_jsonb(m) ORDER BY m.line_no,m.id) FROM material_requisition_items m WHERE m.requisition_id=r.id AND m.project_id=r.project_id),'[]'::jsonb)))
 FROM material_requisitions r WHERE r.id=rid
$$;
-- Read-only eligibility reason used by the workspace; locks are taken by the decision guard.
CREATE OR REPLACE FUNCTION epcc_po_mr_refusal(oid uuid,issuing boolean) RETURNS text LANGUAGE plpgsql AS $$
DECLARE po purchase_orders%ROWTYPE;
BEGIN
 SELECT * INTO po FROM purchase_orders WHERE id=oid;
 IF NOT FOUND THEN RETURN 'PO_EXEC_MR_SOURCE'; END IF;
 IF po.requisition_id IS NULL THEN RETURN NULL; END IF;
 IF EXISTS(SELECT 1 FROM purchase_orders p WHERE p.project_id=po.project_id AND p.company_id=po.company_id AND p.id<>po.id
  AND (p.requisition_id=po.requisition_id OR EXISTS(SELECT 1 FROM purchase_order_items l JOIN material_requisition_items m ON m.id=l.requisition_item_id WHERE l.purchase_order_id=p.id AND m.requisition_id=po.requisition_id))
  AND (p.state<>'DRAFT' OR p.issued_by IS NOT NULL OR p.issued_at IS NOT NULL)
  AND NOT EXISTS(SELECT 1 FROM po_execution_decisions e WHERE e.purchase_order_id=p.id AND e.purpose='issue' AND e.source_hash IS NOT NULL)) THEN
  RETURN 'PO_EXEC_MR_LEGACY_RECONCILIATION_REQUIRED'; END IF;
 IF NOT epcc_po_pricing_source_valid(po.id,po.project_id,po.company_id) THEN RETURN 'PO_EXEC_MR_SOURCE'; END IF;
 IF NOT EXISTS(SELECT 1 FROM po_pricing_revisions a WHERE a.purchase_order_id=po.id
  AND a.sequence=(SELECT max(sequence) FROM po_pricing_revisions WHERE purchase_order_id=po.id)
  AND a.content_hash=epcc_po_execution_digest(epcc_po_execution_content(to_jsonb(po)))) THEN
  RETURN 'PO_EXEC_MR_PRICING_REQUIRED'; END IF;
 IF issuing AND EXISTS(SELECT 1 FROM purchase_order_items l JOIN material_requisition_items m ON m.id=l.requisition_item_id
  WHERE l.purchase_order_id=po.id AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id)
  AND l.quantity+COALESCE((SELECT sum(a.quantity) FROM po_mr_allocations a WHERE a.requisition_item_id=m.id),0)>m.quantity) THEN
  RETURN 'PO_EXEC_MR_QUANTITY_EXCEEDED'; END IF;
 RETURN NULL;
END $$;
CREATE OR REPLACE FUNCTION epcc_guard_po_execution_decision() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE po purchase_orders%ROWTYPE; approval po_execution_decisions%ROWTYPE; content jsonb; expected_policy uuid; n integer; total numeric;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
    PERFORM 1 FROM projects WHERE id=NEW.project_id FOR UPDATE;
    SELECT * INTO po FROM purchase_orders WHERE id=NEW.purchase_order_id AND project_id=NEW.project_id AND company_id=NEW.buyer_company_id FOR UPDATE;
    IF NOT FOUND OR po.state<>'DRAFT' OR po.author_policy_id IS NULL OR po.drafted_by IS NULL OR po.material_category IS NULL
        OR NOT epcc_project_is_writable(NEW.project_id) THEN
        RAISE EXCEPTION 'PO_EXEC_NOT_ELIGIBLE' USING ERRCODE='23514';
    END IF;
    expected_policy:=epcc_po_execution_live_policy(po.project_id,NEW.actor_id,NEW.actor_company_id,po.company_id,NEW.purpose,po.discipline);
    IF expected_policy IS NULL OR expected_policy IS DISTINCT FROM NEW.policy_id THEN RAISE EXCEPTION 'PO_EXEC_AUTHORITY' USING ERRCODE='42501'; END IF;
    IF NEW.actor_id=po.drafted_by THEN RAISE EXCEPTION 'PO_EXEC_SEPARATION' USING ERRCODE='42501'; END IF;
    IF NOT EXISTS(SELECT 1 FROM companies c JOIN project_company pc ON pc.company_id=c.id WHERE c.id=po.vendor_company_id AND c.tenant_type='VEN' AND pc.project_id=po.project_id) THEN
        RAISE EXCEPTION 'PO_EXEC_VENDOR' USING ERRCODE='23514';
    END IF;
    IF EXISTS(SELECT 1 FROM purchase_order_items l LEFT JOIN purchase_order_items prev ON prev.id=l.supersedes_id
        WHERE l.purchase_order_id=po.id AND (l.project_id<>po.project_id OR (l.supersedes_id IS NOT NULL AND (prev.id IS NULL OR prev.purchase_order_id<>po.id))))
        OR (SELECT count(*) FROM purchase_order_items WHERE purchase_order_id=po.id)>200 THEN
        RAISE EXCEPTION 'PO_EXEC_LINEAGE' USING ERRCODE='23514';
    END IF;
    IF EXISTS(WITH RECURSIVE walk AS (
        SELECT id,supersedes_id,ARRAY[id] AS path,false AS cycle FROM purchase_order_items WHERE purchase_order_id=po.id
        UNION ALL SELECT l.id,l.supersedes_id,w.path||l.id,l.id=ANY(w.path) FROM walk w JOIN purchase_order_items l ON l.id=w.supersedes_id
        WHERE NOT w.cycle AND l.purchase_order_id=po.id) SELECT 1 FROM walk WHERE cycle) THEN
        RAISE EXCEPTION 'PO_EXEC_LINEAGE' USING ERRCODE='23514';
    END IF;
    SELECT count(*),round(sum(l.quantity*l.unit_price),2) INTO n,total FROM purchase_order_items l WHERE l.purchase_order_id=po.id
        AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id);
    IF n=0 OR po.currency IS NULL OR po.currency !~ '^[A-Z]{3,8}$' OR po.total_value IS NULL OR total IS DISTINCT FROM po.total_value
        OR EXISTS(SELECT 1 FROM purchase_order_items l WHERE l.purchase_order_id=po.id
            AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id)
            AND (l.unit_price IS NULL OR l.unit_price<0 OR l.quantity<=0)) THEN
        RAISE EXCEPTION 'PO_EXEC_PRICING' USING ERRCODE='23514';
    END IF;
    IF po.requisition_id IS NOT NULL THEN
        PERFORM 1 FROM material_requisitions WHERE id=po.requisition_id AND project_id=po.project_id AND company_id=po.company_id FOR UPDATE;
        PERFORM 1 FROM material_requisition_items WHERE requisition_id=po.requisition_id ORDER BY id FOR SHARE;
        IF epcc_po_mr_refusal(po.id,NEW.purpose='issue') IS NOT NULL THEN
            RAISE EXCEPTION '%',epcc_po_mr_refusal(po.id,NEW.purpose='issue') USING ERRCODE='23514';
        END IF;
        NEW.source_hash:=epcc_po_mr_source_hash(po.requisition_id);
    ELSE
        NEW.source_hash:=NULL;
        IF EXISTS(SELECT 1 FROM purchase_order_items WHERE purchase_order_id=po.id AND requisition_item_id IS NOT NULL) THEN
            RAISE EXCEPTION 'PO_EXEC_MR_HEADER_REQUIRED' USING ERRCODE='23514';
        END IF;
    END IF;
    content:=epcc_po_execution_content(to_jsonb(po));
    IF NEW.content_hash IS DISTINCT FROM epcc_po_execution_digest(content) THEN RAISE EXCEPTION 'PO_EXEC_STALE_CONTENT' USING ERRCODE='23514'; END IF;
    IF NEW.purpose='issue' THEN
        SELECT * INTO approval FROM po_execution_decisions WHERE purchase_order_id=po.id AND purpose='finance' ORDER BY sequence DESC LIMIT 1;
        IF NOT FOUND OR approval.id IS DISTINCT FROM NEW.approval_id OR approval.content_hash IS DISTINCT FROM NEW.content_hash
            OR approval.source_hash IS DISTINCT FROM NEW.source_hash
            OR approval.policy_id IS DISTINCT FROM NEW.policy_id OR approval.policy_id IS DISTINCT FROM
            epcc_po_execution_live_policy(po.project_id,approval.actor_id,approval.actor_company_id,po.company_id,'finance',po.discipline) THEN
            RAISE EXCEPTION 'PO_EXEC_APPROVAL_STALE' USING ERRCODE='23514';
        END IF;
        IF approval.actor_id=NEW.actor_id OR approval.actor_id=po.drafted_by THEN RAISE EXCEPTION 'PO_EXEC_SEPARATION' USING ERRCODE='42501'; END IF;
    ELSIF NEW.purpose<>'finance' THEN RAISE EXCEPTION 'PO_EXEC_PURPOSE' USING ERRCODE='23514'; END IF;
    NEW.snapshot:=content;
    SELECT COALESCE(max(sequence),0)+1 INTO NEW.sequence FROM po_execution_decisions WHERE purchase_order_id=po.id;
    PERFORM set_config('epcc.company_id',NEW.actor_company_id::text,true);
    SELECT name INTO NEW.actor_name FROM users WHERE id=NEW.actor_id;
    PERFORM set_config('epcc.company_id',po.company_id::text,true);
    NEW.created_at:=CURRENT_TIMESTAMP;
    RETURN NEW;
END $$;

CREATE OR REPLACE FUNCTION epcc_guard_po_mr_allocation() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE e po_execution_decisions%ROWTYPE; po purchase_orders%ROWTYPE; l purchase_order_items%ROWTYPE; m material_requisition_items%ROWTYPE;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
 SELECT * INTO e FROM po_execution_decisions WHERE id=NEW.issue_decision_id AND purpose='issue';
 IF NOT FOUND THEN RAISE EXCEPTION 'PO_EXEC_MR_ISSUE_REQUIRED' USING ERRCODE='23514'; END IF;
 SELECT * INTO po FROM purchase_orders WHERE id=e.purchase_order_id AND state='DRAFT' FOR UPDATE;
 IF NOT FOUND THEN RAISE EXCEPTION 'PO_EXEC_MR_ISSUE_REQUIRED' USING ERRCODE='23514'; END IF;
 SELECT * INTO l FROM purchase_order_items WHERE id=NEW.purchase_order_item_id AND purchase_order_id=po.id;
 IF NOT FOUND OR EXISTS(SELECT 1 FROM purchase_order_items WHERE supersedes_id=l.id) THEN RAISE EXCEPTION 'PO_EXEC_MR_ALLOCATION_LINE' USING ERRCODE='23514'; END IF;
 SELECT * INTO m FROM material_requisition_items WHERE id=l.requisition_item_id AND requisition_id=po.requisition_id FOR UPDATE;
 IF NOT FOUND OR (NEW.project_id,NEW.buyer_company_id,NEW.purchase_order_id,NEW.requisition_id,NEW.requisition_item_id,NEW.quantity,NEW.approved_quantity,NEW.unit,NEW.source_hash)
  IS DISTINCT FROM (po.project_id,po.company_id,po.id,po.requisition_id,m.id,l.quantity,m.quantity,m.unit,e.source_hash)
  OR e.project_id<>po.project_id OR e.buyer_company_id<>po.company_id OR e.source_hash IS NULL THEN
  RAISE EXCEPTION 'PO_EXEC_MR_ALLOCATION_LINE' USING ERRCODE='23514'; END IF;
 IF NEW.quantity+COALESCE((SELECT sum(quantity) FROM po_mr_allocations WHERE requisition_item_id=m.id),0)>m.quantity THEN
  RAISE EXCEPTION 'PO_EXEC_MR_QUANTITY_EXCEEDED' USING ERRCODE='23514'; END IF;
 NEW.created_at:=e.created_at;
 RETURN NEW;
END $$;
CREATE TRIGGER po_mr_allocation_guard BEFORE INSERT ON po_mr_allocations FOR EACH ROW EXECUTE FUNCTION epcc_guard_po_mr_allocation();

CREATE OR REPLACE FUNCTION epcc_apply_po_mr_allocations() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.purpose='issue' AND NEW.source_hash IS NOT NULL THEN
  INSERT INTO po_mr_allocations(id,project_id,buyer_company_id,purchase_order_id,purchase_order_item_id,requisition_id,requisition_item_id,issue_decision_id,quantity,approved_quantity,unit,source_hash,created_at)
  SELECT gen_random_uuid(),NEW.project_id,NEW.buyer_company_id,p.id,l.id,p.requisition_id,m.id,NEW.id,l.quantity,m.quantity,m.unit,NEW.source_hash,NEW.created_at
   FROM purchase_orders p JOIN purchase_order_items l ON l.purchase_order_id=p.id JOIN material_requisition_items m ON m.id=l.requisition_item_id AND m.requisition_id=p.requisition_id
   WHERE p.id=NEW.purchase_order_id AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id) ORDER BY m.id;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER po_issue_mr_allocations AFTER INSERT ON po_execution_decisions FOR EACH ROW EXECUTE FUNCTION epcc_apply_po_mr_allocations();

-- Keep legacy primitives compatible for unenrolled sources, but never let them
-- bypass commitments already enrolled in this controlled allocation ledger.
CREATE OR REPLACE FUNCTION epcc_guard_po_allocated_source_header() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.state IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') THEN
  PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
  IF (EXISTS(SELECT 1 FROM po_mr_allocations a WHERE a.requisition_id=NEW.requisition_id)
   OR EXISTS(SELECT 1 FROM purchase_order_items l JOIN po_mr_allocations a ON a.requisition_item_id=l.requisition_item_id WHERE l.purchase_order_id=NEW.id))
   AND NOT EXISTS(SELECT 1 FROM po_execution_decisions e WHERE e.purchase_order_id=NEW.id AND e.purpose='issue' AND e.source_hash IS NOT NULL) THEN
   RAISE EXCEPTION 'PO_EXEC_MR_CONTROLLED_SOURCE' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER zy_po_allocated_source_header BEFORE INSERT OR UPDATE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION epcc_guard_po_allocated_source_header();
CREATE OR REPLACE FUNCTION epcc_guard_po_allocated_source_line() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF NEW.requisition_item_id IS NOT NULL THEN
  PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
  IF EXISTS(SELECT 1 FROM po_mr_allocations WHERE requisition_item_id=NEW.requisition_item_id)
   AND EXISTS(SELECT 1 FROM purchase_orders p WHERE p.id=NEW.purchase_order_id AND p.state IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED'))
   AND NOT EXISTS(SELECT 1 FROM po_execution_decisions e WHERE e.purchase_order_id=NEW.purchase_order_id AND e.purpose='issue' AND e.source_hash IS NOT NULL) THEN
   RAISE EXCEPTION 'PO_EXEC_MR_CONTROLLED_SOURCE' USING ERRCODE='23514';
  END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER po_allocated_source_line BEFORE INSERT OR UPDATE ON purchase_order_items FOR EACH ROW EXECUTE FUNCTION epcc_guard_po_allocated_source_line();

-- Deferred checks run after middleware has restored the initiating tenant/project.
-- Rebind only the immutable event scope, validate, and restore on success/error.
CREATE OR REPLACE FUNCTION epcc_require_po_issue_applied() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE prior_company text; prior_project text;
BEGIN
 prior_company:=current_setting('epcc.company_id',true); prior_project:=current_setting('epcc.project_id',true);
 PERFORM set_config('epcc.company_id',NEW.buyer_company_id::text,true);
 PERFORM set_config('epcc.project_id',NEW.project_id::text,true);
 IF NEW.purpose='issue' AND NOT EXISTS(SELECT 1 FROM purchase_orders p WHERE p.id=NEW.purchase_order_id AND p.project_id=NEW.project_id
  AND p.company_id=NEW.buyer_company_id AND p.state='ISSUED' AND p.issued_by=NEW.actor_id AND p.issued_at=NEW.created_at) THEN
  RAISE EXCEPTION 'PO_EXEC_ISSUE_NOT_APPLIED' USING ERRCODE='23514';
 END IF;
 PERFORM set_config('epcc.company_id',COALESCE(prior_company,''),true);
 PERFORM set_config('epcc.project_id',COALESCE(prior_project,''),true);
 RETURN NEW;
EXCEPTION WHEN OTHERS THEN
 PERFORM set_config('epcc.company_id',COALESCE(prior_company,''),true);
 PERFORM set_config('epcc.project_id',COALESCE(prior_project,''),true);
 RAISE;
END $$;

CREATE OR REPLACE FUNCTION epcc_require_po_mr_allocated() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE po purchase_orders%ROWTYPE; prior_company text; prior_project text;
BEGIN
 prior_company:=current_setting('epcc.company_id',true); prior_project:=current_setting('epcc.project_id',true);
 PERFORM set_config('epcc.company_id',NEW.buyer_company_id::text,true);
 PERFORM set_config('epcc.project_id',NEW.project_id::text,true);
 IF NEW.purpose='issue' THEN
  SELECT * INTO po FROM purchase_orders WHERE id=NEW.purchase_order_id;
  IF po.requisition_id IS NOT NULL AND (NEW.source_hash IS NULL OR NEW.source_hash IS DISTINCT FROM epcc_po_mr_source_hash(po.requisition_id)
   OR (SELECT count(*) FROM po_mr_allocations WHERE issue_decision_id=NEW.id)<>(SELECT count(*) FROM purchase_order_items l WHERE l.purchase_order_id=po.id AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id))
   OR EXISTS(SELECT 1 FROM purchase_order_items l WHERE l.purchase_order_id=po.id AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id)
    AND NOT EXISTS(SELECT 1 FROM po_mr_allocations a WHERE a.issue_decision_id=NEW.id AND a.purchase_order_item_id=l.id AND a.requisition_item_id=l.requisition_item_id AND a.quantity=l.quantity))) THEN
   RAISE EXCEPTION 'PO_EXEC_MR_ALLOCATION_INCOMPLETE' USING ERRCODE='23514';
  END IF;
 END IF;
 PERFORM set_config('epcc.company_id',COALESCE(prior_company,''),true);
 PERFORM set_config('epcc.project_id',COALESCE(prior_project,''),true);
 RETURN NEW;
EXCEPTION WHEN OTHERS THEN
 PERFORM set_config('epcc.company_id',COALESCE(prior_company,''),true);
 PERFORM set_config('epcc.project_id',COALESCE(prior_project,''),true);
 RAISE;
END $$;
CREATE CONSTRAINT TRIGGER po_issue_mr_allocated AFTER INSERT ON po_execution_decisions DEFERRABLE INITIALLY DEFERRED
 FOR EACH ROW EXECUTE FUNCTION epcc_require_po_mr_allocated();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retain source allocation and issue evidence.');
    }
};
