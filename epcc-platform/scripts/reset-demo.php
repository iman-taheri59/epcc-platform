<?php

declare(strict_types=1);

// Deliberately separate from dependency restoration. No database changes until
// the effective environment, driver and exact target have ALL been checked.
$options = ['confirm' => '', 'check' => false];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--confirm-database=')) {
        $options['confirm'] = substr($arg, strlen('--confirm-database='));
    } elseif ($arg === '--check-only') {
        $options['check'] = true;
    } else {
        fwrite(STDERR, "Unknown argument. Use --confirm-database=NAME [--check-only].\n");
        exit(2);
    }
}
if ($options['confirm'] === '') {
    fwrite(STDERR, "Refusing reset without --confirm-database=NAME.\n");
    exit(2);
}

$base = dirname(__DIR__).'/app';
$_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = $base;
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if (! $app->environment('local') || $db->getDriverName() !== 'pgsql'
    || $db->getDatabaseName() !== $options['confirm']) {
    fwrite(STDERR, "Refusing reset: local environment, PostgreSQL and exact database confirmation are required.\n");
    exit(2);
}
if ($options['check']) {
    fwrite(STDOUT, "Reset preconditions passed. CHECK ONLY; no database changes.\n");
    exit(0);
}
fwrite(STDOUT, "Explicitly confirmed demo reset of database: ".$db->getDatabaseName()."\n");
foreach ([['migrate:fresh', ['--force' => true, '--seed' => true]],
    ['db:seed', ['--class' => 'ProcurementSeeder', '--force' => true]]] as [$command, $arguments]) {
    $status = $kernel->call($command, $arguments);
    fwrite(STDOUT, $kernel->output());
    if ($status !== 0) {
        exit($status);
    }
}
