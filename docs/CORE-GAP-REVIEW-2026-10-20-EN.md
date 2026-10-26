> **Core receipt matrix accepted — 2026-10-22:** [first-slice contract](PIPING-RECEIPT-FIRST-SLICE-CONTRACT-EN.md) / [فارسی](PIPING-RECEIPT-FIRST-SLICE-CONTRACT-FA.md). Material-controller MRR → QC review → QA acceptance → warehouse GRN; owner-company current project.configure nominates independently. MRR/QC/QA are three different people; GRN may overlap with its independent grant. These choices are CLOSED; earlier pending-matrix prose applies only to advanced actions now. No runtime grant/screen/receipt implemented; structured prerequisite items deferred.

> **Supplied receipt reference / user policy — 2026-10-22:** [receipt-to-storage design](PIPING-RECEIPT-STORAGE-DESIGN-EN.md) / [فارسی](PIPING-RECEIPT-STORAGE-DESIGN-FA.md), [ADR-046](ADR-046-PIPING-RECEIPT-STORAGE-EN.md). Independent physical custody plus controlled acceptance/no ordinary HOLD or REJECTED issue accepted; authority matrix must be approved before activation. Only this boundary supersedes ADR-032 R4’s blanket no-new-gate statement; other pending rules remain unapproved. Current selected work is reference-driven design, not the prior allocator recommendation or receipt activation. No app/schema/grant change; structured prerequisite items remain deferred.

# Core capability gaps and recommended development order

2026-10-20 · **SOURCE/DOCUMENT REVIEW COMPLETED — RECOMMENDATIONS, NOT IMPLEMENTATION APPROVAL**

[فارسی](CORE-GAP-REVIEW-2026-10-20-FA.md) · [Current ledger](CURRENT-IMPLEMENTATION-LEDGER-EN.md) · [Review evidence](evidence/2026-10-20-core-gap-review/README.md)

## 1. Executive conclusion

Stop extending prerequisite tracking for now. Its current free-text, inherited-stage, register/filter, history and current PDF slices are delivered with bounded prior evidence. **The user explicitly deferred both design and implementation of structured within-stage prerequisite items.** They are not the next task, not a dependency for the work below, and must not be restarted implicitly.

The most valuable next feature area is the missing **human physical receipt workflow**, but adding a receive form over today's service would expose unresolved provenance and quantity arithmetic. First address the known document-number allocation defect and diagnose the narrowly specified warehouse cases. Then obtain only the genuinely missing receipt-policy decisions and build one usable exact-line receipt slice. Do not redesign procurement wholesale.

This review authorizes no new role, read delegation, schema, hard readiness gate or business-rule change. Prior accepted decisions are preserved. Piping-first remains product direction; this sequence strengthens its cross-module chain rather than declaring other disciplines out of scope.

## 2. What is actually present

| Area | Reviewed status | Important limit / references |
|---|---|---|
| CDE / documents | Register, revisions, reviews, files and transmittal slices exist | Document number allocation is still RLS-visible MAX-based; see D01 below. Not a complete EIM product. |
| PMS / piping | Class rules, branch resolution, line/component/joint core and retained PMS pins exist | Not full authoring, fit-up, equipment/nozzles or welding/NDT. `PipingClassService`, `ComponentService`, ledger; old “PMS design only” is stale. |
| MR → PO | Human draft creation, category separation, controlled pricing, separate finance/issue authority, same-version execution and MR commitment allocations exist | ADR-033–040; a commitment allocation is not receipt, usable material, automatic MR ORDERED or legacy reconciliation. |
| PO inspection observations | Independently nominated, create-only human entry on an originally visible PO exists | ADR-041; no default grant or delegated PO reading. FAIL/missing inspection remains advisory for physical receipt. |
| ITP notifications | Draft/edit/history, activity window, issued evidence, cancel-only, company receipts, prerequisites/register/filters/current PDF exist | ADR-042–045 and current PDF guide; not full ITP approval, technical result/ITR closure, transport or work permission. |
| Warehouse/site | Receipt service, lots/movements, issue/delivery/reversal surfaces exist | No human receipt caller found in reviewed `app/app`/`app/routes`; exact PO-item linkage and arithmetic gaps remain. |
| MTO | Accepted immutable snapshots, NEW journal, topology/reconciliation/evidence diagnostics exist | G01 remains unresolved/unadmitted; read-only diagnostics are not an operational effective selector. MTO permission redesign stays deferred. |
| Completion / delivery | Punch/MCC, partial test-pack service/routes, local-asset and PDF checks exist | Not full hydrotest execution, welding/NDT, RFSU/PAC/FAC/dossier or certified offline installation/restore. |

“Exists” is source-reviewed presence, not a new runtime pass. This session ran no PHPUnit, database, browser, CI or pilot scenario. Prior prerequisite-PDF proof is 20/1102 plus separate 4/60; it is historical, not a current complete-suite result. No global completion percentage is defensible.

## 3. Prioritized backlog

Ranks are recommendations based on integrity risk, cross-module dependency and usable feature value—not approved delivery dates.

| Rank / ID | Work and reason | Next bounded deliverable | Approval boundary |
|---|---|---|---|
| P0 / D01 | Document numbering collision can block core engineering records across companies | Confirm current behavior with a focused private-document regression; review atomic allocator design and number-gap requirements; then implement an approved correction | Do not expose hidden documents, widen RLS or silently approve a new counter policy/schema. Keep accepted number format/key unless explicitly changed. |
| P1 / D02 | Warehouse provenance/progress can produce misleading quantities; needed before human receipts | Targeted diagnostic fixtures for repeated code, mixed units, amendments, returns/reversals and PO completion. Report observed outcomes; no semantic patch by inference | ADR-032 is still PROPOSED. Diagnose independently of approving its candidate rules. |
| P1 / D03 | Human receipt is the remaining application-entry break in PO → inspection → warehouse | After D02 and necessary decisions: one same-project, exact-PO-line receipt form, immutable source attribution and receipt history; localized and original-reader scoped | Decide recipient/warehouse recorder authority, eligible source reading, lineage/legacy handling and reversal semantics. Do not inherit PO drafter/issuer or inspection nomination. No new grant approved here. |
| P1 parallel / D04 | G01 prevents trustworthy combined revision/scope demand and end-to-end material coverage | Continue bounded technical provenance/evidence/diagnostic work; list the precise unresolved operational admission dependency | Accepted revision/overlap principles are closed. Operational admission writer/new MTO gate/selector activation is not authorized by this review. Do not wait on this for every independent warehouse diagnostic. |
| P2 / D05 | An integrated journey is still not certified by component suites | Run a bounded synthetic, authenticated MR → approved PO issue → existing inspection path; mark receipt unavailable until D03. Run the full MTO-to-usable pilot only after its actual dependencies are resolved | No seed/SQL shortcut labelled human workflow, no assumed source access, no “full pilot passed” from a subset. Existing three-person PO separation remains. |
| P2 / D06 | On-premise delivery needs operational evidence, not just unit coverage | Installer/dependency inventory and isolated backup/restore + offline PDF rehearsal at a release milestone | No production data/environment changes; not a new full regression on each small edit. |
| Later / D07 | Equipment/nozzles, welding/NDT, full test execution and turnover are genuine piping gaps | Scope one vertical slice when upstream material/provenance priorities are stable | FR-QA-05/06 and FR-COM-05/06 are target requirements, not implemented authority. Detailed fit-up/ITP decisions and usable-stock/signature policy are not inferred. |

### D01: strength of evidence

`DocumentRegister::nextSequence()` computes `1 + Document::...->max('sequence')`, under caller visibility; `register()` uses it separately for the printable number and sequence. Prior [reconciliation evidence §unrelated defect](G01-RECONCILIATION-EVIDENCE-EN.md) records a real private-fixture SQLSTATE23505 collision when one company cannot see another's existing same-project/discipline/type number. Current source still has this allocation approach. **Historical internal-service reproduction; not newly reproduced here, not proof of an authorized UI exploit or simultaneous race.** Review atomicity, non-disclosing allocation, immutable existing numbers, migration/backfill, rollback, concurrency and archive behavior before implementation.

### D02: exact diagnostic set

- **Repeated code:** two 10EA PO lines share a code; delivering 10 for one must not be presented as completion evidence for both. Today `subsystemMaterialStatus()` credits SITE movements through `MaterialLot.item_code` for each line.
- **Mixed units:** M and EA must not form one physical completion denominator. Today `refreshPurchaseOrderState()` sums all PO-item quantities and all receipt quantities; subsystem totals also mix line quantities.
- **Amendment:** retained predecessor 80 and successor 90 must not silently become demand170. Establish how receipts before/after amendment and cancellation are attributed before selecting a fix.
- **Completed PO:** `OrderGapService::EFFECTIVE_STATES` excludes RECEIVED, while `WarehouseService` can produce it. ADR-015's “issued and not cancelled” versus narrower implementation remains an explicit ADR-032 conflict. Whether this report is historical ordered coverage or outstanding commitments needs reconciliation; do not simply add RECEIVED to receipt eligibility.
- **Legacy/returns:** `receive()` creates a lot with PO header/code/unit but omits `purchase_order_item_id`; a nullable model column is not implemented lineage. Unknown old links remain unknown, not backfilled by code. Reversal and return effects require ledger-aware tests, not deleting history.

These are source-confirmed mechanisms and specified risks, **not new runtime failure evidence**. Existing `WarehouseTest` cases cover basic receipt/state/stock/advisory inspection; their presence does not prove these combined cases.

## 4. Documentation conflicts resolved by this review

| Existing wording | Current disposition |
|---|---|
| Pilot PP-21/G07: no PO/inspection/receipt application callers | Partially superseded: PO creation/finance/issue and inspection routes now exist; receipt remains missing. Split the status instead of declaring G07 closed. |
| Ledger: notification editing/cancellation deferred | Superseded by implemented bounded editing, history and cancel-only; replacement/reissue/transport remain deferred. |
| Roadmap/TODO: PMS only a design; test packs absent | Core class service and partial test-pack service/routes exist; neither is full PMS authoring/hydrotest completion. Do not restart implemented foundations. |
| “Current complete source is green” in older dated headers | Historical evidence tied to its recorded source. Later focused checks cannot certify all current source/CI. This review makes no new runtime claim. |
| Structured prerequisite items proposed as next task | Explicit user deferral now recorded; both design and implementation paused. Free text and existing inherited-stage behavior stay unchanged. |

Dated historical text and evidence are retained; updated ledger rows and notices point here. Review ranking does not supersede domain ADR decisions. No unresolved decision is silently accepted.

## 5. Decisions that must not be reopened or inferred

- MR/PO are category-separated; PO drafter, financial approver and issuer are three actual people, with separately nominated target-buyer authority and same-version approval. Original-drafter live pricing grant/full-copy MR issue allocation remain.
- Inspection entry is independently nominated and uses only originally visible POs. Notification grants, cancel-only, separate company receipts and prerequisite company-management/sender-report/recipient-review/shared-history choices remain.
- Accepted MTO revision precedence, overlap, pinning and immutable provenance principles remain; no automatic MTO rights from PMS/MR/PO policy.
- Received, inspected, delivered, fulfilled prerequisite and usable stock/technical acceptance/work permission are distinct.
- Structured prerequisite items are explicitly deferred by the latest user instruction. No schema design or implementation for them in this review.

## 6. Proposed immediate next increment

**A focused document-number allocation regression and correction-design note (D01), followed by warehouse diagnostic fixtures (D02).** This is the recommended sequence, not code already delivered. If a fresh reproduction shows the old numbering defect no longer applies in its legitimate supported path, document that outcome and proceed to D02; do not force a repair based only on historical evidence.

For fast delivery, run affected tests only. Full regression belongs at a cross-module milestone/high-risk change/release, with source frozen and results captured—not after every small change. D03 is the next recommended user-visible feature after its specific policy prerequisites, not another notification micro-enhancement.

## 7. Review limits

Static inspection of named source, routes, existing test names, ADRs and local evidence only; no exhaustive audit of every module, new failure reproduction, production/security/engineering approval, commit, push or deployment. Application sources and dependency locks must remain byte-identical throughout this documentation review; evidence records the comparison. No external research was needed to override or restate settled project decisions.
