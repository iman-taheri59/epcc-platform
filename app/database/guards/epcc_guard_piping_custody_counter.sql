CREATE OR REPLACE FUNCTION public.epcc_guard_piping_custody_counter()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
BEGIN
 IF pg_trigger_depth()<>2 OR TG_OP='DELETE' THEN RAISE EXCEPTION 'PIPING_CUSTODY_COUNTER_INTERNAL' USING ERRCODE='23514'; END IF;
 RETURN NEW;
END $function$;
