<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Net engineering take-off rows for comparison with accepted gross MTO.
 *
 * This is intentionally not a model/PCF importer. A manual row can be entered
 * now, a derived row can be recorded later, and PCF remains an explicit source
 * type without pretending that a parser exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineering_estimate_rows', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->string('source_type', 24);
            $t->string('source_revision', 128)->nullable();
            $t->text('source_evidence')->nullable();
            $t->uuid('material_item_id');
            $t->uuid('accepted_mto_row_id')->nullable();
            $t->decimal('net_quantity', 18, 3);
            $t->string('unit', 16);
            $t->uuid('scope_id')->nullable();
            $t->uuid('piping_class_id')->nullable();
            $t->uuid('line_id')->nullable();
            $t->jsonb('provenance')->default(DB::raw("'{}'::jsonb"));
            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->restrictOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('material_item_id')->references('id')->on('material_items')->restrictOnDelete();
            $t->foreign('accepted_mto_row_id')->references('id')->on('accepted_mto_rows')->restrictOnDelete();
            $t->foreign('scope_id')->references('id')->on('subsystems')->restrictOnDelete();
            $t->foreign('piping_class_id')->references('id')->on('piping_classes')->restrictOnDelete();
            $t->foreign('line_id')->references('id')->on('piping_lines')->restrictOnDelete();

            $t->index(['project_id', 'source_type']);
            $t->index(['project_id', 'material_item_id', 'scope_id']);
            $t->index(['accepted_mto_row_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE engineering_estimate_rows ADD CONSTRAINT engineering_estimate_source_known CHECK (
                source_type IN ('MANUAL_ISO','MODEL_DERIVED','PCF_IMPORT')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE engineering_estimate_rows ADD CONSTRAINT engineering_estimate_qty_positive CHECK (
                net_quantity > 0
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE engineering_estimate_rows ADD CONSTRAINT engineering_estimate_provenance_object CHECK (
                jsonb_typeof(provenance) = 'object'
            )
        SQL);

        DB::unprepared(<<<'SQL'
            ALTER TABLE engineering_estimate_rows ENABLE ROW LEVEL SECURITY;
            ALTER TABLE engineering_estimate_rows FORCE ROW LEVEL SECURITY;

            CREATE POLICY engineering_estimates_tenant_isolation ON engineering_estimate_rows
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR engineering_estimate_rows.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = engineering_estimate_rows.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = engineering_estimate_rows.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            );

            CREATE POLICY engineering_estimates_archive_freeze ON engineering_estimate_rows
            AS RESTRICTIVE
            FOR ALL
            USING (true)
            WITH CHECK (epcc_project_is_writable(project_id));

            CREATE POLICY engineering_estimates_archive_no_delete ON engineering_estimate_rows
            AS RESTRICTIVE
            FOR DELETE
            USING (epcc_project_is_writable(project_id));
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP POLICY IF EXISTS engineering_estimates_archive_no_delete ON engineering_estimate_rows;
            DROP POLICY IF EXISTS engineering_estimates_archive_freeze ON engineering_estimate_rows;
            DROP POLICY IF EXISTS engineering_estimates_tenant_isolation ON engineering_estimate_rows;
        SQL);

        Schema::dropIfExists('engineering_estimate_rows');
    }
};
