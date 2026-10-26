# Baseline Verification — 2026-10-18

> Historical report; the subsequent complete final-source run is now GREEN (1083 tests /16385 assertions /0 failures/errors/skips). Current evidence: `FINAL-SOURCE-VERIFICATION-2026-10-18-EN.md`. Earlier pending-rerun statements below are historical.


> Historical targeted-baseline report. The subsequent complete run and PDF repair are recorded in `FULL-SUITE-VERIFICATION-2026-10-18-EN.md` (FA companion available); use that report for current runtime status.


Source baseline: `main` at `0c54c7f84d1e343bf1d58833977ac0834dcfdcc0`.
Local working branch: `arena/baseline-verification-2026-10-18`.
No remote push, PR, production data or customer deployment was involved.

## Outcome

A real PostgreSQL-capable development runtime and the Laravel display server now work in this workspace. Two reproducible defects were repaired without changing engineering approval, tenancy or authorization rules. Current capability boundaries and next work are indexed in `CURRENT-IMPLEMENTATION-LEDGER-EN.md` (Persian companion available).

**This is targeted stabilization, NOT a passing full-suite certification.** The full run was stopped for focused triage after real failures appeared. A complete serial full-suite run is still the next acceptance gate.

## Runtime evidence

- Debian 13 official packages: PHP 8.4.26, Composer 2.8.8, PostgreSQL 17; Chromium/poppler/Vazirmatn installed.
- Locked Composer dependencies installed without ignoring platform requirements.
- Application role checked directly: `epcc`, `rolsuper=false`, `rolbypassrls=false`.
- Separate `epcc` and `epcc_test` databases; `btree_gist` provisioned by admin.
- Initial `php artisan migrate --seed --force` completed against the disposable development database.
- Application frontend built successfully with ignored Node 22/npm 11 tools. Node 20/npm 10 installation first failed inside npm's resolver. App has no tracked npm lockfile; dependency pinning remains an improvement item.
- Real Laravel server listens on `0.0.0.0:8000`, not the static prototype.
- Ignored `.env` contains only synthetic development configuration. Preview URL session fallback is enabled for this demo and remains host-gated; disable it for customer deployment.

Recreation: `NATIVE-DEBIAN-RUNTIME-EN.md`. Installed system packages and ignored files are not guaranteed to survive a workspace restore.

## Defect 1 — Repeated fresh migrations fail

Reproduction: after one successful migration on the test database, a subsequent isolated SeedPathTest fails with SQLSTATE 42723: `epcc_pipe_schedule_exception_guard` already exists.

Cause: PostgreSQL standalone trigger functions survive Laravel's table-only `migrate:fresh` drop. The three 2026-10-15 migrations used unconditional `CREATE FUNCTION`.

Fix: use `CREATE OR REPLACE FUNCTION` with the same function bodies/signatures in migrations 100000, 110000 and 130000. Table creation and constraints remain unchanged; this does not make the entire migration idempotent or replace a production upgrade strategy.

Regression: new `PipeScheduleMigrationRebuildTest` executes fresh twice and verifies all five affected tables retain forced RLS and row-level BEFORE INSERT/UPDATE/DELETE guards with the append-only rejection branch.

Initial focused verification: rebuild + seed + PMS validator tests passed **6 tests / 79 assertions**.

## Defect 2 — PMS snapshot UUID interpreted as an integer

Reproduction: `ComponentModellingTest::test_the_model_page_renders_components_and_open_ends` returned HTTP 500. The underlying SQL compared a UUID snapshot primary key against integer `IN (0)` (SQLSTATE 42883); tenant-context restoration subsequently masked it with an aborted-transaction error.

Cause: `PmsClassRevisionSnapshot` inherited Eloquent's incrementing integer-key metadata even though its ID is a service-assigned UUID.

Fix: declare `incrementing=false` and `keyType='string'`. No UUID generator or write permission was added to this immutable snapshot model. The PostgreSQL snapshot immutability rules are unchanged.

Regression: new `PmsClassRevisionSnapshotKeyTest` checks UUID metadata, identity preservation and the relation's related model type. The existing component HTTP suite exercises actual eager loading and schedule/report behavior.

A remaining existing component-screen assertion expected the obsolete exact markup `<td class="mono">80</td>`. The screen now includes PMS comparison status in that cell. The assertion now uses DOM/XPath to check the actual PIPE row's schedule starts with 80 and includes `Matches issued PMS schedule`; it is not relaxed to a page-wide occurrence.

The positive PMS validator unit test was reported risky because it made no assertions. It now checks both source and draft schedule values remain intact after pure validation.

## Executed checks

- Standards/reference-pack validator: passed.
- PHP standards-engine conformance and standalone material screening: passed; screening reports 60 assertions.
- Five prototype suites: passed (render matrix 324 combinations; sidebar 19, piping workspace 16, MTO acceptance 15, order-gap 19).
- Frontend build: passed.
- Focused final PHP regression: **56 tests / 271 assertions, 0 failures, 0 errors, 0 skipped**. See `evidence/2026-10-18/summary.json` for exact totals/status.
- Browser smoke on actual Laravel: synthetic EPC login succeeded; portfolio, document register, piping workspace and order-gap board returned HTTP 200 in FA and EN (8 checks); no page-level JavaScript exceptions observed. This checks rendering, not complete transactional journeys.
- Persian document register at 430px: direction RTL, document scrollWidth=430, non-empty content; screenshot inspected. This is one device-width check, not comprehensive mobile accessibility certification.
- PHP lint and `git diff --check`: passed at final review.

## Test-run provenance and limitations

The initial broad run overlapped accidentally with the root runner, which also invokes PHPUnit once PHP/vendor are present. Those results are INVALID as a clean baseline and are retained only outside the repository as diagnostics. An isolated SeedPathTest subsequently established the repeat-migration failure independently. An isolated full run before the fix was stopped because of the repeated migration errors. A later serial full run after the migration fix reached real assertion failures and was stopped to investigate the first one, not reported as complete. The component-focused run after the UUID fix exposed the old exact-HTML assertion and a missing Laravel boot in the newly added metadata test; both test defects were corrected before final targeted verification.

Never execute two DB suites against epcc_test concurrently. Root `scripts/run-tests.sh` is NOT a standalone-only runner when PHP/vendor exist. Use separate independent commands for conformance/prototype checks while a DB run is active.

Full suite, Docker image/compose jobs, load/concurrency stress, backup recovery and complete engineering workflows are not certified by this work. In particular, TQ/NCR metadata capture is not issuance, signature verification or engineering acceptance; no new release gate was introduced. The tenant-context exception-masking behavior and missing app npm lockfile are recorded for subsequent work rather than silently changed.

## Next action

Run the complete suite serially, capture JUnit and classify every remaining failure. Do not start a new business feature until this gate is green or each exception has an explicit owner decision. Then agree the end-to-end piping pilot and implement one ADR-backed missing slice. Do not bulk-merge pre-work or add deferred MTO permissions.

Full raw logs/browser screenshots are under `/home/user/epcc-evidence`; compact verification evidence is copied into `docs/evidence/2026-10-18/`. These are synthetic local results, not customer records.
