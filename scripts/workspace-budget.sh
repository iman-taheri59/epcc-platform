#!/usr/bin/env bash
# Check the workspace snapshot budget and, only when explicitly requested,
# remove the ignored Composer dependency tree if it is the safe cleanup candidate.
#
# Usage:
#   bash scripts/workspace-budget.sh                  # read-only check
#   bash scripts/workspace-budget.sh --clean          # check, then ask before pruning
#   bash scripts/workspace-budget.sh --clean --yes    # non-interactive prune
#   bash scripts/workspace-budget.sh --help
#
# The defaults leave headroom below the platform's approximate 128 MiB / 10,000
# file snapshot caps. No source, standards, backups, or tracked files are deleted.
set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
REPO_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd -P)"
WORKSPACE_ROOT="${WORKSPACE_ROOT:-$(dirname -- "$REPO_ROOT")}"
WORKSPACE_ROOT="$(cd -- "$WORKSPACE_ROOT" && pwd -P)"

MAX_MIB=96
MAX_FILES=8000
CLEAN=0
YES=0

usage() {
    cat <<'EOF'
Usage: scripts/workspace-budget.sh [--check | --clean [--yes]] [options]

Options:
  --check             Report usage only (default); exit 1 if over budget.
  --clean             If over budget, offer to remove ignored app/vendor.
  --yes               Confirm --clean without an interactive prompt.
  --max-mib N         Maximum snapshot-visible size in MiB (default: 96).
  --max-files N       Maximum snapshot-visible file count (default: 8000).
  --help              Show this help.

Set WORKSPACE_ROOT to measure a different workspace root. Cleanup is restricted
 to this repository's ignored Composer dependency directory; it never removes
 tracked files or other workspace content.
EOF
}

fail_usage() {
    printf 'Error: %s\n\n' "$1" >&2
    usage >&2
    exit 2
}

while (($#)); do
    case "$1" in
        --check) CLEAN=0 ;;
        --clean) CLEAN=1 ;;
        --yes) YES=1 ;;
        --max-mib|--max-files)
            (($# >= 2)) || fail_usage "$1 requires a value"
            [[ "$2" =~ ^[0-9]+$ ]] || fail_usage "$1 must be a non-negative integer"
            if [[ "$1" == --max-mib ]]; then MAX_MIB="$2"; else MAX_FILES="$2"; fi
            shift
            ;;
        --help|-h) usage; exit 0 ;;
        *) fail_usage "unknown option: $1" ;;
    esac
    shift
done

(( YES == 0 || CLEAN == 1 )) || fail_usage '--yes is only valid with --clean'
MAX_BYTES=$((MAX_MIB * 1024 * 1024))

# Match the generated/cache directory exclusions used by workspace snapshots.
# Deliberately do NOT exclude vendor: Composer's app/vendor tree caused the
# previous file-count overrun, so the check must see it.
measure() {
    python3 - "$1" <<'PY'
import os
import stat
import sys
from pathlib import Path

root = Path(sys.argv[1]).resolve()
excluded_dirs = {
    '.arena', '.cache', '.local', '.mypy_cache', '.next', '.nox', '.npm',
    '.nuxt', '.output', '.parcel-cache', '.playwright-cli', '.pytest_cache',
    '.ruff_cache', '.svelte-kit', '.tox', '.turbo', '.venv', '.vite',
    '__pycache__', 'build', 'coverage', 'dist', 'node_modules', 'out', 'target',
}

def sensitive(parts):
    name = parts[-1] if parts else ''
    if name in {'.netrc', '.git-credentials'}:
        return True
    return len(parts) >= 2 and parts[-2] == '.git' and name in {'config', 'credentials'}

size = 0
count = 0
for current, dirs, files in os.walk(root, topdown=True, followlinks=False):
    current_path = Path(current)
    kept_dirs = []
    for name in dirs:
        if name in excluded_dirs:
            continue
        path = current_path / name
        try:
            info = path.lstat()
        except OSError:
            continue
        if stat.S_ISLNK(info.st_mode):
            if not sensitive(path.relative_to(root).parts):
                count += 1
                size += info.st_size
        else:
            kept_dirs.append(name)
    dirs[:] = kept_dirs

    for name in files:
        path = current_path / name
        try:
            relative = path.relative_to(root).parts
            if sensitive(relative):
                continue
            info = path.lstat()
        except OSError:
            continue
        if stat.S_ISREG(info.st_mode) or stat.S_ISLNK(info.st_mode):
            count += 1
            size += info.st_size

print(size, count)
PY
}

report() {
    python3 - "$1" "$2" "$MAX_MIB" "$MAX_FILES" <<'PY'
import sys
size, count, max_mib, max_files = map(int, sys.argv[1:])
print(f'Workspace checked: {size / (1024 * 1024):.1f} MiB, {count:,} files')
print(f'Guard thresholds: {max_mib} MiB, {max_files:,} files')
PY
}

over_budget() {
    local bytes="$1" files="$2"
    (( bytes > MAX_BYTES || files > MAX_FILES ))
}

read -r before_bytes before_files < <(measure "$WORKSPACE_ROOT")
report "$before_bytes" "$before_files"

if ! over_budget "$before_bytes" "$before_files"; then
    echo 'OK: workspace is within the guard thresholds.'
    exit 0
fi

printf 'OVER BUDGET: the workspace should be pruned before snapshotting.\n' >&2
if (( CLEAN == 0 )); then
    printf 'Run: bash %q --clean\n' "$SCRIPT_DIR/$(basename -- "${BASH_SOURCE[0]}")" >&2
    exit 1
fi

VENDOR_DIR="$REPO_ROOT/app/vendor"
if [[ ! -d "$VENDOR_DIR" || -L "$VENDOR_DIR" ]]; then
    echo 'No safe Composer vendor directory exists; no files were removed.' >&2
    exit 1
fi
if [[ ! -f "$REPO_ROOT/app/composer.json" || ! -f "$REPO_ROOT/app/composer.lock" \
    || ! -f "$VENDOR_DIR/autoload.php" || ! -f "$VENDOR_DIR/composer/installed.json" ]]; then
    echo 'app/vendor does not look like a complete Composer install; refusing to remove it.' >&2
    exit 1
fi
if ! git -C "$REPO_ROOT" check-ignore -q -- app/vendor; then
    echo 'app/vendor is not ignored by Git; refusing to remove it.' >&2
    exit 1
fi
if [[ -n "$(git -C "$REPO_ROOT" ls-files -- app/vendor)" ]]; then
    echo 'Tracked files exist under app/vendor; refusing to remove them.' >&2
    exit 1
fi

read -r vendor_bytes vendor_files < <(measure "$VENDOR_DIR")
printf 'Safe cleanup candidate: app/vendor (%s files, %.1f MiB).\n' \
    "$vendor_files" "$(python3 -c 'import sys; print(int(sys.argv[1]) / (1024 * 1024))' "$vendor_bytes")"
echo 'Composer can recreate it later from app/composer.lock.'

if (( YES == 0 )); then
    if [[ ! -r /dev/tty ]]; then
        echo 'No interactive terminal; rerun with --clean --yes to confirm.' >&2
        exit 2
    fi
    answer=''
    read -r -p 'Remove only this generated dependency directory? [y/N] ' answer </dev/tty || true
    case "${answer,,}" in
        y|yes) ;;
        *) echo 'Cancelled; no files were removed.'; exit 0 ;;
    esac
fi

rm -rf -- "$VENDOR_DIR"
printf 'Removed %s.\n' "$VENDOR_DIR"
read -r after_bytes after_files < <(measure "$WORKSPACE_ROOT")
report "$after_bytes" "$after_files"
if over_budget "$after_bytes" "$after_files"; then
    echo 'Still over budget; no other files were removed. Review large files manually.' >&2
    exit 1
fi
echo 'OK: workspace is now within the guard thresholds.'
