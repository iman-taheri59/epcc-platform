# Physical custody / initial MRR — 2026-10-22

[English guide](../../PIPING-PHYSICAL-CUSTODY-EN.md) · [راهنمای فارسی](../../PIPING-PHYSICAL-CUSTODY-FA.md)

User accepted current nominees + independent original source visibility for record reads; any eligible participating custody/MRR nominee may record, preserving actual actor/company separately from selected custodian. No source read delegation, owner/configurator automatic record read, receiving company-type filter or QC/QA/GRN/stock action.

## Verification

- `final-verified.log`: **20 custody tests /242 assertions PASS**,37.16s, zero skipped, final code.
- `final-focused.log`: **60 tests /438 assertions PASS**,99.46s, then17 custody +14 receipt configuration +29 existing warehouse. Precedes final retry-collision/form/source-wait tightening, superseded for custody by the20-case run. **Overlapping, not additive.**
- `config-status-subset.log`:2/43 PASS3.82s after configuration status wording adjustment; overlaps.
- Real separate worker PG connections observed blocked: duplicate key single record, suspension during advisory wait403, role revocation during actual source row lock wait403. Late failure rolls back fact and counter. No receipt credit/PO/MR/stock mutation.
- `source-wait-revocation.log`, `final-custody-after-recovery.log`, earlier dev/fix logs retained; not added to counts. Initial envelope order/fixture issue fixed; supplemental original pivot restore and administrative synthetic identity transfer corrected; cached worker stats replaced by exact pg_locks and isolated child DB environment. No weakening of source RLS.

## Artifacts / integrity

`record-en.html`, `record-fa.html`: actual synthetic HTTP detail render with CSRF inputs scrubbed; not browser screenshots. `predecessor-check.log`:9 earlier source hashes matched before changes. `static-checks.log`: Pint/lint10 PHP plus Blade/route checks. `database-audit.log`: private socket-only PG17 application role NOSUPERUSER/NOBYPASSRLS (CREATEDB only for test harness), FORCE RLS tables and invoker functions. `database-shutdown.log`: stopped at handoff.

`source-sha256.txt`: current16-file combined config/custody application slice. `doc-links.log`, `diff-check.log`, `archive-check.log`: paired docs/notice links, whitespace and source roundtrip. `current-source.tar.gz`, `current-source-sha256.txt`, `/home/user/000-piping-physical-custody-source.tar.gz`: checked current dependent source/docs; no env/vendor/db dump. Older source archives remain historical, never blindly restore over current work.

Canonical configuration history still owner-company RLS + owner-admin HTTP directory gate. Current derived nominations carry no labels/reasons/history; per-own-company read plus owner maintenance only. Migration uses restored bounded administrative owner context only to project existing approvals; runtime stays original caller. Actor/project GUC is restored after nested calls and SQL errors. Trusted DB/application GUC contract is not per-human raw-SQL identity isolation or hostile-DDL protection.

Physical immutable CUSTODY_ONLY facts are not a formal MRR certificate/PDF, QA acceptance, usable stock, GRN, verified location/material/certificate or future legacy cutover. A technical CUST- serial is not approved fiscal document numbering; original timestamp offset text not separately retained, instant shown UTC. New request keys are new facts, no shipment-text dedupe. Existing WarehouseService stays unchanged. Structured prerequisite design/implementation deferred.

No full-suite/load/interactive-browser/current-CI/production-migration/commit/push/deployment claim.

## خلاصهٔ فارسی

دفتر و جزئیات/فرم تحویل‌گیری فیزیکی با معرفی جاری و دید مستقل منبع اجرا شد؛ شرکت واقعی ثبت‌کننده می‌تواند غیر از متولی باشد.۲۰/۲۴۲ نهایی موفق؛۶۰/۴۳۸ مرتبط قدیمی‌تر و۲/۴۳ متن تنظیمات هم‌پوشان‌اند. رکورد CUSTODY_ONLY است و موجودی/پذیرش/GRN نمی‌سازد. آزمون هم‌زمانی واقعی و لغو نقش هنگام انتظار منبع انجام شد؛ بقیهٔ سیاست‌های معوق باز و مستندند. DB خصوصی هنگام تحویل خاموش و استقرار انجام نشده است.
