> **2026-10-25 evidence preflight prerequisite:** [Original-caller CDE byte preflight](SCOPE-EVIDENCE-PREFLIGHT-EN.md) now gates both selected references with independent doc.view and actual discipline before any file stream. It does not attach evidence, issue a permanent composition identity or establish retention/current validity; prior readiness/approval flags remain false.

> **Current closeout — 2026-10-19 local /2026-10-18 UTC:** [CDE-source complete suite GREEN](G01-CDE-FULL-SUITE-EN.md) / [فارسی](G01-CDE-FULL-SUITE-FA.md): **1134 tests/16598 assertions,0 failures/errors/skips,exit0;452 source and2 worker hashes unchanged**; JUnit1323.967296s. New-source full pending gate CLOSED. Earlier pending/no-full/next-full statements below are superseded history, not current instructions. No app source edits this closeout. G01 OPEN; byte integrity is NOT engineering approval/admission, UNADMITTED/UNRESOLVED, no authority/OrderGap change. Runtime recreated after reset; current cache cluster is in metadata, stopped after closeout. Next: accepted-decision-backed read-only reconciliation/validity work, not rerun of unchanged full gate. Current standalone recovery: epcc-source-recovery-cde-full-2026-10-19.tar.gz.

# G01 — exact CDE file integrity, read-only bounded adapter

2026-10-18. **RELATED_SUITE_GREEN_FULL_SUITE_PENDING** on new452-file source. Companion: G01-CDE-EVIDENCE-INTEGRITY-FA.md.

## Decision basis and delivered change

Read [decision reference index](DECISION-REFERENCE-INDEX-EN.md), MTO-REQUIREMENTS §3.5 and accepted ADR-018 §9.2: document-based evidence must cite an exact retained/retrievable immutable CDE revision; a hash alone is insufficient. This slice implements only the technical exact-reference/local-byte check, NOT evidence admission, issuer authenticity, coverage completeness, engineering approval or permission policy. It does not reopen the accepted additive/mapping/withdrawal/history rules.

Added:
- `app/app/Services/CdeEvidenceIntegrityService.php`
- `app/tests/Feature/CdeEvidenceIntegrityTest.php`

No migration, model modification, route, HTTP/CLI endpoint, role/grant, actor policy, upload, event writer or selector/OrderGap integration. Existing DocumentFileStore is unchanged. No existing consumer is switched to this new service. Any future endpoint must independently enforce existing authentication/entitlements; RLS-backed lookup is not a replacement for endpoint authorization.

## Exact read contract

`verify(projectUUID, documentUUID, revisionUUID, fileUUID, expectedSha256)`:

1. Validate UUIDs and lowercase64-hex expected digest; normalize UUID casing.
2. Refetch exact document/revision/file tuple, matching project and parent IDs in a single joined SQL statement under **normal invoker RLS on all three tables**. No tenant switch, SECURITY DEFINER or model/currentRevision fallback. Hidden, absent and mismatching references return the same generic finding without source metadata. Document number/title alone cannot resolve a reference. Identical bytes on another document/revision cannot substitute for the requested tuple.
3. Require stored SHA equal to caller's expected SHA; no fallback to a changed stored digest.
4. This first adapter supports **only configured local/local-driver storage**. Reconstruct the existing content-addressed path from bound project, digest and allowed extension; reject a differing DB path before reading storage. No arbitrary caller path, original filename, HTTP/URI, S3 or external evidence fetch.
5. Stream actual local scratch/retained bytes in64KiB chunks. Enforce recorded positive size and existing FileType100MiB ceiling; stop if byte count exceeds either. Verify total size and SHA-256 against the exact reference. Missing, unreadable, tampered, oversized/truncated content fails closed.
6. Requery selected metadata after reading; a visible difference returns SOURCE_METADATA_CHANGED_DURING_READ. This is a consistency check, not a lock/versioned retention guarantee.
7. Return visible source provenance only after success: exact IDs, digest, byte count, document number/revision label, state/suitability and issued-by/time. Never return storage path, disk, file bytes or exception internals.

Success is **BYTES_VERIFIED / integrity_verified=true**, but **ready_to_admit=false, admission_state=UNADMITTED, ready=false, gross=null**. Findings still include ENGINEERING_AUTHORITY_NOT_ESTABLISHED and RETENTION_AND_REVOCATION_NOT_ESTABLISHED. For WIP/SHARED, REVISION_CONTENT_MUTABLE is also present. Publication is not imposed as a new evidence policy: bytes can be checked in any visible state, while that state is reported honestly. PUBLISHED/A-family, B-family and ARCHIVED must not be confused with admission or approval for the requested purpose. Archived exact revisions remain inspectable rather than silently replaced with current revision.

## Limitations / recommendations

- Positive integrity establishes byte/digest agreement at read time, not the truth of the engineering content, issuer signature, immutable retention, admissibility of a state/suitability, exact MTO-pair authorization or current evidence validity.
- Local disk configuration/root and server filesystem are trusted administrative inputs. Canonical-path checks are not protection against a privileged filesystem/DB owner, symlink replacement under a compromised root, or malicious storage adapters.
- Requery detects changed selected metadata visible to that transaction; it cannot detect every ABA change, a mutation after the last read, or commits hidden by a caller's REPEATABLE READ snapshot. File bytes are not atomically locked with DB metadata. No simultaneous independent-worker race or crash-recovery claim is made.
- Metadata-drift test intentionally injects a DB mutation in the readStream test callback. It is NOT a two-connection contention proof.
- Byte check streams at most the existing100MiB attachment ceiling, but holds a read transaction while doing so. No load/performance certification, retained-object lease or report-cutoff guarantee is claimed.
- No external controlled-record/CDE-link creation, CDE evidence adapter wiring into the offline proposal, authenticated export, admission event, revocation reader or topology reconciliation is delivered. Those remain subsequent technical slices respecting the explicit MTO permission deferral.

## Actual tests

Private PostgreSQL17.11 `epcc_test`, actual restricted epcc role (non-super/non-BYPASSRLS/non-CREATEDB), PHP8.4.26/PHPUnit12.5.35, synthetic fixtures only, serial execution. Disk fake here is a real local scratch filesystem supporting real stream/hash reads, not a mocked successful integrity response; only the drift-injection test replaces disk access deliberately.

- **85 related tests /288 assertions, exit0,0 failures/errors/skips**.
- New service coverage within that total: **22 tests /110 assertions**.
- Includes document-file/register, scope observation and RLS sargability matched by the recorded PHPUnit filter.
- Dedicated cases: exact provenance/no writes; WIP mutable; archived revision vs current; same hash wrong document; outsider and private-WIP competitor denial before storage; published participant visibility; wrong project; malformed ID/digest; expected digest mismatch; missing/same-size tampered/truncated/oversized bytes; noncanonical path; unsupported remote disk; oversize record; metadata drift; wrong current-revision substitution; UUID casing; archived-project read.
- Full PHP lint (360 scoped files), offline proposal46, selection33 and planner26 PASS separately, not added to PHPUnit.
- Initial14-case run failed one fixture lookup because a seeded email did not exist; `initial.*` retains that failure. Corrected fixture uses actual nonparticipant Arvand identity. Final85-case run is the passing source result.

Evidence: `docs/evidence/2026-10-18-cde-integrity/` with JUnit/log/exit, manifest452, summary, posture and SHA256SUMS. The preceding450 source files are unchanged; two files were added. **Complete suite on452-file source has NOT run.** Previous1112/16488 green remains exact450-source historical evidence, not a complete gate for the new adapter. No production/customer migration, deployment, commit/push/PR, CI result, browser/frontend run or integrated pilot.

## Next gate

Complete suite on this exact452-file source, then a bounded reviewed technical reconciliation/retention-validity layer using the already accepted decision semantics. Do not create new MTO roles or an operational admission writer by inference. G01 remains OPEN; OrderGap unchanged. Current standalone recovery bundle is `epcc-source-recovery-cde-integrity-2026-10-18.tar.gz` (new-source full suite pending, not certified by the older scope-full bundle).
