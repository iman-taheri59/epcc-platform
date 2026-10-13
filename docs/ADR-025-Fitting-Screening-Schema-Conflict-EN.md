# ADR-025 — Ported fitting/screening schema conflict exposed by GitHub CI

Status: ROOT CAUSE FOUND — omitted parallel predecessor migration and schema service; ported, CI verification pending
Date: 2026-10-12
Related: P-25 steps 2+3, ADR-021

## Evidence

GitHub CI PR #7 run `38343518174` reached PostgreSQL migrations and failed at
`2026_09_29_180000_capture_material_screening_snapshots.php` with SQLSTATE
42703: `project_fitting_definitions.size_designation` does not exist. That
migration's RLS INSERT policy references `material_family`, `size_designation`,
`reference_standard`, `material_spec`, `material_grade`, `declaration_reason`
and `properties` from the parallel session's general material-definition schema.
Our `2026_09_28_140000_create_project_fitting_definitions.php` defines only
CAP/ELBOW dimensional records (`nps`, `dn`, `od_mm`, `dimension`,
`dimension_mm`, project spec provenance). `MaterialScreeningEvidence` and the
ported screening tests also expect the absent general-material columns, as
does the parallel `FittingReviewService` for non-FITTING families. This is a
**logic/schema collision**, not just a missing column typo. Docker compose
bootstrap fails from the same migration chain; PHPUnit could not start.

## Guard and proposed options

Do not add nullable columns merely to satisfy CREATE POLICY: the ported service
would still misinterpret dimensional fitting records as generic material
declarations, while tests would describe a workflow absent from this branch.
Do not remove the RLS predicate to force a green migration or skip failing
tests. The earlier assessment incorrectly classified screening + fitting as a
single portable unit; revise that claim. The two coherent options are:

A. Design a distinct project material declaration entity/table and review
workflow with its own screening snapshots; keep dimensional fitting definition
as a separate host, explicitly mapping any shared review semantics (recommended
if material screening is a current product need).

B. Defer general material screening snapshots/services/tests until the new
schema is approved, retaining standalone `MaterialScreening::check()` as a pure
reader; repair the fitting-only review migration path independently.

Before either option, capture the next migration/test failures behind this one,
without masking the defect. A new independent CI job runs the three pure PCF
test classes without migrating, allowing verification of the read-only parser
while full Laravel integration is blocked. No production-safe assertion about
ported screening or fitting review can be made until migrations and tests pass.

## Follow-up discovery after inspecting the actual workspace archive

The prior diagnosis that the parallel session lacked a compatible schema was **incorrect**. The archive contains exactly the omitted predecessor `2026_09_29_150000_extend_project_material_declarations.php` (chronologically between the review and snapshot migrations) and `ProjectMaterialSchema.php`. It expands the dimensional `project_fitting_definitions` table into a discriminated FITTING/PIPE/FLANGE/VALVE/GASKET/BOLTING declaration with family-aware checks and review policies, preserving existing fitting rows. The port had copied migration 090000 and 180000 but skipped 150000; its services already call `ProjectMaterialSchema` although that class was missing. Copied the missing migration, service and original populated-upgrade `MaterialMigrationTest` verbatim. This restores the *original* parallel migration order rather than inventing nullable columns or weakening RLS. The earlier recommendation for a separate material table is an architectural alternative, **not** a prerequisite already approved by the owner. CI must now verify this recovery; until then the full workflow remains unverified, including absent HTTP presentation and other parallel modules.
