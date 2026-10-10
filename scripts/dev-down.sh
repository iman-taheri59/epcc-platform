#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Stop the EPCC development stack.
#
#   scripts/dev-down.sh             stop and remove containers. Data survives.
#   scripts/dev-down.sh --volumes   ALSO destroy the PostgreSQL data volume and
#                                   the vendor/node_modules volumes. The next
#                                   dev-up.sh is a full first boot again.
#   scripts/dev-down.sh --rmi       also remove the built image
#
# --volumes is the answer to "my database is in a state I do not understand",
# and it is also the only way to re-run docker/postgres/init/*.sql: the official
# postgres image executes those scripts exactly once, when the data directory is
# created. Editing them and restarting changes nothing.
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

say() { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
die() { printf '\033[1;31mxx  %s\033[0m\n' "$*" >&2; exit 1; }
# Print this file's header comment as the usage text. Reading it out of the
# source is deliberate: a hand-copied usage block drifts away from the options
# actually implemented, and nobody notices until it has lied for a month.
# `-+` rather than `-\{10,\}`: mawk, the awk on a default Debian/Ubuntu host,
# does not support interval expressions, and an unmatched pattern here silently
# prints the whole script instead of the usage block.
usage() { awk 'NR>2 && /^# -+$/ {exit} NR>2 {print}' "$0"; }

VOLUMES=0 RMI=0
for arg in "$@"; do
    case "$arg" in
        --volumes|-v) VOLUMES=1 ;;
        --rmi)        RMI=1 ;;
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

ARGS=(down --remove-orphans)
[ "$VOLUMES" -eq 1 ] && ARGS+=(--volumes)
[ "$RMI" -eq 1 ]     && ARGS+=(--rmi local)

if [ "$VOLUMES" -eq 1 ]; then
    say "Stopping the stack and DELETING the database, vendor and node_modules volumes"
else
    say "Stopping the stack (volumes kept)"
fi

"${COMPOSE[@]}" "${ARGS[@]}"

if [ "$VOLUMES" -eq 1 ]; then
    cat <<'EOF'

  Everything local is gone: the epcc and epcc_test databases, the Composer and
  npm trees. Start again with:

      scripts/dev-up.sh

  docker/postgres/init/*.sql will run again on that boot, because it only ever
  runs against an empty data volume.

EOF
else
    printf '\n  Containers removed, data kept. Start again with: scripts/dev-up.sh\n\n'
fi
