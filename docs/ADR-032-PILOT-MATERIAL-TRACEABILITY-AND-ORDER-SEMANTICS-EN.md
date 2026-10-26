> **Supplied receipt reference / user policy — 2026-10-22:** [receipt-to-storage design](PIPING-RECEIPT-STORAGE-DESIGN-EN.md) / [فارسی](PIPING-RECEIPT-STORAGE-DESIGN-FA.md), [ADR-046](ADR-046-PIPING-RECEIPT-STORAGE-EN.md). Independent physical custody plus controlled acceptance/no ordinary HOLD or REJECTED issue accepted; authority matrix must be approved before activation. Only this boundary supersedes ADR-032 R4’s blanket no-new-gate statement; other pending rules remain unapproved. Current selected work is reference-driven design, not the prior allocator recommendation or receipt activation. No app/schema/grant change; structured prerequisite items remain deferred.

# ADR-032 — Pilot material traceability and completed-order semantics

Status: **PROPOSED — source findings and candidate requirements; not approved or implemented**.
Date: 2026-10-18.
Depends on: ADR-014, ADR-015, ADR-018, ADR-024; accepted MTO scope/revision principles remain unchanged.
Companion: `ADR-032-PILOT-MATERIAL-TRACEABILITY-AND-ORDER-SEMANTICS-FA.md`.

## Context and source evidence

Preparing the piping pilot exposed integration boundaries that are not proved by the existing component tests:

1. `OrderGapService::EFFECTIVE_STATES` includes only ISSUED and PARTIALLY_RECEIVED; `WarehouseService` can move the same PO to RECEIVED. The accepted phrase in ADR-015 §5 is issued and not cancelled, while its §9 implementation record adopts the narrower receipt-acceptance membership. Receipt eligibility and historical ordered coverage are different concepts; this inconsistency needs an explicit reconciliation, not an unannounced code change.
2. The lot model has a nullable purchase_order_item_id, but the current receive service accepts a PO header and free-text code/unit and does not write that FK. Repeated codes and legacy nulls cannot be treated as exact provenance.
3. Legacy subsystem material status credits SITE movements by item_code for each PO item and totals quantities across units. Legacy PO receipt progress sums every item row, including the predecessor retained by an amendment. These are source-reviewed risks; dedicated new runtime reproductions have not been run in this session.
4. Existing accepted-MTO gross aggregation already has its own effective-revision/overlap gap, recorded in ADR-024. This ADR must not create a second demand authority or reopen closed scope principles.

Exact scenario IDs and source references: `PIPING-PILOT-ACCEPTANCE-MATRIX-EN.md` PP-14–18/G02–G06. This ADR does not claim an authorization exploit or a direct mechanical-completion release bypass.

## Candidate requirements for approval

### R1 — Separate ordered coverage from receipt eligibility

Recommended: a fully received, noncancelled PO remains evidence of the quantity ordered for gross-versus-order coverage, reading only applicable amendment lineage heads. A fully received PO remains ineligible for further ordinary receipts under the existing receipt rule. Draft and cancelled orders still contribute zero. Explicitly amend ADR-015's implementation record and add issued→partial→received coverage tests if this interpretation is accepted.

Alternative: keep an outstanding-open-order report that excludes RECEIVED, but name it accordingly and keep it separate from gross procurement-order coverage. Do not present either as physical usable stock.

### R2 — Exact physical source attribution

Recommended: new controlled receipts carry an explicit same-project PO-item/lineage reference with compatible source unit and server validation. Preserve original source fields and legacy unknowns. No fuzzy matching or backfill from item_code/description alone. Amendment receipts and receipts already posted before an amendment need a documented lineage attribution rule; do not move historical ledger evidence silently.

This is a proposed future write-path change, not permission to expose an endpoint, grant a role or reinterpret every existing internal fixture. Before implementation, settle the human surface and authorization using the project's existing rules or an explicit approved change.

### R3 — Per-identity/unit/scope physical arithmetic

Recommended: deliveries cannot be credited to more than their explicit originating item/allocation; per-line/lineage receipt progress must not add incompatible units or sum superseded demand. Return and reversal behavior must be included in the agreed ledger rule. Preserve audit history and mark unknown attribution explicitly instead of returning a false zero gap or false completeness.

Do not redefine the previously accepted meaning of no-material-required subsystems without an explicit decision. Do not infer a release gate from a legacy display total.

### R4 — Do not invent usable-stock policy

Physical receipt remains allowed when inspection fails or is missing, with the existing advisory warning. Received, issued, delivered, inspection PASS and usable are not interchangeable. The definition of usable stock and the parties who may release a hold, verify evidence or reserve stock remain open; no new hard inspection gate or automatic approval is authorized here.

## Options

- **A — Recommended:** make the report concepts explicit, reproduce the boundary cases, then implement bounded exact-attribution and lineage-aware slices under approved policy.
- **B — Keep legacy arithmetic with explicit limitations:** suitable only for a deliberately restricted demo with nonrepeated codes/single units/single revision; not acceptance of operational correctness.
- **C — Rewrite procurement/warehouse wholesale:** not recommended; it risks losing existing audit/tenant constraints and does not remove the need to decide provenance and authority.

## Open questions (not previously closed MTO questions)

1. Confirm R1: does completed receipt leave historical effective ordered quantity intact, with a separate outstanding-order view if needed?
2. How are receipts linked across later PO-line amendments, cancellations after partial delivery and returns? Which exact lineage/allocation IDs are authoritative?
3. How should legacy unlinked lots be reported and explicitly reconciled, by whom and with what append-only evidence?
4. What constitutes usable stock and who can authorize its release/reservation for a given scope? Do not silently reuse PMS/MR author nominations for this.

## Verification plan (not executed as new pilot tests here)

- Gross100, issued80 gives order gap20; receiving30 then50 does not turn already-ordered80 into zero if R1 is approved. The fully received order still rejects an extra ordinary receipt.
- Two 10EA PO lines share a code; one line's delivered10 cannot satisfy both. Wrong-project item IDs and incompatible units fail atomically.
- Amendment80→90 does not produce demanded170; receipts posted before and after amendment follow the agreed provenance rule, not an inferred code match.
- Mixed M/EA line progress never uses one sum of their quantities. Reversal/return accounting preserves history and does not erase original events.
- Missing/failed inspection still allows a physical receipt and remains visible; no usable or release decision is inferred.
- Tenant-negative, archive-negative, human-authority and rollback/concurrency paths accompany any new human write surface.

## Falsifiers / reconsideration

Revisit the chosen design if a project permits partial amendment acceptance, over-delivery tolerance, vendor substitution or cross-scope stock transfer requiring a different explicit allocation grain. Such needs do not justify silently changing source identity or folding usable quantity into order gap.

## Implementation status

Documentation only. No production code, schema, grants, receipt transitions, MTO permissions or approval gates changed for this proposal. The first recommended development slice remains ADR-024 effective accepted-MTO revision/scope selection, before relying on any downstream combined pilot figure.
