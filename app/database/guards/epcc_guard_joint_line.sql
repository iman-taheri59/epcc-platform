-- Guard for the one line a joint is allowed to belong to.
--
-- A joint carries a single piping_line_id, and the joint number is allocated
-- per line (joints_line_seq_unique, nextSeq under a lock). That number is not
-- cosmetic: it is what ties the weld to a hydrotest pack, a test pack and an
-- ITR. Whoever owns the number owns the weld's destiny in the turnover
-- dossier. So a joint whose two ends sit on different lines is not merely odd,
-- it is unanswerable — which line's dossier does this weld belong to?
--
-- JointService refuses that in PHP. This guard makes it true in the database
-- too, because the PHP rule is one direct INSERT away from being irrelevant,
-- and the test suite has been known to write joints by hand.
--
-- Nozzles are the reason this guard cannot simply compare two component rows.
-- A NOZZLE port belongs to equipment, not to a line, so it contributes no line
-- at all: the joint takes its number from whichever side does have one
-- (ADR-010 §3.1). A nozzle-to-component joint is therefore legal, and a
-- nozzle-to-nozzle one is not, because then nothing owns the number.
--
-- A BRANCH JOINT is the one shape this guard deliberately lets through with a
-- NULL second port (ADR-006 §§14–15, P-22). P2P stubs weld the branch pipe's end
-- to a point on the header's WALL; an OLET wall weld stores the fitting's real
-- RUN port there instead. Neither shape invents a port on the header. The joint
-- therefore carries a frozen header reference instead of a second port: the
-- header component, a station along it, and an offset from its centreline.
--
-- That makes a branch joint CROSS-LINE BY NATURE, which is the case this guard
-- was written to refuse. It is not the same thing as a tie-in, though, and
-- conflating them would be wrong:
--
--   - a TIE-IN joins two line ENDS. Neither side owns the weld, which is why
--     the number has nowhere to live and why the refusal stands;
--   - a BRANCH is a weld made by one line ONTO another. The branch pipe's
--     component is on the branch line, that line numbers the weld, and that
--     line's dossier owns it. The header merely gets landed on.
--
-- So when port_b_id is NULL the guard checks only port_a's side, and the line
-- the joint is recorded against must be that side's line. The header may sit
-- on any other line in the project: it is a thing being welded to, not a
-- co-owner of the number.
CREATE OR REPLACE FUNCTION epcc_guard_joint_line() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE
    v_line_a uuid;
    v_line_b uuid;
    v_owner_a text;
    v_owner_b text;
BEGIN
    -- The line each side claims, and what kind of thing that side is. A nozzle
    -- port has no component, so its line is NULL by design rather than by
    -- accident — which is exactly why "both NULL" cannot be told apart from
    -- "one side is a nozzle" by looking at the lines alone.
    SELECT c.piping_line_id, p.owner_kind
      INTO v_line_a, v_owner_a
      FROM ports p
      LEFT JOIN components c ON c.id = p.component_id
     WHERE p.id = NEW.port_a_id;

    -- A wall joint has no second port. joints_other_side already insists that
    -- a NULL port_b_id is accompanied by a header reference, so reaching this
    -- branch without one cannot happen - but the tests write rows by hand, so
    -- assert it rather than trust it.
    IF NEW.port_b_id IS NULL THEN
        IF NEW.branch_header_component_id IS NULL THEN
            RAISE EXCEPTION 'JT_NO_LINE_SIDE' USING ERRCODE = '23514';
        END IF;

        IF v_owner_a IS NULL THEN
            RAISE EXCEPTION 'JT_PORT_NOT_FOUND' USING ERRCODE = '23503';
        END IF;

        -- The branch pipe's own line owns the weld and its number.
        IF v_line_a IS NULL THEN
            RAISE EXCEPTION 'JT_NO_LINE_SIDE' USING ERRCODE = '23514';
        END IF;

        IF v_line_a <> NEW.piping_line_id THEN
            RAISE EXCEPTION 'JT_LINE_MISMATCH' USING ERRCODE = '23514';
        END IF;

        RETURN NEW;
    END IF;

    SELECT c.piping_line_id, p.owner_kind
      INTO v_line_b, v_owner_b
      FROM ports p
      LEFT JOIN components c ON c.id = p.component_id
     WHERE p.id = NEW.port_b_id;

    IF v_owner_a IS NULL OR v_owner_b IS NULL THEN
        RAISE EXCEPTION 'JT_PORT_NOT_FOUND' USING ERRCODE = '23503';
    END IF;

    -- At least one end must be a pipe. Two equipment nozzles bolted together
    -- have no line to be numbered against, and piping_line_id is NOT NULL.
    IF v_line_a IS NULL AND v_line_b IS NULL THEN
        RAISE EXCEPTION 'JT_NO_LINE_SIDE' USING ERRCODE = '23514';
    END IF;

    -- A component side must be on the very line this joint is recorded
    -- against. A nozzle side asserts nothing.
    IF v_line_a IS NOT NULL AND v_line_a <> NEW.piping_line_id THEN
        RAISE EXCEPTION 'JT_LINE_MISMATCH' USING ERRCODE = '23514';
    END IF;

    IF v_line_b IS NOT NULL AND v_line_b <> NEW.piping_line_id THEN
        RAISE EXCEPTION 'JT_LINE_MISMATCH' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;
