#!/usr/bin/env bash
# Safe root entry point for rebuilding the Arena sandbox runtime.
# Default: restore runtime/dependencies, inspect DB, and do not migrate or seed.
# --apply: after the preflight, apply pending migrations only.
set -Eeuo pipefail
ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
APPLY_MIGRATIONS=0
QUICK=0

usage() {
  cat <<'EOF'
Usage: bash restore.sh [--quick] [--apply]

Default: rebuild runtime/dependencies and inspect the local database, without
         applying migrations or seed data.
--apply: apply pending Laravel migrations only (never migrate:fresh or seed).
--quick: skip apt package installation; required tools must already exist.
EOF
}

while (($#)); do
  case "$1" in
    --apply) APPLY_MIGRATIONS=1 ;;
    --quick) QUICK=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

# Keep one implementation of the environment/DB safety checks. The underlying
# script uses --apply to permit runtime changes; --no-migrate gives this entry
# point its safe default while retaining the explicit opt-in for migrations.
RUN_ARGS=(--apply)
if (( APPLY_MIGRATIONS == 0 )); then
  RUN_ARGS+=(--no-migrate)
fi
if (( QUICK == 1 )); then
  RUN_ARGS+=(--quick)
fi
exec bash "$ROOT/scripts/rebuild-arena-sandbox.sh" "${RUN_ARGS[@]}"
