> **2026-10-25 bounded correction:** [CDE read discipline](CDE-READ-DISCIPLINE-EN.md) now filters the existing register before eager loading and checks actual document discipline before review/download payload or Storage. The earlier missing-context observation is historical for these three GET/HEAD routes only. Mutations/admin alternatives and permanent evidence composition remain separately gated; preflight/approval semantics unchanged.

# Original-caller evidence preflight and controlled-composition boundary

**2026-10-25 · internal diagnostic implemented and focused/affected tested; not deployed.**

[فارسی](SCOPE-EVIDENCE-PREFLIGHT-FA.md) · [Exact pair-bound opinions](SCOPE-STRUCTURE-REVIEWS-EN.md) · [Structure declarations](SCOPE-STRUCTURE-DECLARATIONS-EN.md) · [Underlying byte checker](G01-CDE-EVIDENCE-INTEGRITY-EN.md)

## Decision/status: prerequisite delivered, not a controlled package

The previous recommendation was a separately immutable composition of narrative, structure and exact evidence. Investigation confirmed that the existing CDE byte checker **does not establish retained-object availability or current evidence validity/revocation**. Persisting its output as a controlled evidence package would silently bypass those gates. Therefore this increment implements the necessary original-caller preflight only; permanent manifest/composition identity and attachment writers remain blocked. This is deliberate staged implementation, not completion of the proposed package writer and not a new engineering policy.

Added: `ScopeEvidencePreflightService`, bilingual stale-input message and 16 application tests. **No migration, domain data writer, attachment, package UUID/digest, HTTP route/UI/export, new grant/role or change to older production code.** Existing proposals, declarations and opinions remain unchanged. SA-01/02 and DA-01..04 remain accepted; no settled choice is re-asked or overridden.

## Strict internal contract

```text
inspect(project, genuine_authenticated_original_User, {
 declaration_id,
 expected_declaration_version, expected_declaration_digest,
 expected_proposal_id, expected_proposal_version, expected_proposal_digest,
 references: {
   topology: { document_id, revision_id, file_id, sha256 },
   coverage: { document_id, revision_id, file_id, sha256 }
 }
})
```

Unknown top-level/nested keys, URI/disk/path/actor/state/verification fields, incomplete roles and malformed UUID/hash are rejected. Project is derived from the server's fresh project, not an evidence input. UUID references normalized; SHA256 is lowercase64 hex. The two claimed purposes are a **bounded diagnostic input shape, not a decision that two files suffice for engineering scope**. One file may be claimed for both purposes without establishing either relationship.

This is a trusted internal facade, not authentication by supplying a User UUID. Future HTTP use must supply the actual session actor and preserve these gates. It is not a new public evidence-discovery endpoint.

## Execution and visibility closure

1. Bind the original actor's own company and exact project through normal TenantContext. Reload project; inspect exact declaration through its authenticated structure/source reader. That reader independently requires original proposer current propose+source-reader or another eligible same-company current review+source-reader path, all observed rows and every declared ancestor identity. Record-only, configurator-only, unrelated project/company and hidden source do not inherit access.
2. Require current narrative stream head plus all expected proposal/declaration IDs, versions and digests. Never substitute “latest” for the supplied pair. This diagnostic is current-pair-only; archive project reading is allowed, not writing.
3. Reload actual User eligibility, participation, company plan, personal project membership and role data. For **both exact CDE tuples before ANY Storage call**, use invoker-RLS joins across file/revision/document, matching project and all parent identities. First read only actual discipline; require independent `AuthorizationPipeline::check(doc.view, actual_document_discipline)`. Only after that check load an internal descriptor, verify discipline did not drift, and retain it temporarily for end comparison. No source-owner impersonation or tenant widening.
4. CDE module entitlement is separate from procurement entitlement/source nomination. QC or a current SA review nomination does not grant doc.view. The actual evidence document's discipline is checked, not assumed equal to PIPING/MTO nomination scope. A foreign private WIP tuple is hidden; a normally visible published participant document remains readable under existing CDE RLS and independent original-user CDE access. No new cross-company grant is created.
5. Invoke unchanged CdeEvidenceIntegrityService on exact supplied revision/file/hash for each role. Its bounded local canonical-content-addressed file adapter streams and checks real size/hash, rejects unsupported/noncanonical storage and rechecks its own metadata. No URL/S3 fetch, file copy, attachment, fallback current revision or same-hash substitute document.
6. After both streams, reload CDE eligibility and every descriptor; reinspect authenticated structure/source/current pair; compare **all** initial/final relevant descriptors, including discipline, document number, locator/disk/extension, SHA/size, revision/state/suitability/issuer/time. This also catches first-file metadata drift during the second stream. Denied access is opaque404; incomplete integrity or descriptor drift returns BLOCKED with evidence=[] and no partial first-file provenance.
7. Only all-success releases the existing byte checker's allowlisted provenance for both selected refs, claimed purpose and findings, plus exact parent identity/version/hash. Never disk/path, binary bytes, raw DB envelope or exception internals. No source quantities are returned; source helpers internally read/hash their own accepted row bytes.

Normal CDE pipeline denial audit is allowed to commit before the final opaque refusal when this method owns the outermost transaction. A caller's enclosing transaction can still roll it back; the pipeline's existing best-effort audit behavior is not upgraded to guaranteed durable auditing. Successful diagnostic query trace has no INSERT/UPDATE/DELETE or stock access. Denial audit is the explicit non-domain write exception.

## Honest result semantics

Success status **BYTES_CHECKED_UNRETAINED**, all_evidence_bytes_verified=true: each selected stream matched its exact expected bytes at its check time, with the stated metadata/visibility rechecks. It is **not a simultaneously atomic read, durable evidence identity, retention lease or validity/authority certificate**. Always:

```text
package_persisted = package_identity_issued = evidence_attached = false
evidence_relationship_verified = evidence_validity_verified = retention_verified = false
package_verified = engineering_approval = ready_to_admit = operationally_authorized = false
admission_state = UNADMITTED; gross = null
```

No package ID/hash is minted even temporarily. BLOCKED has no partial evidence or parent provenance. Missing/hidden/unentitled/out-of-scope CDE dependencies are opaque404; malformed/stale preconditions are validation errors. WIP may pass a diagnostic byte check but retains REVISION_CONTENT_MUTABLE; PUBLISHED/A1 or ARCHIVED is not inferred to be appropriate/current/approved for the claimed purpose. Exact archived revisions are not replaced with their document's newer revision.

Old narrative/structure opinions remain bound only to their original content. Preflight never loads them as evidence review, rewrites their digest, sets their approval flags or changes declaration evidence_attached=false. Existing UI/readiness flags remain unchanged.

## Limitations and identified legacy gap

- Descriptor comparison is best effort, not DB/filesystem atomicity, ABA detection, a guarantee against later byte mutation or a commit hidden by caller isolation. No two-worker concurrency, crash-recovery, retention-lock or performance certification is claimed. Each of two streams can consume up to the existing100MiB ceiling; no new higher limit/remote adapter.
- Mid-stream revocation/drift tests inject DB changes through a stream test callback. They are **not** simultaneous-worker race proofs. They show the final original-user gates and all-descriptor comparison discard a partial result.
- Local storage configuration/filesystem administrator and DB owner remain trusted. SHA agreement is not an issuer signature or historical engineering authority. Frozen structural identities/current source-row coverage are not complete authoritative engineering scope.
- Current stream-head detection retains the existing complete same-company/project metadata-policy assumption, not a hidden-dependency inventory proof. Only the two supplied tuples are checked; this is not all technical evidence or all obligations.
- **Correction recommendation:** existing `DocumentController::authorize()` calls `check(user, capability, project)` without discipline context, including the download path. The new facade explicitly supplies the actual evidence discipline; do not claim legacy CDE reads already enforce it identically. Audit index/review/download behavior and corresponding RLS/membership tests in a separate narrowly scoped correction before claiming global per-human discipline closure. No legacy controller, source visibility matrix or user permission was silently changed here.
- Record-only participant reading, authoritative historical topology/disposition adapters, permanent composition, retention/current validity, positive independent technical review, separate-grant recording/lifecycle/effective selection and DA-03 complete-ledger atomic reservation remain absent/gated. No buying/QA/CDE/MTO-acceptance grant is inferred.

## Proposed next composition contract — NOT implemented/approved as a new grant

A future immutable composition must bind its own ID/version/hash and exact narrative+structure ID/version/hash **and** an immutable manifest's ID/version/hash. Manifest entries must resolve the exact controlled revision/file and real byte identity, with explicitly assessed purpose relationships, retention proof/handle and visible current-validity/revocation state. These fields and mechanisms are technical proposals, not existing records or new authority assignments.

- Create no controlled manifest until original-caller visibility of **all** actual dependencies and retained byte availability/current validity are established. A CDE URL/hash/title, publication flag or an empty revocation lookup is not a substitute. Retention duration/operator authority is not invented from SA-01 or an existing receipt/QA nomination.
- Any evidence addition/change creates a new composition version. An unchanged parent pair can be referenced by a new separate composition identity, without mutating it. Earlier narrative/pair-bound opinions never automatically cover it; a distinct future review must bind the complete composition and freshly inspect its dependencies. No “mark old opinion approved” migration.
- Admission recording needs the accepted distinct-person review and separately current recording grant against the exact independently approved complete composition, plus all source/validity/lifecycle gates. Proposal authority does not waive these. No positive writer should reuse this diagnostic result as a retained snapshot/certificate.
- Prove dependency/validity inventory closure under the eventual metadata policies; a hidden record or bounded list is not evidence of absence. Until such adapters and controls exist, package creation/positive admission/reservation stay closed. Accepted policy is unchanged; any genuinely new privileged retention action needs its own explicit authority design rather than default nominations.

## Verification and delivery

- Final focused: **16 tests/85 assertions PASS,41.73s**, authoritative for final new service. Initial12/71 PASS32.70s overlaps.
- Affected run: 53 PASS/264 assertions and **one test-fixture error**, missing Plan class qualification after adding a test; not a production logic failure. Both unchanged companion classes (22 CDE integrity +16 declarations =38 tests) passed. Fixed only that test's Plan reference; reran all16 new tests successfully, not the unaffected38. These are overlapping scoped runs, **not a clean single54-test pass or full regression**. Original failing log retained.
- Covers dual exact binding, independent CDE RBAC/entitlement/actual discipline, stale loaded actor data, all-dependency preauthorization/no Storage access on refusal, hidden ancestor and private foreign source, normal published foreign visibility, strict shape/injection, every stale precondition/head, hash/size/missing/tampered bytes with no partial disclosure, same-file multi-purpose non-proof, archived revision/project, no domain writes/source mutation/old opinion upgrade, post-stream revocation/first-file locator drift and denial audit behavior.
- Disk fake uses actual local scratch files/streams and SHA checks. Only drift/revocation injection uses a controlled stream callback. Hidden-row fixture policies are removed in finally; final audit confirms no leaked fixture policy or disabled immutable trigger.
- Four PHP Pint/lint, whitespace, ordinary epcc NOSUPERUSER/NOBYPASSRLS/NOCREATEDB, existing FORCE RLS/guards and prior production-byte invariants checked. No HTTP/browser/HTML capture, full regression/CI, customer migration/data/grant, deploy/commit/push. Private socket-only PG stopped afterward.

Evidence: `docs/evidence/2026-10-25-scope-evidence-preflight/`. Source archive is a **dependent increment on the exact structure-review delivery**, not a full runnable backup; runtime/vendor/DB excluded, old delivered archives unchanged.
