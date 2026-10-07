# Chat Transfer Handoff — EPCC Platform

**Date:** 2026-10-06  
**Purpose:** Transfer the full working state of this chat to another chat/agent so work can continue from the latest point.  
**Repository:** `/home/user/epcc-platform`  
**Branch:** `arena/roadmap-reconciliation`  
**Latest pushed commit:** `d141f329603ed5b12aa3a9c667e8c08e3ace6f8b` (`d141f32 app: add MTO staging foundation`)

> Persian counterpart: `CHAT-TRANSFER-HANDOFF-FA.md`

---

## 1. Starting instruction for the next chat

If you paste this into the next chat, use this as the initial context:

```text
Please continue work on `/home/user/epcc-platform`, branch `arena/roadmap-reconciliation`. Read `CHAT-TRANSFER-HANDOFF-FA.md` / `CHAT-TRANSFER-HANDOFF-EN.md`, then `HANDOFF.md`, `README.md`, `docs/TODO.md`, `docs/MTO-REQUIREMENTS-FA.md`, and `docs/MTO-REQUIREMENTS-EN.md`. The last pushed commit is `d141f329603ed5b12aa3a9c667e8c08e3ace6f8b` (`app: add MTO staging foundation`). Continue from Slice 4: accepted immutable MTO snapshots (`accepted_mto_batches`, `accepted_mto_rows`) with all-or-nothing acceptance and rollback tests. Do not implement MTO permissions yet.
```

---

## 2. Current repository state

- Repository path: `/home/user/epcc-platform`
- Active branch: `arena/roadmap-reconciliation`
- Latest confirmed local/remote commit:

```text
local:  d141f329603ed5b12aa3a9c667e8c08e3ace6f8b
remote: d141f329603ed5b12aa3a9c667e8c08e3ace6f8b
```

- PR URL:

```text
https://github.com/iman-taheri59/epcc-platform/pull/new/arena/roadmap-reconciliation
```

- If the remote is missing in the next environment, add it without storing credentials:

```bash
cd /home/user/epcc-platform
git remote add origin https://github.com/iman-taheri59/epcc-platform.git
```

- Do not store any PAT in project files, remote URLs, or git config.

---

## 3. Important user constraints

1. Every new document must have a matching English counterpart.
2. All EPCC disciplines remain in product scope, but **Piping** is the first deep/template discipline.
3. Test and preview data must be synthetic/fake but realistic.
4. The current UI is considered cluttered; keep the direction task-first and less noisy.
5. The product should first satisfy the Iranian market: on-prem/air-gap, Persian, Jalali, Iranian customer needs; international comes later.
6. The uploaded docs package must not be blindly overwritten into the repo; it must be analyzed and manually merged.
7. MTO permission/access design is deferred; do not implement new MTO roles/access gates unless explicitly approved later.
8. Temporary PATs should only be used for Git operations via temporary `GIT_ASKPASS`; never store them. Revoke them after the session if possible.

---

## 4. Uploaded package and analysis result

The user uploaded:

```text
/home/user/uploads/epcc-platform-mto-docs-changes-2026-10-06.txt
```

It was actually a ZIP file renamed with a `.txt` extension and was extracted to:

```text
/home/user/req-update-2026-10-06/epcc-platform-mto-docs-changes-2026-10-06/
```

Analysis result:

- The package is docs-only and contains complete replacement files, not diffs/patches.
- Direct overwrite is unsafe because its baseline differs from the current branch.
- Some features were described as `BUILT` in the package but did not exist in the current branch, including:
  - `BaselineService`
  - `baseline_batches`, `baseline_discrepancies`
  - `PcfParser`, `ModelImportService`, `model_imports`
  - immutable accepted MTO rows before the current work
  - PMS services and NDT models mentioned in the package
- Reconciled requirements are recorded in:
  - `docs/MTO-REQUIREMENTS-FA.md`
  - `docs/MTO-REQUIREMENTS-EN.md`

---

## 5. Accepted MTO domain principles

1. Approved project MTO = gross procurement-planning quantity.
2. Model/isometric estimate = net engineering need.
3. If `net > gross`, the positive difference is a shortage; percentage tolerance must not suppress it.
4. If `gross >= net`, show the delta only; do not infer cause/waste/contingency.
5. Order gap is separate from physical usable-material gap.
6. `mto_item_no` alone is not globally unique and must not be a downstream key.
7. Accepted MTO row must be an immutable snapshot.
8. Acceptance must be all-or-nothing per MTO document/sub-MTO revision.
9. Aggregating multiple MTOs is allowed only with disjoint scope or exact immutable pair-level additivity evidence.
10. Scope must be stable/versioned and frozen in snapshots.
11. MTO access-control design is deferred.

---

## 6. Work completed and pushed in this chat

### 6.1 MTO Foundation documentation

Commit:

```text
e54b499 docs: reconcile MTO foundation requirements
```

Main files:

```text
docs/MTO-REQUIREMENTS-FA.md
docs/MTO-REQUIREMENTS-EN.md
docs/TODO.md
HANDOFF.md
README.md
```

Content:

- analyzed the uploaded package;
- recorded why direct overwrite is unsafe;
- staged the MTO Foundation roadmap;
- recorded gross/net, accepted-row identity, all-or-nothing acceptance, order gap and usable-gap principles.

### 6.2 Slice 2 — Material Library Foundation

Commit:

```text
65880b2 app: add material catalogue foundation
```

Created files:

```text
app/database/migrations/2026_10_06_100000_create_material_catalogue.php
app/app/Models/MaterialItem.php
app/app/Services/MaterialCatalogueService.php
app/database/seeders/MaterialCatalogueSeeder.php
app/tests/Feature/MaterialLibraryTest.php
```

Also changed:

```text
app/database/seeders/DatabaseSeeder.php
```

Capabilities:

- `material_items` table;
- two-level catalogue shape:
  - `project_id IS NULL`: global/reference rows;
  - `project_id IS NOT NULL`: project overlay rows;
- project overlay is the current writable path for the Iranian-market use case;
- exact commodity lookup;
- alias/vendor code is only a lookup hint, not identity;
- controlled category/source/status/base_unit;
- bilingual description required;
- RLS based on project participation;
- synthetic PH-14 catalogue with codes:
  - `PIP-CS-6-SCH40`
  - `VLV-GT-6-150`
  - `GSK-SPW-6-150-SS-GR`

### 6.3 Slice 3 — MTO Document / Revision / Staging

Commit:

```text
d141f32 app: add MTO staging foundation
```

Created files:

```text
app/database/migrations/2026_10_06_110000_create_mto_staging.php
app/app/Models/MtoDocument.php
app/app/Models/MtoRevision.php
app/app/Models/MtoStagingRow.php
app/app/Services/MtoStagingService.php
app/tests/Feature/MtoStagingTest.php
```

Capabilities:

- tables:
  - `mto_documents`
  - `mto_revisions`
  - `mto_staging_rows`
- versioned MTO document header;
- revision starts as `DRAFT`;
- staging rows are editable and not accepted yet;
- exact material resolution from `material_items`;
- raw text is preserved;
- alias is not accepted as identity;
- validation states:
  - `ACCEPTABLE`
  - `BLOCKED_UNMATCHED_MATERIAL`
  - `BLOCKED_UNIT_INCOMPATIBLE`
  - `BLOCKED_DUPLICATE_IDENTITY`
  - `BLOCKED_QUANTITY_INVALID`
- RLS for project participants.

---

## 7. Validations already run

### After Material Library Foundation

```bash
bash restore.sh
php artisan test --filter MaterialLibraryTest
php artisan test --filter SeedPathTest
python3 standards-packs/validate_packs.py
```

Results:

```text
MaterialLibraryTest: 6 passed / 15 assertions
SeedPathTest: 2 passed / 6 assertions
standards validator: all 3 standards packs + 5 reference packs valid
```

### After MTO Staging Foundation

```bash
bash restore.sh
php artisan test --filter MtoStagingTest
php artisan test --filter MaterialLibraryTest
php artisan test --filter SeedPathTest
python3 standards-packs/validate_packs.py
```

Results:

```text
MtoStagingTest: 8 passed / 21 assertions
MaterialLibraryTest: 6 passed / 15 assertions
SeedPathTest: 2 passed / 6 assertions
standards validator: all 3 standards packs + 5 reference packs valid
```

The full Laravel suite was once started after Material Library and showed no failure before sandbox timeout, but it did not complete. Earlier, on commit `f33c9a7`, the full suite completed with `858 passed / 3166 assertions`. After Slice 2 and Slice 3, only targeted tests plus restore/validator were run.

---

## 8. Operational notes and known traps

1. PHP/Composer/PostgreSQL may not exist after a sandbox snapshot. First run:

```bash
cd /home/user/epcc-platform
bash restore.sh
```

2. Do not run Laravel tests in parallel. Parallel runs race on PostgreSQL `epcc_test` schema reset.

3. If a long full suite times out, find and kill remaining processes:

```bash
ps -ef | grep -E 'php|artisan|phpunit' | grep -v grep
kill <pid>
```

4. If the test database is corrupted after timeout:

```bash
cd /home/user/epcc-platform/app
PGPASSWORD=$(grep '^DB_PASSWORD=' .env | cut -d= -f2-) \
  psql -h 127.0.0.1 -U epcc -d epcc_test \
  -c 'DROP SCHEMA public CASCADE; CREATE SCHEMA public;'
```

5. `mutate.sh` may sometimes show a mode change because of tooling. Before committing:

```bash
chmod +x mutate.sh
git status --short
```

---

## 9. Build/runtime state

- Runtime is rebuildable with `restore.sh`.
- `restore.sh` creates/updates PostgreSQL `.env`, generates the app key, migrates/seeds, and publishes assets.
- Local `app/.env` must not be committed.
- PHP/Composer/vendor/cache are not guaranteed to persist across workspace snapshots; run `bash restore.sh` if needed.

---

## 10. Not built yet

The following are not built and must not be demoed/documented as built:

```text
accepted_mto_batches
accepted_mto_rows
all-or-nothing MTO acceptance
immutable accepted MTO snapshots
gross MTO vs net engineering estimate comparison
engineering_estimate_rows
PO attribution/order gap
usable material definition and physical gap
MTO access-control/permissions design
MTO UI/workspace
CSV/Excel import UI
PCF/model import
BaselineService / Baseline batches
PMS services from uploaded package
```

---

## 11. Exact next step

### Slice 4 — Accepted immutable MTO snapshots

Build:

```text
accepted_mto_batches
accepted_mto_rows
MtoAcceptanceService
MtoAcceptanceTest
```

Recommended behavior:

1. Only a revision in `DRAFT` or possibly `UNDER_REVIEW` may be accepted; for MVP, prefer service-level acceptance from `DRAFT` only unless a state transition is added.
2. A revision with zero rows must not be accepted.
3. If any `mto_staging_rows.validation_state != ACCEPTABLE`, the whole acceptance fails and no snapshot rows are created.
4. Acceptance must run inside a DB transaction.
5. Accepted rows are immutable; the service should not provide update/delete paths.
6. Snapshot must freeze:
   - project_id
   - source mto_document_id
   - source mto_revision_id
   - accepted_batch_id
   - mto_item_no
   - material_item_id
   - gross_quantity
   - unit
   - scope_id nullable
   - hierarchy_revision_id nullable
   - piping_class_id nullable
   - frozen raw description / material labels / commodity code
   - source row hash
   - accepted_at
7. After acceptance, the revision state can become `ACCEPTED`.
8. Later downstream records must link to `accepted_mto_row_id`, not `mto_item_no` alone.

Required tests:

- accepts all acceptable rows atomically;
- refuses when any row is blocked;
- refusal creates zero accepted rows;
- duplicate or blocked staging rows cannot be accepted;
- accepted rows preserve frozen source values even if the material item later changes;
- non-participant cannot see accepted rows;
- accepted row identity is a UUID and independent of `mto_item_no`.

---

## 12. Key files to read first

In order:

```text
CHAT-TRANSFER-HANDOFF-FA.md
CHAT-TRANSFER-HANDOFF-EN.md
HANDOFF.md
README.md
docs/TODO.md
docs/MTO-REQUIREMENTS-FA.md
docs/MTO-REQUIREMENTS-EN.md
app/app/Services/MaterialCatalogueService.php
app/app/Services/MtoStagingService.php
app/tests/Feature/MaterialLibraryTest.php
app/tests/Feature/MtoStagingTest.php
```

---

## 13. Expected state before continuing

After committing/pushing this handoff, this should be clean:

```bash
cd /home/user/epcc-platform
git status --short
```

The latest commit should be the handoff commit if committed, otherwise at least `d141f32`. Check with:

```bash
git log --oneline -5
```
