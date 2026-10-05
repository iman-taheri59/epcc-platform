# ROADMAP TO MVP — EPCC Platform

Last updated: **2026-10-05**

---

## 1. MVP Goal

The MVP must not mean “all EPCC modules”. The MVP should be an installable and demoable Iran-first product that reliably keeps three promises:

1. **Cross-company confidentiality control** through PostgreSQL RLS and the six-gate authorization pipeline.
2. **A dependable contractual/engineering workflow**: documents, reviews, transmittals, punch, MCC, basic procurement/materials, and basic piping.
3. **Deliverability in on-premise / air-gapped Iranian environments**: Persian/RTL/Jalali, no CDN, backup/restore, and the key official PDF outputs.

Anything that does not strengthen these three promises is second priority for the MVP, even if it is required for the final product.

---

## 2. Very Short Current Status

### Built and useful as vertical slices

- Auth/session login
- ARC §5 six-gate authorization
- PostgreSQL RLS and tenant context
- Portfolio / project setup / team / archive-reopen
- CDE / document register / revisions / file attachments
- Review comments and publication gate
- Contractor transmittals and vendor transmittals
- Punch list and Mechanical Completion certificate
- PDF infrastructure and several PDF outputs
- Limited RLS-aware Filament admin panel
- Basic procurement/materials: requisition → PO → inspection → warehouse → site
- Facility / area / subsystem physical breakdown
- Piping line register, numbering, AFC gate, evidence attachment
- Unit preferences
- Components / ports / joints / compatibility / dia-inch
- Standards packs and reference packs

### MVP gaps

- The current sandbox does not provide a full runtime; PHP/Composer/PostgreSQL must be available.
- TODO/coverage should be remapped to FRs after procurement and piping additions.
- Several contractual/operational PDFs are missing.
- RFSU/PAC/FAC are not yet in the flow.
- PMS/piping class rules are still only an ADR/design.
- Engineering import has not started and needs real sample files.
- HSE and Schedule still have no pages.

---

## 3. Execution Principles

### 3.1 Non-negotiable rules

1. **No tenant-scoped feature should be merged without full PostgreSQL/RLS tests.**
2. **No page should be added without the shell contract.**
3. **No CDN, remote font, remote avatar, or external network dependency should be added.**
4. **Contractual outputs must be snapshotted, not silently rewritten from live data later.**
5. **Navigation `planned` and `locked` states must not be conflated.**
6. **Every new feature must be usable in Persian/RTL and English/LTR.**
7. **Every new migration must explicitly consider RLS, archive read-only behaviour, and project/company scope.**

### 3.2 Definition of Done for a feature

A feature counts as built only when:

- Migration/model/service/controller/view are complete where needed;
- The real HTTP path is tested, not only a direct service call;
- Positive and negative RLS cases are tested: “sees its own rows” and “does not see others’ rows”;
- Persian/English and RTL/LTR are covered at the view level;
- Register/table screens are checked for filter/sort/tabletools behaviour;
- PDF/file features check magic bytes, access control, and air-gap constraints;
- `HANDOFF.md` and `docs/TODO.md` are updated.

---

## 4. Phase 0 — Reliable Runtime Environment

**Goal:** Make full project execution possible before heavy DB/RLS changes.

### Work items

1. Choose one path:
   - An official Docker/devcontainer for Laravel + PostgreSQL + Composer + Node + Chromium; or
   - A precise local Linux/WSL installation guide.
2. Define a smoke-test command:
   ```bash
   cd app
   php artisan migrate:fresh --seed
   php artisan test
   node tests/Browser/sidebar-toggle.test.cjs
   node tests/Browser/table-tools.test.cjs
   python3 ../standards-packs/validate_packs.py
   ```
3. If Docker is chosen, local CI/GitHub workflows should use the same image to avoid “works on my machine”.

### Acceptable output

- One documented command for full rebuild.
- Full PHPUnit execution against real PostgreSQL.
- PDF tests run without hidden skips.

### Risk

High initial setup cost, but without it every later migration is risky.

---

## 5. Phase 1 — Documentation and Backlog Reconciliation

**Status:** Started by this documentation branch.

### Work items

1. Reconcile `docs/TODO.md` with `HANDOFF.md` and the current code.
2. Correct stale Procurement and Piping status.
3. Stabilise the MVP roadmap in this document.
4. Later, update `docs/Prototype-Coverage-Map.md` by mapping FRs to code.

### Acceptable output

- TODO no longer claims Procurement has zero pages.
- ADR-006 phases 1/1b/2/3 are recognised as built.
- Real gaps are visible.

---

## 6. Phase 2 — Low-risk, High-value PDFs

**Goal:** Complete official outputs that matter immediately in demos and customer handover.

### Items

1. **Payment certificate PDF**
   - Based on `PaymentCertificate`
   - Persian/English
   - Jalali, Persian digits, IRR formatting
   - Snapshot if contractual data can change

2. **Punch register PDF**
   - Printable register snapshot or selected filters
   - A/B/C category, state, responsible company, subsystem, dates

3. **GRN/MIV or warehouse document PDF/print outputs**
   - Warehouse receipt, warehouse issue, site delivery
   - Heat/lot number and MTC link

### Required tests

- `%PDF-` magic byte
- Access control for project participant and non-participant
- Persian/RTL smoke test
- No network/CDN dependency

---

## 7. Phase 3 — Complete the Commissioning Certificate Chain

**Goal:** Build the path after MCC: RFSU/PAC/FAC.

### Items

1. **RFSU certificate**
   - Use the existing `blocksReadyForStartUp()` logic
   - Gate based on A+B items
   - Snapshot punch/material/subsystem readiness

2. **PAC**
   - Provisional acceptance
   - Exception/condition list
   - Owner/PMC/Contractor signatures according to roles

3. **FAC**
   - Final acceptance
   - Closeout conditions
   - No rewriting of earlier evidence

### Design note

MCC/RFSU/PAC/FAC should not be treated as a simple enum if each has different evidence, gates, and signatures. Clarify domain services and snapshot contracts first.

---

## 8. Phase 4 — PMS and Piping Rules

**Goal:** Move from “modelling components” to “engineering validation”.

### Items

1. `piping_class_rules`
   - Half-open DN ranges `[from, to)`
   - `EXCLUDE USING gist`
   - `btree_gist` in the migration
   - Publish gate for gap detection

2. Branch table
   - Overlap is allowed as general rule + exception
   - Exact duplicate is forbidden
   - Resolver uses “specificity wins”

3. Validation integration
   - ASME/reference existence = INVALID
   - PMS/spec mismatch = DEVIATION/WARNING
   - Line/component attach UI must show the distinction

### Required tests

- Overlapping schedule bands rejected
- Adjacent half-open bands accepted
- Gap detection
- Branch table specific rule wins
- Positive and negative RLS
- Migration fresh/rollback

---

## 9. Phase 5 — Engineering Import Phase 1

**Goal:** Ingest data before construction/installation from Excel/PCF/property exports.

### Real prerequisite

At least one real sample of each:

- Line list Excel
- MTO Excel
- Real PCF from Plant 3D/CADWorx/AVEVA
- Navisworks property export, not NWD/NWC/NWF

### Items

1. `import_batches`
   - Immutable
   - `source_kind`
   - `uploaded_by`
   - checksum
   - status: uploaded/previewed/committed/rejected

2. Staging tables
   - Raw row JSON
   - Normalised fields
   - Row-level validation messages

3. Preview → commit flow
   - No truncate-and-reload
   - Commit must have idempotency/checksum behaviour

4. Import profiles
   - Customer column mapping
   - Configuration over code

### Forbidden promise

Do not promise direct upload and parsing of NWD/NWF/NWC. The correct path is property export or PCF.

---

## 10. Phase 6 — Equipment / Nozzles

**Goal:** Bring equipment into the same completion and piping compatibility model.

### Accepted design from ADR-010

- A nozzle owns a port.
- The joints table does not need a structural change.
- A port owner can be COMPONENT or NOZZLE.
- A mismatch such as a 6 inch 300# RF nozzle connected to a 150# flange must be a hard INVALID at entry time.

### Items

1. Equipment register
2. Nozzles table
3. Nozzle ports
4. Compatibility with component ports
5. Equipment connected to subsystem
6. UI for selecting a nozzle as an endpoint/open port

---

## 11. Phase 7 — Minimal HSE

**Goal:** Deliver the minimum HSE surface that is credible for an Iranian oil and gas demo.

### MVP HSE

1. PTW / Permit to Work
2. Incident report
3. HSE observation
4. Toolbox talk attendance

### Notes

- HSE is cross-company, so RLS and confidentiality are sensitive.
- Some incidents need controlled visibility; not all contractors should see every incident.

---

## 12. Post-MVP Backlog

- Schedule: P6/MSP sync, lookahead, baseline, delay analysis
- Full NCR/CAPA and ITP/ITR
- Welding/NDT and calibration
- Contract register, VO/change order, claims
- Full-text/OCR
- Offline field app
- BIM/CAD viewer
- LDAP/ERP/P6 integrations
- Notification engine
- Workflow/BPM
- Iranian taxpayer system / Shaparak
- Billing/metering/customer onboarding

---

## 13. Pilot Readiness Indicators

Before a real pilot, at least these must be true:

1. Full install/restore in an environment separate from development.
2. Full test suite execution against real PostgreSQL.
3. At least one Iranian demo seed/project covering:
   - document lifecycle
   - transmittal
   - punch/MCC
   - procurement/material to site
   - piping line/component/joint
4. Main official PDFs.
5. Green air-gap verification.
6. Project backup/export and restore.
7. Persian admin guide.
8. An honest limitations list for the pilot customer.

---

## 14. Open Product Decisions for the Owner

1. Should RFSU/PAC/FAC or PMS come first?
2. Are real engineering import sample files available?
3. Which first contract/payment-certificate form is the target: Conditions of Contract 4311 or a specific NOC form?
4. Which NOC numbering scheme should be implemented first?
5. Should minimal HSE come before or after PMS?
6. Is Docker/devcontainer acceptable, or is a manual installation path preferred?
