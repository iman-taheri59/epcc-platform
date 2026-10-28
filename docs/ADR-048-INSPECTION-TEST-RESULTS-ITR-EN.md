# ADR-048 — Inspection and test result records (MTR / NDT / hydro) and ITR compilation

Status: **ACCEPTED (2026-10-28)** — owner blanket instruction to start execution ("انجام کارها رو شروع کن"); the recommended options are adopted as the decisions: `result_provenance=internal_entry_and_external_register`, `weld_result_binding=joint_level_with_ndt_methods`, `hold_witness_points=structured_signoff_on_notification_stages` (slice 2 — A.4b), `mtr_binding=lot_and_heat` (slice 1 lands heat-first with optional lot; exact pairing in A.4b), `itr_generation=both` (A.5). The owner may revise any key at any time; a revision is an append-only amendment to this ADR, never a silent edit. Completes the quality loop that ADR-041/042–045/046 deliberately left open ("a notification is not an inspection result"), and unlocks the turnover dossier (ADR-057).

Implementation record — **slice 1 (service-only), 2026-10-28, commit `29c138d`**: migration `app/database/migrations/2026_10_28_100000_add_inspection_test_results.php` (forward-only; four append-only RLS+FORCE tables `joint_weld_records` / `joint_ndt_results` / `test_pack_results` / `material_certificates`, ordering + lineage + provenance-pairing triggers, `epcc.result_actor_id` session contract), `InspectionResultService` (recordWeld/recordNdt/recordTestResult/registerCertificate + jointHistory/packResults/certificatesForHeat readers, bilingual errors), `InspectionResultTest` 10 tests / 69 assertions green, `JointTest`+`PipingGrnTest`+`StockLedgerTest` regression green (77 tests / 275 assertions). Guides: `docs/INSPECTION-RESULTS-EN.md` / `docs/INSPECTION-RESULTS-FA.md`. Declared next slices: ITP hold/witness sign-offs on notification stages + role-scoped authority policies + unit-registry pressure validation + pack-transition gating (TODO item **A.4b**); ITR compiler remains **A.5**.

## Context

What exists:

- ITP notifications with drafts, immutable issue, cancellation, company acknowledgment and prerequisites (ADR-042…045) — the *announcement* of inspection events.
- The receipt chain MRR→QC→QA→GRN with version-bound manual decisions (ADR-046) — *acceptance* of deliveries.
- Independent inspection entry (ADR-041) and legacy `MaterialInspection` rows.
- Joints with numbering and fit-up (`JointService`, ADR-006) and piping test packs (`PipingTestPackService`) — the objects that results must bind to.

What does not exist: a structured record of **results** — material test reports (EN 10204 3.1/MTR) bound to lot/heat, NDT reports and per-joint weld results, hydrostatic/pneumatic test results per test pack, checklist-style hold/witness point sign-offs with signatures, and any ITR (Inspection & Test Report) compilation. Without results, no weld map, no test-pack dossier, no turnover package.

## Decision points (explicit owner choices required)

1. `result_provenance=internal_entry_and_external_register | internal_only`
   Recommended: **internal_entry_and_external_register** — mirrors the accepted TQ/NCR two-mode decision in ADR-031: results recorded in-system, or externally signed reports registered with their identity (never presented as platform-issued).
2. `weld_result_binding=joint_level_with_ndt_methods | test_pack_level_only`
   Recommended: **joint_level_with_ndt_methods** — each weld joint gets per-method results (RT/UT/MT/PT/VT) with repair-weld history; test-pack results (hydro) bind to the pack and enumerate member joints at test time.
3. `hold_witness_points=structured_signoff_on_notification_stages | free_form_result_only`
   Recommended: **structured_signoff** — ITP stages already exist on notifications (document revision stages, ADR-042); bind hold/witness point sign-off records (who, company, role, time, outcome) to them. TPI witness = company-scoped sign-off using the existing multi-company identity model.
4. `mtr_binding=lot_and_heat | lot_only`
   Recommended: **lot_and_heat** — custody amendments already carry heat identity; MTRs bind to (lot, heat) pairs so traceability from pipe piece → heat → certificate is exact.
5. `itr_generation=on_demand_compilation | frozen_signed_snapshots`
   Recommended: **both** — on-demand compilation for review, plus a frozen signed snapshot (digest-bound, like scope proposal envelopes) for handover.

## Sketch of the decision

- Append-only result tables (RLS + FORCE, ordinal journal where ordering matters): `inspection_results` (notification-stage sign-offs), `weld_results` (joint-bound, per method, with repair lineage), `test_pack_results` (hydro/pneumatic), `material_certificates` (MTR bound to lot/heat, file optional per ADR-031 precedent).
- ITR compiler service: given a test pack / system / dossier scope, assemble results + notifications + receipts + certificates into the bilingual PDF pipeline (existing Chromium+Vazirmatn renderer), with a completeness report that is **explicit, not a certificate** (house honesty pattern: enumerate missing results rather than silently omitting).
- Signature semantics follow ADR-031: external signatures are registered evidence, internal sign-offs use the platform identity + separation rules.

## Consequences

- Closes the quality loop: announce (ITP) → attend/sign (this ADR) → accept (ADR-046) → compile (ITR) → hand over (ADR-057).
- Weld maps and NDT coverage statistics become derivable; vendor rating (ADR-052) gains a quality input.
- Heavy test surface: separation (inspector ≠ notifier ≠ acceptor where required), immutability, external-register honesty, compilation completeness.

## Out of scope

Laboratory management (ISO 17025 workflows), radiography film/digital archive storage, NCR/TQ handling (ADR-031 implementation is a separate TODO item), commissioning checklists (ADR-057).
