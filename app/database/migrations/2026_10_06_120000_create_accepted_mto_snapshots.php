<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Immutable accepted MTO snapshots.
 *
 * Staging rows are deliberately mutable. Once a revision is accepted, these
 * two tables become the auditable gross-MTO baseline for downstream work. The
 * source identifiers remain for traceability, while the labels and source-row
 * hash are copied so a later catalogue edit cannot rewrite history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accepted_mto_batches', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('mto_document_id');
            $t->uuid('mto_revision_id');
            $t->string('document_number', 128);
            $t->string('revision_code', 32);
            $t->string('state', 24)->default('ACCEPTED');
            $t->string('source_file_hash', 128)->nullable();
            $t->string('scope_mode', 24);
            $t->uuid('header_scope_id')->nullable();
            $t->string('hierarchy_revision_id', 96)->nullable();
            $t->timestampTz('accepted_at');
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('mto_document_id')->references('id')->on('mto_documents')->restrictOnDelete();
            $t->foreign('mto_revision_id')->references('id')->on('mto_revisions')->restrictOnDelete();
            $t->foreign('header_scope_id')->references('id')->on('subsystems')->restrictOnDelete();

            // A revision is accepted once. A replacement is a new revision,
            // never a second mutable version of this batch.
            $t->unique('mto_revision_id');
            $t->index(['project_id', 'accepted_at']);
        });

        Schema::create('accepted_mto_rows', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('accepted_mto_batch_id');
            $t->uuid('mto_document_id');
            $t->uuid('mto_revision_id');
            $t->uuid('source_staging_row_id');

            $t->string('mto_item_no', 96);
            $t->uuid('material_item_id');
            $t->decimal('gross_quantity', 18, 3);
            $t->string('unit', 16);
            $t->decimal('canonical_quantity', 18, 3)->nullable();
            $t->uuid('scope_id')->nullable();
            $t->string('hierarchy_revision_id', 96)->nullable();
            $t->uuid('piping_class_id')->nullable();

            // These are copied from the staging row/catalogue at acceptance;
            // they are not live display joins.
            $t->text('raw_description');
            $t->text('material_description_en');
            $t->text('material_description_fa');
            $t->string('material_commodity_code', 96);
            $t->string('material_base_unit', 16);
            $t->string('material_category', 32);
            $t->char('source_row_hash', 64);
            $t->timestampTz('accepted_at');
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('accepted_mto_batch_id')->references('id')->on('accepted_mto_batches')->restrictOnDelete();
            $t->foreign('mto_document_id')->references('id')->on('mto_documents')->restrictOnDelete();
            $t->foreign('mto_revision_id')->references('id')->on('mto_revisions')->restrictOnDelete();
            $t->foreign('source_staging_row_id')->references('id')->on('mto_staging_rows')->restrictOnDelete();
            $t->foreign('material_item_id')->references('id')->on('material_items')->restrictOnDelete();
            $t->foreign('scope_id')->references('id')->on('subsystems')->restrictOnDelete();
            $t->foreign('piping_class_id')->references('id')->on('piping_classes')->restrictOnDelete();

            $t->unique(['accepted_mto_batch_id', 'source_staging_row_id']);
            $t->index(['project_id', 'mto_item_no']);
            $t->index(['mto_revision_id', 'mto_item_no']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE accepted_mto_batches ADD CONSTRAINT accepted_mto_batches_state_known CHECK (
                state = 'ACCEPTED'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE accepted_mto_batches ADD CONSTRAINT accepted_mto_batches_scope_mode_known CHECK (
                scope_mode IN ('HEADER_SCOPE','ROW_SCOPE','MIXED')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE accepted_mto_rows ADD CONSTRAINT accepted_mto_rows_qty_positive CHECK (
                gross_quantity > 0
                AND (canonical_quantity IS NULL OR canonical_quantity > 0)
            )
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE accepted_mto_batches ENABLE ROW LEVEL SECURITY;
            ALTER TABLE accepted_mto_batches FORCE ROW LEVEL SECURITY;
            ALTER TABLE accepted_mto_rows ENABLE ROW LEVEL SECURITY;
            ALTER TABLE accepted_mto_rows FORCE ROW LEVEL SECURITY;

            CREATE POLICY accepted_mto_batches_tenant_isolation ON accepted_mto_batches
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR accepted_mto_batches.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = accepted_mto_batches.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = accepted_mto_batches.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            );

            CREATE POLICY accepted_mto_rows_tenant_isolation ON accepted_mto_rows
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR accepted_mto_rows.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = accepted_mto_rows.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = accepted_mto_rows.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            );

            -- Explicit deny policies document the invariant and keep the
            -- tables closed even if a future permissive policy is added.
            CREATE POLICY accepted_mto_batches_no_update ON accepted_mto_batches
            AS RESTRICTIVE FOR UPDATE USING (false) WITH CHECK (false);
            CREATE POLICY accepted_mto_batches_no_delete ON accepted_mto_batches
            AS RESTRICTIVE FOR DELETE USING (false);
            CREATE POLICY accepted_mto_rows_no_update ON accepted_mto_rows
            AS RESTRICTIVE FOR UPDATE USING (false) WITH CHECK (false);
            CREATE POLICY accepted_mto_rows_no_delete ON accepted_mto_rows
            AS RESTRICTIVE FOR DELETE USING (false);
        SQL);

        // RLS is the tenant boundary; the trigger is the history boundary.
        // It also protects callers that run with a privileged role.
        DB::unprepared(<<<'SQL'
            CREATE FUNCTION epcc_reject_accepted_mto_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'accepted MTO snapshots are immutable';
            END;
            $$;

            CREATE TRIGGER accepted_mto_batches_immutable
            BEFORE UPDATE OR DELETE ON accepted_mto_batches
            FOR EACH ROW EXECUTE FUNCTION epcc_reject_accepted_mto_mutation();

            CREATE TRIGGER accepted_mto_rows_immutable
            BEFORE UPDATE OR DELETE ON accepted_mto_rows
            FOR EACH ROW EXECUTE FUNCTION epcc_reject_accepted_mto_mutation();
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS accepted_mto_rows_immutable ON accepted_mto_rows;
            DROP TRIGGER IF EXISTS accepted_mto_batches_immutable ON accepted_mto_batches;
            DROP FUNCTION IF EXISTS epcc_reject_accepted_mto_mutation();
            DROP POLICY IF EXISTS accepted_mto_rows_no_delete ON accepted_mto_rows;
            DROP POLICY IF EXISTS accepted_mto_rows_no_update ON accepted_mto_rows;
            DROP POLICY IF EXISTS accepted_mto_rows_tenant_isolation ON accepted_mto_rows;
            DROP POLICY IF EXISTS accepted_mto_batches_no_delete ON accepted_mto_batches;
            DROP POLICY IF EXISTS accepted_mto_batches_no_update ON accepted_mto_batches;
            DROP POLICY IF EXISTS accepted_mto_batches_tenant_isolation ON accepted_mto_batches;
        SQL);

        Schema::dropIfExists('accepted_mto_rows');
        Schema::dropIfExists('accepted_mto_batches');
    }
};
