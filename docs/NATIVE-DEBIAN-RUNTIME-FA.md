> محیط تازه۲۰۲۶-۱۰-۱۹: اجرای کامل۱۱۶۹/۱۷٬۱۴۶ روی۴۵۶ منبع؛ data `/home/user/.cache/epcc-reconciliation-full-pg-rbik7nwk` و socket `/tmp/epcc-reconciliation-full-sock-lq78n0a7`؛ metadata در `/home/user/.cache/epcc-app-journal-cluster.json`،۵۵۴۵ خصوصی/بدون TCP، WAL۱۲۸/۳۲ و دوام روشن؛ پس از پایان متوقف شد. runtime excluded ممکن است پاک شود؛ مسیر قدیمی تکرار نشود. مرجع G01-RECONCILIATION-EVIDENCE-FULL-SUITE-FA.md.

# محیط توسعهٔ بومی Debian 13

تأییدشده در این workspace در 2026-10-18. این راهنما روش توسعه است، نه installer تولید یا مشتری.

دستورهای کامل نصب و راه‌اندازی در نسخهٔ انگلیسی همراه قرار دارند: [NATIVE-DEBIAN-RUNTIME-EN.md](NATIVE-DEBIAN-RUNTIME-EN.md).

## پیش‌نیاز و اجزا

دسترسی sudo و شبکه به مخازن رسمی Debian، Composer و npm لازم است. در این نشست PHP 8.4.26 با افزونه‌های PostgreSQL/XML/intl و دیگر افزونه‌های Laravel، Composer 2.8.8، PostgreSQL 17، Chromium، poppler و Vazirmatn نصب شدند. محدودیت شبکهٔ نشست‌های قبلی به معنی ناممکن‌بودن دائمی نصب نیست.

دو دیتابیس مستقل epcc و epcc_test با نقش epcc ساخته می‌شوند. این نقش نباید superuser یا BYPASSRLS داشته باشد. افزونهٔ btree_gist را مدیر دیتابیس آماده می‌کند. رمز نمونهٔ epcc_dev فقط برای محیط نمایشی محلی است؛ پورت دیتابیس روی loopback می‌ماند.

## راه‌اندازی برنامه

داخل app، Composer بر اساس composer.lock نصب می‌شود. env فقط در صورت نبودن از example کپی و مقادیر PostgreSQL جایگزین SQLite می‌شوند. DB_DATABASE برنامه epcc است؛ PHPUnit طبق پیکربندی epcc_test را انتخاب می‌کند. در این محیط session/cache از فایل و queue به‌صورت sync است. کلید برنامه و env نباید commit شوند.

پس از تولید کلید، package discovery و filament:assets، migration و seed روی دیتابیس خالی اجرا می‌شوند. migrate:fresh مخرب است و فقط روی دیتابیس نمایشی دورریختنی مجاز است. seed را بدون reset مرتباً روی دیتابیس موجود اجرا نکنید.

برای frontend از Node 22/npm 11 استفاده شد؛ npm میزبان با Node 20 به خطای resolver رسید. app در مبنای فعلی lockfile npm ثبت‌شده ندارد؛ lockfile ریشه فقط به prototype مربوط است. build موفق به معنی locked بودن dependencyهای frontend برنامه نیست.

## جداسازی تست‌ها

- تست‌های دیتابیس سریالی اجرا شوند؛ همه از epcc_test استفاده و migrate:fresh اجرا می‌کنند.
- DB_DATABASE=epcc را در محیط shell export نکنید؛ config cache قدیمی را پاک کنید.
- runner ریشه بعد از نصب PHP/vendor کل تست‌های Laravel را هم اجرا می‌کند؛ آن را هم‌زمان با PHPUnit اجرا نکنید.
- bootstrap تست‌ها فایل‌های نمایشی مدارک را پاک می‌کند؛ از دیتابیس یا فایل مشتری استفاده نکنید.
- زمانی که تست دیتابیس فعال است، استانداردها و prototype را با دستورهای مستقل نسخهٔ EN بررسی کنید.

## نمایش و ایمنی

سرور واقعی با `php artisan serve --host=0.0.0.0 --port=8000 --no-reload` اجرا می‌شود. برای iframe این preview، SESSION_URL_FALLBACK در env نادیده‌گرفته‌شده فعال است و کد میزبان preview را هم کنترل می‌کند. توکن session حتی امضاشده نیز محرمانه است؛ در استقرار مشتری fallback باید خاموش و trusted proxyها محدود شوند. این سرور و حساب‌های مصنوعی، سرویس production نیستند.

بسته‌های سیستم، سرویس‌ها، vendor/node_modules و env ممکن است بعد از restore محیط باقی نمانند. قبل از اجرای مجدد، محیط را بازسازی و نقش دیتابیس را بررسی کنید. این روش هنوز Docker/compose، بازیابی backup یا عملیات production را تأیید نکرده است.


## حفظ فایل‌های منبع در sandbox

برای جلوگیری از ورود هزاران فایل تولیدشده به snapshot، در clone تازه بدون app/vendor، مسیر `.cache/vendor` ساخته و app/vendor به `../.cache/vendor` لینک می‌شود؛ سپس Composer در app اجرا می‌شود. دستورهای دقیق در نسخهٔ EN آمده‌اند. vendor موجود را بدون بررسی overwrite نکنید. bootstrap تست از قبل APP_BASE_PATH را مشخص کرده است. پس از restore محیط، مقصد ignored و dependencyها باید دوباره ساخته شوند. بستهٔ بازیابی کد این نشست در `/home/user/epcc-recovery/` جایگزین backup دیتابیس یا installer نیست.


## cluster مجزای استفاده‌شده در تأیید نهایی

اجرای سبز نهایی از PostgreSQL17 روی loopback پورت۵۴۳۳ و دیتابیس دورریختنی epcc_test استفاده کرد؛ دیتابیس نمایشی۵۴۳۲ جدا ماند. داده در /tmp روی tmpfs بود و durability/backup تولید را تأیید نمی‌کند. fsync، synchronous_commit و full_page_writes روشن بودند؛ فقط WAL به۱۲۸/۳۲MB محدود شد.

دستورهای ساخت cluster موقت با کاربر غیر root، SCRAM برای TCP، socket محلی مدیر و اجرای foreground تحت نظارت در نسخهٔ EN آمده‌اند. نقش epcc همچنان بدون superuser/BYPASSRLS/CREATEDB است. در تست فقط DB_PORT=5433 و SESSION_URL_FALLBACK=false override می‌شوند؛ DB_DATABASE برنامه نباید به تست export شود. پس از آزمون سرور مجزا متوقف شود؛ پس از reset آن را موجود فرض نکنید. cluster معمولی توسعه با پورت واقعی خود و همین نقش نیز قابل استفاده است.
