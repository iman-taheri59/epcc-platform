<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-Level Security: the tenant-isolation backstop (ARC §5.1).
 *
 * The application already filters by company. RLS exists so that a forgotten
 * `where` clause, a raw query, or a Filament table that someone wires up
 * carelessly CANNOT return another company's rows. Defence in depth.
 *
 * Mechanism
 * ---------
 * Every request sets a session-scoped GUC:
 *
 *     SET LOCAL epcc.company_id = '<uuid>';
 *
 * and the policies below compare it against each row's `company_id`.
 *
 * Why SET LOCAL specifically
 * --------------------------
 * `SET LOCAL` is scoped to the current transaction, so it cannot leak between
 * requests that share a pooled connection. That is the failure mode Annex A
 * §3.4 warned about under Octane, where the app is long-lived and connections
 * are reused. A plain `SET` would persist on the connection and hand tenant A's
 * context to tenant B's request.
 *
 * Employer visibility
 * -------------------
 * The employer/owner must see all participants' rows on their own projects
 * (SRS cross-company visibility matrix). That is expressed as a second
 * predicate, not as an RLS bypass.
 */
return new class extends Migration
{
    /** Tables carrying a company_id tenant column. */
    private const TENANT_TABLES = [
        'work_packages',
        'payment_certificates',
        'gate_evaluations',
    ];

    public function up(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");

            // FORCE applies the policy to the table owner too. Without this,
            // the owning role silently bypasses RLS and the backstop is
            // decorative - a very common and very quiet mistake.
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (
                    -- Own rows.
                    company_id = current_setting('epcc.company_id', true)::uuid
                    OR
                    -- Employer sees every participant's rows on its projects.
                    EXISTS (
                        SELECT 1
                        FROM projects p
                        WHERE p.id = {$table}.project_id
                          AND p.owner_company_id = current_setting('epcc.company_id', true)::uuid
                    )
                )
                WITH CHECK (
                    company_id = current_setting('epcc.company_id', true)::uuid
                )
            SQL);
        }

        // A NULL/unset GUC must mean "see nothing", never "see everything".
        // current_setting(..., true) returns NULL when unset, and
        // `company_id = NULL` is NULL (not true), so the row is filtered out.
        // Fail-closed by construction - asserted in the test suite.
    }

    public function down(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
