<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Repair the ITP row-level security policies.
 *
 * THE DEFECT
 * ----------
 * The six ITP tables were created with this clause:
 *
 *     project_id IN (SELECT project_id FROM project_memberships
 *                    WHERE company_id = current_setting('epcc.company_id', true)::uuid)
 *
 * `project_memberships` has no `company_id` column. Postgres does not reject
 * this: it resolves the name against the OUTER table, so the policy silently
 * became
 *
 *     ... WHERE itps.company_id = current_setting(...)
 *
 * — an EXISTS over every membership row, i.e. "rows my own company created".
 *
 * The impact is not a confidentiality leak. It is the opposite, and it breaks
 * the purpose of the module: measured on the fixture, the owner (who is also
 * the creator) saw 1 ITP and the **TPI, a legitimate member of PH-14, saw 0**
 * — so the third-party inspector could not read or sign the plan naming it.
 *
 * No test caught it because every ITP test acts as the owner company, which is
 * also the creating company, so "creator-only" and "member" give identical
 * answers.
 *
 * THE REPAIR
 * ----------
 * Read access follows the house pattern used by 27 other policies
 * (`material_lots`, `joints`, …): scope to the bound project, and require
 * membership in `project_company`.
 *
 * Write access is deliberately NOT uniform, because the two kinds of ITP table
 * answer to different rules:
 *
 *   - The five tables holding the PLAN TEXT (itps, itp_stages,
 *     itp_stage_parties, itp_stage_documents, itp_annexes) accept writes only
 *     from the company that owns the row. A TPI reads the plan; it does not
 *     edit it.
 *
 *   - `itp_records` accepts writes from any member of the project, because a
 *     signature IS a write and the signer is deliberately NOT the company that
 *     owns the record: `openForLot()` stamps the record with the lot owner's
 *     company, and the TPI then signs it. Locking that down to the owning
 *     company would make a witness or hold point unsignable by the very party
 *     the row nominates. The real defence there is the party grid, already
 *     enforced in `ItpService::sign()` — a signer whose tenant_type has no row
 *     in `itp_stage_parties` is refused with `party_has_no_role_here`.
 *
 * Only the six ITP tables are repaired here, per the user's instruction to fix
 * ITP first. `baseline_batches`, `baseline_discrepancies`, `engineering_items`,
 * `model_imports` and `model_import_diffs` carry the same broken clause and are
 * still outstanding — see HANDOFF §5ad.
 */
return new class extends Migration
{
    /** The five tables holding the text of the plan. */
    private const PLAN_TABLES = [
        'itps',
        'itp_stages',
        'itp_stage_parties',
        'itp_stage_documents',
        'itp_annexes',
    ];

    public function up(): void
    {
        foreach (self::PLAN_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant ON {$table}");

            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant ON {$table}
                USING (
                    (
                        NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                        OR project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
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
        }

        /*
         * itp_records: same read rule, but any member of the project may
         * write. See the note above — a signature is an UPDATE performed by a
         * company that is deliberately not the record's owner.
         */
        DB::statement('DROP POLICY IF EXISTS itp_records_tenant ON itp_records');

        DB::statement(<<<'SQL'
            CREATE POLICY itp_records_tenant ON itp_records
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = itp_records.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = itp_records.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        DB::statement("COMMENT ON TABLE itp_records IS
            'Inspection results. RLS lets ANY project member write: a signature is an UPDATE by a company that is not the record owner. Who may sign which row is enforced by the party grid in ItpService::sign().'");
    }

    public function down(): void
    {
        // Deliberately restores the CORRECT clause rather than the broken one.
        // Rolling back a repair should not reinstate a defect.
        foreach ([...self::PLAN_TABLES, 'itp_records'] as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant ON {$table}");

            DB::statement(<<<SQL
                CREATE POLICY {$table}_tenant ON {$table}
                USING (
                    project_id IN (
                        SELECT project_id FROM project_company
                        WHERE company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
                WITH CHECK (
                    project_id IN (
                        SELECT project_id FROM project_company
                        WHERE company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            SQL);
        }
    }
};
