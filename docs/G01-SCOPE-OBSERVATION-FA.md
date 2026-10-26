> **CURRENT CLOSEOUT — 2026-10-18:** [complete scope-source suite GREEN](G01-SCOPE-FULL-SUITE-EN.md) / [گزارش فارسی](G01-SCOPE-FULL-SUITE-FA.md): **1112 tests/16488 assertions,0 failures/errors/skips,exit0; all450 source and2 worker hashes unchanged**. JUnit1298.043810s. The scope-source complete-suite pending gate is CLOSED; pending/next-full statements below are superseded history. No app source changes this closeout. Observation remains UNADMITTED; readiness false/gross null; G01 OPEN, OrderGap/authority unchanged. Next: trusted versioned scope/topology admission, not repetition of the unchanged full gate. Standalone current-source recovery: `epcc-source-recovery-scope-full-2026-10-18.tar.gz`; earlier446-source bundle and overlay remain historical. Database on excluded cache disk, private socket/no TCP, stopped after closeout. No production migration/commit/push/PR.

# G01 — ثبت تغییرناپذیر مشاهدهٔ scope، نه تأیید مهندسی

تاریخ: ۲۰۲۶-۱۰-۱۸

**وضعیت نسخهٔ فعلی: تست‌های مرتبط موفق؛ اجرای کامل هنوز انجام نشده است.** منبع برنامه اکنون ۴۵۰ فایل دارد. نتیجهٔ قبلیِ ۱۰۹۸ تست و ۱۶٬۴۲۹ assertion فقط برای منبع قبلیِ ۴۴۶فایلی معتبر است و به این تغییرات تعمیم داده نمی‌شود. G01 همچنان باز است.

## کار انجام‌شده

- برای پذیرش‌های جدید، جدول مستقل `mto_scope_observations` اضافه شد. پذیرش‌های قدیمی، حتی آن‌هایی که journal دارند، خودکار backfill نمی‌شوند.
- trigger تأخیری، معمولاً هنگام commit بیرونی، شناسه‌ها، scope و pin ادعاشده، material، مقدار اعشاری، واحد و hash مبدأ ردیف‌ها را ثبت می‌کند؛ همچنین روابط قابل‌مشاهده و هم‌پروژهٔ subsystem ← area اختیاری ← facility در یک snapshot SQL ثبت می‌شوند.
- payload و مجموعهٔ ردیف‌ها هرکدام SHA-256 جداگانه دارند. این هش‌ها کنترل یکپارچگی در دیتابیس‌اند، نه امضای مهندسی یا اثبات اعتبار تاریخی topology.
- وضعیت همیشه `DATABASE_OBSERVATION / UNADMITTED` است. pin غیرخالی نیز به‌معنای تأیید آن نیست. scope خالی به ریشهٔ پروژه تبدیل نمی‌شود؛ area خالی مجاز است.
- ثبت معمولی دستی و تغییر/حذف از مسیر مدل و SQL رد می‌شوند. FORCE RLS، محدودیت tenant/project و archive و بازگرداندن context بیرونی حفظ شده‌اند. guard عمق trigger کنترل مسیر یکپارچگی است، نه محافظ در برابر مالک schema یا trigger مخربِ دارای اختیار ساخت.

## ارزیابی فقط‌خواندنی

سرویس `MtoScopeReadinessService`، منبع و observation قابل‌مشاهده را دوباره می‌خواند و به attribute یا relation کش‌شدهٔ caller اعتماد نمی‌کند. خروجی در تمام حالت‌ها:

- `ready = false`
- `status = UNRESOLVED`
- `gross = null`، نه صفر ساختگی

شواهد مفقود، pin تأییدنشده، scope خالی، والد نامشهود/ناهم‌پروژه، رابطهٔ حل‌نشدهٔ header و row و تغییر مجموعهٔ ردیف‌ها گزارش می‌شوند. تغییر بعدی graph، snapshot قبلی را بازنویسی نمی‌کند. اضافه‌شدن خام ردیف هنوز طبق محدودیت‌های قبلی جدول می‌تواند ممکن باشد؛ این مرحله آن جدول را seal نمی‌کند، بلکه drift را تشخیص می‌دهد و coverage ثبت‌شده را بی‌صدا بزرگ نمی‌کند.

**OrderGapService، انتخاب gross مؤثر، board/API و مجوزهای MTO تغییر نکرده‌اند.** این کار تأیید topology، پوشش کامل، disjointness یا حل overlap نسخه‌های قدیمی نیست.

## محدودیت زمانی مهم

پیش از اجرای capture داخل transaction بیرونی، نبود observation صادقانه گزارش می‌شود. اجرای دستی `SET CONSTRAINTS ... IMMEDIATE` می‌تواند capture را زودتر انجام دهد؛ افزودن بعدی ردیف، drift است. snapshot متعلق به لحظهٔ اجرای trigger است، نه تضمین topology تاریخی در لحظهٔ commit فیزیکی. تغییر هم‌زمان hierarchy به revision معتبر تبدیل نمی‌شود. چون درج sidecar به پروژهٔ قابل‌نوشتن نیاز دارد، پذیرش و سپس archive در یک transaction بیرونی ممکن است در capture تأخیری رد شود. `observed_at` زمان capture است، نه ترتیب پذیرش یا لزوماً زمان commit.

## شواهد واقعی

| بررسی | نتیجه |
|---|---|
| تست‌های مرتبط PostgreSQL | **۵۸ تست، ۱۹۷ assertion، خروج ۰**؛ بدون failure/error/skip |
| تست‌های جدید observation در همین مجموع | **۱۴ تست، ۵۹ assertion** |
| هم‌زمانی واقعی kernelهای مستقل Laravel | **۵ از ۵ موفق**؛ blocking واقعی و کنترل observation، حالت UNADMITTED و digest |
| lint PHP | ۳۵۸ فایل موفق |
| ابزار مستقل selection / planner | ۳۳ / ۲۶ مورد موفق؛ جدا از شمار PHPUnit |
| اجرای کامل روی منبع جدید | **انجام نشده؛ در انتظار** |

سناریوها شامل capture دقیق و اعشار، نبود observation پیش از commit، rollback، area اختیاری، reparent بعدی، والد متعلق به پروژهٔ دیگر، ردیف‌های scoped/unscoped، افزودن ردیف، تغییر/درج جعلی، outsider، context تو‌در‌تو، نبود backfill، خواندن پس از archive و رد rollback حذف‌کنندهٔ تاریخچه است.

یک اجرای اولیه به‌علت fixture نامعتبرِ NULL کردن facility غیرقابل‌NULL خطا داشت؛ fixtureها بدون خاموش‌کردن constraint اصلاح شدند. اجرای توسعه‌یافتهٔ بعدی نیز به‌علت پُرشدن tmpfs مسیر `/tmp` متوقف شد و ۴۷ error داشت؛ **آن اجرا موفق نیست** و log/XML آن نگه داشته شده است. دیتابیس آزمایشی به مسیر cache روی دیسک منتقل شد، سقف WAL به ۱۲۸/۳۲MB برگشت و recovery خودکار کامل شد؛ سپس اجرای نهایی با migrate:fresh موفق شد و هم‌زمانی هم گذشت. این رویداد گواه crash-recovery تولید نیست.

شواهد در `docs/evidence/2026-10-18-scope-observation/` قرار دارند؛ گزارش EN جزئیات، فرمان‌ها و محدودیت‌ها را توضیح می‌دهد. مسیر runtime در `/home/user/.cache/epcc-app-journal-cluster.json` به‌روز شده؛ socket/پورت قبلی و نبود TCP حفظ شده است. بستهٔ منبع حاوی دیتابیس یا runtime نیست.

هیچ migration مشتری/تولید، commit، push، PR، frontend build، browser smoke یا پایلوت یکپارچه انجام نشده است. ADR-032 همچنان پیشنهادی است؛ قواعد RECEIVED/usable و اختیارات MTO دست‌نخورده‌اند.

## قدم بعدی

اجرای کامل suite روی همین منبع ۴۵۰فایلی و ثبت شواهد/hash مستقل آن؛ سپس طراحی قرارداد admission معتبر و نسخه‌دار با provenance و coverage صریح. observation فعلی نباید خودکار به تأیید مهندسی ارتقا یابد.
