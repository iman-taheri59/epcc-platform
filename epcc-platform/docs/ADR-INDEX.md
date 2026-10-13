# فهرست تصمیم‌های معماری

قاعده الزامی: [ADR-PROCESS](ADR-PROCESS.md). پیش از توسعه، متن ADR موضوع و اصلاحیه‌های آن را بخوانید. این فهرست به‌تنهایی تأییدِ محتوای تمام اسناد یا وضعیت اجرایی آن‌ها نیست.

## وضعیت تازه

- [تکمیل عملیاتی مدیریت متریال](ADR-018-Material-Management-Operational-Completion.md): نویسندگان پروژه و مدیر تنظیمات کارفرما تأیید شدند؛ فرم پیش‌نویس چندقلمی، تاریخچه مجوزها و اصلاح شماره‌گذاری بین شرکت‌ها پیاده شد. چرخه کامل متریال هنوز بسته نیست.

- [ثبت ساختاریافته الزامات NDT در PMS](PMS-NDT-REQUIREMENTS-2026-09-29.md): گام اول پیشنهاد تأییدشده اجرا و آزموده شد؛ ADR-008 §25.1، ADR-015 §9 و ADR-013 §16. خارج از بازرسی متریال؛ پیوند ITP و اجرای NDT هنوز گام بعدی‌اند.

- **اصلاح دامنه NDT:** کاربر الزامات NDT را از حوزه بازرسی متریال خارج کرد؛ [ADR-008 §24](ADR-008-PMS-Piping-Class-Rules.md) و [ADR-015 §8](ADR-015-Inspection-and-Test-Plans.md). تصمیم ثبت شده، اجرای NDT هنوز انجام نشده؛ سوابق بازرسی و دروازه‌های موجود تغییر نکردند.

- [فرم شیرهای دستی PMS](PMS-MANUAL-VALVES-2026-09-29.md): انتخاب صریح کاربر؛ فرم تریم/ساخت/عملگر، تاریخچه و ورود گروهی اتمیک، همراه حفاظت SQL بایگانی پیاده و آزموده شد؛ ADR-008 §23.1 و ADR-013 §15. انتشار/AFC و NDT/ITP تغییر نکردند.

- [الزامات ویژه کلاس PMS](PMS-SPECIAL-REQUIREMENTS-2026-09-29.md): فرم حفظ/جایگزینی/پاک‌کردن، حفظ متن و تاریخچه و نمایش امن پیاده و آزموده شد؛ ADR-008 §22.1. فقط این بخش از PMS-ADR-06 تکمیل شد، نه NDT/ITP یا انتشار/AFC.

- [واحدهای هدر PMS](PMS-MEASUREMENTS-2026-09-29.md): مقدار/واحد اصلی، مقدار مرجع دقیق‌تر و نمایش طبق ترجیحات برای دما و خوردگی مجاز پیاده و آزموده شد؛ ADR-014 §14.1 / ADR-008 §21.1. بازنگری‌ها همچنان متوقف‌اند.

- [ورود گروهی PMS](PMS-BULK-ENTRY-2026-09-29.md): انتخاب سایزهای مستقل، پیش‌نمایش تعارض و ثبت اتمیک پیاده و آزموده شد؛ ADR-008 §20.1. بازبینی بازنگری‌ها به درخواست کاربر فعلاً متوقف است.

- [طرح تفصیلی بازنگری PMS و AFC](PMS-REVISION-DESIGN-2026-09-29.md): کاربر تعیین متولی را به تعویق انداخت و فقط طراحی خواست؛ ADR-008 §19. طرح پیشنهادی تهیه شد؛ کد، انتشار و پایگاه‌داده تغییر نکردند.

- [مجوز نویسندگان PMS](PMS-AUTHORING-POLICY-2026-09-29.md): افراد/نقش‌های قابل‌تنظیم پروژه، محدود به شرکت خود؛ بدون مجوز پیش‌فرض. پیش‌نیاز اجرا شده، نه سلسله‌مراتب سند/بازنگری PMS.

- [تحویل گام اول DR-01](DR01-REFERENCE-SNAPSHOTS-2026-09-29.md): تثبیت مبنای ارجاع CDE/ITP؛ PMS همچنان نیازمند اتصال واقعی نسخه سند است.

- [نقد تصمیم‌های ADR](ADR-DECISION-REVIEW-2026-09-29.md): شش مورد با پاسخ جداگانه کاربر و ثبت تصمیم در اصلاحیه‌های ADR؛ اختیار پذیرش استاندارد فعلاً باز است. تنها گام CDE/ITP از DR-01 پیاده و آزموده شده است.

- [ADR-008 §12](ADR-008-PMS-Piping-Class-Rules.md#12-pms-entry-and-source-links--reconciliation-2026-09-29): رابط اولیه PMS و فاصله‌های باز؛ تصمیم قبلیِ نسخه‌بندی سند تغییر نکرده است.
- [ADR-017](ADR-017-Project-Material-Review-and-Finding-Dispositions.md): ثبت یکپارچه تصمیم‌های تعاریف متریال و یافته‌ها.
- [تطبیق اولیه PMS](ADR-RECONCILIATION-2026-09-29.md): دامنه مطالعه، موارد منطبق و فاصله‌های پیاده‌سازی.

## اسناد

| فایل | عنوان |
|---|---|
| [ADR-001-AWP-Packaging.md](ADR-001-AWP-Packaging.md) | ADR-001 — How to package and licence AWP |
| [ADR-002-Defer-AWP.md](ADR-002-Defer-AWP.md) | ADR-002 — Defer AWP to the international phase |
| [ADR-003-Annex-A-Laravel-Evaluation.md](ADR-003-Annex-A-Laravel-Evaluation.md) | ADR-003 Annex A — Laravel evaluated as the backend stack |
| [ADR-003-Technology-Stack.md](ADR-003-Technology-Stack.md) | ADR-003 — Technology stack |
| [ADR-004-Physical-Breakdown-Plant-Pipeline-Subsystem.md](ADR-004-Physical-Breakdown-Plant-Pipeline-Subsystem.md) | ADR-EPCC-004 — Physical breakdown: Plant / Pipeline / Subsystem |
| [ADR-005-Engineering-Data-Ingestion.md](ADR-005-Engineering-Data-Ingestion.md) | ADR-EPCC-005 — Engineering data ingestion: Excel, 3D models, and the engineering baseline |
| [ADR-006-Piping-Line-Component-Joint-Model.md](ADR-006-Piping-Line-Component-Joint-Model.md) | ADR-EPCC-006 — Piping: lines, components, ports and joints |
| [ADR-007-Reference-Data-Engineering-Standards.md](ADR-007-Reference-Data-Engineering-Standards.md) | ADR-EPCC-007 — Engineering reference data (pipe sizes, schedules, ratings) |
| [ADR-008-PMS-Piping-Class-Rules.md](ADR-008-PMS-Piping-Class-Rules.md) | ADR-EPCC-008 — PMS piping classes: size-dependent rules and the branch table |
| [ADR-009-Manual-Modelling-and-Component-Templates.md](ADR-009-Manual-Modelling-and-Component-Templates.md) | ADR-EPCC-009 — Manual modelling, component templates, and the PCF-vs-Plant 3D question |
| [ADR-010-Equipment-Nozzles-and-Dia-Inch.md](ADR-010-Equipment-Nozzles-and-Dia-Inch.md) | ADR-EPCC-010 — Equipment and nozzles, custom component templates, and the dia-inch convention |
| [ADR-010-Equipment-Register.md](ADR-010-Equipment-Register.md) |  |
| [ADR-011-Database-Per-Project.md](ADR-011-Database-Per-Project.md) | ADR-EPCC-011 — One database per project? |
| [ADR-012-Scaling-Projects-and-the-RLS-Escape-Hatch.md](ADR-012-Scaling-Projects-and-the-RLS-Escape-Hatch.md) | ADR-EPCC-012 — Scaling with many projects: the RLS escape hatch is the bottleneck |
| [ADR-013-Project-Backup-Export-and-Deletion.md](ADR-013-Project-Backup-Export-and-Deletion.md) | ADR-EPCC-013 — Per-project backup, export and deletion |
| [ADR-014-Units-of-Measure.md](ADR-014-Units-of-Measure.md) | ADR-EPCC-014 — User-selectable units of measure |
| [ADR-015-Inspection-and-Test-Plans.md](ADR-015-Inspection-and-Test-Plans.md) | ADR-015 — Inspection and Test Plans (ITP) |
| [ADR-016-Standards-Library.md](ADR-016-Standards-Library.md) | ADR-016 — The library of codes and standards |
| [ADR-017-Project-Material-Review-and-Finding-Dispositions.md](ADR-017-Project-Material-Review-and-Finding-Dispositions.md) | ADR-017 — Project material declarations, review and finding dispositions |

## نکته شناسه‌های تاریخی

دو فایل با پیشوند ADR-010 در مخزن وجود دارد؛ در ارجاع از **نام کامل فایل** استفاده کنید. آن‌ها در این مرحله ادغام یا شماره‌گذاری مجدد نشده‌اند، چون تعیین رابطه جایگزینی نیازمند بازبینی محتوای هر دو و ارجاع‌هایشان است. فایل ADR-003-Annex-A نیز پیوست ADR-003 است، نه تصمیمی که با حذف سند اصلی جایگزین شود.

هنگام ایجاد ADR جدید، شماره آزاد و عنوان مشخص انتخاب کنید و این فهرست را به‌روز کنید. جدول بالا فهرست اسناد است، نه فهرست اسناد کاملاً بازخوانی‌شده؛ دامنه بازخوانی این مرحله در گزارش تطبیق آمده است.
