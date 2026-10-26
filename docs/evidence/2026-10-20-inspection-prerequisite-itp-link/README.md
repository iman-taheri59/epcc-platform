# Exact inherited ITP context — 2026-10-20

[English delivery](../../INSPECTION-PREREQUISITE-ITP-LINK-REVIEW-EN.md) · [تحویل فارسی](../../INSPECTION-PREREQUISITE-ITP-LINK-REVIEW-FA.md)

Approved `next_prerequisite_scope=itp_link`, then `itp_link_granularity=inherited_stage`. Exact recorded parent stage/revision display; no structured subitem model, source access, schema/backfill, PDF or workflow/authority change.

- `final-focused.log`: from `app`, `EPCC_ITP_LINK_EVIDENCE=1 php artisan test --compact tests/Unit/InspectionNotificationPrerequisiteStageTest.php tests/Feature/InspectionNotificationPrerequisiteTest.php` → **54/807 PASS**,47.96s, zero skipped (30 pure cases +24 prerequisite feature cases, five new feature cases and19 existing affected cases including four concurrency schedules).
- `legacy-followup.log`: `php artisan test --compact tests/Feature/InspectionNotificationActivityWindowTest.php --filter=real_legacy_single_time_issue_accepts_separate_prerequisite` → **1/12 PASS**,4.54s.55 distinct cases /819 assertions across two commands. Not a full activity-window/PDF run.
- `focused.log`: initial53/802 PASS,46.22s; overlaps final, predates added zero/language-fallback case, not additive.
- `stage-en.html`, `stage-fa.html`: actual synthetic rendered prerequisite history pages, token scrubbed, not interactive browser/screenshots or production evidence.
- `static-checks.log`, `doc-links.log`, `source-sha256.txt`, `diff-check.log`, `database-audit.log`, `database-shutdown.log`: final bounded source/runtime checks.

Pure projection tests cover malformed/absent/foreign identity fields, point/sequence/text types, unknown JSON, allowlisted nine-field projection, no future private fields and legacy missing project metadata. Feature coverage adds exact inherited stage across create/current/history; no live document/revision/stage/private-history query; GET parent/event nonmutation; client retarget injection refusal; archived source/cancelled+archived project reading; unaddressed-reader denial; explicit unknown; escaped recorded-language fallback preserving `0`; legacy real issued snapshot linkage.

Runtime reset required restoring PHP8.4/PostgreSQL17 and dependencies from existing Composer lock. Private Unix-socket-only `epcc_test` with NOSUPERUSER/NOBYPASSRLS app role; no production DB. No browser/all-browser/load/full regression/current-CI/PDF-prerequisite certification or commit/push/deployment. Earlier81/1290 +1/5 evidence remains historical for completed prerequisite workflow, not this change.
