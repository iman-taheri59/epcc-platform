<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** ADR-034. No historical inference, renumbering, grants or MTO admission. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['material_requisitions', 'purchase_orders'] as $table) {
            Schema::table($table, function (Blueprint $t): void {
                $t->string('material_category', 32)->nullable();
                $t->index(['project_id', 'material_category']);
            });
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_category_valid CHECK
                (material_category IN ('PIPE','FITTING','FLANGE','VALVE','GASKET','BOLT_NUT','SPECIALTY','SUPPORT'))");
        }
        Schema::table('material_requisition_items', function (Blueprint $t): void {
            $t->foreignUuid('material_item_id')->nullable()->constrained('material_items')->restrictOnDelete();
        });
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_procurement_category_header() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source_project uuid; source_category text; bad boolean;
BEGIN
    IF TG_OP='UPDATE' AND OLD.material_category IS NOT NULL AND NEW.material_category IS DISTINCT FROM OLD.material_category THEN
        RAISE EXCEPTION 'PROCUREMENT_CATEGORY_IMMUTABLE' USING ERRCODE='23514';
    END IF;
    -- Existing policy-attributed rows remain unclassified. Require category when NEW attribution is assigned.
    IF NEW.author_policy_id IS NOT NULL AND NEW.material_category IS NULL THEN
        IF TG_OP='INSERT' THEN
            RAISE EXCEPTION 'PROCUREMENT_CATEGORY_REQUIRED' USING ERRCODE='23514';
        ELSIF OLD.author_policy_id IS NULL THEN
            RAISE EXCEPTION 'PROCUREMENT_CATEGORY_REQUIRED' USING ERRCODE='23514';
        END IF;
    END IF;
    IF TG_TABLE_NAME='purchase_orders' THEN
      IF NEW.requisition_id IS NOT NULL THEN
        SELECT project_id, material_category INTO source_project, source_category
            FROM material_requisitions WHERE id=NEW.requisition_id;
        IF NEW.material_category IS NOT NULL OR source_category IS NOT NULL THEN
            IF source_project IS DISTINCT FROM NEW.project_id OR source_category IS DISTINCT FROM NEW.material_category THEN
                RAISE EXCEPTION 'MR_PO_CATEGORY_MISMATCH' USING ERRCODE='23514';
            END IF;
        END IF;
    END IF;
      END IF;
    IF NEW.material_category IS NOT NULL AND (TG_OP='INSERT' OR OLD.material_category IS NULL) THEN
        IF TG_TABLE_NAME='material_requisitions' THEN
            SELECT EXISTS (SELECT 1 FROM material_requisition_items l LEFT JOIN material_items m ON m.id=l.material_item_id
                WHERE l.requisition_id=NEW.id AND l.material_item_id IS NOT NULL
                AND (m.id IS NULL OR m.category<>NEW.material_category OR (m.project_id IS NOT NULL AND m.project_id<>NEW.project_id))) INTO bad;
        ELSE
            SELECT EXISTS (SELECT 1 FROM purchase_order_items l LEFT JOIN material_items m ON m.id=l.material_item_id
                WHERE l.purchase_order_id=NEW.id AND l.material_item_id IS NOT NULL
                AND (m.id IS NULL OR m.category<>NEW.material_category OR (m.project_id IS NOT NULL AND m.project_id<>NEW.project_id))) INTO bad;
        END IF;
        IF bad THEN RAISE EXCEPTION 'PROCUREMENT_LINE_CATEGORY_MISMATCH' USING ERRCODE='23514'; END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER zz_mr_category_guard BEFORE INSERT OR UPDATE ON material_requisitions
FOR EACH ROW EXECUTE FUNCTION epcc_procurement_category_header();
CREATE TRIGGER zz_po_category_guard BEFORE INSERT OR UPDATE ON purchase_orders
FOR EACH ROW EXECUTE FUNCTION epcc_procurement_category_header();

CREATE OR REPLACE FUNCTION epcc_procurement_category_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE category text; parent_project uuid; material record;
BEGIN
    IF TG_TABLE_NAME='material_requisition_items' THEN
        SELECT material_category, project_id INTO category, parent_project FROM material_requisitions WHERE id=NEW.requisition_id;
    ELSE
        SELECT material_category, project_id INTO category, parent_project FROM purchase_orders WHERE id=NEW.purchase_order_id;
    END IF;
    -- Unclassified legacy documents retain their existing attribution semantics.
    IF category IS NOT NULL THEN
        IF parent_project IS DISTINCT FROM NEW.project_id THEN
            RAISE EXCEPTION 'PROCUREMENT_LINE_PROJECT_MISMATCH' USING ERRCODE='23514';
        END IF;
        IF NEW.material_item_id IS NOT NULL THEN
            SELECT * INTO material FROM material_items WHERE id=NEW.material_item_id FOR SHARE;
            IF NOT FOUND OR material.category<>category
                OR (material.project_id IS NOT NULL AND material.project_id<>NEW.project_id) THEN
                RAISE EXCEPTION 'PROCUREMENT_LINE_CATEGORY_MISMATCH' USING ERRCODE='23514';
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER mr_line_category_guard BEFORE INSERT OR UPDATE OF material_item_id,project_id,requisition_id
ON material_requisition_items FOR EACH ROW EXECUTE FUNCTION epcc_procurement_category_line();
CREATE TRIGGER po_line_category_guard BEFORE INSERT OR UPDATE OF material_item_id,project_id,purchase_order_id
ON purchase_order_items FOR EACH ROW EXECUTE FUNCTION epcc_procurement_category_line();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retain material category and attribution history.');
    }
};
