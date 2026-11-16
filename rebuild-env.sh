#!/usr/bin/env bash
# Compatibility entry point for sandbox/runtime restoration.
# The implementation is centralized in restore.sh so neither entry point can
# reset a database or seed by default.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec bash "$ROOT/restore.sh" "$@"
