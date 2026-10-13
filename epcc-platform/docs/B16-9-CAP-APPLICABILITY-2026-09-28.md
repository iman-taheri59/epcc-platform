# B16.9 cap applicability correction — completed for existing covered sizes

> **LATEST USER RULING / IMPLEMENTATION:** B16.9 scope remains NPS 1/2–48;
> ingestion limits do not shrink the standard. Pack v1.2.0 now has 99 rows
> spanning that range for LR90/LR45/caps. Large cap E values are recorded;
> E1/threshold selection still requires agreement, not guessed values.
> Outside-range lookup routes to PROJECT_SPEC_REQUIRED. Project-definition
> authoring/storage is still pending. See `docs/B16-9-SIZE-SCOPE-2026-09-28.md`
> from the repository root. Current tests: 50 PHP / 663 assertions, 21 Python,
> all 19 packs passed. Older coverage figures and process IDs below are historical.


Date: 2026-09-28. Supersedes the open cap defect in `B16-9-SOURCE-REVIEW-2026-09-28.md` for the existing NPS 1/2–24 coverage only.

## Data change

`fitting-dimensions.ref.json` is now version **1.1.0**. All original 65 dimension values remain unchanged. Each of the 21 cap rows now also has:

- `limitingWallThicknessMm`: the boundary for use of E.
- `e1Mm`: the E1 length above the boundary.

Source: user-supplied ASME B16.9-2018, Table 6.1-10, notes (1) and (2), printed page 20 / PDF page 31. PDF SHA-256: `f254b756cf54dc4115fba10c659f205cee913c816068736ee76f59b608052ab4`.

The 42 new cells were manually transcribed, compared against the independently layout-extracted source columns by OD, and pinned in Python regression tests. The original 65 selected numeric cells still match the supplied PDF. This is a limited source transcription/applicability correction, not full engineering certification.

## Reader contract

```php
$ref = app(\App\Support\Reference\FittingDimensions::class);
$ref->lookup('CAP', null, '6');          // UNAVAILABLE / CAP_THICKNESS_REQUIRED
$ref->lookup('CAP', null, '6', 10.92);   // AVAILABLE: E, 89 mm
$ref->lookup('CAP', null, '6', 10.920001);// AVAILABLE: E1, 102 mm
```

The new optional fourth argument is the **applicable specified cap wall thickness in mm**. It is not a schedule code, corrosion allowance, guessed pipe wall or silently substituted field measurement. The caller must establish that its input is appropriate to the cited thickness condition.

- At or below the boundary: E.
- Strictly above: E1. No boundary rounding is applied.
- Absent input: `CAP_THICKNESS_REQUIRED` with a null row.
- Zero, negative, NaN or infinite input: `CAP_THICKNESS_INVALID` with a null row.
- NPS 26 and larger: still `SIZE_NOT_TABULATED`. No expansion was attempted. The source requires manufacturer/purchaser agreement for E1 in that range; the application does not model or infer it.

Available cap results carry `selection` with the supplied thickness, threshold, original tabulated E and applicable note. The returned row's `symbol` and `mm` identify the selected E/E1 value. Selection never mutates the cached reference row: an E1 lookup followed by an E lookup still returns the correct E. Even where E and E1 are numerically equal, the selected symbol/note remains explicit.

The loader rejects legacy/incomplete cap rows, invalid applicability fields, E1 shorter than E, applicability fields on elbows and attempts to extend cap coverage above NPS 24 without implementing its distinct semantics. JSON Schema and Python semantic validation enforce corresponding constraints.

## PMS consumer contract

```php
$result = app(\App\Services\PipingClassBody::class)
    ->fittingDimensionForItem($item, 11.0, 'Approved cap thickness specification, row 7');
```

The consumer requires both an explicit thickness and a nonblank reference identifying its source. Missing source returns `CAP_THICKNESS_SOURCE_REQUIRED`. The supplied reference is retained as `selection.thicknessSource`; this is provenance supplied by the caller, not automatic verification of the document's authority. Existing standard/edition, BW-end and NPS/DN checks remain.

No automatic Schedule conversion is performed. Neither the source PMS row nor any component length is changed. This correction remains service-level work: no new PMS UI, physical-model writeback, pressure adequacy check or database migration was added.

## Verification

- Red regression before the fix: lookup without thickness incorrectly returned AVAILABLE. Evidence: `cap-before.log`.
- Reader and cap tests: **13 tests / 461 assertions**, passed.
- Combined reader, cap, PMS body and compliance suite: **44 tests / 514 assertions**, passed, no skips. Includes the preceding 13 tests; totals are not additive.
- Python fitting regressions: **18 passed**, including source-backed values and schema/semantic mutation probes.
- All **3 project packs + 16 reference packs** validate.
- Four changed/new PHP files pass syntax checks.
- Source comparison: **65/65 existing dimension cells + 42/42 new applicability cells** match the extracted source columns.
- Local login returned HTTP 200 after restarting the app on port 8000 with runtime debug disabled. No browser matrix or full PHP suite was rerun; external gateway verification remains unresolved.

Evidence is under `/home/user/organization-report/`: `cap-reader.{log,xml}`, `cap-pms-regression.{log,xml}`, `cap-python.log`, `cap-pack-validation.log`; source comparison under `b16-source-review/`.

## Handoff

No application DB reset/reseed was required in this increment. Test fixtures operated on the guarded test database. Current processes: PostgreSQL `epcc-66a530a4`, application `epcc-e25c6080`. Missing vendor cache was reconstructed from the existing lockfile.

Next: source-preserving PMS input/review that makes missing thickness/source visible and shows selected dimensions separately from the source text. Generic modelled elbows still lack an explicit structured radius/angle selection; do not infer those from defaults. Larger caps and reducing/two-size fittings remain separate scope.
