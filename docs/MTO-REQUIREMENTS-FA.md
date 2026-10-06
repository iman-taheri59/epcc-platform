# نیازمندی‌های تکمیلی MTO پایپینگ و مدیریت متریال

**تاریخ:** 2026-10-06  
**وضعیت:** پیشنهاد نیازمندی و طراحی مرحله‌ای؛ هنوز پیاده‌سازی نشده مگر مواردی که صریحاً در کد فعلی وجود دارند.  
**دامنه:** پایپینگ اول؛ سایر دیسیپلین‌های EPCC در محدوده محصول باقی می‌مانند اما فعلاً به‌صورت عمیق پیاده‌سازی نمی‌شوند.  
**ورودی:** بستهٔ آپلودی `epcc-platform-mto-docs-changes-2026-10-06.txt`، کد فعلی branch `arena/roadmap-reconciliation`، ADRهای موجود و تصمیم‌های قبلی کاربر.

---

## 1. جمع‌بندی اجرایی

نیازمندی‌های MTO آپلودشده از نظر محصولی دقیق و مهم هستند، اما با branch فعلی نباید به‌صورت جایگزینی کامل فایل‌ها اعمال شوند. بعضی فایل‌های بسته از وضعیت مستنداتی/کدی دیگری آمده‌اند و در آن‌ها چند قابلیت به‌صورت `BUILT` توصیف شده که در branch فعلی وجود ندارد؛ مانند `BaselineService`، `PcfParser`، `ModelImportService`، `accepted_mto_rows` و سرویس‌های PMS. بنابراین این سند نیازمندی‌ها را با وضعیت واقعی مخزن فعلی آشتی می‌دهد.

هدف فاز بعدی این است که برای پایپینگ، زنجیرهٔ زیر قابل اتکا و قابل آزمون شود:

```text
Material Library
→ MTO Document / Revision / Staging Rows
→ Immutable Accepted MTO Rows
→ Gross MTO vs Net Engineering Need
→ Order Gap against valid PO
→ Material Receipt / Usable Gap
→ Piping Workspace / Test Pack / Turnover readiness
```

اصل کلیدی: **Approved MTO مقدار ناخالص برنامه‌ریزی خرید است؛ نیاز مدل/ایزومتریک مقدار خالص مهندسی است. این دو نباید بدون provenance و basis مشترک با هم ادغام شوند.**

---

## 2. وضعیت واقعی branch فعلی

### 2.1 ساخته‌شده و قابل اتکا

در branch فعلی این قابلیت‌ها واقعاً وجود دارند و تست دارند:

- Laravel/PostgreSQL runtime با `restore.sh` اصلاح‌شده.
- RLS و چندمستأجری پایه.
- CDE / document register / transmittals / punch / MC certificate.
- Procurement و warehouse پایه با MR/PO/receipt/issue/deliver/reverse در سطح موجود فعلی.
- ساختار پایپینگ:
  - line register
  - AFC evidence gate
  - components/ports
  - joints
  - compatibility
  - task-first Piping Workspace در Laravel
  - test-pack workflow اولیه: create pack، assign line، state transition `DRAFT → READY → TESTED → ACCEPTED`.
- آخرین اجرای کامل: `858 passed / 3166 assertions`.

### 2.2 ادعاهایی در بستهٔ آپلودی که در branch فعلی ساخته نشده‌اند

این موارد نباید در مستندات فعلی به‌عنوان ساخته‌شده ثبت شوند مگر کدشان هم وارد شود:

| مورد | وضعیت branch فعلی |
|---|---|
| `BaselineService` | وجود ندارد |
| `baseline_batches` / `baseline_discrepancies` | وجود ندارد |
| `PcfParser` / `ModelImportService` / `model_imports` | وجود ندارد |
| quarantine برای unknown imported line | وجود ندارد |
| immutable accepted-MTO-row snapshot store | وجود ندارد |
| PMS entry/access/bulk services ذکرشده در بسته | وجود ندارد |
| NDT requirement model ذکرشده در بسته | وجود ندارد |
| `material_requisition_sequence` ذکرشده در بسته | وجود ندارد |

نتیجه: بستهٔ آپلودی باید به‌عنوان **منبع نیازمندی و تصمیم طراحی** استفاده شود، نه جایگزین مستقیم فایل‌های موجود.

---

## 3. اصول دامنه‌ای پذیرفته‌شده برای MTO

### 3.1 Gross MTO و Net Need جدا هستند

- Approved project MTO مقدار **gross procurement-planning** است.
- 3D model / PCF / manual isometric take-off مقدار **net engineering need** است.
- اختلاف مثبت `net > gross` کسری برنامه خرید است.
- اختلاف `gross >= net` کسری نیست؛ فقط اختلاف نمایش داده می‌شود.
- درصد tolerance نباید کسری مثبت را مخفی کند.
- سیستم نباید از اختلاف، علت‌هایی مانند waste factor، contingency یا over-ordering را حدس بزند مگر دادهٔ صریح وجود داشته باشد.

### 3.2 Order Gap و Physical Material Gap دو شاخص جدا هستند

**Order Gap:**

```text
Accepted Gross MTO Quantity - Valid Issued/In-force Net PO Quantity
```

- draft PO و cancelled PO حذف می‌شوند.
- amendment مقدار قبلی را جایگزین می‌کند.
- PO بالاتر از MTO به‌عنوان over-ordered/above-plan نمایش داده می‌شود، نه shortage منفی.

**Receipt / Usable Material Gap:**

```text
Required Quantity - Usable Physical Material Quantity
```

- تعریف وضعیت‌های usable هنوز تصمیم‌گیری نشده و باید جداگانه تأیید شود.
- تا زمان تصمیم، سیستم نباید خودکار فرض کند pending inspection، quarantine، reserved یا rejected usable یا unusable هستند.

### 3.3 هویت ردیف MTO باید immutable باشد

`mto_item_no` به‌تنهایی کلید پایدار نیست. هویت درست ردیف پذیرفته‌شده:

```text
Project
+ MTO document / sub-MTO
+ accepted revision or immutable accepted batch
+ mto_item_no
+ stable accepted_mto_row_id
```

قواعد:

- لینک‌های downstream فقط باید به `accepted_mto_row_id` یا کلید پایدار equivalent اشاره کنند.
- DRAFT staging row نباید target لینک باشد.
- `mto_item_no` برای نمایش و ردیابی انسانی حفظ می‌شود، اما تنها کلید نیست.
- matching بر اساس description یا fuzzy matching ممنوع است.

### 3.4 Acceptance باید all-or-nothing باشد

برای یک MTO document/sub-MTO revision:

1. همهٔ rowها validate می‌شوند.
2. scope، material match، unit compatibility و identity بررسی می‌شود.
3. اگر هر row خطا داشته باشد، کل batch DRAFT باقی می‌ماند.
4. اگر همه معتبر باشند، همهٔ accepted-row snapshotها در یک transaction ساخته می‌شوند.
5. فقط همان batch ACCEPTED می‌شود.
6. sibling documents/sub-MTOs مستقل هستند و acceptance یکی، coverage دیگری را نمی‌بندد.

### 3.5 تجمیع چند MTO فقط با disjoint scope یا additivity evidence

دو MTO پذیرفته‌شده فقط وقتی قابل جمع هستند که:

- scopeها واقعاً disjoint باشند؛ یا
- یک رابطهٔ additive بین exact immutable revisions ثبت شده باشد.

رابطهٔ additive باید شامل این موارد باشد:

- reason مکتوب؛
- اگر document-based است، citation به exact immutable CDE revision؛
- اگر خارج از CDE است، evidence record با stable ID، issuer/source، exact number/revision/date، retained file یا immutable URI و hash؛
- hash تنها کافی نیست؛
- رابطه به revisionهای بعدی ارث نمی‌رسد.

### 3.6 Scope باید stable و versioned باشد

- scope باید از hierarchy رسمی پروژه بیاید، نه متن آزاد.
- header scope فقط وقتی مجاز است که همهٔ rows همان scope یا descendant معتبر آن باشند.
- mixed-scope MTO نیاز به row-level scope دارد.
- row خارج از header boundary conflicting/unallocated است.
- missing/ambiguous required scope، acceptance کل revision را block می‌کند.
- accepted snapshot باید scope ID و hierarchy revision زمان acceptance را نگه دارد.
- rename با same stable ID coverage را حفظ می‌کند.
- split/merge/replacement با IDs جدید coverage را inherit نمی‌کند.
- mapping بین hierarchy revisions باید append-only و evidence-backed باشد و به‌خودی‌خود اجازه جمع کردن quantity نمی‌دهد.

---

## 4. نیازمندی‌های فاز A — Foundation MTO پایپینگ

## A1. Material Library

### هدف

هیچ MTO row رسمی نباید بدون تطبیق با material library accepted شود. unmatched rows می‌توانند در DRAFT باقی بمانند و به‌عنوان کار باز دیده شوند.

### موجودیت پیشنهادی: `material_items`

فیلدهای حداقلی:

| فیلد | توضیح |
|---|---|
| `id` | UUID پایدار |
| `discipline` | فعلاً `PIPING`؛ قابل توسعه برای سایر دیسیپلین‌ها |
| `category` | `PIPE`, `FITTING`, `FLANGE`, `VALVE`, `GASKET`, `BOLT_NUT`, `SPECIALTY`, `SUPPORT` در صورت ورود supports |
| `commodity_code` | کد کالای استاندارد پروژه/شرکت |
| `description_en`, `description_fa` | شرح دوزبانه |
| `base_unit` | `m`, `each`, و فقط در صورت نیاز `kg` |
| `size_nps`, `size_dn` | در صورت کاربرد |
| `schedule`, `rating`, `class_code` | بسته به category |
| `material_spec`, `grade` | مشخصات فنی |
| `source` | reference pack، PMS، project catalogue یا manual approved |
| `status` | `ACTIVE`, `WITHDRAWN`, `PROJECT_ONLY` |

### قواعد

- material identity با description ساخته نمی‌شود.
- alias/vendor code فقط lookup کمک‌کننده است، نه identity.
- withdrawn item برای historical rows خواندنی می‌ماند اما برای acceptance جدید نیاز به تصمیم دارد.

### سؤال‌های تصمیمی

1. material library در MVP دو سطحی باشد؟ پیشنهاد: global/reference + project overlay.
2. آیا project overlay برای ایران/کارگاه‌ها اجباری باشد؟ پیشنهاد: بله، چون کدینگ کالا در شرکت‌ها متفاوت است.

---

## A2. MTO Document, Revision and Staging

### هدف

MTO باید به‌عنوان سند مهندسی versioned دیده شود، نه spreadsheet آزاد.

### موجودیت‌ها

#### `mto_documents`

- project_id
- discipline
- document_number
- title_en/title_fa
- source_type: `CDE_REVISION`, `EXTERNAL_EVIDENCE`, `MANUAL`
- cde_document_id nullable
- company_id

#### `mto_revisions`

- mto_document_id
- revision_code
- revision_date
- state: `DRAFT`, `UNDER_REVIEW`, `ACCEPTED`, `SUPERSEDED`, `REJECTED`
- source_file_id/hash nullable
- scope_mode: `HEADER_SCOPE`, `ROW_SCOPE`, `MIXED`
- header_scope_id nullable
- hierarchy_revision_id nullable

#### `mto_staging_rows`

- revision_id
- `mto_item_no`
- raw_description
- raw_quantity
- raw_unit
- material_item_id nullable
- row_scope_id nullable
- piping_class_id nullable
- subsystem/system/unit reference nullable
- validation_state
- validation_errors json

#### `accepted_mto_rows`

Immutable snapshot:

- id
- accepted_batch_id
- revision_id
- `mto_item_no`
- material_item_id
- gross_quantity
- unit
- canonical_quantity nullable
- scope_id
- hierarchy_revision_id
- piping_class_id nullable
- frozen display labels
- source row hash
- accepted_at

### قواعد

- staging rows قابل اصلاح هستند.
- accepted rows immutable هستند.
- accepted rows هرگز update/delete عادی نمی‌شوند؛ supersession با revision جدید انجام می‌شود.
- acceptance فقط روی revision کامل انجام می‌شود.

---

## A3. MTO Acceptance Validation

### Validation gates

یک MTO revision فقط وقتی قابل acceptance است که:

1. همهٔ rows material_item_id معتبر داشته باشند.
2. unit هر row با unit material سازگار باشد.
3. quantity مثبت و دارای precision قابل قبول باشد.
4. scope هر row قابل تعیین باشد.
5. row scope خارج از header boundary نباشد.
6. duplicate ambiguity در `mto_item_no` داخل document/sub-MTO کنترل شود.
7. اگر category برای unit/project in-scope است، coverage قابل محاسبه باشد.
8. اگر additive relation لازم است، evidence دقیق داشته باشد.

### خروجی validation

- ACCEPTABLE
- BLOCKED_UNMATCHED_MATERIAL
- BLOCKED_SCOPE_MISSING
- BLOCKED_SCOPE_CONFLICT
- BLOCKED_UNIT_INCOMPATIBLE
- BLOCKED_DUPLICATE_IDENTITY
- WARNING_OVERLAP_AMBIGUOUS

### رفتار UI

- هیچ row خطادار نباید silently حذف شود.
- کاربر باید بتواند خطاها را filter کند.
- پیام‌ها FA/EN باشند.
- row raw text حفظ شود.

---

## A4. Net Engineering Estimate

### هدف

نیاز خالص مهندسی از مدل/ایزومتریک/ورود دستی با MTO gross قابل مقایسه شود.

### منابع فاز MVP

1. manual/isometric take-off entry؛
2. derived simple quantity از components فعلی فقط جایی که داده کافی است؛
3. PCF import بعداً، مگر تصمیم جداگانه گرفته شود.

### موجودیت پیشنهادی: `engineering_estimate_rows`

- project_id
- source_type: `MANUAL_ISO`, `MODEL_DERIVED`, `PCF_IMPORT`
- source_revision/evidence
- material_item_id
- accepted_mto_row_id nullable
- net_quantity
- unit
- scope_id
- piping_class_id nullable
- line_id nullable
- provenance json

### قواعد

- اگر accepted_mto_row_id وجود ندارد، row provisional است.
- no fuzzy match.
- اگر gross MTO کمتر از net باشد، shortage مثبت ایجاد می‌شود.
- اگر gross MTO بیشتر باشد، فقط delta نمایش داده می‌شود.

---

## A5. Purchase Order Attribution and Order Gap

### هدف

PO lineها باید قابل roll-up به material/scope/class باشند.

### نیازمندی

برای هر PO line:

- material_item_id
- quantity/unit
- effective state
- scope allocation
- piping class optional
- project/company/vendor
- amendment lineage

### قواعد

- draft/cancelled excluded.
- issued/in-force included.
- amendment replaces previous quantity.
- ambiguous scope → unallocated.
- no automatic proration.
- PO > MTO به‌عنوان over-plan نمایش داده شود.

---

## A6. Receipt / Usable Material Gap

### وضعیت

تعریف usable material هنوز توسط کاربر defer شده است. تا تصمیم، فقط داده‌ها را جداگانه نمایش می‌دهیم و از نتیجه‌گیری قطعی پرهیز می‌کنیم.

### گزینهٔ پیشنهادی برای تصمیم بعدی

usable باشد:

- inspected and accepted;
- warehouse available;
- issued to site;
- delivered to site.

usable نباشد:

- pending inspection;
- quarantine;
- rejected;
- reserved for other scope;
- returned pending disposition;
- damaged.

این پیشنهاد هنوز accepted نیست.

---

## 5. نیازمندی‌های بعدی Piping Completion

### 5.1 Equipment and Nozzles

- equipment register با tag/type/service/scope؛
- nozzle register با tag/size/rating/facing؛
- line endpoint واقعی به equipment/nozzle/line/open end؛
- validation برای size/rating mismatch.

### 5.2 Fit-up and Welding

- fit-up record برای joint؛
- fitter/welder code؛
- fitted_at؛
- WPS/PQR reference؛
- weld process؛
- shop/field؛
- repair/rework cycle.

### 5.3 NDT خارج از Material Inspection

- NDT requirements از PMS/ITP می‌آید؛
- execution/result entity مستقل دارد؛
- RT/UT/PT/MT/PMI در صورت تصمیم؛
- repair/retest lifecycle؛
- نباید در `MaterialInspection` مخلوط شود.

### 5.4 Test Pack Completion

Workflow فعلی test pack فقط فاز اول است. تکمیل بعدی باید شامل باشد:

- blind list؛
- pressure test report؛
- medium/duration/gauge calibration؛
- no open A punch؛
- NDT required accepted؛
- material traceability complete؛
- reinstatement checklist؛
- evidence files and turnover link.

---

## 6. Product / Iran-first Requirements

### 6.1 Licensing

- offline signed license file؛
- installation ID؛
- modules/projects/users/support expiry؛
- offline activation؛
- license status page؛
- audit log.

### 6.2 Air-gap Verification

- `php artisan verify:airgap`؛
- no CDN؛
- no external fonts/assets؛
- local avatars؛
- update bundle integrity.

### 6.3 Backup / Restore

- DB + files backup؛
- checksum؛
- dry-run restore؛
- restore validation؛
- bilingual operator guide.

### 6.4 Sales Assets

برای بازار ایران و سپس بین‌المللی:

- proposal FA/EN؛
- SOW FA/EN؛
- SLA FA/EN؛
- support contract FA/EN؛
- air-gap checklist FA/EN؛
- implementation/UAT checklist FA/EN؛
- pricing sheet FA/EN.

---

## 7. ترتیب پیشنهادی اجرا

### Slice 1 — Documentation reconciliation

- این سند و نسخهٔ انگلیسی ثبت شود.
- TODO/HANDOFF با وضعیت واقعی branch به‌روز شود.
- بستهٔ آپلودی مستقیم overwrite نشود.

### Slice 2 — Material Library foundation

- migration/model/service برای `material_items`؛
- seed synthetic piping catalogue؛
- tests برای identity/alias/status.

### Slice 3 — MTO document/revision/staging

- `mto_documents`, `mto_revisions`, `mto_staging_rows`؛
- manual entry/import placeholder؛
- validation UI/workspace ابتدایی.

### Slice 4 — Accepted immutable snapshots

- `accepted_mto_batches`, `accepted_mto_rows`؛
- all-or-nothing transaction؛
- tests برای rollback کامل.

### Slice 5 — Net estimate and gross/net comparison

- `engineering_estimate_rows`؛
- linkage به accepted MTO row؛
- no-tolerance shortage logic.

### Slice 6 — PO attribution and order gap

- PO line material/scope attribution؛
- order gap board؛
- over-plan نمایش جداگانه.

### Slice 7 — Usable material decision and physical gap

- ابتدا تصمیم کاربر درباره usable states؛
- سپس implementation.

---

## 8. تصمیم‌های باز برای سؤال‌های بعدی

برای ادامهٔ مرحله‌ای، این سؤال‌ها باید یکی‌یکی بسته شوند:

1. Material library دو سطحی باشد؟ global/reference + project overlay؟
2. MTO acceptance authority تا زمان طراحی دسترسی‌ها چگونه محدود شود؟
3. آیا hierarchy revisioning از ابتدا پیاده شود یا ابتدا stable scope IDs کافی است؟
4. آیا اولین net estimate از manual/isometric باشد یا از components فعلی؟
5. وضعیت‌های usable material دقیقاً کدام‌اند؟
6. آیا supports در همین فاز MTO component-like وارد شوند یا بعداً؟
7. آیا MTO import از Excel/CSV در MVP لازم است یا manual entry کافی است؟

---

## 9. اصل اجرایی

تا وقتی نیازمندی‌ها نهایی نشده‌اند، هر قابلیت باید با این قالب جلو برود:

```text
Requirement proposal → conflict check → user decision → small implementation slice → tests → docs update
```

هیچ مستندی نباید ادعای `BUILT` کند مگر migration/code/UI/tests واقعاً در همین branch وجود داشته باشد.
