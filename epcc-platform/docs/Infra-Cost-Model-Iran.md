# Infrastructure cost model — Iran (per stack option)

| Field | Value |
|---|---|
| Document ID | COST-EPCC-001 |
| Date | 2026-09-09 |
| Status | Working estimate — prices sampled Sep 2026, all figures in **تومان** |
| Relates to | ADR-EPCC-003 + Annex A (stack), MKT-EPCC-001 (on-premise/air-gap), COV-EPCC-001 |
| Headline | **The stack choice moves the server bill by roughly 5–8%. The architecture choices move it by 40–60%.** Choosing a stack on hosting cost would be optimising the wrong variable. |

> **A note on currency:** everything below is in تومان at prices sampled in September 2026. I have deliberately **not** converted to USD/EUR — the rate is volatile enough that a conversion would imply a precision this model does not have. Re-sample vendor pricing before any of these numbers goes into a customer proposal.

---

## 1. First: who actually pays?

This has to be settled before any number means anything, and it is easy to get wrong.

MKT-EPCC-001 makes on-premise/in-country single-tenant the **primary** deployment. That means:

| Cost bucket | Who pays | Notes |
|---|---|---|
| Production server for each install | **The customer** | It sits in the NOC affiliate's or EPC's own server room. For the air-gapped tier it *must* — colocation and public cloud are both disqualified by AG-01…AG-06. |
| Our development, CI, build and mirror infrastructure | **Us** | Recurring, small, and the only line we actually control. |
| Demo/sales environment | **Us** | One shared box. |
| Eventual multi-tenant SaaS (phase 2) | **Us** | Not in scope for the launch. Costed separately in §7. |

So the practical consequence is:

- Server cost is a **sales objection to defuse**, not an operating expense to minimise. If the sizing sheet says "you need a 700-million-تومان server," the Project Licence deal gets harder. If it says "a 130-million-تومان used DL380 handles this," it doesn't.
- Our own burn is genuinely small (§6) and the stack barely affects it.

Anyone comparing "Laravel hosting vs Node hosting cost" for this product is answering a question the business model does not ask.

---

## 2. Where the RAM actually goes

Below is a realistic per-install footprint for a single EPC project — call it **~120 named users, ~30 concurrent**, which is a normal large-project population.

| Component | RAM | Who put it there |
|---|---|---|
| **OpenSearch** (JVM, EN + FA analyzers) | **6 GB** | ADR-003 §2 |
| **PostgreSQL 18** | 8 GB | ADR-003 §2 |
| **Keycloak** (JVM) | 1.5 GB | ADR-003 §2 |
| Headless Chromium PDF workers | 1.5 GB | ADR-003 §2 |
| MinIO | 1 GB | ADR-003 §2 |
| Redis | 1 GB | ADR-003 §2 |
| RabbitMQ (Erlang VM) | 1 GB | ADR-003 §2 |
| OS + container overhead | 2 GB | — |
| **Subtotal, stack-independent** | **22 GB** | |
| Application tier | 0.4 – 1.2 GB | **← the only line the stack changes** |
| **Total** | **~22.4 – 23.2 GB** | |

Read that table again before reading anything else in this document. **Two JVMs and PostgreSQL are ~70% of the memory. The application language is 2–5%.**

### 2.1 The application tier, stack by stack

| Stack | Model | Workers | RAM | Source |
|---|---|---|---|---|
| Laravel + PHP-FPM | process per request | 10 | ~0.4 GB (30–50 MB each) | [1](https://deploynix.io/blog/php-fpm-vs-laravel-octane-on-deploynix-real-world-performance-comparison) |
| Laravel + Octane/FrankenPHP | app resident per worker | 4 | ~0.3 GB (60–90 MB each) | [1](https://deploynix.io/blog/php-fpm-vs-laravel-octane-on-deploynix-real-world-performance-comparison) |
| NestJS / Node 24 | app resident per worker | 4 | ~1.2 GB | estimate, not benchmarked |
| .NET 10 | app resident per worker | 4 | ~1.2–2 GB | estimate, not benchmarked |

Laravel is genuinely the lightest — PHP-FPM's process-per-request model releases memory between requests, and Octane's ~120 MB resident workers ([1](https://deploynix.io/blog/php-fpm-vs-laravel-octane-on-deploynix-real-world-performance-comparison)) are still less than a Node worker with a loaded module graph.

**The advantage is real and it is worth about 0.8 GB.** On a 32 GB box that is 2.5% of the machine. It does not change which server you buy.

Throughput is not the binding constraint either: Octane serves ~2,100–2,600 RPS on 4 workers versus ~850 for PHP-FPM ([1](https://deploynix.io/blog/php-fpm-vs-laravel-octane-on-deploynix-real-world-performance-comparison)). Thirty concurrent engineers filling in ITR forms will not approach even the PHP-FPM figure. **Every candidate stack is over-provisioned for this workload at any sane server size.**

---

## 3. Iranian price anchors, September 2026

### 3.1 Cloud VPS, Iran-located

| Provider | Spec | تومان / ماه |
|---|---|---|
| Liara | 2 vCPU / 4 GB / 20 GB SSD | ۱,۹۰۰,۰۰۰ |
| Liara | 8 vCPU / 16 GB / 160 GB SSD | ۵,۷۵۰,۰۰۰ |
| Liara | 16 vCPU / 32 GB / 320 GB SSD | ۱۰,۰۵۰,۰۰۰ |
| Liara | 32 vCPU / 64 GB / 640 GB SSD | ۱۷,۶۰۰,۰۰۰ |
| Virak Cloud | 8 Core / 32 GB / 150 GB (memory-optimised) | ۴,۸۰۰,۳۸۴ |
| Virak Cloud | 16 Core / 16 GB / 100 GB (network-optimised) | ۵,۶۸۹,۹۴۴ |

Sources: [2](https://liara.ir/products/cloud-server/), [3](https://virakcloud.com/). Liara bills download traffic at ۲,۵۰۰ تومان/GB with upload free ([2](https://liara.ir/products/cloud-server/)).

### 3.2 Dedicated servers, Iran

| Provider | Spec | تومان / ماه |
|---|---|---|
| VPSMarket | 2× Xeon E5-2620, 64 GB DDR3, 2×1TB SSD | ۴,۴۵۰,۰۰۰ |
| VPSMarket | 2× Xeon E5-2680 v4, **128 GB** DDR4, 2×1TB SSD | ۶,۸۰۰,۰۰۰ |
| Saba Host | 2× E5-2630v3 (12C/24T), 32 GB, 300 GB SAS | ۷,۹۲۰,۰۰۰ |
| Saba Host | 2× E5-2650v3 (20C/40T), 64 GB, 2×250 GB SSD | ۱۰,۹۰۰,۰۰۰ |
| OnlineServer | 2× E5-2620 v2, 32 GB, 2×1TB SATA | ۸,۶۰۰,۰۰۰ (۷,۷۴۰,۰۰۰ annual) |

Sources: [4](https://vpsmarket.org/dedicated-iran/), [5](https://saba.host/dedicated-server/iran-dedicated-server), [6](https://onlineserver.ir/dedicated-server/iran).

> **Finding worth acting on:** dedicated is dramatically better value than cloud VPS in Iran right now. **128 GB dedicated at ۶,۸۰۰,۰۰۰ versus 64 GB cloud at ۱۷,۶۰۰,۰۰۰** — four times the RAM for 39% of the price. Pricing spreads between vendors are also enormous (VPSMarket's 64 GB dedicated at ۴,۴۵۰,۰۰۰ vs Saba's at ۱۰,۹۰۰,۰۰۰ — 2.4×), so **quote at least three vendors on every deal.** Much of that spread is CPU generation and support quality, but not all of it.

### 3.3 Buying hardware outright (the air-gapped case)

| Config | تومان, one-time |
|---|---|
| HP DL380 G9, 8SFF, 2× E5-2620v3, 32 GB, 600 GB | ۶۹,۵۰۰,۰۰۰ |
| HP DL380 G9, 8SFF, 2× E5-2680v4, **64 GB**, 3.6 TB | ۱۲۹,۵۰۰,۰۰۰ |
| HP DL380 G9, 8SFF, 2× E5-2680v4, **128 GB**, 3.6 TB | ۱۹۹,۰۰۰,۰۰۰ |
| Bare chassis, no CPU/RAM | ۳۰,۰۰۰,۰۰۰ – ۴۰,۰۰۰,۰۰۰ |
| Colocation, 1U | ۳,۸۴۹,۰۰۰/ماه (۳,۶۵۸,۰۰۰ annual) |

Sources: [7](https://rasamserver.com/product/hpe-proliant-dl380-g9/), [8](https://emalls.ir/%D9%85%D8%B4%D8%AE%D8%B5%D8%A7%D8%AA_HP-ProLiant-DL380-Gen9-8SFF-Server~id~1663277), [9](https://www.iranserver.com/dedicated-server/co-location/).

Used Gen9 hardware is the workhorse of Iranian enterprise IT, it is plentiful, and it is more than adequate for this workload. A 128 GB DL380 G9 at ۱۹۹ میلیون is a rounding error against an EPCC project's budget — and most target customers **already have** spare capacity in their server room, in which case the marginal hardware cost of our product is zero.

---

## 4. Three sizing scenarios, priced

### Scenario A — one project, ~120 users, full ADR-003 architecture

Needs ~24 GB usable → **32 GB machine**.

| Option | تومان / ماه | 3-year total |
|---|---|---|
| Liara cloud 32 GB | ۱۰,۰۵۰,۰۰۰ | ۳۶۱,۸۰۰,۰۰۰ |
| Virak 32 GB memory-optimised | ۴,۸۰۰,۳۸۴ | ۱۷۲,۸۱۴,۰۰۰ |
| VPSMarket dedicated 64 GB | ۴,۴۵۰,۰۰۰ | ۱۶۰,۲۰۰,۰۰۰ |
| Customer-owned DL380 G9 64 GB | ۱۲۹,۵۰۰,۰۰۰ one-time | **۱۲۹,۵۰۰,۰۰۰** |

**Stack delta across this whole table: zero.** Every option above absorbs the 0.8 GB difference between Laravel and NestJS without changing tier.

### Scenario B — same install, architecture trimmed for P1 (see §5)

Drop OpenSearch (use PostgreSQL full-text for P1), drop RabbitMQ (use the database queue), defer ClickHouse. Needs ~15 GB → **16 GB machine**.

| Option | تومان / ماه | 3-year total |
|---|---|---|
| Liara cloud 16 GB | ۵,۷۵۰,۰۰۰ | ۲۰۷,۰۰۰,۰۰۰ |
| VPSMarket dedicated 64 GB | ۴,۴۵۰,۰۰۰ | ۱۶۰,۲۰۰,۰۰۰ |
| Customer-owned DL380 G9 32 GB | ۶۹,۵۰۰,۰۰۰ one-time | **۶۹,۵۰۰,۰۰۰** |

**Against Scenario A on the same procurement route, this is a 43–46% saving.** On the cloud route it is ۴,۳۰۰,۰۰۰ تومان/ماه.

Compare that with the ~۳۰۰,۰۰۰ تومان/ماه that the Laravel-vs-Node memory difference is worth, and the priority ordering is obvious.

### Scenario C — HA, multi-project, large NOC affiliate

3 app nodes + Postgres primary/replica + separate OpenSearch → roughly 96–128 GB across nodes.

| Option | تومان / ماه | 3-year total |
|---|---|---|
| VPSMarket dedicated 128 GB × 2 | ۱۳,۶۰۰,۰۰۰ | ۴۸۹,۶۰۰,۰۰۰ |
| Liara cloud 64 GB × 2 | ۳۵,۲۰۰,۰۰۰ | ۱,۲۶۷,۲۰۰,۰۰۰ |
| Customer-owned 2× DL380 G9 128 GB | ۳۹۸,۰۰۰,۰۰۰ one-time | **۳۹۸,۰۰۰,۰۰۰** |

At this size the cloud route costs **3.2× the owned-hardware route over three years**. For any customer who intends to run the product for more than about 20 months, owned hardware wins outright — and the air-gapped tier forces that answer anyway.

---

## 5. What actually reduces the bill

Ordered by impact. Note that the stack choice does not appear until the bottom.

| # | Lever | Saving | Cost of pulling it |
|---|---|---|---|
| 1 | **Drop OpenSearch from P1**, use PostgreSQL full-text search | ~6 GB, a whole size tier | Persian FTS in PostgreSQL needs real evaluation — there is no built-in Persian stemmer, so this needs a spike before it is promised. Not free. |
| 2 | **Buy hardware instead of renting cloud** | 60–70% over 3 years | Capex, and the customer owns the ops. Usually what they want anyway. |
| 3 | **Quote three vendors** | up to 2.4× on identical specs | An afternoon of procurement work. |
| 4 | **Dedicated instead of cloud VPS** | ~60% at equal RAM | No elastic scaling — irrelevant for a fixed-population on-premise install. |
| 5 | **Defer ClickHouse to P4** (already in ADR-003) | ~4 GB | Already decided. |
| 6 | **RabbitMQ → database queue for P1** | ~1 GB | Laravel makes this nearly free; on Node it is more work. |
| 7 | Laravel instead of NestJS | ~0.8 GB | — |

Levers 1 and 2 together are worth more than lever 7 by a factor of roughly fifteen.

---

## 6. Our own infrastructure (this is the number we control)

| Item | Spec | تومان / ماه |
|---|---|---|
| CI + build runner | 8 vCPU / 16 GB dedicated share | ~۴,۵۰۰,۰۰۰ |
| Internal package mirror (Satis or Verdaccio) + container registry | 2 vCPU / 4 GB + storage | ~۱,۹۰۰,۰۰۰ |
| Demo / sales environment | 8 vCPU / 16 GB | ~۵,۷۵۰,۰۰۰ |
| Staging (mirrors a customer install) | 16 GB | ~۵,۷۵۰,۰۰۰ |
| Backup + artefact storage | ~2 TB | ~۲,۰۰۰,۰۰۰ |
| **Total** | | **~۲۰,۰۰۰,۰۰۰ تومان/ماه** |

Roughly ۲۴۰ میلیون تومان a year — **less than the cost of one mid-level engineer.** The stack changes this by maybe ۵۰۰,۰۰۰ تومان/ماه in Laravel's favour, mostly on the CI runner.

One genuine Laravel saving here that is *not* about RAM: a Satis mirror of ~150 Composer packages is a fraction of the storage, bandwidth and scan time of a Verdaccio mirror of a ~1,000-package npm tree (Annex A §2.2). Call it a modest, real, ongoing operational advantage — measured in engineer-hours per release, not in تومان.

---

## 7. If the SaaS phase happens (phase 2, not now)

Multi-tenant SaaS inverts §1 — we would pay for everything. At that point the app tier's memory profile finally starts to matter, because it multiplies across tenants.

Rough shape at 20 tenants on shared infrastructure: 2 app nodes + Postgres primary/replica + shared OpenSearch ≈ 160 GB ≈ ۲۰,۴۰۰,۰۰۰ تومان/ماه on dedicated, or about ۱,۰۲۰,۰۰۰ تومان per tenant per month. Laravel's lighter app tier might save 10–15% of the *app node* portion — perhaps ۱,۵۰۰,۰۰۰ تومان/ماه.

Worth noting for the future. Not worth deciding today, and MKT-EPCC-001 has already deferred SaaS.

---

## 8. Conclusions

1. **The stack does not decide the server bill.** Laravel is genuinely the lightest option, and it is worth ~0.8 GB out of ~23 GB. Do not use hosting cost as an argument in the ADR-003 / Annex A decision — it is not strong enough to carry weight in either direction.
2. **OpenSearch is the single most expensive architectural decision we have made**, at 6 GB and a whole server size tier. It was added to ADR-003 without a cost challenge. **Recommendation: move OpenSearch out of P1** and spike PostgreSQL Persian full-text first.
3. **Dedicated beats cloud in Iran by a wide margin, and owned hardware beats both** beyond ~20 months. This aligns with the air-gap requirement rather than fighting it — a rare case where the compliance-driven answer is also the cheap one.
4. **A realistic per-install hardware ask is ۷۰–۱۳۰ میلیون تومان one-time**, on used Gen9 hardware most target customers already own. That is the number for the sales conversation, and it is small enough not to be an obstacle.
5. **Our own burn is ~۲۰ میلیون تومان/ماه** and is not a meaningful constraint on any of these decisions.

### Recommended next step

Produce a one-page **customer sizing sheet** (Small / Medium / HA) with the RAM, disk and one-time cost for each, so sales never has to improvise an answer to "چه سروری لازم داریم؟". That is a half-day of work and it removes a recurring deal objection.

---

*End of document — COST-EPCC-001*
