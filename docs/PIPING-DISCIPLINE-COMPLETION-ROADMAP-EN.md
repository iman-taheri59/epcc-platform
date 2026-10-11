# Piping Discipline Completion Roadmap — EPCC Platform

Date: **2026-10-05**

## 1. Decision Principle

All disciplines required by EPCC projects must remain represented in the product model, and no decision should turn the platform into a “piping-only” system. However, to build a sellable and dependable product, **Piping is selected as the first deep and complete discipline**.

Why Piping first:

- Piping connects strongly to Engineering, Procurement, Construction, QA/QC, and Commissioning.
- Its data is measurable: line, component, port, joint, weld, NDT, test pack, dia-inch, material traceability.
- Its field errors are expensive: flange rating mismatch, missing material, weld/NDT repair, hydrotest delay.
- A correct Piping pattern can be reused for Electrical, I&C, Civil, and Mechanical.

---

## 2. “All Disciplines Considered, Piping Completed First” Rule

### 2.1 What remains common to all disciplines

These capabilities must stay discipline-aware and must not be hardcoded to Piping:

- CDE / Document register
- Transmittal and review cycle
- Project setup and discipline registry
- Facility / area / subsystem breakdown
- Procurement/materials and MTO/MRR/warehouse/site delivery
- Punch list and completion certificates
- Authorization/RLS and visibility
- Reporting/PDF/export
- Units and reference data framework

### 2.2 What becomes Piping-specific for now

- Piping class / PMS rules
- Branch table
- Equipment / nozzles as piping terminations
- Fit-up capture
- Supports and spools
- Welding and NDT
- Test packs / hydrotest
- PCF import
- Dia-inch progress
- Piping roll-ups into subsystem readiness

---

## 3. Current Piping Status

According to `HANDOFF.md` and the current code:

| Area | Status |
|---|---|
| Reference packs | Built: sizes, schedules, end types, component templates, units |
| Piping lines | Built: register, numbering, AFC gate |
| Numbering editor | Built |
| Evidence attachment | Built |
| Unit preferences | Built |
| Components / ports | Built |
| Joints / compatibility / dia-inch | Built |
| PMS rules | **Built (ADR-008 phases 1–2, 2026-10-11): classes, rules + EXCLUDE constraint, publish gate, branch table + specificity resolver — awaiting runtime verification** |
| Equipment / nozzles | Designed in ADR-010, not built |
| Fit-up | Designed in ADR-006, not built |
| Supports/spools | Designed, not built |
| Welding/NDT | Needs additional ADR/design |
| Test packs / hydrotest | In SRS, not built |
| PCF import | Designed in ADR-005/006, not built |
| Incremental roll-ups | Recommended, not built |

---

## 4. Definition of “Piping Complete” for the MVP

Piping is complete for the MVP when this operational journey works end to end:

1. Create/import the line list
2. Configure PMS / piping class
3. Build a line model with components/ports/joints
4. Terminate a line at an equipment nozzle or a valid endpoint
5. Validate compatibility and PMS deviations
6. Connect MTO/material to procurement and warehouse/site delivery
7. Capture fit-up and weld/NDT for the required joints
8. Build test packs / hydrotest packages
9. Drive punch and MCC/RFSU readiness from real line/material/weld/test status
10. Report progress by joint/dia-inch/test pack incrementally, not by expensive live aggregate
11. Produce PDF/Excel/turnover evidence for line/test pack/weld log

---

## 5. Proposed Piping Completion Phases

### Phase P0 — Full runtime environment and baseline tests

**Prerequisite for all phases.** PMS, RLS, and Piping migrations are PostgreSQL-heavy, so work should not start without PHP/PostgreSQL available.

Outputs:

- Full PHPUnit execution against PostgreSQL
- Piping demo/seed project
- Smoke tests for existing Piping screens
- Snapshot of current state before changes

### Phase P1 — PMS and branch table

> **Status 2026-10-11: IMPLEMENTED — awaiting runtime verification.** All six
> outputs below landed in migration `2026_10_11_120000_create_piping_classes_and_rules`,
> `PipingClassService`, and `PipingClassTest` (ADR-008 §9). Wiring into
> component attach / joint compatibility is ADR-008 build phase 3, still open.

Based on ADR-008:

- `piping_classes`
- `piping_class_rules` with DN half-open ranges
- `EXCLUDE USING gist`
- Gap detection in the publish gate
- Branch table with specificity-wins resolution
- Integration with component attach and joint compatibility
- **Addendum (owner 2026-10-11, ADR-008 §9.1):** the branch outcome
  vocabulary is closed — `TEE / WELDOLET / SOCKOLET / P2P`; `P2P`
  (pipe-to-pipe) is a first-class outcome meaning *no component*, with
  kinds **stub-in / stub-on** and a reinforcement pad whose thickness (mm)
  is mandatory when the pad is required.

Value: stops incorrect data before it reaches the field.

### Phase P2 — Equipment / nozzles

Based on ADR-010:

- Minimal equipment register
- `equipment_nozzles`
- Nozzle ports
- Line endpoint upgrade from free text to FK
- Nozzle schedule report
- Compatibility between nozzle and component ports

Decisions required before starting:

1. Tag uniqueness: per project or per facility? Recommendation: per facility.
2. Is an instrument register needed now, or are in-line instruments as tagged components enough?
3. Should equipment tags be generated by the system or entered/imported and validated? Recommendation: entered/imported + pattern validation.

### Phase P3 — Fit-up and field capture

Based on ADR-006 phase 4:

- Joint state: MODELLED → FITTED
- `fitted_by_code`
- `fitted_at`
- State transition constraints
- Field capture without requiring a user account for every fitter

Value: first real field data attached to the Piping model.

### Phase P4 — Supports and spools

- Support register with the same numbering engine pattern as lines
- Support document linkage
- Optional spool grouping
- Shop/field derivation
- Spool status and release

### Phase P5 — Welding / NDT / repair loop

Needs detailed design, but the MVP minimum is:

- Weld log for joints where it is required
- Welder code and WPS reference
- NDT request/result
- Repair cycle
- Repair rate KPI
- Heat/material traceability to weld where feasible
- **Branch-weld joint types (owner requirement, 2026-10-12, proposal P-21):**
  stub-in/stub-on P2P connections are welds, not components — the joint
  register must carry `BRANCH_STUB_IN` / `BRANCH_STUB_ON` as first-class
  joint types so branch welds are counted and reported separately from butt
  welds, and pad requirements stay traceable from the ADR-008 class rules.

This phase must align with QA/QC and may deserve its own ADR.

### Phase P6 — Test pack / hydrotest

- Test pack build from line/joint/subsystem
- Prerequisites: material, weld/NDT, punch, documents
- Hydrotest record
- Reinstatement checklist
- Certificate/PDF

### Phase P7 — PCF import

Based on ADR-005/006:

- Upload → preview → commit
- Immutable import batch
- No auto-numbering for imported unnumbered welds
- Mapping to components/ports/joints
- Deviation report
- Rollback/reject before commit

### Phase P8 — Roll-ups and turnover

- Incremental progress tables
- Subsystem readiness contribution
- Dia-inch earned progress
- Line/test pack/weld turnover export
- Dashboards for Piping Lead and Commissioning

---

## 6. Required Tests for Piping Phases

Every phase must include:

- Positive and negative RLS
- Archived project read-only behaviour
- Persian/English and RTL/LTR
- Real HTTP paths, not only service tests
- Fresh PostgreSQL migrations
- Tabletools for registers
- PDF magic byte and access control if it produces documents
- Mutation/probe tests for critical rules such as PMS overlap, branch specificity, and dia-inch minimum diameter

---

## 7. Impact on Other Disciplines

The Piping pattern should produce generic seams, not closed code:

| Piping pattern | Future reuse |
|---|---|
| line/component/port/joint | cable/drum/pull/termination for Electrical |
| equipment/nozzle endpoint | instrument tap/nozzle/connection for I&C |
| PMS validation | cable spec / instrument spec / material spec validation |
| test pack | loop folder / energization package / concrete pour dossier |
| weld/NDT traceability | cable test / calibration / concrete cube tests |
| dia-inch progress | discipline-specific earned quantity |

---

## 8. Requirement Design Recommendations

1. **Go deep on Piping rather than building every discipline shallowly at once.**
2. **Every Piping feature should leave behind a generic pattern** so the next discipline is faster.
3. **PMS should come before PCF import**; importing without a validator only imports wrong data faster.
4. **Equipment/nozzles should come before welding/NDT** because endpoint compatibility catches expensive errors early.
5. **Roll-ups must not be live aggregates**; the ADR-006 benchmark showed heavy aggregates under RLS are expensive.
6. **Welding/NDT likely needs its own ADR** because it contains several sensitive workflows and KPIs.

---

## 9. Recorded Product Decision

- All EPCC disciplines remain in product scope.
- The deep implementation priority from here is: **complete the Piping discipline**.
- General prerequisites such as runtime environment, RLS safety, and documentation alignment remain mandatory.
