> **2026-10-18 current implementation notice:** chronology and UNADMITTED database observations are now persisted; complete application suite1112/16488 is GREEN on450 unchanged source hashes (G01-SCOPE-FULL-SUITE-EN.md). The original lock/migration/test-pending statements below are historical design context. Offline ADR-024-SCOPE-ADMISSION-CONTRACT-EN.md adds38 proposal cases, not authority or operational selection; OrderGap remains unchanged.

# ADR-024 implementation design — effective accepted-MTO selection (G01)

> Continuation: draft `ADR-024-MIGRATION-AND-BACKFILL-SPEC-EN.md` and26 offline planner cases are now available; no migration/production selection fix has been applied.


Date: 2026-10-18. **DRAFT implementation design + executable contract; NOT a production fix, schema approval or new permission policy.**
Related: ADR-024, MTO-REQUIREMENTS §§3.3–3.6, pilot PP-05–08/G01. Companion: `ADR-024-EFFECTIVE-MTO-SELECTION-DESIGN-FA.md`.

## 1. Preserve the settled business decisions

Gross demand comes only from immutable accepted-MTO rows. Revisions of the same document/scope replace, never add. Independent document scopes add only if physically disjoint or backed by an additive decision for the exact accepted revision pair. Parent/descendant scopes overlap. Scope identities and hierarchy versions are pinned. Cross-version mapping does not itself authorize addition. Material identity and compatible unit are explicit; no code/description fuzzy matching, percentage tolerance or automatic model winner. No change to deferred MTO authority, advisory inspection, completed-order semantics or usable stock.

## 2. Why a quick latest-timestamp patch is unsafe

Current `MtoAcceptanceService` locks only the source revision; simultaneous acceptance of two different revisions of one document is not serialized. `accepted_at` is not a unique ordering key. UUID order, revision-code lexical order and MAX(timestamp) across unrelated documents are not business chronology.

`AcceptedMtoBatch`/`AcceptedMtoRow` preserve hierarchy_revision_id as a nullable string and scope UUIDs, but this is not a persisted immutable hierarchy graph. Current Subsystem relates to project/facility/optional area; it has no general versioned parent graph. Reading today's names/relations cannot prove historical disjointness. The current OrderGapService simply sums all accepted rows.

A second danger is choosing the latest row per material: when a material disappears from a new revision, its old row must not survive. Replace the entire selected scope snapshot, not each material's individual last observation. When a whole scope disappears or changes identity, absence is not automatically an approved zero, carry-forward or retirement.

## 3. Proposed persisted acceptance and selection contract

These are proposed structures/invariants, not migrations or approved table names.

1. **Acceptance ordering:** lock one project-local acceptance-clock row throughout the acceptance transaction; allocate a strictly increasing integer ordinal. Serialize all acceptance writers on that lock before taking source locks in a consistent order. Gaps after failure are harmless; duplicates are forbidden. Transaction rollback must leave no accepted snapshot or selection event. Acceptance order is not revision-code order. A late accepted revision follows the existing latest-accepted rule; rejecting semantically older revisions would be a separate acceptance-policy decision.
2. **Pinned scope manifest:** acceptance freezes the authoritative hierarchy revision and each covered stable scope plus its membership/ancestor proof. Validate all rows lie in coverage and header boundaries. Freeze the complete covered scope set independently of which material rows survive. Legacy missing pins cannot be interpreted as project-wide or disjoint.
3. **Append-only selection journal:** each event identifies project, exact document, pinned scope/version, exact accepted batch/revision and acceptance ordinal, plus previous selection event, transaction group, actor/evidence provenance and action. Accepted rows/batches are never updated to SUPERSEDED. Derive a head by absence of a same-key successor. Enforce same-project composite FKs, unique predecessor (no branching), one root per key and one event per acceptance/key. Validate the exact batch actually covers that scope. Scope-version changes, explicit carry-forward or retirement need a reviewed disposition; do not manufacture them from absent rows.
4. **Additivity journal:** unordered pair of exact accepted batch/revision IDs, project, reason and a controlled evidence reference; record admission provenance append-only. Evidence must meet MTO-REQUIREMENTS §3.5 (exact immutable CDE revision, or controlled external record with issuer/number/revision/date/retained file or immutable URI/hash). A hash or arbitrary string from an HTTP request is insufficient. Correction/revocation needs append-only event semantics before operational use. No automatic inheritance to successor revisions.
5. **Read snapshot:** select all heads and scope/evidence inputs in one consistent database read transaction, with a cutoff/selection-version identity for the report. Do not combine pre-acceptance gross with post-acceptance evidence. Cache keys must include selection/evidence versions, not only project ID.

Concurrency acceptance criteria: two simultaneous different-revision acceptances produce a linear unbranched journal in committed order; replay of the same revision is rejected by existing unique revision constraint; stale predecessor insertion loses atomically. Include rollback after snapshot creation and tenant/archive negatives. These require real PostgreSQL tests once persistence is implemented; no such new tests have run yet.

## 4. Fail-closed read contract

Return structured `ready`, selected exact batch/row IDs, findings and per-material/per-unit integer-milli totals. On unresolved overlap, ordering, scope or evidence, totals are **null**, not zero, and the combined report must display an unresolved state. Never silently omit a conflicting document or show a green covered badge. Production UI/API consumers must be adapted before this can be enabled; the existing board return shape cannot express this state safely.

Compare all selected document scope pairs, not only rows sharing a material code: document overlap needs disposition even if current material lists differ. Check parent/descendant membership in pinned topology, not string prefixes. Unknown hierarchy version/mapping blocks automatic aggregation. Evidence for A1+B1 cannot authorize A2+B1; evidence must cover every overlapping selected pair. Disjoint scopes do not require artificial additive approval.

Sum only selected rows at `(material_item_id, exact unit)` grain, using checked integer milli-units; preserve scope/row provenance for drill-down. No sum of M+EA, float conversions or hidden quantity tolerance. Existing net estimates remain attached to historical accepted-row UUIDs; a new gross revision must not silently relink or erase them. Stale net references require explicit visibility/reconciliation in a later consumer slice.

## 5. Executable design prototype (not wired into Laravel)

- `prototype/domain/EffectiveMtoSelection.php`
- `prototype/test/effective-mto-selection.php`
- Run from repo root: `php prototype/test/effective-mto-selection.php`.

**33 independent executable cases passed.** No Composer, Laravel bootstrap, database or seed mutation is used. This deliberately avoids the existing test bootstrap's seeded-blob purge, so the running demo is not damaged.

The pure prototype takes synthetic snapshots with a proposed explicit acceptance_order, a scope coverage manifest, a trusted pinned ancestor registry and already-admitted evidence references. It proposes the current selection; it does NOT persist journal events or prove admission/authority/RLS. These inputs cannot currently be reliably reconstructed from production snapshots. Do not put a thin adapter in front of the current board that fabricates missing metadata.

Bounded prototype limitations:

- Same-document scope set and hierarchy version must stay identical; changed/removed scopes are blocked pending explicit disposition design. This is a conservative prototype restriction, not a new owner-approved rule.
- Every covered scope must contain rows. An empty batch/scope does not clear demand implicitly; retirement/zero-demand semantics are not implemented.
- Cross-version comparisons are blocked even with a supplied exact-pair reference; no mapping/admission engine exists.
- Evidence references are assumed to have passed a trusted future admission adapter. The prototype only checks exact pair, project, nonempty reason/reference, and demonstrates that a hash alone fails. It does not verify actual CDE files, actor permission, signatures or revocations.
- Synthetic IDs are readable test labels, not invented database IDs. The class is a design contract, not an HTTP payload validator.
- No PO, engineering-net, stock or production board behavior changes.

Covered cases include100→110 not210, order independence, equal timestamps, removed material, disjoint leaves, parent overlap, all-pair evidence, revision-specific evidence expiry, cross-version and scope-change blocks, foreign project/evidence, duplicate row/revision/order, missing identity, exact M/EA separation, integer overflow and0.001 precision. Failed selection emits no partial total. Inputs remain unchanged in each test.

## 6. Rollout gates and current outcome

1. **DONE:** source design review and33 executable contract cases; bounded mutation probes recorded separately.
2. **NEXT:** convert the journal/manifest/evidence interfaces into a reviewed migration and backfill specification; explicitly address legacy timestamp ties, missing hierarchy pins, scope-set changes and evidence revocation. No guessed latest UUID, invented acceptance order or automatic external-evidence admission.
3. **PENDING:** migrations, composite project FKs, append-only DB guards/RLS and transactional acceptance adapter. New authority design remains deferred; do not expose a new write surface by inference.
4. **PENDING:** real PostgreSQL concurrency/rollback/tenant/archive tests and report unresolved-state UI/API contract; migration/backfill must not corrupt historical estimates or immutable snapshots.
5. **PENDING:** switch OrderGapService behind the reviewed contract, run related app regressions and then the full suite on the changed application source. Execute the applicable integrated pilot scenarios afterward.

The prior final-source1083/16385 green run remains valid for the unchanged application source. Do not add33 prototype cases to that PHPUnit count or claim G01 is fixed. No new production full run was necessary for this isolated design step. No commit, push or PR.
