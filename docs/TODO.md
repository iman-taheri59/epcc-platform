> **بازبینی جاری / تصمیم کاربر — ۲۰۲۶-۱۰-۲۰:** [شکاف‌ها و اولویت‌ها](CORE-GAP-REVIEW-2026-10-20-FA.md) / [English](CORE-GAP-REVIEW-2026-10-20-EN.md). طراحی و اجرای اقلام ساخت‌یافتهٔ پیش‌نیاز صریحاً معوق شد؛ متن آزاد محفوظ است. بازبینی مستندات/سورس انجام شد، نه اجرای تست یا تصویب تغییر اختیار/schema. پیشنهاد بعدی: بررسی متمرکز تخصیص شمارهٔ سند، سپس تشخیص ردیابی/محاسبهٔ انبار؛ قابلیت کاربری هدف بعدی دریافت دقیق ردیف PO پس از تصمیم‌های لازم. وضعیت‌های قدیمیِ متعارض و ادعای «کل سورس جاری سبز» در متن زیر، شاهد تاریخی‌اند؛ مرجع وضعیت این بازبینی است.

> **Current delivery — 2026-10-19:** [independent inspection pagination](INSPECTION-REGISTER-PAGINATION-EN.md) / [فارسی](INSPECTION-REGISTER-PAGINATION-FA.md). **40 affected tests /556 assertions PASS**, four new cases. Inspection25-row pages, independent three-way navigation/true totals, explicit project + unchanged participant-visible quality RLS, unused PO eager load removed; advisory receipt semantics unchanged. Supersedes the earlier inspection-pagination deferral only. No new role/schema/endpoint/write or full-suite/production claim; older evidence/notices below are historical.

> **Current delivery — 2026-10-19:** [exact money + independent register pagination](REGISTER-PAGINATION-MONEY-EN.md) / [فارسی](REGISTER-PAGINATION-MONEY-FA.md). **41 affected tests /499 assertions PASS**, eight new cases. Exact stored decimal money/null≠zero; independent25-row MR/PO pages, filtered totals/late count, stable ordering and preserved filters/original caller RLS. This closes the two earlier recommended increments, not indexed/load-certified search or inspection pagination. No new endpoint/schema/role/action or full-suite/production claim. Prior evidence retained; older current/follow-up notices below are historical.

> **Current feature — 2026-10-19:** [independent MR/PO register search + states](PROCUREMENT-REGISTER-FILTERS-EN.md) / [فارسی](PROCUREMENT-REGISTER-FILTERS-FA.md). **33 affected tests /310 assertions PASS**, eight new cases. Bound literal header search, category/state intersection, unchanged original caller RLS, scoped counts and inspections unchanged. No new role/schema/endpoint/action or full-suite/production claim. Server pagination/indexing and preexisting register float/integer money formatting remain explicit follow-up recommendations. Prior evidence retained; older current notices below are historical.

> **Current correction — 2026-10-19:** [safe pricing-form recovery](PO-PRICING-FORM-RECOVERY-EN.md) / [فارسی](PO-PRICING-FORM-RECOVERY-FA.md). **42 affected tests /465 assertions PASS**, nine new cases. Recover input only for the same current PO hash and complete active UUID set; blank≠zero, stale/malformed input not rebased, original write/grant/source checks unchanged. No new role/schema/endpoint or full-suite/production claim. Prior evidence preserved; older current notices below are historical.

> **Current read-only feature — 2026-10-19:** [structured retained pricing comparison](PO-PRICE-HISTORY-COMPARISON-EN.md) / [فارسی](PO-PRICE-HISTORY-COMPARISON-FA.md). **33 affected tests / 359 assertions PASS**, eight new cases. Exact text money, UUID-matched before/after prices/currency/totals, unknown≠zero, raw snapshots retained and latest20 only. Existing buyer/project RLS unchanged; no new writer/role/schema/approval or full-suite/production claim. Prior evidence unchanged; older current/next notices below are historical.

> **Current workflow addition — 2026-10-19:** [PO draft directly from MR details](MR-TO-PO-CONTEXT-ACTION-EN.md) / [فارسی](MR-TO-PO-CONTEXT-ACTION-FA.md). **43 affected tests / 340 assertions PASS**, five new cases. Eligible own-buyer/current independently nominated authors reuse the existing full-copy POST; live source/grant/archive rechecks, safe validation return, no source consumption/reservation/issue. GET stays nonmutating; the optional action deliberately creates a DRAFT PO. No new endpoint/role/schema or full-suite/production claim. Prior evidence unchanged; older current/next notices below are historical.

> **Current feature — 2026-10-19:** [read-only MR detail sheet](MATERIAL-REQUISITION-DETAILS-EN.md) / [فارسی](MATERIAL-REQUISITION-DETAILS-FA.md). **30 affected tests / 240 assertions PASS**, ten new cases. Exact stored source lines/original quantity spelling, recorded approval/rejection metadata and bounded visible related POs, under original caller RLS/current membership/discipline/plan. No journal delegation, mixed-unit sum, official remaining quantity, new role/schema/write gate. Prior evidence unchanged; no full-suite/production claim. Evidence: `docs/evidence/2026-10-19-mr-details/summary.json`. Older current/next notices below are historical.

> **Current targeted verification — 2026-10-19:** [PO rollback / nomination-suspension contention](PO-ROLLBACK-REVOCATION-EN.md) / [فارسی](PO-ROLLBACK-REVOCATION-FA.md). **38 affected tests / 328 assertions PASS**, including four new two-connection schedules (issue rollback, suspension commit/rollback, issue-before-suspension). Runtime code unchanged; no authority/workflow/schema changes. These are bounded timeout-and-fresh-retry schedules, not comprehensive crash/revocation certification. Prior evidence remains unchanged; totals are not cumulative. Older current/next notices below are historical.

> **Current boundary repair — 2026-10-19:** [human OrderGap source protection](ORDER-GAP-HUMAN-BOUNDARY-EN.md) / [فارسی](ORDER-GAP-HUMAN-BOUNDARY-FA.md). Confirmed HTTP quantity-amendment escape for controlled MR copies closed; human attribution/amendment now use fresh locked source markers and protected forms are hidden. Trusted/legacy lineage and accepted complete pricing retained. **77 affected tests / 764 assertions PASS**, six new cases. No new roles/MTO policy/schema migration or full-suite/production claim. Evidence: `docs/evidence/2026-10-19-order-gap-human-boundary/summary.json`. Legacy/manual authority redesign remains deferred. Older current/next notices below are historical.

> **Current security correction — 2026-10-19:** [PO issued-guard RLS isolation](PO-GUARD-ISOLATION-EN.md) / [فارسی](PO-GUARD-ISOLATION-FA.md). Confirmed hidden-journal line-mutation bypass repaired with a trigger-only, visible-parent-capped integrity lookup; caller settings restored and ordinary journal RLS unchanged. **71 affected tests / 658 assertions PASS**, six new cases including archived populated upgrade. Forward-only function migration180000; no historical row rewrite/new roles/MTO rights/production application/full-suite claim. Evidence: `docs/evidence/2026-10-19-po-guard-isolation/summary.json`. Older current/next notices below are historical.

> **Current increment — 2026-10-19:** [OrderGap mutation lock alignment](ORDER-GAP-LOCKING-EN.md) / [فارسی](ORDER-GAP-LOCKING-FA.md). **65 affected tests / 552 assertions PASS**, six new cases, including two real issue-versus-amend connection schedules. Project advisory → project → PO → item locks; fresh-state/lineage rechecks; legacy rules retained and controlled issued-content freeze unchanged. No new roles/MTO authority/reconciliation writer or full-suite/production claim. Evidence: `docs/evidence/2026-10-19-order-gap-locking/summary.json`. Next recommendations are design/review, not enabled post-issue amendment or capacity release. Older current/next notices below are historical.

> **Current increment — 2026-10-19:** [read-only MR source commitment review](PO-SOURCE-REVIEW-EN.md) / [بررسی فقط‌خواندنی تعهدات منبع](PO-SOURCE-REVIEW-FA.md). **57 related tests/502 assertions PASS**,12 new cases. Existing caller-RLS/discipline visibility only; no buyer impersonation/new roles/schema/write actions. Visible cancelled/headerless legacy commitments and recorded allocations are distinguished; hidden buyer evidence is UNKNOWN, not missing/zero; quantities retain historical units and no official remaining balance is inferred. ADR-036/038/039 authority/atomic issuance retained; prior108/746 evidence unchanged. No full/recovery/production claim. Next: lock-order alignment and controlled resolution/post-issue design; write-based legacy resolution/release remains NOT implemented. Older current/next statements below are historical.

>2026-10-19 latest delivery: purchase-order detail sheet added; see PURCHASE-ORDER-DETAILS-EN.md / FA.md.54 targeted tests/227 assertions PASS; no full rerun/recovery per latest user instruction. PO creation/receipt/inspection entry remain separate missing operational surfaces.

# TODO — وضعیت کامل کارهای انجام‌شده و انجام‌نشده

> **2026-10-18 status notice:** this is a historical record. Current local final-source suite is GREEN:1083 tests/16385 assertions,0 failures/errors/skips. Use `docs/FINAL-SOURCE-VERIFICATION-2026-10-18-EN.md`, the current implementation ledger and the new piping pilot matrix (FA companions available). Pilot end-to-end execution and product completeness are not certified; do not follow old next-slice instructions blindly.


**سند وضعیت پروژهٔ سامانهٔ مدیریت پروژه‌های EPCC**
آخرین به‌روزرسانی: **۱۴۰۵/۰۷/۱۴** (2026-10-06)
وضعیت کلی: **نمونهٔ اولیه + چند برش عمودی کارکرد** · پوشش مستند قبلی **~۴۰–۴۵٪** هنوز بازشماری نشده
تست‌ها طبق `HANDOFF.md`: **۸۷۲ قبول / ۳۲۰۲ ادعا** (+ jsdom) · **۱۵۳+ جهش تأییدشده**

> بازنگری 2026-10-05: این سند با `HANDOFF.md` و کد فعلی همگام شد.
> بازنگری 2026-10-06: runtime Laravel/PostgreSQL با `restore.sh` قابل بازیابی شد و full suite اجرا شد.
> بستهٔ نیازمندی‌های MTO آپلودی تحلیل شد؛ نتیجه در `docs/MTO-REQUIREMENTS-FA.md` و `docs/MTO-REQUIREMENTS-EN.md` ثبت شد.
> Slice 2 بنیاد Material Library و Slice 3 MTO document/revision/staging در کد اضافه شدند؛ full suite قبلی تا زمان timeout شکست نشان نداد اما کامل نشد، تست‌های Slice 2/3 هدفمند پاس شدند. Slice 4 هم اضافه شد و تست هدفمند آن پس از restore runtime اجرا می‌شود.

> این سند مکمل `HANDOFF.md` است، نه جایگزین آن. HANDOFF می‌گوید *چطور* کار می‌کند؛
> این سند می‌گوید *چه چیزی هست و چه چیزی نیست*.


---

## ۰الف. نیازمندی‌های MTO پایپینگ — تحلیل و مرحله‌بندی 2026-10-06

| وضعیت | مورد | محل |
|---|---|---|
| ✅ | بستهٔ آپلودی `epcc-platform-mto-docs-changes-2026-10-06.txt` تحلیل شد؛ فایل‌ها ZIP با پسوند txt بودند و شامل نسخه‌های کامل مستندات، نه diff، بودند. | `/home/user/uploads/` |
| ✅ | تشخیص داده شد که بسته نباید مستقیم روی repo overwrite شود، چون بخشی از آن از baseline دیگری آمده و قابلیت‌هایی را `BUILT` می‌نامد که در branch فعلی کد ندارند. | `docs/MTO-REQUIREMENTS-FA.md` |
| ✅ | اصول دامنه‌ای MTO ثبت شد: gross approved MTO با net engineering need یکی نیست؛ order gap و physical usable-material gap جدا هستند؛ `mto_item_no` کلید کافی نیست؛ accepted row باید immutable snapshot باشد؛ acceptance باید all-or-nothing باشد. | `docs/MTO-REQUIREMENTS-FA.md` / `EN` |
| ✅ | ترتیب پیشنهادی اجرا تعریف شد: Material Library → MTO Document/Revision/Staging → Accepted immutable rows → Net estimate reconciliation → PO attribution/order gap → usable material decision. | `docs/MTO-REQUIREMENTS-FA.md` / `EN` |
| ✅ | بنیاد Material Library ساخته شد: `material_items`، مدل `MaterialItem`، سرویس `MaterialCatalogueService`، seeder synthetic و تست هدفمند. | `app/database/migrations/2026_10_06_100000_create_material_catalogue.php`, `app/app/Models/MaterialItem.php`, `app/app/Services/MaterialCatalogueService.php`, `app/tests/Feature/MaterialLibraryTest.php` |
| ✅ | MTO document/revision/staging ساخته شد: `mto_documents`، `mto_revisions`، `mto_staging_rows`، سرویس staging و validation اولیه. | `app/database/migrations/2026_10_06_110000_create_mto_staging.php`, `app/app/Services/MtoStagingService.php`, `app/tests/Feature/MtoStagingTest.php` |
| ✅ | Slice 4 accepted immutable rows ساخته شد: `accepted_mto_batches`، `accepted_mto_rows`، acceptance تراکنشی all-or-nothing، snapshot labels/hash، RLS و rollback tests. | `app/database/migrations/2026_10_06_120000_create_accepted_mto_snapshots.php`, `app/app/Services/MtoAcceptanceService.php`, `app/tests/Feature/MtoAcceptanceTest.php` |
| ✅ | Slice 5 ساخته شد: `engineering_estimate_rows`، provenance منبع، لینک اختیاری به `accepted_mto_row_id` و مقایسهٔ gross/net بدون tolerance. | `app/database/migrations/2026_10_06_130000_create_engineering_estimates.php`, `app/app/Services/EngineeringEstimateService.php`, `app/tests/Feature/EngineeringEstimateTest.php` |
| ❌ | PO attribution/order gap و usable gap هنوز ساخته نشده‌اند. | — |
| ❌ | تعریف وضعیت‌های material قابل مصرف/usable هنوز تصمیم کاربر می‌خواهد. | `docs/MTO-REQUIREMENTS-FA.md` §A6 |

گام پیشنهادی بعدی: Slice 6 یعنی attribution خطوط PO و order gap بر اساس مقدارهای issued/in-force؛ PO بیشتر از MTO باید over-plan جداگانه باشد.

---

## ۰. خلاصهٔ مدیریتی

| | تعداد | توضیح |
|---|---|---|
| حوزهٔ کارکردی مشخص‌شده در SRS | ~۱۰۱ | در ۱۲ گروه ماژول |
| نیازمندی کارکردی (FR) | **۱۲۶** | شمارش یکتا از SRS (تأییدشده) |
| گروه ماژول با کار واقعی | **۷ از ۱۲** | CORE، PROJECT، ENGINEERING، QUALITY، COMMISSIONING، COMMERCIAL، PROCUREMENT — با عمق متفاوت |
| گروه ماژول با صفر صفحه | **۲ از ۱۲** | HSE، SCHEDULE |
| سناریوی پذیرش کامل | **۲ از ۶** | سناریو ۳ و ۵ سرتاسری؛ زنجیره procurement/materials هم یک برش عملیاتی دارد اما در SRS هنوز به‌عنوان journey کامل بازشماری نشده |
| پوشش کلی (COV-EPCC-001) | **~۴۰–۴۵٪، نیازمند بازشماری** | عدد قبلی هنوز تنها برآورد رسمی است؛ با ساخت procurement و piping باید دوباره به FRها نگاشت شود |

> **دربارهٔ درصد پوشش:** ~۴۰–۴۵٪ یک برآورد مهندسی در `Prototype-Coverage-Map.md`
> است، نه شمارش FR. از زمان ثبت آن، procurement/materials و چند فاز پایپینگ ساخته شده‌اند؛
> بنابراین عدد احتمالاً محافظه‌کارانه است، اما تا نگاشت یک‌به‌یک ۱۲۶ نیازمندی به کد
> بازسازی نشود، نباید عدد جدیدی اعلام شود. جدول‌های زیر — که به فایل‌های واقعی اشاره
> می‌کنند — معتبرتر از درصد خام‌اند.

**دو سناریوی پذیرش کامل:**
- **سناریو ۳** (چرخهٔ مدارک مهندسی): ایجاد ← پیوست ← اشتراک ← بازبینی با نظر مسدودکننده ← رفع ← انتشار ← ارسال ← تأیید دریافت ← **خروجی PDF**
- **سناریو ۵** (تکمیل مکانیکی): بازدید ← ثبت نقص ← تخصیص ← رفع ← تأیید ← بستن ← صدور گواهی، با دروازهٔ نواقص ردهٔ A و استثنای ثبت‌شدهٔ کارفرما

---

## ۱. کارهای انجام‌شده ✅

### ۱.۱ زیرساخت و معماری

| # | کار | جزئیات | محل |
|---|---|---|---|
| ✅ | **انتخاب پشتهٔ فناوری** | Laravel 13.31 + Filament v4 + PHP 8.4 + PostgreSQL 17. مستند با تحلیل رقبا (.NET رد شد: تحریم + هزینهٔ VS 2026) | `docs/ADR-003-*.md` |
| ✅ | **چندمستأجری با RLS** | جداسازی در سطح **پایگاه‌داده** با Row Level Security، نه با `where`. دامنه: شرکت × پروژه | ۱۷ مهاجرت |
| ✅ | **۱۲ قانون پایدار RLS** | هرکدام از رفع یک نقص واقعی به‌دست آمده (`SET LOCAL` نیاز به تراکنش باز، `FORCE RLS`، `NULLIF(GUC,'')` و…) | `docs/Spike-RLS-Results.md` |
| ✅ | **موتور استانداردها** | بدون وابستگی به فریم‌ورک، قابل تست مستقل | `packages/standards-engine/` |
| ✅ | **۳ بستهٔ استاندارد** | `ips-iran` (پیش‌فرض)، `iso`، `awp` — انتخاب در زمان ایجاد پروژه | `standards-packs/*.json` |
| ✅ | **دوزبانگی واقعی FA/EN** | RTL کامل، تاریخ جلالی (`morilog/jalali`)، ارقام فارسی، ZWNJ | سراسر |
| ✅ | **اسکریپت بازیابی ۶۰ ثانیه‌ای** | پس از ریست سندباکس، کل زمان اجرا را بازمی‌سازد | `restore.sh` |

### ۱.۲ هویت و دسترسی

| # | کار | جزئیات |
|---|---|---|
| ✅ | **احراز هویت** | ورود مبتنی بر نشست، کاربر مقید به مستأجر، حساب غیرفعال رد می‌شود |
| ✅ | **خط لولهٔ ۶ دروازه‌ای ARC §5** | AUTH ← ENTITLEMENT ← MEMBERSHIP ← RBAC ← ABAC ← SoD، **اولین شکست برنده است** |
| ✅ | **پیام رد دوزبانه و ممیزی‌شده** | `Denial::message($lang)`، ثبت بر اساس **کنشگر** نه موضوع |
| ✅ | **تفکیک وظایف (SoD)** | `cert.approve` ⊥ `cert.submit` |
| ✅ | **۹ نوع شرکت** | متمرکز در `TenantType` + قید CHECK در پایگاه‌داده |
| ✅ | **نوار کناری به‌عنوان سطح مجوز** | سه حالت `available` / `planned` / `locked` — تمایزشان تجاری است، نه زیبایی‌شناختی |

### ۱.۳ ماژول مدارک (`doc`)

| # | کار | جزئیات | مرجع |
|---|---|---|---|
| ✅ | **دفتر ثبت مدارک / CDE** | شماره‌گذاری قاعده‌محور `{PROJECT}-{DISCIPLINE}-{TYPE}-{SEQ:4}` | FR-DOC-01 |
| ✅ | **حالت‌های ISO 19650** | WIP ← Shared ← Published ← Archived با کدهای تناسب. `WIP→PUBLISHED` ممنوع | FR-DOC-02 |
| ✅ | **کنترل بازنگری** | بایگانی می‌کند، بازنویسی نمی‌کند | FR-ENG-02 |
| ✅ | **پیوست فایل** | آدرس‌دهی محتوامحور (sha256)، فهرست مجاز ۱۱ پسوند، سقف ۱۰۰ مگابایت، دانلود از کنترلر (نه دیسک عمومی) | FR-DOC-01/02 |
| ✅ | **چرخهٔ بازبینی و نظر** | HOLD/CODE1/CODE2 انتشار را مسدود می‌کنند، CODE3 پاسخ می‌خواهد، خودبازبینی ممنوع | FR-ENG-06 |
| ✅ | **نامه‌های ارسال (پیمانکار)** | DRAFT←ISSUED، تغییرناپذیر پس از صدور، تأیید دریافت هر شرکت، سررسید مشتق‌شده (۱۴/۱۰/۷ روز) | FR-ENG-05 |
| ✅ | **نامه‌های ارسال وندور** | جریان **دوم** و جدا: سری `VTR`، صفحهٔ مستقل، زنجیرهٔ `source_item_id` برای رهگیری وندور←EPC←کارفرما | FR-ENG-05b |
| ✅ | **بستهٔ ZIP دوزبانه** | با `MANIFEST.csv` دارای BOM و checksum | FR-ENG-05 |

### ۱.۴ ماژول کیفیت (`qaqc`)

| # | کار | جزئیات | مرجع |
|---|---|---|---|
| ✅ | **فهرست نواقص** | ردهٔ A/B/C، چرخهٔ ۶ حالته `RAISED→ASSIGNED→FIXED→{VERIFIED\|REJECTED}→CLOSED` | FR-COM-04 |
| ✅ | **تأیید مستقل** | هرکسی **جز** شرکت مسئول تأیید می‌کند؛ فقط کارفرما می‌بندد | FR-COM-04 |
| ✅ | **دروازهٔ تکمیل مکانیکی** | MCC با نقص باز ردهٔ A صادر نمی‌شود، **مگر** استثنای تأییدشدهٔ کارفرما با دلیل مکتوب و snapshot | FR-COM-05 |

### ۱.۵ تجاری و خروجی

| # | کار | جزئیات |
|---|---|---|
| ✅ | **صورت‌وضعیت موقت ایرانی** | با تعدیل، حسن انجام کار ۱۰٪، پیش‌پرداخت ۲۰٪ |
| ✅ | **خروجی PDF** | Chromium بدون سر + Vazirmatn. فرم‌های چسبان، ZWNJ، دوجهته، ارقام فارسی، جلالی — همه با رستر کردن تأیید شده. **ریسک IR-R2 بازنشسته شد** |
| ✅ | **دروازه‌های انتشار** | مبتنی بر بستهٔ استاندارد، IR-G1…IR-G4، ناظر غیرقابل‌چشم‌پوشی |

### ۱.۶ تجربهٔ کاربری

| # | کار | جزئیات |
|---|---|---|
| ✅ | **ابزار جدول** | مرتب‌سازی + فیلتر روی هر دفتر ثبت، آگاه به ارقام فارسی/عربی، یه/کاف، ZWNJ |
| ✅ | **قرارداد پوستهٔ صفحه** | `<body>` → `.app` → sidebar → `.wrap` → topbar → صفحه. با تست ساختاری تثبیت شده |
| ✅ | **راه‌اندازی پروژه** | ایجاد پروژه، انتخاب بستهٔ استاندارد، دعوت شرکت‌ها، انتصاب افراد |
| ✅ | **پنل ادمین Filament** | `/admin` با RLS واقعی، فارسی/RTL، فونت محلی (بدون CDN). پنج منبع: «مدارک» (ویرایش فقط عنوان)، «فهرست نواقص»، «نامه‌های ارسال»، «کاربران» و «زیرسیستم‌ها» (بقیه فقط‌خواندنی). دروازهٔ تکمیل مکانیکی در «زیرسیستم‌ها» **مشتق** است نه ذخیره‌شده. همهٔ تاریخ‌ها شمسی از طریق `JalaliDate`. جزئیات و دام‌ها در §5i |
| ✅ | **فایل‌های ترجمه** | `lang/fa/epcc.php` و `lang/en/epcc.php` — اولین فایل‌های ترجمهٔ پروژه |

### ۱.۷ کیفیت مهندسی

| # | کار | جزئیات |
|---|---|---|
| ✅ | **۸۵۲ تست / ۳۱۳۶ ادعا** | عدد مستند در `HANDOFF.md` در پایان آخرین فاز کدنویسی؛ اجرای کامل در این sandbox فعلاً به‌دلیل نبود PHP/PostgreSQL ممکن نیست |
| ✅ | **۱۵۳ جهش تأییدشده** | هر قانون با خراب‌کردن عمدی کد و دیدن قرمزشدن تست اعتبارسنجی شده (۱۵ جهش روی دادهٔ بسته‌ها و ۱۵ روی کد PHP در فاز ۰) |
| ✅ | **بایگانی پروژه (ADR-013 → ساخته شد)** | کاربر «آرشیو» را انتخاب کرد و ساخته شد. `ACTIVE ↔ ARCHIVED` برگشت‌پذیر، **بدون حالت DELETED** (حذف یک تشریفات جداست، نه یک گزینه در همان منو). پروژهٔ بایگانی‌شده **کاملاً خواندنی می‌ماند** و **در سقف `max_projects` شمرده نمی‌شود** — نکتهٔ تجاری اصلی: مشتری با طرح یک‌پروژه‌ای که برای شروع پروژهٔ دوم مجبور به حذف اولی باشد، آن را حذف می‌کند، و عملاً شرایط لایسنس به او دستور داده مدارک قراردادی را نابود کند. دو لایه: سرویس **با صدای بلند و به زبان کاربر** رد می‌کند، RLS پشتیبان است. دو یافته: **رد شدن UPDATE/DELETE در RLS بی‌صداست** (صفر ردیف، بدون خطا، `save()` مقدار true برمی‌گرداند)؛ و **`WITH CHECK` برای DELETE اصلاً ارزیابی نمی‌شود** — با سیاست اول، حذف پانچ‌لیست یک پروژهٔ بایگانی‌شده `DELETE 9` داد و همه پاک شدند، پس سیاست دوم `FOR DELETE` روی ۲۲ جدول اضافه شد. ضمناً معلوم شد **`SetUserLocale` فقط روی پنل Filament ثبت شده بود**، پس پیام‌های `__()` بیرون از پنل به زبان پیش‌فرض برمی‌گشتند. **۳۰ تست** |
| ✅ | **HANDOFF زنده** | ۱۰ بخش، شامل دام‌ها و بن‌بست‌ها — به دستور کاربر در هر مرحله به‌روز می‌شود |
| ✅ | **نمودارهای UML ماژول تهیه و متریال** | `docs/uml/` — پنج نمودار PlantUML (مدل دامنه، ماشین‌های حالت، توالی بازرسی تا دروازهٔ MC، برگشت تراکنش و RLS، نمای اجزا) به‌علاوهٔ `uml.html` خودبسنده با SVG درون‌خطی و **بدون هیچ ارجاع خارجی**. متن نمودارها **انگلیسی** است، مطابق قاعدهٔ مستندات |

### ۱.۸ تهیه، متریال و پایپینگ

| # | کار | جزئیات | مرجع |
|---|---|---|---|
| ✅ | **Procurement/materials پایه** | زنجیرهٔ تهیه ← بازرسی ← ورود به انبار ← خروج از انبار ← ورود به سایت، با lot/heat number، MTC به‌عنوان مدرک CDE، ledger افزایشی و اتصال به وضعیت متریال زیرسیستم | `HANDOFF.md` §5k |
| ✅ | **ساختار شکست فیزیکی** | `project → facility → (optional) area → subsystem` با شماره‌گذاری per-facility، FK مرکب برای جلوگیری از area اشتباه، و `fullCode()` مشتق‌شده | `HANDOFF.md` §5l / ADR-004 |
| ✅ | **دادهٔ مرجع مهندسی** | ۳۶ سایز، ۳۵۵ size/schedule، ۱۳ end type، ۲۰ component template و ۴۱ port definition در reference packs read-only | `HANDOFF.md` §5m / ADR-007 |
| ✅ | **پایپینگ phase 1/1b** | line register، numbering engine per-project، AFC gate، numbering editor و evidence attach/detach | `HANDOFF.md` §5r/§5s |
| ✅ | **واحدهای اندازه‌گیری** | ذخیره canonical و نمایش per-user/per-project برای pressure/temperature، با محدودسازی UI به bar/psi و °C/°F طبق تصمیم کاربر | `HANDOFF.md` §5t / ADR-014 |
| ✅ | **Components / ports / joints / dia-inch** | مدل‌سازی دستی خط، ports مشتق از template، joints با compatibility سه‌حالته، numbering policy و dia-inch بر پایه قطر کوچک‌تر | `HANDOFF.md` §5u/§5v / ADR-006 |

---

## ۲. اصلاحات و نکات آموخته‌شده 🔧

مواردی که ساخته شدند، **غلط بودند**، و اصلاح شدند. اینجا می‌آیند چون هرکدام یک درس ساختاری دارند.

| # | مشکل | چرا هیچ تستی نگرفت | راه‌حل |
|---|---|---|---|
| 🔧 | **`MC` غلط ترجمه شده بود** | «Mechanical Completion body» به‌جای **مدیریت پیمان**. یک `tenant_type` فقط باید رشته‌اش مطابقت کند؛ قواعد نقش درست بودند پس رفتار سبز بود در حالی که صفحه دروغ می‌گفت | `TenantType` + قید CHECK + `TenantTypeTest` که **واژه‌ها** را پین می‌کند |
| 🔧 | **`display:inline-block` روی `<td>`** | `display:table-cell` را جایگزین می‌کند و ستون‌ها یکی جابه‌جا می‌شوند. **jsdom موتور چیدمان ندارد** پس نمی‌بیند؛ DOM سالم به‌نظر می‌رسد | `td.mono, th.mono{display:table-cell}` + اندازه‌گیری `getBoundingClientRect()` در Chromium واقعی |
| 🔧 | **نشت دید کارفرما** | بند فراگیر RLS («مالک همهٔ نامه‌های صادرشده را می‌بیند») نامه‌های زنجیرهٔ تأمین وندور را افشا می‌کرد | محدود به `kind='CONTRACTOR'`؛ کارفرما فقط اگر مخاطب صریح باشد می‌بیند |
| 🔧 | **مسیر HTTP تست‌نشده بود** | همهٔ ۱۸ تست وندور سرویس را مستقیم صدا می‌زدند؛ کنترلری که `kind` را نادیده می‌گرفت از کل مجموعه رد می‌شد | دو تست سطح-endpoint اضافه شد |
| 🔧 | **پنل Filament بدون `BindTenant`** | RLS **بسته** شکست می‌خورد، پس صفحه خالی بود؛ تست «نشت نمی‌کند» روی یک صفحهٔ خراب سبز بود | تست آینه‌ای «سطرهای خودش را می‌بیند». **هرگز جداسازی را بدون دیده‌شدن ادعا نکنید** |
| 🔧 | **`->font()` از Google Fonts** | هیچ تستی به شبکه نگاه نمی‌کرد — نقض AG-01…AG-06 که در دیتاسنتر مشتری «کند» نمی‌شود بلکه **هنگ** می‌کند | `LocalFontProvider` + تستی که HTML را برای ۵ میزبان CDN می‌گردد |
| 🔧 | **`canAccessPanel` تست‌نشده** | جهش M79: با `return true` کل مجموعه سبز ماند — **حساب غیرفعال پنل ادمین را باز می‌کرد** | تست `former@pars` روی `/admin` |
| 🔧 | **ستون وضعیت خالی** | به `currentRevision.state` بسته شده بود ولی نام ستون `cde_state` است. صفحه ۲۰۰ برمی‌گرداند و **سلول هر سطر خالی** بود | با **نگاه‌کردن به اسکرین‌شات** پیدا شد، نه با تست. حالا هر ستون رابطه‌ای ادعای محتوا دارد (M81) |
| 🔧 | **فرم ادمین فیلدهای کنترل‌شده را باز می‌گذاشت** | اسکافولد `number`/`sequence`/`company_id` را ورودی آزاد کرده بود — تایپ دوبارهٔ شماره یعنی برخورد با ایندکس یکتا، و تغییر شرکت یعنی ناپدیدشدن مدرک از دفتر ثبت سازندهٔ خودش | فرم به دو عنوان دوزبانه محدود شد؛ ایجاد و حذف برداشته شد |
| 🔧 | **جدول `users` اصلاً RLS نداشت** | با اتصال به‌عنوان EPC-PARS، مدارک درست ۵ تا فیلتر می‌شد ولی **هر ۹ کاربر از هر ۸ شرکت** دیده می‌شد — کارکنان کارفرما، بازرسان مشاور و مهندسان دو EPC رقیب. هیچ صفحه‌ای کاربران را لیست نمی‌کرد، پس نشت پنهان مانده بود | مهاجرت `2026_09_12_100000`: RLS روی `users` و `project_memberships`. سه دام: **بازگشت بی‌نهایت سیاست‌ها**، فراموشی `NULLIF` (قانون ۳)، و اینکه **لاگین قبل از وجود tenant اتفاق می‌افتد** ⇒ دریچهٔ `forIdentityLookup()` |
| 🔧 | **همهٔ مسیرهای `/admin` به لاگین ریدایرکت می‌شدند** | فیلامنت کاربر را **قبل از** `BindTenant` resolve می‌کند، پس RLS سطر را پنهان می‌کرد و ادمین واردشده مهمان تلقی می‌شد. **هر ۲۱ تست پنل سبز بود** چون `actingAs()` شیء User را مستقیم تزریق می‌کند و هرگز از provider نمی‌پرسد | با **اسکرین‌شات** پیدا شد (سومین بار). رفع در سطح provider: `TenantAwareUserProvider`. جهش M95 نشان داد اولین تستِ نوشته‌شده هم بی‌اثر بود چون `SESSION_DRIVER=array` است |
| 🔧 | **آواتار کاربران از `ui-avatars.com` خوانده می‌شد** | فیلامنت به‌طور پیش‌فرض نشانی آواتار را از **نام کاربر** می‌سازد و به `ui-avatars.com` می‌فرستد — این نشانی در منوی کاربرِ **هر صفحهٔ ادمین** رندر می‌شد. دو ایراد: در دیتاسنتر بدون اینترنت مرورگر تا پایان timeout منتظر می‌ماند؛ و **نام واقعی کارمند** به یک سرویس ثالث فرستاده می‌شد که برای فهرست پرسنل یک شرکت تابعهٔ NIOC مسئلهٔ محرمانگی است. اسکنر ایستا آن را **ندید** چون نشانی در PHP و هنگام درخواست ساخته می‌شود و در هیچ فایلی روی دیسک نیست | **رفع شد** با `LocalAvatarProvider`: حروف اول نام به‌صورت SVG درون‌خطی (data URI)، رنگ ثابت per-name. با ضبط **net-log مرورگر واقعی** پیدا شد نه با خواندن کد. `FilamentInfoWidget` هم حذف شد (برند و شمارهٔ نسخهٔ فریم‌ورک روی داشبورد مشتری) |
| 🔧 | **صفحهٔ مدارک روی گوشی فارسی کاملاً سفید بود** | `/projects/PH-14/documents?lang=fa` در عرض ۴۳۰ تنها **۱ رنگ یکتا** رندر می‌کرد. همان صفحه با `lang=en` سالم بود — همین نامتقارنی سرنخ اصلی شد. علت: آیتم flex به‌طور پیش‌فرض `min-width:auto` دارد یعنی «هرگز کوچک‌تر از عرض ذاتی محتوایم نشو». جدول مدارک ۱۰۳۲px عرض ذاتی دارد و viewport گوشی ۴۸۵px بود، پس `.wrap` از viewport پهن‌تر می‌شد. در LTR فقط از سمت راست بریده می‌شود و صفحه خوانا می‌ماند؛ در **RTL** چیدمان از لبهٔ راست شروع می‌شود و همین سرریز کل سند را به بیرون صفحه هل می‌داد | **رفع شد** با `min-width:0` روی `.app > .wrap` به‌علاوهٔ `overflow-x:auto` در media موبایل. هر دو لازم‌اند: `min-width:0` تنها، ستون وضعیت و بازنگری را می‌برید که دقیقاً دو فیلدی هستند که آدم دفتر مدارک را برایشان باز می‌کند. اندازه‌گیری بعد از رفع: مدارک ۱→**۲۷۶۰**، نامه‌های ارسال ۲۰۶→**۲۶۳۲**. قفل‌شده با `ShellStructureTest` (M110–M111) |
| 🔧 | **گواهی صادرشده، مدرک خودش را بازنویسی می‌کرد** | خلاصهٔ نواقص (کل/بسته‌شده/باز ردهٔ A) در هر بار رندر **زنده** محاسبه می‌شد. یعنی همان گواهی با همان شماره و همان URL، چند روز بعد اعداد دیگری نشان می‌داد: بسته‌شده ۲→۳، باز ردهٔ A ۲→۱. انحراف همیشه در جهت **آراسته‌کننده** بود: گواهی‌ای که با دو نقص باز ردهٔ A صادر شده بود، خودبه‌خود به گواهی‌ای «تقریباً تمیز» تبدیل می‌شد و دقیقاً همان چیزی را پاک می‌کرد که حسابرس یا داور دنبالش است | با **دانلود دوبارهٔ همان گواهی** پیدا شد. ستون `punch_summary` (jsonb) هنگام صدور نوشته می‌شود، به تقلید از `exception_items`. پیش‌نویس عمداً زنده می‌ماند چون کسی که می‌خواهد تصمیم به صدور بگیرد باید وضعیت **امروز** را ببیند (M107–M109) |
| 🔧 | **اعداد لاتین کنار اعداد فارسی در همان صفحه** | ستون‌های خودمان ۵ و ۴۰٪ چاپ می‌کردند ولی صفحه‌بندی خودِ فیلامنت «نمایش 1 به 5 از 5 نتیجه». عدد لاتین وسط جملهٔ فارسی برای خواننده فوراً دیده می‌شود و برای تستی که فقط ۲۰۰ بودن صفحه را چک می‌کند نامرئی است. **هر پنج منبع** درگیر بودند — از اولین منبع در حال ارسال بود | با **اسکرین‌شات** پیدا شد (چهارمین بار). یک‌جا در سطح مترجم رفع شد: `LocalisedNumberTranslator` که پارامترهای عددی را در locale فارسی تبدیل می‌کند، bind شده در `register()` نه `boot()`. فقط مقادیر **کاملاً عددی** تبدیل می‌شوند تا `PH-14-SS-2201` به `PH-۱۴-SS-۲۲۰۱` تبدیل نشود (M101–M104) |
| 🔧 | **تاریخ میلادی در پنل** | `->dateTime()` فیلامنت `سپتامبر 12, 2026` را در صفحه‌ای کاملاً فارسی چاپ می‌کرد. در دفتر نامه‌ها تاریخ **قراردادی** است: مهلت ۱۴ روزه را تعیین می‌کند. کد ۲۰۰ بود و همهٔ تست‌ها سبز | ریشه ساختاری بود: تبدیل شمسی فقط به‌صورت کلوژر خصوصی `$jdate` در هر Blade کپی شده بود. `App\Support\JalaliDate` استخراج شد و هر ده view و هر سه جدول پنل به آن وصل شدند؛ `DateFormattingTest` **شکل کدبیس** را قفل می‌کند (M87–M89) |
| 🔧 | **`grep` جای ادعا نیست** | تست «حذف وجود ندارد» روی **کامنت توضیحی خودش** شکست خورد و یک حذف با نام دیگر را هم نمی‌گرفت | بازنویسی روی `canDelete()` / `canCreate()` / `getPages()` |
| 🔧 | **تست بی‌اثر** | «پیمانکار نمی‌تواند رفع خودش را تأیید کند» با حذف نگهبان هم سبز بود، چون قانون نوع-مستأجر زودتر خطا می‌داد | ادعا روی **پیام** + استفاده از شرکتی با نوع تأییدکننده |

---

## ۳. کارهای انجام‌نشده ❌

### ۳.۱ اولویت بالا — شکاف‌های ماژول‌های ساخته‌شده

| # | کار | چرا مهم است | تخمین/ریسک |
|---|---|---|---|
| ⏳ | **تکمیل دیسیپلین پایپینگ** | تصمیم محصولی جدید: همهٔ دیسیپلین‌های EPCC در scope می‌مانند، اما پایپینگ اول عمیق و کامل می‌شود؛ جزئیات در `docs/PIPING-DISCIPLINE-COMPLETION-ROADMAP-FA.md` و نسخهٔ EN | بزرگ؛ نیازمند محیط کامل PostgreSQL/PHP |
| ⏳ | **PMS + branch table** | اولین فاز پایپینگ کامل؛ بدون PMS، import و مدل‌سازی فقط دادهٔ غلط را سریع‌تر وارد می‌کنند | متوسط تا بالا؛ PostgreSQL-heavy |
| ⏳ | **Equipment / nozzles** | دومین فاز پایپینگ؛ endpoint compatibility خطاهای پرهزینهٔ flange/nozzle را قبل از bolt-up می‌گیرد | متوسط |
| ⏳ | **RFSU + PAC + FAC** | زنجیرهٔ commissioning بعد از MCC نیمه‌کاره است؛ برای پایپینگ باید readiness از line/material/weld/test هم تغذیه شود | کوچک تا متوسط؛ بعد از baseline پایپینگ بهتر است |
| ⏳ | **PDF صورت‌وضعیت / دفتر نواقص / GRN / MIV** | خروجی‌های رسمی مهم‌اند، اما بعد از تصمیم piping-first، PDFهای line/test pack/weld log هم به backlog اضافه می‌شوند | کوچک تا متوسط |
| ⏳ | **Bulk import سیستم/زیرسیستم** | الان شکست سیستم/زیرسیستم عمدتاً seeded است؛ منبع ادمین عمداً create ندارد چون داده باید از مهندسی بیاید، نه تایپ دستی | متوسط |
| ⏳ | **عکس نقص روی `DocumentFileStore`** | زیرساخت فایل وجود دارد ولی punch photos هنوز به همان store و ACL وصل نیست | کوچک |
| ⏳ | **RFQ/مناقصه و تکمیل Procurement** | زنجیرهٔ پایه ساخته شده، اما RFQ/tendering، مقایسه پیشنهادها و بعضی خروجی‌های خرید باقی است | متوسط |
| ⏳ | **قواعد PMS و جدول انشعاب** | ADR-008 طراحی شده اما ساخته نشده؛ برای جلوگیری از spec-break در مدل پایپینگ ضروری است | متوسط تا بالا؛ PostgreSQL-heavy |
| ⏳ | **Equipment / nozzles** | ADR-010 طراحی کرده که nozzle مالک port است و joints تغییر نمی‌کند؛ برای گرفتن mismatch ریتینگ/فلنج قبل از bolt-up مهم است | متوسط؛ بعد از تثبیت PMS بهتر است |
| ⏳ | **درون‌ریزی مهندسی Excel/PCF/property export** | ADR-005 آماده است؛ بیشترین ارزش وقتی دارد که نمونه فایل واقعی مشتری موجود باشد. NWD/NWF/NWC نباید به‌عنوان مسیر ingest وعده داده شود | بالا؛ نیازمند نمونه فایل |
| ⚠️ | **محیط اجرای کامل تست** | در این sandbox فعلی PHP/Composer/PostgreSQL نصب نیست؛ بدون آن تغییرات migration/RLS پرریسک‌اند | پیش‌نیاز توسعه سنگین |
| ⏳ | **زیرساخت planning و فروش نرم‌افزار** | برای بازار ایران باید demo seed، sales deck، offline license، installer، support diagnostics، pilot checklist و اسناد تحویل ساخته شود؛ مسیر international هم باز بماند | تجاری/محصولی؛ موازی با توسعه فنی |

### ۳.۲ وضعیت گروه‌های ماژول

| گروه | وضعیت | نکته |
|---|---|---|
| ✅/⚠️ **CORE** | برش اصلی ساخته شده | auth، RLS، plans، entitlement، tenant binding و sidebar authorization فعال‌اند |
| ⚠️ **PROJECT** | جزئی | setup، team، archive/reopen و physical breakdown ساخته شده؛ WBS/CBS/OBS/PBS، risk، issue، action، MOM و lessons learned باقی‌اند |
| ⚠️ **ENGINEERING** | جزئی اما رو به رشد | CDE، transmittal، vendor stream، piping lines/components/ports/joints ساخته شده؛ tag register، datasheet، interface، TQ/RFI، deviation، import، PMS، VDRL و BIM viewer باقی‌اند |
| ⚠️ **PROCUREMENT** | پایه ساخته شده | requisition/PO/inspection/warehouse/site chain وجود دارد؛ RFQ/tendering، vendor comparison، logistics/customs کامل و PDFهای GRN/MIV باقی‌اند |
| ⚠️ **QUALITY** | جزئی | punch list و MC gate ساخته شده؛ ITP/ITR، NCR/CAPA، welding/NDT، calibration و audit باقی‌اند |
| ⚠️ **COMMISSIONING** | جزئی | subsystem + MCC ساخته شده؛ RFSU/PAC/FAC، pre-commissioning ITR، handover dossier و performance test باقی‌اند |
| ⚠️ **COMMERCIAL** | جزئی | صورت‌وضعیت ایرانی و release gates ساخته شده؛ contract register، VO/change order، claim، cost control، cash flow، budget و guarantees باقی‌اند |
| ⚠️ **CONSTRUCTION** | محدود | AWP/WFP عمداً به فاز بین‌الملل موکول شده؛ package-based field execution و constraints باید با هستهٔ فعلی هم‌راستا بماند |
| ❌ **HSE** | صفر صفحه | PTW، incident، observation، toolbox، JSA، training و environment باقی‌اند |
| ❌ **SCHEDULE** | صفر صفحه | P6/MSP sync، lookahead، baseline، delay analysis و Monte Carlo باقی‌اند |
| ⚠️ **ANALYTICS** | داشبورد محدود | report builder، alerting، AI و export کامل باقی‌اند |
| ⚠️ **ADMIN/SaaS** | جزئی | Filament admin محدود و entitlement engine هست؛ metering، billing، customer onboarding و health console باقی‌اند |

### ۳.۳ قابلیت‌های عرضی ساخته‌نشده

| # | کار | ریسک |
|---|---|---|
| ❌ | **جست‌وجوی تمام‌متن / OCR** | متوسط — انتظار می‌رود ولی وجود ندارد |
| ❌ | **اپ میدانی آفلاین** | **بالاترین ریسک کل محصول (IR-R5)**، کاملاً ساخته‌نشده |
| ❌ | **نمایشگر BIM / CAD** | بالا — نیاز به کتابخانهٔ سنگین |
| ❌ | **یکپارچه‌سازی P6 / ERP / LDAP** | متوسط |
| 🔧 | **`verify:airgap`** | دستور پیش‌پروازی AG-06 نوشته شد: سه سطح BLOCKER/LATENT/BENIGN، خروجی JSON برای گزارش تحویل، و `--strict` برای CI. باندل نصب آفلاین (AG-01/AG-03) هنوز مانده |
| ❌ | **موتور اعلان** | پایین — SDK کاوه‌نگار/SMS.ir شناسایی شده |
| ❌ | **گذرگاه یکپارچه‌سازی** | پایین در این فاز |
| ❌ | **موتور گردش‌کار / BPM** | متوسط — الان قواعد در سرویس‌ها سخت‌کد شده |
| ❌ | **سامانهٔ مؤدیان / شاپرک** | مسدودکنندهٔ تجاری‌سازی ایران |

---

## ۴. مسیر پیشنهادی

جزئیات فازبندی جدید در `docs/ROADMAP-TO-MVP.md` آمده است. خلاصهٔ اجرایی:

| اولویت | کار | چرا حالا | پیش‌نیاز |
|---|---|---|---|
| ۰ | **محیط اجرای کامل Laravel/PostgreSQL** | بدون اجرای migration/RLS/PHPUnit، تغییرات دیتابیس و دسترسی قابل اعتماد نیست | PHP، Composer، PostgreSQL یا Docker/devcontainer |
| ۱ | **Reconcile مستندات و backlog** | وضعیت واقعی با `HANDOFF.md` جلوتر از TODO قبلی بود؛ تصمیم‌گیری باید روی سند همگام باشد | همین بازنگری |
| ۲ | **تکمیل پایپینگ — PMS و branch table** | تصمیم فعلی محصول: همهٔ دیسیپلین‌ها دیده شوند، اما پایپینگ اول کامل شود | PostgreSQL کامل + migration tests |
| ۳ | **تکمیل پایپینگ — Equipment/nozzles، fit-up، supports/spools** | endpoint واقعی، دادهٔ field و support/spool مسیر پایپینگ را عملیاتی می‌کند | تصمیم‌های ADR-010 |
| ۴ | **تکمیل پایپینگ — welding/NDT، test packs، PCF import، roll-ups** | پایپینگ بدون weld/NDT/test pack هنوز برای construction/commissioning کامل نیست | ADR تکمیلی welding/NDT + نمونه PCF |
| ۵ | **PDFهای رسمی و RFSU/PAC/FAC** | بعد از پایپینگ، certificate/readiness و خروجی‌ها به دادهٔ واقعی متصل می‌شوند | اجرای کامل تست |
| ۶ | **HSE حداقلی و دیسیپلین بعدی** | بعد از تثبیت الگوی پایپینگ، قابلیت‌ها به دیسیپلین‌های دیگر تعمیم داده می‌شوند | انتخاب product owner |

---

## ۵. تصمیمات بسته (بدون ADR جدید بازگشایی نشود)

| تصمیم | مقدار |
|---|---|
| بازار اول | **ایران.** درون‌سازمانی/air-gap، فارسی پیش‌فرض، جلالی، ریال. SaaS بین‌المللی فاز بعد است، نه موازی |
| پشته | Laravel 13.31 + Filament v4 + PHP 8.4 + PostgreSQL 17 |
| استانداردها | **قابل انتخاب per-project** — `ips-iran` (پیش‌فرض)، `iso`، `awp` |
| AWP | **موکول** به فاز بین‌الملل، در CI به‌عنوان نگهبان عمومیت می‌ماند |
| سند نیازمندی‌ها | **به انگلیسی** (دستور صریح کاربر) — نرم‌افزار دوزبانه است |
| مدل فروش | پلن Full و Special هر شرکت + **پروانهٔ پروژه** به‌عنوان محصول پرچم‌دار |
| قیمت | **۷۰–۱۳۰ میلیون تومان** یک‌باره |
