# Optional register filter time UI — 2026-10-20

Approved `next_scope=filter_usability`. Implemented optional helper controls; no query/service/controller/parser/migration or authority changes.

## Reproduce (private PostgreSQL17/app test runtime)

From app/:

```sh
EPCC_FILTER_UI_EVIDENCE=1 vendor/bin/phpunit tests/Feature/InspectionNotificationRegisterFiltersTest.php tests/Feature/InspectionNotificationTimeUiTest.php tests/Unit/InspectionNotificationFiltersTest.php tests/Unit/InspectionNotificationInputTest.php --colors=never
node tests/Browser/notification-filter-time.test.cjs
```

PHP8.4, private `epcc` role, Unix socket/5545, database epcc_test; test setup migrates/reseeds synthetic data. Never run this command on production/business data. Browser script uses installed `/opt/playwright/node_modules/playwright` and `/usr/bin/chromium`, no external network or authenticated session; fixture submissions captured as FormData, no live navigation. Skill-instructions file path unavailable; actual installed Playwright used directly.

## Results

- `focused.log`: **26 tests /404 assertions PASS**,24.695s, zero skipped:9 filter +4 time UI +3 parser +10 input. Includes new DOM contract test and existing scope/privacy/filter/GET/malformed input checks.
- `browser.log`, `browser-summary.json`: **7 scenario groups /147 assertions PASS**, current Laravel-rendered fixture EN/FA in Asia/Tehran/America/New_York, no-JS EN/FA and390px Persian mobile. Blank/partial/cleared/one-sided/equal-bound, seconds/offset/UTC/no-zone conversion, manual invalid/roundtrip, form data separation/context/page reset, language/clear links, required draft/edit isolation, mobile RTL/LTR.
- `empty-*.html`, `prefilled-*.html`, `edit-*.html`: current synthetic fixtures, actual CSRF values replaced with synthetic-test-token. No production data/session.
- `filter-time-fa-mobile.png`: actual Chromium screenshot, reviewed for layout; mobile time controls inside viewport.
- `static-checks.log`: fresh-cache Pint3 files, PHP lint3, JS syntax, Blade cache/clear PASS.
- `source-sha256.txt`: final affected source fingerprints, not business-history tamper-proofing.
- `guide-links.log`: EN/FA guide local links resolve.
- `diff-check.log`: whitespace validation PASS.
- `database-audit.log`, `database-shutdown.log`: NOSUPERUSER/NOBYPASSRLS, FORCE RLS retained, no TCP listen; pg_ctl confirms stopped after verification.

[English](../../INSPECTION-NOTIFICATION-FILTER-TIME-UI-EN.md) / [فارسی](../../INSPECTION-NOTIFICATION-FILTER-TIME-UI-FA.md).

Separate/nonadditive PHP/browser results. Prior58/640 and other increment totals historical; do not add them. No full-suite/current CI, live authenticated HTTP browser/native picker-popup/all-browser/Safari/Firefox, production, performance/concurrency certification. No commit/push/deploy. Legacy single times, source pickers, scope, paging25, inclusive server overlap and optional/equal bounds unchanged. Mandatory draft interval still requires end strictly after start; filter equal-bound instant semantics do not change writes.
