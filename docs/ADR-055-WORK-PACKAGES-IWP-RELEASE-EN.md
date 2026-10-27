# ADR-055 — Construction work packages (IWP) and constraint-free release

Status: **PROPOSED — awaiting owner decisions** (2026-10-26). The CON module scores 1 today (punch, MC certificates, joint fit-up). The SRS commits to the AWP taxonomy (CWA/CWP, EWP, PWP, IWP, TWP/SWP); this ADR covers the installation side, the platform's nearest horizon.

## Context

Execution objects already exist at piece level: lines, components, joints with numbering and fit-up (`JointService`, ADR-006), test packs, material readiness signals (accepted MTO rows, GRNs, stock balances once ADR-047 lands), ITP notifications with prerequisites (ADR-045). What is missing is the **organising container** that construction actually runs on: a bounded package of work (scope of joints/components in an area/system), released only when its constraints are cleared — materials on hand, drawings issued-for-construction, prerequisites/permits, crew availability — and then tracked to completion with daily progress.

## Decision points (explicit owner choices required)

1. `package_scope_basis=joint_and_component_sets | schedule_activity_bound | both_linked`
   Recommended: **both_linked** — a work package references a set of platform objects (joints/components/lines) AND binds to schedule activities (ADR-051), so readiness and progress flow on both spines.
2. `constraint_model=declarative_checklist_with_auto_signals | fully_automatic_gate | manual_release_only`
   Recommended: **declarative_checklist_with_auto_signals** — each constraint type has a resolver: material (stock balance from ADR-047 covers the package's MTO), documents (IFC revision exists in CDE), inspection (prerequisites/ITP planned), access/permits (manual, evidenced). Release = all resolvers green or explicitly waived (waiver is an authority-routed, reason-coded append-only act — never silent).
3. `release_authority=construction_management_roles | chained_approval_via_workflow`
   Recommended: **chained_approval_via_workflow** once ADR-049 exists (planner proposes → construction manager releases); interim: single authorized role with person-separation from the proposer.
4. `progress_capture=daily_report_per_package | event_derived_only | both_reconciled`
   Recommended: **both_reconciled** — daily reports (crew, hours, completed joints, blockers) entered by subcontractors (portal per ADR-058 / field capture per ADR-056) reconciled against event-derived progress (fitted/welded joints from ADR-048 results); divergence itself is a managed signal, not a shame metric.
5. `subcontractor_binding=package_to_subcontract_line | packages_only`
   Recommended: **packages_only** initially — packages are assigned to companies (existing multi-company identity); subcontract commercial lines stay out of scope until a construction-contract ADR exists.

## Sketch of the decision

- Tables (RLS + FORCE): `work_packages` (area/system, scope refs, bound activities, state machine per ADR-049 policy: DRAFT→READY_CHECK→RELEASED→IN_PROGRESS→COMPLETED/CLOSED), `work_package_constraints` (type, resolver, status, evidence refs), `work_package_releases` (append-only, authority refs, waivers), `daily_reports` (append-only per package-day; blockers as coded + free text).
- Constraint resolvers are STABLE functions over existing data — a package's readiness is recomputable at any cutoff (house reproducibility idiom).
- Completion feeds ADR-051 progress (installed quantities) and ADR-057 (mechanical completion per system assembles its packages).

## Consequences

- The construction loop closes: plan (schedule) → package → release when constraint-free → capture → progress → completion; field teams get a reason to open the system daily (the adoption engine the platform currently lacks).
- Depends on ADR-047 (stock) and benefits from ADR-048 (results) and ADR-049 (policies) — sequencing matters; do not start before ADR-047 is decided.
- Test surface: resolver determinism, waiver authority + separation, daily-report immutability, reconciliation divergence computation, RLS across EPC/SUB.

## Out of scope

Crew/equipment resource management and calendars, HSE permits-to-work (future HSE ADR), scaffolding/temp-works, quantity surveying, subcontract commercial management, TWP/start-up packages (ADR-057 covers turnover side).
