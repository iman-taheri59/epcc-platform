# Current prerequisite PDF appendix — 2026-10-20

[English guide](../../INSPECTION-PREREQUISITE-PDF-EN.md) · [راهنمای فارسی](../../INSPECTION-PREREQUISITE-PDF-FA.md)

User selected `next_prerequisite_step=prerequisite_pdf`. Same issued PDF route/reader scope; current labelled complete appendix with retained ITP stage, all states and immutable before/after history. No original snapshot/write/grant/schema change. Post-render fingerprint recheck rejects committed prerequisite creation/revision with409; existing cancellation/receipt/current-reader checks retained.

## Executed tests

From `app`:
- `EPCC_PREREQUISITE_PDF_EVIDENCE=1 php artisan test --compact tests/Feature/InspectionPrerequisitePdfTest.php tests/Feature/InspectionNotificationPdfTest.php` → **20 tests /1102 assertions PASS**,49.34s, zero skipped (`final-focused.log`).7 new+13 existing.
- `php artisan test --compact tests/Feature/InspectionNotificationCancellationTest.php tests/Feature/InspectionNotificationAcknowledgmentTest.php --filter=pdf` → **4 tests /60 assertions PASS**,13.43s, zero skipped (`related-pdf.log`).24 distinct targeted cases/1162 assertions across two commands, not a full suite.

`initial-focused.log`: fresh private DB lacked required preexisting btree_gist; administrator provisioned it. `focused-after-harness-fix.log`:6 pass/one font assertion; renderer temporary HOME cannot discover user-local font, fixed by system-font installation. No app policy/migration/assertion weakening. Final evidence supersedes these overlapping runs.

## Artifacts and limits

- `sample-en.pdf`, `sample-fa.pdf`, corresponding text: actual Chromium PDFs from synthetic long-text/all-four-states fixture.13 pages in Persian sample, embedded Vazirmatn. Persian page3 raster inspected; not live-auth UI/all-browser/load certification.
- `sample-en.html`, `sample-fa.html`: captured controller-rendered HTML from a **different** escaped-text/zero-note synthetic fixture, not the source of the preceding long-text PDFs. No POST forms or CSRF tokens.
- `baseline-source-check.log`: seven predecessor source hashes matched before edits; no source recovery. PHP/Composer/PG/Chromium rebuilt after reset; vendor disposable excluded-cache symlink. Lock unchanged.
- `static-checks.log`, `doc-links.log`, `source-sha256.txt`, `diff-check.log`: Pint/lint5, Blade, checked new/current documentation links and source manifest.
- `database-audit.log`, `database-shutdown.log`: private socket-only app role NOSUPERUSER/NOBYPASSRLS, CREATEDB for existing harness, five FORCE-RLS tables; server stopped at handoff.
- `current-source.tar.gz`, `current-source-sha256.txt`: checked current source/document bundle; root copy `/home/user/000-prerequisite-pdf-source.tar.gz`. Older archives retained. Never blindly restore over newer work.

Optimistic read/fingerprint check is not a lock against changes after final read; no frozen/historically reproducible export/signature. Complete history can be long; timeout503, no silent truncation or unlimited/load capacity promise. No full regression/current CI/production/commit/push/deployment. Original management/report/review/visibility decisions, register/company filters and permissions retained. Structured items and readiness/hold policy remain deferred.
