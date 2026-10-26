> **Current 2026-10-23 — custody-amendment increment:** original-preparer/live-custody-grant source/Heat amendments are implemented; exact amended-basis fresh QC and QA are required. [Current guide](docs/PIPING-CUSTODY-AMENDMENTS-EN.md). Earlier correction-as-proposal statements are historical. Controlled GRN/stock/legacy cutover are still unimplemented.
> **وضعیت جاری — اصلاحیهٔ تحویل‌گیری:** اصلاح منبع/Heat فقط توسط تهیه‌کنندهٔ اصلی با معرفی جاری اجرا شده است؛ QC و QA تازه روی همان نسخه لازم است. [راهنمای جاری](docs/PIPING-CUSTODY-AMENDMENTS-EN.md). پیشنهاد قدیمی اصلاحیه اکنون اجرایی است؛ GRN/موجودی و گذار انبار قدیمی هنوز اجرا نشده‌اند.

> **وضعیت جاری ۲۰۲۶-۱۰-۲۳:** تصمیم نهایی QA با شواهد نسخه‌دار انسانی و منع پذیرش شرط باز اجرا شده است؛ [راهنمای جاری](docs/PIPING-QA-EN.md). گزاره‌های قبلی دربارهٔ QA اجرا‌نشده/سیاست باز، تاریخی‌اند. GRN و گذار مسیر انبار قدیمی هنوز اجرا نشده‌اند.
> **Current 2026-10-23:** version-bound manual QA and open-condition blocking are implemented; [current guide](docs/PIPING-QA-EN.md). Earlier unimplemented/pending QA statements are historical. Controlled GRN and legacy warehouse cutover remain unimplemented.

> **Receiving QC delivered — 2026-10-22:** [guide](docs/PIPING-RECEIVING-QC-EN.md) / [فارسی](docs/PIPING-RECEIVING-QC-FA.md). Immutable parent-bound receiving observations, independent current QC nomination/original source scope; actual MRR preparer cannot self-review.50/578 PASS (16 QC +20 custody +14 PO inspection). Only MRR≠QC executable: no QA/GRN/stock release or automatic technical checklist/accepted-with-comment policy. Earlier no-QC-action statements are historical for this increment. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

> **Physical custody / initial MRR delivered — 2026-10-22:** [guide](docs/PIPING-PHYSICAL-CUSTODY-EN.md) / [فارسی](docs/PIPING-PHYSICAL-CUSTODY-FA.md). Current nominees + independent source reading, and explicitly nominated recorder from any eligible participant accepted/implemented. Final20/242; related milestone60/438 and config text2/43 PASS, overlapping not additive. CUSTODY_ONLY, no QC/QA/GRN/stock/source-read grant. Earlier no-custody/no-resolver statements are historical for this increment. Actual three-person separation remains required for later actions. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

> **Receipt nomination configuration delivered — 2026-10-22:** [guide](docs/PIPING-RECEIPT-ACCESS-EN.md) / [فارسی](docs/PIPING-RECEIPT-ACCESS-FA.md). Owner-admin-only screen, explicit custodian/four independent sets, version/suspension/history and FORCE RLS implemented.28/278 PASS; subsequent view2/43 overlaps. No custody/MRR/QC/acceptance/GRN action/resolver implemented, no default grants/source-read expansion. Earlier no-screen/no-schema prose is historical only for this increment. Not full-suite/current-CI/interactive-browser/deployed/committed/pushed; structured prerequisite items deferred.

> **Core receipt matrix accepted — 2026-10-22:** [first-slice contract](docs/PIPING-RECEIPT-FIRST-SLICE-CONTRACT-EN.md) / [فارسی](docs/PIPING-RECEIPT-FIRST-SLICE-CONTRACT-FA.md). Material-controller MRR → QC review → QA acceptance → warehouse GRN; owner-company current project.configure nominates independently. MRR/QC/QA are three different people; GRN may overlap with its independent grant. These choices are CLOSED; earlier pending-matrix prose applies only to advanced actions now. No runtime grant/screen/receipt implemented; structured prerequisite items deferred.

> **Supplied receipt reference / user policy — 2026-10-22:** [receipt-to-storage design](docs/PIPING-RECEIPT-STORAGE-DESIGN-EN.md) / [فارسی](docs/PIPING-RECEIPT-STORAGE-DESIGN-FA.md), [ADR-046](docs/ADR-046-PIPING-RECEIPT-STORAGE-EN.md). Independent physical custody plus controlled acceptance/no ordinary HOLD or REJECTED issue accepted; authority matrix must be approved before activation. Only this boundary supersedes ADR-032 R4’s blanket no-new-gate statement; other pending rules remain unapproved. Current selected work is reference-driven design, not the prior allocator recommendation or receipt activation. No app/schema/grant change; structured prerequisite items remain deferred.

> **Current review / user deferral — 2026-10-20:** [core gaps and priorities](docs/CORE-GAP-REVIEW-2026-10-20-EN.md) / [فارسی](docs/CORE-GAP-REVIEW-2026-10-20-FA.md). Structured prerequisite item design AND implementation explicitly deferred; free text retained. Source/document review, not a test run or new authority/schema approval. Recommended next: focused document allocation review, then warehouse provenance/arithmetic diagnostics; next user-visible target is exact-line PO receipt after needed decisions. Conflicting older status/“current full source green” statements below are historical evidence, not this review’s current verification.

> **Approved PDF continuation — 2026-10-20:** [current prerequisite appendix](docs/INSPECTION-PREREQUISITE-PDF-EN.md) / [فارسی](docs/INSPECTION-PREREQUISITE-PDF-FA.md). Selected prerequisite_pdf implemented: all records/full history/retained stage, separate from issuance snapshot; same scope, no new schema/authority/writes.409 on changes during render.20/1102 + separate4/60 PASS; not full-suite/current-CI/deployed. Only prerequisite-PDF deferral and contradictory historical prose below are superseded; other decisions retained.

> **Current delivery — 2026-10-20:** [original-notice status filter for prerequisites](docs/INSPECTION-PREREQUISITE-ACTIVE-NOTICE-FILTER-EN.md) / [فارسی](docs/INSPECTION-PREREQUISITE-ACTIVE-NOTICE-FILTER-FA.md). User-selected active_notice_filter: All default, not-cancelled/cancelled, independent of prerequisite state; no writes/authority/schema/PDF. Company-picker/count scope retained;36/2008 PASS. Seven prior source hashes matched, no recovery needed; vendor remains excluded-cache symlink. Not full-suite/current-CI/browser/deployed/committed/pushed; private DB stopped after checks.

> **Current delivery — 2026-10-20:** [readable prerequisite company picker](docs/INSPECTION-PREREQUISITE-COMPANY-PICKER-EN.md) / [فارسی](docs/INSPECTION-PREREQUISITE-COMPANY-PICKER-FA.md). User-selected company_picker: captured name/code from visible declaring companies only, literal search/25-option pages, pinned selection/independent pages. No new directory/access/schema/write/PDF.56/1793 PASS; not full-suite/current-CI/browser/deployed/committed/pushed.16 prior sources restored from checked archive with differences preserved; new bundle in evidence; private DB stopped after checks.

> **Current delivery — 2026-10-20:** [project prerequisite tracking](docs/INSPECTION-PREREQUISITE-REGISTER-EN.md) / [فارسی](docs/INSPECTION-PREREQUISITE-REGISTER-FA.md). Selected tracking_register: status/declaring-company identity/literal notice filters,25-row pages and visible-only counts, cancelled-original label; no new writes/authority/schema/PDF. Missing inherited-stage workspace source restored/verified; recovery report/source archive in evidence. Final47/1051 + separate legacy1/12 PASS; earlier runs overlap. Not full-suite/current-CI/browser/deployed/committed/pushed; private DB stopped after checks.

> **Current delivery — 2026-10-20:** [exact inherited ITP context for prerequisites](docs/INSPECTION-PREREQUISITE-ITP-LINK-REVIEW-EN.md) / [فارسی](docs/INSPECTION-PREREQUISITE-ITP-LINK-REVIEW-FA.md). User-selected inherited_stage implemented: retained notice snapshot only in create/current/history context; no live sources, new schema/access/backfill, structured sub-items or PDF changes.54/807 + separate legacy1/12 PASS; not full-suite/browser/current-CI/deployed/committed/pushed. Private DB stopped after checks.

> **Current delivery — 2026-10-20:** [recipient prerequisites](docs/INSPECTION-NOTIFICATION-PREREQUISITES-EN.md) / [فارسی](docs/INSPECTION-NOTIFICATION-PREREQUISITES-FA.md), [ADR-045](docs/ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-EN.md). Explicit any_reader_company_scope / report_and_recipient_confirm / all_notice_readers implemented. Multiple item-text requirements, reasoned edits/withdrawals, sender live-issuer reports, recipient confirmation/rejection and immutable history. No automatic gate, technical acceptance or source/stock authority; original/PDF unchanged.81/1290 + separate upgrade1/5 PASS; not full-suite/current-CI/deployed/committed/pushed; private DB stopped after checks. Earlier current notices below are historical.

> **Current delivery — 2026-10-20:** [company acknowledgment](docs/INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md) / [فارسی](docs/INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-FA.md), [ADR-044](docs/ADR-044-INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md). Explicit any_authorized_company_reader / issue_and_cancellation_separate / all_authorized_notice_readers / detail_and_current_pdf implemented. First immutable company/event receipt, no late original receipt after cancel; scoped company/person/time and current PDF with409 on receipt changes during render. No automatic read tracker, transport/signature/approval or doc/MTO/MR/PO/stock authority. Final61/1040 + separate upgrade1/5 PASS; overlapping final PDF1/22 PASS. Not full-suite/current CI/deployed/committed/pushed; private DB stopped.

> **Current delivery — 2026-10-20:** [independent issued cancellation](docs/INSPECTION-NOTIFICATION-CANCELLATION-EN.md) / [فارسی](docs/INSPECTION-NOTIFICATION-CANCELLATION-FA.md), [ADR-043](docs/ADR-043-INSPECTION-NOTIFICATION-CANCELLATION-EN.md). Explicit current_issue_grant/cancel_only/status_reason_scoped_replacement implemented: separate append-only event, immutable original number/version/body/snapshot, effective CANCELLED, scoped reason/actor/time without private-history disclosure. Current PDF banner/status and409 on cancellation during render. Final59/884 + separate upgrade1/5 PASS; overlapping PDF1/22 PASS. Only cancellation deferral superseded; replacement/undo/acknowledgment deferred. Not90-case/full-suite/production/current-CI certification; no commit/push/deploy. Private DB stopped.

> **Latest continuation — 2026-10-20:** [optional filter time UI](docs/INSPECTION-NOTIFICATION-FILTER-TIME-UI-EN.md) / [فارسی](docs/INSPECTION-NOTIFICATION-FILTER-TIME-UI-FA.md), user-selected `filter_usability` delivered.26 tests/404 assertions and7 Chromium groups/147 checks PASS (separate/nonadditive). Partial helper blocks submit; fully empty omits bound; no assumed offset/new authority/schema. Current synthetic rendered Chromium fixtures, not production/live-auth/full suite; private DB stopped.

> **Current delivery — 2026-10-20:** [notification register filters](docs/INSPECTION-NOTIFICATION-REGISTER-FILTERS-EN.md) / [فارسی](docs/INSPECTION-NOTIFICATION-REGISTER-FILTERS-FA.md). User-selected `register_filters`: literal number/current-reference search, state, inclusive offset-aware overlap, authorized counts/pages and language/context preservation. No inferred legacy window, history search, new authority/schema/endpoint/write. **58 tests /640 assertions PASS, zero skipped**, not full suite/production/current CI/performance certification. Earlier counts historical; ADR-042/MTO/private-history boundaries retained.

> **Current delivery — 2026-10-20:** [notification draft edit history](docs/INSPECTION-NOTIFICATION-DRAFT-HISTORY-EN.md) / [فارسی](docs/INSPECTION-NOTIFICATION-DRAFT-HISTORY-FA.md). User-selected `draft_history`: atomic DB before/after/version + captured editor name/UUID/time/settings, append-only/FORCE RLS, originating-company readers only, no recipient/PDF history.25-row pages, no-op saves retained, no pre-install backfill. Migration120000; **87 tests /1139 assertions PASS, zero skipped**, not full suite/production/current CI. Earlier totals historical; authority/ADR-042/MTO boundaries retained, issued cancellation/rescheduling deferred.

> **Current delivery — 2026-10-20:** [notification date/time UI](docs/INSPECTION-NOTIFICATION-TIME-UI-EN.md) / [فارسی](docs/INSPECTION-NOTIFICATION-TIME-UI-FA.md). User-selected `date_time_ui`: native date/time + explicit offset, visible exact/direct/no-JS entry and readable display; no inferred timezone/API/schema/authority change. Related53/740 and final4/81 subset PASS; separate18 browser scenarios/149 checks PASS, nonadditive. Not full suite/production/current CI/E2E/all-browser acceptance. History and replace_time/ADR-042/MTO boundaries retained.

> **Current delivery — 2026-10-20:** [notification activity window](docs/INSPECTION-NOTIFICATION-ACTIVITY-WINDOW-EN.md) / [فارسی](docs/INSPECTION-NOTIFICATION-ACTIVITY-WINDOW-FA.md). User-selected `replace_time`: required planned start/end replace single time, preserve offsets and compare instants; EN/FA forms/register/detail/PDF. Legacy issued times explicitly labelled, no backfill; legacy drafts must explicitly convert before issue. Migration110000; **71 tests /802 assertions PASS, zero skipped**, not full suite/production/current CI; earlier counts/single-time contract are historical. Same authority/ADR-042/MTO boundaries; no issued rescheduling.

> **Current delivery — 2026-10-20:** [bounded notification draft editing](docs/INSPECTION-NOTIFICATION-DRAFT-EDIT-EN.md) / [فارسی](docs/INSPECTION-NOTIFICATION-DRAFT-EDIT-FA.md). User-approved `draft_only`; same current draft grant/company, optimistic version, fixed source/creation evidence; latest-edit metadata, not full draft history. Issued/PDF immutability retained; issued cancellation/rescheduling deferred. **58 tests /631 assertions PASS, zero skipped**; not full suite/production/current CI. Earlier create-only limits/counts are historical; ADR-042/MTO boundaries unchanged.

> **Current delivery — 2026-10-20:** [issued-notification PDF](docs/INSPECTION-NOTIFICATION-WORKFLOW-EN.md) / [فارسی](docs/INSPECTION-NOTIFICATION-WORKFLOW-FA.md): actual EN/FA output from immutable history, same readers and post-render visibility recheck; no record mutation/signature/transport/source-read delegation. Project metadata captured only on new issues; no legacy backfill. **42/501 + separate21/85 PASS =63 tests /586 assertions across two commands, no skips**, not full suite/production/current CI. Earlier PDF deferrals and46/250 counts are historical; five ADR-042 choices and MTO boundary retained.

> **Current delivery — 2026-10-19:** [operational ITP notifications](docs/INSPECTION-NOTIFICATION-WORKFLOW-EN.md) / [فارسی](docs/INSPECTION-NOTIFICATION-WORKFLOW-FA.md). Explicit main contractor, authorized-admin draft/issue permission assignment, document-revision stages, drafts, atomic issue/immutable snapshot and purpose-limited recipient inbox are implemented. **Two final commands:46 tests /250 assertions PASS**, not full regression. Notice is not inspection result/signature/external delivery/usable stock. Earlier contract-only status below is historical; five ADR-042 choices and ADR-041/MTO boundaries retained. No production/current-CI claim.

> **New requirement/status — 2026-10-19:** [ADR-042: ITP inspection notifications](docs/ADR-042-ITP-INSPECTION-NOTIFICATIONS-EN.md). Five owner choices retained: all stages, separately delegated contractor permission, explicit authorized project recipients, document-revision/stage host, main contractor only. **Input contract only:7 unit tests/24 assertions PASS; no operational draft/issue UI/service yet.** Notification is not ADR-041 inspection result; earlier evidence does not certify this feature.

> **Current delivery — 2026-10-19:** [independent inspection entry](docs/INSPECTION-ENTRY-EN.md) / [فارسی](docs/INSPECTION-ENTRY-FA.md), [ADR-041](docs/ADR-041-INDEPENDENT-INSPECTION-ENTRY-EN.md). **46 affected tests /558 assertions PASS**,14 new cases. Owner versioned nominations/suspension, create-only original-visible PO form, server attribution and immutable new evidence. Existing TPI/PMC/MC/OWN independence, non-PASS findings and IRN numbering retained; no default/inherited writer, PO read delegation, source/lot/financial/receipt/usable/MTO mutation. Migration190000 privately verified only; no full-suite/production claim. Older current notices/counts below are historical and nonadditive.

> **Current closeout — 2026-10-19 local /2026-10-18 UTC:** [CDE-source complete suite GREEN](docs/G01-CDE-FULL-SUITE-EN.md) / [فارسی](docs/G01-CDE-FULL-SUITE-FA.md): **1134 tests/16598 assertions,0 failures/errors/skips,exit0;452 source and2 worker hashes unchanged**; JUnit1323.967296s. New-source full pending gate CLOSED. Earlier pending/no-full/next-full statements below are superseded history, not current instructions. No app source edits this closeout. G01 OPEN; byte integrity is NOT engineering approval/admission, UNADMITTED/UNRESOLVED, no authority/OrderGap change. Runtime recreated after reset; current cache cluster is in metadata, stopped after closeout. Next: accepted-decision-backed read-only reconciliation/validity work, not rerun of unchanged full gate. Current standalone recovery: epcc-source-recovery-cde-full-2026-10-19.tar.gz.

> **CURRENT CLOSEOUT — 2026-10-18:** [complete scope-source suite GREEN](docs/G01-SCOPE-FULL-SUITE-EN.md) / [گزارش فارسی](docs/G01-SCOPE-FULL-SUITE-FA.md): **1112 tests/16488 assertions,0 failures/errors/skips,exit0; all450 source and2 worker hashes unchanged**. JUnit1298.043810s. The scope-source complete-suite pending gate is CLOSED; pending/next-full statements below are superseded history. No app source changes this closeout. Observation remains UNADMITTED; readiness false/gross null; G01 OPEN, OrderGap/authority unchanged. Next: trusted versioned scope/topology admission, not repetition of the unchanged full gate. Standalone current-source recovery: `epcc-source-recovery-scope-full-2026-10-18.tar.gz`; earlier446-source bundle and overlay remain historical. Database on excluded cache disk, private socket/no TCP, stopped after closeout. No production migration/commit/push/PR.

> **CURRENT — 2026-10-18 scope-observation slice:** read [G01-SCOPE-OBSERVATION-EN](docs/G01-SCOPE-OBSERVATION-EN.md) / [FA](docs/G01-SCOPE-OBSERVATION-FA.md) FIRST. New450-file source:58 related PHPUnit tests/197 assertions and5 real contention scenarios PASS; **complete suite on this source NOT RUN/PENDING**. Earlier1098/16429 green below belongs only to preceding446-file source. New sidecar is always UNADMITTED; readiness false/gross null; no historical backfill, selector/OrderGap/authority change. Runtime data moved to excluded `/home/user/.cache/epcc-app-journal-pg-uo100j_u` after test tmpfs exhaustion; metadata identifies it, socket unchanged. Next: new-source complete gate, then trusted admission. Earlier status text below is history, not current-source verification.

# Chat Transfer Handoff — EPCC Platform

> **2026-10-18 status notice:** this is a historical record. Current local final-source suite is GREEN:1083 tests/16385 assertions,0 failures/errors/skips. Use `docs/FINAL-SOURCE-VERIFICATION-2026-10-18-EN.md`, the current implementation ledger and the new piping pilot matrix (FA companions available). Pilot end-to-end execution and product completeness are not certified; do not follow old next-slice instructions blindly.


**Date:** 2026-10-06  
**Purpose:** Transfer the full working state of this chat to another chat/agent so work can continue from the latest point.  
**Repository:** `/home/user/epcc-platform`  
**Branch:** `arena/roadmap-reconciliation`  
**Latest pushed commit:** `d141f329603ed5b12aa3a9c667e8c08e3ace6f8b` (`d141f32 app: add MTO staging foundation`)

> Persian counterpart: `CHAT-TRANSFER-HANDOFF-FA.md`

---

## 1. Starting instruction for the next chat

If you paste this into the next chat, use this as the initial context:

```text
Please continue work on `/home/user/epcc-platform`, branch `arena/roadmap-reconciliation`. Read `CHAT-TRANSFER-HANDOFF-FA.md` / `CHAT-TRANSFER-HANDOFF-EN.md`, then `HANDOFF.md`, `README.md`, `docs/TODO.md`, `docs/MTO-REQUIREMENTS-FA.md`, and `docs/MTO-REQUIREMENTS-EN.md`. The last pushed commit is `d141f329603ed5b12aa3a9c667e8c08e3ace6f8b` (`app: add MTO staging foundation`). Continue from Slice 4: accepted immutable MTO snapshots (`accepted_mto_batches`, `accepted_mto_rows`) with all-or-nothing acceptance and rollback tests. Do not implement MTO permissions yet.
```

---

## 2. Current repository state

- Repository path: `/home/user/epcc-platform`
- Active branch: `arena/roadmap-reconciliation`
- Latest confirmed local/remote commit:

```text
local:  d141f329603ed5b12aa3a9c667e8c08e3ace6f8b
remote: d141f329603ed5b12aa3a9c667e8c08e3ace6f8b
```

- PR URL:

```text
https://github.com/iman-taheri59/epcc-platform/pull/new/arena/roadmap-reconciliation
```

- If the remote is missing in the next environment, add it without storing credentials:

```bash
cd /home/user/epcc-platform
git remote add origin https://github.com/iman-taheri59/epcc-platform.git
```

- Do not store any PAT in project files, remote URLs, or git config.

---

## 3. Important user constraints

1. Every new document must have a matching English counterpart.
2. All EPCC disciplines remain in product scope, but **Piping** is the first deep/template discipline.
3. Test and preview data must be synthetic/fake but realistic.
4. The current UI is considered cluttered; keep the direction task-first and less noisy.
5. The product should first satisfy the Iranian market: on-prem/air-gap, Persian, Jalali, Iranian customer needs; international comes later.
6. The uploaded docs package must not be blindly overwritten into the repo; it must be analyzed and manually merged.
7. MTO permission/access design is deferred; do not implement new MTO roles/access gates unless explicitly approved later.
8. Temporary PATs should only be used for Git operations via temporary `GIT_ASKPASS`; never store them. Revoke them after the session if possible.

---

## 4. Uploaded package and analysis result

The user uploaded:

```text
/home/user/uploads/epcc-platform-mto-docs-changes-2026-10-06.txt
```

It was actually a ZIP file renamed with a `.txt` extension and was extracted to:

```text
/home/user/req-update-2026-10-06/epcc-platform-mto-docs-changes-2026-10-06/
```

Analysis result:

- The package is docs-only and contains complete replacement files, not diffs/patches.
- Direct overwrite is unsafe because its baseline differs from the current branch.
- Some features were described as `BUILT` in the package but did not exist in the current branch, including:
  - `BaselineService`
  - `baseline_batches`, `baseline_discrepancies`
  - `PcfParser`, `ModelImportService`, `model_imports`
  - immutable accepted MTO rows before the current work
  - PMS services and NDT models mentioned in the package
- Reconciled requirements are recorded in:
  - `docs/MTO-REQUIREMENTS-FA.md`
  - `docs/MTO-REQUIREMENTS-EN.md`

---

## 5. Accepted MTO domain principles

1. Approved project MTO = gross procurement-planning quantity.
2. Model/isometric estimate = net engineering need.
3. If `net > gross`, the positive difference is a shortage; percentage tolerance must not suppress it.
4. If `gross >= net`, show the delta only; do not infer cause/waste/contingency.
5. Order gap is separate from physical usable-material gap.
6. `mto_item_no` alone is not globally unique and must not be a downstream key.
7. Accepted MTO row must be an immutable snapshot.
8. Acceptance must be all-or-nothing per MTO document/sub-MTO revision.
9. Aggregating multiple MTOs is allowed only with disjoint scope or exact immutable pair-level additivity evidence.
10. Scope must be stable/versioned and frozen in snapshots.
11. MTO access-control design is deferred.

---

## 6. Work completed and pushed in this chat

### 6.1 MTO Foundation documentation

Commit:

```text
e54b499 docs: reconcile MTO foundation requirements
```

Main files:

```text
docs/MTO-REQUIREMENTS-FA.md
docs/MTO-REQUIREMENTS-EN.md
docs/TODO.md
HANDOFF.md
README.md
```

Content:

- analyzed the uploaded package;
- recorded why direct overwrite is unsafe;
- staged the MTO Foundation roadmap;
- recorded gross/net, accepted-row identity, all-or-nothing acceptance, order gap and usable-gap principles.

### 6.2 Slice 2 — Material Library Foundation

Commit:

```text
65880b2 app: add material catalogue foundation
```

Created files:

```text
app/database/migrations/2026_10_06_100000_create_material_catalogue.php
app/app/Models/MaterialItem.php
app/app/Services/MaterialCatalogueService.php
app/database/seeders/MaterialCatalogueSeeder.php
app/tests/Feature/MaterialLibraryTest.php
```

Also changed:

```text
app/database/seeders/DatabaseSeeder.php
```

Capabilities:

- `material_items` table;
- two-level catalogue shape:
  - `project_id IS NULL`: global/reference rows;
  - `project_id IS NOT NULL`: project overlay rows;
- project overlay is the current writable path for the Iranian-market use case;
- exact commodity lookup;
- alias/vendor code is only a lookup hint, not identity;
- controlled category/source/status/base_unit;
- bilingual description required;
- RLS based on project participation;
- synthetic PH-14 catalogue with codes:
  - `PIP-CS-6-SCH40`
  - `VLV-GT-6-150`
  - `GSK-SPW-6-150-SS-GR`

### 6.3 Slice 3 — MTO Document / Revision / Staging

Commit:

```text
d141f32 app: add MTO staging foundation
```

Created files:

```text
app/database/migrations/2026_10_06_110000_create_mto_staging.php
app/app/Models/MtoDocument.php
app/app/Models/MtoRevision.php
app/app/Models/MtoStagingRow.php
app/app/Services/MtoStagingService.php
app/tests/Feature/MtoStagingTest.php
```

Capabilities:

- tables:
  - `mto_documents`
  - `mto_revisions`
  - `mto_staging_rows`
- versioned MTO document header;
- revision starts as `DRAFT`;
- staging rows are editable and not accepted yet;
- exact material resolution from `material_items`;
- raw text is preserved;
- alias is not accepted as identity;
- validation states:
  - `ACCEPTABLE`
  - `BLOCKED_UNMATCHED_MATERIAL`
  - `BLOCKED_UNIT_INCOMPATIBLE`
  - `BLOCKED_DUPLICATE_IDENTITY`
  - `BLOCKED_QUANTITY_INVALID`
- RLS for project participants.

---

## 7. Validations already run

### After Material Library Foundation

```bash
bash restore.sh
php artisan test --filter MaterialLibraryTest
php artisan test --filter SeedPathTest
python3 standards-packs/validate_packs.py
```

Results:

```text
MaterialLibraryTest: 6 passed / 15 assertions
SeedPathTest: 2 passed / 6 assertions
standards validator: all 3 standards packs + 5 reference packs valid
```

### After MTO Staging Foundation

```bash
bash restore.sh
php artisan test --filter MtoStagingTest
php artisan test --filter MaterialLibraryTest
php artisan test --filter SeedPathTest
python3 standards-packs/validate_packs.py
```

Results:

```text
MtoStagingTest: 8 passed / 21 assertions
MaterialLibraryTest: 6 passed / 15 assertions
SeedPathTest: 2 passed / 6 assertions
standards validator: all 3 standards packs + 5 reference packs valid
```

The full Laravel suite was once started after Material Library and showed no failure before sandbox timeout, but it did not complete. Earlier, on commit `f33c9a7`, the full suite completed with `858 passed / 3166 assertions`. After Slice 2 and Slice 3, only targeted tests plus restore/validator were run.

---

## 8. Operational notes and known traps

1. PHP/Composer/PostgreSQL may not exist after a sandbox snapshot. First run:

```bash
cd /home/user/epcc-platform
bash restore.sh
```

2. Do not run Laravel tests in parallel. Parallel runs race on PostgreSQL `epcc_test` schema reset.

3. If a long full suite times out, find and kill remaining processes:

```bash
ps -ef | grep -E 'php|artisan|phpunit' | grep -v grep
kill <pid>
```

4. If the test database is corrupted after timeout:

```bash
cd /home/user/epcc-platform/app
PGPASSWORD=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-) \
  psql -h 127.0.0.1 -U epcc -d epcc_test \
  -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'
```

5. `mutate.sh` may sometimes show a mode change because of tooling. Before committing:

```bash
chmod +x mutate.sh
git status --short
```

---

## 9. Build/runtime state

- Runtime is rebuildable with `restore.sh`.
- `restore.sh` creates/updates PostgreSQL `.env`, generates the app key, migrates/seeds, and publishes assets.
- Local `app/.env` must not be committed.
- PHP/Composer/vendor/cache are not guaranteed to persist across workspace snapshots; run `bash restore.sh` if needed.

---

## 10. Not built yet

The following are not built and must not be demoed/documented as built:

```text
accepted_mto_batches
accepted_mto_rows
all-or-nothing MTO acceptance
immutable accepted MTO snapshots
gross MTO vs net engineering estimate comparison
engineering_estimate_rows
PO attribution/order gap
usable material definition and physical gap
MTO access-control/permissions design
MTO UI/workspace
CSV/Excel import UI
PCF/model import
BaselineService / Baseline batches
PMS services from uploaded package
```

---

## 11. Exact next step

### Slice 4 — Accepted immutable MTO snapshots

Build:

```text
accepted_mto_batches
accepted_mto_rows
MtoAcceptanceService
MtoAcceptanceTest
```

Recommended behavior:

1. Only a revision in `DRAFT` or possibly `UNDER_REVIEW` may be accepted; for MVP, prefer service-level acceptance from `DRAFT` only unless a state transition is added.
2. A revision with zero rows must not be accepted.
3. If any `mto_staging_rows.validation_state != ACCEPTABLE`, the whole acceptance fails and no snapshot rows are created.
4. Acceptance must run inside a DB transaction.
5. Accepted rows are immutable; the service should not provide update/delete paths.
6. Snapshot must freeze:
   - project_id
   - source mto_document_id
   - source mto_revision_id
   - accepted_batch_id
   - mto_item_no
   - material_item_id
   - gross_quantity
   - unit
   - scope_id nullable
   - hierarchy_revision_id nullable
   - piping_class_id nullable
   - frozen raw description / material labels / commodity code
   - source row hash
   - accepted_at
7. After acceptance, the revision state can become `ACCEPTED`.
8. Later downstream records must link to `accepted_mto_row_id`, not `mto_item_no` alone.

Required tests:

- accepts all acceptable rows atomically;
- refuses when any row is blocked;
- refusal creates zero accepted rows;
- duplicate or blocked staging rows cannot be accepted;
- accepted rows preserve frozen source values even if the material item later changes;
- non-participant cannot see accepted rows;
- accepted row identity is a UUID and independent of `mto_item_no`.

---

## 12. Key files to read first

In order:

```text
CHAT-TRANSFER-HANDOFF-FA.md
CHAT-TRANSFER-HANDOFF-EN.md
HANDOFF.md
README.md
docs/TODO.md
docs/MTO-REQUIREMENTS-FA.md
docs/MTO-REQUIREMENTS-EN.md
app/app/Services/MaterialCatalogueService.php
app/app/Services/MtoStagingService.php
app/tests/Feature/MaterialLibraryTest.php
app/tests/Feature/MtoStagingTest.php
```

---

## 13. Expected state before continuing

After committing/pushing this handoff, this should be clean:

```bash
cd /home/user/epcc-platform
git status --short
```

The latest commit should be the handoff commit if committed, otherwise at least `d141f32`. Check with:

```bash
git log --oneline -5
```
