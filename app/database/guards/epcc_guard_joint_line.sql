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
-- What it deliberately does NOT do: it does not enable tie-ins. A cross-line
-- joint stays refused, here as in the service. Making those work is ADR-006
-- §13 (R-2/P-22) and an open design, not a missing constraint.
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
