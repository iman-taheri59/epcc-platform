# ADR-058 — External-party portals (VEN / SUB / TPI self-service)

Status: **PROPOSED — awaiting owner decisions** (2026-10-26). The SRS defines nine company types; today only the EPC-internal side and *recipient readers* of notifications have real surfaces. Vendor/subcontractor/TPI self-service is the adoption force-multiplier — and the RLS model was built for exactly this.

## Context

Existing multi-company assets that portals can stand on: company-scoped RLS with FORCE (139 policies), identity hatch + memberships with freshness checks, notification recipient inbox with company acknowledgment (ADR-044/045), custody/MRR/QC/QA person-separation across companies (ADR-046), independent inspection entry (ADR-041), transport with signed links (ADR-050, proposed), device-pinned field identity (ADR-056, proposed). What is missing: bounded, role-scoped entry surfaces where an external party acts on its own data without ever seeing the EPC's internals — plus the governance (invitation, verification, revocation) around external identities.

## Decision points (explicit owner choices required)

1. `portal_topology=single_app_scoped_surfaces | separate_portal_applications | progressive_single_app`
   Recommended: **progressive_single_app** — one application, but external sessions land in a distinct, minimal surface set (their RFQs/offers, their POs, their notification inbox, their packages/daily reports, their deliveries); no navigation to EPC screens at all. Separate apps duplicate the identity/RLS stack for no gain.
2. `external_identity=invited_verified_identities | delegated_company_admin | both_tiered`
   Recommended: **both_tiered** — the EPC/owner invites a company contact (verified email/phone per ADR-050 address discipline); that company's admin can then manage its own members within the granted scope. Every external identity is membership-bound and revocation is immediate (freshness checks already exist).
3. `action_set_phase1=read_and_respond | include_document_upload | include_field_entries`
   Recommended: **include_document_upload** at phase 1 — offers (ADR-052), PO acknowledgment + forecast updates (feeds the existing slip computation), invoice submission (ADR-053), shipping documents, notification acknowledgment (exists). Field entries (daily reports, results) come with ADR-056 device discipline.
4. `treatment_of_witness_tpi=in_system_signoff_identities | external_register_only`
   Recommended: **in_system_signoff_identities** — TPI witnesses are external identities with hold/witness-point sign-off rights (ADR-048 structured sign-offs); their signatures are platform-recorded but never described as platform-issued documents (ADR-031 honesty).
5. `commercial_exposure=own_data_only_strict | own_data_plus_project_publications`
   Recommended: **own_data_only_strict** initially — a vendor sees its own RFQs/offers/POs/deliveries and nothing else; ranked-outcome disclosure (ADR-052 `outcome_disclosure`) and project publications (ITP schedules to attend) are explicit, project-configured relaxations, each an ADR-level decision.

## Sketch of the decision

- No new tenancy machinery: portals are a **surface layer** over existing RLS — every query already filters by company; the work is scoping routes/views/menus to external session types, plus a session-kind claim (`internal` vs `external:company`) enforced in middleware (fail-closed, like BindTenant).
- Invitation/verification flow: invite (authority-routed) → verified contact → membership with role codes scoped to portal actions → device/transport bindings (ADR-050/056) → revocation ledger (append-only).
- Audit: external sessions get the same AuthorizationDenial recording; portal actions appear in the same ledgers as internal ones (no separate truth).

## Consequences

- Vendors update forecasts (slip data quality improves), submit invoices (ADR-053 provenance = vendor-entered), subcontractors file daily reports (ADR-055), TPI signs hold points (ADR-048) — each upstream ADR gains its natural actor.
- Security surface: external identity lifecycle becomes critical (invitation forgery, session fixation, cross-company probing); throttle + demo-hint gating (already merged) extend naturally, but a dedicated abuse test-suite is required.
- Sequencing: portals are a **multiplier, not a foundation** — start phase 1 after ADR-050 transport (invitations need a channel) and expose only flows that already exist server-side.

## Out of scope

Owner/client (OWN) executive portals and dashboards-as-a-service, public tender portals, marketplace/discovery, mobile apps (ADR-056 covers field), billing of platform usage per external seat (commercial/licensing — SRS §7, separate track).
