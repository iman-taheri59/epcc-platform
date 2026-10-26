# G01 — اتصال ترتیب پذیرش جدید به snapshotهای واقعی Laravel

> FULL GATE NOW CLOSED: subsequent complete run on this SAME446-file source passed1098 tests/16429 assertions,0 failures/errors/skips. Current evidence: `G01-JOURNAL-FULL-SUITE-VERIFICATION-FA.md`. The related-stage pending statements below are historical; no implementation changed for the full run.


تاریخ: 2026-10-18. **پیاده‌سازی محلی و تست‌های مرتبط موفق؛ اجرای کامل روی کد تغییریافته هنوز در انتظار است.**

این مرحله ثبت منشأ و ترتیب پذیرش جدید است؛ **نه انتخاب MTO مؤثر، نه backfill تاریخی و نه رفع گزارش OrderGapService**. جزئیات فنی و بازتولید در [نسخهٔ انگلیسی](G01-REAL-ACCEPTANCE-JOURNAL-EN.md) است.

## تغییر واقعی برنامه

- migration جدید، شمارندهٔ پروژه و جدول immutable به نام `mto_acceptance_journal` را به schema واقعی اضافه می‌کند.
- درج هر batch پذیرفته‌شدهٔ جدید، در همان تراکنش، رکوردی با شناسه‌های دقیق پروژه/شرکت/batch/مدرک/بازنگری و ordinal افزایشی پروژه ایجاد می‌کند.
- سرویس پذیرش اکنون منبع واقعی را پیدا می‌کند و به‌ترتیب **پروژه → مدرک → بازنگری** قفل می‌گیرد. پروژهٔ غیرفعال را رد می‌کند و به project_id روی مدل قدیمیِ ورودی اعتماد نمی‌کند.
- مدل فقط‌خواندنی `MtoAcceptanceJournal` و رابطهٔ `acceptanceJournal()` برای batch اضافه شدند. endpoint، grant یا نقش انسانی تازه‌ای ایجاد نشده است.
- تمام رکوردها به **origin=NEW و selection_state=UNRESOLVED** محدودند. scope/hierarchy مفقود همچنان مفقود است؛ ثبت ترتیب، تأیید scope یا انتخاب مبنای gross نیست.

## تضمین‌ها و محدودیت‌ها

FK مرکب، ارجاع journal را به همان tuple دقیق snapshot مقید می‌کند. برای ردیف‌های جدید snapshot هم FK مرکب اضافه شد؛ اما قید تاریخی عمداً **NOT VALID** است: روی درج‌های جدید اجرا می‌شود، بدون اینکه داده‌های قدیمی را به‌اشتباه اعتبارسنجی‌شده اعلام کند. inventory و VALIDATE تاریخی هنوز لازم است.

clock فقط یک گام جلو می‌رود؛ timestamp برابر، ordinal برابر تولید نمی‌کند. تخصیصِ commit‌نشده پس از rollback می‌تواند دوباره استفاده شود؛ ترتیب commit‌شده یکتا و immutable است. برای batchهای قدیمی هیچ ordinal ساخته نمی‌شود؛ تست expansion روی دادهٔ پذیرفته‌شدهٔ قبلی این موضوع را بررسی کرد.

قید deferred هنگام commit، وجود journal، مجموعهٔ غیرخالی ردیف‌های frozen و وضعیت ACCEPTED بازنگری متناظر را بررسی می‌کند. این **seal کامل manifest چندscope یا موتور پذیرش شواهد نیست**؛ سرویس واقعی همچنان مسئول اعتبارسنجی و کپی تمام staging rowهاست.

قید deferred برای بررسی همان منبع درج‌شده، context شرکت/پروژه را موقتاً تنظیم و سپس هر دو را بازمی‌گرداند؛ SECURITY DEFINER یا bypass کردن RLS ندارد. این رفتار زیر context تو‌در‌توی دو شرکت نیز تست شد. شکست، کل تراکنش و تنظیمات محلی را rollback می‌کند.

درج journal مسیر خودکار trigger دارد؛ درج عادی مستقیم رد می‌شود. کنترل عمق trigger **مرز اختیار انسانی یا دفاع در برابر مالک بدخواه schema نیست**. RLS اجباری و triggerهای immutable جلوی تغییر عادی تاریخچه را می‌گیرند؛ مدیریت schema/backup جداست. مجوزهای معوق MTO تغییر نکرده‌اند.

migration **forward-only** است: down عمداً خطا می‌دهد تا منشأ ثبت‌شده حذف نشود. rollback عملیاتی باید با اصلاح رو‌به‌جلو و حفظ تاریخچه طراحی شود، نه migrate:rollback مخرب. هیچ migration روی دیتابیس مشتری اجرا نشده است.

## نتیجهٔ اجرا روی آخرین کد

| بررسی | نتیجه |
|---|---|
| PHPUnit مرتبط | **۸۹ تست، ۲۴۰ assertion، خروج۰** |
| failure / error / skip | **صفر / صفر / صفر** |
| تست جدید journal داخل این اجرا | **۱۵ مورد** |
| رقابت با پردازش‌های واقعی Laravel | **۵ سناریو موفق** |
| manifest منبع این مرحله | **۴۴۶ فایل** |
| اجرای کامل PHPUnit روی کد جدید | **هنوز اجرا نشده / در انتظار** |

مجموعهٔ مرتبط شامل پذیرش و staging متریال، تخمین مهندسی، order gap، آرشیو، RLS و sargability بود. خطاهای اولیهٔ harness هم اصلاح و با نام تاریخی نگهداری شدند: خطای COMMIT از نوع PDOException بود و یک fixture منفی پیش از رسیدن به FK به UNIQUE برخورد می‌کرد. نتیجهٔ نهاییِ گزارش‌شده پس از رفع این دو مورد است.

**نتیجهٔ کامل۱۰۸۳/۱۶٬۳۸۵ قبلی متعلق به منبع پیش از journal است.** از آن manifest، دو فایل برنامه تغییر کرده و سه فایل برنامه/تست/migration اضافه شده‌اند. بنابراین آن نتیجه را نباید موفقیت اجرای کامل کد فعلی دانست یا با۸۹ تست جدید جمع کرد.

### هم‌زمانی با سرویس‌های واقعی

دو worker مستقل، kernel واقعی Laravel را boot می‌کنند و از `MtoAcceptanceService` و `ProjectArchiveService` استفاده می‌کنند؛ دیگر صرفاً SQL مصنوعی نیست. با pg_blocking_pids منتظرماندن واقعی worker دوم تأیید شد:

1. دو بازنگری متفاوت هر دو commit شدند و ordinal مجزا گرفتند.
2. پذیرش اول rollback شد؛ دومی بدون orphan در clock/row/journal commit شد.
3. پذیرش هم‌زمانِ همان بازنگری: یکی commit شد و دومی با اعتبارسنجی موجود رد شد؛ فقط یک پذیرش باقی ماند.
4. آرشیو ابتدا commit شد؛ پذیرش منتظر رد شد و journal تازه‌ای نساخت.
5. پذیرش پیش از آرشیو منتظر commit شد؛ سپس آرشیو کامل شد.

همهٔ journalها UNRESOLVED باقی ماندند. داده‌ها فقط fixture مصنوعی‌اند. harness فایل‌های `scripts/tests/mto_journal_worker.php` و `scripts/tests/test_mto_journal_concurrency.py` است و نباید هم‌زمان با PHPUnit یا روی اطلاعات مشتری اجرا شود.

## محیط

PHP8.4.26، PHPUnit12.5.35، PostgreSQL17.11 و psycopg3.2.6؛ وابستگی‌های Composer از lockfile بازیابی شدند. دیتابیس دورریختنی `epcc_test` در cluster مجزای `/tmp/epcc-app-journal-pg-*` و socket خصوصی `/tmp/epcc-app-journal-sock-*` روی پورت۵۵۴۵ بود؛ listener TCP نداشت. trust محلی فقط برای آزمون بود، نه الگوی تولید.

نقش epcc بدون superuser/BYPASSRLS/CREATEDB/CREATEROLE است؛ fsync، synchronous_commit و full_page_writes روشن ماندند. env، APP_KEY، vendor و دادهٔ cluster در بستهٔ بازیابی نیستند. برنامهٔ نمایشی یا سرور preview شروع نشد.

## گام بعد قطعی

**ابتدا اجرای کامل و سریالی تمام تست‌ها روی همین منبع جدید، بدون تغییر بیشتر کد حین اجرا.** گام موفقیت کامل قبلی اکنون برای منبع قدیمی تاریخی است؛ برای منبع فعلی ادعای سبزبودن کامل نداریم.

پس از آن، interfaceهای manifest/topology معتبر، شواهد و reconciliation تاریخی، انتخاب قطعی MTO مؤثر و نمایش unresolved باید تکمیل شوند. گزارش فعلی همچنان همهٔ accepted rowها را جمع می‌کند؛ این مرحله معنای سفارش RECEIVED، usable، بازرسی یا اختیار MTO را تغییر نداده است. ADR-032 تصویب نشده و پایلوت یکپارچه اجرا نشده است.

شواهد: `docs/evidence/2026-10-18-g01-app/`. هیچ commit، push، PR یا استقرار مشتری انجام نشده است.
