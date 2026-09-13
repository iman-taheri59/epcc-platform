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
        $this->call([EpccDemoSeeder::class, AuthSeeder::class]);
    }
}
