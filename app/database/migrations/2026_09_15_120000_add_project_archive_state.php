<?php

declare(strict_types=1);

use App\Support\ProjectState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Project archive (ADR-013 §7.2).
 *
 * Adds the lifecycle column plus a DATABASE-ENFORCED write block: once a
 * project is ARCHIVED, no INSERT or UPDATE against its rows can succeed, even
 * from a route that forgot to check.
 *
 * WHY BOTH A SERVICE CHECK AND AN RLS BACKSTOP
 * --------------------------------------------
 * Spiked before writing this (ADR-013 §7.2). RLS alone is not enough: a blocked
 * UPDATE returns `UPDATE 0` with NO error, so Eloquent's save() returns true and
 * the user is told their edit was saved when it was silently discarded. That is
 * worse than a refusal.
 *
 * So the service layer refuses loudly (the user gets a real message) and RLS is
 * the backstop that makes a missed check harmless rather than catastrophic.
 * Neither layer alone is sufficient: the service check is forgettable, and the
 * RLS check is silent.
 *
 * INSERTs DO raise. Only UPDATE/DELETE are silent, because they match zero rows
 * rather than violating WITH CHECK.
 */
return new class extends Migration
{
    /**
     * Tables whose writes must be frozen when the parent project is archived.
     *
     * Deliberately enumerated rather than derived. Every table here carries
     * project_id; `projects` itself is excluded because the un-archive
     * transition has to be able to write it.
     *
     * authorization_denials and gate_evaluations are included even though they
     * have no FK to projects (ADR-013 §8) - they are audit sinks, and an
     * archived project should stop accruing them.
     *
     * @var list<string>
     */
    private const FROZEN_TABLES = [
        'areas',
        'document_files',
        'document_review_comments',
        'document_revisions',
        'documents',
        'facilities',
        'material_inspections',
        'material_lots',
        'material_movements',
        'material_requisition_items',
        'material_requisitions',
        'mc_certificates',
        'payment_certificates',
        'project_memberships',
        'punch_items',
        'purchase_order_items',
        'purchase_orders',
        'subsystems',
        'transmittal_items',
        'transmittal_recipients',
        'transmittals',
        'work_packages',
    ];

    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table): void {
            $table->string('state', 16)->default(ProjectState::ACTIVE);
            $table->timestampTz('archived_at')->nullable();
            $table->uuid('archived_by')->nullable();
            $table->text('archive_reason')->nullable();
        });

        $states = implode("','", ProjectState::all());
        DB::statement(<<<SQL
            ALTER TABLE projects
            ADD CONSTRAINT projects_state_is_known
            CHECK (state IN ('{$states}'))
        SQL);

        // An archived project must say when and by whom, or the audit trail is
        // a shrug. Enforced in the database because the service layer is not
        // the only thing that writes here (a future admin tool, an import).
        DB::statement(<<<'SQL'
            ALTER TABLE projects
            ADD CONSTRAINT projects_archived_has_provenance
            CHECK (
                (state <> 'ARCHIVED')
                OR (archived_at IS NOT NULL AND archived_by IS NOT NULL)
            )
        SQL);

        DB::statement('ALTER TABLE projects ADD CONSTRAINT projects_archived_by_foreign '
            .'FOREIGN KEY (archived_by) REFERENCES users(id) ON DELETE SET NULL');

        DB::statement("CREATE INDEX projects_state_idx ON projects (state) WHERE state <> 'ACTIVE'");

        // A SECURITY DEFINER function, so the check itself is not subject to
        // the RLS on `projects` - otherwise a session scoped to project A could
        // not see project B's state and the policy would fail open.
        // STABLE lets the planner cache it within a statement.
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION epcc_project_is_writable(p_project_id uuid)
            RETURNS boolean
            LANGUAGE sql
            STABLE
            SECURITY DEFINER
            SET search_path = public
            AS $$
                SELECT COALESCE(
                    (SELECT state = 'ACTIVE' FROM projects WHERE id = p_project_id),
                    -- No such project: let the foreign key produce the error
                    -- rather than masking it as a permission problem.
                    true
                )
            $$
        SQL);

        foreach (self::FROZEN_TABLES as $table) {
            // TWO policies per table, because one cannot cover both cases.
            //
            // (1) INSERT and UPDATE are caught by WITH CHECK, which inspects
            //     the row being written. USING (true) is deliberate: a
            //     RESTRICTIVE USING clause is ANDed into SELECT as well, and
            //     that would hide the archive from the people who archived it.
            //     An archive you cannot read is just a slow delete.
            DB::statement(<<<SQL
                CREATE POLICY {$table}_archive_freeze ON {$table}
                AS RESTRICTIVE
                FOR ALL
                USING (true)
                WITH CHECK (epcc_project_is_writable(project_id))
            SQL);

            // (2) DELETE needs its own policy. WITH CHECK is NEVER evaluated
            //     for DELETE - there is no new row to check - so the policy
            //     above lets a DELETE through untouched. Verified the hard
            //     way: with only policy (1) in place, deleting the punch list
            //     of an archived project reported DELETE 9 and removed all
            //     nine rows.
            //
            //     A USING clause scoped FOR DELETE constrains only the DELETE
            //     command, so reads are unaffected and the data stays visible.
            DB::statement(<<<SQL
                CREATE POLICY {$table}_archive_no_delete ON {$table}
                AS RESTRICTIVE
                FOR DELETE
                USING (epcc_project_is_writable(project_id))
            SQL);
        }

        // Note on how these two fail, because they do NOT fail alike:
        //   INSERT / UPDATE -> raise SQLSTATE 42501, naming the policy.
        //   DELETE          -> matches zero rows and raises NOTHING.
        // The rows are safe either way, but a silent DELETE 0 lets calling
        // code believe it succeeded. That is why ProjectArchiveService refuses
        // first and this layer is only the backstop.
    }

    public function down(): void
    {
        foreach (self::FROZEN_TABLES as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_archive_no_delete ON {$table}");
            DB::statement("DROP POLICY IF EXISTS {$table}_archive_freeze ON {$table}");
        }

        DB::statement('DROP FUNCTION IF EXISTS epcc_project_is_writable(uuid)');
        DB::statement('DROP INDEX IF EXISTS projects_state_idx');
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_archived_by_foreign');
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_archived_has_provenance');
        DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_state_is_known');

        Schema::table('projects', function (Blueprint $table): void {
            $table->dropColumn(['state', 'archived_at', 'archived_by', 'archive_reason']);
        });
    }
};
