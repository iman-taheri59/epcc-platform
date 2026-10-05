# ADR-003 Annex A — Laravel evaluated as the backend stack

> **Status — 2026-10-05:** Accepted and implemented as the current stack direction. The application under `app/` is Laravel/Filament. Parent architecture/SRS documents must treat this Annex as superseding the stack section of ADR-003.


| Field | Value |
|---|---|
| Document ID | ADR-EPCC-003-A |
| Date | 2026-09-09 |
| Status | **ACCEPTED — 2026-09-09.** The founders chose Laravel. This annex now supersedes ADR-003's decision line; ADR-003 is retained for its constraint analysis. |
| Question | Should the platform be built on Laravel (PHP) instead of NestJS (TypeScript)? |
| Short answer | **Laravel is the strongest alternative on the table — stronger than .NET 10 — and it wins outright if the team is PHP-heavy.** It does not win on technical merit alone, and the margin is much narrower than ADR-003 implied. |

> **This annex weakens a claim made in ADR-003 §3.1.** That section called the shared rule evaluator "the deciding technical argument." Evaluating Laravel forced a re-examination and the argument is worth less than stated. §4 below explains why, and §7 amends ADR-003 accordingly.

---

## 1. Why Laravel was not in ADR-003, and why that was a mistake

ADR-003 compared TypeScript against .NET 10 only. That framing came from the wrong question — "which enterprise runtime?" — when the binding question for an Iran-first launch is "which stack can we staff, and which one gets ~101 functional areas of registers, forms and approvals built fastest?"

Judged on those two questions, PHP/Laravel is the largest developer population in the Iranian market and has the best CRUD-scaffolding story of any candidate. Omitting it was an oversight, not a considered exclusion.

---

## 2. Where Laravel is genuinely better than the ADR-003 choice

These are real advantages, not consolation points.

### 2.1 Filament v4 against a system that is mostly registers

Recount what COV-EPCC-001 describes: 12 module groups, ~101 functional areas. Strip out the rule engine, the offline field app and the document renderer, and the large majority of what remains is *list → filter → form → approve → audit → export*: deliverables register, MDR, transmittals, RFIs, NCRs, punch lists, ITRs, material requisitions, POs, expediting logs, contracts, guarantees, صورت‌وضعیت lines, HSE incidents, permits.

That is exactly Filament v4's target. It is stable since August 2025, built on Livewire + Alpine + Tailwind v4, and ships resources, relation managers, policy-driven authorization, bulk actions with per-record authorization, native MFA, and panel-level multi-tenancy without third-party packages ([1](https://filamentphp.com/insights/leandrocfe-whats-new-in-filament-v4), [2](https://www.reddit.com/r/laravel/comments/1mob1xf/filament_v4_is_now_stable/)). Its v4 rewrite specifically targeted large-table rendering performance ([3](https://richdynamix.com/articles/filament-v4-zero-to-production-dashboard-complete-guide)).

Honest estimate: for the register-shaped 60–70% of the SRS, a Filament build is plausibly **2–3× faster to first working screen** than hand-written NestJS + React. Not 20% faster. That is a large number against a 176-requirement backlog and a small team.

The caveat is stated in §3.2 — Filament is server-rendered, so it buys nothing for the field app.

### 2.2 Dependency supply chain and air-gap (constraint C1)

This is where Laravel is *clearly* better and ADR-003 did not consider it.

A NestJS + React + Vite install pulls a `node_modules` tree in the high hundreds to low thousands of packages. A Laravel + Filament install is typically 100–200 Composer packages. For a product whose stated requirement is signed offline bundles, an internal mirror, and per-release vulnerability scanning (MKT AG-01…AG-06):

- Composer mirroring via a self-hosted Satis instance is small, static, and boring. Verdaccio mirroring a thousand-package npm tree is neither.
- npm has a recurring history of supply-chain compromises through transitive dependencies. Packagist's smaller, less-transitive graph is a materially smaller attack surface. For air-gapped installs at state-affiliated clients, this is a procurement-conversation asset, not a footnote.
- Fewer packages means fewer CVEs to triage every release for every customer running a different version (risk IR-R4).

### 2.3 Iranian ecosystem packages already exist

`morilog/jalali` is a mature, widely used Jalali date library with a Carbon bridge ([4](https://packagist.org/packages/morilog/jalali)) — and COV-EPCC-001 records that the prototype's Jalali date is currently a hard-coded string, i.e. this is unbuilt work. Kavenegar, SMS.ir, Iranian payment gateways and سامانه مودیان clients all have maintained Laravel/PHP SDKs, generally maintained *by Iranian developers* — which also means the documentation and the support channel are in Persian. The TypeScript equivalents exist but are thinner and less maintained.

### 2.4 Familiar operations for the customer's IT department

MKT-EPCC-001 assumes customer-operated on-premise installs. Iranian enterprise IT departments know PHP. Even shipping as containers (which we will), the day a support call requires the customer's own sysadmin to reason about what is running, PHP is more legible to them than a Node process tree.

### 2.5 Performance is no longer an argument against PHP

Laravel Octane with FrankenPHP or Swoole delivers roughly **2.5–3× the throughput of PHP-FPM** with P50 latency dropping from ~45 ms to ~15 ms, by keeping the framework booted in memory ([5](https://deploynix.io/blog/php-fpm-vs-laravel-octane-on-deploynix-real-world-performance-comparison), [6](https://dev.to/deploynix/php-fpm-vs-laravel-octane-on-deploynix-real-world-performance-comparison-53gp)). For our workload — document workflow at tens to low hundreds of concurrent users per install — even plain PHP-FPM is sufficient. Performance does not decide this.

Queue ergonomics are also a genuine Laravel strength: Horizon plus database/Redis queues is more mature and more operable than BullMQ, and PHP's process-per-job model handles the long-running CPU-bound work (PDF, OCR, report generation) *better* than Node, which ADR-003 conceded is Node's weak spot.

---

## 3. Where Laravel is worse

### 3.1 Support windows are short, and our installs are long-lived

This is the most underrated problem and it is specific to our deployment model.

| Framework/runtime | Released | Security support ends | Window |
|---|---|---|---|
| Laravel 13 | Mar 2026 | Q1 2028 | ~2 years |
| Laravel 12 | Feb 2025 | Feb 2027 | 2 years |
| Node 24 LTS | 2024 | Apr 2028 | ~3.5 years |
| .NET 10 LTS | Nov 2025 | Nov 2028 | 3 years |

Laravel gives **18 months of bug fixes and 24 months of security fixes for every release** — there has been no LTS release since Laravel 6 ([7](https://laravel-news.com/laravel-13), [8](https://v12.x.laravel.wiki/en/releases)). PHP itself is fine (8.4 security to Dec 2028, 8.5 to Dec 2029 ([9](https://www.zend.com/resources/php-eol))); the framework is the constraint.

Consequence: an air-gapped install commissioned in 2027 and expected to run untouched until 2031 will be on an unsupported Laravel major within two years. We would owe every customer a framework major upgrade roughly annually — negotiated, scheduled, and validated, across installs we cannot reach remotely. Laravel majors are deliberately gentle these days (Laravel 13 is explicitly a stability-first release, minimum PHP 8.3 ([7](https://laravel-news.com/laravel-13))), so each upgrade is small. But "small and annual, times N customers, offline" is a permanent operational tax that Node 24 LTS and .NET 10 LTS do not levy.

**This is the single strongest argument against Laravel for this product**, and it is an argument about our deployment model, not about PHP.

### 3.2 Filament and the offline field app do not compose

Livewire and Filament are server-rendered: every interaction is a round trip. The field app must work for 7 days with no connectivity on low-end Android tablets (ARC offline sync; risk IR-R5). Those two facts are irreconcilable.

So the real Laravel architecture is not "Laravel + Filament." It is:

- **Laravel + Filament** for the office-side registers and the admin/SaaS console, plus
- **A separate React PWA** for the field, talking to a Laravel API.

Two frontends, two UI toolkits, two design-token pipelines, two RTL implementations. That is a genuine cost, and it partly eats the §2.1 productivity win. It is *not* fatal — the two audiences are genuinely different, and one could argue the split is honest rather than accidental — but it must be priced in, and the bilingual RTL design system now has to be built twice.

### 3.3 Type safety across the tier boundary

TypeScript strict mode gives one type system spanning API contract, client and shared rules. PHP 8.4 typing plus PHPStan level 9 is much better than PHP's reputation but stops at the HTTP boundary; the React PWA's view of the API is generated or hand-written, not shared. Real, but manageable with generated OpenAPI clients.

### 3.4 PostgreSQL RLS is less well-trodden

> **RESOLVED 2026-09-09 — see `docs/Spike-RLS-Results.md`.** The spike passed. Eloquent
> + session-scoped RLS works with no loss of ORM ergonomics, and the predicted
> connection-reuse hazard was real: a committed `SET LOCAL` leaves the GUC as `''`
> rather than NULL, which broke unbound queries on reused connections. Fixed with
> `NULLIF`. Two further defects (audit actor/subject conflation, non-singleton tenant
> context) were also found. **Still to do: re-run under Octane**, which is where this
> class of bug bites hardest.
>
> **Updated 2026-09-09 (multi-project).** Extending the spike from one project to
> many found two more defects, both recorded in §8 of the spike results: an
> RLS predicate scoped by company alone ignored project participation, and a
> nested `runAs()` leaked its scope back to the caller because `SET LOCAL` is
> transaction-scoped, not savepoint-scoped. Both are fixed and pinned by tests.
> The second is precisely the "connection reuse means `SET LOCAL` discipline has
> to be exactly right" hazard this section predicted — it appeared without
> Octane, which strengthens rather than weakens the case for the Octane re-run.
>
> **CLOSED 2026-09-09 (Octane).** Re-run against FrankenPHP with 2 workers:
> 400 interleaved and 240 concurrent cross-tenant requests produced **zero
> leaks**, and a fresh request on a reused worker starts with both GUCs null.
> The counterfactual was also demonstrated — a plain `SET` leaked one tenant's
> identity into eight subsequent requests and exposed its certificates to an
> unbound reader. Measured **2.8× p50** over `artisan serve`, inside the
> predicted band. See `docs/Spike-RLS-Results.md` §9. This section's concern was
> correct and is now mitigated by rule, not by luck.


Eloquent with session-scoped RLS variables and per-request tenant context is a less common pattern than in the Node ecosystem, and Eloquent's connection reuse under Octane makes `SET LOCAL` discipline something to get exactly right. This is a spike, not a blocker — but if Laravel is chosen, the ORM spike in ADR-003 §5 becomes an **Eloquent-under-Octane-with-RLS** spike and it gets more important, not less.

### 3.5 A wash, not a loss: Persian PDF

PHP has native shaping libraries — mPDF handles Arabic-script shaping — but its own maintainers direct users to headless Chrome when CSS fidelity matters ([10](https://github.com/mpdf/mpdf)), and comparisons agree that headless Chromium is the quality answer for complex layouts ([11](https://peterdev.pl/picking-a-php-tool-to-generate-pdfs/)). Since we already decided on headless Chromium, and Chromium is an external process either way, **the stack choice does not affect risk IR-R2 at all.** Neither stack helps; the golden-image test suite is required regardless.

---

## 4. The argument this annex overturns

ADR-003 §3.1 claimed that sharing the rule evaluator between server and PWA as one TypeScript package was "the deciding technical argument." Testing that claim against Laravel exposes a flaw in it.

The pack rules are **already declarative JSON**, validated by `standards-packs/schema/standards-pack.schema.json`. Guards, criteria, lifecycles, release gates, numbering rules and progress weights are *data*, not code. What the evaluator does is walk that data and return a verdict.

That means the duplicated component is not "the business logic." It is a **small interpreter over a fixed, versioned data format** — realistically on the order of 1,000–2,000 lines per implementation. And critically, an interpreter over a declarative format is exactly the kind of thing you can prove equivalent:

> **Shared conformance corpus.** Define the evaluator's contract as a set of JSON cases — `(pack, object state, context) → expected verdict + expected reasons`. Both implementations run the same corpus in CI. Divergence becomes a build failure, not a field data conflict.

Build that corpus and the "two implementations will drift" fear is largely retired — for Laravel *and* for .NET. It costs perhaps 2–3 weeks up front plus discipline forever after, against a shared-package approach that costs nothing extra but forces the whole backend into one language.

That is a much weaker basis for a stack decision than ADR-003 presented. I am flagging it rather than letting it stand.

The residual TypeScript advantage is real but narrower: no corpus to maintain, no risk of a subtle interpreter divergence that the corpus fails to cover, and one language for new engineers to learn.

---

## 5. Scored against the four deciding constraints

| Constraint (ADR-003 §1) | TypeScript / NestJS | Laravel / PHP | .NET 10 |
|---|---|---|---|
| C1 air-gapped, zero egress | Good — but a large npm tree to mirror and scan | **Best** — small Composer graph, trivial Satis mirror | Good; NuGet mirrors fine, VS 2026 tooling adds friction |
| C2 free toolchain | **Best** — VS Code, zero paid seats | **Best** — VS Code + PHP, zero paid seats | Worst — productive C# in practice means paid Rider/VS |
| C3 offline app shares rules | **Best** — one shared package | Adequate **with a conformance corpus** (§4) | Adequate with the same corpus, or heavy Blazor WASM |
| C4 one runtime to package/support | **Best** — one runtime | Two runtimes (PHP + Node for asset build), one deployed | Two runtimes |
| **Long-term support window** | Good — Node 24 to Apr 2028 | **Worst** — ~2 years per Laravel major, annual upgrades on offline installs | Best — .NET 10 to Nov 2028 |
| **Build speed for ~101 registers** | Baseline | **Best by a wide margin** (Filament v4) | Baseline |
| **Iran hiring pool** | Good | **Best** | Good in enterprises, weaker for product work |
| **Long CPU-bound jobs** | Worst (mitigated by workers) | **Best** (process-per-job, Horizon) | Best |
| **Type safety end to end** | **Best** | Adequate (PHP 8.4 + PHPStan L9) | Best |

No column sweeps. That is the honest result.

---

## 6. Recommendation

> **OUTCOME (2026-09-09): Laravel was chosen.** The build is scaffolded and running — see
> `/app` and `/packages/standards-engine`. The §6 recommendation below is kept as the
> record of *why*. Note that the decision was made without the team-composition answer
> the annex asked for, so **if the engineering team turns out to be TS-heavy, revisit
> this** — the conformance corpus and the framework-free engine were built specifically
> so that reversal stays affordable.

**Do not change ADR-003 unilaterally. Make the team answer decide, and state the rule now so the decision is not re-litigated later.**

| If the founding engineering team is… | Then build on | Because |
|---|---|---|
| **PHP/Laravel-heavy** (the most likely case in this market) | **Laravel 13 + Filament v4 + PostgreSQL 18, React PWA for field only** | The §2.1 delivery-speed advantage plus native fluency beats every remaining TypeScript advantage. Accept the annual-upgrade tax and manage it (§8). |
| **JS/TS-heavy** | NestJS + React, as ADR-003 states | Unchanged. |
| **Hiring from scratch in Tehran/Isfahan** | **Laravel** | Deepest local pool, fastest ramp, cheapest to replace an engineer. §3.1 becomes the price of admission, not a veto. |
| **C#-heavy** | .NET 10 | As ADR-003 §6.1 already says. |

**In every branch:** the packs stay declarative JSON, the field app stays a React PWA, PostgreSQL 18 + RLS stays, and the **shared conformance corpus of §4 becomes mandatory** unless the backend is TypeScript.

If the answer is Laravel, one additional recommendation: **do not put the standards-pack rule engine inside Filament.** Keep it as a framework-agnostic PHP package with no Laravel dependencies, driven only by the pack JSON and the conformance corpus. It is the most valuable and longest-lived code in the product; it should outlive any framework decision, including this one.

---

## 7. Amendments to ADR-003

1. **§3.1 is downgraded** from "the deciding technical argument" to "a real but modest advantage, worth roughly the cost of building and maintaining a conformance corpus."
2. **§5 spike list gains a sixth entry** — *build the conformance corpus and a second reference interpreter* — which must be run before any non-TypeScript backend is committed. If Laravel is chosen, the ORM spike is restated as Eloquent + session-scoped RLS under Octane.
3. **§6 reversal conditions gain a fifth:** *the team is PHP-heavy, or we are hiring from scratch in Iran.* This replaces "a .NET-heavy hire" as the most likely reversal, because it is more likely.
4. **Laravel replaces .NET 10 as the designated primary alternative.**

---

## 8. If Laravel is chosen, mitigate §3.1 deliberately

The short support window is manageable, but only on purpose:

- Pin one Laravel major per **product** release train, not per customer. All customers on product v2.x run Laravel 13; the move to Laravel 14 is a product v3.0 event with its own validation cycle.
- Budget a framework-upgrade sprint every 12 months as a standing line item, not an interruption.
- Keep the domain core (rule engine, pack resolution, numbering, progress) in framework-free PHP packages, so a Laravel major upgrade touches the HTTP/admin layer and not the part of the system where correctness matters.
- Contract the support window honestly with clients: state which framework version an install carries and when it stops receiving security patches. Silence here becomes an audit finding at an NOC affiliate.

---

*End of document — ADR-EPCC-003-A*
