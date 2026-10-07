# ADR-003 — Technology stack

> **Status — 2026-10-05:** Superseded by `ADR-003 Annex A — Laravel evaluated as the backend stack`. The TypeScript/NestJS recommendation is retained as decision history, not as the current implementation target. Current stack: Laravel 13 + Filament v4 + PostgreSQL, with the framework-free standards engine preserved for future offline/TypeScript parity.


| Field | Value |
|---|---|
| Document ID | ADR-EPCC-003 |
| Date | 2026-09-09 |
| Status | **Proposed** — closes ARC-EPCC-001 §12 AD-1 |
| Context | Iran-first launch (MKT-EPCC-001). On-premise/air-gapped primary. Modular monolith confirmed (AD-2). |
| Decision | **TypeScript end-to-end: NestJS + React + PostgreSQL.** .NET 10 is the credible alternative and the switch condition is stated in §6. |
| Amended by | **[ADR-003 Annex A — Laravel evaluation](ADR-003-Annex-A-Laravel-Evaluation.md)**, which downgrades §3.1, makes Laravel the primary alternative, and adds a sixth P0 spike. Read the annex with this document. |

---

## 1. The constraints that actually decide this

Under an Iran-first, on-premise, air-gapped launch, the usual stack criteria (raw throughput, framework fashion) are not the binding ones. These four are:

| # | Constraint | Consequence |
|---|---|---|
| C1 | **Air-gapped install, zero egress** | Everything must be self-hostable and mirrorable. Rules out anything with a mandatory cloud service or license-server call-home. |
| C2 | **Commercial tooling is hard to pay for from Iran** | Sanctions and banking make JetBrains/Visual Studio subscriptions impractical at team scale. A stack that *requires* paid IDEs is a recurring procurement problem, not a one-off. |
| C3 | **The offline field app must re-run server rules locally** | The PWA has to evaluate pack guards (constraint clearance, form validation, required attributes) with no connectivity, and reach the same verdict the server will on sync. |
| C4 | **Small team, on-premise support burden** | Every install is customer-operated at a different version (risk IR-R4). Fewer runtimes to package, patch and debug remotely is a direct cost saving. |

C3 is the one most often underestimated, and it is the deciding technical argument below.

---

## 2. Decision

| Layer | Choice | Version at build start |
|---|---|---|
| **Language (both tiers)** | TypeScript 5.x, strict mode | — |
| **Backend** | NestJS (modular monolith) on Node.js | Node 24 Active LTS (supported to Apr 2028) |
| **Frontend** | React 18 + Vite, logical-CSS design system | — |
| **Field app** | Same React codebase as an installable PWA, Dexie (IndexedDB) + Workbox | — |
| **Database** | PostgreSQL 18 with Row-Level Security | 18.x (EOL Nov 2030) |
| **ORM / migrations** | Drizzle or Prisma — decide at P0 spike | — |
| **Cache / jobs** | Redis + BullMQ | — |
| **Message bus** | RabbitMQ (Kafka optional, never required — ADR from MKT §3) | — |
| **Search** | OpenSearch, EN + FA analyzers | — |
| **Object storage** | MinIO (S3-compatible API) | — |
| **Identity** | Keycloak, bundled; federates to customer LDAP/AD | — |
| **Analytics store** | ClickHouse (add at P4, not P1) | — |
| **PDF generation** | Server-side headless Chromium + a Persian-shaping pipeline — **must be spiked in P0** | — |
| **OCR** | Tesseract with Persian traineddata, self-hosted | — |
| **Packaging** | Docker Compose (small installs) and k3s (HA), Helm chart, signed offline bundles | — |
| **Internal mirrors** | Verdaccio (npm) + a registry mirror for images | — |

Every item is open-source, self-hostable, and has no license-server dependency. That is not a coincidence; it is C1 applied as a filter.

---

## 3. Why TypeScript end-to-end rather than .NET

.NET 10 is genuinely strong here — LTS to November 2028, excellent performance, first-class PostgreSQL support, and a large developer population in Iranian enterprises. This is not a landslide. Three specific things decide it:

### 3.1 The offline field app shares rule code with the server (C3)

> **Downgraded by Annex A §4.** The claim below that this is *the* deciding argument does not survive scrutiny: the pack rules are declarative JSON, so the duplicated part is a ~1–2 kLOC interpreter whose equivalence can be enforced by a shared conformance corpus. Treat this section as a modest advantage, not a decider.

The field PWA must, with no connectivity:
- validate a dynamic ITR form against its pack-defined schema
- decide whether a work package's guards are satisfied
- enforce required-attributes-per-state before allowing a local save

The server runs exactly the same rules on sync. In a TypeScript stack, that logic is **one package imported by both tiers**. Two implementations of the same rules that must never disagree is a defect generator, and the disagreements surface as data conflicts on a construction site with no support engineer nearby.

With .NET you would either duplicate the rule engine in TS for the client, or compile C# to WASM (Blazor) and accept a heavier offline bundle on the low-end Android tablets we are explicitly targeting. Both are worse.

The standards packs are already JSON with a JSON Schema. A TypeScript rule evaluator over that JSON, shared by server and client, is the natural shape of this system.

### 3.2 Free toolchain end to end (C2)

VS Code, Node, Postgres, Keycloak, MinIO — no paid seats, no license procurement, no sanctions-exposed subscription renewals. .NET itself is MIT, but the productive C# experience in practice means Visual Studio or Rider, both commercial. For a team in Tehran that is a recurring friction we can simply avoid.

### 3.3 One runtime to package and support (C4)

Air-gapped bundles, offline update channels, and remote debugging of customer-operated installs all get cheaper with a single runtime and a single dependency mirror. With two ecosystems you maintain two mirrors, two vulnerability-scanning pipelines and two patching cadences, for the same product.

### What we give up, honestly

- **Raw CPU throughput.** .NET is faster. For this workload — document workflow, registers, approvals — the bottleneck is PostgreSQL and file conversion, not the app tier. Heavy work (conversion, OCR, analytics) runs in separate workers regardless of language.
- **Long-running CPU-bound work.** Node's single-threaded model is a poor fit; mitigated by putting conversion/OCR/report generation in dedicated worker processes, which the architecture already requires.
- **Enterprise familiarity.** Some Iranian NOC IT departments are Microsoft shops and will ask. The answer is that the product ships as containers and they operate it as a black box — the runtime inside is our concern, not theirs.

---

## 4. Notable per-layer reasoning

**PostgreSQL 18 with RLS** — RLS is the tenant-isolation backstop from ARC §5.1. It stays even when running single-tenant on-premise, because retrofitting it later for the eventual SaaS phase would be enormous (risk IR-R8). JSONB carries pack-driven dynamic attributes without schema churn.

**Keycloak bundled** — works fully offline, speaks SAML/OIDC/LDAP/SCIM, and federates to the Active Directory that nearly every Iranian enterprise already runs.

**RabbitMQ default, Kafka optional** — a mandatory Kafka cluster is an unreasonable operational burden for a single-node customer install. The outbox pattern means the bus is swappable.

**ClickHouse deferred to P4** — do not pay the operational cost of a second datastore until the analytics volume justifies it. PostgreSQL handles P1–P3 reporting.

> **Update 2026-09-10 — resolved.** The spike demanded below was built and
> passed. Headless Chromium 152 + `fonts-vazirmatn` renders the MC certificate
> and transmittal sheet correctly in both languages; `PdfRenderer` is the
> integration point and `tests/Feature/PdfExportTest.php` is the regression
> suite. Chromium is now a **runtime dependency of the installer** (~315 MB)
> and must be added to the air-gap bundle. It runs with networking disabled.

**PDF generation is the highest-risk item on this list.** Persian shaping, ligatures, ZWNJ and mixed LTR/RTL in formal documents (صورت‌وضعیت, تحویل موقت) is where most software in this market visibly fails (risk IR-R2). The stack choice does not solve it. **P0 must produce a golden-image test suite proving Persian PDF output before the doc-generation architecture is committed.**

---

## 5. What must be spiked in P0 before this is locked

| Spike | Question it answers | Fails if |
|---|---|---|
| Persian PDF pipeline | Can we render formal bilingual documents correctly and repeatably? | Shaping/ZWNJ defects that golden-image tests can't eliminate |
| Shared rule-evaluator package | Does one TS pack-rule evaluator genuinely run server-side and in the PWA? | The shared package needs per-tier forks — this would materially weaken §3.1 |
| Air-gapped install | One-command install from a signed offline bundle, zero egress | Any component demands network at install or runtime |
| ORM choice | Drizzle vs Prisma under RLS with per-request tenant context | Neither cleanly supports session-scoped RLS variables |
| Offline sync on low-end Android | 7 days offline, 500 MB cache, deterministic replay | Cache eviction or conflict replay loses field data |
| **Conformance corpus** (added by Annex A) | Can a JSON case corpus pin evaluator behaviour so two implementations provably agree? | Verdicts can't be expressed as data — only then does §3.1 regain full weight |

---

## 6. What would change this decision

State it now so it is revisited on evidence, not preference:

1. **The team we can actually hire is .NET-heavy.** This is the single most likely reversal and a legitimate one — a stack the team writes well beats a marginally better stack they don't. If the first five engineers are strong C# and weak TS, switch the backend to .NET 10 and accept a duplicated rule evaluator in the PWA.
2. **The P0 shared-rule-evaluator spike fails.** §3.1 is the main technical argument; if it doesn't hold, the decision is much closer and team familiarity should win.
3. **A launch client contractually mandates a Microsoft stack.** Rare, but some state-affiliated IT departments have standards. Containers usually make this moot.
4. **The team is PHP-heavy, or we are hiring from scratch in Iran.** Added by Annex A, and *more* likely than condition 1. Build on Laravel 13 + Filament v4; see Annex A §6.
5. **CPU-bound work turns out to dominate.** If profiling in P2 shows the app tier is the bottleneck rather than Postgres and workers, revisit.

**Not a reason to change:** benchmark posts, framework popularity, or a preference for static typing — TypeScript in strict mode already provides that.

---

## 7. Open question for the founders

This ADR is written as a recommendation from first principles because **I do not know the team**. Point 6.1 can override everything above.

Before locking: what does the engineering team look like — existing C#/.NET people, existing JS/TS people, or hiring from scratch in Tehran/Isfahan? That answer is worth more than any argument in §3.

---

*End of document — ADR-EPCC-003*
