> **2026-10-19 bounded concurrency follow-up:** [rollback and suspension schedules](PO-ROLLBACK-REVOCATION-EN.md) / [فارسی](PO-ROLLBACK-REVOCATION-FA.md): four additional real-connection cases pass; general crash/revocation/SQL concurrency remains unverified. No authority or runtime changes; prior proof below retained.

> **2026-10-19 guard correction:** [PO-GUARD-ISOLATION-EN](PO-GUARD-ISOLATION-EN.md) / [فارسی](PO-GUARD-ISOLATION-FA.md). The non-buyer hidden-issue integrity bypass is now fixed by migration180000; journal read visibility remains unchanged. Original guard-visibility follow-up statements below are historical; other deferred authority/workflows remain deferred.

# OrderGap mutation lock alignment

Date: 2026-10-19 · **IMPLEMENTED / TARGETED VERIFIED**, not a new authority decision.

## References and scope

ADR-015 R1/R3/R4/R6 remains the attribution, lineage-head, explicit-scope and tenant-isolation contract. ADR-036/037 controlled execution, ADR-038 price-only draft authority, ADR-039 version/source-bound issue allocation and ADR-040 read-only diagnostics remain in force. This correction implements the previously identified OrderGap/issuance lock-order follow-up; it does not supersede those decisions.

`OrderGapService::attribute`, `amend` and `allocate` now execute in transactions and acquire:

1. The existing `po-entry:<project UUID>` transaction advisory lock.
2. The fresh project row, with the existing writable-project check.
3. The parent PO row in that project.
4. The item row constrained to that same parent/project; allocation rows follow it.

An initial **nonlocking, caller-RLS-visible** item read discovers the lock key and parent. It is not trusted as the mutation state. After locking, authoritative rows replace caller models, including cached relations. Attribution validates the fresh unit; amendment copies fresh fields and checks the fresh parent state; all three recheck the lineage head. Explicit project/item mismatch fails closed. No company/project impersonation or policy expansion is introduced.

## Compatibility and decision conflict

ADR-015's historical issued-line amendment semantics cannot be read as permission to bypass the later controlled-issuance content freeze. Legacy orders without controlled execution retain their existing lineage/effective-state rules. The existing SQL freeze guards remain unchanged; an amendment attempted after controlled issue still fails and rolls back. No controlled post-issue amendment, cancellation/release, partial purchase or replacement-order workflow has been enabled.

An existing trusted OrderGap draft-line mutation is **not** a new price/quantity editor grant. If such a mutation changes a controlled MR-linked draft, previous pricing/financial evidence cannot certify the new version. The normal independent execution gates remain required. Scope allocations remain distinct from the immutable MR issue-allocation ledger; their existing arithmetic/replacement semantics are unchanged, not newly certified.

## Verification

Evidence: `docs/evidence/2026-10-19-order-gap-locking/summary.json` and `related.log`.

**65 affected tests / 552 assertions PASS, 116.350 seconds**: OrderGap, MR issue allocation, controlled execution and controlled pricing. Six new cases cover fresh amendment fields, fresh attribution unit, cached-parent cancellation, acquisition order for all three mutations/stale heads, and two real two-connection contention schedules:

- **Issue wins:** uncommitted issue holds the project lock; the real OrderGap amendment times out there with SQLSTATE `55P03`, before an item row lock. After issue commits, retry hits `PO_EXEC_ISSUED_CONTENT_FROZEN`; no successor or duplicate allocation is committed.
- **Amendment wins:** the real OrderGap service creates a successor while retaining its transaction locks; competing raw issue times out. After amendment commits, retry using old financial/pricing evidence is refused with `PO_EXEC_MR_PRICING_REQUIRED`; the order remains DRAFT with no issue ledger rows.

The timeout is intentional test instrumentation, not a production timeout/retry policy. The final combined run supersedes initial fixture-scope failures retained in `focused.log`; `new-cases.log` is an intermediate five-case pass, not the final total. Three PHP lint checks, targeted Pint and repository diff whitespace checks pass. The private Unix-socket PostgreSQL role is non-superuser/NOBYPASSRLS; the server is stopped after verification.

## Boundaries and next recommendations

- No routine full suite, production migration, deployment, commit/push or current CI claim. Prior allocation/source-review evidence is unchanged.
- No exhaustive deadlock freedom: arbitrary SQL writes can still acquire row locks before trigger advisory locks. Multi-project/raw-SQL/revocation/crash/retry schedules and non-buyer guard visibility require separate bounded review; these tests only establish the documented application paths and buyer-scoped schedules.
- **PROPOSED, not implemented:** separately design versioned post-issue corrections and write-based legacy reconciliation, preserving source ceilings, independent financial/issue approvals and historical evidence. Never reinterpret lineage as cancellation release.
- **Explicitly deferred:** new MTO access roles, scope admission/operational gates and physical receipt/unit-aggregation certification. This locking correction grants none.
