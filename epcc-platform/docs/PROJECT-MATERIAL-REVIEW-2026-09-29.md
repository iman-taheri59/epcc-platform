# Project material declarations: category-scoped review and corrections

> **CURRENT — 2026-09-29:** The missing material-family source has been
> reconstructed and regression-tested; the blocker described in the earlier
> engineering-core report is resolved. Stored-record, bilingual read-only technical
> screening is now integrated. See `MATERIAL-SCREENING-INTEGRATION-2026-09-29.md`.
> Current: 484 PHP tests / 10,985 assertions, 60 core assertions, 21 Python tests,
> 19 packs and 24 browser cases passed. Earlier counts below are historical.
> Submission blocking/N/A evidence, persisted screening snapshots and downstream
> PMS/model/MTO consumption remain pending.


Date: 2026-09-29 (user-local). Supersedes the fitting-only scope of the earlier review report; its core workflow invariants remain in force.

## User instruction and implementation

Extend the delivered protections to other piping materials where needed. The shared declaration/review mechanism now supports **PIPE, FLANGE, VALVE, GASKET and BOLTING**, alongside existing FITTING declarations (single-size CAP/ELBOW).

The existing route `/projects/{code}/piping/project-fittings` and internal table/model/service names remain for compatibility. The visible navigation is **Project material declarations** / **تعریف‌های اختصاصی متریال**. A validated `family` query selects the category; absent means FITTING. This is one shared workflow engine, not five duplicated workflow implementations.

### Shared protections

- Original Spec number, actual revision, clause/table and original wording are mandatory and preserved. New categories also require the component standard/edition or explicit project Spec, material specification/edition, size designation with units, subtype and reason for the project-specific declaration. Material grade is separately recorded if declared; not inferred from the component standard.
- Corrections append a new version with a reason, predecessor and root. Originals, review decisions and source revisions remain unchanged; approval is never inherited.
- Ordered independent reviewers, no author anywhere in the correction chain, no repeat signers, current membership/activity/PIPING scope and company-scoped role checks remain shared.
- **Policy versions are now per project AND material family.** Existing policies default only to FITTING. No new family receives policy settings or review authority by migration. Until separately configured by an authorized owner-company project configurator, submission and approval remain blocked.
- In-flight reviews retain their captured policy; new settings affect future submissions only. Existing decision guard, RLS, project advisory lock and archive checks remain in place.
- Corrections cannot switch material family, at both service and database FK boundaries. A new database trigger rejects a submission bound to a policy for another family, including raw SQL attempts.

## Technical data and applicability

For non-fitting materials, `size_designation` preserves the source's own notation: e.g. NPS with inches, DN, or metric bolt/thread notation. It is **not** parsed into fitting NPS or converted between units. Fitting-only NPS/DN/OD/single-dimension inputs are prohibited for the new categories.

Optional technical fields are category-specific:

| Family | Declared attributes |
|---|---|
| PIPE | Schedule, wall thickness, outside diameter, product form/manufacture |
| FLANGE | Pressure rating, facing, series, bore, outside diameter, thickness |
| VALVE | Construction standard/edition, rating, end connection, trim, operator/actuator/datasheet reference, face-to-face |
| GASKET | Construction/type, rating, ring designation, inside/outside diameter, thickness |
| BOLTING | Thread standard/edition, bolt/stud length, nut height, coating; nominal thread notation in size designation |

Dimensions explicitly labeled mm must be positive with at most four decimals and fit the declared numeric bound. Unknown/cross-family technical keys are rejected by service validation. Rating, Class/PN, schedule, series, trim and similar identifiers remain text as written; no numerical conversion or assumed equivalence. Properties are stored as a JSON object, not a fitting dimension or material-property inference.

**Optional means not declared, not compliant or zero.** The bilingual page explicitly requires reviewers to check completeness and applicability against the source. This increment does not supply an automatic completeness matrix, verify every cited edition or certify engineering conformance. In particular, it does not invent universal size limits from the coverage of transcribed reference packs. B16.9 cannot be entered as the component standard for these non-fitting groups.

Existing B16.9 range remains **NPS ½–48**. The original outside-range fitting declaration restriction, in-range reference-gap distinction and separate cap conditions are unchanged. Universal standard packs are untouched. Existing valve/body/trim/operator/actuator rules and actuator datasheet separation remain unchanged; recording a source declaration does not create a PMS valve or datasheet.

## Migration and security boundaries

Migration 52: `2026_09_29_150000_extend_project_material_declarations.php`.

- Add family and source/technical metadata without rewriting historical values.
- Conditional database shape keeps original fitting geometry and size constraints; new groups require their source headers and keep legacy fitting geometry NULL.
- Root source uniqueness is supplemented for non-fitting textual sizes; corrections may keep the same actual Spec revision.
- Composite self-FKs bind correction lineage to project, company AND family.
- Policy uniqueness becomes `(project_id, material_family, version)`; all pre-extension policies retain FITTING authority only.
- Cross-family review-run trigger supplements the existing ordered decision trigger.
- Existing forced RLS and append-only policies remain unchanged. Migration is forward-only to avoid destructive history rollback.
- PostgreSQL function uses CREATE OR REPLACE so disposable `migrate:fresh` works even when table-independent functions survive table drops. This was found and fixed during the initial test pass.

As before, configuration authority and remaining independent-reviewer feasibility have service checks; do not claim a comprehensive production security audit. Technical-property field validation is in the service; the database additionally checks JSON object shape and the required category/source structure, not every engineering attribute.

## Verification of final code

Two disjoint targeted PHP groups:

1. **97 tests / 1,040 assertions**: fitting and material declaration/review, B16.9 scope, caps, fitting dimensions and PMS body/compliance.
2. **388 tests / 9,972 assertions**: pipe reference, materials library/presentation, flange bolting/ratings, manual valves, actuators, valve face-to-face/ratings/testing, and populated forward migration.

Total **485 tests / 11,012 assertions passed**. Includes **20 new tests / 231 assertions** (19 material review tests, one migration test); these counts are not additive to the totals.

New acceptance coverage includes all five families reaching independent review approval, no inherited fitting policy, separate version counters, source/size preservation, duplicate declarations, correction history and author-chain exclusion, stale/returned/archived/cross-project actions, role changes, immutable events, raw SQL family-policy mismatch and family-changing lineage, category-specific field validation and bilingual HTTP forms with escaped source wording.

Populated migration test builds the previous 51-migration schema, inserts a fitting definition, policy, submission and valid decision, applies migration 52, verifies **every pre-existing column value remains equal**, and verifies all new families still have no policy.

- **21 Python reference tests passed; all 3 project packs + 16 reference packs valid.**
- **10 PHP files passed syntax checks.**
- Actual three-actor Chromium PIPE workflow: configure consultant→owner, submit, return, correct while retaining Spec A, resubmit, configure future one-stage policy, complete old two-stage snapshot. No self-approval control; original return and both approval decisions retained.
- Actual browser registration of FLANGE, VALVE, GASKET and BOLTING with family-specific attributes: all stayed unreviewed with no inherited submission workflow.
- Final read-only **24 page cases**: six categories × FA/EN × desktop 1440/mobile 430; HTTP 200, correct direction, no root overflow or JS page errors. Persian bolting mobile screenshot visually inspected.

Evidence: `/home/user/organization-report/materials-final.{log,xml}`, `materials-adjacent.{log,xml}`, `materials-reference-tests.log`, `materials-packs.log`, `materials-lint.log`, `materials-review-browser/results.json`, `materials-pages.json` and screenshots. Do not rerun the browser write scenario over existing records. `materials-pages.cjs` is read-only and safe to rerun.

No full PHP suite, concurrency/load test or complete production security audit is claimed.

## Current runtime/demo and remaining work

Runtime packages and PostgreSQL were absent after workspace restoration, so dependencies and the user-authorized disposable demo DB were rebuilt. Previous browser evidence remains on disk; the old fitting demo rows are not the current runtime dataset.

Current demo: **52 migrations, 6 synthetic material definitions, 2 PIPE policy versions, 2 review runs, 3 decisions**. One returned/superseded PIPE root and one approved correction; FLANGE/VALVE/GASKET/BOLTING each have one unreviewed root and no configuration. No current FITTING policy in the rebuilt demo. These are acceptance-only records, never construction/engineering recommendations.

Database process: `project-database-c2d77493`; application: `epcc-platform-10b292fd`, listening 0.0.0.0:8000 with APP_DEBUG=false. `.env` unchanged. Local browser acceptance is not verification of the external Arena traffic gateway.

Still deliberately not built:
- Automatic application to PMS, model, MTO, quantities or cut lengths—even for APPROVED declarations.
- Explicit reviewed selection, conflict reconciliation and downstream consumption.
- Audited rerouting/withdrawal/revocation or unchanged-version resubmission.
- Automatic coverage/edition verification or full engineering completeness rules for all material families.
- Existing pending large-cap agreement capture and two-size fitting definitions.
- Changes to procurement stock, receiving inspection or unrelated disciplines.
