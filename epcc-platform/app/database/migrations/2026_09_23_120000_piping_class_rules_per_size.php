<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * USER CORRECTION (2026-09-23): no size bands. One row per size.
 *
 *   «برای هر سایز لوله که در کلاس مورد نظر وجود دارند اسکجوئل به صورت مجزا
 *    ثبت شود و از بازه استفاده نشود»
 *
 * The range design solved a problem the user does not have. A PMS is read and
 * transcribed size by size - the engineer picks a diameter and then picks its
 * schedule - so storing "DN15 to DN100 is SCH 80" made the software's model
 * disagree with the way the document is actually used.
 *
 * What this buys, beyond matching practice:
 *
 *   - The four-inch ambiguity CANNOT ARISE. There is no boundary to place, so
 *     there is nothing to get wrong. The half-open encoding was the right
 *     answer to the wrong question.
 *   - A class covers exactly the sizes it lists. "1/2 to 4 inch" quietly
 *     claimed 1/8, 1/4 and 3/8 as well, which most classes do not carry.
 *   - Uniqueness is a plain UNIQUE index rather than a GiST exclusion
 *     constraint. One size, one schedule, enforced by a b-tree.
 *   - A schedule can be checked against ITS OWN size at write time instead of
 *     across a band, so "SCH 160 at 3 1/2 inch" is refused precisely rather
 *     than condemning the whole band it sat in.
 *
 * The branch table keeps its ranges. That is a different shape: overlap there
 * is deliberate (a general rule plus narrower exceptions), and a spike proved
 * that forbidding it rejects correct PMS rules. Making it cell-by-cell would
 * be 120 rows for one class where the document has three.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The exclusion constraint guarded overlapping bands. With one row per
        // size there are no bands to overlap.
        DB::statement('ALTER TABLE piping_class_rules DROP CONSTRAINT IF EXISTS piping_class_rules_no_overlap');
        DB::statement('ALTER TABLE piping_class_rules DROP CONSTRAINT IF EXISTS piping_class_rules_range_is_real');

        Schema::table('piping_class_rules', function ($t): void {
            // The size this rule is about. DN is the integer key; NPS is the
            // label the engineer typed and the one that goes back on screen,
            // kept because "1 1/4" cannot be reconstructed from 32 without a
            // lookup, and a rule should be readable without one.
            $t->integer('dn')->nullable();
            $t->string('nps', 16)->nullable();
        });

        // Carry any existing rows over: a band becomes its lower bound. Dev
        // data only - nothing is published yet - but a silent data loss here
        // would be worse than a wrong-but-visible row.
        DB::statement('UPDATE piping_class_rules SET dn = lower(dn_range) WHERE dn IS NULL');

        Schema::table('piping_class_rules', function ($t): void {
            $t->dropColumn('dn_range');
        });

        DB::statement('ALTER TABLE piping_class_rules ALTER COLUMN dn SET NOT NULL');
        DB::statement('ALTER TABLE piping_class_rules ALTER COLUMN nps SET NOT NULL');

        DB::statement('ALTER TABLE piping_class_rules ADD CONSTRAINT piping_class_rules_dn_is_positive CHECK (dn > 0)');

        // One size, one answer per kind. The whole guarantee, in an index.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_rules
            ADD CONSTRAINT piping_class_rules_one_row_per_size
            UNIQUE (piping_class_id, rule_kind, dn)
        SQL);

        // Resolution is now an equality lookup, so the GiST index the
        // exclusion constraint used to provide is replaced by a b-tree.
        DB::statement('DROP INDEX IF EXISTS piping_class_rules_class_kind_idx');
        DB::statement(<<<'SQL'
            CREATE INDEX piping_class_rules_lookup_idx
            ON piping_class_rules (project_id, piping_class_id, rule_kind, dn)
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE piping_class_rules DROP CONSTRAINT IF EXISTS piping_class_rules_one_row_per_size');
        DB::statement('ALTER TABLE piping_class_rules DROP CONSTRAINT IF EXISTS piping_class_rules_dn_is_positive');
        DB::statement('DROP INDEX IF EXISTS piping_class_rules_lookup_idx');

        DB::statement('ALTER TABLE piping_class_rules ADD COLUMN dn_range int4range');
        DB::statement("UPDATE piping_class_rules SET dn_range = int4range(dn, dn + 1, '[)')");
        DB::statement('ALTER TABLE piping_class_rules ALTER COLUMN dn_range SET NOT NULL');

        Schema::table('piping_class_rules', function ($t): void {
            $t->dropColumn(['dn', 'nps']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_rules ADD CONSTRAINT piping_class_rules_no_overlap
            EXCLUDE USING gist (piping_class_id WITH =, rule_kind WITH =, dn_range WITH &&)
        SQL);
    }
};
