# ADR-EPCC-008 — PMS piping classes: size-dependent rules and the branch table

> **Status — 2026-10-05:** Proposed and now promoted to the next Piping completion phase after a full PHP/PostgreSQL test environment is available. Do not implement this against SQLite or without PostgreSQL migration/RLS tests.


**Status:** BUILD PHASES 1–2 IMPLEMENTED 2026-10-11 — awaiting runtime verification (schema + service + resolvers + publish gate + `PipingClassTest`; see §9). Phases 3–5 (ADR-006 wiring, deviation audit, class revisions) remain unbuilt.
**Date:** 2026-09-14
**Depends on:** ADR-007 (reference data: NPS/DN/OD, schedules) — PROPOSED
**Consumed by:** ADR-006 (piping lines, components, ports, joints) — PROPOSED

**Trigger (user):** «مدرک PMS مشخص می‌کنه که برای هر پایپینگ کلاس چه متریالی رو باید استفاده کرد.
مثلاً لوله ۱/۲ تا ۴ اینچ SCH80، ۴ تا ۱۰ اینچ SCH40. این رو چطور پیاده می‌کنی؟ و همین‌طور نوع
کامپوننت انشعاب در قطرهای مختلف.»

Also confirmed: **ASME is the governing dimensional standard** (ADR-007 §9 closed).

---

## 1. The question behind the question

"1/2 to 4 inch SCH80, 4 to 10 inch SCH40" contains a **boundary ambiguity that will cause a
real defect** if copied literally: 4" appears in both bands. On paper a piping engineer reads
the intent instantly; a database cannot. Ask ten engineers and the usual reading is
*"up to and including 3½", then 4" and above"* — but the *usual* reading is not good enough
when a wrong answer specifies the wrong wall thickness on a pressure line.

So the first design decision is not about tables at all:

> **Size bands are stored as half-open DN ranges `[from, to)`, never as inclusive text.**

`[15, 100)` and `[100, 250)` are unambiguous, tile perfectly with no gap and no overlap, and
make 4" (DN100) resolve to exactly one rule. The UI can still *display* "½"–3½"" and "4"–10"";
storage and display are different problems.

Everything below follows from that one choice.

---

## 2. Why DN and not inches

The ranges are keyed on **DN integers**, not NPS strings or inch decimals:

- NPS is a *label*, not a number: `1 1/4`, `1.25`, `1-1/4` and `1¼` are the same size written
  four ways, and Persian input adds `۱¼`. Range logic on strings is not possible; range logic on
  floats invites `3.5 == 3.4999999`.
- DN is a **small integer** (15, 20, 25, 32, 40, 50, 65, 80, 100…), so `int4range` applies
  directly and PostgreSQL's own range machinery does the work.
- ADR-007's reference pack already carries the NPS ↔ DN ↔ OD mapping, so converting the user's
  "4 inch" into DN100 at entry time is a lookup, not a guess.

This also fixes the fold problem: `fold()` already normalises Persian digits for search, and now
size entry does not depend on how someone typed the fraction.

---

## 3. Rule kind 1 — one-dimensional bands (pipe schedule, and much else)

The user's example is one instance of a general pattern. The same shape covers:

| Rule kind | Resolves to | Typical PMS wording |
|---|---|---|
| `PIPE_SCHEDULE` | schedule / wall | ½"–3½" SCH 80, 4"–10" SCH 40 |
| `END_PREPARATION` | end type | ≤1½" socket weld, ≥2" butt weld |
| `VALVE_TYPE` | component spec | ≤2" ball valve, >2" gate valve |
| `FLANGE_TYPE` | component spec | ≤1½" SW flange, ≥2" WN flange |
| `GASKET_TYPE`, `BOLT_SPEC`, `CORROSION_ALLOWANCE` | … | same shape |

So **one table, discriminated by `rule_kind`** — not a separate table per rule type. The same
reasoning as `MovementType` (one ledger, four document types) and `FacilityKind` (one table, a
kind discriminator) already proven twice in this codebase.

```sql
CREATE TABLE piping_class_rules (
  id              uuid PRIMARY KEY,
  project_id      uuid NOT NULL,
  company_id      uuid NOT NULL,
  piping_class_id uuid NOT NULL REFERENCES piping_classes(id) ON DELETE CASCADE,
  rule_kind       text NOT NULL,          -- CHECK against PipingRuleKind::all()
  dn_range        int4range NOT NULL,     -- half-open [from, to)
  value           jsonb NOT NULL,         -- {"schedule":"80"} / {"end_type":"SW"} / ...
  note_en         text, note_fa text,
  source_ref      text                    -- PMS section/table this came from
);

CREATE EXTENSION IF NOT EXISTS btree_gist;

ALTER TABLE piping_class_rules ADD CONSTRAINT piping_class_rules_no_overlap
  EXCLUDE USING gist (
    piping_class_id WITH =,
    rule_kind       WITH =,
    dn_range        WITH &&
  );
```

**The exclusion constraint is the load-bearing part.** Two rules of the same kind on the same
class cannot overlap — the database refuses. This is the same philosophy as the composite FK in
ADR-004: make the corrupt state *unrepresentable*, rather than trusting a service-layer check
that the next developer will bypass with a bulk import.

**Verified on this PostgreSQL 17.11 before proposing:**

| Test | Result |
|---|---|
| `[15,100)` SCH80 + `[100,250)` SCH40 inserted | accepted |
| DN100 (4") resolved | **exactly one rule → SCH 40** |
| DN80 (3") resolved | SCH 80 |
| Same bands written inclusive (`[15,100]` + `[100,250]`) | **rejected**, `exclusion_violation` at DN100 |

That fourth row is the point: had someone entered the user's sentence literally as two inclusive
bands, **the database would have stopped them**, with an error naming the conflicting size.

### 3.1 Gaps are legal to the database but wrong in engineering

Overlap is impossible; a **gap** is not — `[15,100)` + `[150,250)` leaves 5" (DN125) with no
rule, and the DB accepts it happily (confirmed). That is correct behaviour while a class is
being entered, and a defect once it is published.

Detected with PostgreSQL 17's multirange support rather than application looping:

```sql
SELECT range_agg(dn_range) FROM piping_class_rules
WHERE piping_class_id = ? AND rule_kind = 'PIPE_SCHEDULE';
--  complete → {[15,600)}                one fragment
--  gapped   → {[15,100),[150,250)}      two fragments  ⇒ incomplete
```

A coverage check is therefore "does `range_agg` return exactly one contiguous range covering the
class's declared span?" — cheap, exact, and expressible as a single query. This becomes a
**publication gate** on the piping class, in the same spirit as open A-items blocking an MC
certificate: a class with holes may exist as a draft and may not be used by a line.

### 3.2 Resolution is a lookup, not a calculation

```php
$dn  = $reference->size($line->nominal_size)->dn;   // "4" → 100  (ADR-007)
$rule = $class->ruleFor(PipingRuleKind::PIPE_SCHEDULE, $dn);  // ← @> operator, GiST index
$schedule = $rule?->value['schedule'] ?? throw new NoRuleForSize($class, $dn);
```

**Throws rather than returns null.** A missing rule means the PMS does not cover this size for
this class, which is an engineering error worth surfacing loudly — the same reasoning as
ADR-007's reference lookups throwing on `1/2" SCH 30`. A silent null here becomes a silent
default schedule, which becomes a wrong wall thickness on a pressure line.

---

## 4. Rule kind 2 — the branch table is **two-dimensional and legitimately overlapping**

The user's second question is a different shape, and assuming otherwise would have produced a
broken design. A PMS branch table is a matrix:

|  header ↓ / branch → | 1" | 2" | 4" | 6" |
|---|---|---|---|---|
| **6"** | SOCKOLET | WELDOLET | TEE(red.) | TEE |
| **4"** | WELDOLET | WELDOLET | TEE | — |
| **2"** | SOCKOLET | TEE | — | — |

So a rule is `(header_range × branch_range) → component_type`.

> **2026-10-12 requirements-review note (R-1):** the outcome vocabulary was
> closed and extended by §9.1 — the outcome is a fitting type **or** `P2P`
> (no component, with a mandatory stub-in/stub-on kind and, for
> `reinforcement = PAD`, a mandatory pad thickness in mm). Read this
> section's examples together with §9.1.

### 4.1 The spike that changed the design

My first instinct was to reuse the exclusion constraint in two dimensions:

```sql
EXCLUDE USING gist (piping_class_id WITH =, header_range WITH &&, branch_range WITH &&)
```

**It failed, and the failure was informative.** Inserting two perfectly normal PMS rules —

```
header [15,600)  branch [15,600)  → TEE        (the general case)
header [50,600)  branch [15,50)   → WELDOLET   (small branch off a large header)
```

— was **rejected** with `exclusion_violation`. The second rule's footprint sits *inside* the
first, and `&&` in both dimensions is true.

But both rules are correct, and every real branch table is written this way: **a general rule
plus more specific exceptions**. Forbidding overlap would force the engineer to enumerate every
cell of the matrix explicitly — dozens of rows where the PMS has three, and a fresh chance to
mistype each one.

**Conclusion: for the branch table, overlap is a feature, not corruption.** Resolution is by
**specificity — the narrowest matching rule wins.**

### 4.2 What is actually constrained

```sql
CREATE TABLE piping_class_branch_rules (
  id              uuid PRIMARY KEY,
  project_id      uuid NOT NULL,
  company_id      uuid NOT NULL,
  piping_class_id uuid NOT NULL REFERENCES piping_classes(id) ON DELETE CASCADE,
  header_range    int4range NOT NULL,
  branch_range    int4range NOT NULL,
  component_type  text NOT NULL,        -- TEE / REDUCING_TEE / WELDOLET / SOCKOLET / STUB_IN …
  value           jsonb,                -- rating, schedule override, reinforcement pad …
  source_ref      text,
  UNIQUE (piping_class_id, header_range, branch_range)   -- the same footprint twice is a typo
);
```

The `UNIQUE` on the exact footprint still catches genuine duplicates; overlap is allowed.

### 4.3 Resolution, verified

```sql
SELECT component_type
FROM piping_class_branch_rules
WHERE piping_class_id = ?
  AND header_range @> :header_dn
  AND branch_range @> :branch_dn
ORDER BY (upper(header_range) - lower(header_range))
       + (upper(branch_range) - lower(branch_range)) ASC   -- narrowest footprint first
LIMIT 1;
```

Tested against the three-rule fixture above:

| Header | Branch | Rules matching | Chosen | Correct? |
|---|---|---|---|---|
| 6" (DN150) | 6" (DN150) | TEE | **TEE** | yes — equal size |
| 6" (DN150) | 1" (DN25) | TEE, WELDOLET, SOCKOLET | **SOCKOLET** | yes — tightest footprint |
| 8" (DN200) | 1" (DN25) | TEE, WELDOLET, SOCKOLET | **SOCKOLET** | yes |

Three rules match a single cell, which is exactly why specificity has to be explicit. The
narrowness metric is `header span + branch span`; an `ORDER BY` tiebreak on an explicit
`priority` column handles the rare case where two rules are equally narrow.

### 4.4 A constraint the database cannot express

`branch_dn ≤ header_dn` — a 6" branch off a 2" header is nonsense. This cannot be a row-level
CHECK because it is a property of the *query*, not of the rule. It belongs in the resolver, and
it is worth a distinct exception (`BranchLargerThanHeader`) rather than a generic "no rule
found", because the two have completely different causes: one is a data-entry error in the
model, the other a gap in the PMS.

---

## 5. Where this plugs into ADR-006

| ADR-006 element | What ADR-008 gives it |
|---|---|
| `piping_lines.piping_class` | FK into `piping_classes` — the per-project register (ADR-006 §11.5) |
| `component_ports.wall_thickness` | **derived**: class rule → schedule, then ADR-007 → wall for (DN, schedule) |
| `components` at a branch | the expected `component_type`, so the model can be *checked* rather than trusted |
| `PortCompatibility` (§5.4) | a third verdict source: "legal per ASME, but **not what this class specifies**" |
| PCF / 3D import | every imported component can be validated against the class — the single highest-value automated check in the whole ingestion story |

That last row is worth emphasising. Once the PMS is data, importing a 3D model stops being a
transcription exercise and becomes an **audit**: 4,000 lines checked against their class in
seconds, with a report of every deviation. That is the «خیلی از اشتباهات کم شده» the user is
after — not faster typing, but a machine that disagrees with the designer when the designer is
wrong.

---

## 6. Two-layer validation, and why both layers are needed

| Layer | Question | Source | Verdict on failure |
|---|---|---|---|
| **Physical** (ADR-007) | does this size/schedule *exist* in ASME? | reference packs | **INVALID** — unbuildable |
| **Contractual** (ADR-008) | is it what the **PMS** specifies for this class? | project piping classes | **DEVIATION** — buildable, but not per spec |

Keeping them separate matters. A 6" SCH 60 pipe is perfectly real (ASME lists it) and perfectly
wrong on a class that says SCH 40 — reporting that as "invalid" would be false, and reporting it
as "fine" would miss the actual problem. This mirrors the **advisory inspection** decision in
the procurement module: the system reports, it does not silently block.

---

## 7. Open questions — all answered (2026-09-14), see ADR-009 §4

| # | Question | Answer | Effect |
|---|---|---|---|
| 1 | Do branch rules depend on more than the two sizes? | **No, two sizes only** | resolver stays 2-D; no speculative third axis |
| 2 | Are reducing tees a distinct component type? | **No — one `TEE`**, labelled Eq./Red. | reduction **derived from port sizes**, never stored; a stored type could contradict its own ports |
| 3 | Class inheritance? | **No — each class written out in full** | `parent_class_id` **dropped** from the design |
| 4 | Lock published classes? | **Yes** | `DRAFT → PUBLISHED`; published is read-only, edits create a new **revision**, lines keep pointing at the revision they were issued against |

The user also corrected the size-band example: no size ever falls in two bands. **The half-open
`[from, to)` encoding and the exclusion constraint stay** — they are now simply the correct
encoding of that intent, and the constraint guards it against transcription error rather than
against the user.

---

## 8. Build order

| Phase | Content |
|---|---|
| 0 | **ADR-007 reference packs** (sizes, schedules, end types) — prerequisite for DN conversion |
| 1 | `piping_classes` register + `piping_class_rules` + exclusion constraint + coverage gate + resolver with `NoRuleForSize` |
| 2 | `piping_class_branch_rules` + specificity resolver + `BranchLargerThanHeader` |
| 3 | Wire into ADR-006: derive `wall_thickness`, validate components against their class |
| 4 | Deviation reporting on import (the audit described in §5) |
| 5 | Class revisions and the publication lock (§7 #4 — confirmed required) |

**Nothing is implemented.** Phase 1 is small and self-contained; the spikes in §3 and §4.1 were
throwaway tables and have been dropped.

---

## 9. Implementation record — build phases 1 & 2 (2026-10-11)

**Status of this record: IMPLEMENTED — awaiting runtime verification** (the
owner's Laragon `php artisan test` is the verification step; the EXCLUDE
constraint itself was already proven live against sandbox PostgreSQL in the
P-19 session).

- **Schema** — migration `2026_10_11_120000_create_piping_classes_and_rules`:
  `piping_classes` (code unique per project, `dn_span` int4range,
  DRAFT/PUBLISHED), `piping_class_rules` (rule_kind CHECK from
  `PipingRuleKind::ALL`, `dn_range` int4range, jsonb `value`, and the
  load-bearing `EXCLUDE USING gist (piping_class_id WITH =, rule_kind WITH =,
  dn_range WITH &&)`), and `piping_class_branch_rules` (two int4ranges,
  `UNIQUE` on the exact footprint, `priority` tiebreak). All three tables
  carry FORCE RLS with the sargable participation preamble shipped the same
  day (migration 110000, ADR-012 fix). The migration asserts `btree_gist`
  exists and fails with an actionable message — the extension is a DBA step
  (sandbox script / Laragon doc / CI), never an app-role CREATE EXTENSION.
- **Service** — `PipingClassService`: create/addRule/addBranchRule translate
  exclusion/unique violations into bilingual validation errors; `publish`
  runs the §3.1 gate with `(span − range_agg(dn_range))` per rule kind and
  refuses holes; `ruleFor` and `branchFor` are `@>` lookups that THROW
  (`NoRuleForSizeException`, `BranchLargerThanHeaderException`) — never null.
  Published classes are read-only (revision mechanics are build phase 5).
- **Resolution** — specificity-wins is `(header span + branch span) ASC,
  priority DESC, created_at ASC`, exactly §4.3.
- **Tests** — `tests/Feature/PipingClassTest.php` pins the ADR fixtures:
  adjacent bands accepted / overlap refused, DN100→SCH40 / DN80→SCH80 /
  uncovered size throws, gapped publish refused then accepted once filled,
  published class read-only, the three-rule branch table (TEE / SOCKOLET /
  WELDOLET by narrowness), `branch > header` distinct error, duplicate
  footprint refused, priority tiebreak, and the non-participant RLS mirror.

### 9.1 Addendum — branch vocabulary closed, P2P with its two kinds and the pad rule (2026-10-11)

Owner requirements (same day, two decisions): «جدول انشعاب قاعدهٔ
Pipe-to-Pipe هم داشته باشد، به نام **P2P**، با دو نوع کلی **stub-in** و
**stub-on**؛ و در صورت نیاز به پد تقویتی، **ضخامت پد** هم مشخص شود».

- **`BranchConnectionType`** (closed, DB CHECK + bilingual service
  validation): `TEE`, `WELDOLET`, `SOCKOLET`, and **`P2P`** — the branch is
  contoured and welded onto/into the header with no fitting. Semantically
  load-bearing for build phase 3: a P2P cell means "expect NO component at
  this branch".
- **`P2pKind`** (required on P2P cells, forbidden on fittings, DB-enforced):
  `STUB_IN` (seats inside the header bore) and `STUB_ON` (saddle on the
  header surface).
- **`BranchReinforcement`**: `NONE` / `PAD`. Owner rule: a PAD requires its
  thickness — `pad_thickness numeric(8,3)` in **millimetres**, mandatory and
  > 0 whenever `reinforcement = 'PAD'`; a stray thickness without a pad is
  refused too. Both invariants are CHECK-backed and service-validated. A PAD
  may optionally declare `value.pad_material` in the `PIPE_MATERIAL` value
  shape; if absent, it inherits the header DN's issued `PIPE_MATERIAL`.
- **Live verification on sandbox PostgreSQL** caught and fixed a NULL trap:
  a CHECK of the shape `(type='P2P' AND kind IN (...)) OR (...)` PASSES when
  the first branch evaluates to NULL, so a P2P row without its kind slipped
  through the first draft; the shipped constraint is NULL-explicit
  (`p2p_kind IS NOT NULL AND …`). Valid rows (P2P+pad, P2P stub-on bare,
  fitting) all pass; all four corrupt shapes are refused by the database.

### 9.2 Forward requirement — branch welds are joints, not components (owner, 2026-10-12)

Recorded requirement (proposal P-21, piping roadmap P5): a P2P cell means
the branch is contoured and welded onto/into the header with NO fitting —
so at build time the connection is a WELD, and the joint register must carry
formal branch-weld types (`BRANCH_STUB_IN` / `BRANCH_STUB_ON`). Branch
welds must be counted and reported separately from butt welds, dia-inch
metrics must not silently absorb them, the mandatory pad thickness of a PAD
rule must stay traceable to the weld, and a joint contradicting its class is
a deviation. The core joint-schema/service slice is now implemented in ADR-006
§14 and this ADR's §§9.4–9.5; full welding/NDT workflow and reporting remain
open, as recorded below.

### 9.3 Pad thickness must be validated per ASME B31.3 §304.3.3 (owner requirement, 2026-10-12)

The `pad_thickness` recorded on a P2P branch rule is a structural number
today; the owner requires it to be **engineering-validated** with the
ASME B31.3 para 304.3.3 area-replacement method for fabricated welded
branch connections (internal pressure):

```
d1 = [Db − 2(Tb − c)] / sin β                    (effective opening)
d2 = greater of d1 or (Tb − c) + (Th − c) + d1/2, capped at Dh
L4 = lesser of 2.5(Th − c) or 2.5(Tb − c) + Tr
A1 = th · d1 · (2 − sin β)                       required area  (eq 6)
A2 = (2·d2 − d1) · (Th − th − c)                 excess run wall (eq 7)
A3 = 2·L4 · (Tb − tb − c) / sin β                excess branch wall (eq 8)
A4 = pad area + weld-metal area inside the zone
PASS when A2 + A3 + A4 ≥ A1                      (eq 6a)
```

Nomenclature: `h`=run/header, `b`=branch; `D`=OD, `T`=nominal wall (mill
tolerance deducted), `t`=pressure-design thickness per B31.3 para 304.1.2,
`c`=corrosion + mechanical allowances, `β`=branch angle (45°–90°),
`Tr`=nominal PAD thickness in the current rule; for a PAD cut from pipe,
`Tr,min = 0.875·Tr` is used in `L4` and pad-metal area, while the weld minima
use nominal `Tr`. Weld credit follows para 328.5.4 and Appendix H H301/H302:
`tc` is the lesser of 0.7 times the **nominal** branch wall and 6 mm, kept
separate from mill-tolerance-reduced `Tb`. The root branch-to-run weld credits
`2·tc²`; when a PAD is present, the outer-edge-to-run weld additionally
credits `2·(0.5·Tr)²` using nominal `Tr`. The separate PAD-to-branch attachment
is required but is not added as extra A4 credit in H302. The PAD metal slice is
bounded by both its physical span `D_s` along the run and the reinforcement
zone: `A4,pad = min(D_s−Db, 2·d2−Db)·min(T_{r,min}, L4)·min(1,S_pad/S_run)`.
A PAD rule may declare an optional `value.pad_material` using the same
validated material shape as `PIPE_MATERIAL`, including its applicable stress
override and Section IX welding identity. If it is absent, PAD material defaults to the header DN's
issued `PIPE_MATERIAL` rule; the installation snapshot records both source
and resolved identity. The A4 metal contribution is multiplied by
`min(1,S_pad/S_run)` at the class design temperature, so the default same
material gives a ratio of 1 and a weaker explicit material is reduced.
Figure 304.3.3-1 note (2) proportions the physical A4 width against the full
minimum saddle thickness `T_{r,min}`; `L4` clips the credited area height, but
does not make an over-thick PAD physically wider. The ratio must be at least
1:1 and is preferably near 4:1 where geometry allows; only the 1:1 limit is a
hard validation. If branch allowable stress is below run stress,
`A3` is reduced by `S_branch/S_run` (para 304.3.3(c)(2)); higher branch or
reinforcement stress earns no extra credit (para 304.3.3(f)(4)). Before
balancing areas, both mill-tolerance-reduced pipe walls must satisfy
`T ≥ t + c` (para 304.1.1(a)); an area deficit cannot be hidden by clipping
negative `A2`/`A3` to zero. The Eq. (3a) wrapper also refuses `t ≥ D/6`
**or** `P/(S·E) > 0.385`, either of which requires the special consideration
of para 304.1.2(b).

**Validation semantics.** A recorded `pad_thickness` is valid when the
check passes for the **worst case** inside the rule's header×branch
footprint — every (DN_header, DN_branch) pair sized with the schedules its
own class rules assign and the class's design conditions. Where no pad is
recorded (`reinforcement = NONE`), the same check must show the bare
connection already passes, or the rule must say `PAD`. PMS branch cells do not contain root geometry, so publication checks the full supported β domain rather than guessing a perpendicular branch. This **is implemented** by evaluating both endpoints, 45° and 90°, plus every geometry transition where the area balance changes affine regime. Let `x = csc(β)`, `K = Db − 2(Tb − c)` and `C = (Tb − c) + (Th − c)`: then `d1=Kx`, `d2=min(Dh, max(Kx, C+Kx/2))`, and the area balance is piecewise affine in `x`. Candidate points include the `d1`/half-width crossover, active reinforcement-zone cap, any zero/finite-pad-width transitions, and the capped-zone `A2` clip. The minimum over the supported angle interval is therefore captured at a candidate; the controlling `beta_deg` is retained with the worst pair result. Required pad thickness is solved at failing candidates, and the largest requirement controls the class cell.

The direct P2P wall-root model records an explicit
`branch_longitudinal_angle_deg` from 45°–90°; existing unrecorded roots remain
NULL rather than being assigned a guessed angle. The publication gate covers
that whole angle domain, so it does not rely on one root angle. PAD perimeter
method v2 separately accepts only a centred root with β=90°; recording an
oblique β does not approve an angled-pad outline. The platform also computes the minimum
required pad thickness by bisection because `Tr` changes `L4`.

**Status.** Step A (done 2026-10-12, proposal P-24): the pure calculation
itself — `App\Services\BranchReinforcementCalculator` with
`evaluate()` and `requiredPadThickness()`, covered by
`Tests\Unit\BranchReinforcementCalculatorTest` against published worked
examples plus hand-verified fixtures (zone capping, stress-ratio
reduction, 45° laterals, corrupt-input refusals).

All Step B decisions settled by the owner 2026-10-12 — recorded in the
P-24 entry: design pressure/temperature are **class attributes**; material
data from a **Table A-1 reference pack** built from the standard the owner
uploads (manual entry stays as override); **mill tolerance 12.5%**
(`T = 0.875 × nominal`). Step B therefore adds: class design-basis
columns, the material reference pack + grade rule, the eq (3a) wrapper
(already built — `pressureDesignThickness()`), and publish-gate validation
of every PAD rule against the worst case of its footprint, refusing `NONE`
where reinforcement is required. Bilingual validation messages, same
pattern as the rest of this service.

**Step B status (2026-10-12, BUILT).** Section IX identity (owner
requirement 2026-10-12): PIPE_MATERIAL values carry `p_no` and
`group_no`; both are mandatory for materials outside the materials
library, while library materials take the P-No from the Table A-1 column
the 2024 edition prints and the Group No from Section IX Table QW/QB-422
(2021 edition, owner-uploaded); the pack build cross-checks the two
books' P-No columns and fails on drift. The
owner-uploaded ASME B31.3-2024
is extracted: `standards-packs/reference/b313-materials.ref.json` holds 57
Table A-1 (SI) rows for the common grades — API 5L X-grades are published
under ISO names (L290 = X42 … L485 = X70), PSL1/PSL2 kept as distinct rows
— plus Tables 304.1.1-1 (Y), 302.3.5-1 (W) and the A-3 (Ej) subset,
regenerated by `scripts/build_b313_materials_pack.py`. The accessor is
`App\Support\Reference\MaterialReference` (ADR-007 pattern; every lookup
throws; S interpolated between columns, never extrapolated), covered by
`Tests\Unit\MaterialReferenceTest` with Appendix-H anchors. Migration
`2026_10_12_120000` added the class design basis
(`design_pressure_mpa` / `design_temperature_c`, MPa / deg C) and the
`PIPE_MATERIAL` rule kind, whose value resolves against the pack unless an
`s_mpa_override` is given (manual entry remains an override). The publish
gate now runs `App\Services\BranchConnectionPadGate`: every branch cell is
checked at the worst case of its footprint (all pack DN pairs), effective
wall = 0.875 × nominal, PAD cells must pass with the recorded thickness,
NONE cells must pass bare, and anything uncheckable — §304.3.1(b) limits,
missing schedule/CA/material rules, temperatures beyond Table A-1 — is
refused with a bilingual message listing each failing cell. Covered by
`Tests\Feature\PipingPadGateTest` on pack-computed matrices. Commits
`55272b2`, `551b160`, `ae5351c`; runtime verification pending on the
owner's Laragon tier.

**B31.3-2024 PDF reconciliation (2026-10-26).** The attached edition's
§304.3.3 and Figure 304.3.3-1 (printed pp. 25–28), §328.5.4 (printed pp.
70–71), and Appendix H Examples H301/H302 (printed pp. 445–447) were extracted
and checked against the calculators. The existing `A3` stress-ratio reduction,
wall-thickness refusal, §304.1.2(b) limits, nominal root-weld sizing, and exact
45°–90° β sweep remain in force. PAD A4 now uses the two-axis saddle geometry's
span along the run, is clipped to the reinforcement-zone width, and uses the
0.875 minimum-wall factor for pipe-saddle metal thickness. Root and PAD-edge
weld credits are separately calculated per H301/H302; the required
PAD-to-branch fillet throat is recorded separately but earns no extra H302
area credit. Figure 304.3.3-1 note (2)'s 1:1 minimum ratio is a hard gate and
installation validation; its near-4:1 preference is advisory. The H302
regression recalculates Eq. (3a) at the published 4.347 MPa: exact
`17.48×0.875 = 15.295 mm` gives pad area 1,425.419 mm² (vs the example's
rounded 1,426) and credited weld area 224.775 mm² (vs 225); the balance is
+4.622 mm² at 4.347 MPa and −1.519 mm² at 4.352 MPa. The gate rejects an
insufficient recorded thickness after applying its material ratio. Regression
coverage includes header fallback, weaker explicit PAD stress, the stronger-
material cap, and malformed optional PSL data. Geometry remains a centred 90°
method; no θ or component rotation is used. A branch cell may explicitly declare
`value.pad_material`; if absent, PAD material defaults to that header DN's
issued `PIPE_MATERIAL` rule. The selected material identity, design-temperature
allowable stress, source (explicit or default), and applied
`min(1,S_pad/S_run)` ratio are frozen in the installation snapshot; the
publish gate uses the same material rule. Actual as-welded sizes are not
recorded or verified. PHPUnit could not be run because PHP, Composer, and application dependencies are absent; the
changed PHP source/tests were syntax-parsed with `php-parser`, and independent
Python numeric checks covered H302, the β-sweep, and the fallback-versus-weaker-PAD-stress gate fixture.

**Flange rating step (2026-10-12, BUILT).** The owner-uploaded ASME
B16.5-2025 extends Step B to flanged joints:
`standards-packs/reference/flange-ratings.ref.json` holds all 45 SI
pressure-temperature tables (Tables 2-1.1 .. 2-3.19, 903 rating rows,
classes 150..2500) with each table's material block, regenerated by
`scripts/build_flange_ratings_pack.py`. The builder cross-checks the
Group 1.1 SI table cell-by-cell against its U.S. Customary twin (every
converted value must land inside the SI step bracket) and fails on
ladder/monotonicity violations. The accessor is
`App\Support\Reference\FlangeRatingReference` (ADR-007 pattern; every
lookup throws — above a ladder top or at a temperature where the table
retires a class there is NO published rating, and guessing one would be
unsafe). The FLANGE_TYPE rule value is
`{"rating_class": 300, "spec": "A105", "grade": "F316"|null}`: the
rating class must sit on the B16.5 ladder and the material must resolve
to a material group in the pack's index — both refused at entry,
bilingual. The publish gate rates every FLANGE_TYPE cell against the
class design basis (`validateFlangeRatings`): the design pressure
(MPa -> bar) must not exceed the group table's rating at the design
temperature. Covered by `Tests\Unit\FlangeRatingReferenceTest` (pack
anchors: A105 Class 150 = 19.6 bar at <=38 deg C, the classic 285 psig)
and `Tests\Feature\PipingFlangeRatingGateTest`. B16.47 large-diameter
tables and Section II Part D extensions remain follow-ups.

### 2026-10-13 domain correction (ADR-028)

PMS is an **engineering specification document that includes one or more
piping classes**. The class/rule tables implemented here model its technical
content only; they do not model the PMS document, its issued revisions, or the
membership of each class specification in a revision. `PUBLISHED` on a class
is a technical gate, not evidence of CDE document approval. Do not describe
this implementation as a complete PMS document or use class publication alone
to infer a governing document revision for line registration. See ADR-028.

### 9.4 Branch connections are separable in the joint register (built 2026-11-10)

Owner requirement: «در پروژه‌ها معمولاً قیمت اجرا و جوشکاری اتصال انشعاب از
قیمت اجرا و جوشکاری جوش معمولی بیشتر است. سیستم باید بتواند اتصالات انشعابی را
از معمولی تفکیک کند» — a branch connection costs more to execute and weld than
an ordinary weld, so the register must be able to tell them apart. §9.2 asked
for the same thing in specification language. The model/service portion is
implemented; the welding report and commercial rate schedule are not.

**Built.** `joints.branch_kind`, nullable, CHECK-constrained and indexed
(`WHERE branch_kind IS NOT NULL`). TEE/CROSS are derived from the **BRANCH port
role**, not sizes or end types, and frozen like `joint_type`. OLET is explicit:
`JointService::joinOletToWall()` writes `branch_kind=OLET` on the OLET
RUN/FA-to-header-wall weld, while its separate BRANCH-to-pipe butt weld remains
an ordinary joint. That makes the purchased fitting and both physical welds
visible without counting the class-level branch connection twice. `STUB_IN`
and `STUB_ON` remain explicit P2P kinds written only by
`JointService::joinToWall()`. `lineMetrics()` returns `branch_welds`,
`branch_dia_inch` and `branch_by_kind` alongside totals; the OLET root weld's
physical `dia_inch` uses its branch size, not its larger RUN size.

**Why the totals still include branch welds.** They are welds, and a dia-inch is
a dia-inch. What was wrong was that nothing could see them separately — not that
they were counted. The complement is `welds - branch_welds`.

**Why the vocabulary is not `BranchConnectionType`.** The class declares
(TEE/WELDOLET/SOCKOLET/P2P); the register observes the physical joint. The two
are stored separately: `branch_kind` preserves observation while the issued
class result and pad are snapshotted on the joint. `Joint::branchDeviation()`
reports agreement, mismatch or an ungoverned case; it does not overwrite the
observation with the verdict.

**Deliberately not built.** This slice does not create a complete branch
welding workflow. The line-model UI now creates explicit wall roots and the
direct-P2P pad pair (§9.6), but there is still no PCF-to-model writer, weld
log, NDT/repair loop, welder qualification or WPS/PQR linkage, or contract-rate
schedule. Generic OLET subtypes are not a PCF import classification: the
read-only PCF parser does not make an OLET model-connectable or write either
interface. Do not mistake the P2P `joinToWall()` path for an OLET shortcut.

**Valuation is not decided here.** No multiplier is folded into dia-inch. The
factor is a property of the contract and fabricator, not of the weld; baking it
in would produce a commercial number that looks physical. The register exposes
branch welds by kind, branch size and available pad thickness; a future contract
rate schedule may price them. No rate schedule is implemented here.

### 9.5 The class verdict travels with the branch weld (built 2026-11-10)

Step 2 of the §9.2 work. §9.4 gave the register its own OBSERVATION
(`branch_kind`): port-role-derived for TEE/CROSS and explicit wall-joint
construction for OLET/STUB_IN/STUB_ON. This gives it the class's DECLARATION,
and the space between the two.

**Built.** `joints` gains `branch_class_type`, `branch_class_p2p_kind`,
`branch_pad_thickness_mm` and `branch_rule_state`, all CHECK-constrained and all
nullable. `JointService::branchVerdictFor()` fills them for TEE/CROSS branch
welds; `wallVerdictFor()` fills them on the P2P path, and
`joinOletToWall()` records the OLET verdict/pad on the root wall weld.
`Joint::branchDeviation()` compares observation with the declared outcome.

**Read from the issued snapshot, never from the live rules.** Same convention
ComponentService already settled for pipe schedules — "inherits the line's
issued PMS specification, not the mutable/current piping-class rules". A weld is
a stronger case for it than a pipe: a joint is a physical fact, and a class
revision issued next month must not retroactively restate what this weld was
built to. `branch_pad_thickness_mm` is frozen rather than referenced for the same
reason — the pad was bought, cut and welded at that thickness.

**Two vocabularies, deliberately.** `branch_kind` (`JointBranchKind`) is what the
model saw, whether derived from a TEE/CROSS port or written by an explicit wall
path; `branch_class_type` (`BranchConnectionType`) is what the class declared.
They cannot be derived from one another, and keeping both is what makes the
deviation check possible — overwriting the observation with the verdict would
destroy the only evidence that they differ. `branchDeviation()`
returns `AGREES`, `MISMATCH`, `FITTING_WHERE_CLASS_SAYS_NONE`, `UNMATCHED` or
`UNGOVERNED`.

**Records rather than refuses.** Plant 3D refuses to connect when the branch
table has no cell for the pair — "Without an entry, Plant 3D does not create a
connection at that point". That is the right behaviour once there is a screen
for the branch table, and there is not one today; a refusal would be a wall the
user cannot climb. So an ungoverned branch weld is marked instead, and
`lineMetrics()` reports `branch_ungoverned` and `branch_padded`.

`branch_rule_state` distinguishes the two ways of being ungoverned, because two
different people chase them: `NO_SNAPSHOT` means the line was never given an
issued class (an upstream data gap); `NO_RULE` means the class exists but has no
cell for this header x branch pair (a branch-table authoring gap).

**`NO_SNAPSHOT` is nearly unreachable, and that is worth knowing.**
`PmsLineEligibility::snapshotId()` refuses to create a line whose class has no
approved revision, and the database guard `LINE_PMS_PIN_IMMUTABLE` refuses to
un-pin one that has. The state therefore exists only for rows written outside
the service — importers, data repair, lines predating the PMS feature. Both
facts are asserted by tests rather than assumed.

**`UNMATCHED` is a gap in the vocabulary, not a defect in the weld.** A CROSS
branch is a branch connection, but `BranchConnectionType` is closed at
TEE/WELDOLET/SOCKOLET/P2P and has no CROSS, so no declared value can ever agree
with it. Calling that a deviation would be wrong; the gap is in the PMS
vocabulary and opening it is a separate decision.

**Deliberately not built.** The verdict is recorded and deviation is
observable, but mismatch does not hard-block the physical joint. There is no
report/dashboard for `branch_ungoverned`, no PCF-to-model writer, and no
WPS/PQR, welder qualification, NDT/repair or contract-rate workflow. The
model page creates explicit P2P/OLET wall roots, and direct-P2P PAD cells use
the two-fillet assembly in §9.6; these remaining items are separate acceptance
criteria, not implied by §§9.4–9.6.

### 9.6 Direct P2P PAD rule — physical pad plus two header-governed fillets

A physical `PAD` rule is supported only for `P2P` with `STUB_IN` or `STUB_ON`;
TEE and OLET/SOCKOLET rules cannot declare a pad. Authoring validation and a
PostgreSQL check reject new non-P2P PAD rules, and the publish gate refuses any
legacy row that contradicts this restriction.

When an issued rule declares `P2P` + the matching `STUB_IN`/`STUB_ON` kind
with `reinforcement = PAD`, installation snapshots the resolved thickness,
stores the analytic OD contact curve and a separate sampled outer perimeter
with its bounding spans, and creates a physical pad plus two separate, numbered
fillet joints: pad-to-header pipe and pad-to-branch pipe. The circumferential
span is around the header; the header-axis span is along the run. Inputs are
the actual header/branch ODs and component schedules, schedule walls reduced
by the reference 12.5% mill-tolerance factor, and the greater corrosion
allowance from the two lines' issued PMS snapshots.

For a centred right-angle saddle, the existing B31.3 values `d1` and `d2`
remain the source of the reinforcement-zone limits. The ideal outer-cylinder
contact curve is defined separately in the local frame by
`x=Rb·cos(u)`, `y=Rb·sin(u)`, `z=sqrt(Rh²−y²)`, where `Rh=Dh/2`, `Rb=Db/2`,
and `0≤u≤2π`. On the unrolled header shell, `s=Rh·atan2(y,z)`; its exact
developed contact span is `S=Dh·asin(Db/Dh)` (asin in radians), while its
header-axis span is `Db`. This is only a local mathematical frame, not a
project-wide orientation or θ convention.

The PAD outer perimeter is a second, independent shape: the developed contact
footprint is expanded by a closed disk of radius `e=d2−Db/2`, which must be
non-negative. This nominal uniform shell-surface offset makes its physical
header-axis span `D_s=Db+2e=2d2`; its developed circumferential span is `S+2e`.
The perimeter is persisted as a closed sampled polyline separately from the
analytic contact curve. The two stored PAD dimensions are bounding spans of
that outer perimeter; they are not the contact curve, and `d1`/`d2` remain
zone limits rather than a perimeter description. The B31.3 zone slack
`d2−d1/2` is not substituted for the physical perimeter offset. This uniform
offset is a modelling convention, not a B31.3-mandated outline or minimum
PAD dimension. The code supplies the reinforcement-zone limits and A4 span
checks, not a fabrication saddle-cut contour.

For the current pipe-saddle rule, the physical `D_s` span is used to cap the
credited `A4` pad width at `min(D_s−Db, 2·d2−Db)`. Nominal pad thickness is
reduced by 0.875 for L4 and pad-metal area; the root and pad-to-run edge weld
credits are separate per H301/H302, while the required pad-to-branch
attachment weld is recorded without extra A4 credit. The installation
snapshot records credited A4 width, credited area height
`min(T_{r,min}, L4)`, and area. Figure 304.3.3-1 note (2)'s physical
width-to-height ratio is checked separately as `A4 width / T_{r,min}` using
the full minimum saddle thickness (not the L4-clipped area height); the 1:1
minimum is enforced and the near-4:1 preference is advisory. The class gate
applies the same physical span-based A4 model while retaining its full
45°–90° β sweep. An optional PAD material is declared as
`branch_rule.value.pad_material` with the `PIPE_MATERIAL` shape; when absent,
it defaults to the header DN's issued `PIPE_MATERIAL` rule. A4 metal credit
uses `min(1,S_pad/S_run)` at the class design temperature, and the selected
material identity, source, stress and ratio are frozen in the PAD snapshot.

The current joint schema stores an explicit P2P β field (45°–90°), but has
no θ or component-rotation field. PAD installation requires the root's recorded
β=90° and zero offset; it does not assume or infer β, and refuses other β
values until an approved angled-saddle method exists. While a PAD is installed,
the database locks a change to the root's β so the frozen geometry snapshot
cannot drift; remove the assembly before revising the angle. The PAD contact
curve and perimeter method do not use θ or component rotation. The sampled
perimeter is a nominal geometric profile—not a code minimum, area-replacement
pass, branch-wall cut path, or fabrication drawing. No wall compensation,
`+10 mm` workshop allowance, fit-up clearance, weld preparation, kerf, or
fabrication tolerance is applied; these require separate engineering and shop
definition.

The pad and both fillets resolve from and retain the header line's issued PMS
class, regardless of the existing P2P root's register owner. To preserve that
rule/owner coupling, both fillet joints are registered on the header line; the
root itself is not silently reassigned.

This path is intentionally limited to direct P2P roots. An OLET's purchased
fitting introduces another physical interface, and no OLET pad geometry or PMS
rule behavior is inferred here. The OLET remains governed by its separate
wall-root path in ADR-006 §15.

The service enforces the issued rule and exact P2P kind; PostgreSQL guards the
pad/root/port/target relationship and commit-time completeness of the two
fillets. Fillet welds count as numbered welds but contribute no guessed dia-inch: a
pad outline length cannot be derived from nominal pipe size. This modelling
slice does not close P-23's pad-material/MTO question or the broader P-21
weld-report and welding/NDT workflow.
