#!/usr/bin/env bash
# Install a pinned, self-contained PHP 8.4 CLI under the repository .local/.
# Linux x86_64 only. No apt/Docker/Composer or privileged permissions needed.
# This build has NO pdo_pgsql: Laravel/PostgreSQL tests still require the full
# native extension stack documented for Laragon/CI.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
REV='e0c212d36422e5aa1f7357ec0524d99de8ee79bf'
SHA256='7d6f8623d6fb3600c01b81a92249558a9f84a042f407c947158ec3f96f0337bd'
SRC="$ROOT/.local/php-native-src"
ZIP="$SRC/bin/linux/x64/php-8.4.zip"
DEST="$ROOT/.local/php-native/bin"

if [[ "$(uname -s)" != Linux || "$(uname -m)" != x86_64 ]]; then
    echo 'This installer supports only Linux x86_64; use Laragon for Windows.' >&2
    exit 2
fi
for tool in git unzip sha256sum; do
    command -v "$tool" >/dev/null || { echo "Missing $tool" >&2; exit 2; }
done
if [[ ! -f "$ZIP" ]]; then
    mkdir -p "$ROOT/.local"
    git init -q "$SRC"
    git -C "$SRC" remote add origin https://github.com/NativePHP/php-bin.git
    git -C "$SRC" sparse-checkout init --cone
    git -C "$SRC" sparse-checkout set bin/linux/x64
    git -C "$SRC" fetch -q --depth 1 --filter=blob:none origin "$REV"
    git -C "$SRC" checkout -q --detach FETCH_HEAD
fi
printf '%s  %s\n' "$SHA256" "$ZIP" | sha256sum --check --status || {
    echo 'PHP archive checksum mismatch; refusing to run unverified binary.' >&2
    exit 1
}
mkdir -p "$DEST"
unzip -oq "$ZIP" -d "$DEST"
chmod +x "$DEST/php"
"$DEST/php" -v
"$DEST/php" -m | grep -qx intl
"$DEST/php" -m | grep -qx PDO
if ! "$DEST/php" -m | grep -qx pdo_pgsql; then
    echo 'Note: this PHP CLI lacks pdo_pgsql; use Laragon or CI for Laravel/PostgreSQL tests.' >&2
fi
echo "Installed: $DEST/php"
