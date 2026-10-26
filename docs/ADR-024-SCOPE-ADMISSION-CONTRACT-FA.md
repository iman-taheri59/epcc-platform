> **وضعیت ۲۰۲۶-۱۰-۲۵:** [نظر مستقلِ غیرتأییدی با اتصال دقیق متن+ساختار](SCOPE-STRUCTURE-REVIEWS-FA.md) اکنون در سرویس داخلی پیاده است؛ فقط COMMENTED/CHANGES_REQUESTED. نبود این writer محدود در متن زیر تاریخی است. بازبینیِ متصل به شاهد/تأیید مهندسی/admission فعال نیست؛ UI متن و پرچم‌های آمادگی ثابت‌اند.

> **برش ساختار ۲۰۲۶-۱۰-۲۴:** [ادعای جداگانهٔ تأییدنشدهٔ توپولوژی/پوشش](SCOPE-STRUCTURE-DECLARATIONS-FA.md) اکنون سرویس داخلی دارد. نبود اظهارنامهٔ ساختاری در متن قبلی تاریخی است؛ صفحه/خوانندهٔ متن تغییر نکرده و ساختار را نمایش/بازبینی نمی‌کند. شاهد/بازبینی مثبت/admission فعال نشده است.

> **اصلاح وضعیت اختیار/ذخیره‌سازی —۲۰۲۶-۱۰-۲۴:** SA-01/02 صریحاً مصوب‌اند ([انتخاب نهایی](SCOPE-ADMISSION-AUTHORITY-FA.md))؛ معرفی/رابط محدود و ذخیرهٔ متن متصل به منبع ساخته شده‌اند ([راهنمای جدید](SCOPE-PROPOSAL-ENVELOPES-FA.md)). تعویق کلی سیاست عامل‌ها/نبود جدول پیشنهاد در متن قدیمی، فقط در همین محدوده جایگزین شده است. خود قرارداد فنی ADR همچنان پیش‌نویس است، نه تصویب کلی payload/شاهد/پذیرش. اختیار قبلی پذیرش snapshot MTO تغییر نکرده؛ writer پذیرش/تأیید مثبت نداریم.

> **وضعیت جاری —۲۰۲۶-۱۰-۱۹:** [اجرای کامل موفق](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-FA.md) / [EN](G01-RECONCILIATION-EVIDENCE-FULL-SUITE-EN.md):۱۱۶۹ تست/۱۷٬۱۴۶ assertion، صفر failure/error/skip، خروج۰؛ hash۴۵۶ منبع و۲ worker ثابت. gate همین منبع بسته شد؛ متن‌های pending/next-full پایین تاریخی‌اند. **نقص شماره‌گذاری MAX زیر RLS اصلاح نشده** و پیشنهاد مستقل آن در گزارش است. G01 باز؛ UNADMITTED/UNRESOLVED، gross=null، بدون تغییر role/route/migration/OrderGap. بازیابی فعلی: epcc-source-recovery-reconciliation-evidence-full-2026-10-19.tar.gz؛ DB مستقل موقت پس از پایان متوقف شد.

# ADR-024 — پیش‌نویس قرارداد admission و coverage، نسخهٔ ۱٫۱

تاریخ: ۲۰۲۶-۱۰-۱۸

**این قرارداد پیشنهادی است، نه سیاست اختیار مصوب، migration یا endpoint تأیید.** نسخهٔ EN جزئیات کامل shape و محدودیت‌ها را دارد.

منبع برنامه تغییر نکرده است: هش هر ۴۵۰ فایل با اجرای کامل موفقِ ۱۱۱۲ تست و ۱۶٬۴۸۸ assertion برابر است. این مرحله فقط prototype مستقل، تست‌های آن و مستندات/شواهد را اضافه کرده است. ۴۶ مورد فعلی را نباید به شمار PHPUnit اضافه کرد.


**تفکیک وضعیت اجرا:** مطالب۴۵۰ hash/نتیجهٔ۱۱۱۲/۱۶٬۴۸۸ و عدم تغییر برنامه در متن، مربوط به مرحلهٔ اصلی prototype آفلاین‌اند. diagnosticهای کم‌سطح source/comparison/بایت اکنون وجود دارند و ترکیب فقط‌خواندنی آن‌ها در G01-RECONCILIATION-EVIDENCE-FA.md گزارش شده؛ هیچ‌کدام fulfillment سرتاسری gateهای admission یا adapter کامل proposal/coverage نیستند. شکل envelope نسخهٔ۱٫۱ و اختیار/lifecycle پذیرفته‌شده تغییر نکرده‌اند.

## تفکیک ضروری

۱. observation فعلی، مشاهدهٔ تغییرناپذیر دیتابیس است؛ نه اثبات topology تاریخی یا پوشش کامل.
۲. proposal، graph و coverage صریحی پیشنهاد می‌دهد که به observation، batch و pin ادعاشده متصل است؛ می‌توان ساختار آن را بررسی کرد، بدون آنکه تأییدش کنیم.
۳. admission معتبر به احراز منبع، شواهد واقعی، اختیار صادرکننده/تصمیم‌گیرنده و قواعد اصلاح/ابطال نیاز دارد؛ این‌ها هنوز پیاده نشده‌اند.
۴. حتی admission نیز به‌تنهایی مجوز جمع‌کردن revisionها یا scopeهای هم‌پوشان نیست؛ قواعد انتخاب و additivity مستقل می‌مانند.

خروجی prototype حتی برای ورودی کامل:

- `status = STRUCTURALLY_REVIEWABLE_PROPOSAL`
- `ready_to_admit = false`
- `admission_state = UNADMITTED`
- `ready = false` و `gross = null`
- `writes_performed = 0`

## قرارداد قابل‌آزمایش

فایل‌ها:

- `prototype/domain/MtoScopeAdmissionProposal.php`
- `prototype/test/mto-scope-admission-proposal.php`

اجرا: `php prototype/test/mto-scope-admission-proposal.php`

بدون bootstrap برنامه، Composer یا اتصال دیتابیس اجرا می‌شود. شناسه‌های تست صرفاً برچسب مصنوعی هستند. این ابزار HTTP parser، اعتبارسنج UUID/quantity، کنترل اندازهٔ ورودی یا adapter احرازشدهٔ دیتابیس نیست.

### اتصال به منبع

proposal باید دقیقاً schema_version عددی ۱، source_binding، nodes، coverage، topology_evidence و coverage_evidence داشته باشد. field ناشناخته، ازجمله ادعای دستی ADMITTED، رد می‌شود.

binding شامل project_id، batch_id، observation_id، payload_hash، source_rows_hash، hierarchy_revision_id و header_scope_id است. همه با export عرضه‌شده تطبیق دقیق دارند؛ header می‌تواند صریحاً null باشد، اما pin مفقود از graph امروز حدس زده نمی‌شود. hash باید ۶۴ رقم hex کوچک باشد.

**هیچ‌کدام از دو ورودی احراز نشده‌اند.** این validator هش JSONB دیتابیس را بازتولید یا صحت export را اثبات نمی‌کند؛ یک جفت ورودی جعلی اما سازگار می‌تواند از نظر ساختاری قابل‌بررسی باشد، ولی هرگز admitted نیست.

### topology و coverage

- یک ریشهٔ PROJECT صریح با شناسهٔ پروژه؛ nodeهای یکتا و هم‌پروژه، والد موجود و بدون چرخه.
- روابط مجاز: PROJECT→FACILITY، FACILITY→AREA، FACILITY→SUBSYSTEM و AREA→SUBSYSTEM. نبود area با اتصال مستقیم نمایش داده می‌شود، نه ساختن area جعلی.
- این graph می‌تواند قطعه‌ای شامل اجداد scopeهای موردنظر باشد؛ موجودی کامل پروژه یا topology تاریخی معتبر را اثبات نمی‌کند.
- coverage مستقل عرضه می‌شود، نه از روی materialهای موجود. هر row مشاهده‌شده دقیقاً یک بار و زیر scope خودش پوشش داده می‌شود.
- scope پوشش باید leaf صریح SUBSYSTEM باشد؛ والد به‌طور خودکار به فرزندان گسترش نمی‌یابد.
- row گمشده/تکراری/ناشناخته، scope خالی یا pin متفاوت رد می‌شود.
- header غیرخالی باید خود leaf یا یکی از اجداد صریح آن در proposal هم‌نسخه باشد. ancestry اثبات‌نشده مسدود می‌ماند و scope هم‌ردیف descendant نیست. این اصلاح محدودیت قبلی را با ADR-018 §۹٫۴ منطبق می‌کند و FK/API عملیاتی را تغییر نمی‌دهد.
- scope بدون row به‌عنوان صفر مصوب، retirement یا carry-forward تفسیر نمی‌شود. این محدودیت محافظه‌کارانه است، نه تصمیم تجاری جدید.
- نبود scopeای که هیچ row آن را آشکار نمی‌کند، از روی این داده‌ها قابل‌تشخیص نیست؛ **کامل‌بودن واقعی coverage به مرجع مستقل نیاز دارد**.

[مقایسه‌گر فقط‌خواندنی برنامه](G01-TOPOLOGY-RECONCILIATION-FA.md) اکنون تفاوت graph پیشنهادی با observation منجمد را گزارش می‌کند؛ تعیین تکلیف آن با شواهد تاریخی و اتصال envelope کامل/معتبر admission هنوز انجام نشده است. graph امروز حقیقت تاریخی فرض و observation بازنویسی نمی‌شود.

### اظهار شواهد کنترل‌شده

هر دو evidence به record_id، issuer، number، revision، تاریخ معتبر YYYY-MM-DD، retained_uri و file_sha256 نیاز دارند. hash تنها کافی نیست. این صرفاً بررسی شکل اظهارنامه بر مبنای فیلدهای controlled record است؛ فایل، هویت صادرکننده، hash محتوای واقعی، ماندگاری، تغییرناپذیری URI یا ابطال بررسی نمی‌شوند. adapter معتبر CDE هنوز ساخته نشده و یک URI دلخواه نباید به‌عنوان revision تأییدشدهٔ CDE معرفی شود.

## هشت مانع پیش از admission واقعی

۱. export احرازشدهٔ منبع، visibility درست، tuple دقیق و تشخیص drift.
۲. تطبیق فایل/محتوا/issuer/hash و retention شواهد کنترل‌شده.
۳. reconciliation صریح observation با topology پیشنهادی.
۴. مرجع معتبر topology تاریخی و هویت‌های نسخه‌دار.
۵. مرجع پوشش کامل مستقل از rowها و تعیین تکلیف scopeهای خالی/حذف‌شده/تغییریافته.
۶. **سیاست اختیار مصوب** برای تصمیم‌گیرنده، tenant/project و delegation؛ هیچ نقش یا grant تازه‌ای با این draft ایجاد نمی‌شود و اختیار MTO همچنان دست‌نخورده است.
۷. اجرای چرخهٔ پذیرفته‌شدهٔ ADR-018 §۹٫۲ برای additive/mapping: رکورد superseding جدید، عدم ویرایش/حذف، حفظ وضعیت زمان محاسبه برای تاریخچه و latest-effective برای گزارش جاری؛ withdrawal ابهام ایجاد می‌کند. برای aggregate جدید topology/coverage، target رویدادها، وابستگی و اجرای تراکنشی باید دقیق شوند؛ اصل چرخه کاملاً تصمیم‌گیری‌نشده نیست.
۸. بازاعتبارسنجی تراکنشی، نسخهٔ policy/evidence، guard ضد replay/stale/branch، RLS/archive و rollback اتمیک؛ نتیجهٔ offline نباید قابلیت نوشتن محسوب شود.

پیشنهاد lifecycle آینده: submission با admission فرق دارد؛ admission فقط پس از تصمیم دارای اختیار ممکن است؛ correction/revocation رویداد مستقل و تغییرناپذیر خواهد بود. این مرحله هیچ جدول، event یا permission عملیاتی ایجاد نمی‌کند.

## نتیجهٔ آزمون و ادامه

**۴۶ تست مستقل موفق** برای binding دقیق، schema سخت‌گیرانه، graph، area اختیاری، coverage، header، pin، کنترل اظهار شواهد و تاریخ واقعی، ورودی خراب و حالت خالی. همهٔ موارد، عدم تغییر ورودی، نبود نوشتن و عدم admission را نیز بررسی می‌کنند. ابزارهای قبلی selection و planner با ۳۳ و ۲۶ مورد دوباره موفق شدند؛ ۴۵۰ هش منبع برنامه ثابت ماندند.

شواهد: `docs/evidence/2026-10-18-admission-contract/`.

اجرای کامل برنامه بی‌دلیل تکرار نشد، چون منبع برنامه تغییر نکرده است. نتیجهٔ قبلی معتبر است، اما اعتبار persistence یا authority آینده را ثابت نمی‌کند. G01 باز است و OrderGap/board/API بدون تغییرند.

پیش از writer واقعی باید اختیار مشخص انسانی با رعایت تعویق موجود، adapter احراز مدارک topology/coverage و وابستگی/target رویدادهای admission دقیق شوند. قواعد پذیرفته‌شدهٔ شواهد، mapping، withdrawal و تاریخچه دوباره سؤال نمی‌شوند؛ سپس schema/API و تست‌های واقعی هم‌زمانی/RLS/archive/replay بررسی شوند. هیچ migration مشتری، commit، push یا PR انجام نشد.

## اصلاح مستند در ۲۰۲۶-۱۰-۱۸

[فهرست مرجع تصمیم‌ها](DECISION-REFERENCE-INDEX-FA.md) دامنهٔ پذیرفته‌شده و باز را تفکیک می‌کند. پیش‌نویس قبلی دامنهٔ تصمیم‌گیری‌نشده را بیش از حد گسترده توصیف کرده بود؛ ADR-018 §§۹٫۲–۹٫۶ قبلاً شواهد، اصلاح/withdrawal، header/descendant، split/merge و snapshot تاریخی را تعیین کرده است. این اصلاح اختیار تازه‌ای تصویب نمی‌کند. هشت تست ancestry اضافه شد؛ مجموع مستقل۴۶ است و شواهد۳۸ مورد قبلی تاریخی‌اند. هر۴۵۰ هش برنامه ثابت است؛ تست proposal مصنوعی parent/project قابلیت جدید در برنامه نیست. کار فنیِ versioning، احراز شواهد، provenance، بررسی فقط‌خواندنی و تست بدون نقش/gate جدید قابل ادامه است.
