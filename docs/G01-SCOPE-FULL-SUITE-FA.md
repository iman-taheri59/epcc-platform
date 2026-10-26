> **Current closeout — 2026-10-19 local /2026-10-18 UTC:** [CDE-source complete suite GREEN](G01-CDE-FULL-SUITE-EN.md) / [فارسی](G01-CDE-FULL-SUITE-FA.md): **1134 tests/16598 assertions,0 failures/errors/skips,exit0;452 source and2 worker hashes unchanged**; JUnit1323.967296s. New-source full pending gate CLOSED. Earlier pending/no-full/next-full statements below are superseded history, not current instructions. No app source edits this closeout. G01 OPEN; byte integrity is NOT engineering approval/admission, UNADMITTED/UNRESOLVED, no authority/OrderGap change. Runtime recreated after reset; current cache cluster is in metadata, stopped after closeout. Next: accepted-decision-backed read-only reconciliation/validity work, not rerun of unchanged full gate. Current standalone recovery: epcc-source-recovery-cde-full-2026-10-19.tar.gz.

> **Current continuation — 2026-10-18 CDE integrity:** [EN](G01-CDE-EVIDENCE-INTEGRITY-EN.md) / [فارسی](G01-CDE-EVIDENCE-INTEGRITY-FA.md). New452-file source:85 related tests/288 assertions PASS;22/110 new within total. **Complete suite on new source NOT RUN/PENDING.** Prior1112/16488 full green below is for preceding450 files only; those450 remain unchanged, two app/test files added. Read-only exact CDE tuple/local-byte integrity, no route/role/migration/admission/OrderGap change. UNADMITTED. Older “no app edits/current full green” statements below describe preceding slices, not this source. Next: full suite on452 files. Standalone current recovery: epcc-source-recovery-cde-integrity-2026-10-18.tar.gz. Database private/cache-disk/socket5545, stopped after closeout.

# G01 — اجرای کامل نسخهٔ scope-observation موفق شد

تاریخ: ۲۰۲۶-۱۰-۱۸

**وضعیت: اجرای کامل روی منبع فعلیِ ۴۵۰فایلی موفق است.** گام «در انتظار اجرای کامل» این نسخه بسته شد؛ نتیجهٔ قبلیِ ۵۸ تست مرتبط، شواهد مرحلهٔ قبل است.

| شاخص | نتیجه |
|---|---:|
| تست‌ها | **۱۱۱۲** |
| assertionها | **۱۶٬۴۸۸** |
| failure / error / skip | **صفر / صفر / صفر** |
| خروج | **۰** |
| زمان JUnit | **۱۲۹۸٫۰۴۳۸۱۰ ثانیه؛ حدود ۲۱ دقیقه و ۳۸ ثانیه** |
| هش منبع ثابت پیش، حین و پس از اجرا | **۴۵۰ فایل** |
| هش harness ثابت | **۲ فایل** |

تمام suiteهای پیکربندی‌شدهٔ Unit و Feature اجرا شدند. در این مرحله هیچ کد برنامه، مدل، migration، تست یا template تغییر نکرد؛ تغییرات پس از اجرا فقط گزارش و بازیابی‌اند.

## محیط و شواهد

PHP 8.4.26، PHPUnit 12.5.35 و PostgreSQL 17.11؛ دیتابیس مستقل `epcc_test` با fixtureهای مصنوعی. نقش واقعی برنامه `epcc` فاقد superuser، BYPASSRLS و CREATEDB است. اتصال socket خصوصی/پورت ۵۵۴۵ و بدون TCP؛ local trust فقط مختص محیط آزمایشی است. تنظیمات دوام `fsync`، `synchronous_commit` و `full_page_writes` روشن و سقف WAL برابر ۱۲۸/۳۲MB بود. داده روی دیسک cache قرار داشت؛ در این اجرای کامل، پُرشدن tmpfs یا recovery تازه رخ نداد.

شواهد خام شامل log، JUnit، کد خروج، manifest منبع، هش harness، وضعیت دیتابیس و checksumها در مسیر زیر هستند:

`docs/evidence/2026-10-18-scope-full/`

بررسی Composer/platform، lint تعداد ۳۵۸ فایل PHP، standards، conformance، screening و مثال مصنوعی آن موفق بودند. ابزارهای مستقل selection و planner نیز ۳۳ و ۲۶ مورد موفق داشتند؛ این اعداد به PHPUnit اضافه نشده‌اند. پنج سناریوی واقعی هم‌زمانی مرحلهٔ قبل روی همین منبع همسان موفق بودند و هم‌زمان با این suite تکرار نشدند.

دیتابیس پس از پایان کار متوقف می‌شود. metadata در `/home/user/.cache/epcc-app-journal-cluster.json` است. runtime، دادهٔ دیتابیس، dependencies و مقادیر env داخل بستهٔ منبع نیستند.

## چه چیزهایی هنوز حل نشده‌اند؟

- observation همچنان **`UNADMITTED`** است؛ ثبت آن تأیید مهندسی یا topology تاریخی معتبر نیست.
- readiness همچنان `false`، انتخاب `UNRESOLVED` و gross برابر `null` است.
- OrderGapService همچنان ردیف‌های پذیرفته‌شده را جمع می‌کند؛ board/API به selected gross تغییر نکرده‌اند.
- backfill قدیمی، اعتبارسنجی FK تاریخی، admission معتبر، coverage کامل و حل overlap/disjointness/additivity انجام نشده‌اند.
- محدودیت‌های زمانی trigger، اجرای زودهنگام constraint، graph قابل‌تغییر، تشخیص افزودن ردیف به‌جای seal کردن و پذیرش‌وسپس‌archive در یک transaction پابرجا هستند؛ گزارش G01-SCOPE-OBSERVATION-FA/EN جزئیات را دارد.
- قواعد اختیار MTO و RECEIVED/usable تغییر نکرده‌اند و ADR-032 پیشنهادی باقی است.
- frontend build، browser smoke، CI تازه، پایلوت یکپارچه، migration مشتری/تولید، commit، push یا PR انجام نشده است. سبزبودن محلی گواه استقرار یا crash-recovery تولید نیست.

## بازیابی و قدم بعدی

بستهٔ `epcc-source-recovery-scope-full-2026-10-18.tar.gz` بازیابی مستقلِ منبع روی baseline دقیق `0c54c7f84d1e343bf1d58833977ac0834dcfdcc0` است؛ برخلاف مکمل مرحلهٔ قبل، به اعمال بستهٔ قبلی نیاز ندارد. راهنمای داخل بسته، بررسی checksum، patch و archive و بازیابی تمیز را توضیح می‌دهد. روی کپی بازیابی‌شده، تطبیق هش و تست‌های مستقل ۳۳/۲۶ انجام می‌شود، نه تکرار اجرای کامل PHPUnit یا PostgreSQL.

قدم بعدی طراحی قرارداد admission و coverage معتبر، نسخه‌دار و دارای provenance تحت ADR-024 است. **سبزبودن تست‌ها نباید observation یا pin ادعاشده را به اختیار مهندسی تبدیل کند.** G01 همچنان باز است؛ اجرای کاملِ بسته‌شده را روی منبع بدون تغییر صرفاً به‌خاطر نوشته‌های تاریخی «pending» تکرار نکنید.
