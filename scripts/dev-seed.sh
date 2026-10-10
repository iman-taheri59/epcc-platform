#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Rebuild the development database from scratch: migrate:fresh --seed, then the
# separate procurement fixture.
#
#   scripts/dev-seed.sh                     rebuild epcc (the dev database)
#   scripts/dev-seed.sh --without-procurement
#   scripts/dev-seed.sh --migrate-only      apply pending migrations, keep data
#
# This DELETES every row in the development database. It never touches
# epcc_test - that one belongs to the test suite, which runs migrate:fresh per
# test anyway.
#
# Why the procurement seeder is a second command: DatabaseSeeder deliberately
# calls EpccDemoSeeder, AuthSeeder and MaterialCatalogueSeeder only, so that the
# core demo stays small (and so SeedPathTest can pin exactly what the documented
# `migrate:fresh --seed` path produces). ProcurementSeeder is additive. This
# script mirrors restore.sh, which does the same two steps.
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!!  %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mxx  %s\033[0m\n' "$*" >&2; exit 1; }
# Print this file's header comment as the usage text. Reading it out of the
# source is deliberate: a hand-copied usage block drifts away from the options
# actually implemented, and nobody notices until it has lied for a month.
# `-+` rather than `-\{10,\}`: mawk, the awk on a default Debian/Ubuntu host,
# does not support interval expressions, and an unmatched pattern here silently
# prints the whole script instead of the usage block.
usage() { awk 'NR>2 && /^# -+$/ {exit} NR>2 {print}' "$0"; }

PROCUREMENT=1 MIGRATE_ONLY=0
for arg in "$@"; do
    case "$arg" in
        --without-procurement) PROCUREMENT=0 ;;
        --migrate-only)        MIGRATE_ONLY=1 ;;
        -h|--help)     usage; exit 0 ;;
        *) die "unknown option: $arg (try --help)" ;;
    esac
done

command -v docker >/dev/null 2>&1 || die "docker is not installed"
if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
else
    die "neither 'docker compose' nor 'docker-compose' is available"
fi

# Run as the developer's uid, matching the main container process, so that
# storage blobs and logs the seeders write stay writable on the host.
EXEC_USER=()
EPCC_UID="${EPCC_UID:-$(sed -n 's/^EPCC_UID=//p' .env 2>/dev/null | tail -1 || true)}"
EPCC_GID="${EPCC_GID:-$(sed -n 's/^EPCC_GID=//p' .env 2>/dev/null | tail -1 || true)}"
if [ -n "${EPCC_UID:-}" ]; then
    EXEC_USER=(-u "${EPCC_UID}:${EPCC_GID:-$EPCC_UID}")
fi

container_id=$("${COMPOSE[@]}" ps -q app 2>/dev/null || true)
if [ -z "$container_id" ]; then
    die "the app container is not running. Start it first: scripts/dev-up.sh"
fi
docker inspect -f '{{.State.Running}}' "$container_id" 2>/dev/null | grep -q true \
    || die "the app container is not running. Start it first: scripts/dev-up.sh"

if [ "$MIGRATE_ONLY" -eq 1 ]; then
    say "Applying pending migrations (data kept)"
    "${COMPOSE[@]}" exec -T "${EXEC_USER[@]}" app \
        php artisan migrate --force --no-interaction
else
    say "Dropping and rebuilding the development database"
    "${COMPOSE[@]}" exec -T "${EXEC_USER[@]}" app \
        php artisan migrate:fresh --force --seed --no-interaction

    if [ "$PROCUREMENT" -eq 1 ]; then
        say "Seeding the procurement fixture"
        "${COMPOSE[@]}" exec -T "${EXEC_USER[@]}" app \
            php artisan db:seed --class=ProcurementSeeder --force --no-interaction
    fi
fi

# Cached config and compiled views survive a rebuild of the data underneath them
# and produce failures that have nothing to do with the seed.
say "Clearing caches"
"${COMPOSE[@]}" exec -T "${EXEC_USER[@]}" app bash -c \
    'php artisan config:clear -q; php artisan route:clear -q; php artisan view:clear -q' \
    || warn "could not clear caches"

cat <<'EOF'

  Development database rebuilt.

    App:    http://localhost:8000
    Login:  karimi@nioc.example / password

  The fixture is three projects across eight companies, two employers, two
  standards packs (IPS and ISO), a competing EPC and a vendor - the shape
  EpccDemoSeeder documents at the top of the file.

EOF
