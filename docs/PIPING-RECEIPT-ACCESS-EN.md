> **Current 2026-10-23 — custody-amendment increment:** original-preparer/live-custody-grant source/Heat amendments are implemented; exact amended-basis fresh QC and QA are required. [Current guide](PIPING-CUSTODY-AMENDMENTS-EN.md). Earlier correction-as-proposal statements are historical. Controlled GRN/stock/legacy cutover are still unimplemented.
> **وضعیت جاری — اصلاحیهٔ تحویل‌گیری:** اصلاح منبع/Heat فقط توسط تهیه‌کنندهٔ اصلی با معرفی جاری اجرا شده است؛ QC و QA تازه روی همان نسخه لازم است. [راهنمای جاری](PIPING-CUSTODY-AMENDMENTS-EN.md). پیشنهاد قدیمی اصلاحیه اکنون اجرایی است؛ GRN/موجودی و گذار انبار قدیمی هنوز اجرا نشده‌اند.

> **وضعیت جاری ۲۰۲۶-۱۰-۲۳:** تصمیم نهایی QA با شواهد نسخه‌دار انسانی و منع پذیرش شرط باز اجرا شده است؛ [راهنمای جاری](PIPING-QA-EN.md). گزاره‌های قبلی دربارهٔ QA اجرا‌نشده/سیاست باز، تاریخی‌اند. GRN و گذار مسیر انبار قدیمی هنوز اجرا نشده‌اند.
> **Current 2026-10-23:** version-bound manual QA and open-condition blocking are implemented; [current guide](PIPING-QA-EN.md). Earlier unimplemented/pending QA statements are historical. Controlled GRN and legacy warehouse cutover remain unimplemented.

> **Receiving QC delivered — 2026-10-22:** [guide](PIPING-RECEIVING-QC-EN.md) / [فارسی](PIPING-RECEIVING-QC-FA.md). Immutable parent-bound receiving observations, independent current QC nomination/original source scope; actual MRR preparer cannot self-review.50/578 PASS (16 QC +20 custody +14 PO inspection). Only MRR≠QC executable: no QA/GRN/stock release or automatic technical checklist/accepted-with-comment policy. Earlier no-QC-action statements are historical for this increment. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

> **Physical custody / initial MRR delivered — 2026-10-22:** [guide](PIPING-PHYSICAL-CUSTODY-EN.md) / [فارسی](PIPING-PHYSICAL-CUSTODY-FA.md). Current nominees + independent source reading, and explicitly nominated recorder from any eligible participant accepted/implemented. Final20/242; related milestone60/438 and config text2/43 PASS, overlapping not additive. CUSTODY_ONLY, no QC/QA/GRN/stock/source-read grant. Earlier no-custody/no-resolver statements are historical for this increment. Actual three-person separation remains required for later actions. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

# Piping receipt responsibility configuration

2026-10-22 · **CONFIGURATION FOUNDATION IMPLEMENTED / TARGETED VERIFIED — NOT DEPLOYED**
[فارسی](PIPING-RECEIPT-ACCESS-FA.md) · [ADR-046](ADR-046-PIPING-RECEIPT-STORAGE-EN.md) · [First-slice contract](PIPING-RECEIPT-FIRST-SLICE-CONTRACT-EN.md) · [Evidence](evidence/2026-10-22-piping-receipt-access/README.md)

## What is usable now

Owner-company administrator with current project.configure and procurement/pack entitlement opens **Warehouse → Piping receipt responsibilities**. Dedicated GET/POST `/projects/{projectCode}/materials/receipt-access` uses original project participation, fresh active identity/company and current authorization. Other companies are denied even with project.configure; owner without that capability cannot view the directory or edit. GET is private/no-store.

Explicitly choose the participating procurement/pack-entitled custodian and at least one current PIPING/unrestricted project person or company-bound role for each independent action: custody/MRR, receiving QC, final QA acceptance, warehouse GRN. No custodian/nominee is selected by default and no role/type is automatically promoted. User selections also retain their company ID so later identity movement cannot be treated as an implicit nomination transfer. People/role options come only from active eligible members of participating companies, not a global directory. Recorded labels/custodian names are historical snapshots, not live foreign directory lookups.

Save a reasoned new version; stale versions are refused, never silently rebased. Suspension stores four empty action sets and no custodian. Old history remains append-only; latest20 versions displayed. Archive keeps authorized owner history read-only, hides the selection directory/form and refuses writes. EN/FA shell, language link, escaped labels/history, scoped safe form recovery and warehouse entry link included. Historical role labels retain their captured language.

## Crucial boundary

**This configures responsibilities; it does not implement physical receipt, MRR/QC/QA actions, GRN or stock posting.** No runtime action-policy resolver is exposed yet. New configuration selections are not new generic role capabilities and do not activate existing WarehouseService actions. No existing PO/inspection/document/MTO SELECT policy or warehouse issue/receipt behavior changes.

The approved three-person MRR/QC/QA rule is displayed; it must be enforced on actual actors when the future workflow is implemented. Overlapping selector sets are allowed because role sets can contain multiple people; overlap is not authorization for self-approval. GRN may use any earlier actor only with independent warehouse nomination. **No claim of runtime SoD enforcement for a nonexistent workflow.** Before action integration, implement a purpose-limited fresh nomination resolver without exposing this owner-only history to nominees or impersonating the owner.

Next vertical increment: controlled physical custody/MRR, original-visible exact-line source handling and current nomination enforcement; then receiving QC/QA acceptance/GRN with actual-actor separation and atomic stock transitions. Advanced accepted-with-comment/disposition/waiver, legacy enrollment/cutover, technical checklists and preservation policies remain governed by the recorded pending decisions. Structured prerequisite items remain deferred.

## Implementation / integrity

Forward-only `2026_10_22_100000_add_piping_receipt_author_configuration.php`: one empty-on-install append-only `piping_receipt_author_policies` table, owner-company/project FORCE RLS; SELECT/INSERT only, no participant-wide read, no UPDATE/DELETE policy. Invoker guard serializes policy versions with project advisory + project FOR NO KEY UPDATE locks, rechecks current SQL configurator/entitlement/writability and shape, stamps actor/time/custodian metadata server-side. No new SECURITY DEFINER, existing source table alteration, default nominees, backfill or stock rewrite. SQL data-shape and configurator guards do not claim universal per-human SQL RBAC or direct-SQL nominee entitlement validation; trusted app validates the current eligible selectors, and future actions must revalidate them independently.

## Executed evidence

`EPCC_RECEIPT_ACCESS_EVIDENCE=1 php artisan test --compact tests/Feature/PipingReceiptAccessTest.php tests/Feature/InspectionEntryTest.php` → **28 tests /278 assertions PASS**,43.97s, zero skipped:14 new configuration features +14 existing inspection features (`final-verified.log`). Covers owner/nonowner capability, bilingual private page/link, no defaults, current individual/company-role selection, same-version sequential stale refusal, suspension/history, invalid/forged/duplicate/nested payloads, active identity/membership/discipline/role/plan/pack, archive, SQL RLS/shape/configurator/server metadata, and populated archived forward install without business changes.

Subsequent view-only empty-selection recovery correction: affected2 tests/43 assertions PASS3.26s (`post-view-recovery-check.log`), overlapping, **not30 distinct tests**; Blade cache/clear passed. No true simultaneous two-connection race, HTTP crash/replay/idempotency, interactive browser, load, current-CI or full-suite certification claimed. Locks/unique constraint exist, but serial stale tests are not concurrency proof.

Initial migration failed on repeated migrate:fresh because a standalone function survived table drops; changed to CREATE OR REPLACE. Next run found JSON boolean suspension conditional-validation behavior; fixed the application using validated boolean + Rule::requiredIf, and corrected foreign-project fixture expectation403→404 without changing access guards. Expanded run caught two test-harness errors (missing exception import and dotted JSON error-key lookup); fixed test code, not SQL guards. Initial logs retained; earlier25/254 and failed/expanded runs overlap and are not added to final counts.

Final Pint/lint7 PHP files, Blade, local doc links, source hashes/archive and diff checks recorded. Private PG17 socket-only NOSUPERUSER/NOBYPASSRLS role (CREATEDB for existing harness), FORCE RLS and invoker function audited; server stopped at handoff. Eight predecessor feature hashes matched before this increment; no source recovery. Runtime rebuilt from Composer lock into excluded-cache vendor symlink.

No production migration, commit, push or deployment. No complete receipt/storage/preservation workflow claim. Previous policy/design-only notices are historical for this configuration increment, not supersession of accepted business decisions.
