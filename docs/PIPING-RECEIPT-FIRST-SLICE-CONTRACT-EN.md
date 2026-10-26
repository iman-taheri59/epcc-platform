> **Current 2026-10-23 — custody-amendment increment:** original-preparer/live-custody-grant source/Heat amendments are implemented; exact amended-basis fresh QC and QA are required. [Current guide](PIPING-CUSTODY-AMENDMENTS-EN.md). Earlier correction-as-proposal statements are historical. Controlled GRN/stock/legacy cutover are still unimplemented.
> **وضعیت جاری — اصلاحیهٔ تحویل‌گیری:** اصلاح منبع/Heat فقط توسط تهیه‌کنندهٔ اصلی با معرفی جاری اجرا شده است؛ QC و QA تازه روی همان نسخه لازم است. [راهنمای جاری](PIPING-CUSTODY-AMENDMENTS-EN.md). پیشنهاد قدیمی اصلاحیه اکنون اجرایی است؛ GRN/موجودی و گذار انبار قدیمی هنوز اجرا نشده‌اند.

> **وضعیت جاری ۲۰۲۶-۱۰-۲۳:** تصمیم نهایی QA با شواهد نسخه‌دار انسانی و منع پذیرش شرط باز اجرا شده است؛ [راهنمای جاری](PIPING-QA-EN.md). گزاره‌های قبلی دربارهٔ QA اجرا‌نشده/سیاست باز، تاریخی‌اند. GRN و گذار مسیر انبار قدیمی هنوز اجرا نشده‌اند.
> **Current 2026-10-23:** version-bound manual QA and open-condition blocking are implemented; [current guide](PIPING-QA-EN.md). Earlier unimplemented/pending QA statements are historical. Controlled GRN and legacy warehouse cutover remain unimplemented.

> **Receiving QC delivered — 2026-10-22:** [guide](PIPING-RECEIVING-QC-EN.md) / [فارسی](PIPING-RECEIVING-QC-FA.md). Immutable parent-bound receiving observations, independent current QC nomination/original source scope; actual MRR preparer cannot self-review.50/578 PASS (16 QC +20 custody +14 PO inspection). Only MRR≠QC executable: no QA/GRN/stock release or automatic technical checklist/accepted-with-comment policy. Earlier no-QC-action statements are historical for this increment. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

> **Physical custody / initial MRR delivered — 2026-10-22:** [guide](PIPING-PHYSICAL-CUSTODY-EN.md) / [فارسی](PIPING-PHYSICAL-CUSTODY-FA.md). Current nominees + independent source reading, and explicitly nominated recorder from any eligible participant accepted/implemented. Final20/242; related milestone60/438 and config text2/43 PASS, overlapping not additive. CUSTODY_ONLY, no QC/QA/GRN/stock/source-read grant. Earlier no-custody/no-resolver statements are historical for this increment. Actual three-person separation remains required for later actions. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

> **Receipt nomination configuration delivered — 2026-10-22:** [guide](PIPING-RECEIPT-ACCESS-EN.md) / [فارسی](PIPING-RECEIPT-ACCESS-FA.md). Owner-admin-only screen, explicit custodian/four independent sets, version/suspension/history and FORCE RLS implemented.28/278 PASS; subsequent view2/43 overlaps. No custody/MRR/QC/acceptance/GRN action/resolver implemented, no default grants/source-read expansion. Earlier no-screen/no-schema prose is historical only for this increment. Not full-suite/current-CI/interactive-browser/deployed/committed/pushed; structured prerequisite items deferred.

# Piping receipt — approved responsibility matrix and first implementation contract

2026-10-22 · **CORE RESPONSIBILITY MATRIX ACCEPTED / IMPLEMENTATION CONTRACT PROPOSED / CONFIGURATION, CUSTODY AND RECEIVING OBSERVATIONS IMPLEMENTED; QA-GRN PLANNED**

[فارسی](PIPING-RECEIPT-FIRST-SLICE-CONTRACT-FA.md) · [ADR-046](ADR-046-PIPING-RECEIPT-STORAGE-EN.md) · [Full reference-driven design](PIPING-RECEIPT-STORAGE-DESIGN-EN.md)

## 1. Newly closed decisions — do not re-ask

User selected:

- `receipt_acceptance_responsibility=qc_qa_warehouse`: material controller prepares custody/MRR; QC records receiving review; QA makes final acceptance; warehouse posts GRN against that acceptance.
- `receipt_nomination_administrator=owner_configure_explicit`: current owner-company project.configure administrator explicitly selects the participating custodian company and action users or company-scoped roles. No default or inherited action grant; no automatic PO/certificate access.
- `receipt_person_separation=preparer_qc_acceptor_distinct`: MRR preparer, QC reviewer and QA acceptor are three different people. GRN poster may be one of those people **only with the independently assigned warehouse grant**. GRN posting is not a second acceptance decision.

Together with the previous physical-custody/controlled-acceptance decision, these close the **core** authority design. Actual project/company/person selections are future configuration values, not missing product decisions. Advanced exception/disposition/preservation actions were not approved through these choices.

| Action key proposed | Functional responsibility | Approved authority boundary |
|---|---|---|
| `custody_mrr` | Material controller/SMC | Independently nominated; actual custody and MRR creation, not acceptance |
| `receiving_qc` | Receiving QC | Independently nominated; not automatically the existing PO inspection grant |
| `acceptance_qa` | QA acceptance | Independently nominated; actor differs from MRR preparer and QC reviewer |
| `post_grn` | Warehouse | Independently nominated; references the authorized acceptance; may overlap any earlier person |
| Configuration | Owner-company administrator with current project.configure | Select participating custodian and action selectors; no self-executing warehouse/QA grant |

The labels are functions, not permission inferred from a company type, job title or role name. Assigning a role selector binds it to its company; matching is refreshed against actual project role membership. A nomination is not evidence of professional qualification or legal signature. Person separation must bind to authoritative recorded actor identity, not display names; multiple accounts are not proof of distinct physical humans.

## 2. First implementable increment: nomination configuration foundation

Build the owner-only configuration entry point first, not a receive button wired to the legacy service. Proposed data contract:

- project, explicitly selected participating custodian company, configuration UUID/version, reason, server actor/company/time;
- independent person/company-role selector sets for the four actions above;
- initially UNCONFIGURED, with no synthesized default selections; suspension/replacement is a new retained version, never an in-place history edit;
- stale expected version refused; selector shape, current active identity/project participation, company/role binding and module/pack checked freshly;
- only the current owner-company project.configure administrator can view the configuration directory or write the policy; no global personnel directory;
- target selection does not expose source PO lines or certificates. Operational actors must independently retain original source reading; nomination alone cannot reveal hidden documents;
- all nomination configuration is read-only on archived projects; implementation must preserve read history without permitting writes;
- expose status honestly: “responsibilities configured” is not “receipt workflow operational” until the custody/QC/acceptance/GRN routes and guards are integrated.

The exact table/API/route and RLS implementation are still engineering design, not migrations already delivered. Before implementation, examine the current InspectionAccessService pattern without copying its all-participant configuration-read policy or treating its inspector-type restriction as an approved receiving-QC rule. Receiving roles are separate from ADR-041 PO observations. All action checks must later re-resolve current nominations; retained labels alone never authorize an action.

## 3. Following vertical slice: physical custody → accepted stock

| Stage | Proposed input/evidence | Server-side requirement |
|---|---|---|
| Custody/MRR | shipment/package, actual count/unit, heat/batch, receiving/quarantine location; exact original-visible PO line when available | Record real arrival despite missing MTC/IRN or QC. Missing/unresolved source stays unresolved; no fake PO or read delegation. |
| Document/QC review | versioned applicable checklist, measurements, files/revisions, findings, receiving decision | Fresh QC nomination and scope; person differs from MRR preparer. Uploaded file is not verified compliance. |
| QA acceptance | exact reviewed receipt revision, QC evidence and reason | Fresh QA nomination; third person; detect changes after QC. Accepted-with-comment/blocking checklist policy remains pending, not auto-approved. |
| GRN | exact current authorized acceptance, quantity/heat splits, qualified location | Fresh warehouse nomination, exact revision, atomic/idempotent stock posting and no duplicate credit. GRN actor may equal earlier actors. |
| Ordinary consumption issue | exact accepted lot and quantity | HOLD/REJECTED blocked; moving quarantine stock to another location does not release it. Legacy cutover still needs its separately recorded policy. |

A proposed unplanned custody record can preserve the physical fact without resolving a hidden/cancelled/missing source, but cannot silently post an accepted source-linked GRN. Exact treatment of exceptional acceptance is not authorized yet. No financial approval/payment or PO/MR commitment state is changed by this design. Existing ordinary receipt service is not yet this workflow.

Proposed concurrency/immutability contract: version-bound before/after evidence; canonical lock order shared with relevant project/source mutations; fresh eligibility/source/quantity checks after waiting; atomic acceptance/GRN ledger and late-failure rollback; append-only corrections/reversals. Revocation after earlier form display must stop a new action. Existing commercial source freeze is retained. These are acceptance targets, not claims of implemented locks.

## 4. Focused tests to accompany implementation — not executed

| ID | Scenario / required result |
|---|---|
| RCFG-01 | No configuration: no default/inherited permission or custodian inferred from first company. |
| RCFG-02 | Owner-company current project.configure can nominate; non-owner with the same generic capability cannot. Owner without capability also cannot. |
| RCFG-03 | Only active eligible project-member/person or company-bound role selectors accepted; foreign project/company, malformed and forged server fields refused. |
| RCFG-04 | Same-version contention: one new version and stale refusal; suspension preserves older records; no UPDATE/DELETE/history backfill. |
| RCFG-05 | Nominations neither unlock hidden PO/certificate content nor grant the old PO inspection action. Directory visible only to configuring authority. |
| RCFG-06 | Archived project rejects configuration writes; revocation/participation/company/role/module/pack changes rechecked. |
| RSEP-01 | MRR=QC, MRR=QA or QC=QA each independently refused; comparisons do not use display names or company IDs as person identity. |
| RSEP-02 | Three distinct authorized people succeed; GRN by any one succeeds only with separate current warehouse nomination. No fourth-person requirement invented. |
| RFLOW-01 | Missing evidence/failed QC permits physical registration, not accepted GRN; HOLD/REJECTED blocks ordinary issue. |
| RFLOW-02 | Repeated code/mixed units/partial shipment/multiple heats/stale review/duplicate GRN cannot create false credit. |
| RFLOW-03 | Changes to reviewed receipt or eligibility during lock wait invalidate old approval/posting; rollback retains no partial stock/decision. |
| RFLOW-04 | FA/EN entry, safe form recovery, history escaping and bounded read access demonstrated via actual HTTP paths. |

Use targeted tests per increment; cross-module milestone/release full run is separate. Source/runtime capabilities must be rebuilt and verified before claiming test execution; no runtime test has run in this contract delivery.

## 5. Still open — do not mislabel core matrix as pending

Open: blocking versus nonblocking accepted-with-comment conditions; technical checklist applicability; exception/waiver/disposition; legacy enrollment/issue enforcement; over-delivery tolerance and amendment/return closure; immutable numbering implementation; detailed preservation authority and integration scope. These do not reopen the three authority selections above, but any dependent action must wait for its applicable policy. Structured prerequisite items and new MTO/admission rights remain deferred.

## Delivery status

Core matrix finalized in ADR-046 and companion design; first increment and focused acceptance contract recorded. **No nomination screen, nomination grant, custody form, MRR acceptance or GRN posting has been implemented/activated in this delivery.** No app/schema change, runtime/database/browser/CI test, commit, push or deployment. This document is a concrete implementation handoff, not a feature-completion claim.
