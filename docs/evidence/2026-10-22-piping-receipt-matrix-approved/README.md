# Piping receipt core matrix — user approval closeout

2026-10-22 · Core authority decisions accepted; implementation contract only.

[English contract](../../PIPING-RECEIPT-FIRST-SLICE-CONTRACT-EN.md) · [قرارداد فارسی](../../PIPING-RECEIPT-FIRST-SLICE-CONTRACT-FA.md) · [English ADR](../../ADR-046-PIPING-RECEIPT-STORAGE-EN.md) · [تصمیم فارسی](../../ADR-046-PIPING-RECEIPT-STORAGE-FA.md)

## Accepted selections

`qc_qa_warehouse`, `owner_configure_explicit`, `preparer_qc_acceptor_distinct` as recorded in `accepted-selections.json`. These close the core matrix. Nomination values (actual companies/users) belong to future owner configuration; do not re-ask these product-policy choices. Advanced waiver/disposition/accepted-with-comment/legacy/preservation rules are still pending independently.

ADR-046 and reference-driven EN/FA design updated without accepting the rest of the sample matrix by inference. First-slice contract specifies four independent action nominations, owner-only directory/configuration, no default or original-source read delegation, versioned replacement/suspension, future runtime actor separation and GRN overlap rule. Twelve focused configuration/separation/flow cases are planned, **not executed**. Existing InspectionAccessService/migration/UI/controller pattern and current dependencies inspected; no copying of all-participant configuration reads or old inspector-type rules assumed for receiving.

## Evidence and limits

- `application-unchanged.log`:545 application/source/resource/config/test/Composer files match the preceding design manifest; no application addition/removal/change in recorded scope.
- `documents.json`, `doc-links.log`, `diff-check.log`, `summary.json`: edited document inventory, new contract/ADR/notice link checks, whitespace and explicit status.
- `accepted-selections.json`: exact user answer identifiers, not proof that project nominations have been configured.

No application code, nomination screen/grant, custody/MRR/QA/GRN route, migration, runtime/database/browser/CI test, dependency installation or server was created/executed in this decision closeout. No commit/push/deployment. Source/docs review is not runtime feature delivery. Implementation is the next stage; no completed receipt workflow claim.

## خلاصهٔ فارسی

سه انتخاب صریح کاربر در ADR-046 بسته شد: QC بررسی، QA پذیرش، انبار GRN؛ معرفی مستقل توسط مدیر شرکت مالک دارای project.configure؛ سه شخص متفاوت MRR/QC/QA با امکان GRN توسط یکی از آن‌ها در صورت اختیار مستقل. ماتریس اصلی دوباره منتظر تأیید نیست. قرارداد برش اول و آزمون‌های لازم ثبت شدند؛ کد/فرم/مجوز هنوز فعال نشده و تست runtime اجرا نشده است. اقلام ساخت‌یافتهٔ پیش‌نیاز همچنان معوق‌اند.
