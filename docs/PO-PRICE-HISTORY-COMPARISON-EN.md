# Human-readable retained PO pricing comparisons

2026-10-19 · **IMPLEMENTED / TARGETED VERIFIED**

## Using the comparison

Open an already-visible PO detail sheet and expand a pricing-history revision. In addition to the unchanged retained before/after raw snapshots, the revision now shows:

- Before/after recorded currency and recorded total.
- Before/after unit prices matched by actual PO-line UUID, with code and UUID displayed. Repeated item codes do not merge lines.
- All stored lines from that revision, including unchanged historical/superseded rows. Unknown/unrecorded prices are distinguished from `0.00`.

The before/after currency fields identify the denomination of their respective price columns. No foreign-exchange conversion, financial delta, new total calculation or purchasing-capacity inference occurs. These are **historical recorded values**, not current prices, renewed financial approval, current source eligibility or signature verification.

The latest20 revisions remain displayed; older revisions are retained in the append-only table. Raw snapshots, hashes, actor/time and reasons remain available. No audit record is rewritten or rehashed.

## Authority and implementation boundaries

This is a read-only presentation improvement under ADR-038's existing original-drafter price-only workflow and existing buyer/project-scoped pricing-history visibility. ADR-033–040 authority/provenance/issuance rules and newer OrderGap boundaries are unchanged. It adds no endpoint, role, draft/editor grant, cross-company delegation, MTO right, signature claim or reconciliation/post-issue workflow.

`PurchaseOrderPricingHistoryService` executes **inside the same original caller TenantContext** already used by `PurchaseOrderPricingService::context`; it performs no company/project switch or identity lookup. The existing forced-RLS journal policy still decides which history rows are visible. An oversight-readable PO does not confer buyer pricing-history visibility. This patch preserves the preexisting detail/history read boundary; it is not a new general user-authorization design.

For precision, PostgreSQL extracts money with JSONB `->>` / `#>>` as **TEXT**, then packages those strings for PHP. PHP never decodes the raw numeric snapshots into floating-point amounts for this comparison. There is no arithmetic or implicit currency conversion in the presenter.

The presenter supports the current schema1/header/line shape, requires unique matching UUID sets and stable codes, and accepts decimal strings or explicit null values. Invalid JSON, unknown shape, malformed/nondecimal/native-float normalized values, duplicate or unmatched IDs do not produce guessed comparisons: the UI explains that the structured comparison is unavailable and retains raw snapshot access. This is structural presentation validation, not cryptographic verification or certification of historical data. It does not manufacture a missing prior price as zero.

## Verification

**33 affected tests / 359 assertions PASS, 49.562 seconds**, including **eight new cases** (four pure presenter tests and four HTTP/real-PostgreSQL cases). `focused.log`: eight new cases/101 assertions, included in the combined result, not additive.

Coverage: duplicate-code UUID matching with reordered lines; currency/total changes and zero vs unknown; exact `99999999999999.99` unit price / `509999999999999.95` recorded total; malformed/unsupported/duplicate/missing identity fallback; rejection of native-float coercion; EN/FA rendering; seven-table no-mutation snapshot; non-buyer/project RLS hiding;21 revisions stored while only20 displayed; existing superseded-line evidence retained and displayed without repricing history; existing append-only, source, actor/price-only, rollback and PO-detail regressions.

Evidence: `docs/evidence/2026-10-19-po-price-comparison/summary.json`, `related.log`, `focused.log`. Six PHP lint/Pint checks, repository whitespace diff check and seven implementation hashes are recorded. Private PostgreSQL17/PHP8.4, role epcc NOSUPERUSER/NOBYPASSRLS, UTF8, Unix socket only; stopped after verification.

No new schema migration, source/order/audit mutation by reads, routine full suite, current CI, browser acceptance, production deployment, commit/push or broad concurrency certification. Previous evidence folders are unchanged. General legacy/manual/MTO authority, controlled post-issue changes, official remaining source capacity, physical receipt and write-based legacy reconciliation remain separate/deferred.
