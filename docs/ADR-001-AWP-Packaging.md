# ADR-001 — How to package and licence AWP

| Field | Value |
|---|---|
| Document ID | ADR-EPCC-001 |
| Date | 2026-09-09 |
| Status | **Accepted and closed** (2026-10-11 status update — HANDOFF §1 and TODO §5 record this decision as closed; see below) |
| Context | Iran-first launch decision (MKT-EPCC-001). AWP lands in phase P3. |
| Decision needed by | Price-list publication, not build start. P0 exit at the latest. |

---

## 1. Why the original question is malformed

The question "is AWP in Full or an add-on?" assumes AWP is one indivisible feature. It isn't. Decomposed, it is seven distinct things with very different cost, reusability and demand profiles:

| # | Component | AWP-specific? | Useful without AWP? | Build cost |
|---|---|---|---|---|
| 1 | **Constraint engine** — typed constraints, owners, promise dates, aging, event-driven auto-clearance | No | **Yes, universally** | Medium |
| 2 | **Rolling lookahead** — 90/60/30/6-week workbenches, readiness heat map | No | **Yes** (this is essentially Last Planner) | Low-Medium |
| 3 | **Field package execution** — offline package download, crew assignment, package return | No | **Yes** — any field execution needs this | High |
| 4 | CWA/CWP/EWP/PWP/IWP formal hierarchy | Yes | No | Medium |
| 5 | Path of Construction builder | Yes | No | Medium |
| 6 | Model-based scoping (3D object → package scope + quantities) | Yes | Partly | **High** (needs BIM viewer) |
| 7 | AWP KPIs (tool time, PPC, backlog) + CII/COAA maturity assessment | Yes | No | Low |

Items **1, 2 and 3 are general project-control infrastructure**. They deliver value on an IPS-pack Iranian project that has never heard of AWP. Items **4–7 are the AWP methodology proper**.

Evidence that the architecture already leans this way: the IPS/Iran pack (`ips-iran.pack.json`) already defines six constraint types — ENG, MAT, PERMIT, QC, NAZER, SITE — with event-driven auto-clearance. The constraint engine is *already* being used outside AWP. Gating that engine behind an AWP add-on would mean deliberately breaking the Iranian pack.

---

## 2. The structural argument — AWP cannot be a per-company entitlement

This is the decisive point and it is not a pricing argument, it is a functional one.

AWP only works if the **whole project ecosystem** participates:

- Engineering must produce deliverables aligned to EWPs → the EPC's engineering team needs it
- Procurement must reserve material against IWPs → the procurement org needs it
- The subcontractor's foreman must receive and return IWPs → **subcontractors need it**
- The owner must approve the Path of Construction → **the owner needs it**
- QC must attach ITRs to packages → the QA org needs it

If the EPC buys an AWP add-on but its five subcontractors are on Starter and cannot see IWPs, AWP does not partially work. It **does not work at all** — the crews at the workface are precisely the people who must consume the packages.

So a per-company AWP add-on sells the buyer something that structurally cannot deliver value unless every counterparty independently buys the same thing. That is a broken SKU, and it will produce refund conversations and a reputation for overselling.

**Conclusion: if AWP is an add-on, it must be a *project-level* add-on, never a per-company one.**

---

## 3. The Iran-specific procurement argument

In the Iranian public/semi-public procurement context (NOCs and their affiliates), adding scope to a signed contract requires a formal amendment (الحاقیه). That process is slow, requires re-justification, and is politically costly for the manager who requests it.

The practical consequence: **the "land small, expand later" motion that makes add-ons work in Western SaaS is weak in this market.** Whatever the customer buys, they largely buy at initial contract signature.

This cuts two ways and I want to be honest about both:

- **Against add-ons:** later upsell is unlikely, so the main commercial benefit of an add-on model (expansion revenue) is largely unavailable.
- **For add-ons:** if the add-on is presented as a line item in the *original* quotation, the friction disappears entirely. This is a sales-process question, not a packaging question.

Net: the procurement argument does not kill the add-on model, but it does mean **AWP must be quoted at initial deal time or it will never be sold.** An add-on that relies on discovering demand in year two will simply not sell in Iran.

---

## 4. The strategic argument — AWP is the international bridge

Under the Iran-first decision, AWP is not the near-term revenue driver. Its strategic role is different: it is the credential that makes the product sellable outside Iran in phase P5.

That creates a specific risk. If AWP is priced as a rarely-bought add-on:
- attach rate stays low (realistically 10–20% in Iran near term)
- almost no real projects exercise the module
- we get no production feedback, no reference customer, no case study
- in three years the international pitch is *"we support AWP"* backed by zero live deployments — which sophisticated international owners will see through immediately

Adoption, not revenue, is what AWP needs to produce in years 1–3. Pricing should be set to maximise *usage*, not to extract margin from a small base.

---

## 5. Decision

**A three-part split:**

### 5.1 In the core (available on Full, and on Professional)
- Constraint engine (typed constraints, owners, promise dates, aging, event-driven clearance)
- Rolling lookahead planning (90/60/30/6 week)
- Field package execution and offline package handling

Rationale: general infrastructure, already required by the IPS pack, delivers value with no AWP methodology in sight. Gating it would damage the core product.

### 5.2 AWP Pack — a **project-level** add-on
Contains items 4, 5, 7: the CWA/CWP/EWP/PWP/IWP hierarchy, Path of Construction builder, AWP-specific KPIs and the CII/COAA maturity assessment.

Licensing shape:
- Purchased **once per project**, by whoever drives the methodology — normally the owner, sometimes the EPC
- Enables AWP for **every participating company on that project**, regardless of each company's own plan tier
- A subcontractor on Starter working on an AWP-enabled project sees and executes IWPs. No separate purchase.
- Priced as a percentage uplift on the Project Licence, or as a standalone project fee where the project is not on a Project Licence

### 5.3 Model-based scoping — separate add-on
Item 6 rides on the BIM viewer, which is already a separate paid add-on with its own substantial cost. It stays bundled with the BIM viewer rather than with AWP. A customer can run AWP perfectly well from 2D drawings and quantity take-offs.

### 5.4 Launch-phase pricing posture
For the first 2–3 projects: **price the AWP pack at or near cost, or include it free in exchange for a reference commitment and case-study rights.** Buying adoption is the correct trade while the strategic goal is credibility rather than margin.

---

## 6. What this changes in existing documents

| Document | Statement to correct |
|---|---|
| `MKT-EPCC-001` §6.2 item 1 | "AWP moves out of Full and becomes a paid add-on available from Professional up" → replaced by the three-part split above; specifically **project-level**, not per-company |
| `MKT-EPCC-001` §6.2 table | AWP add-on row: clarify it is a project-scoped SKU |
| `SRS-EPCC-001` §15 Q6 | Now answered |
| Prototype plans screen | Reflect project-level scoping |

---

## 7. What would change this decision

State the falsifiers now, so the decision can be revisited on evidence rather than opinion:

1. **A launch client mandates AWP contractually.** If the first pilot is an owner who requires AWP across the project, AWP stops being a strategic side-bet and becomes core scope — fold it into the Project Licence outright and drop the add-on.
2. **Attach rate exceeds ~40% in the first year.** Demand is real and price-insensitive; the pack can carry a proper margin.
3. **Attach rate is under ~10% after four projects.** The Iranian market is not ready. Stop investing in AWP beyond maintenance, keep the constraint engine (which earns its keep regardless), and defer the rest to the international phase.
4. **A competitor bundles AWP as standard in this market.** Bundling becomes defensive necessity.

---

## 8. Honest summary of the reversal

My earlier recommendation in MKT-EPCC-001 — "paid add-on from Professional up" — was reasoned from the right instinct (don't price AWP out of reach by locking it in the top tier) but got the *unit of sale* wrong. Per-company AWP licensing is functionally incoherent because AWP is a whole-project methodology. The correct unit is the project.

---

*End of document — ADR-EPCC-001*
