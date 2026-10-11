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
  refused too. Both invariants are CHECK-backed and service-validated.
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
a deviation. This lands with the welding/NDT phase ADR; nothing is built yet.
