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


### Additional decision — 2026-10-05: complete Piping first

All EPCC disciplines remain in product scope, but the deep implementation priority from this point is **completing the Piping discipline**. Detailed phasing is recorded in `docs/PIPING-DISCIPLINE-COMPLETION-ROADMAP-EN.md`; the Persian counterpart is `docs/PIPING-DISCIPLINE-COMPLETION-ROADMAP-FA.md`.


### Additional decision — 2026-10-05: synthetic preview data and UX redesign

Testing and previews will use realistic synthetic data. Because the current UI feels cluttered, the UX direction shifts from register-first to task-first. The proposed plan is recorded in `docs/UI-UX-IMPROVEMENT-PLAN-EN.md` with the Persian counterpart `docs/UI-UX-IMPROVEMENT-PLAN-FA.md`.

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

## 6. Phase 2 — Piping Completion P1: PMS and Branch Table

**Goal:** Move Piping from modelling to engineering validation. Details are in `docs/PIPING-DISCIPLINE-COMPLETION-ROADMAP-EN.md`.

### Items

- `piping_classes` and `piping_class_rules`
- DN half-open ranges `[from, to)`
- `EXCLUDE USING gist` to prevent overlap in one-dimensional rules
- Gap detection at publish time
- Branch table resolved by specificity-wins
- Validation integration with component attach and joint compatibility

---

## 7. Phase 3 — Piping Completion P2-P4: Equipment/nozzles, Fit-up, Supports/Spools

**Goal:** Bring real endpoints and field data into the Piping model.

### Items

- Minimal equipment register
- Equipment nozzles and nozzle ports
- Upgrade free-text endpoints to FKs when equipment/nozzle data exists
- Nozzle schedule report
- Fit-up capture for joints
- Support register with numbering engine
- Optional spool grouping

---

## 8. Phase 4 — Piping Completion P5-P8: Welding/NDT, Test Packs, PCF Import, Roll-ups

**Goal:** Make Piping truly end-to-end for construction and commissioning.

### Items

- Weld log, welder code, WPS reference
- NDT request/result and repair cycle
- Test pack / hydrotest package
- PCF upload → preview → commit
- Immutable import batches
- Incremental roll-ups for dia-inch, joints, test packs, and subsystem readiness

### Note

Welding/NDT likely needs its own ADR because it contains sensitive workflows, KPIs, and traceability requirements.

---

## 9. Phase 5 — Official Outputs and Completion Certificates

**Goal:** Once Piping is connected to real data, outputs and certificates become operationally meaningful.

### Items

- Line/test pack/weld log PDFs alongside previously planned PDFs
- Payment certificate, punch register, GRN/MIV PDFs
- RFSU/PAC/FAC driven by line/material/weld/test status

---

## 10. Phase 6 — Engineering Import and the Next Discipline

**Goal:** After Piping validators are in place, begin engineering import and generalise the pattern to the next discipline.

### Items

- Real sample line list, MTO, PCF, and Navisworks property export files
- Import profiles
- Discipline pattern extraction for Electrical/I&C or the next selected discipline

---

## 11. Phase 7 — Minimal HSE

**Goal:** Deliver the minimum HSE surface credible for an Iranian oil and gas demo after the Piping pattern is stabilised.

### MVP HSE

1. PTW / Permit to Work
2. Incident report
3. HSE observation
4. Toolbox talk attendance

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

1. Decided: PMS and Piping completion come before deep RFSU/PAC/FAC work unless a demo/customer need reverses it.
2. Are real engineering import sample files available?
3. Which first contract/payment-certificate form is the target: Conditions of Contract 4311 or a specific NOC form?
4. Which NOC numbering scheme should be implemented first?
5. Minimal HSE comes after the Piping pattern is stabilised, unless a pilot client pulls it forward.
6. Is Docker/devcontainer acceptable, or is a manual installation path preferred?
