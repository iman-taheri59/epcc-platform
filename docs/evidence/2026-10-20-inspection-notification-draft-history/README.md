# Append-only draft edit history evidence — 2026-10-20 local

User selected `draft_history`. **87 tests /1139 assertions PASS,156.343s, zero skipped**, one affected command (16 history +16 draft-edit +22 workflow +10 window +13 PDF +10 input). No full-suite/current-CI/production/load/cryptographic evidence claim; previous totals nonadditive.

`summary.json` includes implementation hashes, scope/coverage and limits. `final-focused.log` is the final feature/input proof; `database-audit.log` confirms non-superuser/non-bypass role, four FORCE-RLS tables and no SECURITY DEFINER helper. Private DB stopped (`database-stopped.log`).

PHP lint/Blade pass in `static-checks.log`. That initial file also contains one style spacing issue in the optional test-artifact write; corrected without semantic change. Final fresh-cache8-file Pint PASS in `final-style.log`; intermediate fresh/per-file logs retained.

`recipient-current-issued.pdf` is actual Chromium output from **synthetic fixture data**; its text has CURRENTISSUEDBODY and not PRIVATEHISTORYBODY. It is not a real issued project record. PDF contains no prior draft history. SQL and HTML recipient privacy, atomic rollback, append-only guards, no-op saves, old coverage gaps and exact-key real double-edit workers are separately verified.

[English guide](../../INSPECTION-NOTIFICATION-DRAFT-HISTORY-EN.md) / [راهنمای فارسی](../../INSPECTION-NOTIFICATION-DRAFT-HISTORY-FA.md).
