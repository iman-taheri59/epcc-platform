<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $scope = "project_id=NULLIF(current_setting('epcc.project_id',true),'')::uuid";
        $owned = $scope." AND company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid";
        DB::statement("ALTER POLICY piping_class_valve_specs_read ON piping_class_valve_specs USING ({$scope})");
        DB::statement("ALTER POLICY piping_class_valve_specs_write ON piping_class_valve_specs USING ({$owned}) WITH CHECK ({$owned})");
        DB::statement("CREATE POLICY pms_valve_participant ON piping_class_valve_specs AS RESTRICTIVE FOR ALL USING
            (EXISTS (SELECT 1 FROM project_company pc WHERE pc.project_id=piping_class_valve_specs.project_id
                AND pc.company_id=NULLIF(current_setting('epcc.company_id',true),'')::uuid))");
        DB::statement('CREATE POLICY pms_valve_archive_write ON piping_class_valve_specs AS RESTRICTIVE FOR ALL USING (true) WITH CHECK (epcc_project_is_writable(project_id))');
        DB::statement('CREATE POLICY pms_valve_archive_delete ON piping_class_valve_specs AS RESTRICTIVE FOR DELETE USING (epcc_project_is_writable(project_id))');
    }

    public function down(): void
    {
        throw new RuntimeException('Forward-only archive protection: do not reopen archived valve writes.');
    }
};
