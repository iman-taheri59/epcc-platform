# Iran-First Market Addendum
## Revisions to SRS-EPCC-001 and ARC-EPCC-001

| Field | Value |
|---|---|
| Document ID | MKT-EPCC-001 |
| Version | 1.0 |
| Date | 2026-09-09 |
| Status | Decision record — supersedes conflicting statements in SRS §15 Q1 |
| Decision | **Primary launch market is Iran.** International SaaS is a later phase, not a parallel one. |

---

## 1. What this decision changes

The SRS was written market-neutral, treating on-premise deployment, Persian UI and local billing as *options*. They are no longer options. This addendum promotes them to constraints and demotes several previously-assumed capabilities.

| Area | Was (market-neutral SRS) | Now (Iran-first) |
|---|---|---|
| **Primary topology** | Shared multi-tenant SaaS | **On-premise / in-country single-tenant**, air-gap capable. SaaS is the secondary topology. |
| **Default language** | English, Persian at parity | **Persian is the default locale**; English at parity but secondary in UI defaults, sample data and support |
| **Primary calendar** | Gregorian, Jalali secondary | **Jalali (Solar Hijri) primary**, Gregorian secondary |
| **Standards packs at launch** | ISO + AWP | **ISO + IPS/Iranian pack** at launch; AWP moves to P3 (few Iranian clients mandate it yet) |
| **Billing** | Card-based self-serve subscription | **Annual invoice in IRR, PO/bank transfer**, formal invoice via the tax e-invoicing system. No self-serve checkout at launch. |
| **Third-party cloud services** | Assumed available (managed DB, SendGrid, Stripe, OpenAI, Google Fonts) | **None assumable.** Every dependency must be self-hostable or locally sourced. |
| **AI assist** | Vendor API | **Self-hosted model only.** No feature may depend on a foreign inference API. |
| **ERP integration targets** | SAP, Oracle, Dynamics | **Hamkaran System, Rayvarz, Sepidar, Didgah** first; SAP later |
| **Licence enforcement** | Online entitlement checks | **Offline signed licence files** with grace behaviour; no call-home requirement |

---

## 2. Deployment model (revised)

### 2.1 Reference topology — the default sale
```
Customer datacentre (NIOC / NIGC / NPC / EPC contractor HQ)
┌──────────────────────────────────────────────────────────┐
│  Docker Compose or lightweight K8s (k3s) — single node    │
│  or 3-node HA depending on project size                   │
│                                                           │
│  App (modular monolith)  ·  Keycloak  ·  PostgreSQL       │
│  MinIO (object store)    ·  OpenSearch  ·  Redis          │
│  RabbitMQ (not Kafka)    ·  Conversion workers            │
│  Optional: self-hosted LLM (CPU/GPU node)                 │
│                                                           │
│  Ingress: customer's own domain + customer's own TLS      │
│  Egress: NONE required. Air-gap supported.                │
└──────────────────────────────────────────────────────────┘
        ▲
        │ Offline update channel: signed .tar bundle on USB / internal mirror
        │ Licence: signed offline file, hardware-bound, time-limited
```

**Sizing guidance to publish with the product:**

| Project size | Users | Spec |
|---|---|---|
| Small (single plant, one contractor) | ≤150 | 8 vCPU, 32 GB RAM, 1 TB SSD, single node |
| Medium (multi-discipline EPC) | ≤600 | 16 vCPU, 64 GB RAM, 4 TB SSD, 3 nodes |
| Mega (phase-scale, multi-company) | ≤2000 | 32+ vCPU, 128 GB RAM, 10 TB+, 3-node HA + separate conversion node |

### 2.2 Air-gap requirements (hard)
- **AG-01** The installer bundle SHALL contain every container image, font, dictionary, map tile set and model weight. Zero network fetch at install time.
- **AG-02** No component may block, degrade, retry-loop or log-spam when outbound internet is unavailable.
- **AG-03** Updates SHALL be delivered as a signed offline bundle, verifiable with a public key shipped at install; the customer verifies the signature before applying.
- **AG-04** Telemetry SHALL be opt-in and, when off, produce no outbound connection attempts whatsoever.
- **AG-05** Licence validation SHALL work fully offline. Expiry degrades to read-only + export, never to data loss or lockout of safety records (see BR-10).
- **AG-06** A pre-flight `verify-airgap` command SHALL scan the running system for outbound connection attempts and fail the acceptance test if any are found. This is part of the customer acceptance procedure, not just internal QA.

### 2.3 Hosted option (for private-sector contractors)
For EPC contractors and consultants who don't require in-house hosting, offer a managed instance on **Iranian cloud infrastructure** (ArvanCloud, ParsPack, or the customer's preferred local provider) — still single-tenant, still no foreign dependency. This becomes the volume play for mid-size contractors while NOCs take on-premise.

---

## 3. Dependency substitution table

Every foreign SaaS assumption in ARC-EPCC-001 §10 is replaced. This table is the definitive build constraint.

| Function | Removed | Replacement | Notes |
|---|---|---|---|
| Cloud infra | AWS / Azure / GCP | Customer datacentre; ArvanCloud / ParsPack for hosted | No managed-service dependency anywhere |
| Object storage | S3 | **MinIO** (S3-compatible API) | Same code path both topologies |
| Identity | Azure Entra, Google Workspace | **Keycloak** bundled + LDAP/AD federation | Most Iranian enterprises run AD |
| Email | SendGrid / SES | **Customer's own SMTP relay** (Exchange / Zimbra) | Configured at install |
| SMS | Twilio | **Kavenegar / SMS.ir / Ghasedak / Melipayamak** — pluggable connector | SMS matters here; site users rely on it |
| Push / chat | Teams, Slack | **Bale, Eitaa, internal Rocket.Chat/Mattermost** connectors | Teams connector kept for companies that use it |
| Payments | Stripe / Paddle | **Bank IPG via Shaparak PSP** (Saman, Mellat, Parsian) or pure invoice/bank transfer | Self-serve checkout deferred |
| Invoicing / tax | Foreign e-invoicing | **National tax e-invoicing system (سامانه مودیان)** connector | Confirm current schema and thresholds with your tax advisor before build |
| Fonts | Google Fonts CDN | **Vazirmatn bundled locally** (SIL OFL) + a Latin face, self-hosted | Never a CDN reference — breaks air-gap and preview |
| Maps / GIS | Google Maps | **Neshan / Balad tiles**, or offline tile packs, or plain CAD-plot backdrops | Site plot plans usually beat web maps anyway |
| Message bus | Kafka (managed) | **RabbitMQ**, with DB-outbox-only mode for the smallest installs | Kafka stays supported but never required |
| AI inference | OpenAI / Anthropic API | **Self-hosted open-weight model** with Persian capability, on customer hardware | If no GPU budget, AI features are simply disabled — never a broken dependency |
| OCR | Cloud vision APIs | **Tesseract with Persian traineddata** + layout model, self-hosted | Persian OCR quality must be benchmarked in P0 |
| CAD/BIM conversion | Autodesk Platform Services | **Self-hosted converters** (IFC.js, ODA-based or open toolchain) | Licensing of any commercial converter must be verified as usable in-country |
| Error tracking | Sentry SaaS | **Self-hosted Sentry / GlitchTip** or plain log shipping | |
| Package registries at build | npm/nuget public | **Internal mirror (Verdaccio / Nexus)** | Build reproducibility under intermittent access |

**Rule to enforce in CI:** a dependency-audit job SHALL fail the build if any runtime code references a hostname outside the allowlist. This is how the air-gap guarantee stays true over 3 years of feature work, rather than eroding.

---

## 4. Localization requirements (elevated from SRS §6.2.2)

### 4.1 Calendar — now a first-class scheduling concern, not a display filter
- **FA-CAL-01 (M)** Jalali is the default input and display calendar; Gregorian toggleable per user. Storage stays UTC/ISO-8601 (unchanged).
- **FA-CAL-02 (M)** **Nowruz shutdown** (approximately 1–13 Farvardin, exact dates set annually) SHALL be modelled as a first-class non-working period in every calendar, propagating through schedule, EVM, SLA timers, permit validity and constraint promise dates. This is the single largest recurring schedule event in Iranian projects and cannot be a manually-entered exception each year.
- **FA-CAL-03 (M)** Lunar Hijri holidays shift ~11 days per solar year — the holiday set SHALL be **data-driven and updatable without a software release** (an editable calendar file the customer's planning department maintains).
- **FA-CAL-04 (M)** Default working week **Saturday–Wednesday**, with Thursday configurable as full/half/off — per company, since site and head-office weeks differ. Site rotations (e.g. 24/6, 20/10) SHALL be modellable as resource calendars.
- **FA-CAL-05 (M)** Dual-date display on all printed documents: Jalali primary, Gregorian in parentheses, on certificates, transmittals, MoM and invoices.

### 4.2 Persian text handling
- **FA-TXT-01 (M)** Normalize Arabic ك/ي to Persian ک/ی on input and in the search index; both forms must return the same results.
- **FA-TXT-02 (M)** Correct ZWNJ (نیم‌فاصله) handling in storage, search tokenization and PDF rendering.
- **FA-TXT-03 (M)** Digits stored ASCII, displayed per user preference (۰-۹ or 0-9). **Document numbers, tag numbers and quantities always render ASCII** to stay machine-readable and match drawings.
- **FA-TXT-04 (M)** Persian ICU collation for all sorting. Never byte order.
- **FA-TXT-05 (M)** Mixed LTR/RTL rendering must be correct for the common Iranian case: Persian sentence containing an ASCII tag number, e.g. «نصب اسپول در خط 12-PG-2201 تکمیل شد».
- **FA-TXT-06 (M)** Persian PDF generation with correct shaping and ligatures, verified against a golden-image test suite — this is where most Persian software fails.

### 4.3 Persian-first product surface
Sample data, project templates, in-app help, training material, error messages and the sales demo all ship **Persian-first**. The English build is generated from the same resource pipeline and must stay at parity, but Persian is what the customer sees on day one.

---

## 5. New standards pack: IPS / Iranian framework

A third pack ships at launch alongside ISO. It `extends` the ISO pack and overlays the Iranian regulatory and contractual model.

**Delivered as `standards-packs/ips-iran.pack.json`.** Contents:

| Domain | Iranian expression |
|---|---|
| Technical standards | **IPS (Iranian Petroleum Standards)** discipline series — engineering document classes, review codes and approval routes aligned to IPS practice |
| Contract framework | **شرایط عمومی پیمان (نشریه ۴۳۱۱)** lifecycle: پیش‌پرداخت (advance), صورت‌وضعیت موقت (interim payment certificate), تعدیل (price adjustment), حسن انجام کار (retention), تحویل موقت (provisional acceptance), دوره تضمین (warranty period), تحویل قطعی (final acceptance) |
| Pricing basis | **فهرست‌بهای واحد پایه** (annual unit-rate schedules) as a measurement basis alongside lump-sum |
| Price adjustment | **تعدیل** using quarterly published index coefficients — computed, not manually keyed |
| Governance | **نظام فنی و اجرایی کشور** gate model for state-funded projects |
| HSE | Ministry of Petroleum **HSE-MS** structure |
| Document coding | Preloaded numbering schemes for **NIOC, NIGC, NPC, NIORDC, NISOC, POGC, PEDEC** |
| Approval parties | کارفرما / مشاور / پیمانکار / ناظر مقیم / بازرس شخص ثالث mapped onto the platform's company types |
| Certificates | صورتجلسه, گواهی تحویل موقت, گواهی تحویل قطعی — bilingual templates |

**Why this is the commercial centre of gravity:** an EPC contractor working for NIGC does not care that the platform supports ISO 19650. They care that it produces a correct صورت‌وضعیت with تعدیل and a compliant تحویل موقت package. The IPS pack is the reason they buy; ISO and AWP are the reason they keep it as they win international work.

---

## 6. Commercial model (revised)

### 6.1 Pricing and billing
- **Currency:** IRR (quoted in تومان, invoiced per tax requirements). Multi-currency retained in the product for contractors with foreign scope.
- **Cycle:** annual, invoiced up front, PO/bank-transfer based. Multi-year with discount for NOCs on budget cycles.
- **Tax:** VAT per current rate; formal invoice submitted through the national tax e-invoicing system. **Confirm the current API schema, thresholds and filing obligations with a local tax advisor before implementation** — this system's requirements change and I won't guess at specifics.
- **No self-serve card checkout at launch.** Enterprise sales motion with a pilot, not product-led growth.

### 6.2 Plan structure adjusted for the market
The four-tier structure holds, with two changes:

1. **AWP is split three ways rather than bundled or sold per company.** The constraint engine, rolling lookahead and field package execution stay **in the core** (they are general infrastructure — the IPS pack already depends on the constraint engine). The AWP methodology proper — CWA/CWP/EWP/PWP/IWP hierarchy, Path of Construction, AWP KPIs and maturity assessment — is a **project-level add-on** enabling every participating company on that project, never a per-company entitlement. Model-based scoping stays with the BIM viewer add-on. Full reasoning and falsifiers in **ADR-EPCC-001**. This answers SRS §15 Q6.
2. **A new "Project Licence" is the flagship SKU, not an afterthought.** The Owner (typically the NOC or its project company) buys one licence covering every participating company for the project's duration. This matches how Iranian mega-projects are actually funded and removes the impossible task of separately selling to 15 subcontractors.

| SKU | Buyer | Shape |
|---|---|---|
| **Project Licence** (flagship) | Owner / NOC / project company | All companies, all modules, project duration, on-premise, IRR, one invoice |
| Full | Large EPC contractor | Per-company annual, all modules |
| Professional | Mid-size contractor / consultant | Per-company annual, core execution modules |
| Starter | Small consultant / vendor | Entry point, doc control focused |
| **AWP Pack** (project-scoped) | Owner or EPC driving the methodology | Enables AWP for **all companies on that project** regardless of their own tier. See ADR-EPCC-001. |
| Add-ons (per company) | Any | BIM viewer (incl. model-based scoping), Claims, extra storage, extra environments, self-hosted AI node |

### 6.3 Licence enforcement without call-home
Signed offline licence file, bound to a hardware/install fingerprint, carrying: tenant, plan, module entitlements, user/project quotas, expiry. Renewal is a new file. Expiry → read-only plus full export. Never a lockout, never a phone-home requirement. This is both an air-gap necessity and a trust signal to buyers who are wary of foreign software behaviour.

---

## 7. Integration priorities (re-ranked)

| Priority | Integration | Was | Reason for change |
|---|---|---|---|
| **M — P1** | Excel/CSV import-export, everywhere | M | Unchanged. This is how Iranian projects actually exchange data today. |
| **M — P1** | Primavera P6 (XER/XML file-based) | M | Keep file-based, **drop dependency on P6 EPPM API/licensing**. XER round-trip is what's practically usable. |
| **M — P1** | LDAP / Active Directory | S | Elevated — near-universal in Iranian enterprises |
| **M — P2** | National tax e-invoicing system | S | Elevated — legal requirement for our own billing |
| **M — P2** | Local SMS gateways | S | Elevated — primary field notification channel |
| **S — P2** | Iranian ERP (Hamkaran, Rayvarz, Sepidar, Didgah) | — | New. Replaces SAP as the realistic first ERP target. |
| **S — P3** | National PKI digital signature | S | Held. Required for legally-weighty signatures (تحویل موقت, صورت‌وضعیت). |
| **S — P3** | AVEVA / Hexagon / Autodesk model import | S | Held — file-based ingestion only, no vendor cloud API |
| **C — P4** | SAP / Oracle ERP | S | Demoted — relevant only for the later international phase |
| **W** | Aconex, ProjectWise, Power BI cloud | S | Dropped from the Iran roadmap. Offer a local BI connector (Metabase/Superset self-hosted) instead. |

---

## 8. Roadmap impact

| Phase | Change from SRS §13 |
|---|---|
| **P0 Discovery** | Add: Persian PDF/OCR spike, Jalali calendar engine spike, air-gap install spike. Recruit **one NOC-affiliated pilot and one private EPC pilot** before P1 exit. |
| **P1 Core** | Unchanged scope, but Persian-first and **on-premise installer is a P1 deliverable, not P5**. Ship ISO pack + **IPS pack**. |
| **P2 Execution** | Add صورت‌وضعیت + تعدیل to P2 (moved forward from P4) — this is the highest-value module for the Iranian buyer and shortens time-to-first-revenue. |
| **P3 AWP** | AWP pack lands here as a paid add-on rather than core. |
| **P4 Commercial & Analytics** | Claims and portfolio analytics; self-hosted BI connector. |
| **P5 Scale & International** | Now explicitly the *internationalization* phase: multi-tenant SaaS hardening, GDPR posture, English-first marketing, SAP/Aconex connectors, foreign cloud topology. |

**Net effect:** faster to first Iranian revenue (صورت‌وضعیت in P2 instead of P4), later to international readiness. That is the correct trade given the decision.

---

## 9. Risks specific to this decision

| ID | Risk | Impact | Mitigation |
|---|---|---|---|
| IR-R1 | **Dependency erosion** — a developer adds a CDN font or cloud API in month 14 and silently breaks air-gap | Critical | CI hostname allowlist audit (§3) + `verify-airgap` acceptance test on every release |
| IR-R2 | **Persian PDF/OCR quality** below acceptable for formal documents | High | **PDF half RETIRED (2026-09-10).** Headless Chromium + Vazirmatn proven end to end: joining forms, ZWNJ, bidi-embedded Latin tags, Persian digits and Jalali dates all correct in the issued MC certificate and transmittal sheet. Font embedding and subsetting verified with `pdffonts`. OCR remains unproven. |
| IR-R3 | Commercial CAD/BIM converter licensing unobtainable or unusable in-country | Medium-High | Design the viewer behind an interface; ship open IFC path as baseline, commercial converter as pluggable |
| IR-R4 | **On-premise support burden** — dozens of customer-operated installs, each on a different version, no remote access | High | Strict LTS release train (2 releases/year + patches), bundled diagnostics export, remote-assist only by customer-initiated session |
| IR-R5 | Payment collection and FX for any foreign tooling we ourselves need | Medium | Budget for local-currency operations; minimize our own foreign SaaS dependencies for the same reason we minimize the product's |
| IR-R6 | Building only for Iran creates architecture that can't go international | Medium | The pack engine and i18n pipeline already abstract this. Rule: **nothing Iran-specific goes in the core** — it goes in the IPS pack or a connector. Enforced at code review. |
| IR-R7 | Self-hosted AI quality in Persian disappoints, damaging credibility | Medium | Ship AI **off by default**, positioned as assist not authority. Never let an AI feature be load-bearing for a workflow. |
| IR-R8 | Single-tenant sprawl makes the eventual SaaS pivot expensive | Medium | Keep RLS and tenant-scoping in the data layer from day one even when running single-tenant — cost is near zero now, retrofit is enormous |

---

## 10. Decisions now closed, and what's still open

**Closed by this addendum:**
- SRS §15 Q1 — launch market: **Iran-first** ✔
- SRS §15 Q6 — AWP packaging: **three-way split — constraint engine/lookahead/field execution in core; AWP methodology as a project-level add-on; model-based scoping with the BIM viewer** (ADR-EPCC-001) ✔
- SRS §15 Q7 — hosting: **on-premise/in-country primary, local cloud secondary, no foreign cloud** ✔
- ARC §12 AD-2 — **modular monolith confirmed** (on-prem packaging makes this decisive) ✔
- ARC §12 AD-3 — **Kafka optional, RabbitMQ default** ✔

**Still open and now more urgent:**
1. **Pilot clients.** Which NOC or affiliate, and which private EPC? Everything in P0 sharpens once these are named.
2. ~~**Backend stack** (ARC AD-1) — needs the team hiring plan. .NET and NestJS both package fine for on-prem; pick on available talent in Tehran/Isfahan.~~ **CLOSED (2026-10-11 note):** decided via ADR-003 Annex A — Laravel 13 + Filament v4 + PHP 8.4 + PostgreSQL 17, already implemented.
3. **Contract forms beyond شرایط عمومی پیمان** — do target clients use bespoke NOC forms (POGC, PEDEC have their own) that need preloading?
4. **Which NOC document numbering scheme to build first** — they differ, and the first one built shapes the numbering engine's flexibility.
5. **GPU budget for self-hosted AI** — determines whether AI ships in P4 or slips.
6. **Support model staffing** — on-premise LTS support is a headcount question, not an engineering one, and it's the top margin risk.

---

*End of document — MKT-EPCC-001 v1.0*
