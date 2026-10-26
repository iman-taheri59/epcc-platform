# Piping receiving QC observations — 2026-10-22

[English guide](../../PIPING-RECEIVING-QC-EN.md) · [راهنمای فارسی](../../PIPING-RECEIVING-QC-FA.md)

Implemented independent current receiving_qc nomination and actual MRR≠QC actor check on immutable parent custody. Read scope inherits approved parent scope with original source visibility, not owner/configurator or a source-read delegation. No generic role/default, new inspector-type rule, QA acceptance, technical checklist gate, waiver, GRN, stock or legacy WarehouseService change. Only the first edge of the three-person chain is executable; different User.id is the authoritative identity boundary, not proof of physical human separation across accounts.

## Verification

`EPCC_RECEIVING_QC_EVIDENCE=1 php artisan test --compact tests/Feature/PipingReceivingReviewTest.php tests/Feature/PipingCustodyTest.php tests/Feature/InspectionEntryTest.php` → **50 tests /578 assertions PASS**,100.61s, zero skipped:16 new QC +20 existing custody +14 existing PO inspection, `final-focused.log`.

Real isolated child worker connections proven waiting on exact advisory key: duplicate one review; parent connection appends newer head and waiting independent review422; revocation during wait403. Late failure rollback, SQL self/stale guards/server stamping, RLS/source hiding, timezone-stable custody hash, forward install, bilingual escaping/private cache, archive and scoped empty-head recovery covered. Partial/failed runs overlap and are not summed.

Development errors retained: self-FK added after primary key; private rebuilt DB recreated explicit UTF8 after SQL_ASCII failure; parent FOR SHARE filtered by missing UPDATE RLS, fixed using immutable/FK-bound parent reads without adding write policy; missing Process import restored after earlier Pint cleanup. No relaxed business authority.

## Evidence files

- `review-en.html`, `review-fa.html`: actual synthetic HTTP history render, CSRF inputs scrubbed, no browser screenshot claim.
- `predecessor-check.log`:16 previous application files matched before this increment; no recovery required.
- `static-checks.log`: Pint/lint11 PHP, Blade cache/clear and two routes.
- `database-audit.log`, `database-shutdown.log`: private UTF8 PG17 socket-only NOSUPERUSER/NOBYPASSRLS app role (CREATEDB existing harness only), FORCE RLS SELECT/INSERT and invoker functions, stopped at handoff.
- `source-sha256.txt`, `doc-links.log`, `diff-check.log`: combined23-file application slice, current guide/notice links and whitespace.
- `current-source.tar.gz`, `current-source-sha256.txt`, `archive-check.log`, root `/home/user/000-piping-receiving-qc-source.tar.gz`: checked current dependent source/docs, no env/vendor/db dump. Never restore old archives blindly over newer work.

Fresh resolver uses original caller context only. Parent history read is inherited, not broadened; no parent UPDATE/DELETE policy added to obtain a lock. Project advisory serialization plus immutable parent/FK and guard rechecks are the concurrency contract. Trusted DB/GUC model is not per-human direct-SQL authentication or hostile-DDL protection. Dates shown UTC, original offset text not retained; no extra chronology assertion. Result NO_NONCONFORMITY_OBSERVED is scoped observation, not PASS/compliance/acceptance. Pending technical applicability/mandatory evidence/accepted-with-comment rules must precede dependent QA automation. Structured ITP prerequisite design/implementation deferred.

No full-suite/browser/load/current-CI/production migration/commit/push/deployment claim.

## خلاصهٔ فارسی

مشاهدات QC با معرفی مستقل جاری و منع خودبررسی تهیه‌کنندهٔ MRR اجرا شد؛ سابقه از دید مجاز والد تبعیت می‌کند.۵۰/۵۷۸ موفق، شامل۱۶ تست جدید. کل زنجیرهٔ سه‌شخصی QA، GRN یا موجودی ساخته نشده و «عدم مشاهدهٔ عدم‌انطباق» پذیرش نیست. خطاهای توسعه حفظ، SQL_ASCII runtime اصلاح و RLS والد تضعیف نشد. DB خصوصی هنگام تحویل خاموش و استقرار انجام نشده است.
