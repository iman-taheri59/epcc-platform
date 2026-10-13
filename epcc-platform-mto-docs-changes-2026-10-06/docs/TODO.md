## MTO piping design resumed — updated 2026-10-05 (design only)

- [x] User resumed material-MTO design; ITP remains deferred. Primary source review: ADR-005 §§2.1–2.3 and ADR-008 §11.20; dated accepted decisions in ADR-005 §8 and ADR-008 §11.20.1.
- [x] Accepted: required-vs-ordered gap is separate from required-vs-received/usable-material gap; only valid issued/in-force net PO quantity counts for the order measure; cancelled/draft POs excluded and amendments replace prior quantities.
- [x] Accepted: compare only same material/scope and compatible units. Pipes branch-count and metre gaps are separate; branch↔length conversion needs an explicit applicable supply-length basis; otherwise show separately as non-comparable. ADR-005 §8 / ADR-014 §15.
- [x] Accepted future PO-line scope tags for material + process unit/system/subsystem/piping class; ambiguous rows stay unallocated, with no inference or automatic proration. This is not MTO-row↔MR linkage. ADR-005 §8 / ADR-018 §7.
- [x] Confirmed quantity bases: approved MTO is gross procurement-planning quantity; 3D/isometric/manual estimate is net need; preserve both and do not infer an allowance. If gross MTO is below comparable net need, any positive gap is a shortage; if equal/above, show the quantity difference only. If no accepted MTO exists for a matching scope/category, net need remains provisional, not official order gap. Piping first; per-unit/per-discipline one or more MTOs; material-library matching, revision preservation, piping-class/subsystem/system/unit reporting.
- [x] Order gap uses gross accepted MTO vs valid net PO; PO above gross MTO is shown separately, never as negative shortage, with no automatic PO change. If gross MTO is below comparable net need, any positive gap is a purchase-plan shortage with no tolerance; otherwise show the quantity delta only. ADR-005 §8.
- [x] Automatic Pipes MTO branch estimate uses gross MTO length / explicit project supply length, rounded up; manual branch count is retained and not silently overwritten. If no applicable supply length is configured, no default is assumed and manual count is required for a complete row. ADR-005 §8 / ADR-014 §15.
- [x] Current piping phase limited to component-like materials; paint/coating, insulation and welding consumables deferred. ADR-008 §11.20.2. Their future quantity basis remains open.
- [ ] Define which warehouse/receipt states count as usable for the physical gap (inspection/hold/quarantine/rejection/reservation); user explicitly deferred this on 2026-10-02, so do not adopt the proposed filter.
- [x] Accepted MTO coverage rule: each unit's project-defined in-scope component category needs an ACCEPTED MTO or explicit out-of-scope record; missing category is a coverage gap, not zero, and DRAFT does not close it. ADR-008 §11.20.3.
- [x] User approved independent project-configurable scope authority: owner-company current `project.configure` admin configures nominees; the same nominated engineering authority sets each unit's initial in-scope category list and declares out-of-scope. The admin gains no automatic technical authority; unconfigured/unconfirmed scope is not complete. ADR-008 §11.20.3.
- [x] Every out-of-scope declaration requires a written reason and a project-document citation when document-based; re-entry preserves the old declaration and reopens coverage until an MTO is accepted.
- [x] Per-unit in-scope category sets are versioned; additions open a coverage gap; removals follow out-of-scope disposition; historical scope/coverage is preserved. ADR-008 §11.20.3.
- [x] Gross-MTO vs net-estimate comparison uses the composite identity project-scoped MTO document/sub-MTO + accepted revision/immutable batch + `mto_item_no`; a stable reference to the exact immutable accepted MTO row is required, since the row code alone is not globally unique. Missing/ambiguous references stay unmatched; no description-based guessing or row-level shortage inference. This is component/model↔MTO-row identity only, not MTO↔MR/PO linkage. ADR-005 §8 / ADR-006 §10.8 / ADR-008 §11.20.5 / ADR-018 §9.1.
- [x] Distinct accepted MTO documents/sub-MTOs aggregate only for disjoint scopes or a pair-level additive relationship between exact immutable revisions. That declaration requires a written reason and citation to the exact immutable project-document revision when document-based; if registered in CDE, use a stable exact-revision reference and keep number/title display-only. For outside-CDE sources, use a controlled evidence record with stable ID, issuer/source, exact number/revision/date and retained file or immutable URI plus hash; hash alone is insufficient. If the exact version cannot be retrieved, overlap stays ambiguous; it applies within overlapping scope to rows matching library material and compatible units. Unresolved rows stay ambiguous; relationships are not inherited by new revisions. Declarations are append-only; corrections/withdrawals are superseding records, and historical roll-ups preserve the prior state while current roll-ups use the latest effective record. Revisions of one document supersede under ADR-005 §2.2; do not sum revisions.
- [x] Parent process-unit/system MTO overlaps child system/subsystem scope; do not add parent and child without explicit additive relation. Separate leaf scopes may sum at parent roll-up. Piping class is an independent grouping dimension, not a physical hierarchy node. ADR-005 §8 / ADR-008 §11.20.7 / ADR-018 §9.3.
- [x] Record MTO scope at header only when rows share one physical scope; mixed-scope documents require row-level scope. A row may refine a parent header scope; scope outside its boundary is conflicting/unallocated. Missing/ambiguous rows stay unallocated. Missing/ambiguous required scope or header-boundary conflict blocks acceptance of the entire document/sub-MTO revision: batch remains DRAFT and no partial accepted snapshots are created; fix and revalidate. Scope uses a stable project-hierarchy ID, not free-text identity; the accepted-row snapshot preserves the exact historical scope reference. Accepted design, not implemented. See numbered rules ADR-005 §8 / ADR-008 §11.20.8 / ADR-018 §9.4 / ADR-RECONCILIATION §33.
- [x] User accepted scope-tree versioning rules: pin hierarchy revision in accepted MTO snapshots; never recycle scope IDs; record structural changes as new hierarchy revisions; preserve acceptance-time label/path for display only; cross-version roll-ups require unambiguous pinned relations or explicit immutable mapping/evidence. ADR-005 §8 / ADR-008 §11.20.8 / ADR-018 §9.4 / ADR-RECONCILIATION §33. Design accepted; implementation remains open.
- [x] User accepted cross-version scope-mapping semantics: exact source/target scope IDs and tree revisions, relationship type, reason and immutable evidence when document-based; append-only/superseding; one-to-one equivalence requires evidence; split/merge requires complete non-overlapping proof; mapping does not authorize additive MTO quantities. ADR-005 §8 / DR-10. Design accepted; implementation remains open.
- [x] User accepted scope-mapping lifecycle: historical roll-ups preserve mapping state known at calculation; current roll-ups use latest effective pair record; withdrawal leaves current scope unallocated without rewriting history; no transitive chaining unless stable IDs and pinned trees already prove equivalence. ADR-005 §8 / DR-10. Design accepted, implementation remains open.
- [x] User accepted physical process-unit restructuring/coverage rule: rename with same ID preserves category coverage; split/merge/replacement with new IDs does not inherit prior declarations or MTO coverage; each successor needs its own versioned category scope and accepted MTO or explicit out-of-scope per category. Mapping alone does not close coverage. ADR-005 §8 / ADR-008 §11.20.3 / ADR-018 §9.5 / DR-11. Design accepted; implementation open.
- [x] User accepted immutable roll-up calculation provenance: snapshot exact MTO/row inputs, hierarchy/mapping/additivity versions, units/conversion and rule versions, result and unresolved inputs; historical snapshots immutable and current roll-up recomputed from latest effective state. ADR-005 §8 / ADR-018 §9.6 / DR-12. Design accepted; implementation open.
- [ ] Define/implement storage and validation for pair-level additive relationships between immutable MTO revisions; complete reconciliation design. MTO↔MR exact row linkage intentionally deferred; MR number header-only for now.
- [x] User directs continue business logic/principles and defer MTO permissions/access levels; preserve accepted principles but do not implement roles, grants or control policies yet. ADR-005 §9 / ADR-008 §11.20.4.
- [x] Accepted direct FK to stable ID in a separate immutable accepted-MTO-row snapshot store; DRAFT remains staging and cannot be a target. Preserve `mto_item_no` for display, not as sole key. No MTO↔MR/PO link. ADR-005 §8 / ADR-006 §10.8 / ADR-RECONCILIATION §30.
- [x] Accepted all-or-nothing acceptance per one MTO document/sub-MTO revision: validate all rows, create every immutable accepted-row snapshot, and mark only that batch ACCEPTED in one transaction; any failure rolls back all snapshots and keeps that batch DRAFT. Sibling documents/sub-MTOs stay independent; accepting one does not close another's coverage gap.
- [ ] Design physical target table/column names and transaction implementation; no code/schema selected or changed yet.
- [ ] Implement gross-MTO vs net-need comparison by the full MTO document/sub-MTO + accepted revision/immutable batch + `mto_item_no` identity, separate from generic same-basis reconciliation/tolerance; current takeoff/reconciliation does not preserve/use that composite and still uses item-code/unit plus percent tolerance. No code/tests changed.
- [ ] Continue non-permission MTO design. MTO code/tests unchanged.

## MR creation prerequisite — 2026-09-30

- [x] Confirmed missing create form/route and absent dedicated human author gate in raiseRequisition; ADR-018.
- [x] MR-01: configurable project people/company-role authors, no default, own-company draft creation only.
- [x] MR-02: owner-company current project.configure administrator selects independent MR nominees; no approval grant.
- [x] Implement authorized multi-line draft MR entry and independent policy configuration (ADR-018 §6).
- [x] Final193/774 selected tests, dedicated25/309 included; no failures/errors/skips. Pint/Blade passed. [Usage/evidence](MATERIAL-REQUISITION-ENTRY-2026-09-30.md).
- [ ] Subsequent gaps: MR editing/rejection correction, PO/receipt/return UI, MTO lineage, unit-consistent downstream quantities and existing transition authority audit.

## Latest priority — material-management lifecycle (2026-09-30)

- [x] User explicitly paused ITP work; no answer approving either domain redesign option (ADR-015 §10).
- [ ] Audit actual material workflow from requisition/PO through receipt, traceability, issue/site delivery and returns; reconcile implemented services with reachable forms before claiming completeness.
- [ ] Implement scoped gaps with relevant ADR and regression evidence; no automatic change to existing inspection/warehouse gates.
- [ ] NDT/ITP continuation is now deferred; existing PMS NDT remains outside material inspection.

## NDT source entry delivered — accepted staged design (2026-09-29)

- [x] User accepted separation: PMS requirements → appropriate non-material ITP reference/planning → independent NDT execution/results. ADR-008 §25 / ADR-015 §9.
- [x] First step: bounded structured source fields, missing-data notices, stable UUID, authorized atomic create/update and full history, FA/EN UI; migration235950 with parent/scope/archive RLS (ADR-013 §16).
- [x] Final157/959 selected tests, dedicated21/125 included; corpus25/25. [Usage/evidence](PMS-NDT-REQUIREMENTS-2026-09-29.md).
- [ ] Non-material ITP reference without independent editable source copy.
- [ ] NDT execution/results/defects/repair/retest and preserved historical execution basis; not provided by the entry counter alone.
- [ ] Existing material inspection/warehouse/PMI unchanged; revision and deferred authority reviews remain paused.

## NDT scope correction — 2026-09-29

- [x] User selected NDT/ITP, then explicitly excluded NDT requirements from material inspection. ADR-008 §24 / ADR-015 §8. Neither incoming-only nor blanket fabrication/installation option accepted.
- [ ] Implement PMS-owned NDT requirements outside MaterialInspection/PO/lot/incoming-ITP/warehouse-issue scope; no second editable source.
- [ ] Establish the non-material ITP execution boundary without inferring authority or silently altering signed/historical ITPs.
- [ ] No NDT implementation/tests yet. Existing PMI/inspection data and gates untouched; revision reviews still paused.

## Manual-valve entry delivered — user-selected continuation (2026-09-29)

- [x] `pms_next_entry_area=manual_valves`: explicit keep/replace/clear, separate body/trim/operator/construction fields, library trim provenance, full before/after audit and FA/EN summaries. ADR-008 §23.1.
- [x] Bulk preview/confirm retains valve fields and reference basis; independent side rows and late-failure rollback verified.
- [x] Migration235900: valve-spec SQL archive backstop, null-safe scope and participant restriction; retained archived reads/reopen and raw DML tested (ADR-013 §15).
- [x] Final225/1085 selected tests, including24/158 dedicated; corpus25/25. [Delivery](PMS-MANUAL-VALVES-2026-09-29.md).
- [ ] Remaining PMS-ADR-06: NDT/ITP, SP, branch/per-size rules. Structured rating/citations and DR-03/04 engine remediation remain distinct work; old cross-size operator inference not newly wired.
- [ ] Revision reviews/custody/evidence/adoption authority still deferred; no publication/AFC integration.

## Operational continuation — class special requirements delivered (2026-09-29)

- [x] Existing nullable JSON field now has faithful multiline keep/replace/clear entry and escaped FA/EN reader summary. Legacy shapes retained; existing author/company/version/archive and atomic history apply. ADR-008 §22.1.
- [x] Final112 tests/676 assertions, including22/114 dedicated; engine25/25. [Delivery/evidence](PMS-SPECIAL-REQUIREMENTS-2026-09-29.md). No schema or reference changes.
- [ ] PMS-ADR-06 is only partially closed: NDT/ITP, manual valves, SP and branch/size-rule forms remain; per-entry structured citations not added.
- [ ] Revision reviews and deferred authorities remain paused; no publication/AFC implementation.

## Operational continuation — PMS header units delivered (2026-09-29)

- [x] Accepted measurement contract implemented for min/max design temperature and corrosion allowance: original pair + six-decimal C/mm mirror; keep/set/clear; legacy provenance notice; preferences. ADR-014 §14.1 / ADR-008 §21.1.
- [x] Final190 selected tests/808 assertions, engine25/25. [Usage/deployment boundaries](PMS-MEASUREMENTS-2026-09-29.md).
- [ ] Other numeric consumers must follow the same entered-value contract; no standalone PMS export added. Pressure datum DR-06 remains separate.
- [ ] Continue remaining operational entry gaps as scoped; revision discussions remain paused.

## Operational continuation — bulk entry delivered, reviews paused (2026-09-29)

- [x] User paused revision reviews and selected `pms_bulk_entry`; PR-09 unanswered, no option accepted.
- [x] Explicit catalogue-size bulk create, server preview/conflicts, atomic confirm, row audit and FA/EN UI (ADR-008 §20.1). Final48/379 including bulk16/117; engine25/25. [Delivery/limits](PMS-BULK-ENTRY-2026-09-29.md).
- [ ] PMS document/revision/AFC integration remains unbuilt and review paused; previous decisions retained.
- [ ] Units, structured citations/rating and remaining entry forms remain open. No claim of full-system completion.

## PMS review PR-08 — 2026-09-29

- [x] Accepted `append_dispute_warning_only`, ADR-008 §19.8: append/show later conflict against accepted basis; preserve history; no automatic new-release hold/revocation.
- [ ] Specify dispute registration/resolution authority separately; do not infer from deferred PR-07 or other roles.
- [ ] Future tests distinguish later dispute from originally unknown basis and preserve PR-05/06 plus all existing release gates. No code/tests changed.

## PMS review PR-07 — 2026-09-29

- [x] Asked historical evidence acceptance authority; user selected `defer_evidence_authority`, ADR-008 §19.7.
- [ ] Authority remains explicitly deferred; no default grant and no active historical-evidence acceptance path. Independence proposal not approved by deferral. PR-05/06 unchanged.

## PMS review PR-06 — 2026-09-29

- [x] Accepted `require_linked_evidence`, ADR-008 §19.6: exact historical PMS content/identity, historical authorized status, and explicit line/AFC-decision linkage; incomplete/conflicting evidence remains unresolved.
- [ ] Determine separate evidence-acceptance authority; uploads do not clear the unknown-basis gate automatically.
- [ ] Design/test evidence retention, retrieval, scope checks and immutable resolution history when implementation is authorized. No tests run in this increment.

## PMS review PR-05 — 2026-09-29

- [x] Accepted `preserve_flag_block_release`, ADR-008 §19.5: preserve legacy AFC history, mark unknown PMS basis, block new release until resolution; no automatic rollback of already released lines.
- [ ] Specify evidence sufficiency and authority for historical-basis resolution; no default grant or fabricated backfill.
- [ ] Implement/test populated migration and release gate only after design-only scope is reopened. No runtime tests in this increment.

## PMS review PR-04 — 2026-09-29

- [x] Accepted `current_eligible_only`, ADR-008 §19.4: new AFC only on eligible current published PMS; draft work continues when this gate blocks; no older-version fallback/exception granted.
- [ ] Implement/verify atomic current-basis check against concurrent publication after design-only scope is reopened; preserve historical AFC pins. No new runtime test evidence.

## PMS review PR-03 — 2026-09-29

- [x] Accepted `supersede_on_publication`, ADR-008 §19.3: distinguish working/current-published PMS; new draft does not supersede old publication.
- [ ] Design/implement atomic publication replacement and migration/consumer handling after design-only scope is reopened. Keep non-PMS CDE unchanged unless separately authorized. No new tests run.

## PMS review PR-02 — 2026-09-29

- [x] Asked review-round basis; user explicitly selected `defer_review_round_basis` (ADR-008 §19.2).
- [ ] Review-round/content binding remains unresolved; do not implement snapshot rounds by default. Proceed to next review issue; PR-01 remains accepted.

## PMS individual design review — 2026-09-29

- [x] User chose `review_design`; no implementation and custody still deferred.
- [x] PR-01 accepted: `freeze_shared`, ADR-008 §19.1; content edits require authorized WIP return, comments/history retained, no automatic approval inheritance.
- [ ] Specify review-round/content binding and test SHARED protection across all content paths before implementation; no blanket approval of remaining design details.

## PMS design-only scope — 2026-09-29

- [x] Record explicit `pms_document_custody=defer_custody`; ADR-008 §19. No default custodian/publisher.
- [x] Prepare [detailed revision/AFC design](PMS-REVISION-DESIGN-2026-09-29.md); proposed schema, graph copy, locks, legacy handling and acceptance matrix, not implemented behavior.
- [ ] Custody/creation/association/publication and controlled multi-company copy authority: explicitly deferred; do not activate hierarchy under the earlier general continuation.
- [ ] Implement and verify hierarchy only after scope is reopened and blocking authority decisions are settled. PMS-ADR-01/04 remain open; no new runtime test evidence in this documentation increment.

## PMS hierarchy continuation — 2026-09-29

- [x] User selected project-configurable PMS authoring users/roles, fail-closed before configuration (ADR-008 §17).
- [x] User selected `own_company_only`; nomination retains company-owned content boundaries (ADR-008 §17.1).
- [x] Implement/test current-entry author configuration/history and post-lock checks; no default authors (ADR-008 §18.1).
- [ ] Real PMS document/revision/line-pin integration remains unbuilt; do not infer publication/adoption authority.

## 2026-09-29 — ADR decision critique (separate from implementation gaps)

- [x] Record user's instruction to flag problems in decisions themselves, with evidence/reasons.
- [x] Scoped review of ADR-007/008/013/014/016; proposals recorded in each relevant ADR without silent supersession.
- [x] **DR-01 decision:** user selected `freeze_at_release`; ADR-016 §8 records immutable identity/edition/clause at publication/final approval, with edition specified first.
- [x] **DR-01 CDE/ITP reference-basis increment:** publication snapshot, immutability, source-edition/history UI and legacy handling implemented; 15/81 focused and 128/317 selected regression tests passed. ADR-016 §10–11.
- [ ] **DR-01 remaining:** integrate PMS revisions/structured citations/AFC pins; whole-ITP signed-content versioning and historical pack replay are not completed. See DR01-REFERENCE-SNAPSHOTS-2026-09-29.md.
- [x] **DR-02 principle:** user selected `separate_adoption`; no automatic contractual adoption from citation.
- [ ] **DR-02 authority:** question asked; user explicitly selected `hority:** question asked; user explicitly selected `defer_authority`. Keep unresolved without a default grant; all six primary questions have now been answered. Implementation/migration/tests remain pending.
- [x] **DR-03 decision:** user accepted `distinguish_results`; ADR-007/008 §14.
- [ ] **DR-03 implementation:** map categories to findings, preserve approval semantics, specify migration and verify.
- [x] **DR-04 decision:** user accepted `advisory_unless_evidenced`; ADR-008 §15.
- [ ] **DR-04 implementation:** separate advisory from blocking effects; test consumption with other findings unchanged.
- [x] **DR-05 decision:** user accepted `verified_restore_protocol`; ADR-013 §13 supersedes old operational recipe.
- [ ] **DR-05 implementation:** design actual constraint checks, consistent DB/files snapshot, content checks and isolated failure-injection restore tests.
- [x] **DR-06 decision:** user selected `independent_datum`; ADR-014 §13.
- [ ] **DR-06 implementation:** independent datum/unknown handling, same-basis conversion guards, legacy migration and tests; keep bar/psi menu.
- [ ] **D-01:** correct the erroneous round-trip explanatory note in the units pack through a reference-data change with its tests; arithmetic is recorded, pack not changed.
- [ ] **D-03:** reproduce the old pg_dump exit-status claim before treating it as a tool invariant.

See ADR-DECISION-REVIEW-2026-09-29.md. All six principles now have explicit user approval; DR-02 authority is explicitly deferred. Only the scoped DR-01 CDE/ITP increment is implemented; other items remain open. Original archive/measurement/history principles remain.

## 2026-09-29 — Mandatory ADR coverage / PMS reconciliation

- [x] User's ADR-per-capability rule recorded in root AGENTS, ADR-PROCESS and HANDOFF.
- [x] Relevant primary ADRs read directly within the declared review scope; ADR-008 §12 records partial implementation rather than waiving prior requirements.
- [x] ADR-017 consolidates prior material-review/finding decisions; reference/unit/archive/standards ADRs cross-linked.
- [ ] **PMS-ADR-01:** one PMS document per project, CDE revision ownership, published lock and AFC line pin/impact handling (already accepted).
- [x] **PMS-ADR-02 header scope:** min/max temperature and corrosion allowance now retain entered pair + canonical mirror and preference-aware display (ADR-014 §14.1); future physical fields/exports remain bound by the contract.
- [x] **PMS-ADR-03 scoped bulk entry:** common-specification create across explicit catalogue sizes, preview/conflicts and per-size independence; ADR-008 §20.1. No spreadsheet parser/multi-class/custom-size batch/update batch delivered.
- [ ] **PMS-ADR-04:** PMS standards catalogue identity, adopted project edition and structured citations while preserving unresolved wording.
- [x] **PMS-ADR-05:** notes SQL archive INSERT/UPDATE/DELETE and empty-GUC guards implemented and verified in PmsAccessTest; ADR-013 §14.
- [ ] **PMS-ADR-06:** remaining header special requirements, NDT/ITP, manual-valve/SP, branch/per-size-policy entry coverage.
- [x] **PMS-ADR-07 entry:** project-configurable nominees, own-company only; current entry routes/service and policy audit implemented/tested.
- [ ] **PMS-ADR-07 document integration:** scope PMS document custody/publication without deriving authority from writer nomination.
- [ ] **PMS-ADR-08:** structured governing-standard/rating identity; no comparison of bare class numbers.

Details and acceptance obligations: ADR-RECONCILIATION-2026-09-29.md. **None of these gaps is fixed by this documentation increment.** Prior “delivered” sections describe scoped UI work, not complete ADR compliance.

## 2026-09-29 — PMS entry and explicit sources delivered
- [x] User selected both library references and approved project definitions; explicitly required first PMS entry UI.
- [x] FA/EN class header, numbered note and per-size material-row entry/edit; source wording retained.
- [x] Explicit library size/material selection; approved project-definition link with no silent value replacement.
- [x] Before/after audit, optimistic version checks, company/discipline/project/archive restrictions.
- [x] Populated 55→56 upgrade and selected regression: 310 tests / 1,845 assertions; final focused 18 / 151.
- [x] Browser entry/edit + reference checks; FA/EN desktop/mobile forms/history.
- [ ] Branch-table and per-size class-policy UI; detailed manual valve/trim/operator UI.
- [ ] PMS-wide approval/release/revision workflow (not delivered or implied by entry).
- [ ] Deliberate model/MTO application; full application regression/load/production certification.

See PMS-ENTRY-2026-09-29.md. PMS entry/reference linking was authorized this turn; earlier “PMS not authorized” notes below are historical, not current prohibitions.

## 2026-09-29 — Formal dispositions delivered
- [x] Current independent reviewer only; no own-response disposition.
- [x] Record-only decisions and cited N/A acceptance scoped to response/finding/version.
- [x] Append-only replacement chain, stale-write protection, forced tenant RLS.
- [x] Migration 54→55 preservation; targeted 138 tests / 1,322 assertions.
- [x] FA/EN active desktop/mobile UI and multi-actor browser exercise.
- [ ] Full application regression/load/production deployment certification (not claimed).
- [ ] Source-document authenticity/archive and PMS/model/MTO linkage: not authorized in this increment.

See MATERIAL-FINDING-DISPOSITIONS-2026-09-29.md. Older pending entries about formal dispositions are superseded by this delivery; broader engineering scope is not.

# TODO — وضعیت کامل کارهای انجام‌شده و انجام‌نشده

> **LATEST — 2026-09-29:** `MATERIAL-FINDING-RESPONSES-2026-09-29.md`.
> Cited author explanations/N/A proposals and current-stage reviewer notes are
> now appended to immutable captured findings. N/A excerpt required; role, stage,
> source-company, snapshot identity, retry and archive/closure guards are active.
> No automatic clearing, exception acceptance or approval-policy change.
> Current: 123 PHP tests / 1,218 assertions; 60 core assertions; 21 Python tests;
> 19 packs; 24 page + four active mobile form cases. Schema 54, seven demo notes.
> Formal disposition/exception authority, verified document links, post-approval
> addenda, full reference replay, engineering gates and downstream consumption
> remain pending. Older status statements below are historical.


> **LATEST — 2026-09-29:** See `MATERIAL-SCREENING-SNAPSHOTS-2026-09-29.md`.
> Immutable source/result evidence is now captured atomically on each new
> non-fitting submission. Live and historical findings are distinct in the UI;
> old reviews are explicitly without evidence, never retroactively backfilled.
> Current verification: 108 PHP tests / 1,118 assertions; 60 core assertions,
> 21 Python tests, 19 packs and 24 browser cases passed. Demo schema 53.
> Automatic engineering gates, applicability/N/A handling, full reference/code
> replay archives, explicit PMS selection/consumption and review recovery remain
> pending. Status statements below describe earlier increments.


> **CURRENT — 2026-09-29:** The missing material-family source has been
> reconstructed and regression-tested; the blocker described in the earlier
> engineering-core report is resolved. Stored-record, bilingual read-only technical
> screening is now integrated. See `MATERIAL-SCREENING-INTEGRATION-2026-09-29.md`.
> Current: 484 PHP tests / 10,985 assertions, 60 core assertions, 21 Python tests,
> 19 packs and 24 browser cases passed. Earlier counts below are historical.
> Submission blocking/N/A evidence, persisted screening snapshots and downstream
> PMS/model/MTO consumption remain pending.


> **Current status — 2026-09-29:** See
> [PROJECT-MATERIAL-REVIEW-2026-09-29.md](PROJECT-MATERIAL-REVIEW-2026-09-29.md).
> Shared source/correction/review protections now cover fittings, pipe, flange,
> valve, gasket and bolting. Policies and version numbers are per project/family;
> old fitting authority is NOT inherited. Category-specific source attributes,
> immutable history and family-binding database guards are built. No engineering
> conformance or automatic PMS/model/MTO application is implied.
> Final targeted tests: 485 PHP / 11,012 assertions; 21 Python; all 19 packs valid;
> three-actor PIPE workflow and 24 bilingual desktop/mobile pages passed.
> Demo has 52 migrations. B16.9 remains ½–48 for fittings only.
> **Pending:** explicit reviewed selection/consumption, conflict reconciliation,
> audited rerouting/withdrawal/revocation, unchanged-version resubmission,
> automatic material completeness/applicability checks, large-cap agreements and
> two-size fitting definitions. Older counts/percentages below are historical.

**سند وضعیت پروژهٔ سامانهٔ مدیریت پروژه‌های EPCC**
آخرین به‌روزرسانی: **۱۴۰۵/۰۶/۲۱** (2026-09-12)
وضعیت کلی: **نمونهٔ اولیه + یک برش عمودی کارکرد** · پوشش **~۴۰–۴۵٪** از سیستم مشخص‌شده
تست‌ها: **۵۸۶ قبول / ۲۵۶۴ ادعا** + ۸۳ jsdom · **۱۵۳ جهش تأییدشده**

> این سند مکمل `HANDOFF.md` است، نه جایگزین آن. HANDOFF می‌گوید *چطور* کار می‌کند؛
> این سند می‌گوید *چه چیزی هست و چه چیزی نیست*.

---

## ۰. خلاصهٔ مدیریتی

| | تعداد | توضیح |
|---|---|---|
| حوزهٔ کارکردی مشخص‌شده در SRS | ~۱۰۱ | در ۱۲ گروه ماژول |
| نیازمندی کارکردی (FR) | **۱۲۶** | شمارش یکتا از SRS (تأییدشده) |
| گروه ماژول با کار واقعی | **۵ از ۱۲** | CORE، ENGINEERING، QUALITY، COMMISSIONING، COMMERCIAL — همه جزئی |
| گروه ماژول با صفر صفحه | **۳ از ۱۲** | PROCUREMENT، HSE، SCHEDULE |
| سناریوی پذیرش کامل | **۲ از ۶** | سناریو ۳ و ۵ سرتاسری |
| پوشش کلی (COV-EPCC-001) | **~۴۰–۴۵٪** | برآورد مستند، نه اندازه‌گیری خودکار |

> **دربارهٔ درصد پوشش:** ~۴۰–۴۵٪ یک برآورد مهندسی در `Prototype-Coverage-Map.md`
> است، نه شمارش FR. نگاشت یک‌به‌یک ۱۲۶ نیازمندی به کد هنوز انجام نشده؛ تا آن
> زمان جدول‌های زیر — که به فایل‌های واقعی اشاره می‌کنند — سند معتبرترند.

**دو سناریوی پذیرش کامل:**
- **سناریو ۳** (چرخهٔ مدارک مهندسی): ایجاد ← پیوست ← اشتراک ← بازبینی با نظر مسدودکننده ← رفع ← انتشار ← ارسال ← تأیید دریافت ← **خروجی PDF**
- **سناریو ۵** (تکمیل مکانیکی): بازدید ← ثبت نقص ← تخصیص ← رفع ← تأیید ← بستن ← صدور گواهی، با دروازهٔ نواقص ردهٔ A و استثنای ثبت‌شدهٔ کارفرما

---

## ۱. کارهای انجام‌شده ✅

### ۱.۱ زیرساخت و معماری

| # | کار | جزئیات | محل |
|---|---|---|---|
| ✅ | **انتخاب پشتهٔ فناوری** | Laravel 13.31 + Filament v4 + PHP 8.4 + PostgreSQL 17. مستند با تحلیل رقبا (.NET رد شد: تحریم + هزینهٔ VS 2026) | `docs/ADR-003-*.md` |
| ✅ | **چندمستأجری با RLS** | جداسازی در سطح **پایگاه‌داده** با Row Level Security، نه با `where`. دامنه: شرکت × پروژه | ۱۷ مهاجرت |
| ✅ | **۱۲ قانون پایدار RLS** | هرکدام از رفع یک نقص واقعی به‌دست آمده (`SET LOCAL` نیاز به تراکنش باز، `FORCE RLS`، `NULLIF(GUC,'')` و…) | `docs/Spike-RLS-Results.md` |
| ✅ | **موتور استانداردها** | بدون وابستگی به فریم‌ورک، قابل تست مستقل | `packages/standards-engine/` |
| ✅ | **۳ بستهٔ استاندارد** | `ips-iran` (پیش‌فرض)، `iso`، `awp` — انتخاب در زمان ایجاد پروژه | `standards-packs/*.json` |
| ✅ | **دوزبانگی واقعی FA/EN** | RTL کامل، تاریخ جلالی (`morilog/jalali`)، ارقام فارسی، ZWNJ | سراسر |
| ✅ | **اسکریپت بازیابی ۶۰ ثانیه‌ای** | پس از ریست سندباکس، کل زمان اجرا را بازمی‌سازد | `restore.sh` |

### ۱.۲ هویت و دسترسی

| # | کار | جزئیات |
|---|---|---|
| ✅ | **احراز هویت** | ورود مبتنی بر نشست، کاربر مقید به مستأجر، حساب غیرفعال رد می‌شود |
| ✅ | **خط لولهٔ ۶ دروازه‌ای ARC §5** | AUTH ← ENTITLEMENT ← MEMBERSHIP ← RBAC ← ABAC ← SoD، **اولین شکست برنده است** |
| ✅ | **پیام رد دوزبانه و ممیزی‌شده** | `Denial::message($lang)`، ثبت بر اساس **کنشگر** نه موضوع |
| ✅ | **تفکیک وظایف (SoD)** | `cert.approve` ⊥ `cert.submit` |
| ✅ | **۹ نوع شرکت** | متمرکز در `TenantType` + قید CHECK در پایگاه‌داده |
| ✅ | **نوار کناری به‌عنوان سطح مجوز** | سه حالت `available` / `planned` / `locked` — تمایزشان تجاری است، نه زیبایی‌شناختی |

### ۱.۳ ماژول مدارک (`doc`)

| # | کار | جزئیات | مرجع |
|---|---|---|---|
| ✅ | **دفتر ثبت مدارک / CDE** | شماره‌گذاری قاعده‌محور `{PROJECT}-{DISCIPLINE}-{TYPE}-{SEQ:4}` | FR-DOC-01 |
| ✅ | **حالت‌های ISO 19650** | WIP ← Shared ← Published ← Archived با کدهای تناسب. `WIP→PUBLISHED` ممنوع | FR-DOC-02 |
| ✅ | **کنترل بازنگری** | بایگانی می‌کند، بازنویسی نمی‌کند | FR-ENG-02 |
| ✅ | **پیوست فایل** | آدرس‌دهی محتوامحور (sha256)، فهرست مجاز ۱۱ پسوند، سقف ۱۰۰ مگابایت، دانلود از کنترلر (نه دیسک عمومی) | FR-DOC-01/02 |
| ✅ | **چرخهٔ بازبینی و نظر** | HOLD/CODE1/CODE2 انتشار را مسدود می‌کنند، CODE3 پاسخ می‌خواهد، خودبازبینی ممنوع | FR-ENG-06 |
| ✅ | **نامه‌های ارسال (پیمانکار)** | DRAFT←ISSUED، تغییرناپذیر پس از صدور، تأیید دریافت هر شرکت، سررسید مشتق‌شده (۱۴/۱۰/۷ روز) | FR-ENG-05 |
| ✅ | **نامه‌های ارسال وندور** | جریان **دوم** و جدا: سری `VTR`، صفحهٔ مستقل، زنجیرهٔ `source_item_id` برای رهگیری وندور←EPC←کارفرما | FR-ENG-05b |
| ✅ | **بستهٔ ZIP دوزبانه** | با `MANIFEST.csv` دارای BOM و checksum | FR-ENG-05 |

### ۱.۴ ماژول کیفیت (`qaqc`)

| # | کار | جزئیات | مرجع |
|---|---|---|---|
| ✅ | **فهرست نواقص** | ردهٔ A/B/C، چرخهٔ ۶ حالته `RAISED→ASSIGNED→FIXED→{VERIFIED\|REJECTED}→CLOSED` | FR-COM-04 |
| ✅ | **تأیید مستقل** | هرکسی **جز** شرکت مسئول تأیید می‌کند؛ فقط کارفرما می‌بندد | FR-COM-04 |
| ✅ | **دروازهٔ تکمیل مکانیکی** | MCC با نقص باز ردهٔ A صادر نمی‌شود، **مگر** استثنای تأییدشدهٔ کارفرما با دلیل مکتوب و snapshot | FR-COM-05 |

### ۱.۵ تجاری و خروجی

| # | کار | جزئیات |
|---|---|---|
| ✅ | **صورت‌وضعیت موقت ایرانی** | با تعدیل، حسن انجام کار ۱۰٪، پیش‌پرداخت ۲۰٪ |
| ✅ | **خروجی PDF** | Chromium بدون سر + Vazirmatn. فرم‌های چسبان، ZWNJ، دوجهته، ارقام فارسی، جلالی — همه با رستر کردن تأیید شده. **ریسک IR-R2 بازنشسته شد** |
| ✅ | **دروازه‌های انتشار** | مبتنی بر بستهٔ استاندارد، IR-G1…IR-G4، ناظر غیرقابل‌چشم‌پوشی |

### ۱.۶ تجربهٔ کاربری

| # | کار | جزئیات |
|---|---|---|
| ✅ | **ابزار جدول** | مرتب‌سازی + فیلتر روی هر دفتر ثبت، آگاه به ارقام فارسی/عربی، یه/کاف، ZWNJ |
| ✅ | **قرارداد پوستهٔ صفحه** | `<body>` → `.app` → sidebar → `.wrap` → topbar → صفحه. با تست ساختاری تثبیت شده |
| ✅ | **راه‌اندازی پروژه** | ایجاد پروژه، انتخاب بستهٔ استاندارد، دعوت شرکت‌ها، انتصاب افراد |
| ✅ | **پنل ادمین Filament** | `/admin` با RLS واقعی، فارسی/RTL، فونت محلی (بدون CDN). پنج منبع: «مدارک» (ویرایش فقط عنوان)، «فهرست نواقص»، «نامه‌های ارسال»، «کاربران» و «زیرسیستم‌ها» (بقیه فقط‌خواندنی). دروازهٔ تکمیل مکانیکی در «زیرسیستم‌ها» **مشتق** است نه ذخیره‌شده. همهٔ تاریخ‌ها شمسی از طریق `JalaliDate`. جزئیات و دام‌ها در §5i |
| ✅ | **فایل‌های ترجمه** | `lang/fa/epcc.php` و `lang/en/epcc.php` — اولین فایل‌های ترجمهٔ پروژه |

### ۱.۷ کیفیت مهندسی

| # | کار | جزئیات |
|---|---|---|
| ✅ | **۵۸۶ تست / ۲۵۶۴ ادعا** | ۳۷ کلاس تست Feature + ۸۳ تست jsdom |
| ✅ | **۱۵۳ جهش تأییدشده** | هر قانون با خراب‌کردن عمدی کد و دیدن قرمزشدن تست اعتبارسنجی شده (۱۵ جهش روی دادهٔ بسته‌ها و ۱۵ روی کد PHP در فاز ۰) |
| ✅ | **بایگانی پروژه (ADR-013 → ساخته شد)** | کاربر «آرشیو» را انتخاب کرد و ساخته شد. `ACTIVE ↔ ARCHIVED` برگشت‌پذیر، **بدون حالت DELETED** (حذف یک تشریفات جداست، نه یک گزینه در همان منو). پروژهٔ بایگانی‌شده **کاملاً خواندنی می‌ماند** و **در سقف `max_projects` شمرده نمی‌شود** — نکتهٔ تجاری اصلی: مشتری با طرح یک‌پروژه‌ای که برای شروع پروژهٔ دوم مجبور به حذف اولی باشد، آن را حذف می‌کند، و عملاً شرایط لایسنس به او دستور داده مدارک قراردادی را نابود کند. دو لایه: سرویس **با صدای بلند و به زبان کاربر** رد می‌کند، RLS پشتیبان است. دو یافته: **رد شدن UPDATE/DELETE در RLS بی‌صداست** (صفر ردیف، بدون خطا، `save()` مقدار true برمی‌گرداند)؛ و **`WITH CHECK` برای DELETE اصلاً ارزیابی نمی‌شود** — با سیاست اول، حذف پانچ‌لیست یک پروژهٔ بایگانی‌شده `DELETE 9` داد و همه پاک شدند، پس سیاست دوم `FOR DELETE` روی ۲۲ جدول اضافه شد. ضمناً معلوم شد **`SetUserLocale` فقط روی پنل Filament ثبت شده بود**، پس پیام‌های `__()` بیرون از پنل به زبان پیش‌فرض برمی‌گشتند. **۳۰ تست** |
| ✅ | **HANDOFF زنده** | ۱۰ بخش، شامل دام‌ها و بن‌بست‌ها — به دستور کاربر در هر مرحله به‌روز می‌شود |
| ✅ | **نمودارهای UML ماژول تهیه و متریال** | `docs/uml/` — پنج نمودار PlantUML (مدل دامنه، ماشین‌های حالت، توالی بازرسی تا دروازهٔ MC، برگشت تراکنش و RLS، نمای اجزا) به‌علاوهٔ `uml.html` خودبسنده با SVG درون‌خطی و **بدون هیچ ارجاع خارجی**. متن نمودارها **انگلیسی** است، مطابق قاعدهٔ مستندات |

---

## ۲. اصلاحات و نکات آموخته‌شده 🔧

مواردی که ساخته شدند، **غلط بودند**، و اصلاح شدند. اینجا می‌آیند چون هرکدام یک درس ساختاری دارند.

| # | مشکل | چرا هیچ تستی نگرفت | راه‌حل |
|---|---|---|---|
| 🔧 | **`MC` غلط ترجمه شده بود** | «Mechanical Completion body» به‌جای **مدیریت پیمان**. یک `tenant_type` فقط باید رشته‌اش مطابقت کند؛ قواعد نقش درست بودند پس رفتار سبز بود در حالی که صفحه دروغ می‌گفت | `TenantType` + قید CHECK + `TenantTypeTest` که **واژه‌ها** را پین می‌کند |
| 🔧 | **`display:inline-block` روی `<td>`** | `display:table-cell` را جایگزین می‌کند و ستون‌ها یکی جابه‌جا می‌شوند. **jsdom موتور چیدمان ندارد** پس نمی‌بیند؛ DOM سالم به‌نظر می‌رسد | `td.mono, th.mono{display:table-cell}` + اندازه‌گیری `getBoundingClientRect()` در Chromium واقعی |
| 🔧 | **نشت دید کارفرما** | بند فراگیر RLS («مالک همهٔ نامه‌های صادرشده را می‌بیند») نامه‌های زنجیرهٔ تأمین وندور را افشا می‌کرد | محدود به `kind='CONTRACTOR'`؛ کارفرما فقط اگر مخاطب صریح باشد می‌بیند |
| 🔧 | **مسیر HTTP تست‌نشده بود** | همهٔ ۱۸ تست وندور سرویس را مستقیم صدا می‌زدند؛ کنترلری که `kind` را نادیده می‌گرفت از کل مجموعه رد می‌شد | دو تست سطح-endpoint اضافه شد |
| 🔧 | **پنل Filament بدون `BindTenant`** | RLS **بسته** شکست می‌خورد، پس صفحه خالی بود؛ تست «نشت نمی‌کند» روی یک صفحهٔ خراب سبز بود | تست آینه‌ای «سطرهای خودش را می‌بیند». **هرگز جداسازی را بدون دیده‌شدن ادعا نکنید** |
| 🔧 | **`->font()` از Google Fonts** | هیچ تستی به شبکه نگاه نمی‌کرد — نقض AG-01…AG-06 که در دیتاسنتر مشتری «کند» نمی‌شود بلکه **هنگ** می‌کند | `LocalFontProvider` + تستی که HTML را برای ۵ میزبان CDN می‌گردد |
| 🔧 | **`canAccessPanel` تست‌نشده** | جهش M79: با `return true` کل مجموعه سبز ماند — **حساب غیرفعال پنل ادمین را باز می‌کرد** | تست `former@pars` روی `/admin` |
| 🔧 | **ستون وضعیت خالی** | به `currentRevision.state` بسته شده بود ولی نام ستون `cde_state` است. صفحه ۲۰۰ برمی‌گرداند و **سلول هر سطر خالی** بود | با **نگاه‌کردن به اسکرین‌شات** پیدا شد، نه با تست. حالا هر ستون رابطه‌ای ادعای محتوا دارد (M81) |
| 🔧 | **فرم ادمین فیلدهای کنترل‌شده را باز می‌گذاشت** | اسکافولد `number`/`sequence`/`company_id` را ورودی آزاد کرده بود — تایپ دوبارهٔ شماره یعنی برخورد با ایندکس یکتا، و تغییر شرکت یعنی ناپدیدشدن مدرک از دفتر ثبت سازندهٔ خودش | فرم به دو عنوان دوزبانه محدود شد؛ ایجاد و حذف برداشته شد |
| 🔧 | **جدول `users` اصلاً RLS نداشت** | با اتصال به‌عنوان EPC-PARS، مدارک درست ۵ تا فیلتر می‌شد ولی **هر ۹ کاربر از هر ۸ شرکت** دیده می‌شد — کارکنان کارفرما، بازرسان مشاور و مهندسان دو EPC رقیب. هیچ صفحه‌ای کاربران را لیست نمی‌کرد، پس نشت پنهان مانده بود | مهاجرت `2026_09_12_100000`: RLS روی `users` و `project_memberships`. سه دام: **بازگشت بی‌نهایت سیاست‌ها**، فراموشی `NULLIF` (قانون ۳)، و اینکه **لاگین قبل از وجود tenant اتفاق می‌افتد** ⇒ دریچهٔ `forIdentityLookup()` |
| 🔧 | **همهٔ مسیرهای `/admin` به لاگین ریدایرکت می‌شدند** | فیلامنت کاربر را **قبل از** `BindTenant` resolve می‌کند، پس RLS سطر را پنهان می‌کرد و ادمین واردشده مهمان تلقی می‌شد. **هر ۲۱ تست پنل سبز بود** چون `actingAs()` شیء User را مستقیم تزریق می‌کند و هرگز از provider نمی‌پرسد | با **اسکرین‌شات** پیدا شد (سومین بار). رفع در سطح provider: `TenantAwareUserProvider`. جهش M95 نشان داد اولین تستِ نوشته‌شده هم بی‌اثر بود چون `SESSION_DRIVER=array` است |
| 🔧 | **آواتار کاربران از `ui-avatars.com` خوانده می‌شد** | فیلامنت به‌طور پیش‌فرض نشانی آواتار را از **نام کاربر** می‌سازد و به `ui-avatars.com` می‌فرستد — این نشانی در منوی کاربرِ **هر صفحهٔ ادمین** رندر می‌شد. دو ایراد: در دیتاسنتر بدون اینترنت مرورگر تا پایان timeout منتظر می‌ماند؛ و **نام واقعی کارمند** به یک سرویس ثالث فرستاده می‌شد که برای فهرست پرسنل یک شرکت تابعهٔ NIOC مسئلهٔ محرمانگی است. اسکنر ایستا آن را **ندید** چون نشانی در PHP و هنگام درخواست ساخته می‌شود و در هیچ فایلی روی دیسک نیست | **رفع شد** با `LocalAvatarProvider`: حروف اول نام به‌صورت SVG درون‌خطی (data URI)، رنگ ثابت per-name. با ضبط **net-log مرورگر واقعی** پیدا شد نه با خواندن کد. `FilamentInfoWidget` هم حذف شد (برند و شمارهٔ نسخهٔ فریم‌ورک روی داشبورد مشتری) |
| 🔧 | **صفحهٔ مدارک روی گوشی فارسی کاملاً سفید بود** | `/projects/PH-14/documents?lang=fa` در عرض ۴۳۰ تنها **۱ رنگ یکتا** رندر می‌کرد. همان صفحه با `lang=en` سالم بود — همین نامتقارنی سرنخ اصلی شد. علت: آیتم flex به‌طور پیش‌فرض `min-width:auto` دارد یعنی «هرگز کوچک‌تر از عرض ذاتی محتوایم نشو». جدول مدارک ۱۰۳۲px عرض ذاتی دارد و viewport گوشی ۴۸۵px بود، پس `.wrap` از viewport پهن‌تر می‌شد. در LTR فقط از سمت راست بریده می‌شود و صفحه خوانا می‌ماند؛ در **RTL** چیدمان از لبهٔ راست شروع می‌شود و همین سرریز کل سند را به بیرون صفحه هل می‌داد | **رفع شد** با `min-width:0` روی `.app > .wrap` به‌علاوهٔ `overflow-x:auto` در media موبایل. هر دو لازم‌اند: `min-width:0` تنها، ستون وضعیت و بازنگری را می‌برید که دقیقاً دو فیلدی هستند که آدم دفتر مدارک را برایشان باز می‌کند. اندازه‌گیری بعد از رفع: مدارک ۱→**۲۷۶۰**، نامه‌های ارسال ۲۰۶→**۲۶۳۲**. قفل‌شده با `ShellStructureTest` (M110–M111) |
| 🔧 | **گواهی صادرشده، مدرک خودش را بازنویسی می‌کرد** | خلاصهٔ نواقص (کل/بسته‌شده/باز ردهٔ A) در هر بار رندر **زنده** محاسبه می‌شد. یعنی همان گواهی با همان شماره و همان URL، چند روز بعد اعداد دیگری نشان می‌داد: بسته‌شده ۲→۳، باز ردهٔ A ۲→۱. انحراف همیشه در جهت **آراسته‌کننده** بود: گواهی‌ای که با دو نقص باز ردهٔ A صادر شده بود، خودبه‌خود به گواهی‌ای «تقریباً تمیز» تبدیل می‌شد و دقیقاً همان چیزی را پاک می‌کرد که حسابرس یا داور دنبالش است | با **دانلود دوبارهٔ همان گواهی** پیدا شد. ستون `punch_summary` (jsonb) هنگام صدور نوشته می‌شود، به تقلید از `exception_items`. پیش‌نویس عمداً زنده می‌ماند چون کسی که می‌خواهد تصمیم به صدور بگیرد باید وضعیت **امروز** را ببیند (M107–M109) |
| 🔧 | **اعداد لاتین کنار اعداد فارسی در همان صفحه** | ستون‌های خودمان ۵ و ۴۰٪ چاپ می‌کردند ولی صفحه‌بندی خودِ فیلامنت «نمایش 1 به 5 از 5 نتیجه». عدد لاتین وسط جملهٔ فارسی برای خواننده فوراً دیده می‌شود و برای تستی که فقط ۲۰۰ بودن صفحه را چک می‌کند نامرئی است. **هر پنج منبع** درگیر بودند — از اولین منبع در حال ارسال بود | با **اسکرین‌شات** پیدا شد (چهارمین بار). یک‌جا در سطح مترجم رفع شد: `LocalisedNumberTranslator` که پارامترهای عددی را در locale فارسی تبدیل می‌کند، bind شده در `register()` نه `boot()`. فقط مقادیر **کاملاً عددی** تبدیل می‌شوند تا `PH-14-SS-2201` به `PH-۱۴-SS-۲۲۰۱` تبدیل نشود (M101–M104) |
| 🔧 | **تاریخ میلادی در پنل** | `->dateTime()` فیلامنت `سپتامبر 12, 2026` را در صفحه‌ای کاملاً فارسی چاپ می‌کرد. در دفتر نامه‌ها تاریخ **قراردادی** است: مهلت ۱۴ روزه را تعیین می‌کند. کد ۲۰۰ بود و همهٔ تست‌ها سبز | ریشه ساختاری بود: تبدیل شمسی فقط به‌صورت کلوژر خصوصی `$jdate` در هر Blade کپی شده بود. `App\Support\JalaliDate` استخراج شد و هر ده view و هر سه جدول پنل به آن وصل شدند؛ `DateFormattingTest` **شکل کدبیس** را قفل می‌کند (M87–M89) |
| 🔧 | **`grep` جای ادعا نیست** | تست «حذف وجود ندارد» روی **کامنت توضیحی خودش** شکست خورد و یک حذف با نام دیگر را هم نمی‌گرفت | بازنویسی روی `canDelete()` / `canCreate()` / `getPages()` |
| 🔧 | **تست بی‌اثر** | «پیمانکار نمی‌تواند رفع خودش را تأیید کند» با حذف نگهبان هم سبز بود، چون قانون نوع-مستأجر زودتر خطا می‌داد | ادعا روی **پیام** + استفاده از شرکتی با نوع تأییدکننده |

---

## ۳. کارهای انجام‌نشده ❌

### ۳.۱ اولویت بالا — شکاف‌های ماژول‌های ساخته‌شده

| # | کار | چرا مهم است | تخمین |
|---|---|---|---|
| ⏳ | **تجهیزات، نازل‌ها و دیا‌اینچ** | `ADR-010` نوشته شد. **دیا‌اینچ = قطر کوچکتر**؛ روی ۵ جوش نمونه ۱۹ در برابر ۲۷ — **۴۲٪ بیش‌نمایی** در قراردادی که بر اساس دیا‌اینچ پرداخت می‌کند. **نازل مالک پورت است، پس جدول joints هیچ تغییری لازم ندارد** (با اسپایک تأیید شد). قالب کامپوننت دو لایه: جهانی فقط‌خواندنی + پروژه‌ای (بدون امکان هم‌نامی). بزرگ‌ترین برد فوری: ناهمخوانی ریتینگ فلنج تجهیزات (۳۰۰# مقابل ۱۵۰#) در زمان ورود گرفته می‌شود نه سر بالت‌آپ |
| ⏳ | **مدل‌سازی دستی و قالب کامپوننت‌ها** | `ADR-009` نوشته شد. **کاربر هیچ‌وقت تعداد پورت را وارد نمی‌کند** — پورت‌ها ویژگی کلاس کامپوننت‌اند (دقیقاً روش Plant 3D: catalog template). جریان دستی = **انتخاب نوع + انتخاب پورت باز**؛ سایز و اسکجوئل و end type و خود jointS همه **مشتق می‌شوند**. **PCF یا Plant 3D انتخاب دوگانه نیست**: مدل مفهومی از Plant 3D، فرمت درون‌ریزی **PCF** (متنی، مستقل از فروشنده، CADWorx و AVEVA هم تولید می‌کنند، جوش‌ها را دارد). NPS در **چهار ستون** (رشته، اعشاری، DN، میلی‌متر) — ستون اعشاری برای **دیا اینچ** الزامی است |
| ⏳ | **قواعد پایپینگ کلاس (PMS) و جدول انشعاب** | `ADR-008` نوشته شد. بازه‌های سایز به‌صورت **نیمه‌باز `[from,to)` روی DN** ذخیره می‌شوند — جملهٔ خود کاربر (۴ اینچ در هر دو بازه) برای مهندس روشن ولی برای دیتابیس مرگ‌بار است. `EXCLUDE USING gist` هم‌پوشانی را **غیرقابل‌بیان** می‌کند (روی PG 17.11 تأیید شد)؛ حفره‌ها با `range_agg` گرفته می‌شوند. **اسپایک جدول انشعاب طراحی را عوض کرد**: محدودیت دوبعدی دو قاعدهٔ کاملاً درست PMS را رد کرد؛ جدول انشعاب ذاتاً **قاعدهٔ کلی + استثناهای خاص** است، پس هم‌پوشانی مجاز و حل به روش **خاص‌ترین قاعده برنده** است. دو لایه اعتبارسنجی: ASME می‌گوید چه چیزی **وجود دارد**، PMS می‌گوید چه چیزی **تجویز شده** |
| ✅ | **دادهٔ مرجع مهندسی (سایز، اسکجوئل، اند تایپ، قالب کامپوننت)** | `ADR-007` **پذیرفته و ساخته شد** (فاز ۰). چهار بستهٔ JSON نسخه‌دار در `standards-packs/reference/`: **۳۶ سایز** (۱/۸ تا ۴۸ اینچ)، **۳۵۵ جفت (سایز، اسکجوئل)**، ۱۳ اند تایپ PCF، **۲۰ قالب کامپوننت** با ۴۱ تعریف پورت. دسترسی از `App\Support\Reference\PipeReference` (سینگلتون، حافظه‌ای، **هر جست‌وجو استثنا پرتاب می‌کند**). **بدون جدول دیتابیس و بدون RLS** — حفاظت لازم این است که «هیچ‌کس ننویسد، حتی شرکت مالک»، و فایل روی دیسک این را مطلق می‌دهد. **اختلاف STD/SCH40 و XS/SCH80 در سه لایه ادعا می‌شود.** دو عیب: PHP کلید عددی رشته‌ای را به **عدد صحیح** تبدیل می‌کند (`schedulesFor()` لیست مخلوط برمی‌گرداند)؛ و **باگ fallback در جهت عکس ادعای تست قابل‌دسترس است** — ASME در ۲۲ و ۲۶ اینچ STD دارد ولی اصلاً SCH 40 ندارد، پس fallback «کمک‌کننده» عدد ۹٫۵۳ را برای اسکجوئلی که وجود ندارد برمی‌گرداند؛ این یکی **از تست اول جان سالم به در برد** و فقط با جهش‌سنجی پیدا شد. **۴۹ تست / ۷۷۵ ادعا، ۳۰ جهش گرفته‌شده** |
| ⏳ | **مدل پایپینگ: خط، کامپوننت، پورت، جوینت** | `ADR-006` نوشته شد؛ **هر پنج سؤال پاسخ داده شد و سند بازنگری شد** (§۱۰)؛ شمارهٔ جوینت **به‌ازای هر خط و شرطی** (جوشی/رزوه‌ای لازم، فلنجی نه) با partial unique index؛ درون‌ریزی **حق ندارد شماره بسازد**؛ وضعیت جوینت MODELLED→FITTED با کد فیتر و تاریخ؛ ساپورت رجیستر جدا؛ اسپول اختیاری؛ کامپوننت‌ها از **MTO** با `mto_item_no`. **هر ده سؤال پاسخ داده شد** (§۱۰ و §۱۱): شمارهٔ جوینت **عدد ساده به‌ازای هر خط با شمارندهٔ مشترک**؛ اتصالات فلنجی فعلاً بی‌شماره (تا ماژول bolt-up)؛ شماره‌گذاری ساپورت با همان موتور توکنی خطوط. **آمادهٔ ساخت**؛ فاز ۰ آن ADR-007 است. مدل پورت از `PartPort`/`P3dPartConnection` نرم‌افزار Plant 3D اقتباس شد؛ اما Plant 3D **شمارهٔ جوینت، جوش و NDT ندارد** — آنجا هندسه است، اینجا موجودیت اجرایی. **PCF مسیر درون‌ریزی است** (پورت‌ها صریح، جوش‌ها با تفکیک کارگاه/سایت). قاعدهٔ شماره‌گذاری خط **به‌ازای هر پروژه پیکربندی‌پذیر** است و باید هم **بسازد هم تجزیه کند**. سازگاری پورت‌ها سه حالته (VALID/WARNING/INVALID). **محک زده شد**: ۲ میلیون ردیف با RLS واقعی — خواندن ایندکس‌دار **۰٫۴۵ میلی‌ثانیه** ولی تجمیع کامل **۱۱ برابر کندتر** — پس پیشرفت باید **افزایشی نگهداری شود**، نه زنده محاسبه |
| ⏳ | **درون‌ریزی دادهٔ مهندسی: اکسل، PCF، خروجی Navisworks** | `ADR-005` نوشته شد و **منتظر تصمیم کاربر** است. یافتهٔ تعیین‌کننده: **NWD قابل تجزیه نیست** (اختصاصی، فقط ویندوز، و خروجی‌های Navisworks فقط هندسه‌اند بدون خصوصیات) — پس مسیر، **property export** است نه خود مدل. **PCF بهترین منبع است**: متن ساده، خروجی Plant 3D/CADWorx/AVEVA، شامل هر قطعه با قطر و کد و مختصات. طراحی: یک جدول **baseline خنثی** با `source_kind`، **بسته‌های import نسخه‌دار و تغییرناپذیر** (نه truncate-and-reload)، جریان `upload → preview → commit`، و **PMS به‌عنوان اعتبارسنج توصیه‌ای**. وابسته به ADR-004. پنج فاز — فاز ۱ مستقلاً مفید است |
| ✅ | **ساختار شکست فیزیکی: Facility / Area / Subsystem** | `ADR-004` **پذیرفته و ساخته شد**. جدول `facilities` با تفکیک‌گر `kind`، سطح **اختیاری** `areas` (= CWA در AWP)، و آدرس‌دهی per-kind در `jsonb`. شماره‌گذاری **به‌ازای هر facility** شد پس `fullCode()` افزوده شد و ستون Facility در فهرست زیرسیستم‌ها قابل پنهان‌شدن نیست. کلید خارجی **مرکب** `subsystems_area_within_facility` اجازه نمی‌دهد ناحیه‌ای از facility دیگر انتخاب شود. `PIPELINE` قابل ذخیره است ولی در UI انتخاب‌پذیر نیست (KP بعداً). **۲۵ تست / ۶۹ ادعا** و جهش‌های M118–M123 |
| ⚠️ | **منابع ادمین باقی‌مانده** | «مدارک» و «فهرست نواقص» ساخته شدند. نامه‌ها، زیرسیستم‌ها، شرکت‌ها و کاربران هنوز نه | متوسط |
| ❌ | **گواهی RFSU** | `blocksReadyForStartUp()` نوشته و تست شده ولی **هیچ‌کس صداش نمی‌زند**. دروازهٔ A+B | کوچک |
| ❌ | **گواهی PAC / FAC** | تحویل موقت و قطعی — بقیهٔ زنجیرهٔ FR-COM-05 | متوسط |
| ❌ | **PDF صورت‌وضعیت** | موتور PDF هست، این قالب نیست | کوچک |
| ❌ | **PDF دفتر نواقص** | همان | کوچک |
| ❌ | **ورود انبوه سیستم/زیرسیستم** | الان فقط با سیدر ساخته می‌شود. دقیقاً به همین دلیل منبع ادمین «زیرسیستم‌ها» فرم ساخت ندارد: شکست زیرسیستم‌ها از مهندسی سیستم می‌آید، نه تایپ دستی — کدی که با P&ID نخواند برای کل زنجیرهٔ تحویل نامرئی است (§5i-3) | کوچک |
| ❌ | **عکس نقص روی `DocumentFileStore`** | زیرساخت فایل هست، اتصالش نیست | کوچک |
| ❌ | **گردش‌کار تأیید دریافت وندور** | چون وندورها حساب ندارند، تأیید را پیمانکار ثبت می‌کند. اگر روزی حساب بگیرند باید بازنگری شود | — |

### ۳.۲ ماژول‌های کاملاً ساخته‌نشده

| گروه | حوزه‌ها | وضعیت | نکته |
|---|---|---|---|
| ❌ **PROCUREMENT** | ۱۰ | صفر صفحه | درخواست خرید، RFQ/مناقصه، PO، پیگیری، بازرسی، لجستیک، گمرک، MRR، انبار، مازاد |
| ❌ **HSE** | ۷ | صفر صفحه | مجوز کار، حادثه، مشاهده، توکس‌باکس، JSA، آموزش، محیط‌زیست |
| ❌ **SCHEDULE** | ۶ | صفر صفحه | زمان‌بندی، همگام‌سازی P6/MSP، نگاه‌روبه‌جلو، خط‌مبنا، تحلیل تأخیر، مونت‌کارلو |
| ⚠️ **PROJECT** | ۱۰ | فقط راه‌اندازی | WBS/CBS/OBS/PBS، تقویم، دروازه‌های حاکمیتی، ریسک، مسئله، اقدام، درس‌آموخته، صورت‌جلسه |
| ⚠️ **ENGINEERING** | ۱۱ | ۳ از ۱۱ | **نبود:** دفتر تگ/دارایی، دیتاشیت، مدیریت اینترفیس، TQ/RFI، انحراف/ارفاق، MTO، نمایشگر BIM، VDRL |
| ⚠️ **CONSTRUCTION** | ۹ | فقط نمونهٔ اولیه | AWP/WFP، CWP/IWP، محدودیت‌ها — **عمداً به فاز بین‌الملل موکول شد** (ADR-002) |
| ⚠️ **QUALITY** | ۸ | ۱ از ۸ | **نبود:** سازندهٔ ITP، فرم‌های ITR، گردش NCR/CAPA، جوش و NDT، کالیبراسیون، ممیزی |
| ⚠️ **COMMISSIONING** | ۷ | ۲ از ۷ | **نبود:** شکست سیستم/زیرسیستم، ITR پیش‌راه‌اندازی، کتابچهٔ تحویل، تست عملکرد، تحویل و چون‌ساخت |
| ⚠️ **COMMERCIAL** | ۸ | ۱ از ۸ | **نبود:** دفتر قرارداد، دستور تغییر، ادعا، کنترل هزینه، جریان نقدی، بودجه، ضمانت‌نامه |
| ⚠️ **ANALYTICS** | ۵ | داشبورد ایستا | **نبود:** سازندهٔ گزارش، هشدار، AI، خروجی |
| ⚠️ **ADMIN/SaaS** | ۷ | فقط موتور استحقاق | **نبود:** اندازه‌گیری مصرف، صورتحساب، کنسول ادمین، پذیرش مشتری، سلامت سامانه |

### ۳.۳ قابلیت‌های عرضی ساخته‌نشده

| # | کار | ریسک |
|---|---|---|
| ❌ | **جست‌وجوی تمام‌متن / OCR** | متوسط — انتظار می‌رود ولی وجود ندارد |
| ❌ | **اپ میدانی آفلاین** | **بالاترین ریسک کل محصول (IR-R5)**، کاملاً ساخته‌نشده |
| ❌ | **نمایشگر BIM / CAD** | بالا — نیاز به کتابخانهٔ سنگین |
| ❌ | **یکپارچه‌سازی P6 / ERP / LDAP** | متوسط |
| 🔧 | **`verify:airgap`** | دستور پیش‌پروازی AG-06 نوشته شد: سه سطح BLOCKER/LATENT/BENIGN، خروجی JSON برای گزارش تحویل، و `--strict` برای CI. باندل نصب آفلاین (AG-01/AG-03) هنوز مانده |
| ❌ | **موتور اعلان** | پایین — SDK کاوه‌نگار/SMS.ir شناسایی شده |
| ❌ | **گذرگاه یکپارچه‌سازی** | پایین در این فاز |
| ❌ | **موتور گردش‌کار / BPM** | متوسط — الان قواعد در سرویس‌ها سخت‌کد شده |
| ❌ | **سامانهٔ مؤدیان / شاپرک** | مسدودکنندهٔ تجاری‌سازی ایران |

---

## ۴. مسیر پیشنهادی

به ترتیب ارزش. هیچ‌کدام شروع نشده.

| اولویت | کار | چرا حالا |
|---|---|---|
| ~~۱~~ | ~~پنل ادمین Filament~~ | **انجام شد** — §5i. پنج منبع ساخته شد (مدارک، فهرست نواقص، نامه‌های ارسال، کاربران، زیرسیستم‌ها). «شرکت‌ها» عمداً پوشش داده نشده (جدول tenant تحت RLS نیست) |
| **۱** | **RFSU + PAC + FAC** | زنجیرهٔ گواهی نیمه‌کاره است؛ منطق دروازه‌اش از قبل نوشته و تست شده *(به درخواست کاربر فعلاً معلق)* |
| **۳** | **نصب‌کنندهٔ درون‌سازمانی** | `verify:airgap` (AG-06) **انجام شد**؛ باندل نصب و امضای آفلاین (AG-01/AG-03) باقی است |
| ~~۴~~ | ~~ماژول PROCUREMENT~~ | **انجام شد** — §5k. زنجیرهٔ تهیه ← بازرسی ← ورود به انبار ← خروج از انبار ← ورود به سایت، با ردیابی تا شمارهٔ ذوب و اتصال به دروازهٔ MC. باقی‌مانده: RFQ/مناقصه، و PDF برای GRN/MIV |
| **۵** | **NCR/CAPA + ITP/ITR** | کیفیت فقط ۱ از ۸ حوزه دارد |
| **۶** | **HSE (حداقل مجوز کار + حادثه)** | الزام قانونی در پروژه‌های نفت و گاز |

---

## ۵. تصمیمات بسته (بدون ADR جدید بازگشایی نشود)

| تصمیم | مقدار |
|---|---|
| بازار اول | **ایران.** درون‌سازمانی/air-gap، فارسی پیش‌فرض، جلالی، ریال. SaaS بین‌المللی فاز بعد است، نه موازی |
| پشته | Laravel 13.31 + Filament v4 + PHP 8.4 + PostgreSQL 17 |
| استانداردها | **قابل انتخاب per-project** — `ips-iran` (پیش‌فرض)، `iso`، `awp` |
| AWP | **موکول** به فاز بین‌الملل، در CI به‌عنوان نگهبان عمومیت می‌ماند |
| سند نیازمندی‌ها | **به انگلیسی** (دستور صریح کاربر) — نرم‌افزار دوزبانه است |
| مدل فروش | پلن Full و Special هر شرکت + **پروانهٔ پروژه** به‌عنوان محصول پرچم‌دار |
| قیمت | **۷۰–۱۳۰ میلیون تومان** یک‌باره |
