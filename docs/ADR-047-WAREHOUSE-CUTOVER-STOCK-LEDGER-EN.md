# ADR-047 — Warehouse core cutover and the single stock ledger

Status: **ACCEPTED (2026-10-27)** — owner blanket instruction to start execution ("انجام کارها رو شروع کن"); the four recommended options are adopted as the decisions: `warehouse_target_core=controlled_core_only`, `legacy_disposition=read_only_then_retire`, `stock_ledger_model=append_only_projection`, `opening_balances=physical_count_required`. The owner may revise any key at any time; a revision is an append-only amendment to this ADR, never a silent edit. Closes the open debt recorded as B6 in the 2026-10 reviews and repeatedly deferred in ADR-046 banners ("legacy warehouse cutover remains unimplemented").

Implementation record — **slice 1 (service-only), 2026-10-27, commit `b648866`**: migration `app/database/migrations/2026_10_27_100000_add_unified_stock_ledger.php` (forward-only), `StockLedgerService`, legacy gate `epcc.warehouse.legacy_writes_refused` on all five `WarehouseService` stock writers, `StockLedgerTest` 10 tests / 81 assertions green, `PipingGrnTest` + `WarehouseTest` regression green (60 tests / 265 assertions together). Guides: `docs/STOCK-LEDGER-EN.md` / `docs/STOCK-LEDGER-FA.md`. Declared next slices: role-scoped `stock_ledger_author_policies`, per-project cutover register, HTTP/UI surface (TODO item A.2b).

## Context

Two material cores are live in parallel:

- **Legacy core** — `WarehouseService` with `MaterialItem` / `MaterialLot` / `MaterialMovement` / `MaterialInspection`, surfaced through `MaterialsController`. Movements are mutable-path warehouse operations.
- **Controlled core (branch `arena/piping-material-cycle-2026-10-26`, now on main)** — physical custody with heat amendments (`PipingCustodyService`, `PipingCustodyAmendmentService`), the MRR→QC→QA→GRN receipt chain (ADR-046), forward-only controlled GRN with accepted-stock history (`2026_10_25_110000_add_controlled_piping_grns`, down() throws by design), and receiving reviews.

There is **no stock balance ledger**: nothing answers "how many of item X, lot/heat H, in location L, in state S, are on hand right now" as an append-only projection of accepted GRNs minus issues. There is no material preservation process and no over/short reconciliation. Every day without a cutover decision grows both cores.

## Decision points (explicit owner choices required)

1. `warehouse_target_core=controlled_core_only | dual_core_with_bridge | legacy_core_modernized`
   Recommended: **controlled_core_only** — the controlled core already carries the discipline (forward-only, separation of duties, heat identity) the platform is built on.
2. `legacy_disposition=read_only_then_retire | hard_cutover_at_date | keep_indefinitely`
   Recommended: **read_only_then_retire** — legacy screens switch to read-only at cutover, historical movements stay queryable, writes move to the controlled core; retirement (drop write paths entirely) one increment later.
3. `stock_ledger_model=append_only_projection | snapshot_table_with_journal`
   Recommended: **append_only_projection** — a `stock_entries` journal (GRN acceptance +, issue −, adjustment ± with reason codes and dual authorization) plus a materialized balance view; consistent with the MTO acceptance journal pattern (`epcc_mto_clock_step` ordinal clock).
4. `opening_balances=physical_count_required | import_legacy_balances_verified | start_at_zero`
   Recommended: **physical_count_required** for active projects (with an import-assist sheet reconciled against legacy balances; discrepancies become adjustment entries with reason codes).

## Sketch of the decision

- New migrations (RLS + FORCE per house style): `stock_entries` append-only journal; `stock_balances` projection (or a STABLE SQL function); issue/adjustment authority rows reusing the ADR-046 nomination pattern (owner-configured, person-separation).
- `WarehouseService` write methods raise a fail-closed refusal after the cutover flag; reads remain.
- Custody→GRN chain posts the opening `+` entries; material issues (to line/joint/work package) post `−` entries referencing the consumer.
- Over/short = adjustment entry type with mandatory reason + second authorization.

## Consequences

- Closes the "two parallel truths" risk; enables inventory answers, preservation scheduling (balances + age), and later cost actuals (GRN valuation → ADR-054).
- Migration effort is real: legacy movement history must map to journal entries or stay read-only history (recommended: stay read-only; do not rewrite history).
- Test surface: concurrency (issue vs GRN), separation of duties, RLS, fail-closed legacy writes, balance invariant (Σ entries = balance).

## Out of scope (this ADR)

Vendor consignment stock, inter-warehouse transfers as a first-class process, barcode/RFID capture (see ADR-056 for field capture), costing (ADR-054).
