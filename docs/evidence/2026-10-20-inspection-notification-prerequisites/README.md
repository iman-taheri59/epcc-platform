# Recipient prerequisite verification — 2026-10-20

[English guide](../../INSPECTION-NOTIFICATION-PREREQUISITES-EN.md) · [راهنمای فارسی](../../INSPECTION-NOTIFICATION-PREREQUISITES-FA.md) · [ADR-045](../../ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-EN.md)

## Final evidence (not cumulative)

- `final-focused.log`: from `app`, `EPCC_PREREQUISITE_EVIDENCE=1 php artisan test --compact tests/Feature/InspectionNotificationPrerequisiteTest.php tests/Feature/InspectionNotificationAcknowledgmentTest.php tests/Feature/InspectionNotificationCancellationTest.php tests/Feature/InspectionNotificationPdfTest.php tests/Feature/InspectionNotificationActivityWindowTest.php` → **81/1290 PASS**,176.73s, zero skipped.
- `forward-workflow.log`: `php artisan test --compact tests/Feature/InspectionNotificationTest.php --filter=upgrade` → **1/5 PASS**,2.12s. Distinct historical installation fixture, not another full workflow run.82 distinct targeted tests overall.
- `initial-focused.log`: initial12/193 PASS; `expanded-initial.log`:78 pass/1 failed,1272 assertions; wrong test expectation for archive403 corrected to existing project validation422. These are historical/overlapping, not final green or additive totals.
- `current-schema-install.log`: fresh private current migration after historical-schema test. No production application or historical data backfill.
- `database-audit.log`: private app role NOSUPERUSER/NOBYPASSRLS, Unix socket only; eight notification-related tables FORCE RLS, four new SQL functions invoker.
- `static-checks.log`, `source-sha256.txt`, `doc-links.log`, `diff-check.log`: final style/syntax/Blade/local-link/source evidence.
- `prerequisite-en.html`, `prerequisite-fa.html`: synthetic current rendered read-only other-recipient history, actual app views; CSRF tokens scrubbed. Not interactive browser/E2E/production screenshots, not real contractual data.
- `database-shutdown.log`: private server stopped after checks.

Four two-connection tests observe actual advisory-lock waits: same-version CONFIRM [0,2] and one event, revoked issue grant ahead of REPORT [2], cancellation ahead of REPORT [2], changed recipient discipline ahead of CONFIRM [2]. Late event-trigger failure rolls back projection. Other cases cover company scope, original readers/no private history, state/version/immutable events, strict input and SQL guards, real legacy single-time issue, no-backfill archived upgrade, eligibility, pagination, escaped text and retained names. See guides and test source for precise coverage; not exhaustive schedules or crash/load/full-suite certification.

Current PDF remains unchanged and does not export prerequisites. Original issue/snapshot, receipts and business records are not rewritten. No new stock/source authority, automatic gate, inspection acceptance or legal signature. No commit/push/current-CI/production deployment claim.
