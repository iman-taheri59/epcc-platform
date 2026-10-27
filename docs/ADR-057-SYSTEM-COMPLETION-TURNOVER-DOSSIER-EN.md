# ADR-057 — System/subsystem completion and turnover dossier assembly

Status: **PROPOSED — awaiting owner decisions** (2026-10-26). COM (commissioning/handover) scores 0.5: `McCertificateService` and punch items exist; the `Subsystem` model exists but carries no completion process. This ADR is mostly an **assembly** decision — nearly every input already exists elsewhere.

## Context

Handover in EPCC is a document-and-state intensive process: mechanical completion per system/subsystem (punch categorised A/B/C, tests passed, documents as-built), then pre-commissioning/commissioning checklists, then the turnover dossier (ITRs, test packs, material certificates, as-built documents, punch clearance, MC certificate) handed to the owner/operator. The platform holds: subsystems/areas/facilities (models), test packs + joints (ADR-006/048 results once landed), certificates (MC exists), CDE with ISO 19650 states (as-built = a Published revision state), punch items (Filament), ITP notifications + acknowledgments + prerequisites, receipt chain with acceptance, traceability. What is missing is the completion state machine per system and the dossier compiler.

## Decision points (explicit owner choices required)

1. `completion_hierarchy=system_subsystem_two_level | three_level_with_area | flexible_project_defined`
   Recommended: **system_subsystem_two_level** — matches the existing `Subsystem`/`Facility`/`Area` models and industry practice (P&ID systems → sub-systems); area stays a geographic cross-reference.
2. `mechanical_completion_rules=declarative_gates_with_waivers | checklist_manual_only`
   Recommended: **declarative_gates_with_waivers** — MC requires: all package work completed (ADR-055), category-A punch closed (B/C may carry with explicit acceptance), required test packs accepted (ADR-048 results), as-built revisions Published (CDE state), ITP prerequisites closed; each gate resolves automatically where data exists, and any waiver is an authority-routed append-only act (ADR-049 pattern).
3. `dossier_form=compiled_index_with_links | frozen_signed_bundle | both`
   Recommended: **both** — a live compiled index (always current, shows completeness honestly like the ITR compiler) plus frozen digest-bound bundles issued at milestones (MC, handover) using the envelope/snapshot discipline (hash-bound, immutable, bilingual PDF via the existing renderer).
4. `commissioning_scope=mc_and_handover_only | include_precommissioning_checklists | full_commissioning_with_energization`
   Recommended: **include_precommissioning_checklists** — pre-commissioning checklists (cleaning, flushing, tightness beyond hydro) are structured records bound to subsystems; full commissioning (energization, performance tests, reliability runs) is a later ADR once owner/operator personas (ISO 55000 handover in SRS) are engaged.
5. `handover_acceptance=owner_counter_signature_in_system | external_register_only`
   Recommended: **both modes** per the ADR-031 precedent: owner acceptance recorded in-system by an OWN-persona identity, or externally signed and registered with provenance.

## Sketch of the decision

- Tables (RLS + FORCE): `completion_states` per subsystem (state machine under ADR-049 policy: CONSTRUCTION→MC_REQUESTED→MC_CERTIFIED→PRECOM_READY→HANDED_OVER, with gate snapshots at each transition), `punch_links` (punch item ⇄ subsystem/package with category A/B/C and clearance evidence), `dossier_indexes` (live completeness view: required document types per project handover matrix vs present items, explicit gaps — never silent omission), `dossier_bundles` (frozen, digest-bound, certificate-linked).
- MC certificate flow graduates from the current service into the state machine: issuance = transition with gate snapshot + authority chain; the existing `McCertificate` data remains valid history.
- Dossier completeness adopts the house honesty idiom: "this dossier contains X of Y required records; missing items are enumerated" — not a completeness certificate by silence.

## Consequences

- The platform's endpoint becomes visible: every upstream record (documents, materials, welds, tests, punch) finally converges into a sellable deliverable — the turnover dossier. This is also the strongest adoption argument for owner companies.
- Depends on ADR-048 (results) and benefits from ADR-055 (packages); can start with MC gates on existing data (punch + test packs + documents) before those land, with gaps shown honestly.
- Test surface: gate resolver determinism, waiver authority/separation, frozen bundle digest stability, completeness honesty (missing-item enumeration), RLS for owner-side readers.

## Out of scope

Full commissioning management (energization/performance/reliability), O&M handover & asset register (ISO 55000 — future ADR), spare-parts handover, training/documentation for operators, digital-twin/CFIHOS handover dictionaries (SRS mentions ISO 15926/CFIHOS — a later data-export ADR).
