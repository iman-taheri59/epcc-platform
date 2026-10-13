# Project-defined fitting register — initial authoring slice

> **Subsequent implementation — 2026-09-29:** configurable review and append-only
> corrections are now built; see [FITTING-REVIEW-WORKFLOW-2026-09-29.md](FITTING-REVIEW-WORKFLOW-2026-09-29.md).
> The no-review/no-correction descriptions below are the original registration slice.
> Automatic engineering consumption and conflict reconciliation remain pending.


Date: 2026-09-28. Implements the next part of the user's rule: sizes outside B16.9's NPS 1/2–48 scope must be user-defined against the project Spec, not extrapolated from the standard.

## Delivered

**Entry point:** project → piping lines → “Project-defined fittings” / «تعریف‌های اختصاصی پروژه».

Routes:
- `GET /projects/{projectCode}/piping/project-fittings`
- `POST /projects/{projectCode}/piping/project-fittings`

The bilingual FA/EN, RTL/LTR form and paginated register support **single-size ELBOW and CAP declarations outside NPS 1/2–48**. Inputs: component type, elbow qualifier as specified, NPS in inches, explicit OD in mm, optional explicit DN, dimension meaning, dimension value in mm, Spec number/revision/clause and original source wording/applicability conditions. Numeric precision is limited to four decimal places; excess precision is rejected, not silently rounded. DN/OD/radius/length are never inferred.

ELBOW requires a nonblank angle/radius qualifier and CENTRE_TO_END; CAP requires no elbow qualifier and LENGTH. The form does not add definitions for reducing/two-size fittings or replace the agreement workflow for 26–48 inch caps inside the standard's scope.

## Storage and trust boundary

Migration `2026_09_28_140000_create_project_fitting_definitions.php` creates a dedicated table. New records are always:

- `provenance = PROJECT_SPEC`
- `review_state = UNREVIEWED`
- Attributed server-side to the authenticated user/company and resolved project.

Client-supplied company/project/author/provenance/approval fields cannot override these values. Original wording is stored and HTML-escaped on display. Database CHECKs enforce out-of-range NPS, positive numeric values, dimension/type consistency, nonblank source metadata and the unreviewed/project-spec states. A unique source key rejects duplicate declarations by the same company; a duplicate never overwrites existing data. Insert uses an inner savepoint so PostgreSQL constraint errors can become validation messages without leaving the outer tenant transaction aborted.

This is an **append-only declaration register**, not engineering approval. There are no update/delete/approval endpoints or RLS update/delete policies. Corrections/supersession with an audit trail remain future work; users should not invent a Spec revision to work around that absence. Cross-company conflicting declarations are not automatically reconciled or selected.

## Access control

An active user must belong to a company participating in the project and have project membership. Eligible participants may submit unreviewed declarations; this increment does not grant engineering approval rights to any role.

Database RLS is enabled and forced. Reads require explicit project scope and participating company. Insert additionally requires creator-company ownership, a writable project, an active same-company author and author project membership. A participant may read another participant's declaration in the same project but cannot update/delete it. Unscoped reads, nonparticipants and wrong-project reads are denied. Removed participation revokes access even with stale user membership. Archived projects remain readable but cannot accept new definitions.

## No automatic engineering consumption

Neither the universal B16.9 pack nor PMS source rows nor model component dimensions are changed. `PipingClassBody` still reports PROJECT_SPEC_REQUIRED for out-of-range sizes: a later explicit, reviewed project-definition selection must connect that result to this register. A registered row must not be treated as an approved standard value. No general custom-size expansion of piping components/MTO was performed.

## Adjacent defect discovered and fixed

The first real browser visit by a contractor to a fresh project's piping register returned HTTP 500: `PipingLineService::configFor` attempted to INSERT an employer-owned configuration under the contractor's tenant.

A regression first reproduced the failure. The service now returns an unsaved default configuration for non-owner contexts or archived projects when no stored config exists. It does not impersonate the owner or weaken RLS. Existing owner-side creation behaviour remains. The regression verifies the contractor can find the new form link and no owner configuration row is inserted.

## Verification evidence

Evidence under `/home/user/organization-report/`:

- `project-spec-regression.{log,xml}`: **155 tests / 946 assertions passed**, no failures/errors/skips. Includes 14 new registry tests / 83 assertions plus reader, piping, equipment and unit-preference regression coverage. Counts from the initial run below are not additive.
- `project-spec-tests.{log,xml}`: initial 9-test authoring/isolation run, 63 assertions, passed; superseded by the larger final run.
- `project-spec-piping-before.log` / `project-spec-piping-after.log`: fresh-project contractor navigation red/green regression.
- `project-spec-browser/results.json`: actual CSRF-protected browser registration, duplicate rejection, in-range form rejection, owner visibility, nonparticipant denial and link discovery.
- Four page cases: FA/EN × 1440/430 widths, HTTP 200, correct direction, no root overflow; zero recorded page JS errors. Mobile Persian screenshot visually inspected. Screenshots: `project-spec-browser/{fa,en}-{1440,430}.png`.
- `project-spec-lint.log`: nine changed/new PHP files checked successfully.
- `project-spec-reference-tests.log`: 21 Python fitting regressions passed.
- `project-spec-packs.log`: all 3 project packs + 16 reference packs validate.

The browser script is guarded by `EPCC_ALLOW_DEMO_WRITES=yes`. It left **one** explicitly synthetic, unreviewed 52-inch elbow declaration (`SYNTHETIC-UI-SPEC-52`, revision `DEMO-A`) in PH-14; duplicate/invalid submissions created no additional records. Its dimensions are demonstration inputs, not a project specification or design recommendation.

## Environment

Ephemeral runtime dependencies/database state had to be restored; the user-authorized disposable demo was rebuilt, now with **50 migrations**. Test fixtures used the isolated guarded test database. PostgreSQL process `epcc-de02986b`; app process `epcc-4ac7c9ad`, port 8000, runtime debug disabled. Chromium/Playwright used for local browser acceptance. Direct authenticated Arena gateway acceptance remains unverified; local browser results are not a claim about that gateway. No full application test-suite run or comprehensive production security assessment was performed.

## Next scope

1. Audited corrections/supersession and role-authorized review, including contradictory declarations from different companies.
2. Explicit selection of an approved project definition in PMS, followed by deliberate piping/MTO consumption; never a silent reference fallback.
3. Separate manufacturer/purchaser-agreement capture for in-range large caps.
4. Two-size fittings and the wider PMS authoring/review workflow remain separate increments.
