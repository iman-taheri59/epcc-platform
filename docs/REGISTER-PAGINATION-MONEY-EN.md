> **Current delivery — 2026-10-19:** [independent inspection pagination](INSPECTION-REGISTER-PAGINATION-EN.md) / [فارسی](INSPECTION-REGISTER-PAGINATION-FA.md). **40 affected tests /556 assertions PASS**, four new cases. Inspection25-row pages, independent three-way navigation/true totals, explicit project + unchanged participant-visible quality RLS, unused PO eager load removed; advisory receipt semantics unchanged. Supersedes the earlier inspection-pagination deferral only. No new role/schema/endpoint/write or full-suite/production claim; older evidence/notices below are historical.

# Exact register money and independent MR / PO pagination

2026-10-19 · **IMPLEMENTED / TARGETED VERIFIED** · [فارسی](REGISTER-PAGINATION-MONEY-FA.md)

## Delivery and corrected deficiency

The owner asked to continue the proposed sequence: first repair monetary display, then independently paginate the two procurement registers. Both are delivered without new authority or workflow decisions.

**Money:** the preexisting register formatter used `number_format((float)$v)` and dropped fractional display precision. The formatter now renders the model's exact stored `decimal:2` string with its recorded currency, translating digits only for FA. Null remains `—`, distinct from `0.00`. No grouping, float, arithmetic, FX conversion, recomputation, financial approval or stored amount change occurs. Verified values include `9999999999999999.99 EUR`, `12.34 EUR`, `0.00 EUR` and unknown in both languages. This closes the monetary-display recommendation in the earlier register-filter guide, not every historical numeric-calculation concern elsewhere.

**Pagination:** MR and PO each show up to25 matching headers, using independent `mr_page` and `po_page` parameters on the existing procurement GET route. Row ordering is `sequence DESC, id DESC`, including a deterministic UUID tie-breaker for historical repeated sequence values. Each register shows current row range, matching total, current/last page and first/previous/next navigation.

## Counts, links and edge cases

- Summary MR/PO counters cover **all visible server-filtered matches**, not only25/current-page rows. Per-register ranges/counts describe the displayed page. Counts are documents, not material quantities, commitments or remaining capacity.
- Late-order total uses the *same* PO header query, filters and original-caller RLS: both dates must be known and forecast DATE greater than promised DATE. This preserves `PurchaseOrder::isLate()` date semantics, not “overdue as of today” or a new issued-only rule. Missing/equal/earlier dates are not counted. No material-unit or monetary totals are summed.
- A page link preserves category, both independent text/state filters, language and the other register's page. Applying the GET filter form omits both page controls and starts both at1; Reset also clears search/state while preserving category/language.
- Pages accept the existing Laravel nullable integer validation style, min1/max1000000; invalid array, fractional, nonnumeric, zero/negative/oversized values are refused. JSON validation requests return422, normal web requests use existing redirect/errors behavior. The cap bounds the accepted page parameter, not the cost of database work.
- A syntactically valid page beyond the last page remains empty **without changing the true matching total** or moving the other register. The UI distinguishes this from no visible matches and offers First page recovery. It does not silently clamp/rebase the requested page. Empty-result datasets report0 matching documents.
- Browser tabletools operate on current-page rows only; their local search/sort does not update server totals or search other pages. Money's displayed text is exact; this patch does not certify client-side numeric sort precision.
- Navigation is ordinary server-rendered HTML and does not require JavaScript. EN/FA range and out-of-range messages and uniquely named navigation labels are included.

## Decisions, implementation and authority

Reviewed ADR-034 category separation/no inferred legacy classification, the prior filter guide and FR-UX-04 tabletools contract, accepted original-caller/register authority boundaries and the owner's explicit next-step sequence. ADR-033/035–040 independent draft/pricing, full-copy source, three-person/versioned approval/issuance and deferred MTO rights are unchanged.

`MaterialsController::procurement` keeps original project access, module/pack entitlement, TenantContext and forced RLS. MR and PO paginate independently with `withQueryString()` under that context; the late count is an aggregate on a clone of the same filtered PO query. No hidden journal joins, identity lookup or buyer impersonation was introduced. Existing inspections and their count/eager loading remain unchanged. New `materials.procurement-pagination` is presentation only; POST contracts/authority are untouched. No endpoint, schema/index/migration, role, source/order/audit state change, reservation, cancellation or reconciliation writer is added.

Header retrieval in the MR/PO register queries has LIMIT25/OFFSET and eager-loads their relationships only for current-page parent IDs. Count queries do not materialize all matching models. This is a bounded header-loading improvement, **not** a whole-page memory bound: inspection rows/related orders, access dashboards and legacy nested line counts are still governed by their existing loading. No capability to read new disciplines/companies/projects is claimed.

## Verification

**41 affected tests /499 assertions PASS in66.891s**, including **eight new feature cases** added to `ProcurementRegisterFilterTest`. Combined suites also include existing `ProcurementCategoryTest` and `PurchaseOrderEntryTest`; all prior register-filter cases remain included. Smoke two cases/65 assertions are a subset, not additive. No routine full-suite rerun.

New cases cover exact money/unknown vs zero/ENFA and unchanged stored orders;26 matching MR and PO legacy headers split25/1 with repeated-sequence deterministic ordering in all four page combinations; filter/language/cross-page preservation and form reset; late total across pages with early/equal/unknown/state/category exclusions; hidden competitor counts versus visible oversight counts; invalid page inputs; out-of-range/first-page independent recovery; SQL query-log checks of LIMIT/OFFSET and current-page relation IDs. Existing foreign BB-2/vendor/competing-buyer isolation, scoped category/state/text search, archived seven-table no-mutation and entry regressions remain included. Synthetic legacy paging fixtures add no operational author, finance, issue or receipt authority; guards stay enabled.

Evidence: `docs/evidence/2026-10-19-register-pagination-money/{summary.json,related.log,smoke.log}`. Four PHP lint/Pint checks, whitespace check, role evidence and six implementation hashes are recorded. PHP8.4/PostgreSQL17, UTF8/localeC, private Unix socket, epcc NOSUPERUSER/NOBYPASSRLS; stopped after verification. Previous evidence folders remain historical and unchanged. No production migration/deployment, commit/push, current CI, browser acceptance, load/recovery/concurrency or full-suite claim.

## Remaining recommendations / not delivered

- Measure representative scoped query plans and counts, then consider indexes/trigram search with original-RLS behavior and migration evidence. Offset/count/substr queries still depend on matching data volume;25 headers is **not20k-document performance certification**.
- Inspection pagination and any other unbounded register/dashboard work require their own scope; this slice does not claim those completed.
- Paging is live, not a snapshot/export: concurrent inserts/changes can shift rows or make separate count/retrieval statements briefly disagree. Deterministic ordering is for a fixed dataset, not cross-request snapshot isolation.
- General legacy/manual/MTO authority, physical receipt, post-issue editing, official source remaining capacity and write-based legacy reconciliation remain separate/deferred. This patch neither repairs nor certifies all legacy float/mixed-unit arithmetic elsewhere.
