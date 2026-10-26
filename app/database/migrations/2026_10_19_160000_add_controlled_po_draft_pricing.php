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
        Schema::create('po_pricing_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->restrictOnDelete();
            $t->foreignUuid('buyer_company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('purchase_order_id')->constrained('purchase_orders')->restrictOnDelete();
            $t->foreignUuid('actor_id')->constrained('users')->restrictOnDelete();
            $t->foreignUuid('policy_id')->constrained('po_author_policies')->restrictOnDelete();
            $t->string('actor_name');
            $t->integer('sequence')->default(0);
            $t->string('before_hash', 64);
            $t->string('content_hash', 64);
            $t->jsonb('before_snapshot');
            $t->jsonb('snapshot');
            $t->text('reason');
            $t->timestampTz('created_at');
            $t->unique(['purchase_order_id', 'sequence']);
        });
        DB::statement("ALTER TABLE po_pricing_revisions ADD CONSTRAINT po_pricing_shape CHECK (length(btrim(reason))>0 AND length(reason)<=4000 AND before_hash ~ '^[a-f0-9]{64}$' AND content_hash ~ '^[a-f0-9]{64}$' AND before_hash<>content_hash)");
        DB::statement('ALTER TABLE po_pricing_revisions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE po_pricing_revisions FORCE ROW LEVEL SECURITY');
        $scope = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid AND buyer_company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid AND EXISTS(SELECT 1 FROM project_company pc WHERE pc.project_id=po_pricing_revisions.project_id AND pc.company_id=po_pricing_revisions.buyer_company_id)";
        DB::statement("CREATE POLICY po_pricing_read ON po_pricing_revisions FOR SELECT USING ({$scope})");
        DB::statement("CREATE POLICY po_pricing_insert ON po_pricing_revisions FOR INSERT WITH CHECK ({$scope} AND epcc_project_is_writable(project_id))");
        DB::unprepared(<<<'SQL'
-- Source validation only: no MR mutation, allocation or delegated human read endpoint.
CREATE OR REPLACE FUNCTION epcc_po_pricing_source_valid(oid uuid,pid uuid,bid uuid) RETURNS boolean LANGUAGE sql STABLE AS $$
SELECT EXISTS(SELECT 1 FROM purchase_orders p JOIN material_requisitions r ON r.id=p.requisition_id
 WHERE p.id=oid AND p.project_id=pid AND p.company_id=bid AND r.project_id=pid AND r.company_id=bid AND r.state='APPROVED'
 AND p.material_category IS NOT NULL AND p.material_category=r.material_category AND p.discipline=r.discipline
 AND p.title_en=r.title_en AND p.title_fa=r.title_fa
 AND (SELECT count(*) FROM material_requisition_items WHERE requisition_id=r.id) BETWEEN 1 AND 20
 AND (SELECT count(*) FROM purchase_order_items l WHERE l.purchase_order_id=p.id AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id))=(SELECT count(*) FROM material_requisition_items WHERE requisition_id=r.id)
 AND NOT EXISTS(SELECT 1 FROM purchase_order_items l LEFT JOIN material_requisition_items m ON m.id=l.requisition_item_id
  WHERE l.purchase_order_id=p.id AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id)
  AND (m.id IS NULL OR m.requisition_id<>r.id OR l.project_id<>pid OR l.quantity<=0
    OR (l.item_code,l.description_en,l.description_fa,l.unit,l.quantity,l.subsystem_id,l.material_item_id)
       IS DISTINCT FROM (m.item_code,m.description_en,m.description_fa,m.unit,m.quantity,m.subsystem_id,m.material_item_id)))
 AND NOT EXISTS(SELECT 1 FROM material_requisition_items m WHERE m.requisition_id=r.id AND
    (SELECT count(*) FROM purchase_order_items l WHERE l.purchase_order_id=p.id AND l.requisition_item_id=m.id AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id))<>1))
$$;
CREATE OR REPLACE FUNCTION epcc_guard_po_pricing_revision() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE po purchase_orders%ROWTYPE; content jsonb; total numeric; expected uuid;
BEGIN
 PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
 PERFORM 1 FROM projects WHERE id=NEW.project_id FOR UPDATE;
 SELECT * INTO po FROM purchase_orders WHERE id=NEW.purchase_order_id AND project_id=NEW.project_id AND company_id=NEW.buyer_company_id FOR UPDATE;
 IF NOT FOUND OR po.state<>'DRAFT' OR po.author_policy_id IS NULL OR po.drafted_by IS DISTINCT FROM NEW.actor_id OR NOT epcc_project_is_writable(po.project_id) THEN
  RAISE EXCEPTION 'PO_PRICING_NOT_ELIGIBLE' USING ERRCODE='23514';
 END IF;
 expected:=epcc_po_author_policy(po.project_id,NEW.actor_id,po.company_id,po.discipline);
 IF expected IS NULL OR expected IS DISTINCT FROM NEW.policy_id OR NOT EXISTS(
  SELECT 1 FROM users u JOIN companies c ON c.id=u.company_id JOIN plans pl ON pl.id=c.plan_id JOIN projects p ON p.id=po.project_id
  WHERE u.id=NEW.actor_id AND u.company_id=po.company_id AND u.is_active AND (jsonb_exists(pl.modules,'proc') OR jsonb_exists(pl.modules,'*')) AND (jsonb_exists(pl.standards_packs,p.standards_pack) OR jsonb_exists(pl.standards_packs,'*')))
 THEN RAISE EXCEPTION 'PO_PRICING_AUTHORITY' USING ERRCODE='42501'; END IF;
 IF NOT epcc_po_pricing_source_valid(po.id,po.project_id,po.company_id) THEN RAISE EXCEPTION 'PO_PRICING_SOURCE' USING ERRCODE='23514'; END IF;
 SELECT round(sum(l.quantity*l.unit_price),2) INTO total FROM purchase_order_items l WHERE l.purchase_order_id=po.id AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id);
 IF po.currency IS NULL OR po.currency !~ '^[A-Z]{3,8}$' OR po.total_value IS NULL OR total IS DISTINCT FROM po.total_value OR EXISTS(
  SELECT 1 FROM purchase_order_items l WHERE l.purchase_order_id=po.id AND NOT EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id) AND (l.unit_price IS NULL OR l.unit_price<0))
 THEN RAISE EXCEPTION 'PO_PRICING_TOTAL' USING ERRCODE='23514'; END IF;
 content:=epcc_po_execution_content(to_jsonb(po));
 IF NEW.content_hash IS DISTINCT FROM epcc_po_execution_digest(content) OR NEW.before_hash IS DISTINCT FROM epcc_po_execution_digest(NEW.before_snapshot) THEN
  RAISE EXCEPTION 'PO_PRICING_HASH' USING ERRCODE='23514'; END IF;
 IF NEW.before_snapshot->'schema' IS DISTINCT FROM content->'schema'
 OR ((NEW.before_snapshot->'header')-ARRAY['currency','total_value']) IS DISTINCT FROM ((content->'header')-ARRAY['currency','total_value'])
 OR (SELECT jsonb_agg(value-'unit_price' ORDER BY ord) FROM jsonb_array_elements(NEW.before_snapshot->'lines') WITH ORDINALITY AS x(value,ord))
 IS DISTINCT FROM (SELECT jsonb_agg(value-'unit_price' ORDER BY ord) FROM jsonb_array_elements(content->'lines') WITH ORDINALITY AS x(value,ord)) THEN
  RAISE EXCEPTION 'PO_PRICING_NONPRICE_CHANGE' USING ERRCODE='23514'; END IF;
 IF EXISTS(SELECT 1 FROM jsonb_array_elements(NEW.before_snapshot->'lines') b
   JOIN purchase_order_items l ON l.id=(b->>'id')::uuid AND l.purchase_order_id=po.id
   WHERE EXISTS(SELECT 1 FROM purchase_order_items s WHERE s.supersedes_id=l.id)
   AND b->'unit_price' IS DISTINCT FROM to_jsonb(l)->'unit_price') THEN
  RAISE EXCEPTION 'PO_PRICING_HISTORY_CHANGE' USING ERRCODE='23514'; END IF;
 NEW.snapshot:=content;
 SELECT COALESCE(max(sequence),0)+1 INTO NEW.sequence FROM po_pricing_revisions WHERE purchase_order_id=po.id;
 SELECT name INTO NEW.actor_name FROM users WHERE id=NEW.actor_id;
 NEW.created_at:=CURRENT_TIMESTAMP;
 RETURN NEW;
END $$;
CREATE TRIGGER po_pricing_revision_guard BEFORE INSERT ON po_pricing_revisions FOR EACH ROW EXECUTE FUNCTION epcc_guard_po_pricing_revision();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retain PO pricing evidence.');
    }
};
