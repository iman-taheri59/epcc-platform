#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# EPCC Platform — one-command test runner for any environment.
#
# Runs every suite the current machine CAN run and says loudly what it
# skipped and why. The full-stack suites (rule engine, Laravel/RLS) need
# PHP; on a Docker-equipped host use scripts/dev-test.sh instead — this
# script is the degraded-environment runner (sandboxes without Docker).
#
#   bash scripts/run-tests.sh
# ---------------------------------------------------------------------------
set -uo pipefail
cd "$(dirname "$0")/.."
fail=0
skip=0
hdr () { printf '\n\033[1m== %s ==\033[0m\n' "$1"; }
skipmsg () { printf '  SKIP - %s\n' "$1"; skip=$((skip+1)); }

# 1. Standards packs + reference packs (Python; venv so PEP 668 hosts work).
hdr "Standards packs (schema, bilingual parity, ADR-002 seams, reference packs)"
if command -v python3 >/dev/null 2>&1; then
  PY=python3
  if ! "$PY" -c "import jsonschema" >/dev/null 2>&1; then
    if [ ! -x .cache/ve/bin/python ]; then
      "$PY" -m venv .cache/ve >/dev/null 2>&1 || true
      .cache/ve/bin/pip install -q jsonschema >/dev/null 2>&1 || true
    fi
    [ -x .cache/ve/bin/python ] && PY=.cache/ve/bin/python
  fi
  "$PY" standards-packs/validate_packs.py || fail=1
else
  skipmsg "python3 not installed"
fi

# 2. Rule-engine conformance corpus (needs PHP).
hdr "Rule-engine conformance corpus (language-neutral contract)"
if command -v php >/dev/null 2>&1; then
  php packages/standards-engine/conformance/run.php || fail=1
  php scripts/tests/test_material_screening.php || fail=1
  php scripts/screen-material.php docs/examples/material-screening-synthetic.json >/dev/null || fail=1
else
  skipmsg "php not installed (need PHP 8.4, see compose.yaml)"
fi

# 3. Full Laravel suite against real PostgreSQL (needs PHP + vendor + DB).
hdr "Laravel application suite (PHPUnit + PostgreSQL RLS)"
if command -v php >/dev/null 2>&1 && [ -f app/vendor/autoload.php ]; then
  (cd app && php artisan test 2>&1 | tail -6) || fail=1
else
  skipmsg "run 'composer install' in app/ with PHP 8.4, or use scripts/dev-test.sh (Docker)"
fi

# 4. HTML prototype suites (jsdom).
if command -v node >/dev/null 2>&1 && [ -d node_modules/jsdom ]; then
  for t in render sidebar piping-workspace mto-acceptance-preview order-gap-board; do
    hdr "HTML prototype — ${t} (jsdom)"
    node "prototype/test/${t}.test.js" || fail=1
  done
elif command -v node >/dev/null 2>&1; then
  hdr "HTML prototype suites (jsdom)"
  skipmsg "npm install at repo root first"
else
  hdr "HTML prototype suites (jsdom)"
  skipmsg "node not installed"
fi

printf '\n'
if [ "$fail" -eq 0 ]; then
  printf '\033[32mALL RUNNABLE SUITES PASSED\033[0m (%d skipped)\n' "$skip"
else
  printf '\033[31mFAILURES ABOVE\033[0m (%d skipped)\n' "$skip"
fi
exit $fail
