> **Current stage2 status:** bounded manual/unlinked financial approval and three-person issuance are now implemented and targeted-verified; MR-linked execution remains blocked. See [current guide](PO-VERSIONED-EXECUTION-EN.md). The stage1 delivery record below is historical and its evidence is unchanged.

# ADR-036 — separate PO financial approval and issuance

2026-10-19. **Owner decisions ACCEPTED through explicit selections:** `po_issue_authority_model=separate_financial_and_issue`; `po_execution_company_scope=explicit_target_buyer`.

The PO drafter, financial approver and issuer must be three distinct people. Financial approval must bind the exact order version being issued. Financial and issue nominations are separate from each other and from draft/MR/PMS authority, with no default grantees.

The project owner-company administrator with existing project.configure nominates people or company-scoped project roles from participating companies **for each target buyer company**. A grant for buyer A is not a grant for buyer B or every order in the project. Cross-company participation is explicitly allowed by this owner decision, but does not authorize general impersonation of the buyer, unrelated documents, MTO writes/admission, receipt or usable-stock decisions.

## Implementation stages (not interchangeable)
1. Buyer-scoped append-only nomination policy, independent financial/issuer selectors, reason/version/actor, suspension, current eligibility and owner configuration UI. This increment does not expose approval/issuance actions or delegate PO data reads.
2. Still pending: version-bound financial approval and issuer action, live scoped authority, three-person separation, immutable decision history, stale-content refusal and coordinated state/quantity handling. Do not wire ProcurementService::issuePurchaseOrder directly to HTTP; its legacy trusted primitive is not sufficient human authority.

Lists may overlap because a role/person can perform different duties for different POs; stage2 must reject any transaction where drafter, approver and issuer are not three different people. A policy record alone is not proof that an order received financial approval.

Stage1 scope: participating OWN/EPC/SUB companies are target buyers as in existing draft ownership. Nominees can be active project members/company-scoped roles of any participating company, as selected by the owner. Future action still needs current discipline, membership and module entitlement; storing a nomination never activates an unavailable module. Buyer-specific policy versions, including clearing either purpose or suspending both, are retained without mutating prior records. No existing users/roles gain implicit nominations and no historical PO state is changed.

Targeted configuration, authority, buyer/project isolation, live person/role revocation, RLS/append-only, archive, populated upgrade and affected-entry/UI tests; no routine full-suite/recovery. Status/evidence: PO-EXECUTION-AUTHORITY-{EN,FA}.md after verification. Companion: ADR-036-PO-FINANCIAL-AND-ISSUE-AUTHORITY-FA.md.

## Verified status

Stage1 nomination configuration implemented:145 targeted tests/1172 assertions PASS,14 new/105 included. Stage2 financial/version/three-person issuance operations NOT IMPLEMENTED. See [delivery guide](PO-EXECUTION-AUTHORITY-EN.md).
