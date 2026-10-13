# ADR-EPCC-008 — PMS piping classes: size-dependent rules and the branch table

> **2026-09-29 reconciliation:** Read §12 for the initial PMS entry UI and its open gaps. Whole-document revision/publication, bulk transcription and entered-unit provenance remain required; entry_version is not a published PMS revision.

**Status:** **ACCEPTED — phases 1, 2 and 3 BUILT (2026-09-23)**, then **AMENDED the same day: size rules are stored PER SIZE, not as bands (§10)**. **§11 (2026-09-24) records an agreed re-analysis against real PMS documents: the body axis, the P-T table, the header and SP items all change before phase 4. §11.13 amends it further: a rating is `(standard, class)`, not a string, and some classes (800) are computed rather than tabulated. **§11.16 then corrects §11.13's B16.11 paragraph.** **§11.17 adds wellhead scope (API 6A) and introduces the PIPING TYPE as a standards profile — the largest scope change here.** **§11.18 then corrects §11.17: the pipe stays NPS, and the wellhead work is much smaller than §11.17 claims.** **§11.19 audits API 5L, fixes a rotted cross-reference, and establishes that a PSL is a pair.** **§11.20 records that a piping MTO is often split into sub-MTOs, and the live reconciliation defect that follows — deferred by the user.** **§11.21 records build order steps 1, 2 and 2b as BUILT.** **§11.22 corrects §11.21's account of where the B16.5 numbers came from.** Read §9, §10 and §11 together; §1–§3 are superseded.** Phases 4 and 5 open.
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

## 9. What was actually built (2026-09-23)

**Phases 1, 2 and 3 are implemented and green: 39 tests.** The spikes of §3 and
§4.1 were re-run against this PostgreSQL 17 before any code was written, and all
four rows of the §3 table reproduced exactly — including the important one,
where the user's sentence transcribed literally as two inclusive bands is
rejected with a message naming DN100.

| Artefact | Contents |
|---|---|
| `…2026_09_23_100000_create_piping_class_rules.php` | both tables, the EXCLUDE constraint, the UNIQUE footprint, RLS, archive freeze |
| `Support/Piping/DnRange.php` | the half-open convention, in one place |
| `Support/Piping/PipingRuleKind.php` | 6 kinds, each declaring its payload key |
| `Support/Piping/Exceptions/{NoRuleForSize,BranchLargerThanHeader}Exception.php` | two faults, deliberately not one |
| `Models/PipingClass{Rule,BranchRule}.php` | + `rules()` / `branchRules()` on `PipingClass` |
| `Services/PipingClassRuleService.php` | write + resolve + coverage |
| `Services/PipingClassComplianceService.php` | phase 3: the audit |
| `tests/Feature/PipingClassRuleTest.php` | 29 tests |
| `tests/Feature/PipingClassComplianceTest.php` | 10 tests |

### Decisions taken during the build that this ADR did not anticipate

**`DnRange` exists because the convention must not live only in comments.**
PostgreSQL normalises whatever it is given — write `[15,100]` and it silently
becomes `[15,101)`. The boundary is therefore decided in exactly one class, and
`label()` prints the *inclusive* bound (`DN15–DN99`) because showing the stored
exclusive bound on screen would re-create the ambiguity the design removes.

**Write-time validation beyond what §3 described.** Three refusals were added
because each would otherwise produce a rule that looks stored and resolves to
nothing:
1. a payload that does not match its kind (`{"end_type":…}` on a schedule rule);
2. a band bounded by a DN that is not an ASME size (`DN17` to `DN63`);
3. **a schedule that does not exist across the whole band.** This is §6's
   two-layer check applied at write time, and the example is real and inside
   the user's own band: **SCH 160 is not made at 3½″ (DN90)**, so
   "½″ to 4″ SCH 160" is impossible even though it reads perfectly on paper.

**A rating and a schedule are alternative ways of stating strength.** The
compliance check asks the schedule question only of components whose wall is set
by one; a valve carries a pressure class instead. Read from the template pack
(`requiresRating`), not from a second hard-coded list that would drift. Without
this, every valve on every line would report a deviation, and a report full of
those is a report nobody reads.

**Four outcomes, not two.** `schedule.deviates` (designer to fix),
`piping_class.no_rule` (specification to fix), `piping_class.not_set`
(unjudgeable — saying VALID would imply an approval nobody gave) and
`schedule.unknown` (INVALID, physically unbuildable). Collapsing any pair sends
someone to read the wrong document.

**`STUB_IN` is a legal branch answer with no component behind it** — the branch
pipe is welded straight into the header. The component-type check allows it
explicitly rather than treating it as an unknown template.

### Still open

- **Phase 4** — deviation reporting wired into PCF/MTO import. `auditLine()` is
  the engine; nothing calls it during ingestion yet.
- **Phase 5** — class revisions and the publication lock (§7 #4). `coverage()`
  is the gate the lock needs, and it is built and tested; the state machine is
  not.
- **No screen.** Rules can only be created programmatically. The PMS arrives as
  a consultant PDF, so a transcription screen — with `uncoveredSizes()` shown
  live — is the natural next piece.
- `piping_classes.material` is still free text, unlinked to the material
  library. A `MATERIAL` rule kind is the obvious bridge and was deliberately
  not invented ahead of the user naming it.


---

## 10. AMENDMENT (2026-09-23) — no bands; one row per size

**User instruction:** «برای هر سایز لوله که در کلاس مورد نظر وجود دارند اسکجوئل
به صورت مجزا ثبت شود و از بازه استفاده نشود» — and, on how it is used: *the
user picks the pipe diameter, then picks its schedule.*

**This supersedes §1, §2 and §3 for `piping_class_rules`.** §4 (the branch
table) is unchanged.

### Why the original design was answering the wrong question

§1 opened with the four-inch ambiguity and built half-open ranges to resolve it.
That was a correct solution to a problem **the user does not have**, because a
PMS is not read as bands — it is read one diameter at a time. Storing bands made
the software's model disagree with the way the document is used.

The correction **removes the problem rather than solving it**:

| | bands (§3) | per size (this amendment) |
|---|---|---|
| four-inch ambiguity | solved by half-open encoding | **cannot arise** — no boundary exists |
| uniqueness | `EXCLUDE USING gist` + `btree_gist` | plain `UNIQUE (class, kind, dn)` |
| what a class covers | implied by band arithmetic | **exactly the rows it lists** |
| narrowing a class | rewrite boundaries, risk a gap | delete a row |
| revising one size | split a band into three | overwrite one row |
| schedule validity | checked across a whole band | checked **against its own size** |

That last row is a real improvement, not a wash. Under bands, "SCH 160 from ½″
to 4″" was refused because *somewhere* in the band a size failed. Now the
refusal says **SCH 160 is not made at 3½″** — the actual fact.

Notably, the **gap-detection machinery is gone entirely**: `range_agg`,
multirange parsing, the "is it contiguous" publication gate. There are no gaps
when a class simply lists its sizes. `coverage()` now answers the question that
is actually useful — *does this class cover the sizes the project uses?* — which
only the caller knows, so the required list is a parameter.

### Schema

`dn_range int4range` → `dn integer` + `nps varchar(16)`, both NOT NULL.

Both are stored on purpose: `dn` is the integer the lookup keys on, `nps` is the
label the engineer typed. `1 1/4` cannot be reconstructed from `32` without a
lookup, and a rule should be readable without one.

### API

| before | after |
|---|---|
| `addRule($class, $kind, DnRange, $value)` | `setRule($class, $kind, $nps, $value)` — upserts |
| — | `setRules($class, $kind, [$nps…], $value)` — transcription convenience |
| — | `sizesCovered($class, $kind)` — ascending by DN |
| — | `removeRule($class, $kind, $nps)` |
| `coverage()` → contiguity | `coverage($class, $kind, $requiredNps)` → what is missing |
| `uncoveredSizes()` | `uncoveredSizesInUse()` — sizes actually modelled but unspecified |

`setRules()` is **explicitly not a band**: it writes one independent row per
size, each correctable on its own afterwards. A PMS does often say "2 to 6 inch
SCH 40", and making someone type that six times is six chances to mistype it —
but what is *stored* is six rows. There is a test that changes one of them and
asserts the others do not move.

`setRule()` upserts, so re-stating a size is a **correction**, not a collision.
The `UNIQUE` index still stops two rows existing, and a test bypasses the
service with a raw insert to prove it — because a bulk import is exactly what
would.

### Test count unchanged: 39

The suites were **rewritten to the new intent, not deleted** — the fourth time
this rule has earned its place. `test_four_inch_resolves_to_exactly_one_schedule`
became `test_the_boundary_problem_cannot_arise`, which asserts that exactly one
row can speak for DN100.

`DnRange` survives, used **only** by the branch table, with its docblock
narrowed to say so.

---

## 11. ANALYSIS (2026-09-24) — what a real PMS is, and the four things we got wrong

**Status: agreed, NOT BUILT.** This section records a design conversation, not
code. Everything in §9 and §10 still describes what actually runs today. Read
this before writing phase 4.

The trigger was a question — "what do you actually know about PMS in the real
world?" — answered by reading three complete PMS documents (EIL 293 pp, GAIL
`SP-01`, Assam Gas `VCS-PL-PI-PMS-001`) rather than from memory. The comparison
was uncomfortable: the engine in §9/§10 is correct for the slice it covers, and
the slice is much narrower than the document it claims to model.

### 11.1 What a class sheet actually contains

```
PIPE CLASS      : A1A
RATING          : 150
BASE MATERIAL   : CARBON STEEL
CORROSION ALLOW : 1.5 MM
SPECIAL REQ     : Non IBR / Low Temp / NACE / Stress Relieved
SERVICE         : (free prose - which fluids this class is for)

TEMP  (degC)    -29    38    93   149   204   260   316   343   371
PRESS (kg/cm2)  19.33 19.33 16.17 14.41 13.35 11.95  9.84  8.78  7.73

BRANCH TABLE    (triangular matrix, letter codes)
NOTES           (numbered, referenced by row)
ITEM | SIZE | DESCRIPTION      <- the body
```

We model the branch table and a fragment of the body. We model none of the
header beyond three free-text columns.

### 11.2 The class code is a label, not data

The user's correction: the code is assigned by the design engineer, differs
between projects, and each segment carries project-specific meaning.

Therefore **nothing may be derived by parsing it.** `A1A` on one project and
`A1A` on another are not the same class. The truth lives in the header fields;
the code is an identifier in the project's namespace — which
`UNIQUE(project_id, code)` already gets right.

Because the segments do mean something, a per-project *code grammar* (segments
and their meanings) is worth holding in `project_piping_config` so the UI and
MTO can **explain** a code. Explaining is not deriving.

#### 11.2.1 ASME B31.3 slice 1 - a condition is not a service (2026-09-25)

**The user supplied ASME B31.3-2024.** The platform had leaned on it in four
places since step 4 - the material grouping, the fluid service category, the
wall thickness and the NDT percentages - without anyone having read it. This is
**slice 1 of 3**, chosen because it unblocks the PMS to ITP path the user named.

**`SEVERE_CYCLIC` WAS NEVER A FLUID SERVICE.** Clause 300.2 defines *"severe
cyclic conditions"* separately: *conditions applying to specific piping
components or joints for which the owner or the designer determines that design
and construction to better resist fatigue loading is warranted.* It sits
**alongside** the service, not inside it - a Category M line can be under severe
cyclic conditions and so can a Normal one. Holding it as a sixth value of one
enum made that ordinary combination **unrecordable**.

**And two real services were missing:** Elevated Temperature (300.2(c)) and
High Purity (300.2(e)). A class in either could not be stated at all.

So `piping_classes.fluid_service_category` now takes the **six** services B31.3
lists, and `severe_cyclic_conditions` is a boolean of its own. Rows already
carrying the old value are migrated rather than rejected: the service becomes
NULL - the sheet never said what it was - and the flag is set, which is exactly
what the sheet did say.

**CATEGORY D IS THE ONLY SERVICE THE PLATFORM CAN CONTRADICT.** It is the one
B31.3 defines with numbers: nonflammable, nontoxic, not damaging to tissue,
design gauge pressure **<= 1 035 kPa (150 psi)**, design temperature
**<= 186 C (366 F)**, fluid temperature **>= -29 C**. Every other service is a
judgement - *the owner determines*, *the engineering design specifies* - and
this engine has no standing to second-guess one. `CATEGORY_D_BOUNDS_EXCEEDED`
fires when a class calls itself Category D and its own design conditions say
otherwise; for every other service `categoryDViolations()` returns **null**,
which is a different answer from "checked and fine".

**The examination extent, from the Code instead of from habit.** 341.4.1 gives
Normal Fluid Service **at least 5 %** random radiography of circumferential butt
and miter groove welds per designated lot; 341.4.2 gives Category D **visual
examination only** - no percentage at all, and a percentage there is a Normal
figure that has wandered; 341.4.3 gives severe cyclic conditions **100 %**. The
extent is a **minimum**: 341.4.1 says "or to any greater extent specified in the
engineering design", so a stricter project specification is never in conflict.

Splitting the axis is what makes `examinationFor('CATEGORY_M', severeCyclic: true)`
answerable - 100 % radiography on a Category M line - which the old enum could
not express.

`fluid-service.ref.json` - `FluidService` - `FluidServiceTest` **10** -
`PipingClassHeaderTest` **26**.

**Not in this slice:** Table A-1 material groupings, the 304.1.2 wall thickness
equation, Table 341.3.2-1 acceptance criteria (the A-to-M letter matrix),
Chapter VIII (Category M), Chapter IX (High Pressure), and Tcr from Table
302.3.5-1. The pack says so itself, so the next reader does not assume B31.3 is
done.

### 11.2.2 ASME B31.3 slice 2 - the equation, and where the Code withdraws it (2026-09-25)

`PipingClassHeader` has been saying *"no B31.3 wall thickness can be
calculated"* since step 4 without anyone having read the clause. Slice 2 reads
it.

**The equation, clause 304.1.2:**

    t  = P*D / (2*(S*E*W + P*Y))     eq. (3a)
    tm = t + c

with every symbol defined in the pack, because an undefined symbol is an
invitation to guess. `tm = t + c` is an addition: a corrosion allowance is
metal permitted to disappear, not a factor on the design thickness.

**Table 304.1.1-1, coefficient Y** - five material families over eight
temperature bands, transcribed whole. Three things about it are easy to get
wrong and are now tested:

- the first column is **"482 C and below"** and the last **"677 C and above"** -
  open bands, not points. Read as points, an ordinary line at 20 C has no
  coefficient at all.
- Y **may be interpolated** for intermediate temperatures (the 304.1.2 symbol
  list says so). Ferritic steel at 524 C is 0.6.
- **gray iron is 0.0 and then blank.** The blank is the fact that gray iron is
  not used above 482 C, not a gap to fill.

**WHAT THE CALCULATOR REFUSES IS THE POINT OF IT.**

`WallThickness::calculate()` returns a refusal rather than a number in four
situations, and two of them are the Code withdrawing its own formula:

| refusal | why |
| --- | --- |
| `ALLOWABLE_STRESS_NOT_SUPPLIED` | Table A-1 is slice 3. S must arrive from a source the caller can cite. |
| `P_OVER_SE_ABOVE_0_385` | 304.1.2(b) withdraws the thin-wall equation there. |
| `THICK_WALL_T_AT_OR_ABOVE_D_OVER_6` | Same clause, other direction - and Y itself changes form. |
| `COEFFICIENT_Y_NOT_TABULATED` | Gray iron above 482 C, or a family the table does not list. |

**A pleasing result that fell out of testing it:** the two limits in 304.1.2(b)
are **one limit**. With Y = 0.4, `t/D = (P/SE) / (2(1 + 0.4·P/SE))`, and at
P/SE = 0.385 that is 0.1668 - which is 1/6. The Code states the same edge twice
because a designer may arrive at it from either direction.

**A claim checked in passing.** `materials.ref.json` has cited *"ASME B31.3
Table A-1 material groupings"* as its source since it was built, and the
library is keyed by **product form** rather than by metallurgy. Table A-1 is
indeed organised that way, in fourteen forms. The library's groups are a
coarser cut - it has `PIPE` where the Code separates "Pipe", "Pipe & tube",
"Smls. pipe" and "Wld. pipe" - because the distinction that matters to a
purchase order is not the one that matters to a stress table.

**WHY TABLE A-1 IS NOT HERE.** Its identification rows and its stress values
are printed in *separate page blocks* - thirty-five of them in the SI table
alone - joined only by a line number. A first parse recovered 55 of some 600
materials and mis-joined several. A wrong S produces a wrong wall thickness and
**nothing downstream can tell**. That is the most consequential number in the
Code, so it waits for slice 3 and a parse that asserts every line number
resolves to exactly one material and one contiguous temperature series.

`fluid-service.ref.json` v1.1.0 - `WallThickness` - `WallThicknessTest` **12**.

### 11.2.3 ASME B31.3 slice 3 - the allowable stress, and two gates (2026-09-25)

The last slice, and the number the other two were waiting for: **S**, the basic
allowable stress from Table A-1.

**THE TABLE IS A TRAP, AND THE FIRST TWO PARSES FELL INTO IT.** Table A-1
prints a material's identity and its stresses in *separate page blocks*, joined
only by a line number - and **that line number restarts in every material
section**. There is a line 1 in the iron section, another in carbon steel,
another in stainless. Joining on the number alone merged cast iron's 13.8 MPa
with carbon steel's 43.2 and produced stress curves that *rise* with
temperature.

Three faults were found by assertions rather than by reading:

| fault | symptom | fix |
| --- | --- | --- |
| line numbers restart per section | 40 duplicate lines, 29 rising curves | join on **(section, line)**, section advancing when an identification number goes backwards |
| the identification header changes between sections (carbon steel adds *Size* and *P-No.*) | 126 rows read of some 1 120 | anchor on the **spec number** instead of column position |
| stress values are printed as integers as well as decimals, and the header reads `No. to 40` | 194 stress rows read of 1 120, with a **stale temperature list** silently applied | accept integers; clear `temps` at every page break so a header that fails to parse yields **no** rows rather than wrong ones |

**TWO GATES BEFORE A VALUE MAY BE CITED.** Parsing is not verification, so a
value is `verified: true` only if

1. **the structural join held** - one identity per line per section, no
   conflicting value for a temperature, and a series that never rises; and
2. **it independently agrees with the Code's own basis**, para. 302.3.2,
   `S = min(UTS/3, two-thirds of yield)`, computed from tensile and yield
   figures held in `materials.ref.json` that came from the **ASTM
   specifications**, not from this table.

**77 of 798 materials clear both and are citable. 721 are held**, and
`stressAt()` returns null for them with `HELD_NOT_INDEPENDENTLY_CONFIRMED`.

**The strongest evidence that the join held is an absence.** Of the materials
with an independent check, **21 sit below the basis and none sits above it**. A
value below is ordinary - a class, a weld joint efficiency or a time-dependent
criterion governs instead. A value above would be impossible under the Code.
**A mis-join scatters in both directions**, so an all-below distribution is the
signature of a sound join and a stricter criterion, not of a parse error.

**The chain the three slices built now runs end to end.** NPS 6 A106 Gr B at
100 C, Class 150, 3 mm corrosion allowance:

    S  = 138.0 MPa      (slice 3, Table A-1)
    Y  = 0.4            (slice 2, Table 304.1.1-1)
    t  = 1.188 mm       (slice 2, eq. 3a)
    tm = 4.188 mm

Schedule 40 at NPS 6 is 7.11 mm, so an ordinary class is comfortable - which is
exactly the answer a reviewer expects, and exactly why a wrong S would have
been so hard to notice.

And when the material is held, **the chain stops rather than bending**: no S,
no thickness, and a refusal that names the missing input.

`allowable-stress.ref.json` - `AllowableStress` - `AllowableStressTest` **11**.

## 11.3 The P-T table is derived, not stored

The user's correction: the pressure-temperature rating is adapted from ASME
B16.5. So it must never be typed into a project.

B16.5 keys its rating tables on a **material group number** (Table 1A), not on
the specification: A105, A216 WCB and A350 LF2 are all Group 1.1; F304 is 2.1.
Table 2-<group> then gives pressure against temperature per class. Two hops:

```
grade  ->  B16.5 material group  ->  (group x class)  ->  P-T curve
           property of the grade      new reference pack
           (materials.ref.json)       (flange-pt-ratings.ref.json)
```

Hop one belongs beside `pNumber`, which grades already carry. Hop two is a pack
in the same shape as `pipe-schedules`.

Three constraints on that pack:

- **B16.5 stops at NPS 24.** 26" and above is B16.47 Series A or Series B, with
  different numbers. Our size pack runs to 48", so the pack needs a *standard*
  axis or it will answer confidently and wrongly above 24".
- **The flange table is a ceiling, not the answer.** Under B31.3 the weakest
  component governs; a class may be derated by a valve (B16.34), by an IBR
  restriction or by the wall calculation. The Assam Gas sheet shows a 150# CS
  class at 16.17 where Group 1.1 gives more. So: derive by default, allow an
  explicit override carrying a mandatory reason.
- **The numbers are not citable.** Public sources contradict each other on group
  membership — one places F316 in 2.2, another in 2.3. Every value enters with
  `verified: false`, per the standing rule for allowable stress.

Canonical pressure stays `kPa`; the published tables are bar and psi.

### 11.4 The body axis is (component type x qualifier x size)

The six `rule_kind` values are not one axis. They are a mixture:

| kind | what it really is |
| --- | --- |
| `PIPE_SCHEDULE` | an attribute of one component |
| `FLANGE_TYPE` | a qualifier on the flange component |
| `GASKET_TYPE` | a qualifier on the gasket component |
| `VALVE_TYPE` | a qualifier on the valve component |
| `END_PREPARATION` | a class-level policy affecting every component |
| `CORROSION_ALLOWANCE` | not a rule at all — a header field |

`component-templates.ref.json` already holds the correct axis: 22 codes with
qualifiers (`FLANGE` -> WN/SO/BL/SW/THD/LJ, `VALVE` -> GATE/GLOBE/BALL/...,
`ELBOW` -> 90LR/90SR/45/180LR). That list *is* a PMS item list. We built the
axis and then did not use it — the same failure as the material library.

A body row becomes:

```
(class, FLANGE, WN,   6") -> {spec:A105, form:FORGED, dimStandard:B16.5,
                              rating:150, end:BW, facing:RF}
(class, PIPE,   null, 6") -> {spec:A106, grade:B, form:SMLS,
                              dimStandard:B36.10M, schedule:40, end:BE}
```

`spec/grade/form` resolve against `MaterialLibrary` — the gasket pattern.

**`VALVE_TYPE` is a misreading.** Its docblock says "which valve the class wants
at this size (ball small, gate large)". A PMS does not choose valve function;
the P&ID does. A PMS states which valve types are *permitted* in the class
(absence from the sheet means not permitted) and, for each, its construction per
size. Both fall out of the new axis for free.

**`END_PREPARATION` survives** as the one genuine class-level rule. "SW below
1.5in, BW at 2in and above" must hold for every component at that size or the
joint cannot be made. PIP calls it *joint construction*. It stays at class
level, per size, and each component row's `end` is validated against it — a
policy and its observance, not two sources of truth.

**Schema consequence.** One size carries several rows of the same component type
(flange WN *and* flange BL; valve GATE *and* valve CHECK), so the key becomes
`(piping_class_id, component_type, qualifier, dn)`. Trap: in PostgreSQL two rows
with `qualifier IS NULL` do not collide under a plain `UNIQUE`, so `PIPE` would
silently admit duplicates. PG 17 gives the clean fix:

```sql
UNIQUE NULLS NOT DISTINCT (piping_class_id, component_type, qualifier, dn)
```

### 11.5 Corrosion allowance has two homes; one must go

`piping_classes.corrosion_allowance` (a `string(16)`) and
`PipingRuleKind::CORROSION_ALLOWANCE` both exist, and nothing reconciles them.
The enum's justification — "can legitimately vary with size" — was assumed by
the author, not stated by the user, and is wrong: CA is corrosion rate times
design life, both properties of the service, not of the diameter. A different CA
is what makes a different class (A1A at 1.5, A4A at 3.0).

**Confirmed by the user, 2026-09-24: a single class never carries different CA at
different sizes.** The rule kind goes; the header column stays and should become
`decimal corrosion_allowance_mm` — a number that feeds a B31.3 calculation, not
a string that accepts "3", "3 mm" and "1/8 in" alike. The rule kind has zero
callers outside its own definition, so removing it breaks nothing.

### 11.6 Header fields we have no home for

- `service_en` / `service_fa` — which fluids
- `special_requirement` — NACE, IBR, LTCS, stress relieved, HIC
- `design_temp_min` / `design_temp_max`
- **`fluid_service_category`** (B31.3: Normal, Category D, Category M, High
  Pressure, Severe Cyclic) — new, and load-bearing: it is what explains why one
  class takes 5% random radiography and another 100%. Without it the NDT
  percentages are unexplainable numbers.
- numbered notes, referenced from body rows

### 11.7 Revision: the whole document, and the register already does it

**User decision: the PMS revises as one document, and there is one PMS per
project.**

No new mechanism is needed. `documents` -> `document_revisions` ->
`document_files` exists with `cde_state` and RLS sharing, and ITP was registered
the same way (`2026_09_19_210000_register_itp_as_a_document.php`). PMS follows
that precedent. Classes hang off a revision; the standing constraint "a
published class locks" is then derived from document state rather than
separately enforced.

Copy-on-write per revision is affordable: ~50 classes x ~20 component types x
~20 sizes is ~20,000 rows a revision, ~200,000 over ten. That is nothing.

**User decision: lines already AFC stay pinned to the revision they were built
to, and are flagged as impacted when a new revision is issued.** They are not
migrated forward.

### 11.8 NDT belongs in the PMS and feeds the ITP

**User decision: NDT lives in the PMS.**

`itp_stages` already has exactly the columns an NDT requirement needs —
`method` (VI/DI/UT/MT/PT/RT/PMI/LT/FT/DR), `frequency_en`, `acceptance_en`,
`reference`. The mapping is close to one-to-one.

Direction, per the user's standing rule against a second source of truth: **the
PMS states, the ITP cites.**

### 11.9 Speciality (SP) items — in scope, plus manual valves

**User decision: SP items are in scope now, and non-automatic (manual) valves
are to be added.**

Manual valves in the PMS finally consume `VALVE_TRIM` (6 specs / 43 trims), the
third asset built and never wired. A manual valve row needs body (`CASTINGS` or
`FORGINGS`, body only per the standing constraint), trim (`VALVE_TRIM`),
rating, ends, construction standard (API 600/602/6D, BS 1868) and **operator**
(handwheel / lever / gear / chainwheel). Operator is per size — a gearbox
becomes mandatory above a diameter — so it sits on the new axis unchanged.

This yields a clean boundary and does **not** reopen the §5ag deferral: if it
has an actuator it is a datasheet; if it does not, it is a PMS row. The actuator
pack is the discriminator. Control valves, PSV/TSV and the valve function-tag
axis remain deferred.

**BUILT, 2026-09-25.** `piping_class_valve_specs` — a 1:1 side table on the body
row, not four more columns on `piping_class_items`, because a trim, a
construction standard and an operator are NULL on every pipe, flange, elbow and
gasket row in the system. The discriminator is enforced by the schema and not by
a comment: `operator` is CHECKed to `HANDWHEEL / LEVER / GEAR / CHAINWHEEL`, and
there is no actuator column and no fail-safe column at all, so an MOV cannot be
written into a class body.

Three references answer the three independent questions, and two of them had
been sitting unwired since the rounds that built them:

| question | source | state before this round |
| --- | --- | --- |
| body | material library, groups `CASTINGS` / `FORGINGS` | wired (§11.12 step 5) |
| trim | material library, group `VALVE_TRIM` | **built, never consumed** |
| operator | `actuators.ref.json`, the `power: MANUAL` half | **built, never consumed** |
| construction standard | `valve-construction.ref.json` | **new — nothing held it** |

The new pack holds 13 standards (API 600/602/603/594/599/608/609/623/6D,
BS 1414/1868/1873, ISO 15761), each with the valve types it covers, the body
product forms it implies and its NPS window. Every row carries
`verified: false` — none of these standards is in the repo — so a finding built
on a size scope says so in its own message rather than quoting it as the
standard's requirement. `ValveConstruction::scopeIsCitable()` exists so the day
a copy of API 600 is bought, one data change makes the findings citable and no
code changes.

`ManualValves` reports 15 codes and refuses nothing (§11.10). Two are worth
recording as decisions rather than as code:

- **No gearbox threshold is hardcoded.** "Gear operator above NPS 8" is real in
  practice and different in every project specification, and this repo has
  already been bitten by a validator rule that encoded the author's habit rather
  than a standard. Instead `operatorConsistency()` compares a class **against
  itself**: if the 6 inch gate valve in this class is geared, the 12 inch one
  cannot be a bare handwheel. Nothing outside the document is consulted, so the
  rule cannot be a rule about the transcriber.
- **A check valve takes no operator, and that falls out of the data.** The
  actuator pack holds no travel for `CHECK`, so `travelOf()` returns null and
  the absence is correct rather than a special case in code. The mirror of the
  same fact catches a handwheel on a ball valve: quarter turn against multi
  turn is mechanical, not a preference.

`CHAINWHEEL` was added to `actuators.ref.json` (v1.1.0) for this round. It is
exempt from the travel test on purpose: a chainwheel is not a way of making
torque, it is a way of REACHING a valve mounted out of arm's length, and it
hangs off whatever operator is underneath.

`ManualValveTest` — **42 tests**.

#### 11.9.1 The valve is rated by B16.34, not by the flange standard (2026-09-25)

**The user supplied ASME B16.34-2025.** It closes the gap named first in the
standards review: until this round the platform held B16.5 and B16.47 and
nothing else, so a valve's pressure–temperature envelope was being read off the
**flange** tables. That is the commonest specification error in piping, and it
is wrong in three concrete ways:

| | B16.5 (flange) | B16.34 (valve) |
| --- | --- | --- |
| Class 400 | rated | **not tabulated at all** |
| Class 4500 | absent | **rated** |
| Special Class | no such concept | **a second, higher envelope** for valves that pass extra NDE |

`valve-pt-ratings.ref.json` — **100 curves** (50 material groups × Standard and
Special), **2,002 temperature points**, SI only, all `verified: true`. The U.S.
customary tables are the same curves re-rounded and are deliberately not
transcribed: two copies of one fact is two answers.

**The corroboration, and what it cost to check.** B16.34 Standard Class is
supposed to reproduce the flange ratings for the same material group. Against
the already-verified B16.5 pack it does so in **4,039 of 4,050** comparable
cells. The eleven that differ are listed in `crossStandardDiscrepancies`, and
they are the reason the pack had to be transcribed rather than aliased.

**Four cells of B16.34-2025 do not survive arithmetic.** All four are digit
transpositions in the high classes, and all four were found by validator rules
rather than by reading:

| group | rating class | °C | class | printed | corroborated |
| --- | --- | --- | --- | --- | --- |
| 1.18 | Standard | 500 | 1500 | 106.6 bar | 160.6 bar |
| 3.19 | Standard | 450 | 2500 | 205.9 bar | 295.9 bar |
| 2.1 | Special | 538 | 4500 | 462.2 bar | 426.2 bar |
| 2.9 | Special | 425 | 4500 | 587.7 bar | 538.7 bar |

Each is stored **as printed** — a transcription that silently corrects its
source stops being a transcription — and each carries `doNotCite` plus the
evidence for calling it an error. `ratingAt()` returns null for those cells and
`refusalFor()` says `CELL_WITHHELD_AS_SUSPECTED_PRINTING_ERROR`; an
interpolation that would span one is refused too. This is the `verified: false`
principle applied one cell at a time.

Two of the four arbitrate each other: groups 1.18 and 3.19 are printed with the
same Standard Class curve from 38 °C to 600 °C, and each group's bad cell is
contradicted by the other's good one.

`ValvePtRatings` refuses rather than guesses in four distinct ways
(`MATERIAL_GROUP_UNKNOWN`, `PRESSURE_CLASS_NOT_TABULATED`,
`ABOVE_THE_LAST_TABULATED_TEMPERATURE`, `CELL_WITHHELD_…`) plus
`LIMITED_CLASS_IS_COMPUTED` — Mandatory Appendix V derives Limited Class per
valve from a stress area, so there is no table and a bare null would read as
missing data.

`groupFor('A182')` returns **null**, not the first group found: A182 runs from
F1 to F91 across a dozen groups, and a plausible-looking wrong curve is worse
than no curve. That distinction produced a second one in `ManualValves` —
`BODY_NOT_IN_B1634` (the standard has never heard of this spec, a DISCREPANCY)
is now separate from `BODY_GRADE_NOT_STATED` (A216 is there twice, as WCB in
group 1.1 and WCC in 1.2, and the row does not say which — INCOMPLETE).

Five new findings on a valve row: `RATING_ABSENT`, `RATING_NOT_A_VALVE_CLASS`,
`BODY_NOT_IN_B1634`, `BODY_GRADE_NOT_STATED`,
`DESIGN_TEMPERATURE_ABOVE_THE_CURVE`, and `MATERIAL_NOTE_LIMITS_TEMPERATURE` —
the last because "not to be used over 371 °C" sits under a table that keeps
printing numbers above 371 °C, so the note has to travel with the material or
the grid over-rates the valve. **This is the first check in the system that puts
a material, a pressure class and a design temperature together and asks whether
the valve can actually hold.**

#### 11.9.2 The length, from B16.10 (2026-09-25)

**The user supplied ASME B16.10-2022.** A rating is not a drawing: B16.34 says
a Class 600 gate valve in A216 WCB holds 102 bar at 38 °C, and B16.10 says it
is 559 mm long. The second number closes an isometric and sets the cut length
of the pipe either side of the valve, and the platform had no source for it.

`valve-face-to-face.ref.json` — **1,833 dimensions**, 6 pressure classes, 11
valve types, plus **267 ring-joint adjustments** (Table 3.3-1) and **12 facing
rules** (Table 3.2-1).

**Scope: steel only.** The Class 125/250 cast-iron and AWWA columns were read
and discarded — 478 cells. B16.34, which supplies the rating, does not cover
cast iron and the material library holds no cast-iron valve body, so a length
for one could never be paired with anything.

**Four symbols, not one number.** `A` face-to-face flanged, `B` end-to-end
buttwelding, `D` centre-to-face and `E` centre-to-end for angle bodies, which
have no face-to-face because they turn a corner. A column headed "A and B"
prints one figure serving both, so two rows are emitted from it.

**One column can serve several valve types.** "Globe, Lift Check, and Swing
Check" is one column: at Class 600 NPS 6 all three are 559 mm, by the standard
and not by coincidence. `valveTypes` is therefore a list.

**The finding that changed the model.** A **lift check appears twice** in Table
1.5-1 — with the globes as an in-line body measured face-to-face (symbol A,
559 mm) and with the angle valves measured centre-to-face (symbol D, 279 mm).
Same valve type, same class, same size, two bodies, 280 mm apart. `lengthFor()`
returns null and `refusalFor()` says `SEVERAL_BODY_STYLES_OFFERED` rather than
picking one.

**Nineteen printed irregularities, all declared, none corrected.** Sixteen
places where a valve gets *shorter* as it gets bigger and three cells where the
millimetre and inch columns disagree beyond rounding. Two have the standard's
own explanation: Table 1.3-1 column 9 takes its large sizes from **API 6D**
(note 14), so that column is two standards spliced together; and Class 900
valves NPS 2½ and smaller carry **Class 1500** dimensions by note (2) of Table
1.5-2, which makes the small end of the column longer than the sizes above it.

**The erratum.** ASME issued a March 2023 erratum for misaligned column
headings on pages 13 and 21. The corrected pages are bound at the **front** of
the PDF, ahead of the title page, so a naive parse reads them first and then
overwrites them with the faulty originals. They are parsed last, and compared:
**0 cells differ**, so the erratum changed headings only — which is what ASME
said — and the column map was read from the corrected pages.

**Cross-pack check.** Every class dimensioned here is one B16.34 rates, and
`pipe-sizes` supplies the DN↔NPS vocabulary (the validator caught this pack
spelling NPS 1½ as `1-1/2` where the platform spells it `1 1/2`). One class is
rated and not dimensioned: **4500**. A valve can be perfectly rateable and not
drawable, and that is now sayable.

Three more findings on a valve row: `NO_TABULATED_LENGTH`,
`PATTERN_NOT_STATED` and `DIMENSIONAL_STANDARD_DOES_NOT_COVER_IT`. The middle
one matters most in practice — B16.10 routinely offers two lengths (a Class 600
gate valve is 559 mm long or 457 mm short) and a PMS row naming neither has not
specified the valve. The pattern is looked for in `description_as_written`
first, because that is where a real sheet puts it, and the finding is only
raised when the row cites B16.10 for itself.

`ValveFaceToFaceTest` — **15 tests**. `ManualValveTest` — **46**.

#### 11.9.3 The tests, from API 598 (2026-09-25)

**The user supplied API Standard 598 (2023).** It is the third leg of the
valve: B16.34 rates it, B16.10 measures it, API 598 says what has to be
witnessed before anyone accepts it. **This is the first reference pack in the
platform that produces ITP rows rather than drawing figures.**

`valve-testing.ref.json` — **60 requirement rows** (Table 1) over 6 test
columns, **10 shell-pressure rules** (Table 2), **13 closure-pressure rows**
(Table 3), **5 duration bands** (Table 4), **22 leakage rows** (Table 5) plus
the formulas that take over above NPS 48.

**The shell test pressure is not printed in API 598.** For a steel valve Table
2 gives a *rule*: 1.5 × the pressure rating at 38 °C, rounded **up** to the
next whole bar. The rating is in ASME B16.34, which the platform now holds, so
`ValveTesting::shellTestPressureBarG()` computes it — **the first calculation
in the system that needs two purchased standards at once**:

| class | B16.34 rating at 38 °C (group 1.1) | × 1.5 | shell test |
| --- | --- | --- | --- |
| 150 | 19.6 bar | 29.4 | **30 barG** |
| 300 | 51.0 bar | 76.5 | **77 barG** |
| 600 | 102.0 bar | 153.0 | **153 barG** |
| 1500 | 255.1 bar | 382.65 | **383 barG** |

Without a material group there is no answer, and `shellTestPressureBarG()`
returns null rather than a figure that would be right for group 1.1 and wrong
for the other forty-nine.

**Two axes the PMS does not have.** API 598 tests a **floating** ball valve
differently from a **trunnion mounted** one, and an API 609 **Category A**
butterfly differently from a **Category B**. A PMS row says "BALL" and stops.
`testsFor()` returns nothing and `refusalFor()` says `VARIANT_REQUIRED`, naming
the choices — the gap made visible rather than guessed past. A gate valve, by
contrast, has a column of its own, so "parallel slide gate" is an extra route
into the globe column and not a missing answer: `requiresVariant('GATE')` is
false and nobody is nagged about ordinary gate valves.

**Two traps inside Table 1**, both now asserted by tests:

1. **The high- and low-pressure closure rows invert.** Wherever the low-pressure
   test becomes optional, the high-pressure one becomes required. They are one
   decision written twice, and a copy-paste between them would be invisible.
2. **The class threshold moves with the size.** The closure rows split at Class
   1500 for DN ≤ 100 and at Class **600** for DN > 100. A Class 900 valve is on
   opposite sides of the line at NPS 4 and NPS 6. Reading it as a single
   boundary mis-states every large valve between Class 600 and 1500.

`leakageFor()` distinguishes a check valve (mL/min) from everything else
(drops/min) — different columns of Table 5, and reading across the wrong pair
is an order-of-magnitude error on an acceptance criterion. A **resilient seat is
zero at every size**, because that is what the seat type means rather than a
tabulated rate. Above DN 1200 the table stops and the standard's formulas take
over; extrapolating the table instead would be a different, wrong answer.

`ValveTestingTest` — **18 tests**.

**Next: wire it into the ITP (ADR-015).** The pack now answers "which tests, at
what pressure, for how long, to what leakage" for any valve in the PMS; the ITP
service does not yet ask.

#### 11.9.4 API 600 arrives, and corrects three guesses (2026-09-25)

**The user supplied API Standard 600 (2021).** It is the first purchased copy
that is a **109 dpi scan with no text layer**, and that changes what can be
taken from it: the prose OCRs almost perfectly, the landscape numeric tables do
not. So this round verifies the **scope** and transcribes **no tables at all**.

**The verified:false discipline paid for itself.** Three of our guesses about
API 600 were wrong, and each was producing a false finding:

| field | guessed | actually | what it was doing |
| --- | --- | --- | --- |
| `bodyGroups` | `[CASTINGS]` | `[CASTINGS, FORGINGS]` | flagging every legitimate A105 **forged** gate valve as `BODY_FORM_AGAINST_STANDARD` |
| `npsMin` | 2 | **1** | putting every NPS 1–1½ API 600 valve out of scope |
| `npsMax` | *(open)* | **42** | accepting an NPS 48 gate valve API 600 does not cover |

The forged-body error is the instructive one. The guess — "API 600 is cast,
API 602 is its forged small-bore counterpart" — is what every piping engineer
says, and it is wrong: **Table D.1 gives the Group 1.1 body as "C-Si A105 or
A216-WCB"**, a forging and a casting in one row, and 7.3 speaks of "the shell of
a cast or forged, carbon or alloy steel valve". The old value is kept in
`correctedFromGuess` rather than deleted, because the point of the record is to
show which findings the platform was raising wrongly and from which field.

**Verified from clause 1:** bolted bonnet, outside screw and yoke, rising stem,
non-rising handwheel, single or double gate, wedge or parallel seating, metallic
seating surfaces, flanged or butt-welding ends; **DN 25–1050 (NPS 1–42)**;
**classes 150, 300, 600, 900, 1500, 2500**. `scopeIsCitable('API 600')` is now
**true** — the first of the thirteen — and its out-of-scope findings no longer
append "scope read from the standard title, not from a purchased copy".

**New concept: `sourceQuality`.** Owning a standard is not the same as being
able to read it, and the pack now distinguishes `NOT_HELD`, `TEXT` and
`SCANNED_IMAGE`. API 600 is `SCANNED_IMAGE`, and `tablesNotTranscribed` names
the seven tables that were deliberately left alone — minimum wall thickness,
wear travel, stem diameter, packing width, seating/stem/backseat materials,
trim numbers, and the Annex D material combinations — with the reason (*"a
figure that is wrong in the third digit is indistinguishable from a right
one"*) and what would fix it (*a text-layer PDF, or a scan at 300 dpi or
better*).

**The trim chart stays unverified.** `VALVE_TRIM` still carries 20 API 600 trims
at `verified: false`. Table 9 is exactly the table OCR cannot be trusted on, and
a trim number attached to the wrong seating material is a procurement error
wearing a standard's name.

`ManualValveTest` — **50 tests**, including three that assert the corrected
behaviour and two rewritten because they had encoded the wrong guess.

#### 11.9.5 A readable API 600, and the trim chart stops being folklore (2026-09-25)

**The user supplied a text-layer copy of API 600-2021.** The scan verified the
scope; this verifies **Table 8**, which is the chart the whole valve module
turns on.

**The industry chart had 20 trims. Table 8 has 30.** Missing from ours: **5B**
and the entire nickel-alloy family **19, 19A, 19B, 19C, 20, 20A, 20B, 20C, 21**
— Alloy 625, C276 and 825, which are ordinary selections on sour and seawater
service and simply could not be recorded.

**Trim 1 is obsolete.** Table 8 prints "TRIM NUMBER 1 IS OBSOLETE" across the
row. Until the standard was read, the platform offered it as a normal choice.
It is now **held but not offered**: `trimsFor('GATE')` returns 29,
`trimsFor('GATE', true)` returns 30, and a row naming it raises
`TRIM_WITHDRAWN` — "the standard dropped this" rather than "we have never heard
of it", which is a different instruction to a transcriber. Deleting it would
have turned a reportable fact into an unknown trim.

**Table 9 adds substitution.** Specify trim 10 and the manufacturer may supply
12 or 16; specify 8 and they may supply 5. The relation runs **one way** and is
recorded as such.

**Two more inventions exposed.** The old chart gave trim 9 a stem of *ASTM B564
N04400* and trim 6 a seat of *ASTM B151*. Table 8 gives trim 9's stem as **MFG
Standard** — the standard defers to the manufacturer — and trim 6's second
seating surface as Cu-Ni per note (k), with no specification at all. Plausible
inventions are the hardest kind to notice, and both had survived a full test
suite.

**The shape changed because the standard's shape is different.** The old model
split a trim into **seat, disc, backseat, stem** — our invention. Table 8's
columns are **seating surface** (one concept covering the seat ring and the
wedge face, which is why a trim can name two of them) and **stem/backseat
bushing** as a single column group with one material type and two hardness
columns. `requiredParts` for API 600 is now
`['seatingSurface', 'stem', 'backseatBushing']`.

A seating surface also resolves through **up to three routes** — cast integral,
forged integral, weld deposited — so the property is `seatingSurfaceSpecifications`,
plural. Collapsing them into one would force a choice the standard leaves to
the manufacturer.

**Two sentinels are now first-class.** `MFG Standard` (the standard declining to
name a specification) and `WITHDRAWN` (an obsoleted trim). Both are carried
through the specification *and* the grade so they cannot be mistaken for data,
and the validator checks that pairing.

`valve-construction` API 600 is now `sourceQuality: TEXT`, and
`tablesNotTranscribed` changes meaning: the remaining tables are dimensional
and bolting data **nothing asks for yet**, not data that cannot be read.

`ManualValveTest` **55**, `MaterialLibraryTest` **108**. Wider run **530 passed
/ 9,394 assertions**.

#### 11.9.6 API 602, and Class 800 stops being a formula off the web (2026-09-25)

**The user supplied API Standard 602, 10th edition (2015)** — the small-bore
companion to API 600. Two things came out of it, and the second is the larger.

**The cast/forged folklore is now wrong at BOTH ends.** Our unverified entry
said API 602 meant a forged body. **Table 1a lists castings — A216 WCB, A352
LCC, A351 CF8M — beside the forgings.** API 600 said the same thing last round.
Two standards checked, two single-form claims collapsed, and six entries in the
pack still carry one. So `BODY_FORM_AGAINST_STANDARD` now **discloses in its own
message** whether the claim behind it is a citation or a habit — the same
treatment the size-scope finding already had.

Also corrected: `npsMin` was open, and clause 1 starts at **DN 8 (NPS ¼)**.
Confirmed unchanged: gate, globe **and** check; NPS 4 ceiling.

**CLASS 800 IS NOW CITED, NOT GUESSED.** The platform derived it as
`1/3 × Class 600 + 2/3 × Class 900` from public sources, `verified: false`.
API 602 **clause 4.1.3** defines it: the ratings *"are a linear interpolation of
Standard Class 600 and Standard Class 900 pressure/temperature ratings listed in
ASME B16.34"*. Class 800 sits two thirds of the way from 600 to 900 — the same
weights. **The guess was right and is now a citation.**

Two further things fell out of reading the clause:

- **The base standard was wrong.** The entry said `appliesToStandard: B16.5`.
  Clause 4.1.3 says **B16.34**. A Class 800 *valve* was being derived from the
  *flange* tables — the same error, in miniature, that `valve-pt-ratings` exists
  to correct.
- **API 602 cites B16.34 undated**, and says that for undated references the
  latest edition applies. So for a project today the governing Class 800 rating
  is the interpolation of **B16.34-2025**, *not* the figure printed in API
  602-2015 Table 2a. The printed table is a snapshot; the rule is the
  requirement.

**Corroboration.** Applying the clause to our verified B16.34 group 1.1 against
API 602's printed Table 2a: **14 of 16 rows within 0.023 MPa**. The two that
differ — 50 °C (13.273 computed vs 13.37 printed) and 500 °C (3.073 vs 3.14) —
are declared, and are consistent with B16.34 having moved in the decade between
the two documents rather than with an error on either side.

The validator rule that blanket-refused `verified` on a derived class was
relaxed on the same terms as the curve rule before it: a derived class may claim
it only if it names the clause, the date read, and the standard it derives from.

**Four tests had encoded the old guesses** and were rewritten — including one
that had already been rewritten once, from API 600 to API 602, and was wrong
both times. It now asserts the *disclosure* rather than the claim.

`ManualValveTest` **57**, `ValvePtRatingsTest` **21**. Wider run **534 passed /
9,409 assertions**.

#### 11.9.7 API 603, and the field that was asking the wrong question (2026-09-25)

**The user supplied API Standard 603 (2025)** — the corrosion-resistant cut of
the gate valve. Verified from clause 1: **NPS ½ to 24**, **classes 150, 300 and
600 only**, full port, bolted bonnet, OS&Y. It stops where API 600 keeps going
(NPS 42, Class 2500), and that ceiling is now checkable.

**The third single-product-form claim collapsed** — clause 7.3 again says *"the
shell of a cast or forged valve"*. Three standards read, three collapses. But
API 603 makes the more useful point: **it does not restrict the product form at
all.** Table 4 gives the body as *"As selected from ASME B16.34, **Group 2 and
Group 3**"*.

That is what "corrosion-resistant" in the title means, stated as something a
machine can test — and the platform can test it, because `valve-pt-ratings`
holds all fifty B16.34 groups. So `bodyMaterialGroups` was added beside
`bodyGroups`, and a new finding
**`BODY_MATERIAL_GROUP_AGAINST_STANDARD`** resolves the row's spec and grade to
its B16.34 group and checks the family: A216 WCB is group **1.1** and therefore
outside API 603 *however it was made*, while A351 CF8M is **2.2** and ordinary.

**The lesson is about our own model, not the standard.** `bodyGroups` asks which
product form a standard permits. Three standards in, the answer is always
"both", and for API 603 the question was simply the wrong one. A field that
keeps returning the same answer is usually measuring the wrong thing.

**A cross-pack link fell out too.** Clause 7.2: *"Each valve shall be pressure
tested as specified in API 598."* `testedTo('API 603')` now returns `API 598`,
so the construction pack and the testing pack meet without anyone hardcoding
the join.

**Trim vocabulary recorded, not duplicated.** API 603 Table 5 uses the API 600
trim numbers but only **24 of the 30** — 5B and the 20/20A/20B/20C/21 hardfaced
nickel family are absent — and Table 6 offers **three** substitutions where API
600 Table 9 offers six, because the 13Cr-based swaps have no place on a
corrosion-resistant valve. That is held as a `trimVocabulary` note on the entry
rather than as a second copy of the chart: re-transcribing it would create two
answers to one question.

`ManualValveTest` **61**. Wider run **538 passed / 9,419 assertions**.

#### 11.9.8 API 608: a valve is not its shell (2026-09-25)

**The user supplied API Standard 608 (2020).** The largest correction of the
whole valve sequence came out of clause 4.1:

> *"The valve pressure-temperature rating shall be the lesser of the shell
> rating or the seat and seal rating."*

Until now the platform read the shell rating out of B16.34 and called it the
valve rating. For a soft-seated ball valve that is not a rounding error. A
**Class 600** group 1.1 shell is **102 bar** at 38 °C; the same valve with a
**PTFE seat above DN 100 is 19.7 bar** — a factor of five, in the direction that
buys a valve for five times the pressure it can hold.

`valve-pt-ratings` gains **`seatRatings`** — API 608 Table 1, 8 curves and 52
points, PTFE and R-PTFE × floating and trunnion × three size bands — and
**`governingRatingAt()`**, which returns the lesser of shell and seat with a
`governedBy` flag. A metal seat is unaffected: Table 1 does not cover it, so the
pack returns the shell and says so rather than implying anything.

**`SEAT_GOVERNS_THE_RATING`** reports the gap on a PMS row, with both figures in
the message.

**THE API 598 ARC CLOSES.** `ValveTesting::testsFor('BALL', …)` has been
refusing with `VARIANT_REQUIRED` since the API 598 round, because API 598 tests
a floating ball differently from a trunnion mounted one and a PMS row says only
"BALL". **API 608 clause 1.4 is where that vocabulary comes from** — *"floating
(seat-supported) ball (Figure C.1) and trunnion ball valve designs (Figure
C.2)"*. `ValveConstruction::designVariants('API 608')` now returns exactly the
two names `ValveTesting::variantsFor('BALL')` asks for, and a test asserts the
two packs agree. The gap became **`BALL_DESIGN_NOT_STATED`** — reportable
instead of silent.

**A scope shape the model did not have: size and class depend on the END TYPE.**
Clause 1.1/1.2 — flanged and butt-welding run NPS ½–24, socket-welding and
threaded stop at **NPS 2**; and flanged stops at **Class 600** while the other
three reach **Class 800**. One `npsMin`/`npsMax`/`pressureClasses` triple cannot
say that, so `endTypeScopes` carries it and the flat fields hold the union.

Note the mirror with API 602, where flanged ends existed at every class *except*
800. Two standards now say the same thing from opposite directions: **Class 800
is a non-flanged, compact-valve class.**

Clause 4.2 sends Class 800 shell ratings to **API 602 (2016) Tables 2a–2f** — a
second standard pointing at the source the Class 800 derivation was verified
against last round. *(We hold API 602-2015; the citation is dated 2016 and that
difference is recorded rather than assumed away.)*

`ManualValveTest` **66**. Wider run **543 passed / 9,435 assertions**.

#### 11.9.9 API 609: the valve with no ASME class (2026-09-25)

**The user supplied API Standard 609 (2021).** It completes the valve
construction set and contributes the sharpest category distinction yet.

**Category A butterfly valves have no ASME pressure class at all.** Clause
1.2 a): they are *"manufacturer's rated cold working pressure (CWP)"* valves
carrying a class **125 or 150 flange BOLTING PATTERN** — which looks like a
class and is not one. Category B is the ASME class and P-T rated valve, with an
offset seat. Any code that reads a Class 150 shell rating for a Category A valve
has invented a figure the standard never claimed, so `ratingBasis` records the
difference per variant and the seat table refuses Category A outright.

**Both halves of the API 598 gap are now closed from the construction
standards.** API 598 note (h) says *"Butterfly Category A and Category B
definition according to API 609"* — and `designVariants('API 609')` now returns
exactly what `ValveTesting::variantsFor('BUTTERFLY')` asks for, as API 608 did
for the ball designs last round. A test asserts each pair agrees.

**The seat table gained a second shape.** API 608 keys a ball seat on the ball
design and a DN band; **API 609 Table 1 keys a butterfly seat on the pressure
class.** Same idea, different axes, so a seat curve now carries a
`discriminators` map instead of fixed fields, and a caller that does not supply
them gets null rather than whichever curve matched first. **Class 600 has an
R-PTFE column and no PTFE column** — the standard declines to rate PTFE there,
which is an answer and not a missing cell.

`seatRatings` is now 13 curves: 8 ball (API 608) and 5 butterfly (API 609).

**A win NOT claimed.** The tempting story was "a fourth single-product-form
claim collapses". It did not: API 609's `bodyGroups` was **already null** — the
entry never claimed a product form. The validator caught the overstatement
(*"records a correction to bodyGroups that changed nothing"*) and the entry now
says so explicitly. Clause 1.1 does give it the widest material scope of the
five read: gray iron, ductile iron, bronze, steel, nickel-based and special
alloys.

**A gap in OUR vocabulary, surfaced rather than hidden.** API 609 reaches
**NPS 60** and `pipe-sizes` stops at **NPS 48**. Rather than truncate the
standard to fit the pack, `npsMaxBeyondPipeSizes` declares it and the validator
reports it as a note. ASME B36.10M is what would close it.

**Butterfly face-to-face is deliberately not transcribed.** Tables 2 and 3a–3c
give it, and **ASME B16.10 Table 1.3-4 already does**. Two sources for one
dimension is two answers; the honest next step is a deliberate cross-check
between them, and it has not been done.

`ManualValveTest` **68**, `ValvePtRatingsTest` **25**. Wider run **549 passed /
9,450 assertions**.

#### 11.9.10 API 623: a substitution table belongs to its standard (2026-09-25)

**The user supplied API Standard 623 (2021)** — the globe valve, and the sibling
of API 600. NPS 2–24, classes 150–2500, and the widest set of body
configurations here: bolted **and** pressure-seal bonnet, rotating and
nonrotating rising stems, rising and nonrising handwheels, straight / y-pattern
/ right-angle, and stop-check.

**Fourth genuine product-form collapse.** Table 7: *"Body and bonnet — As
selected from ASME B16.34, Group 1, Group 2, and Group 3."* All three groups, no
product-form restriction. Clauses 7.3 and 7.4 do speak only of castings, but
they are about *inspecting* a casting when one is used (MSS SP-55), not a
restriction to them.

The contrast with API 603 is now recorded on both entries, and it is the point:
**API 623 takes Groups 1, 2 and 3; API 603 takes only 2 and 3.** That limit is
what the word "corrosion-resistant" in the API 603 title actually buys.

**The globe trim chart went from 9 trims to 24.** The old spec held nine off an
industry chart, in the four-part shape the standard does not have. Table 8
carries the same 24 numbers as API 603 — the API 600 set without 5B and without
the 20/20A/20B/20C/21 nickel family — and prints **"TRIM NUMBER 1 IS OBSOLETE"**
just as API 600 does.

**A SUBSTITUTION TABLE BELONGS TO ITS STANDARD, NOT TO THE TRIM NUMBER.** API
623 Table 9 is *not* API 600 Table 9. It drops `8 → 5` and adds **`2 → 10`**,
which API 600 does not offer. Trim 8 and trim 2 exist in both charts and
substitute differently, which is the pair that proves the rule.

**A fifth invented property, caught.** The old chart carried a per-trim boolean
`requiresOversizeStem`, ticked on the austenitic trims. API 623 says no such
thing. Clause 5.8.1 states a **design obligation on the manufacturer** — the
minimum comes from Table 5, and *"the actual stem diameter shall take into
account the valve design details and the stem material strength
characteristics"*, with MSS SP-91 bounding the input force. A plausible
simplification of a real rule is the hardest kind of invention to spot.

**Materials inherited, and labelled as such.** API 623 Table 8 has the same 24
codes and the same column structure as API 600 Table 8, but the two have **not**
been compared cell by cell. So the codes, the obsolescence of trim 1 and the
Table 9 substitutions are `verified: true` from API 623, while every material
property stays `verified: false` with `materialsInheritedFrom` naming where it
came from. The validator caught the first attempt at this, which left a `source`
string on an unverified property — *"a citation nobody stands behind is worse
than none"*.

**The body-form test has now been wrong three times** — API 600, then API 602,
then API 623 — so it stopped chasing the last unverified guess and now asserts
the **pattern**: no verified standard restricts the body product form. If a
future purchased standard does, it fails loudly, and that would be worth
knowing. The code path is still covered against one of the four remaining
unchecked claims, with the finding required to admit it is unchecked.

`ManualValveTest` **74**, `MaterialLibraryTest` **108**. Wider run **555 passed
/ 9,933 assertions**.

#### 11.9.11 API 594: two types, two dimension sources (2026-09-25)

**The user supplied API Standard 594 (2022)** — check valves. NPS 2 to 48, and
three findings that each break an assumption the platform was carrying.

**1. THE FACE-TO-FACE SOURCE DEPENDS ON THE TYPE.** Clause 1 splits check valves
in two: a **Type A** valve takes its short face-to-face from **API 594 Tables 2
and 3**; a **Type B** valve takes it from **ASME B16.10**. The platform holds
B16.10 and *not* API 594 Tables 2/3 — so **a Type A check valve currently
resolves to a Type B length**. That is recorded as a known gap with the fix
named, not left to be discovered when a spool will not close.

Type A also admits **gray and ductile iron** bodies; Type B is B16.34 materials
only. Two variants of one standard with different material scopes as well as
different dimension sources.

**2. THE CLASSES COME FROM THREE FLANGE STANDARDS.** 150–2500 per **B16.34**,
**125 and 250 per ASME B16.1** (gray iron), and **150/300 per ASME B16.42**
(ductile iron). This is the first standard here to carry iron classes, and
`ValvePtRatings::tabulatesPressureClass('125')` is correctly false — a consumer
that resolves every class through B16.34 finds nothing for 125 or 250 and must
not invent it.

**3. THE CHECK-VALVE TRIM CHART WAS INVENTED OUTRIGHT.** The library held four
codes — `13CR`, `STL`, `316`, `MONEL` — that appear in no standard. **API 594
numbers its trims like every other API valve standard**: Table 4 gives **twelve**
(1, 2, 5, 5A, 8, 9, 10, 12, 13, 14, 16 and the bronze **AA**). And its parts are
**seating surface and hinge pin** — a check valve is opened by the flow, so it
has no stem and no backseat; the "disc" in the old four-part list was ours.

**A trim number belongs to its standard, not to the world.** A test asserted
that trim 8 was a gate trim and could not appear elsewhere — true only because
the check chart used made-up letter codes. API 594 has a trim 8 of its own, with
different materials. The API standards share one numbering family and the same
number means different things in different standards, exactly as the
substitution tables do.

**API 594 states no seat hardness at all** — the words *hardness*, *HB* and
*Brinell* appear nowhere in it, where API 600 and API 623 both give a column.
The validator rule demanding hardness on a hardfaced trim was written from the
API 600 shape; a spec may now declare the absence, and API 594 does. Borrowing a
figure from the gate chart would be an inspection criterion nobody wrote.

`ManualValveTest` **79**, `MaterialLibraryTest` **108**. Wider run **560 passed
/ 10,080 assertions**.

#### 11.9.12 API 599: not every standard numbers its trim (2026-09-25)

**The user supplied API Standard 599, ninth edition (October 2025)** — metal
plug valves. NPS ½ to 36, classes 150–2500 **including 800**, with threaded and
socket-welding ends stopping at **NPS 2** (the per-end-type scope shape API 608
introduced).

**API 599 PRINTS NO TRIM NUMBER TABLE.** API 600, 623 and 594 each print one.
API 599 does not: clause 6.2.1 gives the shell from **ASME B16.34 or a ductile
iron from B16.42**, and leaves seat, sleeve and plug facing to be agreed on the
purchase order. The two codes the library held for plug valves — `PTFE-SLV` and
`MTL` — were a vocabulary for a chart that does not exist.

So the API599 trim spec is **removed**, `trimStandardFor('PLUG')` returns null
alongside NEEDLE and DIAPHRAGM, and `ManualValves` no longer raises
`TRIM_ABSENT` on a plug valve row. **Demanding a trim number from a plug valve
asks for something the standard has not got** — the fourth invented vocabulary
this sequence has removed, and the first where the whole chart was fictitious.

**The third variant axis API 598 needed.** Note (f) of its Table 1 says *"for
lubricated plug valves, the high-pressure closure test is mandatory and the
low-pressure closure test is optional"* — prose, until now. Clause 1.2 names the
two: **LUBRICATED** and **NON_LUBRICATED**. With API 608 (ball designs) and API
609 (butterfly categories), every variant API 598 distinguishes now has its
vocabulary from a construction standard.

**Three-way and four-way plug valves are explicitly out of scope** (clause 1.2
NOTE), while **tandem** valves — two independently operating plugs in one body —
are in. Worth holding: `THREE_WAY_VALVE` is on the deferred list and this says
which standard will *not* cover it.

**Four design groups with different face-to-face dimensions** (clause 1.6):
short, regular, venturi, round port. The short pattern exists only in Class 150
and 300 and matches the B16.10 gate-valve face-to-face over NPS 1½–12 — which is
exactly what `valve-face-to-face` already holds, so the two agree.

**And a second overstatement caught.** The tempting story was "a fifth
product-form collapse". API 599's `bodyGroups` was **already null**, as API
609's had been, and the validator said so again. **Four entries genuinely
collapsed: API 600, 602, 603 and 623.** The rest never made the claim.

`ManualValveTest` **83**, `MaterialLibraryTest` **108**. Wider run **564 passed
/ 10,167 assertions**.

#### 11.9.13 API Spec 6D: the loop closes (2026-09-25)

**The user supplied API Specification 6D, 25th edition (2021).** The largest
document held, and the one that settles an anomaly declared four rounds ago.

**IT IS A SPECIFICATION, NOT A STANDARD.** API 600 covers a valve type; API 6D
covers design, manufacturing, materials, welding, quality control, assembly,
testing, marking, documentation and process controls in one document, for
**five** valve types. Naming it on a PMS row picks a quality regime — and,
because **Annex C is normative**, a dimension source too.

**THE B16.10 LOOP CLOSES.** B16.10 Table 1.3-1 note (14) said its Class 150
gate figures for the large sizes were *"extracted from API 6D"*. Read from the
other side, they are:

> **17 of 19 sizes match to the millimetre.** The two that do not are the same
> inch figure rounded differently — 7.50 in is 190.5 mm, and one standard
> rounds up while the other rounds down.

**And the declared anomaly is settled.** The B16.10 round recorded that Table
1.3-1 column 9 *shortens* from 762 mm at NPS 34 to 711 mm at NPS 36 and
attributed it to note (14). API 6D gives **813 mm at NPS 36** and **660 mm at
NPS 30** — and our B16.10 data holds *both* values at each of those sizes. The
larger figure in each pair is the API 6D one; the smaller is the
**through-conduit** valve that note (14) sends to a different number. **The
column was never non-monotonic — it was two valve constructions printed in one
place.**

**A valve type nothing else here knows.** The 25th edition added **AXIAL**
valves. No component-template qualifier, no actuator travel, no trim chart and
no face-to-face row outside Annex C knows the type. Declared via
`valveTypesBeyondTemplate` rather than quietly added: widening the VALVE
template changes the PMS body, the MTO and every dropdown that reads it, and
that is a decision to take deliberately — the same treatment NPS 60 got when
API 609 reached past `pipe-sizes`.

**Class 400, for the third time.** Clause 1 NOTE: *"ASME Class 400 has been
removed from this specification."* ASME B16.34 never tabulated it, API 602 omits
it, and API 6D has now deleted it outright. Three independent documents say a
Class 400 **valve** is not a thing — while a Class 400 **flange** remains
ordinary in B16.5.

Also the first document here to name **hydrogen gas** service.

`ManualValveTest` **86**, `ValveFaceToFaceTest` **17**. Wider run **569 passed /
10,191 assertions**.

#### 11.9.14 ISO 15761: a second pressure system, and the last collapse (2026-09-25)

**The user supplied ISO 15761:2020** — the ISO twin of API 602: compact steel
gate, globe and check valves, NPS ¼ to 4.

**THE FIFTH AND FINAL PRODUCT-FORM CLAIM COLLAPSES.** ISO 15761 was the last
*verified* entry still asserting one form — `FORGINGS`, on the reasoning that it
is the compact forged valve. Clause 5.4.4.2: *"Body end flanges and bonnet
flanges shall be **cast or forged** integral with the body."* **Five for five**:
API 600, 602, 603, 623 and now ISO 15761. Every single-product-form claim this
repo made has been wrong.

**A PRESSURE SYSTEM THE PLATFORM DOES NOT HAVE.** Clause 1 rates these valves in
**two parallel systems**: ASME Class 150–2500 *and* **PN 16, 25, 40, 63, 100,
250, 400**. Every pack here — `pipe-sizes`, `flange-pt-ratings`,
`valve-pt-ratings`, the PMS itself — keys pressure on an ASME class number, so a
European sheet writing **PN 40** has nowhere to go. Declared through
`pnDesignations` rather than dropped.

And the two systems do **not** map one to one. Clause 1, on the class this
platform verified two rounds ago: *"Class 800 is not a listed class
designation… **There is no equivalent PN designation**."*

**A SECOND TRIM VOCABULARY.** Clause 6.1: *"The trim combination number (**CN**)
identifies both the stem material and the associated seating surface
material."* This is the system **API 602 Table 12** uses and a different one
from the API 600 trim numbers the library holds — the CN set includes **11A, 12A
and 14A**, which no API 600 chart has. It is recorded and deliberately *not*
transcribed: Annex F ties each CN to a body and bolting combination, so the
chart and its associations have to be built together or the association is lost.

**And the two families contradict each other.** API 600 Table 8 note (b)
**prohibits** free-machining 13Cr outright. ISO 15761 clause 6.1.2 **permits**
it when the purchaser specifies it, identified by the trim number **plus 100** —
CN101, 104, 105, 105A, 106, 107, 108, 108A. Same material, same valve family,
two answers. Which is exactly why a PMS has to name the governing document.

`ManualValveTest` **89**. Wider run **572 passed / 10,202 assertions**.

**The valve construction set is now ten of thirteen verified** — API 594, 599,
600, 602, 603, 608, 609, 623, 6D and ISO 15761. The three that remain
(BS 1414, BS 1868, BS 1873) are still unchecked claims whose findings say so. Four of them still
assert a single body product form; on the evidence so far, all four are wrong.

Still missing: **IPS-M-PI-110** (what an Iranian client actually cites; free to
obtain), **B16.25** for weld-end preparation, **NACE MR0175** for sour service,
and **ASME B36.10M** to reach NPS 60. Readable and unbuilt: API 600 Tables
1/4/Annex D, API 603 Tables 1–3, API 608 Tables 3–4, API 609 Tables 2/3a–3c, and
**API 602 Table 12**, whose trim vocabulary is keyed by **CN** rather than by
trim number.

**CORRECTION, 2026-09-24 — «نیازی به ثبت special items در pms نیست».** The
user states that special items need not be recorded in the PMS. The paragraph
that stood here proposed a class body holding **two kinds of row** (standard,
and special keyed by SP number x size). That proposal was *our analysis of
sampled PMS documents*, not a user decision, and it was built (step 6, commit
`f7ef1fa`) before the distinction was checked. It has been reversed by
`2026_09_24_180000_specials_leave_the_pms_body.php`.

**A class body holds one kind of row: component type x qualifier x size.**

**The SP register stays.** What the user actually decided about SP numbers is
untouched by the correction, and none of it involves the PMS: an SP number is
**unique across the whole project**, and it is recorded **both on the MTO and on
the datasheet** — one number, two uses. Since a PMS is transcribed rather than
authored (below), SP numbers are validated, never allocated. `specialty_items`
therefore remains, standing on its own, waiting for the MTO and the datasheet to
consume it.

*Method note, worth more than the feature:* a design document mixes user
decisions with the author's reasoning, and only the former binds. Before
building from a section of this ADR, check which paragraphs are headed
**User decision** and treat the rest as argument.

Because MTO is imported, an SP number arrives from two directions and the two
must agree. That reconciliation already has machinery: an MTO row citing an
unknown SP number is a `BaselineDiscrepancy`.

Tagged versus untagged matters here. Six templates carry `isTagged`; SP items
split the same way. A pig trap or restriction orifice is tagged and acquires two
identities — `SP-014` (what it is) and `V-2301` (which one it is). Which
register owns which is **moot for now**: with specials out of the PMS, nothing
in the built system needs the tagged instance. `specialty_items.is_tagged`
records the distinction; the question returns when the MTO consumes SP numbers.

### 11.10 A PMS is transcribed, not authored — and that changes the writes

**User decision: in the target market the PMS is produced outside and
transcribed into the system.**

Two consequences.

First, the entry screen is a **transcription tool**. 400 rows for one class,
50 classes, is not a click-per-row interface. It must be bulk-oriented: one
specification applied to a selected set of sizes. `setRules()` — built during
the per-size correction — is already that shape.

Second, and sharper: **write-time refusals become wrong.** `setRule()` currently
refuses SCH 160 at 3.5" because ASME does not make it. For an authoring tool
that is right. For a transcription tool it is a wall — the transcriber may not
correct the client's document, and cannot record what it says.

**User decision, 2026-09-24: record it, then raise a discrepancy.** This is the
`verified: false` precedent applied to rules: the data is held, and is not
citable until reviewed. Every write-time refusal in `PipingClassRuleService`
must be re-read in that light.

### 11.11 What this unlocks

None of the following is possible today. All of it becomes a query once material
lives in the rules and the class code lives on the MTO:

- MTO says `A106 Gr.B` where the class at that size requires `A312 TP316`
- a line's design temperature falls outside the class's P-T curve
- a ball valve in a class that lists no ball valve row
- 1" pipe with BE ends in a class whose joint construction is SW below 1.5"
- a spiral wound gasket against an incompatible facing
- MTO bolting below the B16.5 floor

### 11.12 Build order

| # | work | depends on |
| --- | --- | --- |
| 1 | `b165MaterialGroup` on grades, `verified: false` | — |
| 2 | `flange-pt-ratings` pack, with a standard axis | — |
| 3 | PMS as a registered document + revision container | 3 follows ITP |
| 4 | header enrichment; drop `CORROSION_ALLOWANCE` rule kind | 2 |
| 5 | body on component x qualifier x size; wire the material library | 1 |
| 6 | SP register + the special row kind | 3 |
| 7 | NDT block feeding the ITP | 4 |
| 8 | transcription screen, bulk-oriented | 5, 6 |
| 9 | MTO: class code + SP number columns, and the checks in 11.11 | 5, 6 |

Steps 1 and 2 are reference data only and carry no migration risk.

### 11.13 AMENDMENT (2026-09-24, same day) — a rating is `(standard, class)`, and some classes are computed

**Trigger (user):** valve classes generally line up with the ASME B16.5 / B16.47
rating classes, *but some valve classes — Class 800 for instance — do not exist
in those standards at all.* What follows from that?

More than expected. §11.3 above has a hole in it, and the hole is a symptom.

#### Where Class 800 actually lives

Not in B16.5 and **not in B16.34 either**. It is an *intermediate* class defined
by **API 602 / ISO 15761** for small-bore forged (wrought) steel gate, globe and
check valves with socket-welding and threaded ends. It exists precisely because
those valves have **no flange**: a B16.5 class number exists to fix flange
dimensions, and a valve with no flange is free to carry a pure pressure
designation. 800 fills the gap between 600 and 1500 for small bore.

#### It has no table — it has a formula

Per ISO 15761 §4.1.2 and API 602 §4.1.3, the Class 800 curve is a linear
interpolation of the B16.34 tables:

```
P800(T) = 1/3 * P600(T)  +  2/3 * P900(T)
```

Checked against carbon steel at 38 degC: `1/3(1480) + 2/3(2220) = 1973 psi`,
and a manufacturer's catalogue states **1975 psi at 100 degF** for a Class 800
A105 forged valve. The formula is right; the difference is rounding. All of
these numbers enter `verified: false` like every other figure in this repo.

**Design consequence.** `flange-pt-ratings.ref.json` must carry *derived* classes
alongside tabulated ones, rather than a fabricated 800 row:

```json
{ "class": "800", "definedBy": "API_602",
  "derivedFrom": [["600", 0.3333], ["900", 0.6667]],
  "verified": false }
```

This is better than data: any future intermediate class is expressible without a
new table, and the derivation is auditable.

#### The real finding: "rating" is not one enumeration, it is at least six

| standard | classes | what they rate |
| --- | --- | --- |
| B16.5 | 150, 300, 400, 600, 900, 1500, 2500 | flanges, NPS 1/2–24 |
| B16.47 A/B | 75, 150, 300, 400, 600, 900 | flanges, NPS 26–60 |
| B16.34 | 150, 300, 600, 900, 1500, 2500, 4500 | valves |
| API 602 / ISO 15761 | **800** | small-bore forged valves |
| **B16.11** | **2000, 3000, 6000, 9000** | **forged SW / threaded fittings** |
| B16.1 | 125, 250 | grey cast iron |

`piping_lines.pressure_rating` is a single `string(16)` holding all of them.

`3000` in B16.11 and `300` in B16.5 share neither scale nor meaning. **This is
the standing lesson already recorded in this repo — "codes are unique only
within their standard", learned on the API 600 scope question — recurring one
level up.** A rating is a pair, `(standard, class)`, and must be stored as one.

#### A valve's class relates to the piping class by `>=`, not `=`

A 600# piping class routinely specifies Class 800 socket-weld valves in small
bore. The valve is *stronger* than the class requires, and that is correct, not
a deviation.

So: the class rating governs the flanges and the P-T envelope; each valve row
carries its own class; and the check is **sufficiency** —

```
valve MAWP at design temperature  >=  class MAWP at design temperature
```

**Never compare class numbers.** `800 > 600` happens to be true; `3000 > 300` is
meaningless because the two live on unrelated scales. Comparison is on pressure
at temperature, always. This is the same shape as the bolting sufficiency check
the user specified — one pattern, two uses.

#### `requiresRating` is on the wrong axis

`component-templates.ref.json` today:

```
ELBOW  rating=-     TEE   rating=-     COUPLING rating=-   OLET rating=-
FLANGE rating=True  VALVE rating=True
```

But a **socket-weld** elbow is class-rated (3000/6000/9000 per B16.11), while a
**butt-weld** elbow is not — it inherits the schedule of the pipe it joins. Same
template, opposite answers.

So `requiresRating` is not a property of the component type. It is a property of
**component type x end type**. This directly breaks `isScheduled()`
(`category in {PIPE, FITTING} and requiresRating !== true`), which will ask a
socket-weld elbow for a schedule when it should be asking for a class.

#### A validator that falls out for free

> **CORRECTED by §11.16 (same day). Two errors below: the table is quoted from
> its socket-welding column only (threaded correlates differently), and it is a
> *rating basis*, not a fit-up rule — B16.11 explicitly permits thinner or
> thicker wall. The check is sufficiency, not equality. Read §11.16.**

B16.11 ties the class to the **socket bore**, not to strength:

| fitting class | bored for |
| --- | --- |
| 3000 | Sch 80 |
| 6000 | Sch 160 |
| 9000 | XXS |

A Class 3000 fitting on Sch 160 pipe will not seat, and loads the weld root. We
hold the fitting class and the pipe schedule for the same size in the same
class, so the check costs nothing. Add it to §11.11.

#### What this changes in the build order (§11.12)

- **Step 2** gains derived classes, and the pack's key becomes
  `(standard, class, material group, temperature)`.
- **New step, before 5:** move rating off a bare string onto `(standard, class)`,
  and move `requiresRating` onto component type x end type.
- **Step 9** gains two checks: valve-class sufficiency by pressure at
  temperature, and B16.11 fitting rating basis against actual pipe schedule
  (see §11.16 — sufficiency, not equality).

#### Note to self

The evidence was already quoted in §11.1's own source material — the real PMS row
reads `A105 forged 3000 lb SW B16.11` against `A234 WPB LR BW B16.9`. The `3000`
was read as part of a description string rather than as a *different rating
scale*. A number in a transcribed description is not decoration; ask which
standard issued it.

### 11.14 B16.47 — the materials are held, the dimensions are not (2026-09-24)

**Trigger (user):** are the specifications of B16.47 flanges recorded in the
material reference library?

Two different questions hide in that one, and they have opposite answers.

#### The material library correctly holds none of it

`materials.ref.json` states the exclusion at the top of the file:

> `pressureRating`: **NOT held here.** A flange rating is a property of the
> COMPONENT under ASME B16.5/B16.47, not of the material, and
> `piping_classes.pressure_rating` already carries it. **A105 is A105 whether
> the flange is 150# or 1500#.**

That is right, and B16.47 changes nothing about it: B16.47 is a **dimensional**
standard, not a material specification. A 30" Series A weld-neck flange is made
from the same forging specs as a 6" B16.5 one.

#### And the materials for those flanges are present

`FORGINGS` (`componentTypes` includes `FLANGE`, `BLIND`, `SPECTACLE_BLIND`):

```
A105   0 grades     A350  11 grades     A182  22 grades
A694   9 grades     A707   7 grades
```

**A694 (F42–F70) is specifically the large-diameter pipeline flange and fitting
spec**, and A707 covers low-temperature forged flanges. Nothing is missing on
the material side.

#### The gap is dimensional, and it starts exactly at NPS 26

`flange-bolting.ref.json` declares its own limit:

```
source: "ASME B16.5 ... Classes 150, 300, 600, 900, 1500"
  5 ratings x ~20 rows, every one of them  1/2 .. 24
```

It stops precisely where B16.47 begins, while `pipe-sizes.ref.json` runs to
NPS 48. So for NPS 26–48 we hold size, schedule and material, and hold **no
bolting data and no P-T basis**.

`FlangeBolting::forJoint()` returns `?array` and `return null` (line 146) above
24". Harmless today — the class is registered in `EpccServiceProvider` and has
**no business-logic consumer yet** — but the moment the bolting sufficiency
check the user asked for is wired, it will have **no floor to compare against
above 24" and will pass silently.** That is the worst failure mode available:
a check that reports success because it has no data.

The standards catalogue holds only a citation row —
`ASME · B16.47 · Large Diameter Steel Flanges NPS 26 through NPS 60 · 2020` —
so we can cite the standard and hold nothing from it.

#### Series A and Series B are not interchangeable

B16.47 has two series with different ancestry: **Series A** from MSS SP-44
(heavier, fewer and larger bolts) and **Series B** from API 605 (more, smaller
bolts on a tighter bolt circle). At the same NPS and the same class, outside
diameter, thickness, bolt count and bolt circle all differ. Series B also
carries a Class 75 that Series A does not.

Therefore **series is a project-level choice** and belongs in the class header or
the piping configuration, and the pack needs a `series` axis. Building it
without one repeats the §11.3 warning — B16.5 versus B16.47 — one level finer.

Whether the B16.47 P-T tables track the B16.5 material-group numbers unchanged
is **not asserted here**. Both series' dimensions and ratings enter
`verified: false` and are not citable until checked against a purchased copy.

**Open question put to the user and not yet answered:** which series the target
projects typically use. It affects only priority, not design.

#### Added to the build order (§11.12)

> **Extend `flange-bolting` to B16.47 with a `series` (A/B) axis, covering
> NPS 26–48 so the pack is flush with `pipe-sizes`.** Until then, any
> sufficiency check must treat "no data" as *unknown*, never as *pass*.

### 11.15 Both B16.47 series are in use — which simplifies one pack and doubles another (2026-09-24)

**Answer to the question left open in §11.14 (user):** projects use **both**
Series A and Series B.

That invalidates the assumption in §11.14 that series is a project-level choice.
It cannot live in `project_piping_config`; it has to be resolvable at each
flange.

#### Its home already exists

§11.4 gives a body row the payload

```
{spec:A105, form:FORGED, dimStandard:B16.5, rating:150, end:BW, facing:RF}
```

so at NPS 26 and above it simply becomes `dimStandard: "B16.47", series: "A"`.
**No new structure.** A class header may carry a *default* series to speed
transcription, but the row is authoritative.

#### The P-T pack does NOT need a series axis

Checked, because the opposite was being assumed:

> The dimensional series, A and B, **have the same pressure-temperature
> ratings.** But unless they use special bolts they will not fit up together.

B16.47 materials align with B16.5 (nickel alloys excepted), so the same tables
generally apply. This splits the two packs cleanly:

| pack | key |
| --- | --- |
| `flange-pt-ratings` | (standard, class, material group, temperature) — **no series** |
| `flange-bolting` | (standard, **series**, class, nps) — **series required** |

**But editions drift.** The same source warns that for one material and class the
rating *can* differ between B16.5 and B16.47 depending on edition — "typically by
5 psi or so". So the pack must carry the **edition year**, not only the standard.
The standards catalogue already holds `ASME · B16.47 · … · 2020`, so the link
exists.

#### Four checks that come almost free

1. **Series A does not bolt to Series B.** At the same NPS and class the outside
   diameter, bolt count and bolt circle all differ; mating them is not possible
   without special bolts. This is a **joint-level** check and belongs on the
   existing `PortCompatibility` seam, which today only warns about facing. The
   predictable source of the clash is a vendor equipment nozzle in one series
   against a piping class in the other.
2. **Above NPS 24 the maximum class is 900.** B16.5 reaches 2500; B16.47 does
   not. A 1500# class therefore cannot carry a 30" flange — nothing stops that
   today.
3. **Class 75 exists only in Series B.**
4. **RTJ is Series A only**, and within Series A only for Classes 300–900 (one
   source further restricts the defined RTJ dimensions to NPS 26–36). This lands
   directly on `permittedFacings`, which we hold and do not validate.

#### One restriction deliberately not asserted

Sources indicate B16.47 covers only **weld neck and blind** flanges — not SO, SW,
threaded or lap joint — which would make four of the `FLANGE` template's six
qualifiers invalid above NPS 24. One source's table was self-contradictory at
exactly this cell (showing Series A as blind-only and Series B as weld-neck-only,
which agrees with nothing else). **Not claimed here.** It enters
`verified: false` with the rest and is settled against a purchased copy.

#### Net effect on the build order

- `flange-pt-ratings` gets **simpler** — no series axis — but gains an edition year.
- `flange-bolting` **doubles** — both series in full, NPS 26–48.
- Four new checks join §11.11, the flange-series mismatch being the one that
  stops a joint from being assembled at all.

### 11.16 CORRECTION to §11.13 — B16.11 Table 8 is a rating basis, not a fit-up rule (2026-09-24)

**Trigger (user):** B16.11 contains a table giving the thickness equivalent of
each class, and that probably bears on system validation.

It does. Reading the actual table showed **two errors in §11.13**, one of
omission and one of kind.

#### The table

ASME B16.11 **Table 8** in the 2016 edition (**Table 7** in earlier ones),
*Correlation of Fittings Class with Pipe Schedule No. or Wall Designation of
Pipe*:

| Class | Type of fitting | Schedule No. | Wall designation |
| --- | --- | --- | --- |
| 2000 | **Threaded** | 80 | XS |
| 3000 | **Threaded** | 160 | … |
| 6000 | **Threaded** | … | XXS |
| 3000 | **Socket-welding** | 80 | XS |
| 6000 | **Socket-welding** | 160 | … |
| 9000 | **Socket-welding** | … | XXS |

#### Error 1 — §11.13 quoted one column as if it were the table

§11.13 states `3000 -> Sch 80, 6000 -> Sch 160, 9000 -> XXS`. That is the
**socket-welding column only**. For a *threaded* fitting, Class 3000 correlates
to **Sch 160**, not Sch 80.

So one class number means two different wall thicknesses depending on end type.
This is the standard's own proof of the §11.13 claim that the axis is
**component type x end type**, not component type — arrived at there by
argument, and confirmed here by the table.

#### Error 2 — it is a rating basis, and §11.13 read it as a fit-up restriction

§11.13 says a Class 3000 fitting on Sch 160 pipe "will not seat, and loads the
weld root". **The standard says the opposite.** Directly beneath Table 8:

> **NOTE (1): This Table is not intended to restrict the use of pipe of thinner
> or thicker wall with fittings.**

and §2.1.1 is headed **Basis of Rating** — "the schedule of pipe corresponding to
each Class designation of fitting **for rating purposes**".

The physical reasoning agrees: **pipe OD is constant across schedules at a given
NPS**, and a socket bore is machined to accept the OD. A Class 3000 socket
fitting therefore accepts Sch 40 and Sch 160 pipe alike. The web source behind
the §11.13 sentence had confused bore with wall.

#### So the validator is sufficiency, not equality

```
Class 3000 socket-welding fitting  ->  rating basis = Sch 80
actual pipe                        ->  Sch 160
=> the fitting is weaker than the pipe; the line's allowable pressure is
   governed by the fitting, not by the pipe wall
```

The check is therefore *"is the fitting's rating basis at least the pipe's actual
schedule"*, and a shortfall is a **finding about which component governs**, not a
refusal.

**This is the third instance of one pattern**, and that is now worth naming:

| case | check |
| --- | --- |
| bolting | MTO quantity/length/diameter >= the B16.5 floor |
| Class 800 valve | valve MAWP at design temperature >= class MAWP |
| **B16.11 fitting** | **fitting rating basis >= actual pipe schedule** |

All three are sufficiency. None is equality. Whatever implements the first
should be shaped so the other two are instances of it rather than three separate
services.

#### Two further checks the table gives away

**The (class, end type) pair is itself constrained.** Threaded exists only as
2000/3000/6000; socket-welding only as 3000/6000/9000. So **"Class 9000
threaded" is not a thing** — one trade source calls a 9000LB NPT request a red
flag for exactly this reason — and neither is Class 2000 socket-welding. A
transcribed row carrying an impossible pair should be flagged at entry.

**`2M`, `3M`, `6M`, `9M` are permitted alternative class designations**, with M
standing for 1000. Since the PMS is **transcribed**, a source sheet may well use
them. The parser must accept both spellings or the transcriber is blocked by a
legal marking.

#### Edition sensitivity, again

The table moved from Table 7 to Table 8 between editions. This is the second
place today where an edition number changes an answer (the first being the few-psi
drift between B16.5 and B16.47 in §11.15). **Reference packs carry an edition
year, and citations resolve against it.**

### 11.17 Wellhead scope — API 6A, and the piping type as a standards profile (2026-09-24)

**Trigger (user):** projects with a wellhead facility use API standards such as 6A
and 6D, which differ wholesale from ASME in material, in pressure and
temperature rating, and in dimensions.

This is the largest scope change in this ADR, and the user's own proposal —
*make the piping type filterable, and give it a type* — turns out to be the thing
that makes it tractable.

#### First: 6A and 6D are not the same problem

**API 6D fits the model we already have.** It **references the ASME B16.34 rating
tables rather than duplicating them**, uses ASME classes 150–2500, and covers
flanged and butt-weld gate, ball, plug and check valves. The `(standard, class)`
pair from §11.13 already carries it.

One trap: **API 6D-2008 raised Class 600 at 100 degF from 1440 to 1480 psig.**
So 6D is *not* identical to B16.34. That is the **third** place in one day where
an edition changes an answer, after the B16.5/B16.47 few-psi drift (§11.15) and
the B16.11 Table 7→8 move (§11.16). The point is no longer a detail: **packs
carry an edition year and citations resolve against it.**

**API 6A is a different regime in every axis:**

| axis | ASME | API 6A |
| --- | --- | --- |
| pressure | dimensionless class (150…2500) | **actual psi**: 2K/3K/5K/10K/15K/20K |
| behaviour with temperature | **derates continuously** | **flat** across the temperature class |
| temperature | a number | **a class letter** (K, L, N, P, R, S, T, U, V) |
| material | ASTM spec (A105) | **material class** AA…HH |
| size | NPS | **bore diameter** — 2-1/16", 4-1/16", 13-5/8" — *but see §11.18: the PIPE stays NPS* |
| flange | B16.5 / B16.47 | **6B / 6BX** |
| gasket | B16.20 R/RX | **BX** — *WRONG, see §11.18: B16.20 covers BX and we already hold it* |
| quality | — | **PSL 1/2/3/3G/4** and **PR1/PR2** |

A 6A item's whole identity is one structured marking:

```
A114-API 6A-3 1/16 in-10,000 psi-S-FF-1,5-3-PR2-6-05
                  |        |     |  |   |  |  |
               size      RWP  temp mat H2S PSL PR
```

#### The user's decisions

- **The Christmas tree itself is tagged equipment with a datasheet; its piping
  and its manual accessories are piping members** — inside PMS scope. Consistent
  with §11.9: manual valves are PMS rows, actuated ones are datasheets.
- **The boundary is the tree's outlet flange.**
- **API 6A continues downstream of that flange** to a transition point —
  **but this varies between projects.**
- **Sour service (NACE MR0175 / ISO 15156) is in scope now**, lifting an earlier
  deferral.
- **Piping type becomes a first-class, filterable concept.**

#### Piping type is a standards profile, not a label

Treated as a label it solves nothing. Treated as a **profile that selects which
reference packs and which rules apply**, it resolves the whole 6A/ASME conflict
at one point instead of in every table:

| what the type binds | process piping | wellhead piping |
| --- | --- | --- |
| size system | NPS (36-size pack) | NPS for pipe + **bore designation** for connections (§11.18) |
| rating axis | dimensionless ASME class | **RWP in psi** |
| temperature behaviour | continuous derating from tables | **flat within the temperature class** |
| material reach | `CASCADE` (spec → grade) | **performance class** (AA…HH) |
| flange standard | B16.5 / B16.47 | **6B / 6BX** |
| gasket family | B16.20 R/RX | B16.20 **BX** — already held (§11.18) |
| quality axis | — | **PSL / PR** |

**The type list is project-configurable** (process, utility, wellhead, flare,
drain …) and each type carries a profile. Only "wellhead" pulls in API 6A, so a
project without a wellhead never meets the complexity.

This is the same shape as the platform's **founding requirement that the
standards framework (ISO vs AWP) be selectable rather than hardcoded** — one
axis further down.

**Crucially, the transition point is data, not a rule.** Because it varies by
project, the system must not know "after the choke". The project **declares** it
by assigning a class or line to a type. What the system *does* owe is detection:
**any joint whose two sides carry different piping types is an interface and must
be validated explicitly**, never silently joined.

#### The boundary already has a home

`component-templates.ref.json` carries `NOZZLE` with **`isTagged: true` and
`requiresRating: true`**. The tree's outlet flange is exactly that — a nozzle on a
tagged equipment item — and the piping begins there. If the vendor supplies a
6BX 10K outlet with a BX ring, the piping class must present the same.

That makes a **third job** for the `PortCompatibility` seam:

| # | check | from |
| --- | --- | --- |
| 1 | facing | exists, warns only (`PortCompatibility.php:112`) |
| 2 | B16.47 Series A vs B | §11.15 |
| 3 | **API 6A flange/ring across an equipment or type boundary** | here |

A third recurrence. Like the three sufficiency checks in §11.16, this is an
abstraction asking to be built once.

#### What this breaks

> **CORRECTED by §11.18. The paragraph below is overstated: 6B weld necks carry
> RESTRICTED bores matched to real NPS pipe and schedules, so wellhead PIPE rows
> use the existing 36-size pack unchanged. What is needed is a
> `(6A size, rating) -> (NPS, schedule)` mapping table, not a second size
> universe. The mapping stops at 6BX, which is always full bore.**

**A second size system — the deepest impact.** `piping_class_rules` is keyed on
`dn integer` + `nps varchar`, and the user's standing constraint is that sizes are
worked in NPS. **`2-1/16"` is not an NPS**; it is a bore. Nothing in the 36-size
pack can express it, and the per-size model of §10 has no key for wellhead rows.

**A fifth `reachedBy`.** The library has CASCADE / CONSTITUENT / TRIM /
REQUISITION_ONLY. API 6A states a **requirement**, not a selection: "material
class DD" fixes body and trim *categories* and leaves the specification to the
manufacturer. The classes, as far as three agreeing sources show:

| class | body | pressure-controlling parts (trim) | sour |
| --- | --- | --- | --- |
| AA | carbon / low-alloy | carbon / low-alloy | no |
| BB | carbon / low-alloy | **stainless** | no |
| CC | stainless | stainless | no |
| DD | carbon / low-alloy | carbon / low-alloy | **yes** |
| EE | carbon / low-alloy | stainless | **yes** |
| FF | stainless | stainless | **yes** |
| HH | CRA | CRA | **yes** |

Note it already embeds the body/trim split we built `VALVE_TRIM` for.

**Temperature is a combinable class letter.** `P+U` (−20 to 250 degF) is a common
6A gate valve rating, so the field is not a single-valued enum.

**Rating philosophy differs.** A comparison function cannot just read numbers; it
must know whether the rating derates with temperature or is flat.

#### What comes free

**PSL feeds the ITP**, exactly as the NDT block does in §11.8. And the
`(material class x RWP) -> minimum PSL` table is a complete validator:

```
AA/BB/CC:  <=5K -> PSL1  ·  10K,15K -> PSL2  ·  20K -> PSL3
DD/EE/FF:  <=5K -> PSL1  ·  10K -> PSL2  ·  15K,20K -> PSL3
HH/ZZ:     PSL3 throughout  ·  20K -> PSL4
```

#### Sour service, and a trap worth stating plainly

**"NACE compliant" is not a property of a material.** A grade is not absolutely
acceptable; it is acceptable **within an environmental envelope** — H2S partial
pressure, temperature, chloride, pH — and under hardness restrictions (carbon
steel at 22 HRC, for instance). A boolean `naceCompliant: true` on a grade would
record a falsehood.

What belongs on the grade is the **hardness limit and the applicability
envelope**; compliance is **computed at assessment time** against the service.
This is the same discipline as `verified: false`: hold the input, do not
pre-compute the conclusion.

And sour service is **cross-cutting, not wellhead-only** — ASME piping in sour
service is equally subject to MR0175. It must not be buried inside the wellhead
profile.

#### Source reliability — worse here than anywhere else in this ADR

Public sources **contradict each other** on API 6A:

- temperature class lists: `K,L,N,P,S,T,U,V` (a direct transcription of Table 2),
  versus `K,L,P,R,S,T`, versus lists including X and Y
- class S range: one source −18 to 60 degC, another −18 to 149 degC
- material classes: one source names only four; another maps DD to austenitic
  stainless, contradicting three others
- whether 6B spans 2K–5K with 6BX taking 10K–20K, or 6BX spans the whole range

The tables above follow the sources that transcribe the standard's own Tables 2
and 3 and agree with each other. **None of it is citable.** Everything enters
`verified: false` and waits for a purchased copy.

#### Build order impact

A new step, and it is not small:

> **Piping type as a standards profile** — a project-configurable type list, each
> binding a size system, rating axis, temperature model, material reach mode,
> flange and gasket standard, and quality axis. Then a **bore-diameter size
> pack**, a **performance-class material mode**, and the API 6A rating, PSL and
> temperature-class packs behind the wellhead profile.

The profile must come **before** the wellhead data, or the data has nowhere to
live. And it should be built so that an ordinary project never encounters it.

### 11.18 CORRECTION to §11.17 — the pipe is NPS; only the connection is a bore (2026-09-24)

**Trigger (user):** API 6A pipe sizes can be compatible with ASME/ASTM pipe
sizes, and so can the flanges.

Correct, and §11.17 overstated the problem in three places. The wellhead work is
**substantially smaller** than that section claims.

#### Correction 1 — `pipe-sizes.ref.json` is not broken for wellhead scope

§11.17 says *"size is a bore diameter, not NPS"* and calls a second size system
"the deepest impact". That is true of the **equipment connection**, and false of
the **pipe**.

API 6A **Type 6B weld-neck flanges do not have full bores — they have restricted
bores machined to match standard ANSI/ASME pipe sizes and schedules.** The worked
example: a **4-1/16" 5,000 psi 6B** flange has a maximum bore of **3.47"**,
approximating the ID of **4" Schedule 160 line pipe** at 4.5" OD. Manufacturers
list stock as *"weld neck flanges 2-1/16" to 7-1/16", **neck sizes 2" to 6"**,
schedules SCH 80, SCH 160 and XXH"*.

And the underlying reason, which is the user's point exactly:

> **API and ASTM/ANSI pipe sizes share common ODs in many sizes. ANSI and API
> standards share a common ancestry.**

So the two designations describe different things rather than competing:

| | basis |
| --- | --- |
| ASME/ASTM **NPS** | a *nominal* label, not a measurement |
| API 6A **size** | the actual **through-bore** |

**A wellhead line's pipe rows therefore use the existing 36-size NPS pack,
unchanged.** Only the *flange and equipment connection* carries a 6A bore
designation — and for 6B it maps to a real (NPS, schedule) pair.

What we need is not a parallel size universe but **one mapping pack**:
`(6A size, rating) -> (NPS, schedule)`. That is a table, not an architecture.

#### Correction 2 — 6B and 6BX behave differently, and the mapping stops at 6BX

- **6B weld neck**: restricted bore matched to NPS pipe; 45K yield material.
- **6BX weld neck**: **always full bore**; 60K yield at 10,000 psi and 75K at
  15,000 psi and above. Their necks **"do not usually match any pipe schedules"**,
  and specifying pipe-matching necks on 6BX 10K/15K is described as done
  **"at high risk"**.

So the NPS mapping holds for 2K–5K and **deliberately stops** at 10K. The pack
must say *no mapping* there rather than invent one.

Also a scope limit worth holding: **API 6A does not specify weld-neck flanges
above 11"**; charts circulating above that size are explicitly unofficial.

#### Correction 3 — the gasket family is not new; we already hold it

§11.17 lists the wellhead gasket family as **BX** against B16.20 R/RX, implying
something outside our library. Checked the pack:

```
B16.20-RJ  (ASME B16.20 ring joint)
  RJ-R-SOFT · RJ-R-304 · RJ-R-316 · RJ-RX-316 · RJ-BX-316
```

**B16.20 covers R, RX and BX, and all three are already in the gasket group.**
That line of §11.17 was wrong. Nothing to add.

#### A new rule, and it is a fourth sufficiency check

> **"API 5L / ASTM carbon steel line pipe is lower yield than API 6A flanges,
> therefore a heavier wall is required."**

The flange is 45K, 60K or 75K; A106/API 5L pipe is not. Matching the bore is not
enough — **the pipe wall must compensate for the yield difference** so the
assembly still reaches the flange's rated working pressure. Note too that 6BX
flanges are typically 4130/4140 low-alloy or 410 SS, so **a compatible higher
grade of pipe is required**, not merely a thicker one.

That is the **fourth** instance of the pattern named in §11.16:

| case | check |
| --- | --- |
| bolting | MTO quantity/length/diameter >= B16.5 floor |
| Class 800 valve | valve MAWP at design temp >= class MAWP |
| B16.11 fitting | fitting rating basis >= actual pipe schedule |
| **6A weld neck** | **pipe wall + grade sufficient for the flange's RWP given the yield gap** |

#### Flange interchangeability — the sources disagree, so the system must not decide

- one: API and ASME flanges are **"not interchangeable"**, and where bolt circle
  and hole count appear identical the similarity is **"misleading and dangerous"**
- another: *"some API flanges can easily be joined with ASME flanges even though
  the flange rating is different"*
- a third, on 6B 5,000 psi: a **"dimensional heritage shared with ASME B16.5
  Class 1500 ring-joint flanges"**, and *"in overlapping sizes the flanges
  generally mate, but … **confirm dimensions ring-for-ring and hole-for-hole
  before mixing systems on a joint**"*

Three reputable-sounding sources, three different answers. **The system must
therefore neither pass nor refuse a cross-standard flanged joint silently.** It
must **flag it as an interface requiring explicit engineering approval, and
record the decision** — the same treatment §11.10 gives a transcribed value that
fails a rule, and the same treatment §11.17 requires where two piping types meet.

#### Net effect on the build order

The wellhead profile gets **materially cheaper**:

- ~~bore-diameter size pack~~ → **a `(6A size, rating) -> (NPS, schedule)` mapping
  pack for 6B, explicitly empty for 6BX.** Pipe rows keep the existing NPS pack.
- ~~BX gasket family~~ → **already present** in `B16.20-RJ`.
- still required: the **performance-class material mode** (AA…HH), the **RWP /
  temperature-class / PSL packs**, and the **piping type profile** itself.

§11.17's alarm about a second size system was overstated. The profile, the
material mode and the quality axis remain real; the size universe does not.

### 11.19 API 5L was already held — and one cross-reference had rotted (2026-09-24)

**Trigger (user):** is there anything in the system for API 5L? Then, on being
shown the answer: **PSL exists in 6A too, and there it runs 1 to 4.**

Unlike §11.14, the audit found the data present and in good shape. It found a
**real, shipped defect** next to it, and the user's follow-up exposed a **naming
collision that was one step from becoming a bug**.

#### What was already there

`API5L` sits in the `PIPE` group, `reachedBy: CASCADE`, product forms
`SMLS / ERW / DSAW`, with minimum yield and tensile on every grade, all
`verified: false`. The **PSL axis was modelled correctly** as `classOrType`, the
same slot every other spec uses for its class or type. The standards catalogue
carries `API · 5L · Line Pipe · 2018 · MATERIAL`, and `ItpTemplateSeeder`
already cites 5L for pipeline incoming material.

Better than expected: a **cross-reference already existed**. Fifteen flange and
fitting grades carry `matchingLinePipeGrade`, pairing A694 F42–F70 and A860
WPHY-42–70 with the line pipe grade they are meant to be welded to — *"F60
flanges go on X60 pipe"*.

#### The defect: five of those fifteen pointed at nothing

| dangling reference | held by |
| --- | --- |
| `API 5L X46` | A694 F46, A860 WPHY-46 |
| `API 5L X48` | A694 F48 |
| `API 5L X50` | A694 F50 |
| `API 5L X56` | A694 F56 |

The pack contained only B, X42, X52, X60, X65 and X70. **X46, X48, X50 and X56
were named but absent**, and `validate_packs.py` — 28 material rules — had no
rule that a cross-reference must resolve.

This is the **same shape** as the §11.14 finding, where `flange-bolting` stopped
silently at NPS 24. The lesson recorded there applies again, one level up:
*missing reference data must read as unknown, never as pass* — and here it read
as neither, because nothing looked.

**Fixed.** Rule 29 was written **first and made to fail** on the shipped pack (all
five, exit 1) before any data was added; then X46, X48, X50, X56 and **X80** were
added, and the rule went green. Pack `1.9.0 -> 1.10.0`, 355 -> 360 grades.
`MaterialLibraryTest` 103 passed / 6863 assertions.

#### PSL is a pair — the user's catch, and a third instance of one trap

**API 5L has PSL 1 and 2. API 6A has PSL 1, 2, 3, 3G and 4.** Same acronym, same
general idea — a product specification level — but **different standards,
different scales, and no meaning in common**:

| | scale | what it governs |
| --- | --- | --- |
| **API 5L** | PSL1, PSL2 | line pipe chemistry, CVN impact testing, carbon equivalent |
| **API 6A** | PSL1, PSL2, PSL3, PSL3G, PSL4 | extent of QA, inspection and testing on wellhead equipment |

A 5L PSL2 is **not** a 6A PSL2. It is not a lower grade of one, either — they are
different axes.

This is the **third** appearance of the trap named in §11.13:

1. `Class 300` (B16.5) versus `Class 3000` (B16.11) — same word, different scale
2. a rating is therefore `(standard, class)`, never a bare string
3. **a PSL is `(standard, level)`, never a bare string**

And the near-miss is worth stating: §11.17 had *already* written the 6A
`(material class x RWP) -> minimum PSL` table into this ADR, while the pack
*already* stored 5L's PSL as the bare string `"PSL2"`. Both were correct alone.
Nothing anywhere said they were different things. The first code to read a "PSL"
from one and compare it with the other would have been wrong and would have
looked right.

The `_note` on the 5L spec now says so explicitly, and the remedy is the §11.13
remedy unchanged: **carry the standard with the level.**

#### The fourth sufficiency check is already computable on the pipe side

§11.18 established that a 6A flange out-yields line pipe, so the pipe wall and
grade must compensate. The pack now holds the whole pipe side of that sum:

| grade | min yield | | 6A flange | min yield | lowest 5L grade that clears it |
| --- | --- | --- | --- | --- | --- |
| B | 245 MPa (36 ksi) | | **6B** (45K) | 310 MPa | X42 (290) **falls short**; X52 clears |
| X42 | 290 (42) | | **6BX 10K** (60K) | 414 MPa | X60 (415) clears **by 1 MPa** |
| X52 | 360 (52) | | **6BX 15K/20K** (75K) | 517 MPa | **X80 (555) — X70 does not clear** |
| X60 | 415 (60) | | | | |
| X65 | 450 (65) | | | | |
| X70 | 485 (70) | | | | |
| X80 | 555 (80) | | | | |

That last row explains the §11.18 source's remark that 6BX flanges are typically
**4130/4140 low-alloy or 410 SS**: at 75K yield, ordinary line pipe does not
reach the flange. Only the 6A side of the comparison is still missing.

Note also that `matchingLinePipeGrade` is the **precedent** for this check — it
already expresses "this flange grade belongs with that pipe grade" as a named
pair. The 6A version is the same relation computed from yields instead of
enumerated.

#### Still open

- **PSL1 versus PSL2 has no semantics.** The letters are stored; what distinguishes
  them — mandatory CVN impact testing, carbon-equivalent limits, tighter chemistry
  on PSL2 — is not. Until it is, the system cannot validate the ordinary project
  rule that **sour service requires PSL2**.
- **No sour-service variant** (API 5L Annex H). Sour service entered scope in
  §11.17, and line pipe is exactly where it bites. This is now a live gap, not a
  deferral.
- Both need real numbers from a purchased copy, and both stay `verified: false`
  until they have them.

### 11.20 A piping MTO is often issued as several sub-MTOs — RECORDED, DEFERRED (2026-09-24)

**User:** in some projects the piping MTO is issued as several sub-MTOs — pipe
MTO, fittings MTO, flanges MTO, valves MTO, paints MTO, and so on. **Recorded
now so it is not lost; completing the MTO is deferred by the user.**

#### Why the split exists

The industry item ordering found in §11.9 —
`Pipe -> Fittings -> Flange -> Gasket -> Fasteners -> Spacer & Blanks -> Valve ->
Specialty` — **is the cut line.** Each commodity goes to a different vendor under
a different purchase order: pipe from a mill, fittings from a forge, valves from a
valve maker, paint from a coating supplier. **The sub-MTO boundary is a
procurement package boundary.**

`ProcurementService` and `PurchaseOrder` today have **no package or commodity
concept at all**, so the two ends of that chain do not meet.

#### The live defect this creates

`BaselineService::reconcile()` takes the **union** of item keys from the two
batches:

```php
$keys = array_unique([...array_keys($left), ...array_keys($right)]);
```

`baseline_batches` has **no scope column** — only `source_kind` (`XLSX_MTO`), a
filename and a revision label. A batch cannot say *"I claim to cover fittings
only."*

So reconciling a **fittings-only sub-MTO** against a full model batch raises
**`MISSING_IN_MTO` for every pipe, flange, valve and gasket in the model** —
thousands of false findings, with the real ones buried underneath.

#### The generalisation, which is the part worth keeping

This is the **third** appearance of one failure today:

| where | what happened |
| --- | --- |
| §11.14 | `FlangeBolting::forJoint()` returns `null` above NPS 24 — **missing data read as a pass** |
| §11.19 | dangling `matchingLinePipeGrade` — **a reference that reads as an answer and delivers none** |
| here | **an absent row read as "missing" when it means "out of scope"** |

> **The system must distinguish *absent* from *not claimed*.** `MISSING_IN_MTO`
> conflates two entirely different facts.

And the absence of scope cuts both ways: because no batch declares what it
covers, **nothing can detect a sub-MTO that was never issued either**. No scope
means false positives in reconciliation *and* false negatives in coverage.

#### Paint is not a piping component

A paints MTO — and equally an insulation or welding-consumable MTO — contains
rows that are **not components**:

- absent from all 22 codes in `component-templates.ref.json`
- no size, no schedule, no material grade in the piping sense
- measured in **square metres or litres**, not `EA` / `M` / `KG`
- a **consumable derived from surface area**, not a counted article

`engineering_items` assumes a component shape throughout. A sub-MTO may not have
one. (One real template sighted in §11.9's research carried an `Insulation Code`
column for the same reason.)

#### One thing that is already right

Sub-MTOs **revise independently** — the valves MTO may sit at Rev C while the
pipe MTO is at Rev A. `revision_label` is per batch and `state` is
`DRAFT / ACCEPTED / SUPERSEDED` per batch, so this works today without change.

#### Alignment worth noting

The last category in the industry ordering is **Specialty items**, which is
exactly the **SP register** of §11.11. A specialty-items sub-MTO is the SP
register's natural counterpart.

#### The user's own qualifier is the design instruction

**"In *some* projects"** — the split is not fixed. This is the **third time today**
that something varies between projects, and the answer is the same every time:

> **project configuration, never a hardcoded rule.**

(The first was the piping type and the API 6A transition point, §11.17; the
second was the pipe-class code grammar, §11.1.) So a batch's scope must be a
**project-definable category list**, not an enum frozen in code.

#### When this is picked up, the work is

1. a **scope** (category) on `baseline_batches`, from a project-configurable list
2. `reconcile()` restricted to the **intersection** of the two batches' scopes
3. a discrepancy kind distinguishing **not claimed** from **missing**
4. a **coverage** question: is there an accepted batch for every category in scope
5. non-component rows (paint, insulation, consumables) — needs its own design
6. alignment with a procurement **package** concept, which does not exist yet

### 11.20.1 MTO design resumed for piping material-management — 2026-10-02

The previous deferral is historical: the user resumed MTO design while keeping ITP deferred and restricting current focus to piping. See ADR-005 §8 for the accepted current design and the distinction between accepted decisions, deferred decisions and unimplemented behavior.

Confirmed in the resumed design: multiple MTO/sub-MTO documents may exist per process unit and discipline; each piping line has exactly one piping class; Pipes MTO carries both total required length and a project-specification-based estimate or manual value for procurement branch count (not model-segment count); MTO material specs use the material library; unmatched items may remain draft but cannot be accepted/issued; accepted MTO and library bases are revision-preserved; model refreshes are compared without silently overwriting manual changes; shortage dimensions include class, subsystem, system and unit.

The exact MTO↔MR row relationship remains deferred; for now the MR number appears only in the MTO header. The existing batch-scope, reconciliation and procurement-package gaps listed in §11.20 remain open; this design resumption does not implement them.

### 11.20.2 Piping MTO first-phase boundary — accepted 2026-10-02

The user accepted a component-material-only boundary for the current piping phase. Pipes, fittings, flanges, valves and other component-like piping materials are in scope. Paint/coating, insulation and welding consumables that are not represented as piping components are excluded for now; their independent quantities (e.g. area/volume) and take-off basis need a later design. Do not fabricate component rows or infer quantities for those materials. This is a phase deferral, not a permanent exclusion from future piping MTOs, and no non-component calculation has been implemented.

### 11.20.3 Component MTO coverage per process unit — accepted 2026-10-02

The component-material categories in scope must be explicitly defined for the project/process unit. An MTO is not complete for that unit until each in-scope category has an ACCEPTED MTO/sub-MTO, or the category is explicitly recorded as out of scope. A missing batch/category is a coverage gap, never an implicit zero-quantity claim; a DRAFT does not close the gap. Reconciliation compares only categories actually claimed by both source batches, while a separate coverage check identifies required categories without an accepted MTO. The user accepted an independent, project-configurable authority for scope decisions. The current owner-company administrator with `project.configure` configures the project list of authorized people/roles, but is not automatically authorized to make technical scope decisions unless separately nominated. The same nominated engineering authority confirms the initial in-scope component-category set per process unit and may declare a category out of scope. No default authority is inferred; without a nominee/confirmed category set, coverage remains unconfirmed, not zero or complete. Every out-of-scope declaration requires a written reason; when based on a project document, it must cite that document. If a category later returns to scope, preserve the old declaration and reopen the coverage gap until an MTO is accepted; do not overwrite/delete the earlier history. This is an accepted design rule, not implemented behavior. Detailed workflow remains open.

#### Versioned per-unit category scope — accepted 2026-10-02

The in-scope category set for each process unit is versioned. Adding a category opens a coverage gap until an MTO for it is accepted; removing a category follows the out-of-scope process. Changes never rewrite historical scope or coverage.

#### Physical process-unit restructuring — accepted 2026-10-06

A stable-ID rename preserves the category scope; changes below unit level do not change the per-unit category set. A process-unit split/merge/replacement creating new IDs does not automatically transfer the old category declaration or MTO coverage; each successor requires its own versioned category set and per-category ACCEPTED MTO or explicit out-of-scope record. A scope mapping alone does not close successor-unit coverage. See ADR-005 §8 and DR-11. Accepted design; not implemented.

### 11.20.4 MTO authorization and access-level review deferred — 2026-10-02

The user directed that work continue on system logic/principles while permissions and access levels are deferred. Preserve the earlier decisions about configurable scope authority as history, but do not finalize or implement role grants, nomination administration, controller policies or segregation-of-duty rules for MTO/category-scope actions until access design is reopened. In particular, who may create, edit, accept or resolve an MTO, who may declare scope and who configures those nominees remain undecided for implementation. This deferral does not retroactively alter the already delivered MR-01/MR-02 slice.

### 11.20.5 Gross-MTO versus net-estimate row identity — accepted 2026-10-05

Compare a gross MTO row with a net model/isometric estimate only through the exact composite identity: project-scoped MTO source document/sub-MTO + its accepted revision or immutable batch identity + `mto_item_no`. The row number is not assumed globally unique across MTO documents or revisions and is distinct from the material-library item ID. The model/engineering record must retain a stable reference to the exact immutable accepted MTO row, resolving the full composite identity; the user selected a direct FK/reference to the stable identifier of the exact immutable accepted MTO row, targeting a separate accepted-row snapshot store; DRAFT rows remain in staging and cannot be FK targets. Keep `mto_item_no` for display, not as the sole join key. Snapshot rows are immutable and resolve the full composite identity. Acceptance is all-or-nothing per one MTO document/sub-MTO revision: validate every row in that submission, create all immutable accepted-row snapshots and mark only that batch accepted in one transaction; any validation/snapshot failure rolls back all snapshots and leaves that batch DRAFT. Sibling MTO documents/sub-MTOs are independent; accepting one does not close another's coverage gap. Physical table/column names and transaction implementation remain to be designed. If the reference is absent, leave it unmatched, with no fuzzy match or row-level shortage inference. This does not add MTO-row↔MR/PO linkage. See ADR-005 §8, ADR-006 §10.8 and ADR-018 §9.1. Current implementation does not yet honor this rule.

### 11.20.6 Aggregating sibling MTO documents — accepted 2026-10-05

For distinct accepted MTO documents/sub-MTOs, sum gross quantities only when consumption scopes are disjoint or an explicit additive relationship is recorded between the exact immutable accepted revisions. A pair-level declaration must include a written reason and, when document-based, cite the exact immutable project-document revision—not only its document number or title. For a source registered in project CDE, use a stable reference to the exact CDE revision; number/title are display-only. For a source outside CDE, use a controlled external-evidence record with stable ID, issuer/source, exact document number/revision/date and retained original file or controlled immutable URI plus content hash; hash alone is insufficient. If exact version cannot be retained and retrieved, it cannot support the additive declaration and overlap remains ambiguous. It applies within the overlapping scope and only to rows matching by material-library identity and compatible units; unresolved rows remain ambiguous/unallocated. Without a declaration for overlapping comparable scope, do not auto-sum. A new revision does not inherit the relationship automatically. Additive declarations are append-only; corrections/withdrawals are new superseding records, not edits/deletions. Historical roll-ups retain the declaration state known at calculation time; current roll-ups use the latest effective record, and withdrawal leaves overlapping scope ambiguous/unallocated. Revisions of one document follow ADR-005 §2.2 and do not add to each other. No MTO-row↔MR/PO link is created. See ADR-005 §8, ADR-018 §9.2 and ADR-RECONCILIATION §31. Current implementation does not model these relationships.

### 11.20.7 Physical parent/child scope overlap — accepted 2026-10-05

A parent-level MTO at process-unit or system scope overlaps MTO quantities scoped to its descendants; do not add parent and child values without an explicit additive declaration. Separate/disjoint leaf subscopes may be summed at the parent roll-up. Piping class is a separate grouping dimension, not a physical hierarchy level. This is a design rule, not implemented behavior; see ADR-005 §8 and ADR-018 §9.3.

### 11.20.8 MTO scope attribution for roll-ups — accepted 2026-10-05

When every row in one MTO document/sub-MTO has the same physical scope, store scope on the header and let rows inherit it. If the document contains multiple physical scopes, each row must state its scope explicitly. A row-level descendant scope may refine the header's parent scope; scope outside the header boundary is a conflict and remains unallocated, without silent expansion. Missing or ambiguous row scope remains unallocated; no guessed allocation or cross-scope roll-up is allowed. Physical scope follows process unit/system/subsystem; piping class is an independent grouping dimension, not a hierarchy node. If a required row scope is missing/ambiguous or conflicts with the header boundary, acceptance of the entire document/sub-MTO revision is blocked: retain the batch as DRAFT and create no partial accepted-row snapshots, under the all-or-nothing acceptance rule. Correct and revalidate the batch; sibling documents/sub-MTOs remain independent. Resolve physical scope by stable ID in the authoritative project hierarchy, not free text as operative identity; immutable accepted-row snapshots preserve the exact scope reference, and later hierarchy changes do not rewrite historical assignments. User also accepted: pin the hierarchy revision in each MTO snapshot; never recycle scope IDs, preserve as-of label/path for display, and represent structural changes by a new hierarchy revision; permit cross-revision roll-up only when pinned relationships prove a common scope unambiguously or an explicit immutable mapping/evidence record exists. Such a mapping must name exact source/target scope IDs and hierarchy revisions, relationship type, rationale and exact document evidence when applicable; it is append-only with superseding corrections. One-to-one continuity needs equivalence evidence; split/merge requires proof of complete, non-overlapping correspondence, otherwise scope remains unallocated. Mapping does not authorize quantity addition; the additive declaration between exact MTO revisions remains separate. Historical roll-ups preserve mapping state known at calculation; current roll-ups use the latest effective direct mapping, and withdrawal leaves current allocation unresolved without rewriting history. Do not infer transitive mapping chains across revisions unless stable IDs and pinned hierarchies establish equivalence. See the numbered rules in ADR-005 §8, ADR-018 §9.4 and ADR-RECONCILIATION §33. Current implementation does not enforce these rules.

### 11.21 Build order steps 1, 2 and 2b — BUILT (2026-09-24)

The first code and data of this ADR. All three are reference-layer only: no
migration, no schema change to the database, nothing a project can break.

#### Step 1 — `b165MaterialGroup`

Five public sources were compared before a single value was written, and they
contradict each other badly: F316 is group 2.2 in four and 2.3 in the fifth;
A351 CF3 is 2.1 in one and 2.3 in another; F11 and F22 move a whole group
between sources; A350 LF3 and LF6 appear in three different groups.

**Only the unanimous rows were written** — 15 of them, every one
`verified: false`:

| group | materials |
| --- | --- |
| 1.1 | A105, A350 LF2 (Cl 1 and 2), A216 WCB, A515 70, A516 70, A537 Cl 1 |
| 2.1 | A182 F304, F304H, A351 CF8, A240 304 |
| 2.2 | A182 F316, F316H, A351 CF8M, A240 316 |

Everything contested is **absent on purpose**. An absent group reads as
*unknown*; a wrong group does not fail, it silently returns a different
pressure. §11.14's lesson applied before the fact rather than after it.

**A105 forced a design decision.** It deliberately has no grades — the
specification is the whole material, and `MaterialLibraryTest` says so
explicitly — yet it is the most common flange material there is. So the schema
now lets a **gradeless** spec carry properties, and the resolver reads the grade
first and the spec second. Validator rule 32 permits spec-level properties
*only* on a gradeless spec, so the two can never both answer.

`properties()` was extended to see the spec-level fallback as well. Without
that, it and `b165MaterialGroup()` would have disagreed about A105 — not a bug
today, but one waiting.

Three new validator rules, each **made to fail on an injected violation** before
the data was added: a group number must look like one; a spec and its grade may
not both declare one; only a gradeless spec may carry spec-level properties.

#### Step 2 — `flange-pt-ratings.ref.json`

> **CORRECTED 2026-09-24, after the user asked what sources were used for
> B16.5.** The paragraphs below described the figures as a "transcription" and
> the pack's `source` field read *"ASME B16.5 pressure-temperature ratings"* —
> both of which imply the standard was read. **It was not. Nobody on this
> project has a copy.** The five sources listed for step 1 are real and were
> fetched; the 6x6 rating table was **not in any of them**. It was written from
> working knowledge and only then tested. The `source` field now says so, and a
> `corroboration` block records every check and its result. See §11.22.


`(standard, edition, material group, class, temperature) -> kPa`. Only the
carbon-steel curve, six temperatures, six classes.

**The transcription was made to corroborate itself twice before being trusted:**

1. **Linearity.** Above Class 150, a B16.5 rating is linear in the class number.
   It holds at every temperature — worst spread 0.44 %, typically under 0.1 %.
   A mistyped digit breaks the line, and this is now validator rule 2.
2. **The Class 800 cross-check.** The API 602 formula `1/3·600 + 2/3·900` applied
   to this curve returns **1975 psi at 38 °C** — exactly the figure the OMB
   catalogue prints for a Class 800 forged valve (§11.13). A formula from one
   standard reproducing a vendor's published number off another standard's curve
   is strong evidence the numbers are real.

It is still **corroboration, not verification**, so nothing is marked verified
and **the lookup refuses**:

| method | behaviour |
| --- | --- |
| `ratingAt()` | **null** while the curve is unverified |
| `indicativeRatingAt()` | the number, for display that says so |
| `sufficientFor()` | **null, not false** — treating unknown as false reports a compliant line as a violation |

This mirrors `properties()` / `verifiedProperties()` in the material library.
The day a purchased copy arrives, curves flip to `verified: true` one at a time
and the feature switches on with no other change.

**Seven validator rules, every one made to fail on an injected violation:**
verified-claim, linearity, Class 150 must sit *below* the line the higher
classes make, monotonic decrease with temperature, derived-class integrity
(sources exist, weights sum to 1, not also tabulated), **B16.47 may never
acquire a series axis**, and every curve names an edition year.

Class 800 is carried as a **formula**, not a row — `derivedFrom` with weights —
and rule 5 forbids it also being tabulated.

#### Step 2b — the gap above NPS 24 now speaks

The §11.14 defect was never the missing data. It was the **silence**:
`forJoint()` returned `null` both for *"this joint needs no check"* and for
*"this pack has never heard of NPS 30"*, and a caller reading the first meaning
passes every large flange on a project.

`flange-bolting` now carries a **`coverage`** block naming what it claims:

| standard | series | NPS | rows |
| --- | --- | --- | --- |
| B16.5 | — | 1/2 – 24 | **yes** |
| B16.47 | **A** | 26 – 60 | **no, declared empty** |
| B16.47 | **B** | 26 – 60 | **no, declared empty** |

and a `covers(standard, series, nps)` method. `null` **plus** `covers() === false`
is the honest **unknown**. The two B16.47 series are declared with no rows
because there is no verified data for them — declaring the gap is the whole
point, since a gap that is merely absent cannot be reported.

The **series axis lives here and only here**: A and B differ in bolt count,
diameter and circle, while sharing pressure-temperature ratings (§11.15). Rule 6
of the ratings pack enforces the other half of that split.

#### What remains before this can be switched on

Every number in both packs is unverified, and both consumers refuse rather than
guess. **The blocking input is a purchased copy of B16.5, B16.47 and B16.11** —
not more engineering.

### 11.22 What the B16.5 figures actually rest on (2026-09-24)

**Trigger (user):** *"which sources did you use for B16.5?"* The answer turned
out to be two different answers for two different things, and §11.21 had
presented them as one.

#### The material groups — five fetched sources, and the disagreement is recorded

| source | what it gave |
| --- | --- |
| texasflange.com/blog/asme-b16-5-standards | a Table 1A transcription for groups 1.1, 2.1, 2.2 with forgings/castings/plates columns |
| weldfabworld.com/asme-b16-5-flange-pressure | a group table plus the Class 150 rating at 38 C |
| blog.projectmaterials.com/.../asme-b16-5-pt-rating-tables | group assignments and a worked interpolation |
| midstreamcalculator.com/.../pressure-ratings-fundamentals | groups plus several psig spot values |
| sunhyings.com/.../asme-b16-5 | groups 1.1, 2.1, 2.2, 2.3 |

These were fetched, their contradictions were tabulated, and **only the 15
unanimous rows were written**. That part stands.

#### The rating curve — no fetched source at all

**The 6x6 table was not in any search result.** It was written from working
knowledge of the standard. The searches returned only scattered spot values.

That is a legitimate way to get a starting dataset. Describing it as a
*transcription*, and stamping the pack `"source": "ASME B16.5 pressure-
temperature ratings"`, was not. Anyone reading the pack would reasonably
conclude someone had the document open.

#### What the numbers do rest on

Three internal checks, run **before** the data was written:

| check | result |
| --- | --- |
| above Class 150 the rating is linear in the class number | holds, worst spread **0.44 %** |
| Class 150 sits below the line the higher classes make | holds at all six temperatures |
| API 602's Class 800 formula on this curve vs a published catalogue figure | **1975 psi**, exactly what OMB prints |

and seven external spot checks, run **after** the user's question:

| source | claim | deviation |
| --- | --- | --- |
| weldfabworld | Group 1.1, Class 150, 38 C = 19.6 bar | **0.00 %** |
| midstream | A105 Class 150 = 285 psig | −0.25 % |
| midstream | A105 Class 600 at 150 F = 1415 psig | +0.60 % |
| midstream | A105 Class 600 at 600 F = 1095 psig | **+3.33 %** |
| projectmaterials | Class 300 at 300 F = 655 psig | +0.16 % |
| projectmaterials | Class 300 at 400 F = 635 psig | −0.36 % |
| projectmaterials | Class 300 interpolated at 350 F = 645 psig | −0.04 % |

Six of seven within 0.6 %. **The seventh is the useful one:** at 600 F the error
is 3.3 %, because our temperature grid is 100 C wide there and the standard's is
not. That is now written into the pack as a warning against reading that region
off this curve — a limit discovered by checking, which is the entire argument
for checking.

#### The correction

The pack's `source` now states plainly that it is **not** transcribed from
B16.5 and that it is an informed reconstruction. A `corroboration` block carries
every check above, with an explicit opening line: *this is not provenance; every
check was run after the numbers were written, so it can only fail to disprove
them.*

Nothing about `verified: false` changes. The lookup still refuses.

#### The lesson

> **Corroboration is not provenance, and a `source` field is a claim like any
> other.** Six independent numbers agreeing to half a percent is good evidence
> the figures are right. It is not evidence that anybody read the standard, and
> a pack that says otherwise will be cited as though somebody had.

This is the same failure recorded in §11.21's own dead ends, one level up: *a
docblock justification may be the author's assumption rather than the user's
requirement.* Here it was the author's assumption about the author.

---

## §11.23 — The standard arrived. What the guesses were worth.

The project owner supplied a purchased copy of **ASME B16.5-2025** (a revision
of B16.5-2020, which is the edition the pack had been claiming). It is 274
pages, text-based, and it never enters git — it sits in the gitignored
`standards-source/` drop folder created in §11.22, and only extracted values
with a citation reach the repository.

Two things were read out of it: the **material-group assignments** that live in
`materials.ref.json`, and the **45 SI pressure–temperature tables** (Table 2-1.1
through Table 2-3.19) that are now the whole of `flange-pt-ratings.ref.json`.

### What the reconstruction was actually worth

§11.22 admitted that the Group 1.1 curve had been written from working knowledge
and dressed up with a `source` field that implied a transcription. It can now be
scored against the real thing, point by point — 36 comparable values:

| | |
| --- | --- |
| mean absolute error | **0.42 %** |
| worst error | **2.61 %** (Class 300 @ 500 °C) |
| values within 0.3 % | every single one at or below 400 °C |
| the 500 °C row | uniformly **~2 % high**, all six classes |
| temperatures carried | **6 of the 16** the standard tabulates |
| classes carried | 6 of 7 — **Class 400 was missing entirely** |

That is the honest shape of a confident reconstruction: accurate enough to
survive any review that spot-checks a few numbers, and wrong in ways that only
the document reveals. The missing 50 °C row alone changes what an interpolation
at 69 °C returns.

### The spot check that was blaming the wrong party

§11.22 recorded seven external spot checks, six inside 0.6 % and one at
**+3.33 %** (Class 600 at 600 °F), and concluded that our coarse temperature
grid was the cause. It was not.

| | psig |
| --- | --- |
| midstreamcalculator.com claimed | 1,095 |
| **ASME B16.5-2025 Table 2-1.1C says** | **1,135** |
| our reconstruction interpolated | 1,131 |

The source was wrong by 3.5 %; we were right to within 0.31 %. The lesson is the
opposite of the one recorded: **an external spot check is only as good as the
page you checked against, and a disagreement does not tell you which side moved.**
The three *internal* consistency checks — linearity in class number, Class 150
sitting below the line, the API 602 Class 800 formula — all held, and all three
were pointing at the right answer while the external check was pointing away
from it.

### Scoring the five web sources

Each source's claims were replayed against the standard. Only claims about
materials we actually hold could be scored:

| source | checkable | right | **wrong** | accuracy |
| --- | ---: | ---: | ---: | ---: |
| texasflange.com | 17 | 17 | **0** | 100 % |
| sunhyings.com | 6 | 6 | **0** | 100 % |
| midstreamcalculator.com | 10 | 7 | **3** | 70 % |
| weldfabworld.com | 18 | 11 | **7** | 61 % |
| blog.projectmaterials.com | 12 | 6 | **6** | **50 %** |

Two of the five were coin flips. projectmaterials — the most professionally
presented of them — got F316 and F316H wrong, F321 and F347 wrong, F51 wrong,
and LF6 Cl. 1 wrong. weldfabworld put F9 and C12 in 1.7 (really 1.14), F5 and C5
in 1.5 (really 1.17 and 1.13), and CF3/CF3M in 2.3 (really 2.1 and 2.2).

### The discipline paid for itself exactly

§11.21 wrote **only the 15 rows where every source agreed** and refused the
seven contested ones. Against the standard:

- **15 of 15 confirmed. Zero contradicted.**
- Every one of the **16 wrong claims** in the table above falls on a material
  that was refused.

The consensus filter did not merely reduce the error rate; it caught **100 %**
of the errors. The refusal was not caution for its own sake — it was the
mechanism. And the 60 materials that were refused for want of agreement are now
resolved from the document, taking the pack from 15 guessed assignments to
**75 read ones**.

### Two rules the document refuted

The validator carried two rules built from a *model* of how B16.5 is
constructed. Real data broke both, and in both cases **the document outranks the
model**:

1. **Linearity in class number is not a law.** Group 2.8 (20Cr–18Ni–6Mo: A182
   F44, A351 CK3MCuN, A240 S31254) departs by up to **10.7 %** — Class 2500
   holds 430.9 bar all the way to 100 °C while Class 300 has already started
   falling, so the high classes are clipped by a ceiling the low ones never
   reach. Groups 1.18, 3.6 and 3.19 depart by 1–5 %.
2. **A rating can rise with temperature, on paper.** Group 3.3 Class 2500 goes
   128.2 bar at 350 °C to 128.3 at 375 °C. The standard prints bar to one
   decimal; a falling curve can tick up one digit in the last place.

Neither rule was deleted. Both were made to **depend on who is speaking**:
fatal on an unverified curve, where they are still the only typo detector there
is, and a recorded note on a verified one. A rule that contradicts a
transcription is a rule about the transcriber.

### `verified` stopped being a mood

Three tripwires — one validator rule for ratings, one for material properties,
and ten tests — existed only to make `verified: true` unreachable while nothing
had been read. All three fired on this change, which is what they were for. None
was deleted; each was **narrowed into the thing it was always meant to become**:

> `verified` is a **citation**, not a flag. It requires a named document, a
> numbered table, and a date. An unverified value that cites a table is now
> *also* a failure — a stale citation must not outlive the reading.

The ten tests were inverted rather than removed, and one was **split**: the
refusal contract still has to hold for B16.47, which was not purchased, or
verifying B16.5 would have quietly verified everything. Likewise
`MaterialLibraryTest` now asserts that the B16.5 group is the **only** verified
property in the pack — allowable stresses, yields and tensiles are still
unverified, and one purchase must not launder the rest.

### The consumer did not change

`FlangePtRatings::ratingAt()` began returning real pressures without a single
line of the class being edited. The refusal had always lived in the data. That
is the payoff for holding the input and never pre-computing a conclusion you
cannot defend — the thing §11.16 decided and §11.21 built.

**B16.47 remains unverified, has no curves, and is still refused.**

### Dead end recorded

> *A validator rule derived from how a standard "must" be built is a hypothesis
> about the standard, and it should be labelled as one from the start.* Both
> refuted rules were written with comments asserting they were properties of the
> standard. They were properties of most of it. Had they been fatal on verified
> data, the correct transcription would have been rejected in favour of the
> model — the exact inversion of authority this ADR spent §11.22 arguing against.

---

## §11.24 — B16.47-2025, and what happens when two standards disagree

The owner then supplied **ASME B16.47-2025** (revising 2020) — *Large Diameter
Steel Flanges NPS 26 Through NPS 60*, 142 pages. Same handling as §11.23: the
document sits in the gitignored drop folder, only extracted values are
committed.

Transcribed: **27 SI rating tables** (Table 3 … Table 28), 578 temperature
points, **2,971 values**. B16.47 rates 27 groups against B16.5's 45 — the
**group 3.x nickel alloys are absent from B16.47 entirely**.

### Two things that were hearsay are now verified

Both had been recorded from vendor pages and carried in the pack as unverified
claims. The document settles them:

- **Series A and B share one rating table.** The 27 rating tables are indexed by
  material group with **no series axis at all**, while the dimension tables
  (32+) are per series. A and B differ in bolt circle and bolt count, not in
  what pressure they hold — and they remain **not interchangeable** at the same
  NPS and class, which is a dimensional fact, not a rating one.
- **Class 75 is Series B only.** Dimensions for Class 75 appear under Series B
  (Table 37); Series A starts at Class 150 (Table 32). `definesClass()` was too
  coarse to catch a Series A line calling for Class 75, so
  `classesForSeries()` was added.

Class 75 is also **not half of Class 150**. It is 9.8 vs 19.6 bar at 38 °C, but
it drops off the table above 350 °C while Class 150 runs to 538 °C. Deriving it
by halving would invent ratings in a band where the standard refuses to give
one.

### The material groups agree perfectly

B16.47 Table 1 is the same *List of Material Specifications* as B16.5 Table 1A.
Replayed against the 75 assignments read from B16.5 yesterday: **55 comparable
cells, 55 agree, 0 clash.** An independent confirmation of §11.23's extraction
from a second purchased document.

### The ratings agree almost perfectly — and the exceptions are the finding

Of the **2,100 values the two standards share** (classes 150–900, matching
groups and temperatures), **2,089 are identical to the last printed digit —
99.48 %**. Eleven are not.

Those eleven were arbitrated *without* reference to either standard's opinion:
above Class 150 a rating is linear in the class number, so **the other classes
in the same row of the same table** predict the disputed cell.

| group | °C | class | B16.47 | B16.5 | line predicts | on the line |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| 2.5 | 475 | 600 | 63.7 | **66.7** | 66.76 | B16.5 |
| 2.11 | 500 | 300 | 34.1 | **32.1** | 32.19 | B16.5 |
| 1.3 | 500 | 600 | 22.7 | **21.7** | 21.76 | B16.5 |
| 2.5 | 38 | 900 | 148.1 | **149.1** | 149.12 | B16.5 |
| 1.1 | 300 | 600 | 79.6 | **79.8** | 79.80 | B16.5 |
| 1.4 | 450 | 300 | 21.4 | **21.5** | 21.46 | B16.5 |
| 2.8 | 350 | 300 | 37.6 | **37.7** | 37.71 | B16.5 |
| 1.9 | 100 | 400 | 68.6 | **68.7** | 68.68 | B16.5 |
| 1.18 | 250 | 400 | **61.8** | 61.7 | 61.77 | B16.47 |
| 1.2 | 150 | 900 | **150.5** | 150.6 | 150.53 | B16.47 |
| 2.4 | 400 | 150 | 5.5 | 6.5 | — | *see below* |

Eight of the nine decidable cells favour B16.5, and in seven of those B16.5 sits
within **0.09 bar** of the prediction while B16.47 is **0.94 to 3.06 bar** away.
The two that favour B16.47 differ by one digit in the last place in both
directions — rounding noise, not error.

**The Class 150 case decides itself by inspection.** Class 150 sits below the
line by design and cannot be arbitrated that way, so read down the column
instead: B16.47 Table 20 prints **5.5 bar at both 400 °C and 425 °C**, while
B16.5 prints 7.4 / 6.5 / 5.5 / 4.6. B16.47 appears to have repeated the 425 °C
cell.

### An independent check found the same cells

The monotonicity rule — *a rating must fall as temperature rises* — knows
nothing about B16.5. Run against the fresh B16.47 transcription it failed on
exactly two cells:

- group 2.5 class 600 **rises** 63.7 → 64.4 bar from 475 °C to 500 °C
- group 2.11 class 300 **rises** 33.4 → 34.1 bar from 475 °C to 500 °C

Both are cells the linearity arbitration had already flagged, and in both the
B16.5 figure restores a falling curve. **Two checks built for different purposes,
neither aware of the other, landed on the same printing errors.** That is worth
much more than either alone — and it is the same lesson as §11.23's spot-check
reversal, arriving from the other direction.

### The policy: record it, then raise a discrepancy

Standing instruction, and it is exactly right here. **Neither transcription was
altered.** Each pack holds what its own document says; correcting B16.47 to
match B16.5 would destroy the only evidence that they differ, and on a project
governed by B16.47 the B16.47 figure is the **contractual** one no matter what
the line predicts.

So the disagreement became data: `crossStandardDiscrepancies` records all
eleven cells with both values, both table numbers, the prediction and the
verdict, and `discrepancyFor()` lets a caller ask. The caller still gets the
figure from the standard it asked for.

Three validator rules were added, **each made to fail first**:

1. a shared cell that disagrees and is **not declared** is a failure — so a
   future transcription error cannot hide inside a 99.5 % match;
2. a **declared** discrepancy where the packs now agree is *also* a failure — a
   stale record is a false accusation against a standard;
3. a class must belong to at least one dimensional series.

The monotonicity rule was taught to accept a declared discrepancy as a recorded
note rather than a failure, because refusing it would mean refusing to record
what the standard prints — which is the opposite of the policy.

### Dead end recorded

> *I wrote an expected value into a test from memory instead of reading it out
> of the pack, and it was wrong* — `1.10 / 400 / 300 °C` asserted 49.9 bar
> against an actual 60.0. The test caught it immediately, but it is the precise
> failure mode this whole ADR exists to police, committed inside the test that
> was meant to police it. **Assertions about transcribed data must be read out
> of the transcription, never recalled.**

---

## 12. PMS entry and source links — reconciliation (2026-09-29)

**Status:** user-authorized initial PMS entry/source-linking capability; **partly implemented with open conformity gaps**. Recorded retrospectively after the user required ADR coverage for every new capability. This amendment does **not** supersede §7 #4, §10, §11.7, §11.10, ADR-014 or ADR-016. The older “no screen” statement in §9 is historical; the required bulk-oriented screen is still incomplete.

### 12.1 Trigger and confirmed additions

The user requested connection of data to PMS, selected **both** reference-library data and project-approved material definitions, then corrected the premise: no PMS entry interface existed and it must be added in this step.

The initial interface now handles class headers, numbered notes and one material row at a time. Explicit library material selection supplies group/spec/grade; explicit size selection supplies DN/NPS. Original document wording is not automatically replaced. A link to a project definition records the selected approved version, not an automatic application of its properties.

New reference links require currently derived project approval, not a posted state or formal N/A acceptance alone. Existing links remain pinned if a definition is superseded, with an advisory and explicit replacement required. [ADR-017](ADR-017-Project-Material-Review-and-Finding-Dispositions.md) governs the definition workflow.

### 12.2 As built — not a substitute for the accepted target architecture

Migration 56 adds source-document/revision **text**, entry_version/provenance columns and append-only before/after events. `PmsEntryService` uses entry versions to reject stale edits. These are transcription audit/concurrency features, **not document revision containers, publication locks or AFC revision pins**.

Current entry access is active PIPING-eligible membership plus company ownership of the class; other project participants read. This was an implementation choice, not an explicit user decision on a PMS-specific atomic capability. Do not describe it as a newly user-approved authorization policy. Its relationship to role-based entry/publishing authority requires review before expanding the surface.

The interface uses existing per-size body uniqueness and checker findings. Semantic discrepancies do not prevent faithful transcription; structural/reference-selection/access errors may refuse a write. No delete endpoint is offered. Selected reference facts and version/source identifiers are retained; complete source-pack replay is not implemented.

### 12.3 Unchanged decisions and open gaps

| ID | Governing decision | Current gap / obligation |
|---|---|---|
| PMS-ADR-01 | §7 #4 and §11.7: one PMS document per project; classes belong to its revision; published data locks; AFC lines stay pinned | `source_document`, `source_revision` and entry_version do not provide this. Current classes/rows are updated in place. Integrate existing CDE document/revision identity, immutable published snapshots and line pins before treating this UI as a released-PMS workflow. |
| PMS-ADR-02 | ADR-014 §§2, 3.1, 10: entered value/unit plus canonical mirror | The header currently exposes C/mm-only numbers. Source text is not a structured entered-value/unit pair. Reuse existing units/preference machinery; no canonical-only exception is accepted. |
| PMS-ADR-03 | §11.10 and §11.12 step 8: bulk-oriented transcription | Current UI is single-row entry. Add explicit selected-size/bulk entry with per-size storage, preview, conflict handling and acceptance tests; do not reintroduce bands or assume an import parser already exists. |
| PMS-ADR-04 | ADR-016 §§2.4–2.7, 4: project adoption/edition and structured citations | Material/size JSON references are wired, but dimensional standard remains free text without PMS structured catalogue citations. Keep unresolved source text while linking resolvable identity/edition explicitly. |
| PMS-ADR-05 | ADR-013 §10: service AND SQL archive protection | Service blocks note writes, but static migration review finds no archive predicate on the existing `piping_class_notes` write policy. Add/test the database backstop; do not claim raw-SQL archive safety for notes from an HTTP test. |
| PMS-ADR-06 | §11.6, §11.8, §11.9, §11.12: full header/notes, NDT, SP/manual-valve and rules coverage | Notes and much of header/body have UI; special-requirements entry, NDT/ITP flow, branch/per-size-policy screens and detailed manual-valve/SP entry are not completed by this delivery. |
| PMS-ADR-07 | New entry authority must be explicit and distinct from review/publication authority | Resolve/document PMS write-capability policy; the current company/member rule is as-built, not proof of accepted RBAC design. |
| PMS-ADR-08 | §11.13, corrected by later standards amendments: rating identity includes governing standard, not bare class-number comparisons | A textual pressure_rating and separate dimensional_standard do not establish a structured rating-standard identity. Preserve source wording while implementing the accepted rating model; no number-only sufficiency inference. |

These are implementation gaps/review items, not new user-approved deferrals or reasons to weaken the original decisions. Detailed evidence and scope of this review: [ADR reconciliation](ADR-RECONCILIATION-2026-09-29.md).

### 12.4 Alternatives and consequences

- A separate “new PMS ADR” replacing ADR-008 was rejected: this is the same subject and must retain its user decisions and corrections.
- Calling row edit counters “PMS revisioning” was rejected: document revision, publication state and line pinning are different invariants.
- Treating a warning or an approved material reference as class publication/engineering clearance was rejected.
- Retrospectively amending away bulk entry, document integration or unit provenance to match the UI was rejected.

The entry UI remains a partial transcription capability. The code still uses existing PMS tables; a documentation warning is **not** a technical isolation/publication barrier. Existing consumers must be reviewed when closing PMS-ADR-01; this amendment does not claim they are already revision-safe.

### 12.5 Traceability and acceptance

Code: `app/app/Services/PmsEntryService.php`, `app/app/Http/Controllers/PmsController.php`, `app/resources/views/piping/pms.blade.php`, migration `2026_09_29_235000_add_pms_entry_provenance.php`.

Tests: `app/tests/Feature/PmsEntryTest.php`, `PmsEntryMigrationTest.php`, existing PipingClass/definition-review suites. Earlier reported evidence is in [PMS delivery report](PMS-ENTRY-2026-09-29.md): 310 selected tests / 1,845 assertions, then focused/UI reruns. **Those tests did not prove complete ADR conformity and were not rerun for this documentation review.**

Before closing the above gaps, add acceptance cases for whole-document revision ownership, publication lock through service and raw SQL, old AFC line stability, explicit new-revision impact, bulk per-size independence, entered-unit round-trip/preference handling, standards-edition/citation preservation and SQL note archive refusal. Published PMS compatibility must not be inferred from entry-form success.

## 13. Decision-review items, pending user decision (2026-09-29)

See [decision review](ADR-DECISION-REVIEW-2026-09-29.md), separate from §12's implementation reconciliation.

- **DR-03:** §6/§9's missing size/schedule → “physically unbuildable” inference is overbroad for incomplete or out-of-scope reference data. Later unknown-result amendments do not consistently update that original contract or its consumers.
- **DR-04:** §11.9's operator monotonicity is a heuristic, not proof that a source row is wrong. DN and valve type alone do not establish comparable torque/design conditions. Proposed: advisory review unless a cited Spec/design basis supports a stronger conclusion; no automatic engineering clearance either way.

No runtime checking rule or user decision was changed by this entry. Whole-document revisions, publication locks, per-size storage and bulk transcription remain valid accepted obligations; incomplete implementation is not evidence they should be removed.

## 14. Accepted DR-03 — distinguish reference absence from proven nonconformity (2026-09-29)

User explicitly selected `distinguish_results`. Distinguish (1) structurally invalid input, (2) insufficient reference data or absence of coverage, (3) project-specific definition requiring review, and (4) evidenced nonconformity against an identified standard. A failed lookup alone proves neither physical impossibility nor standards nonconformity. These are semantic result categories, not a final enum/schema specification; findings can carry independent dimensions when applicable.

This supersedes older blanket INVALID/physically-impossible interpretations of missing size/schedule data. It grants no automatic permission to use a material and changes no approval rules. Existing B16.9 coverage through NPS 48 remains; out-of-scope items must follow project-Spec definition/review, not be labelled B16.9 reference data. Implementation, legacy finding migration and regression tests are pending. No runtime change was made.

## 15. Accepted DR-04 — operator heuristic is advisory without evidence (2026-09-29)

User selected `advisory_unless_evidenced`. The DN/operator-effort monotonicity pattern alone is a review warning, not proof of a transcription error and not independently grounds for making data invalid or non-consumable. An identified Spec requirement or sufficiently comparable, cited design evidence is required to assert engineering nonconformity. This supersedes §11.9's categorical error inference and its standalone consumption effect. It does not clear other findings, waive approval, grant reviewer authority, or automatically authorize material use. Implementation must distinguish advisory findings from blocking findings; legacy findings and `isConsumable()` behavior need explicit tests. No runtime change or test run occurred in this decision step.

## 16. DR-01 dependency boundary (2026-09-29)

User authorized implementing DR-01. The first increment uses CDE and the existing ITP structured-reference consumer under ADR-016 §10. PMS remains without real revision ownership and structured citations; this shared reference-snapshot work does **not** close PMS-ADR-01 or PMS-ADR-04, freeze current PMS rows, or protect AFC line pins. A separate integration increment must implement §11.7/§12 without relabelling entry counters as document revisions.

## 17. User decision — configurable PMS authors per project (2026-09-29)

**ACCEPTED:** after requesting continuation of the PMS document/revision hierarchy, the user selected `project_configurable` in `pms_hierarchy_next`: project-specific users or roles control PMS entry/editing; until configured, PMS content writes must be refused. This replaces §12.2's as-built membership-only authoring assumption as the target policy. It grants neither document-publication nor standard-adoption authority; DR-02 adoption authority is still explicitly deferred.

Implementation is pending. Existing code combines active PIPING membership with row company ownership; the new selection does not, by itself, explicitly waive inter-company ownership. Before widening writes for a shared project PMS, clarify whether a designated author may edit the entire PMS or only their company's rows. Do not silently assign either scope. Existing project configuration patterns (`project.configure` and owner-company administration) have been inspected, but the selection did not create a new universal publisher role.

The already accepted one-PMS-per-project/revision-container and frozen AFC-line decisions in §11.7 remain. Existing text revision labels/edit counters must not be converted into evidence of publication or AFC acceptance. No code, schema or data changes have yet been made in this decision increment.

### 17.1 Author scope — explicit user answer

User selected **`own_company_only`** in `pms_author_company_scope`. Project nomination is an additional condition; it does not grant cross-company content editing. Active project/PIPING membership and the row's owning company remain required. Finalized content is not made editable by nomination; publication and standard adoption remain separate.

## 18. Author-policy prerequisite implementation design (2026-09-29)

**Scope of this increment:** implement the accepted §17/§17.1 authorization prerequisite and its UI/history; do not claim §11.7 PMS revision containers/AFC pins have been built. The hierarchy itself remains the next integration increment.

- One append-only sequence of author policies per project. Selectors name a project user or a company-scoped role (`user:<uuid>` / `role:<company-uuid>:<role-code>`), matching the existing review selector grammar but not its approval-stage logic. No implicit default grants, including for administrators. An explicit empty selection suspends all PMS entry; configuration does not change existing content or engineering approval.
- Configuration reuses existing `project.configure` authority and owner-company administration pattern, plus active participating membership and writable project checks. This is not a new publisher/approver role. Eligible nominees must currently be active participating PIPING members; role grants are evaluated against live membership, not a cached list of individuals.
- Configuration versions, reason, actor and timestamp are retained. A submitted expected version prevents overwriting a newer policy. Configuration and PMS entry serialize on the existing `pms-entry:<project>` transaction lock; entry authorization is rechecked **after** that lock.
- Existing public PMS header/item/note writes all go through PmsEntryService. They fail closed without a matching current policy; row-company ownership is still enforced. The immutable PMS entry event records the actual policy ID; a database event guard rechecks the actor and policy. Application authentication supplies the actor; the shared DB tenant context is not independent authentication of a human.
- Permission enforcement scope: PMS entry endpoints/service and their transactional audit path, with existing RLS ownership. General internal engineering fixture/import code and arbitrary raw content-table DML are not converted into per-user-RBAC APIs by this increment. Do not present audit-insert protection as a blanket direct-SQL write barrier. Actual version/publication guards still need to cover **all** future PMS content writers.
- Migration adds policy/history metadata only, no default selections or fabricated history on existing events. Current legacy content stays readable, but its entry UI becomes read-only until configured. The separately identified notes archive gap (ADR-013 §11) is repaired using NULL-safe tenant expressions and separate archive INSERT/UPDATE vs DELETE protections; this does not change archive policy.

Alternatives rejected: nomination as publication/adoption authority; automatic nomination of all current members; cross-company writes; mutating past policies; inheriting material-review stage authority. Acceptance: no-policy refusal, direct-user/company-role grants, live role/member/account revocation, company/project isolation, explicit suspension, stale policy rejection, immutable history, current-policy audit stamping, archive and empty-GUC safety, FA/EN forms. Full hierarchy acceptance remains §12.5 and is not closed by these tests.

### 18.1 Implemented and verified prerequisite — not PMS revisioning

Implemented `PmsAccessService`, immutable project author policies (migration 235700), existing header/item/note entry checks after the shared policy lock, audit policy-ID stamping, and FA/EN configuration/history UI. Explicit-empty suspension grants nobody; migration/seeding nominates nobody. Company ownership remains mandatory. Existing configuration authority is reused; author nomination grants no publication/adoption authority.

Verified final serial sets: PmsAccess/PmsEntry/PmsEntryMigration/DocumentStandardSnapshot **47 tests / 343 assertions**; ProjectArchive/FittingReview **44 / 149**. Includes real two-connection wait/revocation, cross-company/cross-project scope, role/account changes, legacy preservation and raw notes archive checks. Live read-only HTTP smoke: two actors × FA/EN, 200; no visual-browser certification. Source reports are linked in [the delivery report](PMS-AUTHORING-POLICY-2026-09-29.md).

PMS-ADR-05 notes archive/empty-GUC gap is closed in this scope. PMS-ADR-07's authoring decision and current entry implementation are covered; document custody/publication integration remains separate. **PMS-ADR-01/04 remain open; no PMS document/revision parent or AFC pin was added.** The source-code entry counter remains an edit counter, not a document revision.


## 19. Document custody deferred; design-only continuation (2026-09-29)

**Explicit user answer:** in `pms_document_custody`, user selected **`defer_custody`** (“فعلاً فقط طراحی تفصیلی”). Leave the document custodian unresolved; prepare the data/revision/AFC design only and do not expand publication. This narrows the current continuation to documentation; it does not cancel §11.7 or reopen the accepted author/company decisions in §17–18. Standard-adoption authority remains independently deferred under ADR-016 §9.1.

The reason for asking: current CDE documents belong to a company; registering assigns the actor's company and issue endpoints use existing document authority. A project PMS can contain classes of several companies. Neither its first class writer nor a project author nomination determines who may own, assemble or publish the whole document. The proposed options of binding an explicitly selected CDE document or configuring a custodian first were **not selected**; neither is an accepted default.

The [detailed design](PMS-REVISION-DESIGN-2026-09-29.md) is a **proposal, not an implemented or fully approved schema**. It covers same-project one-document linkage; revision-scoped class identity/uniqueness; complete graph copy preserving company ownership; SQL publication guards; lock ordering; immutable AFC binding events and impact reporting; DR-01 reference integration without automatic adoption; explicit legacy-unknown handling; rollout and acceptance scenarios. Cross-company mechanical copying must not become an unrestricted cross-company author grant. Custody and the authority to invoke such copying remain activation blockers.

Integration detail recorded without changing CDE policy: `DocumentRegister::issueRevision` archives the old published revision when making the next WIP revision. Historical line readers therefore cannot use a live `isApproved()` check or `currentRevision` lookup as a replacement for recorded historical PMS basis. Proposed historical binding evidence preserves that basis; permission to select an archived revision for a new binding is not granted. WIP-only editing/SHARED review freeze, lineage identifiers and outbox processing are design proposals, not separately user-approved policies.

No application code, schema, reference pack, project data or runtime permission changed. No application tests were rerun for this documentation-only increment. PMS-ADR-01/04 and PMS-ADR-07 document integration remain open; previous author-policy test results belong to §18.1, not this design. Next work must respect this explicit deferral rather than silently starting implementation or repeatedly asking the same custody question.


### 19.1 Design review scope and first answer — SHARED content freeze (2026-09-29)

User selected `pms_continue_after_design=review_design`: review issues individually; no code changes. Custody remains deferred. For the first issue, user selected **`pms_review_01_shared_editing=freeze_shared`**.

**ACCEPTED target, not implemented:** PMS content is read-only while SHARED. Review comments may continue under their existing authority. Editing requires an authorized return to WIP; author nomination alone does not authorize that transition or publication. Prior comments/history remain; an old review outcome must not automatically approve changed content. Published/archived immutability and own-company author scope remain unchanged.

Reason: changing the content underneath an ongoing review can detach the reviewer's conclusions from the content being evaluated. A separate fixed review snapshot with parallel editable draft was offered but not selected; no parallel-draft workflow is introduced. This accepts only the SHARED editing proposal in §19 and detailed-design §4(b), not all other proposed technical mechanisms.

Before implementation, specify how each review round binds to its submitted content and how prior outcomes are distinguished from the current round; preserving comments alone is insufficient. Acceptance must cover HTTP/service/raw-DML attempts on every PMS child while SHARED, comments without content mutation, authorized WIP return, retained old history, no automatic reuse of approval for changed content, and edit/share/return races. Current CDE permits SHARED→WIP, but that fact is not evidence that PMS guards or review-round binding have been implemented.

Documentation only: no code/schema/data/permission changes or application test execution. Custody, standard-adoption authority and the remaining review proposals stay open.


### 19.2 PR-02 — review-round basis explicitly deferred (2026-09-29)

User selected `pms_review_02_review_round_basis=defer_review_round_basis`. The proposed complete immutable content/reference snapshot at each SHARED submission, closing the round on WIP return and creating a new round on resubmission was **not accepted or rejected**; leave the mechanism unresolved and proceed to the next review issue. Do not install it as a default. §19.1 SHARED freeze, preserved history and no automatic approval inheritance remain accepted. Custody and adoption authority remain independently deferred. No code/schema/data changes or tests; this is a decision-status update only.


### 19.3 PR-03 — PMS supersession at publication (2026-09-29)

User selected **`pms_review_03_supersession_timing=supersede_on_publication`**. **Accepted PMS target, not implemented:** distinguish the working revision from the current published revision. Merely creating a new WIP revision must not archive/supersede the previously published PMS revision. Normal replacement occurs atomically when the successor is published. Keep previous content immutable and existing AFC line bindings pinned. Publication and AFC eligibility remain separate: this decision does not make a B-coded publication construction-approved or allow silent fallback to an older revision for a new binding. Independent withdrawal/invalidation is outside this decision.

Reason: current `DocumentRegister::issueRevision` immediately archives the current published revision and points the document at a WIP revision, creating a gap in published availability. The alternative of retaining that behavior and accepting the gap was not selected. This amends the PMS integration target described in §19 and design §2/§4(d); it does not assert that the earlier as-built description was false, nor authorize a global lifecycle change for other CDE document types.

Implementation design must separate working/published pointers or equivalent queries; serialize publication and pointer replacement; retain historical release evidence; adapt PMS-specific callers without silently changing non-PMS behavior. Acceptance: opening/editing/sharing or failing publication of a successor leaves the current published PMS unchanged; successful publication replaces it atomically; concurrent publications cannot create two current published bases; old AFC history remains stable; A/B suitability remains independently checked. Data migration must not infer historical publication/withdrawal from current mutable rows.

No code, schema, data or permissions changed and no application tests run. Custody and PR-02 review-round mechanism remain deferred; approving this behavior does not authorize implementation under the design-only scope.


### 19.4 PR-04 — new AFC only against the eligible current PMS publication (2026-09-29)

User selected **`pms_review_04_new_afc_basis=current_eligible_only`**. **Accepted target, not implemented:** a new AFC confirmation must use the current published PMS revision, and that revision must independently satisfy AFC eligibility. If the current publication is not eligible (for example B suitability under the existing application rules), draft line registration/completion may continue but new AFC confirmation is blocked. Do not silently fall back to, or offer ordinary selection of, an older eligible publication as the new AFC basis. Other existing evidence/authorization checks remain required.

Reason: publication supersession accepted in §19.3 does not establish construction authorization. Substituting an older revision would bypass the distinction between the current publication and the permitted construction basis. The alternative of a documented exception for an older revision was offered but **not selected**; no exception authority or endpoint is created. The A/B example describes current application eligibility, not a new universal interpretation of external suitability codes.

Existing AFC lines keep their recorded historical revision and immutable content. This is preservation of evidence, not a decision to revoke their AFC state, authorize continued construction, or resolve independent withdrawal/invalidation. No existing line is automatically upgraded or downgraded by this decision. Reconfirmation constitutes a new confirmation and must revalidate the then-current eligible publication; prior historical binding alone does not satisfy it.

Design consequences: at the AFC transaction boundary, re-read/lock the current published pointer and selected class revision, verify they match and are eligible, then record the binding and transition atomically. Serialize against successor publication so a stale page cannot confirm a superseded basis. Reader/UI should explain the blocking reason without disabling permitted draft work. Do not reuse the historical-reader path as a new-confirmation validator.

Acceptance, not yet executed: eligible current publication plus all other required evidence permits confirmation; ineligible current publication blocks it even if an older A revision exists; explicitly posting an old class/revision is rejected; missing publication refuses confirmation without blocking authorized draft entry; concurrent publication/confirmation yields a consistent ordering; pre-existing AFC history remains unchanged; subsequent eligible publication removes only this gate, not other checks. Legacy unresolved-basis handling remains the separate migration design.

Documentation only: no application/schema/data/permission changes and no tests run. §19 design-only scope, deferred custody, PR-02 review-round basis and standard-adoption authority remain unchanged.


### 19.5 PR-05 — legacy unknown PMS basis: preserve history, block new release (2026-09-29)

User selected **`pms_review_05_legacy_unknown_basis=preserve_flag_block_release`**. **Accepted target, not implemented:** retain the recorded state/history of legacy AFC lines whose historical PMS revision/content basis cannot be established; explicitly label the basis unknown. Do not infer a historical release from class ID, source revision text, edit counter or today's content, and do not attach today's revision as if it were the original basis.

Until the ambiguity is resolved, block a new transition from AFC-confirmed to released-for-construction in the application. Do not automatically revert already released lines or erase the recorded AFC decision. This is an application transition rule, not a field stop-work/continue-work instruction. The warning-only alternative was not selected.

Distinguish documentary reconstruction of a historical basis from a new AFC confirmation. Documentary resolution needs actual supporting evidence and an attributable resolution record; its validating authority and sufficient evidence requirements remain unresolved and must not default to author nomination or project configuration. No operational resolution path is authorized by this decision alone. New/reconfirmation must satisfy §19.4 current eligible publication and other applicable checks; an older-version historical record is not an exception for new AFC. Conversely, a new confirmation must not pretend to repair or prove the missing historical basis.

Design effects: track basis knowledge separately from line release state; preserve source IDs/text and any original audit evidence; record later resolution with its actual actor/time/evidence without backdating. A transition to release must recheck the unknown-basis gate transactionally rather than trust a UI flag. Service/API/import and database bypass cases require coverage. For a line already released, subsequent workflow behavior beyond this new-release gate remains governed by separately specified policy; do not infer broad edit locks or revocation.

Acceptance, not executed: a populated upgrade preserves line state and historical data, sets unknown basis where evidence is absent, and never fabricates a pin; AFC→release refuses while unknown; already released rows remain unchanged; missing/forged resolution evidence cannot clear the gate; a new confirmation records a distinct current eligible basis without rewriting history; archive/tenant isolation and concurrent resolution/release remain safe. Detailed resolution authority/criteria must be decided before enabling that path.

Documentation only. No code, migration, data change or tests. Design-only scope remains; custody, PR-02 review-round basis and adoption authority are still deferred.


### 19.6 PR-06 — minimum evidence for historical PMS basis (2026-09-29)

User selected **`pms_review_06_historical_evidence=require_linked_evidence`**. **Accepted target, not implemented:** resolving an unknown historical PMS basis requires retrievable evidence establishing all three of: (1) the exact PMS content and revision identity; (2) that revision's authorized status at the time of the original AFC confirmation; (3) a traceable explicit connection between that line, that AFC decision and that revision. One or several controlled records may establish these facts; file count is not the criterion.

Reason: a PMS file alone does not prove the line used it; a typed revision label alone does not establish content or historical authorization. A checksum establishes byte consistency, not provenance, authenticity, authorization or use by a line. Current-content similarity, current class IDs and present-day approval cannot substitute for historical evidence. Incomplete or conflicting evidence leaves the historical basis unresolved; do not guess/backfill the missing fact.

Evidence submission alone must not clear unknown status or lift the §19.5 release gate. Authority to review/accept the evidence remains an independent unresolved decision; do not infer it from document upload, author nomination or project configuration. A complete-looking upload is not automatically verified evidence. Preserve the submitted evidence and attributable later decision at their actual recording times, without rewriting the original AFC history. No technical assurance of document authenticity or automated forgery detection is claimed.

This refines the evidence requirement left open in §19.5; its authority requirement remains open. Documentary reconstruction does not constitute new AFC, grant an older-version exception under §19.4, or authorize field work. Prior deferred custody, PR-02 and adoption questions remain unchanged.

Acceptance, not executed: require all three evidence links; one sufficient controlled record or a coherent multi-record chain is permitted; missing facts, unresolved contradictions, label/hash-only or unrelated-line evidence cannot resolve the basis; upload without an authorized acceptance leaves the gate intact; corrections preserve prior records and actual timestamps. Implementation must make evidence retention/retrieval and access checks testable; exact schema and validating authority remain pending. No code, schema, data changes or application tests in this documentation-only increment.


### 19.7 PR-07 — historical evidence acceptance authority explicitly deferred (2026-09-29)

User selected **`pms_review_07_evidence_acceptance_authority=defer_evidence_authority`**. Leave authority to accept historical PMS-basis evidence unresolved. Neither proposed project-configurable independent reviewers nor reuse of existing AFC authority was selected. Do not grant this power by default to authors, uploaders, project configuration administrators or AFC signers. The proposed requester/evidence-provider separation rule was part of an unselected option and is **not a new accepted policy**.

PR-05's unknown-basis release gate and PR-06's three-part evidence requirement remain accepted. Evidence upload alone cannot resolve the unknown basis; no historical-evidence acceptance path may be enabled until authority is explicitly settled. This does not reopen the separately accepted new-AFC current-eligible-basis rule or make reconstruction equivalent to reconfirmation. Custody, PR-02 review-round basis and standard-adoption authority remain independently deferred.

This records explicit deferral, not rejection of the proposed options or authorization to implement either. No code, schema, data, permissions or tests changed. Continue individual design review without repeatedly re-asking this deferred question absent a user request to reopen it.


### 19.8 PR-08 — later conflicting evidence: append history and warn only (2026-09-29)

User selected **`pms_review_08_later_evidence_conflict=append_dispute_warning_only`**. **Accepted target, not implemented:** where new evidence conflicts with a previously accepted historical PMS basis, retain the original acceptance and evidence and append the new conflict/evidence with attribution. Make the dispute visible; recording it does not itself create an additional release-for-construction blocker. The proposed automatic new-release hold was **not selected**. Do not automatically revoke the prior decision, replace the line's basis or revert already released lines.

Reason: silent overwriting erases the earlier decision; hiding the dispute makes contested evidence appear unqualified. Warning-only retains the conflict without granting the reporter automatic authority to stop release or invalidate an accepted historical basis. A warning is not proof that either account is correct or permission to continue field work. Existing authorization and release checks remain applicable.

Keep basis knowledge/acceptance and dispute status distinct. This applies to a conflict with a previously accepted basis; it does not waive §19.5's blocker for an originally unknown basis or §19.6's requirement that unresolved contradictions in an initial reconstruction prevent acceptance. Do not turn the later-dispute flag into an automatic unknown-basis reclassification that silently recreates the rejected blocker. New/reconfirmation still follows §19.4. A subsequent authorized resolution or invalidation is a separate action whose authority/effects are not settled by this answer.

Authority to register a formal dispute and to resolve it remains unresolved; a raw upload or unsupported assertion must not be treated as an authorized adjudication. §19.7 acceptance-authority deferral stands. No permission is inferred from author/config/upload roles. Correction or closure must not erase earlier evidence or backdate records; precise lifecycle/schema are still design work.

Acceptance, not executed: append conflict without mutation of original evidence/acceptance/pin/state; clearly show the disagreement alongside the accepted historical basis; adding the warning alone does not fail an otherwise valid release; all existing blockers still apply; initially unknown/incompletely reconstructed bases do not bypass PR-05/06 through this rule; unsupported upload cannot change an accepted basis. No code, schema, data, permissions or tests changed in this documentation-only increment.

## 20. Operational continuation — explicit-size bulk entry (2026-09-29)

User explicitly paused revision/design reviews (“بازنگری‌ها رو بذار برای بعد”) and selected `system_completion_next_area=pms_bulk_entry`. PR-09 was asked but skipped; none of its options was accepted. Prior decisions/deferrals remain historical requirements, not authorization to implement revisioning now. This selection authorizes the bulk-entry implementation, replacing design-only scope for this capability only.

Governing sections read: §10 (individual sizes, no bands), §11.10/11.12 (bulk faithful transcription), §12 (open gaps), §17–18 (author/company gates), and the reconciliation report. Design: expand shared item fields across an explicit selection of catalogue sizes in a single class; use existing item validation/material selection/provenance and independent item/event rows. No interval inference, spreadsheet parser, overwrite/upsert, class publication or default authors. Manual custom-size entry remains through the existing single-row form; catalogue size selection is not a claim of B16.9 coverage beyond NPS48.

Two-step workflow: server-side read-only preview shows resolved size/material fields, conflicts and advisory findings; an authenticated encrypted, actor/project/class-bound preview token expires after 30 minutes. Confirmation revalidates authorization, archive state, class edit version, input/reference normalization, selected definition approval and duplicate axes under the existing fitting-review→pms-entry project locks. Token changes, expiry, stale preview or any conflict refuse the whole batch; no silent skip/replace. Max 100 explicit sizes per request. Existing per-row DB uniqueness is the concurrent-write backstop. One outer transaction includes every item and its existing policy-stamped audit event; an inner failure rolls all rows back. Preview performs no business DML.

Use the existing new-item fields with an additional multi-size selector and preview action, without requiring JavaScript. Persist only explicitly confirmed prepared fields; posted confirmation-side field changes cannot alter the token's data. Each row remains independently editable by the existing editor. Preview is transcription review, not engineering clearance, source authenticity, publication or adoption. Project-definition links retain their current explicit-selection semantics and live approval checks.

Acceptance planned: explicit nonadjacent sizes/no intermediates; preview without writes; independent saved rows/audit; rollback on late failure; NULL-qualifier duplicate conflicts and intervening inserts; stale header/reference/definition state; tampered/expired/replayed or wrong-scope tokens; revoked author/archive and own-company/project isolation; bilingual escaping and existing single-row regression. Implementation/verification evidence will be appended separately.


### 20.1 Bulk entry implemented and verified

Implemented PmsBulkEntryService, shared prepareItem validation in PmsEntryService, two POST endpoints, explicit-size checkboxes in the new-item form and escaped FA/EN preview/confirmation. No schema change. Token fingerprint includes class/notes, normalized inputs/reference selection and row findings; confirmation rechecks live approval/authorization. Batch creates distinct rows/events in one transaction. Existing axes refuse the whole batch; no overwrite or skips. Browser validation failures return to the GET editor with errors/previous input, not a POST-only preview URL.

Final serial verification: PmsBulkEntry/PmsEntry/PmsAccess/PmsEntryMigration **48 tests / 379 assertions**, zero failures/errors/skips, including **16 / 117** dedicated bulk tests (subset, not additive). Standards corpus25/25, Blade compilation and four authenticated read-only live HTTP checks pass. No visual-browser or full-product QA. Real two-connection policy revocation test reran within PmsAccessTest; no new two-connection bulk-confirmation race test. Reference packs unchanged. Detailed evidence/limits: [bulk delivery](PMS-BULK-ENTRY-2026-09-29.md).

PMS-ADR-03's explicit catalogue-size/common-specification create workflow is implemented; spreadsheet import, multi-class batching, custom-size bulk editor and bulk updates are not provided. Per-row source/provenance remains faithful; cross-row findings are visible on the class page after entry, not a complete preview engineering certification. Existing PMS-ADR-01/02/04/06/08 and document integration remain open. Revision design reviews are paused by user instruction, not silently resolved or cancelled.

## 21. Operational continuation — PMS header measurement pairs (2026-09-29)

Following user continuation after bulk delivery, implement the accepted PMS-ADR-02 measurement contract for minimum/maximum design temperature and corrosion allowance through ADR-014 §14. Store entered value/unit plus higher-precision C/mm mirrors, reuse unit preferences and keep source text. Existing values are legacy canonical-only until explicitly re-entered; no guessed backfill. Keep/enter/clear and unchanged company/project author gates apply. Pressure Class/PN is not converted. Revision design discussions, publication/AFC and deferred authorities remain paused/unimplemented; this is not approval of any unanswered design question. Implementation and tests pending at this design entry.


### 21.1 Implemented and verified header measurements

ADR-014 §14.1 records the delivered source value/unit pairs, six-decimal C/mm mirrors, explicit legacy keep/set/clear behavior, preference-aware display and database shape/boundary safeguards. Existing author/company/version/archive controls apply; no pressure-rating conversion or revision/publication authority change. PmsMeasurementService and migration235800 integrate into current header entry; bulk rows continue independently and reject stale previews after header changes.

Verified **190 selected tests / 808 assertions** across two non-overlapping serial sets (90/562 PMS/header and100/246 units/preferences), no failures/errors/skips, engine25/25. No full-product or visual-browser claim. [Report](PMS-MEASUREMENTS-2026-09-29.md). PMS-ADR-02's min/max temperature and corrosion-allowance header scope is implemented; document hierarchy, structured citations/rating and remaining entry forms stay open. Revision review remains paused.

## 22. Operational continuation — class special requirements (2026-09-29)

Under the user's continuing operational completion request, implement the existing §11.6/§12 PMS-ADR-06 special-requirements entry gap. No new authority decision is being requested or assumed. Revision reviews remain paused. Read ADR-PROCESS, §11.6/§12/§17–18, reconciliation PMS-ADR-06, ADR-013 §10 and ADR-016 §9/9.1 before this design.

Use existing nullable `piping_classes.special_requirements` JSON array, not a new schema or parallel source. Provide a multiline entry, one requirement per nonblank line; keep order, duplicates, internal and edge whitespace of nonblank lines. Normalize line separators and omit blank lines only. Do not split punctuation, enumerate acceptable requirement types, translate or infer NACE/IBR acceptance from wording. Exclude this specific nested input from HTTP trimming so service and HTTP transcription agree. Source-document/revision/text and mandatory reason remain the header's existing attribution fields, not per-requirement structured citations.

Keep / replace / clear is explicit; default keep even on a new empty class. Omitted mode preserves the old list; reject posted text without an explicit mode rather than silently discard intended input. Replace requires nonempty text, max50 nonblank entries, max1000 Unicode characters per entry and max50100 input characters; reject nested arrays, invalid UTF-8 and non-text control characters rather than truncate. Clear stores NULL; it does not assert that the project has no special requirements. Empty display means not entered, not certified absence. Existing odd-shaped legacy JSON should remain readable/unchanged under keep; show its escaped raw representation and require explicit replacement rather than auto-converting it.

Run the update after current project/company author and optimistic-version checks, in the same header/audit transaction. Reuse archive/RLS protection; do not describe it as a new raw-SQL user-authorization barrier. Read-only participants see escaped entries, with the same warning that transcription is not approval. Bulk preview class fingerprints must become stale after a special-requirements change. A citation-like string does not call `cite()`, adopt a standard, modify findings, publish a class, grant N/A or alter NDT/ITP.

Planned acceptance: creation/readback/audit, preserved text/order/duplicates, keep/omission/clear and malformed legacy preservation, field errors and limits without partial header writes, wrong-company/revoked/archive/stale refusal, FA/EN and XSS, no standards-adoption side effects, bulk preview invalidation and existing entry/measurement regression. No schema migration needed. Implementation and verified evidence will be recorded separately.


### 22.1 Implemented and verified special-requirements entry

Implemented the existing nullable JSON field through PmsEntryService's header transaction, targeted TrimStrings exception and two escaped FA/EN Blade partials. Explicit keep/replace/clear, omitted-mode preservation, text-without-mode refusal, 50/1000/50100 bounds and UTF-8/control validation follow §22. CRLF/CR/LF are supported line separators; Unicode whitespace-only lines are omitted. Odd-shaped legacy values remain unchanged under keep and render as escaped JSON. No schema migration, enumeration, automatic citation/adoption or new authority. Header source fields/reason and existing before/after audit apply; per-entry structured citations remain absent.

Final serial verification: **112 tests / 676 assertions**, no failures/errors/skips across PmsSpecialRequirements, PmsMeasurement, PmsEntry, PmsBulkEntry, PmsAccess, PmsEntryMigration and PipingClassHeader. Dedicated22/114 is included. Engine corpus25/25, explicit-file Pint and Blade compilation pass. HTTP feature tests cover bilingual author/reader escaping, precise transcription, existing author/company/archive/version boundaries and stale bulk previews. No visual-browser/full-product/backup-restore claim; no new special-field two-connection race test. Reference files unchanged. [Delivery and evidence](PMS-SPECIAL-REQUIREMENTS-2026-09-29.md).

This closes only the special-requirements form portion of PMS-ADR-06. NDT/ITP, manual valves, SP, branch/size-rule entry and structured references remain separate gaps. No change to paused revision reviews or deferred custody/evidence/adoption authorities.


## 23. Manual-valve entry — selected operational continuation (2026-09-29)

User explicitly selected `pms_next_entry_area=manual_valves`. Governing text read: §11.9 (existing side table and faithful transcription), §12, §14/15, §17–18, ADR-013 §10 and ADR-016 §9/9.1, plus reconciliation PMS-ADR-06. Revision/custody/adoption discussions stay paused.

Design before implementation: reuse piping_class_valve_specs, one row per body item, and the existing body material/size/rating/end fields. Add explicit keep/replace/clear (default keep; omission preserves) within existing item save/version/author/company/archive transaction, not an independent save/version. Require explicit mode when valve fields are sent. Before/after item audit includes valve_spec; clear deletes only the current side record with prior contents retained in the event. Keep ignores supplied valve fields. Reject conversion of an item with a retained valve layer to non-VALVE; explicit clear permits the conversion. Replace is VALVE-only, must contain at least one meaningful field, requires both trim-standard/code or neither, and only existing four manual operators (or no operator). Unknown but well-formed source standards/trim pairs remain transcribable; do not force guessed library matches or require an operator on CHECK.

Use existing VALVE_TRIM library for explicit keyed selection, separate from body material. Store selected trim record/reference provenance without adopting any standard; manual fields remain available for unresolved, obsolete or inconsistent source data. Construction standard remains distinct from dimensional standard. Existing technical per-row checks may be shown as nonblocking review findings, never clearance; cross-size operatorConsistency is NOT newly wired while its DR-04 categorical inference remains unresolved in code. No new automatic rating conversion, published revision/AFC, NDT or actuator fields.

Bulk preview/confirm must carry the same normalized valve layer and trim provenance, show it before confirmation, include it in the reference fingerprint and create independent side records in the same batch transaction. No silent field discard. References remain unchanged.

Static inspection found valve-spec RLS lacks archive guards and uses unsafe empty-GUC UUID casts. Amend policies by forward migration to null-safe project/company scope, participant membership and the existing restrictive archive write/delete guards, retaining archived reads. This is an implementation gap against ADR-013, not a new authority policy. Human author nomination remains enforced by service/event path, not claimed as universal raw-SQL authorization. No existing valve data rewrite.

Acceptance planned: create/read/edit/keep/clear and full audit; trim selection/provenance vs manual unknown pair; missing/empty/malformed/mismatched shapes and powered operators; atomic rollback, stale/wrong-company/revoked/archive protection; raw SQL archive DML and reopen/empty context; escaped FA/EN author/reader and old-input; bulk preview/confirm including all fields and references; existing PMS and ManualValve regression. This entry is design, not execution evidence.


### 23.1 Implemented and verified manual-valve entry

PmsValveEntryService prepares bounded valve fields and explicit library trim selection, then applies them inside PmsEntryService's existing item transaction/version boundary. PipingClassItem has the existing side-table relation; before/after events now include valve_spec. Keep/omission retains previous layer/provenance, replace stores fields and reference versions, clear removes current layer but not history. The dedicated HTTP trim exception preserves valve description edge whitespace. Source unknown/withdrawn pairs remain recordable; only structural violations block.

FA/EN editor/summary reuse body fields and distinguish construction from dimensional standard. Trim and construction suggestions have optional client-side qualifier filtering; manual source entry remains available, and no JavaScript is required to save. Existing per-row ManualValves findings are displayed, not used for write approval. No operatorConsistency hook, threshold or new consumption gate; underlying DR-03/04 redesign/legacy classification remains open. Bulk preview includes valve details and prepared reference basis; confirm creates independent side records with atomic batch rollback.

Migration235900 fixes missing valve-spec archive SQL guards and empty-context casts, retains reads and adds project participation restriction using existing patterns (ADR-013 §15). No row rewrite or new columns; forward-only security change. This enforces scope/archive, not raw-SQL human-author nomination.

Final serial9-class regression: **225 tests / 1085 assertions**, no failures/errors/skips. Includes dedicated24/158, existing ManualValve89/251 and PMS112/676. Engine corpus25/25, explicit-file Pint and Blade compilation pass. Dedicated tests cover HTTP text/library provenance, unknown pairs, edit/keep/clear history, invalid forms, author/company/stale gates, SQL archive DML/reopen/read, bilingual escaping and late batch rollback. No visual-browser/JS execution, full-product or restore QA claim; no new two-connection valve concurrency test.32 engineering reference files unchanged. [Delivery and remaining gaps](PMS-MANUAL-VALVES-2026-09-29.md).

This closes the operational manual-valve entry portion of PMS-ADR-06, not its NDT/ITP/SP/branch/size-rule portions, nor structured rating/adoption or PMS revision/AFC integration.


## 24. NDT continuation — explicitly outside material inspection (2026-09-29)

User selected `pms_next_after_manual_valves=ndt_itp`. After reading §11.8/§12 and ADR-015's incoming-material boundary, the assistant offered incoming-ITP integration versus extending fabrication/installation. User selected neither: explicit correction **«الزامات NDT را از حوزه بازرسی متریال خارج کن»**. This answer supersedes the proposed incoming-material scope; it is not approval of either offered option.

Accepted boundary: the PMS NDT-requirements capability is outside material inspection. Do not implement it as MaterialInspection records, PO/lot/receipt checks, incoming-material ITP requirements or warehouse-issue conditions. §11.8 remains: PMS states the requirements, an appropriate ITP cites them; shared field names do not justify attaching the requirements to the existing incoming-material workflow. No automatic inference of NDT percentages/acceptance criteria from fluid service or standards.

This correction does not authorize deleting existing inspection/ITP/PMI records or historical evidence, removing unrelated inspection gates, or reclassifying all existing methods by their name. The exact non-material execution consumer and its lifecycle still require scoped design; do not infer blanket authorization for all fabrication/installation workflows from exclusion of material inspection. Revision/custody/adoption decisions remain paused/deferred.

Status: user-confirmed scope correction, documentation recorded. No NDT schema, UI, link, gate or migration implemented in this increment; no runtime tests claimed. Before implementation, design PMS-owned requirements and the non-material ITP boundary without a second editable source or silent changes to historical ITPs. See ADR-015 §8 and reconciliation §20.


## 25. Accepted staged NDT design and first implementation scope (2026-09-29)

User asked for a recommendation, then accepted it with **«خوبه ادامه بده»**: separate PMS requirement definition, ITP planning/reference and independent NDT execution/results; retain PMS as the source, avoid separately editable ITP copies, do not silently change the basis of earlier tests. Immediate implementation scope is the proposed first step: structured NDT requirement entry in PMS. §24's exclusion of material inspection stands. This acceptance is not reopening revision review or granting new approval authority.

Read governing §11.8/§12/§17–18/§24, ADR-015 §8, ADR-013 §10, ADR-016 §9/9.1 and reconciliation before design. Design: new child table `piping_class_ndt_requirements` with stable UUID, parent class/project/company and bounded source-text columns: applicability, method, extent/frequency, sampling_basis, acceptance, reference, conditions, source_ref. Separate table rather than embedding an anonymous list: stable identity permits a later explicit consumer link without creating a second source. No execution/ITP foreign key, result, inspection lot, warehouse gate or approval state now.

Create/update only, no delete endpoint. Require applicability, method, source locator and change reason; allow remaining source details to be missing without fabricating them. Display missing extent/sampling basis/acceptance/reference prominently, including when the source only says “RT 10%”. Conditions blank means not recorded, not certified absence. No percentage arithmetic, method enum restriction, standard adoption or engineering clearance. Preserve source wording/edge whitespace with narrowly scoped HTTP trim exceptions. Enforce Unicode, non-text-control and field limits. Max100 entries per class in the application.

Use existing project author/company/archive checks and project lock, then parent-class optimistic entry_version; stamp company/project server-side. Full class before/after events include ordered NDT requirements and current author policy, incrementing the class counter so old bulk previews become stale. This is edit history, not a released document revision or an execution-basis pin. Header/note updates preserve the requirements. Unknown requirement UUIDs must not create or overwrite another class's record.

SQL: parent/project/company consistency in write policy, project-participant read scope, own-company write scope, null-safe tenant settings and restrictive archive write/delete protection with archived reads retained. Add no initial rows/backfill or default author grants. Human nomination remains service/event-owned, not a claim that arbitrary SQL authenticates a human. Forward-only migration avoids dropping recorded source requirements.

Acceptance planned: source capture/readback, explicit missing-data display, update and audit, malformed/long/invalid text and limits, scope/author/company/version/archive protection, direct SQL archive/parent mismatch/empty context, atomic rollback, no material/ITP/adoption side effects, bilingual escaping/old-input recovery, stale bulk preview and related PMS regression. ITP citation/execution/results and their historical-basis enforcement remain subsequent work.


### 25.1 First step implemented and verified

Implemented PipingClassNdtRequirement and migration235950; a PMS-owned child table with stable UUIDs, bounded text, scoped/parent-consistent RLS and archive protection. PmsEntryService.saveNdt handles create/update in the existing project-locked, company/author/version-checked transaction. No delete endpoint. Class before/after audit includes ordered ndt_requirements; header/note writes retain them. Class edit counter changes invalidate existing bulk previews.

FA/EN source-entry/read UI includes applicability, method, extent, sampling basis, acceptance, reference, conditions and source locator, with explicit missing-detail notices. No method/percentage/acceptance inference, no automatic adoption/citation, no approval/result field. Target-scoped old-input handling and narrow text-trimming exceptions retain source text. The schema has no ITP/inspection/lot/execution link; subsequent execution-basis and ITP integration are not built.

Final9-class serial run: **157 tests / 959 assertions**,0 failures/errors/skips, including dedicated21/125. Existing PMS136/834 supplies related regression, not a whole-product run. Dedicated coverage includes Unicode and bounds, source preservation/incompleteness, stable ID/update/history, header/note preservation, no external inspection/ITP/adoption writes, HTTP reader/author escaping, company/revocation/stale/foreign ID, SQL parent/archive/reopen/empty-context safety, late audit rollback and cap100/edit-at-cap. Engine corpus25/25, explicit-file Pint and Blade compilation pass; live login HTTP200.32 reference files unchanged. No visual-browser/full-product/restore QA or new two-connection NDT race claim. [Delivery](PMS-NDT-REQUIREMENTS-2026-09-29.md).

PMS-ADR-06's NDT source-entry portion is implemented; non-material ITP citation and independent execution/results remain next steps under the accepted separation. Revision/custody/adoption authority remains deferred and existing incoming-material gates/history are unchanged.
