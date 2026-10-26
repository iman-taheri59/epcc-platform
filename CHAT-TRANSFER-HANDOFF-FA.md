> **Current 2026-10-23 — custody-amendment increment:** original-preparer/live-custody-grant source/Heat amendments are implemented; exact amended-basis fresh QC and QA are required. [Current guide](docs/PIPING-CUSTODY-AMENDMENTS-FA.md). Earlier correction-as-proposal statements are historical. Controlled GRN/stock/legacy cutover are still unimplemented.
> **وضعیت جاری — اصلاحیهٔ تحویل‌گیری:** اصلاح منبع/Heat فقط توسط تهیه‌کنندهٔ اصلی با معرفی جاری اجرا شده است؛ QC و QA تازه روی همان نسخه لازم است. [راهنمای جاری](docs/PIPING-CUSTODY-AMENDMENTS-FA.md). پیشنهاد قدیمی اصلاحیه اکنون اجرایی است؛ GRN/موجودی و گذار انبار قدیمی هنوز اجرا نشده‌اند.

> **وضعیت جاری ۲۰۲۶-۱۰-۲۳:** تصمیم نهایی QA با شواهد نسخه‌دار انسانی و منع پذیرش شرط باز اجرا شده است؛ [راهنمای جاری](docs/PIPING-QA-FA.md). گزاره‌های قبلی دربارهٔ QA اجرا‌نشده/سیاست باز، تاریخی‌اند. GRN و گذار مسیر انبار قدیمی هنوز اجرا نشده‌اند.
> **Current 2026-10-23:** version-bound manual QA and open-condition blocking are implemented; [current guide](docs/PIPING-QA-FA.md). Earlier unimplemented/pending QA statements are historical. Controlled GRN and legacy warehouse cutover remain unimplemented.

> **QC دریافت اجرا شد — ۲۰۲۶-۱۰-۲۲:** [راهنما](docs/PIPING-RECEIVING-QC-FA.md) / [English](docs/PIPING-RECEIVING-QC-EN.md). مشاهده و نتیجهٔ QC روی تحویل‌گیری تغییرناپذیر با معرفی مستقل جاری و دید اصلی منبع؛ تهیه‌کنندهٔ واقعی MRR حق خودبررسی ندارد.۵۰/۵۷۸ موفق (۱۶ QC +۲۰ تحویل‌گیری +۱۴ بازرسی PO). فقط MRR≠QC اجرایی است؛ نه QA، GRN، آزادسازی موجودی یا چک‌لیست فنی/Accepted with Comment خودکار. ادعاهای قدیمیِ نبود اقدام QC برای همین برش تاریخی‌اند. نه full-suite/CI/مرورگر/تولید/commit/push؛ اقلام ساخت‌یافتهٔ پیش‌نیاز معوق‌اند.

> **تحویل‌گیری فیزیکی / MRR اولیه اجرا شد — ۲۰۲۶-۱۰-۲۲:** [راهنما](docs/PIPING-PHYSICAL-CUSTODY-FA.md) / [English](docs/PIPING-PHYSICAL-CUSTODY-EN.md). مشاهدهٔ معرفی‌شدگان جاری با دید مستقل منبع و ثبت توسط هر شرکت مشارکت‌کنندهٔ صریحاً معرفی‌شده مصوب و اجرا شد. نهایی۲۰/۲۴۲؛ milestone مرتبط۶۰/۴۳۸ و متن تنظیمات۲/۴۳ موفق، هم‌پوشان و غیرقابل‌جمع. ثبت فقط CUSTODY_ONLY؛ نه QC/QA/GRN، موجودی یا مجوز منبع. ادعاهای قدیمیِ نبود تحویل‌گیری/resolver از این برش تاریخی‌اند. تفکیک واقعی سه شخص برای اقدام‌های آینده لازم است. نه full-suite/CI/مرورگر/تولید/commit/push؛ اقلام ساخت‌یافته معوق‌اند.

> **برش اجرایی تنظیم مسئولان — ۲۰۲۶-۱۰-۲۲:** [راهنما](docs/PIPING-RECEIPT-ACCESS-FA.md) / [English](docs/PIPING-RECEIPT-ACCESS-EN.md). صفحهٔ فقط مدیر مجاز شرکت مالک، انتخاب صریح متولی و چهار مجموعهٔ مستقل، نسخه/تعلیق/سابقه و FORCE RLS اجرا شد.۲۸/۲۷۸ موفق؛ بررسی view بعدی۲/۴۳ هم‌پوشان. هنوز دریافت/MRR/QC/پذیرش/GRN و resolver اقدام‌ها پیاده نشده؛ نه مجوز خودکار یا گسترش دید منبع. عبارت‌های قبلیِ «هیچ صفحه/schema ساخته نشده» فقط برای این برش تاریخی‌اند. نه full-suite/CI/مرورگر/استقرار/commit/push؛ اقلام ساخت‌یافته معوق‌اند.

> **ماتریس اصلی دریافت مصوب — ۲۰۲۶-۱۰-۲۲:** [قرارداد برش اول](docs/PIPING-RECEIPT-FIRST-SLICE-CONTRACT-FA.md) / [English](docs/PIPING-RECEIPT-FIRST-SLICE-CONTRACT-EN.md). MRR از کنترلر متریال، بررسی QC، پذیرش QA و GRN انبار؛ مدیر شرکت مالک با project.configure معرفی مستقل کند. MRR/QC/QA سه شخص متفاوت؛ GRN می‌تواند یکی از آن‌ها با اختیار مستقل باشد. این تصمیم‌ها بسته‌اند؛ عبارات قبلیِ انتظار تأیید فقط برای اقدامات پیشرفته معتبرند. هنوز کد/مجوز/فرم دریافت فعال نشده؛ اقلام ساخت‌یافته معوق‌اند.

> **فایل مرجع و تصمیم دریافت — ۲۰۲۶-۱۰-۲۲:** [طراحی دریافت تا انبارش](docs/PIPING-RECEIPT-STORAGE-DESIGN-FA.md) / [English](docs/PIPING-RECEIPT-STORAGE-DESIGN-EN.md)، [ADR-046](docs/ADR-046-PIPING-RECEIPT-STORAGE-FA.md). ورود فیزیکی مستقل و پذیرش کنترل‌شده/منع خروج عادی Hold یا Rejected مصوب شد؛ ماتریس اختیار باید پیش از فعال‌سازی تأیید شود. فقط این مرز جای عبارت کلی منع gate جدید در ADR-032 R4 را می‌گیرد؛ بقیهٔ تصمیم‌های باز مصوب نشده‌اند. کار جاری طراحی مبتنی بر فایل است، نه اجرای پیشنهاد قبلی شماره‌گذاری یا فعال‌کردن دریافت. کد/schema/مجوز تغییر نکرد؛ اقلام ساخت‌یافتهٔ پیش‌نیاز معوق‌اند.

> **بازبینی جاری / تصمیم کاربر — ۲۰۲۶-۱۰-۲۰:** [شکاف‌ها و اولویت‌ها](docs/CORE-GAP-REVIEW-2026-10-20-FA.md) / [English](docs/CORE-GAP-REVIEW-2026-10-20-EN.md). طراحی و اجرای اقلام ساخت‌یافتهٔ پیش‌نیاز صریحاً معوق شد؛ متن آزاد محفوظ است. بازبینی مستندات/سورس انجام شد، نه اجرای تست یا تصویب تغییر اختیار/schema. پیشنهاد بعدی: بررسی متمرکز تخصیص شمارهٔ سند، سپس تشخیص ردیابی/محاسبهٔ انبار؛ قابلیت کاربری هدف بعدی دریافت دقیق ردیف PO پس از تصمیم‌های لازم. وضعیت‌های قدیمیِ متعارض و ادعای «کل سورس جاری سبز» در متن زیر، شاهد تاریخی‌اند؛ مرجع وضعیت این بازبینی است.

> **ادامهٔ مصوب PDF — ۲۰۲۶-۱۰-۲۰:** [پیوست جاری پیش‌نیازها](docs/INSPECTION-PREREQUISITE-PDF-FA.md) / [English](docs/INSPECTION-PREREQUISITE-PDF-EN.md). انتخاب prerequisite_pdf اجرا شد: همهٔ شروط/سابقهٔ کامل و مرحلهٔ محفوظ، جدا از متن صدور؛ همان scope، بدون schema/اختیار/نوشتن تازه. کنترل۴۰۹ تغییر حین render.۲۰/۱۱۰۲ + چهار مورد جدا۴/۶۰ موفق؛ نه full-suite/CI/استقرار. فقط تعویق PDF پیش‌نیاز و عبارت‌های قدیمی مخالف زیر جایگزین می‌شوند؛ بقیه تصمیم‌ها محفوظ‌اند.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [فیلتر وضعیت اصل اعلان در پیش‌نیازها](docs/INSPECTION-PREREQUISITE-ACTIVE-NOTICE-FILTER-FA.md) / [English](docs/INSPECTION-PREREQUISITE-ACTIVE-NOTICE-FILTER-EN.md). active_notice_filter مصوب: همه پیش‌فرض، باطل‌نشده/باطل‌شده؛ مستقل از وضعیت شرط و بدون نوشتن/اختیار/schema/PDF. گزینهٔ شرکت و scope تعدادها محفوظ؛۳۶/۲۰۰۸ موفق. هفت hash قبلی برابر، بازیابی لازم نشد؛ vendor در cache مستثنا باقی است. نه full-suite/CI/مرورگر/استقرار/commit/push؛ DB خصوصی پس از بررسی خاموش.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [انتخابگر خوانای شرکت پیش‌نیاز](docs/INSPECTION-PREREQUISITE-COMPANY-PICKER-FA.md) / [English](docs/INSPECTION-PREREQUISITE-COMPANY-PICKER-EN.md). company_picker مصوب اجرا شد: نام/کد snapshot فقط شرکت‌های اعلام‌کنندهٔ قابل‌رؤیت، جست‌وجوی literal و۲۵ گزینهٔ صفحه‌دار، حفظ انتخاب و صفحه‌های مستقل. بدون directory/دسترسی/schema/نوشتن/PDF تازه.۵۶/۱۷۹۳ موفق؛ نه full-suite/CI/مرورگر/استقرار/commit/push.۱۶ سورس قبلی از آرشیو کنترل‌شده با حفظ تفاوت‌ها بازیابی؛ bundle جدید در شواهد؛ DB خصوصی پس از بررسی خاموش.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [فهرست پیگیری پیش‌نیازها](docs/INSPECTION-PREREQUISITE-REGISTER-FA.md) / [English](docs/INSPECTION-PREREQUISITE-REGISTER-EN.md). tracking_register مصوب: فیلتر وضعیت/هویت شرکت اعلام‌کننده/شمارهٔ اعلان،۲۵ ردیف و تعداد فقط قابل‌رؤیت، برچسب اصل باطل، بدون نوشتن/اختیار/schema/PDF تازه. بخش مرحلهٔ موروثیِ باقی‌نمانده در workspace بازیابی و آزموده شد؛ گزارش/آرشیو سورس در شواهد.۴۷/۱۰۵۱ نهایی + اعلان قدیمی جدا۱/۱۲ موفق؛ اجراهای قبلی هم‌پوشان‌اند. نه full-suite/CI/مرورگر/استقرار/commit/push؛ DB خصوصی پس از بررسی خاموش.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [زمینهٔ دقیق مرحلهٔ ITP برای پیش‌نیاز](docs/INSPECTION-PREREQUISITE-ITP-LINK-REVIEW-FA.md) / [English](docs/INSPECTION-PREREQUISITE-ITP-LINK-REVIEW-EN.md). انتخاب inherited_stage اجرا شد: فقط snapshot محفوظ همان اعلان در فرم/جزئیات/سابقه؛ نه منبع زنده، schema/دسترسی تازه، backfill، آیتم جزئی ساختاریافته یا تغییر PDF.۵۴/۸۰۷ + اعلان قدیمی جدا۱/۱۲ موفق؛ نه full-suite/مرورگر/CI/استقرار/commit/push. DB خصوصی پس از بررسی خاموش شد.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [پیش‌نیاز گیرندگان](docs/INSPECTION-NOTIFICATION-PREREQUISITES-FA.md) / [English](docs/INSPECTION-NOTIFICATION-PREREQUISITES-EN.md)، [ADR-045](docs/ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-FA.md). any_reader_company_scope / report_and_recipient_confirm / all_notice_readers مصوب اجرا شد. چند شرط متنی مستقل، اصلاح/پس‌گرفتن با دلیل، گزارش مجوزدار جاری فرستنده، تأیید/رد گیرنده و سابقهٔ ثابت. نه gate خودکار، پذیرش فنی یا اختیار منبع/موجودی؛ اصل و PDF محفوظ.۸۱/۱۲۹۰ + ارتقای جدا۱/۵ موفق؛ نه full-suite/CI/استقرار/commit/push؛ DB خصوصی پس از بررسی خاموش. موارد قبلی زیر این یادداشت تاریخی‌اند.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [دریافت شرکت‌ها](docs/INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-FA.md) / [English](docs/INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md)، [ADR-044](docs/ADR-044-INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-FA.md). انتخاب صریح any_authorized_company_reader / issue_and_cancellation_separate / all_authorized_notice_readers / detail_and_current_pdf اجرا شد. اولین دریافت ثابت هر شرکت/رویداد؛ اصل پس از ابطال دریافت نمی‌شود؛ دید شرکت/شخص/زمان در scope اصل و PDF جاری، کنترل۴۰۹ تغییر حین render. نه tracker/ارسال/امضا/تصویب یا اختیار سند/MTO/MR/PO/موجودی.۶۱/۱۰۴۰ + ارتقای جدا۱/۵ موفق؛ PDF نهایی۱/۲۲ هم‌پوشان. بدون full-suite/CI جاری/استقرار/commit/push؛ DB خصوصی خاموش شد.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [ابطال مستقل صادرشده](docs/INSPECTION-NOTIFICATION-CANCELLATION-FA.md) / [English](docs/INSPECTION-NOTIFICATION-CANCELLATION-EN.md)، [ADR-043](docs/ADR-043-INSPECTION-NOTIFICATION-CANCELLATION-FA.md). انتخاب صریح current_issue_grant/cancel_only/status_reason_scoped_replacement اجرا شد؛ رویداد append-only جدا، اصل/شماره/نسخه/snapshot ثابت و وضعیت مؤثر CANCELLED؛ دید دلیل/شخص/زمان در همان scope، بدون افشای تاریخچهٔ خصوصی. PDF جاری و کنترل۴۰۹ ابطال حین render. نهایی۵۹/۸۸۴ + ارتقای جدا۱/۵ موفق؛ PDF۱/۲۲ هم‌پوشان. فقط تعویق ابطال جایگزین؛ جایگزینی/undo/تأیید دریافت معوق. نه موفقیت۹۰موردی/full-suite/تولید/CI؛ بدون commit/push/deploy. DB خصوصی خاموش شد.

> **آخرین ادامه — ۲۰۲۶-۱۰-۲۰:** [UI اختیاری زمان فیلتر](docs/INSPECTION-NOTIFICATION-FILTER-TIME-UI-FA.md) / [English](docs/INSPECTION-NOTIFICATION-FILTER-TIME-UI-EN.md)، انتخاب `filter_usability` اجرا شد. ۲۶ آزمون/۴۰۴ assertion و۷ گروه مرورگر/۱۴۷ بررسی موفق؛ جدا و غیرقابل‌جمع. helper ناقص ارسال را مسدود، کاملاً خالی مرز را حذف می‌کند؛ بدون offset حدسی/اختیار/schema تازه. بررسی Chromium روی صفحات مصنوعیِ جاری، نه production/live-auth/full-suite؛ DB خصوصی خاموش شد.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [جست‌وجو/فیلتر نوتیفیکیشن](docs/INSPECTION-NOTIFICATION-REGISTER-FILTERS-FA.md) / [English](docs/INSPECTION-NOTIFICATION-REGISTER-FILTERS-EN.md). انتخاب `register_filters`: literal شماره/مرجع فعلی، وضعیت، هم‌پوشانی شاملِ offsetدار، آمار/صفحهٔ مجاز و حفظ زبان/context. زمان تکی قدیمی بازه فرض نمی‌شود؛ بدون جست‌وجوی تاریخچه، اختیار/schema/endpoint/نوشتن تازه. **۵۸ آزمون /۶۴۰ assertion موفق، بدون skip**؛ نه full-suite/تولید/CI/کارایی. آمار قبلی تاریخی؛ ADR-042/MTO و محرمانگی سابقه ثابت‌اند.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [تاریخچهٔ اصلاح پیش‌نویس نوتیفیکیشن](docs/INSPECTION-NOTIFICATION-DRAFT-HISTORY-FA.md) / [English](docs/INSPECTION-NOTIFICATION-DRAFT-HISTORY-EN.md). انتخاب `draft_history`: قبل/بعد نسخه‌دار + شخص/نام/زمان/مجوز، ثبت اتمیک DB و append-only/FORCE RLS؛ فقط شرکت ثبت‌کننده، نه سابقهٔ گیرنده/PDF. صفحه‌بندی۲۵ردیفی، حفظ ذخیرهٔ یکسان، بدون backfill قدیمی. migration120000؛ **۸۷ آزمون /۱۱۳۹ assertion موفق، بدون skip**، نه full-suite/تولید/CI. آمار قبلی تاریخی؛ اختیارها و مرز ADR-042/MTO ثابت، ابطال/تغییر زمان صادرشده معوق.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [بهبود فرم تاریخ/ساعت نوتیفیکیشن](docs/INSPECTION-NOTIFICATION-TIME-UI-FA.md) / [English](docs/INSPECTION-NOTIFICATION-TIME-UI-EN.md). انتخاب `date_time_ui`: کنترل تاریخ/ساعت + offset صریح، مقدار دقیق/ورود مستقیم/بدون JS و نمایش خوانا؛ بدون حدس منطقهٔ زمانی یا تغییر API/schema/اختیار.۵۳/۷۴۰ آزمون مرتبط و زیرمجموعهٔ نهایی۴/۸۱ موفق؛ مرورگر۱۸ سناریو/۱۴۹ بررسی موفق، غیرقابل‌جمع. نه full-suite/تولید/CI/E2E/همهٔ مرورگرها؛ سابقه و تصمیم replace_time/ADR-042/MTO ثابت‌اند.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [بازهٔ فعالیت در نوتیفیکیشن](docs/INSPECTION-NOTIFICATION-ACTIVITY-WINDOW-FA.md) / [English](docs/INSPECTION-NOTIFICATION-ACTIVITY-WINDOW-EN.md). انتخاب `replace_time`: شروع/پایان الزامی جای وقت تکی، حفظ offset و کنترل زمان مطلق؛ فرم/فهرست/جزئیات/PDF دو‌زبانه. صادرشدهٔ قدیمی بدون بازنویسی و با برچسب تک‌زمانی؛ پیش‌نویس قدیمی پیش از صدور نیازمند تبدیل صریح. migration110000؛ **۷۱ آزمون /۸۰۲ assertion موفق، بدون skip**، نه full-suite/تولید/CI جاری؛ آمار/قرارداد قبلی تاریخی‌اند. اختیار/مرز ADR-042/MTO ثابت؛ تغییر زمان صادرشده اضافه نشد.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [ویرایش محدود پیش‌نویس نوتیفیکیشن](docs/INSPECTION-NOTIFICATION-DRAFT-EDIT-FA.md) / [English](docs/INSPECTION-NOTIFICATION-DRAFT-EDIT-EN.md). انتخاب `draft_only` اجرا شد؛ همان مجوز مستقل ثبت/شرکت، کنترل نسخه و مرجع ثابت؛ آخرین ویرایشگر/زمان ثبت می‌شود، نه تاریخچهٔ کامل. صادرشده/PDF تغییرناپذیر؛ ابطال/تغییر زمان صادرشده معوق. **۵۸ آزمون /۶۳۱ assertion موفق، بدون skip**؛ نه full-suite/تولید/CI جاری. محدودیت create-only و شمارش‌های قبلی تاریخی‌اند؛ مرز ADR-042/MTO ثابت است.

> **تحویل جاری — ۲۰۲۶-۱۰-۲۰:** [PDF نوتیفیکیشن صادرشده](docs/INSPECTION-NOTIFICATION-WORKFLOW-FA.md) / [English](docs/INSPECTION-NOTIFICATION-WORKFLOW-EN.md): فارسی/انگلیسی از سابقهٔ صدور، همان دسترسی و کنترل پس از تولید؛ بدون تغییر رکورد/امضا/ارسال/حق خواندن مرجع. متادیتای پروژه فقط در صدور جدید، بدون backfill قدیمی. **۴۲/۵۰۱ +۲۱/۸۵ موفق؛۶۳ آزمون /۵۸۶ assertion در دو فرمان مستقل، بدون skip**؛ نه full-suite/تولید/CI جاری. متن‌های قبلیِ تعویق PDF و آمار۴۶/۲۵۰ تاریخی‌اند؛ پنج انتخاب ADR-042 و مرز MTO ثابت‌اند.

> **تحویل جاری — ۲۰۲۶-۱۰-۱۹:** [نوتیفیکیشن عملیاتی مراحل ITP](docs/INSPECTION-NOTIFICATION-WORKFLOW-FA.md) / [English](docs/INSPECTION-NOTIFICATION-WORKFLOW-EN.md). تعیین صریح پیمانکار اصلی، واگذاری مجوز ثبت/صدور توسط مدیر مجاز، مراحل نسخهٔ سند، پیش‌نویس و صدور اتمیک/snapshot و inbox هدفمند ساخته شدند. **دو اجرای نهایی۴۶ آزمون /۲۵۰ assertion موفق**؛ نه اجرای کامل. نوتیفیکیشن، نتیجه/امضا/تحویل خارجی/usable نیست. وضعیت «فقط قرارداد ورودی» قبلی تاریخی شد؛ تصمیم‌های پنج‌گانهٔ ADR-042، مرز نتیجهٔ ADR-041 و MTO محفوظ‌اند. تولید/CI جاری ادعا نمی‌شود.

> **نیازمندی جدید و وضعیت — ۲۰۲۶-۱۰-۱۹:** [ADR-042: نوتیفیکیشن مراحل ITP](docs/ADR-042-ITP-INSPECTION-NOTIFICATIONS-FA.md). پنج انتخاب کاربر ثبت شد: همهٔ مراحل، مجوز مستقل پیمانکار با واگذاری مدیر مجاز، گیرندگان صریح پروژه، نسخهٔ سند+فهرست مراحل و فقط پیمانکار اصلی. **فقط قرارداد ورودی پیاده شد:۷ unit/۲۴ assertion موفق؛ گردش عملیاتی/فرم/صدور هنوز ساخته نشده است.** این نوتیفیکیشن است نه نتیجهٔ بازرسی ADR-041؛ آمارهای قبلی شواهد این قابلیت نیستند.

> **تحویل جاری — ۲۰۲۶-۱۰-۱۹:** [ثبت مستقل بازرسی](docs/INSPECTION-ENTRY-FA.md) / [English](docs/INSPECTION-ENTRY-EN.md)، [ADR-041](docs/ADR-041-INDEPENDENT-INSPECTION-ENTRY-FA.md). **۴۶ آزمون مرتبط /۵۵۸ assertion موفق**،۱۴ مورد جدید. تنظیم نسخه‌دار/تعلیق توسط مالک، فرم ایجاد روی PO قابل‌مشاهدهٔ اصلی، انتساب سرور و سابقهٔ تازهٔ غیرقابل‌ویرایش. قاعدهٔ شرکت مستقل TPI/PMC/MC/مالک، یافتهٔ non-PASS و شمارهٔ IRN قبلی محفوظ است؛ نقش پیش‌فرض/ارثی، تفویض دید PO یا تغییر منبع/لات/مالی/رسید/usable/MTO نداریم. migration190000 فقط خصوصی آزموده شد؛ اجرای کامل/تولید ادعا نمی‌شود. اعلان‌ها و آمار قدیمی زیر تاریخی و غیرقابل‌جمع‌اند.

> **Current closeout — 2026-10-19 local /2026-10-18 UTC:** [CDE-source complete suite GREEN](docs/G01-CDE-FULL-SUITE-EN.md) / [فارسی](docs/G01-CDE-FULL-SUITE-FA.md): **1134 tests/16598 assertions,0 failures/errors/skips,exit0;452 source and2 worker hashes unchanged**; JUnit1323.967296s. New-source full pending gate CLOSED. Earlier pending/no-full/next-full statements below are superseded history, not current instructions. No app source edits this closeout. G01 OPEN; byte integrity is NOT engineering approval/admission, UNADMITTED/UNRESOLVED, no authority/OrderGap change. Runtime recreated after reset; current cache cluster is in metadata, stopped after closeout. Next: accepted-decision-backed read-only reconciliation/validity work, not rerun of unchanged full gate. Current standalone recovery: epcc-source-recovery-cde-full-2026-10-19.tar.gz.

> **CURRENT CLOSEOUT — 2026-10-18:** [complete scope-source suite GREEN](docs/G01-SCOPE-FULL-SUITE-EN.md) / [گزارش فارسی](docs/G01-SCOPE-FULL-SUITE-FA.md): **1112 tests/16488 assertions,0 failures/errors/skips,exit0; all450 source and2 worker hashes unchanged**. JUnit1298.043810s. The scope-source complete-suite pending gate is CLOSED; pending/next-full statements below are superseded history. No app source changes this closeout. Observation remains UNADMITTED; readiness false/gross null; G01 OPEN, OrderGap/authority unchanged. Next: trusted versioned scope/topology admission, not repetition of the unchanged full gate. Standalone current-source recovery: `epcc-source-recovery-scope-full-2026-10-18.tar.gz`; earlier446-source bundle and overlay remain historical. Database on excluded cache disk, private socket/no TCP, stopped after closeout. No production migration/commit/push/PR.

> **CURRENT — 2026-10-18 scope-observation slice:** read [G01-SCOPE-OBSERVATION-EN](docs/G01-SCOPE-OBSERVATION-EN.md) / [FA](docs/G01-SCOPE-OBSERVATION-FA.md) FIRST. New450-file source:58 related PHPUnit tests/197 assertions and5 real contention scenarios PASS; **complete suite on this source NOT RUN/PENDING**. Earlier1098/16429 green below belongs only to preceding446-file source. New sidecar is always UNADMITTED; readiness false/gross null; no historical backfill, selector/OrderGap/authority change. Runtime data moved to excluded `/home/user/.cache/epcc-app-journal-pg-uo100j_u` after test tmpfs exhaustion; metadata identifies it, socket unchanged. Next: new-source complete gate, then trusted admission. Earlier status text below is history, not current-source verification.

# انتقال چت — EPCC Platform

> **2026-10-18 status notice:** this is a historical record. Current local final-source suite is GREEN:1083 tests/16385 assertions,0 failures/errors/skips. Use `docs/FINAL-SOURCE-VERIFICATION-2026-10-18-EN.md`, the current implementation ledger and the new piping pilot matrix (FA companions available). Pilot end-to-end execution and product completeness are not certified; do not follow old next-slice instructions blindly.


**تاریخ:** 2026-10-06  
**هدف:** این فایل برای انتقال کامل وضعیت کاری این چت به یک چت/عامل دیگر نوشته شده است تا کار از آخرین نقطه ادامه پیدا کند.  
**Repository:** `/home/user/epcc-platform`  
**Branch:** `arena/roadmap-reconciliation`  
**آخرین commit push شده:** `d141f329603ed5b12aa3a9c667e8c08e3ace6f8b` (`d141f32 app: add MTO staging foundation`)

> نسخهٔ انگلیسی متناظر: `CHAT-TRANSFER-HANDOFF-EN.md`

---

## 1. دستور شروع برای چت بعدی

اگر این فایل را در چت بعدی paste می‌کنید، متن زیر را به‌عنوان context اولیه بدهید:

```text
Please continue work on `/home/user/epcc-platform`, branch `arena/roadmap-reconciliation`. Read `CHAT-TRANSFER-HANDOFF-FA.md` / `CHAT-TRANSFER-HANDOFF-EN.md`, then `HANDOFF.md`, `README.md`, `docs/TODO.md`, `docs/MTO-REQUIREMENTS-FA.md`, and `docs/MTO-REQUIREMENTS-EN.md`. The last pushed commit is `d141f329603ed5b12aa3a9c667e8c08e3ace6f8b` (`app: add MTO staging foundation`). Continue from Slice 4: accepted immutable MTO snapshots (`accepted_mto_batches`, `accepted_mto_rows`) with all-or-nothing acceptance and rollback tests. Do not implement MTO permissions yet.
```

---

## 2. وضعیت فعلی repository

- مسیر repo: `/home/user/epcc-platform`
- branch فعال: `arena/roadmap-reconciliation`
- آخرین commit محلی/remote که تأیید شده:

```text
local:  d141f329603ed5b12aa3a9c667e8c08e3ace6f8b
remote: d141f329603ed5b12aa3a9c667e8c08e3ace6f8b
```

- PR URL:

```text
https://github.com/iman-taheri59/epcc-platform/pull/new/arena/roadmap-reconciliation
```

- اگر remote در محیط جدید موجود نبود، بدون ذخیره credential این را اضافه کنید:

```bash
cd /home/user/epcc-platform
git remote add origin https://github.com/iman-taheri59/epcc-platform.git
```

- از ذخیره‌کردن PAT در فایل‌های پروژه، remote URL یا git config خودداری شود.

---

## 3. قوانین و قیود مهم کاربر

1. همهٔ مستندات جدید باید نسخهٔ انگلیسی متناظر داشته باشند.
2. همهٔ دیسیپلین‌های لازم EPCC در scope محصول هستند، اما فعلاً **Piping** باید به‌عنوان discipline عمیق/الگو تکمیل شود.
3. دادهٔ تست و preview باید synthetic/fake ولی واقعی‌نما باشد.
4. UI فعلی شلوغ تلقی شده؛ مسیر UX باید task-first و خلوت‌تر بماند.
5. محصول ابتدا برای بازار ایران آماده شود: on-prem/air-gap، فارسی، Jalali، نیازهای مشتریان ایرانی؛ نسخهٔ بین‌المللی بعداً.
6. uploaded docs package نباید مستقیم روی repo overwrite شود؛ باید تحلیل و merge دستی شود.
7. طراحی permission/access برای MTO فعلاً deferred است؛ نقش‌ها/گیت‌های access جدید برای MTO پیاده‌سازی نشود مگر کاربر جداگانه تأیید کند.
8. PATهای موقت فقط برای Git operation و با `GIT_ASKPASS` موقت استفاده شوند؛ در فایل‌ها ذخیره نشوند. پس از پایان بهتر است revoke شوند.

---

## 4. package آپلودی کاربر و نتیجهٔ تحلیل

کاربر فایل زیر را آپلود کرده بود:

```text
/home/user/uploads/epcc-platform-mto-docs-changes-2026-10-06.txt
```

این فایل در واقع ZIP با پسوند `.txt` بود و استخراج شد به:

```text
/home/user/req-update-2026-10-06/epcc-platform-mto-docs-changes-2026-10-06/
```

نتیجهٔ تحلیل:

- package docs-only و شامل فایل‌های کامل replacement بود، نه patch/diff.
- overwrite مستقیم خطرناک است چون baseline آن با branch فعلی فرق دارد.
- در package برخی قابلیت‌ها `BUILT` توصیف شده بودند که در branch فعلی وجود نداشتند، مثل:
  - `BaselineService`
  - `baseline_batches`, `baseline_discrepancies`
  - `PcfParser`, `ModelImportService`, `model_imports`
  - immutable accepted MTO rows قبل از کار فعلی
  - PMS services و NDT models ذکرشده در package
- نتیجهٔ reconciled در این فایل‌ها ثبت شد:
  - `docs/MTO-REQUIREMENTS-FA.md`
  - `docs/MTO-REQUIREMENTS-EN.md`

---

## 5. اصول دامنه‌ای MTO که پذیرفته شده‌اند

1. approved project MTO = gross procurement-planning quantity.
2. model/isometric estimate = net engineering need.
3. اگر `net > gross` باشد، اختلاف مثبت shortage است و percentage tolerance نباید آن را پنهان کند.
4. اگر `gross >= net` باشد، اختلاف فقط به‌عنوان delta نمایش داده می‌شود؛ cause/waste/contingency infer نمی‌شود.
5. order gap از physical usable-material gap جداست.
6. `mto_item_no` به‌تنهایی globally unique نیست و نباید downstream key باشد.
7. accepted MTO row باید immutable snapshot باشد.
8. acceptance باید all-or-nothing per MTO document/sub-MTO revision باشد.
9. aggregation چند MTO فقط با disjoint scope یا exact immutable pair-level additivity evidence مجاز است.
10. scope باید stable/versioned باشد و در snapshotها freeze شود.
11. MTO access-control design deferred است.

---

## 6. کارهایی که در این چت انجام و push شد

### 6.1 مستندات MTO Foundation

Commit:

```text
e54b499 docs: reconcile MTO foundation requirements
```

فایل‌های اصلی:

```text
docs/MTO-REQUIREMENTS-FA.md
docs/MTO-REQUIREMENTS-EN.md
docs/TODO.md
HANDOFF.md
README.md
```

محتوا:

- تحلیل package آپلودی
- ثبت اینکه package نباید overwrite شود
- تعریف مرحله‌بندی MTO Foundation
- ثبت اصول gross/net، accepted row identity، all-or-nothing acceptance، order gap و usable gap

### 6.2 Slice 2 — Material Library Foundation

Commit:

```text
65880b2 app: add material catalogue foundation
```

فایل‌های تولیدشده:

```text
app/database/migrations/2026_10_06_100000_create_material_catalogue.php
app/app/Models/MaterialItem.php
app/app/Services/MaterialCatalogueService.php
app/database/seeders/MaterialCatalogueSeeder.php
app/tests/Feature/MaterialLibraryTest.php
```

تغییر دیگر:

```text
app/database/seeders/DatabaseSeeder.php
```

قابلیت‌ها:

- جدول `material_items`
- دو سطحی بودن کاتالوگ:
  - `project_id IS NULL`: global/reference rows
  - `project_id IS NOT NULL`: project overlay rows
- project overlay برای بازار ایران به‌عنوان مسیر writable فعلی
- exact commodity lookup
- alias/vendor code فقط lookup hint، نه identity
- کنترل category/source/status/base_unit
- bilingual description الزامی
- RLS بر اساس project participation
- synthetic PH-14 catalogue با کدهای:
  - `PIP-CS-6-SCH40`
  - `VLV-GT-6-150`
  - `GSK-SPW-6-150-SS-GR`

### 6.3 Slice 3 — MTO Document / Revision / Staging

Commit:

```text
d141f32 app: add MTO staging foundation
```

فایل‌های تولیدشده:

```text
app/database/migrations/2026_10_06_110000_create_mto_staging.php
app/app/Models/MtoDocument.php
app/app/Models/MtoRevision.php
app/app/Models/MtoStagingRow.php
app/app/Services/MtoStagingService.php
app/tests/Feature/MtoStagingTest.php
```

قابلیت‌ها:

- جدول‌های:
  - `mto_documents`
  - `mto_revisions`
  - `mto_staging_rows`
- MTO document versioned header
- revision با state اولیه `DRAFT`
- staging rows editable و هنوز accepted نیستند
- exact material resolution از `material_items`
- raw text حفظ می‌شود
- alias به‌عنوان identity قبول نمی‌شود
- validation stateها:
  - `ACCEPTABLE`
  - `BLOCKED_UNMATCHED_MATERIAL`
  - `BLOCKED_UNIT_INCOMPATIBLE`
  - `BLOCKED_DUPLICATE_IDENTITY`
  - `BLOCKED_QUANTITY_INVALID`
- RLS برای project participants

---

## 7. validationهای انجام‌شده

### پس از Material Library Foundation

```bash
bash restore.sh
php artisan test --filter MaterialLibraryTest
php artisan test --filter SeedPathTest
python3 standards-packs/validate_packs.py
```

نتایج:

```text
MaterialLibraryTest: 6 passed / 15 assertions
SeedPathTest: 2 passed / 6 assertions
standards validator: all 3 standards packs + 5 reference packs valid
```

### پس از MTO Staging Foundation

```bash
bash restore.sh
php artisan test --filter MtoStagingTest
php artisan test --filter MaterialLibraryTest
php artisan test --filter SeedPathTest
python3 standards-packs/validate_packs.py
```

نتایج:

```text
MtoStagingTest: 8 passed / 21 assertions
MaterialLibraryTest: 6 passed / 15 assertions
SeedPathTest: 2 passed / 6 assertions
standards validator: all 3 standards packs + 5 reference packs valid
```

Full Laravel suite یک‌بار بعد از Material Library شروع شد و تا timeout شکست نشان نداد، اما کامل نشد. در sandbox قبلاً full suite روی commit `f33c9a7` با `858 passed / 3166 assertions` کامل پاس شده بود. پس از Slice 2 و Slice 3 فقط تست‌های هدفمند و restore/validator اجرا شدند.

---

## 8. خطاها و نکات عملیاتی مهم

1. در این sandbox ممکن است PHP/Composer/PostgreSQL پس از snapshot موجود نباشند. اول اجرا کنید:

```bash
cd /home/user/epcc-platform
bash restore.sh
```

2. Laravel tests را موازی اجرا نکنید. اجرای موازی روی PostgreSQL `epcc_test` باعث race در schema reset می‌شود.

3. اگر یک full suite طولانی timeout شد، process باقی‌مانده را بررسی و kill کنید:

```bash
ps -ef | grep -E 'php|artisan|phpunit' | grep -v grep
kill <pid>
```

4. اگر دیتابیس تست بعد از timeout خراب شد:

```bash
cd /home/user/epcc-platform/app
PGPASSWORD=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-) \
  psql -h 127.0.0.1 -U epcc -d epcc_test \
  -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'
```

5. `mutate.sh` گاهی به‌دلیل ابزارها mode change نشان می‌دهد؛ قبل از commit مطمئن شوید executable است:

```bash
chmod +x mutate.sh
git status --short
```

---

## 9. وضعیت build/runtime

- runtime با `restore.sh` قابل بازسازی است.
- `restore.sh` PostgreSQL `.env` می‌سازد/به‌روزرسانی می‌کند، app key می‌سازد، migrate/seed می‌کند و assets را آماده می‌کند.
- local `app/.env` نباید commit شود.
- PHP/Composer/vendor/cache در workspace snapshot پایدار نیستند؛ پس از انتقال ممکن است نیاز به `bash restore.sh` باشد.

---

## 10. مواردی که هنوز ساخته نشده‌اند

هنوز built نیستند و نباید در مستندات/دمو به‌عنوان built معرفی شوند:

```text
accepted_mto_batches
accepted_mto_rows
all-or-nothing MTO acceptance
immutable accepted MTO snapshots
gross MTO vs net engineering estimate comparison
engineering_estimate_rows
PO attribution/order gap
usable material definition and physical gap
MTO access-control/permissions design
MTO UI/workspace
CSV/Excel import UI
PCF/model import
BaselineService / Baseline batches
PMS services from uploaded package
```

---

## 11. گام بعدی دقیق

### Slice 4 — Accepted immutable MTO snapshots

باید ساخته شود:

```text
accepted_mto_batches
accepted_mto_rows
MtoAcceptanceService
MtoAcceptanceTest
```

رفتار پیشنهادی:

1. فقط revision با state `DRAFT` یا شاید `UNDER_REVIEW` قابل accept باشد؛ برای MVP بهتر است فقط `DRAFT` در service تست شود یا state transition ساده اضافه شود.
2. اگر revision هیچ row ندارد، accept نشود.
3. اگر حتی یک `mto_staging_rows.validation_state != ACCEPTABLE` باشد، کل acceptance fail شود و هیچ snapshot ساخته نشود.
4. acceptance در یک DB transaction باشد.
5. accepted rows immutable باشند؛ update/delete مستقیم نباید مسیر service داشته باشد.
6. snapshot باید این‌ها را freeze کند:
   - project_id
   - source mto_document_id
   - source mto_revision_id
   - accepted_batch_id
   - mto_item_no
   - material_item_id
   - gross_quantity
   - unit
   - scope_id nullable
   - hierarchy_revision_id nullable
   - piping_class_id nullable
   - frozen raw description / material labels / commodity code
   - source row hash
   - accepted_at
7. بعد از acceptance، revision state می‌تواند `ACCEPTED` شود.
8. downstreamهای بعدی باید به `accepted_mto_row_id` وصل شوند، نه `mto_item_no` تنها.

تست‌های لازم:

- accepts all acceptable rows atomically
- refuses when any row blocked
- refusal creates zero accepted rows
- duplicate or blocked staging rows cannot be accepted
- accepted rows preserve frozen source values even if material item later changes
- non-participant cannot see accepted rows
- accepted row identity is UUID and independent of `mto_item_no`

---

## 12. فایل‌های کلیدی برای خواندن در شروع ادامه کار

به‌ترتیب:

```text
CHAT-TRANSFER-HANDOFF-FA.md
CHAT-TRANSFER-HANDOFF-EN.md
HANDOFF.md
README.md
docs/TODO.md
docs/MTO-REQUIREMENTS-FA.md
docs/MTO-REQUIREMENTS-EN.md
app/app/Services/MaterialCatalogueService.php
app/app/Services/MtoStagingService.php
app/tests/Feature/MaterialLibraryTest.php
app/tests/Feature/MtoStagingTest.php
```

---

## 13. آخرین وضعیت مورد انتظار قبل از شروع ادامه کار

بعد از commit/push این handoff، انتظار می‌رود:

```bash
cd /home/user/epcc-platform
git status --short
```

خالی باشد.

آخرین commit باید commit مربوط به همین handoff یا اگر commit نشد، حداقل `d141f32` باشد. اگر handoff commit شد، از روی `git log --oneline -5` وضعیت را بررسی کنید.
