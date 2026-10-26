> **2026-10-25 status:** [Exact narrative+structure-bound independent non-approving opinions](SCOPE-STRUCTURE-REVIEWS-EN.md) are now implemented internally (COMMENTED/CHANGES_REQUESTED only). Any absence of that bounded writer below is historical. This is not evidence-bound engineering review/admission, and the existing narrative UI/readiness flags are unchanged.

> **2026-10-24 structure increment:** [separately persisted unverified topology/coverage claims](SCOPE-STRUCTURE-DECLARATIONS-EN.md) now exist as an internal service. Earlier absence-of-structured-declaration statements below are historical. This narrative page/reader is unchanged and does not display or review structure. No evidence/positive review/admission is enabled.

> **Authority/persistence status correction —2026-10-24:** SA-01/02 are explicitly accepted ([final choice section](SCOPE-ADMISSION-AUTHORITY-EN.md)); bounded nominations/UI and source-bound narrative persistence now exist ([new guide](SCOPE-PROPOSAL-ENVELOPES-EN.md)). Earlier blanket actor-policy deferral/no-proposal-table statements are superseded in that bounded scope. This technical ADR remains a draft, not blanket acceptance of its full engineering payload/evidence/admission contract. Snapshot MTO acceptance authority is unchanged. No positive admission/review writer exists.

> **Current closeout —2026-10-19:** [reconciliation/evidence full GREEN](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-EN.md) / [فارسی](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-FA.md): **1169 tests/17146 assertions,0 failures/errors/skips,exit0;456 source+2 worker hashes unchanged**. Full pending gate CLOSED; older pending/next-full prose below is superseded history. **Separate DocumentRegister RLS/MAX collision NOT FIXED**, recommendation retained. No app edits this closeout; G01 OPEN, UNADMITTED/UNRESOLVED, gross null, no role/route/migration/OrderGap change. Next recommendation: decision-first numbering allocator review, not rerun of unchanged full gate. Recovery: epcc-source-recovery-reconciliation-evidence-full-2026-10-19.tar.gz; isolated PG stopped after closeout, runtime metadata transient.

# ADR-024 — scope admission / coverage contract, draft v1.1

2026-10-18. **DRAFT CONTRACT, not an approved authority policy, migration or admission endpoint.** Companion: ADR-024-SCOPE-ADMISSION-CONTRACT-FA.md.

Historical original offline-contract slice:450 source hashes/1112 tests/16488 assertions remained unchanged then; its46 standalone cases are not PHPUnit. Current application verification is in the dated notice above. Low-level source/comparison/byte diagnostics now exist, including the read-only composition in G01-RECONCILIATION-EVIDENCE-EN.md, but the full trusted proposal/coverage/admission envelope remains unimplemented. No schema/authority decision is changed; G01 OPEN.

## 1. Separate observation, proposal, authority and selection

1. The existing immutable DATABASE_OBSERVATION/UNADMITTED sidecar records observed DB facts. It does not prove authoritative historical hierarchy, scope completeness or actor authority.
2. A **proposal** supplies an independently declared topology fragment and coverage manifest, explicitly tied to the observation, accepted batch and claimed hierarchy revision. Its structure can be checked without approving it.
3. A future **trusted admission** must verify source export, controlled evidence content/retention, historical topology and coverage authority, actor policy and lifecycle enforcement. The additive/mapping correction/withdrawal semantics are ALREADY ACCEPTED in ADR-018 §9.2; only their precise extension/enforcement for the new topology/coverage admission aggregate remains design work. These adapters/enforcement are NOT implemented here.
4. Admission would still not itself authorize effective-selection/additivity. The selector must honor exact revision pairs, overlap, complete report consistency and all other ADR-024 requirements. No OrderGap integration is made.

A green validator result means only `STRUCTURALLY_REVIEWABLE_PROPOSAL`. Every output has `ready_to_admit=false`, `admission_state=UNADMITTED`, `ready=false`, `gross=null`, `writes_performed=0`.

## 2. Executable proposal shape

Prototype: `prototype/domain/MtoScopeAdmissionProposal.php`; tests: `prototype/test/mto-scope-admission-proposal.php`. Run the latter with PHP from repo root; no Composer/bootstrap/database/filesystem mutation by the validator. Synthetic IDs in tests are labels, not invented production IDs. This is not an HTTP parser, authenticated export adapter, UUID validator, quantity validator or resource-limit enforcement layer.

Top-level keys are exactly:

| Field | Rule |
|---|---|
| schema_version | integer1, not string or boolean |
| source_binding | exact fields below, compared strictly with supplied observed source |
| nodes | nonempty list of id/project_id/kind/parent_id |
| coverage | independently supplied nonempty list of scope_id/row_ids |
| topology_evidence | controlled-record declaration |
| coverage_evidence | controlled-record declaration |

Unknown top-level/binding/node/coverage/evidence keys are rejected rather than accepting smuggled `approved`/admission-state fields.

Binding fields: project_id, batch_id, observation_id, payload_hash, source_rows_hash, hierarchy_revision_id, header_scope_id. All are nonempty strings except header_scope_id, which must explicitly be null or a nonempty matching string. Hashes are64 lowercase hexadecimal characters. Missing hierarchy pins cannot be filled from today's graph. Legacy unpinned acceptance needs a separately reviewed historical reconciliation/disposition contract, not guessed values in this envelope.

The supplied observed source carries the same binding and rows with id, scope_id and hierarchy_revision_id. The validator checks exact row identity/scope/pin membership against that supplied source. **Neither input is authenticated. It does not recompute PostgreSQL JSONB digests or verify that the claimed source export actually came from the database. A consistent fabricated input pair can be structurally reviewable but never admitted.**

### Topology fragment

One explicit PROJECT root, whose id is the bound project id and parent null. Every node is same-project and has a unique id. Parent nodes must exist. Allowed edge kinds are PROJECT→FACILITY, FACILITY→AREA, FACILITY→SUBSYSTEM, AREA→SUBSYSTEM. Cycles and other edges are blocked. Direct facility→subsystem means the optional area is absent; a synthetic area is not invented.

This is the current application's fixed hierarchy model, not a general engineering graph or a complete project inventory. The proposal can include an ancestry-closed fragment for its leaves. Structurally valid ancestry does not prove that supplied parents are historically correct. **A read-only application comparator now reports proposed-versus-frozen observed edges** (see [G01 topology reconciliation](G01-TOPOLOGY-RECONCILIATION-EN.md)). Resolving those differences against authoritative historical evidence and integrating a trusted complete proposal/admission envelope remain unimplemented. The diagnostic never copies today's graph as history or rewrites the observation.

### Coverage

Each covered scope must be an explicit SUBSYSTEM leaf in the fragment. A parent scope is not silently expanded into descendants. Each observed row must be covered exactly once, under exactly its observed scope. Unknown, duplicated, omitted or null-scope rows are blocked. Duplicate covered scopes are blocked. A non-null header must be the covered leaf itself or appear on its explicit parent chain in the same pinned proposal. Missing/unproven ancestry blocks structural review; sibling scopes are not descendants. This corrects the earlier equality-only prototype limitation to match accepted ADR-018 §9.4; it grants no topology authority and changes no production header FK/API.

Coverage is supplied independently, not inferred from material presence. Empty covered scopes are blocked pending an explicit zero/retirement/carry-forward disposition contract. This conservative limitation is not a new business rule. The validator cannot tell whether a real covered scope has been omitted from the declaration when no material row exposes that omission. **Real coverage completeness needs authoritative evidence independent of the row set.** No quantities are summed; disappearance of a material or scope is not treated as approved zero.

### Controlled-record declarations

Both declarations require exactly record_id, issuer, number, revision, date (real YYYY-MM-DD calendar date), retained_uri and file_sha256. Hash-only/free-text-only declarations fail. These are the structural external controlled-record fields motivated by MTO-REQUIREMENTS §3.5; they are not verified evidence. An arbitrary URI/hash/issuer is still a claim, not trusted admission. The prototype neither fetches files nor checks issuer identity, file bytes, retention, immutability or revocation. CDE document-revision/file resolution needs a separate trusted adapter; do not label a URI as an admitted CDE record.

## 3. Mandatory admission trust gates — NOT established end to end

The prototype always lists these outstanding requirements, including for fully reviewable input. Separate read-only diagnostics cover components of source binding, frozen-edge comparison and CDE bytes, not trusted admission satisfaction of these gates:

1. AUTHENTICATED_SOURCE_EXPORT — tenant/project visibility and exact immutable source tuple, observation schema/hash, source-row-set drift, claimed pin and header; reject a client-supplied replacement for DB facts.
2. CONTROLLED_EVIDENCE_CONTENT_AND_RETENTION — resolve exact controlled revisions, retained bytes/hash/issuer and immutable retention; no arbitrary submitted string as authority.
3. OBSERVATION_TO_TOPOLOGY_RECONCILIATION — document differences, never mutate accepted history or use current parents as historical proof.
4. HISTORICAL_TOPOLOGY_AUTHORITY — authoritative version and stable identities, separately from DB observation.
5. COVERAGE_COMPLETENESS_AUTHORITY — complete independently declared scope set, ancestor/boundary proof, and dispositions for missing/empty/changed coverage.
6. APPROVED_ACTOR_POLICY — explicitly defined approved admission authority, project/tenant and delegation boundaries. **No role, grant or engineering approval right is assigned by this draft. Deferred MTO acceptance authority is unchanged.**
7. REVOCATION_AND_CORRECTION_LIFECYCLE — implement the accepted ADR-018 §9.2 additive/mapping semantics: new superseding records, no edit/delete, historical then-effective state, current latest-effective state, withdrawal leaving ambiguity. For topology/coverage admission, specify exact event targets, dependencies and transactional effects without assuming a new human authority policy. Do not equate a once-valid signature with perpetually valid evidence.
8. TRANSACTIONAL_REVALIDATION_AND_REPLAY_GUARDS — future trusted persistence must repeat checks under consistent source locks/read snapshot, bind policy/evidence versions and exact source digests, reject stale/replayed/branched decisions, preserve tenant/archive guards and rollback atomically. A previous offline result must never be a write capability.

Proposed future event semantics, subject to reviewed policy/schema: submission is not admission; only a separately authorized decision can admit; correction/revocation is append-only and never edits observations/accepted rows. Revocation must invalidate dependent effective-read readiness or yield unresolved totals, not silently retain a green selected total. No events/tables/permissions are created by this draft.

## 4. Verification and remaining decisions

**46 offline cases PASS**: complete but unadmitted candidate, order independence, strict shape/version, every source-binding field, missing pin, null/exact header, hash format, foreign/duplicate/missing/cyclic/wrong-kind topology, optional area, independent coverage, no parent expansion, omitted/duplicate/foreign/misassigned rows, missing/duplicate row scope, row pin mismatch, same/descendant header ancestry, sibling/missing-header blocks, evidence completeness and actual dates (including embedded null input), malformed nested data, empty source/zero block. All assert no input mutation, no totals, no writes and no admission.

Selection33 and backfill-planner26 standalone cases also rerun PASS. All450 full-tested source hashes are unchanged. Evidence: docs/evidence/2026-10-18-admission-contract/. No PHP application/PG/full suite rerun was needed for this offline-only slice; the previous full pass stays valid for unchanged application source, not proof of future admission integration.

Before any admission writer: resolve the specific human authority under the existing permission deferral, trusted topology/coverage record adapters and precise admission-event dependencies/dispositions. Reuse accepted evidence, mapping, withdrawal and historical-roll-up rules rather than reopening them. Versioning/evidence-resolution/provenance/read-only checks and tests may proceed without a new role or admission gate. Then review a persistence/API contract and test real RLS/archive/stale-source/replay/concurrency behavior. Until then keep the fail-closed application and existing board unchanged. No customer migration, remote operation, commit, push or PR.

## 2026-10-18 decision reconciliation

See [decision reference index](DECISION-REFERENCE-INDEX-EN.md). The preceding offline draft overstated the undecided scope: existing ADR-018 §§9.2–9.6 already settle evidence, mapping/withdrawal, header ancestry, split/merge coverage and historical snapshots. This correction does not approve a new authority policy. Added8 structural ancestry cases (46 total); all450 full-tested application hashes unchanged. Production header storage remains subsystem-only; synthetic parent/project proposal tests are not an application feature. Previous38-case evidence is historical.
