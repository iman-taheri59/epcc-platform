#!/usr/bin/env bash
# Rebuild the RUNTIME after a sandbox reset. Source is never the problem;
# PHP, PostgreSQL, Composer and app/vendor are what disappear.
# Usage: bash restore.sh   (idempotent, safe to re-run)
set -euo pipefail
cd "$(dirname "$0")"
say(){ printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

if ! command -v php >/dev/null; then
  say "Installing PHP 8.4 + PostgreSQL 17"
  sudo apt-get update -qq
  sudo apt-get install -y -qq php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml \
      php8.4-curl php8.4-zip php8.4-intl postgresql postgresql-contrib unzip
fi

# PDF export (MC certificates, transmittal sheets) shells out to headless
# Chromium, and Persian needs a font that actually has the joining forms.
# Without fonts-vazirmatn the PDFs still generate - as boxes or disconnected
# letters, which is the silent failure mode risk IR-R2 is about.
if ! command -v chromium >/dev/null && ! command -v chromium-browser >/dev/null; then
  say "Installing Chromium + Vazirmatn for PDF export"
  sudo apt-get install -y -qq chromium fonts-vazirmatn poppler-utils
fi

if ! command -v composer >/dev/null; then
  say "Installing Composer"
  curl -sS https://getcomposer.org/installer -o /tmp/ci.php
  sudo php /tmp/ci.php --install-dir=/usr/local/bin --filename=composer --quiet
fi

# The document register accepts CAD and scanned drawing sets. PHP's stock 2M
# upload cap rejects nearly every real deliverable, and it fails SILENTLY -
# an invalid UploadedFile with no exception. Re-applied on every restore
# because /etc is wiped by a sandbox reset along with PHP itself.
say "Applying PHP limits for document uploads"
sudo tee /etc/php/8.4/cli/conf.d/99-epcc.ini >/dev/null <<'INI'
upload_max_filesize = 100M
post_max_size = 110M
memory_limit = 512M
max_file_uploads = 30
max_execution_time = 300
INI

say "Starting PostgreSQL"
sudo pg_ctlcluster 17 main start 2>/dev/null || true
for i in $(seq 20); do pg_isready -q && break; sleep 1; done

say "Ensuring role + databases"
sudo -u postgres psql -tAc "select 1 from pg_roles where rolname='epcc'" | grep -q 1 \
  || sudo -u postgres psql -qc "create role epcc login password 'epcc_dev'"
for db in epcc epcc_test; do
  sudo -u postgres psql -tAc "select 1 from pg_database where datname='$db'" | grep -q 1 \
    || sudo -u postgres createdb -O epcc "$db"
  sudo -u postgres psql -qc "grant all on schema public to epcc" "$db" >/dev/null
done

# vendor MUST be a symlink into .cache: a real ./app/vendor blows the 10k-file
# workspace snapshot cap and truncates the repo SILENTLY. Never "fix" this by
# checking vendor in.
say "Restoring vendor symlink"
mkdir -p /home/user/.cache/epcc-vendor
[ -L app/vendor ] || { rm -rf app/vendor; ln -s /home/user/.cache/epcc-vendor app/vendor; }
( cd app && composer install --no-interaction --no-progress -q )

say "Migrating + seeding"
( cd app && php artisan migrate:fresh --force --seed )
# The procurement fixture is a separate seeder so the core demo stays small.
( cd app && php artisan db:seed --class=ProcurementSeeder --force )

say "Clearing caches"
( cd app && php artisan config:clear -q; php artisan route:clear -q; php artisan view:clear -q )

# Filament ships its CSS/JS into public/ via a publish step, and public/ is NOT
# where composer puts them - so a fresh vendor install leaves /admin rendering
# as unstyled HTML with no JS. The panel still returns 200, which is exactly
# why this is easy to miss: nothing fails, it just looks broken.
say "Publishing Filament assets"
( cd app && php artisan filament:assets -q )

# The jsdom browser harnesses live in node_modules at /home/user, which is
# snapshot-excluded and therefore wiped by every reset. Without this the
# harnesses fail with MODULE_NOT_FOUND and print NOTHING, which reads exactly
# like "0 tests to run" - a silent loss of 345 assertions.
if ! node -e "require('/home/user/node_modules/jsdom')" 2>/dev/null; then
  say "Reinstalling jsdom for the browser harnesses"
  ( cd /home/user && npm install jsdom --no-audit --no-fund --silent )
fi

cat <<'MSG'

Runtime restored. Start the server with:

    cd app && php artisan serve --host=0.0.0.0 --port=8000

Then sign in as karimi@nioc.example / password  (see REBUILD.md §0a-3).
MSG
