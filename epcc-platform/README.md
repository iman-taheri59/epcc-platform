# EPCC Suite — Project Workspace

## Material requisition entry — 2026-09-30

Project-configured own-company draft entry is now reachable under **تهیه و خرید / Procurement**. Owner-company project administrators explicitly nominate people/company-scoped roles; no default authors. The form validates 1–20 lines, preserves entered quantity/unit and binds an author policy; project-wide numbering no longer depends on company-visible MAX. [Usage, verification and remaining scope](docs/MATERIAL-REQUISITION-ENTRY-2026-09-30.md). This is the first material-lifecycle slice, not PO/receipt/return completion. ITP continuation is paused; PMS NDT stays outside material inspection.

## PMS NDT source requirements — 2026-09-29

Structured NDT source entry now records applicability, method, extent, sampling basis, acceptance, references and conditions, with visible missing details, stable IDs, class-version checks and audit history. This is outside material inspection. Migration235950 adds the protected child table. [Usage and verification](docs/PMS-NDT-REQUIREMENTS-2026-09-29.md). ITP links and NDT execution/results remain subsequent work; no approval or warehouse gate is created.

## PMS manual-valve entry — 2026-09-29

User-selected manual-valve form now records construction standard, trim and manual operator separately from body fields, with explicit keep/replace/clear, complete item audit and bulk preview/atomic confirmation. Migration235900 adds the missing valve-spec SQL archive backstop. [Usage, verification and limits](docs/PMS-MANUAL-VALVES-2026-09-29.md). Transcription is not approval; revisions and deferred authorities remain paused.

## PMS class special requirements — 2026-09-29

The header now supports faithful multiline requirements with explicit keep/replace/clear, escaped FA/EN summaries, legacy-data preservation and existing company/author/version/archive controls. This is transcription, not standards adoption or engineering approval. [Usage, tests and scope](docs/PMS-SPECIAL-REQUIREMENTS-2026-09-29.md). No schema change; revision review remains paused.

## PMS header units — 2026-09-29

Design temperature and corrosion allowance now retain the entered decimal/unit plus a six-decimal canonical mirror, with C/F and the existing length menu. Explicit keep/enter/clear preserves legacy provenance; preferred-unit display never rewrites source values. [Usage, migration and test evidence](docs/PMS-MEASUREMENTS-2026-09-29.md). Pressure rating and document/AFC revisioning are unchanged.

## PMS bulk entry — 2026-09-29

The new-item form now supports explicit multi-size selection, server preview/conflict checks and all-or-none creation of independent rows/audit events. Existing author/company gates apply; no automatic overwrite. [Usage, tests and limits](docs/PMS-BULK-ENTRY-2026-09-29.md). Revision design reviews are paused at the user's request; this is operational entry work, not published PMS/AFC integration.

## PMS authoring prerequisite — 2026-09-29

PMS entry now requires explicit per-project user/company-role nomination; until configured it is read-only. Nomination retains company ownership and does not grant publication or standards adoption. Configuration is available on the PMS page to the existing owner-company `project.configure` administrator. No seed/migration grants default authors. [Delivery and test scope](docs/PMS-AUTHORING-POLICY-2026-09-29.md). **PMS document/revision hierarchy and AFC pins remain unimplemented.**

Bilingual (EN/FA) modular platform for large industrial EPCC projects — oil, gas, petrochemical, power.

## Development rule

Every capability must be covered by its relevant ADR. Read [the ADR process](docs/ADR-PROCESS.md), [index](docs/ADR-INDEX.md), root `AGENTS.md` and applicable amendments before implementing. The [PMS reconciliation](docs/ADR-RECONCILIATION-2026-09-29.md) identifies open gaps in the recent partial UI; successful tests are not complete architectural conformity.

## Contents

> **Market decision: Iran-first.** On-premise/air-gapped deployment, Persian-default UI, Jalali calendar and IRR billing are constraints, not options. See `docs/Iran-First-Market-Addendum.md` — it supersedes conflicting statements elsewhere.

| Path | What it is |
|---|---|
| `HANDOFF.md` | **START HERE.** Living handoff — what works today, the architecture rules you must not break, the preview-iframe traps, testing discipline, next steps, and a session log. Updated every session. |
| `docs/SRS-EPCC-Platform.md` | Software Requirements Specification (SRS-EPCC-001) — scope, personas, access model, 13 module groups, 18 disciplines, NFRs, licensing, roadmap, risks |
| `docs/Architecture-and-Data-Model.md` | Architecture (ARC-EPCC-001) — module manifest contract, standards engine resolution, 6-gate authorization pipeline, canonical data model, offline sync, RTL architecture, event catalogue, tech selection |
| `app/` | **The Laravel 13 application** — real code. **Authentication + ARC §5 six-gate authorization**, **project setup** (create a project, choose its standards pack, invite companies, staff people), **multi-project** portfolio + per-project workspaces, tenant isolation enforced by PostgreSQL RLS, bilingual FA/EN, real Jalali dates. `php artisan serve` |
| `packages/standards-engine/` | **Framework-free PHP rule engine** (`epcc/standards-engine`). Zero Laravel dependency by design — ADR-003 Annex A §6. Evaluates pack release gates |
| `packages/standards-engine/conformance/` | **Language-neutral conformance corpus** (25 cases, v1.2.0) + runner + mutation-testing record. The contract that lets the PHP server and the future TS offline app provably agree |
| `docs/Spike-RLS-Results.md` | **P0 spike result** — Eloquent + PostgreSQL RLS. PASS, with five defects found and fixed (empty-GUC cast, audit actor vs subject, non-singleton context, company-only predicate ignoring project participation, nested-scope leak). §8 multi-project, **§9 Octane — CLOSED (0 leaks / 640 requests, 2.8× p50)**, **§10 PgBouncer transaction pooling — CLOSED (safe; a plain `SET` leaks across client connections)** |
| `docs/Infra-Cost-Model-Iran.md` | **Server cost model in تومان** — per-stack RAM footprint, Iranian cloud/dedicated/hardware price anchors (Sep 2026), three sizing scenarios, and what actually reduces the bill |
| `docs/ADR-003-Annex-A-Laravel-Evaluation.md` | **Annex to ADR-003** — Laravel/Filament assessed as the backend. Makes Laravel the primary alternative to TypeScript, overturns the ADR-003 §3.1 argument, and ties the choice to the team's actual language |
| `docs/ADR-003-Technology-Stack.md` | **Decision record** — TypeScript end-to-end (NestJS + React + PostgreSQL 18), why not .NET, the five P0 spikes that must pass, and the reversal conditions |
| `docs/Prototype-Coverage-Map.md` | **Read before demoing** — module-by-module map of what the prototype shows vs what the system specifies, and what is deliberately absent |
| `docs/ADR-002-Defer-AWP.md` | **Decision record** — AWP methodology deferred to the international phase; the two architectural seams that keep revival at ~6–10 weeks instead of a rewrite |
| `docs/ADR-001-AWP-Packaging.md` | **Decision record** — how AWP is decomposed and licensed: constraint engine in core, AWP methodology as a project-level add-on, model-based scoping with the BIM viewer. Includes the falsifiers that would reverse it. |
| `docs/Iran-First-Market-Addendum.md` | **Decision record (MKT-EPCC-001)** — dependency substitution table, air-gap requirements, Jalali/Nowruz calendar rules, IPS pack scope, revised commercial model and roadmap |
| `standards-packs/schema/standards-pack.schema.json` | JSON Schema (Draft 2020-12) defining what a standards pack may contain |
| `standards-packs/ips-iran.pack.json` | **IPS / Iran Pack v1.0.0** — IPS standards, شرایط عمومی پیمان (۴۳۱۱), تعدیل, صورت‌وضعیت, تحویل موقت/قطعی, HSE-MS. Launch default. |
| `standards-packs/iso.pack.json` | ISO Framework Pack v1.0.0 — ISO 21502, 19650, 9001, 31000, 45001, 15926/CFIHOS |
| `standards-packs/awp.pack.json` | AWP / WFP Pack v1.0.0 — **deferred to the international phase but kept in CI** as a genericity guard (ADR-002) |
| `standards-packs/validate_packs.py` | CI gate — schema validation, bilingual-parity enforcement, progress-weight checks, and the ADR-002 seam guards |
| `prototype/index.html` | Clickable bilingual prototype — 9 screens, live language / company-role / standards-pack switching, collapsible sidebar with mobile drawer |
| `prototype/test/sidebar.test.js` | jsdom behaviour test — 19 assertions covering the sidebar rail/drawer, RTL, keyboard and navigation behaviour |

## Prototype — scope warning

The prototype is **9 screens covering ~15–20% of the specified system** (12 module groups, ~101 functional areas, 176 requirements). It is a static HTML mock-up: **no database, no backend, no authentication, nothing persists.** It exists to prove four hard architectural ideas — the standards pack engine, bilingual RTL, cross-company permissions, and hard release gates — not to preview the product. Full breakdown in `docs/Prototype-Coverage-Map.md`.

## Prototype — what to try

Open `prototype/index.html`. It boots **Persian-first with the IPS / Iran pack active**, as an Iranian customer would see it. Three switches in the header change everything:

1. **English / فارسی** — full RTL mirroring, not just text direction.
2. **Company** — EPC, Owner, PMC, TPI, MC, Subcontractor. Permissions change live: release buttons, punch-close buttons and payment-certificate actions enable or disable, and the access matrix highlights your column.
3. **Standard** — IPS / Iran, ISO, or AWP. KPIs, terminology, document lifecycle, numbering rule and available modules all change. Under IPS and ISO the AWP screens correctly report the module as inactive.

Key screens for a demo:
- **صورت‌وضعیت / Payment Certificate** — the Iranian payment build-up with تعدیل, حسن انجام کار and advance recovery, plus an authority-limit escalation. IPS pack only.
- **IWP Release Gate** — AWP constraint hard-block in action. AWP pack only.
- **Quality & Punch** — MC certificate blocked by failed guards.
- **Access Matrix** — the commercial confidentiality firewall.

**Sidebar:** the ☰ button collapses the sidebar to a 56px icon rail on desktop, and turns it into an off-canvas drawer below 820px — opening from the right in Persian/RTL and the left in English/LTR. The drawer closes on navigation, on scrim tap, and on Escape.

Tested: 324 render combinations (3 packs × 2 languages × 6 company roles × 9 pages) plus 19 jsdom behaviour assertions:

```bash
npm install jsdom && node prototype/test/sidebar.test.js
```

## Validating the packs

```bash
pip install jsonschema
python3 standards-packs/validate_packs.py
```

This is the CI gate, not just a schema check. It enforces four things:

1. **Schema validity** of all three packs.
2. **Bilingual parity** — any user-facing string with `en` but no `fa` fails the build.
3. **Progress-weight integrity** — rule-of-credit weights must sum to 100, or progress reporting silently lies.
4. **ADR-002 seam guards** — the deferred AWP pack must stay loadable, `IWP mustBelongTo CWP` must stay expressible as pack data, the launch packs must keep AWP structures disabled, and the IPS pack must keep using the constraint engine.

Point 4 is the mechanism that makes deferring AWP safe. If someone bakes Iran-specific assumptions into the standards engine, the AWP pack stops resolving and the build breaks that day — instead of the problem surfacing three years later when the international release needs it.

Verified: all four erosion scenarios (disabled IWP, stripped constraint engine, English-only label, broken weights) are caught by the gate.

## Decisions — closed and open

**Closed:** Technology stack — **Laravel 13 + Filament v4 + PHP 8.4** (user decision; ADR-003 superseded by its Annex A). Rule engine kept framework-free per Annex A §6, with a conformance corpus so the offline TS app can be proven to agree · Iran-first launch market · AWP split three ways with the methodology as a **project-level** add-on (ADR-001) · AWP methodology **deferred to the international phase**, constraint engine / lookahead / package-based field execution stay in core (ADR-002) · on-premise primary with no foreign cloud dependency · modular monolith · RabbitMQ default with Kafka optional.

**Open, and now blocking P0:**
1. **Pilot clients** — which NOC or affiliate, and which private EPC? Everything sharpens once these are named.
3. **Contract forms beyond شرایط عمومی پیمان** — do target clients use bespoke NOC forms (POGC, PEDEC) needing preload?
4. **Which NOC numbering scheme to build first** — the first one shapes the engine's flexibility.
5. **GPU budget** — determines whether self-hosted AI ships in P4 or slips.
6. **On-premise support staffing** — the top margin risk, and a headcount question rather than an engineering one.
