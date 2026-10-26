> **Current follow-on ADR-039:** MR-linked financial approval/three-person issuance with full-copy atomic allocation is now implemented and targeted-verified (108/746 PASS); one real two-connection contention scenario is included. [Current delivery guide](PO-MR-ISSUE-ALLOCATION-EN.md). Pending/block statements below describe earlier historical increments; accepted authority and allocation choices are not reopened.

> **Follow-on ADR-038:** controlled linked-draft pricing is now delivered; full-copy issue allocation semantics are ACCEPTED but their implementation is pending. MR-linked financial/issue stays blocked. See [current pricing guide](PO-CONTROLLED-PRICING-EN.md). Earlier statements below describe their historical increment.

# Version-bound PO financial approval and three-person issuance

2026-10-19 · [فارسی](PO-VERSIONED-EXECUTION-FA.md) · [ADR-036 authority](ADR-036-PO-FINANCIAL-AND-ISSUE-AUTHORITY-EN.md) · [ADR-037 implementation boundary](ADR-037-PO-VERSIONED-EXECUTION-EN.md)

## Delivered scope

The first operational stage2 surface is implemented and targeted-verified for **human-attributed, categorized, fully priced manual/unlinked PO drafts**. This is an incremental implementation boundary, not a permanent prohibition on MR-based purchases. MR-linked execution awaits controlled pricing and official quantity allocation. Earlier MR full-copy-to-DRAFT and category behavior remain intact.

1. Owner-company project.configure administrator configures independent financial and issuer nominees for each target buyer in Procurement. Persons or company-scoped roles may belong to participating counterparties. No default or inheritance is introduced.
2. An eligible nominee opens **PO financial / issue workspace** under the target buyer's nominations card. Category tabs keep Pipes, Flanges and other categories separate. The workspace delegates only this buyer's commercial PO review, within the person's live project membership, discipline and procurement/pack entitlement. An ordinary drafter or owner configurator is not automatically a reviewer or signer.
3. The financial reviewer reviews current header and line/history data, fingerprint and reason, then selects **Approve this financial version**. A retained canonical snapshot and decision are recorded; the PO stays **DRAFT**, with no issued-order coverage.
4. A separately nominated issuer reviews the same current version and latest financial decision, enters a reason and selects **Issue this approved version**. Drafter, financial reviewer and issuer must be **three different actual users**. The financial signer must still be eligible. Successful issuance atomically creates its decision, transitions to ISSUED and records the actual issuer/time.

No visible action button is a convenience indication, not authorization proof: SQL and service recheck source/pricing, authority, version, latest decision and separation on submission. A failed check refuses rather than silently repairing amounts or issuing a newer version.

## What invalidates approval

- Any commercial header or line/history change produces a different canonical SHA-256 fingerprint. Vendor, currency, total, promised date, titles, category, discipline, source identities, quantities and lineage are covered. All superseded rows are retained in the snapshot.
- A newly recorded financial decision supersedes the previous expected approval ID even when content is identical.
- A new buyer nomination-policy version requires renewed approval, even with identical selectors. Suspension/removal of the issuer blocks the action; loss of the financial signer's active status, membership, role, discipline or plan eligibility blocks issuance.
- Archived projects reject actions and revoke this live delegated workspace. Historical decisions remain stored; no new archival export surface is claimed here.

Workflow state/timestamps, issuer audit fields and expediting forecast are excluded from the commercial fingerprint. Prices and quantities use database exact decimal arithmetic; the rounded active-line total must exactly match the header. Missing/negative prices, nonpositive active quantities, malformed/cyclic lineage, missing currency or mismatched totals are refused. Zero prices are permitted; no currency conversion or budget-limit semantics are invented. certificate authority_limit is not purchasing authority.

## Evidence and transaction boundaries

`po_execution_decisions` is append-only and forced-RLS, with project/buyer/PO, purpose, sequence, policy, actor/company/name, expected approval, fingerprint, canonical JSONB snapshot, reason and database timestamp. The displayed retained JSON uses the database's raw text to avoid PHP float conversion of monetary values. The fingerprint is a content-integrity digest, **not a PKI/electronic-signature certification**.

Journal insertion and controlled issuance commit together. A deferred constraint rejects journal-only issuance; a failing header transition rolls the journal back. New human-attributed drafts cannot enter ISSUED, PARTIALLY_RECEIVED or RECEIVED directly without an issue decision. Controlled issued commercial data and issuance basis are frozen, including line insertion/update/deletion; forecast and receipt-state progress remain separate. Controlled cancellation, amendment and reapproval are not exposed. Historical issued orders are not retro-certified or retro-frozen by fabricated decisions.

Dedicated routes:

- `GET /projects/{projectCode}/procurement/execution/{buyerId}` — category-separated list.
- `GET .../execution/{buyerId}/{orderId}` — commercial review/history.
- `POST .../execution/{buyerId}/{orderId}/{finance|issue}` — scoped action with current fingerprint, reason and, for issue, expected approval ID.

Generic PO detail RLS and existing OWN/PMC/MC oversight remain unchanged. A competing EPC can review its explicitly targeted buyer here, but does not thereby acquire generic detail, another buyer, warehouse, MR or MTO access. Tenant/project context restoration is retained on success and refusal; no general buyer-impersonation endpoint exists. The legacy issue primitive is not wired to HTTP.

## Verification and remaining gates

- Affected selection: **140 tests /828 assertions PASS**, 215.025s; Procurement, PO detail/entry/nominations/source-copy, category, OrderGap and Warehouse. This run preceded the final direct-state bypass tightening.
- Final current-code selection: **19 tests /191 assertions PASS**, 31.666s; 17 workflow/scoped-read/SQL/atomicity/category/UI cases plus 2 populated-upgrade cases. It verifies the final guard against direct DRAFT→ISSUED/PARTIALLY_RECEIVED/RECEIVED bypass. These selections overlap and must not be added together.
- Final scoped PHP lint, Pint and repository diff whitespace checks: see `docs/evidence/2026-10-19-po-versioned-execution/summary.json` and logs. Historical stage1 evidence145/1172 remains unchanged in its own directory.
- Private disposable PostgreSQL test database uses application role `epcc` (non-superuser, NOBYPASSRLS), UTF8 and no TCP listener. Initial local bootstrap errors (self-referencing FK statement ordering and disposable database SQL_ASCII encoding) were corrected before verification; they are not production migrations.

Not claimed: full-suite/recovery rerun, real two-connection contention/revocation/crash verification, browser acceptance, current CI, production migration/deployment, commit/push or procurement completion. Existing OrderGap may acquire a line lock before this project advisory lock: PostgreSQL deadlock abort is fail-closed, but lock-order alignment and deliberate multi-connection testing remain a release gate. MTO authority/admission deferrals, G01–G07 gates and the historical DocumentRegister numbering defect remain unchanged.

## Recommended next increment

Implement a controlled pricing surface for MR-derived PO drafts and an explicit, versioned source-quantity allocation policy before enabling their financial approval/issuance. Do not infer remaining quantities or duplicate-purchase prevention from the existing exact full-copy link. Then design controlled post-issue changes and verify actual concurrent lock ordering; do not weaken the frozen-content guard merely to reuse legacy amendment paths.
