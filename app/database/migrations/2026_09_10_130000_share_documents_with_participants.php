<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Make the CDE actually common (SRS FR-ENG-03, ARC §5.4).
 *
 * THE BUG THIS FIXES
 * The document tables shipped with the standard tenant policy: you see your own
 * rows, and the employer sees everyone's. On a payment certificate that is
 * exactly right. On a document register it is wrong, and it made the module
 * useless for half the roles the product is sold to - a PMC with `doc.view`,
 * a participant on the project, opened the register and saw "no documents".
 * Consultant, MC and TPI would all have had the same experience.
 *
 * A "common data environment" whose whole purpose is to be COMMON cannot be
 * scoped like private commercial data.
 *
 * THE RULE, FROM ISO 19650
 * The CDE states are themselves the sharing model:
 *
 *   WIP       private to the originating company. Unfinished work is nobody
 *             else's business, and a contractor will not adopt a system that
 *             exposes half-drawn work to the client.
 *   SHARED    published TO THE PROJECT for review. Visible to every
 *             participant - this is the entire point of the state.
 *   PUBLISHED / ARCHIVED  likewise visible; these are the issued record.
 *
 * So visibility is: your own rows always, plus every non-WIP row on a project
 * you participate in.
 *
 * THE EMPLOYER AND WIP
 * The employer keeps its existing see-everything reach, INCLUDING WIP. That is
 * a deliberate, reversible choice, not an oversight: it matches how the rest of
 * this system already treats the owner, and Iranian employer organisations
 * expect oversight of work in progress. A strict ISO 19650 reading would hide
 * WIP even from the appointing party. If a customer wants that, the change is
 * confined to the `owner_sees_wip` branch below.
 *
 * NO RECURSION
 * `documents` and `document_files` reference `document_revisions` in their
 * policies, and `document_revisions` references neither. One level, no cycle.
 * The subqueries are deliberately written against the base table with the
 * state test inline, rather than leaning on the other table's own policy,
 * so each policy is readable on its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ---- document_revisions: the state lives here, so this is the root --
        DB::statement('DROP POLICY IF EXISTS document_revisions_tenant_isolation ON document_revisions');
        DB::statement(<<<'SQL'
            CREATE POLICY document_revisions_tenant_isolation ON document_revisions
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR document_revisions.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND (
                    -- Your own rows, on a project you participate in.
                    (
                        company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        AND EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = document_revisions.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
                    )
                    -- Anything SHARED or beyond, to every participant. This is
                    -- the line that makes the CDE common.
                    OR (
                        document_revisions.cde_state <> 'WIP'
                        AND EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = document_revisions.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
                    )
                    -- Employer oversight, including WIP. See the note above.
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = document_revisions.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            )
            WITH CHECK (
                -- Writing is unchanged: you may only ever create your OWN rows.
                -- Reading widely and writing narrowly is the whole point.
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = document_revisions.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        // ---- documents: visible if ANY revision of it is visible ------------
        // Not "if its CURRENT revision is visible": a drawing at Rev A
        // published with Rev B being drafted must stay in the register for
        // everyone else, showing Rev A. Keying on current_revision_id would
        // make the document vanish the moment its author started Rev B.
        DB::statement('DROP POLICY IF EXISTS documents_tenant_isolation ON documents');
        DB::statement(<<<'SQL'
            CREATE POLICY documents_tenant_isolation ON documents
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR documents.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND (
                    (
                        company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        AND EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = documents.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
                    )
                    OR (
                        EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = documents.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
                        AND EXISTS (
                            SELECT 1 FROM document_revisions r
                            WHERE r.document_id = documents.id
                              AND r.cde_state <> 'WIP'
                        )
                    )
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = documents.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = documents.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        // ---- document_files: follow the revision they hang off ---------------
        DB::statement('DROP POLICY IF EXISTS document_files_tenant_isolation ON document_files');
        DB::statement(<<<'SQL'
            CREATE POLICY document_files_tenant_isolation ON document_files
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR document_files.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND (
                    (
                        company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        AND EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = document_files.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
                    )
                    OR (
                        EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = document_files.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        )
                        AND EXISTS (
                            SELECT 1 FROM document_revisions r
                            WHERE r.id = document_files.revision_id
                              AND r.cde_state <> 'WIP'
                        )
                    )
                    OR EXISTS (
                        SELECT 1 FROM projects p
                        WHERE p.id = document_files.project_id
                          AND p.owner_company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = document_files.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        // The state test now runs on every read of these tables.
        DB::statement('CREATE INDEX IF NOT EXISTS document_revisions_doc_state_idx ON document_revisions (document_id, cde_state)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS document_revisions_doc_state_idx');
        // Deliberately not restoring the old policies: they were a defect.
    }
};
