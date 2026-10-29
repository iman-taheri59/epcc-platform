> **Status 2026-10-26 — single source of truth.** Dated delivery/correction
> banners were stacked here (40 in README, 42 in HANDOFF) until no reader could
> tell which statements were still current. They are preserved verbatim, newest
> first, in [docs/STATUS-BULLETIN.md](docs/STATUS-BULLETIN.md). This table says
> what is true now; the bulletin says how we got here.

| Area | State |
|---|---|
| Piping material cycle — MR→PO separation, PO draft/pricing, MTO journal, scope admission | Implemented on `arena/piping-material-cycle-2026-10-26` (ADR-032…041) |
| Receipt chain MRR→QC→QA→controlled GRN + physical custody/amendments | Implemented (ADR-046 + custody guides) |
| Unified stock ledger & warehouse cutover | **Slice 1 implemented (ADR-047 ACCEPTED, service-only)**: append-only journal + ordinal clock + GRN auto-journaling + insufficient-balance guard + dual-person authority; legacy non-piping writes gated by `EPCC_WAREHOUSE_LEGACY_WRITES_REFUSED` (default off until opening physical counts are posted). Role-scoped authority policies, per-project cutover register and HTTP/UI = declared next slices. See `docs/STOCK-LEDGER-EN.md` ([فارسی](docs/STOCK-LEDGER-FA.md)) |
| ITP inspection notifications — draft/issue/cancel/company ack/prerequisites/bilingual PDF | Implemented (ADR-042…045) |
| Inspection & test result records — weld/NDT per joint, hydro per test pack, MTR registry | **Slice 1 implemented (ADR-048 ACCEPTED, service-only)**: four append-only RLS+FORCE tables + ordering/lineage guards (FITTED-only, weld-before-NDT, READY-pack-only, rejected/failed lineage) + two-mode provenance (`IN_SYSTEM`/`EXTERNAL_REGISTER`, CHECK-paired identity) + exact-heat MTR reader. **Slice 2 parts 1-2 (A.4b, A.4c) implemented**: recording is default-deny behind versioned, suspendable nominations authored only by the nominated company or the project employer; pack states are held to the evidence (TESTED needs a result, ACCEPTED needs the latest result to be PASS, ACCEPTED is terminal); recorded pressures must be PRESSURE units from the registry. **Slice 2 part 3 (A.4d) implemented**: ITP hold/witness sign-offs on notification stages — the stage and its point are derived, signing is default-deny behind a nomination, an outcome that changes the work must say why, and a HOLD point can be waived only by the project employer. See `docs/ITP-STAGE-SIGN-OFFS-EN.md` ([فارسی](docs/ITP-STAGE-SIGN-OFFS-FA.md)). **Slice 2 part 4 (A.4e) implemented**: an MTR can only be bound to custody that attests its exact (lot, heat) — derived, never declared — or kept as unresolved with a reason, and the coverage report answers which received heats have no certificate. See `docs/MTR-CUSTODY-BINDING-EN.md` ([فارسی](docs/MTR-CUSTODY-BINDING-FA.md)). Still declared: HTTP/UI and the legacy `material_lots` reconciliation = A.4f; ITR compiler = A.5. See `docs/INSPECTION-RESULTS-EN.md` ([فارسی](docs/INSPECTION-RESULTS-FA.md)) |
| ITR compiler — test-pack dossiers & frozen snapshots | **Slice 1 implemented (ADR-048 decision 5, service-only)**: on-demand compilation + `itr_snapshots` (append-only RLS+FORCE, trigger-derived dossier/completeness/sha256 digest — client content never declarable), explicit completeness report (`ITR_COMPILATION_NOT_A_CERTIFICATE`; gaps enumerated, never hidden), optional bilingual PDF via the existing Chromium/Vazirmatn renderer. Subsystem/dossier scopes, notification/receipt/MTR binding and the signature layer = declared slice 2 (A.5b). See `docs/ITR-COMPILER-EN.md` ([فارسی](docs/ITR-COMPILER-FA.md)) |
| Traceability, line numbering, unit registry, demand reader | Implemented; demand-reader reinstall semantics covered by `DemandReaderTest` |
| Tests | Domain suites green on a correctly provisioned Postgres (see `.env.example` note: worker-isolation tests require a `/tmp/epcc-*` socket host and `epcc_test`); full suite 1963 OK / 24 skipped on main @ `d257468` (last full local run; the full suite now runs in CI on every push while local verification stays targeted — see the cadence note); A.4e verified by 273 related tests / 1632 assertions; per-slice counts live in the bulletin |
| CI | **Revived and green** on `e19aee3` (run 40370676791, 6/6 jobs, 2026-10-26): `.github/workflows/ci.yml` now provisions native PG17 on the runner with a `/tmp/epcc-pg` socket for worker-isolation tests and forces `APP_DEBUG=false`. A future job failing in ~3s with zero steps means the account billing/spending wall, not the code. |
| Completion roadmap | [docs/TODO-COMPLETION-ROADMAP-EN.md](docs/TODO-COMPLETION-ROADMAP-EN.md) ([فارسی](docs/TODO-COMPLETION-ROADMAP-FA.md)) — detailed task list from the 2026-10-26 process assessment (phases 0/A–G, immediate review debts included), indexed against twelve **PROPOSED** ADRs 047–058 awaiting owner decisions |

# EPCC Suite — Project Workspace

Bilingual (EN/FA) modular platform for large industrial EPCC projects — oil, gas, petrochemical, power.

> **Latest feature:** [Purchase order details](docs/PURCHASE-ORDER-DETAILS-EN.md) ([فارسی](docs/PURCHASE-ORDER-DETAILS-FA.md)) — click an order number in Procurement for lines/amendments, delivery dates, lots and inspections. **54 targeted tests/227 assertions PASS**. Full suite is not rerun/scheduled per change under the latest user instruction; previous1169/17146 is historical pre-feature proof. No new role/schema/write authority.

> **Faster delivery cadence:** bundle useful feature work, run affected tests, keep essential docs short; full suite/recovery packaging only when justified by milestones/risk/release, not every continuation.


## Contents

> **Market decision: Iran-first.** On-premise/air-gapped deployment, Persian-default UI, Jalali calendar and IRR billing are constraints, not options. See `docs/Iran-First-Market-Addendum.md` — it supersedes conflicting statements elsewhere.

| Path | What it is |
|---|---|
| `HANDOFF.md` | **START HERE.** Living handoff — what works today, the architecture rules you must not break, the preview-iframe traps, testing discipline, next steps, and a session log. Updated every session. |
| `docs/SRS-EPCC-Platform.md` | Software Requirements Specification (SRS-EPCC-001) — scope, personas, access model, 13 module groups, 18 disciplines, NFRs, licensing, roadmap, risks |
| `docs/Architecture-and-Data-Model.md` | Architecture (ARC-EPCC-001) — module manifest contract, standards engine resolution, 6-gate authorization pipeline, canonical data model, offline sync, RTL architecture, event catalogue, tech selection |
| `app/` | **The Laravel 13 application** — real code. **Authentication + ARC §5 six-gate authorization**, **project setup** (create a project, choose its standards pack, invite companies, staff people), **multi-project** portfolio + per-project workspaces, tenant isolation enforced by PostgreSQL RLS, bilingual FA/EN, real Jalali dates. `php artisan serve` |
| `compose.yaml` + `scripts/dev-*.sh` | **One-command development environment** — PostgreSQL 17 and PHP 8.4 with Composer, Node 22, headless Chromium, poppler and Vazirmatn, all in containers. `scripts/dev-up.sh`, then sign in at <http://localhost:8000>. No PHP, Composer, Node or PostgreSQL needed on the host |
| `scripts/run-tests.sh` | **Degraded-environment test runner** — for sandboxes without Docker/PHP: runs the standards-pack validator (auto-venv) and all five jsdom prototype suites, and reports loudly what it skipped and why. `bash scripts/run-tests.sh` |
| `scripts/sandbox-pg.sh` | **Real PostgreSQL inside a Docker-less sandbox** — provisions PG 16.2 from the `pgserver` wheel with a non-superuser `epcc` role, `epcc`/`epcc_test` databases and a source-compiled `btree_gist`. `bash scripts/sandbox-pg.sh start\|status\|stop`. The PHP tier still requires the Docker image (see `docs/IMPROVEMENT-PROPOSALS-EN.md` P-19). |
| `scripts/preview-server.mjs` | **Zero-dependency live preview server** for the air-gap-safe prototype (no CDN, no build step). `node scripts/preview-server.mjs` → <http://localhost:8080> (`/` main prototype, `/piping-workspace.html`, `/healthz`) |
| `docs/DEVELOPMENT-ENVIRONMENT.md` | **How to run everything locally** — the container stack, what the first boot does, the four `scripts/dev-*.sh` commands, CI, troubleshooting, and the two traps that break the suite silently: exporting a Laravel variable into the container, and letting the application role be a PostgreSQL superuser |
| `docs/LARAGON-DEVELOPMENT-ENVIRONMENT.md` | **Windows / Laragon setup (no Docker)** — PHP 8.4 in Laragon, the non-superuser `epcc` role, the forbidden-SQLite rule, `filament:assets`, demo accounts, and the P-20 note that PDF export needs POSIX tools |
| `packages/standards-engine/` | **Framework-free PHP rule engine** (`epcc/standards-engine`). Zero Laravel dependency by design — ADR-003 Annex A §6. Evaluates pack release gates |
| `packages/standards-engine/conformance/` | **Language-neutral conformance corpus** (25 cases, v1.2.0) + runner + mutation-testing record. The contract that lets the PHP server and the future TS offline app provably agree |
| `docs/Spike-RLS-Results.md` | **P0 spike result** — Eloquent + PostgreSQL RLS. PASS, with five defects found and fixed (empty-GUC cast, audit actor vs subject, non-singleton context, company-only predicate ignoring project participation, nested-scope leak). §8 multi-project, **§9 Octane — CLOSED (0 leaks / 640 requests, 2.8× p50)**, **§10 PgBouncer transaction pooling — CLOSED (safe; a plain `SET` leaks across client connections)** |
| `docs/Infra-Cost-Model-Iran.md` | **Server cost model in تومان** — per-stack RAM footprint, Iranian cloud/dedicated/hardware price anchors (Sep 2026), three sizing scenarios, and what actually reduces the bill |
| `docs/ADR-003-Annex-A-Laravel-Evaluation.md` | **Annex to ADR-003** — Laravel/Filament assessed as the backend. Makes Laravel the primary alternative to TypeScript, overturns the ADR-003 §3.1 argument, and ties the choice to the team's actual language |
| `docs/ADR-003-Technology-Stack.md` | **Decision record** — TypeScript end-to-end (NestJS + React + PostgreSQL 18), why not .NET, the five P0 spikes that must pass, and the reversal conditions |
| `docs/Prototype-Coverage-Map.md` | **Read before demoing** — module-by-module map of what the prototype shows vs what the system specifies, and what is deliberately absent |
| `docs/MTO-REQUIREMENTS-FA.md` / `docs/MTO-REQUIREMENTS-EN.md` | **Piping MTO Foundation requirements** — reconciled from the uploaded 2026-10-06 MTO docs package against the actual current branch. Material Library, MTO document/revision/staging, Slice 4 immutable accepted snapshots, and Slice 5 gross/net comparison are implemented; PO order gap and usable-material decision remain later slices. |
| `docs/ADR-002-Defer-AWP.md` | **Decision record** — AWP methodology deferred to the international phase; the two architectural seams that keep revival at ~6–10 weeks instead of a rewrite |
| `docs/ADR-001-AWP-Packaging.md` | **Decision record** — how AWP is decomposed and licensed: constraint engine in core, AWP methodology as a project-level add-on, model-based scoping with the BIM viewer. Includes the falsifiers that would reverse it. |
| `docs/ADR-015-PO-Attribution-and-Order-Gap.md` | **Requirements record (IMPLEMENTED — awaiting runtime verification)** — MTO Slice 6: PO line material attribution, effective issued/in-force quantities, amendment lineage, explicit no-proration scope allocation, and the order-gap board. All open questions closed 2026-10-11; code + screen + `OrderGapTest` landed the same day (§9). |
| `docs/IMPROVEMENT-PROPOSALS-EN.md` | **Living improvement-proposals register** — standing rule 5: better alternatives for requirements/code/capabilities, each with a status (OPEN / ACCEPTED / REJECTED / BUILT). |
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
- **Order Gap Board preview** — `prototype/order-gap-board.html` (or the sidebar link). The Slice 6 screen language validated before build: accepted gross vs effective ordered per material, shortage / covered / over-plan / unmatched states, draft-PO exclusion, no proration — bilingual FA/EN, 19 jsdom assertions.
- **Laravel Piping Workspace** — after `./restore.sh` and login, open `/projects/PH-14/piping/workspace?lang=en` (or `lang=fa`). It is the first task-first workspace in the real app, derived from project line/evidence/joint/test-pack data; hydrotest packs can now be created and lines assigned there.
- **صورت‌وضعیت / Payment Certificate** — the Iranian payment build-up with تعدیل, حسن انجام کار and advance recovery, plus an authority-limit escalation. IPS pack only.
- **IWP Release Gate** — AWP constraint hard-block in action. AWP pack only.
- **Quality & Punch** — MC certificate blocked by failed guards.
- **Access Matrix** — the commercial confidentiality firewall.

**Sidebar:** the ☰ button collapses the sidebar to a 56px icon rail on desktop, and turns it into an off-canvas drawer below 820px — opening from the right in Persian/RTL and the left in English/LTR. The drawer closes on navigation, on scrim tap, and on Escape.

Tested: 324 render combinations (3 packs × 2 languages × 6 company roles × 9 pages), 19 sidebar jsdom assertions, and 16 Piping Workspace jsdom assertions:

```bash
npm install
npm run test:prototype      # jsdom prototype suites
bash scripts/run-tests.sh   # every suite this machine can run (+ loud skip report)
npm run preview             # live preview of the prototype on :8080
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



## MTO requirements note — 2026-10-06

The uploaded package `epcc-platform-mto-docs-changes-2026-10-06.txt` was analyzed as a requirements source, not applied as a direct replacement. It contains complete replacement documents from a different baseline and mentions several MTO/PMS/MR capabilities that are **not implemented** in this branch. The reconciled, branch-safe requirements are in `docs/MTO-REQUIREMENTS-FA.md` and `docs/MTO-REQUIREMENTS-EN.md`.

Current implementation status: Material Library foundation is built with a two-level catalogue shape, project overlays, synthetic PH-14 piping catalogue, RLS isolation and tests. MTO document/revision/staging is built as DRAFT-only rows with material validation. Slice 4 creates immutable accepted MTO batches and row snapshots atomically. Slice 5 records provenance-bearing net estimates and reports exact gross/net shortages or deltas without tolerance. Next implementation direction: PO attribution/order gap, and only after a user decision, usable-material gap.

## Decisions — closed and open

**Closed:** Technology stack — **Laravel 13 + Filament v4 + PHP 8.4** (user decision; ADR-003 superseded by its Annex A). Rule engine kept framework-free per Annex A §6, with a conformance corpus so the offline TS app can be proven to agree · Iran-first launch market · AWP split three ways with the methodology as a **project-level** add-on (ADR-001) · AWP methodology **deferred to the international phase**, constraint engine / lookahead / package-based field execution stay in core (ADR-002) · on-premise primary with no foreign cloud dependency · modular monolith · RabbitMQ default with Kafka optional.

**Proposed 2026-10-26 (awaiting owner decisions):** ADR-047…058 — warehouse cutover & stock ledger (047), inspection/test results & ITR (048), minimal workflow transition ledger (049), notification transport & delivery ledger (050), schedule integration & progress measurement (051), sourcing/vendors/RFQ (052), commercial close: change orders/invoicing/3-way match/payment (053), cost commitment/actual/EVM (054), work packages/IWP release (055), field capture & offline sync (056), system completion & turnover dossier (057), external-party portals (058). Each lists explicit `key=option` decision points with a recommendation; sequencing lives in [docs/TODO-COMPLETION-ROADMAP-EN.md](docs/TODO-COMPLETION-ROADMAP-EN.md) ([فارسی](docs/TODO-COMPLETION-ROADMAP-FA.md)).

**Open, and now blocking P0:**
1. **Pilot clients** — which NOC or affiliate, and which private EPC? Everything sharpens once these are named.
2. **Contract forms beyond شرایط عمومی پیمان** — do target clients use bespoke NOC forms (POGC, PEDEC) needing preload?
3. **Which NOC numbering scheme to build first** — the first one shapes the engine's flexibility.
4. **GPU budget** — determines whether self-hosted AI ships in P4 or slips.
5. **MTO acceptance UI/workflow** — the service-only all-or-nothing accepted snapshot slice is built; decide when the first user-facing workspace should expose it.
6. **Usable-material definition** — decide which warehouse/inspection/reservation states count as usable for the future physical material gap.
7. **On-premise support staffing** — the top margin risk, and a headcount question rather than an engineering one.
