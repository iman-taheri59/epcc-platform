> **2026-10-19 human-surface clarification:** [OrderGap boundary](ORDER-GAP-HUMAN-BOUNDARY-EN.md) / [فارسی](ORDER-GAP-HUMAN-BOUNDARY-FA.md). Controlled MR-linked human quantity amendment/re-attribution through OrderGap is now refused. Trusted lineage primitives remain available internally, not as human editing grants. Broader legacy/manual/MTO authority redesign remains deferred.

> **Current follow-on ADR-039:** MR-linked financial approval/three-person issuance with full-copy atomic allocation is now implemented and targeted-verified (108/746 PASS); one real two-connection contention scenario is included. [Current delivery guide](PO-MR-ISSUE-ALLOCATION-EN.md). Pending/block statements below describe earlier historical increments; accepted authority and allocation choices are not reopened.

# ADR-038 — controlled pricing and full-copy MR allocation

2026-10-19. Owner explicitly ACCEPTED `po_draft_pricing_authority=original_drafter_live_grant` and `mr_issue_allocation_scope=full_copy_issue_allocation` through selections. These are not unresolved questions. ADR-033–037/category/three-person authority remain in force.

## Accepted authority and allocation
Only the original PO drafter may price/reprice its own-buyer-company linked DRAFT, while still holding current independent PO draft nomination, membership, discipline and module/pack entitlement. No new role or cross-company pricing delegation. Quantity, source identities and commercial descriptions cannot be changed through pricing. Financial approver and issuer remain separate people.

Full-copy scope remains. Multiple DRAFTs may exist, without source reservation at draft or financial approval. At issuance only, allocate each active PO line against its real approved MR-line ID, with exact aggregate issued quantity no greater than approved source quantity. Legacy ambiguous issued commitments require reconciliation, not automatic certification or zero assumed consumption. Partial imports/partial purchasing are not enabled. Source state/signatures and quantities are not silently rewritten.

## Increment sequencing
1. Controlled complete pricing in original drafter's existing PO detail: expected content hash, all active line UUIDs exactly once, exact nonnegative prices and currency, reason, exact rounded total and append-only before/after audit. Full-copy source validity is rechecked; preserve historical/superseded rows. SQL audit validates current authority/source/pricing and limits differences to prices/currency/total. A pricing audit is not financial approval or source allocation. Existing trusted SQL mutations are not retroactively converted into universally audited human endpoints.
2. Separate issuance-allocation implementation and atomicity/source-ceiling tests are still PENDING. Keep the ADR-037 MR-linked financial/issue refusal until that increment is verified. Current decision accepts allocation semantics; it does not claim their delivery.

A complete pricing action (including zero price) requires every active line, positive source quantities and a valid currency; unknown/partial pricing is not silently completed. Identical submissions are rejected as no change. Current content drift rejects stale forms; concurrent price submissions serialize under the existing project PO-entry lock, followed by PO and line locks. Real competing-connection lock-order/revocation tests remain a release gate.

Forward-only schema adds audit evidence without backfill, grants, source consumption or modification of historical stage1/stage2 evidence. EN/FA guides and focused tests accompany delivery; no routine full-suite/recovery rerun.


Step1 delivery: implemented and targeted-verified, 55 related tests/487 assertions PASS; [guide/evidence boundaries](PO-CONTROLLED-PRICING-EN.md). Step2 allocation remains pending.
