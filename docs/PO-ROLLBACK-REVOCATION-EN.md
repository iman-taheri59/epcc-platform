# PO issuance: bounded rollback and nomination-suspension contention

2026-10-19 · **TARGETED VERIFIED** · Test/documentation increment; no runtime application/migration changes.

## Existing contract and purpose

ADR-036/037 require current independent finance/issue nominations and same-version evidence. ADR-038/039 make drafts and finance non-reserving, while issue decisions, source allocations and the ISSUED transition commit atomically. Prior OrderGap locking/SQL integrity/human-boundary repairs remain in force. This increment tests identified rollback-release and nomination-revocation schedules without changing those decisions or introducing authority.

## Four new real-connection schedules

The issuance side uses independent PostgreSQL PDO connections and the actual decision/header triggers. The suspension side uses the real `PurchaseOrderExecutionAccessService::configure` under the existing authorized owner, targeting the existing buyer. No guards are disabled and no new grants are fabricated.

| Schedule | Observed bounded result |
|---|---|
| Issue A inserts its decision, transitions the PO and creates two allocations without commit; issue B contends for the same MR | A sees two provisional allocations; another connection sees zero. B times out on the project advisory lock. A rolls back; B retries in a fresh transaction and commits. A's PO remains DRAFT with only its preexisting financial decision, no issuer or allocation. B has one issue decision and two allocations. MR header/lines remain identical. |
| Owner suspension A holds its transaction open; issue B uses the former financial/issue basis | B times out at the project lock. A commits the new suspended policy version. Fresh B retry fails `PO_EXEC_AUTHORITY` / `42501`. PO stays DRAFT, existing finance remains, and no issue allocations are committed. |
| Same suspension wins the lock but is rolled back rather than committed | B waits/times out; after A rolls back, B retries and succeeds using the still-live original policy. No rolled-back policy version remains; one issue and two allocations commit. |
| Issue A wins the lock; the real owner configuration service attempts suspension | Configuration times out on the project advisory lock. A commits issue. A fresh configuration retry then commits suspension. The PO, execution journal and allocation rows remain identical; future issue nomination is absent. Suspension is not retroactive cancellation or capacity release. |

Each contention uses a test-only `150ms` local lock timeout and explicitly checks SQLSTATE `55P03` plus the advisory-lock location. Competing transactions are rolled back before retry. These tests establish **timeout → fresh transaction → current-state recheck** schedules; they do not certify a single blocked statement automatically resuming correctly under every isolation level, or introduce a production retry/timeout policy.

## Results and traceability

- **4 new focused cases / 48 assertions PASS**, 8.945s (`focused.log`).
- **38 affected tests / 328 assertions PASS**, 74.837s (`related.log`): `PurchaseOrderMrAllocationTest` and `PurchaseOrderExecutionAccessTest`. The four new cases are included, not added again to this total.
- One modified PHP test file; PHP lint, targeted Pint and diff whitespace checks pass.
- Four carried runtime hashes (OrderGap service/controller/view and SQL guard migration180000) match their preceding evidence. Only tests/docs/evidence were edited in this increment. Prior 77/764 and other evidence folders are unchanged historical run records; totals are not cumulative and are not a full suite claim.
- Private PostgreSQL17, PHP8.4, role `epcc` NOSUPERUSER/NOBYPASSRLS, UTF8, Unix socket only; stopped after verification.

Evidence: `docs/evidence/2026-10-19-po-rollback-revocation/summary.json`, `runtime-baseline-check.json`, and the named logs. Test methods identify each schedule directly in `app/tests/Feature/PurchaseOrderMrAllocationTest.php`.

## Explicit limits and recommendations

This closes only the four documented contention checks, not a general concurrency/revocation release gate. Account deactivation, membership/discipline/plan/role changes, multi-project/raw-SQL inversion, crash/process loss, network disconnect, commit-acknowledgment uncertainty, automatic retries and other isolation levels remain unverified by these new cases. Existing sequential authority tests are not relabeled as concurrency proof.

No new MTO/OrderGap role, operational admission, reconciliation writer, partial purchase, cancellation release, controlled post-issue correction, production migration/deployment, commit/push, current CI or routine full-suite execution. General legacy/manual write authority remains deferred.

Recommended next useful feature work should stay inside already accepted authority. Any proposal for controlled post-issue changes or write-based legacy reconciliation must separately define version/evidence preservation and authorization; passing rollback tests does not authorize those workflows.
