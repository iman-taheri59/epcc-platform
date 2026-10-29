CREATE OR REPLACE FUNCTION public.epcc_guard_piping_stock_credit()
 RETURNS trigger
 LANGUAGE plpgsql
AS $function$
DECLARE g piping_grn_records%ROWTYPE;
BEGIN
 IF TG_OP<>'INSERT' OR pg_trigger_depth()<>2 THEN RAISE EXCEPTION 'PIPING_STOCK_DERIVED_IMMUTABLE' USING ERRCODE='23514'; END IF;
 SELECT * INTO g FROM piping_grn_records WHERE id=NEW.grn_id AND project_id=NEW.project_id;
 IF NOT FOUND THEN RAISE EXCEPTION 'PIPING_STOCK_PARENT' USING ERRCODE='42501'; END IF;
 NEW.quantity:=g.quantity; NEW.unit:=g.unit; NEW.custodian_company_id:=g.custodian_company_id; NEW.created_at:=g.created_at; RETURN NEW;
END $function$;
