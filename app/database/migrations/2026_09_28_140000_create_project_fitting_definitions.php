<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_fitting_definitions', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignUuid('company_id')->constrained('companies')->restrictOnDelete();
            $t->foreignUuid('created_by')->constrained('users')->restrictOnDelete();
            $t->string('component_type', 16);
            $t->string('qualifier', 80)->default('');
            $t->decimal('nps', 12, 4);
            $t->unsignedInteger('dn')->nullable();
            $t->decimal('od_mm', 14, 4);
            $t->string('dimension', 24);
            $t->decimal('dimension_mm', 14, 4);
            $t->string('spec_number', 120);
            $t->string('spec_revision', 60);
            $t->string('spec_clause', 120);
            $t->text('source_text');
            $t->string('provenance', 24)->default('PROJECT_SPEC');
            $t->string('review_state', 24)->default('UNREVIEWED');
            $t->timestampsTz();
            $t->unique(['project_id', 'company_id', 'component_type', 'qualifier', 'nps',
                'spec_number', 'spec_revision', 'spec_clause'], 'project_fitting_source_unique');
            $t->index(['project_id', 'created_at']);
        });
        DB::statement(<<<'SQL'
            ALTER TABLE project_fitting_definitions ADD CONSTRAINT project_fitting_definition_valid CHECK (
                nps > 0 AND nps < 100000000 AND (nps < 0.5 OR nps > 48)
                AND od_mm > 0 AND od_mm < 10000000000
                AND dimension_mm > 0 AND dimension_mm < 10000000000
                AND (dn IS NULL OR dn > 0)
                AND ((component_type = 'CAP' AND qualifier = '' AND dimension = 'LENGTH')
                    OR (component_type = 'ELBOW' AND length(trim(qualifier)) > 0 AND dimension = 'CENTRE_TO_END'))
                AND length(trim(spec_number)) > 0 AND length(trim(spec_revision)) > 0
                AND length(trim(spec_clause)) > 0 AND length(trim(source_text)) > 0
                AND provenance = 'PROJECT_SPEC' AND review_state = 'UNREVIEWED'
            )
        SQL);
        DB::statement('ALTER TABLE project_fitting_definitions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE project_fitting_definitions FORCE ROW LEVEL SECURITY');
        $visible = "(project_fitting_definitions.project_id = NULLIF(current_setting('epcc.project_id', true), '')::uuid
            AND EXISTS (SELECT 1 FROM project_company pc
                WHERE pc.project_id = project_fitting_definitions.project_id
                AND pc.company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid))";
        DB::statement("CREATE POLICY project_fitting_read ON project_fitting_definitions FOR SELECT USING ({$visible})");
        DB::statement("CREATE POLICY project_fitting_insert ON project_fitting_definitions FOR INSERT WITH CHECK (
            {$visible}
            AND company_id = NULLIF(current_setting('epcc.company_id', true), '')::uuid
            AND epcc_project_is_writable(project_id)
            AND EXISTS (SELECT 1 FROM users u WHERE u.id = project_fitting_definitions.created_by
                AND u.company_id = project_fitting_definitions.company_id AND u.is_active)
            AND EXISTS (SELECT 1 FROM project_memberships pm
                WHERE pm.project_id = project_fitting_definitions.project_id
                AND pm.user_id = project_fitting_definitions.created_by)
        )");
        // Append-only evidence. No UPDATE/DELETE policies or HTTP endpoints.
    }

    public function down(): void
    {
        Schema::dropIfExists('project_fitting_definitions');
    }
};
