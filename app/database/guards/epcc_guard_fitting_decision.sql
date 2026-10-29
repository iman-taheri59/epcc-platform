CREATE OR REPLACE FUNCTION public.epcc_guard_fitting_decision()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
    DECLARE definition_row record; workflow_steps jsonb; selector text; signer_roles jsonb;
            signer_scope jsonb; already integer;
    BEGIN
        PERFORM pg_advisory_xact_lock(hashtextextended('fitting-review:' || NEW.project_id::text, 0));
        SELECT d.* INTO definition_row FROM fitting_review_runs r
            JOIN project_fitting_definitions d ON d.id=r.definition_id
            WHERE r.id=NEW.run_id AND r.project_id=NEW.project_id;
        IF NOT FOUND THEN RAISE EXCEPTION 'Review run unavailable' USING ERRCODE='42501'; END IF;
        SELECT p.steps INTO workflow_steps FROM fitting_review_policies p
            JOIN fitting_review_runs r ON r.policy_id=p.id WHERE r.id=NEW.run_id;
        SELECT count(*) INTO already FROM fitting_review_decisions WHERE run_id=NEW.run_id;
        IF NEW.stage <> already+1 OR NEW.stage > jsonb_array_length(workflow_steps)
            OR EXISTS (SELECT 1 FROM fitting_review_decisions WHERE run_id=NEW.run_id
                AND (decision='RETURN' OR created_by=NEW.created_by))
            OR EXISTS (SELECT 1 FROM project_fitting_definitions WHERE revises_id=definition_row.id)
            OR EXISTS (SELECT 1 FROM project_fitting_definitions d
                WHERE (d.id=COALESCE(definition_row.root_id,definition_row.id)
                    OR d.root_id=COALESCE(definition_row.root_id,definition_row.id))
                AND d.created_by=NEW.created_by)
        THEN RAISE EXCEPTION 'Review sequence or separation of duties violation' USING ERRCODE='42501'; END IF;
        SELECT pm.role_codes,pm.discipline_scope INTO signer_roles,signer_scope
            FROM project_memberships pm JOIN users u ON u.id=pm.user_id
            WHERE pm.project_id=NEW.project_id AND pm.user_id=NEW.created_by
                AND u.company_id=NEW.company_id AND u.is_active;
        IF NOT FOUND OR (signer_scope IS NOT NULL AND NOT signer_scope ? 'PIPING') THEN
            RAISE EXCEPTION 'Reviewer inactive or outside discipline' USING ERRCODE='42501'; END IF;
        selector := workflow_steps->(NEW.stage-1)->>'selector';
        IF NOT COALESCE(selector='user:'||NEW.created_by::text
            OR (split_part(selector,':',1)='role' AND split_part(selector,':',2)=NEW.company_id::text
                AND signer_roles ? split_part(selector,':',3)),false)
        THEN RAISE EXCEPTION 'Reviewer not assigned to this step' USING ERRCODE='42501'; END IF;
        RETURN NEW;
    END;
    $function$;
