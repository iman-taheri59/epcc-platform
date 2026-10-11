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
     * ADR-008 §4.2: the closed branch vocabulary. STUB_IN is the
     * Pipe-to-Pipe outcome — the branch is contoured and welded straight
     * into the header, no fitting in between.
     */
    private const BRANCH_TYPES = ['TEE', 'WELDOLET', 'SOCKOLET', 'STUB_IN'];

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

        Schema::create('piping_classes', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->string('code', 32);
            $t->string('name_en');
            $t->string('name_fa');
            $t->string('state', 16)->default('DRAFT');

            // The declared coverage span for one-dimensional rules, e.g.
            // [15,600). The publish gate demands each rule kind cover this
            // span contiguously. Text here, cast to int4range below.
            $t->text('dn_span');

            $t->timestampTz('published_at')->nullable();
            $t->uuid('published_by')->nullable();
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('published_by')->references('id')->on('users')->nullOnDelete();

            $t->unique(['project_id', 'code']);
            $t->index(['project_id', 'state']);
        });

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

        foreach (['piping_classes', 'piping_class_rules', 'piping_class_branch_rules'] as $table) {
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
        foreach (['piping_class_branch_rules', 'piping_class_rules', 'piping_classes'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
        }

        Schema::dropIfExists('piping_class_branch_rules');
        Schema::dropIfExists('piping_class_rules');
        Schema::dropIfExists('piping_classes');
    }
};
