# گزارش اولیه تحلیل ریپوی `epcc-platform`

تاریخ تحلیل: 2026-10-05

## وضعیت دسترسی و همگام‌سازی

- ریپو با موفقیت clone شد: `github.com/iman-taheri59/epcc-platform`
- شاخه فعلی: `main`
- HEAD محلی و ریموت همگام‌اند: `61093dd3f9a8e146161e288e2857c9dc372e6b4d`
- حجم ورک‌اسپیس فعلی پروژه حدود ۱۵ مگابایت است؛ وابستگی‌های حجیم Laravel/Node در مخزن نبودند.

> نکته امنیتی: توکن دسترسی در فایل‌های پروژه یا remote ذخیره نشده است. بعد از پایان کار، بهتر است PAT موقت را revoke کنید.

---

## خلاصه اجرایی

پروژه از یک اسکلت ساده Laravel جلوتر است و عملاً یک vertical slice نسبتاً جدی دارد. تمرکز اصلی تا اینجا روی این محورها بوده:

1. معماری چندمستأجری با PostgreSQL RLS
2. احراز هویت و authorization شش‌دروازه‌ای
3. دوزبانگی فارسی/انگلیسی، RTL واقعی، تاریخ شمسی
4. CDE / دفتر مدارک / بازبینی / ترنسمیتال‌ها
5. Punch list و Mechanical Completion
6. پنل ادمین Filament محدود و RLS-aware
7. Procurement/materials در حد زنجیره تهیه تا سایت
8. ساختار فیزیکی facility/area/subsystem
9. پایپینگ: line register، numbering، evidence، units، components/ports، joints و dia-inch
10. reference packs استانداردها و داده‌های مهندسی

بر اساس `HANDOFF.md`، آخرین وضعیت تست مستندشده: **852 passed / 3136 assertions** به‌علاوه تست‌های jsdom. در محیط فعلی PHP/Composer/PostgreSQL نصب نیست، بنابراین فعلاً اجرای تست‌های Laravel ممکن نبود، اما تست‌های قابل اجرای فعلی با Node/Python سبز شدند.

---

## بررسی‌های اجراشده در این محیط

### 1. اعتبارسنجی standards packs و reference packs

دستور:

```bash
python3 standards-packs/validate_packs.py
```

نتیجه:

- هر ۳ standards pack معتبرند: `iso`, `awp`, `ips-iran`
- هر ۵ reference pack معتبرند: pipe sizes، schedules، end types، component templates، units
- seamهای AWP مطابق ADR-002 سالم‌اند
- داده مرجع فعلی: ۳۶ سایز، ۳۵۵ جفت size/schedule، ۱۳ end type، ۲۰ component template با ۴۱ port definition، ۶ quantity و ۲۹ unit

### 2. تست jsdom پروتوتایپ sidebar

دستور:

```bash
npm ci --no-audit --no-fund
node prototype/test/sidebar.test.js
```

نتیجه: **19 passed, 0 failed**

### 3. وضعیت ریپو

- `git status` تمیز است.
- `origin` بدون token ثبت شده و امن است.
- HEAD محلی و ریموت برابرند.

---

## ساختار فنی فعلی

### Laravel app

مسیر اصلی: `app/`

- Laravel 13.x
- Filament v4
- PHP requirement: `^8.3`، ولی اسناد پروژه PHP 8.4 را هدف می‌گیرند
- PostgreSQL/RLS محور اصلی طراحی است
- package داخلی: `epcc/standards-engine` به‌صورت path repository

### تعداد فایل‌ها/مؤلفه‌های مهم

- migrations: 27
- models: 35
- controllers: 12
- Filament resource-related files: 17
- Feature tests: حدود ۴۸ فایل PHP + Browser jsdom tests

### مسیرهای اصلی ساخته‌شده

- `/login`
- `/` portfolio
- `/projects/new`
- `/projects/{code}`
- `/projects/{code}/documents`
- `/projects/{code}/transmittals`
- `/projects/{code}/vendor-transmittals`
- `/projects/{code}/piping`
- `/projects/{code}/piping/config`
- `/projects/{code}/piping/{line}/model`
- `/projects/{code}/punch`
- `/projects/{code}/materials`
- `/projects/{code}/procurement`
- `/projects/{code}/team`
- `/admin` از طریق Filament

---

## نکات معماری حیاتی که نباید شکسته شوند

این‌ها از `HANDOFF.md` و ساختار کد استخراج شده‌اند:

1. **RLS منبع اصلی isolation است**؛ نباید با `where company_id = ...` جایگزین شود.
2. `SET LOCAL` فقط داخل transaction معتبر است؛ استفاده از `SET` معمولی خطر نشت tenant دارد.
3. `FORCE ROW LEVEL SECURITY` باید حفظ شود.
4. `NULLIF(GUC,'')` برای GUCهای tenant ضروری است.
5. تست RLS روی SQLite بی‌معناست؛ باید روی PostgreSQL واقعی اجرا شود.
6. ترتیب middleware باربر است: `auth` قبل از `tenant`.
7. sidebar سطح authorization است؛ planned و locked نباید اشتباه گرفته شوند.
8. هر screen جدید باید Shell contract را رعایت کند: app → sidebar → wrap → topbar → content.
9. هر قابلیت air-gapped باید بدون CDN، فونت/آواتار خارجی و network dependency کار کند.
10. هر خروجی قراردادی/حسابرسی باید snapshot شود و زنده محاسبه نشود، مگر پیش‌نویس تصمیم‌گیری باشد.

---

## وضعیت ماژول‌ها

### نسبتاً ساخته‌شده

- CORE/Auth/Tenancy/RLS
- Project setup، portfolio، archive/reopen
- Document register / CDE
- Document files
- Review comments
- Transmittals و vendor transmittals
- Punch list و MC certificate
- PDF زیرساختی و چند خروجی
- Procurement/materials در سطح زنجیره پایه
- Physical breakdown: facilities/areas/subsystems
- Piping phase 1 تا 3: lines، config، units، components/ports، joints، dia-inch
- Filament admin محدود

### ناتمام یا شکاف‌دار

- RFSU/PAC/FAC certificates
- PDF صورت‌وضعیت و PDF دفتر نواقص
- bulk import سیستم/زیرسیستم
- punch photos متصل به DocumentFileStore
- RFQ/tendering و اسناد PDF انبار/خرید مثل GRN/MIV
- HSE module
- Schedule module
- Project management کامل: WBS/CBS/OBS/PBS، risk، issue، action، MOM، lessons learned
- Engineering import: Excel/PCF/Navisworks property export
- PMS/piping class rules و branch table
- Equipment/nozzles
- Full-text/OCR
- offline field app
- BIM/CAD viewer
- P6/ERP/LDAP integrations
- installer/offline bundle/signature
- notification engine
- workflow/BPM
- سامانه مؤدیان/شاپرک

---

## نکته مهم درباره مستندات

`docs/TODO.md` در چند بخش قدیمی شده است. مثلاً در بخش‌هایی هنوز Procurement را «صفر صفحه» معرفی می‌کند، در حالی که `HANDOFF.md` بخش 5k و کد فعلی نشان می‌دهد Procurement/materials تا حدی ساخته شده است. بنابراین برای تصمیم‌گیری فنی، فعلاً `HANDOFF.md` معتبرتر است؛ ولی TODO باید در اولین فرصت reconcile شود.

---

## پیشنهاد مسیر ادامه کار

### اولویت 0 — آماده‌سازی محیط اجرای واقعی

در این sandbox فعلی PHP/Composer/PostgreSQL نصب نیست، پس برای توسعه مطمئن Laravel باید یکی از این‌ها را داشته باشیم:

1. اجرای کارها در محیطی که PHP/Composer/PostgreSQL دارد؛ یا
2. اضافه‌کردن Docker/Devcontainer قابل اجرای محلی؛ یا
3. تمرکز فعلی روی اصلاحات مستندات، تست‌های JS/Python و کدنویسی static تا وقتی محیط PHP فراهم شود.

بدون اجرای PHPUnit و migrationها، تغییرات DB/RLS پرریسک‌اند.

### اولویت 1 — همگام‌سازی مستندات وضعیت

قبل از ساخت قابلیت جدید، `docs/TODO.md` باید با وضعیت فعلی `HANDOFF.md` و کد reconcile شود:

- علامت‌گذاری Procurement به‌عنوان partially built/done پایه‌ای
- علامت‌گذاری ADR-006 phases 1/1b/2/3 به‌عنوان built
- جداکردن backlog واقعی از موارد قدیمی
- ساخت یک roadmap مرحله‌ای قابل اجرا

این کار کم‌ریسک است و نیاز به اجرای PHP ندارد.

### اولویت 2 — قابلیت کوچک و ارزشمند: PDFهای باقی‌مانده

اگر محیط PHP فراهم شود، کوچک‌ترین کارهای محصولی:

- PDF صورت‌وضعیت
- PDF دفتر نواقص
- PDFهای GRN/MIV در Procurement/materials

مزیت: زیرساخت PDF موجود است، دامنه محدود است، ارزش demo زیاد است.

### اولویت 3 — RFSU/PAC/FAC

زنجیره commissioning کامل‌تر می‌شود. طبق مستندات، `blocksReadyForStartUp()` وجود دارد و تست شده ولی هنوز در UI/flow مصرف نشده. این مسیر احتمالاً از ساخت یک ماژول جدید کوچک‌تر و منسجم‌تر است.

### اولویت 4 — PMS / piping class rules

برای پایپینگ و جلوگیری از ورود داده غلط ارزش زیادی دارد، ولی DB/RLS و migrationهای PostgreSQL-heavy دارد، پس فقط وقتی محیط تست کامل آماده باشد.

### اولویت 5 — engineering import phase 1

Excel/PCF/property export import می‌تواند ارزش تجاری بالایی داشته باشد، اما قبل از شروع بهتر است نمونه واقعی فایل‌ها از کارفرما/کاربر داشته باشیم.

---

## پیشنهاد عملی برای قدم بعدی همین جلسه

اگر موافق باشید، قدم بعدی کم‌ریسک و مفید این است:

1. `docs/TODO.md` را با وضعیت فعلی کد و `HANDOFF.md` به‌روزرسانی کنم.
2. یک `docs/ROADMAP-TO-MVP.md` بسازم که backlog را به فازهای قابل انجام تقسیم کند.
3. تغییرات را در یک branch جدا مثل `arena/roadmap-reconciliation` commit کنم.

بعد از آن، وقتی محیط اجرای PHP/Composer/PostgreSQL یا Docker فراهم شد، سراغ تغییرات محصولی می‌رویم.
