CREATE OR REPLACE FUNCTION public.epcc_guard_po_author_policy()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE current_version integer; person record;
BEGIN
    PERFORM pg_advisory_xact_lock(hashtextextended('po-entry:' || NEW.project_id::text, 0));
    SELECT COALESCE(max(version),0) INTO current_version FROM po_author_policies WHERE project_id=NEW.project_id;
    IF NEW.version<>current_version+1 THEN
        RAISE EXCEPTION 'PO_AUTHOR_POLICY_STALE' USING ERRCODE='23514';
    END IF;
    SELECT u.name INTO person FROM users u JOIN project_memberships pm ON pm.user_id=u.id
        WHERE u.id=NEW.created_by AND u.company_id=NEW.company_id AND u.is_active AND pm.project_id=NEW.project_id
        AND EXISTS (SELECT 1 FROM roles r WHERE jsonb_exists(pm.role_codes,r.code)
            AND (jsonb_exists(r.capabilities,'project.configure') OR jsonb_exists(r.capabilities,'project.*') OR jsonb_exists(r.capabilities,'*')));
    IF NOT FOUND THEN RAISE EXCEPTION 'PO_AUTHOR_CONFIG_FORBIDDEN' USING ERRCODE='42501'; END IF;
    NEW.actor_name:=person.name;
    NEW.created_at:=CURRENT_TIMESTAMP;
    RETURN NEW;
END $function$;
