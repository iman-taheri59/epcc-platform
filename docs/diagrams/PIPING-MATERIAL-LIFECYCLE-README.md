# Piping material lifecycle UML / چرخه متریال پایپینگ

Snapshot: 2026-10-26. Diagram-only delivery; no code, policy approval, grant, migration or new runtime test.

- `PIPING-MATERIAL-LIFECYCLE-FA.svg`: Persian UML activity overview, opens directly in a browser/viewer. Green is implemented in the workspace, not deployed. Amber/dashed issue path is a proposal awaiting approval. Grey is deferred/separate. Source PO is an independent eligibility dependency, not a mandatory gate for recording actual arrival. Failure loops require revalidation; they do not imply an automatic reversal or waiver.
- `PIPING-MATERIAL-LIFECYCLE.puml`: editable English PlantUML activity source with the same lifecycle semantics. Render with PlantUML when needed; SVG layout is separately authored for Persian readability.

مراحل اجراشده تا GRN با مراحل صرفاً پیشنهادی خروج تفکیک شده‌اند. نمودار جامع تمام جزئیات خرید/MR/MTO نیست؛ مسیر متریال از ورود واقعی تا اعتبار پذیرفته‌شده و مرز مراحل آینده را نشان می‌دهد. اسناد حاکم: `../PIPING-GRN-FA.md` و `../PIPING-ISSUE-DESIGN-FA.md`. شناسه‌های D1–D3 همچنان پیشنهادند. محل ثبت‌شده GRN معادل تحویل سامانه کامل حفاظت/صلاحیت محل نیست. شرط باز/مدرک ناقص، مصرف عادی ایجاد نمی‌کند.

This is a conceptual lifecycle/authority overview, not a claim of installed consumption, full purchasing/MTO coverage, current evidence retention guarantee or operational deployment. No prior test counts are presented as new diagram verification. SVG XML parsing was checked; no PlantUML render execution is claimed.
