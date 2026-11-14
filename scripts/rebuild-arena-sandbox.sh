#!/usr/bin/env bash
# Rebuild the Arena sandbox/preview runtime after an environment reset.
#
# This is deliberately NOT the repository-root restore.sh: it does not drop or
# wipe databases, never runs migrate:fresh, and never seeds demo data. Existing
# app/.env and APP_KEY values are preserved; a new .env is copied from the
# example only when one does not already exist.
#
#   bash scripts/rebuild-arena-sandbox.sh                 # read-only preflight
#   bash scripts/rebuild-arena-sandbox.sh --apply         # rebuild + pending migrations
#   bash scripts/rebuild-arena-sandbox.sh --apply --no-migrate
#
# Composer's generated vendor tree lives in ~/.cache (excluded from workspace
# snapshots) and is exposed at app/vendor through a symlink.
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd -P)"
APP="$REPO_ROOT/app"
VENDOR_CACHE="$HOME/.cache/epcc-vendor"
COMPOSER_CACHE="$HOME/.cache/composer"

APPLY=0
QUICK=0
MIGRATE=1

say() { printf '\n==> %s\n' "$*"; }
die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

usage() {
    cat <<'EOF'
Usage: scripts/rebuild-arena-sandbox.sh [options]

Default mode is a read-only preflight. Options:
  --apply        Rebuild the Arena sandbox runtime (explicitly permits changes).
  --quick        Skip apt installation; fail if required tools are missing.
  --no-migrate   Do not apply pending Laravel migrations.
  --help         Show this help.

Safety:
  * Sandbox only; refuses production-marked or remote database configuration.
  * Creates local databases only when absent; never drops or wipes them.
  * Applies ordinary pending migrations only (never migrate:fresh).
  * Never seeds demo data.
  * Preserves an existing app/.env and APP_KEY; generates a key only if blank.
  * Stores Composer vendor files outside the workspace in ~/.cache/epcc-vendor.
EOF
}

while (($#)); do
    case "$1" in
        --apply) APPLY=1 ;;
        --quick) QUICK=1 ;;
        --no-migrate) MIGRATE=0 ;;
        --help|-h) usage; exit 0 ;;
        *) die "Unknown option: $1 (try --help)." ;;
    esac
    shift
done

[[ -f "$APP/composer.json" && -f "$APP/composer.lock" ]] \
    || die "Missing Laravel app or Composer lockfile under $APP."
[[ -f "$REPO_ROOT/scripts/workspace-budget.sh" ]] \
    || die 'scripts/workspace-budget.sh is missing.'

# Read one dotenv key without sourcing .env as shell code. If .env is absent,
# inspect .env.example so preflight and first-run checks use the same defaults.
dotenv_value() {
    local key="$1" fallback="${2:-}" env_file="$APP/.env"
    [[ -f "$env_file" ]] || env_file="$APP/.env.example"
    python3 - "$env_file" "$key" "$fallback" <<'PY'
import ast
import re
import sys
from pathlib import Path

path, wanted, fallback = Path(sys.argv[1]), sys.argv[2], sys.argv[3]
if path.exists():
    for line in path.read_text().splitlines():
        stripped = line.strip()
        if not stripped or stripped.startswith('#') or '=' not in stripped:
            continue
        key, raw = stripped.split('=', 1)
        if key.strip() != wanted:
            continue
        value = raw.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
            try:
                value = ast.literal_eval(value)
            except (SyntaxError, ValueError):
                value = value[1:-1]
        else:
            value = re.split(r'\s+#', value, maxsplit=1)[0].strip()
        print(value)
        raise SystemExit(0)
print(fallback)
PY
}

if (( APPLY == 0 )); then
    say 'Read-only preflight: no packages, files, services, or databases will be changed.'
    printf 'Target: Arena sandbox/preview; repo: %s\n' "$REPO_ROOT"
    for tool in php composer pg_ctlcluster pg_lsclusters psql; do
        if command -v "$tool" >/dev/null 2>&1; then
            printf '  found: %s\n' "$tool"
        else
            printf '  missing: %s\n' "$tool"
        fi
    done
    if [[ -f "$APP/.env" ]]; then
        current_env="$(dotenv_value APP_ENV local)"
        current_db="$(dotenv_value DB_DATABASE epcc)"
        printf '  Laravel environment: %s; database target: %s (password hidden)\n' \
            "$current_env" "$current_db"
    else
        echo '  app/.env: missing (will be copied from .env.example on --apply)'
    fi
    bash "$REPO_ROOT/scripts/workspace-budget.sh" --check
    echo 'Preflight complete. Rebuild only with --apply.'
    exit 0
fi

(( EUID != 0 )) || die 'Run this as the workspace user; the script uses sudo for system packages/services.'
command -v sudo >/dev/null 2>&1 || die 'sudo is required for apt and PostgreSQL setup.'

# Refuse a production-marked or remote database configuration before changing
# the host. Fixed database/role names protect against targeting another DB.
APP_ENV_FILE="$(dotenv_value APP_ENV local)"
[[ "${APP_ENV_FILE,,}" != production ]] \
    || die 'app/.env says APP_ENV=production; this sandbox-only script refuses to continue.'
SHELL_APP_ENV="${APP_ENV:-}"
[[ "${SHELL_APP_ENV,,}" != production ]] \
    || die 'The shell exports APP_ENV=production; refusing to continue.'
DB_CONNECTION_FILE="$(dotenv_value DB_CONNECTION '')"
DB_DATABASE="$(dotenv_value DB_DATABASE '')"
DB_USERNAME="$(dotenv_value DB_USERNAME '')"
DB_PASSWORD="$(dotenv_value DB_PASSWORD '')"
DB_HOST_FILE="$(dotenv_value DB_HOST '')"
DB_PORT_FILE="$(dotenv_value DB_PORT '')"
DB_URL_FILE="$(dotenv_value DB_URL '')"

[[ "$DB_CONNECTION_FILE" == pgsql ]] \
    || die 'Expected DB_CONNECTION=pgsql for the Arena sandbox.'
[[ "$DB_DATABASE" == epcc ]] \
    || die 'Expected local DB_DATABASE=epcc; refusing to touch a differently named database.'
[[ "$DB_USERNAME" == epcc ]] \
    || die 'Expected local DB_USERNAME=epcc; refusing to alter another database role.'
[[ -z "$DB_PORT_FILE" || "$DB_PORT_FILE" == 5432 ]] \
    || die 'Expected local DB_PORT=5432; refusing to redirect the configured database.'
[[ -n "$DB_PASSWORD" ]] \
    || die 'DB_PASSWORD is empty; set a sandbox password in app/.env first.'
[[ -z "$DB_URL_FILE" ]] \
    || die 'DB_URL is set; refusing an ambiguous or remote URL. Clear DB_URL in app/.env for the sandbox.'
case "$DB_HOST_FILE" in
    ''|127.0.0.1|localhost|::1|/tmp/epcc-pg|/var/run/postgresql) ;;
    *) die 'app/.env DB_HOST is not local; refusing to redirect or alter a remote database.' ;;
esac
[[ "$DB_USERNAME" =~ ^[a-z_][a-z0-9_]{0,62}$ ]] \
    || die 'DB_USERNAME is not a safe PostgreSQL role name.'

# Resolve the cache before package installation. Inside the workspace, only the
# snapshot-excluded ~/.cache tree is accepted.
[[ "$VENDOR_CACHE" == /* ]] || die 'EPCC_VENDOR_CACHE must be an absolute path.'
mkdir -p -- "$VENDOR_CACHE" "$COMPOSER_CACHE"
VENDOR_CACHE_REAL="$(cd -- "$VENDOR_CACHE" && pwd -P)"
COMPOSER_CACHE_REAL="$(cd -- "$COMPOSER_CACHE" && pwd -P)"
HOME_REAL="$(cd -- "$HOME" && pwd -P)"
case "$VENDOR_CACHE_REAL/" in
    "$REPO_ROOT/"*) die 'Composer vendor cache must be outside the repository/workspace.' ;;
esac
case "$VENDOR_CACHE_REAL/" in
    "$HOME_REAL/.cache/"*) ;;
    "$HOME_REAL/"*) die 'A cache under the workspace must live inside the excluded ~/.cache directory.' ;;
esac
case "$VENDOR_CACHE_REAL/" in
    "$COMPOSER_CACHE_REAL/"*) die 'Vendor cache must not overlap the Composer download cache.' ;;
esac
case "$COMPOSER_CACHE_REAL/" in
    "$VENDOR_CACHE_REAL/"*) die 'Vendor cache must not overlap the Composer download cache.' ;;
esac

if (( QUICK == 0 )); then
    say 'Installing PHP 8.4, Composer and PostgreSQL 17 packages'
    sudo apt-get update -qq
    sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y -qq --no-install-recommends \
        ca-certificates curl git unzip composer python3 \
        php8.4-cli php8.4-pgsql php8.4-sqlite3 php8.4-mbstring php8.4-xml \
        php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath \
        postgresql-17 postgresql-client-17
fi

for tool in php composer pg_createcluster pg_ctlcluster pg_lsclusters pg_isready psql python3; do
    command -v "$tool" >/dev/null 2>&1 \
        || die "Required command '$tool' is missing. Re-run without --quick."
done
php -r 'exit(PHP_VERSION_ID >= 80401 && PHP_VERSION_ID < 80500 ? 0 : 1);' \
    || die 'The locked app requires PHP 8.4.1 or newer in the 8.4 series.'
for extension in pdo_pgsql xmlreader xmlwriter mbstring intl; do
    php -m | grep -qi "^${extension}$" || die "Required PHP extension '$extension' is missing."
done

# Preserve existing configuration and keys. On a first run only, create .env
# from the checked-in template and default the browser preview to debug-off.
if [[ ! -f "$APP/.env" ]]; then
    [[ -f "$APP/.env.example" ]] || die 'app/.env.example is missing.'
    (umask 077 && cp -- "$APP/.env.example" "$APP/.env")
    python3 - "$APP/.env" <<'PY'
import sys
from pathlib import Path

path = Path(sys.argv[1])
lines = path.read_text().splitlines()
updated = []
seen = False
for line in lines:
    if '=' in line and line.split('=', 1)[0].strip() == 'APP_DEBUG':
        if not seen:
            updated.append('APP_DEBUG=false')
            seen = True
    else:
        updated.append(line)
if not seen:
    updated.append('APP_DEBUG=false')
path.write_text('\n'.join(updated).rstrip() + '\n')
PY
fi

# Keep Composer's file-heavy vendor tree outside the snapshotted workspace.
VENDOR_LINK="$APP/vendor"
if [[ -L "$VENDOR_LINK" ]]; then
    link_target="$(readlink -f -- "$VENDOR_LINK" 2>/dev/null || true)"
    if [[ "$link_target" != "$VENDOR_CACHE_REAL" ]]; then
        # Remove only a stale symlink, never the path it points to.
        rm -f -- "$VENDOR_LINK"
    fi
elif [[ -e "$VENDOR_LINK" ]]; then
    [[ -d "$VENDOR_LINK" ]] || die 'app/vendor exists but is not a directory or symlink.'
    git -C "$REPO_ROOT" check-ignore -q -- app/vendor \
        || die 'app/vendor is not Git-ignored; refusing to move or remove it.'
    [[ -z "$(git -C "$REPO_ROOT" ls-files -- app/vendor)" ]] \
        || die 'Tracked files exist under app/vendor; refusing to move or remove them.'
    [[ -f "$VENDOR_LINK/autoload.php" && -f "$VENDOR_LINK/composer/installed.json" ]] \
        || die 'app/vendor lacks Composer markers; refusing to move or remove it.'
    if [[ -f "$VENDOR_CACHE_REAL/autoload.php" ]]; then
        rm -rf -- "$VENDOR_LINK"
    else
        if [[ -n "$(find "$VENDOR_CACHE_REAL" -mindepth 1 -maxdepth 1 -print -quit)" ]]; then
            die 'The external vendor cache is non-empty but incomplete; inspect it before retrying.'
        fi
        rmdir "$VENDOR_CACHE_REAL" 2>/dev/null || true
        mv -- "$VENDOR_LINK" "$VENDOR_CACHE_REAL"
    fi
fi
if [[ ! -L "$VENDOR_LINK" ]]; then
    ln -s "$VENDOR_CACHE_REAL" "$VENDOR_LINK"
fi

# Expose the isolated socket used by sandbox worker tests. The existing
# app/.env remains untouched and may connect through the socket or localhost TCP.
if [[ ! -d /etc/postgresql/17/main ]]; then
    conflicting_cluster="$(pg_lsclusters -h | awk '$3 == 5432 { print $1 "/" $2 }')"
    [[ -z "$conflicting_cluster" ]] \
        || die "Port 5432 belongs to existing cluster(s): $conflicting_cluster; refusing to create another cluster."
    say 'Creating a PostgreSQL 17/main cluster (no existing cluster is dropped)'
    sudo pg_createcluster --port 5432 17 main
fi
conflicting_cluster="$(pg_lsclusters -h | awk '$3 == 5432 && !($1 == "17" && $2 == "main") { print $1 "/" $2 }')"
[[ -z "$conflicting_cluster" ]] \
    || die "Port 5432 is assigned to another cluster: $conflicting_cluster; refusing to restart or redirect it."
cluster_row="$(pg_lsclusters -h | awk '$1 == "17" && $2 == "main" { print $3, $4 }')"
[[ -n "$cluster_row" ]] || die 'PostgreSQL 17/main cluster is not registered.'
read -r cluster_port cluster_status <<< "$cluster_row"
[[ "$cluster_port" == 5432 ]] \
    || die "PostgreSQL 17/main uses port $cluster_port, not 5432; refusing to redirect the app."
PG_CONF=/etc/postgresql/17/main/postgresql.conf
PG_HBA=/etc/postgresql/17/main/pg_hba.conf
[[ -f "$PG_CONF" && -f "$PG_HBA" ]] \
    || die 'PostgreSQL 17/main config files are missing.'
sudo install -d -o postgres -g postgres -m 2775 /tmp/epcc-pg

config_changes="$(sudo python3 - "$PG_CONF" "$PG_HBA" <<'PY'
import re
import sys
from pathlib import Path

conf_path, hba_path = map(Path, sys.argv[1:3])
conf_lines = conf_path.read_text().splitlines()
setting = "unix_socket_directories = '/var/run/postgresql,/tmp/epcc-pg'"
active = [i for i, line in enumerate(conf_lines)
          if not line.lstrip().startswith('#')
          and re.match(r'^\s*unix_socket_directories\s*=', line)]
conf_changed = False
if active:
    first = active[0]
    new_conf = []
    for i, line in enumerate(conf_lines):
        if i == first:
            if line.strip() != setting:
                conf_changed = True
            new_conf.append(setting)
        elif i in active:
            conf_changed = True
        else:
            new_conf.append(line)
else:
    new_conf = conf_lines + ['', setting]
    conf_changed = True
if conf_changed:
    conf_path.write_text('\n'.join(new_conf).rstrip() + '\n')

records = [
    'local   epcc            epcc                                    scram-sha-256',
    'local   epcc_test       epcc                                    scram-sha-256',
]
target_pairs = {('epcc', 'epcc'), ('epcc_test', 'epcc')}
raw = hba_path.read_text().splitlines()
kept = []
for line in raw:
    fields = line.split()
    if fields and not fields[0].startswith('#') and fields[0] == 'local' and len(fields) >= 3:
        if (fields[1], fields[2]) in target_pairs:
            continue
    kept.append(line)
insert_at = next((i for i, line in enumerate(kept)
                  if line.strip() and not line.lstrip().startswith('#')
                  and line.lstrip().startswith('local ')), len(kept))
new_hba = kept[:insert_at] + records + kept[insert_at:]
hba_changed = new_hba != raw
if hba_changed:
    hba_path.write_text('\n'.join(new_hba).rstrip() + '\n')
print(int(conf_changed), int(hba_changed))
PY
)"
read -r CONF_CHANGED HBA_CHANGED <<< "$config_changes"

if [[ "$cluster_status" == down ]]; then
    say 'Starting PostgreSQL 17/main'
    sudo pg_ctlcluster 17 main start
elif [[ "$cluster_status" == online && "$CONF_CHANGED" == 1 ]]; then
    say 'Restarting PostgreSQL to apply the local worker socket configuration'
    sudo pg_ctlcluster 17 main restart
elif [[ "$cluster_status" == online && "$HBA_CHANGED" == 1 ]]; then
    say 'Reloading PostgreSQL authentication rules'
    sudo pg_ctlcluster 17 main reload
elif [[ "$cluster_status" != online ]]; then
    die "PostgreSQL cluster state is '$cluster_status'; inspect it before proceeding."
fi
pg_isready -h /tmp/epcc-pg -p 5432 >/dev/null \
    || die 'PostgreSQL is not accepting connections on /tmp/epcc-pg:5432.'

# Create/update only the local app role. The password travels to psql over stdin,
# not as a process argument or in logs. Existing databases are never dropped.
say 'Ensuring the local application role and databases (no drops or wipes)'
EPCC_ROLE_PASSWORD="$DB_PASSWORD" python3 - "$DB_USERNAME" <<'PY' | sudo -u postgres psql -X -q -A -t -v ON_ERROR_STOP=1
import os
import sys

def literal(value):
    return "'" + value.replace("'", "''") + "'"

role = literal(sys.argv[1])
password = literal(os.environ['EPCC_ROLE_PASSWORD'])
role_condition = f"rolname = {role}"
print(
    "SELECT format('CREATE ROLE %I LOGIN PASSWORD %L NOSUPERUSER NOCREATEDB "
    f"NOCREATEROLE NOBYPASSRLS', {role}, {password}) "
    f"WHERE NOT EXISTS (SELECT 1 FROM pg_roles WHERE {role_condition})"
)
print(r'\gexec')
print(
    "SELECT format('ALTER ROLE %I WITH LOGIN PASSWORD %L NOSUPERUSER "
    f"NOCREATEDB NOCREATEROLE NOBYPASSRLS', {role}, {password})"
)
print(r'\gexec')
PY

sudo -u postgres psql -X -q -A -t -v ON_ERROR_STOP=1 \
    -v app_role="$DB_USERNAME" -v app_db="$DB_DATABASE" <<'SQL'
SELECT format('CREATE DATABASE %I OWNER %I', :'app_db', :'app_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = :'app_db')
\gexec
SELECT format('CREATE DATABASE %I OWNER %I', 'epcc_test', :'app_role')
WHERE NOT EXISTS (SELECT 1 FROM pg_database WHERE datname = 'epcc_test')
\gexec
SQL

for database in "$DB_DATABASE" epcc_test; do
    owner="$(sudo -u postgres psql -XAtq -d postgres \
        -c "SELECT pg_get_userbyid(datdba) FROM pg_database WHERE datname = '$database'")"
    [[ "$owner" == "$DB_USERNAME" ]] \
        || die "Database '$database' is owned by '$owner', not '$DB_USERNAME'; no ownership change was made."
    sudo -u postgres psql -X -v ON_ERROR_STOP=1 -d "$database" \
        -c 'CREATE EXTENSION IF NOT EXISTS btree_gist;' >/dev/null
done

role_ok="$(sudo -u postgres psql -XAtq -d postgres \
    -c "SELECT count(*) FROM pg_roles WHERE rolname = '$DB_USERNAME' AND NOT rolsuper AND NOT rolbypassrls AND NOT rolcreatedb AND NOT rolcreaterole")"
[[ "$role_ok" == 1 ]] \
    || die 'The epcc role must be a non-superuser without BYPASSRLS, CREATEDB, or CREATEROLE.'

# Remove inherited settings so Composer and Artisan use the verified app/.env.
artisan() (
    cd "$APP"
    env -u APP_ENV -u APP_DEBUG -u APP_KEY \
        -u DB_CONNECTION -u DB_HOST -u DB_PORT -u DB_DATABASE \
        -u DB_USERNAME -u DB_PASSWORD -u DB_URL \
        php artisan "$@"
)

laravel_db_probe() (
    cd "$APP"
    probe="$(mktemp /tmp/epcc-laravel-db-probe.XXXXXX)"
    trap 'rm -f -- "$probe"' EXIT
    cat > "$probe" <<'PHP'
<?php
require getcwd().'/vendor/autoload.php';
$app = require getcwd().'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$row = $app->make('db')->connection()->selectOne(
    'SELECT current_database() AS database_name, current_user AS role_name'
);
printf("Laravel DB connection: %s / %s\n", $row->database_name, $row->role_name);
PHP
    env -u APP_ENV -u APP_DEBUG -u APP_KEY \
        -u DB_CONNECTION -u DB_HOST -u DB_PORT -u DB_DATABASE \
        -u DB_USERNAME -u DB_PASSWORD -u DB_URL \
        php "$probe"
)

composer_sandbox() (
    cd "$APP"
    env -u APP_ENV -u APP_DEBUG -u APP_KEY \
        -u DB_CONNECTION -u DB_HOST -u DB_PORT -u DB_DATABASE \
        -u DB_USERNAME -u DB_PASSWORD -u DB_URL \
        COMPOSER_CACHE_DIR="$COMPOSER_CACHE" \
        composer "$@"
)

say 'Installing Composer dependencies from composer.lock into the excluded cache'
# Defer Composer's Laravel boot scripts until .env and APP_KEY are ready.
composer_sandbox --no-interaction install --no-scripts --no-progress --prefer-source

if [[ -z "$(dotenv_value APP_KEY '')" ]]; then
    say 'Generating APP_KEY only because it is empty (an existing key is preserved)'
    artisan key:generate --force --no-interaction
fi

say 'Running Composer autoload scripts after .env and APP_KEY are ready'
composer_sandbox --no-interaction run-script post-autoload-dump

say 'Clearing compiled Laravel files and publishing Filament assets'
# Avoid optimize:clear: its cache:clear phase can flush a database-backed cache.
artisan config:clear --no-interaction
artisan route:clear --no-interaction
artisan view:clear --no-interaction
artisan filament:assets --no-interaction

# Read-only DB inspection before migrations; report metadata only, never rows.
pg_app() {
    PGPASSWORD="$DB_PASSWORD" psql -X -v ON_ERROR_STOP=1 -h /tmp/epcc-pg -p 5432 \
        -U "$DB_USERNAME" -d "$DB_DATABASE" -Atq -c "$1"
}
public_tables="$(pg_app "SELECT count(*) FROM information_schema.tables WHERE table_schema='public' AND table_type='BASE TABLE'")"
if [[ "$(pg_app "SELECT to_regclass('public.migrations') IS NOT NULL")" == t ]]; then
    migration_count="$(pg_app 'SELECT count(*) FROM migrations')"
else
    migration_count=0
fi
db_size="$(pg_app 'SELECT pg_size_pretty(pg_database_size(current_database()))')"
printf '\nDatabase preflight: db=%s, public_tables=%s, recorded_migrations=%s, size=%s\n' \
    "$DB_DATABASE" "$public_tables" "$migration_count" "$db_size"
if (( MIGRATE == 1 )) && [[ "$migration_count" == 0 && "$public_tables" != 0 ]]; then
    die 'Database has public tables but no recorded migrations; refusing to migrate an ambiguous schema.'
fi

if (( MIGRATE == 1 )); then
    say 'Applying pending migrations only (never migrate:fresh)'
    artisan migrate --force --no-interaction
else
    say 'Skipping migrations (--no-migrate was requested)'
fi

say 'Verifying the Laravel DB connection and workspace budget'
laravel_db_probe
bash "$REPO_ROOT/scripts/workspace-budget.sh" --check

cat <<EOF

Arena sandbox runtime is ready. No database was dropped, wiped, or seeded.
Migrations: $(if (( MIGRATE == 1 )); then printf 'pending migrations applied if any'; else printf 'skipped (--no-migrate)'; fi)
Start the preview with:
  cd "$APP" && php artisan serve --host=0.0.0.0 --port=8000
EOF
