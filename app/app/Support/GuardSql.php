<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * One home for the trigger guards.
 *
 * Every `epcc_guard_*` function's authoritative text lives in
 * `database/guards/<name>.sql`. The copies inside historical migrations stay
 * where they are — they are what built the database up to that point — but the
 * consolidated pass (2026_10_29_200000) re-applies every file at the end of the
 * run, so the files always have the last word.
 *
 * Rules this class exists to enforce:
 *
 *  1. To change a guard, edit its file. Do not re-declare it inline in a new
 *     migration; a re-declaration after the consolidated pass is exactly the
 *     drift this registry was created to make impossible, and
 *     `GuardDefinitionsTest` fails on it.
 *  2. A migration that binds a guard with CREATE TRIGGER must call apply() for
 *     that guard immediately before the CREATE TRIGGER, so the trigger binds
 *     the current text even when the file was edited in the same change.
 *  3. A brand-new guard is added as a file, then applied and bound the same way.
 *
 * Identity of a guard is its name; names are validated, because they become
 * paths.
 */
final class GuardSql
{
    private const PATTERN = '/^epcc_guard_[a-z0-9_]+$/';

    /** @return list<string> sorted guard names, one per file */
    public static function names(): array
    {
        $names = [];

        foreach (glob(self::directory().'/*.sql') ?: [] as $path) {
            $name = basename($path, '.sql');

            if (preg_match(self::PATTERN, $name) === 1) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    public static function directory(): string
    {
        return database_path('guards');
    }

    public static function path(string $name): string
    {
        self::assertName($name);

        return self::directory().'/'.$name.'.sql';
    }

    public static function body(string $name): string
    {
        $path = self::path($name);

        if (! is_file($path)) {
            throw new RuntimeException("No canonical definition for guard [{$name}] at [{$path}].");
        }

        $body = file_get_contents($path);

        if ($body === false || trim($body) === '') {
            throw new RuntimeException("Canonical definition for guard [{$name}] is empty.");
        }

        return $body;
    }

    /**
     * Apply one guard, several, or all of them. Idempotent by construction:
     * every file is a CREATE OR REPLACE FUNCTION statement.
     *
     * @param  string|list<string>|null  $names
     * @return list<string> the guards applied
     */
    public static function apply(string|array|null $names = null): array
    {
        $requested = match (true) {
            $names === null => self::names(),
            is_string($names) => [$names],
            default => $names,
        };

        foreach ($requested as $name) {
            DB::unprepared(self::body($name));
        }

        return $requested;
    }

    /** @return array<string, string> name => pg_get_functiondef, for installed guards */
    public static function installed(): array
    {
        $rows = DB::select(
            "select p.proname as name, pg_get_functiondef(p.oid) as definition
               from pg_proc p
              where p.pronamespace = 'public'::regnamespace
                and p.proname like 'epcc\_guard\_%'"
        );

        $installed = [];

        foreach ($rows as $row) {
            $installed[$row->name] = $row->definition;
        }

        ksort($installed);

        return $installed;
    }

    public static function definitionOf(string $name): ?string
    {
        self::assertName($name);

        $row = DB::selectOne(
            "select pg_get_functiondef(p.oid) as definition
               from pg_proc p
              where p.pronamespace = 'public'::regnamespace
                and p.proname = ?
              limit 1",
            [$name]
        );

        return $row->definition ?? null;
    }

    private static function assertName(string $name): void
    {
        if (preg_match(self::PATTERN, $name) !== 1) {
            throw new RuntimeException("Not a guard name: [{$name}].");
        }
    }
}
