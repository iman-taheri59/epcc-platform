<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-008 (piping phase P1) — PMS piping classes: size-dependent rules and
 * the branch table.
 *
 * Two rule shapes, deliberately different:
 *
 * 1. ONE-DIMENSIONAL RULES (`piping_class_rules`). "½–3½ SCH 80, 4–10
 *    SCH 40" is stored as half-open DN ranges `[from, to)` and guarded by an
 *    EXCLUDE constraint: two rules of the same kind on the same class may
 *    never overlap. Overlap is corruption here, so the corrupt state is made
 *    unrepresentable. Gaps stay legal while a class is drafted and are
 *    refused by the publish gate (service layer + `range_agg`).
 *
 * 2. BRANCH RULES (`piping_class_branch_rules`). A branch table is a matrix
 *    `(header_range × branch_range) → component_type` where a general rule
 *    plus narrower exceptions is the NORMAL shape (ADR-008 §4.1 spike), so
 *    overlap is allowed and resolution is specificity-wins in the resolver.
 *    The UNIQUE on the exact footprint still catches a duplicated cell.
 *
 * btree_gist is required by the exclusion constraint. It is provisioned by
 * the DBA step (scripts/sandbox-pg.sh / docs/LARAGON-DEVELOPMENT-ENVIRONMENT
 * .md / CI), never by the application role — this migration asserts it with
 * a clear message instead of CREATE EXTENSION.
 *
 * RLS follows the sargable preamble shipped the same day (migration
 * 2026_10_11_110000): participation/ownership semi-join narrowed to the
 * bound project, standard participation write check.
 */
return new class extends Migration
{
    /** ADR-008 §3: one table discriminated by rule_kind. */
    private const RULE_KINDS = [
        'PIPE_SCHEDULE', 'END_PREPARATION', 'VALVE_TYPE', 'FLANGE_TYPE',
        'GASKET_TYPE', 'BOLT_SPEC', 'CORROSION_ALLOWANCE',
    ];

    /**
     * ADR-008 §4.2: the closed branch vocabulary. P2P is the Pipe-to-Pipe
     * outcome (owner terminology, 2026-10-11) — the branch is contoured and
     * welded onto/into the header with no fitting. Its two kinds (stub-in /
     * stub-on) live in `p2p_kind`.
     */
    private const BRANCH_TYPES = ['TEE', 'WELDOLET', 'SOCKOLET', 'P2P'];

    /** @see \App\Support\Piping\P2pKind */
    private const P2P_KINDS = ['STUB_IN', 'STUB_ON'];

    /** @see \App\Support\Piping\BranchReinforcement */
    private const REINFORCEMENTS = ['NONE', 'PAD'];

    public function up(): void
    {
        $ext = DB::selectOne("SELECT 1 AS ok FROM pg_extension WHERE extname = 'btree_gist'");

        if ($ext === null) {
            throw new RuntimeException(
                'The btree_gist extension is required for the PMS exclusion constraint (ADR-008). '.
                'Create it once as a database administrator: CREATE EXTENSION IF NOT EXISTS btree_gist; '.
                '— see docs/LARAGON-DEVELOPMENT-ENVIRONMENT.md / scripts/sandbox-pg.sh.'
            );
        }

        // ---- piping_classes -------------------------------------------------

        // The line register already owns this table (2026_09_15). Extend it
        // in place: piping_lines, MTO and estimates reference these IDs.
        // Legacy rows have no declared size coverage; never invent one.
        Schema::table('piping_classes', function (Blueprint $t): void {
            $t->string('name_en')->nullable();
            $t->string('name_fa')->nullable();
            $t->string('state', 16)->default('DRAFT');
            $t->text('dn_span')->nullable();
            $t->timestampTz('published_at')->nullable();
            $t->uuid('published_by')->nullable();
            $t->foreign('published_by')->references('id')->on('users')->nullOnDelete();
            $t->index(['project_id', 'state']);
        });
        DB::statement("UPDATE piping_classes SET name_en = COALESCE(description_en, code), name_fa = COALESCE(description_fa, description_en, code)");
        DB::statement('ALTER TABLE piping_classes ALTER COLUMN name_en SET NOT NULL, ALTER COLUMN name_fa SET NOT NULL');
        // Older line-editor code still inserts description_en without PMS names.
        // Preserve its contract while keeping names mandatory for every row.
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_piping_class_fill_names() RETURNS trigger
            LANGUAGE plpgsql AS $$
            BEGIN
                NEW.name_en := COALESCE(NULLIF(NEW.name_en, ''), NULLIF(NEW.description_en, ''), NEW.code);
                NEW.name_fa := COALESCE(NULLIF(NEW.name_fa, ''), NULLIF(NEW.description_fa, ''), NEW.name_en);
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER piping_classes_fill_names BEFORE INSERT OR UPDATE ON piping_classes
            FOR EACH ROW EXECUTE FUNCTION epcc_piping_class_fill_names();
        SQL);


        // ---- piping_class_rules ---------------------------------------------

        Schema::create('piping_class_rules', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('piping_class_id');
            $t->string('rule_kind', 32);
            $t->text('dn_range');
            $t->jsonb('value');
            $t->text('note_en')->nullable();
            $t->text('note_fa')->nullable();
            $t->text('source_ref')->nullable();
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('piping_class_id')->references('id')->on('piping_classes')->cascadeOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $t->index(['project_id', 'piping_class_id', 'rule_kind']);
        });

        // ---- piping_class_branch_rules --------------------------------------

        Schema::create('piping_class_branch_rules', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('piping_class_id');
            $t->text('header_range');
            $t->text('branch_range');
            $t->string('component_type', 32);

            // Pipe-to-Pipe cells only: stub-in vs stub-on. NULL on fittings.
            $t->string('p2p_kind', 16)->nullable();

            // Owner rule (2026-10-11): when a reinforcement pad is required,
            // its thickness is a mandatory datum — enforced by CHECK below.
            $t->string('reinforcement', 16)->default('NONE');
            $t->decimal('pad_thickness', 8, 3)->nullable();   // millimetres

            $t->jsonb('value')->nullable();
            $t->text('source_ref')->nullable();
            // Explicit tiebreak when two rules are equally narrow (ADR-008 §4.3).
            $t->integer('priority')->default(0);
            $t->uuid('created_by')->nullable();
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('piping_class_id')->references('id')->on('piping_classes')->cascadeOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->nullOnDelete();

            $t->index(['project_id', 'piping_class_id']);
        });

        // ---- ranges, checks, the exclusion constraint, uniqueness -----------

        $kinds = implode(', ', array_map(static fn (string $k) => "'{$k}'", self::RULE_KINDS));
        $branchTypes = implode(', ', array_map(static fn (string $k) => "'{$k}'", self::BRANCH_TYPES));
        $p2pKinds = implode(', ', array_map(static fn (string $k) => "'{$k}'", self::P2P_KINDS));
        $reinforcements = implode(', ', array_map(static fn (string $k) => "'{$k}'", self::REINFORCEMENTS));

        DB::unprepared(<<<SQL
            ALTER TABLE piping_classes ALTER COLUMN dn_span TYPE int4range
                USING dn_span::int4range;

            ALTER TABLE piping_class_rules ALTER COLUMN dn_range TYPE int4range
                USING dn_range::int4range;

            ALTER TABLE piping_class_branch_rules ALTER COLUMN header_range TYPE int4range
                USING header_range::int4range;
            ALTER TABLE piping_class_branch_rules ALTER COLUMN branch_range TYPE int4range
                USING branch_range::int4range;

            ALTER TABLE piping_classes ADD CONSTRAINT piping_classes_state_valid
                CHECK (state IN ('DRAFT', 'PUBLISHED'));
            ALTER TABLE piping_classes ADD CONSTRAINT piping_classes_span_not_empty
                CHECK (NOT isempty(dn_span));

            ALTER TABLE piping_class_rules ADD CONSTRAINT piping_class_rules_kind_valid
                CHECK (rule_kind IN ({$kinds}));
            ALTER TABLE piping_class_rules ADD CONSTRAINT piping_class_rules_range_not_empty
                CHECK (NOT isempty(dn_range));

            ALTER TABLE piping_class_branch_rules ADD CONSTRAINT piping_class_branch_ranges_not_empty
                CHECK (NOT isempty(header_range) AND NOT isempty(branch_range));
            ALTER TABLE piping_class_branch_rules ADD CONSTRAINT piping_class_branch_type_valid
                CHECK (component_type IN ({$branchTypes}));
            -- A P2P cell must say which kind; a fitting cell must not.
            -- NULL-explicit on purpose: `NULL IN (...)` is NULL, and a CHECK
            -- that evaluates to NULL passes — verified live, that let a P2P
            -- row with no kind through the first draft of this constraint.
            ALTER TABLE piping_class_branch_rules ADD CONSTRAINT piping_class_branch_p2p_kind_valid
                CHECK (
                    (component_type = 'P2P' AND p2p_kind IS NOT NULL AND p2p_kind IN ({$p2pKinds}))
                    OR (component_type <> 'P2P' AND p2p_kind IS NULL)
                );
            ALTER TABLE piping_class_branch_rules ADD CONSTRAINT piping_class_branch_reinforcement_valid
                CHECK (reinforcement IN ({$reinforcements}));
            -- Owner rule: a PAD without its thickness is an incomplete datum
            -- and must be unrepresentable. A thickness without a PAD is a
            -- contradiction and is refused too.
            ALTER TABLE piping_class_branch_rules ADD CONSTRAINT piping_class_branch_pad_valid
                CHECK (
                    (reinforcement = 'PAD' AND pad_thickness IS NOT NULL AND pad_thickness > 0)
                    OR (reinforcement <> 'PAD' AND pad_thickness IS NULL)
                );

            -- THE LOAD-BEARING CONSTRAINT (ADR-008 §3): two rules of the same
            -- kind on the same class may not overlap. btree_gist supplies the
            -- equality operators for uuid and text inside a GiST index.
            ALTER TABLE piping_class_rules ADD CONSTRAINT piping_class_rules_no_overlap
                EXCLUDE USING gist (
                    piping_class_id WITH =,
                    rule_kind       WITH =,
                    dn_range        WITH &&
                );

            -- The same footprint twice is a typo; overlapping footprints are
            -- the normal general-plus-exception shape and stay legal.
            ALTER TABLE piping_class_branch_rules ADD CONSTRAINT piping_class_branch_footprint_unique
                UNIQUE (piping_class_id, header_range, branch_range);
        SQL);

        // ---- RLS: sargable participation preamble + standard write check ----

        $gucC = "NULLIF(current_setting('epcc.company_id', true), '')::uuid";
        $gucP = "NULLIF(current_setting('epcc.project_id', true), '')";

        foreach (['piping_class_rules', 'piping_class_branch_rules'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    {$table}.project_id IN (
                        SELECT pc.project_id
                        FROM project_company pc
                        WHERE pc.company_id = {$gucC}
                          AND (
                              {$gucP} IS NULL
                              OR pc.project_id = {$gucP}::uuid
                          )
                        UNION
                        SELECT p.id
                        FROM projects p
                        WHERE p.owner_company_id = {$gucC}
                          AND (
                              {$gucP} IS NULL
                              OR p.id = {$gucP}::uuid
                          )
                    )
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {$table}.project_id
                          AND pc.company_id = {$gucC}
                    )
                )
                WITH CHECK (
                    company_id = {$gucC}
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = {$table}.project_id
                          AND pc.company_id = {$gucC}
                    )
                )
            SQL);
        }
    }

    public function down(): void
    {
        foreach (['piping_class_branch_rules', 'piping_class_rules'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
        }

        Schema::dropIfExists('piping_class_branch_rules');
        Schema::dropIfExists('piping_class_rules');
        DB::statement('DROP TRIGGER IF EXISTS piping_classes_fill_names ON piping_classes');
        DB::statement('DROP FUNCTION IF EXISTS epcc_piping_class_fill_names()');
        DB::statement('ALTER TABLE piping_classes DROP CONSTRAINT IF EXISTS piping_classes_state_valid');
        DB::statement('ALTER TABLE piping_classes DROP CONSTRAINT IF EXISTS piping_classes_span_not_empty');
        Schema::table('piping_classes', function (Blueprint $t): void {
            $t->dropIndex(['project_id', 'state']);
            $t->dropForeign(['published_by']);
            $t->dropColumn(['name_en', 'name_fa', 'state', 'dn_span', 'published_at', 'published_by']);
        });
    }
};
