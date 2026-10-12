# Material engineering screening — original read-only core report

> **Resolved in the next increment:** Missing material-family source has been
> reconstructed and tested; read-only checks are now displayed per stored record.
> See `MATERIAL-SCREENING-INTEGRATION-2026-09-29.md`. Blocker/runtime statements
> below describe the earlier stage, not current state. Rule boundaries still apply.


Date: 2026-09-29. User selected **engineering checks**, not PMS selection or review rerouting.

## Important baseline discrepancy

At entry, the previous material-extension report, test logs/XML and browser evidence were present, but the application source was fitting-only: migration 52, ProjectMaterialSchema, new material view/language files and the two material test classes were absent; the controller/definition/review services lacked the family extension. This is an observed inconsistency, not evidence that the earlier tests were never executed. Their counts must NOT be represented as verification of the current source.

`php scripts/verify-material-baseline.php` emits a non-destructive inventory and exits 1 while this prerequisite is incomplete. Current evidence: `/home/user/organization-report/material-baseline-preflight.json`. Do not activate new approval gates or silently recreate migration history against an unknown database based solely on prior reports. Restore/reconcile the material extension, then rerun its migration/tenant/workflow tests before integration.

The cause of the source discrepancy is not established. The restored vendor directory was a real directory; it has been moved to `/home/user/.cache/epcc-vendor` and replaced with a symlink to keep generated dependencies outside the saved source footprint. No project source or engineering reference data was deleted. No database reset, migration or project-data write was performed this turn.

## Delivered in this increment

`app/app/Services/Engineering/MaterialScreening.php` is a dependency-free, read-only checker for the documented declaration input shape. `scripts/screen-material.php` exposes JSON findings or a standalone escaped bilingual HTML report:

```sh
php scripts/tests/test_material_screening.php
php scripts/screen-material.php docs/examples/material-screening-synthetic.json
php scripts/screen-material.php docs/examples/material-screening-synthetic.json --html > report.html
```

Input: one local JSON object, maximum 1 MiB. The example is conspicuously synthetic. The CLI boots no framework, connects to no database, makes no network calls and writes no files itself. Exit 0 means report generation succeeded, **not** that the material passed engineering review. Invalid input exits 1.

Output includes rule version, fingerprint of the JSON declaration, bilingual findings with code/field/basis, consulted reference pack versions/sources/SHA-256, and explicit `engineering_approved=false`, `automatic_consumption_allowed=false`. It never alters source wording, a Spec revision or a declared value.

### Three distinct outcomes

- `INCOMPLETE`: missing source fields or minimum review context.
- `DISCREPANCY`: definite local inconsistency, e.g. invalid declared mm value, closed pipe bore, inner diameter not smaller than outer, or B16.9 applied as the component standard to a non-fitting group.
- `REVIEW_REQUIRED`: reference gap, unresolved edition/notation, nominal reference difference, unknown subtype/property, or required verification against the actual source.

Every result retains a source-review finding. There is deliberately no automatic APPROVED/PASS state. Minimum preparation fields are a **screening profile**, not invented mandatory clauses of a standard. Where a field genuinely does not apply, the engineer must resolve that from the source; this core does not supply an audited N/A exception mechanism or block project submissions.

### Implemented checks

- Source number/revision/clause/wording, size designation, component/material source specifications, subtype and declaration reason.
- PIPE: product form and schedule-or-wall context; positive finite mm dimensions with at most four decimals; open-bore geometry.
- FLANGE: rating/facing context; explicit A/B series when B16.47 is cited; bore versus outside diameter if both declared.
- VALVE: construction standard, rating, end connection, trim and operator context. No arbitrary gearbox threshold or automatic actuator-to-PMS conversion.
- GASKET: construction context; inner/outer consistency when both declared. No invented universal thickness or pressure-class requirement for every gasket type.
- BOLTING: thread standard context; distinguish recognized NUT from BOLT/STUD/STUD_BOLT so nut records do not require bolt length. Unrecognized subtype requires review, rather than inferring it from prose. NPS/DN is not treated as a bolt thread designation.
- Rating notation: unitless/ambiguous values require review. Class/PN/pressure units are neither converted nor treated as equivalent. Recognition of notation is not validation of a rating or pressure-temperature capability.

### Reference comparison boundary

PIPE nominal OD/wall comparison only runs for exact `ASME B36.10M-2022` or `ASME B36.19M-2018` declarations and explicit resolvable NPS-with-inch notation. Uses the existing pipe-size and pipe-schedule packs without modifying them. Exact source labels are retained; there is no DN/NPS conversion, schedule alias fallback or reference-value replacement.

- STD is not silently mapped to 40; XS is not mapped to 80.
- Stainless comparison is restricted to explicit S schedules supported by this check; ambiguous cross-standard schedule applicability requires review.
- Missing size/pair is a **loaded-reference gap**, not a claim of outside-standard scope.
- Edition mismatch or malformed/missing reference prevents comparison.
- A difference from a nominal table value is a review finding, not a manufacturing rejection: tolerances and measurement-vs-nominal meaning are not available.
- Fingerprints make the consulted source pack identifiable; they do not certify its transcription or purchased-source verification.

Existing B16.9/cap checks and the ½–48 fitting boundary are untouched. FITTING/CAP/ELBOW are intentionally outside this new non-fitting checker and continue to require the existing fitting path.

## Verification executed this turn

- Standalone PHP checker regression: **60 assertions passed**, covering five families, missing context, numeric/geometry errors, exact-edition/reference gaps, STD distinction, Class/PN handling, subtype conditions, input immutability and fail-closed reference handling.
- CLI smoke checks passed: JSON and HTML generation, source unchanged, HTML escaping, malformed JSON/list/null rejection.
- Four PHP files passed syntax checks.
- **21 Python reference tests passed; all 3 project + 16 reference packs validated.**
- No Laravel/PHPUnit full suite, authenticated browser workflow, database migration, approval-gate integration or production security certification was executed/claimed in this turn.

## Next work, in order

1. Reconcile/restore the missing material-family implementation against the prior technical report and acceptance evidence. Preserve known fitting protections and do not infer current database contents.
2. Rerun populated migration, RLS/category-policy/author-chain/correction and bilingual UI regressions against the restored source.
3. Integrate this read-only checker into per-declaration display, using the actual authorized stored record, not trusted browser-posted approval state.
4. Define audited handling of project-specific applicability/N/A and source evidence before enabling blocking approval rules.
5. Add verified source-backed checks for other families, pressure-temperature ratings, material compatibility and design conditions. A material name or nominal Class alone is insufficient for those checks.

PMS selection/consumption, approval revocation and model/MTO changes remain out of scope.
