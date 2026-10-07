<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Material catalogue foundation for MTO.
 *
 * MTO acceptance must not be based on free-text descriptions. This table gives
 * the platform a stable material identity before accepted MTO snapshots exist.
 *
 * The model is intentionally two-level:
 *
 *   - project_id NULL  = global/reference material item, readable by every
 *     project and suitable for future IPS/ISO/reference packs;
 *   - project_id value = project overlay item, where an Iranian client/EPC can
 *     keep its own commodity code, description, class wording and lifecycle.
 *
 * RLS allows every project participant to read global rows plus its own project
 * overlay rows. The first slice only writes project overlay rows through the
 * service; global catalogue administration remains a future controlled import
 * path so normal tenant users cannot quietly redefine corporate reference data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_items', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id')->nullable();
            $t->uuid('reference_item_id')->nullable();

            $t->string('discipline', 32);
            $t->string('category', 32);
            $t->string('commodity_code', 96);
            $t->text('description_en');
            $t->text('description_fa');
            $t->string('base_unit', 16);

            $t->string('size_nps', 32)->nullable();
            $t->string('size_dn', 32)->nullable();
            $t->string('schedule', 32)->nullable();
            $t->string('rating', 32)->nullable();
            $t->string('class_code', 64)->nullable();
            $t->string('material_spec', 128)->nullable();
            $t->string('grade', 64)->nullable();

            $t->string('source', 32);
            $t->string('status', 24)->default('ACTIVE');
            $t->jsonb('alias_codes')->default(DB::raw("'[]'::jsonb"));

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();

            $t->index(['project_id', 'discipline', 'category']);
            $t->index(['project_id', 'status']);
            $t->index(['discipline', 'commodity_code']);
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX material_items_project_code_unique
            ON material_items (project_id, discipline, commodity_code)
            WHERE project_id IS NOT NULL
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX material_items_global_code_unique
            ON material_items (discipline, commodity_code)
            WHERE project_id IS NULL
        SQL);

        /*
         * Self-referential foreign keys are added after table creation. Doing
         * it inside Schema::create can make PostgreSQL evaluate the reference
         * before the primary key is fully visible, the same trap documented in
         * the materials ledger migration.
         */
        Schema::table('material_items', function (Blueprint $t): void {
            $t->foreign('reference_item_id')->references('id')->on('material_items')->nullOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE material_items ADD CONSTRAINT material_items_category_known CHECK (
                category IN ('PIPE','FITTING','FLANGE','VALVE','GASKET','BOLT_NUT','SPECIALTY','SUPPORT')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE material_items ADD CONSTRAINT material_items_status_known CHECK (
                status IN ('ACTIVE','WITHDRAWN','PROJECT_ONLY')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE material_items ADD CONSTRAINT material_items_source_known CHECK (
                source IN ('REFERENCE_PACK','PMS','PROJECT_CATALOGUE','MANUAL_APPROVED')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE material_items ADD CONSTRAINT material_items_unit_known CHECK (
                base_unit IN ('M','EA','KG')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE material_items ADD CONSTRAINT material_items_aliases_are_array CHECK (
                jsonb_typeof(alias_codes) = 'array'
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE material_items ADD CONSTRAINT material_items_overlay_reference_not_self CHECK (
                reference_item_id IS NULL OR reference_item_id <> id
            )
        SQL);

        DB::unprepared('ALTER TABLE material_items ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE material_items FORCE ROW LEVEL SECURITY');

        DB::unprepared(<<<'SQL'
            CREATE POLICY material_items_tenant_isolation ON material_items
            USING (
                project_id IS NULL
                OR (
                    (
                        NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                        OR material_items.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                    )
                    AND EXISTS (
                        SELECT 1 FROM project_company pc
                        WHERE pc.project_id = material_items.project_id
                          AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                    )
                )
            )
            WITH CHECK (
                project_id IS NOT NULL
                AND (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR material_items.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = material_items.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE POLICY material_items_archive_freeze ON material_items
            AS RESTRICTIVE
            FOR ALL
            USING (true)
            WITH CHECK (project_id IS NULL OR epcc_project_is_writable(project_id))
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE POLICY material_items_archive_no_delete ON material_items
            AS RESTRICTIVE
            FOR DELETE
            USING (project_id IS NULL OR epcc_project_is_writable(project_id))
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('material_items');
    }
};
