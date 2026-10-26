> **Current 2026-10-23 — custody-amendment increment:** original-preparer/live-custody-grant source/Heat amendments are implemented; exact amended-basis fresh QC and QA are required. [Current guide](PIPING-CUSTODY-AMENDMENTS-EN.md). Earlier correction-as-proposal statements are historical. Controlled GRN/stock/legacy cutover are still unimplemented.
> **وضعیت جاری — اصلاحیهٔ تحویل‌گیری:** اصلاح منبع/Heat فقط توسط تهیه‌کنندهٔ اصلی با معرفی جاری اجرا شده است؛ QC و QA تازه روی همان نسخه لازم است. [راهنمای جاری](PIPING-CUSTODY-AMENDMENTS-EN.md). پیشنهاد قدیمی اصلاحیه اکنون اجرایی است؛ GRN/موجودی و گذار انبار قدیمی هنوز اجرا نشده‌اند.

> **وضعیت جاری ۲۰۲۶-۱۰-۲۳:** تصمیم نهایی QA با شواهد نسخه‌دار انسانی و منع پذیرش شرط باز اجرا شده است؛ [راهنمای جاری](PIPING-QA-EN.md). گزاره‌های قبلی دربارهٔ QA اجرا‌نشده/سیاست باز، تاریخی‌اند. GRN و گذار مسیر انبار قدیمی هنوز اجرا نشده‌اند.
> **Current 2026-10-23:** version-bound manual QA and open-condition blocking are implemented; [current guide](PIPING-QA-EN.md). Earlier unimplemented/pending QA statements are historical. Controlled GRN and legacy warehouse cutover remain unimplemented.

> **Receiving QC delivered — 2026-10-22:** [guide](PIPING-RECEIVING-QC-EN.md) / [فارسی](PIPING-RECEIVING-QC-FA.md). Immutable parent-bound receiving observations, independent current QC nomination/original source scope; actual MRR preparer cannot self-review.50/578 PASS (16 QC +20 custody +14 PO inspection). Only MRR≠QC executable: no QA/GRN/stock release or automatic technical checklist/accepted-with-comment policy. Earlier no-QC-action statements are historical for this increment. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

> **Physical custody / initial MRR delivered — 2026-10-22:** [guide](PIPING-PHYSICAL-CUSTODY-EN.md) / [فارسی](PIPING-PHYSICAL-CUSTODY-FA.md). Current nominees + independent source reading, and explicitly nominated recorder from any eligible participant accepted/implemented. Final20/242; related milestone60/438 and config text2/43 PASS, overlapping not additive. CUSTODY_ONLY, no QC/QA/GRN/stock/source-read grant. Earlier no-custody/no-resolver statements are historical for this increment. Actual three-person separation remains required for later actions. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

> **Receipt nomination configuration delivered — 2026-10-22:** [guide](PIPING-RECEIPT-ACCESS-EN.md) / [فارسی](PIPING-RECEIPT-ACCESS-FA.md). Owner-admin-only screen, explicit custodian/four independent sets, version/suspension/history and FORCE RLS implemented.28/278 PASS; subsequent view2/43 overlaps. No custody/MRR/QC/acceptance/GRN action/resolver implemented, no default grants/source-read expansion. Earlier no-screen/no-schema prose is historical only for this increment. Not full-suite/current-CI/interactive-browser/deployed/committed/pushed; structured prerequisite items deferred.

> **Core receipt matrix accepted — 2026-10-22:** [first-slice contract](PIPING-RECEIPT-FIRST-SLICE-CONTRACT-EN.md) / [فارسی](PIPING-RECEIPT-FIRST-SLICE-CONTRACT-FA.md). Material-controller MRR → QC review → QA acceptance → warehouse GRN; owner-company current project.configure nominates independently. MRR/QC/QA are three different people; GRN may overlap with its independent grant. These choices are CLOSED; earlier pending-matrix prose applies only to advanced actions now. No runtime grant/screen/receipt implemented; structured prerequisite items deferred.

> **Supplied receipt reference / user policy — 2026-10-22:** [receipt-to-storage design](PIPING-RECEIPT-STORAGE-DESIGN-EN.md) / [فارسی](PIPING-RECEIPT-STORAGE-DESIGN-FA.md), [ADR-046](ADR-046-PIPING-RECEIPT-STORAGE-EN.md). Independent physical custody plus controlled acceptance/no ordinary HOLD or REJECTED issue accepted; authority matrix must be approved before activation. Only this boundary supersedes ADR-032 R4’s blanket no-new-gate statement; other pending rules remain unapproved. Current selected work is reference-driven design, not the prior allocator recommendation or receipt activation. No app/schema/grant change; structured prerequisite items remain deferred.

> **Current review / user deferral — 2026-10-20:** [core gaps and priorities](CORE-GAP-REVIEW-2026-10-20-EN.md) / [فارسی](CORE-GAP-REVIEW-2026-10-20-FA.md). Structured prerequisite item design AND implementation explicitly deferred; free text retained. Source/document review, not a test run or new authority/schema approval. Recommended next: focused document allocation review, then warehouse provenance/arithmetic diagnostics; next user-visible target is exact-line PO receipt after needed decisions. Conflicting older status/“current full source green” statements below are historical evidence, not this review’s current verification.

# Piping Pilot — Acceptance Matrix and Integration Gaps

> Full journal-source gate NOW GREEN1098/16429,zero failures/errors/skips: `G01-JOURNAL-FULL-SUITE-VERIFICATION-EN.md`. Earlier pending notices below are historical. Effective scope selection and the integrated pilot remain open.


> Next implementation update: real NEW acceptance chronology is now connected (always UNRESOLVED). See `G01-REAL-ACCEPTANCE-JOURNAL-EN.md`:89 related tests/240 assertions +5 app-service contention scenarios; FULL SUITE ON CHANGED SOURCE PENDING. No effective board switch or pilot completion.


> G01 persistence update:36 isolated real-PG prototype cases passed; see `G01-POSTGRES-PERSISTENCE-PROOF-EN.md`. Synthetic source model, NOT application/pilot integration. The integrated pilot remains unexecuted.


> G01 design update:33 standalone contract cases passed;3 bounded mutation probes detected. See `ADR-024-EFFECTIVE-MTO-SELECTION-DESIGN-EN.md`. Application board unchanged; integrated pilot NOT executed.


Date: 2026-10-18. Status: **PLANNING / SOURCE-REVIEWED; pilot not executed**.
Baseline: `main@0c54c7f` plus local stabilization repairs. This document maps existing requirements and code to a proposed synthetic pilot. It is not approval of a new schema, permission, signature policy or release gate.

## 1. Boundaries

- Keep Iran-first, piping-first and all other EPCC disciplines in product scope.
- Use only synthetic data and a disposable database; never import customer documents into a public demo.
- Keep gross accepted MTO, net engineering need, effective ordered quantity, received stock, issued stock, site delivery and usable stock distinct.
- MTO permission redesign remains deferred. MR nomination does not grant approval rights; PMS nomination is a separate policy.
- TQ/NCR capture is not issuance, signature verification, final disposition or engineering acceptance. Detailed Fit-up remains deferred to the project's ITP definition. Do not restart paused ITP work implicitly.
- Read-only PCF admission is not accepted model import or a second MTO authority.
- Full-suite evidence is a software gate, not proof that the pilot below has run end to end. See the final-source verification report for the actual test result.

## 2. Synthetic fixture contract

Reuse the existing PH-14 tenant/project fixtures, with an owner-company project administrator, an explicitly nominated EPC MR author, an independently nominated PMS author and separate technical/commercial actors permitted by the existing workflow. Do not mint new roles or treat company type alone as a newly approved human authority design.

A proposed dataset, not an installed seed:

| Object | Synthetic value |
|---|---|
| Physical scope | One stable, versioned PH-14 subsystem; use actual generated UUIDs at runtime |
| Piping basis | DN150/NPS6, controlled PMS snapshot with schedule40; separate schedule80 discrepancy |
| Catalogue | Exact project material UUID for `PIP-CS-6-SCH40`, base unit `M` |
| Accepted MTO | One document/revision, gross `100.000 M` |
| Net estimate | `105.000 M`, linked to the exact accepted row and scope, with source revision/evidence |
| Ordinary PO | `80.000 M`, initially DRAFT then ISSUED, explicitly attributed to the catalogue item |
| Separate amendment branch | Replace an 80 line with a 90 line; do not add them |
| Separate receipt branch | Receive30 then50 against an unamended80 order; avoid conflating this case with the amendment case |
| Physical ledger branch | Receive50, issue30, deliver20 to site: store20, in-transit10, site20; no inferred usable quantity |
| Negative identity branch | Two distinct PO items with the same code, 10EA each; delivery10 attributable to only one item |

Quantities in different units are never added into a material-coverage total. The dataset contains no real engineering approval or valid issued customer certificate.

## 3. Acceptance matrix

`Component evidence` below names existing tests, not execution of this integrated pilot. `Gap` means source-reviewed/pending reproduction or design; do not invent a passing result for it.

| ID | Scenario / expected evidence | Existing component evidence | Pilot status / dependency |
|---|---|---|---|
| PP-01 | Current source passes configured Unit+Feature suites using non-superuser PostgreSQL and zero skips | Final-source full JUnit; RLS suites | Runtime gate PASSED1083/16385 with zero failures/errors/skips; `FINAL-SOURCE-VERIFICATION-2026-10-18-EN.md`; not an E2E pilot result |
| PP-02 | Nonparticipants cannot see/mutate pilot project records; archived reads remain but writes are blocked | RowLevelSecurityTest, MultiProjectTest, ProjectArchiveTest | Component evidence; integrated actor matrix still to run |
| PP-03 | PMS attachment/draft edit requires its own current nomination; MR nomination does not replace it | PmsAuthorPolicyTest, PmsClassDocumentTest, MaterialRequisitionEntryTest | Component evidence; no new authorities |
| PP-04 | Issued line pins immutable PMS snapshot; next draft revision does not rewrite issued line basis | PmsClassDocumentTest, ComponentModellingTest | Component evidence; fixture publication is not customer approval |
| PP-05 | Schedule80 versus pinned40 creates discrepancy/history; IN_SYSTEM remains unissued draft and EXTERNAL_REGISTER remains unverified metadata | ComponentModellingTest, ADR-029/030/031 | Capture can be exercised; complete signing/disposition flow is NOT delivered |
| PP-06 | One bad MTO row rejects the entire acceptance; successful accepted source/material/scope labels remain immutable | MtoAcceptanceTest | Service/DB evidence; do not promise a complete customer-facing MTO authoring UI |
| PP-07 | Same document/scope accepted as A=100 then B=110 contributes110, not210; both snapshots remain auditable | ADR-024; existing OrderGapTest only covers simpler fixtures | **Gap G01**: effective accepted revision selection |
| PP-08 | Parent/descendant or overlapping sibling scope cannot silently aggregate; additive evidence is exact-revision-specific | MTO requirements and ADR-024 | **Gap G01**: schema/selection/evidence implementation pending; do not ask again whether overlap matters |
| PP-09 | Net105 versus accepted gross100 reports shortage5 without percentage tolerance; gross above net is delta only | EngineeringEstimateTest | Component evidence; automated model-to-estimate ingestion not implied |
| PP-10 | Authorized human creates MR DRAFT with exact source spelling; nominee cannot approve their own request; no MTO source link is fabricated | MaterialRequisitionEntryTest, ProcurementTest, ADR-018 | Component evidence; accepted-MTO-to-MR linkage is a later explicit design |
| PP-11 | DRAFT80 contributes0; ISSUED80 against gross100 yields order gap20; unmatched codes remain unmatched | OrderGapTest | Single-revision component evidence |
| PP-12 | Amendment80→90 contributes90 (gap10), not170; cancelled order contributes0 | OrderGapTest | Component evidence; warehouse progression across amendments is separate PP-17 |
| PP-13 | Allocate60 of an80 order explicitly; remainder20 stays unallocated; over-allocation fails; no proration | OrderGapTest | Component evidence; no automatic mapping from bulk scope to a line |
| PP-14 | Partial receipt must not change historical ordered80. Proposed: full receipt also must not erase ordered80 from procurement coverage | OrderGapService effective predicate; WarehouseTest | **Gap G02**: RECEIVED is currently excluded; ADR-032 proposal requires reconciliation |
| PP-15 | Receipt lot retains exact originating PO item/lineage and compatible unit; code/description alone cannot choose between repeated lines | MaterialLot model, WarehouseService::receive | **Gap G03**: field exists but normal receipt path does not populate PO-item FK |
| PP-16 | Delivery10 to one of two identical-code 10EA lines cannot satisfy both lines | WarehouseService::subsystemMaterialStatus; WarehouseTest | **Gap G04**: legacy item-code rollup requires reproduction/repair design |
| PP-17 | Receipt progress is evaluated by appropriate item/unit/lineage, not sum of unrelated units or superseded quantities | WarehouseService::refreshPurchaseOrderState | **Gap G05**: legacy PO-total arithmetic; semantics/design pending |
| PP-18 | Received50, store20, in-transit10 and site20 are shown distinctly; FAIL/missing inspection remains visible, not silently usable | WarehouseTest; advisory inspection rule | Physical ledger components exist; **Gap G06** usable-stock definition/authority unresolved |
| PP-19 | FA/EN task views, mobile RTL and formal MCC PDF retain correct scope/status/evidence | Browser smoke, DateFormattingTest, PdfExportTest | Component smoke/regression evidence; customer UAT still required |
| PP-20 | RFSU/PAC/FAC and final handover use complete verified readiness evidence | Commissioning roadmap | Not complete; do not mark pilot full-lifecycle accepted |
| PP-21 | Human PO creation/issue, inspection and physical receipt through approved authenticated paths | ADR-033–041; MaterialsController; web routes | **PARTIAL / source-reviewed 2026-10-20:** human PO and inspection routes now exist; receipt entry still missing. Integrated pilot not run; no new visibility/receipt grant. |

## 4. Source-reviewed gaps (not newly executed regressions)

### G01 — Effective accepted MTO revision/scope selection

`OrderGapService.php:251–276` loads all project accepted rows and aggregates by material+unit. It does not choose a current revision or enforce cross-document scope additivity. This is already recorded in ADR-015 §10 and ADR-024. Do not copy a parallel BaselineService, mutate immutable accepted batches, or declare the latest timestamp across unrelated documents the winner. The domain principles are already accepted; the implementation must define deterministic exact-document/scope ordering and evidence storage.

### G02 — Fully received order disappears from order coverage

`OrderGapService.php:35–38,390–398` uses only ISSUED/PARTIALLY_RECEIVED. `ProcurementState` separately defines RECEIVED, reached by warehouse receipts. Thus the normal lifecycle can remove a completed order from a report described as gross-versus-effective-ordered. ADR-015's accepted prose says issued and not cancelled, while its implementation record names the narrower predicate. Record/reconcile this conflict in ADR-032 before changing semantics. Keeping an order in historical ordered coverage must not reopen receipt acceptance for an already fully received PO.

### G03 — Receipt's exact PO-item link is not populated

`MaterialLot::$fillable` includes purchase_order_item_id, but `WarehouseService::receive` receives a PO header plus free-text itemCode/unit and omits that FK from its lot insert (`WarehouseService.php:155–217`). Do not assume the model field means source-line traceability is delivered. An existing nullable field also does not justify guessing old receipts from their descriptions.

### G04 — Delivery reuse / incompatible totals in legacy subsystem report

`WarehouseService.php:441–501` selects subsystem PO lines without effective-state/head filtering. Each line credits all SITE movements from lots sharing its item_code. Two separate lines with that code can receive the same delivery credit. It also adds line quantities into one total without unit buckets. This is a static diagnostic with a specified negative fixture, **not yet a runtime reproduction**. Current caller found: `MaterialsController` material screen. No direct MC release bypass is asserted by this review.

### G05 — Receipt progress sums all PO quantities

`WarehouseService.php:569–585` sums every PO item's quantity and every PO receipt. `OrderGapService::amend` keeps the old item and inserts its successor. Therefore progress lacks the effective-head rule used by order gap and lacks per-unit/item isolation. Reproduce both an amended single-item order and a mixed-unit order before selecting a correction; do not merely tune the comparison tolerance.

### G06 — Usable is not a synonym for received or inspected

Inspection is intentionally advisory for receiving. A failed or missing inspection can coexist with a valid physical receipt and must remain visible. The policy that makes stock usable (including holds, verification, units, reservation/allocation and scope) still needs its approved definition. This plan creates no new inspection hold or release gate.

### G07 — Current split status (2026-10-20)

Human PO creation/pricing/financial-and-issue execution and inspection entry now have routes and controller paths (ADR-033–041). A current caller search under app/app and app/routes found no `->receive(` application call; human receipt is still open. Do not count G07 or the integrated pilot complete. The historical paragraph below describes the older baseline, not current PO/inspection absence. New priority/dependency review: [core gaps](CORE-GAP-REVIEW-2026-10-20-EN.md).

#### Historical G07 diagnosis

The route/controller audit exposes MR entry/advancement, forecasts, order attribution/amendment/allocation, issue/deliver/reverse and read registers. A source caller search found `createPurchaseOrder`, `inspect` and `receive` invoked by trusted `ProcurementSeeder`, not an application controller/Filament action. Their services and tests exist, but a human cannot complete those steps through a demonstrated normal entry surface. ADR-018 explicitly excludes PO/receive/returns/RFQ completion from its MR slice. A service harness may prove components, not pretend to be human workflow acceptance. Do not add the missing surfaces without settled authority, validation, source traceability and audit requirements.

## 5. Historical recommended sequence — current order in the 2026-10-20 core-gap review

1. Close the final-source software-test gate with machine-readable evidence; keep source unchanged during the run.
2. **First development slice recommended: G01 effective accepted-MTO selection**, because ADR-024 already records the accepted precedence/scope principles and gross demand underlies downstream decisions. Prepare the precise append-only selection/additivity design and regressions before a schema change; do not reopen closed business principles.
3. Use the PP-14–17 examples to reproduce G02–G05 in dedicated isolated tests. Reconcile ADR-032/ADR-015 before changing RECEIVED semantics or physical attribution/legacy treatment.
4. Add an integration harness connecting existing services with explicit provenance; document manual transitions and unavailable UI honestly. Do not fabricate missing links just to make a demo green.
5. Only then agree usable-stock and TQ/NCR disposition authorities and implement one approved slice at a time. Keep Fit-up/ITP and further commissioning work at their agreed boundary.

## 6. Definition of pilot evidence

For every executed case retain source SHA+working-tree digest, fixture IDs, actor/company/project, input and expected quantities/units, immutable source IDs/revisions/scope, actual state transitions, denial/rollback evidence and FA/EN screen or report where relevant. A task is not PASSED because a differently scoped component test is green. Currently this matrix is planned and source-reviewed; none of its integrated cases is claimed as executed here.
