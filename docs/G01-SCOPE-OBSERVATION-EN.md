> **CURRENT CLOSEOUT — 2026-10-18:** [complete scope-source suite GREEN](G01-SCOPE-FULL-SUITE-EN.md) / [گزارش فارسی](G01-SCOPE-FULL-SUITE-FA.md): **1112 tests/16488 assertions,0 failures/errors/skips,exit0; all450 source and2 worker hashes unchanged**. JUnit1298.043810s. The scope-source complete-suite pending gate is CLOSED; pending/next-full statements below are superseded history. No app source changes this closeout. Observation remains UNADMITTED; readiness false/gross null; G01 OPEN, OrderGap/authority unchanged. Next: trusted versioned scope/topology admission, not repetition of the unchanged full gate. Standalone current-source recovery: `epcc-source-recovery-scope-full-2026-10-18.tar.gz`; earlier446-source bundle and overlay remain historical. Database on excluded cache disk, private socket/no TCP, stopped after closeout. No production migration/commit/push/PR.

# G01 — immutable scope observations, not scope admission

Date: 2026-10-18. **RELATED_SUITE_GREEN_FULL_SUITE_PENDING** on the new 450-file source manifest. This supersedes current-source claims in the earlier journal full-suite closeout: its 1098 tests / 16429 assertions remain valid evidence for its exact preceding 446-file source, not this source. G01 remains OPEN.

## Delivered boundary

- Forward-only migration `2026_10_18_110000_capture_mto_scope_observations.php` creates `mto_scope_observations`. It does not visit/backfill existing accepted batches, even batches already possessing a NEW journal entry.
- A deferred constraint trigger on NEW batch INSERT captures a JSONB database observation, normally at outer transaction commit. It uses one SQL snapshot for referenced same-project subsystem → optional area → facility edges and ordered accepted-row facts. The row facts include exact IDs, staging hash, material, decimal quantity/canonical quantity, unit, scope and claimed hierarchy revision. A NULL scope stays NULL, never project root. A NULL area is valid.
- Columns bind the exact project/company/batch/document/revision tuple to the immutable accepted batch; a unique batch FK also requires its existing NEW acceptance journal. PostgreSQL computes SHA-256 over its JSONB text representation, separately for the whole payload and the observed row set. This is a database-local canonical representation, not a cross-language signing protocol or engineering signature.
- Every observation is **DATABASE_OBSERVATION / UNADMITTED**. A non-null hierarchy string is a claim, not a verified version, engineering approval, historical manifest, disjointness proof or complete-scope coverage.
- Ordinary application/model and SQL UPDATE/DELETE are refused (RLS may produce zero affected rows). Direct SQL INSERT is rejected by the automatic trigger-path guard. Like the existing journal guard, trigger depth is an integrity-routing check, NOT protection against trusted schema owners/attacker-created triggers. Database owners can subvert hashes and triggers; no cryptographic authority is claimed.
- FORCE tenant/project-set RLS, invoker-only functions, no SECURITY DEFINER/BYPASSRLS, archive write restriction. Deferred capture pins only the already-inserted tuple's tenant/project and restores caller GUCs. `observed_at` is the actual capture clock, not acceptance chronology or necessarily the commit timestamp.

## Read-only readiness

`MtoScopeReadinessService::assess(AcceptedMtoBatch)` refetches visible source and observation instead of trusting caller attributes/cached relations. It returns `ready=false`, `status=UNRESOLVED`, `gross=null` in every case. There is intentionally no admission writer, selector, API/board switch or selected-gross integration.

Findings cover invisible source; missing observation; payload hash mismatch; source-row-set drift; unsupported schema version; missing/unadmitted claimed hierarchy pin; missing or mismatching row pins; missing row scope; unresolved visible/same-project subsystem/facility/area parents at capture; unresolved differing header/row scope relation. `NO_ADMITTED_TOPOLOGY` remains even for structurally resolved observations. Later graph changes do NOT rewrite the stored observation or turn current topology into historical admission.

The accepted-row table is not newly sealed by this slice: late raw INSERTs can still be legal under its pre-existing schema. The independent row-set digest makes additions detectable by readiness, and frozen coverage is not silently enlarged. Existing consumers, including **OrderGapService**, do not call this readiness service and still aggregate accepted rows as before. This does not fix selected gross, additivity, legacy overlap or the unresolved UI/API.

## Timing and compatibility limitations

The capture is deferred until commit by default. Inside an enclosing transaction, before capture runs, readiness honestly reports `SCOPE_OBSERVATION_MISSING`. `SET CONSTRAINTS ... IMMEDIATE` can run capture earlier; subsequent source-row additions are drift, not an expanded observation. It is an observation at trigger execution, not a guarantee of final/historical topology at physical commit. Concurrent hierarchy mutation is not serialized into a trusted revision. Capture insert requires writable project under RLS; accepting and then archiving within one enclosing transaction can therefore fail at deferred capture. No business admission policy is inferred from this.

The existing journal expansion test now migrates the pre-journal cutoff and then all later expansion files; this keeps its original no-fabricated-history assertions meaningful with the new dependent migration. The new expansion test separately proves that adding scope observation to an already journaled batch does not invent historical observations.

## Actual verification

Private `epcc_test`, PostgreSQL 17.11, PHP 8.4.26, PHPUnit 12.5.35, restricted `epcc` role; synthetic fixtures only. No concurrent DB suites.

| Check | Result |
|---|---|
| Related PHPUnit suites | **58 tests / 197 assertions, exit 0**, no errors/failures/skips |
| New observation cases within that total | **14 tests / 59 assertions** |
| Real independent Laravel-worker contention | **5/5 PASS**, actual `pg_blocking_pids`, plus no missing observation, no non-UNADMITTED state, no row-set digest drift |
| PHP lint | 358 files in app/app, database, routes, config, tests; passed |
| Standalone selection/planner | 33 / 26 cases passed; separate from PHPUnit total |
| Complete PHPUnit on this new source | **NOT RUN / PENDING** |

Related suites: acceptance journal, acceptance, staging, scope observation, OrderGap, RLS sargability. New tests cover exact capture/digests/decimals, before-commit missingness, rollback, optional area, later reparenting, cross-project parent, mixed scoped/unscoped rows, row additions, model/SQL mutation, fabricated insert, outsider/forged-model reads, nested context restoration, no historical backfill, archived reads and forward-only down.

Evidence: `docs/evidence/2026-10-18-scope-observation/` (`related.xml`, logs, exit, contention JSON, source manifest/delta, database posture, SHA256SUMS). The initial fixture log contains invalid attempts to set a NOT NULL facility to NULL; corrected tests use real legal reparenting and a cross-project facility, without disabling constraints. A later expanded run failed because `/tmp` tmpfs filled (47 errors, NOT a passing run). Its log/XML are retained as `infra-disk-full.*`. The isolated cluster was stopped/exited, moved to excluded `/home/user/.cache/epcc-app-journal-pg-uo100j_u`, restarted with max/min WAL 128/32MB and integrity settings ON; automatic recovery completed. The final 58-case run used migrate:fresh fixtures and passed, then contention passed. This is not a production crash-recovery certification.

Runtime metadata: `/home/user/.cache/epcc-app-journal-cluster.json`. Socket/port unchanged, no TCP. Source recovery does not include runtime/database contents. No production/customer migration, remote operation, commit, push, PR, frontend build, browser smoke or integrated pilot. ADR-032 remains proposed; no MTO authority/RECEIVED/usable changes.

## Next gate

Run the complete suite on this exact 450-file source and close out its own source/evidence hashes. Then design a separately authorized, versioned, provenance-backed admission/coverage contract; missing admission must continue to fail closed. Do not promote these observations or non-null pins into authority.
