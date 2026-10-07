# زیرساخت برنامه‌ریزی محصول و فروش نرم‌افزار — EPCC Platform

تاریخ: **2026-10-05**

## 1. تصمیم راهبردی

زیرساخت‌های برنامه‌ریزی محصول، فروش، استقرار، فعال‌سازی، پشتیبانی و رشد تجاری باید از ابتدا طراحی شوند. اولویت بازار، **ایران** است؛ یعنی محصول باید برای فروش on-premise/air-gapped، فارسی، جلالی، ریال/تومان، قراردادهای سازمانی، استقرار در دیتاسنتر مشتری و محدودیت‌های تحریم آماده باشد. در عین حال، معماری تجاری نباید راه نسخه‌های بین‌المللی را ببندد.

اصل تصمیم:

```text
Iran-first commercialization now; international-ready commercial architecture later.
```

این سند مکمل اسناد زیر است:

- `docs/Iran-First-Market-Addendum.md`
- `docs/Infra-Cost-Model-Iran.md`
- `docs/ROADMAP-TO-MVP.md`
- `docs/PIPING-DISCIPLINE-COMPLETION-ROADMAP-FA.md`
- `docs/UI-UX-IMPROVEMENT-PLAN-FA.md`

---

## 2. هدف زیرساخت فروش و planning

هدف فقط «ساخت نرم‌افزار» نیست؛ هدف ساخت محصولی است که بتوان آن را:

1. به مشتری ایرانی نشان داد؛
2. برای آن proposal و قیمت داد؛
3. در محیط بستهٔ مشتری نصب کرد؛
4. با لایسنس/activation کنترل کرد؛
5. آموزش داد؛
6. پشتیبانی کرد؛
7. تمدید یا توسعه داد؛
8. بعداً برای بازار بین‌المللی بسته‌بندی کرد.

---

## 3. نیازمندی‌های بازار ایران

### 3.1 مدل استقرار

برای ایران، مسیر اصلی فروش:

```text
On-premise / Private cloud / Air-gapped installation
```

نیازمندی‌ها:

- نصب بدون وابستگی به اینترنت خارجی؛
- offline update bundle؛
- مستند نصب فارسی؛
- health check و `verify:airgap`؛
- backup/restore پروژه؛
- امکان نصب روی دیتاسنتر کارفرما یا EPC؛
- عدم وابستگی به CDN، Google Fonts، سرویس آواتار خارجی، API خارجی؛
- license activation قابل انجام در حالت offline.

### 3.2 مدل قیمت‌گذاری ایران

قیمت‌گذاری باید با رفتار خرید سازمان‌های ایرانی سازگار باشد. پیشنهاد:

| SKU | توضیح | مدل قیمت |
|---|---|---|
| Project License | لایسنس پروژه، محصول پرچم‌دار | مبلغ یک‌باره بر اساس اندازه پروژه |
| Company Plan | Full / Special برای هر شرکت مشارکت‌کننده | سالانه یا یک‌باره با پشتیبانی |
| Implementation Package | نصب، پیکربندی، آموزش اولیه | service fee |
| Support & Maintenance | پشتیبانی، update، backup review | سالانه |
| Add-ons | BIM viewer، AWP international، AI، OCR | مرحله‌ای |

برای نسخه ایران، بهتر است ارزش اصلی روی **Project License + Implementation + Support** باشد، نه SaaS ماهانه.

### 3.3 قرارداد و خرید

نیازمندی‌های فروش سازمانی ایران:

- proposal فارسی؛
- پیش‌فاکتور/صورت‌حساب رسمی؛
- قرارداد پشتیبانی؛
- SLA فارسی؛
- RFP response template؛
- security checklist؛
- air-gap compliance checklist؛
- برنامهٔ آموزش؛
- صورتجلسه تحویل نصب؛
- گواهی پذیرش UAT؛
- برنامهٔ مهاجرت/ورود داده.

### 3.4 مشتری هدف اولیه

سه دستهٔ هدف:

1. **Owner/NOC affiliate** — ارزش اصلی: کنترل، محرمانگی، completion، turnover، claims evidence.
2. **EPC contractor** — ارزش اصلی: مدارک، متریال، پایپینگ، صورت‌وضعیت، کاهش دوباره‌کاری.
3. **PMC/MC/TPI** — ارزش اصلی: review، inspection، punch، audit trail.

### 3.5 پیام فروش ایران

پیام نباید «یک نرم‌افزار مدیریت پروژه عمومی» باشد. پیام بهتر:

```text
سامانهٔ ایرانی، درون‌سازمانی و دوزبانه برای کنترل مهندسی، متریال، پایپینگ، کیفیت و تحویل پروژه‌های EPCC؛ با تمرکز بر اسناد قراردادی، محرمانگی بین شرکت‌ها و آمادگی تکمیل مکانیکی.
```

برای فاز اول demo:

- Document lifecycle
- Transmittal
- Procurement/materials
- Piping workspace
- Punch/MCC
- Persian PDF
- RLS/visibility بین شرکت‌ها

---

## 4. زیرساخت licensing و activation

### 4.1 نیازمندی‌ها

- لایسنس offline؛
- machine/site fingerprint؛
- project license count؛
- company plan entitlement؛
- module/add-on entitlement؛
- expiry/support period؛
- signed license file؛
- activation request/response برای محیط air-gapped؛
- audit of license changes.

### 4.2 مدل پیشنهادی

```text
license.epcc.json + signature
```

محتوا:

- customer legal name
- installation id
- allowed projects
- allowed companies/plans
- modules/add-ons
- support expiry
- issued_at / expires_at
- signature

### 4.3 اصل مهم

License enforcement نباید RLS/security را پیچیده کند. RLS برای محرمانگی است؛ entitlement/licensing برای دسترسی محصولی و تجاری.

---

## 5. زیرساخت demo و sales engineering

### 5.1 Demo seed

با تصمیم قبلی، برای preview از دادهٔ ساختگی استفاده می‌شود. برای فروش باید یک demo seed رسمی داشته باشیم:

```text
PH-14 South Pars Gas Compression Demo
```

باید شامل:

- نقش‌های مختلف شرکت‌ها؛
- دادهٔ فارسی/انگلیسی؛
- سناریوهای سالم و خراب؛
- پایپینگ کامل در سطح demo؛
- خروجی PDF؛
- workflowهای قابل نمایش زیر ۱۵ دقیقه.

### 5.2 Demo scripts

برای هر persona یک سناریوی demo:

| Persona | سناریوی demo |
|---|---|
| Owner | دیدن وضعیت پروژه، blockers، MCC readiness، محرمانگی |
| EPC | ورود line، متریال، رفع blocker، ارسال مدرک |
| PMC/MC | review، punch، approval، audit |
| TPI | inspection، NDT/test pack evidence |
| Warehouse | receipt/issue/site delivery |

### 5.3 Sales collateral

- بروشور فارسی ۲ صفحه‌ای؛
- deck معرفی ۱۰ اسلاید؛
- ماتریس ارزش برای Owner/EPC/PMC؛
- مقایسه با Excel/SharePoint/Primavera-only approach؛
- FAQ امنیت و on-prem؛
- قیمت‌نامه داخلی؛
- checklist آمادگی pilot.

---

## 6. زیرساخت product planning

### 6.1 ورودی‌های planning

Backlog نباید فقط از ایده‌های فنی بیاید. ورودی‌ها:

- مشتری pilot؛
- نیازمندی‌های قرارداد/RFP؛
- demo feedback؛
- support tickets؛
- ADRها؛
- SRS؛
- ریسک‌های بازار ایران؛
- مسیر بین‌المللی.

### 6.2 فازبندی محصول

پیشنهاد release lanes:

| Lane | هدف |
|---|---|
| P0 Pilot | demo/pilot قابل نصب با داده ساختگی و چند journey واقعی |
| P1 Iran MVP | پایپینگ عمیق، مدارک، متریال، punch/MCC، نصب on-prem |
| P2 Iran Enterprise | HSE، Schedule حداقلی، LDAP، backup/export، reporting |
| P3 International Ready | multi-currency، SaaS readiness، AWP activation، English-first sales |
| P4 International Scale | marketplace/add-ons، integrations، AI/OCR advanced |

### 6.3 اولویت‌بندی

هر آیتم backlog باید این scoring را داشته باشد:

- ارزش فروش ایران؛
- کاهش ریسک pilot؛
- وابستگی فنی؛
- قابلیت demo؛
- قابلیت reuse برای دیسیپلین‌های دیگر؛
- آمادگی برای international؛
- هزینه پشتیبانی on-prem.

---

## 7. زیرساخت پیاده‌سازی و تحویل مشتری

### 7.1 Implementation methodology

برای هر مشتری:

1. Discovery workshop
2. Project setup
3. Standards pack selection
4. Company/role setup
5. Demo seed walkthrough
6. Data import pilot
7. User training
8. UAT
9. Go-live
10. Hypercare

### 7.2 مدارک تحویل

- Installation report
- Configuration report
- License certificate
- Admin guide FA
- User quick guide FA
- Backup/restore procedure
- Air-gap verification report
- UAT sign-off
- Support contact/SLA

---

## 8. پشتیبانی و عملیات فروش ایران

### 8.1 Support model

- سطح 1: راهبری کاربر و آموزش؛
- سطح 2: پیکربندی، گزارش، داده؛
- سطح 3: bug/security/update؛
- SLA جدا برای ساعات اداری و پروژه‌های critical.

### 8.2 On-prem support risk

بزرگ‌ترین ریسک تجاری on-prem ایران، هزینه پشتیبانی است. باید از ابتدا:

- diagnostic bundle؛
- log export؛
- health check؛
- version report؛
- backup verification؛
- update rollback؛
- admin training؛

ساخته شود.

---

## 9. آمادگی بین‌المللی در نسخه‌های بعدی

نباید برای international فعلاً محصول را پیچیده کنیم، اما باید مسیر را نبندیم.

### 9.1 چیزهایی که از الان باید neutral بمانند

- currency model؛
- calendar model؛
- language/resource pipeline؛
- standards pack engine؛
- company/project licensing model؛
- units of measure؛
- discipline registry؛
- audit/export؛
- deployment topology.

### 9.2 چیزهایی که بعداً اضافه می‌شوند

- SaaS billing؛
- online activation؛
- international payment processor؛
- English-first marketing site؛
- AWP methodology activation؛
- GDPR/data residency controls؛
- SOC2/ISO27001 evidence pack؛
- partner/channel program؛
- cloud marketplace packaging.

---

## 10. تغییرات پیشنهادی در محصول برای فروش‌پذیری

### 10.1 درون محصول

- صفحه About/License؛
- health check page؛
- demo mode؛
- sample data reset؛
- admin onboarding checklist؛
- usage/seat/project summary؛
- exportable system report.

### 10.2 بیرون محصول

- sales deck؛
- website/landing فارسی؛
- demo video کوتاه؛
- proposal template؛
- RFP response library؛
- implementation SOW template؛
- SLA/support contract template.

---

## 11. Backlog فروش و planning

| اولویت | آیتم | توضیح |
|---|---|---|
| 1 | Demo seed رسمی | داده ساختگی واقعی‌نما برای PH-14 |
| 2 | Piping workspace demo | محور demo فروش ایران |
| 3 | License file design | signed offline license |
| 4 | Installer / runtime environment | نصب قابل تکرار on-prem |
| 5 | Admin guide FA | برای تحویل pilot |
| 6 | Sales deck FA | برای جلسات اولیه |
| 7 | Pilot checklist | معیار آمادگی و success criteria |
| 8 | Support diagnostics | کاهش هزینه پشتیبانی on-prem |
| 9 | Proposal/SOW templates | سرعت فروش سازمانی |
| 10 | International readiness checklist | جلوگیری از تصمیم‌های بن‌بست‌ساز |

---

## 12. جمع‌بندی

برای موفقیت بازار ایران، محصول باید علاوه بر کد، زیرساخت فروش و تحویل داشته باشد: demo seed، لایسنس offline، نصب on-prem، اسناد فروش فارسی، آموزش، support diagnostics و مدل قیمت‌گذاری. برای بازار بین‌المللی، فعلاً باید معماری تجاری را neutral نگه داریم و بعداً SaaS billing، AWP activation، compliance و کانال‌های فروش را اضافه کنیم.
