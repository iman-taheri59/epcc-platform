# Documentation and ADR Audit — EPCC Platform

Date: **2026-10-05**  
Scope: all primary Markdown files under `docs/`, all ADRs, status documents, SRS, architecture, coverage map, RLS spike, infrastructure cost model, and the Iran-first market addendum.

> This is a **documentation audit**, not a full code verification. PHP/Composer/PostgreSQL are not installed in the current sandbox, so implementation claims were checked only against the file structure, `HANDOFF.md`, and the Python/Node tests that can run here.

---

## 1. Executive Summary

The project is well documented: decisions are captured in ADRs, traps and empirical findings are recorded, and `HANDOFF.md` is an effective technical memory. The main issue is that **several parent documents have drifted behind the real project direction**:

1. `Architecture-and-Data-Model.md` still presents TypeScript/NestJS/React/PostgreSQL 18 as the main stack, while the real decision and codebase are Laravel 13 + Filament + PostgreSQL.
2. `SRS-EPCC-Platform.md` still frames SaaS/multi-tenant as a peer deployment model; the Iran-first addendum makes on-prem/air-gapped deployment, Persian/Jalali/IRR the launch constraints.
3. Some ADRs have since been implemented but still contain old wording such as “Nothing is implemented”; the most important example is `ADR-006`.
4. `Prototype-Coverage-Map.md` is likely stale after the Procurement/materials and Piping work.
5. Some SRS requirements are too broad for the MVP and should be split into release phases and maturity levels.

---

## 2. ADR Matrix

| ADR | Main decision/topic | Decision status | Current implementation status | Recommended action |
|---|---|---|---|---|
| ADR-001 AWP Packaging | AWP as a project-level add-on, not a per-company entitlement | Accepted/closed | Constraint engine and pack seam exist; methodology deferred | Keep CI protection; align SRS/licensing wording with project-level scope |
| ADR-002 Defer AWP | Defer AWP methodology to international phase; keep machinery in core | Accepted/closed | `awp` pack and seam validation exist; AWP remains the main planned module | Add an AWP project smoke test in CI if not already present |
| ADR-003 Technology Stack | TypeScript/NestJS/React/PostgreSQL 18 | **Superseded** by Annex A | Real stack is Laravel/Filament | Add a clear “superseded” banner at the top |
| ADR-003 Annex A | Laravel 13 + Filament v4 as the real backend choice | Accepted/closed | Actual app is Laravel | Rewrite Architecture and SRS stack sections around the Annex |
| ADR-004 Physical Breakdown | project → facility → optional area → subsystem | Accepted and built | Migration/model/UI/admin are built according to `HANDOFF` | Add “Accepted and built” status at the top of the ADR |
| ADR-005 Engineering Data Ingestion | Neutral baseline, immutable import batches, preview→commit, PCF/property export | Proposed / ready for product decision | Not built | Collect real sample files before build; keep NWD/NWC/NWF explicitly out of scope |
| ADR-006 Piping Model | Lines/components/ports/joints, numbering, compatibility, dia-inch | Implicitly accepted; **document is stale** | Phases 1/1b/2/3 are built; phase 4+ pending | Replace the old “Nothing is implemented” ending with “Partially built” status |
| ADR-007 Reference Data | Read-only reference packs for sizes/schedules/end types/templates | Accepted and built | Pack validation passes; reference packs exist | Add status metadata at top |
| ADR-008 PMS Rules | DN half-open ranges, exclusion constraint, branch specificity wins | Proposed | Not built | Build only after a full PostgreSQL test environment exists |
| ADR-009 Manual Modelling | Template-driven component creation; PCF import format, Plant 3D conceptual model | Mostly accepted and partly built | Manual modelling/components/ports built; import pending | Split status: modelling built, PCF import pending |
| ADR-010 Equipment/Nozzles/Dia-inch | dia-inch = smaller diameter; nozzle owns port; equipment phase 3b | Proposed / partially realised | dia-inch built; equipment/nozzles not built | Close the three open questions before phase 3b |
| ADR-011 Database per Project | Shared database + RLS, not database-per-project | Recommendation accepted | Current architecture is shared DB/RLS | Cross-reference ADR-012 and the backup/export plan |
| ADR-012 RLS Escape Hatch | RLS policy performance issue and scale-safe predicate shape | Proposed / **security-sensitive** | Unclear whether implemented | Do not implement casually; correctness tests and EXPLAIN guards are mandatory |
| ADR-013 Backup/Export/Delete | Archive by default; ceremonial hard delete; export still proposed | Archive accepted and built | Archive built; export/hard delete not built | Raise export priority for pilot readiness; keep hard delete ceremonial |
| ADR-014 Units | Entered + canonical units, user/project preferences | Accepted and built | Units layer and UI built | Make this shape mandatory for every future physical numeric field |

---

## 3. Non-ADR Document Matrix

| Document | Role | Status | Issue/action |
|---|---|---|---|
| `README.md` | Repository entry map | Good, but should link to new docs | Add links to `ROADMAP-TO-MVP-*.md` and these audit documents |
| `HANDOFF.md` | Technical memory and most authoritative status source | Most reliable current source | Long; add an index/status table near the top |
| `docs/TODO.md` | Done/not-done status | Reconciled | Must be updated with `HANDOFF.md` after each session |
| `docs/ROADMAP-TO-MVP*.md` | MVP phasing | New and aligned with current status | Phase 0 should be made more precise after runtime decision |
| `SRS-EPCC-Platform.md` | Parent requirements document | **Stale in several sections** | Reconcile stack, market, packs, MVP phases, and scope |
| `Architecture-and-Data-Model.md` | Parent architecture document | **Stale in stack and some patterns** | Replace/supersede NestJS/React/PostgreSQL18 sections with Laravel/Filament architecture |
| `Iran-First-Market-Addendum.md` | Iran-first market decision | Valid and should dominate SRS | Good; SRS should absorb this decision to reduce conflicts |
| `Prototype-Coverage-Map.md` | Prototype/app coverage against SRS | Likely stale | Reflect Procurement and Piping work |
| `Spike-RLS-Results.md` | RLS rules and spike findings | Important and still valid | Cross-reference ADR-012; future policy changes should be recorded here |
| `Infra-Cost-Model-Iran.md` | Iranian infrastructure cost model | Useful | Recheck numbers if Laravel stack changed memory footprint assumptions |
| `docs/uml/*` | Technical diagrams | Useful for procurement/materials and ingestion proposal | Needs an index describing which diagrams are current and which are proposed |

---

## 4. Important Conflicts and Stale Areas

### 4.1 The parent architecture stack is still TypeScript

`Architecture-and-Data-Model.md` still says Backend = NestJS on Node 24 and React SPA. The real decision in Annex A and the actual code is Laravel 13 + Filament.

**Recommendation:** Add an ADR supersession note at the top of Architecture or rewrite the stack section. Prefer updating the existing architecture document rather than creating a parallel v2 document that can drift again.

### 4.2 The SRS pack list does not match the Iran-first product

The SRS still speaks about `ISO`, `AWP`, `Hybrid`, and `Custom`. The real product and README use `ips-iran`, `iso`, and `awp`.

**Recommendation:** For launch, make `ips-iran` the default. Move `hybrid/custom` to a later phase or an admin-only import path.

### 4.3 ADR-006 still claims it is not implemented

ADR-006 ends by saying “Nothing is implemented”, but phases 1/1b/2/3 have been built.

**Recommendation:** Add a status block at the top:

```text
Status: Accepted, partially built.
Built: phases 0, 1, 1b, 2, 2b, 3.
Pending: fit-up, supports/spools, PCF import, roll-ups, welding/NDT.
```

### 4.4 Architecture says every module owns schema and forbids cross-module SQL joins

This principle is good for the future, but may be too strict for the current Laravel modular monolith.

**Recommendation:** Change it to “domain services own writes; read models may compose through explicit services/query objects”. A total ban on cross-module SQL is too restrictive for MVP delivery.

### 4.5 The SRS has M/S/C priority but no release phase

Many requirements are marked M, but the Iran-first MVP cannot build all of them.

**Recommendation:** Give every requirement a `Release target`: `P0 Pilot`, `P1 MVP`, `P2 Enterprise`, `P3 International`.

---

## 5. Better Requirement Recommendations

### 5.1 Workflow/BPM

**Current requirement:** visual BPMN workflow designer as M.  
**Better requirement:** Do not build the workflow designer for MVP. First build typed, audited state machines for core objects; then a small workflow definition DSL; move the visual BPMN designer to Enterprise phase.

### 5.2 Cross-company visibility matrix

**Current requirement:** configurable matrix per object type.  
**Better requirement:** For MVP, keep the matrix pack-defined and limited. Allow overrides only for selected object types with approval and audit. A fully free matrix can explode RLS complexity and test scope.

### 5.3 Every read audit

**Current requirement:** log every read of confidential records and every export.  
**Better requirement:** Always log exports/downloads/permission changes and reads of labelled-confidential records. For all reads, use sampling/aggregation or a separate access ledger. Logging every SELECT can destroy performance and storage.

### 5.4 Digital signature

**Current requirement:** PAdES/CAdES and national PKI as M.  
**Better requirement:** For MVP, PDF snapshots + watermarking + approval audit are enough. Build national PKI/signature support only after a pilot customer's legal requirements are clear.

### 5.5 OCR/Search

**Current requirement:** full-text + metadata + EN/FA OCR.  
**Better requirement:** Split into phases: metadata search first, extracted-PDF full-text second, Persian OCR third. Persian OCR has its own quality, cost, and air-gap constraints.

### 5.6 Offline field app

**Current requirement:** offline-capable field data capture.  
**Better requirement:** Before building the app, define the sync contract with a conformance corpus: conflict rules, queue replay, package snapshot, attachment caching, and rejection handling. Offline without this contract becomes a bug factory.

### 5.7 SSO/MFA

**Current requirement:** SAML/OIDC/LDAP/Google/Azure plus MFA/WebAuthn/SMS.  
**Better requirement:** For Iran/on-prem: local auth + LDAP/AD first; TOTP next; SMS fallback only if a domestic provider and non-air-gapped mode are explicitly defined. Google/Azure should not be a primary path under sanctions/air-gap.

### 5.8 BIM/NWD viewer

**Current requirement:** viewer for NWD/RVT/IFC and similar formats.  
**Better requirement:** Do not promise direct NWD upload and parsing. The correct path is property export for data and a separate conversion pipeline for viewing. ADR-005 already states this; the SRS should align.

### 5.9 Standards Pack Editor

**Current requirement:** no-code editor with diff/version/sandbox.  
**Better requirement:** First ship signed/importable packs + validation CLI + fixtures. Build the editor later. An early editor creates a dangerous misconfiguration surface.

### 5.10 Procurement depth

**Current requirement:** broad procurement module.  
**Better requirement:** For Iran-first, deepen procurement around material traceability and warehouse/site readiness rather than complex RFQ flows first. RFQ matters, but field readiness and MTC/heat traceability produce earlier demo value.

### 5.11 Schedule module

**Current requirement:** P6/MSP sync, delay analysis, Monte Carlo.  
**Better requirement:** First build lookahead and constraint readiness tied to package/material/punch status. P6 sync without high-quality WBS mapping and readiness data becomes cosmetic rather than control.

### 5.12 HSE

**Current requirement:** seven HSE areas.  
**Better requirement:** Limit HSE MVP to PTW + Incident + Observation + Toolbox. Add JSA/training/environment after privacy model and approval flow are proven.

---

## 6. Recommended Documentation Fix Order

1. Update `Architecture-and-Data-Model.md` to the Laravel/Filament stack.
2. Align `SRS-EPCC-Platform.md` with Iran-first and release phases.
3. Add status blocks to `ADR-006`, `ADR-009`, and `ADR-010` so built/pending is clear.
4. Recount `Prototype-Coverage-Map.md` with Procurement and Piping.
5. Update `README.md` with links to the new roadmap and audit documents.
6. Define fixed ADR metadata for every ADR:

```text
Status: Proposed | Accepted | Superseded | Built | Partially built | Deferred
Implementation: Not started | Partial | Complete
Supersedes:
Superseded by:
Last reviewed:
```

---

## 7. Conclusion

The project direction is sound: Iran-first, Laravel/Filament, real RLS, standards packs, CDE, commissioning, and piping. The largest current risk is not code; it is **parent-document drift**. If the SRS and Architecture document are not aligned with `HANDOFF.md` and the current code, future decisions may be made against an obsolete reality.

My recommendation: before the next large feature, do a short pass to correct Architecture and SRS; then continue with Phase 0 runtime environment and the smaller PDF/RFSU features.
