# G01 — NEW acceptance chronology connected to real Laravel snapshots

> FULL GATE NOW CLOSED: subsequent complete run on this SAME446-file source passed1098 tests/16429 assertions,0 failures/errors/skips. Current evidence: `G01-JOURNAL-FULL-SUITE-VERIFICATION-EN.md`. The related-stage pending statements below are historical; no implementation changed for the full run.


Date: 2026-10-18. **IMPLEMENTED locally, related tests GREEN; full suite on changed application source PENDING.**
This is NEW acceptance provenance, **not effective MTO selection, a scope manifest, legacy backfill or a fixed OrderGapService**. FA companion available.

## Actual implementation

- Migration `2026_10_18_100000_record_new_mto_acceptance_order.php` expands the real app schema with a per-project acceptance clock and immutable `mto_acceptance_journal`.
- An AFTER INSERT trigger on a **new** accepted batch records its exact project/company/batch/document/revision tuple and a project-local increasing ordinal. A project row lock serializes acceptance with the existing archive service. The clock and journal are part of the same transaction as the batch/rows/source state.
- `MtoAcceptanceService` now locates the real source and locks **project → document → revision**. It rechecks project/source consistency and refuses inactive projects. It does not trust the caller model's possibly stale project attributes.
- Read-only model `MtoAcceptanceJournal` and `AcceptedMtoBatch::acceptanceJournal()` expose provenance without adding it to existing API serialization by default. No new routes, grants, actor roles or human approval surface.
- Every entry is constrained to **origin=NEW, selection_state=UNRESOLVED**. Missing scope/hierarchy remains missing in the accepted snapshot; it is not fabricated or upgraded to effective coverage. No caller can use this journal as an approved selected-gross report.

## Integrity and safety boundaries

The journal's composite FK binds the entire snapshot tuple. New frozen rows additionally get a matching composite parent FK. That source-row FK is deliberately **NOT VALID** for historical data: PostgreSQL enforces it on new inserts while an explicit legacy inventory/VALIDATE gate remains open. The migration does not relabel old anomalies as validated.

Clock changes must advance by one; a second accepted revision gets a different ordinal even if accepted timestamps tie. Rollback can reuse an **uncommitted** allocation; committed ordinals are unique and immutable. These NEW ordinals are not reconstructed historical global chronology.

Journal writes are automatic through the batch-insert trigger. A trigger-depth integrity check rejects ordinary direct INSERT; **this is not an authorization/capability boundary against a schema owner or attacker-created triggers**. The app's trusted SQL role retains schema privileges as before. Model hooks reject create/update/delete and DB restrictive RLS blocks history mutations; the existing immutable-snapshot trigger also protects journal UPDATE/DELETE by privileged DML. Malicious schema administrators remain outside this guarantee.

Both new tables have FORCE RLS using project-participant/context membership and index-friendly project-set predicates. Archive restrictions reject new writes but preserve reads. No participant is made an MTO approver by this change.

A deferred batch constraint checks journal existence, a nonempty frozen-row set and the matching source revision's ACCEPTED state at commit. It is a bounded integrity check, **not a full multi-scope/staging-manifest or evidence-admission seal**; the real acceptance service remains responsible for validating and copying every staging row atomically. The deferred trigger temporarily pins only its already-inserted source company/project to check integrity after nested TenantContext restoration, restores both settings on success, uses no SECURITY DEFINER or RLS bypass, and returns no source data. Failure rolls back the whole transaction/local settings. A regression explicitly verifies restoration under nested different-company context.

Existing accepted snapshots/labels/hashes are not mutated. The migration's expansion over an already accepted batch is tested: no journal/clock/ordinal is fabricated. A normal new acceptance can be observed separately from historical missing chronology; it does not supersede a legacy scope without a controlled reconciliation design.

The migration is **forward-only**: down() throws rather than deleting recorded provenance. Do not use migrate:rollback as an operational rollback for this slice. A reviewed forward correction/controlled disabling of the new writer/read path must preserve history. No customer deployment was performed.

## Executed evidence on final source

| Check | Result |
|---|---|
| Related configured PHPUnit tests | **89 tests /240 assertions**, exit0 |
| Failures/errors/skips | **0/0/0** |
| New journal cases within that run | **15** |
| Related coverage | MTO acceptance/staging, engineering estimates, order gap, project archive, RLS and sargability suites |
| Separate real-service contention harness | **5 scenarios passed** |
| Application source integrity | **446** recorded source/test/template/reference hashes at final related run |
| PHP/Python syntax checks | Passed for touched/new files |
| Complete PHPUnit suite on this changed source | **NOT RUN / PENDING** |

The prior1083/16385 complete green run belongs to **pre-journal source**. Two of its443 source files changed and three app source/test/migration files were added. Do not combine that older full result with this89-test result or call the new full gate green. An earlier local test-harness run caught PDOException-vs-QueryException behavior at COMMIT; another negative fixture initially hit a UNIQUE key before the intended FK. Both fixtures were corrected and the final89-test run passed; historical error logs are retained with explicit names.

### Real Laravel concurrency, not synthetic SQL functions

Files `scripts/tests/mto_journal_worker.php` and `scripts/tests/test_mto_journal_concurrency.py` boot independent real application kernels and call **MtoAcceptanceService / ProjectArchiveService** under TenantContext. The first worker holds its outer transaction after the service returns; pg_blocking_pids verifies that the second worker is truly blocked before release.

1. Two different draft revisions both commit, producing separate NEW journal ordinals.
2. First acceptance rolls back; second commits with no orphan rows/clock/journal.
3. Two workers attempt the same revision: one commits, the second receives the existing revision validation rejection; exactly one acceptance survives.
4. Archive commits first; waiting acceptance is rejected as inactive, with no new journal.
5. Acceptance commits before waiting archive; both complete in that order and history remains.

The harness asserts source/batch/row/journal/clock deltas, no orphan batch after these tests, and all journal selection states UNRESOLVED. Archive cases reopen the synthetic project using the real service. No private customer data or production DB was used.

## Environment and reproduction

PHP8.4.26, PHPUnit12.5.35, PostgreSQL17.11, psycopg3.2.6; locked Composer dependencies restored under ignored cache. Dedicated disposable `epcc_test` under `/tmp/epcc-app-journal-pg-*`, private Unix socket `/tmp/epcc-app-journal-sock-*`, port5545, no TCP listener, local trust **test only**. Actual epcc role is non-superuser/non-BYPASSRLS/non-CREATEDB/non-CREATEROLE. fsync/synchronous_commit/full_page_writes ON; WAL128/32MB. .env/APP_KEY/dependencies/database data are not in recovery archives. No app preview/demo server was started.

From app/, with the isolated DB and ignored development env correctly provisioned:

```sh
vendor/bin/phpunit --colors=never \
  --filter 'MtoAcceptanceJournalTest|MtoAcceptanceTest|MtoStagingTest|EngineeringEstimateTest|OrderGapTest|ProjectArchiveTest|RowLevelSecurityTest|RlsSargabilityTest' \
  --log-junit /path/to/related.xml
```

For the concurrency harness, **after** PHPUnit finishes, prepare a fresh disposable demo/test database using migrate:fresh --seed (destructive, epcc_test only). Then from repo root:

```sh
EPCC_APP_JOURNAL_TEST=isolated \
EPCC_APP_JOURNAL_SOCKET=/tmp/epcc-app-journal-sock-REPLACE_WITH_ACTUAL \
EPCC_APP_JOURNAL_EVIDENCE=/path/to/concurrency.json \
  /usr/bin/python3 scripts/tests/test_mto_journal_concurrency.py
```

The ignored app .env must point at that same socket/port5545/database epcc_test. The harness needs local admin observation and synthetic seeded PH-14/EPC-PARS actors; it writes test fixtures. Both workers and orchestrator refuse normal app/production connection settings. Never run alongside PHPUnit or on customer data. The PHPUnit bootstrap purges seeded attachment files; demo reinitialization, if needed, comes only afterward.

## Current gate / next step

**First next action: run the complete configured suite serially on this exact new source, preserving its manifest; do not change more app code while that gate is running.** The previous baseline green is historical now. No full-suite success is claimed for the changed source.

After that gate: pinned authoritative scope manifest/topology and controlled evidence/legacy reconciliation interfaces, then deterministic effective selection and unresolved-state consumers. Current OrderGapService still aggregates all accepted rows; this slice intentionally does not change ordered coverage, usable stock, inspection, MTO rights or ADR-032. Integrated pilot remains unexecuted. No commit/push/PR or production migration.

Evidence: `docs/evidence/2026-10-18-g01-app/{summary.json,related.xml,related.log,concurrency.json,concurrency.log,database-posture.log,tested-source-manifest.json}`.
