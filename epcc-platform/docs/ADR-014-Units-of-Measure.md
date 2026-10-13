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

## 11. PMS entry conformity finding (2026-09-29)

**Open implementation gap; no change to the accepted decision.** The recently added PMS form exposes `design_temp_min_c`, `design_temp_max_c` and `corrosion_allowance_mm` as fixed-unit fields. It does not implement the entered value/unit + canonical mirror contract or unit/preference controls for those inputs. `source_text`/`corrosion_allowance_as_written` preserve prose but do not replace the structured measurement pair.

This conflicts with the obligation in §10 for future physical numeric fields; it is recorded as **PMS-ADR-02** in the [reconciliation](ADR-RECONCILIATION-2026-09-29.md). Do not amend §10 to declare C/mm-only entry sufficient merely because that UI was shipped. Reuse UnitRegistry and UnitPreferenceService, preserve the source measurement, respect the C/F menu and test conversion/round-trip/absolute-zero behavior when correcting the implementation. Rating codes such as Class 150 are not pressure measurements and must not be passed through unit conversion as if they were.

This is a static source review, not a new test execution or an implemented fix. The same contract must be considered for project-material numeric properties documented in ADR-017; no general `_mm` naming exemption is granted.

## 12. Decision and rationale review (2026-09-29)

**DR-06 — proposed, pending decision:** pressure unit and datum are distinct. The current bar/psi menu is retained as a user requirement, but both codes map to GAUGE in the pack. A gauge-only field/project contract must be explicit, or datum must be recorded independently; an unspecified source basis must not silently become gauge. This does not propose automatic gauge/absolute conversion or a default atmospheric pressure. See [decision review](ADR-DECISION-REVIEW-2026-09-29.md).

**D-01 — verified arithmetic correction to the rationale, not to the accepted entered-value policy:** with the declared factor 6.89475729, 285 psi = 1965.00582765 kPa; 1965.0058 / 6.89475729 ≈ 284.9999959897 psi, not the 285.0006 stated in §2.1. The same erroneous example appears in the reference pack note; the pack was not edited in this documentation review. The need to retain original measurements remains valid.

**D-02 — stale diagram:** §3.1 distinguishes default_display from canonical; §7's diagram still says pack canonical. Read §3.1 as the later accepted display-fallback amendment. No unit menu or numeric application behavior changed in this review.

## 13. Accepted DR-06 — pressure datum independent of unit (2026-09-29)

User selected `independent_datum`. Retain the approved bar/psi menu, but store the pressure reference basis independently of the unit. A bare unit does not imply Gauge. Preserve explicit Gauge/Absolute evidence from the source; where the source does not identify a basis, retain Unknown rather than guess. Unknown-basis values cannot be relied upon in calculations requiring that basis. No automatic Gauge↔Absolute conversion is authorized.

This supersedes the implicit datum assumption of generic pressure units discussed in §3.1/§5 and the reference pack, not the entered-value/source-history or display-unit principles. Future design must handle explicit source spellings such as bara/psia without losing their basis or allowing contradictory unit/datum combinations. These details, storage, canonical conversion guards, legacy data treatment and tests remain to be specified/implemented; no pack/schema/code changes occurred in this decision step.

## 14. PMS header measurement implementation design (2026-09-29)

User requested operational completion after bulk entry; revision reviews remain paused. This increment implements the already accepted §2/§3.1/§10 contract for PMS design min/max temperature and class corrosion allowance only. It does not change pressure rating, pressure datum, publication or AFC policy. Governing ADR-008 §12/§17–18 and ADR-013 §10 remain.

- Add explicit entered-value text (bounded ordinary decimal, up to six fractional digits) and unit-code pairs adjacent to the existing C/mm canonical columns. Retain lexical decimal input such as `0.125000`; do not reconstruct the source pair from a rounded mirror. Widen mirrors to decimal(16,6) so 1/8 inch entered as 0.125 in is 3.175 mm, not 3.18. Raw fractions/scientific notation are not this input grammar; original source prose stays independent.
- UnitRegistry supplies selectable menus and affine conversion; temperature menu remains C/F (not K), length menu follows the existing pack. Validate quantity, unit, finite converted value, absolute-zero/nonnegative limits and canonical min/max ordering before rounding. Retain existing application upper bounds 9999.9 C and 999.99 mm. Physical bounds are structural, not proof of engineering suitability.
- Existing numeric rows retain NULL source pairs: migration does not invent original input or rewrite audit history. The editor offers explicit keep / enter / clear per measurement; existing fields default to keep, including legacy canonical-only values. New blank inputs use safe selectable defaults from user→project→pack preferences. Existing entered units do not change with display preference. Readers show original pair separately from canonical and preferred-unit equivalents, and label legacy provenance missing.
- Preserve compatibility with explicit old API field names `_c`/`_mm` when no new measurement keys are posted: their named unit defines the newly submitted value, not an inferred historical source unit. Missing measurement fields preserve existing values. Mixed old/new payloads refuse rather than silently choose. The new UI does not post writable canonical mirrors.
- Apply conversion/keep/clear after author/company/version checks under the existing transaction/locks; record source pairs and mirrors in existing before/after audit. Database checks enforce pair completeness, decimal grammar and physical/canonical limits. A trigger refuses mirror-only changes of captured measurements and discarding a pair while retaining a mirror. It does not independently reproduce the reference-pack conversion or turn raw SQL into per-human authorization. Internal canonical-only writers must not silently leave a captured source pair stale.
- Migration widens storage and adds constraints without fabricated backfill. Existing impossible historical canonical values cause migration validation to stop, not silent correction; reconcile them explicitly before deployment. Forward-only down refuses destructive precision/provenance loss. Full replay of historic reference packs remains outside this increment.

Acceptance planned: Fahrenheit/Celsius and inch/mm anchors, original-string preservation and no round-trip drift, differing min/max units, absolute-zero boundary and just-below/negative/NaN inputs, wrong/missing/retired units, preferences/legacy keep/no writes on reads, clear and audit, stale/unauthorized/archive requests, SQL partial-pair/mirror-only refusal, populated forward migration and regression of bulk/header/units consumers. Verified results will be recorded separately.


### 14.1 Implemented and verified PMS header scope

Implemented migration235800, PmsMeasurementService, captured pairs on PipingClass, integration into the post-authorization/version header transaction, FA/EN keep/set/clear inputs and original/canonical/preferred read-only summaries. Missing measurement keys preserve data; explicit new value requires a unit; unit-only submissions cannot clear an existing value. Validation errors name the offending value/unit/mode field. The existing explicit-unit API remains compatible without fabricated legacy backfill. No reference-pack changes.

Final serial sets: PmsMeasurement/PmsEntry/PmsBulkEntry/PmsAccess/PmsEntryMigration/PipingClassHeader **90 tests / 562 assertions**, UnitRegistry/UnitPreference **100 / 246**; combined **190 / 808**, zero failures/errors/skips. PmsMeasurement **16 / 146** is included, not additive. Engine corpus25/25. Blade compilation and four authenticated read-only live HTTP FA/EN checks pass; no visual-browser/full-product/restore certification. Populated upgrade, archive retention and stopping on invalid legacy data were tested in the isolated test database.

PMS-ADR-02 is addressed for these three current header fields. Other physical fields/consumers must still follow §10; no standalone PMS export was added. SQL guards enforce shape/bounds and refuse mirror-only drift, not independent recomputation of reference-pack arithmetic. Pressure datum DR-06 and publication/revision/AFC remain open/unchanged. [Delivery, usage and deployment limits](PMS-MEASUREMENTS-2026-09-29.md).

## 15. MTO quantity comparison across branch count and length — 2026-10-02

**Status: user-confirmed design; not implemented.** This supplements the source-value/unit contract in §§2 and 10 and the MTO design in ADR-005 §8; it does not claim that downstream material quantities now use canonical conversion.

- Compare required and ordered quantities only for the same material identity and scope, using compatible units.
- Pipes MTO retains both total length and procurement-branch count; the two shortage figures are reported separately.
- A branch count may be compared/converted to length only when the applicable supply length for the item/order is explicitly known. A general unit converter cannot infer that one branch has a particular length.
- For the accepted simple MTO branch-count estimate, use gross MTO total length—not net model/isometric need—divided by the explicit project supply length, rounded up. A manual branch count is retained and is not silently replaced by the estimate. If no applicable project supply length is defined, do not assume a generic default; automatic estimation is unavailable and the branch count must be entered manually for a complete MTO row. This is not cutting optimization.
- If the required and ordered units cannot be related by a defined applicable length basis, preserve their entered quantity/unit pairs, show the unit-specific values separately, and mark the cross-unit gap unavailable rather than guessing or silently rounding.

No code, schema, test, or runtime behavior is certified by this amendment. The existing MR first-entry slice still preserves native unit strings and does not close ADR-014's downstream unit-consistency gaps.
