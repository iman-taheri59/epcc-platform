# ADR-EPCC-004 — Physical breakdown: Plant / Pipeline / Subsystem

**Status:** ACCEPTED — built, migrated and tested on 2026-09-13 (see §6)
**Date:** 2026-09-13
**Trigger:** «هر پروژه می‌تونه چندین plant و چندین خط لوله (pipeline، نه خطوط piping) داشته باشه و هر plant می‌تونه چندین زیرسیستم داشته باشه. این ساختار باید با ساختار نرم‌افزار Plant 3D سازگاری داشته باشه.»

---

## 1. What we have today (verified, not remembered)

`subsystems` hangs directly off `projects`:

```
projects ──1:N── subsystems ──1:N── punch_items
                            ──1:1── mc_certificates        UNIQUE(subsystem_id)
                            ──0:N── purchase_order_items
                            ──0:N── material_requisition_items
                            ──0:N── material_lots
                            ──0:N── material_movements
```

Columns: `id, project_id, company_id, code, name_en, name_fa, discipline`.
Constraint: `UNIQUE(project_id, code)`. One RLS policy: `subsystems_tenant_isolation`.

Live fixture (PH-14, bound as NIOC-AFF — note a raw unbound count returns 0, RLS trap §8):

| code | discipline | name |
|---|---|---|
| PH-14-SS-2201 | MECHANICAL | Cooling water pump set P-2201 A/B |
| PH-14-SS-3301 | ELECTRICAL | Switchgear room SG-3301 |
| PH-14-SS-4401 | CIVIL | Firewater tank foundation TK-4401 |
| PH-14-SS-5501 | INSTRUMENT | Metering skid MS-5501 |
| PH-14-SS-6601 | PIPING | Condensate line 6"-CD-6601 |

**Blast radius:** 30 files, 182 occurrences of `Subsystem`/`subsystem_id`.

There is a **flat middle** in the model: a project of 4,000 subsystems has no intermediate
grouping. That is the gap you are pointing at, and it is real.

---

## 2. How Plant 3D actually structures a project

I checked this rather than assuming, because the answer changes the recommendation.

### 2.1 The Project Manager tree

Plant 3D's top-level tree is **fixed and not extensible**: Project Node → *P&ID Drawings*,
*Plant 3D Drawings*, *Related Files*. You may nest folders **below** those three, and you may
re-path them, but [you cannot create additional top-level folders](https://forums.autodesk.com/t5/autocad-plant-3d-forum/customize-the-project-folder-structure/td-p/11216763) — and Plant 3D
will not recognise any folder not shown in Project Manager. These are **virtual folders**
defined in the project `.xml`.

### 2.2 The recommended breakdown is AREA

Autodesk's own guidance for large projects is explicit: look at the site plan and
[divide it into areas](https://help.autodesk.com/cloudhelp/2023/ENU/Plant3D-Managing-Large-Projects/files/GUID-0C3ECAB4-7F69-47F5-866C-CE0B95D86978.htm) — "each area may be a process unit, or it may be a logical
sub-division of a process unit". Their worked refinery example has **14 areas**, each
subdivided by discipline (Equipment / Structures / Piping), with the pipe rack as its own
area. Critically: *"the fact that the P&ID area will span several of the Piping areas is not
important"* — **4 P&ID areas vs 13 piping areas in the same project.**

### 2.3 Area and Unit are *properties*, not a hierarchy table

This is the key finding. In Plant 3D, `Area`, `Unit`, `Service`, `LocationNumber` are
**tag subparts** — configurable properties on classes, drawings and the project, assembled
into tag formats. The default equipment tag 2 is `Area-Type-Number` → `25-P-1000`;
instrumentation is `Area-Type-Loop` → `51-PT-100`. Properties can be **acquired** from a
project-level property, so changing Unit at project level updates every tag that uses it.

Data lives in a **SQLite `.dcf` PnPDatabase** (or SQL Server for larger teams), in tables like
`PnPDrawings`, `PnPDataLinks`, `PnPLineGroups`, keyed by auto-numbered `PnPID`.

So Plant 3D's "hierarchy" is: **a physical folder tree for drawings + a flat object database
where grouping is expressed as string properties on objects.** There is no `plants` table,
no `areas` table, no foreign key from a line to an area.

### 2.4 Pipe Line Group — the one real grouping object

[Pipes are the only components that are automatically grouped.](https://help.autodesk.com/cloudhelp/2018/ENU/Plant3D-UserGuide/files/GUID-EE5C48C9-9F4E-4ED6-BD77-079587E1C8CD.htm)
A **Pipe Line Group** is a non-physical container whose tag **must be unique project-wide**,
while the line *segments* inside it may repeat. The seasoned advice from practitioners is
that the line group tag should be **just the line number, or service+number** — *not*
area-size-service-spec, because size and spec belong to segments, not to the group.

### 2.5 What Plant 3D does **not** have

- No **System / Subsystem** concept. It is a design authoring tool; commissioning systems
  are a completions concept and live in tools like WinPCS, Completions, GoCompletions.
- No **MC / punch / handover** state on any object.
- No **pipeline routing / stationing / KP** model. Cross-country pipelines are organised by
  **kilometre point (KP)** and construction **spreads** — a linear referencing system with
  no equivalent in Plant 3D's area model.

---

## 3. The analysis

### 3.1 Your instinct is right, but "compatible with Plant 3D" cannot mean "copy it"

Plant 3D is a **design authoring** tool. We are a **project controls / completions** platform.
Adopting its structure literally would mean storing Area and Unit as *strings on each object*
and having no hierarchy table at all. That would be a step backwards for us: we need to hang
an MC certificate, a punch register and a material shortage on a node, and you cannot put a
foreign key on a string.

What compatibility must actually mean is **lossless interchange**:

> Every Plant 3D object must land in exactly one node of our tree, and our node codes must be
> reconstructible from Plant 3D tag subparts — without a human mapping spreadsheet.

That is achievable, and it is a much stronger requirement than visual similarity.

### 3.2 Plant and Pipeline are genuinely different things — do not force one table

This is the part I'd push back on if we modelled naively. A **plant** is bounded by area
coordinates; a **pipeline** is bounded by *stationing* — KP 0+000 to KP 147+500, divided into
spreads, with block valve stations at intervals. A pipeline's "area" is a kilometre range.

If we make `pipelines` a row in a generic `facilities` table with an `area` string, then the
first time someone asks "which welds are between KP 12 and KP 18" we cannot answer without a
migration. Conversely if we give plants a `from_kp`/`to_kp` we carry two dead columns on every
refinery unit.

**But** they share something real: both are *commissioning parents*. Both get subsystems, both
get MC certificates, both get material allocated. The shared part is the **breakdown node**;
the differing part is the **spatial addressing**.

### 3.3 Three options

#### Option A — Add `plants` + `pipelines` as two sibling tables

```
projects ──1:N── plants    ──1:N── subsystems
         ──1:N── pipelines ──1:N── subsystems
```
`subsystems` gets `plant_id NULL` + `pipeline_id NULL` + a CHECK that exactly one is set.

*For:* explicit, each table carries only its own columns.
*Against:* every query that walks upward becomes a two-branch `COALESCE`. `subsystemMaterialStatus`,
the MC gate, the punch register, RLS policies and the Filament tables all double their join logic.
The XOR CHECK is the classic smell of a missing abstraction.

#### Option B — One polymorphic `facility` with a `kind` discriminator ✅ recommended

```
projects ──1:N── facilities ──1:N── areas(opt) ──1:N── subsystems
                   kind: PLANT | PIPELINE | OFFSITE | MARINE | UTILITY
```

One table, one FK from `subsystems`, `kind` drives which optional columns are meaningful and
which validation rules apply (a PIPELINE requires `from_kp`/`to_kp`; a PLANT requires neither).
Spatial addressing goes in a **`jsonb addressing`** column validated per kind — the same
pattern already proven by `punch_summary` and `exception_items`.

This mirrors a decision we have already made and validated twice: `McCertificate` uses a
`state` column rather than separate tables, and the movement ledger uses `MovementType` rather
than four document tables. **One table + a typed discriminator + behaviour in a value object**
is this codebase's established answer to "similar but not identical things".

*For:* one join everywhere; `subsystem_id` stays exactly as it is, so **the 182 existing
occurrences do not move**; new kinds (marine terminal, tank farm, offsite) cost a constant,
not a migration; RLS stays one policy.
*Against:* `kind`-conditional validation must be enforced in a value object or it rots.

#### Option C — Generic self-referencing `breakdown_nodes` tree

Infinitely flexible, and wrong for us. An arbitrary-depth tree means no query can assume a
level, recursive CTEs everywhere, and the UI cannot render a stable breadcrumb. AWP needs
*named* levels (CWA → CWP → IWP), and a generic tree cannot express "a CWP must live in a CWA".

### 3.4 The Area level — and why it should be optional

Plant 3D's primary unit is the **Area**, and its own example has 13 of them under one site.
If we go `plant → subsystem` directly we lose the level Plant 3D actually organises by, and
the import in §3.1 stops being lossless.

But an Iranian gas booster station with 12 subsystems does not want to invent 12 areas.
So: **`areas` exists, `subsystems.area_id` is NULLable**, and a subsystem may attach directly
to its facility. Same principle as `closurePercent()` returning 100 for an empty punch list —
the absence of structure must not be reported as an error.

This also solves the P&ID-vs-piping mismatch: Plant 3D explicitly tolerates 4 P&ID areas over
13 piping areas. Because our `areas` are *commissioning/spatial* nodes and the drawing's area
is an imported **string property**, the two can disagree without either being wrong.

### 3.5 The AWP question — this is where it pays off

`awp.pack.json` already references `cwa`, `cwp`, `ewp`, `iwp`. CWA = **Construction Work Area**,
and today we have **nowhere to put it**. A CWA is precisely an area-level node. Option B with
the optional `areas` level gives AWP a home without a second migration:

| our node | ISO / conventional | AWP |
|---|---|---|
| `facility` (PLANT) | Plant / Site | — |
| `facility` (PIPELINE) | Pipeline | — |
| `area` | Area / Unit | **CWA** |
| `subsystem` | Subsystem | (work packages reference it) |

The standards pack stays the thing that names the levels per project — consistent with the
standing rule that the framework is **selectable, not hardcoded**.

### 3.6 What the code change actually costs

The good news: **`subsystem_id` does not move.** Punch, MC certificates, procurement and all
five material tables keep pointing at subsystems exactly as now. What changes:

| area | change | risk |
|---|---|---|
| migration | 2 new tables, 2 nullable FKs on `subsystems`, RLS policies on both | low — same shape as existing |
| `Subsystem` model | `facility()`, `area()`, `fullCode()` | low |
| new value object | `FacilityKind` (+ per-kind required addressing) | low, pure |
| seeder | PH-14 gets 1 plant + 1 pipeline; 5 subsystems distributed | **fixture must be uneven** — a pipeline subsystem *and* a facility with no areas, or the new rules are untestable (lesson from M96/M99) |
| Navigation / screens | breakdown selector; facility column on registers | medium — shell contract |
| Filament | Subsystem table gains facility/area columns + filters | low |
| reports | `subsystemMaterialStatus` gains optional roll-up to facility | medium |
| RLS | 2 new policies, same participation clause | **must be tested from 4 sides**, per §5e |

Estimated ~12–15 files new/changed, ~25–35 new tests, and I would expect **4–6 new mutations**
(XOR of kind, area optionality, roll-up double-counting, cross-facility subsystem leakage).

### 3.7 One trap worth naming now

`UNIQUE(project_id, code)` on subsystems. Once facilities exist, two plants in one project will
legitimately both want `SS-2201`. Options: keep project-wide uniqueness (simple, but rejects a
real-world naming scheme), or move to `UNIQUE(facility_id, code)` and make the *displayed* code
`{PLANT}-{SS}`. I lean to the latter, but it is a **user decision about your numbering
convention**, not a technical one — and it changes `fullCode()` everywhere it is printed,
including on issued PDFs.

---

## 4. Recommendation

**Option B**: `facilities` (kind = PLANT | PIPELINE | …) → optional `areas` → `subsystems`,
with per-kind addressing in a validated `jsonb` column and a `FacilityKind` value object.

Compatibility with Plant 3D delivered as a **documented import mapping**, not as a copied
schema:

| Plant 3D | ours |
|---|---|
| Project | `projects` |
| Project Manager folder / drawing `Area` property | `areas.code` |
| `Unit` tag subpart | `areas.unit_code` |
| Pipe Line Group tag (unique project-wide) | line register, future — maps to `subsystems` via Service/Area |
| `PnPID` | kept as `external_ref` for round-tripping |

The gap Plant 3D leaves — System/Subsystem, MC, punch, handover — is exactly the gap this
platform exists to fill. We should be *readable from* Plant 3D, not shaped like it.

---

## 5. Questions asked, and how they were answered

1. **Subsystem numbering — project-wide or per plant?** → **Per facility.**
   `UNIQUE(project_id, code)` was dropped in favour of `UNIQUE(facility_id, code)`, so
   `PL-01/SS-01` and `PL-02/SS-01` are two legal, distinct subsystems. Issued certificates are
   unaffected because they snapshot their identifiers rather than joining live.
2. **Do pipelines need their own intermediate level?** → **Deferred, not refused.**
   `PIPELINE` exists in `FacilityKind::all()` (so the CHECK accepts it and data can be imported)
   but is excluded from `selectable()`, so no one can create one through the UI until stationing
   is designed. `requiresStationing()` already returns true only for that kind.
3. **Is `area` a level you actually use?** → **Optional, and the fixture proves both shapes.**
   `subsystems.area_id` is nullable, and `PH-14-PL-02` deliberately has no areas at all.
4. **KP addressing now or later?** → **Later.** The `addressing` jsonb column is in place and
   validated per kind, so adding `from_kp`/`to_kp` is data, not a migration.

---

## 6. What was actually built

| Object | Shape |
|---|---|
| `facilities` | `id, project_id, company_id, code, name_en/fa, kind, addressing jsonb DEFAULT '{}', external_ref` · `UNIQUE(project_id, code)` · `INDEX(project_id, kind)` · CHECK on `kind` driven by `FacilityKind::all()` |
| `areas` | tenant columns + `facility_id, code, name_en/fa, unit_code` (nullable — Plant 3D keeps Area and Unit as separate tag subparts) + `external_ref` · `UNIQUE(facility_id, code)` |
| `subsystems` | gained `facility_id` **NOT NULL** (cascade) and `area_id` **nullable** (`nullOnDelete`); unique key swapped to `UNIQUE(facility_id, code)` |
| integrity | composite FK `subsystems_area_within_facility (area_id, facility_id) → areas (id, facility_id) ON DELETE SET NULL`, backed by `CREATE UNIQUE INDEX areas_id_facility_id_unique` |
| RLS | both new tables copy `subsystems_tenant_isolation` verbatim: read = any project participant, write = the owning company |
| `FacilityKind` | PLANT / PIPELINE / OFFSITE / MARINE / UTILITY · `all()` drives the CHECK · `selectable()` excludes PIPELINE · `requiresStationing()` · bilingual `label()` and `areaLabel()` (Area / Section / Berth) |
| `Subsystem::fullCode()` | `"{facility->code} / {code}"`, **not** a stored column — a composed code in a column drifts the moment a facility is renamed |
| admin UI | the subsystem register shows Facility (non-toggleable, because the bare code is now ambiguous) and Area (toggleable, em dash when deliberately absent) |

### The composite FK is the load-bearing part

Two independent FKs would let a subsystem point at facility *A* while pointing at an area that
belongs to facility *B* — silent cross-facility corruption that every roll-up would then report
confidently and wrongly. The composite FK makes that state **unrepresentable** at the storage
layer rather than merely discouraged by application code.

### Migration trap worth remembering

The backfill initially wrote nothing and the migration then failed with
`23502 … facility_id contains null values`. Cause: `subsystems` is under **`FORCE ROW LEVEL
SECURITY`** and a migration runs with no `epcc.company_id` GUC set, so the backfill `SELECT`
matched zero rows — silently, because RLS filters rather than errors. Fix: `DISABLE ROW LEVEL
SECURITY` around the backfill, then restore **both** `ENABLE` *and* `FORCE` (`ENABLE` alone
leaves the table owner exempt). Safe only because PostgreSQL DDL is transactional.

### Verification

- Full suite **482 passed / 1647 assertions** after the schema change — backward compatible.
- New `tests/Feature/BreakdownTest.php` — **25 passed / 69 assertions**.
- Mutation-tested: M118 composite FK dropped, M119 old project-wide unique restored, M120 RLS
  left disabled after the backfill, M121 area delete cascades instead of nulling, M122 PIPELINE
  made selectable, M123 `fullCode()` degraded to the bare code — **all six caught.**
- M118 initially *survived*. The cause was the mutation, not the test: dropping the constraint
  with `psql` is pointless when `setUp()` runs `migrate:fresh`, which rebuilds it. Mutating the
  **migration source** caught it. Recorded because the false "SURVIVED" nearly led to rewriting
  a test that was already correct.

### Deliberately not built

Pipeline KP stationing and spread-based breakdown; bulk subsystem import; roll-up of
`subsystemMaterialStatus()` to facility/area level. ADR-005 (engineering data ingestion) depends
on this schema and remains PROPOSED.

---

## 7. Amendment (2026-10-31, commit `17c683b2`) — the SYSTEM level

**Status: ACCEPTED.** Appended, never edited: the decision above stands, and this
adds the level it left unmodelled.

### What was missing

§1 of this ADR named the problem itself: *"There is a flat middle in the model: a
project of 4,000 subsystems has no intermediate level."* §4 answered it with
`areas` — a physical level — and, alongside it, `areas.unit_code`, a nullable
string described as "one process unit". That is a level the platform knew about
and did not model, and it left two different questions sharing one answer:

- **Where is it?** — physical, answered by facility and (optionally) area;
- **What does it do?** — functional, and it crosses physical boundaries: the
  firefighting pumps sit in the process plant and the tank foundation in the
  utilities block, and the client who asks for the firefighting handover dossier
  is asking a question no area can answer.

### The amendment

```
project ──1:N── systems ──1:N── subsystems           (functional: what it does)
project ──1:N── facilities ──1:N── areas(opt) ──1:N── subsystems
                                                     (physical: where it is)
```

Two parallel trees over the same subsystems, because they are two questions.

- **`systems`** — `project_id`, `company_id` (tenant), `code`, bilingual names.
  Code unique per **project**, not per facility: a system is allowed to span
  facilities. RLS + `FORCE ROW LEVEL SECURITY` with the identical clause used by
  `subsystems`, `facilities` and `areas` — readable by every participant,
  writable by the accountable company. The breakdown is the map the whole
  project works from.
- **`subsystems.system_id` — NOT NULL, FK `ON DELETE RESTRICT`.** A subsystem
  that names no system is a subsystem nobody can commission. The platform is
  greenfield and no project has been run on it, so the cost of insisting is
  payable now; the cost of not insisting grows with every subsystem anybody
  creates. `RESTRICT` rather than cascade: deleting a system would silently
  orphan every subsystem's functional parent.
- **`areas.unit_code` is DROPPED**, not deprecated. The system is the real thing
  now, and a second column answering the same question is the drift this
  amendment exists to remove.
- **Systems hang off the project, not off a facility** — deliberately, and for
  the reason §3 gave against the two-parent option: a level whose parent is
  `COALESCE(facility, pipeline)` is a level every upward query will eventually
  answer wrong.

### What did NOT change

Subsystem numbering (unique per facility), MC certificates, punch lists,
material status and custody stay exactly as they were: they are all keyed on the
subsystem, which is still the unit that gets mechanically completed. The
functional tree adds a grouping over the same rows; it does not move them.

### Deliberately not built

- **`AREA` and `FACILITY` dossier scopes.** The ITR compiles for
  `TEST_PACK`, `SUBSYSTEM`, `SYSTEM` and `PROJECT`. The two physical levels are
  the obvious next rung and are cheap to add — same resolver, one more branch —
  but they are not what this amendment was about, and adding them untested would
  be scope taken, not scope decided.
- **A system hierarchy** (system → sub-system → sub-sub-system). No project has
  asked for one; two levels answer every question a handover dossier asks today.

---

## 8. Amendment (2026-10-31) — Prior art: what comparable software and standards actually do

**Status: INFORMATIONAL.** This section does not change the decision; it records
the survey that was run *after* §7 was implemented, to check whether the
two-parallel-trees shape is an idiosyncrasy of ours or the industry norm. It is
the norm. Every source below was read this day; the URLs are the ones actually
consulted.

### 8.1 Completions tools keep two breakdowns and join them at the tag

- **Hexagon / Intergraph Smart Completions (now Octave OnSite Completions).**
  Step 1 of the vendor's own implementation process is *"Import Engineering
  Data, Define Work Breakdown Structure (WBS), Systemization, Location
  Structures"*. The product page states that **Process Breakdown Structure
  (PBS), "also known as systemization", and Location Breakdown Structure (LBS)**
  exist "to allocate tags, work, certification and handover, which is key to
  review the overlap between construction and commissioning turnover
  processes". Two structures, one tag register, neither the parent of the
  other. — [hexagon.com product page](https://hexagon.com/products/hxgn-smart-build-field-handover),
  [Hexagon Smart Completions brochure](https://catalogartifact.azureedge.net/publicartifacts/hexagonppm.smart_completions_smart_cloud-d28768ce-d35f-478c-9b8a-c1150f9444b1/Artifacts/Documents/Hexagon_PPM_Smart_Completions_Brochure_US.pdf)
- **WinPCS / GoCompletions.** The object hierarchy is functional and
  cross-discipline — *"system > sub-system > motor > cable > panel"* — while the
  reporting axes are siblings: *"Select from the Main Menu the **System, Unit or
  Area** on which a status report is required."* Unit and Area are alternative
  lenses over the same rows, not levels above System. — [winpcs.com](http://www.winpcs.com/commissioning.htm)
- **GoTechnology hub2.** At the Scope level the "Extended Logical Structure"
  carries **System, Subsystem, Area, Module, Discipline** as attributes of the
  scope item, and certification can be grouped by *System, Subsystem, Primary
  Handover, Secondary Handover, System/Discipline, Subsystem/Discipline, Area,
  Module*. Grouping is chosen per report, not frozen into one tree. — [hub2 User Guide](https://gotechnology.github.io/u/UserGuide.pdf)
- **Falcon Global, "Systemization Guidelines"** (a working CSU method statement,
  not a brochure). Systemization is *"the definition of the physically smallest
  part of each of the facility's Process or Utility Systems, divided into Sub
  Systems, so that each Sub System will function as an Operable Entity"*; the
  boundaries are marked up on **P&IDs and SLDs** as battery limits; systems are
  acknowledged to be *"spread across large areas in the Plant"*; and the entire
  exercise exists to manage *"their transition from an **Areas** approach to a
  **Systems** approach"* (PSCM — Progressive Systems Completion Methodology). A
  system is defined as the thing that is **not** the area. — [Falcon Systemization Guidelines, 2019](https://falconglobal.net/wp-content/uploads/2019/03/Systemization-Guidelines-20190221.pdf)

### 8.2 The standards say the same thing, and more formally

- **IEC/ISO 81346-1.** The structuring principles are three orthogonal aspects —
  **function-oriented, product-oriented, location-oriented** — with the prefix
  signs `=` function, `+` location, `-` product. Its own figures include *"an
  object accessible from three aspects"*. A designation such as
  `=H10+UCB01-CF001` names one object seen through three lenses. The stated
  reason: *"Function, location, and product change on different clocks… A flat
  tag that fuses all three forces a rename every time any one of them shifts."*
  Our two trees are two of these three aspects; **equipment/tag is the third and
  is already modelled elsewhere.** — [IEC 81346-1:2009 abstract and contents](https://standards.iteh.ai/catalog/standards/iec/120d35da-ea1e-4ced-b394-05f0ca036f70/iec-81346-1-2009),
  [practical summary with the aspect table](https://tagsight.io/learn/what-is-iec-81346)
- **NORSOK Z-007 (Mechanical completion and commissioning).** It defines the
  software category we are building: a *"Project Completion System (PCS) is a
  computerised system for electronic documentation of Mechanical completion,
  commissioning and preservation **by system, subsystem, area, commissioning
  package, MC package and/or individual tag**"* — system, subsystem and area
  enumerated as **alternative** documentation axes. And *"System: An
  operational system **or a non-operational system**… Non-operational systems
  are systems such as surface protection, insulation, civil, structural steel,
  markings and signs, cable and tubing trays"* — categories that by definition
  cannot belong to a single process unit. — [NORSOK Z-007 Rev. 2 text](https://dokumen.tips/documents/norsok-mechanical-completion-and-commissioning-z-007.html)
- **API RP 1FSC, *Recommended Practice for Facilities Systems Completion
  Planning and Execution*, 1st ed.** The four phases our certificates track
  (Mechanical Completion → Pre-Commissioning → Commissioning → Performance
  Testing/Start-Up) are the ones it defines, and *systemization* in its sense is
  the act of producing the priority start-up sequence of subsystems. — [title and phase model](https://falconglobal.net/wp-content/uploads/2021/11/Falcon-Group-Zenator-Systems-20211122.pdf),
  [course description](https://petroknowledge.com/courses/commissioning-start-up-with-api-rp-1fsc-foundation)
- **CFIHOS / ISO 15926 lineage.** The DEXPI asset-lifecycle information
  management draft records the position directly: *"DEXPI proposes the use of
  **different functional structures** to identify units in the process model and
  tags/functional locations in the plant model."* Location is carried on the tag
  as data, not as the tag's parent. — [DEXPI ALC information management, 2026-02](https://dexpi.org/wp-content/uploads/2026/02/ALC-information-management_V4_for-publication_20260211.pdf)
- **Advanced Work Packaging (CII).** The clearest statement of all, because AWP
  is the *construction* methodology and still refuses to collapse the two:
  *"Two maps, kept live. The Contractor shall maintain, throughout the Project,
  a live cross-reference between (i) the area map (CWA → CWP → IWP) and (ii) the
  system map (System → Subsystem → CxWP)"*, and the commissioning package
  *"is organized by system and subsystem, **not by area**, and breaks the
  location-pattern of the first four packages on purpose."* CII's own material
  lists the shift to systems as the **future state** of the discipline:
  *"Future state: timely mapping to System Work Packages (SWPs)… IWPs mapped to
  system and tag level."* — [AWP spec, §2.05 and §3.01](https://taktproductioninstitute.com/advanced-work-packaging/),
  [CII, AWP and Lean Construction](https://www.construction-institute.org/api/resource/download/7430)

### 8.3 The design/authoring tools do not nest system under location either

- **Plant 3D** — as §2 established: Area/Unit/Service/LocationNumber are **tag
  subparts**, not a hierarchy table.
- **AVEVA E3D / PDMS.** The administrative hierarchy WORLD → SITE → ZONE is
  explicitly *not* a physical one: *"A ZONE element is not necessarily used to
  define a physical area, it is more likely to store similar types of item for
  easy reference, such as a piping system in one ZONE element."* The functional
  grouping is a **separate element type**: *"You may define as a GROUP any
  collection of elements in the database, assembling these elements as a single
  GROUP unit."* A location tree plus an arbitrary group = the same two-aspect
  split, implemented thirty years ago. — [AVEVA E3D 2.1 help, database element types](http://help.aveva.com/AVEVA_Everything3D/2.1/GS/GS4.4.2.html),
  [PDMS Design Reference Manual, GROUP element](https://pdfcoffee.com/pdms-design-3-pdf-free.html)
- **SAP PM.** A functional location may be structured by function *or* by space,
  and SAP ships a mechanism for holding **both at once**: *alternative
  labelling*, where the same functional location carries a second label under a
  different structure indicator, chosen by user profile. One object, two
  hierarchies, neither of them the master. (SAP's own warning: it is off by
  default and must be performance-tested before use.) — [SAP learning, Configuring and Creating Functional Locations](https://learning.sap.com/courses/configuring-organizational-and-master-data-in-sap-s-4hana-service/configuring-and-creating-functional-locations),
  [alternative labelling in practice](https://www.erpgreat.com/plant/sap-pm-questions-answers-on-functional-locations.htm)

### 8.4 What the survey settles

1. **Systems at project level, code unique per project — confirmed.** Not one of
   the nine sources binds *System* under *Unit*. The closest any of them comes
   is SAP, and SAP solves it with a second label over the same object, which is
   strictly weaker than what we have (a real row in a real functional tree).
2. **Two parallel trees joined at the subsystem — confirmed**, and it is what
   AWP calls "two maps kept live". Our join is *stronger* than the industry
   norm: AWP's cross-reference is a maintained document; ours is a foreign key,
   so "which systems have subsystems inside area X?" is a join, not an audit.
3. **No `facility_id` on `systems` — confirmed.** This is the finding that
   changes a mind: the middle way offered before this survey (an optional unit
   column) is **weaker than it first looked**. Once systems can carry a unit,
   the first question every consumer asks becomes "which systems are in this
   unit?", and cross-unit systems — firefighting, instrument air, flare, ESD —
   silently drop out of the answer. That is precisely the population the level
   exists to express. SAP's alternative labels avoid this by making the second
   hierarchy a *view of the same object*, never a filter on it.
4. **Where the unit then belongs: in the code, as an attribute, on the object —
   never as a column on the level.** `SY-PL01-FIRE` is the Plant 3D answer (Area
   is a tag subpart), the CFIHOS/DEXPI answer (Location is data on the tag) and
   the IEC 81346 answer (`+PL01` is a location block inside a tag, not a level).
5. **The one gap the survey exposed — now closed.** AWP's "two maps kept live"
   has a *construction-side* reader: the area-based user must be able to find
   their work through the system map. In our schema that was derivable
   (`systems ← subsystems → areas → facilities`) but not *offered*, and
   derivable-but-unoffered is exactly the state AWP describes as the current
   (deficient) state of the discipline. ADR-048 slice 3d closed it the same day:
   `AREA` and `FACILITY` are dossier scopes through the same resolver, an area
   dossier names the `system_codes` that reach into it, and the screen and the
   printed document both print the cross-reference in both directions.

### 8.5 Two things the survey suggests we may eventually want

Recorded, not decided, and deliberately not built:

- **A start-up priority on the system/subsystem.** In both the Falcon guidelines
  and API RP 1FSC, the *output* of systemization is not the tree — it is the
  **priority sequence** (P1 power and safety, P2 control, P3 utilities,
  P4 storage/handling, P5 process, P6 metering and export, in the Falcon
  example). A nullable smallint on `systems` (and possibly on `subsystems`)
  would carry it. Cheap to add, expensive to retro-fit once sequencing reports
  are written against its absence.
- **Non-operational systems.** NORSOK Z-007's list — insulation, surface
  protection, civil, structural steel, markings and signs, cable and tubing
  trays — are legitimate "systems" for MC purposes and are, if anything, *more*
  cross-unit than the operational ones. Nothing in the `systems` table prevents
  them; the naming convention and any future roll-up must simply not assume that
  every system is a process or utility function.

---

## 9. Amendment (2026-10-31) — the start-up priority on a system

**Status: ACCEPTED.** Appended, never edited: §7 stands, and this adds the one
property the tree did not carry.

### What was missing

§7 gave the project a functional tree. A tree says what belongs to what; it does
not say what must come *first*, and in this discipline those are different
deliverables. The prior-art survey in §8 found the distinction stated plainly:
in **API RP 1FSC** (*Recommended Practice for Facilities Systems Completion
Planning and Execution*) and in the systemization method, the output of the
exercise is not the breakdown — it is **the priority sequence in which the
subsystems and systems must be live** for the plant to reach first oil. The
Falcon guidelines name the bands their projects use: **P1** power and safety,
**P2** control, **P3** utilities, **P4** storage and handling, **P5** process,
**P6** metering and export. Two systems sitting side by side in the tree are not
equal in the schedule, and a schema that can say "these two are both systems"
but not "this one must be live before that one" is missing the output of the
exercise it exists to support.

### The amendment

```
systems.startup_priority   smallint   NULL   CHECK (startup_priority IS NULL OR startup_priority > 0)
```

- **Nullable, and that is a state rather than an omission.** A system with no
  priority is one the commissioning manager has not placed yet. The alternative
  — defaulting to zero, or to some "unassigned" sentinel — would sort unplaced
  systems ahead of every system a project actually sequenced, which is a quieter
  way of lying than admitting the question is open. Unsequenced systems sort
  last and are still named.
- **Positive only.** The CHECK refuses `0` and negatives for the same reason.
- **An integer, never a band label.** The platform stores `1`, not `'P1'`,
  because what P1 *means* is the project's convention and it is not the same
  convention everywhere: some projects run P1–P6, others P10–P90, and some
  sequence by a number that is not a band at all. Storing a number also means
  two systems can share a band without ambiguity — the fixture has two at P1 and
  two at P3, which is the ordinary case, not an edge case.

### Why it is not a field that gates nothing

This chapter's standing objection to a new column is that it may record
something no behaviour reads. The priority is read in three places, all of them
consequential:

1. **It is carried on the frozen SYSTEM dossier** (`scope.startup_priority`,
   `schema_version` 5 → 6), for the same reason a subsystem dossier carries its
   discipline: a handover document that does not say where this system stands in
   the start-up order leaves operations to guess at it.
2. **The cross-reference is sequenced.** An `AREA` or `FACILITY` dossier lists
   the `system_codes` that touch it ordered by start-up priority, not
   alphabetically. That turns §8.4's AWP cross-reference into an answer to the
   question the area-side reader actually asks — *what must be live first on my
   patch?* — instead of a list they have to re-sort themselves. The fixture's
   process area makes the difference visible: it holds cooling water (P3) and
   firefighting (P1), so the sequenced list begins with firefighting and the
   alphabetical one does not.
3. **The picker lists systems in start-up order**, so the sequence is the reading
   order on the screen, and each option shows its band (`PH-14-SY-4400 · P1`).

### Deliberately not built

- **A per-subsystem priority.** Both API RP 1FSC and the Falcon guidelines do
  assign one — in the workshops, each *subsystem* gets its place in the
  sequence. But a column with no commissioning-planning surface behind it is
  exactly the "field that gates nothing" this section says no to: nothing today
  would read it, and a number on a subsystem that no screen shows and no
  document carries is a promise the platform cannot keep. It belongs to the
  planning surface, when that exists.
- **A register of who sequenced what, and when.** Systemization is explicitly
  iterative — *"definitely not a one-time event"* — so the history will matter
  eventually. It is not here, because there is no surface to show it and no
  caller to refuse it.
