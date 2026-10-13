# Prototype Coverage Map — what the prototype shows vs what the system is

| Field | Value |
|---|---|
| Document ID | COV-EPCC-001 |
| Date | 2026-09-09 |
| Purpose | Prevent the prototype being mistaken for the product scope |

---

## Short answer

**No.** The prototype shows **9 screens**. The specified system has **12 module groups containing roughly 90 functional areas**, defined by **176 numbered requirements**.

The prototype covers roughly **15–20% of the specified scope**, and it was built that way deliberately. It is a *demonstration of the hard architectural ideas*, not a preview of the product. Everything in it is a static HTML mock-up with hard-coded sample data — no database, no backend, no persistence, no authentication.

---

## What the prototype was actually built to prove

Four things that are hard to explain in a document and easy to get wrong in a build:

1. **The standards pack engine works** — switching IPS / ISO / AWP visibly changes KPIs, terminology, document lifecycles, numbering rules and which modules exist at all.
2. **Bilingual RTL is real** — the whole layout mirrors, not just text direction.
3. **Cross-company permissions are structural** — switching company role visibly enables and disables actions.
4. **Release gates hard-block** — constraint clearance and certificate prerequisites are enforced, not advisory.

Those four are the platform's spine. If they were wrong, everything else would need rebuilding. That is why they were prototyped first.

---

## Coverage by module

Legend: **Shown** = visible in the prototype · **Partial** = a slice visible · **Not built** = specified only

| # | Module group | Functional areas | Prototype | Notes |
|---|---|---|---|---|
| 1 | **CORE** (identity, tenancy, licensing, config, standards engine, i18n, audit, notification, files, search, workflow/BPM, integration bus) | 13 | **Partial** | Standards engine + i18n demonstrated. Identity, tenancy, audit, search, workflow engine, file/CDE service, notifications, integration bus: **none of it exists**. |
| 2 | **PROJECT** (portfolio, setup, WBS/CBS/OBS/PBS, calendars, gates, risk, issue, action, lessons learned, meetings/MoM) | 10 | **Not built** | Breakdown structures appear only as a read-only list on the Work Packaging screen. No project setup wizard, no risk register, no gates UI, no meetings. |
| 3 | **ENGINEERING** (deliverables register, CDE, review & comment, tag/asset register, datasheets, interface mgmt, TQ/RFI, deviation, MTO, BIM viewer, vendor data VDRL) | 11 | **Partial** | Only the deliverables register is shown, as a 5-row static table. No review/comment workflow, no tag register, no TQ, no MTO, no VDRL, no BIM viewer. |
| 4 | **PROCUREMENT** (requisitions, RFQ/bid/tabulation, PO, expediting, inspection coordination, logistics, customs, MRR, warehouse & preservation, surplus) | 10 | **Not built** | Zero screens. Material availability appears only as a constraint indicator on the IWP board. |
| 5 | **CONSTRUCTION** (AWP/WFP, CWP/IWP, constraints, field execution & daily reports, progress & EV, manpower & equipment, scaffolding, subcontract admin, site instructions) | 9 | **Partial** | CWP list, IWP constraint board and Path of Construction shown. No daily reports, no manpower, no scaffolding, no subcontract admin, no field app. |
| 6 | **QUALITY** (ITP, ITR/checksheets, NCR/CAPA, welding & NDT, certificates & traceability, calibration, audits, TPI/MC workflows) | 8 | **Partial** | Punch list and MC gate shown. No ITP builder, no ITR forms, no NCR workflow, no welding/NDT, no calibration, no audits. |
| 7 | **HSE** (PTW, incident, observation, toolbox talk, JSA, training/competency, environmental) | 7 | **Not built** | Zero screens. Permits appear only as a constraint type. |
| 8 | **COMMISSIONING** (system/subsystem breakdown, pre-comm/comm ITRs, punch, MC/RFSU certificates, turnover dossier, performance test, handover & as-built) | 7 | **Partial** | Punch list and a blocked MC certificate shown. No SBS, no dossier builder, no performance test, no handover. |
| 9 | **COMMERCIAL** (contract mgmt, change/variation, claims, progress invoicing, cost control & forecast, cash flow, budget & trend, bonds/guarantees) | 8 | **Partial** | Only the Iranian interim payment certificate (صورت‌وضعیت) with تعدیل. No contract register, no change orders, no claims, no cost control, no cash flow. |
| 10 | **SCHEDULE** (master schedule, P6/MSP sync, lookahead, baselines, delay analysis, Monte Carlo) | 6 | **Not built** | No Gantt, no schedule, no P6 integration. |
| 11 | **ANALYTICS** (dashboards, KPI library, alerts, AI assist, executive & client reporting) | 5 | **Partial** | One static dashboard with pack-driven KPIs. No report builder, no alerts, no AI, no export. |
| 12 | **ADMIN/SAAS** (plans, entitlements, metering, billing, onboarding, support, health) | 7 | **Partial** | Plans page is a marketing table only. No entitlement engine, no metering, no billing, no admin console. |

**Totals: ~101 functional areas specified · 9 prototype screens · 0 backed by real logic.**

---

## What is missing that people usually assume is there

Worth stating explicitly, because these are the things a stakeholder seeing a polished demo tends to assume exist:

| Missing | Reality |
|---|---|
| Database | ~~None.~~ **Now real** for the certificate slice: PostgreSQL 17 with row-level security. The rest of the prototype is still JavaScript literals. |
| Backend / API | None. |
| Login / users / roles | ~~A dropdown.~~ **Now real**: session authentication, users bound to a tenant, and the full ARC §5 six-gate pipeline (entitlement → membership → RBAC → ABAC → SoD) with bilingual, audited denials. |
| Saving anything | Every button is inert. Nothing persists; a refresh resets everything. |
| File upload / viewer | None. No CDE, no versioning, no transmittals. |
| Search | None. |
| Offline field app | None. This is the highest-risk component in the whole product (risk IR-R5) and is entirely unbuilt. |
| Reports / PDF export | **PDF export works** for the MC certificate and the transmittal sheet (headless Chromium + Vazirmatn). Persian typography risk IR-R2 is retired for PDF. No report builder yet. |
| Jalali calendar engine | ~~Hard-coded string.~~ **Now real** (`morilog/jalali`), rendering Persian digits in the Laravel screens. |
| P6 / ERP / LDAP integrations | None. |
| On-premise installer | None. |

---

## What *is* real, beyond the mock-up

Not everything in the workspace is a mock. These artifacts are genuine and would survive into the build:

| Artifact | Status |
|---|---|
| `standards-pack.schema.json` | Real JSON Schema (Draft 2020-12). Enforces pack structure. |
| `ips-iran.pack.json` | Real, complete, schema-valid: 3 lifecycles, 4 gates, 11 KPIs, 11 compliance items, 21 bilingual terms. |
| `iso.pack.json`, `awp.pack.json` | Real, complete, schema-valid. |
| `validate_packs.py` | Working CI gate: schema, bilingual parity, progress-weight integrity, ADR-002 seam guards. All four erosion scenarios verified as caught. |
| `sidebar.test.js` | Working jsdom test, 19 assertions. |
| `packages/standards-engine/` | Real, framework-free rule engine. Fails closed on unknown checks and missing facts; distinguishes *passed* from *ungated*. |
| Conformance corpus v1.2.0 | 25 cases, 7 mutants verified to break it. The engine's executable specification. |
| Laravel app (`app/`) | Real: PostgreSQL 17, 7 migrations, row-level security with FORCE, 44 passing tests / 118 assertions. |
| Multi-project tenancy | Real and enforced in the database: `company × project` predicate, optional project scope, per-project serials, per-project standards pack. |
| SRS / ARC / ADR / market addendum | Specification and decision records — the actual deliverable so far. |

The packs matter most: they are the configuration that the future engine will consume unchanged. The prototype consumes hard-coded copies of that data, but the source files are production-shaped.

---

## Update — 2026-09-09: the Laravel slice

Three screens are no longer mock-ups. `/` (portfolio), `/projects/{code}`
(certificates) and `/gate` (rule playground) run on Laravel 13 + PostgreSQL 17
with real persistence and real tenant isolation.

This deliberately went *deep on one slice* rather than wide. What it buys:

- **Multi-project is proven, not asserted.** Six companies across three
  projects, with two projects sharing a contractor and one project on a
  different standards pack. Isolation is enforced by PostgreSQL, so it holds
  even when the application code is wrong or bypassed.
- **The standards framework is genuinely selectable per project.** KISH-1 runs
  `iso` while PH-14 and BB-2 run `ips-iran` — same code, different verdicts.
- **Coverage is unchanged (~15–20%).** Depth on the tenancy spine does not add
  breadth. PROCUREMENT, HSE, SCHEDULE and PROJECT remain unbuilt.

---

## Update — 2026-09-09 (later): authentication and authorization

`?as=EPC-PARS` is gone. Identity now comes from a session, and the tenant bound
into `epcc.company_id` is the authenticated user's company — so what a screen
renders and what PostgreSQL permits are derived from the same fact.

What landed:

- **Users belong to exactly one company.** That is what makes the RLS key
  derivable from the session rather than the URL.
- **The ARC §5 pipeline, all six gates**, refusing in strict order. Order is
  part of the contract: a non-member is refused at MEMBERSHIP *before* RBAC is
  consulted, so a reply can never be used to probe which capabilities exist on
  a project the caller cannot see.
- **Plans gate modules** (SRS BR-02). Arvand on Starter genuinely cannot open
  the cost module; the same user is allowed `project.view`.
- **Delegation of authority is per person, per project** — not per company.
  This immediately changed real behaviour: PH14-SV-0007 (43.2B) is *cleared*
  for the employer's representative (100B limit) and *blocked* on
  `budgetWithinAuthority` for Pars's own PM (40B limit). The old flat
  per-company limit hid that entirely.
- **Every denial is audited and bilingual** (FR-I18N-01, NFR-SEC-06).
- **A membership cannot be created for a company that is not on the project** —
  enforced by a database trigger, so a bug in a future invite screen cannot
  quietly bypass the commercial firewall.

Coverage is still roughly **15–20%**: this is depth on the access spine, not
breadth. PROCUREMENT, HSE, SCHEDULE and the rest remain unbuilt.

---

## Update — 2026-09-09 (later still): project setup

Until now every project came from a seeder. The platform could enforce access
to projects it could not create. That is closed:

| Screen | Route | What it does |
|---|---|---|
| Create project | `GET/POST /projects/new`, `/projects` | Employer creates a project, **chooses its standards pack**, invites companies |
| Project team | `GET/POST /projects/{code}/team` | Lists participating companies and staffed people; adds your own colleagues |

Four rules the screens enforce, each covered by a test that has been shown to
fail when the rule is removed:

1. **Only an employer tenant may create a project.** Checked twice — capability
   `project.create` *and* `tenant_type === 'OWN'` — because a plan
   misconfiguration must not be able to promote a contractor into an employer.
2. **The pack is chosen once, at creation, from what the plan entitles.** A
   Starter company sees ISO only, and posting `awp` directly is refused, not
   merely hidden. Changing the pack later is deliberately not offered: live
   documents are numbered and gated against the pack that created them.
3. **The plan's `max_projects` quota is enforced** at the point of creation.
4. **You may only add people from your own company.** An employer must not be
   able to plant a user inside a contractor's tenant.

Creation runs in a single transaction that writes the project, the owner's
`project_company` row, the partner rows and the creator's membership together.
The owner participation row is not decorative: without it the RLS predicate
hides the new project from the company that just created it.

### A fail-open found by building this screen

Adding a create flow produced the first user whose memberships did **not**
mirror their company's participation — and that immediately exposed a real
hole on the *read* path. Both `CertificateController` and the new team screen
scoped visibility by **company** alone. A brand-new employee at EPC-PARS, with
zero project memberships, could load the portfolio and read PH-14's 43.2B
certificate on their first login, because their employer was on the project.

Gate 3 (MEMBERSHIP) existed in `AuthorizationPipeline` and was correct; the
read controllers simply never consulted it. The demo fixture had concealed the
gap for weeks, because every seeded user happened to be staffed on exactly the
projects their company participated in.

Both queries now require company participation **and** a personal membership.
Three regression tests pin it, and each was verified to fail (200 where 404 is
required) when the membership filter is mutated away.

> **Company participation is a commercial fact, not an individual grant.**
> Staffing a person onto a project is a deliberate act and must be recorded as
> one. A fixture where the two always coincide will hide the difference.

Suite after this work: **106 Laravel tests / 421 assertions** (up from 84), plus
corpus 25/25, 324 renders, sidebar 19/19 — all green.

---

## Update — 2026-09-10: the document register, its files, and the CDE

The `doc` module moved from "sold but rendering *Soon*" to a working vertical
slice. It is the first module built after the sidebar told customers it
existed, which is why it jumped the queue ahead of QA/QC.

**Built**

| Requirement | State | Notes |
|---|---|---|
| FR-ENG-01 deliverables register | **Working** | Real table, RLS-scoped, bilingual titles. |
| FR-ENG-02 numbering | **Working** | `{PROJECT}-{DISCIPLINE}-{TYPE}-{SEQ:4}`, sequence per (project, discipline, type). |
| FR-ENG-03 CDE states | **Working** | ISO 19650 machine; illegal transitions refused server-side. Suitability codes validated per state. |
| FR-ENG-04 revisions | **Working** | New revision archives the old one; supersede is a record, not an erasure. |
| FR-DOC-01 file storage | **Working** | Content-addressed (SHA-256), allow-listed types, 100 MB ceiling, integrity verification. |
| FR-DOC-02 retrieval | **Partial** | Streamed through authorization with correct disposition; PDFs/images open in the browser's own viewer. **No in-app viewer, no annotation.** |

| FR-ENG-06 review & comment | **Working** | Parallel multi-reviewer, 6 categories, dispositions with mandatory justification on rejection, closure statistics, and a publication gate. |

**Still not built in this module:** full-text and OCR search, BIM/CAD
rendering, vendor data (VDRL), tag register, TQ/RFI.

**The correction worth recording.** The register initially used the standard
tenant policy — you see your own rows, the employer sees everything. Every test
passed. Then a logged-in PMC, a genuine participant holding `doc.view`, opened
the register and saw *nothing*. A common data environment scoped like private
commercial data is not a CDE. Migration `130000` makes the **CDE state** the
sharing boundary: WIP stays with its author (and the employer), everything
Shared or beyond is visible to every participant. Writing is still restricted
to your own rows.

This is the second time on this project that a module passed its tests while
being wrong for the user in front of it. Both were caught by signing in as each
role and looking, and neither would have been caught otherwise.

**Coverage after this slice: roughly 30–35%** of the specified scope.
Acceptance journey 3 ("bilingual document review cycle") now runs end to end
except for the transmittal and the PDF: EPC shares Rev B → PMC and the owner
comment in parallel in Persian → EPC dispositions → publication is refused
until the blocking comments are answered. That is the first of the eight
journeys to work as a real workflow rather than a demonstration.

---

## Update — 2026-09-10 (later): transmittals close journey 3

| Requirement | State | Notes |
|---|---|---|
| FR-ENG-05 transmittals | **Working** | Multi-recipient formal issue, immutable once sent, per-company acknowledgement, derived overdue tracking against a per-purpose response clock, bilingual ZIP package with a checksummed manifest. |
| FR-ENG-05b vendor transmittals | **Working** | Second, separate stream for supplier letters: own `VTR` number series, own screen, the originating vendor named separately from the EPC that recorded it, roll-up into a contractor letter with a permanent `source_item_id` chain, and RLS that keeps supply-chain correspondence out of the Employer's view unless they are named a recipient. |

**Acceptance journey 3 now runs end to end** — create, attach, share, review
with blocking comments, dispose, publish, transmit, acknowledge — with the
single exception of PDF rendering, which is a stack question (headless Chrome)
rather than a domain one.

**Two corrections worth recording.**

*A draft is not correspondence.* The first RLS policy granted visibility to the
sender, the named recipients and the employer. That reads correctly and is
wrong: it applies from the moment the letter is **drafted**, so an addressee
could read a letter the sender had not sent yet and was still editing. The
index page looked fine because the seeded recipient was also the employer —
the two claims coincided, exactly the fixture trap recorded earlier in this
document. Recipient and employer visibility is now gated on `issued_at`.

*A passing test can encode the wrong model.* `the employer sees every letter on
its project` asserted a count of 3 and was green throughout. It was asserting
the leak. Fixing the policy turned it red, and the correct response was to fix
the **test**, which now asserts both halves: the employer sees every issued
letter, and does not see another company's draft.

**Coverage after this slice: roughly 35–40%** of the specified scope. The
`doc` module is now the deepest in the product; QA/QC is the largest sold
module still rendering *Soon*.

---

## Slice: QA/QC — punch list & Mechanical Completion (FR-COM-04/05)

Implements **acceptance journey 5**, whose criterion is a refusal:

> An MCC cannot be issued with open A-items unless an Owner-approved exception
> is recorded.

Built: `subsystems`, `punch_items`, `mc_certificates` (all RLS-FORCEd),
`PunchCategory` / `PunchState` as the rule and lifecycle tables,
`PunchListService` (role rules from the SRS §4.1 matrix), `McCertificateService`
(the gate), a bilingual register with per-subsystem gate cards, and 35 tests.

**Three findings worth recording.**

*A gate needs a legitimate way through it.* The exception is not a weakening of
the rule; it is what stops the rule being bypassed outside the system. It is
employer-only, needs a written reason, and snapshots the waived items as
`jsonb` — because the value of the record is that it says *which* A-items the
owner accepted, at the moment they accepted them.

*The fixture could not express the journey.* PH-14 had an owner, contractors
and a consultant, but no management contractor (مدیریت پیمان) — so "the contractor cannot
verify its own fix" had no independent verifier to contrast with. Adding
`MC-KARAN` was a prerequisite for the slice, not a detail of it.

*A guard test can be caught by the wrong guard.* That same self-verification
test passed with its guard deleted, because the acting company was an `EPC`,
which is not a verifier tenant type at all — a different rule threw, and
`expectException()` cannot tell two refusals apart. Assert on the message.

**Coverage after this slice: roughly 40–45%** of the specified scope. The
COMMISSIONING and QUALITY groups move from *prototype-only* to partially real.
`awp` is the last module still rendering *Soon*.

---

## Why not prototype everything?

Building all ~101 areas as mock-ups would take months and prove nothing new. Mock-ups have sharply diminishing returns: the tenth static register teaches you nothing the first one didn't.

The remaining risk in this product is not "can we draw a procurement screen". It is:

1. Does Persian PDF generation hold up for formal documents? (IR-R2)
2. Does the offline field app survive a real site? (IR-R5)
3. Does the on-premise air-gapped install work at a customer datacentre? (AG-06)
4. Does the pack engine stay generic under three years of feature pressure? (ADR-002)

None of those are answered by more HTML. They are answered by **spikes in P0** — which is exactly what the roadmap allocates.

---

## If broader coverage is wanted anyway

Two legitimate reasons to extend the prototype, with different answers:

**For a sales demo** — add screens with the highest buyer emotion for the Iranian market, in this order: (1) صورتجلسه / site minutes, (2) NCR workflow, (3) daily progress report, (4) contract & guarantee register with expiry alerts. Roughly one day each as static mock-ups.

**For engineering validation** — stop adding screens. Build one thin vertical slice end-to-end instead: real database, real auth, real standards-engine resolution, one register with genuine CRUD and workflow. One real slice de-risks more than twenty mock screens.

My recommendation is the second, unless there is a specific pitch meeting driving the first.

---

*End of document — COV-EPCC-001*
