# HANDOFF — EPCC Platform

## CURRENT — MTO piping design; access controls deferred; no MTO implementation (2026-10-06)

User resumed material-MTO work while ITP remains deferred. Relevant actual ADRs were searched and directly reviewed: ADR-005 §§2.1–2.3, ADR-006 §10.6, ADR-008 §11.20 and ADR-018 §9. ADR-005 §8, ADR-006 §10.8, ADR-008 §§11.20.1–11.20.5, ADR-018 §9.1 and ADR-005 §9 record accepted design and the access-control deferral; no MTO code/schema/tests changed. Continue business logic only; MTO roles, grants, nomination administration and acceptance permissions remain deferred. This does not reopen delivered MR-01/MR-02.

Accepted: approved project MTO quantity is gross procurement-planning quantity and may exceed net need; 3D/manual-isometric estimate is net need. Preserve both bases. If gross MTO is below comparable net need, any positive difference is a purchase-plan shortage; no tolerance suppresses it. If equal/above, show the quantity difference only, without inferred cause/percentage. Order gap compares gross accepted MTO with valid net PO; PO above MTO is shown separately, never as negative shortage, and does not automatically change the PO. If no accepted MTO exists for the matching unit/category/scope, net engineering estimate is provisional, not an official order-gap baseline; apply this per scope/category. ADR-005 §§2.1–2.2/§8. MTO material specs use the material library; unmatched rows may remain DRAFT but cannot be accepted/issued until matched. Accepted MTO/library bases remain versioned; model refresh compares and preserves manual work. Gross-MTO vs net-model/isometric comparison uses the composite identity project-scoped MTO document/sub-MTO + accepted revision/immutable batch + `mto_item_no`; a stable reference to the exact immutable accepted MTO row is required because the row code alone is not globally unique. User approved a direct FK to a stable ID in a separate immutable accepted-MTO-row snapshot store; DRAFT remains in staging and cannot be a target. Preserve `mto_item_no` for display, not as sole key. Acceptance-to-snapshot is all-or-nothing per one MTO document/sub-MTO revision: validate all rows, create every immutable accepted-row snapshot and mark only that batch ACCEPTED in one transaction; any failure rolls back snapshots and leaves that batch DRAFT. Sibling documents/sub-MTOs stay independent; accepting one does not close another's coverage gap. Different accepted documents aggregate only where consumption scopes are disjoint or an explicit pair-level additive relationship is recorded between the exact immutable revisions. That declaration requires a written reason and, when document-based, a citation to the exact immutable project-document revision (not only its number/title). If registered in project CDE, store a stable reference to that exact CDE revision; number/title are display-only. For outside-CDE sources, use a controlled evidence record with stable ID, issuer/source, exact number/revision/date and retained file or immutable URI plus hash; hash alone is insufficient. If exact version cannot be retrieved, it cannot support additivity and overlap stays ambiguous. It applies only to matching library items and compatible units in the overlapping scope; unmatched rows stay ambiguous. New revisions do not inherit the relation; revisions of one document follow ADR-005 §2.2 and are not added together. A parent unit/system scope overlaps descendant system/subsystem MTOs and cannot be summed with them absent explicit additivity; disjoint leaf scopes may sum at the parent. Piping class is an independent grouping dimension, not a physical hierarchy level (ADR-005 §8 / ADR-008 §11.20.7). MTO scope is stored at the header only when all rows share it; mixed-scope documents require row-level scope. A row-level descendant may refine a parent header scope; a row outside the header boundary is conflicting/unallocated. Missing/ambiguous required scope or a boundary conflict blocks acceptance of the whole document/sub-MTO revision: keep the same batch DRAFT, create no partial accepted-row snapshots, then fix and revalidate. Scope identity uses a stable ID in the authoritative project hierarchy, not free text; the immutable accepted-row snapshot preserves the exact scope reference used at acceptance so later hierarchy edits cannot rewrite historical assignment. This entire numbered scope policy is accepted design only, not implemented (ADR-005 §8 / ADR-008 §11.20.8 / ADR-018 §9.4 / ADR-RECONCILIATION §33 / DR-10). User accepted the three scope-tree mechanics: pin the hierarchy revision in accepted MTO snapshots; never recycle IDs, preserve as-of labels/paths for display and record structural changes as new hierarchy revisions; allow cross-version roll-up only where pinned stable-ID relationships establish the common scope or an explicit immutable mapping/evidence record exists. Design only, not implemented. The user also accepted mapping semantics: record exact source/target IDs and tree revisions, relationship type, rationale and exact evidence when document-based; keep records append-only with superseding corrections; one-to-one equivalence needs evidence; split/merge needs complete non-overlapping proof; mappings do not authorize adding MTO values. Design only, not implemented (ADR-005 §8 / ADR-008 §11.20.8 / ADR-018 §9.2 / ADR-RECONCILIATION §33 / DR-10 / TODO). User accepted mapping lifecycle: historical roll-ups preserve mapping state at calculation, current roll-ups use the latest effective pair record, withdrawal leaves current scope unallocated without rewriting history, and transitive chains are not inferred unless stable IDs and pinned trees already prove equivalence. Design accepted, not implemented (ADR-005 §8 / DR-10 / TODO). No code/schema/tests changed. User accepted process-unit split/merge coverage rules: same-ID rename preserves coverage; new successor IDs do not inherit old category declarations or MTO coverage; each successor needs its own versioned category set and per-category MTO or explicit out-of-scope record; mapping alone does not close coverage (ADR-005 §8 / ADR-008 §11.20.3 / ADR-018 §9.5 / DR-11). Design only, not implemented. Declarations are append-only; correction/withdrawal is a superseding record. Historical roll-ups preserve the relation state at calculation time; current roll-ups use the latest effective record, with withdrawn overlap ambiguous/unallocated. Physical target table/column names and implementation remain to be designed. User accepted immutable roll-up calculation provenance: historical snapshots preserve exact MTO/row, hierarchy, mapping/additivity, material/unit, rule-version and unallocated-input state; current roll-up is a new calculation from latest effective inputs (ADR-005 §8 / ADR-018 §9.6 / DR-12 / TODO). Design only; implementation remains open. Missing identity stays unmatched, without fuzzy matching or row-level shortage inference. This component/model↔MTO identity does not create MTO-row↔MR/PO linkage. Current baseline takeoff/reconciliation does not preserve/use this identity; `takeOffFromModel()` groups without `mto_item_no`, `indexItems()` matches `item_code|unit`, and `reconcile()` applies percentage tolerance/`difference_pct`. This conflicts with the accepted gross/net basis and any-positive-shortage rule; code/schema/tests remain unchanged. Piping first; one line has one class; shortages can be grouped at class, subsystem, system, unit. Pipes MTO stores gross procurement metres and estimated/manual supply-branch count separately from net engineering need; automatic branch count uses gross metres divided by explicit project supply length, rounded up. If no project supply length is defined, no default is assumed and manual branch count is required for a complete row. Manual values are not silently overwritten; no model-segment count. Current phase covers component-like piping materials only; paint/coating, insulation and welding consumables are deferred. MR number is header-only; exact MTO-row↔MR linkage deferred.

Two separate indicators: order gap (accepted required quantity vs net valid issued/in-force PO; draft/cancelled excluded; amendments replace prior quantity) and receipt/usable-material gap. These are not the same measure. The definition of which received/warehouse states count as usable is explicitly deferred; the proposed exclusion of pending inspection/quarantine/rejected/reserved stock is not accepted. Compare only same material/scope and compatible units; Pipes branch and metre deficits stay separate, with conversion only where the applicable supply length is explicit. Future PO lines must be attributable to material + unit/system/subsystem/class for roll-ups; ambiguous lines stay unallocated, without auto-proration. This does not create an MTO-row↔MR link. Coverage rule is accepted: each unit's project-defined in-scope component category needs an ACCEPTED MTO or an explicit out-of-scope record; missing is a gap, not zero; DRAFT does not close coverage. Independent project-configurable scope authority is accepted: owner-company current `project.configure` admin configures nominees; the same nominated engineering authority confirms each unit's in-scope categories and may declare out-of-scope. The admin gains no automatic technical authority; without a nominee/confirmed set, coverage is unconfirmed, not zero or complete. Out-of-scope declarations require a written reason and project-document citation when document-based. Re-entry preserves prior history and reopens the gap until an MTO is accepted. Detailed workflow and reconciliation remain open. PO schema/UI and current order calculation remain unimplemented; do not claim delivery.

---

## CURRENT — MR first entry slice delivered and verified (2026-09-30)

MR-01 and MR-02 confirmed: explicit project people/company-role authors; owner-company current project.configure administrator configures. No default grants, own-company DRAFT creation only. ADR-018 §5–6. Independent author policy/service/form, source string and policy attribution, bounded1–20 rows, decimal18,3/no rounding, scope/entitlement/discipline/archive protection, append-only policy history, bilingual old-input-safe UI. Existing trusted ProcurementService remains an internal primitive; only new human facade is routed.

Found and fixed project-wide numbering over company-filtered MAX: projects.material_requisition_sequence monotone counter with populated/archived migration initialization, shared primitive row lock. Changed source files5/new8; reference files32 unchanged. Migration2026_09_30_042713. Final selected regression193 tests/774 assertions; zero failures/errors/skips; dedicated25/309 included. Pint11 files and Blade compiled cleanly. ITP tests are regression only, not resumed ITP development. Evidence /home/user/organization-report/mr-final.{log,xml}. Primary report docs/MATERIAL-REQUISITION-ENTRY-2026-09-30.md.

Runtime restored again after user selection reset. Created fresh synthetic demo epcc and separate epcc_test databases (not restoration); demo migrated/seeded with no author nominations. App8000 running, loginHTTP200. ITP implementation paused, NDT outside material inspection. Remaining lifecycle: PO/receipt/return UI, rejected MR correction/editing, MTO links, unit-aware downstream calculations and existing transition human authority audit. No full-product/browser/concurrency/restore claims.

---

## Historical checkpoint — MR author accepted, prior to MR-02 (2026-09-30)

Material lifecycle audit confirmed no MR create endpoint/form despite ProcurementService.raiseRequisition existing. Service only checks buyer company type, not a dedicated human author policy. Recorded ADR-018 before implementation, read ADR-005 baseline invariants, ADR-013 archive, ADR-014 units and ADR-017 separation of definition approval from purchasing. User selected `material_requisition_authority=project_configurable`: no default, project people/company-role nominees, own-company draft creation, no approval grant. **MR-02 who configures nominations remains pending**; do not silently grant it. No new production code/schema/tests yet. Runtime dependencies restored from lock; PostgreSQL/app not started this turn. ITP remains paused; NDT excluded from material inspection.

First intended slice after authority settled: multi-line draft MR entry on existing Procurement page, reuse service/state/SoD; bound decimal(18,3) quantities without silent rounding, same-project subsystems, atomic creation, FA/EN validation. Avoid pretending this closes PO/receipt/MTO integration. Current scalar quantity+unit storage/numbering behavior require careful scope; no default conversion or baseline link.

---

## CURRENT — ITP paused; complete material-management lifecycle (2026-09-30)

Latest user: **«ITP رو بذار برای بعد. فعلاً می‌خوام چرخه مدیریت متریال رو کامل کنیم»**. This supersedes immediate NDT/ITP continuation. Neither shared-ITP-domain nor separate-NDT-plan option accepted. ADR-015 §10 records pause; no ITP schema/activation/gate changes made. PMS NDT stays outside material inspection; revisions/authorities remain deferred.

Initial source inventory only: ProcurementService has raise/submit/technical-commercial approval/reject requisition, PO create/issue/forecast. WarehouseService has inspect/applyInspection/receive/issue/deliverToSite/returnToStore/reverse. MaterialsController exposes warehouse, issue/deliver/reverse, procurement, requisition advance and forecast; create/receive/return UI coverage requires inspection. Old TODO contains stale zero-page counts and later claim chain delivered; do not treat either as verified completeness. Next inspect actual routes/views, governing procurement/material ADR decisions, models and tests, then close service-to-UI/lifecycle gaps. No new code/tests yet.

---

## CURRENT — structured NDT source entry delivered; ITP/execution next (2026-09-29)

User accepted the assistant's recommendation with **«خوبه ادامه بده»**: separate PMS requirement definition, ITP planning/reference and independent NDT execution/results. Immediate proposed/implemented step is structured PMS requirement entry, outside material inspection. ADR-008 §25 written before code, §25.1 results; ADR-015 §9 acceptance; ADR-013 §16 archive. Report `docs/PMS-NDT-REQUIREMENTS-2026-09-29.md`. **Revision review/authorities still deferred.**

New PipingClassNdtRequirement + migration235950: stable UUID child rows, applicability/method/source_ref required, extent/sampling_basis/acceptance/reference/conditions nullable source text. Missing first four optional technical details flagged, no inference/default percentages or criterion generation. Unknown method text accepted; UTF-8/control/length constraints. Narrow TrimStrings exceptions preserve source edges. App max100 rows/class, edits at cap permitted; no delete.

PmsEntryService.saveNdt and POST /pms/{classId}/ndt use existing policy/company/archive/project-lock and parent class optimistic version. Full class audit snapshots now include ordered ndt_requirements; header/note writes preserve them, parent version makes old bulk preview stale. RLS validates parent/project/company, project participation and null-safe scope; restrictive archive write/delete guards retain reads. No raw-SQL human RBAC claim. Schema/records have NO ITP, material/lot, execution/result or approval relationship.

FA/EN editor and summaries, target-scoped old input, field-specific validation, missing-data notices and escaped source. Reference text is not automatic structured citation/adoption. Stable UUID/history are NOT document revisions or execution-basis pins. Future appropriate non-material ITP reference must not become separately editable requirement copy; execution/report/repair/retest lifecycle remains unbuilt. Existing incoming inspection/PMI/ITP/warehouse gates untouched.

Final157 tests/959 assertions,0 failures/errors/skips: dedicated21/125 + related8 PMS classes136/834. Header/entry/bulk/access/migration/special/measurement/valve regressions included; independent89-test ManualValve engine not rerun. Corpus25/25, explicit-file Pint and Blade cache, live login HTTP200. No visual/full-product/restore QA or new NDT two-connection race claim.32 reference files unchanged. Evidence `/home/user/organization-report/ndt-{final,target}.{log,xml}`, conformance/pint/before/delivery-check. Initial full-run bash transport returned shell_error while PHP continued; did NOT start a concurrent suite. Observed same PID through completion, final JUnit verified157/959; watcher exited0.

Runtime restored PHP8.4/PG17/vendor from lock, new local epcc demo seeded (NOT restored), no default authors. Tests reset only epcc_test NOSUPERUSER/NOBYPASSRLS. PG `project-database-a7e75790`, app8000 `epcc-3ab3e713`. Migration applied to demo.

Next according to accepted sequence: non-material ITP reference/planning and independent NDT execution/results, with explicit historical-basis handling; do not attach to material inspection or claim these already exist. Other PMS-ADR-06 SP/branch/rules remain. Do not reopen paused custody/revision/adoption questions by default.

---

## CURRENT — NDT explicitly outside material inspection; implementation pending (2026-09-29)

User continued, selected `pms_next_after_manual_valves=ndt_itp`. On scope question, selected NEITHER incoming-only nor fabrication/installation extension; custom answer: **«الزامات NDT را از حوزه بازرسی متریال خارج کن»**. Follow ADR-008 §24 / ADR-015 §8. PMS owns NDT requirements; do NOT put this capability into MaterialInspection, PO/lot/incoming ITP or warehouse-issue gates. Shared itp_stages fields do not justify reusing that workflow.

Read actual ADR-008 §11.8/§12, ADR-015 §§1–2.8/7 and reconciliation. Recorded accepted scope correction only: no NDT code/schema/data/tests implemented. Exact non-material execution consumer/lifecycle needs scoped design, not an inferred blanket reopening of fabrication/installation. Existing inspection/PMI/history/gates were NOT removed; correction is not authority to erase historical records or classify every method by name. Revision reviews and all deferred authorities remain paused.

Next: design/implement PMS-owned NDT requirement entry and establish appropriate non-material ITP integration boundary, honoring this exclusion and the no-second-source rule. Do not claim selection of either rejected/offered execution-scope option. Previous manual-valve delivery remains valid; its test counts are historical, not new tests for NDT.

---

## CURRENT — manual-valve entry delivered (2026-09-29)

User explicitly chose `pms_next_entry_area=manual_valves` after continuation. ADR-008 §23 design before implementation, §23.1 final; ADR-013 §15 archive correction. Report `docs/PMS-MANUAL-VALVES-2026-09-29.md`. **Do not reopen paused revision reviews/authorities by default.**

PmsValveEntryService reuses piping_class_valve_specs, flat item[valve_*] fields, explicit keep/replace/clear (default keep). Omitted fields preserve, fields without mode reject; replace VALVE-only and nonempty, both trim fields or neither, valid UTF-8/length/control checks, four manual operators or NULL. Unknown/withdrawn source text remains transcribable. Explicit keyed library selection captures trim/source; construction/dimensional claims separate. Description HTTP trimming exception preserves edge spaces. PmsEntryService shares item version/author/company/archive transaction and includes valve_spec in before/after events. Converting component away from VALVE requires explicit clear. Old events unchanged.

UI FA/EN author/editor/read summaries, optional JS suggestion filtering by qualifier; manual fields remain. Existing per-row ManualValves findings displayed nonblocking; class operatorConsistency NOT newly wired due to pending DR-04 runtime correction. DR-03 old findings categorization still legacy; no full engineering-conformity claim. Bulk input allowlist/prepared basis/preview/confirm include valve data and selected-reference provenance, atomic independent side rows.

Migration235900 protects valve-spec archive writes/delete with null-safe project/company scope and participant restriction, preserving reads. No column/new-table/data rewrite; forward-only. Raw DML archive INSERT/UPDATE42501, DELETE0, read/reopen and empty context tested; does not independently enforce human author nomination in arbitrary SQL.

Final225 selected tests/1085 assertions pass: PmsValve24/158 + ManualValve89/251 + prior seven PMS classes112/676.0 failures/errors/skips. Corpus25/25; explicit-file Pint/Blade pass. Initial dedicated failures were JSONB key-order expectations and stale test Project state for reopening; fixed expectations, then all rerun. No visual/JS/browser/full-product/restore QA or new two-connection valve race claim.32 reference files unchanged. Evidence `/home/user/organization-report/pms-valves-{final,target}.{log,xml}`, conformance/pint, before.json/delivery-check.json.

Runtime restored PHP8.4/PG17/vendor from lock. Initial composer postscript needed vendor symlink, then successful install. New local epcc seeded with standard demo (NOT restored); no author policy grants. Tests destructive only epcc_test NOSUPERUSER/NOBYPASSRLS. PG `project-database-0c655ca7`, app8000 `epcc-0fac3e59`. Migration applied to demo.

Next remaining operational gaps: NDT/ITP, SP, branch/size-rule forms, structured rating/references. Do not treat this as full PMS-ADR-06 closure or reopen PR-09/custody/review-round/evidence/adoption questions unprompted.

---

## CURRENT — class special requirements delivered (2026-09-29)

Under continued operational completion after units (not a new explicit user-selected option), delivered the existing special_requirements field's entry gap. ADR-008 §22 written before implementation; §22.1 records final behavior/evidence. Read §11.6/§12/§17–18, ADR-013 §10 and ADR-016 §9/9.1. Report `docs/PMS-SPECIAL-REQUIREMENTS-2026-09-29.md`. **Revision review remains paused.**

PmsEntryService applies keep/replace/clear inside the existing authorized, version-checked header transaction. Default keep even on new class; omission preserves, posted text requires explicit mode. Max50 nonblank lines/1000 Unicode chars per line/50100 total; CRLF/CR/LF splitting, Unicode whitespace-only lines omitted, other line-edge whitespace/order/duplicates preserved. Invalid arrays/UTF-8/C0 and C1 nontext controls refused. Only nested header.special_requirements_text exempted from trimming. No migration. Legacy odd JSON kept and rendered raw/escaped rather than auto-converted. No cite/adoption or NDT/ITP effects. Existing header source fields/reason and before/after audit reused; no per-entry structured references.

Final serial7-class regression **112 tests/676 assertions**, dedicated22/114 included;0 failures/errors/skips. Engine25/25, explicit-file Pint, Blade cache, live login HTTP200 pass. FA/EN writer/reader HTTP feature tests pass; not visual-browser or full-product/restore QA. Initial partial had undefined oldText; fixed to existing target-scoped h helper. Archive test corrected to existing redirect/validation behavior, not weakened gate. No new concurrency test for this field; existing PmsAccess suite included. Evidence `/home/user/organization-report/pms-special-{final,target}.{log,xml}`, before.json/delivery-check.json.32 reference files unchanged.

Runtime restored PHP8.4/PG17/vendor from lock; local epcc newly seeded with default demo (not restored), no default author grants. Destructive fixtures only epcc_test under NOSUPERUSER/NOBYPASSRLS. PG process `project-database-ab47bf78`, app8000 `epcc-aeaa8b9e`; older process IDs below historical. Vendor remains excluded cache symlink.

Remaining: other PMS-ADR-06 forms (NDT/ITP/manual valve/SP/branch rules), structured references/rating and other operational gaps. Do not reopen PR-09, custody, review-round/evidence/adoption authority by default. No publication/AFC or full-PMS completion claim.

---

## CURRENT — PMS header measurements delivered (2026-09-29)

Following continued operational work after bulk entry, implemented the already accepted units contract (not a new explicit `pms_units` selection). ADR-014 §14/14.1 and ADR-008 §21/21.1. **Revision design review remains paused; do not reopen deferred questions by default.** Main report `docs/PMS-MEASUREMENTS-2026-09-29.md`.

Migration235800 adds six nullable varchar source value/unit columns and widens three C/mm mirrors to numeric(16,6), with shape/bounds checks and captured-pair mirror-only/drop-pair guards. No historical pair backfill; populated/archived legacy reads labelled. Invalid old canonical values stop migration rather than being corrected silently. Forward-only down. DB does NOT independently recompute reference-pack factors/quantity mapping for arbitrary raw DML; conversion is application-owned.

New PmsMeasurementService runs inside existing author/company/version-checked header transaction. C/F only; LENGTH menu from existing units pack. Original decimal strings retained (six fractional digits); scalar malformed/nonfinite/fraction/exponent/wrong-unit/retired-unit rejected with field-specific errors. Canonical order checked before rounding. Keep/set/clear UI; existing defaults keep, new empty defaults user→project→pack. Reads show original/canonical/preferred without writes. Missing measurement keys preserve; unit-only cannot clear; old `_c`/`_mm` API inputs capture their explicitly named unit; mixed old/new rejects. Pressure Class/PN and DR-06 untouched.

Final serial sets: PMS/header **90 tests/562 assertions** + UnitRegistry/Preference **100/246** = **190/808**, zero failures/errors/skips. Dedicated measurement16/146 included. Engine25/25; compiled Blade and four read-only authenticated owner/writer FA/EN HTTP checks pass, no visual/full-product/restore QA. Evidence `pms-units-final.{log,xml}`, `pms-units-related.{log,xml}`, `pms-units-delivery-check.json` under organization-report. First189/802 commentary was superseded after adding the unit-only regression test; final190/808 is authoritative.

Runtime was absent: restored PHP8.4/PostgreSQL17/vendor from lock, created missing local epcc with demo seeds, no author nominations (NOT a restore). Tests reset only epcc_test under NOSUPERUSER/NOBYPASSRLS. PG process `project-database-d6d64ae0`, app8000 `epcc-platform-f451c4ad`; vendor symlink into excluded cache. Migration already applied to demo. Existing data/reference files not deleted.

Remaining operational work: other entry forms (special requirements/NDT/manual valve/SP/rules), structured citations/rating etc. No automatic authority or blanket implementation of outstanding ADR reviews. No standalone PMS export added. Do not represent current class editing as immutable published PMS or complete product.

---

## CURRENT — operational PMS bulk entry delivered; revision review paused (2026-09-29)

User explicitly said to leave revision reviews for later and continue completing the system. Selected `system_completion_next_area=pms_bulk_entry`. PR-09 impact-report question was skipped (no accepted answer). **Do not resume revision/authority questions by default.** Old design decisions/deferrals remain, but scoped operational bulk implementation was authorized; prior design-only restriction does not prohibit this selected feature.

ADR-008 §20/20.1, report `docs/PMS-BULK-ENTRY-2026-09-29.md`. New PmsBulkEntryService; shared prepareItem in existing entry service; preview/confirm routes and FA/EN no-JS UI. Explicit catalogue sizes (max100), common item fields, read-only preview/conflicts/row findings, authenticated encrypted 30-minute actor/company/project/class token, revalidation after existing review→entry locks, all-or-none independent rows and audit events. No overwrite/skip or schema change. Existing own-company author policy/no default author remains. Browser error recovery redirects to GET editor and preserves input. No revision/publication/AFC implementation.

Final serial PmsBulkEntry/PmsEntry/PmsAccess/PmsEntryMigration **48 tests/379 assertions** pass,0 skips/errors; bulk16/117 is INCLUDED. Engine corpus25/25. Four live read-only owner/writer×FA/EN HTTP checks pass; all unconfigured content forms closed. No visual/full-product/restore QA. Original pms-bulk-first failures were fixture errors, fixed; runtime move of vendor initially broke autoload/local package symlink, fixed by COMPOSER_VENDOR_DIR install from lock. Final48/379 reran after fix.

Runtime restored from absent environment; missing local epcc recreated with default demo seeds, no author nominations (not prior data restoration). Isolated epcc_test only reset by tests. PG17 process `project-database-a7bbf20f`; app8000 `epcc-platform-e172f78e`; vendor symlink to excluded `/home/user/.cache/epcc-vendor`. Main evidence `/home/user/organization-report/pms-bulk-final.{log,xml}` and `pms-bulk-delivery-check.json`.

Next operational gaps may be units or remaining entry forms; user has not selected those yet. Bulk custom sizes remain single-entry, Excel/multi-class/update batches not delivered. Review paused; do not reopen PR-09/deferred custody/PR-02/PR-07/adoption without explicit request.

---

## CURRENT — PR-08 later conflict warning-only accepted (2026-09-29)

User selected `pms_review_08_later_evidence_conflict=append_dispute_warning_only` (NOT recommended blocking option). ADR-008 §19.8: append attributed conflict/evidence, keep original acceptance/evidence/pin/state, show dispute, no new release blocker solely from conflict. No automatic revocation/reclassification to unknown. Keep basis knowledge and dispute flags distinct so PR-05 blocker is not smuggled in. PR-05 originally-unknown gate and PR-06 initial-reconstruction contradictory-evidence requirement remain; PR-04 and other release checks still apply. Formal dispute registration/resolution authority/effects remain unresolved; PR-07 deferral stands. No code/DB/tests. Updated design/review/reconciliation/TODO. Continue one-by-one design review only; no implementation authorized or repeated deferred-authority questions by default.

---

## CURRENT — PR-07 evidence acceptance authority explicitly deferred (2026-09-29)

User selected `pms_review_07_evidence_acceptance_authority=defer_evidence_authority`. ADR-008 §19.7: no default acceptance authority. Neither configurable independent reviewers nor existing AFC signers selected; proposed independence/no-self-acceptance is NOT accepted by this deferral. PR-05 gate and PR-06 evidence requirements stand; uploads cannot resolve basis, acceptance path must not be enabled until authority settled. No code/DB/permissions/tests changed. Custody, PR-02 and adoption authority also remain deferred. Do not re-ask these absent reopening. Other design review may continue one issue at a time; no implementation authorized. Possible next independent issue: how known later evidence contradicting a previously recorded historical basis is retained and flagged, without silently rewriting history or inventing revocation authority.

---

## CURRENT — PR-06 linked historical evidence accepted (2026-09-29)

User selected `pms_review_06_historical_evidence=require_linked_evidence`. ADR-008 §19.6 requires retrievable evidence for exact PMS content/revision, historical authorized status at AFC time, and explicit line/AFC-decision→revision connection. One or multiple records may establish it; labels, hashes or today's content alone cannot. Incomplete/conflicting evidence stays unknown; upload does not clear the release gate. Evidence acceptance authority remains unresolved and separate; do not infer it from writer/config/upload rights. Documentation only, no code/DB/tests. Next individual review: who may accept historical-basis evidence, without reopening deferred document custody/PR-02/adoption or re-asking accepted PR-01/03–06.

---

## CURRENT — PR-05 legacy unknown basis rule accepted (2026-09-29)

User selected `pms_review_05_legacy_unknown_basis=preserve_flag_block_release`. ADR-008 §19.5: preserve historical states/data and label unknown PMS basis; block new AFC→released-for-construction transitions until resolved; do not auto-revert already released lines. Not a field stop/continue-work instruction. Historical documentary reconstruction and new AFC confirmation are distinct; reconfirmation follows PR-04 and never fabricates missing history. Resolution authority/evidence sufficiency remain unresolved; no default permission. Documentation only, no code/DB/tests. Design/review/reconciliation/TODO updated. Next review can address adequate evidence for historical basis reconstruction, separately from its authority; do not re-ask accepted PR-01/03/04/05 or reopen deferred custody/PR-02/adoption by default.

---

## CURRENT — PR-04 current eligible publication for new AFC accepted (2026-09-29)

User selected `pms_review_04_new_afc_basis=current_eligible_only`. ADR-008 §19.4: new AFC confirmations require current published PMS AND AFC eligibility; when ineligible, authorized draft work continues but new confirmation blocks. No automatic/ordinary older-revision fallback; offered documented exception was not selected. Preserve existing historical AFC bindings without inferring revocation or permission to continue construction. Reconfirmation checks current eligible publication again. No code/DB/tests; design-only scope remains. Custody, PR-02 and adoption authority remain deferred. Design/review/reconciliation/TODO updated. Next independent review can address legacy AFC lines whose PMS revision basis is unknown; do not re-ask accepted PR-01/03/04 or deferred questions by default.

---

## CURRENT — PR-03 supersession at publication accepted (2026-09-29)

After deferring PR-02, user selected `pms_review_03_supersession_timing=supersede_on_publication`. ADR-008 §19.3: for PMS, separate working/current-published revision; creating successor WIP does not archive the published predecessor; successful successor publication replaces it atomically. Old AFC pins/content stay fixed. No new AFC eligibility for B publication, no global non-PMS CDE change and no independent withdrawal policy inferred. Detailed design §2/§4(d)/H09 updated; old immediate-archive descriptions remain as-built history, not target. Documentation only; no code/DB/tests. PR-02 review-round mechanism, custody and adoption authority remain explicitly deferred. Next individual review may address the release-vs-AFC-eligible basis when a successor is published with B suitability; do not re-ask PR-01/03 or deferred items by default.

---

## CURRENT — PR-02 review-round basis deferred (2026-09-29)

User selected `pms_review_02_review_round_basis=defer_review_round_basis`; snapshot-per-submission/round closure/resubmission proposal remains unresolved. ADR-008 §19.2; design/review/TODO updated. Continue to next issue without re-asking PR-02 or inferring default snapshot approval. PR-01 SHARED freeze remains accepted; custody and adoption authority still deferred. Documentation only, no code/DB/test changes.

---

## CURRENT — individual PMS design review; PR-01 accepted (2026-09-29)

User chose `pms_continue_after_design=review_design`, retaining design-only scope and deferred custody. First issue answered: `pms_review_01_shared_editing=freeze_shared`. ADR-008 §19.1 and design §4(b)/§11: content read-only in SHARED, review comments continue under existing rights, edits only after authorized WIP return; preserve history and do not automatically carry prior approval to changed content. No new transition/publication rights. No implementation or application tests. Next review should address binding review rounds to submitted content, one issue at a time, without reopening deferred custody or re-asking PR-01. Other design proposals remain unapproved.

---

## CURRENT — PMS hierarchy design only; custody explicitly deferred (2026-09-29)

After continuing, user selected `pms_document_custody=defer_custody`: only detailed design; leave document custodian unresolved and do not expand publication. **Do not start hierarchy code/migration under the earlier general continuation or infer a custodian.** Accepted single-PMS/revision/AFC and project-configurable own-company author decisions remain unchanged; DR-02 authority remains separately deferred.

ADR-008 §19 and `docs/PMS-REVISION-DESIGN-2026-09-29.md` record the answer and proposed technical plan: data/FKs/uniqueness, full graph copy, company boundaries, publication backstops/locks, historical AFC bindings/impact, DR-01 and legacy handling, rollout and 15 acceptance scenarios. Technical details are proposed, not blanket user-approved or implemented. Existing CDE archives the previous revision when creating a new WIP: historic AFC readers need recorded basis rather than current-state/currentRevision fallback. Cross-company graph-copy invocation authority is unresolved with custody.

This increment changed documentation only; no runtime/DB/code/reference changes and no application tests rerun. Earlier tests below are historical. Main deliverable is the design above; PMS-ADR-01/04 remain open. Next: await an explicit change in design-only scope/deferral before hierarchy implementation, without repeatedly re-asking the deferred question.

---

## CURRENT — PMS author-policy prerequisite implemented (2026-09-29)

After “continue” on the PMS hierarchy, user selected **project_configurable** authors and then **own_company_only** scope. Recorded in ADR-008 §17/17.1; design and delivered limits in §18/18.1. Implemented this authorization prerequisite first; **did NOT implement PMS document/revision hierarchy or AFC pins**. Report: `docs/PMS-AUTHORING-POLICY-2026-09-29.md`.

New `PmsAccessService`, migration `2026_09_29_235700_create_pms_author_policies.php`, FA/EN policy UI/history in PMS, current header/item/note gate checks AFTER the project policy lock, SQL event guard + author_policy_id. Existing owner-company/project.configure administers nominations; no default authors including admins. Role selections are company-scoped/live; memberships/accounts/PIPING scope are rechecked. Writers remain restricted to their own company. No publication/adoption authority inferred. Policy configuration/history and whole-document revisions are different version chains.

PMS-ADR-05 notes archive SQL/empty-GUC/participation guards closed; ADR-013 §14. Current-entry per-user authorization is NOT blanket raw-DML RBAC across all internal engineering setters/importers; see explicit scope caveat in ADR-008 §18. Future revision/publication implementation must cover all content paths.

Fresh tests: **47 / 343** (PmsAccess/PmsEntry/PmsEntryMigration/DocumentStandardSnapshot), **44 / 149** (ProjectArchive/FittingReview), engine corpus25/25. Live read-only HTTP: owner config UI vs writer, FA/EN, four 200s, all unconfigured content forms closed. No visual-browser or full-product/backup QA. First-run failures fixed: canWrite now binds its own tenant scope; old cross-project object test explicitly nominates its fixture actor in the target project, so it still tests the 404 object boundary rather than early 403 unconfigured denial.

Runtime absent again: restored locked dependencies and PostgreSQL17, created missing local demo `epcc` with default seeds (no authors nominated); no restoration of prior ephemeral demo claimed. Tests only reset `epcc_test`. Current processes: database `project-database-1485508e`, live app8000 `epcc-platform-edcaa839`. Previous IDs are historical.

**Next:** actual project→single PMS document→CDE revision→classes integration under ADR-008 §11.7, preserving company ownership, historical contents and AFC line references. Scope document custody/creation/publication mapping explicitly rather than assuming author nomination grants it. Legacy text revision/edit counters are not evidence of publication. DR-02 adoption authority still deferred. Do not re-ask the two author-policy choices or claim hierarchy is already built.

---

## CURRENT — PMS hierarchy continuation; configurable authoring accepted

User asked to continue after the PMS hierarchy explanation. Selected `project_configurable` in `pms_hierarchy_next`: nominate PMS-entry/edit users or roles per project and refuse content writes until configured. ADR-008 §17 records this accepted target; implementation not yet performed. No new publication or adoption authority was granted.

Inspected PmsEntryService/PmsController/FittingReviewService, memberships and roles. Existing writes are company-owned, and one project PMS may collect classes from multiple companies. **Next clarification:** whether designated authors have project-wide PMS content access or retain company-scoped editing. Do not silently broaden or hardcode this access. Then design the actual PMS document/revision/line-pin integration, preserving §11.7. Current sandbox runtime (PHP/vendor) is absent again; no runtime restoration, DB operation or application test in this increment yet.

---

## CURRENT — DR-01 CDE/ITP reference-basis increment delivered (2026-09-29)

User selected **implement_dr01** after asking to continue. Read ADR-PROCESS, ADR-016, ADR-015 and relevant ADR-008 lifecycle/reconciliation sections directly. ADR-016 §10 records the implementation design; §11 records implemented/verified boundaries. Report: `docs/DR01-REFERENCE-SNAPSHOTS-2026-09-29.md`.

Implemented additive migration 235500, publication-time reference JSON on document revisions, explicit source-edition field, no catalogue fallback at release, legacy-uncaptured status (no inferred backfill), immutable finalized references/SQL backstops, locked current-document transitions, next-revision independence, and FA/EN history/source-edition UI under existing doc.view/doc.issue+ownership. ITP rendering uses the frozen basis. Existing automatic citation/adoption remains DR-02's unresolved implementation, NOT accepted authority.

Verified: focused **15 tests / 81 assertions**, final serial DocumentRegister/StandardsLibrary/Itp **128 / 317**, engine conformance corpus **25/25**, live HTTP FA/EN 200. First regression's draft-cascade deletion defect was fixed before the full rerun. A real forward migration, raw-DML tampering and two-connection lock contention are included. No new browser visual tests, full-product run or backup/restore experiment.

**Still open:** PMS whole-document CDE integration, structured citations/AFC pins (PMS-ADR-01/04), complete ITP content/signed-record revision binding and historical engineering-pack replay. DR-01 is not globally complete. DR-02 adoption authority explicitly deferred; no new default role. DR-03–06 accepted but not implemented in this step.

Runtime was absent in fresh sandbox: rebuilt PHP 8.4/Composer/PostgreSQL17 and dependencies. app/AGENTS bootstrap required Laravel Boost dev installation; generated guidance/config/skills and composer lock changes are tooling. Created absent local `epcc` database with default synthetic seed; tests isolated in `epcc_test` without superuser/BYPASSRLS. This was NOT a restore of previous ephemeral demo data. Current process IDs: database `project-database-7b5ec8e1`; live app port8000 `epcc-platform-9163486d`. Old progress notes/process IDs below are historical.

Next work should scope actual PMS revision/citation dependencies before claiming DR-01 works in PMS. Do not silently resolve DR-02 authority, start unrelated decisions or claim that source edition transcription is contractual adoption. References have source-version metadata, not evidence of engineering truth.

---

## CURRENT — all six ADR-review answers recorded (2026-09-29)

**This supersedes the dialogue progress notes below, which are retained as history.** User requested one-by-one explanation, question and answer. Received: DR-01 `freeze_at_release` (ADR-016 §8); DR-02 `separate_adoption` plus explicit `defer_authority` (ADR-016 §9/9.1); DR-03 `distinguish_results` (ADR-007/008 §14); DR-04 `advisory_unless_evidenced` (ADR-008 §15); DR-05 `verified_restore_protocol` (ADR-013 §13); DR-06 `independent_datum` (ADR-014 §13).

Only adoption authority remains an explicitly deferred policy choice. Detailed designs, legacy handling, code/schema/reference updates and verification are NOT completed. All changes in this dialogue were documentation-only. No new permission was inferred, no runtime/database operation was performed and no application/restore tests were run. The review report/TODO/index carry current status. Do not re-ask answered principles or silently treat the deferred authority as resolved. Further implementation must follow primary ADRs and state its scope accurately.

---

## Latest decision — DR-05 accepted; next DR-06

User selected `verified_restore_protocol`; ADR-013 §13 records the replacement safety requirements, not a completed restore design or tested implementation. Ask DR-06 pressure datum separately next. DR-02 authority remains explicitly deferred. Documentation only.

---

## Latest decision — DR-04 accepted; next DR-05

User selected `advisory_unless_evidenced`; ADR-008 §15 records advisory-only DN/operator heuristic without independent blocking effect. DR-01/03 accepted, DR-02 separation accepted but authority deferred. Ask DR-05 restore-safety issue next. Documentation only; no tests or runtime changes.

---

## Latest decision — DR-03 accepted; next DR-04

User selected `distinguish_results`; recorded in ADR-007/008 §14. DR-01 and DR-02 principle accepted; DR-02 authority explicitly deferred. Ask DR-04 separately next. No implementation or tests performed.

---

## Current decision step — move to DR-03 (2026-09-29)

User explicitly selected **`defer_authority`** for the DR-02 follow-up: adoption-authority choice remains open, but the citation/adoption separation principle is accepted. Proceed to the third issue, reference absence versus physical impossibility. Do not ask another DR-02 authority question now or infer a default role. ADR-01 freeze-at-release remains accepted; DR-03–06 unanswered. Documentation updated only.

---

## Current question — DR-02 adoption authority (2026-09-29)

The user answered DR-02 **`separate_adoption`**. Principle accepted and recorded in ADR-016 §9: citation does not automatically establish a contractual project requirement. **Next ask, within this same issue, who may approve standard/edition adoption.** No authority has been selected yet. DR-01 `freeze_at_release` remains accepted. DR-03–DR-06 remain unanswered. Continue one question/issue at a time; no code or data changed.

---

## Current decision dialogue — one issue at a time (2026-09-29)

User explicitly requires presenting each ADR problem, asking how to resolve it, and receiving their answer before moving to the next. **DR-01 answered: `freeze_at_release`** — draft citations may change; standard identity/edition/clause are frozen for each document version at publication/final approval, edition specified first. Recorded in ADR-016 §8 and the decision-review report. Implementation is pending; no code/schema changes or new tests in this decision step.

**Next: ask DR-02 separately** (citation vs contractual adoption). Do not treat DR-01 as approval of versioned adoption, automatic adoption, new permissions, or any other review proposal. DR-03–DR-06 remain unanswered. Concrete lifecycle/legacy migration details for DR-01 must be specified before coding, without retroactively inventing source editions.

---

## Latest instruction — critically review ADR decisions with reasons (2026-09-29)

The user requires reporting flaws/omissions in prior ADR decisions, not merely enforcing them. `docs/ADR-DECISION-REVIEW-2026-09-29.md` separates six decision/assumption findings from implementation gaps: **DR-01** immutable standard-edition citation history; **DR-02** citation vs contractual adoption; **DR-03** unknown reference vs physical impossibility; **DR-04** operator monotonicity as a heuristic; **DR-05** restore/FK validation and snapshot completeness; **DR-06** pressure unit vs datum. These are proposals/review items pending decision, not implemented policy changes.

Factual notes: ADR-014 round-trip example recalculated (285.0006 is not obtained from its stated numbers); stale canonical/display fallback diagram; ADR-013 replica disables checks rather than deferring them and VALIDATE of already-valid constraints is a no-op (official PG docs verified). PG18 `--where` claim is not supported by its official option list; RLS-visible dump option exists; historical exit=0 remains unverified. See report for sources and qualifications.

Documentation only; **no runtime code/schema/reference data changes and no application/restore test run**. A standalone Decimal calculation and official-document review were performed. Existing accepted decisions remain until explicitly amended; do not silently apply proposed replacements. Next architecture decision should resolve DR-01/02 before standards/PMS integration, alongside previously recorded PMS conformity gaps.

---

## Mandatory ADR workflow and open PMS conformity gaps — 2026-09-29

**Latest user instruction:** every new capability requires its relevant ADR; amendments/new behavior must be added to the existing subject ADR, or a new ADR for a distinct subject. This applies before/during implementation, not as optional post-delivery prose. Read root `AGENTS.md`, `docs/ADR-PROCESS.md` and `docs/ADR-INDEX.md`.

A direct, scoped ADR/source reconciliation found **open** gaps in the recent PMS UI: whole-document revision/publication/AFC pins, entered units + canonical mirror, bulk entry, structured standards adoption/citations, note-table SQL archive protection, remaining PMS fields/screens, entry-authority decision and structured rating identity. See `docs/ADR-RECONCILIATION-2026-09-29.md` and ADR-008 §12. Do not relabel entry_version as a document revision or amend away accepted requirements to fit the code. Publication/revision and bulk entry were already user decisions, not optional new inventions. PMS authority details need explicit review rather than being retroactively attributed to the user.

ADR-017 now records prior user-confirmed project-material review/screening/response/disposition decisions. ADR-007/013/014/016 add boundary/gap notes. **This increment changed documentation only: no runtime tests, code/schema changes or gap fixes.** Earlier test counts remain evidence for their earlier scope, not ADR-conformity proof. Previous runtime/process IDs are unverified in this turn.

**Next work:** close the reconciliation gaps under the applicable ADRs before claiming a complete/released PMS capability. The earlier entry delivery is partial, not a new waiver of accepted architecture.

---

## Current increment — PMS entry UI and explicit reference links, 2026-09-29

**Start with `docs/PMS-ENTRY-2026-09-29.md`.** User authorized PMS integration, selected BOTH standard-library data and approved project definitions, then corrected the premise: no PMS entry UI existed; it must be added now. Delivered FA/EN class header + numbered note + per-size body row entry/editing. This is external-document transcription, not authoring/approving an engineering PMS. References are explicit: library selections fill only selected reference columns; an approved project-definition link does not overwrite row values or grant use authorization. Source wording is preserved, edits require a reason/version and write append-only before/after history. No deletion endpoint.

Migration **56** adds PMS provenance/version columns, approved-definition FK/SQL guard, immutable audit events, item participation/archive restrictions and safe empty-GUC handling. Existing records remain at entry_version 0 with no invented links/history. Current approval is computed from captured workflow decisions, not the legacy declaration review_state column. Superseded links remain pinned and warn; new selection refuses them. Active PIPING-scope members may transcribe classes for their own company; other project participants read. No new reviewer authority or class approval workflow.

Verification: targeted **310 PHP tests / 1,845 assertions**; final new-suite rerun **18 / 151** after UI localization, core **60**, Python **21**, **19** validated packs. Browser creation/edit flow plus three participants and four FA/EN desktop/mobile form/row cases passed; final read-only repeat passed. Not full application or load/production certification.

Current runtime: PG `project-database-974aad96`; app `epcc-platform-pms-81d3b7bc`, port8000/debugfalse. Demo DB reset/reseeded under standing authorization. Project PH-14 → piping → PMS entry; synthetic class `QA-PMS-UI`, three rows (PIPE 6, ELBOW 6, custom CAP 60), six audit events, one numbered note, two project definitions (one approved, one unreviewed). Latest browser IDs in organization-report/pms-browser/results.json. No actual engineering clearance. Earlier runtime IDs/counts below are historical. Vendor/browser dependencies and processes may disappear on resume.

Pending: branch-table/per-size-policy entry screens, detailed manual-valve trim/operator editor, comprehensive class approval/release/revision workflow, and any deliberate model/MTO application. Do not silently infer equivalence between project-source free wording and reference codes. B16.9 NPS ½–48/cap rules unchanged; NPS60 demo is explicit project-Spec transcription, NOT an extension of B16.9.

---
## Earlier increment (historical)

## Current increment — formal finding dispositions, 2026-09-29

**Read `docs/MATERIAL-FINDING-DISPOSITIONS-2026-09-29.md` first.**
Migration 55 implements independent current-reviewer, append-only decisions on cited author responses. ACCEPT_EXPLANATION / REQUEST_EVIDENCE / REJECT_NA / documented ACCEPT_NA are RECORD_ONLY. No new final approval gate or material-use authority. Source responses, captured evidence and previous decisions remain immutable. Replacements require latest predecessor and change reason. New responses/corrections inherit nothing.

Current targeted verification: **138 PHP tests / 1,322 assertions**, core 60 assertions, Python 21 tests, 19 valid packs; three-actor browser workflow plus four FA/EN desktop/mobile active history/form cases passed. Not the full application suite. Runtime: PG `project-database-366a7555`; app `epcc-platform-e57c795b`, port 8000, debug false. Fresh seeded demo plus one synthetic PIPE definition in review, one N/A response and two dispositions (request evidence → accept N/A). No engineering clearance. Dependencies remain excluded from snapshots. Earlier runtime IDs/counts below are historical.

---
## Previous increment (historical)

Last updated: **2026-09-29** · **cited finding responses and current-reviewer notes delivered**.

> **START WITH `docs/MATERIAL-FINDING-RESPONSES-2026-09-29.md`.**
> User selected finding_resolution: cited explanations and N/A rationale without
> changing approval rules. Delivered append-only EXPLANATION / NA_REQUEST from
> eligible submitting-company members and REVIEW_NOTE from the current independent
> reviewer. Every note binds to a captured finding/run, not today's live result.
> N/A is a proposal only: no acceptance/resolution status, exception or new gate.
> Mandatory citation/rationale; source excerpt required for N/A. No auto source
> verification, state mutation, reviewer-stage advancement or PMS/model/MTO use.
> Archived/approved/superseded versions are read-only; corrections have new threads.
> Migration 54 adds forced-RLS immutable notes, finding binding, current-role SQL
> guard, per-finding sequence and retry-token uniqueness. Existing review locks,
> source history, snapshots and B16.9 ½–48/cap constraints remain unchanged.
> Final targeted verification: **123 PHP / 1,218 assertions**, includes **15 new /
> 100**; core 60 assertions, 21 Python tests, 19 packs, three-actor browser flow,
> final 24 bilingual pages + four active mobile form cases passed. Not full suite.
> PG `project-database-3d7885a3`; app `epcc-platform-665a5e9f`, port8000/debugfalse.
> Demo: 54 migrations, 7 definitions, 2 PIPE policies, 3 runs/decisions/snapshots,
> 7 notes. Open synthetic discussion record is deliberately still IN_REVIEW.
> Source manifest/archive retained; vendor is an excluded-cache symlink.
> Prior reports/counts/runtime IDs below are historical; the new report is current.

## Current operational baseline

- B16.9-2018 scope **NPS 1/2–48** is a standing user ruling. Universal pack v1.2.0
  retains 99 LR90/LR45/cap rows. Outside-range definitions are project-Spec data,
  never inferred B16.9 values. In-range large-cap applicability/agreement remains
  separate; no two-size expansion. Source and range reports remain in docs/.
- User authorized reset/reseed of the current synthetic DB without renewed
  confirmation. Source files and future real/production data are excluded.
- Runtime rebuilt; demo has **51 migrations**, two linked synthetic definition
  versions, two policy revisions, two review runs, three decisions. The corrected
  test version is project-approved under v1; current demo policy v2 applies only
  to new submissions. This is synthetic acceptance evidence, not design approval.
- Keep PostgreSQL **epcc-caafc8aa** and app **epcc-f904a537** running. App serves
  0.0.0.0:8000, runtime APP_DEBUG=false. Chromium/Playwright are available.
- Demo accounts: owner `karimi@nioc.example`, contractor `rezaei@pars.example`,
  consultant `tehrani@pmc.example`; password `password` (synthetic only).
- No independent authenticated external Arena gateway acceptance. Local browser
  results and earlier iframe simulation must not be described as that proof.
- This is targeted regression, not a new full suite or security/engineering
  certification. Earlier audit and phase-1 records are historical snapshots.
- Review migration is forward-only to preserve audit history. Project deletion
  tools must account for restrictive review FKs. Archive and disposable reset work.
- Next: explicit approved-definition selection in PMS, conflict handling, audited
  recovery/rerouting/revocation for blocked review runs, then deliberate piping/MTO
  consumption. Never silently apply declarations or rewrite source wording.

---

This is the one file to read before touching anything. It answers: what is this,
what works today, what is deliberately missing, what will bite you, and what to
do next. Everything else is detail hanging off `README.md`.

---

## 1. What this project is

A bilingual (EN/FA) modular platform for managing large industrial EPCC
projects — oil, gas, petrochemical, power. Sold per company on **Full** and
**Special** plans.

Four decisions are closed and should not be relitigated without a new ADR:

| Decision | Value | Where |
|---|---|---|
| Launch market | **Iran first.** On-prem/air-gap, Persian default, Jalali, IRR. International SaaS is a later phase, not parallel. | `docs/Iran-First-Market-Addendum.md` |
| Stack | **Laravel 13.31 + Filament v4 + PHP 8.4 + PostgreSQL 17** | `docs/ADR-003-Annex-A-Laravel-Evaluation.md` |
| Standards | **Selectable per project** — `ips-iran` (default), `iso`, `awp`. Chosen once at creation. | `standards-packs/` |
| AWP | **Deferred** to the international phase, kept in CI as a genericity guard. | `docs/ADR-002-Defer-AWP.md` |

> The requirements document is written **in English** by explicit instruction,
> even though project communication is in Persian. The *software* is bilingual.

---

## 2. Run it in 60 seconds

```bash
cd /home/user/epcc-platform/app
php artisan serve --host=0.0.0.0 --port=8000
```

After a sandbox reset the runtime is gone (source is not). Rebuild:

```bash
cd /home/user/epcc-platform && ./restore.sh     # idempotent, ~3-5 min
```

Sign in with any seeded account, password `password`:

| Email | Company | Plan | Good for demonstrating |
|---|---|---|---|
| `karimi@nioc.example` | NIOC-AFF (owner) | special | Everything unlocked; can create projects |
| `rezaei@pars.example` | EPC-PARS (contractor) | full | Authority limit blocks a 43.2B certificate |
| `hosseini@arvand.example` | EPC-ARVAND | **starter** | Locked modules / upgrade prompts |
| `tehrani@pmc.example` | PMC-TEHRAN | full | Consultant view |
| `former@pars.example` | EPC-PARS | — | **Disabled account** — gate 1 refusal |

All seeded emails end in `.example` and are generated by `AuthSeeder`; the login
screen lists them from the database, so they cannot drift out of sync.

---

## 3. What actually works today

**~40–45% of the specified system** (`docs/Prototype-Coverage-Map.md`, COV-EPCC-001).
Be honest about this in any demo.

- **Authentication** + the full **ARC §5 six-gate authorization pipeline**
  (AUTH → ENTITLEMENT → MEMBERSHIP → RBAC → ABAC → SoD, first failure wins).
- **Multi-tenancy enforced by PostgreSQL RLS**, not by `where` clauses. Scope is
  company × project.
- **Multi-project portfolio** and per-project certificate workspaces.
- **Project setup**: create a project, pick its standards pack, invite companies,
  staff people.
- **Release gates** driven by the framework-free standards engine.
- **Bilingual FA/EN with real RTL** and Jalali dates.
- **Entitlement-driven sidebar** (see §5).
- **Document register / CDE** (`doc`): rule-driven numbering, ISO 19650 states
  (WIP → Shared → Published → Archived) with suitability codes, revision
  control that archives rather than overwrites, **file attachments**
  (content-addressed, access-controlled), and the **review & comment cycle**
  that gates publication. See §5a and §5c.
- **Transmittals** (`doc`): formal multi-recipient issue, per-company
  acknowledgement with derived overdue tracking, and a bilingual ZIP package
  with a checksummed manifest. See §5d.
- **PDF export** of the MC certificate and the transmittal sheet, in both
  languages, via headless Chromium. See §5f.
- **QA/QC — punch list & Mechanical Completion** (`qaqc`): subsystem walkdown,
  A/B/C categories, a six-state lifecycle with independent verification, and the
  **MC gate** with its employer-approved exception. See §5e.
- **Filter + sort on every register table** (§5b), Persian-digit aware.

**Acceptance journey 3 (engineering document flow) now runs end to end**:
create → attach → share → review with blocking comments → dispose → publish →
transmit → acknowledge — **including PDF output**, which was the last gap.

**Acceptance journey 5 (mechanical completion) now runs end to end**: walkdown →
raise → assign → fix → verify → close → certificate, with the A-item gate
refusing issue and the owner's recorded exception the only way past it.

Deliberately absent: full-text/OCR search, offline sync, PDF export,
integrations, installer, and the PROCUREMENT / HSE / SCHEDULE modules.

---

## 4. Architecture rules you must not break

These were each learned by fixing a real defect. Full list in
`docs/Spike-RLS-Results.md` (12 standing rules).

1. **`SET LOCAL` requires an open transaction**, otherwise tenancy fails **open**.
2. Use **`FORCE ROW LEVEL SECURITY`**; table owners bypass RLS otherwise.
3. **`NULLIF(GUC,'')`** — `SET LOCAL` leaves the GUC as `''` after commit.
4. **Audit by actor**, not by subject.
5. **Never RLS-test on SQLite.** It has no RLS; the test would pass and prove nothing.
6. **Participation ≠ membership.** `project_company` is the commercial fact RLS
   keys on; `project_memberships` is the personal grant. Read queries filter on
   **both** (ARC §5.4).
7. **Tenant state is transaction-scoped.** Never a plain `SET`.
8. **An ungated certificate is not a cleared one** — it is unexamined.
9. **Middleware order is load-bearing** — `auth` before `tenant`.

---

## 5. The sidebar is an authorization surface

`app/Support/Navigation.php` builds the menu from the *same* gates the pipeline
enforces, so the menu can never offer something the pipeline then refuses.
Three states, and conflating any two is a real defect:

| State | Meaning | Rendered as |
|---|---|---|
| `available` | Entitled and built | A real link |
| `planned` | Entitled, **not built yet** | Inert, "Soon" |
| `locked` | **Not in the company's plan** | Inert, "Upgrade" — the in-product upsell surface |

Telling a customer to *upgrade* for a module they already pay for is a billing
complaint; telling them "soon" for something they never bought is a lost sale.
Keep the distinction.

- Locked/planned items render as `<span>`, never `<a>` — no href means they
  cannot be clicked, middle-clicked or crawled into a denial.
- Icons are **inline SVG**: the preview iframe has no network, so icon fonts and
  CDNs silently fail.
- Injected by a **view composer** (`ViewServiceProvider`), not by each
  controller — otherwise every new screen must remember the sidebar, and the one
  that forgets renders a page with no way out.
- `PLANNED_MODULES` in `Navigation.php` is the list to shrink as modules land.

**Collapse / expand.** Desktop toggles `body.rail` (a 60px icon rail); mobile
toggles `body.drawer` (off-canvas, closed by the scrim, Escape, or following a
link). Class names match `prototype/index.html` deliberately, so the two cannot
drift. Three non-obvious constraints:

- The burger lives in `partials/topbar.blade.php`, **outside** the sidebar. A
  control that collapses the sidebar must survive the collapse — inside the
  drawer it would slide off-canvas and strand the user.
- The collapsed state is restored **in `<head>`, before first paint**. This app
  is server-rendered, so every click is a new document; restoring it at the end
  of the body makes the sidebar flash open on every navigation, which reads as
  a broken toggle. The class goes on `<html>` first, then mirrors onto `<body>`.
- The desktop rail state must be **neutralised inside the mobile media query**,
  or the off-canvas panel slides in 60px wide showing icons and nothing else.
- **`max-width` and `overflow` do nothing on an inline box.** Anything collapsed
  by the rail must be `inline-block` (or block) first. The language switch is an
  `<a>` — inline by default — so when it inherited the collapse rules it laid
  out at zero usable width and disappeared from the *expanded* sidebar.
- **`display:none` cannot be animated.** It is a discrete property, so hiding
  labels with it makes the rail snap however smooth the container is. Collapse
  them with `max-width`/`opacity` (inline) and `height`/`padding` (block), and
  keep `overflow-x:hidden` on the panel so text clips instead of scrolling.
- The width transition must live **outside** the mobile media query. The
  original one only applied to `transform` on mobile, so desktop never animated.
- `prefers-reduced-motion` is honoured — motion is a vestibular accessibility
  issue, not a taste preference.
- The script **must wait for `DOMContentLoaded`.** The sidebar renders before
  the content column (flex order), so the script is emitted *before* the button
  it wires up; running immediately made `getElementById` return `null` and the
  script bail at its own guard — the button looked perfect and did nothing.

Behaviour is covered by `app/tests/Browser/sidebar-toggle.test.cjs` (17
assertions, jsdom). PHPUnit never runs JavaScript, so the Laravel tests can only
assert the markup is present — run the jsdom test after touching the script.

> **Never hand-run a script the page already ships.** The first version of that
> test re-evaluated the sidebar script *after* parsing had finished, which found
> the button and passed 17/17 while the live page was completely dead. Let the
> parser execute it in natural document order (`beforeParse` to stub
> `matchMedia`, then wait for `load`). A test that bypasses the real execution
> order proves nothing.

---

## 5a. Document register / CDE

`app/Support/CdeState.php` is the state machine; `app/Services/DocumentRegister.php`
holds the rules; `documents` + `document_revisions` are the tables.

Two rules are load-bearing and are enforced **server-side**, not by hiding
buttons:

- **WIP cannot jump to PUBLISHED.** Skipping SHARED skips review — a drawing
  reaching construction unchecked is the exact failure ISO 19650 exists to
  prevent. SHARED → WIP *is* allowed: review rejecting a drawing is normal.
- **ARCHIVED is terminal.** Superseding is a record, not an erasure, so a new
  revision archives the old one and history is kept.

Suitability codes are validated against the target state (S-codes for SHARED,
A/B-codes for PUBLISHED); only WIP may carry none.

> **When adding a tenant table, copy the policy from migration `110000`, not
> `100100`.** The document register shipped with the *original* policy and had
> two defects at once: no `NULLIF(GUC,'')`, and no project scope — so an owner
> scoped to PH-14 still saw its own KISH-1 rows, because those are also "its
> own". Company identity is not a project scope (standing rule 7).

> **Eager-load RLS relations inside `runAs()`.** A lazy `->currentRevision`
> evaluated after the scope closes runs with no bound tenant and returns NULL.
> The row is invisible, not missing — fail-closed, but it looks exactly like a
> broken write.

### 5a.1 Attachments (FR-DOC-01/02)

`DocumentFileStore` owns the bytes; `FileType` owns what is acceptable.

- **Content-addressed storage.** The path is
  `documents/{project}/{ab}/{cd}/{sha256}.{ext}` and contains **no user input
  at all**. Path traversal is designed out rather than filtered out. The hash
  doubles as the integrity record (FR-AUD-02) and de-duplicates re-issues.
- **Allow-list, never a deny-list.** A deny-list is a list of the attacks
  someone already thought of. SVG and HTML are refused outright: documents to a
  user, executable script to a browser.
- **The stored MIME comes from our table, keyed by extension** — never from the
  client's `Content-Type`.
- **Only PDF/PNG/JPEG are served `inline`**; everything else is `attachment`,
  plus `nosniff` and a restrictive CSP. Inlining an arbitrary upload is how a
  stored file becomes stored XSS.
- **Nothing is on a public disk.** Every byte is streamed through
  `DocumentController@download`, so downloads pass auth → entitlement →
  membership → RLS exactly like the register screen.
- **Files attach only to WIP/SHARED revisions.** A published revision is a
  matter of record; the way to change it is a new revision.
- **Detach never deletes shared bytes** while another revision references the
  same hash.

> **PHP's upload limit fails SILENTLY.** Exceeding `upload_max_filesize` yields
> an `UploadedFile` that is merely *not valid* — no exception. The stock 2 MB
> default rejects nearly every real deliverable. `restore.sh` now writes
> `/etc/php/8.4/cli/conf.d/99-epcc.ini` (100 MB), and the store reports the
> server limit by name instead of "unknown error".

### 5a.2 The CDE must actually be common

> **Found by live verification, not by tests.** The document tables shipped
> with the standard tenant policy — own rows, plus the employer sees all. That
> is right for a payment certificate and **wrong for a document register**: a
> PMC holding `doc.view`, a full participant on the project, opened the
> register and saw *"no documents"*. Consultant, MC and TPI were all equally
> broken. Every unit test passed, because they all asserted the old model.

Migration `130000` makes **the CDE state the sharing boundary, not company
ownership**:

| Row | Author | Other participants | Employer | Non-participant |
|---|---|---|---|---|
| WIP | ✅ | ❌ | ✅ | ❌ |
| Shared / Published / Archived | ✅ | ✅ | ✅ | ❌ |

WIP stays private because a contractor will not adopt a system that exposes
half-drawn work to the client. `WITH CHECK` is deliberately **unchanged** —
read widely, write narrowly.

The employer keeping sight of WIP is a **deliberate, reversible** choice (it
matches how the owner is treated everywhere else here, and Iranian employer
organisations expect it). Strict ISO 19650 would hide it; that change is
confined to one branch of the policy.

Verified live across five roles on PH-14: author 5 docs / 1 WIP, consultant
4 / 0, employer 5 / 1, rival contractor 4 / 0, non-participant 404.

## 5b. Table filter + sort (FR-UX-04)

`resources/views/partials/tabletools.blade.php`. Opt a table in with one
attribute; everything else is discovered from the markup:

```blade
<table data-tt>
  <thead><tr>
    <th data-sort="text">Number</th>
    <th data-sort="num">Amount</th>
    <th data-sort="text" data-facet>State</th>
    <th>Actions</th>            {{-- no data-sort: not sortable --}}
  </tr></thead>
```

Gives every data table a search box, a dropdown per faceted column, a live
row count, a reset button, and click-to-sort headers (asc → desc → **back to
the server's order**; without that third state there is no undo).

**Two escape hatches, both load-bearing:**

| Attribute | On | Why |
|---|---|---|
| `data-sortkey` | `<td>` | Sort by something other than the displayed text. |
| `data-facet-value` | `<td>` | Group by something other than the displayed text. |

Where they are used and what breaks without them:

- **CDE state** → `data-sortkey="{{ CdeState::ordinalOf(...) }}"`. Sorting the
  *label* is wrong in both languages: alphabetically "Archived" precedes "WIP",
  and the Persian labels collate differently again. The register is read as a
  pipeline and must sort as one. `CdeState::ordinalOf()` is the single source
  of that order; an unknown state sorts last rather than throwing, because a
  sort must never be the thing that takes a page down.
- **Money** → `data-sortkey="{{ $c->net_amount }}"`. The cell renders
  `۴۳٬۲۳۴٬۵۰۰٬۰۰۰`; sorting that as text is nonsense.
- **Gate verdict** → `data-facet-value`. The cell shows "Blocked" *followed by
  every failure reason*, so faceting on raw text would give each blocked row
  its own dropdown entry. A filter with one row behind every option is not a
  filter.

> **Persian digits are folded before matching.** Typing `14` finds `۱۴-P-۱۲۰۱`
> and vice versa. Arabic yeh/kaf are folded to Persian, ZWNJ to a space, and
> diacritics dropped — a user on an Arabic keyboard, or pasting from an older
> Windows document, must still find the row.

**Progressive enhancement.** The toolbar is *built by script*. The server never
ships a search box that would be dead if the script failed — a plain, complete,
working table degrades to exactly that. The script sits in `<head>` and so
carries the same `DOMContentLoaded` guard as the sidebar (§5); without it,
`init()` finds no tables and fails silently.

**Filtered rows use the `hidden` attribute**, not a class, so they leave the
accessibility tree too. The row count is `aria-live="polite"`; sortable headers
are real `<button>`s with `aria-sort`.

> **Client-side, deliberately.** These are one project's rows — tens, not tens
> of thousands. A server round-trip per sort would be slower, lose scroll
> position, and thread every filter through the RLS query. **When a register
> outgrows this** (the SRS assumes 20k documents) the answer is server-side
> paging behind the same controls, not a bigger script.

Guards: `tests/Feature/TableToolsTest.php` (10 tests) + `tests/Browser/
table-tools.test.cjs` (44 assertions fa / 41 en). The PHPUnit sweep walks
**every** Blade view and fails if any `<table>` with a `<thead>` lacks
`data-tt`, so a future screen cannot ship an unfilterable table.

## 5c. Review & comment cycle (FR-ENG-06)

`CommentCategory`, `CommentDisposition`, `ReviewCycle`, `ReviewComment`,
`documents/review.blade.php`. Implements acceptance journey 3.

**The rule the module exists for: an open blocking comment prevents
publication.** Without it a comment sheet is a suggestion box, and a drawing
with an unanswered Code 1 reaches the site.

| Category | Blocks publication | Needs an answer |
|---|---|---|
| HOLD, CODE1, CODE2 | **yes** | yes |
| CODE3 | no | yes |
| CODE4, INFO | no | no |

`CommentCategory::blocksPublication()` is the single source of that truth;
adding a category is a one-line change, not a hunt through controllers.

**The gate lives in `DocumentRegister::transition()`**, not the controller —
the API, the importer and any future bulk action all pass through that method,
and a gate that only exists on one screen is not a gate. The refusal *names the
comments* ("comments 1, 4, 5"), because "blocked by 3 comments" sends the user
hunting.

Other enforced rules, each mutation-tested:

- **The originator cannot review its own document.** Self-approval is the
  control the owner is paying for.
- **Only the originator may dispose.** A reviewer answering its own comment is
  marking its own homework.
- **Rejecting requires a written justification.** "Rejected" with no reason is
  how a review cycle becomes a dispute, and that dispute is eventually read by
  a lawyer.
- **Only SHARED revisions are under review.** WIP has not been offered;
  PUBLISHED is answered by a new revision, not a late comment.

> **This table breaks the tenant pattern deliberately.** A comment has TWO
> companies with a claim: `company_id` (the reviewer who wrote it) and
> `originator_company_id` (who must answer). Keying the policy on `company_id`
> alone would stop the contractor answering comments on its own drawing —
> i.e. the entire workflow. Reads are open to every project participant
> (hiding comments from other reviewers is why reviews go round three times);
> writes are restricted to those two companies, with per-column rules in the
> service.

> **`FOR UPDATE` is not allowed with aggregate functions.** Sequence allocation
> locks the parent `document_revisions` row, not `max(seq)` — PostgreSQL
> rejects the latter outright. Parallel multi-reviewer is a stated requirement,
> so two reviewers colliding on `(revision_id, seq)` is the normal case.

> **A DB column default is NOT reflected back into the model.** `disposition`
> defaulted to `'PENDING'` in PostgreSQL, but the freshly created object held
> `NULL`, so `isPending()` and `isBlocking()` returned the wrong answer on a
> brand-new comment while the row was correct. Set defaults explicitly in the
> service. Caught by a test asserting the in-memory object.

## 5d. Transmittals (FR-ENG-05)

`TransmittalPurpose`, `Transmittal`, `TransmittalRecipient`, `TransmittalItem`,
`TransmittalService`, `TransmittalPackager`, `transmittals/index.blade.php`.
Completes acceptance journey 3: a document is drafted, reviewed, published and
now **formally issued**.

**A transmittal is evidence.** Its value in a delay claim is that it proves
*what* was sent, *to whom*, *when*, and *when they acknowledged it*. Every rule
below protects one of those four facts.

| Purpose | Acknowledgement | Response due |
|---|---|---|
| FOR_REVIEW | required | 14 days |
| FOR_APPROVAL | required | 10 days |
| FOR_CONSTRUCTION | required | 7 days |
| FOR_INFORMATION | not required | no clock |

- **Numbering** `{PROJECT}-TR-{SEQ:4}`, unique per project, DRAFT → ISSUED.
- **Issued letters are immutable.** You cannot add a document to a letter that
  has already gone out; you send a second letter. Editing a sent letter is
  forging the record.
- **Items point at a `revision_id`, never a `document_id`**, and snapshot
  `number / revision / state / suitability` *as at issue*. The revision moves
  on afterwards; the letter must still read as it did on the day.
- **WIP cannot be transmitted** — WIP has not been shared, so it cannot be
  formally issued.
- **Acknowledgement is recipient-only, self-only, and idempotent** — a second
  click never overwrites the first timestamp, because that timestamp is the
  entire evidentiary value of the row.
- **Overdue is derived, never stored.** A stored flag is wrong the moment the
  clock passes it and nobody ran the job.
- **ZIP export**: entries `{number}_Rev{rev}_{sanitised}`, plus `MANIFEST.csv`
  (SHA-256 per file) and a bilingual `TRANSMITTAL.txt` cover sheet.

> **`MANIFEST.csv` is written with a UTF-8 BOM.** Without it, Excel on a Persian
> Windows install renders every Farsi filename as mojibake, and the manifest is
> the one artifact a claims consultant actually opens.

> **RLS is sender ∪ recipients ∪ employer — and only once ISSUED.** The first
> cut gated recipiency alone, so an addressee (and the employer) could read a
> letter the sender had not yet sent, while the sender was still changing the
> distribution list. A draft is not correspondence. Caught by probing the model
> directly rather than trusting the index page, which had looked correct
> because the seeded recipient was also the employer.

> **A policy on a child table MUST NOT select from its parent.** Gating
> `transmittal_recipients` on `transmittals.issued_at` deadlocked the pair —
> the parent's own policy reads the child — and PostgreSQL aborts with
> `infinite recursion detected in policy`. `issued_at` is therefore
> **denormalised onto both child tables by trigger** (`AFTER UPDATE` on the
> parent to propagate, `BEFORE INSERT` on each child to inherit). The trigger,
> not the service, owns the guarantee, so an importer or a DBA cannot bypass it.

> **A third party sees nothing.** A contractor must not read a rival's
> correspondence on the same project — verified live: EPC-SAHAND, a full
> participant, sees an empty register and gets a 404 on the ZIP.

## 5e. QA/QC — punch list & Mechanical Completion (FR-COM-04/05)

**Category letters are A / B / C in BOTH languages** (user decision,
2026-09-11). The punch category is a contractual term that appears in the same
form on drawings, in the MC procedure and in correspondence with vendors who do
not read Persian, so الف/ب/ج invented a second vocabulary for one thing. Only
the explanatory prose after the dash is translated
(`A — مانع تکمیل مکانیکی`). This also covers the gate message and the MC
certificate PDF. Enforced by `tests/Feature/PunchTableTest.php`.

`PunchCategory`, `PunchState`, `Subsystem`, `PunchItem`, `McCertificate`,
`PunchListService`, `McCertificateService`, `PunchController`,
`punch/index.blade.php`. Completes **acceptance journey 5**.

**The one rule the module exists for:**

> An MC certificate cannot be issued while a category-A punch item is open,
> *unless* an Owner-approved exception is recorded.

Both halves matter. A gate with no legitimate override gets bypassed outside
the system, which is worse than no gate — so the override exists, but it is
**employer-only**, needs a **written reason**, and stores a **`jsonb` snapshot
of the items open at that moment**. The snapshot is evidence: re-deriving it
later would show today's open items, not the ones the owner actually accepted.
`test_the_exception_snapshot_does_not_drift_when_items_close_later` pins that.

**Where the rules live**

| Rule | Home | Why there |
|---|---|---|
| Which category blocks which milestone | `PunchCategory::blocksMechanicalCompletion()` / `blocksReadyForStartUp()` | A client renaming a category is a different edit from a client changing a rule, so labels are separate from the gate |
| Legal lifecycle moves | `PunchState::canTransition()` | One table, not scattered `if`s |
| Who may do what | `PunchListService` | The API, an importer and a mobile client must not each invent their own version |
| The gate itself | `McCertificateService::issue()` | Single choke point |

`RAISED → ASSIGNED → FIXED → {VERIFIED | REJECTED}`, `REJECTED → ASSIGNED`,
`VERIFIED → CLOSED`. **`isOpen()` is true for everything except `CLOSED`** —
`VERIFIED` deliberately still blocks, because verified means the inspector
agrees, while closed means the owner accepted it into the record.

Role rules, from the SRS §4.1 matrix row "Punch item (A)": MC/TPI/PMC/OWN raise,
the responsible contractor fixes, **anyone but the responsible company** verifies,
**only the employer closes** and **only the employer grants exceptions**.

**Traps this cost:**

- **The fixture had no management contractor.** Journey 5 needs a verifier that
  is neither the contractor nor the owner, so `MC-KARAN` (`tenant_type = MC`,
  user `jafari@karan.example`) was added. `SeedPathTest` asserts 8 companies now.
  **Note the meaning of MC — see §5h; it is not "Mechanical Completion".**
- **`consultant` had no `punch.*`**, so the PMC got a 403 on a register the SRS
  says it may verify. Capability decides *reach*; the service decides *actions*.
- **State must be set explicitly on create.** A DB default is not reflected back
  into the model, so `isOpen()` would read `NULL` on a freshly created row.
- The seeded subsystem is **deliberately blocked** (2 open A-items). A demo that
  opens on a green screen never shows the feature that was sold.


### A certificate must not rewrite its own evidence

An issued MC certificate is a statement about a **moment**: what was accepted,
on what basis, by whom. Anything on it that is recomputed at render time is not
evidence — it is a live report wearing a certificate's letterhead.

The PDF was computing "total / closed / open category-A" from the punch list on
every download. Those three numbers are the technical justification for the
handover. Found by downloading the same certificate twice:

| | at issue | a few closures later |
|---|---|---|
| Closed | 2 | **3** |
| Open — category A | 2 | **1** |

Same number, same URL, same ISSUED certificate, different facts. And the drift
always runs in the **flattering direction**: a certificate issued over two open
A-items quietly repairs itself into one that looks nearly clean, erasing
exactly what an auditor or an arbitrator would be looking for. The waived-items
list beside it was already correct, because `exception_items` is a stored jsonb
snapshot — so the page was half snapshot, half live query, and the half that
drifted was the summary.

Fixed with `mc_certificates.punch_summary` (jsonb, nullable), written by
`McCertificateService::issue()` and read through
`McCertificate::punchSummary($items, $openBlocking)`. **Nullable and
state-dependent on purpose:** a DRAFT keeps showing live numbers, because a
draft's job is to tell whoever is deciding whether to issue where the subsystem
stands *right now*. Issuing is what freezes it.

The subsystem register on `/projects/{p}/punch` still shows live closure
percentages, and that is correct — it is a working view of the site, not a
record of an event. **The rule is not "snapshot everything", it is "snapshot
what a document asserts about a moment".**

`punchSummary()`'s `isIssued()` check is currently unreachable through the UI
(nothing writes a snapshot before issue), so removing it changes no page and no
end-to-end test — mutation **M108** survived until the contract was asserted on
the model directly. It is kept rather than deleted because it is protecting a
real future mistake: the day anything writes a snapshot earlier, a draft would
silently start reporting a stale position to the person deciding whether to
issue.

## 5f. PDF export (headless Chromium)

`PdfRenderer`, `resources/views/print/{layout,mc-certificate,transmittal}.blade.php`,
`PunchController::certificatePdf()`, `TransmittalController::sheet()`.
**This retires the PDF half of risk IR-R2**, which the ADRs had flagged as the
highest-risk item in the stack and required a P0 spike for.

**Why a browser and not a PHP library.** Persian is a joining script: every
letter has up to four contextual forms, ZWNJ is semantic (`پیش‌نویس` is not
`پیشنویس`), and Latin document numbers sit inside RTL sentences under the bidi
algorithm. HarfBuzz gets this right. PHP PDF libraries mostly do not, and when
they fail they fail **silently** — a page that looks plausible to a
non-Persian reader and is gibberish to the client. mPDF's own maintainers point
users at headless Chrome for this reason.

**Verified, not assumed.** Rendered PDFs were rasterised and read: joining
forms, ZWNJ, bidi-embedded Latin tags, Persian digits and Jalali dates are all
correct in both languages. `pdffonts` confirms Vazirmatn is **embedded and
subsetted**, not substituted. ~1.05 s per document.

**Dependencies** (`restore.sh` installs all three): `chromium`,
**`fonts-vazirmatn`** — without it PDFs still generate, as boxes — and
`poppler-utils` for the tests. Chromium is **~315 MB and must go in the
air-gap bundle**; it runs with networking disabled.

**Design decisions worth keeping:**

| Decision | Reason |
|---|---|
| `PdfRenderer` takes finished HTML and knows nothing about certificates | Documents stay ordinary Blade views that can be previewed in a browser |
| Validates `%PDF-` magic bytes **and** a size floor | Chrome can exit 0 having written a truncated file; serving that as a signed certificate is worse than an error |
| Recursive scratch cleanup | Chrome treats the scratch `HOME` as real and mints `.cache`/`.config`/`.local`; plain `rmdir()` fails silently and leaks |
| Binary list is constructor-injectable | Lets an operator point at a custom Chrome, and lets tests simulate a host without one |
| **ZIP degrades, `/sheet.pdf` does not** | The ZIP's job is delivering drawings, so a missing renderer only drops the cover page. On the standalone endpoint the PDF *is* the deliverable, so it 503s |

**Traps this cost:**

- **`position: fixed` footers do not pin per page in Chrome's print path.** The
  footer landed across the top of page 2, over the table header. Footers now
  flow as normal content at the end of the document.
- **Chrome creates files inside the scratch `HOME`**, so cleanup must recurse.
- **`pdftotext` inserts bidi control characters** (U+202A–U+202E, U+200E/F)
  around RTL runs. They are invisible and they break naive substring matching,
  so the test helper strips them before asserting. A raw `assertStringContains`
  on Persian will fail for the wrong reason.
- The print views are exempt from the `data-tt` sweep test — a PDF has no
  JavaScript and no user to click a filter. The exemption is scoped to
  `views/print/` and was mutation-tested to confirm it still catches screens.


### Shell contract (read before adding a screen)

Every screen that shows the sidebar MUST nest exactly like this:

```blade
<body>
<div class="app">          {{-- flex row --}}
@include('partials.sidebar')   {{-- must be INSIDE .app --}}
<div class="wrap">         {{-- content column, flex:1 --}}
@include('partials.topbar')    {{-- must be INSIDE .wrap --}}
  ...page...
</div>
</div>
</body>
```

Two failure modes, both reported by the user on 2026-09-11:

- **Sidebar outside `.app`** — it stops being a flex sibling, so the content
  column starts at x=0 and slides underneath it.
- **Topbar as a direct child of `.app`** — it becomes a THIRD column next to
  the sidebar instead of a bar above the content, and the collapse button
  disappears from where the user expects it.

The toggle (`id="sb-burger"`) lives in the topbar, deliberately outside the
sidebar: a control that collapses the sidebar must survive the collapse, and on
mobile the sidebar is off-canvas entirely. `ShellStructureTest` enforces all of
this per screen. The view composer supplies `$user`, `$acting`, `$nav`, `$plan`
and `$langSwitchUrl` as **defaults**, so a new screen only needs the markup
above plus its view name in `ViewServiceProvider::SHELL_VIEWS`.


## 5g. Vendor transmittals — the second stream (FR-ENG-05b)

Two letters share a shape but are not the same thing:

| | Counterparty | Meaning |
|---|---|---|
| `CONTRACTOR` — `{PROJECT}-TR-{SEQ:4}` | EPC → Employer / Consultant | the contractual submission; starts a review clock, evidence in a delay claim |
| `VENDOR` — `{PROJECT}-VTR-{SEQ:4}` | Vendor → EPC | supply-chain correspondence; the Employer is not a party |

**User decisions (2026-09-11), all four confirmed before building:**
1. **Vendors do not log in.** The EPC's document controller records what the
   vendor sent. So `company_id` (the tenant column and RLS anchor) stays the
   EPC, and `vendor_company_id` names the originator. Conflating the two would
   hand a vendor tenant the contractor's whole register the day vendors get
   logins.
2. **Separate number series AND a separate screen** — two independent counters
   scoped by `kind`, and `/projects/{code}/vendor-transmittals`.
3. **Roll-up must be traceable.** `transmittal_items.source_item_id` is a
   nullable self-reference, so `vendor letter → EPC letter → client` survives.
4. Document type: existing `{TYPE}` codes are enough for now.

**Where the rules live:** `TransmittalService::draft()` (validation +
per-stream numbering), `TransmittalService::rollUp()`, two CHECK constraints
(`kind` is known; vendor fields agree with `kind`), and the RLS policy.

**The non-obvious part — separation must hold in the DATA, not just the UI.**
The Employer's original blanket clause ("owner sees every issued letter in the
project") silently exposed vendor letters. A filter on screen with a leak
underneath is not separation, so the employer catch-all is now
`kind = 'CONTRACTOR'` only. The Employer still sees a vendor letter when
**named as a recipient** (an explicit act of sharing) and always sees vendor
documents **rolled up** into a contractor submission. Migration
`2026_09_11_130000_...`; all three properties are pinned by tests.

**Traps:**
- `rollUp()` copies the snapshot from the **source item**, never re-reading the
  document. What the EPC forwards is what the vendor actually sent; if the
  vendor has since issued Rev C that is a different letter, not a silent
  upgrade.
- Roll-up is **idempotent** — rolling the same letter up twice adds nothing.
- Only an **issued** vendor letter can be rolled up, and only into a **draft
  contractor** letter.
- A company **cannot be its own vendor**, or a contractor could file its own
  submissions in the vendor stream and drop them from the client-facing count.

## 5h. "MC" means two different things — read this before using it

**This was wrong in the codebase for months and no test could see it.**

| Abbreviation | Meaning | Kind of thing | Where it lives in code |
|---|---|---|---|
| MC | **مدیریت پیمان** — Management Contractor | a **company type** | `App\Support\TenantType::MC`, `companies.tenant_type`, `MC-KARAN` |
| MC / MCC | **تکمیل مکانیکی** — Mechanical Completion | a **milestone** | `McCertificate`, `mc_certificates`, `PunchCategory::blocksMechanicalCompletion()`, `{PROJECT}-MCC-{SEQ}` |

A **management contractor** is a firm the *employer* engages to administer the
contract with the contractor on the employer's behalf: supervising execution,
checking progress and quality, verifying what the contractor claims, signing
off milestones. It is a standard Iranian contracting arrangement with its own
fee structure. **Mechanical Completion** is the point at which a subsystem is
built and ready for pre-commissioning. Same two letters, unrelated concepts.

The seeder, two Blade views, the SRS role table and the coverage map all
described the `MC` *tenant type* as "Mechanical Completion body". The company
was even named "Karan Completion Services / خدمات تکمیل کاران".

**Why nothing failed.** A tenant type only has to be a *matching string*. The
role rules (`PunchListService::VERIFIER_TYPES` / `RAISER_TYPES`) were correct —
an employer's contract administrator *should* raise and verify punch items —
so every behavioural test passed. The label is decoration to the code and
load-bearing to the user. Only a human reading the screen could catch it, and
that is exactly what happened.

**What now prevents a recurrence:**

- `App\Support\TenantType` — the nine SRS types in one place, with bilingual
  labels and a long comment on the MC collision. The codes were previously
  loose strings in five files.
- Migration `2026_09_11_140000_constrain_tenant_type.php` — a CHECK constraint
  so an importer or a `psql` session cannot invent a tenth type that the role
  rules would then silently refuse to recognise.
- `tests/Feature/TenantTypeTest.php` (7 tests) — asserts the words, not just
  the behaviour: that MC's label is «مدیریت پیمان», that the string
  "mechanical" does **not** appear in it, that the two contract-role views
  agree, that every type has labels in both languages, and that the CHECK is
  neither too loose nor too tight. Mutations M75–M77 confirm all three bite.

**The lesson worth keeping:** behavioural tests cannot see a wrong name. If a
term is contractual vocabulary the customer will read on screen, pin the term
itself in a test.

## 5i. The Filament admin panel (§9 item 1)

`/admin`, provider `app/Providers/Filament/AdminPanelProvider.php`.
Currently five resources: **Document**, **PunchItem**, **Transmittal**,
**User**, **Subsystem** (9 admin routes). Filament v4 + Livewire, discovered
from `app/Filament/`.

**TENANCY IS THE LOAD-BEARING PART.** Filament builds its OWN middleware stack
in the panel provider; the generated one knows nothing about `BindTenant`. By
standing rule 1, `SET LOCAL` outside a transaction is silently discarded, so a
panel without it does not merely lose isolation — it never establishes it.

What that actually looked like, measured rather than assumed:

| | with no `BindTenant` | with `BindTenant` |
|---|---|---|
| sees another tenant's rows | no | no |
| sees its OWN rows | **no** | yes |

RLS was failing **closed**, so "does not leak across tenants" passed while the
panel was simply broken and empty. **Only the mirror test told them apart.**
Never assert isolation without also asserting visibility — a screen that shows
nothing passes every leak test ever written.

**Four things the stock scaffold got wrong for this product:**

1. **403 for everyone.** Filament refuses panel access outside `local` unless
   `User` implements `FilamentUser`. Added `canAccessPanel()` returning
   `is_active` — mutation **M79** proved the first version was untested: a
   *deactivated* account could open the admin panel and the whole suite stayed
   green. That is the finding an on-prem security review leads with.
2. **Google Fonts.** `->font('Vazirmatn')` fetches from bunny.net/Google by
   default — a hard **AG-01…AG-06 violation**. In a customer datacentre it does
   not degrade, it hangs. Fixed with
   `provider: \Filament\FontProviders\LocalFontProvider::class`, which emits
   nothing and lets the OS-installed `fonts-vazirmatn` win — the same font the
   PDF renderer uses. Pinned by a test that greps the HTML for five CDN hosts.
3. **English, LTR, UUID-first.** `--generate` emits a column per DB column: it
   led with three UUIDs and showed both title columns at once. A document
   controller identifies a document by its **number**. The UUID column is
   *deleted*, not toggled off — a hidden column is still one somebody can turn
   on and paste into an email.
4. **A bulk DELETE button.** On a controlled register. Revisions supersede and
   states archive; that is the whole point of ISO 19650. Removed.

**A bug tests could not see.** The state column was bound to
`currentRevision.state`; the attribute is `cde_state`. Filament resolved null
and rendered an **empty cell on every row** — page still 200, tenancy tests
still green, and the register silently lost the field that says whether a
drawing may be built from. Found by *looking at a screenshot*. Any column whose
value arrives through a relation now gets a content assertion, because a
mistyped attribute fails silently rather than loudly (mutation **M81**).

**Locale.** `users.locale` existed and was populated but nothing read it; the
hand-written screens use `?lang=`. New `SetUserLocale` middleware applies the
user's own language (Persian default, `?lang=` still wins) because Filament
resolves labels through the translator at render time, far from any view.
Panel strings live in `lang/{fa,en}/epcc.php` — the first translation files in
the project.

**What an admin resource may DO is the second load-bearing decision.**
`--generate` gives full CRUD on every model. For this product that is wrong in
a specific way, and differently wrong per resource:

| Resource | create | edit | delete | why |
|---|---|---|---|---|
| Document | no | titles only | **no** | number/sequence/company are derived by `DocumentRegister`; the register archives rather than deletes (ISO 19650) |
| PunchItem | no | **no** | no | `state` is a position in a workflow with rules about *who* may move it, not a column |
| Transmittal | no | no | no | an ISSUED transmittal is evidence of what was sent on a date; items snapshot the revision as sent, and the per-stream counter is allocated under a row lock |
| Subsystem | no | no | no | the breakdown arrives as a bulk import from system engineering, and `code` is the join key the whole handover chain hangs off — a hand-typed code that misses the P&ID is invisible to every document, punch item and certificate that references it; editing it after punch items exist orphans them |
| User | no | no | no | `is_active` IS gate 1 and is what `canAccessPanel()` reads — an editable toggle re-opens every door in the product without passing any of the six gates; `company_id` is the tenant; roles live on the per-project membership, not the user |

The generated document form offered `number`, `sequence`, `company_id` and
`current_revision_id` as free inputs. Retyping a number is not an edit, it is a
collision with the `(project_id, number)` unique index; changing `company_id`
does not move a document, it makes it vanish from its author's register. The
form now edits the two genuinely free fields — the bilingual titles — and shows
the rest as read-only placeholders, because an admin still needs to *see* which
record they have.

The punch resource is stricter still: an admin form writing `state` would let
someone mark an item VERIFIED with nobody having verified it, on a register
whose entire purpose is proving somebody did — and `McCertificateService`
reads that column, so a forged state also unblocks a completion certificate.

**A grep is not an assertion.** The first version of the no-delete test
searched the source for `"DeleteAction"` and failed against the *code comment*
explaining why there is no DeleteAction. It would equally have missed a delete
introduced under another name. Rewritten to call `canDelete()` / `canCreate()`
/ `getPages()` — the resource's own authorization, which is what Filament
actually consults.

**One resource shows BOTH transmittal streams**, with `kind` as a filter.
The hand-written UI deliberately splits contractor (`-TR-`) from vendor
(`-VTR-`): a document controller working the contractual log does not want
supply-chain letters in their numbering. The admin view answers the opposite
question — "what left this project, by any route?" — so splitting it would be
the wrong shape. The employer still sees no `VTR` rows here, and that is
enforced in PostgreSQL, not in the resource: mutation **M86** hard-filtered the
table to `CONTRACTOR` and the isolation test still passed, because RLS was
doing the work all along.

### The Jalali defect — why a green suite still shipped a wrong page

The transmittal list went out with Filament's `->dateTime()` and rendered
`سپتامبر 12, 2026` on a page that was otherwise entirely Persian. HTTP 200,
every test green. **It was found by looking at a screenshot.**

On a submission log that is not cosmetic: the date decides whether a reply
landed inside the contractual 14-day window.

The root cause was not the panel. Jalali formatting only ever existed as a
private closure inside each Blade view — `$jdate = function ($d) use ($fa)`,
copy-pasted per screen. They agreed by luck, and a surface written months later
could reuse none of them, so it reached for the framework default.

Fixed by extracting **`app/Support/JalaliDate.php`** (`format`,
`formatWithTime`, `digits`) and routing every date and number through it —
all three panel tables and all ten Blade views; there is no `Morilog` call left
in `resources/views`. It takes `$lang` as an argument rather than reading the
app locale, because the PDF renderer formats a document in a language chosen
per render, not per request.

`DateFormattingTest` pins this **structurally**, not just by output: one test
fails if any view calls `Morilog` directly, another if any Filament file calls
`->date()`/`->dateTime()` outside a comment. Mutations **M87–M89** confirm all
three bite. A behavioural test alone would not have caught the re-introduction
of a fourth private closure.

**Scope.** This is an ADMIN view, not a replacement for
`/projects/{code}/documents`. That screen enforces the workflow (numbering via
`DocumentRegister`, transitions via `CdeState`, gating via `ReviewCycle`). This
one is for what the workflow does not cover: fixing a typo, looking a record up
across projects, inspecting what is stored. Both sit under the same RLS.

### 5i-2. Identity tables: the leak the Users resource exposed

**Building the Users resource is what found it.** `users`, `companies` and
`project_memberships` were never in the original RLS migration's
`TENANT_TABLES`. Isolation for users was a `->where('company_id', ...)` in one
controller — precisely the "forgotten where clause" that migration's own
comment says RLS exists to backstop. Measured, bound as EPC-PARS:

```
documents visible: 5      <- correctly narrowed
users visible:     9      <- every user of all eight companies
```

Nothing had exposed it because no screen listed users. A naive Filament table
would have published the client's representatives, the PMC's inspectors and two
rival EPCs' engineers — names and addresses — to every tenant.

Fixed in `2026_09_12_100000_secure_identity_tables`: RLS on `users` and
`project_memberships`, with three USING clauses — own company, the identity
hatch, and **counterparties on a shared project** (a document says "reviewed
by", a transmittal "acknowledged by"; hide those rows and working pages render
blank names).

**`companies` is deliberately NOT covered.** The participant list is on the
contract and every party already knows it; covering it would need a second
bypass for a row whose leak is "the contractor learns the consultant's name".
Recorded so the next person does not assume it was missed.

Three traps paid for here:

1. **Policy recursion.** The tight rule — "users holding a membership on a
   project my company is on" — makes `users` read `project_memberships` while
   that table's policy reads `users`. PostgreSQL rejects it outright:
   *infinite recursion detected in policy for relation "users"*. The clause was
   re-expressed through `project_company` (no RLS of its own), which is
   slightly wider — employees of a participating company who are not on the
   team — and that is the accepted trade.
2. **`NULLIF` on every GUC read** (rule 3). `current_setting(...,true)` returns
   `''`, not NULL, once a GUC is set and cleared in the same transaction, and
   `''::uuid` is a hard error. The first draft reproduced the bug this project
   already fixed once in `2026_09_09_100200`.
3. **Login happens before a tenant exists.** Authentication must read a user
   row to learn which company to bind, so `epcc.company_id` is necessarily
   unset — and unset means "see nothing". Without an escape hatch RLS does not
   leak; it locks everyone out. Hence `TenantContext::forIdentityLookup()`.

**The hatch is a bypass and is documented as one.** Mitigations: one greppable
name, transaction-scoped like every other GUC, cleared in a `finally`, and
**snapshot-restored rather than force-cleared** — a nested call that cleared
the GUC on exit silently shut the hatch its caller still needed, and the
symptom was not an error but a write that matched no rows, leaving the
"disabled" fixture account enabled.

### The bug that a green suite could not see

After the policy landed, **every `/admin` route redirected to the login screen
with a perfectly valid session.** Filament's `Authenticate` resolves the user
before `BindTenant` binds a tenant, so the guard got NULL and treated the admin
as a guest.

All 21 panel tests stayed green, because **`actingAs()` injects a User object
straight into the guard and never asks the user provider for it**. Found by
taking a screenshot — the third defect on this project found that way rather
than by a test.

Fixed at the *provider*, not in a middleware: `TenantAwareUserProvider` wraps
`retrieveById` / `retrieveByToken` / `retrieveByCredentials` /
`updateRememberToken` in the hatch, so the session guard, the panel,
`Auth::attempt` and remember-me are all covered by one greppable class.

**And the first test written for it was also inert.** `phpunit.xml` sets
`SESSION_DRIVER=array`, so a session does not survive between test requests and
Laravel keeps the already-resolved user in memory — `retrieveById()` is never
called. Mutation M95 deleted the provider override outright and the HTTP test
stayed green. The assertion that bites calls the provider directly, the way the
session guard does. **If a test exercises authentication, check it is not
riding on `actingAs()` or an in-memory session.**

### 5i-3. The subsystem register — deriving a gate, and a panel-wide digit defect

The MC gate is **derived, never stored**. `subsystems` has no state column: a
subsystem is certifiable when it has no open category-A punch item, which is a
question about *other* rows and changes the moment somebody closes the last
one. A boolean written at walkdown time is wrong by the afternoon, and "can
this be handed over" is precisely the question nobody should answer from a
stale column.

**Three rules the table must not restate in its own words.** `PunchCategory`
owns "A blocks MC", `PunchState` owns "open means anything but CLOSED", and
`McCertificate` owns DRAFT/ISSUED. The table asks all three rather than
hardcoding `['A']` or a literal state list — otherwise a future change to the
rule leaves this screen quietly asserting the old one, on the screen where
somebody decides whether to hand over a unit.

**Counts are computed by the database.** `Subsystem::openBlockingItems()` and
`closurePercent()` walk the loaded `punchItems` collection: correct for one
record on a detail page, quadratic on a register. The table uses `withCount`
with the same predicates, and a tinker probe asserts the SQL and the model
agree row by row — if the two ever disagree, one of them is wrong and it is
worth knowing which before shipping.

**`closurePercent()` returns 100 for an empty punch list.** Arithmetically
fine, dangerous on a handover register: "100%" beside a subsystem nobody has
walked down reads as finished when it means the opposite. The column renders
**"not walked down"** in words for that case.

**A one-row fixture proves nothing** (recorded before, re-proved here). With a
single subsystem a working badge and a hardcoded one render identically. The
fixture now carries five, each existing to kill a specific mutation:

| code | state it pins |
|---|---|
| PH-14-SS-2201 | 2 open A-items, DRAFT certificate — the blocked case |
| PH-14-SS-3301 | items raised and all CLOSED — genuinely clear |
| PH-14-SS-4401 | zero items — never walked down |
| PH-14-SS-5501 | one A-item **VERIFIED, not CLOSED** — still blocked |
| PH-14-SS-6601 | one open **category-B** item — open, but not blocking |

SS-5501 and SS-6601 were added *because* mutations survived without them.
M96 (the table's own open-state list drops VERIFIED) and M99 (blocking widened
to A+B) were both undetectable by any test until a row existed that could tell
the rules apart. **When a mutation survives, ask whether the fixture can
express the difference at all before rewriting the assertion** — and note M96
survived a test that checked `PunchState::isOpen()` and the *model* helper.
Both were right; the *table* was wrong. Assert on the rendered row.

**Adding fixture rows broke three unrelated tests**, which hardcoded
`PH-14-PL-0006` and `assertSame(5, $count)`. Those literals tested the
fixture's size, not the behaviour claimed in the test name. They now derive the
expectation (`count() + 1`, and the MC body's view as the reference for "every
participant sees the same list"), so the next fixture change does not break
them again.

**The panel-wide defect the screenshot found — mixed digit systems.** Persian
pages rendered our columns in Persian digits (۵, ۴۰٪) and Filament's own chrome
in Latin: *"نمایش 1 به 5 از 5 نتیجه"*. Latin numerals inside a Persian sentence
are instantly visible to a reader and invisible to an assertion that only
checks the page loaded. It affected **all five resources**, not just the one
being built — it had been shipping since the first one.

Fixed once, at the translator: `App\Support\LocalisedNumberTranslator` extends
Laravel's `Translator` and converts numeric `:parameter` values when the locale
is `fa`, bound via `$this->app->extend('translator', …)` in
`AppServiceProvider::register()` (**register, not boot** — the container
resolves `translator` during boot). Per-table fixes were rejected: they leave
the next table free to reintroduce it and do nothing for strings inside the
Filament package.

Only **wholly-numeric** values convert. Converting the rendered line would turn
PH-14-SS-2201 into PH-۱۴-SS-۲۲۰۱, and tags are read off drawings and typed into
search boxes in Latin. That guard is currently **unobservable through the UI** —
no rendered string passes a digit-bearing non-numeric parameter, and a mutation
removing it leaves every page byte-identical. So it is pinned by a test against
the translator directly, not by an HTTP test that would pass either way. An
inert test is worse than none: it reports safety it never checked.

### The mobile blank page — and why only Persian had it

`/projects/PH-14/documents?lang=fa` at a 430px viewport rendered **one unique
colour**: the background. Not a slow load, not missing data — the HTML was 66KB
with every document in it. The same page in **English at the same width was
fine**, and that asymmetry was the whole clue.

A flex item defaults to `min-width:auto`, which means *never shrink below my
content's intrinsic width*. The documents table is ~1032px intrinsic; the phone
viewport was 485px. So `.wrap` grew **past** the viewport instead of
overflowing inside it. In LTR that clips harmlessly off the right edge and the
page still reads. In RTL the block is laid out from the right edge, so the same
overflow pushes the **entire document off-screen to the left** — a blank
rectangle.

Measured, before → after:

| page (fa, 430px) | before | after |
|---|---|---|
| documents | **1** | 2760 |
| transmittals | 206 | 2632 |
| project / team / punch | 2562 / 3217 / 1087 | 2953 / 3219 / 3321 |

Fixed in the shell, not per view: `min-width:0` on `.app > .wrap`, plus
`overflow-x:auto` inside the existing mobile media block. **Both halves are
needed.** `min-width:0` alone contains the overflow but then clips it, and the
columns past the fold on a document register are the state and the revision —
the two fields somebody opens that register to read.

Three candidate fixes all restored the page (`table-layout:fixed`,
`min-width:0`, `display:block` on the table). The containment fix was chosen
because it repairs the layout bug itself rather than restyling every table, and
it leaves the `<table>` semantics the jsdom harnesses depend on intact.

**Why no test caught it.** The suite asserts status codes and the presence of
strings in HTML. Every one of those assertions passed on a page that painted
nothing — the markup was perfect and unreachable. `ShellStructureTest` now
pins both CSS rules *and* checks that the pages carrying wide tables really do
render through the shell, so a page that stopped using it could not keep
passing on the CSS assertion alone (M110–M111).

**This is the fifth defect found by looking rather than testing**, and the
first found by looking at a phone width. Rendering at desktop only is a blind
spot for an Iran-first product where site staff are on phones.

### 5j. AG-06 — the air-gap pre-flight, and what static scanning cannot see

`php artisan verify:airgap` is the command the customer runs at acceptance. It
scans `public/` and `resources/` for anything a browser would fetch from the
internet and grades each finding:

| severity | meaning | exit |
|---|---|---|
| **BLOCKER** | fetched while rendering: `src=`, a stylesheet `href`, `@import`, `url()`, or a JS loader that assigns `.src`/`.href` | **1** |
| **LATENT** | present in a shipped file on a path we do not enable | 0 (1 under `--strict`) |
| **BENIGN** | comment, licence, XML namespace | not reported |

The severity split is the whole product. A bare grep for `https://` over a
vendor tree returns dozens of hits, all noise until classified, and a command
that fails on things the customer cannot act on is a command the customer
learns to ignore. Default is BLOCKER: an unknown external reference is guilty
until somebody writes down why it is not. `--json` feeds the acceptance report;
`--strict` is what CI runs.

**Current findings: 0 blockers, 3 latent** — Pusher's CDN inside Filament's
`echo.js` (served `->core()` on every panel page but inert, because Echo is
never initialised with `BROADCAST_CONNECTION=log`) and Font Awesome inside
EasyMDE (`autoDownloadFontAwesome` defaults to true; we register no
MarkdownEditor field). If anyone adds one, pass
`autoDownloadFontAwesome => false` or it becomes a blocker on site.

**Two things the static scan missed, and the general lesson.**

First, Pusher. `echo.js` stores `cdn_https:"https://js.pusher.com"` and
concatenates the script URL at call time, so no literal `src="https://..."`
exists anywhere in the file. The first version of this command gave it a clean
bill of health. Dynamic script loaders are the highest-risk shape precisely
because they fetch lazily, so a pattern for CDN bases held in config
properties was added.

Second — and this one is the real lesson — **`ui-avatars.com`**. Filament's
default avatar provider builds the URL in PHP from the user's name, so it
appears in no shipped file at all; it was rendered into the user menu of every
admin page. Found by capturing a real browser's net-log against `/admin`, not
by reading code. Two defects in one: an image that cannot be fetched in a
customer datacentre, and a named employee posted to a third party — for a NOC
affiliate's staff list that is a confidentiality problem quite apart from the
air gap. Fixed with `App\Support\LocalAvatarProvider`, which draws initials as
an inline SVG data URI (deterministic colour per name, Vazirmatn for Persian
initials, zero requests).

> **A file scanner can only find URLs that exist as text on disk.** Anything
> assembled at runtime is invisible to it. That is why
> `AirgapVerificationTest` asserts against the *rendered HTML* of every panel
> screen as well as against the command — rendered output is the only place
> runtime-assembled URLs become visible. Note it filters to fetching
> attributes only: an `<a href>` to an external site is navigation and costs
> nothing until clicked, and flagging those would bury the real findings.

`FilamentInfoWidget` was also dropped: it put the framework's branding and
exact version number on the customer's dashboard, which is both wrong-branded
and a free CVE list for an attacker.

**This is the ninth defect found by looking rather than testing, and the third
in the air-gap family** (after `->font()` and the preview iframe). The pattern
is consistent: everything that reaches outward is invisible in a sandbox with
working internet. The only reliable detector is to watch the network, or to
cut it.

### 5k. Procurement and materials — تهیه، بازرسی، انبار، سایت

The second C in EPCC. Five documents covering the chain the user named:

| Persian | document | prefix |
|---|---|---|
| تهیه | requisition → PO | `-MR-`, `-PO-` |
| بازرسی | inspection record | `-IRN-` |
| ورود به انبار | goods receipt | `-GRN-` |
| خروج از انبار | material issue | `-MIV-` |
| ورود به سایت | site delivery | `-SDN-` |

**One movement table, not four.** GRN/MIV/SDN/MRN are four pieces of paper but
the same fact: a quantity of one lot moved somewhere at a time and somebody
signed. Four tables would mean four sequence allocators, four RLS policies and
a balance that UNIONs four shapes. The ledger is **append-only** — a wrong
receipt is corrected with a reversing movement, never an UPDATE, because a
warehouse's only real asset is being able to say what it held on the day of an
audit.

**The sign convention lives in `MovementType::sign()`, not in the data.** A
stored signed quantity means every reader must know the convention, and
somebody eventually inserts a negative receipt. Note `SITE => 0`: the material
left the warehouse at ISSUE, and counting it again would double-deduct. That
mutation (M114) kills three tests.

**ISSUE and SITE are two steps on purpose.** ISSUE is a stock question; SITE is
a completion question. Material can sit on a laydown area for weeks between
them, and collapsing them hides it exactly when somebody is looking for it.
`test_issued_but_not_yet_on_site_is_still_a_shortage` pins this.

**Lots exist because the user asked for heat-number traceability.** Stock is
not "300 m of pipe" but "120 m of heat A-4471". The MTC is a **document in the
CDE**, not a file path, so it inherits revisions, transmittals and access
control — a material certificate outside the register is one nobody can find
at handover. `isFullyTraceable()` requires both an identifier *and* the
certificate: a heat number with no MTC behind it is not traceability.

**Inspection is ADVISORY — the user's explicit choice.** A failed or missing
inspection records a warning and lets the receipt through. That matches how
sites run (material lands at 2am, the TPI signs on Sunday) and a system that
refuses the receipt gets bypassed with a paper note nobody types in. The price
is that "received but not passed" must be loud, so `inspection_result` is
denormalised onto the lot and `uninspectedLots()` drives a panel on the screen.
**Advisory applies to the verdict, not to who may give it** — a contractor
still cannot inspect its own material, or the record looks like assurance while
carrying none. NULL is treated as needing attention: not inspected is not the
same as passed.

**Two separate SoD rules, two different messages.** The raiser may not approve
their own MR; and the technical and commercial approvals may not be signed by
the same person. They are different failures and a buyer needs to know which
one they hit. Mirrors `cert.approve` XOR `cert.submit`.

**Expediting keeps the promise and the forecast in separate columns.** A system
that lets the forecast overwrite the promise makes every order look on time.

**RLS is commercial here, unlike the punch list.** A punch list is shared — the
whole project walks down together. Procurement is not:

- a **vendor** sees the PO addressed to it and nothing else (vendors on one
  project are frequently competitors and a PO carries prices)
- **requisitions** are the buyer's planning: employer/PMC/MC read them, vendors
  never do
- **stock and movements** are operational, so every participant reads them —
  the employer is entitled to know whether the valve is on site, which is the
  weekly spreadsheet this module exists to kill

**The MC link (user asked for it).** `subsystemMaterialStatus()` compares
ordered against *delivered to site*, computed live, never stored — same rule as
`closurePercent()`. A subsystem that buys nothing reports complete rather than
short, the same call as closure returning 100 for an empty punch list.

Module key is `proc`, in the `full` and `special` plans. Screens:
`/projects/{code}/procurement` and `/projects/{code}/materials`.

**UML (`docs/uml/`).** Ten PlantUML sources plus a single self-contained viewer,
`docs/uml/uml.html` — inline SVG, **zero external references**, so it obeys the
same air-gap rule as `tour/tour.html` and opens from a file:// path on a machine
with no network:

| file | what it answers |
|---|---|
| `01-domain-model.puml` | the 7 tables, their multiplicities, and *why* lots and the ledger are shaped that way |
| `02-state-machines.puml` | MR, PO, and the physical custody of a lot side by side |
| `03-sequence-receive-to-site.puml` | one heat from inspection to the MC gate, including the lock and the derived PO state |
| `04-reversal-and-rls.puml` | correcting a booking without erasing it; what a vendor sees (0 MRs / 3 POs / 3 lots) |
| `05-component-view.puml` | layering, and the rule that value objects depend on nothing |
| `06-breakdown.puml` | **AS BUILT** — ADR-004 facility / area / subsystem + the composite FK |
| `07-proposed-ingestion.puml` | **PROPOSED, not built** — ADR-005 engineering data ingestion |
| `08-itp-domain.puml` | ADR-015 — the five ITP tables, the library/project-copy split, and the party grid |
| `09-itp-state-machines.puml` | the plan (no APPROVED) and one record; **WAIVED reachable only from NOTIFIED, only at a W point** |
| `10-itp-hold-point-gate.puml` | receipt is counted, release is governed — the 2am truck, the refused waiver, the lock |

Regenerate with `plantuml -tsvg docs/uml/*.puml` then re-run the inliner
(the HTML embeds the SVGs; editing a `.puml` alone does **not** update it).
`plantuml` and `graphviz` are **absent after a sandbox reset**: `apt-get install
-y graphviz` and fetch `plantuml.jar` from the GitHub release. Without `dot`,
PlantUML still emits sequence diagrams but fails class and state ones with
`Cannot run program "/opt/local/bin/dot"` — and installing graphviz this session
removed `chromium`, so screenshot and diagram tooling do not currently coexist.
Diagram text is **English** per the standing documentation rule, while the
column and label names are quoted verbatim from the schema — the diagrams were
written against a live `information_schema` dump rather than from memory, so
they are safe to trust when the code and this file disagree.

**Note for whoever installs the toolchain:** `graphviz` and `plantuml` are *not*
in the base image and are wiped by a sandbox reset. `sudo apt-get install -y
graphviz plantuml` restores them (`apt-get` without `sudo` fails on the dpkg
lock — the user is uid 1000 but has passwordless sudo).

### 5l. Physical breakdown — facility / area / subsystem (ADR-004)

**`project → facility → (optional) area → SYSTEM → subsystem`** (five levels since
**ADR-004 rev 2**, 2026-09-18 — see §5w). Decision record and the full
as-built table: `docs/ADR-004-Physical-Breakdown-Plant-Pipeline-Subsystem.md`;
diagram `docs/uml/06-breakdown.puml`.

**What a facility is.** One table, `facilities`, with `kind ∈ FacilityKind` =
PLANT | PIPELINE | OFFSITE | MARINE | UTILITY. Not sibling `plants` and
`pipelines` tables: what they share is being a *commissioning parent*; what
differs is *spatial addressing*, and that lives in a per-kind validated
`addressing jsonb`. Sibling tables would push every upward query into a
two-branch `COALESCE` plus an XOR CHECK — the classic shape of a missing
abstraction. Same pattern already proved twice here: `McCertificate.state` and
`MovementType`.

**`FacilityKind::all()` vs `selectable()` — the distinction matters.** `all()`
drives the CHECK constraint, so a PIPELINE row is storable and importable.
`selectable()` omits PIPELINE, so nobody can create one through the UI while KP
stationing is undesigned. Storable-but-not-offerable is the honest state; a
CHECK that rejected PIPELINE would have to be migrated later, and one that
offered it would promise reporting that does not exist.

**Areas are optional, on purpose.** Plant 3D's own refinery example has 13
areas, so omitting the level would break lossless import; but an Iranian booster
station with 12 subsystems should not have to invent 12 areas. Hence
`subsystems.area_id` is nullable and the fixture is **deliberately uneven** —
`PH-14-PL-01` has areas `AR-22`/`AR-33` *and* `SS-5501` hanging directly off the
facility, while `PH-14-PL-02` has no areas at all. A fixture where every branch
looks the same cannot fail informatively. Same rule as `closurePercent()`
returning 100 for an empty punch list: **absence of structure is not an error.**

**Numbering is per facility.** `UNIQUE(project_id, code)` → `UNIQUE(facility_id,
code)`. Real projects renumber per plant, and forcing project-wide uniqueness
across a 4,000-subsystem job invents collisions that do not exist on site.
Consequence to remember: **the bare code no longer identifies a subsystem.** The
admin register shows Facility as a *non-toggleable* column for that reason, and
`Subsystem::fullCode()` composes `"{facility} / {code}"` at read time. It is not
a stored column — a composed code in a column drifts the moment a facility is
renamed, and issued certificates already snapshot their identifiers.

**The one constraint to not remove.** `subsystems_area_within_facility` is a
*composite* FK `(area_id, facility_id) → areas (id, facility_id)` (backed by
`areas_id_facility_id_unique`), `ON DELETE SET NULL`. Two independent FKs would
happily allow a subsystem in facility *A* pointing at an area in facility *B* —
silent cross-facility corruption that every roll-up would then report
confidently and wrongly. Deleting an **area** nulls `area_id` and keeps the
subsystem; deleting a **facility** cascades, because a subsystem with no
commissioning parent is meaningless.

**Migration trap (cost an hour, will recur).** The backfill wrote nothing and
the migration died with `23502 … facility_id contains null values`. `subsystems`
is under `FORCE ROW LEVEL SECURITY` and a migration has no `epcc.company_id`
GUC, so the backfill `SELECT` matched zero rows — **silently**, because RLS
filters rather than errors. Fix: `DISABLE ROW LEVEL SECURITY` around the
backfill, then restore **`ENABLE` *and* `FORCE`** — `ENABLE` alone leaves the
owner exempt, and role `epcc` owns these tables. Safe only because PostgreSQL
DDL is transactional.

**Tests:** `tests/Feature/BreakdownTest.php`, 25 tests / 69 assertions, covering
structure, per-facility code reuse, `fullCode()`, cross-facility integrity,
area-delete vs facility-delete semantics, `FacilityKind` (including
known-but-not-selectable), the addressing jsonb, `Area::displayCode()`, RLS
reads and writes, and that the migration left `relrowsecurity` *and*
`relforcerowsecurity` true on all three tables. Mutations M118–M123 all caught.

## 5m. Engineering reference data — ASME sizes, schedules, end types, templates (phase 0)

**ADR-007 is ACCEPTED AND BUILT.** This is the first piece of ADRs 006–010 to exist as code.
It is deliberately the first: the line-number parser, the port compatibility engine and the
PCF importer all need the size vocabulary before they can validate anything.

### What it is

Four versioned JSON packs under `standards-packs/reference/`, read through one memoised
singleton. **Not database tables.** `NPS 6" = DN 150 = OD 168.3 mm` is true on every project
in every company in every year — putting it in a table means either ~500 duplicated rows per
project or the system's first cross-tenant table plus a permanent RLS exception. The
protection actually needed is *nobody can write, including the owner company*, which a file
on disk gives absolutely and a permissive RLS policy only pretends to.

| Pack | Size |
|---|---|
| `pipe-sizes.ref.json` | 36 sizes, 1/8″–48″ |
| `pipe-schedules.ref.json` | 355 (size, schedule) pairs, 18-rung ladder |
| `end-types.ref.json` | 13 PCF end types |
| `component-templates.ref.json` | 20 templates, 41 port definitions |

Access: `App\Support\Reference\PipeReference`, singleton in `EpccServiceProvider`, directory
from `config('epcc.reference_dir')`. `App\Support\Reference\PipeSize` is the readonly value
object. **Every lookup throws** (`UnknownSizeException`, `UnknownScheduleException`) — except
`resolveLabel()`, which returns null because line-number parsing legitimately asks "is this
token a size at all?" where "no" is a normal answer.

### The four NPS columns, and why none can go

At the user's explicit instruction, and each has a job the others cannot do:
`nps` `"2 1/2"` is what the engineer reads · **`npsDecimal` `2.5` is the only summable one —
this is what makes dia-inch computable (ADR-010)** · `dn` `65` is the integer key for ADR-008's
`int4range` bands · `odMm` `73.0` is the physical dimension. A future "simplification" to
fewer columns silently breaks dia-inch reporting.

### STD is not SCH 40 — the trap that justified this whole ADR

Published tables disagree, and **several print STD values under a column headed SCH 40**.
Verified in the shipped data and asserted in three independent layers:

| NPS | SCH 40 | STD | SCH 80 | XS |
|---|---|---|---|---|
| 6″ | 7.11 | 7.11 | 10.97 | 10.97 |
| 10″ | 9.27 | 9.27 | **15.09** | **12.70** |
| 12″ | **10.31** | **9.53** | 17.48 | **12.70** |
| 24″ | **17.48** | **9.53** | 30.96 | **12.70** |

STD diverges from SCH 40 from 12″; XS diverges from SCH 80 from 10″. Above those, STD is
pinned at 9.53 and XS at 12.70 regardless of diameter. **A test that spot-checks 2″ and 6″
passes on a completely wrong large-bore table.**

### TRAPS

- **PHP coerces numeric string array keys to integers.** `array_keys()` on the decoded wall
  table returns `40` as an **int**, so `schedulesFor()` handed back a mixed `int|string` list:
  strict `in_array()` silently fails, and feeding a key back into `wall()` is a `TypeError`.
  Fixed with `array_map(strval(...), …)`. Caught by the suite on first run.
- **The reachable alias-fallback bug runs the other way.** Asserting STD ≠ SCH 40 at 12″+ does
  **not** stop a "helpful" fallback, because STD is never the missing one. **ASME defines STD
  at 22″ and 26″ but no SCH 40 there at all** (likewise XS but no SCH 80 from 26″ up) — a
  fallback returns 9.53 mm for a schedule that does not exist. Survived the first suite; found
  only by mutation M1.
- **Instance identity does not prove memoisation.** A singleton that re-decodes 500 rows per
  lookup is still a singleton. The test now warms the cache, deletes the files, asserts the
  lookup still works, **and** asserts a fresh instance against the same empty directory throws
  — otherwise it would pass on an eager-loading implementation. Found by mutation M14.
- **A PCF bore is meaningless without its unit.** `UNITS-BORE` may be `INCH`, `MM` or
  `INCH-SIXTEENTH`. A bore of `96` is 6″ in sixteenths and **not a pipe size at all** in
  inches. `resolveBore()` therefore takes the unit as a required argument and throws on an
  unrecognised one rather than defaulting.
- **Persian digits must be folded.** `۲ ۱/۲` → `2 1/2`. Without it, a Farsi-locale user typing
  a perfectly valid size gets `UnknownSizeException`.

### Validation, three layers

Generation-time checks in the emitting script; `validate_packs.py` grew
`check_reference_schema()` + `check_reference_integrity()` (NPS labels re-parsed with
`Fraction` and compared to `npsDecimal`, `odIn × 25.4` reconciled to `odMm`, monotonic ladder,
the STD/XS pins, `numbered ⇒ weldable`, `TEE` must have 3 ports and be `qualifierDerived`,
`EQUAL_TEE`/`REDUCING_TEE` **forbidden**, `NOZZLE` must carry `ownerKind`); then 49 PHPUnit
tests including `test_it_touches_no_database()`, which fails if reference data is ever moved
into a table. **15 data mutations and 15 PHP mutations were run; all 30 caught.**


## 5n. Why one database, not one per project (ADR-011)

Asked by the user on 2026-09-15. Answer: **no for the hosted SaaS, yes at instance level for
on-premise.** Measured rather than asserted, and one finding settled it.

**The fixture already contains the breaking case.** `EPC-PARS`, `NIOC-AFF` and `PMC-TEHRAN` each
participate in **two projects** — normal for an Iranian EPC contractor, not an edge case. Under
database-per-project a single human becomes two independent user rows with two password hashes
(verified in a spike), and the post-login landing page — *my documents across my projects* —
cannot be produced by a query at all:

```
ERROR:  cross-database references are not implemented
```

That is a hard PostgreSQL limit, not a config problem. `plans.max_projects` also proves the
**commercial unit is the company, not the project**, so the billing boundary and the storage
boundary would point in opposite directions.

**Isolation is already there.** Binding a session shows the same company gets strictly one
project's rows when bound to that project, both when bound to neither, and a non-participant
gets zero. Separation would buy isolation that exists, and would swap a database-enforced rule
(fails closed) for a connect-to-the-right-database rule in application code (fails open).

**RLS applies to `COPY`, not just `SELECT`** — so a per-project export is just a bound session.
The usual "but we need per-project handover" argument does not require separation.

The one genuine capability the shared database lacks is **per-project point-in-time restore**.
Schema-per-project solves it and stays cheap to reach later, because every table already carries
`project_id`, RLS is already project-aware, and there are **zero** `DB::connection()` call sites.


## 5o. The RLS escape hatch is a performance defect (ADR-012) — UNFIXED

**Found 2026-09-15 at 200 projects / 2.64 M rows. Present in production code today, invisible at
fixture scale, and it couples tenants: one customer's page slows down when an unrelated
customer's project grows.**

Every tenant policy has this shape:

```sql
(NULLIF(current_setting('epcc.project_id', true),'') IS NULL      -- escape hatch
 OR project_id = NULLIF(current_setting('epcc.project_id', true))::uuid)
AND EXISTS (SELECT 1 FROM project_company pc WHERE …)
```

`A IS NULL OR col = A` is **not sargable**. No index on `project_id` can ever be used:

```
Seq Scan on punch_items   Rows Removed by Filter: 1315440   -- to return 25 rows
Execution Time: 870 ms  (1,690 ms once the table doubled)
```

Adding `(project_id, sequence)` changed nothing — **the index was never the problem.**

**The trap: the fastest fix is the broken one.** Deleting the `OR` branch gives 870 ms → 0.16 ms
and **silently returns 0 rows** for the cross-project landing page (company bound, no project
bound). No error; every project-bound test still passes. A benchmark alone would have chosen it.
`coalesce(setting, project_id)` is correct but still opaque to the planner (183 ms, parallel seq
scan).

**The fix that is both:** the policy keeps only membership (`project_id IN (SELECT … FROM
project_company WHERE company_id = …)`) and the *query* adds `WHERE project_id = :current`.
Measured: register page **0.144 ms**, cross-project view **211,200 rows**, non-participant **0**,
participant asking for a project it is not in **0**.

**The curve goes flat** — a 540 k-row project pages in the same 0.13 ms as a 5 k-row one, at both
1.3 M and 2.6 M total rows. Page latency follows page size, not table size. **No partitioning, no
sharding, no database-per-project.**

Not yet applied: it changes 24 security policies. Isolation *between companies* stays
database-enforced; isolation *between projects of the same company* moves to the query layer —
a deliberate decision, not a side effect. Plan in ADR-012 §8, and **write the failing
cross-project test first**, because the current suite would pass the broken version.


## 5p. Per-project backup and deletion (ADR-013) — proven on a scratch copy, not yet built

User asked whether one project can be backed up and then completely deleted. **Yes — the full
cycle (export → delete → restore → verify) was run end to end on a byte-for-byte copy of the live
database.** 93 rows / 26 tables exported, delete took **53 ms** via 23 cascades, zero orphans,
other projects untouched, restore matched the manifest exactly and every FK re-validated.

**Four traps, and the first two make the naive answer wrong:**

1. **`pg_dump` as the app user silently truncates.** `FORCE ROW LEVEL SECURITY` applies to the
   owner, so the dump **aborts mid-way, exits 0, and leaves a 29 KB file** where the real dump is
   166 KB. A backup script checking `$?` reports success. Per-project backup therefore requires a
   superuser/`BYPASSRLS` role — the backup job is a **privileged component**.
2. **`pg_dump --where` is PostgreSQL 18.** On 17.11 it is `unrecognized option`. Row-filtered
   export must be built table by table with `\copy (SELECT … WHERE project_id=…)`.
3. **A topological sort of the 25 tables silently drops the document register.**
   `documents.current_revision_id ↔ document_revisions.document_id` is a genuine cycle, so those
   four tables have no topological position and fall out of the recursion — the export completes,
   reports success, and contains **no documents**. Fix: explicit reviewed order + restore under
   `SET session_replication_role = replica`, then re-validate. **The per-table row-count manifest
   is what catches a silently-empty table.**
4. **Deleting the rows does not delete the files.** No FK reaches the filesystem: after a clean
   cascade with zero orphans, the project's 4 blobs are still on disk. Blob paths are
   project-scoped (`documents/<project-uuid>/…`) and no sha is shared across projects, so it is a
   directory removal — but it must happen **before** the rows, or the paths are gone.

**Also:** `authorization_denials` and `gate_evaluations` carry `project_id` with **no FK**, so they
are never cascaded and must be deleted explicitly. And `projects_code_unique` means a deleted
code becomes reusable, so two unrelated `PH-14`s can end up in the archive.

Deleting PH-14 destroyed 1 MC certificate, **2 payment certificates**, 5 transmittals and 3
inspections — contractual evidence. ADR-013 therefore proposes three separate operations (export /
archive / ceremonial two-person hard delete) and recommends **archive as the default**.


## 5q. Project archive — BUILT (the answer to "what happens when a project finishes?")

ADR-013 asked whether a finished project should be archived or hard-deleted. **The decision was
archive**, and it is now built. A hard delete remains a separate, ceremonial operation that is
deliberately *not* implemented yet.

**What archiving is.** A project moves `ACTIVE ↔ ARCHIVED`, reversibly. Archived means:
read-only for everyone including the owner, hidden from the portfolio by default, and **not
counted against `plans.max_projects`**. Nothing is deleted, nothing is moved, every certificate and
transmittal stays exactly where it was and stays exportable. There is deliberately **no `DELETED`
state** in `ProjectState` — deletion is not one dropdown position away from archiving.

**Why the quota exclusion is the commercial crux.** A customer on a one-project plan who finishes a
job and cannot start the next one without deleting the first will delete the first. The licence
terms would be quietly instructing them to destroy contractual evidence. Archiving only works as an
alternative to deletion if it is free of the quota — see `ProjectSetupController` (the count is now
`->active()`), and the refusal message now tells them archiving is the way out.

**Two enforcement layers, and both are necessary.**

| Layer | File | Fails how |
|---|---|---|
| Service (refuses first) | `app/Services/ProjectArchiveService.php` | `ValidationException`, translated, actionable |
| RLS (backstop) | migration `2026_09_15_120000_add_project_archive_state.php` | `SQLSTATE 42501` on INSERT/UPDATE; **silence** on DELETE |

Neither alone is enough. The service is forgettable — a new write path that skips `assertWritable()`
gets no protection from it. RLS is mute — see the trap below. So the service exists to produce a
sentence a user can act on, and RLS exists so that forgetting it is merely rude rather than
destructive.

**`epcc_project_is_writable(uuid)` is `STABLE SECURITY DEFINER` and must stay that way.** Without
`SECURITY DEFINER` the function is itself subject to RLS on `projects`, so a session scoped to
project A cannot see project B's state, the lookup returns NULL, and the freeze **fails open**.

**The DELETE hole, found by trying it.** The freeze policy is `FOR ALL USING (true) WITH CHECK
(...)`. `USING (true)` is deliberate — a RESTRICTIVE `USING` clause is ANDed into `SELECT` too, and
an archive you cannot read is just a slow delete. But **`WITH CHECK` is never evaluated for
DELETE**, because there is no new row to check. With only that policy, deleting the punch list of
an archived project reported `DELETE 9` and removed all nine rows. Fixed with a second policy per
table, `FOR DELETE USING (epcc_project_is_writable(project_id))`, which constrains only DELETE and
leaves reads alone. **22 tables × 2 policies.** Verified: archived → `DELETE 0`, rows intact;
reopened → `DELETE 9`.

**UI.** Archive/reopen live on the project page, owner company only (`TenantType::OWN`), with the
reason recorded and shown back as `Archived on <date> by <user> — <reason>`. The portfolio hides
archived projects but always shows a clickable count — hiding without saying so is how a finished
project starts to feel lost.

Tests: `php artisan test --filter=ProjectArchiveTest` — **30 tests**, including the DELETE
regression, the silent-failure documentation, the quota exclusion and the bilingual refusal.


## 5r. Piping lines + numbering + the AFC gate (ADR-006 phase 1) — BUILT

The first piece of the engineering-object spine. A **line** is the unit the whole piping discipline
hangs off: components, joints, welds, NDT, hydrotest and progress all resolve to a line. Phase 1
builds the register, the project-configurable number, and the gate that says *"this line may be
built"*.

### The numbering engine is data, not code

The number format is **per project**, stored as jsonb on `project_piping_config`:

```
{size}-{service}-{class}-{subsystem}-{sequence}-{coating}   →  8-P-A1-PH-14-SS-2201-001-CT2
```

Each token declares `source` (`attribute` | `parent` | `counter`), an optional `width` for padding
and an `optional` flag. **Padding is render-time only** — `sequence` is an integer in the database,
so `MAX(sequence)+1` stays a number and never sorts as `"10" < "9"`.

**Do not parse a number by splitting on the separator.** The separator occurs *inside* token values:
`explode('-', '8-P-A1-PH-14-SS-2201-001-CT2')` yields **9 fragments for 6 tokens**, because the
subsystem code `PH-14-SS-2201` contains three of them. `parse()` builds an anchored regex from the
injected vocabularies instead.

Two PCRE facts the tests now pin:

- **Alternation is first-match, not longest-match.** Usually backtracking hides this (`A1|A1B`
  against `A1B-…` retries and succeeds). It stops hiding it when the *next* token can absorb the
  difference — with `PH-14|PH-14-SS` against `PH-14-SS-2201`, both splits match and the wrong one
  wins silently. Hence vocabularies are sorted **longest-first**. Mutation M1 proves it.
- A relaxed diagnostic token must be lazy `.+?`, and **only one token may be relaxed at a time**.
  `[^-]+` cannot cross a separator, so relaxing everything at once just fails again.

**`compose()` throws; `parse()` never throws.** Composition with a value outside the project
register is a refusal — otherwise an unrecognisable number reaches a drawing. Parsing is diagnosis:
it returns one of four outcomes (no-match / valid / matched-with-`unknownValues` /
matched-but-`unconfirmed`), because `isValid()` and `isFullyChecked()` are different questions when
a number arrives from someone else's P&ID.

### Two doors, and why imports must not feed the counter

`create()` allocates: it locks the **subsystem** row and takes `MAX(sequence)+1`.
`register()` imports: it stores the supplied number **verbatim** and leaves **`sequence` NULL**.

That NULL is load-bearing. An imported `…-777` must not push our next allocation to 778 — we would
be inheriting a foreign numbering scheme's gaps by accident. A non-conforming number is stored with
`number_tokens` NULL and flagged, never rejected and never "normalised": ADR-006 §10.2 forbids
inventing or rewriting someone else's tag. Mutation M10 pins it.

### What AFC actually means, and the B-code trap

AFC — *Approved For Construction* — is the moment the office tells the site **this is safe to
build**. A gate that can be passed without real approved evidence is worse than no gate, because
every downstream report then claims a check happened.

`CdeState::isApproved($state, $suitability)` requires **both**:

| Condition | Why |
|---|---|
| `cde_state === PUBLISHED` | SHARED is issued *for review*, however it is coded |
| suitability starts with `A` | **B1–B5 mean "approved with comments"** — published, valid, *not* fit to build |

Both halves are needed and neither is redundant. **Mutation M8 caught the gap here**: the obvious
"not approved" fixture is SHARED+S2, but S2 already fails the A-check, so the state condition was
never exercised and could have been deleted with the suite still green. The test that separates
them is **SHARED + A1** — right code, wrong container. A null or empty suitability is not approval
either: absence of a code is absence of a decision.

Waving a B-code through is precisely the rework mechanism the gate exists to stop.

**Two defects were found by writing these tests, before any of this ran:** `confirmAfc()` called
`CdeState::isApproved()` — *a method that did not exist* — on `$revision->state`, *a column that
does not exist* (it is `cde_state`, with `suitability` a sibling column).

### States

`DRAFT → AFC_CONFIRMED → RELEASED`, with backward edges to `DRAFT` only. `revertToDraft()` demands
a reason and **nulls** `afc_confirmed_at` / `released_at` — a line pulled back must not keep a
stale approval timestamp that a later report would read as still-approved (mutation M12). Four DB
CHECK constraints enforce known states and AFC/release provenance independently of the service, so
a future write path that bypasses `PipingLineService` still cannot forge an approval.

### UI — `/projects/{code}/piping`

Register, both doors, the gate buttons, and per-line **blockers**: the screen says *why* a line
cannot pass (`Missing: ISOMETRIC, PID` / `Not approved: <doc no.>`), not merely that it has not.

Two UI defects found by driving the real screen with curl after the service tests were green:

1. `UnknownTokenValueException` escaped as a **500**. The engine was right to refuse; the
   controller was wrong to crash. An operator choosing a service the project has not set up is
   making a correctable mistake and is now told which value and which token.
2. The form offered **free text** for vocabulary-controlled tokens. Now they are `<select>`s built
   from the live register, and a token the pattern needs but the project has not populated renders
   as a disabled "not set up for this project yet" notice rather than a box that can only fail.

Note `back()` is wrong for the error path — a form POST may carry no referer, and the redirect to
`/` burns the flashed error. Redirect to the register explicitly.

### Tests

| Suite | Tests | Notes |
|---|---|---|
| `LineNumberingTest` | 24 | pure unit, no DB, ~1 s |
| `PipingLineTest` | 31 | service + RLS, real DB |
| `PipingLineHttpTest` | 6 | the screen, authorization, the two defects above |

**13 mutants killed, 0 survivors** (after fixing the two the first pass exposed): sort order,
`preg_quote`, lazy relaxation, padding, compose validation, regex anchors, both halves of the
approval predicate, the missing-evidence check, import sequencing, counter scope, revert clearing,
participation check.


## 5s. The numbering editor and evidence attachment (ADR-006 phase 1b) — BUILT

§5r left phase 1 *correct but not operable*: the number format could only be changed from tinker,
and evidence could only be attached programmatically. Both are now real screens.

### `/projects/{code}/piping/config` — the numbering editor

Owner-company only. Edits the token list, the separator, and the three project registers
(services, coatings, insulation types). Two blank token rows are always rendered, so adding a
token needs no JavaScript.

**Why this screen needed guard rails more than most.** A number format is the one setting where a
plausible-looking mistake does not fail on save — it fails weeks later, on the hundredth line.
Four rules, each verified rather than assumed:

| Rule | What happens without it |
|---|---|
| Pattern must contain a counter | **Probed the engine first:** composing a counter-less pattern twice with identical inputs returns the identical string, so the second `create()` dies on the uniqueness check with *"number already taken"* — a message naming the symptom and hiding the cause. The project silently caps at one line per subsystem. |
| Counter width may grow, never shrink below what was issued | Padding is render-time, so narrowing does **not** corrupt stored data — which is exactly why it is dangerous. Nothing breaks until the project crosses the threshold, then line 100 renders beside `001` and a text sort interleaves them. |
| Register values may not contain the separator | Every number built from the value becomes unparseable: the regex cannot tell the operator's dash from a structural one. |
| A value used in an existing line number cannot be removed | Removing it renumbers nothing; it just makes those numbers unrecognisable to `parse()`. Paired with a test proving an **unused** value can still be removed, so the rule does not freeze the register solid. |

Well-formedness (one counter maximum, no duplicate token names, non-empty separator) is
**delegated to `NumberPattern`'s constructor** rather than reimplemented, so the two cannot drift.
The service only translates those into a `ValidationException` a form can render.

**Trap:** `AmbiguousPatternException` and `InvalidPatternException` both extend `RuntimeException`
directly and are `final` — neither is a subclass of the other. Catching only `InvalidPatternException`
let the two-counter case escape as a 500. Catch both.

### Evidence attachment, and the gate that could not be escaped

The register now has a document picker per draft line, showing each linked document with its CDE
state and suitability, `✓` or `•` by whether it actually passes.

**Driving the screen found a real defect.** Attaching is *not* exclusive — several documents can
be linked under the same kind, and `unapprovedEvidence()` checks **all** of them. So a B-coded
drawing attached by mistake locked the line out of AFC **permanently**, with no way back except a
database edit. Attaching the correct A-coded isometric afterwards did not help: the bad link was
still evidence.

`detachEvidence()` fixes it, with two constraints:

- **Draft lines only.** Detaching evidence from a confirmed line would erase the justification for
  a decision already taken. Revert to draft first — that path demands a reason and is logged.
- **The link must belong to the line.** Caught by **mutation M21**, which survived the first pass:
  nothing stopped a hand-edited URL pairing line A with line B's evidence link. Both rows sit in
  the same project, so **RLS is perfectly happy** — the ownership check is the only thing between a
  curious URL and quietly stripping evidence off a line the user never opened.

A strict gate must still be escapable by *correcting the input*. That is the difference between a
gate and a trap.

### Confirmed end-to-end through the UI

Attach B-coded ISO + approved P&ID → gate refuses, naming the document and explaining that a B
code is *approved with comments* → detach the B-coded one → attach the A-coded one → **AFC
confirmed**.

### Tests

`PipingConfigTest` 15 · `PipingLineHttpTest` 10 (was 6) · `PipingLineTest` 32 (was 31, +2 archive
guards, −1 duplicate I had added twice).

**Mutants 14–22, all caught** after M21 was fixed: counter-less pattern, narrowing guard,
owner-only, separator-in-value, in-use removal, archive guard, detach-on-confirmed,
detach-cross-line, and `data-tt` on the register table.

### `data-tt-exempt`

`TableToolsTest` enforces that every view with a `<thead>` opts into filter/sort. The config
screen's token grid legitimately must not: **the row order *is* the data** (it is the order tokens
appear in the number), so sorting would corrupt what is being edited and filtering would hide rows
about to be submitted as a set. Rather than weaken the guard, the view declares `data-tt-exempt`
and says why. Verified the guard still bites: removing `data-tt` from the real register table
still fails the test, and there is exactly **one** exemption in the codebase.



## 5t. Units of measure (ADR-014) — BUILT

**User request:** «قابلیت تغییر واحدها مثل فشار، دما و غیره رو ایجاد کن».

### What it does

An engineer types `285 psi`; the owner reads `1965.01 kPa (285 psi)`; a contractor who prefers barg
reads `19.65 barg (285 psi)`. **Nothing in the database changed between those two readings.**

### The storage shape — copy this for every future physical quantity

Three columns per value, not one:

| Column | Example | Role |
|---|---|---|
| `design_pressure` | `285` | **source of truth** — what the datasheet says |
| `design_pressure_unit` | `psi` | **source of truth** — a number without this is not a pressure |
| `design_pressure_kpa` | `1965.005828` | derived mirror, for `WHERE`/`ORDER BY`/indexes |

Storing only the canonical loses the engineer's number: 285 psi round-trips to **285.0006** at
`decimal(12,4)`, so a 150#/300# rating lookup keyed on "285" or "740" misses. Storing only the
entered pair makes "every line above 10 barg" a full scan with a unit-dependent predicate — which
ADR-012 already measured as 0.12 ms vs 1690 ms under RLS.

**A value with no unit gets no canonical.** Guessing is how a psi number lands in a barg column.

### Four rules the engine enforces

1. **Temperature is affine.** `canonical = value × factor + offset`. Multiply-only is wrong by 32
   degrees everywhere (it maps 0 °C to 0 °F). One code path: offset defaults to 0 and collapses.
2. **A difference is not a temperature.** A 50 °C rise is 90 °F, not 122. `convertDifference()`
   uses the factor alone. Nothing calls it yet — it exists so the first delta field finds it.
3. **Gauge never converts to absolute.** They differ by *site* atmospheric pressure, not a
   constant. The converter refuses and the message names atmospheric pressure, so the reader knows
   it is a missing input rather than a missing feature.
4. **Absolute zero is checked on the CONVERTED value**, so −500 °F (−295.6 °C) is caught while
   −400 °F (−240 °C) passes. Enforced in the service *and* as a `CHECK`, so an import path that
   skips the service still cannot write impossible data.

### The menu is narrower than the engine (user decision, 2026-09-17)

Choosable: **pressure → `bar` or `psi`; temperature → `C` or `F`.** Nothing else.

Everything else is retired with `"selectable": false` in the pack — **not deleted**. The engine
still converts kPa, kg/cm², barg, K and the rest, forever, because values already stored and
values arriving from imports must stay readable. Deleting a unit turns historic data into an
unreadable number; hiding it does not.

**Consequence worth remembering: canonical ≠ default display.** Pressure is stored in kPa and read
in bar. Falling back to the canonical unit would show an unconfigured project a unit that is not in
its own dropdown, so the pack carries `default_display` per quantity and `UnitRegistry` has
`defaultDisplayUnit()` separate from `canonicalUnit()`. If you add a quantity, set both.

A refused choice says "kPa is not one of the units this project uses — choose one of: bar, psi",
never "unknown unit", which would be false.

`validate_packs.py` asserts the agreed menu, that every quantity offers at least one unit, and that
`default_display` is itself selectable. Widening the menu is an edit to that file, not a drift.

### Preference resolution

```
user override  →  project default  →  pack canonical
```

`users.unit_preferences` is **nullable**; `project_piping_config.unit_preferences` defaults to `{}`.
**Null ≠ `{}`.** Null on a user means "inherit"; `{}` means "I have chosen". Collapsing them would
freeze a user's display at whatever the project default was the day they first opened the screen.

Project defaults are employer-only and blocked on an archived project. A *personal* preference is
neither gated — it changes no stored value.

### Gotchas found while building this

- **`unitsFor()` returns whole unit definitions, not codes.** Passing them to a `string $code`
  callback is a 500 on page render, not a type error at compile time.
- **`$user->fresh()` returns null after updating a preference.** `users` carries RLS and a bare
  re-read outside a tenant scope sees nothing. `setUserPreferences()` returns the updated model;
  use it. Same trap as the one already recorded for `fresh()` outside `runAs()`.
- **`decimal(16,6)` rounds the mirror.** 150 psi stores as `1034.213594`, not `1034.2135935`.
  Comparing an in-memory value against a stored one fails on the last digit — compare a stored
  read against a stored read. **Reports must print the entered value**, never the mirror.
- **RELEASED has a `CHECK` demanding AFC provenance.** A test that forces `release_state` directly
  must supply `afc_confirmed_at`/`_by` too.
- **The auth proxy on 8099 does not forward POST bodies.** Test write paths directly against 8000
  with a real login, or the CSRF failure looks like a token bug.

### Pack validation

`standards-packs/reference/schema/units.schema.json` + `check_units()` in `validate_packs.py`.
Beyond shape, it verifies: the canonical unit exists and has factor exactly 1.0; no alias collides
with another unit's code; offsets appear only on temperature and datums only on pressure; the six
quantity keys the PHP names still exist; and anchor conversions (285 psi, 1 bar, 1 kg/cm², 1 in,
1 ft, 1 lb, 100 °F, 0 K). **Verified by breaking the pack six ways — all six were caught.**

### Files

`app/Support/Units/{Quantity,UnitRegistry,Measurement}.php` · `app/Services/UnitPreferenceService.php`
· `PipingLineService::{canonicalValues,updateDesign}` · migration `2026_09_15_160000_…` ·
`resources/views/piping/index.blade.php` · `lang/{en,fa}/epcc.php` under `units.*` ·
`tests/Feature/{UnitRegistryTest,UnitPreferenceTest}.php` (63 + 37).

**Mutation testing: 43 mutants on the units layer, 0 survivors.** Two survived first pass: M28 (the
cross-quantity check was masked by the datum check — only PRESSURE has non-NONE datums, so
isolating it needed `mm→kg` and `barg→bara`) and M38 (`updateDesign` had no archive-gate test).



## 5u. Components and ports (ADR-006 phase 2) — BUILT

**The user's question this answers:** «چطور سیستم تشخیص بده که لوله دو port و Tee سه port داره؟»

**It never asks.** The port count, the port roles and the default end types all come from the
component template in the reference pack. `ComponentService::place()` takes a template code and
creates the ports itself; there is no parameter anywhere that accepts a port count, and
`test_the_service_exposes_no_way_to_set_a_port_count` exists to keep it that way.

### The gesture

Pick a component type, pick an open port to attach it to. Everything else is derived:

| Derived | From |
|---|---|
| number and roles of ports | the template |
| the connecting port's size | **inherited from the parent port** — never typed |
| the other ports' sizes | the template's `sizeRule` |
| which end is "branch" / "small" | the port ROLE, not its number |

Size inheritance is what makes *"6″ pipe onto a 2″ branch"* structurally impossible rather than
merely discouraged. An explicit `nominal_size` passed to `attach()` is **discarded**, and a test
asserts the discard.

### Schema

`components` (per line, `UNIQUE(piping_line_id, sequence)`) and **one** `ports` table with an
`owner_kind` discriminator. The nozzle side lands at phase 3b; the column and its CHECK exist now
so that arrival is an INSERT, not a migration. ADR-010 §3.2 rejected sibling tables because every
joint query would then need a two-branch COALESCE.

CHECKs worth knowing: `ports_exactly_one_owner` (a row cannot claim both a component and a nozzle),
`components_size_pair_complete` (NPS string and decimal travel together or not at all — a decimal
without the string cannot be displayed, a string without the decimal cannot be compared, and
dia-inch needs the comparison), `components_length_not_negative`.

`is_connected` is denormalised **on purpose**: "every open end on this line" is the single
most-used query in the system (ADR-009 §2.3) and drives the worklist, the progress metric and the
import validator.

### Rules enforced

- branch ≤ run on a TEE/OLET (`BRANCH_LE_RUN`)
- a REDUCER's two ends must differ — a 6×6 "reducer" is a spool nobody ordered
- a per-port override on a `SAME_AS_FIRST` component (a "6×4 valve") is **ignored**, not honoured
- a length on a component whose template has none is dropped
- NOZZLE is in the pack but **not placeable on a line** — it belongs to equipment
- an unrecognised size is refused, never silently nulled
- RELEASED lines and ARCHIVED projects refuse modelling

### Gotchas found while building this

- **There is no `epcc_can_read_project()` helper.** I invented it from memory and the migration
  failed on `CREATE POLICY`. The real read rule is spelled out inline from `current_setting` —
  copy it from `create_piping_lines`, do not reconstruct it.
- **`->sortBy([fn, fn])` silently does nothing useful.** The array form of `sortBy` expects
  *property/comparison pairs*, not a list of key closures. It presented as P2 listed before P1 in
  the open-ends panel. Use one closure returning a composite sort key.
- **Form vocabulary must map to port ROLES, not port numbers.** The branch is port 3 on a TEE but
  port 2 on an OLET, and a REDUCER has no branch at all. The controller resolves the role to a seq
  from the template; a positional guess would put a branch size on a reducer's large port.

### Two display bugs the screenshot caught that no test would have

Both were found by *looking* at the rendered page, not by running the suite — worth remembering
when a feature is "done and green".

1. **Open and connected ports were near-identical pills.** Both used `var(--line)` borders on a
   dark background. Colour alone also fails a colour-blind reader. Fixed with a dashed amber
   border and a `○` / `●` glyph, plus a legend next to the heading, so the state is readable
   without relying on hue.
2. **RTL reordered the inch mark.** `P2 RUN 6"` rendered in Farsi as `"P2 RUN 6` — the quote
   floated to the visual start of the line. Latin-plus-digit tokens inside an RTL paragraph need
   `<bdi>`; inside an `<option>`, where markup is not allowed, they need a trailing **U+200E LRM**
   instead. Six places on this page were affected. **Check every mixed-script token in FA, not
   just the prose.**

Screenshots of both languages: `docs/screens/model-page-{en,fa}.png`.

### Files

`app/Models/{Component,Port}.php` · `app/Services/ComponentService.php` ·
`app/Http/Controllers/ComponentController.php` · `resources/views/piping/model.blade.php` ·
migration `2026_09_17_120000_create_components_and_ports.php` · routes `piping.model*` ·
`lang/{en,fa}/epcc.php` under `component.*` · `tests/Feature/ComponentModellingTest.php` (39).

Reachable from the line register: each row has a **Model** button.

**Mutation testing: 13 mutants, 0 survivors** (M44–M56).


## 5v. Joints, compatibility and dia-inch (ADR-006 phase 3) — BUILT

The model stops being a parts list here. Every connection is now a row with a number, a state and
a measurable size — the entity Plant 3D does not have, because there a connection is geometry and
here it is a construction object.

### The table

`joints`: uuid pk, `project_id`/`company_id`/**`piping_line_id`** (denormalised — a joint never
moves between lines, and every read is line-scoped), `port_a_id`/`port_b_id`, nullable
`joint_seq`, `numbering_policy`, `joint_type`, `state`, `fitted_by_code`+`fitted_at`, frozen
`nominal_size`/`nominal_size_decimal`/`schedule`/`thickness_mm`, `shop_field`, `spool_id`,
`isometric_sheet_no`, `compatibility_state`+`compatibility_notes jsonb`, `source`, `external_ref`.

Constraints that carry weight:

- **`CHECK (port_a_id < port_b_id)`** plus `UNIQUE(port_a_id, port_b_id)`. Without the CHECK,
  (A,B) and (B,A) both satisfy the UNIQUE and the same weld is counted twice in dia-inch.
  `JointService::orderedPair()` sorts before every write so no caller can get it wrong.
- **Two separate unique indexes on `port_a_id` and on `port_b_id`.** The pair-unique does not give
  this: (A,B) and (A,C) are distinct pairs, so without them port A could be welded to two things.
- `joints_unnumbered_policy_has_no_seq` — a NOT_APPLICABLE joint must not be carrying a number.
  Switching a weld to a flanged connection otherwise leaves the old number on reports forever.
- `joints_fitted_needs_evidence` — FITTED demands **both** `fitted_by_code` and `fitted_at`, the
  same shape as the AFC gate. Who-but-not-when is not auditable.
- Partial unique `joints_line_seq_unique WHERE joint_seq IS NOT NULL`, partial index
  `joints_awaiting_number_idx` for the chase-list.

### Three rules worth not re-deriving

**Dia-inch: the smaller diameter governs, and it is never stored.** `min(portA, portB)`, computed
every time. A stored copy drifts from the ports and the error does not crash — it inflates
progress, which on a job paid by dia-inch is an invoice. Only an approved progress snapshot
freezes it. Flanged joints contribute **nothing**: bolt-up is real work but it is not welding.

**Frozen vs computed is a deliberate split.** `nominal_size`/`schedule`/`thickness_mm` are frozen
onto the joint at creation — a joint accepted at 6"/SCH40 must keep saying so even if a port is
later edited. Dia-inch is the opposite. The distinction: those columns record what was **built**,
dia-inch **measures** it.

**A number nobody uses is a lie that costs.** Welded joints get a per-line sequence from a shared
counter (welded + threaded), allocated under `lockForUpdate()` on the line row. Flanged joints get
`numbering_policy = NOT_APPLICABLE` and **no** number — they are not welds awaiting one. Next
number is **`max()+1`, never `count()+1`**: joints get deleted during modelling and a reissued
number means two different welds on two drawings.

### PortCompatibility — a pure function with three outcomes

`app/Support/Piping/{PortCompatibility,Verdict}.php`. No DB, no locale — the verdict carries
reason **codes**, and translation happens in `Verdict::messages()`, so tests compare verdicts
without string-matching a language file.

| Verdict | Example |
|---|---|
| INVALID | BW onto FL · size mismatch · unknown end code · port joined to itself |
| WARNING | schedule break at equal bore · SW/SW gender unverified |
| VALID | BW/BW equal bore · **PL onto BW** (a field-cut pipe) · anything against `**` |

The schedule check compares the **resolved wall from the reference pack**, not the schedule name.
STD vs SCH 40 at 6" is the same wall and must not nag; at 12" it is 10.31 vs 9.53 and must. Two
tests pin both halves, which is what stops it being "optimised" into a string comparison later.

### What phase 3 fixed in phase 2

`ComponentService::remove()` can now reopen the counterpart port. It separates every joint
touching the component **first**, then deletes its ports — the other order cascades the joints
away and leaves the neighbour flagged connected with nothing attached, invisible to the open-ends
worklist. `markConnected()` is gone; `attach()` returns a `joint` key alongside the component.

### Files

`app/Models/Joint.php` · `app/Services/JointService.php` ·
`app/Support/Piping/{PortCompatibility,Verdict}.php` · migration
`2026_09_18_090000_create_joints.php` · `lang/{en,fa}/epcc.php` under `joint.*` ·
`resources/views/piping/model.blade.php` (joints table + metrics header) ·
`app/Http/Controllers/ComponentController.php` · `tests/Feature/JointTest.php` (**46**).

**Mutation testing: 23 mutants, 0 survivors** (M57–M78), harness restored at **`mutate.sh`**.

Screenshots: `docs/screens/joints-{en,fa}.png`.

### Two things the mutants and the demo caught

- **M64 found a missing test, not a missing guard.** The self-joint check existed; nothing
  asserted it. A PCF import with colliding END-POINT coordinates would have booked dia-inch for a
  weld that does not exist.
- **The demo script's first version was engineering-wrong, and the engine said so.** Welding a
  flanged valve straight onto a pipe was refused; the fixture needed a flange, exactly as site
  would. When a refusal looks like a bug, check the pipework before the code.


## 5w. The System tier, and Area IS the Unit (ADR-004 rev 2) — BUILT

The user supplied their breakdown diagram and it had **five** levels where the schema had four:

```
Project → Plant/Complex → Unit/Area → System → Sub-system → Tagged assets / Work packages
```

The System level had never been rejected — it had never been **considered**. That is the worse
failure mode: a deliberate omission gets revisited, an accidental one does not.

**Why it matters.** A system is **functional** ("Fuel Gas"), an area is **geographic**, and they
deliberately do not nest. Construction is organised by area, **commissioning by system**. Without
the tier a subsystem's only parent is geographic, so *"what is outstanding before Fuel Gas can be
commissioned?"* has no query — discovered when commissioning starts, with tens of thousands of
rows to re-parent.

### As built

`systems`: uuid pk, `project_id`/`company_id`/**`facility_id`** (a system spans areas, so it hangs
off the facility), `code`, `name_en`/`name_fa`, **`commissioning_sequence`** (nullable — utilities
before process, and that order belongs to the system), `external_ref`.
`UNIQUE (facility_id, code)` — two plants in one project both legitimately run a `CW`.

`subsystems.system_id`, **mandatory wherever a facility exists**
(`CHECK (facility_id IS NULL OR system_id IS NOT NULL)`), plus the composite FK
`(system_id, facility_id) → systems(id, facility_id)` **`ON DELETE RESTRICT`** — the same trick as
areas, making a cross-facility subsystem unrepresentable. RESTRICT rather than SET NULL because
system is not optional: deleting one must not silently take delivered subsystems with it.

**`areas.unit_code` is now mandatory** — the user's ruling "Area IS the Unit". It is load-bearing
for tagging (`11-P-101A`), so: `NOT NULL`, `CHECK (btrim(unit_code) <> '')` (NOT NULL alone accepts
`'   '`, which composes to `11--P-101A`), and **`UNIQUE (facility_id, unit_code)`** — the exact
ambiguity the user described, where two areas coded `11` make the tag meaningless.

### Fixture

Three systems, deliberately **not** parallel to the areas: `CW` cooling water (seq 20) and `EL`
power (seq 10) in PL-01, `IA` instrument air (**no sequence**) in PL-02. CW spans an area and a
no-area subsystem; PL-01 hosts two systems. A fixture where systems and areas lined up would hide
every bug in code that confuses the two axes.

### Files

`app/Models/System.php` · `Subsystem::system()` · `Facility::systems()` ·
migration `2026_09_18_140000_create_systems_tier.php` · `database/seeders/AuthSeeder.php` ·
`tests/Feature/SystemTierTest.php` (**17**) · revised `tests/Feature/BreakdownTest.php` (**26**).

**Mutation: 10 mutants, 0 survivors after two tests were added** (M79–M88, harness
`mutate_systems.sh` + `mutants_systems.txt`). M83/M85 **survived first**: the test counted seeded
rows and the seeder never writes a null, so it passed with or without the constraint. M86
(`NULLS LAST`) is **equivalent** on PostgreSQL and is documented as such in the test rather than
papered over.


## 5x. Equipment, nozzles — and the withdrawal of auto-numbering (phase 3b) — BUILT

ADR-010 §3 is built. The central claim held: **a nozzle is a port owner, so `joints` needed no
change.** A pipe-to-nozzle joint is two ports. The only code that moved was
`JointService::lineFor()`, which takes the line from the **pipe** side (a nozzle has no line) and
returns `FIELD` for the weld (a vessel is set, then the spool is welded to it).

### THE RULE THAT CHANGED — read this before touching any numbering code

Both tagging modes the user asked for were built. Writing the test
`a_deleted_tag_is_never_reissued` killed one of them:

> **`max(sequence)` only sees rows that still EXIST.** Delete `11-P-102` during design and the
> counter hands that tag to the next pump. A tag that has reached a P&ID, a datasheet or a PO must
> never come back.

The only real fix is an issued-number ledger — which **is** the engineering tag list, the
authoritative register on every project. Building a second one guarantees drift.

**The user's ruling: the system validates and refuses duplicates; it never invents.**

This reverses ADR-006 §10.1 and applies to **piping lines too**, which shipped with the same flaw.
`PipingLineService::create()` is now closed behind `allow_auto_line_numbers`, **off by default**;
`register()` (supply the number) is the production path. Seven test files opt in explicitly via
`TestCase::allowAutoLineNumbers()` — the opt-in is visible in the test, not hidden in a default.

### The gap ADR-010 §3.4 predicted and the schema did not have

A 6″ 300# nozzle against a 6″ 150# flange **passed every check**: `ports` had no `rating` column,
so bore, end type and schedule all matched. Added `ports.rating` + `ports.facing`:
rating mismatch ⇒ **INVALID** (no legitimate reading); facing mismatch ⇒ **WARNING** (RF-to-FF is
occasionally deliberate with a full-face gasket). `150#` / `150` / `CL150` normalise to one rating.
**The nozzle's rating is copied onto its PORT** — `PortCompatibility` reads ports, and a rating
living only on the nozzle row would never be compared.

### As built

`equipment`: `UNIQUE (project_id, tag_number)` — the **full** tag, unit included (`11-P-101A` and
`21-P-101A` coexist). `tag_sequence`/`tag_suffix` stored so a register sorts without re-parsing.
`subsystem_id` inherits punch/MC/material machinery free.
`equipment_nozzles`: `UNIQUE (equipment_id, tag)`; **`is_spare`** — a capped spare is not an open
end, and a worklist of false positives stops being read.
Files: `app/Models/{Equipment,EquipmentNozzle}.php` · `app/Services/EquipmentService.php` ·
`app/Support/Numbering/EquipmentTagPattern.php` · migrations `2026_09_18_{170000,173000,180000,184000}_*` ·
`tests/Feature/EquipmentTest.php` (**28**).
Mutation **13 killed / 0 survivors** (`mutate_equipment.sh` + `mutants_equipment.txt`), plus
**three documented equivalents** (M89/M94/M95: `NumberEngine::parse()` re-checks the same three
things, so those guards buy error *quality*, not behaviour).


## 5y. The DELETED state — BUILT

**Why.** Phase 3b withdrew auto-numbering because `max(sequence)+1` cannot see
rows that are gone, so deleting `11-P-102` handed that tag to the next pump.
That fixed the *allocation* side. A hard `DELETE` reopens the identical hole
from the other side: the tag string goes free and somebody retypes a number
that is printed on an issued isometric or stamped on a nameplate.

**Three rulings from the user (2026-09-18), all implemented:**

1. **Scope = anything that carries a tag** — `equipment`, `piping_lines`,
   `equipment_nozzles`. Components and joints are deliberately excluded: they
   are created and destroyed constantly during modelling, no tag ever leaves
   the system with them, and provenance columns would bloat the two largest
   tables for nothing.
2. **The tag stays RESERVED.** The row survives, so the unique index keeps
   refusing a re-register of that tag forever.
3. **Deletion is refused while connections exist.** Detach first — the same
   discipline `separate()` imposes on a FITTED joint.

**Not Laravel `SoftDeletes`.** A global scope would make a withdrawn pump
silently invisible in the register, and "where did P-101A go?" must have an
answer. Deleted rows stay visible and labelled; callers opt out explicitly with
`Equipment::query()->live()` or `LineReleaseState::live()`.

**Schema** (`2026_09_18_200000_add_deleted_state_to_tagged_entities.php`):
`deleted_at` / `deleted_by` / `deletion_reason` on `equipment` and
`piping_lines`; the same three plus `is_deleted` on `equipment_nozzles`;
`DELETED` added to both state CHECKs; three `*_deletion_has_provenance` CHECKs
(either not deleted with all three null, or deleted with `deleted_at` **and**
`deleted_by` set); partial indexes `equipment_live_idx` / `piping_lines_live_idx`.

**A pre-existing CHECK had to be widened — expect this with any new state.**
`piping_lines_afc_has_provenance` read "anything that is not DRAFT must carry
AFC evidence". True when the only other states were AFC_CONFIRMED and RELEASED;
false now, because a DRAFT line withdrawn as a duplicate never passed the AFC
gate. Widened to `release_state IN ('DRAFT','DELETED') OR (evidence)`. The gate
itself is untouched: nothing reaches AFC_CONFIRMED or RELEASED without evidence.

**Behaviour.** `EquipmentService::remove()` / `removeNozzle()` and
`PipingLineService::withdraw()` all demand a non-blank reason. Deleting a vessel
withdraws its nozzles with it. A RELEASED line cannot be withdrawn at all (that
is `revertRelease()`, a visible and reversible act); a line still carrying
modelled components cannot either. `DELETED` is **terminal** — no undelete,
because "deleted on the 3rd… and then what?" is an audit trail that lies.
Re-enter instead, so mistake and correction both stay visible.
`ordinalOf(DELETED)` is **-1, not 3**, so a register sorted by progress does not
show withdrawn lines as the most advanced work on the job.

**Tests.** `tests/Feature/DeletedStateTest.php` — 21 tests. Mutation harness
`mutate_deleted.sh` + `mutants_deleted.txt`: **8 killed / 0 survivors**.

## 5z. 3D model import via PCF (ADR-005 phase 2) — BUILT

**The 3D model cannot be read, and that is settled.** NWD/NWF/NWC are
proprietary, undocumented and Windows-only. The way in is the **Piping
Component File** — plain text, ISOGEN's own input, emitted by Plant 3D,
CADWorx, AVEVA, SmartPlant, Creo and 3DEXPERIENCE. Unlike an MTO it carries
**connectivity**, which is what makes joints possible instead of a parts list.

**Pipeline.** `PcfParser` (pure — no DB, no tenant, testable on a fixture) →
`PcfVocabulary` (PCF names → our 20 templates) → `ModelImportService` →
`model_imports` + `model_import_diffs` → screen at
`/projects/{code}/piping/model-import` (sidebar: "3D model import").

**The importer has NO privileged path into the model.** Components go through
`ComponentService::place()`, joints through `JointService::join()`. An imported
joint is judged by the compatibility engine exactly as a hand-made one is —
otherwise an import could book dia-inch for a butt weld onto a flange.

**The three rulings (user, 2026-09-18):**

1. **Re-import diffs, never destroys.** FITTED joint or `manually_edited_at`
   ⇒ the change is withheld and written as **PROTECTED** with before/after.
   Absent from a new revision ⇒ **REMOVED_IN_MODEL**, reported, never deleted.
2. **Unknown line ⇒ QUARANTINED** (`piping_lines.is_quarantined`), kept out of
   progress, admitted only by `acceptQuarantinedLine()`.
3. **Imported objects stay editable but flagged** — `manually_edited_at` +
   `Component::markManuallyEdited()`.

**Schema** (`2026_09_18_220000_create_model_imports.php`): `model_imports`
(hash, units, revision, counts, RLS FORCEd) · `model_import_diffs`
(CREATED/UPDATED/UNCHANGED/PROTECTED/REMOVED_IN_MODEL/UNMAPPED, CHECK that a
PROTECTED row must state a reason) · `components.{model_import_id,
manually_edited_at, manually_edited_by, pcf_identifier, pcf_type,
spool_identifier}` · `ports.{x_mm,y_mm,z_mm}` · the quarantine columns.

**Joints are built by coordinate.** Ports within **1 mm** are one joint —
geometry engines agree to floating-point noise, real welds are never 1 mm
apart. Exactly two, or nothing: **three at one point means stacked components**,
and picking two would fabricate a weld.

**Tests.** `PcfParserTest` 19 (pure unit, extends PHPUnit's TestCase) ·
`ModelImportTest` 31. Mutation `mutate_pcf.sh` + `mutants_pcf.txt`:
**10 killed / 0 survivors**. Fixture `tests/Fixtures/pcf/line-6-P.pcf`.
Screens `docs/screens/model-import-{en,fa}.png`.


## 5aa. MTO ↔ 3D model reconciliation (ADR-005 §2.1, §2.2)

**The problem.** The line list, the MTO and the 3D model are three statements
about one reality. Given a table each, "how much 6-inch pipe does this line
need?" has three answers and no way to choose between them. One neutral table
whose every row carries its own source is the only arrangement that lets the
question be asked at all.

**The user's ruling — nobody wins automatically.** When two sources disagree
beyond tolerance the system stores BOTH numbers and raises a finding. The
baseline then has **no agreed quantity** for that item until a person picks one
and writes down why. `agreedBaseline()` returns `quantity => null,
disputed => true` while a finding is OPEN. This is deliberate: a gap between
MTO and model usually means the isometric was revised and the MTO was never
re-issued, and a system that silently picks the bigger number turns that into
250 m of the wrong pipe on a truck.

**Tolerance.** One flat percentage for every item kind,
`project_piping_config.baseline_tolerance_pct`, default **2.000**. A difference
at or under it is not a finding.

**Tables.** `baseline_batches` (source_kind `XLSX_MTO|XLSX_LINELIST|PCF|MANUAL`,
`state DRAFT|ACCEPTED|SUPERSEDED`, content_hash, row_count, rejected_count,
warnings jsonb) · `engineering_items` (quantity > 0, confidence 0–100,
external_ref, source_row) · `baseline_discrepancies` (kind
`QUANTITY|MISSING_IN_MODEL|MISSING_IN_MTO`, both claims side by side,
`state OPEN|ACCEPTED_A|ACCEPTED_B|ACCEPTED_OTHER|DISMISSED`). CHECKs enforce
that an ACCEPTED batch has `accepted_at` AND `accepted_by`, and that any
non-OPEN finding has a resolver and a timestamp. RLS ENABLE+FORCE on all three.

**Confidence ladder** (`BaselineBatch::CONFIDENCE`): PCF 90, XLSX_MTO 70,
XLSX_LINELIST 60, MANUAL 40. It orders EVIDENCE only and is **never** a
tie-break — it picks the display source for an item nobody disputes.

**Service** `app/Services/BaselineService.php`: `importMto()` (→ DRAFT),
`accept()` (supersedes the previous ACCEPTED batch of the same source+scope,
never deletes), `takeOffFromModel()` (PIPE summed in M from `length_mm/1000`,
everything else counted EA), `reconcile()` (keys by `item_code|unit`; deletes
only OPEN findings before re-running, so resolved history survives),
`resolve()` (a blank reason is refused), `agreedBaseline()`, `shortageReport()`.

**Why the shortage report exists.** The warehouse screen compares ordered with
delivered and structurally cannot see a shortfall in the ORDER itself: 120 m
ordered against a baseline of 134 m reads as 100% delivered while the line is
14 m short. `shortageReport()` subtracts orders and receipts from the agreed
baseline, and returns **null, not 0**, for a disputed item — an unknown
requirement must not look like a satisfied one.

**No PhpSpreadsheet.** It requires `ext-gd`, absent from this image (and a poor
thing to demand of an air-gapped on-prem install just to read a grid of text).
`app/Support/Tabular/SpreadsheetReader.php` reads XLSX via ZipArchive +
SimpleXML and CSV via `fgetcsv`. It resolves the first sheet through
`xl/_rels/workbook.xml.rels` rather than assuming `sheet1.xml`, and **honours
each cell's `r="E7"` reference** — Excel omits empty cells, so the Nth `<c>` is
not column N and a blank column otherwise shifts every value one place left
while looking entirely plausible.

**Persian numerals.** `(float) "۱٬۲۰۰٫۵"` is `0.0`, silently. `parseNumber()`
normalises Persian/Arabic-Indic digits, the `٫` decimal mark and `٬ ، ,` NBSP
separators before casting. Header aliases are accepted in both languages,
case-insensitive and position-free; **item_code + quantity are mandatory** or
the file is refused outright.

**Screen.** `/projects/{code}/piping/{lineId}/baseline`, linked from the line's
model page. Built around the disagreements, not the import. Both numbers sit
side by side with an empty decision box between them.

**Tests.** `BaselineReconciliationTest` **40 passed / 95 assertions**, including
the full loop through HTTP and a non-participant refusal. Mutation
`mutate_baseline.sh` + `mutants_baseline.txt`: **11 killed / 0 survivors**.

**Two bugs the screenshot caught that the tests had not.** (1) An
`EngineeringItem` saved without `piping_line_id` is invisible for ever — every
read filters by it, so the row sits in an ACCEPTED batch and appears in no
baseline and no shortage report, with no error anywhere. The model now inherits
line and subsystem from the batch in a `creating` hook. (2) Under RTL a leading
minus detaches from its digits: `-14` renders as `14-`. `<bdi>` around the
number and the percentage pins the sign. **Render the page and look at it —
neither failure could have been found by asserting on service return values.**

**Trap found here.** A new column is invisible to `update()` until it is in the
model's `$fillable` — `baseline_tolerance_pct` was silently ignored and the
tolerance test failed as if the logic were wrong. Also: `$model->fresh()`
OUTSIDE a `runAs()` binding fails with `invalid input syntax for type uuid: ""`
under RLS, which reads like a data bug and is really a missing tenant scope.



## 5ab. Inspection & Test Plans (ITP) and incoming material inspection

**What an ITP is.** A table of inspection activities, each row carrying
acceptance criteria, a method, a frequency, the record it produces — and, against
every participating party, an INTERVENTION CODE. That party grid is the point of
the document.

**The four codes, and why they are not a boolean.**

| Code | Meaning | Effect here |
|---|---|---|
| `H` | Hold — کار متوقف می‌ماند | **Blocks release.** The stage waits, indefinitely if necessary. |
| `W` | Witness — دعوت الزامی | Must be invited; if they do not attend, work proceeds lawfully. |
| `M` | Monitor — پایش | May drop in unannounced. Nothing waits. |
| `R` | Review — بررسی مدرک | Document review only. No attendance. |

The whole commercial weight of an ITP sits in H versus W: it decides who pays for
a week of standby, and it is argued over in real claims. A single "requires
inspection" flag erases exactly the fact that matters.

**THE USER'S RULING — a hold blocks RELEASE, never the goods receipt.**
Enforced in `WarehouseService::issue()`, inside the transaction and after the
row lock. The truck arrives at 2am and the TPI signs on Sunday; a system that
refuses the *receipt* gets bypassed with a paper note nobody types in, and then
the warehouse module is a fiction. Blocking the *issue* is both enforceable and
honest: the material is in the store, counted and visible, and simply may not be
welded into the plant yet.

**PIPING and PIPELINE are separate disciplines with separate ITPs**, by the
user's instruction. Not cosmetic: plant piping is B31.3 with lot-based PMI;
cross-country pipeline is B31.4/B31.8 + API 5L, where what matters is heat
traceability, end-bevel condition and coating holiday detection. The partial
unique index `itps_one_active_per_discipline` is per (project, discipline) — if
it were per project, activating the pipeline ITP would silently retire the
piping one and a whole discipline would lose its gate.

**Six tables.** `itp_templates` (library — no project_id, no RLS, `stages`
jsonb is a document copied out on use) · `itps` · `itp_stages` ·
`itp_stage_parties` · `itp_records` · `itp_stage_documents`. RLS ENABLE+FORCE
on the five project tables, same membership-based policy as every other tenant
table.

**A row's references are split in two, on purpose.** `itp_stages.reference` is
free text for **external codes only** (`ASME B31.3`, `EN 10204`) — we do not
own them and they are in nobody's register. **Project** specs, drawings,
procedures and the report form are FOREIGN KEYS in `itp_stage_documents`
(`usage` = SPEC | DRAWING | PROCEDURE | FORM, plus `locator` like `§7.3`), so a
spec reissued at Rev. C cannot leave the ITP silently citing Rev. B. Pass
`pinned_revision_id` only when a stage was agreed against a named revision.
`restrictOnDelete`: deleting a cited spec is refused, not silently unlinked.
`ItpStage::formLabel()` prefers the registered FORM document over the printed
`form_number`, which is the fallback for client forms that never reach the
register. A template cites by `document_number` and an unresolvable one is
**skipped, not fatal** — a standard template legitimately names a spec a given
project never issued; an unresolvable `document_id` IS fatal.

**The party grid is keyed by TENANT TYPE, not company id.** An ITP is written
before the TPI firm is appointed and outlives that firm being replaced; binding
it to a company row would make a signed quality document depend on a procurement
decision that has not happened yet. The concrete company is resolved at
signature and stored on the record.

**`WAIVED` — the state that stops a lie.** A witness point the invited party
skipped is neither passed nor failed: the work lawfully proceeded without them.
Recording it as PASSED asserts an attendance that never happened (expensive in a
claim); recording it as FAILED stops material nothing is wrong with. So it is
its own state, it demands the notice evidence, and **it is refused for a hold
point** — a hold that nobody attended simply stays PENDING. That asymmetry IS
the difference between the two codes, in both the service and a CHECK
constraint.

**Templates are COPIED, never referenced.** Editing the library must not alter
the signed ITP of a running project. `source_template_id` keeps provenance.

**Deferred by the user:** the ITP approval/revision workflow. `state` is
`DRAFT|ACTIVE|SUPERSEDED` with deliberately **no** APPROVED state — an unused
approval state invites code that pretends to check it.

**Fixture gap this closed.** There was no TPI company in the demo data, so
"the TPI holds this point" was untestable and the test silently SKIPPED — worse
than a failure, because it reads as a pass. Added `TPI-BAZRASI` +
`naderi@bazrasi.example` (role `qc_inspector`, not `mc_supervisor`: a
third-party inspector has no business issuing an MC certificate). This moved the
fixture counts, which is why `SeedPathTest` (8→9 companies) and
`IdentityIsolationTest` (9→10 users) changed.

**Screens.** `/projects/{code}/itp` register · `/{itpId}` the full party grid
with a legend · `/itp/lots/{lotId}` the per-lot checklist, which states the gate
first and plainly. The lot screen never offers "waived" on a hold point.

**Tests.** `ItpTest` **43 passed / 92 assertions**. Mutation `mutate_itp.sh` +
`mutants_itp.txt`.

### The ITP is itself a project document (ADR-015 §2.9)

The user's ruling: *«خود مدرک ITP یکی از مدارک پروژه است که در مدارک گنجانده
می‌شود. همچنین فرم‌های گزارشات بازرسی در پیوست ITP می‌آیند»*. The plan had been
carrying its own number and its own DRAFT/ACTIVE/SUPERSEDED lifecycle **beside**
the register — a parallel registry, so the plan never reached a transmittal or
the MDR, and two numbering schemes drifted.

`itps.document_id` → `documents`. The register owns the number, the revisions,
the CDE state and the transmittals; `itps` keeps the discipline, the stages, the
party grid and the gate.

- **`document_id` is nullable, FK `nullOnDelete`.** A plan copied from the
  library before it has a number must still exist, and deleting a register entry
  must never destroy signed records. `Itp::displayNumber()` prefers
  `document->number`; both screens say plainly when a plan is **not** registered.
- **`cdeState()` is deliberately NOT merged into `state`.** `state` is the gate
  (`releaseAllowed()` reads it); `cdeState()` is publication. A plan is commonly
  ACTIVE on site while its document is still SHARED. Merging them would let a
  publication step switch off a hold point.
- **Annexes ≠ row citations.** `itp_stage_documents` usage=FORM answers "which
  form at clause 1.4" — per row. `itp_annexes` is the cover-sheet annex list:
  a 40-row ITP cites a form 40 times and has 3 annexes.
  `syncAnnexesFromStages()` derives one from the other (labels A/B/C in
  first-use order, sequence `(n+1)*10`) so they cannot drift.
  `itp_annexes.document_id` is `restrictOnDelete`.
- `ItpService` now takes `DocumentRegister` in its constructor. `POST
  …/itp/{itpId}/register` registers **and** syncs annexes — one intent, so the
  user cannot leave a registered plan with no annexes.
- `itps.number` survives as the pre-registration working label.

**Trap paid for here:** a new migration run against a seeded DB leaves it empty
until you re-seed — `migrate:fresh --seed`, not `migrate`. And in the mutation
file **a `||` inside a pattern splits the pipe-delimited record**: M424 first
"passed" only because the mangled pattern produced a syntax error. A kill whose
echoed description contains `|` is a false kill — re-check it.

**Tests (after this change).** `ItpTest` **63 passed / 147 assertions**.
Mutation `mutate_itp_doc.sh` + `mutants_itp_doc.txt`: **6 killed / 0 survived**
(M420 double registration · M421 cross-project annex · M422 the plan as its own
annex · M423 duplicate annex · M424 one annex per form not per citation ·
M425 register number beats working label). Screens
`docs/screens/itp-registered-{fa,en}.png`.


## 5ac. The library of codes and standards (ADR-016)

The user's instruction: the system must hold a library of the standards and
codes used on a project, usable from the various modules — the ITP standards
column among them.

Before this, a standard was prose in a `varchar`. A grep found `ASME B31.3`,
`ASME B31.3.` and `API RP` as three strings, and *"which inspections cite
B31.3?"* — the list needed the day a project changes edition — was unanswerable.

**Three tables.**

```
standards           global catalogue: body, designation, normalised (unique),
                    title_en/fa, edition, category, is_active.  NO RLS.
project_standards   (project, standard) -> edition, remarks.       RLS
standard_citations  (citable_type, citable_id, standard) -> clause. RLS
```

- **The catalogue is reference data** — no `project_id`, no RLS, same call as
  `itp_templates` and the packs. 59 entries seeded by `StandardsLibrarySeeder`
  (ASME/API/EN/ISO/ASTM/NACE/AWS/IEC/IEEE/ISA/**IPS**), all with Persian titles.
- **`normalised` is the identity key**: `body+designation` uppercased with
  non-alphanumerics stripped, so `ASME B31.3` / `asme b 31.3` / `ASME-B31.3`
  are one row. `define()` is idempotent on it.
- **The edition is pinned on the ADOPTION, not the catalogue.** The user asked
  for "just an edition field"; put literally on `standards` that would move
  *every* project the day somebody tidies the catalogue. So the catalogue
  carries the current edition and `project_standards.edition` carries what this
  project's contract names. Null = follow the catalogue.
  `isPinnedBehindCatalogue()` flags a deliberate freeze on screen.
- **Citations are polymorphic.** `citable_type` holds our own constant
  (`ITP_STAGE`), **never a FQCN** — a namespace rename must not orphan them.
- **Citing is adopting**: `cite()` adopts on the way through, so the
  applicability list assembles itself from what the documents reference.
- **The free-text column survives** (user's ruling). `structureStandards()`
  runs as each stage is created: what resolves becomes a citation, the rest
  stays in `itp_stages.reference`, and `printedStandards()` concatenates both,
  so the engineer sees the identical column. A fully consumed reference is set
  to **NULL, never `''`**.
- Parsing handles `·`, `;`, `,`, ` / ` and a **carried issuing body**, so
  `ASME B36.10M · B16.9 · B16.5` is three ASME standards.

**Screens.** `/projects/{code}/standards` — the project list on top (with a
citation count per standard; an adopted-but-uncited standard is either dead
weight or an ITP gap), the filterable catalogue below.
`/projects/{code}/standards/{standardId}` — every ITP row citing it, which is
the edition-change worklist. Nav item `project.standards`.
Screens `docs/screens/standards-{fa,usage-fa}.png`.

**Tests.** `StandardsLibraryTest` **31 passed / 80 assertions**. Mutation
`mutate_standards.sh` + `mutants_standards.txt`: **8 killed / 0 survived**
(M430 duplicate spellings · M431 normalisation · M432 pinned edition ·
M433 carried body · M434 withdrawn standard · M435 cite-adopts ·
M436 template structuring · M437 unmatched text survives).

**Traps paid for here:**
- **A view needs `$dir` from its controller.** There is no view composer; every
  controller passes `'dir' => $lang === 'fa' ? 'rtl' : 'ltr'` by hand. Omitting
  it is a 500 whose message is just `Undefined variable $dir`.
- **`assertSee('Codes & standards')` fails** — Blade escapes it to `&amp;`.
- **A mutant can be inert without being wrong.** M437's first form
  (`'reference' => false`) only forced a ternary down the `implode` branch, and
  `implode` on an empty array is `''` — indistinguishable in a nullable column.
  Re-aimed at the branch VALUE (`: null`) it died. **If a mutant survives, check
  whether it changes observable behaviour at all before writing a test for it.**
  It did surface a real gap though: no template row cites a known standard AND
  unknown text, so that path was untested — `test_a_row_that_is_half_known_keeps_both_halves`
  now covers it.
- **`addStage($itp, $user, $data)`** takes the user as the second argument.
  Read the signature; do not guess it.

---

## 5ad. The ITP RLS repair, and who may assign party roles

### The defect (found 2026-09-20, ITP half fixed the same day)

Six ITP tables were created with a policy reading
`project_memberships.company_id` — **a column that does not exist**. Postgres
does not reject this: it resolves the name against the OUTER table, so

```sql
project_id IN (SELECT project_id FROM project_memberships
               WHERE company_id = current_setting('epcc.company_id', true)::uuid)
```

silently became `... WHERE itps.company_id = current_setting(...)` — an EXISTS
over every membership row, i.e. **"rows my own company created"**.

**The impact was not a leak; it was the opposite, and it broke the module's
purpose.** Measured on the fixture: owner (creator) saw 1 ITP, **TPI — a
legitimate member of PH-14 — saw 0**, so the nominated third-party inspector
could neither read nor sign the plan naming it.

**Why 63 green tests missed it:** every ITP test acted as the owner company,
which is also the creating company, so "creator-only" and "member" returned the
same answer. Even the TPI signing tests ran inside `asOwner()`. The new
`test_a_nominated_party_can_sign_while_bound_as_itself` binds the tenant as the
TPI for exactly this reason.

### The repair — `…2026_09_20_140000_fix_itp_tenant_policies.php`

Read access follows the **real** house pattern (copy this one, verified against
`material_lots` and `joints` in `pg_policies` — not from memory):

```sql
USING (
    (NULLIF(current_setting('epcc.project_id', true), '') IS NULL
     OR project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
    AND EXISTS (SELECT 1 FROM project_company pc
                WHERE pc.project_id = {table}.project_id
                  AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid)
)
```

Write access is **deliberately not uniform**:

- the five tables holding the **plan text** (`itps`, `itp_stages`,
  `itp_stage_parties`, `itp_stage_documents`, `itp_annexes`) pin
  `company_id = <acting company>` in `WITH CHECK` — a TPI reads the plan, it
  does not edit it;
- **`itp_records` accepts writes from any project member**, because a signature
  IS a write and the signer is deliberately *not* the record's owner:
  `openForLot()` stamps the record with the lot owner's company and the TPI then
  signs it. Locking that down would make every witness and hold point
  unsignable by the party the row nominates. The real defence is the party grid
  in `ItpService::sign()` (`party_has_no_role_here`).

`down()` restores the **correct** clause, not the broken one — rolling back a
repair must not reinstate a defect.

**Still outstanding (same broken clause, 5 tables):** `baseline_batches`,
`baseline_discrepancies`, `engineering_items`, `model_imports`,
`model_import_diffs`. The user chose ITP first.

### Who may set the roles of the project parties

User's ruling: **only the employer (OWN), the management contractor (MC) and
the consultant (PMC)**. Declared once, on `TenantType`:

```php
TenantType::MAY_ASSIGN_PARTY_ROLES = [OWN, MC, PMC];
TenantType::mayAssignPartyRoles(?string $tenantType): bool
```

Enforced in `ItpService::assertMayAssignPartyRoles()`, called from
`createFromTemplate()` (a template carries a full grid, so copying one IS
assigning everybody their hold points) and from `addStage()` **only when a
grid is actually supplied** — adding a bare row is drafting, assigning
interventions is an allocation of authority.

This closed a real hole: before it, **there was no authorisation on ITP
authoring at all**. Demonstrated on the fixture — a TPI created a 5-row plan
and assigned every party its intervention codes.

Checked against the acting company's `tenant_type`, not a role code: the rule
is about which FIRM this is, not what an administrator granted a user inside it.

**Trap:** the guard first built the message with a `$lang` argument while
`__()` reads the app locale — producing an English sentence with a Persian noun
inside it. House convention is `__()` with no locale argument; `$lang` is only
for picking from bilingual arrays.

**Tests.** `ItpTest` **72 passed / 172 assertions** (63 → 72). Mutation
`mutate_rls_fix.sh` + `mutants_rls_fix.txt`: **6 killed / 0 survived**
(M440 TPI added to the list · M441 MC+PMC removed · M442 guard removed from
`createFromTemplate` · M443 guard ignores the list · M444 read policy widened
to any member · M445 write check unpinned) — M444/M445 mutate the **migration
SQL** itself. Full suite **1112 / 3696**.

---

## 5ae. The third-party inspector's duties (user, 2026-09-20) — SCOPE AGREED, NOT YET BUILT

The user stated what a TPI actually does on an Iranian EPCC project:

1. **approve or reject** executed work and **incoming project material**;
2. **sign the daily inspection reports**;
3. **issue NCRs, certificates and inspection reports**;
4. **evaluate / interpret the tests** carried out on the project.

**All of it is bounded by the role and the extent of the inspection scope
defined in the ITP.** The user clarified "inspection scope" means: *the
activities named in the ITP on which a role has been defined for the TPI* —
it is NOT a separate permission table. So the authority question
"may this TPI reject this item / raise this NCR?" reduces to
"does this TPI hold a party row on that ITP stage?", which the schema already
answers (`itp_stage_parties` unique on `itp_stage_id, tenant_type`, and each
`itps` row is per-discipline). **Do not build a second source of truth.**

### Decisions taken

- **NCR issuer:** any party that inspects **that row** — TPI, PMC, MC, OWN
  where the party grid names them. The contractor and the vendor cannot raise
  an NCR against themselves.
- **Build order:** **NCR first**, then daily inspection reports. The failure
  route is currently incomplete — a row can be recorded FAILED and nothing is
  born from it.

### Standing instruction

**The user will supply the scenarios step by step and we are currently only at
the INCOMING MATERIAL stage** — construction activities are not in scope yet.
Build what the current scenario requires; do not generalise ahead of it.

### Already in place (do not duplicate)

`MaterialInspection` (`project_id, company_id, purchase_order_id, number,
sequence, result, stage, findings_en/fa, inspected_by, inspected_at`) already
models an inspection verdict against a purchase order, and its `company()` is
documented as "the inspecting body: TPI, PMC, MC or the owner".
`ItpService::sign()` already records a per-row verdict with
`signed_tenant_type`. NCR must connect to these, not replace them.

**Nothing of this section is implemented yet.**

---

## 5af. The material library (ASME B31.3 Table A-1) — PIPE GROUP BUILT

A library of the materials most used on projects, grouped as B31.3 Table A-1
groups them. Built as a **reference pack**, not a table.

### Why a pack and not a table — the opposite answer to the standards library

§5ad's sister question got the opposite answer for a concrete reason. The
standards catalogue could not become a file because `project_standards` and
`standard_citations` hold real foreign keys to it with `restrictOnDelete`.
**Nothing points at materials** — there was no material library at all, so
there is no integrity to lose. The data is universal (B31.3 is the same on
every project), users do not edit it, and it changes with a release of the
software rather than with a customer. That is the `pipe-schedules.ref.json`
profile exactly.

### Shape: group → spec → grade → product form

`standards-packs/reference/materials-pipe.ref.json` (v1.0.0) —
**8 specs, 36 grades, 7 product forms** in the `PIPE` group:
A106 · A53 · A333 · A335 · A312 · A358 · A790 · API 5L.

Three things in that shape are load-bearing:

1. **A group declares which component types it may supply.** A flange is a
   forging, so the pipe group lists only `PIPE`, `NIPPLE`, `SWAGE`. Without
   this the second dropdown offers every group and a user can build a flange
   out of A106.
2. **A grade is identified by code AND class together.** A358 grade 304 exists
   as Class 1 (radiographed) and Class 3 (not) — same code, different
   acceptance. `MaterialLibrary::gradeKey()` is the single place that spells
   the pair (`304 CL 1`). Keying on the code alone would let one overwrite the
   other.
3. **The product form is a fourth dropdown, not a property of the grade.** The
   form carries the weld joint quality factor Ew used in the B31.3 wall
   thickness calculation: SMLS 1.00 · ERW 0.85 · EFW 0.80 · EFW_RT 1.00 ·
   FBW 0.60 · DSAW 0.95 · HCW 0.85. ASTM A53 Type S/E/F is the same steel in
   three forms with three factors; folding the form into the grade would
   silently pick one of them. **An unknown form returns `null`, never 1.0** —
   defaulting would delete the penalty a welded seam is supposed to carry.

**A spec may have zero grades** (A105, in a group not yet built).
`specHasGrades()` is the explicit contract so the UI disables the control
rather than rendering an empty dropdown, and a null grade on a spec that HAS
grades is refused, not silently accepted.

### Nothing is verified, and allowable stress is absent

Every property ships `verified: false`. `verifiedProperties()` returns only
checked ones — today always empty — so a calculation reads that and refuses to
run rather than quoting an unchecked number. **Allowable stress (S) tables are
not in the pack at all**: they are copyrighted, and a wrong S value corrupts a
wall thickness with nothing reporting it. The validator fails the build if
`allowableStress` ever appears.

### What an MTO row stores

The four **strings** — group, spec, grade key, product form — never an index or
a row id, so the pack can be reordered without touching project data.
`isKnown()` says whether a stored selection still resolves; `describe()`
returns `null` rather than a half-built label so a caller can tell "not set"
from "set to something withdrawn".

### Round 2 — the rest of the pipe group (v1.1.0)

**13 specs / 82 grades / 9 product forms**, in two groups.

**Pipe rolled from plate: A671 · A672 · A691.** Welded from plate rather than
made as pipe, so they are `EFW`/`EFW_RT` only and can never be seamless. Each
grade records `basePlateSpecification` (A672 C70 → `ASTM A516 Grade 70`),
because an inspector receiving the pipe checks the **plate** certificate. The
class (`CL 12` / `CL 22` / `CL 32`, and `CL 42` on A691) encodes post-weld heat
treatment plus degree of radiography — the same reason A358 needed
`classOrType`, now the dominant case: 78 of the 82 grades in the metallic group
are (code, class) pairs.

**A second group, `PIPE_NONMETALLIC`** — ISO 14692 GRE/GRP and ISO 4427 PE.
Both groups answer `groupsForComponentType('PIPE')`, because a firewater line
really can be HDPE, so **a caller must branch on `metallic`, never on the group
code.**

### The trap: a non-metallic form has NO weld joint quality factor

ASME B31.3 **Chapter VII** rates non-metallic piping from a qualified pressure,
not from S × E × W. Their forms (`FW` filament wound, `EXTRUDED`) therefore
carry `weldJointQualityFactor: null`.

`(float) null` is **0.0** — a perfectly plausible-looking factor that would
zero the pressure a GRE line may hold. `formIndex()` guards the cast explicitly
and mutant **M460** proves the guard is load-bearing.

Since both an unknown form and a non-metallic one return `null`,
**`isKnownProductForm()`** exists to tell them apart; without it a caller either
rejects valid GRE or swallows a typo. `isMetallic($group)` gates the whole
S × E × W world.

### Galvanising is orthogonal to the product form

A53 Type S, E and F can **each** be black or hot-dip zinc coated. Folding the
coating into the form would need six entries instead of three plus two, so
`surfaceConditions` is its own axis (`BLACK` / `GALV` / `NONE`), offered only
where a spec declares more than one. Non-metallic specs are `NONE`; the
validator refuses a galvanised GRE and a steel pipe with no surface.

### Deferred, with reasons recorded

- **Tube (A269 instrument, A213 exchanger)** — user: a separate group later.
  Note there is **no `TUBE` component template** (only 20 exist, all piping),
  and tube is sized by OD, not NPS, so it needs its own size handling.
- **Sour service (NACE MR0175 / ISO 15156)** — user: later, at material
  inspection. **Nothing anywhere in the codebase mentions it today**, and it is
  not in the 59-standard catalogue. It matters for the launch market: South
  Pars and most Iranian gas is sour. Conclusion from the discussion: compliance
  is a property of the **heat/lot** (hardness, heat treatment, chemistry), not
  of the grade, so it belongs on the incoming-material record rather than here.

### Files

- `standards-packs/reference/materials-pipe.ref.json` (v1.1.0)
- `standards-packs/reference/schema/materials-pipe.schema.json` — `metallic`
  and `surfaceConditions` required; `weldJointQualityFactor` is `["number","null"]`.
- `standards-packs/validate_packs.py` → `check_materials()`. Ten failure modes,
  **every one provoked deliberately and each fired**: unknown component type ·
  undefined product form · a property claiming `verified: true` · duplicate
  (code, class) · numeric with no unit · smuggled `allowableStress` · metallic
  spec with a factorless form · non-metallic spec with a welded form ·
  galvanised non-metallic · metallic spec with surface `NONE`.
- `app/app/Support/Reference/MaterialLibrary.php` — singleton in
  `EpccServiceProvider`. New: `isMetallic()`, `isKnownProductForm()`,
  `surfaceConditionsFor()`; `groups()` and `groupsForComponentType()` now carry
  `metallic`.
- `app/tests/Feature/MaterialLibraryTest.php` — **28 passed / 414 assertions**.
  Mutation M450–M455 and M460–M463: **10 killed / 0 survived**.

### Round 3 — forgings (v1.2.0), and the pack is renamed

`materials-pipe.ref.json` → **`materials.ref.json`** (refId `materials`,
schema `materials.schema.json`). It stopped being a pipe pack the moment
forgings arrived; the PHP const, the validator key and the test path moved with
it.

**Three groups / 18 specs / 131 grades / 10 product forms.**
`FORGINGS` adds A105 · A350 · A182 · A694 · A707 (49 grades) and claims twelve
component types: FLANGE, OLET, NOZZLE, COUPLING, UNION, BLIND, SPECTACLE_BLIND,
CAP, plus ELBOW/TEE/CROSS/REDUCER — B16.11 socket-weld and threaded fittings
**are** forgings. Butt-weld fittings will come from A234 later, so those four
types will be supplied by two groups; that is correct, not a clash.

**The zero-grade case is finally real.** A105 has no grades, so the branch that
was only reasoned about now executes: `grade()` returns `[]` and `isKnown()` is
**true** for a null grade there — the exact mirror of A106, where null must be
refused. Both directions are tested and mutants M470/M471 kill the branch.
`A105 Gr B` — which people really do write on requisitions — is still refused.

**A182 is ONE spec covering two ASTM tables.** A purchase document says
`A182 F316`, never "A182 austenitic", so splitting it would break the match to
the paperwork. The family (`FERRITIC` / `AUSTENITIC` / `DUPLEX`) is a grade
**property**, and it is what welding and PWHT branch on: F22 is P-No 5A,
F316 is P-No 8, F53 is P-No 10H. F51/F53/F55 sit in the austenitic ASTM table
but are **duplex** and are filed as such.

**A694 records `matchingLinePipeGrade`** — F60 flanges go on X60 pipe, and both
sides are now in the library, so the pairing is checkable rather than
remembered.

### A rating is NOT a material property

A105 is A105 whether the flange is 150# or 1500#. The rating belongs to the
component under B16.5/B16.47 and **`piping_classes.pressure_rating` already
holds it** (`string(16)`, values like `150#`, `300#`). A copy here would be a
second truth that drifts. The validator refuses any grade property whose name
contains `rating`, and a test sweeps all 131 grades.

**Trap hit while writing that rule:** the first version searched the whole
serialised pack and fired on the *conventions* prose, which is allowed to name
the thing it forbids. Check the **grade properties**, not the JSON blob.

**Test corrected, not deleted:** `test_a_component_type_the_group_does_not_supply_offers_no_group`
asserted that FLANGE yields *no* group. That was only true while no group
supplied flanges. Rewritten to assert what it actually meant — the **pipe**
group must never appear for a flange — plus GASKET, which nothing supplies yet.

### Files (updated)

- `standards-packs/reference/materials.ref.json` (v1.2.0)
- `standards-packs/reference/schema/materials.schema.json`
- `standards-packs/validate_packs.py` → `check_materials()`, **12 rules**, all
  provoked deliberately: unknown component type · undefined product form ·
  `verified: true` · duplicate (code, class) · numeric with no unit · smuggled
  `allowableStress` · metallic spec with a factorless form · non-metallic with a
  welded form · galvanised non-metallic · metallic with surface `NONE` ·
  unknown `family` · a grade carrying a rating.
- `app/app/Support/Reference/MaterialLibrary.php`
- `app/tests/Feature/MaterialLibraryTest.php` — **39 passed / 1145 assertions**.
  Mutation M450–M455, M460–M463, M470–M471: **12 killed / 0 survived**, plus an
  inert control mutant (M472) that survived as designed, proving the harness
  discriminates.

### Round 4 — wrought fittings (v1.3.0)

**Four groups / 23 specs / 168 grades.** `FITTINGS` adds A234 · A403 · A420 ·
A815 · A860 (37 grades).

**The first deliberate overlap.** Fittings claim ELBOW, TEE, CROSS, REDUCER,
CAP and OLET — which `FORGINGS` already claims. That is the feature: a
butt-weld A234 elbow and a socket-weld A105 one are both elbows, and **the
material group is how the user says which**. The cascade offers both groups and
the user picks.

The guard that makes the overlap safe: **one specification must not appear in
two groups**, or a stored selection could not say where it came from. New
validator rule, provoked and fired.

**A403's WP-S / WP-W / WP-WX stay on the product-form axis.** The
specification's classes mean seamless, welded, and welded with full
radiography — the same fact `SMLS`/`EFW`/`EFW_RT` already carry. Putting them in
`classOrType` would create two parallel axes for one fact **and the Ew lookup
would miss them**, exactly the mistake avoided with A53 Type S/E/F. A test
asserts every A403 grade has a null class.

**The sets now close across groups.** Three tests check that a real line can be
specified end to end without leaving the library:
- line pipe: X60 pipe · F60 flange (A694) · WPHY-60 fitting (A860) — the last
  two both record `matchingLinePipeGrade`;
- low temperature: A333 Gr 6 pipe · A350 LF2 flange · A420 WPL6 fitting, every
  A420 grade impact tested like the pipe it joins;
- every family present in pipe (ferritic, austenitic, duplex) has a fitting
  counterpart.

User declined recording the base material a fitting is made from (A234 WPB from
A106 Gr B or A516). Not carried.

### Files (updated)

- `standards-packs/reference/materials.ref.json` (v1.3.0)
- `standards-packs/validate_packs.py` → `check_materials()`, **13 rules**, each
  provoked deliberately.
- `app/tests/Feature/MaterialLibraryTest.php` — **47 passed / 1493 assertions**.
  Mutation to date M450–M455, M460–M463, M470–M471: **12 killed / 0 survived**,
  plus inert control M472 which survived as designed.

### Round 5 — castings (v1.4.0)

**Five groups / 29 specs / 201 grades.** `CASTINGS` adds A216 · A217 · A352 ·
A351 · A995 · A494 (33 grades) and claims **VALVE, CONTROL_VALVE and
THREE_WAY_VALVE, which no group supplied before**, plus cast NOZZLE/ELBOW/TEE/
REDUCER/CAP.

**Only the valve BODY is here.** Trim (API 600 trim numbers, seat and stem) is a
property of the valve as an engineered item — it sits with the B16.34 rating
and the maker's model, not in the MTO material cascade. User's decision,
recorded in `conventions.valveTrim`.

### The trap: Ew = 1.0 on a casting does NOT mean "no derating"

A casting is poured to shape, so there is no longitudinal seam to discount and
the `CAST` form carries `weldJointQualityFactor: 1.0`, exactly like `SMLS` and
`FORGED`. **But ASME B31.3 para 302.3.3 applies a separate CASTING QUALITY
FACTOR Ec**, which depends on the examination the casting received and is *not*
held in this pack. Anyone reading Ew = 1.0 as "castings are not derated" would
be wrong. Recorded in `conventions.castingQualityFactor` and asserted by a test
so the note cannot be quietly deleted.

### Cast and wrought grades name each other

A requisition says `TP316`; the material arrives marked `CF8M`. They are the
same alloy in different processes, and confusing them at incoming inspection is
a real event. Cast grades therefore carry **`wroughtEquivalent`**, and a new
validator rule requires that the named grade **actually exists in the library**
— proprietary names (Monel 400, Hastelloy C) are exempt because they have no
ASTM grade here.

**That rule immediately caught a genuine error in my own data:** A351 CG8M was
written as equivalent to `TP317`, which the library does not hold. Corrected to
`UNS S31700`, which is unambiguous. This is the second time a validator rule
has caught the hand that wrote it.

**A494 introduces the `NICKEL` family** — Monel, Hastelloy, Inconel — with
P-numbers 41–44.

The cold-service set now closes completely: A333 Gr 6 pipe · A350 LF2 flange ·
A420 WPL6 fitting · **A352 LCC valve body**, every A352 grade impact tested.

### Files (updated)

- `standards-packs/reference/materials.ref.json` (v1.4.0)
- `standards-packs/validate_packs.py` → `check_materials()`, **14 rules**, each
  provoked deliberately.
- `app/tests/Feature/MaterialLibraryTest.php` — **56 passed / 1825 assertions**.

### Round 6 — bolting (v1.5.0), and the first portless component

**The user overruled the recommendation, and was right to.** Recon said: PCF
never models a bolt as a component (a `BOLT` record carries `CO-ORDS` but no
`END-POINT`, and bolting normally rides as `BOLT-DIA` / `BOLT-QUANTITY` /
`BOLT-LENGTH` attributes on the flange); Plant 3D keeps bolt sets off the tool
palette and generates them from the flanged joint; our own
`PcfVocabulary::NOT_MODELLED` already lists `BOLT`. The recommendation was
therefore to attach bolting to the flange and leave `componentTypes` empty.

The user rejected that on practice, not theory: **projects order bolting in
excess of the calculated need** — studs are dropped, galled and cut wrong — so
a quantity derived from the flange count under-orders every time. Bolting is a
take-off line in its own right. What the system owes instead is a
**sufficiency check**: are the bolts and nuts entered NOT LESS than the flanges
require?

**A surplus is never an error here. A shortfall always is.** That sentence is
the whole design.

#### The portless component

`BOLT` and `NUT` are the first templates with `ports: []`. A bolt does not
connect two runs of pipe; it closes a joint.

- Schema `ports.minItems` relaxed 1 → 0. **Checked every consumer first:**
  `PipeReference::portCount()`, `ComponentController:203`,
  `ComponentService:112/338`, `ModelImportService:530` all iterate `ports`
  unconditionally, so a zero-port template creates zero ports and nothing
  divides by the count. `createPortsFromTemplate()` needed no change at all.
- Empty must stay **deliberate**, so the validator now refuses a portless
  template that is not `BOLT`/`NUT`, one with no explanatory `note`, and one
  that is not `SAME_AS_FIRST`. All three provoked.
- Materials rule 16 ties the two packs together: the `BOLTING` group's
  `componentTypes` must equal exactly the set of portless templates. Give
  `BOLT` a port in future and the pack fails rather than drifting.

#### The group

`BOLTING` — 5 specs / 30 grades, one product form `HEAT_TREATED` (a fastener is
neither seamless nor welded; offering SMLS would invite a meaningless Ew).

| Spec | Grades | Note |
|---|---|---|
| A193 | 8 | High-temperature bolts. B7 is the industry default |
| A194 | 8 | Nuts. 2H is the industry default |
| A320 | 6 | Low-temperature bolts. L7 completes the cold-service set |
| A453 | 3 | Grade 660 (A286), where B16 runs out |
| A563 | 5 | Carbon/alloy nuts — structural, not pressure piping |

Two facts made load-bearing by tests:

1. **Every grade declares `fastenerRole` BOLT or NUT** (validator rule 15).
   Ordering A194 2H as a bolt is a real requisition error, and the reconciler
   counts the two separately.
2. **The M grades are separate grades, not variants.** B7M is the same alloy as
   B7 held to 235 HBW instead of 321 HBW for sour service — and therefore
   690 MPa instead of 860. A test asserts the strength is *lower*, because the
   hardness ceiling costing strength is the entire trade. Substituting one for
   the other is a sulphide stress cracking failure years later.

`A320 L7` completes the cold-service set: A333 Gr 6 pipe · A350 LF2 flange ·
A420 WPL6 fitting · A352 LCC body · **L7 bolting**. A B7 stud on that joint is
a cold fracture.

#### The sufficiency check

New pack `standards-packs/reference/flange-bolting.ref.json` (v1.0.0) — ASME
B16.5, **5 ratings × 97 size rows**: what ONE joint needs. New class
`app/app/Support/Reference/FlangeBolting.php`, bound as a singleton.

`shortfalls($required, $supplied, $nutsPerBolt)` returns findings at three
severities:

- `SHORTFALL` — genuinely not enough. Bolt count, nut count, bolt too thin.
- `MISFIT` — a bolt **fatter** than the hole. Surplus quantity is fine; surplus
  diameter does not enter a drilled hole.
- `ADVISORY` — length. **Bolt count and diameter are hard B16.5 facts and are
  safe to assert; length is not** — it depends on facing and gasket thickness,
  and the pack figures carry `verified:false` like every other unverified
  number in this system. `isSufficient()` therefore ignores advisories.

Three traps deliberately handled:

- **A joint is two flanges and ONE bolt set.** An MTO counting a set per flange
  orders double, so the API takes *joints*. `jointsFromFlangePairs()` **refuses
  an odd count** rather than rounding — an odd flange is a blind, a valve or a
  nozzle that has not been accounted for.
- **Nuts are counted against the bolts actually ordered**, not the bolts
  required. Order 200 studs where 120 are needed and you still need 400 nuts.
  Surplus studs with exact nuts is a common slip.
- **An unanswerable joint is a finding, not silence.** A 48″ flange returns
  `UNKNOWN_JOINT`; letting it pass would report a take-off as sufficient
  precisely where the reference is blind.

#### Validator

`check_flange_bolting()` — 8 rules, every one provoked: duplicate rating ·
size not in `pipe-sizes` · out of size order · bolt count falling as the flange
grows · length shorter than twice the bolt diameter · `verified:true` ·
diameter shrinking as the class rises · a missing 150/300/600 rating.

**One rule was written wrong and the data was right.** "A bolt cannot be wider
than the pipe it clamps" failed on ½″ 1500#, which really does take a ¾″ bolt —
bolt circle diameter has nothing to do with bore. It was replaced with the only
monotonic direction the table actually has: *hold the size still, raise the
class, and the bolt never gets thinner.* Bolt **count** is not monotonic (24″
1500# uses 16 bolts of 3½″, fewer and far fatter), and within one rating the
diameter is not either (300# goes 1½″→¾″ then back to ⅝″ at 2″). Third time a
validator rule has caught its author; first time the author was wrong about the
standard rather than the data.

### Round 6a — stud bolt vs machine bolt

Asked whether the difference was understood, and it exposed two real gaps in
what had just been committed.

**The difference.** A stud bolt is a headless threaded rod with a nut at EACH
end, measured first thread to last thread excluding the chamfers (B18.31.2,
the "LEP" convention). A machine bolt has an integral hex head, takes ONE nut,
and is measured from UNDER THE HEAD. They are not interchangeable and their
lengths are not comparable.

**Gap 1 — the nut ratio was a free parameter.** `shortfalls()` took
`int $nutsPerBolt`, so a caller could declare a studded joint and then pass 1,
halving the nut order with nothing to catch it. The template's
`STUD`/`MACHINE` qualifier was not connected to it at all. Now the argument is
a **style** (`STYLE_STUD` / `STYLE_MACHINE`) and the ratio is derived; an
unknown style throws. The qualifier list on the `BOLT` template was trimmed to
those two — `CAP` has no place on a B16.5 flange.

**Gap 2 — one length column for two kinds of fastener.** `minLengthMm` was the
stud figure only. A machine bolt for the same joint is ~10–25 mm shorter (6″
300#: 120 mm stud, 110 mm machine), so a machine bolt was being measured
against the stud column and reported short when it was not. **A false warning
is worse than no warning** — it teaches people to ignore the real ones. Added
`minLengthMachineBoltMm`; the two columns are never compared with each other.

**And a rule that is not about length at all.** B16.5 permits machine bolts
only at **Class 150 and 300**, raised or flat face; from 600# up, and for any
ring joint, the joint must be studded. That is reported as `MISFIT`, not a
shortfall: nothing is missing, the wrong fastener has been specified, and
ordering more of it does not help. The machine column is therefore **absent**
above 300# — absent meaning *not permitted*, not *unknown*.

Validator gains 3 rules (transposed columns · a machine length where the class
forbids it · a missing machine length where it is allowed), all provoked.
Pack → v1.1.0, 40 of 97 rows carry the second column.

### Files (Round 6)

- `standards-packs/reference/materials.ref.json` (**v1.5.0**) — 6 groups /
  34 specs / 231 grades / 12 forms.
- `standards-packs/reference/flange-bolting.ref.json` (**new**, v1.1.0) +
  `schema/flange-bolting.schema.json` — 5 ratings × 97 rows, 40 of them
  carrying `minLengthMachineBoltMm`.
- `standards-packs/reference/component-templates.ref.json` — **22** templates,
  still 41 port definitions.
- `standards-packs/reference/schema/component-templates.schema.json` — `ports`
  `minItems` 0.
- `standards-packs/validate_packs.py` — `check_materials()` **16 rules**,
  `check_flange_bolting()` **11 rules**, portless-template rules in the template
  check.
- `app/app/Support/Reference/FlangeBolting.php` (**new**) + binding in
  `EpccServiceProvider`.
- `app/tests/Feature/FlangeBoltingTest.php` (**new**) — **31 passed /
  120 assertions**.
- `mutate_bolting.sh` + `mutants_bolting.txt` (**new**) — M480–M495,
  **16 killed / 0 survived**.

### Round 7 — plate (v1.6.0), and the deletion of provenance

**The user's ruling came first and reshaped the round:** *"there is no need at
all to record what the final material used to be."* A material is recorded as
WHAT IT IS.

`basePlateSpecification` was free text on **42 grades** of A671/A672/A691 —
strings like `"ASTM A516 Grade 70"` resolving against nothing. The plan had
been to validate those strings against the incoming plate group. Instead they
were **deleted**, and the reasoning is better than the plan was: `ASTM A672
C70 CL 22` already names the acceptance the finished pipe must meet, and the
plate it was rolled from adds nothing a requisition, an inspection or a wall
thickness calculation can act on. **A heat's history lives on the mill
certificate, which belongs to the LOT, not to the library.**

Validator rule 17 refuses its return under any of six names
(`basePlateSpecification`, `baseMaterial`, `madeFrom`, `rolledFrom`,
`forgedFrom`, `parentMaterial`), at spec level and grade level. A `PHPUnit`
sweep asserts the same across all 272 grades. This also retroactively settles
the `madeFrom` idea that was declined for fittings — same principle, now
enforced.

The old test was **rewritten to the new intent, not deleted** (the standing
rule): it now asserts the key is absent and that the material is still fully
described by what it is.

#### The group

`PLATE` — 8 specs / 37 grades, one product form. Supplies **BLIND** and
**SPECTACLE_BLIND** alongside `FORGINGS`: a B16.48 line blank is cut from
plate, a blind flange is forged, and the user picks the route.

| Spec | Grades | Note |
|---|---|---|
| A516 | 3 | The default. Gr 70 is what almost every spectacle blind is cut from |
| A515 | 2 | Coarse grain: better hot, worse cold. **Not a substitute for A516** |
| A285 | 3 | Low-pressure vessels and tankage |
| A573 | 3 | Structural-origin plate with specified notch toughness |
| A537 | 2 | CL 1 normalised / CL 2 quenched+tempered — class is identity |
| A204 | 3 | C-Mo, the first step into elevated temperature |
| A387 | 11 | Cr-Mo. Gr 91 exists **only** as CL 2, P-No 15E |
| A240 | 10 | Stainless + duplex. The plate side of A358 |

`PLATE_STRUCTURAL` — A283 alone, 4 grades, **supplying nothing at all**.

#### Three things the validator caught in my own work

1. **PLATE was given `Ew = null`.** Wrong by our own precedent: `CAST` and
   `FORGED` carry 1.0 because Ew derates a *longitudinal seam* and they have
   none — a flat plate has none either. `null` is reserved for forms designed
   on a different basis (the non-metallic route), and `(float) null` is `0.0`,
   which would **zero** a pressure calculation rather than leave it alone. Now
   1.0, with a test asserting the real factor (0.80 / 1.00) appears only when
   the plate becomes pipe.
2. **A240 was given surface `NONE`.** Every other stainless spec in the library
   says `BLACK`, meaning uncoated mill finish. `NONE` belongs to the
   non-metallic route. The thing that must never be offered is `GALV`, and it
   is simply absent.
3. **A283 sat next to A516 in the same group — so the cascade would have
   offered structural plate for a line blank.** New rule 18 refuses any group
   that mixes `pressureRetaining: false` grades with pressure-retaining
   component types, and refuses a spec that marks only *some* grades structural
   (the cascade offers the spec before the grade, so the whole spec must
   agree). A283 moved to its own group.

That is the fourth, fifth and sixth time a validator rule has corrected its
author.

#### A second portless-style relaxation

`componentTypes` `minItems` 1 → 0, exactly as `ports` was relaxed for `BOLT`.
And exactly as there, **empty must be deliberate**: rule 19 allows an empty
list *only* when every grade in the group is `pressureRetaining: false`.
Anything else with no component types is a group someone forgot to wire up,
invisible to every dropdown.

### Files (Round 7)

- `standards-packs/reference/materials.ref.json` (**v1.6.0**) — 8 groups /
  43 specs / **272 grades** / 13 forms.
- `standards-packs/reference/schema/materials.schema.json` — `componentTypes`
  `minItems` 0.
- `standards-packs/validate_packs.py` — `check_materials()` **19 rules**.
- `app/tests/Feature/MaterialLibraryTest.php` — **70 passed / 4246
  assertions**.

### Round 8 — gaskets (v1.7.0), and the metallic/pressure-designed split

The group deferred one round earlier, now built. The user chose all three
families, chose **constituents recorded as properties that must resolve**, and
chose to record the permitted facing now and validate it later.

#### The multi-material problem, solved without touching the cascade

A spiral wound gasket is four materials. The cascade is four steps deep and
returns one. **The resolution: a vendor sells "CGI, 316 winding, graphite
filler, CS outer ring" as ONE catalogue item, so that IS the grade** — and the
constituents hang off it as properties. Nothing about the cascade changed, and
it stays four steps deep for every component in the system.

What makes the constituents real rather than free text is **validator rule 20**:
every `*Specification` / `*Grade` pair must resolve against a grade the library
actually holds. `ASTM A240` + `316` resolves; `ASTM A999` does not; a
specification without its grade is refused. Six pairs are covered (winding,
outer ring, inner ring, core, ring, jacket) and a PHPUnit sweep asserts the
same from the other side. This is the `wroughtEquivalent` discipline from the
castings round, which caught a genuine error in its author's data the first
time it ran.

`outerRingMaterial` is separate from `windingMaterial` because **the difference
is a real failure**: a carbon steel centring ring on a 316 winding is the
standard cheapest build, and in a chloride service the ring corrodes away and
the gasket loses its centring while the winding is untouched. Both builds are
in the pack (`SW-316-GR` and `SW-316-GR-SSR`) and a test asserts they differ.

| Spec | Grades | Note |
|---|---|---|
| B16.20-SW | 10 | Spiral wound. Style (CG/CGI/R) is the `classOrType` |
| B16.20-KP | 2 | Kammprofile |
| B16.20-RJ | 5 | Ring joint R / RX / **BX, not interchangeable** |
| B16.21 | 7 | Non-metallic flat, incl. one full-face |
| B16.21-JKT | 2 | Metal jacketed |

Two new product forms: `ASSEMBLED` and `DIE_CUT`, both `Ew = null`.

#### The distinction the gasket forced into existence

**`metallic` answers "is this steel?"; `pressureDesigned` answers "is this
sized by the B31.3 wall thickness formula?"** For eight groups the two
coincide, which is why the library conflated them until now. A spiral wound
gasket is unquestionably steel and has no wall, no allowable stress, no seam —
it is sized by the flange it seats in.

The Ew and surface-condition rules now key on `pressureDesigned`. Note
`PIPE_NONMETALLIC` reaches `false` by a **different route**: GRE *is* a
pressure boundary, but Chapter VII rates it from a qualified hydrostatic basis
instead. Two groups, one flag, two reasons.

**That flag is also exactly what an editor would flip to silence a complaint** —
and a mutation setting `pressureDesigned: false` on the PIPE group **survived**,
silently disabling every Ew guard in the file. Rule 22 now refuses `false` on
any metallic group that supplies a pressure boundary component. The survivor
is the reason the rule exists.

#### Three more corrections by the validator

1. `NON_METALLIC` was offered as a `family` for rubber. Refused — `family` is a
   **metallurgical** axis and widening the enum would have destroyed its
   meaning for the 246 grades that sit on it. Non-metallic grades now carry **no
   family at all**; absent is the honest answer.
2. The spec-code pattern `^[A-Z0-9]+$` refused `B16.20` — every spec until now
   was ASTM. Widened deliberately (`B16.20-SW` passes, `b16.20` and `B16.20 SW`
   still fail).
3. A test asserting *"nothing supplies a GASKET"* failed. **The test was right
   about the world and the world changed** — rewritten to the intent (a gasket
   comes from the gasket group and nowhere else), never deleted.

Rule 21 additionally requires every gasket grade to state a temperature ceiling
or hardness limit, and to say which facing it seats on. **The filler sets the
ceiling, not the flange**: PTFE gives up at 260 °C where graphite holds to 650.

### Files (Round 8)

- `standards-packs/reference/materials.ref.json` (**v1.7.0**) — 9 groups /
  48 specs / **298 grades** / 15 forms.
- `standards-packs/reference/schema/materials.schema.json` — `pressureDesigned`
  required; spec-code pattern widened for ASME.
- `standards-packs/validate_packs.py` — `check_materials()` **22 rules**.
- `app/tests/Feature/MaterialLibraryTest.php` — **83 passed / 5052 assertions**.

**`INSTRUMENT` is now the only unclaimed component type.**

### Round 9 — valve trim (v1.8.0), and how a group is reached

The second half of the assembled-component problem, and the user's rule that it
must not be solved separately from the gasket. The gasket pattern carried over
intact; what the valve added is that **a valve needs two answers, not one.**

**The trap this round was built to avoid.** The obvious move is
`VALVE_TRIM.componentTypes = ['VALVE']`. That is wrong, and silently: the
cascade would then offer *"API 600 Trim 8"* in the dropdown where *"A216 WCB"*
belongs, and the valve would come out of the MTO **with a trim and no body**. A
gasket is one axis; a valve is two — body **and** trim — so trim is reached by
its own selector and the cascade never sees it. `groupsForComponentType('VALVE')`
returns `CASTINGS` only, and a test asserts `VALVE_TRIM` is absent from it.

**`reachedBy` — the new group axis.** Rule 19 used to reason that a group with
no `componentTypes` must be structural. That was true of exactly one group
(`PLATE_STRUCTURAL`) and stopped being true the moment two groups existed that
are reached by other routes. Every group now states its route:

| route | meaning | groups |
|---|---|---|
| `CASCADE` | the four-step material dropdown | the 9 original supplying groups |
| `CONSTITUENT` | never chosen directly; only named by another grade's property | `BAR` |
| `TRIM` | a selector **alongside** the body material | `VALVE_TRIM` |
| `REQUISITION_ONLY` | nameable on a requisition, supplies no component | `PLATE_STRUCTURAL` |

Enforced both ways: a group with component types **must** say `CASCADE`
(rule 19), and a `CONSTITUENT` group that nothing names anywhere is dead weight
and fails (rule 23). `MaterialLibrary::REACHED_BY_*` mirrors the four.

**The 7 trims that could not resolve.** Of the 20 API 600 trims, 11 use only
410/304/316/347 and resolved against grades already held. Seven named **310,
Monel, Alloy 20 and Cu-Ni**, which the library had no *wrought* grade for — it
held the **cast** equivalents (`A494 M35-1`, `A351 CN7M`), and **a stem is bar,
not a casting**. The user chose to add the missing specs rather than hold a
partial chart, so the round added four:

- **`A276`** stainless bar — 410, 420, 304, 316, 321, 347, 310. *This is where
  trim resolves.* `A182 F6a` and `A276 410` are the same 13Cr alloy; the
  difference is the **product form**, and resolving trim to A182 would have
  named the wrong form on the requisition even though the metal is right.
- **`B564`** nickel alloy forgings — Monel 400, 625, C276, 825.
- **`B473`** Alloy 20 bar (the wrought partner of the cast `CN7M`).
- **`B151`** copper nickel rod — 70/30, 90/10. **No `family` property:** the
  same call the gasket round made about rubber. `family` is a metallurgical
  axis and a copper alloy has no place on it.

New product form **`BAR`, Ew 1.0** — a bar has no longitudinal seam to derate,
exactly like `PLATE`, `FORGED` and `CAST`.

**Hardfacing, and paying for an exemption.** Stellite 6 is a cobalt-chromium
overlay to **AMS 5894**, not an ASTM product spec, so it cannot resolve against
this library and is deliberately exempt from rule 20. An exemption with nothing
in return is how free text creeps back, so **rule 25 charges for it**: any trim
with a hardfaced *or* hardened seat must state a **minimum seat hardness** —
350 HB for a Stellite overlay, 750 HB for hardened 13Cr. Hardness is the only
property of an overlay a receiving inspector can actually measure, and it is
what the ITP row for incoming valves will check.

**Trim 8 is the one most often got wrong** and is now asserted explicitly: the
**seat is hardfaced but the disc is plain 13Cr**. A reader who assumes the disc
matches the seat has specified a more expensive valve than the one that arrives.

Validator is **25 rules**. New this round, all provoked live and restored:

- **19 (rewritten)** — keys on `reachedBy`; catches both a cascade group with no
  component types and a non-cascade group that has them.
- **20 (extended)** — `seat`/`disc`/`backseat`/`stem` × (`*Specification`,
  `*Grade`) join the six gasket constituent pairs. Ten pairs now policed.
- **23** — a `CONSTITUENT` group nothing names is unreachable from everything.
- **24** — a trim must state **all four** parts, and each must resolve to a held
  grade **or** to a declared hardfacing — never to neither.
- **25** — a hardfaced or hardened seat must state a minimum hardness.

Pack **v1.8.0 — 11 groups / 53 specs / 332 grades / 16 forms.**

### Files (Round 9)

- `standards-packs/reference/materials.ref.json` — v1.8.0; groups `BAR`,
  `VALVE_TRIM`; `reachedBy` on all 11; conventions `valveTrim`, `reachedBy`,
  `trimHardfacing`, `trimIsNotATemperatureRating`, `barHasNoSeam`,
  `copperHasNoFamily`; `assembledComponents` retitled **(SUPERSEDED)**.
- `standards-packs/reference/schema/materials.schema.json` — `reachedBy`
  required, 4-value enum.
- `standards-packs/validate_packs.py` — `check_materials()` 25 rules.
- `app/app/Support/Reference/MaterialLibrary.php` — `REACHED_BY_*` consts,
  `TRIM_PARTS`, `trims()`, `trim()`, `minSeatHardness()`; `reachedBy` on the
  group shape; **`groupsForComponentType()` now skips non-cascade groups.**
- `app/tests/Feature/MaterialLibraryTest.php` — **95 passed / 5995 assertions**
  (12 new trim tests).

### Round 10 — the API 600 scope error (v1.9.0)

**The user caught a real defect in Round 9, not a naming quibble.** ASME/API
scope: **API 600 is "Steel Gate Valves — Flanged and Butt-welding Ends, Bolted
Bonnets". It covers gate valves and nothing else.** Round 9 shipped a single
`API600` spec with no valve-type axis, so `VALVE_TRIM` was attached to all eight
`VALVE` qualifiers and the library would happily offer *"Trim 8, Stellite seat"*
for a **butterfly valve**.

Three pieces of evidence from our own data that it was substantive:

1. **All 20 trims carried a `backseat`.** A backseat is a rising-stem feature.
   A ball or butterfly valve has none — and rule 24 *required* all four parts,
   so the library was **inventing a component that does not exist**.
2. **Not one soft seat existed.** A ball valve seat is normally **PTFE / RPTFE /
   PEEK**. For ball valves the chart was not merely incomplete, it was *wrong*.
3. **Rule 25 was wrong for globe valves.** API 623 adds a **minimum stem
   diameter** — an austenitic stem is weaker than 410 and buckles under the much
   higher closing thrust — which we did not record at all.

This is the same class of error rule 20 exists to prevent: *being in the library
≠ being usable for this component.* Round 9 enforced it for materials and forgot
it for valve types.

**The fix — a valve-type axis, one standard per type:**

| standard | valve type | parts | trims |
|---|---|---|---|
| `API600` | `GATE` | seat, disc, backseat, stem | 20 |
| `API623` | `GLOBE` | seat, disc, backseat, stem | 9 |
| `API594` | `CHECK` | seat, disc, **hingePin** | 4 |
| `API608` | `BALL` | seat, **ball**, stem | 4 |
| `API609` | `BUTTERFLY` | seat, disc, stem | 4 |
| `API599` | `PLUG` | seat, **plug**, stem | 2 |

**`requiredParts` is per specification.** The four API 600 parts are the parts a
*rising-stem* valve has, not the parts a valve has. `NEEDLE` and `DIAPHRAGM` are
deliberately uncovered — `trimStandardFor()` returns **null**, and null is a real
answer, not a gap to paper over.

**Soft seats bring the gasket truth one component along:** the polymer fails
first, not the body. PTFE 204 °C · RPTFE 232 °C · PEEK 260 °C · EPDM 120 °C ·
NBR 100 °C. An RPTFE seat creeps at 232 °C while the A216 WCB body is untroubled.

Validator is **28 rules**. Changed/new, all provoked live and restored:

- **24 (rewritten)** — parts come from the spec's `requiredParts`; also refuses a
  trim that names a part its valve does not have.
- **20 (extended)** — `ball`, `plug`, `hingePin` pairs added.
- **25 (generalised)** — any hardfaced part, not just the four gate parts.
- **26** — a standard must name valve types the `VALVE` template offers, and no
  two standards may claim one type. *This is the rule that fails the original
  bug: `API600` claiming `BALL` now errors.*
- **27** — a polymer seat must state a temperature ceiling.
- **28** — a trim may not be both soft seated and hardfaced.

**PHP:** `trimsFor($valveType)` is now the only honest way to ask;
`trimStandardFor()`, `trimPartsFor()`, `trimMaxTemperature()`; `trim()` and
`minSeatHardness()` take an optional `$standard` because **trim codes repeat
across standards** (`API608 PTFE` vs `API609 PTFE`). `trim()` reads parts in the
spec's own order.

Pack **v1.9.0 — 11 groups / 58 specs / 355 grades**; `VALVE_TRIM` **43 trims**
across 6 standards. `MaterialLibraryTest` **103 passed / 6808 assertions**.

### Round 11 — valve actuators (new pack `actuators` v1.0.0)

The user asked for actuators to be **selectable from a dropdown on the MTO**.
Recon changed what that means, and the finding is worth keeping:

**There is no MTO entry screen.** An MTO reaches the system only as an
**imported file** (`BaselineService::importMto()`), reconciled against the 3D
model; `material_requisition_items` rows are free text with no controller or
view. The only screen with real component dropdowns is the **line model** page
(`ComponentController` → `piping/model.blade.php`).

**A second gap found on the way:** `qualifier` appears **nowhere** in the
application. The `VALVE` template offers GATE/GLOBE/BALL/…, but `components`
has no column to store which one, so a placed valve is just `VALVE`. That
blocks actuators directly, because **which actuators are legal depends on the
valve type**.

Given that, the user chose **reference data only** — the bolting-round staging,
reference first and screen after — plus **fail-safe and control mode**.

**Travel is the load-bearing fact.** An actuator drives a valve only if their
travel matches: quarter-turn for BALL/BUTTERFLY/PLUG, multi-turn for
GATE/GLOBE/NEEDLE/DIAPHRAGM. This is mechanical, not a preference — a lever
physically cannot open a 12″ gate valve — so `forValveType()` is the only honest
dropdown source. **`CHECK` is deliberately uncovered:** it is opened by flow and
shut by backflow, so it has no actuator at all, which is the same reason its
trim has no stem.

**Fail-safe is the trap the pack exists to carry.** Only an actuator that
**stores energy** moves the valve when power is lost. A spring return does; a
**double-acting pneumatic actuator and an electric MOV do not** — they stay
exactly where they are, and datasheets call that "fail safe" constantly. That is
how a safety case ends up resting on a valve that will never move. So
`supportsFailSafe` is true for `PNEUMATIC_SPRING` **and nothing else**, and a
spring return carries **no `defaultFailSafe`**: FC and FO are opposite safe
states chosen per valve from the process (shutting fuel gas is safe, shutting
cooling water is not), never defaulted from hardware.

**I caught myself writing the wrong data here** — the convention said only a
spring return may claim FC/FO, and the first draft set `supportsFailSafe: true`
on the double-acting and electric rows. Fixed before it became data; rule 4 now
enforces it.

8 actuators: `HANDWHEEL` · `LEVER` · `GEAR` (both travels) · `PNEUMATIC_SPRING` ·
`PNEUMATIC_DOUBLE` · `ELECTRIC` · `HYDRAULIC` · `GAS_OVER_OIL`.

`check_actuators()` — **7 rules, all provoked live and restored:**
1 travel names only valve types the `VALVE` template offers · 2 travel is
exclusive (no type on both) · 3 an actuator's travel must resolve · **4 only a
spring return may claim a fail-safe** · 5 a manual actuator fails last and
cannot modulate · 6 a spring return must not carry a default · 7 nothing is
verified.

**A bug found by probing:** rule 3's provocation printed nothing because the
summary line crashed with `KeyError` on the unknown travel before failures were
printed — `fail()` collects rather than exits. The summary now skips unresolved
travels. *A silent probe meant my own reporting was broken, not that the rule
was absent.*

### Files (Round 11)

- `standards-packs/reference/actuators.ref.json` — **new pack**, v1.0.0.
- `standards-packs/reference/schema/actuators.schema.json` — **new schema**.
- `standards-packs/validate_packs.py` — `actuators` in `REF_PACKS`,
  `check_actuators()`, called from `main()`. Now **8 reference packs**.
- `app/app/Support/Reference/ValveActuators.php` — **new**. `all()` ·
  `forValveType()` (the dropdown) · `travelOf()` · `canDrive()` ·
  `supportsFailSafe()` · `failSafeOptions()` · **`objections()`** ·
  `failSafeModes()` · `controlModes()`. Consts `FAIL_LAST/CLOSED/OPEN`.
- `app/app/Providers/EpccServiceProvider.php` — singleton binding.
- `app/tests/Feature/ValveActuatorTest.php` — **new, 17 passed / 96 assertions**.

**`objections()` is the single call a future screen makes** before storing a
row: returns `TRAVEL_MISMATCH` · `NO_FAIL_SAFE` · `NOT_MODULATING` ·
`UNKNOWN_ACTUATOR` · `UNKNOWN_VALVE_TYPE`, and `[]` means acceptable.

### Still open after the actuator round

**The dropdown has no screen yet** — same state as `shortfalls()` and `trims()`.
All three now wait on the same thing.

**`components` cannot store a valve qualifier.** This is now the blocker for
wiring any of it up: without knowing a valve is a BALL, the system cannot filter
actuators or trims for it. A migration adding `qualifier` to `components` (plus
`actuator`, `fail_safe`, `control_mode`) is the natural next step, and it is
what turns three reference packs into a working valve datasheet.

**Size thresholds are deliberately absent.** "Gear operator above NPS 8" is a
project decision belonging to the piping class or the valve datasheet, not to a
reference pack. What is stored is what is mechanically possible.

### Still open after the trim rescope

**`CONTROL_VALVE` and `THREE_WAY_VALVE` have no trim standard.** They are
separate templates with no qualifiers, so `trimStandardFor()` returns null for
them. A control valve's trim is a vendor/ISA matter rather than an API chart, but
this is an unclosed edge.

**Actuators are still unrecorded** — manual, gear, pneumatic, electric,
hydraulic. Raised earlier and not yet picked up; it affects MTO, requisition and
ITP.

**PSV/PRV has no component template at all** — tagged, inspected, and usually on
its own register. **Investigated 2026-09-23: see §5ag.** It is worse than a
missing template — a PSV has two sizes and two pressure ratings, which the
current model cannot express.

### Still open after the assembled-component problem

Both halves are now built — the problem the user refused to let us half-solve is
closed. What it leaves behind:

**Trim is reference data with no screen.** `trims()` and `minSeatHardness()` are
not wired to anything. The natural home is the valve line of an MTO (body from
the cascade + trim from its own selector) and an ITP incoming-inspection row
that checks the measured seat hardness against `minSeatHardness()` — the same
staging as `shortfalls()` in the bolting round, which is also still unwired.

**Facing validation.** `permittedFacings` is on all 26 gasket grades,
`ports.facing` and `equipment_nozzles.facing` exist (`RF`/`FF`/`RTJ`), and
`PortCompatibility.php:112` already warns on a mismatch. Nothing joins them yet.

**Trim 6's second hardness.** API states 250 HB and 175 HB for the two seating
surfaces; only the higher is modelled. `minSeatHardness` is one number and a
second column was not worth it before a screen needs it.

**`INSTRUMENT` is the only component type no group supplies** — unchanged, and
still correct: an instrument is an engineered item, not a material.

### (superseded) The assembled-component problem (raised by the user)

Gaskets were the next group. Recon stopped the round before any data was
written, and **the user named the real issue: the same challenge exists for
valves.** Both are deferred together, deliberately, because solving one badly
would prejudice the other.

**The cascade assumes one component has one material.** True for pipe, flange,
fitting and bolting. False for exactly two things:

| | What the MTO must actually say | Today |
|---|---|---|
| Gasket | 316 winding + graphite filler + CS outer ring + 316 inner ring | cascade returns one material |
| Valve | A216 WCB body + API 600 Trim 8 (410 stem, Stellite seat) | body only; trim deferred in Round 5 |

Each has a real failure mode that goes unrecorded: a carbon steel outer ring
corrodes away in a chloride service and the gasket loses its centring; the
wrong trim wears a seat out in two months while the body is untouched.

**The insight that should shape the solution:** neither is really *N materials*.
A vendor sells "CG 316/graphite/CS" as ONE catalogue item, and API 600 Trim 8
is ONE designation that happens to imply a stem and a seat material. So the
likely answer is a **CONFIGURATION that references materials**, not N parallel
dropdowns bolted onto the cascade. A gasket style and a trim number are the
same shape of problem.

Open questions to settle when this is picked up (asked, not yet answered):

- Gasket types in scope: B16.20 semi-metallic (spiral wound, kammprofile) ·
  B16.20 ring joint (R / RX / BX) · B16.21 non-metallic flat.
- Whether a gasket must be **validated against the flange facing** — RTJ
  gaskets only on RTJ faces, full-face only on FF. `ports.facing` and
  `equipment_nozzles.facing` already exist (`RF`, `FF`, `RTJ`), and
  `PortCompatibility.php:112` already warns on a facing mismatch between two
  ports, so the hook is there.
- Whether `GASKET`'s existing `requiresRating: true` is right — a B16.21 flat
  gasket is not rated the way a spiral wound one is.

**GASKET and INSTRUMENT remain the only unclaimed component types.**

### Still to do

- Remaining material groups, **one at a time with the user naming the specs**.
- **A structural component template does not exist.** `SUPPORT` is in the
  category enum but no template uses it, so `PLATE_STRUCTURAL` currently
  supplies nothing by design. When supports are modelled, that is where A283
  and A36 attach.
- **Wire `FlangeBolting::shortfalls()` into the MTO screen.** The check exists
  and is tested; nothing calls it yet, because manual MTO entry does not exist.
  It needs the flange lines grouped by (rating, size) and a stated joint count.
- **Bolt↔nut pairing (B7↔2H) — the user declined it for now.** Materials only.
  If it is ever wanted, `fastenerRole` is the hook.
- Valve trim, alongside the B16.34 rating, when valves are modelled as items.
- Allowable stress, once transcribed from a purchased standard. **Casting
  quality factor Ec too, and every `minLengthMm` in flange-bolting.**
- Tube + a `TUBE` component template; sour-service attributes on the lot.
  **`maxHardness` on the bolting grades is the NACE MR0175 hook** when sour
  service arrives.
- `piping_classes.material` is still free text; `material_lots` still carries
  only `item_code` + description. Neither is connected to the library.

---

## 5ag. The datasheet gap — MSDV, control valves, PSV/TSV (RECON ONLY, NOT BUILT)

Between 2026-09-23 the user asked three questions in a row: which automated
valves exist (MOV, MSDV), what about control valves, and what about PSV/TSV.
Each was investigated against the packs and the schema. **Nothing was built —
the user said "باید بررسی بشه. فعلاً کاری نکن" on the first and the same
reasoning was accepted for the rest.** This section is the record so the
investigation is not repeated.

**They are all one finding: three different things are being asked of a model
that only knows materials and geometry.**

### 1. MOV / SDV / ESDV / BDV / XV are NOT valve types

The single most important distinction, and the one that would have been coded
wrong: **`MOV` describes the actuation method, `SDV` describes the assigned
function.** They are orthogonal axes, not one list. A valve can legitimately be
both an MOV and an SDV — one word is about its motor, the other about its duty
in the shutdown narrative.

| Tag | What it describes | Normal | On failure |
|---|---|---|---|
| `XV` | general automated on/off | open | stays put |
| `SDV` | process shutdown | open | usually closed |
| `ESDV` | emergency shutdown (SIS) | open | **fail closed** |
| `BDV` / `EBDV` | blowdown to flare | **closed** | **fail open** |
| `MOV` | *actuation* — electric motor | — | fails in place |
| `AOV` | *actuation* — pneumatic | — | depends on spring |
| `SOV` | solenoid pilot, a **part of** the actuator package | — | — |

SDV and BDV are a pair: on a fire the SDV closes to cut the feed while the BDV
opens to send trapped gas to flare.

**Our actuator pack already enforces the key safety consequence.** Industry
sources state plainly that an MOV is generally unsuitable as an ESDV, because
an electric motor is slow and has no stored energy. `ELECTRIC` is already
`supportsFailSafe: false`, so validator rule 4 already refuses "MOV + FAIL_CLOSED".
That was a lucky alignment, not a designed one — but it holds.

**`MSDV` is not an international term.** It appears in no standard or reference
searched. It is a project/employer tag. The user identified it as **"Main Shut
Down Valve"** — the station's principal isolation valve — so it is about
*position and importance in the plant*, not about actuation. **Still to be
confirmed against a real project P&ID before anything is coded.** Sources are
explicit that tagging conventions differ between operators and that the P&ID
and the project tagging philosophy outrank any generic definition.

### 2. A control valve's "trim" is not our trim

`CONTROL_VALVE` currently returns `trimStandard=NULL`, `travel=NULL`,
`actuators=0`. That is the honest answer, and attaching it to `VALVE_TRIM`
would repeat the API 600 mistake in a new costume.

| | Isolation valve (API 600) | Control valve |
|---|---|---|
| Trim means | a **list of materials** | a **geometry** — plug/cage profile |
| Chosen by | corrosion and temperature | **pressure drop and loop behaviour** |
| Success is | zero leakage | the right Cv curve |
| Parts | seat, disc, backseat, stem | plug, seat, **cage, sleeve, guide** |

The trim sets the **flow characteristic** — linear, equal percentage, quick
opening. The same body with a different plug profile is a different valve. Our
"trim number" vocabulary has no meaning here; offering Trim 8 Stellite for a
control valve is a correct-looking answer to a question nobody asked.

Two further things break our model:
- **A control valve is allowed to leak.** ANSI/FCI 70-2 explicitly accepts it
  and grades it: Class IV = 0.01% of rated Cv (the metal-seat industry norm),
  Class VI = soft seat, measured in bubbles per minute. We have no concept of a
  leakage class anywhere.
- **Its travel is neither multi-turn nor quarter-turn — it is linear** (sliding
  stem). `travelOf('CONTROL_VALVE')` returning `null` is correct.

Sizing (Cv, cavitation, noise) is the **vendor's** calculation, not ours. If
control valves are ever picked up, the job is to *hold and control the
datasheet*, not to reproduce IEC 60534.

### 3. PSV/TSV breaks the size and rating model outright

**There is no relief device template at all** — 22 templates, none of them a
PSV. The only occurrence of the string in the whole repo is sample NCR text in
`AuthSeeder`.

**A PSV is not commandable.** It has no actuator and receives no signal; it is
opened by the process fluid itself. So the entire MOV/SDV discussion above does
not apply to it, and the actuator pack is correctly irrelevant.

The structural problem:

| | our model | a PSV |
|---|---|---|
| size | `components.nominal_size`, **one value** | **inlet × outlet differ** (e.g. `1" × D × 2"`) |
| rating | `pressure_rating` on the **line** | **inlet 300# , outlet 150#** |
| sized by | NPS | **API 526 orifice letter D…T** |

The outlet is always larger than the inlet because the relieved gas expands and
needs more area to pass the same mass at lower pressure. The outlet rating is
commonly one class **lower**, because it discharges into a low-pressure flare
header. The user's own standing rule is that `piping_lines.pressure_rating` is
**the single home of a rating** — a PSV has two. `REDUCER` (`LARGE_GT_SMALL`)
solves the two-size half but not the two-rating half.

Order code `3J4` = 3" inlet, J orifice, 4" outlet. Orifice D = 0.110 in²,
T = 26.00 in².

**TSV is not a separate device.** Technically there is no difference from a PSV:
both are opened by pressure, and the name misleads — temperature does not open
it, the *pressure from thermal expansion* does. Practitioners argue the term
should not be used generally and survives only as an employer preference. Its
real distinction is the **scenario**: a blocked-in liquid line warmed by the
sun, where only a few millilitres must escape, so no capacity calculation is
needed and the size is almost always ¾" × 1". **If relief devices are built,
TSV is a `reliefScenario` on a PSV, not a second template** — making it a type
would repeat the MSDV error of mistaking a function tag for a component type.

**PSV belongs to inspection before it belongs to MTO.** Its live questions are
set pressure, the ASME stamp, seat-tightness testing to API 527 and periodic
recertification to API 576 — all ITP territory, which we already have. Expect to
reach relief devices from the **inspection** side, not the material cascade.

### What these three have in common

| | what it lacks | what it wants |
|---|---|---|
| MSDV/ESDV/BDV | a **function/service axis** | a service tag on the placed item |
| control valve | Cv, leakage class, characteristic | a **datasheet** |
| PSV/TSV | two sizes, two ratings, orifice, set pressure | a **datasheet + scenario** |

All three are **engineered items with a datasheet**, like a pump — not rows in
the material cascade. Designing them together is one piece of work; designing
them separately is the same work three times. Deferred by the user, in that
order of investigation, pending PMS.

---

## 5ah-3. The PMS re-analysis (2026-09-24) — AGREED, NOT BUILT. Read before phase 4.

**Nothing in this section is code.** §5ah and §5ah-2 still describe what runs.
This records a design conversation whose conclusions the user approved, and
which changes the shape of phases 4 and 5. The full write-up is
**ADR-008 §11** — this is the index to it.

The trigger was the user asking what I actually knew about PMS in the real
world. Rather than answer from memory I read three complete PMS documents (EIL
293 pp, GAIL `SP-01`, Assam Gas `VCS-PL-PI-PMS-001`). The engine we shipped is
correct for its slice; the slice is far narrower than the document it claims to
model.

### The four structural findings

**1. The class code is a label, not data.** The user: it is assigned by the
design engineer, differs project to project, and each segment means something
locally. So nothing may be parsed out of it — `A1A` here is not `A1A` there.
`UNIQUE(project_id, code)` is already right. A per-project *code grammar* in
`project_piping_config` lets the UI **explain** a code without deriving from it.

**2. The P-T table is derived from B16.5, never typed.** B16.5 keys its tables on
a **material group number** (Table 1A), not on the spec — A105/A216 WCB/A350 LF2
are all Group 1.1. Two hops: `grade -> B16.5 group` (a property on the grade,
beside `pNumber`) then `(group x class) -> curve` (a new reference pack).
Watch three things: **B16.5 stops at NPS 24** (26"+ is B16.47 A/B with different
numbers, and our size pack runs to 48"), the flange table is a **ceiling** that a
class may derate (B31.3: weakest component governs — so allow an override with a
mandatory reason), and **public sources contradict each other** on group
membership, so everything enters `verified: false`.

**3. The body axis is (component type x qualifier x size).** The six
`rule_kind`s are a mixture of three different things — an attribute
(`PIPE_SCHEDULE`), three qualifiers (`FLANGE_TYPE`, `GASKET_TYPE`, `VALVE_TYPE`),
a class-level policy (`END_PREPARATION`) and a header field
(`CORROSION_ALLOWANCE`). **`component-templates.ref.json` already holds the right
axis** — 22 codes with qualifiers, which *is* a PMS item list. We built it and
did not use it, exactly as with the material library. Each body row then carries
a material library reference, which today it cannot.
`VALVE_TYPE` is a misreading: a PMS does not pick valve function (the P&ID
does), it says which types are permitted and how each is built per size.
`END_PREPARATION` survives as the one real class-level rule — PIP calls it
*joint construction* — with component rows validated against it.

**4. Corrosion allowance has two homes.** The column and the rule kind both
exist and nothing reconciles them. **The user confirmed a class never varies CA
by size.** The rule kind goes (zero callers); the column stays and should become
`decimal corrosion_allowance_mm`.

### The user's decisions, verbatim in effect

- **One PMS per project**, revising **as a whole document**.
- **Lines already AFC stay pinned to the old revision and are flagged as
  impacted** — not migrated forward.
- **NDT lives in the PMS** and feeds the ITP. The PMS states, the ITP cites.
- **SP items are in scope now**, and **manual (non-automatic) valves are to be
  added**.
- **SP numbers are unique across the whole project**, and the SP number is
  recorded **both on the MTO and on the datasheet**.
- **The PMS is transcribed from outside, not authored here.**
- **Write-time refusals become "record it, then raise a discrepancy."**

### Two things that follow and will bite if forgotten

**The unique key gains a nullable column.** One size holds several rows of one
component type (flange WN *and* BL; valve GATE *and* CHECK), so the key becomes
`(piping_class_id, component_type, qualifier, dn)`. **In PostgreSQL two rows with
`qualifier IS NULL` do not collide under a plain UNIQUE** — `PIPE` would silently
admit duplicates. PG 17 fixes it: `UNIQUE NULLS NOT DISTINCT (...)`.

**Transcription inverts the write path.** `setRule()` refuses SCH 160 at 3.5"
because ASME does not make it. Correct when authoring; a wall when transcribing,
because the transcriber may not correct the client's document. Every refusal in
`PipingClassRuleService` must be re-read as a candidate discrepancy. The entry
screen is a bulk transcription tool — 400 rows per class, ~50 classes — so
`setRules()` (one spec, many sizes) is the primitive, not a row editor.

### SP items break the axis on purpose

A pig trap is not one of the 22 templates and must never become one; widening an
enum to admit a new case is a trap already recorded in §8. So a class body holds
**two row kinds**: standard `(component type x qualifier x size) -> material
library`, and special `(SP number x size) -> SP register`. Since MTO is imported,
an SP number arrives from two directions; a row citing an unknown SP number is a
`BaselineDiscrepancy` — machinery we already have. Tagged SP items (pig trap,
restriction orifice) acquire two identities, `SP-014` (what it is) and `V-2301`
(which one) — decide which register owns which before building.

### Assets built and still unwired, now with a consumer

Manual valves in the PMS finally consume **`VALVE_TRIM` (6 specs / 43 trims)**.
The body comes from `CASTINGS`/`FORGINGS` (body only, per the standing
constraint), plus rating, ends, construction standard (API 600/602/6D, BS 1868)
and **operator** (handwheel/lever/gear/chainwheel — per size, since a gearbox
becomes mandatory above a diameter, so it needs no new structure).

This does **not** reopen §5ag: if it has an actuator it is a datasheet, if not it
is a PMS row, and the actuator pack is the discriminator. Control valves,
PSV/TSV and the function-tag axis stay deferred.

### One header field we never had

`fluid_service_category` (B31.3: Normal / Category D / Category M / High
Pressure / Severe Cyclic). It is load-bearing, not decorative: it is what
explains why one class takes 5% random radiography and another 100%. Also
missing: `service`, `special_requirement`, design temperature range, and
numbered notes referenced from rows.

### AMENDMENT, same day — a rating is `(standard, class)`, and Class 800 is computed

The user: valve classes mostly line up with B16.5/B16.47, **but some — Class 800
— do not exist in those standards at all.** That observation opened a hole in
finding 2 above and exposed a bigger one. Full write-up: **ADR-008 §11.13**.

**Class 800 lives in API 602 / ISO 15761**, not B16.5 and **not B16.34 either**.
It exists because those valves have *no flange*: a class number exists to fix
flange dimensions, so a flangeless valve is free to carry a pure pressure
designation between 600 and 1500.

**It has no table, it has a formula.** ISO 15761 §4.1.2 / API 602 §4.1.3:
`P800(T) = 1/3*P600(T) + 2/3*P900(T)`. Carbon steel at 38 degC gives
`1/3(1480) + 2/3(2220) = 1973 psi`; a manufacturer catalogue says 1975 psi at
100 degF. So the P-T pack needs **derived classes** beside tabulated ones — a
`derivedFrom` entry, not a fabricated 800 row. Better than data: any future
intermediate class is expressible without a new table.

**The real finding: "rating" is six enumerations, not one.** B16.5
(150…2500) · B16.47 A/B (75…900) · B16.34 (150…4500) · API 602 (**800**) ·
**B16.11 (2000/3000/6000/9000)** · B16.1 (125/250). `3000` in B16.11 and `300`
in B16.5 share neither scale nor meaning, and
`piping_lines.pressure_rating string(16)` is the single home for all of them.
**This is the repo's own standing lesson recurring — "codes are unique only
within their standard", first learned on API 600 scope.** A rating is a pair.

**Valve class relates to the class rating by `>=`, not `=`.** A 600# class
routinely specifies Class 800 SW valves in small bore; the valve is stronger
than required and that is correct. Check sufficiency: *valve MAWP at design
temperature >= class MAWP at design temperature*. **Never compare class
numbers** — `800 > 600` is accidentally true, `3000 > 300` is meaningless. Same
shape as the bolting sufficiency check.

**`requiresRating` is on the wrong axis — a live defect in the pack.** A
socket-weld elbow *is* class-rated (B16.11 3000/6000/9000); a butt-weld elbow is
not, it inherits the pipe schedule. Same template, opposite answers, so the flag
belongs on **component type x end type**. This breaks `isScheduled()` today
(`category in {PIPE,FITTING} and requiresRating !== true`): it will ask a
socket-weld elbow for a schedule.

**A free validator.** ~~B16.11 class fixes the socket bore~~ — **WRONG, corrected
in the B16.11 block below and in ADR-008 §11.16.** The correlation is a *rating
basis*, the table is end-type dependent, and the check is sufficiency.

Build-order deltas: step 2 gains derived classes and a `(standard, class,
group, temperature)` key; a **new step before 5** moves rating onto
`(standard, class)` and `requiresRating` onto type x end type; step 9 gains the
two checks above.

### B16.47 — materials held, dimensions absent above NPS 24 (ADR-008 §11.14)

The user asked whether B16.47 flange specifications are in the material library.
Two questions, opposite answers.

**By design the library holds none of it, and that is correct.** `materials.ref.json`
says so in its own header: a rating is a property of the COMPONENT under
B16.5/B16.47, not of the material — *A105 is A105 whether the flange is 150# or
1500#*. B16.47 is a **dimensional** standard.

**The materials themselves are all present.** `FORGINGS` (componentTypes include
FLANGE/BLIND/SPECTACLE_BLIND) carries A105, A350 (11), A182 (22), **A694 (9)**
and A707 (7). A694 F42–F70 is precisely the large-diameter pipeline flange spec.

**The gap is dimensional and starts at NPS 26.** `flange-bolting.ref.json` says
`source: ASME B16.5`, and every one of its 5 ratings runs **1/2 .. 24** — it stops
exactly where B16.47 begins, while `pipe-sizes.ref.json` runs to **NPS 48**. For
26–48 we hold size, schedule and material, and no bolting and no P-T basis.

**The dangerous part.** `FlangeBolting::forJoint()` returns `null` above 24"
(line 146). Harmless now — registered in `EpccServiceProvider`, **no business
consumer yet** — but once the bolting sufficiency check is wired it will have no
floor above 24" and will **pass silently**. A check that succeeds because it has
no data is the worst failure mode there is. Whatever wires it must treat missing
data as *unknown*, never as *pass*.

**Series A and B are not interchangeable.** Series A (from MSS SP-44) is heavier
with fewer, larger bolts; Series B (from API 605) has more, smaller bolts on a
tighter bolt circle. Same NPS and class, different OD, thickness, bolt count and
bolt circle; Series B also has a Class 75 that A lacks. **Series is a
project-level choice** — header or piping config — and the pack needs a `series`
axis or it repeats the §11.3 B16.5-vs-B16.47 warning one level finer. Whether
B16.47 P-T tables track the B16.5 group numbers unchanged is **not asserted**;
everything enters `verified: false`.

**ANSWERED (same day): projects use BOTH series.** See the next block — that
kills the "project-level choice" reading written above.

Build-order addition: **extend `flange-bolting` to B16.47 with a `series` axis,
NPS 26–48, flush with `pipe-sizes`.**

### Both B16.47 series are in use (ADR-008 §11.15)

The user: projects use **both** Series A and Series B. So series is **not** a
project setting and cannot sit in `project_piping_config` — it must be
resolvable at each flange.

**Its home already exists.** The §11.4 row payload carries `dimStandard`, so at
NPS 26+ it becomes `dimStandard: "B16.47", series: "A"`. No new structure. A
class header may hold a *default* series to speed transcription; the row wins.

**The P-T pack does NOT need the series axis** — verified, because the opposite
was being assumed: *"The dimensional series, A and B, have the same
pressure-temperature ratings. But unless they use special bolts they will not
fit up together."* B16.47 materials align with B16.5 (nickel alloys excepted).
So:

| pack | key |
| --- | --- |
| `flange-pt-ratings` | (standard, class, material group, temperature) — **no series** |
| `flange-bolting` | (standard, **series**, class, nps) — **series required** |

**Editions drift**, though: for one material and class the rating can differ
between B16.5 and B16.47 by edition, "typically by 5 psi or so". The pack needs
an **edition year**, not just a standard name. The standards catalogue already
holds `ASME · B16.47 · 2020`.

**Four nearly-free checks:**
1. **Series A does not bolt to Series B** — different OD, bolt count and bolt
   circle at the same NPS and class. Joint-level, so it belongs on the
   `PortCompatibility` seam that currently only warns about facing. Likeliest
   real-world clash: a vendor nozzle in one series, the piping class in the other.
2. **Above NPS 24 the maximum class is 900** — B16.5 reaches 2500, B16.47 does
   not. A 1500# class cannot carry a 30" flange, and nothing stops that today.
3. **Class 75 exists only in Series B.**
4. **RTJ is Series A only**, Classes 300–900 (one source further limits defined
   RTJ dimensions to NPS 26–36). Lands on `permittedFacings`, which we hold and
   never validate.

**Not asserted:** that B16.47 covers only WN and BL (which would invalidate four
of `FLANGE`'s six qualifiers above NPS 24). One source's table contradicted
itself at exactly that cell. `verified: false` until a purchased copy settles it.

Net: `flange-pt-ratings` gets **simpler** (no series) but gains an edition year;
`flange-bolting` **doubles** (both series, NPS 26–48).

### B16.11 Table 8 is a rating basis, not a fit-up rule — CORRECTS the block above (ADR-008 §11.16)

The user pointed at the thickness-equivalence table in B16.11 and said it
probably bears on validation. It does, and reading it showed **two errors** in
what was written an hour earlier.

**ASME B16.11 Table 8** (2016 ed.; **Table 7** in earlier editions),
*Correlation of Fittings Class with Pipe Schedule No. or Wall Designation*:

| Class | Type | Schedule | Wall |
| --- | --- | --- | --- |
| 2000 | **Threaded** | 80 | XS |
| 3000 | **Threaded** | 160 | … |
| 6000 | **Threaded** | … | XXS |
| 3000 | **Socket-welding** | 80 | XS |
| 6000 | **Socket-welding** | 160 | … |
| 9000 | **Socket-welding** | … | XXS |

**Error 1 — one column was quoted as the whole table.** `3000 -> Sch 80` is the
*socket-welding* row. A **threaded** Class 3000 correlates to **Sch 160**. One
class number, two thicknesses, decided by end type — the standard's own proof of
the claim that the axis is **component type x end type**.

**Error 2 — it is a rating basis, not a fit-up restriction.** Directly under the
table: *"NOTE (1): This Table is not intended to restrict the use of pipe of
thinner or thicker wall with fittings."* and §2.1.1 is headed **Basis of
Rating**. Physically obvious in hindsight: **pipe OD is constant across schedules
at a given NPS**, and the socket bore is machined to the OD, so a Class 3000
socket fitting takes Sch 40 and Sch 160 alike. The source behind the original
sentence had confused bore with wall.

**So the check is sufficiency:** *fitting rating basis >= actual pipe schedule*.
A shortfall is a finding about **which component governs the line's allowable
pressure**, not a refusal.

**Third instance of one pattern — name it before building it:**

| case | check |
| --- | --- |
| bolting | MTO quantity/length/diameter >= B16.5 floor |
| Class 800 valve | valve MAWP at design temp >= class MAWP |
| B16.11 fitting | fitting rating basis >= actual pipe schedule |

All sufficiency, none equality. Build the first so the other two are instances of
it, not three services.

**Two more free checks.** The `(class, end type)` pair is constrained: threaded is
only 2000/3000/6000, socket-welding only 3000/6000/9000 — so **"Class 9000
threaded" does not exist** (trade sources treat a 9000LB NPT enquiry as a red
flag), nor does Class 2000 socket-welding. And **`2M`/`3M`/`6M`/`9M` are legal
alternative designations** (M = 1000); since the PMS is **transcribed**, the
parser must accept both or a legal marking blocks the transcriber.

**Edition sensitivity again.** The table moved from Table 7 to Table 8 between
editions — the second place today an edition number changes an answer, after the
few-psi B16.5/B16.47 drift. **Packs carry an edition year; citations resolve
against it.**

### Wellhead scope: API 6A, and PIPING TYPE as a standards profile (ADR-008 §11.17)

**The largest scope change in this analysis**, and the user's own proposal — make
the piping type filterable and give it a type — is what makes it tractable.

**6A and 6D are not the same problem.** API 6D **references the B16.34 tables**
and uses ASME classes, so `(standard, class)` from §11.13 already carries it —
except that **API 6D-2008 raised Class 600 at 100 degF from 1440 to 1480 psig**.
That is the **third** edition-sensitivity finding of the day (after B16.5/B16.47
drift and B16.11 Table 7→8). **Packs carry an edition year. This is settled.**

**API 6A differs on every axis:** pressure is **actual psi** (2K/3K/5K/10K/15K/20K)
and **flat across the temperature class** rather than derating; temperature is a
**class letter** (K,L,N,P,R,S,T,U,V) and **combinable** (`P+U`); material is a
**class** (AA…HH) not a spec; **size is a bore diameter** (`2-1/16"`), not NPS;
flanges are **6B/6BX** with **BX** ring gaskets; and there is a quality axis,
**PSL 1–4** and **PR1/PR2**. Identity is one marking string:
`…-10,000 psi-S-FF-1,5-3-PR2-…`.

**User decisions.** The Christmas tree is **tagged equipment with a datasheet**;
its **piping and manual accessories are piping** (PMS scope) — consistent with
§11.9. Boundary = the **tree's outlet flange**. **API 6A continues downstream** to
a transition point, **but that varies by project**. **Sour service is in scope**
(lifts a deferral). **Piping type becomes first-class and filterable.**

**Piping type = a standards profile.** As a label it solves nothing; as a
selector of *which packs and rules apply* it resolves 6A-vs-ASME at one point
instead of in every table. It binds: size system · rating axis · temperature
behaviour · material reach mode · flange standard · gasket family · quality axis.
The **type list is project-configurable** (process/utility/wellhead/flare/drain),
so a project without a wellhead never meets the complexity. Same shape as the
platform's founding requirement that **ISO vs AWP be selectable, not hardcoded**.

**The transition point is DATA, not a rule.** It varies by project, so the system
must never know "after the choke" — the project declares it by assigning a type.
What the system owes is detection: **a joint whose two sides carry different
piping types is an interface and must be validated explicitly.**

**The boundary already has a home:** `NOZZLE` exists with `isTagged: true` and
`requiresRating: true`. A 6BX 10K tree outlet with a BX ring must be matched by
the piping class. That is a **third job** for `PortCompatibility` (after facing,
which only warns today, and the B16.47 series check) — another abstraction
recurring three times.

**What breaks.** (1) ~~**A second size system** — the deepest impact.~~
**OVERSTATED — corrected in the next block and ADR-008 §11.18: the PIPE stays
NPS; only the connection is a bore, and a mapping table covers it.** (2) **A fifth
`reachedBy`**: 6A states a *requirement* (class DD fixes body and trim
**categories**), not a selection — and it already embeds the body/trim split
`VALVE_TRIM` was built for. (3) Temperature is a **combinable letter**.
(4) Comparison must know whether a rating **derates or is flat**.

**What comes free.** **PSL feeds the ITP** exactly as the NDT block does (§11.8),
and `(material class x RWP) -> minimum PSL` is a complete validator.

**The NACE trap — state it plainly.** *"NACE compliant" is not a property of a
material.* A grade is acceptable only **within an environmental envelope** (H2S
partial pressure, temperature, chloride, pH) and under **hardness limits**. A
boolean `naceCompliant: true` on a grade would record a falsehood. Store the
**hardness limit and envelope**; compute compliance **at assessment time**. Same
discipline as `verified: false`: hold the input, never pre-compute the
conclusion. And sour service is **cross-cutting** — ASME piping in sour service is
equally subject to MR0175, so it must not be buried inside the wellhead profile.

**Sources are worse here than anywhere else in this ADR** — they disagree on the
temperature class list, on the range of class S, on the material class
definitions, and on whether 6BX spans the whole pressure range. Nothing is
citable; all `verified: false`.

**Build order:** a new step, **piping type as a standards profile**, must come
**before** any wellhead data or the data has nowhere to live — then a
bore-diameter size pack, a performance-class material mode, and the 6A rating,
PSL and temperature-class packs behind the wellhead profile. Build it so an
ordinary project never encounters it.

### The pipe is NPS; only the connection is a bore — CORRECTS the block above (ADR-008 §11.18)

The user: API 6A pipe sizes can be compatible with ASME/ASTM sizes, and so can
the flanges. Correct — and §11.17 overstated the problem in **three** places.

**1. `pipe-sizes.ref.json` is NOT broken.** API 6A **Type 6B weld necks have
restricted bores machined to match standard ANSI/ASME pipe sizes and schedules**.
Worked example: a **4-1/16" 5,000 psi 6B** flange has a max bore of **3.47"**,
about the ID of **4" Sch 160** line pipe (4.5" OD). Stock lists read *"2-1/16" to
7-1/16", **neck sizes 2"–6"**, SCH 80 / SCH 160 / XXH"*. The reason is the user's
point: **API and ANSI pipe sizes share common ODs — the standards share a common
ancestry.** NPS is a *nominal label*; the 6A size is the *actual through-bore*.
They describe different things, they do not compete. **Wellhead pipe rows use the
existing 36-size pack unchanged**; we need one mapping table,
`(6A size, rating) -> (NPS, schedule)`, not a parallel size universe.

**2. The mapping stops at 6BX.** 6B weld neck = restricted bore, 45K yield.
**6BX weld neck = always full bore**, 60K at 10K psi and 75K at 15K+, and its
necks **"do not usually match any pipe schedules"** — specifying pipe-matching
necks there is done *"at high risk"*. The pack must record **no mapping** at 6BX
rather than invent one. Also: **API 6A does not specify weld necks above 11"**;
larger charts are unofficial.

**3. The gasket family is not new — we already hold it.** Checked the pack:
`B16.20-RJ` contains `RJ-R-SOFT · RJ-R-304 · RJ-R-316 · RJ-RX-316 · **RJ-BX-316**`.
**B16.20 covers R, RX and BX.** That line of §11.17 was simply wrong.

**A new rule, and a FOURTH sufficiency check.** *"API 5L / ASTM carbon steel line
pipe is lower yield than API 6A flanges, therefore a heavier wall is required."*
Matching the bore is not enough — the **pipe wall and grade must compensate for
the yield gap** so the assembly still reaches the flange's RWP (6BX is typically
4130/4140 or 410 SS, so a higher grade, not just a thicker wall). Joining
bolting · Class 800 valves · B16.11 fittings — **four now**.

**Cross-standard flange joints: the system must NOT decide.** Three sources, three
answers — "not interchangeable, the similarity is misleading and dangerous" vs
"some API flanges can easily be joined with ASME flanges" vs 6B 5,000 psi having a
"dimensional heritage shared with ASME B16.5 Class 1500 ring-joint flanges" and
*"generally mate, but confirm ring-for-ring and hole-for-hole"*. So: **flag it as
an interface needing explicit engineering approval and record the decision** —
same treatment as a failed transcribed value (§11.10) and a type boundary (§11.17).

**Net: the wellhead profile got materially cheaper.** Gone: the bore size
universe, the BX gasket family. Still real: the **piping type profile**, the
**performance-class material mode** (AA…HH), and the **RWP / temperature-class /
PSL** packs.

### API 5L was already held, one cross-reference had rotted, and a PSL is a pair (ADR-008 §11.19)

Unlike the B16.47 audit, this one found the data **present and in good shape** —
and a **real shipped defect** beside it.

**Present:** `API5L` in the `PIPE` group, forms `SMLS/ERW/DSAW`, yield and tensile
on every grade, **PSL modelled correctly as `classOrType`**, catalogue row
`API · 5L · 2018`, and `ItpTemplateSeeder` already citing it. Better than
expected, a cross-reference already existed: **15 flange and fitting grades carry
`matchingLinePipeGrade`** (A694 F60 and A860 WPHY-60 -> API 5L X60).

**The defect: 5 of those 15 pointed at nothing.** `X46` (A694 F46, A860 WPHY-46),
`X48` (F48), `X50` (F50), `X56` (F56) were **named but absent** from the pack, and
`validate_packs.py` had no rule that a cross-reference must resolve. **Same shape
as §11.14** — data that reads as an answer and delivers none.

**Fixed.** Rule 29 written **first and made to fail** on the shipped pack (all 5,
exit 1), then X46/X48/X50/X56/**X80** added, then green. Pack **1.9.0 -> 1.10.0**,
**355 -> 360 grades**. `MaterialLibraryTest` **103 passed / 6863 assertions**.

**A PSL is a pair — the user's catch.** **API 5L has PSL 1–2; API 6A has PSL
1, 2, 3, 3G, 4.** Same acronym, different standards, different scales, nothing in
common: 5L's governs chemistry, CVN and carbon equivalent on line pipe; 6A's
governs the extent of QA and testing on wellhead equipment. A 5L PSL2 is **not** a
6A PSL2, nor a lower grade of one.

**Third instance of the §11.13 trap:** `Class 300` vs `Class 3000` -> a rating is
`(standard, class)` -> **a PSL is `(standard, level)`**. And the near-miss is the
point: §11.17 had *already* written 6A's `(material class x RWP) -> min PSL` table
into the ADR while the pack *already* stored 5L's PSL as the bare string
`"PSL2"`. Both correct alone; nothing said they were different things. The first
code to compare them would have been wrong and looked right. The spec `_note` now
says so.

**The fourth sufficiency check is already computable on the pipe side.** With 6A
flange yields at 45K/60K/75K = 310/414/517 MPa: **6B** needs X52 (X42's 290 falls
short), **6BX 10K** is cleared by X60 **by 1 MPa**, and **6BX 15K/20K needs X80 —
X70 does not clear it**. That last row explains why 6BX flanges are typically
4130/4140 or 410 SS: ordinary line pipe does not reach 75 ksi. Only the 6A side of
the comparison is still missing. `matchingLinePipeGrade` is the **precedent** —
the same relation, enumerated rather than computed.

**Still open:** **PSL1 vs PSL2 has no semantics** (CVN, carbon equivalent, tighter
chemistry are not recorded), so the system cannot yet validate the ordinary rule
that **sour service requires PSL2**; and there is **no sour-service variant**
(5L Annex H) — a live gap now that §11.17 brought sour service into scope, not a
deferral. Both need a purchased copy.

### A piping MTO is often several sub-MTOs — RECORDED, DEFERRED (ADR-008 §11.20)

**User: in some projects the piping MTO is issued as several sub-MTOs** — pipe,
fittings, flanges, valves, paints. **Recorded so it is not lost; the user has
deferred completing the MTO.**

**Why:** the industry ordering (`Pipe -> Fittings -> Flange -> Gasket -> Fasteners
-> Spacer & Blanks -> Valve -> Specialty`) **is the cut line**, because each
commodity is a **different vendor and a different PO**. The sub-MTO boundary is a
**procurement package boundary** — and `ProcurementService` / `PurchaseOrder` have
**no package concept at all**.

**LIVE DEFECT.** `reconcile()` takes the **union** of item keys
(`array_unique([...array_keys($left), ...array_keys($right)])`) and
`baseline_batches` has **no scope column**. A fittings-only sub-MTO reconciled
against a full model batch raises **`MISSING_IN_MTO` for every pipe, flange, valve
and gasket in the model** — thousands of false findings burying the real ones.

**Third appearance of one failure today** (after §11.14's `forJoint()` returning
`null` above NPS 24, and §11.19's dangling `matchingLinePipeGrade`):

> **The system must distinguish *absent* from *not claimed*.** `MISSING_IN_MTO`
> conflates two entirely different facts.

And it cuts both ways: with no declared scope, **nothing can detect a sub-MTO that
was never issued** either. False positives in reconciliation, false negatives in
coverage.

**Paint is not a piping component.** A paints (or insulation, or welding
consumable) MTO carries rows with **no template among the 22**, no size, no
schedule, no grade — measured in **m2 or litres**, a consumable derived from
surface area. `engineering_items` assumes a component shape throughout.

**Already right:** sub-MTOs revise independently, and `revision_label` + `state`
are per batch, so that works untouched.

**Alignment:** the last industry category, **Specialty items**, is exactly the SP
register of §11.11.

**The user's qualifier is the instruction.** *"In **some** projects"* — **third
time today** something varies by project (after the piping type / 6A transition
point and the class-code grammar), and the answer is the same: **project
configuration, never a hardcoded rule.** Batch scope must be a project-definable
category list.

**When picked up:** scope on `baseline_batches` from a project list · `reconcile()`
on the **intersection** of scopes · a kind separating *not claimed* from *missing*
· a coverage check · non-component rows (own design) · a procurement package
concept.

### BUILT: steps 1, 2 and 2b of the build order (ADR-008 §11.21)

First code and data of ADR-008. **Reference layer only** — no migration, no DB
schema change. Packs valid, **all tests green**.

**Step 1 — `b165MaterialGroup`.** Five sources compared; they contradict badly
(F316 is 2.2 in four and 2.3 in the fifth; CF3 is 2.1 in one and 2.3 in another;
F11/F22 shift a whole group; LF3/LF6 appear in three). **Only the unanimous 15
were written**, all `verified:false`: **1.1** = A105, A350 LF2 (Cl 1/2), A216
WCB, A515 70, A516 70, A537 Cl 1 · **2.1** = F304, F304H, CF8, A240 304 ·
**2.2** = F316, F316H, CF8M, A240 316. Everything contested is **absent on
purpose** — absent reads as unknown; wrong returns a different pressure.

**A105 forced a design decision.** It has no grades by design (tested), yet it
is the commonest flange material. So the schema now lets a **gradeless spec**
carry properties, and the resolver reads **grade first, spec second**. Rule 32
permits spec-level properties only where there are no grades, so both can never
answer. `properties()` was extended to see the fallback too — otherwise it and
the new resolver would have disagreed about A105.

**Step 2 — `flange-pt-ratings.ref.json`** (9th reference pack).
`(standard, edition, group, class, temperature) -> kPa`; carbon steel only.
**Corroborated twice before being trusted:** (1) above Class 150 a B16.5 rating
is **linear in the class number** — holds at every temperature, worst spread
0.44 % — so a mistyped digit breaks the line; (2) the API 602 Class 800 formula
on this curve gives **1975 psi at 38 °C**, exactly what the OMB catalogue prints
(§11.13). Corroboration, **not** verification.

**So the lookup refuses.** `ratingAt()` returns **null** while unverified;
`indicativeRatingAt()` is display-only; `sufficientFor()` returns **null, not
false** — treating unknown as false reports a compliant line as a violation.
Mirrors `properties()` / `verifiedProperties()`. A purchased copy flips curves to
`verified:true` one at a time and nothing else changes.

**Step 2b — the NPS 24 gap now speaks.** §11.14's defect was never the missing
data, it was the **silence**: `forJoint()` returned `null` both for "nothing to
check" and for "never heard of NPS 30". `flange-bolting` v1.2.0 now carries a
**`coverage`** block — B16.5 1/2–24 **with** rows, B16.47 **Series A** and
**Series B** 26–60 **declared with no rows** — plus `covers(standard, series,
nps)`. `null` **plus** `covers()===false` is the honest **unknown**. A gap that
is merely absent cannot be reported; a declared one can.

**The series axis lives on bolting and nowhere else** (A/B differ in bolt count,
diameter, circle; they share P-T) — and ratings-pack rule 6 enforces the other
half.

**Ten validator rules added across the three steps, EVERY ONE made to fail on an
injected violation first.** The linearity rule is the valuable one: it catches
the only failure this data can have that does not announce itself.

**Blocking input is a purchased copy of B16.5 / B16.47 / B16.11 — not more
engineering.**

### OPEN DEFECT: baseline RLS binds to the outer table (DEFECT-001)

**Now in the repo at `docs/DEFECT-001-Baseline-RLS-Binds-To-The-Outer-Table.md`.**
It lived in `/tmp` for a day and a half, was flagged four times as "move it into
the repo", and **a sandbox reset destroyed it**. It had to be re-derived. *A
finding that is not in the repository does not exist.*

Five tables — `baseline_batches`, `baseline_discrepancies`, `engineering_items`,
`model_imports`, `model_import_diffs`. All have `FORCE RLS` and a policy; the
policy is the defect. It was written as

```sql
project_id IN (SELECT project_id FROM project_memberships
               WHERE company_id = current_setting('epcc.company_id', true)::uuid)
```

but **`project_memberships` has no `company_id`**, so SQL bound the name to the
**outer** table and `pg_policies` really holds
`WHERE baseline_batches.company_id = current_setting(...)`. The subquery no
longer mentions the inner table at all.

Result: **too restrictive** (the row's `company_id` is who IMPORTED it, so the
owner, consultant, MC and TPI cannot see a contractor's MTO) and **too
permissive** (a company removed from the project still matches its own old
rows). Not a leak, which is why nothing caught it.

**Why no test caught it:** the baseline tests read and write as ONE company
inside `runAs()`, where the broken and correct policies agree. Standing rule 12
again — never claim isolation without a mirrored test proving the rows ARE
visible to whoever should see them.

**Fix shape:** route all five through `epcc_project_is_writable(project_id)` like
`piping_lines`, not a patched subquery — participation is `project_company`, not
`project_memberships` (standing rule 6). **Write the two-company failing test
first.**

**General lesson:** an unqualified column in a policy subquery is a **silent
correlation bug**. Qualify every column.

### CORRECTION: where the B16.5 numbers actually came from (ADR-008 §11.22)

**User asked which sources were used for B16.5.** Two different answers for two
different things, and §11.21 had presented them as one.

**Material groups — five fetched sources, sound.** texasflange · weldfabworld ·
blog.projectmaterials · midstreamcalculator · sunhyings. Contradictions
tabulated, **only the 15 unanimous rows written**. That part stands.

**The rating curve — NO fetched source.** The 6x6 table was **not in any search
result**; it was written from working knowledge. That is a fine way to get a
starting dataset. Calling it a *transcription* and stamping the pack
`"source": "ASME B16.5 pressure-temperature ratings"` was not — it reads as
though someone had the document open. **Nobody on this project has a copy.**

**What the numbers do rest on.** Three internal checks run *before* writing
(linearity above Class 150, worst spread 0.44 % · Class 150 below the line ·
API 602's Class 800 formula reproducing OMB's printed 1975 psi) and **seven
external spot checks run after the question** — six within 0.6 %, one at
**+3.33 %**.

**The 3.33 % outlier is the useful one:** Class 600 at 600 F, where our
temperature grid is 100 C wide and the standard's is not. Now written into the
pack as a warning against reading that region off this curve. A limit found by
checking.

**Fixed:** `source` now states it is **not** transcribed from B16.5 and is an
informed reconstruction; a new `corroboration` block carries every check, opening
with *this is not provenance — every check ran after the numbers were written, so
it can only fail to disprove them.* Pack 1.0.0 -> 1.1.0. Schema extended.
`verified:false` unchanged; the lookup still refuses.

> **Corroboration is not provenance, and a `source` field is a claim like any
> other.** Six numbers agreeing to half a percent is good evidence the figures
> are right. It is not evidence that anybody read the standard — and a pack that
> says otherwise will be cited as though somebody had.

### Build order (ADR-008 §11.12)

1. `b165MaterialGroup` on grades, `verified: false`
2. `flange-pt-ratings` pack **with a standard axis** (B16.5 / B16.47-A / B16.47-B)
3. PMS as a registered document + revision container — follow
   `2026_09_19_210000_register_itp_as_a_document.php`
4. header enrichment; drop the `CORROSION_ALLOWANCE` rule kind
5. body on component x qualifier x size; wire the material library
6. SP register + the special row kind
7. NDT block feeding the ITP
8. transcription screen, bulk-oriented
9. MTO: class code + SP number columns, and the compliance checks

Steps 1 and 2 are reference data only — no migration risk.

---

## 5ah-2. AMENDED the same day — NO SIZE BANDS, one row per size

**User instruction:** «برای هر سایز لوله که در کلاس مورد نظر وجود دارند اسکجوئل
به صورت مجزا ثبت شود و از بازه استفاده نشود» — plus, on how it is used: *the
user picks the diameter, then picks the schedule.*

**Read this section, not §5ah, for the current shape.** §5ah is kept as the
history of how the design got here.

**The band design was a correct answer to a question the user does not have.** A
PMS is not read as bands; it is read one diameter at a time. The correction
**removed** the problem instead of solving it:

| | bands | per size |
|---|---|---|
| the four-inch ambiguity | solved by half-open encoding | **cannot arise** — no boundary |
| uniqueness | `EXCLUDE USING gist` + `btree_gist` | plain `UNIQUE (class, kind, dn)` |
| what a class covers | implied by band arithmetic | **exactly the rows it lists** |
| narrowing a class | rewrite boundaries, risk a gap | delete a row |
| revising one size | split a band in three | overwrite one row |
| schedule check | across a whole band | **against its own size** |

That last row is a genuine improvement, not a wash: the refusal now says **SCH
160 is not made at 3½″** instead of condemning the entire band that size sat in.

**All the gap machinery is gone** — `range_agg`, multirange parsing, the
contiguity publication gate. There are no gaps when a class simply lists its
sizes. `coverage()` now answers the question that is actually useful — *does
this class cover the sizes the project uses?* — and since only the caller knows
that, the required list is a parameter.

**Schema:** `dn_range int4range` → `dn integer` + `nps varchar(16)`, both NOT
NULL. Both on purpose: `dn` is what the lookup keys on, `nps` is the label the
engineer typed, and `1 1/4` cannot be rebuilt from `32` without a lookup.

**API:**

| before | after |
|---|---|
| `addRule($class,$kind,DnRange,$value)` | `setRule($class,$kind,$nps,$value)` — **upserts** |
| — | `setRules($class,$kind,[$nps…],$value)` |
| — | `sizesCovered()` (ascending by DN) · `removeRule()` |
| `coverage()` → contiguity | `coverage($class,$kind,$requiredNps)` → what is missing |
| `uncoveredSizes()` | `uncoveredSizesInUse()` — sizes modelled but unspecified |

`setRules()` is **not a band**: it writes one independent row per size. A PMS
does say "2 to 6 inch SCH 40" and typing that six times is six chances to
mistype it — but what is *stored* is six rows, and a test changes one and
asserts the others do not move.

`setRule()` upserting means re-stating a size is a **correction**, not a
collision. The `UNIQUE` index still stops two rows existing, and a test proves
it with a raw insert that **bypasses the service** — because a bulk import is
exactly what would.

**Still 39 tests, rewritten to the new intent rather than deleted** — the fourth
time that rule has paid off. `test_four_inch_resolves_to_exactly_one_schedule`
became `test_the_boundary_problem_cannot_arise`, asserting that exactly one row
can speak for DN100.

`DnRange` survives, used **only** by the branch table, docblock narrowed to say
so. **The branch table is unchanged** — overlap there is deliberate, and
cell-by-cell would be 120 rows for a class whose document states three.

Migration `app/database/migrations/2026_09_23_120000_piping_class_rules_per_size.php`
carries existing rows over at their lower bound rather than dropping them.

---

## 5ah. The PMS as data — ADR-008 phases 1, 2 and 3 — BUILT

The long-deferred piece. The user's original sentence was:

> «مثلاً لوله ۱/۲ تا ۴ اینچ SCH80، ۴ تا ۱۰ اینچ SCH40»

**Four inches is in both bands.** An engineer reads the intent instantly; a
database cannot, and the wrong reading is a wrong wall thickness on a pressure
line. Everything here follows from one decision: **size bands are stored as
half-open DN ranges `[from, to)`, never as inclusive text.**

**The spikes were re-run before writing any code**, and all four rows of
ADR-008 §3 reproduced on this PostgreSQL 17 — including the one that matters:
the sentence transcribed literally as two *inclusive* bands is **rejected**,
with an error naming DN100. There is a test that does exactly this by writing
raw rows, bypassing the service, because **a service-layer check is precisely
what a bulk import goes around.**

### Two tables, because the two rule kinds are genuinely different shapes

| | `piping_class_rules` | `piping_class_branch_rules` |
|---|---|---|
| shape | 1-D band | 2-D footprint (header × branch) |
| overlap | **corruption** — `EXCLUDE USING gist` refuses it | **a feature** — general rule + narrower exceptions |
| resolution | the one matching band | **narrowest footprint wins**, `priority` breaks ties |
| guard | exclusion constraint | `UNIQUE` on the exact footprint (a duplicate is a typo) |

ADR-008 §4.1 records the spike that proved forbidding overlap in the branch
table **rejects two perfectly correct PMS rules** — a general TEE over the whole
matrix plus an OLET for small branches off large headers, whose footprint sits
inside it. Both tests exist and both directions are pinned.

### Files

- `app/database/migrations/2026_09_23_100000_create_piping_class_rules.php` —
  both tables, `btree_gist`, the EXCLUDE constraint, RLS + archive freeze.
- `app/app/Support/Piping/DnRange.php` — the half-open convention in **one**
  place. Postgres normalises `[15,100]` into `[15,101)` silently, so the
  boundary is decided here and nowhere else. `label()` prints the **inclusive**
  bound (`DN15–DN99`): showing the stored exclusive bound would re-create the
  ambiguity on screen.
- `app/app/Support/Piping/PipingRuleKind.php` — 6 kinds, each declaring the
  jsonb key it resolves to.
- `app/app/Support/Piping/Exceptions/NoRuleForSizeException.php`,
  `BranchLargerThanHeaderException.php`.
- `app/app/Models/PipingClassRule.php`, `PipingClassBranchRule.php`;
  `PipingClass` gained `rules()` / `branchRules()`; **`PipingLine` gained
  `components()`**, which did not exist.
- `app/app/Services/PipingClassRuleService.php` — write, resolve, coverage.
- `app/app/Services/PipingClassComplianceService.php` — phase 3, the audit.
- `app/tests/Feature/PipingClassRuleTest.php` — **29 tests**.
- `app/tests/Feature/PipingClassComplianceTest.php` — **10 tests**.

### Three write-time refusals the ADR had not anticipated

Each would otherwise store a rule that **looks present on a screen and resolves
to nothing**:

1. a payload that does not match its kind (`{"end_type":…}` on a schedule rule);
2. a band bounded by a DN that is not an ASME size (`DN17`–`DN63`);
3. **a schedule that does not exist across the whole band.** This is the §6
   two-layer check moved to write time, and the example is real *and inside the
   user's own band*: **SCH 160 is not made at 3½″ (DN90)**, so "½″ to 4″
   SCH 160" is impossible even though it reads perfectly on paper.

**My first version of test 3 was wrong** — I guessed `10S` would be patchy and
it is present at all 20 sizes in range. Querying the pack instead of guessing
produced a better example than the one I invented.

### The audit — why this was worth building (phase 3)

Once the PMS is data, importing a 3D model stops being transcription and becomes
an **audit**. `auditLine()` returns counts plus a finding per component.

**Four outcomes, deliberately not two:**

| code | verdict | who fixes it |
|---|---|---|
| `schedule.deviates` | WARNING | the designer — it is buildable, just not per spec |
| `piping_class.no_rule` | WARNING | **the specification** — a hole in the PMS |
| `piping_class.not_set` | WARNING | nobody yet — unjudgeable, and VALID would imply an approval nobody gave |
| `schedule.unknown` | **INVALID** | physically unbuildable |

Collapsing any pair sends someone to read the wrong document. A 6″ SCH 80 pipe
is real and buyable and simply not what class A1 says — calling that INVALID
would be false, calling it VALID would miss the problem.

**A rating and a schedule are alternative ways of stating strength.** The check
asks the schedule question only of components whose wall is set by one, read
from the template pack's `requiresRating` rather than a second hard-coded list
that would drift. Without that guard **every valve on every line would report a
deviation**, and a report full of false findings is a report nobody reads.

### Still open

- **Phase 4:** nothing calls `auditLine()` during PCF/MTO ingestion yet.
- **Phase 5:** class revisions and the publication lock. `coverage()` is the
  gate that lock needs and it is built; the state machine is not.
- **No screen at all** — rules can only be created programmatically. The PMS
  arrives as a consultant PDF, so a transcription screen showing
  `uncoveredSizes()` live is the natural next piece.
- `piping_classes.material` is still free text. A `MATERIAL` rule kind is the
  obvious bridge to the material library and was **deliberately not invented**
  ahead of the user naming it.

---

## 5ai. ASME B16.5-2025 was purchased and read (2026-09-24) — VERIFIED

**The first verified data in the repository.** Everything else in every pack is
still unverified and must stay that way.

**The document is NOT in git.** It lives in `standards-source/`, which is
gitignored (`standards-source/*` + `!README.md`, confirmed with
`git check-ignore -v`). Per-seat licence: a commit would redistribute it to
every clone, backup and contractor with repo access. Only extracted values with
a citation reach the repository. **Do not `git add -f` it, ever.**

### What was extracted

| | |
| --- | --- |
| `flange-pt-ratings.ref.json` **2.0.0** | all **45** SI tables, 2-1.1 … 2-3.19 — 675 temperature points, 7 classes, **4,725 values**, all `verified: true` |
| `materials.ref.json` **1.12.0** | `b165MaterialGroup` on **75** grades (was 15), all `verified: true` |
| edition | **2025**, revising 2020 — the pack had been claiming 2020 |

Extraction recipe, if it ever has to be redone:
`pdftotext -layout` then parse each `Table 2-N.M` block (the `…C` twins are US
customary — take the SI ones). Each table's header block *is* Table 1A content
for that group, so group membership and ratings come out of the same parse.

### What the reading proved (ADR-008 §11.23)

- **The 15 consensus-only guesses: 15 right, 0 wrong.** All **16** wrong claims
  found across the five web sources landed on materials §11.21 had **refused**.
  The consensus filter caught **100 %** of the errors.
- Source accuracy: texasflange **100 %**, sunhyings **100 %**, midstream 70 %,
  weldfabworld 61 %, **projectmaterials 50 %**.
- The reconstructed Group 1.1 curve was **0.42 % mean / 2.61 % worst**, carried
  6 of 16 temperatures, and **had omitted Class 400 entirely**.
- The `+3.33 %` outlier §11.22 blamed on our grid was **the source being wrong**:
  midstream 1,095 psig vs the standard's **1,135**; we said 1,131.

### Two validator rules were refuted by the document

Both were hypotheses about how B16.5 is built, written as if they were facts.
Neither was deleted — both now **depend on who is speaking**: fatal on an
unverified curve, a recorded note on a verified one.

1. **Linearity in class number** — Group **2.8** (A182 F44 / CK3MCuN / S31254)
   breaks it by **10.7 %**; Class 2500 holds 430.9 bar to 100 °C while Class 300
   is already falling. Groups 1.18, 3.6, 3.19 break it by 1–5 %.
2. **Monotonic fall with temperature** — Group **3.3** Class 2500 goes 128.2 bar
   at 350 °C to **128.3** at 375 °C. Rounding to one decimal, not metallurgy.
   Verified curves may now tick up **one rounding step (10 kPa)**, no more.

### `verified` now means a citation, not a flag

Three tripwires fired on this change, which is exactly what they were built for.
Each was narrowed, none removed:

- a **verified** curve must carry `sourceTable` and a **declared** edition;
- a **verified** property must carry `source` **and** `readOn`;
- an **unverified** thing that cites a table/source is **also** a failure — a
  stale citation must not outlive the reading.

Ten tests were inverted. One was **split**: `FlangePtRatingsTest` still asserts
that **B16.47 refuses**, because it was not purchased — otherwise verifying
B16.5 would have quietly verified everything. `MaterialLibraryTest` asserts
`b165MaterialGroup` is the **only** verified property in the pack; allowable
stresses, yields and tensiles remain unverified and non-citable.

### `FlangePtRatings::ratingAt()` now answers

**Not one line of the class changed.** The refusal lived in the data. Callers
that were getting `null` will now get real pressures — `sufficientFor()` returns
a real boolean for B16.5 and still `null` for B16.47.

### Still open

`B16.47` has no curves and is unverified. `flange-bolting` is still unwired to
any check. `piping_classes.material` is still free text, so the two-hop lookup
(grade → group → pressure) has a working second hop and no first-hop consumer.

---

## 5aj. ASME B16.47-2025 read (2026-09-24) — and two standards that disagree

Same handling as §5ai: **the document is NOT in git**, `standards-source/` is
gitignored, only extracted values with a citation are committed.

| | |
|---|---|
| transcribed | **27** SI tables (Table 3 … 28), 578 points, **2,971 values** |
| groups | 27 vs B16.5's 45 — **no group 3.x**, B16.47 rates no nickel alloys |
| classes | **75**, 150, 300, 400, 600, 900 — stops at 900 |

**Two vendor-page claims are now verified from the document:** Series A and B
share one rating table (the 27 rating tables have **no series axis**; only the
dimension tables are per series), and **Class 75 is Series B only** (Table 37 vs
Series A starting at Table 32). Class 75 is **not** half of Class 150 — it dies
above 350 °C while Class 150 runs to 538 °C, so deriving it would invent
ratings.

Material groups cross-checked against the 75 read from B16.5: **55 agree, 0
clash.**

### The 11 cells where two purchased 2025 standards disagree

**2,089 of the 2,100 shared values are identical (99.48 %).** The other 11 are
in `crossStandardDiscrepancies` in the pack, with both values, both table
numbers and the arbitration. Arbitrated by linearity across the *same row of the
same table*, so neither standard's opinion was used: **8 of the 9 decidable
cells favour B16.5**, seven of them by a margin of 0.09 bar vs 0.94–3.06 bar.
The Class 150 case (group 2.4 @ 400 °C) decides by inspection — B16.47 prints
5.5 bar at both 400 °C and 425 °C, repeating a cell.

**The monotonicity rule found two of the same cells on its own**, knowing
nothing about B16.5: groups 2.5 and 2.11 both *rise* with temperature in
B16.47's printing. Two unrelated checks, same verdict.

### The policy that governs this — do not "fix" it later

**Neither transcription is altered.** On a project governed by B16.47 the
B16.47 figure is the **contractual** one regardless of what the line predicts.
Correcting it would destroy the evidence. `discrepancyFor($group, $class, $T)`
is how a caller finds out.

Three validator rules guard it, each made to fail first: an **undeclared**
disagreement fails; a **declared** one where the packs now agree also fails (a
stale record is a false accusation); a class must belong to a series.

### New on `FlangePtRatings`

`classesForSeries()` (null = no series axis, which differs from an empty list),
`seriesShareRatings()`, `discrepancyFor()`.

### Trap

`B16.47` is no longer the "unverified standard" example — two tests used it that
way and had to be repointed at **group 3.4**, which B16.47 genuinely does not
rate. If you need an unverified example, there is no longer one in this pack.

---

## 5ak. Flange bolting transcribed from B16.5-2025 (2026-09-24) — VERIFIED

`flange-bolting.ref.json` **v2.0.0**. Was 97 rows across 5 classes, every one
`verified: false`. Now **136 rows across all 7 classes** (150/300/400/600/900/
1500/2500), NPS 1/2-24, every one verified against a purchased copy, from the
"Templates for Drilling Class N Pipe Flanges" tables 7, 10, 13, 15, 17, 19, 21
(SI units). Parser: character-level fraction stitching, see §8 trap.

**The trap that nearly cost the round.** The drilling tables use **two column
layouts**, and the last three columns mean different things in each:

| | col 7 | col 8 | col 9 |
|---|---|---|---|
| Class 150, 300 | stud, 1.5-mm raised face | stud, ring joint | **machine bolt**, raised face |
| Class 400 and up | stud, raised face | stud, male-female / tongue-and-groove | **stud, ring joint** |

Reading the second layout with the first layout's names files ring-joint stud
lengths as machine-bolt lengths. I did exactly that, and the comparison against
the existing rows then showed **28 disagreements** in classes 600 and 900 that
looked like a data defect in the pack. They were a defect in my extraction.
With the columns named correctly, **all 94 comparable rows agree with the
standard exactly** - the inherited figures were right all along.

What caught it was an **existing validator rule**: "B16.5 permits machine bolts
at Class 150 and 300 only." The wrong mapping handed a machine-bolt length to
Class 1500, and the rule refused it. A rule written for one reason caught a
different mistake entirely.

**Deferral rows.** B16.5 prints no dimensions for Class 400 below NPS 4, nor
Class 900 below NPS 3. It prints note (5): *"Use Class 600 dimensions in this
size"* / *"Use Class 1500 dimensions in this size"*. Those 16 rows carry
`deferredToClass` and nothing else. `forJoint()` resolves the hop and returns
`deferredFrom` and `ratingUsed` so a take-off can say where the number came
from. Answering `null` would have read as "this joint needs no bolts".

**New fields:** `minLengthRingJointMm`, `minLengthTongueGrooveMm`,
`boltHoleDiameterIn`, `flangeOdMm`, `boltCircleMm`, `deferredToClass`, plus
`source`/`readOn` per row. `minLengthMm` still means the raised-face stud, so
the existing consumer kept working.

**Six validator rules, each provoked to fail first:** a deferral row carrying
numbers; a deferral pointing at a class the pack lacks; a deferral landing on a
size the target does not dimension; a verified row without `source`/`readOn`;
an unverified row that cites one; and an alternate-facing length more than 25 %
from the raised-face figure, which is what a crossed column layout looks like.

**Test fallout.** Verifying the data broke three tests that used it as the
*unverified* example - the same trap as `flange-pt-ratings` in §5aj. A short
bolt at 300#/NPS 6 was ADVISORY because the length was a guess; it is now a
SHORTFALL. `FlangeBoltingTest` 34 -> **38 tests**.

### B16.47-2025 tables 32-42, same round

`flange-bolting.ref.json` **v2.1.0**. 11 more entries, **198 rows**, NPS 26-60,
both series, classes 75/150/300/400/600/900. Pack total **334 rows, all
verified**. `hasRows: false` is gone.

Three structural facts the standard states and we now hold:

- **Series B stops at NPS 36** in Classes 400, 600 and 900. Series A Class 900
  stops at NPS 48. Those 42 rows carry `notOffered: true` - *the standard
  declines this size*, which is not the same as *we have not transcribed it*,
  and neither is the same as *zero bolts*.
- **A ring-joint stud length exists in Series A only**, and only NPS 26-36.
- Series A at Class 300 and up has a **15th column** (ring joint); Class 150
  and all of Series B have 14. Same trap as §5ak, caught by the width check.

**`forJoint()` now takes a series.** Above NPS 24 a class and size exist in
*both* series with different drilling, so answering without one is a coin toss
that buys the wrong bolts. With no series given it returns null - "cannot say",
the same shape the old coverage gap had. `seriesAnswering($rating, $nps)` says
which series would answer, so a screen can ask rather than guess.

**A rule of ours was wrong again.** "Bolt count never falls as the flange grows"
is true of B16.5 but **false of B16.47**: Class 300 Series A drops from 32 bolts
at NPS 44 to 28 at NPS 46. In every real case the bolts get *fatter* - fewer,
thicker studs carrying the same load - which the rule's own comment already
noted for 24 inch 1500# without following the thought through. The rule is now
"count may fall only if the diameter rises". Third time this session a validator
rule turned out to encode a habit rather than the standard.

**A declared gap, not an invented size.** B16.47 dimensions flanges to NPS 60;
`pipe-sizes` stops at NPS 48 because *its* source, B36.10M, has not been bought.
NPS 50-60 are therefore transcribed, correct, and **unreachable** - no line can
be drawn at those sizes. Rather than invent six sizes from memory, the pack
declares them in `sizesBeyondPipeSizesPack` with the reason and a date, and the
validator fails on an *undeclared* unreachable row **and** on a stale
declaration for a size pipe-sizes has since gained. Same discipline as
`crossStandardDiscrepancies`. **Buying B36.10M closes this.**

Six more validator rules, each provoked first. `FlangeBoltingTest` 38 -> **41**.

**Still open:** nothing wires `shortfalls()` to a screen; `pipe-sizes` needs
B36.10M to reach NPS 60.

## 5al. The piping class HEADER — ADR-008 §11.12 step 4 — BUILT (2026-09-24)

Migration `2026_09_24_120000_enrich_piping_class_header.php`. Closes the
user-flagged priority: **`piping_classes.material` is no longer free text.**

**Gone:** `material varchar(64)`, `corrosion_allowance varchar(16)`, and the
`CORROSION_ALLOWANCE` rule kind. The user was asked directly whether a single
class can carry a different allowance at different sizes and answered «خیر», so
the per-size rule had no referent. The value is now
`corrosion_allowance_mm decimal(5,2)` in the header, a number a B31.3 wall
calculation can consume rather than a string that accepted "3", "3 mm" and
"1/8 in" alike.

**Added:** `service_en`/`service_fa`, `fluid_service_category` (B31.3, CHECKed
to the five categories), `design_temp_min_c`/`design_temp_max_c`,
`material_group_code`/`material_spec_code`/`material_grade_key` into the
material library, `material_as_written`, `corrosion_allowance_as_written`,
`special_requirements jsonb`, `default_flange_series`. New table
`piping_class_notes` (number unique in class, RLS like its siblings).

### The governing rule: record, then raise a discrepancy

The user's instruction — *the PMS is transcribed from outside, not authored
here*, and *write-time refusals become "record it, then raise a discrepancy"* —
decides the whole shape. `PipingClassHeader` therefore **never refuses a
material**. A transcriber meeting `ASTM A999 Gr. ZZ` records it verbatim in
`material_as_written` and gets a `MATERIAL_UNRESOLVED` **discrepancy**; the
write succeeds. Refusing would mean a project's own PMS could not be entered
into the system built to hold it.

Findings come in two severities, and the distinction is load-bearing:
**DISCREPANCY** = the document and our references disagree, somebody must
decide. **INCOMPLETE** = the header is simply not filled in yet. A missing
corrosion allowance is INCOMPLETE; an unreadable one is a DISCREPANCY.

Only *arithmetic* impossibilities are refused, as CHECK constraints: a maximum
below a minimum, a negative allowance, a grade without its spec, a series that
is not A or B. No real document contains those.

### What reading the library actually taught us

`materials.ref.json` is keyed by **product form** — PIPE, FORGINGS, FITTINGS,
CASTINGS, BOLTING, PLATE — *not* by metallurgy. There is no `CARBON_STEEL`
group; A106 lives in `PIPE`, and "Carbon steel" is an unverified
`nominalComposition` property on the grade. So a class header's material
reference is necessarily its **base (pipe) material**, and the flange, fitting
and gasket specs arrive with the body in step 5. The test says so out loud, so
the next reader does not re-derive it. I had written `CARBON_STEEL` into the
test from an assumption and the library corrected me.

### Also

`default_flange_series` exists because §5ak proved Series A and B are different
flanges at the same size: `SERIES_NOT_STATED` fires only when the class's rating
is one B16.47 actually offers above NPS 24, so it stays quiet on classes that
never see a large flange.

`PipingClassHeaderTest` — **21 tests**. 241 green across the touched area.

**Next in the build order:** step 5, the body on component x qualifier x size,
wiring the material library per component. Then step 6 (SP register), step 7
(NDT feeding the ITP).

## 5am. The PMS BODY — ADR-008 §11.12 step 5 — BUILT (2026-09-24)

Migration `2026_09_24_140000_create_piping_class_items.php`, model
`PipingClassItem`, service `PipingClassBody`, 21 tests.

This is the layer that finally **consumes the three assets built for PMS that
PMS never reached**: the material library, the component template qualifiers,
and the per-size `END_PREPARATION` policy. HANDOFF's own lesson — "before
building anything in a new module, check what already exists for it" — was the
brief, and nothing new was invented that one of those three already answered.

**The axis is `component type x qualifier x size`**, not the six `rule_kind`
values, which §11.4 showed were a mixture of one component's attribute, three
qualifiers, one class-level policy and one header field.

### The key, and the trap inside it

```sql
UNIQUE NULLS NOT DISTINCT (piping_class_id, component_type, qualifier, dn)
```

One size carries several rows of the same component type — a flange sheet lists
WN *and* BL — so the qualifier is part of the key. But most components have no
qualifier, and **in PostgreSQL two rows with `qualifier IS NULL` do not collide
under a plain `UNIQUE`**: `PIPE` at DN 150 would have been accepted twice and
neither the database nor a reader would have seen it. Proved in the sandbox
before trusting it: a plain `UNIQUE (a,b)` took both `(1, NULL)` rows; `NULLS
NOT DISTINCT` refused the second. PG 15+ only, and we are on 17.

### Record, then raise a discrepancy — again

Same instruction as the header (§5al). A row naming `A999` is **recorded** and
flagged `MATERIAL_UNKNOWN`; a prose-only row is `MATERIAL_UNRESOLVED` at
severity INCOMPLETE, because a half-transcribed sheet is not a conflict. Ten
finding codes, split the same way: DISCREPANCY when the document and our
references disagree, INCOMPLETE when the sheet is simply unfinished.

Only two things are CHECK-refused, both arithmetic rather than judgement: a row
that names *neither* a material nor a description (an MTO line from it would be
blank) and a material reference with a grade but no spec.

### END_PREPARATION becomes a policy with observance

§11.4 kept it as the one genuine class-level rule. `endPreparationConflicts()`
checks every body row's `end_type` against the class rule at that DN rather than
merging the two into one source of truth. If the class says BW at DN 150 and a
row says SW, the joint cannot physically be made and one of the two was
transcribed wrongly — so it is reported, not silently reconciled.

### Two small corrections the code made to me

`MaterialLibrary::productFormsFor()` returns the form **objects**, not their
codes; `implode` on it is an ErrorException. And `piping_class_rules.nps` is NOT
NULL — the per-size amendment added it alongside `dn`, and a fixture that sets
only `dn` dies.

**Also note:** absence from the body means **not permitted**. `itemFor()` never
falls back to a neighbouring size or a parent class — piping classes do not
inherit, and §10 forbids overlapping size bands.

**Next in the build order:** step 6, the SP register and the special row kind;
then step 7, the NDT block feeding the ITP. Step 8 (the transcription screen) is
what finally puts all of §5al and §5am in front of a user — nothing from either
is on a screen yet.

## 5an. The SP register and the second body row kind — step 6 — BUILT, THEN HALF REVERSED (2026-09-24)

> **Read §5ao before this section.** The register described here survives; the
> second body row kind was removed the same day on the user's instruction.

Migration `2026_09_24_160000_create_specialty_items.php`, model `SpecialtyItem`,
`PipingClassBody` extended, `SpecialtyItemTest` **15 tests**.

**SP items break the component axis deliberately.** A pig trap is not one of the
22 templates and must never be made into one — every project invents new
specials, and widening an enum to admit a new case is a trap this repo has
already been caught by. So a class body now holds two kinds of row:

| kind | key | points at |
| --- | --- | --- |
| standard | component type x qualifier x size | the material library |
| special | SP number x size | the SP register |

`CHECK ((component_type IS NOT NULL AND specialty_item_id IS NULL) OR (…))` —
exactly one kind, never both, never neither. `findingsForItem()` returns early
for a special: running the template checks over one would report
`UNKNOWN_COMPONENT_TYPE` on every pig trap.

**User decisions encoded:** SP numbers **unique across the whole project**
(`UNIQUE (project_id, sp_number)`) — one register, many classes referencing it;
the number lives **on the MTO and on the datasheet**, so the register holds
`datasheet_document_id` and a *tagged* special without one is reported; and SP
numbers are **validated, never allocated** — a test asserts the model has no
method whose name contains "next" or "allocate".

### The bug the tests caught, and why it matters

The special key was first written as a table constraint:

```sql
UNIQUE NULLS NOT DISTINCT (piping_class_id, specialty_item_id, dn)
```

Standard rows carry `specialty_item_id IS NULL`, so NULLS NOT DISTINCT made
**every standard row at one size collide with every other** — a class could hold
a weld-neck flange at DN 150 *or* a blind, never both. That is precisely the
case §11.4 introduced the qualifier key for, undone one migration later by the
same PostgreSQL feature that had just been the right answer elsewhere.
`PipingClassBodyTest::test_one_size_carries_several_rows_of_the_same_component`
caught it. The fix is a **partial unique index** `WHERE specialty_item_id IS NOT
NULL`. Both now coexist and were read back out of `pg_indexes` to confirm.

Lesson worth carrying: **`NULLS NOT DISTINCT` is right when the NULL means "this
row genuinely has no qualifier" and wrong when it means "this row is not that
kind of row."** Same syntax, opposite intent.

### Left deliberately unbuilt

§11.9 says a tagged special acquires two identities — `SP-014` (what it is) and
`V-2301` (which one it is) — and that **"which register owns which must be
settled before build."** It has not been settled by the user, so the register
holds the SPECIFICATION only. `is_tagged` records that instances will exist; no
column presumes where they live. **This is an open question for the user**, not
an oversight.

Also still deferred from §11.9: manual valves, which need `VALVE_TRIM` (the
third built-and-unwired asset) plus an `operator` per size.

## 5ao. Specials leave the PMS body — the correction, and what it cost (2026-09-24)

**User instruction:** «نیازی به ثبت special items در pms نیست» — special items
do not need to be recorded in the PMS.

Migration `2026_09_24_180000_specials_leave_the_pms_body.php` reverses the body
half of step 6. It drops the partial unique index, the `exactly_one_kind` CHECK,
the `specialty_item_id` column and its FK; deletes any special rows; and
restores `component_type NOT NULL` and the original `says_something_check`.
Code: `PipingClassItem` loses `specialty_item_id` from `$fillable`,
`specialtyItem()`, `isSpecial()` and the special branch of `label()`;
`PipingClassBody` loses `SPECIAL_NOT_DESCRIBED`, `SPECIAL_DATASHEET_ABSENT`,
`specialFindings()`, `specialsIn()`, the early return in `findingsForItem()` and
the `with('specialtyItem')` eager load.

**A class body is one kind of row again: component type x qualifier x size.**

**What stays, and why.** `specialty_items` — project-unique `sp_number`,
bilingual description, free-text `kind_as_written`, `is_tagged`,
`datasheet_document_id`, RLS, and a test asserting no method name contains
"next" or "allocate". The user's decisions about SP numbers never mentioned the
PMS: the number is unique across the **project**, and it is recorded on the
**MTO** and on the **datasheet**. Both consumers are still unbuilt, so the
register now sits alone and waits. Deleting it would have thrown away the part
the user actually asked for.

`SpecialtyItemTest` went 15 -> 9 tests. Eight body-row tests were deleted; four
register tests were added in their place, including one that reads
`information_schema.columns` and asserts `specialty_item_id` is **not** on
`piping_class_items` — the correction is now defended by a test, not by a
comment. `SpecialtyItem|PipingClassBody` **29 passed / 642 assertions**; wider
`PipingClass|SpecialtyItem|PipingLine|Component|MaterialLibrary` **295 passed /
7870 assertions**.

**The lesson, which is the expensive part (see also §8):** ADR-008 §11.9 said
"a class body holds two kinds of row" and I built it. That sentence was *our
analysis* of sampled PMS documents. The paragraphs actually headed
**User decision** in the same section said only that SP numbers are
project-unique and appear on the MTO and the datasheet — **neither of which is
the PMS**. A design document interleaves the user's decisions with the author's
reasoning and only the former binds. **Before building from an ADR section,
separate the two.** ADR-008 §11.9 now carries the correction inline.

The tagged-instance question (`SP-014` what it is vs `V-2301` which one it is)
is moot for now and returns when the MTO consumes SP numbers.

## 5ap. Manual valves in the PMS — ADR-008 §11.9 — BUILT (2026-09-25)

**User decision: non-automatic valves are to be added**, with the discriminator
*if it has an actuator it is a datasheet; if it does not, it is a PMS row.*

`2026_09_25_090000_create_piping_class_valve_specs.php` ·
`app/Models/PipingClassValveSpec.php` · `app/Services/ManualValves.php` ·
`app/Support/Reference/ValveConstruction.php` ·
`standards-packs/reference/valve-construction.ref.json` (+ its schema, +
`check_valve_construction()` in `validate_packs.py`) ·
`tests/Feature/ManualValveTest.php` **35 passed**.

**A valve needs three answers, not one.** A flange is A105 and that is the whole
material question. A valve is a cast A216 WCB **body**, a 13Cr **trim** and a
**gearbox** — three selections from three sources, none derivable from the
others. Two of those sources had been built and left unwired: `VALVE_TRIM` in
the material library and `actuators.ref.json` behind `ValveActuators`. This
round consumes both. Nothing was re-implemented; `trimsFor()` and the new
`manualOperatorsFor()` are the authorities.

**The boundary is in the schema, not in a comment.** `operator` is CHECKed to
`HANDWHEEL / LEVER / GEAR / CHAINWHEEL`. There is no actuator column and no
fail-safe column — a test reads `information_schema` and asserts their absence.
An MOV cannot be written into a class body, which is the user's discriminator
made structural.

**Why a side table and not four more columns.** The body table is the axis
(which component, at which size, made of what). Trim, construction standard and
operator are NULL on every pipe, flange, elbow and gasket row in the system, and
each would need a CHECK tying it to `component_type = 'VALVE'`. One row per
valve row, keyed 1:1, says the same thing without the litter.

**New pack: `valve-construction` v1.0.0, 13 standards, 0 verified.** API
600/602/603/594/599/608/609/623/6D, BS 1414/1868/1873, ISO 15761 — each with the
valve types it covers, the body product forms it implies (API 600 cast, API 602
forged: the clearest reason the pair exists) and its NPS window. **None of these
standards is in the repo**, so every scope figure is `verified: false` and the
OUT_OF_SCOPE finding says *"scope read from the standard title, not from a
purchased copy"* in its own message. `scopeIsCitable()` is read rather than
assumed: buying API 600 flips one flag in data and changes no code.

**Two decisions inside `ManualValves` worth more than the code.**

1. **No gearbox threshold is hardcoded.** "Gear above NPS 8" is project
   specification and differs everywhere; §8 already records what happens when a
   validator rule encodes the author's habit. `operatorConsistency()` compares
   the class **against itself** — geared at 6 inch, bare handwheel at 12 inch is
   a transcription error — so nothing outside the document is consulted.
2. **A check valve takes no operator, and that falls out of the data.** The
   actuator pack holds no travel for `CHECK`; `travelOf()` returns null and the
   absence is correct rather than a special case. The same fact, mirrored,
   catches a handwheel on a ball valve.

`CHAINWHEEL` was added to `actuators.ref.json` (**v1.1.0**) and is deliberately
exempt from the travel test: it is a way of REACHING a valve, not of turning it
harder, and it hangs off whatever operator is underneath.

15 finding codes, all reporting, none refusing (§11.10). `PipingClassBody`
now takes `ManualValves` and folds valve findings into `findingsForItem()`, so
`isConsumable()`, the future transcription screen and MTO see them without
knowing valves are a special case.

**Run:** `ManualValveTest` 35 passed; wider
`PipingClass|ManualValve|SpecialtyItem|PipingLine|Component|MaterialLibrary|Actuator|FlangeBolting`
→ **388 passed / 8251 assertions**.

## 5aq. ASME B16.34 — a valve stops being rated by the flange standard (2026-09-25)

**The user supplied ASME B16.34-2025**, the first item on the standards-gap
list. It is in `standards-source/` and gitignored, like B16.5 and B16.47.

`standards-packs/reference/valve-pt-ratings.ref.json` (+ schema, +
`check_valve_pt_ratings()` in `validate_packs.py`) ·
`app/Support/Reference/ValvePtRatings.php` ·
`tests/Feature/ValvePtRatingsTest.php` **19 passed** ·
`standards-source/tools/{parse_b1634.py,build_b1634_pack.py}`.

**100 curves** = 50 material groups × Standard and Special class. **2,002
temperature points.** SI only, all `verified: true`.

**Why a second ratings pack and not an extension of `flange-pt-ratings`.**
Because the two answer different questions, and conflating them was the defect:

| | B16.5 (flange) | B16.34 (valve) |
| --- | --- | --- |
| Class 400 | rated | **not tabulated at all** |
| Class 4500 | absent | **rated** |
| Special Class | no such concept | a second, higher envelope |
| material groups | 45 | **50** |

`FlangePtRatings` is untouched, so none of the 1,300 existing tests moved.

**Corroboration.** B16.34 Standard Class agrees with the verified B16.5 pack in
**4,039 of 4,050** comparable cells. The eleven that differ are declared in
`crossStandardDiscrepancies` — and they are why the pack had to be transcribed
rather than aliased.

**FOUR CELLS OF B16.34-2025 DO NOT SURVIVE ARITHMETIC.** All digit
transpositions, all in the high classes, all found by validator rules and not by
reading:

| group | rating class | °C | class | printed | corroborated |
| --- | --- | --- | --- | --- | --- |
| 1.18 | Standard | 500 | 1500 | 106.6 bar | 160.6 bar |
| 3.19 | Standard | 450 | 2500 | 205.9 bar | 295.9 bar |
| 2.1 | Special | 538 | 4500 | 462.2 bar | 426.2 bar |
| 2.9 | Special | 425 | 4500 | 587.7 bar | 538.7 bar |

Stored **as printed**, each with `doNotCite` and the evidence. `ratingAt()`
returns null for them, `refusalFor()` says why, and an interpolation spanning
one is refused too. Groups 1.18 and 3.19 arbitrate each other: they carry the
same Standard Class curve from 38 °C to 600 °C, so each one's bad cell is
contradicted by the other's good one.

**What the parser had to survive** (all of it silent if unhandled): a cell can
carry its own note marker — `1.4 (4)` is ONE value, and demanding seven bare
numbers per row quietly dropped the top five temperatures of every long table; a
`(Cont'd)` page does not restart the table, and resetting the section on it
loses the second page; a grade is not always introduced by `Gr.` — `A537 Cl. 1`
has no prefix and a regex that demanded one dropped the whole material from
group 1.1 without a word. The builder now asserts that every spec token in a
printed header survives into the pack.

**Wired into the PMS.** `ManualValves` gained `ratingFindings()` and five codes:
`RATING_ABSENT`, `RATING_NOT_A_VALVE_CLASS` (a Class 400 valve — copied off the
flange sheet), `BODY_NOT_IN_B1634`, `BODY_GRADE_NOT_STATED`,
`DESIGN_TEMPERATURE_ABOVE_THE_CURVE`, `MATERIAL_NOTE_LIMITS_TEMPERATURE`. The
last exists because "not to be used over 371 °C" sits under a table that keeps
printing numbers above 371 °C. **This is the first check in the system that puts
a material, a pressure class and a design temperature together.**

`groupFor('A182')` returns **null** rather than the first of the dozen groups
A182 appears in. That refusal forced a real product distinction: the standard
never having heard of a spec (DISCREPANCY) is not the same as the spec being
there twice under different grades (INCOMPLETE — A216 is WCB in group 1.1 and
WCC in 1.2, two different curves).

`ManualValveTest` is now **42 passed**. Wider run
`PipingClass|ManualValve|ValvePtRatings|SpecialtyItem|FlangeBolting|MaterialLibrary|Component|PipingLine`
→ **397 passed / 8,348 assertions**.

**Still missing, in the order they unblock things:** ASME B16.10 (face-to-face —
no spool length without it), API 598 (the valve ITP cannot be written),
IPS-M-PI-110 (what an Iranian client cites; free), then API 600/602 to make the
13 construction standards citable.

## 5ar. ASME B16.10 — how long a valve is (2026-09-25)

**The user supplied ASME B16.10-2022 (with the March 2023 errata)**, the second
item on the standards-gap list. In `standards-source/`, gitignored.

`standards-packs/reference/valve-face-to-face.ref.json` (+ schema, +
`check_valve_face_to_face()`) · `app/Support/Reference/ValveFaceToFace.php` ·
`tests/Feature/ValveFaceToFaceTest.php` **15 passed** ·
`standards-source/tools/build_b1610_pack.py`.

**1,833 dimensions** over 6 classes and 11 valve types, **267 ring-joint
adjustments**, **12 facing rules**. Steel only — 478 cast-iron cells read and
discarded, because B16.34 does not rate cast iron and the material library holds
no cast-iron valve body.

**A rating is not a drawing.** B16.34 says the valve holds; B16.10 says how long
it is. Without the second, a spool cannot be dimensioned, an isometric cannot be
closed, and the pipe length on an MTO is a guess with a decimal point on it.

**Four symbols, not one number:** `A` face-to-face flanged, `B` end-to-end
buttwelding, `D` centre-to-face and `E` centre-to-end for angle bodies. A column
headed "A and B" prints one figure serving both ends; two rows are emitted.

**THE FINDING THAT CHANGED THE MODEL.** A **lift check appears twice** in Table
1.5-1: with the globes as an in-line body (symbol A, 559 mm at Class 600 NPS 6)
and with the angle valves as a centre-to-face body (symbol D, 279 mm). Same
type, same class, same size, **280 mm apart**. `lengthFor()` returns null and
says `SEVERAL_BODY_STYLES_OFFERED`. A valve type alone does not identify a
valve.

**Nineteen printed irregularities, declared and not corrected** — 16 places
where a valve gets shorter as it gets bigger, 3 where the mm and inch columns
disagree beyond rounding. Two have the standard's own reason: Table 1.3-1
column 9 takes its large sizes from **API 6D** (note 14), and Class 900 valves
NPS 2½ and smaller carry **Class 1500** dimensions (note 2 of Table 1.5-2).

**The column map is written out by hand, on purpose.** The headings are five
lines of centred text over numbered columns and the text layer keeps none of
that attached. Guessing from x-positions is how a Class 150 gate valve gets a
cast-iron length. What the parser does automatically is what a machine is good
at: counting cells, matching row widths, refusing anything that does not fit —
**0 skipped lines, 0 orphans** in the final run.

**What the parse had to survive:** `533 ( 21.00)` and `267(10.50)` in the same
table (two real rows lost to a regex assuming one space); the vulgar fraction
split across lines again, so `40 (1 ∕2)` reads as NPS ½ when it is NPS 1½ —
solved by keying on **DN**, which is never split, and treating the printed NPS
as a cross-check; a note-only cell `(3)` being swallowed by the value to its
left and shifting every figure after it one class; and `\b0\b`, because an
unbounded `0` alternative matched the zero inside `20 (3/4)`.

**The erratum is bound at the FRONT of the PDF**, before the title page, so a
naive parse reads the corrected pages first and then overwrites them with the
faulty originals. Parsed last here, and compared cell by cell: **0 differ** — so
the erratum moved headings only, and the column map was read from the corrected
pages.

**Cross-pack checks that earned their keep:** every class dimensioned here is
one B16.34 rates; class **4500 is rated and dimensioned by nothing**, which is
now reportable; and the validator caught this pack spelling NPS 1½ as `1-1/2`
where `pipe-sizes` spells it `1 1/2`. Two size vocabularies is one too many.

**Wired into the PMS.** Three more codes: `NO_TABULATED_LENGTH`,
`PATTERN_NOT_STATED`, `DIMENSIONAL_STANDARD_DOES_NOT_COVER_IT`. The pattern is
looked for in `description_as_written` first — that is where a real sheet puts
it — and the finding fires only when the row cites B16.10 for itself.

`ManualValveTest` **46 passed**. Wider run
`PipingClass|ManualValve|ValvePtRatings|ValveFaceToFace|SpecialtyItem|FlangeBolting|MaterialLibrary|Component|PipingLine`
→ **416 passed / 8,440 assertions**.

**Still missing:** API 598 (the valve ITP), IPS-M-PI-110 (free, and what an
Iranian client cites), API 600/602 to make the construction pack citable,
B16.25 for weld-end preparation.

## 5as. API 598 — the valve ITP becomes writable (2026-09-25)

**The user supplied API Standard 598 (2023)**, the third item on the
standards-gap list. In `standards-source/`, gitignored.

`standards-packs/reference/valve-testing.ref.json` (+ schema, +
`check_valve_testing()`) · `app/Support/Reference/ValveTesting.php` ·
`tests/Feature/ValveTestingTest.php` **18 passed** ·
`standards-source/tools/build_api598_pack.py`.

**This is the first reference pack that produces ITP rows rather than drawing
figures.** B16.34 rates the valve, B16.10 measures it, API 598 says what has to
be witnessed before anyone accepts it.

**60 requirement rows** (Table 1), **10 shell-pressure rules** (Table 2), **13
closure-pressure rows** (Table 3), **5 duration bands** (Table 4), **22 leakage
rows** (Table 5) plus the formulas above NPS 48.

**THE SHELL TEST PRESSURE IS NOT IN API 598.** For steel, Table 2 gives a rule —
1.5 × the pressure rating at 38 °C, rounded **up** to the next whole bar — and
the rating is in B16.34. `shellTestPressureBarG()` computes it: class 150 → 30,
300 → 77, 600 → 153, 1500 → 383 barG for group 1.1. **The first calculation in
the platform that needs two purchased standards at once.** Without a material
group it returns null; a figure from the class alone is right for one group in
fifty.

**TWO AXES THE PMS DOES NOT HAVE.** API 598 tests a **floating** ball
differently from a **trunnion mounted** one, and an API 609 **Category A**
butterfly differently from a **Category B**. A PMS row says "BALL" and stops.
`testsFor()` returns nothing and `refusalFor()` says `VARIANT_REQUIRED`. A gate
valve has its own column, so `requiresVariant('GATE')` is **false** — the
"parallel slide gate" variant is an extra route into the globe column, not a
missing answer, and nobody gets nagged about ordinary gate valves.

**Two traps inside Table 1, both asserted:**

1. **The closure rows invert.** Where the low-pressure test goes optional, the
   high-pressure one goes required. One decision written twice; a copy-paste
   between the rows would be invisible.
2. **THE CLASS THRESHOLD MOVES WITH THE SIZE** — 1500 for DN ≤ 100, **600** for
   DN > 100. A Class 900 valve sits on opposite sides of the line at NPS 4 and
   NPS 6. Read as one boundary it mis-states every large valve between 600 and
   1500.

**Table 1 and Table 3 are written out by hand, Table 5 is parsed.** A merged
cell — one "Optional" spanning four size and class bands — has no position in a
text layer. Reading it and writing the reading down is honest; inferring it from
whitespace is not. Table 5 is a plain grid and is parsed with the row count
asserted at 22, and its NPS column comes from `pipe-sizes`, not from the
mangled second column (the DN 65 row arrives as three separate lines, with the
eight figures on a line carrying no size at all — the split-fraction habit for
the third standard running).

**Validator rules worth keeping:** the shell test is never optional; every
column is answered for every test in every band (a gap is indistinguishable
from "no test needed" at the point of use); duration bands must tile the size
range with no gap or overlap; leakage must rise with size; a resilient seat must
be zero; and the two unit columns of Table 5 must agree at 16 drops per mL and
100 bubbles per mL.

Wider run `Valve|PipingClass|ManualValve|SpecialtyItem|FlangeBolting|MaterialLibrary|Itp`
→ **465 passed / 8,629 assertions**.

**NEXT: wire it into the ITP (ADR-015).** The pack answers "which tests, at what
pressure, for how long, to what leakage" for any valve in the PMS. `ItpService`
does not yet ask.

**Still missing:** IPS-M-PI-110 (free, and what an Iranian client cites),
API 600/602 to make the construction pack citable, B16.25 for weld-end
preparation, NACE MR0175 for sour service.

## 5at. API 600 — a scan, and three guesses corrected (2026-09-25)

**The user supplied API Standard 600 (2021).** First purchased copy that is a
**109 dpi scan with no text layer**. `standards-source/`, gitignored.

`standards-packs/reference/valve-construction.ref.json` → **v1.1.0** ·
`check_valve_construction_sources()` in `validate_packs.py` ·
`app/Support/Reference/ValveConstruction.php` (+`sourceQuality()`,
`tablesNotTranscribed()`, `correctedFromGuess()`, `raw()`) ·
`tests/Feature/ManualValveTest.php` **50 passed**.

**THE `verified:false` DISCIPLINE PAID FOR ITSELF.** Three guesses were wrong
and each was producing a false finding:

| field | guessed | actually | what it was doing |
| --- | --- | --- | --- |
| `bodyGroups` | `[CASTINGS]` | `[CASTINGS, FORGINGS]` | flagging every legitimate **A105 forged** gate valve |
| `npsMin` | 2 | **1** | putting every NPS 1–1½ API 600 valve out of scope |
| `npsMax` | *(open)* | **42** | accepting an NPS 48 gate valve API 600 does not cover |

The forged-body one is the lesson: "API 600 is cast, API 602 is its forged
small-bore counterpart" is what everyone says, and **Table D.1 gives the Group
1.1 body as "C-Si A105 or A216-WCB"** — a forging and a casting in one row —
while 7.3 speaks of "the shell of a cast or forged... valve". Old values are
kept in `correctedFromGuess`, not deleted.

**Verified from clause 1:** bolted bonnet, OS&Y, rising stem, non-rising
handwheel, single/double gate, wedge or parallel seating, metallic seating,
flanged or BW ends; **DN 25–1050 = NPS 1–42**; classes 150–2500.
`scopeIsCitable('API 600')` is **true**, the first of thirteen.

**NEW CONCEPT — `sourceQuality`: `NOT_HELD` / `TEXT` / `SCANNED_IMAGE`.**
Owning a standard is not the same as being able to read it. API 600 is
`SCANNED_IMAGE` and `tablesNotTranscribed` names the **seven tables left
alone** (wall thickness, wear travel, stem diameter, packing width, Table 8
materials, Table 9 trim numbers, Annex D combinations) with the reason and the
fix (*a text-layer PDF, or a scan at 300 dpi or better*).

**What OCR could and could not do.** `tesseract` on `pdftoppm -r 300`: prose
near-perfect; **portrait pages must NOT be rotated**; landscape tables need
`rotate(-90, expand=True)` and then read passably; dense numeric tables produce
errors that cannot be seen. **The trim chart stays `verified: false`** — Table 9
is exactly what OCR cannot be trusted on, and a trim number against the wrong
seating material is a procurement error wearing a standard's name.

**A trap inside our own code.** `ValveConstruction::all()` projects every
standard onto a FIXED key set — a contract the dropdowns rely on — so the new
fields were invisible through `get()` and silently returned their defaults.
Anything reading a newer field now goes through the new private `raw()`.

Two existing tests **had encoded the wrong guess** and were rewritten:
`test_a_forged_body_under_a_cast_valve_standard_is_a_discrepancy` (now exercised
against API 602, whose forged claim is still unverified) and
`test_the_size_narrows_the_standards_offered` (API 600 and API 602 genuinely
overlap at NPS 1).

Wider run `Valve|PipingClass|ManualValve|SpecialtyItem|MaterialLibrary|Itp`
→ **428 passed / 8,444 assertions**.

## 5au. A readable API 600 — the trim chart stops being folklore (2026-09-25)

**The user supplied a TEXT-LAYER copy of API 600-2021** after the scan. The scan
gave us the scope; this gives us **Table 8**, the chart the whole valve module
turns on.

`standards-packs/reference/materials.ref.json` → **v1.5.0** ·
`standards-source/tools/build_api600_trims.py` ·
`MaterialLibrary` (+`trimIsObsolete()`, `trimAlternatives()`,
`trimsFor($type, $includeObsolete)`) · `ManualValves::TRIM_WITHDRAWN` ·
`valve-construction` API 600 → `sourceQuality: TEXT`.

**THE INDUSTRY CHART HAD 20 TRIMS. TABLE 8 HAS 30.** Missing: **5B** and the
whole nickel-alloy family **19 / 19A / 19B / 19C / 20 / 20A / 20B / 20C / 21**
(Alloy 625, C276, 825) — ordinary choices on sour and seawater service that
could not be recorded at all.

**TRIM 1 IS OBSOLETE** — Table 8 prints it across the row. Held but **not
offered**: `trimsFor('GATE')` = 29, `trimsFor('GATE', true)` = 30, and a row
naming it raises `TRIM_WITHDRAWN`. Deleting it would turn a reportable fact
into an unknown trim; a sheet copied from an older revision still says Trim 1.

**Table 9** adds one-way substitution: specify 10 and the maker may supply 12
or 16; specify 8 and they may supply 5.

**TWO MORE INVENTIONS EXPOSED.** The old chart gave trim 9 a stem of *ASTM B564
N04400* and trim 6 a seat of *ASTM B151*. Table 8 says trim 9's stem is **MFG
Standard** and trim 6's second surface is Cu-Ni per note (k) with no
specification. Both had survived a full green suite.

**THE SHAPE CHANGED BECAUSE THE STANDARD'S SHAPE IS DIFFERENT.** seat / disc /
backseat / stem was *our* split. Table 8 has **seating surface** (one concept
covering seat ring and wedge face — hence trims that name two) and
**stem/backseat bushing** as ONE column group: one material type, two hardness
columns. `requiredParts` is now
`['seatingSurface','stem','backseatBushing']`, and the seating surface resolves
through `seatingSurfaceSpecifications` — **plural**, because Table 8 offers cast
integral, forged integral and weld deposited as alternatives.

**Two sentinels are now first-class:** `MFG Standard` (the standard declining to
name a specification) and `WITHDRAWN`. Both must appear in the specification AND
the grade so they cannot read as data; the validator enforces the pairing.

**Four existing tests had encoded the old chart** and were rewritten. That is
the second round running where the purchased copy corrected assertions the
suite was happily proving.

`ManualValveTest` **55**, `MaterialLibraryTest` **108**, wider run **530 passed
/ 9,394 assertions**.

**Readable but not built yet:** API 600 Table 1 (wall thickness), Table 4 (stem
diameter), Annex D (body/bonnet/cover material and bolting combinations).

## 5av. Workspace tidy - the standards folder (2026-09-25)

**User asked for the standards we do not need to be removed.** Two things
qualified, both verified before deleting rather than assumed:

- **The scanned API 600** (4.7 MB, 109 dpi, no text layer). Superseded by the
  text-layer copy, which was checked to carry every table (1-9, 4a, D.1-D.4)
  and every annex (A-E). The survivor is now plainly `API 600 2021.pdf`; the
  `text` suffix existed only to tell the two apart.
- **`/home/user/uploads/`** (18 MB). All seven PDFs confirmed **byte-identical
  by checksum** to the copies in `standards-source/`.

**92 MB -> 71 MB.** *(Superseded the same day - see 5aw, where the remaining six
PDFs came down too.)* `standards-source/README.md` carries a table saying which
pack each extraction feeds and what is still unbuilt from it - **API 600 Tables
1, 4 and Annex D are readable and not yet transcribed.**

**Fixed while checking:** `build_b1634_pack.py` read its intermediate from
`/tmp/b1634_raw.json`, which no sandbox reset survives, so the B16.34 pipeline
could not be re-run to reproduce its own pack. The intermediate now lands in
`standards-source/` beside the tools. All four builders run clean.

**NOT removed, and worth a decision:** `source-backup.tar.gz` (3.4 MB). Our own
`.gitignore` says "version control is now doing this job", and it holds 453
entries against git's 557 tracked files - it is a stale subset. It was left
alone because the request was about standards and deleting things outside the
ask is not tidying.

## 5aw. PDF in, PDF out - the standards folder becomes a scaffold (2026-09-25)

**User is about to upload more standards and the workspace had grown.** The
instruction: remove what we do not currently need, re-upload on demand.

**The fact that made this easy: not one tool in `standards-source/tools/` opens
a PDF.** Every builder reads a `pdftotext -layout` extraction. The PDF is
scaffolding for the extraction, and nothing downstream touches it.

So all six remaining PDFs came down - **12.8 MB** - and the **3.2 MB of `.txt`
extractions stayed**. Then the claim was tested rather than asserted: every
reference pack was rebuilt from the extractions alone and compared against the
committed copies. **Byte-for-byte identical, all thirteen.**

**Workspace 92 MB -> 58 MB** across the two rounds.

**`SOURCES.json` is the new safety net.** Name, byte size, **md5** and the
extraction each document produced, plus the one command that restores it. A
re-uploaded file can be confirmed as the same document rather than assumed.

**A licence check worth having run:** across all 90 commits, the only tracked
paths under `standards-source/` are the README and the tools. **No purchased
document has ever entered git** - so the deletion genuinely reclaims space
(nothing lurking in history as an unreachable blob) and the per-seat licence
discipline has held since the folder was created.

**THE WORKFLOW IS NOW STANDING PROCEDURE**, written into
`standards-source/README.md`: drop the PDF, extract it, transcribe the tables
that have a consumer, record it in `SOURCES.json`, **delete the PDF, keep the
`.txt`**. The incremental cost of the next standard is now the size of its text
layer - typically 100 KB to 1 MB - not the size of the document.

**The one exception, stated in the README:** a SCANNED PDF has no text layer, so
the page images are the only source. Keep a scan until a text-layer copy
replaces it.

**What a `.txt` cannot give:** figures, page layout, and the ability to
re-render a page as an image. The last matters only for a scan, which cannot be
transcribed anyway. A challenged figure can still be answered - the `.txt` is
the document's own text layer.

## 5ax. API 602 - the folklore dies, and Class 800 gets a citation (2026-09-25)

**User supplied API Standard 602, 10th edition (2015).** First standard handled
end-to-end under the new **PDF in, PDF out** workflow: extracted, transcribed,
recorded in `SOURCES.json`, PDF deleted. **Cost to the persisted workspace: 1 MB**
(the 0.24 MB extraction plus pack and doc changes), against ~1.9 MB if the PDF
had stayed. 58 MB -> 59 MB.

`valve-construction` -> **v1.3.0** (API 602 verified, TEXT) ·
`flange-pt-ratings` Class 800 -> **verified** · `ManualValves` body-form message ·
`ValvePtRatingsTest` **21** · `ManualValveTest` **57**.

**THE CAST/FORGED FOLKLORE IS WRONG AT BOTH ENDS.** Our guess said API 602 meant
a forged body. **Table 1a lists A216 WCB, A352 LCC and A351 CF8M beside the
forgings.** API 600 said the same last round. **Two checked, two collapsed** -
and six entries still carry a single-form claim. So the finding now **discloses
in its own message** whether the rule behind it is a citation or a habit.
`npsMin` also corrected: clause 1 starts at **DN 8 (NPS 1/4)**, not an open bound.

**CLASS 800 STOPS BEING A FORMULA OFF THE WEB.** Clause 4.1.3: the ratings *"are
a linear interpolation of Standard Class 600 and Standard Class 900
pressure/temperature ratings listed in ASME B16.34"*. 800 is two thirds of the
way from 600 to 900 - **the same weights we had been using unverified.** The
guess was right; it is now a citation.

Two things fell out of actually reading the clause:

1. **The base standard was wrong** - the entry said `appliesToStandard: B16.5`,
   the clause says **B16.34**. A Class 800 VALVE was being derived from the
   FLANGE tables. Same error as the whole B16.34 round, in miniature.
2. **API 602 cites B16.34 UNDATED** and says the latest edition applies for
   undated references. So today's governing Class 800 figure is the
   interpolation of **B16.34-2025**, *not* API 602-2015's printed Table 2a. The
   table is a snapshot; the rule is the requirement.

**Corroboration:** clause applied to our verified B16.34 group 1.1 vs API 602
Table 2a - **14 of 16 rows within 0.023 MPa**. The two that differ (50 C and
500 C) are declared and are consistent with B16.34 moving in the intervening
decade.

The validator rule blanket-refusing `verified` on a derived class was relaxed on
the same terms as the curve rule before it: name the clause, the date read, and
the standard derived from, or stay unverified.

**FOUR tests had encoded the old guesses.** One of them had already been
rewritten once - from API 600 to API 602 - and was wrong both times. It now
asserts the *disclosure* rather than the claim, so it cannot be wrong a third
time in the same way.

Wider run **534 passed / 9,409 assertions**.

## 5ay. API 603 - the field that was asking the wrong question (2026-09-25)

**User supplied API Standard 603 (2025).** Second standard through the
**PDF in, PDF out** workflow. Persisted workspace 59 -> 61 MB (144 KB extraction
plus pack, service, test and doc changes); the 1.4 MB PDF is gone and recorded
in `SOURCES.json`.

`valve-construction` -> **v1.4.0** · `ValveConstruction::bodyMaterialGroups()`,
`testedTo()` · `ManualValves::BODY_MATERIAL_GROUP_AGAINST_STANDARD` ·
`ManualValveTest` **61**.

**Verified from clause 1:** corrosion-resistant bolted bonnet gate valve,
**NPS 1/2 to 24**, **classes 150/300/600 only**, full port, OS&Y, rising stem.
It stops where API 600 keeps going (NPS 42, Class 2500) - now checkable.

**THIRD SINGLE-PRODUCT-FORM CLAIM COLLAPSED** (clause 7.3: "the shell of a cast
or forged valve"). Three read, three gone.

**BUT THE REAL FINDING IS ABOUT OUR MODEL.** API 603 does not restrict the
product form at all. Table 4: the body is **"As selected from ASME B16.34,
Group 2 and Group 3"** - which is what "corrosion-resistant" means, stated as a
testable constraint. `bodyGroups` was simply the wrong question for this
standard.

So `bodyMaterialGroups` sits beside it, and the new finding resolves the row's
spec and grade through `ValvePtRatings::groupFor()` and checks the family:
A216 WCB is group **1.1**, outside API 603 however it was made; A351 CF8M is
**2.2** and ordinary. **A field that keeps returning the same answer is usually
measuring the wrong thing.**

**Cross-pack link:** clause 7.2 mandates API 598, so `testedTo('API 603')`
returns `API 598` and the construction and testing packs meet without a
hardcoded join.

**Trim vocabulary recorded, not duplicated:** Table 5 uses the API 600 numbers
but only **24 of 30** (no 5B, no 20/20A/20B/20C/21) and Table 6 gives **three**
substitutions against API 600's six - the 13Cr swaps have no place on a
corrosion-resistant valve. Held as a `trimVocabulary` note, because a second
copy of the chart is a second answer.

Wider run **538 passed / 9,419 assertions**.

## 5az. An upload that was not a standard (2026-09-25)

**API 608 was requested and what arrived was a web page.** 36 pages of a Chinese
document-sharing site's preview - viewer chrome, "related documents" sidebar,
and a final page reading *"the full-text reading has ended; downloading this
document requires 1500 credits"*.

It had the right filename, a clean text layer, and extracted without complaint.
It contained **zero** occurrences of *Scope*, *B16.34*, *pressure*, *Class 150*,
*API 598*, *trunnion* or *floating*, against 1,872 Chinese characters. Nothing
was transcribed, nothing was recorded in `SOURCES.json`, and **API 608 remains
`verified: false`** in `valve-construction` - still a guess, and its findings
still say so.

**`standards-source/tools/check_extraction.py`** now guards this, and is step 3
of the workflow in the folder README. Calibrated on the eight real extractions:

| | latin words | CJK | numbered tables | markers |
| --- | --- | --- | --- | --- |
| real standards | 4,769 - 33,642 | 0 | 25 - 433 | 4 or 5 of 5 |
| the web page | 911 | 1,872 | 0 | 0 of 5 |

**The first version of the guard rejected seven of the eight real standards** -
it demanded each marker word at least three times, and "normative" appears once
in an ASME front matter. A guard that cries wolf is worse than no guard, so it
was recalibrated against the measured spread before being kept. `--selftest`
reconstructs the paywall page's shape and asserts it is still rejected, so the
guard cannot rot into a rubber stamp.

**What a real API 608 would be worth:** it is the one standard that might close
the gap API 598 opened - `testsFor('BALL', ...)` currently refuses with
`VARIANT_REQUIRED` because API 598 tests a FLOATING ball differently from a
TRUNNION MOUNTED one and a PMS row says only "BALL". API 608 is where that
vocabulary would come from.

## 5ba. API 608 - a valve is not its shell (2026-09-25)

**User supplied API Standard 608 (2020)** after the rejected web page. The guard
passed it, then the round produced **the largest correction of the whole valve
sequence.**

`valve-construction` -> **v1.5.0** · `valve-pt-ratings` -> **v1.1.0** with
`seatRatings` · `ValvePtRatings::seatRatingAt()`, `governingRatingAt()` ·
`ValveConstruction::designVariants()` · `ManualValves::SEAT_GOVERNS_THE_RATING`,
`BALL_DESIGN_NOT_STATED` · `ManualValveTest` **66**.

**CLAUSE 4.1: "the valve pressure-temperature rating shall be the LESSER of the
shell rating or the seat and seal rating."** The platform read the shell out of
B16.34 and called it the valve rating. Class 600 group 1.1 shell = **102 bar**
at 38 C; the same valve with a **PTFE seat above DN 100 = 19.7 bar**. A factor
of five, in the direction that buys a valve for five times what it can hold.

`seatRatings` = API 608 Table 1: **8 curves, 52 points** (PTFE and R-PTFE x
floating and trunnion x three DN bands). `governingRatingAt()` returns the
lesser with a `governedBy` flag. **A metal seat is untouched** - Table 1 does
not cover it, so the pack returns the shell rather than implying a limit.

**THE API 598 ARC CLOSES.** `ValveTesting::testsFor('BALL', ...)` has refused
with `VARIANT_REQUIRED` since 5as. **API 608 clause 1.4** names the two designs:
"floating (seat-supported) ball ... and trunnion ball valve designs".
`designVariants('API 608')` now returns exactly what `variantsFor('BALL')` asks
for, and a test asserts the two packs agree. The silent refusal became the
reportable `BALL_DESIGN_NOT_STATED`.

**A SCOPE SHAPE THE MODEL DID NOT HAVE: size and class depend on the END TYPE.**
Flanged and BW run NPS 1/2-24; socket-weld and threaded stop at **NPS 2**.
Flanged stops at **Class 600**; the other three reach **Class 800**. One
npsMin/npsMax/pressureClasses triple cannot express it - `endTypeScopes` does,
and the flat fields keep the union for rough filters.

**Mirror worth noticing:** API 602 said flanged exists at every class EXCEPT
800; API 608 says flanged stops at 600 and the rest reach 800. Two standards,
opposite directions, same fact: **Class 800 is a non-flanged compact-valve
class.**

Clause 4.2 sends Class 800 shell ratings to **API 602 (2016) Tables 2a-2f** - a
second standard pointing at what 5ax verified. We hold API 602-**2015**; the
citation is dated **2016**, and that is recorded rather than assumed away.

Wider run **543 passed / 9,435 assertions**. Persisted workspace 61 -> 72 MB
(the jump is `.git` and docs, not standards: the extraction is 80 KB and the
0.8 MB PDF is gone).

## 5bb. API 609 - the valve with no ASME class (2026-09-25)

**User supplied API Standard 609 (2021).** Completes the valve construction set:
**five of thirteen standards now verified** (600, 602, 603, 608, 609).

`valve-construction` -> **v1.6.0** · `valve-pt-ratings` -> **v1.2.0** ·
`ValvePtRatings::seatRatingAt()` regeneralised · `ManualValveTest` **68** ·
`ValvePtRatingsTest` **25**.

**CATEGORY A BUTTERFLY VALVES HAVE NO ASME PRESSURE CLASS.** Clause 1.2 a):
manufacturer-rated **cold working pressure**, carrying a class 125 or 150 flange
**BOLTING PATTERN** - which looks like a class and is not one. Category B is the
ASME class and P-T rated valve with an offset seat. Reading a Class 150 shell
rating for a Category A valve invents a figure the standard never claimed, so
`variantScopes[].ratingBasis` records it and the seat table refuses Category A.

**BOTH HALVES OF THE API 598 GAP ARE NOW CLOSED.** Note (h) of API 598 Table 1
sends the reader to API 609 for the categories, exactly as clause 1.4 of API 608
gave the ball designs. `designVariants()` matches `variantsFor()` for BALL and
for BUTTERFLY, and a test asserts each pair.

**THE SEAT TABLE GAINED A SECOND SHAPE.** API 608 keys a ball seat on ball
design + DN band; **API 609 keys a butterfly seat on PRESSURE CLASS**. A curve
now carries a `discriminators` map instead of fixed fields, and a caller that
supplies none gets null rather than the first curve that matched. **Class 600
has an R-PTFE column and no PTFE column** - an answer, not a missing cell.
13 curves now: 8 ball, 5 butterfly.

**A WIN I DID NOT CLAIM.** The tempting story was "a fourth product-form claim
collapses". It did not - API 609's `bodyGroups` was **already null**. The
validator caught the overstatement ("records a correction that changed
nothing"), and the entry now says so in `bodyGroupsNote`. Clause 1.1 does give
it the widest material scope of the five: gray iron, ductile iron, bronze,
steel, nickel-based and special alloys.

**A GAP IN OUR OWN VOCABULARY, SURFACED NOT HIDDEN.** API 609 reaches **NPS 60**
and `pipe-sizes` stops at **48**. `npsMaxBeyondPipeSizes` declares it and the
validator reports a note instead of failing - truncating the standard to fit the
pack would have made our gap look like the standard's scope. **ASME B36.10M**
closes it.

**Butterfly face-to-face deliberately NOT transcribed:** Tables 2 and 3a-3c give
it and **B16.10 Table 1.3-4 already does**. Two sources for one dimension is two
answers. A deliberate cross-check is the honest next step.

Wider run **549 passed / 9,450 assertions**.

## 5bc. API 623 - a substitution table belongs to its standard (2026-09-25)

**User supplied API Standard 623 (2021)**, the globe sibling of API 600.
**Six of thirteen construction standards now verified.**

`valve-construction` -> **v1.7.0** · `materials` -> **v1.6.0** ·
`ManualValveTest` **74** · `MaterialLibraryTest` **108**.

**FOURTH GENUINE PRODUCT-FORM COLLAPSE.** Table 7: body and bonnet "As selected
from ASME B16.34, Group 1, Group 2, and Group 3" - all three, no product-form
restriction. Clauses 7.3/7.4 mention castings only because they govern
INSPECTING a casting (MSS SP-55), not because only castings are allowed.

**The contrast with API 603 is the point, and both entries now carry it:**
API 623 takes Groups 1, 2 AND 3; API 603 takes only 2 and 3. That limit is what
"corrosion-resistant" in the API 603 title buys.

**THE GLOBE TRIM CHART WENT FROM 9 TO 24.** The old spec held nine off an
industry chart in the four-part shape the standard does not have. Table 8 has
the same 24 numbers as API 603, and prints **"TRIM NUMBER 1 IS OBSOLETE"** too.

**A SUBSTITUTION TABLE BELONGS TO ITS STANDARD, NOT TO THE TRIM NUMBER.**
API 623 Table 9 drops `8 -> 5` and adds **`2 -> 10`**, which API 600 does not
offer. Trim 8 and trim 2 are in both charts and substitute differently.

**A FIFTH INVENTED PROPERTY.** `requiresOversizeStem`, a per-trim boolean on the
austenitic trims. API 623 says no such thing: clause 5.8.1 is a DESIGN
OBLIGATION - minimum from Table 5, actual diameter to "take into account ... the
stem material strength characteristics", MSS SP-91 bounding the input force.
Now recorded as a verified spec-level `stemDiameterRule`. **A plausible
simplification of a real rule is the hardest kind of invention to spot.**

**Materials inherited and labelled.** Same 24 codes and column structure as API
600 Table 8, but NOT compared cell by cell - so codes, obsolescence and Table 9
are verified from API 623 while every material property stays verified:false
under `materialsInheritedFrom`. The validator caught the first attempt, which
left a `source` on an unverified property: *a citation nobody stands behind is
worse than none.*

**THE BODY-FORM TEST HAS BEEN WRONG THREE TIMES** (API 600, 602, 623). It now
asserts the PATTERN - no verified standard restricts the product form - and
fails loudly if a future one does. The code path is still covered against one of
the four remaining unchecked claims, with the finding required to admit it.

Wider run **555 passed / 9,933 assertions**.

## 5bd. API 594 - two types, two dimension sources (2026-09-25)

**User supplied API Standard 594 (2022)**, check valves. **Seven of thirteen
construction standards now verified.** `valve-construction` -> **v1.8.0**,
`materials` -> **v1.7.0**, `ManualValveTest` **79**.

**1. THE FACE-TO-FACE SOURCE DEPENDS ON THE TYPE.** Clause 1: a **Type A** check
valve is dimensioned by **API 594 Tables 2 and 3**; a **Type B** by **ASME
B16.10**. We hold B16.10 and NOT API 594 Tables 2/3, so **a Type A check valve
currently resolves to a Type B length.** Recorded in `variantScopes[].faceToFaceSource`
and in `tablesNotTranscribed.reason` as a known gap with the fix named.
Type A also admits **gray and ductile iron**; Type B is B16.34 only.

**2. THE CLASSES COME FROM THREE FLANGE STANDARDS.** 150-2500 per B16.34,
**125/250 per ASME B16.1** (gray iron), **150/300 per B16.42** (ductile iron).
First standard here with iron classes. `pressureClassSources` records which is
which, because `tabulatesPressureClass('125')` is correctly FALSE and a
consumer must not invent a curve for it.

**3. THE CHECK-VALVE TRIM CHART WAS INVENTED OUTRIGHT.** Four codes - `13CR`,
`STL`, `316`, `MONEL` - that appear in no standard. Table 4 gives **twelve
numbered trims** (1, 2, 5, 5A, 8, 9, 10, 12, 13, 14, 16, AA). Parts are
**seatingSurface and hingePin**: a check valve is opened by the flow, so no stem
and no backseat - the "disc" in the old four-part list was ours too.

**A TRIM NUMBER BELONGS TO ITS STANDARD, NOT TO THE WORLD.** A test asserted
trim 8 was a gate trim and could appear nowhere else - true only because the
check chart used made-up letter codes. API 594 has its own trim 8 with different
materials. Same lesson as the substitution tables in 5bc.

**API 594 STATES NO SEAT HARDNESS ANYWHERE** - hardness, HB and Brinell appear
zero times, where API 600 and 623 both give a column. The validator rule
demanding hardness on a hardfaced trim encoded the API 600 shape; a spec may now
declare the absence via `hardnessNotSpecified`, and API 594 does.

New sentinel: **`NOT SPECIFIED`** for Table 4 leaving the bronze trim AA's hinge
pin columns empty, alongside `MFG Standard` and `WITHDRAWN`.

Wider run **560 passed / 10,080 assertions**.

## 5be. API 599 - not every standard numbers its trim (2026-09-25)

**User supplied API Standard 599, 9th edition (October 2025).** **Eight of
thirteen construction standards verified.** `valve-construction` -> **v1.9.0**,
`materials` -> **v1.8.0**, `ManualValveTest` **83**.

**API 599 PRINTS NO TRIM NUMBER TABLE.** Clause 6.2.1 gives the shell from ASME
B16.34 or a ductile iron from B16.42 and leaves seat, sleeve and plug facing to
the purchase order. The library's `PTFE-SLV` and `MTL` were a vocabulary for a
chart that **does not exist**.

- the API599 trim spec is **removed** from VALVE_TRIM
- `trimStandardFor('PLUG')` now returns null, beside NEEDLE and DIAPHRAGM
- `ManualValves::trimFindings()` no longer raises `TRIM_ABSENT` when the valve
  type has no chart - demanding a trim NUMBER from a plug valve asks for
  something the standard has not got

**THE THIRD VARIANT AXIS API 598 NEEDED.** Note (f) of its Table 1 - "for
lubricated plug valves, the high-pressure closure test is mandatory and the
low-pressure closure test is optional" - was prose. Clause 1.2 names
**LUBRICATED / NON_LUBRICATED**. With API 608 (ball designs) and API 609
(butterfly categories), **every variant API 598 distinguishes now has its
vocabulary from a construction standard.**

**Three-way and four-way plug valves are OUT of scope** (clause 1.2 NOTE);
tandem valves are in. `THREE_WAY_VALVE` is on the deferred list and this says
which standard will not cover it.

Scope: NPS 1/2-36, classes 150-2500 **including 800** (third standard with it),
threaded and socket-welding ends stop at **NPS 2**. Ductile iron Class 150/300
follows **B16.42**, not B16.34. Four design groups (short / regular / venturi /
round port) with different face-to-face; the short pattern matches B16.10's gate
figures over NPS 1 1/2-12, which `valve-face-to-face` already holds.

**SECOND OVERSTATEMENT CAUGHT.** "A fifth product-form collapse" - no.
API 599's `bodyGroups` was already null, as API 609's was. **Four genuinely
collapsed: API 600, 602, 603, 623.** The validator caught it both times.

Wider run **564 passed / 10,167 assertions**.

## 5bf. API Spec 6D - the loop closes (2026-09-25)

**User supplied API Specification 6D, 25th edition (2021).** Largest document
held (8,825 extracted lines). **Nine of thirteen construction standards
verified.** `valve-construction` -> **v1.10.0**, `valve-face-to-face` -> **v1.1.0**.

**THE B16.10 LOOP CLOSES.** B16.10 note (14) said its Class 150 gate figures for
the large sizes were "extracted from API 6D". Checked against 6D Annex C Table
C.5: **17 of 19 sizes match to the millimetre**; the other two are the same inch
figure rounded differently (7.50 in = 190.5 mm, one up one down).

**THE DECLARED ANOMALY IS SETTLED.** The B16.10 round recorded column 9
shortening from 762 mm at NPS 34 to 711 at NPS 36. API 6D gives **813 at NPS 36**
and **660 at NPS 30**, and our B16.10 data holds BOTH values at each size. The
larger is the 6D figure; the smaller is the **through-conduit** valve note (14)
sends elsewhere. **The column was never non-monotonic - it was two valve
constructions in one place.** Recorded in
`valve-face-to-face.corroboration.explainsADeclaredAnomaly`.

**A VALVE TYPE NOTHING ELSE KNOWS: AXIAL**, added in the 25th edition. No
template qualifier, no actuator travel, no trim chart. Declared via
`valveTypesBeyondTemplate` and the validator reports a note - same treatment as
NPS 60 in 5bb. **Widening the VALVE template is a deliberate decision, not a
side effect of reading a document.**

**CLASS 400 FOR THE THIRD TIME.** Clause 1 NOTE: "ASME Class 400 has been
removed from this specification." B16.34 never tabulated it, API 602 omits it,
6D deleted it. Three documents: a Class 400 VALVE is not a thing; a Class 400
FLANGE is ordinary.

**It is a SPECIFICATION, not a standard** - design through documentation, five
valve types, and Annex C face-to-face is **normative**, so naming 6D picks a
dimension source as well as a quality regime. First document here to name
**hydrogen gas** service.

Wider run **569 passed / 10,191 assertions**.

## 5bg. ISO 15761 - a second pressure system, and the last collapse (2026-09-25)

**User supplied ISO 15761:2020**, the ISO twin of API 602. **Ten of thirteen
construction standards verified.** `valve-construction` -> **v1.11.0**,
`ManualValveTest` **89**.

**THE FIFTH AND FINAL PRODUCT-FORM CLAIM COLLAPSES.** ISO 15761 was the last
verified entry still asserting one form (`FORGINGS`). Clause 5.4.4.2: "Body end
flanges and bonnet flanges shall be **cast or forged** integral with the body."
**Five for five** - API 600, 602, 603, 623, ISO 15761. **Every
single-product-form claim this repo ever made has been wrong.** Only the three
BS entries still carry one, and they are unverified.

**A PRESSURE SYSTEM THE PLATFORM DOES NOT HAVE.** Clause 1 rates these valves in
ASME Class 150-2500 **AND** in **PN 16/25/40/63/100/250/400**. Every pack keys
pressure on an ASME class number; a European sheet writing PN 40 has nowhere to
go. Declared via `pnDesignations` - third declared gap of this shape after
NPS 60 (5bb) and AXIAL (5bf).

**The two systems do not map one to one.** Clause 1: Class 800 "has no
equivalent PN designation".

**A SECOND TRIM VOCABULARY: CN.** Clause 6.1 - the trim COMBINATION NUMBER
identifies stem and seating surface together. Same system as API 602 Table 12,
different from API 600 trim numbers; the CN set includes **11A, 12A, 14A** which
no API 600 chart has. Recorded, deliberately NOT transcribed: **Annex F ties
each CN to a body and bolting combination**, so chart and associations must be
built together.

**THE TWO FAMILIES CONTRADICT EACH OTHER.** API 600 Table 8 note (b) PROHIBITS
free-machining 13Cr. ISO 15761 clause 6.1.2 PERMITS it on request, under the
trim number **plus 100** (CN101, 104, 105, 105A, 106, 107, 108, 108A). Same
material, same valve family, two answers - which is why a PMS must name the
governing document.

Wider run **572 passed / 10,202 assertions**.

## 5bh. ASME B31.3 slice 1 - a condition is not a service (2026-09-25)

**User supplied ASME B31.3-2024**, the governing design code and the biggest
document held (37,057 extracted lines, 2.9 MB). **SLICE 1 of 3.** The extraction
is KEPT, unlike every other standard, because slices 2 and 3 need it.

`standards-packs/reference/fluid-service.ref.json` (+ schema, +
`check_fluid_service()`) - `app/Support/Reference/FluidService.php` -
`...2026_09_25_190000_severe_cyclic_is_a_condition_not_a_service.php` -
`FluidServiceTest` **10** - `PipingClassHeaderTest` **26**.

**`SEVERE_CYCLIC` WAS NEVER A FLUID SERVICE.** Clause 300.2 defines it
separately as a CONDITION the owner or designer designates to resist fatigue.
It applies ON TOP of any fluid service, so "Category M under severe cyclic
conditions" - an ordinary line - was **unrecordable** in a single enum. Now a
boolean column beside the service, with old rows migrated (service to NULL,
flag set) rather than rejected.

**Two services were missing entirely:** ELEVATED_TEMPERATURE (300.2(c)) and
HIGH_PURITY (300.2(e)). The CHECK constraint now lists the Code's **six**.

**CATEGORY D IS THE ONLY SERVICE WITH NUMBERS IN IT** - 1 035 kPa gauge, 186 C,
-29 C. Everything else is "the owner determines" / "the engineering design
specifies", and the engine has no standing to second-guess a judgement.
`CATEGORY_D_BOUNDS_EXCEEDED` is the one place a declared service can contradict
its own sheet. `categoryDViolations()` returns **null** for other services -
"nothing here to check" is not "checked and fine".

**EXAMINATION EXTENT, FROM THE CODE:** Normal **5 %** random RT per designated
lot (341.4.1); Category D **visual only, no percentage** (341.4.2 + Table
341.3.2-1 note 5); severe cyclic **100 %** (341.4.3). The extent is a MINIMUM -
"or to any greater extent specified in the engineering design" - so a stricter
project spec never conflicts.

**The header's design pressure is DERIVED, not stored:** the class holds a
pressure CLASS, so `designGaugePressureKpa()` goes through `FlangePtRatings`
(B16.5 - a piping class rating is a flange class) at the class design
temperature, and returns null if any link is missing. A Category D check on a
guessed pressure would be worse than no check.

Wider run **490 passed / 9,965 assertions**.

**SLICES 2 AND 3 REMAIN:** Table A-1 groupings + the 304.1.2 wall thickness
equation; then Table 341.3.2-1 acceptance criteria and the allowable stresses.

## 5bi. ASME B31.3 slice 2 - the equation and its edge (2026-09-25)

**Slice 2 of 3.** `fluid-service.ref.json` -> **v1.1.0** -
`app/Support/Reference/WallThickness.php` - `WallThicknessTest` **12**.

**THE EQUATION, 304.1.2:** `t = P*D / (2*(S*E*W + P*Y))`, `tm = t + c`. Every
symbol defined in the pack. `tm = t + c` is an ADDITION - a corrosion allowance
is metal allowed to disappear, not a factor.

**TABLE 304.1.1-1, COEFFICIENT Y** - five families over eight bands,
transcribed whole. Three traps, all tested: the first column is "482 C AND
BELOW" and the last "677 C AND ABOVE" (open bands - read as points, a line at
20 C has no Y at all); Y MAY BE INTERPOLATED between columns; and gray iron is
0.0 then BLANK, which is the fact that it is not used up there.

**WHAT IT REFUSES IS THE POINT.** Four refusals, two of them the Code
withdrawing its own formula:
`ALLOWABLE_STRESS_NOT_SUPPLIED` (Table A-1 is slice 3),
`P_OVER_SE_ABOVE_0_385` and `THICK_WALL_T_AT_OR_ABOVE_D_OVER_6` (both
304.1.2(b)), `COEFFICIENT_Y_NOT_TABULATED`.

**THE TWO LIMITS IN 304.1.2(b) ARE ONE LIMIT.** With Y = 0.4,
`t/D = (P/SE)/(2(1+0.4*P/SE))`, and at P/SE = 0.385 that is 0.1668 = 1/6. The
Code states the edge twice because a designer can reach it from either side.

**A CLAIM CHECKED IN PASSING:** materials.ref.json has always cited "B31.3
Table A-1 material groupings" and the library is keyed by PRODUCT FORM. Table
A-1 really is organised that way, in fourteen forms. Our groups are a coarser
cut - `PIPE` where the Code separates Pipe / Pipe & tube / Smls. pipe / Wld.
pipe - because the distinction that matters to a purchase order is not the one
that matters to a stress table.

**WHY TABLE A-1 IS NOT IN THIS SLICE.** Identification rows and stress values
are in SEPARATE page blocks - 35 in the SI table - joined only by a line
number. A first parse got 55 of ~600 and mis-joined several. A wrong S gives a
wrong thickness and nothing downstream can tell. **Slice 3** must parse the two
block kinds separately, join on line number, and ASSERT that every line
resolves to exactly one material and one contiguous temperature series before
any value is trusted.

Wider run **494 passed / 9,384 assertions**.

## 5bj. ASME B31.3 slice 3 - the allowable stress, and two gates (2026-09-25)

**Slice 3 of 3. The B31.3 sequence is complete.**
`standards-packs/reference/allowable-stress.ref.json` (+ schema, +
`check_allowable_stress()`) - `app/Support/Reference/AllowableStress.php` -
`standards-source/tools/parse_b313_a1.py` and `build_b313_stresses.py` -
`AllowableStressTest` **11**.

**THE TABLE IS A TRAP AND MY FIRST TWO PARSES FELL IN.** Identity and stresses
are in SEPARATE page blocks joined only by a line number, and **that number
RESTARTS in every material section**. Three faults, all caught by assertions
rather than by reading:

1. **line numbers restart per section** - 40 duplicate lines, 29 curves that
   RISE with temperature. Join on **(section, line)**.
2. **the identification header changes between sections** (carbon steel adds
   *Size* and *P-No.*) - 126 rows read of ~1 120. Anchor on the **spec
   number**, not on column position.
3. **stress values are integers as well as decimals** (`103`, not just `97.5`)
   and the temperature header reads `No.  to 40` - 194 rows read of 1 120,
   with a **STALE temperature list** applied to the rest. Accept integers, and
   **clear `temps` at every page break** so a header that fails to parse yields
   NO rows instead of wrong ones.

**TWO GATES.** A value is citable only if the structural join held AND its
room-temperature figure agrees with para. 302.3.2,
`S = min(UTS/3, 2/3 yield)`, computed from tensile and yield figures in
`materials.ref.json` that came from the ASTM specs, not from this table.
**77 of 798 citable, 721 held** with `HELD_NOT_INDEPENDENTLY_CONFIRMED`.

**THE EVIDENCE THE JOIN HELD IS AN ABSENCE:** of the checkable materials, 21
are BELOW the basis and **none is above it**. Below is ordinary (a class, a
weld joint efficiency, a time-dependent criterion). Above is impossible under
the Code. **A mis-join scatters BOTH ways**, so all-below is the signature of a
sound join.

**THE CHAIN RUNS END TO END:** NPS 6 A106 B at 100 C, Class 150, 3 mm CA ->
S = 138.0 MPa, Y = 0.4, t = 1.188 mm, tm = 4.188 mm. Sch 40 is 7.11 mm, so an
ordinary class is comfortable - the answer a reviewer expects, and the reason a
wrong S would be invisible. When the material is HELD the chain STOPS rather
than bending: no S, no thickness, and a refusal naming the missing input.

Wider run **505 passed / 44,011 assertions**.

**B31.3 IS NOW DONE TO THE DEPTH THE PLATFORM USES IT.** Still unread and
declared: Table 341.3.2-1 acceptance criteria (the A-to-M matrix), Tables A-1A
and A-1B quality factors Ej and Ec, Table 302.3.5 W, Chapter VIII (Category M),
Chapter IX (High Pressure).

## 6. The preview iframe (read before debugging "login is broken")

The preview serves the app in an **iframe whose top-level document is a
different site**, so its cookies are third-party and the browser blocks them.
Symptom: login succeeds server-side, the redirect arrives anonymous, the form
re-renders blank, and **nothing is logged**.

**Five rounds of cookie-attribute tuning failed.** The browser's policy is not
observable from the server, so it cannot be satisfied — the dependency had to be
removed instead. Current mechanism (both positions load-bearing):

| Middleware | Position | Job |
|---|---|---|
| `PreviewSessionFallback` | **first** in `web`, before `EncryptCookies` | Converts a signed `sid` token from the URL/body into a genuine session cookie |
| `PreviewUrlRewriter` | **last** in `web` | Appends the token to links, forms, redirects, Livewire URIs and JSON `redirect` |

Traps, all paid for in debugging time:

- The forged cookie **must be encrypted and carry the `CookieValuePrefix` HMAC**,
  or `EncryptCookies` silently discards it.
- The injected script must observe **`childList` only** — watching attributes
  retriggers on its own writes: infinite loop, white page.
- **Exceptions bypass the whole middleware pipeline**, so `PreviewUrlRewriter::apply()`
  is also called from `$exceptions->respond()`.
- **`URL::forceScheme()` does not touch `$request->fullUrl()`** — `redirect()->back()`
  emitted `http://` and the browser blocked it as mixed content. Fixed by marking
  the connection secure in `ConfigureForPreviewProxy`.
- **`$middleware->replace()` does not touch group middleware** — it fails
  *silently*. Use the `*InGroup` variants.
- **Diagnose from the `sessions` table, not the log:**
  `select to_timestamp(last_activity)::time, count(*), count(user_id) from sessions group by 1 order by 1 desc;`
  Many rows with 0 authenticated per page load ⇒ the cookie is not coming back.
- **Test over `http://` with a preview `Host`.** Driving tests at `https://`
  makes the request secure by construction and silently disables the condition
  under test — one guard here was inert until a mutation test caught it.

The whole mechanism is gated on a preview host **and** `SESSION_URL_FALLBACK`
(default **off**). On a real deployment it is inert.

---

## 7. Testing discipline

```bash
cd app && php artisan test                          # 667 passed / 2751 assertions (~1050 s)
python3 standards-packs/validate_packs.py           # 3 standards + 4 reference packs
node app/tests/Browser/sidebar-toggle.test.cjs      # 17 assertions, needs a fixture
node app/tests/Browser/sidebar-animation.test.cjs   # 19 assertions, computed styles
node app/tests/Browser/table-tools.test.cjs         # 42-47 per page, needs /tmp/tt.html
#   fixtures: SB_FIXTURE= / TT_FIXTURE= override the default paths. Capture a
#   logged-in page first - these drive the REAL shipped script in jsdom.
```

The jsdom tests need `jsdom` at `/home/user` (wiped by every reset — **`restore.sh`
now reinstalls it**) and a captured logged-in page; override with `SB_FIXTURE=`
for the sidebar harnesses and `TT_FIXTURE=` for the table harness. **A missing
`jsdom` prints nothing at all**, which reads like "no tests to run" rather than
a failure — if a harness produces no output, check `node_modules` first.
The app's `package.json` sets `"type": "module"`, hence the `.cjs` extension.

- **A guard test that passes may be inert.** Mutate the fix away and watch it go
  red. **153 mutations have been confirmed caught** this way (123 + 15 pack-data + 15 PHP in phase 0); the practice has
  already exposed three false-confidence tests (the mixed-content guard, a
  sidebar entitlement check, and "the contractor cannot verify its own fix"),
  a guard made inert by its own documentation, and a test that encoded the
  wrong model entirely.
- **`display:inline-block` on a `<td>` silently breaks the whole table.** It
  replaces `display:table-cell`, so the cell leaves the column layout and every
  following column shifts by one - the header ends up over the wrong data. Cell
  COUNTS still match, the DOM still looks right, and jsdom cannot see it
  because jsdom has no layout engine. Diagnosed by measuring
  `getBoundingClientRect()` per cell in real headless Chromium. `.mono` (an
  LTR-run helper meant for `<span>`) had been applied to `<td>` on two screens.
- **Per-page markup drifts, and a one-fixture harness cannot see it.** The
  sidebar harnesses ran against a fixture from a SINGLE page, so three newer
  screens that nested the shell differently were invisible to 345 passing
  browser assertions. `ShellStructureTest` now asserts the nesting order
  (`.app` > `aside.sb` > `.wrap` > `.topbar`) on EVERY screen via a data
  provider. When a partial has a required position, test the position.
- **`@{{ $var }}` silently prints braces.** `@{{ }}` is Blade's escape syntax
  for JS templating. Written as a literal `@` separator it disabled the
  variable and shipped `ips.iran{{ $packVersion }}` to the page. A regex sweep
  for `{{ $` in rendered HTML now covers every screen.
- **A working endpoint is not a reachable feature.** The certificate PDF route
  passed every test while the download link sat inside an `isIssued()` branch,
  so a draft had no way to reach it. Found by loading the live page, not by the
  suite. When you add an endpoint, assert the UI actually links to it.
- **A guard test can be caught by the WRONG GUARD.** `expectException()` only
  proves *something* refused. The self-verification test passed with its guard
  deleted, because the acting company was an `EPC` — a tenant type that is not
  a verifier at all — so a different rule threw. Assert on the **message**, and
  choose fixture actors that can only fail the rule under test.
- **A test can encode the WRONG MODEL and still pass.** `the employer sees every
  letter on its project` asserted a count of 3 and was green — it was asserting
  that the employer could read another company's unsent draft. When a guard
  fails after you fix a defect, first ask whether the *test* was wrong.
- **A jsdom harness can encode one page's shape.** `table-tools.test.cjs` has
  now been fixed three times for this: it assumed the register's column layout,
  then a minimum row count, then a CDE-state column. Assert against what the
  markup *declares* (`data-sort`, `data-facet` counts) rather than magic
  numbers, and use `skip()` so a rule that does not apply to a page can never
  be mistaken for a rule that passed.
- **The oracle must use the same collation as the page.** The harness sorted
  with `<` and flagged correct Persian ordering as a bug: "گ" precedes "م" for
  a reader but follows it by code unit. Both sides now use `Intl.Collator`.
- `DatabaseTransactions` collides with the seeders — use `migrate:fresh --force`
  in `setUp()`.
- CSRF cannot be exercised through HTTP feature tests (`PreventRequestForgery`
  short-circuits on `runningUnitTests()`); call the handler directly.
- `assertRedirect()` is too strict once a query parameter is appended — assert on
  the `Location` header with `assertStringStartsWith`.

---

## 8. Known traps (non-obvious, will cost you an hour each)

- **A LINE NUMBER THAT JOINS TWO TABLES MAY RESTART PER SECTION.** B31.3 Table A-1 has a line 1 in
  the iron section, another in carbon steel, another in stainless. Joining on the number alone
  merges unrelated materials and the only outward sign is a stress curve that RISES with
  temperature. Always find the section boundary and join on (section, line).

- **CLEAR A PARSED TABLE HEADER AT EVERY PAGE BREAK.** If a header fails to match, a stale one from
  the previous page will be applied to this page's rows, binding real numbers to the wrong columns.
  Clearing it turns a silent mis-read into an obvious gap.

- **AN ALL-ONE-DIRECTION DISCREPANCY IS EVIDENCE OF A RULE; A SCATTERED ONE IS EVIDENCE OF A BUG.**
  21 allowable stresses sat below the 302.3.2 basis and none above it. A mis-join would have
  scattered them both ways. Check the DIRECTION of disagreements before assuming a parse fault.

- **A CALCULATOR MUST REFUSE PAST ITS OWN VALIDITY LIMIT.** B31.3 304.1.2(b) withdraws the
  thin-wall equation at t >= D/6 or P/SE > 0.385. Code that keeps multiplying past it returns a
  number that looks exactly like a right one. Transcribe the limits with the formula, always.

- **AN "AND BELOW" / "AND ABOVE" COLUMN IS A BAND, NOT A POINT.** Table 304.1.1-1's outer columns
  cover everything beyond them. Treating them as points leaves an ordinary 20 C line with no
  coefficient.

- **A CONDITION IS NOT A SERVICE, AND ONE ENUM CANNOT HOLD BOTH.** `fluid_service_category` carried
  SEVERE_CYCLIC as a sixth value for five rounds, which made "Category M under severe cyclic
  conditions" - an ordinary line - impossible to record. When one value in an enum answers a
  different question from the others, it is a second axis wearing a disguise.

- **EVERY SINGLE-PRODUCT-FORM CLAIM THIS REPO MADE HAS BEEN WRONG.** API 600, 602, 603, 623 and
  ISO 15761 all say "cast or forged". Five for five. The three BS entries still claim one and are
  unverified - treat them as wrong until a purchased copy says otherwise.

- **AN ISO STANDARD MAY RATE THE SAME VALVE IN TWO PRESSURE SYSTEMS.** ISO 15761 carries ASME Class
  AND PN, and they do not map one to one (Class 800 has no PN equivalent). Any field typed as "the
  pressure class" silently loses the PN half of a European sheet.

- **A DECLARED ANOMALY MAY BE TWO THINGS IN ONE COLUMN.** The B16.10 gate column that "shortens"
  from NPS 34 to NPS 36 is not an error and not non-monotonic: it holds the ordinary valve AND the
  through-conduit valve, which note (14) prices differently. When a curve misbehaves, ask whether
  the column is really one thing before calling it a misprint.

- **NOT EVERY VALVE STANDARD NUMBERS ITS TRIM.** API 600, 623 and 594 print numbered trim tables;
  API 599 prints none and leaves plug valve seat/sleeve/facing to the purchase order. A "trim
  number" control for plug valves offers a vocabulary that does not exist - and the library had
  invented two codes to fill it. Check the standard has the concept before modelling the field.

- **A TRIM NUMBER IS NOT GLOBALLY UNIQUE.** The API valve standards share one numbering family:
  API 600 trim 8, API 623 trim 8 and API 594 trim 8 are all real and all different. Any lookup,
  assertion or dropdown keyed on the trim code alone will cross standards silently. Key on
  (standard, code) - always.

- **THE DIMENSION SOURCE CAN DEPEND ON THE VARIANT.** API 594 Type A is dimensioned by API 594
  itself, Type B by ASME B16.10. Holding only B16.10 does not fail loudly - it quietly returns a
  Type B length for a Type A valve.

- **A PLAUSIBLE SIMPLIFICATION OF A REAL RULE IS THE HARDEST INVENTION TO SPOT.** The trim chart
  carried `requiresOversizeStem` as a per-trim boolean. API 623 clause 5.8.1 does require the stem
  material's strength to be accounted for - but as a design obligation on the manufacturer, not as
  a flag against a trim number. The invention was not wrong in spirit, which is why it survived
  five rounds of review.

- **A SUBSTITUTION TABLE IS PER STANDARD, NOT PER TRIM NUMBER.** API 600 Table 9 lets trim 8 be
  supplied as trim 5; API 623 Table 9 does not, and adds 2 -> 10 instead. Same trim numbers,
  different standards, different alternatives. Never hang alternatives off the trim code alone.

- **A "CLASS" ON A NAMEPLATE MAY BE A BOLTING PATTERN, NOT A PRESSURE CLASS.** API 609 Category A
  butterfly valves are rated at a manufacturer's cold working pressure and carry a class 125 or 150
  FLANGE BOLTING PATTERN. Reading that as an ASME pressure class and looking up a B16.34 shell
  rating invents a figure nobody claimed.

- **DO NOT INVENT A WIN.** The pattern "every product-form claim collapses" was three for three and
  the fourth entry never made the claim at all. The validator caught it: a correction whose `was`
  equals its `now` is not a correction. Check what the entry actually said before writing the story.

- **A VALVE'S RATING IS NOT ITS SHELL RATING.** API 608 clause 4.1: the lesser of shell and seat.
  A Class 600 shell is 102 bar; the same valve with a PTFE seat above DN 100 is 19.7 bar. Any code
  that answers "what pressure does this valve hold" from B16.34 alone is wrong by a factor of five
  on every soft-seated ball valve. Use `ValvePtRatings::governingRatingAt()`.

- **SCOPE CAN DEPEND ON THE END TYPE.** API 608 gives socket-weld and threaded ends a different
  size ceiling (NPS 2 vs 24) AND a different class list (800 yes, vs flanged 800 no) from flanged
  and butt-welding. A single npsMin/npsMax/classes triple silently flattens four different scopes
  into one.

- **AN UPLOAD CAN HAVE THE RIGHT FILENAME AND CONTAIN NO STANDARD.** A "copy of API 608" was 36
  pages of a document-sharing site's paywalled preview, with a clean text layer that extracted
  perfectly. Run `standards-source/tools/check_extraction.py <file>.txt` before transcribing a word.
  Tell-tales: a few hundred latin words instead of thousands, CJK or other site chrome, no numbered
  tables, and phrases like "downloading this document requires N credits".

- **A FIELD THAT KEEPS RETURNING THE SAME ANSWER IS MEASURING THE WRONG THING.** `bodyGroups`
  asked which product form a valve standard permits. Three purchased standards later the answer is
  always "cast or forged", and API 603 does not turn on product form at all - it restricts the
  B16.34 MATERIAL GROUP. Before encoding a constraint, check the standard is actually constraining
  that axis.

- **A SINGLE-PRODUCT-FORM CLAIM ABOUT A VALVE STANDARD IS PROBABLY FOLKLORE.** "API 600 is cast,
  API 602 is forged" is what the industry says and what we encoded. API 600 Table D.1 lists
  "A105 or A216-WCB"; API 602 Table 1a lists A216 WCB beside the forgings. Two for two. Six such
  claims remain in `valve-construction`, all unverified - treat them as suspect, and make any
  finding built on one say so.

- **AN UNDATED NORMATIVE REFERENCE MEANS THE LATEST EDITION, WHICH CAN BEAT THE PRINTED TABLE.**
  API 602-2015 cites ASME B16.34 with no date. Its own Class 800 table is therefore a snapshot of
  a rule against a decade-old edition; the rule applied to B16.34-2025 is what actually governs.
  Check how a standard cites its references before treating its tables as final.

- **A TWO-SCRIPT PIPELINE WITH ITS MIDDLE IN `/tmp` ONLY EVER RAN ONCE.** `parse_b1634.py` wrote
  `/tmp/b1634_raw.json` and `build_b1634_pack.py` read it; after any sandbox reset the build half
  failed and the pack could not be reproduced from its own source. Intermediates belong in the
  workspace next to the tool, not in `/tmp`.

- **A VALIDATOR RULE THAT FIGHTS A TRANSCRIPTION IS USUALLY RIGHT.** Four rules rejected the API 600
  Table 8 shape in turn — spec/grade pairing, part resolution, seat hardness, requiredParts — and
  each time the standard's shape was the better one. Change the rule to match the STANDARD, never
  the data to match the rule, and add the sentinel explicitly when a standard genuinely defers.

- **A STANDARD'S OWN SENTINELS MUST BE CARRIED THROUGH BOTH HALVES OF A PAIR.** `MFG Standard` in a
  specification with a real-looking grade beside it reads as data. Put the sentinel in both, and
  make the validator insist on it.

- **A PURCHASED STANDARD CAN BE A SCAN.** API 600-2021 arrived at 109 dpi with no text layer.
  `pdftotext` returns nothing at all — check `pdffonts` (empty) and `pdfimages -list` before
  concluding the file is broken. Prose OCRs well at `pdftoppm -r 300` + `tesseract --psm 6`;
  **portrait pages must not be rotated**, landscape tables need `rotate(-90, expand=True)`, and
  dense numeric tables are not trustworthy at that resolution at any setting. Verify the prose,
  transcribe no tables, and record why in the pack.

- **OUR OWN `all()` PROJECTIONS HIDE NEW FIELDS.** `ValveConstruction::all()` maps each standard
  onto a fixed key set. Add a field to the pack, read it through `get()`, and you get the default
  forever with no error. Grep for `array_map` projections before assuming a reference reader
  passes data through.

- **"API 600 IS CAST, API 602 IS FORGED" IS FOLKLORE.** Table D.1 of API 600-2021 lists the body as
  "A105 or A216-WCB". Industry shorthand that everyone repeats is exactly the kind of claim the
  `verified:false` flag exists to catch.

- **A MERGED CELL HAS NO POSITION IN A TEXT LAYER.** API 598 Table 1 prints one "Optional" spanning
  four size-and-class bands; `pdftotext` drops it on a line of its own, halfway down, aligned with
  nothing. Do not infer merged matrices from whitespace — read them and write the reading down as
  data, with the footnote references attached.

- **A BAND THRESHOLD CAN MOVE WITH ANOTHER AXIS.** API 598 splits its closure rows at Class 1500 for
  DN ≤ 100 and at Class 600 for DN > 100. Modelling it as one class boundary is wrong for every
  large valve between the two, and every individual row still looks plausible.

- **A STANDARD'S OWN ERRATA CAN BE BOUND AT THE FRONT OF THE PDF.** ASME B16.10-2022 carries its
  March 2023 corrected pages 13 and 21 *before* the title page. Parse the file in order and the
  faulty originals, which come later, silently overwrite the corrections. Parse errata last and
  report how many cells they changed.

- **A NOTE MARKER BELONGS TO THE VALUE IT TOUCHES.** `21 (0.87)      (3)` is a value in one column
  and a note-only cell in the next. A suffix pattern of `(?:\s*\(\d+\))*` swallows the `(3)`,
  merges two columns into one and shifts every figure after it one column left — with no error.
  Bound the gap: `\s{0,2}`.

- **A BARE `0` ALTERNATIVE IN A CELL REGEX MATCHES THE ZERO INSIDE `20`.** Use `\b0\b`. The symptom
  is rows that come out exactly one cell too wide.

- **KEY A DIMENSION TABLE ON DN, NOT ON THE PRINTED NPS.** Vulgar fractions are typeset with the
  whole number on the line above, so `NPS 1 1/2` reaches the text layer as `40 (1 ∕2)` — read
  literally, NPS ½, a different valve. DN is never split. Take the value cells out of the line
  first and whatever size token is left in the residue is the row's.

- **A CELL IN A STANDARD'S TABLE CAN CARRY ITS OWN NOTE MARKER.** B16.34 prints `1.4 (4)` as one
  value. A parser that demands seven bare numbers per row rejects that line, and the table still
  looks perfectly parsed — group 1.8 Standard Class silently lost its top five temperatures. Strip
  `(n)` from the value part before splitting, then assert the row width.

- **`pdftotext` is NOT in the base image.** `sudo apt-get install -y poppler-utils` fails with 404s
  until `sudo apt-get update` has run. Budget a minute before any PDF round.

- **A LEAKED `idle in transaction` BACKEND CAN OUTLIVE EVERYTHING.** After an OOM kill, PostgreSQL
  may also be left "shutting down" with a stuck checkpointer; `service postgresql restart` then
  hangs forever behind systemd. Recover with
  `sudo kill -9 <postgres pids>` and let the cluster replay its WAL (60–90 s), not with `service`.

- **A LEAKED `idle in transaction` BACKEND BLOCKS EVERY LATER `migrate:fresh`, FOREVER.** Kill a
  test run (timeout, OOM, Ctrl-C) and its PostgreSQL backend survives holding an open transaction
  and its table locks. The next suite's `migrate:fresh` waits on `DROP TABLE` with no error and no
  progress — it looks like a hanging test, and it is not. Diagnose and fix:
  `SELECT pid, state, now()-state_change FROM pg_stat_activity WHERE datname LIKE 'epcc%'`, then
  `sudo kill -9 <pid>` (a `pg_terminate_backend` issued from the same crippled pool may not land).
  One such backend was also holding **985 MB** of a 2 GB box.

- **A BLANKET string replace can rewrite the DEFINITION of the helper it is meant to call.**
  Replacing `$this->valves()->findingsFor($item)` with `$this->findings($item)` across a test file
  also rewrote the body of `findings()` itself into `return $this->tenant(fn () => $this->findings($item));`
  — infinite recursion. It presented as a *hang*, not a stack overflow: the process grew to 1.4 GB,
  the OOM killer took it **and** PostgreSQL, and the leaked backend then blocked every subsequent
  run (see the trap above). Three hours of "the environment is broken" for one careless `replace`.
  **After a blanket replace, grep the function whose name you introduced and read its body.**

- **An ADR mixes the user's decisions with the author's analysis, and only the first kind binds.**
  ADR-008 §11.9 asserted "a class body holds two kinds of row"; step 6 built it; the user then said
  specials do not belong in the PMS at all. The paragraphs headed **User decision** in that same
  section had never said so. Before building a section, split it into what was decided and what was
  argued. (§5ao.)

- **`NULLS NOT DISTINCT` cuts both ways.** Correct when NULL means "this row genuinely has no
  qualifier" (the body axis). Catastrophic when it means "this row is not that kind of row" — the
  special axis silently merged every standard row at a size into one slot, undoing the qualifier key
  one migration after it was added. Use a **partial unique index** for the second case. Caught only
  because a test asserted WN-and-blind-at-one-size.

- **A pipe-delimited mutant file cannot carry a `|` inside a pattern.** M100's regex contained
  `(CL|CLASS)`; the record split, the pattern never applied, and the harness reported **SURVIVED**
  for a mutation that never happened. Keep delimiters out of patterns, and remember a SKIP must be
  as loud as a survivor.

- **A mutation whose pattern matches twice mutates the FIRST occurrence.** M97 appeared on both the
  nozzle row and the port row and kept hitting the nozzle, where nothing reads it — looking exactly
  like a missing test. Check which occurrence you hit before writing a test.

- **`max(sequence) + 1` reissues the numbers of DELETED rows.** It is not a concurrency bug and a
  lock does not help: the counter simply cannot see what is gone. Any number that leaves the system
  — on a drawing, a test pack, a PO — needs either an issued-number ledger or, better, an external
  authority that already keeps one.

- **A backfill must disable RLS on every table it READS, not just the one it writes.** The systems
  migration disabled `subsystems` (its target) but not `facilities` (its source). With no tenant
  GUC set an RLS-protected table returns **zero rows rather than erroring**, so
  `INSERT ... SELECT` reported `INSERT 0 0`, the backfill silently did nothing, and the migration
  then died on the NOT NULL with an opaque `23514` that pointed at the wrong thing entirely.
  Disable every source table, and make the migration **assert the backfill matched something** so
  the next person gets a sentence instead of a constraint code.

- **"Count the seeded rows" does not test a NOT NULL.** If the seeder never writes a null, the
  assertion passes with or without the constraint — mutants M83/M85 survived on exactly this. To
  pin a constraint you must **attempt the forbidden write**, not observe that it never happens.

- **A bidi-isolated NUMBER is not enough — isolate the whole phrase.** `<bdi>6</bdi> welds ·
  <bdi>2</bdi> fitted` renders in Farsi as **"۶ جوش ۲۰ مونتاژشده ۲۸۰"**: the `·` separator is a
  neutral character sitting between a digit run and a word, so the algorithm attaches it to the
  digits and it reads as part of the next number. The digits were right; the punctuation moved.
  Wrap `number + label` together in one `<bdi>`, and use a separator inside its own element.

- **A mutation run killed by a timeout leaves the mutant IN THE SOURCE.** The harness restores the
  file after each test run; kill it mid-run and the last edit survives. It then looks like working
  code — `if (false) {` sat in `JointService::join()` and the next partial run reported SKIP
  ("pattern did not apply") rather than a failure. After any interrupted run:
  `grep -rn "if (false)" app/app/` and check `git diff` before believing anything.

- **A SKIP from the mutation harness is not a pass.** It means the pattern never applied, so
  nothing was broken and the test suite proved nothing. Treat SKIP as loudly as SURVIVED — the
  harness exits non-zero on either.

- **An equivalent mutant is not a surviving test gap.** `min(null, 6.0)` is already `null` in PHP,
  so deleting the null guard in `diaInch()` changes no observable behaviour. Before writing a test
  to kill a survivor, check the mutation actually alters the result — otherwise you are adding a
  test that asserts the language spec. Retarget the mutant instead (`return 0.0`).

- **`LineReleaseState` is a class of string constants, not an enum.** `LineReleaseState::RELEASED->value`
  throws "Attempt to read property value on string". Use the constant directly.

- **Forcing `release_state = RELEASED` needs TWO provenance pairs, not one.** `afc_confirmed_at`
  + `afc_confirmed_by` **and** `released_at` + `released_by`. Supplying only the AFC pair fails
  with a 23514 that looks exactly like the guard you are trying to test firing.

- **A standalone bootstrap script needs its own `<?php` tag and absolute paths.** `php artisan
  tinker --execute="require '/tmp/x.php'"` printed the file instead of running it (no opening
  tag), and `__DIR__` inside a `/tmp` script resolves to `/tmp`, not the app.

- **Outside a tenant binding, `firstOrFail()` on an RLS table looks exactly like "the seeder never
  ran".** The rows are there; the role cannot see them. Use
  `TenantContext::forIdentityLookup(...)` to resolve the project and user, then `runAs(...)`.
  Confirm with `sudo -u postgres psql` before re-seeding — re-running the seeder hits
  `companies_code_unique` and buries the real cause.

- **When the engine refuses your demo data, check the pipework before the code.** Welding a
  flanged valve directly onto a pipe was refused by `PortCompatibility`; the model was wrong, not
  the rule. The fixture needed a flange, exactly as site would.

- **PCRE alternation is first-match, and backtracking usually hides it.**
  `A1|A1B` against `A1B-…` *works* — the engine tries `A1`, fails the next
  separator, retries `A1B`. So a naive test passes and you conclude ordering
  does not matter. It matters when the following token can absorb the
  difference: `PH-14|PH-14-SS` against `PH-14-SS-2201` matches both ways and
  keeps the **wrong** split, silently attributing a line to the wrong
  subsystem. Sort vocabularies longest-first. See §5r.
- **A test can pin a rule for the wrong reason.** The AFC gate needs
  `PUBLISHED` **and** an A-family suitability. The obvious negative fixture,
  SHARED+S2, fails the A-check on its own — so the `PUBLISHED` half was never
  actually exercised and deleting it left the suite green. Mutation testing
  found it; the fixture that separates the two conditions is **SHARED + A1**.
  When a rule is a conjunction, write one test per conjunct that satisfies
  every *other* conjunct. See §5r.
- **RLS `WITH CHECK` does not apply to DELETE.** A RESTRICTIVE policy with a
  `WITH CHECK` clause blocks INSERT and UPDATE and lets every DELETE straight
  through — there is no new row to check, so the clause is never evaluated.
  A read-only guard therefore needs a *second* policy `FOR DELETE USING (...)`.
  Found by trying it: an "archived" project's punch list deleted cleanly,
  `DELETE 9`. See §5q.
- **A blocked UPDATE/DELETE under RLS is SILENT.** INSERT raises 42501 naming
  the policy, but UPDATE and DELETE simply match zero rows: no error,
  `rowCount = 0`, and Eloquent's `save()` returns **true**. The data is safe and
  the user is told the opposite. Never let RLS be the only thing refusing a
  write that a human is waiting on — refuse in a service first.
- **A `SECURITY DEFINER` helper is mandatory when a policy reads another
  RLS-protected table.** `epcc_project_is_writable()` reads `projects`; without
  `SECURITY DEFINER` it sees only the currently scoped project, returns NULL for
  every other one, and the policy **fails open** — the most dangerous direction.
- **`__()` was only localised inside the Filament panel.** `SetUserLocale` was
  registered on the admin panel alone, so on every other route the translator
  used the default locale. The hand-written views hid this for months because
  they take language from their own `?lang=` closure — the page was Persian and
  only the `__()` strings were English. It surfaced the moment a *service* threw
  a translated exception. Now registered on the `web` group too, before
  `PreviewUrlRewriter` (which must stay last).

- **`pg_dump` exits 0 after an RLS abort.** Under `FORCE ROW LEVEL SECURITY` the
  dump fails part-way and still returns success, leaving a truncated file. Never
  trust the exit code — compare the output size or the row counts against a
  manifest. See §5p.
- **A topological sort over the schema drops any FK cycle.** `documents` and
  `document_revisions` reference each other, so a recursive dependency ordering
  returns 21 of 25 tables and loses the whole document register without error.
  Order such lists explicitly, and load with
  `SET session_replication_role = replica`.
- **`x IS NULL OR col = x` in an RLS policy disables every index on `col`.** It reads
  like a harmless convenience ("unbound means all") and it silently forces a
  sequential scan forever. At fixture scale a seq scan is the *correct* plan, so
  this is invisible until a million rows exist. Check `Rows Removed by Filter`
  in `EXPLAIN (ANALYZE)` — if it is orders of magnitude larger than the rows
  returned, the predicate is not sargable. See §5o.
- **Benchmark a security policy and you will select the insecure version.** The
  fastest form of the tenant policy is the one that returns zero rows for the
  cross-project view. Every correctness probe must be run alongside the timing,
  in the same script, or the fast-and-wrong answer wins.
- **The backup was archiving itself.** `tar -czf source-backup.tar.gz .` from the
  repo root includes the *previous* `source-backup.tar.gz`, so each refresh
  nests the last one and the file roughly doubles. It also swept in republished
  Filament assets (`app/public/{js,css}/filament`, ~3 MB) and compiled Blade
  views, all of which `restore.sh` regenerates. Exclude the tarball itself, the
  published assets, `storage/framework/views` and `storage/framework/testing`;
  then **diff the entry list against the old one** — a pure size comparison
  cannot tell "captured more source" from "captured more garbage". Current:
  **3,469,495 B / 418 entries**, extraction verified in `/tmp` before replacing
  the old file.
- **Numeric string keys are integers in PHP.** `json_decode(..., true)` turns
  `"40"` into array key `40`, so `array_keys()` on a schedule table returns a
  mixed `int|string` list. Strict `in_array()` then fails silently and passing a
  key to a `string`-typed parameter is a `TypeError`. Cast at the boundary with
  `array_map(strval(...), …)`.
- **An assertion about data only tests the direction the data exercises.**
  `PipeReferenceTest` asserted STD ≠ SCH 40 at 12″+, which felt like complete
  coverage of the alias question. It was not: a fallback from a missing schedule
  to its "equivalent" survived, because STD is never the missing one. The
  reachable case is the reverse — ASME defines STD at 22″/26″ but no SCH 40
  there at all. Only mutation testing found it.
- **Anything that reaches the internet is invisible in a connected sandbox.**
  A URL assembled at runtime (Filament's avatar provider) exists in no file, so
  grep and any static scan report a clean build. Capture a browser net-log, or
  assert on rendered HTML. `php artisan verify:airgap` is the standing check.
- **Render at a phone width before believing a page works.** A page can be
  200, structurally perfect and still paint nothing; in RTL an overflow does
  not clip, it evicts the document from the viewport. Measuring unique colours
  in a screenshot is a cheap, reliable blank-page detector (1 colour = blank).
- **A document that asserts something about a moment must snapshot it.** Any
  number a certificate, transmittal or approval renders from a live query will
  drift after the fact, and the drift is not random — it flatters, because the
  work continues in the direction the document was hoping for. Ask of every
  figure on an issued artefact: *is this a fact about then, or a report about
  now?* (§5e). Test it by producing the artefact twice with a real change in
  between; a single render can never show this.
- **`pkill -f "artisan serve"` kills the calling shell.** Use the process tools.
- **`artisan serve` caches `bootstrap/app.php`** — restart it; `config:clear` is
  not enough.
- **Sandbox resets wipe the runtime, not the source** (7× so far). Snapshots cap
  at ~10,000 files / 128 MB and truncate *silently*, which is why
  `app/vendor` is a symlink into `~/.cache/epcc-vendor`.
- **Seeded attachments will blow the file cap if nothing purges them.** Every
  `migrate:fresh --seed` mints new project UUIDs and writes real blobs under
  `storage/app/private/documents/{project_id}/{ab}/{cd}/`. Nothing removed the
  previous run's tree, so it reached **5,066 project folders / 12,963 files**
  and pushed the workspace to 13,263 — past the cap, which truncates in
  silence. The content-addressed fan-out makes this a file-**count** problem
  (~31,000 directories) long before it is a disk-space one (58 MB).
  `tests/bootstrap.php` now purges that tree **before and after** every suite,
  and `BlobIntegrityTest` re-verifies each surviving blob against its SHA-256
  so the purge can never quietly delete live data.
  **Check `find /home/user -type f -not -path "*/vendor/*" -not -path "*/.cache/*" | wc -l`
  when anything writes files in a loop.** It should be in the low hundreds.
- **Never pin `APP_URL` to a sandbox host** — it is stale after the next reset.
  It stays `http://localhost:8000`.
- **The pack ID is `ips.iran`, but it is addressed by basename `ips-iran`** in
  the database and forms.
- **`WithoutModelEvents` in a seeder breaks `HasUuid`.**
- **The `?as=` tenant-switch parameter is deliberately dead.** Do not reflect
  arbitrary query parameters into links — `ViewServiceProvider::CARRIED_QUERY`
  is an allowlist for exactly this reason.

---



### A new route under an existing `{param}` path gets swallowed

`piping/model-import` was declared AFTER `piping/{lineId}/model`, so Laravel
matched "model-import" as a lineId and the page 404'd inside a `findOrFail`
with nothing in the log to explain it. **Declare literal segments before
parameterised ones**, and there is now a test that fetches the URL.

### Do not call `__()` when STORING a system-generated reason

It freezes the writer's language into the row: the Farsi screenshot showed an
English quarantine reason. Store a machine key (`AUTO:FROM_FILE:…`) and
translate at render. **A user-typed reason is the opposite** — store it
verbatim, because translating somebody's own words is a forgery.

### Real PCFs indent the header's own fields

`PIPING-SPEC` and `REVISION` arrive indented under `PIPELINE-REFERENCE`. Read
as orphan attributes, the file looks like it has no spec — and the import then
SUCCEEDS with a blank one, which is worse than failing.

### A new seeder must be registered in DatabaseSeeder, not only called by tests

`ItpTemplateSeeder` was written, called explicitly by `ItpTest::setUp()`, and
never added to `DatabaseSeeder::run()`. The suite was green for a whole session
while `migrate:fresh --seed` produced a database with an **empty template
library** — the "create ITP from template" button had nothing to offer. The
docblock in that file already warns about exactly this, from the last time it
happened. Any new seeder goes into the `call([...])` list first, then into the
tests.

### A Blade view may carry its own translation array

`resources/views/itp/show.blade.php` builds a local `$t` map and `$L()` reads
from it — adding keys to `lang/{en,fa}/epcc.php` does nothing for that view and
the page dies with `Undefined array key`. Check where `$L` comes from before
adding a label. Its closure also takes no arguments, so `:placeholder`
replacement is not available there.

### A SKIPPED test reads as a pass and hides a missing fixture

`ItpTest` skipped its "a party with no role cannot sign" case because there was
no TPI company in the demo data at all — the very party the ITP grid is built
around. The suite was green and the guard was untested. Treat a skip in a new
test as a failure until the reason is understood; here the fix was to add the
company, not to soften the test.

### A refusal can come from the wrong guard and still look like a pass

The hold-cannot-be-waived test refused — but because the OWNER holds no role at
that clause, not because the stage was a hold point. Two guards on one path
again. When a test asserts that something is REFUSED, assert the message too:
`$e->errors()['result'][0]` pinned it to the right rule.

### Changing a shared seeder moves counts in unrelated tests

Adding one company to `EpccDemoSeeder` broke `SeedPathTest` (8→9 companies) and
`IdentityIsolationTest` (9→10 users). Those assertions are deliberate — they
pin the fixture — so grep for count assertions before touching the seeder.

### A column missing from `$fillable` is ignored by `update()` — silently

`baseline_tolerance_pct` was added by migration and used by the service, but
not listed on `ProjectPipingConfig::$fillable`. `update(['baseline_tolerance_pct' => 10])`
then returned `true`, changed nothing, and the tolerance test failed as though
the comparison logic were broken. Add the column to `$fillable` (and `$casts`)
in the same commit as the migration.

### `$model->fresh()` outside a tenant binding is an RLS error wearing a disguise

Calling `fresh()` on an RLS table outside `runAs()` raises
`SQLSTATE[22P02] invalid input syntax for type uuid: ""` — the empty GUC being
compared against a uuid column. It looks like corrupt data and is really a
missing tenant scope. Wrap the re-read: `$this->asOwner(fn () => $x->fresh())`.

### One printed table can use two column layouts

ASME B16.5's drilling templates name their last three columns differently at
Class 150/300 than from Class 400 up — a machine-bolt length in one, a
ring-joint stud in the other, same position. Extracting with a single mapping
produced 28 plausible wrong numbers and made the *existing* data look defective.
Read every table's own header, not the first one's. See §5ak.

### A new state can violate an OLD provenance CHECK

`piping_lines_afc_has_provenance` was written as "not DRAFT ⇒ must have AFC
evidence". Adding `DELETED` broke it instantly: a draft line withdrawn as a
duplicate has no AFC evidence and never will. The CHECK was correct when
written and wrong the moment a fourth state existed. **Before adding a state,
grep every CHECK on that table for the old state list** — they encode
"everything except X" assumptions that silently become false.

## 9. Suggested next steps

**Next (2026-09-24, after §5ak):** transcribe B16.47-2025 tables 32–42 so
Series A/B bolting stops being `hasRows: false`; then close
`piping_classes.material`; then NCR, then daily inspection reports.

> **Start here (2026-09-24).** The next piece of work is **PMS phase 4**, and its
> shape changed the day before you read this. **Read §5ah-3 and ADR-008 §11
> first** — the body axis, the P-T table, the class header and SP items were all
> re-analysed against real PMS documents and agreed with the user. The build
> order is at the end of §5ah-3. Steps 1 and 2 (a `b165MaterialGroup` property
> on grades, and a `flange-pt-ratings` pack) are reference data only and carry
> no migration risk, so they are the cheapest place to start.

> Everything below this line is the older running list, kept because it still
> holds items nobody has picked up.

**ADR-006 phase 1 — BUILT (2026-09-15).** `piping_lines`, the per-project numbering engine and
the AFC gate, with a working register screen at `/projects/{code}/piping`. See **§5r** for the
engineering content, the B-code trap and the 13 killed mutants. **Next is phase 2: ports and
components** (`components` → `component_ports`), then 2b the manual modelling UI, then 3 joints
with `diaInch()`.

**Phase 1b closed all three items phase 1 left open** (see §5s): the numbering editor exists, the
register has a document picker with detach, and `assertWritable()` turned out to be wired into all
six piping write paths already — it just had no test, which it now does (two, in `PipingLineTest`).

Left open by phase 1b, in the order I would take them:
1. **The pattern editor cannot reorder tokens.** Rows are edited in place and blank rows append;
   changing `{size}-{service}` to `{service}-{size}` means retyping both. Drag-and-drop needs
   JavaScript, but up/down buttons would not. Minor, and invisible until someone tries it.
2. **No preview of the next number while editing.** The screen shows the *current* sketch; it
   cannot show what the number would look like under the pattern being typed without a round trip.
3. **`insulation_types` is editable but unused** — no token reads it yet. Harmless, but it is the
   kind of half-wired field that later gets mistaken for dead code and deleted.

**ADR-007 (`docs/ADR-007-…md`) — ACCEPTED AND BUILT (2026-09-15, phase 0).** The engineering
reference packs: 36 sizes, 355 schedule pairs, 13 end types, 20 component templates, behind
`PipeReference`. See §5m for the traps. **This is phase 0 of the consolidated build order in
ADR-010 §5.**

ADR-006 is now **PARTLY BUILT** (phases 1, 1b, 2 and 3 of 7). ADRs 008, 009 and 010 remain PROPOSED (analysis only). Three questions at the end of
ADR-010 are still unanswered by the user: equipment tag uniqueness (per project vs per
facility — I lean per facility), whether an instrument register is needed now, and whether
equipment tags are imported-and-validated rather than allocated.

**ADR-004 (`docs/ADR-004-…md`) — ACCEPTED AND BUILT (2026-09-13).** `project → facility →
(optional) area → subsystem`. Investigated rather than assumed: Plant 3D has **no
System/Subsystem, no MC, no punch, no handover** — it is a design authoring tool whose
"hierarchy" is a fixed 3-node folder tree plus `Area`/`Unit` as **string tag subparts** in a
SQLite `.dcf` PnPDatabase, so compatibility means a documented **import mapping**, not a copied
schema. Built Option B: one `facilities` table with a `FacilityKind` discriminator, an
**optional** `areas` level (= Plant 3D Area = AWP **CWA**), per-kind addressing in validated
`jsonb`. `subsystem_id` did **not** move — all 6 dependent tables untouched. The four open
questions are now answered in ADR §5; the load-bearing decisions:

- Numbering is **per facility** — `UNIQUE(project_id, code)` became `UNIQUE(facility_id, code)`,
  so `PL-01/SS-01` and `PL-02/SS-01` are both legal. The admin register therefore shows Facility
  as a non-toggleable column: the bare code alone is now ambiguous.
- `subsystems_area_within_facility`, a **composite** FK `(area_id, facility_id) → areas
  (id, facility_id)`, makes "area belongs to another facility" unrepresentable rather than
  merely discouraged.
- `PIPELINE` is in `FacilityKind::all()` (importable) but excluded from `selectable()` — KP
  stationing is deferred, and the `addressing` jsonb means adding it is data, not a migration.
- `fullCode()` composes `"{facility} / {code}"` at read time; storing it would drift on rename.

Six mutations (M118–M123) confirm the new rules. **M118 first reported SURVIVED and that was
the mutation's fault, not the test's** — dropping a constraint with `psql` proves nothing when
`setUp()` runs `migrate:fresh` and rebuilds it. Mutate the **migration source**.

**ADR-006 (`docs/ADR-006-Piping-Line-Component-Joint-Model.md`) — PROPOSED, all ten questions answered (§10, §11). Ready to build; phase 0 is ADR-007.**
The user corrected a category error in ADR-005: isometrics and support drawings are not things
to *file*, the system needs **their content**. Model: `subsystem → piping_lines → components →
component_ports → joints`, with the port model adopted from Plant 3D's `PartPort` /
`P3dPartConnection`. Key findings: Plant 3D stores a connection as a **relationship row**
(part1/port1/part2/port2) and has **no joint number, no weld, no NDT** — the joint is *geometry*
there and a *construction object* here. **PCF is the import path**: ports are explicit
(`END-POINT x y z bore ENDTYPE`), a TEE really does emit 3 keypoints, and **welds are already
first-class** with `CATEGORY FABRICATION|ERECTION` = shop/field. Line numbering is
**per-project configurable** (the PMS is the authority, not the standards pack) and must
**parse as well as compose**, so the pattern is stored as typed tokens, not a `{}` string.
Compatibility returns **three** verdicts (VALID/WARNING/INVALID) — a boolean would make every
spec break either a lie or a blocker, the same reasoning as advisory inspection.

**The answers changed the shape.** Joint numbering is **per line and conditional**: welded and
threaded joints need a number, flanged ones do not. That makes `joint_number` nullable for *two
different reasons*, which must not be conflated — so `numbering_policy` (REQUIRED |
NOT_APPLICABLE) is stored, guarded by a **partial unique index** plus a CHECK. Spiked on this
PostgreSQL first (no partial-unique precedent existed in the schema): unnumbered flanges and
unnumbered imported welds coexist, duplicates rejected, a *numbered* flange rejected. Import
**must not invent numbers** — an unnumbered welded joint is a valid row and needs its own
worklist. Joints gained a state machine (**MODELLED → FITTED**, `fitted_by_code` + `fitted_at`
mandatory on entry); **`fitted_by_code` is a string, not a `users` FK** — verified there is no
personnel table and `users` holds office accounts, so a pipe fitter has no login and must not be
forced into the identity RLS policies. Supports are **part of the line but their own register**
(no ports, so no fake component rows). Spools are an **optional grouping** (nullable `spool_id`),
not a level — same reasoning as optional `areas` in ADR-004. `isometric_sheets` was **deleted**:
components derive from the **MTO** (`mto_item_no`, the missing link to
`material_requisition_items`), and the sheet is just a string on components and joints; pipes
carry `length_mm` under a type-conditional CHECK.

**Second round resolved the rest.** Joint number is a **simple integer sequence per line**,
welded and threaded sharing one counter, allocated under `lockForUpdate()` on the parent line —
stored as an int, padded only in the view (storing `"007"` turns sorting and next-number into
string surgery). **Flanged joints stay unnumbered** (my recommendation, accepted): a number
nobody uses gets quoted in reports and then has to be broken when the bolt-up register arrives
with its own scheme; they lose nothing meanwhile, since they are still rows, still
compatibility-checked. Supports get the **same typed-token numbering engine** as lines, fed by
the Standard Support / Support Details / Support List documents.

**ADR-007 (`docs/ADR-007-Reference-Data-Engineering-Standards.md`) — PROPOSED, new.** The user
asked where universal constants live «که به دیتابیس فشار وارد نکند». Answer: **versioned JSON
reference packs** beside `standards-packs/`, read-only, **no RLS** — and the reasoning matters:
RLS on reference data could only ever be "everyone can read", a policy that fails open, whereas
the real guarantee needed is *nobody can write, including the owner*. A file gives that
absolutely. It also dodges the ADR-004 trap where a seeder cannot read an RLS-forced table.
Split: **universal** (NPS/DN/OD, schedules, end types, flange ratings) → reference packs;
**per-project** (piping classes from the PMS, services, coatings) → normal tenant tables.

**ADR-010 (`docs/ADR-010-Equipment-Nozzles-and-Dia-Inch.md`) — PROPOSED, new.** Closes ADR-009's
three questions.

- **Dia-inch: the smaller diameter governs.** `min(portA, portB)`. Measured on a 5-weld sample:
  **19.0 vs 27.0** under the other reading — a **42% overstatement**, and on a job progressed or
  paid by dia-inch that is a commercial number. Computed, **never stored on the joint** (it would
  drift from the port sizes), *except* frozen into an approved progress snapshot. The test must
  contain **reducing** welds — an equal-bore fixture passes under either convention and proves
  nothing, the same trap as testing schedules only at 2".
- **Component templates get two tiers.** Universal set (PIPE/ELBOW/TEE/…/NOZZLE) ships read-only
  in the reference pack — a tee has three ports everywhere and that is not a project opinion.
  Projects may add their own (Victaulic coupling, vendor connector) but **may not shadow a
  universal code**; same closed-vocabulary / configurable-subset split as `FacilityKind::all()`
  vs `selectable()`.
- **Equipment: a nozzle is a port owner, so `joints` needs NO change.** Verified with a spike —
  one `ports` table, `owner_kind ∈ {COMPONENT, NOZZLE}`, a CHECK enforcing exactly one owner FK.
  A COMPONENT↔NOZZLE joint inserted with the joints table untouched; a mixed-owner row rejected
  by `check_violation`. Mirrors Plant 3D, where `AssetOwnership` links equipment to nozzles and a
  nozzle is a `NozzleSubPart`, not a line component. **Rejected** the alternative (separate
  `nozzle_ports` + `port_a_kind`/`port_b_kind` on joints) because every joint query would become
  a two-branch UNION — the same smell that argued for one `facilities` table in ADR-004.
- `equipment.subsystem_id` means equipment **joins the existing completion machinery for free**:
  punch, MC and material status are already keyed to subsystems. Biggest immediate win: a 6" 300#
  RF nozzle against a 6" 150# RF flange becomes a **hard INVALID at entry**, instead of being
  discovered at bolt-up with the crane hired.
- Equipment lands at **phase 3b**, not phase 1 — lines keep free-text endpoints until then and
  upgrade to nozzle FKs without migrating a single joint.

**ADR-009 (`docs/ADR-009-Manual-Modelling-and-Component-Templates.md`) — PROPOSED, new.**
Answers "how does manual entry work, and is the model PCF or Plant 3D?".

- **The user never types a port count.** Ports are a property of the component *class*, declared
  once in a **component template** shipped as reference data — verified that this is exactly how
  Plant 3D works: the catalog template holds properties "shared by all sizes", and
  `PLANTPARTCONVERT` is the command that *adds ports* to a custom block before it can be a
  piping component. Chain maps 1:1 — Plant 3D catalog → our `component_templates`; spec →
  `piping_classes`; placed part → `components`.
- **Manual flow is "pick a type, pick an open port".** Simulated end-to-end: adding a 6"×6"×2"
  TEE materialises 3 ports with roles RUN/RUN/BRANCH; attaching a PIPE to P3 inherits the 2"
  size and creates the joint implicitly. Port count, sizes, schedule, end type, wall thickness
  and the expected branch component are all **derived**. **The "open ends" list is the whole UI**
  — same query drives the worklist, progress and the import validator.
- **Structural refusals vs engineering warnings**: attaching to a taken port or typing a
  mismatched size is *impossible*; a spec-break schedule change or a branch component that
  differs from the class table is a *warning/deviation*. Same split as advisory inspection.
- **PCF vs Plant 3D is a false choice — they are different layers.** Conceptual model from
  **Plant 3D** (ports belong to a class; a connection is a relationship row). Import format
  **PCF, primary** — text, vendor-neutral, emitted by CADWorx/AVEVA/SmartPlant too, and it
  already carries welds with `CATEGORY FABRICATION|ERECTION`. Reading `.dcf` would tie us to
  Autodesk **and Windows** and still would not give welds. Commercially decisive for Iran, where
  CADWorx and AVEVA are common.
- **NPS is stored in four columns** at the user's instruction: `nps_label` "2 1/2",
  `nps_decimal` 2.5, `dn` 65, `od_mm` 73.0. The decimal column is **load-bearing, not
  redundant** — it is the only one that can be summed, and **dia-inch of accepted welds** is the
  stated purpose. Tested: summing the string column raises `ValueError` and sorting it puts
  `12` before `2 1/2`.
- **ADR-008's four questions all closed** (ADR-009 §4): branch rules stay 2-D; **equal and
  reducing tees are ONE `TEE` type** with the reduction *derived from port sizes* (a stored
  `REDUCING_TEE` could contradict its own ports); **no class inheritance** — `parent_class_id`
  dropped; **published classes lock**, edits create a revision and lines keep pointing at the
  revision they were issued against.
- The user corrected the size-band example (no size is in two bands). **Half-open ranges and the
  exclusion constraint stay** — they now encode that intent exactly, and guard it against
  transcription error rather than against the user.

**ADR-008 (`docs/ADR-008-PMS-Piping-Class-Rules.md`) — PROPOSED, new.** How a PMS piping class
expresses size-dependent rules («۱/۲ تا ۴ اینچ SCH80، ۴ تا ۱۰ اینچ SCH40») and the branch table.

- **Half-open DN ranges `[from, to)`, never inclusive text.** The user's own sentence has 4" in
  *both* bands — obvious to an engineer, fatal to a database. Keyed on **DN integers** because
  NPS is a label (`1 1/4`, `1.25`, `1-1/4`, `۱¼`), not a number.
- **One table `piping_class_rules` with a `rule_kind` discriminator** — schedule, end prep,
  valve type, flange type, gasket, bolt spec all share the shape. Same pattern as `MovementType`
  and `FacilityKind`.
- **`EXCLUDE USING gist (class WITH =, rule_kind WITH =, dn_range WITH &&)`** makes overlapping
  bands unrepresentable. **Verified on PG 17.11:** half-open bands accepted, DN100 resolves to
  exactly one rule (SCH 40), DN80 to SCH 80, and the *inclusive* spelling of the same sentence
  is **rejected** with `exclusion_violation` naming DN100. Needs `btree_gist` — **already
  installed in the dev DB** by the spike; the migration must `CREATE EXTENSION IF NOT EXISTS`.
- Overlap is impossible but **gaps are not**, so coverage is checked with PG 17 `range_agg`:
  one contiguous fragment = complete, more than one = holes. Becomes a **publication gate** on
  the class.

**The branch table spike changed the design — record this.** I first tried the same exclusion
constraint in two dimensions. It **rejected two perfectly legitimate PMS rules** (`[15,600)×
[15,600)→TEE` plus `[50,600)×[15,50)→WELDOLET`), because a specific rule's footprint sits inside
the general one. Every real branch table is written as *general rule + specific exceptions*, so
**overlap there is a feature, not corruption**: `UNIQUE` on the exact footprint only, and
resolution by **specificity — narrowest `header span + branch span` wins**. Verified: three
rules match the 6"×1" cell and SOCKOLET (tightest) is correctly chosen, while 6"×6" gives TEE.
`branch_dn ≤ header_dn` cannot be a row CHECK (it is a property of the query, not the row) so it
lives in the resolver with its own exception.

**Two-layer validation, deliberately separate:** ASME says what *exists* (INVALID if not),
the PMS says what is *specified* (DEVIATION if not). A 6" SCH 60 pipe is real and wrong — one
layer alone would either lie or miss it. Same philosophy as advisory inspection.

**A real trap caught while verifying that data.** Published B36.10M tables *disagree* on
large-bore walls because some print **STD** values under a column labelled **SCH 40**.
Verified: **STD diverges from SCH 40 at 12" and above** (12" is 10.31 vs 9.53) and **XS diverges
from SCH 80 at 10" and above** (fixed at 12.70). So STD/XS/XXS are **first-class schedules, not
aliases**, and the tests must assert the *divergence* — a fixture checking only 2" and 6" would
pass on a completely wrong large-bore table.

**Benchmarked before proposing** (2M rows, real RLS policy, role `epcc`): indexed fetch of one
line's ports **0.45 ms** — the policy's `EXISTS` is hashed once, not per row — but a full-scan
aggregate is **2,248 ms vs 204 ms without RLS, an 11× penalty**. So: normal screens are safe,
and **piping progress roll-ups must be incrementally maintained, never aggregated live.** That
is a *new* rule — `subsystemMaterialStatus()` (WarehouseService:441) computes live today, which
is fine at its current scale but must not be copied into the piping module.

**ADR-005 (`docs/ADR-005-Engineering-Data-Ingestion.md`) — PROPOSED, depends on ADR-004.** The
user wants the system usable *before* construction starts and *before* material reaches site,
fed by Excel and by Navisworks exports, carrying PMS, MTO, isometrics, line list and support
drawings. **The decisive finding: NWD/NWF/NWC cannot be parsed** — proprietary, Windows-only,
spec not public, and Navisworks itself exports only DWF/FBX/KML (geometry without properties;
Freedom cannot export at all). So the ingestion path is the **property export** the model owner
produces from Navisworks Manage, never the model file. Do not promise NWD upload.
**PCF is the best available source** — plain text, ISOGEN's own input format, emitted by Plant
3D / CADWorx / AVEVA / CAESAR II, giving every component with bore, item code, coordinates and
weight: an MTO nobody typed, parseable with no library and no licence.
Design: **one neutral `engineering_items` baseline** carrying `source_kind` + `confidence`, fed
by several importers; **versioned immutable `import_batches`** (never truncate-and-reload, or a
re-import silently deletes the baseline a PO was raised against — and keeping batches yields the
Rev B→Rev C diff for free); staged `upload → preview → commit` so a 40k-row file that fails at
row 39,999 leaves nothing behind; **`import_profiles`** so a new client's column headings are
configuration, not code; **PMS as an advisory validator**, matching the inspection choice already
made. Already present and reusable: `openspout` 4.32 + `league/csv` (installed), `jobs` /
`job_batches` / `failed_jobs` (migrated, `app/Jobs/` does not exist yet), and the CDE for
isometrics/support drawings. Five phases in §6 of the ADR; **Phase 1 is independently useful**.
Five open questions — the most useful thing the user can supply is **a real sample of each file
type**. A browser 3D viewer is explicitly **out of scope**.


> **Full itemised status — what is done and what is not — lives in
> `docs/TODO.md`.** It is the answer to "where are we?"; this section is only
> the short priority order.

Roughly in value order. None is started.

1. ~~**Filament v4 admin panel**~~ — **done**, see §5i. Five resources
   (Document, PunchItem, Transmittal, User, Subsystem). `companies` is
   deliberately uncovered (it is not an RLS-scoped tenant table).
2. ~~PDF export~~ — **done**, see §5f. Remaining: the payment certificate
   (صورت‌وضعیت) and the punch register still have no PDF.
3. **Procurement, HSE, Schedule** module groups.
4. **Installer + air-gap verification** (`verify-airgap`, AG-01…AG-06).
5. **Extend QA/QC**: RFSU certificates (A+B gate — `blocksReadyForStartUp()` is
   already written and tested but nothing calls it), **subsystem bulk import**
   (the reason the admin resource has no create form — see §5i-3),
   punch photos on `DocumentFileStore`.

~~Transmittals (FR-ENG-05)~~ — **done**, see §5d.
~~QA/QC + punch list (FR-COM-04/05)~~ — **done**, see §5e. `awp` is now the only
module still in `PLANNED_MODULES`.

---

## 9a. Git remote — and why the config keeps disappearing

> **State, 2026-09-18 (end of session).** Remote `main` is `61093dd`. Local is
> `5a758d5`, i.e. **3 unpushed commits**: `2fe8e3c` (System tier), `c0c733f`
> (phase 3b + numbering withdrawal), `5a758d5` (the DELETED state). No
> credential is stored, so `git push` needs a PAT. Note the local `origin/main`
> ref is stale (`edc2925`) because it cannot be fetched without one — trust
> `61093dd`, not `git log origin/main`. Working tree is clean.

> **State as of 2026-09-18 (end of phase 3): PUSHED AND IN SYNC.** Remote `main` and local
> `main` are both at **`c442de8`** (phase 3 — joints, compatibility, dia-inch), **376 files**.
> The eight-commit backlog that had built up since `edc2925` is gone: the range
> `edc2925..c442de8` covers phase 1, phase 1b, ADR-014 units, the units menu narrowing,
> phase 2 components/ports, the RTL/legibility fixes and phase 3.
>
> Verified after pushing, not assumed: `git ls-remote` returns the same SHA as `git rev-parse
> HEAD`; the pushed tree contains **no** `.env`, `.netrc`, credentials file or
> `source-backup.tar.gz` — only `app/.env.example`, whose secret-shaped keys are all empty.
>
> **The token was not persisted, by design.** It was passed inline to a single `git push` via a
> one-shot `https://x-access-token:TOKEN@…` URL and never written to `.git/config`,
> `.git-credentials` or the `origin` remote (confirmed by grep). `origin` is registered with the
> plain HTTPS URL, so it is usable for `fetch`/`ls-remote` but a future push will ask for a
> credential again — which is the intended posture. Expect to mint a fresh fine-grained PAT
> (**Contents: Read and write**, scoped to `epcc-platform`) next time; assume this one is spent.


**`github.com/iman-taheri59/epcc-platform`** (private). Current `main` =
**`c442de8`**, 376 files. `app/.env` and `source-backup.tar.gz` are gitignored
and have been confirmed absent from the remote tree.

**The recurring trap:** `.git/config` is on the snapshot exclusion list (along
with `.git-credentials` and `.netrc`), so **every sandbox reset wipes the remote
URL and the committed identity**. Symptoms, in the order you will hit them:

1. `git commit` fails with *"empty ident name"* — fix with
   `git config user.email … && git config user.name …` (repo-local is fine).
2. `git push origin main` fails with *"repository does not exist"* — the
   `origin` remote is simply gone, not misconfigured.

**Do not paste the token into the remote URL.** Push with a one-shot URL so it
never lands in a file that could be committed or archived:

```bash
git push "https://x-access-token:${TOKEN}@github.com/iman-taheri59/epcc-platform.git" HEAD:main
git remote add origin https://github.com/iman-taheri59/epcc-platform.git   # tokenless
```

Pipe the output through `sed "s|${TOKEN}|***|g"` — git echoes the full URL on
error, and that output otherwise ends up in the transcript.

**Verify the push against the API, not the push output.** This repo has twice
reported `size: 0` and a stale `pushed_at` for a non-empty tree. Compare
`git/ref/heads/main` to `git rev-parse HEAD`, then list
`git/trees/main?recursive=1` and check the files you expect.

**A fine-grained PAT is scoped to a repo LIST, and a missing repo looks like a
404, not a permission error.** A token that authenticates perfectly
(`/user` → 200, correct login) can still return **404 Not Found** for
`/repos/<owner>/<repo>` and `403 Write access to repository not granted` on
push — because the repository was simply never added to the token's
*Repository access* list. 404 rather than 403 is deliberate on GitHub's part:
it avoids confirming that a private repo exists.

Diagnose it in one call before assuming the token is broken or expired:

```bash
curl -s -H "Authorization: Bearer $PAT" \
  "https://api.github.com/user/repos?per_page=100&affiliation=owner" \
  | python3 -c "import json,sys; [print(r['full_name'], r['permissions']['push']) for r in json.load(sys.stdin)]"
```

This listed exactly one repo — `iman-taheri59/awp-epcc`, an **unrelated
project** (Laravel at the repo root, Docker, `phases-*` branches, last pushed
2026-09-07) whose history shares no commit with ours. Pushing there would have
needed `--force` or a nonsense merge and would have damaged someone else's
work. **If the reachable repo is not the one you expect, stop and ask** — the
fix is on GitHub (add the repo, Contents: Read and write), not in the shell.

**Push status:** `main` = **`04fe0ad`**, pushed 2026-09-15 and verified through
the API (branch sha, five spot-checked new paths, HANDOFF §5q present). Local
and remote are level; nothing is outstanding.

**Token hygiene:** tokens have been pasted into the chat twice. Treat every one
of them as compromised and rotate it once the push is confirmed — a PAT with
repo scope on a private commercial codebase is the whole product.

## 10. Session log

### 2026-09-24 — the PMS re-analysis: read the real documents, found four wrong shapes

No code changed. Asked what I knew about PMS in the real world, I read three
complete PMS documents instead of answering from memory, and the comparison
cost us the assumption that §5ah was nearly done. It is not: the engine is
right for its slice and the slice is small.

Four findings, all agreed with the user and written up in **ADR-008 §11** and
**§5ah-3** above: the class code is a label that must never be parsed; the P-T
table is derived from B16.5 via a *material group number* and never typed; the
body axis is component type x qualifier x size, which
`component-templates.ref.json` has held all along; and corrosion allowance has
two homes, one of which the user confirmed should go.

The pattern worth remembering is that **three assets were built and never
wired** — the material library, the component template qualifiers, and
`VALVE_TRIM`. Each was built for PMS, and PMS reached none of them. Before
building anything in a new module, check what already exists for it.

A second lesson, cheaper to learn here than later: I invoked the
no-auto-numbering constraint to argue that SP numbers must be typed. The user
asked whether that constraint was about MTO. It was not — it covered equipment
tags and line numbers only, MTO allocates nothing at all, and documents, ITPs,
transmittals and weld joints still auto-number happily. The real dividing line
is whether the identifier is born elsewhere or born here. **Check the scope of
a standing constraint before reasoning from it.**

Late in the session the user asked one more question — valve classes mostly
match B16.5, so what about Class 800, which is in neither B16.5 nor B16.34?
That one sentence cost a second amendment. Class 800 belongs to API 602 and is
**computed** (`1/3*C600 + 2/3*C900`), not tabulated; a rating is therefore
`(standard, class)` across six different enumerations, not a string; a valve's
class must be checked as *sufficient*, never as equal, and never by comparing
class numbers; and `requiresRating` is on the wrong axis, because a socket-weld
elbow is class-rated while a butt-weld one is not. The clincher: the evidence
had been in a PMS table I quoted on the first day — `A105 forged 3000 lb SW
B16.11` — and I had read `3000` as part of a description instead of as a
different rating scale. **A number inside a transcribed description is not
decoration; ask which standard issued it.** See ADR-008 §11.13.

Closing the day on the material side: asked whether API 5L was anywhere in the
system, and this time the answer was yes and in good shape — but **five of the
fifteen `matchingLinePipeGrade` cross-references pointed at grades that did not
exist**, unchecked by any rule. Wrote the rule first, watched it fail on shipped
data, then added the grades. The user's follow-up was sharper than the audit:
**API 6A has PSL 1–4 while API 5L has PSL 1–2** — the same acronym on two
incomparable scales, and we were one comparison away from a bug that would have
looked right. A PSL is a pair, exactly as a rating is. See ADR-008 §11.19.

And then the user pulled the alarm back down: API 6A sizes can be compatible
with ASME/ASTM ones. True — 6B weld necks have **restricted bores machined to
match real NPS pipe and schedules**, because ANSI and API share an ancestry and
common ODs. So the pipe stays NPS, only the connection is a bore, and a mapping
table replaces the second size universe I had just warned about. The BX gasket
was already in our library too. **Two of the three things I called structural
were not.** The lesson is narrow and useful: *when two standards look
incompatible, check whether they share an ancestor before declaring a second
architecture.* See ADR-008 §11.18.

The day ended with the biggest scope change yet: wellhead facilities, and API
6A. 6D turned out to fit the existing model (it borrows the B16.34 tables),
6A does not fit any of it — psi instead of a class, flat instead of derating,
a temperature letter instead of a number, a material class instead of a spec,
and a **bore diameter instead of an NPS**, which the per-size model has no key
for. The user's own suggestion resolved it: make **piping type** first-class,
and let each type bind a whole standards profile. That confines the complexity
to projects that have a wellhead, and it is the same shape as the founding
ISO-vs-AWP selectability requirement. The transition point between types is
**data, not a rule**, because it differs between projects. See ADR-008 §11.17.

Also worth carrying forward: *NACE compliance is not a material property* — it
is material-in-environment, so store the hardness limit and envelope and compute
compliance when assessing, never as a boolean on a grade.

Last exchange of the day, and the most useful: the user pointed at B16.11's
thickness-equivalence table. Reading it **corrected two things written an hour
earlier** — I had quoted the socket-welding column as though it were the whole
table (threaded Class 3000 is Sch 160, not Sch 80), and I had read a *rating
basis* as a fit-up rule when the standard's own note says thinner or thicker
wall is not restricted. Pipe OD is constant across schedules, so the socket
seats regardless; the source I had leaned on confused bore with wall. The check
is sufficiency, which makes **three** sufficiency checks now (bolting, Class 800
valves, B16.11 fittings) — an abstraction, not a coincidence. See ADR-008 §11.16.

Then the series question came back answered: **both** are used, which killed the
"project-level choice" reading an hour after writing it. Series moves onto the
flange row — where §11.4's payload already had room for it — and, checked rather
than assumed, **A and B share the same P-T ratings**, so the P-T pack gets
simpler while the bolting pack doubles. Four checks fell out, the sharpest being
that a Series A flange cannot bolt to a Series B one. See ADR-008 §11.15.

A last question closed the day: are B16.47 flange specs in the material
library? No, and correctly so — B16.47 is dimensional, and the library says in
its own header that a rating belongs to the component, not the material. The
materials are all there (A694 is the large-diameter flange spec). What is not
there is any B16.47 **dimension**: `flange-bolting` stops at NPS 24 while
`pipe-sizes` runs to 48, and `forJoint()` returns null above 24 without saying
so. Nothing consumes it yet, so nothing is broken — but a sufficiency check
wired onto a pack with no rows would report success for lack of data. **Missing
reference data must read as unknown, never as pass.** Series A and B are also
not interchangeable, so the pack needs a series axis and the project needs to
choose. See ADR-008 §11.14.

User decisions taken this session: one PMS per project, revised as a whole
document; AFC lines pin to the old revision and are flagged as impacted; NDT
lives in the PMS and feeds the ITP; SP items in scope now plus manual valves;
SP numbers unique project-wide and recorded on both MTO and datasheet; the PMS
is transcribed rather than authored, so write-time refusals become
record-plus-discrepancy.


- **2026-09-23 (cont³).** **User corrected ADR-008: no size bands** (§5ah-2) —
  each size a class carries gets its own row, because a PMS is read one
  diameter at a time. The range design had been a correct answer to a question
  the user does not have, and the correction *removed* the four-inch ambiguity
  rather than encoding around it: with no boundary there is nothing to put on
  the wrong side of one. The GiST exclusion constraint became a plain UNIQUE,
  all the `range_agg` gap machinery disappeared, and the schedule check got
  **more** precise — it now names "SCH 160 is not made at 3½″" instead of
  condemning the band that size sat in. Kept `setRules()` so a PMS line like
  "2 to 6 inch SCH 40" is typed once, but it writes six independent rows and a
  test proves changing one leaves the others alone. The branch table keeps its
  ranges. 39 tests rewritten to the new intent, not deleted.

- **2026-09-23 (cont²).** **ADR-008 phases 1–3 built** (§5ah), the piece most
  other work was waiting on. Re-ran the ADR's spikes first and all four rows
  reproduced, including the load-bearing one: the user's own sentence entered
  literally as two *inclusive* bands is refused by the database with an error
  naming DN100. Two tables, because overlap is **corruption** in a 1-D schedule
  band and a **feature** in a 2-D branch table — the latter proven by the
  earlier spike where forbidding it rejected two correct PMS rules. Added three
  write-time refusals the ADR had not foreseen, each of which would otherwise
  store a rule that looks present and resolves to nothing; **the best of them
  came from being wrong** — I guessed SCH 10S would be patchy, queried the pack,
  found it universal, and found instead that **SCH 160 is not made at 3½″**,
  which sits inside the user's own band. Phase 3 is the payoff: `auditLine()`
  turns model import from transcription into an audit, with four distinct
  outcomes so a deviation (designer's fault) is never confused with a PMS hole
  (specification's fault). Guarded it by reading `requiresRating` from the
  template pack — without that, every valve on every line would have reported a
  false deviation. 39 new tests.

- **2026-09-23 (cont).** Three user questions about valves — automated types
  (MOV/MSDV), control valves, then PSV/TSV — investigated and **recorded
  without building** (§5ag), at the user's instruction. One finding runs
  through all three: `MOV` is an *actuation* method and `SDV` an *assigned
  function*, so they are orthogonal axes and a valve can be both; a control
  valve's "trim" is a **geometry** setting the flow characteristic, not a
  material list, and it is *allowed to leak* under ANSI/FCI 70-2; a PSV has
  **two sizes and two ratings** (inlet 300# × outlet 150#, sized by API 526
  orifice letter), which the one-size/one-rating model cannot hold, and TSV is
  a *scenario* on a PSV rather than a device. `MSDV` exists in no standard —
  the user identified it as **Main** Shut Down Valve, still to be confirmed
  against a project P&ID. Noted with some relief that the actuator pack already
  refuses "MOV as ESDV", because `ELECTRIC` is `supportsFailSafe: false` and
  rule 4 enforces it. All three want the same missing thing: a **datasheet**,
  not a material-cascade row.

- **2026-09-19 (cont³).** Per the user, an ITP row must carry its standards,
  the related **project** references/specs, and the inspection **report form
  number** — not just an activity and a party grid. Added
  `itp_stage_documents` (SPEC / DRAWING / PROCEDURE / FORM, optional pinned
  revision, locator) plus `itp_stages.form_number`. Project references are
  foreign keys into the document register so a reissued spec cannot leave the
  ITP silently citing the old revision; external codes stay plain text in
  `reference` because we do not own them. Seeded a real piping supply spec and
  two report forms, and the grid screen gained three columns. 9 new tests → 52.
  Found and fixed: `ItpTemplateSeeder` was never registered in
  `DatabaseSeeder`, so a rebuilt database had no templates at all.

- **2026-09-19 (cont²).** Three UML diagrams for ITP: `08-itp-domain`,
  `09-itp-state-machines`, `10-itp-hold-point-gate`, drawn from the migration
  and the service rather than from memory, and inlined into `docs/uml/uml.html`
  (now 10 diagrams, still zero external references). Doing so exposed a
  pre-existing bug: the `<section id="d7">` opening tag had never been written,
  so diagram 7 was nested inside section 6 and its nav link went nowhere —
  fixed. Verified with an `HTMLParser` tag-balance check after `chromium`
  disappeared from the image.

- **2026-09-19 (cont).** ITP built (ADR-015): H/W/M/R intervention grid,
  per-discipline plans with **PIPING and PIPELINE separate**, a copied standard
  template library, per-lot inspection records with notice and signature, and
  the gate. Per the user's ruling **a hold point blocks RELEASE, never the goods
  receipt** — enforced in `WarehouseService::issue()`. `WAIVED` is refused for a
  hold point in both the service and a CHECK. Added the missing TPI company to
  the fixture (a test had been silently skipping). 43 new tests, 10/10 mutants
  killed. Screens `docs/screens/itp-{grid,lot}-{en,fa}.png`; rendering them
  caught an RTL bug where a latin notice reference reordered its hyphen.

- **2026-09-19.** MTO↔model reconciliation built (ADR-005 §2.1/§2.2 → the
  neutral baseline is now real): `SpreadsheetReader` (no PhpSpreadsheet —
  `ext-gd` is absent), three tables, `BaselineService`, bilingual screen,
  shortage report. Per the user's ruling **no source wins automatically**: both
  claims are stored, the disagreement is a finding, and the baseline has no
  agreed number until a person decides and gives a reason. 38 new tests,
  11/11 mutants killed. Suite **1009 passed / 3435 assertions**. Screens
  `docs/screens/baseline-{en,fa}.png` — rendering them caught two bugs the
  tests missed (an item with no line is invisible for ever; `-14` renders as
  `14-` under RTL). Two more traps
  recorded in §8 (a non-`$fillable` column is silently ignored by `update()`;
  `fresh()` outside a tenant binding looks like a data bug under RLS).

- **2026-09-18 (cont. 2).** PCF importer built (ADR-005 phase 2 → PARTLY BUILT): parser, vocabulary, diff-based re-import, quarantine, coordinate-based joints, bilingual screen. 50 new tests, 10/10 mutants killed. Suite **969 passed / 3340 assertions**. Route-ordering and store-time-translation traps recorded in §8.

- **2026-09-18 (cont).** DELETED state built for equipment, nozzles and piping lines: tag stays reserved, connected things refuse deletion, reason mandatory, terminal, not SoftDeletes. `piping_lines_afc_has_provenance` widened. 21 new tests, 8/8 mutants killed. Suite **969 passed / 3340 assertions**.




### 2026-09-17 (later still) — ADR-006 phase 2: components and ports

Built the modelling layer: `components` + a single discriminated `ports` table, `ComponentService`,
a per-line model screen, and the attach gesture. The user's "how does it know a tee has three
ports" question is answered structurally — the template declares them and no API accepts a count.

**Sandbox reset #33 mid-turn.** Wrote `rebuild-env.sh` so the next one is a single command instead
of ten minutes of remembering; it also verifies chromium/poppler and warns that their absence makes
13 tests skip silently rather than fail.

Full suite **852 passed / 3136 assertions**, zero skipped. 13 new mutants on the modelling service,
all caught.

### 2026-09-17 (later) — units menu narrowed to bar/psi and C/F

User: «فقط واحدهای سلسیوس و فارنهایت را برای دما و bar و psi برای فشار را در نظر بگیر».

Retired the other units with a `selectable: false` flag rather than deleting them, so stored and
imported values in kPa/kg/cm²/barg/K keep converting. That exposed a distinction the original
design had glossed over: the canonical storage unit (kPa) is no longer a legal *display* unit, so
the fallback for an unconfigured project had to become a new `default_display` (bar).

**Sandbox reset #32 at the start of this turn** — rebuilt PHP 8.4, Composer, vendor, PostgreSQL,
the database, and (newly) **chromium + poppler-utils + fonts-vazirmatn**, which brought 13
previously self-skipping PDF tests back to life. Full suite now **767 passed / 2998 assertions,
zero skipped** (was 758 passed with 13 silently skipped).

5 new mutants on the narrowing logic, all caught; 4 new pack-corruption probes, all caught.

### 2026-09-17 — units of measure (ADR-014) wired end to end

Built on the previous session's engine: `UnitPreferenceService` (user → project → canonical),
canonical mirror columns populated on every write via `PipingLineService::canonicalValues()`, a new
`updateDesign()` path (there was none — design conditions could only be set at creation), the pack
schema + `check_units()` CI gate, FA/EN keys, and the register UI (two new columns, value+unit
inputs, a units panel with separate "just for me" and "project default" buttons).

Verified end to end over HTTP against a real login: created a line at 285 psi / 650 °F, switched
the reader's units to barg, confirmed the display changed to `19.65 barg (285 psi)` and the stored
row did not change at all.

Full suite **758 passed / 2980 assertions** (was 667 / 2751). Units mutation total **38 run, 0
survivors**; M28 and M38 survived first pass and both exposed real gaps. Pack validator proved by
breaking `units.ref.json` six ways — all six caught.

ADR-014 written (it had been referenced in docblocks but did not exist).

Append one row per session. Keep it terse.

| Date | Change | Result |
|---|---|---|
| 2026-09-15 | **Phase 1b (§5s): the numbering editor and evidence attachment — the two things that made phase 1 *correct but not operable*.** The number format could only be changed from tinker, which is a hardcoded rule with extra steps; evidence could only be attached programmatically, so the gate was demonstrable but not usable. Both are now screens. Probed the engine before designing the editor's guard rails rather than reasoning about them: a **counter-less pattern composes to the identical string twice**, so the second create dies on the uniqueness check with "number already taken" — symptom named, cause hidden, project silently capped at one line per subsystem. Four rules now refuse at the point of editing, including that counter width may grow but never shrink below what was issued (padding is render-time, so narrowing corrupts nothing until the project crosses the threshold, which is exactly what makes it dangerous). Well-formedness is delegated to `NumberPattern`'s constructor so the two cannot drift — which exposed that **`AmbiguousPatternException` is not a subclass of `InvalidPatternException`**; both extend `RuntimeException` and are `final`, so catching one let the two-counter case escape as a 500. Driving the register with curl then found the defect that mattered: **attaching evidence is not exclusive and the gate checks every link**, so one B-coded drawing attached by mistake locked a line out of AFC permanently with no way back but a database edit. Added `detachEvidence()` — draft lines only, because removing the justification for a decision already taken is not a correction. **Mutation M21 survived the first pass**: nothing stopped a hand-edited URL from pairing line A with line B's evidence link, and since both rows live in the same project **RLS is perfectly happy** — the ownership check was the only guard, and it was untested. Also: `TableToolsTest` correctly caught the config screen's token grid, which legitimately must not be sortable because **the row order *is* the data**; declared `data-tt-exempt` in the view with its reasoning rather than weakening the guard, then confirmed the guard still bites | **667 / 2751**, 21 new tests, **mutants 14–22 caught, 0 survivors** |
| 2026-09-15 | **ADR-006 phase 1 built (§5r): piping lines, per-project numbering, and the AFC gate.** The first piece of the engineering spine — every component, joint, weld and NDT record eventually resolves to a line. The number format is **data, not code**: a jsonb token list per project, composed and parsed by an engine with the vocabularies injected. Confirmed against real fixture data that the obvious implementation is wrong — **`explode('-', …)` yields 9 fragments for 6 tokens**, because the subsystem code contains three separators. Two defects existed before a line of test ran: `confirmAfc()` called **`CdeState::isApproved()`, which did not exist**, on **`$revision->state`, a column that does not exist**. Fixing it forced the real question — what *is* approval? Answered from the ISO 19650-2 table already in the code rather than invented: `PUBLISHED` **and** an A-family suitability, because **B1–B5 mean "approved with comments"** and waving them through is exactly the rework the gate exists to prevent. Mutation testing then earned its keep twice: **M1** showed my longest-first-sorting test proved nothing (PCRE backtracking rescues `A1|A1B`, so the real hazard is a *following* token absorbing the difference — `PH-14` vs `PH-14-SS`, wrong subsystem, silently), and **M8** showed the `PUBLISHED` half of the gate could be deleted with the suite still green, because the SHARED+S2 fixture fails the A-check anyway; the test that separates them is **SHARED + A1**. Built the register UI and drove it with curl, which surfaced two more: a legitimate refusal escaped as a **500**, and the form offered free text where a project register governs. Imported numbers keep **`sequence` NULL** so a foreign `…-777` cannot push our counter to 778 | **647 / 2716**, 61 new tests, **13 mutants killed, 0 survivors** |
| 2026-09-15 | **Project archive built (§5q) — the answer to ADR-013's open question, which the user settled as «آرشیو».** `ACTIVE ↔ ARCHIVED`, reversible, with **no `DELETED` state**: deletion stays a separate ceremony rather than one dropdown position away. Archived projects are read-only, hidden from the portfolio behind a clickable count, and **excluded from `plans.max_projects`** — the commercial crux, because a customer on a one-project plan who cannot start job two without deleting job one *will* delete job one, and the licence terms would be instructing them to destroy contractual evidence. Two layers, both necessary and failing differently: the service refuses loudly and translated, RLS is the backstop. Spiked the mechanism before writing it and found the asymmetry that shaped the design — **a blocked UPDATE/DELETE raises nothing**, `rowCount=0`, `save()` returns true, so RLS alone would tell a user "saved" while the edit vanished. Then found a real hole by trying it rather than trusting the design: **`WITH CHECK` is never evaluated for DELETE**, so the freeze policy reported `DELETE 9` and emptied an archived project's punch list — fixed with a second `FOR DELETE USING (...)` policy per table (22 × 2). Also uncovered incidentally: **`SetUserLocale` was registered on the Filament panel only**, so every `__()` outside the admin panel fell back to the default locale; invisible while only views used it (they have their own `?lang=` closure) and exposed the instant a service threw a translated exception | **586 / 2564**, 30 new tests |
| 2026-09-15 | **Phase 0 built: the engineering reference packs (§5m, ADR-007 → ACCEPTED AND BUILT).** First code from ADRs 006-010, and deliberately first: the line-number parser, the compatibility engine and the PCF importer all need the size vocabulary before they can validate anything. Four versioned JSON packs (36 sizes, 355 schedule pairs, 13 PCF end types, 20 component templates) behind one memoised singleton - **not** database tables, because the protection needed is *nobody can write, including the owner company*, which a file gives absolutely and a permissive RLS policy only pretends to. The ADR predicted from published sources that **STD is not an alias of SCH 40 above 10″ and XS is not SCH 80 above 8″**; the shipped data now asserts it in three layers. Two defects: PHP **coerces numeric string array keys to integers**, so `schedulesFor()` returned a mixed `int|string` list (caught by the suite on first run); and the reachable **alias-fallback bug runs the opposite way to the assertion** - ASME defines STD at 22″ and 26″ but no SCH 40 there at all, so a "helpful" fallback returns 9.53 mm for a schedule that does not exist. That one **survived the first suite** and was found only by mutation M1. M14 also showed the singleton test proved nothing: instance identity is not memoisation, so it now warms the cache, deletes the files, and asserts a *fresh* instance still throws | **556 / 2491**, **153 mutations caught** (+15 pack-data, +15 PHP) |
| 2026-09-13 | **Physical breakdown built (§5l, ADR-004 → ACCEPTED).** `project → facility → (optional) area → subsystem`, after establishing that Plant 3D has **no** System/Subsystem, MC, punch or handover — so compatibility is an **import mapping**, not a copied schema. One `facilities` table with a `FacilityKind` discriminator instead of sibling plant/pipeline tables, because what they share is being a commissioning parent and what differs is addressing (per-kind `jsonb`). Numbering moved to **per facility**, which made the bare code ambiguous — so `fullCode()` composes at read time (never stored: it would drift on rename) and the register shows Facility as a non-toggleable column. Integrity rests on a **composite** FK `(area_id, facility_id) → areas(id, facility_id)`, making cross-facility corruption unrepresentable. The fixture is deliberately uneven — one facility with areas *and* a direct subsystem, one with no areas at all — because a uniform fixture cannot fail informatively. Two traps recorded: the backfill silently matched **zero rows** under `FORCE ROW LEVEL SECURITY` (RLS filters, it does not error), and **M118 first reported SURVIVED because the mutation was wrong, not the test** — dropping a constraint in `psql` proves nothing when `setUp()` runs `migrate:fresh`. Mutate the migration source. Pushed as `cf99f0f` (§9a — a reset had wiped `.git/config`, so the remote and the git identity had to be recreated first) | **507 / 1716**, **123 mutations caught** |
| 2026-09-13 | **UML for the procurement module (`docs/uml/`).** Installed `graphviz` + `plantuml` (absent from the base image; plain `apt-get` fails the dpkg lock, `sudo` works) and drew five diagrams — domain model, the three state machines, an inspection-to-MC-gate sequence, reversal + RLS, and the component view. Written against a live `information_schema` dump rather than from memory, so the attribute lists are the real columns. Packaged as `docs/uml/uml.html` with the SVGs **inlined** — checked for external refs and found none, matching the `tour.html` air-gap pattern — and verified by screenshot rather than by byte count | no code change, **482 / 1647** |
| 2026-09-09 | Docs, standards packs, prototype, ADRs, Laravel scaffold, standards engine, conformance corpus | Foundations in place |
| 2026-09-09 | PostgreSQL + RLS spike, multi-project, Octane and PgBouncer spikes | All CLOSED, 12 standing rules recorded |
| 2026-09-09 | Auth + six-gate authorization, `BindTenant`, project setup & team screens | Vertical slice works |
| 2026-09-10 | Preview login debugging, rounds 1–5 (cookie attributes) | **All failed** — wrong layer |
| 2026-09-10 | Cookie-less preview: `PreviewSessionFallback` + `PreviewUrlRewriter`; fixed mixed-content `redirect()->back()` | **Login works in the preview** |
| 2026-09-10 | Entitlement-driven sidebar, view composer, `HANDOFF.md`; fixed `?as=` reflection regression | 147 tests green |
| 2026-09-10 | **Sandbox reset #6** — PHP, PostgreSQL and `vendor` wiped, source intact. `restore.sh` rebuilt it in ~43 s | 147 tests green, preview back up |
| 2026-09-10 | Collapsible sidebar: icon rail (desktop) + off-canvas drawer (mobile), ported from the prototype; pre-paint state restore | 150 PHP tests + 17 jsdom assertions green |
| 2026-09-10 | **Sandbox reset #7.** Toggle was dead — script ran before its button existed; the jsdom test had masked it by re-running the script post-parse. Fixed both. | 151 PHP + 17 jsdom green |
| 2026-09-10 | Desktop rail snapped with no animation: no width transition outside the mobile query, and `display:none` labels are unanimatable. Added `sidebar-animation.test.cjs`. | 152 PHP + 29 jsdom green |
| 2026-09-10 | Language switch invisible — the animation change gave an inline `<a>` `max-width`, which inline boxes ignore. Fixed with `inline-block`; guard added. | 152 PHP + 36 jsdom green |
| 2026-09-10 | **Document register / CDE shipped** (ISO 19650 states, numbering, revisions, RLS). Caught a cross-project RLS leak and a lazy-load-outside-scope trap in the process. | 173 PHP + 36 jsdom green |
| 2026-09-10 | **File attachments shipped** (content-addressed, allow-listed, streamed through authz). Then live verification exposed that participants saw an EMPTY register — the CDE was not common. Migration 130000 makes the CDE state the sharing boundary. | 196 PHP + 36 jsdom green |
| 2026-09-10 | **Filter + sort on every data table** (FR-UX-04): reusable partial, Persian-digit folding, lifecycle-ordered CDE column, sweep test over all views. | 206 PHP + 80 jsdom green |
| 2026-09-10 | **Review & comment cycle shipped** (FR-ENG-06, journey 3): categories, dispositions, closure stats, and a publication gate wired into the state machine. Two real bugs caught by tests (unreflected column default; invalid `FOR UPDATE` + aggregate). | 225 PHP + 125 jsdom green |
| 2026-09-10 | **Transmittals (FR-ENG-05)** + ZIP packager. Found and fixed a **draft-visibility leak** (addressees and the employer could read unsent letters) and the **child-policy RLS recursion** it exposed; corrected a test that encoded the wrong model. Harness fixed for page-shape and collation assumptions | **248 / 836**, 128 jsdom, 36 mutations caught |
| 2026-09-10 | **Sandbox reset #8** — `.cache` (and with it `vendor`) wiped; the workspace also rolled back past the transmittal work. Recovered in full from `source-backup.tar.gz`, which is why it is refreshed at the end of every session | 249 / 838 green after restore |
| 2026-09-10 | Blob-store purge in `tests/bootstrap.php` + `BlobIntegrityTest`; workspace 13,263 → 318 files | File cap no longer at risk |
| 2026-09-10 | **Sandbox reset #9** — PHP, PostgreSQL and `vendor` wiped, source intact (incl. transmittals). `bash restore.sh` rebuilt in ~38 s | 249 / 838 green after restore |
| 2026-09-10 | **QA/QC + punch list + MC certificates (FR-COM-04/05, journey 5).** Added the `MC-KARAN` tenant the fixture lacked; gave `consultant` the `punch.*` it was missing. Caught an **inert test** in mutation testing — "contractor cannot verify its own fix" passed with the guard deleted, because the tenant-type rule threw instead; fixed by asserting on the message and using a verifier-type company. Fixed 3 harness over-assumptions (`data-sort="none"` counted as declared, single-value facets, digits inside identifiers) | **284 / 939**, 345 jsdom (7 fixtures), **46 mutations caught** |
| 2026-09-10 | **Sandbox reset #10** — PHP/PostgreSQL/vendor wiped again; `bash restore.sh` rebuilt in ~45 s and **auto-reinstalled jsdom** (the fix added last session paid off immediately) | 284 / 939 green after restore |
| 2026-09-10 | **PDF export via headless Chromium (§5f) — retires the PDF half of risk IR-R2.** Rasterised and visually verified Persian output rather than trusting a byte count; found and fixed a per-page footer collision and a category column that wrapped to four lines. Mutation testing found the `%PDF-` magic-byte guard was **completely untested** (a fake browser writing garbage would have been served as a certificate) and that `isAvailable()` is only a fast path, not the guard that keeps the ZIP alive | **301 / 1002**, 257 jsdom (5 pages × table tools + 36 sidebar), **54 mutations caught** |
| 2026-09-11 | **Sandbox reset #11** — same wipe; `restore.sh` rebuilt in 58 s and **auto-installed Chromium + Vazirmatn + poppler** for the first time, so PDF export survived the reset untouched | verified live: cert PDF 200, Vazirmatn embedded |
| 2026-09-11 | **Shell layout bug reported by the user** — punch, transmittals and document-review put the sidebar outside `.app` and the topbar as a direct child of it, so content slid under the sidebar and the collapse button went missing. Root cause was markup order, not CSS. Also gave `/gate` the shell (it is linked from the sidebar but had none, stranding the user) while keeping it usable logged-out, moved `$user`/`$acting` into the view composer as defaults, and fixed a literal `ips.iran{{ $packVersion }}` caused by Blade's `@{{ }}` escape | **319 / 1110**, **57 mutations caught** |
| 2026-09-11 | **Register column shift + category letters (both user-reported).** The QA/QC table showed "Subsystem" above the category data: `.mono` sets `display:inline-block` for LTR runs and was on `<td>`, which replaces `display:table-cell` and drops the cell out of the column layout. Cell counts matched, so only per-cell geometry in real Chromium found it; the same latent bug was on the document-review table. Fixed with `td.mono{display:table-cell}`. Also switched punch categories from الف/ب/ج to **A/B/C in both languages**, including the gate message and the MC certificate PDF | **325 / 1149**, **60 mutations caught** |
| 2026-09-11 | **Sandbox reset #12** — mid-task; `restore.sh` rebuilt in 58 s with Chromium/Vazirmatn intact | — |
| 2026-09-13 | **Procurement & materials (§5k).** Built the chain the user named: تهیه ← بازرسی ← ورود به انبار ← خروج از انبار ← ورود به سایت. 7 tables, 7 models, 2 services, 2 screens, 51 tests. Key decisions: one append-only movement ledger rather than four document tables; the sign convention in code not data (`SITE => 0`, or site delivery double-deducts); ISSUE and SITE kept separate so material on a laydown area stays visible; lots keyed to heat number with the MTC as a CDE document; inspection advisory per the user's instruction, paid for with a permanent "needs attention" panel; two distinct SoD rules on the requisition. RLS is commercial here — a vendor sees its own PO and not a competitor's, and never sees a requisition | **482 / 1647**, **117 mutations caught** |
| 2026-09-13 | **AG-06 shipped, and a ninth defect (§5j).** Wrote `verify:airgap`, the pre-flight the customer runs at acceptance, with a BLOCKER/LATENT/BENIGN split so the output stays actionable. Writing it found two leaks the suite could not see: Pusher's CDN, hidden from the first scanner because `echo.js` concatenates the URL at call time, and **`ui-avatars.com`**, which Filament builds in PHP from the user's name and rendered into every admin page - an un-fetchable image on site AND an employee's real name handed to a third party. The avatar one exists in no file on disk and was found with a browser net-log; fixed with `LocalAvatarProvider` (inline SVG initials). Dropped `FilamentInfoWidget` too. The test now asserts on rendered HTML, not just files, because that is the only place a runtime-assembled URL is visible | **427 / 1523**, **113 mutations caught** |
| 2026-09-13 | **The mobile blank page (§5i-3).** Building a screenshot tour for a phone, the first shot came back as a single flat colour: `/documents?lang=fa` at 430px painted **nothing**, while the same page in English was fine. Cause was `min-width:auto` on a flex item — a 1032px table widened `.wrap` past a 485px viewport, and in RTL that pushes the whole document off-screen rather than clipping it. Fixed once in the shell with `min-width:0` + `overflow-x:auto`; both halves needed, since containment alone hides the state and revision columns. Bisected by measuring unique colours per render, which is what separated "page is broken" from "screenshot tool is broken" and then LTR from RTL. Also shipped `tour/tour.html`: a self-contained, data-URI gallery so the work is reviewable on a phone where the live preview cannot open | **416 / 1433**, **111 mutations caught** |
| 2026-09-12 | **Certificate evidence integrity, and clearing two pieces of flagged debt.** Started on the `User::find()` in `PunchController` flagged as unconverted since the identity-table work — and it turned out to be **correct**: the `users` counterparty policy already covers reading an issuer from another company, verified from all four participant sides. But the fixture had **never issued a certificate**, so the whole signatory path was unrendered by any test; the blade guards it with `@if($issuer)`, meaning a failure drops the "issued by" line from signature evidence rather than erroring (**M105**). Then, having finally rendered an issued certificate, downloading it twice showed the real defect: the punch summary was **recomputed live on every render**, so an issued certificate's own numbers drifted as the site worked on — closed 2→3, open A 2→1, always in the flattering direction. Fixed with a `punch_summary` jsonb snapshot written at issue, mirroring `exception_items`; drafts deliberately stay live (M107–M109). Also replaced all **ten** `Subsystem::first()` call sites with an explicit code lookup — proven load-bearing: pointing the helper at a different subsystem fails 8 tests | **414 / 1423**, **109 mutations caught** |
| 2026-09-12 | **Subsystem admin resource, a fixture that could finally tell rules apart, and a panel-wide digit defect (§5i-3).** Built the read-only Subsystem register whose point is the **derived** MC gate — no state column exists, so the table asks `PunchCategory`/`PunchState`/`McCertificate` for the rules and computes counts with `withCount` rather than the model's collection-walking helpers (quadratic on a register); a probe confirmed SQL and model agree row by row. `closurePercent()` returns 100 for an empty list, so an un-walked subsystem says **"not walked down"** instead of claiming completion. **Two mutations survived** and neither was an assertion problem: the fixture had no A-item in VERIFIED and no B-only subsystem, so "VERIFIED still blocks" and "only A blocks" were unprovable. Added SS-5501 and SS-6601, then M96 and M99 died — M96 having survived a test that checked `PunchState::isOpen()` and the model helper while the **table** disagreed with both. Growing the fixture broke three PunchList tests hardcoding `PL-0006`/`count=5`; de-hardcoded rather than renumbered. Screenshot then caught a defect shipping since the first resource: Persian pages mixed **Persian digits in our columns with Latin in Filament's pagination**, on all five resources. Fixed once at the translator (`LocalisedNumberTranslator`, bound in `register()` not `boot()`), converting only wholly-numeric parameters so tags stay Latin — a guard **unobservable through the UI**, so pinned against the translator directly rather than by an inert HTTP test | **410 / 1401**, **104 mutations caught** |
| 2026-09-12 | **Users admin resource, and the identity-table leak it exposed (§5i-2).** Before writing the resource, checked whether `users` was even under RLS: it was not. Bound as EPC-PARS the database returned **all nine users of all eight companies** — the client's reps, the PMC's inspectors and two rival EPCs' engineers — while documents correctly narrowed to 5. Added RLS on `users` + `project_memberships` with a counterparty clause, hitting **policy recursion** (the two policies read each other; re-expressed through `project_company`) and re-learning **rule 3** (`NULLIF` on every GUC read). Login needs a read before a tenant exists, so added the greppable `forIdentityLookup()` hatch — which then had to **snapshot-restore rather than force-clear**, because a nested call was shutting the hatch its caller still needed and silently leaving the disabled fixture account enabled. Then the screenshot: **every `/admin` route redirected to login on a valid session**, because Filament resolves the user before `BindTenant` and `actingAs()` had hidden that from all 21 panel tests. Fixed at the user provider. **M95 proved the first test for it was inert too** — `SESSION_DRIVER=array` means `retrieveById()` never runs in tests | **395 / 1348**, **95 mutations caught** |
| 2026-09-12 | **Transmittal admin resource + the Jalali defect (§5i).** Read the domain classes before writing a line (`TransmittalPurpose::label()` returns a string where `PunchState::label()` returns an fa/en array) — the habit that stopped a repeat of the invented-constant bug. Built a read-only Transmittal resource showing **both streams in one list** with `kind` as a filter, the inverse of the hand-written UI's deliberate split. Then the screenshot showed `سپتامبر 12, 2026` on a fully Persian page: Filament's `->dateTime()` renders **Gregorian**, and on a submission log the date is contractual. Root cause was structural — Jalali formatting existed only as a private `$jdate` closure copy-pasted into each Blade view, so a surface written later could reuse none of it. Extracted **`App\Support\JalaliDate`** and routed all three panel tables and all ten views through it; no `Morilog` call remains in `resources/views`. `DateFormattingTest` guards the **shape of the codebase**, not just output (M87–M89) | **378 / 1305**, **89 mutations caught** |
| 2026-09-12 | **Admin resource scope + punch resource (§5i).** The generated Document form exposed `number`, `sequence`, `company_id` and `current_revision_id` as free inputs — retyping a number collides with the unique index, changing the company makes a document vanish from its author's register. Form reduced to the bilingual titles; create/delete withdrawn. Added a **read-only** PunchItem resource: `state` is a workflow position with rules about who may move it, and `McCertificateService` reads that column, so an editable form would let an admin forge a VERIFIED and unblock a completion certificate. **A grep is not an assertion** — the first no-delete test failed against its own explanatory comment; rewritten against `canDelete()` / `getPages()` | **369 / 1275**, **84 mutations caught** |
| 2026-09-12 | **Filament v4 admin panel (§5i).** Wrote the tenancy test FIRST, and it earned its keep: with the stock middleware stack the panel showed the signed-in contractor **nothing at all** — RLS failing closed — so the "no leak" test passed on a broken screen. Only the mirror test ("still shows its own rows") could tell the two apart. Three more real defects: `->font()` pulls from Google Fonts (**air-gap violation**, now pinned by a CDN-host test), `canAccessPanel` was untested so a **deactivated account could open the admin panel** (M79), and the state column was bound to `currentRevision.state` when the attribute is `cde_state` — an **empty cell on every row** that returned 200 and was found by looking at a screenshot, not by a test (M81). Added `SetUserLocale` + the project's first `lang/` files | **363 / 1256**, **81 mutations caught** |
| 2026-09-11 | **Corrected the meaning of `MC` (§5h).** The user asked "do you know what MC means?" — it is **مدیریت پیمان / Management Contractor**, the employer's contract administrator, not the "Mechanical Completion body" the seeder, two views, the SRS role table and the coverage map all claimed. **No test could have caught it**: a tenant type only has to be a matching string, and the role rules were already right, so the behaviour passed while the screen lied. Added `TenantType` (nine types, one place — they were loose strings in five files), a CHECK constraint on `companies.tenant_type`, and `TenantTypeTest` which pins the words themselves. Renamed the fixture company to مدیریت پیمان کاران and the role to `mc_supervisor` | **356 / 1240**, **77 mutations caught** |
| 2026-09-11 | **Vendor transmittals as a separate stream (§5g).** Asked the four structural questions first (who records, what "separate" means, roll-up traceability, document typing) rather than guessing. Separate `VTR` series, own screen, `source_item_id` chain, two CHECK constraints, `VEN-AZAR` added to the fixture with two seeded letters. **Mutation testing found the HTTP create path was untested** — a controller ignoring the submitted `kind` filed vendor letters into the contractual series and the whole suite still passed. Narrowing the employer RLS clause surfaced a genuine leak: the owner could read supply-chain letters | **349 / 1202**, **74 mutations caught** |



## 2026-09-28 — B16.9 continuation: validator hardening

The user confirmed B16.9 fitting-dimension data as the latest addition. This
supersedes the chronology implied by the older header and suggested-next list.
The delivered v1.0.0 pack contains 65 dimensions (44 LR elbow, 21 cap); no
application reader or dedicated consumer was identified during reconnaissance.

Added `standards-packs/tests/test_fitting_dimensions.py`: 13 in-memory regression
tests, 10 failing against the previous validator and all 13 passing after repair.
The aggregate runner now includes these tests. Full pack validation passes for
all 3 project packs and 16 reference packs. PHP/Laravel tests remain unexecuted:
PHP, Composer, PostgreSQL and vendor are absent in this sandbox.

Fixed the unbounded floor allowance: NPS 8 with a 9999 mm take-out previously
passed. Four small-size exception values are now explicitly pinned to the
supplied transcription, NOT independently reverified from ASME. Other sizes
retain the existing symmetric plausibility tolerance. Added duplicate-key,
DN/NPS, row-kind/symbol/dimension/table, source-table manifest and missing pipe
reference checks. Removed the stale Table 6.1-4 mention from the source prose;
no dimensional values changed. Short-radius rows remain unsupported.

Important limits: this is data-integrity hardening, not standards certification.
Caps have no new independent dimensional oracle. Source standards/parser tools
are absent from the transfer. Changes to engineering numbers or exceptions
require source evidence. No database changes or runtime reset occurred.

Next candidate: a fitting-reference reader with explicit unavailable responses
and provenance, then an agreed application consumer. Two-size components remain
a separate scope decision. The existing baseline RLS defect remains open.


## 2026-09-28 — security, data-preservation and regression maintenance

See `docs/AUDIT-2026-09-28.md` for the authoritative change list, evidence and
limitations. Delivered: forward baseline/model RLS repair with command-specific
policies and archive protection; explicit denial of non-owner engineering
writes; Gate-1 check on every tenant-bound request; production/private login
without publishing the user directory; per-process test storage and destructive
fixture guards; non-destructive default restore with explicit local-only reset;
strict prerequisite/skip handling and portable fresh-HTML browser harnesses.

Verified four isolated PostgreSQL shards: 1587 PHP tests. Later focused runs
cover the final additions and changes; deduplicated latest results match all
1596 tests in current PHPUnit inventory, 48455 assertions, zero errors, failures
or skips. Additional checks: 11 operational safety tests, 13 fitting validator
tests, 25 engine conformance cases, 19 packs, 324 prototype renders and 182 JS
assertions (prototype + FA/EN application document screen). Composer/npm audits
reported no dependency advisories at execution time. No dimensional engineering
values or commercial rules changed. No application database was reset/migrated.

Do not count the initial timed-out full run or overlapping focused test counts
as extra passes. These results are not a complete security/engineering
certification or a declaration of production readiness. Missing source standards,
ADR-012 performance, complete authorization/ITP policy review, live deployment
and all-screen real-browser visual acceptance remain tracked work.
