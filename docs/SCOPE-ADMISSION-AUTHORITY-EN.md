> **2026-10-24 subsequent web increment:** owner nomination/suspension pages and own-source diagnostics now exist; [current web guide](SCOPE-ADMISSION-AUTHORITY-WEB-EN.md). Statements below about absence of human UI describe the earlier service-only increment. Trusted package/evidence/admission writer remains absent.

> **2026-10-24 implementation update:** internal action nomination/preflight service and migration are now implemented; [guide](SCOPE-ADMISSION-AUTHORITY-SERVICE-EN.md). Earlier “no nomination table/service” statements describe the decision-only slice. No human UI, trusted package or admission writer yet.

# Scope admission authority: the remaining explicit activation decision

2026-10-23 · **SA-01/SA-02 explicitly accepted as bounded actor policy; not activated. See final decision section.**
[فارسی](SCOPE-ADMISSION-AUTHORITY-FA.md)

## Source-backed blocker

The scoped reader and reduction-only suspension are implemented. They do not authorize engineering admission. Rechecked:

- [Admission contract §3](ADR-024-SCOPE-ADMISSION-CONTRACT-EN.md): APPROVED_ACTOR_POLICY is explicitly outstanding. It assigns no human engineering approval right.
- `MtoScopeReadinessService`: NO_ADMITTED_TOPOLOGY, gross null.
- `2026_10_18_110000_capture_mto_scope_observations`: CHECK requires DATABASE_OBSERVATION/UNADMITTED; immutable observation is not an admission record to be flipped to ADMITTED.
- [Frozen topology reconciliation](G01-TOPOLOGY-RECONCILIATION-EN.md) and `MtoTopologyReconciliationService`: comparison, even a match, does not establish historical engineering truth, coverage or evidence validity.
- `MtoScopeAdmissionProposal`: structurally reviewable can never set ready_to_admit=true. Matching fabricated proposal/observation inputs is not trusted admission.

DA-01 authorizes a bounded source-reader nomination model. DA-02..04 concern demand reconciliation/reservation. None names a technical scope-admission actor. A source writer cannot be inferred from “continue,” project.configure, procurement/QA responsibility or a green structural comparison. Existing MTO snapshot acceptance is not redefined or newly granted here.

## Proposed bounded authority matrix

| Action | Proposed actor rule | Unchanged constraints |
|---|---|---|
| Configure admission action nominees | Current project-owner project.configure administrator, if this new surface is explicitly accepted | Explicit eligible participating users/company-scoped roles, exact project/PIPING/document/pinned scope, reasoned versions/suspension, no defaults; configurator not a reader/approver by default |
| Prepare engineering package | Explicit proposal nominee | Independent current source-reader access and original source/evidence visibility; exact accepted batch/revision/rows, pinned hierarchy, full stated coverage, immutable evidence/version closure |
| Technically review package | Separately nominated technical reviewer, not proposer | Verify authoritative historical topology and full package coverage, not today's DB tree. Resolve exact evidence integrity/retention/validity independently; do not assume file hash proves engineering sufficiency |
| Record reviewed admission | Separately current record grant; proposed: either proposer or reviewer may record | Exact independently reviewed package/version, fresh dependencies, archive/project/tenant checks and atomic append-only event; no ability to rewrite observations or self-approve |
| Correct/withdraw | Same separately approved scope of action with reasoned independent review, governed by already accepted lifecycle semantics | Superseding event, no UPDATE/DELETE, historical then-effective reports retained; withdrawal leaves current ambiguity, no automatic fallback to earlier admitted/selected material |

Recommended separation: proposer ≠ technical reviewer; recorder may be either only with an independent current recording grant. Alternative: three distinct users. These are **new admission** nominations, not automatic reuse of DA-02 nominations, MR approvers, PO issuers or receiving QA. User-ID separation does not verify natural-person identity. Eligible nomination does not prove technical competence by itself; project owner must explicitly assign the engineering responsibility. No technical role codes or company-type defaults are invented.

## Decisions requested — only genuinely open items

**SA-01:** accept owner-configured, explicit independent technical admission nominees for exact document/pinned-scope actions; or keep admission authority deferred and continue read-only engineering diagnostics only.

**SA-02:** if proceeding, choose independent two-person review plus separately granted recording (recommended), or three distinct proposer/reviewer/recorder users. Deferral remains an option. The rule applies to scope-admission packages only; DA-02 and existing purchasing/custody SoD stay closed.

These choices do not reopen already accepted revision replacement, scope/hierarchy pinning, all-pair additive evidence, correction/withdrawal, original-caller permissions, full-copy buying or DA-01..04. Exact version-bound CDE/external retained evidence must meet the existing contract; admission nomination does not grant CDE/library content, MTO snapshot acceptance, waiver, quantity tolerance, substitute material, warehouse posting or general project-read access.

## Implementation still required even after an actor policy answer

A new append-only admission sidecar and lifecycle, not changing UNADMITTED observation CHECKs; genuine independently resolved authoritative topology/coverage/evidence; authenticated same-snapshot source binding; complete visibility and validity dependency closure; concurrency/archive/revocation/idempotency/rollback and same-project FK/FORCE-RLS tests; proper scoped human facade. Partial visible rows must not become a complete green package. No authoritative capacity/reservation until effective selection and commitment completeness also pass. Actor approval does not certify these adapters or make historical data admitted.

Next deliverable after answers: record accepted/deferred choices bilingually, specify bounded admission policy/service integration against the actual source schemas, and implement only the approved slice with focused tests. Do not silently enable an ADMITTED flag to remove the blocker.

## Verification posture

Documentation/source audit only. No code edited, no runtime started and no tests rerun. Prior executable source manifests and local links checked in `docs/evidence/2026-10-23-scope-admission-authority/`. Previous 44/172 is historical for reader suspension, not a new admission result. No migration, human grant, production data, CI/deploy/commit/push.

## Explicit user choices — accepted 2026-10-23

- **SA-01=`owner_configured_explicit_technical_admission`:** owner-company current project.configure administrator explicitly nominates eligible participating people/company-scoped roles for separate proposal, technical-review and recording actions, limited to exact project/PIPING/document/pinned-scope boundaries. No defaults or purchase/QA/DA-02 inheritance. Independent source/evidence reading and current eligibility remain mandatory.
- **SA-02=`independent_review_separate_record_grant`:** proposer and technical reviewer differ; either may record only with a separate current recording nomination and exact independent reviewed package. Reasoned independently reviewed correction/withdrawal follows the accepted lifecycle. Existing purchase/custody person separation and DA-02 are unchanged.

These explicit form answers supersede the pending notices for SA-01/SA-02. The bounded technical-admission actor model is now **accepted**, not generally deferred. It does not select particular people/companies, grant source/CDE access, redefine existing accepted-MTO snapshot authority or activate any writer. No already-accepted replacement/additivity/lifecycle rule is reopened.

**Implementation remains absent:** no admission nomination screen/table, trusted engineering evidence adapter or operational admission event writer has been built by this documentation slice. Existing observations remain UNADMITTED. Technical content/coverage sufficiency, complete visibility and immutable authenticated evidence closure still need implementation. Effective selection and complete demand commitments remain independent gates before reservation. Next work can implement the bounded nomination/service slice without asking SA-01/SA-02 or DA-01..04 again; no automatic ADMITTED flag or default role may substitute for those gates.
