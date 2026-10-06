# Remaining Requirements for Piping MTO and Material Management

**Date:** 2026-10-06  
**Status:** requirements proposal and staged design; not implemented unless explicitly stated as present in the current codebase.  
**Scope:** Piping first; all EPCC disciplines remain in product scope, but Piping is the deep/template discipline for the current MVP.  
**Inputs:** uploaded package `epcc-platform-mto-docs-changes-2026-10-06.txt`, current branch `arena/roadmap-reconciliation`, existing ADRs, and prior user decisions.

---

## 1. Executive Summary

The uploaded MTO documents contain important and product-relevant decisions, but they must not be copied over the current repository as wholesale replacements. Several files in the package appear to come from a different documentation/code baseline and describe some features as `BUILT` that do not exist in the current branch, such as `BaselineService`, `PcfParser`, `ModelImportService`, `accepted_mto_rows`, and multiple PMS services. This document reconciles the useful requirements from the package with the real state of the current repository.

The next target is to make the following Piping material-planning chain reliable and testable:

```text
Material Library
→ MTO Document / Revision / Staging Rows
→ Immutable Accepted MTO Rows
→ Gross MTO vs Net Engineering Need
→ Order Gap against valid PO
→ Material Receipt / Usable Gap
→ Piping Workspace / Test Pack / Turnover readiness
```

Key principle: **an approved MTO is a gross procurement-planning quantity; a model/isometric estimate is a net engineering need. These two quantities must not be collapsed without explicit provenance and a compatible basis.**

---

## 2. Actual State of the Current Branch

### 2.1 Built and reliable

The current branch actually contains and tests the following capabilities:

- Fixed Laravel/PostgreSQL runtime restoration through `restore.sh`.
- Core RLS and multi-tenancy.
- CDE / document register / transmittals / punch / MC certificate.
- Baseline procurement and warehouse flow with MR/PO/receipt/issue/deliver/reverse at the current level.
- Piping foundation:
  - line register;
  - AFC evidence gate;
  - components/ports;
  - joints;
  - compatibility checks;
  - task-first Laravel Piping Workspace;
  - first test-pack workflow: create pack, assign line, and transition `DRAFT → READY → TESTED → ACCEPTED`.
- Latest full suite: `858 passed / 3166 assertions`.

### 2.2 Claims in the uploaded package that are not built in this branch

The following must not be documented as built in this branch unless their code is imported or implemented:

| Claimed/mentioned item | Current branch status |
|---|---|
| `BaselineService` | absent |
| `baseline_batches` / `baseline_discrepancies` | absent |
| `PcfParser` / `ModelImportService` / `model_imports` | absent |
| quarantine for unknown imported lines | absent |
| immutable accepted-MTO-row snapshot store | absent |
| PMS entry/access/bulk services from the package | absent |
| NDT requirement model from the package | absent |
| `material_requisition_sequence` from the package | absent |

Conclusion: the uploaded package is a **requirements/design input**, not a direct replacement for the current files.

---

## 3. Accepted Domain Principles for MTO

### 3.1 Gross MTO and Net Need are separate bases

- Approved project MTO is a **gross procurement-planning** quantity.
- 3D model / PCF / manual isometric take-off is a **net engineering need**.
- A positive `net > gross` difference is a purchase-planning shortage.
- `gross >= net` is not a shortage; the delta should be shown only.
- Percentage tolerance must not suppress a positive shortage.
- The system must not infer waste factor, contingency, or over-order cause unless explicit data exists.

### 3.2 Order Gap and Physical Material Gap are separate indicators

**Order Gap:**

```text
Accepted Gross MTO Quantity - Valid Issued/In-force Net PO Quantity
```

- Draft and cancelled POs are excluded.
- Amendments replace previous effective quantity.
- PO above MTO is displayed as above-plan/over-ordered, not as negative shortage.

**Receipt / Usable Material Gap:**

```text
Required Quantity - Usable Physical Material Quantity
```

- The usable-state definition remains undecided and must be confirmed separately.
- Until then, the system must not silently classify pending inspection, quarantine, reserved, or rejected material as usable or unusable.

### 3.3 MTO row identity must be immutable

`mto_item_no` alone is not a stable key. The correct accepted-row identity is:

```text
Project
+ MTO document / sub-MTO
+ accepted revision or immutable accepted batch
+ mto_item_no
+ stable accepted_mto_row_id
```

Rules:

- Downstream links must target `accepted_mto_row_id` or an equivalent stable key.
- Draft staging rows must not be link targets.
- `mto_item_no` remains a human display/reference value, not the identity by itself.
- Description matching and fuzzy matching are forbidden.

### 3.4 Acceptance is all-or-nothing

For one MTO document/sub-MTO revision:

1. All rows are validated.
2. Scope, material matching, unit compatibility, and identity are checked.
3. If any row fails, the whole batch stays DRAFT.
4. If all rows pass, all accepted-row snapshots are created in one transaction.
5. Only that batch is marked ACCEPTED.
6. Sibling documents/sub-MTOs remain independent; accepting one does not close another coverage gap.

### 3.5 Multiple MTO aggregation requires disjoint scope or additivity evidence

Two accepted MTOs may be summed only when:

- their scopes are truly disjoint; or
- a pair-level additive relationship exists between exact immutable revisions.

An additive relationship requires:

- a written reason;
- if document-based, a citation to the exact immutable CDE revision;
- if outside CDE, a controlled evidence record with stable ID, issuer/source, exact number/revision/date, retained file or immutable URI, and hash;
- hash alone is insufficient;
- the relationship is not inherited by later revisions.

### 3.6 Scope must be stable and versioned

- Scope must come from the authoritative project hierarchy, not free text.
- Header scope is valid only when all rows are in that scope or valid descendants.
- Mixed-scope MTOs require row-level scope.
- A row outside the header boundary is conflicting/unallocated.
- Missing or ambiguous required scope blocks acceptance of the whole revision.
- Accepted snapshots must preserve the scope ID and hierarchy revision at acceptance time.
- Rename with the same stable ID preserves coverage.
- Split/merge/replacement with new IDs does not inherit coverage.
- Cross-version mappings must be append-only and evidence-backed; mapping alone does not authorize summing quantities.

---

## 4. Phase A Requirements — Piping MTO Foundation

## A1. Material Library

**Implementation status as of 2026-10-06:** first foundation slice is built in code. The current branch now has `material_items`, `MaterialItem`, `MaterialCatalogueService`, `MaterialCatalogueSeeder`, and `MaterialLibraryTest`. This is only the catalogue identity foundation; it does **not** yet mean MTO document/staging/acceptance exists.

### Goal

No official MTO row should be accepted without matching a material library item. Unmatched rows may remain DRAFT and visible as open work.

### Proposed entity: `material_items`

Minimum fields:

| Field | Meaning |
|---|---|
| `id` | stable UUID |
| `discipline` | currently `PIPING`; extensible to other disciplines |
| `category` | `PIPE`, `FITTING`, `FLANGE`, `VALVE`, `GASKET`, `BOLT_NUT`, `SPECIALTY`, optionally `SUPPORT` |
| `commodity_code` | project/company commodity code |
| `description_en`, `description_fa` | bilingual description |
| `base_unit` | `m`, `each`, and only if needed `kg` |
| `size_nps`, `size_dn` | where applicable |
| `schedule`, `rating`, `class_code` | category-dependent |
| `material_spec`, `grade` | technical specification |
| `source` | reference pack, PMS, project catalogue, or manual approved |
| `status` | `ACTIVE`, `WITHDRAWN`, `PROJECT_ONLY` |

### Rules

- Material identity is not created from description.
- Alias/vendor code is a lookup aid, not identity.
- Withdrawn items remain readable for history; new acceptance against them needs a policy decision.

### Decision questions

1. Should the material library be two-level? **Decision applied for the foundation slice:** yes — `project_id = NULL` is reserved for global/reference rows and `project_id != NULL` is project overlay.
2. Should project overlay be mandatory for Iranian deployments? **Decision applied for the foundation slice:** yes — the writable path currently creates project overlay rows because commodity coding differs by customer/company.

---

## A2. MTO Document, Revision and Staging

### Goal

MTO must be handled as a versioned engineering document, not as a free spreadsheet.

### Entities

#### `mto_documents`

- project_id
- discipline
- document_number
- title_en/title_fa
- source_type: `CDE_REVISION`, `EXTERNAL_EVIDENCE`, `MANUAL`
- cde_document_id nullable
- company_id

#### `mto_revisions`

- mto_document_id
- revision_code
- revision_date
- state: `DRAFT`, `UNDER_REVIEW`, `ACCEPTED`, `SUPERSEDED`, `REJECTED`
- source_file_id/hash nullable
- scope_mode: `HEADER_SCOPE`, `ROW_SCOPE`, `MIXED`
- header_scope_id nullable
- hierarchy_revision_id nullable

#### `mto_staging_rows`

- revision_id
- `mto_item_no`
- raw_description
- raw_quantity
- raw_unit
- material_item_id nullable
- row_scope_id nullable
- piping_class_id nullable
- subsystem/system/unit reference nullable
- validation_state
- validation_errors json

#### `accepted_mto_rows`

Immutable snapshot:

- id
- accepted_batch_id
- revision_id
- `mto_item_no`
- material_item_id
- gross_quantity
- unit
- canonical_quantity nullable
- scope_id
- hierarchy_revision_id
- piping_class_id nullable
- frozen display labels
- source row hash
- accepted_at

### Rules

- Staging rows are editable.
- Accepted rows are immutable.
- Accepted rows are not normally updated or deleted; supersession comes through a new revision.
- Acceptance is performed for the whole revision.

---

## A3. MTO Acceptance Validation

### Validation gates

A revision can be accepted only when:

1. every row has a valid material item;
2. every row unit is compatible with the material unit;
3. quantity is positive and within accepted precision;
4. every required scope can be resolved;
5. row scope is not outside the header boundary;
6. duplicate ambiguity in `mto_item_no` is controlled within document/sub-MTO;
7. coverage is computable for in-scope unit/category combinations;
8. any required additive relationship has exact evidence.

### Validation states

- ACCEPTABLE
- BLOCKED_UNMATCHED_MATERIAL
- BLOCKED_SCOPE_MISSING
- BLOCKED_SCOPE_CONFLICT
- BLOCKED_UNIT_INCOMPATIBLE
- BLOCKED_DUPLICATE_IDENTITY
- WARNING_OVERLAP_AMBIGUOUS

### UI behavior

- No invalid row may be silently dropped.
- Users must be able to filter by errors.
- Messages must be bilingual FA/EN.
- Raw row text must be preserved.

---

## A4. Net Engineering Estimate

### Goal

Net engineering need from model/isometric/manual take-off must be comparable to gross accepted MTO without losing provenance.

### MVP sources

1. manual/isometric take-off entry;
2. derived simple quantity from existing components where enough data exists;
3. PCF import later unless separately approved.

### Proposed entity: `engineering_estimate_rows`

- project_id
- source_type: `MANUAL_ISO`, `MODEL_DERIVED`, `PCF_IMPORT`
- source_revision/evidence
- material_item_id
- accepted_mto_row_id nullable
- net_quantity
- unit
- scope_id
- piping_class_id nullable
- line_id nullable
- provenance json

### Rules

- Without accepted_mto_row_id, the row is provisional.
- No fuzzy match.
- If gross MTO is less than net need, a positive shortage is shown.
- If gross MTO is greater, only a delta is shown.

---

## A5. Purchase Order Attribution and Order Gap

### Goal

PO lines must be attributable to material/scope/class for roll-up.

### Requirement

Each PO line needs:

- material_item_id;
- quantity/unit;
- effective state;
- scope allocation;
- optional piping class;
- project/company/vendor;
- amendment lineage.

### Rules

- Draft/cancelled excluded.
- Issued/in-force included.
- Amendment replaces previous quantity.
- Ambiguous scope remains unallocated.
- No automatic proration.
- PO above MTO is shown as over-plan, not negative shortage.

---

## A6. Receipt / Usable Material Gap

### Status

The usable-material definition is explicitly deferred. Until decided, the system should present raw material states separately and avoid final conclusions.

### Candidate decision for later approval

Usable:

- inspected and accepted;
- warehouse available;
- issued to site;
- delivered to site.

Not usable:

- pending inspection;
- quarantine;
- rejected;
- reserved for another scope;
- returned pending disposition;
- damaged.

This is not accepted yet.

---

## 5. Later Piping Completion Requirements

### 5.1 Equipment and Nozzles

- equipment register with tag/type/service/scope;
- nozzle register with tag/size/rating/facing;
- real line endpoints to equipment/nozzle/line/open end;
- validation for size/rating mismatches.

### 5.2 Fit-up and Welding

- joint fit-up record;
- fitter/welder code;
- fitted_at;
- WPS/PQR reference;
- weld process;
- shop/field;
- repair/rework cycle.

### 5.3 NDT outside Material Inspection

- NDT requirements come from PMS/ITP;
- execution/result entity is independent;
- RT/UT/PT/MT/PMI where decided;
- repair/retest lifecycle;
- must not be mixed into `MaterialInspection`.

### 5.4 Test Pack Completion

The current test-pack workflow is only the first slice. Completion requires:

- blind list;
- pressure test report;
- medium/duration/gauge calibration;
- no open A punch;
- required NDT accepted;
- material traceability complete;
- reinstatement checklist;
- evidence files and turnover link.

---

## 6. Product / Iran-first Requirements

### 6.1 Licensing

- offline signed license file;
- installation ID;
- modules/projects/users/support expiry;
- offline activation;
- license status page;
- audit log.

### 6.2 Air-gap Verification

- `php artisan verify:airgap`;
- no CDN;
- no external fonts/assets;
- local avatars;
- update bundle integrity.

### 6.3 Backup / Restore

- DB + files backup;
- checksum;
- dry-run restore;
- restore validation;
- bilingual operator guide.

### 6.4 Sales Assets

For Iran-first sales and later international readiness:

- proposal FA/EN;
- SOW FA/EN;
- SLA FA/EN;
- support contract FA/EN;
- air-gap checklist FA/EN;
- implementation/UAT checklist FA/EN;
- pricing sheet FA/EN.

---

## 7. Proposed Execution Order

### Slice 1 — Documentation reconciliation

- Register this document and its Persian counterpart.
- Update TODO/HANDOFF against the actual current branch.
- Do not overwrite files from the uploaded package directly.

### Slice 2 — Material Library foundation

**Built 2026-10-06 in this branch:**

- migration/model/service for `material_items`;
- two-level identity shape: global/reference rows plus project overlay rows;
- RLS for project participants and project overlays;
- synthetic PH-14 piping catalogue seed;
- tests for identity, exact commodity lookup, alias-not-identity, validation, RLS isolation and seed path.

### Slice 3 — MTO document/revision/staging

- `mto_documents`, `mto_revisions`, `mto_staging_rows`;
- manual entry/import placeholder;
- initial validation workspace.

### Slice 4 — Accepted immutable snapshots

- `accepted_mto_batches`, `accepted_mto_rows`;
- all-or-nothing transaction;
- full rollback tests.

### Slice 5 — Net estimate and gross/net comparison

- `engineering_estimate_rows`;
- link to accepted MTO row;
- no-tolerance shortage logic.

### Slice 6 — PO attribution and order gap

- PO line material/scope attribution;
- order gap board;
- separate above-plan indicator.

### Slice 7 — Usable material decision and physical gap

- first obtain the user decision about usable states;
- then implement.

---

## 8. Open Decision Questions

These should be resolved one by one:

1. Should the material library be two-level: global/reference + project overlay?
2. How should MTO acceptance authority be limited until access design is finalized?
3. Should hierarchy revisioning be implemented from the beginning, or are stable scope IDs sufficient for the first slice?
4. Should the first net estimate source be manual/isometric entry or the existing component model?
5. Which material states count as usable?
6. Should supports enter the current component-like MTO phase or be deferred?
7. Is Excel/CSV MTO import required for MVP, or is manual entry enough for the first implementation slice?

---

## 9. Execution Principle

While requirements remain unfinished, every capability should follow this loop:

```text
Requirement proposal → conflict check → user decision → small implementation slice → tests → docs update
```

No document should claim `BUILT` unless migration/code/UI/tests actually exist in this branch.
