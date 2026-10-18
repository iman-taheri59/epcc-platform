<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::table('project_fitting_definitions',function (Blueprint $t) {
            $t->string('material_family',16)->default('FITTING');
            $t->string('size_designation',128)->nullable();
            $t->string('reference_standard',200)->nullable();
            $t->string('material_spec',200)->nullable();
            $t->string('material_grade',120)->nullable();
            $t->text('declaration_reason')->nullable();
            $t->jsonb('properties')->default('{}');
            $t->unique(['id','project_id','company_id','material_family'],'material_definition_family_unique');
            foreach (['revises_id'=>'material_revision_family_fk','root_id'=>'material_root_family_fk'] as $column=>$name) {
                $t->foreign([$column,'project_id','company_id','material_family'],$name)->references(['id','project_id','company_id','material_family'])->on('project_fitting_definitions')->restrictOnDelete();
            }
        });
        DB::unprepared(<<<'SQL'
            ALTER TABLE project_fitting_definitions
                ALTER COLUMN nps DROP NOT NULL, ALTER COLUMN od_mm DROP NOT NULL,
                ALTER COLUMN dimension DROP NOT NULL, ALTER COLUMN dimension_mm DROP NOT NULL,
                DROP CONSTRAINT project_fitting_definition_valid;
            ALTER TABLE project_fitting_definitions ADD CONSTRAINT project_material_definition_valid CHECK (
                length(trim(spec_number))>0 AND length(trim(spec_revision))>0 AND length(trim(spec_clause))>0 AND length(trim(source_text))>0
                AND provenance='PROJECT_SPEC' AND review_state='UNREVIEWED' AND jsonb_typeof(properties)='object'
                AND ((material_family='FITTING' AND nps IS NOT NULL AND od_mm IS NOT NULL AND dimension IS NOT NULL AND dimension_mm IS NOT NULL
                    AND nps>0 AND nps<100000000 AND (nps<0.5 OR nps>48) AND od_mm>0 AND od_mm<10000000000
                    AND dimension_mm>0 AND dimension_mm<10000000000 AND (dn IS NULL OR dn>0)
                    AND ((component_type='CAP' AND qualifier='' AND dimension='LENGTH')
                        OR (component_type='ELBOW' AND length(trim(qualifier))>0 AND dimension='CENTRE_TO_END')))
                OR (material_family IN ('PIPE','FLANGE','VALVE','GASKET','BOLTING') AND component_type=material_family
                    AND nps IS NULL AND dn IS NULL AND od_mm IS NULL AND dimension IS NULL AND dimension_mm IS NULL
                    AND length(trim(qualifier))>0 AND size_designation IS NOT NULL AND length(trim(size_designation))>0
                    AND reference_standard IS NOT NULL AND length(trim(reference_standard))>0
                    AND material_spec IS NOT NULL AND length(trim(material_spec))>0
                    AND declaration_reason IS NOT NULL AND length(trim(declaration_reason))>0))
            );
            CREATE UNIQUE INDEX project_material_source_unique ON project_fitting_definitions
                (project_id,company_id,material_family,qualifier,size_designation,spec_number,spec_revision,spec_clause)
                WHERE revises_id IS NULL AND material_family <> 'FITTING';
            ALTER TABLE fitting_review_policies ADD COLUMN material_family varchar(16) NOT NULL DEFAULT 'FITTING';
            ALTER TABLE fitting_review_policies DROP CONSTRAINT fitting_review_policies_project_id_version_unique;
            ALTER TABLE fitting_review_policies ADD CONSTRAINT material_policy_family_valid CHECK
                (material_family IN ('FITTING','PIPE','FLANGE','VALVE','GASKET','BOLTING'));
            ALTER TABLE fitting_review_policies ADD CONSTRAINT material_policy_version_unique UNIQUE(project_id,material_family,version);
            CREATE OR REPLACE FUNCTION epcc_guard_material_review_run() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM project_fitting_definitions d JOIN fitting_review_policies p
                    ON p.project_id=d.project_id AND p.material_family=d.material_family
                    WHERE d.id=NEW.definition_id AND p.id=NEW.policy_id AND d.project_id=NEW.project_id)
                THEN RAISE EXCEPTION 'Policy material family mismatch' USING ERRCODE='42501'; END IF;
                RETURN NEW;
            END;
            $$;
            CREATE TRIGGER material_review_run_guard BEFORE INSERT ON fitting_review_runs
                FOR EACH ROW EXECUTE FUNCTION epcc_guard_material_review_run();
        SQL);
    }
    public function down(): void {throw new RuntimeException('Material review history is forward-only; restore an explicitly chosen backup to revert.');}
};
