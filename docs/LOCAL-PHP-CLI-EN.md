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
