# Controlled piping issue — proposed authority and control matrix

2026-10-25 · **DESIGN FOR APPROVAL — NOT ACCEPTED POLICY / NOT IMPLEMENTED**

[فارسی](PIPING-ISSUE-DESIGN-FA.md) · [Implemented GRN boundary](PIPING-GRN-EN.md)

## 1. Mandate, evidence and retained decisions

The user selected **issue design**, specifically requirements and a proposed responsibility/control matrix before any new authority is activated. This authorizes documentation, not new grants or issue writers. Previously accepted receipt nominations and MRR/QC/QA separation are closed decisions and are not reopened.

Reviewed sources: supplied `reference/EPC_Piping_Material_Receipt_Inspection_Storage_Preservation.md`, especially §§4.3–4.5, 6.1, 6.3 and the **sample**, nonbinding §6.5 matrix; ADR-046; receipt first-slice/storage design; current GRN contract; existing `WarehouseService::issue`, `deliverToSite`, `returnToStore` and new GRN migration. Reference MIV/MRV nomenclature is ambiguous, so this proposal uses **request → technical release → dispatch → recipient acknowledgment** as precise action names. No project procedure or named personnel has been supplied for these new duties.

**Implemented baseline:** whole accepted physical receipt, exact current QA/QC/amendment at posting, immutable GRN and separate positive credit, no duplicate/overdelivery, recognized PIPING legacy write cutoff. **Still absent:** controlled debits/reservations, issue nomination/read adapters, current condition/preservation/hold records, controlled acknowledgment/return/installation. Historical 133/1066 tests verify that baseline, not this design.

## 2. Recommended first slice and excluded meanings

One project; one configured participating custodian; eligible controlled GRN credits only; same-project visible receiving company and exact existing subsystem; explicit intended use and independently visible technical document/revision references. Preserve actual recorder company separately from physical custodian. Start with request lines each allocating an exact credit/Heat, quantity and unchanged unit. Explicit partial quantity issue is proposed; **no automatic partial fulfillment** when a requested quantity is unavailable.

No substitute grade/source, transfer across projects/custodians, Free-Issue, stock merging, unit conversion, scrap, adjustment, installation, return/reversal, remnant register or MTO consumption writer. A subsystem is a delivery destination, not proof of technical suitability or installation. Do not fabricate a spool/weld-map entity or infer isometric/line relationships from item code. Any future richer target must have its own validated scope/identity model.

Dispatch means authorized confirmation of actual warehouse handover and reduces warehouse quantity once. Recipient acknowledgment records independently reported delivery and reduces in-transit quantity, **not warehouse quantity again**. Neither event declares installed or consumed material. Physical events outside the authorized flow must not be relabeled as compliant; incident/exception recording and resolution require a separate governed workflow.

## 3. Proposed independent action matrix — D1

Owner-company current `project.configure` administrator would configure new **separate** action selectors: eligible active participant users or company-bound project roles, explicit project/PIPING/custodian and recipient company where applicable; no defaults. This extends the configuration pattern only after approval; receipt policy is not silently enlarged.

| Proposed action | Functional responsibility | Minimum authority / separation |
|---|---|---|
| `issue_request` | Construction/material requester | Explicit current nomination, own requester company; exact destination/purpose; no stock debit or technical release |
| `issue_technical_release` | Competent technical/material-condition reviewer | Separate current nomination; differs from recorded requester; exact request/evidence/condition version; not inherited QA/QC/PO authority |
| `issue_dispatch` | Warehouse handover recorder | Separate current nomination for explicit custodian; differs from requester **and** technical reviewer; original actor/company preserved |
| `issue_receive_ack` | Receiving company representative | Separate current recipient-company nomination; may be the requester; cannot be the dispatch actor for that handover |
| `issue_safety_hold` | Authorized condition/safety observer | Separate current nomination; warehouse/technical function is not itself a grant; can restrict specified credit or request without releasing it |
| Clear an issue safety hold | Independent technical reviewer | Current technical-release nomination, differs from hold declarer; reasoned exact new condition assessment; cannot override unresolved required evidence/conditions |
| Configuration | Owner administrator | No automatic operational/read authority; configurations versioned, stale-version protected and archived-project read-only |

Recommended: requester, technical reviewer and dispatcher are **three distinct recorded users**. This is a new proposed issue rule, not a redefinition of the accepted receipt three-person rule. The same person may perform a receipt-stage duty and an issue-stage duty only with the independently approved issue grant and issue-stage separation. Identity records do not prove different physical humans; account governance remains necessary.

## 4. Current readiness, safety holds and evidence — D2

Do not reuse frozen receiving QA as a perpetual release certificate. Introduce a separate append-only, version-bound **issue-readiness assessment**, without editing post-GRN QC/QA/amendments. Bind it to exact request allocation, GRN/Heat, destination/purpose, applicable project/vendor procedure revisions and retained evidence.

The reviewer explicitly assesses traceability and technical suitability for the stated use, current physical condition, storage/preservation history, shelf-life/expiry where applicable, required inspection/retest and absence of unresolved applicable conditions. Record applicability with reason; “not applicable” is not a default and “unknown” is not “satisfied.” No hard-coded universal inspection interval, humidity limit or expiry rule. Technical competence and applicable engineering/HSE procedure remain human responsibilities.

**Recommendation:** unknown, overdue, missing required evidence or open safety/quality condition blocks ordinary dispatch. No accepted-with-open-comment, waiver or urgency bypass. Required evidence that cannot be independently read/verified also blocks. A new assessment is required after a relevant change; an old approval never automatically covers new files, destination, quantity, Heat or condition version. Revalidate evidence/version and relevant condition/preservation/expiry at dispatch against a trusted server clock and approved procedure, not an arbitrary generic 24-hour approval lifetime.

A nominated observer can append a reasoned restrictive hold on the exact credit/request. It immediately blocks pending/new dispatch and invalidates applicable release for unissued quantity; it does not erase historical dispatch, return quantity or reopen receiving QA. Clearing requires another currently nominated technical reviewer and a new exact assessment with all required conditions resolved. Already-dispatched concerns require separately governed incident/recall handling, not an automatic stock reversal.

**Prerequisite:** a trustworthy current condition/hold signal and applicable preservation evidence path must exist before enabling positive dispatch. The present GRN ledger does not provide them. Missing preservation tracking cannot be represented by a default “good” flag or hidden inside a nominal release checkbox. The first implementation must deliver this bounded assessment/hold contract or remain dispatch-disabled; a full preservation scheduler is a separate scope.

## 5. Scoped reads and quantity model — D3

### Read design requiring explicit approval

Existing GRN/QA readers are bounded to receipt nominees. Assigning `post_grn` or QA merely to make an issue screen work would create unwanted authority. Instead propose a **new bounded issue read adapter/projection** for independently nominated issue actors on selected controlled credits, requiring original-caller independent actual source PO/line visibility. It exposes only needed receipt/credit/Heat/unit/quantity metadata and approved destination-scoped request/dispatch facts, not prices, unrelated request companies or a broad directory. Existing receipt reader policy remains unchanged.

Requester and recipient can read necessary material/handover facts without automatically reading technical certificates. Technical reviewer and dispatcher must independently authorize all required source/design/condition and pinned receiving evidence through actual-discipline `doc.view`, plan/module and per-table RLS, before byte access. If existing QA citation RLS prevents access, design an explicit exact-credit evidence-reference adapter: nomination alone grants no document/file access, no broad QA duty and no source-owner impersonation. Its projection, completeness proof and privacy tests must be reviewed before implementation; it does not exist today.

A source-visible issue nominee may see **complete per-credit aggregate posted debit and available quantity**, without seeing unauthorized recipient identities/reasons. This bounded aggregate disclosure is part of D3; do not derive availability by summing only detailed rows that happen to be visible.

### Recommended non-reserving first slice

Requests and approvals do **not reserve** stock. The supplied reference's MRV reservation stage is a future policy option, not implicitly implemented here. Stock reservation would require independent hold/release/expiry/cancellation arithmetic and is explicitly deferred. Existing DA-03 procurement reservations are a different ledger: issue must not debit/release/recreate them or infer demand/MTO entitlement.

Per exact controlled credit, in its exact unit:

`warehouse quantity remaining = accepted GRN credit − all posted controlled dispatch quantities`

`in transit = all posted dispatch quantities − all valid matching recipient acknowledgments`

Eligibility is a separate condition: a positive remaining quantity can still be on Hold and **not dispatchable**. Availability must not imply current release. Acknowledgment cannot exceed its dispatch balance or silently move rejected delivery back to stock. Returns require their own future inspection/credit workflow.

At dispatch, lock the authoritative credit/balance rows and relevant request/condition/configuration in a canonical shared order; revalidate nominations, original actor/source/evidence, exact approved request version, recipient/destination, current holds/readiness and full balance. Apply positive exact decimal quantity (existing ledger scale 3, no float/tolerance/conversion) against complete authoritative per-credit totals. All allocations of a dispatch post atomically or none; no automatic line substitution or partial fulfillment. Different units/Heat/credits never collapse into a generic item-code balance.

Proposed immutable dispatch/debit records plus a strictly derived minimal per-credit balance/completeness index permit guarded aggregates even when detailed recipient rows are hidden. Completion constraints, idempotent request identity and unique posting per approved dispatch intent prevent missing debit, duplicate debit or incomplete multi-line posting. Guard any future metadata/RLS changes against hidden-ledger undercount. Do not claim that RLS or a hash alone supplies full application authority or physical byte verification.

## 6. Proposed lifecycle and correction boundary

- Request: DRAFT → SUBMITTED → RELEASED or CHANGES_REQUIRED/REJECTED → DISPATCHED. Draft edits have versions; release pins exact submitted content. A changed submitted request requires a new version/review.
- Before dispatch: requester may reasonedly withdraw their own request under current request nomination; no reservation release because no reservation exists. Reviewer may append withdrawal of their own exact release under current grant. Safety holds independently stop dispatch. Withdrawn/rejected/changed requests cannot dispatch.
- Dispatch is immutable and atomic with the debit. After dispatch, no edit/delete/cancel-to-credit shortcut. Request withdrawal, PO cancellation or QA revocation cannot retroactively undo a physical handover.
- Recipient records exact dispatch, actual received quantity/time and discrepancies under their own company/current grant. Pending or disputed delivery does not recredit warehouse. Further dispute resolution, return, recovery or reversal remains a separately approved slice.
- Archived project: read retained authorized history; no operational mutations. Read access, nominations and required source/evidence eligibility checked independently at every new action; stale/revoked authority cannot replay posting.

Exact table/API names, output numbering, split acknowledgment semantics and transport/recipient rejection treatment need detailed implementation contracts before writers. “Dispatch posted” must never display “installed/consumed.”

## 7. Acceptance plan — proposed tests, not executed

1. No inherited issue grant from GRN, PO, QA, DA-01/03 or project configuration; company-role/custodian/recipient binding; stale, inactive, archived and hidden-source denial.
2. Three-person issue separation and independent recipient acknowledgment; recorder company is not impersonated custodian; original caller retained.
3. Historical accepted GRN with unknown preservation, expired shelf-life, new damage, open hold or inaccessible evidence cannot dispatch. Exact reasoned non-applicability and independent hold clearing; no QA rewrite.
4. New request/file/condition/destination/Heat/quantity version invalidates prior release. Partial hidden evidence cannot appear complete; authorization before all file reads, hash/size checks and late-failure rollback.
5. Two simultaneous dispatches cannot exceed one credit; visible partial recipient ledgers cannot overstate balance. Repeated same request creates one debit; changed intent rejected; atomic multi-credit failure rolls back all allocations/indexes.
6. 0.001 boundary and exact units; no mixed-unit/code pooling. An individually valid approval may lose capacity before dispatch because approvals are non-reserving; show insufficiency and require explicit replanning.
7. Acknowledgment reduces transit only, cannot exceed exact outstanding dispatch; shortage/rejection does not recredit; no installation/MTO/PO-state side effect.
8. Cutoff on legacy PIPING remains; no unknown legacy certification or generated credit from old movements. Source/read-projection privacy and aggregate completeness tests.
9. Bilingual UI explicitly distinguishes warehouse remaining, Hold, dispatchable, in-transit and received. No fabricated zero for unavailable/hidden values. Reasoned history, rollback and raw SQL guard tests alongside supported adapter tests.

No runtime tests were run for this documentation-only increment. Do not reuse baseline passing counts as issue implementation evidence.

## 8. Decisions requested and rollout order

Approval requested separately for:

- **D1 — `independent_issue_actions_three_person`**: proposed independent nominations, owner administrator, three-person request/review/dispatch separation, separately nominated recipient acknowledgment, safety-hold/independent-clear duties.
- **D2 — `fresh_issue_readiness_no_open_conditions`**: separate version-bound current readiness/preservation/condition assessment and restrictive holds; unknown/open required conditions block dispatch; no waiver or automatic release. Deliver readiness/hold prerequisites before dispatch activation.
- **D3 — `exact_credit_nonreserving_atomic_dispatch`**: non-reserving requests/approvals, exact-credit partial quantities with all-or-none submitted allocations, scoped issue read/evidence-reference adapter and complete per-credit aggregates; separate dispatch debit/recipient acknowledgment; no installed/consumed, return, demand-reservation or MTO mutation.

These are **proposals**, not accepted keys or live grants. Approval would authorize bounded detailed implementation planning, not named project personnel or automatic data admission. Recommended sequence after approval: nomination/read-projection contract → readiness/hold model → request/review → atomic dispatch → recipient acknowledgment, each with focused evidence; operational acceptance and deployment remain separate. Resolve the acknowledgment/discrepancy contract before enabling its writer.

## 9. Corrections to prior narrative

Older receipt design prose that says no GRN exists is historical; the current GRN guide governs that bounded implementation. Conversely, the supplied sample “In Stock → Reserved → Issued → Installed” is not an approved implemented state machine. Existing legacy float/advisory `issue/deliverToSite/returnToStore` methods cannot be reused as controlled writers. Existing GRN “accepted location” is not a delivered qualified-location/preservation subsystem. These gaps must be explicit, not silently cured by a usable-stock flag.
