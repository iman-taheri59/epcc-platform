<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MTO document/revision/staging foundation.
 *
 * This slice deliberately stops before immutable accepted MTO snapshots. It
 * gives the application a versioned place to load and validate MTO rows while
 * keeping them editable and clearly DRAFT. Acceptance, all-or-nothing snapshot
 * creation, gross/net comparison and MR/PO rollups are later slices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mto_documents', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->string('discipline', 32);
            $t->string('document_number', 128);
            $t->string('title_en');
            $t->string('title_fa');
            $t->string('source_type', 32);
            $t->uuid('cde_document_id')->nullable();
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('cde_document_id')->references('id')->on('documents')->nullOnDelete();

            $t->unique(['project_id', 'discipline', 'document_number']);
            $t->index(['project_id', 'discipline']);
        });

        Schema::create('mto_revisions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('mto_document_id');
            $t->string('revision_code', 32);
            $t->date('revision_date')->nullable();
            $t->string('state', 24)->default('DRAFT');
            $t->string('source_file_hash', 128)->nullable();
            $t->string('scope_mode', 24)->default('ROW_SCOPE');
            $t->uuid('header_scope_id')->nullable();
            $t->string('hierarchy_revision_id', 96)->nullable();
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('mto_document_id')->references('id')->on('mto_documents')->cascadeOnDelete();
            $t->foreign('header_scope_id')->references('id')->on('subsystems')->nullOnDelete();

            $t->unique(['mto_document_id', 'revision_code']);
            $t->index(['project_id', 'state']);
        });

        Schema::create('mto_staging_rows', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('mto_revision_id');
            $t->unsignedInteger('line_no');
            $t->string('mto_item_no', 96);
            $t->text('raw_description');
            $t->decimal('raw_quantity', 18, 3);
            $t->string('raw_unit', 16);
            $t->uuid('material_item_id')->nullable();
            $t->uuid('row_scope_id')->nullable();
            $t->uuid('piping_class_id')->nullable();
            $t->string('validation_state', 40);
            $t->jsonb('validation_errors')->default(DB::raw("'[]'::jsonb"));
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('mto_revision_id')->references('id')->on('mto_revisions')->cascadeOnDelete();
            $t->foreign('material_item_id')->references('id')->on('material_items')->nullOnDelete();
            $t->foreign('row_scope_id')->references('id')->on('subsystems')->nullOnDelete();
            $t->foreign('piping_class_id')->references('id')->on('piping_classes')->nullOnDelete();

            $t->unique(['mto_revision_id', 'line_no']);
            $t->index(['project_id', 'validation_state']);
            $t->index(['mto_revision_id', 'mto_item_no']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE mto_documents ADD CONSTRAINT mto_documents_source_known CHECK (
                source_type IN ('CDE_REVISION','EXTERNAL_EVIDENCE','MANUAL')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE mto_revisions ADD CONSTRAINT mto_revisions_state_known CHECK (
                state IN ('DRAFT','UNDER_REVIEW','ACCEPTED','SUPERSEDED','REJECTED')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE mto_revisions ADD CONSTRAINT mto_revisions_scope_mode_known CHECK (
                scope_mode IN ('HEADER_SCOPE','ROW_SCOPE','MIXED')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE mto_staging_rows ADD CONSTRAINT mto_staging_rows_qty_positive CHECK (
                raw_quantity > 0
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE mto_staging_rows ADD CONSTRAINT mto_staging_rows_errors_array CHECK (
                jsonb_typeof(validation_errors) = 'array'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE mto_staging_rows ADD CONSTRAINT mto_staging_rows_state_known CHECK (
                validation_state IN (
                    'ACCEPTABLE',
                    'BLOCKED_UNMATCHED_MATERIAL',
                    'BLOCKED_UNIT_INCOMPATIBLE',
                    'BLOCKED_DUPLICATE_IDENTITY',
                    'BLOCKED_QUANTITY_INVALID'
                )
            )
        SQL);

        foreach (['mto_documents', 'mto_revisions', 'mto_staging_rows'] as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }

        DB::unprepared(<<<'SQL'
            CREATE POLICY mto_documents_tenant_isolation ON mto_documents
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR mto_documents.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = mto_documents.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = mto_documents.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE POLICY mto_revisions_tenant_isolation ON mto_revisions
            USING (
                EXISTS (
                    SELECT 1 FROM mto_documents d
                    WHERE d.id = mto_revisions.mto_document_id
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM mto_documents d
                    WHERE d.id = mto_revisions.mto_document_id
                )
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE POLICY mto_staging_rows_tenant_isolation ON mto_staging_rows
            USING (
                EXISTS (
                    SELECT 1 FROM mto_revisions r
                    WHERE r.id = mto_staging_rows.mto_revision_id
                )
            )
            WITH CHECK (
                EXISTS (
                    SELECT 1 FROM mto_revisions r
                    WHERE r.id = mto_staging_rows.mto_revision_id
                )
            )
        SQL);

        foreach (['mto_documents', 'mto_revisions', 'mto_staging_rows'] as $table) {
            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_archive_freeze ON {$table}
                AS RESTRICTIVE
                FOR ALL
                USING (true)
                WITH CHECK (epcc_project_is_writable(project_id))
            SQL);

            DB::unprepared(<<<SQL
                CREATE POLICY {$table}_archive_no_delete ON {$table}
                AS RESTRICTIVE
                FOR DELETE
                USING (epcc_project_is_writable(project_id))
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mto_staging_rows');
        Schema::dropIfExists('mto_revisions');
        Schema::dropIfExists('mto_documents');
    }
};
