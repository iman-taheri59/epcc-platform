# ADR-048 — Inspection and test result records (MTR / NDT / hydro) and ITR compilation

Status: **ACCEPTED (2026-10-28)** — owner blanket instruction to start execution ("انجام کارها رو شروع کن"); the recommended options are adopted as the decisions: `result_provenance=internal_entry_and_external_register`, `weld_result_binding=joint_level_with_ndt_methods`, `hold_witness_points=structured_signoff_on_notification_stages` (slice 2 — A.4b), `mtr_binding=lot_and_heat` (slice 1 lands heat-first with optional lot; exact pairing in A.4b), `itr_generation=both` (A.5). The owner may revise any key at any time; a revision is an append-only amendment to this ADR, never a silent edit. Completes the quality loop that ADR-041/042–045/046 deliberately left open ("a notification is not an inspection result"), and unlocks the turnover dossier (ADR-057).

Implementation record — **slice 1 (service-only), 2026-10-28, commit `29c138d`**: migration `app/database/migrations/2026_10_28_100000_add_inspection_test_results.php` (forward-only; four append-only RLS+FORCE tables `joint_weld_records` / `joint_ndt_results` / `test_pack_results` / `material_certificates`, ordering + lineage + provenance-pairing triggers, `epcc.result_actor_id` session contract), `InspectionResultService` (recordWeld/recordNdt/recordTestResult/registerCertificate + jointHistory/packResults/certificatesForHeat readers, bilingual errors), `InspectionResultTest` 10 tests / 69 assertions green, `JointTest`+`PipingGrnTest`+`StockLedgerTest` regression green (77 tests / 275 assertions). Implementation record — **A.4b slice 1 (nominated authority + evidence-gated pack states), 2026-10-28, commit `d257468`**: migration `app/database/migrations/2026_10_28_140000_add_result_authority_and_pack_gating.php` (forward-only) adds `result_recorder_policies` (versioned, suspendable, RLS+FORCE, selectors `user:<uuid>` / `role:<company>:<code>`, one action per evidence kind) plus `epcc_result_nomination()`, and re-declares the three slice-1 guards to STAMP and REQUIRE the authorising nomination on every record (`recorder_policy_id`, `RESULT_AUTHORITY_NOT_NOMINATED` 42501). Recording was membership-implied in slice 1 — any project member could create the inspection record a client reads; it is now default-deny, and the authorising policy is auditable on the row. The same migration installs `epcc_guard_test_pack_transition()` on `piping_test_packs`: TESTED requires a recorded result, ACCEPTED requires the LATEST result to be PASS, ACCEPTED is terminal, and the DRAFT→READY→TESTED→ACCEPTED order cannot be skipped (enforced against direct SQL, not just the service, which now maps these refusals to bilingual field errors). `ResultAuthorityAndGatingTest` 13 tests / 40 assertions green; `InspectionResultTest` (10/69) + `ItrSnapshotTest` (10/72) + `JointTest` + `PipingGrnTest` + `StockLedgerTest` + `CdeEvidenceIntegrityTest` + `ComponentModellingTest` + `UnitPreferenceTest` green together (218 tests / 819 assertions). Fixture helper `tests/Support/ResultRecorderPolicyFixture.php` makes nomination explicit in tests.

Guides: `docs/INSPECTION-RESULTS-EN.md` / `docs/INSPECTION-RESULTS-FA.md` (invariants 11 and 12, two formerly-declared boundaries now struck through as CLOSED). Declared next slices: ITP hold/witness sign-offs on notification stages + who may AUTHOR a nomination + unit-registry pressure validation + (lot,heat) pairing (TODO item **A.4b slice 2 / A.4c**); ITR compiler remains **A.5**.

Implementation record — **A.5 slice 1 (ITR compiler, service-only), 2026-10-28, commit `2a6e2fa`**: migration `app/database/migrations/2026_10_28_120000_add_itr_snapshots.php` (forward-only; `itr_snapshots` append-only RLS+FORCE with trigger-derived dossier/completeness/`sha256` digest — client content is never declarable, scope-proposal envelope precedent; premature-freeze refusal on DRAFT packs; `epcc.itr_actor_id` session contract), `ItrCompilerService` (compile = pure read of the SAME SQL bodies the freeze trigger uses, so preview and snapshot agree byte-for-byte; freeze; snapshot with reader digest re-verification; snapshotsForPack; renderPdf optional-by-host via the existing Chromium/Vazirmatn renderer), bilingual blade `resources/views/print/itr-dossier.blade.php` on the `print.layout` shell. The completeness report enumerates every gap (unreleased lines, unfitted joints, joints without weld/NDT, open rejections cleared only by repair+re-exam lineage, packs without PASS) and the dossier kind is literally `ITR_COMPILATION_NOT_A_CERTIFICATE`. `ItrSnapshotTest` 10 tests / 72 assertions green; `InspectionResultTest`+`JointTest`+`PipingGrnTest`+`StockLedgerTest` regression green (87 tests / 344 assertions). Guides: `docs/ITR-COMPILER-EN.md` / `docs/ITR-COMPILER-FA.md`. Declared slice-2 items (TODO **A.5b**): subsystem/dossier scopes, ITP-notification and receipt/MTR binding (needs A.4b piece-level custody), formal signature/approval layer (interfacing ADR-057), HTTP/UI surface.

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
