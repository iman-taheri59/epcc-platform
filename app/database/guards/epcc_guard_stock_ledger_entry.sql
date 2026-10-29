CREATE OR REPLACE FUNCTION public.epcc_guard_stock_ledger_entry()
 RETURNS trigger
 LANGUAGE plpgsql
 SET search_path TO 'public', 'pg_catalog'
AS $function$
DECLARE n bigint; g piping_grn_records%ROWTYPE; line purchase_order_items%ROWTYPE; pcode text;
BEGIN
  -- Serialize balance decisions per stock key; the ledger is the only balance truth.
  PERFORM pg_advisory_xact_lock(hashtextextended('stock-ledger:' || NEW.project_id::text || ':' || NEW.company_id::text
    || ':' || NEW.item_code || ':' || COALESCE(NEW.heat_ref, '') || ':' || NEW.unit, 0));
  SELECT code INTO pcode FROM projects WHERE id = NEW.project_id FOR NO KEY UPDATE;
  IF NOT FOUND OR NOT epcc_project_is_writable(NEW.project_id) THEN
    RAISE EXCEPTION 'STOCK_LEDGER_PROJECT_NOT_WRITABLE' USING ERRCODE = '42501';
  END IF;
  IF NEW.kind = 'RECEIPT' THEN
    SELECT * INTO g FROM piping_grn_records WHERE id = NEW.grn_id AND project_id = NEW.project_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'STOCK_LEDGER_RECEIPT_SOURCE' USING ERRCODE = '42501'; END IF;
    SELECT * INTO line FROM purchase_order_items WHERE id = g.source_line_id AND project_id = g.project_id;
    IF NOT FOUND THEN RAISE EXCEPTION 'STOCK_LEDGER_RECEIPT_SOURCE' USING ERRCODE = '42501'; END IF;
    -- The entry must be exactly the GRN credit: holder, actor, quantity, unit, heat,
    -- source line and the source line's own item identity. No reinterpretation.
    IF NEW.company_id IS DISTINCT FROM g.custodian_company_id
       OR NEW.recorded_by IS DISTINCT FROM g.posted_by
       OR NEW.quantity <> g.quantity OR NEW.unit <> g.unit
       OR NEW.heat_ref IS DISTINCT FROM g.heat_ref
       OR NEW.purchase_order_item_id IS DISTINCT FROM g.source_line_id
       OR NEW.item_code IS DISTINCT FROM line.item_code
       OR NEW.material_item_id IS DISTINCT FROM line.material_item_id THEN
      RAISE EXCEPTION 'STOCK_LEDGER_RECEIPT_MISMATCH' USING ERRCODE = '23514';
    END IF;
  ELSE
    IF NEW.project_id IS DISTINCT FROM NULLIF(current_setting('epcc.project_id', true), '')::uuid
       OR NEW.company_id IS DISTINCT FROM NULLIF(current_setting('epcc.company_id', true), '')::uuid
       OR NEW.recorded_by IS DISTINCT FROM NULLIF(current_setting('epcc.stock_actor_id', true), '')::uuid THEN
      RAISE EXCEPTION 'STOCK_LEDGER_AUTHORITY' USING ERRCODE = '42501';
    END IF;
    -- Dual person authority: the approver is an active colleague of the holder company.
    -- Role-scoped authority policies are the declared next slice (see guide); person
    -- separation plus company binding is what THIS slice enforces, everywhere.
    IF NOT EXISTS (SELECT 1 FROM users u WHERE u.id = NEW.approved_by
                   AND u.company_id = NEW.company_id AND u.is_active) THEN
      RAISE EXCEPTION 'STOCK_LEDGER_APPROVER_NOT_COLLEAGUE' USING ERRCODE = '42501';
    END IF;
    -- You cannot issue or adjust away what the ledger does not hold for this key.
    IF NEW.kind IN ('ISSUE', 'ADJUSTMENT_OUT')
       AND NEW.quantity > epcc_stock_balance(NEW.project_id, NEW.company_id, NEW.item_code, NEW.heat_ref, NEW.unit) THEN
      RAISE EXCEPTION 'STOCK_LEDGER_INSUFFICIENT_BALANCE' USING ERRCODE = '23514';
    END IF;
  END IF;
  INSERT INTO stock_ledger_clocks(project_id, last_ordinal) VALUES (NEW.project_id, 1)
    ON CONFLICT (project_id) DO UPDATE SET last_ordinal = stock_ledger_clocks.last_ordinal + 1
    RETURNING last_ordinal INTO n;
  NEW.ordinal := n;
  RETURN NEW;
END; $function$;
