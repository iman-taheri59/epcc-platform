# syntax=docker/dockerfile:1
# ---------------------------------------------------------------------------
# EPCC Platform - development runtime image
#
# One container that can do everything the suite needs, so that "it works on my
# machine" stops being a variable:
#
#   * PHP 8.3 CLI + every extension composer.lock actually asks for
#   * Composer 2 (copied from the official image, not `curl | php`)
#   * Node 22 (Vite 8 needs >= 22.12; Debian bookworm ships Node 18)
#   * PostgreSQL 17 client (psql/pg_isready for the boot and dev scripts)
#   * headless Chromium + poppler-utils + Vazirmatn
#   * Python 3 (standards-packs/validate_packs.py)
#
# The PDF toolchain is NOT optional. Without a headless browser the 13 PDF
# tests call markTestSkipped() and the suite STILL REPORTS GREEN (REBUILD.md
# §4). Without Vazirmatn, PdfExportTest fails on a substituted font - or worse,
# a Persian MC certificate goes to a client full of disconnected letters. A
# container that quietly loses either one is worse than no container, so the
# build verifies all of it at the bottom of this file and fails loudly.
#
# Build context is the REPOSITORY ROOT, not app/, because composer.json
# resolves `epcc/standards-engine` from the path repository
# `../packages/standards-engine`.
# ---------------------------------------------------------------------------

FROM php:8.3-cli-bookworm

ENV DEBIAN_FRONTEND=noninteractive \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_HOME=/home/epcc/.composer \
    NPM_CONFIG_UPDATE_NOTIFIER=false \
    NPM_CONFIG_FUND=false \
    NPM_CONFIG_AUDIT=false \
    HOME=/home/epcc

# --- A writable home for whoever ends up running the process ----------------
# The entrypoint drops privileges to the invoking developer's uid (see
# scripts/dev-up.sh), which is a uid this image knows nothing about. Rather
# than bake in a user that may not match, give any uid a writable HOME so
# Composer, npm and Chromium all have somewhere to put their caches. Created
# BEFORE the package step because that step can invoke npm.
RUN mkdir -p /home/epcc/.composer /home/epcc/.cache /home/epcc/.npm \
 && chmod -R 1777 /home/epcc

# --- Node 22 ----------------------------------------------------------------
# Copied from the official image instead of adding the NodeSource apt repo: one
# fewer third-party key in the build, and the version cannot drift under us.
COPY --from=node:22-bookworm-slim /usr/local/bin/node /usr/local/bin/node
COPY --from=node:22-bookworm-slim /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -sf /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
 && ln -sf /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
 && node --version \
 && npm --version

# --- Composer ---------------------------------------------------------------
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# --- System packages + PHP extensions ---------------------------------------
# gd needs libpng/libjpeg/libfreetype/libwebp; intl needs libicu; zip needs
# libzip. The *-dev packages stay installed: `apt-get --auto-remove` on them can
# take the matching RUNTIME library with it and break gd at request time, which
# is a much more confusing failure than a slightly larger image.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        fontconfig \
        git \
        unzip \
        python3 \
        postgresql-client \
        libicu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        libwebp-dev \
        libonig-dev \
        chromium \
        poppler-utils \
        fonts-dejavu-core; \
    \
    # Vazirmatn. From the Debian archive where the release carries it, otherwise
    # from the upstream npm tarball (Node is already in the image, so this needs
    # no extra tooling). Either way the build ends by proving fontconfig can see
    # the family - print/layout.blade.php asks for it BY NAME with no @font-face,
    # because the renderer runs with networking disabled (AG-01..AG-06).
    apt-get install -y --no-install-recommends fonts-vazirmatn || { \
        echo "fonts-vazirmatn is not in this archive - installing from the npm release"; \
        mkdir -p /tmp/vazir; \
        cd /tmp/vazir; \
        npm pack vazirmatn@33.0.3 --silent; \
        tar xzf vazirmatn-*.tgz; \
        mkdir -p /usr/share/fonts/truetype/vazirmatn; \
        cp package/fonts/ttf/Vazirmatn-*.ttf /usr/share/fonts/truetype/vazirmatn/; \
        cd /; \
        rm -rf /tmp/vazir; \
    }; \
    \
    docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp; \
    docker-php-ext-install -j"$(nproc)" \
        bcmath \
        exif \
        gd \
        intl \
        opcache \
        pcntl \
        pdo_pgsql \
        pgsql \
        sockets \
        zip; \
    # mbstring is compiled into the base image; install it only if that ever
    # stops being true, rather than failing the build on a duplicate.
    php -m | grep -qx mbstring || docker-php-ext-install -j"$(nproc)" mbstring; \
    apt-get clean; \
    rm -rf /var/lib/apt/lists/*

# --- Optional operator-supplied fonts (air-gapped builds) --------------------
# See docker/php/fonts/README.md. A directory with no TTFs in it is normal.
COPY docker/php/fonts/ /tmp/epcc-fonts/
RUN set -eux; \
    if ls /tmp/epcc-fonts/*.ttf >/dev/null 2>&1; then \
        mkdir -p /usr/share/fonts/truetype/epcc; \
        cp /tmp/epcc-fonts/*.ttf /usr/share/fonts/truetype/epcc/; \
    fi; \
    rm -rf /tmp/epcc-fonts; \
    fc-cache -f >/dev/null

# --- PHP settings -----------------------------------------------------------
COPY docker/php/epcc.ini /usr/local/etc/php/conf.d/99-epcc.ini

# --- Entrypoint -------------------------------------------------------------
COPY docker/php/entrypoint.sh /usr/local/bin/epcc-entrypoint
RUN chmod +x /usr/local/bin/epcc-entrypoint

# --- Application source -----------------------------------------------------
# Copied LAST so that a code edit invalidates only this layer. In the normal
# dev flow compose.yaml bind-mounts the checkout over the top of it, so the
# copy is what makes the image usable standalone (CI, a demo box, a machine
# without the repo checked out) rather than what the developer actually edits.
COPY . /srv/epcc
WORKDIR /srv/epcc

# --- LOUD verification ------------------------------------------------------
# Every one of these has a silent-failure story behind it. Fail the BUILD here
# instead of discovering it as a mysteriously green suite or a Persian PDF full
# of boxes three weeks later.
RUN set -eux; \
    php -v | head -1; \
    for ext in bcmath ctype curl dom exif fileinfo gd intl json mbstring \
               openssl pcntl pdo pdo_pgsql pgsql posix session simplexml \
               sockets tokenizer xml zip; do \
        php -m | grep -qx "$ext" || { echo "MISSING PHP EXTENSION: $ext"; exit 1; }; \
    done; \
    command -v chromium >/dev/null \
        || { echo "MISSING chromium - the 13 PDF tests would SILENTLY SKIP"; exit 1; }; \
    command -v pdffonts >/dev/null \
        || { echo "MISSING poppler-utils (pdffonts) - font embedding is unverified"; exit 1; }; \
    command -v pdftotext >/dev/null \
        || { echo "MISSING poppler-utils (pdftotext) - the Persian round-trip is unverified"; exit 1; }; \
    fc-list | grep -qi vazirmatn \
        || { echo "MISSING Vazirmatn - drop the TTFs into docker/php/fonts/ (see the README there)"; exit 1; }; \
    command -v setpriv >/dev/null \
        || { echo "MISSING setpriv (util-linux) - the entrypoint cannot drop privileges"; exit 1; }; \
    command -v psql >/dev/null; \
    command -v python3 >/dev/null; \
    composer --version; \
    node --version; \
    npm --version; \
    fc-list | grep -i vazirmatn | head -1

EXPOSE 8000

ENTRYPOINT ["/usr/local/bin/epcc-entrypoint"]
# `php artisan serve` is the dev server the whole project is documented against
# (REBUILD.md, restore.sh). Override with `docker compose run app bash` for a
# shell, or `docker compose exec app php artisan test` for the suite.
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
