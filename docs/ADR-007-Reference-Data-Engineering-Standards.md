# ADR-EPCC-007 — Engineering reference data (pipe sizes, schedules, ratings)

**Status:** **ACCEPTED AND BUILT** (phase 0, 2026-09-15) — see §11 for the as-built record
**Date:** 2026-09-14
**Depends on:** ADR-006 (piping model) — PROPOSED (this ADR does not wait on it)
**Relates to:** the standards-pack engine (`packages/standards-engine`, `standards-packs/*.pack.json`)

**Trigger (user):** «بعضی داده‌ها مثل سایز، استاندارد و… برای همه پروژه‌ها ثابت هستند و باید در جایی
توی سیستم که به دیتابیس هم فشار وارد نکند، ذخیره بشن تا در قسمت‌های مختلف سیستم از اونها استفاده
بشه. مثلاً سایز لوله‌ها باید از ۱/۲ اینچ همراه با DN متناظرش و قطر خارجی به اینچ و میلیمتر و
اسکجوئل تو سیستم ثبت شده باشه.»

This is a **different kind of data** from everything built so far, and it deserves its own
decision rather than being smuggled into ADR-006.

---

## 1. Why this is not just another table

Every table in this system so far answers *"what is true on this project?"* — documents,
punch items, requisitions, subsystems. They are tenant data: per project, per company, under
RLS, edited by users, audited.

ASME B36.10M is not that. `NPS 6" = DN 150 = OD 168.3 mm` is true in Tehran, in Basra and in
Rotterdam, in 2026 and in 1996. It has four properties that invert every assumption the tenant
tables are built on:

| Property | Tenant data | Reference data |
|---|---|---|
| Scope | one project | **all projects, all companies** |
| Who writes it | users | **nobody** — it ships with the release |
| RLS | mandatory | **meaningless** (and actively harmful — see §4) |
| Change rate | continuous | **once per standard revision, years apart** |
| Audit | who changed what | irrelevant; the *version* is what matters |

Putting it in a normal table would mean either duplicating ~500 rows per project (absurd) or
creating the system's first cross-tenant table and then writing an RLS exception for it — a
permanent hole in an otherwise uniform policy. The user's instinct — «به دیتابیس فشار وارد
نکند» — is correct for a stronger reason than performance.

---

## 2. The data really is fixed — but the sources disagree, and that is the trap

I cross-checked seven published B36.10M tables. They agree on most rows and **disagree on
exactly the rows where a naive implementation breaks**:

| NPS | OD mm | SCH 40 | STD | equal? | SCH 80 | XS | equal? |
|---|---|---|---|---|---|---|---|
| 1/2" | 21.3 | 2.77 | 2.77 | yes | 3.73 | 3.73 | yes |
| 6" | 168.3 | 7.11 | 7.11 | yes | 10.97 | 10.97 | yes |
| 8" | 219.1 | 8.18 | 8.18 | yes | 12.70 | 12.70 | yes |
| 10" | 273.0 | 9.27 | 9.27 | yes | 15.09 | **12.70** | **no** |
| 12" | 323.8 | **10.31** | **9.53** | **no** | 17.48 | **12.70** | **no** |
| 24" | 610.0 | **17.48** | **9.53** | **no** | 30.96 | **12.70** | **no** |

**STD diverges from SCH 40 at 12" and above. XS diverges from SCH 80 at 10" and above.**
Above those sizes STD is fixed at 9.53 mm (3/8") and XS at 12.70 mm (1/2") regardless of
diameter.

Two of the sources I found silently print STD values in a column labelled "SCH 40" — which is
why the same question returns 9.53 and 10.31 from different pages. If we had taken one source
and typed it in, every thickness compatibility check on large-bore pipe would have been wrong,
and it would have looked authoritative.

**Consequences for the design:**

1. `STD`, `XS`, `XXS` are **not aliases** of `40`/`80`/`160`. They are their own schedule
   identifiers, and the dataset must carry both.
2. The dataset needs a **provenance field** (`source: "ASME B36.10M-2022"`) so a disputed number
   can be traced to a named edition rather than to "a website".
3. It needs a **test that asserts the divergence**, not just spot values — a fixture that only
   checks 2" and 6" would pass on a completely wrong large-bore table.
4. Not every schedule exists at every size. `1/2" SCH 10` exists; `1/2" SCH 30` does not.
   **Absence must be representable**, and asking for a non-existent combination must be an
   error, not a silent zero.

---

## 3. Options

**Option A — database tables (`pipe_sizes`, `pipe_schedules`), seeded.**
*For:* joinable in SQL; editable through the admin panel.
*Against:* it is the first cross-tenant table, so it needs an RLS exemption; it must be seeded
into every project database including air-gapped on-prem installs (AG-01…AG-06); a query on
every size dropdown; and "editable" is a *defect*, not a feature — a site engineer must not be
able to redefine 6" pipe.

**Option B — PHP config array (`config/pipe_data.php`).**
*For:* zero queries; opcache caches it.
*Against:* `opcache.enable_cli=0` here, so CLI/queue workers reparse it each run; it is invisible
to the standards-pack machinery; and it is PHP code, so it cannot be shipped to or validated by
anything that is not PHP. Also unversioned — "which edition of B36.10M is this?" has no answer.

**Option C — a JSON reference pack, loaded through a cached repository.** ⇐ **recommended**
*For:* it reuses **machinery that already exists and is already tested**. `standards-packs/`
already ships versioned JSON with a schema and `validate_packs.py`; `config/epcc.php` already
resolves `pack_dir`. A reference pack is the same idea with a different payload: data that
varies by *standards family* rather than by project. It is validated at build time, diffable in
git, shippable to an air-gapped site as a file, and **read-only by construction**.
*Against:* one new repository class and a cache warm step.

**Rejected: fetching from an online API.** Non-negotiable — MKT-EPCC-001 requires the system to
run fully air-gapped.

---

## 4. Why not RLS, explicitly

It is worth stating because every other table in this codebase has it, and a reviewer will ask.

RLS on reference data would be **actively harmful**: the policy would have to be "everyone can
read", which is a policy that can only fail open. The genuine protection here is different —
**nobody can write, including the owner company**. A file on disk, loaded read-only, provides
that guarantee absolutely; a table with a permissive policy only pretends to.

This also sidesteps the trap recorded in ADR-004: a migration cannot read an RLS-forced table
without a tenant GUC, which is exactly the situation a seeder for reference data would hit.

---

## 5. Proposed shape

```
standards-packs/
  reference/
    pipe-sizes.ref.json        # NPS ↔ DN ↔ OD (in + mm), per size
    pipe-schedules.ref.json    # (size × schedule) → wall thickness, ID, kg/m
    end-types.ref.json         # PCF two-char vocabulary: BW SW FL SC PL PF CP GL…
    component-templates.ref.json  # port COUNT and ROLES per component class (ADR-009 §2)
    flange-ratings.ref.json    # 150/300/600/900/1500/2500 # + facing RF/FF/RTJ
    schema/                    # JSON-schema for each, checked by validate_packs.py
```

```jsonc
// pipe-sizes.ref.json
{
  "refId": "pipe-sizes",
  "version": "1.0.0",
  "source": "ASME B36.10M-2022 / B36.19M-2018",
  "note": "OD is exact by standard, not derived from NPS.",
  "sizes": [
    {"nps": "1/2",   "npsDecimal": 0.5,  "dn": 15,  "odIn": 0.840,  "odMm": 21.3},
    {"nps": "2 1/2", "npsDecimal": 2.5,  "dn": 65,  "odIn": 2.875,  "odMm": 73.0},
    {"nps": "3/4",  "npsDecimal": 0.75, "dn": 20,  "odIn": 1.050,  "odMm": 26.7},
    {"nps": "1",    "npsDecimal": 1.0,  "dn": 25,  "odIn": 1.315,  "odMm": 33.4},
    {"nps": "12",   "npsDecimal": 12.0, "dn": 300, "odIn": 12.750, "odMm": 323.8}
    // … 1/2" to 48"
  ]
}
```

```jsonc
// pipe-schedules.ref.json  — sparse ON PURPOSE: absence means "does not exist"
{
  "refId": "pipe-schedules",
  "version": "1.0.0",
  "source": "ASME B36.10M-2022",
  "schedules": ["5","10","20","30","40","60","80","100","120","140","160",
                "STD","XS","XXS","5S","10S","40S","80S"],
  "note": "STD is NOT an alias of 40 above NPS 10; XS is NOT an alias of 80 above NPS 8.",
  "walls": {
    "1/2": {"10": 2.11, "40": 2.77, "STD": 2.77, "80": 3.73, "XS": 3.73, "160": 4.78, "XXS": 7.47},
    "12":  {"10": 4.57, "40": 10.31, "STD": 9.53, "80": 17.48, "XS": 12.70, "160": 33.32, "XXS": 25.40}
  }
}
```

**Access layer** — one class, mirroring how the standards engine is already consumed:

```php
final class PipeReference          // app/Support/Reference/PipeReference.php
{
    public function sizes(): array;                       // all, ordered by DN
    public function size(string $nps): PipeSize;          // throws UnknownSizeException
    public function byDn(int $dn): PipeSize;              // the Iranian/EN way in
    public function wall(string $nps, string $schedule): float;  // throws if the combo is absent
    public function schedulesFor(string $nps): array;     // only those that exist
    public function insideDiameter(string $nps, string $sched): float;
}
```

Loaded once, memoised in the process, and warmed into the application cache. **No repository
method returns null for a missing combination** — `1/2" SCH 30` throws, because a silent null
becomes a silent zero becomes a wall-thickness check that passes everything.

---

## 6. What consumes it

| Consumer | Use |
|---|---|
| `piping_lines.nominal_size` | the `{size}` token of the line number — a **picklist from the reference**, not free text |
| `component_ports` | `nominal_size` + `schedule` → `wall_thickness` **derived, not typed** |
| `PortCompatibility` (ADR-006 §5.4) | "6" SCH 40 to 6" SCH 80" is a WARNING; the thicknesses come from here |
| MTO / requisition items | `item_code` validation and weight roll-up (kg/m is in the dataset) |
| PCF import | `END-POINT … bore ENDTYPE`: bore in `INCH-SIXTEENTH` or `MM-HUNDREDTHS` must be **resolved to a known NPS**, and an unmatched bore is an import warning rather than a new size |
| Line-number **parsing** (ADR-006 §4) | `6-P-1001-…` → is `6` a legal size? Parsing needs the vocabulary |

That last one is the strongest argument for doing this now rather than later: ADR-006's
numbering engine must *parse* line numbers, and it cannot validate a size token without the
size list.

---

## 7. Air-gap and versioning

- Ships **inside the release** as files; nothing to fetch. Satisfies AG-01…AG-06 by construction.
- `version` + `source` are recorded per pack. When a project's certificates or MTOs were
  computed, the pack version can be stated — the same reasoning that makes certificates snapshot
  their identifiers rather than join live (ADR-004).
- A project may eventually need to **pin** a reference version (a 2012-edition project should not
  silently re-baseline when we ship a 2022 table). Proposed: `projects.reference_pack_version`,
  nullable, defaulting to latest. **Not built in phase 1** — recorded so the column is not a
  surprise later.

---

## 8. Scope for phase 1

Deliberately narrow, because the user's example named exactly this:

1. `pipe-sizes.ref.json` — **NPS 1/2" through 48"**, with DN, OD in inches and OD in mm.
2. `pipe-schedules.ref.json` — walls for the common schedules, **with STD/XS/XXS as first-class
   entries, not aliases**.
3. `end-types.ref.json` — the PCF two-character vocabulary (BW/SW/FL/SC/PL/PF/CP/GL…), which
   ADR-006's compatibility engine needs anyway.
4. `PipeReference` + JSON-schema validation wired into `validate_packs.py`.
5. Tests, including **the divergence assertions** from §2 — `wall('12','40') !== wall('12','STD')`
   and `wall('10','80') !== wall('10','XS')`. A test that only checks 2" would pass on a wrong
   table.

Flange ratings, gasket and bolt tables, and material grades are **not** in phase 1. They are the
same pattern and can be added as further packs when the bolt-up register is specified.

---

## 9. Editions — decided

**ASME is the governing dimensional standard** (user, 2026-09-14). So:
**ASME B36.10M** for carbon/alloy steel and **ASME B36.19M** for stainless, recorded in each
pack's `source` field. This is also what Plant 3D, PCF/ISOGEN and the Iranian IPS specs
reference, so no translation layer is needed.

A project that later needs EN 10220 or a different edition gets **another pack**, selected by
`projects.reference_pack_version` (§7) — not an edit to this one. Reference data is versioned,
never mutated.

---

## 9a. The four NPS columns are not redundant

`nps` (string), `npsDecimal`, `dn` and `odMm` all ship, at the user's explicit instruction, and
each has a job that the others cannot do:

- `nps` — `"2 1/2"`, what the engineer reads and what appears in a line number.
- `npsDecimal` — **`2.5`, the only column that can be summed.** This is what makes **dia-inch**
  of accepted welds computable, which the user named as the purpose. Tested: summing the string
  column raises `ValueError`, and sorting it puts `12` before `2 1/2`.
- `dn` — the integer key for ADR-008's `int4range` size bands.
- `odMm` — physical dimension, from ASME.

A future "simplification" to fewer columns would silently break dia-inch reporting, so this note
exists to stop it.

---

## 10. What consumes this beyond ADR-006

`docs/ADR-008-PMS-Piping-Class-Rules.md` depends on this pack for the **NPS → DN conversion**
that makes size-band rules possible: the PMS says "4 inch", the rule engine stores DN100, and
only this reference can connect the two. ADR-008 §2 explains why DN integers are the only sane
key for range logic — NPS is a label (`1 1/4`, `1.25`, `1-1/4`, `۱¼`), not a number.


---

## 11. As built (2026-09-15, phase 0)

Everything in §5 and §8 is implemented. Nothing in this ADR was deferred except the
flange/gasket/bolt packs, which §8 already placed outside phase 1.

### 11.1 Files

| Path | Contents |
|---|---|
| `standards-packs/reference/pipe-sizes.ref.json` | **36 sizes**, NPS 1/8″ – 48″, four columns each |
| `standards-packs/reference/pipe-schedules.ref.json` | **355 (size, schedule) pairs** over an 18-rung ladder |
| `standards-packs/reference/end-types.ref.json` | 13 PCF end types, 3 of them numbered |
| `standards-packs/reference/component-templates.ref.json` | 20 templates, 41 port definitions |
| `standards-packs/reference/schema/*.schema.json` | one JSON-schema per pack |
| `app/app/Support/Reference/PipeReference.php` | the access layer |
| `app/app/Support/Reference/PipeSize.php` | readonly value object |
| `app/app/Support/Reference/Exceptions/` | `UnknownSizeException`, `UnknownScheduleException` |
| `app/tests/Feature/PipeReferenceTest.php` | **49 tests / 775 assertions** |

Wired as a singleton in `EpccServiceProvider`; directory configured at
`config('epcc.reference_dir')`, default `../standards-packs/reference`.

### 11.2 The divergence, verified in the shipped data

§2 predicted it from the published tables; the data now asserts it in three places
(generator, CI validator, PHPUnit):

| NPS | SCH 40 | STD | SCH 80 | XS |
|---|---|---|---|---|
| 6″ | 7.11 | 7.11 | 10.97 | 10.97 |
| 10″ | 9.27 | 9.27 | **15.09** | **12.70** |
| 12″ | **10.31** | **9.53** | 17.48 | **12.70** |
| 24″ | **17.48** | **9.53** | 30.96 | **12.70** |

### 11.3 Two defects the tests caught, and one the tests missed

**Caught by the suite on first run — PHP integer key coercion.** `array_keys()` on the
decoded wall table returns `40` as an **int**, not `"40"`, because PHP coerces numeric
string keys. `schedulesFor()` therefore handed back a mixed `int|string` list: a strict
`in_array()` in a picklist would silently fail, and passing a key straight back into
`wall()` was an outright `TypeError`. Fixed with an explicit `array_map(strval(...), …)`
and a comment, because the next person to "simplify" that line will reintroduce it.

**Missed by the suite, found by mutation testing — the reachable alias fallback.** The
suite asserted that STD ≠ SCH 40 at 12″ and above, so a mutant that made `wall()` fall
back from a missing STD to SCH 40 still passed: at every size where STD is missing… it
never is. The reachable direction is the other one. **ASME defines STD at 22″ and 26″
but defines no SCH 40 there at all** (likewise XS but no SCH 80 from 26″ up). A
"helpful" fallback would return 9.53 mm for a schedule the standard does not define at
that size — a plausible number, wrong, and undetectable downstream. Now covered by
`test_it_never_falls_back_between_std_and_a_numbered_schedule` and its XS twin.

The general lesson, consistent with the rest of this codebase: **an assertion about
data can only test the direction the data exercises.** Only mutation testing found the
gap.

**Also strengthened:** the singleton test asserted instance identity, which does not
prove memoisation — a singleton that re-decodes 500 rows per lookup is still a
singleton. Replaced with an observable proof: warm the cache, delete the files, assert
the lookup still works, and assert a *fresh* instance against the same empty directory
throws (otherwise the test would pass on an eager-loading implementation).

### 11.4 Validation, three layers

1. **Generation time** — the script that emitted the schedule table asserted size-table
   agreement, ladder membership, positive bore, the divergence and per-size monotonicity
   before writing.
2. **CI** — `validate_packs.py` grew `check_reference_schema()` and
   `check_reference_integrity()`: NPS labels parsed with `Fraction` and compared against
   `npsDecimal`, `odIn × 25.4` reconciled against `odMm` (0.6 mm tolerance), ascending
   DN/decimal/OD, no orphan sizes, monotonic ladder, the STD/XS pins, `numbered ⇒
   weldable`, template port sequences, roles and end types resolvable, `TEE` present with
   3 ports and `qualifierDerived`, `EQUAL_TEE`/`REDUCING_TEE` **forbidden**, `NOZZLE`
   carrying `ownerKind`. The existing bilingual check was extended over the reference
   packs for free. **15 data mutations were run against it; 15 were caught.**
3. **PHPUnit** — 49 tests, including `test_it_touches_no_database()`, which fails if a
   future refactor moves reference data into a table and quietly makes it the first
   cross-tenant one.

### 11.5 Decisions taken while building, not in the original analysis

- **`resolveLabel()` returns null, everything else throws.** Line-number parsing and PCF
  import legitimately need to ask *"is this token a size at all?"*, where "no" is a normal
  answer rather than an error. Every other accessor throws, per §5.
- **Persian digits are folded on input.** `۲ ۱/۲` resolves to `2 1/2`. The UI is bilingual
  and a Farsi keyboard produces these; without folding, a Persian-locale user typing a
  size gets `UnknownSizeException` for a perfectly valid pipe.
- **`resolveBore()` takes the unit as a required argument** and throws on an unrecognised
  one, rather than defaulting to inches. A PCF bore of `96` is 6″ in `INCH-SIXTEENTH` and
  not a pipe size at all in `INCH` — asserted in
  `test_misreading_the_bore_unit_would_corrupt_the_size`.
- **`NOZZLE` ships in the template pack** even though a nozzle is not a line component.
  The port-creation machinery is identical (one template, one port); only `ownerKind`
  differs. This is what lets ADR-010's pipe-to-nozzle joint reuse the `joints` table
  unchanged.
- **Three schedules were added to the §8 ladder** (`5S`, `10S`, `40S`, `80S` were already
  listed; the stainless rows are populated from B36.19M where they differ from B36.10M).

### 11.6 Not built, deliberately

`flange-ratings.ref.json` (150–2500 # with RF/FF/RTJ facings) is still the obvious next
pack, but nothing consumes it until ADR-010's nozzle rating check exists at phase 3b.
`projects.reference_pack_version` (§7) remains a recorded future column, not a migration.
