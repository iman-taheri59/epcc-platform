> **بازبینی جاری / تصمیم کاربر — ۲۰۲۶-۱۰-۲۰:** [شکاف‌ها و اولویت‌ها](CORE-GAP-REVIEW-2026-10-20-FA.md) / [English](CORE-GAP-REVIEW-2026-10-20-EN.md). طراحی و اجرای اقلام ساخت‌یافتهٔ پیش‌نیاز صریحاً معوق شد؛ متن آزاد محفوظ است. بازبینی مستندات/سورس انجام شد، نه اجرای تست یا تصویب تغییر اختیار/schema. پیشنهاد بعدی: بررسی متمرکز تخصیص شمارهٔ سند، سپس تشخیص ردیابی/محاسبهٔ انبار؛ قابلیت کاربری هدف بعدی دریافت دقیق ردیف PO پس از تصمیم‌های لازم. وضعیت‌های قدیمیِ متعارض و ادعای «کل سورس جاری سبز» در متن زیر، شاهد تاریخی‌اند؛ مرجع وضعیت این بازبینی است.

> **ادامهٔ مصوب PDF — ۲۰۲۶-۱۰-۲۰:** [پیوست جاری پیش‌نیازها](INSPECTION-PREREQUISITE-PDF-FA.md) / [English](INSPECTION-PREREQUISITE-PDF-EN.md). انتخاب prerequisite_pdf اجرا شد: همهٔ شروط/سابقهٔ کامل و مرحلهٔ محفوظ، جدا از متن صدور؛ همان scope، بدون schema/اختیار/نوشتن تازه. کنترل۴۰۹ تغییر حین render.۲۰/۱۱۰۲ + چهار مورد جدا۴/۶۰ موفق؛ نه full-suite/CI/استقرار. فقط تعویق PDF پیش‌نیاز و عبارت‌های قدیمی مخالف زیر جایگزین می‌شوند؛ بقیه تصمیم‌ها محفوظ‌اند.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [فیلتر وضعیت اصل اعلان در پیش‌نیازها](INSPECTION-PREREQUISITE-ACTIVE-NOTICE-FILTER-FA.md) / [English](INSPECTION-PREREQUISITE-ACTIVE-NOTICE-FILTER-EN.md). active_notice_filter مصوب: همه پیش‌فرض، باطل‌نشده/باطل‌شده؛ مستقل از وضعیت شرط و بدون نوشتن/اختیار/schema/PDF. گزینهٔ شرکت و scope تعدادها محفوظ؛۳۶/۲۰۰۸ موفق. هفت hash قبلی برابر، بازیابی لازم نشد؛ vendor در cache مستثنا باقی است. نه full-suite/CI/مرورگر/استقرار/commit/push؛ DB خصوصی پس از بررسی خاموش.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [فهرست پیگیری پیش‌نیازها](INSPECTION-PREREQUISITE-REGISTER-FA.md) / [English](INSPECTION-PREREQUISITE-REGISTER-EN.md). tracking_register مصوب: فیلتر وضعیت/هویت شرکت اعلام‌کننده/شمارهٔ اعلان،۲۵ ردیف و تعداد فقط قابل‌رؤیت، برچسب اصل باطل، بدون نوشتن/اختیار/schema/PDF تازه. بخش مرحلهٔ موروثیِ باقی‌نمانده در workspace بازیابی و آزموده شد؛ گزارش/آرشیو سورس در شواهد.۴۷/۱۰۵۱ نهایی + اعلان قدیمی جدا۱/۱۲ موفق؛ اجراهای قبلی هم‌پوشان‌اند. نه full-suite/CI/مرورگر/استقرار/commit/push؛ DB خصوصی پس از بررسی خاموش.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [پیش‌نیاز گیرندگان](INSPECTION-NOTIFICATION-PREREQUISITES-FA.md) / [English](INSPECTION-NOTIFICATION-PREREQUISITES-EN.md)، [ADR-045](ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-FA.md). any_reader_company_scope / report_and_recipient_confirm / all_notice_readers مصوب اجرا شد. چند شرط متنی مستقل، اصلاح/پس‌گرفتن با دلیل، گزارش مجوزدار جاری فرستنده، تأیید/رد گیرنده و سابقهٔ ثابت. نه gate خودکار، پذیرش فنی یا اختیار منبع/موجودی؛ اصل و PDF محفوظ.۸۱/۱۲۹۰ + ارتقای جدا۱/۵ موفق؛ نه full-suite/CI/استقرار/commit/push؛ DB خصوصی پس از بررسی خاموش. موارد قبلی زیر این یادداشت تاریخی‌اند.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [دریافت شرکت‌ها](INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-FA.md) / [English](INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md)، [ADR-044](ADR-044-INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-FA.md). انتخاب صریح any_authorized_company_reader / issue_and_cancellation_separate / all_authorized_notice_readers / detail_and_current_pdf اجرا شد. اولین دریافت ثابت هر شرکت/رویداد؛ اصل پس از ابطال دریافت نمی‌شود؛ دید شرکت/شخص/زمان در scope اصل و PDF جاری، کنترل۴۰۹ تغییر حین render. نه tracker/ارسال/امضا/تصویب یا اختیار سند/MTO/MR/PO/موجودی.۶۱/۱۰۴۰ + ارتقای جدا۱/۵ موفق؛ PDF نهایی۱/۲۲ هم‌پوشان. بدون full-suite/CI جاری/استقرار/commit/push؛ DB خصوصی خاموش شد.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [ابطال مستقل صادرشده](INSPECTION-NOTIFICATION-CANCELLATION-FA.md) / [English](INSPECTION-NOTIFICATION-CANCELLATION-EN.md)، [ADR-043](ADR-043-INSPECTION-NOTIFICATION-CANCELLATION-FA.md). انتخاب صریح current_issue_grant/cancel_only/status_reason_scoped_replacement اجرا شد؛ رویداد append-only جدا، اصل/شماره/نسخه/snapshot ثابت و وضعیت مؤثر CANCELLED؛ دید دلیل/شخص/زمان در همان scope، بدون افشای تاریخچهٔ خصوصی. PDF جاری و کنترل۴۰۹ ابطال حین render. نهایی۵۹/۸۸۴ + ارتقای جدا۱/۵ موفق؛ PDF۱/۲۲ هم‌پوشان. فقط تعویق ابطال جایگزین؛ جایگزینی/undo/تأیید دریافت معوق. نه موفقیت۹۰موردی/full-suite/تولید/CI؛ بدون commit/push/deploy. DB خصوصی خاموش شد.

> **آخرین ادامه — ۲۰۲۶-۱۰-۲۰:** [UI اختیاری زمان فیلتر](INSPECTION-NOTIFICATION-FILTER-TIME-UI-FA.md) / [English](INSPECTION-NOTIFICATION-FILTER-TIME-UI-EN.md)، انتخاب `filter_usability` اجرا شد. ۲۶ آزمون/۴۰۴ assertion و۷ گروه مرورگر/۱۴۷ بررسی موفق؛ جدا و غیرقابل‌جمع. helper ناقص ارسال را مسدود، کاملاً خالی مرز را حذف می‌کند؛ بدون offset حدسی/اختیار/schema تازه. بررسی Chromium روی صفحات مصنوعیِ جاری، نه production/live-auth/full-suite؛ DB خصوصی خاموش شد.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [جست‌وجو/فیلتر نوتیفیکیشن](INSPECTION-NOTIFICATION-REGISTER-FILTERS-FA.md) / [English](INSPECTION-NOTIFICATION-REGISTER-FILTERS-EN.md). انتخاب `register_filters`: literal شماره/مرجع فعلی، وضعیت، هم‌پوشانی شاملِ offsetدار، آمار/صفحهٔ مجاز و حفظ زبان/context. زمان تکی قدیمی بازه فرض نمی‌شود؛ بدون جست‌وجوی تاریخچه، اختیار/schema/endpoint/نوشتن تازه. **۵۸ آزمون /۶۴۰ assertion موفق، بدون skip**؛ نه full-suite/تولید/CI/کارایی. آمار قبلی تاریخی؛ ADR-042/MTO و محرمانگی سابقه ثابت‌اند.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [تاریخچهٔ اصلاح پیش‌نویس نوتیفیکیشن](INSPECTION-NOTIFICATION-DRAFT-HISTORY-FA.md) / [English](INSPECTION-NOTIFICATION-DRAFT-HISTORY-EN.md). انتخاب `draft_history`: قبل/بعد نسخه‌دار + شخص/نام/زمان/مجوز، ثبت اتمیک DB و append-only/FORCE RLS؛ فقط شرکت ثبت‌کننده، نه سابقهٔ گیرنده/PDF. صفحه‌بندی۲۵ردیفی، حفظ ذخیرهٔ یکسان، بدون backfill قدیمی. migration120000؛ **۸۷ آزمون /۱۱۳۹ assertion موفق، بدون skip**، نه full-suite/تولید/CI. آمار قبلی تاریخی؛ اختیارها و مرز ADR-042/MTO ثابت، ابطال/تغییر زمان صادرشده معوق.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [بهبود فرم تاریخ/ساعت نوتیفیکیشن](INSPECTION-NOTIFICATION-TIME-UI-FA.md) / [English](INSPECTION-NOTIFICATION-TIME-UI-EN.md). انتخاب `date_time_ui`: کنترل تاریخ/ساعت + offset صریح، مقدار دقیق/ورود مستقیم/بدون JS و نمایش خوانا؛ بدون حدس منطقهٔ زمانی یا تغییر API/schema/اختیار.۵۳/۷۴۰ آزمون مرتبط و زیرمجموعهٔ نهایی۴/۸۱ موفق؛ مرورگر۱۸ سناریو/۱۴۹ بررسی موفق، غیرقابل‌جمع. نه full-suite/تولید/CI/E2E/همهٔ مرورگرها؛ سابقه و تصمیم replace_time/ADR-042/MTO ثابت‌اند.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [بازهٔ فعالیت در نوتیفیکیشن](INSPECTION-NOTIFICATION-ACTIVITY-WINDOW-FA.md) / [English](INSPECTION-NOTIFICATION-ACTIVITY-WINDOW-EN.md). انتخاب `replace_time`: شروع/پایان الزامی جای وقت تکی، حفظ offset و کنترل زمان مطلق؛ فرم/فهرست/جزئیات/PDF دو‌زبانه. صادرشدهٔ قدیمی بدون بازنویسی و با برچسب تک‌زمانی؛ پیش‌نویس قدیمی پیش از صدور نیازمند تبدیل صریح. migration110000؛ **۷۱ آزمون /۸۰۲ assertion موفق، بدون skip**، نه full-suite/تولید/CI جاری؛ آمار/قرارداد قبلی تاریخی‌اند. اختیار/مرز ADR-042/MTO ثابت؛ تغییر زمان صادرشده اضافه نشد.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [ویرایش محدود پیش‌نویس نوتیفیکیشن](INSPECTION-NOTIFICATION-DRAFT-EDIT-FA.md) / [English](INSPECTION-NOTIFICATION-DRAFT-EDIT-EN.md). انتخاب `draft_only` اجرا شد؛ همان مجوز مستقل ثبت/شرکت، کنترل نسخه و مرجع ثابت؛ آخرین ویرایشگر/زمان ثبت می‌شود، نه تاریخچهٔ کامل. صادرشده/PDF تغییرناپذیر؛ ابطال/تغییر زمان صادرشده معوق. **۵۸ آزمون /۶۳۱ assertion موفق، بدون skip**؛ نه full-suite/تولید/CI جاری. محدودیت create-only و شمارش‌های قبلی تاریخی‌اند؛ مرز ADR-042/MTO ثابت است.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [PDF نوتیفیکیشن صادرشده](INSPECTION-NOTIFICATION-WORKFLOW-FA.md) / [English](INSPECTION-NOTIFICATION-WORKFLOW-EN.md): فارسی/انگلیسی از سابقهٔ صدور، همان دسترسی و کنترل پس از تولید؛ بدون تغییر رکورد/امضا/ارسال/حق خواندن مرجع. متادیتای پروژه فقط در صدور جدید، بدون backfill قدیمی. **۴۲/۵۰۱ +۲۱/۸۵ موفق؛۶۳ آزمون /۵۸۶ assertion در دو فرمان مستقل، بدون skip**؛ نه full-suite/تولید/CI جاری. متن‌های قبلیِ تعویق PDF و آمار۴۶/۲۵۰ تاریخی‌اند؛ پنج انتخاب ADR-042 و مرز MTO ثابت‌اند.

> **تحویل جاری — ۲۰۲۶-۱۰-۱۹:** [نوتیفیکیشن عملیاتی مراحل ITP](INSPECTION-NOTIFICATION-WORKFLOW-FA.md) / [English](INSPECTION-NOTIFICATION-WORKFLOW-EN.md). تعیین صریح پیمانکار اصلی، واگذاری مجوز ثبت/صدور توسط مدیر مجاز، مراحل نسخهٔ سند، پیش‌نویس و صدور اتمیک/snapshot و inbox هدفمند ساخته شدند. **دو اجرای نهایی۴۶ آزمون /۲۵۰ assertion موفق**؛ نه اجرای کامل. نوتیفیکیشن، نتیجه/امضا/تحویل خارجی/usable نیست. وضعیت «فقط قرارداد ورودی» قبلی تاریخی شد؛ تصمیم‌های پنج‌گانهٔ ADR-042، مرز نتیجهٔ ADR-041 و MTO محفوظ‌اند. تولید/CI جاری ادعا نمی‌شود.

> **تحویل جاری — ۲۰۲۶-۱۰-۱۹:** [ثبت مستقل بازرسی](INSPECTION-ENTRY-FA.md) / [English](INSPECTION-ENTRY-EN.md)، [ADR-041](ADR-041-INDEPENDENT-INSPECTION-ENTRY-FA.md). **۴۶ آزمون مرتبط /۵۵۸ assertion موفق**،۱۴ مورد جدید. تنظیم نسخه‌دار/تعلیق توسط مالک، فرم ایجاد روی PO قابل‌مشاهدهٔ اصلی، انتساب سرور و سابقهٔ تازهٔ غیرقابل‌ویرایش. قاعدهٔ شرکت مستقل TPI/PMC/MC/مالک، یافتهٔ non-PASS و شمارهٔ IRN قبلی محفوظ است؛ نقش پیش‌فرض/ارثی، تفویض دید PO یا تغییر منبع/لات/مالی/رسید/usable/MTO نداریم. migration190000 فقط خصوصی آزموده شد؛ اجرای کامل/تولید ادعا نمی‌شود. اعلان‌ها و آمار قدیمی زیر تاریخی و غیرقابل‌جمع‌اند.

> **تحویل جاری — ۲۰۲۶-۱۰-۱۹:** [صفحه‌بندی مستقل بازرسی](INSPECTION-REGISTER-PAGINATION-FA.md) / [English](INSPECTION-REGISTER-PAGINATION-EN.md). **۴۰ آزمون / ۵۵۶ assertion موفق**؛ چهار مورد جدید. صفحه‌های۲۵تایی، پیمایش مستقل سه فهرست/کل واقعی، قید پروژه و RLS قبلی کیفیتِ مشارکت‌کنندگان، حذف eager load بلااستفادهٔ PO؛ بازرسی مشورتی باقی ماند. فقط تعویق قبلی صفحه‌بندی بازرسی بسته شد؛ بدون نقش/schema/endpoint/نوشتن یا ادعای اجرای کامل/تولید. شواهد/وضعیت‌های پایین تاریخی‌اند.

> **تحویل جاری — ۲۰۲۶-۱۰-۱۹:** [مبلغ دقیق و صفحه‌بندی مستقل فهرست](REGISTER-PAGINATION-MONEY-FA.md) / [English](REGISTER-PAGINATION-MONEY-EN.md). **۴۱ آزمون / ۴۹۹ assertion موفق**؛ هشت مورد جدید. مبلغ رشته‌ای دقیق و null≠صفر، صفحه‌های مستقل۲۵تایی، کل نتایج/دیرکرد فیلترشده، ترتیب ثابت و حفظ فیلتر/RLS اصلی. دو پیشنهاد قبلی انجام شدند؛ ایندکس/تأیید عملکرد بار و صفحه‌بندی بازرسی‌ها هنوز انجام نشده‌اند. بدون endpoint/schema/نقش/اقدام جدید یا ادعای اجرای کامل/تولید. شواهد قبلی محفوظ و وضعیت‌های پایین تاریخی‌اند.

> **قابلیت جاری — ۲۰۲۶-۱۰-۱۹:** [جست‌وجو و وضعیت مستقل فهرست MR/PO](PROCUREMENT-REGISTER-FILTERS-FA.md) / [English](PROCUREMENT-REGISTER-FILTERS-EN.md). **۳۳ آزمون / ۳۱۰ assertion موفق**؛ هشت مورد جدید. جست‌وجوی سربرگ با پارامتر bindشده، اشتراک دسته/وضعیت، حفظ RLS فراخوان، شمارش نتایج سرور و بازرسی بدون تغییر. بدون نقش/schema/endpoint/اقدام جدید یا ادعای اجرای کامل/تولید. صفحه‌بندی/ایندکس و formatter قدیمی float/بدون اعشارِ مبلغ، پیشنهاد بعدی‌اند. شواهد قبلی محفوظ و وضعیت‌های پایین تاریخی‌اند.

> **اصلاح جاری — ۲۰۲۶-۱۰-۱۹:** [بازیابی ایمن فرم قیمت](PO-PRICING-FORM-RECOVERY-FA.md) / [English](PO-PRICING-FORM-RECOVERY-EN.md). **۴۲ آزمون / ۴۶۵ assertion موفق**؛ ۹ مورد جدید. بازیابی فقط با هش جاری PO و تمام UUIDهای فعال؛ خالی≠صفر، ورودی قدیمی/نامعتبر منتقل نمی‌شود و کنترل نوشتن/اختیار/منبع حفظ شده است. بدون نقش/schema/endpoint جدید یا ادعای اجرای کامل/تولید. شواهد قبلی محفوظ و وضعیت‌های پایین تاریخی‌اند.

> **قابلیت فقط‌خواندنی جاری — ۲۰۲۶-۱۰-۱۹:** [مقایسهٔ تاریخچهٔ قیمت](PO-PRICE-HISTORY-COMPARISON-FA.md) / [English](PO-PRICE-HISTORY-COMPARISON-EN.md). **۳۳ آزمون / ۳۵۹ assertion موفق**؛ هشت مورد جدید. مبلغ رشته‌ای دقیق، تطبیق UUID، قیمت/ارز/جمع قبل‌وبعد، تفکیک صفر و نامشخص، حفظ snapshot خام و نمایش۲۰ سابقه. RLS خریدار/پروژه حفظ شده؛ بدون اختیار/نقش/schema/تأیید جدید یا ادعای اجرای کامل/تولید. شواهد قبلی محفوظ و وضعیت‌های پایین تاریخی‌اند.

> **گردش‌کار جاری — ۲۰۲۶-۱۰-۱۹:** [پیش‌نویس PO از جزئیات MR](MR-TO-PO-CONTEXT-ACTION-FA.md) / [English](MR-TO-PO-CONTEXT-ACTION-EN.md). **۴۳ آزمون / ۳۴۰ assertion موفق**؛ پنج مورد جدید. نویسندهٔ مجاز مستقلِ همان خریدار از POST موجود کپی کامل استفاده می‌کند؛ منبع/مجوز/آرشیو دوباره کنترل می‌شوند. GET بدون تغییر است و اقدام اختیاری عمداً PO پیش‌نویس می‌سازد؛ نه رزرو/مصرف/صدور. بدون endpoint/نقش/schema یا ادعای اجرای کامل/تولید. شواهد قبلی حفظ و وضعیت‌های قبلی پایین تاریخی‌اند.

> **قابلیت جاری — ۲۰۲۶-۱۰-۱۹:** [جزئیات فقط‌خواندنی MR](MATERIAL-REQUISITION-DETAILS-FA.md) / [English](MATERIAL-REQUISITION-DETAILS-EN.md). **۳۰ آزمون / ۲۴۰ assertion موفق**؛ ده مورد جدید. اقلام دقیق/نمایش اولیهٔ مقدار، اطلاعات ثبت‌شدهٔ تأیید/رد و سفارش مرتبط با صفحه‌بندی، زیر RLS اصلی و عضویت/رشته/plan جاری. بدون بازکردن دفتر، جمع واحد ناهمگون، ماندهٔ رسمی، نقش/schema/درگاه نوشتن جدید. شواهد قبلی دست‌نخورده‌اند؛ ادعای اجرای کامل/تولید نیست. وضعیت‌های قبلی پایین تاریخی‌اند.

> **بررسی هدفمند جاری — ۲۰۲۶-۱۰-۱۹:** [رقابت rollback و تعلیق نامزدی](PO-ROLLBACK-REVOCATION-FA.md) / [English](PO-ROLLBACK-REVOCATION-EN.md). **۳۸ آزمون / ۳۲۸ assertion موفق**؛ شامل چهار سناریوی جدید دو اتصال. کد اجرایی، اختیار، گردش‌کار و schema تغییر نکرده‌اند. شواهد محدود به timeout و retry در تراکنش تازه‌اند، نه تأیید جامع crash/لغو اختیار. شواهد قبلی دست‌نخورده و اعداد غیرتجمعی‌اند؛ وضعیت‌های قدیمی پایین تاریخی هستند.

> **اصلاح مرز جاری — ۲۰۲۶-۱۰-۱۹:** [حفاظت منبع در OrderGap انسانی](ORDER-GAP-HUMAN-BOUNDARY-FA.md) / [English](ORDER-GAP-HUMAN-BOUNDARY-EN.md). مسیر تأییدشدهٔ تغییر مقدار کپی MR از HTTP بسته شد؛ انتساب/اصلاحیهٔ انسانی بر نشانگر تازهٔ قفل‌شده کنترل و فرم حفاظت‌شده پنهان می‌شود. زنجیرهٔ داخلی/قدیمی و قیمت‌گذاری مجاز حفظ شدند. **۷۷ آزمون / ۷۶۴ assertion موفق**؛ شش مورد جدید. بدون نقش/سیاست MTO/مهاجرت تازه یا ادعای اجرای کامل/تولید. بازطراحی اختیار قدیمی/دستی معوق است؛ وضعیت‌های قبلی پایین تاریخی‌اند.

> **اصلاح امنیتی جاری — ۲۰۲۶-۱۰-۱۹:** [جداسازی محافظ صدور از دید دفتر](PO-GUARD-ISOLATION-FA.md) / [English](PO-GUARD-ISOLATION-EN.md). نقص تأییدشدهٔ تغییر خط هنگام پنهان‌بودن دفتر با جست‌وجوی داخلیِ فقط‌تریگر و محدود به والد قابل‌مشاهده اصلاح شد؛ زمینه بازگردانده و RLS عادی دفتر حفظ شد. **۷۱ آزمون / ۶۵۸ assertion موفق**؛ شش مورد جدید شامل ارتقای پایگاه آرشیوشده. مهاجرت روبه‌جلوی تابعی180000؛ بدون بازنویسی تاریخچه، نقش/اختیار MTO یا اجرای تولید/مجموعهٔ کامل. وضعیت‌های قدیمی پایین تاریخی‌اند.

> **گام جاری — ۲۰۲۶-۱۰-۱۹:** [هماهنگی قفل‌های OrderGap](ORDER-GAP-LOCKING-FA.md) / [English](ORDER-GAP-LOCKING-EN.md). **۶۵ آزمون مرتبط / ۵۵۲ assertion موفق**؛ شش مورد جدید شامل دو رقابت واقعی صدور/اصلاحیه. ترتیب قفل پروژه→سفارش→خط و کنترل وضعیت تازه؛ قواعد قدیمی و انجماد محتوای صادرشده حفظ شدند. اختیار جدید MTO، تعیین تکلیف نوشتنی، آزادسازی ظرفیت، اجرای کامل یا استقرار تولید ایجاد/ادعا نشده است. پیشنهادهای ادامه صرفاً طراحی/بررسی‌اند. وضعیت‌های قدیمی پایین، تاریخی هستند.

# نقشهٔ تکمیل دیسیپلین پایپینگ — EPCC Platform

تاریخ: **2026-10-05**

## 1. اصل تصمیم

همهٔ دیسیپلین‌های لازم پروژه‌های EPCC باید در مدل محصول دیده شوند و هیچ تصمیمی نباید سیستم را «فقط پایپینگ» کند. با این حال، برای ساخت محصول قابل‌فروش و قابل‌اتکا، **دیسیپلین پایپینگ به‌عنوان اولین دیسیپلین عمیق و کامل** انتخاب می‌شود.

منطق انتخاب پایپینگ:

- پایپینگ بیشترین اتصال را به Engineering، Procurement، Construction، QA/QC و Commissioning دارد.
- داده‌های آن قابل سنجش‌اند: line، component، port، joint، weld، NDT، test pack، dia-inch، material traceability.
- خطاهای آن هزینهٔ میدانی بالا دارند: mismatch ریتینگ فلنج، missing material، weld/NDT repair، hydrotest delay.
- اگر الگوی پایپینگ درست ساخته شود، برای Electrical، I&C، Civil و Mechanical قابل تعمیم است.

---

## 2. قاعدهٔ «همهٔ دیسیپلین‌ها دیده شوند، پایپینگ اول کامل شود»

### 2.1 چه چیزی برای همهٔ دیسیپلین‌ها مشترک می‌ماند

این قابلیت‌ها باید discipline-aware باقی بمانند و نباید به پایپینگ hard-code شوند:

- CDE / Document register
- Transmittal و review cycle
- Project setup و discipline registry
- Facility / area / subsystem breakdown
- Procurement/materials و MTO/MRR/warehouse/site delivery
- Punch list و completion certificates
- Authorization/RLS و visibility
- Reporting/PDF/export
- Units و reference data framework

### 2.2 چه چیزی فعلاً پایپینگ-خاص کامل می‌شود

- Piping class / PMS rules
- Branch table
- Equipment / nozzles as piping terminations
- Fit-up capture
- Supports and spools
- Welding and NDT
- Test packs / hydrotest
- PCF import
- Dia-inch progress
- Piping roll-ups into subsystem readiness

---

## 3. وضعیت فعلی پایپینگ

طبق `HANDOFF.md` و کد فعلی:

| بخش | وضعیت |
|---|---|
| Reference packs | ساخته‌شده: sizes, schedules, end types, component templates, units |
| Piping lines | ساخته‌شده: register، numbering، AFC gate |
| Numbering editor | ساخته‌شده |
| Evidence attachment | ساخته‌شده |
| Unit preferences | ساخته‌شده |
| Components / ports | ساخته‌شده |
| Joints / compatibility / dia-inch | ساخته‌شده |
| PMS rules | طراحی‌شده در ADR-008، ساخته نشده |
| Equipment / nozzles | طراحی‌شده در ADR-010، ساخته نشده |
| Fit-up | طراحی‌شده در ADR-006، ساخته نشده |
| Supports/spools | طراحی‌شده، ساخته نشده |
| Welding/NDT | نیازمند ADR/طراحی تکمیلی |
| Test packs / hydrotest | در SRS هست، ساخته نشده |
| PCF import | طراحی‌شده در ADR-005/006، ساخته نشده |
| Incremental roll-ups | توصیه شده، ساخته نشده |

---

## 4. تعریف «پایپینگ کامل» برای MVP

پایپینگ زمانی برای MVP کامل حساب می‌شود که این journey عملیاتی end-to-end کار کند:

1. ایجاد/ورود line list
2. تنظیم PMS / piping class
3. ساخت مدل line با component/port/joint
4. اتصال line به equipment nozzle یا endpoint معتبر
5. اعتبارسنجی compatibility و PMS deviation
6. اتصال MTO/material به procurement و warehouse/site
7. ثبت fit-up و weld/NDT برای joints لازم
8. ساخت test pack / hydrotest package
9. punch و MCC/RFSU readiness بر اساس وضعیت واقعی line/material/weld/test
10. گزارش progress بر اساس joint/dia-inch/test pack به‌صورت incremental، نه live aggregate سنگین
11. خروجی PDF/Excel/turnover evidence برای line/test pack/weld log

---

## 5. فازهای پیشنهادی تکمیل پایپینگ

### فاز P0 — محیط اجرای کامل و baseline تست

**پیش‌نیاز همهٔ فازها.** چون PMS و RLS و migrationهای پایپینگ PostgreSQL-heavy هستند، بدون محیط PHP/PostgreSQL نباید شروع شوند.

خروجی:

- اجرای کامل PHPUnit روی PostgreSQL
- seed/demo project پایپینگ
- smoke برای صفحات فعلی piping
- snapshot وضعیت فعلی قبل از تغییر

### فاز P1 — PMS و branch table

> **وضعیت ۲۰۲۶-۱۰-۱۱: پیاده‌سازی شده — در انتظار تأیید اجرایی.** هر شش
> خروجی زیر در مایگریشن `2026_10_11_120000_create_piping_classes_and_rules`،
> `PipingClassService` و `PipingClassTest` پیاده شد (ADR-008 §9). اتصال به
> component attach و سازگاری جوش‌ها فاز ۳ ساختِ ADR-008 است و هنوز باز است.

بر اساس ADR-008:

- `piping_classes`
- `piping_class_rules` با DN half-open ranges
- `EXCLUDE USING gist`
- gap detection در publish gate
- branch table با specificity-wins
- integration با component attach و joint compatibility
- **الحاقیه (کارفرما ۲۰۲۶-۱۰-۱۱، ‏ADR-008 §9.1):** واژگان نتیجهٔ جدول انشعاب
  بسته شد — `TEE / WELDOLET / SOCKOLET / P2P`؛ ‏`P2P` (لوله‌به‌لوله) نتیجهٔ
  درجه‌یک به معنای *بدون کامپوننت* است، با دو نوع **stub-in / stub-on** و پد
  تقویتی که در صورت نیاز، ضخامت آن (میلی‌متر) اجباری است.

ارزش: جلوگیری از ورود دادهٔ غلط قبل از field.

### فاز P2 — Equipment / nozzles

بر اساس ADR-010:

- equipment register حداقلی
- equipment_nozzles
- nozzle ports
- line endpoint upgrade از free text به FK
- nozzle schedule report
- compatibility بین nozzle و component port

تصمیم‌های لازم قبل از شروع:

1. tag uniqueness: per project یا per facility؟ پیشنهاد: per facility.
2. instrument register الان لازم است یا in-line instruments به‌عنوان tagged components کافی‌اند؟
3. equipment tag توسط سیستم تولید شود یا وارد/validate شود؟ پیشنهاد: imported/entered + pattern validation.

### فاز P3 — Fit-up و field capture

بر اساس ADR-006 phase 4:

- joint state: MODELLED → FITTED
- `fitted_by_code`
- `fitted_at`
- محدودیت‌های state transition
- ثبت field بدون نیاز به user account برای fitter

ارزش: اولین دادهٔ واقعی سایت روی مدل پایپینگ.

### فاز P4 — Supports و spools

- support register با numbering engine مشابه line
- support documents linkage
- spool grouping اختیاری
- shop/field derivation
- spool status و release

### فاز P5 — Welding / NDT / repair loop

نیازمند طراحی دقیق، ولی حداقل MVP:

- weld log برای joints با policy لازم
- welder code و WPS reference
- NDT request/result
- repair cycle
- repair rate KPI
- heat/material traceability به weld در حد feasible
- **انواع مفصلِ جوش انشعاب (نیازمندی کارفرما، ۲۰۲۶-۱۰-۱۲، پیشنهاد P-21):**
  اتصالات لوله‌به‌لوله (stub-in/stub-on) کامپوننت ندارند — اتصال، جوش است.
  رجیستر جوش‌ها باید `BRANCH_STUB_IN` / `BRANCH_STUB_ON` را به‌عنوان نوعِ
  مفصل درجه‌یک داشته باشد تا جوش‌های انشعاب جدا از جوش‌های لب‌به‌لب شمارش و
  گزارش شوند و الزام پد از قواعد کلاسِ ADR-008 قابل ردیابی بماند.

این فاز باید با QA/QC هماهنگ شود و ممکن است ADR جدا لازم داشته باشد.

### فاز P6 — Test pack / hydrotest

- test pack build از line/joint/subsystem
- prerequisites: material, weld/NDT, punch, documents
- hydrotest record
- reinstatement checklist
- certificate/PDF

### فاز P7 — PCF import

بر اساس ADR-005/006:

- upload → preview → commit
- immutable import batch
- no auto-numbering برای imported unnumbered welds
- mapping به components/ports/joints
- deviation report
- rollback/reject before commit

### فاز P8 — Roll-ups و turnover

- incremental progress tables
- subsystem readiness contribution
- dia-inch earned progress
- line/test pack/weld turnover export
- dashboard برای piping lead و commissioning

---

## 6. تست‌های ضروری برای فازهای پایپینگ

هر فاز باید این موارد را داشته باشد:

- RLS positive/negative
- archived project read-only
- فارسی/انگلیسی و RTL/LTR
- HTTP path واقعی، نه فقط service test
- migration fresh روی PostgreSQL
- tabletools برای registerها
- PDF magic byte و access control اگر خروجی دارد
- mutation/probe برای rules حیاتی مثل PMS overlap، branch specificity، dia-inch min diameter

---

## 7. اثر روی دیسیپلین‌های دیگر

الگوی پایپینگ باید generic seam تولید کند، نه کد بسته:

| الگوی پایپینگ | تعمیم آینده |
|---|---|
| line/component/port/joint | cable/drum/pull/termination برای Electrical |
| equipment/nozzle endpoint | instrument tap/nozzle/connection برای I&C |
| PMS validation | cable spec / instrument spec / material spec validation |
| test pack | loop folder / energization package / concrete pour dossier |
| weld/NDT traceability | cable test / calibration / concrete cube tests |
| dia-inch progress | discipline-specific earned quantity |

---

## 8. پیشنهادهای طراحی نیازمندی

1. **پایپینگ را عمیق کنید، نه اینکه همزمان همهٔ دیسیپلین‌ها را سطحی بسازید.**
2. **هر پایپینگ feature باید یک generic pattern پشت خود باقی بگذارد** تا دیسیپلین بعدی سریع‌تر ساخته شود.
3. **PMS قبل از PCF import بیاید**؛ import بدون validator فقط دادهٔ غلط را سریع‌تر وارد می‌کند.
4. **Equipment/nozzles قبل از welding/NDT بهتر است** چون endpoint compatibility خطاهای پرهزینه را زود می‌گیرد.
5. **Roll-upها live aggregate نباشند**؛ ADR-006 benchmark نشان داده RLS در aggregate سنگین گران است.
6. **Welding/NDT احتمالاً ADR مستقل می‌خواهد** چون خودش چند workflow و KPI حساس دارد.

---

## 9. تصمیم محصولی ثبت‌شده

- همهٔ دیسیپلین‌های EPCC در scope محصول باقی می‌مانند.
- اولویت اجرای عمیق از اینجا به بعد: **تکمیل دیسیپلین پایپینگ**.
- فازهای عمومی مثل environment، RLS safety و documentation alignment همچنان پیش‌نیازند.
