# Independent issued cancellation —2026-10-20

Approved `next_scope=issued_lifecycle`, then **current_issue_grant / cancel_only / status_reason_scoped_replacement**. No replacement, reschedule, undo, acknowledgment or new automatic role. See [ADR-043 EN](../../ADR-043-INSPECTION-NOTIFICATION-CANCELLATION-EN.md) / [FA](../../ADR-043-INSPECTION-NOTIFICATION-CANCELLATION-FA.md), [guide EN](../../INSPECTION-NOTIFICATION-CANCELLATION-EN.md) / [FA](../../INSPECTION-NOTIFICATION-CANCELLATION-FA.md).

## Final reproducible affected commands (from app/)

```sh
EPCC_NOTIFICATION_CANCEL_EVIDENCE=1 vendor/bin/phpunit tests/Feature/InspectionNotificationCancellationTest.php tests/Feature/InspectionNotificationDraftEditTest.php tests/Feature/InspectionNotificationRegisterFiltersTest.php tests/Feature/InspectionNotificationActivityWindowTest.php tests/Feature/InspectionNotificationTimeUiTest.php tests/Unit/InspectionNotificationFiltersTest.php --colors=never
vendor/bin/phpunit tests/Feature/InspectionNotificationTest.php --filter test_forward_upgrade_on_populated_archived_project_creates_no_implicit_main_or_grants --colors=never
EPCC_NOTIFICATION_CANCEL_EVIDENCE=1 vendor/bin/phpunit tests/Feature/InspectionNotificationCancellationTest.php --filter test_real_bilingual_cancelled_pdf --colors=never
```

Private synthetic test DB only: each test migrates/reseeds; NEVER production. PHP8.4/PostgreSQL17, local Unix socket/5545 epcc_test. Existing isolated worker extended for cancel and403/404 refusals, no HTTP provisioning/grants.

- **`final-repaired-focused.log`:59 tests /884 assertions PASS**,119.036s, zero skips:15 cancel +16 draft edit +9 register +12 window +4 time UI +3 filter parser.
- **`repaired-workflow-upgrade.log`:1 test /5 assertions PASS**,2.009s; disjoint case,60 distinct cases across these two commands. No claim of one combined60-case run.
- **`final-pdf-status.log`:1 test /22 assertions PASS**,4.199s, after correcting the print metadata row to cancelled; overlaps the59, do NOT add. Real EN/FA PDF contains CANCELLED/reason/current issued body/number, no private historical draft marker; no-store. Other previously passed cases unchanged by this final view-only status row fix, no full59 rerun afterward.
- `cancelled-{en,fa}.pdf`, `.txt`: final real Chromium outputs and pdftotext, synthetic fixture. `cancelled-fa-page1.png`: final output, visually reviewed for Persian shaping, explicit banner, UTC and cancelled status row.
- `cancelled-detail-{en,fa}.html`: synthetic current detail; CSRF values scrubbed to synthetic-test-token. No production data/session tokens.
- `static-checks.log`: fresh-cache Pint13 PHP files PASS,13 lint PASS, Blade cache/clear PASS.
- `database-audit.log`: NOSUPERUSER/NOBYPASSRLS, FORCE RLS five tables, invoker cancellation guard, no TCP listen address.
- `final-private-schema.log`: fresh full migration installation on private DB to restore all forward schema after deliberate prior-schema fixture teardown; not production. Later targeted PDF test also migrates full schema.
- `database-shutdown.log`: pg_ctl no server running after stopping private process.
- `guide-links.log`, `diff-check.log`, `source-sha256.txt`: local new guide/ADR/evidence links, whitespace check, affected source hashes (not business-history tamper proof).

## Transparent intermediate failures

`initial-focused.log`:10 cases/62 assertions,3 failures+4 errors from undefined callback project variable; use locked project fixed it. `focused-fixed.log`:10/170 PASS,21.150s. `extended-focused.log`:15/202 PASS,28.769s. These are historical/overlapping, not final totals.

`expanded-pre-repair.log`: broader90-case command,176.927s,1442 assertions,**1 error +3 failures**,86 cases passed. One old-schema fixture omitted the new dependent cancellation table in teardown; fixed fixture dependency order, not production migration weakened. A broad edit accidentally inserted register batch-fetch code into editContext too; removed it only from editContext and restored original edit behavior. Final59 command retests all3 edit failures; separate1-case upgrade command retests the dependency error. Do NOT claim90 passed or add those PDF/history passing subsets to final counts. Outdated EN/FA immutable footer said draft editing/cancellation absent; corrected. PDF visual review exposed an old issued status label below the new cancelled banner; final view fix +1/22 actual PDF retest resolved it.

## Boundary evidence

One append-only cancellation row, immutable raw issued row/version/number/snapshot/recipients and private-history rows. Required reason, captured SQL actor/name/time/current issue-setting, same-company/current nomination (including another issuer), revoked/main-changed/active/plan/discipline/participation/archive gates, source archival/real legacy single-time withdrawal, payload tampering, raw scope/version/actor forgery refusals, server overriding name/time/setting/discipline, UPDATE/DELETE/duplicate refusal. Scoped CANCELLED/active ISSUED counts/filter, no reason search; recipient raw RLS scope and original current visibility retained. Late insert failure rolls back; notification/history/settings/ITP/PO/MR/stock lot/movement snapshots unchanged by success as well as failure.

Independent processes + advisory-lock barrier: two cancellations give one event; committed grant revocation while worker waits causes refusal. Independent committed insert during test rendering gives409 instead of old active PDF bytes. These are targeted races, not arbitrary concurrency/load/deadlock certification. Real bilingual PDF output verified, not new interactive browser/native-calendar/all-browser certification. Previously downloaded copies cannot be recalled; recheck current register.

Forward migration130000 tested on existing issued+draft archived project: no old data rewrite/cancellation backfill/default grant. Mandatory new draft intervals/legacy unknown display/private history preserved. No result/stock mutation, no MTO/PO/MR authority, no approved ITP/hold/release/notice-period inference. No full-suite/current CI/production, commit/push/deployment claim. Prior increment totals nonadditive.
