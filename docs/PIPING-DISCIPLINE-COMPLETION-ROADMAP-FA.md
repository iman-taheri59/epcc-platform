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
