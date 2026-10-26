# Inspection prerequisites — current PDF appendix

2026-10-20 · **ACCEPTED / IMPLEMENTED / TARGETED VERIFIED — NOT DEPLOYED**
[فارسی](INSPECTION-PREREQUISITE-PDF-FA.md) · [ADR-045](ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-EN.md) · [Evidence](evidence/2026-10-20-prerequisite-pdf/README.md)

## Approved continuation and use

User selected `next_prerequisite_step=prerequisite_pdf`, not structured item design or a broader gap review. The existing issued-notice PDF download now appends a separately labelled **current prerequisite tracking appendix**, after original content and company receipts. No new route or authority. English/Persian selection and private/no-store download headers remain.

The appendix has a server UTC preparation time, inherited exact recorded ITP revision/stage (or explicit unavailable context for legacy snapshots), all current prerequisite records in every state, and each record's complete immutable before/after history in ascending version order. Captured declaring-company name/code, record UUID, item text, requirement, state/version, action, actor/company/time and notes are escaped, multiline and printable. “0” is retained. No collapsed details or web pagination in print. History shows original creation plus edits, reports, corrections, confirmations and withdrawals; no private draft history.

No prerequisite records means **none recorded**, not contractual readiness. Fulfillment/withdrawal is not inspection acceptance, ITP approval, a technical waiver, signature, automatic work/stock gate or permission to proceed. Cancelled notices/archived projects retain readable tracking and their original lifecycle banner; export does not make them writable.

## Boundaries and consistency

Same fresh original-notice reader authorization before and after rendering: active identity/company, current membership/participation, discipline, QAQC and pack, original recipient visibility and FORCE RLS. No doc.view delegation, new role/grant, source document/ITP/PO/MR/MTO joins, schema/index/migration or workflow changes. Appendix reads are limited to the requested parent notice/project and its visible records/events. It deliberately ignores web list filters and page query strings; this is a complete notice export, not a filtered register PDF.

Issued number/version/body/snapshot and prerequisite/event records are not written by export. Existing cancellation and receipt race checks remain; a deterministic fingerprint of the ordered complete prerequisite projection **and** history is compared on a fresh read after rendering. Committed creation or revision during rendering returns localized **409**, with no PDF attachment/bytes; retry includes the change. A changed-then-reverted record still has a new version/event. Reader departure is still refused. This is an optimistic before/after check, **not** a lock or guarantee against changes after the last check.

The label explicitly separates current follow-up from the retained issuance evidence. Previously downloaded files are not rewritten, and no historical PDF store, signing, frozen export revision, legal certification or persistent export identifier is introduced. Future downloads are current views, not reproductions of issuance-time prerequisite data.

## Verification

- `EPCC_PREREQUISITE_PDF_EVIDENCE=1 php artisan test --compact tests/Feature/InspectionPrerequisitePdfTest.php tests/Feature/InspectionNotificationPdfTest.php`: **20 tests /1102 assertions PASS**,49.34s, zero skipped (7 new +13 existing).
- `php artisan test --compact tests/Feature/InspectionNotificationCancellationTest.php tests/Feature/InspectionNotificationAcknowledgmentTest.php --filter=pdf`: **4 tests /60 assertions PASS**,13.43s, zero skipped; separate nonoverlapping cases. Total **24 distinct targeted cases /1162 assertions**, not a full regression.
- Real Chromium EN/FA PDF text, embedded Vazirmatn and multipage long-text/end markers checked; Persian sample page3 raster inspected for joining/RTL/overflow. Seven new cases include all four current states, full before/after edits, more than25 records and27 history versions, escaped HTML/zero notes/no live sources, foreign-notice exclusion/empty disclaimer, cancelled+archived retention, and real second-session committed update/create during rendering with409 and successful retry.
- Initial fresh-runtime run stopped in migration because required `btree_gist` had not been provisioned; fixed as private harness administrator, not app migration/privilege relaxation. Next run had6 passes and a missing embedded-font assertion: renderer's scratch HOME could not see a user-local font; installing Vazirmatn as a system font fixed the environment, not the assertion. Final runs above pass. Initial logs retained; do not add overlapping runs.
- Final Pint/lint5 PHP files, Blade compile/clear, local links, diff and source hashes/bundle are recorded in evidence. Private role NOSUPERUSER/NOBYPASSRLS (CREATEDB only for historical test harness), socket-only PG17 and FORCE RLS verified; private server stopped at handoff.

No full-suite/current-CI/live-auth browser/load/production certification, commit, push or deployment. Runtime dependencies were rebuilt from lock after workspace reset; seven predecessor source hashes matched before edits, no source recovery. Vendor remains a disposable excluded-cache symlink.

## Decision review and recommendations

This explicitly supersedes **only** ADR-045's prerequisite-current-PDF deferral and corresponding earlier “PDF unchanged/no prerequisite PDF” prose. ADR-042 original notification choices, ADR-043 cancel-only, ADR-044 separate company receipts, ADR-045 company management/sender reporting/recipient review/all-reader history and inherited-stage/free-text boundaries are retained. Earlier delivery notices and counts remain historical, not current verification.

**Proposed, not enabled:** independently versioned/frozen export manifests if exact later reproduction becomes necessary; compact summary plus a separate full-history export for large notices. Full before/after text can be lengthy (synthetic long-text sample13 pages); current renderer timeout returns503 rather than silently truncating. No claim of load capacity or unlimited export size. Structured sub-items, readiness/hold policy, source authority, uploads, outbox, replacement/reissue remain deferred and require their own approval.
