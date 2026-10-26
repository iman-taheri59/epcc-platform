<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Exact draft-copy provenance, not quantity reservation, MR consumption or approval. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION epcc_guard_po_draft_source() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE source material_requisitions%ROWTYPE; n integer;
BEGIN
    IF TG_OP='UPDATE' AND OLD.author_policy_id IS NOT NULL AND OLD.requisition_id IS NOT NULL
        AND NEW.requisition_id IS DISTINCT FROM OLD.requisition_id THEN
        RAISE EXCEPTION 'PO_DRAFT_SOURCE_IMMUTABLE' USING ERRCODE='23514';
    END IF;
    IF NEW.author_policy_id IS NOT NULL AND NEW.requisition_id IS NOT NULL THEN
        IF TG_OP='UPDATE' THEN
            IF OLD.requisition_id IS NOT DISTINCT FROM NEW.requisition_id AND OLD.author_policy_id IS NOT NULL THEN RETURN NEW; END IF;
        END IF;
        SELECT * INTO source FROM material_requisitions WHERE id=NEW.requisition_id FOR UPDATE;
        IF NOT FOUND OR source.project_id<>NEW.project_id OR source.company_id<>NEW.company_id
            OR source.state<>'APPROVED' OR source.material_category IS NULL
            OR source.material_category IS DISTINCT FROM NEW.material_category OR source.discipline IS DISTINCT FROM NEW.discipline THEN
            RAISE EXCEPTION 'PO_DRAFT_SOURCE_NOT_ELIGIBLE' USING ERRCODE='23514';
        END IF;
        SELECT count(*) INTO n FROM material_requisition_items WHERE requisition_id=source.id;
        IF n<1 OR n>20 OR n<>(SELECT count(*) FROM purchase_order_items WHERE purchase_order_id=NEW.id)
            OR EXISTS (SELECT 1 FROM material_requisition_items s WHERE s.requisition_id=source.id
                AND NOT EXISTS (SELECT 1 FROM purchase_order_items p WHERE p.purchase_order_id=NEW.id AND p.requisition_item_id=s.id))
            OR EXISTS (SELECT 1 FROM purchase_order_items p LEFT JOIN material_requisition_items s ON s.id=p.requisition_item_id
                WHERE p.purchase_order_id=NEW.id AND (s.id IS NULL OR s.requisition_id<>source.id
                OR (p.item_code,p.description_en,p.description_fa,p.unit,p.quantity,p.subsystem_id,p.material_item_id,p.source_quantity)
                IS DISTINCT FROM (s.item_code,s.description_en,s.description_fa,s.unit,s.quantity,s.subsystem_id,s.material_item_id,COALESCE(s.source_quantity,s.quantity::text)))) THEN
            RAISE EXCEPTION 'PO_DRAFT_SOURCE_COPY_MISMATCH' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER zz_po_draft_source_guard BEFORE INSERT OR UPDATE ON purchase_orders
FOR EACH ROW EXECUTE FUNCTION epcc_guard_po_draft_source();

CREATE OR REPLACE FUNCTION epcc_guard_po_draft_source_line() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE parent purchase_orders%ROWTYPE; predecessor purchase_order_items%ROWTYPE;
BEGIN
    IF TG_OP='UPDATE' THEN
        SELECT * INTO parent FROM purchase_orders WHERE id=OLD.purchase_order_id;
        IF parent.author_policy_id IS NOT NULL AND parent.requisition_id IS NOT NULL
            AND (OLD.requisition_item_id IS DISTINCT FROM NEW.requisition_item_id OR OLD.purchase_order_id IS DISTINCT FROM NEW.purchase_order_id
                OR OLD.project_id IS DISTINCT FROM NEW.project_id) THEN
            RAISE EXCEPTION 'PO_DRAFT_SOURCE_LINE_IMMUTABLE' USING ERRCODE='23514';
        END IF;
    END IF;
    SELECT * INTO parent FROM purchase_orders WHERE id=NEW.purchase_order_id;
    IF parent.author_policy_id IS NOT NULL AND parent.requisition_id IS NOT NULL THEN
        IF TG_OP='UPDATE' THEN
            IF OLD.requisition_item_id IS DISTINCT FROM NEW.requisition_item_id OR OLD.purchase_order_id IS DISTINCT FROM NEW.purchase_order_id
                OR OLD.project_id IS DISTINCT FROM NEW.project_id THEN
                RAISE EXCEPTION 'PO_DRAFT_SOURCE_LINE_IMMUTABLE' USING ERRCODE='23514';
            END IF;
        ELSE
            SELECT * INTO predecessor FROM purchase_order_items WHERE id=NEW.supersedes_id;
            IF NOT FOUND OR predecessor.purchase_order_id<>parent.id OR predecessor.requisition_item_id IS DISTINCT FROM NEW.requisition_item_id THEN
                RAISE EXCEPTION 'PO_DRAFT_SOURCE_AMENDMENT_REQUIRED' USING ERRCODE='23514';
            END IF;
        END IF;
        IF NOT EXISTS (SELECT 1 FROM material_requisition_items s WHERE s.id=NEW.requisition_item_id
            AND s.requisition_id=parent.requisition_id AND s.project_id=NEW.project_id) THEN
            RAISE EXCEPTION 'PO_DRAFT_SOURCE_LINE_MISMATCH' USING ERRCODE='23514';
        END IF;
    END IF;
    RETURN NEW;
END $$;
CREATE TRIGGER po_draft_source_line_guard BEFORE INSERT OR UPDATE OF requisition_item_id,purchase_order_id,project_id
ON purchase_order_items FOR EACH ROW EXECUTE FUNCTION epcc_guard_po_draft_source_line();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only: retain draft source provenance guards.');
    }
};
