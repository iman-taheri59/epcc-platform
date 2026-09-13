#!/usr/bin/env bash
# One command to validate everything in the repo.
set -uo pipefail
cd "$(dirname "$0")/../.."
fail=0
hdr () { printf '\n\033[1m== %s ==\033[0m\n' "$1"; }

hdr "Standards packs (schema, bilingual parity, ADR-002 seams)"
python3 standards-packs/validate_packs.py || fail=1

hdr "Rule-engine conformance corpus (language-neutral contract)"
if command -v php >/dev/null 2>&1; then
  php packages/standards-engine/conformance/run.php || fail=1
else
  echo "  SKIP - php not installed"
fi

hdr "Laravel application"
if command -v php >/dev/null 2>&1 && [ -f app/vendor/autoload.php ]; then
  (cd app && php artisan test 2>&1 | tail -4) || fail=1
else
  echo "  SKIP - run 'composer install' in app/ first"
fi

hdr "HTML prototype - render matrix"
node prototype/test/render.test.js || fail=1

hdr "HTML prototype - sidebar (jsdom)"
if [ -d node_modules/jsdom ]; then
  node prototype/test/sidebar.test.js || fail=1
else
  echo "  SKIP - npm install jsdom"
fi

printf '\n'
if [ "$fail" -eq 0 ]; then printf '\033[32mALL SUITES PASSED\033[0m\n'; else printf '\033[31mFAILURES ABOVE\033[0m\n'; fi
exit $fail
