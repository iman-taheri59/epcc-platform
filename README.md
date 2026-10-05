# EPCC Suite — Project Workspace

Bilingual (EN/FA) modular platform for large industrial EPCC projects — oil, gas, petrochemical, power.

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
| `prototype/index.html` | Clickable bilingual prototype — 9 screens, live language / company-role / standards-pack switching, collapsible sidebar with mobile drawer. Links to the newer Piping Workspace preview. |
| `prototype/piping-workspace.html` | **Synthetic-data Piping Workspace preview** — task-first UI concept for the piping-first strategy: summary cards, blocker-first filters, readiness bars, deviation inbox, test-pack board and line detail drawer. Self-contained and air-gap safe. |
| `prototype/test/sidebar.test.js` | jsdom behaviour test — 19 assertions covering the sidebar rail/drawer, RTL, keyboard and navigation behaviour |
| `prototype/test/piping-workspace.test.js` | jsdom behaviour test — 16 assertions pinning Piping Workspace localisation, mobile filter layout, card rows, drawer behaviour and air-gap safety |

## Prototype — scope warning

The prototype is **9 screens covering ~15–20% of the specified system** (12 module groups, ~101 functional areas, 176 requirements). It is a static HTML mock-up: **no database, no backend, no authentication, nothing persists.** It exists to prove four hard architectural ideas — the standards pack engine, bilingual RTL, cross-company permissions, and hard release gates — not to preview the product. Full breakdown in `docs/Prototype-Coverage-Map.md`.

## Prototype — what to try

Open `prototype/index.html`. It boots **Persian-first with the IPS / Iran pack active**, as an Iranian customer would see it. Three switches in the header change everything:

1. **English / فارسی** — full RTL mirroring, not just text direction.
2. **Company** — EPC, Owner, PMC, TPI, MC, Subcontractor. Permissions change live: release buttons, punch-close buttons and payment-certificate actions enable or disable, and the access matrix highlights your column.
3. **Standard** — IPS / Iran, ISO, or AWP. KPIs, terminology, document lifecycle, numbering rule and available modules all change. Under IPS and ISO the AWP screens correctly report the module as inactive.

Key screens for a demo:
- **Piping Workspace preview** — open `prototype/piping-workspace.html` directly, or use the Piping Workspace link in the prototype sidebar. Shows the new task-first UX with synthetic PH-14 data.
- **Laravel Piping Workspace** — after `./restore.sh` and login, open `/projects/PH-14/piping/workspace?lang=en` (or `lang=fa`). It is the first task-first workspace in the real app, derived from project line/evidence/joint/test-pack data; hydrotest packs can now be created and lines assigned there.
- **صورت‌وضعیت / Payment Certificate** — the Iranian payment build-up with تعدیل, حسن انجام کار and advance recovery, plus an authority-limit escalation. IPS pack only.
- **IWP Release Gate** — AWP constraint hard-block in action. AWP pack only.
- **Quality & Punch** — MC certificate blocked by failed guards.
- **Access Matrix** — the commercial confidentiality firewall.

**Sidebar:** the ☰ button collapses the sidebar to a 56px icon rail on desktop, and turns it into an off-canvas drawer below 820px — opening from the right in Persian/RTL and the left in English/LTR. The drawer closes on navigation, on scrim tap, and on Escape.

Tested: 324 render combinations (3 packs × 2 languages × 6 company roles × 9 pages), 19 sidebar jsdom assertions, and 16 Piping Workspace jsdom assertions:

```bash
npm install
npm run test:prototype
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
