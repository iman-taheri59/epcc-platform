# Repository-local Laravel installation (Linux sandbox)

As of 2026-10-14 the repository's `app/vendor/` contains the locked
**Laravel Framework 13.31.0** production dependencies. Recreate the local
installation after a new sandbox checkout using:

```sh
bash scripts/install-local-laravel.sh
.local/php-native/bin/php app/artisan --version
```

The script first installs the pinned local PHP 8.4 CLI if absent (see
`docs/LOCAL-PHP-CLI-EN.md`), then uses Composer 2 and the committed
`app/composer.lock` with `--no-dev --prefer-source --no-scripts --no-plugins`.
GitHub source clones are necessary in this restricted sandbox because Composer
zip distributions redirect to unavailable external hosts. Without a system
Composer, the script uses a SHA-256-verified Composer 2.6.5 PHAR committed in
a **third-party** GitHub mirror at a pinned commit. This verifies repeatable
bytes, NOT independent upstream authenticity. On the owner's workstation,
prefer Composer downloaded and verified directly from the official site. No
PHAR, vendored dependencies or generated environment key is committed.

The only ignored platform requirements during the sandbox install are
`ext-xmlreader` and `ext-xmlwriter`, which the available pinned static CLI
lacks. Composer scripts are disabled; `artisan package:discover` is invoked
explicitly after installation. The script creates an ignored `app/.env` only
when absent, chooses `DB_CONNECTION=pgsql` (never SQLite), file-based
session/cache and a generated local app key. It does **not** configure a
PostgreSQL database or provide the missing extensions.

Verified in this sandbox:

- `app/vendor/autoload.php` exists; Laravel class autoloads;
- `php artisan --version` reports **Laravel Framework 13.31.0**;
- `php artisan package:discover` and `php artisan route:list --path=up`
  succeed; `/up` responded HTTP 200 during a local smoke check.

**Important boundary:** the PHP binary lacks `pdo_pgsql`, `pgsql`,
`xmlreader` and `xmlwriter`; development dependencies (`phpunit/phpunit`)
were deliberately not installed. The database-backed `/login` responded 500
in the smoke check, so the temporary preview server was stopped rather than
leaving a broken application preview. The application is **installed but not
fully runnable or testable locally**. Do not use SQLite as a surrogate for
PostgreSQL/RLS. The full six-job CI (including PostgreSQL + RLS) remains the
integration-test authority; Windows Laragon with PHP extensions is the
preferred interactive runtime. See
`docs/LARAGON-DEVELOPMENT-ENVIRONMENT.md` and `REBUILD.md`.

A better follow-up is to provision a trusted PHP 8.4 build with PostgreSQL
and XML extensions plus official Composer, install dev dependencies, and run
the existing PHPUnit suite against a non-superuser PostgreSQL role. Until
then, neither a `/up` response nor `artisan --version` proves the product
pages work end to end.

## CI availability note

The first CI run after the preceding local-PHP tooling change (`a945088`, run
38508213581) failed **before any job started** because GitHub Actions reported
account payments/spending-limit restrictions. This does not measure Laravel
or the installer. Recheck the run for this change once GitHub Actions is
available; local smoke checks are reported separately above.

The Laravel installer commit (`db0aa0e`, run 38508782336) received the same
pre-start billing restriction on every job. Its CI status is blocked, not a
failing PHPUnit or installer result.

## PostgreSQL extension installation investigation (2026-10-14)

`pdo_pgsql` is **not installed** in this sandbox. The repository-local
PHP 8.4.21 was compiled as a statically linked musl CLI (`ldd` reports
"not a dynamic executable"); its configure command includes PDO SQLite but
neither `--with-pdo-pgsql` nor `--with-pgsql`. There is no matching PHP
extension build toolchain, libpq headers or packaged extension in this
sandbox. A generic system `.so` cannot safely be copied into this build.
The Debian package host is blocked by the sandbox network, and GitHub release
assets redirect to a blocked host; GitHub Actions currently cannot start jobs
because of a billing/spending-limit restriction. No unsupported binary or
SQLite fallback has been installed in its place.

**Remediation:** replace the repository-local binary with a trusted PHP 8.4
build containing both `pdo_pgsql` and `pgsql` (plus XMLReader/XMLWriter), or
use the owner's Laragon PHP 8.4 with those extensions enabled. Verify using
`php -m` (both names), `php -r 'var_dump(PDO::getAvailableDrivers());'`
(`pgsql` must be listed), and a non-superuser PostgreSQL-backed migration/test.
This is a runtime replacement, not merely adding a line to `php.ini` of the
current static build. Do not claim the extension is installed until the
checks pass in the same PHP executable that runs `artisan`.
