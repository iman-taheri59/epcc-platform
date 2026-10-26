# Safe PO pricing validation recovery

2026-10-19 · **IMPLEMENTED / TARGETED VERIFIED** · [فارسی](PO-PRICING-FORM-RECOVERY-FA.md)

## Problem and delivered behavior

The existing pricing form redisplayed stored unit prices after a validation failure, losing the user's submitted prices. Currency/reason previously reused flashed input independently of the current version. This is a presentation deficiency, not a proven unauthorized write: existing POST version, authority, source and transaction checks already remained in force.

The form now restores bounded submitted currency, reason and unit-price strings **as one complete form state**, only when:

1. The flashed content hash exactly matches the freshly loaded current PO fingerprint.
2. Submitted line IDs are exactly the current complete active-ID set, once each; line order is immaterial.
3. The input has only the existing expected header/line keys and safe scalar/blank control values.

Prices are mapped by UUID, never index or repeated item code. Invalid but bounded scalar prices remain visible for correction, HTML-escaped; this does not make them valid. Empty controls converted to null by middleware are redisplayed as blank, **not zero and not the prior stored price**. Exact decimal strings, including zero, are not converted to float or localized inside POST inputs.

Stale hashes, partial/foreign/duplicate/historical line selections, nested/native-float values, unexpected fields or oversized values discard the *whole* flashed form state. The UI shows a bilingual warning, the current server values and a blank reason; it does not mix old currency/reason with fresh prices. The hidden fingerprint always comes from the freshly loaded server state. The user must review and explicitly resubmit; no auto-save, auto-submit, draft persistence or automatic stale retry occurs.

Restoration is request/session convenience only, not approval, evidence, authority, current source certification or a new current-MR fingerprint. It neither recalculates totals nor writes anything. Subsequent POST still independently validates all prices, current actor/grant/project, approved full-copy MR, commercial version, source identities, exact totals, locks and atomic audit. MR changes not represented in the commercial PO fingerprint remain subject to the existing source-validity recheck at write time.

## Decisions and scope

Reviewed ADR-038 (`original_drafter_live_grant`, complete active IDs, no silent completion, stale-content rejection), the controlled-pricing guide and the existing validation/POST contract. ADR-033–040/category/three-person/full-copy authority choices are unchanged. No new endpoint, schema, editor role, cross-buyer delegation, MTO right, quantity/source-description editing, reservation, financial approval or issuance behavior.

`PurchaseOrderPricingFormState` is a pure presentation helper. It does not query the database or switch TenantContext. It is used only inside the existing `canPrice` branch. Limits: currency8 characters, reason4000, scalar price text64 (allowing invalid text for correction); server price syntax remains the existing16 integer digits/up to2 decimal places. The current active set is bounded to1–20, as in the existing human full-copy workflow.

**Recommendation implemented:** bind all recovered fields to the same current version and complete line set rather than merely adding `old()` to price controls. This preserves user work without silently rebasing it onto changed content. Source reconciliation, new roles and post-issue editing are not authorized by this correction.

## Verification and evidence

**42 affected tests /465 assertions PASS in59.744s**, including **nine new cases** (four pure helper + five HTTP/PostgreSQL). Suites: `PurchaseOrderPricingFormStateTest`, `PurchaseOrderPricingComparisonTest`, `PurchaseOrderPricingTest`, `PurchaseOrderDetailsTest`. No full-suite rerun. The initial focused subset was eight cases/90 assertions; the final combined run additionally includes the blank-price case and Persian stale-warning assertions. Counts are not additive.

New coverage: reordered-ID restoration and corrected successful POST/exact total; blank vs stored price/zero; stale-version non-rebase with current hidden hash; escaped malicious scalar input; nested/foreign/duplicate input rejection; live nomination suspension; EN/FA; pure stale, malformed, partial, unexpected, oversized, native-float and empty-control cases. Existing source/actor/price-only, rollback, append-only/RLS, history comparison and detail tests remain included. Validation failure and form reads create no pricing revision; corrected deliberate POST creates exactly one.

Evidence: `docs/evidence/2026-10-19-po-pricing-form-recovery/summary.json`, `related.log`, `focused.log`; five PHP lint/Pint checks, whitespace check, role evidence and six implementation hashes. PHP8.4/PostgreSQL17, UTF8, private Unix-only test database with `epcc` NOSUPERUSER/NOBYPASSRLS; stopped after testing.

No migration/deployment/commit/push, browser acceptance, current CI, full-suite, universal SQL per-user RBAC or broad concurrency certification. Previous evidence folders are retained as historical records. General legacy/manual/MTO authority, source reconciliation writes, official remaining capacity, physical receipt and controlled post-issue changes remain separate/deferred.
