# Original-notice lifecycle filter — 2026-10-20

[English guide](../../INSPECTION-PREREQUISITE-ACTIVE-NOTICE-FILTER-EN.md) · [راهنمای فارسی](../../INSPECTION-PREREQUISITE-ACTIVE-NOTICE-FILTER-FA.md)

User selected `next_after_company_picker=active_notice_filter`. Optional `prerequisite_notice_state`: empty/absent All(default), ACTIVE(no cancellation), CANCELLED. Read-only original-notice projection; not prerequisite state, readiness or work authority.

## Executed evidence

From `app`: `EPCC_ACTIVE_NOTICE_FILTER_EVIDENCE=1 php artisan test --compact tests/Unit/InspectionPrerequisiteFiltersTest.php tests/Feature/InspectionPrerequisiteNoticeStateFilterTest.php tests/Feature/InspectionPrerequisiteRegisterTest.php tests/Feature/InspectionPrerequisiteCompanyPickerTest.php` → **36 tests /2008 assertions PASS**,59.61s, zero skipped, `final-focused.log`.5 filter units,9 new lifecycle features,14 register and8 picker features. First run passed; previous increment counts are historical/nonadditive, not this command's totals.

Coverage: All default and both lifecycle groups intersecting all four prerequisite states; hidden/unaddressed cancellation scope and authorized reader counts; unchanged original/prerequisite/history/business records under filtered GET; fresh read after committed cancellation; archive-active read-only; malformed/UTF8/NUL filters rejected JSON/HTML422 without redirect/unfiltered fallback; both pagers/lang/shortcuts/reset; picker scope/pinned selection independent of result lifecycle; current discipline/cross-project. Existing affected features cover broader current reader identity/participation/QAQC/pack and escaping. No full regression/PDF/legacy migration/browser/load/current-CI/production certification.

## Artifacts

- `active-en.html`, `active-fa.html`, `cancelled-en.html`, `cancelled-fa.html`: synthetic actual app-rendered read-only pages. Not browser screenshots or real contract data; no POST forms/tokens.
- `baseline-source-check.log`: prior embedded archive +seven current source hashes matched before edits. No source recovery needed. Runtime vendor remains excluded-cache symlink, not a persisted dependency tree.
- `static-checks.log`, `doc-links.log`, `source-sha256.txt`, `diff-check.log`: final Pint/lint/Blade/local-link/source evidence.
- `database-audit.log`, `database-shutdown.log`: private socket-only app role NOSUPERUSER/NOBYPASSRLS, existing FORCE-RLS tables; DB stopped.
- `current-source.tar.gz`, `current-source-sha256.txt`: credential-free checked source bundle of current feature/dependencies/docs. Separate latest root copy `/home/user/000-prerequisite-active-notice-filter-source.tar.gz`; prior archives not overwritten. Compare manifests before any restore; do not replace newer user work blindly.

No migration/index/RLS/role/new source read/write/PDF change or commit/push/deployment. Cancelled history retained; ACTIVE does not imply a writable project or permission to proceed. Counts and pagination are current read-committed observations, not a frozen report or lock against later cancellation.
