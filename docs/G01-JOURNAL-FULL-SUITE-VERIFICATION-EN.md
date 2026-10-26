> **CURRENT CLOSEOUT — 2026-10-18:** [complete scope-source suite GREEN](G01-SCOPE-FULL-SUITE-EN.md) / [گزارش فارسی](G01-SCOPE-FULL-SUITE-FA.md): **1112 tests/16488 assertions,0 failures/errors/skips,exit0; all450 source and2 worker hashes unchanged**. JUnit1298.043810s. The scope-source complete-suite pending gate is CLOSED; pending/next-full statements below are superseded history. No app source changes this closeout. Observation remains UNADMITTED; readiness false/gross null; G01 OPEN, OrderGap/authority unchanged. Next: trusted versioned scope/topology admission, not repetition of the unchanged full gate. Standalone current-source recovery: `epcc-source-recovery-scope-full-2026-10-18.tar.gz`; earlier446-source bundle and overlay remain historical. Database on excluded cache disk, private socket/no TCP, stopped after closeout. No production migration/commit/push/PR.

> **CURRENT — 2026-10-18 scope-observation slice:** read [G01-SCOPE-OBSERVATION-EN](G01-SCOPE-OBSERVATION-EN.md) / [FA](G01-SCOPE-OBSERVATION-FA.md) FIRST. New450-file source:58 related PHPUnit tests/197 assertions and5 real contention scenarios PASS; **complete suite on this source NOT RUN/PENDING**. Earlier1098/16429 green below belongs only to preceding446-file source. New sidecar is always UNADMITTED; readiness false/gross null; no historical backfill, selector/OrderGap/authority change. Runtime data moved to excluded `/home/user/.cache/epcc-app-journal-pg-uo100j_u` after test tmpfs exhaustion; metadata identifies it, socket unchanged. Next: new-source complete gate, then trusted admission. Earlier status text below is history, not current-source verification.

# G01 journal — complete application suite GREEN on current source

Date: 2026-10-18. Branch `arena/baseline-verification-2026-10-18`; base `0c54c7f84d1e343bf1d58833977ac0834dcfdcc0` plus uncommitted stabilization and NEW-journal changes. **No commit, push, PR or production deployment.**
FA companion: `G01-JOURNAL-FULL-SUITE-VERIFICATION-FA.md`.

## Closed gate: actual complete run

| Evidence | Result |
|---|---|
| Complete configured PHPUnit Unit + Feature suites | **1098 tests /16429 assertions** |
| Failures / errors / skipped | **0 /0 /0** |
| Process exit code | **0** |
| JUnit duration | **1238.612073 seconds**, about20m39s |
| Final tested-source identity | **446 files unchanged** before, during and after execution |
| Separate actual-service worker identity | Both previously recorded worker hashes unchanged |
| Concurrent DB tests | None |
| Composer validation/platform requirements | Passed |
| Scoped PHP syntax checks | **351 files** under app/app, database, routes and tests passed |
| Independent standards/conformance/screening | Passed |
| Standalone selection-contract / offline planner | **33 /26 cases passed**, separate from PHPUnit totals |

The complete run includes all15 new acceptance-journal Feature cases alongside the existing suites. This is **one full execution**, not a sum of earlier partial runs. JUnit, exit status and the frozen source manifest were independently checked after process completion.

**The previous pending full-suite gate for the446-file journal source is now CLOSED GREEN.** The89-test related run and5 actual Laravel-service contention scenarios remain prior bounded evidence on the same unchanged source; neither is added to the1098-test count. Those concurrency scenarios were not rerun during the complete suite.

No app/model/service/migration/template/test source was edited during or after execution. Subsequent changes are documentation, evidence preservation and source-only recovery refresh.

## Command and runtime

Executed from app/:

```sh
SESSION_URL_FALLBACK=false vendor/bin/phpunit --colors=never --testdox \
  --log-junit ../docs/evidence/2026-10-18-g01-full/phpunit.xml
```

The ignored test environment selects real PostgreSQL epcc_test through a private `/tmp/epcc-app-journal-sock-*` Unix socket on port5545. Dedicated cluster data is under `/tmp/epcc-app-journal-pg-*`; this is disposable synthetic-test data, not a production backup. The DB was started separately; no other test suite or concurrency-fixture writer ran alongside PHPUnit. The root run-tests.sh was deliberately not invoked because it would start another Laravel test run.

- PHP8.4.26 /PHPUnit12.5.35 /PostgreSQL17.11; locked Composer dependencies available under ignored cache.
- Actual application role epcc is **not superuser, not BYPASSRLS and not CREATEDB**.
- **fsync, synchronous_commit, full_page_writes ON** throughout. WAL bounds128MB/32MB; these integrity flags were not disabled to get a green result.
- No PostgreSQL TCP listener; local socket trust is **test-only**, not a production authentication recommendation. Temporary socket directory is private to the test runtime.
- Chromium/poppler/Vazirmatn available for existing PDF tests. This is not a fresh browser-smoke, frontend-build or jsdom run; no Docker/current GitHub Actions result is claimed.
- App env/APP_KEY, dependencies and database data are not part of recovery archives. No demo application server or customer database was started/changed.

The full test bootstrap purges seeded attachment blobs. Recreate a disposable demo separately after tests if a future preview needs it; never run this suite against customer data. Runtime packages/processes and `/tmp` data may need recreation after reset.

## What is now proved — and what remains open

Proved: the current collected application Unit+Feature suite, including real NEW-acceptance chronology integration, passes together on this exact source in this environment. Its currently tested model/service/DB interactions have no failures, errors or skips in this run.

Still open:

- **G01 effective selection:** journal entries remain NEW/UNRESOLVED. OrderGapService still aggregates all accepted rows; a journal ordinal is not scope admission or selected gross demand.
- Authoritative pinned scope manifest/topology, overlap/additivity admission, scope-change disposition, legacy reconciliation and unresolved-state UI/API consumers are not delivered by this green result.
- Historical source-row composite FK remains explicitly NOT VALID for old data; enforcement for new rows does not certify the historical population.
- MTO human-authority redesign remains deferred; no new approval role or grant. ADR-032 is still PROPOSED/unapproved; no received-order/usable-stock/inspection-policy changes.
- The integrated21-case pilot has not been executed end to end. Production security, deployment, performance, backup/restore and crash durability are not certified.

No application code needs another full rerun merely because older reports say pending. New app edits, deployment validation and ordinary independent CI/review remain separate gates. **Next development slice is pinned, provenance-backed scope-manifest/topology admission and fail-closed selection integration under existing ADR-024 principles**, not repeating this closed baseline or silently switching the board.

## Evidence and recovery

Current evidence folder: `docs/evidence/2026-10-18-g01-full/`:

- `phpunit.xml`, `phpunit.log`, `exit-code`;
- `tested-source-manifest.json` and `summary.json`;
- DB posture, Composer/platform, PHP lint, standards/conformance/screening and standalone-contract logs;
- checksums.

Older full1083/16385 and related89/240 reports are retained as historical milestones. Current source's authoritative complete result is **1098/16429**.

The refreshed source-only bundle outside the repo contains the base patch plus selected new app/prototype/test/docs/evidence files and checksums. Application source hashes are checked against a clean baseline recovery; the complete app suite is **not** rerun on that recovered copy. It excludes env/credential values/dependencies/database contents. Inspect its instructions before applying; never apply over uncommitted work. It is not an installer or DB backup and performs no remote operation.
