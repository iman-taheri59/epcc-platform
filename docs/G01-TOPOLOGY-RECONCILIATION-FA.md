> **وضعیت جاری —۲۰۲۶-۱۰-۱۹:** [اجرای کامل موفق](G01-TOPOLOGY-FULL-SUITE-FA.md) / [EN](G01-TOPOLOGY-FULL-SUITE-EN.md):۱۱۵۱ تست/۱۶٬۷۷۶ assertion، صفر failure/error/skip، خروج۰؛ hash۴۵۴ منبع و۲ worker ثابت. gate اجرای کامل این منبع بسته شد. متن‌های pending/next-full قدیمی پایین تاریخی‌اند و با این گزارش supersede می‌شوند. مقایسه فقط‌خواندنی است؛ G01 باز و UNADMITTED/UNRESOLVED، gross=null، بدون تغییر اختیار/OrderGap/role/route/migration. بازیابی فعلی: epcc-source-recovery-topology-full-2026-10-19.tar.gz؛ DB مستقل موقت پس از پایان متوقف شد.

# G01 — تطبیق فقط‌خواندنی topology ثبت‌شده با پیشنهاد

۲۰۲۶-۱۰-۱۹ — **تست‌های مرتبط موفق؛ اجرای کامل نسخهٔ جدید در انتظار است.** نسخهٔ EN جزئیات کامل قرارداد و محدودیت‌ها را دارد.

## مبنای تصمیم و تغییر انجام‌شده

[فهرست مرجع تصمیم‌ها](DECISION-REFERENCE-INDEX-FA.md)، تصمیم‌های پذیرفته‌شدهٔ ADR-018 §§۹٫۲–۹٫۶، الزامات MTO §۳٫۶ و قرارداد پیشنهادی admission مرور شدند. ID پایدار، pin نسخه، عدم انتقال خودکار coverage، mapping مستند، تاریخچهٔ تغییرناپذیر و تعویق اختیار MTO، تصمیم‌های موجودند؛ دوباره سؤال نشده‌اند.

دو فایل اضافه شد:

- `app/app/Services/MtoTopologyReconciliationService.php`
- `app/tests/Feature/MtoTopologyReconciliationTest.php`

هیچ migration، endpoint وب/CLI، role/grant، writer تأیید، تغییر snapshot، اتصال CDE به proposal، تغییر OrderGap یا مصرف‌کنندهٔ قبلی ایجاد نشد. سرویس readiness فعلی نیز دست‌نخورده است. این diagnostic داخلی جای احراز هویت/entitlement مسیر احتمالی آینده یا سیاست اختیار را نمی‌گیرد.

## سرویس چه می‌کند؟

متد `compare` شناسهٔ batch، binding دقیق و nodeهای پیشنهادیِ غیرقابل‌اعتماد را می‌گیرد.

- batch و observation را با query تازه و تحت RLS عادی می‌خواند، نه attribute یا relation کش‌شدهٔ caller.
- project/batch/observation، hash payload و row-set، pin ادعاشده و header را دقیق تطبیق می‌دهد. field اضافی approved یا نسخهٔ متفاوت به mapping/اختیار تبدیل نمی‌شود.
- در **یک statement SQL**، payload و digestهای آن و row-set فعلی خوانده/بررسی می‌شوند؛ تغییر tenant یا دورزدن RLS وجود ندارد.
- فقط JSON تاریخیِ observation را می‌خواند؛ جدول‌های mutable فعلی Subsystem/Area/Facility منبع مقایسه نیستند.
- روابط project→facility→area اختیاری→subsystem را از facts ثبت‌شده بازسازی می‌کند؛ area خالی یعنی اتصال مستقیم، نه area جعلی. ریشهٔ project فقط wrapper ساختاری روابط معلوم است و scope خالی را به پوشش کل پروژه تبدیل نمی‌کند.
- والد نامشهود/ناهم‌پروژه، تضاد area و facility، pin/scope مفقود یا row-set تغییرکرده، مقایسه را ناقص نگه می‌دارند؛ proposal جای شواهد ناقص را پُر نمی‌کند.

nodeهای proposal باید یکتا، هم‌پروژه، دارای والد صریح و نوع مجاز باشند. ریشهٔ PROJECT یکتا و رابطه‌ها مطابق hierarchy ثابت فعلی‌اند؛ والد ناموجود، چرخه، field ناشناخته و نوع نامعتبر رد می‌شوند. سقف فنی هر graph برابر۱۰هزار node است؛ عبور از سقف نتیجهٔ ناقص می‌دهد، نه پوشش جزئیِ تأییدشده. این سقف resource است، نه قاعدهٔ تجاریِ اندازهٔ پروژه.

binding داخلی باید دقیقاً با مقادیر مرجع یکسان باشد؛ node UUID و parent normalize می‌شوند ولی project_id باید UUID canonical پروژه باشد. این interface داخلی HTTP parser عمومی یا مسیر ثبت proposal نیست.

## تفاوت‌ها چگونه گزارش می‌شوند؟

| وضعیت | معنی |
|---|---|
| `OBSERVED_EDGE_OR_KIND_DIFFERS` | همان ID، ولی والد یا نوع پیشنهادی متفاوت است |
| `NODE_NOT_IN_PROPOSAL` | node ثبت‌شده در پیشنهاد نیست؛ به‌معنای retirement یا صفر مصوب نیست |
| `NOT_OBSERVED_AT_CAPTURE` | node پیشنهادی در این observation محدود ثبت نشده؛ اثبات جدیدبودن، نامعتبر بودن یا نبودن تاریخی آن نیست |

ترتیب تفاوت‌ها مستقل از ترتیب ورودی و بر اساس UUID قطعی است. observation تنها اجداد scopeهای ارجاع‌شده را دارد، نه موجودی کامل پروژه؛ node اضافهٔ proposal ممکن است صرفاً بیرون از دامنهٔ capture بوده باشد.

این سرویس coverage مستقل، مرز header، equivalence، split/merge یا مجوز جمع‌کردن را تأیید نمی‌کند. اعتبارسنج کامل proposal مستقل است و با این کار به adapter معتبر تبدیل نشده است.

خروجی مقایسهٔ کامل می‌تواند `MATCHES_OBSERVATION` یا `DIFFERENCES_FOUND` باشد. در حالت ناقص `comparison_complete=false` و differences خالی می‌ماند تا گزارش جزئی شبیه نتیجهٔ معتبر دیده نشود. **در همهٔ حالت‌ها:**

- `ready_to_admit=false`
- `admission_state=UNADMITTED`
- `ready=false` و `gross=null`

حتی تطابق کامل نیز عدم احراز اختیار مهندسی، پوشش و اعتبار شواهد را گزارش می‌کند. تطابق با مشاهدهٔ یک graph mutable، صحت topology تاریخی رسمی را اثبات نمی‌کند و هیچ نتیجه‌ای ذخیره/admit نمی‌شود.

## محدودیت‌ها

query واحد، snapshot همان statement و تابع isolation موجود caller است؛ نه cutoff پایدار گزارش، قفل آینده، lease انتخاب، امضای proposal یا اثبات اعتبار جاری شواهد. تغییر بعدی rowها یا commit نامشهود در repeatable-read caller الزاماً دیده نمی‌شود. این سرویس در برابر مالک مخرب schema که trigger/hash را عوض کند تضمین ندارد. هم‌زمانی تازهٔ دو connection یا crash-recovery آزمایش نشده است. هیچ فایل/شبکه/CDE در این سرویس خوانده نمی‌شود.

## شواهد واقعی

- **۸۳ تست مرتبط، ۴۴۲ assertion؛ خروج۰، بدون failure/error/skip**.
- در همین مجموع، **۱۷ تست جدید و ۱۷۸ assertion**.
- suiteهای topology reconciliation، scope observation، acceptance journal، CDE integrity، OrderGap و RLS sargability.
- پوشش اختصاصی: match بدون write، area اختیاری، والد/نوع متفاوت، node مفقود/ثبت‌نشده، نادیده‌گرفتن reparent جاری، pin/scope ناقص، تمام bindingها، graph خراب/ناهم‌پروژه/چرخه، ترتیب قطعی و عدم mutation، افزودن خام row، outsider/مدل جعلی، نبود observation پیش از commit، archive و والد unresolved در capture.
- lint تعداد۳۶۲ فایل PHP و ابزارهای مستقل proposal۴۶، selection۳۳ و planner۲۶ موفق‌اند؛ جدا از PHPUnit.

محیط: PostgreSQL17.11 مستقل epcc_test با نقش محدود epcc، PHP8.4.26/PHPUnit12.5.35، fixture مصنوعی، socket خصوصی۵۵۴۵/بدون TCP و تنظیمات دوام روشن/WAL۱۲۸/۳۲MB. اجرای اولیه۱۵ موردی موفق بود. اجرای توسعه‌یافته یک خطای fixture داشت: first() بدون ترتیب پس از UPDATE زیرسیستم متفاوتی انتخاب می‌کرد. fixture با ترتیب ID قطعی اصلاح و انتظار unresolved-parent حفظ شد؛ شکست میانی در `fixture-order-failure.*` نگه داشته شده و اجرای نهایی موفق است. هیچ RLS/constraint خاموش نشد.

شواهد: `docs/evidence/2026-10-19-topology-reconciliation/`.

## نسخه و قدم بعدی

۴۵۲ فایل پیشین ثابت‌اند و دو فایل اضافه شده؛ منبع فعلی **۴۵۴ فایل** است. **اجرای کامل این نسخه هنوز انجام نشده**؛ نتیجهٔ کامل۱۱۳۴/۱۶٬۵۹۸ فقط برای منبع قبلی۴۵۲فایلی است.

قدم بعدی اجرای کامل همین منبع۴۵۴فایلی است؛ سپس disposition/اعتبار شواهد با دامنهٔ محدود و مبتنی بر تصمیم‌های پذیرفته‌شده، بدون اختراع اختیار جدید. هیچ commit/push/PR، migration مشتری/استقرار، frontend/browser، CI تازه یا پایلوت یکپارچه اجرا نشد. G01 باز و OrderGap بدون تغییر است.

بستهٔ مستقل فعلی: `epcc-source-recovery-topology-reconciliation-2026-10-19.tar.gz`؛ اجرای کامل نسخهٔ جدید در راهنمای آن صریحاً pending است.
