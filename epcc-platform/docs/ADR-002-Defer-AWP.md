# ADR-002 — Defer AWP to the international phase

| Field | Value |
|---|---|
| Document ID | ADR-EPCC-002 |
| Date | 2026-09-09 |
| Status | **Proposed** — refines ADR-001, does not contradict it |
| Context | Iran-first launch. AWP attach rate in Iran expected low near-term. Question raised: drop AWP now, revive it for the international version? |
| Decision | **Yes — defer the AWP methodology from P3 to the international phase (P5+). Protect two architectural seams so revival costs weeks, not a rewrite.** |

---

## 1. Recommendation in one paragraph

Defer it. The AWP *methodology* — CWA/CWP/EWP/PWP/IWP hierarchy, Path of Construction builder, model-based scoping, AWP maturity assessment — earns nothing in the Iranian market for the next two to three years and consumes roughly a quarter of a delivery phase. Cut it from P3.

But "dropping AWP" must not mean dropping the three capabilities underneath it, because **the Iranian product already depends on them**. And it must not mean designing as though packages don't exist, because that is the one decision that turns a future 6–10 week revival into a 9-month rewrite.

---

## 2. What is actually being deferred

Using the ADR-001 decomposition:

| # | Component | Decision | Why |
|---|---|---|---|
| 1 | Constraint engine | **Build in P2 — not deferred** | The IPS pack already defines six constraint types (ENG, MAT, PERMIT, QC, NAZER, SITE). Deferring this breaks the Iranian product. |
| 2 | Rolling lookahead (90/60/30/6) | **Build in P2 — not deferred** | This is Last Planner, not AWP. Every Iranian site planner wants a 6-week lookahead. Low cost, high perceived value in demos. |
| 3 | Field package execution (offline) | **Build in P2 — not deferred** | Field execution is core. See seam 2 below. |
| 4 | CWA/CWP/EWP/PWP/IWP hierarchy | **Defer** | Pure AWP formalism. No Iranian client asks for it. |
| 5 | Path of Construction builder | **Defer** | Same. |
| 6 | Model-based scoping | **Defer** | Rides on the BIM viewer, which is separately deferred anyway. Highest cost of the set. |
| 7 | AWP KPIs + CII/COAA maturity assessment | **Defer** | Meaningless without 4 and 5. |

So the honest framing is not "drop AWP". It is **"build the useful two-thirds now under Iranian names, defer the methodology layer"**. Items 1–3 are general project-control infrastructure that happen to be famous because AWP popularised them.

---

## 3. The two seams that must survive

This is the entire engineering content of this decision. Everything else is scheduling.

### Seam 1 — a generic work package entity, from day one

Build **one** `work_package` table with a `type` discriminator, even though the Iranian configuration will only use one type.

```sql
work_package (
  id, tenant_id, project_id, number,
  type          text NOT NULL,   -- 'FIELD_PACKAGE' now; 'IWP','CWP','EWP','PWP','TWP' later
  parent_id     uuid NULL,       -- self-referencing; hierarchy rules come from the pack
  wbs_node_id, area_id, system_id, discipline_code,
  crew_size, estimated_hours, planned_start, planned_finish,
  status, pack_version, ...
)
```

The hierarchy rules (`IWP mustBelongTo CWP`) already live in the standards pack as data — see `breakdownStructures[].mustBelongTo` in the schema. So enabling AWP later means **adding enum values and switching on a pack**, not migrating a table with 200,000 rows across live customer installs.

- Cost of doing this now: near zero. You need a package concept regardless, because field crews receive work in bundles.
- Cost of retrofitting: schema migration on every on-premise install, progress recalculation, field-app rewrite. On air-gapped customer sites this is the single most expensive kind of change we can make.

**If only one thing survives from this ADR, make it this.**

### Seam 2 — the field app syncs *packages*, not activities or documents

The offline unit of download, cache, execution and return must be a **package**: scope + drawings + tag list + ITRs + materials + permits, as one bundle.

It is tempting to build the field app around "today's activities" or "documents assigned to me". Both are simpler and both are wrong — they make the app impossible to point at an IWP later without rebuilding the sync layer, the conflict policy and the offline cache model.

- Cost now: none. Package-oriented is also the better design for Iranian sites.
- Cost later: effectively a rewrite of the highest-risk component in the product.

### Two seams that already exist and just need protecting

- **Constraint types are pack-driven** with event-bound auto-clearance — already in the schema (`constraintTypes[].autoCheck`). Do not hardcode the six IPS types.
- **Progress rules support `quantityBased`** — already in the schema. Do not special-case IPS rule-of-credit.

---

## 4. The cheap insurance policy

**Keep `awp.pack.json` in the repository and add it to CI as a validation test.**

It costs nothing to store — it is a JSON file that already exists and already validates. But it does real work:

1. It is a **regression test on the pack engine's genericity.** If someone quietly makes the standards engine assume Iranian review codes or IPS lifecycles, the AWP pack stops loading and CI fails. The build tells you the day you broke the abstraction, instead of finding out in year three.
2. It is a **sales artifact.** In an international conversation, "here is our AWP configuration, validated in CI" is worth more than a roadmap slide.
3. It **preserves design intent** across staff turnover. Three years and several engineers later, the pack documents what the architecture was supposed to support.

Concretely: keep the existing schema-validation job running over all three packs, and add a smoke test that boots a throwaway project with the AWP pack and asserts the resolver returns AWP terminology and structures. If that test can't pass, the seams have rotted.

---

## 5. What the freed capacity should buy

Roughly a quarter of P3 comes free. Do not let it evaporate into general scope. The Iranian deals are won on these, and each is currently thinner than it should be:

| Priority | Where to spend it | Why |
|---|---|---|
| 1 | **Offline field app hardening** — real 7-day offline, low-end Android tablets, conflict edge cases | Highest adoption risk in the whole product (risk IR-R5). Also directly protects seam 2. |
| 2 | **Persian PDF and OCR quality** — golden-image test suite, correct shaping, mixed LTR/RTL | Risk IR-R2. Formal Iranian documents are the product's public face; bad Persian typography kills credibility instantly. |
| 3 | **صورت‌وضعیت / تعدیل depth** — unit-rate schedules, index coefficients, measurement sheets, edge cases in deductions | The reason Iranian contractors buy. Depth here beats breadth elsewhere. |
| 4 | **On-premise installer and air-gap verification** — one-command install, `verify-airgap`, offline update bundle | Every deal depends on this working smoothly at the customer's datacentre. |

That reallocation is a straight upgrade to win rate. AWP would have bought a capability almost nobody in the target market is asking for.

---

## 6. Revised roadmap position

| Phase | Was | Now |
|---|---|---|
| P2 | Execution modules | + constraint engine, lookahead, package-based field execution (under Iranian terminology) |
| P3 | AWP & Commissioning (3–4 months) | **Commissioning, completion, punch, certificates, turnover dossier only** (~2–2.5 months). AWP methodology removed. |
| P5 | Scale & internationalisation | + **AWP methodology pack activation**: hierarchy runtime, Path of Construction builder, AWP KPIs, maturity assessment. Estimated 6–10 weeks *if the seams held*. |

The 6–10 week estimate is credible only because items 1–3 already exist and the pack file is already written and CI-validated. Without the seams, budget 6–9 months and a painful migration across installed on-premise customers.

---

## 7. Risks of deferring

| Risk | Likelihood | Mitigation |
|---|---|---|
| A pilot client turns out to mandate AWP | Low in Iran | The pack exists; with seams intact, ~6–10 weeks. Quote it as a project-level add-on (ADR-001) rather than refusing. |
| Seams rot silently over three years of feature work | **Medium-High** | The CI pack-boot test in §4 is specifically designed to catch this. Treat a failure as a build break, not a warning. |
| Losing the "AWP-ready" positioning in early international talks | Low | The validated pack plus the constraint engine running in production is a stronger claim than a half-built module nobody uses. |
| Team forgets the deferral rationale and rebuilds AWP ad hoc | Medium | This ADR plus the pack file are the record. Revisit at each phase gate. |

---

## 8. What would reverse this

- A named launch client contractually requires AWP → fold it back into scope and into the Project Licence.
- We win an international pilot earlier than P5 → pull AWP forward to serve it.
- The constraint engine proves so popular with Iranian clients that they start asking for the packaging layer on top → demand-led, the best possible reason to build it.

---

## 9. Summary

Defer the methodology, keep the machinery, protect two seams, and let CI hold the line. The instinct behind the question is right: AWP is not what wins Iranian deals. The trap is confusing "we won't sell AWP for three years" with "we can design as if work packages don't exist" — the first is a sound commercial call, the second is a rewrite waiting to happen.

---

*End of document — ADR-EPCC-002*
