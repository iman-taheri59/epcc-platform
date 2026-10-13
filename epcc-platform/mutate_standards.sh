#!/usr/bin/env bash
# Mutation harness for the System tier (ADR-004 rev 2).
#
# Mutants are read from mutants_standards.txt, pipe-delimited, so that neither
# bash nor python has to quote SQL fragments containing quotes.
#
# Two rules this harness exists to honour:
#   - mutate the MIGRATION SOURCE, never the live schema: setUp() runs
#     migrate:fresh, so a constraint dropped in psql is back before the first
#     assertion (the M118 lesson)
#   - a pattern that fails to apply is a SKIP, never a pass
set -uo pipefail
cd "$(dirname "$0")/app" || exit 1

M=database/migrations/2026_09_19_190000_add_itp_stage_references.php
SVC=app/Services/ItpService.php
MODEL=app/Models/ItpStageDocument.php
STAGE=app/Models/ItpStage.php
F="StandardsLibraryTest"

PASS=0; SURVIVED=0; SKIPPED=0
declare -a SURVIVORS=()

while IFS='|' read -r id file old new desc; do
  [ -z "${id// }" ] && continue
  case "$id" in \#*) continue ;; esac

  # expand $M / $MOD
  eval "path=\"$file\""

  backup="$(mktemp)"; cp "$path" "$backup"

  MUT_PATH="$path" MUT_OLD="$old" MUT_NEW="$new" python3 - <<'PY'
import os, sys
path = os.environ['MUT_PATH']
old  = os.environ['MUT_OLD'].replace('\\n', '\n')
new  = os.environ['MUT_NEW'].replace('\\n', '\n')
src  = open(path, encoding='utf-8').read()
if old not in src:
    sys.exit(3)
open(path, 'w', encoding='utf-8').write(src.replace(old, new, 1))
PY
  rc=$?

  if [ $rc -eq 3 ] || diff -q "$path" "$backup" >/dev/null 2>&1; then
    echo "  SKIP $id  (pattern did not apply) - $desc"
    SKIPPED=$((SKIPPED+1)); cp "$backup" "$path"; rm -f "$backup"; continue
  fi

  out="$(php artisan test --filter="$F" 2>&1 | sed 's/\x1b\[[0-9;]*m//g')"

  if echo "$out" | grep -qE '(FAILED|failed|ERROR|Error)'; then
    echo "  kill $id  - $desc"; PASS=$((PASS+1))
  else
    echo "  LIVE $id  *** SURVIVED *** - $desc"
    SURVIVED=$((SURVIVED+1)); SURVIVORS+=("$id: $desc")
  fi

  cp "$backup" "$path"; rm -f "$backup"
done < ../mutants_standards.txt

echo
echo "=== killed: $PASS   survived: $SURVIVED   skipped: $SKIPPED ==="
if [ ${#SURVIVORS[@]} -gt 0 ]; then
  echo "SURVIVORS:"; printf '  %s\n' "${SURVIVORS[@]}"
fi
[ "$SURVIVED" -eq 0 ] && [ "$SKIPPED" -eq 0 ]
