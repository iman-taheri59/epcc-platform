<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\GuardSql;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The guard registry: forty-eight `epcc_guard_*` trigger functions, each of
 * which used to be declared inline in the migration that introduced it — and
 * re-declared, whole, whenever a later slice needed to change one IF of it.
 *
 * Three copies of epcc_guard_notification, two of epcc_guard_piping_grn, two of
 * epcc_guard_material_certificate, and so on. Every copy had to be kept in step
 * by hand, and drift is silent: the database ends up saying something that no
 * single file in the repository says.
 *
 * database/guards/*.sql is now the only place a guard's current text lives. These
 * tests are what make that true rather than aspirational: the database must
 * agree with the files, the files must be idempotent, and no migration dated
 * after the consolidated pass may declare a guard inline.
 */
final class GuardDefinitionsTest extends TestCase
{
    private const CONSOLIDATED_PASS = '2026_10_29_200000_consolidate_guard_definitions.php';

    public function test_the_registry_is_not_empty_and_every_file_is_a_guard(): void
    {
        $names = GuardSql::names();

        $this->assertNotSame([], $names, 'the guard registry disappeared');

        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^epcc_guard_[a-z0-9_]+$/', $name);
            $this->assertFileExists(GuardSql::path($name));
        }
    }

    public function test_every_installed_guard_has_a_file_and_every_file_is_installed(): void
    {
        $installed = array_keys(GuardSql::installed());

        $this->assertSame(GuardSql::names(), $installed, 'the registry and the database disagree about which guards exist');
    }

    public function test_reapplying_every_canonical_file_changes_nothing(): void
    {
        $before = GuardSql::installed();

        GuardSql::apply();

        $this->assertSame($before, GuardSql::installed(), 'a freshly migrated database already differs from database/guards — the files are not authoritative');
    }

    public function test_each_file_is_valid_sql_on_its_own(): void
    {
        foreach (GuardSql::names() as $name) {
            $before = GuardSql::definitionOf($name);

            GuardSql::apply($name);

            $this->assertSame($before, GuardSql::definitionOf($name), "applying [{$name}] was not a no-op");
        }
    }

    public function test_no_migration_after_the_consolidated_pass_declares_a_guard_inline(): void
    {
        $directory = database_path('migrations');
        $this->assertFileExists($directory.'/'.self::CONSOLIDATED_PASS, 'the consolidated pass itself is missing');

        $offenders = [];

        foreach (glob($directory.'/*.php') ?: [] as $path) {
            $file = basename($path);

            if ($file === self::CONSOLIDATED_PASS || strcmp($file, self::CONSOLIDATED_PASS) < 0) {
                continue; // history: those migrations predate the registry
            }

            $source = (string) file_get_contents($path);

            if (preg_match('/CREATE\s+OR\s+REPLACE\s+FUNCTION\s+(public\.)?epcc_guard_/i', $source) === 1) {
                $offenders[] = $file;
            }
        }

        $this->assertSame([], $offenders, 'a guard was declared inline instead of being edited in database/guards — the file no longer has the last word');
    }

    public function test_the_guards_are_bound_to_triggers_not_left_orphaned(): void
    {
        $bound = DB::select(
            "select distinct pg_proc.proname as name
               from pg_trigger
               join pg_proc on pg_proc.oid = pg_trigger.tgfoid
              where not pg_trigger.tgisinternal
                and pg_proc.proname like 'epcc\_guard\_%'"
        );

        $bound = array_map(static fn ($row): string => $row->name, $bound);
        sort($bound);

        $unbound = array_values(array_diff(GuardSql::names(), $bound));

        $this->assertSame([], $unbound, 'a guard exists in the registry but no trigger binds it');
    }
}
