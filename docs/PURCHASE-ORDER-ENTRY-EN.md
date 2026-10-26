> Follow-on 2026-10-19: [approved own-company MR → PO draft copying](PO-MR-DRAFT-LINK-EN.md) is now available under independent PO nomination, with exact source IDs and **no MR consumption/issuance**. Prior verification below belongs to its earlier source; current targeted result is131/1067.

> Updated 2026-10-19: owner category correction is now implemented; new MR/PO entry requires material_category. See [current category guide](PROCUREMENT-MATERIAL-CATEGORIES-EN.md). Tests below belong to the earlier draft-entry source.

# Purchase order draft entry — 2026-10-19

**Status: implemented and targeted-verified.** Accepted authority decision: [ADR-033](ADR-033-PO-DRAFT-ENTRY-EN.md); [فارسی](PURCHASE-ORDER-ENTRY-FA.md).

## Use
1. Open Project → Procurement. An owner-company administrator with existing `project.configure` selects people or company-scoped project roles in **Purchase order author access**, records a reason and saves a policy version. No authors are granted automatically; MR/PMS permissions do not carry over. Clear nominees with explicit suspension to stop new entry.
2. A nominated active buyer-company member opens **New purchase order — draft**. Enter bilingual titles, discipline, a participating vendor and 1–20 lines. Each line needs material code, bilingual descriptions, unit and positive exact quantity (up to 3 decimals). Optional subsystem must belong to the project.
3. Prices are optional (up to 2 decimals); priced lines require currency. Delivery input is explicitly Gregorian. Save redirects to the order detail sheet. State, company, actor and project-wide number are server-assigned.

## Rules and boundaries
- Independent append-only nomination history captures reason, version and actor; a draft records its author-policy basis. Current membership, discipline, module entitlement and project archive state are checked. Revocation blocks new drafts, not deletion of history. No new role or MTO permission is granted.
- Quantities retain their source spelling; no float arithmetic or automatic unit conversion. Fully priced totals use exact decimal products and round the final sum half-up to 2 decimals. Missing/partial prices leave the header total **unknown**, not zero. Monetary totals do not sum incompatible physical quantities.
- Header, lines, attribution and project counter are one transaction. Upgrade backfills counters across company-visible historical orders without an RLS bypass, retains legacy rows with nullable new attribution, and creates **no initial authors**. Migration is forward-only; no production migration was performed.
- This is **manual, unlinked DRAFT entry only**. It does not consume/link an MR, mark it ORDERED, issue a PO, approve spending, receive goods or approve inspection/usable stock. The legacy ProcurementService remains a trusted internal primitive, not a human permission gate.
- Corrected a direct-detail entitlement gap: order detail now applies the same existing proc/pack module check as Procurement, in addition to membership and RLS. A named vendor needs its own entitlement; buyer membership is not inherited. Archived detail reads remain allowed.

## Verification
**92 related tests /613 assertions, exit0**, plus a separate **1 MR-upgrade test /57 assertions, exit0** (93 distinct cases/670 assertions across two runs). The related run includes **14 new PO entry/upgrade tests /133 assertions**, PO detail, Procurement, MR entry and shell regressions. Evidence: `docs/evidence/2026-10-19-po-entry/{related,mr-upgrade}.{log,xml}` and `summary.json` with feature-source hashes. Scoped PHP lint, Pint and `git diff --check` passed.

Checks cover no default/inherited authority, owner configuration and stale versions, person/role nomination, live revocation, project/company/vendor/subsystem isolation, protected fields and strict decimals, partial/exact pricing, archive refusal, late-failure rollback including counter, append-only policy/immutable attribution, cross-company numbering and populated archived upgrade. No real parallel-connection/crash or browser test is claimed. Initial red fixture runs were corrected; final evidence above is green.

Full suite/recovery bundle not repeated under the user's faster, affected-test cadence. Previous full-suite and detail-only reports are historical source proof, not current full certification. No commit, push or deployment.

## Remaining recommendations — not implemented
Next proposed usable slice: explicitly traced approved-MR → PO draft linking/import without prematurely consuming quantities. PO issuance/financial approval still requires its separate authority decision. Existing DocumentRegister cross-company numbering defect is **not** fixed by the PO counter. MTO authority deferral and other G01–G07 gaps remain unchanged.
