> **پیش‌نیاز شاهد،۲۰۲۶-۱۰-۲۵:** [پیش‌بررسی بایت CDE با هویت اصلی](SCOPE-EVIDENCE-PREFLIGHT-FA.md) هر دو ارجاع منتخب را با doc.view مستقل و رشتهٔ واقعی پیش از stream کنترل می‌کند. اتصال شاهد/هویت ترکیبی دائمی/نگهداری یا اعتبار جاری نمی‌سازد؛ پرچم‌های آمادگی/تأیید قبلی false می‌مانند.

> **Current closeout — 2026-10-19 local /2026-10-18 UTC:** [CDE-source complete suite GREEN](G01-CDE-FULL-SUITE-EN.md) / [فارسی](G01-CDE-FULL-SUITE-FA.md): **1134 tests/16598 assertions,0 failures/errors/skips,exit0;452 source and2 worker hashes unchanged**; JUnit1323.967296s. New-source full pending gate CLOSED. Earlier pending/no-full/next-full statements below are superseded history, not current instructions. No app source edits this closeout. G01 OPEN; byte integrity is NOT engineering approval/admission, UNADMITTED/UNRESOLVED, no authority/OrderGap change. Runtime recreated after reset; current cache cluster is in metadata, stopped after closeout. Next: accepted-decision-backed read-only reconciliation/validity work, not rerun of unchanged full gate. Current standalone recovery: epcc-source-recovery-cde-full-2026-10-19.tar.gz.

# G01 — بررسی فقط‌خواندنی فایل دقیق CDE

۲۰۲۶-۱۰-۱۸ — **تست‌های مرتبط موفق؛ اجرای کامل منبع جدید در انتظار است.** نسخهٔ متناظر EN جزئیات قرارداد و محدودیت‌ها را دارد.

## مبنای تصمیم و تغییر انجام‌شده

طبق [فهرست تصمیم‌ها](DECISION-REFERENCE-INDEX-FA.md)، الزامات MTO §۳٫۵ و تصمیم پذیرفته‌شدهٔ ADR-018 §۹٫۲، مدرک مبتنی بر CDE باید به revision دقیق و اصل قابل نگهداری/بازیابی ارجاع دهد؛ hash تنها کافی نیست.

دو فایل اضافه شد:

- `app/app/Services/CdeEvidenceIntegrityService.php`
- `app/tests/Feature/CdeEvidenceIntegrityTest.php`

این سرویس داخلی فقط مرجع دقیق و بایت‌های محلی را بررسی می‌کند. **migration، endpoint وب/CLI، role/grant، مسیر تأیید یا تغییری در OrderGap و مصرف‌کنندگان قبلی اضافه نشده است.** DocumentFileStore نیز دست‌نخورده است. endpoint احتمالی آینده باید authentication/entitlement موجود را مستقل رعایت کند؛ lookup تحت RLS جای آن را نمی‌گیرد.

## رفتار سرویس

۱. ورودی، UUID دقیق پروژه/سند/revision/فایل و SHA-256 مورد انتظار است؛ عنوان یا شمارهٔ سند به‌تنهایی پذیرفته نمی‌شود. UUIDهای معادل از نظر حروف normalize می‌شوند.
۲. هر سه جدول با tuple و project دقیق، در یک query و تحت RLS عادی خوانده می‌شوند؛ بدون تغییر tenant، دورزدن RLS، اعتماد به مدل caller یا جایگزینی currentRevision.
۳. اگر مرجع نامشهود، ناموجود یا نامنطبق باشد، خطای یکسان و بدون اطلاعات منبع بازمی‌گردد. فایل هم‌hash از سند/revision دیگر جایگزین نمی‌شود.
۴. hash مورد انتظار باید با hash ثبت‌شده یکی باشد؛ سپس مسیر content-addressed بازسازی می‌شود. مسیر متفاوت دیتابیس، filename کاربر، disk غیرمحلی و URL/S3 خوانده نمی‌شوند.
۵. بایت‌های واقعی با stream و قطعات ۶۴KiB خوانده می‌شوند. اندازه و hash واقعی با مرجع مقایسه می‌شود؛ سقف همان محدودیت موجود پیوست‌ها، ۱۰۰MiB است. فایل مفقود، غیرقابل‌خواندن، دستکاری‌شده یا با اندازهٔ متفاوت رد می‌شود.
۶. metadata منتخب دوباره خوانده می‌شود و تغییر قابل‌مشاهده حین خواندن، نتیجه را مسدود می‌کند.
۷. فقط پس از موفقیت، provenance مرجع دقیق، hash، تعداد بایت و state/suitability/issuer-time قابل‌مشاهده بازمی‌گردد؛ نه مسیر ذخیره‌سازی، disk، بایت‌های فایل یا جزئیات exception.

موفقیت فقط `BYTES_VERIFIED` و `integrity_verified=true` است. همچنان:

- `ready_to_admit=false`
- `admission_state=UNADMITTED`
- `ready=false` و `gross=null`

مرجع مهندسی، ماندگاری و ابطال هنوز تأیید نشده‌اند. برای WIP/SHARED، قابل‌تغییر بودن محتوا نیز صریح گزارش می‌شود. بررسی بایت‌ها در state قابل‌مشاهده مجاز است، ولی شرط تازه‌ای برای پذیرش مدرک وضع نشده؛ PUBLISHED، suitability نوع A/B و ARCHIVED خودکار به تأیید برای کاربرد موردنظر تبدیل نمی‌شوند. revision تاریخی archived بررسی می‌شود، نه اینکه با revision فعلی جایگزین شود.

## شواهد واقعی

- **۸۵ تست مرتبط و ۲۸۸ assertion؛ خروج۰، بدون failure/error/skip**.
- در همین مجموع، **۲۲ تست و ۱۱۰ assertion جدید** برای سرویس.
- تست‌ها شامل provenance دقیق و نبود تغییر DB، WIP، revision تاریخی، سند اشتباه با hash یکسان، outsider و WIP خصوصی، visibility مجاز سند منتشرشده، پروژهٔ اشتباه، شناسه/hash نامعتبر، فایل مفقود/دستکاری/کوتاه/بزرگ، مسیر نامعتبر، disk دوردست، تغییر metadata، casing UUID و خواندن پروژهٔ archived هستند.
- lint تعداد۳۶۰ فایل PHP و تست‌های مستقل proposal۴۶، selection۳۳ و planner۲۶ موفق شدند؛ این اعداد به PHPUnit اضافه نمی‌شوند.

تست‌ها با PostgreSQL17.11 و نقش محدود epcc، PHP8.4.26/PHPUnit12.5.35 و fixture مصنوعی اجرا شدند. scratch disk واقعاً stream و hash می‌شود؛ فقط سناریوی تزریق تغییر metadata، دسترسی disk را هدفمند جایگزین می‌کند. اجرای اولیه یک fixture با email ناموجود داشت؛ اصلاح شد و شکست اولیه در `initial.*` حفظ شده است.

شواهد: `docs/evidence/2026-10-18-cde-integrity/`.

## محدودیت‌ها — چه چیزی اثبات نشده است؟

- برابری hash در لحظهٔ خواندن، حقیقت مهندسی، امضای صادرکننده، retention تغییرناپذیر، اعتبار فعلی مدرک یا مجوز additive نیست.
- تنظیمات root محلی و سرور trusted هستند؛ این سرویس در برابر مالک مخرب filesystem/DB، symlink زیر root مخدوش یا adapter مخرب محافظ کامل نیست.
- بازخوانی metadata، قفل یا snapshot اتمیک فایل+DB نیست؛ تغییر ABA، تغییر پس از آخرین query یا commit پنهان در REPEATABLE READ caller الزاماً دیده نمی‌شود.
- تست drift تغییر را در callback همان آزمون تزریق می‌کند؛ اثبات هم‌زمانی دو connection مستقل نیست.
- transaction هنگام stream باز است؛ load/performance یا دوام تولید تأیید نشده است.
- adapter خارجی، writer admission، احراز export، retention/revocation reader، اتصال به proposal و reconciliation topology هنوز تحویل نشده‌اند؛ اختیار MTO طبق تصمیم قبلی معوق است.

## وضعیت نسخه و ادامه

منبع فعلی **۴۵۲ فایل** است: ۴۵۰ فایل قبلی ثابت‌اند و دو فایل اضافه شده‌اند. **اجرای کامل این منبع انجام نشده است.** نتیجهٔ ۱۱۱۲ تست/۱۶٬۴۸۸ assertion، شواهد منبع قبلیِ ۴۵۰فایلی است و به این تغییرات تعمیم داده نمی‌شود.

گام بعدی اجرای کامل روی همین منبع۴۵۲فایلی است؛ سپس تکمیل فنیِ محدود با تکیه بر قواعد پذیرفته‌شدهٔ شواهد، اصلاح/withdrawal و تاریخچه. G01 باز و OrderGap بدون تغییر است. هیچ migration مشتری/تولید، commit/push/PR، frontend/browser یا پایلوت یکپارچه اجرا نشد.

بستهٔ مستقل فعلی: `epcc-source-recovery-cde-integrity-2026-10-18.tar.gz`؛ راهنمای آن صریحاً اجرای کامل نسخهٔ جدید را pending اعلام می‌کند.
