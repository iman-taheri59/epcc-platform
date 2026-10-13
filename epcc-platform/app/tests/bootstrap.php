<?php

declare(strict_types=1);

/*
 * `vendor/` is a symlink into an excluded cache directory so its ~16,500 files
 * do not count against the workspace snapshot cap (see REBUILD.md §0).
 *
 * Laravel infers the application base path from the directory holding the
 * registered Composer autoloader. Through the symlink that resolves to the
 * cache directory, so it would look for bootstrap/app.php in the wrong place.
 * Pin the real base path before the autoloader registers.
 */
$_ENV['APP_BASE_PATH'] = $_SERVER['APP_BASE_PATH'] = dirname(__DIR__);

require __DIR__.'/../vendor/autoload.php';

/* Each PHPUnit process gets an isolated storage tree. Never purge application
 * attachments: the previous bootstrap deleted storage/app/private/documents.
 * Override cached configuration too, so a production config cache cannot
 * redirect test disks, logs or destructive migrations into the live app.
 */
$testStorage = sys_get_temp_dir().'/epcc-tests-'.bin2hex(random_bytes(12));
foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
    mkdir($testStorage.'/'.$directory, 0700, true);
}
foreach ([
    'LARAVEL_STORAGE_PATH' => $testStorage,
    'APP_CONFIG_CACHE' => $testStorage.'/config.php',
    'APP_ROUTES_CACHE' => $testStorage.'/routes.php',
] as $name => $value) {
    $_ENV[$name] = $_SERVER[$name] = $value;
    putenv($name.'='.$value);
}

register_shutdown_function(static function () use ($testStorage): void {
    if (! is_dir($testStorage)) {
        return;
    }
    $walker = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($testStorage, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($walker as $entry) {
        // Never traverse symlinks, including links to application documents.
        if ($entry->isDir() && ! $entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($testStorage);
});
