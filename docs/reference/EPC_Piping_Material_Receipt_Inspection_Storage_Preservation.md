# Comprehensive Process for Receipt, Inspection, Store Handover, and Preservation of Piping Materials & Equipment in an EPC Project (with Automation Perspective)

The full cycle — from the moment shipment is announced until preservation in the warehouse and issue to construction — is presented below, broken down into **scenarios, steps, inspections, documents, reports, and automation requirements**.

---

## 1. Roles and Stakeholders

| Role | Key Responsibility |
|---|---|
| Procurement / Expediting | Issue PO, shipment follow-up, pre-alert |
| Logistics / Traffic | Transport, customs clearance, arrival coordination |
| Site Material Controller (SMC) | Document control, MRR registration, inventory management |
| Warehouse Supervisor | Unloading, storage layout, preservation, material issue |
| QC Inspector (Piping / Welding / Coating) | Receiving inspection, PMI, MTC verification |
| QA / Document Control | Certificate control, traceability, archiving |
| Third-Party Inspector (TPI) | Issue IRN / Release Note at source |
| HSE | Unloading safety, lifting, hazardous materials |
| Security / Gate | Vehicle entry log, waybill recording |
| Vendor / Supplier | NCR close-out, submission of missing documents |
| Client / Owner Rep | Inspection witness, Free-Issue approval |
| Construction / Piping Engineering | Material request (MIV), substitute approval |

---

## 2. Material Receipt Scenarios

### Scenario A — Direct Purchase by EPC Contractor (Vendor Supply)
Standard route: PO → factory inspection (FAT/TPI) → IRN → transport → arrival → receiving inspection → warehouse.

### Scenario B — Owner-Furnished Material (Free-Issue)
- Ownership belongs to the client; a two-party Handover Certificate is required.
- Any shortage/damage must be reported to the client representative immediately and recorded in a joint OS&D.
- Stock must be recorded in ERP with a separate flag (Non-owned stock).

### Scenario C — Imported Material
- Additional documents: Bill of Lading / AWB, Commercial Invoice, Certificate of Origin, Packing List, Customs Clearance, Insurance Certificate.
- Risks: customs delay, packaging opened at customs, marine transport damage (rust/salt-water).
- Inspection must be performed **before leaving customs** or immediately after arrival, with the insurance representative present (in case of damage).

### Scenario D — Local Purchase
Documents: invoice, waybill, MTC, certificate of conformity. Factory inspection is usually not performed, so receiving inspection is stricter.

### Scenario E — Prefabricated Spools from the Shop
- Documents: Spool Release Note, Weld Map, NDT Reports, PWHT, Hydrotest (if performed in the shop), Coating Report, Traceability Sheet.
- Inspection: spool number against isometric, dimensions, internal cleanliness, end caps on both ends, paint damage.

### Scenario F — Partial Shipment / Back-order
The PO arrives in several shipments. The system must retain the Open Quantity and Balance of each Line Item.

### Scenario G — Urgent / AOG Material
Fast-track route with priority inspection, but **without eliminating quality steps**.

### Scenario H — Inter-site Transfer (from another site/project)
Documents: Transfer Note, last MRR at source, copy of MTC. Risk: loss of traceability.

### Scenario I — Return to Store (from construction or a second-tier contractor)
Material Return Note (MRN); re-inspection of physical condition; determination of Reusable / Scrap.

### Scenario J — Non-conforming, Damaged, or Undocumented Material
- Entry into the **Quarantine Area** with a Hold Tag (red/yellow label).
- Issue OS&D and NCR.
- Release from quarantine only with a Disposition (Accept as-is / Repair / Reject / Replace).

### Scenario K — Spare Parts (Commissioning & 2-Year Spares) and Special Tools
Separate store, original packaging must not be opened, final handover to the client.

### Scenario L — Sensitive Items (instrumentation, control valves, graphite gaskets, elastomers, welding electrodes)
Require a covered/temperature- and humidity-controlled store; shelf-life management.

---

## 3. End-to-End Workflow

### Step 0 — Pre-Arrival
1. Receive the **Shipping Notice / Pre-alert** from the Vendor or Expediting (at least 48–72 hours in advance).
2. Receive the Packing List, IRN/Release Note, and shipping documents electronically.
3. Create an **Expected Receipt** in the system (ASN – Advanced Shipping Notice) linked to the PO Line Items.
4. Plan unloading: crane type, Lifting Plan for heavy items, storage location, manpower.
5. Notify QC so an inspector is present at unloading.

### Step 1 — Gate Entry
- Record plate number, driver, waybill, and arrival time.
- Vehicle safety check (HSE Vehicle Checklist).
- Issue a **Gate Pass / Vehicle Entry Log**; in an automated system: scan the waybill QR and match it against the ASN.
- Weighing at the weighbridge (where required, for bulk pipe).

### Step 2 — Unloading
- Preliminary packaging inspection before unloading (photographing the load condition on the trailer — mandatory for insurance claims).
- Unload in the **Receiving / Inspection Bay** (not directly into the storage area).
- Use nylon slings for stainless and painted pipe; chains and hooks are prohibited.
- Record the Unloading Report (time, equipment, manpower, incidents).

### Step 3 — Document Verification
Verify the following against the PO and Requisition:

| Document | Check Point |
|---|---|
| Packing List | Quantity, tag, weight, number of packages |
| Commercial Invoice / Delivery Note | PO number, Line Item |
| MTC (EN 10204 3.1 / 3.2) | Heat No, Grade, Chemical/Mechanical properties, Impact Test, Hardness, NACE (if required) |
| IRN / Release Note (TPI) | Number, date, scope, punch list |
| Certificate of Conformity (CoC) | Compliance with Spec/Datasheet |
| Test Reports | Valve hydrotest, NDT, PMI, Hardness, HIC/SSC |
| Coating / Painting Report | DFT, Holiday Test, paint system |
| Mill Marking / Traceability Map | Physical marking matching the MTC |
| Preservation Certificate / Instruction | Preservation method and validity period |
| MSDS | For chemical items (primer, grease, gasket compound) |
| Warranty / Guarantee | Start date |
| Vendor Drawings / Final Data Book | For equipment (control valve, strainer, expansion joint) |

Result: **Document Check Status** = Complete / Incomplete (Hold).

### Step 4 — Quantity Check
- Physical count by Item, Size, Rating, Material, Heat No.
- Comparison against the Packing List and PO.
- Record: Received / Over / Short / Damaged.

### Step 5 — Receiving Inspection (QC)
Based on the **Site Receiving ITP**. Minimum requirements by material type:

**Pipes**
- Marking (Spec, Grade, Size, Sch, Heat No, Mill) and match with MTC
- Wall thickness (UT) on a sampling basis, outside diameter, ovality, bevel (Bevel angle & Land)
- End caps (both ends), internal cleanliness, rust, pitting, dent, lamination
- PMI for alloys and stainless steels (100% or sampling per Spec)
- Ferrite check for duplex, hardness for sour service

**Fittings**
- Marking, dimensions (ASME B16.9 / B16.11), thickness, bevel, PMI
- Heat No match with MTC

**Flanges**
- Rating, facing (RF/RTJ/FF), surface finish (Ra), bore, thickness, face protector
- Face damage (highly critical)

**Valves**
- Tag No, body/trim material, rating, fire-safe cert, hydrotest report (API 598 / ISO 5208)
- Open/closed position (Gate/Globe: closed, Ball/Plug: open), caps, flange faces, manual operation, grease leakage
- Actuator and accessories per Datasheet
- Packaging for cryogenic / oxygen service valves (Cleanliness Cert)

**Gaskets**
- Type, size, rating, material, manufacturing date / shelf life
- Spiral wound: no deformation, stored horizontally

**Bolting**
- Grade (B7/L7/B8M…), head marking, coating (PTFE/HDG), MTC, quantity

**Special Items**: strainer, expansion joint, spectacle blind, steam trap, sight glass, hose, sample connection — per Datasheet and Vendor Drawing.

**Welding Consumables**
- Batch No, MTC, shelf life, intact packaging, compliance with WPS

Inspection result: **Accepted / Accepted with Comment / Rejected / Hold**

### Step 6 — Report Issuance
- **MRR (Material Receiving Report)**: the primary acceptance document; unique number, linked to PO/Packing List/MTC.
- **MIR (Material Inspection Report)**: QC results.
- **OS&D Report (Over, Short & Damage)**: in case of discrepancy.
- **NCR (Non-Conformance Report)**: for quality non-conformances.
- **Damage Report + Photos**: for insurance/vendor claims.
- **Hold Tag / Reject Tag**: physical and in the system.

### Step 7 — Goods Receipt into the Warehouse (GRN)
- Post the GRN in the ERP (SAP MM / Oracle / SmartPlant Materials / in-house system).
- Assign a **Location Code** (Yard-Row-Rack-Bin).
- Issue an identification tag (QR/RFID label): Item Code, Description, Heat No, PO, MRR, Location, Date.
- Update the **Bin Card** and **Stock Ledger**.
- Record in the **Heat Number / Traceability Register**.
- Close or keep open the PO Line Item.

### Step 8 — Notifications
- Notify Procurement for vendor invoicing (payment contingent on MRR Accepted).
- Notify Construction Planning (material available for MIV).
- Notify the Vendor for OS&D / NCR with a response deadline.

---

## 4. Storage & Preservation

### 4.1 Storage Location Classification

| Class | Conditions | Items |
|---|---|---|
| Open Yard (A) | Open air, on sleepers/racks with drainage slope, no ground contact | Carbon steel pipe, large fittings |
| Covered Yard (B) | Canopy, no walls | Flanges, painted spools, large valves |
| Closed Warehouse (C) | Enclosed, ventilated | Valves, small fittings, bolting, gaskets |
| Climate-Controlled (D) | Temperature/humidity control (RH < 50–60%) | Instrumentation, electrodes, elastomers, control valves, oxygen-clean items |
| Hazmat Store (E) | Per MSDS, ventilated, explosion-proof | Paint, solvents, adhesives, grease |
| Quarantine (Q) | Segregated, fenced, labelled | Hold/Reject material |
| Spares / Client Store (S) | Locked, restricted access | Spare parts and Free-Issue items |

### 4.2 Storage Layout Rules
- Segregate **Stainless / Alloy / Duplex** from carbon steel (prevent iron contamination; use wooden or plastic sleepers, never metal).
- Pipes: maximum stack height per size, wedges/stoppers on both sides, end caps always fitted, slope for water drainage.
- Flanges: face up or face-to-face with protectors, never directly on the ground.
- Valves: on pallets, in the correct stem orientation (vertical for gate/globe), flange protectors fitted, stem protected with grease.
- Gaskets: horizontal, away from sunlight and ozone, in original packaging.
- Bolting: original packaging, moisture-proof, segregated by grade.
- Electrodes: quiver/oven per the WPS instruction; temperature logging.
- Color coding per project specification (e.g. colour band for grade).
- FIFO / FEFO for shelf-life items.

### 4.3 Preservation Program
- **Preservation Procedure** per material type and storage class (typically based on Vendor Instruction + project Specification).
- Periodic inspection: monthly for sensitive items, quarterly for bulk, and after any severe weather event.
- Activities: visual inspection, renewal of VCI/desiccant, grease renewal, valve stem cycling (every 3 months), end cap check, hold-down check, RH/temperature measurement, shelf-life control, recording in the **Preservation Log**.
- Excessive storage duration (e.g. > 6 months for valves) → **Re-inspection** and, if required, **Re-preservation** and possibly Re-test.
- Monthly **Preservation Status Report** to QA and the client.

### 4.4 Inventory Control
- Periodic **Cycle Count** and **Physical Inventory** annually / at milestones.
- **Stock Reconciliation** (ERP vs Physical vs MTO).
- Surplus / Shortage analysis against MTO and isometrics.
- Weekly **Material Status Report**: Received / Inspected / Accepted / Hold / Issued / Balance.

### 4.5 Material Issue
- **MIV / MRV (Material Issue Voucher / Material Request Voucher)** referencing Isometric / Line No / Spool.
- **Heat No** allocation to the spool at issue (for traceability up to the Weld Map).
- Pre-issue check for items stored over a long period.
- **Material Return Note** for returns.
- Management of pipe **cut-lengths / offcuts** (Remnant Register).

---

## 5. Complete Document & Report Checklist

**Inputs**
PO + revisions / MR & Datasheet / Spec / Packing List / Shipping documents (BL, AWB, CMR, Truck Waybill) / Invoice / Customs Clearance / Insurance Cert / IRN & Inspection Reports / MTC / CoC / Test Reports / Coating Reports / Vendor Preservation Instruction / MSDS / Vendor Drawings.

**Site-generated (Outputs)**
Gate Entry Log / Unloading Report / Receiving Checklist / Document Verification Checklist / MRR / MIR / PMI Report / Dimensional Report / OS&D Report / Damage Report + Photo Log / NCR / Hold-Reject Tag / GRN / Material Tag Label / Bin Card / Heat No Traceability Register / Storage Location Register / Preservation Log / Periodic Inspection Report / Cycle Count Sheet / MIV-MRV / MRN / Remnant Register / Material Status Report / Vendor Claim Letter / Insurance Claim File / Quarantine Register / Disposition Record / Shelf-life Register / Handover Certificate (Free-Issue, Spares).

---

## 6. Automation Design

### 6.1 State Machine per Line Item / Package

```
Expected (ASN) → Arrived at Gate → Unloaded → Docs Under Review
   ├─ Docs Incomplete → HOLD (Doc)
   └─ Docs OK → Under QC Inspection
         ├─ Accepted → GRN Posted → In Stock (Location Assigned)
         ├─ Accepted w/ Comment → In Stock (Flag)
         ├─ Hold → Quarantine → Disposition → (Accept / Repair → Re-inspect / Reject → Return to Vendor / Scrap)
         └─ Short/Over/Damaged → OS&D Open → Vendor/Insurance Claim → Closed
In Stock → Reserved (MRV) → Issued (MIV) → Installed
In Stock → Returned (MRN) → Re-inspect → In Stock / Scrap
In Stock → Surplus → Transfer / Handover to Client
```

### 6.2 Core Entities
- **PO / PO Line Item** (Item Code, Qty, Unit, Spec, Delivery Date)
- **Shipment / ASN** (Vendor, Transport, ETA, Packages)
- **Package** (Crate No, Weight, Dimensions, Content)
- **Material Item** (Item Code, Description, Size, Rating, Material Grade, Heat No, Tag No, Batch No, Shelf-life, Preservation Class)
- **MRR** (No, Date, Inspector, Status, Links)
- **Inspection Record** (ITP Step, Result, Attachments, PMI values)
- **OS&D / NCR** (Type, Description, Root Cause, Disposition, Due Date, Responsible)
- **Certificate** (Type, No, Heat/Batch, File)
- **Location** (Warehouse, Zone, Row, Rack, Bin, Class)
- **Stock Transaction** (Receipt, Issue, Return, Transfer, Adjustment)
- **Preservation Task** (Frequency, Last Done, Next Due, Checklist)
- **MIV / MRV / MRN** (Isometric, Line, Spool, Qty, Heat No allocated)

### 6.3 Digital Forms and Capabilities
- Mobile/tablet forms with QR/Barcode/RFID scanning at the gate, unloading, inspection, and storage layout.
- Mandatory photo capture with Geo/Time stamp during unloading and damage events.
- OCR to extract Heat No and MTC values and automatically match them with physical marking.
- Dynamic checklist based on material type (Pipe / Valve / Flange…).
- Multi-step approval workflow (SMC → QC → QA → Warehouse) with electronic signature.
- Automated rules: no GRN without an MRR Accepted; no MIV for Hold material; shelf-life alerts; preservation due-date alerts; grade-mixing alert at a single location.
- Full traceability: Heat No ↔ MTC ↔ Spool ↔ Weld ↔ Isometric ↔ Test Package.
- Vendor Portal for document upload and response to NCR/OS&D.
- Integration: ERP (SAP MM), material management system (SmartPlant Materials / Aveva ERM), Document Control (Aconex/EDMS), Welding/QC DB, Planning (Primavera).

### 6.4 Dashboards and KPIs
- Material Received vs Planned (%), Average Receiving-to-GRN Lead Time
- Number and value of Hold / Quarantine items and average close-out time
- OS&D and NCR rate by vendor (Vendor Performance)
- Preservation Compliance (% of tasks completed on time)
- Inventory Accuracy (Cycle Count)
- Surplus / Shortage vs MTO
- Shelf-life Expiry Forecast
- Document Completeness Index (% of MRRs with complete documentation)

### 6.5 Approval Matrix (Sample)

| Document | Prepared by | Reviewed by | Approved by | Copied to |
|---|---|---|---|---|
| MRR | SMC | QC | QA / Warehouse Mgr | Procurement, Planning |
| MIR / PMI | QC Inspector | QC Lead | QA Mgr | Client Rep (at H/W points) |
| OS&D | SMC | Logistics | Procurement Mgr | Vendor, Insurance, Client |
| NCR | QC | QA | Project QA Mgr + Engineering | Vendor, Client |
| Preservation Log | Warehouse | QC | QA | Client Rep |
| MIV | Construction | SMC | Warehouse Sup. | QC (heat allocation) |

---

## 7. Common Risks and Automation Controls

| Risk | Proposed System Control |
|---|---|
| Material arrival without IRN | Lock Gate Entry until the IRN is uploaded or a waiver is approved |
| Heat No mixing | Mandatory label scan during storage layout and issue |
| Missed preservation | Automatic scheduling and escalation |
| Vendor payment before acceptance | Link invoicing to MRR Accepted |
| Lost MTC documents | Electronic archive linked to Heat No |
| End-of-project surplus | Continuous Stock vs MTO reporting and purchase control |
| Flange/valve face damage in store | Periodic visual checklist |
| Expired elastomers/electrodes | FEFO with 30/60/90-day alerts |

---

If you wish, I can convert this structure into one of the following outputs: a **process BPMN**, **templates for MRR/MIR/OS&D/Preservation Log forms** (Excel), a **data model (ERD)**, or **use cases / user stories** for the software development team.
