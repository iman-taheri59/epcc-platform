# Supplied piping receipt-to-storage reference — design evidence

2026-10-22 · Documentation/design only; not implemented or runtime-tested.

[English design](../../PIPING-RECEIPT-STORAGE-DESIGN-EN.md) · [طراحی فارسی](../../PIPING-RECEIPT-STORAGE-DESIGN-FA.md) · [English ADR](../../ADR-046-PIPING-RECEIPT-STORAGE-EN.md) · [تصمیم فارسی](../../ADR-046-PIPING-RECEIPT-STORAGE-FA.md)

## User decisions

`piping_receipt_acceptance_boundary=physical_custody_then_controlled_acceptance` and `piping_receipt_authority_design=design_matrix_before_grants`. Physical arrival/custody retained despite missing docs/failed inspection; authorized acceptance before accepted GRN; no ordinary consumption issue for Hold/Rejected. Matrix is proposed, not approved; no new writer/configurator/read privilege activated. Reference's strict IRN gate-entry lock not selected. Structured prerequisite-item design/implementation remains deferred.

## Traceability and checks

Original uploaded318-line reference copied verbatim under docs/reference, SHA256 recorded in `reference-sha256.txt`; byte comparison against upload required. Paired design maps twelve scenarios A–L and fourteen requirement groups REC-01–08/STO-01/PRE-01/INV-01/ISS-01/AUT-01/RPT-01, proposed responsibility matrix, independent state axes, implementation sequence and unexecuted focused tests. ADR-046 marks only two selected choices accepted; detailed matrix/schema/legacy/technical controls remain proposals.

Current WarehouseService receipt, MaterialLot and existing routes reviewed. Original receive method does not establish new controlled flow. `application-before.json` / `application-unchanged.log` prove application scope unchanged; `documents.json`, `doc-links.log`, `diff-check.log`, `summary.json` record document checks. No PHP/dependency install/database/server/browser/runtime test/commit/push/deploy. Source-reviewed presence and planned tests are not execution evidence.

## خلاصهٔ فارسی

فایل اصلی بدون تغییر حفظ شد؛ دو انتخاب صریح کاربر در ADR-046 و طراحی دو‌زبانه ثبت شدند. ماتریس مسئولیت‌ها هنوز پیشنهادی و منتظر تأیید است. دوازده سناریو، مراحل دریافت تا انبارش/نگهداشت و قابلیت‌های بعدی ردیابی شدند. هیچ اختیار، schema یا کد اجرایی فعال نشده و آزمون جریان جدید اجرا نشده است. مقادیر نمونهٔ فنی تابع دستور مصوب پروژه/سازنده‌اند، نه قواعد عمومی hard-code‌شده.
