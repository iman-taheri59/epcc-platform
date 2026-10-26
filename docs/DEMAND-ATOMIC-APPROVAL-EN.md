# Controlled demand: source adapter and atomic final MR approval

2026-10-23 · **Isolated PostgreSQL protocol proof implemented; application integration NOT implemented.**
[فارسی](DEMAND-ATOMIC-APPROVAL-FA.md) · [Accepted DA-01..04](DEMAND-ACTIVATION-MATRIX-EN.md#7-explicit-user-decisions--accepted-2026-10-23)

## 1. Scope and result

This advances the accepted policy beyond offline arithmetic: `prototype/demand-atomicity/` contains an isolated SQL schema/function and real PostgreSQL multi-connection tests. **29 cases PASS**, including actual lock contention, rollback and duplicate retries. This is not a Laravel feature, production migration, source admission proof or permission rollout. No files under `app/` changed; no application DB/data was used.

DA-01 independent document/scope readers, DA-02 independent review/separate apply grant, DA-03 final technical/commercial MR approval reservation and DA-04 reasoned nonautomatic reconciliation remain accepted, not reopened. Existing procurement full-copy/draft policy, original pricing/finance/issue authorities, custody/QC/QA and warehouse boundaries are unchanged.

## 2. Actual integration seam and newly identified risks

Source inspected:

- `app/app/Services/ProcurementService.php::approveCommercially()` checks transition, approver company type and established person separation, then updates MR to APPROVED and stamps actor/time. It does **not** reserve controlled MTO demand or serialize the future demand ledger.
- `app/app/Http/Controllers/MaterialsController.php::advanceRequisition()` resolves project, validates action and calls the service under original TenantContext; it does not perform demand admission/reservation. Existing transaction context alone does not implement the required dependency locks or ledger freshness checks.
- `app/database/migrations/2026_10_06_120000_create_accepted_mto_snapshots.php`: batch/row RLS allows participating project companies. That is a storage boundary, **not** DA-01's new explicit document/scope nomination gate. A reader adapter must not mistake “SQL row visible” for the newly approved action authority. No broad accepted-table RLS rewrite was made here.
- `MtoScopeReadinessService` still returns NO_ADMITTED_TOPOLOGY; new acceptance chronology is not effective-scope admission. Authoritative live demand remains blocked.

**Recommendation:** a future controlled-MR branch must wrap final approval AND reservation in the same transaction and shared dependency protocol, not call an after-commit listener, queue, or HTTP second step. It must reload/lock the MR rather than trust a stale Eloquent object. Apply existing commercial/technical SoD, actor/company/action checks; never replace them with demand-review nomination. Unlinked legacy MRs retain existing behavior. Controlled enrollment must be explicit/versioned; missing linkage cannot silently select the legacy branch. Audit all trusted service/import callers before enabling so they cannot bypass a controlled MR's invariant.

A demand-review grant does not grant commercial approval, and commercial approval does not imply demand execution. The proof deliberately requires both independent capabilities. Exact production action mapping/service execution attribution must be explicit in the bounded implementation, not a seeded permission or new inferred approver role.

## 3. DA-01 source-reader adapter design

Logical configuration, not shipped tables:

- Append-only current nomination policy per project with reason/version/suspension; owner-company current project.configure administers. No default readers; configurator is not a reader by default.
- Explicit eligible participating users or company-bound roles, actor company, PIPING, exact MTO document ID and exact pinned scope set/hierarchy identity. No text/code/prefix match, wildcard project grant, cross-version scope carry-forward or role inheritance from MR/PO/PMS/receipt/QA.
- Configuration selector must use independently resolvable source identities; no hidden document titles in a picker. A configurator unable to independently resolve a source must fail closed, not gain a source-reader role from configuring it. Any genuinely missing metadata authority must be reported, not added implicitly.
- On every new reader call: fresh active actor/company, participant/member/discipline, proc/pack entitlement, current nomination and exact source selectors, **AND** original caller source RLS. No company switch, SECURITY DEFINER delegation, cached old nomination or claim that a nomination makes hidden original data visible.
- Resolve exact immutable batch/revision/row/project tuples against the admitted effective selection. No manufacturing manifest pins from today's Subsystem tree, chronology alone, staging rows, requested UUIDs or client hashes. Use original source units/gross; no canonical conversion or gross-minus-stock without separately approved semantics.
- Require complete visibility/validity of the authorized selection/inventory closure before any affirmative capacity. A hidden row or unapproved source cannot be silently dropped from an otherwise green total. Return generic unavailable/unresolved without hidden IDs/counts/titles. Project-wide capacity requires a separately valid complete project inventory, not the count of caller-visible records.
- Do not query CDE bodies merely because demand-reader nomination exists. Evidence integrity/read authorization remains its own resolver boundary.

Proposed internal result: authorized exact source tuple + immutable basis/MR/review/inventory/evidence versions, readiness findings, original caller provenance. Unavailable/incomplete yields null capacity, no guessed zero or writer admission. Reader nomination and selection/admission are distinct: accepting the former does not fix the latter.

## 4. Future approval transaction

1. Original actor and project scope, current explicit capabilities and existing MR commercial eligibility. Lock compatible project/archive root first, then deterministic MR/source/ledger dependencies; coordinate this order with all acceptance/review/configuration/PO writers. The proof serializes the entire project for clarity, not a throughput guarantee.
2. Re-read exact MR version/state, technical/raising identities, independently approved explanation, different proposer/reviewer and separate current apply grant. Check the same visible source and buyer scope. An applier in this proof is one of proposer/reviewer; no inherited grant.
3. Validate expected selection, admission/evidence heads, MR/review versions and reconciled inventory. Missing admission/history or changed evidence blocks, even if gross is unchanged.
4. Check stable request identity with server-computed full payload digest; identical retries return the existing outcome once original current authorization is rechecked. Conflicting content is rejected. The production digest must bind complete package/action/source closure, not only the simplified proof fields.
5. Under lock, validate every MR line's exact identity/unit/scope and aggregate remaining quantity with exact decimals; atomically stamp final MR approval and append reservation sidecar events. Nothing remains if any late statement fails. Never update accepted MTO rows to track usage.
6. Later valid full-copy PO issue links/consumes the same reservation: **no second demand debit**. This handoff is not implemented/tested by this slice. Failed MR approval cannot leave a reservation; missing required reservation cannot leave a controlled MR approved.
7. Reconciliation/release remains reasoned and independently reviewed. No automatic release from rejection, missing source, revision change, archive or receipt/QA/stock status.

## 5. Proof implementation and exact limits

`schema.sql` creates only `demand_proof` in a **dedicated throwaway** database `demand_atomic_proof`: projects, simplified source capacity, synthetic nominations, MR fixture snapshots and immutable reservation events. `approve_and_reserve()` is SECURITY INVOKER. Actor role is NOSUPERUSER/NOBYPASSRLS; all five tables ENABLE/FORCE RLS for project/buyer. Exact per-source nomination/capability checks occur inside the tested protocol. Identical request and project/MR uniqueness prevent duplicate protocol reservations; source bound plus project serialization prevents tested overconsumption.

**Do not install this schema into the app.** It is intentionally incomplete:

- Synthetic TEXT IDs, aggregate quantities, trusted admitted/inventory booleans and toy scope/capability fixtures do not resolve real source authorization, hierarchy, evidence, identity or legacy obligations. Real material/unit/line mapping remains the separate contract, not validated by this SQL function.
- Toy source capacity combines gross/used for demonstration. Production accepted-MTO data is immutable; use separately reviewed sidecars/events and guarded derived capacity, never UPDATE accepted rows.
- The actor fixture has DML privileges needed for invoker row locks/updates. The proof establishes the **function protocol**, not a universal SQL-level invariant against direct DML, a compromised app DB role or spoofed actor GUC. Production facade/guard/constraint design must close all mutation paths before activation. Do not weaken RLS or grant extra human rights to make row locking work.
- No general actual-user membership/entitlement resolver, nomination configuration UI, approved-scope reader, live inventory, effective selection/admission, MR enrollment/content journal, multi-line sealing, PO handoff, full source FK closure or deployment exists here.
- Concurrent invalidation fixtures lock the same project root before changing head/grant/archive state. This demonstrates a **shared lock protocol requirement**. It does not prove existing real app writers follow that protocol, nor instant revocation of an already committed result. All participating writers must be audited/adapted.
- Project/buyer RLS negatives and immutable events are tested; complete production cross-source/document permission and per-person RBAC are **not** certified. A current authorized retry can return a historic recorded success after source change, but cannot create another reservation; stale new requests still fail.

## 6. Verification and reproduction

Authoritative evidence: `docs/evidence/2026-10-23-demand-atomicity/postgres.log`.

**29 isolated PG cases PASS**, separate from earlier PHP/offline or application counts: approval+reservation commit; rollback after MR update and after event insert; repeated/concurrent idempotent requests; conflicting request payload; no second MR approval; stale MR/basis/evidence; foreign project/buyer and missing nominee; four fresh authority flags; missing admission/inventory; archived project; MR SoD and self-review negatives; changed reviewed evidence; concurrent two-MR capacity race; actual blocked-call visibility of committed basis/archive/grant changes; ordinary actor RLS negatives; privileged fixture event immutability.

Runner is destructive **only inside the guarded dedicated proof database/schema**. Requires PostgreSQL 17 and Python psycopg, precreated ordinary actor `demand_atomic_actor` and admin `user`; never point it at an app DB. Fixed test port 5547, socket prefix `/tmp/epcc-demand-atomic-sock-`, TCP disabled in this run:

```sh
DEMAND_PROOF_SOCKET=/tmp/epcc-demand-atomic-sock-local \
DEMAND_PROOF_RESET=demand_atomic_proof \
/usr/bin/python3 prototype/demand-atomicity/test_postgres.py
```

Fixture admin resets only `demand_proof`; test function runs as ordinary actor. Private cluster/socket were initialized specifically for this slice and stopped afterward. No application PHPUnit/full regression/browser/production migration/data audit/CI/deploy/commit/push. Runtime/cache/vendor/DB are not included in the dependent source increment. Prior app/prototype hashes remain unchanged and are separately checked.

## 7. Next implementation boundary

The next executable app slice can be **nomination configuration + a fail-closed scoped reader readiness adapter**, not authoritative capacity/reservation yet. It must not expose hidden documents or pretend missing admission is ready. Before adding its migration, review configuration metadata visibility, exact source composite keys and plan/package/role freshness using focused PostgreSQL upgrade/RLS tests. Final MR transaction activation remains gated by genuine admitted effective selection and complete legacy commitment reconciliation. DA-01..04 need not be re-asked.
