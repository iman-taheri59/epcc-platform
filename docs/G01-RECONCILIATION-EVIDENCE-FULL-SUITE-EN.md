# G01 — complete suite GREEN on reconciliation/evidence source

2026-10-19 local (Asia/Tehran) and UTC. **GREEN_COMPLETE_SUITE_ON_RECONCILIATION_EVIDENCE_SOURCE**. [فارسی](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-FA.md).

> **Known defect is NOT closed:** DocumentRegister's RLS-visible MAX allocator can collide with a private document's already-used project-wide number. This full pass is regression evidence on the scoped diagnostic source, NOT a correction or certification of numbering. See the retained reproduction and recommendation below.

## Actual complete gate

| Metric | Actual |
|---|---:|
| Configured Unit + Feature tests | **1169** |
| Assertions | **17146** |
| Failures / errors / skipped | **0 / 0 / 0** |
| Exit | **0** |
| JUnit duration | **1211.438979 seconds** |
| Frozen source hashes unchanged | **456** |
| Worker harness hashes unchanged | **2** |

```sh
# app/, serially, isolated disposable epcc_test only
SESSION_URL_FALLBACK=false vendor/bin/phpunit --colors=never --testdox \
  --log-junit ../docs/evidence/2026-10-19-reconciliation-evidence-full/phpunit.xml
```

Evidence: `docs/evidence/2026-10-19-reconciliation-evidence-full/`: raw log/JUnit/exit, frozen456-source and2-worker manifests, Composer/platform/posture and independent outputs, summary, link checks and SHA256SUMS. Source is hash-identical to the preceding101/812 focused source. No app/model/migration/test/template/lockfile edits in this closeout; later edits docs/recovery only. The18 new tests/370 assertions are within the full suite; do not add the focused101/812 or standalone checks to1169.

## Decisions and unchanged boundaries

Read [decision reference index](DECISION-REFERENCE-INDEX-EN.md), accepted ADR-018 §§9.2–9.6 and [dependency diagnostic contract](G01-RECONCILIATION-EVIDENCE-EN.md). MTO authority remains explicitly deferred. This gate verifies regression behavior, not approval of new roles or a trusted operational admission envelope.

The internal service composes fresh frozen-topology comparison with exact same-project CDE revision/file byte checks, followed by a second source read. A hidden/drifted source or malformed dependency stays incomplete. Differences do not become approved dispositions just because hashes match. The claimed topology/coverage file purposes and semantic relationships remain unauthenticated; an unrelated file or a file reused for both purposes can pass byte integrity without proving independent coverage.

Even DEPENDENCY_BYTES_CHECKED/diagnostic_complete=true only means the bounded sequential byte diagnostic completed. It does NOT mean authenticated historical topology, complete coverage, issuer authority, immutable retention, present revocation validity or admission. Checks are NOT atomic across DB and filesystem; later file changes, ABA and caller snapshot visibility limits remain. The source-recheck stream hook is not independent-worker concurrency proof; existing worker hashes were checked, not newly executed for contention certification.

Every output keeps evidence_relationship_verified=false, evidence_validity_verified=false, ready_to_admit=false, admission_state=UNADMITTED, ready=false and gross=null. Effective selection remains UNRESOLVED, G01 OPEN, existing consumers/OrderGap unchanged. No endpoint/CLI, role/grant/access redesign, schema/migration, disposition/admission writer, legacy backfill, usable-stock rule change, customer/production migration/deployment or commit/push/PR.

## Runtime reconstruction

Ignored runtime was absent after reset. Restored Debian PHP8.4.26/extensions, Composer and147 locked packages (--no-scripts), PHPUnit12.5.35, PostgreSQL17.11, Chromium/poppler/fonts, without source/lock/dependency-version edits. Existing env/APP_KEY/secrets retained and not published; ignored DB connection location/user updated for isolated runtime only.

New epcc_test only, no customer/demo DB; epcc non-super/non-CREATEDB/non-BYPASSRLS. Private Unix socket5545, no TCP; local trust TEST ONLY. fsync/synchronous_commit/full_page_writes ON, WAL128/32MB on excluded cache disk. Serial full run, no competing migrate:fresh/seeder/blob purge/concurrency runner and no disabled RLS/integrity guards. Database stopped after closeout.

Data `/home/user/.cache/epcc-reconciliation-full-pg-rbik7nwk`, socket `/tmp/epcc-reconciliation-full-sock-lq78n0a7`, metadata `/home/user/.cache/epcc-app-journal-cluster.json`. Transient excluded runtime is NOT in recovery and may disappear after reset. Native guides/current metadata/posture must be rechecked; do not reuse stale historical paths.

## Independent checks, historical scope and open defect

Composer validate/platform,364 scoped PHP lints, standards packs/conformance/material screening + synthetic example PASS. Offline proposal46/selection33/planner26 PASS separately. No new two-connection/crash/backup recovery, frontend build, browser smoke, GitHub Actions/CI, Docker or integrated pilot certification.

| Exact source snapshot | Evidence meaning |
|---|---|
|452-source CDE | Historical1134/16598 full pass |
|454-source topology comparison | Historical1151/16776 full pass |
|456-source dependency focused |101/812 related pass,18/370 new included |
|456-source complete now |**1169/17146 full pass, pending full gate CLOSED** |

Earlier pending/next-full prose for this same456-source is superseded, not current instructions. Older bundles/logs remain historical snapshots; no relabeling or adding totals across runs.

**Numbering defect:** the private-evidence reproduction used an internal Sahand DocumentRegister call that could not see Pars' private CIVIL/DWG row; nextSequence selected PH-14-CIVIL-DWG-0001 and project-wide uniqueness rejected it with23505. No authorized UI workflow or actual simultaneous race is certified by that fixture. Source allocator is unchanged. Preserved failure log/XML: `docs/evidence/2026-10-19-reconciliation-evidence/private-numbering-collision.*`. Final diagnostic fixtures use an unused synthetic REC-TEST namespace, not a relaxed constraint/RLS policy. This full pass therefore does not exercise a successful correction of the known collision.

Recommendation remains separate: review numbering/gap/rollback/archive/writer requirements, then design a project/discipline/type transactional allocator with non-disclosing counter access, atomic allocation and reviewed backfill from existing numbers. Add private cross-company, parallel, rollback/replay/archive regressions. Do NOT expose private documents, disable RLS or retry the same invisible MAX. No allocator schema, role or number-gap policy is approved/implemented by this report.

## Recovery and next work

Standalone source-only `epcc-source-recovery-reconciliation-evidence-full-2026-10-19.tar.gz`, baseline0c54c7f84d1e343bf1d58833977ac0834dcfdcc0, includes docs/evidence, excludes env/credentials/dependencies/runtime/DB. Clean reconstruction verifies all456 source+2 worker/content hashes and offline46/33/26 only; app/PG full NOT rerun on recovered copy. The preceding pending-full reconciliation-evidence bundle remains historical.

Next priority recommendation: decision/requirements-first design/reproduction for the known numbering defect as a separate bounded task. Evidence relationship/validity, authority, complete coverage and trusted admission still need future work within accepted semantics. Do not repeat this closed full gate on unchanged source just because historical prose says pending.
