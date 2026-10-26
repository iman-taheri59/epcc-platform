<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    // NOT WithoutModelEvents: HasUuid generates primary keys on the `creating`
    // model event. Suppressing events makes every insert fail with a
    // not-null violation on `id`.

    /**
     * `php artisan migrate:fresh --seed` must produce the working demo, not an
     * empty database. The feature tests call EpccDemoSeeder directly, so this
     * being stock went unnoticed until the dev database was rebuilt.
     */
    public function run(): void
    {
        // Fail closed in production: these seeders create demo identities
        // with well-known passwords. A production `migrate:fresh --seed`
        // must be a deliberate act, not a side effect of a deploy script.
        if (app()->environment('production') && ! config('epcc.allow_demo_seed')) {
            throw new \RuntimeException(
                'Refusing to seed demo identities in production. '
                .'Set EPCC_ALLOW_DEMO_SEED=true only for a controlled demo install.'
            );
        }

        $this->call([EpccDemoSeeder::class, AuthSeeder::class, MaterialCatalogueSeeder::class]);
    }
}
