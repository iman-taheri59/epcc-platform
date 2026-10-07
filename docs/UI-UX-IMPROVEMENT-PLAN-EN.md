# UI/UX Improvement Plan — EPCC Platform

Date: **2026-10-05**

## 1. Execution Decision

For testing, previews, and demos, use **synthetic but realistic data**. The fake data must demonstrate workflows, not merely random records. The preview should feel like a real EPCC project: line list, documents, materials, punch, certificates, and Piping status.

Because the current product priority is completing the Piping discipline, the new UX should go deep on Piping first while keeping the pattern reusable for all EPCC disciplines.

---

## 2. Current UI Problem

Based on the current views and shell structure:

1. Pages feel more like technical registers than a guided product.
2. The heavy dark palette plus dense tables gives an “internal developer tool” feel.
3. Too much information is shown at once; visual hierarchy is weak.
4. The user path is unclear: users do not know the next best action.
5. Forms, tables, and actions vary by page; a unified page template is not obvious.
6. The sidebar is valuable because it is authorization-aware, but it still feels technical and register-driven.
7. Statuses, badges, empty states, and error/action messages need stronger product-level standardisation.

---

## 3. Proposed Design Principle

### 3.1 Move from register-first to task-first

EPCC naturally has many registers, but users should not be left alone with tables. Every register should answer the main work questions:

- “What is stuck?”
- “What must be done today?”
- “What is ready for release/approval?”
- “What blocks MCC/RFSU?”

### 3.2 Every page should have three layers

1. **Context header**: project, discipline, subsystem/area, pack, overall status
2. **Action strip**: role-relevant primary actions
3. **Register/detail**: the main table or form

### 3.3 Piping-first

For Piping, pages should show the journey:

```text
Line → Components/Ports → Joints → Fit-up/Weld/NDT → Test Pack → Completion Readiness
```

At every step, the user should know where the line or subsystem is blocked.

---

## 4. Synthetic Data for Testing and Preview

### 4.1 Good synthetic data characteristics

Synthetic data should:

- Include Persian and English values;
- Include Jalali/Gregorian dates;
- Cover multiple company roles: Owner, EPC, PMC, TPI, Vendor;
- Include both clean and intentionally problematic cases;
- Demonstrate real Piping problems such as rating mismatches or missing NDT;
- Demonstrate authorization scenarios;
- Be resettable in preview.

### 4.2 Proposed demo project

A fixed demo project:

```text
Project: PH-14 South Pars Gas Compression
Code: PH-14
Default pack: ips-iran
Default language: fa
```

### 4.3 Demo Piping dataset

Minimum dataset:

- 3 facilities
- 6 areas
- 12 subsystems
- 40 piping lines
- 120 components
- 180 ports
- 95 joints
- 20 welded joints requiring NDT
- 5 rating mismatch warnings/errors
- 4 missing material cases
- 3 lines blocked from AFC
- 2 test packs
- 1 subsystem ready for MCC
- 1 subsystem blocked by an A-punch

### 4.4 Demo scenarios

1. **Line AFC gate**: a line blocked by B-code evidence or missing IFC.
2. **Nozzle mismatch**: a 150# flange connected to a 300# nozzle and marked INVALID.
3. **Material readiness**: a line not ready because gasket/bolt material has not reached site.
4. **Weld/NDT**: a joint is fitted up but has no NDT result.
5. **Test pack readiness**: a test pack is blocked by an A-punch or open weld repair.

---

## 5. Specific UI Recommendations

### 5.1 Role-based dashboard

Instead of dropping users directly into a register after login, provide a role dashboard:

- Project Manager: blockers, approvals, overdue items
- Piping Lead: line readiness, PMS deviations, test pack status
- Document Controller: reviews, transmittals, overdue acknowledgements
- QC Inspector: inspections, punch, NDT, test packs
- Warehouse: missing/available materials

### 5.2 New Piping page as a workspace

For `/projects/{code}/piping`:

- Top summary cards:
  - Lines total
  - AFC ready
  - Blocked
  - PMS deviations
  - Test packs ready
- Tabs:
  - Lines
  - Model
  - Joints
  - Weld/NDT
  - Test Packs
  - Deviations
- Quick filters:
  - Blocked only
  - Ready for next step
  - My company
  - By subsystem
  - By piping class

### 5.3 Detail drawer instead of excessive navigation

For line/joint/component details, open a side drawer or detail panel instead of forcing the user through many pages:

- Summary
- Blockers
- Documents
- Materials
- Joints
- Actions

This reduces navigation noise.

### 5.4 Standard page templates

Define four fixed templates:

1. Register page
2. Detail page/drawer
3. Workflow action page
4. Dashboard page

Every new view should use one of these templates.

### 5.5 Colour and visual hierarchy

The current dark theme is heavy. Recommendation:

- Default demo mode: light or neutral theme
- Dark theme as a user preference
- Stable colour meanings:
  - Green: ready/approved
  - Yellow: warning/deviation
  - Red: blocker/rejected
  - Purple/blue: informational/configuration
- Lighter page background and lower-contrast cards.

### 5.6 Real empty states

Instead of an empty table:

- Short explanation: “No lines have been imported yet”
- Primary CTA: “Import line list” or “Create first line”
- Short help link

### 5.7 User-friendly action labels

Replace technical action wording:

- `transition` → “Move to next step”
- `attach evidence` → “Add supporting document”
- `publish` → “Officially publish”
- `deviation` → “Record technical deviation”

### 5.8 Persistent breadcrumb and context

At the top of each page:

```text
Project / Facility / Area / Subsystem / Discipline
```

Mirror properly in Persian/RTL.

### 5.9 Guided demo mode

Add a toggle:

```text
Guided demo
```

When enabled, each page shows a small explanation box:

- What does this page do?
- What should the demo user try?
- Which data is intentionally broken?

### 5.10 Command palette / global search later

Eventually users should be able to type:

```text
PH-14-L-1001
MCC SS-2201
TR-0004
```

and jump directly to the object. For MVP, a project-level quick search is enough.

---

## 6. Suggested Information Architecture

The current authorization-aware sidebar should remain, but its structure should become more role/task oriented:

```text
Overview
My Work
Engineering
  Documents
  Piping Workspace
  Transmittals
Procurement & Materials
  Requisitions
  Warehouse
Quality & Completion
  Punch
  Test Packs
  Certificates
Admin / Setup
  Team
  Project Setup
```

The current planned/locked distinction must remain.

---

## 7. Low-risk Technical Implementation Plan

1. Centralise design tokens in one partial.
2. Add small Blade components:
   - `x-page-header`
   - `x-summary-card`
   - `x-status-badge`
   - `x-action-button`
   - `x-empty-state`
   - `x-filter-bar`
3. Before rewriting every page, rebuild only the Piping Workspace using the new pattern.
4. If it works, migrate Documents/Punch/Materials next.
5. Keep shell and tabletools tests intact.

---

## 8. Piping-specific UX Recommendations

### 8.1 Line readiness bar

For every line:

```text
Documents ✓ | Model ✓ | Materials ! | Weld/NDT × | Test Pack - | Completion -
```

### 8.2 Blocker-first view

The Piping Lead usually wants to know what is stuck. The default filter should be:

```text
Blocked / Needs attention
```

not all lines.

### 8.3 Deviation inbox

All PMS and compatibility warnings in one inbox:

- severity
- object
- reason
- suggested fix
- waiver/deviation action

### 8.4 Test pack board

Test packs as a board:

```text
Draft → Building → Ready for Hydrotest → Tested → Reinstatement → Closed
```

### 8.5 Visual traceability chain

For a joint:

```text
Line → Component A/Port → Joint → Component B/Port → Weld/NDT → Material heat → Test pack → Subsystem
```

This chain is very powerful in demos.

---

## 9. Execution Priority

1. Demo seed/fake data strategy
2. Piping Workspace UX mock in Blade or prototype
3. Design tokens and base Blade components
4. Summary cards and blocker-first line register
5. Line detail drawer
6. Deviation inbox
7. Test pack board
8. Then migrate other pages

---

## 10. Conclusion

The current UI is technically usable, but it is too register-driven and feels cluttered for end users. The better path is to use realistic synthetic data and a new Piping Workspace to move the experience from “looking at tables” to “solving project work”.
