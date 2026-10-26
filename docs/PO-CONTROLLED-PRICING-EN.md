> **Current correction — 2026-10-19:** [safe pricing-form recovery](PO-PRICING-FORM-RECOVERY-EN.md) / [فارسی](PO-PRICING-FORM-RECOVERY-FA.md). **42 affected tests /465 assertions PASS**, nine new cases. Recover input only for the same current PO hash and complete active UUID set; blank≠zero, stale/malformed input not rebased, original write/grant/source checks unchanged. No new role/schema/endpoint or full-suite/production claim. Prior evidence preserved; older current notices below are historical.

> **2026-10-19 presentation follow-on:** [readable pricing comparisons](PO-PRICE-HISTORY-COMPARISON-EN.md) / [فارسی](PO-PRICE-HISTORY-COMPARISON-FA.md). Existing retained before/after evidence now has exact-text UUID-matched tables. Pricing/write/visibility rules are unchanged; raw snapshots and older evidence remain retained.

> **Current follow-on ADR-039:** MR-linked financial approval/three-person issuance with full-copy atomic allocation is now implemented and targeted-verified (108/746 PASS); one real two-connection contention scenario is included. [Current delivery guide](PO-MR-ISSUE-ALLOCATION-EN.md). Pending/block statements below describe earlier historical increments; accepted authority and allocation choices are not reopened.

# Controlled MR-linked PO draft pricing

2026-10-19 · [فارسی](PO-CONTROLLED-PRICING-FA.md) · [ADR-038](ADR-038-MR-PO-PRICING-ALLOCATION-EN.md)

## Current delivery

**Implemented and targeted-verified: complete pricing/repricing of a human-attributed, full-copy MR-linked DRAFT by its original drafter in its own buyer company, with current independent PO draft authority.** No new role, MTO right, cross-company pricing grant or financial authority is introduced.

The owner accepted both `original_drafter_live_grant` pricing authority and `full_copy_issue_allocation`. These are settled decisions, not questions to ask again. **Allocation semantics are accepted but their execution is not implemented in this increment.** MR-linked financial approval/issuance remains fail-closed under ADR-037. Manual/unlinked same-version three-person execution remains available as previously delivered.

## Use

1. Open Project → Procurement → a PO copied in full from an approved own-company MR → PO detail.
2. The original drafter with live membership/role/discipline/procurement/standards-pack eligibility sees **Controlled MR-linked PO pricing**. Owner configuration rights, another nominated drafter, financial nomination and issuer nomination do not substitute for original authorship. An archived project displays no pricing form.
3. Supply a currency, a nonnegative decimal-string unit price for **every active line**, and a non-blank reason. Quantity, source line ID and descriptions are not editable. Zero price is valid; missing/partial prices are not silently completed. Currency is a recorded code, not an exchange-rate calculation.
4. Save complete pricing. The server locks project/PO/source/lines, rechecks authority and exact source-copy validity, compares the submitted commercial fingerprint, calculates the exact decimal total with half-up rounding to two places, and writes prices, total/currency and the append-only revision together.
5. Review history with the raw retained before/after snapshots and hashes. The latest20 are displayed; earlier records remain stored. Identical no-change submissions and stale replays are refused without an extra revision.

The MR remains APPROVED with its quantities, approval signatures and timestamps unchanged. The PO stays DRAFT and unissued. No source reservation, remaining quantity, purchase commitment or receipt is created. Superseded line/history rows remain intact and are not repriced. Changed source content or a PO no longer matching the approved full-copy source requires reconciliation, not silent repair.

## Implementation and boundaries

- `POST /projects/{projectCode}/procurement/orders/{orderId}/pricing` accepts `pricing[content_hash,currency,reason,lines]`; each line accepts only its UUID and price. Exactly the complete current active-ID set is required once each. Foreign/historical/duplicate/partial IDs, quantity overrides, forged state/actor fields, negative/native-float prices and overflow are refused.
- `po_pricing_revisions` stores project, buyer, PO, actor/current draft-policy version, name/time, per-order sequence, reason and both canonical hashes/snapshots. It is forced-RLS with no UPDATE/DELETE policy. Audit rows are readable within the buyer's existing scoped PO detail; no new cross-company audit/read delegation is added.
- SQL validates the current original drafter/policy, writable project, plan entitlement, approved full-copy source and exact complete pricing; it checks snapshot/hash consistency, price-only differences and preservation of historical prices. Actual before content comes from the locked server transaction, not from a client snapshot. This is not universal SQL per-user RBAC or an automatic audit of every legacy trusted write.
- Any late audit refusal rolls back line prices and the header total/currency. Existing source-reference and issued-content guards remain in force. No legacy records are retro-priced or backfilled, no source state is converted to ORDERED and historical evidence is untouched.
- Header/line-history fingerprints remain the ADR-037 commercial representation. A pricing revision is neither financial approval nor a PKI signature. The pricing form does not delegate MR access beyond the drafter's existing own-company scope.

## Verification

**55 related tests /487 assertions PASS**, 92.807s, including **13 new pricing/upgrade cases** and existing PO detail, MR full-copy, version-bound financial/issue and populated-upgrade regressions. No routine full-suite/recovery rerun. Final scoped8-file PHP lint, Pint and whitespace checks and10 implementation source hashes are recorded in `docs/evidence/2026-10-19-po-controlled-pricing/summary.json`.

Cases cover exact decimal totals and original source-quantity spelling, unchanged source/signatures/order state, original-author-only enforcement despite another author's/financial grant, current role/discipline/plan revocation, suspension/archive, stale/repeated/no-change forms, zero price, complete-ID enforcement, input spoofing, overflow, source mismatch, superseded rows, SQL audit actor/protected-data/hash refusals, late-failure rollback, append-only buyer/project isolation, escaped EN/FA UI, archived populated upgrade and continued refusal of MR-linked financial action.

Private PostgreSQL tests use non-superuser/NOBYPASSRLS `epcc`, UTF8 and a Unix socket; the test server is stopped after verification. No production migration/deployment, commit/push, current CI, browser acceptance, recovery, real two-connection contention/revocation or crash claim. Existing lock-order alignment and DocumentRegister defects remain separate gates; no MTO authority/admission deferral changed.

## Next accepted increment — not delivered yet

Implement the full-copy issue allocation ledger: at issuance only, assign each active PO line to its actual approved MR-line ID, enforce exact aggregate quantity no greater than the approved quantity, and atomically commit allocations, issue evidence and the ISSUED transition. Multiple drafts remain possible and neither drafts nor financial approval reserve the source. Unknown legacy issued commitments require explicit reconciliation, not assumed-zero consumption. Partial purchasing is not enabled. Do not re-ask these accepted choices and do not remove the MR-linked execution guard before this path and its rollback/duplicate-purchase tests pass.
