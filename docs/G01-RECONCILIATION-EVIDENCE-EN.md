> **Current closeout —2026-10-19:** [reconciliation/evidence full GREEN](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-EN.md) / [فارسی](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-FA.md): **1169 tests/17146 assertions,0 failures/errors/skips,exit0;456 source+2 worker hashes unchanged**. Full pending gate CLOSED; older pending/next-full prose below is superseded history. **Separate DocumentRegister RLS/MAX collision NOT FIXED**, recommendation retained. No app edits this closeout; G01 OPEN, UNADMITTED/UNRESOLVED, gross null, no role/route/migration/OrderGap change. Next recommendation: decision-first numbering allocator review, not rerun of unchanged full gate. Recovery: epcc-source-recovery-reconciliation-evidence-full-2026-10-19.tar.gz; isolated PG stopped after closeout, runtime metadata transient.

# G01 — read-only reconciliation/evidence dependency diagnostic

2026-10-19. **RELATED_SUITE_GREEN_FULL_SUITE_PENDING** on456 source files. [فارسی](G01-RECONCILIATION-EVIDENCE-FA.md).

## Basis and implementation boundary

Reviewed [decision reference index](DECISION-REFERENCE-INDEX-EN.md), accepted ADR-018 §§9.2–9.6 and [draft admission contract](ADR-024-SCOPE-ADMISSION-CONTRACT-EN.md). Exact controlled revision/file identity, retained original/hash, no inferred mapping/coverage and immutable history are accepted requirements. MTO permission redesign remains deferred. This work grants no actor authority and does not reinterpret previously accepted lifecycle rules.

Two files added:
- `app/app/Services/MtoReconciliationEvidenceService.php`
- `app/tests/Feature/MtoReconciliationEvidenceTest.php`

An internal diagnostic composes the existing frozen-topology comparator and exact CDE byte verifier. Existing consumers remain unchanged. No route/API/CLI, schema/migration, role/grant, admission/disposition writer, source export endpoint, selector or OrderGap change. This is not the complete trusted proposal-envelope adapter and does not call the offline coverage validator.

## Interface and sequence

`inspect(batch, binding, nodes, references)` uses the existing exact seven-field source binding and proposed graph contract. References are exactly two **caller-claimed purposes**, topology and coverage; each is an exact object of project_id, document_id, revision_id, file_id, sha256. UUIDs and lowercase64-hex digest are validated, unknown fields rejected. All reference shapes are validated before any evidence stream. Explicit URI/path/storage/approved flags cannot be smuggled into this interface.

1. Fresh frozen-source comparison under existing invoker RLS. Incomplete/hidden source or invalid proposal stops before evidence reads.
2. Require evidence project to equal the freshly verified bound project. No arbitrary other-project resolution or tenant switch, even if a broader tenant context can see multiple projects.
3. Verify the exact CDE revision/file tuple and bounded local bytes separately for topology and coverage, using CdeEvidenceIntegrityService without latest-revision substitution.
4. Repeat source comparison after both evidence checks. Changed/hidden source clears all evidence results and keeps diagnostic incomplete; differences never become a disposition. A synthetic stream callback test demonstrates late accepted-row-set drift detection, not independent-worker concurrency.

This reference shape is a narrow internal CDE-only diagnostic interface, not a replacement for the offline proposal's controlled-record declarations. External controlled records/URIs remain accepted domain possibilities but are unsupported here; no new business prohibition is introduced. There are at most two bounded file checks; resource limits of the existing CDE verifier and10000-node comparator remain unchanged.

## Result meaning — explicitly not evidence validity

For stable source with both byte checks successful: status=DEPENDENCY_BYTES_CHECKED, diagnostic_complete=true, all_evidence_bytes_verified=true. For malformed/invisible/drifted source/reference or unsuccessful bytes: INCOMPLETE; failed evidence roles preserve their own reasons, but all-evidence and diagnostic flags are false. If final source is hidden/drifted, evidence=[] rather than retaining a stale successful subreport.

In every case:
- evidence_relationship_verified=false and evidence_validity_verified=false;
- ready_to_admit=false, admission_state=UNADMITTED, ready=false, gross=null.

The caller's assignment of file to purpose is **not authenticated**. Valid bytes of an unrelated document can pass byte checks; this service neither parses document semantics nor proves the file supports these specific nodes, rows or claimed hierarchy revision. Reusing the same file for both purposes is allowed as a diagnostic claim and never proves independent coverage. No issuer/engineering authority, complete coverage, immutable retention or present revocation status is established. WIP/SHARED bytes may be readable but are reported mutable; PUBLISHED/ARCHIVED is not promoted to engineering authority. A topology difference remains a difference even when both hashes match.

Checks are sequential, not an atomic DB+filesystem snapshot, durable cutoff, signature, lease or write capability. The first file can change after its check; ABA/later changes and caller snapshot visibility limitations remain. The second source read does NOT close these gaps. Every completed report lists EVIDENCE_CHECKS_NOT_ATOMIC and unverified roles/relationship, authority, coverage and retention/revocation findings. No operational validity/revocation ledger or reconciliation disposition is created.

## Actual verification

- **101 related tests /812 assertions, exit0,0 failures/errors/skips; JUnit163.589777s**.
- Within that total **18 new cases /370 assertions** (not additional to101).
- Filter includes reconciliation evidence, topology reconciliation, scope observation, acceptance journal, CDE integrity, OrderGap and RLS sargability.
- New cases: successful bytes/no writes/no input mutation/no path disclosure; differences not resolved by hashes; same-file role claims; incomplete source/stale binding before streams; strict shape/ID/digest types; foreign project; missing/corrupt blob; equal bytes/wrong tuple; mutable WIP; exact archived revision; invisible batch; private foreign-company evidence with visible batch; late source drift; deterministic order; archived project.
-364 scoped PHP lints; offline proposal46/selection33/planner26 passed separately.

Synthetic PostgreSQL17.11 epcc_test, restricted epcc (non-super/non-CREATEDB/non-BYPASSRLS), PHP8.4.26/PHPUnit12.5.35, same isolated cache cluster/private socket5545/no TCP, fsync/synchronous_commit/full_page_writes ON/WAL128/32MB. Serial tests; no competing bootstrap/seeder/concurrency runner. Existing runtime was reused; no source/lockfile or dependency upgrade. Database stopped after closeout. No new two-connection/crash/browser/frontend/CI/pilot certification. No commit/push/PR/customer migration/deployment.

Evidence: `docs/evidence/2026-10-19-reconciliation-evidence/`. Initial15-case run passed. An expanded run exposed the pre-existing numbering issue below; failure XML/log retained. Final fixture deliberately uses an unused synthetic REC-TEST discipline namespace to isolate this diagnostic from that allocator; no production number/type policy or constraint was relaxed.

## Observed separate defect and recommendation — NOT fixed here

**Internal DocumentRegister numbering is RLS-visible MAX-based while document number uniqueness is project-wide.** In the private-evidence fixture, a Sahand registration cannot see an existing private Pars CIVIL/DWG row, chooses PH-14-CIVIL-DWG-0001, and the project-wide unique constraint rejects insertion (SQLSTATE23505). This was an internal service/fixture call, not proof of an authorized UI workflow or independent simultaneous race. Source: DocumentRegister::nextSequence/nextNumber/register. Evidence: private-numbering-collision.log/xml.

Do not “fix” this by exposing private documents, disabling RLS or retrying the same hidden MAX. Recommend a separately scoped transactional allocator keyed by project/discipline/type with reviewed writer authorization, non-disclosing counter access, atomic allocation and migration/backfill from existing numbers; preserve uniqueness/rollback/archive rules. Add dedicated same-project cross-company/private-document, parallel allocation, rollback, replay and archive regressions. Whether number gaps are allowed requires checking existing numbering requirements before schema choice. This is a recommendation, NOT an accepted new schema/role or implemented correction. It remains a known open defect even though the isolated diagnostic regression passes.

## Version meaning and next gate

Preceding454 source hashes are unchanged; two new files make456. The [1151/16776 complete pass](G01-TOPOLOGY-FULL-SUITE-EN.md) is exact preceding454-source proof, NOT proof of this new source. **Complete suite on456 source NOT RUN/PENDING**. Current standalone source-only recovery: `epcc-source-recovery-reconciliation-evidence-2026-10-19.tar.gz`; its pending status is explicit. Recovery verifies source/content hashes and offline46/33/26, not a second app/PG run.

Next: full suite on this exact456-file source. Keep the independently discovered numbering defect visible and scope any correction separately; do not hide it behind the full-suite total. Evidence relationship/validity, authoritative topology, complete coverage and operational admission remain unestablished. G01 OPEN, effective selection UNRESOLVED, OrderGap unchanged.
