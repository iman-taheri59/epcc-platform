# Demand basis versioning and old-commitment reconciliation

2026-10-23 · **Technical proposal + executable offline diagnostic. No operational writer/schema approved or activated.**
[فارسی](DEMAND-BASIS-RECONCILIATION-FA.md) · [Preceding provenance contract](MATERIAL-DEMAND-PROVENANCE-EN.md)

## 1. Delivered and preserved boundaries

Added `prototype/domain/DemandBasisReconciliation.php` and its standalone synthetic tests. They calculate a reproducible manifest content version and classify old commitment references after a selection change. No Laravel adapter, DB access, migrations, live MTO read, authority, reservation/release or transfer is added. Existing purchasing, incoming report, QC/QA and stock behavior is unchanged.

Follow ADR-018/ADR-024 accepted semantics: revision replacement is not addition; removed material cannot survive via per-material latest-row selection; scope changes/withdrawal need explicit disposition, not fabricated zero. This proposal does not replace the existing accepted-MTO chronology journal or treat its UNRESOLVED observations as admitted effective scope. Separate deferred MTO access and any new demand writer still require approval. Existing full-copy MR→PO and nonreserving PO draft policies are not reopened.

## 2. Content version v1

Synthetic manifest requires **exactly** `project`, `selection_version`, `rows`. Each row requires exactly `id, batch, revision, hierarchy, scope, material, unit, quantity_milli`. Project is inherited from the manifest; a future source adapter must validate every row's same-project composite source tuple before constructing it.

All identities are nonblank strings; row IDs unique; nonempty row list; quantity positive integer milli-units bounded by numeric(18,3). Sort row keys and sort rows by exact ID; preserve all text verbatim, including units. Canonical JSON uses explicit `demand-basis-v1` domain separator, fixed envelope order, unescaped Unicode/slashes and throwing JSON encoding. Prefix SHA-256 with `demand-basis-v1:`. Key/list permutation cannot change a version; any meaningful field or upstream selection-version change does. Unknown fields are rejected rather than silently dropped.

This is a **content digest**, not a signature, admission, proof of authority, current visibility, or authentication. Synthetic labels are not fabricated DB identifiers. A complete operational manifest must also bind admitted evidence/topology lifecycle heads via the authoritative selection version, tool/schema versions and source closure. This prototype does not validate that dependency closure, physical units, source authenticity or external evidence. Do not expose it as an HTTP payload validator or accept a client digest as a trusted version.

## 3. Reconciliation diagnostic

`review(old, current, expectedCurrentVersion, commitments, inventoryComplete)`:

- Validate both manifests; same project; compare current content version with the separately resolved expected head. This is a synthetic freshness check, not a lock or live compare-and-swap implementation.
- Validate immutable historical entry IDs, exact old basis version, exact old source row, project, positive bounded quantities, no duplicate entries and aggregate old row commitments no greater than old gross. A bad history blocks the entire diagnostic rather than revealing partial green results.
- Same accepted row ID with changed batch/revision/hierarchy/scope/material/unit/quantity is an integrity defect, not a normal replacement. Revisions replacing demand require distinct immutable row IDs. Manifest selection-version changes alone are not source corruption.
- Keep each commitment's original version/row/quantity. If source row remains and basis unchanged: CURRENT_REFERENCE_ONLY. If exact row remains but selection version/manifest changed: UNCHANGED_ROW_NEW_BASIS_REVIEW. If absent from current basis: SOURCE_NOT_IN_CURRENT_BASIS. **None** transfers, releases or certifies availability.
- Incomplete/hidden inventory is explicitly incomplete. Even empty input is not evidence of no historical commitments. Output always `status=REVIEW_REQUIRED`, `operationally_authorized=false`, `remaining_milli=null`, `transfers=[]`. No aggregate live capacity, automatic successor matching, zero-demand inference or mutation.

Conservative limitation: new basis with unchanged row still requires review. This is an offline safety restriction, **not** a new policy forbidding future approved exact carry-forward. It intentionally does not propose how much to release, reserve, reduce or move when gross shrinks or a row disappears. MR content versions/approvals and historical policy authority must be resolved by future adapters, not inferred by this class.

## 4. Proposed persistence layout — no migration

Logical names only, additive sidecars; do not retrofit old source snapshots by UPDATE.

| Logical record | Required invariant before activation |
|---|---|
| Demand basis + immutable rows | Project, contract version, content digest, authoritative selection/admission/evidence heads, complete source tuple, actor/visibility provenance; `(project,id)` keys and same-project composite FKs; sealed completeness in one transaction |
| MR content-version sidecar | Exact MR/header/ordered line content and hash, source policy references and predecessor; append-only, no invented version for old MR; existing MR may stay unlinked |
| Historical commitment inventory | Source namespace and immutable event identity, exact old basis/row and MR version, quantities/units, provenance and inventory cutoff; unknown/hidden and legacy unresolved records retained as findings, never zero-filled |
| Reconciliation package + proposed lines | Old/current exact versions, inventory digest/cutoff, proposed disposition, reason/evidence, unresolved findings, immutable line IDs and full dependency closure; diagnosis distinct from permission and execution |
| Reconciliation outcome event | Only after separately approved authority/lifecycle; exact reviewed package, expected heads, request identity and outcome; append-only supersession, no history deletion or automatic relinking |

All future tables require FORCE RLS, original-caller visibility, same-project and original-source checks, archive-write guard, immutable UPDATE/DELETE rejection and RESTRICT history dependencies. A configuration administrator is not automatically a reader/actor. A migration does not grant the still-deferred human MTO authority. Do not introduce a generic JSON blob or SECURITY DEFINER escape as a substitute for source FKs/permissions.

## 5. Proposed transaction/read protocol

Before an execution writer exists, separately approve reservation/release timing and human authority. Technical plan afterward:

1. Resolve active original actor/project and explicit action; never switch tenant to buyer/source custodian. Acquire compatible project/archive serialization before demand/MR/row locks; reconcile with existing acceptance lock order before implementing to avoid deadlocks.
2. Lock/version-check selection and evidence/admission heads, MR content head, inventory cutoff and affected commitment streams in deterministic key order. Reject changed dependencies atomically, including evidence revocation with unchanged gross. A matching content hash alone is insufficient.
3. Re-read authorized source data and complete ledger, validate exact bounds and package dependencies. Hidden/incomplete data cannot authorize release or capacity. No netting/stock offsets or unit conversions without approved semantics.
4. Insert all outcome and linkage events atomically with stable request identity and payload digest. Same request/same payload returns the recorded outcome; same request/different payload is a conflict. Failure rolls back every event and counter. These are proposed controls, not implemented guarantees.
5. Capture immutable report version/cutoff. Use a consistent SQL snapshot or correctly initialized transaction for all dependencies; never set repeatable-read after TenantContext has already queried. Pagination counts are not a frozen audit snapshot.

Required future real PostgreSQL tests: concurrent same-row consumption, selection/evidence change during review, archive/permission revocation race, hidden source, stale MR, duplicate retries/conflicting payloads, crash/late rollback, incomplete package commit, same-project FK/RLS checks and legacy inventory completeness. Offline arithmetic cannot prove any of these.

## 6. Evidence and next gate

`php prototype/test/demand-basis-reconciliation.php`: **35 offline synthetic cases PASS**. Related preceding provenance script rerun: **37 cases PASS**, separate count. Syntax/diff checks and byte-identical prior application manifest recorded under `docs/evidence/2026-10-23-demand-reconciliation/`. No app PHPUnit, database/runtime, production data, browser, migration, CI, deployment, commit or push in this slice. Earlier incoming-report 47/505 remains historical.

Next useful work is the **activation matrix and lifecycle proposal**: which separate authority may read a demand source, propose/approve/apply reconciliation, and at which approved lifecycle point capacity changes. Show explicit proposed choices and inherited decisions; obtain approval before any grant/writer. More synthetic design does not close the operational chain.
