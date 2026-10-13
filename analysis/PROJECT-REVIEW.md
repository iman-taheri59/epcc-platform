# EPCC Platform — continuation readiness review

Date: 2026-09-28
Scope: source-level reconnaissance and available non-destructive checks. Not a complete security audit, engineering certification, or runtime acceptance test.

## 1. Executive summary

The uploaded tree is a substantial Laravel application, not only a UI prototype. Domain services and migrations cover documents, procurement/material movements, piping models, equipment, engineering baselines, inspection plans and engineering reference data. Source and historical notes extend through 2026-09-25, beyond the 2026-09-24 HANDOFF header.

The next session should establish a reproducible runtime/test baseline, address the documented baseline RLS defect, and reconcile the backlog with the code before implementing another broad module. No application source, settings, or database was modified during this review.

## 2. Architecture map

- `app/`: Laravel application; composer.lock pins Laravel v13.31.0, Filament v4.13.1 and Octane v2.19.1.
- Declared operating stack: PHP 8.4 and PostgreSQL 17. These runtimes are not installed in the current sandbox.
- UI: server-rendered Blade screens plus Filament administration. `prototype/` is separate and must not be confused with the production application.
- `app/app/Services/`: domain/workflow rules.
- `app/app/Support/`: authorization, tenancy, state machines, numbering, units and reference lookup.
- `packages/standards-engine/`: framework-independent PHP standards engine.
- `standards-packs/`: three project packs and sixteen engineering reference packs with Python validation.
- `docs/`: requirements, architecture and ADRs. `HANDOFF.md` is the most detailed historical record, but not uniformly current.
- `/home/user/workspace-support/`: uploaded workspace helpers, isolated from the application. They have not been activated.

Inventory: 58 model files, 28 service files, 16 controller files, 48 migrations, 69 Feature test files, one Unit test file, three browser harness files. File counts are not test counts or coverage percentages.

## 3. Continuation constraints

These are recorded project decisions, not decisions newly taken in this review:

- Iran-first, on-premise/air-gap, Persian default, bilingual FA/EN, Jalali dates and IRR.
- Keep requirements documentation in English; communicate with the user in Persian.
- AWP is deferred, but its standards pack remains a generality guard.
- Authorization order: authentication, entitlement, membership, RBAC, ABAC, segregation of duties.
- RLS requires a genuine PostgreSQL database, a non-bypass application role, FORCE RLS and transaction-local tenant settings. SQLite cannot establish isolation correctness.
- Commercial project participation and personal project membership are distinct facts.
- Archive is not delete: completed projects retain readable contractual evidence.
- Engineering identifiers must follow the latest recorded decisions; do not reintroduce withdrawn automatic line/equipment numbering based on older sections.
- PMS body rows use component type × qualifier × size, not the earlier size-band design.
- Specialty items are NOT part of the PMS body. Their project register survives independently.
- Manual valves belong in PMS; actuated equipment follows the datasheet distinction documented in the handoff.
- Record document values and report discrepancies; do not silently rewrite source engineering facts to fit reference tables.
- Distinguish a missing/unverified input from a successful engineering check. Retain refusal paths.

## 4. Implemented source areas

Presence confirmed through services, routes, migrations and selected implementation reading; end-to-end behavior has NOT been reverified here.

| Area | Present source | Remaining qualification |
|---|---|---|
| Core/access | AuthorizationPipeline, TenantContext, BindTenant, project setup/archive | Runtime isolation and access regression still required |
| Documents | Register, files, revisions, review, transmittals, ZIP/PDF services | PDF needs Chromium/fonts and runtime verification |
| QA/completion | Punch lifecycle, MC certificate service | Do not infer that RFSU/PAC/FAC are implemented |
| Procurement/materials | ProcurementService, WarehouseService, routes for movements and forecasts | Not evidence of a complete tender/RFQ purchasing suite |
| Piping/equipment | Lines, components, ports/joints services, equipment, PCF import | Import and cross-company tests need PostgreSQL |
| Baselines | MTO/model baseline and discrepancy services/routes | Documented RLS defect remains a blocker |
| Inspection/standards | ItpService, StandardsLibrary and associated screens/routes | Broader NCR/CAPA and inspection reporting require separate scope |
| PMS | Header/body/rules/compliance, manual valve services and migrations | No dedicated PMS editing route identified in web.php; backend presence is not UI delivery |
| Engineering references | Flange/valve ratings, bolting, dimensions, fluid service, stress, wall thickness | Structural validation is not independent source-standard verification |

## 5. Priority findings

### P0 — Baseline/model-import RLS defect still represented in source

Reference: `docs/DEFECT-001-Baseline-RLS-Binds-To-The-Outer-Table.md`.

The migrations `2026_09_18_220000_create_model_imports.php` and `2026_09_19_090000_create_engineering_baseline.php` still contain the unqualified `company_id` reference inside a `project_memberships` subquery. No later corrective migration was identified in the supplied migration set.

Affected tables: baseline_batches, baseline_discrepancies, engineering_items, model_imports, model_import_diffs. The documented SQL name-resolution issue binds the absent inner column to the outer table, asking the wrong participation question. The defect record describes both over-restriction and stale access; actual effects must be reproduced under the current PostgreSQL policies and nested identity policies before claiming a demonstrated exploit.

Fix workflow: first write failing mirrored visibility tests for two participant companies, a removed company, nonparticipants and archived-project writes; then add a forward migration, reuse the established participation/writeability helpers and run the full tenancy suite. Do not equate read permission with write permission.

### P0 — Restore script is destructive

`restore.sh` invokes `php artisan migrate:fresh --force --seed`, followed by a separate ProcurementSeeder. Running it against a database containing useful data destroys and rebuilds that database. Its "idempotent, safe to re-run" comment must not be treated as a data-preservation guarantee.

Before using it: verify the target environment/database, back up any existing data, and split dependency/runtime bootstrap from an explicitly approved demo reset. It also assumes particular PHP/PostgreSQL packaging and `/home/user` jsdom placement.

### P1 — No verified application runtime baseline in this environment

PHP, Composer and psql are absent; app/vendor and node_modules are absent. An app/.env file exists and was not printed, changed or copied into this report. No Laravel tests, PHP conformance tests, jsdom harnesses, migrations, or live database inspections were run.

### P1 — Backlog and test claims are stale and contradictory

Examples: early HANDOFF text lists PDF and procurement as absent despite later implementations; TODO still lists equipment/piping work as pending; the HANDOFF header test total predates later focused suites. Its suggested next steps also include work subsequently marked complete.

Do not quote 40–45% coverage as a measured current figure. Do not add focused test counts together. Build a current requirement → service → endpoint/UI → test matrix and obtain a fresh full-suite result including skips.

### P1 — Performance change requires a separate decision and regression scope

HANDOFF §5o and ADR-012 record an unresolved RLS planner problem with optional project scope. The proposed change moves some same-company project scoping from database policy into queries. Historical benchmark figures have not been reproduced here. Treat this as an explicit architecture/security change, not a casual index fix, and preserve cross-project portfolio behavior.

### P1 — Engineering evidence package is incomplete in the transfer

`standards-source/` and its cited parsing/build tools are absent. JSON reference packs are present. Source standards therefore cannot be independently rechecked or rebuilt from the uploaded files alone. `docs/uml/`, also mentioned in documentation, is absent.

AllowableStress currently explicitly limits citable entries to verified data (the source reports 77 of 798); held entries must not be unlocked simply because schema validation passes. Safety-critical engineering decisions require qualified review of source clauses, assumptions and applicability, not just test success.

### P2 — Aggregate test runner can overstate success

`prototype/test/run-all.sh` prints SKIP for absent PHP, vendor or jsdom yet may still print ALL SUITES PASSED if the remaining commands succeed. CI should report missing required suites as incomplete or fail them, while distinguishing optional checks. The prototype render test uses a minimal DOM stub, not a real browser layout engine.

## 6. Checks actually executed

1. `python3 standards-packs/validate_packs.py` — exit 0; all 3 project standards packs and 16 reference packs valid; AWP seams intact. Output retained in `standards-validation.txt`.
2. `node prototype/test/render.test.js` — exit 0; 324 combinations (3 packs × 2 languages × 6 roles × 9 pages), zero reported problems. Output retained in `prototype-render.txt`.

These checks do not prove current Laravel behavior, RLS isolation, browser layout/RTL, PDF output or normative correctness of engineering tables.

## 7. Proposed order for the next working session

1. Safely provision runtime/dependencies without blindly running restore.sh. Confirm database role and isolated test database.
2. Run full Laravel/PHP/JS checks and capture failures, skips and environment versions; inspect actual PostgreSQL policies.
3. Reproduce and repair DEFECT-001 with regression tests and a forward migration.
4. Reconcile TODO/HANDOFF into a single current backlog; keep prior decisions and supersession history visible.
5. Confirm the user's desired next feature. Plausible continuations are PMS transcription UI and NDT/ITP integration, or NCR and daily inspection reports. These are options, not a newly approved scope.
6. For each change: focused tests, mutation/negative tests where relevant, full regression, real-browser FA/EN desktop/mobile checks and updated handoff.

No new feature development, runtime installation, schema reset or data mutation was performed in this review.
