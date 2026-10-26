# Readable company picker — 2026-10-20

[English](../../INSPECTION-PREREQUISITE-COMPANY-PICKER-EN.md) · [فارسی](../../INSPECTION-PREREQUISITE-COMPANY-PICKER-FA.md)

Approved `next_prerequisite_increment=company_picker`. No new directory access/schema/authority/workflow write/PDF.

## Final executed evidence

From `app`: `EPCC_PICKER_EVIDENCE=1 php artisan test --compact tests/Unit/InspectionPrerequisiteFiltersTest.php tests/Feature/InspectionPrerequisiteCompanyPickerTest.php tests/Feature/InspectionPrerequisiteRegisterTest.php tests/Unit/InspectionNotificationPrerequisiteStageTest.php` → **56 tests/1793 assertions PASS**,43.34s, zero skipped, `final-focused.log`.8 picker features +4 filter units +14 register/recovery features +30 pure inherited-stage cases. Not full regression/current CI/browser/load/production acceptance.

`initial-focused.log`: fatal helper-name collision with framework `options()` before cases; renamed to `pickerResponse()`. `expanded-focused.log`:25 pass/1 fixture failure,1750 assertions (archive RLS correctly rejected fixture membership update). `expanded-archive-fixture.log`:55 pass/1 fixture failure,1788 assertions (reopen used stale in-memory project); corrected via existing owner reopen with fresh project. No archive policy was weakened. Runs overlap, not additive or substitutes for final green.

Coverage: visible-only declaring companies, no mere participant/hidden-notice directory; unique stable company identity and latest-created captured name vs live rename/reporter; literal code/EN/FA option search independent of result filters; off-search/off-page selected and generic unavailable UUID preservation; malformed/UTF8/NUL validation; discipline/project scope; cancellation/archive reads; escaped labels and no GET mutations;27 real eligible synthetic companies proving independent bounded company/register pages and pinned selection. Existing affected register tests recheck identity/membership/participation/pack, zero/count visibility, escaping and stage context.

## Artifacts

- `picker-en.html`, `picker-fa.html`: synthetic actual rendered app views, no POST/token, escaped hostile-looking names; not browser screenshots or contractual data.
- `static-checks.log`, `doc-links.log`, `source-sha256.txt`, `diff-check.log`: final bounded style/syntax/Blade/local links/source evidence.
- `database-audit.log`, `database-shutdown.log`: socket-only private epcc_test, NOSUPERUSER/NOBYPASSRLS app role, existing FORCE RLS; stopped after checks.
- `source-recovery.log`, `pre-recovery-source.tar.gz`:16 prior code files restored from checked preceding source bundle; previous differing files retained first. No all-repository recovery claim.
- `current-source.tar.gz`, `current-source-sha256.txt`: verified small credential-free source archive for this feature and preceding notification dependencies, preserving both picker and inherited-stage/register code. Compare manifest before restoration; never overwrite later work blindly. Separate latest recovery copy `/home/user/000-prerequisite-company-picker-source.tar.gz`; prior root archive is not overwritten.

Runtime dependencies restored from existing Composer lock; no production DB. No migration, new role/read grant, stock/work gate, source/company-directory delegation or PDF change; no commit/push/deployment. Earlier56/47/66/54/81 evidence is historical/nonadditive as described in guides.

`vendor-layout-check.log`: post-runtime-cache relocation and Composer autoload regeneration, pure/filter34/67 PASS,0.28s, overlapping final56. Generated runtime cache is excluded from persistence. Follow REBUILD.md and explicit COMPOSER_VENDOR_DIR after resets; do not rebuild a real16780-file vendor under the persisted source tree.
