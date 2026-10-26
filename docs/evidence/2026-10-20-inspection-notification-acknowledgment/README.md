# Company acknowledgments —2026-10-20

User selected recipient_acknowledgment, then **any_authorized_company_reader / issue_and_cancellation_separate / all_authorized_notice_readers / detail_and_current_pdf**. See [ADR-044 EN](../../ADR-044-INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md) / [FA](../../ADR-044-INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-FA.md), [guide EN](../../INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-EN.md) / [FA](../../INSPECTION-NOTIFICATION-ACKNOWLEDGMENT-FA.md).

## Reproduce (app/; isolated synthetic epcc_test only)

```sh
EPCC_NOTIFICATION_ACK_EVIDENCE=1 vendor/bin/phpunit tests/Feature/InspectionNotificationAcknowledgmentTest.php tests/Feature/InspectionNotificationCancellationTest.php tests/Feature/InspectionNotificationPdfTest.php tests/Feature/InspectionNotificationActivityWindowTest.php --colors=never
vendor/bin/phpunit tests/Feature/InspectionNotificationTest.php --filter test_forward_upgrade_on_populated_archived_project_creates_no_implicit_main_or_grants --colors=never
EPCC_NOTIFICATION_ACK_EVIDENCE=1 vendor/bin/phpunit tests/Feature/InspectionNotificationAcknowledgmentTest.php --filter test_real_current_bilingual_pdf --colors=never
```

Private PHP8.4/PostgreSQL17 Unix socket/5545 app role; tests migrate/reseed, NEVER production. Real PDF requires existing Chromium/fonts/poppler runtime. Existing isolated PHP worker extended with acknowledge command, no HTTP provisioning, no new default roles.

## Results / provenance

- **`final-focused.log`:61 tests /1040 assertions PASS**,135.191s, zero skipped:20 acknowledgment +15 cancellation +13 PDF +13 activity-window. New20 + one real-legacy receipt case are included, not additional to61.
- **`forward-workflow.log`:1 test /5 assertions PASS**,1.862s, separate nonoverlapping existing workflow upgrade case.62 unique cases across two commands, not one combined run/full suite.
- **`final-pdf-footer.log`:1 test /22 assertions PASS**,3.754s, after presentation-only footer clarification + assertion. OVERLAPS61, do not add. No full61 rerun after that text-only change; final affected real PDF case retested.
- `initial-focused.log`:14 cases/215 assertions,13 passed and one fixture error (synthetic plan code exceeded existing varchar24); shortened fixture code, did not weaken schema/application validation.
- `extended-focused.log`:19/252 PASS,36.012s, before added membership/escaping checks; historical/overlapping.
- `receipts-{en,fa}.pdf`, `.txt`: actual current Chromium PDF + extracted text, both original and cancellation company receipts, no private old draft marker, current body/number/no-store; no business row/event mutation. `receipts-fa-page3.png`: final Persian receipt table/footer, visually reviewed. No production data.
- `other-recipient-{en,fa}.html`: current synthetic cross-recipient detail with recorded other-company receipts; actual CSRF tokens replaced by synthetic-test-token.
- `static-checks.log`: fresh-cache Pint11 PHP files PASS,11 PHP lint PASS, Blade cache/clear PASS.
- `database-audit.log`: private NOSUPERUSER/NOBYPASSRLS, FORCE RLS six notification tables, both event guards invoker, no TCP listen address.
- `database-shutdown.log`: pg_ctl no server running after private process stopped.
- `source-sha256.txt`: final affected source fingerprints, not cryptographic business-history tamper proof.
- `guide-links.log`, `diff-check.log`: new EN/FA guide/ADR/evidence links and whitespace validation.

## Boundary coverage

Explicit company receipt, not automatic page/PDF/read tracking; any authorized selected-recipient reader including no-role/no doc/draft/issue right and QAQC-only/no-doc plan. First company/event record only; independent companies/events. Sender not selected cannot acknowledge; selected sender self-only; unaddressed admin/other company cannot read or act. All original readers see approved cross-recipient actor/company/time via snapshots, no foreign person/company directory or private-history query. Snapshot names stable after rename; escaping verified. Payload metadata/version/target forgery, SQL scope/actor/captures/immutability/duplicate checks, active/membership/discipline/module/pack/participation/project archive gates. Legacy actual pre-window issued notice + exact cancellation both acknowledged without window/backfill/body changes. Archived source read not delegated. Successful receipt leaves notifications/cancellations/private history/settings/stages/material inspections/PO/MR/stock lots/movements unchanged; late insert failure rolls back event entirely. Populated archived forward upgrade creates no fake receipts or old-row changes.

Independent process advisory-lock barriers: two readers/same company produce one; two selected companies both succeed; committed cancellation rejects waiting original receipt; committed discipline revocation rejects waiting receipt in SQL. Independent committed acknowledgment during renderer hook yields409/no PDF bytes/disposition. Existing cancellation-during-render/departure checks retested. Targeted races, not arbitrary concurrency/deadlock/load certification. Final read cannot prevent later changes; downloaded/printed old copies cannot be recalled.

Forward-only migration140000, old migrations/parent RLS unchanged. SQL uses common advisory/project header lock and immutable parent reads, not recipient UPDATE/row-lock authority over sender data. Names/clock/model captured server-side under live reader/entitlement locks. Trusted app supplies actor identity; not end-user DB authentication or privileged-DDL/cryptographic tamper proof. Source old-schema fixtures updated only for correct dependency teardown/reinstallation.

No interactive browser/Safari/Firefox/native-picker/all-browser, full-suite/current-CI/production certification. Previous cancellation/filter/history totals are historical/nonadditive. No replacement/reschedule/undo, receipt retraction, transport/outbox/reminder, signature/legal receipt, attendance/approval/result/stock mutation or MTO/MR/PO/doc delegation. No commit/push/deployment.
