-- A direct P2P wall root must carry an explicit longitudinal branch angle.
-- Older P2P roots may remain NULL until deliberately revised; they are never
-- backfilled from station, offset, joint kind, or a presumed perpendicular fit.
-- Once a BranchPad snapshots the root beta, that value cannot drift while the
-- physical pad assembly remains installed.
CREATE OR REPLACE FUNCTION epcc_guard_joint_branch_angle() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE
    v_beta numeric;
    v_old_beta numeric;
BEGIN
    v_beta := NULLIF(to_jsonb(NEW)->>'branch_longitudinal_angle_deg', '')::numeric;

    IF TG_OP = 'UPDATE' THEN
        v_old_beta := NULLIF(to_jsonb(OLD)->>'branch_longitudinal_angle_deg', '')::numeric;

        IF v_beta IS DISTINCT FROM v_old_beta
            AND EXISTS (
                SELECT 1
                  FROM branch_pads
                 WHERE branch_root_joint_id = NEW.id
            ) THEN
            RAISE EXCEPTION 'JT_P2P_BRANCH_ANGLE_PAD_LOCKED' USING ERRCODE = '23514';
        END IF;
    END IF;

    IF NEW.joint_type IN ('BRANCH_STUB_IN', 'BRANCH_STUB_ON') THEN
        IF v_beta IS NULL OR v_beta < 45.00 OR v_beta > 90.00 THEN
            IF TG_OP = 'INSERT' THEN
                RAISE EXCEPTION 'JT_P2P_BRANCH_ANGLE_REQUIRED' USING ERRCODE = '23514';
            END IF;

            IF OLD.joint_type NOT IN ('BRANCH_STUB_IN', 'BRANCH_STUB_ON')
                OR v_old_beta IS NOT NULL THEN
                RAISE EXCEPTION 'JT_P2P_BRANCH_ANGLE_REQUIRED' USING ERRCODE = '23514';
            END IF;
        END IF;
    ELSIF v_beta IS NOT NULL THEN
        RAISE EXCEPTION 'JT_BRANCH_ANGLE_ONLY_FOR_P2P' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;
