<?php

declare(strict_types=1);

use App\Support\GuardSql;
use Illuminate\Database\Migrations\Migration;

/**
 * The consolidated guard pass: the files in database/guards have the last word.
 *
 * Forty-eight guards were, until now, declared inline inside the migrations that
 * introduced them. Whenever a later slice needed to change one IF of an existing
 * guard, it had to re-declare the whole function body in a new migration —
 * byte-for-byte identical except the change — which is how two copies of
 * epcc_guard_notification (three, in fact), epcc_guard_piping_grn,
 * epcc_guard_material_certificate, epcc_guard_piping_qa and a dozen others came
 * to exist. Each copy has to be kept in step with the others by hand, and drift
 * between them is silent: the database simply ends up saying something no single
 * file in the repository says.
 *
 * This migration moves the authority to one place. It runs after every guard has
 * been declared by its historical migration, and re-applies the canonical text
 * of all of them from database/guards/*.sql. The historical copies stay — they
 * are how a database gets built in the first place, and triggers bind functions
 * at their own point in the timeline — but from here on they are history, not
 * truth.
 *
 * From now on: to change a guard, edit its file. A migration whose triggers bind
 * a guard calls GuardSql::apply() for it immediately before CREATE TRIGGER, so
 * the trigger binds the current text even when the file changed in the same
 * change. A re-declaration inline in a later migration is refused by
 * GuardDefinitionsTest.
 *
 * This is only safe because no project has ever been registered with this
 * software: there is no database whose guards were installed by an earlier
 * version and must be carried forward untouched. With production data the
 * equivalent change would need a compatibility argument per guard.
 */
return new class extends Migration
{
    public function up(): void
    {
        $names = GuardSql::names();

        if ($names === []) {
            throw new RuntimeException('database/guards is empty — the consolidated pass has nothing to apply.');
        }

        GuardSql::apply($names);

        // Every file must be installed, and applying it twice must be a no-op:
        // that is what makes the file authoritative rather than advisory.
        foreach ($names as $name) {
            $after = GuardSql::definitionOf($name);

            if ($after === null) {
                throw new RuntimeException("Guard [{$name}] was not installed by its canonical file.");
            }

            GuardSql::apply($name);

            if (GuardSql::definitionOf($name) !== $after) {
                throw new RuntimeException("Applying guard [{$name}] twice does not settle — the file is not idempotent.");
            }
        }
    }

    public function down(): void
    {
        throw new RuntimeException('The consolidated guard pass is forward-only: guards have no prior definition to fall back to.');
    }
};
