#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# Run the repository's test suites inside the development container.
#
#   scripts/dev-test.sh                          every suite
#   scripts/dev-test.sh laravel                  only the Laravel suite
#   scripts/dev-test.sh packs                    standards-pack validator (python)
#   scripts/dev-test.sh engine                   rule-engine conformance corpus
#   scripts/dev-test.sh prototype                jsdom harnesses for prototype/
#
#   scripts/dev-test.sh laravel -- --filter=PipingLineTest
#   scripts/dev-test.sh laravel -- --group=rls
# Everything after `--` is handed to `php artisan test`.
#
#   scripts/dev-test.sh --allow-skips            do not fail on skipped tests
#
# ---------------------------------------------------------------------------
# THE SKIP GATE, AND WHY THIS SCRIPT EXISTS IN ADDITION TO run-all.sh
#
# PdfExportTest and VendorTransmittalTest call markTestSkipped() when no
# headless browser is on the host, and poppler's pdffonts/pdftotext when those
# are missing. The suite STILL EXITS ZERO and prints "passed". REBUILD.md §4
# records 13 tests disappearing that way, and HANDOFF.md records them coming
# back only after the toolchain was reinstalled. A green run that quietly ran
# 13 fewer tests is worse than a red one.
#
# So this script parses PHPUnit's summary line and fails the run if anything
# skipped. The container is built with the whole PDF toolchain and its Dockerfile
# refuses to build without it, so in practice the count is always zero - which
# is exactly when a gate is worth having.
# ---------------------------------------------------------------------------
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

say()  { printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }
ok()   { printf '\033[1;32m    ok\033[0m  %s\n' "$*"; }
bad()  { printf '\033[1;31m  FAIL\033[0m  %s\n' "$*"; }
warn() { printf '\033[1;33m!!  %s\033[0m\n' "$*" >&2; }
die()  { printf '\033[1;31mxx  %s\033[0m\n' "$*" >&2; exit 1; }
# Print this file's header comment as the usage text. Reading it out of the
# source is deliberate: a hand-copied usage block drifts away from the options
# actually implemented, and nobody notices until it has lied for a month.
# `-+` rather than `-\{10,\}`: mawk, the awk on a default Debian/Ubuntu host,
# does not support interval expressions, and an unmatched pattern here silently
# prints the whole script instead of the usage block.
usage() { awk 'NR>2 && /^# -+$/ {exit} NR>2 {print}' "$0"; }

SUITE="all"
ALLOW_SKIPS=0
PASSTHROUGH=()

while [ $# -gt 0 ]; do
    case "$1" in
        --allow-skips) ALLOW_SKIPS=1; shift ;;
        -h|--help)     usage; exit 0 ;;
        --)            shift; PASSTHROUGH=("$@"); break ;;
        all|laravel|packs|engine|prototype)
            [ "$SUITE" = "all" ] || die "only one suite argument at a time"
            SUITE="$1"; shift ;;
        *)             die "unknown argument: $1 (try --help)" ;;
    esac
done

if [ ${#PASSTHROUGH[@]} -gt 0 ] && [ "$SUITE" != "laravel" ]; then
    warn "arguments after -- go to 'php artisan test'; forcing SUITE=laravel"
    SUITE="laravel"
fi

command -v docker >/dev/null 2>&1 || die "docker is not installed"
if docker compose version >/dev/null 2>&1; then
    COMPOSE=(docker compose)
elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE=(docker-compose)
else
    die "neither 'docker compose' nor 'docker-compose' is available"
fi

container_id=$("${COMPOSE[@]}" ps -q app 2>/dev/null || true)
[ -n "$container_id" ] || die "the app container is not running. Start it first: scripts/dev-up.sh"
docker inspect -f '{{.State.Running}}' "$container_id" 2>/dev/null | grep -q true \
    || die "the app container is not running. Start it first: scripts/dev-up.sh"

# Match the main container's uid so test artifacts (storage blobs, logs,
# .phpunit.result.cache) stay writable on the host.
EXEC_USER=()
EPCC_UID="${EPCC_UID:-$(sed -n 's/^EPCC_UID=//p' .env 2>/dev/null | tail -1 || true)}"
EPCC_GID="${EPCC_GID:-$(sed -n 's/^EPCC_GID=//p' .env 2>/dev/null | tail -1 || true)}"
if [ -n "${EPCC_UID:-}" ]; then
    EXEC_USER=(-u "${EPCC_UID}:${EPCC_GID:-$EPCC_UID}")
fi

# run_in_container "<shell command>" - streams to the terminal AND captures.
LOG="$(mktemp -t epcc-test.XXXXXX)"
trap 'rm -f "$LOG"' EXIT

run_in_container() {
    local cmd="$1" status
    : > "$LOG"
    set +e
    "${COMPOSE[@]}" exec -T "${EXEC_USER[@]}" app bash -c "$cmd" 2>&1 | tee "$LOG"
    status=${PIPESTATUS[0]}
    set -e
    return "$status"
}

# ------------------------------------------------------------------- suites ---
failures=0

run_packs() {
    say "Standards packs (schema, bilingual parity, ADR-002 seams)"
    if run_in_container 'cd /srv/epcc && python3 standards-packs/validate_packs.py'; then
        ok "standards packs"; else bad "standards packs"; failures=$((failures + 1)); fi
}

run_engine() {
    say "Rule-engine conformance corpus (language-neutral contract)"
    if run_in_container 'cd /srv/epcc && php packages/standards-engine/conformance/run.php'; then
        ok "rule engine"; else bad "rule engine"; failures=$((failures + 1)); fi
}

run_prototype() {
    say "HTML prototype (jsdom harnesses)"
    # The harnesses need node_modules at the REPOSITORY ROOT. If jsdom is
    # missing they die with MODULE_NOT_FOUND and print nothing, which reads
    # exactly like "0 tests to run" - so check for it instead of trusting exit 0.
    if run_in_container 'cd /srv/epcc && node -e "require(\"jsdom\")" 2>/dev/null || { echo "jsdom missing - installing"; npm install --no-audit --no-fund --silent; }'; then
        :
    else
        bad "could not provide jsdom for the prototype harnesses"
        failures=$((failures + 1))
        return
    fi

    local cmd='cd /srv/epcc && node prototype/test/render.test.js && npm run test:prototype'
    if run_in_container "$cmd"; then
        ok "prototype"; else bad "prototype"; failures=$((failures + 1)); fi
}

run_laravel() {
    say "Laravel application suite (PostgreSQL + RLS)"

    local cmd='cd /srv/epcc/app && php artisan test --no-ansi'
    if [ ${#PASSTHROUGH[@]} -gt 0 ]; then
        # %q so a --filter with a regex or a path with spaces survives the hop
        # into the container's bash intact.
        cmd="$cmd $(printf ' %q' "${PASSTHROUGH[@]}")"
    fi

    local status=0
    run_in_container "$cmd" || status=$?

    # --- the skip gate -------------------------------------------------------
    local summary skipped
    summary="$(grep -E '^[[:space:]]*Tests:' "$LOG" | tail -1 || true)"
    skipped=""
    if [ -n "$summary" ]; then
        # Two summary shapes exist and the gate has to read both:
        #   Collision (artisan test):  Tests:    13 skipped, 843 passed (2998 assertions)
        #   PHPUnit   (vendor/bin):    Tests: 843, Assertions: 2998, Skipped: 13.
        # Parsing only the first would report "no skips" on the second.
        skipped="$(printf '%s' "$summary" \
            | grep -oiE '[0-9]+[[:space:]]+skipped|skipped:[[:space:]]*[0-9]+' \
            | grep -oE '[0-9]+' | head -1 || true)"
    fi

    if [ -n "$skipped" ] && [ "$skipped" -gt 0 ]; then
        if [ "$ALLOW_SKIPS" -eq 1 ]; then
            warn "$skipped test(s) SKIPPED (allowed by --allow-skips). Nearly always a missing"
            warn "PDF toolchain: chromium, poppler-utils (pdffonts/pdftotext) or Vazirmatn."
        else
            bad "$skipped test(s) SKIPPED - the suite is not proving what it claims to"
            printf '     %s\n' "$summary"
            printf '     Missing toolchain is the usual cause. Check with:\n'
            printf '       docker compose exec app bash -c "command -v chromium pdffonts pdftotext; fc-list | grep -i vazirmatn"\n'
            printf '     Re-run with --allow-skips only if you know why they skipped.\n'
            failures=$((failures + 1))
            return
        fi
    elif [ -z "$summary" ] && [ "$status" -eq 0 ]; then
        # Exit 0 with no summary line means we ran something other than the
        # suite (a typo'd filter matches no test, for instance). Say so rather
        # than report success.
        warn "no PHPUnit summary line in the output - did the filter match any test?"
    fi

    if [ "$status" -eq 0 ]; then
        ok "laravel${summary:+  ($summary)}"
    else
        bad "laravel (exit $status)"
        failures=$((failures + 1))
    fi
}

case "$SUITE" in
    packs)     run_packs ;;
    engine)    run_engine ;;
    prototype) run_prototype ;;
    laravel)   run_laravel ;;
    all)
        run_packs
        run_engine
        run_laravel
        run_prototype
        ;;
esac

printf '\n'
if [ "$failures" -eq 0 ]; then
    printf '\033[1;32mALL SUITES PASSED\033[0m (%s)\n\n' "$SUITE"
else
    printf '\033[1;31m%d SUITE(S) FAILED\033[0m (%s)\n\n' "$failures" "$SUITE"
fi
exit $((failures > 0 ? 1 : 0))
