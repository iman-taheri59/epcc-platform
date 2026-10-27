# Unified Stock Ledger & Warehouse Cutover — Slice 1 (EN)

> ADR-047 · Status: **ACCEPTED — slice 1 implemented (service-only)** · 2026-10-27
> Migration `2026_10_27_100000_add_unified_stock_ledger.php` · Service `StockLedgerService`
> Legacy gate: `config/epcc.php → warehouse.legacy_writes_refused` (`EPCC_WAREHOUSE_LEGACY_WRITES_REFUSED`)
> Persian twin: `STOCK-LEDGER-FA.md`

## What this is

One append-only journal — `stock_ledger_entries` — that answers **"what does the
controlled warehouse hold?"** for the honest key `(project, holder company, item
code, heat ref or unknown, unit)`. A balance is never stored: it is computed
from the journal (`epcc_stock_balance`), so every number is reproducible and
explainable, entry by entry, in ordinal order.

Five entry kinds:

| Kind | Born from | Authority |
|---|---|---|
| `OPENING_COUNT` | physical count at cutover | dual persons: recorder ≠ approver, approver an active colleague of the holder company, reason mandatory |
| `RECEIPT` | **only** the controlled GRN credit trigger (`piping_accepted_stock_entries` → journal) | the whole MRR→QC→QA→GRN chain (ADR-046); no human re-entry |
| `ISSUE` | service posting | dual persons + reason; refused if balance insufficient |
| `ADJUSTMENT_IN` / `ADJUSTMENT_OUT` | service posting | dual persons + reason; OUT refused if balance insufficient |

## Invariants (all in the database, house style)

1. **Append-only** — UPDATE/DELETE refused by trigger (`55000`); a mistake is a
   new correcting entry, never an edit.
2. **Ordinal clock** — `stock_ledger_clocks` advances by exactly one per entry
   per project (the `epcc_mto_clock_step` pattern); the journal is a total order.
3. **Insert-path depth rules** — a `RECEIPT` is born only inside the GRN credit
   trigger chain; a manual entry only directly. Neither path can be forged from
   the other (`STOCK_LEDGER_RECEIPT_DERIVED_ONLY` / `..._MANUAL_DIRECT_ONLY`).
4. **Frozen receipts** — a RECEIPT must equal its GRN exactly (holder, actor,
   quantity, unit, heat, source line, item identity) and carries a frozen
   `source_snapshot` of the GRN/PO-line facts at post time.
5. **Insufficient-balance refusal** — `ISSUE`/`ADJUSTMENT_OUT` beyond the
   recorded balance raise `STOCK_LEDGER_INSUFFICIENT_BALANCE`, serialized per
   stock key by an advisory transaction lock; concurrent overdraw cannot slip.
6. **Holder-company scoping** — RLS+FORCE: a company reads/journals its own
   entries; explicit receipt readers additionally see GRN-derived receipts
   through the existing `piping_grn_records` visibility — never more.
7. **Idempotency** — manual entries require a client `request_id`
   (unique per project); a retry says "duplicate", it never double-posts.
8. **Forward-only migration** — like the GRN history it extends, `down()`
   refuses: recorded holdings cannot be unmade.

## The legacy cutover gate

The legacy core (`WarehouseService` — receive/issue/deliverToSite/returnToStore/
reverse) is **already closed for PIPING** (service refusal + the
`aa_piping_legacy_stock_cutoff` DB trigger). For every other discipline it stays
open until the owner flips:

```
EPCC_WAREHOUSE_LEGACY_WRITES_REFUSED=true
```

When true, all five legacy stock writes fail closed **before any other
validation** with a bilingual ADR-047 refusal; reads
(`subsystemMaterialStatus`, `uninspectedLots`) stay open — legacy history
remains evidence after it stops being a writer. There is no automatic
translation of legacy lots/movements into the ledger: the bridge is the
**opening physical count** (`OPENING_COUNT`, dual authority, counted-not-copied).
Legacy rows are never silently reconciled.

## Recommended cutover sequence (per project)

1. Freeze a count date; physically count holdings per (item, heat, unit).
2. Post `OPENING_COUNT` entries (recorder + approver, reason = count reference).
3. Verify computed balances against the count sheets.
4. Flip `EPCC_WAREHOUSE_LEGACY_WRITES_REFUSED=true`.
5. From that moment: receipts arrive only via the controlled GRN chain;
   issues/adjustments only via the ledger service.

## Declared boundaries of THIS slice (honesty first)

- **Service-only** — no HTTP surface/UI yet (the MTO-acceptance-slice precedent);
  the ledger is reachable through `StockLedgerService` inside the tenant contract.
- **Person separation, not yet role policy** — any two active colleagues of the
  holder company can record/approve. A `stock_ledger_author_policies` table
  (the `piping_receipt_author_policies` pattern) is the declared next slice.
- **Global flag, not per-project** — cutover granularity per project (an
  authority-routed cutover register) is a declared next slice.
- **Unit strings are exact keys** — `M` and `m` are different buckets; the
  service upper-cases manual units, GRN units come from the source line as-is.
- Piping GRNs remain the only automatic feeder; other disciplines feed the
  ledger manually until their controlled receipt chains exist.

## Tests

`tests/Feature/StockLedgerTest.php` — 10 tests / 81 assertions: exact balances
and one clock; overdraw refusal records nothing; self-approval/blank-reason/bad
quantity/RECEIPT-kind refusals; approver must be an active colleague (service
and DB guard); immutability + derived-only receipts; foreign actor/company
refusal; holder-company scoping (unknown heat is its own bucket); duplicate
request id; **a real controlled GRN credit journals exactly one frozen RECEIPT**;
the cutover flag closes all five legacy writers fail-closed and reopens them.
Regression: `PipingGrnTest` + `WarehouseTest` (50 tests) green alongside.
