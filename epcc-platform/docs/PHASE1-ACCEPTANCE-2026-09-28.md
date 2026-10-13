# Phase 1 — live demo and practical acceptance

> Historical phase-1 snapshot. Subsequent implementation and current reset
> status: [B16-9-PHP-READER-2026-09-28.md](B16-9-PHP-READER-2026-09-28.md).
> B16.9 PHP reading/initial PMS service consumption is now implemented. Prior
> synthetic acceptance writes are no longer current demo records after reseeding.


Date: 2026-09-28. This report supersedes historical launch/coverage claims, not the requirements. Technical documentation is in English; user communication is Persian.

## Outcome and limits

A synthetic demo is running on port 8000 with PostgreSQL 17 and runtime `APP_DEBUG=false`. There was no existing application database or populated attachment store when inspected. The `epcc` database was created and seeded once; 49 migrations were applied, including the repaired baseline RLS policies. Three synthetic projects are present. The database was not reset for browser acceptance. Restrictively permissioned database/attachment backups were taken before business-workflow writes.

Local Chromium acceptance passed. **The external Arena traffic gateway is not independently verified:** a direct request returned 403 / Missing Traffic Access Token. A separate local HTTPS cross-site iframe simulation, stripping Cookie headers, successfully exercised login → project → document register via the existing preview session fallback. This is simulation evidence, not gateway acceptance. No signed session URLs should be shared. The temporary TLS test proxy has been stopped; the application and PostgreSQL remain running.

## Current capability inventory

“Implemented” below does not mean complete against every requirement or production-certified.

| Area | Implemented / observed now | Incomplete or not covered by this acceptance |
|---|---|---|
| Core, access and bilingual shell | Login, project navigation, FA/EN and RTL/LTR; owner/contractor access; nonparticipant project denial; persistent desktop sidebar and mobile Escape | Full production security assessment, load testing, complete role/route matrix |
| Engineering documents | Live browser creation of bilingual DWG; attachment upload and byte-identical download; WIP → SHARED/S1 | Full review/publish/transmittal lifecycle was not repeated in this browser run; existing implementation/tests are separate evidence |
| Piping and model import | PCF fixture import, quarantine, owner visibility of contractor import, populated component/joint model page | Real project PCF qualification, acceptance of imported line, comprehensive model-editing workflow |
| ITP | Create from PIPING template and register as a draft document; five-stage detail page; preserve Persian after POST | No ITP publication/activation in this run; end-to-end daily inspection/NCR integration remains future scope |
| Procurement and warehouse | Existing services and live bilingual pages; targeted regression tests passed | No new end-to-end purchasing/receiving/issuing workflow executed in this phase |
| Quality / completion | Existing punch and MC certificate services; punch pages and PDF endpoint checked | Full closeout/issuance workflow not rerun; this is not acceptance of the entire commissioning scope |
| Standards and PMS | Existing standards library, piping-class header/body/rules/compliance services; B16.9 reference dataset and validators | B16.9 PHP reader/reference-aware PMS/piping consumption not yet built; robust source-preserving PMS entry/review needs its own implementation scope |
| Other product modules | Historical roadmap remains in TODO/SRS | No new NCR/reporting, HSE, scheduling or deferred AWP module delivered in this phase |

Latest feature addition remains the user-confirmed **ASME B16.9 data**, not the chronology in old handoff entries. The 65-row dataset is unchanged: 22 LR90, 22 LR45 and 21 caps. Reducing/two-size fittings were not added. Original purchased standards are absent, so dimensional certification against those originals is not claimed. No current percentage-of-product-completion is asserted.

## Acceptance evidence

Evidence directory: `/home/user/organization-report/acceptance/`.

| Check | Result | Evidence |
|---|---|---|
| Final populated read-only browser matrix | 68 cases: 17 routes × FA/EN × 1440/430 widths; 0 issues, JS errors or failed requests | `browser-results.json`, `browser-populated-final.log`, `screenshots/` |
| Navigation/access interactions | 4 passed | Same browser JSON |
| PDF endpoints | 4 valid PDF responses: transmittal/punch × FA/EN | PDF files and browser JSON |
| Visual spot check | Persian transmittal PDF first page, populated mobile piping model and desktop Persian ITP detail inspected | `screenshots/transmittal-pdf-fa.png`, `piping-model-fa-430.png`, `itp-detail-fa-1440.png` |
| Actual business workflow | 7 steps, 0 issues on final run | `workflow-results.json`, guarded `workflows.cjs` |
| Local cookieless cross-site HTTPS iframe | Passed; 4 request Cookie headers stripped | `iframe-results.json`, `screenshots/cookieless-iframe.png` |
| Presentation/date/shell/warehouse/procurement regression | 81 tests, 263 assertions, exit 0 | `presentation-regression.log` and `.xml` |
| Final ITP/materials regression | 73 tests, 185 assertions, exit 0 | `itp-final.log` and `.xml` |
| Syntax | Four changed controller/test PHP files and browser runner passed syntax checks | Commands executed after fixes |

The two regression groups overlap and must not be added as a distinct-test total. The earlier maintenance audit's 1596 distinct tests / 48455 assertions predates these presentation changes; the full suite was not rerun after the latest changes. PDFs were not all exhaustively visually inspected. The browser matrix checks page health, language direction, nonblank content and root overflow; it does not prove every control works.

## Fixes made during practical acceptance

1. Contain wide desktop content with horizontal scrolling in the shell instead of overflowing the document root.
2. Use shared `JalaliDate::format` for materials/procurement header dates, including Persian digits. A guarded regression reproduced the defect before the fix.
3. Read ITP language from request input, including the hidden POST field, rather than only query parameters. The strengthened registration test failed with an EN redirect before the fix and passed afterward; the live browser workflow also passed.

## Synthetic mutations retained

The first business run completed writes before exposing the ITP redirect-language defect. The corrected run then completed successfully. Both runs' clearly marked acceptance data remain: two synthetic DWG documents, two registered draft ITPs, two import-history entries and one quarantined fixture line. No ITP was published/activated and no imported line was accepted. Do not rerun the write script casually or reset the database to hide these records. `EPCC_ALLOW_DEMO_WRITES=yes` is required by the workflow runner.

## Demo access and operation

Use the live application preview on port 8000. Synthetic owner: `karimi@nioc.example`; synthetic contractor: `rezaei@pars.example`; demo-only password: `password`. Do not reuse these accounts/credentials for production. The public account chooser remains disabled by default. Runtime debug is disabled without rewriting the private `.env`.

Read-only runner: `scripts/browser-acceptance.cjs`. It accepts `EPCC_BROWSER_MODULE`, `CHROMIUM_PATH`, `EPCC_BASE_URL`, `EPCC_ACCEPTANCE_OUTPUT`, `EPCC_DEMO_PASSWORD`, and optional `EPCC_EXTRA_ROUTES_JSON` containing `[name, route]` pairs. Acceptance tooling is externally installed, not a new production dependency. Never run PHPUnit against the demo database; the existing test guard and isolated test storage must remain in place.

## Recommended next scope (not implemented here)

1. B16.9 PHP reader plus reference-aware PMS/piping integration, explicit unavailable-data results, no guessed dimensions and no reducing/two-size expansion yet.
2. Source-preserving PMS entry/review with distinct incomplete, conflicting and unreviewed states.
3. ITP → daily inspection/NCR workflow under a separately agreed scope.

Production hardening, ADR-012 performance work and independent standards verification remain open. This phase is practical demo acceptance, not a full security audit or engineering certification.
