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
        Schema::create('po_execution_decisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->foreignUuid('policy_id')->constrained('po_execution_policies')->restrictOnDelete();
            $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('actor_company_id')->constrained('companies')->restrictOnDelete();
            $t->string('actor_name');
            $t->string('purpose', 16);
            $t->integer('sequence')->default(0);
            $t->uuid('approval_id')->nullable();
            $t->string('content_hash', 64);
            $t->jsonb('snapshot');
            $t->text('reason');
            $t->timestampTz('created_at');
            $t->unique(['purchase_order_id', 'sequence'], 'po_execution_decision_sequence_unique');
        });
        Schema::table('po_execution_decisions', fn (Blueprint $t) => $t->foreign('approval_id')->references('id')->on('po_execution_decisions')->restrictOnDelete());
        DB::statement("ALTER TABLE po_execution_decisions ADD CONSTRAINT po_execution_decision_shape CHECK
            (purpose IN ('finance','issue') AND length(btrim(reason))>0 AND length(reason)<=4000 AND content_hash ~ '^[a-f0-9]{64}$'
            AND ((purpose='finance' AND approval_id IS NULL) OR (purpose='issue' AND approval_id IS NOT NULL)))");
        DB::statement("CREATE UNIQUE INDEX po_execution_once ON po_execution_decisions(purchase_order_id) WHERE purpose='issue'");
        DB::statement('ALTER TABLE po_execution_decisions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE po_execution_decisions FORCE ROW LEVEL SECURITY');
        $scope = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid
            AND buyer_company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid
            AND EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=po_execution_decisions.project_id AND pc.company_id=po_execution_decisions.buyer_company_id)";
        DB::statement("CREATE POLICY po_execution_decision_read ON po_execution_decisions FOR SELECT USING ({$scope})");
        DB::statement("CREATE POLICY po_execution_decision_insert ON po_execution_decisions FOR INSERT WITH CHECK ({$scope} AND epcc_project_is_writable(project_id))");
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_po_execution_content(header jsonb) RETURNS jsonb LANGUAGE sql STABLE AS $$
SELECT jsonb_build_object('schema',1,'header',header-ARRAY['state','created_at','updated_at','forecast_date','issued_by','issued_at'],
    'lines',COALESCE((SELECT jsonb_agg(to_jsonb(i)-ARRAY['created_at','updated_at'] ORDER BY i.line_no,i.id)
        FROM purchase_order_items i WHERE i.purchase_order_id=(header->>'id')::uuid AND i.project_id=(header->>'project_id')::uuid),'[]'::jsonb))
$$;
CREATE OR REPLACE FUNCTION epcc_po_execution_digest(content jsonb) RETURNS text LANGUAGE sql IMMUTABLE AS $$
SELECT encode(sha256(convert_to(content::text,'UTF8')),'hex')
$$;
-- Narrow metadata lookup. Never delegates PO reads; restores the caller's buyer GUC even on exception.
CREATE OR REPLACE FUNCTION epcc_po_execution_live_policy(pid uuid,aid uuid,cid uuid,bid uuid,purpose text,disc text)
RETURNS uuid LANGUAGE plpgsql AS $$
DECLARE prior text; result uuid;
BEGIN
    prior:=current_setting('epcc.company_id',true);
    IF bid IS DISTINCT FROM NULLIF(prior,'')::uuid OR pid IS DISTINCT FROM NULLIF(current_setting('epcc.project_id',true),'')::uuid THEN RETURN NULL; END IF;
    PERFORM set_config('epcc.company_id',cid::text,true);
    IF NOT EXISTS (SELECT 1 FROM users u JOIN companies c ON c.id=u.company_id JOIN plans pl ON pl.id=c.plan_id JOIN projects p ON p.id=pid
        WHERE u.id=aid AND u.company_id=cid AND u.is_active AND (jsonb_exists(pl.modules,'proc') OR jsonb_exists(pl.modules,'*'))
        AND (jsonb_exists(pl.standards_packs,p.standards_pack) OR jsonb_exists(pl.standards_packs,'*'))) THEN PERFORM set_config('epcc.company_id',COALESCE(prior,''),true); RETURN NULL; END IF;
    IF NOT EXISTS (SELECT 1 FROM companies c JOIN plans pl ON pl.id=c.plan_id JOIN projects p ON p.id=pid WHERE c.id=bid
        AND (jsonb_exists(pl.modules,'proc') OR jsonb_exists(pl.modules,'*'))
        AND (jsonb_exists(pl.standards_packs,p.standards_pack) OR jsonb_exists(pl.standards_packs,'*'))) THEN PERFORM set_config('epcc.company_id',COALESCE(prior,''),true); RETURN NULL; END IF;
    result:=epcc_po_execution_nomination(pid,aid,cid,bid,purpose,disc);
    PERFORM set_config('epcc.company_id',COALESCE(prior,''),true);
    RETURN result;
EXCEPTION WHEN OTHERS THEN
    PERFORM set_config('epcc.company_id',COALESCE(prior,''),true); RAISE;
END $$;

CREATE OR REPLACE FUNCTION epcc_guard_po_execution_decision() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE po purchase_orders%ROWTYPE; approval po_execution_decisions%ROWTYPE; content jsonb; expected_policy uuid; n integer; total numeric;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
    PERFORM 1 FROM projects WHERE id=NEW.project_id FOR UPDATE;
    SELECT * INTO po FROM purchase_orders WHERE id=NEW.purchase_order_id AND project_id=NEW.project_id AND company_id=NEW.buyer_company_id FOR UPDATE;
    IF NOT FOUND OR po.state<>'DRAFT' OR po.author_policy_id IS NULL OR po.drafted_by IS NULL OR po.material_category IS NULL
        OR po.requisition_id IS NOT NULL OR NOT epcc_project_is_writable(NEW.project_id) THEN
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
    content:=epcc_po_execution_content(to_jsonb(po));
    IF NEW.content_hash IS DISTINCT FROM epcc_po_execution_digest(content) THEN RAISE EXCEPTION 'PO_EXEC_STALE_CONTENT' USING ERRCODE='23514'; END IF;
    IF NEW.purpose='issue' THEN
        SELECT * INTO approval FROM po_execution_decisions WHERE purchase_order_id=po.id AND purpose='finance' ORDER BY sequence DESC LIMIT 1;
        IF NOT FOUND OR approval.id IS DISTINCT FROM NEW.approval_id OR approval.content_hash IS DISTINCT FROM NEW.content_hash
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
CREATE TRIGGER po_execution_decision_guard BEFORE INSERT ON po_execution_decisions FOR EACH ROW EXECUTE FUNCTION epcc_guard_po_execution_decision();

CREATE OR REPLACE FUNCTION epcc_guard_po_execution_header() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE issued po_execution_decisions%ROWTYPE;
BEGIN
    IF TG_OP='INSERT' THEN
        IF NEW.author_policy_id IS NOT NULL AND NEW.state<>'DRAFT' THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUE_DECISION_REQUIRED' USING ERRCODE='23514';
        END IF;
        RETURN NEW;
    END IF;
    PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
    SELECT * INTO issued FROM po_execution_decisions WHERE purchase_order_id=NEW.id AND purpose='issue';
    IF FOUND THEN
        IF epcc_po_execution_digest(epcc_po_execution_content(to_jsonb(NEW))) IS DISTINCT FROM issued.content_hash THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUED_CONTENT_FROZEN' USING ERRCODE='23514';
        END IF;
        IF NEW.state NOT IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') THEN
            RAISE EXCEPTION 'PO_EXEC_CANCELLATION_PENDING' USING ERRCODE='23514';
        END IF;
        IF OLD.state='DRAFT' AND NEW.state='ISSUED' THEN NEW.issued_by:=issued.actor_id; NEW.issued_at:=issued.created_at;
        ELSIF (NEW.issued_by,NEW.issued_at) IS DISTINCT FROM (OLD.issued_by,OLD.issued_at) OR NEW.state='DRAFT' THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUE_BASIS_IMMUTABLE' USING ERRCODE='23514';
        END IF;
    ELSIF NEW.author_policy_id IS NOT NULL AND NEW.state IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') AND (OLD.state NOT IN ('ISSUED','PARTIALLY_RECEIVED','RECEIVED') OR OLD.author_policy_id IS NULL) THEN
        RAISE EXCEPTION 'PO_EXEC_ISSUE_DECISION_REQUIRED' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER zz_po_execution_header_guard BEFORE INSERT OR UPDATE ON purchase_orders FOR EACH ROW EXECUTE FUNCTION epcc_guard_po_execution_header();

CREATE OR REPLACE FUNCTION epcc_guard_po_execution_lines() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE oid uuid; pid uuid;
BEGIN
    IF TG_OP<>'INSERT' THEN
        SELECT p.project_id INTO pid FROM purchase_orders p WHERE p.id=OLD.purchase_order_id;
        PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||pid::text,0));
        IF EXISTS(SELECT 1 FROM po_execution_decisions WHERE purchase_order_id=OLD.purchase_order_id AND purpose='issue') THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUED_CONTENT_FROZEN' USING ERRCODE='23514';
        END IF;
    END IF;
    IF TG_OP<>'DELETE' THEN
        SELECT p.project_id INTO pid FROM purchase_orders p WHERE p.id=NEW.purchase_order_id;
        PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||pid::text,0));
        IF EXISTS(SELECT 1 FROM po_execution_decisions WHERE purchase_order_id=NEW.purchase_order_id AND purpose='issue') THEN
            RAISE EXCEPTION 'PO_EXEC_ISSUED_CONTENT_FROZEN' USING ERRCODE='23514';
        END IF;
        RETURN NEW;
    END IF;
    RETURN OLD;
END $$;
CREATE TRIGGER po_execution_lines_guard BEFORE INSERT OR UPDATE OR DELETE ON purchase_order_items FOR EACH ROW EXECUTE FUNCTION epcc_guard_po_execution_lines();

CREATE OR REPLACE FUNCTION epcc_require_po_issue_applied() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF NEW.purpose='issue' AND NOT EXISTS(SELECT 1 FROM purchase_orders p WHERE p.id=NEW.purchase_order_id AND p.project_id=NEW.project_id
        AND p.company_id=NEW.buyer_company_id AND p.state='ISSUED' AND p.issued_by=NEW.actor_id AND p.issued_at=NEW.created_at) THEN
        RAISE EXCEPTION 'PO_EXEC_ISSUE_NOT_APPLIED' USING ERRCODE='23514';
    END IF;
    RETURN NEW;
END $$;
CREATE CONSTRAINT TRIGGER po_issue_applied AFTER INSERT ON po_execution_decisions DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION epcc_require_po_issue_applied();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retain signed financial/issue evidence.');
    }
};
