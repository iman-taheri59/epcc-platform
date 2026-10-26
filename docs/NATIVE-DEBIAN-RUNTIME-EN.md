>2026-10-19 latest runtime: complete1169/17146 on456 source. Data `/home/user/.cache/epcc-reconciliation-full-pg-rbik7nwk`, socket `/tmp/epcc-reconciliation-full-sock-lq78n0a7`, metadata `/home/user/.cache/epcc-app-journal-cluster.json`; private5545/no TCP, WAL128/32MB/integrity ON; stopped after closeout. Excluded runtime may disappear; verify current metadata/posture or recreate, never reuse stale paths. See G01-RECONCILIATION-EVIDENCE-FULL-SUITE-EN.md.

>2026-10-19 latest runtime: full1151/16776 on454 source. Metadata `/home/user/.cache/epcc-app-journal-cluster.json`; data `/home/user/.cache/epcc-topology-pg-1ge_atll`, socket `/tmp/epcc-topology-sock-v4vh9mdo`, private5545/no TCP, WAL128/32MB/integrity ON; stopped after closeout. Excluded runtime may vanish; do not reuse stale paths. See G01-TOPOLOGY-FULL-SUITE-EN.md.

# Native Debian 13 Development Runtime

Verified on 2026-10-18 in this workspace. This is an additional development tier, not a production installer. Network availability and sudo access vary by session; earlier sandbox network failures are historical observations, not permanent platform capabilities.

## Prerequisites and installation

Debian 13, sudo permission, official Debian package repositories, npm registry and Composer package endpoints. Use only on a development host; installation changes system packages and starts a local database.

```sh
sudo apt-get update
sudo apt-get install -y --no-install-recommends \
  php8.4-cli php8.4-pgsql php8.4-xml php8.4-mbstring php8.4-intl \
  php8.4-curl php8.4-zip php8.4-bcmath php8.4-gd composer \
  postgresql-17 postgresql-contrib-17 chromium poppler-utils fonts-vazirmatn
sudo pg_ctlcluster 17 main start
php -v
php -r 'var_export(PDO::getAvailableDrivers());'
fc-match Vazirmatn
```

Provision a dedicated local role and separate application/test databases. On a fresh installation only:

```sh
sudo -u postgres psql -v ON_ERROR_STOP=1 <<'SQL'
CREATE ROLE epcc LOGIN PASSWORD 'epcc_dev' NOSUPERUSER NOCREATEDB NOBYPASSRLS;
CREATE DATABASE epcc OWNER epcc;
CREATE DATABASE epcc_test OWNER epcc;
SQL
for db in epcc epcc_test; do
  sudo -u postgres psql -d "$db" -c 'CREATE EXTENSION IF NOT EXISTS btree_gist';
done
```

These credentials are synthetic local-development credentials, never a customer configuration. Keep PostgreSQL bound to loopback. Do not use a superuser app role to bypass RLS errors.

## Laravel

For sandbox persistence, keep generated Composer files under the excluded cache directory instead of snapshotting thousands of vendor files. On a fresh clone with no app/vendor:

```sh
# repository root; do not overwrite an existing vendor directory
mkdir -p .cache/vendor
ln -s ../.cache/vendor app/vendor
```

The existing tests/bootstrap.php pins APP_BASE_PATH so the vendor symlink does not confuse Laravel. Recreate the ignored target and reinstall dependencies after restoration. Avoid concurrent test bootstraps, which purge seeded blobs.

From `app/`, install locked dependencies without running package hooks initially:

```sh
composer install --no-scripts --no-interaction --prefer-dist
cp .env.example .env   # ONLY if .env does not already exist
```

Edit ignored `.env`: set `DB_CONNECTION=pgsql`, host `127.0.0.1`, port `5432`, database `epcc`, username `epcc`, password `epcc_dev`. Replace the SQLite default; do not leave conflicting active DB lines. For this local tier use `SESSION_DRIVER=file`, `CACHE_STORE=file`, `QUEUE_CONNECTION=sync`, and `APP_DEBUG=false`. Never commit `.env` or APP_KEY.

```sh
php artisan key:generate
php artisan package:discover
php artisan filament:assets
php artisan migrate --seed --force
```

`migrate --seed` is the initial empty-database path, not a repeatable production deployment or repeatable seed command. For an expendable demo database only, `migrate:fresh --seed` recreates it destructively. Never point test commands at customer data.

## Frontend

Use Node 22 compatible with Vite 8. The host's Node 20.20.2/npm 10 install hit an npm dependency-resolution exception in this review; a separate ignored Node 22/npm 11 tool directory succeeded. No app dependency versions or lockfiles were changed.

```sh
# With Node 22 and npm 11 on PATH, from app/:
npm install --ignore-scripts
npm run build
```

There is no tracked app/package-lock.json at this review baseline. This is a reproducibility gap, not a claim that the app dependency graph is locked. The root package-lock.json belongs to prototype tests. Do not confuse the two npm projects.

## Test isolation

`app/phpunit.xml` selects `epcc_test`; `.env` supplies the connection credentials. Do not export DB_DATABASE=epcc into the shell. Clear stale Laravel config before testing. Run DB suites **serially**: the tests share a database and use migrate:fresh. Do not run the full PHPUnit command and root scripts/run-tests.sh concurrently: the root script also launches the full Laravel suite once vendor/PHP are present.

```sh
cd app
php artisan config:clear
vendor/bin/phpunit --colors=never --log-junit /path/to/evidence/phpunit.xml
# Focused rebuild regression:
vendor/bin/phpunit --filter 'PipeScheduleMigrationRebuildTest|SeedPathTest|PmsRevisionDraftValidatorTest'
```

`tests/bootstrap.php` purges seeded document blobs; this is another reason not to use a real/customer database or run competing test processes. Reseed a disposable demo only after tests if demo file attachments need restoring; do not seed repeatedly over an existing demo without resetting it.

Run the independent checks separately if a DB suite is already active:

```sh
# repository root
python3 standards-packs/validate_packs.py
php packages/standards-engine/conformance/run.php
php scripts/tests/test_material_screening.php
php scripts/screen-material.php docs/examples/material-screening-synthetic.json
npm run test:prototype
```

## Display server

```sh
# app/
php artisan serve --host=0.0.0.0 --port=8000 --no-reload
```

The current live preview is the real Laravel application, not the prototype. Only synthetic demo data may be used. For cookie-blocking sandbox iframes, this workspace enables SESSION_URL_FALLBACK=true in ignored .env; the existing code also requires a recognized preview host. Signed URL session tokens remain sensitive. Keep this flag OFF in customer deployments, restrict trusted proxies and do not publish the demo as a production service.

System packages, services, node tool binaries, vendor/node_modules and ignored runtime files are not guaranteed to survive workspace restoration. Recreate them from these instructions and verify role posture before rerunning tests. This native tier has not certified Docker image/compose stack jobs, backup recovery or production operations.


## Final-source verification: optional separate ephemeral test cluster

The2026-10-18 green full run used a separate PostgreSQL17 cluster on loopback5433, not the demo cluster5432. Its directory was on /tmp(tmpfs on this host), so its data is disposable and not a production backup/durability scenario. fsync, synchronous_commit and full_page_writes remained ON. WAL128MB max/32MB min bounded memory use; no SQL/RLS guard was disabled.

On a fresh development host with installed PostgreSQL17 tools, create an expendable cluster as the non-root development user (choose an unused port):

```sh
PGDATA_TEST=$(mktemp -d /tmp/epcc-verify.XXXXXX)
PGSOCK_TEST=$(mktemp -d /tmp/epcc-verify-sock.XXXXXX)
pwfile=$(mktemp)
head -c 32 /dev/urandom | base64 > "$pwfile"
/usr/lib/postgresql/17/bin/initdb -D "$PGDATA_TEST" -U postgres \
  --auth-local=trust --auth-host=scram-sha-256 --pwfile="$pwfile" -E UTF8
rm -f "$pwfile"
printf 'Data:%s\nSocket:%s\n' "$PGDATA_TEST" "$PGSOCK_TEST"
# Run in a supervised terminal; do not use production paths:
/usr/lib/postgresql/17/bin/postgres -D "$PGDATA_TEST" -h127.0.0.1 -p5433 \
  -k "$PGSOCK_TEST" -c max_wal_size=128MB -c min_wal_size=32MB
```

In another terminal, substitute the printed socket path. Provision epcc with the same synthetic development password used by the ignored app .env and create epcc_test/extension (admin connection is through that local socket). The commands in the database-provisioning section apply with `psql -h <printed-socket> -p5433 -U postgres`, creating only epcc_test for this cluster. Verify superuser=false, bypassrls=false and createdb=false, and verify all three integrity settings ON. Then, from app/:

```sh
DB_PORT=5433 SESSION_URL_FALLBACK=false vendor/bin/phpunit \
  --colors=never --testdox --log-junit /path/to/evidence/phpunit.xml
```

Do not override DB_DATABASE to epcc; phpunit.xml selects epcc_test. Keep demo env pointed at5432. Stop the isolated server after verification; its transient data is not persisted in source recovery. A normal development PostgreSQL cluster/port with the correct role posture is also valid; use its port rather than assuming5433 exists after a reset.
