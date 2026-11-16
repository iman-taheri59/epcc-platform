#!/usr/bin/env bash
# Run the two real integration harnesses after the complete PHPUnit suite.
# This is CI-only: it creates and destroys a fresh, private test cluster, never
# the development/test database or any user/customer database.
set -euo pipefail

if [[ "${GITHUB_ACTIONS:-}" != "true" || -z "${GITHUB_WORKSPACE:-}" || -z "${GITHUB_RUN_ID:-}" || -z "${GITHUB_RUN_ATTEMPT:-}" ]]; then
  echo 'Refusing to run outside GitHub Actions with an identified runner workspace.' >&2
  exit 2
fi

ROOT="$GITHUB_WORKSPACE"
APP="$ROOT/app"
PG_BIN="/usr/lib/postgresql/17/bin"
PORT=5545
SUFFIX="${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
DATA="/tmp/epcc-app-journal-pg-${SUFFIX}"
SOCKET="/tmp/epcc-app-journal-sock-${SUFFIX}"
EVIDENCE_DIR="$ROOT/docs/evidence/2026-10-20-inspection-notification-time-ui"
EVIDENCE="${EPCC_APP_JOURNAL_EVIDENCE:-$EVIDENCE_DIR/mto-journal-concurrency.json}"

[[ -d "$APP" && -f "$APP/vendor/autoload.php" ]] || {
  echo 'Laravel dependencies are missing; install Composer dependencies first.' >&2
  exit 2
}
[[ -x "$PG_BIN/initdb" && -x "$PG_BIN/pg_ctl" && -x "$PG_BIN/psql" ]] || {
  echo 'PostgreSQL 17 binaries are required.' >&2
  exit 2
}
[[ "$DATA" == "/tmp/epcc-app-journal-pg-${SUFFIX}" ]]
[[ "$SOCKET" == "/tmp/epcc-app-journal-sock-${SUFFIX}" ]]

BROWSER=""
for candidate in google-chrome-stable google-chrome chromium chromium-browser headless-shell; do
  if BROWSER="$(command -v "$candidate" 2>/dev/null)"; then
    break
  fi
done
[[ -n "$BROWSER" ]] || {
  echo 'No installed Chromium/Chrome executable found for the notification time-UI harness.' >&2
  exit 2
}
export EPCC_CHROMIUM_PATH="$BROWSER"

cleanup() {
  sudo -u postgres "$PG_BIN/pg_ctl" -D "$DATA" -m fast -w stop >/dev/null 2>&1 || true
  sudo rm -rf -- "$DATA" "$SOCKET"
}
trap cleanup EXIT

sudo install -d -m 0755 -o postgres -g postgres "$DATA" "$SOCKET"
sudo -u postgres "$PG_BIN/initdb" -D "$DATA" -U postgres \
  --auth-local=trust --auth-host=reject --no-instructions
sudo tee -a "$DATA/postgresql.conf" >/dev/null <<EOF
listen_addresses = ''
port = $PORT
unix_socket_directories = '$SOCKET'
EOF
sudo -u postgres "$PG_BIN/pg_ctl" -D "$DATA" -w -l "$DATA/postgresql.log" start

admin() {
  local database="$1"
  shift
  sudo -u postgres "$PG_BIN/psql" -h "$SOCKET" -p "$PORT" -d "$database" -v ON_ERROR_STOP=1 "$@"
}

admin postgres <<'SQL'
CREATE ROLE epcc LOGIN PASSWORD 'epcc_dev' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE DATABASE epcc_test OWNER epcc;
SQL
admin epcc_test -c 'CREATE EXTENSION btree_gist'

DATA_DIRECTORY="$(admin postgres -Atc 'SHOW data_directory')"
[[ "$(basename "$DATA_DIRECTORY")" == epcc-app-journal-pg-* ]]
[[ -z "$(admin postgres -Atc 'SHOW listen_addresses')" ]]
ROLE_POSTURE="$(admin postgres -Atc "SELECT rolsuper || '/' || rolbypassrls || '/' || rolcreatedb || '/' || rolcreaterole FROM pg_roles WHERE rolname='epcc'")"
[[ "$ROLE_POSTURE" == false/false/false/false ]]

export APP_ENV=testing DB_CONNECTION=pgsql DB_URL=
export DB_HOST="$SOCKET" DB_PORT="$PORT" DB_DATABASE=epcc_test
export DB_USERNAME=epcc DB_PASSWORD=epcc_dev
mkdir -p "$EVIDENCE_DIR"

cd "$APP"
php artisan config:clear --no-interaction >/dev/null 2>&1 || true
php artisan migrate:fresh --force --seed --no-interaction
php artisan db:seed --class=ProcurementSeeder --force --no-interaction

cd "$ROOT"
EPCC_APP_JOURNAL_TEST=isolated \
EPCC_APP_JOURNAL_SOCKET="$SOCKET" \
EPCC_APP_JOURNAL_PORT="$PORT" \
EPCC_APP_JOURNAL_EVIDENCE="$EVIDENCE" \
  python3 scripts/tests/test_mto_journal_concurrency.py

(cd "$APP" && EPCC_TIME_UI_EVIDENCE=1 vendor/bin/phpunit \
  --colors=never --filter InspectionNotificationTimeUiTest \
  --log-junit "$EVIDENCE_DIR/focused-phpunit.xml")
python3 - "$EVIDENCE_DIR/focused-phpunit.xml" <<'PY'
import sys
import xml.etree.ElementTree as ET

root = ET.parse(sys.argv[1]).getroot()
suites = root.findall('testsuite')
if root.tag == 'testsuite':
    suites = [root]
tests = sum(int(suite.get('tests', 0)) for suite in suites)
failures = sum(int(suite.get('failures', 0)) for suite in suites)
errors = sum(int(suite.get('errors', 0)) for suite in suites)
skipped = sum(int(suite.get('skipped', 0)) for suite in suites)
print(f'Focused UI fixture: {tests} tests, {failures} failures, {errors} errors, {skipped} skipped')
if tests < 4 or failures or errors or skipped:
    raise SystemExit('Focused Laravel fixture must collect at least four tests with no failures, errors, or skips.')
PY

python3 scripts/tests/inspection_notification_time_ui.py
