# ADR-EPCC-010 — Equipment and nozzles, custom component templates, and the dia-inch convention

> **Status — 2026-10-05:** Partially realised. Dia-inch using the smaller diameter is built through the joint model; equipment/nozzles remain pending and are now phase P2 of Piping completion. Open decisions before build: tag uniqueness scope, instrument register timing, and whether equipment tags are generated or imported/validated.


**Status:** **PARTLY BUILT** — section 1 (the dia-inch convention) is built and tested in phase 3; sections 2 (custom templates) and 3 (equipment and nozzles) remain proposed.
**Date:** 2026-09-14
**Depends on:** ADR-006 (piping model), ADR-007 (reference data), ADR-009 (manual modelling)

**Triggers (user, 2026-09-14):** answers to ADR-009 §7 — (1) dia-inch uses the **smaller**
diameter, (2) custom component templates: «نظر خودت», (3) **model equipment**.

---

## 1. Dia-inch: the smaller diameter governs

> «قطر کوچکتر»

```php
// JointMetrics::diaInch(Joint $j): float
return min($j->portA->nps_decimal, $j->portB->nps_decimal);
```

Trivial to implement, easy to get wrong later, and the error is invisible: it does not crash, it
just inflates progress. Measured on a five-weld sample:

| weld | A | B | dia-inch (smaller) | dia-inch ("larger") |
|---|---|---|---|---|
| W-001 | 6" | 6" | 6.0 | 6.0 |
| W-002 | 6" | **2"** | **2.0** | 6.0 |
| W-003 | 2" | 2" | 2.0 | 2.0 |
| W-004 | 12" | **8"** | **8.0** | 12.0 |
| W-005 | 1" | 1" | 1.0 | 1.0 |
| | | **total** | **19.0** | **27.0** |

*(Corrected 2026-09-18: the table as first written printed only the three welds
that differ between the two conventions, so its rows summed to 16 rather than
the 19 quoted in the text. The equal-bore welds W-003 and W-005 were always part
of the sample — they are what makes the point that a fixture of equal-bore welds
alone would pass under either reading. `JointTest` now asserts the full five.)*

The "larger governs" reading gives **27.0** — a **42% overstatement** on this sample. On a
project where fabrication is paid or progressed by dia-inch, that is a commercial number.

Therefore:

- `dia_inch` is **computed, never stored on the joint**. Storing it would let it drift from the
  port sizes, the same defect avoided by not storing `fullCode()` (ADR-004) and by deriving
  Eq./Red. tee from port sizes (ADR-009 §4.2).
- **Except** in a *progress snapshot*. When a weld is accepted and included in a progress
  statement, the dia-inch of that statement is frozen alongside it — same rule as certificates
  snapshotting their identifiers. A later size correction must not silently rewrite an approved
  invoice.
- The convention gets a named test asserting **19.0, not 27.0**, with the reducing welds in the
  fixture. A fixture of equal-bore welds would pass under either convention and prove nothing —
  the same trap as testing pipe schedules only at 2" (ADR-007 §2).

---

## 2. Custom component templates — my recommendation

> «نظر خودت»

**Two tiers, and the boundary is "who bears the consequence of it being wrong".**

| Tier | Who defines | Examples | Mutability |
|---|---|---|---|
| **Universal** | ships with the release, in the ADR-007 reference pack | PIPE, ELBOW, TEE, REDUCER, VALVE, FLANGE, CAP, WELDOLET, NOZZLE | **read-only**, versioned |
| **Project** | the project's engineering lead, in `component_templates` | proprietary coupling, special strainer, vendor skid connector | editable while DRAFT, locked on publication |

Reasoning:

1. **A tee has three ports everywhere.** That is not a project opinion, and letting a project
   redefine it invites a data-entry error that silently corrupts every branch check. Universal
   templates are read-only for the same reason ASME dimensions are (ADR-007 §4): the real
   protection needed is *nobody can write*, not *the right people can write*.
2. **But the universal set will never be complete.** A Victaulic coupling, a hygienic clamp, a
   vendor's proprietary quick-connect — refusing these would force users to model them as
   something they are not, which is worse than letting them declare the truth.
3. **A project template may not shadow a universal one.** `TEE` is reserved. A project defines
   `TEE-VIC` or similar; the system rejects a collision rather than silently preferring one. This
   is exactly the `FacilityKind::all()` vs `selectable()` distinction (ADR-004): the vocabulary
   is closed, the *usable subset* is configurable.
4. **Project templates are subject to the same locking as piping classes** (ADR-009 §4.4): once
   a line uses a template, changing the port count would orphan existing ports. Edits create a
   revision.

```
component_templates
  id, project_id (NULL = universal), code, name_en/fa,
  ports jsonb       -- [{name, role}], count implied by array length
  size_rule         -- ALL_EQUAL | RUN_EQUAL_BRANCH_LTE | P1_GT_P2 | ...
  state             -- DRAFT | PUBLISHED
  UNIQUE (project_id, code)
  -- plus an application rule: a project code may not equal a universal code
```

`project_id IS NULL` for universal rows is the one place a table in this system is
cross-tenant — and it is read-only, which is the condition that makes it safe (ADR-007 §4).

---

## 3. Equipment — the part that needs care

> «تجهیزات رو مدل کن»

### 3.1 The insight that shapes everything

A nozzle is **a port owner**, exactly like a component. Once that is accepted, the question
"how does a joint terminate at equipment?" dissolves — a pipe-to-nozzle joint is just two ports,
and **the `joints` table needs no change whatsoever**.

**Verified before proposing.** A spike with one `ports` table, an `owner_kind` discriminator and
a CHECK enforcing exactly one owner:

```
joint links: COMPONENT <-> NOZZLE        ← inserted with no joint-table change
mixed owner (owner_kind=COMPONENT + nozzle_id) → REJECTED by check_violation
```

So the model is:

```
equipment ──< equipment_nozzles ──< ports (owner_kind = NOZZLE)
                                      │
components ───────────────────────────┴──< ports (owner_kind = COMPONENT)
                                              │
                                            joints (two ports, unchanged)
```

This mirrors Plant 3D exactly: its `AssetOwnership` table stores "which nozzle belongs to which
equipment", and a nozzle is a **sub-part** of equipment (`NozzleSubPart`, with its own
`PartSizeProperties` and `ConnectionPortCount`) — not a line component. The property dump in
ADR-006 §2 shows a real nozzle carrying `Tag: N-1`, `ConnectionPortCount: 2`, `Shop_Field: SHOP`.

### 3.2 The alternative I rejected

Keeping `component_ports` as-is and adding a separate `nozzle_ports` table, with `joints` gaining
`port_a_kind`/`port_b_kind` columns. Rejected because every joint query would need a two-branch
`UNION` or `COALESCE`, and the compatibility engine would need parallel code paths that can
drift. That is the same "sibling tables ⇒ two-branch COALESCE" smell that argued for one
`facilities` table in ADR-004. **One ports table with a discriminated owner is the same pattern,
applied again.**

Cost to be honest about: `ports` becomes the largest table in the system (ADR-006 §6 measured
~530k rows for a mid-size unit) and now carries two nullable FK columns. The CHECK makes the
invalid combinations unrepresentable, and a partial index per owner kind keeps lookups tight.

### 3.3 Equipment is a discipline, not a table

Modelling equipment properly means accepting scope. Minimum useful set:

```sql
equipment
  id, project_id, company_id,
  subsystem_id,                  -- same parent as lines (ADR-004 gives facility/area above)
  tag_number,                    -- UNIQUE(project_id, tag_number) - V-101, P-201A, E-301
  equipment_type,                -- VESSEL|PUMP|EXCHANGER|TANK|COMPRESSOR|FILTER|SKID|OTHER
  name_en, name_fa,
  service_description,
  -- design data, mirroring piping_lines so reports can treat them alike
  design_pressure, design_temperature, operating_pressure, operating_temperature,
  design_code,                   -- ASME VIII Div.1, API 610, TEMA ...
  material_spec, insulation_type,
  weight_empty_kg, weight_operating_kg,
  -- commercial / traceability
  po_item_id,                    -- nullable FK to purchase_order_items: equipment is PROCURED
  vendor_company_id,             -- nullable FK to companies
  datasheet_document_id,         -- CDE document
  ga_drawing_document_id,        -- general arrangement
  external_ref,                  -- Plant 3D PnPID
  state                          -- PLANNED|ORDERED|DELIVERED|INSTALLED|COMMISSIONED

equipment_nozzles
  id, project_id, company_id,
  equipment_id,
  tag,                           -- N-1, N-2, M-1  UNIQUE(equipment_id, tag)
  nozzle_type,                   -- PROCESS|VENT|DRAIN|MANWAY|INSTRUMENT|RELIEF|SPARE
  nps_label, nps_decimal, dn,    -- ADR-009 four-column convention
  end_type,                      -- FL / BW / SW  (PCF vocabulary, ADR-007)
  rating, facing,                -- 150#, RF/FF/RTJ
  schedule, wall_thickness,
  elevation, orientation,        -- N/S/E/W or degrees, for the layout report
  is_spare boolean,              -- a capped spare nozzle is not an open end
  datasheet_ref, external_ref
```

`subsystem_id` on equipment means **equipment joins the existing completion machinery for free**:
punch items, MC certificates and material status are all keyed to subsystems already. A vessel
becomes MC-able without new plumbing.

### 3.4 What equipment unlocks immediately

| Capability | How |
|---|---|
| a line terminates at real equipment | `from_ref`/`to_ref` free text (ADR-006 §5.1) is replaced by nozzle FKs; "which lines connect to V-101?" becomes a query |
| **nozzle schedule report** | the classic deliverable: every nozzle of a vessel, its size, rating, and the line attached — or **UNCONNECTED**, which is the error worth finding before hydrotest |
| open-ends analysis gets honest | an unconnected non-spare nozzle is an **open end**; `is_spare` prevents false positives on capped nozzles |
| equipment-to-piping compatibility | the same `PortCompatibility` engine: a 6" 300# RF nozzle against a 6" 150# RF flange is a **hard INVALID**, a genuinely common and expensive field error |
| procurement linkage | `po_item_id` ties the physical vessel to its PO, its MTC and its vendor — the traceability chain the materials module already has for bulk items |

That fourth row is worth emphasising: flange rating mismatches at equipment connections are
discovered at bolt-up, on site, with the crane already hired. Catching them at model-entry time
is exactly the «خیلی از اشتباهات کم شده» the user is after.

### 3.5 Scope discipline — what equipment does *not* get now

Deliberately excluded, to keep this from becoming an equipment-engineering module:
internals and trays, mechanical seals, drivers/motors as separate tagged items, foundation and
anchor bolts, vendor document expediting, spare-parts lists, rotating-equipment alignment
records, preservation. Each is real; none is needed for piping to terminate correctly.

---

## 4. Consequences for earlier ADRs

| ADR | Change |
|---|---|
| **006 §5.1** | `piping_lines.from_ref`/`to_ref` become nullable FKs to `equipment_nozzles`, with the free-text columns retained as fallback for "connects to future package X" |
| **006 §5.2** | `component_ports` is generalised to `ports` with `owner_kind` — a rename plus a discriminator, done before any data exists |
| **006 §5.3** | `joints` **unchanged** — the whole point of §3.1 |
| **009 §2.2** | `NOZZLE` joins the universal template set (1 port, role `PROCESS`) |
| **008** | the branch table is unaffected; a nozzle is never a branch |

---

## 5. Build order (revised, consolidated)

| Phase | Content |
|---|---|
| 0 | ADR-007 reference packs **+ component templates** (universal, incl. NOZZLE) |
| 1 | `piping_lines` + numbering + AFC gate |
| 2 | `ports` (discriminated) + `components` + template-driven creation |
| 2b | manual modelling UI (ADR-009 §2.3) |
| 3 | `joints` + compatibility + per-line numbering + **`diaInch()` with the smaller-diameter test** |
| **3b** | **`equipment` + `equipment_nozzles`**, nozzle schedule report, line↔nozzle termination |
| 4 | fit-up capture |
| 5 | supports, spools |
| 6 | PCF import (creates components *and* resolves `END-CONNECTION-EQUIPMENT` to nozzles) |
| 7 | roll-ups, deviation reporting |

Equipment lands at **3b** rather than phase 1: a line can be entered and modelled with free-text
endpoints from day one, and equipment upgrades those endpoints to real references without
migrating any joint. Nothing earlier is blocked by it.

---

## 6. Open questions

1. **Equipment tag uniqueness** — unique per *project*, or per *facility*? ADR-004 made subsystem
   codes per-facility for good reasons; the same argument may apply to `V-101` on two plants.
   I lean **per facility**, consistent with ADR-004.
2. **Instruments** — in-line instruments (control valves, flow meters, orifices) are piping
   components with tags; off-line ones (transmitters, gauges) are not. Do you want an
   instrument register now, or are in-line instruments adequately covered as tagged components?
3. **Does equipment need its own numbering engine?** Tags come from the P&ID and are rarely
   generated by us — I assume **entered/imported, validated against a per-project pattern**, not
   allocated. Confirm.
4. **Dia-inch of branch welds (2026-10-12, review finding R-4).** §1's rule is
   `min(portA, portB)` — but a stub-in/stub-on branch weld (ADR-008 §9.1) has
   no header-side port (P-22). The natural reading of "the smaller governs" is
   **the branch nominal size**, reported **separately** from butt-weld
   dia-inch per proposal P-21 rather than absorbed into it. Confirm the
   convention (and any norm-based weighting) when the welding phase is designed.

---

## 3.6 Built (2026-11-09)

Migration `2026_11_04_100000`, guard `epcc_guard_equipment`, and
`EquipmentNozzleTest`: **9 tests / 17 assertions**, with the joint, component,
piping and compatibility region green (384 tests / 2072 assertions) and the
whole suite green on CI (2234 tests / 28139 assertions, 0 failures, 0 errors,
0 skipped).

The claim in §3.1 is now proved rather than asserted. `EquipmentNozzleTest`
writes a joint between a component port and a nozzle port through the ordinary
`port_a_id` / `port_b_id` columns. If `joints` had needed a discriminator it
would have needed a migration, not a test.

What is now true:

- `equipment` and `equipment_nozzles`, both RLS-scoped, with the FK from
  `ports.nozzle_id` that the `ports` table has been waiting for since
  2026-09-17, and a unique `(nozzle_id, seq)` so a nozzle's ports are numbered
  once for the same reason a component's are.
- Equipment sits on a subsystem, so punch items and MC certificates reach it
  with no new plumbing — a vessel is MC-able for free.
- Open-end is derived: unconnected AND not spare. `EQ_*` guard codes hold the
  outside world equipment points at (subsystem, purchase order line,
  datasheets) to this project.

Scope discipline held, per §3.5: no internals, trays, seals, drivers as
separate tags, foundations or preservation.

### Two things worth remembering

- **A joint stores its two ports in a fixed order** (`joints_ordered_pair`).
  Three local runs passed with one draw of random UUIDs and CI failed on the
  next. A test that writes two UUIDs into ordered columns must order them
  itself, or it is a coin flip.
- **A fitted joint must say who fitted it and when.** The nozzle end gets no
  exemption from `joints_fitted_needs_evidence`.

### Not built yet

No service, controller or screen. This slice is the structure and the proof;
`EquipmentNozzleTest` still hand-writes its nozzle ports because nothing
creates them yet. The nozzle schedule report (§3.4) and equipment-to-piping
compatibility through `PortCompatibility` are the natural next increments —
the latter is the one that catches flange rating mismatches at model-entry
time instead of at bolt-up.

## 3.7 Built (2026-11-09) — the service and the screen

The register now runs end to end. `EquipmentService` owns the model,
`EquipmentController` serves two screens, and equipment sits in the sidebar
next to piping rather than in a spreadsheet.

**The capability, and why it is shaped that way.** `equipment.maintain` is a
MODELLING capability, not a viewing one. It sits on employer_rep,
project_manager, consultant and mc_supervisor. It is deliberately withheld
from qc_inspector and site_engineer — and that withholding is the interesting
part, because those two still READ the register through plain `project.view`.
A QC inspector who cannot see what the nozzles are cannot inspect the welds on
them, but a QC inspector who can move a nozzle around is rewriting the design
to suit the welds. `EquipmentRegisterTest` holds both halves: he gets a 200 on
the index with no create form at all, and a 403 if he posts anyway.

**The schedule is the deliverable.** `nozzleSchedule()` walks
nozzle → port → joint → other port → component → line, and reports
`is_open_end = connected_line IS NULL AND NOT is_spare`. Nothing stores it.
The screen shows the line where there is one, UNCONNECTED where there is not,
and SPARE where it is capped. That distinction is the reason §3 exists at all:
a capped spare is a design decision, and putting it on the worklist as
unfinished work is how a hydrotest gets delayed by a nozzle nobody ever meant
to weld.

### Three things worth remembering

- **`whereKey()` is Eloquent only.** On `DB::table(...)` it emits
  `column "key" does not exist`, and the only symptom is a later, unrelated
  `current transaction is aborted` from the next `set_config`. The subsystem
  guard in `validated()` uses `where('id', ...)` for exactly this reason.
- **Every write policy on these tables demands `created_by` =
  `epcc.result_actor_id`.** `TenantContext::runAs()` sets the company and the
  project but not the actor, so the service wraps its writes in `actingAs()`,
  borrowed from `InspectionReleaseNoteService`. Without it the INSERT is
  refused with 42501 and nothing on the PHP side says why.
- **`project_piping_config` is writable only by the company that owns it.**
  Test fixtures that build a line must run as the employer's representative,
  not the EPC's, or `configFor($project)->update(...)` fails.

### Not built yet

Equipment-to-piping compatibility through `PortCompatibility` — the increment
that catches a flange rating mismatch at model-entry time instead of at
bolt-up. Also deliberately out: internals, trays, seals, drivers as separate
tags, foundations, preservation. A nozzle port is still created by the test
helper rather than by the service, because nothing in the product has asked
for one yet.

## 3.8 Built (2026-11-10) — what a joint may connect, and saying so

Three gaps §3.7 left open, closed together because they are one question: what
may a joint actually connect, and does the system say so when the answer is no.

**The nozzle path is open.** `addNozzle()` now declares the nozzle's port as it
declares the nozzle, from the nozzle's own dimensions, so the two cannot drift.
Without it the register showed a nozzle that nothing could be welded to and the
schedule could only ever say UNCONNECTED. `JointService::lineFor()` takes the
line from whichever side has one — a nozzle belongs to equipment, not to a
line, and contributes no number. Two nozzles and no pipe stays refused.

**The cross-line rule is now true in the database.** `epcc_guard_joint_line`
rejects a joint whose component sides disagree with its `piping_line_id`, and
one whose only sides are nozzles. It permits the nozzle case by design; writing
it before the nozzle path opened would have made the two fight. The service
refusal was never the weakness — the weakness was that `joints` is a table and
the test suite writes to it directly.

**PCF says what it cannot represent.** Three warnings at intake:
`cross_line_component` (a component carrying a PIPELINE-REFERENCE other than
the file's own), `tap_connection` (a branch landing on a header wall), and
`branch_on_header_wall` (a TEE-SET-ON / TEE-SET-IN component, which PCF writes
as a whole component rather than a tap). None of them was reported before; a
file with a tie-in imported clean and was read as an ordinary run of the file's
own line. A silently reshaped model is worse than a refused one — a refusal
sends someone to look.

### Four things worth remembering

- **`ports_size_pair_complete` wants the size label and the decimal together or
  not at all.** The nozzle form asks only for the label, so `declarePort()`
  derives the decimal from it via `PipeReference::resolveLabel()`. Without that,
  declaring `6"` was refused by a database error about a column nobody had seen.
- **`epcc.result_actor_id` must be set before `ComponentService::place()`**, not
  only before writes to `equipment`. The symptom is an RLS refusal on `ports`,
  which does not mention the actor at all.
- **`equipment_shape` requires name_en and name_fa of at least two trimmed
  characters.** A fixture using `'S'` fails the insert, not a validation rule.
- **The `both_ends_are_nozzles` message already existed, unwired, in both
  language files.** Someone anticipated this case and never connected it. Worth
  grepping `lang/` for an unused key before inventing one.

### Not built, and not faked

Cross-line two-port tie-ins still remain refused: neither line owns a two-ended
tie-in joint. P2P branch welds on a header wall use the frozen-header path in
ADR-006 §14; an OLET uses the separate path in §15, with its real RUN port
referencing the header wall and its BRANCH port joining the branch line. Neither
path invents a third header port, and the purchased OLET remains a component in
the model/MTO. These are model/service paths, not end-to-end import workflows:
no wall-joint UI creates them and `PcfAdmission` only reports standard PCF taps
read-only. Do not fake a P2P stub as an OLET or an OLET as a fitting-less stub.
