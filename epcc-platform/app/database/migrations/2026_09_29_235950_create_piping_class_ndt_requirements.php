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
        Schema::create('piping_class_ndt_requirements', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->foreignUuid('project_id')->constrained('projects')->cascadeOnDelete();
            $t->foreignUuid('company_id')->constrained('companies');
            $t->foreignUuid('piping_class_id')->constrained('piping_classes')->cascadeOnDelete();
            $t->string('applicability', 2000);
            $t->string('method', 120);
            $t->string('source_ref', 1000);
            $t->string('extent', 1000)->nullable();
            $t->string('sampling_basis', 2000)->nullable();
            $t->string('acceptance', 4000)->nullable();
            $t->string('reference', 2000)->nullable();
            $t->string('conditions', 4000)->nullable();
            $t->timestampsTz();
            $t->index(['project_id', 'piping_class_id']);
        });
        DB::statement("ALTER TABLE piping_class_ndt_requirements ADD CONSTRAINT pms_ndt_required_text CHECK (btrim(applicability)<>'' AND btrim(method)<>'' AND btrim(source_ref)<>'')");
        DB::statement('ALTER TABLE piping_class_ndt_requirements ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE piping_class_ndt_requirements FORCE ROW LEVEL SECURITY');
        $scope = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid";
        $owned = $scope." AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid";
        $parent = 'EXISTS (SELECT 1 FROM piping_classes c WHERE c.id=piping_class_ndt_requirements.piping_class_id AND c.project_id=piping_class_ndt_requirements.project_id AND c.company_id=piping_class_ndt_requirements.company_id)';
        DB::statement("CREATE POLICY pms_ndt_read ON piping_class_ndt_requirements FOR SELECT USING ({$scope})");
        DB::statement("CREATE POLICY pms_ndt_write ON piping_class_ndt_requirements FOR ALL USING ({$owned}) WITH CHECK ({$owned} AND {$parent})");
        DB::statement("CREATE POLICY pms_ndt_participant ON piping_class_ndt_requirements AS RESTRICTIVE FOR ALL USING
            (EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=piping_class_ndt_requirements.project_id
                AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid))");
        DB::statement('CREATE POLICY pms_ndt_archive_write ON piping_class_ndt_requirements AS RESTRICTIVE FOR ALL USING (true) WITH CHECK (epcc_project_is_writable(project_id))');
        DB::statement('CREATE POLICY pms_ndt_archive_delete ON piping_class_ndt_requirements AS RESTRICTIVE FOR DELETE USING (epcc_project_is_writable(project_id))');
    }

    public function down(): void
    {
        throw new RuntimeException('NDT source requirements are forward-only; do not drop recorded requirements.');
    }
};
