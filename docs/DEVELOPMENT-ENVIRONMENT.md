# Development environment

Everything needed to run the EPCC Platform locally, in containers, with one
command. This is priority 0 in `docs/TODO.md` §4 («محیط اجرای کامل
Laravel/PostgreSQL»): until migrations, RLS and PHPUnit actually run, a database
or access-control change cannot be trusted.

Two environments are supported and they are kept in step deliberately:

| | What it is | Entry point |
|---|---|---|
| **Containers** (this document) | PostgreSQL 17 + PHP 8.4 + Composer + Node 22 + headless Chromium, reproducible on any machine with Docker | `scripts/dev-up.sh` |
| **Bare sandbox** | The same stack installed directly onto a Debian/Ubuntu host, rebuilt after every sandbox wipe | `restore.sh` / `rebuild-env.sh`, see `REBUILD.md` |

If you change a credential, a seeder, a port or a package in one, change it in
the other. They are the same environment expressed twice, and the failure mode
of letting them drift is a green suite that proves less than it claims.

---

## 1. Prerequisites

* Docker Engine 24+ with the Compose v2 plugin (`docker compose version`), or
  Docker Desktop.
* ~4 GB of disk for the image (Chromium is most of it) and ~2 GB for the
  volumes.
* Ports `8000` and `5433` free on the host. Both are configurable (§7).

Nothing else. **You do not need PHP, Composer, Node, PostgreSQL or Python on
your machine** — that is the point.

## 2. Quick start

```bash
git clone https://github.com/iman-taheri59/epcc-platform.git
cd epcc-platform
scripts/dev-up.sh
```

Then open <http://localhost:8000> and sign in as
**`karimi@nioc.example` / `password`**. The admin panel is at `/admin`.

The first boot takes a few minutes: Composer installs ~16,500 files, npm builds
the Vite assets, and the demo fixture is migrated and seeded. Every later boot
takes seconds, because the dependency trees live in named volumes and the
database in `db-data`. The script waits for the healthcheck before printing the
URL, so a printed URL is a working one.

## 3. What comes up

| Service | Image / build | Host port | Purpose |
|---|---|---|---|
| `db` | `postgres:17` | `127.0.0.1:5433` → 5432 | `epcc` (development) and `epcc_test` (suite) |
| `app` | built from `docker/php/Dockerfile` | `8000` → 8000 | `php artisan serve`, and the shell every script uses |

The `app` image contains PHP 8.4 with `pdo_pgsql pgsql intl bcmath gd zip pcntl
sockets opcache exif mbstring`, Composer 2, Node 22, the PostgreSQL client,
Python 3, headless Chromium, `poppler-utils` and the Vazirmatn font.

Your checkout is bind-mounted at `/srv/epcc`, so **edit on the host and see it
in the container** — no rebuild after a code change. Three things are named
volumes rather than bind mounts: `app/vendor`, `app/node_modules` and the
repository-root `node_modules`. See §6 for why `app/vendor` in particular must
not be shared with the host.

## 4. Everyday commands

```bash
scripts/dev-up.sh                 # start (rebuilds the image if compose.yaml changed)
scripts/dev-up.sh --rebuild       # force a no-cache rebuild
scripts/dev-up.sh --reset         # destroy all data, then start clean
scripts/dev-up.sh --logs          # start, then tail the app log

scripts/dev-test.sh               # every suite, in the order run-all.sh uses
scripts/dev-test.sh laravel       # just PHPUnit
scripts/dev-test.sh laravel -- --filter=PipingLineTest
scripts/dev-test.sh packs         # standards-pack validator
scripts/dev-test.sh engine        # rule-engine conformance corpus
scripts/dev-test.sh prototype     # jsdom harnesses

scripts/dev-seed.sh               # migrate:fresh --seed + ProcurementSeeder
scripts/dev-seed.sh --migrate-only
scripts/dev-seed.sh --without-procurement

scripts/dev-down.sh               # stop, keep all data
scripts/dev-down.sh --volumes     # stop and delete the database + dependency volumes
```

Ad hoc access:

```bash
docker compose exec app bash                       # shell in /srv/epcc
docker compose exec app php artisan tinker
docker compose exec app php artisan migrate:status
docker compose logs -f app
psql -h 127.0.0.1 -p 5433 -U epcc -d epcc          # password epcc_dev
```

## 5. What the first boot actually does

`docker/php/entrypoint.sh`, in order. Each step is conditional, so re-running is
safe.

1. **Drop privileges** to `EPCC_UID`/`EPCC_GID` (set by `dev-up.sh` on Linux), so
   `app/.env`, `storage/logs` and `public/build` are owned by you on the host
   rather than by root.
2. **Wait for PostgreSQL** on the compose network.
3. **`composer install --no-scripts`** if `app/vendor/autoload.php` is missing or
   the `epcc/standards-engine` symlink is broken.
4. **Create `app/.env`** from `.env.example` and write the database connection
   and application settings into it — never into the process environment (§6.1).
5. **`artisan key:generate`** if `APP_KEY` is empty (never overwrites an existing
   key — that would invalidate every signed cookie).
6. **`npm ci` / `npm install`** for the root `jsdom` and for `app/`, then
   **`npm run build`** if `app/public/build/manifest.json` is missing.
7. **Migrate, and seed only if the database is empty.** The emptiness check
   queries `information_schema`, not a model count: almost every table here is
   under RLS with `FORCE ROW LEVEL SECURITY`, so an unbound model count returns
   `0` *by policy* and would re-seed on every boot.
8. **`package:discover`, `filament:assets`, clear caches.** Without
   `filament:assets`, `/admin` renders as unstyled HTML and still returns 200.
9. **Warn** if any part of the PDF toolchain is missing (§8).

Opt out of any of it with `SKIP_COMPOSER=1`, `SKIP_NPM=1`, `SKIP_ASSETS=1`,
`SKIP_MIGRATE=1`, or `SKIP_BOOTSTRAP=1` for all of it:

```bash
SKIP_MIGRATE=1 docker compose up -d
```

## 6. Traps — read before changing anything here

Four decisions in this setup look like simplification opportunities and are not.
Three of them break the suite in a way that *invites you to weaken the suite*
rather than fix the environment, which is what makes them expensive: the symptom
points at the assertion, not at the cause.

### 6.1 No variable Laravel reads may be exported to the container

The rule `compose.yaml` follows: every value the application needs is passed in
as an `EPCC_*` variable that **only the entrypoint reads**, and the entrypoint
writes the real key into `app/.env`. Nothing Laravel resolves by name is ever a
real environment variable.

The reason is a three-way precedence that is easy to get wrong:

* Laravel loads `.env` through an **immutable** Dotenv repository, so a real
  environment variable always wins over the file.
* `app/phpunit.xml` sets its overrides (`APP_ENV=testing`, `DB_DATABASE=epcc_test`,
  `SESSION_DRIVER=array`, …) with PHPUnit's default `force="false"`, which does
  **not** replace a variable that already exists in the environment.

So a real exported variable beats *both* `.env` and the test configuration. Two
concrete failures, both of which stay quiet:

| If you export | What actually happens |
|---|---|
| `DB_DATABASE=epcc` | The whole suite — including every test that calls `migrate:fresh` — runs against the **development** database and wipes the demo fixture. Tests still pass. |
| `APP_ENV=local` | The suite stops running in the `testing` environment `phpunit.xml` asks for; `.env.testing` is never considered and `app()->environment()` assertions change meaning. Tests still mostly pass. |

`EPCC_DB_*`, `EPCC_APP_ENV`, `EPCC_APP_DEBUG`, `EPCC_APP_URL` and
`EPCC_SERVER_WORKERS` are therefore prefixed, and `phpunit.xml` remains the
single source of truth for the test environment.

### 6.2 The application role must not be a superuser

A PostgreSQL superuser bypasses row-level security unconditionally, *even with
`FORCE ROW LEVEL SECURITY`*. So `POSTGRES_USER: epcc` — the obvious shortcut —
would make every fail-closed tenancy assertion read the whole table:
`SeedPathTest`'s "unbound read must be fail-closed", and the leak tests in
`RowLevelSecurityTest`, `IdentityIsolationTest` and `MultiProjectTest`.

`docker/postgres/init/01-epcc-databases.sql` therefore creates the superuser
`postgres` for administration only, plus a plain `LOGIN` role `epcc` that owns
both databases. That is the same shape `restore.sh` builds on a bare sandbox,
and the same reason `2026_09_12_100000_secure_identity_tables.php` can state
that `CREATE ROLE` is denied to `epcc`. The CI workflow asserts
`superuser=f bypassrls=f` rather than trusting the SQL.

### 6.3 Why `app/vendor` is a named volume

On a sandbox host `app/vendor` is a **symlink into `~/.cache`** so that
Composer's 16,500 files do not blow the workspace snapshot cap (`REBUILD.md` §0).
That symlink is meaningless inside a container. Separately, `composer.json`
resolves `epcc/standards-engine` from a path repository with `"symlink": true`,
so the symlink is **absolute** — a `vendor/` built on the host points at a path
that does not exist in the image. The entrypoint detects that and reinstalls.

### 6.4 The runtime is PHP 8.4, even though `composer.json` says `^8.3`

`app/composer.json` declares `"php": "^8.3"`, which reads as though a PHP 8.3
image is the faithful choice. It is not, and the failure is total rather than
subtle:

```
Your lock file does not contain a compatible set of packages. Please run composer update.
  - symfony/console v8.1.6 requires php >=8.4.1 -> your php version (8.3.35) does not satisfy that requirement.
```

`composer.lock` resolved **Symfony 8.1**, and 18 of the locked packages declare
`php >=8.4.1`. `^8.3` is a *floor* that the locked dependency tree has simply
outgrown; nothing in the repository can install on 8.3.

Every other source here already agrees: `REBUILD.md` records "PHP 8.4.24" as the
verified version, `restore.sh` installs `php8.4-cli`, and `HANDOFF.md` and
`docs/TODO.md` both name the stack "Laravel 13.31 + Filament v4 + **PHP 8.4** +
PostgreSQL 17". The Dockerfile and CI are therefore pinned to 8.4.

Tightening `composer.json` to `^8.4` and regenerating the lock would make the
constraint tell the truth, but it touches every environment at once rather than
just this one, so it is deliberately left as a separate decision.

## 7. Configuration

All of these are read from a gitignored `./.env` at the repository root, which
`dev-up.sh` creates on first run.

| Variable | Default | Meaning |
|---|---|---|
| `APP_PORT` | `8000` | Host port for the app. Also drives the `APP_URL` written into `app/.env` |
| `DB_FORWARD_PORT` | `5433` | Host port for PostgreSQL (loopback only). 5433, not 5432, so a PostgreSQL already on the host is not fought over |
| `DB_DATABASE` | `epcc` | Development database |
| `DB_USERNAME` / `DB_PASSWORD` | `epcc` / `epcc_dev` | Application role — same credentials as `restore.sh` |
| `EPCC_UID` / `EPCC_GID` | unset | Ownership for container-written files. Set automatically on Linux; leave unset on macOS/Windows, where Docker Desktop already maps ownership |
| `PHP_CLI_SERVER_WORKERS` | `4` | `artisan serve` workers; the default of 1 makes an asset-heavy Filament page load serially |
| `EPCC_APP_ENV` / `EPCC_APP_DEBUG` | `local` / `true` | Written into `app/.env`, not exported (§6.1) |

`app/.env` is generated inside the container and is **not** committed. The
test database is fixed to `epcc_test` by `app/phpunit.xml`, not by this file.

## 8. The skip gate, and why the PDF toolchain is not optional

`PdfExportTest` and `VendorTransmittalTest` call `markTestSkipped()` when no
headless browser is present, and again when `pdffonts`/`pdftotext` are missing.
**PHPUnit still exits zero and prints "passed."** `REBUILD.md` §4 records 13
tests disappearing this way on a sandbox reset; `HANDOFF.md` records them coming
back only after Chromium, poppler and Vazirmatn were reinstalled.

Three layers stop that happening here:

1. The Dockerfile installs Chromium, `poppler-utils`, `fontconfig`,
   `fonts-vazirmatn` and `fonts-dejavu-core`, and **fails the build** if
   `fc-list` cannot then see a Vazirmatn family or if any binary is missing.
2. `scripts/dev-test.sh` parses PHPUnit's summary line and fails the run on any
   non-zero skip count (`--allow-skips` to override).
3. The CI workflow installs the toolchain, verifies a browser from
   `PdfRenderer::BINARIES` is reachable, and applies the same summary-line gate.

Vazirmatn matters beyond the tests: `resources/views/print/layout.blade.php`
asks for the family **by name**, with no `@font-face`, because the renderer runs
with networking disabled (air-gap posture AG-01…AG-06). A missing font is not an
error — it is a Persian MC certificate full of disconnected letters that only a
Persian reader notices, after it has been issued to a client. For an air-gapped
build host, drop the TTFs into `docker/php/fonts/` (see the README there).

## 9. Continuous integration

`.github/workflows/ci.yml` runs on every push to `main`, every pull request, and
on demand:

| Job | Runs | Notes |
|---|---|---|
| `static` | `standards-packs/validate_packs.py`, `packages/standards-engine/conformance/run.php` | No database, no vendor directory |
| `prototype` | `prototype/test/render.test.js` + `npm run test:prototype` | Node 22, root `jsdom` |
| `laravel` | The full PHPUnit suite | `postgres:17` service, non-superuser `epcc` role, PDF toolchain, skip gate. Runs `vendor/bin/phpunit` with `--log-junit` so every failing test becomes a PR annotation, and the run fails on any failure, error **or** skip |
| `image` | Builds `docker/php/Dockerfile`, smoke-checks it, `docker compose config`, `shellcheck` | Proves the dev image still contains what §8 depends on |
| `stack` | Boots the real compose stack, loads `/login`, queries the database as `epcc`, runs a slice of the suite | Proves `dev-up.sh` works end to end, that RLS is fail-closed inside the container, and that the suite cannot reach the development database (§6.1) |

The suite is large and several tests call `migrate:fresh` in `setUp()`, so the
`laravel` job runs ~19 minutes and is given 90. That is expected, not a leak.

The `stack` job is the one that validates this document's central claim. It
plants a sentinel table in the **development** database, runs a slice of the
suite — which calls `migrate:fresh` — and then requires the sentinel to survive.
A suite that had leaked onto `epcc` would have dropped it, so §6.1 is enforced by
an assertion rather than by a comment.

## 10. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| `dev-up.sh` reports "not healthy yet after ~6 minutes" | First boot still installing, or a step failed | `docker compose logs -f app` — the entrypoint prints what it is doing |
| Page renders unstyled, `/admin` looks broken, but returns 200 | Filament assets never published | `docker compose exec app php artisan filament:assets` |
| "Vite manifest not found" | `app/public/build` missing | `docker compose exec app npm run build` |
| `SQLSTATE…could not connect to server` on boot | `db` not healthy yet, or the init scripts were changed after the volume existed | `docker compose logs db`; after editing `docker/postgres/init/*`, run `scripts/dev-down.sh --volumes` |
| `composer install` fails: "Your lock file does not contain a compatible set of packages" | The runtime is PHP 8.3; the locked Symfony 8.1 tree needs `>=8.4.1` | Re-read §6.4 — the image and CI are pinned to 8.4 |
| A `php -m` check fails on an extension that is installed | `php -m` prints `PDO`, `SimpleXML`, `Phar`, `SPL` in mixed case | Match case-insensitively (`grep -qxi`) |
| Tenancy tests fail with rows visible where none should be | The role became a superuser | Re-read §6.2; `scripts/dev-down.sh --volumes && scripts/dev-up.sh` |
| Tests wiped your demo data | `DB_DATABASE` exported as a real env var | Re-read §6.1 |
| `EACCES` writing files on the host after a container run | Container ran as root (no `EPCC_UID`) | Check `./.env` has your uid, then `sudo chown -R $(id -u):$(id -g) app/storage app/.env` |
| Prototype harnesses print nothing and exit 0 | Root `node_modules/jsdom` missing | `docker compose exec app npm install` |
| PDF tests skipped | Toolchain missing | §8 — but the image build should already have refused |

## 11. Files added

```
compose.yaml                              the stack
docker/php/Dockerfile                     PHP 8.4 runtime + PDF toolchain
docker/php/entrypoint.sh                  first-boot bootstrap (restore.sh's counterpart)
docker/php/epcc.ini                       upload/memory limits (mirrors restore.sh)
docker/php/fonts/                         optional font drop-in for air-gapped builds
docker/postgres/init/01-epcc-databases.sql  non-superuser role + epcc / epcc_test
scripts/dev-up.sh                         build, start, wait, report
scripts/dev-down.sh                       stop (optionally destroy data)
scripts/dev-seed.sh                       rebuild the demo database
scripts/dev-test.sh                       every suite, with the skip gate
.github/workflows/ci.yml                  CI
docs/DEVELOPMENT-ENVIRONMENT.md           this file
.dockerignore                             build context
```
