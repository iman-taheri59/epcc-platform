# Material family recovery and stored-record screening integration

> **Subsequent increment:** `MATERIAL-SCREENING-SNAPSHOTS-2026-09-29.md` adds
> immutable submission-time evidence alongside this live display. The earlier
> "not persisted" limitation below now applies only to the live computation;
> new submissions have captured history. Runtime/counts below are historical.


Date: 2026-09-29. Latest instruction: continue recovery, then integrate the engineering checks.

## Delivered

The missing material-family implementation has been **reconstructed and regression-tested**, rather than treated as recovered byte-identical source. Earlier execution evidence was retained; current tests are separate.

- Restored `ProjectMaterialSchema`, migration 52, family-aware definition/review/controller logic, category-specific form, FA/EN labels, and material workflow/migration tests.
- One shared immutable review engine, separate per-project/per-family policy versions. Existing fitting policies still apply only to FITTING. New families have no automatic authority/configuration.
- Category-bound correction FKs and review-run guard; source uniqueness for textual material sizes; immutable sources/history and original fitting constraints remain.
- Existing URL retained: project → piping → **Project material declarations**. Choose PIPE/FLANGE/VALVE/GASKET/BOLTING, or FITTING for the original fitting path.
- Each non-fitting record now displays **Technical screening / کنترل اولیه اطلاعات فنی**. Findings distinguish missing information, definite inconsistencies, and source review needs. Rule version, declaration fingerprint and consulted reference pack hashes are expandable.

## Important separation of concerns

The controller first authenticates/project-checks access and retrieves tenant-scoped stored records. It passes an explicit allowlist of their source fields to `MaterialScreening`. Query-string `properties` or `engineering_approved` values cannot forge these findings. Source text and finding/reference values are Blade-escaped.

Screening is **read-only and computed on display**. It writes neither the source nor review events, and is not a blocking submission/approval gate. Current computed results are not immutable historical screening snapshots. A pack/rule update may change displayed findings; the displayed versions/hashes identify the current calculation, not the evidence used for a historical human approval. Human review status remains separate and is neither upgraded nor revoked by this screen.

This does not introduce automatic PMS/model/MTO use. Existing independent signer/author-chain/archival/tenant protections are unchanged. B16.9 ½–48 remains fitting-only. Unknown reference coverage never becomes a fabricated standard limit. Actual project Spec verification, applicability/N/A handling and manufacturing tolerances still need engineering review.

For the precise read-only rule scope and reference limitations, see `MATERIAL-ENGINEERING-SCREENING-2026-09-29.md`; its former integration blocker is resolved by this increment.

## Current verification (not old report counts)

- **97 PHP tests / 1,026 assertions**: material and fitting review/definitions, populated migration, B16.9 scope/caps/dimensions, PMS body/compliance.
- **387 PHP tests / 9,959 assertions**: pipe/material references, material presentation, flange bolting/ratings and valve construction/operator/face-to-face/ratings/testing regressions.
- Total **484 targeted PHP tests / 10,985 assertions passed**. Not the full suite. Reconstructed material tests reorganize earlier cases; historical totals are not carried forward.
- Independent screening-core test: **60 assertions passed**.
- **21 Python reference tests passed; all 3 project + 16 reference packs valid**.
- Feature tests exercise current category workflow/no inherited policy, author-chain/independent review, returned and approved corrections, immutable records, raw SQL category-policy/lineage rejection, wrong-company/project actions, bilingual output escaping, category isolation, read-only source equality before/after GET, ignored forged query findings, and unauthorized screening access.
- Populated migration test builds the 51-migration schema, inserts definition/policy/run/valid decision, applies migration 52, compares every original column and verifies no new-category authority.
- Actual Chromium three-actor PIPE workflow passed: consultant return, linked correction retaining Spec A, new submission frozen to its two-stage policy, later one-stage policy does not bypass the original consultant stage, final independent approval.
- Actual FLANGE/VALVE/GASKET/BOLTING registration passed; no configuration inherited.
- **24 browser cases**: six categories × FA/EN × 1440/430. HTTP 200, expected screening count per record, source-review findings, correct direction, no root overflow or JS page errors. Persian bolting mobile screenshot inspected visually.
- Source preflight now reports no missing files. It remains a source-presence check, not a replacement for runtime or security tests.

Evidence under `/home/user/organization-report/`: `material-recovery-tests.{log,xml}`, `material-recovery-adjacent.{log,xml}`, `material-recovery-preflight.json`, `material-recovery-reference.log`, `material-recovery-packs.log`, `material-recovery-browser/results.json` and screenshots. Prior artifacts were not overwritten. Browser write script is guarded and must not be rerun over existing demo evidence.

## Current runtime

Dependencies/PostgreSQL were absent and were rebuilt. The current user-authorized disposable demo DB was initialized; tests use separate `epcc_test` under a non-superuser, NOBYPASSRLS role. `.env` unchanged.

Current application DB: **52 migrations, 6 clearly synthetic definitions, 2 PIPE policies, 2 runs, 3 decisions**. One PIPE root returned/superseded, its correction approved under captured two-stage v1; current v2 has one stage for future submissions. Four other material roots remain unreviewed/unconfigured. No fitting policy is seeded in this rebuilt runtime.

PG process: `project-database-079a60ed`. App process: `epcc-platform-181acd44`, listening on 0.0.0.0:8000 with APP_DEBUG=false. Local browser checks do not establish external Arena gateway accessibility. Vendor is a symlink into excluded cache. A source manifest and small recovery archive are retained with the evidence to aid future integrity checks; the original persistence discrepancy's cause remains unproven.

## Still pending

- Audited per-project applicability/N/A/source-evidence handling before any automatic blocking gate.
- Versioned/persisted screening evidence if it is to become part of formal approval history.
- Broader verified engineering checks (e.g. material compatibility and pressure/temperature design conditions); the current screening is not a conformance certificate.
- Explicit approved-version PMS selection, conflict reconciliation and downstream consumption.
- Audited review rerouting/withdrawal/revocation, unchanged-version resubmission; large-cap agreements and two-size fitting declarations.

No concurrency/load test or comprehensive production security certification is claimed.
