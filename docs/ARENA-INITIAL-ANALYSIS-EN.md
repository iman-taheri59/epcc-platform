# Initial Analysis Report for `epcc-platform`

Analysis date: 2026-10-05

## Access and Synchronisation Status

- The repository was cloned successfully: `github.com/iman-taheri59/epcc-platform`
- Current branch at analysis time: `main`
- Local and remote `main` were in sync: `61093dd3f9a8e146161e288e2857c9dc372e6b4d`
- The current workspace footprint was about 15 MB; heavy Laravel/Node dependencies were not present in the repository.

> Security note: the temporary access token was not stored in project files or in the git remote. Revoke the temporary PAT after the work session is finished.

---

## Executive Summary

The project is far beyond a plain Laravel scaffold and already contains several serious vertical slices. The current implementation mainly focuses on:

1. Multi-tenancy with PostgreSQL Row Level Security
2. Authentication and the six-gate authorization pipeline
3. Bilingual Persian/English UX, real RTL, and Jalali dates
4. CDE / document register / review / transmittals
5. Punch list and Mechanical Completion
6. A limited Filament admin panel
7. Procurement/materials at the basic chain level
8. Physical breakdown: facility / area / subsystem
9. Piping: line register, numbering, evidence, units, components/ports, joints, and dia-inch
10. Standards packs and engineering reference packs

According to `HANDOFF.md`, the last documented full-suite status is **852 passed / 3136 assertions** plus jsdom tests. PHP/Composer/PostgreSQL are not installed in the current sandbox, so the Laravel test suite could not be executed here. The checks that are executable in the current sandbox did pass.

---

## Checks Run in This Environment

### 1. Standards packs and reference packs validation

Command:

```bash
python3 standards-packs/validate_packs.py
```

Result:

- All 3 standards packs are valid: `iso`, `awp`, `ips-iran`
- All 5 reference packs are valid: pipe sizes, schedules, end types, component templates, units
- AWP seams required by ADR-002 are intact
- Current reference data includes: 36 sizes, 355 size/schedule pairs, 13 end types, 20 component templates with 41 port definitions, 6 quantities, and 29 units

### 2. Prototype sidebar jsdom test

Command:

```bash
npm ci --no-audit --no-fund
node prototype/test/sidebar.test.js
```

Result: **19 passed, 0 failed**

### 3. Repository status

- `git status` was clean before documentation changes.
- `origin` is registered without a token.
- Local and remote branch heads were verified as matching.

---

## Current Technical Structure

### Laravel app

Main path: `app/`

- Laravel 13.x
- Filament v4
- PHP requirement in Composer: `^8.3`; project documents target PHP 8.4
- PostgreSQL/RLS is a core architectural dependency
- Internal package: `epcc/standards-engine` as a path repository

### Important component counts

- Migrations: 27
- Models: 35
- Controllers: 12
- Filament resource-related files: 17
- Tests: roughly 48 PHP Feature files plus Browser/jsdom tests

### Main implemented routes

- `/login`
- `/` portfolio
- `/projects/new`
- `/projects/{code}`
- `/projects/{code}/documents`
- `/projects/{code}/transmittals`
- `/projects/{code}/vendor-transmittals`
- `/projects/{code}/piping`
- `/projects/{code}/piping/config`
- `/projects/{code}/piping/{line}/model`
- `/projects/{code}/punch`
- `/projects/{code}/materials`
- `/projects/{code}/procurement`
- `/projects/{code}/team`
- `/admin` through Filament

---

## Critical Architecture Rules Not to Break

Extracted from `HANDOFF.md` and the code structure:

1. **RLS is the primary isolation boundary** and must not be replaced by ad-hoc `where company_id = ...` filtering.
2. `SET LOCAL` is only valid inside an open transaction; plain `SET` risks tenant leakage.
3. `FORCE ROW LEVEL SECURITY` must remain enabled where required.
4. `NULLIF(GUC,'')` is required when reading tenant GUCs.
5. RLS tests on SQLite are meaningless; PostgreSQL must be used.
6. Middleware order is load-bearing: `auth` before `tenant`.
7. The sidebar is an authorization surface; `planned` and `locked` must not be conflated.
8. Every new screen must follow the shell contract: app → sidebar → wrap → topbar → content.
9. Air-gapped features must not depend on CDNs, external fonts, remote avatars, or network-only assets.
10. Contractual/audit evidence must be snapshotted and must not be recomputed live, except for deliberate decision drafts.

---

## Module Status

### Relatively implemented

- CORE/Auth/Tenancy/RLS
- Project setup, portfolio, archive/reopen
- Document register / CDE
- Document files
- Review comments
- Transmittals and vendor transmittals
- Punch list and MC certificate
- PDF infrastructure and some PDF outputs
- Procurement/materials at the basic chain level
- Physical breakdown: facilities/areas/subsystems
- Piping phases 1 through 3: lines, config, units, components/ports, joints, dia-inch
- Limited Filament admin panel

### Unfinished or partial gaps

- RFSU/PAC/FAC certificates
- PDF for payment certificates and punch register
- Bulk import for systems/subsystems
- Punch photos connected to `DocumentFileStore`
- RFQ/tendering and procurement PDFs such as GRN/MIV
- HSE module
- Schedule module
- Full project management: WBS/CBS/OBS/PBS, risk, issue, action, MOM, lessons learned
- Engineering import: Excel/PCF/Navisworks property export
- PMS/piping class rules and branch table
- Equipment/nozzles
- Full-text/OCR
- Offline field app
- BIM/CAD viewer
- P6/ERP/LDAP integrations
- Installer/offline bundle/signature
- Notification engine
- Workflow/BPM
- Iranian taxpayer system / Shaparak integrations

---

## Documentation Note

Before reconciliation, `docs/TODO.md` had drifted behind the actual code and `HANDOFF.md`. For example, parts of it still described Procurement as having zero pages, while the code and `HANDOFF.md` show a basic procurement/materials chain. `HANDOFF.md` should be treated as the more authoritative technical status document until all derived documents are reconciled.

---

## Recommended Next Steps

### Priority 0 — Prepare a real runtime/test environment

The current sandbox lacks PHP/Composer/PostgreSQL. Safe Laravel development requires one of:

1. An environment with PHP/Composer/PostgreSQL already available; or
2. A Docker/devcontainer setup; or
3. Temporarily focusing only on documentation, JS/Python checks, and static code work until PHP/PostgreSQL are available.

DB/RLS changes are high risk without PHPUnit and migration execution.

### Priority 1 — Reconcile status documentation

Before building major new functionality, `docs/TODO.md` should be reconciled with the current code and `HANDOFF.md`:

- Mark Procurement as partially built / basic-chain done
- Mark ADR-006 phases 1/1b/2/3 as built
- Separate the real backlog from stale items
- Produce an executable roadmap

This is low risk and does not require PHP.

### Priority 2 — Small high-value PDFs

Once PHP is available, the smallest product-value items are:

- Payment certificate PDF
- Punch register PDF
- GRN/MIV PDFs for procurement/materials

The PDF infrastructure already exists, making these bounded and demo-friendly.

### Priority 3 — RFSU/PAC/FAC

This completes the commissioning certificate chain. According to the documentation, `blocksReadyForStartUp()` already exists and is tested but is not yet used in the UI/flow.

### Priority 4 — PMS / piping class rules

This is high value for piping data quality, but it involves PostgreSQL-heavy migrations and RLS tests, so it should wait until the full test environment is available.

### Priority 5 — Engineering import phase 1

Excel/PCF/property export import has high commercial value, but real customer sample files should be collected before implementation.

---

## Suggested Immediate Next Step

The safest useful next step is:

1. Reconcile `docs/TODO.md` with `HANDOFF.md` and the current code.
2. Add `docs/ROADMAP-TO-MVP.md`.
3. Commit those changes on a separate branch such as `arena/roadmap-reconciliation`.

After a real PHP/Composer/PostgreSQL or Docker environment is available, move to product changes.
