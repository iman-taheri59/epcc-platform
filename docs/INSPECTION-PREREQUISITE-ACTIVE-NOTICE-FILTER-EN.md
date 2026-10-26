> **Approved PDF continuation — 2026-10-20:** [current prerequisite appendix](INSPECTION-PREREQUISITE-PDF-EN.md) / [فارسی](INSPECTION-PREREQUISITE-PDF-FA.md). Selected prerequisite_pdf implemented: all records/full history/retained stage, separate from issuance snapshot; same scope, no new schema/authority/writes.409 on changes during render.20/1102 + separate4/60 PASS; not full-suite/current-CI/deployed. Only prerequisite-PDF deferral and contradictory historical prose below are superseded; other decisions retained.

# Prerequisite tracking — original-notice lifecycle filter

2026-10-20 · **USER SELECTED / IMPLEMENTED / TARGETED VERIFIED — NOT DEPLOYED**
[فارسی](INSPECTION-PREREQUISITE-ACTIVE-NOTICE-FILTER-FA.md) · [Register](INSPECTION-PREREQUISITE-REGISTER-EN.md) · [Company picker](INSPECTION-PREREQUISITE-COMPANY-PICKER-EN.md) · [ADR-045](ADR-045-INSPECTION-NOTIFICATION-PREREQUISITES-EN.md)

## Accepted continuation

User selected `next_after_company_picker=active_notice_filter`: distinguish prerequisites on issued, not-cancelled original notices from retained cancelled-original history. This is a read-only projection, not an operational readiness/hold policy. Existing reader/company authority, reporting/recipient review, immutable evidence, inherited-stage context, source access and current PDF remain unchanged.

The existing register adds **Original notice status** with:

| UI choice | GET `prerequisite_notice_state` | Meaning |
|---|---|---|
| All notices (including cancelled) | empty/absent | Existing default: all authorized visible prerequisites on issued notices |
| Issued and not cancelled | `ACTIVE` | Original notice is issued and has no cancellation event |
| Cancelled original notices | `CANCELLED` | Original notice has its independent retained cancellation event |

**All is the default**. Old URLs without this key continue to include both groups. No history is silently hidden, withdrawn, fulfilled, deleted or reassigned. Cancelled original rows retain their existing read-only cancellation label. Status is selected explicitly by GET, with no persistence to notice or prerequisite.

This is independent of `prerequisite_state` (OPEN/REPORTED/FULFILLED/WITHDRAWN). An active-notice filter can include a fulfilled or withdrawn prerequisite; a cancelled-original filter can include an unchanged OPEN prerequisite. The original raw row remains ISSUED after cancellation; ACTIVE must not be implemented by testing that raw state alone. **Not cancelled is not project writability, active membership entitlement, inspection readiness, technical acceptance, release or permission to proceed.** In particular, an archived project's not-cancelled notice remains readable in ACTIVE but its writes stay prohibited.

## Query, visibility and interaction

`InspectionNotificationService::prerequisiteRegister()` retains the same original project/issued-version/discipline parent join, FORCE RLS and fresh reader membership/participation/QAQC/pack checks. ACTIVE adds bound-scope NOT EXISTS; CANCELLED adds EXISTS against `inspection_notification_cancellations`, correlated to **that original notice and its project**. It does not inspect unrelated cancellation events or join a source document. All other register filters intersect it (AND), including declaring company and literal notice number. No new table/index/migration/RLS/authority or write path.

Matching total/page applies the lifecycle filter. **All-visible prerequisite total and company-picker scope remain computed before it**, just as before other result filters. A selected visible company whose only prerequisite belongs to a cancelled notice remains available/pinned even if ACTIVE yields zero rows. Picker-search results are not a promise of matching prerequisites under current result filters. Hidden or unaddressed notices/cancellations remain absent from every count/row/option; configuration privilege alone does not gain a notice read.

Both record and company pages remain25 rows/options with independent page parameters. Lifecycle choice is carried by normalized pagination, language switching and row company/notice shortcuts. Language switch keeps both page positions; applying the form or shortcuts resets pagination as before. Clear filters removes the lifecycle key and restores All. Unrelated query context is not propagated. Server-rendered EN/FA/RTL, native select; no required JavaScript/new endpoint. Existing private/no-store headers remain.

The fifth supported GET key is `prerequisite_notice_state`; optional/null/ordinary surrounding whitespace normalize to empty or the exact uppercase values. Arrays, lowercase aliases, prerequisite statuses such as OPEN, raw ISSUED, ALL/UNKNOWN, invalid UTF-8 and NUL are rejected422, not silently treated as All. JSON returns validation errors; HTML returns a localized422 without Referer redirect. The existing original-query parsing and early NUL validation remain; no global middleware change.

A fresh request after a committed cancellation reflects its group change without mutating prerequisite/version/events or the retained issue. These are live offset pages/read-committed count queries, not a frozen report or lock against cancellation after the final read. No new concurrency guarantee, actionability decision or stale-page work authorization is implied.

## Exact verification

[Evidence](evidence/2026-10-20-prerequisite-active-notice-filter/README.md): **36 targeted tests /2008 assertions PASS**,59.61s, zero skipped.5 filter unit cases (one new),9 new lifecycle feature cases,14 affected register features,8 company-picker features. No full regression/current CI/browser/load/production acceptance claim. Previous56/1793 and other counts are historical/nonadditive, not rerun totals for this increment.

New coverage: default All; both groups and every prerequisite status; company/notice/status intersections; scoped hidden/unaddressed cancellation counts and sender/recipient reads; sequential refresh after committed cancellation; no retained issue/prerequisite/history/business-data mutation by filtered GET; archived ACTIVE read-only/no proceed inference; malformed/UTF8/NUL JSON+HTML refusal; both pagers/language/shortcuts/reset; picker independence and pinned selection; current discipline/cross-project scope. Existing affected cases recheck the broader current identity/participation/module/pack and escaped recorded-name boundaries.

Before modification, all seven prior picker source hashes matched the checked archive; **no source recovery was needed** this time. Runtime vendor remains symlinked to excluded cache as required by REBUILD.md; no new dependency/lock change. Static Pint/lint/Blade/local links, source hashes, private socket-only nonbypass-role/FORCE-RLS audit, synthetic EN/FA rendered active/cancelled views, and a new checked source archive are recorded. The database was stopped after checks. No commit/push/production migration/deployment.

## Prior decisions / remaining scope

Only the previously proposed active-original filter is now superseded by implementation. ADR-042's original choices, ADR-043 cancel-only, ADR-044 acknowledgment/PDF and ADR-045 company prerequisite/report/review/visibility remain. The company picker still searches one representative recorded name, not a foreign live directory or all historical aliases. Prerequisite PDF/export, structured sub-items, alerts/transport, replacement/reissue/rescheduling and technical readiness/hold gates are separate deferred increments. Do not change the default to Active or hide cancelled records without a separate explicit decision.
