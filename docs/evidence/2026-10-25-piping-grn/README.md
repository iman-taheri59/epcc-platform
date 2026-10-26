# Controlled piping GRN verification — 2026-10-25

Final source: whole accepted custody → immutable controlled GRN → exactly one separate accepted-stock credit and minimal posted marker. Increment is not deployed. Contract/boundaries: ../../PIPING-GRN-EN.md and ../../PIPING-GRN-FA.md.

## Final execution

`cd app && php artisan test --compact tests/Feature/PipingGrnTest.php tests/Feature/PipingQaDecisionTest.php tests/Feature/PipingReceivingReviewTest.php tests/Feature/PipingCustodyAmendmentTest.php tests/Feature/PipingCustodyTest.php tests/Feature/PipingReceiptAccessTest.php tests/Feature/WarehouseTest.php`

**133 PASS / 1066 assertions / 443.78 seconds**; `final-affected.log`. Includes 21 new GRN tests. Test migrations are fresh PostgreSQL migrations under an ordinary NOSUPERUSER/NOBYPASSRLS role. Private Unix-only test database, not customer data. Earlier focused runs overlap, not additive. Test helpers publish synthetic retained files; they do not certify real project evidence.

Real two-PDO check: first accepted 2.000 receipt posts inside an uncommitted transaction; independently accepted second 4.000 receipt on the same 5.000 exact line tries SQL insert and receives lock timeout 55P03; after first commit retry receives 23514 ORDERED_QUANTITY_EXCEEDED. Only one GRN/credit/marker remains. This tests guarded SQL serialization, not a browser load test. Normal positive adapter still independently checks actual file bytes/full doc.view.

## Corrected exploratory failures (not suppressed)

- Initial migration assumed only one SELECT/ALL legacy policy. Actual accepted baseline has permissive tenant ALL plus restrictive archive ALL (`USING true`). Replaced the assumption with explicit names/count, parser-normalized expected body/role/command/permissiveness checks, then freeze digests. Did not bless unknown selective policies. Final affected run recreates and validates final migration repeatedly.
- New multi-file test helper originally called Storage::fake for each file, deleting its earlier synthetic file before QA. Moved fake storage initialization to setUp in the new test only.
- Added deferred completion check initially fired after the scoped actor was restored and consequently could not see its own credit at commit. Fixed by explicitly flushing the named constraint inside the actor context, then resetting DEFERRED, matching the existing QA pattern. No SECURITY DEFINER/BYPASSRLS was introduced.
- Added imports for new negative-test exception/archive references. Corrected an unlinked-receipt test that attempted to amend lowercase `m` to an uppercase `M` source: units are immutable/exact; fixture now records a new physical arrival with matching unit instead of changing policy/data.

Exploratory logs stay in the workspace. Final package carries final logs, not an assertion that every intermediate run passed.

## Scope and integrity

`rls-and-trigger-audit.log`: ordinary role, ENABLE/FORCE RLS on all three new tables, enabled derived/immutable/legacy/posting/completion triggers. `static-checks.log`: syntax/selected formatting and whitespace checks. `source-sha256`: exact included source/evidence bytes; archive is an incremental current-working-tree source snapshot, not a standalone installation.

Dependencies: existing repository/application, approved independent receipt nominations, custody, QC, manual QA, custody amendments, PO issue/source visibility and CDE/evidence access increments already present in this workspace. Preserved prior unrelated dirty work; no dependency installer, .env, credentials, database dump, vendor/cache or runtime included. Old WarehouseService had pre-existing unrelated modifications, which remain; this phase adds only the recognized-PIPING receive rejection there. No legacy balance import, controlled issue/reversal/preservation, permanent scope admission or DA03 reservation writer.

No full regression, current CI, interactive browser, operational acceptance, production migration/deployment, commit or push. Migration down is intentionally prohibited; do not disable triggers or edit history for legacy reconciliation.

Final presentation-only controller formatting was followed by the bilingual HTTP case again: **1 PASS / 13 assertions / 5.22 s**, overlapping the 133-test affected run, not additive (`final-http-after-format.log`). Selected ten-file Pint check, PHP syntax and whitespace checks pass.
