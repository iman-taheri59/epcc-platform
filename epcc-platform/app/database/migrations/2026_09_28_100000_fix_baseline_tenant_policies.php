<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DEFECT-001: qualify participation against project_company, never the absent
 * project_memberships.company_id. Shared reads must NOT turn into shared
 * deletion: PostgreSQL never applies WITH CHECK to DELETE.
 *
 * Retain creator-company write ownership, require current participation and
 * scope, and freeze writes on archive. Broader cross-company approval rights
 * require an explicit workflow decision, not an accidental SELECT policy.
 */
return new class extends Migration
{
    private const TABLES = [
        'baseline_batches', 'engineering_items', 'baseline_discrepancies',
        'model_imports', 'model_import_diffs',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $visible = "(
                (NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                 OR {$table}.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid)
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = {$table}.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )";
            $writable = "({$visible}
                AND {$table}.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND epcc_project_is_writable({$table}.project_id))";

            DB::statement("DROP POLICY IF EXISTS {$table}_tenant ON {$table}");
            foreach (['read', 'insert', 'update', 'delete'] as $operation) {
                DB::statement("DROP POLICY IF EXISTS {$table}_{$operation} ON {$table}");
            }
            DB::statement("CREATE POLICY {$table}_read ON {$table} FOR SELECT USING ({$visible})");
            DB::statement("CREATE POLICY {$table}_insert ON {$table} FOR INSERT WITH CHECK ({$writable})");
            DB::statement("CREATE POLICY {$table}_update ON {$table} FOR UPDATE USING ({$writable}) WITH CHECK ({$writable})");
            DB::statement("CREATE POLICY {$table}_delete ON {$table} FOR DELETE USING ({$writable})");
        }
    }

    public function down(): void
    {
        // A rollback must never reinstall the known insecure policy.
        $this->up();
    }
};
