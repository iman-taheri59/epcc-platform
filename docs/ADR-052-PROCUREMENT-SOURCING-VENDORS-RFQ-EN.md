# ADR-052 — Procurement front end: vendor register, prequalification, RFQ and award

Status: **PROPOSED — awaiting owner decisions** (2026-10-26). The mid-cycle (MR→PO: ADR-033…040) is deep and green; the sourcing end does not exist. The SRS VEN persona has no portal or process today.

## Context

Existing assets this ADR builds on: the multi-company identity model (companies, memberships, RLS), MR/PO separation with material categories (ADR-034), PO draft entry and versioned execution (ADR-033/037), BigDecimal pricing allocation (ADR-038), source review (ADR-040), and vendor promise/forecast slip already computed on `PurchaseOrder`. Missing: who the vendors are, whether they may be invited, how offers are requested/compared, and how an award becomes a PO draft.

## Decision points (explicit owner choices required)

1. `vendor_model=company_subtype | separate_vendor_register_linked_to_companies`
   Recommended: **company_subtype** — a vendor IS a company (tenant) with a `VEN` type flag and a qualification record; reuses identity/RLS entirely and prepares the ADR-058 portal.
2. `prequalification=structured_scoped_lists | binary_approved_flag | matrix_per_material_category`
   Recommended: **matrix_per_material_category** — approval is per (vendor, material category/discipline, project or corporate level) with validity windows and append-only status history; AVL (approved vendor list) becomes a query, not a document.
3. `rfq_flow=platform_full_cycle | external_register_mode | both_per_ADR031_precedent`
   Recommended: **both_per_ADR031_precedent** — IN_SYSTEM (RFQ issued, offers entered by vendor portal or buyer-on-behalf with provenance) and EXTERNAL_REGISTER (offers received by email/paper registered with identity + files), exactly like the accepted TQ/NCR two-mode decision. Never present registered offers as platform-collected.
4. `bid_comparison=structured_tabulation_with_award_recommendation | free_form_attachment`
   Recommended: **structured_tabulation** — commercial (prices mapped to MR lines via the existing allocation machinery) + technical (acceptance criteria checklist) side-by-side, clarification rounds append-only, award recommendation routed through ADR-049 chained approval once available (interim: existing PO financial/issue authority pattern of ADR-036).
5. `award_to_po=creates_po_draft | creates_po_directly`
   Recommended: **creates_po_draft** — award converts into the existing PO draft entry flow (ADR-033), keeping versioned execution and source review untouched; the RFQ/award lineage is bound into the PO (digest of the awarded tabulation).

## Sketch of the decision

- Tables (RLS + FORCE): vendor qualifications (per category, windowed, dual-authorized status changes), `rfqs` + `rfq_line` (bound to MR lines/material categories), `vendor_offers` + `offer_line` (append-only versions), `bid_tabulations` (snapshot, digest-bound), `award_decisions` (approval chain references, lineage to PO draft).
- Vendor rating inputs already derivable: promise slip (PO), QC/QA outcomes on receipts (ADR-046), NDT/MTR quality once ADR-048 lands — expose as a computed rating, never a mutable score without journal.
- Cross-company visibility follows RLS: a vendor sees its own RFQs/offers only; buyers see the tabulation; losers see their own outcome (configurable per project policy — a real tender-law concern in Iran: `outcome_disclosure=own_only | all_bidders_ranked`).

## Consequences

- Completes the procurement narrative: demand (MTO/MR) → sourcing (this) → order (ADR-033…040) → receipt (ADR-046) → money (ADR-053).
- The structured tabulation reuses pricing allocation semantics — consistency win; but bid data is commercially sensitive: RLS must extend to *within* the buyer company (need-to-know), a new granularity.
- Test surface: category-scoped qualification enforcement at RFQ invitation, offer immutability after deadline, tabulation determinism, award→PO lineage digest, disclosure-policy boundaries.

## Out of scope

E-auction/reverse bidding, framework/blanket agreements with call-offs (candidate later ADR), vendor portal UI beyond data entry (ADR-058), customs/logistics tracking.
