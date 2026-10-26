> **Current stage2 status:** bounded manual/unlinked financial approval and three-person issuance are now implemented and targeted-verified; MR-linked execution remains blocked. See [current guide](PO-VERSIONED-EXECUTION-EN.md). The stage1 delivery record below is historical and its evidence is unchanged.

# PO financial / issuer nominations — 2026-10-19

**Stage1 implemented and targeted-verified; financial approval and issuance actions remain pending.** [فارسی](PO-EXECUTION-AUTHORITY-FA.md) · [accepted ADR-036](ADR-036-PO-FINANCIAL-AND-ISSUE-AUTHORITY-EN.md).

## Accepted owner decisions
The drafter, financial approver and issuer must be **three different people** for each PO; approval must bind the exact order version issued. The owner-company project.configure administrator independently selects financial/issuer people or company-scoped roles from participating companies **for each target buyer**, with no defaults or MR/PMS/draft-author inheritance. Cross-company nomination is explicitly authorized, but a grant for buyer A does not cover buyer B.

## Delivered now
Project → Procurement → **PO financial / issuance nominations**. Expand a target buyer and configure separate financial and issuer lists with a reason. Each buyer has independent version numbering, current selectors and the latest20 history entries (earlier entries retained). A purpose can be cleared independently; explicit suspension clears both. Lists may overlap for different POs, but this does not waive the future per-order three-person rule.

Targets are participating OWN/EPC/SUB buyers, consistent with existing PO ownership. Nominees can be active members/company-scoped roles from any participating company. Configuration requires the active owner's existing configure capability/module access and a writable project. The live nomination lookup checks target/purpose, current person/role/membership/discipline, project state, actor and buyer proc/pack entitlement. It never substitutes for operation/version/SoD checks and does not use certificate authority_limit as purchasing authority.

The new append-only forced-RLS table `po_execution_policies` captures buyer/project, separate selector snapshots, reason, version and actor/time. SQL validates buyer membership, configuring actor, current selectors and serialized per-buyer version. No update/delete policy is granted. The forward-only migration creates **no nominations**, changes no PO/source state, and leaves existing roles/capabilities untouched.

## Explicitly NOT delivered
No financial approval, version signature, issuance endpoint/button, delegated PO read scope, purchasing commitment, receipt or MTO authority. The three-person and same-version rules are accepted requirements but **are not yet executed by an operational workflow**. `nominationId()` is deliberately named as a lookup, not `canIssue()`. The legacy `ProcurementService::issuePurchaseOrder` remains a trusted primitive and has NOT been made a safe human endpoint; never wire it to HTTP with nomination alone. Existing OWN/PMC/MC oversight reads remain unchanged; a competing EPC nominee does not acquire PO visibility from this stage.

Next stage: version-bound financial decision history and issuance with all three identities checked, stale-content refusal, scoped cross-company operation/read handling, appropriate pricing and coordinated order/quantity state. Do not re-ask the settled three-person or per-buyer company-scope decisions. No universal SQL user-RBAC or full procurement completion claim; earlier G01–G07/MTO deferrals and DocumentRegister numbering defect remain unchanged.

## Verification
**145 targeted tests /1172 assertions PASS**, no errors/failures/skips (~197s); includes **14 new nomination/upgrade cases /105 assertions** and prior entry/category/source-copy/details/Procurement/OrderGap/shell regressions. Coverage: default denial/draft independence, cross-company nomination limited to target buyer/purpose, owner-only current capability, per-buyer stale versions, independent clearing/suspension, invalid/foreign/disabled selectors and targets, live role/discipline/user/plan revocation, archive read-only history, RLS/SQL append-only guards, EN/FA/escaping/malformed old-input handling, no action/read delegation, and archived populated upgrade preserving a priced draft unchanged.

Evidence: `docs/evidence/2026-10-19-po-execution-authority/related.{log,xml}`, `summary.json` (explicit stage flags and10 source hashes), scoped8-file PHP lint, Pint and diff-check. Initial negative fixture used PMC, which already has oversight reads; corrected to a competing EPC, **without changing RLS**. No full-suite/recovery, browser/real concurrency/crash, production migration, commit/push or deployment claim.
