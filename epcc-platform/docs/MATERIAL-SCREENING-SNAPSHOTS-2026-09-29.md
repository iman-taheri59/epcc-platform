# Immutable submission-time material screening evidence

> **Subsequent increment:** `MATERIAL-FINDING-RESPONSES-2026-09-29.md` adds an
> append-only cited discussion per captured finding. Captured result JSON/hash
> remain immutable; the rendered panel now also contains separately labeled,
> later-added notes. No automatic finding resolution or acceptance is implied.
> Runtime/counts below describe the earlier snapshot increment.


Date: 2026-09-29. Continuation of the user's selected engineering-check workstream.

## Delivered

Each **new non-fitting material submission** now atomically captures:

- The exact stored declaration fields supplied to the checker, including original wording, actual Spec revision/clause and technical properties.
- The computed bilingual findings, overall screening status, rule version, consulted reference identifiers/versions/source descriptions/SHA-256 values and explicit non-approval/non-consumption flags.
- Submission/run, definition version, project/company, submitter and submission timestamp.
- SHA-256 of the serialized result and of the implementation file at capture time. The input digest is retained in the result.

The page shows **Current technical screening / کنترل فنی روز** separately from **Screening captured at submission / کنترل فنی ثبت‌شده هنگام ارسال**. Historical findings are read from stored evidence, never recalculated. Their source and identity details are expandable. Audit time is displayed with an explicit UTC label.

This remains **evidence recording, not an engineering approval gate**. INCOMPLETE/DISCREPANCY/REVIEW_REQUIRED findings do not automatically reject a submission or override a human decision. If evidence cannot be persisted, the submission transaction rolls back: this is integrity enforcement, not a new engineering acceptance rule. Nothing is applied automatically to PMS/model/MTO.

## Data design and integrity

Migration 53: `2026_09_29_180000_capture_material_screening_snapshots.php`.

- New `material_screening_snapshots` table, primary key `run_id`, one record per submission, forced RLS and no UPDATE/DELETE policies.
- Composite FK binds the snapshot to the run's project, company, definition AND submitter. Source equality is independently checked against the authorized stored definition in the INSERT policy.
- `input_json` and `result_json` are text containing validated JSON, preserving exact serialized bytes. `jsonb` would reorder object keys and invalidate byte-level fingerprint reproduction. UI pretty-printing is a display, not the byte stream used for hashing.
- DB checks enforce JSON shape, supported screening statuses, false engineering-approval/automatic-consumption flags and matching input/result SHA-256 digests. The empty-properties PHP `[]` representation is explicitly matched to the source DB's empty JSON object.
- New `fitting_review_runs.screening_run_id` is NULL for historical runs. A BEFORE INSERT trigger **overrides client-supplied values**, pointing new non-fitting runs to their own ID and keeping FITTING NULL.
- A native **DEFERRABLE INITIALLY DEFERRED FK** requires the referenced evidence before transaction commit. It works even after nested tenant context is restored, without custom security-definer or tenant-bypass logic. The snapshot's reverse composite FK ensures the run already exists when evidence is inserted.
- The existing project advisory lock, immutable policy capture, ordered independent signers, correction lineage, archive protection and tenant checks are unchanged.
- `MaterialScreeningEvidence` centralizes the source-field allowlist, current calculation, capture and historical read. Both controller and review service use it; no HTTP endpoint accepts a report or hash as authoritative input.

DB constraints verify provenance/shape/hash consistency, not the truth of the engineering algorithm. Hashes are **not digital signatures, purchased-source verification or proof that a reviewer read the findings**. The implementation-file hash identifies the file at capture; it is not a cryptographic runtime attestation. Full historical reference-pack/code archival and replay are not implemented by this table; stored versions/digests identify what was consulted, while the computed result itself is retained.

## Historical behavior

- Migration does not populate evidence for old runs, even if they are approved or still in progress.
- UI explicitly says that no submission-time screening was captured for such a run. Current screening is not relabeled as historical evidence.
- The INSERT policy rejects attempts to backfill a legacy run with today's checker, because its immutable `screening_run_id` remains NULL.
- Existing legacy approvals and all previous source/policy/run/decision column values are preserved. Old decisions can continue under their captured workflow; no new retroactive approval requirement is imposed.
- Correcting a declaration keeps the old evidence and creates an unreviewed new version. A fresh snapshot is captured only when that new version is submitted.
- FITTING is still outside the new non-fitting checker; its existing B16.9/cap workflow remains unchanged, including the ½–48 scope boundary.

## Verification this increment

- Combined targeted regression: **108 PHP tests / 1,118 assertions passed**. Includes existing material/fitting workflows, definitions, populated migrations, B16.9 scope/caps/dimensions, PMS body/compliance and the new snapshot tests.
- New snapshot coverage: **11 tests / 92 assertions** (included, not additive). A final focused rerun after the audit-time presentation adjustment also passed 11/92.
- Covers exact source/result hashing, attribution, captured reference metadata, ignored forged POST data, bilingual HTML escaping, changed-reference live output versus unchanged history, corrections, no duplicate capture, forced-RLS immutability and tenant/project isolation, capture-failure rollback, raw-SQL attempt to disable/omit mandatory evidence, source/hash/approval/creator forgery, all five families with empty technical properties, and non-blocking human review.
- Migration test starts at schema 52 with a populated approved PIPE review, applies 53, compares every prior column, confirms approval survives, verifies explicit legacy UI and rejects retroactive evidence insertion. Existing 51→current fitting preservation test also passes.
- Initial missing-evidence test exposed a harness detail: deferred FK errors at PDO commit are PDOException rather than Laravel QueryException. Catch corrected to PDOException; constraint remained unchanged and the final test proves rejection/rollback.
- Screening core: **60 assertions passed**. **21 Python reference tests passed**, all **3 project + 16 reference packs valid**.
- Actual Chromium three-actor PIPE flow passed, including return, correction, policy snapshot isolation and final independent approval. Original captured evidence text remained identical across the flow; the replacement has a different captured run.
- **24 final read-only browser cases** passed: six categories × FA/EN × 1440/430. Correct live/historical record counts, HTTP 200, text direction, no root overflow or JS errors. Persian mobile snapshot panel visually inspected.
- Ten changed PHP files passed syntax checks. No full PHP-suite rerun, concurrency/load test or production security certification claimed. Previous 484-test run is historical, not a count for this increment.

Evidence: `/home/user/organization-report/screening-snapshot-final.{log,xml}`, `screening-snapshot-focused-final.{log,xml}`, `screening-snapshot-core.log`, `screening-snapshot-reference.log`, `screening-snapshot-packs.log`, `screening-snapshot-browser/results.json`, `screening-snapshot-pages.json`, screenshot files and source manifest/archive. The browser write scenario refuses reruns over existing records; the pages-only script is safe to rerun.

## Runtime

Dependencies and PostgreSQL state were absent on entry and rebuilt. Only the user's authorized disposable demo was initialized; tests remain isolated in `epcc_test`, using a non-superuser/NOBYPASSRLS role. `.env` unchanged; vendor is an excluded-cache symlink.

Current demo: **53 migrations, 6 synthetic definitions, 2 PIPE policies, 2 review runs, 3 decisions, 2 immutable screening snapshots**. PIPE's original is returned/superseded; its correction is approved under two-stage v1 while latest v2 governs future one-stage submissions. FLANGE/VALVE/GASKET/BOLTING roots are unreviewed and unconfigured. Synthetic evidence is not construction authorization.

Processes: PostgreSQL `project-database-2405ba99`; app `epcc-platform-b88df348`, 0.0.0.0:8000, APP_DEBUG=false. Local browser acceptance does not verify the external Arena gateway.

## Remaining boundaries

Audited project-specific applicability/N/A/source-evidence handling before any automatic engineering gate; full historical reference/code archival and replay; broader verified compatibility/design-condition checks; explicit reviewed-version PMS selection/conflict handling and downstream consumption; audited rerouting/withdrawal/revocation; unchanged-version resubmission; large-cap agreements and two-size fitting declarations.
