# Inspection notification date/time UI evidence — 2026-10-20 local

User chose `date_time_ui`. No schema/API/service/role change.

Related PHP: **53 tests /740 assertions PASS**,96.840s, zero skips. Final post-RTL-hint-only subset: **4 /81 PASS**,9.151s, nonadditive. Browser: **18 scenarios /149 assertions PASS**, actual Chromium, latest sources, no external requests/JS errors. See `summary.json`, logs and `browser-summary.json` for exact coverage and source/fixture hashes.

Browser fixtures are actual Laravel-rendered **synthetic demo pages**, CSRF tokens scrubbed. Browser checks are offline DOM/native validation/FormData, not authenticated browser HTTP submission; PHP separately exercises routes/saves. `time-controls-fa-mobile.png` is a390px viewport widget screenshot, not physical-device or full accessibility/all-browser acceptance. Initial browser log contains a corrected harness keyword error.

Pint/lint/Blade and diff pass; non-superuser/non-bypass app role/FORCE RLS audited; private database stopped. No production/current-CI/full-suite claim, no additive historical totals.

[English guide](../../INSPECTION-NOTIFICATION-TIME-UI-EN.md) / [راهنمای فارسی](../../INSPECTION-NOTIFICATION-TIME-UI-FA.md).
