# G01 — اجرای کامل منبع diagnostic تطبیق/شواهد موفق شد

۲۰۲۶-۱۰-۱۹ تهران و UTC. وضعیت **GREEN_COMPLETE_SUITE_ON_RECONCILIATION_EVIDENCE_SOURCE**. [EN](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-EN.md).

> **نقص شماره‌گذاری اصلاح نشده است:** MAX زیر RLS در DocumentRegister می‌تواند با شمارهٔ سند خصوصیِ نامشهود برخورد کند. این اجرای سبز، شاهد regression همین منبع است، نه رفع یا تأیید سلامت allocator.

## نتیجهٔ واقعی

- **۱۱۶۹ تست و۱۷٬۱۴۶ assertion** در کل Unit + Feature.
- **صفر failure/error/skip، خروج۰**.
- زمان JUnit: **1211.438979 ثانیه**.
- hash تمام **۴۵۶ فایل منبع و۲ worker** با manifest پیش از اجرا و منبع focused قبلی یکسان است.
- در این مرحله هیچ فایل برنامه/model/migration/test/template یا lockfile تغییر نکرد؛ تغییرات پس از اجرا فقط مستندات و بازیابی‌اند.

۱۸ تست جدید/۳۷۰ assertion داخل مجموع کامل‌اند. نتیجهٔ مرتبط۱۰۱/۸۱۲ یا ابزارهای مستقل به این مجموع اضافه نمی‌شوند. log خام، JUnit، exit، manifest، posture/Composer/checks و checksum در `docs/evidence/2026-10-19-reconciliation-evidence-full/` هستند؛ دستور دقیق اجرای سری در EN آمده است.

## مرز تصمیم و محصول

[فهرست تصمیم‌ها](DECISION-REFERENCE-INDEX-FA.md)، ADR-018 §§۹٫۲–۹٫۶ و [قرارداد diagnostic](G01-RECONCILIATION-EVIDENCE-FA.md) مرجع‌اند. اختیار MTO معوق است. سبزشدن این gate، مجوز انسانی یا تصویب envelope معتبر admission نیست.

سرویس فقط‌خواندنی، topology منجمد را با proposal مقایسه و بایت tuple دقیق CDE در همان پروژه را بررسی می‌کند و سپس منبع را دوباره می‌خواند. منبع نامشهود/دچار drift یا وابستگی خراب ناقص می‌ماند. hash درست، تفاوت topology را تعیین تکلیف نمی‌کند. رابطهٔ فایل با node/row/pin و purposeهای topology/coverage احراز نمی‌شود؛ فایل نامرتبط یا یک فایل برای هر دو purpose نیز ممکن است hash درست داشته باشد، بدون اثبات پوشش مستقل.

DEPENDENCY_BYTES_CHECKED/diagnostic_complete فقط پایان diagnostic محدود بایت است، نه authority تاریخی، coverage کامل، اختیار issuer، retention تغییرناپذیر، اعتبار revocation یا admission. خواندن‌ها بین DB و filesystem اتمیک نیستند؛ تغییر بعدی، ABA و visibilityِ isolation caller باقی‌اند. callback تست recheck، اثبات concurrency مستقل نیست؛ workerها فقط hash-check شده‌اند.

در همهٔ خروجی‌ها evidence_relationship_verified و evidence_validity_verified=false، ready_to_admit=false، UNADMITTED، ready=false و gross=null می‌مانند. انتخاب مؤثر UNRESOLVED، G01 باز و OrderGap/مصرف‌کننده‌های قبلی ثابت‌اند. هیچ endpoint/CLI، role/grant، بازطراحی دسترسی، schema/migration، writer تصمیم/admission، backfill، تغییر usable stock، استقرار/migration مشتری یا commit/push/PR انجام نشد.

## محیط و بررسی‌های مستقل

runtime پس از reset موجود نبود؛ PHP8.4.26/extensions، PostgreSQL17.11، Composer با۱۴۷ package قفل‌شده و --no-scripts، PHPUnit12.5.35 و Chromium/poppler/fonts بدون تغییر source/lock/version بازسازی شدند. env/APP_KEY/secret حفظ و منتشر نشد؛ فقط اتصال ignored به محیط مستقل تازه اشاره می‌کند.

فقط epcc_test مصنوعی تازه، نه DB مشتری/دمو؛ epcc بدون superuser/BYPASSRLS/CREATEDB، socket خصوصی۵۵۴۵ بدون TCP و local trust فقط تست. دوام روشن/WAL۱۲۸/۳۲MB روی cache disk. اجرای سری بدون suite/bootstrap/seed/blob purge رقیب و بدون خاموش‌کردن RLS/guard. DB پس از پایان متوقف شد.

data: `/home/user/.cache/epcc-reconciliation-full-pg-rbik7nwk`؛ socket: `/tmp/epcc-reconciliation-full-sock-lq78n0a7`؛ metadata: `/home/user/.cache/epcc-app-journal-cluster.json`. runtime موقت داخل recovery نیست و ممکن است در reset پاک شود؛ راهنمای native و metadata/posture موجود دوباره بررسی شوند، نه مسیر قدیمی.

Composer validate/platform، lint تعداد۳۶۴ فایل، standards/conformance/screening/example و ابزارهای۴۶/۳۳/۲۶ موفق‌اند. اجرای تازهٔ مستقل دو connection/crash/backup، frontend/browser، CI/Docker یا پایلوت ادعا نشده است.

## معنای تاریخی و نقص باز

۴۵۲ فایل/۱۱۳۴ تست/۱۶٬۵۹۸ assertion و۴۵۴ فایل/۱۱۵۱ تست/۱۶٬۷۷۶ assertion شواهد تاریخی‌اند. نتیجهٔ مرتبط منبع۴۵۶فایلی۱۰۱/۸۱۲ بود؛ **اجرای کامل همین منبع اکنون۱۱۶۹/۱۷٬۱۴۶ موفق و گام pending آن بسته است**. متن pending/next-full قدیمی برای همین منبع supersede می‌شود؛ بسته‌ها و logهای قبلی تغییر معنا نمی‌کنند و مجموع‌ها جمع نمی‌شوند.

نقص مستقل: ثبت داخلی Sahand زیر RLS، سند خصوصی Pars در CIVIL/DWG را نمی‌دید، MAX شمارهٔ PH-14-CIVIL-DWG-0001 را انتخاب و uniqueness کل پروژه آن را با23505 رد کرد. این fixture اثبات مسیر UI مجاز یا race هم‌زمان نیست. allocator تغییر نکرده است؛ شواهد `docs/evidence/2026-10-19-reconciliation-evidence/private-numbering-collision.*` محفوظ‌اند. fixture نهایی diagnostic با namespace مصنوعی REC-TEST از این برخورد جدا شد؛ نه RLS/constraint شل شد و نه رفع نقص تست شد.

پیشنهاد مستقل: ابتدا الزامات numbering/gap/rollback/archive و writer بررسی، سپس allocator تراکنشی project/discipline/type با دسترسی غیر‌افشاگر به counter، تخصیص اتمیک و backfill بررسی‌شده طراحی شود. تست سند خصوصی/چند شرکت، موازی، rollback/replay/archive لازم است. افشای سند خصوصی، خاموش‌کردن RLS یا retry همان MAX پنهان راه‌حل نیست. هیچ schema/role/سیاست gap جدیدی در این گزارش تصویب یا پیاده نشده است.

## بازیابی و قدم بعدی

بستهٔ مستقل `epcc-source-recovery-reconciliation-evidence-full-2026-10-19.tar.gz` با baseline دقیق0c54c7f84d1e343bf1d58833977ac0834dcfdcc0 شامل source/docs/evidence و بدون env/credential/dependency/runtime/DB است. بازسازی تمیز hashهای۴۵۶ منبع/۲ worker/content و ابزارهای۴۶/۳۳/۲۶ را بررسی می‌کند؛ اجرای برنامه/PG روی کپی بازیابی‌شده تکرار نشده است. بستهٔ pending قبلی تاریخی باقی می‌ماند.

اولویت پیشنهادی بعدی: بررسی تصمیم/نیازمندی و طراحی/بازتولید محدود نقص شماره‌گذاری، جدا از این gate. رابطه/اعتبار شواهد، authority، پوشش کامل و admission معتبر هنوز کار آینده‌اند. متن تاریخی pending دلیل اجرای دوبارهٔ gate بسته‌شده روی منبع ثابت نیست.
