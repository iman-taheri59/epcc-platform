<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Native file attachments for the document register (SRS FR-DOC-01/02).
 *
 * WHY FILES HANG OFF THE REVISION, NOT THE DOCUMENT
 * Rev A's PDF and Rev B's PDF are different files, and the whole point of the
 * register is that you can still open what was issued for construction last
 * March. Attaching to the document would either overwrite history or need a
 * second "which revision was this?" column - i.e. this table, badly.
 *
 * CONTENT-ADDRESSED STORAGE
 * The stored path is derived from the SHA-256 of the bytes, never from the
 * user's filename. Three reasons, in order of importance:
 *
 *   1. Path traversal dies at the design level. A filename of
 *      `../../.env` cannot escape anything if it is never part of the path.
 *   2. Integrity is checkable. FR-AUD-02 wants "what was issued?" to be
 *      answerable years later; a hash proves the bytes did not drift.
 *   3. Re-issuing an unchanged 80 MB drawing costs nothing.
 *
 * The original filename is kept as DISPLAY DATA only, and is sanitised on the
 * way out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_files', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('document_id');
            $t->uuid('revision_id');
            $t->uuid('project_id');   // denormalised: the RLS policy joins on it
            $t->uuid('company_id');   // RLS tenant column

            // Display name. NEVER used to build a path.
            $t->string('original_name', 512);
            // Where the bytes actually live, relative to the disk root.
            $t->string('storage_path', 512);
            // Which disk wrote it, so a later move to MinIO/S3 can be
            // incremental instead of a flag day (MKT-EPCC-001 wants object
            // storage on-prem).
            $t->string('disk', 32)->default('local');

            $t->char('sha256', 64);
            $t->unsignedBigInteger('size_bytes');
            // The type WE decided from the bytes + extension, not the browser's
            // claim. A client-supplied Content-Type is user input.
            $t->string('mime_type', 128);
            $t->string('extension', 16);

            $t->uuid('uploaded_by')->nullable();
            $t->timestampTz('uploaded_at')->nullable();

            $t->timestampsTz();

            $t->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $t->foreign('revision_id')->references('id')->on('document_revisions')->cascadeOnDelete();
            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $t->foreign('uploaded_by')->references('id')->on('users')->nullOnDelete();

            // The same bytes may legitimately appear on many revisions, but
            // attaching the identical file twice to ONE revision is a
            // double-click, not an intent.
            $t->unique(['revision_id', 'sha256']);
            $t->index(['document_id']);
            $t->index(['project_id', 'sha256']);
        });

        DB::statement('ALTER TABLE document_files ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE document_files FORCE ROW LEVEL SECURITY');

        // Policy shape copied from migration 110000 (payment_certificates),
        // which is the CURRENT correct shape: NULLIF on every GUC read, an
        // optional epcc.project_id scope, and participation checked through
        // project_company. See the document-register migration for the two
        // defects that came from copying the original 100100 policy instead.
        DB::statement(<<<'SQL'
            CREATE POLICY document_files_tenant_isolation ON document_files
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR document_files.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND
                (
                    (
                        company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                        AND EXISTS (
                            SELECT 1 FROM project_company pc
                            WHERE pc.project_id = document_files.project_id
                              AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
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
    }

    public function down(): void
    {
        DB::statement('DROP POLICY IF EXISTS document_files_tenant_isolation ON document_files');
        Schema::dropIfExists('document_files');
    }
};
