CREATE OR REPLACE FUNCTION public.epcc_guard_piping_posted_index()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
 IF TG_OP<>'INSERT' OR pg_trigger_depth()<>2 THEN RAISE EXCEPTION 'PIPING_POSTED_INDEX_DERIVED_ONLY' USING ERRCODE='23514'; END IF; RETURN NEW;
END $function$;
