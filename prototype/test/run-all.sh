#!/usr/bin/env bash
# Back-compat wrapper. The one-command runner now lives in scripts/run-tests.sh
# (it additionally covers the piping-workspace and mto-acceptance-preview jsdom
# suites and the PEP-668 venv). Kept so muscle memory and existing docs keep
# working. See HANDOFF §0 rule 4 and docs/IMPROVEMENT-PROPOSALS-EN.md.
set -euo pipefail
exec bash "$(dirname "$0")/../../scripts/run-tests.sh"
