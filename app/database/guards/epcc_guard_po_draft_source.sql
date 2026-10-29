CREATE OR REPLACE FUNCTION public.epcc_guard_po_draft_source()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
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
END $function$;
