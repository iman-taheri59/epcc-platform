# ADR-EPCC-009 — Manual modelling, component templates, and the PCF-vs-Plant 3D question

> **Status — 2026-10-05:** Partially built. The template-driven component/port modelling path is implemented; PCF import remains pending and should come after PMS validation so imports do not bypass engineering rules.


**Status:** PROPOSED — analysis only, nothing implemented
**Date:** 2026-09-14
**Depends on:** ADR-006 (piping model), ADR-007 (reference data), ADR-008 (PMS class rules)

**Triggers (user, 2026-09-14):**
1. «اگر داده‌ها به جای فایل ۳ بعدی، به صورت دستی وارد سیستم بشه… کاربر خط رو انتخاب کنه و بخواد
   یک Tee و یک لوله متصل به اون رو مدل کنه، این رو چطور پیاده‌سازی می‌کنی؟ چطور سیستم تشخیص بده
   که لوله دو port و Tee سه port داره؟»
2. «می‌خوای از مدل PCF استفاده کنی یا Plant 3D؟»
3. NPS storage: string **and** decimal **and** DN **and** mm — «بعداً برای محاسبه دیا اینچ
   جوش‌های تأیید شده استفاده خواهیم کرد».
4. Corrections and answers to ADR-008 §7 (all four).

---

## 1. NPS storage — the four-column answer, and why the decimal column is load-bearing

The user specified it precisely: `2 1/2` as a string, `2.5` as a decimal, plus DN and mm.
This is right, and the reason is worth recording because a future developer will be tempted to
"simplify" it to one column.

**Tested, not assumed:**

```
string-only attempt:  sum(float(n) for n in ["2 1/2","6",...])  →  ValueError
with numeric column:  22.25 dia-inch                             →  works

sort by string :  ['1 1/4', '1/2', '12', '2 1/2', '6']     ← 12" before 2½"
sort by numeric:  ['1/2', '1 1/4', '2 1/2', '6', '12']     ← correct
```

So the decimal column is not redundancy, it is the only thing that makes **dia-inch** possible —
the user's stated purpose. Dia-inch of accepted welds is
`Σ (nps_decimal × weld_count)`, and it is the standard productivity and progress measure for
piping fabrication. A string column cannot be summed; a string sort puts 12" before 2½".

| Column | Type | Purpose |
|---|---|---|
| `nps_label` | text | `"2 1/2"` — what the engineer reads and what appears in the line number |
| `nps_decimal` | numeric(6,3) | `2.500` — arithmetic: **dia-inch**, sorting, size comparison, range lookup |
| `dn` | integer | `65` — the key for ADR-008's `int4range` band logic |
| `od_mm` | numeric(8,2) | `73.03` — physical, from ASME B36.10M |

All four ship in the ADR-007 reference pack, so they are entered **once, by us**, never by a
user. A parser accepts `2 1/2`, `2-1/2`, `2½`, `۲ ۱/۲` and resolves to the canonical row —
`fold()` already normalises Persian digits for the search layer and the same normalisation
applies here.

**Note on dia-inch:** it will be computed from the **joint's** size, not the component's, because
a 6"×2" reducing weld is 2" dia at the small end by most conventions. The exact convention is an
open question for the welding ADR; storing `nps_decimal` on the port makes any convention
computable.

---

## 2. The manual-modelling question — templates, not typing

> «چطور سیستم تشخیص بده که لوله دو port و Tee سه port داره؟»

**The user never tells it.** Port count is a property of the *component class*, declared once in
a **component template**, exactly as Plant 3D does it.

### 2.1 What Plant 3D actually does (verified)

Plant 3D's **catalog template** (`.CTPL`) "specifies the type of components that a catalog
contains… For each component type, the template contains properties that are **shared by all
sizes** of the component" — connection type, rating, material. Ports live with the part
definition: for custom parts, `PLANTPARTCONVERT` is the command that **adds ports** to a block
before it can become a piping component, and the port data is stored in the block definition's
XDATA under `AcPp3d`.

So in Plant 3D the chain is: **catalog** (every part that exists) → **spec** (the filtered subset
approved for this class and size range) → **placed instance**. Ports come from the catalog
definition; the modeller never counts them.

That maps cleanly onto what we already have:

| Plant 3D | Ours |
|---|---|
| Catalog | `component_templates` — universal, ADR-007 reference pack |
| Spec | `piping_classes` + `piping_class_rules` — per project, ADR-008 |
| Placed part | `components` + `component_ports` — per line, ADR-006 |

The user's request to "adopt the Plant 3D port model" is therefore satisfied **structurally**,
not by copying tables.

### 2.2 The template

Universal, shipped as reference data (it is true everywhere that a tee has three ports):

```jsonc
// component-templates.ref.json
{
  "PIPE":     {"ports":[{"n":"P1","role":"RUN"},{"n":"P2","role":"RUN"}],
               "sizeRule":"ALL_EQUAL", "lengthRequired": true},
  "ELBOW":    {"ports":[{"n":"P1","role":"RUN"},{"n":"P2","role":"RUN"}], "sizeRule":"ALL_EQUAL"},
  "TEE":      {"ports":[{"n":"P1","role":"RUN"},{"n":"P2","role":"RUN"},{"n":"P3","role":"BRANCH"}],
               "sizeRule":"RUN_EQUAL_BRANCH_LTE"},
  "REDUCER":  {"ports":[{"n":"P1","role":"LARGE"},{"n":"P2","role":"SMALL"}], "sizeRule":"P1_GT_P2"},
  "VALVE":    {"ports":[{"n":"P1","role":"RUN"},{"n":"P2","role":"RUN"}], "sizeRule":"ALL_EQUAL"},
  "FLANGE":   {"ports":[{"n":"P1","role":"RUN"},{"n":"P2","role":"FACE"}], "sizeRule":"ALL_EQUAL"},
  "CAP":      {"ports":[{"n":"P1","role":"RUN"}], "sizeRule":"ALL_EQUAL"},
  "WELDOLET": {"ports":[{"n":"P1","role":"BRANCH"}], "sizeRule":"ALL_EQUAL", "attachesToRun": true}
}
```

Port **roles** matter as much as the count: a TEE's third port is a `BRANCH`, which is what lets
ADR-008's branch table be consulted automatically, and what lets a reducing tee be entered as
6"×6"×2" without a separate component type (see §4.2).

### 2.3 The manual entry flow, simulated

I walked the user's exact scenario — pick a line, add a tee, attach a pipe:

```
added #1 TEE: system created 3 ports automatically
    P1 RUN    6"
    P2 RUN    6"
    P3 BRANCH 2"

open ports offered to user: [(1,'P1','6'), (1,'P2','6'), (1,'P3','2')]
user picks P3 (branch, 2") -> new #2 PIPE auto-sized 2"
joint created: #1 TEE.P3 <-> #2 PIPE.P1  (welded, needs number)
remaining open ends on line: [(1,'P1'), (1,'P2'), (2,'P2')]
```

The interaction is **"pick a component type, pick an open port to attach it to"** — three clicks,
no port arithmetic. Everything else is derived:

| Derived | From |
|---|---|
| number of ports | the template |
| port sizes | the parent port for the connecting end; the class rule for the rest |
| wall thickness / schedule | ADR-008 class rule for that DN, then ADR-007 for the wall |
| end type | ADR-008 `END_PREPARATION` rule for that DN |
| the **joint** | created implicitly by the attachment, with compatibility evaluated at once |
| expected branch component | ADR-008 branch table, when attaching to a `BRANCH` role port |

**The "open ends" list is the whole UI.** A line is complete when it has no unintended open
ports; the same query drives the worklist, the progress metric and the import validator. This is
the query I flagged in ADR-006 §5.2 as "the single most useful", and it is why
`component_ports.is_connected` is denormalised.

### 2.4 What the system refuses, and what it merely warns about

| Situation | Response |
|---|---|
| attaching to an already-connected port | **refused** — the port is not in the offered list |
| 6" pipe onto a 2" branch port | **refused** — size is inherited, not typed |
| BW end onto FL end | **INVALID** (ADR-006 §5.4) |
| SCH 40 onto SCH 80 at equal bore | **WARNING** — legal at a spec break |
| TEE where the class's branch table says WELDOLET | **DEVIATION** (ADR-008 §6) — recorded, not blocked |
| a line left with open ends | **warning on the line**, never a hard block — a line under construction legitimately has them |

The refusals are all *structural* (the UI cannot express the bad state); the warnings are all
*engineering judgement* (the engineer may be right). That split is the same one already used for
advisory inspection in the procurement module.

---

## 3. PCF or Plant 3D? — the answer is "both, at different layers"

> «می‌خوای از مدل PCF استفاده کنی یا Plant 3D؟»

This question has been quietly answered by the previous three ADRs, but it deserves to be stated
plainly because the two are **not competing options** — they operate at different layers.

| | **Plant 3D** | **PCF** |
|---|---|---|
| What it is | a design **application** + its PnPDatabase | a neutral **interchange file** |
| Access | SQLite `.dcf` / SQL Server, Windows-only API | plain text, parseable anywhere |
| Emitted by | Autodesk only | Plant 3D, CADWorx, AVEVA, SmartPlant, Creo, 3DEXPERIENCE |
| Ports | `PartPort` + `P3dPartConnection` relationship tables | `END-POINT` / `BRANCH1-POINT` with bore + end type |
| Welds | **absent** — connection is geometry | **first-class**, with `CATEGORY FABRICATION\|ERECTION` |
| Joint numbers, MC, punch, handover | **absent** | absent |

**Decision:**

- **Conceptual model → Plant 3D.** Ports belong to a component class, declared in a catalog
  template; a connection is a relationship row, not a pointer on a port. That is the shape
  ADR-006 adopted and §2 above extends to manual entry.
- **Import format → PCF, primary.** It is text, vendor-neutral, and it already carries welds
  with the shop/field split. Reading the `.dcf` directly would tie the product to Autodesk and to
  Windows, and would still not give us welds.
- **Neither is the storage model.** Our schema is our own: it has joint numbers, fit-up state,
  MC, punch and handover — none of which exist in either source. Compatibility means a
  documented **import mapping**, which is what ADR-004 §Plant 3D mapping and ADR-005 already say.

A secondary Plant 3D path (reading `.dcf` on a Windows machine, or ingesting a Data Manager
export) stays possible later as an *enrichment* source for attributes PCF omits. It is not on
the critical path.

**Why this matters commercially for the Iranian market:** PCF support means an EPCC contractor
using CADWorx or AVEVA — both common — can use the system on day one. Betting on `.dcf` would
have excluded them.

---

## 4. Answers to ADR-008's open questions

### 4.1 Branch rules depend on two sizes only

> «خیر فقط به ۲ سایز وابسته است»

So the resolver keeps exactly two dimensions. **No third dimension is added** — I will not build
a service/schedule axis "just in case". If a sour-service class ever needs one, it becomes a
separate piping class, which is how the PMS already handles it.

### 4.2 Equal and reducing tees are **one component type**

> «هر دو نوع Tee یک کامپوننت می‌باشد و با Eq. Tee و Red. Tee از هم تفکیک می‌شوند»

Confirms the design: `component_type = 'TEE'`, and equal-vs-reducing is **derived from the port
sizes**, not stored as a different type:

```php
$isReducing = $tee->port('P3')->nps_decimal < $tee->port('P1')->nps_decimal;
// display label: "Eq. Tee" | "Red. Tee"  (bilingual, in the view layer)
```

This is the right way round. A stored `REDUCING_TEE` type could contradict its own port sizes —
the classic denormalisation defect, the same one avoided by not storing `fullCode()` (ADR-004)
and by not storing the joint's derived attributes twice (ADR-006 §5.3). The **label** is
computed; the **data** is the three port sizes.

The branch table (ADR-008 §4) therefore returns `TEE` for both cells, and the reduction is
implied by the header/branch sizes of the cell that matched.

### 4.3 Classes are written out in full — no inheritance

> «هر کدام کامل نوشته میشود»

`parent_class_id` is **dropped from the design**. Each `piping_class` carries its complete rule
set. Simpler, and it matches how the PMS is actually written; a class that shares 90% of its
rows with another is duplicated, which is correct because they can diverge at any revision.

### 4.4 Published classes are locked; changes create a revision

> «بله»

So:

```
piping_classes.state : DRAFT → PUBLISHED
```

- `DRAFT` — editable, may have coverage gaps (ADR-008 §3.1), **may not be assigned to a line**.
- `PUBLISHED` — coverage-gated on publication, then **read-only**. Editing creates
  `revision = revision + 1` as a new row; lines keep pointing at the revision they were issued
  against.

Same reasoning as certificates snapshotting their identifiers: a line whose wall thickness was
derived from class `A1 rev 2` must not silently re-derive when `rev 3` lands. The *deviation
report* (ADR-008 §5) is what surfaces "these 40 lines are on an old class revision" — visible,
not automatic.

---

## 5. Correction accepted: the size-band overlap was a slip

> «اشتباه از من بود، هیچ سایزی در دو بازه مختلف قرار نمی‌گیرد»

Understood — and **the half-open `[from, to)` design stays**, for a reason that is now stronger
rather than weaker. Since no size ever belongs to two bands, half-open ranges are simply the
*correct encoding of the user's own intent*, and the `EXCLUDE USING gist` constraint (verified in
ADR-008 §3) becomes a guarantee that the intent is never violated by a typo, a bulk import or a
future editor. The constraint is not there because the user made a mistake; it is there because
**the PMS is transcribed by humans** and this is the one class of error that cannot be caught by
reading.

---

## 6. What this adds to the build order

Inserted into ADR-006's plan:

| Phase | Content | Changed by this ADR |
|---|---|---|
| 0 | ADR-007 reference packs | **+ `component-templates.ref.json`** (port counts and roles) |
| 1 | `piping_lines` + numbering + AFC gate | — |
| 2 | `components` + `component_ports` | **+ template-driven creation**; ports are never hand-counted |
| 2b | **Manual modelling UI**: pick type → pick open port → attach | **new**, and it is the phase that makes the system usable without a 3D model |
| 3 | `joints` + compatibility + numbering | joints now created *implicitly* by attachment |
| 4 | Fit-up capture | — |
| 5 | Supports, spools | — |
| 6 | **PCF import** | confirmed as the primary path (§3) |

Phase 2b is small once 2 and 3 exist — it is a UI over rules that already have to be there for
import validation anyway. **The same resolver serves both**: manual entry and PCF import both
create components through the template, so they cannot diverge.

---

## 7. Open questions — answered 2026-09-14, see ADR-010

| # | Question | Answer |
|---|---|---|
| 1 | Dia-inch convention for a reducing weld | **The smaller diameter governs.** Computed, never stored (except frozen into an approved progress snapshot). ADR-010 §1 |
| 2 | Who may define component templates | **Two tiers**: universal set read-only in the reference pack; projects may add their own, may not shadow a universal code. ADR-010 §2 |
| 3 | Equipment now, or free-text endpoints | **Model equipment.** A nozzle is a port owner, so `joints` needs no change — verified. ADR-010 §3 |
