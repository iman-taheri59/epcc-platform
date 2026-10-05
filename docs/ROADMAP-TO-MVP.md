# ROADMAP TO MVP — EPCC Platform

Last updated: **2026-10-05**  
Language note: this roadmap is Persian because it is an internal execution document for the current team conversation. Requirements/ADR documents remain English where already mandated.

---

## 1. هدف MVP

MVP این پروژه نباید «همهٔ ماژول‌های EPCC» باشد. MVP باید یک محصول قابل نصب و قابل demo برای بازار ایران باشد که این سه وعده را واقعاً نگه دارد:

1. **کنترل محرمانگی بین شرکت‌ها** با PostgreSQL RLS و authorization شش‌دروازه‌ای.
2. **جریان قراردادی/مهندسی قابل اتکا**: مدارک، بازبینی، ترنسمیتال، punch، MCC، procurement/materials پایه و پایپینگ پایه.
3. **قابل تحویل در محیط on-prem/air-gapped ایران**: فارسی/RTL/Jalali، بدون CDN، قابل backup، قابل restore، و با خروجی‌های PDF اصلی.

هر چیزی که این سه را تقویت نکند، برای MVP اولویت دوم است؛ حتی اگر برای نسخه نهایی لازم باشد.

---

## 2. وضعیت فعلی بسیار خلاصه

### ساخته‌شده و قابل اتکا در vertical slice

- Auth/session login
- ARC §5 six-gate authorization
- PostgreSQL RLS و tenant context
- Portfolio / project setup / team / archive-reopen
- CDE / document register / revisions / file attachments
- Review comments و publication gate
- Contractor transmittals و vendor transmittals
- Punch list و Mechanical Completion certificate
- PDF infrastructure و چند خروجی PDF
- Filament admin محدود و RLS-aware
- Procurement/materials پایه: requisition → PO → inspection → warehouse → site
- Facility / area / subsystem physical breakdown
- Piping line register، numbering، AFC gate، evidence attachment
- Unit preferences
- Components / ports / joints / compatibility / dia-inch
- Standards packs و reference packs

### شکاف‌های MVP

- محیط اجرای کامل در sandbox فعلی موجود نیست؛ PHP/Composer/PostgreSQL باید فراهم شود.
- TODO/coverage باید بعد از ساخت procurement و piping دوباره به FRها نگاشت شود.
- چند PDF قراردادی/عملیاتی هنوز وجود ندارد.
- RFSU/PAC/FAC هنوز در flow نیست.
- PMS/piping class rules هنوز فقط ADR است.
- Import مهندسی هنوز شروع نشده و به نمونه فایل واقعی نیاز دارد.
- HSE و Schedule هنوز صفر صفحه‌اند.

---


### تصمیم تکمیلی 2026-10-05 — پایپینگ اول کامل می‌شود

همهٔ دیسیپلین‌های EPCC در scope محصول باقی می‌مانند، اما از این نقطه به بعد اولویت اجرای عمیق با **تکمیل دیسیپلین پایپینگ** است. جزئیات فازبندی در `docs/PIPING-DISCIPLINE-COMPLETION-ROADMAP-FA.md` آمده و نسخهٔ انگلیسی متناظر `docs/PIPING-DISCIPLINE-COMPLETION-ROADMAP-EN.md` است.

## 3. اصول اجرای ادامه کار

### 3.1 قاعده‌های غیرقابل مذاکره

1. **هیچ قابلیت tenant-scoped بدون تست PostgreSQL/RLS کامل merge نشود.**
2. **هیچ صفحه‌ای بدون shell contract اضافه نشود.**
3. **هیچ وابستگی CDN/فونت/آواتار خارجی اضافه نشود.**
4. **خروجی‌های قراردادی snapshot شوند، نه اینکه بعداً با داده زنده بازنویسی شوند.**
5. **planned و locked در navigation قاطی نشوند.**
6. **هر قابلیت جدید باید در فارسی/RTL و انگلیسی/LTR قابل استفاده باشد.**
7. **هر migration جدید باید RLS، archive-readonly و project/company scope را صریح بررسی کند.**

### 3.2 تعریف Done برای هر قابلیت

برای اینکه یک آیتم «ساخته‌شده» حساب شود:

- migration/model/service/controller/view کامل باشد، اگر لازم است؛
- HTTP path واقعی تست شده باشد، نه فقط service مستقیم؛
- RLS مثبت و منفی تست شده باشد: «خودش را می‌بیند» و «دیگری را نمی‌بیند»؛
- فارسی/انگلیسی و RTL/LTR در view پوشش داده شود؛
- اگر table/register دارد، filter/sort/tabletools بررسی شود؛
- اگر PDF/فایل دارد، magic byte، access control و air-gap constraints بررسی شوند؛
- `HANDOFF.md` و `docs/TODO.md` به‌روزرسانی شوند.

---

## 4. فاز 0 — محیط اجرای قابل اعتماد

**هدف:** قبل از تغییرات سنگین DB/RLS، اجرای کامل پروژه ممکن شود.

### کارها

1. یکی از این دو مسیر انتخاب شود:
   - Docker/devcontainer رسمی برای Laravel + PostgreSQL + Composer + Node + Chromium؛ یا
   - سند نصب محلی دقیق برای Linux server/WSL.
2. دستور smoke test تعریف شود:
   ```bash
   cd app
   php artisan migrate:fresh --seed
   php artisan test
   node tests/Browser/sidebar-toggle.test.cjs
   node tests/Browser/table-tools.test.cjs
   python3 ../standards-packs/validate_packs.py
   ```
3. اگر Docker انتخاب شد، CI محلی/گیت‌هاب باید از همان image استفاده کند تا «روی سیستم من کار می‌کند» حذف شود.

### خروجی قابل قبول

- یک دستور documented برای rebuild کامل.
- اجرای کامل PHPUnit با PostgreSQL واقعی.
- اجرای PDF tests بدون skip پنهان.

### ریسک

بالا از نظر زمان اولیه، اما بدون آن هر migration بعدی پرریسک است.

---

## 5. فاز 1 — همگام‌سازی مستندات و backlog

**وضعیت:** این فاز با این commit شروع شده است.

### کارها

1. `docs/TODO.md` با `HANDOFF.md` و کد فعلی همگام شود.
2. status قدیمی Procurement و Piping اصلاح شود.
3. roadmap MVP در همین فایل تثبیت شود.
4. اگر لازم شد `docs/Prototype-Coverage-Map.md` بعداً با نگاشت FR به code به‌روزرسانی شود.

### خروجی قابل قبول

- سند TODO دیگر ادعا نکند Procurement صفر صفحه است.
- ADR-006 phases 1/1b/2/3 به‌عنوان built شناخته شوند.
- شکاف‌های واقعی واضح باشند.

---

## 6. فاز 2 — تکمیل پایپینگ P1: PMS و Branch Table

**هدف:** پایپینگ از «مدل‌سازی» به «اعتبارسنجی مهندسی» برسد. جزئیات در `docs/PIPING-DISCIPLINE-COMPLETION-ROADMAP-FA.md`.

### آیتم‌ها

- `piping_classes` و `piping_class_rules`
- بازه‌های DN نیمه‌باز `[from, to)`
- `EXCLUDE USING gist` برای جلوگیری از overlap در ruleهای یک‌بعدی
- gap detection هنگام publish
- branch table با resolution بر اساس specificity-wins
- اتصال validation به component attach و joint compatibility

---

## 7. فاز 3 — تکمیل پایپینگ P2-P4: Equipment/nozzles، Fit-up، Supports/Spools

**هدف:** endpointهای واقعی و دادهٔ field وارد مدل پایپینگ شوند.

### آیتم‌ها

- equipment register حداقلی
- equipment nozzles و nozzle ports
- ارتقای free-text endpointها به FK در صورت وجود equipment/nozzle
- nozzle schedule report
- fit-up capture برای joints
- support register با numbering engine
- spool grouping اختیاری

---

## 8. فاز 4 — تکمیل پایپینگ P5-P8: Welding/NDT، Test Pack، PCF Import، Roll-ups

**هدف:** پایپینگ برای construction و commissioning واقعاً end-to-end شود.

### آیتم‌ها

- weld log، welder code، WPS reference
- NDT request/result و repair cycle
- test pack / hydrotest package
- PCF upload → preview → commit
- import batches immutable
- incremental roll-ups برای dia-inch، joint، test pack و subsystem readiness

### نکته

Welding/NDT احتمالاً ADR مستقل لازم دارد، چون خودش workflow، KPI و traceability حساس دارد.

---

## 9. فاز 5 — خروجی‌های رسمی و Completion Certificates

**هدف:** بعد از اتصال پایپینگ به دادهٔ واقعی، خروجی‌ها و گواهی‌ها ارزش عملیاتی پیدا کنند.

### آیتم‌ها

- PDFهای line/test pack/weld log در کنار PDFهای قبلی
- PDF صورت‌وضعیت، punch register، GRN/MIV
- RFSU/PAC/FAC با readiness مبتنی بر line/material/weld/test status

---

## 10. فاز 6 — Engineering Import و دیسیپلین بعدی

**هدف:** بعد از validatorهای پایپینگ، import داده مهندسی و تعمیم الگو به دیسیپلین بعدی آغاز شود.

### آیتم‌ها

- نمونه فایل واقعی line list، MTO، PCF، Navisworks property export
- import profiles
- discipline pattern extraction برای Electrical/I&C یا دیسیپلین منتخب بعدی

---

## 11. فاز 7 — HSE حداقلی

**هدف:** حداقل HSE معتبر برای demo نفت‌وگاز ایران بعد از تثبیت الگوی پایپینگ.

### MVP HSE

1. PTW / Permit to Work
2. Incident report
3. HSE observation
4. Toolbox talk attendance

---

## 12. Backlog بعد از MVP

- Schedule: P6/MSP sync، lookahead، baseline، delay analysis
- NCR/CAPA و ITP/ITR کامل
- Welding/NDT و calibration
- Contract register، VO/change order، claims
- Full-text/OCR
- Offline field app
- BIM/CAD viewer
- LDAP/ERP/P6 integrations
- Notification engine
- Workflow/BPM
- سامانه مؤدیان/شاپرک
- Billing/metering/customer onboarding

---

## 13. شاخص‌های آمادگی برای Pilot

قبل از pilot واقعی، حداقل این‌ها باید برقرار باشند:

1. نصب/restore کامل در محیط جدا از توسعه.
2. اجرای کامل تست‌ها با PostgreSQL واقعی.
3. حداقل یک project seed/demo ایرانی با سناریوهای:
   - document lifecycle
   - transmittal
   - punch/MCC
   - procurement/material to site
   - piping line/component/joint
4. PDFهای رسمی اصلی.
5. air-gap verification سبز.
6. backup/export و restore پروژه.
7. راهنمای admin فارسی.
8. لیست محدودیت‌های صادقانه برای مشتری pilot.

---

## 14. تصمیمات باز که بهتر است کاربر/مالک محصول مشخص کند

1. تصمیم گرفته شد: PMS و تکمیل پایپینگ قبل از RFSU/PAC/FAC عمیق انجام شود، مگر اینکه demo/customer خلافش را تحمیل کند.
2. آیا نمونه فایل واقعی engineering import موجود است؟
3. اولین فرم قرارداد/صورت‌وضعیت هدف دقیقاً کدام است؟ شرایط عمومی پیمان 4311 یا فرم NOC خاص؟
4. اولین NOC numbering scheme کدام است؟
5. HSE MVP بعد از تثبیت الگوی پایپینگ می‌آید، مگر الزام pilot client آن را جلو بکشد.
6. برای توسعه بعدی، Docker/devcontainer قابل قبول است یا محیط نصب دستی ترجیح دارد؟
