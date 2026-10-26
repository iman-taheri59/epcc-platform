# Material receipt automation: integration and gaps

2026-10-23 · **Read-only source/document review; recommendations, not new authority or implementation.**
[فارسی](MATERIAL-RECEIPT-AUTOMATION-REVIEW-2026-10-23-FA.md)

## Executive finding

The concern is substantially correct: **there is no completed, version-bound operational chain from effective MTO demand to incoming accepted/usable material.** “No connection exists anywhere” would be inaccurate. Material identity and subsystem references, an MTO/PO comparison board, exact approved-MR-to-PO copying and PO-line-bound custody already exist. These are partial connections; they are not an end-to-end demand-allocation/receipt/stock reconciliation engine.

The recent work establishes controlled physical arrival, receiving QC, manual final QA and original-preparer source/heat amendments, with explicit authority, three-person separation, immutable history, fresh version checks and original-source privacy. Those controls are valuable and do not need replacing. They intentionally do not post GRN/accepted stock or cut over the legacy warehouse. Their focused tests do not certify MTO-to-stock integration or project material readiness.

## Current chain

```text
Accepted MTO snapshots + acceptance order + scope/evidence diagnostics
        -- operational effective selection/admission unresolved --> Effective demand
        -- no controlled MTO-row/selection-version demand allocation --> MR line
MR line -- exact approved full copy / issue allocation --> PO line
PO line -- explicit UUID, or unresolved arrival --> Physical custody / MRR
        -- immutable source/heat amendment --> Fresh receiving QC --> Fresh manual QA
        -- controlled GRN/posting NOT implemented --> Accepted stock / lot
        -- legacy issue/site ledger exists, not cut over --> Site / installation
```

| Segment | Evidence-based status | Important limit |
|---|---|---|
| Accepted MTO | Immutable batches/rows and newer acceptance journal exist | Chronology/observations are not authoritative effective scope admission |
| MTO vs PO | `OrderGapService::board` compares material ID + exact unit | It still sums accepted rows across revisions; not a selected-current-MTO engine |
| MTO → MR | MR can carry material library ID and subsystem | No controlled exact accepted-MTO-row/revision/selection basis allocation in MR entry |
| MR → PO | Exact source-line FK/full-copy and issued commitment ledger implemented | Controls approved MR capacity, not an effective MTO demand budget |
| PO → custody | Original-caller-visible PIPING PO line, immutable snapshot, unresolved entry allowed | Source linkage is not a lot posting or a demand allocation |
| Custody → QC → QA | Implemented with exact amendment and QC binding, independent authority, three actors | Manual technical assessment, not automated applicability/certificate verification |
| QA → controlled GRN/lot | Not implemented | ACCEPTED does not create stock or an issue permission |
| Warehouse → site | Legacy lot/movement paths exist | Not comprehensively bound to the new QA/amendment chain; delivery is not installation |

## Findings and severity

### P0 — effective MTO selection is not integrated into operational totals

`OrderGapService::board` queries all accepted rows and adds gross by `(material_item_id, unit)`. Example: the same scope has accepted revisions100 and120; this aggregation can produce220 instead of replacing the old snapshot with120. Existing G01 journal/scope/CDE diagnostics are expressly UNADMITTED/UNRESOLVED; they do not resolve the production board's selection. Revision removal, overlapping scopes, authoritative hierarchy versions and exact-pair additivity evidence need resolution. A latest timestamp or latest-per-material shortcut would also be wrong.

Rows without material identity are skipped by this board. Their omission must not be presented as resolved/zero demand. Missing authoritative scope/selection must be UNKNOWN/UNRESOLVED, not a green coverage badge.

### P0 — missing MTO-to-MR demand provenance and quantity allocation

`MaterialRequisitionEntryService` accepts manual lines with optional material identity/subsystem and stores the entered source quantity. `MaterialRequisitionItem` has no controlled accepted-MTO-row/selection-version demand allocation. Same material ID, code or description is not proof of which MTO revision/scope/quantity was requested. Therefore the exact MR→PO ledger does not establish that the MR itself was within effective MTO need, nor prevent duplicate requisitions against that need.

Do not infer this missing authority from MR/PMS rights. MTO access/admission remains deferred. Manual/emergency/unplanned procurement needs an explicit basis class and reconciliation policy, not fabricated MTO ancestry; physical arrival must remain recordable even without MTO/PO.

### P0 — quality acceptance and stock are still separate islands

The controlled chain ends at QA. No authoritative GRN connects exact custody/amendment/QC/QA to lot quantities and the material ledger. Consequently “arrived”, “QA accepted”, “posted”, “released”, “reserved”, “available” and “delivered/installed” cannot be interchangeable counts. The aggregate accepted/posted quantity ceiling across multiple receipts against one PO line is not supplied by the QA per-receipt ceiling.

The legacy `WarehouseService::receive` creates a lot and receipt movement without binding to the new QA decision; `issue` checks positive quantity, a locked lot and balance, without a current new-QA/amendment gate. `MaterialsController::issue` invokes that path. This is a source-level integration risk, not a claim that a fresh receiving HTTP route is exposed, that arbitrary users are authorized, or that an actual bypass/production incident occurred. Legacy cutover has explicitly not happened.

### P1 — stock/source identity is not consistently propagated

`MaterialLot` permits `purchase_order_item_id`, but the inspected legacy `receive` implementation sets only PO header identity, not that line FK, and uses separately supplied code/unit/description. The new custody line reference is stronger, but it is not yet converted to the lot. There is no universal material-ID/MTO-basis/custody/amendment/QC/QA chain on stock. Code matching can join distinct specifications, duplicate lines or units. Optional material identity in MR/PO leaves more cases unresolved; identity readiness should be explicit, not guessed.

### P1 — legacy quantitative reports can mislead

1. `refreshPurchaseOrderState` sums quantities across the PO's lines and receipt movements without a per-line/unit reconciliation. Example:10m pipe +10EA flange vs20m pipe may satisfy the numeric header total while flange receipt is zero. Summing unlike units is not a valid completion metric; floats add another precision concern.
2. `subsystemMaterialStatus` matches SITE movements by project/subsystem/item code for every PO line. Two10-unit lines with the same code and one10-unit site delivery can each receive the same10-unit coverage, yielding false aggregate completeness. It also does not enforce current QA/released stock or MTO need in that arithmetic.
3. That report treats no ordered lines as complete. This historical policy is not proof that effective MTO/engineering need is zero; it can mask demand that never became a PO. Do not silently redefine the existing policy—separate “no applicable need confirmed” from “no demand data”.
4. `OrderGapService` counts ISSUED/PARTIALLY_RECEIVED, not RECEIVED POs. A completed order can disappear from order coverage. Whether the business metric should retain completed procurement coverage or represent open commitments is a deferred semantics decision. Do not automatically add a state without settling the metric.

These are code-derived risks/examples, not newly executed failing regressions or observed production incidents.

### P1/P2 — later material-control gaps

- One receipt/working basis is not a structured multi-item/multi-heat inspection/disposition model. Partial acceptance/rejection/split/merge/surplus/shortage/damage/returns need explicit quantity-conserving rules; partial physical receipt is distinct from changing the approved full-copy MR→PO buying model.
- A post-GRN correction, newer QC, withdrawn evidence or Hold needs explicit blocking/compensating workflow, not silent alteration of posted stock. Existing amendments are pre-GRN and do not authorize stock reversal.
- Shelf life, preservation, location/custody handover and reinspection can affect usability; applicable rules are not implemented by the current QA attestations.
- Heat/MTC → lot → issue → spool/joint/isometric → test-pack traceability is not proven end-to-end by PO/receipt linkage. Site delivery is not installation or consumption.
- A receipt-wide all-green badge must not conceal unavailable source evidence, unresolved demand, legacy stock or unknown allocations. Hidden data must not be treated as zero or disclosed to complete a report.

## Recommended target design — proposal only

Use separate stable identities and quantity allocations, not a single guessed MTO foreign key:

```text
Admitted effective MTO selection/version
 → exact accepted demand rows / stable scopes / material ID / exact unit
 → explicit demand-to-MR allocation amounts
 → existing MR line → PO line → issued source allocation
 → custody + exact amendment → QC → QA
 → idempotent controlled GRN → lot/heat + ledger
 → explicit reservations/issues → site → installation/consumption evidence
```

Demand rows may be consolidated and one PO line may arrive in multiple shipments/heats; a quantity-bearing allocation relationship is often needed rather than assuming1:1 everywhere. This is a design recommendation, not authorization to expose partial buying, cross-company import, automatic proration, new MTO grants or a new allocation writer. Preserve existing approved full-copy MR→PO execution until separately changed.

Keep the original requirement basis and current requirement selection separately. A new MTO revision must not overwrite the historical basis of existing MR/PO/receipt records. Reconciliation must report changed scope, removed items, changed specifications/units, surplus and shortage explicitly. Material-library identity alone is not a demand version.

Use distinct proposed indicators at identical identity/scope/unit grain:

- **Procurement gap:** applicable effective gross procurement need minus eligible procurement coverage, with over-plan separate. Define completed-order coverage explicitly.
- **Physical/accepted outstanding:** separate physical arrivals, QA decisions and net posted accepted receipts per actual PO line. Do not count QC/QA versions as additional quantities.
- **Usable execution gap:** remaining valid engineering execution need minus stock actually released and allocatable to that scope; account for reservations and confirmed installation/consumption without double counting. Gross MTO, net engineering need and free stock are different bases.

Unknown demand/source/quality/release is an explicit state, not numerical zero. Return quantities and uncertainty separately; do not mix M and EA or double-count a shared lot across scopes.

## Delivery priority and critique of the development sequence

Separating physical custody from acceptance/stock was correct and must remain. However, continuing vertical features without an end-to-end demand/material identity contract has left well-controlled islands. More QA detail or a standalone GRN alone will not close the MTO gap. The next roadmap should not claim receipt-to-stock automation is already complete.

1. **First: read-only integration/readiness audit and a traceability contract.** Show which actual receipt lines have exact PO/MR/material/scope ancestry, which lack MTO basis, which are legacy/unknown, and which effective MTO scopes remain unresolved. Preserve current source permissions. Ratify demand identity, allocation lifecycle and any deferred MTO authority before adding writers.
2. **Then: operational effective-MTO selection/admission (G01) and controlled demand-to-MR provenance.** Resolve ordering/scope/overlap/legacy cases; fail closed for unresolved totals; do not infer admission from CDE byte integrity or journal order.
3. **Then/alongside the agreed contract: controlled GRN and legacy cutover.** Independent `post_grn`, exact live QA/QC/amendment/source/evidence, atomic per-line cumulative ceiling, request idempotency and lot/ledger provenance. Define post-posting invalidation, quarantine/release and legacy reconciliation; do not silently disable legacy behavior or legitimize legacy data.
4. **Prove one small end-to-end pilot before broad automation.** One admitted MTO scope/material/unit → MR → issued PO → two partial physical receipts/heats → missing-doc Hold → versioned evidence → fresh QA → GRN → scoped issue; then revise the MTO and verify no duplication/automatic rewriting. Include same-code distinct items, shared stock, hidden sources, concurrent receipts and stale QA/duplicate GRN negatives.
5. **Expand to structured exceptions, preservation and installation/test-pack traceability.** No automatic fuzzy matching or new roles without approval.

## Source anchors and verification boundary

Inspected current files, not only historical guides:
- `app/app/Services/{MaterialRequisitionEntryService,PurchaseOrderEntryService,OrderGapService,WarehouseService}.php`
- `app/app/Models/{MaterialRequisitionItem,PurchaseOrderItem,MaterialLot}.php`
- `app/app/Http/Controllers/MaterialsController.php`
- `docs/PO-MR-ISSUE-ALLOCATION-EN.md`, `docs/ORDER-GAP-HUMAN-BOUNDARY-EN.md`
- `docs/G01-REAL-ACCEPTANCE-JOURNAL-EN.md`, `docs/G01-RECONCILIATION-EVIDENCE-FULL-SUITE-EN.md`, `docs/ADR-024-EFFECTIVE-MTO-SELECTION-DESIGN-EN.md`
- `docs/PIPING-{PHYSICAL-CUSTODY,RECEIVING-QC,QA,CUSTODY-AMENDMENTS}-EN.md`

No application/schema/authority change, test run, database start, migration, deployment or actual project-data audit in this review. Prior69/729 focused result concerns the preceding custody/QC/QA increment, not proof of the integration proposed here. No implementation percentage or production incident is inferred.
