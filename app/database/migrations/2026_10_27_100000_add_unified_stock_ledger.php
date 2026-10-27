<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-047 — the unified stock ledger (slice 1: service-only, no UI).
 *
 * One append-only journal for what the controlled warehouse holds:
 *   OPENING_COUNT  physical count at cutover (dual person authority)
 *   RECEIPT        derived-only, written by the controlled GRN credit trigger
 *   ISSUE          out to site/consumption (dual person authority)
 *   ADJUSTMENT_IN / ADJUSTMENT_OUT  corrections (dual person authority, reason)
 *
 * Invariants live in the database, in house style: an ordinal clock that can
 * only advance by one, insert-path depth rules (a RECEIPT is only ever born
 * from the GRN credit path, a manual entry is only ever direct), an
 * insufficient-balance guard that refuses to issue what the ledger does not
 * hold, RLS+FORCE keyed to the holder company, and total immutability — a
 * mistake is corrected with a new entry, never by editing one.
 *
 * Identity key for balances: (project, holder company, item_code, heat_ref,
 * unit) — heat_ref participates with IS NOT DISTINCT FROM semantics; an
 * unknown heat is its own honest bucket and never merges with a known one.
 *
 * Forward-only, exactly like the controlled GRN history it extends.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_ledger_clocks', function (Blueprint $t): void {
            $t->uuid('project_id')->primary();
            $t->bigInteger('last_ordinal');
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
        });

        Schema::create('stock_ledger_entries', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            // The HOLDER of the stock line being moved (custodian company for receipts).
            $t->uuid('company_id');
            $t->bigInteger('ordinal');
            $t->string('kind', 16);
            $t->string('item_code', 96);
            $t->string('heat_ref', 200)->nullable();
            $t->string('unit', 16);
            $t->decimal('quantity', 18, 3);
            // Optional catalogue enrichment; the honest key is (item_code, heat_ref, unit).
            $t->uuid('material_item_id')->nullable();
            $t->uuid('purchase_order_item_id')->nullable();
            $t->uuid('grn_id')->nullable()->unique();
            // Client-generated idempotency key for manual entries; receipts dedupe on grn_id.
            $t->uuid('request_id')->nullable();
            $t->uuid('recorded_by');
            $t->uuid('approved_by')->nullable();
            $t->text('reason')->nullable();
            $t->jsonb('source_snapshot')->nullable();
            $t->timestampTz('recorded_at', 6)->useCurrent();
            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('material_item_id')->references('id')->on('material_items')->restrictOnDelete();
            $t->foreign('purchase_order_item_id')->references('id')->on('purchase_order_items')->restrictOnDelete();
            $t->foreign('grn_id')->references('id')->on('piping_grn_records')->restrictOnDelete();
            $t->foreign('recorded_by')->references('id')->on('users')->restrictOnDelete();
            $t->foreign('approved_by')->references('id')->on('users')->restrictOnDelete();
            $t->unique(['project_id', 'ordinal']);
            $t->unique(['project_id', 'request_id']);
            $t->unique(['project_id', 'id']);
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE stock_ledger_clocks ADD CHECK(last_ordinal > 0);

ALTER TABLE stock_ledger_entries ADD CHECK(ordinal > 0 AND quantity > 0);
ALTER TABLE stock_ledger_entries ADD CHECK(kind IN ('OPENING_COUNT','RECEIPT','ISSUE','ADJUSTMENT_IN','ADJUSTMENT_OUT'));
ALTER TABLE stock_ledger_entries ADD CHECK(item_code ~ '[^[:space:]]' AND length(item_code) <= 96 AND unit ~ '[^[:space:]]' AND length(unit) <= 16);
-- A receipt is exactly a GRN credit: no request_id, no human approver/reason, a frozen snapshot instead.
ALTER TABLE stock_ledger_entries ADD CHECK((kind = 'RECEIPT') = (grn_id IS NOT NULL));
ALTER TABLE stock_ledger_entries ADD CHECK((kind = 'RECEIPT') = (request_id IS NULL));
ALTER TABLE stock_ledger_entries ADD CHECK(kind <> 'RECEIPT' OR (approved_by IS NULL AND reason IS NULL AND source_snapshot IS NOT NULL));
-- Manual entries carry dual person authority and a non-blank reason; corrections are new entries, never edits.
ALTER TABLE stock_ledger_entries ADD CHECK(kind = 'RECEIPT' OR (approved_by IS NOT NULL AND approved_by <> recorded_by
  AND reason IS NOT NULL AND length(reason) <= 4000 AND reason ~ '[^[:space:]]' AND source_snapshot IS NULL));

ALTER TABLE stock_ledger_clocks ENABLE ROW LEVEL SECURITY;
ALTER TABLE stock_ledger_clocks FORCE ROW LEVEL SECURITY;
CREATE POLICY stock_clock_use ON stock_ledger_clocks FOR ALL
  USING (project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
  WITH CHECK (project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid);

ALTER TABLE stock_ledger_entries ENABLE ROW LEVEL SECURITY;
ALTER TABLE stock_ledger_entries FORCE ROW LEVEL SECURITY;
-- The holder company reads its own journal; explicit receipt readers additionally see
-- GRN-derived receipts through the existing piping_grn_records visibility, never more.
CREATE POLICY stock_ledger_read ON stock_ledger_entries FOR SELECT USING (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND (company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
       OR EXISTS (SELECT 1 FROM piping_grn_records g
                  WHERE g.id = stock_ledger_entries.grn_id
                    AND g.project_id = stock_ledger_entries.project_id)));
CREATE POLICY stock_ledger_insert ON stock_ledger_entries FOR INSERT WITH CHECK (
  project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
  AND ((kind <> 'RECEIPT'
        AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
        AND recorded_by = NULLIF(current_setting('epcc.stock_actor_id', true), '')::uuid)
    OR (kind = 'RECEIPT'
        AND EXISTS (SELECT 1 FROM piping_grn_records g
                    WHERE g.id = stock_ledger_entries.grn_id
                      AND g.project_id = stock_ledger_entries.project_id
                      AND g.posted_by = NULLIF(current_setting('epcc.receipt_actor_id', true), '')::uuid
                      AND g.custodian_company_id = stock_ledger_entries.company_id))));

CREATE OR REPLACE FUNCTION epcc_stock_balance(p_project uuid, p_company uuid, p_item text, p_heat text, p_unit text)
RETURNS numeric LANGUAGE sql STABLE SET search_path = public, pg_catalog AS $$
  SELECT COALESCE(SUM(CASE WHEN e.kind IN ('OPENING_COUNT','RECEIPT','ADJUSTMENT_IN') THEN e.quantity ELSE -e.quantity END), 0)
  FROM stock_ledger_entries e
  WHERE e.project_id = p_project AND e.company_id = p_company
    AND e.item_code = p_item AND e.heat_ref IS NOT DISTINCT FROM p_heat AND e.unit = p_unit
$$;

CREATE OR REPLACE FUNCTION epcc_stock_clock_step() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  IF TG_OP = 'DELETE' THEN RAISE EXCEPTION 'stock clock cannot be deleted' USING ERRCODE = '55000'; END IF;
  IF TG_OP = 'INSERT' THEN
    IF NEW.last_ordinal <> 1 THEN RAISE EXCEPTION 'stock clock starts at one' USING ERRCODE = '23514'; END IF;
  ELSIF NEW.project_id <> OLD.project_id OR NEW.last_ordinal <> OLD.last_ordinal + 1 THEN
    RAISE EXCEPTION 'stock clock must advance by one' USING ERRCODE = '23514';
  END IF;
  RETURN NEW;
END; $$;
CREATE TRIGGER stock_clock_step BEFORE INSERT OR UPDATE OR DELETE ON stock_ledger_clocks
  FOR EACH ROW EXECUTE FUNCTION epcc_stock_clock_step();

-- Integrity guard for the insert paths, NOT an authority policy:
-- a RECEIPT is born only inside the GRN credit trigger chain (depth 3),
-- a manual entry only directly from the application (depth 1).
CREATE OR REPLACE FUNCTION epcc_stock_entry_insert_path() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  IF NEW.kind = 'RECEIPT' AND pg_trigger_depth() <> 3 THEN
    RAISE EXCEPTION 'STOCK_LEDGER_RECEIPT_DERIVED_ONLY' USING ERRCODE = '55000';
  END IF;
  IF NEW.kind <> 'RECEIPT' AND pg_trigger_depth() <> 1 THEN
    RAISE EXCEPTION 'STOCK_LEDGER_MANUAL_DIRECT_ONLY' USING ERRCODE = '55000';
  END IF;
  RETURN NEW;
END; $$;
CREATE TRIGGER stock_entry_insert_path BEFORE INSERT ON stock_ledger_entries
  FOR EACH ROW EXECUTE FUNCTION epcc_stock_entry_insert_path();

CREATE OR REPLACE FUNCTION epcc_reject_stock_ledger_mutation() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
BEGIN
  RAISE EXCEPTION 'stock ledger is append-only' USING ERRCODE = '55000';
END; $$;
CREATE TRIGGER stock_ledger_immutable BEFORE UPDATE OR DELETE ON stock_ledger_entries
  FOR EACH ROW EXECUTE FUNCTION epcc_reject_stock_ledger_mutation();

CREATE OR REPLACE FUNCTION epcc_guard_stock_ledger_entry() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
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
END; $$;
CREATE TRIGGER stock_ledger_guard BEFORE INSERT ON stock_ledger_entries
  FOR EACH ROW EXECUTE FUNCTION epcc_guard_stock_ledger_entry();

-- The controlled GRN credit journals itself: one accepted-stock credit produces
-- exactly one RECEIPT entry, frozen from the GRN and its source line at post time.
CREATE OR REPLACE FUNCTION epcc_record_stock_receipt_from_grn() RETURNS trigger
LANGUAGE plpgsql SET search_path = public, pg_catalog AS $$
DECLARE g piping_grn_records%ROWTYPE; line purchase_order_items%ROWTYPE; snap jsonb;
BEGIN
  SELECT * INTO g FROM piping_grn_records WHERE id = NEW.grn_id AND project_id = NEW.project_id;
  IF NOT FOUND THEN RAISE EXCEPTION 'STOCK_LEDGER_RECEIPT_SOURCE' USING ERRCODE = '42501'; END IF;
  SELECT * INTO line FROM purchase_order_items WHERE id = g.source_line_id AND project_id = g.project_id;
  IF NOT FOUND THEN RAISE EXCEPTION 'STOCK_LEDGER_RECEIPT_SOURCE' USING ERRCODE = '42501'; END IF;
  snap := jsonb_build_object(
    'grn_number', g.number, 'grn_reason', g.reason, 'location_ref', g.location_ref,
    'purchase_order_id', g.purchase_order_id, 'purchase_order_item_id', g.source_line_id,
    'custody_id', g.custody_id, 'qa_id', g.qa_id, 'review_id', g.review_id,
    'amendment_id', g.amendment_id, 'custodian_company_id', g.custodian_company_id,
    'quantity', g.quantity, 'unit', g.unit, 'heat_ref', g.heat_ref,
    'item_code', line.item_code, 'material_item_id', line.material_item_id);
  INSERT INTO stock_ledger_entries(id, project_id, company_id, kind, item_code, heat_ref, unit, quantity,
    material_item_id, purchase_order_item_id, grn_id, recorded_by, source_snapshot)
  VALUES (gen_random_uuid(), NEW.project_id, g.custodian_company_id, 'RECEIPT', line.item_code, g.heat_ref,
    g.unit, g.quantity, line.material_item_id, g.source_line_id, NEW.grn_id, g.posted_by, snap);
  RETURN NEW;
END; $$;
CREATE TRIGGER record_stock_receipt AFTER INSERT ON piping_accepted_stock_entries
  FOR EACH ROW EXECUTE FUNCTION epcc_record_stock_receipt_from_grn();
SQL);

        // Freeze the invoker metadata contract for this slice, in house style: any later
        // added/changed SELECT policy on the ledger fails the install instead of silently
        // widening who can read a balance.
        $policies = DB::scalar("SELECT array_agg(polname::text ORDER BY polname)::text FROM pg_policy WHERE polrelid = 'stock_ledger_entries'::regclass AND polcmd IN ('r', '*')");
        if ($policies !== '{stock_ledger_read}') {
            throw new RuntimeException('Unexpected stock ledger read policy; reconcile before installing.');
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The unified stock ledger is forward-only: recorded holdings history cannot be unmade.');
    }
};
