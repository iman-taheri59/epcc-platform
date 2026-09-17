#!/usr/bin/env bash
# Mutation harness.
#
# A test suite that is green on its first run has proven nothing yet: it may be
# asserting things the code cannot violate. This breaks the code on purpose,
# one edit at a time, and demands that the suite notice.
#
# A mutant that SURVIVES is the finding - it means the rule it embodies is not
# actually pinned by any test.
#
# Two hard-won details:
#   - strip ANSI before grepping the result, or every line "matches"
#   - guard every substitution with `diff -q`: a pattern that silently fails to
#     apply produces a green run that looks like a killed mutant
set -uo pipefail

cd "$(dirname "$0")/app" || exit 1

PASS=0
SURVIVED=0
SKIPPED=0
declare -a SURVIVORS=()

# mutate <id> <file> <python-replacement-expression> <test-filter> <description>
mutate() {
  local id="$1" file="$2" expr="$3" filter="$4" desc="$5"
  local backup
  backup="$(mktemp)"
  cp "$file" "$backup"

  MUT_FILE="$file" MUT_EXPR="$expr" python3 - <<'PY'
import os, sys
path = os.environ['MUT_FILE']
src = open(path, encoding='utf-8').read()
old, new = eval(os.environ['MUT_EXPR'])
if old not in src:
    sys.exit(3)
open(path, 'w', encoding='utf-8').write(src.replace(old, new, 1))
PY
  local rc=$?

  if [ $rc -eq 3 ] || diff -q "$file" "$backup" >/dev/null 2>&1; then
    # The pattern did not apply. Reporting this as a killed mutant would be a
    # lie: nothing was ever broken.
    echo "  SKIP $id  (pattern did not apply) - $desc"
    SKIPPED=$((SKIPPED + 1))
    cp "$backup" "$file"; rm -f "$backup"
    return
  fi

  local out
  out="$(php artisan test --filter="$filter" 2>&1 | sed 's/\x1b\[[0-9;]*m//g')"

  if echo "$out" | grep -qE '(FAILED|failed|ERROR|Error)'; then
    echo "  kill $id  - $desc"
    PASS=$((PASS + 1))
  else
    echo "  LIVE $id  *** SURVIVED *** - $desc"
    SURVIVED=$((SURVIVED + 1))
    SURVIVORS+=("$id: $desc")
  fi

  cp "$backup" "$file"; rm -f "$backup"
}

echo "=== Phase 3 mutants: joints, compatibility, dia-inch ==="

C=app/Support/Piping/PortCompatibility.php
J=app/Services/JointService.php
S=app/Services/ComponentService.php
F=JointTest

# --- the dia-inch convention: the commercially expensive one ---------------
mutate M57 "$C" "('return min(\$sizeA, \$sizeB);', 'return max(\$sizeA, \$sizeB);')" "$F" \
  "dia-inch takes the LARGER diameter"

mutate M58 "$C" "('''            // Not zero. Zero is a real dia-inch value that sums silently into
            // a progress total; null forces the caller to decide.
            return null;''', '''            return 0.0;''')" "$F" \
  "dia-inch returns 0.0 instead of null for an unsized port"

mutate M59 "$J" "('if (! \$joint->isWeld()) {\n            return null;\n        }', '')" "$F" \
  "flanged joints contribute dia-inch"

# --- compatibility verdicts -------------------------------------------------
mutate M60 "$C" "('} elseif (abs(\$sizeA - \$sizeB) > 0.0001) {', '} elseif (false) {')" "$F" \
  "size mismatch no longer refused"

mutate M61 "$C" "('return Verdict::make(Verdict::INVALID, \$reasons);', 'return Verdict::make(Verdict::WARNING, \$reasons);')" "$F" \
  "INVALID downgraded to WARNING"

mutate M62 "$C" "('''        return ['reasons' => \$reasons, 'fatal' => true];
    }

    /**
     * Does this joint get a number?''', '''        return ['reasons' => \$reasons, 'fatal' => false];
    }

    /**
     * Does this joint get a number?''')" "$F" \
  "BW-to-FL no longer fatal"

mutate M63 "$C" "('if (\$wallA === null || \$wallB === null || abs(\$wallA - \$wallB) > 0.0001) {', 'if (true) {')" "$F" \
  "schedule warning compares names, not resolved walls (STD vs 40 nags)"

mutate M64 "$C" "('if (\$a->id !== null && \$a->id === \$b->id) {', 'if (false) {')" "$F" \
  "a port may be joined to itself"

# --- numbering --------------------------------------------------------------
mutate M65 "$J" "('->max(\x27joint_seq\x27) ?? 0) + 1;', '->count()) + 1;')" "$F" \
  "next joint number uses count()+1, reissuing deleted numbers"

mutate M66 "$J" "('\x27joint_seq\x27 => \$numbered ? \$this->nextSeq(\$line) : null,', '\x27joint_seq\x27 => \$this->nextSeq(\$line),')" "$F" \
  "flanged joints get numbers too"

mutate M67 "$J" "('\x27numbering_policy\x27 => \$numbered\n                    ? Joint::NUMBERING_REQUIRED\n                    : Joint::NUMBERING_NOT_APPLICABLE,', '\x27numbering_policy\x27 => Joint::NUMBERING_REQUIRED,')" "$F" \
  "every joint claims to need a number"

# --- the ordered pair -------------------------------------------------------
mutate M68 "$J" "('return strcmp((string) \$first->id, (string) \$second->id) < 0', 'return strcmp((string) \$first->id, (string) \$second->id) > 0')" "$F" \
  "port pair sorted the wrong way"

# --- fit-up evidence --------------------------------------------------------
mutate M69 "$J" "('if (\$code === \x27\x27) {', 'if (false) {')" "$F" \
  "an empty fitter code is accepted"

mutate M70 "$J" "('\x27fitted_at\x27 => \$at ?? now(),', '\x27fitted_at\x27 => \$at,')" "$F" \
  "fit-up recorded with no timestamp"

mutate M71 "$J" "('if (\$joint->isFitted()) {', 'if (false) {')" "$F" \
  "a fitted joint may be separated, destroying the record"

# --- separation and removal -------------------------------------------------
mutate M72 "$J" "('->update([\x27is_connected\x27 => false]);', '->update([\x27is_connected\x27 => true]);')" "$F" \
  "separating a joint leaves the ports closed"

mutate M73 "$S" "('foreach (\$this->joints->jointsTouching(\$component) as \$joint) {\n                \$this->joints->separate(\$joint, \$actor);\n            }', '')" "$F" \
  "removing a component no longer reopens its neighbour (the phase 2 hole)"

# --- frozen attributes ------------------------------------------------------
mutate M74 "$J" "('\x27thickness_mm\x27 => \$this->wallFor(\$freshA),', '\x27thickness_mm\x27 => null,')" "$F" \
  "joint wall thickness never recorded"

mutate M75 "$J" "('\x27compatibility_notes\x27 => \$verdict->toJson(),', '\x27compatibility_notes\x27 => null,')" "$F" \
  "warning reasons discarded"

# --- guards -----------------------------------------------------------------
mutate M76 "$J" "('if (\$componentA->piping_line_id !== \$componentB->piping_line_id) {', 'if (false) {')" "$F" \
  "ports on different lines may be joined"

mutate M77 "$J" "('if (\$verdict->isInvalid()) {', 'if (false) {')" "$F" \
  "an INVALID pair is recorded rather than refused"

mutate M78 "$J" "('if (\$port->is_connected) {', 'if (false) {')" "$F" \
  "a connected port may be joined again"

echo
echo "=== killed: $PASS   survived: $SURVIVED   skipped: $SKIPPED ==="
if [ ${#SURVIVORS[@]} -gt 0 ]; then
  echo "SURVIVORS:"
  printf '  %s\n' "${SURVIVORS[@]}"
fi
[ "$SURVIVED" -eq 0 ] && [ "$SKIPPED" -eq 0 ]
