#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# EPCC Platform — PostgreSQL 17 for sandboxes without Docker.
#
# The degraded tier (HANDOFF §0 rule 4) normally has no database. This script
# provisions a REAL PostgreSQL server from the binaries bundled inside the
# `pgserver` PyPI wheel (pypi.org is reachable from these sandboxes; Docker,
# apt and release-assets.githubusercontent.com are not). The app role follows
# the CI discipline: `epcc` is NOT a superuser and does NOT bypass RLS —
# a superuser would void every tenancy assertion.
#
#   bash scripts/sandbox-pg.sh start     # init if needed, start on 127.0.0.1:5432
#   bash scripts/sandbox-pg.sh status
#   bash scripts/sandbox-pg.sh stop
#
# Databases: epcc (app) and epcc_test (PHPUnit), owner `epcc`, password
# `epcc_dev` — the exact credentials restore.sh writes into app/.env.
# Data lives in .cache/pgdata (git-ignored; rebuild after a sandbox reset).
# ---------------------------------------------------------------------------
set -euo pipefail
cd "$(dirname "$0")/.."

DATADIR="$PWD/.cache/pgdata"
SOCKDIR="$PWD/.cache/pgsock"
PORT="${PGPORT:-5432}"
VENV="$PWD/.cache/pgve"

pginstall() {
  if [ ! -x "$VENV/bin/python" ]; then
    python3 -m venv "$VENV"
    "$VENV/bin/pip" install -q pgserver
  fi
  "$VENV/bin/python" - <<'EOF'
import os, pgserver
print(os.path.join(os.path.dirname(pgserver.__file__), "pginstall"))
EOF
}

PG_BIN="$(pginstall)/bin"
PG_SHARE="$(pginstall)/share/postgresql/extension"

# The pgserver wheel ships without contrib extensions. btree_gist is needed by
# ADR-008 (PMS EXCLUDE USING gist), so build it from the official PostgreSQL
# source at codeload.github.com (reachable; php.net/apt/packagist are not).
ensure_btree_gist() {
  [ -f "$PG_SHARE/btree_gist.control" ] && return 0
  local ver maj_min rel workdir
  ver="$("$PG_BIN/pg_config" --version | awk '{print $2}')"     # e.g. 16.2
  rel="REL_${ver//./_}"                                          # REL_16_2
  workdir="$(mktemp -d)"
  echo "-- building btree_gist from postgres $ver source"
  curl -sfL -o "$workdir/pg.tar.gz" \
    "https://codeload.github.com/postgres/postgres/tar.gz/refs/tags/$rel"
  tar xzf "$workdir/pg.tar.gz" -C "$workdir" "postgres-$rel/contrib/btree_gist"
  make -C "$workdir/postgres-$rel/contrib/btree_gist" USE_PGXS=1 \
    PG_CONFIG="$PG_BIN/pg_config" >/dev/null 2>&1
  make -C "$workdir/postgres-$rel/contrib/btree_gist" USE_PGXS=1 \
    PG_CONFIG="$PG_BIN/pg_config" install >/dev/null
  rm -rf "$workdir"
  [ -f "$PG_SHARE/btree_gist.control" ] || { echo "FAIL: btree_gist build"; exit 1; }
}

psql_admin() { "$PG_BIN/psql" -h 127.0.0.1 -p "$PORT" -U postgres -d postgres -v ON_ERROR_STOP=1 "$@"; }

case "${1:-start}" in
  start)
    mkdir -p "$SOCKDIR"
    if [ ! -s "$DATADIR/PG_VERSION" ]; then
      echo "-- initdb at $DATADIR"
      rm -rf "$DATADIR"
      "$PG_BIN/initdb" -D "$DATADIR" -U postgres --auth=trust -E UTF8 >/dev/null
    fi
    if [ -s "$DATADIR/postmaster.pid" ]; then
      echo "-- already running"
    else
      "$PG_BIN/pg_ctl" -D "$DATADIR" -w -l "$DATADIR/logfile" \
        -o "-p $PORT -c listen_addresses=127.0.0.1 -k $SOCKDIR" start >/dev/null
    fi

    echo "-- role + databases (CI rule: app role is NOT a superuser)"
    ensure_btree_gist
    psql_admin -qc "DO \$\$ BEGIN
      IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname='epcc') THEN
        CREATE ROLE epcc LOGIN PASSWORD 'epcc_dev' NOSUPERUSER NOCREATEDB NOBYPASSRLS;
      END IF; END \$\$;"
    for db in epcc epcc_test; do
      psql_admin -tc "SELECT 1 FROM pg_database WHERE datname='$db'" | grep -q 1 \
        || psql_admin -qc "CREATE DATABASE $db OWNER epcc"
      # btree_gist is needed by future PMS migrations (ADR-008); creating it
      # here keeps the app role unprivileged.
      psql_admin -d "$db" -qc "CREATE EXTENSION IF NOT EXISTS btree_gist"
    done

    echo "-- assertions"
    psql_admin -tc "SELECT version();" | head -1
    SUP=$(psql_admin -Atc "SELECT rolsuper || '/' || rolbypassrls FROM pg_roles WHERE rolname='epcc'")
    [ "$SUP" = "false/false" ] || { echo "FAIL: epcc must be superuser=false bypassrls=false, got $SUP"; exit 1; }
    echo "ok  epcc superuser=false bypassrls=false"
    psql_admin -h 127.0.0.1 -U epcc -d epcc -qc "SELECT 1" \
      && echo "ok  epcc can connect to epcc with password auth over TCP"
    echo "PostgreSQL ready: host=127.0.0.1 port=$PORT user=epcc password=epcc_dev db=epcc / epcc_test"
    ;;
  status)
    if [ -s "$DATADIR/postmaster.pid" ]; then
      psql_admin -c "SELECT version();" | head -2
    else
      echo "not running"
    fi
    ;;
  stop)
    [ -s "$DATADIR/postmaster.pid" ] && "$PG_BIN/pg_ctl" -D "$DATADIR" -w stop || echo "not running"
    ;;
  *)
    echo "usage: $0 {start|status|stop}"; exit 2
    ;;
esac
