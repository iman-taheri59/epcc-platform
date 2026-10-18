# ADR-020 — PMS field-group reconciliation on the current schema

Status: PARTIALLY IMPLEMENTED — group A schema/service/publish gate added; Laragon tests pending
Date: 2026-10-12
Related: ADR-008, ADR-019, P-25

## Context

The parallel session built a richer PMS header, per-size items, valve specifications and NDT requirements on its own migration chain. This branch has its own `piping_classes`, `piping_class_rules`, `piping_class_branch_rules` and design-basis migration. Both branches create `piping_class_rules`; importing the parallel migrations would collide. The current class has `design_pressure_mpa` and `design_temperature_c`; `PIPE_MATERIAL` is already a DN-band rule kind. Do not replace that design with a second competing PMS schema.

This ADR defines four separable *proposals*, not approved migrations. For every chosen group: write a dedicated migration on the present tables, add model/service validation and bilingual failures, preserve drafts, run an upgrade/rollback test plus publish-gate tests on Laragon, and record source/effective-edition provenance. Do not treat an unverified field as evidence of code compliance.

## Group A — fluid service category (recommended first)

Store an explicit project-class condition on `piping_classes`, not in a size-band `value`: the classification applies to the whole class, while a line assigned to it still needs a consistency check. A controlled vocabulary should be derived from the existing `fluid-service.ref.json` pack, not from untrusted arbitrary strings. Allow null on existing drafts; publish only when classification is confirmed. Owner decision: each piping class refers to exactly one fluid-service category. Use one nullable category column on the class for existing drafts; require a valid category before new publication. Do not create a many-to-many table. Lines assigned to the class must be checked against the class classification when line-level classification exists. Keep the reference edition/source and classification decision auditable. Gate downstream calculations only where the applicable B31.3 rule explicitly requires it.

## Group B — severe cyclic service

Represent this as an explicit class design condition (`true` / `false` / unknown), **not a material identity and not a default false**. Migrated rows must remain unknown until engineering review; require a recorded determination at publish. Cross-check relevant end-preparation, weld, examination and branch design checks; do not infer the condition from design pressure alone. If classification can vary per line, decide whether the class must be split or an explicit line override is permitted before schema work.

## Group C — material identity keys

The present `PIPE_MATERIAL` DN-band rule already carries `spec`, `grade`, optional `psl`, `pipe_type`, `p_no`, `group_no`, and strength/joint-factor overrides; it resolves against B31.3 Table A-1 reference data. Do not add redundant header `material_group/spec/grade` fields without a documented precedence rule. Better alternative: normalize and validate those keys at rule-write/publish time against the file-backed material reference, while preserving the per-band declaration and the mandatory manually entered P-No/Group-No for materials outside the library. A header summary, if needed, should be derived rather than a second source of truth. Publish must fail for an unresolved item unless the permitted manual path contains explicit provenance and approval.

## Group D — NDT requirements

Do not import parallel `piping_class_ndt_requirements` without deciding what they apply to: service/class, joint kind, material, size band, or a specific weld. Recommend a separate versioned requirement table referencing the current class/revision and a bounded scope (joint kind, DN interval if relevant), examination method/extent/acceptance criterion, source standard and clause + edition; overlapping contradictory rules must block publication. Distinguish prescribed minimum from project-specific enhancement. No signed ITP or inspection result should be retroactively rewritten when a PMS draft changes. ITP is deferred; provide no implied execution/acceptance workflow now.

## Open decisions and sequence

1. Group A is selected: exactly one fluid-service category per piping class (owner decision). Implemented without a legacy backfill: draft rows may remain null; new publications require a category. No important live database data exists per owner. If a published legacy row exists, its null is retained, not fabricated.
2. Confirm whether the severe-cyclic determination belongs solely to the class or can be line-specific (B).
3. Decide whether a header material summary is required despite the existing per-band material rule (C); default is no duplicate storage.
4. Define NDT scope, overlap and revision policy (D); ITP integration remains a separate future decision.

**No application code or schema change in step 6.** This is the conflict-zone design checkpoint required by the stepwise integration plan. Tests were not run here; the owner's earlier Laragon verification gates remain pending.

## Owner decision and source-data correction (2026-10-12)

Owner: «در واقع هر پایپینگ کلاس می‌تواند به یک دسته سیال اشاره کند». Interpret as one classification per class, not a list or many-to-many association. This is *not* the process-fluid identity (water/gas/oil) and does not include severe cyclic, which is a separate condition. The reference pack `fluid-service.ref.json` currently enumerates six services, while its prose says seven; correct this internal count before using its enum for validation. Normal is a fallback classification, not a default silently assigned to legacy rows. Group A implemented on the existing schema, without automatic backfill; existing published nulls remain explicit until separately reviewed.

## Group A implementation (2026-10-12)

Migration `2026_10_12_140000` adds one nullable `piping_classes.fluid_service_category` with a database CHECK for the six B31.3-2024 §300.2 service codes. `FluidServiceCategory::ALL` is the service-layer vocabulary; it must be kept in sync with `fluid-service.ref.json` and the CHECK. `setFluidServiceCategory()` edits drafts with bilingual validation; publish refuses null (after prior engineering gates). The existing PMS fixture tests were adjusted to select NORMAL explicitly; a new test covers missing category and rejects `SEVERE_CYCLIC` as a category. No implicit classification, automated Category D eligibility assertion, line-level assignment check or ITP integration is claimed. PHP syntax parsed, JSON parsed; PHPUnit/Laragon not executed here.
