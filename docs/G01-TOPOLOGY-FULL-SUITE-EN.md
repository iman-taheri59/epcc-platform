> **Current closeout —2026-10-19:** [reconciliation/evidence full GREEN](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-EN.md) / [فارسی](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-FA.md): **1169 tests/17146 assertions,0 failures/errors/skips,exit0;456 source+2 worker hashes unchanged**. Full pending gate CLOSED; older pending/next-full prose below is superseded history. **Separate DocumentRegister RLS/MAX collision NOT FIXED**, recommendation retained. No app edits this closeout; G01 OPEN, UNADMITTED/UNRESOLVED, gross null, no role/route/migration/OrderGap change. Next recommendation: decision-first numbering allocator review, not rerun of unchanged full gate. Recovery: epcc-source-recovery-reconciliation-evidence-full-2026-10-19.tar.gz; isolated PG stopped after closeout, runtime metadata transient.

# G01 — complete suite GREEN on frozen-topology reconciliation source

2026-10-19 local (Asia/Tehran) and UTC. **GREEN_COMPLETE_SUITE_ON_TOPOLOGY_RECONCILIATION_SOURCE**. Companion: [FA](G01-TOPOLOGY-FULL-SUITE-FA.md).

## Actual complete result

| Metric | Result |
|---|---:|
| Configured Unit + Feature tests | **1151** |
| Assertions | **16776** |
| Failures / errors / skipped | **0 / 0 / 0** |
| Exit | **0** |
| JUnit duration | **1311.528845 seconds** |
| Unchanged source hashes | **454** |
| Unchanged worker harness hashes | **2** |

```sh
# from app/, serially, isolated expendable epcc_test only
SESSION_URL_FALLBACK=false vendor/bin/phpunit --colors=never --testdox \
  --log-junit ../docs/evidence/2026-10-19-topology-full/phpunit.xml
```

Full raw log/JUnit/exit, source454+worker2 manifests, runtime/dependency/posture logs, independent outputs, summary and checksums: `docs/evidence/2026-10-19-topology-full/`. The17 reconciliation tests/178 assertions and prior83/442 focused pass are within/related to the full result, NOT added on top. All source hashes match the preceding focused source manifest. No app/model/migration/test/template or lockfile edits in this full-gate continuation; subsequent edits are docs/recovery only.

## Decision and product boundary

Read [decision reference index](DECISION-REFERENCE-INDEX-EN.md) and [reconciliation implementation contract](G01-TOPOLOGY-RECONCILIATION-EN.md). ADR-018 §§9.2–9.6 and existing MTO permission deferral remain baseline. This full gate verifies regression behavior, not acceptance of a new authority policy.

- The comparator reads frozen observation edges, never today's mutable graph as historical truth. Proposal bindings are strict and source tuples/hash/row-set are freshly checked under existing invoker RLS.
- MATCHES_OBSERVATION is structural comparison of bounded fragments only. NOT_OBSERVED_AT_CAPTURE is not proof of a newly created node; NODE_NOT_IN_PROPOSAL is not approved retirement/zero.
- No authenticated historical topology, authoritative engineering coverage, evidence validity/disposition, or full trusted proposal/admission envelope has been established.
- In every comparator result: UNADMITTED, ready_to_admit=false, ready=false, gross=null. Effective selection remains UNRESOLVED. Existing readiness consumers and OrderGap remain unchanged.
- A single-statement snapshot is not a durable cutoff or a lock against later mutations; caller isolation visibility limits remain. No fresh independent two-connection contention/crash test is claimed. Existing worker hashes were checked, not a new concurrency certification.
- No operational writer, route/API, role/grant/access redesign, legacy backfill, production/customer migration/deployment, usable-stock rule change, commit/push/PR. G01 remains OPEN. Local full green is not engineering approval/security/load/availability/backup/pilot certification.

## Runtime rebuild and isolation

Ignored runtime had vanished after reset. Reinstalled Debian PHP8.4.26/extensions, Composer, PostgreSQL17.11, Chromium/poppler/fonts;147 packages restored from the same lock with --no-scripts, PHPUnit12.5.35. Existing env/APP_KEY/secrets retained and not published; only ignored DB location/user settings pointed to the fresh isolated runtime. No dependency versions changed.

Created fresh epcc_test only; no customer/demo DB touched. Application role epcc non-super/non-CREATEDB/non-BYPASSRLS. Private Unix socket port5545, no TCP; local trust is TEST ONLY. fsync/synchronous_commit/full_page_writes ON, WAL128/32MB. Cache-disk data, not tmpfs. Serial full suite; no competing migrate:fresh/seeder/blob purge/concurrency runner. No RLS/integrity guard disabled.

Runtime data `/home/user/.cache/epcc-topology-pg-1ge_atll`, socket `/tmp/epcc-topology-sock-v4vh9mdo`; metadata `/home/user/.cache/epcc-app-journal-cluster.json`. Database stopped after closeout. These excluded transient paths are NOT source-recovery material; recreate using native guide if absent and verify actual current metadata/posture rather than copying stale paths.

## Separate checks and exact historical meanings

Composer validate/platform,362 scoped PHP lints, standards packs/conformance/material screening + synthetic example passed. Offline proposal46/selection33/planner26 passed, not added to PHPUnit. No separate frontend build, browser smoke, GitHub Actions/CI, Docker or integrated pilot claimed.

| Snapshot | Exact scope/result |
|---|---|
|450-source scope gate | Historical1112/16488 complete pass |
|452-source CDE gate | Historical1134/16598 complete pass |
|454-source reconciliation focused |83/442 related pass;17/178 new included |
|454-source reconciliation full (now) |**1151/16776 complete pass; pending gate CLOSED** |

Earlier NOT_RUN/PENDING or next-full prose for this same454-file source is superseded by this dated gate; retained logs/manifests and older recovery bundles are historical, not relabeled. Do not rerun this unchanged closed gate solely because old prose says pending.

## Recovery and next bounded work

Current standalone source-only `epcc-source-recovery-topology-full-2026-10-19.tar.gz`, baseline0c54c7f84d1e343bf1d58833977ac0834dcfdcc0, includes docs/evidence and excludes env/credentials/dependencies/runtime/DB. Clean reconstruction verifies all454 source hashes/content and reruns offline46/33/26 only; app/PG full suite is NOT rerun on recovered copy. The preceding topology-reconciliation bundle intentionally remains a pending-full historical snapshot.

Next technical work, after applicable decisions are reviewed: bounded read-only evidence-backed reconciliation disposition/validity dependencies; keep full proposal envelope, historical topology authority and coverage/admission explicitly unresolved until implemented/proven. No human permission grant by inference and no silent writer/selector change.
