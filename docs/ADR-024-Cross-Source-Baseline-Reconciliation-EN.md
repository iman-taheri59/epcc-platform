# ADR-024 — Cross-source engineering claims without a second MTO authority

> 2026-10-18 implementation update: see `ADR-024-EFFECTIVE-MTO-SELECTION-DESIGN-EN.md` for the draft journal/manifest/read contract and33 passing standalone design-prototype cases. G01 is NOT fixed in the application; no schema/role change. Prior inability-to-run statements below are historical; final application baseline is locally green1083/16385.


Status: PROPOSED implementation; prior owner scope/revision decisions recovered (no schema change)
Date: 2026-10-12
Related: ADR-005, ADR-021, ADR-023, P-25

## Problem evidenced in the current code

`MtoAcceptanceService` writes an immutable `accepted_mto_batches`/`accepted_mto_rows` snapshot per accepted MTO revision (`mto_revision_id` unique). Each row carries `material_item_id`, `unit`, `gross_quantity`, optional `canonical_quantity`, scope/subsystem, optional piping class, revision and source-row hash. `EngineeringEstimateService` links its net estimate to an accepted row. `OrderGapService::board()` currently sums **all** accepted rows matching the project, keyed by `material_item_id|unit`, without choosing which document revision/scope is currently in force. If the same scoped document is accepted twice at different revisions, gross demand can be double-counted. Do not wire a second baseline into procurement before this existing precedence issue is resolved and tested.

The parallel `baseline_batches`/`engineering_items` represent revisioned quantity *claims* (model, MTO, manual) with human discrepancy resolution. Copying them as a second accepted MTO authority would leave `EngineeringEstimateService` and `OrderGapService` consuming a different truth from the new reconciliation UI. The owner's ruling in the parallel session was that no source wins automatically when model and MTO disagree.

## Proposed split of responsibilities

1. **Contractual gross demand** remains the accepted-MTO snapshot, with one explicit effective-revision selection per `(project, document identity, scope)`; old snapshots are immutable and queryable. Do not infer the winner from `MAX(accepted_at)` across unrelated documents or scopes. Prior accepted decision: parent and descendant physical scopes overlap; disjoint leaf scopes can be aggregated, and sibling-document overlap needs explicit exact-revision additive evidence. Do not silently treat a whole-project MTO and a subsystem MTO as disjoint.
2. **Independent source claims** (PCF take-off, manual survey) are immutable evidence with source kind, file hash, source document/revision, line/subsystem, extractor/version and capture time. They are not implicitly accepted demand. Do not merge these rows into `accepted_mto_rows` or manufacture accepted-MTO IDs.
3. **Reconciliation** compares claims at an explicitly agreed identity grain; a raw PCF `ITEM-CODE` is not automatically `material_item_id`. Use controlled mapping with unmapped bucket, source-specific original quantity/unit and independently validated conversion to a canonical unit. A `PIPE` length and purchase quantity in EA cannot be summed. Unmatched, ambiguous, overlapping scope or incompatible unit yields a finding, never zero or a guessed match.
4. **Human disposition** links both immutable source-row IDs, records proposed correction, actor, reason and timestamp. Acceptance cannot silently mutate either source. An accepted correction to gross demand must be a new MTO revision through existing acceptance workflow; a model correction must be a new model source snapshot. Downstream reports continue reading the selected accepted MTO snapshot until the new revision is accepted.
5. **Isolation and publication:** each claim and finding has project-scoped RLS and source provenance; released/installed joints cannot be overwritten by a model extract. A PCF parse/admission result is not a model acceptance or an engineering baseline.

## Previous-session decisions recovered (not new questions)

The parallel-session `docs/ADR-005-Engineering-Data-Ingestion.md` §2.2 and accepted 2026-10-05/06 scope decisions (also reconciled into this branch’s `docs/MTO-REQUIREMENTS-EN.md` §§3.3–3.6) already settle the domain principles: latest accepted batch per scope is the current baseline for revisions of the same document; prior immutable snapshots remain historical and are not added; independent sibling documents may sum only with disjoint scope or an append-only evidence-backed additive decision between the *exact* accepted revisions. Parent/descendant scopes overlap. Scope IDs and hierarchy revisions are pinned; cross-version mapping alone does not authorize additivity. Positive `net > gross` is a shortage even below any percentage tolerance. No model source wins automatically over approved gross MTO.

**Caution on copying code:** the parallel `BaselineService::accept()` mutates earlier batch state to `SUPERSEDED` by `(project, source_kind, line/null)` rather than exact MTO document and scope; its `reconcile()` skips differences within a configurable tolerance, and `agreedBaseline()` then chooses the highest-confidence source. Those behaviors cannot be directly copied as the current gross-MTO order-gap rule. In particular, our accepted MTO snapshots prohibit updates and `accepted_mto_batches.state` is constrained to `ACCEPTED`. The accepted *principles* carry forward, not that mutable implementation.

## Remaining implementation design (not a repeat request for the decisions above)

- Express the already-decided same-document current-revision rule as an append-only effective-revision selection/lineage for exact document and pinned scope, without mutating accepted snapshots. Define deterministic acceptance ordering when timestamps tie.
- Confirm whether item matching uses `material_item_id` plus unit and scope, or a separately reviewed mapping for model codes. Decide whether line-level reconciliation can be reported when MTO has only subsystem scope.
- Approve the mismatch threshold/unit policy and human resolver role. No automatic model-vs-MTO winner.

**Recommended next verifiable step:** first fix/verify *effective accepted MTO revision selection* in current `OrderGapService` with multi-revision/scope tests, before any new baseline table. This is a safer and smaller improvement than copying the parallel service. Scope-overlap principles are already owner-approved; implementation still needs exact append-only selection and additive-evidence schema/design. PHP tests cannot run in this sandbox; Laragon verification of previous steps remains open.
