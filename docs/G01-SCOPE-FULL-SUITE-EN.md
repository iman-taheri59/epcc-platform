> **Current closeout — 2026-10-19 local /2026-10-18 UTC:** [CDE-source complete suite GREEN](G01-CDE-FULL-SUITE-EN.md) / [فارسی](G01-CDE-FULL-SUITE-FA.md): **1134 tests/16598 assertions,0 failures/errors/skips,exit0;452 source and2 worker hashes unchanged**; JUnit1323.967296s. New-source full pending gate CLOSED. Earlier pending/no-full/next-full statements below are superseded history, not current instructions. No app source edits this closeout. G01 OPEN; byte integrity is NOT engineering approval/admission, UNADMITTED/UNRESOLVED, no authority/OrderGap change. Runtime recreated after reset; current cache cluster is in metadata, stopped after closeout. Next: accepted-decision-backed read-only reconciliation/validity work, not rerun of unchanged full gate. Current standalone recovery: epcc-source-recovery-cde-full-2026-10-19.tar.gz.

> **Current continuation — 2026-10-18 CDE integrity:** [EN](G01-CDE-EVIDENCE-INTEGRITY-EN.md) / [فارسی](G01-CDE-EVIDENCE-INTEGRITY-FA.md). New452-file source:85 related tests/288 assertions PASS;22/110 new within total. **Complete suite on new source NOT RUN/PENDING.** Prior1112/16488 full green below is for preceding450 files only; those450 remain unchanged, two app/test files added. Read-only exact CDE tuple/local-byte integrity, no route/role/migration/admission/OrderGap change. UNADMITTED. Older “no app edits/current full green” statements below describe preceding slices, not this source. Next: full suite on452 files. Standalone current recovery: epcc-source-recovery-cde-integrity-2026-10-18.tar.gz. Database private/cache-disk/socket5545, stopped after closeout.

# G01 — complete suite GREEN on scope-observation source

Date: 2026-10-18. Status: **GREEN_COMPLETE_SUITE_ON_SCOPE_OBSERVATION_SOURCE**.

## Verified result

One complete configured Unit + Feature PHPUnit run on the actual **450-file scope-observation source** passed:

| Metric | Result |
|---|---:|
| Tests | **1112** |
| Assertions | **16488** |
| Failures / errors / skipped | **0 / 0 / 0** |
| Exit | **0** |
| JUnit duration | **1298.043810 seconds (~21m38s)** |
| Source hashes unchanged before / during / after | **450** |
| Worker harness hashes unchanged | **2** |

Command, from `app/`:

```sh
SESSION_URL_FALLBACK=false vendor/bin/phpunit --colors=never --testdox \
  --log-junit ../docs/evidence/2026-10-18-scope-full/phpunit.xml
```

Evidence: `docs/evidence/2026-10-18-scope-full/`, including raw PHPUnit log, JUnit, exit code, frozen source manifest, worker hashes, database posture, independent check logs, summary and SHA256SUMS. No application/model/migration/test/template edits during or after this run; subsequent changes are reports/recovery only. This closes the new-source complete-suite pending gate. Earlier 58/197 related evidence and the preceding 1098/16429 complete journal-source evidence remain historical, not competing current results.

## Environment and independent checks

PHP 8.4.26, PHPUnit 12.5.35, PostgreSQL 17.11; disposable `epcc_test`, synthetic fixtures only. Actual application role `epcc` is non-superuser, non-BYPASSRLS, non-CREATEDB. Private Unix socket, port5545, no TCP; local trust is TEST ONLY. `fsync`, `synchronous_commit`, `full_page_writes` ON, WAL max/min128/32MB. Data directory is on excluded cache disk rather than the tmpfs that exhausted space during the preceding slice. No new disk exhaustion or recovery event occurred in this full run.

Composer validate/platform, 358 PHP lints, standards packs, rule-engine conformance, material screening and its synthetic example all passed. Standalone selection33 and planner26 cases passed separately; not added to PHPUnit count. The preceding slice's five real independent-kernel contention cases already passed on the identical source, including observation completeness/state/digest checks; they were not rerun concurrently with this suite.

The database is stopped after closeout; metadata `/home/user/.cache/epcc-app-journal-cluster.json` identifies the cache data directory and socket. Runtime/dependencies/environment values/database contents are not part of source recovery. A full suite resets disposable test fixtures; no demo or customer data is certified.

## Still explicitly unresolved

- Observation is **DATABASE_OBSERVATION / UNADMITTED**, not authoritative historical topology or engineering approval.
- Readiness remains `false`, selection `UNRESOLVED`, gross `null`.
- `OrderGapService` still aggregates accepted rows; no selected-gross or board/API switch.
- No legacy backfill, historical FK validation, trusted admission manifest, complete coverage/disjointness/additivity or legacy-overlap resolution.
- Timing, early SET CONSTRAINTS, graph mutability, late-row drift detection (not a row-set seal), and accept-then-archive-in-one-transaction limitations remain as documented in G01-SCOPE-OBSERVATION-EN.md.
- No authority/RECEIVED/usable change; ADR-032 remains proposed.
- No fresh frontend build/browser smoke, CI/GitHub Actions, production/customer migration/deployment, integrated pilot, commit, push or PR. Local test success is not customer security/availability/crash-recovery certification.

## Recovery and next action

`epcc-source-recovery-scope-full-2026-10-18.tar.gz` is a standalone source-only recovery against exact base `0c54c7f84d1e343bf1d58833977ac0834dcfdcc0`, not an overlay requiring the older bundle. Its guide explains patch/archive inspection, checksums and clean reconstruction. Original full-suite execution is not repeated on the recovered copy: source identity and standalone33/26 cases are checked there. Older recovery and scope supplement are retained as historical artifacts.

The next development gate is a separately authorized/versioned, provenance-backed topology and scope-admission/coverage contract under ADR-024. Do not upgrade captured observations, non-null hierarchy claims or a green suite into engineering authority. G01 remains OPEN; do not repeat this closed full gate on unchanged source merely because old evidence says pending.
