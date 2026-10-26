> Update 2026-10-19: manual PO draft entry is now available; detail also checks existing proc/pack entitlement. Previous verification below is historical for the detail-only source. See [current entry guide](PURCHASE-ORDER-ENTRY-EN.md).

# Purchase order details — 2026-10-19

**Use:** Project → Procurement → click an order number. Direct route: `/projects/{projectCode}/procurement/orders/{orderId}?lang=en` (or `fa`).

Delivered: bilingual/RTL order sheet, buyer/vendor/source MR when visible, recorded header value, separate contractual/forecast dates and variance, searchable/sortable line table, exact quantities/units/prices, preserved superseded rows and predecessor IDs, lots with heat/serial and ledger balance, inspection records/findings, links back to procurement and warehouse. Shared shell, local assets and language switch retained.

Authorization follows existing company/project membership + invoker PostgreSQL RLS. Inactive/removed users, nonparticipants, same-project competitors and mismatching project/order routes are rejected; named vendor visibility is tested. No new permission policy, migration or write path. All relations are loaded within the scoped tenant transaction. Missing/invisible related records are explicit, not permission bypasses.

Boundaries: this is an operational **read sheet**, not PO creation, receipt or inspection entry. Header value is recorded data, not a new amended-price calculation. Latest stored line is not necessarily an in-force order. Unlike units are not summed. Lots without the exact PO-line link are marked unlinked; no code/description matching. Receipt/inspection/balance are not usable-stock approval. Existing G01–G07 and document-numbering gaps are not claimed fixed.

Verification: **54 targeted tests /227 assertions, exit0**, including8 new HTTP cases/52 assertions, ProcurementTest and ShellStructureTest (~67s final related run). Covers EN/FA, dates, shell, escaping, amendments/mixed units, lots/advisory inspection, authentication, vendor/member/RLS negatives, archive reads and no business writes. Initial fixture reused a unique line number; corrected to the next number without constraint changes. Source PHP lint and git diff --check pass. Evidence: `docs/evidence/2026-10-19-po-details/`. No browser smoke/deployment claim.

## Updated development cadence — explicit user instruction

Targeted tests per feature and directly affected dependencies; full suite only at milestones, high-risk infrastructure/security/schema changes or release. No routine recovery archive or repeated full-gate/report cycle per change. Preserve tenant/authorization and data-integrity checks; update essential docs briefly. Earlier1169/17146 full proof belongs to pre-feature source; the current feature is targeted-verified, **not a pending mandatory full gate**. No full rerun or recovery bundle this iteration.
