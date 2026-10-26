# Activity-window notification evidence — 2026-10-20 local

Explicit user choice `activity_window=replace_time`. Required planned start/end replace single time on new writes; legacy issued single time stays labelled and unmodified. Legacy drafts require explicit versioned conversion.

**71 tests /802 assertions PASS,105.926s, zero skipped**, one focused command: 10 activity-window +16 draft-edit +22 workflow +13 PDF +10 input. Not full regression/current CI/production/load certification; previous totals nonadditive.

`final-focused.log`, `static-checks.log`, `database-audit.log`, `database-stopped.log` and `summary.json` contain verification, scope and source/artifact hashes. Initial smoke error from the built-in after validator peer-array comparison is retained and fixed, not counted as passing.

`window-{en,fa}.pdf` and `legacy-{en,fa}.pdf` are actual Chromium output using **synthetic fixture data**, not real issued project records. Text extracts/font report/first-page FA raster are retained. New API requires coordinated writer/client migration; no synthetic historical range/backfill.

[English guide](../../INSPECTION-NOTIFICATION-ACTIVITY-WINDOW-EN.md) / [راهنمای فارسی](../../INSPECTION-NOTIFICATION-ACTIVITY-WINDOW-FA.md).
