> **Current closeout —2026-10-19:** [topology-source full GREEN](G01-TOPOLOGY-FULL-SUITE-EN.md) / [فارسی](G01-TOPOLOGY-FULL-SUITE-FA.md): **1151 tests/16776 assertions,0 failures/errors/skips,exit0;454 source+2 worker hashes unchanged**. Full-source pending gate CLOSED; older pending/next-full prose below is superseded history, not current instructions. No app edits this closeout. G01 OPEN; comparison is NOT authority/admission, UNADMITTED/UNRESOLVED, gross null, no role/route/migration/OrderGap change. Next: accepted-decision-backed read-only disposition/validity dependencies, not repeating this unchanged full gate. Current source-only recovery: epcc-source-recovery-topology-full-2026-10-19.tar.gz. Private temporary PG stopped after closeout; verify current cache metadata after resets.

# G01 — complete suite GREEN on exact-CDE-integrity source

Local date:2026-10-19 (Asia/Tehran); execution UTC date:2026-10-18, hence the retained evidence directory date. Status: **GREEN_COMPLETE_SUITE_ON_CDE_INTEGRITY_SOURCE**.

## Executed complete result

| Metric | Actual result |
|---|---:|
| Configured Unit + Feature tests | **1134** |
| Assertions | **16598** |
| Failures / errors / skipped | **0 / 0 / 0** |
| Exit | **0** |
| JUnit duration | **1323.967296 seconds (~22m04s)** |
| Source hashes unchanged before/during/after | **452** |
| Worker harness hashes unchanged | **2** |

From app/:

```sh
SESSION_URL_FALLBACK=false vendor/bin/phpunit --colors=never --testdox \
  --log-junit ../docs/evidence/2026-10-18-cde-full/phpunit.xml
```

Evidence: `docs/evidence/2026-10-18-cde-full/` — full raw log, JUnit, exit, frozen452-source and2-worker manifests, runtime/dependency/posture logs, independent check outputs, summary and SHA256SUMS. No application/model/migration/test/template source changed in this closeout. Subsequent changes are documentation/recovery only. The full-suite pending gate for the452-file CDE source is now CLOSED; the preceding85/288 related result and1112/16488 previous-source complete result remain historical evidence, not current pending gates or additional test counts.

## Runtime reset and isolation

Ignored runtime had vanished after workspace restoration; no old private PG data or vendor tree was assumed to exist. Recreated Debian PHP8.4.26/extensions, Composer and the **147 locked packages** without editing composer.lock or app source, plus PostgreSQL17.11, Chromium/poppler/fonts required by the established suite. Composer install used --no-scripts. Existing ignored env values were not published. PHPUnit12.5.35 was restored from lock.

Created a NEW disposable cluster with fresh epcc_test only, not a customer/demo database. Actual app role epcc: non-superuser/non-CREATEDB/non-BYPASSRLS. Private Unix socket5545; no TCP. Local trust is TEST ONLY. fsync/synchronous_commit/full_page_writes ON; WAL128/32MB. Data is on excluded disk cache, not tmpfs. No RLS/integrity guard was disabled. Database tests ran serially with no competing bootstrap/seed/blob purge or service-contention harness. No disk exhaustion/recovery event occurred in this run.

Current runtime metadata: `/home/user/.cache/epcc-app-journal-cluster.json`; data directory `/home/user/.cache/epcc-app-journal-pg-cpv573x2`, socket `/tmp/epcc-app-journal-sock-qp8e_g2c`. These are ephemeral runtime locations, NOT source recovery paths. Database is stopped after closeout. Do not copy stale paths from older reports after another reset; recreate runtime using the native guide and verify current metadata/posture.

## Independent checks

Composer validate/platform,360 PHP lints scoped to app/app,database,routes,config,tests, standards packs, rule-engine conformance, material screening and its synthetic example passed. Offline proposal46, selection33 and planner26 passed independently; do not add them to1134. No new actual two-connection contention, crash recovery, browser smoke/frontend build, CI/GitHub Actions or integrated pilot run is claimed.

## Product/authority boundaries unchanged

Read [decision reference index](DECISION-REFERENCE-INDEX-EN.md) first. Accepted ADR-018 evidence, mapping/withdrawal, header ancestry, coverage and immutable historical-roll-up decisions remain the baseline. MTO permission/access redesign remains explicitly deferred.

- CdeEvidenceIntegrityService is an internal read-only exact-visible-tuple/local-byte verifier; no endpoint/role/grant/migration or existing-consumer switch.
- BYTES_VERIFIED does not establish engineering truth, issuer authenticity, immutable retention, present revocation status or authority for topology/coverage/additivity.
- WIP/SHARED content mutability and CDE state/suitability are reported, not silently promoted to approved evidence. Archived exact reference is not substituted with current revision.
- Metadata requery is not atomic filesystem+DB locking; ABA, later mutations and caller snapshot visibility limitations remain. The injected metadata-drift test is not independent-worker concurrency evidence.
- Observation/admission remains UNADMITTED; effective selection UNRESOLVED, readiness false/gross null. OrderGapService still aggregates accepted rows as before; selected gross/overlap issues are not fixed.
- No legacy backfill, historical FK validation, new MTO authority, usable/RECEIVED rule change, customer/production migration/deployment, commit/push/PR. ADR-032 remains proposed. Local green is not security/load/availability/backup certification.

## Recovery and next bounded slice

`epcc-source-recovery-cde-full-2026-10-19.tar.gz` is standalone source-only recovery on exact base0c54c7f84d1e343bf1d58833977ac0834dcfdcc0. It includes current docs and evidence; excludes runtime, credentials/env values, dependencies and DB. Clean reconstruction verifies452 source hashes and all changed/new content and reruns offline46/33/26 only; app/PG complete tests are NOT rerun on recovered copy. Old pending bundles remain historical.

Next technical slice: scoped, read-only observation-to-proposed-topology reconciliation and/or evidence-validity dependencies using accepted ADR-018 semantics; continue to distinguish structure/byte verification from authenticated admission. No operational writer/new permission gate by inference. G01 remains OPEN; do not repeat this CLOSED full gate on unchanged source merely because historical documents contain pending instructions.
