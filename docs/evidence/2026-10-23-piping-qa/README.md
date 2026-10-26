# Piping QA evidence / شواهد QA

2026-10-23. Implementation and focused local verification only; not deployed.

- [English guide](../../PIPING-QA-EN.md) / [راهنمای فارسی](../../PIPING-QA-FA.md)
- `predecessor-check.log`:23 predecessor application hashes matched before editing. Runtime restored from Composer lock; private UTF8 socket-only PG17; epcc NOSUPERUSER/NOBYPASSRLS.
- `final-focused.log`:73-case affected batch,72 passed /1 QA fixture-function collision,636 executed assertions,173.68s. Not an all-green73 run.
- `affected-fixture-rerun.log`: corrected repeatable/cleaned fixture,1 PASS /7 assertions,5.63s.
- `final-qa-only.log`: final19 QA cases PASS /142 assertions,58.46s; dependent54 cases had passed unchanged in the batch. Runs overlap; do not sum assertions or call this full regression.
- `initial-focused.log`, `second-focused.log`, `third-focused.log`, `fourth-focused.log`: retained developmental failures/fixes; record/table alias ambiguity, fixture pricing/unit/current-tenant assumptions, expected archive422, escaped HTML assertion and test function cleanup. No authority/policy relaxed to pass tests.
- `qa-http-en.html`, `qa-http-fa.html`: actual synthetic successful HTTP response bodies with CSRF redacted, not browser screenshots. They precede the test-fixture-only fix; production application source unchanged afterward.
- `format.log`, `static.log`, `blade.log`, `routes.log`, `database-audit.log`, `shutdown.log`: bounded static/runtime audits.
- `documents.json`:22 current-status/guides documents; earlier dated histories preserved.
- `current-source-sha256.txt`, `links.json`, `archive-manifest-sha256.txt`, `archive-verification.json`, `summary.json`: final source/document/link/package checks. No env, vendor, DB dump, uploads or external binaries in archive.

Source/package excludes test runtime and uploaded synthetic files. PostgreSQL role/GUC is the existing trusted-application boundary; no hostile-DDL or per-human SQL claim. SHA checks prove integrity against stored hashes, not technical sufficiency or authenticity. Existing legacy stock service is not cut over. No full-suite/current-CI/browser/load/production migration/commit/push/deployment claim.

فارسی: ارزیابی کفایت فنی انسانی است. اشارهٔ مدرک دسترسی جدیدی نمی‌دهد. خطای fixture و بازاجرای اصلاح‌شده شفاف نگهداری شده‌اند؛ اجراهای همپوشان جمع نمی‌شوند. GRN، موجودی، معافیت و گذار انبار قدیمی فعال نشده‌اند.
