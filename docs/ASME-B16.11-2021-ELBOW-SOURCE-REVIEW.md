# ASME B16.11-2021 elbow dimension source review

**Report date:** 2026-11-16

**Source:** user-supplied `/home/user/uploads/ASME B16.11-2021.pdf`

**SHA-256:** `5fb9295df91fcb38978ec6a7eddabc53057c5465af63aac647f5a56bb4040664`

This is a limited source review and SI dimension transcription for the two-end 45-degree and 90-degree elbows used by the separate B16.11 port-geometry profile. It is not an engineering certification, CAD solid, installation-weld specification, or pressure-rating determination.

## Source pages reviewed

| Source | Printed page | PDF page | Use |
|---|---:|---:|---|
| Table 1.1.1-1 | 5 | 18 | Elbow size/class availability |
| Table 6.1-1 (SI) | 6 | 19 | Socket-welding elbow dimensions |
| Table 6.1-1C (US Customary) | 7 | 20 | Unit-system cross-check; not transcribed |
| Table 6.1-3 (SI) | 10 | 23 | Threaded elbow dimensions |
| Table 6.1-3C (US Customary) | 11 | 24 | Unit-system cross-check; not transcribed |
| Figure 6.2.7-1 | 18 | 31 | Meaning and status of the recommended socket-weld gap |

The SI values are transcribed as the standard's separate metric values. They are not produced by converting the inch tables; the standard treats the two unit systems independently.

## Verified scope

Table 1.1.1-1 lists these elbow class/size ranges:

- Socket-welding: Class 3000, NPS 1/8–4; Class 6000, NPS 1/8–2; Class 9000, NPS 1/2–2.
- Threaded: Classes 2000, 3000, and 6000, each NPS 1/8–4.
- The dimension tables list 12 nominal sizes from NPS 1/8 through 4. Only the 63 listed class/size rows are represented in the transcription; no missing pair is inferred.

The tables identify **45-degree and 90-degree** elbows. They do not give these elbows `LR` or `SR` qualifiers. The B16.11 `45` / `90` values are not aliases for B16.9 `45LR` / `90LR`, and no bend radius is inferred.

## Dimension semantics and application datum

- **Socket-welding, Table 6.1-1:** `A` is center-to-bottom-of-socket; `J` is minimum socket depth. The reference stores both raw dimensions separately. The application profile explicitly sets each SW port datum at the **socket bottom** and uses tabulated `A` directly as the local tangent distance. `J` remains provenance; the profile does not calculate `A − J` or claim that the standard directly tabulates a socket-mouth distance.
- **Threaded, Table 6.1-3:** `A` is center-to-end for a 90-degree elbow; `C` is center-to-end for a 45-degree elbow. The profile consumes the matching value directly as the tangent distance to the threaded-end plane.
- **Socket-weld installation gap:** Figure 6.2.7-1 depicts an *approximate 1.5 mm recommended* gap before welding. It is not an exact size/class-specific insertion dimension and is never added as a geometry offset or input.
- B16.11 class designation is an explicit dimensional lookup key, **not** a project PMS class and not a directly encoded pressure rating.

## Transcription and comparison

The metric geometry columns are recorded in:

- `standards-packs/reference/forged-fitting-elbow-dimensions.ref.json`
- `standards-packs/reference/schema/forged-fitting-elbow-dimensions.schema.json`

The transcription contains 63 size/class rows: 27 socket-welding and 36 threaded. All 63 supported size/class records were compared against table cells extracted from the supplied PDF; the comparison found zero mismatches. The repository validator checks the declared coverage matrix, row keys, dimension symbols and kinds, source fingerprint, and representative values. This comparison validates transcription consistency only, not manufacturing tolerances or engineering suitability.

## Application integration boundary

The reference is consumed through `ForgedFittingElbowDimensions` and `ForgedFittingElbowGeometryProfile`, kept separate from the existing B16.9 `FittingDimensions` and `ButtWeldElbowGeometryProfile` path.

For B16.11, component placement records an explicit standard, angle (`45` or `90`), class designation, and one selected end type copied to both ports (`SW`/`SW` or `SC`/`SC`). The class is never obtained from PMS. Only exact reference rows resolve; missing class/NPS/end-configuration combinations remain `UNRESOLVED`, with no interpolation or extrapolation. Mixed ends and street elbows are not represented. The existing B16.9 BW `90LR` / `45LR` path remains separate and unchanged dimensionally.

The profile is port geometry only. It uses the ADR-061 local frame contract, explicit manual pose and frame, profile hash, and transformed port points/directions. Pose revisions remain DRAFT-only and append-only; changing source inputs marks prior geometry `STALE`, and this profile does not change topology or joint ownership. It does not generate a CAD solid or Autodesk Plant 3D `.ctpl` / `.pcat` file.

## Verification status

Verification performed in this work session:

- `python3 standards-packs/validate_packs.py` passed: all 3 standards packs and 17 reference packs valid; B16.11 has 63 rows (27 SW, 36 threaded).
- PHP 8.4.27 syntax checks passed for the B16.11 reference/profile, component/controller/pose integration, model/provider, migration, and focused unit/feature test files. Pint passed on all 10 targeted PHP files.
- PHPUnit passed the focused B16.11 unit class (6 tests, 79 assertions), the B16.11-filtered `ComponentModellingTest` (4 tests, 55 assertions, including HTTP create and attach), and two B16.9 regression feature tests (2 tests, 35 assertions). The B16.11 path remained separate from the B16.9 BW LR path.
- Before feature tests, the configured `epcc_test` database was confirmed empty (no public tables or migration ledger). The feature test class uses `migrate:fresh` and its two test seeders on this isolated `epcc_test` database only. The separate `epcc` development database was not reset or seeded.
- The sandbox had no PostgreSQL cluster before runtime rebuild. The approved rebuild created the local cluster/database and applied ordinary pending migrations only; it did not drop/reset, run `migrate:fresh`, or seed `epcc`. A read-only `migrate:status` check confirms `2026_10_26_231000_add_b16_11_fitting_specification` is `Ran`.
- No full Laravel suite was run. Tests and migrations were confined to the checked local sandbox and isolated test database; verify database state again before future migrations or test resets.
