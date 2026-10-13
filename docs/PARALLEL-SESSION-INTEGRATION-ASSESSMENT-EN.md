# Parallel-Session Integration Assessment (2026-10-12)

Companion to `docs/IMPROVEMENT-PROPOSALS-EN.md` entry **P-25**. The owner
published the `workspace` branch carrying a full export of a parallel Arena
session (repo snapshot dated 2026-10-06). The reference-library data was
ported verbatim in commit `bf15816`. This document assesses the remaining
question: **which of that session's APP code is compatible with this
session's logic, and in what order should it be evaluated?**

---

## 1. What the two sessions share

Both branches descend from `48b1e5d` (PR #4). Both obey the same house
rules: bilingual validation messages, RLS with rule-3 `NULLIF` GUC reads,
reference packs as files not tables (ADR-007), ADR-driven requirements,
test-first claims with evidence logs. Both arrived independently at the
same PMS branch-table concept ("overlap is legitimate, narrowest footprint
wins") and at the same B16.5-2025 flange ratings — the ported packs were
cross-checked cell-by-cell (4,725/4,725 exact), which is strong evidence
the two sessions read the same standards the same way.

## 2. Where they diverge

| Dimension | This session (`arena/782120e4-…`) | Parallel session (workspace export) |
|---|---|---|
| Migrations | 36 | 62 (+26 not ours; we hold 9 they lack) |
| Models | our set incl. MTO staging / accepted snapshots / test packs / order gap | +23 models incl. baseline, ITP, equipment/nozzles, standards library, fitting definitions, PMS items/valve specs/NDT |
| Test files | 60 | 100 |
| PMS core | `PipingClassService` + rules table (8 kinds) + branch rules with reinforcement/pad thickness + EXCLUDE constraint + design basis + publish gate (pad check, flange P-T check) | Richer class HEADER semantics (fluid service category, severe cyclic condition, design temp min/max with units, material group/spec/grade keys), per-size rules, class items, valve specs, specialty items, NDT requirements, compliance service, bulk entry |

**The central conflict is the PMS schema itself.** Both sessions
implemented ADR-008's class rules independently:
- theirs: `2026_09_23…29` chain (`create_piping_class_rules`,
  `per_size`, `enrich_piping_class_header`, `create_piping_class_items`,
  `create_piping_class_valve_specs`, `severe_cyclic_is_a_condition…`);
- ours: `2026_10_11_120000_create_piping_classes_and_rules` +
  `2026_10_12_120000_add_design_basis_and_pipe_material_kind`.

Merging these two schemas is a design project, not a copy operation, and
stays an owner decision (P-25 open item 1).

## 3. Module categorisation

### A. Data packs — DONE
Ported in `bf15816` (11 packs, 16 schemas, validator, tests).

### B. Additive modules — low conflict, recommended for stepwise porting
1. **Engineering screening** (`App\Services\Engineering\MaterialScreening`,
   `MaterialScreeningEvidence`, finding dispositions/responses,
   `scripts/screen-material.php`, docs `MATERIAL-ENGINEERING-SCREENING-*`).
   A read-only, versioned, bilingual declaration screener for PIPE /
   FLANGE / VALVE / GASKET / BOLTING families against the ported packs;
   it "never certifies a standard, edits a source or grants approval".
   DB footprint: one evidence/snapshot table. Their tests: 10 screening
   tests + migration tests + snapshot tests.
2. **Fitting definitions + review** (`ProjectFittingDefinition` model +
   2 migrations + `FittingReviewService` + controller + 14 tests +
   `B16-9-*` docs). Built exactly on the ported `fitting-dimensions`
   pack (B16.9-2018); the B16.9 PDF itself is in `.cache` from the export.
3. **Standards library** (`Standard`, `StandardCitation`,
   `ProjectStandard`, `StandardsLibrary` service + migration) — a
   metadata layer over standards; must be reconciled with our
   `StandardsPackRepository` (small design note first).
4. **MR entry** (`MaterialRequisitionEntryService`,
   `MaterialRequisitionAccessService`, policy, forms; 25 dedicated
   tests). Builds on `ProcurementService`, which exists unchanged in our
   tree — genuinely additive.
5. **Engineering baseline / model import** (`BaselineService`,
   `ModelImport*`, `EngineeringItem`) — larger; depends on the
   components/ports model we already share. Evaluate after 1–4.

### C. Conflict zone — owner decision, not a blind port
6. **PMS header enrichment + NDT + valve specs** from their schema.
   Valuable semantics (fluid service category, severe cyclic as a
   condition, material keys, NDT requirement rows with source text),
   but expressed in their schema. Porting means re-expressing them on
   top of OUR model, one field-group at a time, each with its own
   migration + tests. Needs a short design note per field-group before
   any code.

### D. Deliberately deferred
7. **ITP** — the owner paused ITP in the parallel session itself
   («ITP رو بذار برای بعد»); its models/services stay archived.

## 4. Stepwise plan (one verifiable step at a time)

| Step | Content | Exit criterion | Status |
|---|---|---|---|
| 1 (this document) | Inventory + compatibility assessment | owner approval of the sequence | DONE 2026-10-12 (`3bdff9b`) |
| 2+3 (merged) | Port **engineering screening + fitting definitions/review** together — the screening-evidence layer is keyed to fitting-definition rows (`MaterialScreeningEvidence::capture`), so they are one functional unit. Ported: `FittingDimensions` accessor, `ProjectFittingDefinition` model, `ProjectFittingDefinitionService`, `FittingReviewService`, `MaterialScreening` + `MaterialScreeningEvidence`, 3 migrations (definitions / review policy-runs-decisions / screening snapshots, all with house-style RLS), the 4 test files (32 tests), 7 design docs. UI (controller/routes/views) deferred to its own step | their tests green on owner's Laragon (`php artisan test`) | PORTED 2026-10-12 — awaiting Laragon verification |
| 4 | Port **MR entry** — `MaterialRequisitionEntryService` + `MaterialRequisitionAccessService` + `PmsAccessService` (portable via its standalone `pms_author_policies` migration), `ProcurementService` replaced with their strict superset (MR docblock + numbering bug-fix: `projects.material_requisition_sequence` counter, `firstOrFail`), migrations `2026_09_29_235700` (PMS author policies) + `2026_09_30_042713` (seq column + `mr_author_policies`, house RLS), `MaterialsController` replaced (MR creation + author-access endpoints + plan gate, verbatim additions), `mr-entry.blade.php` + 2 procurement-screen merges, 2 POST routes, lang files (`mr`, `fitting_review`, `project_fittings`, `material_screening`, `project_materials` en+fa), tests verbatim (`MaterialRequisitionEntryTest` + `MaterialRequisitionMigrationTest`), docs `MATERIAL-REQUISITION-ENTRY-2026-09-30.md` + their `ADR-018-Material-Management-Operational-Completion.md` (their number kept — code cites it; our numbering stops at ADR-015; divergence logged for doc consolidation). Models byte-identical, seeder plans already ours. | 19 dedicated tests green on owner's Laragon (`php artisan test --filter=MaterialRequisition`) | PORTED 2026-10-12 — awaiting Laragon verification |
| 5 | **Standards library** reconciliation: `docs/ADR-019-Standards-Library-Reconciliation-EN.md` records an ITP dependency and publication-edition conflict; stop before port per step rule | owner chooses catalogue/adoption-only adaptation or full deferral; existing `StandardsPackRepository` consumers unaffected | OPTION A IMPLEMENTED 2026-10-12 — catalogue/adoption only; awaiting Laragon verification |
| 6 | PMS field-group design notes (fluid service, severe cyclic, material keys, NDT): `docs/ADR-020-PMS-Field-Groups-Reconciliation-EN.md` | owner chooses first group and resolves its open cardinality/scope decisions before schema work | DESIGN COMPLETE 2026-10-12 — owner selection pending |
| 7 | Baseline / model import evaluation (`docs/ADR-021-Baseline-Model-Import-Reconciliation-EN.md`): accepted-MTO semantic collision, missing PCF parser/SpreadsheetReader, model-write risk | no-go on wholesale port; owner decides parser-only or full deferral | ASSESSED; parser-only follow-up ported 2026-10-12 — awaiting Laragon verification; baseline/model importer still deferred |

Each step: copy the module's files, adapt imports/namespaces only where
mechanically required, keep their tests verbatim where runnable, run the
house checks (brace balance here; `php artisan test` + `pint` on the
owner's Laragon tier), commit, and log the result in HANDOFF + P-25.
Any step that shows a logic conflict stops and becomes a design note
instead of a forced merge.

## 5. Risks and guards

- **Schema collisions:** steps 2–5 were screened above; step 6 is the
  only zone with known collisions and is gated behind design notes.
- **Silent semantic drift:** both sessions use identical vocabulary for
  the shared concepts (verified for branch tables and flange ratings);
  where a ported module references a concept we lack, we port the
  concept's minimum definition with it, noted in the commit.
- **Provenance:** every ported file keeps its behaviour and tests; the
  export remains on the remote `workspace` branch as the archive of
  record for anything not yet ported.

**CI correction (PR #7):** step 2+3 is NOT verified as portable: ADR-025 documents the general-material vs dimensional-fitting schema collision in screening evidence migration. Full Laravel migration and compose bootstrap are blocked; no false green claim.

**Recovery correction:** the `2026_09_29_150000` predecessor migration and `ProjectMaterialSchema` existed in the parallel archive but were omitted in our step-2+3 port. Restored verbatim with original `MaterialMigrationTest`; ADR-025 records corrected diagnosis and CI gate.
