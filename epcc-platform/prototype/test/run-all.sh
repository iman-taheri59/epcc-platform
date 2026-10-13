#!/usr/bin/env bash
# Full validation. Missing prerequisites/skipped PHPUnit tests are NOT success.
set -uo pipefail
cd "$(dirname "$0")/../.."
fail=0
hdr () { printf '\n\033[1m== %s ==\033[0m\n' "$1"; }

for command in python3 php node pdffonts pdftotext; do
  command -v "$command" >/dev/null 2>&1 || { echo "MISSING: $command" >&2; fail=1; }
done
command -v chromium >/dev/null 2>&1 || command -v chromium-browser >/dev/null 2>&1 || { echo "MISSING: Chromium (PDF tests)" >&2; fail=1; }
[[ -f app/vendor/autoload.php ]] || { echo "MISSING: composer install in app/" >&2; fail=1; }
python3 -c 'import jsonschema' 2>/dev/null || { echo "MISSING: Python jsonschema" >&2; fail=1; }
node -e "require('jsdom')" 2>/dev/null || { echo "MISSING: npm ci at project root" >&2; fail=1; }
if [[ "$fail" != 0 ]]; then
  echo "INCOMPLETE: install prerequisites; no full-suite success can be claimed." >&2
  exit 2
fi

fixtures=$(mktemp -d -t epcc-browser-XXXXXXXX)
trap 'rm -rf -- "$fixtures"' EXIT
export EPCC_BROWSER_FIXTURE_DIR="$fixtures"

hdr "Reference validator regression tests"
python3 -m unittest discover -s standards-packs/tests -v || fail=1
hdr "Operational safety regression tests"
python3 -m unittest discover -s scripts/tests -v || fail=1
hdr "Standards packs (schema, bilingual parity, ADR-002 seams)"
python3 standards-packs/validate_packs.py || fail=1
hdr "Rule-engine conformance corpus"
php packages/standards-engine/conformance/run.php || fail=1
hdr "Laravel application (PostgreSQL test database only)"
(cd app && php vendor/bin/phpunit --fail-on-skipped --fail-on-incomplete) || fail=1
hdr "HTML prototype render matrix and sidebar"
node prototype/test/render.test.js || fail=1
node prototype/test/sidebar.test.js || fail=1
hdr "Application JavaScript on fresh authenticated FA/EN HTML"
for lang in fa en; do
  export SB_FIXTURE="$fixtures/documents-$lang.html"
  export TT_FIXTURE="$SB_FIXTURE"
  for test in app/tests/Browser/*.cjs; do
    node "$test" || fail=1
  done
done
printf '\n'
if [[ "$fail" == 0 ]]; then
  printf '\033[32mALL CONFIGURED SUITES PASSED\033[0m\n'
else
  printf '\033[31mFAILURES OR INCOMPLETE CHECKS ABOVE\033[0m\n'
fi
exit "$fail"
