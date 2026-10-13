#!/usr/bin/env bash
# Rebuild the RUNTIME after a sandbox reset. Source is never the problem;
# PHP, PostgreSQL, Composer and app/vendor are what disappear.
# Usage: bash restore.sh   (runtime only; preserves database contents)
set -euo pipefail
cd "$(dirname "$0")"
say(){ printf '\n\033[1;36m==> %s\033[0m\n' "$*"; }

reset_demo=0
confirm_database=""
for arg in "$@"; do
  case "$arg" in
    --help|-h)
      echo "Usage: bash restore.sh [--reset-demo --confirm-database=NAME]"
      echo "Default: restore runtime ONLY. No migrations, seeds or database reset."
      echo "--reset-demo DESTROYS data; allowed only for a confirmed local PostgreSQL database."
      exit 0 ;;
    --reset-demo) reset_demo=1 ;;
    --confirm-database=*) confirm_database="${arg#*=}" ;;
    *) echo "Unknown argument: $arg" >&2; exit 2 ;;
  esac
done
if [[ "$reset_demo" == 1 && -z "$confirm_database" ]] || [[ "$reset_demo" == 0 && -n "$confirm_database" ]]; then
  echo "A reset requires BOTH --reset-demo and --confirm-database=NAME." >&2
  exit 2
fi

if ! command -v php >/dev/null || ! command -v pg_ctlcluster >/dev/null || ! php -r '
    foreach (["pdo_pgsql", "mbstring", "dom", "curl", "zip", "intl", "bcmath"] as $extension) {
        if (!extension_loaded($extension)) { exit(1); }
    }
' 2>/dev/null; then
  say "Installing PHP 8.4 + PostgreSQL 17"
  sudo apt-get update -qq
  sudo apt-get install -y -qq php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml \
      php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath postgresql postgresql-contrib unzip
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
  sudo apt-get update -qq
  sudo apt-get install -y -qq composer
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
pg_isready -q || { echo "PostgreSQL did not become ready." >&2; exit 1; }

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
if [[ ! -L app/vendor ]]; then
  if [[ -e app/vendor ]]; then
    echo "app/vendor exists and is not a symlink; refusing to delete it." >&2
    exit 1
  fi
  ln -s /home/user/.cache/epcc-vendor app/vendor
fi
( cd app && composer install --no-interaction --no-progress -q )

if [[ "$reset_demo" == 1 ]]; then
  say "Explicitly requested demo reset (DESTRUCTIVE)"
  php scripts/reset-demo.php "--confirm-database=$confirm_database"
else
  say "Database contents preserved: no migrations or seeds were run"
fi

say "Clearing caches"
( cd app && php artisan config:clear -q; php artisan route:clear -q; php artisan view:clear -q )

# Filament ships its CSS/JS into public/ via a publish step, and public/ is NOT
# where composer puts them - so a fresh vendor install leaves /admin rendering
# as unstyled HTML with no JS. The panel still returns 200, which is exactly
# why this is easy to miss: nothing fails, it just looks broken.
say "Publishing Filament assets"
( cd app && php artisan filament:assets -q )

# Resolve dependencies from the project, not a hard-coded parent workspace.
say "Restoring locked browser-test dependencies"
npm ci --ignore-scripts --no-audit --no-fund

cat <<'MSG'

Runtime restored. Start the server with:

    cd app && php artisan serve --host=0.0.0.0 --port=8000

Existing data is preserved by default. For an EMPTY local demo only, explicitly
request --reset-demo --confirm-database=epcc after checking the target and backups.
Demo accounts exist only after seeding; see REBUILD.md.
MSG
