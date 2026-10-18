# ADR-019 — Reconcile the parallel standards library before porting

Status: ACCEPTED IN PART — owner selected option A (catalogue + project adoption only); citation/ITP deferred
Date: 2026-10-12
Related: ADR-007 (file-based reference packs), parallel-session ADR-016 (not copied because ADR numbering collides), P-25 step 5

## Context and evidence

The parallel export at `workspace` contains `Standard`, `ProjectStandard`, `StandardCitation`, `StandardsLibrary`, and migration `2026_09_20_100000_create_standards_library.php`. Its library is a *catalogue of code identities and project applicability*, not a replacement for our file-backed `StandardsPackRepository` (which reads versioned `*.pack.json` through `get()` and `available()` and is consumed by `GateService`, `ProjectSetupController`, and `GateDemoController`). These two responsibilities can coexist; neither should silently become an alias of the other.

But the exported module is **not independently portable**:

1. Its migration ends with `COMMENT ON COLUMN itp_stages.reference`; this branch has no `itp_stages` table. Running it here would fail.
2. `StandardCitation::TYPES` contains only `ITP_STAGE`, and `printed()` calls `ItpStage::find()` and revision snapshot APIs not present in this branch. Thus there is no usable citation host in this branch. Its `StandardsLibraryTest` relies on that host and cannot be kept verbatim as an integration test without importing ITP, which the owner explicitly deferred.
3. The later parallel migration `2026_09_29_235500_freeze_document_standard_references.php` adds `standard_citations.source_edition` and ITP snapshot/guard triggers. The exported model's `printed()` and fillable list assume that column; importing only the earlier migration would leave model and schema out of sync. Importing the later migration requires ITP tables and revision semantics.
4. The exported `ProjectStandard::effectiveEdition()` falls back to the *mutable* catalogue edition when a project has no explicit edition. That is acceptable as an on-screen draft preview, but not as the authoritative basis of a released engineering decision. Cite/release must pin an edition and immutable identity/snapshot at publication, otherwise a catalogue edit silently changes the apparent basis. The parallel export itself added a later freeze migration to address precisely this risk.
5. A polymorphic citation carries `citable_type`, `citable_id`, and `project_id` without a database FK to its host. A future port must validate host existence and matching project, and document deletion/revision behavior; checking only the type string is insufficient for tenant integrity. The global catalogue also needs an explicit administrative write boundary, not merely an absence of tenant-facing UI.

## Decision requested (no implicit approval)

**Recommended:** defer citation rows and ITP integration. Implement the global catalogue and per-project adoption as a separate future step, adapting the exported migration to omit the ITP-specific statement and creating dedicated tests for project isolation/edition behavior. Do not claim this is a verbatim port; any adapted module needs its own acceptance gate. Keep the file-based reference packs authoritative for engineering numbers and provenance. Explicitly pin editions before any published use. Add citations only when a real host and immutable publication semantics have been designed (ITP remains deferred).

Alternative: defer the entire library until a citation host is approved. This avoids an unused metadata table but postpones consistent per-project code naming. **Do not** copy the exported migration/model wholesale or import ITP as an incidental dependency of step 5.

Owner choices: (A) catalogue + project adoption now, citations later (recommended), or (B) defer all of step 5. Until chosen, P-25 step 5 is blocked at the reconciliation gate. No tests were executed here per owner's instruction; Laragon remains the runtime verification tier for earlier ported steps.

## Implementation after owner selection (2026-10-12)

Owner chose option A. `2026_10_12_130000_create_standards_catalogue.php` creates only `standards` and RLS-protected `project_standards`. `Standard`, `ProjectStandard`, and the catalogue/adoption methods of `StandardsLibrary` are based on the parallel export; citation model/methods/table, ITP-specific migration statements, ITP tests, routes and UI are excluded. New `StandardsCatalogueTest` replaces the ITP-dependent exported suite and exercises normalization, edition pinning, withdrawal and RLS isolation. `StandardsPackRepository` and its consumers are unchanged. Catalogue writes are service-level only: do not expose `define()`/`withdraw()` to tenant routes; platform-admin authorization must precede any future endpoint. Null project edition is a draft preview only: any future release workflow must explicitly pin its effective edition and immutable source snapshot. Tests intentionally not executed in this environment per owner; Laragon gate pending.
