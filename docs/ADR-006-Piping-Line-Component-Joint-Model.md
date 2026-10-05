# ADR-EPCC-006 — Piping: lines, components, ports and joints

> **Status — 2026-10-05:** Accepted and partially built. Built: ADR-007 phase 0 reference packs, phase 1 piping lines/numbering/AFC gate, phase 1b numbering editor/evidence attachment, phase 2 components/ports/manual modelling, and phase 3 joints/compatibility/dia-inch. Pending: PMS rules, equipment/nozzles, fit-up capture, supports/spools, welding/NDT, test packs, PCF import, and incremental roll-ups. Piping is now the first discipline targeted for deep completion.


**Status:** **PARTLY BUILT** — phase 1 (lines + numbering + AFC gate), phase 1b (numbering editor + evidence), phase 2 (components + ports) and **phase 3 (joints + compatibility + dia-inch)** are built and shipped. Phases 3b-7 remain proposed.
**Revised 2026-09-14** with two rounds of user answers — **§10** (first five) and **§11**
(second five, all resolved). Both override §5 where they differ. Spawned **ADR-007** for
universal reference data.
**Date:** 2026-09-14
**Supersedes part of:** ADR-005 §"what is a document vs what is data" (see §9)
**Depends on:** ADR-004 (facility / area / subsystem) — ACCEPTED and built

**Trigger (user, verbatim intent):** isometrics and support drawings are not things to *file*.
The system needs **their content**. Every piping line in a subsystem must be entered before
construction starts and after the engineering documents go **AFC**, with its attributes; then
every component from the isometric BOM, each with **ports**; each port connects to another
port, and that meeting point is a **joint** that must be numbered, carry properties derived
from its two ports, and be **validated for compatibility**.

---

## 1. What the user actually described (restated as a data model)

Reading the request back as entities, before choosing any schema:

| # | Statement | Entity / rule it implies |
|---|---|---|
| 1 | "هر زیر سیستم دارای چندین خط پایپینگ است" | `piping_lines` belongs to a **subsystem** (ADR-004 gives us that parent) |
| 2 | AFC engineering documents are the precondition | a line may not be *released* until its source documents are **AFC** — a gate, not a foreign key |
| 3 | piping class, coating, size, design P/T, operating P/T, test P + test fluid | line **attributes**, and most are *design intent*, not as-built |
| 4 | numbering `{size}-{service}-{class}-{subsystem}-{seq}-{coating}` | a **per-project configurable pattern**, not a hardcoded format |
| 5 | "قاعده شماره‌گذاری در هر پروژه می‌تواند متفاوت باشد" | the pattern is **project data**, and the PMS document is its authority |
| 6 | isometric has 1..n **sheets**, each sheet has a **BOM** | *first reading:* `isometrics` → `isometric_sheets`. **Corrected in §10.6** — components derive from the **MTO**, and the sheet number is just an attribute |
| 7 | component has 1..n **ports** with size, thickness, schedule, type | `components` → `component_ports`; a TEE has 3 |
| 8 | "مشابه پورت‌های نرم‌افزار Plant 3D" | adopt Plant 3D's `PartPort` / `P3dPartConnection` shape |
| 9 | two ports meet ⇒ a **joint** with a number and derived properties | `joints`, referencing exactly two ports |
| 10 | "امکان اتصال ۲ پورت باید ارزیابی شود" | a **compatibility rule engine** over (size, thickness, type) |
| 11 | 3D import should do this non-manually | import is an **accelerator**, manual entry stays first-class |

Point 11 is the one I had wrong in ADR-005, and §9 records the correction.

---

## 2. What Plant 3D really does (verified, not remembered)

The user asked to adopt Plant 3D's port model. Its 3D piping database is deliberately small —
**you cannot create custom classes in the 3D environment**, so the table set is fixed, unlike
the P&ID side. Topology lives in five relationship tables:

| Plant 3D table | What it stores |
|---|---|
| `PartPort` | the ports that exist on a part, and their properties: position, direction, nominal diameter, **end type** |
| `P3dPartConnection` | *which two parts are connected, and through which port on each* |
| `P3dLineGroupPartRelationship` | which parts belong to which **Line Group** (the line-number object) |
| `P3dDrawingLineGroupRelationship` | which line group lives in which drawing |
| `AssetOwnership` | equipment ↔ its **nozzles** |

Two details that shape our schema:

- **The connection is a first-class row, not a pointer on the port.** `P3dPartConnection` is a
  *relationship* table keyed by (part1, port1, part2, port2). Autodesk's own API exposes it only
  as `GetRelatedRowIds("P3dPartConnection","Part1",id,"Part2")`. This is exactly the user's
  "joint" — except Plant 3D stores no joint *number*, no weld procedure, no NDT, no welder.
  **The connection exists there as geometry; for us it is a construction object.**
- **A nozzle is a sub-part of equipment, not a component in the line.** Real property dump from
  a nozzle shows `ConnectionPortCount: 2`, `Tag: N-1`, plus `Shop_Field: SHOP`. So the model
  must allow a joint whose other side is an **equipment nozzle**, not a pipe component.

**End types.** Plant 3D calls it an *endcode*; ISOGEN/PCF calls it a two-character
**connection type** on each `END-POINT`. The canonical PCF list is fixed and short: `BW` butt
weld, `SW` socket weld, `FL` flanged, `SC` screwed, `PL` plain, `CP` compression, `PF` push fit,
`GL` glued, plus hygienic-class variants. **This is the vocabulary the compatibility rule must
speak**, and it is worth adopting verbatim rather than inventing ours.

---

## 3. PCF is the import format, and it is better than I claimed

A PCF is plain text, one component per block, and it carries precisely what this ADR needs:

```
PIPE
    END-POINT   34.5570  8.8345  0.0000  8.0000 PL
    END-POINT  -37.5680  8.8345  0.0000  8.0000 BW
    ITEM-CODE   PIPEAB40PE-100
    ITEM-DESCRIPTION  A53B GR B ERW SCH 40
    SPOOL-IDENTIFIER  SPOOL1
TEE-SET-ON
    END-POINT     1.7836 27.5220  0.0000  3.0000 PL
    CENTRE-POINT  5.1586 27.5220  0.0000
    BRANCH1-POINT 5.1586 30.9020  0.0000  3.0000 PL
    SKEY TEPL
WELD
    COMPONENT-IDENTIFIER 7
    MASTER-COMPONENT-IDENTIFIER 6
    END-POINT  2228812.31 1330744.31 11070.41 6.0000
    SKEY WW
    CATEGORY FABRICATION
```

Observations that matter:

- **Ports are explicit and typed**: `END-POINT x y z bore ENDTYPE`, `BRANCH1-POINT` for the
  third leg of a tee. A TEE literally emits 3 keypoints — the user's example, in the file.
- **Welds are already first-class objects** with `CATEGORY FABRICATION` (shop) vs
  `ERECTION` (field). That is the shop/field split a welding register needs, free.
- **Header carries the line**: `PIPELINE-REFERENCE`, `PIPING-SPEC`, `AREA`, `PROJECT-IDENTIFIER`,
  plus `UNITS-BORE` / `UNITS-CO-ORDS` which **must be read, not assumed** — `INCH-SIXTEENTH`
  and `MM-HUNDREDTHS` are legal and will silently corrupt sizes if ignored.
- It is emitted by Plant 3D, CADWorx, AVEVA, SmartPlant, Creo and 3DEXPERIENCE — so supporting
  PCF is not an Autodesk bet.

**Therefore: joints can be derived from geometry, not guessed.** Two ports at the same
coordinate are connected. That is how ISOGEN itself resolves connectivity.

---

## 4. The numbering problem is the interesting one

The user's example — `{size}-{service}-{piping class index}-{sub system code}-{sequence no.}-{coating type}`
— and the explicit statement that **it differs per project**.

We already have a numbering engine: `standards-packs/*.pack.json` carries patterns like
`{PROJECT}-CWA-{SEQ:2}` and `{CWP}-IWP-{SEQ:4}`, and services format with `sprintf('%s-MR-%04d')`.
So the mechanism exists — but line numbering has three properties the document numbering does
not:

1. **The authority is a project document (the PMS), not the standards pack.** Two projects on
   the same ISO pack will number lines differently. So the pattern belongs on the **project**
   (or on a per-project piping config), overridable, with the pack supplying only a default.
2. **Most tokens are attributes of the line itself**, not counters: `{size}`, `{service}`,
   `{class}`, `{coating}` come from columns; only `{sequence}` is allocated.
3. **Lines are frequently *imported already numbered*.** The number arrives from the P&ID or
   the 3D model. So the engine must work in **two directions**: *compose* a number from
   attributes, and **parse** a supplied number back into attributes to validate it against the
   pattern — and to catch the classic error of a line whose tag says `6"` while its components
   are 8".

That second direction is the one that earns its keep, and it is why the pattern should be
stored as an ordered list of typed tokens rather than as a bare string with `{}` in it:

```jsonc
// project_piping_config.line_number_pattern
{ "separator": "-",
  "tokens": [
    {"token":"size",       "source":"attribute", "attribute":"nominal_size", "format":"inch"},
    {"token":"service",    "source":"attribute", "attribute":"service_code", "picklist":"services"},
    {"token":"class",      "source":"attribute", "attribute":"piping_class"},
    {"token":"subsystem",  "source":"parent",    "attribute":"subsystem.code"},
    {"token":"sequence",   "source":"counter",   "width":3, "scope":"subsystem"},
    {"token":"coating",    "source":"attribute", "attribute":"coating_code", "optional":true}
  ]}
```

Typed tokens give parsing, validation and a UI preview for free; a raw string gives none of
them. `"scope":"subsystem"` matters because a sequence restarting per subsystem vs per project
is exactly the ambiguity ADR-004 already hit with subsystem codes.

---

## 5. Proposed schema

Eight tables. All carry `project_id` + `company_id` and the standard tenant RLS policy.

```
subsystems (exists)
  └─ piping_lines ───────────── the line number and its design attributes
       ├─ line_documents ────── AFC evidence: line ↔ CDE document (iso, P&ID, PMS, line list)
       ├─ isometrics ─────────── the drawing + revision, pointing at the CDE document
       ├─ pipe_supports ──────── §10.4/§11.3 — part of the line, SEPARATE register, no
       │                          ports; own project-configurable numbering pattern
       ├─ spools ─────────────── §10.5 — OPTIONAL grouping; a line may have none
       └─ components ─────────── PIPE / ELBOW / TEE / VALVE / FLANGE …
             │                    + mto_item_no, isometric_sheet_no, length_mm (pipes)
             └─ component_ports ── 1..n per component: size, thickness, schedule, end type
                   └─ joints ───── exactly two ports; state MODELLED→FITTED;
                                   number REQUIRED for welded/threaded, N/A for flanged

NOTE: `isometric_sheets` was in the first draft and is REMOVED (§10.6). The sheet is a
printing artefact; components and joints each carry `isometric_sheet_no` as a string.
```

### 5.1 `piping_lines`

| Column | Note |
|---|---|
| `subsystem_id` | the parent from ADR-004 |
| `line_number` | the composed tag, **`UNIQUE(project_id, line_number)`** |
| `number_tokens jsonb` | the parsed tokens — lets us re-validate if the pattern changes |
| `nominal_size`, `service_code`, `piping_class`, `coating_code` | the tokens, as real columns |
| `design_pressure`, `design_temperature`, `operating_pressure`, `operating_temperature` | numeric + unit |
| `test_pressure`, `test_fluid`, `test_type` | hydro/pneumatic; feeds the future test-pack module |
| `insulation_type`, `insulation_thickness`, `tracing_type` | Plant 3D acquisition properties; cheap now |
| `from_ref`, `to_ref` | free text "from V-101 nozzle N-1 to pump P-201A" until equipment exists |
| `release_state` | DRAFT → AFC_CONFIRMED → RELEASED_FOR_CONSTRUCTION |
| `external_ref` | Plant 3D Line Group `PnPID` / PCF `PIPELINE-REFERENCE` |

Units are **stored explicitly** next to each pressure/temperature. A project mixing barg and
kPa is normal; a column called `design_pressure` with an implied unit is a defect waiting for
its first foreign vendor.

### 5.2 `component_ports` — modelled on Plant 3D `PartPort`

| Column | Note |
|---|---|
| `component_id`, `port_index` | `UNIQUE(component_id, port_index)`; TEE has 0,1,2 |
| `port_name` | Plant 3D uses `S1`,`S2`,`B1`; PCF uses END/BRANCH1/BRANCH2 |
| `nominal_size` | bore at **this** port — a reducer's two ports differ, which is the point |
| `wall_thickness`, `schedule` | the user's explicit requirement |
| `end_type` | **PCF two-char vocabulary**: BW/SW/FL/SC/PL/PF/CP/GL… |
| `pressure_rating` | 150/300/600# — two FL ports of different class must not be a valid joint |
| `facing`, `gender` | RF/FF/RTJ; MALE/FEMALE for PF and SW |
| `position_x/y/z` | from PCF `END-POINT`; **this is what lets us derive joints** |
| `is_connected` | denormalised for "find all open ends", the single most useful query |

### 5.2b `components` (revised by §10.6)

| Column | Note |
|---|---|
| `piping_line_id` | parent |
| `spool_id` | **nullable** — a line may have no spools at all (§10.5) |
| `component_type` | PIPE / ELBOW / TEE / REDUCER / VALVE / FLANGE / GASKET / BOLT_SET … |
| `mto_item_no` | **the identity the user asked for** — ties the physical component to the MTO, and through it to `material_requisition_items.item_code` |
| `isometric_sheet_no` | plain string; no `isometric_sheets` table (§10.6) |
| `length_mm` | **pipes only** — `CHECK (component_type <> 'PIPE' OR length_mm IS NOT NULL)` |
| `item_code`, `description` | from the BOM / PCF `ITEM-CODE` + `ITEM-DESCRIPTION` |
| `tag_number` | valves and instruments carry one; nullable |
| `external_ref` | Plant 3D `PnPID` / PCF `COMPONENT-IDENTIFIER` |

A conditional CHECK rather than a blanket `NOT NULL`: length is mandatory for a pipe and
meaningless for a valve, and a nullable column with a convention in someone's head is how you
get a register full of zeros.

### 5.3 `joints` — the entity Plant 3D does not have

| Column | Note |
|---|---|
| `joint_seq` | **nullable integer**, per-line scope, shared by welded + threaded (§11.1); partial-unique; displayed zero-padded in the view, never stored padded |
| `numbering_policy` | REQUIRED \| NOT_APPLICABLE — distinguishes "never gets a number" from "not numbered yet" |
| `state` | MODELLED → FITTED (§10.3); QA/QC states deferred at the user's instruction |
| `fitted_by_code`, `fitted_at` | both mandatory on entering FITTED; **code is a string, not a `users` FK** (§10.3) |
| `isometric_sheet_no` | §10.6 |
| `port_a_id`, `port_b_id` | **`CHECK (port_a_id < port_b_id)`** + `UNIQUE(port_a_id, port_b_id)` |
| `joint_type` | BUTT_WELD / SOCKET_WELD / FLANGED / SCREWED / … derived from the two end types |
| `nominal_size`, `schedule`, `thickness` | **derived and frozen** at creation from the ports |
| `shop_field` | PCF `CATEGORY FABRICATION` ⇒ SHOP, `ERECTION` ⇒ FIELD |
| `spool_id` | PCF `SPOOL-IDENTIFIER`, nullable |
| `compatibility_state` | VALID / WARNING / INVALID + `compatibility_notes jsonb` |
| `wps_id`, `welder_id`, `ndt_*` | **deliberately deferred** — the user will specify QA/QC separately |

Three deliberate choices:

- **A joint is not a port property.** Storing `connected_to_port_id` on the port would make the
  relationship exist twice and let the two copies disagree. One row, ordered pair, unique.
- **`port_a_id < port_b_id` is enforced by a CHECK**, so (A,B) and (B,A) cannot both exist. The
  same trick that makes the composite FK in ADR-004 load-bearing: make the corrupt state
  *unrepresentable* rather than merely discouraged.
- **Derived attributes are frozen, not computed on read.** A joint inspected and accepted at
  6"/SCH40 must keep saying 6"/SCH40 even if someone later edits the port. Same reasoning as
  `fullCode()` being computed but certificates being snapshotted.

### 5.4 Compatibility evaluation

`PortCompatibility::evaluate(portA, portB): Verdict` — a pure function, no DB, no locale, with
three outcomes rather than a boolean:

| Verdict | Example | Why not just "invalid" |
|---|---|---|
| **INVALID** | BW to FL; 6" to 8" with no reducer; SW male to SW male | genuinely unbuildable |
| **WARNING** | SCH 40 to SCH 80 at equal bore; 150# flange to 300# flange | legal, common at a spec break, but must be *seen* |
| **VALID** | BW/BW, equal bore, equal schedule | — |

A boolean would force every spec-break transition to be either a lie or a blocker. The EPCC
reality is that the designer is often right and the checker must be able to say "unusual,
confirm" — the same pattern as **advisory inspection** in the procurement module, which the
user explicitly asked for there.

The rule set itself is **data, not code** — per standards pack, overridable per project — for
the same reason the numbering pattern is.

---

## 6. Sizing: is this schema affordable? (measured, not assumed)

A mid-size Iranian refinery unit: ~4,000 lines, ~60 components/line ⇒ **240k components**,
~2.2 ports each ⇒ **~530k ports**, ~250k joints. A large EPCC job is 3–5× that.

This would be the **first table in the project above 10⁵ rows**, and every one of them is under
an RLS policy with a correlated `EXISTS` on `project_company`. I benchmarked it rather than
assume — 2,000,000 rows, real policy, role `epcc`, inside a tenant binding:

| Query | With RLS | Without RLS | Verdict |
|---|---|---|---|
| Indexed fetch of one line's ports | **0.45 ms** | — | fine; the `EXISTS` is hashed **once**, not per row |
| Full-scan aggregate (`GROUP BY end_type` over 2M) | **2,248 ms** | 204 ms | **11× penalty** |

Conclusions:

1. **Normal operation is safe.** Every screen in this design filters by line, subsystem or
   isometric — all indexed, sub-millisecond. The RLS `SubPlan` is hashed once per query.
2. **Never compute a progress roll-up by scanning this table.** Subsystem/line completion must
   be **incrementally maintained** (counter columns updated on joint state change), not
   aggregated live. At 11× the RLS overhead this is the difference between a 200 ms dashboard
   and a 2.2 s one — and `subsystemMaterialStatus()` currently computes live, so this is a
   **new rule, not the existing pattern**.
3. Partitioning is **not** needed at this scale. Revisit past ~10M ports.

---

## 7. Where this collides with what is already built

| Existing thing | Interaction |
|---|---|
| `subsystems` (ADR-004) | parent of `piping_lines`. No schema change needed — vindicates building ADR-004 first |
| **CDE / `documents`** | the iso PDF, P&ID, PMS and line list stay **documents**. `line_documents` links line → document and is how "AFC" is evidenced. **Both models are needed; ADR-005 was wrong to treat this as either/or** |
| **`punch_items`** | already keyed to `subsystem_id`. A punch should be raisable against a **joint** or **line** — needs a nullable polymorphic target later, not now |
| **`mc_certificates`** | MC on a subsystem should eventually require its lines complete. **Do not wire this yet** — it would make every MC test depend on piping fixtures |
| **`material_lots`** (heat no., MTC) | the real prize: a joint's two ports trace to components, components to lots, lots to **heat numbers and MTCs**. Full weld traceability with no new procurement work |
| `WarehouseService::subsystemMaterialStatus()` | today compares ordered vs delivered. With a component model it could compare **required (from BOM)** vs delivered — a much stronger claim |
| RLS tenant policy | copy verbatim, 8 times. §6 says that is affordable |

---

## 8. Options considered

**Option A — components only, no ports, no joints.** A BOM list per line.
*For:* a week's work; covers MTO and progress-by-count.
*Against:* cannot answer "is this connection buildable", cannot produce a weld register, cannot
trace a weld to two heat numbers. It throws away the user's actual requirement (ports, joints,
compatibility) to save effort on the easy half.

**Option B — full port/joint topology, as above.** ⇐ **recommended**
*For:* matches the domain and Plant 3D; makes PCF import lossless; unlocks welding/NDT, spool
tracking, test packs and weld-level traceability as *later, additive* modules.
*Against:* 8 tables; the biggest single schema increment so far; needs the incremental roll-up
discipline from §6.

**Option C — store the PCF blob and parse on demand.**
*For:* trivial import.
*Against:* no editing, no manual entry, no joint numbering, no state. The user explicitly wants
manual entry to work **without** a 3D model. Rejected.

---

## 9. Correction to ADR-005

ADR-005 §"You listed six things" classified **isometric drawing** and **support drawing** as
*documents* and stopped there. That was a **category error born of the wrong question**: I asked
"is this a controlled document?" when the user's question was "where does the *information*
live?" Both are true — the isometric is a revision-controlled document **and** the authoritative
source of component/port/joint data. ADR-005 should be amended to say the document register is
the *carrier*, and this ADR the *content*; PCF is promoted from "best source" to **the primary
ingestion path for topology**, with the Navisworks property export demoted to attribute
enrichment.

I am not editing ADR-005 in place until this ADR is accepted — an ADR records what was believed
when it was written, and superseding is the honest mechanism.

---

## 10. Answers received (2026-09-14) and what they change

All five answered. Three simplified the model; two made it stricter.

### 10.1 Joint numbering is **per line** — and conditional

> «اتصالات جوشی و رزوه‌ای نیاز به شماره دارند و اتصالات فلنجی نیازی به شماره ندارند»

This is the single most structural answer, because it means **`joint_number` is nullable for two
different reasons that must not be confused**:

| Reason | Meaning | Should it ever get a number? |
|---|---|---|
| Joint is **flanged** (or equipment-to-flange) | numbering does not apply | **No** — by rule |
| Joint is welded/threaded but **imported from 3D without a number** | number not yet allocated | **Yes** — pending manual numbering |

A single nullable column cannot tell those apart, and "unnumbered" would silently include both —
so a fitter's worklist of "welds still needing numbers" would be polluted by every flange in the
plant. Therefore `numbering_policy` is stored explicitly (`REQUIRED` | `NOT_APPLICABLE`),
derived from `joint_type` at creation, and the constraint is:

```sql
CREATE UNIQUE INDEX joints_number_unique
  ON joints (project_id, joint_number) WHERE joint_number IS NOT NULL;

ALTER TABLE joints ADD CONSTRAINT joints_number_policy CHECK (
  (numbering_policy = 'NOT_APPLICABLE' AND joint_number IS NULL)
  OR numbering_policy = 'REQUIRED'
);
```

**Verified on this PostgreSQL before proposing** (throwaway table): five legal rows including
two unnumbered flanges and one unnumbered imported weld all inserted; a duplicate number was
rejected with `unique_violation`; a *numbered flange* was rejected with `check_violation`. The
partial index is what allows unlimited NULLs while keeping real numbers unique — there is no
precedent for a partial unique index in this schema yet, hence the spike.

Scope is **per line**, so the counter lives on `piping_lines` and is allocated under
`lockForUpdate()` on the parent line row — the same pattern already used for MR/PO (lock the
`projects` row) and MCC/punch (lock the `subsystems` row).

**Flanged joints still exist as rows.** They are real connections, they need compatibility
checking (150# to 300# is exactly the error worth catching), and bolt/gasket material hangs off
them. They simply carry no number. A later "bolt-up register" can number them on its own scheme
without touching this one.

### 10.2 Import may legitimately produce **unnumbered** components and joints

> «در صورتی که امکان شماره‌گذاری از فایل سه بعدی وجود نداشته باشد، سیستم باید شماره‌ها را خالی گذاشته»

So the import is **not** allowed to invent numbers. Consequences:

- `joint_number` stays NULL and the joint is still a complete, valid row — topology first,
  identity later. This is the opposite of the document register, where a number is allocated at
  creation and never absent.
- The UI needs a first-class **"unnumbered welded joints"** queue, and bulk numbering along the
  line. `numbering_policy = 'REQUIRED' AND joint_number IS NULL` is the exact predicate; it
  wants a partial index of its own.
- **No `NOT NULL` on `joint_number` ever**, and no "auto-number on import" convenience. A number
  invented by software and later contradicted by the fabrication drawing is worse than a blank.

### 10.3 Fit-up is the first construction state on a joint

> «هر کدوم از اتصالات که توسط فیتر Fit-up بشه، وضعیتش به fitted تغییر می‌کنه و کد فیتر و تاریخ ثبت میشه»

A joint now has a lifecycle, so it gets a state machine in `app/Support/` like every other
workflow here (`PunchState`, `ProcurementState`, `CdeState`):

```
MODELLED ──► FITTED ──► (welding / QC states, user will specify later)
    ▲           │
    └───────────┘  un-fit / rework returns it to MODELLED
```

- `MODELLED` — exists in the model, not touched in the field.
- `FITTED` — `fitted_by_code`, `fitted_at` recorded. **Both mandatory on entering the state**,
  enforced in the service, not merely in the form.

**`fitted_by_code` is a string, not an FK to `users`.** Checked: there is no personnel table in
this schema, and `users` holds *office accounts* (`company_id`, `email`, `password`, `locale`,
`is_active`). A pipe fitter does not have a login. Inventing `users` rows for site tradesmen
would put them inside the identity RLS policies and the authorization pipeline, which is wrong
on both security and meaning. A future `site_personnel` register (fitters, welders with their
qualified ranges, NDT operators) is the right home; until then the code is recorded as typed
data with a format check. **This is a deliberate deferral, and the column name says so.**

QA/QC states are explicitly **left blank** at the user's instruction («موارد مربوط به بازرسی و
کنترل کیفی رو هم بعداً میگم»). The state machine is written so states can be appended without
migrating the existing ones — a transition *table*, not a chain of booleans.

### 10.4 Supports: part of the line, separate register

> «ساپورت یکی از اجزای خط است ولی رجیستر متفاوتی داره»

So: **not** a `components` row with fake ports. A support has no flow port, no bore, no end
type; forcing it into the component table would mean every port query needs "…and not a
support", and the compatibility engine would have to special-case it.

Instead `pipe_supports` — its own table, `piping_line_id` parent, its own register and numbering,
carrying `support_type` (shoe/guide/anchor/spring/hanger), `location_ref`, `elevation`,
`support_drawing_document_id` → CDE, and `isometric_sheet_no`. It reuses the same tenant RLS
policy and appears in the line's detail view. **Separate register, same parent** is exactly what
was asked.

### 10.5 Spools are optional groupings, not a level

> «اسپول هم می‌تونه یکی از اجزای خط باشه و شامل چندین کامپوننت و اتصال ولی خط می‌تونه هیچ اسپولی نداشته باشه»

Modelled as `spools` with a **nullable** `spool_id` on both `components` and `joints`. Not an
intermediate level in the hierarchy — a line's components hang off the line whether or not they
are spooled. Same "optional level" reasoning as `areas` in ADR-004, and the same trap avoided:
a mandatory spool would force field-run piping to invent one.

A joint is a **shop weld** when both its components sit in the same spool, and a **field weld**
otherwise. That is derivable, but it will be **stored** (`shop_field`) and frozen, because PCF
already states it (`CATEGORY FABRICATION|ERECTION`) and the two sources must be reconciled at
import rather than silently recomputed later.

### 10.6 No per-sheet BOM; MTO item is the identity; pipes carry length

> «نیازی به ثبت BOM هر شیت نیست… کامپوننت‌ها از MTO منتج می‌شوند و برای هر یک باید شماره item mto ثبت گردد»

Three changes, all simplifications except the last:

1. **`isometric_sheets` is deleted from the design.** The sheet is a printing artefact. Instead
   `components.isometric_sheet_no` and `joints.isometric_sheet_no` — a plain string on each, as
   requested («شماره شیت آیزومتریک هم می‌بایست به کامپوننت‌ها و اتصالات انتساب یابد»). One less
   table, one less join, and it still answers "show me everything on sheet 3 of 5".
2. **`mto_item_no` on every component**, mandatory for manually entered components. This is the
   link the procurement module has been missing: `material_requisition_items` already carries
   `line_no` and `item_code` but nothing ties a requisition line to a *physical component*.
   With `mto_item_no` present on both sides, "what is installed vs what was ordered vs what
   arrived" becomes answerable per component instead of per subsystem.
3. **`length_mm` on pipe components.** Required for `PIPE`, meaningless for a valve — so a CHECK
   conditioned on component type, not a blanket `NOT NULL`. PCF gives it for free as the
   distance between the two `END-POINT` coordinates; manual entry takes it from the iso.

### 10.7 Net effect on the schema

| ADR-006 §5 proposed | After the answers |
|---|---|
| `isometric_sheets` (table) | **removed** — `isometric_sheet_no` string on components and joints |
| `isometrics` | kept, but thinner: the drawing and its revision, pointing at the CDE document |
| `joints.joint_number` | nullable + **partial unique** + `numbering_policy` CHECK |
| — | **new** `JointState` (MODELLED/FITTED) + `fitted_by_code`, `fitted_at` |
| — | **new** `pipe_supports` table (separate register, line parent) |
| — | **new** `spools` table; nullable `spool_id` on components and joints |
| `components` | **+ `mto_item_no`**, **+ `isometric_sheet_no`**, **+ `length_mm`** (CHECK: pipes only) |

Still **eight tables**, counted explicitly: `piping_lines`, `line_documents`, `isometrics`,
`components`, `component_ports`, `joints`, `pipe_supports`, `spools`. (`isometric_sheets`
removed; `pipe_supports` and `spools` added.)

---

## 11. Second round of answers (2026-09-14) — all resolved

### 11.1 Joint number: **simple sequence, shared counter**

> «شماره ساده» + «شمارنده مشترک»

So `joints.joint_number` holds `1, 2, 3…` **as displayed**, scoped per line, with welded and
threaded joints drawing from **one** counter. The line already identifies the context, so
`{line}-J-001` would repeat information the user is already looking at.

Stored as an **integer** (`joint_seq`), not a zero-padded string: padding is presentation, and
storing `"007"` makes sorting and "next number" arithmetic into string surgery. The displayed
form is `str_pad($seq, 3, '0', STR_PAD_LEFT)` in the view layer, exactly as document numbers
already do with `sprintf('%04d')`.

The counter is allocated under `lockForUpdate()` on the **parent `piping_lines` row** — the
established pattern (MR/PO lock `projects`; MCC/punch lock `subsystems`).

### 11.2 Flanged joints: unnumbered for now (my recommendation, accepted)

> «پیشنهاد خودت»

**Leave them unnumbered.** Reasons, in order of weight:

1. **A number nobody uses is a lie that costs.** If flanged joints get numbers now, those
   numbers appear on reports and in exports, people start quoting them, and when the bolt-up
   register arrives with its own scheme we either break their references or maintain two.
2. **They lose nothing.** A flanged joint is still a row, still compatibility-checked (150# to
   300# is exactly the error worth catching), still addressable by its two components, still
   attachable to a punch item.
3. **The data model already distinguishes them** via `numbering_policy = NOT_APPLICABLE`
   (§10.1), so adding a *separate* flange/bolt-up series later is additive: a new column
   `boltup_seq` with its own partial-unique index, no migration of existing numbers.

If you later want them numbered, the honest trigger is the **bolt-up / torque register**
(torque values, gasket type, bolt lot, tightening pass) — that module gives the number a job.

### 11.3 Support numbering: project pattern, sourced from the support documents

> «الگوی پروژه‌ای بر اساس مدارک standard support یا support details و support list و آیزومتریک»

`pipe_supports` therefore gets the **same typed-token numbering machinery** as `piping_lines`
(§4), not a hardcoded format — one engine, two configurations. Tokens available to a support:

| Token | Source |
|---|---|
| `support_type` | the standard support **type code** from the Standard Support document (e.g. `SH`, `GD`, `AN`, `SP`) |
| `standard_detail_ref` | the detail number from **Support Details** |
| `line` | the parent line number, or any of its own tokens |
| `isometric_sheet_no` | the sheet the support appears on |
| `sequence` | counter, scope configurable (per line / per iso / per project) |

And `pipe_supports` carries `support_list_ref` — supports arrive as a **Support List** document,
so the register must round-trip to it. Each of the three source documents is linked as a CDE
document, the same way `line_documents` evidences AFC.

### 11.4 Line number format: the brackets were separators — confirmed

> «بخش‌های مختلف فرمت مثالی شماره خط رو برای این تو کروشه گذاشته بودم که بدونی هر بخش با خط تیره از هم جدا میشه»

Understood, and this is what §4 already encodes: `separator: "-"` with an ordered token list.
The braces in `{size}-{service}-{class}-{subsystem}-{seq}-{coating}` were never meant literally,
and the typed-token design means the separator is configurable per project too — a project using
`/` or no separator at all costs nothing.

### 11.5 Attribute values must be pre-registered, not typed

> «بعضی موارد مثل سایز، piping class، نوع پوشش، از قبل تو سیستم ثبت بشن»

This splits into two kinds, and the distinction drives **ADR-007**:

| Kind | Examples | Varies by | Home |
|---|---|---|---|
| **Universal reference** | pipe sizes (NPS/DN/OD), schedules and wall thicknesses, end types, flange ratings | nothing — true everywhere | **ADR-007**: versioned JSON reference packs, read-only, no RLS, no DB pressure |
| **Project register** | piping classes (from the PMS), services, coating types, insulation types | per project | normal tenant tables, seeded per project, editable by the engineering lead |

So `piping_lines.nominal_size` is a **picklist from the reference pack**, while
`piping_lines.piping_class` is a **FK into a per-project `piping_classes` table**. Both are
"pre-registered" as the user asked; they differ in who owns the list.

This is also what makes line-number **parsing** (§4) possible: every token can be validated
against a known vocabulary, so `8-P-A1-SS2201-001-CT2` can be rejected if `A1` is not a piping
class on this project — the single most useful import check there is.

**→ See `docs/ADR-007-Reference-Data-Engineering-Standards.md`**, which also documents a real
trap found while verifying the data: published sources **disagree** on large-bore walls because
some print STD values under a "SCH 40" heading. STD diverges from SCH 40 at **12" and above**;
XS diverges from SCH 80 at **10" and above**. Taking one source on trust would have produced a
confidently wrong table.

---

## 12. If accepted, build order (revised)

| Phase | Content | Independently useful? |
|---|---|---|
| 1 | `piping_lines` + configurable numbering (compose **and** parse) + manual CRUD + `line_documents` AFC gate | **yes** — a line register with AFC status is valuable alone |
| 2 | `components` (+ `mto_item_no`, sheet no., length) + `component_ports`; "open ends" report | yes |
| 3 | `joints` + `PortCompatibility` (3 verdicts) + per-line conditional numbering + **unnumbered queue** | yes — the checking tool |
| 4 | `JointState` fit-up capture (`fitted_by_code`, `fitted_at`) | **yes — first field-facing value** |
| 5 | `pipe_supports` register; `spools` + shop/field derivation | yes |
| 6 | **PCF import** → staged `upload → preview → commit`; must import *unnumbered* | yes — collapses 2–5 to minutes |
| 7 | Incrementally-maintained roll-ups (§6) into subsystem progress; join to `material_lots` heats via `mto_item_no` | yes |
| 8 | QA/QC and welding states — **awaiting the user's specification** | separate ADR |

**Phase 0 (new, prerequisite): the reference packs of ADR-007.** Phase 1 cannot validate a
`{size}` token without the size vocabulary, so ADR-007 phase 1 lands first. It is small and
self-contained.

Phase 4 moved earlier: fit-up is the first thing that makes this useful **to the people on site**
rather than to the office, and it needs only phases 1–3.

**Nothing is implemented.** The five questions in §11 are narrower than the previous set and
none of them blocks phase 1 — say the word and I will start there while they are settled.
