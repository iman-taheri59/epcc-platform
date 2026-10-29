CREATE OR REPLACE FUNCTION public.epcc_guard_po_execution_policy()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE current_version integer; person record; selection jsonb;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:'||NEW.project_id::text,0));
    SELECT COALESCE(max(version),0) INTO current_version FROM po_execution_policies
        WHERE project_id=NEW.project_id AND buyer_company_id=NEW.buyer_company_id;
    IF NEW.version<>current_version+1 THEN RAISE EXCEPTION 'PO_EXECUTION_POLICY_STALE' USING ERRCODE='23514'; END IF;
    IF NOT EXISTS (SELECT 1 FROM companies c JOIN project_company pc ON pc.company_id=c.id
        WHERE pc.project_id=NEW.project_id AND c.id=NEW.buyer_company_id AND c.tenant_type IN ('OWN','EPC','SUB')) THEN
        RAISE EXCEPTION 'PO_EXECUTION_BUYER_INVALID' USING ERRCODE='23514';
    END IF;
    SELECT u.name INTO person FROM users u JOIN project_memberships pm ON pm.user_id=u.id
        WHERE u.id=NEW.created_by AND u.company_id=NEW.company_id AND u.is_active AND pm.project_id=NEW.project_id
        AND EXISTS (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
            AND (jsonb_exists(r.capabilities,'project.configure') OR jsonb_exists(r.capabilities,'project.*') OR jsonb_exists(r.capabilities,'*')));
    IF NOT FOUND THEN RAISE EXCEPTION 'PO_EXECUTION_CONFIG_FORBIDDEN' USING ERRCODE='42501'; END IF;
    FOR selection IN SELECT value FROM jsonb_array_elements(NEW.finance_selectors||NEW.issue_selectors) LOOP
        IF NOT EXISTS (SELECT 1 FROM users u JOIN project_memberships pm ON pm.user_id=u.id
            JOIN project_company pc ON pc.company_id=u.company_id AND pc.project_id=pm.project_id
            WHERE pm.project_id=NEW.project_id AND u.is_active AND
                (selection->>'selector'='user:'||u.id::text OR EXISTS
                (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
                    AND selection->>'selector'='role:'||u.company_id::text||':'||r.code))) THEN
            RAISE EXCEPTION 'PO_EXECUTION_SELECTOR_INVALID' USING ERRCODE='23514';
        END IF;
    END LOOP;
    NEW.actor_name:=person.name; NEW.created_at:=CURRENT_TIMESTAMP;
    RETURN NEW;
END $function$;
