-- A BranchPad is one physical plate with exactly two independently numbered
-- fillet-joint records. Foreign keys to those future joint IDs are deferred so
-- the service can create the pad first and both joints atomically in one
-- transaction; this deferred check closes the cycle at commit.
CREATE OR REPLACE FUNCTION epcc_guard_branch_pad_complete() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'BRANCH_PAD_IMMUTABLE' USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM components pad_component
          JOIN components header_pipe ON header_pipe.id = NEW.header_pipe_component_id
          JOIN components branch_pipe ON branch_pipe.id = NEW.branch_pipe_component_id
          JOIN ports branch_pipe_port ON branch_pipe_port.id = NEW.branch_pipe_port_id
          JOIN piping_lines header_line ON header_line.id = NEW.header_line_id
          JOIN joints root ON root.id = NEW.branch_root_joint_id
          JOIN ports root_port ON root_port.id = root.port_a_id
          JOIN components root_branch ON root_branch.id = root_port.component_id
         WHERE pad_component.id = NEW.component_id
           AND pad_component.template_code = 'BRANCH_PAD'
           AND pad_component.project_id = NEW.project_id
           AND pad_component.company_id = NEW.company_id
           AND pad_component.piping_line_id = branch_pipe.piping_line_id
           AND header_pipe.template_code = 'PIPE'
           AND branch_pipe.template_code = 'PIPE'
           AND header_pipe.project_id = NEW.project_id
           AND branch_pipe.project_id = NEW.project_id
           AND header_pipe.piping_line_id = NEW.header_line_id
           AND header_line.project_id = NEW.project_id
           AND header_line.pms_class_snapshot_id = NEW.pms_class_snapshot_id
           AND branch_pipe_port.component_id = branch_pipe.id
           AND branch_pipe_port.role = 'RUN'
           AND root.project_id = NEW.project_id
           AND root.port_b_id IS NULL
           AND root.branch_header_component_id = header_pipe.id
           AND root.joint_type IN ('BRANCH_STUB_IN', 'BRANCH_STUB_ON')
           AND root.branch_kind = CASE root.joint_type
                WHEN 'BRANCH_STUB_IN' THEN 'STUB_IN'
                WHEN 'BRANCH_STUB_ON' THEN 'STUB_ON'
               END
           AND root.port_a_id = NEW.branch_pipe_port_id
           AND root_port.id = NEW.branch_pipe_port_id
           AND root_port.role = 'RUN'
           AND root_port.component_id = NEW.branch_pipe_component_id
           AND root_branch.project_id = NEW.project_id
           AND root_branch.piping_line_id = branch_pipe.piping_line_id
           AND root.branch_kind IN ('STUB_IN', 'STUB_ON')
           AND (
                NOT (to_jsonb(root) ? 'branch_longitudinal_angle_deg')
                OR (to_jsonb(root)->>'branch_longitudinal_angle_deg')::numeric = NEW.pad_geometry_beta_deg
           )
           AND NEW.branch_rule_component_type = 'P2P'
           AND NEW.branch_rule_p2p_kind = root.branch_kind
           AND NEW.pad_thickness_mm > 0
           AND NEW.pad_minor_axis_mm > 0
           AND NEW.pad_major_axis_mm > NEW.pad_minor_axis_mm
           AND NEW.pad_major_axis_direction = 'AROUND_HEADER'
           AND NEW.pad_minor_axis_direction = 'ALONG_HEADER'
           AND NEW.pad_geometry_d2_mm > 0
           AND NEW.pad_geometry_beta_deg = 90.00
           AND NEW.pad_geometry_method = 'CENTERED_90_DEGREE_SADDLE_PERIMETER_V2'
           AND jsonb_typeof(NEW.pad_geometry_snapshot) = 'object'
    ) THEN
        RAISE EXCEPTION 'BRANCH_PAD_INVALID' USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM joints header_joint
          JOIN ports header_pad_port ON header_pad_port.id = header_joint.port_a_id
          JOIN components pad_component ON pad_component.id = header_pad_port.component_id
          JOIN joints root ON root.id = NEW.branch_root_joint_id
         WHERE header_joint.id = NEW.header_joint_id
           AND header_joint.branch_pad_id = NEW.id
           AND header_joint.branch_pad_side = 'HEADER'
           AND header_joint.branch_root_joint_id = NEW.branch_root_joint_id
           AND header_joint.joint_type = 'FILLET_WELD'
           AND header_joint.numbering_policy = 'REQUIRED'
           AND header_joint.joint_seq IS NOT NULL
           AND header_joint.piping_line_id = NEW.header_line_id
           AND header_joint.branch_wall_component_id = NEW.header_pipe_component_id
           AND header_joint.branch_station_mm = root.branch_station_mm
           AND header_joint.branch_offset_mm = root.branch_offset_mm
           AND header_pad_port.component_id = NEW.component_id
           AND header_pad_port.role = 'PAD_HEADER'
           AND header_pad_port.is_connected
           AND pad_component.template_code = 'BRANCH_PAD'
    ) THEN
        RAISE EXCEPTION 'BRANCH_PAD_HEADER_FILLET_MISSING' USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1
          FROM joints branch_joint
          JOIN ports branch_pad_port ON branch_pad_port.id = branch_joint.port_a_id
          JOIN components pad_component ON pad_component.id = branch_pad_port.component_id
         WHERE branch_joint.id = NEW.branch_joint_id
           AND branch_joint.branch_pad_id = NEW.id
           AND branch_joint.branch_pad_side = 'BRANCH'
           AND branch_joint.branch_root_joint_id = NEW.branch_root_joint_id
           AND branch_joint.joint_type = 'FILLET_WELD'
           AND branch_joint.numbering_policy = 'REQUIRED'
           AND branch_joint.joint_seq IS NOT NULL
           AND branch_joint.piping_line_id = NEW.header_line_id
           AND branch_joint.branch_wall_component_id = NEW.branch_pipe_component_id
           AND branch_joint.branch_station_mm IS NULL
           AND branch_joint.branch_offset_mm IS NULL
           AND branch_pad_port.component_id = NEW.component_id
           AND branch_pad_port.role = 'PAD_BRANCH'
           AND branch_pad_port.is_connected
           AND pad_component.template_code = 'BRANCH_PAD'
    ) THEN
        RAISE EXCEPTION 'BRANCH_PAD_BRANCH_FILLET_MISSING' USING ERRCODE = '23514';
    END IF;

    IF (SELECT count(*) FROM joints WHERE branch_pad_id = NEW.id) <> 2 THEN
        RAISE EXCEPTION 'BRANCH_PAD_NEEDS_EXACTLY_TWO_FILLET_JOINTS' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;
