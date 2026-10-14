<?php

declare(strict_types=1);

/**
 * Windows/Laragon (or any PHP 8.4 host) alternative to blocked Actions.
 * Default: READ-ONLY preflight. --run explicitly opts into destructive
 * PHPUnit migrations against the dedicated epcc_test database only.
 * Never prints passwords or DSNs containing credentials.
 */
$root = dirname(__DIR__);
$app = $root.DIRECTORY_SEPARATOR.'app';
$run = ($argv[1] ?? '') === '--run';
if (count($argv) > 2 || (isset($argv[1]) && ! $run)) {
    fwrite(STDERR, "Usage: php scripts/verify-laragon-pms.php [--run]\n");
    exit(2);
}

$fail = static function (string $message): never {
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
};
$ok = static function (string $message): void { echo "OK: {$message}\n"; };

if (PHP_VERSION_ID < 80401) {
    $fail('PHP 8.4.1 or newer is required by app/composer.lock.');
}
foreach (['pdo_pgsql', 'pgsql', 'intl', 'zip', 'gd'] as $extension) {
    if (! extension_loaded($extension)) {
        $fail("Enable extension={$extension} in the selected Laragon PHP php.ini.");
    }
}
$ok('PHP '.PHP_VERSION.' and required PostgreSQL extensions are loaded');
if (! is_file($app.'/vendor/autoload.php') || ! is_file($app.'/vendor/phpunit/phpunit/phpunit')) {
    $fail('Run composer install (including dev dependencies) in app/ first.');
}

$envFile = $app.'/.env';
if (! is_file($envFile)) {
    $fail('Create app/.env from .env.example and configure the local PostgreSQL role.');
}
$env = [];
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (preg_match('/^([A-Z_]+)=(.*)$/', trim($line), $m) === 1) {
        $env[$m[1]] = trim($m[2], " \t\"'");
    }
}
if (($env['DB_CONNECTION'] ?? '') !== 'pgsql' || ($env['DB_URL'] ?? '') !== '') {
    $fail('Use DB_CONNECTION=pgsql and an empty DB_URL in app/.env. SQLite cannot test RLS.');
}
$xml = file_get_contents($app.'/phpunit.xml');
if ($xml === false || preg_match('/<env\s+name="DB_DATABASE"\s+value="epcc_test"\s*\/>/', $xml) !== 1) {
    $fail('phpunit.xml must target the dedicated epcc_test database.');
}

// PHPUnit environment values are not guaranteed to override exported shell
// variables. Refuse a conflicting shell database rather than risk wiping it.
foreach (['DB_CONNECTION', 'DB_URL', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD'] as $key) {
    $exported = getenv($key);
    if ($exported !== false && $exported !== ($env[$key] ?? '')) {
        $fail("Exported {$key} differs from app/.env; clear it before testing.");
    }
}
$exportedDatabase = getenv('DB_DATABASE');
if ($exportedDatabase !== false && $exportedDatabase !== 'epcc_test') {
    $fail('Exported DB_DATABASE must be epcc_test or unset before running PHPUnit.');
}
$exportedEnvironment = getenv('APP_ENV');
if ($exportedEnvironment !== false && $exportedEnvironment !== 'testing') {
    $fail('Exported APP_ENV must be testing or unset before running PHPUnit.');
}

$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = $env['DB_PORT'] ?? '5432';
$user = $env['DB_USERNAME'] ?? '';
$password = $env['DB_PASSWORD'] ?? '';
if ($user === '' || $host === '' || ! ctype_digit($port)) {
    $fail('Set DB_HOST, DB_PORT and DB_USERNAME in app/.env.');
}
try {
    $db = new PDO("pgsql:host={$host};port={$port};dbname=epcc_test", $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $identity = $db->query('SELECT current_database() AS db, current_user AS role, '
        .'(SELECT rolsuper FROM pg_roles WHERE rolname = current_user) AS super, '
        .'(SELECT rolbypassrls FROM pg_roles WHERE rolname = current_user) AS bypass, '
        ."current_setting('server_version_num')::integer AS version")->fetch(PDO::FETCH_ASSOC);
    $extension = $db->query("SELECT 1 FROM pg_extension WHERE extname = 'btree_gist'")->fetchColumn();
} catch (Throwable $e) {
    $fail('Cannot connect to epcc_test as the configured role, or cannot query its capabilities. Check PostgreSQL and app/.env.');
}
if ($identity === false || $identity['db'] !== 'epcc_test'
    || in_array($identity['super'], ['t', true, '1', 1], true)
    || in_array($identity['bypass'], ['t', true, '1', 1], true)) {
    $fail('Testing requires epcc_test with a non-superuser role that does not BYPASSRLS.');
}
if ((int) $identity['version'] < 160000 || $extension === false) {
    $fail('PostgreSQL 16+ and the btree_gist extension are required in epcc_test.');
}
$ok('epcc_test: PostgreSQL '.floor((int) $identity['version'] / 10000)
    .', btree_gist present, role '.$identity['role'].' is not superuser/BYPASSRLS');
$db = null;

if (! $run) {
    echo "READ-ONLY preflight passed. Run with --run to execute PHPUnit.\n";
    exit(0);
}

fwrite(STDERR, "WARNING: --run invokes migrate:fresh in epcc_test and DELETES its existing test data.\n");
chdir($app);
passthru(escapeshellarg(PHP_BINARY).' artisan config:clear', $clearStatus);
if ($clearStatus !== 0) {
    $fail('Could not clear the Laravel configuration cache before testing.');
}
foreach (['test --filter=PmsClassDocumentTest', 'test'] as $arguments) {
    echo "Running: php artisan {$arguments}\n";
    passthru(escapeshellarg(PHP_BINARY).' artisan '.$arguments, $status);
    if ($status !== 0) {
        $fail("php artisan {$arguments} failed (exit {$status}).");
    }
}
echo "Laravel test commands passed. Check their output for SKIPPED tests (especially PDF tooling); this does not run Docker Compose.\n";
