# Custody amendment evidence / شواهد اصلاحیهٔ تحویل‌گیری

2026-10-23 · implemented and targeted locally verified; not deployed.

- [English guide](../../PIPING-CUSTODY-AMENDMENTS-EN.md) / [راهنمای فارسی](../../PIPING-CUSTODY-AMENDMENTS-FA.md).
- Approved `original_preparer_live_grant`; no takeover/new roles/source/document grants. Arrival facts untouched. No GRN/stock/legacy cutover.
- `predecessor-check.log`:30 predecessor QA application hashes matched before editing.
- `migration.log`: fresh forward schema install. `forward-final.log`:3 affected forward-install cases PASS18 assertions,7.46s; included again in final affected run, do not sum.
- `final-focused.log`: **69 tests /729 assertions PASS,183.96s**, no skipped: amendment14 + QA19 + receiving-QC16 + custody20. Focused, not full regression.
- `initial-focused.log`: first9 cases PASS89 assertions. `second-focused.log`:12 passed/1 fixture import failure. `forward-focused.log`:2 passed/1 fixture schema-simulation leftover-trigger failure. Both fixed without loosening permissions; clean final run supersedes them. Runs overlap and are not additive.
- `http-en.html`, `http-fa.html`: actual synthetic private HTTP responses, CSRF redacted; not browser screenshots. `format.log`, `static.log`:16 PHP files style/lint plus diff whitespace. `blade.log`, `routes.log`, `database-audit.log`, `shutdown.log`: bounded compile/routes/role/FORCE-RLS/invoker/UTF8/socket/shutdown checks.
- `documents.json`:24 guides/current-status documents; historical sections retained, current notices close only the newly approved correction question.
- `current-source-sha256.txt`, `source-check.log`, `links.json`, `archive-manifest-sha256.txt`, `summary.json`, `archive-verification.json`: final application hashes, current-link existence and source/docs archive round-trip checks. Archive verification remains external to avoid recursive self-hash.

Runtime was restored with locked Composer packages and an isolated PG17 database; an initial Composer hook failed before its vendor symlink existed and completed after the symlink was restored. No production DB or per-human SQL identity/hostile-DDL claim. Original application-role/GUC boundary remains.

Source archive is a **dependent source/docs increment**, not a standalone installation or deployment bundle. No env/vendor/DB dumps/test uploads/runtime included. Existing legacy warehouse service remains separate. Later posting/aggregate availability, post-stock correction disposition, quantity/unit corrections, preparer replacement, waivers/preservation and formal reports require their own increment/policies.

فارسی: اصلاحیه مجوز فنی یا موجودی نیست. تهیه‌کننده، ورود اولیه و دسترسی اصلی منبع حفظ شده‌اند؛ با مخفی شدن آخرین منبع، مبنای قدیمی جایگزین نمی‌شود. خطاهای fixture و رفع آن‌ها شفاف ثبت شده‌اند؛ اجرای نهایی۶۹/۷۲۹ موفق است و اجراهای همپوشان جمع نمی‌شوند. هیچ استقرار یا رگرسیون کامل ادعا نشده است.
