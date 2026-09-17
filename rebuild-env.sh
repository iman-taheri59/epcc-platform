#!/usr/bin/env bash
#
# Rebuild the whole development environment after a sandbox reset.
#
# The sandbox has now been wiped 33 times. Each time, php, vendor/, PostgreSQL,
# the database and the PDF toolchain all vanish together, and rebuilding them by
# hand costs ten minutes and risks forgetting one - which is worse than losing
# it, because a MISSING PDF TOOLCHAIN MAKES 13 TESTS SILENTLY SKIP while the
# suite still reports green.
#
# Usage:  bash rebuild-env.sh          # packages, vendor, database, seed
#         bash rebuild-env.sh --quick  # skip apt (packages already present)
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP="$ROOT/app"
QUICK="${1:-}"

say () { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }

# ---------------------------------------------------------------- packages --
if [ "$QUICK" != "--quick" ]; then
  say "Installing system packages"
  sudo apt-get update -qq
  sudo apt-get install -y -qq \
    php-cli php-mbstring php-xml php-curl php-zip php-sqlite3 php-intl php-bcmath php-pgsql \
    postgresql postgresql-client \
    chromium poppler-utils fonts-vazirmatn
fi

php -v | head -1

if ! command -v composer >/dev/null 2>&1; then
  say "Installing Composer"
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/tmp --filename=composer >/dev/null
  sudo mv /tmp/composer /usr/local/bin/composer
fi
composer --version

# ---------------------------------------------------------------- postgres --
say "Starting PostgreSQL"
sudo service postgresql start >/dev/null 2>&1 || true
for _ in $(seq 1 20); do
  pg_isready -h 127.0.0.1 >/dev/null 2>&1 && break
  sleep 1
done
pg_isready -h 127.0.0.1

# ------------------------------------------------------------------ vendor --
# vendor/ is a symlink into ~/.cache, which is excluded from snapshots by name.
say "Restoring vendor/"
mkdir -p /home/user/.cache/epcc-vendor
if [ ! -L "$APP/vendor" ]; then
  rm -rf "$APP/vendor"
  ln -s /home/user/.cache/epcc-vendor "$APP/vendor"
fi
cd "$APP"
composer install --no-interaction --no-progress 2>&1 | tail -3

# ---------------------------------------------------------------- database --
say "Ensuring role and databases"
PW=$(grep '^DB_PASSWORD=' "$APP/.env" | cut -d= -f2-)
DBU=$(grep '^DB_USERNAME=' "$APP/.env" | cut -d= -f2-)
DBN=$(grep '^DB_DATABASE=' "$APP/.env" | cut -d= -f2-)

sudo -u postgres psql -tAc "SELECT 1 FROM pg_roles WHERE rolname='$DBU'" | grep -q 1 \
  || sudo -u postgres psql -q -c "CREATE ROLE $DBU LOGIN PASSWORD '$PW';"

for db in "$DBN" epcc_test; do
  sudo -u postgres psql -tAc "SELECT 1 FROM pg_database WHERE datname='$db'" | grep -q 1 \
    || sudo -u postgres psql -q -c "CREATE DATABASE $db OWNER $DBU;"
done

say "Migrating and seeding"
php artisan migrate:fresh --force 2>&1 | tail -2
php artisan db:seed --class=EpccDemoSeeder --force 2>&1 | tail -1
php artisan db:seed --class=AuthSeeder --force 2>&1 | tail -1

# ------------------------------------------------------------------ verify --
say "Verifying the toolchain"
for bin in chromium pdftotext pdffonts; do
  command -v "$bin" >/dev/null 2>&1 \
    && echo "  ok    $bin" \
    || echo "  MISSING  $bin  <- PDF tests will SILENTLY SKIP"
done

say "Ready"
cat <<'EOF'
  Serve:   cd app && php artisan serve --host=0.0.0.0 --port=8000
  Login:   karimi@nioc.example / password
  Tests:   cd app && php artisan test
  Packs:   python3 standards-packs/validate_packs.py
EOF
