# Repository-local PHP CLI (Linux sandbox)

The Linux sandbox can install **native PHP 8.4.21** into `.local/php-native/bin/php`
without Docker or system packages. The executable is a pinned build from
`NativePHP/php-bin` (commit `e0c212d36422e5aa1f7357ec0524d99de8ee79bf`),
verified against the SHA-256 of its checked-in upstream zip before extraction.
No binary or 140 MB installer cache is committed. The installer and version
pin are tracked; `.local/` is ignored and must be recreated in a new sandbox.

```sh
bash scripts/install-local-php.sh
.local/php-native/bin/php -v
.local/php-native/bin/php -l app/app/Services/PmsLineEligibility.php
.local/php-native/bin/php packages/standards-engine/conformance/run.php
```

This build includes `intl`, `zip`, `gd`, `mbstring`, PDO and SQLite, but **does
not include `pdo_pgsql` or `pgsql`**. The application requires PostgreSQL/RLS;
SQLite is NOT an acceptable replacement for its integration tests. Thus this
binary is for syntax checks and framework-free PHP tests, **not** a complete
Laravel dev server or PHPUnit/PostgreSQL runtime. In addition, `app/vendor/`
is not installed in the sandbox. Keep using the full GitHub Actions job for
those tests until a native 8.4 build with PostgreSQL extensions and Composer
dependencies can be installed. Do not suppress extension checks to make the
suite appear green.

Windows workstation: keep **Laragon PHP 8.4 with `pdo_pgsql` and `pgsql`** as
the primary runtime; see `docs/LARAGON-DEVELOPMENT-ENVIRONMENT.md`. The
Linux-only installer intentionally does not alter that workflow. This is
preferable to the PHP WebAssembly fallback because it runs an actual native
CLI and needs no large Node dependency tree; the upstream binary still lacks
PostgreSQL support, which remains an explicit limitation.

### Uploaded alternative PHP inspection (2026-10-15)

A user-provided Linux x86_64 gzip TAR was inspected without executing its
binary. Its `php-config` omits `--with-pdo-pgsql` and `--with-pgsql`; the
archive has no matching PostgreSQL PHP extension or bundled libpq and was
compiled with `--disable-session`/ZTS. It is therefore not a drop-in
Laravel/PostgreSQL runtime. The artifact was not copied into the repository
or used to replace the verified local CLI. Obtain a trusted PHP 8.4 CLI with
`pdo_pgsql`, `pgsql`, session, and the Laravel-required extensions, then
check `PDO::getAvailableDrivers()` before running migrations.

### PostgreSQL-capable sandbox runtime feasibility (2026-10-15)

The static-php-cli source on GitHub supports building PHP 8.4 with pgsql,
pdo_pgsql and session, but its ready-made release asset redirect is not
reachable from this sandbox and local autoconf/pkg-config plus development
headers are unavailable. A PostgreSQL 16.2 server wheel from PyPI provides
`libpq.so` and `libpq-fe.h` (under ignored `.cache/pgve`); it does not provide
a PHP extension. No compatible PHP runtime or passing PostgreSQL/Laravel test
result exists here yet. Use a verified Linux x86_64 PHP 8.4 runtime with
pdo_pgsql and the Laravel extension set, or run the documented Laragon route.
