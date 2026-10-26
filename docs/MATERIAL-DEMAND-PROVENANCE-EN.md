# Effective-demand → MR provenance: executable design contract v1

2026-10-23 · **Proposed design with an offline executable diagnostic, not an application feature or approved allocation policy.** [فارسی](MATERIAL-DEMAND-PROVENANCE-FA.md)

## Scope and settled decisions

Continuation of the approved traceability-first sequence: [incoming report](PIPING-MATERIAL-TRACEABILITY-EN.md), [selection design](ADR-024-EFFECTIVE-MTO-SELECTION-DESIGN-EN.md), [admission contract](ADR-024-SCOPE-ADMISSION-CONTRACT-EN.md), [decision index](DECISION-REFERENCE-INDEX-EN.md), ADR-018 and ADR-034. Existing selection semantics are not reopened: same-document revisions replace, not add; independent demand requires disjoint pinned scope or exact revision-specific admitted additive evidence; missing/unresolved is not zero. Existing full-copy MR→PO, nonreserving PO drafts, issued allocation reconciliation, independent purchasing authorities and custody/QC/QA policies stay unchanged.

**Implemented in this slice:** pure PHP proposal validator and synthetic tests, bilingual contract and status updates. **Not implemented:** source adapter, schema, live data report, permission, MTO admission/selection change, demand allocation writer, MR requirement/version storage, GRN or stock. Nothing is connected to Laravel, routes, OrderGapService, MR creation or the incoming report. This does not make the operational MTO→MR link available.

## Source audit and the actual missing bridge

- `app/app/Models/MaterialRequisitionItem.php`: optional material/scope, quantity/unit/text; no exact accepted-MTO row, controlled selection-version or demand commitment link. Existing MR/PO rights must not imply MTO reading.
- `app/app/Services/MtoScopeReadinessService.php::assess()`: observations remain UNRESOLVED with NO_ADMITTED_TOPOLOGY; observations/digests are not admitted topology or authority.
- `prototype/domain/EffectiveMtoSelection.php`: offline selector returns selected batch/row IDs and per-material/unit totals; its trusted pinned/evidence inputs cannot simply be fabricated from operational observations.
- `prototype/domain/MaterialDemandProvenance.php` now specifies the **next** bridge using a trusted synthetic manifest, never live records. A version label/hash is an integrity reference, not admission, source authenticity, a human grant or current visibility proof.

## Proposed input/outputs

`assess(basis, currentVersion, mr, ledger, proposal, ledgerComplete)` consumes synthetic arrays only:

1. Trusted basis manifest: project, opaque immutable selection version, readiness and selected rows. Each row retains exact project, accepted batch, revision, immutable row identity, pinned hierarchy/scope, material ID, exact unit and positive integer milli-quantity. The upstream adapter must prove that these rows actually belong to the selected admitted basis; this class **does not** verify that assertion.
2. A separately current selection-version token, resolved in the same future consistent read. Stale basis blocks all balances. Passing the same caller-supplied string twice proves nothing.
3. A proposed immutable MR content-version identity and exact line IDs/material/scope/unit/quantity. Existing MR storage does not provide this new contract automatically; content hashing/persistence and lifecycle activation remain design work. Missing free-text identities are unexplained **in this proposal**, not forbidden in today's MR workflow.
4. Complete independently reconciled historical commitments plus a proposed explanation for every MR line. History has exact project/basis/row/quantity and immutable entry IDs, with synthetic COMMITTED state. Ledger completeness is a trusted test precondition, not a client toggle granting capacity. Hidden/unknown legacy history must not be silently omitted.
5. Proposed links name exact MR/version/line and selected source row, never commodity-code/description matching.

Consistent result: `status=CONSISTENT_PROPOSAL`, exact row/batch/revision/hierarchy/scope/material/unit provenance, per-row remaining milli-quantity, pinned basis/MR versions and **`operationally_authorized=false` always**. Failure: `status=UNRESOLVED`, explicit finding, `remaining_milli_by_row=null`, empty provenance and no partial green result. Never display these synthetic balances as live purchasing capacity.

Checks: exact project/versions; missing/duplicate identities; selected-row membership; exact units/material/scope; positive integers within numeric(18,3), without float/string rounding; no over-explained MR line; every MR line fully explained; no row overcommitment after history plus proposal; duplicate event across history/proposal rejected. Subtraction-before-addition prevents overflow. No totals across scopes/units; deterministic output ordering. Inputs remain unchanged.

## Policy boundary: examples are not authority

One MR line explained by several source rows is an **illustrative possible provenance shape**, not approval for partial buying, split demand allocation or a new writer. Exact scope equality is a conservative diagnostic restriction; it does not replace accepted ancestor/hierarchy semantics. No canonical-unit conversions, allowances/waste factors, stock offsets, engineering net quantities, automatic substitute materials, transfers, cancellations, releases or replenishment rules are invented.

COMMITTED in the test fixture is not a newly activated MR state or a decision about when demand is reserved. Existing PO drafts remain nonreserving. Draft/cancelled/released/unresolved history is conservatively rejected rather than silently reserved or released. Old-basis commitments block pending exact reconciliation; no automatic relinking after a revision change, quantity shrink or removed material. This may over-block unaffected rows; conservative prototype behavior is not a new business mandate.

## Activation gates and recommended next work

1. Finish the technical adapter/persistence design using already accepted scope/selection lifecycle rules, including versioning, stale commitment reconciliation and provenance preservation. Do not invent missing scope/admission from today's topology.
2. Obtain **separate approval** for the still-deferred MTO read authority and any new demand writer/reservation/release policy. No automatic source permission from PMS/MR/PO/receipt roles. Do not re-ask already settled purchasing or custody authorities.
3. A real writer would need original-caller visibility, explicit scoped authority, atomic version/ledger freshness checks, consistent lock ordering, per-row bounds, immutable reasoned events, idempotent retry identity, archive checks and real PostgreSQL concurrency/rollback/RLS tests. Offline checks do not prevent simultaneous live overcommitment.
4. Only after these gates connect controlled provenance to MR and incoming diagnostics. QA→GRN remains separate controlled work and a bounded pilot; this contract does not authorize it.

## Evidence

Run `php prototype/test/material-demand-provenance.php`: **37 offline synthetic cases PASS**. Related existing selector/planner/admission scripts rerun separately: **33 / 26 / 46 cases PASS**. Do not add these counts to PHPUnit or claim 142 application tests. Source directory: `docs/evidence/2026-10-23-demand-provenance/`.

New tests cover stale selection/MR, unknown history, foreign projects, missing pins/identity, duplicate rows/entries, removed rows, exact material/unit/scope, decimal bounds and one-milli precision, full line explanation, historical-plus-candidate overcommitment, and no operational permission/input mutation. No app DB/data access, grants, migration, live concurrency, browser, full regression, CI, deployment, commit or push. The earlier 47/505 application result remains historical for the incoming-report slice, not a new result for this design.
