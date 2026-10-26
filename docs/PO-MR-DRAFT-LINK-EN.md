> **2026-10-19 follow-on:** [contextual MR→PO action](MR-TO-PO-CONTEXT-ACTION-EN.md) / [فارسی](MR-TO-PO-CONTEXT-ACTION-FA.md). An eligible own-buyer independent PO author can now submit the existing full-copy draft form from MR details. GET remains nonmutating; the POST creates a DRAFT, not a reservation or issue. Earlier “no action controls” statements below describe the prior sheet increment. No authority expansion.

> **Current follow-on ADR-039:** MR-linked financial approval/three-person issuance with full-copy atomic allocation is now implemented and targeted-verified (108/746 PASS); one real two-connection contention scenario is included. [Current delivery guide](PO-MR-ISSUE-ALLOCATION-EN.md). Pending/block statements below describe earlier historical increments; accepted authority and allocation choices are not reopened.

> **Follow-on ADR-038:** controlled linked-draft pricing is now delivered; full-copy issue allocation semantics are ACCEPTED but their implementation is pending. MR-linked financial/issue stays blocked. See [current pricing guide](PO-CONTROLLED-PRICING-EN.md). Earlier statements below describe their historical increment.

> Follow-on: authority model is now explicitly settled in ADR-036 (three distinct people; nominees per buyer from participating companies). [Stage1 nomination configuration](PO-EXECUTION-AUTHORITY-EN.md) is implemented, not financial/issuance operations. Do not treat the earlier “separate decision needed” next-step text below as an unresolved authority question.

# Approved MR → PO draft link — 2026-10-19

**Implemented / targeted-verified.** [ADR-035](ADR-035-MR-TO-PO-DRAFT-LINK-EN.md) · [فارسی](PO-MR-DRAFT-LINK-FA.md).

## Use
In Project → Procurement, expand **PO draft from this MR** in an eligible MR row. Select the participating vendor and optional contractual delivery date (Gregorian input), then create the linked draft. The action is for an **APPROVED, explicitly categorized, own-buyer-company MR with 1–20 lines**. The actor needs independent PO draft nomination; MR authorship alone grants nothing. Existing live membership/discipline/module/archive checks remain in force; final authority is rechecked on submission.

All source lines are copied, in order, from server-read data: bilingual titles/descriptions, discipline, category, exact code/unit/quantity, material identity and subsystem. Header MR ID and each real MR-line ID are linked; duplicate codes are not treated as identity. If an old source has no original quantity spelling, use its stored exact decimal quantity, not an invented original spelling. PO detail shows visible source-line numbers. Related records outside a vendor's scope remain hidden under existing RLS.

**No MR mutation or consumption:** the MR remains APPROVED; quantities, approval signatures and timestamps are unchanged. PO remains DRAFT, unissued and unpriced (unknown total). No quantity reservation, official remaining-quantity calculation or duplicate-purchase prevention is claimed. Multiple drafts from one MR are possible, with a visible warning that they are not multiple approved purchases.

## Integrity / scope
- Only source ID, vendor ID and optional delivery date are accepted at the new POST `/projects/{projectCode}/procurement/orders/from-requisition` (`po_source[...]`). Source title/category/line/quantity/price overrides and partial selection are rejected. The separate manual PO form still rejects arbitrary requisition fields.
- Project and PO-entry locks, a locked MR header and shared source-line locks enclose the existing nominated draft-entry facade, exact copy, number allocation and reference assignment in one transaction. Any late failure rolls back header, lines and counter. The old consuming ProcurementService conversion is deliberately **not** used.
- SQL guards validate the initial full copy and same-company/project/approved/category source, then prevent clearing/reassigning source references or moving a linked source line into another PO. Existing amendment lineage can create a successor retaining the source-line reference; that does not change the MR. This is initial-copy/reference integrity, not universal SQL per-user RBAC, a frozen replay of every field, or an issued-order allocation engine.
- Cross-company import, multiple MRs per PO, partial import, automatic historical classification and pricing/issue/receipt writes are not exposed. Own-company/full-copy limits are bounded implementation scope, not new global procurement prohibitions. Current material-category rules and independent authority decisions are unchanged. Existing trusted legacy primitives remain compatibility paths.
- The forward-only migration adds guards only: no backfill, renumbering, source consumption or permission grants. Older linked records are not retro-certified. No production migration/deployment was performed. G01–G07, MTO authority deferral and the unrelated DocumentRegister allocator defect remain unchanged.

## Verification
**131 affected tests /1067 assertions PASS**, no errors/failures/skips, final run ~174s. Includes **15 new source-copy cases /110 assertions** plus category and populated upgrade, both entry flows/earlier upgrades, PO detail, Procurement, OrderGap and shell regressions. Evidence: `docs/evidence/2026-10-19-po-mr-draft-link/related.{log,xml}`, `summary.json` (10 source hashes), scoped PHP lint (7 files), Pint and diff-check.

New cases cover independent/guest refusal; exact duplicate-code line provenance; unchanged source/approvals; EN/FA UI/detail; spoofed/partial inputs; ineligible current state, unknown category, empty/>20 source; foreign buyer/project boundary; suspension/archive refusal; repeated non-reserving drafts; late-link rollback/counter; reference reassignment and inexact SQL-copy refusal; catalogue/subsystem preservation; original spelling fallback; and source-preserving amendment lineage. Existing populated migration tests also execute the new forward guard migration. No full-suite/recovery, browser smoke, real parallel/crash test, commit/push or deployment claim.

**Next proposed workflow:** issuance, remaining-quantity controls and official order allocation require their separate authority/state design; this draft link must not be promoted to purchasing approval. A searchable catalogue selector remains a smaller usability improvement, not a new MTO permission grant.
