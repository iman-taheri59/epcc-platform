-- Guard for equipment and their nozzles (ADR-010 §3).
--
-- A nozzle is a port owner, exactly like a component, so `ports` and `joints`
-- need no change. What does need holding is the outside world a piece of
-- equipment points at: its subsystem, its purchase order line, its datasheet
-- and its GA drawing. Every one of those is project-scoped, and a vessel in
-- this project attached to another project's subsystem would quietly join two
-- tenants' completion machinery together.
--
-- What it deliberately does NOT do: no equipment engineering. No internals,
-- trays, seals, drivers, foundations or preservation — none of which a pipe
-- needs in order to terminate correctly.
CREATE OR REPLACE FUNCTION epcc_guard_equipment() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE
    v_ok boolean;
BEGIN
    IF TG_TABLE_NAME = 'equipment' THEN

        IF NEW.subsystem_id IS NOT NULL THEN
            SELECT EXISTS (SELECT 1 FROM subsystems s
                            WHERE s.id = NEW.subsystem_id
                              AND s.project_id = NEW.project_id) INTO v_ok;
            IF NOT v_ok THEN
                RAISE EXCEPTION 'EQ_SUBSYSTEM_NOT_FOUND' USING ERRCODE = '23503';
            END IF;
        END IF;

        -- Equipment is procured. The line it was bought on must be this
        -- project's line, or the vessel inherits someone else's MTC and vendor.
        IF NEW.po_item_id IS NOT NULL THEN
            SELECT EXISTS (SELECT 1 FROM purchase_order_items l
                            WHERE l.id = NEW.po_item_id
                              AND l.project_id = NEW.project_id) INTO v_ok;
            IF NOT v_ok THEN
                RAISE EXCEPTION 'EQ_PO_ITEM_NOT_FOUND' USING ERRCODE = '23503';
            END IF;
        END IF;

        IF NEW.datasheet_document_id IS NOT NULL THEN
            SELECT EXISTS (SELECT 1 FROM documents d
                            WHERE d.id = NEW.datasheet_document_id
                              AND d.project_id = NEW.project_id) INTO v_ok;
            IF NOT v_ok THEN
                RAISE EXCEPTION 'EQ_DOCUMENT_NOT_FOUND' USING ERRCODE = '23503';
            END IF;
        END IF;

        IF NEW.ga_drawing_document_id IS NOT NULL THEN
            SELECT EXISTS (SELECT 1 FROM documents d
                            WHERE d.id = NEW.ga_drawing_document_id
                              AND d.project_id = NEW.project_id) INTO v_ok;
            IF NOT v_ok THEN
                RAISE EXCEPTION 'EQ_DOCUMENT_NOT_FOUND' USING ERRCODE = '23503';
            END IF;
        END IF;

        RETURN NEW;
    END IF;

    -- equipment_nozzles: a nozzle belongs to equipment of the SAME project.
    -- Otherwise a nozzle could be grafted onto a vessel it was never cut into.
    SELECT EXISTS (SELECT 1 FROM equipment e
                    WHERE e.id = NEW.equipment_id
                      AND e.project_id = NEW.project_id) INTO v_ok;
    IF NOT v_ok THEN
        RAISE EXCEPTION 'EQ_EQUIPMENT_NOT_FOUND' USING ERRCODE = '23503';
    END IF;

    RETURN NEW;
END; $$;
