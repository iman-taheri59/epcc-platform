CREATE OR REPLACE FUNCTION public.epcc_guard_material_review_run()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
    BEGIN
        IF NOT EXISTS (SELECT 1 FROM project_fitting_definitions d JOIN fitting_review_policies p
            ON p.project_id=d.project_id AND p.material_family=d.material_family
            WHERE d.id=NEW.definition_id AND p.id=NEW.policy_id AND d.project_id=NEW.project_id)
        THEN RAISE EXCEPTION 'Policy material family mismatch' USING ERRCODE='42501'; END IF;
        RETURN NEW;
    END;
    $function$;
