#!/usr/bin/env bash
# Recreate the sandbox's Laravel production dependencies without apt/Docker.
# This does NOT supply pdo_pgsql/xmlreader/xmlwriter or PostgreSQL itself.
set -euo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="$ROOT/.local/php-native/bin/php"
REV='ee6dc12943e6dec90c14489ac87f227f4dc8510b'
PHAR_SHA='9a18e1a3aadbcb94c1bafd6c4a98ff931f4b43a456ef48575130466e19f05dd6'
COMPOSER_SRC="$ROOT/.local/composer-src"
COMPOSER_PHAR="$COMPOSER_SRC/composer.phar"

if [[ ! -x "$PHP" ]]; then
    bash "$ROOT/scripts/install-local-php.sh"
fi
if [[ ! -f "$ROOT/app/vendor/autoload.php" ]]; then
    if command -v composer >/dev/null; then
        COMPOSER=(composer)
        echo 'Using system Composer.'
    else
        for tool in git sha256sum; do
            command -v "$tool" >/dev/null || { echo "Missing $tool" >&2; exit 2; }
        done
        if [[ ! -f "$COMPOSER_PHAR" ]]; then
            mkdir -p "$ROOT/.local"
            git init -q "$COMPOSER_SRC"
            git -C "$COMPOSER_SRC" remote add origin https://github.com/hikarine3/docker-bedrock-wordpress.git
            git -C "$COMPOSER_SRC" sparse-checkout init --cone
            git -C "$COMPOSER_SRC" sparse-checkout set bedrock
            git -C "$COMPOSER_SRC" fetch -q --depth 1 --filter=blob:none origin "$REV"
            git -C "$COMPOSER_SRC" checkout -q --detach FETCH_HEAD
        fi
        printf '%s  %s\n' "$PHAR_SHA" "$COMPOSER_PHAR" | sha256sum --check --status || {
            echo 'Composer PHAR checksum mismatch. Refusing unverified dependency installer.' >&2
            exit 1
        }
        # The upstream mirror is third-party, not the official Composer site.
        # On a workstation prefer a separately verified official Composer 2.
        COMPOSER=("$PHP" "$COMPOSER_PHAR")
        echo 'Using pinned third-party Composer 2.6.5 mirror; prefer official Composer on workstations.' >&2
    fi
    cd "$ROOT/app"
    # The pinned sandbox PHP lacks XMLReader/XMLWriter; source installs are
    # used because the default Composer dist URLs redirect to blocked hosts.
    "${COMPOSER[@]}" install --no-dev --prefer-source --no-interaction \
        --no-progress --no-scripts --no-plugins \
        --ignore-platform-req=ext-xmlreader --ignore-platform-req=ext-xmlwriter
fi
cd "$ROOT/app"
if [[ ! -e .env ]]; then
    cp .env.example .env
    "$PHP" -r '
      $p=".env"; $s=file_get_contents($p);
      $s=str_replace("DB_CONNECTION=sqlite", "DB_CONNECTION=pgsql\nDB_HOST=127.0.0.1\nDB_PORT=5432\nDB_DATABASE=epcc\nDB_USERNAME=epcc\nDB_PASSWORD=", $s);
      $s=str_replace(["SESSION_DRIVER=database", "CACHE_STORE=database", "QUEUE_CONNECTION=database", "APP_DEBUG=true"], ["SESSION_DRIVER=file", "CACHE_STORE=file", "QUEUE_CONNECTION=sync", "APP_DEBUG=false"], $s);
      file_put_contents($p,$s);
    '
    "$PHP" artisan key:generate --force --no-interaction
fi
"$PHP" artisan package:discover --no-ansi
"$PHP" artisan --version
if ! "$PHP" -m | grep -qx pdo_pgsql; then
    echo 'WARNING: Laravel installed but database-backed pages/tests need a PHP build with pdo_pgsql and PostgreSQL.' >&2
fi
