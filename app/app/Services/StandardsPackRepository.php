<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Loads standards packs from disk and caches the decoded JSON.
 *
 * The packs are product data, not application code: they live outside the
 * Laravel app (../standards-packs) so the same files feed the CI validator,
 * the conformance corpus, and eventually the offline field app.
 */
final class StandardsPackRepository
{
    /** @var array<string, array<string,mixed>> */
    private array $cache = [];

    public function __construct(private readonly string $packDir)
    {
    }

    /** @return array<string,mixed> */
    public function get(string $packName): array
    {
        if (isset($this->cache[$packName])) {
            return $this->cache[$packName];
        }

        $path = $this->packDir.'/'.$packName.'.pack.json';
        if (! is_file($path)) {
            throw new RuntimeException("Standards pack not found: {$packName} (looked in {$path})");
        }

        /** @var array<string,mixed> $decoded */
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $this->cache[$packName] = $decoded;
    }

    /** @return list<string> */
    public function available(): array
    {
        $out = [];
        foreach (glob($this->packDir.'/*.pack.json') ?: [] as $file) {
            $out[] = basename($file, '.pack.json');
        }
        sort($out);

        return $out;
    }
}
