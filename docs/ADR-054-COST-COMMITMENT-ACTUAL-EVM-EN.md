# ADR-054 — Cost control: commitments, actuals and lightweight earned value

Status: **PROPOSED — awaiting owner decisions** (2026-10-26). COM$ scores 0.5 today (PO pricing exists; `PaymentCertificate` is demo-only). Principle: derive cost from data the platform already produces — do not build an accounting system.

## Context

The platform already holds every input a lightweight cost engine needs: budgets are the only missing seed. PO pricing allocation is exact BigDecimal per MR line (ADR-038); PO versions carry financial authority (ADR-036/037); GRNs value accepted receipts (ADR-046); invoices/matches/certificates arrive with ADR-053; physical progress arrives with ADR-051. What does not exist: a budget/CBS to compare against, commitment tracking, and any earned-value view.

## Decision points (explicit owner choices required)

1. `budget_structure=cbs_mirror_of_schedule_wbs | independent_cost_breakdown | import_from_external`
   Recommended: **cbs_mirror_of_schedule_wbs** — the cost breakdown mirrors the imported schedule WBS (ADR-051 activity hierarchy), so cost and progress join on the same spine; budget lines are versioned snapshots (append-only revisions with authority, like PMS snapshots).
2. `commitment_semantics=po_version_current | pr_po_pipeline_stages`
   Recommended: **pr_po_pipeline_stages** — commitment ladder: estimate (engineering estimate rows exist) → PR/MR → RFQ award → PO issued → change orders; each stage updates the committed figure via journal entries, never by overwriting (reproducible "committed as of T").
3. `actuals_source=grn_valuation_and_invoices | invoices_only`
   Recommended: **grn_valuation_and_invoices** — accrued actual at GRN (PO price × accepted quantity), settled actual at matched invoice/certificate (ADR-053); the gap between them is a first-class report (accruals/gr-in-inspection), not an error.
4. `evm_depth=bcws_bcwp_acwp_core_indices | full_evm_with_forecasting`
   Recommended: **bcws_bcwp_acwp_core_indices** — PV from ADR-051 planned progress × budget; EV from event-based physical progress × budget; AC from actuals; SPI/CPI/VAC per WBS node and project. Forecasting (ETC/EAC models) deferred until two reporting periods of history exist.
5. `currency_policy=project_base_currency_with_rate_snapshots`
   Recommended: fixed — reuse ADR-053 dated rate snapshots; every derived figure stores the rate snapshot digest it used.

## Sketch of the decision

- Tables (RLS + FORCE): `budget_revisions` (CBS-node bound, versioned, authority-routed), `cost_journal` (append-only entries: commitment stages, GRN accruals, invoice settlements, adjustments with reason codes — ordinal clock like the MTO journal), materialized per-node rollups as STABLE functions/projections (never a mutable balance without journal).
- Every index (SPI/CPI/VAC) is a pure function of (budget revision, cost journal cutoff, progress snapshot) — recomputable and explainable, in the platform's honesty idiom: reports show their inputs.
- Dashboards share the ADR-049 inbox projection layer: budget-vs-commitment-vs-actual aging and overrun alerts become inbox items for the right roles.

## Consequences

- Project controls triangle completes: scope (MTO/admission, exists) + time (ADR-051) + cost (this) → real EVM without a finance module.
- The append-only cost journal is audit gold: every number in a management report traces to entries and digests.
- Test surface: journal invariant (Σ = rollup), commitment stage ordering, accrual→settlement transition, rate-snapshot determinism, BigDecimal exactness end-to-end, RLS across owner/EPC finance roles.

## Out of scope

General ledger, payroll/own-force labour cost (needs ADR-055 field data first), cash-flow forecasting, multi-project portfolio funding, invoicing itself (ADR-053).
