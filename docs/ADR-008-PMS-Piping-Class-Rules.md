# ADR-EPCC-008 — PMS piping classes: size-dependent rules and the branch table

**Status:** PROPOSED — analysis only, nothing implemented
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

## 7. Open questions

1. **Do your PMS branch tables ever depend on anything besides the two sizes?** Some specify by
   *service* (a sour-service class forbids stub-ins) or by *schedule*. If so the resolver gains
   a third optional dimension — cheap now, awkward later.
2. **Reducing tees.** Is `6"×4" → TEE` distinct from `6"×6" → TEE` in your tables, or is the
   reduction implied by the two sizes being different? I assume implied, and store one
   `component_type` per cell.
3. **Class inheritance.** Some PMS documents define a base class plus deltas (`A1` and `A1S`
   differing in three rows). Worth supporting a `parent_class_id`, or is each class written out
   in full in your projects?
4. **Who may edit a published class?** I propose: draft → published (coverage-gated, §3.1), and
   a published class becomes read-only, with changes creating a new **revision** — because lines
   already numbered against it must not silently re-baseline. Same reasoning as certificates
   snapshotting their identifiers.

None of these block phase 1.

---

## 8. Build order

| Phase | Content |
|---|---|
| 0 | **ADR-007 reference packs** (sizes, schedules, end types) — prerequisite for DN conversion |
| 1 | `piping_classes` register + `piping_class_rules` + exclusion constraint + coverage gate + resolver with `NoRuleForSize` |
| 2 | `piping_class_branch_rules` + specificity resolver + `BranchLargerThanHeader` |
| 3 | Wire into ADR-006: derive `wall_thickness`, validate components against their class |
| 4 | Deviation reporting on import (the audit described in §5) |
| 5 | Class revisions and the publication lock (open question 4) |

**Nothing is implemented.** Phase 1 is small and self-contained; the spikes in §3 and §4.1 were
throwaway tables and have been dropped.
