> **Current closeout —2026-10-19:** [topology-source full GREEN](G01-TOPOLOGY-FULL-SUITE-EN.md) / [فارسی](G01-TOPOLOGY-FULL-SUITE-FA.md): **1151 tests/16776 assertions,0 failures/errors/skips,exit0;454 source+2 worker hashes unchanged**. Full-source pending gate CLOSED; older pending/next-full prose below is superseded history, not current instructions. No app edits this closeout. G01 OPEN; comparison is NOT authority/admission, UNADMITTED/UNRESOLVED, gross null, no role/route/migration/OrderGap change. Next: accepted-decision-backed read-only disposition/validity dependencies, not repeating this unchanged full gate. Current source-only recovery: epcc-source-recovery-topology-full-2026-10-19.tar.gz. Private temporary PG stopped after closeout; verify current cache metadata after resets.

# G01 — read-only frozen-topology reconciliation

2026-10-19. **RELATED_SUITE_GREEN_FULL_SUITE_PENDING** on new454-file source. Companion: G01-TOPOLOGY-RECONCILIATION-FA.md.

## Decision basis and exact scope

Read [decision reference index](DECISION-REFERENCE-INDEX-EN.md), accepted ADR-018 §§9.2–9.6, MTO requirements §3.6 and draft admission contract. Stable IDs/pinned versions, no inferred coverage transfer, evidence-backed mapping, immutable history and explicit MTO permission deferral are existing decisions, not new questions.

Added only:
- `app/app/Services/MtoTopologyReconciliationService.php`
- `app/tests/Feature/MtoTopologyReconciliationTest.php`

No migration, route/API/CLI, role/grant, admission writer, snapshot mutation, CDE-proposal wiring, selected-gross/OrderGap change or existing-consumer switch. Existing readiness service remains unchanged. This internal diagnostic is not a replacement for future endpoint authentication/entitlements or an approval policy.

## Input and source read

`compare(AcceptedMtoBatch $batch, array $binding, array $nodes)` takes the batch identifier, an untrusted exact binding, and an untrusted proposed ancestry fragment. It does NOT take a trusted historical graph or an already-admitted proposal.

Binding has exactly project_id, batch_id, observation_id, payload_hash, source_rows_hash, hierarchy_revision_id and header_scope_id. Values are compared strictly to freshly read source fields; no extra approval flag, missing pin, mismatching hash/project/observation or cross-version substitution is accepted. Header null must match explicitly. This narrow internal binding is exact/case-sensitive; node UUID identities/parent IDs are normalized to lowercase, but project_id must equal the canonical project UUID. No mapping is inferred for a different hierarchy version.

One joined SQL statement reads the visible immutable batch/observation and computes PostgreSQL JSONB payload and current accepted-row-set digest checks in the same statement snapshot. Normal invoker RLS applies to both tables and the row helper; there is no context switch, SECURITY DEFINER or lookup from caller model attributes/cached relations. Absent/hidden batch yields SOURCE_NOT_VISIBLE and no observation ID; visible batch with no observation yields SCOPE_OBSERVATION_MISSING. Incomplete/damaged/drifted input yields no partial differences or total.

The comparator reads **only frozen observation JSON**, never current Subsystem/Area/Facility tables. It reconstructs observed edges as project→facility→optional area→subsystem, preserving direct facility→subsystem where area is null. The project root is a structural wrapper for known observed edges, NOT a promotion of null row scope to whole-project coverage. Captured area/facility conflicts, unresolved parents, missing scopes or mismatching row pins block comparison rather than being filled from the proposal.

## Structure and difference report

Nodes are a list of exact id/project_id/kind/parent_id objects, all same-project. Exactly one explicit PROJECT root with project UUID and null parent; allowed kinds/edges are PROJECT→FACILITY, FACILITY→AREA, FACILITY→SUBSYSTEM, AREA→SUBSYSTEM. Duplicates, missing parents, foreign nodes, unknown fields and invalid kinds/edges are rejected. Strict kind levels exclude cycles. Bounded resource limit: at most10000 nodes per graph; exceeding it returns incomplete, not approved partial coverage. This is an implementation resource bound, not a domain limit on project size.

Once both fragments are structurally comparable, deterministic UUID-sorted differences are returned:

| Code | Meaning — NOT a disposition |
|---|---|
| OBSERVED_EDGE_OR_KIND_DIFFERS | Same ID has a differing proposed parent or kind |
| NODE_NOT_IN_PROPOSAL | Captured node is absent from the proposal; NOT approved retirement/zero |
| NOT_OBSERVED_AT_CAPTURE | Proposed node was not in this bounded observation; NOT proof it is new, invalid or outside the real historical hierarchy |

Comparison is restricted to the observed/proposed fragments. Captured nodes cover only referenced row scope ancestry, NOT the whole project inventory. Additional proposed nodes may simply have been outside that observation. No equivalence, split/merge continuity, header boundary, empty-scope disposition or independent coverage completeness is inferred by this comparator. The offline full proposal validator has separate structure/coverage/header checks; it is not invoked or upgraded to a trusted adapter here.

Outputs:
- INCOMPLETE / comparison_complete=false for missing evidence, stale binding, malformed graph or row-set drift; differences=[];
- MATCHES_OBSERVATION or DIFFERENCES_FOUND / comparison_complete=true for structural comparison;
- in EVERY case ready_to_admit=false, admission_state=UNADMITTED, ready=false, gross=null.

Even a match includes ENGINEERING_AUTHORITY_NOT_ESTABLISHED, COVERAGE_NOT_VERIFIED and EVIDENCE_VALIDITY_NOT_VERIFIED. Matching a captured mutable-DB observation is NOT validation of authoritative historical topology. No source wins automatically and nothing is saved/admitted.

## Consistency and security limitations

The single SQL read is a point-in-time statement snapshot under the caller's existing DB isolation, not a durable report cutoff, selection lease, lock against future accepted-row additions, authenticated proposal signature or current-evidence validity proof. If caller uses an older repeatable-read snapshot, unseen later changes remain unseen. Source facts here are normal guarded observation schema-v1 records, not protection against trusted schema owners changing functions/triggers/payloads. No new multi-connection concurrency/crash-recovery proof is claimed.

This is not a full untrusted HTTP parser. Inputs are typed arrays; proposed nodes are strictly checked and resource-bounded. Future envelope/schema expansion must be explicitly versioned rather than silently interpreting new topology semantics. No filesystem/network/CDE file read occurs in this service. Read-only diagnostics can proceed under the existing permission deferral; an operational human admission writer or new role still cannot be inferred from this work.

## Actual verification

Private PostgreSQL17.11 epcc_test, PHP8.4.26/PHPUnit12.5.35, restricted epcc role, synthetic fixtures, serial execution, private socket5545/no TCP, integrity ON/WAL128/32MB.

- **83 related tests /442 assertions, exit0;0 failures/errors/skips**.
- Within that total, **17 new cases /178 assertions**.
- Matched suites: topology reconciliation, scope observation, acceptance journal, CDE integrity, OrderGap and RLS sargability.
- Dedicated coverage: exact match/no writes, optional area, changed parent/type, absent/unobserved nodes, later mutable graph changes ignored, missing pin/scope, every binding field/unknown flag, invalid/foreign/duplicate/missing/cyclic graph, deterministic input order/no mutation, late raw row insertion, outsider/forged model, before-commit missing observation, archived-project read and unresolved cross-project capture parent.
-362 PHP lints passed; proposal46/selection33/planner26 passed separately, not added to PHPUnit.

Initial15-case run passed. The first expanded run had one failing fixture: unordered first() selected a different subsystem after UPDATE moved a row; deterministic ID ordering corrected the fixture without weakening expected unresolved-parent behavior. That failure log/XML are retained as fixture-order-failure.*; final related.xml/exit=0 is passing evidence. No RLS or constraint was disabled.

Evidence: `docs/evidence/2026-10-19-topology-reconciliation/`. All preceding452 source hashes are unchanged; two new files make454. **Complete suite on454 source NOT RUN/PENDING**; prior1134/16598 complete pass belongs to preceding452-file source only. No commit/push/PR, customer migration/deployment, browser/frontend, CI or integrated pilot.

Next: full suite on this exact454-file source, then separately scoped evidence-backed reconciliation dispositions/validity using already accepted semantics; no authority invention. Current standalone source recovery: `epcc-source-recovery-topology-reconciliation-2026-10-19.tar.gz` (new-source full pending).
