# G01 — نتیجهٔ آزمون persistence روی PostgreSQL واقعی

> Full journal-source gate NOW GREEN1098/16429,zero failures/errors/skips: `G01-JOURNAL-FULL-SUITE-VERIFICATION-FA.md`. Earlier pending notices below are historical. Effective scope selection and the integrated pilot remain open.


> Next implementation update: real NEW acceptance chronology is now connected (always UNRESOLVED). See `G01-REAL-ACCEPTANCE-JOURNAL-FA.md`:89 related tests/240 assertions +5 app-service contention scenarios; FULL SUITE ON CHANGED SOURCE PENDING. No effective board switch or pilot completion.


تاریخ: 2026-10-18. **۳۶ آزمون روی PostgreSQL17.11 مجزا موفق شدند. این یک نمونهٔ دیتابیسی محدود است، نه migration لاراول یا رفع G01 در گزارش اصلی.**

جزئیات قیود، محدودیت‌ها و دستورهای بازتولید در [نسخهٔ انگلیسی](G01-POSTGRES-PERSISTENCE-PROOF-EN.md) است.

## چه چیزی ساخته و اجرا شد؟

- `prototype/persistence/schema.sql`: schema اجرایی با FKهای مرکب هم‌پروژه، شمارندهٔ پذیرش، دفتر انتخاب immutable، FORCE RLS، قید آرشیو و کنترل کامل‌بودن تراکنش.
- `prototype/persistence/test_postgres.py`: آزمون‌های واقعی با connectionهای مستقل و کنترل SQLSTATE، rollback و هم‌زمانی.
- شواهد: `docs/evidence/2026-10-18-g01-postgres/` شامل log کامل، نتایج ماشینی و جهش‌های عمدی.

نقش writer واقعاً بدون superuser/BYPASSRLS/CREATEDB/CREATEROLE متصل شد. RLS روی هر شش جدول مصنوعی فعال و اجباری بود؛ فیلترشدن مالک غیر bypass نیز بررسی شد.

## نتایج مهم ۳۶ آزمون

- source، manifest تک‌scope و رویداد انتخاب با هم commit شدند؛ قید deferred دیتابیس، batch ناقص را **در COMMIT** رد کرد.
- بازنگری جدید head جدید ساخت و تاریخچه را دست‌نخورده نگه داشت.
- ریشهٔ تکراری، انشعاب، پرش گام، predecessor نامعتبر/ناسازگار، چرخه و منبع جدید با ordinal قدیمی رد شدند.
- خطا پس از درج، replay بازنگری یا head منقضی هیچ clock/source/selection نیمه‌کاره باقی نگذاشت.
- FK مرکب، اتصال منبع پروژهٔ دیگر را حتی با connection مدیر رد کرد؛ این آزمون مستقل از پنهان‌سازی RLS بود.
- context مفقود/شرکت یا پروژهٔ بیگانه دسترسی به دادهٔ هدف نداد. RLS restrictive حتی پس از اعطای موقت UPDATE/DELETE، تغییر تاریخچه را متوقف کرد؛ trigger هم UPDATE/DELETE مدیر را رد کرد.
- پروژهٔ آرشیوشده خواندنی ماند اما پذیرش و تغییر مستقیم clock را نپذیرفت؛ writer اختیار تغییر پرچم آرشیو نداشت.

### هم‌زمانی واقعاً اجرا شد

در هر سناریو، دو connection هم‌زمان استفاده شد. قبل از آزادکردن تراکنش اول، با **pg_blocking_pids** تأیید شد که تراکنش دوم واقعاً پشت آن منتظر است:

1. دو پذیرش عادی هر دو به‌ترتیب commit شدند؛ گام‌های۱ و۲، با یک head.
2. پذیرش اول rollback شد؛ پذیرش منتظر بدون orphan، ریشهٔ درست ساخت.
3. نویسندهٔ منتظر با expected head قدیمی، با SQLSTATE40001 بازنده شد.
4. آرشیو ابتدا قفل پروژه را گرفت؛ پذیرش منتظر پس از commit آرشیو رد شد.
5. پذیرش ابتدا قفل را گرفت؛ پیش از آرشیو منتظر commit شد و پذیرش‌های بعدی رد شدند.

این نتایج آزمون کارایی، نبود تمام deadlockهای ممکن یا crash recovery نیستند.

## کنترل کیفیت تست‌ها

سه تغییر خراب‌کننده در کپی‌های موقت schema ایجاد شد:

- حذف قفل پروژه؛
- حذف قید کامل‌بودن batch در commit؛
- حذف الزام پیشروی ترتیب منبع.

تست‌ها هر سه را تشخیص دادند. سپس schema اصلی دوباره اجرا شد و **هر ۳۶ آزمون موفق شدند**. جهش‌های عمداً ناموفق، باگ گزارش‌شده در برنامهٔ اصلی نیستند.

## مرزهای صریح این نتیجه

- جداول project/member/batch مصنوعی و کوچک‌اند؛ schema واقعی اسناد، بازنگری، accepted rows، policyهای کامل برنامه یا workflow انسانی نیستند.
- نمونه فقط **یک scope در هر batch** را پوشش می‌دهد. seal چندscope، topology تاریخی، تغییر/حذف scope و جمع gross کامل هنوز به adapter واقعی نیاز دارند.
- مسیر NEW آزمایش شده؛ پذیرش شواهد، legacy reconciliation، grant/revoke جمع‌پذیری و اختیار resolver ساخته نشده‌اند. ابزار backfill قبلی همچنان فقط نامزد بررسی می‌دهد.
- writer برای آزمون منفی، INSERT و تغییر clock دارد؛ این مدل، دسترسی عمومی SQL/HTTP یا طراحی نهایی capability نیست. contextهای tenant را harness مورد اعتماد تنظیم می‌کند؛ جلوگیری از جعل context توسط کاربر انسانی اثبات نشده است.
- trigger جلوی UPDATE/DELETE را می‌گیرد، نه مدیری که عمداً trigger را خاموش یا schema را تغییر می‌دهد؛ کنترل دسترسی مدیر و backup جداست.
- به دیتابیس برنامه وصل نشدیم؛ migration عملیاتی، تست لاراول، استقرار، CI، backup/restore یا آزمون دوام پس از crash انجام نشد.
- OrderGapService، لینک‌های net، مجوز MTO، usable و معنای RECEIVED تغییر نکردند؛ ADR-032 همچنان تصویب‌نشده است.

## محیط و بازتولید

cluster دورریختنی تازه در `/tmp/epcc-journal-pg-*` با socket خصوصی `/tmp/epcc-journal-sock-*` ساخته شد؛ PostgreSQL هیچ listener TCP نداشت. احراز هویت socket از نوع trust فقط برای همین محیط خصوصی آزمایشی بود، نه الگوی امنیت تولید.

**fsync، synchronous_commit و full_page_writes روشن ماندند**؛ WAL به۱۲۸/۳۲MB محدود بود. ابزارهای PostgreSQL و psycopg پس از reset نصب شدند. دادهٔ cluster و وابستگی‌ها وارد بستهٔ بازیابی نمی‌شوند.

دستورهای کامل ساخت cluster با کاربر غیر root و اجرای runner در نسخهٔ EN آمده‌اند. هشدار مهم: runner دیتابیس اختصاصی `epcc_journal_proof` را حذف و بازسازی می‌کند؛ فقط در cluster دورریختنی اجرا شود. guardها تأیید صریح reset، prefix مسیرهای موقت و نبود TCP را بررسی می‌کنند. ابزار، env برنامه را نمی‌خواند.

## وضعیت و گام بعد

**این گام بسته شد:** اثبات اجراییِ محدودِ قیود مرکب، FORCE RLS، تاریخچهٔ immutable، آرشیو، commit/rollback و رقابت واقعی connectionها.

**هنوز باز:** migration و adapter واقعی برنامه، manifest چندscope و interfaceهای topology/شواهد/legacy، اختیار انسانی، تست‌های policy واقعی پروژه و رابط unresolved. گام بعد، اتصال محدود journal پذیرش جدید به snapshotهای واقعی با قرارداد fail-closed است؛ تا آماده‌شدن این مرزها، گزارش اصلی فعال نشود.

hash۴۴۳ فایل مبنای قبلی همچنان یکسان است. نتیجهٔ۱۰۸۳/۱۶٬۳۸۵ مربوط به اجرای کامل قبلی برنامه است؛ این۳۶ آزمون مستقل به آن اضافه نمی‌شوند. هیچ commit/push/PR، migration تولید یا استقرار مشتری انجام نشده است.
