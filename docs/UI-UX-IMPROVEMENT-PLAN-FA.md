# برنامهٔ بهبود رابط کاربری و تجربهٔ کاربری — EPCC Platform

تاریخ: **2026-10-05**

## 1. تصمیم اجرایی

برای تست، پیش‌نمایش و demo از **داده‌های ساختگی اما واقعی‌نما** استفاده می‌کنیم. دادهٔ ساختگی باید سناریوهای کاری را نشان دهد، نه صرفاً رکوردهای تصادفی. هدف این است که کاربر در پیش‌نمایش بتواند یک پروژهٔ EPCC واقعی را حس کند: line list، مدارک، متریال، punch، certificate، و وضعیت پایپینگ.

همچنین چون اولویت محصولی فعلی تکمیل دیسیپلین پایپینگ است، UX جدید باید اول برای پایپینگ عمیق شود، اما الگوی آن برای همهٔ دیسیپلین‌های EPCC قابل تعمیم بماند.

---

## 2. مشکل فعلی رابط کاربری

برداشت از ساختار viewها و shell فعلی:

1. صفحات بیشتر شبیه registerهای فنی هستند تا یک محصول هدایت‌گر.
2. رنگ تیره و کنتراست زیاد، همراه با جدول‌های متراکم، حس «ابزار داخلی توسعه» می‌دهد.
3. اطلاعات زیاد همزمان نمایش داده می‌شود؛ hierarchy بصری ضعیف است.
4. مسیر کاربر مشخص نیست: کاربر نمی‌فهمد قدم بعدی چیست.
5. هر صفحه فرم‌ها، جدول‌ها و actionها را به شکل نسبتاً مستقل نشان می‌دهد؛ یک الگوی یکپارچهٔ page template کم‌رنگ است.
6. sidebar خوب است که authorization-aware است، اما برای کاربر نهایی هنوز زیاد technical و registerمحور است.
7. statusها، badgeها، empty stateها و error/action messages باید استانداردتر و محصولی‌تر شوند.

---

## 3. اصل طراحی پیشنهادی

### 3.1 از register-first به task-first

EPCC ذاتاً register زیاد دارد، اما کاربر نباید با «دفترها» تنها بماند. هر register باید با taskهای اصلی همراه باشد:

- «چه چیزی گیر کرده؟»
- «چه چیزی امروز باید انجام شود؟»
- «چه چیزی آمادهٔ release/approval است؟»
- «چه چیزی مانع MCC/RFSU است؟»

### 3.2 هر صفحه باید سه سطح داشته باشد

1. **Context header**: پروژه، دیسیپلین، subsystem/area، pack، وضعیت کلی
2. **Action strip**: کارهای اصلی همان نقش
3. **Register/detail**: جدول یا فرم اصلی

### 3.3 اولویت پایپینگ

برای پایپینگ، صفحه‌ها باید journey را نشان دهند:

```text
Line → Components/Ports → Joints → Fit-up/Weld/NDT → Test Pack → Completion Readiness
```

کاربر باید در هر لحظه بداند line یا subsystem در کدام مرحله گیر کرده است.

---

## 4. دادهٔ ساختگی برای تست و پیش‌نمایش

### 4.1 ویژگی‌های دادهٔ ساختگی خوب

دادهٔ ساختگی باید:

- فارسی و انگلیسی داشته باشد؛
- Jalali/Gregorian date داشته باشد؛
- چند company role را پوشش دهد: Owner، EPC، PMC، TPI، Vendor؛
- حالت‌های مثبت و منفی داشته باشد، نه فقط دادهٔ تمیز؛
- خطاهای واقعی پایپینگ را نشان دهد، مثل mismatch rating یا missing NDT؛
- سناریوهای authorization را نشان دهد؛
- در preview قابل reset باشد.

### 4.2 پروژهٔ demo پیشنهادی

یک پروژهٔ demo ثابت:

```text
Project: PH-14 South Pars Gas Compression
Code: PH-14
Default pack: ips-iran
Language default: fa
```

### 4.3 دادهٔ پایپینگ demo

حداقل dataset:

- 3 facility
- 6 area
- 12 subsystem
- 40 piping lines
- 120 components
- 180 ports
- 95 joints
- 20 welded joints requiring NDT
- 5 rating mismatch warnings/errors
- 4 missing material cases
- 3 lines blocked from AFC
- 2 test packs
- 1 subsystem ready for MCC
- 1 subsystem blocked by A-punch

### 4.4 سناریوهای demo

1. **Line AFC gate**: یک line به‌خاطر مدرک B-code یا missing IFC block شود.
2. **Nozzle mismatch**: flange 150# به nozzle 300# وصل شود و INVALID بگیرد.
3. **Material readiness**: line آمادهٔ نصب نیست چون gasket/bolt هنوز site نشده.
4. **Weld/NDT**: joint fit-up شده اما NDT result ندارد.
5. **Test pack readiness**: test pack به‌خاطر punch A یا weld repair باز block است.

---

## 5. پیشنهادهای UI مشخص

### 5.1 Dashboard نقش‌محور

به‌جای اینکه کاربر بعد از login مستقیم وارد یک register شود، role dashboard داشته باشد:

- Project Manager: blockers, approvals, overdue
- Piping Lead: line readiness, PMS deviations, test pack status
- Document Controller: reviews, transmittals, overdue acknowledgements
- QC Inspector: inspections, punch, NDT, test packs
- Warehouse: missing/available materials

### 5.2 صفحهٔ پایپینگ جدید به‌صورت workspace

برای `/projects/{code}/piping` پیشنهاد:

- بالای صفحه: summary cards
  - Lines total
  - AFC ready
  - Blocked
  - PMS deviations
  - Test packs ready
- تب‌ها:
  - Lines
  - Model
  - Joints
  - Weld/NDT
  - Test Packs
  - Deviations
- فیلترهای سریع:
  - Blocked only
  - Ready for next step
  - My company
  - By subsystem
  - By piping class

### 5.3 Detail drawer به‌جای رفت‌وبرگشت زیاد

برای line/joint/component، به‌جای رفتن به صفحات زیاد، یک side drawer یا detail panel باز شود:

- Summary
- Blockers
- Documents
- Materials
- Joints
- Actions

این کار آشفتگی page navigation را کم می‌کند.

### 5.4 Standard page templates

چهار template ثابت تعریف شود:

1. Register page
2. Detail page/drawer
3. Workflow action page
4. Dashboard page

هر view جدید باید یکی از این‌ها باشد.

### 5.5 رنگ و visual hierarchy

فعلاً dark theme سنگین است. پیشنهاد:

- حالت پیش‌فرض demo: light یا neutral theme
- dark theme به‌عنوان preference
- رنگ‌ها معنی ثابت داشته باشند:
  - سبز: ready/approved
  - زرد: warning/deviation
  - قرمز: blocker/rejected
  - بنفش/آبی: informational/configuration
- background صفحه روشن‌تر و cardها کم‌کنتراست‌تر شوند.

### 5.6 Empty states واقعی

به‌جای جدول خالی:

- توضیح کوتاه «هنوز line وارد نشده»
- CTA اصلی «Import line list» یا «Create first line»
- لینک help کوتاه

### 5.7 Action labels کاربرپسند

به‌جای actionهای فنی:

- `transition` → «ارسال به مرحله بعد»
- `attach evidence` → «افزودن مدرک پشتیبان»
- `publish` → «انتشار رسمی»
- `deviation` → «ثبت مغایرت فنی»

### 5.8 Breadcrumb و context ثابت

بالای هر صفحه:

```text
Project / Facility / Area / Subsystem / Discipline
```

در فارسی RTL درست mirror شود.

### 5.9 Guided demo mode

یک toggle برای demo:

```text
حالت راهنما / Guided demo
```

وقتی روشن است، هر صفحه یک box کوچک نشان دهد:

- این صفحه چه کاری می‌کند؟
- برای demo چه چیزی را امتحان کن؟
- کدام داده عمداً خراب است؟

### 5.10 Command palette / global search بعداً

برای محصول نهایی، کاربر باید بتواند سریع بزند:

```text
PH-14-L-1001
MCC SS-2201
TR-0004
```

و مستقیم به object برسد. برای MVP اول می‌توان فقط quick search داخل project ساخت.

---

## 6. پیشنهاد Information Architecture

Sidebar فعلی authorization-aware است و باید حفظ شود، اما ساختارش بهتر است role/taskمحور شود:

```text
Overview
My Work
Engineering
  Documents
  Piping Workspace
  Transmittals
Procurement & Materials
  Requisitions
  Warehouse
Quality & Completion
  Punch
  Test Packs
  Certificates
Admin / Setup
  Team
  Project Setup
```

برای planned/locked همچنان distinction فعلی حفظ شود.

---

## 7. پیشنهاد فنی برای پیاده‌سازی UI بدون ریسک زیاد

1. اول design tokens را در یک partial مرکزی جمع کنید.
2. componentهای Blade کوچک بسازید:
   - `x-page-header`
   - `x-summary-card`
   - `x-status-badge`
   - `x-action-button`
   - `x-empty-state`
   - `x-filter-bar`
3. قبل از بازنویسی همهٔ صفحات، فقط Piping Workspace را با الگوی جدید بسازید.
4. اگر الگو جواب داد، Documents/Punch/Materials را migrate کنید.
5. تست‌های shell و tabletools حفظ شوند.

---

## 8. پیشنهادهای UX مخصوص پایپینگ

### 8.1 Line readiness bar

برای هر line یک نوار readiness:

```text
Documents ✓ | Model ✓ | Materials ! | Weld/NDT × | Test Pack - | Completion -
```

### 8.2 Blocker-first view

پایپینگ lead بیشتر می‌خواهد بداند چه چیزی گیر کرده است. پس default filter بهتر است:

```text
Blocked / Needs attention
```

نه همهٔ lineها.

### 8.3 Deviation inbox

همهٔ PMS و compatibility warnings در یک inbox:

- severity
- object
- reason
- suggested fix
- waiver/deviation action

### 8.4 Test pack board

Test packها به‌صورت board:

```text
Draft → Building → Ready for Hydrotest → Tested → Reinstatement → Closed
```

### 8.5 Visual traceability chain

برای یک joint:

```text
Line → Component A/Port → Joint → Component B/Port → Weld/NDT → Material heat → Test pack → Subsystem
```

این chain برای demo بسیار قدرتمند است.

---

## 9. اولویت اجرا

1. Demo seed/fake data strategy
2. Piping Workspace UX mock داخل Blade یا prototype
3. Design tokens و componentهای Blade پایه
4. Summary cards و blocker-first line register
5. Detail drawer برای line
6. Deviation inbox
7. Test pack board
8. سپس migrate صفحات دیگر

---

## 10. جمع‌بندی

رابط کاربری فعلی از نظر فنی قابل استفاده است، اما برای کاربر نهایی آشفته و registerمحور است. مسیر بهتر این است که با دادهٔ ساختگی واقعی‌نما و یک Piping Workspace جدید، تجربه را از «دیدن جدول‌ها» به «حل‌کردن کارهای پروژه» تبدیل کنیم.
