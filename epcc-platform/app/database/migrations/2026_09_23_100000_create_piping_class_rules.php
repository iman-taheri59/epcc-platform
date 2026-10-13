<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-008 phases 1 and 2 — the PMS as data.
 *
 * The user's sentence was: "1/2 to 4 inch SCH80, 4 to 10 inch SCH40". Four
 * inches appears in both bands. A piping engineer reads the intent instantly;
 * a database cannot, and a wrong reading puts the wrong wall thickness on a
 * pressure line. So bands are stored as HALF-OPEN DN ranges [from, to) and
 * never as inclusive text, and the database refuses overlap rather than
 * trusting a service check that a bulk import will bypass.
 *
 * Two tables, because the two rule kinds have genuinely different shapes:
 *
 *  - piping_class_rules        1-D bands. Overlap is CORRUPTION - one size
 *                              must yield exactly one schedule.
 *  - piping_class_branch_rules 2-D footprints. Overlap is a FEATURE - every
 *                              real branch table is a general rule plus
 *                              narrower exceptions, so the resolver picks the
 *                              narrowest match instead.
 *
 * Both facts were re-verified against this PostgreSQL 17 before writing this
 * migration, including that entering the user's sentence literally as two
 * inclusive bands is rejected with a message naming DN100.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const TENANT_TABLES = [
        'piping_class_rules',
        'piping_class_branch_rules',
    ];

    public function up(): void
    {
        // Required for an EXCLUDE constraint that mixes equality on a uuid
        // with range overlap: plain gist has no uuid operator class.
        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');

        // ------------------------------------------------------------------
        // Phase 1 - one-dimensional size bands
        // ------------------------------------------------------------------
        Schema::create('piping_class_rules', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('piping_class_id');

            // One table discriminated by kind, not a table per rule type. The
            // same shape covers schedule, end preparation, valve type, flange
            // type, gasket and corrosion allowance - the same reasoning as the
            // single movement ledger.
            $t->string('rule_kind', 32);

            // value is jsonb because each kind resolves to a different payload
            // ({"schedule":"80"} vs {"end_type":"SW"}). A typed column per kind
            // would be mostly nulls.
            $t->jsonb('value');

            $t->text('note_en')->nullable();
            $t->text('note_fa')->nullable();

            // Which PMS table this row was transcribed from. The PMS arrives as
            // a consultant PDF, so the citation is the only way back to the
            // source when a rule is questioned.
            $t->string('source_ref', 128)->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('piping_class_id')->references('id')->on('piping_classes')->cascadeOnDelete();
        });

        // int4range has no Blueprint method; add it raw.
        DB::statement('ALTER TABLE piping_class_rules ADD COLUMN dn_range int4range NOT NULL');

        // A descending or empty band is a transcription error, not a rule.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_rules ADD CONSTRAINT piping_class_rules_range_is_real CHECK (
                NOT isempty(dn_range)
                AND lower(dn_range) IS NOT NULL
                AND upper(dn_range) IS NOT NULL
                AND lower(dn_range) >= 0
            )
        SQL);

        // THE load-bearing constraint. Two rules of the same kind on the same
        // class may not overlap: DN100 must resolve to exactly one schedule.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_rules ADD CONSTRAINT piping_class_rules_no_overlap
            EXCLUDE USING gist (
                piping_class_id WITH =,
                rule_kind       WITH =,
                dn_range        WITH &&
            )
        SQL);

        // ------------------------------------------------------------------
        // Phase 2 - the two-dimensional branch table
        // ------------------------------------------------------------------
        Schema::create('piping_class_branch_rules', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('piping_class_id');

            // Which fitting the PMS wants at this header x branch cell.
            $t->string('component_type', 32);

            // Optional qualifier: a TEE is one component type, equal or
            // reducing being derived from the port sizes (ADR-008 §7 #2, the
            // user's own correction). This holds things like a reinforcement
            // pad requirement instead.
            $t->jsonb('value')->nullable();

            // Explicit tiebreak for the rare case of two equally narrow rules.
            // Without it the resolver's ordering would depend on insertion
            // order, which is not a rule anyone wrote down.
            $t->integer('priority')->default(0);

            $t->string('source_ref', 128)->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('piping_class_id')->references('id')->on('piping_classes')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE piping_class_branch_rules ADD COLUMN header_range int4range NOT NULL');
        DB::statement('ALTER TABLE piping_class_branch_rules ADD COLUMN branch_range int4range NOT NULL');

        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_branch_rules ADD CONSTRAINT piping_class_branch_rules_ranges_are_real CHECK (
                NOT isempty(header_range) AND NOT isempty(branch_range)
                AND lower(header_range) IS NOT NULL AND upper(header_range) IS NOT NULL
                AND lower(branch_range) IS NOT NULL AND upper(branch_range) IS NOT NULL
                AND lower(header_range) >= 0 AND lower(branch_range) >= 0
            )
        SQL);

        // NOT an exclusion constraint. A spike proved that forbidding overlap
        // rejects two perfectly normal PMS rules - a general TEE over the whole
        // matrix plus a WELDOLET for small branches off large headers - because
        // the second footprint sits inside the first. Overlap is how branch
        // tables are written. What IS an error is the same footprint twice,
        // which is a duplicated row rather than an exception.
        DB::statement(<<<'SQL'
            ALTER TABLE piping_class_branch_rules
            ADD CONSTRAINT piping_class_branch_rules_footprint_once
            UNIQUE (piping_class_id, header_range, branch_range)
        SQL);

        // ------------------------------------------------------------------
        // Tenancy, archive freeze, indexes
        // ------------------------------------------------------------------
        foreach (self::TENANT_TABLES as $table) {
            DB::unprepared("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::unprepared("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    (
                        NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                        OR {$table}.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                    )
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {$table}.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
                WITH CHECK (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {$table}.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            SQL);

            // ADR-013. Both halves: WITH CHECK is never evaluated for DELETE.
            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_archive_freeze ON {$table}
                AS RESTRICTIVE
                FOR ALL
                USING (true)
                WITH CHECK (epcc_project_is_writable(project_id))
            SQL);

            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_archive_no_delete ON {$table}
                AS RESTRICTIVE
                FOR DELETE
                USING (epcc_project_is_writable(project_id))
            SQL);

            // ADR-012: lead with project_id so the tenant predicate is an
            // index scan rather than a filter.
            DB::statement("CREATE INDEX {$table}_project_idx ON {$table} (project_id)");
        }

        // Resolution indexes. The 1-D lookup is served by the GiST index the
        // exclusion constraint already created; the branch lookup has none, so
        // give it one on both ranges.
        DB::statement(<<<'SQL'
            CREATE INDEX piping_class_branch_rules_lookup_idx
            ON piping_class_branch_rules
            USING gist (piping_class_id, header_range, branch_range)
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX piping_class_rules_class_kind_idx
            ON piping_class_rules (project_id, piping_class_id, rule_kind)
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('piping_class_branch_rules');
        Schema::dropIfExists('piping_class_rules');
        // btree_gist is left installed: other work may come to depend on it,
        // and dropping an extension is not this migration's business.
    }
};
