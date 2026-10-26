> **وضعیت جاری —۲۰۲۶-۱۰-۱۹:** [اجرای کامل موفق](G01-TOPOLOGY-FULL-SUITE-FA.md) / [EN](G01-TOPOLOGY-FULL-SUITE-EN.md):۱۱۵۱ تست/۱۶٬۷۷۶ assertion، صفر failure/error/skip، خروج۰؛ hash۴۵۴ منبع و۲ worker ثابت. gate اجرای کامل این منبع بسته شد. متن‌های pending/next-full قدیمی پایین تاریخی‌اند و با این گزارش supersede می‌شوند. مقایسه فقط‌خواندنی است؛ G01 باز و UNADMITTED/UNRESOLVED، gross=null، بدون تغییر اختیار/OrderGap/role/route/migration. بازیابی فعلی: epcc-source-recovery-topology-full-2026-10-19.tar.gz؛ DB مستقل موقت پس از پایان متوقف شد.

# G01 — اجرای کامل منبع بررسی فایل CDE موفق شد

تاریخ محلی تهران: **۲۰۲۶-۱۰-۱۹**؛ تاریخ اجرای UTC برابر۲۰۲۶-۱۰-۱۸ است، بنابراین تاریخ پوشهٔ شواهد حفظ شده است.

**وضعیت: اجرای کامل روی منبع فعلیِ ۴۵۲فایلی موفق؛ گام pending این نسخه بسته شد.**

| شاخص | نتیجهٔ واقعی |
|---|---:|
| تست‌های Unit و Feature پیکربندی‌شده | **۱۱۳۴** |
| assertionها | **۱۶٬۵۹۸** |
| failure / error / skip | **صفر / صفر / صفر** |
| خروج | **۰** |
| زمان JUnit | **۱۳۲۳٫۹۶۷۲۹۶ ثانیه؛ حدود۲۲ دقیقه و۴ ثانیه** |
| هش منبع ثابت پیش، حین و پس از اجرا | **۴۵۲ فایل** |
| هش harness ثابت | **۲ فایل** |

در این مرحله هیچ کد برنامه، مدل، migration، تست یا template تغییر نکرد. تغییرات پس از اجرا فقط مستندات و بازیابی‌اند. نتیجهٔ۸۵/۲۸۸ مربوط به تست‌های مرتبط مرحلهٔ قبل و نتیجهٔ۱۱۱۲/۱۶٬۴۸۸ مربوط به اجرای کامل منبع قبلی است؛ این‌ها به شمار فعلی اضافه نمی‌شوند.

فرمان از app/:

```sh
SESSION_URL_FALLBACK=false vendor/bin/phpunit --colors=never --testdox \
  --log-junit ../docs/evidence/2026-10-18-cde-full/phpunit.xml
```

شواهد خام شامل log، JUnit، خروج، manifest منبع و harness، وضعیت runtime و نقش دیتابیس، بررسی‌های مستقل و checksumها:

`docs/evidence/2026-10-18-cde-full/`

## بازسازی محیط اجرایی و جداسازی

runtime پس از بازنشانی workspace باقی نمانده بود؛ وجود vendor یا دیتابیس قبلی فرض نشد. PHP8.4.26 و extensionها، Composer، همان **۱۴۷ بستهٔ قفل‌شده** بدون تغییر lockfile، PostgreSQL17.11 و ابزارهای PDF/font لازم بازسازی شدند. PHPUnit12.5.35 از lock نصب شد؛ composer install با --no-scripts بود. مقدار env/credential منتشر نشد.

cluster کاملاً تازه و مستقل با epcc_test ساخته شد؛ نه دادهٔ مشتری یا demo. نقش epcc فاقد superuser، CREATEDB و BYPASSRLS است. socket خصوصی/پورت۵۵۴۵، بدون TCP و با local trust فقط آزمایشی؛ تنظیمات fsync، synchronous_commit و full_page_writes روشن و سقف WAL۱۲۸/۳۲MB بود. داده روی cache دیسکی است، نه tmpfs. هیچ guard یا RLS خاموش نشد و اجرای دیتابیس رقیب هم‌زمان نبود. پُرشدن دیسک یا recovery جدید در این اجرا رخ نداد.

metadata فعلی: `/home/user/.cache/epcc-app-journal-cluster.json`؛ مسیر دادهٔ تازه در cache به نام `epcc-app-journal-pg-cpv573x2` ثبت شده است. دیتابیس پس از اتمام کار متوقف می‌شود. این runtime داخل بازیابی منبع نیست و پس از reset ممکن است دوباره نیاز به ساخت داشته باشد؛ مسیرهای قدیمی گزارش‌ها را بدون بررسی metadata استفاده نکنید.

## بررسی‌های مستقل

Composer validate/platform، lint تعداد۳۶۰ فایل PHP، standards، conformance، screening و مثال مصنوعی آن موفق بودند. ابزارهای مستقل proposal۴۶، selection۳۳ و planner۲۶ نیز موفق شدند؛ جدا از شمار PHPUnit.

هم‌زمانی تازهٔ دو connection، crash-recovery، frontend build/browser smoke، CI تازه یا پایلوت یکپارچه اجرا نشده است. موفقیت یک suite جای این شواهد را نمی‌گیرد.

## مرزهای محصول و اختیار حفظ شده‌اند

[فهرست مرجع تصمیم‌ها](DECISION-REFERENCE-INDEX-FA.md) مبنای ادامه است. قواعد پذیرفته‌شدهٔ ADR-018 دربارهٔ شواهد، mapping/withdrawal، header/descendant، پوشش و snapshot تاریخی دوباره سؤال نشده‌اند. بازطراحی اختیار MTO همچنان طبق تصمیم قبلی معوق است.

- سرویس CDE فقط داخلی و فقط‌خواندنی است؛ هیچ endpoint، role/grant، migration یا اتصال به مصرف‌کنندگان قبلی ایجاد نشده است.
- `BYTES_VERIFIED` فقط تطبیق بایت‌هاست؛ حقیقت مهندسی، امضای صادرکننده، retention، وضعیت ابطال یا اختیار admission/additive را ثابت نمی‌کند.
- state/suitability و تغییرپذیری WIP/SHARED گزارش می‌شوند، نه اینکه خودکار به تأیید تبدیل شوند. revision تاریخی با currentRevision جایگزین نمی‌شود.
- بازخوانی metadata قفل اتمیک فایل+DB نیست؛ محدودیت‌های ABA، تغییر بعد از آخرین query و snapshot caller پابرجاست. تست drift تزریقی، اثبات هم‌زمانی واقعی نیست.
- observation/admission همچنان **UNADMITTED**، انتخاب مؤثر **UNRESOLVED**، readiness=false و gross=null است. OrderGapService تغییری نکرده و مسئلهٔ selected gross/overlap حل نشده است.
- backfill قدیمی، اعتبارسنجی FK تاریخی، تغییر اختیار MTO یا قواعد usable/RECEIVED، migration مشتری/تولید، commit/push/PR انجام نشده است. ADR-032 پیشنهادی باقی است؛ سبزبودن محلی گواه امنیت، load، availability یا backup تولید نیست.

## بازیابی و قدم بعدی

بستهٔ مستقل `epcc-source-recovery-cde-full-2026-10-19.tar.gz` روی baseline دقیق `0c54c7f84d1e343bf1d58833977ac0834dcfdcc0` ساخته شده است. runtime، env/کلید/credential، dependencies و دادهٔ دیتابیس ندارد. در بازسازی تمیز، تمام۴۵۲ هش منبع و محتوای جدید/تغییریافته تطبیق و فقط ابزارهای مستقل۴۶/۳۳/۲۶ تکرار می‌شوند؛ اجرای کامل برنامه/PG روی کپی بازیابی‌شده تکرار نمی‌شود. بسته‌های قبلیِ pending تاریخی‌اند.

قدم فنی بعدی، reconciliation فقط‌خواندنی و محدودِ observation با topology پیشنهادی و/یا وابستگی اعتبار شواهد بر مبنای تصمیم‌های موجود است؛ بدون ساخت writer یا permission تازه از روی حدس. G01 باز است؛ اجرای کامل بسته‌شده را روی منبع بدون تغییر صرفاً به‌خاطر متن‌های تاریخی pending تکرار نکنید.
