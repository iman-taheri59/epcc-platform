-- Guard for the project discrepancy register (TQ/NCR).
--
-- Two tables, one guard, chosen by the table being written.
--
-- What it guarantees:
--   * A discrepancy can only hang off a source that belongs to THIS project.
--     Polymorphism without a project check is how one tenant's NCR starts
--     blocking another tenant's line.
--   * A lifecycle event can only be written against an existing report of this
--     project, and never before the report was raised.
--
-- What it deliberately does NOT do: it does not issue, route, sign or verify
-- anything, and it grants no release. Recorded external metadata is still an
-- unverified claim.
CREATE OR REPLACE FUNCTION epcc_guard_project_discrepancy() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE
    v_ok     boolean;
    v_raised boolean;
BEGIN
    IF TG_TABLE_NAME = 'project_discrepancy_reports' THEN

        -- Exactly one source is already guaranteed by the CHECK. Here we prove
        -- whichever one is set actually belongs to this project.
        IF NEW.purchase_order_item_id IS NOT NULL THEN
            SELECT EXISTS (SELECT 1 FROM purchase_order_items l
                            WHERE l.id = NEW.purchase_order_item_id
                              AND l.project_id = NEW.project_id) INTO v_ok;
            IF NOT v_ok THEN
                RAISE EXCEPTION 'DR_SOURCE_NOT_FOUND' USING ERRCODE = '23503';
            END IF;
        END IF;

        IF NEW.component_id IS NOT NULL THEN
            SELECT EXISTS (SELECT 1 FROM components c
                            WHERE c.id = NEW.component_id
                              AND c.project_id = NEW.project_id) INTO v_ok;
            IF NOT v_ok THEN
                RAISE EXCEPTION 'DR_SOURCE_NOT_FOUND' USING ERRCODE = '23503';
            END IF;
        END IF;

        IF NEW.pipe_schedule_request_id IS NOT NULL THEN
            SELECT EXISTS (SELECT 1 FROM pipe_schedule_exception_requests r
                            WHERE r.id = NEW.pipe_schedule_request_id
                              AND r.project_id = NEW.project_id) INTO v_ok;
            IF NOT v_ok THEN
                RAISE EXCEPTION 'DR_SOURCE_NOT_FOUND' USING ERRCODE = '23503';
            END IF;
        END IF;

        IF NEW.test_pack_id IS NOT NULL THEN
            SELECT EXISTS (SELECT 1 FROM piping_test_packs p
                            WHERE p.id = NEW.test_pack_id
                              AND p.project_id = NEW.project_id) INTO v_ok;
            IF NOT v_ok THEN
                RAISE EXCEPTION 'DR_SOURCE_NOT_FOUND' USING ERRCODE = '23503';
            END IF;
        END IF;

        IF NEW.release_note_id IS NOT NULL THEN
            SELECT EXISTS (SELECT 1 FROM inspection_release_notes n
                            WHERE n.id = NEW.release_note_id
                              AND n.project_id = NEW.project_id) INTO v_ok;
            IF NOT v_ok THEN
                RAISE EXCEPTION 'DR_SOURCE_NOT_FOUND' USING ERRCODE = '23503';
            END IF;
        END IF;

        RETURN NEW;
    END IF;

    -- project_discrepancy_events
    SELECT EXISTS (SELECT 1 FROM project_discrepancy_reports r
                    WHERE r.id = NEW.report_id
                      AND r.project_id = NEW.project_id) INTO v_ok;
    IF NOT v_ok THEN
        RAISE EXCEPTION 'DR_REPORT_NOT_FOUND' USING ERRCODE = '23503';
    END IF;

    -- Nothing happens before the report exists as raised. An answer or a
    -- disposition without a raised report is a record with no beginning.
    IF NEW.event <> 'RAISED' THEN
        SELECT EXISTS (SELECT 1 FROM project_discrepancy_events e
                        WHERE e.project_id = NEW.project_id
                          AND e.report_id = NEW.report_id
                          AND e.event = 'RAISED') INTO v_raised;
        IF NOT v_raised THEN
            RAISE EXCEPTION 'DR_EVENT_WITHOUT_RAISED' USING ERRCODE = '23514';
        END IF;
    END IF;

    RETURN NEW;
END; $$;
