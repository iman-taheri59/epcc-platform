> **وضعیت جاری —۲۰۲۶-۱۰-۱۹:** [اجرای کامل موفق](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-FA.md) / [EN](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-EN.md):۱۱۶۹ تست/۱۷٬۱۴۶ assertion، صفر failure/error/skip، خروج۰؛ hash۴۵۶ منبع و۲ worker ثابت. gate همین منبع بسته شد؛ متن‌های pending/next-full پایین تاریخی‌اند. **نقص شماره‌گذاری MAX زیر RLS اصلاح نشده** و پیشنهاد مستقل آن در گزارش است. G01 باز؛ UNADMITTED/UNRESOLVED، gross=null، بدون تغییر role/route/migration/OrderGap. بازیابی فعلی: epcc-source-recovery-reconciliation-evidence-full-2026-10-19.tar.gz؛ DB مستقل موقت پس از پایان متوقف شد.

# G01 — اجرای کامل منبع تطبیق topology موفق شد

۲۰۲۶-۱۰-۱۹ به وقت تهران و UTC. وضعیت: **GREEN_COMPLETE_SUITE_ON_TOPOLOGY_RECONCILIATION_SOURCE**. [نسخهٔ EN](G01-TOPOLOGY-FULL-SUITE-EN.md).

## نتیجهٔ واقعی

- **۱۱۵۱ تست و ۱۶٬۷۷۶ assertion** در کل Unit + Feature.
- **صفر failure/error/skip، خروج۰**.
- زمان JUnit: **1311.528845 ثانیه**.
- hash تمام **۴۵۴ فایل منبع و۲ worker** با manifest پیش از اجرا یکسان است؛ منبع با اجرای focused قبلی همسان است.
- هیچ فایل برنامه/model/migration/test/template یا lockfile در این مرحله عوض نشد؛ تغییرات این مرحله فقط محیط موقت، شواهد، مستندات و بازیابی است.

۱۷ تست جدید/۱۷۸ assertion و نتیجهٔ مرتبط۸۳/۴۴۲ را نباید به مجموع اجرای کامل اضافه کرد. شواهد خام log/JUnit/exit/manifest/checksum و بررسی‌های مستقل: `docs/evidence/2026-10-19-topology-full/`. دستور دقیق اجرای سری در نسخهٔ EN آمده است.

## این نتیجه چه چیزی را اثبات نمی‌کند؟

[فهرست تصمیم‌ها](DECISION-REFERENCE-INDEX-FA.md) و [قرارداد مقایسه‌گر](G01-TOPOLOGY-RECONCILIATION-FA.md) مرجع‌اند. قواعد پذیرفته‌شدهٔ ADR-018 §§۹٫۲–۹٫۶ و تعویق اختیار MTO بدون تغییرند.

مقایسه‌گر فقط روابط observation منجمد و proposal غیرقابل‌اعتماد را زیر RLS و با binding/hash تازه مقایسه می‌کند؛ graph امروز را حقیقت تاریخی فرض نمی‌کند. تطابق ساختاری یک fragment اثبات authority تاریخی/coverage/اعتبار شواهد نیست. node ثبت‌نشده الزاماً جدید و node غایب الزاماً بازنشسته یا صفر مصوب نیست.

همهٔ خروجی‌ها همچنان **UNADMITTED، ready_to_admit=false، ready=false و gross=null** هستند. انتخاب مؤثر UNRESOLVED است و OrderGap/مصرف‌کننده‌های قبلی عوض نشده‌اند. query واحد، cutoff پایدار یا قفل تغییر بعدی نیست؛ محدودیت visibilityِ isolation caller باقی است. اجرای تازهٔ contention دو connection یا crash-recovery ادعا نمی‌شود؛ workerها فقط hash-check شدند.

هیچ writer عملیاتی، role/grant/بازطراحی دسترسی، endpoint، legacy backfill، migration/استقرار مشتری، قاعدهٔ usable stock یا commit/push/PR ایجاد نشد. **G01 همچنان باز است**؛ سبزشدن تست‌ها تأیید مهندسی، امنیت، بار، availability، backup یا پایلوت نیست.

## محیط و بررسی‌های مستقل

runtime موقت پس از reset موجود نبود؛ PHP8.4.26/extensions، PostgreSQL17.11، Composer، Chromium/poppler/fonts و۱۴۷ package از lock بدون script بازسازی شدند؛ PHPUnit12.5.35 و source/lockfile ثابت‌اند. env/APP_KEY/secret حفظ و منتشر نشدند؛ فقط تنظیمات اتصال ignored به محیط مستقل جدید اشاره می‌کنند.

فقط epcc_test مستقل با fixture مصنوعی ساخته شد؛ نه DB مشتری/دمو. نقش epcc بدون superuser/BYPASSRLS/CREATEDB؛ socket خصوصی۵۵۴۵ و بدون TCP، local trust فقط برای تست؛ fsync/synchronous_commit/full_page_writes روشن و WAL۱۲۸/۳۲MB روی cache disk. اجرا سری و بدون suite/bootstrap/seed/blob purge رقیب بود؛ هیچ RLS/guard خاموش نشد. DB پس از پایان متوقف شد.

data: `/home/user/.cache/epcc-topology-pg-1ge_atll`؛ socket: `/tmp/epcc-topology-sock-v4vh9mdo`. این مسیرها موقت‌اند و در recovery نیستند. metadata در `/home/user/.cache/epcc-app-journal-cluster.json` فقط اگر باقی مانده باشد معتبر است؛ در reset بعدی باید محیط مستقل دوباره ساخته و posture بررسی شود.

Composer validate/platform، lint تعداد۳۶۲ فایل، standards/conformance/screening و example موفق‌اند. ابزارهای مستقل proposal۴۶/selection۳۳/planner۲۶ جدا هستند و به PHPUnit اضافه نمی‌شوند. frontend build/browser smoke، CI تازه، Docker یا پایلوت یکپارچهٔ جداگانه اجرا نشده است.

## وضعیت تاریخی و ادامه

- منبع۴۵۰فایلی:۱۱۱۲/۱۶٬۴۸۸ تاریخی.
- منبع۴۵۲فایلی:۱۱۳۴/۱۶٬۵۹۸ تاریخی.
- منبع۴۵۴فایلی مرتبط:۸۳/۴۴۲؛ ۱۷/۱۷۸ جدید در همان مجموع.
- منبع۴۵۴فایلی کامل فعلی: **۱۱۵۱/۱۶٬۷۷۶ موفق؛ گام pending بسته شد**.

عبارت‌های pending/next-full قدیمی برای همین منبع با این گزارش supersede می‌شوند؛ شواهد و بسته‌های قبلی تاریخی و بدون تغییر معنا باقی می‌مانند. صرف متن قدیمی، دلیل تکرار gate بسته‌شده روی منبع ثابت نیست.

بستهٔ مستقل فعلی `epcc-source-recovery-topology-full-2026-10-19.tar.gz` روی baseline دقیق0c54c7f84d1e343bf1d58833977ac0834dcfdcc0 است؛ فقط source/docs/evidence، بدون env/credential/dependency/runtime/DB. بازسازی تمیز، hash۴۵۴ فایل و ابزارهای۴۶/۳۳/۲۶ را بررسی می‌کند؛ اجرای کامل برنامه/PG روی کپی بازسازی‌شده تکرار نشده است. بستهٔ قبلی topology-reconciliation عمداً snapshot تاریخیِ pending می‌ماند.

کار بعدی: بررسی تصمیم‌ها و کار محدود فقط‌خواندنی برای disposition/وابستگی‌های اعتبار شواهد؛ full envelope، authority topology تاریخی و coverage/admission تا پیاده‌سازی و اثبات همچنان unresolved می‌مانند. هیچ اختیار انسانی یا تغییر پنهان writer/selector از سبزشدن تست‌ها استنتاج نمی‌شود.
