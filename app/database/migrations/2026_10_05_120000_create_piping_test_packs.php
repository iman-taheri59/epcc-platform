<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Piping test packs / hydrotest packs.
 *
 * This is the first turnover object built from the line spine: lines are
 * designed and AFC-released first, then grouped into a pressure-test package.
 * A line belongs to at most one active test pack in the MVP. The pack state is
 * deliberately small; detailed hydrotest reports, punch carry-over and PDF
 * turnover evidence can hang from this entity without changing its identity.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('piping_test_packs', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->uuid('project_id');
            $t->uuid('company_id');
            $t->uuid('subsystem_id')->nullable();

            $t->string('code', 64);
            $t->string('title_en')->nullable();
            $t->string('title_fa')->nullable();

            // DRAFT -> READY -> TESTED -> ACCEPTED.
            $t->string('state', 24)->default('DRAFT');

            $t->decimal('test_pressure', 12, 4)->nullable();
            $t->string('test_pressure_unit', 12)->nullable();
            $t->timestampTz('ready_at')->nullable();
            $t->uuid('ready_by')->nullable();
            $t->timestampTz('tested_at')->nullable();
            $t->uuid('tested_by')->nullable();
            $t->timestampTz('accepted_at')->nullable();
            $t->uuid('accepted_by')->nullable();
            $t->text('notes')->nullable();

            $t->timestampsTz();

            $t->foreign('project_id')->references('id')->on('projects')->cascadeOnDelete();
            $t->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $t->foreign('subsystem_id')->references('id')->on('subsystems')->nullOnDelete();
            $t->foreign('ready_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('tested_by')->references('id')->on('users')->nullOnDelete();
            $t->foreign('accepted_by')->references('id')->on('users')->nullOnDelete();

            $t->unique(['project_id', 'code']);
            $t->index(['project_id', 'state']);
        });

        Schema::table('piping_lines', function (Blueprint $t): void {
            $t->uuid('test_pack_id')->nullable()->after('subsystem_id');
            $t->foreign('test_pack_id')->references('id')->on('piping_test_packs')->nullOnDelete();
            $t->index(['project_id', 'test_pack_id']);
        });

        DB::statement(<<<'SQL'
            ALTER TABLE piping_test_packs ADD CONSTRAINT piping_test_packs_state_known CHECK (
                state IN ('DRAFT','READY','TESTED','ACCEPTED')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE piping_test_packs ADD CONSTRAINT piping_test_packs_pressure_pair CHECK (
                (test_pressure IS NULL AND test_pressure_unit IS NULL)
                OR (test_pressure IS NOT NULL AND test_pressure_unit IS NOT NULL)
            )
        SQL);

        DB::unprepared('ALTER TABLE piping_test_packs ENABLE ROW LEVEL SECURITY');
        DB::unprepared('ALTER TABLE piping_test_packs FORCE ROW LEVEL SECURITY');

        DB::unprepared(<<<'SQL'
            CREATE POLICY piping_test_packs_tenant_isolation ON piping_test_packs
            USING (
                (
                    NULLIF(current_setting('epcc.project_id', true), '') IS NULL
                    OR piping_test_packs.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
                )
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = piping_test_packs.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
            WITH CHECK (
                company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                AND EXISTS (
                    SELECT 1 FROM project_company pc
                    WHERE pc.project_id = piping_test_packs.project_id
                      AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
                )
            )
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE POLICY piping_test_packs_archive_freeze ON piping_test_packs
            AS RESTRICTIVE
            FOR ALL
            USING (true)
            WITH CHECK (epcc_project_is_writable(project_id))
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE POLICY piping_test_packs_archive_no_delete ON piping_test_packs
            AS RESTRICTIVE
            FOR DELETE
            USING (epcc_project_is_writable(project_id))
        SQL);
    }

    public function down(): void
    {
        Schema::table('piping_lines', function (Blueprint $t): void {
            $t->dropForeign(['test_pack_id']);
            $t->dropIndex(['project_id', 'test_pack_id']);
            $t->dropColumn('test_pack_id');
        });

        Schema::dropIfExists('piping_test_packs');
    }
};
