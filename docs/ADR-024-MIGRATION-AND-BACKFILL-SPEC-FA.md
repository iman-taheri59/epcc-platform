# مشخصات migration و انتقال داده‌های قدیمی برای ADR-024 / G01

> Full journal-source gate NOW GREEN1098/16429,zero failures/errors/skips: `G01-JOURNAL-FULL-SUITE-VERIFICATION-FA.md`. Earlier pending notices below are historical. Effective scope selection and the integrated pilot remain open.


> Next implementation update: real NEW acceptance chronology is now connected (always UNRESOLVED). See `G01-REAL-ACCEPTANCE-JOURNAL-FA.md`:89 related tests/240 assertions +5 app-service contention scenarios; FULL SUITE ON CHANGED SOURCE PENDING. No effective board switch or pilot completion.


> Subsequent bounded persistence proof: `G01-POSTGRES-PERSISTENCE-PROOF-FA.md` records36 real-PG tests and3 mutation probes. Application integration, full scope/evidence/legacy adapters and operational migration remain pending; earlier prototype-DB-test plans below now have this limited execution evidence.


تاریخ: 2026-10-18. **پیش‌نویس فنی همراه ابزار آفلاین بررسی؛ نه migration اجراشده، نه تصویب schema/اختیار جدید و نه رفع G01 در گزارش اصلی.**

طرح کامل قیود و پروتکل تراکنش در [نسخهٔ انگلیسی](ADR-024-MIGRATION-AND-BACKFILL-SPEC-EN.md) است. اصول مصوب قبلی تغییر نکرده‌اند.

## خروجی این مرحله

علاوه بر مشخصات ذخیره‌سازی، ابزار اجرایی بررسی backfill ساخته شد که فقط JSON محلی می‌خواند و گزارش JSON می‌دهد. **هیچ اتصال دیتابیس، اجرای SQL یا تغییر داده ندارد.**

- `prototype/domain/MtoBackfillPlanner.php`
- `prototype/test/mto-backfill-planner.php`
- `scripts/plan-mto-backfill.php`
- نمونهٔ مصنوعی: `docs/examples/mto-backfill-synthetic-blocked.json`

**۲۶ تست جدید ابزار موفق شدند؛ ۳۳ تست قرارداد انتخاب مرحلهٔ قبل نیز دوباره موفق اجرا شدند.** سه جهش عمدی در منطق ابزار تشخیص داده شدند و چهار حالت CLI نیز بررسی شد. این‌ها تست‌های مستقل طراحی‌اند، نه تست یکپارچهٔ لاراول/PostgreSQL، و به آمار۱۰۸۳ تست برنامه اضافه نمی‌شوند.

## مدل پیشنهادی ذخیره‌سازی

نام جدول‌ها فعلاً پیشنهادی‌اند و هیچ migration در مسیر برنامه اضافه نشده است:

| بخش | مسئولیت |
|---|---|
| شمارندهٔ پذیرش پروژه | ترتیب افزایشیِ پذیرش‌های جدید، در تراکنش و زیر قفل مشترک |
| sidecar منشأ پذیرش | اتصال به batch/document/revision دقیق؛ تفکیک پذیرش جدید از پذیرش قدیمیِ بازسازی‌شده با شواهد |
| manifest و توپولوژی ثابت scope | مجموعهٔ کامل scopeهای تحت پوشش و شواهد عضویت/اجداد در نسخهٔ تاریخی |
| دفتر append-only انتخاب | انتخاب batch دقیق برای scope، رویداد قبلی و گام محلی؛ بدون ریشهٔ تکراری، انشعاب یا چرخه |
| دفتر جمع‌پذیری | grant/revoke با شواهد پذیرفته‌شده برای جفت batch/revision دقیق، بدون ارث به بازنگری بعد |
| دفتر backfill و یافته‌ها | digest ورودی و package، نسخهٔ ابزار، منشأ اقدام و resolutionهای پیوسته بدون پاک‌کردن تاریخچه |

قیود ضروری: FK مرکب هم‌پروژه، FORCE RLS، جلوگیری DB-level از UPDATE/DELETE تاریخچه، جلوگیری از نوشتن در پروژهٔ آرشیوشده و جلوگیری از commit ناقص یک گروه manifest/selection. شمارنده استثنای محدودِ mutable است؛ snapshot پذیرفته‌شده دست‌نخورده می‌ماند.

**تفکیک مهم:** برای داده‌های قدیمی شمارهٔ ترتیب جهانی تاریخی ساخته نمی‌شود. زنجیرهٔ سند با شواهد بازسازی می‌شود؛ پذیرش‌های جدید پس از cutover ترتیب سری‌شدهٔ خود را دارند. ترتیب گام‌های یک زنجیره، ادعای کشف ترتیب تاریخی کل پروژه نیست.

## انتقال داده بدون حدس

### ۱. فهرست‌برداری فقط‌خواندنی

در آینده، exporter باید از snapshot سازگار و tenant-bound تمام batchها و ردیف‌های پذیرفته‌شدهٔ پروژه را با شناسه‌ها، hashها، مقدار/واحد اصلی و scope/hierarchy تاریخی استخراج کند. exporter دیتابیس هنوز ساخته نشده؛ ورودی ابزار فعلی صرفاً نمونهٔ قرارداد فایل است.

مجموع scopeهای دیده‌شده در ردیف‌ها، به‌تنهایی manifest کامل پوشش نیست. topology فعلی هم اثبات topology تاریخی نیست. کمبود این اطلاعات باید finding بماند؛ سند کنترل‌شدهٔ بعدی می‌تواند در sidecar تفسیر/حل آن را ثبت کند، نه اینکه null تاریخی را بازنویسی کند.

### ۲. package بررسی مقید به ورودی

هر package به پروژه و **SHA-256 همان inventory** متصل است و ترتیب کامل batchهای هر سند، دلیل و مرجع شواهد کنترل‌شده را دارد. حتی timestampهای متفاوت هم به‌تنهایی اجازهٔ حدس ترتیب commit تاریخی کل پروژه نمی‌دهند. در زمان برابر، UUID یا نام بازنگری برنده تعیین نمی‌کند.

تغییر scope، انتقال بین نسخه‌های hierarchy، حذف scope و retirement نیازمند disposition صریح‌اند. mapping به‌تنهایی مجوز جمع نیست. اختیار پذیرندهٔ شواهد و اصالت مرجع باید جدا بررسی شوند؛ وجود یک رشته یا hash در JSON تأیید انسانی محسوب نمی‌شود.

### ۳. dry run فعلی

ابزار دو وضعیت دارد:

- **BLOCKED:** یافته‌های مانع دارد؛ هیچ زنجیرهٔ جزئیِ قابل‌اعمال تحویل نمی‌دهد.
- **CANDIDATE_FOR_CONTROLLED_REVIEW:** فقط نامزد بررسی کنترل‌شده است، نه مجوز اجرا.

در هر دو وضعیت، `ready_to_apply=false` و `writes_performed=0` است.

نمونهٔ مصنوعی با زمان‌های پذیرش برابر و pinهای تاریخی مفقود عمداً **BLOCKED و exit1** شد؛ هیچ scope یا ترتیب تاریخی برایش ساخته نشد. ابزار، بازبینی که به inventory قدیمی/پروژهٔ دیگر وصل باشد، حذف یا تکرار batch در زنجیره، تغییر محتوای ردیف و تغییر scope را نیز تشخیص می‌دهد.

ابزار فعلی موتور بررسی همهٔ قیود دامنه، topology، overlap، امضا، RLS یا اختیار resolver نیست. نامزد بررسی باید تمام این preflightها را در مسیر apply آینده بگذراند.

### ۴. apply آینده — هنوز پیاده نشده

زیر قفل پروژه/شمارنده، inventory و package دوباره hash شوند؛ هر drift باعث رد کامل شود. سپس اختیار، شواهد، manifest، topology، overlap تمام جفت‌ها، وضعیت آرشیو و ریشه‌های journal بررسی شوند. کل backfill پروژه باید اتمیک باشد؛ ردکردن بی‌صدای سند متعارض مجاز نیست.

اجرای تکراریِ ورودی و package یکسان باید idempotent باشد؛ محتوای متفاوت با کلید تکراری خطاست. تست واقعی rollback، crash/retry، هم‌زمانی و tenant/archive پیش‌شرط انتشار است. این تست‌ها هنوز اجرا نشده‌اند.

## پروتکل پذیرش جدید و انتشار

ترتیب قفل پیشنهادی: ردیف پروژه → شمارندهٔ پذیرش → سند → بازنگری → کلیدهای انتخاب با ترتیب ثابت. بررسی آرشیو داخل قفل پروژه انجام می‌شود؛ snapshot، sidecar، manifest و selection در همان تراکنش ایجاد می‌شوند. هیچ تخمین net تاریخی خودکار به ردیف جدید وصل نمی‌شود.

گزارش باید انتخاب/شواهد/مقادیر را از یک snapshot سازگار بخواند و در حالت unresolved، مقدار **null نه صفر** نشان دهد. UI/API باید پیش از فعال‌شدن مسیر جدید این حالت را بفهمد.

انتشار مرحله‌ای: expand خالی → مقایسهٔ shadow → backfill کنترل‌شده → تست‌های DB و رابط → فعال‌سازی. rollback عملیاتی یعنی خاموش‌کردن خواندن/نوشتن جدید با حفظ تاریخچه؛ نه حذف جدول‌های پرشده و نه برگشت بی‌صدای پروژهٔ مبهم به جمع قدیمیِ گمراه‌کننده.

## اجرا و وضعیت محیط

از ریشهٔ مخزن:

```sh
php prototype/test/mto-backfill-planner.php
php prototype/test/effective-mto-selection.php
php scripts/plan-mto-backfill.php docs/examples/mto-backfill-synthetic-blocked.json
```

exit0 ابزار CLI فقط نامزد بررسی، exit1 مانع و exit2 ورودی نامعتبر است. PHP CLI پس از reset محیط دوباره نصب شد. در این نوبت **اجرای سرور برنامه یا دیتابیس ادعا نمی‌شود**؛ نتیجهٔ کامل۱۰۸۳/۱۶٬۳۸۵ شواهد قبلی روی کد برنامهٔ بدون تغییر است، نه اجرای تازهٔ همین محیط. bootstrap لاراول یا پاک‌سازی فایل‌های seed اجرا نشد.

## گام بعد

مشخصات برای بازبینی آماده است، اما تصویب مالک محصول ادعا نمی‌شود. پیش از migration عملیاتی باید interfaceهای topology تاریخی معتبر، admission شواهد/اختیار resolver، disposition تغییر scope و لغو شواهد قطعی شوند. سپس کوچک‌ترین لایهٔ persistence در دیتابیس آزمایشی مجزا با تست‌های واقعی قیود/RLS/rollback/هم‌زمانی ساخته شود؛ اتصال گزارش تا آماده‌شدن نمایش unresolved خاموش بماند.

**G01 همچنان در گزارش اصلی باز است.** مجوز MTO، usable-stock یا معنای RECEIVED تغییر نکرد؛ ADR-032 تصویب نشده و commit/push/PR انجام نشده است.
