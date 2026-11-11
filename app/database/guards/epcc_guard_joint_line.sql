-- Guard for the line that owns a joint's register entry and sequence.
--
-- The physical ports of an ordinary joint must still be on the same line. A
-- branch wall root is an explicit cross-line shape: P2P and OLET roots name
-- their header component, and may be owned by either the branch line or that
-- header line. Later joints may use the header register through that explicit
-- wall root. A TEE/CROSS BRANCH-port weld is the one other manual case: it may
-- carry an explicit same-project header-line reference while its physical
-- ports remain on their one physical line. Neither case permits an ordinary
-- cross-line port-to-port tie-in.
--
-- Nozzles own no line. A normal nozzle-to-component joint therefore remains
-- owned by the pipe side (ADR-010 §3.1).
CREATE OR REPLACE FUNCTION epcc_guard_joint_line() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE
    v_line_a uuid;
    v_line_b uuid;
    v_project_a uuid;
    v_project_b uuid;
    v_owner_a text;
    v_owner_b text;
    v_role_a text;
    v_role_b text;
    v_template_a text;
    v_template_b text;
    v_header_line uuid;
    v_header_project uuid;
    v_header_template text;
    v_owner_project uuid;
    v_branch_root uuid;
    v_manual_header_line uuid;
    v_is_tee_cross_branch boolean := false;
    v_root_branch_line uuid;
    v_root_header_line uuid;
    v_root_project uuid;
    v_root_branch_project uuid;
    v_root_header_project uuid;
    v_root_port_b uuid;
    v_root_header_component uuid;
    v_root_type text;
BEGIN
    -- Read optional columns through JSON so this function remains runnable
    -- during rollback after either ownership column is removed.
    v_branch_root := NULLIF(to_jsonb(NEW)->>'branch_root_joint_id', '')::uuid;
    v_manual_header_line := NULLIF(to_jsonb(NEW)->>'branch_header_line_id', '')::uuid;

    SELECT c.piping_line_id, c.project_id, p.owner_kind, p.role, c.template_code
      INTO v_line_a, v_project_a, v_owner_a, v_role_a, v_template_a
      FROM ports p
      LEFT JOIN components c ON c.id = p.component_id
     WHERE p.id = NEW.port_a_id;

    IF NEW.port_b_id IS NULL THEN
        -- A wall root has no second port, but it must name the real header and
        -- be one of the explicitly modelled P2P/OLET wall-weld shapes.
        IF NEW.branch_header_component_id IS NULL OR v_line_a IS NULL THEN
            RAISE EXCEPTION 'JT_NO_LINE_SIDE' USING ERRCODE = '23514';
        END IF;

        IF v_manual_header_line IS NOT NULL THEN
            RAISE EXCEPTION 'JT_HEADER_LINE_INVALID' USING ERRCODE = '23514';
        END IF;

        IF v_owner_a IS NULL THEN
            RAISE EXCEPTION 'JT_PORT_NOT_FOUND' USING ERRCODE = '23503';
        END IF;

        SELECT c.piping_line_id, c.project_id, c.template_code
          INTO v_header_line, v_header_project, v_header_template
          FROM components c
         WHERE c.id = NEW.branch_header_component_id;

        IF v_header_line IS NULL OR v_header_template <> 'PIPE'
            OR v_project_a IS DISTINCT FROM NEW.project_id
            OR v_header_project IS DISTINCT FROM NEW.project_id THEN
            RAISE EXCEPTION 'JT_HEADER_LINE_INVALID' USING ERRCODE = '23514';
        END IF;

        IF NEW.joint_type NOT IN ('BRANCH_STUB_IN', 'BRANCH_STUB_ON', 'OLET_HEADER_WELD') THEN
            RAISE EXCEPTION 'JT_WALL_TYPE_INVALID' USING ERRCODE = '23514';
        END IF;

        -- Explicit owner choice: either the physical branch line or the line
        -- of the real header component. No third line is a valid owner.
        IF NEW.piping_line_id <> v_line_a AND NEW.piping_line_id <> v_header_line THEN
            RAISE EXCEPTION 'JT_LINE_MISMATCH' USING ERRCODE = '23514';
        END IF;

        RETURN NEW;
    END IF;

    SELECT c.piping_line_id, c.project_id, p.owner_kind, p.role, c.template_code
      INTO v_line_b, v_project_b, v_owner_b, v_role_b, v_template_b
      FROM ports p
      LEFT JOIN components c ON c.id = p.component_id
     WHERE p.id = NEW.port_b_id;

    IF v_owner_a IS NULL OR v_owner_b IS NULL THEN
        RAISE EXCEPTION 'JT_PORT_NOT_FOUND' USING ERRCODE = '23503';
    END IF;

    IF v_line_a IS NULL AND v_line_b IS NULL THEN
        RAISE EXCEPTION 'JT_NO_LINE_SIDE' USING ERRCODE = '23514';
    END IF;

    v_is_tee_cross_branch := COALESCE(
        (v_role_a = 'BRANCH' AND v_template_a IN ('TEE', 'TEE-SKEWED') AND NEW.branch_kind = 'TEE')
        OR (v_role_a = 'BRANCH' AND v_template_a = 'CROSS' AND NEW.branch_kind = 'CROSS')
        OR (v_role_b = 'BRANCH' AND v_template_b IN ('TEE', 'TEE-SKEWED') AND NEW.branch_kind = 'TEE')
        OR (v_role_b = 'BRANCH' AND v_template_b = 'CROSS' AND NEW.branch_kind = 'CROSS'),
        false
    );

    IF v_branch_root IS NOT NULL THEN
        IF v_manual_header_line IS NOT NULL THEN
            RAISE EXCEPTION 'JT_BRANCH_ROOT_INVALID' USING ERRCODE = '23514';
        END IF;

        -- The explicit root proves which header may own this ordinary-looking
        -- joint. The two real port sides must remain on that root's branch
        -- line; only piping_line_id (the register/number owner) may differ.
        SELECT r.port_b_id,
               r.branch_header_component_id,
               r.joint_type,
               r.project_id,
               branch_component.piping_line_id,
               branch_component.project_id,
               header_component.piping_line_id,
               header_component.project_id
          INTO v_root_port_b,
               v_root_header_component,
               v_root_type,
               v_root_project,
               v_root_branch_line,
               v_root_branch_project,
               v_root_header_line,
               v_root_header_project
          FROM joints r
          JOIN ports root_port ON root_port.id = r.port_a_id
          JOIN components branch_component ON branch_component.id = root_port.component_id
          JOIN components header_component ON header_component.id = r.branch_header_component_id
         WHERE r.id = v_branch_root;

        IF NOT FOUND OR v_root_port_b IS NOT NULL OR v_root_header_component IS NULL
            OR v_root_type NOT IN ('BRANCH_STUB_IN', 'BRANCH_STUB_ON', 'OLET_HEADER_WELD')
            OR v_root_branch_line IS NULL OR v_root_header_line IS NULL THEN
            RAISE EXCEPTION 'JT_BRANCH_ROOT_INVALID' USING ERRCODE = '23514';
        END IF;

        IF v_root_project IS DISTINCT FROM NEW.project_id
            OR v_root_branch_project IS DISTINCT FROM v_root_project
            OR v_root_header_project IS DISTINCT FROM v_root_project
            OR v_project_a IS DISTINCT FROM v_root_project
            OR (v_project_b IS NOT NULL AND v_project_b IS DISTINCT FROM v_root_project) THEN
            RAISE EXCEPTION 'JT_BRANCH_ROOT_INVALID' USING ERRCODE = '23514';
        END IF;

        IF (v_line_a IS NOT NULL AND v_line_a <> v_root_branch_line)
            OR (v_line_b IS NOT NULL AND v_line_b <> v_root_branch_line) THEN
            RAISE EXCEPTION 'JT_LINE_MISMATCH' USING ERRCODE = '23514';
        END IF;

        IF NEW.piping_line_id <> v_root_branch_line
            AND NEW.piping_line_id <> v_root_header_line THEN
            RAISE EXCEPTION 'JT_LINE_MISMATCH' USING ERRCODE = '23514';
        END IF;

        RETURN NEW;
    END IF;

    IF v_manual_header_line IS NOT NULL THEN
        -- A direct header assignment is permitted only for the branch weld on
        -- a real TEE/CROSS BRANCH port, never by setting branch_kind alone.
        IF NOT v_is_tee_cross_branch THEN
            RAISE EXCEPTION 'JT_BRANCH_OWNER_INVALID' USING ERRCODE = '23514';
        END IF;

        -- Both real line-bearing sides remain on one physical branch line.
        -- This explicit register owner does not create a physical tie-in.
        IF v_line_a IS NOT NULL AND v_line_b IS NOT NULL AND v_line_a <> v_line_b THEN
            RAISE EXCEPTION 'JT_LINE_MISMATCH' USING ERRCODE = '23514';
        END IF;

        IF (v_project_a IS NOT NULL AND v_project_a IS DISTINCT FROM NEW.project_id)
            OR (v_project_b IS NOT NULL AND v_project_b IS DISTINCT FROM NEW.project_id) THEN
            RAISE EXCEPTION 'JT_LINE_MISMATCH' USING ERRCODE = '23514';
        END IF;

        SELECT project_id
          INTO v_owner_project
          FROM piping_lines
         WHERE id = v_manual_header_line;

        IF v_owner_project IS DISTINCT FROM NEW.project_id
            OR v_manual_header_line IS DISTINCT FROM NEW.piping_line_id
            OR v_manual_header_line = COALESCE(v_line_a, v_line_b) THEN
            RAISE EXCEPTION 'JT_LINE_MISMATCH' USING ERRCODE = '23514';
        END IF;

        RETURN NEW;
    END IF;

    -- Without a wall-root link or an explicit TEE/CROSS header-line selection,
    -- each component-side line must be the line recorded on the joint. This
    -- preserves the ordinary tie-in boundary.
    IF v_line_a IS NOT NULL AND v_line_a <> NEW.piping_line_id THEN
        RAISE EXCEPTION 'JT_LINE_MISMATCH' USING ERRCODE = '23514';
    END IF;

    IF v_line_b IS NOT NULL AND v_line_b <> NEW.piping_line_id THEN
        RAISE EXCEPTION 'JT_LINE_MISMATCH' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;
