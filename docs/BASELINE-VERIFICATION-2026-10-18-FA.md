# گزارش تثبیت محیط و رفع موانع تست — 2026-10-18

> Historical report; the subsequent complete final-source run is now GREEN (1083 tests /16385 assertions /0 failures/errors/skips). Current evidence: `FINAL-SOURCE-VERIFICATION-2026-10-18-FA.md`. Earlier pending-rerun statements below are historical.


> Historical targeted-baseline report. The subsequent complete run and PDF repair are recorded in `FULL-SUITE-VERIFICATION-2026-10-18-EN.md` (FA companion available); use that report for current runtime status.


مبنای کد: `main` در `0c54c7f84d1e343bf1d58833977ac0834dcfdcc0`.
شاخهٔ محلی: `arena/baseline-verification-2026-10-18`.
نسخهٔ فنی مفصل انگلیسی: [BASELINE-VERIFICATION-2026-10-18-EN.md](BASELINE-VERIFICATION-2026-10-18-EN.md).

## نتیجه

محیط واقعی Laravel/PostgreSQL و نمایش برنامه راه افتاد. دو ایراد بازتولیدپذیر اصلاح شد؛ قواعد تأیید مهندسی، RLS و مجوزها تغییر نکردند. هیچ push یا PR انجام نشد و داده‌ها کاملاً نمایشی هستند.

**این مرحله تثبیت هدفمند است، نه اعلام موفقیت کل مجموعهٔ تست‌ها.** اجرای کامل پس از مشاهدهٔ شکست‌های واقعی برای ریشه‌یابی اولین مورد متوقف شد. اجرای سریالی کامل همچنان گام بعدی است.

## محیط اجرا

PHP 8.4.26، Composer 2.8.8، PostgreSQL 17 و Chromium/poppler/Vazirmatn از بسته‌های رسمی Debian نصب شدند. dependencyهای Composer بدون نادیده‌گرفتن الزامات نصب شدند. migration و seed اولیه موفق بودند. نقش epcc نه superuser است و نه BYPASSRLS دارد؛ دیتابیس برنامه و تست جدا هستند.

build رابط با Node 22/npm 11 موفق شد؛ نصب اولیه با Node 20/npm 10 در resolver خود npm شکست خورده بود. وابستگی‌های برنامه تغییر داده نشدند. نبود lockfile برای npm داخل app یک شکاف بازتولیدپذیری است.

سرور پورت ۸۰۰۰، برنامهٔ واقعی Laravel است، نه prototype. گزینهٔ session در URL فقط برای این preview و میزبان‌های مجاز در env نادیده‌گرفته‌شده فعال است؛ در استقرار مشتری باید خاموش باشد. بسته‌های سیستم و فایل‌های ignored ممکن است پس از بازیابی محیط نیازمند نصب مجدد باشند.

## اصلاح اول: بازسازی مجدد دیتابیس

پس از migration موفق اولیه، اجرای مجدد SeedPathTest با خطای وجود قبلی تابع PostgreSQL شکست می‌خورد. migrate:fresh جدول‌ها را حذف می‌کند اما تابع مستقل trigger را باقی می‌گذارد.

در سه migration تاریخ 2026-10-15، دستور CREATE FUNCTION به CREATE OR REPLACE FUNCTION تبدیل شد؛ بدنهٔ guard و محدودیت‌ها دست‌نخورده‌اند. تست جدید PipeScheduleMigrationRebuildTest دوبار بازسازی را اجرا و باقی‌ماندن RLS اجباری و guardهای پنج جدول را بررسی می‌کند. تست‌های اولیهٔ بازسازی/seed/validator: ۶ تست و ۷۹ assertion موفق.

## اصلاح دوم: UUID مربوط به snapshot PMS

صفحهٔ مدل پایپینگ در تست با خطای ۵۰۰ باز نمی‌شد. Eloquent به‌دلیل تعریف‌نشدن نوع کلید مدل، UUID را به عدد تبدیل می‌کرد و SQL به صورت id IN (0) می‌شد.

در PmsClassRevisionSnapshot نوع کلید string و incrementing=false مشخص شد؛ امکان نوشتن یا تولید خودکار UUID به snapshot تغییرناپذیر اضافه نشده است. تست metadata و تست‌های HTTP موجود این مسیر را بررسی می‌کنند.

یک assertion قدیمی صفحهٔ مدل به HTML دقیقِ قبل از اضافه‌شدن وضعیت مقایسهٔ PMS وابسته بود. به‌جای حذف بررسی، تست اکنون با DOM/XPath مقدار schedule و معنای انطباق را در سلول سطر PIPE کنترل می‌کند. تست مثبت validator نیز اکنون assertion صریح دارد و بی‌اثر/risky نیست.

## شواهد

- validator بسته‌های استاندارد و مرجع: موفق.
- conformance موتور PHP و material screening مستقل: موفق؛ screening دارای ۶۰ assertion.
- پنج مجموعهٔ prototype: موفق.
- build رابط: موفق.
- تست‌های نهایی هدفمند: **۵۶ تست / ۲۷۱ assertion، بدون failure، error یا skip**. شواهد در `evidence/2026-10-18/summary.json` ثبت شده است.
- ورود نمایشی در مرورگر واقعی و چهار صفحه در دو زبان: هشت پاسخ ۲۰۰؛ بدون خطای page-level جاوااسکریپت.
- مدارک فارسی در عرض ۴۳۰ پیکسل: RTL، محتوای غیرخالی و scrollWidth برابر عرض viewport؛ تصویر بررسی شد.

اجرای اولیهٔ تست‌ها به‌اشتباه با runner ریشه تداخل داشت؛ آن خروجی مبنای معتبر نیست. خطای migration سپس در اجرای مستقل بازتولید شد. اجرای کامل سریالی پس از اصلاح migration به شکست واقعی رسید و برای بررسی آن متوقف شد؛ گزارش موفقیت کامل صادر نشده است. تست‌های دیتابیس را نباید هم‌زمان علیه epcc_test اجرا کرد. runner ریشه در صورت وجود PHP/vendor خودش PHPUnit را نیز اجرا می‌کند.

آزمون بار، امنیت جامع، backup/restore، Docker/compose و فرایند کامل مهندسی تأیید نشده‌اند. مشکل پوشانده‌شدن خطای اصلی توسط restore کردن context در تراکنش ناموفق و نبود lockfile برنامه برای بررسی بعدی ثبت شده‌اند، نه اینکه بی‌صدا تغییر کنند.

## گام بعد

اجرای سریالی کل تست‌ها و طبقه‌بندی شکست‌های باقی‌مانده؛ سپس توافق روی پایلوت پایپینگ و تکمیل یک شکاف محدود با ADR. طراحی مجوزهای MTO معوق می‌ماند و ثبت TQ/NCR به معنی صدور، تأیید امضا یا پذیرش مهندسی نیست.

لاگ‌های کامل و تصاویر: `/home/user/epcc-evidence`. شواهد کم‌حجم در `docs/evidence/2026-10-18` قرار دارند.
