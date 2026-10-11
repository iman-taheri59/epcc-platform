# Architecture & Data Model
## Industrial EPCC Project Management & Automation Platform
### Companion to SRS-EPCC-001 v1.0

> **Architecture status note — 2026-10-05:** The stack section in this document predates the Laravel decision. `ADR-003 Annex A` supersedes the original NestJS/React stack decision: the current implementation target is Laravel 13 + Filament v4 + PostgreSQL with a framework-free standards engine. The architectural principles, RLS model, standards-pack model and event/audit concepts remain relevant, but implementation examples that mention NestJS/React should be read as historical until this document is fully reconciled.
>
> **Discipline sequencing note:** all EPCC disciplines remain in scope, but Piping is now the first discipline to be completed deeply. Piping-specific implementation must leave generic seams for the remaining disciplines instead of hardcoding the platform around Piping.

| Field | Value |
|---|---|
| Document ID | ARC-EPCC-001 |
| Version | 1.0 |
| Date | 2026-09-09 |
| Status | Draft for architecture review |

---

## 1. Architecture Principles

| # | Principle | Consequence |
|---|---|---|
| P1 | **Configuration over code** | Standards packs, workflows, forms, numbering rules, KPIs and permissions are versioned data, not deployments. Client-specific behaviour never forks the codebase. |
| P2 | **Modular by contract** | Each module owns its schema and publishes an API + event contract. No cross-module SQL joins, ever. |
| P3 | **Default-deny visibility** | Nothing is visible across company boundaries unless the visibility matrix grants it. |
| P4 | **Bilingual-first, RTL-first** | Every string, layout, PDF and chart is designed for FA-RTL and EN-LTR simultaneously — never retrofitted. |
| P5 | **Offline is a first-class citizen** | Field capture must survive a week without connectivity and reconcile deterministically. |
| P6 | **Everything is auditable** | Every state change is an event; the event log is the legal record. |
| P7 | **Entitlement enforced server-side** | The client never decides what a plan allows. |
| P8 | **Deployable anywhere** | The same artifact runs in multi-tenant cloud, single-tenant cloud, and air-gapped on-premise. |

---

## 2. High-Level Architecture

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                              CLIENT LAYER                                    │
│  Web SPA (React/TS, RTL-aware)  │  Field PWA (offline)  │  Public API clients │
│  Bilingual design system        │  IndexedDB + sync     │  Power BI / ERP     │
└───────────────────────────┬──────────────────────────────────────────────────┘
                            │  HTTPS / OpenAPI 3.1 / GraphQL(read) / WebSocket
┌───────────────────────────▼──────────────────────────────────────────────────┐
│                    EDGE  —  API Gateway / BFF                                │
│  AuthN (OIDC)  ·  Rate limit per plan  ·  Idempotency  ·  Tenant resolution   │
│  Request-scoped context: {tenantId, userId, projectId, locale, entitlements}  │
└───────────────────────────┬──────────────────────────────────────────────────┘
                            │
┌───────────────────────────▼──────────────────────────────────────────────────┐
│                    PLATFORM KERNEL (cross-cutting, always on)                │
│  Identity & Tenancy │ Entitlement/Licensing │ Standards Engine │ Workflow/BPM │
│  i18n & Calendar    │ Audit & Ledger        │ Notification     │ Form Engine  │
│  Numbering Engine   │ Search Indexer        │ File/CDE Service │ Job Runner   │
└───────────────────────────┬──────────────────────────────────────────────────┘
                            │  in-process calls (modular monolith) + domain events
┌───────────────────────────▼──────────────────────────────────────────────────┐
│                         BUSINESS MODULES (pluggable)                         │
│ PROJECT │ ENGINEERING │ PROCUREMENT │ CONSTRUCTION/AWP │ QUALITY │ HSE        │
│ COMMISSIONING │ SCHEDULE │ COST&CONTRACT │ ANALYTICS │ SAAS-ADMIN            │
│ each: manifest.json → capabilities, permissions, events, settings, licence    │
└───────────────────────────┬──────────────────────────────────────────────────┘
                            │  outbox → bus
┌───────────────────────────▼──────────────────────────────────────────────────┐
│              EVENT BUS (Kafka/RabbitMQ) + Outbox pattern                      │
│  document.approved.v1 · iwp.released.v1 · punch.raised.v1 · ncr.closed.v1 ... │
└───┬─────────────┬──────────────┬───────────────┬──────────────┬──────────────┘
    │             │              │               │              │
┌───▼───┐   ┌─────▼─────┐  ┌─────▼─────┐   ┌─────▼─────┐  ┌─────▼──────────────┐
│Search │   │ Analytics │  │Notification│  │ Connector │  │ File Conversion    │
│OpenSrch│  │ ClickHouse│  │ Email/SMS │   │ Hub (P6,  │  │ (CAD/BIM/PDF/OCR)  │
│EN+FA  │   │ + dbt     │  │ Teams/Push│   │ SAP, BIM) │  │ async workers      │
└───────┘   └───────────┘  └───────────┘   └───────────┘  └────────────────────┘

DATA:  PostgreSQL (RLS, per-tenant keys) · Redis · S3-compatible object store
```

### 2.1 Deployment Topologies

| Topology | Target | Isolation | Notes |
|---|---|---|---|
| **Shared SaaS** | Starter / Professional / Full | Logical (RLS + tenant key) | Multi-region, region choice from Professional |
| **Dedicated DB** | Full (option) | Separate database, shared compute | For clients with DB-level separation clauses |
| **Single-tenant** | Special / Enterprise | Separate namespace + DB + storage bucket | Custom domain, BYOK, own release cadence |
| **On-premise / air-gapped** | Enterprise (NOC/sovereign) | Fully customer-operated | Offline update channel, signed bundles, no telemetry egress |

### 2.2 Why modular monolith first
A modular monolith with enforced bounded contexts gives us: single transaction boundary for complex EPCC workflows (an IWP release touches 6 contexts), far simpler on-prem/air-gapped packaging, and lower operational cost during P1–P3. Contexts already communicate only through interfaces + events, so extraction to services (file conversion, search, analytics, notifications first) is mechanical when load demands it.

---

## 3. Module Manifest Contract

Every module ships a manifest that the kernel reads at boot. This is what makes modules genuinely pluggable and licence-aware.

```jsonc
{
  "moduleId": "construction.awp",
  "version": "1.4.0",
  "displayName": { "en": "Advanced Work Packaging", "fa": "بسته‌بندی پیشرفته کار" },
  "dependsOn": ["core.workflow", "core.forms", "project.wbs", "engineering.deliverables"],
  "optionalIntegrations": ["schedule.p6", "engineering.model"],
  "licence": {
    "requiredEntitlement": "module.awp",
    "availableInPlans": ["full", "special"],
    "purchasableAsAddOn": true
  },
  "standardsPackHooks": ["awp", "hybrid"],
  "permissions": [
    "iwp.view", "iwp.create", "iwp.release", "iwp.waiver.approve",
    "cwp.manage", "constraint.manage", "poc.edit"
  ],
  "publishes": ["iwp.created.v1", "iwp.released.v1", "constraint.cleared.v1"],
  "subscribes": ["document.approved.v1", "material.reserved.v1", "permit.issued.v1"],
  "settings": [
    { "key": "iwpTargetCrewDays", "type": "number", "default": 10, "scope": "project" },
    { "key": "hardBlockOnConstraint", "type": "boolean", "default": true, "scope": "project" }
  ],
  "ui": {
    "navEntries": [{ "route": "/awp", "icon": "package", "order": 40 }],
    "dashboardWidgets": ["iwp-readiness", "constraint-aging", "ppc-trend"]
  },
  "migrations": "./migrations",
  "healthCheck": "/internal/health/awp"
}
```

**Kernel guarantees:** a module whose entitlement is absent is not booted, its routes 404 with an upgrade hint, its events are not subscribed, and its tables are never created for that tenant. Disabling costs nothing at runtime (NFR-MOD-03).

---

## 4. Standards Engine — Runtime Resolution

The Standards Engine is a **resolver** sitting between modules and configuration. Modules never hardcode a workflow, a status name or a KPI formula; they ask the engine.

```
Module asks:  resolve("document.lifecycle", ctx{project, objectType, discipline})
                          │
                 ┌────────▼────────┐
                 │ Standards Engine│
                 └────────┬────────┘
      layered merge (later overrides earlier):
        1. Platform baseline pack        (ships with product)
        2. Selected pack: iso | awp | hybrid   (versioned, signed)
        3. Tenant-level overrides        (change-controlled)
        4. Project-level overrides       (change-controlled, audit-logged)
        5. Discipline-level overrides    (narrow, e.g. piping weld ITRs)
                          │
              resolved, cached, versioned config  →  module behaviour
```

### 4.1 What a pack controls
| Domain | Example ISO pack | Example AWP pack |
|---|---|---|
| Breakdown structures | WBS / CBS / OBS / PBS | CWA → CWP → IWP, EWP, PWP, TWP |
| Terminology (EN/FA) | "Deliverable" / «قلم تحویلی» | "EWP" / «بسته کاری مهندسی» |
| Document lifecycle | WIP→Shared→Published→Archived (ISO 19650, S0–S7) | Same + `IFC-for-IWP` suitability gate |
| Progress rules | Rule-of-credit by revision milestone | Rule-of-credit by installed quantity per IWP |
| Release gates | Gate review checklist | Constraint-free release (hard block) |
| KPIs | Doc turnaround, NCR aging, CPI/SPI | PPC, tool time, IWP on-time release, constraint aging |
| Numbering | ISO 19650 naming convention | CWP/IWP coded by CWA + discipline + sequence |
| Certificates | ISO 9001 evidence set | TWP/RFSU turnover set |

### 4.2 Pack change control
Switching packs mid-project runs the **impact analyzer**:
1. Diff the resolved configuration (states, mandatory fields, gates, KPIs).
2. Classify each delta: `safe` / `needs-mapping` / `data-loss-risk`.
3. Produce a bilingual impact report with affected object counts.
4. Require Owner approval + justification; write an immutable `pack.changed.v1` event.
5. Historical records remain resolvable under the **pack version they were created with** — reports can be re-run under either version.

---

## 5. Multi-Company Authorization Pipeline

Every request passes through five gates in strict order. Failing any gate returns 403 with a machine-readable reason (`ENTITLEMENT_MISSING`, `NOT_PROJECT_MEMBER`, `SCOPE_EXCLUDED`, `VISIBILITY_DENIED`, `SOD_CONFLICT`).

```
Request
  │
  1. AUTHENTICATION  ── valid token, MFA satisfied, session/device/IP policy OK
  │
  2. ENTITLEMENT     ── does the caller's TENANT plan include this capability?
  │                     (signed entitlement token, server-evaluated, cached 60s)
  3. MEMBERSHIP      ── is the user a member of this project, in which contract role?
  │
  4. RBAC            ── does any assigned role grant the atomic capability?
  │                     e.g. "iwp.release", "punch.close.A", "cost.actuals.view"
  5. ABAC + VISIBILITY
  │     a) scope filters: discipline ∈ user scope, CWA ∈ scope, system ∈ scope,
  │        WBS branch ∈ scope, confidentiality label ≤ user clearance
  │     b) cross-company matrix: for (objectType, ownerCompanyType, viewerCompanyType)
  │        → Hidden | Metadata | Read | Comment | Review | Approve | Edit | Own
  │     c) commercial firewall: rates/margins/internal float/internal risk
  │        NEVER cross company boundary unless explicitly published
  │
  6. SoD CHECK (on write) ── conflicting duty combination? threshold exceeded?
  │
  → Handler executes → domain event → audit ledger (hash-chained)
```

### 5.1 Data-layer enforcement
Authorization is **not** only in the service layer. PostgreSQL Row-Level Security policies enforce `tenant_id` and project membership as a backstop, so a bug in a handler cannot leak cross-tenant data. A dedicated isolation test suite (`AR-04`) attempts cross-tenant reads on every table in CI and fails the build on any success.

### 5.2 Confidentiality labels
`public-in-project` · `company-internal` · `commercial-restricted` · `personal-data` · `safety-critical`
Labels drive: visibility defaults, export watermarking, download restriction, retention, and audit intensity.

---

## 6. Canonical Data Model

### 6.1 Universal object envelope
Every business entity carries this envelope (DR-02):

```
id              uuid            globally unique
tenant_id       uuid            owning company (RLS key)
project_id      uuid            null for tenant-level master data
number          text            human-readable, generated by Numbering Engine
title_en        text
title_fa        text
status          text            resolved against active standards pack
discipline_code text            FK → discipline code list
area_id         uuid            CWA / unit / plant
system_id       uuid            SBS node (for commissioning traceability)
confidentiality text            label (§5.2)
pack_version    text            standards pack version at creation
created_by/at, modified_by/at, deleted_at (soft), version (optimistic lock)
```

### 6.2 Core relationships (ERD, textual)

```
COMPANY (tenant) ──< USER
   │                   │
   └──< PLAN/ENTITLEMENT│
                        │
PROJECT ──< PROJECT_MEMBERSHIP >── USER
   │           (company, contract role, role templates, scope filters)
   │
   ├──< CONTRACT ──< CHANGE_ORDER ──< CLAIM
   │        └──< INVOICE (IPC) ──< PAYMENT
   │
   ├──< WBS_NODE ──< ACTIVITY (schedule) ──< PROGRESS_RECORD
   ├──< CBS_NODE ──< BUDGET ──< COMMITMENT / ACTUAL / FORECAST
   ├──< SBS_NODE (system → subsystem) ──< TAG
   ├──< CWA ──< CWP ──< IWP ──< CONSTRAINT
   │              │        └──< FIELD_PROGRESS, ITR_INSTANCE, PUNCH_ITEM
   │              ├──< EWP ──< DELIVERABLE
   │              └──< PWP ──< REQUISITION
   │
   ├──< DELIVERABLE ──< REVISION ──< FILE
   │        │              └──< COMMENT ──< DISPOSITION
   │        └──< TRANSMITTAL_LINE >── TRANSMITTAL
   │
   ├──< TAG (equipment/line/instrument/cable/loop)
   │        ├──< DATASHEET, ITR_INSTANCE, CERTIFICATE, PUNCH_ITEM
   │        └──< PO_LINE (what was bought for this tag)
   │
   ├──< REQUISITION ──< RFQ ──< BID ──< PO ──< PO_MILESTONE
   │                                     ├──< EXPEDITING_RECORD
   │                                     ├──< INSPECTION_NOTIFICATION ──< TPI_RELEASE
   │                                     ├──< SHIPMENT ──< MRR ──< MATERIAL_STOCK
   │                                     └──< VDRL_ITEM ──< REVISION
   │
   ├──< ITP ──< ITP_POINT (H/W/R/M + responsible party)
   │              └──< ITR_INSTANCE ──< SIGNATURE (multi-party)
   ├──< NCR ──< ROOT_CAUSE ──< CAPA
   ├──< WELD ──< NDT_REQUEST ──< NDT_RESULT      WELDER ──< QUALIFICATION
   ├──< TEST_PACK ──< LINE / WELD
   │
   ├──< PERMIT (PTW) ──< ISOLATION (LOTO)
   ├──< INCIDENT ──< INVESTIGATION ──< CAPA
   │
   ├──< PUNCH_ITEM (A/B/C) ──> SBS_NODE, TAG, IWP
   ├──< CERTIFICATE (MCC / RFSU / PAC / FAC) ──< PREREQUISITE_CHECK
   ├──< TURNOVER_DOSSIER ──< DOSSIER_SECTION >── (documents, certs, ITRs)
   │
   ├──< RISK ──< RESPONSE_PLAN
   ├──< TQ / RFI ──> IMPACT (cost/schedule) ──> CHANGE_ORDER
   ├──< INTERFACE_AGREEMENT (party A ↔ party B)
   └──< MEETING ──< ACTION_ITEM

AUDIT_EVENT (append-only, hash-chained)  ← every mutation everywhere
DOMAIN_EVENT (outbox → bus)              ← every state transition
```

### 6.3 The three traceability spines
These chains must be queryable end-to-end in one hop from the UI — they are what auditors and clients actually ask for:

1. **Physical spine:** `Tag → Datasheet → PO line → MRR → heat number → Weld → NDT → ITR → Punch → Subsystem → MC certificate → Turnover dossier`
2. **Work spine:** `Path of Construction → CWA → CWP → IWP → Constraint clearance → Field progress → Earned quantity → EVM → IPC line`
3. **Change spine:** `Site instruction / TQ → Impact assessment → Change order → Contract value → Measured progress → Invoice → Payment → Claim (if disputed)`

### 6.4 Key design decisions
| Decision | Rationale |
|---|---|
| Tags are first-class, not document attachments | Handover data (CFIHOS/ISO 15926) and commissioning depend on tag-centric queries, not document-centric ones |
| IWP holds a *snapshot* of its scope at release | Field crews must not have the package change under them; changes create a revision with a re-release decision |
| Progress records are immutable append-only entries | Progress must be reconstructible for claims and delay analysis; corrections are reversing entries, never edits |
| Constraints are typed entities with owners and dates | Constraint aging is the single most predictive AWP KPI; it needs its own lifecycle, not a checkbox |
| Bilingual titles are columns, not a translation table | Every register list and PDF needs both languages at once; a join per row is unacceptable at 500k documents |
| Pack version stamped on every record | Lets historical records stay valid after a pack change (§4.2) |

---

## 7. Offline Field Sync Design

Field crews work in areas with no connectivity. The sync model is deterministic and conflict-safe.

| Aspect | Approach |
|---|---|
| **Download unit** | The IWP is the sync unit: package + drawings + ITR forms + tag list + punch history, bundled and cached in IndexedDB |
| **Write model** | Append-only local operation log with client-generated UUIDs and monotonic sequence — never "save the whole record" |
| **Conflict policy** | Per object type: `form-instance` = last-writer-wins on field level with both versions retained; `punch/ITR signature` = never overwritten, second signature becomes an additional record; `progress quantity` = additive entries, no overwrite |
| **Server authority** | Server rejects operations violating the pack rules (e.g. closing an A-punch without permission) and returns a bilingual rejection reason queued for the user |
| **Media** | Photos compressed client-side, uploaded on resumable chunked transfer, tied to the operation log entry |
| **Guarantee** | 7 days offline, 500 MB cache, deterministic replay; no silent data loss under any sequence |

---

## 8. Bilingual & RTL Architecture

| Concern | Implementation |
|---|---|
| Layout | Logical CSS properties everywhere (`margin-inline-start`, not `margin-left`); `dir` on `<html>`; single stylesheet serves both directions |
| Charts | Gantt, S-curve and histogram components accept a `direction` prop and mirror axes, legends and time flow |
| Fonts | Vazirmatn/IRANSans-class Persian webfont + Inter for Latin, with a unified metric scale so mixed strings don't jump |
| Text normalization | ی/ك Arabic→Persian normalization, ZWNJ handling, digit shaping (۰-۹ vs 0-9 display, always ASCII in storage) |
| Search | Two analyzers in OpenSearch: `en_std` and `fa_norm` (normalization + stemming + stopwords); queries hit both |
| Sorting | Persian collation via ICU; never rely on byte order |
| Storage | Always UTC + ISO-8601; Jalali is a **display/entry** conversion, never a storage format |
| Documents | PDF renderer with dual layouts: `EN`, `FA`, and `EN|FA` side-by-side for transmittals, MoM and certificates |
| CI enforcement | Build fails on any missing FA key; visual regression tests run every critical screen in both directions |

---

## 9. Event Catalogue (excerpt)

Events are versioned, additive-only, and documented. Consumers must tolerate unknown fields.

| Event | Publisher | Notable consumers |
|---|---|---|
| `document.approved.v1` | Engineering | AWP (clears engineering constraint), Notification, Analytics, Search |
| `material.received.v1` | Procurement | AWP (material constraint), Warehouse, Cost (VOWD) |
| `material.reserved.v1` | Procurement | AWP (IWP readiness) |
| `permit.issued.v1` | HSE | AWP (permit constraint) |
| `constraint.cleared.v1` | Construction | AWP readiness engine, Analytics |
| `iwp.released.v1` | Construction | Field PWA (push package), Schedule (L5), Analytics |
| `progress.recorded.v1` | Construction | Cost (EVM), Schedule, Analytics, Invoicing |
| `itr.signed.v1` | Quality | Commissioning (subsystem completion), Dossier builder |
| `ncr.raised.v1` / `ncr.closed.v1` | Quality | Cost (CoPQ), Analytics, Notification |
| `punch.raised.v1` / `punch.closed.v1` | Commissioning | Certificate prerequisite engine |
| `certificate.issued.v1` | Commissioning | Contract (milestone), Invoicing, Owner dashboard |
| `change.approved.v1` | Commercial | Schedule, Cost, Contract value, Analytics |
| `pack.changed.v1` | Standards Engine | Every module (cache invalidation), Audit |
| `entitlement.changed.v1` | SaaS Admin | Kernel (module boot/unboot), UI |

---

## 10. Technology Selection (indicative, confirm at design gate)

| Layer | Choice | Why |
|---|---|---|
| Frontend | React 18 + TypeScript, Vite, TanStack Query, logical-CSS design system | Mature RTL support; virtualization for 100k-row registers |
| Field app | Same React codebase as PWA + IndexedDB (Dexie) + Workbox | One codebase; installable; runs on low-end Android |
| Backend | **NestJS on Node 24 LTS** (ADR-003) | Shares the pack rule-evaluator package with the PWA; free toolchain; one runtime to package for air-gapped installs |
| Primary DB | PostgreSQL 18 + Row-Level Security | RLS as isolation backstop; JSONB for pack-driven dynamic attributes |
| Cache/queue | Redis + BullMQ; RabbitMQ (Kafka optional) | Kafka never mandatory, so air-gapped installs stay simple |
| Search | OpenSearch with EN + FA analyzers | Self-hostable, no licence issue on-prem |
| Analytics | ClickHouse, added at P4 | Don't pay for a second datastore before the volume justifies it |
| Object storage | MinIO (S3-compatible) | Same API cloud and air-gapped |
| Identity | Keycloak (bundled) + LDAP/AD federation | Works offline; nearly every Iranian enterprise runs AD |
| File conversion / OCR | Isolated worker pool; Tesseract with Persian traineddata | Untrusted-file processing must be sandboxed and separately scalable |
| PDF | Headless Chromium + Persian shaping pipeline — **P0 spike required** | Highest-risk item; Persian typography is where this market's software fails (IR-R2) |
| Infra | Docker Compose / k3s + Helm, signed offline bundles | One artifact, four topologies |

Full reasoning, alternatives considered and reversal conditions: **`ADR-003-Technology-Stack.md`**.

## 11. Cross-Cutting Concerns Placement

| Concern | Where it lives | Note |
|---|---|---|
| Numbering | Kernel service | Rule-driven, reservation-capable, collision-proof under concurrency |
| Forms (ITR, DPR, checksheets) | Kernel Form Engine | One engine serves QA, HSE, Construction, Commissioning — no duplicated form logic |
| Workflow | Kernel BPM | Modules declare workflow bindings; they never implement state machines locally |
| Notifications | Kernel + async worker | Digest/batching, per-user quiet hours, bilingual templates |
| Reporting | Analytics module + template engine | Pack-driven template selection; bilingual output |
| Attachments | File/CDE service | Single storage, versioning and virus scanning path for all modules |
| Audit | Kernel ledger | Hash-chained, append-only, exportable to customer SIEM |

---

## 12. Open Architecture Decisions (need a call)

| # | Decision | Options | Recommendation |
|---|---|---|---|
| AD-1 | Backend stack | .NET vs NestJS vs Java/Spring | **Historical — superseded 2026-10.** Was closed as TypeScript/NestJS under ADR-EPCC-003; **ADR-003 Annex A then selected Laravel 13 + Filament v4 + PHP 8.4 + PostgreSQL 17**, which is what the codebase implements. Read this row as the decision trail, not the current stack. |
| AD-2 | Monolith vs services at launch | Modular monolith vs microservices | **Modular monolith** — on-prem packaging and transactional EPCC workflows both favour it |
| AD-3 | Kafka mandatory? | Required vs optional | **Optional** — RabbitMQ/DB-outbox mode for air-gapped installs |
| AD-4 | BIM viewer | Build vs licence (Autodesk APS, Bentley, open IFC.js) | Licence for v1 (IFC.js for open formats), revisit at scale |
| AD-5 | Tenant DB strategy | Shared schema vs schema-per-tenant vs DB-per-tenant | Shared + RLS for Starter–Full; DB-per-tenant for Enterprise |
| AD-6 | AI hosting | Vendor API vs self-hosted model | Both; self-hosted small model mandatory for air-gapped/sovereign clients |

---

*End of document — ARC-EPCC-001 v1.0*

---

## 5.3 Implementation status (2026-09-09)

The pipeline in §5 is implemented in `app/app/Support/AuthorizationPipeline.php`
and covered by `tests/Feature/AuthorizationPipelineTest.php` (12 tests).

| Gate | Status | Storage | Notes |
|---|---|---|---|
| 1 AUTHENTICATION | **Built** | `users` | Session auth; a disabled account is refused even with a correct password. |
| 2 ENTITLEMENT | **Built** | `plans`, `companies.plan_id` | Module + standards-pack entitlement per company (SRS BR-02). Signed entitlement *tokens* deferred; evaluation is server-side already. |
| 3 MEMBERSHIP | **Built** | `project_memberships` | DB trigger refuses a membership whose company is not on the project. Read queries require a personal membership, **not** merely company participation — see §5.4. |
| 4 RBAC | **Built** | `roles.capabilities` | Atomic capabilities with `family.*` wildcards. |
| 5 ABAC | **Partial** | `project_memberships.discipline_scope`, `.authority_limit` | Discipline scope and delegated authority enforced. The full cross-company visibility matrix and confidentiality labels (§5.2) are **not** built. |
| 6 SoD | **Partial** | in-code table | Submit-vs-approve conflict enforced. Threshold-based and role-combination rules deferred. |

**Refusal order is a security property, not a style choice.** A non-member is
refused at gate 3 before gate 4 runs, so the response cannot be used to
enumerate which capabilities exist on another company's project. The tests
assert the *gate*, not merely that access was denied.

**Denials are audited** to `authorization_denials` with gate, machine-readable
reason, capability and path (NFR-SEC-06). That table is deliberately *not*
RLS-scoped: a cross-tenant probe is exactly what a security operator must see.
Audit writes are wrapped in a try/catch — a full audit table must never turn a
403 into a 500.

**Authority is a property of the person.** `authority_limit` lives on the
membership, not the company. This surfaced immediately: PH14-SV-0007 (43.2B) is
cleared for the employer's representative and blocked on
`budgetWithinAuthority` for the contractor's own PM.

## 5.4 Participation is not membership

Two facts are easy to conflate, and conflating them is a data breach:

| Fact | Table | Means |
|---|---|---|
| **Participation** | `project_company` | "EPC-PARS is a contractor on Phase 14." A commercial relationship between an organisation and a project. This is what the RLS predicate keys on. |
| **Membership** | `project_memberships` | "Engineer Rezaei is staffed on Phase 14 as project manager, with a 40B authority limit." A grant to a *person*. |

Participation is a precondition for membership — the database trigger enforces
that direction — but it does **not** imply it. A contractor may have two
hundred employees and eight people on a given project.

Application read queries must therefore filter on **both**. Requiring only
participation means every employee of every participating company can read
every project that company touches, from their first login, with no deliberate
act of staffing. This was a live defect in `CertificateController`, found on
2026-09-09 while building the project-setup screen: it went unnoticed because
the demo fixture only contained users whose memberships happened to mirror
their company's participation.

RLS remains company-scoped by design — it is the commercial firewall between
organisations, and it is the correct boundary for that job. Person-level
scoping sits above it in the query layer. **Two boundaries, two mechanisms;
neither substitutes for the other.**
