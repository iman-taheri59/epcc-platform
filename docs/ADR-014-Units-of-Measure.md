# ADR-EPCC-014 — User-selectable units of measure

**Status:** **ACCEPTED AND BUILT** — engine, storage, preferences and UI are in place (see §9).
**Date:** 2026-09-15 (built out 2026-09-17; menu narrowed 2026-09-17 — see §3.1)
**Depends on:** ADR-007 (reference packs — units ship as one), ADR-006 (piping lines carry the first values)
**Relates to:** ADR-001 (RLS — preferences are per user and per project, both tenant-scoped)

**Trigger (user):** «قابلیت تغییر واحدها مثل فشار، دما و غیره رو ایجاد کن»
— *add the ability to change units such as pressure, temperature and so on.*

---

## 1. What the request actually is

On the surface: a dropdown to switch between psi and barg. Underneath, three different problems
that a single dropdown would conflate:

1. **Input.** An engineer types 285 and means psi, because that is what the datasheet says.
2. **Storage.** The database must be able to answer "every line above 10 barg" without reading
   every row and doing arithmetic per row.
3. **Display.** An Iranian owner reads barg; an international contractor on the same project reads
   psi; both are looking at the same line.

These pull in opposite directions. Optimising for storage says "convert everything to one unit and
throw the rest away". Optimising for fidelity says "store exactly what was typed". The decision
below takes both, because in this domain each one alone is wrong in a way that costs money.

---

## 2. Decision

**Store both the entered value with its unit, AND a canonical mirror.**

| Column | Holds | Authority |
|---|---|---|
| `design_pressure` | `285` | **source of truth** |
| `design_pressure_unit` | `psi` | **source of truth** |
| `design_pressure_kpa` | `1965.005828` | derived, for comparison only |

The entered pair is what appears on the drawing and what the engineer will defend in a meeting. The
canonical column exists so that a `WHERE` clause and an index can do their job.

### 2.1 Why not canonical only

Because the round trip is lossy and the loss is not theoretical. `decimal(12,4)` storing
285 psi → 1965.0058 kPa → back to psi gives **285.0006**. A rating lookup keyed on "285" or "740"
(the 150# and 300# table values) misses. The engineer's number must survive verbatim.

### 2.2 Why not entered-only

Because "show me every line above 10 barg" then becomes a full scan with a unit-dependent
expression in the predicate, on a table that will hold hundreds of thousands of rows across
projects, under RLS — which ADR-012 already measured as the difference between 0.12 ms and 1690 ms.

### 2.3 Why not a PostgreSQL generated column

Tempting, and wrong. Generated columns must be `IMMUTABLE`, which would freeze today's conversion
factors into the schema. A correction to the units reference pack would then become a migration
plus a table rewrite. **The application owns the conversion; the database owns the constraint.**

---

## 3. Canonical units

| Quantity | Canonical | Why |
|---|---|---|
| PRESSURE | `kPa` | SI, and avoids the bar/barg/bara ambiguity as a storage unit |
| TEMPERATURE | `C` | the working unit of every process datasheet in scope |
| LENGTH | `mm` | matches the pipe reference pack (ADR-007) |
| MASS | `kg` | — |
| FLOW | `m3/h` | — |
| WEIGHT_PER_LENGTH | `kg/m` | feeds future weight roll-ups |

Factors live in `standards-packs/reference/units.ref.json`, validated by `validate_packs.py`.
Verified anchors: psi `6.89475729`, kg/cm² `98.0665`, in `25.4`, ft `304.8`, lb `0.45359237`.
**285 psi = 1965.0058277 kPa.**

### 3.1 The menu is narrower than the engine — user decision, 2026-09-17

The user narrowed what may be **chosen**:

| Quantity | Offered | Still converts, never offered |
|---|---|---|
| PRESSURE | `bar`, `psi` | kPa, MPa, barg, bara, psia, kg/cm², mmH2O, mmHg |
| TEMPERATURE | `C`, `F` | K |

**Retired by a flag (`"selectable": false`), not by deletion.** Deleting them from the pack would
have been the smaller edit and the wrong one: every value already stored in kPa, and every future
import arriving in kg/cm², would become a number nobody can read. The engine must keep converting
units it no longer offers, permanently.

A refused choice therefore says *"kPa is not one of the units this project uses — choose one of:
bar, psi"*, not *"unknown unit"*. The latter would be a lie about a unit the engine converts fine.

**This forced a distinction that did not exist before: canonical ≠ default display.**
Pressure is *stored* in kPa and *read* in bar. Before this change the fallback for an unconfigured
project was the canonical unit; now that kPa is unselectable, that fallback would have shown every
such project a unit missing from its own dropdown. Hence `default_display` in the pack, and
`UnitRegistry::defaultDisplayUnit()` distinct from `canonicalUnit()`.

Kelvin was kept in the engine at the user's request, against a future cryogenic (LNG) scope.

The agreed menu is asserted in `validate_packs.py`, so widening it is a deliberate edit to that
file rather than a quiet drift in the pack.

---

## 4. Temperature is affine, and a difference is not a temperature

`canonical = value × factor + offset`.

For every quantity but temperature the offset is zero and the formula collapses to a plain
multiply. For Fahrenheit it is factor `0.5555…`, offset `−17.7777…`; for Kelvin factor `1.0`,
offset `−273.15`.

A multiply-only converter is wrong by 32 degrees at every single point — it maps 0 °C to 0 °F. This
was caught by a test, not by inspection.

**A temperature *difference* uses the factor alone.** A 50 °C rise is a 90 °F rise, not 122 °F.
`UnitRegistry::convertDifference()` exists for this and is deliberately a separate method.
Nothing calls it yet; it is there so that the first delta-temperature field finds the right tool
instead of reaching for the point converter.

---

## 5. Gauge and absolute are never interconverted

`barg` and `bara` differ by the local atmospheric pressure — a **site condition**, not a constant.
Sea level differs from a plateau site by roughly 15 %, and Iranian plant sites span both.

Each unit therefore carries a `datum` of `GAUGE`, `ABSOLUTE` or `NONE`, and the converter **refuses**
a conversion across datums rather than silently applying 101.325 kPa. The refusal message names
atmospheric pressure explicitly, so the reader knows it is a missing input and not a missing feature.

---

## 6. Absolute zero is enforced on the converted value

A design temperature of −500 is a typo. But −500 is an unremarkable number until you know the unit,
so the floor of **−273.15 °C** is checked on the *canonical* value: −500 °F converts to −295.6 °C
and is caught, while −400 °F (−240 °C) is cold but legal. A 1e-9 tolerance lets exactly −273.15
through, because absolute zero itself is a legitimate limit to write down.

Enforced twice on purpose: in `UnitRegistry` with a readable message, and as a `CHECK` constraint,
so a future import path that bypasses the service cannot write physically impossible data.

---

## 7. Preference resolution

```
user override  →  project default  →  pack canonical
```

- `users.unit_preferences` — jsonb, **nullable**.
- `project_piping_config.unit_preferences` — jsonb, default `{}`.

**Null and `{}` mean different things.** Null on a user means "inherit whatever the project says
today"; `{}` (or a key) means "I have chosen". Collapsing them would freeze a user's display at
whatever the project default happened to be the first time they opened the screen.

The third layer is not a third opinion — it is the floor. A project that has never configured units
still has to render something.

**Who may set what:** project defaults are employer-only and blocked on an archived project, because
that is project data. A personal display preference is neither gated: it changes no stored value,
and blocking a contractor from reading a closed-out project in their own units would protect nothing.

---

## 8. What a wrong unit must not do

A unit that does not exist, a unit belonging to the wrong quantity (psi for TEMPERATURE would render
every temperature as a pressure), or a temperature below absolute zero — all three surface as
**field-level validation errors**, on the field that caused them. None of them is a 500.

A value with **no** unit gets **no** canonical. Guessing the unit is precisely how a psi number ends
up in a barg column.

---

## 9. What is built

| Piece | Path |
|---|---|
| Quantity keys | `app/Support/Units/Quantity.php` |
| Conversion engine | `app/Support/Units/UnitRegistry.php` |
| Value object | `app/Support/Units/Measurement.php` |
| Reference pack | `standards-packs/reference/units.ref.json` |
| Pack schema | `standards-packs/reference/schema/units.schema.json` |
| Pack CI checks | `validate_packs.py` → `check_units()` |
| Preference resolution | `app/Services/UnitPreferenceService.php` |
| Canonical on write | `PipingLineService::canonicalValues()` + `updateDesign()` |
| Migration | `2026_09_15_160000_add_unit_preferences_and_canonical_values.php` |
| UI | `resources/views/piping/index.blade.php` (columns, inputs, selector) |
| Tests | `UnitRegistryTest` (63), `UnitPreferenceTest` (37) |

**Mutation testing: 43 mutants run against the units layer, 0 survivors.** Two survived on first
pass and both revealed real gaps — M28 (a cross-quantity check masked by the datum check) and M38
(`updateDesign` had no archive test). Both are now covered.

---

## 10. Consequences

- Every future numeric field with a physical dimension must follow this shape: entered value,
  entered unit, canonical mirror. A bare `decimal` column with a unit in the label is a defect.
- Reports and exports must print the **entered** value. The canonical column is rounded to its
  declared precision (150 psi stores as 1034.213594, not 1034.2135935) and would show a figure that
  appears on no document.
- Adding a unit is a reference-pack edit plus a CI run — no migration, no deploy of PHP.
- Renaming a quantity key orphans stored preferences. `check_units()` fails the build if a key the
  application names disappears from the pack.
