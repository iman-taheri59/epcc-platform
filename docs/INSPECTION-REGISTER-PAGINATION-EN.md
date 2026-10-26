# Independent inspection-register pagination

2026-10-19 · **IMPLEMENTED / TARGETED VERIFIED** · [فارسی](INSPECTION-REGISTER-PAGINATION-FA.md)

## Delivered

Project → Procurement now paginates inspections independently, up to25 records per page, using `inspection_page` on the existing GET route. Ordering is `sequence DESC, id DESC`; UUID ordering is a stable presentation tie-breaker for repeated historical sequence numbers, not a business chronology/signature claim.

The inspection summary counter is the total of all **currently visible records in this project**, not just the current page. MR/PO text, state and category filters do not narrow inspections, as before. The UI explains this explicitly. Ranges, first/previous/next links, EN/FA labels, no-result and out-of-range behavior use the same bounded pagination presentation as MR/PO. A valid out-of-range page is empty but retains the true total and a First page recovery link.

Links between any of the three registers preserve the other page parameters, language and existing filters. Applying filters or Reset drops all three page positions; Reset additionally drops MR/PO text/state while preserving category/language. `inspection_page` uses nullable integer validation,1–1000000, as MR/PO pages do. Malformed/array/fractional/nonpositive/oversized values follow existing Laravel validation behavior (JSON422, ordinary web redirect/errors). Tabletools operate on the current page only.

## Authority and loading boundaries

Reviewed the remaining inspection-pagination scope in `REGISTER-PAGINATION-MONEY-EN.md`, existing inspection model/schema/RLS and the settled advisory decision reflected in `ProcurementState` and ADR-024. No new inspection writer, approval role, receipt condition, MTO right or PO/MR authority is created. **Inspection remains advisory and does not become a receipt gate.** Receipt and inspection-write workflows are unchanged and are not newly certified here.

`MaterialsController::procurement` keeps original project access, module/pack entitlement and caller TenantContext. Inspection COUNT and row retrieval have the explicit current project predicate and unchanged forced-RLS policy. **The existing quality-record policy lets participating project companies read inspections; it is not buyer-only PO/pricing-history visibility.** Competing participating buyers therefore retain their preexisting inspection view. Nonparticipant/project access remains denied. No buyer impersonation, identity hatch, new policy, schema/index/endpoint or mutation is added.

Only25 inspection models are fetched; `company` is eager-loaded for those records. The unused `purchaseOrder` eager load was removed from this register because its table does not render it. This does not remove the linkage or modify PO detail's separate inspection presentation. It avoids unnecessarily materializing associated order headers. All three register header queries are now paginated; nested legacy lines, access dashboards, aggregates and other screens are not a certified whole-page memory/performance bound. Counts/offset/substring search still need representative query-plan/load assessment before scale claims.

## Targeted verification

**40 affected tests /556 assertions PASS,66.378s**, including **four new feature tests**. Suites: `ProcurementRegisterFilterTest`, `ProcurementCategoryTest`, `PurchaseOrderDetailsTest`. The focused four/82 assertions are a subset, not additive; suite selection differs from the preceding increment. No routine full-suite rerun.

New coverage:26 repeated-sequence inspection fixtures split25/1 with stable UUID order; three-way link independence and filter/language preservation; inspections unchanged by MR/PO filters; malformed page and out-of-range recovery; retained participant visibility and denied nonparticipant RLS/route access; real BB-2 isolation; archived GET eight-table no-mutation snapshot; escaped findings; SQL LIMIT/OFFSET; unused PO relation not loaded. Existing MR/PO paging/exact-money/filter/category and PO detail/advisory failed-inspection display regressions remain included. Fixtures are trusted read-test data, not a grant of human inspection-recording authority. Guards stayed enabled.

Evidence: `docs/evidence/2026-10-19-inspection-register-pagination/{summary.json,focused.log,related.log}`. Four PHP lint/Pint checks, whitespace check, role evidence and five implementation hashes. PHP8.4/PostgreSQL17, UTF8/localeC, epcc NOSUPERUSER/NOBYPASSRLS, private Unix socket; stopped after testing.

No production deployment/migration, commit/push, current CI, browser acceptance, full suite, receipt-operation, broad concurrency/recovery or20k-document load certification. Pagination is live rather than an export/snapshot; concurrent changes can shift pages/counts. Representative indexes/query plans, other unbounded dashboards, inspection-write UI/authority, physical receipt, controlled post-issue changes and deferred MTO/reconciliation work remain separate. Historical evidence is retained.
