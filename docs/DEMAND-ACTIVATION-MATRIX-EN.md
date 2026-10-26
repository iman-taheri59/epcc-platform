# Controlled demand: proposed activation and lifecycle matrix

2026-10-23 · **DA-01..04 explicitly accepted as bounded policy/design; implementation not activated. See §7.**
[فارسی](DEMAND-ACTIVATION-MATRIX-FA.md) · [Version/reconciliation design](DEMAND-BASIS-RECONCILIATION-EN.md)

## 1. Why a decision gate now

The incoming receipt diagnostic is implemented. Demand provenance and reconciliation are executable **offline proposals**, not a live MTO→MR chain. Repeating prototype tests cannot settle authority or when demand capacity changes. This matrix identifies the new decisions needed before implementation, instead of treating “continue” as approval for new MTO access or a reservation policy.

## 2. Closed decisions — do not reopen or inherit

- MTO authority/access remains explicitly deferred pending separate approval (MTO-REQUIREMENTS §8 question 2; ADR-015; decision index). Existing selection/replacement, pinned hierarchy, additive-evidence and lifecycle semantics remain accepted. This matrix does not nominate an MTO acceptor or admission authority.
- MR authors and PO drafters are independently owner-configured; no PMS/MR/receipt inheritance. PO financial approval/issue and original drafter pricing remain as approved. Full-copy MR→PO, nonreserving PO drafts and existing issued allocation limits remain unchanged.
- Custody preparation, receiving QC and QA acceptance are three distinct users; warehouse posting needs independent authority. Demand review never substitutes for technical QA or creates GRN/usable stock.
- Original caller RLS, no tenant impersonation, no automatic configuration-admin reader, immutable history and unknown-not-zero remain invariants.

## 3. Proposed action matrix — all new demand rows are pending

| Action | Proposed authority and scope | Required independent checks | Not granted |
|---|---|---|---|
| Configure demand-specific nominees | Current project-owner project.configure administrator, **only if approved for this new configuration surface** | Current project/participant eligibility; explicit users or company-bound roles, action and scope, append-only reasoned versions; no defaults | Reading MTO or acting merely because configurator |
| Read demand source | Separate explicit nomination, with exact permitted MTO documents/scopes and project/PIPING/company boundaries | Current eligible identity plus approved original-source policy; no broad project-wide fallback; nomination directory must not reveal hidden document titles | MTO acceptance/admission, CDE contents or library permissions |
| Propose explanation/reconciliation | Separate action nominees; own buyer-company MR, exact independently readable demand source | Current MR author authority where MR content changes; immutable MR/basis/inventory versions; readable exact evidence | MR approval, release, movement or live capacity change |
| Review reconciliation | Separate reviewers with original access to the same source and buyer-scoped MR | Exact frozen proposal; proposer cannot approve own proposal; technical/quantity/legacy findings explicit | Financial approval, QA acceptance, MTO topology admission |
| Apply approved outcome | Separate apply grant, scope-bound to buyer/source | Same approved version, fresh evidence/selection/MR/ledger heads, archive and idempotency checks | Approval by implication, bypass of hidden source or unresolved history |
| Release/rebase historical commitment | Separately controlled reviewed outcome; no automatic event on missing rows | Original commitment, approved reason, current dependency closure, existing PO execution/obligation check | Rewriting history, implicit cancellation of PO, reusing old evidence on a new revision |
| Read result/history | Only independently authorized source/MR readers within approved scope | Hide private source/evidence fields; historical readability follows separately approved rules, not a copied snapshot escape | Global inventory, all-company MRs or hidden documents |

**Recommended separation:** proposer ≠ reviewer; applier may be proposer or reviewer **only with a separately current apply grant**, and may not bypass independent approval. A stricter three-distinct-person option is available. Neither option changes existing three-person custody/QA or PO finance/issue separation. Runtime separation is User.id, not proof of natural-person identity.

Configurable nominees are not seeded grants. A future approval of this model is not approval of particular people/companies. Actual source-reader scope, action nominations and entitlement remain explicit configuration; no application table or screen exists yet.

## 4. Proposed lifecycle and capacity semantics

Names below describe events, not new active MR database states:

| Event/condition | Proposed behavior |
|---|---|
| Create/edit explanation draft | No reservation. Pin exact MR content/basis versions; old draft retained when replaced |
| Submit/review proposal | No capacity change; independent review of exact package only |
| MR technical + commercial approval, controlled explanation valid | **Recommended new trigger, only if separately approved:** reserve exact demand amount atomically; normal MR approval does not automatically grant a new demand writer |
| Alternative: issued PO only | MR-level demand remains a planning explanation; record demand commitment only at existing valid PO issue, without duplicating the existing PO→MR ledger. Duplicate approved MR planning may remain possible and must not be shown as available capacity certification |
| Issue full-copy PO | Preserve existing financial/issuer separation and issued allocation checks; if MR-level reservation selected, link/consume the same reservation, **never debit demand twice** |
| Cancel/withdraw/revise MR or lose demand source | Do not auto-release, carry forward or relink. Block new controlled use pending reviewed disposition. Preserve original obligation and historical basis |
| Change selected revision/evidence/topology | Invalidate stale proposals; re-review exact new dependencies. Evidence revocation matters even if gross quantity unchanged |
| Issued PO/physical arrival/QA/stock exists | No demand release inference from those statuses alone. Review actual obligations; demand reconciliation cannot cancel buying or warehouse facts |
| Legacy/hidden/unknown commitments | Unresolved, not zero; no affirmative free-capacity result |

**Recommended release boundary for initial activation:** no automatic release/rebase; reasoned independently reviewed outcome after checking related issued obligations. This is a proposed future surface, not an existing MR cancellation workflow. Partial quantities, commercial cancellation, waiver, stock offsets, waste, substitutes and unit conversions remain out of scope. Full-copy purchasing is preserved; no partial-buying approval is inferred.

A new reservation must not be enabled until there is approved effective selection/admission and source visibility, complete ledger reconciliation, atomic same-row concurrency control, a migration/legacy plan, rollback/idempotency and RLS tests. Selecting a reservation policy does not waive these technical gates.

## 5. Specific choices requested

- **DA-01 — source access:** keep deferred (technical-only work; no live cross-MTO chain), or approve design of explicit independently nominated demand readers with document/scope restrictions. Approval of this model does not approve MTO acceptance/admission or automatically add CDE access.
- **DA-02 — separation:** recommended two-person independent review plus separately granted apply action, or three distinct proposer/reviewer/applier users. All action grants explicit; no inheritance from purchase/QA roles.
- **DA-03 — demand reservation:** recommended at final MR technical/commercial approval for newly controlled linked MRs only, or at valid PO issue only. Existing unlinked MRs and PO draft nonreservation are unchanged; historical commitments need reconciliation, never guessed backfill.
- **DA-04 — release/rebase:** recommended no automation, independently reviewed reasoned outcome; alternatively keep release/rebase entirely deferred and inactive. Automatic release has no approved design and is not offered as a ready implementation.

The four choices below were pending when submitted; explicit answers are now recorded in §7. A custom answer can narrow/reject the proposal. Do not record user-selected values before the response, and do not conflate design approval with deployed behavior.

## 6. Verification posture and next deliverable

Document/decision reconciliation only: no new prototype or application behavior needed to ask these questions. No tests rerun because no executable code changed. Link and unchanged-source hash checks are separate evidence under `docs/evidence/2026-10-23-demand-activation/`. No DB/runtime, grants, migration, production data, CI/deploy/commit/push.

After answers: record accepted versus still-deferred choices in EN/FA, refine the bounded implementation and source-access adapter plan, then implement only the approved slice with focused PostgreSQL tests. Unresolved effective-scope admission remains a blocker to authoritative live capacity even if reader/action policy is accepted.

## 7. Explicit user decisions — accepted 2026-10-23

The user selected all four recommended options in the decision form:

| ID | Accepted value | Binding boundary |
|---|---|---|
| DA-01 | `explicit_scoped_readers` | Design explicit independent demand-reader nominations, owner project.configure administration, eligible participating users/company-scoped roles and explicit MTO document/scope limits; no defaults, purchase/QA inheritance, MTO acceptance/admission or CDE contents grant |
| DA-02 | `independent_review_separate_apply_grant` | Proposer and reviewer must differ; either may apply only with an independent current apply nomination and exact independent approval; purchasing/custody separation unchanged |
| DA-03 | `final_mr_approval` | Reserve only for newly controlled linked MRs at final technical AND commercial approval, with valid controlled provenance and atomic authority/freshness/ledger checks; later PO issue links/consumes the same reservation, never debits demand twice; PO drafts nonreserving |
| DA-04 | `reviewed_no_automation` | Reasoned independently reviewed reconciliation, no automatic release/carry-forward/relink on MR cancellation, missing source or revision change; check issued obligations; no partial-buying or commercial cancellation authority inferred |

**Supersession:** the design choice for this bounded MTO demand-reader model is no longer deferred. This does not close general MTO acceptance/admission authority, enable broad MTO reading, create source grants, or grant access to particular people/documents. New demand actions use the explicit nomination model in §3; no seeded/default actors. These decisions close policy choices, not technical completion.

**Implementation remains NOT ACTIVATED:** no schema/configuration screen/reader adapter/reservation writer exists for these choices yet. A future atomic final-approval integration must not leave MR approved without its required controlled reservation, or create a reservation if approval rolls back. Existing unlinked MR behavior is not retroactively reclassified; legacy demand commitments remain reconciliation findings, never fabricated reservations. Exact duplicate retries must return the original outcome and cannot reserve twice. A stale selection/evidence head or incomplete ledger blocks authoritative capacity regardless of the accepted actor model.

Next approved-boundary work: define a bounded reader-configuration/visibility adapter and same-transaction MR-final-approval hook against real source schemas; retain the independent effective-selection/admission and legacy-completeness gates. Do not re-ask DA-01..04, do not extend them to new waiver/stock/partial procurement policies. Implement only after source dependencies and focused PostgreSQL test plan are demonstrably satisfied.
