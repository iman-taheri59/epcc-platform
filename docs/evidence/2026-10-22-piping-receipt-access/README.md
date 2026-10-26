# Piping receipt owner-only nomination configuration — 2026-10-22

[English guide](../../PIPING-RECEIPT-ACCESS-EN.md) · [راهنمای فارسی](../../PIPING-RECEIPT-ACCESS-FA.md)

ADR-046 accepted independent owner-company project.configure administration; four action nominations; three different actual MRR/QC/QA actors and independent GRN grant. This increment implements **configuration only**. No operational receipt/QC/QA/GRN resolver/action/stock behavior, source-read delegation or default role. Actual SoD enforcement belongs to future actions, not selector-set disjointness.

## Executed evidence

From app: `EPCC_RECEIPT_ACCESS_EVIDENCE=1 php artisan test --compact tests/Feature/PipingReceiptAccessTest.php tests/Feature/InspectionEntryTest.php` → **28 tests /278 assertions PASS**,43.97s, zero skipped (`final-verified.log`):14 new +14 existing. Subsequent view-only empty-selection recovery adjustment: affected2/43 PASS3.26s (`post-view-recovery-check.log`), overlapping, not30 distinct tests. No complete suite or simultaneous two-connection proof.

Initial logs: standalone function survived migrate:fresh table drop, fixed CREATE OR REPLACE; JSON true required-unless behavior fixed with validated boolean/Rule::requiredIf; foreign-project fixture403→404 expectation corrected without changing access. Expanded test errors: missing exception import/dotted JSON-key lookup fixed in harness. Earlier final-focused25/254 and other failed/overlapping runs are not final counts. Final SQL shape/configurator refusal and server stamping plus populated archived forward installation pass.

## Artifacts

- `configured-en.html`, `configured-fa.html`: actual synthetic server-rendered pages, CSRF token inputs scrubbed. Not live server/browser screenshots.
- `baseline-source-check.log`: eight predecessor source hashes matched; no recovery required.
- `static-checks.log`, `doc-links.log`, `diff-check.log`, `source-sha256.txt`: Pint/lint7, Blade, links/source evidence. View cache/clear rerun after later view-only adjustment.
- `database-audit.log`, `database-shutdown.log`: socket-only private PG17 app role NOSUPERUSER/NOBYPASSRLS (CREATEDB for existing test harness), new FORCE RLS, SELECT/INSERT only, invoker guard; server stopped at handoff.
- `current-source.tar.gz`, `current-source-sha256.txt`, root copy `/home/user/000-piping-receipt-access-source.tar.gz`: checked current source/dependent documentation bundle. Older root bundles retained. Never blindly restore over newer work.

No production migration/commit/push/deployment or browser/load/current-CI/full-suite/crash/idempotency/concurrency certificate. New configuration has no existing-source read policy alteration. Trusted app validates eligible nominee selectors; SQL guard validates configurator/shape/version/metadata, not universal raw-SQL human identity/nominee eligibility. History owner-company RLS plus owner-admin HTTP directory gate; not participant-wide nomination disclosure. Future operational actions need a purpose-limited resolver without owner impersonation.

## خلاصهٔ فارسی

صفحهٔ فقط مدیر مجاز شرکت مالک و ذخیرهٔ نسخه‌دار متولی/چهار مسئولیت، تعلیق و سابقه اجرا شد. آزمون نهایی۲۸/۲۷۸ موفق؛۲/۴۳ view بعدی هم‌پوشان است. هنوز دریافت، پذیرش، GRN یا موجودی و resolver اقدام‌ها ساخته نشده‌اند. اصل تفکیک اشخاص مصوب و برای اجرای آینده لازم است، اما در این برش هیچ اقدام عملیاتی برای اعمال آن وجود ندارد. شواهد runtime محدود به آزمون‌های نام‌برده است؛ نه full-suite/CI/تولید.
