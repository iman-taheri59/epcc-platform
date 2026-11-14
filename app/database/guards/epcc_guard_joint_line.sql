-- Guard for the line that owns a joint's register entry and sequence.
--
-- Ordinary physical ports remain on one line. P2P/OLET wall roots carry an
-- explicit header component, while TEE/CROSS branch-port welds may carry an
-- explicit same-project header-line selection. Reinforcement-pad fillets are
-- the one additional wall shape: each has a real BRANCH_PAD-side port, an
-- explicit target pipe wall, a direct P2P root, and the header line as register
-- owner. None of these shapes admits an ordinary cross-line port-to-port
-- tie-in or moves physical components.
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
    v_branch_pad uuid;
    v_branch_pad_side text;
    v_branch_wall_component uuid;
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
    -- Read optional ownership fields through JSON so this function remains
    -- safe during rollback after an optional column is removed.
    v_branch_root := NULLIF(to_jsonb(NEW)->>'branch_root_joint_id', '')::uuid;
    v_manual_header_line := NULLIF(to_jsonb(NEW)->>'branch_header_line_id', '')::uuid;
    v_branch_pad := NULLIF(to_jsonb(NEW)->>'branch_pad_id', '')::uuid;
    v_branch_pad_side := NULLIF(to_jsonb(NEW)->>'branch_pad_side', '');
    v_branch_wall_component := NULLIF(to_jsonb(NEW)->>'branch_wall_component_id', '')::uuid;

    SELECT c.piping_line_id, c.project_id, p.owner_kind, p.role, c.template_code
      INTO v_line_a, v_project_a, v_owner_a, v_role_a, v_template_a
      FROM ports p
      LEFT JOIN components c ON c.id = p.component_id
     WHERE p.id = NEW.port_a_id;

    -- The two independently numbered pad fillets are wall joints with a pad
    -- port on side A. They must be created from the referenced BranchPad and
    -- target exactly its header pipe or branch pipe; the header line owns both
    -- the number and PMS class. This is not a physical pipe-to-pipe tie-in.
    IF v_branch_pad IS NOT NULL OR v_branch_pad_side IS NOT NULL OR v_branch_wall_component IS NOT NULL THEN
        IF v_branch_pad IS NULL OR v_branch_pad_side NOT IN ('HEADER', 'BRANCH')
            OR v_branch_wall_component IS NULL OR v_branch_root IS NULL
            OR NEW.port_b_id IS NOT NULL OR NEW.branch_header_component_id IS NOT NULL
            OR NEW.joint_type <> 'FILLET_WELD' THEN
            RAISE EXCEPTION 'JT_BRANCH_PAD_INVALID' USING ERRCODE = '23514';
        END IF;

        IF NOT EXISTS (
            SELECT 1
              FROM branch_pads bp
              JOIN components pad_component ON pad_component.id = bp.component_id
              JOIN components header_component ON header_component.id = bp.header_pipe_component_id
              JOIN components branch_component ON branch_component.id = bp.branch_pipe_component_id
              JOIN components target_component ON target_component.id = NEW.branch_wall_component_id
              JOIN ports pad_port ON pad_port.id = NEW.port_a_id
              JOIN joints root ON root.id = bp.branch_root_joint_id
              JOIN ports root_port ON root_port.id = root.port_a_id
              JOIN components branch_anchor ON branch_anchor.id = root_port.component_id
              JOIN piping_lines header_line ON header_line.id = bp.header_line_id
             WHERE bp.id = v_branch_pad
               AND bp.branch_root_joint_id = v_branch_root
               AND NEW.branch_pad_id = bp.id
               AND NEW.project_id = bp.project_id
               AND NEW.company_id = bp.company_id
               AND NEW.id = CASE v_branch_pad_side
                    WHEN 'HEADER' THEN bp.header_joint_id
                    WHEN 'BRANCH' THEN bp.branch_joint_id
                   END
               AND NEW.piping_line_id = bp.header_line_id
               AND header_line.project_id = bp.project_id
               AND header_line.pms_class_snapshot_id = bp.pms_class_snapshot_id
               AND root.project_id = bp.project_id
               AND root.port_b_id IS NULL
               AND root.branch_header_component_id = bp.header_pipe_component_id
               AND root.joint_type IN ('BRANCH_STUB_IN', 'BRANCH_STUB_ON')
               AND root.branch_kind = CASE root.joint_type
                    WHEN 'BRANCH_STUB_IN' THEN 'STUB_IN'
                    WHEN 'BRANCH_STUB_ON' THEN 'STUB_ON'
                   END
               AND root.port_a_id = bp.branch_pipe_port_id
               AND root_port.id = bp.branch_pipe_port_id
               AND root_port.role = 'RUN'
               AND root_port.component_id = bp.branch_pipe_component_id
               AND branch_anchor.project_id = bp.project_id
               AND branch_anchor.piping_line_id = branch_component.piping_line_id
               AND pad_component.project_id = bp.project_id
               AND pad_component.template_code = 'BRANCH_PAD'
               AND pad_component.piping_line_id = branch_component.piping_line_id
               AND pad_port.component_id = pad_component.id
               AND target_component.project_id = bp.project_id
               AND target_component.template_code = 'PIPE'
               AND NEW.joint_type = 'FILLET_WELD'
               AND NEW.numbering_policy = 'REQUIRED'
               AND NEW.joint_seq IS NOT NULL
               AND NEW.branch_rule_state = 'RULE'
               AND NEW.branch_kind IS NULL
               AND NEW.branch_class_type = bp.branch_rule_component_type
               AND NEW.branch_class_p2p_kind IS NOT DISTINCT FROM bp.branch_rule_p2p_kind
               AND NEW.branch_pad_thickness_mm = bp.pad_thickness_mm
               AND (
                    (v_branch_pad_side = 'HEADER'
                     AND NEW.branch_wall_component_id = bp.header_pipe_component_id
                     AND target_component.piping_line_id = bp.header_line_id
                     AND pad_port.role = 'PAD_HEADER'
                     AND NEW.branch_station_mm = root.branch_station_mm
                     AND NEW.branch_offset_mm = root.branch_offset_mm
                     AND bp.header_joint_id = NEW.id)
                    OR
                    (v_branch_pad_side = 'BRANCH'
                     AND NEW.branch_wall_component_id = bp.branch_pipe_component_id
                     AND target_component.piping_line_id = branch_component.piping_line_id
                     AND pad_port.role = 'PAD_BRANCH'
                     AND NEW.branch_station_mm IS NULL
                     AND NEW.branch_offset_mm IS NULL
                     AND bp.branch_joint_id = NEW.id)
               )
        ) THEN
            RAISE EXCEPTION 'JT_BRANCH_PAD_INVALID' USING ERRCODE = '23514';
        END IF;

        RETURN NEW;
    END IF;

    IF NEW.port_b_id IS NULL THEN
        -- A P2P/OLET wall root has no second port, but it must name the real
        -- header and be one of the explicitly modelled branch wall shapes.
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
