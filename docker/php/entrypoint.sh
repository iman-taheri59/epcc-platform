#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# EPCC Platform - container entrypoint
#
# Makes `docker compose up` go from "no container" to "working demo on
# http://localhost:8000" with no manual steps, and is safe to re-run: every
# step is conditional on something actually being missing.
#
# It is the container counterpart of restore.sh, which does the same job on a
# bare sandbox. Where the two disagree, one of them is wrong - keep them in
# step (database names, credentials, seeders, Filament assets, the PDF
# toolchain).
#
# Order matters and is not obvious in two places:
#
#   1. `composer install` runs with --no-scripts. Its post-autoload-dump hook
#      boots Laravel, and a boot before .env exists silently configures the
#      app from defaults - DB_CONNECTION=sqlite in .env.example, which is the
#      failure mode restore.sh was written to stop. Dependencies first, .env
#      second, then the artisan commands explicitly.
#
#   2. Migrations run BEFORE seeding is decided, and the "is this a fresh
#      database" check goes through information_schema rather than through a
#      model count. Almost every table in this schema is under RLS with FORCE
#      ROW LEVEL SECURITY, so an unbound model count returns 0 by policy -
#      which is indistinguishable from "empty database" and would re-seed on
#      every single boot. information_schema is not subject to RLS.
#
# Environment opt-outs (all default to "do the work"):
#   SKIP_COMPOSER=1 SKIP_NPM=1 SKIP_ASSETS=1 SKIP_MIGRATE=1 SKIP_BOOTSTRAP=1
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT_DIR="${ROOT_DIR:-/srv/epcc}"
APP_DIR="$ROOT_DIR/app"

# Connection parameters arrive as EPCC_DB_* rather than DB_* on purpose.
# A real DB_* environment variable beats app/.env (Laravel loads .env through
# an IMMUTABLE Dotenv repository), and phpunit.xml sets DB_DATABASE=epcc_test
# with force="false". Export DB_DATABASE=epcc here and PHPUnit can no longer
# override it - every `php artisan test` would then run migrate:fresh against
# the DEVELOPMENT database and wipe the demo fixture. See
# docs/DEVELOPMENT-ENVIRONMENT.md ("Why DB_* is not in compose.yaml").
DB_HOST="${EPCC_DB_HOST:-db}"
DB_PORT="${EPCC_DB_PORT:-5432}"
DB_DATABASE="${EPCC_DB_DATABASE:-epcc}"
DB_USERNAME="${EPCC_DB_USERNAME:-epcc}"
DB_PASSWORD="${EPCC_DB_PASSWORD:-epcc_dev}"

say()  { printf '\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!!  %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mxx  %s\033[0m\n' "$*" >&2; exit 1; }

# Set (or append) one key in a .env file. Appending is not enough on its own:
# .env.example ships `DB_CONNECTION=sqlite` uncommented, and Dotenv keeps the
# FIRST occurrence of a duplicated key, so an appended override is ignored.
env_set() {
    local key="$1" value="$2" file="$3"
    if grep -q "^${key}=" "$file"; then
        # | as the delimiter: values here are URLs and DSNs, never paths.
        sed -i "s|^${key}=.*|${key}=${value}|" "$file"
    else
        printf '%s=%s\n' "$key" "$value" >> "$file"
    fi
}

# ---------------------------------------------------------------- 0. whoami --
# The checkout is bind-mounted, so anything this script writes lands on the
# developer's disk. Left as root, .env, storage/logs and public/build come out
# root-owned and the next `composer install` or editor save on the host fails
# with EACCES - a problem that shows up hours later and looks like nothing to
# do with Docker. Drop to the host uid first, then do all the work as that uid.
if [ "$(id -u)" -eq 0 ] && [ -n "${EPCC_UID:-}" ] && [ -z "${EPCC_PRIVILEGES_DROPPED:-}" ]; then
    EPCC_GID="${EPCC_GID:-$EPCC_UID}"
    say "Adopting host ownership (${EPCC_UID}:${EPCC_GID}) and dropping privileges"
    for path in "$APP_DIR/storage" "$APP_DIR/bootstrap/cache" \
                "$APP_DIR/public/build" "$APP_DIR/public/storage" \
                "$APP_DIR/.env" "$APP_DIR/.env.example"; do
        [ -e "$path" ] && chown -R "${EPCC_UID}:${EPCC_GID}" "$path" 2>/dev/null || true
    done
    # Re-exec as the developer. setpriv --clear-groups needs no passwd entry for
    # a uid this image has never heard of, unlike gosu/su-exec.
    export EPCC_PRIVILEGES_DROPPED=1
    exec setpriv --reuid="$EPCC_UID" --regid="$EPCC_GID" --clear-groups -- "$0" "$@"
fi

if [ "${SKIP_BOOTSTRAP:-0}" = "1" ]; then
    say "SKIP_BOOTSTRAP=1 - handing over without touching anything"
    cd "$APP_DIR" 2>/dev/null || cd "$ROOT_DIR"
    exec "$@"
fi

# ------------------------------------------------------- 1. wait for postgres --
say "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT}"
for _ in $(seq 1 60); do
    if pg_isready -q -h "$DB_HOST" -p "$DB_PORT" >/dev/null 2>&1; then
        break
    fi
    sleep 1
done
pg_isready -q -h "$DB_HOST" -p "$DB_PORT" >/dev/null 2>&1 \
    || die "PostgreSQL never became reachable at ${DB_HOST}:${DB_PORT}. Is the db service healthy? (docker compose logs db)"

# ------------------------------------------------------------- 2. dependencies --
cd "$APP_DIR"

# vendor/ is a NAMED VOLUME in compose.yaml, so it starts empty on the first
# boot and survives rebuilds after that. It is deliberately not baked into the
# image: composer.json resolves epcc/standards-engine through a SYMLINKED path
# repository, and a symlink built at image-build time points at a path that
# only exists in the image.
if [ "${SKIP_COMPOSER:-0}" != "1" ]; then
    engine_link="$APP_DIR/vendor/epcc/standards-engine"
    if [ ! -f vendor/autoload.php ]; then
        say "Installing Composer dependencies (first boot - a minute or two)"
        composer install --no-scripts --no-interaction --no-progress
    elif [ -L "$engine_link" ] && [ ! -e "$engine_link" ]; then
        # A vendor/ copied in from a host checkout: the path-repository symlink
        # is absolute and points at wherever the HOST had the repo.
        say "Repairing the standards-engine path symlink"
        composer install --no-scripts --no-interaction --no-progress
    else
        say "Composer dependencies present"
    fi
fi

[ -f vendor/autoload.php ] || die "app/vendor/autoload.php is missing and SKIP_COMPOSER did not explain why"

# -------------------------------------------------------------------- 3. .env --
if [ ! -f .env ]; then
    say "Creating app/.env from app/.env.example"
    cp .env.example .env
fi

say "Binding app/.env to PostgreSQL (${DB_USERNAME}@${DB_HOST}:${DB_PORT}/${DB_DATABASE})"
env_set DB_CONNECTION pgsql         .env
env_set DB_HOST      "$DB_HOST"     .env
env_set DB_PORT      "$DB_PORT"     .env
env_set DB_DATABASE  "$DB_DATABASE" .env
env_set DB_USERNAME  "$DB_USERNAME" .env
env_set DB_PASSWORD  "$DB_PASSWORD" .env

# Same for the application settings. They arrive prefixed and are written into
# .env rather than left as real environment variables, because a real variable
# beats both .env and phpunit.xml (whose <env> entries are force="false").
# Exporting APP_ENV=local would run the suite in the `local` environment
# instead of `testing`; exporting DB_DATABASE=epcc would point the suite's
# migrate:fresh calls at the development database. See
# docs/DEVELOPMENT-ENVIRONMENT.md §6.1.
env_set APP_ENV                  "${EPCC_APP_ENV:-local}"                       .env
env_set APP_DEBUG                "${EPCC_APP_DEBUG:-true}"                      .env
env_set APP_URL                  "${EPCC_APP_URL:-http://localhost:8000}"       .env
env_set PHP_CLI_SERVER_WORKERS   "${EPCC_SERVER_WORKERS:-4}"                    .env

# .env.example ships an empty APP_KEY and Laravel refuses to boot an encrypted
# session without one. Never overwrite an existing key: rotating it invalidates
# every signed cookie and every encrypted column in a database that persists
# across restarts.
if ! grep -q '^APP_KEY=base64:' .env; then
    say "Generating APP_KEY"
    php artisan key:generate --force --no-interaction
fi

# ------------------------------------------------------------ 4. node + assets --
# Two separate node_modules trees, two separate jobs:
#   app/    Vite build for the real UI (an unbuilt app/public makes Laravel
#           throw "Vite manifest not found" on every page)
#   ./      jsdom for the prototype harnesses in prototype/test/
if [ "${SKIP_NPM:-0}" != "1" ]; then
    if [ ! -d "$ROOT_DIR/node_modules/jsdom" ]; then
        say "Installing jsdom for the prototype harnesses"
        ( cd "$ROOT_DIR" \
          && { [ -f package-lock.json ] && npm ci --no-audit --no-fund || npm install --no-audit --no-fund; } ) \
          || warn "root npm install failed - prototype/test/* will report MODULE_NOT_FOUND and print NOTHING"
    fi

    if [ ! -d node_modules ]; then
        say "Installing app npm dependencies"
        npm install --no-audit --no-fund \
            || warn "app npm install failed - Vite assets will not build"
    fi

    if [ "${SKIP_ASSETS:-0}" != "1" ] && [ -d node_modules ] && [ ! -f public/build/manifest.json ]; then
        say "Building front-end assets (vite)"
        npm run build || warn "vite build failed - pages will report a missing Vite manifest"
    fi
fi

# --------------------------------------------------------- 5. migrate or seed --
if [ "${SKIP_MIGRATE:-0}" != "1" ]; then
    tables=$(PGPASSWORD="$DB_PASSWORD" psql -h "$DB_HOST" -p "$DB_PORT" \
             -U "$DB_USERNAME" -d "$DB_DATABASE" -tAc \
             "select count(*) from information_schema.tables
               where table_schema = 'public' and table_type = 'BASE TABLE'" \
             2>/dev/null || echo 0)

    if [ "${tables:-0}" -eq 0 ]; then
        # First boot against an empty volume: build the working demo, not an
        # empty schema. `migrate:fresh --seed` is the documented path
        # (REBUILD.md) and SeedPathTest pins that DatabaseSeeder produces the
        # fixture. ProcurementSeeder is separate so the core demo stays small -
        # same split restore.sh uses.
        say "Empty database - migrating and seeding the demo fixture"
        php artisan migrate:fresh --force --seed --no-interaction
        php artisan db:seed --class=ProcurementSeeder --force --no-interaction
    else
        say "Database present (${tables} tables) - applying pending migrations"
        php artisan migrate --force --no-interaction
    fi
fi

# ------------------------------------------------------- 6. caches and assets --
# Filament ships its CSS/JS into public/ through a publish step. Without it
# /admin renders as unstyled HTML with no JS and still returns 200, which is
# exactly why it is easy to miss (restore.sh has the same note).
if [ "${SKIP_ASSETS:-0}" != "1" ]; then
    php artisan package:discover --ansi --no-interaction >/dev/null 2>&1 \
        || warn "package:discover failed"
    php artisan filament:assets --no-interaction >/dev/null 2>&1 \
        || warn "filament:assets failed - /admin will render unstyled"
    php artisan config:clear --no-interaction >/dev/null 2>&1 || true
    php artisan route:clear  --no-interaction >/dev/null 2>&1 || true
    php artisan view:clear   --no-interaction >/dev/null 2>&1 || true
fi

# ------------------------------------------------------- 7. PDF toolchain check --
# Not a gate - the app runs fine without it - but the suite does not: 13 tests
# skip instead of failing. Say so at boot rather than at review time.
missing=""
for bin in chromium pdftotext pdffonts; do
    command -v "$bin" >/dev/null 2>&1 || missing="$missing $bin"
done
if [ -n "$missing" ]; then
    warn "PDF toolchain incomplete:${missing} - the PDF tests will SILENTLY SKIP"
fi
fc-list 2>/dev/null | grep -qi vazirmatn \
    || warn "Vazirmatn not visible to fontconfig - Persian PDFs will substitute a non-joining font"

# ------------------------------------------------------------------ 8. hand over --
cat <<EOF

  EPCC development container ready
    App:    ${EPCC_APP_URL:-http://localhost:8000}
    Login:  karimi@nioc.example / password
    Tests:  scripts/dev-test.sh
    Re-seed: scripts/dev-seed.sh

EOF

cd "$APP_DIR"
exec "$@"
