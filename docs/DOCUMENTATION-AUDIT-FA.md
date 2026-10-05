# ممیزی مستندات و ADRها — EPCC Platform

تاریخ: **2026-10-05**  
دامنه: همهٔ فایل‌های Markdown اصلی در `docs/`، همهٔ ADRها، اسناد وضعیت، SRS، معماری، نقشهٔ پوشش، RLS spike، هزینه زیرساخت و افزونه بازار ایران.

> این سند یک **ممیزی مستندات** است، نه تأیید اجرای کامل کد. در sandbox فعلی PHP/Composer/PostgreSQL نصب نیست؛ بنابراین ادعاهای کدنویسیِ مستندات فقط با ساختار فایل‌ها، `HANDOFF.md` و تست‌های قابل اجرای Python/Node تطبیق داده شدند.

---

## 1. خلاصه اجرایی

پروژه از نظر مستندسازی قوی است: تصمیم‌ها با ADR، trapها و یافته‌های تجربی ثبت شده‌اند و `HANDOFF.md` نقش حافظهٔ فنی را خوب بازی می‌کند. مشکل اصلی این است که **چند سند مادر از مسیر واقعی پروژه عقب مانده‌اند**:

1. `Architecture-and-Data-Model.md` هنوز معماری TypeScript/NestJS/React/PostgreSQL 18 را به‌عنوان stack اصلی نشان می‌دهد، در حالی که تصمیم واقعی Laravel 13 + Filament + PostgreSQL است.
2. `SRS-EPCC-Platform.md` هنوز SaaS/multi-tenant را در کنار on-prem مطرح می‌کند؛ افزونهٔ Iran-first می‌گوید on-prem/air-gap و فارسی/Jalali/IRR constraint اصلی بازار اول هستند.
3. برخی ADRها بعداً ساخته شده‌اند اما خود ADR هنوز عبارت‌های قدیمی مثل «Nothing is implemented» دارد؛ مهم‌ترین نمونه `ADR-006` است.
4. `Prototype-Coverage-Map.md` احتمالاً با ساخت Procurement/materials و Piping عقب افتاده و نیاز به بازشماری دارد.
5. بعضی نیازمندی‌های SRS برای MVP بیش از حد وسیع‌اند؛ بهتر است به release phases و سطح بلوغ شکسته شوند.

---

## 2. ماتریس ADRها

| ADR | تصمیم/موضوع اصلی | وضعیت تصمیم | وضعیت پیاده‌سازی فعلی | اقدام پیشنهادی |
|---|---|---|---|---|
| ADR-001 AWP Packaging | AWP به‌صورت project-level add-on، نه per-company entitlement | پذیرفته/بسته | موتور constraint و pack seam وجود دارد؛ methodology موکول است | نگه‌داری در CI؛ SRS/licensing متناظر را با project-level بودن همگام کنید |
| ADR-002 Defer AWP | methodology AWP برای فاز بین‌الملل deferred؛ machinery در core بماند | پذیرفته/بسته | `awp` pack و seam validation موجود است؛ AWP تنها planned module اصلی است | یک smoke test پروژهٔ AWP در CI اضافه شود اگر هنوز نیست |
| ADR-003 Technology Stack | TypeScript/NestJS/React/PostgreSQL 18 | **Superseded** توسط Annex A | stack واقعی Laravel/Filament است | سند را با banner «superseded» شروع کنید تا خواننده گمراه نشود |
| ADR-003 Annex A | Laravel 13 + Filament v4 به‌عنوان انتخاب واقعی backend | پذیرفته/بسته | app واقعی Laravel است | Architecture و SRS باید stack section خود را بر اساس Annex بازنویسی کنند |
| ADR-004 Physical Breakdown | project → facility → optional area → subsystem | پذیرفته و ساخته‌شده | migration/model/UI/admin طبق `HANDOFF` ساخته شده | خود ADR را با «Accepted and built» در ابتدای سند علامت‌گذاری کنید |
| ADR-005 Engineering Data Ingestion | baseline خنثی، import batches immutable، preview→commit، PCF/property export | Proposed / آمادهٔ تصمیم | ساخته نشده | قبل از build نمونه فایل واقعی بگیرید؛ NWD/NWC/NWF را صریحاً out-of-scope نگه دارید |
| ADR-006 Piping Model | lines/components/ports/joints، numbering، compatibility، dia-inch | پذیرفتهٔ ضمنی؛ **سند stale** | phases 1/1b/2/3 ساخته شده؛ phase 4+ باقی | انتهای سند هنوز می‌گوید Nothing implemented؛ باید به «Partially built» تغییر کند |
| ADR-007 Reference Data | reference packs read-only برای sizes/schedules/end types/templates | پذیرفته و ساخته‌شده | validate packs سبز؛ reference packs موجود | در ابتدای ADR وضعیت Accepted/Built اضافه شود |
| ADR-008 PMS Rules | DN half-open ranges، exclusion constraint، branch specificity wins | Proposed | ساخته نشده | بعد از محیط PostgreSQL کامل بسازید؛ این migration بدون تست PG پرریسک است |
| ADR-009 Manual Modelling | template-driven component creation؛ PCF import format، Plant 3D conceptual model | تا حد زیادی پذیرفته و ساخته‌شده | manual modelling/components/ports ساخته شده؛ import نه | سند را تفکیک کنید: بخش modelling built، بخش PCF import pending |
| ADR-010 Equipment/Nozzles/Dia-inch | dia-inch = قطر کوچک‌تر؛ nozzle مالک port؛ equipment phase 3b | Proposed / partially realised | dia-inch ساخته شده؛ equipment/nozzles نه | سه open question را قبل از phase 3b ببندید: tag uniqueness، instruments، numbering/import |
| ADR-011 Database per Project | shared database + RLS، نه database-per-project | توصیه پذیرفته‌شده | current architecture shared DB/RLS است | با ADR-012 و backup/export plan cross-reference شود |
| ADR-012 RLS Escape Hatch | performance issue در RLS policy؛ تغییر predicate برای scale | Proposed / **حساس امنیتی** | unclear if implemented | قبل از اجرا testهای correctness و EXPLAIN guard لازم است؛ این مورد را MVP فوری نکنید مگر bottleneck واقعی شود |
| ADR-013 Backup/Export/Delete | archive پیش‌فرض؛ hard delete ceremonial؛ export هنوز پیشنهادی | archive پذیرفته و ساخته‌شده | archive ساخته شده؛ export/hard delete نه | export را برای Pilot readiness بالا ببرید؛ hard delete را همچنان ceremonial نگه دارید |
| ADR-014 Units | entered + canonical units، user/project preferences | پذیرفته و ساخته‌شده | units layer و UI ساخته شده | این الگو را برای هر فیلد فیزیکی آینده mandatory کنید |

---

## 3. ماتریس اسناد غیر-ADR

| سند | نقش | وضعیت | مشکل/اقدام |
|---|---|---|---|
| `README.md` | نقشهٔ ورود به مخزن | خوب ولی باید با اسناد جدید لینک شود | لینک به `ROADMAP-TO-MVP-*.md` و auditها اضافه شود |
| `HANDOFF.md` | حافظهٔ فنی و منبع معتبر وضعیت واقعی | معتبرترین سند فعلی | طولانی است؛ بهتر است index/status table در ابتدای آن اضافه شود |
| `docs/TODO.md` | وضعیت done/not done | به‌روزرسانی شد | باید بعد از هر session همراه HANDOFF آپدیت شود |
| `docs/ROADMAP-TO-MVP*.md` | فازبندی MVP | جدید و همسو با وضعیت فعلی | بعد از تصمیم محیط runtime باید Phase 0 دقیق‌تر شود |
| `SRS-EPCC-Platform.md` | requirements مادر | **stale در چند بخش** | stack، market، packs، MVP phases و scope باید reconcile شوند |
| `Architecture-and-Data-Model.md` | معماری مادر | **stale در stack و بعضی الگوها** | NestJS/React/PostgreSQL18 sections باید با Laravel/Filament جایگزین یا superseded شوند |
| `Iran-First-Market-Addendum.md` | تصمیم بازار ایران | معتبر و باید بر SRS غالب باشد | خوب است؛ ولی SRS باید این تصمیم را جذب کند تا conflict کمتر شود |
| `Prototype-Coverage-Map.md` | پوشش prototype/app نسبت به SRS | احتمالاً stale | Procurement و Piping ساخته‌شده باید در coverage map بازتاب پیدا کند |
| `Spike-RLS-Results.md` | قواعد RLS و یافته‌های spike | بسیار مهم و هنوز معتبر | با ADR-012 cross-reference شود؛ policy تغییرات آینده باید اینجا ثبت شود |
| `Infra-Cost-Model-Iran.md` | مدل هزینه زیرساخت ایران | مفید | اگر stack نهایی Laravel memory footprint را تغییر داده، اعداد باید بازبینی شوند |
| `docs/uml/*` | نمودارهای فنی | مفید برای procurement/materials و ingestion proposal | نیاز به index دارد که بگوید کدام نمودار current است و کدام proposed |

---

## 4. تناقض‌ها و staleهای مهم

### 4.1 Stack مادر هنوز TypeScript است

`Architecture-and-Data-Model.md` هنوز می‌گوید Backend = NestJS on Node 24 و React SPA. تصمیم واقعی در Annex A و کد، Laravel 13 + Filament است.

**پیشنهاد:** یک ADR supersession note در ابتدای Architecture اضافه شود یا بخش stack آن بازنویسی شود. بهترین مسیر: `Architecture-and-Data-Model.md` را به `Architecture-and-Data-Model-v2-Laravel.md` تبدیل نکنید؛ همان سند را اصلاح کنید تا دو حقیقت موازی ایجاد نشود.

### 4.2 SRS pack list با بازار ایران نمی‌خواند

SRS هنوز از `ISO`, `AWP`, `Hybrid`, `Custom` صحبت می‌کند. سیستم واقعی و README از `ips-iran`, `iso`, `awp` صحبت می‌کنند.

**پیشنهاد:** برای launch، `ips-iran` default باشد؛ `hybrid/custom` به later phase یا admin-only import منتقل شود.

### 4.3 ADR-006 خودش را اجرا نشده معرفی می‌کند

ADR-006 در انتها می‌گوید «Nothing is implemented»، اما phases 1/1b/2/3 ساخته شده‌اند.

**پیشنهاد:** ابتدای ADR یک status block اضافه شود:

```text
Status: Accepted, partially built.
Built: phases 0, 1, 1b, 2, 2b, 3.
Pending: fit-up, supports/spools, PCF import, roll-ups, welding/NDT.
```

### 4.4 Architecture اصل «هر module owns schema و no cross-module SQL joins» را می‌گوید، اما Laravel modular monolith فعلی احتمالاً pragmatic‌تر است

این اصل برای آینده خوب است، ولی در Laravel monolith فعلی اگر خیلی سخت اعمال شود سرعت را کم می‌کند.

**پیشنهاد:** اصل را به «domain services own writes; read models may compose through explicit services/query objects» تغییر دهید. ممنوعیت مطلق cross-module SQL برای MVP زیاد سخت‌گیرانه است.

### 4.5 SRS سطح M/S/C دارد ولی release phase ندارد

خیلی از موارد M هستند، اما برای MVP اول ایران نمی‌توان همه را ساخت.

**پیشنهاد:** هر requirement علاوه بر M/S/C یک `Release target` داشته باشد: `P0 Pilot`, `P1 MVP`, `P2 Enterprise`, `P3 International`.

---

## 5. پیشنهادهای بهتر برای نیازمندی‌ها

### 5.1 Workflow/BPM

**نیازمندی فعلی:** visual BPMN workflow designer به‌عنوان M.  
**پیشنهاد بهتر:** برای MVP، workflow designer نسازید. ابتدا state machineهای typed و audit شده برای objectهای اصلی بسازید؛ بعد یک workflow definition DSL کوچک اضافه کنید؛ BPMN visual designer را به فاز Enterprise منتقل کنید.

### 5.2 Cross-company visibility matrix

**نیازمندی فعلی:** matrix قابل تنظیم برای هر object type.  
**پیشنهاد بهتر:** برای MVP، matrix را pack-defined و محدود نگه دارید؛ override فقط برای object types منتخب و با approval/audit. آزادی کامل matrix می‌تواند RLS و تست‌ها را منفجر کند.

### 5.3 Every read audit

**نیازمندی فعلی:** هر read از confidential record و هر export log شود.  
**پیشنهاد بهتر:** audit کامل برای export/download/permission change و readهای labeled-confidential. برای همهٔ readها sampling/aggregation یا access ledger جدا لازم است؛ logging هر SELECT می‌تواند performance و storage را نابود کند.

### 5.4 Digital signature

**نیازمندی فعلی:** PAdES/CAdES و national PKI به‌عنوان M.  
**پیشنهاد بهتر:** برای MVP، PDF snapshot + watermark + approval audit کافی است. امضای دیجیتال ملی/PKI را بعد از روشن‌شدن مشتری pilot و الزامات حقوقی‌اش بسازید.

### 5.5 OCR/Search

**نیازمندی فعلی:** full-text + metadata + OCR EN/FA.  
**پیشنهاد بهتر:** سه فاز جدا کنید: metadata search اول، full-text extracted PDFs دوم، OCR فارسی سوم. OCR فارسی کیفیت/هزینه/air-gap خاص خودش را دارد.

### 5.6 Offline field app

**نیازمندی فعلی:** offline-capable field data capture.  
**پیشنهاد بهتر:** قبل از ساخت اپ، sync contract را با conformance corpus تعریف کنید: conflict rules، queue replay، package snapshot، attachment caching، rejection handling. Offline بدون این contract تبدیل به bug factory می‌شود.

### 5.7 SSO/MFA

**نیازمندی فعلی:** SAML/OIDC/LDAP/Google/Azure + MFA/WebAuthn/SMS.  
**پیشنهاد بهتر:** برای بازار ایران/on-prem: local auth + LDAP/AD اول؛ TOTP بعد؛ SMS fallback فقط اگر provider داخلی و حالت غیر-air-gap تعریف شود. Google/Azure در air-gap/تحریم نباید مسیر اصلی باشد.

### 5.8 BIM/NWD viewer

**نیازمندی فعلی:** viewer برای NWD/RVT/IFC و غیره.  
**پیشنهاد بهتر:** direct parse/upload برای NWD وعده داده نشود. مسیر درست: property export برای داده و conversion pipeline جدا برای viewer. ADR-005 همین را درست می‌گوید؛ SRS باید با آن همسو شود.

### 5.9 Standards Pack Editor

**نیازمندی فعلی:** no-code editor با diff/version/sandbox.  
**پیشنهاد بهتر:** ابتدا signed/importable packs + validation CLI + fixtures. Editor را بعداً بسازید. Editor زودهنگام سطح خطای خطرناک ایجاد می‌کند.

### 5.10 Procurement depth

**نیازمندی فعلی:** broad procurement module.  
**پیشنهاد بهتر:** برای Iran-first، procurement را با material traceability و warehouse/site readiness عمیق کنید، نه با RFQهای پیچیده در ابتدا. RFQ مهم است ولی field readiness و MTC/heat traceability زودتر demo value می‌دهد.

### 5.11 Schedule module

**نیازمندی فعلی:** P6/MSP sync، delay analysis، Monte Carlo.  
**پیشنهاد بهتر:** ابتدا lookahead و constraint readiness مرتبط با package/material/punch را بسازید. P6 sync بدون data quality و WBS mapping دقیق، خروجی نمایشی می‌دهد نه کنترل واقعی.

### 5.12 HSE

**نیازمندی فعلی:** هفت حوزه HSE.  
**پیشنهاد بهتر:** HSE MVP را به PTW + Incident + Observation + Toolbox محدود کنید. JSA/training/environment را بعد از اثبات privacy model و approval flow اضافه کنید.

---

## 6. پیشنهاد ترتیب اصلاح مستندات

1. `Architecture-and-Data-Model.md` را با Laravel/Filament stack اصلاح کنید.
2. `SRS-EPCC-Platform.md` را با Iran-first و release phases همسو کنید.
3. `ADR-006`، `ADR-009` و `ADR-010` را status-block دار کنید تا built/pending روشن شود.
4. `Prototype-Coverage-Map.md` را با Procurement و Piping بازشماری کنید.
5. `README.md` را با لینک به roadmap و audit جدید به‌روزرسانی کنید.
6. برای هر ADR یک status metadata ثابت تعریف کنید:

```text
Status: Proposed | Accepted | Superseded | Built | Partially built | Deferred
Implementation: Not started | Partial | Complete
Supersedes:
Superseded by:
Last reviewed:
```

---

## 7. جمع‌بندی

جهت کلی پروژه درست است: Iran-first، Laravel/Filament، RLS واقعی، standards packs، CDE، commissioning و piping. بزرگ‌ترین خطر فعلی کدنویسی نیست؛ **drift مستندات مادر** است. اگر SRS و Architecture با `HANDOFF.md` و کد فعلی همگام نشوند، تصمیم‌های بعدی ممکن است بر اساس واقعیت قدیمی گرفته شوند.

پیشنهاد من: قبل از feature بزرگ بعدی، یک pass کوتاه برای اصلاح Architecture و SRS انجام شود؛ سپس Phase 0 runtime environment و بعد featureهای کوچک PDF/RFSU ادامه پیدا کند.
