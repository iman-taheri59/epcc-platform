<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fix: a custom GUC reverts to EMPTY STRING (not NULL) after SET LOCAL commits.
 *
 * Found by RowLevelSecurityTest::test_context_does_not_leak_between_transactions.
 *
 * PostgreSQL behaviour:
 *
 *   never set in this session      -> current_setting(..., true) IS NULL
 *   set with SET LOCAL, then COMMIT -> current_setting(..., true) = ''   <-- !
 *
 * The original policy cast that value directly:
 *
 *   company_id = current_setting('epcc.company_id', true)::uuid
 *
 * With '' that raises `invalid input syntax for type uuid`, so instead of
 * quietly returning zero rows, every unscoped query on a reused connection
 * throws a 500. It fails CLOSED (no data leaks), which is the right direction,
 * but it is still wrong: connections are reused constantly under Octane, so
 * any query outside a bound transaction would error.
 *
 * NULLIF(..., '') normalises both cases to NULL, and `company_id = NULL` is
 * NULL (not true), so the row is filtered out. Fail-closed AND well-behaved.
 *
 * This is precisely the class of defect ADR-003 Annex A §3.4 predicted for
 * Eloquent + session-scoped RLS, and the reason it was made a P0 spike.
 */
return new class extends Migration
{
    private const TENANT_TABLES = [
        'work_packages',
        'payment_certificates',
        'gate_evaluations',
    ];

    public function up(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");

            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    OR EXISTS (
                        SELECT 1
                        FROM projects p
                        WHERE p.id = {$table}.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
                WITH CHECK (
                    company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            SQL);
        }
    }

    public function down(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");

            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    company_id = current_setting('epcc.company_id', true)::uuid
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = {$table}.project_id
                          AND p.owner_company_id = current_setting('epcc.company_id', true)::uuid
                    )
                )
                WITH CHECK (
                    company_id = current_setting('epcc.company_id', true)::uuid
                )
            SQL);
        }
    }
};
