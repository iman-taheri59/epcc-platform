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

/*
 * Every test run does `migrate:fresh --seed`, which mints NEW project UUIDs and
 * seeds real attachments under storage/app/private/documents/{project_id}/...
 * Nothing ever removed the previous run's tree, so the blob store grew by a few
 * directories per run and reached 5,066 project folders / 12,963 files - past
 * the workspace snapshot cap, which truncates SILENTLY and would have cost a
 * session's work. The content-addressed layout (two levels of hash fan-out)
 * multiplies directories fast, so this is a file-COUNT problem long before it
 * is a disk-space problem: 58 MB in ~31,000 directories.
 *
 * Purge the tree before the suite runs. The seeder rebuilds whatever it needs,
 * and BlobIntegrityTest re-verifies every surviving blob against its SHA-256.
 */
$documentRoot = dirname(__DIR__).'/storage/app/private/documents';

$purgeSeededBlobs = static function () use ($documentRoot): void {
    if (! is_dir($documentRoot)) {
        return;
    }

    $entries = new RecursiveDirectoryIterator($documentRoot, FilesystemIterator::SKIP_DOTS);
    $walker = new RecursiveIteratorIterator($entries, RecursiveIteratorIterator::CHILD_FIRST);

    foreach ($walker as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
};

// Before, so a crashed previous run cannot accumulate; and after, so a green
// run leaves the workspace as it found it. One test does `migrate:fresh` per
// case, so a single suite mints ~250 project trees on its own.
$purgeSeededBlobs();
register_shutdown_function($purgeSeededBlobs);

