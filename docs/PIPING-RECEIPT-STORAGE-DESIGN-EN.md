> **Current 2026-10-23 — custody-amendment increment:** original-preparer/live-custody-grant source/Heat amendments are implemented; exact amended-basis fresh QC and QA are required. [Current guide](PIPING-CUSTODY-AMENDMENTS-EN.md). Earlier correction-as-proposal statements are historical. Controlled GRN/stock/legacy cutover are still unimplemented.
> **وضعیت جاری — اصلاحیهٔ تحویل‌گیری:** اصلاح منبع/Heat فقط توسط تهیه‌کنندهٔ اصلی با معرفی جاری اجرا شده است؛ QC و QA تازه روی همان نسخه لازم است. [راهنمای جاری](PIPING-CUSTODY-AMENDMENTS-EN.md). پیشنهاد قدیمی اصلاحیه اکنون اجرایی است؛ GRN/موجودی و گذار انبار قدیمی هنوز اجرا نشده‌اند.

> **وضعیت جاری ۲۰۲۶-۱۰-۲۳:** تصمیم نهایی QA با شواهد نسخه‌دار انسانی و منع پذیرش شرط باز اجرا شده است؛ [راهنمای جاری](PIPING-QA-EN.md). گزاره‌های قبلی دربارهٔ QA اجرا‌نشده/سیاست باز، تاریخی‌اند. GRN و گذار مسیر انبار قدیمی هنوز اجرا نشده‌اند.
> **Current 2026-10-23:** version-bound manual QA and open-condition blocking are implemented; [current guide](PIPING-QA-EN.md). Earlier unimplemented/pending QA statements are historical. Controlled GRN and legacy warehouse cutover remain unimplemented.

> **Receiving QC delivered — 2026-10-22:** [guide](PIPING-RECEIVING-QC-EN.md) / [فارسی](PIPING-RECEIVING-QC-FA.md). Immutable parent-bound receiving observations, independent current QC nomination/original source scope; actual MRR preparer cannot self-review.50/578 PASS (16 QC +20 custody +14 PO inspection). Only MRR≠QC executable: no QA/GRN/stock release or automatic technical checklist/accepted-with-comment policy. Earlier no-QC-action statements are historical for this increment. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

> **Physical custody / initial MRR delivered — 2026-10-22:** [guide](PIPING-PHYSICAL-CUSTODY-EN.md) / [فارسی](PIPING-PHYSICAL-CUSTODY-FA.md). Current nominees + independent source reading, and explicitly nominated recorder from any eligible participant accepted/implemented. Final20/242; related milestone60/438 and config text2/43 PASS, overlapping not additive. CUSTODY_ONLY, no QC/QA/GRN/stock/source-read grant. Earlier no-custody/no-resolver statements are historical for this increment. Actual three-person separation remains required for later actions. Not full-suite/current-CI/browser/production/committed/pushed; structured prerequisites deferred.

> **Receipt nomination configuration delivered — 2026-10-22:** [guide](PIPING-RECEIPT-ACCESS-EN.md) / [فارسی](PIPING-RECEIPT-ACCESS-FA.md). Owner-admin-only screen, explicit custodian/four independent sets, version/suspension/history and FORCE RLS implemented.28/278 PASS; subsequent view2/43 overlaps. No custody/MRR/QC/acceptance/GRN action/resolver implemented, no default grants/source-read expansion. Earlier no-screen/no-schema prose is historical only for this increment. Not full-suite/current-CI/interactive-browser/deployed/committed/pushed; structured prerequisite items deferred.

> **Latest decision:** the core matrix is now accepted: owner-company project.configure administration; independent MRR/QC/QA/GRN nominations; three different MRR/QC/QA people; GRN may overlap with independent warehouse authority. [Authoritative current contract](PIPING-RECEIPT-FIRST-SLICE-CONTRACT-EN.md). Original sample questions below are historical for these core points; advanced actions remain proposed. Current runtime covers configuration/physical custody only; QC/QA/GRN remain planned.

# Piping material receipt through storage and preservation — implementation design

2026-10-22 · **REQUIREMENTS RECONCILED / TWO POLICY CHOICES ACCEPTED / CORE MATRIX ACCEPTED / ADVANCED ACTIONS PROPOSED / CONFIGURATION, CUSTODY AND RECEIVING OBSERVATIONS IMPLEMENTED; QA-GRN PLANNED**

[فارسی](PIPING-RECEIPT-STORAGE-DESIGN-FA.md) · [ADR-046](ADR-046-PIPING-RECEIPT-STORAGE-EN.md) · [Original supplied reference](reference/EPC_Piping_Material_Receipt_Inspection_Storage_Preservation.md) · [Evidence](evidence/2026-10-22-piping-receipt-design/README.md)

## 1. Approved basis and limits

User supplied the receipt/inspection/store-handover/preservation reference and selected:

1. `piping_receipt_acceptance_boundary=physical_custody_then_controlled_acceptance`: record actual arrival and physical custody even with incomplete documents or failed/missing inspection; retain in receiving/quarantine. Accepted GRN follows authorized acceptance. HOLD/REJECTED material is not available for ordinary consumption issue. Exception authority must be separately designed.
2. `piping_receipt_authority_design=design_matrix_before_grants`: prepare and approve the responsibility matrix **before activating any new authority**. The reference's sample matrix is not an approved project organization or grant.

These choices do not approve the proposed entities, migration, named actors, separation-of-person rules, disposition/waiver, tolerance, financial effects, legacy cutover or full SRS. No runtime code changed. Structured within-stage prerequisite items remain explicitly deferred; this is material custody/acceptance, not a prerequisite-item project.

## 2. Workflow: do not compress independent facts into one state

Proposed tracking axes, to be validated in implementation design:

- **Logistics/custody:** EXPECTED → ARRIVED → UNLOADED → RECEIVING_CUSTODY. Arrival may be recorded without a prior ASN; discrepancy remains visible. No strict IRN gate-entry lock was selected.
- **Documents:** NOT_REVIEWED / INCOMPLETE / VERIFIED, with exact checklist revision, reviewer, time and retained evidence. Upload existence alone does not mean MTC/content verification.
- **QC:** NOT_INSPECTED / HOLD / ACCEPTED / ACCEPTED_WITH_COMMENT / REJECTED. Do not auto-map existing PASS/FAIL/CONDITIONAL PO observations to receiving acceptance. Receiving inspection is lot/receipt-line-specific; legacy observations remain immutable and distinct.
- **Acceptance:** PENDING / ACCEPTED / HELD / REJECTED, with authorized decision and reasons. The accepted gate is policy; who signs it and its required evidence are pending matrix approval. Accepted-with-comment eligibility needs explicit classification of blocking versus nonblocking comments; it is not automatic release.
- **Warehouse:** acceptance → accepted GRN → assigned qualified location → stored; receiving/quarantine locations are also recorded before GRN. Physical quantity held on site is visible independently of accepted stock. Storage is not proof of suitability for engineering use.
- **Preservation:** procedure revision, due tasks, completion evidence, observations and expiry; overdue tasks flag review. Automatic hold/release for overdue/expired items is a separate pending decision.

Record custody time, user-reported delivery/inspection dates and server action times separately. Full approval history remains append-only; mistakes create reasoned corrections/reversals, never deletion of earlier events. Repeated HTTP submissions require an idempotency/duplicate-detection contract before posting quantities.

## 3. Reference responsibility matrix — core decisions superseded by accepted contract

[Accepted core matrix and first-slice contract](PIPING-RECEIPT-FIRST-SLICE-CONTRACT-EN.md) is authoritative for configurator, MRR/QC/QA/GRN and person separation. The broader original table is retained for traceability; its advanced-action questions are still open.

These are **functional responsibilities**, not new company types, actual usernames, inherited roles or grants. “Custodian” is an explicitly designated participating company, not automatically the PO buyer, EPC, lot owner or project owner. Ownership and custody must be separate for Free-Issue. Every user still needs current membership/company/project/discipline/module/pack and the applicable approved action grant. Actor company/user/time are server-owned.

| Action / evidence | Proposed preparer | Proposed reviewer / decision holder | Scope and separation question still open |
|---|---|---|---|
| ASN/package registration | Procurement/expediting or logistics | SMC checks PO-line/package mapping | Each company's source reading must already be permitted; no buyer impersonation. Who may prepare/review in this project? |
| Gate/unloading factual record | Gate/logistics or SMC; warehouse unload record | HSE records safety findings; warehouse confirms physical custody | Gate, HSE and SMC are functions, not broad authorization. Mandatory dual-person custody confirmation? |
| Quantity count / receipt custody | SMC/material controller | Warehouse verifies count/custody | Exact PO line + shipment + heat/batch split. Must recorder and verifier differ? |
| Document completeness/MTC verification | SMC/QA document-control function | QA and/or qualified QC verifies applicable content | File/CDE access stays independent; who can attest completeness versus technical compliance? |
| MIR/receiving inspection | Qualified receiving QC | QC lead/QA review; client/TPI witness only where project ITP requires | Existing ADR-041 PO observation nomination/type rules are NOT replaced; receiving-QC authority needs an explicit policy. No default witness/approval from company type. |
| MRR acceptance decision | SMC prepares linked custody/report | Proposed QC recommendation + QA acceptance; warehouse acknowledges handover | Who has final acceptance authority? Mandatory distinct preparer/reviewer/approver? Required document and QC statuses? |
| Accepted GRN posting | Warehouse/material controller | Approved MRR/acceptance prerequisite checked by server | Can acceptance approver also post? Physical fact entry never waits for GRN approval. No payment approval implied. |
| OS&D | SMC/warehouse | Logistics/procurement handles claim; QA/QC handles quality evidence | Case owner/deadline and closure authority; closing a claim does not release HOLD. |
| NCR/disposition/quarantine release | QC/QA raises case | Proposed authorized engineering/QA disposition; client only per contract | Exact repair/reinspect/reject/return/scrap/accept-as-is authorities and signatures must be approved. No general waiver or automatic release. |
| Putaway/location change | Warehouse supervisor/operator | Storage-class/segregation checks; QC review for exceptions | Same project/custodian and original-visible lots. Transfer of custody/ownership is a separate event. |
| Preservation plan and completion | Warehouse prepares/performs | QC reviews applicable checklist; QA reviews exceptions | Approver, task completion/review separation, overdue escalation/hold policy remain pending. |
| Ordinary issue/return | Construction requests; SMC prepares; warehouse executes | Current approved issue authority plus server HOLD/REJECT gate | Material request is not release. Return reinspection/reusable/scrap authority and legacy behavior unresolved. |

No automatic grant to project.configure, OWNER, SMC labels, PO nominees or inspectors is created. The user subsequently selected owner-company current project.configure administration, QC→QA→warehouse and three-person MRR/QC/QA separation; those decisions are CLOSED. See the accepted first-slice contract; questions in the original reference matrix below are historical for those core actions. Proposed receipt visibility: original-authorized source readers plus explicitly authorized custody/workflow readers only after approval; **not** all project participants. QA access to certificates, vendor portal and cross-company evidence require their own bounded policies.

## 4. Quantity and identity contract to design before migration

Each proposed receipt detail pins project, PO header **and exact PO-line ID/version/lineage**, shipment/package, material identity, source unit, heat/batch/serial and actual quantity. One PO line can split into multiple heats/lots; multiple shipments cannot reuse the same posting identity. Do not link by code/description alone or fabricate a PO for Free-Issue/transfer/return.

Keep ordered, expected for shipment, physically observed, accepted, held, rejected, stored, issued and returned quantities separate. Over/short compare like-for-like against the declared expected shipment quantity and applicable source line; back-order is not the same as a short shipment. Damaged quantity is an observation that may overlap held/rejected quantities—not an extra additive bucket. After classification, accepted + held + rejected partitions the same received quantity in one unit at the specified point in time. Quantities across M/EA/etc are never summed. Unit conversion requires an explicit retained rule; no implicit conversion or float rounding contract.

Physical material already present is still recorded if over-delivered, damaged, missing source linkage or delivered against a subsequently cancelled PO; accepted posting/exception processing is separate and must not legitimize invalid PO execution. An unplanned custody record is not a fabricated purchase/stock commitment. Amendment timing, pending receipt quantities, concurrent postings, over-delivery tolerance and back-order closure require explicit policy and fresh locked source revalidation. Current `WarehouseService::receive` is not yet this contract.

Unknown legacy lot-line provenance stays UNKNOWN; no backfill from item_code and no automatic grandfathered quality release. New-flow enrollment, legacy ordinary-issue behavior and migration cutover must be approved before enforcing gates on existing paths. Historical records and source-capacity/PO financial/issue ledgers remain unchanged.

## 5. Reference-to-backlog traceability

P0 here means required design/decision prerequisite, not a new production emergency. All implementation rows below are **planned, not implemented**.

| ID | Supplied reference | Product requirement / proposed increment |
|---|---|---|
| REC-01 | §3 step0 | ASN, ETA, PO-line/package contents, unloading preparation and QC notification; no mandatory global 48–72h rule. |
| REC-02 | §3 steps1–2 | Arrival/gate/waybill, unloading into receiving bay, actual time/count, safety/photo evidence and receiving/quarantine location. Physical registration not blocked by absent IRN. |
| REC-03 | §3 step3 | Versioned material/scenario checklist for packing list, invoice, MTC, IRN, CoC, tests, coating, marking, preservation, MSDS, warranty and vendor dossier. Conditional applicability and verification evidence—not a blanket all-documents-required flag. |
| REC-04 | §3 step4; scenarioF | Exact line/heat/unit counts; partial shipments, expected/open quantities, over/short/damage observations, no double credit or premature closure. |
| REC-05 | §3 step5 | Configured receiving ITP/checklist by pipe/fitting/flange/valve/gasket/bolting/special item/welding-consumable; actual measurements, units, evidence and QC result. No unapproved automatic technical pass/fail thresholds. |
| REC-06 | §3 step6 | MRR/MIR, OS&D/NCR/damage-photo case references, Hold/Reject tag and append-only decisions. Unique numbers need safe atomic allocation; do not reuse the known hidden-MAX defect. No independent duplicate NCR engine approved. |
| REC-07 | §3 step7; §6.3 | Accepted GRN only after authorized acceptance, qualified putaway, tag, bin/stock ledger and heat traceability. GRN does not mean technical permission for installation or automatic vendor payment. |
| REC-08 | §3 step8 | Internal follow-up tasks for procurement/planning/vendor case response. External notifications/outbox/ERP/payment integration separately scoped; no auto-payment or automatic construction reservation. |
| STO-01 | §4.1–4.2 | Locations A open/B covered/C enclosed/D climate/E hazmat/Q quarantine/S spares-client; compatible capacity, segregation, no-ground-contact and protection checklist. Applicability set by project/vendor requirements. |
| PRE-01 | §4.3 | Versioned preservation plans, task schedule, weather-triggered inspection, inspection/re-preservation/retest requests, evidence and status reporting. No universal schedule or automatic release. |
| INV-01 | §4.4; §6.4 | Unit-bucketed physical/accepted/held/issued balances, cycle count, expiry and preservation reports. Adjustment authority/count approval separate; MTO reconciliation remains subject to G01, UNKNOWN not zero. |
| ISS-01 | §4.5 | Ordinary consumption issue prohibited for HOLD/REJECTED; future MIV/MRV, heat allocation, MRN/reinspection and remnants as later bounded slices. Does not reopen structured prerequisite-item design. |
| AUT-01 | §6.1–6.3 | Independent state axes, versioned forms/evidence/history, safe transitions, idempotency, optimistic versions and atomic postings; mobile/QR first if later approved. OCR/RFID/geolocation/e-signature/vendor portal/integrations are separate capabilities, not existing delivery claims. |
| RPT-01 | §5, §6.4–6.5, §7 | Exact as-of report scope, status history, reports/labels and escalation. KPI numerator/denominator, currency, units and visibility specified before aggregation. Sample approval matrix and risk controls are candidates, not blanket authorization. |

### Twelve scenario paths retained

| Reference scenario | Planned path / important boundary |
|---|---|
| A EPC purchase | Recommended first operational slice: issued, originally visible PO line; physical custody → checks → authorized acceptance → GRN/putaway. |
| B Free-Issue | Separate ownership/custodian and two-party handover; no dummy PO or automatic Owner approval. |
| C Imported | Customs/transport/insurance evidence and damage timing; local custody record without blanket insurer access. |
| D Local purchase | Applicable invoice/waybill/MTC/CoC; inspection plan project-specific, not universally “stricter”. |
| E Shop spool | Spool/isometric/release/map/NDT/PWHT/coating evidence; no claim that spool/weld model already exists. |
| F Partial/back-order | Same exact line, multiple shipments/heats; separate shipment discrepancy and remaining order quantity. Include in first slice. |
| G Urgent/AOG | Priority scheduling, no silent removal of checks or unauthorized waiver. |
| H Inter-site/project transfer | Approved source/target custody, traceability and transfer transaction; no cross-project role/read inference. |
| I Return | MRN/source issue reference, condition reinspection; reusable/scrap decision separate. |
| J Nonconforming/undocumented/damaged | Physical custody retained; HOLD/REJECT quarantine, case/disposition/reinspection; no ordinary issue. |
| K Spares/tools | Separate intended use/ownership/packaging/hand-over obligations. |
| L Sensitive items | Configured environment, expiry, shelf-life and preservation requirements. |

## 6. Implementation sequence and focused acceptance plan

1. **Core matrix approval completed. Next: owner-only nomination configuration foundation.** Configure actual custodian/action people under the accepted administrator and separation model; do not re-ask the closed choices. No runtime grant is delivered by this design document.
2. **Design/focused diagnostics:** exact-line identities, mixed units, repeated codes, amendment/completion/return arithmetic; atomic numbering and receipt idempotency. Distinguish report ordered coverage from receipt eligibility (ADR-032 R1 remains pending).
3. **First usable slice proposed:** scenario A + F + J: physical arrival/count/custody, applicable document/QC recording, controlled acceptance/hold, accepted GRN, qualified receiving/quarantine/storage location, history and FA/EN read/entry paths. Permission policy must be approved first; no direct reuse of legacy receive as human API.
4. **Storage/preservation:** locations/segregation and versioned plans/tasks/expiry, review evidence and alerts; then approved reinspection/exception handling.
5. **Later scenarios/reports:** Free-Issue/import/spool/transfer/returns/spares, official outputs, issue/heat/remnant extensions and approved integrations. Every scenario remains tracked; none is silently declared delivered by a generic PO form.

Focused cases planned: missing MTC/IRN and failed inspection still register physical custody; Hold/Rejected ordinary-issue denial; no GRN before approved acceptance; two same-code lines never share receipt credit; mixed units; two partial shipments/no duplicate posting; one line/multiple heats; acceptance cannot release an unreviewed blocking comment; concurrent over-posting/stale authority/cancellation; quarantine location changes do not release quality state; project/company/source visibility and archive denial; immutable evidence/rollback; legacy unknown attribution; preservation procedure revision/expiry; bilingual form recovery/PDF evidence. These tests have **not** run for the proposed flow. Full regression only at the relevant cross-module/release milestone.

## 7. Conflicts and open decisions

- **Resolved policy:** prior advisory physical receipt remains; the new approved acceptance/ordinary-issue gate is separate. ADR-032 R4's blanket “no new hard gate authorized” is superseded only for this new user-selected boundary, not all usable-stock/waiver rules. ADR-041 PO observations still do not themselves block receipt or certify stock.
- **Not selected:** reference §7's strict no-IRN gate-entry lock. Record absent evidence and quarantine/hold without refusing to record actual arrival.
- **Still pending:** advanced role actions, actual configured nominees, accepted-with-comment rules, waivers/disposition, legacy enforcement, receipt quantity closure/tolerance/return semantics, completed-PO coverage, detailed technical checklists and numbering design.
- **Engineering controls:** 48–72h pre-alert, PMI sampling, valve shipping/storage position, humidity, cycling/reinspection periods, packing instructions and photo requirements must reference the approved project/vendor/HSE procedure and material/service conditions. Do not hard-code these illustrative values or use software as a substitute for engineering/HSE review. Digital stamps are recorded metadata, not proof of physical truth or legal signature.
- **Payments/readiness:** reference payment and planning notifications are not approval to alter the accepted financial/PO process, authorize installation or define globally usable stock. MTO admission and structured prerequisite deferrals remain.

## 8. Source review and delivery status

Current `WarehouseService::receive` is PO-header/free-text based and omits lot PO-item FK; `MaterialLot` contains a nullable FK, but its presence is not an implemented exact-line receipt workflow. Existing issue/deliver/reverse routes do not establish the new acceptance/hold contract. Source views and former runtime evidence are not new tests. Original supplied file retained byte-for-byte with SHA256. No application change, migration, grant, runtime/database/browser test, commit, push or deployment in this design delivery.
