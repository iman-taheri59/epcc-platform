# TODO — نقشهٔ راه تکمیل سیستم EPCC

> **Completion roadmap & task list (FA).** Generated 2026-10-26 from the full code-level
> review (`EPCC-REVIEW-FA.md`, `EPCC-BRANCH-REVIEW-FA.md`) and the process assessment
> (`EPCC-PROCESS-ASSESSMENT-FA/EN.md`). Twelve decision skeletons are filed alongside as
> **ADR-047 … ADR-058 (status: PROPOSED)**. House method per increment: owner decisions in
> the ADR → design/guide (EN+FA) → migrations with RLS+FORCE → services with the existing
> discipline (append-only, freshness checks, fail-closed) → tests → evidence per
> `docs/EVIDENCE-POLICY.md`. English twin: `TODO-COMPLETION-ROADMAP-EN.md`.

## قرارداد استفاده از این فایل

- هر کار یک checkbox دارد؛ وقتی کامل شد تیک بزنید و SHA کامیت را جلوی آن بنویسید.
- «معیار پذیرش» هر کار = تست‌هایی که باید سبز شوند (همان الگوی تست‌محور موجود).
- اندازه‌ها: S ≈ یک اینکریمنت کوچک (چند روز)، M ≈ متوسط (۱–۲ هفته)، L ≈ بزرگ (یک چرخهٔ کامل).
- وابستگی‌ها صریح‌اند؛ کاری که وابستگی باز دارد شروع نشود.

---

## ۰) کارهای فوری و بدهی‌های بازِ مرورها (بدون ADR جدید)

- [ ] **۰.۱ پایش billing اکشن‌ها** — اجراهای zero-step (شکست ۳ ثانیه‌ای با صفر step) یعنی سد
      صورتحساب، نه کد. پس از هر push، وضعیت run را در Actions چک کنید. (S)
      معیار پذیرش: اجرای CI روی آخرین کامیت main سبز یا صراحتاً zero-step (نه شکست میانی).
- [ ] **۰.۲ پارالل‌سازی سوئیت** — سوئیت سریال ۵۹ دقیقه است؛ حلقهٔ بازخورد هر اینکریمنت را
      گران کرده. گزینه‌ها: Paratest، یا CI matrix دامنه‌ای (Piping / Inspection / Procurement /
      Core هر کدام یک job). (S–M)
      معیار پذیرش: زمان دیوار سوئیت کامل (لوکال یا CI) زیر ~۲۵ دقیقه بدون حذف تست.
- [ ] **۰.۳ تست Risky بدون assertion** — از گزارش فاز ۱: یک تست هیچ assertion ندارد.
      شناسایی با `--display-risky` و افزودن assertion واقعی یا حذف آگاهانه. (S)
- [ ] **۰.۴ ارتقای هارنس‌های پایتون به CI** — `scripts/tests/*.py` (هم‌رویدادی MTO journal،
      time-UI اعلان‌ها) خارج از هر runner‌ای هستند (بدهی B7). یا به workflow اضافه شوند یا
      در `scripts/run-tests.sh` با گزارش skip بلند. (S)
- [ ] **۰.۵ تبدیل disclaimerها به badge وضعیت** — صفحات پر از «این پذیرش فنی نیست/آزادسازی
      نیست» هستند؛ همان مرزها به badge/state بصری تبدیل شوند (الزام: متن حقوقی در
      tooltip/راهنما باقی بماند، نه ۵ پاراگراف در صفحه). تست‌های PDF/نما به‌روز شوند. (M)
- [ ] **۰.۶ دفترچهٔ فرایند نقش‌محور** — برای هر ماژول فعال یک راهنمای end-to-end دوزبانه
      («یک قلم ماده از MTO تا GRN: چه کسی، کجا، چه وقتی») — الگو: راهنماهای PIPING-*-EN/FA
      موجود، اما به‌جای مرزهای حقوقی، گردش کار را روایت کند. (M، تدریجی per ماژول)
- [ ] **۰.۷ ماتریس وضعیت README را زنده نگه دارید** — جدول «Current truth» تنها منبع وضعیت
      است؛ بنر جدید ممنوع (بایگانی در `docs/STATUS-BULLETIN.md` ادامه یابد). (ongoing)

---

## فاز A — بستن ستون مواد (وابسته به: هیچ؛ شروع توصیه‌شده: فوراً)

> هدف: پایان «دو حقیقت موازی» در مواد و بسته‌شدن حلقهٔ کیفیت. ADRها: **047، 048** (+ اجرای ADR-031 موجود).

- [x] **A.1 تصمیم‌گیری ADR-047** — چهار کلید تصمیم (هستهٔ هدف، سرنوشت legacy، مدل دفتر
      موجودی، موجودی اول دوره). (۲۰۲۶-۱۰-۲۷: با دستور مالک برای شروع اجرا، هر چهار
      گزینهٔ توصیه‌شده پذیرفته و وضعیت ADR به ACCEPTED تغییر کرد؛ بازنگری هر کلید =
      اصلاح append-only روی همان ADR.)
- [x] **A.2 دفتر موجودی append-only** — `stock_entries` (GRN +، صدور −، تعدیل ± با دلیل و
      اختیار دوگانه) + balance projection + ساعت ترتیبی (الگوی `epcc_mto_clock_step`).
      وابسته: A.1. (برش ۱ service-only انجام شد — کامیت `b648866`: ژورنال
      `stock_ledger_entries` + ساعت + تابع موجودی `epcc_stock_balance` + گارد کسری با قفل
      advisory + اختیار دو-شخصی + RECEIPT خودکار از اعتبار GRN؛ تست ۱۰/۸۱ سبز +
      رگرسیون ۶۰ تست سبز. راهنما: `docs/STOCK-LEDGER-FA.md`/`-EN.md`)
      معیار پذیرش: اینوریانس Σentries=balance تحت هم‌رویدادی ✅؛ RLS+FORCE ✅؛ تفکیک وظایف
      در سطح اشخاص ✅ (سطح نقش‌ها → A.2b)؛ تست fail-closed روی نوشتار legacy پس از cutover ✅.
- [ ] **A.2b دفتر موجودی — برش ۲** — جدول اختیارات نقش‌محور `stock_ledger_author_policies`
      (الگوی `piping_receipt_author_policies`)، سطح HTTP/UI برای ثبت و مشاهدهٔ ژورنال، و
      ثبت cutover پروژه‌ای (اختیار-مسیر، به‌جای پرچم سراسری config). وابسته: A.2 ✅. (M)
- [x] **A.3 cutover هستهٔ قدیمی** — `WarehouseService` نوشتاری → refusal fail-closed با پرچم
      cutover؛ خوانا ماندن تاریخچه؛ راهنمای EN/FA. وابسته: A.2. (انجام شد — کامیت
      `b648866`: پرچم `EPCC_WAREHOUSE_LEGACY_WRITES_REFUSED` هر پنج نویسندهٔ موجودی legacy
      را پیش از هر اعتبارسنجی دیگری fail-closed می‌بندد؛ خوانده‌ها باز؛ piping از قبل بسته
      بود. دانه‌بندی پروژه‌ای → A.2b)
- [x] **A.4 رکوردهای نتایج بازرسی/تست (ADR-048) — برش ۱** — `joint_weld_records`
      (WELD/REPAIR_WELD per مفصل FITTED با هویت کد-مهری)، `joint_ndt_results` (per مفصل
      per روش با تبار repair و بازآزمایی)، `test_pack_results` (هیدرو/پنوماتیک per بستهٔ
      READY با تبار آزمون مجدد)، `material_certificates` (MTR به heat دقیق، lot اختیاری).
      دو-حالتی internal/external-register طبق ADR-031. (انجام شد — کامیت `29c138d`:
      `InspectionResultService` + جداول append-only با RLS+FORCE + تریگرهای
      ترتیب/تبار/provenance؛ تست ۱۰/۶۹ سبز + رگرسیون ۷۷/۲۷۵ سبز. راهنما:
      `docs/INSPECTION-RESULTS-FA.md`/`-EN.md`. sign-off مراحل ITP به A.4b منتقل شد)
      معیار پذیرش: جداسازی بازرس≠اعلام‌کننده≔پذیرنده ✅ در سطح شخص (سطح نقش‌ها →
      A.4b)؛ تغییرناپذیری نتایج ✅؛ صداقت external-register ✅ (جفت‌شده در هر دو جهت با
      CHECK)؛ ردیابی قطعه→heat→MTR ✅ (خوانندهٔ heat-دقیق).
- [x] **A.4b نتایج بازرسی — برش ۲، بخش ۱: اختیار نامزدشده + گیت‌گذاری وضعیت بسته بر
      مدرک** (انجام شد — کامیت `d257468`)
      * جدول `result_recorder_policies` (نسخه‌دار، قابل‌تعلیق، RLS+FORCE، انتخاب‌گرهای
        `user:<uuid>` / `role:<company>:<code>`) + تابع `epcc_result_nomination()`؛
        سه گارد برش ۱ حالا نامزدیِ مُجیز را **مُهر و الزام** می‌کنند
        (`recorder_policy_id`، خطای `RESULT_AUTHORITY_NOT_NOMINATED` با 42501). ثبت
        پیش‌تر بر عضویت استوار بود — هر عضو پروژه می‌توانست رکوردی بسازد که کارفرما
        می‌خواند؛ حالا پیش‌فرض ممنوع است و `discipline_scope`ای که PIPING نداشته باشد
        دارنده را فاقد صلاحیت می‌کند.
      * `epcc_guard_test_pack_transition()` روی `piping_test_packs`: TESTED نیازمند
        نتیجهٔ ثبت‌شده؛ ACCEPTED نیازمند PASS بودنِ **آخرین** نتیجه؛ ACCEPTED ترمینال؛
        ترتیب DRAFT→READY→TESTED→ACCEPTED قابل پرش نیست — حتی در برابر SQL مستقیم،
        با نگاشت خطاهای دوزبانه در `PipingTestPackService`. READY به‌عمد در سطح برنامه
        می‌ماند.
      * تست‌ها: `ResultAuthorityAndGatingTest` ۱۳/۴۰ سبز؛ `InspectionResultTest`
        (۱۰/۶۹) + `ItrSnapshotTest` (۱۰/۷۲) به‌همراه Joint/PipingGrn/StockLedger/
        Cde/Component/UnitPref با هم سبز (۲۱۸ تست / ۸۱۹ assertion). فیچر:
        `tests/Support/ResultRecorderPolicyFixture.php`.
      معیار پذیرش: ثبتِ بدون‌نامزدی رد و چیزی نوشته نشود ✅؛ مُهر نامزدی روی رکورد ✅؛
        تعلیق ابطال می‌کند ✅؛ TESTED/ACCEPTED پایبند مدرک ✅؛ بستهٔ پذیرفته‌شده قابل
        تغییر نیست ✅.
- [x] **A.4c نتایج بازرسی — برش ۲، بخش ۲: واحدها + صادرکنندهٔ نامزدی** (انجام شد —
      کامیت `53d1942`)
      * `test_pack_results_pressure_unit_is_pressure`: CHECKای که هنگام مهاجرت از همان
        `units.ref.json`ای تولید می‌شود که `UnitRegistry` می‌خواند (بدون حساسیت به حروف)،
        پس پایگاه‌داده و برنامه نمی‌توانند از هم جدا شوند و نبودِ بستهٔ مرجع نصب را متوقف
        می‌کند. `InspectionResultService` واحدِ ناشناخته **و** واحدِ شناخته‌شده با کمیتِ
        غلط را رد می‌کند؛ `PipingTestPackService` همان چکِ کمیت را برای فشارِ *برنامه‌ای*
        هم گرفت (پیش‌تر یک دما را به‌عنوان فشار می‌پذیرفت).
      * `epcc_guard_result_policy_author()`: نامزدی را فقط خودِ شرکتِ هدف یا کارفرمای
        پروژه می‌تواند صادر کند، آن هم فقط اگر عضو پروژه باشد — یک پیمانکارِ رقیب دیگر
        نمی‌تواند بازرسانِ شرکتِ دیگری را نامزد کند.
      * تست‌ها: `ResultUnitsAndPolicyAuthorshipTest` ۱۰/۳۱ سبز؛ رگرسیونِ مرتبط ۱۷۵ تست /
        ۵۴۰ assertion سبز (دستور کارفرما: سوئیت کامل از این پس در CI اجرا می‌شود، نه در
        حلقهٔ محلی — RUNBOOK §12).
      معیار پذیرش: رد واحدِ ناشناخته و واحد با کمیتِ غلط، حتی با SQL مستقیم ✅؛ پذیرش همهٔ
        واحدهای PRESSURE در هر حالتی از حروف ✅؛ رد صدور توسط ثالث ✅؛ رد نویسندهٔ غیرعضو
        و نویسندهٔ جعلی ✅.
- [x] **A.4d نتایج بازرسی — برش ۲، بخش ۳: sign-offهای hold/witness روی ITP** (انجام شد —
      کامیت `d7d5ce8`)
      * خلأ: اعلان فقط می‌گفت «از شما خواستیم حاضر شوید». هیچ چیز ثبت نمی‌کرد که در مرحله
        چه اتفاق افتاد، پس نقطهٔ توقّفی که هیچ‌کس در آن حاضر نشده و نقطه‌ای که بازرسِ
        کارفرما حاضر شده و پذیرفته، در داده یکسان به نظر می‌رسیدند.
      * `inspection_stage_signoffs` (فقط‌افزودنی، RLS+FORCE، مهاجرت فقط‌روبه‌جلو) همراه با
        `epcc_guard_stage_signoff()`: `stage_id` و `point` از خودِ اعلان **استخراج**
        می‌شوند، پس sign-off نمی‌تواند نوعِ نقطه را غلط اظهار کند؛ امضا پیش‌فرض‌ـ‌ممنوع است
        پشت همان registerِ `result_recorder_policies` (کنشِ جدیدِ `sign_off_stage`) و
        سیاستِ مجاز با نام `signer_policy_id` روی رکورد مهر می‌خورد؛ نتیجه‌ای که کار را
        تغییر می‌دهد باید دلیل داشته باشد (REJECTED → علت، ACCEPTED_WITH_COMMENTS → همان
        نظرها، WAIVED_NOT_ATTENDED → چرا کسی نبود)؛ **نقطهٔ HOLD را فقط کارفرمای پروژه
        می‌تواند انصراف بدهد** — انصرافِ پیمانکار از توقّفِ کارفرما حذفِ یک کنترل است، نه
        انصراف؛ اصلاح یک sign-offِ زنجیره‌شدهٔ جدید است (`previous_signoff_id` باید به یک
        sign-offِ REJECTED از همان اعلان و شرکت اشاره کند)، نه ویرایش؛ `request_id` یکتاست
        تا ارسالِ تکراری دو امضا نگذارد؛ `recorded_at` از نوع `timestamptz(6)` است چون
        ترتیبِ ثبت خودِ رکورد است.
      * اصلاحِ دید: خواندنِ مرحلهٔ ITP مشروط به دسترسیِ خواندن به **revision سند** بود، پس
        همان شرکت‌هایی که اعلان برایشان فرستاده شده نمی‌توانستند مرحله‌ای را که نامش در
        اعلان آمده ببینند — و در نتیجه نمی‌توانستند امضا کنند. یک سیاستِ SELECTِ افزوده به
        عضوِ مطلعِ پروژه اجازه می‌دهد مرحله‌ای را بخواند که اعلانی — که خودش می‌تواند
        بخواند — به آن ارجاع می‌دهد. چیز تازه‌ای درز نمی‌کند و مسیرِ نوشتن باز نمی‌شود.
      * تست‌ها: `ItpSignOffTest` ۱۱ تست / ۴۸ assertion سبز (دو بار، پایدار)؛ رگرسیونِ
        مرتبط (`InspectionNotification`، `InspectionResult`، `ItrSnapshot`،
        `ResultAuthorityAndGating`، `ResultUnitsAndPolicyAuthorship`) ۲۴۵ تست / ۲۱۹۸
        assertion سبز. فیکسچرِ ارتقایِ پیش‌از-A.4d در `InspectionNotificationTest` اکنون
        ابتدا جدولِ sign-off و سیاستِ افزوده را حذف می‌کند.
      * راهنما: `docs/ITP-STAGE-SIGN-OFFS-FA.md` / `-EN.md`.
      معیار پذیرش: ردِ بدون‌نامزدی و نوشته‌نشدنِ هیچ چیز ✅؛ استخراجِ مرحله و نقطه، نه اظهار
        آن‌ها ✅؛ ردِ مردودیِ بی‌علت / انصرافِ بی‌دلیل / پذیرشِ مشروطِ بی‌نظر ✅ (با CHECK،
        پس برای SQL مستقیم هم)؛ پیمانکار نمی‌تواند توقّفِ کارفرما را انصراف بدهد و
        کارفرما می‌تواند ✅؛ نقطهٔ witness را طرفِ مطلع می‌تواند انصراف بدهد ✅؛ اصلاح به
        یک sign-offِ REJECTED زنجیره می‌شود و مردودی در رکورد می‌ماند ✅؛ فقط‌افزودنی حتی
        با SQL مستقیم ✅؛ ردِ request_id تکراری ✅؛ عضوِ دیگر می‌تواند بخواند اما هیچ‌کس به
        نامِ دیگری امضا نمی‌کند ✅؛ اعلانِ پیش‌نویس و اعلانِ نادیدنی امضا نمی‌شوند ✅.
      مرزِ اعلام‌شده (هنوز باز): sign-offها **ثبت** می‌شوند، هنوز **گیت‌گذاری** نشده‌اند —
        پیوندی میان اعلان و بستهٔ تست نیست، پس هنوز می‌توان بسته‌ای را بدون sign-offِ
        توقّف پذیرفت. آن گیت با نام A.5b پیگیری می‌شود و اینجا ادعا نشده. سطح HTTP/UI هم
        همچنان باز است.
- [x] **A.4e نتایج بازرسی — برش ۲، بخش ۴: اتصالِ MTR به custody** (انجام شد — کامیت‌های
      `bb744e0` و `815a1ab`)
      * خلأ: فهرستِ گواهی‌ها heat/lot را به‌صورت متنِ آزاد می‌گفت، زنجیرهٔ custody ی piping
        آنچه دریافت شده را فقط بر حسب heat می‌شناخت، و انبارِ قدیمی (lot_code,
        heat_number) می‌گفت — و هیچ پیوندی نبود. می‌شد گواهی‌ای برای heat ای که هرگز دریافت
        نشده ثبت کرد، و پرسشِ تحویل (کدام heatهایی که داریم گواهی ندارند) اصلاً پاسخی
        نداشت.
      * `piping_custody_amendments.lot_ref` (افزوده، تهی‌پذیر) + گزارشِ **lot مؤثر** توسط
        `epcc_piping_effective_receipt()`: custody می‌تواند lot را هم در کنار heat تأیید
        کند، و اصلاحیه‌های قدیم هَش و NULL ی خود را نگه می‌دارند نه با حدس پُر شوند.
      * `material_certificate_bindings` (فقط‌افزودنی، RLS+FORCE، مهاجرت فقط‌روبه‌جلو) با
        `epcc_guard_certificate_binding()`: heat و lot از **گواهی** و custody/اصلاحیه/item
        از **custody** استخراج می‌شود (درجِ SQL مستقیم که heat ی دیگر ادعا کند به ادعای خودِ
        گواهی اصلاح می‌شود)؛ پیش‌فرض‌ـ‌ممنوع پشت همان register نامزدی (کنشِ جدیدِ
        `bind_certificate`، مهرشده با `bound_policy_id`)؛ انطباقِ دقیق، نه فازی؛ lot ی که
        custody تأیید نکرده **رد** می‌شود (`MTR_LOT_NOT_ATTESTED`) نه تنزیل به سطح heat؛
        گواهیِ متریالی که اینجا نیست با دلیلِ اجباری به‌صورت `UNRESOLVED` نگه داشته
        می‌شود؛ `request_id` یکتا و جفتِ (گواهی، custody) یکتا.
      * `coverage()` پرسشِ تحویل را پاسخ می‌دهد: هر (heat، lot، item) ی که custody تأیید
        می‌کند با تعداد گواهی‌های متصل به آن — heat ی بدون گواهی گزارش می‌شود، هرگز حذف
        نمی‌شود — به‌علاوهٔ هر اتصالِ unresolved با دلیلش.
      * دو نکتهٔ نگه‌داری: نشستِ اتصال **هر دو** قراردادِ نشست را دارد
        (`epcc.result_actor_id` و `epcc.receipt_actor_id`، چون custody فقط برای بازیگرِ
        receipt قابل خواندن است)، و کلیدِ افزوده در `PipingCustodyAmendmentService` باید
        **پیش‌فرض** می‌گرفت چون محموله با قانونِ سخت‌گیرانهٔ `array:` اعتبارسنجی می‌شود
        (`815a1ab`، پس از آنکه ۱۶ تستِ Piping را شکست).
      * تست‌ها: `MtrCustodyBindingTest` ۱۱ تست / ۴۸ assertion سبز (دو بار، پایدار)؛
        رگرسیونِ مرتبط (custody/اصلاحیه/GRN/QA/ردیابیِ Piping، StockLedger، Warehouse،
        اتصال‌های MTR) با هم سبز — ۲۷۳ تست / ۱۶۳۲ assertion.
      * راهنما: `docs/MTR-CUSTODY-BINDING-FA.md` / `-EN.md`.
      معیار پذیرش: استخراجِ heat/lot، نه اظهارِ آن‌ها (حتی با SQL مستقیم) ✅؛ ردِ اتصالِ
        بدون‌نامزدی و نوشته‌نشدنِ هیچ چیز ✅؛ اتصالِ دقیقِ (lot, heat) ✅؛ ردِ lot ی تأییدنشده
        به‌جای تنزیل ✅؛ ردِ heat ی ناشناس مگر با دلیل، و در آن صورت ثبتِ UNRESOLVED ✅؛
        فقط‌افزودنی ✅؛ ردِ request_id تکراری و (گواهی، custody) ی تکراری ✅؛ شرکتِ دیگر
        نمی‌تواند به نامِ دیگری متصل کند ✅؛ coverage ی heat ی بدون گواهی را گزارش می‌کند نه
        اینکه پنهانش کند ✅.
      مرزهای اعلام‌شده: فقط custody ی PIPING (آشتیِ انبارِ قدیمیِ `material_lots` آیتمِ
        cutoverِ ADR-047 است)، بدون HTTP/UI، و coverage می‌شمارد نه اینکه قضاوت کند.
- [x] **A.4f نتایج بازرسی — برش ۲، بخش ۵: آشتیِ انبار قدیمی** (انجام شد — کامیت
      `182a94d`)
      * دیوار: ADR-047 انبارِ `material_lots` را پشت یک cutover بست و گاردِ GRN را واداشت
        هر سفارش خریدی را که هنوز lot قدیمی دارد رد کند. درست بود، اما **هیچ راهی از
        میانش نبود** — هیچ کاری دربارهٔ آن lot نمی‌شد کرد، پس پروژه‌ای که از انبار قدیمی
        استفاده کرده بود هرگز نمی‌توانست برای آن سفارش‌ها GRN کنترل‌شده ثبت کند.
      * `material_legacy_reconciliations` (فقط‌افزودنی، RLS+FORCE، مهاجرت فقط‌روبه‌جلو):
        یک تصمیمِ صریح، نامزدشده و مستدل برای هر lot قدیمی — TAKE_OVER (این رکورد custody
        دوباره‌اش را دریافت می‌کند و باید همان heat را تأیید کند) یا RETIRE (نمی‌آید آن‌سو،
        و این هم دلیلش). item و heat از خودِ lot **استخراج** می‌شوند؛ تصمیمِ بعدی زنجیره
        می‌شود نه ویرایش؛ `request_id` یکتا.
      * `epcc_guard_piping_grn()` بازاعلام شد، بایت‌به‌بایت به‌جز یک IF، تا ردِّ قدیمی فقط
        برای lotهایی باشد که کسی درباره‌شان تصمیم نگرفته: همان کد، همان معنا، دیگر ابدی
        نیست.
      * اختیار از register کیفی (`reconcile_legacy`)، عمداً نه register رسید: آن یکی برای
        هر کنش نامزد می‌خواهد و همهٔ پروژه‌ها را — حتی آن‌ها که اصلاً متریال قدیمی ندارند —
        مجبور می‌کرد پیش از پیکربندیِ رسیدها یک آشتی‌دهنده نامزد کنند.
      * `pending()` فهرستِ کارِ cutover است: lotهایی که هنوز جلوی GRN را گرفته‌اند.
      * تست‌ها: `LegacyMaterialReconciliationTest` ۱۱ تست / ۴۶ assertion سبز (دو بار)؛
        `PipingGrnTest` به‌صورت یکپارچه اثبات می‌کند دروازه پیش از تصمیم بسته است و پس از
        آشتی باز می‌شود (۲۲ / ۱۲۳)؛ رگرسیونِ مرتبط ۴۴۰ تست / ۲۷۸۶ assertion سبز.
      * راهنما: `docs/LEGACY-MATERIAL-RECONCILIATION-FA.md` / `-EN.md`.
      معیار پذیرش: ردِ بدون‌نامزدی و نوشته‌نشدنِ هیچ چیز ✅؛ استخراجِ هویت از lot، نه اظهار
        آن (حتی با SQL مستقیم) ✅؛ ردِ TAKE_OVER با heat ی متفاوت ✅؛ الزامی‌بودنِ دلیل ✅؛
        زنجیره‌شدنِ تصمیمِ دوم و ماندنِ تصمیمِ منسوخ ✅؛ فقط‌افزودنی ✅؛ ردِ request_id
        تکراری ✅؛ شرکتِ دیگر نمی‌تواند به نامِ دیگری تصمیم بگیرد ✅؛ pending() فقط lotهای
        تصمیم‌نگرفته را می‌آورد ✅؛ دروازهٔ GRN پیش از آشتی بسته و پس از آن باز ✅.
      مرزهای اعلام‌شده: این برش دروازه را آشتی می‌دهد نه مقادیر را (`OPENING_COUNT` ی
        ADR-047 دست‌نخورده)، بدون HTTP/UI، و خودِ تصمیم مدرک نیست.
- [x] **A.4g نتایج بازرسی — برش ۲، بخش ۶: register نامزدی صاحبِ یک سطح شد** (انجام
      شد)
      * خلأ: از A.4b ثبتِ مدرکِ بازرسی پیش‌فرض‌ـ‌ممنوع است — یک نتیجه فقط وقتی نوشته
        می‌شود که `epcc_result_nomination()` سیاستی جاری و تعلیق‌نشده پیدا کند که آن
        شخص را نامزد کرده باشد. درست است و در پایگاه‌داده اعمال می‌شود. اما **تنها
        کدی که تاکنون در `result_recorder_policies` می‌نوشت یک fixture تست بود که
        مستقیم ردیف درج می‌کرد**؛ یعنی در عمل کلِ لایهٔ کیفی پشتِ دیوار خاموش بود و
        تنها کلیدش SQL بود. register ای که کسی نتواند در آن بنویسد، قفلی است که
        کلیدش درونِ اتاق است.
      * `ResultRecorderPolicyService` (dashboard/configure/nominationFor) +
        `QualityNominationController` + صفحهٔ
        `/projects/{projectCode}/quality/nominations`: هر شرکت آدم‌های خودش را نامزد
        می‌کند، یا کارفرمای پروژه هر کسی را.
      * نامزدی یعنی (پروژه، شرکتِ هدف، کار، نسخه) و هرگز ویرایش نمی‌شود؛ نسخهٔ جدید
        جایگزین می‌شود و ردیفِ قدیمی می‌ماند. تعلیق یعنی نسخه‌ای جدید با
        `suspended = true` — چون عمداً سیاستِ UPDATE روی این جدول نداریم.
      * انتخاب‌ها (`user:<uuid>`، `role:<شرکت>:<نقش>`) **عرضه** می‌شوند نه پذیرفته:
        فقط اعضایی که گارد می‌شناسد در فهرست می‌آیند.
      * «آیا من نامزد هستم؟» را خودِ `epcc_result_nomination()` پاسخ می‌دهد — پس صفحه
        و گاردها نمی‌توانند دربارهٔ اختیارِ موجود اختلاف داشته باشند.
      * یک register برای هر هفت کاری که CHECK اش مجاز می‌داند: record_weld،
        record_ndt، record_pack_result، register_certificate، sign_off_stage،
        bind_certificate، reconcile_legacy.
      * تست‌ها: `ResultRecorderNominationsHttpTest` ۱۵ تست / ۵۹ assertion سبز، همه از
        مسیرِ HTTP (شرکتِ خودی، کارفرما، ردِّ شرکتِ دیگر، نامزدِ تهی/بیگانه، دلیلِ
        خالی، کارِ ناشناس، نسخهٔ کهنه، تعلیق با و بدون دلیل).
      * راهنما: `docs/QUALITY-NOMINATIONS-FA.md` / `-EN.md`.
      معیار پذیرش: ردِ بدون‌نامزدی و نوشته‌نشدنِ هیچ چیز ✅؛ نسخه‌بندی نه ویرایش ✅؛
        تعلیق یک نسخه است ✅؛ شرکتِ دیگر ممنوع ✅؛ کارفرما مجاز ✅؛ انتخاب‌ها باید عضوِ
        واقعی باشند ✅؛ دلیل اجباری ✅؛ نسخهٔ کهنه رد می‌شود ✅؛ مرجع همان تابعِ گارد
        است ✅؛ ناشناس ← ورود، غیرِعضو ← ممنوع ✅.
      مرزهای اعلام‌شده: فقط register — اینجا هیچ نتیجه، sign-off، اتصال یا آشتی‌ای
        ثبت نمی‌شود.
- [x] **A.4h نتایج بازرسی — برش ۱ (ثبتِ نتایج)** — سطح HTTP/UI برای ثبتِ نتایجِ
      جوش / آزمونِ غیرمخرب / آزمونِ فشار. وابسته: A.4g ✅.
      (انجام شد — کامیت `b2ee0e5`: `InspectionResultController` + `quality.results`
      و سه POST روی `InspectionResultService::dashboard()`؛ فرم فقط جایی رسم
      می‌شود که تابعِ خودِ register بگوید این شخص نامزد است، وگرنه همان‌جا به‌جای
      فرم، دلیلِ رد نشان داده می‌شود؛ غیرِعضوِ پروژه همان دم رد می‌شود.
      * تست‌ها: `InspectionResultHttpTest` ۱۵ تست / ۶۱ assertion سبز، همه از مسیرِ
        HTTP (نامزدی درِ هر فرم را باز می‌کند، جوشِ تعمیری دلیل می‌خواهد، آزمونِ
        مردود شرحِ نقص می‌خواهد، تا جوش نباشد آزمون رد می‌شود، دما فشار نیست،
        قبولی دلیلِ شکست ندارد، آزمون پیش از شروع تمام نمی‌شود، ارسالِ تکراری رد
        می‌شود نه تکثیر).
      * راهنما: `docs/QUALITY-RESULT-RECORDING-FA.md` / `-EN.md`.)
      معیار پذیرش: فرم فقط برای شخصِ نامزد ظاهر می‌شود ✅؛ مرجع همان تابعِ گارد است
        ✅؛ POSTِ جعل‌شده باز هم در پایگاه‌داده رد می‌شود ✅؛ ناشناس ← ورود،
        غیرِعضو ← ممنوع ✅؛ هیچ چیز ویرایش نمی‌شود ✅.
      مرزهای اعلام‌شده: فقط ثبت — صفحه‌ای برای sign-off، اتصالِ گواهی، آشتیِ انبارِ
        قدیمی، خروجیِ ITR و قضاوتِ کامل‌بودن ندارد؛ ورودِ گروهی ندارد؛ فهرستِ مفصل‌ها
        و بسته‌ها تا ۵۰۰ ردیف است.
- [ ] **A.4h نتایج بازرسی — برش ۲** — سطح HTTP/UI برای امضای مراحلِ ITP، اتصال‌های
      MTR و آشتی‌های انبار قدیمی؛ خروجی ITR؛ گزارشِ تطبیقِ مقدارِ رسیدِ نام‌برده با
      ماندهٔ قدیمی. وابسته: برش ۱ از A.4h ✅. (M)
- [x] **A.5 کامپایلر ITR — برش ۱ (دامنهٔ TEST_PACK)** — اسمبلر on-demand + snapshot
      امضاشدهٔ digest-bound؛ گزارش کامل‌بودن صریح (فقره‌های غایب شمرده شوند، نه حذف
      خاموش) با رندر دوزبانهٔ موجود. (انجام شد — کامیت `2a6e2fa`:
      `ItrCompilerService` (compile/freeze/snapshot/renderPdf) + `itr_snapshots`
      (append-only با RLS+FORCE، dossier+کامل‌بودن+digest sha256 همه
      trigger-derived — محتوای client هرگز اعلام‌پذیر نیست) + blade دوزبانهٔ
      `print.itr-dossier`؛ تست ۱۰/۷۲ سبز + رگرسیون ۸۷/۳۴۴ سبز. راهنما:
      `docs/ITR-COMPILER-FA.md`/`-EN.md`. دامنه‌های غیر-بسته به A.5b منتقل شد)
      معیار پذیرش: پیش‌نمایش ≡ snapshot منجمد (همان بدنهٔ SQL) ✅؛ digest دقیقاً
      dossier ذخیره‌شده را می‌بندد ✅ + بازراستیابی خواننده ✅؛ شکاف‌ها شمرده نه
      پنهان ✅؛ انجمادِ یک لحظه در برابر مدرک متأخر تغییرناپذیر ✅.
- [ ] **A.5b کامپایلر ITR — برش ۲** — دامنه‌های subsystem/سیستم و dossier کامل
      (تلاقی با ADR-057)، اتصال اعلان‌های ITP و رسیدها/MTR به dossier (وابسته:
      custody سطح-قطعهٔ A.4b؛ گیتِ اعلان↔بستهٔ تست به sign-offهای A.4d نیاز دارد ✅)،
      لایهٔ امضا/تأیید رسمی روی snapshot ها، سطح HTTP/UI
      برای گردآوری/انجماد/خروجی. وابسته: A.5 ✅، A.4b، F.1. (M)
- [ ] **A.6 اجرای ADR-031: TQ/NCR** — spec نوشته شده (دو حالت IN_SYSTEM/EXTERNAL_REGISTER)؛
      فقط اجرا می‌خواهد: رکورد، گردش (از ADR-049 اگر آماده بود، وگرنه انتقال-حالت ساده با
      اختیار)، disposition، اتصال به خط/قطعه/snapshot PMS. (M)

---

## فاز B — لایهٔ مدیریت (موازی با A؛ ADRها: **049، 050**)

> هدف: پاسخ به «چه کاری منتظر من است؟ چه چیزی دیر شده؟» — تبدیل سیستم از ثبت به مدیریت.

- [ ] **B.1 تصمیم‌گیری ADR-049** — پنج کلید (دامنهٔ موتور، استراتژی پذیرش، SLA، delegation،
      projection). (S)
- [ ] **B.2 موتور گردش‌کار حداقلی** — `workflow_policies` (نقش/شرکت per گام، جداسازی،
      deadline) + `workflow_transitions` (append-only با ساعت ترتیبی) + ثبت delegation
      time-boxed. وابسته: B.1. (L)
      معیار پذیرش: approval زنجیره‌ای A→B→C با SLA؛ delegation منقضی‌شده fail-closed؛
      ledger تغییرناپذیر؛ جداسازی وظایف تحت هم‌رویدادی.
- [ ] **B.3 proof روی چرخهٔ review مدارک** — مسیردهی IDC→IFR→IFA→IFC روی موتور B.2 با
      اتصال `ReviewComment` موجود؛ plan-vs-actual تاریخ per deliverable به رجیستر اضافه شود. (M)
- [ ] **B.4 inbox سراسری «منتظر اقدام من»** — projection روی ledger گردش‌کار + صف‌های دامنه‌ای
      موجود (ack شرکتی، QC/QA، approvalها)؛ دوزبانه؛ GET با no-store (الگوی موجود). (M)
      معیار پذیرش: inbox هرگز کار اختراع/پنهان نمی‌کند (تست projection-honesty).
- [ ] **B.5 داشبورد aging/استثناها** — PO slip (فیلد و محاسبه موجود است!)، اعلان‌های
      بی‌پاسخ، punch باز، رسیدهای بلاتکلیف؛ bucketهای سنی؛ KPIهای حداقلی. (M)
- [ ] **B.6 تصمیم‌گیری ADR-050 + transport ایمیل** — `notification_deliveries` ledger
      (channel/recipient/digest/provider-id/رویدادهای bounce)؛ چهار جریان نخست: issue/cancel/
      ack/approval-request؛ template دوزبانه؛ لینک امضاشدهٔ time-boxed؛ webhook با
      hash_equals؛ شکست transport هرگز رکورد تجاری را تغییر ندهد. (M)
      معیار پذیرش: عدم نشت بین‌شرکتی در body/ضمیمه؛ تغییرناپذیری ledger تحویل؛
      fail-open روی delivery / fail-closed روی record.
- [ ] **B.7 یادآورهای SLA** — اتصال deadlineهای B.2 به کانال B.6 (reminder = رویداد ledger
      تحویل، نه تغییر وضعیت). وابسته: B.2+B.6. (S)

---

## فاز C — دو انتهای تدارکات (وابسته به: B.2 برای approvalها؛ ADRها: **052، 053**)

- [ ] **C.1 تصمیم‌گیری ADR-052** — پنج کلید (مدل vendor، prequalification، جریان RFQ،
      مقایسهٔ پیشنهادها، award→PO). (S)
- [ ] **C.2 ثبت vendor + صلاحیت ماتریسی** — vendor = company با تایپ VEN؛ صلاحیت per
      (vendor, دستهٔ مواد، سطح) با پنجرهٔ اعتبار و تاریخچهٔ append-only؛ AVL = کوئری. (M)
- [ ] **C.3 RFQ → پیشنهادها → tabulation → award** — دو-حالتی (IN_SYSTEM/EXTERNAL_REGISTER)؛
      tabulation ساختاریافته (تجاری با ماشین‌آلات BigDecimal موجود + فنی چک‌لیستی)؛
      rounds شفاف‌سازی append-only؛ award با زنجیرهٔ اختیار؛ تبدیل به PO draft موجود
      (ADR-033) با lineage digest-bound. وابسته: C.1، B.2. (L)
      معیار پذیرش: صلاحیت دسته‌محور در دعوت RFQ اجرایی شود؛ تغییرناپذیری پیشنهاد پس از
      deadline؛ قطعیت tabulation؛ disclosure-policy per پروژه.
- [ ] **C.4 تغییر سفارش PO** — transition بین نسخه‌ها با reason code + زنجیرهٔ اختیار +
      diff snapshot (الگوی draft-history اعلان‌ها)؛ هرگز re-issue خاموش. (M)
- [ ] **C.5 invoice + تطبیق سه‌طرفه** — ورود دو-منشأ (vendor/buyer) با provenance؛
      match خودکار tolerant (تلرانس‌های پروژه) با فریز ورودی‌ها (digest)؛ خطوط
      out-of-tolerance → held با مسیر انسانی؛ قطعیت: اجرای مجدد با رسیدهای جدید = نتیجهٔ
      جدید، نه تغییر قبلی. (L)
- [ ] **C.6 payment certificate واقعی** — دوره، invoices منطبق، کسورات/پیش‌پرداخت/تهاتر
      در BigDecimal، زنجیرهٔ تأیید، دو حالت امضا (داخلی/external-register)؛ مدل/دموی
      موجود `PaymentCertificate` یا مهاجرت کند یا بازنشسته (تصمیم در ADR-053). (M)

---

## فاز D — زمان‌بندی و هزینه با رویکرد integration (وابسته به: B.4/B.5 برای نمایش؛ ADRها: **051، 054**)

- [ ] **D.1 تصمیم‌گیری ADR-051** — پنج کلید (منابع import، نقش schedule، binding، سنجهٔ
      پیشرفت، گزارش). (S)
- [ ] **D.2 واردکنندهٔ XER/MPP** — parse→validate→snapshot تغییرناپذیر (digest+ordinal)؛
      گزارش diff بین snapshotها؛ fail-closed CLI با exit code (الگوی `plan-mto-backfill.php`)؛
      corpus فایل‌های طلایی برای تست پارسر. (L)
- [ ] **D.3 binding فعالیت⇄شیء پلتفرم** — ثبت append-only با supersession؛ پیشنهادهای
      rule-based (code-mask) با تأیید انسانی. (M)
- [ ] **D.4 سنجهٔ پیشرفت فیزیکی event-weighted** — قواعد وزنی نسخه‌دار per پروژه
      (joint: fitted/welded+NDT/tested؛ PO: issued/received/accepted)؛ محاسبهٔ STABLE و
      قابل‌بازتولید در هر cutoff با توضیح «کدام رویدادها این درصد را ساختند»؛ override
      دستی فقط به‌عنوان تعدیل audited. وابسته: D.2/D.3 (+A.4 برای وزن‌های weld). (M)
- [ ] **D.5 S-curve و lookahead** — planned-vs-actual per سیستم/ناحیه/دیسیپلین؛ خوراک
      lookahead برای آزادسازی کارپکیج (D→E). (M)
- [ ] **D.6 تصمیم‌گیری ADR-054 + موتور هزینه** — `budget_revisions` (CBS mirror با
      snapshotهای نسخه‌دار) + `cost_journal` (نردبان تعهد: برآورد→MR→award→PO→تغییر؛
      actual: GRN accrual→invoice settlement) + شاخص‌های EVM سبک (SPI/CPI/VAC) به‌عنوان
      توابع خالص ورودی‌های digest-bound؛ نرخ ارز = snapshotهای تاریخ‌دار (اشتراک با C.5). (L)
      معیار پذیرش: اینوریانس Σjournal=rollup؛ قطعیت/توضیح‌پذیری هر شاخص؛ دقت BigDecimal
      سرتاسری.

---

## فاز E — ساخت و field (وابسته به: A.2 و D.3–D.5؛ ADRها: **055، 056**)

- [ ] **E.1 تصمیم‌گیری ADR-055** — پنج کلید (مبنای scope پکیج، مدل محدودیت، اختیار
      آزادسازی، ثبت پیشرفت، اتصال پیمانکار). (S)
- [ ] **E.2 کارپکیج IWP + resolverهای محدودیت** — `work_packages` (state machine روی
      موتور B.2) + `constraints` با resolverهای STABLE (موجودی از A.2، مدارک IFC از CDE،
      بازرسی از ITPها، مجوزها دستی-evidenced)؛ waiver = عمل اختیار-مسیر append-only. (L)
      معیار پذیرش: قطعیت readiness در هر cutoff؛ جداسازی پیشنهاددهنده≠آزادکننده.
- [ ] **E.3 گزارش روزانه + reconciliation** — daily report append-only per پکیج-روز
      (نیرو/ساعت/jointهای تکمیل/blockerهای کددار)؛ واگرایی با پیشرفت event-derived (D.4)
      به‌عنوان سیگنال مدیریت‌شده. (M)
- [ ] **E.4 تصمیم‌گیری ADR-056** — پنج کلید (سطح capture، مدل sync، معناهای تعارض،
      پیوست‌ها، دستگاه/هویت). (S)
- [ ] **E.5 PWA آفلاین + ژورنال دستگاه** — journal کلاینت امضاشده (device-ordinal)؛
      پذیرش سمت سرور از مسیر اختیار/اعتبارسنجی نرمال (capture-time ≠ record-time، هر دو
      نمایان)؛ صف reconciliation برای ورودی‌های ردشده؛ پیوست‌های content-addressed با
      آپلود resumable؛ snapshotهای خواندنی bounded با staleness نمایان. (L)
- [ ] **E.6 ارتقای هارنس‌های پروتوتایپ persistence** — `prototype/persistence` و
      `demand-atomicity` به تست/CI واقعی تبدیل شوند (بخشی از بدهی B7/۰.۴). (S)

---

## فاز F — راه‌اندازی و تحویل (وابسته به: A.4–A.5، E.2؛ ADR: **057**)

- [ ] **F.1 تصمیم‌گیری ADR-057** — پنج کلید (سلسله‌مراتب تکمیل، قواعد MC، شکل dossier،
      دامنهٔ commissioning، پذیرش کارفرما). (S)
- [ ] **F.2 state machine تکمیل per subsystem** — گیت‌های اعلامی (پکیج‌های کامل، punch A
      بسته، test pack پذیرفته، as-built Published، prerequisites بسته) با resolver خودکار +
      waiver اختیار-مسیر؛ snapshot گیت در هر انتقال. (M)
- [ ] **F.3 اتصال punch به subsystem/پکیج** — دسته‌بندی A/B/C، شواهد clearance، پیوند MC
      certificate موجود به state machine. (S–M)
- [ ] **F.4 کامپایلر turnover dossier** — index زندهٔ کامل‌بودن (ماتریس تحویل پروژه در
      برابر اقلام موجود؛ فقره‌های غایب صریح) + bundleهای منجمد digest-bound در
      milestoneها (MC/handover) با رندر دوزبانه. (M)
- [ ] **F.5 چک‌لیست‌های pre-commissioning** — رکوردهای ساختاریافته (flushing/cleaning/
      tightness) مقید به subsystem. (M)

---

## فاز G — ضرب‌کننده‌های پذیرش (وابسته به: B.6 و جریان‌های server-side موجود؛ ADR: **058**)

- [ ] **G.1 تصمیم‌گیری ADR-058** — پنج کلید (توپولوژی پورتال، هویت بیرونی، مجموعهٔ اعمال
      فاز ۱، TPI witness، تعرض تجاری). (S)
- [ ] **G.2 لایهٔ سطحِ external session** — claim نوع جلسه (`external:company`) در
      middleware با fail-closed؛ surface حداقلی (RFQ/پیشنهادها، PO+forecast، invoice،
      اسناد حمل، inbox اعلان‌ها)؛ بدون هیچ ناوبری به صفحات EPC. (M)
- [ ] **G.3 چرخهٔ هویت بیرونی** — دعوت اختیار-مسیر → تماس verified (کانال B.6) → عضویت
      scope-دار → ledger ابطال append-only؛ گسترش throttle/گیت‌های موجود به جلسات بیرونی +
      تست‌سوییت abuse اختصاصی. (M)
- [ ] **G.4 خودسرویسی vendor فاز ۱** — تأیید PO، به‌روزرسانی forecast (خوراک slip موجود)،
      ثبت invoice (C.5 با provenance vendor)، اسناد حمل. (M)
- [ ] **G.5 sign-off شاهد TPI** — هویت‌های بیرونی با حق امضای hold/witness روی A.4. (S)

---

## ترتیب پیشنهادی اجرا (نقشِ راه یک‌خطی)

```
۰ (فوری) ─► A.1–A.3 (cutover/موجودی) ─► B.1–B.2 (موتور گردش‌کار) ─► B.4–B.6 (inbox/داشبورد/ایمیل)
        └─► A.4–A.6 (نتایج/ITR/TQ-NCR) ─► C (sourcing→money) ─► D (schedule/cost) ─► E (IWP/field) ─► F (handover) ─► G (portals)
```

قانون ترتیب: اول حلقه‌های بازِ داده (مواد/کیفیت)، بعد لایهٔ مدیریت زیر همه‌چیز، بعد عرض
با integration نه construction. هیچ فازی انضباط‌های موجود (append-only/RLS/جداسازی/
fail-closed/صداقت نمایش) را شل نمی‌کند — هر ماژول جدید با همان الگوی ADR+راهنما+تست+evidence.

## نمایهٔ ADRهای پیشنهادی (همه status=PROPOSED)

| ADR | موضوع | فاز |
|---|---|---|
| 047 | cutover انبار + دفتر موجودی واحد | A |
| 048 | نتایج بازرسی/تست (MTR/NDT/hydro) + ITR | A |
| 049 | موتور گردش‌کار حداقلی + ledger انتقال + delegation | B |
| 050 | transport اعلانی (ایمیل/SMS) + ledger تحویل | B |
| 051 | یکپارچگی زمان‌بندی (XER/MPP) + سنجهٔ پیشرفت event-based | D |
| 052 | sourcing: ثبت vendor، صلاحیت، RFQ، award | C |
| 053 | پول: تغییر سفارش، invoice، تطبیق سه‌طرفه، payment certificate | C |
| 054 | تعهد/actual هزینه + EVM سبک | D |
| 055 | کارپکیج IWP + آزادسازی constraint-free | E |
| 056 | capture میدانی + sync آفلاین (ارتقای پروتوتایپ persistence) | E |
| 057 | تکمیل سیستم/subsystem + تدوین turnover dossier | F |
| 058 | پورتال‌های خودسروی VEN/SUB/TPI | G |
