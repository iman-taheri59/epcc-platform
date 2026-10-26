> **Current 2026-10-23 — custody-amendment increment:** original-preparer/live-custody-grant source/Heat amendments are implemented; exact amended-basis fresh QC and QA are required. [Current guide](PIPING-CUSTODY-AMENDMENTS-EN.md). Earlier correction-as-proposal statements are historical. Controlled GRN/stock/legacy cutover are still unimplemented.
> **وضعیت جاری — اصلاحیهٔ تحویل‌گیری:** اصلاح منبع/Heat فقط توسط تهیه‌کنندهٔ اصلی با معرفی جاری اجرا شده است؛ QC و QA تازه روی همان نسخه لازم است. [راهنمای جاری](PIPING-CUSTODY-AMENDMENTS-EN.md). پیشنهاد قدیمی اصلاحیه اکنون اجرایی است؛ GRN/موجودی و گذار انبار قدیمی هنوز اجرا نشده‌اند.

> **وضعیت جاری ۲۰۲۶-۱۰-۲۳:** تصمیم نهایی QA با شواهد نسخه‌دار انسانی و منع پذیرش شرط باز اجرا شده است؛ [راهنمای جاری](PIPING-QA-EN.md). گزاره‌های قبلی دربارهٔ QA اجرا‌نشده/سیاست باز، تاریخی‌اند. GRN و گذار مسیر انبار قدیمی هنوز اجرا نشده‌اند.
> **Current 2026-10-23:** version-bound manual QA and open-condition blocking are implemented; [current guide](PIPING-QA-EN.md). Earlier unimplemented/pending QA statements are historical. Controlled GRN and legacy warehouse cutover remain unimplemented.

# Piping receiving QC observations

2026-10-22 · **IMPLEMENTED / TARGETED VERIFIED / NOT DEPLOYED**
[فارسی](PIPING-RECEIVING-QC-FA.md) · [Physical custody](PIPING-PHYSICAL-CUSTODY-EN.md) · [ADR-046](ADR-046-PIPING-RECEIPT-STORAGE-EN.md) · [Evidence](evidence/2026-10-22-piping-receiving-qc/README.md)

## Approved authority, not a new role or acceptance rule

Implements the already approved `receiving_qc` nomination and the MRR-preparer/QC separation edge of `preparer_qc_acceptor_distinct`. No inherited owner/configurator, custody, QA, warehouse or ADR-041 PO-inspection write grant. Overlapping nomination selectors are permitted, but the actual custody.created_by cannot review that same receipt, even after nomination replacement. Two different actor IDs within the same company may review; equal display names are not grounds for refusal. Authoritative identity currently means User.id; the platform does not prove distinct physical humans across multiple accounts.

Review read scope **inherits the approved parent receipt scope**: current eligible nominee in any of the four receipt responsibilities, plus independent original PO/line visibility for linked custody. No owner/configurator read bypass, company impersonation, participant-wide source SELECT or certificate/MTO read grant. Hidden parent means hidden QC history and fingerprint. Current active identity/company binding/project membership/discipline/role/module/pack/custodian checks reuse the existing fresh resolver. Archive retains authorized history read-only; nomination suspension/revocation closes affected read/write.

## Using the screen

From **Warehouse → Physical custody / MRR register → receipt detail → Receiving QC observations**, open `/projects/{projectCode}/materials/piping-custody/{receiptId}/receiving-qc?lang=en|fa`. GET private/no-store; no form without current receiving-QC nomination, if archived, or if the viewer prepared the MRR. Other eligible receipt readers see history only.

Record actual review timestamp with explicit offset, stated review scope/evidence/findings/limitations and one explicit observation result (no preselected result):

| Stored observation | Meaning / limit |
|---|---|
| NO_NONCONFORMITY_OBSERVED | Within the stated scope, no nonconformity observed; **not** technical compliance certification, unconditional PASS or QA acceptance. |
| NONCONFORMITY_OBSERVED | Nonconformity observed; narrative retained, no automatic NCR/disposition/waiver or stock transition. |
| INCOMPLETE | Review/evidence incomplete, including missing MTC/IRN; records the fact without manufacturing compliance. |

Technical checklist applicability/revisions, mandatory evidence/blocking rules and accepted-with-comment policy are **not** silently decided or implemented. Narrative is mandatory for all three results. Missing evidence/unresolved source can be recorded as a receiving observation; no result releases material. Date syntax/offset is validated and the instant is shown UTC; original offset text and chronology/future-date verification are not additional assertions. Files/certificate validation, structured checklist items, quantities/heat edits and formal QC report/PDF are not in this increment.

Each append retains old reviews, server actor/name/company/time, current policy UUID, parent custody UUID and a timezone-stable SHA-256 of the **entire immutable custody record** (UTC-normalized timestamps). This is a receipt content fingerprint, not the PO source fingerprint, a new custody revision or a hash of external files. Source state remains the custody snapshot; current PO-content reconciliation is not technical acceptance.

History paginates20 versions, with global latest version shown separately. New appends require the exact latest previous_review_id and current nomination policy. Stale forms are refused without silently rebasing the empty first-review head. Safe old input is scoped to both project and custody ID. A UUID request key, same caller/policy/target/payload, returns the original review once; replay of an older review within unchanged current authority remains idempotent after later reviews. Changed payload/caller/target, hidden-key collision or stale policy is refused, not overwritten. New keys are new review entries, not narrative deduplication.

## Implementation / integrity

- Dedicated PipingReceivingReviewController / PipingReceivingReviewService, EN/FA strings, page and parent-detail link; existing custody/stock/PO-inspection services are not reused as receiving decisions. No custody state/material_lot/movement/commercial update.
- Forward-only `2026_10_22_120000_add_piping_receiving_reviews.php` adds one empty `piping_receiving_reviews` table, FORCE RLS SELECT/INSERT only, append-only guard and FK provenance including previous review. No defaults/backfill/QA/GRN. Forward install over populated archived custody is tested.
- Invoker SQL guard shares the existing configuration/custody advisory and project NO KEY UPDATE lock domain, freshly resolves action/parent after waiting and again after lookup, enforces actual actor separation and previous-head compare-and-set, stamps metadata/sequence/fingerprint. No SECURITY DEFINER or new owner/source lookup hatch.
- **Do not add parent UPDATE policy just to lock it.** PostgreSQL FOR SHARE on the append-only parent was filtered by its absent UPDATE RLS policy in development. The parent is already immutable and FK-bound; QC reads it under unchanged original SELECT scope while serializing appends/policy changes in the shared project domain. It never weakens custody UPDATE/DELETE protection or claims mutable parent row-lock certification.
- Late insert failure rolls back the review; no partial history/stock. App GUC/DB-role trust boundary remains the platform's original one, not per-human independently authenticated SQL or hostile-DDL resistance. Same-version numbering is per receipt, not a new formal certificate numbering policy.

## Verification and limitations

`EPCC_RECEIVING_QC_EVIDENCE=1 php artisan test --compact tests/Feature/PipingReceivingReviewTest.php tests/Feature/PipingCustodyTest.php tests/Feature/InspectionEntryTest.php`

**50 tests /578 assertions PASS,100.61s, zero skipped**:16 new receiving-QC +20 existing custody +14 existing PO inspection (`final-focused.log`). Coverage includes no inherited write/owner read; self-review denied HTTP and SQL; different same-company actor IDs accepted; bilingual private shell and escaped findings; original hidden source; immutable versions; fresh nominations/role/activity/archive; strict payload/date/result validation; scoped empty-head recovery; stale/policy/retry refusal; missing actor/project RLS; SQL server stamping and timezone-stable hash; late-failure rollback; populated forward install. Real isolated PHP/PG workers observed waiting on the exact advisory key: duplicate requests produce one review, parent-session newer append makes the waiting independent request stale, nomination revocation denies the waiting review.

Failed/partial runs retained and **not summed**: self-referential FK had to be attached after the primary key; rebuilt private DB initially defaulted SQL_ASCII and was recreated explicitly UTF8; immutable-parent FOR SHARE was removed without loosening RLS; worker Process import restored after Pint removed it before the worker helper was added. No business authority was relaxed to make tests pass. Composer lock dependencies and PHP/PG rebuilt; predecessor16-file source hashes matched before this increment. Actual sanitized EN/FA HTTP review pages retained, not browser screenshots. Pint/lint11 PHP, Blade/routes, links, source hashes and checked source archive recorded; private socket-only PG17 NOSUPERUSER/NOBYPASSRLS role audited and server stopped at handoff.

**Remaining:** final QA acceptance with a third actual person, version-bound reviewed evidence and technical blocking policy; independent GRN/accepted-stock/idempotent posting; legacy cutover, waiver/disposition, preservation and formal reports. Only MRR≠receiving-QC is executable now; no claim of a fully executed three-person QA chain. Structured ITP prerequisite design/implementation stays deferred. Existing WarehouseService/ADR-041 observations remain unchanged. No full-suite, browser/load/current-CI/production migration/commit/push/deployment claim.

**Recommendation before enabling QA:** explicitly settle applicable mandatory evidence/checklist and accepted-with-comment/waiver blocking rules. Do not infer acceptance from NO_NONCONFORMITY_OBSERVED, missing-doc narrative or the mere presence of a QC review. These are recommendations/pending policy, not new runtime approval gates in this delivery.
