# ADR-049 — Minimal workflow engine: transition policies, transition ledger, action inbox

Status: **PROPOSED — awaiting owner decisions** (2026-10-26). Addresses the keystone gap CORE-WF from the SRS module map and finding 3.1/3.2 of the 2026-10-26 process assessment.

## Context

Every implemented domain hand-builds its own state machinery: optimistic `version` columns, hash-bound envelopes (scope proposals), forward-only migrations (controlled GRN), nomination + person-separation (ADR-046), state filters with 422 discipline (notification register). The results are correct but singular: **no chained approval exists anywhere** (A signs, then B, then C, with SLA and substitution), no general transition ledger, and no answer to "what is waiting for me?". The 59-minute suite and per-domain re-implementation are the price.

This ADR deliberately does **not** propose a commercial BPM engine (Camunda-style would fight the ledger culture — see process assessment §5). It proposes the smallest abstraction that generalises what the codebase already does well.

## Decision points (explicit owner choices required)

1. `workflow_scope=policy_and_ledger_only | policy_ledger_and_bpmn_diagrams`
   Recommended: **policy_and_ledger_only** — a transition-policy table + append-only transition ledger + action queue projection. No diagram editor, no scripting runtime.
2. `adoption_strategy=proof_on_one_domain_then_optional | big_bang_all_domains | new_domains_only`
   Recommended: **proof_on_one_domain_then_optional** — first proof: the document review cycle (IDC→IFR→IFA→IFC routing with `ReviewComment` linkage), which today changes state via permissions alone. Existing domains keep their machinery unless/until migrated.
3. `sla_model=deadline_with_aging_only | deadline_with_escalation_actions`
   Recommended: **deadline_with_aging_only** initially — deadlines and aging surface in the inbox/dashboard; automatic escalation (reassign/remind-transport) after ADR-050 transport exists.
4. `delegation=per_user_timeboxed_delegates | none_initially`
   Recommended: **per_user_timeboxed_delegates** — a delegation register (who may act for whom, which policies, until when, append-only) answers the leave/rotation reality; it is also the first step toward the configurable authority matrix (process assessment §2A critique).
5. `inbox_projection=ledger_view | materialized_queue`
   Recommended: **ledger_view** — the inbox is a read model over pending transitions + existing domain queues (acknowledgments, QC/QA decisions, approvals), never a second source of truth.

## Sketch of the decision

- `workflow_policies` (owner-configured per project/company scope, RLS): ordered steps, each with permitted roles/companies, separation constraints (reusing ADR-046 person-separation semantics), deadline rule, witness requirements.
- `workflow_transitions` append-only ledger: policy id, subject reference (kind+uuid), from/to state, actor, company, evidence references, ordinal clock (reuse the `epcc_mto_clock_step` trigger pattern).
- Action inbox: a read projection ("waiting on me", "waiting on my company", aging buckets), bilingual, with the existing no-store GET discipline.
- Transport hooks (reminders) deferred to ADR-050; dashboards consume the same projection.

## Consequences

- Chained approvals, SLA aging and delegation become platform capabilities instead of per-domain inventions; future domains (RFQ award, change orders, NCR disposition, system completion) declare policies instead of writing state machines.
- Must not regress existing guarantees: the abstraction sits **above** domain invariants (immutability, digests, RLS), never replaces them.
- Test surface: separation under concurrency, delegation expiry, ledger integrity, projection honesty (inbox never invents or hides work).

## Out of scope

BPMN/diagram tooling, forms engine, rules scripting, automatic escalation (until transport), replacing the MTO journal or GRN forward-only machinery.
