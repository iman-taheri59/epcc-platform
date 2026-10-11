# Development Environment on Windows — Laragon (no Docker required)

**Date:** 2026-10-11 · Companion to `docs/DEVELOPMENT-ENVIRONMENT.md` (the
Docker tier) and `REBUILD.md` (Linux/sandbox tier). Docker is a packaging
convenience, **not** a Laravel requirement: the app runs on any host with
PHP 8.4 + Composer + PostgreSQL. This document is the step-by-step for the
product owner's actual workstation (Windows + Laragon) and mirrors
`restore.sh` exactly.

---

## 1. What you need

| Piece | Version | Notes |
|---|---|---|
| PHP | **8.4** (the lock file requires ≥ 8.4.1) | Laragon lets you add versions side by side |
| Composer | 2.x | bundled with Laragon |
| PostgreSQL | 16 or 17 (17 is the reference) | 16 is proven to run the whole schema |
| Node | 22, only for the jsdom prototype suites | optional for running the app |
| Chromium + poppler + Vazirmatn | only for PDF export | optional — see §7 |

## 2. PHP 8.4 in Laragon

1. Download the **PHP 8.4 x64 Thread Safe** zip from `windows.php.net/download`.
2. Extract it into `C:\laragon\bin\php\` (e.g. `…\php-8.4.x-Win32-vs17-x64`).
3. Laragon tray → **Menu → PHP → Version** → select the new 8.4 folder.
4. Open its `php.ini` and **uncomment** at least:

   ```ini
   extension=pdo_pgsql
   extension=pgsql
   extension=intl
   extension=zip
   extension=gd
   extension=exif
   ```

   and mirror the upload limits `restore.sh` enforces (the stock 2 MB cap
   rejects real deliverables **silently** — an invalid `UploadedFile`, no
   exception):

   ```ini
   upload_max_filesize = 100M
   post_max_size = 110M
   memory_limit = 512M
   max_file_uploads = 30
   max_execution_time = 300
   ```

5. Verify in Laragon's terminal: `php -v` (must print 8.4) and
   `php -m | findstr pgsql` (must list `pdo_pgsql` and `pgsql`).

## 3. PostgreSQL — and the one rule you must not break

Laragon full ships PostgreSQL; otherwise add a portable build under
`C:\laragon\bin\postgresql\` (or any installer). Then, as the `postgres`
admin:

```sql
CREATE ROLE epcc LOGIN PASSWORD 'epcc_dev' NOSUPERUSER NOBYPASSRLS;
CREATE DATABASE epcc OWNER epcc;
CREATE DATABASE epcc_test OWNER epcc;
GRANT ALL ON SCHEMA public TO epcc;          -- run in BOTH databases
CREATE EXTENSION IF NOT EXISTS btree_gist;   -- run in BOTH databases
```

**The application role must NOT be a superuser.** A superuser bypasses
row-level security even under `FORCE ROW LEVEL SECURITY`, so the tenancy
tests (`SeedPathTest` fail-closed assertion first) go red — see the CI
comment in `.github/workflows/ci.yml` and HANDOFF §4. Do not "simplify"
this by logging the app in as `postgres`.

`btree_gist` is not required by any current migration; it is the first thing
piping phase P1 (ADR-008) needs, and creating it later requires admin
rights, so create it now.

## 4. The application

From the repository root, in Laragon's terminal (it sets PATH for you):

```bat
cd app
composer install
copy .env.example .env
```

Edit `app/.env` — the skeleton defaults to **SQLite, which is forbidden**
for this product (no RLS on SQLite; a passing test there proves nothing):

```ini
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=epcc
DB_USERNAME=epcc
DB_PASSWORD=epcc_dev
```

Then the same sequence `restore.sh` runs:

```bat
php artisan key:generate --force
php artisan migrate:fresh --force --seed
php artisan db:seed --class=ProcurementSeeder --force
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan filament:assets
```

`filament:assets` is easy to miss: without it `/admin` returns **200 with
unstyled HTML and no JS** — nothing fails, it just looks broken.

## 5. Run it

```bat
cd app
php artisan serve --host=127.0.0.1 --port=8000
```

or point a Laragon virtual host at `app/public` and use the `.test` domain.
Sign in with the seeded demo accounts (REBUILD.md §0a-3):
`karimi@nioc.example` / `password` (employer). The login screen lists the
others and fills the form on click.

## 6. Running the tests

```bat
cd app
php artisan test
```

Runs against `epcc_test`. Two standing rules: never run the DB suites in
**parallel** against the same `epcc_test` database (migration/drop-schema
races), and never retarget them at SQLite. The jsdom prototype suites run
from the repo root exactly as everywhere else (`npm install` once, then
`bash scripts/run-tests.sh` from Git-Bash, or the individual
`node prototype/test/*.test.js` files from cmd).

## 7. PDF export on Windows — known limitation (P-20)

`App\Services\PdfRenderer` locates the browser with the POSIX
`command -v`, so on Windows `isAvailable()` returns false and PDF export
degrades gracefully (everything else works). It is not a blocker for
development; making the renderer Windows-aware (add `chrome`/`chrome.exe`
candidates + `where` lookup, Vazirmatn install, poppler for the raster
self-check) is tracked as proposal **P-20** in
`docs/IMPROVEMENT-PROPOSALS-EN.md`.

## 8. Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `could not find driver` | `pdo_pgsql` not enabled in the selected PHP version's php.ini (§2) |
| `/admin` unstyled | `php artisan filament:assets` not run (§4) |
| Tenancy tests failing "expected empty" | the `epcc` role was created as superuser — recreate per §3 |
| `password authentication failed` | `.env` DB block not saved, or the server's `pg_hba.conf` does not allow md5/scram from 127.0.0.1 |
| Uploads fail silently | the php.ini limits from §2 not applied — failure mode is a blank `UploadedFile`, no exception |
| Persian renders as boxes in a PDF | Vazirmatn missing on the host (IR-R2); PDF tier not set up (§7) |
