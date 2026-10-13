# Software Requirements Specification (SRS)
## Industrial EPCC Project Management & Automation Platform
### Oil, Gas, Petrochemical, Power & Heavy Industry — All Disciplines

| Field | Value |
|---|---|
| Document ID | SRS-EPCC-001 |
| Version | 1.0 (Baseline for review) |
| Date | 2026-09-09 |
| Status | Draft for stakeholder approval |
| Product working name | *EPCC Suite* (placeholder) |
| Delivery model | Multi-tenant SaaS + optional single-tenant / on-premise (air-gapped) deployment |
| Languages | English (LTR) and Persian/Farsi (RTL) — full bilingual parity |
| Standards frameworks | ISO-based framework and AWP/WFP framework, **selectable per project** |

---

## 0. How to read this document

Requirements are uniquely identified:

- `FR-<MODULE>-<n>` — Functional requirement
- `NFR-<AREA>-<n>` — Non-functional requirement
- `BR-<n>` — Business/commercial requirement
- `IR-<n>` — Integration requirement
- `CR-<n>` — Compliance requirement

Priority uses MoSCoW: **M** (Must, MVP), **S** (Should, v1.x), **C** (Could, v2), **W** (Won't, out of scope for now).

---

## 1. Executive Summary

### 1.1 Product Vision
A single, modular, bilingual (EN/FA) web platform that plans, executes, controls and automates large industrial **EPCC** (Engineering, Procurement, Construction, Commissioning) projects across **all engineering disciplines**, from FEED through to handover and warranty close-out.

The platform's differentiator is a **switchable standards engine**: at project setup, an organization chooses whether the project is governed by an **ISO-based framework** (ISO 21500/21502, ISO 19650, ISO 55000, ISO 9001, ISO 15926, ISO 10015-style competence control) or by an **AWP/WFP framework** (CII RT-272/RT-319, COAA AWP model, Path of Construction driven), or a **hybrid**. The chosen framework reconfigures workflows, breakdown structures, gate reviews, document coding, KPIs and reports — without a re-implementation.

### 1.2 Business Model
Sold as **per-company subscription plans** (Full and tailored/Enterprise "Special" plans). Every company participating in a project — Owner/Client, EPC Contractor, Consultant/PMC, Subcontractor, Vendor, MC (Management Contractor / مدیریت پیمان), TPI (Third Party Inspection), Certification/Notified Body — holds its **own tenant, its own plan, and its own entitlements**, while collaborating inside shared project workspaces with strictly enforced cross-company visibility rules.

### 1.3 Primary Business Outcomes
| Outcome | Target KPI |
|---|---|
| Reduce engineering rework | −20% rework hours within 2 projects |
| Increase construction Tool Time (AWP) | From ~35% baseline to 50%+ |
| Reduce IWP release without full constraint clearance | <5% of released IWPs |
| Reduce document turnaround (IFR→IFC) | −30% cycle time |
| Punch list closure before RFSU | ≥98% A-items closed |
| Single source of truth | 100% of gate approvals executed in-platform |

---

## 2. Scope

### 2.1 In Scope
- Full EPCC lifecycle: FEED → Detailed Engineering → Procurement → Fabrication → Construction → Pre-commissioning → Commissioning → Start-up/Performance test → Handover → Warranty.
- All disciplines (Section 6.3).
- Multi-company, multi-project, multi-site, multi-currency, multi-calendar (Gregorian + Solar Hijri/Jalali + Lunar Hijri holidays).
- Standards-pack switching (ISO / AWP / Hybrid / Custom).
- Commercial layer: plans, entitlements, metering, billing, reseller/partner model.

### 2.2 Out of Scope (v1)
- Native 3D CAD authoring (platform *consumes* models; it does not replace AVEVA/Autodesk/Hexagon authoring tools).
- Full ERP general ledger and payroll (integration only).
- Detailed stress/process simulation engines (integration only).
- Offline-first native mobile app (v1 provides PWA with cached forms; native app is **S** for v1.2).

### 2.3 Assumptions & Constraints
- Users may be on constrained site connectivity → offline-capable field data capture required.
- Some clients (national oil companies) will require **on-premise / data-residency-in-country** deployment and Persian-first UI.
- Legacy data arrives as Excel/MS Project/Primavera XER/PDF → bulk import tooling is mandatory, not optional.
- Any AI/automation feature must be **explainable and overridable**; no automated approval of safety-critical records.

---

## 3. Stakeholders, Company Types & Personas

### 3.1 Company (Tenant) Types
| Code | Company type | Typical platform use |
|---|---|---|
| OWN | Owner / Client / Employer | Governance, gate approvals, change orders, invoicing approval, final dossier acceptance |
| EPC | EPC / EPCC main contractor | Full execution: engineering, procurement, construction, commissioning |
| PMC | Consultant / PMC / Engineer of record | Review & comment, verification, progress certification |
| SUB | Subcontractor | Scoped work packages, field execution, ITR/QC records |
| VEN | Vendor / Supplier / Fabricator | VDRL/VDR submissions, expediting, FAT, shipping documents |
| MC | Management Contractor (مدیریت پیمان) | Administers the contract for the employer: supervises execution, verifies progress and quality, walkdowns, punch lists, signs off completion certificates |
| TPI | Third Party Inspection | Inspection release notes, NDT witness, hold-point release |
| CB | Certification / Notified Body / Authority | Regulatory certificates, statutory inspections |
| INT | Internal (platform operator) | Support, provisioning, licensing (super-admin, no project data by default) |

### 3.2 Key Personas (abridged)
Project Director, Project Manager, Engineering Manager, Discipline Lead (×12), Document Controller, Planner/Scheduler, Cost Controller, Procurement/Contracts Manager, Expeditor, Materials/Warehouse Manager, Construction Manager, AWP/WFP Coordinator, Superintendent/Foreman, QA/QC Manager, QC Inspector, TPI Inspector, HSE Manager, Commissioning Manager, System Owner, Owner Representative, Client Auditor, Tenant Administrator, Platform Operator.

Each persona has documented: goals, top 5 tasks, decision rights, default role template, and mobile-vs-desktop usage split.

---

## 4. Access Control & Multi-Company Security Model

**FR-SEC-01 (M)** — The platform SHALL implement a **4-dimensional authorization model**:
1. **Tenant** (which company you belong to)
2. **Project/Contract membership** (which projects, and under which contract role)
3. **RBAC role + permission set** (what actions)
4. **ABAC scope filters** (which data: discipline, area/CWA, system, WBS branch, document class, confidentiality label)

**FR-SEC-02 (M)** — Permissions SHALL be expressed as atomic capabilities (`document.review.submit`, `iwp.release`, `punch.close.A`, `cost.actuals.view`, `contract.rate.view`) and grouped into **role templates** per company type; templates are clonable and editable per project.

**FR-SEC-03 (M)** — **Cross-company visibility matrix**: for every object type, the project administrator (Owner by default) configures visibility per company type — `Hidden / Metadata-only / Read / Comment / Review / Approve / Edit / Own`. Default matrix ships per standards pack; overrides are audit-logged with justification.

**FR-SEC-04 (M)** — **Commercial confidentiality firewall**: rates, margins, internal cost, internal float, and internal risk registers of one company SHALL never be visible to another company, including the Owner, unless explicitly published. Ethical/legal wall SHALL be enforceable between competing bidders and between subcontractors.

**FR-SEC-05 (M)** — **Delegation & acting-for**: time-bounded delegation of approval authority with automatic expiry, visible in audit trail as "X on behalf of Y".

**FR-SEC-06 (M)** — **Segregation of duties (SoD)**: the system SHALL block configurable conflicting combinations (e.g. same user raising and approving a change order above a threshold) and log every override.

**FR-SEC-07 (M)** — **Authority matrix / DoA** engine: monetary and non-monetary approval thresholds per company, per contract, per currency, with escalation and auto-reminders.

**FR-SEC-08 (S)** — **Guest / limited-time access** for auditors and authorities: read-only, watermarked, expiring, with per-session export controls.

**FR-SEC-09 (M)** — Every read of a confidential-labelled record and every export SHALL be logged (who, what, when, IP, device, purpose).

### 4.1 Illustrative Default Visibility Matrix (excerpt)
| Object | OWN | EPC | PMC | SUB | VEN | MC | TPI |
|---|---|---|---|---|---|---|---|
| IFC Drawing | Approve | Own | Review | Read (scoped) | Read (scoped) | Read | Read |
| Internal cost forecast | Hidden | Own | Hidden | Hidden | Hidden | Hidden | Hidden |
| Progress measurement | Approve | Own | Verify | Submit | – | – | – |
| ITR / test pack | Read | Own | Review | Submit | – | Verify | Witness/Sign |
| Punch item (A) | Approve close | Own | Verify | Fix | – | Raise/Verify | Raise |
| NCR | Read | Own | Raise/Verify | Respond | Respond | – | Raise |
| Change order | Approve | Raise | Recommend | – | – | – | – |
| MC certificate | Accept | Prepare | Verify | – | – | Issue | Endorse |

---

## 5. Standards Framework Engine (Core Differentiator)

**FR-STD-01 (M)** — At project creation the user SHALL select a **Standards Pack**: `ISO`, `AWP`, `Hybrid (ISO+AWP)`, or `Custom (client-specific)`. Selection is versioned and change-controlled.

**FR-STD-02 (M)** — A Standards Pack is a **declarative configuration bundle** (JSON/YAML, versioned, importable/exportable, digitally signed) that defines:
- Breakdown structures enabled (WBS/CBS/PBS vs CWA/CWP/EWP/PWP/IWP/TWP)
- Lifecycle states and gate criteria
- Document numbering/coding rules and metadata schema
- Mandatory attributes, mandatory records and mandatory approvals
- Review/approval workflow graphs and RACI defaults
- KPI definitions, progress measurement rules and S-curve conventions
- Report and certificate templates
- Terminology dictionary (EN/FA) — e.g. "Deliverable" vs "EWP"
- Compliance checklists and audit evidence requirements

**FR-STD-03 (M)** — Switching or upgrading a pack mid-project SHALL run an **impact analysis** (objects affected, mappings required, data loss risks), require formal approval, and preserve full history of the previous pack.

**FR-STD-04 (M)** — Multiple packs SHALL be able to coexist across projects in one tenant; a company running an ISO project and an AWP project simultaneously must see the correct terminology and workflow in each.

### 5.1 ISO Pack — coverage
| Standard | Platform expression |
|---|---|
| ISO 21500 / 21502 (project management) | Process groups, governance, gate model, PM plan sections as controlled documents |
| ISO 21503/21504/21505 | Programme, portfolio, benefits and governance views |
| ISO 9001 + ISO 10005 (quality plans) | QMS: ITP, NCR, CAPA, audit, management review |
| ISO 19650 (information management / BIM) | CDE with WIP–Shared–Published–Archive states, ISO 19650 naming, TIDP/MIDP, information containers |
| ISO 15926 / CFIHOS | Class library, tag/equipment data model, handover data dictionary |
| ISO 55000 (asset management) | Asset register and handover to O&M |
| ISO 31000 | Risk framework, qualitative + quantitative registers |
| ISO 45001 / 14001 | HSE incident, permit, environmental aspect registers |
| ISO 27001 | Platform security controls (see NFR-SEC) |
| ISO 17020 / 17025 | TPI body and lab competence/records handling |
| ISO 8601 / 4217 / 3166 / 639 | Dates, currency, country, language codes throughout |
| ISO 12006-2, ISO 81346 | Reference designation and classification support |

### 5.2 AWP Pack — coverage
| Concept | Platform expression |
|---|---|
| Path of Construction (PoC) | Interactive PoC builder driving CWP sequencing |
| CWA / CWP | Construction Work Area & Package definition, geo/3D-linked |
| EWP | Engineering Work Package aligned to CWP, deliverable roll-up |
| PWP | Procurement Work Package, material readiness per CWP |
| IWP | Installation Work Package: scope, 8–10 crew-days, constraint-free release |
| TWP / SWP | Turnover & Start-up Work Packages linked to systems/subsystems |
| Constraint management | Engineering, material, equipment, scaffolding, permit, access, labour, safety, prior-work constraints with owner + due date |
| Constraint-free release gate | Hard block on IWP release until 100% (or approved-waiver) clearance |
| Backlog & rolling wave | 90/60/30/6-week lookahead workbenches |
| Tool time / WFP KPIs | PPC, IWP on-time release %, constraint aging, field productivity |
| Model-based scoping | Select 3D objects → auto-generate package scope and quantities |
| AWP maturity assessment | CII/COAA-based self-assessment and scoring dashboard |

**FR-STD-05 (M)** — In the Hybrid pack, ISO document control and AWP execution packaging SHALL coexist, with automatic mapping (e.g. EWP ↔ ISO 19650 information container set; CWP ↔ WBS node).

**FR-STD-06 (S)** — A **Standards Pack Editor** (no-code, admin UI) with validation, diffing, versioning and a sandbox test project.

**FR-STD-07 (S)** — Compliance dashboard: per project, real-time % conformance to the selected pack's mandatory items, with evidence links for external audit.

---

## 6. Functional Requirements by Module

> All modules are independently deployable/enable-able and communicate only through published APIs and domain events (see Section 9). Disabling a module SHALL degrade gracefully, never break the platform.

### 6.1 Module Map
```
CORE            : Identity, Tenancy, Licensing, Config, Standards Engine, i18n, Audit, Notification, Files, Search, Reporting/BI, Workflow/BPM, Integration Bus
PROJECT         : Portfolio, Project Setup, WBS/CBS/OBS/PBS, Calendars, Governance & Gates, Risk, Issue, Action, Lessons Learned, Meetings/MoM
ENGINEERING     : Deliverables Register, Document Control/CDE, Review & Comment, Tag/Asset Register, Data Sheets, Interface Mgmt, Technical Query (TQ), Deviation/Concession, MTO, Model/BIM Viewer, Vendor Data (VDRL)
PROCUREMENT     : Requisitions, RFQ/Bid/Tabulation, PO/Contract, Expediting, Inspection Coordination, Logistics/Shipping, Customs, Material Receiving (MRR), Warehouse & Preservation, Surplus
CONSTRUCTION    : AWP/WFP, CWP/IWP, Constraints, Field Execution & Daily Reports, Progress & Earned Value, Manpower & Equipment, Scaffolding, Subcontract Admin, Site Instructions
QUALITY         : ITP, ITR/Checksheets, NCR/CAPA, Welding & NDT, Certificates & Traceability, Calibration, Audits, TPI/MC workflows
HSE             : Permit to Work, Incident, Observation, Toolbox Talk, Risk Assessment/JSA, Training/Competency, Environmental
COMMISSIONING   : System/Subsystem breakdown, Pre-comm/Comm ITRs, Punch List, MC/RFSU certificates, Turnover Dossier, Performance Test, Handover & As-Built
COMMERCIAL      : Contract Mgmt, Change/Variation, Claims, Progress Invoicing, Cost Control & Forecast, Cash Flow, Budget & Trend, Bonds/Insurance/Guarantees
SCHEDULE        : Master schedule, P6/MSP sync, Lookahead, Baselines, Delay analysis, Schedule risk (Monte Carlo)
ANALYTICS       : Dashboards, KPI library, Alerts, Predictive/AI assist, Executive & Client reporting
ADMIN/SAAS      : Plans, Entitlements, Metering, Billing, Onboarding, Support, Health
```

### 6.2 Core Platform Modules

#### 6.2.1 Identity & Tenancy (CORE-IAM)
- **FR-IAM-01 (M)** SSO: SAML 2.0, OIDC/OAuth2, Azure Entra ID, Google Workspace, LDAP/AD; per-tenant IdP.
- **FR-IAM-02 (M)** MFA (TOTP, WebAuthn/FIDO2, SMS fallback), enforceable per tenant/role.
- **FR-IAM-03 (M)** Tenant isolation with logical (row-level security + tenant key) and optional physical (dedicated DB/schema) isolation for Enterprise plans.
- **FR-IAM-04 (M)** Cross-tenant collaboration: a user is invited into a project by another tenant while keeping home-tenant identity; consent and data-sharing agreement recorded.
- **FR-IAM-05 (M)** Session policy, IP allowlist, device trust, forced logout, password/credential policy per tenant.
- **FR-IAM-06 (S)** SCIM 2.0 user provisioning/deprovisioning.

#### 6.2.2 Internationalization (CORE-I18N)
- **FR-I18N-01 (M)** Full EN/FA parity: every UI string, email, notification, PDF report, error message and system-generated document exists in both languages; no untranslated fallback in production builds (CI check).
- **FR-I18N-02 (M)** True **RTL layout mirroring** (not just text direction): navigation, tables, Gantt charts, S-curves, form flow, icons with direction semantics, PDF layouts.
- **FR-I18N-03 (M)** Per-user language preference, independent of tenant default; switchable at runtime without logout.
- **FR-I18N-04 (M)** **Dual calendar**: Solar Hijri (Jalali) and Gregorian, with conversion, Iranian + international holiday calendars, and Persian/Arabic-Indic digit display option. All storage in UTC/ISO-8601.
- **FR-I18N-05 (M)** Bilingual master data: object titles, discipline names, document types, statuses may carry EN and FA values; reports select per output language.
- **FR-I18N-06 (M)** Bilingual documents: cover sheets, transmittals, certificates and MoM templates renderable side-by-side EN|FA or single-language.
- **FR-I18N-07 (S)** Machine-translation assist for free-text comments with clear "machine translated" labelling; never applied to contractual text without human confirmation.
- **FR-I18N-08 (M)** Persian text handling: correct ی/ك normalization, ZWNJ handling, Persian collation for sorting, Persian-aware full-text search.
- **FR-I18N-09 (C)** Third language readiness (Arabic, Russian, Turkish) via the same resource pipeline.

#### 6.2.3 Workflow / BPM Engine (CORE-WF)
- **FR-WF-01 (M)** Visual workflow designer (BPMN 2.0 subset): states, transitions, parallel/serial approvals, conditions, timers, escalations, delegation.
- **FR-WF-02 (M)** Workflows bound to object types and scoped by project, discipline, company, value threshold.
- **FR-WF-03 (M)** SLA timers with business-calendar awareness (per country/company working week — e.g. Sat–Wed in Iran), breach alerts and reporting.
- **FR-WF-04 (M)** Every workflow instance produces an immutable, exportable approval history.
- **FR-WF-05 (S)** Rule engine for automation: "when X then Y" (auto-assign, auto-notify, auto-create, auto-escalate) with dry-run simulation.

#### 6.2.4 Files, CDE & Search (CORE-DOC)
- **FR-DOC-01 (M)** Versioned object storage, deduplication, virus scanning, checksum integrity, up to ≥5 GB per file (models, point clouds).
- **FR-DOC-02 (M)** In-browser viewer with markup for PDF, Office, DWG/DXF, IFC/RVT/NWD (via conversion), images, video; no local install required.
- **FR-DOC-03 (M)** Federated **search**: full-text + metadata + OCR (EN/FA) across all modules, permission-trimmed, with saved searches.
- **FR-DOC-04 (M)** Digital signature: PAdES/CAdES; support for client-side certificates and (where required) national PKI; signature validation and long-term validation (LTV).
- **FR-DOC-05 (M)** Watermarking, controlled print, download restriction, and "view-only" secure rendering per confidentiality label.

#### 6.2.5 Audit, Records & Legal Hold (CORE-AUD)
- **FR-AUD-01 (M)** Append-only audit log of all create/update/delete/approve/export/permission-change events, tamper-evident (hash chain).
- **FR-AUD-02 (M)** Full point-in-time reconstruction of any record ("what did the drawing register look like on 2025-11-03?").
- **FR-AUD-03 (M)** Retention policies, legal hold, and defensible deletion per tenant/contract.
- **FR-AUD-04 (S)** Audit export packs for external auditors (ISO 9001, client audits) in one click.

### 6.3 Disciplines Covered (Engineering & Construction)
All discipline-aware modules SHALL support at minimum:

| # | Discipline | Notable discipline-specific features |
|---|---|---|
| 1 | Process / Technology | PFD, P&ID register, line list, equipment list, HAZOP action tracking, mass balance attachments |
| 2 | Mechanical — Static (vessels, exchangers, tanks) | Equipment data sheets, U-stamp/ASME records, MDR tracking |
| 3 | Mechanical — Rotating | Vibration/alignment records, FAT/SAT, run-in tests |
| 4 | Piping | Line list, isometrics, spool tracking, welds, hydrotest packs, stress package register |
| 5 | Civil, Structural & Architectural | Concrete pour cards, rebar, steel erection, surveys, geotech |
| 6 | Electrical | SLD, cable schedule/pulling, loop lists, MCC/switchgear, earthing, energization plan |
| 7 | Instrumentation & Control (I&C) | Instrument index, loop folders, calibration, junction boxes, hook-ups |
| 8 | Automation / DCS / SIS / SCADA | Cause & effect, SIL loops, FAT/SIT/SAT, software version control |
| 9 | Telecom / Security / F&G | Network diagrams, detector layouts, CCTV/PAGA testing |
| 10 | HVAC & Utilities | Duct schedules, balancing reports |
| 11 | Fire Protection & Safety Engineering | Fireproofing, deluge testing, safety-critical element register |
| 12 | Pipelines & Offshore/Onshore Facilities | Route/KP referencing, welding logbooks, pigging, hydro/gauging, offshore hook-up |
| 13 | Painting, Coating & Insulation | Surface prep, DFT records, insulation MTO |
| 14 | Corrosion / Materials / Welding Engineering | WPS/PQR/WPQ, material traceability, heat numbers |
| 15 | Environmental & HSE Engineering | Permits, environmental monitoring |
| 16 | Plant Layout / Piping Design / 3D Model | Model review sessions, clash management |
| 17 | Project Services (Planning, Cost, Doc Control, Risk) | Cross-cutting |
| 18 | Commissioning & Start-up | Systems, subsystems, RFSU |

**FR-DIS-01 (M)** Discipline set is configurable per project; custom disciplines can be added with their own codes, deliverable types, and progress rules.
**FR-DIS-02 (M)** Every object carries a discipline attribute enabling discipline-scoped dashboards, permissions and progress roll-up.

### 6.4 Project & Portfolio Module (PROJECT)
- **FR-PRJ-01 (M)** Project setup wizard: contract type (LSTK/EPC, EPCM, Reimbursable, Unit-rate, PC), standards pack, disciplines, currencies, calendars, breakdown structures, participating companies, gate model.
- **FR-PRJ-02 (M)** Multi-level breakdowns: WBS, CBS, OBS, PBS, SBS (system), CWA/CWP (AWP), Area/Unit/Plant, with cross-mapping.
- **FR-PRJ-03 (M)** Project templates & cloning, including workflow, register structures and report set.
- **FR-PRJ-04 (M)** Governance gates (FEL/Stage-gate): entry/exit criteria checklists, evidence attachment, gate approval by Owner.
- **FR-PRJ-05 (M)** Risk register (ISO 31000): qualitative matrix (configurable 3×3…6×6), quantitative cost/schedule impact, response plans, owners, risk review meetings, heat maps, top-10 trending.
- **FR-PRJ-06 (M)** Issue, Action and Decision logs with source traceability (meeting, audit, gate, NCR).
- **FR-PRJ-07 (M)** Meeting management: agenda, attendance, minutes with auto-generated actions, bilingual MoM PDF, e-signature.
- **FR-PRJ-08 (S)** Lessons Learned knowledge base, searchable across projects, taggable by discipline/phase/cause.
- **FR-PRJ-09 (M)** Interface Management: internal/external interface register, interface agreements, responsible parties, due dates, status board.
- **FR-PRJ-10 (S)** Portfolio dashboard: multi-project health, resource contention, cross-project KPI benchmarking.

### 6.5 Engineering & Document Control (ENG)
- **FR-ENG-01 (M)** **Deliverable Register (MDR/DDR)** per discipline: document number (rule-driven), title EN/FA, type, revision, planned/forecast/actual dates for IFR/IFA/IFD/IFC/AB, weightage, % complete, responsible, reviewer, status.
- **FR-ENG-02 (M)** Configurable **document numbering engine** (segment-based: project–plant–area–discipline–type–sequence–revision) with validation, reservation and bulk generation; supports client-mandated schemes and ISO 19650 naming.
- **FR-ENG-03 (M)** **CDE states** (ISO 19650): WIP → Shared → Published → Archived, with suitability codes (S0–S7, A1…, B1…).
- **FR-ENG-04 (M)** Revision control: major/minor, revision matrix, superseding, "latest approved" always resolvable via permanent link/QR.
- **FR-ENG-05 (M)** **Transmittal management**: outgoing/incoming, multi-recipient, acknowledgement tracking, overdue reminders, bilingual transmittal sheet, bulk ZIP export with manifest.
- **FR-ENG-06 (M)** **Review & Comment (comment sheet)**: parallel multi-reviewer, comment categories (Hold/Code 1-4/Info), consolidated comment sheet, comment-by-comment disposition (Accepted/Rejected/Noted) with justification, comment closure statistics, re-submission cycle tracking.
- **FR-ENG-07 (M)** Squad-check and inter-discipline check (IDC) workflow with sign-off per discipline.
- **FR-ENG-08 (M)** **Technical Query (TQ)/RFI** register: raise, route, respond, SLA, impact flag (cost/schedule) auto-linking to change management.
- **FR-ENG-09 (M)** **Deviation / Concession / Waiver** requests with technical authority approval chain.
- **FR-ENG-10 (M)** **Tag & Asset Register** (equipment, lines, instruments, valves, cables, loops): unique tag, class, attributes per class library (CFIHOS/ISO 15926-aligned), parent-child, location/area/system, linked documents, linked purchase order, linked ITRs, lifecycle status. Bulk import/validation.
- **FR-ENG-11 (M)** **MTO / BOM management**: MTO by discipline, revision comparison (MTO rev A vs rev B delta), roll-up to requisitions, shortage analysis vs PO and warehouse.
- **FR-ENG-12 (M)** **Vendor Document Register (VDRL/SDRL)**: per PO, required documents, due dates, submission/review cycles, criticality, hold on payment milestone if overdue.
- **FR-ENG-13 (S)** **3D model & BIM viewer**: IFC/RVT/NWD/E3D-export ingestion, federated model, object → tag → document → package linking, clash register, model-based progress visualization (colour by status), 4D playback against schedule.
- **FR-ENG-14 (S)** Drawing-to-tag intelligent extraction (P&ID OCR assist) with human verification.
- **FR-ENG-15 (M)** Engineering progress measurement: rule-of-credit per deliverable type and revision milestone; discipline-weighted roll-up to project.

### 6.6 Procurement, Materials & Logistics (PRC)
- **FR-PRC-01 (M)** Purchase/Material Requisition (MR) creation from MTO or ad hoc; technical + commercial bid packages.
- **FR-PRC-02 (M)** RFQ issue to vendor list, sealed-bid handling, bid opening controls, **technical bid evaluation (TBE)** and **commercial bid tabulation (CBT)** with scoring, clarification rounds, recommendation-to-award and Owner approval.
- **FR-PRC-03 (M)** Vendor master, AVL/approved vendor list, prequalification, performance rating, blacklist, conflict-of-interest declaration.
- **FR-PRC-04 (M)** PO / Subcontract issuance, amendments, milestones, payment terms, bonds/warranties, LDs.
- **FR-PRC-05 (M)** **Expediting**: per-PO expediting plan, milestone tracking (drawing approval, material procurement, manufacturing, testing, packing, dispatch), expediting reports, criticality ranking, visit reports.
- **FR-PRC-06 (M)** **Inspection coordination**: ITP linkage, inspection notification (IRN), TPI assignment, hold/witness/review points, inspection release note (IRN/RN), NCR from inspection.
- **FR-PRC-07 (M)** **Logistics**: packing list, shipping documents, incoterms, container/parcel tracking, marine/land transport, customs clearance status, insurance, dangerous goods.
- **FR-PRC-08 (M)** **Site materials**: MRR/GRN receiving with inspection, over/short/damage (OS&D) reports, heat/batch traceability, barcode/QR/RFID tagging, bin/yard location, preservation schedules and records, issue/return to field against IWP, reservation for IWP, surplus and reconciliation.
- **FR-PRC-09 (M)** **Material availability engine** for AWP: for any CWP/IWP, real-time answer to "is all material on site, reserved and preserved?" with shortage list and ETA.
- **FR-PRC-10 (S)** Free-issue/client-supplied material tracking, and buy-back/consignment handling.

### 6.7 Construction, AWP/WFP & Field Execution (CON)
- **FR-CON-01 (M)** CWA/CWP/EWP/PWP/IWP hierarchy with automatic alignment checks (an IWP must belong to a CWP; an EWP must serve ≥1 CWP).
- **FR-CON-02 (M)** **Path of Construction** builder with drag-and-drop sequencing, dependency, and schedule sync.
- **FR-CON-03 (M)** **IWP builder**: scope description, drawings, isometrics/spools, tag list, MTO/BOM, man-hours, crew, duration (target 8–10 crew-days), safety requirements, QC ITRs, tools & equipment, work-front photos.
- **FR-CON-04 (M)** **Constraint engine**: constraint types, owner, promised date, status, aging; automated checks against engineering (IFC available), material (reserved/on-site), permits, access, scaffolding, prior IWP completion; **release gate** blocking non-conforming IWPs; waiver with approval.
- **FR-CON-05 (M)** Lookahead workbenches (90/60/30/6 week) with constraint burn-down and readiness heat map.
- **FR-CON-06 (M)** IWP lifecycle: Planned → Ready-to-issue → Issued → In-progress → Complete → Closed (with QC records attached), including field return of the package.
- **FR-CON-07 (M)** **Daily Progress Report (DPR)**: manpower by trade/subcontractor, equipment, weather, work performed against IWP, quantities installed, delays/disruptions, HSE stats, photos, digital signatures; auto-consolidated to weekly/monthly.
- **FR-CON-08 (M)** **Quantity-based progress**: earned quantities per commodity (m³ concrete, ton steel, m/dia-inch weld, m cable, no. of instruments), rules of credit per activity step, automatic EV calculation.
- **FR-CON-09 (M)** **Mobile/offline field app (PWA)**: download IWP + forms, capture progress, ITRs, punch, photos, signatures offline; conflict-safe sync; works on low-end Android tablets; Persian UI.
- **FR-CON-10 (M)** Site instructions, field change requests, and as-built redline capture.
- **FR-CON-11 (S)** Scaffolding register (request, erect, inspect, dismantle, cost by scaffold-day), and lifting plan register.
- **FR-CON-12 (S)** Subcontractor administration: scope, back-to-back milestones, measurement sheets, subcontractor invoices, performance scorecard.
- **FR-CON-13 (S)** Workface productivity analytics: tool time sampling, PPC (Percent Plan Complete), crew performance factor, benchmark vs norms.
- **FR-CON-14 (C)** Geolocation/GIS view of CWAs, live work fronts, and equipment; drone/photogrammetry progress overlay.

### 6.8 Quality Management (QA/QC)
- **FR-QA-01 (M)** **ITP** builder per discipline/activity with hold (H), witness (W), review (R), monitor (M) points and party assignment (Contractor/Consultant/Client/TPI/Vendor).
- **FR-QA-02 (M)** **ITR / checksheet** library (A/B/C types), digital forms, dynamic fields, attachment of evidence, multi-party signature, offline capture.
- **FR-QA-03 (M)** Inspection request (IR/RFI-Q) issuance with notice period, party acceptance, result recording, and automatic ITR closure.
- **FR-QA-04 (M)** **NCR / CAR / PAR** workflow: raise → categorize (severity, cost of poor quality) → root cause (5-Why/Ishikawa) → disposition (rework/repair/use-as-is/reject) → corrective action → verification → close; escalation and repeat-NCR trend.
- **FR-QA-05 (M)** **Welding management**: welder register with qualification validity, WPS/PQR library, weld map per isometric, weld log (joint, welder, date, WPS), NDT request/result (RT/UT/PT/MT/PAUT), repair rate calculation per welder with auto-suspension threshold, traceability to heat numbers.
- **FR-QA-06 (M)** **Test pack / hydrotest management**: pack build from line list, punch, test execution record, reinstatement, flange management.
- **FR-QA-07 (M)** Calibration register for test/measurement equipment with validity alerts.
- **FR-QA-08 (M)** **Certificate & traceability vault**: MTC/EN 10204 3.1/3.2, heat number to tag traceability, coating and NDT certificates, expiry monitoring.
- **FR-QA-09 (M)** Internal/external audit module: plan, checklist, findings, CAPA, follow-up, ISO 9001 evidence pack.
- **FR-QA-10 (M)** **TPI workspace**: TPI-scoped view of assigned inspections, offline record capture, independent signature that the contractor cannot alter, TPI report register, disputed-finding escalation path.
- **FR-QA-11 (M)** Quality KPIs: first-time-right %, NCR aging, repair rate, ITR completion vs plan, inspection-on-time %.

### 6.9 HSE (HSE)
- **FR-HSE-01 (M)** Permit to Work: PTW types (hot work, confined space, excavation, working at height, energized work, lifting), risk assessment attachment, gas test, isolation/LOTO register, approval chain, validity window, daily revalidation, cross-permit conflict detection in same area.
- **FR-HSE-02 (M)** Incident management: near-miss, first aid, MTC, LTI, environmental, property damage; investigation, root cause, corrective actions, statutory reporting; leading/lagging indicators (TRIR, LTIFR, man-hours without LTI).
- **FR-HSE-03 (M)** Observation cards / STOP cards, safety walks, unsafe act/condition tracking.
- **FR-HSE-04 (M)** JSA/JHA, toolbox talks with attendance capture (QR/biometric-optional), and training/competency matrix with expiry alerts and gate on IWP assignment.
- **FR-HSE-05 (S)** Environmental monitoring register (emissions, waste, spills), ISO 14001 aspects/impacts.
- **FR-HSE-06 (S)** Emergency drill register and site access/induction management.

### 6.10 Commissioning, Completion & Handover (COM)
- **FR-COM-01 (M)** **System / Subsystem breakdown (SBS)** with tag allocation; auto-completeness check that every tag belongs to a subsystem.
- **FR-COM-02 (M)** Mapping matrix: CWP/IWP → subsystem, showing construction-to-commissioning readiness ("system completion progress").
- **FR-COM-03 (M)** Pre-commissioning and commissioning ITR (A/B/C) assignment per tag/subsystem, with progress by subsystem.
- **FR-COM-04 (M)** **Punch list**: category A/B/C (or client scheme), raise from walkdown (mobile, photo, 3D/P&ID location pin), assign, cost/impact flag, verify, close; A-item blocking rules for MC/RFSU.
- **FR-COM-05 (M)** Certificate engine: Mechanical Completion Certificate (MCC), Ready for Start-Up (RFSU), Provisional/Taking-Over (PAC), Final Acceptance (FAC), with configurable prerequisites, bilingual templates and multi-party e-signature (EPC + MC + TPI + Owner).
- **FR-COM-06 (M)** **Turnover dossier / Manufacturing Record Book (MRB)** auto-compilation: rules-based collection of drawings, certificates, ITRs, punch status, spares, manuals into an indexed, hyperlinked PDF/A + structured data export.
- **FR-COM-07 (M)** Performance/guarantee test tracking and acceptance criteria verification.
- **FR-COM-08 (M)** As-built management, red-line consolidation, final documentation transmittal to Owner.
- **FR-COM-09 (S)** Handover to O&M: asset register export (CFIHOS/ISO 55000-aligned), spare parts list, preventive maintenance seed data, warranty register and defect liability tracking.

### 6.11 Schedule & Planning (SCH)
- **FR-SCH-01 (M)** Native schedule module: activities, WBS, logic, calendars, resources, baselines, critical path, Gantt (RTL-aware).
- **FR-SCH-02 (M)** **Bi-directional Primavera P6 integration** (XER/XML/P6 EPPM API) and MS Project (MPP/XML) import/export with field mapping and conflict resolution.
- **FR-SCH-03 (M)** Multi-baseline comparison, variance analysis, progress override rules, and schedule health check (DCMA 14-point).
- **FR-SCH-04 (M)** Level 1–5 schedule views; L5 auto-derived from IWPs.
- **FR-SCH-05 (S)** Monte Carlo schedule/cost risk simulation with tornado and P50/P80 outputs.
- **FR-SCH-06 (S)** Delay analysis support: as-planned vs as-built, time impact analysis evidence pack for claims.
- **FR-SCH-07 (M)** Resource histograms and S-curves (planned/earned/actual) exportable to Excel and PDF, bilingual axis labels.

### 6.12 Cost, Contract & Commercial (COM$)
- **FR-CST-01 (M)** CBS and budget management, control accounts, commitments, actuals, accruals, contingency and management reserve.
- **FR-CST-02 (M)** **Earned Value Management**: BCWS/BCWP/ACWP, CPI/SPI, EAC/ETC (multiple formulas), VOWD, to-complete performance index; per WBS/CWP/discipline.
- **FR-CST-03 (M)** Trend register and forecast at completion with scenario comparison.
- **FR-CST-04 (M)** **Change management**: change origination (TQ, NCR, site instruction, client request), estimate, schedule impact, approval per DoA, conversion to Variation Order/Change Order, contract value roll-up; disputed-change register.
- **FR-CST-05 (M)** **Claims module**: notice register with contractual deadline alarms (e.g. FIDIC 20.1-style clocks), records assembly, quantum and extension-of-time build-up.
- **FR-CST-06 (M)** **Progress invoicing / IPC**: measurement from verified progress, retention, advance recovery, price adjustment/escalation formula, deductions, taxes (VAT), multi-currency, approval chain across EPC → PMC → Owner, payment tracking and aging.
- **FR-CST-07 (M)** Contract register: parties, key dates, obligations & obligation-tracking calendar, LDs, bonds and insurance expiry alerts, correspondence linkage.
- **FR-CST-08 (M)** Multi-currency with per-project FX policy (fixed rate, rate at invoice date, hedged) and revaluation reporting.
- **FR-CST-09 (S)** Cash flow forecasting (project and portfolio), funding requests.
- **FR-CST-10 (M)** Formal correspondence register (letters in/out) with reference numbering, obligations extraction, and reply-deadline tracking — bilingual letter templates.

### 6.13 Analytics, Reporting & AI Assist (BI)
- **FR-BI-01 (M)** Role-based dashboards (Executive, PM, Discipline Lead, Construction, QA/QC, HSE, Owner, TPI) with drill-down to source records.
- **FR-BI-02 (M)** KPI library aligned to the active standards pack; each KPI has definition, formula, owner, target, threshold colours — bilingual.
- **FR-BI-03 (M)** Report builder: user-defined tabular/chart reports, saved, scheduled, distributed by email, exportable to PDF/Excel/CSV, bilingual output.
- **FR-BI-04 (M)** Standard report set out-of-the-box: Daily, Weekly, Monthly Progress Report (MPR) with automatic narrative sections, look-ahead, manpower, procurement status, QA/QC, HSE, punch, cost, risk — client-brandable.
- **FR-BI-05 (M)** Alerting engine: threshold/event-based alerts by email, in-app, and optionally SMS/messaging.
- **FR-BI-06 (S)** Analytics warehouse + read-only SQL/ODBC/Power BI connector per tenant.
- **FR-BI-07 (S)** **AI assist (explainable, opt-in per tenant)**:
  - Document/comment summarization and bilingual drafting assistance
  - Constraint-risk prediction for IWP release
  - Schedule slippage early warning from trend patterns
  - Duplicate/similar NCR and lesson-learned retrieval
  - Natural-language query over project data ("show open A punch items in Unit 200")
  - Automatic extraction from vendor documents/certificates (with human-in-the-loop verification)
- **FR-BI-08 (M)** AI governance: per-tenant enable/disable, no cross-tenant training on customer data by default, model/version disclosure, confidence display, mandatory human approval for any contractual/safety-relevant output, full logging of AI-assisted actions.

---

## 7. Commercial / Licensing Requirements (SaaS)

**BR-01 (M)** Plans are defined per **company (tenant)**, not per project. A project can contain companies on different plans; each company's feature availability follows its own plan.

**BR-02 (M)** Baseline plan tiers (final naming/pricing TBD by commercial team):

| Capability axis | Starter | Professional | **Full** | **Special / Enterprise** |
|---|---|---|---|---|
| Named users | ≤25 | ≤150 | Unlimited (fair use) | Custom |
| Active projects | 1 | 5 | Unlimited | Custom |
| Modules | Core + Doc Control + basic Field | + Procurement, QA/QC, HSE, Schedule | All modules incl. AWP, Commissioning, Cost/Claims, BI | All + bespoke modules |
| Standards packs | ISO only | ISO + AWP | ISO + AWP + Hybrid + Custom editor | + client-specific pack authored by us |
| Storage | 250 GB | 2 TB | 10 TB | Custom |
| Integrations | CSV/Excel | + P6, Outlook/Teams | + ERP/SAP, BIM, IoT, full API | + bespoke connectors, on-prem bridge |
| AI assist | – | Limited | Full | Full + private model option |
| Support | Business hours, email | 8×5 + portal | 24×7, 4h P1 SLA | Dedicated CSM, named engineer, custom SLA |
| Deployment | Shared SaaS | Shared SaaS | Shared SaaS / dedicated DB | Single-tenant, on-prem, air-gapped, in-country |
| Branding | Platform | Logo | White-label portal | Full white-label + custom domain |
| Data residency | Region default | Region choice | Region choice | Country-specific / customer datacentre |

**BR-03 (M)** **Entitlement engine**: every feature, module, module-level action and quota is gated by a signed entitlement token evaluated server-side (never trusted client-side). Blocked features show an upgrade path, not an error.

**BR-04 (M)** **Metering & usage**: active users, storage, API calls, documents, projects, AI tokens, model conversions — visible to the tenant admin in real time, with soft/hard limits and overage policy.

**BR-05 (M)** Add-on modules purchasable independently (e.g. AWP pack, Commissioning pack, BIM viewer, Claims module, extra storage, extra environments).

**BR-06 (M)** Trial/sandbox tenant with sample project data (bilingual), auto-expiring, convertible to paid without data loss.

**BR-07 (M)** Billing: subscription (annual/multi-year), invoicing in multiple currencies (incl. IRR/local invoicing requirements), proration, upgrades/downgrades, purchase-order-based enterprise billing, tax handling.

**BR-08 (S)** Partner/reseller & implementation-partner model with margin, sub-tenant provisioning, and partner-scoped support tools.

**BR-09 (M)** **Contract-scoped licensing option**: an Owner may purchase a project-wide licence that covers all participating companies for that project's duration ("project licence"), with the Owner as billing party.

**BR-10 (M)** Licence enforcement must never block: safety-critical records (incident reporting), legally required audit access, or data export of the customer's own data (anti-lock-in guarantee).

**BR-11 (M)** **Data ownership & exit**: customers own their data; full export (structured + files + audit log) on demand and at termination, in open formats, within a contractual SLA.

**BR-12 (S)** In-product self-service: plan comparison, upgrade checkout, seat management, invoice history, licence certificate download.

---

## 8. Non-Functional Requirements

### 8.1 Performance & Scale
- **NFR-PERF-01 (M)** P95 page interaction < 2 s; P95 API read < 500 ms; search results < 3 s on 10M-document corpus.
- **NFR-PERF-02 (M)** Support per large project: ≥500k documents, ≥2M tags, ≥200k IWPs/ITRs, ≥5M audit events/month.
- **NFR-PERF-03 (M)** ≥5,000 concurrent users platform-wide, ≥1,000 concurrent on a single mega-project; horizontally scalable.
- **NFR-PERF-04 (M)** Bulk import of 100k rows with validation report in < 10 minutes, non-blocking (async job with progress).
- **NFR-PERF-05 (M)** Usable over 3G/512 kbps site links; page weight budget and progressive loading; mobile forms usable offline.

### 8.2 Availability & Continuity
- **NFR-AVL-01 (M)** 99.9% monthly uptime for Full plan; 99.95% option for Enterprise; published status page.
- **NFR-AVL-02 (M)** RPO ≤ 15 min, RTO ≤ 4 h; documented and tested DR (at least annually) with customer-visible test evidence.
- **NFR-AVL-03 (M)** Zero-downtime deployments; backward-compatible API versioning (N-2 support).
- **NFR-AVL-04 (M)** Backups encrypted, geo-redundant, restorable per tenant (point-in-time within 35 days).

### 8.3 Security
- **NFR-SEC-01 (M)** ISO 27001-aligned ISMS; SOC 2 Type II roadmap; annual third-party penetration test with remediation SLA.
- **NFR-SEC-02 (M)** Encryption in transit (TLS 1.3) and at rest (AES-256); per-tenant key separation; optional customer-managed keys (BYOK) for Enterprise.
- **NFR-SEC-03 (M)** OWASP ASVS L2 minimum; secure SDLC with SAST/DAST/dependency and container scanning in CI; no critical/high vulnerabilities in release.
- **NFR-SEC-04 (M)** Least privilege, secrets management, no production data in non-production environments (masked/synthetic only).
- **NFR-SEC-05 (M)** Anti-exfiltration controls: export throttling, bulk-download alerting, watermarking, DLP hooks.
- **NFR-SEC-06 (M)** Full audit of administrative and privileged actions, immutable and exportable to customer SIEM (syslog/CEF/webhook).

### 8.4 Compliance & Legal
- **CR-01 (M)** GDPR compliance (EU operation): lawful basis, DSR handling, DPA, sub-processor register, EU data residency option.
- **CR-02 (M)** Configurable data residency (EU, Middle East, customer country) and on-prem deployment for sovereignty requirements.
- **CR-03 (M)** Electronic signature validity: eIDAS-compatible AES/QES support; local PKI integration where mandated; signature evidence retained with the record.
- **CR-04 (M)** Records retention aligned to contract (typically project duration + 10 years) with legal hold.
- **CR-05 (M)** Export-control/sanctions screening awareness in deployment and vendor master (configurable screening lists) — flagging only, decisions remain with the customer.
- **CR-06 (S)** Accessibility WCAG 2.1 AA, including RTL and screen-reader support in both languages.

### 8.5 Usability
- **NFR-UX-01 (M)** Consistent design system, bilingual typography (Persian web fonts with correct rendering), dark/light themes.
- **NFR-UX-02 (M)** Any frequent task ≤3 clicks from role home; global command palette and search.
- **NFR-UX-03 (M)** New field user can complete a progress + ITR entry on mobile with ≤15 minutes of training.
- **NFR-UX-04 (M)** Bulk-edit grids with Excel-like paste, inline validation, and undo.
- **NFR-UX-05 (M)** Contextual help, bilingual in-app guides, and sample templates per module.

### 8.6 Maintainability & Modularity
- **NFR-MOD-01 (M)** Strict module boundaries: each module owns its schema; no cross-module direct DB access; communication via API + events only.
- **NFR-MOD-02 (M)** Module registry: modules declare capabilities, permissions, events, settings, UI entry points, and licence requirements via a manifest.
- **NFR-MOD-03 (M)** A module can be enabled/disabled per tenant and per project at runtime; disabled modules impose no runtime cost.
- **NFR-MOD-04 (M)** Third-party/custom modules installable via SDK without forking the core (Enterprise plan).
- **NFR-MOD-05 (M)** ≥80% automated test coverage on domain logic; contract tests between modules; full E2E suite for critical journeys in both languages.
- **NFR-MOD-06 (M)** Feature flags for progressive rollout; every release reversible.

### 8.7 Observability
- **NFR-OBS-01 (M)** Centralized structured logging, distributed tracing, metrics, per-tenant performance visibility, error budget alerting.
- **NFR-OBS-02 (S)** Tenant-visible health/usage dashboard and job monitor (imports, conversions, reports).

---

## 9. Architecture Requirements

**AR-01 (M)** **Modular architecture**: modular monolith with strict bounded contexts *or* service-per-context — either is acceptable if NFR-MOD-01..04 hold. Recommended: modular monolith at launch, extracting high-load contexts (files/conversion, search, analytics, notifications) as services.

**AR-02 (M)** **Domain events / message bus** (e.g. Kafka/RabbitMQ) with an outbox pattern; published event catalogue and versioning (`document.approved.v1`, `iwp.released.v1`, `punch.raised.v1`).

**AR-03 (M)** **API-first**: every UI capability available via authenticated REST (OpenAPI 3.1) and optional GraphQL read API; webhooks for outbound events; idempotency keys; rate limiting per plan.

**AR-04 (M)** **Multi-tenancy**: tenant context enforced at the data-access layer (row-level security), with a test suite proving cross-tenant isolation.

**AR-05 (M)** **Configuration over code**: standards packs, workflows, numbering rules, forms, report templates, KPI definitions, permission templates are data — not code — and are versioned, exportable and importable.

**AR-06 (M)** **Form/Template engine**: dynamic form builder (used for ITRs, checksheets, DPR, inspection forms, surveys) with conditional logic, calculations, attachments, signatures, offline support, and bilingual labels.

**AR-07 (M)** Deployment via containers + IaC; supports cloud (multi-region), private cloud, and air-gapped on-prem with an offline update channel.

**AR-08 (M)** Reference technology direction (indicative, to be confirmed at design phase): TypeScript/React (RTL-capable UI kit) frontend; .NET or Java/Kotlin or Node/NestJS backend; PostgreSQL primary store; Redis cache; Elasticsearch/OpenSearch for search; S3-compatible object storage; ClickHouse/columnar store for analytics; Keycloak/Entra for identity.

**AR-09 (M)** Environment set per Enterprise customer: Production, Staging/UAT, Sandbox/Training.

---

## 10. Integration Requirements

| ID | System / Standard | Direction | Priority |
|---|---|---|---|
| IR-01 | Primavera P6 (EPPM API, XER/XML) | Bi-directional | M |
| IR-02 | Microsoft Project (XML/MPP) | Bi-directional | M |
| IR-03 | Excel / CSV bulk import-export everywhere | Bi-directional | M |
| IR-04 | SAP / Oracle ERP / MS Dynamics (PO, invoice, GL, material master) | Bi-directional | S |
| IR-05 | AVEVA E3D/PDMS, Hexagon SmartPlant/S3D, Autodesk Plant 3D/Revit/Navisworks (model + tag data) | Inbound | S |
| IR-06 | IFC 4.x / BCF (BIM Collaboration Format) | Bi-directional | S |
| IR-07 | ISO 15926 / CFIHOS data handover | Outbound | S |
| IR-08 | SharePoint / OneDrive / Google Drive / Aconex / ProjectWise | Bi-directional | S |
| IR-09 | Microsoft Teams / Outlook / Google Calendar (notifications, meetings, email-in to registers) | Bi-directional | M |
| IR-10 | Email ingestion (transmittals, letters) with auto-registration | Inbound | M |
| IR-11 | Power BI / Tableau connector | Outbound | S |
| IR-12 | IoT / site telemetry (equipment hours, gas detectors, access control turnstiles for manpower) | Inbound | C |
| IR-13 | Barcode / QR / RFID scanners (materials, tags, ITRs) | Inbound | M |
| IR-14 | National PKI / digital signature providers | Bi-directional | S |
| IR-15 | SMS / messaging gateways (incl. local Iranian providers) | Outbound | S |
| IR-16 | Accounting/e-invoicing local compliance (e.g. Iranian tax system, EU e-invoicing) | Outbound | S |
| IR-17 | Laboratory / NDT service providers (result import) | Inbound | C |
| IR-18 | Identity providers (SAML/OIDC/LDAP/SCIM) | Bi-directional | M |

**IR-GEN-01 (M)** Every integration SHALL be an isolated connector with its own credentials, mapping configuration, retry/dead-letter handling, sync log and a "dry-run/preview" mode. No integration failure may block core platform operation.

---

## 11. Data Requirements

- **DR-01 (M)** Canonical data model covering: Company, User, Project, Contract, WBS/CBS/SBS/CWA node, Discipline, Document, Revision, Transmittal, Comment, Tag/Asset, Requisition, RFQ, PO, Shipment, Material Receipt, Work Package (EWP/CWP/PWP/IWP/TWP), Constraint, Activity, Progress Record, ITP, ITR, NCR, Weld, Test Pack, Punch Item, Certificate, Permit, Incident, Risk, Change, Invoice, Claim, KPI Snapshot.
- **DR-02 (M)** Every business object: globally unique ID, tenant ID, project ID, human-readable number, EN/FA title fields, status, discipline, area/system, created/modified metadata, soft delete, full version history.
- **DR-03 (M)** Master data governance: code lists (disciplines, document types, statuses, units, currencies, trades, commodity classes) manageable per tenant with project-level overrides and change control.
- **DR-04 (M)** Unit of measure engine (metric primary, imperial support) with conversion and per-report unit selection.
- **DR-05 (M)** Data quality: validation rules, mandatory-field enforcement per lifecycle state, duplicate detection, referential integrity across modules, and a data-health dashboard.
- **DR-06 (M)** Migration toolkit: templated importers with pre-validation, staged commit, rollback, and reconciliation report — for legacy Excel/Aconex/P6/file-share data.

---

## 12. Key User Journeys (Acceptance-Level Scenarios)

1. **Owner sets up an AWP project**: create project → select AWP pack → define CWAs and PoC → invite EPC, PMC, TPI, MC tenants → assign visibility matrix → gate 1 approved. *Acceptance: all invited tenants see only permitted objects; terminology switches to AWP; audit shows pack selection.*
2. **Constraint-free IWP release**: planner builds IWP → system flags missing IFC drawing and 2 material shortages → engineering issues IFC and warehouse reserves material → constraints auto-clear → superintendent releases IWP → foreman downloads it offline. *Acceptance: release is impossible while any hard constraint is open unless a waiver is approved and logged.*
3. **Bilingual document review cycle**: EPC issues drawing IFR → PMC reviewers comment in Persian → consolidated comment sheet produced bilingually → EPC dispositions → Rev B IFA → Owner approves → auto-transmittal with acknowledgement tracking. *Acceptance: cycle time, comment closure and revision history fully reportable; PDF renders correctly in both directions.*
4. **TPI hold-point release**: inspection notified 5 days ahead → TPI accepts → performs inspection offline at vendor shop → uploads signed release note → ITR closed → PO milestone unlocked for invoicing. *Acceptance: TPI record is immutable to the contractor; payment milestone gate respected.*
5. **Punch to MC certificate**: the management contractor walks down the subsystem on a tablet → raises 34 punch items with photos and P&ID pins → A-items assigned and closed → MCC prerequisites satisfied → certificate generated bilingually and signed by EPC, MC, TPI, Owner. *Acceptance: MCC cannot be issued with open A-items unless Owner-approved exception is recorded.*
6. **Change to invoice**: client site instruction → TQ → cost & schedule impact estimated → change approved per DoA → contract value updated → progress measured → IPC generated with retention and escalation → PMC verifies → Owner approves → payment tracked. *Acceptance: full traceability from originating instruction to paid invoice line.*
7. **Plan upgrade**: EPC tenant on Professional attempts to open the AWP module → sees entitlement-aware upgrade prompt → admin upgrades to Full → AWP module and standards pack become available immediately without re-provisioning. *Acceptance: no downtime, no data migration, entitlement change fully audited.*
8. **Standards pack change control**: project switches from ISO to Hybrid mid-execution → impact report generated → Owner approves → mappings applied → historical records remain valid under the previous pack version. *Acceptance: no loss of history; reports can be produced under either pack version.*

---

## 13. Delivery Roadmap (indicative)

| Phase | Duration | Content | Exit criteria |
|---|---|---|---|
| **P0 — Discovery & Design** | 6–8 weeks | Requirement sign-off, UX prototypes (EN/FA), architecture & security design, standards pack specification | Approved SRS + clickable prototype |
| **P1 — Core Foundation (MVP)** | 4–5 months | CORE (IAM, tenancy, i18n, workflow, files/CDE, audit, notifications), PROJECT, ENGINEERING doc control, basic reporting, licensing/entitlement engine, ISO pack v1 | Pilot project running document control bilingually end-to-end |
| **P2 — Execution Modules** | 4–5 months | Procurement & materials, QA/QC (ITP/ITR/NCR/welding), HSE, Schedule integration (P6), field mobile/offline app | Pilot construction site operating on the platform |
| **P3 — AWP & Commissioning** | 3–4 months | AWP pack (CWA/CWP/EWP/PWP/IWP, constraints, PoC), commissioning/completion, punch, certificates, turnover dossier | Constraint-free IWP release and MC certificate issued on a live project |
| **P4 — Commercial & Analytics** | 3 months | Cost/EVM, change/claims, invoicing, BI & dashboards, AI assist v1, Hybrid pack, pack editor | Owner-side monthly reporting fully generated from platform |
| **P5 — Scale & Certify** | ongoing | BIM/3D viewer, ERP connectors, ISO 27001 certification, on-prem package, partner SDK | Certification achieved; first Enterprise on-prem deployment |

---

## 14. Risks to the Programme

| ID | Risk | Impact | Mitigation |
|---|---|---|---|
| R-01 | Scope breadth causes never-shipping product | High | Strict MVP (P1) with one pilot client per phase; MoSCoW enforcement |
| R-02 | Standards-pack abstraction proves too rigid/too loose | High | Prototype both ISO and AWP packs in P0 against the same sample project |
| R-03 | Bilingual RTL retrofit cost | Medium-High | RTL-first design system from day one; automated bilingual visual regression tests |
| R-04 | Cross-company data-confidentiality breach | Critical | Isolation test suite, threat modelling, pen tests, default-deny visibility matrix |
| R-05 | Poor site connectivity kills field adoption | High | Offline-first field app validated on real site conditions in P2 |
| R-06 | P6/ERP integration complexity | Medium | Connector SDK + dry-run mode; treat each connector as a product |
| R-07 | Client-specific customization sprawl | High | Configuration-not-code rule; custom work delivered only as packs/modules via SDK |
| R-08 | Sanction/hosting constraints for Iranian clients | High | On-prem/in-country deployment option engineered from P1, not bolted on |
| R-09 | Change resistance from field crews | Medium | Persian-first UX, ≤15-minute training target, champion programme per site |

---

## 15. Open Questions for Stakeholders

1. **Primary launch market** — Iran-first (on-prem, Persian-first, local billing) or international SaaS-first? This changes hosting, billing and compliance priorities significantly.
2. **Pilot client and pilot project** — which discipline mix and which standards pack should the MVP target first?
3. **Contract forms** to support natively (FIDIC Red/Yellow/Silver, Iranian standard forms/شرایط عمومی پیمان, bespoke)?
4. **Mandatory client document numbering schemes** to preload (e.g. NIOC/NIGC/NPC standards)?
5. **Existing tool landscape** to integrate or replace (Aconex, ProjectWise, SAP, P6, in-house Excel)?
6. **Plan boundary decisions** — is AWP a Full-plan feature or a paid add-on for all tiers?
7. **Hosting/data-residency commitments** required by target clients.
8. **Budget and target GA date**, which will determine phase parallelization and team size.

---

## Appendix A — Glossary (EN / FA)

| EN | FA | Note |
|---|---|---|
| EPCC | مهندسی، تأمین، اجرا و راه‌اندازی | Engineering, Procurement, Construction, Commissioning |
| AWP / WFP | برنامه‌ریزی پیشرفته کار / برنامه‌ریزی جبهه کاری | Advanced Work Packaging / Workface Planning |
| CWA / CWP | محدوده کاری اجرا / بسته کاری اجرا | Construction Work Area / Package |
| EWP / PWP / IWP | بسته کاری مهندسی / تأمین / نصب | |
| Path of Construction | مسیر اجرا | |
| Constraint | محدودیت / قید | |
| Deliverable / MDR | اقلام تحویلی / فهرست مدارک | Master Document Register |
| Transmittal | نامه ارسال مدارک | |
| TQ / RFI | پرسش فنی / درخواست اطلاعات | |
| ITP / ITR | برنامه بازرسی و آزمون / گزارش بازرسی و آزمون | |
| NCR / CAPA | گزارش عدم انطباق / اقدام اصلاحی و پیشگیرانه | |
| Punch List | فهرست نواقص | |
| MC (milestone) / RFSU | تکمیل مکانیکی / آمادگی راه‌اندازی | The completion **milestone**. Do not confuse with the MC tenant type below. |
| MC (company type) | مدیریت پیمان | **Management Contractor** — the employer's contract administrator. Same abbreviation, different concept. |
| TPI | بازرسی شخص ثالث | |
| Turnover Dossier / MRB | داشیه تحویل / کتابچه سوابق ساخت | |
| EVM (CPI/SPI) | مدیریت ارزش کسب‌شده | |
| Change Order / Variation | دستور تغییر | |
| IPC | صورت‌وضعیت موقت | |
| PTW | مجوز کار | |
| CDE | محیط داده مشترک | Common Data Environment (ISO 19650) |
| Standards Pack | بسته استاندارد | Platform-specific concept |
| Entitlement | حق دسترسی/اشتراک | Licensing concept |

---

*End of document — SRS-EPCC-001 v1.0*
