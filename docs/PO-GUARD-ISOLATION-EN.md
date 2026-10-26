> **2026-10-19 human-surface clarification:** [OrderGap boundary](ORDER-GAP-HUMAN-BOUNDARY-EN.md) / [فارسی](ORDER-GAP-HUMAN-BOUNDARY-FA.md). Controlled MR-linked human quantity amendment/re-attribution through OrderGap is now refused. Trusted lineage primitives remain available internally, not as human editing grants. Broader legacy/manual/MTO authority redesign remains deferred.

# Controlled PO integrity when issue evidence is hidden

Date: 2026-10-19 · **IMPLEMENTED / TARGETED VERIFIED**

## Decision references and defect

This is a security/correctness repair to ADR-037's existing issued-content freeze, not a new business authority decision. ADR-015 legacy lineage, ADR-038 restricted draft pricing, ADR-039 atomic MR issue allocation and ADR-040 caller-scoped read-only review remain in force. It completes the non-buyer guard visibility follow-up identified in `ORDER-GAP-LOCKING-{EN,FA}.md`.

**Confirmed reproduction:** an oversight caller could read an issued PO and its lines, but could not read its buyer-scoped `po_execution_decisions`. The old line trigger used that hidden journal directly and treated an empty result as “not issued”; a price update succeeded. The original PO-line policy permits writes when the parent is visible, whereas PO-header WITH CHECK remains buyer-scoped. Hiding journal evidence therefore did not itself protect commercial lines.

`reproduction.log` records the deliberately failing regression before the fix. This is an actual reproduced integrity bypass in the private fixture, not merely a proposed concern. It does not establish that production data was modified.

## Implemented correction

New forward-only migration: `2026_10_19_180000_fix_po_issued_guard_visibility.php`.

- Replaces the existing header/line trigger functions, retaining their trigger names, advisory locks, issued-content freeze, allowed receipt/forecast semantics, separation requirements and legacy behavior.
- Introduces `epcc_po_issued_guard_basis(oid)` as a **trigger-only, SECURITY INVOKER** internal helper. Direct SQL calls outside a trigger fail with `PO_EXEC_GUARD_TRIGGER_ONLY` / SQLSTATE `55000`; there is no application/report endpoint.
- First resolves exactly one parent PO under the **original caller RLS**, before changing any context. A hidden parent returns no basis. A conflicting project context cannot become a lookup into another project. Existing empty-project PO visibility is not expanded.
- Temporarily binds only that already-visible parent's buyer and project to inspect its issue decision. Returns only the hash/issuer/timestamp needed by the guards, never financial/source snapshots, policy contents or generic journal rows. Both company/project settings are restored on success and exception.
- The header guard compares against the actual issue hash and preserves the actual issue actor/time. The line guard checks both OLD and NEW parents, so moving a line cannot escape the freeze or insert content into an issued parent.

**Important scope distinction:** the internal trigger performs a narrowly bounded buyer-context lookup for integrity enforcement. This is not a new caller read grant and must not be reused by diagnostics. Ordinary journal SELECT policies are unchanged; ADR-040 continues to show non-buyer execution evidence as UNKNOWN/hidden, not missing or zero. No SECURITY DEFINER/BYPASSRLS/identity-lookup hatch, role, HTTP route or write authority was added.

This is not universal PostgreSQL per-user RBAC. The shared trusted application database role can create SQL functions/triggers and set tenancy GUCs; `pg_trigger_depth()` prevents normal direct use, not a malicious database owner constructing a separate trigger. The application remains responsible for its accepted actor/authority gates.

## Compatibility, upgrade and limitations

- No historical PO/MR/line/pricing/execution/allocation rows are rewritten, backfilled, rehashed or certified; no ledger or read-policy schema changes. Function definitions alone change.
- The migration is forward-only: rollback refuses to restore the known bypass. Apply a reviewed forward correction if needed; production application was **not** performed here.
- Legacy issued orders without controlled issue decisions retain ADR-015 lineage behavior. Controlled issued commercial amendments/cancellation/release remain unavailable, not silently re-enabled.
- Harmless header forecast/receipt progression remains subject to existing rules; non-buyer header writes are not granted. This patch does not redesign the older broad PO-line write policy for legacy/draft orders, MTO rights or scope allocation arithmetic.
- Lock ordering from the previous increment is retained. No exhaustive raw-SQL/multi-project/revocation/crash/retry or deadlock certification is claimed.

## Verification

Evidence: `docs/evidence/2026-10-19-po-guard-isolation/summary.json`.

**71 affected tests / 658 assertions PASS in 118.633 seconds**: execution, MR issue allocation, OrderGap and read-only source review. Six new cases cover:

1. Owner/PMC/named vendor retain PO/line visibility and zero journal visibility, but line UPDATE/INSERT/DELETE and commercial header changes are refused; data snapshots and caller settings are unchanged.
2. Direct helper use is refused, settings remain intact, journal stays hidden, helper is not SECURITY DEFINER.
3. Trigger-only probes cover visible owner/PMC/vendor, empty project, foreign project, empty company and unrelated company contexts; successful lookups restore settings without opening journal SELECT.
4. An injected journal-query failure is caught inside a test trigger and still preserves original caller settings. Test-only functions/policy/probes are removed afterward; no production guard is disabled.
5. Reparenting from or into a controlled issued order is refused.
6. Upgrade of an archived populated database preserves byte-for-byte row representations across six PO/evidence tables and does not fabricate decisions or journal access.

`related.log` is authoritative. `focused.log` is the six-new-case pass; an intermediate snapshot assertion used object identity instead of values and was corrected before the final pass. Private PostgreSQL 17 / PHP 8.4, role `epcc` NOSUPERUSER/NOBYPASSRLS, UTF8, Unix socket only. Runtime/dependencies were restored after the workspace cache reset. Two PHP lint checks, targeted Pint and diff checks pass. Private database stopped after verification.

No full-suite rerun, current CI, deployment, production migration, commit/push or post-issue workflow certification. Previous evidence folders remain historical and unchanged.

## Recommended continuation — proposed, not implemented

Review the legacy/draft OrderGap write surfaces against accepted price-only PO authority before any access redesign; report conflicts rather than inheriting read visibility as operational permission. Separately design versioned post-issue corrections and legacy commitment reconciliation with independent finance/issue decisions and immutable history. No new MTO roles, scope admission, cancellation capacity release or reconciliation writer is authorized by this repair.
