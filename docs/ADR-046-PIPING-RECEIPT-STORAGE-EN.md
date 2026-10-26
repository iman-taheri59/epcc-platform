> **Current 2026-10-23 — custody-amendment increment:** original-preparer/live-custody-grant source/Heat amendments are implemented; exact amended-basis fresh QC and QA are required. [Current guide](PIPING-CUSTODY-AMENDMENTS-EN.md). Earlier correction-as-proposal statements are historical. Controlled GRN/stock/legacy cutover are still unimplemented.
> **وضعیت جاری — اصلاحیهٔ تحویل‌گیری:** اصلاح منبع/Heat فقط توسط تهیه‌کنندهٔ اصلی با معرفی جاری اجرا شده است؛ QC و QA تازه روی همان نسخه لازم است. [راهنمای جاری](PIPING-CUSTODY-AMENDMENTS-EN.md). پیشنهاد قدیمی اصلاحیه اکنون اجرایی است؛ GRN/موجودی و گذار انبار قدیمی هنوز اجرا نشده‌اند.

> **وضعیت جاری ۲۰۲۶-۱۰-۲۳:** تصمیم نهایی QA با شواهد نسخه‌دار انسانی و منع پذیرش شرط باز اجرا شده است؛ [راهنمای جاری](PIPING-QA-EN.md). گزاره‌های قبلی دربارهٔ QA اجرا‌نشده/سیاست باز، تاریخی‌اند. GRN و گذار مسیر انبار قدیمی هنوز اجرا نشده‌اند.
> **Current 2026-10-23:** version-bound manual QA and open-condition blocking are implemented; [current guide](PIPING-QA-EN.md). Earlier unimplemented/pending QA statements are historical. Controlled GRN and legacy warehouse cutover remain unimplemented.

> **Receiving QC delivered — 2026-10-22:** [guide](PIPING-RECEIVING-QC-EN.md) / [فارسی](PIPING-RECEIVING-QC-FA.md). Immutable parent-bound receiving observations, independent current QC nomination/original source scope; actual MRR preparer cannot self-review.50/578 PASS (16 QC +20 custody +14 PO inspection). Only MRR≠QC executable: no QA/GRN/stock release or automatic technical checklist/accepted-with-comment policy. Earlier no-QC-action statements are historical for this increment. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

> **Physical custody / initial MRR delivered — 2026-10-22:** [guide](PIPING-PHYSICAL-CUSTODY-EN.md) / [فارسی](PIPING-PHYSICAL-CUSTODY-FA.md). Current nominees + independent source reading, and explicitly nominated recorder from any eligible participant accepted/implemented. Final20/242; related milestone60/438 and config text2/43 PASS, overlapping not additive. CUSTODY_ONLY, no QC/QA/GRN/stock/source-read grant. Earlier no-custody/no-resolver statements are historical for this increment. Actual three-person separation remains required for later actions. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

> **Receipt nomination configuration delivered — 2026-10-22:** [guide](PIPING-RECEIPT-ACCESS-EN.md) / [فارسی](PIPING-RECEIPT-ACCESS-FA.md). Owner-admin-only screen, explicit custodian/four independent sets, version/suspension/history and FORCE RLS implemented.28/278 PASS; subsequent view2/43 overlaps. No custody/MRR/QC/acceptance/GRN action/resolver implemented, no default grants/source-read expansion. Earlier no-screen/no-schema prose is historical only for this increment. Not full-suite/current-CI/interactive-browser/deployed/committed/pushed; structured prerequisite items deferred.

# ADR-046 — Physical piping custody, controlled acceptance and authority design

2026-10-22 · **CORE POLICY AND RESPONSIBILITY MATRIX ACCEPTED / ADVANCED RULES PENDING / CONFIGURATION, CUSTODY AND RECEIVING OBSERVATIONS IMPLEMENTED; QA-GRN PLANNED**

[فارسی](ADR-046-PIPING-RECEIPT-STORAGE-FA.md) · [Detailed design and reference traceability](PIPING-RECEIPT-STORAGE-DESIGN-EN.md)

## Custody read/recorder amendment accepted — 2026-10-22

- `piping_custody_record_visibility=current_nominated_source_visible_readers`: any current eligible nominee in the four responsibilities reads; linked facts require independent exact source visibility. Owner/configurator alone is not a reader.
- `piping_custody_recorder_company=any_explicitly_nominated_participant`: any eligible current custody/MRR nominee may record for the selected custodian; preserve original actor/company, no tenant impersonation.

Implemented by the linked physical custody guide. This does not approve source read delegation, QC/QA/GRN execution, waiver or legacy cutover. Earlier design-only/currently-no-runtime claims below are historical for configuration and physical custody only.


## Explicit user choices

- `piping_receipt_acceptance_boundary=physical_custody_then_controlled_acceptance`: actual arrival/custody is recorded despite missing documents or failed/missing inspection; retain receiving/quarantine state. Accepted GRN requires authorized acceptance. HOLD/REJECTED material cannot be issued for ordinary consumption. Exception authority is a separate design.
- `piping_receipt_authority_design=design_matrix_before_grants`: submit the responsibility matrix for approval before activating any new authority. The reference's sample SMC/QC/QA/warehouse matrix is not an approved grant or mandatory separation-of-person policy.

## Additional explicit user selections — core matrix now accepted

- `receipt_acceptance_responsibility=qc_qa_warehouse`: material controller prepares custody/MRR; receiving QC reviews; QA gives final acceptance; warehouse posts GRN from that acceptance.
- `receipt_nomination_administrator=owner_configure_explicit`: current owner-company administrator with project.configure selects the participating custodian and independent action people/company-bound project roles. No default/inherited grants or source reading.
- `receipt_person_separation=preparer_qc_acceptor_distinct`: MRR preparer, QC reviewer and QA acceptor must be three different people; GRN poster may be any of them only with independent current warehouse authority.

The earlier matrix-before-grants condition is now satisfied for these core responsibilities, not for advanced waiver/disposition/preservation actions. Actual company/person selections remain project configuration, not questions to re-ask about this policy. [First-slice contract](PIPING-RECEIPT-FIRST-SLICE-CONTRACT-EN.md).

## Consequences and exact supersession

Preserve advisory **physical receipt**; distinguish custody, documentation, receiving QC, acceptance, accepted stock and preservation. Existing ADR-041 create-only PO observations are not receiving acceptance and do not automatically apply a verdict to lots. No strict no-IRN gate-entry lock was selected. Do not lose the physical fact because acceptance is incomplete.

Supersede only ADR-032 R4's blanket no-new-gate statement for the above explicitly accepted acceptance/ordinary-issue boundary. This does not approve R1 completed-order coverage, R2/R3 lineage/return/legacy implementation, global usable-stock definition, waiver/disposition, technical standards, payment gates or a migration design. PO authority/three-person finance/issue controls and MTO deferrals are unchanged.

The user's latest structured prerequisite-item design/implementation deferral remains. Source-driven receipt design is the current selected work, not implementation approval for the separate recommendation ordering in the prior core-gap review.

## Remaining decisions / design

Accepted-with-comment blocking classification; override/disposition/reinspection; legacy enrollment/enforcement; quantity tolerance/lineage/returns and completed-order coverage; numbering/idempotency engineering; technical checklist revisions and preservation/integration authorities. The core configurator, QA acceptance responsibility and three-person rule above are CLOSED. Nominee names/companies are values for the future owner-only configuration screen. No new grant or screen is implemented by recording these choices.

## Implementation and verification

Documentation/reference reconciliation only. No application/schema/grant/stock transition changed; proposed runtime cases have not run. Original supplied reference preserved byte-identically and hashed. Application manifest, local links and whitespace checks recorded in [evidence](evidence/2026-10-22-piping-receipt-design/README.md). Not production/CI/runtime/pilot certification; no commit/push/deployment.
