> **Current closeout —2026-10-19:** [reconciliation/evidence full GREEN](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-EN.md) / [فارسی](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-FA.md): **1169 tests/17146 assertions,0 failures/errors/skips,exit0;456 source+2 worker hashes unchanged**. Full pending gate CLOSED; older pending/next-full prose below is superseded history. **Separate DocumentRegister RLS/MAX collision NOT FIXED**, recommendation retained. No app edits this closeout; G01 OPEN, UNADMITTED/UNRESOLVED, gross null, no role/route/migration/OrderGap change. Next recommendation: decision-first numbering allocator review, not rerun of unchanged full gate. Recovery: epcc-source-recovery-reconciliation-evidence-full-2026-10-19.tar.gz; isolated PG stopped after closeout, runtime metadata transient.

# Decision reference index — MTO scope, evidence and authority

Updated 2026-10-18. This is a navigation/status index, NOT a parallel source of business rules. Read the authoritative source section linked for each row. Accepted domain design, implemented behavior and permission approval are different statuses. This review is limited to the MTO/scope/admission decisions below, not a certification of every project decision.

Companion: [FA](DECISION-REFERENCE-INDEX-FA.md).

| Topic | Recorded status | Authoritative source | Implementation boundary / action |
|---|---|---|---|
| Same-document revision replacement | Accepted domain principle | [ADR-005 §2.2](ADR-005-Engineering-Data-Ingestion.md), [ADR-024 recovered decisions](ADR-024-Cross-Source-Baseline-Reconciliation-EN.md) | NEW chronology exists; effective operational selector still missing. Do not sum historical revisions. |
| Immutable accepted-row identity and atomic acceptance | Accepted | [ADR-018 §9.1](ADR-018-Material-Management-Operational-Completion.md), [MTO requirements §§3.3–3.4](MTO-REQUIREMENTS-EN.md) | Snapshot foundation implemented; do not manufacture a second accepted-MTO authority. |
| Disjoint scope or exact-revision additive declaration | Accepted | [ADR-018 §§9.2–9.3](ADR-018-Material-Management-Operational-Completion.md), [requirements §3.5](MTO-REQUIREMENTS-EN.md) | No implicit inheritance; parent/descendant overlap. Not a new question. |
| Evidence for additive declarations | Accepted | [ADR-018 §9.2](ADR-018-Material-Management-Operational-Completion.md) | Exact CDE revision or controlled external record, retained/retrievable original and hash; hash alone insufficient. Reuse this baseline, do not invent evidence from arbitrary strings. |
| Stable scope / hierarchy revision pinning | Accepted design | [ADR-018 §9.4](ADR-018-Material-Management-Operational-Completion.md), [requirements §3.6](MTO-REQUIREMENTS-EN.md) | Nullable claimed pins/current graph are not authenticated historical topology. Old question about whether pins are needed is superseded. |
| Header or valid descendant; mixed rows | Accepted | [ADR-018 §9.4](ADR-018-Material-Management-Operational-Completion.md) | Missing/conflicting required scope blocks whole acceptance. Offline proposal now recognizes explicit ancestor chains; production adapter not changed. |
| Optional physical area | Accepted and built | [ADR-004](ADR-004-Physical-Breakdown-Plant-Pipeline-Subsystem.md) | Direct facility→subsystem is valid; do not invent an area. |
| Rename / split / merge / replacement | Accepted | [ADR-018 §§9.4–9.5](ADR-018-Material-Management-Operational-Completion.md) | Same-ID rename preserves coverage; new identities require confirmations; mapping alone does not close successor coverage. |
| Additive/mapping correction and withdrawal | Accepted domain semantics | [ADR-018 §9.2](ADR-018-Material-Management-Operational-Completion.md) | New superseding record; history never edited. Historical reports retain then-effective state; current withdrawal leaves ambiguity. NOT an entirely undecided lifecycle. |
| Immutable roll-up provenance | Accepted design | [ADR-018 §9.6](ADR-018-Material-Management-Operational-Completion.md) | New calculation creates new snapshot; unresolved is not zero. Persistence/read implementation remains work. |
| MTO permission/access redesign | Explicitly deferred by user | [FA handoff §3 item7](../CHAT-TRANSFER-HANDOFF-FA.md), [ADR-015 §5 item3](ADR-015-PO-Attribution-and-Order-Gap.md) | No new MTO roles/access gates without separate approval. EPC procurement allocation recording is not topology admission authority. |
| General security framework | SRS requirement, not a named MTO approver | [SRS §4](SRS-EPCC-Platform.md) | Tenant/project/RBAC/ABAC, delegation, SoD/DoA guide implementation; illustrative visibility matrix does not grant topology authority. |
| PMS author nomination | Separate policy | [ADR-026](ADR-026-PMS-Author-Policy-Boundary-EN.md), [ADR-027](ADR-027-PMS-WRITE-AUTHORIZATION-EN.md) | Never inherit PMS/MR rights as MTO/admission permissions. Read superseding owner corrections in those ADRs. |
| New topology/coverage admission actor and evidence adapter | Specific approved actor not found in reviewed records; implementation draft | [admission contract](ADR-024-SCOPE-ADMISSION-CONTRACT-EN.md) | Do not grant roles by inference. Technical verification can proceed independently; operational human authorization must respect the existing deferral. |
| Usable stock | Explicitly deferred | [ADR-018 §8](ADR-018-Material-Management-Operational-Completion.md) | Not changed by admission work. |

## How to use and maintain this index

Before a change: locate the applicable accepted decisions; check dated owner corrections/supersession; distinguish implemented source from proposed target; identify the genuinely missing detail. Update the source document or append a dated correction there, and update this link/status index. Do not replace decisions with summaries in handoff headers. Preserve historical evidence and mark superseded status explicitly. If authority/supersession is unclear, report the conflict instead of guessing.

## Reconciled gaps and recommendations now applied

- Requirements §8 retains historical questions but now identifies permission deferral and already-accepted hierarchy pinning.
- The offline contract no longer presents all correction/revocation semantics as a new business decision. ADR-018 additive/mapping semantics are the established baseline; precise new topology/coverage event targets, validity dependencies and transactional enforcement remain implementation design, not silently approved policy.
- Header equality-only was a prototype limitation, not the accepted rule. The validator now accepts same scope or a proven ancestry chain in the supplied pinned proposal. Missing ancestry stays blocked; even successful structure review remains UNADMITTED. The current DB header FK still references a subsystem; these synthetic ancestor tests do NOT enable parent/project headers in the application.
- Deferred permissions are not a blanket stop on technical work: versioning, evidence resolution design, immutable provenance, read-only consistency/drift checks and tests may proceed without granting new authority. A human admission writer/new access gate/role grant or changed operational selector is not approved by this reconciliation.

## Verification posture

46 standalone admission-proposal cases now pass (previous38 plus8 ancestry cases), with selection33/planner26 rerun separately. No app/migration/API/DB changes. All450 source hashes of the complete1112-test/16488-assertion application run remain unchanged. Do not add offline cases to PHPUnit. Current source gate: [full-suite report](G01-SCOPE-FULL-SUITE-EN.md). Correction evidence: `docs/evidence/2026-10-18-decision-reconciliation/`.

## 2026-10-23 — incoming-material traceability diagnostic

**Implemented:** caller-visible, read-only custody/latest amendment → exact PIPING PO/MR reference report; independently enforced MR visibility, buyer-only historical issue-line evidence, exact QC/QA ID binding, bilingual GET views and explicit unknown demand/stock. No new grant/migration, MTO read, allocation writer, quantity total, GRN or legacy cutover. Not a completeness/release certificate. Focused/affected **47 tests / 505 assertions PASS (130.18s)**; not a full regression. See [contract and evidence limits](PIPING-MATERIAL-TRACEABILITY-EN.md). Accepted policies remain unchanged; new MTO access is deferred. Effective-demand provenance and controlled posting/pilot are proposed next stages, not enabled by this report.

## 2026-10-23 — effective-demand provenance design

Offline executable proposal added: exact selected-row → MR-version/line explanation, stale basis and unknown commitment fail-closed diagnostics, integer per-row limits; **37 synthetic cases PASS**, related existing selector/planner/admission **33/26/46 PASS**. Not PHPUnit. [Contract and activation gates](MATERIAL-DEMAND-PROVENANCE-EN.md). No application adapter, DB/migration/grant, reservation/release policy, allocation writer or live MTO report. Always operationally unauthorized; MTO access remains deferred. Existing incoming report/purchasing/custody behavior unchanged.

## 2026-10-23 — demand basis reconciliation / تطبیق مبنای نیاز

Offline demand basis content-version/reconciliation proposal: **35 synthetic cases PASS**, predecessor provenance **37 PASS**, separate non-PHPUnit counts. No transfers/releases/capacity or live adapter. [Contract, persistence and transaction design](DEMAND-BASIS-RECONCILIATION-EN.md). Deferred MTO access/new writer authority not activated; next gate is a separate activation/lifecycle matrix.

## 2026-10-23 — demand activation decisions pending

Proposed demand source-reader/action matrix, two- versus three-person reconciliation separation, MR-approval versus PO-issue reservation, and reviewed release versus deferral submitted for explicit decisions DA-01..04. **All pending**; no inference of approval from “continue.” [Matrix](DEMAND-ACTIVATION-MATRIX-EN.md). Documentation only; unchanged executable-source hashes and local links checked, no tests rerun or grants/schema/workflow activated.

## 2026-10-23 — DA-01..04 accepted; not activated

DA-01..04 now explicitly accepted: independently scoped demand readers; proposer ≠ reviewer with independent apply grant; atomic reservation at final technical/commercial MR approval; reviewed reconciliation without automatic release. Bounded policy accepted, not runtime activated. General MTO admission, source completeness and technical gates remain unresolved. [Authoritative decisions §7](DEMAND-ACTIVATION-MATRIX-EN.md#7-explicit-user-decisions--accepted-2026-10-23). Earlier pending entry is superseded only for these choices.

## 2026-10-23 — isolated demand atomic approval proof

Isolated PostgreSQL atomic-final-approval protocol: **29 cases PASS**, including rollback, concurrent identical retry, competing MRs and blocked head/archive/grant invalidation. Not application tests or rollout. [Source adapter/seam design and limitations](DEMAND-ATOMIC-APPROVAL-EN.md). DA-01..04 retained; no app files/migrations/grants changed. Actual MTO reader admission/inventory and MR/PO integration still gated. Toy invoker DML privileges do not prove universal SQL invariants.

## 2026-10-23 — scoped demand reader internal facade

First DA-01 application slice: additive scoped reader policy migration + internal DemandReaderService, no routes/UI/default readers. **49 affected application tests /153 assertions PASS (85.03s)**, including 20 new reader cases and populated reinstall. Readiness always null capacity/unauthorized while effective selection/inventory adapters are absent. [As-built boundaries](DEMAND-READER-SERVICE-EN.md). No reservation/PO/stock/admission change or production migration.

## 2026-10-23 — scoped demand reader web surface

Owner-only bilingual scoped reader configuration GET/POST and independently nominated readiness GET implemented, no new schema/grant defaults/reservation. Affected **45/240 PASS**, corrected bilingual HTTP helper rerun **12/61 PASS**, overlapping not additive. [Web guide/known limitation](DEMAND-READER-WEB-EN.md). Hidden-source metadata-only suspension still needs a narrowly reducing follow-up; current grants require original source visibility. No deployment.

## 2026-10-23 — recorded reader suspension correction

Hidden-source suspension administration closed: owner-only recorded-head metadata register and reasoned empty suspended successor; no source lookup/new grant; target-company removal does not block reduction; unhide cannot revive old grant. Additive guard-only migration, down preserves history. **44/172 application PASS (66.83s)**; not full regression/deployed. [Guide](DEMAND-READER-SUSPENSION-EN.md). Effective admission/inventory/reservation still pending.

## 2026-10-23 — separate scope-admission authority gate

Scope-admission actor policy remains a separate unapproved gate, not inherited from DA-01..04. SA-01 independent technical nominations / SA-02 proposer-reviewer-recorder separation submitted as **pending** choices. [Matrix/source audit](SCOPE-ADMISSION-AUTHORITY-EN.md). No code/test/runtime/grant/schema change; prior reader-suspension source hashes unchanged.

## 2026-10-23 — SA-01/SA-02 accepted; not activated

SA-01/SA-02 explicitly accepted: owner-configured independent exact-scope admission nominees; proposer ≠ technical reviewer, recording by either only with a separate live grant. [Authoritative choices](SCOPE-ADMISSION-AUTHORITY-EN.md). Supersedes pending actor-policy notice only. No code/grant/writer activated; source/evidence/admission/selection/inventory technical gates remain. Do not re-ask accepted choices.

## 2026-10-24 — internal scope-admission actor policies

SA-01/02 first internal implementation: ScopeAdmissionAuthorityService + action-scoped append-only policies, independent current source-reader gate, proposal/review/record separation, empty recorded-key suspension and false package/admission readiness. **49 application tests/157 assertions PASS (106.36s)**, 17 new; no full regression/UI/admission writer. [Guide](SCOPE-ADMISSION-AUTHORITY-SERVICE-EN.md). No existing source or reader-service change; one migration-specific old test helper corrected.

## 2026-10-24 — bounded admission authority web interface

Implemented owner-only independent action nomination UI + source-free recorded-policy suspension register + own-source/action diagnostic booleans, with no client package-actor preflight/approval claims. **47 application tests/260 assertions PASS (103.48s),18 new HTTP**. Five authenticated routes, EN/FA/RTL, private/no-store GETs. Internal configure/preflight and preceding migrations/readers unchanged. No trusted package/review/evidence/admission writer, effective demand, reservation or production activation. [Guide](SCOPE-ADMISSION-AUTHORITY-WEB-EN.md). Earlier no-UI limitations superseded only for this bounded slice.

Final narrow input hardening rejects associative selector maps with HTTP422 before they can reach the JSON-array DB CHECK. After this controller-only/list-validation change, **all18 HTTP tests/152 assertions PASS (41.89s)** in final-http.log. The preceding47/260 affected run was before this final hardening; unchanged internal and reader-HTTP code was not needlessly rerun. Runs overlap, not additive.

## 2026-10-24 — source-bound unverified narrative envelopes

First additive internal source-bound **narrative** proposal/review persistence implemented; not the complete technical package. DB-derived immutable binding/actor policy traces and PostgreSQL-jsonb digest, per-proposer exact versions/predecessor, persisted-author independent review restricted to COMMENTED/CHANGES_REQUESTED, no opinion carry-forward or positive approval. Same-company/single-scope fail-closed limits; no new route/default grant/old executable edits. **57 application tests/292 assertions PASS,122.57s (22 new)**, including partial-visibility rejection, raw-SQL defenses and two-connection serialization/stale retry. No admission, effective selection or reservation. [Guide](SCOPE-PROPOSAL-ENVELOPES-EN.md). SA-01/02 remain settled; ADR024 draft authority-status correction is documented.

## 2026-10-24 — exact-ID source-bound envelope reading

Implemented separate ScopeProposalEnvelopeReader/controller/one private no-store GET+EN/FA detail for a known exact ID. Own proposer needs live propose+reader; another same-company actor needs live review+reader; owner/reader-only/record-only do not inherit a path. Hidden/partial/unauthorized returns opaque404. Exact historical version/opinion binding, corruption rejection, capped20 visible opinions with explicit more-warning, archive read-only and escaped text. **58 tests/371 assertions PASS125.32s,18 new**. No persistence/authority service, migration or RLS change, no positive review, structured declarations, admission/reservation or production activation. [Guide](SCOPE-PROPOSAL-ENVELOPE-READ-EN.md). Future record-only participant path remains to be implemented under settled SA-02, not permanently denied.

## 2026-10-24 — unverified structure declarations and narrative-review cutoff

Persisted internal unverified topology/coverage declaration sidecar, exact original-proposer/current proposal hash, canonical UUID/order and single-scope row coverage/frozen-visible identity checks. No evidence inputs. Crucial cutoff: opinion before declaration requires new proposal version; declaration before opinion disables old narrative-only review, app+DB project-serialized; no silent review carry-forward. **56 tests/290 assertions PASS135.80s (16 new)** incl two-PDO races both ways. No new route/UI/admission/positive review/reservation/production activation. [Guide](SCOPE-STRUCTURE-DECLARATIONS-EN.md). Existing reader/migrations intact; old writer only bounded cutoff, old migration fixture only child-before-parent ordering.

## 2026-10-25 — independent exact structure-bound non-approving opinions

Implemented internal exact narrative+structure-bound immutable opinions and same-original-context gated exact-ID inspect. Only COMMENTED/CHANGES_REQUESTED; no evidence/engineering approval/admission. Current independent review+reader, distinct persisted proposer/reviewer, both hashes/versions, all node/source visibility and latest pair revalidated in app+invoker DB guard. **Affected58/257 PASS167.61s; final focused20/95 PASS55.53s** after narrow reason/integrity hardening; overlapping counts. Two-PDO duplicate/supersession races, raw SQL and rollback covered. Prior production services/routes/guards/migrations unchanged; only two parent migration fixtures gain new child ordering. No UI/HTTP/new grants/positive authority/reservation/production activation. [Guide](SCOPE-STRUCTURE-REVIEWS-EN.md). Recommendation: evidence needs immutable composite identity; these opinions cannot cover later attachments.

## 2026-10-25 — original-caller evidence preflight; permanent composition blocked

Implemented internal strict current-pair/two-exact-reference diagnostics, original-caller CDE module/RBAC/actual-discipline plus per-table RLS before any Storage call, and final eligibility/source/all-descriptor checks. No domain writer/migration/attachment/package UUID/hash/default grants/approval/admission/reservation. Published foreign visibility uses existing CDE policy, never source-owner impersonation. Final16/85 PASS41.73s; unchanged38 companion tests passed in an earlier affected run with one subsequently corrected Plan test-fixture import error, not a clean54-pass run; overlap documented. Four PHP/static/RLS/prior-byte checks. Permanent retained composition and validity are not implemented. Identified legacy CDE controller missing discipline context; recommend separate focused correction, no silent global ACL edits. [Contract and evidence boundary](SCOPE-EVIDENCE-PREFLIGHT-EN.md). SA/DA choices unchanged, no deploy/commit/push.

## 2026-10-25 — bounded CDE personal-discipline read correction

Implemented existing register SQL discipline filter before eager loads/counts and actual-document doc.view context before review/download payload/Storage. Fresh original actor/plan; null=unrestricted under other gates,[]=empty; opaque404 for hidden/out-of-scope point resource. Existing writes, policies, roles, views/routes and evidence services unchanged. No new grant, package/evidence approval/admission/reservation. **72 tests/299 assertions PASS151.03s + disjoint19 review tests/79 assertions PASS36.71s =91 distinct targeted/378 across two final runs**, no full regression. Initial12/106 overlaps. Two PHP/static/RLS/prior byte checks. [Contract](CDE-READ-DISCIPLINE-EN.md). Residual: audit mutation/Filament/alternative scope handling; no global CDE closure or revocation lease. Retention/current validity/composition still blocked. No customer migration/deploy/commit/push.

## 2026-10-25 — controlled piping GRN / separate accepted-stock credit

User-authorized accepted-QA → independently nominated warehouse → atomic stock milestone implemented; original actor/source scope, exact latest QA/QC/amendment, full evidence commitment and byte checks. Whole receipt only, one immutable GRN/credit/derived posted marker, exact-decimal cumulative line ceiling; no legacy import or automatic PO/MTO/reservation update. Legacy recognized PIPING writes and post-GRN QC/QA/amendment writes closed; unknown stock is not certified. Final133/1066 PASS443.78s across seven affected suites incl21 new tests and two-PDO guard race. [Guide](PIPING-GRN-EN.md). This supersedes only bounded GRN/stock absence: reconciliation, independent correction/reversal, controlled issue and preservation remain gated. No full regression/customer migration/deploy/commit/push; permanent scope admission/DA03 unchanged.

## 2026-10-25 — issue authority/control design, pending approval

User selected documentation/design. [Proposed contract](PIPING-ISSUE-DESIGN-EN.md): D1 new independent issue actions/three-person separation; D2 separate current issue readiness/preservation evidence and restrictive holds without editing posted receiving QA; D3 exact-credit non-reserving dispatch, scoped read/evidence adapter, complete aggregate balances and separate recipient acknowledgment. Not approved policy or implementation; no code/schema/grant/test/deploy/commit/push. Receipt decisions retained; reservation/returns/installed consumption/MTO writers not implied.
