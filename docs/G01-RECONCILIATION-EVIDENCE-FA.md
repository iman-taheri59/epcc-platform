> **وضعیت جاری —۲۰۲۶-۱۰-۱۹:** [اجرای کامل موفق](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-FA.md) / [EN](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-EN.md):۱۱۶۹ تست/۱۷٬۱۴۶ assertion، صفر failure/error/skip، خروج۰؛ hash۴۵۶ منبع و۲ worker ثابت. gate همین منبع بسته شد؛ متن‌های pending/next-full پایین تاریخی‌اند. **نقص شماره‌گذاری MAX زیر RLS اصلاح نشده** و پیشنهاد مستقل آن در گزارش است. G01 باز؛ UNADMITTED/UNRESOLVED، gross=null، بدون تغییر role/route/migration/OrderGap. بازیابی فعلی: epcc-source-recovery-reconciliation-evidence-full-2026-10-19.tar.gz؛ DB مستقل موقت پس از پایان متوقف شد.

# G01 — گزارش فقط‌خواندنی وابستگی تطبیق به شواهد

۲۰۲۶-۱۰-۱۹ — **تست‌های مرتبط موفق؛ اجرای کامل منبع جدید در انتظار است.** [نسخهٔ EN](G01-RECONCILIATION-EVIDENCE-EN.md).

## مبنای تصمیم و دامنه

[فهرست مرجع تصمیم‌ها](DECISION-REFERENCE-INDEX-FA.md)، ADR-018 §§۹٫۲–۹٫۶ و قرارداد draft admission مرور شدند. ارجاع دقیق به نسخهٔ مدرک، نگهداری اصل/hash، عدم استنتاج mapping/coverage و تاریخچهٔ تغییرناپذیر تصمیم‌های پذیرفته‌شده‌اند. اختیار MTO همچنان معوق است؛ این مرحله اختیار یا قاعدهٔ lifecycle جدیدی تصویب نمی‌کند.

فقط دو فایل جدید اضافه شدند:

- `app/app/Services/MtoReconciliationEvidenceService.php`
- `app/tests/Feature/MtoReconciliationEvidenceTest.php`

سرویس داخلی، مقایسه‌گر topology و بررسی‌کنندهٔ بایت CDE را ترکیب می‌کند. هیچ endpoint وب/CLI، migration/schema، role/grant، writer پذیرش/disposition، selector یا تغییر OrderGap و مصرف‌کنندهٔ قبلی ندارد. adapter کامل proposal/coverage نیست و اعتبارسنج coverage آفلاین را اجرا نمی‌کند.

## روند و ورودی

`inspect`، batch، binding هفت‌فیلدی موجود، nodeهای پیشنهادی و دو ارجاع با purpose **ادعاشدهٔ caller** به نام topology و coverage می‌گیرد. هر ارجاع دقیقاً project_id/document_id/revision_id/file_id/sha256 است. UUID، digest شصت‌وچهاررقمی lowercase و نبود field اضافی بررسی می‌شود. تمام شکل‌ها پیش از بازکردن هر فایل بررسی می‌شوند؛ URI/path/storage/approved پذیرفته نمی‌شود.

۱. تطبیق تازهٔ منبع منجمد زیر RLS؛ منبع نامشهود/ناقص، binding کهنه یا graph نامعتبر پیش از خواندن فایل متوقف می‌شود.
۲. پروژهٔ شواهد باید همان پروژهٔ binding تأییدشده باشد؛ جابه‌جایی tenant یا resolve پروژهٔ دیگر انجام نمی‌شود.
۳. tuple دقیق CDE و بایت محدود فایل برای هر دو purpose بررسی می‌شوند؛ latest revision جای نسخهٔ ارجاع‌شده را نمی‌گیرد.
۴. پس از بررسی فایل‌ها منبع دوباره خوانده می‌شود؛ drift یا ناپدیدشدن آن، نتیجه‌های فایل را خالی و diagnostic را ناقص می‌کند. تفاوت topology هیچ‌وقت disposition نمی‌شود.

این interface محدود فقط CDE است؛ جای اظهار controlled-record در prototype را نمی‌گیرد. شواهد کنترل‌شدهٔ خارجی/URI در طراحی پذیرفته‌شده ممنوع نشده‌اند؛ فقط در این adapter پشتیبانی نمی‌شوند. سقف دو خواندن محدود فایل و سقف۱۰هزار node مقایسه‌گر، محدودیت فنی است.

## معنای خروجی

اگر منبع ثابت و بایت هر دو فایل مطابق باشند، `DEPENDENCY_BYTES_CHECKED` و diagnostic_complete/all_evidence_bytes_verified=true گزارش می‌شود؛ در شکست هر وابستگی، نتیجه INCOMPLETE است. علت شکست هر purpose حفظ می‌شود، ولی aggregate موفق نیست. drift نهایی evidence را خالی می‌کند تا زیرگزارش موفقِ کهنه باقی نماند.

در **تمام حالت‌ها**:

- evidence_relationship_verified=false و evidence_validity_verified=false؛
- ready_to_admit=false، admission_state=UNADMITTED، ready=false و gross=null.

ارتباط فایل با node/row/pin یا purpose **احراز نشده است**. فایل کاملاً نامرتبط هم می‌تواند hash درست داشته باشد؛ سرویس محتوای مهندسی را تفسیر نمی‌کند. استفاده از یک فایل برای هر دو purpose فقط ادعای ورودی است، نه اثبات coverage مستقل. اختیار issuer، authority تاریخی، پوشش کامل، نگهداری تغییرناپذیر و revocation جاری احراز نمی‌شوند. WIP/SHARED قابل‌خواندن ممکن است hash مطابق داشته باشد ولی mutable گزارش می‌شود؛ PUBLISHED/ARCHIVED نیز مجوز مهندسی نیست. hash درست، تفاوت topology را حل نمی‌کند.

خواندن‌ها پشت سر هم‌اند، نه snapshot اتمیک DB+filesystem یا cutoff/lease/امضا. فایل اول می‌تواند پس از بررسی تغییر کند؛ ABA، تغییر بعدی و visibilityِ isolation caller همچنان محدودیت‌اند. query دوم منبع این محدودیت‌ها را نمی‌بندد. گزارش کامل همواره EVIDENCE_CHECKS_NOT_ATOMIC و احرازنشدن رابطهٔ purpose، authority، coverage و retention/revocation را اعلام می‌کند. ledger اعتبار/revocation یا تصمیم reconciliation ساخته نشده است.

## آزمون‌های واقعی

- **۱۰۱ تست مرتبط، ۸۱۲ assertion، خروج۰ و بدون failure/error/skip**؛ JUnit برابر۱۶۳٫۵۸۹۷۷۷ ثانیه.
- **۱۸ تست جدید و۳۷۰ assertion** داخل همان مجموع‌اند، نه افزوده بر آن.
- suiteهای reconciliation evidence، topology reconciliation، observation، acceptance journal، CDE integrity، OrderGap و RLS sargability.
- موارد جدید: match بدون write/mutation/افشای path، حل‌نشدن تفاوت با hash، purposeهای یک‌فایلی، توقف منبع ناقص/binding کهنه پیش از stream، shape/نوع خراب، پروژهٔ دیگر، فایل گم/خراب، tuple اشتباه با بایت یکسان، WIP، نسخهٔ تاریخی دقیق، batch نامشهود، فایل خصوصی شرکت دیگر با batch قابل‌دیدن، drift منبع هنگام stream، ترتیب قطعی و پروژهٔ archive.
- lint تعداد۳۶۴ فایل و ابزارهای مستقل۴۶/۳۳/۲۶ موفق‌اند؛ جدا از PHPUnit.

محیط مصنوعی PostgreSQL17.11 epcc_test با نقش محدود epcc، PHP8.4.26/PHPUnit12.5.35، cache cluster قبلی/socket خصوصی۵۵۴۵ بدون TCP، دوام روشن/WAL۱۲۸/۳۲MB. اجرا سری و بدون suite/bootstrap/seed رقیب بود؛ runtime موجود بازاستفاده شد و وابستگی/source/lockfile قبلی تغییر نکرد. DB پس از پایان متوقف شد. هم‌زمانی دو connection، crash/browser/frontend/CI یا پایلوت تازه ادعا نمی‌شود؛ callback تست drift، اثبات concurrency مستقل نیست. commit/push/PR یا migration/استقرار مشتری انجام نشد.

شواهد: `docs/evidence/2026-10-19-reconciliation-evidence/`. اجرای اولیه۱۵موردی موفق بود؛ اجرای توسعه‌یافته به نقص قبلی شماره‌گذاری برخورد کرد که در ادامه آمده است. log/XML شکست نگه داشته شد. fixture نهایی برای جداسازی این diagnostic از allocator، از namespace مصنوعی استفاده می‌کند؛ هیچ RLS، constraint یا سیاست شماره‌گذاری برنامه تغییر نکرد.

## نقص قبلی مشاهده‌شده و پیشنهاد مستقل — اصلاح نشده

`DocumentRegister::nextSequence` از **MAX قابل‌دیدن زیر RLS** استفاده می‌کند، ولی شمارهٔ سند در **کل پروژه** یکتا است. در fixture خصوصی، ثبت داخلی Sahand سند خصوصی Pars با شمارهٔ PH-14-CIVIL-DWG-0001 را نمی‌دید، همان شماره را انتخاب کرد و constraint با SQLSTATE23505 آن را رد کرد. این شاهد فراخوانی داخلی service است؛ نه اثبات مسیر UI مجاز یا race هم‌زمان مستقل. شواهد: `private-numbering-collision.log/xml`؛ محل کد: nextSequence/nextNumber/register.

**پیشنهاد:** allocator تراکنشی مجزا با کلید project/discipline/type، تخصیص اتمیک، سیاست writer بررسی‌شده و دسترسی غیر‌افشاگر به counter، همراه migration/backfill شماره‌های فعلی؛ uniqueness، rollback و archive حفظ شوند. تست‌های مستقل سند خصوصی/چند شرکت، موازی، rollback، replay و archive لازم‌اند. مجازبودن gap شماره‌ها باید از الزامات قبلی بررسی شود، نه حدس زده شود. افشای اسناد خصوصی، خاموش‌کردن RLS یا retry همان MAX پنهان راه‌حل نیست.

این صرفاً پیشنهاد است؛ schema/role تازه تصویب یا این نقص اصلاح نشده است. موفقیت regression محدود فعلی، نقص مستقل allocator را پنهان نمی‌کند.

## نسخه و قدم بعدی

۴۵۴ hash قبلی ثابت‌اند؛ دو فایل جدید منبع را **۴۵۶فایلی** کرده‌اند. نتیجهٔ کامل قبلی۱۱۵۱/۱۶٬۷۷۶ فقط برای۴۵۴ فایل قبلی است. **اجرای کامل منبع۴۵۶فایلی هنوز انجام نشده و pending است.**

قدم بعدی اجرای کامل همین منبع و پیگیری مستقل پیشنهاد allocator است. authority topology تاریخی، ارتباط/اعتبار شواهد، coverage کامل و admission عملیاتی هنوز احراز نشده‌اند؛ G01 باز، انتخاب مؤثر UNRESOLVED و OrderGap ثابت است.

بستهٔ مستقل فعلی: `epcc-source-recovery-reconciliation-evidence-2026-10-19.tar.gz` با وضعیت pending صریح؛ بازسازی فقط hash/content و ابزارهای۴۶/۳۳/۲۶ را بررسی می‌کند، نه اجرای مجدد برنامه/PG.
