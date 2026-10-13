<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-project support.
 *
 * The data model was already multi-project (everything carries project_id).
 * What was missing is the *isolation* half: RLS scoped rows by COMPANY only,
 * so a contractor working on two projects saw both projects' rows mixed
 * together, and there was nothing stopping a company reading rows for a
 * project it does not participate in.
 *
 * Two changes:
 *
 * 1. A company may only see rows for projects it actually participates in
 *    (an entry in project_company). Previously the tenant predicate never
 *    consulted the participation table at all — a company that somehow
 *    obtained rows tagged with its own id on an unrelated project would have
 *    been shown them.
 *
 * 2. An OPTIONAL project scope, `epcc.project_id`. When set, only that
 *    project's rows are visible; when unset, all permitted projects are.
 *    That is the "one or many projects" requirement expressed in the
 *    database rather than in a where clause a developer can forget.
 *
 * Numbering is also made per-project: two projects must be able to have
 * their own صورت‌وضعیت #1 without colliding.
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
        'work_packages',
        'payment_certificates',
    ];

    public function up(): void
    {
        // Per-project serials: unique WITHIN a project, not globally.
        Schema::table('payment_certificates', function (Blueprint $t): void {
            $t->unique(['project_id', 'serial'], 'payment_certificates_project_serial_unique');
        });

        foreach (self::TENANT_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");

            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    -- Optional project scope. Unset => all permitted projects.
                    (
                        NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                        OR {$table}.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                    )
                    AND
                    (
                        -- Own rows, but only on a project we participate in.
                        (
                            company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            AND EXISTS (
                                SELECT 1 FROM project_company pc
                                WHERE pc.project_id = {$table}.project_id
                                  AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                            )
                        )
                        OR
                        -- Employer sees every participant's rows on its projects.
                        EXISTS (
                            SELECT 1 FROM projects p
                            WHERE p.id = {$table}.project_id
                              AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
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
        }

        // gate_evaluations keeps actor semantics, plus the same project scope.
        DB::statement('DROP POLICY IF EXISTS gate_evaluations_tenant_isolation ON gate_evaluations');
        DB::statement(<<<SQL
            CREATE POLICY gate_evaluations_tenant_isolation ON gate_evaluations
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR gate_evaluations.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND (
                    actor_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = gate_evaluations.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            )
            WITH CHECK (
                actor_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            )
        SQL);

        // The policies join project_company on every row; index accordingly.
        DB::statement('CREATE INDEX IF NOT EXISTS project_company_company_project_idx ON project_company (company_id, project_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS projects_owner_idx ON projects (owner_company_id)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS project_company_company_project_idx');
        DB::statement('DROP INDEX IF EXISTS projects_owner_idx');

        Schema::table('payment_certificates', function (Blueprint $t): void {
            $t->dropUnique('payment_certificates_project_serial_unique');
        });

        foreach (array_merge(self::TENANT_TABLES, ['gate_evaluations']) as $table) {
            $col = $table === 'gate_evaluations' ? 'actor_company_id' : 'company_id';
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    {$col} = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = {$table}.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
                WITH CHECK (
                    {$col} = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            SQL);
        }
    }
};
