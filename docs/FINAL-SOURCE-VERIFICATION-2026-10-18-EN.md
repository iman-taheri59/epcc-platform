# Final-Source Verification — Green Local Software Baseline

> HISTORICAL PRE-JOURNAL SOURCE: current446-file journal source has now passed a complete1098-test/16429-assertion run with zero failures/errors/skips. See `G01-JOURNAL-FULL-SUITE-VERIFICATION-EN.md`. The1083/16385 milestone below remains historical.


Date: 2026-10-18.
Baseline commit: `0c54c7f84d1e343bf1d58833977ac0834dcfdcc0` (`main`) plus the local stabilization repairs recorded in the previous reports.
Working branch: `arena/baseline-verification-2026-10-18`, uncommitted. No push, PR or customer deployment.

## 1. Closed gate: one complete green run on the repaired source

| Evidence | Result |
|---|---|
| Configured PHPUnit Unit + Feature suites | **1,083 tests / 16,385 assertions** |
| Failures / errors / skipped | **0 / 0 / 0** |
| Process exit code | **0** |
| JUnit recorded runtime | Approximately **17m59s** |
| Source integrity | All **443** previously recorded source/test/template/reference files matched the repaired-source manifest before and after the run |
| Concurrent database suites | None |
| PHP syntax | **359 files passed** |
| Standards/reference packs, PHP conformance, material screening, five prototype suites | Passed independently |
| Composer manifest validation and platform requirements | Passed |
| Frontend assets | Build and Filament asset publication passed |
| Real Laravel browser smoke after demo restoration | **8 authenticated FA/EN route checks returned 200; no page-level JS exceptions** |
| FA documents at viewport430 | RTL; page scrollWidth430 |

This run includes the MCC PDF layout repair and passes the original frozen-certificate test without changing it. It supersedes the previous report's pending final-source rerun. **The local full-suite gate is now closed.** Standard independent CI/merge review remains appropriate; no GitHub Actions result is claimed here.

No application, migration, template or test-source content was changed during or after this final run. Subsequent work in this session was documentation/planning and evidence preservation. Generated runtime assets and synthetic demo data were recreated separately.

## 2. Exact execution environment

- PHP8.4.26, PHPUnit12.5.35, PostgreSQL17.11, system Chromium/poppler/Vazirmatn from Debian packages.
- Locked Composer dependencies reinstalled into ignored `.cache/vendor`; `app/vendor` is a symlink supported by the existing test bootstrap's APP_BASE_PATH pin.
- Dedicated **test-only** PostgreSQL cluster on loopback port5433, separate from the demo application database on5432.
- Test data directory `/tmp/epcc-final-pg` is on tmpfs and intentionally disposable. This is not a production durability or crash-recovery test. Nothing from the database data directory is placed in Git/recovery archives.
- `fsync=on`, `synchronous_commit=on`, `full_page_writes=on`; these integrity settings were **not disabled** to obtain green tests. WAL size bounds were set to128MB max/32MB min to keep the disposable cluster within available memory.
- Host authentication SCRAM; local admin provisioning uses a separate Unix socket. Application role `epcc` has `superuser=false`, `bypassrls=false`, `createdb=false`; `btree_gist` was provisioned by the admin.
- The test process selected `epcc_test` from phpunit.xml, with `DB_PORT=5433` and `SESSION_URL_FALLBACK=false` as explicit process-only overrides. The demo `.env` remains pointed at5432.

Command from `app/`:

```sh
DB_PORT=5433 SESSION_URL_FALLBACK=false vendor/bin/phpunit \
  --colors=never --testdox --log-junit /path/to/evidence/phpunit.xml
```

The different port/storage location isolates the test lifecycle; the full configuration still uses real PostgreSQL/RLS and the same repaired source. To reproduce on a normal development cluster use its actual port and an expendable epcc_test database with the same non-superuser posture. Do not run two DB suites together, and do not invoke the root run-tests.sh concurrently: it also starts Laravel tests when PHP/vendor exist.

Runtime packages/ignored files may need recreation after a workspace reset. The recovery archive preserves source/docs/evidence, not the runtime or database.

## 3. Display environment

The actual Laravel app is running on `0.0.0.0:8000`. After the full test process ended (its bootstrap purges seeded blobs), a separate disposable demo database was initialized/seeded:8 companies,3 projects, non-superuser application role. Authenticated browser checks exercised portfolio, document register, piping workspace and order-gap board in FA/EN. This is bounded rendering smoke, not an end-to-end material lifecycle acceptance.

The ignored demo environment enables host-gated URL-session fallback for the sandbox preview. Keep it off in customer deployments; signed URL session tokens are still sensitive. No customer data was used.

## 4. What green does and does not mean

Green proves that all currently collected Unit+Feature cases passed together on this source in this environment. It does not prove every requirement is implemented, all business boundaries are tested, production deployment is secure, backup recovery works, Docker/compose jobs passed or all browser interactions are correct. The JavaScript files in app/tests/Browser are not collected by PHPUnit; the separately executed browser smoke is reported with its actual scope.

The pilot source review found important untested integration gaps:

- **G01:** OrderGapService still aggregates all accepted MTO revisions/scopes; effective accepted-revision and additivity implementation remains pending under ADR-024.
- **G02:** RECEIVED POs are excluded from the current order-coverage predicate; reconcile that with the gross-versus-ordered concept before changing semantics.
- **G03–G05:** normal receipts do not populate exact PO-item linkage; legacy delivery/progress calculations use code/aggregate quantities and need dedicated repeated-code, mixed-unit and amendment regressions.
- **G06:** usable-stock state/authority remains deliberately undecided; advisory inspection is not a new hard gate.
- **G07:** PO creation/inspection/receipt services are called by trusted demo seeding, but normal authenticated entry surfaces were not found in the current route/controller/action audit. Existing service tests are not UI delivery.

These are **source-reviewed findings, not newly executed failing pilot tests**. No business-code fix, role grant or schema was added for them in this session.

## 5. Next development work prepared

- `PIPING-PILOT-ACCEPTANCE-MATRIX-EN.md` / FA:21 proposed cases, synthetic quantities, existing component evidence, missing surfaces and explicit gaps. Integrated pilot cases are **not yet executed**.
- ADR-032 EN/FA: **PROPOSED**, not owner-approved, for completed-order semantics and physical source attribution. It does not authorize new receipt/approval rights.
- First recommended slice: **effective accepted-MTO revision/scope selection (G01 / ADR-024)**. The domain principles are already accepted; prepare deterministic append-only selection/additivity design and regression cases before schema implementation. Do not reopen closed scope principles, bulk-merge pre-work or add deferred MTO roles.

## 6. Evidence and source recovery

Compact evidence: `docs/evidence/2026-10-18-final/`, including full JUnit/console output, environment posture, source manifest, browser JSON and independent check outputs. Raw evidence: `/home/user/epcc-evidence/final-source-2026-10-18/`.

A refreshed source-only recovery bundle is produced outside the repository and verified against a clean checkout at the stated baseline. It contains the tracked patch, selected new source/docs/evidence and checksums, not `.env`, APP_KEY, credentials, vendor/node_modules or database data. Read its recovery instructions before applying it; never apply over uncommitted work. No commit or remote operation is implied.
