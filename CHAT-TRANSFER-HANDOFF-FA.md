# انتقال چت — EPCC Platform

**تاریخ:** 2026-10-06  
**هدف:** این فایل برای انتقال کامل وضعیت کاری این چت به یک چت/عامل دیگر نوشته شده است تا کار از آخرین نقطه ادامه پیدا کند.  
**Repository:** `/home/user/epcc-platform`  
**Branch:** `arena/roadmap-reconciliation`  
**آخرین commit push شده:** `d141f329603ed5b12aa3a9c667e8c08e3ace6f8b` (`d141f32 app: add MTO staging foundation`)

> نسخهٔ انگلیسی متناظر: `CHAT-TRANSFER-HANDOFF-EN.md`

---

## 1. دستور شروع برای چت بعدی

اگر این فایل را در چت بعدی paste می‌کنید، متن زیر را به‌عنوان context اولیه بدهید:

```text
Please continue work on `/home/user/epcc-platform`, branch `arena/roadmap-reconciliation`. Read `CHAT-TRANSFER-HANDOFF-FA.md` / `CHAT-TRANSFER-HANDOFF-EN.md`, then `HANDOFF.md`, `README.md`, `docs/TODO.md`, `docs/MTO-REQUIREMENTS-FA.md`, and `docs/MTO-REQUIREMENTS-EN.md`. The last pushed commit is `d141f329603ed5b12aa3a9c667e8c08e3ace6f8b` (`app: add MTO staging foundation`). Continue from Slice 4: accepted immutable MTO snapshots (`accepted_mto_batches`, `accepted_mto_rows`) with all-or-nothing acceptance and rollback tests. Do not implement MTO permissions yet.
```

---

## 2. وضعیت فعلی repository

- مسیر repo: `/home/user/epcc-platform`
- branch فعال: `arena/roadmap-reconciliation`
- آخرین commit محلی/remote که تأیید شده:

```text
local:  d141f329603ed5b12aa3a9c667e8c08e3ace6f8b
remote: d141f329603ed5b12aa3a9c667e8c08e3ace6f8b
```

- PR URL:

```text
https://github.com/iman-taheri59/epcc-platform/pull/new/arena/roadmap-reconciliation
```

- اگر remote در محیط جدید موجود نبود، بدون ذخیره credential این را اضافه کنید:

```bash
cd /home/user/epcc-platform
git remote add origin https://github.com/iman-taheri59/epcc-platform.git
```

- از ذخیره‌کردن PAT در فایل‌های پروژه، remote URL یا git config خودداری شود.

---

## 3. قوانین و قیود مهم کاربر

1. همهٔ مستندات جدید باید نسخهٔ انگلیسی متناظر داشته باشند.
2. همهٔ دیسیپلین‌های لازم EPCC در scope محصول هستند، اما فعلاً **Piping** باید به‌عنوان discipline عمیق/الگو تکمیل شود.
3. دادهٔ تست و preview باید synthetic/fake ولی واقعی‌نما باشد.
4. UI فعلی شلوغ تلقی شده؛ مسیر UX باید task-first و خلوت‌تر بماند.
5. محصول ابتدا برای بازار ایران آماده شود: on-prem/air-gap، فارسی، Jalali، نیازهای مشتریان ایرانی؛ نسخهٔ بین‌المللی بعداً.
6. uploaded docs package نباید مستقیم روی repo overwrite شود؛ باید تحلیل و merge دستی شود.
7. طراحی permission/access برای MTO فعلاً deferred است؛ نقش‌ها/گیت‌های access جدید برای MTO پیاده‌سازی نشود مگر کاربر جداگانه تأیید کند.
8. PATهای موقت فقط برای Git operation و با `GIT_ASKPASS` موقت استفاده شوند؛ در فایل‌ها ذخیره نشوند. پس از پایان بهتر است revoke شوند.

---

## 4. package آپلودی کاربر و نتیجهٔ تحلیل

کاربر فایل زیر را آپلود کرده بود:

```text
/home/user/uploads/epcc-platform-mto-docs-changes-2026-10-06.txt
```

این فایل در واقع ZIP با پسوند `.txt` بود و استخراج شد به:

```text
/home/user/req-update-2026-10-06/epcc-platform-mto-docs-changes-2026-10-06/
```

نتیجهٔ تحلیل:

- package docs-only و شامل فایل‌های کامل replacement بود، نه patch/diff.
- overwrite مستقیم خطرناک است چون baseline آن با branch فعلی فرق دارد.
- در package برخی قابلیت‌ها `BUILT` توصیف شده بودند که در branch فعلی وجود نداشتند، مثل:
  - `BaselineService`
  - `baseline_batches`, `baseline_discrepancies`
  - `PcfParser`, `ModelImportService`, `model_imports`
  - immutable accepted MTO rows قبل از کار فعلی
  - PMS services و NDT models ذکرشده در package
- نتیجهٔ reconciled در این فایل‌ها ثبت شد:
  - `docs/MTO-REQUIREMENTS-FA.md`
  - `docs/MTO-REQUIREMENTS-EN.md`

---

## 5. اصول دامنه‌ای MTO که پذیرفته شده‌اند

1. approved project MTO = gross procurement-planning quantity.
2. model/isometric estimate = net engineering need.
3. اگر `net > gross` باشد، اختلاف مثبت shortage است و percentage tolerance نباید آن را پنهان کند.
4. اگر `gross >= net` باشد، اختلاف فقط به‌عنوان delta نمایش داده می‌شود؛ cause/waste/contingency infer نمی‌شود.
5. order gap از physical usable-material gap جداست.
6. `mto_item_no` به‌تنهایی globally unique نیست و نباید downstream key باشد.
7. accepted MTO row باید immutable snapshot باشد.
8. acceptance باید all-or-nothing per MTO document/sub-MTO revision باشد.
9. aggregation چند MTO فقط با disjoint scope یا exact immutable pair-level additivity evidence مجاز است.
10. scope باید stable/versioned باشد و در snapshotها freeze شود.
11. MTO access-control design deferred است.

---

## 6. کارهایی که در این چت انجام و push شد

### 6.1 مستندات MTO Foundation

Commit:

```text
e54b499 docs: reconcile MTO foundation requirements
```

فایل‌های اصلی:

```text
docs/MTO-REQUIREMENTS-FA.md
docs/MTO-REQUIREMENTS-EN.md
docs/TODO.md
HANDOFF.md
README.md
```

محتوا:

- تحلیل package آپلودی
- ثبت اینکه package نباید overwrite شود
- تعریف مرحله‌بندی MTO Foundation
- ثبت اصول gross/net، accepted row identity، all-or-nothing acceptance، order gap و usable gap

### 6.2 Slice 2 — Material Library Foundation

Commit:

```text
65880b2 app: add material catalogue foundation
```

فایل‌های تولیدشده:

```text
app/database/migrations/2026_10_06_100000_create_material_catalogue.php
app/app/Models/MaterialItem.php
app/app/Services/MaterialCatalogueService.php
app/database/seeders/MaterialCatalogueSeeder.php
app/tests/Feature/MaterialLibraryTest.php
```

تغییر دیگر:

```text
app/database/seeders/DatabaseSeeder.php
```

قابلیت‌ها:

- جدول `material_items`
- دو سطحی بودن کاتالوگ:
  - `project_id IS NULL`: global/reference rows
  - `project_id IS NOT NULL`: project overlay rows
- project overlay برای بازار ایران به‌عنوان مسیر writable فعلی
- exact commodity lookup
- alias/vendor code فقط lookup hint، نه identity
- کنترل category/source/status/base_unit
- bilingual description الزامی
- RLS بر اساس project participation
- synthetic PH-14 catalogue با کدهای:
  - `PIP-CS-6-SCH40`
  - `VLV-GT-6-150`
  - `GSK-SPW-6-150-SS-GR`

### 6.3 Slice 3 — MTO Document / Revision / Staging

Commit:

```text
d141f32 app: add MTO staging foundation
```

فایل‌های تولیدشده:

```text
app/database/migrations/2026_10_06_110000_create_mto_staging.php
app/app/Models/MtoDocument.php
app/app/Models/MtoRevision.php
app/app/Models/MtoStagingRow.php
app/app/Services/MtoStagingService.php
app/tests/Feature/MtoStagingTest.php
```

قابلیت‌ها:

- جدول‌های:
  - `mto_documents`
  - `mto_revisions`
  - `mto_staging_rows`
- MTO document versioned header
- revision با state اولیه `DRAFT`
- staging rows editable و هنوز accepted نیستند
- exact material resolution از `material_items`
- raw text حفظ می‌شود
- alias به‌عنوان identity قبول نمی‌شود
- validation stateها:
  - `ACCEPTABLE`
  - `BLOCKED_UNMATCHED_MATERIAL`
  - `BLOCKED_UNIT_INCOMPATIBLE`
  - `BLOCKED_DUPLICATE_IDENTITY`
  - `BLOCKED_QUANTITY_INVALID`
- RLS برای project participants

---

## 7. validationهای انجام‌شده

### پس از Material Library Foundation

```bash
bash restore.sh
php artisan test --filter MaterialLibraryTest
php artisan test --filter SeedPathTest
python3 standards-packs/validate_packs.py
```

نتایج:

```text
MaterialLibraryTest: 6 passed / 15 assertions
SeedPathTest: 2 passed / 6 assertions
standards validator: all 3 standards packs + 5 reference packs valid
```

### پس از MTO Staging Foundation

```bash
bash restore.sh
php artisan test --filter MtoStagingTest
php artisan test --filter MaterialLibraryTest
php artisan test --filter SeedPathTest
python3 standards-packs/validate_packs.py
```

نتایج:

```text
MtoStagingTest: 8 passed / 21 assertions
MaterialLibraryTest: 6 passed / 15 assertions
SeedPathTest: 2 passed / 6 assertions
standards validator: all 3 standards packs + 5 reference packs valid
```

Full Laravel suite یک‌بار بعد از Material Library شروع شد و تا timeout شکست نشان نداد، اما کامل نشد. در sandbox قبلاً full suite روی commit `f33c9a7` با `858 passed / 3166 assertions` کامل پاس شده بود. پس از Slice 2 و Slice 3 فقط تست‌های هدفمند و restore/validator اجرا شدند.

---

## 8. خطاها و نکات عملیاتی مهم

1. در این sandbox ممکن است PHP/Composer/PostgreSQL پس از snapshot موجود نباشند. اول اجرا کنید:

```bash
cd /home/user/epcc-platform
bash restore.sh
```

2. Laravel tests را موازی اجرا نکنید. اجرای موازی روی PostgreSQL `epcc_test` باعث race در schema reset می‌شود.

3. اگر یک full suite طولانی timeout شد، process باقی‌مانده را بررسی و kill کنید:

```bash
ps -ef | grep -E 'php|artisan|phpunit' | grep -v grep
kill <pid>
```

4. اگر دیتابیس تست بعد از timeout خراب شد:

```bash
cd /home/user/epcc-platform/app
PGPASSWORD=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-) \
  psql -h 127.0.0.1 -U epcc -d epcc_test \
  -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'
```

5. `mutate.sh` گاهی به‌دلیل ابزارها mode change نشان می‌دهد؛ قبل از commit مطمئن شوید executable است:

```bash
chmod +x mutate.sh
git status --short
```

---

## 9. وضعیت build/runtime

- runtime با `restore.sh` قابل بازسازی است.
- `restore.sh` PostgreSQL `.env` می‌سازد/به‌روزرسانی می‌کند، app key می‌سازد، migrate/seed می‌کند و assets را آماده می‌کند.
- local `app/.env` نباید commit شود.
- PHP/Composer/vendor/cache در workspace snapshot پایدار نیستند؛ پس از انتقال ممکن است نیاز به `bash restore.sh` باشد.

---

## 10. مواردی که هنوز ساخته نشده‌اند

هنوز built نیستند و نباید در مستندات/دمو به‌عنوان built معرفی شوند:

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

## 11. گام بعدی دقیق

### Slice 4 — Accepted immutable MTO snapshots

باید ساخته شود:

```text
accepted_mto_batches
accepted_mto_rows
MtoAcceptanceService
MtoAcceptanceTest
```

رفتار پیشنهادی:

1. فقط revision با state `DRAFT` یا شاید `UNDER_REVIEW` قابل accept باشد؛ برای MVP بهتر است فقط `DRAFT` در service تست شود یا state transition ساده اضافه شود.
2. اگر revision هیچ row ندارد، accept نشود.
3. اگر حتی یک `mto_staging_rows.validation_state != ACCEPTABLE` باشد، کل acceptance fail شود و هیچ snapshot ساخته نشود.
4. acceptance در یک DB transaction باشد.
5. accepted rows immutable باشند؛ update/delete مستقیم نباید مسیر service داشته باشد.
6. snapshot باید این‌ها را freeze کند:
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
7. بعد از acceptance، revision state می‌تواند `ACCEPTED` شود.
8. downstreamهای بعدی باید به `accepted_mto_row_id` وصل شوند، نه `mto_item_no` تنها.

تست‌های لازم:

- accepts all acceptable rows atomically
- refuses when any row blocked
- refusal creates zero accepted rows
- duplicate or blocked staging rows cannot be accepted
- accepted rows preserve frozen source values even if material item later changes
- non-participant cannot see accepted rows
- accepted row identity is UUID and independent of `mto_item_no`

---

## 12. فایل‌های کلیدی برای خواندن در شروع ادامه کار

به‌ترتیب:

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

## 13. آخرین وضعیت مورد انتظار قبل از شروع ادامه کار

بعد از commit/push این handoff، انتظار می‌رود:

```bash
cd /home/user/epcc-platform
git status --short
```

خالی باشد.

آخرین commit باید commit مربوط به همین handoff یا اگر commit نشد، حداقل `d141f32` باشد. اگر handoff commit شد، از روی `git log --oneline -5` وضعیت را بررسی کنید.
