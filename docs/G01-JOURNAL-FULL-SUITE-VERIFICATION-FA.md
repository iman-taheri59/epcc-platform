> **CURRENT CLOSEOUT — 2026-10-18:** [complete scope-source suite GREEN](G01-SCOPE-FULL-SUITE-EN.md) / [گزارش فارسی](G01-SCOPE-FULL-SUITE-FA.md): **1112 tests/16488 assertions,0 failures/errors/skips,exit0; all450 source and2 worker hashes unchanged**. JUnit1298.043810s. The scope-source complete-suite pending gate is CLOSED; pending/next-full statements below are superseded history. No app source changes this closeout. Observation remains UNADMITTED; readiness false/gross null; G01 OPEN, OrderGap/authority unchanged. Next: trusted versioned scope/topology admission, not repetition of the unchanged full gate. Standalone current-source recovery: `epcc-source-recovery-scope-full-2026-10-18.tar.gz`; earlier446-source bundle and overlay remain historical. Database on excluded cache disk, private socket/no TCP, stopped after closeout. No production migration/commit/push/PR.

> **CURRENT — 2026-10-18 scope-observation slice:** read [G01-SCOPE-OBSERVATION-EN](G01-SCOPE-OBSERVATION-EN.md) / [FA](G01-SCOPE-OBSERVATION-FA.md) FIRST. New450-file source:58 related PHPUnit tests/197 assertions and5 real contention scenarios PASS; **complete suite on this source NOT RUN/PENDING**. Earlier1098/16429 green below belongs only to preceding446-file source. New sidecar is always UNADMITTED; readiness false/gross null; no historical backfill, selector/OrderGap/authority change. Runtime data moved to excluded `/home/user/.cache/epcc-app-journal-pg-uo100j_u` after test tmpfs exhaustion; metadata identifies it, socket unchanged. Next: new-source complete gate, then trusted admission. Earlier status text below is history, not current-source verification.

# اجرای کامل برنامه روی کد جدید journal — موفق

تاریخ: 2026-10-18. شاخهٔ محلی `arena/baseline-verification-2026-10-18`؛ مبنا `0c54c7f` به‌علاوهٔ اصلاحات تثبیت و journal. تغییرات هنوز commit نشده‌اند؛ push، PR یا استقرار مشتری انجام نشده است.
جزئیات فنی در [نسخهٔ انگلیسی](G01-JOURNAL-FULL-SUITE-VERIFICATION-EN.md).

## نتیجهٔ قطعی

| بررسی | نتیجه |
|---|---|
| اجرای کامل Unit و Feature در PHPUnit | **۱۰۹۸ تست، ۱۶٬۴۲۹ assertion** |
| failure / error / skip | **صفر / صفر / صفر** |
| کد خروج | **۰** |
| زمان JUnit | **۱۲۳۸٫۶۱۲۰۷۳ ثانیه؛ حدود۲۰ دقیقه و۳۹ ثانیه** |
| تطبیق منبع | **۴۴۶ فایل پیش، حین و پس از اجرا یکسان ماندند** |
| workerهای هم‌زمانی قبلی | hash هر دو فایل بدون تغییر |
| تست دیتابیس هم‌زمان | هیچ موردی نداشت |
| Composer و الزامات محیط | موفق |
| syntax PHP در app/app، database، routes و tests | **۳۵۱ فایل موفق** |
| استانداردها، conformance و screening | مستقل و موفق |
| ابزارهای مستقل انتخاب / backfill | **۳۳ /۲۶ سناریو موفق**؛ خارج از آمار PHPUnit |

این نتیجه مربوط به **یک اجرای کامل واقعی** است، نه جمع چند اجرای جزئی. تمام۱۵ تست جدید journal همراه تست‌های قبلی در همین مجموعه اجرا شدند. XML، کد خروج و manifest پس از پایان دوباره بررسی شدند.

**گام اجرای کامل روی منبع جدید اکنون بسته و سبز است.** نتیجهٔ۸۹ تست مرتبط و۵ رقابت واقعی سرویس‌های Laravel، شواهد قبلی روی همین منبع بدون تغییرند؛ به۱۰۹۸ اضافه نشده‌اند و حین اجرای کامل دوباره اجرا نشدند.

در طول یا پس از اجرا، کد برنامه، migration، مدل، سرویس، قالب یا تست تغییر نکرد. ادامهٔ کار فقط مستندات، حفظ شواهد و به‌روزرسانی بستهٔ بازیابی بود.

## محیط و روش اجرا

از مسیر app/:

```sh
SESSION_URL_FALLBACK=false vendor/bin/phpunit --colors=never --testdox \
  --log-junit ../docs/evidence/2026-10-18-g01-full/phpunit.xml
```

PHP8.4.26، PHPUnit12.5.35 و PostgreSQL17.11 با وابستگی‌های Composer همان lockfile استفاده شدند. دیتابیس واقعی `epcc_test` روی cluster دورریختنی مجزا و socket خصوصی `/tmp/epcc-app-journal-sock-*` با پورت۵۵۴۵ بود؛ داده در `/tmp/epcc-app-journal-pg-*` قرار دارد و وارد بستهٔ بازیابی نمی‌شود.

نقش epcc **superuser یا BYPASSRLS یا CREATEDB ندارد**. تنظیمات **fsync، synchronous_commit و full_page_writes روشن ماندند**؛ برای موفق‌شدن تست‌ها خاموش نشدند. WAL به۱۲۸/۳۲MB محدود بود. listener TCP وجود نداشت؛ trust روی socket فقط تنظیم همین محیط خصوصی آزمایشی است، نه الگوی تولید.

Chromium، poppler و Vazirmatn برای تست‌های PDF موجود بودند. مرورگر تعاملی، frontend build یا jsdom در این مرحله دوباره اجرا نشد؛ موفقیت Docker یا GitHub Actions ادعا نمی‌شود. هیچ سرور نمایشی برنامه یا دیتابیس مشتری راه‌اندازی/تغییر داده نشد.

bootstrap تست‌ها فایل‌های پیوست seed را پاک می‌کند؛ در صورت نیاز، demo دورریختنی را جدا و بعد از تست‌ها بازسازی کنید. دو مجموعهٔ تست دیتابیس یا root run-tests.sh را هم‌زمان اجرا نکنید. وابستگی‌ها، فرایندها و دادهٔ /tmp ممکن است پس از reset نیازمند بازسازی باشند.

## سبزبودن این مرحله چه چیزی را ثابت نمی‌کند؟

- رکوردهای journal همچنان **NEW/UNRESOLVED** هستند؛ ordinal به معنی پذیرش scope یا انتخاب مبنای gross نیست.
- **G01 در گزارش اصلی هنوز باز است**؛ OrderGapService همچنان accepted rowها را بدون انتخاب scope/بازنگری مؤثر جمع می‌کند.
- manifest و topology تاریخی معتبر، پذیرش شواهد هم‌پوشانی/جمع‌پذیری، disposition تغییر scope، reconciliation قدیمی و رابط unresolved هنوز کامل نیستند.
- FK تاریخی ردیف‌های قدیمی همچنان NOT VALID است؛ اجرای قید روی ردیف‌های جدید، اعتبار کل تاریخچه را اثبات نمی‌کند.
- اختیار انسانی MTO همچنان معوق است؛ نقش یا grant جدیدی ایجاد نشده. ADR-032 تصویب نشده و معنای سفارش دریافت‌شده، usable یا سیاست بازرسی تغییر نکرده است.
- پایلوت۲۱سناریویی سرتاسری اجرا نشده؛ امنیت تولید، deployment، کارایی، backup/restore و crash recovery تأیید نشده‌اند.

**گام بعد:** تکمیل پذیرش manifest/topology دارای provenance معتبر و اتصال انتخاب fail-closed مطابق اصول موجود ADR-024؛ نه تکرار این اجرای بسته‌شده یا فعال‌کردن بی‌صدای گزارش. پس از تغییر بعدیِ کد، بازبینی و تست متناسب همچنان لازم است؛ اما عبارت‌های قدیمی «اجرای کامل در انتظار» دیگر وضعیت فعلی نیستند.

## شواهد و بازیابی

مرجع فعلی: `docs/evidence/2026-10-18-g01-full/` شامل JUnit کامل، log، خروج۰، manifest۴۴۶ فایل، summary، وضعیت دیتابیس، بررسی‌های مستقل و checksumها.

نتیجهٔ۱۰۸۳/۱۶٬۳۸۵ و گزارش۸۹/۲۴۰ به‌عنوان مراحل تاریخی باقی می‌مانند؛ **نتیجهٔ کاملِ معتبر برای منبع فعلی۱۰۹۸/۱۶٬۴۲۹ است**.

بستهٔ تازهٔ بازیابی کد/مستندات/شواهد خارج مخزن تهیه و روی baseline تمیز از نظر patch و hash فایل‌ها بررسی شده است؛ اجرای کامل برنامه روی نسخهٔ بازیابی‌شده دوباره انجام نمی‌شود. env، مقدار کلید/credential، vendor، node_modules و دیتابیس در آن نیست. installer یا backup دیتابیس نیست؛ فقط پس از بررسی راهنما و checksum، روی checkout تمیز اعمال شود، نه تغییرات ثبت‌نشده. هیچ عملیات remote انجام نمی‌دهد.
